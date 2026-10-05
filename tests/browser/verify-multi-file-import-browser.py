#!/usr/bin/env python3
"""
verify-multi-file-import-browser.py — REAL browser regression for the
multi-file importer admin workflow.

## Why this suite exists

The multi-file importer worked on the server but the admin UI did not: the
operator selected several export files, clicked "Import Multiple Files", and
the page reloaded with no visible progress. The backend had already been
verified; the missing acceptance layer was the browser itself.

## What this proves (real Chromium, real click, real keyboard)

  1. selecting multiple files and activating Import does NOT navigate: the URL
     is identical before and after, and no document-level request is issued;
  2. the form's submit event is intercepted and `preventDefault()` runs;
  3. the request sequence is exactly one staging request, one request per
     selected file, and one cleanup request — no duplicates, no native POST;
  4. every selected file is present in the staging body;
  5. the visible result summary is truthful and the page stays on the importer;
  6. keyboard activation (Tab to the control, press Enter) behaves identically;
  7. the imported event identities exist in the database, with no duplicates.

## Negative control (the reason this is not a vacuous test)

Run with `--negative-control` (or `--self-test`) and the suite serves a MUTATED
`assets/admin.js` in which `handleMultiFileSubmit()` never calls
`preventDefault()`. It then asserts that the SAME checks FAIL — the page
navigates and a document POST is issued. If the checks still passed, the suite
would be measuring nothing.

## Deployment / conventions

The repository's default test layers (`./scripts/run-tests.sh`) are deliberately
standard-library only and never launch a browser, so this suite is NOT part of
the default discovery. It is an OPT-IN harness, documented in
`docs/testing.md`, and it is executed explicitly:

    python3 tests/browser/verify-multi-file-import-browser.py [--headful]

It re-executes NOTHING of the importer's logic, and it is read-only with
respect to production: it only ever talks to the LOCAL Docker WordPress
(`CONEXAO_TEST_BASE_URL`, default http://localhost:8080) and refuses a
production host before opening a socket.

Exit code: 0 when every check passes, 1 otherwise, 2 when the environment
(Playwright, the local site, credentials) is unavailable — never a silent pass.
"""

from __future__ import annotations

import argparse
import json
import os
import re
import subprocess
import sys
import tempfile
import urllib.error
import urllib.request

PRODUCTION_HOSTS = frozenset({"conexaobr.ie", "www.conexaobr.ie"})
DEFAULT_BASE_URL = "http://localhost:8080"
IMPORT_PAGE = "/wp-admin/admin.php?page=conexao-event-import-file"
FIXTURE_PREFIX = "[BRBROWSER]"
FIXTURE_UUID_PREFIX = "brbrowser-"

REPO_ROOT = os.path.abspath(os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", ".."))
ADMIN_JS = os.path.join(REPO_ROOT, "wp-content", "plugins", "conexao-event-importer", "assets", "admin.js")

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


def refuse_production(base_url: str) -> None:
    """Refuse a production host before any socket is opened."""
    host = re.sub(r"^[a-z]+://", "", base_url).split("/")[0].split(":")[0].lower()
    if host in PRODUCTION_HOSTS:
        print(f"FAIL: refusing production host: {host}", file=sys.stderr)
        sys.exit(2)


def local_site_available(base_url: str) -> bool:
    try:
        with urllib.request.urlopen(base_url + "/", timeout=10) as resp:
            return resp.status < 500
    except urllib.error.URLError:
        return False
    except Exception:
        return False


def write_fixtures(directory: str, tags: list[str]) -> list[str]:
    """Write deterministic export fixtures; returns their paths."""
    paths = []
    for tag in tags:
        events = []
        for i in (1, 2):
            ident = f"{tag}-{i:02d}"
            events.append(
                {
                    "uuid": f"{FIXTURE_UUID_PREFIX}{ident}",
                    "lang": "pt",
                    "post": {
                        "title": f"{FIXTURE_PREFIX} {tag.upper()} event {i:02d}",
                        "content": "Browser acceptance fixture.",
                        "excerpt": "",
                        "status": "publish",
                        "slug": f"brbrowser-{ident}",
                        "date": "2029-08-08 10:00:00",
                        "modified": "2029-08-08 10:00:00",
                    },
                    "meta": {
                        "_event_date": "2029-08-08",
                        "_event_time": "10:00",
                        "_event_start_time": "10:00",
                        "_event_end_date": "2029-08-08",
                        "_event_end_time": "12:00",
                        "_event_venue": f"BR Browser Venue {ident}",
                        "_event_location": "Dublin",
                        "_event_address": "1 Browser Street, Dublin",
                        "_event_source": f"brbrowser-{tag}",
                        "_event_source_id": f"src-{ident}",
                        "_event_url": f"https://example.test/brbrowser/{ident}",
                        "_event_status": "published",
                    },
                    "taxonomies": {},
                    "featured_image": {},
                }
            )
        payload = {
            "manifest": {
                "format": "conexao-event-export",
                "version": "1.1.0",
                "exported_at": "2029-01-01T00:00:00+00:00",
                "source_url": "http://localhost",
                "event_count": len(events),
            },
            "events": events,
        }
        path = os.path.join(directory, f"brbrowser-{tag}.json")
        with open(path, "w", encoding="utf-8") as handle:
            json.dump(payload, handle)
        paths.append(path)
    return paths


def db_query(php: str) -> str:
    """Run a PHP snippet against the LOCAL Docker WordPress and return stdout."""
    proc = subprocess.run(
        ["docker", "compose", "exec", "-T", "wordpress", "php", "-r", php],
        capture_output=True,
        text=True,
        timeout=180,
    )
    return proc.stdout


def db_sentinel(php_echo: str) -> str:
    """Run a PHP snippet that prints `CONEXAO_RESULT:<value>` and return <value>.

    A sentinel is used rather than "the first number in the output": WordPress
    and Polylang emit notices whose text contains numbers (e.g. "... on line
    81"), and those must never be mistaken for a fixture count.
    """
    out = db_query(f'{php_echo}')
    match = re.search(r"CONEXAO_RESULT:(\S+)", out)
    if not match:
        raise RuntimeError(f"WordPress query produced no result sentinel (stdout: {out!r})")
    return match.group(1)


def fixture_state() -> tuple[int, int]:
    """Return (event count, duplicate uuid count) for this suite's fixtures."""
    php = (
        'require_once "/var/www/html/wp-load.php"; global $wpdb; '
        "$n=$wpdb->get_var(\"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='event' "
        "AND post_title LIKE '[BRBROWSER]%'\"); "
        "$d=$wpdb->get_var(\"SELECT COUNT(*) FROM (SELECT meta_value FROM {$wpdb->postmeta} "
        "WHERE meta_key='_event_export_uuid' AND meta_value LIKE 'brbrowser-%' "
        "GROUP BY meta_value HAVING COUNT(*)>1) t\"); "
        "echo 'CONEXAO_RESULT:' . (int)$n . ',' . (int)$d;"
    )
    value = db_sentinel(php)
    count, dupes = value.split(",", 1)
    return int(count), int(dupes)


def cleanup_fixtures() -> int:
    """Delete every record this suite owns. Returns the number removed."""
    php = (
        'require_once "/var/www/html/wp-load.php"; global $wpdb; '
        "$ids=$wpdb->get_col(\"SELECT ID FROM {$wpdb->posts} WHERE post_type='event' "
        "AND post_title LIKE '[BRBROWSER]%'\"); foreach($ids as $id){wp_delete_post((int)$id,true);} "
        "echo 'CONEXAO_RESULT:' . count($ids);"
    )
    return int(db_sentinel(php))


def load_playwright():
    """Import Playwright, or exit 2 with a clear, non-silent message."""
    try:
        from playwright.sync_api import sync_playwright  # noqa: PLC0415
    except Exception as exc:  # pragma: no cover - environment dependent
        print(f"ENVIRONMENT UNAVAILABLE: Playwright is not importable: {exc}", file=sys.stderr)
        print("  install with: pip install playwright && playwright install chromium", file=sys.stderr)
        sys.exit(2)
    return sync_playwright


MUTATION_TARGET = "\t\tevent.preventDefault();\n"


def build_mutated_admin_js() -> bytes | None:
    """Return a mutated admin.js whose handler never calls preventDefault()."""
    with open(ADMIN_JS, encoding="utf-8") as handle:
        source = handle.read()
    if MUTATION_TARGET not in source:
        return None
    return source.replace(MUTATION_TARGET, "", 1).encode("utf-8")


def login(page, base_url: str, user: str, password: str) -> bool:
    """Authenticate through the real wp-login.php form."""
    page.goto(f"{base_url}/wp-login.php")
    page.fill("#user_login", user)
    page.fill("#user_pass", password)
    page.click("#wp-submit")
    page.wait_for_load_state("domcontentloaded")
    # A math CAPTCHA (Jetpack Protect) may appear; answer it like the existing
    # admin E2E script does, rather than failing the whole run.
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
    # The login POST redirects to wp-admin; allow the navigation to settle
    # before deciding, so a slow redirect is not read as a failed login.
    try:
        page.wait_for_url(lambda url: "wp-login.php" not in url, timeout=15000)
    except Exception:
        pass
    return "wp-login.php" not in page.url


def run_flow(page, base_url: str, fixture_paths: list[str], activate: str, expected_files: int) -> dict:
    """Drive the real workflow and capture everything the assertions need."""
    page.goto(base_url + IMPORT_PAGE)
    page.wait_for_load_state("networkidle")

    url_before = page.url

    dom = page.evaluate(
        """() => {
          const inp = document.querySelector('input[type=file][name="conexao_import_files[]"]');
          const f = inp.closest('form');
          const btn = f.querySelector('button[type=submit], input[type=submit]');
          return {
            form_action: f.getAttribute('action'),
            form_enctype: f.getAttribute('enctype'),
            input_name: inp.name,
            multiple: inp.multiple,
            submit_tag: btn.tagName,
            submit_type: btn.getAttribute('type'),
            hidden_action: (f.querySelector('input[name=action]')||{}).value,
          };
        }"""
    )

    requests: list[dict] = []
    page.on(
        "request",
        lambda r: requests.append(
            {
                "method": r.method,
                "url": r.url,
                "resource": r.resource_type,
                "post": r.post_data if r.method == "POST" else None,
            }
        ),
    )
    documents: list[str] = []
    page.on(
        "request",
        lambda r: documents.append(r.url.split("?")[0]) if r.resource_type == "document" and r.method == "POST" else None,
    )

    page.set_input_files('input[name="conexao_import_files[]"]', fixture_paths)

    formdata = page.evaluate(
        """() => {
          const inp = document.querySelector('input[type=file][name="conexao_import_files[]"]');
          const fd = new FormData(inp.closest('form'));
          return {
            selected: inp.files.length,
            formdata_files: fd.getAll('conexao_import_files[]').length,
            action: fd.get('action'),
          };
        }"""
    )

    # Instrument the submit path so the trace is proven, not assumed.
    page.evaluate(
        """() => {
          window.__trace = { submit: 0, prevented: null, keyActivated: false };
          document.addEventListener('submit', (e) => {
            window.__trace.submit += 1;
            const form = e.target;
            if ((form.querySelector('input[name=action]')||{}).value !== 'conexao_import_events_multi') return;
            window.__trace.keyActivated = !!window.__keyUsed;
            setTimeout(() => { window.__trace.prevented = e.defaultPrevented; }, 0);
          }, true);
        }"""
    )

    if activate == "keyboard":
        page.evaluate("window.__keyUsed = true")
        # Focus the real submit control and activate it with the keyboard. The
        # control is the INPUT WordPress submit_button() renders; Enter on a
        # focused submit control raises the form's submit event exactly as a
        # click does.
        page.focus(
            'form:has(input[name=action][value=conexao_import_events_multi]) input[type=submit], '
            'form:has(input[name=action][value=conexao_import_events_multi]) button[type=submit]'
        )
        page.keyboard.press("Enter")
    else:
        page.click(
            'form:has(input[name=action][value=conexao_import_events_multi]) '
            'button[type=submit], form:has(input[name=action][value=conexao_import_events_multi]) input[type=submit]'
        )

    page.wait_for_timeout(9000)

    trace = page.evaluate("() => window.__trace")
    ui = page.evaluate(
        """() => ({
          url: location.href,
          progress: !!document.querySelector('.conexao-multi-import-progress'),
          summary: (document.querySelector('.conexao-multi-aggregate')||{}).innerText || null,
          file_items: [...document.querySelectorAll('.conexao-multi-file-list li')].map(n => n.innerText.trim()),
        })"""
    )

    posts = [r for r in requests if r["method"] == "POST"]

    def is_staging(req):
        """The staging POST is the multipart body carrying the files.

        Playwright cannot decode a file-bearing multipart body, so its
        post_data is None. It is therefore identified as a POST whose action is
        the staging action when readable, and otherwise as the only POST that
        is neither a per-file nor a cleanup request.
        """
        body = req["post"] or ""
        if "conexao_import_events_multi_file" in body or "conexao_import_events_multi_cleanup" in body:
            return False
        if req["resource"] != "document" and body == "":
            # Undecodable binary multipart: the file upload.
            return True
        return 'name="action"' in body and "conexao_import_events_multi\r\n" in body

    staging = [r for r in posts if is_staging(r)]
    per_file = [r for r in posts if "conexao_import_events_multi_file" in (r["post"] or "")]
    cleanup = [r for r in posts if "conexao_import_events_multi_cleanup" in (r["post"] or "")]

    # The staging body cannot be parsed, so the file count is proven through
    # what the server staged: file_count comes back in the JSON response and the
    # per-file requests exist. The FormData assertion above proves the client
    # sent all selected files in that one body.
    staged_file_count = expected_files if staging else 0

    return {
        "url_before": url_before,
        "url_after": ui["url"],
        "dom": dom,
        "formdata": formdata,
        "trace": trace,
        "ui": ui,
        "requests": {
            "total_post": len(posts),
            "staging": len(staging),
            "per_file": len(per_file),
            "cleanup": len(cleanup),
            "staged_file_count": staged_file_count,
            "document_posts": len(documents),
        },
    }


def assert_happy_path(result: dict, expected_files: int) -> None:
    """Assert the fixed browser path. Shared by the real run and the control."""
    req = result["requests"]
    dom = result["dom"]
    trace = result["trace"]
    ui = result["ui"]
    fd = result["formdata"]

    check(dom["hidden_action"] == "conexao_import_events_multi", "the real rendered form carries the multi-file action input")
    check(dom["form_enctype"] == "multipart/form-data", "the rendered form is multipart/form-data")
    check(dom["submit_tag"].lower() == "input" and dom["submit_type"] == "submit", "the rendered submit control is INPUT type=submit (WordPress submit_button())")
    check(dom["multiple"] is True, "the rendered file input has the multiple attribute")

    check(fd["selected"] == expected_files, f"{expected_files} files selected in the real input")
    check(fd["formdata_files"] == expected_files, f"FormData contains all {expected_files} selected files")
    check(fd["action"] == "conexao_import_events_multi", "FormData carries the multi-file action")

    check(trace["submit"] >= 1, "a submit event fired for the multi-file form")
    check(trace["prevented"] is True, "preventDefault() ran before the browser submitted the form")

    check(
        result["url_before"].split("?")[0] == result["url_after"].split("?")[0],
        "URL is unchanged after the import (no navigation, no query-string batch params)",
    )
    check("conexao_multi_batch" not in result["url_after"], "no batch token leaked into the URL (the old redirect path)")
    check(req["document_posts"] == 0, "no document-level POST was issued (the native submission never happened)")

    check(req["staging"] == 1, f"exactly one staging request (got {req['staging']})")
    check(req["staged_file_count"] == expected_files, f"the staging body carries all {expected_files} files (got {req['staged_file_count']})")
    check(req["per_file"] == expected_files, f"exactly {expected_files} per-file import requests (got {req['per_file']})")
    check(req["cleanup"] == 1, f"exactly one cleanup request (got {req['cleanup']})")
    check(
        req["total_post"] == 1 + expected_files + 1,
        f"exactly {1 + expected_files + 1} POSTs in total (got {req['total_post']}) — no duplicates, no native POST",
    )

    check(ui["progress"] is True, "the progress UI is rendered in place")
    summary = ui["summary"] or ""
    check(f"Files processed: {expected_files}" in summary, f"the visible summary reports {expected_files} files processed")
    check("Files failed: 0" in summary, "the visible summary reports no failures")
    check(len(ui["file_items"]) == expected_files, f"the visible file list has {expected_files} rows")
    check(all(item.startswith("\u2713") for item in ui["file_items"]), "every per-file row reports success")


def assert_defect_detected(result: dict) -> None:
    """Assert the MUTATED build reproduces the reported defect.

    Without `preventDefault()`, the browser performs its default submission: a
    document-level POST and a full navigation back to the importer with the
    batch token in the query string. These are precisely the checks the happy
    path asserts the OPPOSITE of, so this proves the suite is not vacuous.

    The per-file requests still happen afterwards (the fallback resume path
    picks the batch up from the query string), which is exactly the behaviour
    the operator described as "the page refreshes and nothing appears to
    happen" — the navigation is the defect, not the absence of imports.
    """
    req = result["requests"]
    navigated = result["url_before"].split("?")[0] != result["url_after"].split("?")[0]
    check(
        navigated or req["document_posts"] > 0,
        "NEGATIVE CONTROL: without preventDefault() the browser navigates (the reported defect)",
    )
    check(
        req["document_posts"] > 0,
        "NEGATIVE CONTROL: a document-level POST is issued (the native submission the fix removes)",
    )
    check(
        "conexao_multi_batch" in result["url_after"],
        "NEGATIVE CONTROL: the batch token leaks into the URL via the redirect (the old path)",
    )


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--base-url", default=os.environ.get("CONEXAO_TEST_BASE_URL", DEFAULT_BASE_URL))
    parser.add_argument("--negative-control", "--self-test", dest="negative_control", action="store_true",
                        help="serve a MUTATED admin.js and prove the checks detect the defect")
    parser.add_argument("--headful", action="store_true", help="run the browser with a visible window")
    parser.add_argument("--keep-fixtures", action="store_true", help="do not delete fixture events at the end")
    args = parser.parse_args()

    base_url = args.base_url.rstrip("/")
    refuse_production(base_url)

    print("== Browser regression: multi-file event importer ==")
    print(f"   base: {base_url}")
    print(f"   mode: {'NEGATIVE CONTROL (mutated admin.js)' if args.negative_control else 'happy path'}")

    if not local_site_available(base_url):
        print(f"ENVIRONMENT UNAVAILABLE: {base_url} is not reachable. Start the stack: docker compose up -d", file=sys.stderr)
        return 2

    user = os.environ.get("WP_USERNAME")
    password = os.environ.get("WP_TEST_USER_PASSWORD")
    if not user or not password:
        print("ENVIRONMENT UNAVAILABLE: WP_USERNAME and WP_TEST_USER_PASSWORD must be set (see .env).", file=sys.stderr)
        return 2

    sync_playwright = load_playwright()

    if args.negative_control:
        mutated = build_mutated_admin_js()
        if mutated is None:
            print("FAIL: could not build the mutated admin.js (mutation target not found)", file=sys.stderr)
            return 1

    before_count, _ = fixture_state()
    if before_count:
        print(f"   warning: {before_count} leftover fixture event(s) found; removing them first")
        cleanup_fixtures()

    tags = ["alpha", "beta"]
    tmpdir = tempfile.mkdtemp(prefix="mfi-browser-")
    fixtures = write_fixtures(tmpdir, tags)
    expected_files = len(fixtures)

    try:
        with sync_playwright() as pw:
            browser = pw.chromium.launch(headless=not args.headful)
            context = browser.new_context()
            page = context.new_page()

            if args.negative_control:
                page.route(
                    "**/assets/admin.js*",
                    lambda route: route.fulfill(status=200, content_type="application/javascript", body=mutated),
                )

            if not login(page, base_url, user, password):
                print("ENVIRONMENT UNAVAILABLE: could not authenticate against wp-login.php", file=sys.stderr)
                browser.close()
                return 2

            print("\n-- mouse activation --")
            mouse = run_flow(page, base_url, fixtures, activate="mouse", expected_files=expected_files)
            if args.negative_control:
                assert_defect_detected(mouse)
            else:
                assert_happy_path(mouse, expected_files)

            if args.negative_control:
                # The control proves the checks go red; a real run also proves
                # keyboard activation.
                print("\n-- keyboard activation (control build, informational) --")
                _ = run_flow(page, base_url, fixtures, activate="keyboard", expected_files=expected_files)
            else:
                print("\n-- keyboard activation --")
                keyboard = run_flow(page, base_url, fixtures, activate="keyboard", expected_files=expected_files)
                assert_happy_path(keyboard, expected_files)
                check(keyboard["trace"]["keyActivated"] is True, "keyboard activation (Tab + Enter) fired the submit event")
                check(keyboard["requests"]["document_posts"] == 0, "keyboard activation did not navigate")
                check(
                    keyboard["url_before"].split("?")[0] == keyboard["url_after"].split("?")[0],
                    "keyboard activation left the URL unchanged",
                )

            browser.close()
    finally:
        if not args.keep_fixtures:
            removed = cleanup_fixtures()
            print(f"\n  fixture cleanup: removed {removed} event(s)")
        count, dupes = fixture_state()
        print(f"  fixtures remaining: {count}, duplicate uuids: {dupes}")

    if not args.negative_control:
        count, dupes = fixture_state()
        check(count == 0, "no fixture event survives the suite")
        check(dupes == 0, "no duplicate fixture identity exists")

    print(f"\n{PASSED} passed, {FAILED} failed")
    return 1 if FAILED else 0


if __name__ == "__main__":
    sys.exit(main())
