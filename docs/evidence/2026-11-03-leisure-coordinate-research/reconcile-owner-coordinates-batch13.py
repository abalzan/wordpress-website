#!/usr/bin/env python3
"""Reconcile the owner's thirteenth research batch (Clare + Cork).

Input : /tmp/manifest-batch12.json
Output: /tmp/manifest-batch13.json

Follows the owner's suggested disposition exactly:
  ACCEPT            Bunratty (visitor parking/entrance), Ennis Friary (published
                    SatNav, replacing the rounded candidate), Fota Arboretum
                    (UCC exact coordinate, confirmed as the Arboretum not the
                    Wildlife Park)
  CORRECTION        Kilkee Cliffs -> the Diamond Rocks Cafe walk-start area,
                    replacing the cliff-viewpoint pin
  RETAIN REVIEW     Annes Grove Gardens (entrance-vs-garden-point choice)
  KEEP EXISTING,    Titanic Experience Cobh (the existing candidate is kept and
  DISCREPANCY       the ~60 m source discrepancy is recorded; the owner did not
                    accept it, so it stays REVIEW)
"""

from __future__ import annotations

import json
import sys

BATCH13 = [
    ([10179], 52.695300, -8.813900, "POPULATE", "owner: Bunratty Castle & Folk Park - visitor parking/entrance area, NOT the castle centre. Bunratty confirms free visitor parking and GPS address V95 TY02; the parking coordinate comes from a listing describing the car park opposite the Folk Park entrance", "visitor parking / entrance area"),
    ([10585], 52.845978, -8.981586, "POPULATE", "owner: Ennis Friary - published SatNav coordinate for the friary on Abbey Street (OPW confirms Abbey Street, Ennis, V95 AN28). This is preferred over the earlier rounded candidate 52.846, -8.981", "friary SatNav location on Abbey Street (replaces the rounded candidate)"),
    ([10184], 52.681780, -9.665030, "POPULATE", "owner correction: Kilkee Cliffs - the Diamond Rocks Cafe / West End walk-start area (Discover Ireland identifies Diamond Rocks Cafe as the cliff walk start; Kilkee's local tourism site says the cafe is at the beginning of the walk). This REPLACES the earlier cliff-viewpoint pin 52.6771, -9.6765, which is geographically plausible but less practical as the primary visitor map pin", "Diamond Rocks Cafe / cliff-walk start (replaces the viewpoint pin)"),
    ([20908], 51.893461, -8.302749, "POPULATE", "owner: Fota Arboretum & Gardens - UCC publishes this exact coordinate and confirms it is the Arboretum/Gardens, NOT Fota Wildlife Park; OPW confirms the site has parking and garden facilities. The existing candidate is kept", "arboretum / gardens (UCC coordinate; distinct from Fota Wildlife Park)"),
    ([20905], 52.196140, -8.466130, "REVIEW", "owner: Annes Grove Gardens - OSM garden feature point (OPW confirms onsite free parking and visitor access but publishes no precise entrance/parking coordinate). A trail-start point around 52.1946, -8.46596 is also reported and may be the visitor arrival area", "candidate - genuine entrance-vs-garden-point choice retained for the owner"),
    ([10187], 51.850285, -8.294663, "REVIEW", "owner: Titanic Experience Cobh - the existing Cobh Guide candidate for the White Star Line Building, 20 Casement Square is KEPT unchanged. Failte Ireland gives 51.84994, -8.29537, ~60 m away; the operator confirms the building, address and front entrance but publishes no coordinate to resolve the discrepancy, so it is preserved rather than changed on the older GPS alone", "candidate - existing pin kept; ~60 m source discrepancy noted"),
]


def main() -> int:
    manifest = json.load(open("/tmp/manifest-batch12.json", encoding="utf-8"))
    by_id = {int(e["id"]): e for e in manifest}

    stats = {"populate": 0, "review": 0}

    for ids, lat, lng, decision, source, assessment in BATCH13:
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

    json.dump(manifest, open("/tmp/manifest-batch13.json", "w", encoding="utf-8"),
              ensure_ascii=False, indent=1)

    from collections import Counter
    print("actions:", Counter(e.get("action", "?") for e in manifest))
    pop = [e for e in manifest if e.get("action") == "POPULATE"]
    print("POPULATE entries:", len(pop), "unique posts:", len({e["id"] for e in pop}))
    print("batch13:", stats)
    return 0


if __name__ == "__main__":
    sys.exit(main())
