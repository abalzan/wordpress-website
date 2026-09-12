#!/usr/bin/env python3
"""
Mondello Park — Stage C recovery, Phase 1/3 production diagnostics.

Read-only by default:
  1. Public REST read of post 11705 (current partial-post state).
  2. Public REST census (meta exposure check on existing events).
  3. Authenticated /users/me (context=edit) — credential validity.
  4. Authenticated plain GET of post 11705 — credential accepted for reads.
  5. Authenticated GET 11705?context=edit — meta schema inspection:
     `_event_export_uuid` present => runtime >= 1.2.0 (registered in 1.2.0).

Optional single-field capability probe (Phase 3 — the ONLY permitted write):
  python3 scripts/mp-phase1-diagnostics.py --probe
  PATCHes exactly one field (`_event_export_uuid`) on post 11705 with the
  value from the validated export, then reads it back. No other write, no
  retries. Any non-success stops immediately.
"""

import base64
import json
import sys
import urllib.error
import urllib.request

BASE_URL = "https://conexaobr.ie"
PARTIAL_ID = 11705
PARTIAL_SLUG = "james-deane-130-showdown"
PROBE_KEY = "_event_export_uuid"


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
    def __init__(self, user=None, pwd=None):
        self.headers = {
            "Content-Type": "application/json",
            "User-Agent": "ConexaoBR/1.0 (Mondello Phase1 Diagnostics)",
            "Accept": "application/json",
        }
        if user and pwd:
            token = base64.b64encode(f"{user}:{pwd}".encode()).decode()
            self.headers["Authorization"] = f"Basic {token}"

    def request(self, method, path, data=None):
        url = f"{BASE_URL}{path}"
        body = json.dumps(data).encode() if data is not None else None
        req = urllib.request.Request(url, data=body, headers=self.headers, method=method)
        try:
            with urllib.request.urlopen(req, timeout=30) as resp:
                return resp.status, json.loads(resp.read())
        except urllib.error.HTTPError as e:
            raw = e.read()
            try:
                return e.code, json.loads(raw)
            except Exception:
                return e.code, {"raw": raw[:400].decode(errors="replace")}


def main():
    do_probe = "--probe" in sys.argv
    anon = Rest()

    print("=" * 70)
    print("PHASE 1 — PRODUCTION DIAGNOSTICS (read-only unless --probe)")
    print("=" * 70)

    # 1. Public state of post 11705
    code, d = anon.request("GET", f"/wp-json/wp/v2/event/{PARTIAL_ID}")
    print(f"\n[1] GET event/{PARTIAL_ID} (public): HTTP {code}")
    if code != 200:
        print(f"    {json.dumps(d)[:300]}")
        return 1
    meta = d.get("meta") or {}
    print(f"    id={d.get('id')} slug={d.get('slug')} status={d.get('status')}")
    print(f"    link={d.get('link')}")
    print(f"    title={(d.get('title') or {}).get('rendered')!r}")
    print(f"    slug_match={d.get('slug') == PARTIAL_SLUG}")
    print(f"    meta keys exposed publicly: {sorted(meta.keys()) or 'none'}")
    print(f"    _event_date={meta.get('_event_date')!r}  _event_source={meta.get('_event_source')!r}")
    try:
        with urllib.request.urlopen(urllib.request.Request(d.get("link"), method="HEAD"), timeout=30) as r:
            print(f"    public URL HTTP {r.status}")
    except urllib.error.HTTPError as e:
        print(f"    public URL HTTP {e.code}")

    # 2. Meta exposure on existing events
    code, events = anon.request("GET", "/wp-json/wp/v2/event?per_page=100&page=1")
    print(f"\n[2] GET /event?per_page=100 (public census): HTTP {code}")
    published = events if isinstance(events, list) else []
    print(f"    published events on page 1: {len(published)}")
    with_meta = [e for e in published
                 if e.get("meta") and (e["meta"].get("_event_source") or e["meta"].get("_event_date"))]
    print(f"    events with _event_* meta exposed publicly: {len(with_meta)}")
    if with_meta:
        sample = with_meta[0]
        print(f"    sample #{sample['id']} {sample['slug']}: "
              f"meta keys={sorted((sample.get('meta') or {}).keys())}")
    return run_auth_checks(do_probe)


def run_auth_checks(do_probe):
    # 3. Authenticated identity
    user, pwd = load_creds()
    auth = Rest(user, pwd)
    code, me = auth.request("GET", "/wp-json/wp/v2/users/me?context=edit&_fields=id,name,roles,capabilities")
    print(f"\n[3] GET /users/me?context=edit: HTTP {code}")
    if code == 200:
        caps = me.get("capabilities") or {}
        print(f"    id={me.get('id')} name={me.get('name')!r} roles={me.get('roles')}")
        print(f"    edit_posts={caps.get('edit_posts')} manage_options={caps.get('manage_options')}")
    else:
        print(f"    {json.dumps(me)[:300]}")

    # 4. Plain authenticated read of 11705
    code, d = auth.request("GET", f"/wp-json/wp/v2/event/{PARTIAL_ID}?_fields=id,slug,status,link,modified")
    print(f"\n[4] GET event/{PARTIAL_ID} (auth, plain): HTTP {code}")
    if code != 200:
        print(f"    {json.dumps(d)[:300]}")
        print("    >>> credential NOT accepted for plain reads — STOP")
        return 1
    print(f"    {json.dumps(d)[:300]}")

    # 5. context=edit read — runtime registration + version gate
    code, edit = auth.request("GET", f"/wp-json/wp/v2/event/{PARTIAL_ID}?context=edit")
    print(f"\n[5] GET event/{PARTIAL_ID}?context=edit: HTTP {code}")
    if code != 200:
        print(f"    {json.dumps(edit)[:300]}")
        print("    >>> credential NOT accepted for edit-context reads — STOP")
        return 1
    meta_schema = edit.get("meta") or {}
    keys = sorted(meta_schema.keys())
    print(f"    meta schema keys on 11705 ({len(keys)}): {keys}")
    has_uuid = PROBE_KEY in keys
    print(f"    {PROBE_KEY} registered: {has_uuid}")
    print(f"    runtime >= 1.2.0 gate: {'PASS' if has_uuid else 'FAIL — runtime < 1.2.0 or not deployed'}")
    if not has_uuid:
        print("\nPHASE 2 GATE: runtime < 1.2.0 — STOP; operator must deploy "
              "dist/conexao-event-runtime.zip")
        return 2
    return finish(do_probe)


def finish(do_probe):
    # Probe payload value from the validated export
    probe_value = None
    try:
        with open("dist/mondello-only-export.json", "r", encoding="utf-8") as fh:
            export = json.load(fh)
        rows = export if isinstance(export, list) else export.get("events") or []
        for ev in rows:
            if (ev.get("slug") or ev.get("source_id")) == PARTIAL_SLUG:
                probe_value = ev.get("uuid")
                break
    except FileNotFoundError:
        pass
    if probe_value is None:
        print("\nERROR: export row for james-deane-130-showdown not found — cannot probe safely")
        return 1

    if not do_probe:
        print(f"\nProbe not requested. Ready probe payload: PATCH event/{PARTIAL_ID} "
              f"meta.{PROBE_KEY} = {probe_value!r}")
        print("Run with --probe to execute the single-field capability probe.")
        return 0

    # ---- Phase 3: single-field capability probe (the ONLY permitted write) --
    print("\n" + "=" * 70)
    print("PHASE 3 — SINGLE-FIELD CAPABILITY PROBE (one write, no retries)")
    print("=" * 70)
    auth = Rest(*load_creds())
    code, res = auth.request("PATCH", f"/wp-json/wp/v2/event/{PARTIAL_ID}",
                             {"meta": {PROBE_KEY: probe_value}})
    print(f"\nPATCH event/{PARTIAL_ID} meta.{PROBE_KEY}: HTTP {code}")
    print(f"    {json.dumps(res)[:400]}")
    if code != 200:
        print("\n>>> PROBE FAILED — STOP. Do not retry. Report to operator.")
        return 1
    code, after = auth.request("GET", f"/wp-json/wp/v2/event/{PARTIAL_ID}?context=edit")
    actual = (after.get("meta") or {}).get(PROBE_KEY) if code == 200 else None
    print(f"Read-back: {PROBE_KEY}={actual!r}")
    if actual == probe_value:
        print(">>> PROBE PASS: protected meta write capability CONFIRMED.")
        return 0
    print(">>> PROBE mismatch — STOP. Do not retry.")
    return 1


if __name__ == "__main__":
    sys.exit(main())

