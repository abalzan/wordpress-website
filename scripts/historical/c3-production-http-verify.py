#!/usr/bin/env python3
"""
Stage C3 - LIVE production HTTP verification (READ-ONLY).

Exercises the live production front-end and REST API for the Events archive:
filters (county / city / category, strict AND), detail pages, external links,
sitemap and timing. GET requests only - no writes, no crawling, no import.

Usage:
    python3 scripts/c3-production-http-verify.py \
        --events-cache /tmp/c3-production-events.json \
        --out docs/importers/events-expansion-stage-c3-http.json
"""

import argparse
import json
import os
import re
import time
import urllib.error
import urllib.request

UA = {"User-Agent": "conexao-c3-verify/1.0"}

ARCHIVE_URLS = [
    ("archive_unfiltered", "/eventos/"),
    ("county_laois", "/eventos/?county=laois"),
    ("county_cork", "/eventos/?county=cork"),
    ("county_dublin", "/eventos/?county=dublin"),
    ("city_portlaoise", "/eventos/?cidade=portlaoise"),
    ("city_cork", "/eventos/?cidade=cork"),
    ("city_dublin", "/eventos/?cidade=dublin"),
    ("county_laois_city_portlaoise", "/eventos/?county=laois&cidade=portlaoise"),
    ("county_kildare_city_maynooth", "/eventos/?county=kildare&cidade=maynooth"),
    ("category_rally", "/eventos/?categoria=rally"),
    ("county_kildare_category_rally", "/eventos/?county=kildare&categoria=rally"),
    # Strict AND proof: an existing county + a city from ANOTHER county must be 0.
    ("and_proof_cork_county_laois_city", "/eventos/?county=cork&cidade=portlaoise"),
    ("and_proof_laois_county_cork_city", "/eventos/?county=laois&cidade=cork"),
    ("unknown_county", "/eventos/?county=atlantis"),
]


def get(url, timeout=90, retries=3):
    last = None
    for attempt in range(retries):
        req = urllib.request.Request(url, headers=UA)
        try:
            t0 = time.time()
            with urllib.request.urlopen(req, timeout=timeout) as resp:
                body = resp.read().decode("utf-8", "replace")
                return resp.status, body, time.time() - t0, dict(resp.headers)
        except urllib.error.HTTPError as e:
            last = e
            if e.code in (429, 500, 502, 503, 504):
                time.sleep(2 * (attempt + 1))
                continue
            return e.code, "", 0.0, {}
        except Exception as e:  # noqa: BLE001
            last = e
            time.sleep(2 * (attempt + 1))
    return None, "", 0.0, {}


def max_page(html):
    pages = [int(n) for n in re.findall(r"/page/(\d+)/", html)]
    return max(pages) if pages else 1


def count_cards(html):
    return len(re.findall(r"<article\b", html))
def pick(events, source, need_image=True):
    pool = [e for e in events if (e.get("meta") or {}).get("_event_source") == source]
    if need_image:
        with_img = [e for e in pool if e.get("featured_media")]
        if with_img:
            pool = with_img
    return pool[0] if pool else None


def verify_detail(base, ev):
    url = ev.get("link")
    code, html, secs, _ = get(url)
    m = re.search(r"<title>(.*?)</title>", html, re.S)
    title = m.group(1).strip() if m else ""
    ld = '"@type":"Event"' in html.replace(" ", "")
    ext = re.findall(
        r'href="(https?://[^"]*(?:eventbrite\.(?:ie|com)|heritageweek\.ie)[^"]*)"', html
    )
    return {
        "id": ev.get("id"),
        "slug": ev.get("slug"),
        "source": (ev.get("meta") or {}).get("_event_source"),
        "url": url,
        "http": code,
        "seconds": round(secs, 3),
        "title_tag": title,
        "has_event_jsonld": bool(ld),
        "has_venue_text": bool(
            ((ev.get("meta") or {}).get("_event_venue") or "___")[:12] in html
        ),
        "external_source_links": sorted(set(ext))[:5],
        "has_og_image": "og:image" in html,
    }


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--base-url", default="https://conexaobr.ie")
    ap.add_argument("--events-cache", default="/tmp/c3-production-events.json")
    ap.add_argument(
        "--out", default="docs/importers/events-expansion-stage-c3-http.json"
    )
    args = ap.parse_args()
    base = args.base_url.rstrip("/")

    result = {
        "generated_at": time.strftime("%Y-%m-%dT%H:%M:%S%z"),
        "archive": [],
        "detail": [],
        "sitemap": {},
    }

    print("=== archive / filter checks ===")
    for name, path in ARCHIVE_URLS:
        code, html, secs, hdrs = get(base + path)
        row = {
            "name": name,
            "path": path,
            "http": code,
            "seconds": round(secs, 3),
            "cards_page1": count_cards(html),
            "max_page": max_page(html),
            "empty_state": ("Nada encontrado" in html)
            or ("Nenhum" in html and count_cards(html) == 0),
            "has_filters": "data-event-filters" in html,
        }
        result["archive"].append(row)
        print(
            f"  {name:34s} http={code} cards={row['cards_page1']:2d} "
            f"maxpage={row['max_page']:3d} empty={row['empty_state']} {row['seconds']}s"
        )

    with open(args.events_cache) as f:
        prod = json.load(f)
    events = prod["events"]

    reps = {
        "eventbrite_laois": pick(events, "eventbrite_laois"),
        "eventbrite_cork": pick(events, "eventbrite_cork"),
        "eventbrite_dublin": pick(events, "eventbrite_dublin"),
        "non_pilot_motorsport_ireland": pick(events, "motorsport_ireland"),
        "non_pilot_legacy_eventbrite": next(
            (
                e
                for e in events
                if (e.get("meta") or {}).get("_event_source") == "eventbrite"
            ),
            None,
        ),
    }
    print()
    print("=== detail page checks ===")
    for label, ev in reps.items():
        if not ev:
            result["detail"].append({"label": label, "found": False})
            print(f"  {label}: NOT FOUND")
            continue
        d = verify_detail(base, ev)
        d["label"] = label
        d["found"] = True
        result["detail"].append(d)
        print(
            f"  {label:30s} http={d['http']} jsonld={d['has_event_jsonld']} "
            f"venue={d['has_venue_text']} ogimg={d['has_og_image']} "
            f"ext={len(d['external_source_links'])} {d['seconds']}s"
        )

    # External source links (card CTA): the archive card links straight to the
    # provider's canonical event URL with target=_blank rel=noopener.
    _, arch, _, _ = get(base + "/eventos/?county=laois")
    cta = re.findall(r'<a href="([^"]+)" class="event-card-cta"[^>]*>', arch)
    ext = [c for c in cta if "eventbrite." in c]
    result["external_links"] = {
        "archive_cta_total": len(cta),
        "archive_cta_external_eventbrite": len(ext),
        "sample": ext[:5],
        "all_target_blank_noopener": arch.count('class="event-card-cta" target="_blank"')
        == len(cta),
    }
    print()
    print(
        f"=== external card links: {len(ext)}/{len(cta)} eventbrite "
        f"(target=_blank rel=noopener={result['external_links']['all_target_blank_noopener']}) ==="
    )

    # Sitemap: production serves the theme's custom sitemap at
    # /sitemap_index.xml (the core sitemap is disabled by design; Jetpack
    # serves a separate posts/pages-only map at /sitemap.xml).
    code, sm, secs, _ = get(base + "/sitemap_index.xml")
    event_in_sitemap = None
    ev_loc_count = len(re.findall(r"<loc>[^<]*/eventos/[^<]*</loc>", sm))
    if reps["eventbrite_laois"]:
        event_in_sitemap = reps["eventbrite_laois"]["link"] in sm
    result["sitemap"] = {
        "url": base + "/sitemap_index.xml",
        "index_http": code,
        "index_seconds": round(secs, 3),
        "event_url_count": ev_loc_count,
        "sample_event_indexable": event_in_sitemap,
    }
    print()
    print(
        f"=== sitemap (/sitemap_index.xml): http={code} "
        f"event_urls={ev_loc_count} sample_present={event_in_sitemap} ==="
    )

    out_dir = os.path.dirname(args.out)
    if out_dir:
        os.makedirs(out_dir, exist_ok=True)
    with open(args.out, "w") as f:
        json.dump(result, f, indent=2, ensure_ascii=False)
    print(f"report written -> {args.out}")


if __name__ == "__main__":
    main()

