#!/usr/bin/env python3
"""Reconcile the owner's twelfth research batch (Tipperary + Waterford).

Input : /tmp/manifest-batch11.json
Output: /tmp/manifest-batch12.json

All eight rows are accepted. The owner's only true choice (The Vee viewpoint vs
the Bay Lough trailhead) is resolved in favour of The Vee viewpoint car park,
as the owner recommends and the listing title leads with "The Vee"; the Bay
Lough Loop official trailhead is retained in the notes.

The owner also explicitly rejected the pipeline's weak points for three rows:
  * Ormond Castle  - the OSM guidepost is a sign, not the castle.
  * Glen of Aherlow - the OSM viewpoint is not the Nature Park access.
  * Swiss Cottage   - the OSM attraction polygon centre is not an entrance.
  * House of Waterford Crystal - the visitor GPS/main entrance is preferred
    over the OSM attraction geometry.
"""

from __future__ import annotations

import json
import sys

BATCH12 = [
    # ---- Tipperary ----
    ([10823], 52.357305, -7.923365, "POPULATE", "owner: Swiss Cottage - Buildings of Ireland Irish Grid 205271, 122810 converted to WGS84 (the cottage/site point, not a separately verified car-park pin); OPW confirms parking and access via the R670 only", "cottage / site point (not the OSM attraction polygon centre)"),
    ([10825], 52.429250, -8.167670, "POPULATE", "owner: Glen of Aherlow - Sport Ireland publishes Christ the King as the Millennium Stone Loop start with these coordinates; Discover Ireland confirms the Nature Park entrance is near the statue and adjacent to parking. This is the selected visitor access point, NOT the pipeline's OSM viewpoint", "Christ the King trail start / Nature Park access + parking"),
    ([10827], 52.242200, -7.956810, "POPULATE", "owner decision: The Vee & Bay Lough - The Vee viewpoint car park is the primary point (the listing title leads with 'The Vee'; Geograph identifies this as The Vee car park/viewpoint). NOTE: the Bay Lough Loop / Killballyboy Wood official Sport Ireland trailhead (grid S 01970 12339) is 52.263080, -7.971860 - a genuinely distinct destination, retained here so the choice can be reversed in one line", "The Vee viewpoint parking (owner's choice); Bay Lough trailhead recorded in notes"),
    ([10829], 52.345280, -7.407136, "POPULATE", "owner: Ormond Castle - geotagged evidence plus OSM building/attraction and archaeological coordinates around 52.345407, -7.407065; OPW confirms the visitor address is Castle Park, off Castle Street. This is the castle, NOT the pipeline's OSM guidepost (a sign)", "the castle (not the guidepost)"),
    # ---- Waterford ----
    ([10783], 52.259326, -7.106593, "POPULATE", "owner: House of Waterford Crystal - published visitor GPS matching The Mall location; the operator confirms the main entrance on The Mall and parking at Bolton Street car park behind the factory. Preferred over the OSM attraction geometry (52.25912, -7.10685)", "visitor GPS / main front entrance on The Mall"),
    ([10795], 52.138240, -7.933187, "POPULATE", "owner: Lismore Castle Gardens - exact published UCC coordinate (garden/site location). The garden operator confirms visitors park in the town car park and walk ~150 m to the entrance, so this is the garden, not the car park", "garden / site location (parking is ~150 m away in the town car park)"),
    ([10799], 52.239230, -7.502340, "POPULATE", "owner: Coumshingaun Loop - exact mapped Coumshingaun Lake Car Park / OSM parking feature; hiking sources confirm Kilclooney Wood car park on the R676 is the standard trailhead. This RESOLVES the earlier uncertainty over whether the candidate was a car park or a lake-centre point", "named lake car park / standard hiking trailhead"),
    ([20940], 52.088981, -7.616245, "POPULATE", "owner: Dungarvan Castle - archaeological report coordinate for the castle/approach; OPW confirms Castle Street, X35 DV58 at the eastern end of Davitt's Quay/Castle Street. The OSM castle geometry (52.089360, -7.616380) agrees on the same Castle Street area; the earlier 52.088500, -7.621500 suggestion was too far west", "castle / approach on Castle Street (replaces the offset candidate)"),
]


def main() -> int:
    manifest = json.load(open("/tmp/manifest-batch11.json", encoding="utf-8"))
    by_id = {int(e["id"]): e for e in manifest}

    stats = {"populate": 0, "review": 0}

    for ids, lat, lng, decision, source, assessment in BATCH12:
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

    json.dump(manifest, open("/tmp/manifest-batch12.json", "w", encoding="utf-8"),
              ensure_ascii=False, indent=1)

    from collections import Counter
    print("actions:", Counter(e.get("action", "?") for e in manifest))
    pop = [e for e in manifest if e.get("action") == "POPULATE"]
    print("POPULATE entries:", len(pop), "unique posts:", len({e["id"] for e in pop}))
    print("batch12:", stats)
    return 0


if __name__ == "__main__":
    sys.exit(main())
