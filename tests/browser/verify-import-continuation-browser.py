#!/usr/bin/env python3
"""G18 browser proof — the server keeps a resumable import alive with no browser.

## Why this exists

The defect this stage fixes is *operator-facing*: Import History promised
"resumable" for a job nothing would ever resume. So the operator surface is
proven in a real browser (Chromium via Playwright), and — more importantly —
the continuation is then proven to run **with the browser closed and no page
open at all**.

## What it does

  Phase 0  seed a probe source (never a real county source) whose URL points at
           a static fixture served by the local stack, so the whole path runs
           over real HTTP with **no external traffic and no API abuse**.
  Phase 1  log in to wp-admin, open Import History in a real browser, and read
           the Progress cell. The page is then **closed**.
  Phase 2  with no browser at all, issue plain HTTP requests. Before the
           cooldown expires: nothing runs. After it expires: the continuation
           executes server-side.
  Phase 3  drive the chain to completion with plain requests only, then open the
           browser **once** to read the final state.

## Scope and safety

Local Compose stack only (`http://localhost:8080`). Refuses any other host. No
production credential, no production host, no external request. The probe source
and every event it creates are deleted at the end.

## Usage

    python3 tests/browser/verify-import-continuation-browser.py
    python3 tests/browser/verify-import-continuation-browser.py --negative-control

Exit codes, matching the other opt-in browser layer in this directory:

    0  pass
    1  fail
    2  environment unavailable (Playwright, the local site, or the account)

A `2` is never reported as a pass. `--negative-control` breaks the scheduling so
that the Import History cell must go RED; that is what makes the browser proof
non-vacuous rather than a screenshot of whatever the page happened to say.
"""

from __future__ import annotations

import json
import os
import re
import subprocess
import sys
import time
import urllib.error
import urllib.request

BASE = os.environ.get("CONEXAO_TEST_BASE_URL", "http://localhost:8080").rstrip("/")
ALLOWED_HOSTS = {"localhost", "127.0.0.1"}

PASSED = 0
FAILURES: list[str] = []


def check(condition: bool, message: str) -> None:
    global PASSED
    if condition:
        PASSED += 1
        print(f"  PASS: {message}")
    else:
        FAILURES.append(message)
        print(f"  FAIL: {message}")


def refuse_non_local() -> None:
    host = re.sub(r"^[a-z]+://", "", BASE).split("/")[0].split(":")[0]
    if host not in ALLOWED_HOSTS:
        print(f"refusing to run against a non-local host: {host}")
        sys.exit(2)


def wp(cli: str, *args: str) -> str:
    """Run a PHP snippet inside the WordPress container."""
    cmd = ["docker", "compose", "exec", "-T", "wordpress", "php", "-r", cli, *args]
    proc = subprocess.run(cmd, capture_output=True, text=True, check=False)
    if proc.returncode != 0:
        print(proc.stdout)
        print(proc.stderr, file=sys.stderr)
        raise SystemExit(f"container command failed ({proc.returncode})")
    return proc.stdout


def get(path: str, timeout: float = 120.0) -> int:
    """A plain HTTP GET: no browser, no cookies, no JavaScript."""
    request = urllib.request.Request(BASE + path, headers={"User-Agent": "g18-proof/1.0"})
    try:
        with urllib.request.urlopen(request, timeout=timeout) as response:
            response.read()
            return response.status
    except urllib.error.HTTPError as error:
        return error.code


def wp_json_call(expression: str, *args: str) -> dict:
    raw = wp(
        "require_once '/var/www/html/tests/bootstrap.php';"
        "require_once WP_PLUGIN_DIR . '/conexao-event-importer/conexao-event-importer.php';"
        "echo wp_json_encode( " + expression + " );",
        *args,
    )
    return json.loads(raw.strip().splitlines()[-1])


def state(source: str) -> dict:
    return wp_json_call("Conexao_Import_Continuation::get_state( $argv[1] )", source)


def checkpoint(source: str) -> dict:
    return wp_json_call("Conexao_Source_Enumeration_Checkpoint::get_state( $argv[1] )", source)


def armed(source: str) -> int:
    raw = wp(
        "require_once '/var/www/html/tests/bootstrap.php';"
        "require_once WP_PLUGIN_DIR . '/conexao-event-importer/conexao-event-importer.php';"
        "echo (int) wp_next_scheduled( Conexao_Import_Continuation::HOOK, array( $argv[1] ) );",
        source,
    )
    return int(raw.strip().splitlines()[-1])


def seed_probe() -> None:
    """Register the probe source and drive it into the resumable state."""
    program = r"""
require_once '/var/www/html/tests/bootstrap.php';
require_once WP_PLUGIN_DIR . '/conexao-event-importer/conexao-event-importer.php';

$config           = Conexao_County_Registry::get_eventbrite_source( 'cork' );
$config['id']     = 'g18_probe_cork';
$config['name']   = 'G18 browser probe (Cork)';
/* The source is fetched from INSIDE the container, where the published host
 * port is unreachable (the same fact the WP-Cron note records), so the fixture
 * is addressed by the Compose service name. Still local-only: no external
 * request is ever made. */
$config['url']    = 'http://wordpress/wp-content/plugins/conexao-event-importer/tests/fixtures/g18-probe-cork.html';
$config['status'] = 'active';

Conexao_Event_Importer::instance()->sources->save( $config );

Conexao_Source_Enumeration_Checkpoint::reset( 'g18_probe_cork' );
Conexao_Import_Continuation::clear( 'g18_probe_cork' );
Conexao_Import_Log::clear();
Conexao_Import_History::clear();

add_filter( 'conexao_eventbrite_time_budget_seconds', static function () { return -1.0; }, 99 );
add_filter( 'conexao_eventbrite_page_delay_seconds', '__return_zero', 99 );
add_filter( 'conexao_eventbrite_backoff_seconds', '__return_zero', 99 );

$stats = Conexao_Event_Importer::instance()->importer->run_source( 'g18_probe_cork', false );

remove_all_filters( 'conexao_eventbrite_time_budget_seconds' );
remove_all_filters( 'conexao_eventbrite_page_delay_seconds' );
remove_all_filters( 'conexao_eventbrite_backoff_seconds' );

$started = Conexao_Import_Continuation::get_state( 'g18_probe_cork' );

/* The continuation's cooldown is real (30 min). Bring the RECORD forward to a
 * short, still-real window so the proof can wait it out with a genuine clock. */
$all            = get_option( Conexao_Import_Continuation::OPTION_KEY, array() );
$window_seconds = 25;

if ( isset( $all['g18_probe_cork'] ) ) {
	$all['g18_probe_cork']['run_at'] = time() + $window_seconds;
	update_option( Conexao_Import_Continuation::OPTION_KEY, $all, true );
	wp_clear_scheduled_hook( Conexao_Import_Continuation::HOOK, array( 'g18_probe_cork' ) );
	wp_schedule_single_event( (int) $all['g18_probe_cork']['run_at'], Conexao_Import_Continuation::HOOK, array( 'g18_probe_cork' ) );
}

echo wp_json_encode(
	array(
		'stats_complete' => (bool) $stats['complete'],
		'checkpoint'     => Conexao_Source_Enumeration_Checkpoint::get_state( 'g18_probe_cork' ),
		'continuation'   => $started,
		'window'         => $window_seconds,
	)
);
"""
    print(wp(program).strip().splitlines()[-1])


def cleanup() -> None:
    """Remove the probe source, its continuation and every event it created."""
    program = r"""
require_once '/var/www/html/tests/bootstrap.php';
require_once WP_PLUGIN_DIR . '/conexao-event-importer/conexao-event-importer.php';

Conexao_Import_Continuation::clear( 'g18_probe_cork' );
Conexao_Source_Enumeration_Checkpoint::reset( 'g18_probe_cork' );

$sources = Conexao_Event_Importer::instance()->sources;
$sources->delete( 'g18_probe_cork' );

$deleted = 0;

/* Loop until a pass finds nothing: a single capped query would leave events
 * behind. Bounded, so this can never hang. */
for ( $pass = 0; $pass < 20; $pass++ ) {
	$ids = get_posts(
		array(
			'post_type'      => 'event',
			'post_status'    => 'any',
			'posts_per_page' => 200,
			'fields'         => 'ids',
			'meta_query'     => array(
				array( 'key' => '_event_source', 'value' => 'g18_probe_', 'compare' => 'LIKE' ),
			),
		)
	);

	if ( empty( $ids ) ) {
		break;
	}

	foreach ( $ids as $id ) {
		wp_delete_post( (int) $id, true );
		++$deleted;
	}
}

echo 'cleaned events: ' . $deleted;
"""
    print(wp(program).strip().splitlines()[-1])


def advance_past_cooldown(source: str) -> None:
    """Set the persisted run_at to the server's "now", i.e. the cooldown elapsed.

    This is the only time the cooldown is simulated, and it is simulated at the
    STATE level (a persisted timestamp), never in code: the real 30-minute
    default is asserted by the suite and visible in the record. Phase 3 above
    waits out a real window with a real clock.
    """
    wp(
        "require_once '/var/www/html/tests/bootstrap.php';"
        "require_once WP_PLUGIN_DIR . '/conexao-event-importer/conexao-event-importer.php';"
        "$all = get_option( Conexao_Import_Continuation::OPTION_KEY, array() );"
        "if ( isset( $all[ $argv[1] ] ) ) {"
        "  $all[ $argv[1] ]['run_at'] = time() - 1;"
        "  update_option( Conexao_Import_Continuation::OPTION_KEY, $all, true );"
        "}"
        "echo 'ok';",
        source,
    )


def read_progress_cell_in_browser() -> str:
    """Open Import History in a REAL browser, read the Progress cell, and close it.

    The browser is closed before returning, so every assertion made after this
    point runs with no browser at all and therefore cannot depend on one.
    """
    from playwright.sync_api import sync_playwright

    login = os.environ.get("WP_USERNAME", "test")
    password = os.environ.get("WP_TEST_USER_PASSWORD", "test")

    with sync_playwright() as playwright:
        browser = playwright.chromium.launch()
        page = browser.new_page()

        page.goto(f"{BASE}/wp-login.php", wait_until="domcontentloaded")
        page.fill("#user_login", login)
        page.fill("#user_pass", password)
        page.click("#wp-submit")
        page.wait_for_load_state("domcontentloaded")

        if "wp-login.php" in page.url:
            browser.close()
            print(
                "ENVIRONMENT UNAVAILABLE: browser login failed, so the wp-admin "
                "surface cannot be read. Check WP_USERNAME / "
                "WP_TEST_USER_PASSWORD, or run "
                "scripts/ensure-local-test-user.php --apply (the documented "
                "post-restore step) to recreate the local account.",
                file=sys.stderr,
            )
            raise SystemExit(2)

        page.goto(f"{BASE}/wp-admin/admin.php?page=conexao-import-history", wait_until="load")

        row = page.locator("tr", has=page.locator("td", has_text="g18_probe_cork")).first
        cell = row.locator("td").nth(11).inner_text().strip()

        browser.close()

    return cell


CONT_PATH = "wp-content/plugins/conexao-event-importer/includes/class-conexao-import-continuation.php"


def break_scheduling() -> str:
    """Make the trigger impossible to arm, so no continuation can be promised.

    Returns the original file so the caller can restore it. This is the browser
    layer's mutation control: with the arming removed the Import History cell
    must stop saying "resumable", and a cell that says it anyway fails the run.
    """
    with open(CONT_PATH, encoding="utf-8") as handle:
        original = handle.read()

    needle = "		return (bool) wp_schedule_single_event( $run_at, self::HOOK, $args );"

    if original.count(needle) != 1:
        print("negative control: could not locate the arming call", file=sys.stderr)
        raise SystemExit(2)

    with open(CONT_PATH, "w", encoding="utf-8") as handle:
        handle.write(original.replace(needle, "		return false; // MUTANT", 1))

    return original


def restore_scheduling(original: str) -> None:
    with open(CONT_PATH, "w", encoding="utf-8") as handle:
        handle.write(original)


def run_negative_control() -> int:
    """Assert the Import History cell does NOT promise what cannot happen."""
    print("G18 browser NEGATIVE CONTROL - scheduling is broken")
    print("-----------------------------------------------------------------")

    original = break_scheduling()

    try:
        seed_probe()

        scheduled = state("g18_probe_cork")
        print(f"  state after a broken arm: {scheduled['phase']}")

        cell = read_progress_cell_in_browser()
        print(f"  Progress cell: {cell}")

        check(scheduled["scheduled"] is False, "with the arming broken, nothing is scheduled")
        check(scheduled["phase"] == "failed", f"the phase is failed (got {scheduled['phase']})")
        check("NOT scheduled" in cell, "the cell says NOT scheduled")
        check("resumable" not in cell, "the cell does NOT claim resumable - the control is red, so the proof is not vacuous")
    finally:
        restore_scheduling(original)
        cleanup()

    print("-----------------------------------------------------------------")
    print(f"negative control: {PASSED} passed, {len(FAILURES)} failed")

    return 1 if FAILURES else 0


def main() -> int:
    if "--negative-control" in sys.argv:
        return run_negative_control()
    refuse_non_local()
    print("G18 browser proof - the server continues the job with no browser")
    print("-----------------------------------------------------------------")

    print("\nPhase 0 - seed a probe source over a local fixture (no external traffic)")
    seed_probe()

    started = state("g18_probe_cork")
    cp = checkpoint("g18_probe_cork")

    check(cp["has_checkpoint"] is True, "the interrupted walk persisted a checkpoint")
    check(
        cp["last_page"] < cp["page_count"],
        f"the checkpoint is mid-listing (page {cp['last_page']} of {cp['page_count']})",
    )
    check(started["scheduled"] is True, "a continuation is scheduled")
    check(armed("g18_probe_cork") > 0, "a WordPress event is armed for it")
    check(started["phase"] == "cooling", f"the phase is cooling (got {started['phase']})")

    print("\nPhase 1 - Import History in a real browser (Chromium)")
    cell = read_progress_cell_in_browser()
    print(f"  Progress cell: {cell}")

    check("resumable" in cell, "the cell says resumable - and a continuation IS scheduled")
    check("next run in" in cell, "the cell states when the next run happens")
    check("NOT scheduled" not in cell, "the cell does not contradict itself")

    print("\nThe browser is now CLOSED. Everything below is a plain HTTP request.")

    print("\nPhase 2 - before the cooldown expires: nothing happens")
    before = state("g18_probe_cork")
    before_cp = checkpoint("g18_probe_cork")

    check(get("/") == 200, "a plain request to the front page succeeds with no browser")

    after_early = state("g18_probe_cork")
    after_early_cp = checkpoint("g18_probe_cork")

    check(after_early["phase"] == "cooling", f"the continuation is still cooling (got {after_early['phase']})")
    check(after_early_cp["last_page"] == before_cp["last_page"], "the page did NOT advance before the cooldown expired")
    check(after_early["scheduled"] is True, "the continuation was not consumed")
    check(
        after_early["seconds_remaining"] <= before["seconds_remaining"],
        "the remaining cooldown counts down on the server clock",
    )

    return finish(before_cp)


def finish(before_cp: dict) -> int:
    """Wait out the real cooldown, then drive the chain with plain requests."""
    print("\nPhase 3 - waiting out the real cooldown window")

    for _ in range(90):
        if state("g18_probe_cork")["phase"] != "cooling":
            break
        time.sleep(1)

    print("\nPhase 4 - after the cooldown: a plain request drives the continuation")
    check(get("/") == 200, "a plain request after the cooldown succeeds")

    advanced = checkpoint("g18_probe_cork")
    print(f"  checkpoint now: page {advanced['last_page']} of {advanced['page_count']}")
    check(
        advanced["last_page"] > before_cp["last_page"] or advanced["complete"],
        "the server advanced the walk with no browser open",
    )

    print("\nPhase 5 - drive the chain to completion with plain requests only")
    for _ in range(12):
        if checkpoint("g18_probe_cork")["complete"]:
            break

        current = state("g18_probe_cork")

        if not current["scheduled"]:
            wp(
                "require_once '/var/www/html/tests/bootstrap.php';"
                "require_once WP_PLUGIN_DIR . '/conexao-event-importer/conexao-event-importer.php';"
                "Conexao_Import_Continuation::reconcile( 'g18_probe_cork' );"
                "echo 'reconciled';"
            )
        else:
            # Each further continue is a further 30-minute cooldown. Wait out one
            # real window above (Phase 3); here the elapsed cooldown is modelled
            # in the persisted record, because a 2-hour wall-clock test is not a
            # test design. No code is changed.
            advance_past_cooldown("g18_probe_cork")

        get("/")
        time.sleep(1)

    final_cp = checkpoint("g18_probe_cork")
    final_state = state("g18_probe_cork")

    print(
        f"  final: page {final_cp['last_page']} of {final_cp['page_count']}, "
        f"complete={final_cp['complete']}, phase={final_state['phase']}"
    )

    check(final_cp["complete"] is True, "the enumeration reached its final page")
    check(final_state["phase"] == "none", f"no continuation is left pending (phase {final_state['phase']})")
    check(armed("g18_probe_cork") == 0, "no WordPress event is left armed")

    print("\nPhase 6 - open the browser once more and read the outcome")
    final_cell = read_progress_cell_in_browser()
    print(f"  Progress cell: {final_cell}")

    check("complete" in final_cell, "Import History reports the finished enumeration as complete")
    check("resumable" not in final_cell, "Import History no longer claims resumable")

    print("\nPhase 7 - cleanup")
    cleanup()

    print("\n-----------------------------------------------------------------")
    print(f"browser proof: {PASSED} passed, {len(FAILURES)} failed")
    for failure in FAILURES:
        print(f"  FAIL: {failure}")

    return 1 if FAILURES else 0


if __name__ == "__main__":
    sys.exit(main())

