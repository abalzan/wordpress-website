#!/usr/bin/env python3
"""Reconcile the owner's fourteenth research batch (Donegal + Kildare).

Input : /tmp/manifest-batch13.json
Output: /tmp/manifest-batch14.json

All six rows are accepted. Notes on three of them:

  * Newmills (20939): the earlier open question is CLOSED in favour of the
    visitor mill site. The archaeology coordinate (54.929234, -7.806959)
    describes a proposed development/testing site EAST of the mill complex, not
    the visitor entrance, so it is rejected for visitor use. The OPW SatNav
    (54.9292, -7.8082) corroborates the retained candidate.

  * Malin Head (10720): the Banba's Crown visitor point is accepted, aligning
    with the upper car park beside the signal tower rather than the abstract
    cape centre.

  * Mondello Park (10863): the owner EXPLICITLY APPROVED the address-based
    venue point (Mondello Park, Donore, Naas, Co. Kildare, Eircode W91 T957).
    The owner's own confidence is MEDIUM because the exact gate coordinate
    remains approximate, so this entry carries `owner_approved: true` and the
    apply tool's narrow owner-approved exception is what permits the write.
"""

from __future__ import annotations

import json
import sys

BATCH14 = [
    ([13865], 55.309020, -7.335630, "POPULATE", "owner: Doagh Famine Village - candidate retained. Operator confirms Eircode F93 PK19, onsite visitor parking and a visitor centre; Discover Ireland confirms free parking. A tourism brochure gives an alternate GPS ~150 m west, which is not a reason to replace the more locally aligned candidate", "visitor centre / onsite parking"),
    ([10720], 55.380948, -7.373576, "POPULATE", "owner: Malin Head - Banba's Crown visitor point, aligning with the upper car park beside the signal tower (Discover Ireland identifies Banba's Crown and the tower as a key stop; Failte Ireland documents the upper and lower car parks). This is Banba's Crown, not the abstract cape centre", "Banba's Crown / upper car park by the signal tower"),
    ([20939], 54.929070, -7.808440, "POPULATE", "owner: Newmills Corn & Flax Mills - candidate retained and the earlier open question CLOSED in favour of the visitor mill site. OPW visitor-brochure SatNav (54.9292, -7.8082) corroborates this point; the excavation coordinate 54.929234, -7.806959 belongs to a proposed development/testing site EAST of the mill complex, not the visitor entrance, and is REJECTED for visitor use", "visitor mill site (archaeology coordinate rejected as a non-visitor location)"),
    ([10853], 53.278610, -6.942560, "POPULATE", "owner: Bog of Allen Nature Centre - the building point, preferred over the generic OSM community-centre polygon; IPCC's official address is Bog of Allen Nature Centre, Lullymore, Rathangan, R51 V293. This is the actual centre, not the wider bog", "the nature-centre building (not the community-centre polygon)"),
    ([10863], 53.257500, -6.745000, "POPULATE", "owner-APPROVED address-based venue point: Mondello Park, Donore, Naas, Co. Kildare, Eircode W91 T957 (the operator confirms the address, access directions and onsite visitor parking). The exact gate coordinate remains approximate, which is why this entry is MEDIUM and carries owner_approved; the pipeline's sports-centre polygon (53.2577, -6.74751) is generic venue geometry and was NOT used", "venue / arrival point on the official address (exact gate approximate - owner approved)"),
    ([10855], 53.183591, -6.845753, "POPULATE", "owner: Pollardstown Fen - candidate retained. NPWS publishes almost exactly the same coordinate (53.1835908, -6.8457528) and its event information separately identifies the car park at 53.183600, -6.8457752. This is the visitor access/car park, not a wetland centroid", "visitor access / car park (NPWS reserve + car-park coordinates agree)"),
]

# Mondello Park is the owner-approved MEDIUM entry.
OWNER_APPROVED = {10863}


def main() -> int:
    manifest = json.load(open("/tmp/manifest-batch13.json", encoding="utf-8"))
    by_id = {int(e["id"]): e for e in manifest}

    stats = {"populate": 0, "review": 0, "owner_approved": 0}

    for ids, lat, lng, decision, source, assessment in BATCH14:
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
                if pid in OWNER_APPROVED:
                    entry["confidence"] = "MEDIUM"
                    entry["owner_approved"] = True
                    stats["owner_approved"] += 1
                stats["populate"] += 1
            else:
                entry["action"] = "REVIEW"
                if entry.get("confidence") in (None, "HIGH"):
                    entry["confidence"] = "MEDIUM"
                entry["proposed_lat"] = None
                entry["proposed_lng"] = None
                entry["reason"] = f"owner-supplied candidate - {assessment}"
                stats["review"] += 1

    json.dump(manifest, open("/tmp/manifest-batch14.json", "w", encoding="utf-8"),
              ensure_ascii=False, indent=1)

    from collections import Counter
    print("actions:", Counter(e.get("action", "?") for e in manifest))
    pop = [e for e in manifest if e.get("action") == "POPULATE"]
    print("POPULATE entries:", len(pop), "unique posts:", len({e["id"] for e in pop}))
    print("batch14:", stats)
    return 0


if __name__ == "__main__":
    sys.exit(main())
