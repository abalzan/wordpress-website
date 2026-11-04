#!/usr/bin/env python3
"""Reconcile the owner's eleventh research batch (Limerick + Longford + Monaghan).

Input : /tmp/manifest-batch10.json
Output: /tmp/manifest-batch11.json

Follows the owner's section-4 reconciliation table and closing recommendation:

  POPULATE  Adare, Askeaton Castle, Curraghchase Forest Park,
            St Mary's Cathedral, Granard Motte, Royal Canal Greenway,
            Sliabh Beagh
  REVIEW    Ardagh Heritage Village, Newcastle Wood,
            Carrickmacross Lace Gallery, Lough Muckno,
            Monaghan County Museum (Peace Campus approximate pin)

Granard Motte's condition ("accept if the listing represents the Motte") is
satisfied: the record is titled "Granard Motte & Bailey" and categorised as
Historia, i.e. the monument itself.
"""

from __future__ import annotations

import json
import sys

BATCH11 = [
    # ---- Limerick ----
    ([10841], 52.564560, -8.789674, "POPULATE", "owner: Adare - Adare Heritage Centre visitor point with free parking behind the centre (published tourism GPS 52.5647, -8.7900 is nearby); the centre is the practical village visitor hub", "village visitor hub / Heritage Centre"),
    ([20930], 52.600449, -8.973985, "POPULATE", "owner: Askeaton Castle - archaeological records for the castle entrance/approach; OPW reports the castle closed with external viewing only, but the coordinate locates the site", "castle entrance / approach"),
    ([10847], 52.592520, -8.867855, "POPULATE", "owner: Curraghchase Forest Park - Sport Ireland identifies this as the trail start and car park (grid R 412 495); UCC's separate site coordinate is ~500 m away and is likely a general park point. This RESOLVES the earlier ~2.5 km discrepancy", "trail start / car park (Sport Ireland)"),
    ([10839], 52.668600, -8.623300, "POPULATE", "owner: St Mary's Cathedral - the existing candidate confirmed by Limerick's visitor guide as the cathedral SatNav coordinate", "cathedral SatNav location"),
    # ---- Longford ----
    ([10658], 53.775370, -7.500610, "POPULATE", "owner: Granard Motte and Bailey - map point for the monument itself; OPW confirms the monument is on the hilltop overlooking Granard. The owner's condition (accept if the listing represents the Motte) is satisfied: the record is titled 'Granard Motte & Bailey' and categorised Historia. NOTE: the Knights & Conquests visitor centre is a distinct location at the foot of the Motte", "the Motte itself (monument), not the visitor centre"),
    ([10660], 53.722520, -7.797660, "POPULATE", "owner: Royal Canal Greenway - Greenways Ireland lists this as the Longford main trailhead and confirms parking at the five main trailheads; preferable to an arbitrary point along the linear greenway", "official Longford trailhead"),
    ([10656], 53.664300, -7.678700, "REVIEW", "owner: Ardagh Heritage Village - candidate near the Heritage Centre/village from published local GPS and village context", "candidate - the source is the surrounding village, not a verified Heritage Centre entrance"),
    ([10662], 53.566700, -7.766700, "REVIEW", "owner: Newcastle Wood - UCC woodland site location (a broad/general point); Coillte confirms the Inny Bridge car park is the trail access but no exact car-park pin is published", "candidate - general woodland reference, not the Inny Bridge car park"),
    # ---- Monaghan ----
    ([10712], 54.323730, -7.101280, "POPULATE", "owner: Sliabh Beagh - Peatland Passport confirms this as the Sliabh Beagh/Braggan GPS point off the L1000 northwest of Monaghan Town; a trail/visitor access point, not a summit", "trail / visitor access point (Braggan)"),
    ([10708], 53.978000, -6.719000, "REVIEW", "owner: Carrickmacross Lace Gallery - Market Square address A81 K300; this is a reference pin for the gallery area", "candidate - not independently verified against the exact building entrance"),
    ([13868], 54.119120, -6.724540, "REVIEW", "owner: Lough Muckno - Hope Castle car park candidate, a visitor access point to the park/lake (geotagged evidence places the car park in this area); Lough Muckno is large so the chosen access point matters. This REPLACES the earlier approximate park pin 54.118, -6.73", "candidate - Hope Castle car park; confirm the intended park entrance"),
    ([10706], 54.247800, -6.968000, "REVIEW", "owner: Monaghan County Museum - moved to the Peace Campus (Plantation Road) in 2024; official pages confirm the address, on-site underground parking and the museum's location in the campus. This numeric pin is approximate map/geocode placement, NOT published official GPS, and the OLD HILL STREET LOCATION MUST NOT BE USED", "candidate - Peace Campus approximate pin"),
]


def main() -> int:
    manifest = json.load(open("/tmp/manifest-batch10.json", encoding="utf-8"))
    by_id = {int(e["id"]): e for e in manifest}

    stats = {"populate": 0, "review": 0}

    for ids, lat, lng, decision, source, assessment in BATCH11:
        for pid in ids:
            entry = by_id.get(pid)
            if entry is None:
                print(f"WARNING: post {pid} not in the manifest", file=sys.stderr)
                continue
            entry["owner_source"] = source
            entry["owner_assessment"] = assessment
            entry["candidate_lat"] = lat
            entry["candidate_lng"] = lng

            if decision == "POPULATE":
                entry["action"] = "POPULATE"
                entry["confidence"] = "HIGH"
                entry["proposed_lat"] = lat
                entry["proposed_lng"] = lng
                entry["reason"] = f"owner-supplied access point - {assessment}"
                stats["populate"] += 1
            else:
                entry["action"] = "REVIEW"
                if entry.get("confidence") in (None, "HIGH"):
                    entry["confidence"] = "MEDIUM"
                entry["proposed_lat"] = None
                entry["proposed_lng"] = None
                entry["reason"] = f"owner-supplied candidate - {assessment}"
                stats["review"] += 1

    json.dump(manifest, open("/tmp/manifest-batch11.json", "w", encoding="utf-8"),
              ensure_ascii=False, indent=1)

    from collections import Counter
    print("actions:", Counter(e.get("action", "?") for e in manifest))
    pop = [e for e in manifest if e.get("action") == "POPULATE"]
    print("POPULATE entries:", len(pop), "unique posts:", len({e["id"] for e in pop}))
    print("batch11:", stats)
    return 0


if __name__ == "__main__":
    sys.exit(main())
