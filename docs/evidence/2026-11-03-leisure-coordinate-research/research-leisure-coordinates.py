#!/usr/bin/env python3
"""Coordinate research pipeline for the /lazer (leisure) attractions.

Purpose
-------
Turn the read-only inventory of published `leisure` records into a coordinate
research manifest, using TWO INDEPENDENT, citable sources per attraction:

  1. Wikidata (via the Wikidata Query Service `wikibase:mwapi` entity search:
     entity QID + English label + P625 coordinate location), and
  2. OpenStreetMap / Nominatim (established mapping service, one request per
     attraction at the documented 1 request/second rate).

The two resolutions run concurrently on separate hosts. Nothing is written to
WordPress by this tool: it reads a TSV inventory and the two public APIs and
writes a JSON cache plus a JSON manifest for human review and for the
repository's apply script.

Confidence rule
---------------
HIGH       both sources resolve the SAME named attraction, both carry a
           coordinate inside the island-of-Ireland bounding box, and they agree
           within HIGH_TOLERANCE_M.
MEDIUM     both sources found but they disagree, or the feature is inherently
           areal/linear (island / peninsula / mountain range / scenic route /
           national park / beach / greenway) so no single visitor access point
           can be proven.
LOW        only one source resolved a coordinate.
NOT_FOUND  no reliable location could be established.

Only HIGH rows are ever proposed for population.

Usage
-----
    python3 research-leisure-coordinates.py --inventory /tmp/inventory.tsv \
        --cache cache.json --out-manifest manifest.json
"""

from __future__ import annotations

import argparse
import concurrent.futures
import json
import math
import os
import re
import sys
import threading
import time
import unicodedata
import urllib.error
import urllib.parse
import urllib.request

WIKIPEDIA_API = "https://en.wikipedia.org/w/api.php"


def title_variants(name: str, county: str) -> list:
    """Plausible en-wiki article titles for an attraction, most specific first."""
    out = [name]
    if "&" in name:
        out.append(name.replace("&", "and"))
    for sep in (" & ", " and ", " – ", " - "):
        if sep in name:
            head = name.split(sep)[0].strip()
            if head:
                out.append(head)
    if name.startswith("The "):
        out.append(name[4:])
    if county:
        out.append(f"{name}, County {county}")
        out.append(f"{name} (County {county})")
    out.append(f"{name} (Ireland)")

    # Common article-title suffixes. A wrong article cannot produce a HIGH row:
    # the second source (OSM) must agree within 250 m for the pair to be HIGH.
    lowered = name.lower()
    for suffix in ("Castle", "House", "Abbey", "Gardens", "Park", "Beach",
                   "Cathedral", "Distillery", "Bridge", "Museum", "Priory",
                   "Waterfall", "Lighthouse", "Caves"):
        if suffix.lower() not in lowered:
            out.append(f"{name} {suffix}")

    seen = []
    for title in out:
        title = re.sub(r"\s+", " ", title).strip()
        if title and title not in seen:
            seen.append(title)
    return seen


def batched_wikipedia_coordinates(titles: list) -> dict:
    """Resolve many en-wiki article coordinates with a handful of requests.

    `prop=coordinates` accepts up to 50 titles per request, so the whole
    catalogue (including title variants) costs a few polite requests instead of
    one per attraction. The returned coordinate is the encyclopaedic location of
    the named article; the article URL is the citable source.
    """
    found = {}
    unique = []
    for title in titles:
        if title not in unique:
            unique.append(title)

    chunk_size = 40
    for start in range(0, len(unique), chunk_size):
        chunk = unique[start : start + chunk_size]
        data = http_json(
            WIKIPEDIA_API,
            {
                "action": "query",
                "prop": "coordinates",
                "titles": "|".join(chunk),
                "redirects": 1,
                "coprop": "type|name|dim|globe",
                "format": "json",
                "formatversion": 2,
            },
            sleep=WIKIPEDIA_SLEEP,
        )
        if not isinstance(data, dict):
            continue
        pages = ((data.get("query") or {}).get("pages")) or []
        if isinstance(pages, dict):
            pages = list(pages.values())
        for page in pages:
            coords = page.get("coordinates") or []
            if not coords:
                continue
            coord = coords[0]
            lat, lng = float(coord["lat"]), float(coord["lon"])
            if not in_ireland(lat, lng):
                continue
            title = page.get("title", "")
            entry = {
                "pageid": page.get("pageid"),
                "title": title,
                "url": "https://en.wikipedia.org/?curid=%s" % page.get("pageid"),
                "lat": lat,
                "lng": lng,
                "type": coord.get("type", ""),
            }
            found[normalise(title)] = entry
    return found

NOMINATIM_API = "https://nominatim.openstreetmap.org/search"
USER_AGENT = "conexao-br-irlanda-leisure-coordinate-research/1.0 (local dev tooling)"

HIGH_TOLERANCE_M = 250.0
WIKIPEDIA_SLEEP = 2.5
NOMINATIM_SLEEP = 1.15

# Island-of-Ireland bounding box: a coordinate outside it is not this site's
# subject matter and is rejected outright.
IRELAND_BBOX = (51.30, 55.55, -11.20, -5.30)

POI_CLASSES = {
    "tourism": {
        "museum", "attraction", "aquarium", "zoo", "theme_park", "gallery",
        "viewpoint", "artwork", "information", "picnic_site",
    },
    "historic": {
        "castle", "monastery", "ruins", "archaeological_site", "abbey",
        "church", "fort", "city_gate", "tower", "memorial", "manor",
        "wayside_cross", "monument", "building", "yes", "battlefield",
    },
    "leisure": {"park", "garden", "nature_reserve", "common", "recreation_ground"},
    "amenity": {"place_of_worship", "theatre", "arts_centre", "library"},
    "man_made": {"lighthouse", "tower", "pier", "bridge"},
    "natural": {"cave_entrance", "peak", "beach", "waterfall", "cliff", "bay", "spring", "wood"},
    "waterway": {"waterfall", "weir"},
    "landuse": {"forest", "vineyard", "orchard"},
    "place": {"island", "islet", "archipelago", "locality"},
    "building": {"yes"},
    "railway": {"station", "halt"},
    "highway": {"trailhead"},
}

# Inherently areal or linear features: a single point cannot be proven to be
# the "main public visitor access point", so they can never be HIGH.
AREAL_MARKERS = (
    "national park", "mountain", "mountains", "range", "peninsula", "ring of",
    "greenway", "road", "drive", "valley", "islands", "island",
    "lough", "lake", "bog", "fen", "wood", "forest park",
    "strand", "beach", "bay", "head", "cliffs", "waterfall", "gap of",
    "way", "trail", "walk",
)

# OSM feature types that are whole-site features rather than a discrete visitor
# POI. Their mapped point is a site centre (or a summit), not the main public
# access point, so they can never be HIGH — the task requires the visitor
# entrance / car park / trailhead for large parks and outdoor attractions.
SITE_LEVEL_TYPES = {
    "peak", "park", "nature_reserve", "common", "recreation_ground",
    "forest", "wood", "beach", "bay", "cliff", "island", "islet", "archipelago",
}

TRAILING_NOISE = re.compile(
    r"\s*\((?:sint[eé]tico|synthetic|velvet strand)\)\s*$", re.IGNORECASE
)


def normalise(value: str) -> str:
    """Lowercase, strip accents/punctuation for a tolerant name comparison."""
    value = unicodedata.normalize("NFKD", value or "")
    value = "".join(ch for ch in value if not unicodedata.combining(ch))
    value = value.replace("&", " and ")
    value = re.sub(r"\([^)]*\)", " ", value)
    value = re.sub(r"[^a-z0-9]+", " ", value.lower())
    return re.sub(r"\s+", " ", value).strip()


def clean_name(title: str) -> str:
    title = TRAILING_NOISE.sub("", title or "").strip()
    return re.sub(r"\s+", " ", title).strip()


def in_ireland(lat: float, lng: float) -> bool:
    lat_min, lat_max, lng_min, lng_max = IRELAND_BBOX
    return lat_min <= lat <= lat_max and lng_min <= lng <= lng_max


def haversine_m(lat1: float, lon1: float, lat2: float, lon2: float) -> float:
    radius = 6371000.0
    p1, p2 = math.radians(lat1), math.radians(lat2)
    dphi = math.radians(lat2 - lat1)
    dlambda = math.radians(lon2 - lon1)
    a = math.sin(dphi / 2) ** 2 + math.cos(p1) * math.cos(p2) * math.sin(dlambda / 2) ** 2
    return 2 * radius * math.asin(math.sqrt(a))


def http_json(url: str, params: dict, *, sleep: float, attempts: int = 4):
    """GET a JSON endpoint, retrying politely on rate limiting and 5xx."""
    query = urllib.parse.urlencode(params)
    request = urllib.request.Request(
        f"{url}?{query}", headers={"User-Agent": USER_AGENT, "Accept": "application/json"}
    )
    payload = None
    for attempt in range(attempts):
        try:
            with urllib.request.urlopen(request, timeout=60) as response:
                payload = json.loads(response.read().decode("utf-8"))
            break
        except urllib.error.HTTPError as exc:
            if exc.code in (429, 500, 502, 503, 504) and attempt < attempts - 1:
                backoff = sleep + 2.0 * (attempt + 1)
                print(f"    ~ HTTP {exc.code}; backing off {backoff:.1f}s", file=sys.stderr)
                time.sleep(backoff)
                continue
            print(f"    ! request failed ({url}): {exc}", file=sys.stderr)
            payload = None
            break
        except Exception as exc:  # noqa: BLE001 - network failure is a research gap
            print(f"    ! request failed ({url}): {exc}", file=sys.stderr)
            payload = None
            break
        finally:
            time.sleep(sleep)
    return payload


def wikipedia_search_coordinate(name: str, county: str):
    """One `generator=search` request: best matching article + its coordinate.

    Used only for the attractions the batched title-variant pass could not
    resolve (a differently-titled or disambiguated article).
    """
    query = f"{name} {county} Ireland" if county else f"{name} Ireland"

    data = http_json(
        WIKIPEDIA_API,
        {
            "action": "query",
            "generator": "search",
            "gsrsearch": query,
            "gsrlimit": 3,
            "prop": "coordinates",
            "coprop": "type|name",
            "format": "json",
            "formatversion": 2,
        },
        sleep=WIKIPEDIA_SLEEP,
    )
    if not isinstance(data, dict):
        return None
    pages = ((data.get("query") or {}).get("pages")) or []
    if isinstance(pages, dict):
        pages = list(pages.values())
    pages = sorted(pages, key=lambda page: page.get("index", 99))
    target = normalise(name)
    for page in pages:
        coords = page.get("coordinates") or []
        if not coords:
            continue
        title = page.get("title", "")
        if target and not (target in normalise(title) or normalise(title) in target):
            continue
        coord = coords[0]
        lat, lng = float(coord["lat"]), float(coord["lon"])
        if not in_ireland(lat, lng):
            continue
        return {
            "pageid": page.get("pageid"),
            "title": title,
            "url": "https://en.wikipedia.org/?curid=%s" % page.get("pageid"),
            "lat": lat,
            "lng": lng,
            "type": coord.get("type", ""),
        }
    return None


def wikipedia_lookup(name: str, county: str, coords: dict):
    """The first title variant of this attraction that resolved a coordinate."""
    for variant in title_variants(name, county):
        entry = coords.get(normalise(variant))
        if entry:
            return entry
    return None


def nominatim_lookup(name: str, town: str, county: str):
    """OSM lookup: a specific query first, then a broader name-only fallback."""
    attempts = []
    parts = [name]
    if town and normalise(town) != normalise(name):
        parts.append(town)
    if county:
        parts.append(county if county.lower().startswith("county") else f"County {county}")
    parts.append("Ireland")
    attempts.append(", ".join(parts))
    attempts.append(f"{name}, Ireland")
    if "&" in name:
        attempts.append(f"{name.replace('&', 'and')}, Ireland")

    for query in attempts:
        result = nominatim_query(query, name, county)
        if result:
            return result
    return None


def nominatim_query(query: str, name: str, county: str):
    data = http_json(
        NOMINATIM_API,
        {
            "q": query,
            "format": "jsonv2",
            "limit": 8,
            "addressdetails": 1,
            "extratags": 1,
            "countrycodes": "ie",
        },
        sleep=NOMINATIM_SLEEP,
    )
    if not isinstance(data, list):
        return None

    target = normalise(name)
    scored = []
    for hit in data:
        hit_name = normalise(hit.get("name") or "")
        klass = hit.get("class") or ""
        typ = hit.get("type") or ""
        poi = typ in POI_CLASSES.get(klass, set())
        name_match = bool(hit_name) and (
            hit_name == target or hit_name in target or target in hit_name
        )
        if not poi and not name_match:
            continue
        lat, lng = float(hit["lat"]), float(hit["lon"])
        if not in_ireland(lat, lng):
            continue
        address = hit.get("address", {})
        hit_county = normalise(address.get("county", ""))
        county_ok = (not county) or (normalise(county) in hit_county) or (
            hit_county in normalise(county)
        )
        score = (name_match, poi, county_ok, float(hit.get("importance") or 0))
        scored.append((score, hit, name_match, poi, county_ok, lat, lng))

    if not scored:
        return None

    scored.sort(key=lambda item: item[0], reverse=True)
    _score, hit, name_match, poi, county_ok, lat, lng = scored[0]
    return {
        "lat": lat,
        "lng": lng,
        "display_name": hit.get("display_name", ""),
        "class": hit.get("class", ""),
        "type": hit.get("type", ""),
        "osm": f"{hit.get('osm_type')}/{hit.get('osm_id')}",
        "name": hit.get("name", ""),
        "name_match": name_match,
        "poi": poi,
        "county_ok": county_ok,
    }


def is_areal(name: str, category: str, osm_type: str) -> bool:
    haystack = normalise(f"{name} {category}")
    for marker in AREAL_MARKERS:
        if marker in haystack:
            return True
    return osm_type in {"island", "islet", "archipelago"}


def title_overlap(a: str, b: str) -> float:
    """Fraction of the attraction's significant tokens present in the article title."""
    left = set(normalise(a).split())
    right = set(normalise(b).split())
    if not left:
        return 0.0
    return len(left & right) / len(left)


def assess(row: dict, wd, osm) -> dict:
    name = clean_name(row["title"])
    result = {
        "wikipedia": wd,
        "osm": osm,
        "distance_m": None,
        "confidence": "NOT_FOUND",
        "reason": "",
    }

    # A Wikipedia article that only loosely matches the attraction (e.g. the
    # town rather than the venue) cannot support a HIGH classification.
    weak_article = bool(wd) and title_overlap(name, wd.get("title", "")) < 0.5

    if wd and osm:
        result["distance_m"] = round(
            haversine_m(wd["lat"], wd["lng"], osm["lat"], osm["lng"]), 1
        )

    areal = is_areal(name, row["category"], (osm or {}).get("type", ""))
    site_level = (osm or {}).get("type", "") in SITE_LEVEL_TYPES

    if wd and osm:
        if result["distance_m"] <= HIGH_TOLERANCE_M and not areal and not weak_article and not site_level:
            result["confidence"] = "HIGH"
            result["reason"] = (
                f"Wikipedia '{wd['title']}' and OSM {osm['osm']} agree within "
                f"{result['distance_m']} m"
            )
        elif result["distance_m"] <= HIGH_TOLERANCE_M and weak_article:
            result["confidence"] = "MEDIUM"
            result["reason"] = (
                f"Wikipedia article '{wd['title']}' is a broader entity than the "
                f"attraction; sources agree within {result['distance_m']} m but the "
                "article does not pin the venue"
            )
        elif result["distance_m"] <= HIGH_TOLERANCE_M and site_level:
            result["confidence"] = "MEDIUM"
            result["reason"] = (
                f"OSM feature {osm['osm']} is a whole-site '{osm.get('type','')}' "
                f"feature; the mapped point is a site centre, not the visitor "
                f"access point (sources agree within {result['distance_m']} m)"
            )
        elif result["distance_m"] <= HIGH_TOLERANCE_M and areal:
            result["confidence"] = "MEDIUM"
            result["reason"] = (
                "Areal/linear feature: no single provable visitor access point "
                f"(sources agree within {result['distance_m']} m)"
            )
        else:
            result["confidence"] = "MEDIUM"
            result["reason"] = (
                f"Sources disagree by {result['distance_m']} m "
                f"(Wikipedia '{wd['title']}' vs OSM {osm['osm']})"
            )
    elif wd or osm:
        result["confidence"] = "LOW"
        result["reason"] = "Only one independent source resolved a coordinate"
    else:
        result["confidence"] = "NOT_FOUND"
        result["reason"] = "No source resolved a coordinate for this name"

    if wd and osm:
        result["proposed_lat"] = round((wd["lat"] + osm["lat"]) / 2, 6)
        result["proposed_lng"] = round((wd["lng"] + osm["lng"]) / 2, 6)
    elif wd:
        result["proposed_lat"] = wd["lat"]
        result["proposed_lng"] = wd["lng"]
    elif osm:
        result["proposed_lat"] = osm["lat"]
        result["proposed_lng"] = osm["lng"]
    else:
        result["proposed_lat"] = None
        result["proposed_lng"] = None

    return result


def read_inventory(path: str) -> list:
    rows = []
    with open(path, encoding="utf-8") as handle:
        for line in handle:
            parts = line.rstrip("\n").split("\t")
            if len(parts) < 15:
                continue
            rows.append(
                {
                    "id": int(parts[0]),
                    "slug": parts[1],
                    "title": parts[2],
                    "lang": parts[3],
                    "county_tax": parts[4],
                    "town_tax": parts[5],
                    "category": parts[6],
                    "meta_county": parts[7],
                    "meta_town": parts[8],
                    "address": parts[9],
                    "website": parts[10],
                    "discover_ireland": parts[11],
                    "map_url": parts[12],
                    "lat": parts[13],
                    "lng": parts[14],
                    "uuid": parts[15] if len(parts) > 15 else "",
                }
            )
    return rows


_CACHE_LOCK = threading.Lock()


def save(cache: dict, path: str) -> None:
    with _CACHE_LOCK:
        tmp = path + ".tmp"
        with open(tmp, "w", encoding="utf-8") as handle:
            json.dump(cache, handle, ensure_ascii=False, indent=1)
        os.replace(tmp, path)


def resolve_wikidata(rows: list, cache: dict, cache_path: str) -> None:
    """Resolve every attraction's Wikipedia coordinate from a few batched calls."""
    titles = []
    for row in rows:
        name = clean_name(row["title"])
        county = row["meta_county"] or row["county_tax"]
        titles.extend(title_variants(name, county))

    print(f"resolving {len(rows)} attractions from {len(set(titles))} title variants...", flush=True)
    coords = batched_wikipedia_coordinates(titles)
    print(f"  batched pass resolved {len(coords)} article coordinates", flush=True)

    total = len(rows)
    unresolved = []
    for index, row in enumerate(rows, 1):
        key = str(row["id"])
        if cache.get(key, {}).get("wikidata_resolved"):
            continue
        name = clean_name(row["title"])
        county = row["meta_county"] or row["county_tax"]
        entry = wikipedia_lookup(name, county, coords)
        if entry is None:
            unresolved.append(row)
            continue
        slot = cache.setdefault(key, {})
        slot["wikidata"] = entry
        slot["wikidata_resolved"] = True
        if index % 5 == 0 or index == total:
            save(cache, cache_path)

    if unresolved:
        # The per-attraction Wikipedia search API is throttled so hard from this
        # environment that a request can cost ~35 s; 150 of them is not viable.
        # The expanded batched title-variant pass above is the coverage lever
        # instead, and anything still unresolved is reported for owner input.
        print(f"  {len(unresolved)} attractions have no Wikipedia coordinate", flush=True)
        for row in unresolved:
            slot = cache.setdefault(str(row["id"]), {})
            slot["wikidata"] = None
            slot["wikidata_resolved"] = True
        save(cache, cache_path)

    resolved = sum(1 for row in rows if cache.get(str(row["id"]), {}).get("wikidata"))
    print(f"[wd] resolved {resolved}/{total} Wikipedia coordinates", flush=True)
    save(cache, cache_path)


def resolve_osm(rows: list, cache: dict, cache_path: str) -> None:
    total = len(rows)
    for index, row in enumerate(rows, 1):
        key = str(row["id"])
        if cache.get(key, {}).get("osm_resolved"):
            continue
        name = clean_name(row["title"])
        county = row["meta_county"] or row["county_tax"]
        town = row["meta_town"] or row["town_tax"]
        entry = nominatim_lookup(name, town, county)
        slot = cache.setdefault(key, {})
        slot["osm"] = entry
        slot["osm_resolved"] = True
        print(f"[osm {index}/{total}] {row['slug']} -> {'hit' if entry else 'miss'}", flush=True)
        if index % 5 == 0:
            save(cache, cache_path)
    save(cache, cache_path)


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--inventory", required=True)
    parser.add_argument("--cache", required=True)
    parser.add_argument("--out-manifest", required=True)
    parser.add_argument("--limit", type=int, default=0)
    args = parser.parse_args()

    cache: dict = {}
    if os.path.isfile(args.cache):
        with open(args.cache, encoding="utf-8") as handle:
            cache = json.load(handle)

    rows = read_inventory(args.inventory)
    if args.limit:
        rows = rows[: args.limit]

    with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
        f_wd = pool.submit(resolve_wikidata, rows, cache, args.cache)
        f_osm = pool.submit(resolve_osm, rows, cache, args.cache)
        f_wd.result()
        f_osm.result()

    manifest = []
    for row in rows:
        slot = cache.get(str(row["id"]), {})
        entry = dict(row)
        entry.update(
            assess(row, slot.get("wikidata"), slot.get("osm"))
        )
        manifest.append(entry)

    with open(args.out_manifest, "w", encoding="utf-8") as handle:
        json.dump(manifest, handle, ensure_ascii=False, indent=1)

    counts: dict = {}
    for entry in manifest:
        key = entry.get("confidence", "?")
        counts[key] = counts.get(key, 0) + 1
    print("confidence:", counts)
    return 0


if __name__ == "__main__":
    sys.exit(main())
