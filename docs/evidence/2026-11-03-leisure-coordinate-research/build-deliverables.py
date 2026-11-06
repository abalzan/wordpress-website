#!/usr/bin/env python3
"""Project the coordinate research manifest into the review deliverables.

Inputs : the JSON manifest produced by research-leisure-coordinates.py
Outputs:
  docs/reports/<date>-leisure-coordinate-research.csv   (the full manifest, CSV)
  docs/reports/<date>-leisure-coordinate-owner-input.md (only unresolved rows)
  scripts/data/leisure-coordinates-<date>.json          (portable import file)

This tool writes only those three files. It never touches WordPress.
"""

from __future__ import annotations

import csv
import json
import os
import re
import sys

DATE = "2026-11-03"
BASE = "http://localhost:8080"

# The only real Polylang pair in the leisure dataset (the CI fixture pair).
EN_SIBLINGS = {10150: 115357, 115357: 10150}
EN_INFO = {
    10150: {"name": "Phoenix Park (synthetic)", "url": "/en/lazer/phoenix-park-en/"},
}

# CI fixtures: published `leisure` records that are NOT real attractions.
FIXTURE_SLUGS = {
    "ph3c-test-lazer-alfa",
    "ph3c-test-lazer-sem-condado",
    "ph3c-test-lazer-zeta",
    "ph3c-test-lazer-externo",
}

FIELDS = [
    "post_id",
    "attraction_name",
    "english_name",
    "portuguese_url",
    "english_url",
    "county",
    "existing_latitude",
    "existing_longitude",
    "proposed_latitude",
    "proposed_longitude",
    "coordinate_source_1",
    "coordinate_source_2",
    "point_description",
    "confidence",
    "action",
    "candidate_coordinates",
    "notes",
]


def osm_url(osm: str) -> str:
    if not osm or "/" not in osm:
        return ""
    kind, ident = osm.split("/", 1)
    kind = kind.replace("node", "node").replace("way", "way").replace("relation", "relation")
    return f"https://www.openstreetmap.org/{kind}/{ident}"


def action_for(confidence: str) -> str:
    if confidence == "HIGH":
        return "POPULATE"
    if confidence in ("MEDIUM", "LOW"):
        return "REVIEW"
    return "OWNER_INPUT_REQUIRED"


def point_description(entry: dict) -> str:
    wd = entry.get("wikipedia") or {}
    osm = entry.get("osm") or {}
    bits = []
    if wd:
        bits.append(f"Wikipedia article '{wd.get('title','')}' coordinate")
    if osm:
        cls = osm.get("class", "")
        typ = osm.get("type", "")
        bits.append(f"OSM {osm.get('osm','')} ({cls}={typ})")
    return "; ".join(bits)


def row_from(entry: dict) -> dict:
    pid = entry["id"]
    name = entry["title"]
    name = re.sub(r"\s*\((?:sint[eé]tico|synthetic)\)\s*$", "", name, flags=re.I).strip()
    slug = entry["slug"]
    fixture = slug in FIXTURE_SLUGS

    en_id = EN_SIBLINGS.get(pid)
    english_name = ""
    english_url = ""
    if en_id and en_id in EN_INFO:
        english_name = EN_INFO[en_id]["name"]
        english_url = EN_INFO[en_id]["url"]

    wd = entry.get("wikipedia") or {}
    osm = entry.get("osm") or {}
    confidence = entry.get("confidence", "NOT_FOUND")
    # An explicit action (owner-supplied research) wins over the derived one.
    action = entry.get("action") or action_for(confidence)

    notes = entry.get("reason", "")
    if entry.get("owner_source"):
        notes = f"{entry['owner_source']} | {notes}"
    if fixture:
        action = "REVIEW"
        notes = "CI test fixture, not a real attraction: " + notes
    if entry.get("lat") or entry.get("lng"):
        notes += " | existing coordinates present"

    candidate = ""
    if action != "POPULATE" and entry.get("candidate_lat") is not None:
        candidate = f"{entry['candidate_lat']}, {entry['candidate_lng']}"
    # A REVIEW row with no candidate coordinate AND no independent source point
    # has nothing to review: it needs owner input outright.
    if action == "REVIEW" and not candidate and not wd and not osm:
        action = "OWNER_INPUT_REQUIRED"

    source_1 = entry.get("source_1") or wd.get("url", "")
    source_2 = entry.get("source_2") or osm_url(osm.get("osm", ""))
    if entry.get("owner_source"):
        source_1 = entry["owner_source"]

    return {
        "post_id": pid,
        "attraction_name": name,
        "english_name": english_name,
        "portuguese_url": f"/lazer/{slug}/" if not fixture else "",
        "english_url": english_url,
        "county": entry.get("meta_county") or entry.get("county_tax") or "",
        "existing_latitude": entry.get("lat") or "",
        "existing_longitude": entry.get("lng") or "",
        "proposed_latitude": entry.get("proposed_lat") if action == "POPULATE" else "",
        "proposed_longitude": entry.get("proposed_lng") if action == "POPULATE" else "",
        "coordinate_source_1": source_1,
        "coordinate_source_2": source_2,
        "point_description": (entry.get("owner_assessment") or point_description(entry)),
        "confidence": confidence,
        "action": action,
        "notes": notes,
        "candidate_coordinates": candidate,
        "_distance_m": entry.get("distance_m"),
        "_fixture": fixture,
        "_slug": slug,
        "_uuid": entry.get("uuid", ""),
        "_wikidata": wd,
        "_osm": osm,
        "_title_raw": entry["title"],
        "_owner_source": entry.get("owner_source", ""),
        "_owner_assessment": entry.get("owner_assessment", ""),
        "_owner_nearest_m": entry.get("owner_nearest_source_m"),
        "_owner_approved": bool(entry.get("owner_approved")),
        "_lang": entry.get("lang", ""),
    }


def main() -> int:
    manifest_path = sys.argv[1]
    repo = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", "..", ".."))
    with open(manifest_path, encoding="utf-8") as handle:
        manifest = json.load(handle)

    rows = [row_from(entry) for entry in manifest]

    # --- CSV -------------------------------------------------------------
    csv_path = os.path.join(repo, "docs", "reports", f"{DATE}-leisure-coordinate-research.csv")
    with open(csv_path, "w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=FIELDS, extrasaction="ignore")
        writer.writeheader()
        for row in rows:
            writer.writerow(row)

    # --- portable JSON import file ---------------------------------------
    portable = []
    for row in rows:
        portable.append(
            {
                "post_id": row["post_id"],
                "uuid": row["_uuid"],
                "slug": row["_slug"],
                "title": row["_title_raw"],
                "county": row["county"],
                "existing_latitude": row["existing_latitude"],
                "existing_longitude": row["existing_longitude"],
                "proposed_latitude": row["proposed_latitude"],
                "proposed_longitude": row["proposed_longitude"],
                "confidence": row["confidence"],
                "action": row["action"],
                "owner_approved": row["_owner_approved"],
                "lang": row["_lang"],
                "candidate_coordinates": row["candidate_coordinates"],
                "owner_source": row["_owner_source"],
                "owner_assessment": row["_owner_assessment"],
                "owner_nearest_source_m": row["_owner_nearest_m"],
                "distance_m": row["_distance_m"],
                "wikipedia": row["_wikidata"],
                "osm": row["_osm"],
                "notes": row["notes"],
            }
        )
    json_path = os.path.join(repo, "scripts", "data", f"leisure-coordinates-{DATE}.json")
    with open(json_path, "w", encoding="utf-8") as handle:
        json.dump(portable, handle, ensure_ascii=False, indent=1)

    counts = {}
    for row in rows:
        counts[row["action"]] = counts.get(row["action"], 0) + 1

    # --- owner-input list -------------------------------------------------
    unresolved = [row for row in rows if row["action"] != "POPULATE"]
    unresolved.sort(key=lambda row: (row["county"], row["attraction_name"].lower()))
    md_path = os.path.join(repo, "docs", "reports", f"{DATE}-leisure-coordinate-owner-input.md")
    lines = [
        f"# Missing / unconfirmed leisure coordinates — owner input required ({DATE})",
        "",
        "Every published `/lazer` attraction whose coordinates could NOT be",
        "established with HIGH confidence. Nothing in this file was populated.",
        "",
        "`REVIEW` means a candidate location was found but the precise visitor",
        "access point is not proven (both sources agree but the feature is areal,",
        "or the two sources disagree, or only one source resolved a location).",
        "`OWNER_INPUT_REQUIRED` means no usable location was found at all.",
        "",
        f"Total rows: **{len(unresolved)}**.",
        "",
        "| # | Attraction | County | Post ID | Existing coordinates | Candidate coordinates | Confidence | Action | Portuguese URL | Sources investigated | Reason | Information needed |",
        "|---|---|---|---|---|---|---|---|---|---|---|---|",
    ]
    for index, row in enumerate(unresolved, 1):
        if row["_owner_source"]:
            src = row["_owner_source"]
        else:
            sources = []
            if row["coordinate_source_1"]:
                sources.append(f"[Wikipedia]({row['coordinate_source_1']})")
            if row["coordinate_source_2"]:
                sources.append(f"[OSM]({row['coordinate_source_2']})")
            src = ", ".join(sources) if sources else "Wikipedia article-title search + OSM Nominatim search (no match)"
        existing = "None"
        if row["existing_latitude"] or row["existing_longitude"]:
            existing = f"{row['existing_latitude']}, {row['existing_longitude']}"
        need = "GPS coordinates or a Google Maps / OSM pin for the main visitor entrance"
        if row["action"] == "REVIEW" and row["candidate_coordinates"]:
            need = "Confirm the candidate point (or supply the correct visitor access point)"
        elif row["confidence"] == "MEDIUM":
            need = "Confirm the preferred visitor access point (which entrance/car park)"
        elif row["confidence"] == "LOW":
            need = "Confirm the location: only one source resolved it"
        lines.append(
            "| {n} | {name} | {county} | {pid} | {existing} | {cand} | {conf} | {action} | `{url}` | {src} | {reason} | {need} |".format(
                n=index,
                name=row["attraction_name"],
                county=row["county"] or "—",
                pid=row["post_id"],
                existing=existing,
                cand=row["candidate_coordinates"] or "—",
                conf=row["confidence"],
                action=row["action"],
                url=row["portuguese_url"] or "—",
                src=src,
                reason=(row["notes"] or "").replace("|", "/"),
                need=need,
            )
        )
    with open(md_path, "w", encoding="utf-8") as handle:
        handle.write("\n".join(lines) + "\n")

    print("wrote", csv_path)
    print("wrote", json_path)
    print("wrote", md_path)
    print("actions:", counts)
    return 0


if __name__ == "__main__":
    sys.exit(main())
