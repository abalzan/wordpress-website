#!/usr/bin/env python3
"""
IVVCC Stage D — Phase B: XML-RPC capability test (READ-ONLY + ONE minimal write).

Safety per task rules:
  - No create/upsert beyond the single designated test post (11673).
  - Exactly ONE wp.editPost containing only the minimum required custom fields
    (_event_source, _event_source_id, _event_status).
  - Long retry spacing (initial 120s quiet, then wait 90s before verify).
  - Logs full audit trail to stdout for the recovery report.

Exit codes: 0 = PASS, 3 = BLOCKED (rate-limited / fault), 4 = FAIL (meta not persisted).
"""

import sys
import time
import json
import xmlrpc.client

WP_URL = "https://conexaobr.ie"
TEST_POST_ID = 11673  # ivvcc-11th-brass-brigade-run (intended IVVCC event #1)
QUIET_INITIAL_S = 120
VERIFY_WAIT_S = 90

MIN_FIELDS = [
    {"key": "_event_source", "value": "ivvcc"},
    {"key": "_event_source_id", "value": "555047"},
    {"key": "_event_status", "value": "published"},
]


def load_creds():
    with open(".env", "r") as fh:
        u = p = None
        for line in fh:
            line = line.strip()
            if line.startswith("WP_USERNAME="):
                u = line.split("=", 1)[1].strip().strip("'\"")
            elif line.startswith("WP_APPLICATION_PASSWORD="):
                p = line.split("=", 1)[1].strip().strip("'\"")
    if not u or not p:
        raise SystemExit("ERROR: credentials missing from .env")
    return u, p


def main():
    user, pwd = load_creds()
    log = []
    t0 = time.time()

    def out(msg):
        ts = time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())
        line = f"[{ts}] {msg}"
        log.append(line)
        print(line, flush=True)

    out("PHASE B START")
    out(f"target={WP_URL} xmlrpc.php, test_post={TEST_POST_ID}")

    srv = xmlrpc.client.ServerProxy(f"{WP_URL}/xmlrpc.php")

    # --- Step 1: quiet period before first request (previous attempts rate-limited)
    out(f"quiet wait {QUIET_INITIAL_S}s before first request...")
    time.sleep(QUIET_INITIAL_S)

    # --- Step 2: single read probe
    try:
        post = srv.wp.getPost(TEST_POST_ID, user, pwd)
        out(f"getPost OK id={post.get('ID')} title={post.get('title')!r}")
    except xmlrpc.client.ProtocolError as exc:
        out(f"getPost PROTOCOL_ERROR {exc.errcode} {exc.errmsg}")
        out("XML-RPC CAPABILITY: BLOCKED (rate-limited) -- no write attempted")
        out(f"PHASE B RESULT: BLOCKED ({exc.errcode} {exc.errmsg})")
        return 3
    except Exception as exc:  # noqa: BLE001
        out(f"getPost ERROR {type(exc).__name__}: {exc}")
        out("XML-RPC CAPABILITY: BLOCKED (unexpected error) -- no write attempted")
        return 3

    # --- Step 3: single minimal wp.editPost write
    out("single wp.editPost with minimum custom fields:")
    for cf in MIN_FIELDS:
        out(f"  {cf['key']} = {cf['value']}")
    try:
        ok = srv.wp.editPost(
            TEST_POST_ID, user, pwd,
            {"post_title": post.get("title", "")},
            custom_fields=MIN_FIELDS,
        )
        out(f"editPost returned: {ok}")
    except xmlrpc.client.ProtocolError as exc:
        out(f"editPost PROTOCOL_ERROR {exc.errcode} {exc.errmsg}")
        out("XML-RPC CAPABILITY: BLOCKED once more at write stage")
        return 3
    except Exception as exc:  # noqa: BLE001
        out(f"editPost ERROR {type(exc).__name__}: {exc}")
        return 3

    # --- Step 4: conservative wait then verify
    out(f"waiting {VERIFY_WAIT_S}s before verification...")
    time.sleep(VERIFY_WAIT_S)

    try:
        post2 = srv.wp.getPost(TEST_POST_ID, user, pwd)
    except Exception as exc:  # noqa: BLE001
        out(f"verify getPost ERROR {type(exc).__name__}: {exc}")
        return 4

    cfs = {cf.get("key"): cf.get("value") for cf in post2.get("custom_fields", [])}
    out("verification read: custom_fields")
    for cf in post2.get("custom_fields", []):
        out(f"  {cf.get('key')} = {cf.get('value')!r}")

    results = []
    for field in MIN_FIELDS:
        actual = cfs.get(field["key"], "<MISSING>")
        passed = actual == field["value"]
        results.append(passed)
        out(f"VERIFY {'PASS' if passed else 'FAIL'}: {field['key']} = {actual!r} (expected {field['value']!r})")

    all_pass = all(results)
    out(f"PHASE B RESULT: {'PASS' if all_pass else 'FAIL'}")
    return 0 if all_pass else 4


if __name__ == "__main__":
    sys.exit(main())