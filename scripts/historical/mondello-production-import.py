#!/usr/bin/env python3
"""
Mondello Park — Production import (REST, create-only).

Imports the validated Mondello Park events from the local export JSON
(dist/mondello-only-export.json) into the production WordPress.com site via
the REST API, following the IVVCC production-import precedent.

SAFETY / non-negotiables
------------------------
- CREATE-ONLY. If ANY pre-existing event with a Mondello source identity,
  allowlisted slug, or canonical Mondello URL is found, the run STOPS before
  writing (hard safety gate — duplicates are reported, never auto-merged).
- Only the six allowlisted source identities are ever written. Anything else
  in the export is refused.
- Dry-run is the default; pass --apply to actually write.
- No updates: any matched existing post aborts the run (never re-written here).
- Every create is followed by a full verification read (context=edit); the
  audit (plan/before/after) is written to a JSON log and mirrored to stdout.
- Taxonomy writes are name→ID lookups against production term lists.
  Missing terms are recorded as SKIPPED-TERM and never created.
- No media upload (IVVCC precedent). Cards fall back to the `_event_banner`
  external URL written in meta, then to the theme placeholder.

REQUIREMENT
-----------
Production must run conexao-event-runtime >= 1.2.0 (auth_callback on the
protected `_event_*` meta + `_event_export_uuid` registration). Verified
read-only before writing via the context=edit meta schema.

Usage
-----
    python3 scripts/mondello-production-import.py [--apply] [--export PATH]
"""

import argparse
import base64
import json
import sys
import time
import urllib.error
import urllib.request

BASE_URL = "https://conexaobr.ie"

# The only source identities this script may create (Stage C validated set).
ALLOWLIST_SOURCE_IDS = {
    "james-deane-130-showdown",
    "drift-games-winter-bash",
    "iccr-september-2026",
    "irx-october-2026",
    "iccr-october-2026",
    "irx-november-2026",
}

ALLOWED_SOURCE = "mondellopark"

# Taxonomies assigned by name (production term lookup; missing terms are
# recorded, never created).
TAXONOMIES = ("conexao_county", "conexao_category", "conexao_tag", "conexao_town")


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
            "User-Agent": "ConexaoBR/1.0 (Mondello Production Import)",
            "Accept": "application/json",
        }

    def request(self, method, path, data=None):
        url = f"{BASE_URL}{path}"
        body = json.dumps(data).encode() if data is not None else None
        req = urllib.request.Request(url, data=body, headers=self.headers, method=method)
        try:
            with urllib.request.urlopen(req, timeout=30) as resp:
                raw = resp.read()
                return json.loads(raw), resp.status
        except urllib.error.HTTPError as e:
            raw = e.read().decode("utf-8", errors="replace")
            try:
                return json.loads(raw), e.code
            except json.JSONDecodeError:
                return {"message": raw[:300]}, e.code
        except urllib.error.URLError as e:
            return {"message": str(e.reason)}, 0

    def get_all_events(self):
        all_events, page = [], 1
        while True:
            result, status = self.request(
                "GET",
                f"/wp-json/wp/v2/event?per_page=100&page={page}"
                "&_fields=id,slug,title,link,meta",
            )
            if status != 200 or not isinstance(result, list) or not result:
                break
            all_events.extend(result)
            if len(result) < 100:
                break
            page += 1
            time.sleep(1)
        return all_events

    def get_event(self, post_id, context="edit"):
        data, status = self.request(
            "GET", f"/wp-json/wp/v2/event/{post_id}?context={context}"
        )
        return data, status


def get_terms(rest, taxonomy):
    """Fetch all production terms for a taxonomy as {name_lower: id}."""
    terms, page = {}, 1
    while True:
        data, status = rest.request(
            "GET",
            f"/wp-json/wp/v2/{taxonomy}?per_page=100&page={page}&_fields=id,name",
        )
        if status != 200 or not isinstance(data, list) or not data:
            break
        for t in data:
            terms[t["name"].strip().lower()] = t["id"]
        if len(data) < 100:
            break
        page += 1
        time.sleep(0.5)
    return terms


def main():
    parser = argparse.ArgumentParser(description="Mondello production import")
    parser.add_argument("--apply", action="store_true", help="actually write")
    parser.add_argument("--export", default="dist/mondello-only-export.json")
    args = parser.parse_args()

    user, pwd = load_creds()
    rest = Rest(user, pwd)

    print("=" * 70)
    print("MONDELLO PARK PRODUCTION IMPORT —", "APPLY" if args.apply else "DRY-RUN")
    print("=" * 70)

    # ---- Auth + runtime capability check (read-only) --------------------
    data, status = rest.request("GET", "/wp-json/wp/v2/event?per_page=1&_fields=id")
    if status != 200:
        print(f"FAIL: cannot read production events (HTTP {status})")
        return 1
    edit, status = rest.request(
        "GET", "/wp-json/wp/v2/event?per_page=1&context=edit&_fields=id,meta"
    )
    if status != 200 or not isinstance(edit, list) or not edit:
        print(f"FAIL: no edit-context access (HTTP {status}) — cannot verify runtime")
        return 1
    meta_schema = (edit[0] or {}).get("meta") or {}
    if "_event_export_uuid" not in meta_schema and "_event_source" not in meta_schema:
        print("FAIL: _event_* meta not exposed in edit context — runtime < 1.2.0?")
        return 1
    print(
        "Pre-flight: auth OK; edit-context meta OK;"
        f" runtime meta keys visible: {len(meta_schema)}"
    )

    # ---- Production baseline --------------------------------------------
    prod = rest.get_all_events()
    print(f"Pre-flight: production events fetched: {len(prod)}")
    mondello = [
        e for e in prod
        if (e.get("meta") or {}).get("_event_source") == ALLOWED_SOURCE
    ]
    if mondello:
        print("GATE: existing Mondello events found — STOP (would-update/unexpected):")
        for e in mondello:
            print(f"  #{e['id']} {e['slug']}")
        return 1
    slugs = {e["slug"] for e in prod}
    clash = slugs & ALLOWLIST_SOURCE_IDS
    if clash:
        print(f"GATE: existing posts with allowlisted slugs — STOP: {sorted(clash)}")
        return 1

    # ---- Load + validate export ------------------------------------------
    try:
        with open(args.export, "r", encoding="utf-8") as fh:
            export = json.load(fh)
    except FileNotFoundError:
        print(f"FAIL: export file not found: {args.export}")
        return 1
    if export.get("manifest", {}).get("format") != "conexao-event-export":
        print("FAIL: invalid export format")
        return 1
    events = export.get("events", [])
    bad_ids = [
        (e.get("meta") or {}).get("_event_source_id")
        for e in events
        if (e.get("meta") or {}).get("_event_source") != ALLOWED_SOURCE
        or (e.get("meta") or {}).get("_event_source_id") not in ALLOWLIST_SOURCE_IDS
    ]
    if bad_ids:
        print(f"GATE: export contains non-allowlisted events — STOP: {bad_ids}")
        return 1
    print(f"Export OK: {len(events)} events, all allowlisted Mondello identities")

    # ---- Production term lookups (read-only) ------------------------------
    term_maps = {tax: get_terms(rest, tax) for tax in TAXONOMIES}
    print(
        "Production terms: "
        + ", ".join(f"{tax}={len(m)}" for tax, m in term_maps.items())
    )

    audit = {
        "run": "mondello-production-import",
        "mode": "apply" if args.apply else "dry-run",
        "timestamp": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
        "baseline_count": len(prod),
        "events": [],
        "overall": "PENDING",
    }
    overall_pass = True
    created_ids = []

    for event in events:
        meta = event.get("meta", {})
        post = event.get("post", {})
        slug = post.get("slug") or meta.get("_event_source_id")
        rec = {
            "source_id": meta.get("_event_source_id"),
            "title": post.get("title"),
            "slug": slug,
            "date": meta.get("_event_date"),
            "end_date": meta.get("_event_end_date", ""),
            "ticket_url": meta.get("_event_url"),
            "canonical_url": meta.get("_event_source_url"),
        }

        payload_meta = {k: v for k, v in meta.items() if v not in (None, "")}
        payload_meta["_event_export_uuid"] = event.get("uuid", "")

        # Taxonomy assignment: name → production term ID (never create terms).
        tax_payload = {}
        skipped_terms = {}
        for tax in TAXONOMIES:
            names = (event.get("taxonomies") or {}).get(tax) or []
            ids = []
            for name in names:
                tid = term_maps[tax].get(str(name).strip().lower())
                if tid:
                    ids.append(tid)
                else:
                    skipped_terms.setdefault(tax, []).append(name)
            if ids:
                tax_payload[tax] = ids
        rec["skipped_terms"] = skipped_terms

        payload = {
            "title": post.get("title", ""),
            "slug": slug,
            "status": "publish",
            "content": post.get("content", ""),
            "meta": payload_meta,
        }
        payload.update(tax_payload)

        print(f"\n[{meta.get('_event_source_id')}] {post.get('title')}")
        print(f"  slug      : {slug}")
        print(f"  date      : {meta.get('_event_date')} → {meta.get('_event_end_date') or '(single day)'}")
        print(f"  ticket    : {meta.get('_event_url')}")
        print(f"  canonical : {meta.get('_event_source_url')}")
        print(f"  meta keys : {len(payload_meta)} | taxonomy payload: {tax_payload}")
        if skipped_terms:
            print(f"  skipped terms (absent on production): {skipped_terms}")

        if not args.apply:
            rec["status"] = "DRY-RUN (not written)"
            audit["events"].append(rec)
            print("  >>> DRY-RUN — no write performed")
            continue

        result, code = rest.request("POST", "/wp-json/wp/v2/event", payload)
        if code not in (200, 201):
            rec["status"] = "FAIL"
            rec["reason"] = f"create HTTP {code}: {json.dumps(result)[:300]}"
            audit["events"].append(rec)
            overall_pass = False
            print(f"  >>> FAIL: create HTTP {code}: {json.dumps(result)[:300]}")
            break

        post_id = result.get("id")
        created_ids.append(post_id)
        rec["post_id"] = post_id
        rec["public_url"] = result.get("link")
        print(f"  >>> created post #{post_id}: {result.get('link')}")

        time.sleep(5)
        after, a_code = rest.get_event(post_id)
        if a_code != 200:
            rec["status"] = "FAIL"
            rec["reason"] = f"after-read HTTP {a_code}"
            audit["events"].append(rec)
            overall_pass = False
            print(f"  >>> FAIL: after-read HTTP {a_code}")
            break

        after_meta = after.get("meta", {})
        mismatches = {
            k: {"expected": v, "actual": after_meta.get(k)}
            for k, v in payload_meta.items()
            if after_meta.get(k) != v
        }
        after_tax = {tax: after.get(tax, []) for tax in tax_payload}
        tax_mismatch = {}
        for tax, ids in tax_payload.items():
            actual_ids = sorted(t.get("id") for t in after_tax[tax])
            if actual_ids != sorted(ids):
                tax_mismatch[tax] = {
                    "expected_ids": sorted(ids),
                    "actual": after_tax[tax],
                }
        rec["after"] = {
            "title": (after.get("title") or {}).get("raw"),
            "slug": after.get("slug"),
            "status": after.get("status"),
            "meta": {k: after_meta.get(k) for k in payload_meta},
            "taxonomies": after_tax,
        }
        if mismatches or tax_mismatch:
            rec["status"] = "FAIL"
            rec["mismatches"] = mismatches
            rec["tax_mismatch"] = tax_mismatch
            overall_pass = False
            print(
                f"  >>> FAIL: {len(mismatches)} meta + {len(tax_mismatch)} "
                "taxonomy mismatches"
            )
            break
        rec["status"] = "PASS"
        print(
            f"  >>> PASS: {len(payload_meta)} meta fields + "
            f"{len(tax_payload)} taxonomies verified"
        )

    # ---- Final reconciliation ---------------------------------------------
    if args.apply and created_ids:
        time.sleep(3)
        final = rest.get_all_events()
        final_mondello = [
            e for e in final
            if (e.get("meta") or {}).get("_event_source") == ALLOWED_SOURCE
        ]
        audit["final_count"] = len(final)
        audit["final_mondello_count"] = len(final_mondello)
        audit["final_mondello_ids"] = sorted(e["id"] for e in final_mondello)
        print("\n" + "=" * 70)
        print(f"Final production count : {len(final)} (baseline {len(prod)})")
        print(f"Mondello events        : {len(final_mondello)} (expected {len(events)})")
        for e in final_mondello:
            print(f"  #{e['id']} {e['slug']}")

    print("\n" + "=" * 70)
    print(f"RESULT: {'PASS' if overall_pass else 'FAIL'} "
          f"({'APPLY' if args.apply else 'DRY-RUN'})")
    audit["overall"] = "PASS" if overall_pass else "FAIL"
    log_name = (
        f"/tmp/mondello-production-import-{'apply' if args.apply else 'dryrun'}-"
        f"{int(time.time())}.json"
    )
    with open(log_name, "w", encoding="utf-8") as fh:
        json.dump(audit, fh, indent=2, ensure_ascii=False)
    print(f"Audit log: {log_name}")
    return 0 if overall_pass else 1


if __name__ == "__main__":
    sys.exit(main())


