#!/usr/bin/env python3
"""Reconcile the owner's fourth research batch into the manifest.

Input : /tmp/manifest-final.json  (the manifest after batch 3)
Output: /tmp/manifest-batch4.json

Decision rules (owner assessment first, repository verification second):

  POPULATE    section 1 - clear, sourced visitor locations; plus the rows in
              section 4 where the owner's recommended point IS the supplied
              candidate coordinate (a decision + a coordinate = actionable).
  CORROBORATE section 3 rows with no uncertainty marker: promoted only when an
              independent source agrees within 250 m.
  REVIEW      section 2 (owner: keep REVIEW), section 3 rows with an explicit
              caveat, and section 4 rows whose recommendation requires a
              DIFFERENT point that has not been supplied (Dun Aonghasa's visitor
              centre, Strandhill's main car park).
"""

from __future__ import annotations

import json
import math
import sys

TOLERANCE_M = 250.0


def hav(a, b, c, d):
    r = 6371000.0
    p1, p2 = math.radians(a), math.radians(c)
    dp, dl = math.radians(c - a), math.radians(d - b)
    x = math.sin(dp / 2) ** 2 + math.cos(p1) * math.cos(p2) * math.sin(dl / 2) ** 2
    return 2 * r * math.asin(math.sqrt(x))


BATCH4 = [
    # ---- section 1: strong new candidates ----
    ([20945], 52.954950, -7.798050, "POPULATE", "owner: Roscrea Castle and visitor site (closely matching mapped + tourism coordinates)", "castle and visitor site"),
    ([10181], 53.041900, -9.345000, "POPULATE", "owner: Doolin Cave official visitor centre (operator GPS, independently corroborated)", "official visitor centre"),
    ([10579], 53.092790, -8.837889, "CORROBORATE", "owner: Coole Park visitor-centre area (independently corroborated by UCC)", "visitor-centre area"),
    ([10746], 53.778110, -9.639078, "POPULATE", "owner: Croagh Patrick main trailhead car park (Sport Ireland identifies this as the car park at the mountain's base)", "trailhead car park - replaces the earlier summit-derived point"),
    ([10845], 52.523106, -8.520380, "POPULATE", "owner: Lough Gur Visitor Centre and lakeshore parking (published visitor-centre GPS)", "visitor centre + parking"),
    ([10613], 51.868605, -10.385583, "POPULATE", "owner: Kerry Cliffs visitor attraction and parking (parking ~200 m before the viewpoints)", "visitor attraction + parking"),
    ([13867], 51.620070, -8.542630, "POPULATE", "owner: Lusitania Museum and Old Head Signal Tower - the actual public visitor attraction", "museum + signal tower"),
    ([10587], 52.635030, -9.494800, "POPULATE", "owner: Kilrush Marina ferry departure point (Scattery Island)", "mainland ferry departure point"),
    ([10859], 53.340049, -6.745716, "POPULATE", "owner: Donadea Forest Park car park", "forest car park"),
    ([10849], 53.144200, -6.902900, "POPULATE", "owner: Irish National Stud & Japanese Gardens visitor-attraction entrance", "visitor entrance"),
    ([10183], 52.934300, -9.348600, "POPULATE", "owner: Lahinch Beach main beach parking/access area", "main beach access"),
    ([10166], 53.709800, -6.561430, "POPULATE", "owner: Slane Castle visitor location", "castle visitor location"),
    ([20934], 53.418755, -9.275722, "POPULATE", "owner: Aughnanure Castle visitor location", "castle visitor location"),
    ([20932], 52.450434, -9.061211, "POPULATE", "owner: Desmond Castle Newcastlewest castle/banqueting hall", "castle visitor location"),
    # ---- section 4: the owner's recommended point IS the supplied candidate ----
    ([13866], 51.607770, -10.158310, "POPULATE", "owner: Dursey Island mainland cable-car station (owner's recommended destination)", "mainland cable-car station - the owner's chosen point"),
    ([10611], 51.925480, -10.286100, "POPULATE", "owner: Valentia Island Knightstown ferry terminal (owner's recommended point)", "island ferry terminal"),
    ([10664], 53.467190, -7.987190, "POPULATE", "owner: Lough Ree - Hodson Bay Marina (owner's recommended access point)", "marina access point"),
    ([10785], 52.259900, -7.106900, "POPULATE", "owner: Waterford Treasures - the Medieval Museum, the owner's recommended representative museum", "medieval museum (umbrella attraction)"),
    ([10196], 52.005938, -9.506360, "POPULATE", "owner: Torc Waterfall lower car park on the N71 (owner's recommended point)", "lower car park"),
    # ---- section 3 rows with no uncertainty marker: tie-break on corroboration ----
    ([10172], 53.500679, -10.096485, "CORROBORATE", "owner: Sky Road main visitor viewpoint / car park", "roadside car park / viewpoint"),
    ([10573], 53.269790, -9.054050, "CORROBORATE", "owner: Spanish Arch (Visit Galway)", "Spanish Arch reference point"),
    ([13871], 52.649560, -7.249910, "CORROBORATE", "owner: National Design & Craft Gallery, Castle Yard, opposite Kilkenny Castle", "mapped gallery location confirmed by the official gallery"),
    # ---- section 2: owner says keep at REVIEW ----
    ([20905], 52.194620, -8.465960, "REVIEW", "owner: Annes Grove Gardens GPS track (OPW confirms the address, visitor access and onsite parking)", "candidate - exact entrance needs confirmation"),
    ([10708], 53.978000, -6.719000, "REVIEW", "owner: Carrickmacross Lace Gallery reference map point (official address)", "candidate - exact gallery entrance needs confirmation"),
    ([20948], 52.964252, -6.568022, "REVIEW", "owner: Dwyer McAllister Cottage (Visit Wicklow)", "candidate - conflicts with another published coordinate"),
    ([10732], 54.270400, -8.602750, "REVIEW", "owner: Strandhill Beach (National Surf Centre coordinates)", "candidate - the main beach car park is not yet established"),
    # ---- section 3 rows with an explicit caveat ----
    ([10855], 53.183591, -6.845753, "REVIEW", "owner: Pollardstown Fen (NPWS published coordinate)", "candidate - identifies the reserve, not necessarily the best visitor access point"),
    ([10174], 53.125461, -9.766763, "REVIEW", "owner: Dun Aonghasa fort itself", "candidate - Heritage Ireland puts the visitor centre ~1 km away; use the visitor centre once confirmed"),
    ([20941], 52.536800, -7.268000, "REVIEW", "owner: Kells Priory car park candidate on the L1023 (Heritage Ireland confirms access is through the car park)", "candidate - the exact pin should be checked before importing"),
]


def main() -> int:
    manifest = json.load(open("/tmp/manifest-final.json", encoding="utf-8"))
    by_id = {int(e["id"]): e for e in manifest}

    stats = {"populate": 0, "corroborated": 0, "review": 0, "not_corroborated": 0}

    for ids, lat, lng, decision, source, assessment in BATCH4:
        for pid in ids:
            entry = by_id.get(pid)
            if entry is None:
                print(f"WARNING: post {pid} not in the manifest", file=sys.stderr)
                continue

            dists = []
            for key in ("wikipedia", "osm"):
                src = entry.get(key) or {}
                if src.get("lat") is not None:
                    dists.append(hav(lat, lng, src["lat"], src["lng"]))
            nearest = round(min(dists), 1) if dists else None

            final = decision
            note = ""
            if decision == "CORROBORATE":
                if nearest is not None and nearest <= TOLERANCE_M:
                    final = "POPULATE"
                    note = f"; corroborated by an independent source within {nearest} m"
                    stats["corroborated"] += 1
                else:
                    final = "REVIEW"
                    note = "; not corroborated (nearest independent source " + (
                        f"{nearest} m away" if nearest is not None else "not found") + ")"
                    stats["not_corroborated"] += 1

            entry["owner_source"] = source
            entry["owner_assessment"] = assessment
            entry["owner_nearest_source_m"] = nearest
            entry["candidate_lat"] = lat
            entry["candidate_lng"] = lng

            if final == "POPULATE":
                entry["action"] = "POPULATE"
                entry["confidence"] = "HIGH"
                entry["proposed_lat"] = lat
                entry["proposed_lng"] = lng
                entry["reason"] = f"owner-supplied access point - {assessment}{note}"
                stats["populate"] += 1
            else:
                entry["action"] = "REVIEW"
                if entry.get("confidence") in (None, "HIGH"):
                    entry["confidence"] = "MEDIUM"
                entry["proposed_lat"] = None
                entry["proposed_lng"] = None
                entry["reason"] = f"owner-supplied candidate - {assessment}{note}"
                stats["review"] += 1

    json.dump(manifest, open("/tmp/manifest-batch4.json", "w", encoding="utf-8"),
              ensure_ascii=False, indent=1)

    from collections import Counter
    print("actions:", Counter(e.get("action", "?") for e in manifest))
    pop = [e for e in manifest if e.get("action") == "POPULATE"]
    print("POPULATE entries:", len(pop), "unique posts:", len({e["id"] for e in pop}))
    print("batch4:", stats)
    return 0


if __name__ == "__main__":
    sys.exit(main())
