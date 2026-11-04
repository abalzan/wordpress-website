#!/usr/bin/env python3
"""Reconcile the owner's ninth research batch (Sligo + Wicklow).

Input : /tmp/manifest-batch8.json
Output: /tmp/manifest-batch9.json

The owner resolved both REVIEW cases in the same message:
  * Lissadell House  -> accept the published Failte Ireland site coordinate.
  * Strandhill Beach -> use the main promenade / car park.

Every row in this batch is therefore accepted. The Strandhill record carries an
explicit caveat: the owner noted the supplied candidate came from a playground
guide and does not by itself prove the car park, so the provenance records both
the decision and that caveat.
"""

from __future__ import annotations

import json
import sys

BATCH9 = [
    # ---- Sligo ----
    ([10734], 54.250900, -8.519300, "POPULATE", "owner: Carrowmore - OPW visitor brochure SatNav coordinates; Heritage Ireland confirms the visitor centre, parking and facilities", "visitor centre / parking (OPW SatNav)"),
    ([10742], 54.326400, -8.493600, "POPULATE", "owner: Drumcliffe Church and Yeats' Grave - OPW visitor brochure coordinate; the church guide confirms the churchyard and parking on site", "church and churchyard / parking"),
    ([10740], 54.346936, -8.580878, "POPULATE", "owner decision: Lissadell House - accept the published Failte Ireland site coordinate (the estate describes its visitor entrance as the third entrance after Carney village, opposite the Anglican church, but does not pin the gate; the estate is closed to the public until 29 May 2027)", "published site coordinate (owner's decision)"),
    ([10732], 54.270400, -8.602750, "POPULATE", "owner decision: Strandhill Beach - main promenade / car park is the representative point. CAVEAT: the owner notes the supplied candidate comes from a Sligo County Council playground guide and does not by itself establish the car park; it sits on the Strandhill seafront. Refine with a precise car-park pin if preferred", "main promenade / car park (owner's decision; candidate is a seafront point)"),
    ([13864], 54.273040, -8.468420, "POPULATE", "owner: The Model - existing pin retained as supported; the venue's visitor guidance confirms Eircode F91 TP20, the building and parking on Connaughton Road", "venue location (existing pin retained)"),
    # ---- Wicklow ----
    ([10161], 52.911583, -6.219361, "POPULATE", "owner: Avondale Forest Park - the operator's Beyond the Trees directions publish 52 54 41.7 N, 6 13 9.7 W (equal to this coordinate) and confirm the on-site car park and visitor centre; this resolves the earlier three-point ambiguity in favour of the operator", "operator access point / car park"),
    ([10555], 52.877419, -6.061707, "POPULATE", "owner: Brittas Bay Beach - Wicklow County Tourism published GPS; a tourism reference pin for a beach that stretches several kilometres", "tourism reference pin on the beach"),
    ([20948], 52.961976, -6.564829, "POPULATE", "owner: Dwyer McAllister Cottage - Buildings of Ireland Irish Grid coordinates for the cottage itself, converted (they match the existing candidate precisely); the tourism point is ~400 m away, so the building-specific heritage inventory wins", "the cottage building"),
    ([10160], 53.137792, -6.312171, "POPULATE", "owner: Sally Gap - Wicklow County Tourism published GPS for the crossroads/pass, which is the correct interpretation for a road-junction attraction", "crossroads / pass (road junction)"),
    ([10553], 52.979150, -6.037850, "POPULATE", "owner: Wicklow's Historic Gaol - Wicklow County Tourism published GPS; a heritage inventory Irish Grid conversion gives ~52.978902, -6.037186, a nearby point on the gaol. The tourism listing reports temporary closure for maintenance", "visitor-site pin on the gaol"),
]


def main() -> int:
    manifest = json.load(open("/tmp/manifest-batch8.json", encoding="utf-8"))
    by_id = {int(e["id"]): e for e in manifest}

    stats = {"populate": 0, "review": 0}

    for ids, lat, lng, decision, source, assessment in BATCH9:
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

    json.dump(manifest, open("/tmp/manifest-batch9.json", "w", encoding="utf-8"),
              ensure_ascii=False, indent=1)

    from collections import Counter
    print("actions:", Counter(e.get("action", "?") for e in manifest))
    pop = [e for e in manifest if e.get("action") == "POPULATE"]
    print("POPULATE entries:", len(pop), "unique posts:", len({e["id"] for e in pop}))
    print("batch9:", stats)
    return 0


if __name__ == "__main__":
    sys.exit(main())
