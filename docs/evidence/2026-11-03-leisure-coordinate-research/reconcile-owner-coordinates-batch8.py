#!/usr/bin/env python3
"""Reconcile the owner's eighth research batch (Dublin + Wexford).

Input : /tmp/manifest-batch7.json
Output: /tmp/manifest-batch8.json

The owner resolved both open decisions in this batch, so both become POPULATE:

  * Aras an Uachtarain -> "use tour visitor centre" -> the Phoenix Park Visitor
    Centre point (tours are ticketed there; it is the same point already stored
    for the Phoenix Park Visitor Centre record 20919, which is correct).
  * Curracloe Beach     -> "accept White Gap as the representative entrance".

  * Saltee Islands      -> the Kilmore Quay visitor ferry departure point,
    consistent with the island convention already applied to Aran Islands,
    Skellig Michael, Clare Island and Garnish Island.

Kept at REVIEW: Royal Hospital Kilmainham (visitor gate not pinned) and the
Phoenix Park EN sibling (115357), which is not a separate attraction.
"""

from __future__ import annotations

import json
import sys

# Phoenix Park Visitor Centre - the ticketing/meeting point for Aras an Uachtarain tours.
PHOENIX_PARK_VC = (53.366050, -6.330930)

BATCH8 = [
    # ---- Dublin ----
    ([20909], 53.350325, -6.286782, "POPULATE", "owner: Arbour Hill Cemetery GPS pin (tourism guidance gives the Arbour Hill address; the pin sits at the cemetery, not Collins Barracks)", "cemetery location"),
    ([10547], 53.294330, -6.128570, "POPULATE", "owner: Dun Laoghaire East Pier mapped feature - a point near the pier's inland end, not the lighthouse at the far end", "pier access point (the East Pier is a long walking route)"),
    ([20915], 53.360360, -6.305700, "POPULATE", "owner: Grangegorman Military Cemetery site coordinate; Heritage Ireland confirms the Blackhorse Avenue visitor address", "cemetery site"),
    ([20926], PHOENIX_PARK_VC[0], PHOENIX_PARK_VC[1], "POPULATE", "owner decision: Aras an Uachtarain tours are ticketed at the Phoenix Park Visitor Centre, so the stored point is the tour meeting/visitor centre", "tour visitor centre (Phoenix Park Visitor Centre) - the owner chose this over the residence gate"),
    ([20922], 53.343460, -6.302550, "REVIEW", "owner: Royal Hospital Kilmainham mapped point on the overall grounds; OPW gives Military Road and a pedestrian east gate", "candidate - the visitor gate is not precisely pinned"),
    # ---- Wexford ----
    ([20936], 52.245850, -6.967226, "POPULATE", "owner: Ballyhack Castle - National Monuments Service excavation documentation coordinate for the castle itself; Heritage Ireland confirms the village location", "castle itself"),
    ([10811], 52.388350, -6.365580, "POPULATE", "owner decision: Curracloe Beach - the White Gap car park accepted as the representative entrance (Wexford County Council lists three entrances: Ballinesker, White Gap and Culletons Gap, with White Gap the largest)", "White Gap car park (owner's chosen entrance)"),
    ([10815], 52.590500, -6.499200, "POPULATE", "owner: Ferns Castle - OPW SatNav coordinates published in a visitor guide; Heritage Ireland lists The Square, Ferns (the castle is currently reported closed until 2027)", "OPW SatNav / site entrance"),
    ([10809], 52.171024, -6.588825, "POPULATE", "owner: Saltee Islands - Kilmore Quay pier visitor ferry departure point (NOT Great Saltee), consistent with the island convention used for Aran Islands, Skellig Michael, Clare Island and Garnish Island", "mainland ferry departure point"),
    ([13869], 52.321047, -6.933696, "POPULATE", "owner: JFK Arboretum - UCC tree-site listing coordinate for the arboretum near New Ross", "arboretum site"),
    ([10813], 52.356250, -6.421700, "POPULATE", "owner: Wexford Wildfowl Reserve visitor-centre GPS from the reserve's own brochure (the NPWS 52.359, -6.416 is a broader reserve reference)", "visitor centre"),
]

# The English sibling is not a separate attraction.
REVIEW_ONLY = {
    115357: "EN sibling of the Phoenix Park record (10150): it is not a separate attraction and must not get an independent coordinate decision. It shares `_leisure_export_uuid` with its PT master, so the UUID-first match resolves both to 10150; repairing that duplicate identifier is a separate data change",
}


def main() -> int:
    manifest = json.load(open("/tmp/manifest-batch7.json", encoding="utf-8"))
    by_id = {int(e["id"]): e for e in manifest}

    stats = {"populate": 0, "review": 0}

    for ids, lat, lng, decision, source, assessment in BATCH8:
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

    for pid, reason in REVIEW_ONLY.items():
        entry = by_id.get(pid)
        if entry is None:
            print(f"WARNING: post {pid} not in the manifest", file=sys.stderr)
            continue
        entry["action"] = "REVIEW"
        if entry.get("confidence") == "HIGH":
            entry["confidence"] = "MEDIUM"
        entry["proposed_lat"] = None
        entry["proposed_lng"] = None
        entry["owner_assessment"] = reason
        entry["owner_source"] = "owner: not a separate attraction"
        entry["reason"] = "owner deferred - " + reason
        stats["review"] += 1

    json.dump(manifest, open("/tmp/manifest-batch8.json", "w", encoding="utf-8"),
              ensure_ascii=False, indent=1)

    from collections import Counter
    print("actions:", Counter(e.get("action", "?") for e in manifest))
    pop = [e for e in manifest if e.get("action") == "POPULATE"]
    print("POPULATE entries:", len(pop), "unique posts:", len({e["id"] for e in pop}))
    print("batch8:", stats)
    return 0


if __name__ == "__main__":
    sys.exit(main())
