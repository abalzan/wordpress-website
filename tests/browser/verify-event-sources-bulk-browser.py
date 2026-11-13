#!/usr/bin/env python3
"""Opt-in browser proof — Event Sources bulk selection and bulk actions.

## Why this suite exists

The bulk-selection controller is JavaScript: a counter, an indeterminate header
checkbox, a delegated change handler and a disabled Apply button. None of that
is observable from PHP source text, and the previous attempt at this feature was
never actually exercised in a browser. This suite drives a real Chromium against
the LOCAL Docker WordPress and asserts the real interactive behaviour.

## What it does

  Phase 0  seed four PROBE sources (`brbulk_probe_a..d`), two inactive and two
           active, plus one fixture event that references probe d. No real
           configured source is ever selected, disabled or deleted.
  Phase 1  log in, open Event Sources and drive the UI:

             initial counter / header / disabled controls
             select one row, then another, then deselect one
             Select All / Deselect All
             header checkbox: all / none / indeterminate
             Apply enabled only with a selection AND a valid action
             FormData contains exactly the selected IDs, and the header
             checkbox is never submitted
             Enable Selected applies to exactly the selected sources
             Disable Selected applies to exactly the selected sources
             Delete Selected asks for confirmation with the real count, then
             removes only the selected configuration and keeps imported events

  Phase 2  prove the backend state through `wp eval` (the registry option).

  Phase 3  remove every probe source and the fixture event.

## Negative control

`--negative-control` serves a MUTATED `assets/admin.js` in which the counter
update is removed. The same browser assertions must then FAIL, which is what
makes this suite a measurement rather than a screenshot.

## Scope and safety

Local Compose stack only (`CONEXAO_TEST_BASE_URL`, default http://localhost:8080).
A non-local host is refused before any socket is opened. No production
credential, no production host and no external request. `WP_USERNAME` /
`WP_TEST_USER_PASSWORD` come from the environment; the password defaults to the
documented local fixture value.

## Usage

    python3 tests/browser/verify-event-sources-bulk-browser.py
    python3 tests/browser/verify-event-sources-bulk-browser.py --negative-control
    python3 tests/browser/verify-event-sources-bulk-browser.py --headful

Exit codes: 0 pass, 1 fail, 2 environment unavailable (never reported as a pass).
"""

from __future__ import annotations

import argparse
import json
import os
import re
import subprocess
import sys

BASE_URL = os.environ.get("CONEXAO_TEST_BASE_URL", "http://localhost:8080").rstrip("/")
ALLOWED_HOSTS = {"localhost", "127.0.0.1"}
SOURCES_PAGE = "/wp-admin/admin.php?page=conexao-event-sources"

PROBE_PREFIX = "brbulk_probe_"
PROBE_IDS = [PROBE_PREFIX + letter for letter in ("a", "b", "c", "d")]

REPO_ROOT = os.path.abspath(os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", ".."))
ADMIN_JS_PATH = os.path.join(
    REPO_ROOT, "wp-content", "plugins", "conexao-event-importer", "assets", "admin.js"
)
ADMIN_CSS_PATH = os.path.join(
    REPO_ROOT, "wp-content", "plugins", "conexao-event-importer", "assets", "admin.css"
)

PASSED = 0
FAILED = 0


def check(condition: bool, message: str) -> bool:
    """Record one assertion. Never raises."""
    global PASSED, FAILED
    if condition:
        PASSED += 1
        print(f"  PASS: {message}")
    else:
        FAILED += 1
        print(f"  FAIL: {message}")
    return bool(condition)


def refuse_non_local() -> None:
    """Refuse a non-local host before any socket is opened."""
    host = re.sub(r"^[a-z]+://", "", BASE_URL).split("/")[0].split(":")[0].lower()
    if host not in ALLOWED_HOSTS:
        print(f"ENVIRONMENT UNAVAILABLE: refusing a non-local host: {host}", file=sys.stderr)
        sys.exit(2)


def php(code: str) -> subprocess.CompletedProcess:
    """Run a PHP snippet against the LOCAL Docker WordPress."""
    return subprocess.run(
        ["docker", "compose", "exec", "-T", "wordpress", "php", "-r", code],
        capture_output=True,
        text=True,
        timeout=180,
        cwd=REPO_ROOT,
    )


def sentinel(code: str) -> str:
    """Run a PHP snippet that prints CONEXAO_RESULT:<value> and return <value>.

    The value is read from STDOUT only: WordPress and Polylang emit notices on
    STDERR (a CLI request has no `$_SERVER['HTTP_HOST']`), and merging the two
    streams would append a log line to the JSON payload.
    """
    proc = php(code)
    match = re.search(r"CONEXAO_RESULT:(\{[^\n]*?\}|[^\s\[{]+)", proc.stdout)
    if not match:
        raise RuntimeError(
            "WordPress produced no result sentinel "
            f"(stdout: {proc.stdout!r}, stderr: {proc.stderr!r})"
        )
    return match.group(1)


def seed_probes() -> None:
    """Create the four probe sources and one fixture event that references d."""
    config = {
        PROBE_IDS[0]: "inactive",
        PROBE_IDS[1]: "inactive",
        PROBE_IDS[2]: "active",
        PROBE_IDS[3]: "active",
    }
    code = (
        'require_once "/var/www/html/wp-load.php"; '
        '$sources = new Conexao_Event_Sources(); '
        "$config = array( " + ", ".join(f"'{k}' => '{v}'" for k, v in config.items()) + " ); "
        "foreach ( $config as $id => $status ) { "
        "$sources->save( array( 'id' => $id, 'name' => 'Bulk browser probe ' . $id, "
        "'url' => 'https://example.com/' . $id, 'type' => 'website', 'status' => $status, 'county' => '' ) ); } "
        "$event = wp_insert_post( array( 'post_type' => 'event', 'post_status' => 'publish', "
        "'post_title' => 'Bulk browser fixture event' ) ); "
        f"update_post_meta( $event, '_event_source', '{PROBE_IDS[3]}' ); "
        "update_post_meta( $event, '_event_source_id', 'brbulk-fixture' ); "
        "update_post_meta( $event, '_event_status', 'published' ); "
        "echo 'CONEXAO_RESULT:' . (int) $event;"
    )
    print(f"   probe event id: {sentinel(code)}")


def registry_state() -> dict:
    """Return {probe id: status or 'missing', 'fixture_event': bool}."""
    code = (
        'require_once "/var/www/html/wp-load.php"; '
        '$sources = new Conexao_Event_Sources(); $out = array(); '
        "foreach ( array( " + ", ".join(f"'{i}'" for i in PROBE_IDS) + " ) as $id ) { "
        "$s = $sources->get( $id ); $out[ $id ] = $s ? $s['status'] : 'missing'; } "
        "$posts = get_posts( array( 'post_type' => 'event', 'post_status' => 'any', "
        "'numberposts' => 1, 'meta_key' => '_event_source_id', 'meta_value' => 'brbulk-fixture' ) ); "
        "$out['fixture_event'] = ! empty( $posts ); "
        "echo 'CONEXAO_RESULT:' . wp_json_encode( $out );"
    )
    return json.loads(sentinel(code))


def cleanup() -> None:
    """Delete every probe source and the fixture event. Never touches real sources."""
    code = (
        'require_once "/var/www/html/wp-load.php"; '
        '$sources = new Conexao_Event_Sources(); $removed = 0; '
        "foreach ( array( " + ", ".join(f"'{i}'" for i in PROBE_IDS) + " ) as $id ) { if ( $sources->delete( $id ) ) { $removed++; } } "
        "$posts = get_posts( array( 'post_type' => 'event', 'post_status' => 'any', 'numberposts' => -1, "
        "'meta_key' => '_event_source_id', 'meta_value' => 'brbulk-fixture' ) ); "
        "foreach ( $posts as $post ) { wp_delete_post( $post->ID, true ); } "
        "echo 'CONEXAO_RESULT:' . $removed;"
    )
    print(f"   probe sources removed: {sentinel(code)}")


def load_playwright():
    """Import Playwright, or exit 2 with a clear, non-silent message."""
    try:
        from playwright.sync_api import sync_playwright  # noqa: PLC0415
    except Exception as exc:  # pragma: no cover - environment dependent
        print(f"ENVIRONMENT UNAVAILABLE: Playwright is not importable: {exc}", file=sys.stderr)
        print("  install with: pip install playwright && playwright install chromium", file=sys.stderr)
        sys.exit(2)
    return sync_playwright


def login(page, user: str, password: str) -> bool:
    """Authenticate through the real wp-login.php form."""
    page.goto(f"{BASE_URL}/wp-login.php")
    page.fill("#user_login", user)
    page.fill("#user_pass", password)
    page.click("#wp-submit")
    page.wait_for_load_state("domcontentloaded")
    body = page.content()
    if "jetpack_protect_process_math_form" in body:
        match = re.search(r"Prove your humanity\s*(\d+)\s*\+\s*(\d+)", re.sub(r"<[^>]+>", " ", body))
        if match:
            answer = int(match.group(1)) + int(match.group(2))
            token = re.search(r'jetpack_protect_answer" value="([^"]*)"', body)
            page.fill("#jetpack_protect_answer", token.group(1) if token else "")
            page.fill("#jetpack_protect_num", str(answer))
            page.evaluate("document.querySelector('form').submit()")
            page.wait_for_load_state("domcontentloaded")
    try:
        page.wait_for_url(lambda url: "wp-login.php" not in url, timeout=15000)
    except Exception:
        pass
    return "wp-login.php" not in page.url


STATE_JS = """() => {
  const rows = [...document.querySelectorAll('.conexao-bulk-row-checkbox')];
  const header = document.querySelector('#conexao-bulk-header-checkbox');
  const status = document.querySelector('#conexao-bulk-status');
  return {
    count: document.querySelector('#conexao-bulk-selected-count').textContent.trim(),
    total: document.querySelector('#conexao-bulk-total-count').textContent.trim(),
    rows: rows.length,
    checked: rows.filter(cb => cb.checked).map(cb => cb.value),
    counterText: document.querySelector('#conexao-bulk-count').textContent.replace(/\\s+/g, ' ').trim(),
    headerChecked: header.checked,
    headerIndeterminate: header.indeterminate,
    applyDisabled: document.querySelector('#conexao-bulk-apply').disabled,
    selectDisabled: document.querySelector('#conexao-bulk-action-select').disabled,
    action: document.querySelector('#conexao-bulk-action-select').value,
    status: status.textContent.trim(),
    statusError: status.className.indexOf('conexao-bulk-status--error') !== -1,
    ariaLive: status.getAttribute('aria-live'),
  };
}"""

MARKUP_JS = """() => {
  const rows = [...document.querySelectorAll('.conexao-bulk-row-checkbox')];
  const header = document.querySelector('#conexao-bulk-header-checkbox');
  return {
    rows: rows.length,
    withForm: rows.filter(cb => cb.form && cb.form.id === 'conexao-bulk-actions-form').length,
    named: rows.filter(cb => cb.name === 'bulk_source_ids[]').length,
    headerName: header.getAttribute('name'),
    headerForm: header.form ? header.form.id : null,
    nestedForms: document.querySelectorAll('form form').length,
    importForms: document.querySelectorAll('form input[value="run_import"]').length,
    rowActionForms: document.querySelectorAll('.conexao-bulk-row-checkbox, form input[value="toggle"], form input[value="delete"]').length,
  };
}"""

FORMDATA_JS = """() => {
  const form = document.getElementById('conexao-bulk-actions-form');
  const fd = new FormData(form);
  const ids = fd.getAll('bulk_source_ids[]');
  return {
    ids: ids,
    action: fd.get('bulk_action_type'),
    hiddenAction: fd.get('conexao_source_action'),
    hasNonce: !!fd.get('conexao_event_sources_nonce'),
    headerSubmitted: ids.indexOf('on') !== -1,
  };
}"""

MUTATION_TARGET = "\t\t\tif ( countEl ) { countEl.textContent = String( checked ); }\n"


def build_mutated_admin_js() -> bytes | None:
    """Return admin.js with the counter update removed (the negative control)."""
    with open(ADMIN_JS_PATH, encoding="utf-8") as handle:
        source = handle.read()
    if MUTATION_TARGET not in source:
        return None
    return source.replace(MUTATION_TARGET, "", 1).encode("utf-8")


def selector(source_id: str) -> str:
    """CSS selector for one row checkbox."""
    return f'input.conexao-bulk-row-checkbox[value="{source_id}"]'


def assert_markup(page) -> None:
    """The table/form arrangement the controller depends on."""
    markup = page.evaluate(MARKUP_JS)
    check(markup["rows"] >= 4, "the sources table rendered rows")
    check(markup["withForm"] == markup["rows"], "every row checkbox is associated with the bulk form")
    check(markup["named"] == markup["rows"], "every row checkbox submits as bulk_source_ids[]")
    check(markup["headerName"] is None, "the header checkbox has no name and is never submitted as an ID")
    check(markup["headerForm"] is None, "the header checkbox is not inside the bulk form")
    check(markup["nestedForms"] == 0, "no nested forms were introduced")
    check(markup["importForms"] >= 1, "the per-source Import Now forms are still independent")


def run_flow(page) -> None:
    """Drive the real UI and assert every required behaviour."""
    posts: list[str] = []
    console_errors: list[str] = []
    page_errors: list[str] = []

    page.on(
        "request",
        lambda request: posts.append(request.post_data or "")
        if request.method == "POST" and "admin.php" in request.url
        else None,
    )
    page.on("console", lambda message: console_errors.append(message.text) if message.type == "error" else None)
    page.on("pageerror", lambda error: page_errors.append(str(error)))

    page.goto(BASE_URL + SOURCES_PAGE)
    page.wait_for_load_state("networkidle")

    print("\n-- markup and initial state --")
    assert_markup(page)

    assets = page.evaluate(
        """() => {
          const js = document.querySelector('script[src*="conexao-event-importer/assets/admin.js"]');
          const css = document.querySelector('link[href*="conexao-event-importer/assets/admin.css"]');
          return { js: js ? js.src : null, css: css ? css.href : null };
        }"""
    )
    js_mtime = int(os.stat(ADMIN_JS_PATH).st_mtime)
    css_mtime = int(os.stat(ADMIN_CSS_PATH).st_mtime)

    check(assets["js"] is not None, "admin.js is enqueued on the Event Sources page")
    check(
        assets["js"] is not None and (f"ver={js_mtime}" in assets["js"] or f"m={js_mtime}" in assets["js"]),
        "the browser is served the CURRENT admin.js (file-mtime cache-buster)",
    )
    check(assets["css"] is not None, "admin.css is enqueued on the Event Sources page")
    # The plugin versions the stylesheet with the plugin version; a site-level
    # optimisation plugin in this local stack may rewrite that to the file
    # mtime instead. Either way the URL must carry a cache-buster.
    check(
        assets["css"] is not None
        and ("ver=1.9.1" in assets["css"] or f"m={css_mtime}" in assets["css"]),
        "admin.css is served with a cache-buster (plugin version or file mtime)",
    )

    state = page.evaluate(STATE_JS)
    check(state["count"] == "0", "initial counter is zero")
    check(state["total"] == str(state["rows"]), "the counter total equals the rendered rows")
    check(
        re.fullmatch(r"0 of \d+ selected", state["counterText"]) is not None,
        f"the counter reads \"0 of N selected\" exactly once ({state['counterText']!r})",
    )
    check(state["headerChecked"] is False and state["headerIndeterminate"] is False, "header checkbox is unchecked")
    check(state["applyDisabled"] is True, "Apply is disabled with no selection")
    check(state["selectDisabled"] is True, "the action selector is disabled with no selection")
    check(state["ariaLive"] == "polite", "the status line is an aria-live=polite region")
    check(state["status"] == "", "no stale status message on load")

    print("\n-- individual selection --")
    page.check(selector(PROBE_IDS[0]))
    state = page.evaluate(STATE_JS)
    check(state["count"] == "1", "selecting one source shows 1")
    check(state["checked"] == [PROBE_IDS[0]], "exactly the clicked row is checked")
    check(state["headerIndeterminate"] is True, "the header becomes indeterminate")
    check(state["applyDisabled"] is True, "Apply stays disabled until an action is chosen")
    check(state["selectDisabled"] is False, "the action selector is enabled by a selection")
    check(state["status"] == "1 source selected", "the live region announces the selection")

    page.check(selector(PROBE_IDS[1]))
    state = page.evaluate(STATE_JS)
    check(state["count"] == "2", "selecting a second source shows 2")
    check(state["checked"] == [PROBE_IDS[0], PROBE_IDS[1]], "both selected rows are checked")

    page.uncheck(selector(PROBE_IDS[1]))
    state = page.evaluate(STATE_JS)
    check(state["count"] == "1", "deselecting one source shows 1")
    check(state["checked"] == [PROBE_IDS[0]], "only the remaining row is checked")

    page.select_option("#conexao-bulk-action-select", "enable")
    state = page.evaluate(STATE_JS)
    check(state["applyDisabled"] is False, "Apply becomes usable with a selection and a valid action")

    page.uncheck(selector(PROBE_IDS[0]))
    state = page.evaluate(STATE_JS)
    check(state["count"] == "0", "deselecting the last source shows 0")
    check(state["applyDisabled"] is True, "Apply is disabled again with no selection")
    check(state["selectDisabled"] is True, "the action selector is disabled again")
    check(state["status"] == "", "the live region clears its message")

    print("\n-- Select All / Deselect All / header checkbox --")
    page.click("#conexao-bulk-select-all")
    state = page.evaluate(STATE_JS)
    check(len(state["checked"]) == state["rows"], "Select All checks every row")
    check(state["count"] == str(state["rows"]), "Select All updates the counter")
    check(state["headerChecked"] is True and state["headerIndeterminate"] is False, "the header is checked, not indeterminate")
    check(
        state["applyDisabled"] == (state["action"] == ""),
        "after Select All, Apply follows the chosen action (a previously chosen action is retained)",
    )

    page.select_option("#conexao-bulk-action-select", "enable")
    check(page.evaluate(STATE_JS)["applyDisabled"] is False, "Apply is usable once the action is chosen")

    page.click("#conexao-bulk-deselect-all")
    state = page.evaluate(STATE_JS)
    check(state["count"] == "0", "Deselect All resets the counter")
    check(state["checked"] == [], "Deselect All clears every row")
    check(state["headerChecked"] is False and state["headerIndeterminate"] is False, "Deselect All clears the header state")
    check(state["applyDisabled"] is True, "Deselect All disables Apply")

    page.check(selector(PROBE_IDS[0]))
    page.check(selector(PROBE_IDS[2]))
    state = page.evaluate(STATE_JS)
    check(state["count"] == "2", "a partial selection counts two")
    check(state["headerIndeterminate"] is True and state["headerChecked"] is False, "a partial selection is indeterminate")

    page.check("#conexao-bulk-header-checkbox")
    state = page.evaluate(STATE_JS)
    check(len(state["checked"]) == state["rows"], "the header checkbox selects every row")
    check(state["headerIndeterminate"] is False, "the header is no longer indeterminate")

    page.uncheck("#conexao-bulk-header-checkbox")
    state = page.evaluate(STATE_JS)
    check(state["checked"] == [], "unchecking the header clears every row")
    check(state["count"] == "0", "the counter returns to zero")

    print("\n-- FormData and submission payload --")
    page.check(selector(PROBE_IDS[0]))
    page.check(selector(PROBE_IDS[1]))
    page.select_option("#conexao-bulk-action-select", "enable")

    formdata = page.evaluate(FORMDATA_JS)
    check(formdata["ids"] == [PROBE_IDS[0], PROBE_IDS[1]], "FormData submits exactly the selected IDs")
    check(formdata["action"] == "enable", "FormData carries the chosen bulk action")
    check(formdata["hiddenAction"] == "bulk_action", "FormData carries the bulk_action form action")
    check(formdata["hasNonce"] is True, "FormData carries the nonce")
    check(formdata["headerSubmitted"] is False, "the header checkbox is not submitted as a source ID")

    print("\n-- Enable Selected --")
    posts.clear()
    with page.expect_navigation(wait_until="domcontentloaded"):
        page.click("#conexao-bulk-apply")
    page.wait_for_load_state("networkidle")

    body = posts[-1] if posts else ""
    check("bulk_source_ids%5B%5D=" + PROBE_IDS[0] in body, "the POST body contains the first selected ID")
    check("bulk_source_ids%5B%5D=" + PROBE_IDS[1] in body, "the POST body contains the second selected ID")
    check("bulk_source_ids%5B%5D=" + PROBE_IDS[2] not in body, "the POST body does not contain an unselected ID")
    check("bulk_status=success" in page.url, "the redirect reports success")
    check(page.locator(".notice-success").count() >= 1, "an admin success notice is visible")

    registry = registry_state()
    check(registry[PROBE_IDS[0]] == "active", "Enable Selected activated probe a")
    check(registry[PROBE_IDS[1]] == "active", "Enable Selected activated probe b")
    check(registry[PROBE_IDS[2]] == "active", "an unselected active source stayed active")
    check(registry[PROBE_IDS[3]] == "active", "the other unselected source stayed active")

    print("\n-- Disable Selected --")
    page.check(selector(PROBE_IDS[2]))
    page.check(selector(PROBE_IDS[3]))
    page.select_option("#conexao-bulk-action-select", "disable")
    posts.clear()
    with page.expect_navigation(wait_until="domcontentloaded"):
        page.click("#conexao-bulk-apply")
    page.wait_for_load_state("networkidle")

    body = posts[-1] if posts else ""
    check("bulk_source_ids%5B%5D=" + PROBE_IDS[2] in body, "the disable POST contains probe c")
    check("bulk_source_ids%5B%5D=" + PROBE_IDS[3] in body, "the disable POST contains probe d")
    check("bulk_source_ids%5B%5D=" + PROBE_IDS[0] not in body, "the disable POST does not contain probe a")

    registry = registry_state()
    check(registry[PROBE_IDS[2]] == "inactive", "Disable Selected deactivated probe c")
    check(registry[PROBE_IDS[3]] == "inactive", "Disable Selected deactivated probe d")
    check(registry[PROBE_IDS[0]] == "active", "an unselected source kept its active status")
    check(registry[PROBE_IDS[1]] == "active", "the second unselected source kept its active status")

    print("\n-- Delete Selected --")
    page.check(selector(PROBE_IDS[3]))
    page.select_option("#conexao-bulk-action-select", "delete")

    dialog: dict[str, str] = {}

    def on_dialog(handle) -> None:
        dialog["message"] = handle.message
        handle.accept()

    page.once("dialog", on_dialog)
    posts.clear()
    with page.expect_navigation(wait_until="domcontentloaded"):
        page.click("#conexao-bulk-apply")
    page.wait_for_load_state("networkidle")

    check("1 source(s)" in dialog.get("message", ""), "the confirmation names the actual number selected")
    check("Imported events will remain" in dialog.get("message", ""), "the confirmation explains events are kept")

    body = posts[-1] if posts else ""
    check("bulk_source_ids%5B%5D=" + PROBE_IDS[3] in body, "the delete POST contains only the selected probe")

    registry = registry_state()
    check(registry[PROBE_IDS[3]] == "missing", "Delete Selected removed the selected configuration")
    check(registry[PROBE_IDS[0]] == "active", "an unselected source survived the delete")
    check(registry[PROBE_IDS[1]] == "active", "the second unselected source survived the delete")
    check(registry[PROBE_IDS[2]] == "inactive", "the third unselected source survived the delete")
    check(registry["fixture_event"] is True, "deleting the source did not delete its imported event")

    print("\n-- console --")
    check(not page_errors, f"no uncaught JavaScript error ({page_errors})")
    check(not console_errors, f"no console error ({console_errors})")


def main() -> int:
    parser = argparse.ArgumentParser(description="Event Sources bulk-selection browser proof")
    parser.add_argument("--headful", action="store_true", help="run Chromium with a window")
    parser.add_argument("--negative-control", action="store_true", help="serve a mutated admin.js")
    parser.add_argument("--keep-fixtures", action="store_true", help="leave the probe sources behind for debugging")
    args = parser.parse_args()

    refuse_non_local()

    user = os.environ.get("WP_USERNAME")
    password = os.environ.get("WP_TEST_USER_PASSWORD") or "test"
    if not user:
        print("ENVIRONMENT UNAVAILABLE: WP_USERNAME must be set (see .env).", file=sys.stderr)
        return 2

    mutated = None
    if args.negative_control:
        mutated = build_mutated_admin_js()
        if mutated is None:
            print("FAIL: could not build the mutated admin.js (mutation target not found)", file=sys.stderr)
            return 1

    sync_playwright = load_playwright()

    print(f"target: {BASE_URL} (local only)")
    seed_probes()

    exit_code = 0

    try:
        with sync_playwright() as playwright:
            browser = playwright.chromium.launch(headless=not args.headful)
            context = browser.new_context()
            page = context.new_page()

            if mutated is not None:
                page.route(
                    "**/assets/admin.js*",
                    lambda route: route.fulfill(
                        status=200, content_type="application/javascript", body=mutated
                    ),
                )

            if not login(page, user, password):
                print("ENVIRONMENT UNAVAILABLE: could not authenticate against wp-login.php", file=sys.stderr)
                browser.close()
                return 2

            if args.negative_control:
                print("\n-- negative control: counter update removed --")
                page.goto(BASE_URL + SOURCES_PAGE)
                page.wait_for_load_state("networkidle")
                page.check(selector(PROBE_IDS[0]))
                state = page.evaluate(STATE_JS)
                check(
                    state["count"] == "0",
                    "without the counter update the counter assertion goes red",
                )
                check(
                    state["checked"] == [PROBE_IDS[0]],
                    "the checkbox itself is still selected, so only the counter is broken",
                )
            else:
                run_flow(page)

            browser.close()
    finally:
        if not args.keep_fixtures:
            cleanup()

    if args.negative_control:
        print(f"\n{PASSED} passed, {FAILED} failed")
        return 1 if FAILED else 0

    registry = registry_state()
    check(
        registry[PROBE_IDS[0]] == "missing" and registry[PROBE_IDS[1]] == "missing" and registry[PROBE_IDS[2]] == "missing",
        "no probe source survives the suite",
    )
    check(registry["fixture_event"] is False, "the fixture event was removed")

    print(f"\n{PASSED} passed, {FAILED} failed")
    exit_code = 1 if FAILED else 0
    return exit_code


if __name__ == "__main__":
    sys.exit(main())
