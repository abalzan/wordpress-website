#!/usr/bin/env python3
"""Reconcile the owner's seventh research batch (Donegal + Monaghan).

Input : /tmp/manifest-batch6.json
Output: /tmp/manifest-batch7.json

Follows the owner's section-3 reconciliation summary exactly:

  POPULATE  Donegal  : Errigal (trailhead car park), Sliabh Liag (VISITOR CENTRE,
                       not the cliff summit)
            Monaghan : Castle Leslie Estate (estate SatNav), Patrick Kavanagh
                       Centre (centre/visitor entrance)
  REVIEW    Doagh Famine Village, Malin Head, Newmills Corn & Flax Mills,
            Carrickmacross Lace Gallery, Lough Muckno, Sliabh Beagh,
            Monaghan County Museum (moved to the Peace Campus; no pin yet)
"""

from __future__ import annotations

import json
import sys

BATCH7 = [
    # ---- Donegal ----
    ([10726], 55.025047, -8.089997, "POPULATE", "owner: Errigal dedicated R251 trailhead car park (Hiking Donegal publishes these coordinates for the trailhead parking)", "trailhead car park - not the summit"),
    ([10716], 54.635600, -8.645300, "POPULATE", "owner: Sliabh Liag VISITOR CENTRE (operator Eircode F94 W8KC publishes 54.6356 N, 8.6453 W; the centre has parking and shuttle access to the cliffs)", "visitor centre - not the cliff summit; this supersedes the earlier conflicting-page question"),
    ([13865], 55.309020, -7.335630, "REVIEW", "owner: Doagh Famine Village (OSM attraction point, close to another published GPS; operator Eircode F93 PK19 with on-site parking)", "candidate - check against the visitor entrance/parking pin"),
    ([10720], 55.380948, -7.373576, "REVIEW", "owner: Malin Head - Banba's Crown viewpoint/parking candidate (Discover Ireland identifies Banba's Crown as a major visitor point)", "candidate - Malin Head covers a larger area with several access points; confirm the intended one"),
    ([20939], 54.929070, -7.808440, "REVIEW", "owner: Newmills Corn & Flax Mills current candidate (Heritage Ireland confirms the OPW site and parking); an archaeological record gives 54.929234, -7.806959 ~100 m away", "candidate - the visitor entrance/parking should take precedence over a generic site point"),
    # ---- Monaghan ----
    ([10702], 54.318210, -6.895820, "POPULATE", "owner: Castle Leslie Estate SatNav coordinate from the estate's own directions page", "estate visitor entrance"),
    ([10710], 54.002924, -6.580940, "POPULATE", "owner: Patrick Kavanagh Centre - centre/visitor entrance (operator confirms Inniskeen A91 KT20 with parking directly across the road)", "centre / visitor entrance"),
    ([10708], 53.978000, -6.719000, "REVIEW", "owner: Carrickmacross Lace Gallery - Market Square town-centre location confirmed (A81 K300)", "candidate - no authoritative GPS pin for the exact gallery entrance"),
    ([13868], 54.118000, -6.730000, "REVIEW", "owner: Lough Muckno approximate park location (Monaghan Tourism identifies the lake and leisure park)", "candidate - confirm which public entrance/parking point it represents"),
    ([10712], 54.323730, -7.101280, "REVIEW", "owner: Sliabh Beagh - Braggan/Knockatallon visitor area off the L1000 (peatland guide)", "candidate - a visitor/trailhead point, not a definitive point for the whole borderland area"),
]

# Monaghan County Museum has moved: no coordinate, only the owner's reason.
REVIEW_ONLY = {
    10706: "The museum is now at The Peace Campus, H18 RP20, not the older Hill Street address; the Peace Campus visitor-entrance coordinate is not yet established (underground parking and lift access)",
}


def main() -> int:
    manifest = json.load(open("/tmp/manifest-batch6.json", encoding="utf-8"))
    by_id = {int(e["id"]): e for e in manifest}

    stats = {"populate": 0, "review": 0}

    for ids, lat, lng, decision, source, assessment in BATCH7:
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
        entry["owner_source"] = "owner: not populated pending resolution"
        entry["reason"] = "owner deferred - " + reason
        stats["review"] += 1

    json.dump(manifest, open("/tmp/manifest-batch7.json", "w", encoding="utf-8"),
              ensure_ascii=False, indent=1)

    from collections import Counter
    print("actions:", Counter(e.get("action", "?") for e in manifest))
    pop = [e for e in manifest if e.get("action") == "POPULATE"]
    print("POPULATE entries:", len(pop), "unique posts:", len({e["id"] for e in pop}))
    print("batch7:", stats)
    return 0


if __name__ == "__main__":
    sys.exit(main())
