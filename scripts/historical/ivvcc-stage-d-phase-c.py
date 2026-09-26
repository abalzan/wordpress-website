#!/usr/bin/env python3
"""
IVVCC Stage D — Phase C: Production event-meta completion (REST, update-only).

Writes the complete required Event Runtime meta for the 7 canonical IVVCC
events on production, using the values from the validated local export
(dist/ivvcc-only-export.json).

SAFETY / non-negotiables
------------------------
- NO event creation (no POST /wp/v2/event without an ID, no importer runs).
- NO deletion.
- Only the 7 allowlisted canonical post IDs are ever touched.
- Only meta is sent in the update body — title/slug/content/status are never
  modified by this script (they are read for verification only).
- Dry-run is the default; pass --apply to actually write.
- Every mutation is preceded by a BEFORE read and followed by an AFTER read;
  the full audit (before/after/verification matrix) is written to a JSON log
  and mirrored to stdout.

REQUIREMENT
-----------
Production must run conexao-event-runtime >= 1.2.0 (auth_callback on the
protected `_event_*` meta). Without it every REST meta write fails with
403 rest_cannot_update. XML-RPC is NOT used here.

Usage
-----
    python3 scripts/ivvcc-stage-d-phase-c.py [--apply] [--export PATH]
"""

import argparse
import base64
import json
import sys
import time
import urllib.error
import urllib.request

BASE_URL = "https://conexaobr.ie"

# Phase A identity mapping: export event UUID -> canonical production post ID.
# Proven by exact slug + title + content match on 2026-10-09 (see
# docs/importers/ivvcc-stage-d-recovery-report.md).
CANONICAL_MAP = {
    "b58c1155-3229-4be6-b511-d82e8e8e977e": 11673,  # IVVCC 11th Brass Brigade Run
    "bc2b057b-38c4-45e4-9f10-3f1ebacc84cb": 11675,  # Muskerry Vintage Club
    "8c5a9c49-406c-41e5-b5f0-8a38e22b10ca": 11677,  # Blessington Vintage Car and Motorcycle Club (Autumn)
    "dc88e1f9-38b1-4d7e-baf9-dd90f2dba583": 11679,  # RIAC/IVVCC Cars and Breakfast
    "847e7e02-a208-4b85-b1ad-aec1182c4d1e": 11681,  # Connacht Veteran & Vintage Motor Club
    "d7cd55a6-c89a-4edf-b1c6-803f271be6b2": 11683,  # Kingdom Veteran Vintage and Classic Car Club
    "10ae4f6b-2053-47ca-b427-bf1d709846ec": 11685,  # Blessington Vintage Car and Motorcycle Club (Dec Mince Pie)
}

# Meta fields to transfer. Only keys present in the per-event export meta are
# written (nothing invented, nothing back-filled with guesses).
TRANSFER_META_KEYS = [
    "_event_date",
    "_event_time",
    "_event_start_time",
    "_event_end_date",
    "_event_end_time",
    "_event_venue",
    "_event_location",
    "_event_address",
    "_event_map_url",
    "_event_url",
    "_event_source_url",
    "_event_organizer",
    "_event_registration",
    "_event_price",
    "_event_status",
    "_event_source",
    "_event_source_id",
    "_event_import_date",
    "_event_last_checked",
    "_event_imported",
]


def load_creds():
    with open(".env", "r", encoding="utf-8") as fh:
        u = p = None
        for line in fh:
            line = line.strip()
            if line.startswith("WP_USERNAME="):
                u = line.split("=", 1)[1].strip().strip("'\"")
            elif line.startswith("WP_APPLICATION_PASSWORD="):
                p = line.split("=", 1)[1].strip().strip("'\"")
    if not u or not p:
        raise SystemExit("ERROR: WP_USERNAME/WP_APPLICATION_PASSWORD missing from .env")
    return u, p


class Rest:
    def __init__(self, user, pwd):
        token = base64.b64encode(f"{user}:{pwd}".encode()).decode()
        self.headers = {
            "Authorization": f"Basic {token}",
            "Content-Type": "application/json",
            "Accept": "application/json",
            "User-Agent": "ConexaoBR/1.0 (Stage D Phase C)",
        }

    def _request(self, method, path, data=None):
        body = json.dumps(data).encode("utf-8") if data is not None else None
        req = urllib.request.Request(
            f"{BASE_URL}{path}", data=body, headers=self.headers, method=method
        )
        try:
            with urllib.request.urlopen(req, timeout=30) as resp:
                raw = resp.read()
                return json.loads(raw) if raw else None, resp.status
        except urllib.error.HTTPError as exc:
            raw = exc.read().decode("utf-8", errors="replace")
            try:
                return json.loads(raw), exc.code
            except json.JSONDecodeError:
                return {"message": raw}, exc.code
        except urllib.error.URLError as exc:
            return {"message": str(exc.reason)}, 0

    def get_event(self, post_id, context="edit"):
        data, status = self._request("GET", f"/wp-json/wp/v2/event/{post_id}?context={context}")
        return data, status

    def patch_meta(self, post_id, meta):
        return self._request("POST", f"/wp-json/wp/v2/event/{post_id}", {"meta": meta})


def main():
    ap = argparse.ArgumentParser(description="IVVCC Stage D Phase C meta completion")
    ap.add_argument("--apply", action="store_true", help="actually write meta (default: dry run)")
    ap.add_argument("--export", default="dist/ivvcc-only-export.json", help="validated export JSON")
    args = ap.parse_args()

    with open(args.export, "r", encoding="utf-8") as fh:
        export = json.load(fh)
    by_uuid = {e["uuid"]: e for e in export["events"]}

    user, pwd = load_creds()
    rest = Rest(user, pwd)

    audit = {
        "phase": "C",
        "mode": "apply" if args.apply else "dry-run",
        "base_url": BASE_URL,
        "ran_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
        "posts": [],
    }
    overall_pass = True

    for uuid, post_id in CANONICAL_MAP.items():
        event = by_uuid.get(uuid)
        rec = {"post_id": post_id, "uuid": uuid, "title": event["post"]["title"] if event else None}
        if not event:
            rec["status"] = "FAIL"
            rec["reason"] = "uuid not found in export"
            audit["posts"].append(rec)
            overall_pass = False
            print(f"[{post_id}] FAIL: uuid missing from export", flush=True)
            continue

        # BEFORE state (edit context -> raw meta)
        before, status = rest.get_event(post_id)
        if status != 200:
            rec["status"] = "FAIL"
            rec["reason"] = f"before-read HTTP {status}: {json.dumps(before)[:200]}"
            audit["posts"].append(rec)
            overall_pass = False
            print(f"[{post_id}] FAIL: before-read HTTP {status}", flush=True)
            continue

        # Identity guard: the allowlisted post must be the intended event.
        expect_slug = event["post"]["slug"]
        if before.get("slug") != expect_slug:
            rec["status"] = "FAIL"
            rec["reason"] = f"slug mismatch: prod={before.get('slug')} expected={expect_slug} — refusing"
            audit["posts"].append(rec)
            overall_pass = False
            print(f"[{post_id}] FAIL: slug mismatch (refusing write)", flush=True)
            continue

        # Build the meta to write: fields present in the export only.
        export_meta = event.get("meta", {})
        meta = {k: export_meta[k] for k in TRANSFER_META_KEYS if k in export_meta}
        meta["_event_export_uuid"] = uuid  # export-format identity key (v1.2.0 registered)

        before_meta = {k: before.get("meta", {}).get(k, "") for k in meta}
        rec["before"] = {
            "title": before.get("title", {}).get("rendered"),
            "slug": before.get("slug"),
            "meta": before_meta,
        }

        print(f"\n[{post_id}] {rec['title']}", flush=True)
        print(f"  slug      : {before.get('slug')}", flush=True)
        print(f"  meta to write ({len(meta)} keys):", flush=True)
        for k, v in meta.items():
            old = before_meta.get(k, "")
            print(f"    {k}: {old!r} -> {v!r}", flush=True)

        if not args.apply:
            rec["status"] = "DRY-RUN (not written)"
            audit["posts"].append(rec)
            print("  >>> DRY-RUN — no write performed", flush=True)
            continue

        # WRITE (meta only)
        result, code = rest.patch_meta(post_id, meta)
        if code not in (200, 201):
            rec["status"] = "FAIL"
            rec["reason"] = f"meta write HTTP {code}: {json.dumps(result)[:300]}"
            audit["posts"].append(rec)
            overall_pass = False
            print(f"  >>> FAIL: meta write HTTP {code}: {json.dumps(result)[:300]}", flush=True)
            continue

        # Polite wait (rate-limit courtesy), then AFTER verification.
        time.sleep(5)
        after, a_code = rest.get_event(post_id)
        if a_code != 200:
            rec["status"] = "FAIL"
            rec["reason"] = f"after-read HTTP {a_code}"
            audit["posts"].append(rec)
            overall_pass = False
            print(f"  >>> FAIL: after-read HTTP {a_code}", flush=True)
            continue

        after_meta = {k: after.get("meta", {}).get(k, "") for k in meta}
        mismatches = {
            k: {"expected": meta[k], "actual": after_meta.get(k)}
            for k in meta
            if after_meta.get(k) != meta[k]
        }
        rec["after"] = {
            "title": after.get("title", {}).get("rendered"),
            "slug": after.get("slug"),
            "meta": after_meta,
        }
        if mismatches:
            rec["status"] = "FAIL"
            rec["mismatches"] = mismatches
            overall_pass = False
            print(f"  >>> FAIL: {len(mismatches)} meta mismatch(es)", flush=True)
            for k, m in mismatches.items():
                print(f"      {k}: expected {m['expected']!r} got {m['actual']!r}", flush=True)
        else:
            rec["status"] = "PASS"
            print(f"  >>> PASS: {len(meta)} meta fields verified", flush=True)

    print("\n" + "=" * 70, flush=True)
    print(f"PHASE C {'APPLY' if args.apply else 'DRY-RUN'} result: "
          f"{'PASS' if overall_pass else 'FAIL'}", flush=True)
    audit["overall"] = "PASS" if overall_pass else "FAIL"
    log_name = f"/tmp/ivvcc-stage-d-phase-c-{('apply' if args.apply else 'dryrun')}-{int(time.time())}.json"
    with open(log_name, "w", encoding="utf-8") as fh:
        json.dump(audit, fh, indent=2, ensure_ascii=False)
    print(f"Audit log: {log_name}", flush=True)
    return 0 if overall_pass else 1


if __name__ == "__main__":
    sys.exit(main())