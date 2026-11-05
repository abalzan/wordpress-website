#!/usr/bin/env python3
"""stage7-rest-permission-test.py — Stage 7C controlled production REST write test.

Proves that the *deployed* ``conexao-data-model`` lets an authenticated
administrator write the two PROTECTED leisure coordinate meta keys
(``_leisure_lat`` / ``_leisure_lng``) over the WordPress REST API — i.e. that
the G19 ``auth_callback`` fix is live in production — and that the write is
fully reversible through the same API.

Safety contract
---------------
* Read-only unless ``--apply`` is passed; ``--apply`` also needs
  ``--confirm-production`` (the shared client's production write gate).
* One record, two meta keys, two writes: the test values, then the originals.
* Credentials come from ``WP_USERNAME`` / ``WP_APPLICATION_PASSWORD`` only; they
  are never printed, placed in argv, or written to the evidence file.
* ``HTTP 429`` is retried with ``Retry-After`` (bounded); any other 4xx aborts.
* If the write lands but a read-back disagrees, the script restores anyway and
  exits non-zero. If the RESTORE fails it exits 3 — that is a production
  incident, not a test failure.

Exit codes: 0 PASS · 1 assertion failure (restored) · 2 write not accepted
(nothing changed) · 3 RESTORE FAILED (production not original) · 4 refused.

Usage
-----
  python3 stage7-rest-permission-test.py --plan
  python3 stage7-rest-permission-test.py --apply --confirm-production \
      --evidence stage7c-evidence.json
"""

from __future__ import annotations

import argparse
import json
import os
import sys
import time
import urllib.error
import urllib.request
from datetime import datetime, timezone

HERE = os.path.dirname(os.path.abspath(__file__))
REPO_ROOT = os.path.abspath(os.path.join(HERE, "..", "..", ".."))
sys.path.insert(0, os.path.join(REPO_ROOT, "scripts", "lib"))

import rest as conexao_rest  # noqa: E402  (path set above, project convention)

# The one record this test may touch, and the exact values it must start from.
TARGET_ID = 10551
TARGET_SLUG = "russborough-house"
TARGET_STATUS = "publish"
BASE_URL = "https://conexaobr.ie"
ORIGINAL_LAT = ""
ORIGINAL_LNG = ""
# Valid in range, deliberately synthetic and far outside Ireland: it can never be
# mistaken for real content if a restore fails, and the record sorts *last* (not
# first) in the proximity ordering rather than appearing as someone's nearest.
TEST_LAT = "1.2345"
TEST_LNG = "-2.3456"

FIELDS = "id,slug,status,modified,modified_gmt,meta"
MAX_429_ATTEMPTS = 6


def utc_now() -> str:
    return datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


class Scenario:
    """Drives one controlled read/write/restore cycle and records evidence."""

    def __init__(self, base_url: str) -> None:
        common = {
            "user_agent": "ConexaoBR-Stage7-REST-Permission-Test/1.0",
            "retries": 1,
        }
        # The shared client is given the SITE url (that is what its production
        # write gate classifies); REST URLs are built on /wp-json explicitly,
        # exactly as scripts/seed-permit-employers-rest.py does.
        self.site = base_url.rstrip("/")
        self.json_base = self.site + "/wp-json"
        self.auth = conexao_rest.RestClient(base_url=self.site, authenticated=True, **common)
        self.public = conexao_rest.RestClient(base_url=self.site, authenticated=False, **common)
        self.steps: list = []
        self.assertions: list = []
        self.side_effects: dict = {}
        self.evidence_path: str | None = None
        self.base_url = self.site

    # -- primitives ---------------------------------------------------------
    def call(self, method: str, path: str, payload=None, *, public=False, v1=False):
        """One REST call returning ``(http_status, parsed_body)``.

        The shared client supplies auth headers and (for writes) the production
        gate; this primitive exists only because the evidence needs the numeric
        HTTP status, which ``RestClient.request`` does not surface.
        """
        client = self.public if public else self.auth
        url = self.json_base + ("/conexao/v1" if v1 else "/wp/v2") + path
        data = None if payload is None else json.dumps(payload).encode("utf-8")
        for attempt in range(MAX_429_ATTEMPTS):
            request = urllib.request.Request(
                url, data=data, headers=client.headers, method=method.upper()
            )
            try:
                with urllib.request.urlopen(request, timeout=90) as response:
                    text = response.read().decode("utf-8", "replace")
                    return response.status, (json.loads(text) if text.strip() else None)
            except urllib.error.HTTPError as error:
                text = error.read().decode("utf-8", "replace")
                if error.code == 429 and attempt < MAX_429_ATTEMPTS - 1:
                    time.sleep(min(int(error.headers.get("Retry-After") or 10), 90))
                    continue
                try:
                    return error.code, json.loads(text)
                except ValueError:
                    return error.code, {"raw": text[:400]}
            except (urllib.error.URLError, TimeoutError) as error:
                if attempt == MAX_429_ATTEMPTS - 1:
                    raise RuntimeError("transport failure: %s" % error) from error
                time.sleep(5 * (attempt + 1))
        raise RuntimeError("retry budget exhausted for %s %s" % (method, path))

    def record(self, step, status, body, *, method, path, authenticated=True) -> dict:
        body = body if isinstance(body, dict) else {}
        meta = body.get("meta") if isinstance(body.get("meta"), dict) else None
        entry = {
            "step": step,
            "method": method,
            "path": path,
            "authenticated": authenticated,
            "http_status": status,
            "slug": body.get("slug"),
            "status_field": body.get("status"),
            "modified": body.get("modified"),
            "lat": meta.get("_leisure_lat") if meta else body.get("lat"),
            "lng": meta.get("_leisure_lng") if meta else body.get("lng"),
            "at": utc_now(),
        }
        if body.get("code"):
            entry["error_code"] = body.get("code")
            entry["error_message"] = str(body.get("message"))[:200]
        self.steps.append(entry)
        return entry

    def assert_that(self, name: str, ok: bool, detail: str = "") -> None:
        self.assertions.append({"assertion": name, "ok": bool(ok), "detail": detail})

    # -- the scenario -------------------------------------------------------
    def read_record(self, *, public: bool) -> dict:
        path = "/leisure/%d?_fields=%s" % (TARGET_ID, FIELDS)
        if not public:
            path += "&context=edit"
        status, body = self.call("GET", path, public=public)
        return self.record(
            "read-%s" % ("record-public" if public else "record"),
            status, body, method="GET", path=path, authenticated=not public,
        )

    def write(self, lat: str, lng: str, label: str) -> dict:
        path = "/leisure/%d" % TARGET_ID
        status, body = self.call(
            "POST", path, {"meta": {"_leisure_lat": lat, "_leisure_lng": lng}}
        )
        entry = self.record(label, status, body, method="POST", path=path)
        entry["payload_written"] = {"_leisure_lat": lat, "_leisure_lng": lng}
        return entry

    def run(self) -> int:
        print("Stage 7C controlled REST permission test")
        print("  target : %s (id %d, slug %s)" % (self.base_url, TARGET_ID, TARGET_SLUG))
        print("  scope  : meta._leisure_lat / meta._leisure_lng on ONE record")

        # 1. Preconditions — refuse unless the record is exactly what we pinned.
        pre = self.read_record(public=False)
        print("  1 pre-read (auth)      HTTP %s lat=%r lng=%r modified=%s"
              % (pre["http_status"], pre["lat"], pre["lng"], pre["modified"]))
        ok_pre = (
            pre["http_status"] == 200
            and pre["slug"] == TARGET_SLUG
            and pre["status_field"] == TARGET_STATUS
            and pre["lat"] == ORIGINAL_LAT
            and pre["lng"] == ORIGINAL_LNG
        )
        self.assert_that(
            "pre-state equals the pinned original ('' for both keys, published, correct slug)",
            ok_pre, "slug=%r status=%r lat=%r lng=%r"
            % (pre["slug"], pre["status_field"], pre["lat"], pre["lng"]),
        )
        if not ok_pre:
            print("  REFUSED: preconditions not met; nothing was written")
            return 4

        original_modified = pre["modified"]

        # 2. The write under test.
        write1 = self.write(TEST_LAT, TEST_LNG, "write-test-values")
        print("  2 POST test values     HTTP %s lat=%r lng=%r"
              % (write1["http_status"], write1["lat"], write1["lng"]))
        if write1["http_status"] != 200:
            after_fail = self.read_record(public=False)
            unchanged = (after_fail["lat"] == ORIGINAL_LAT
                         and after_fail["lng"] == ORIGINAL_LNG)
            self.assert_that("the rejected write left the record unchanged", unchanged,
                             "lat=%r lng=%r" % (after_fail["lat"], after_fail["lng"]))
            self.assert_that("the write is still refused (403 rest_cannot_update means the "
                             "deployed plugin is NOT 1.6.3)", False,
                             "http=%s code=%r" % (write1["http_status"], write1.get("error_code")))
            print("  ABORTED: write not accepted; record re-read unchanged=%s" % unchanged)
            self.dump("ABORTED-WRITE-REJECTED")
            return 2

        self.assert_that("the coordinate write returned HTTP 200 (not 403 rest_cannot_update)",
                         True, "http=200")
        self.assert_that("the REST response echoed the test latitude",
                         write1["lat"] == TEST_LAT, "lat=%r" % (write1["lat"],))
        self.assert_that("the REST response echoed the test longitude",
                         write1["lng"] == TEST_LNG, "lng=%r" % (write1["lng"],))

        # 3. Independent read-back of the persisted write.
        read1 = self.read_record(public=False)
        print("  3 read-back (auth)     HTTP %s lat=%r lng=%r"
              % (read1["http_status"], read1["lat"], read1["lng"]))
        self.assert_that("the test values PERSISTED (independent authenticated re-read)",
                         read1["lat"] == TEST_LAT and read1["lng"] == TEST_LNG,
                         "lat=%r lng=%r" % (read1["lat"], read1["lng"]))

        # 4. Restore — always attempted, even when the read-back disagreed.
        restore = self.write(ORIGINAL_LAT, ORIGINAL_LNG, "restore-original-values")
        print("  4 POST restore         HTTP %s lat=%r lng=%r"
              % (restore["http_status"], restore["lat"], restore["lng"]))
        if restore["http_status"] != 200:
            print("  INCIDENT: restore rejected; production state is NOT original")
            self.dump("INCIDENT-RESTORE-REJECTED")
            return 3

        # 5. Verify the restoration, authenticated and anonymous.
        read2 = self.read_record(public=False)
        print("  5 read-back (auth)     HTTP %s lat=%r lng=%r"
              % (read2["http_status"], read2["lat"], read2["lng"]))
        self.assert_that("restoration verified: authenticated re-read equals the original values",
                         read2["lat"] == ORIGINAL_LAT and read2["lng"] == ORIGINAL_LNG,
                         "lat=%r lng=%r" % (read2["lat"], read2["lng"]))
        self.assert_that("the record is still published after the restore",
                         read2["status_field"] == TARGET_STATUS,
                         "status=%r" % (read2["status_field"],))

        read3 = self.read_record(public=True)
        print("  6 read-back (anon)     HTTP %s lat=%r lng=%r"
              % (read3["http_status"], read3["lat"], read3["lng"]))
        self.assert_that("restoration verified anonymously (public REST agrees)",
                         read3["lat"] == ORIGINAL_LAT and read3["lng"] == ORIGINAL_LNG,
                         "lat=%r lng=%r" % (read3["lat"], read3["lng"]))

        # 6. The public proximity endpoint must be back to null for this record.
        status, body = self.call("GET", "/leisure-coordinates?lang=pt", public=True, v1=True)
        items = body.get("items", []) if isinstance(body, dict) else []
        item = next((i for i in items if i.get("id") == TARGET_ID), None)
        self.steps.append({
            "step": "read-proximity-endpoint", "method": "GET",
            "path": "/conexao/v1/leisure-coordinates?lang=pt", "authenticated": False,
            "http_status": status, "item_for_target": item, "at": utc_now(),
        })
        print("  7 proximity endpoint   HTTP %s item=%r" % (status, item))
        self.assert_that("the public leisure-coordinates item is null/null again",
                         bool(item) and item.get("lat") is None and item.get("lng") is None,
                         "item=%r" % (item,))

        # 7. Measure and disclose the unavoidable side effect.
        self.side_effects = {
            "post_modified_before": original_modified,
            "post_modified_after": read2["modified"],
            "changed": original_modified != read2["modified"],
            "note": ("A REST post update runs wp_update_post(), so post_modified/_gmt always "
                     "move and a revision row is created. Both sit outside the two meta keys "
                     "and cannot be reverted over REST ('modified' is read-only, revisions are "
                     "not deletable). Disclosed, not restored."),
        }

        failed = [a for a in self.assertions if not a["ok"]]
        if failed:
            self.dump("FAIL-ASSERTIONS")
            print("  RESULT: FAIL (%d assertion(s)); originals were restored" % len(failed))
            return 1
        self.dump("PASS")
        print("  RESULT: PASS — permitted, restored, independently verified")
        return 0

    # -- evidence -----------------------------------------------------------
    def dump(self, final: str, path: str | None = None) -> None:
        document = {
            "stage": "7C — controlled production REST write permission test",
            "generated_at": utc_now(),
            "target": {
                "site": self.base_url,
                "post_type": "leisure",
                "id": TARGET_ID,
                "slug": TARGET_SLUG,
                "endpoint": "%s/wp-json/wp/v2/leisure/%d" % (self.base_url, TARGET_ID),
                "method": "POST",
                "auth": "HTTP Basic, WordPress Application Password (value never recorded)",
            },
            "original_values": {"_leisure_lat": ORIGINAL_LAT, "_leisure_lng": ORIGINAL_LNG},
            "test_values": {"_leisure_lat": TEST_LAT, "_leisure_lng": TEST_LNG},
            "steps": self.steps,
            "assertions": self.assertions,
            "side_effects": self.side_effects,
            "result": final,
        }
        destination = path or self.evidence_path
        if destination:
            with open(destination, "w", encoding="utf-8") as handle:
                json.dump(document, handle, indent=2, ensure_ascii=False)
                handle.write("\n")
            print("  evidence written to %s" % destination)
        else:
            print(json.dumps(document, indent=2, ensure_ascii=False))


def main(argv=None) -> int:
    parser = argparse.ArgumentParser(
        description="Stage 7C controlled REST write test for the leisure coordinate meta keys."
    )
    parser.add_argument("--apply", action="store_true",
                        help="perform the write + read-back + restore + verify cycle")
    parser.add_argument("--confirm-production", action="store_true",
                        help="required with --apply: the shared client's production write gate")
    parser.add_argument("--plan", action="store_true", help="print the plan and exit (default)")
    parser.add_argument("--evidence", help="write the JSON evidence document to this path")
    parser.add_argument("--base-url", default=BASE_URL,
                        help="target site (default: the production site this stage authorises)")
    args = parser.parse_args(argv)

    if not args.apply:
        print("PLAN — no request that changes anything will be sent")
        print("  target      : %s" % args.base_url)
        print("  record      : id %d / slug %r" % (TARGET_ID, TARGET_SLUG))
        print("  original    : _leisure_lat=%r _leisure_lng=%r" % (ORIGINAL_LAT, ORIGINAL_LNG))
        print("  test values : _leisure_lat=%r _leisure_lng=%r" % (TEST_LAT, TEST_LNG))
        print("  requests    : GET pre-read, POST test, GET read-back, POST restore,")
        print("                GET verify (auth), GET verify (anon), GET proximity endpoint")
        print("  credentials : WP_USERNAME / WP_APPLICATION_PASSWORD (environment only)")
        return 0

    scenario = Scenario(args.base_url)
    scenario.evidence_path = args.evidence
    try:
        scenario.auth.assert_write_allowed(confirm_production=args.confirm_production)
        return scenario.run()
    except conexao_rest.ProductionTargetError as error:
        print("REFUSED: %s" % error)
        return 4
    except conexao_rest.RestError as error:
        print("ABORTED (REST error): %s" % error)
        return 2


if __name__ == "__main__":
    sys.exit(main())
