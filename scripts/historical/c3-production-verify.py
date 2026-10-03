#!/usr/bin/env python3
"""
Stage C3 - Post-import production verification + reconciliation (READ-ONLY).

Fetches every Event exposed by the production REST API (conexaobr.ie),
reconciles it against the authoritative Stage C2 pilot export artifact
(`c2-pilot-export.json`) and emits a machine-readable reconciliation report.

This script performs NO writes of any kind:
  * no production import / re-import
  * no source crawling / activation
  * no POST/PUT/DELETE against the REST API (GET only)
  * no media changes

The production architecture is intentionally:
    LOCAL SOURCE IMPORT -> VALIDATED EXPORT -> CONTROLLED PRODUCTION IMPORT

Usage:
    python3 scripts/c3-production-verify.py \
        --export /tmp/c2-pilot-export.json \
        --out docs/importers/events-expansion-stage-c3-reconciliation.json

Options:
    --base-url URL   Site base (default https://conexaobr.ie)
    --cache FILE     Cache the raw production fetch here (default /tmp/c3-production-events.json)
    --no-cache       Bypass the cache and re-fetch
    --export FILE    The authoritative C2 export artifact (default /tmp/c2-pilot-export.json)
    --out FILE       Machine-readable reconciliation report output
"""

import argparse
import html
import json
import os
import re
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from collections import Counter

PILOT_SOURCES = [
    "eventbrite_laois",
    "eventbrite_cork",
    "eventbrite_dublin",
    "heritage_week_laois",
    "heritage_week_cork",
    "heritage_week_dublin",
]

# Counties exercised by the pilot (town/county coverage report).
PILOT_COUNTIES = {"Laois", "Cork", "Dublin"}

FIELDS = ",".join(
    [
        "id",
        "slug",
        "link",
        "modified",
        "title",
        "meta",
        "conexao_county",
        "conexao_town",
        "conexao_category",
        "featured_media",
    ]
)


def http_get(url, timeout=90, retries=4, auth=None):
    """GET a URL with a bounded retry loop (transient WP.com 5xx/timeouts)."""
    last = None
    for attempt in range(retries):
        headers = {"User-Agent": "conexao-c3-verify/1.0"}
        if auth:
            headers["Authorization"] = "Basic " + auth
        req = urllib.request.Request(url, headers=headers)
        try:
            with urllib.request.urlopen(req, timeout=timeout) as resp:
                return resp.read(), dict(resp.headers)
        except urllib.error.HTTPError as e:
            last = e
            if e.code in (429, 500, 502, 503, 504):
                time.sleep(2 * (attempt + 1))
                continue
            raise
        except Exception as e:  # noqa: BLE001 - socket timeouts etc.
            last = e
            time.sleep(2 * (attempt + 1))
    raise RuntimeError(f"GET failed after {retries} attempts: {url}: {last}")


def basic_auth_header(user, app_password):
    import base64

    if not user or not app_password:
        return None
    return base64.b64encode(f"{user}:{app_password}".encode()).decode()


def fetch_production(base_url, cache_file, use_cache, auth=None):
    if use_cache and os.path.exists(cache_file):
        with open(cache_file) as f:
            return json.load(f)

    per_page = 100
    page = 1
    events = []
    total = None
    total_pages = None
    # `context=edit` returns raw (un-texturized) titles when authenticated.
    while True:
        params = {"per_page": per_page, "page": page, "_fields": FIELDS}
        if auth:
            params["context"] = "edit"
        q = urllib.parse.urlencode(params)
        url = f"{base_url.rstrip('/')}/wp-json/wp/v2/event?{q}"
        body, headers = http_get(url, auth=auth)
        if total is None:
            total = int(headers.get("x-wp-total", headers.get("X-WP-Total", 0)))
            total_pages = int(
                headers.get("x-wp-totalpages", headers.get("X-WP-TotalPages", 0))
            )
        batch = json.loads(body)
        if not batch:
            break
        events.extend(batch)
        sys.stderr.write(
            f"  fetched page {page}/{total_pages} ({len(events)}/{total})\n"
        )
        if page >= total_pages:
            break
        page += 1

    data = {
        "base_url": base_url,
        "fetched_at": time.strftime("%Y-%m-%dT%H:%M:%S%z"),
        "authenticated": bool(auth),
        "x_wp_total": total,
        "x_wp_totalpages": total_pages,
        "event_count": len(events),
        "events": events,
    }
    with open(cache_file, "w") as f:
        json.dump(data, f)
    return data


def meta_of(ev):
    m = ev.get("meta")
    return m if isinstance(m, dict) else {}


def index_production(events):
    by_uuid, by_identity, by_url = {}, {}, {}
    for ev in events:
        m = meta_of(ev)
        uuid = (m.get("_event_export_uuid") or "").strip()
        src = (m.get("_event_source") or "").strip()
        sid = (m.get("_event_source_id") or "").strip()
        if uuid:
            by_uuid.setdefault(uuid, []).append(ev)
        if src and sid:
            by_identity.setdefault((src, sid), []).append(ev)
        for key in ("_event_url", "_event_source_url"):
            u = (m.get(key) or "").strip()
            if u:
                by_url.setdefault(u, []).append(ev)
    return by_uuid, by_identity, by_url


def pick_candidate(cands, used_ids):
    """Prefer a not-yet-matched production record (stable reconciliation)."""
    for c in cands:
        if c.get("id") not in used_ids:
            return c
    return cands[0] if cands else None


def classify(exp, prod_ev):
    """Return (class, list_of_field_diffs) for a matched pair.

    Comparison normalises whitespace runs (the production import collapses
    repeated spaces) and HTML entities in titles, so only genuine data
    differences are reported.
    """
    if prod_ev is None:
        return "C_MISSING", []

    diffs = []
    m = meta_of(prod_ev)
    em = exp.get("meta", {})
    # Production stores URL-ish meta with every percent-escape sequence removed
    # (WP.com meta sanitisation during the manual import: `%20`/`%2C`/`%3A`/`%2F`
    # vanish). This only affects `_event_banner` (fallback, never used when the
    # local attachment exists) and `_event_map_url` (REST/app-only; not rendered
    # on the site). Normalise both sides so this known transformation is not
    # reported as a data difference, but still record it as an informational note.
    def strip_pct(v):
        return re.sub(r"%[0-9A-Fa-f]{2}", "", v)

    URL_META = {"_event_banner", "_event_map_url", "_event_banner_url"}

    # The export stores the portable identity at the top level (`uuid`); the
    # production meta key is populated by the import. Compare accordingly.
    exp_uuid = (exp.get("uuid") or "").strip()
    # The export does not carry the attachment id meta; the import sets it.
    SKIP_KEYS = {"_event_banner_attachment_id"}
    TIMESTAMPY = {"_event_import_date", "_event_last_checked"}

    def norm(s):
        return " ".join(str(s or "").split())

    keys = sorted(set(em.keys()) | set(m.keys()))
    for key in keys:
        if key in SKIP_KEYS:
            continue
        want = norm(em.get(key))
        got = norm(m.get(key))
        if key == "_event_export_uuid":
            want = norm(exp_uuid)
        if key in URL_META:
            want = norm(strip_pct(want))
            got = norm(strip_pct(got))
        if want == got:
            continue
        diff = {"field": key, "expected": want, "actual": got}
        if key in TIMESTAMPY:
            diff["note"] = "timestamp (import-time value; expected to differ)"
        diffs.append(diff)

    def title_of(ev):
        t = ev.get("title")
        if isinstance(t, dict):
            # Prefer the raw stored title (available with context=edit). If only
            # the rendered title is available, it has been through the_title
            # filters (wptexturize turns " - " into an en-dash, convert_chars
            # encodes "&"); decoding entities recovers the stored value closely
            # enough that only genuine differences are reported.
            if t.get("raw"):
                return t["raw"].strip()
            return html.unescape(t.get("rendered") or "").strip()
        return html.unescape(t or "").strip()

    et = norm(exp.get("post", {}).get("title"))
    gt = norm(title_of(prod_ev))
    if et != gt:
        diffs.append({"field": "title", "expected": et, "actual": gt})

    exp_img = bool(exp.get("featured_image", {}).get("data_base64"))
    got_img = bool(prod_ev.get("featured_media"))
    if exp_img != got_img:
        diffs.append(
            {
                "field": "featured_media",
                "expected": "present" if exp_img else "none",
                "actual": "present" if got_img else "none",
            }
        )
    return ("B_DIFF" if diffs else "A_OK"), diffs


def prod_stats(events):
    st = {
        "total": len(events),
        "by_source": {},
        "by_status": {},
        "with_venue": 0,
        "with_address": 0,
        "with_image": 0,
        "with_url": 0,
        "with_uuid": 0,
        "town_term_ids": {},
        "county_term_ids": {},
        "category_term_ids": {},
    }
    for ev in events:
        m = meta_of(ev)
        src = m.get("_event_source") or "(manual/none)"
        st["by_source"][src] = st["by_source"].get(src, 0) + 1
        status = m.get("_event_status") or "(none)"
        st["by_status"][status] = st["by_status"].get(status, 0) + 1
        if (m.get("_event_venue") or "").strip():
            st["with_venue"] += 1
        if (m.get("_event_address") or "").strip():
            st["with_address"] += 1
        if ev.get("featured_media"):
            st["with_image"] += 1
        if (m.get("_event_url") or "").strip():
            st["with_url"] += 1
        if (m.get("_event_export_uuid") or "").strip():
            st["with_uuid"] += 1
        for term in ev.get("conexao_town") or []:
            st["town_term_ids"][str(term)] = st["town_term_ids"].get(str(term), 0) + 1
        for term in ev.get("conexao_county") or []:
            st["county_term_ids"][str(term)] = st["county_term_ids"].get(str(term), 0) + 1
        for term in ev.get("conexao_category") or []:
            st["category_term_ids"][str(term)] = st["category_term_ids"].get(str(term), 0) + 1
    return st

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--base-url", default="https://conexaobr.ie")
    ap.add_argument("--export", default="/tmp/c2-pilot-export.json")
    ap.add_argument("--cache", default="/tmp/c3-production-events.json")
    ap.add_argument("--no-cache", action="store_true")
    ap.add_argument(
        "--out",
        default="docs/importers/events-expansion-stage-c3-reconciliation.json",
    )
    args = ap.parse_args()

    # Authenticated fetch (context=edit) gives raw stored titles. Optional:
    # the verification still works read-only without credentials.
    auth = basic_auth_header(
        os.environ.get("WP_USERNAME"), os.environ.get("WP_APPLICATION_PASSWORD")
    )

    print("=== Stage C3 production verification (READ-ONLY) ===")
    print(f"base_url   = {args.base_url}")
    print(f"auth       = {'yes' if auth else 'no (public context)'}")
    print(f"export     = {args.export}")
    print(f"cache      = {args.cache}")

    prod = fetch_production(args.base_url, args.cache, not args.no_cache, auth=auth)
    print(
        f"production events fetched = {prod['event_count']} "
        f"(X-WP-Total={prod['x_wp_total']})"
    )

    with open(args.export) as f:
        exp = json.load(f)
    exp_manifest = exp["manifest"]
    exp_events = exp["events"]
    print(
        f"C2 export events = {len(exp_events)} "
        f"(format {exp_manifest['format']} {exp_manifest['version']})"
    )

    prods = prod["events"]
    by_uuid, by_identity, by_url = index_production(prods)

    pstats = prod_stats(prods)
    pilot_prod = [
        e for e in prods if (meta_of(e).get("_event_source") or "") in PILOT_SOURCES
    ]
    nonpilot_prod = [
        e for e in prods if (meta_of(e).get("_event_source") or "") not in PILOT_SOURCES
    ]

    # ---- duplicate audit (production) -----------------------------------------
    id_dupes = {
        k: v
        for k, v in Counter(
            [
                (
                    meta_of(e).get("_event_source") or "",
                    meta_of(e).get("_event_source_id") or "",
                )
                for e in prods
                if meta_of(e).get("_event_source") and meta_of(e).get("_event_source_id")
            ]
        ).items()
        if v > 1
    }
    uuid_dupes = {
        k: v
        for k, v in Counter(
            [
                meta_of(e).get("_event_export_uuid") or ""
                for e in prods
                if meta_of(e).get("_event_export_uuid")
            ]
        ).items()
        if v > 1
    }
    url_dupes = {
        k: v
        for k, v in Counter(
            [meta_of(e).get("_event_url") or "" for e in prods if meta_of(e).get("_event_url")]
        ).items()
        if v > 1
    }

    # ---- reconciliation --------------------------------------------------------
    used = set()
    rows = []
    counts = {"A_OK": 0, "B_DIFF": 0, "C_MISSING": 0, "D_DUPLICATED": 0}
    for e in exp_events:
        uuid = (e.get("uuid") or "").strip()
        em = e.get("meta", {})
        src = (em.get("_event_source") or "").strip()
        sid = (em.get("_event_source_id") or "").strip()
        urls = [u for u in (em.get("_event_url"), em.get("_event_source_url")) if u]

        matched = None
        matched_by = None
        if uuid and by_uuid.get(uuid):
            matched = pick_candidate(by_uuid[uuid], used)
            matched_by = "uuid"
        if matched is None and src and sid and (src, sid) in by_identity:
            matched = pick_candidate(by_identity[(src, sid)], used)
            matched_by = "source_id"
        if matched is None:
            for u in urls:
                if u in by_url:
                    matched = pick_candidate(by_url[u], used)
                    matched_by = "url"
                    break

        dup = False
        if matched is not None:
            used.add(matched.get("id"))
            if src and sid and len(by_identity.get((src, sid), [])) > 1:
                dup = True
            if uuid and len(by_uuid.get(uuid, [])) > 1:
                dup = True

        cls, diffs = classify(e, matched)
        if dup and cls in ("A_OK", "B_DIFF"):
            cls = "D_DUPLICATED"
        counts[cls] += 1
        rows.append(
            {
                "uuid": uuid,
                "source": src,
                "source_id": sid,
                "title": e.get("post", {}).get("title", ""),
                "post_status": e.get("post", {}).get("status", ""),
                "matched_by": matched_by,
                "prod_id": matched.get("id") if matched else None,
                "prod_slug": matched.get("slug") if matched else None,
                "prod_status": meta_of(matched).get("_event_status") if matched else None,
                "classification": cls,
                "diffs": diffs,
            }
        )

    unexpected = [e for e in pilot_prod if e.get("id") not in used]

    # Informational (non-functional): events whose URL-ish meta lost its
    # percent-escapes during the production import. Not a data difference for
    # rendering (banner falls back to the local attachment; map_url is
    # app-only) — tracked separately so the transformation is not hidden.
    url_meta_transformations = {"_event_banner": 0, "_event_map_url": 0}
    for e in exp_events:
        cands = by_uuid.get((e.get("uuid") or "").strip()) or []
        if not cands:
            continue
        pm = meta_of(cands[0])
        for k in url_meta_transformations:
            if (e.get("meta", {}).get(k) or "") != (pm.get(k) or ""):
                url_meta_transformations[k] += 1

    report = {
        "stage": "C3",
        "generated_at": time.strftime("%Y-%m-%dT%H:%M:%S%z"),
        "base_url": args.base_url,
        "production_fetch": {
            "x_wp_total": prod["x_wp_total"],
            "x_wp_totalpages": prod["x_wp_totalpages"],
            "event_count": prod["event_count"],
        },
        "c2_export": {
            "path": args.export,
            "manifest": exp_manifest,
            "event_count": len(exp_events),
        },
        "production_stats_all": pstats,
        "production_stats_pilot": prod_stats(pilot_prod),
        "production_stats_nonpilot": prod_stats(nonpilot_prod),
        "duplicates_production": {
            "by_identity": {f"{k[0]}|{k[1]}": v for k, v in id_dupes.items()},
            "by_uuid": uuid_dupes,
            "by_url": url_dupes,
            "identity_dupe_groups": len(id_dupes),
            "uuid_dupe_groups": len(uuid_dupes),
            "url_dupe_groups": len(url_dupes),
        },
        "reconciliation": {
            "expected": len(exp_events),
            "matched": counts["A_OK"] + counts["B_DIFF"] + counts["D_DUPLICATED"],
            "matched_exact": counts["A_OK"],
            "matched_with_diff": counts["B_DIFF"],
            "missing": counts["C_MISSING"],
            "duplicated": counts["D_DUPLICATED"],
            "unexpected_pilot_in_production": len(unexpected),
            "informational_transform_url_meta": url_meta_transformations,
            "unexpected_pilot_ids": [
                {
                    "id": e.get("id"),
                    "slug": e.get("slug"),
                    "source": meta_of(e).get("_event_source"),
                    "source_id": meta_of(e).get("_event_source_id"),
                    "uuid": meta_of(e).get("_event_export_uuid"),
                }
                for e in unexpected
            ],
        },
        "rows": rows,
    }

    out_dir = os.path.dirname(args.out)
    if out_dir:
        os.makedirs(out_dir, exist_ok=True)
    with open(args.out, "w") as f:
        json.dump(report, f, indent=2, ensure_ascii=False)

    # ---- console summary -------------------------------------------------------
    print()
    print("=== production stats (all) ===")
    print(
        f"  total={pstats['total']}  with_venue={pstats['with_venue']}  "
        f"with_address={pstats['with_address']}  with_image={pstats['with_image']}  "
        f"with_uuid={pstats['with_uuid']}"
    )
    print(f"  by_status={pstats['by_status']}")
    print("  by_source (top):")
    for s, n in sorted(pstats["by_source"].items(), key=lambda kv: -kv[1]):
        print(f"    {s}: {n}")
    print()
    print("=== reconciliation (C2 export vs production) ===")
    r = report["reconciliation"]
    print(f"  EXPECTED   = {r['expected']}")
    print(
        f"  MATCHED    = {r['matched']}  "
        f"(exact {r['matched_exact']}, diff {r['matched_with_diff']})"
    )
    print(f"  MISSING    = {r['missing']}")
    print(f"  DUPLICATED = {r['duplicated']}")
    print(f"  UNEXPECTED (pilot-source prod not in C2) = "
          f"{r['unexpected_pilot_in_production']}")
    print(
        "  informational URL-meta transform (percent-escapes stripped) = "
        f"{r['informational_transform_url_meta']}"
    )
    print()
    print("=== duplicates in production ===")
    d = report["duplicates_production"]
    print(
        f"  identity groups={d['identity_dupe_groups']}  "
        f"uuid groups={d['uuid_dupe_groups']}  url groups={d['url_dupe_groups']}"
    )
    print()
    print(f"report written -> {args.out}")


if __name__ == "__main__":
    main()

