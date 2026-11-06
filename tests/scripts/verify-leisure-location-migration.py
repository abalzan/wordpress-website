#!/usr/bin/env python3
"""Mock-API validation for scripts/migrate-leisure-location-rest.py.

Purpose
-------
Prove the migration script's safety and correctness claims against a STUBBED
WordPress REST API, without a WordPress install, without credentials and
without ever contacting a real site. This is the suite that lets the apply
mode be exercised at all: apply is tested here and ONLY here.

Scope
-----
Everything is in-process: a `http.server.ThreadingHTTPServer` bound to
127.0.0.1 on an ephemeral port stands in for WordPress, and the script is driven
through its real CLI entry point (`main(argv)`) against that stub.

The stub enforces the production WordPress semantics that matter:
  * X-WP-TotalPages pagination,
  * HTTP 429 with Retry-After,
  * 401 for a missing credential,
  * 403 `rest_cannot_update` when a meta key is protected and the plugin has no
    auth_callback — the exact production blocker this migration must survive,
  * and it RECORDS every write so "no accidental write" is an assertion.

Safety
------
Local loopback only. No external network, no production host, no credentials
(the stub accepts any non-empty Basic header). Verifies that the script refuses
a production target without confirmation, that dry-run writes nothing, that the
apply is idempotent, and that an interrupted run resumes.

Run from the repository root:
    python3 tests/scripts/verify-leisure-location-migration.py

Exit code 0 = every assertion passed.
"""

from __future__ import annotations

import base64
import http.server
import importlib.util
import json
import os
import sys
import threading
import urllib.parse

REPO_ROOT = os.path.abspath(
    os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "..")
)
SCRIPT = os.path.join(REPO_ROOT, "scripts", "migrate-leisure-location-rest.py")

PASSED = 0
FAILURES = []


def check(condition, message):
    """Record one assertion. Never raises."""
    global PASSED
    if condition:
        PASSED += 1
        print("  PASS: {}".format(message))
    else:
        FAILURES.append(message)
        print("  FAIL: {}".format(message))
    return bool(condition)


def load_migration_module():
    """Import the migration script as a module (so main() can be driven)."""
    spec = importlib.util.spec_from_file_location("leisure_migration", SCRIPT)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


class StubState:
    """Mutable state shared with the stub server, plus the write journal."""

    def __init__(self, records, per_page=2, meta_writable=True, fail_after=None):
        self.records = records
        self.per_page = per_page
        self.meta_writable = meta_writable
        self.fail_after = fail_after
        self.writes = []
        self.attempts = []
        self.requests = []
        self.rate_limit_once = False
        self.lock = threading.Lock()


def record(rid, slug, lat="", lng=""):
    """Build one stub `leisure` record."""
    return {"id": rid, "slug": slug, "meta": {"_leisure_lat": lat, "_leisure_lng": lng}}


def make_handler(state, require_auth=True):
    """Build a request handler bound to one StubState."""

    class Handler(http.server.BaseHTTPRequestHandler):
        protocol_version = "HTTP/1.1"

        def log_message(self, *_args):
            pass  # keep the suite output clean

        def _send(self, status, payload, extra_headers=None):
            body = json.dumps(payload).encode("utf-8")
            self.send_response(status)
            self.send_header("Content-Type", "application/json")
            self.send_header("Content-Length", str(len(body)))
            for key, value in (extra_headers or {}).items():
                self.send_header(key, value)
            self.end_headers()
            self.wfile.write(body)

        def _authorised(self):
            if not require_auth:
                return True
            header = self.headers.get("Authorization", "")
            if not header.startswith("Basic "):
                return False
            try:
                decoded = base64.b64decode(header[6:]).decode("utf-8")
            except Exception:
                return False
            return ":" in decoded and decoded.split(":", 1)[1] != ""

        def do_GET(self):
            parsed = urllib.parse.urlparse(self.path)
            with state.lock:
                state.requests.append(("GET", parsed.path))
            if not self._authorised():
                self._send(401, {"code": "rest_not_logged_in", "message": "auth"})
                return
            if state.rate_limit_once:
                state.rate_limit_once = False
                self._send(
                    429,
                    {"code": "rate_limited", "message": "slow down"},
                    {"Retry-After": "0"},
                )
                return
            if parsed.path.endswith("/wp/v2/leisure"):
                self._serve_leisure(parsed)
                return
            if parsed.path.endswith("/wp/v2/conexao_leisure_attribute"):
                self._send(
                    200,
                    [{"id": 1, "slug": "exterior"}, {"id": 2, "slug": "gratuito"}],
                    {"X-WP-TotalPages": "1"},
                )
                return
            self._send(404, {"code": "rest_no_route", "message": "no route"})

        def _serve_leisure(self, parsed):
            query = urllib.parse.parse_qs(parsed.query)
            page = int((query.get("page") or ["1"])[0])
            items = list(state.records.values())
            total_pages = max(1, (len(items) + state.per_page - 1) // state.per_page)
            start = (page - 1) * state.per_page
            chunk = items[start : start + state.per_page]
            payload = [
                {
                    "id": item["id"],
                    "slug": item["slug"],
                    "status": "publish",
                    "meta": {
                        "_leisure_lat": item["meta"].get("_leisure_lat", ""),
                        "_leisure_lng": item["meta"].get("_leisure_lng", ""),
                    },
                }
                for item in chunk
            ]
            self._send(
                200,
                payload,
                {"X-WP-TotalPages": str(total_pages), "X-WP-Total": str(len(items))},
            )

    return Handler


def _attach_post(handler_class, state):
    """Attach the write path to the handler class (kept separate for size)."""

    def do_POST(self):
        parsed = urllib.parse.urlparse(self.path)
        length = int(self.headers.get("Content-Length") or 0)
        raw = self.rfile.read(length).decode("utf-8") if length else "{}"
        with state.lock:
            state.requests.append(("POST", parsed.path))
        if not self._authorised():
            self._send(401, {"code": "rest_not_logged_in", "message": "auth"})
            return
        try:
            payload = json.loads(raw)
        except ValueError:
            self._send(400, {"code": "rest_invalid_json", "message": "bad json"})
            return

        key = parsed.path.rstrip("/").rsplit("/", 1)[-1]
        chosen = None
        for item in state.records.values():
            if item["slug"] == key or str(item["id"]) == key:
                chosen = item
                break
        if chosen is None:
            self._send(404, {"code": "rest_post_invalid_id", "message": "no record"})
            return

        with state.lock:
            state.attempts.append({"slug": chosen["slug"], "payload": payload})

        if "meta" in payload:
            if not state.meta_writable:
                # The exact WordPress core behaviour for a protected meta key
                # registered WITHOUT an auth_callback: 403 rest_cannot_update.
                self._send(
                    403,
                    {
                        "code": "rest_cannot_update",
                        "message": (
                            "Sorry, you are not allowed to edit the _leisure_lat "
                            "custom field."
                        ),
                    },
                )
                return
            if state.fail_after is not None and len(state.writes) >= state.fail_after:
                self._send(500, {"code": "boom", "message": "simulated interruption"})
                return
            chosen["meta"]["_leisure_lat"] = payload["meta"].get("_leisure_lat", "")
            chosen["meta"]["_leisure_lng"] = payload["meta"].get("_leisure_lng", "")
            with state.lock:
                state.writes.append({"slug": chosen["slug"], "payload": payload})

        self._send(
            200,
            {
                "id": chosen["id"],
                "slug": chosen["slug"],
                "status": "publish",
                "meta": {
                    "_leisure_lat": chosen["meta"].get("_leisure_lat", ""),
                    "_leisure_lng": chosen["meta"].get("_leisure_lng", ""),
                },
            },
        )

    handler_class.do_POST = do_POST
    return handler_class


class StubServer:
    """A loopback stub WordPress REST API (127.0.0.1 only)."""

    def __init__(self, state, require_auth=True):
        handler = _attach_post(make_handler(state, require_auth), state)
        self.httpd = http.server.ThreadingHTTPServer(("127.0.0.1", 0), handler)
        self.thread = threading.Thread(target=self.httpd.serve_forever, daemon=True)

    def __enter__(self):
        self.thread.start()
        return "http://127.0.0.1:{}".format(self.httpd.server_address[1])

    def __exit__(self, *_exc):
        self.httpd.shutdown()
        self.httpd.server_close()
        self.thread.join(timeout=5)
        return False


# ---------------------------------------------------------------------------
# Fixtures and helpers
# ---------------------------------------------------------------------------

MANIFEST = [
    {"slug": "alpha", "uuid": "u-alpha", "title": "Alpha",
     "proposed_latitude": 53.1, "proposed_longitude": -6.1,
     "confidence": "HIGH", "action": "POPULATE", "owner_approved": False},
    {"slug": "beta", "uuid": "u-beta", "title": "Beta",
     "proposed_latitude": 52.2, "proposed_longitude": -8.2,
     "confidence": "HIGH", "action": "POPULATE", "owner_approved": False},
    {"slug": "gamma", "uuid": "u-gamma", "title": "Gamma",
     "proposed_latitude": 51.3, "proposed_longitude": -9.3,
     "confidence": "MEDIUM", "action": "POPULATE", "owner_approved": True},
    {"slug": "missing-local", "uuid": "u-missing", "title": "Missing in target",
     "proposed_latitude": 50.0, "proposed_longitude": -7.0,
     "confidence": "HIGH", "action": "POPULATE", "owner_approved": False},
    {"slug": "no-coords", "uuid": "u-nocoords", "title": "No coordinate",
     "proposed_latitude": "", "proposed_longitude": "",
     "confidence": "HIGH", "action": "POPULATE", "owner_approved": False},
    {"slug": "bad-coords", "uuid": "u-bad", "title": "Out of range",
     "proposed_latitude": 999, "proposed_longitude": -6.0,
     "confidence": "HIGH", "action": "POPULATE", "owner_approved": False},
    {"slug": "review-only", "uuid": "u-review", "title": "Review",
     "proposed_latitude": 53.0, "proposed_longitude": -6.0,
     "confidence": "MEDIUM", "action": "REVIEW", "owner_approved": False},
]


def write_manifest(path, entries=MANIFEST):
    """Write a temporary manifest file and return its path."""
    with open(path, "w", encoding="utf-8") as handle:
        json.dump(entries, handle)
    return path


def target_records(include_alpha=False):
    """The stub target dataset: beta/gamma empty, alpha optionally pre-set."""
    return {
        "alpha": record(1, "alpha", "53.1" if include_alpha else "", "-6.1" if include_alpha else ""),
        "beta": record(2, "beta"),
        "gamma": record(3, "gamma"),
        "no-coords": record(4, "no-coords"),
        "bad-coords": record(5, "bad-coords"),
        "review-only": record(6, "review-only"),
    }


def run_main(module, argv, ensure_credentials=True):
    """Run the migration CLI and return (exit_code, stdout+stderr)."""
    import contextlib
    import io

    out = io.StringIO()
    code = 0
    saved = dict(os.environ)
    if ensure_credentials:
        os.environ["WP_USERNAME"] = "stub-user"
        os.environ["WP_APPLICATION_PASSWORD"] = "stub-app-password"
    try:
        with contextlib.redirect_stdout(out), contextlib.redirect_stderr(out):
            try:
                code = module.main(argv)
            except SystemExit as exit_error:
                code = exit_error.code if isinstance(exit_error.code, int) else 1
            except Exception as error:  # noqa: BLE001 - a crash is a failure, not an abort
                out.write("\nUNCAUGHT {}: {}\n".format(type(error).__name__, error))
                code = 99
    finally:
        os.environ.clear()
        os.environ.update(saved)
    return code, out.getvalue()


# ---------------------------------------------------------------------------
# Scenarios
# ---------------------------------------------------------------------------


def case_dry_run_writes_nothing(module, tmpdir):
    """Dry run must classify correctly and write NOTHING."""
    print("\n== dry run: pagination across pages, no writes ==")
    state = StubState(target_records(include_alpha=True), per_page=2)
    manifest = write_manifest(os.path.join(tmpdir, "m1.json"))
    report = os.path.join(tmpdir, "r1.json")
    with StubServer(state) as base:
        code, out = run_main(
            module,
            ["--target-url", base, "--dry-run", "--manifest", manifest,
             "--state-file", os.path.join(tmpdir, "s1.json"),
             "--request-delay", "0", "--report", report],
        )

    check(code == 0, "dry run exits 0")
    check(state.writes == [], "dry run performed ZERO writes")
    check(state.attempts == [], "dry run sent ZERO write requests at all")

    doc = json.load(open(report))
    summary = doc["summary"]
    check(summary["eligible_entries"] == 6, "6 eligible entries (HIGH + owner-approved)")
    check(summary["already_set"] == 1, "the pre-populated record is ALREADY_SET")
    check(summary["populate"] == 2, "2 records would be populated (beta, gamma)")
    check(summary["unresolved"] == 3,
          "3 entries unresolved (missing target slug, no coords, out of range)")
    check(summary["gate"] == 0, "the numeric gate is 0 in a dry run")
    check(summary["manifest_skipped"] == {"REVIEW:MEDIUM": 1},
          "the REVIEW entry is skipped and counted")
    check(summary["api_requests"] >= 4, "pagination issued several requests")
    check(summary["target_records"] == 6, "all 6 target records were read across pages")


def case_apply_populates_and_is_idempotent(module, tmpdir):
    """Apply writes exactly the planned coordinates, then is idempotent."""
    print("\n== apply: populate, then re-run is idempotent ==")
    state = StubState(target_records(include_alpha=True), per_page=10)
    manifest = write_manifest(os.path.join(tmpdir, "m2.json"))
    statefile = os.path.join(tmpdir, "s2.json")

    with StubServer(state) as base:
        code, out = run_main(
            module,
            ["--target-url", base, "--apply", "--manifest", manifest,
             "--state-file", statefile, "--request-delay", "0"],
        )
        check(code == 0, "apply exits 0")
        check(len(state.writes) == 2, "apply wrote exactly 2 coordinates (beta, gamma)")
        written = sorted(item["slug"] for item in state.writes)
        check(written == ["beta", "gamma"], "the writes hit beta and gamma only")
        check(state.records["beta"]["meta"]["_leisure_lat"] == "52.2",
              "beta latitude landed exactly")
        check(state.records["gamma"]["meta"]["_leisure_lng"] == "-9.3",
              "the owner-approved MEDIUM entry was written too")
        check(all(a["slug"] in ("beta", "gamma") for a in state.attempts),
              "no write attempt was made against an unplanned record")

        writes_after_first = len(state.writes)
        code2, out2 = run_main(
            module,
            ["--target-url", base, "--apply", "--manifest", manifest,
             "--state-file", os.path.join(tmpdir, "s2b.json"), "--request-delay", "0"],
        )
        check(code2 == 0, "the second apply exits 0")
        check(len(state.writes) == writes_after_first,
              "IDEMPOTENT: the second apply wrote nothing new")
        check("already_set=3" in out2.replace(" ", ""),
              "the second apply reports every in-scope record as ALREADY_SET")


def case_resume_after_interruption(module, tmpdir):
    """An interrupted apply resumes and completes without double-writing."""
    print("\n== interruption: resume completes the remaining records ==")
    state = StubState(target_records(include_alpha=True), per_page=10, fail_after=1)
    manifest = write_manifest(os.path.join(tmpdir, "m3.json"))
    statefile = os.path.join(tmpdir, "s3.json")

    with StubServer(state) as base:
        code, out = run_main(
            module,
            ["--target-url", base, "--apply", "--manifest", manifest,
             "--state-file", statefile, "--request-delay", "0"],
        )
        check(code != 0, "the interrupted run exits non-zero")
        check(len(state.writes) == 1, "exactly one write landed before the interruption")
        journal = json.load(open(statefile))
        check(len(journal["applied"]) == 1,
              "the resume journal recorded the one completed record")

        state.fail_after = None  # heal the stub
        code2, out2 = run_main(
            module,
            ["--target-url", base, "--apply", "--manifest", manifest,
             "--state-file", statefile, "--request-delay", "0"],
        )
        check(code2 == 0, "the resumed run exits 0")
        check(len(state.writes) == 2, "the resumed run completed the second record")
        journal = json.load(open(statefile))
        check(len(journal["applied"]) == 2, "the journal now records both records")


def case_conflict_is_preserved(module, tmpdir):
    """An existing DIFFERING coordinate is preserved (CONFLICT), never overwritten."""
    print("\n== conflict: an existing differing coordinate is preserved ==")
    records = target_records()
    records["beta"]["meta"]["_leisure_lat"] = "1.0"
    records["beta"]["meta"]["_leisure_lng"] = "2.0"
    state = StubState(records, per_page=10)
    manifest = write_manifest(os.path.join(tmpdir, "m4.json"))
    with StubServer(state) as base:
        code, out = run_main(
            module,
            ["--target-url", base, "--apply", "--manifest", manifest,
             "--state-file", os.path.join(tmpdir, "s4.json"), "--request-delay", "0"],
        )
        check(code == 0, "the apply exits 0")
        check(records["beta"]["meta"]["_leisure_lat"] == "1.0",
              "the existing production coordinate is PRESERVED (not overwritten)")
        check(all(item["slug"] != "beta" for item in state.writes),
              "no write was issued for the conflicting record")
        check("conflicts=1" in out.replace(" ", ""), "the conflict is reported")


def case_meta_forbidden_surfaces_clear_error(module, tmpdir):
    """The production 403 rest_cannot_update must produce a clear, bounded error."""
    print("\n== capability: protected meta 403 rest_cannot_update ==")
    state = StubState(target_records(), per_page=10, meta_writable=False)
    manifest = write_manifest(os.path.join(tmpdir, "m5.json"))
    with StubServer(state) as base:
        code, out = run_main(
            module,
            ["--target-url", base, "--apply", "--manifest", manifest,
             "--state-file", os.path.join(tmpdir, "s5.json"), "--request-delay", "0"],
        )
        check(code != 0, "a forbidden meta write exits non-zero")
        check("403" in out and "rest_cannot_update" in out,
              "the error names the HTTP status and the WordPress error code")
        check("auth_callback" in out,
              "the error explains the auth_callback requirement")
        check(state.writes == [], "nothing was written")
        check("stub-app-password" not in out and "stub-user" not in out,
              "credentials never appear in the output")


def case_auth_failure(module, tmpdir):
    """A missing credential produces a clear error, never a silent skip."""
    print("\n== authentication failure ==")
    state = StubState(target_records(), per_page=10)
    manifest = write_manifest(os.path.join(tmpdir, "m6.json"))
    with StubServer(state) as base:
        saved_pw = os.environ.pop("WP_APPLICATION_PASSWORD", None)
        saved_user = os.environ.pop("WP_USERNAME", None)
        try:
            code, out = run_main(
                module,
                ["--target-url", base, "--dry-run", "--manifest", manifest,
                 "--state-file", os.path.join(tmpdir, "s6.json"), "--request-delay", "0"],
                ensure_credentials=False,
            )
        finally:
            if saved_pw is not None:
                os.environ["WP_APPLICATION_PASSWORD"] = saved_pw
            if saved_user is not None:
                os.environ["WP_USERNAME"] = saved_user
        check(code != 0, "a missing credential exits non-zero")
        check("WP_APPLICATION_PASSWORD" in out or "WP_USERNAME" in out,
              "the error names the missing environment variable")
        check(state.writes == [], "nothing was written")


def case_rate_limit(module, tmpdir):
    """A 429 is retried, not treated as a failure."""
    print("\n== rate limiting: 429 is retried with backoff ==")
    state = StubState(target_records(), per_page=10)
    state.rate_limit_once = True
    manifest = write_manifest(os.path.join(tmpdir, "m7.json"))
    with StubServer(state) as base:
        code, out = run_main(
            module,
            ["--target-url", base, "--dry-run", "--manifest", manifest,
             "--state-file", os.path.join(tmpdir, "s7.json"), "--request-delay", "0"],
        )
        check(code == 0, "a single 429 does not fail the run")
        check("retrying" in out, "the retry is reported, not hidden")


def case_production_guard(module, tmpdir):
    """A production target is refused without --confirm-production."""
    print("\n== safety: production target requires explicit confirmation ==")
    manifest = write_manifest(os.path.join(tmpdir, "m8.json"))
    code, out = run_main(
        module,
        ["--target-url", "https://conexaobr.ie", "--apply", "--manifest", manifest,
         "--state-file", os.path.join(tmpdir, "s8.json")],
    )
    check(code != 0, "a production apply without --confirm-production exits non-zero")
    check("--confirm-production" in out, "the refusal names the required flag")
    check("PRODUCTION TARGET" in out, "the production target is announced loudly")

    code2, out2 = run_main(module, ["--apply"])
    check(code2 != 0, "--apply with no --target-url exits non-zero")
    check("--target-url" in out2, "the refusal names --target-url")


def case_mutually_exclusive_modes(module):
    """--apply and --dry-run are mutually exclusive."""
    print("\n== safety: mutually exclusive modes ==")
    code, out = run_main(
        module, ["--target-url", "http://127.0.0.1:1", "--apply", "--dry-run"]
    )
    check(code != 0, "--apply --dry-run exits non-zero")
    check("mutually exclusive" in out, "the conflict is explained")


def case_credential_hygiene(module, tmpdir):
    """The JSON report never contains a credential."""
    print("\n== safety: the report contains no credential ==")
    state = StubState(target_records(), per_page=10)
    manifest = write_manifest(os.path.join(tmpdir, "m9.json"))
    report = os.path.join(tmpdir, "r9.json")
    with StubServer(state) as base:
        run_main(
            module,
            ["--target-url", base, "--apply", "--manifest", manifest,
             "--state-file", os.path.join(tmpdir, "s9.json"),
             "--request-delay", "0", "--report", report],
        )
    body = open(report, encoding="utf-8").read()
    check("stub-app-password" not in body, "no password in the report")
    check("stub-user" not in body, "no username in the report")
    check("Authorization" not in body, "no authorization header in the report")


def case_protocol_path(module, tmpdir):
    """A non-JSON response produces a clear diagnostic, not a traceback."""
    print("\n== diagnostics: a wrong REST base path is explained ==")
    import http.server as hs

    class HtmlHandler(hs.BaseHTTPRequestHandler):
        protocol_version = "HTTP/1.1"

        def log_message(self, *_args):
            pass

        def do_GET(self):
            body = b"<html>not the REST API</html>"
            self.send_response(200)
            self.send_header("Content-Type", "text/html")
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            self.wfile.write(body)

    server = hs.ThreadingHTTPServer(("127.0.0.1", 0), HtmlHandler)
    thread = threading.Thread(target=server.serve_forever, daemon=True)
    thread.start()
    try:
        base = "http://127.0.0.1:{}".format(server.server_address[1])
        manifest = write_manifest(os.path.join(tmpdir, "m10.json"))
        code, out = run_main(
            module,
            ["--target-url", base, "--dry-run", "--manifest", manifest,
             "--state-file", os.path.join(tmpdir, "s10.json"), "--request-delay", "0"],
        )
        check(code != 0, "a non-JSON response exits non-zero")
        check("not JSON" in out, "the error explains that the response was not JSON")
        check("wp-json" in out, "the error points at the /wp-json/ REST base")
    finally:
        server.shutdown()
        server.server_close()
        thread.join(timeout=5)


def case_attributes_add_only(module, tmpdir):
    """The attribute plan sends the local set verbatim and computes NO removals."""
    print("\n== attributes: full local set, no removal logic ==")
    attribute_ids = {"exterior": 11, "familias": 12, "gratuito": 13}
    target_index = {
        "one": {"id": 1, "slug": "one", "status": "publish", "lat": "", "lng": ""},
        "two": {"id": 2, "slug": "two", "status": "publish", "lat": "", "lng": ""},
        "absent": {"id": 3, "slug": "absent", "status": "publish", "lat": "", "lng": ""},
    }
    entries = [
        {"slug": "one", "attributes": ["exterior", "familias"]},
        {"slug": "two", "attributes": ["gratuito"]},
        {"slug": "not-in-target", "attributes": ["exterior"]},
        {"slug": "one", "attributes": ["unknown-term"]},
    ]
    rows = module.plan_attributes(entries, attribute_ids, target_index)
    by_slug = {row["slug"]: row for row in rows}

    check("one" in by_slug and by_slug["one"]["term_ids"] == [11, 12],
          "the local set is sent verbatim (exterior=11, familias=12)")
    check("two" in by_slug and by_slug["two"]["term_ids"] == [13],
          "a second record gets its own local set (gratuito=13)")
    check("not-in-target" not in by_slug,
          "a record absent from the target is never planned (nothing is created)")
    check(all("missing" not in str(row) and "remove" not in str(row) for row in rows),
          "the plan carries no removal/missing concept at all")
    check(len(rows) == 2,
          "a term unknown to the target is dropped, and the record still gets its known terms")


def main():
    """Run every scenario and print the verdict."""
    import tempfile

    print("== Mock-API validation: migrate-leisure-location-rest.py ==")
    print("   stub: loopback only (127.0.0.1), no external network, no credentials")
    module = load_migration_module()

    with tempfile.TemporaryDirectory() as tmpdir:
        case_dry_run_writes_nothing(module, tmpdir)
        case_apply_populates_and_is_idempotent(module, tmpdir)
        case_resume_after_interruption(module, tmpdir)
        case_conflict_is_preserved(module, tmpdir)
        case_meta_forbidden_surfaces_clear_error(module, tmpdir)
        case_auth_failure(module, tmpdir)
        case_rate_limit(module, tmpdir)
        case_production_guard(module, tmpdir)
        case_mutually_exclusive_modes(module)
        case_credential_hygiene(module, tmpdir)
        case_attributes_add_only(module, tmpdir)
        case_protocol_path(module, tmpdir)

    failed = len(FAILURES)
    for failure in FAILURES:
        print("FAIL: {}".format(failure))
    print("{} passed, {} failed".format(PASSED, failed))
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
