#!/usr/bin/env python3
"""
Events Location Filters Stage B — read-only production coverage audit.

GET-only (no credentials, no writes). Fetches all published events via the
public REST API (/wp-json/wp/v2/event, paginated) plus the
conexao_county / conexao_town / conexao_category term lists, and prints:

  - total published events
  - events by county
  - events with / without county
  - events with / without town (city)
  - events with county but no town
  - events with county + town
  - distinct county / town values (with slugs)
  - towns associated with more than one county
  - orphan terms (count == 0) for county / town
  - category coverage

Usage:
  python3 scripts/diagnostics/event-location-coverage.py [BASE_URL]

Defaults to $CONEXAO_SITE_URL, else the local stack (http://localhost:8080).
There is no production default; pass an explicit URL to audit production. Used for the pre-deploy baseline and the post-deploy
re-audit (docs/events-location-filters-stage-b-report.md).
"""

import json
import sys
import urllib.parse
import urllib.request

import os

BASE = (
    sys.argv[1]
    if len(sys.argv) > 1
    else os.environ.get("CONEXAO_SITE_URL", "http://localhost:8080")
).rstrip("/")

FIELDS = "id,link,title,conexao_county,conexao_town,conexao_category"


def get_json(path, **params):
    query = ("?" + urllib.parse.urlencode(params)) if params else ""
    url = BASE + path + query
    req = urllib.request.Request(
        url,
        headers={
            "Accept": "application/json",
            "User-Agent": "conexao-stage-b-audit/1.0 (read-only GET)",
        },
    )
    with urllib.request.urlopen(req, timeout=60) as resp:
        return (
            json.load(resp),
            resp.headers.get("X-WP-Total"),
            resp.headers.get("X-WP-TotalPages"),
        )


def get_term_map(tax):
    """Resolve a taxonomy to {term_id: term_dict}. REST post responses carry
    taxonomy fields as term IDs, so terms are fetched once and mapped."""
    terms = []
    page = 1
    while True:
        chunk, _, total_pages = get_json(
            "/wp-json/wp/v2/" + tax, per_page=100, page=page, _fields="id,name,slug,count"
        )
        terms.extend(chunk)
        if page >= int(total_pages or 1) or not chunk:
            break
        page += 1
    return {t["id"]: t for t in terms}, terms



def term_name(term):
    if isinstance(term, dict):
        return term.get("name", "")
    return str(term) if term else ""


def term_slug(term):
    if isinstance(term, dict):
        return term.get("slug", "")
    return ""


def resolve(term_ids, term_map):
    """Map the REST term-ID list onto term dicts (unknown IDs kept as-is)."""
    out = []
    for tid in term_ids or []:
        if isinstance(tid, dict):
            out.append(tid)
        elif tid in term_map:
            out.append(term_map[tid])
        else:
            out.append({"id": tid, "name": f"?UNKNOWN-ID:{tid}", "slug": f"?unknown-id-{tid}"})
    return out

def main():
    print(f"AUDIT BASE: {BASE}")
    print("READ-ONLY: GET requests only\n")

    # --- All published events (paginated). ---
    events = []
    page = 1
    total = None
    while True:
        chunk, total, total_pages = get_json(
            "/wp-json/wp/v2/event", per_page=100, page=page, _fields=FIELDS
        )
        events.extend(chunk)
        if page >= int(total_pages or 1) or not chunk:
            break
        page += 1

    print(f"TOTAL PUBLISHED EVENTS (REST X-WP-Total): {total}")
    print(f"EVENTS FETCHED: {len(events)}\n")

    # --- Resolve term IDs to term dicts (single GET per taxonomy). ---
    county_map, county_terms = get_term_map("conexao_county")
    town_map, town_terms = get_term_map("conexao_town")
    cat_map, _ = get_term_map("conexao_category")
    print(f"TERMS: county={len(county_terms)} town={len(town_terms)} category={len(cat_map)}\n")

    # --- Per-event term extraction. ---
    by_county = {}
    no_county = []
    county_no_town = []
    county_and_town = 0
    town_counties = {}
    with_category = []
    distinct_counties = {}
    distinct_towns = {}
    with_town = 0

    for ev in events:
        counties = resolve(ev.get("conexao_county"), county_map)
        towns = resolve(ev.get("conexao_town"), town_map)
        cats = resolve(ev.get("conexao_category"), cat_map)
        county_names = sorted({term_name(c) for c in counties if term_name(c)})
        town_names = sorted({term_name(t) for t in towns if term_name(t)})

        for name, slug in ((term_name(c), term_slug(c)) for c in counties):
            if name:
                distinct_counties[name] = slug
        for name, slug in ((term_name(t), term_slug(t)) for t in towns):
            if name:
                distinct_towns[name] = slug

        if county_names:
            for c in county_names:
                by_county.setdefault(c, []).append(ev["id"])
        else:
            no_county.append(ev["id"])

        if town_names:
            with_town += 1
            for t in town_names:
                for c in county_names:
                    town_counties.setdefault(t, set()).add(c)

        if county_names and not town_names:
            county_no_town.append(ev["id"])
        if county_names and town_names:
            county_and_town += 1

        if cats:
            with_category.append(ev["id"])

    def ids(list_):
        return ", ".join(str(i) for i in list_) if list_ else "(none)"

    print("== Events by County ==")
    for name in sorted(by_county):
        print(f"  {name}: {len(by_county[name])}")
    if not by_county:
        print("  (none)")

    print("\n== Distinct County values ==")
    for name in sorted(distinct_counties):
        print(f"  {name} ({distinct_counties[name]})")
    if not distinct_counties:
        print("  (none)")

    print("\n== Distinct Town/City values ==")
    for name in sorted(distinct_towns):
        counties_of = sorted(town_counties.get(name, set()))
        multi = ""
        if len(counties_of) > 1:
            multi = "  [MULTI-COUNTY: " + ", ".join(counties_of) + "]"
        print(f"  {name} ({distinct_towns[name]}){multi}")
    if not distinct_towns:
        print("  (none)")

    total_n = len(events) or 1
    with_county = len(events) - len(no_county)
    print("\n== Coverage ==")
    print(f"  with county:        {with_county}/{len(events)} ({100 * with_county / total_n:.1f}%)")
    print(f"  without county:     {len(no_county)} -> IDs: {ids(no_county)}")
    print(f"  with town:          {with_town}/{len(events)} ({100 * with_town / total_n:.1f}%)")
    print(f"  county but no town: {len(county_no_town)} -> IDs: {ids(county_no_town)}")
    print(f"  county + town:      {county_and_town}")
    print(f"  with category:      {len(with_category)} -> IDs: {ids(with_category)}")

    print("\n== Orphan terms (count == 0) ==")
    for tax, term_list in (("conexao_county", county_terms), ("conexao_town", town_terms)):
        orphans = [t for t in term_list if int(t.get("count", 0)) == 0]
        label = ", ".join(t["name"] for t in orphans) if orphans else ""
        print(f"  {tax}: {len(orphans)} orphan(s)" + (": " + label if label else ""))

    print("\nAUDIT COMPLETE (read-only; no writes performed)")


if __name__ == "__main__":
    main()

