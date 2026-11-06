#!/usr/bin/env python3
"""Reconcile the owner's FINAL research batch (the 8 remaining attractions).

Input : /tmp/manifest-batch16.json
Output: /tmp/manifest-batch17.json

The research author separated the batch into two groups:

  * "strong visitor-access recommendations" needing no owner decision
    (Titanic Experience, Royal Hospital Kilmainham, Banna Strand, Loughcrew)
    -> POPULATE. Titanic / Royal Hospital / Banna are HIGH; Loughcrew is
       MEDIUM-HIGH, so it is recorded at MEDIUM + owner_approved.

  * four PROPOSED OWNER DECISIONS, which the author explicitly declined to
    mark owner-approved "without your confirmation": Altamont Gardens, Annes
    Grove Gardens, Rock of Dunamase, Achill Island.

Of those four the owner has now explicitly decided ONE:

  Rock of Dunamase (10623) -> Option A, the visitor car park / arrival point
  (53.032400, -7.208000), MEDIUM, owner_approved. Option B (the monument,
  53.031783, -7.210743) is explicitly rejected and is recorded as such.

The other three (Altamont Gardens, Annes Grove Gardens, Achill Island) remain
at REVIEW with their candidates recorded, pending the owner's confirmation.
"""

from __future__ import annotations

import json
import sys

BATCH17 = [
    # ---- strong recommendations: no owner decision required ----
    ([10187], 51.850285, -8.294663, "HIGH", "owner: Titanic Experience Cobh - candidate retained. The official address is the White Star Line Building, 20 Casement Square; Cobh Guide publishes 51.85028536599, -8.2946627515388 for it. Failte Ireland's 51.84994, -8.29537 is ~60 m away and refers to the same attraction, so there is no compelling reason to change the point", "White Star Line Building, 20 Casement Square (address-linked GPS)"),
    ([20922], 53.343460, -6.302550, "HIGH", "owner: Royal Hospital Kilmainham - candidate retained as the OPW-confirmed pedestrian east gate on Military Road (OPW warns the approach is steep). This is the east-gate access point, not the centre of the hospital grounds", "Military Road pedestrian east gate"),
    ([10621], 52.338745, -9.835651, "HIGH", "owner: Banna Strand - candidate retained. IrishBeaches explicitly identifies these coordinates as its VERIFIED CAR-PARK pin (not the beach centre or a point along the sand); Failte Ireland's visitor-movement study confirms the substantial paved car park", "verified beach car park"),
    ([10167], 53.744100, -7.118800, "MEDIUM-HIGH", "owner: Loughcrew - the car park at the foot of the walking trail (OPW confirms the narrow approach road, car park and steep climb to Cairn T). The cairn/site point 53.744677, -7.112322 lies uphill and was not used; the pipeline's residential-road hit was rejected. MEDIUM-HIGH because the parking coordinate comes from a third-party parking listing rather than an OPW-published GPS", "car park at the foot of the trail (cairn and residential road rejected)"),
    # ---- the one owner decision the owner has now made ----
    ([10623], 53.032400, -7.208000, "OWNER-DECISION", "OWNER DECISION (resolved): Rock of Dunamase uses OPTION A - the visitor car park / arrival point. OPTION B (the monument itself, 53.031783, -7.210743) is explicitly REJECTED and is not used; the alternative archaeological point 53.031263, -7.210757 was also not used. OPW confirms limited parking close to the monument entrance and the monument is reachable on foot from the car park", "visitor car park / arrival point (owner decision; monument option rejected)"),
    # ---- the three still awaiting the owner's confirmation ----
    ([10879], 52.735567, -6.720599, "REVIEW", "owner: Altamont Gardens - PROPOSED OWNER DECISION awaiting confirmation. Recommended candidate is the visitor parking/entrance area. Four published points differ substantially: Direction Ireland 52.735567, -6.720599; Tuatha 52.735152, -6.720796; a regional visitor guide SatNav 52.738400, -6.720700; and UCC's site coordinate 52.744497, -6.725076 (~1 km north). OPW confirms the visitor car park and address Altamont, Tullow, R93 N882", "candidate - visitor parking/entrance; owner confirmation requested"),
    ([20905], 52.194620, -8.465960, "REVIEW", "owner: Annes Grove Gardens - PROPOSED OWNER DECISION awaiting confirmation. The trail-recorded visitor entrance/start is 52.194620, -8.465960; the previously stored candidate 52.196140, -8.466130 is nearby but may represent the gardens rather than the entrance. OPW confirms the gardens are open with onsite parking and the route via Castletownroche and Doneraile Road", "candidate - visitor arrival/entrance; owner confirmation requested"),
    ([10748], 53.926170, -9.936690, "REVIEW", "owner: Achill Island - PROPOSED OWNER DECISION awaiting confirmation. Placenames.ie records Achill Sound / the Michael Davitt Bridge at exactly these coordinates. Achill Island is a large geographic destination with no natural single centre, so a gateway/bridge point is a practical approach - but the owner asked to confirm that the site should represent the island by its bridge/gateway rather than a destination on the island", "candidate - Achill Sound / Michael Davitt Bridge gateway; owner confirmation requested"),
]

# Loughcrew is MEDIUM-HIGH -> recorded at MEDIUM with owner approval.
OWNER_APPROVED = {10167, 10623}


def main() -> int:
    manifest = json.load(open("/tmp/manifest-batch16.json", encoding="utf-8"))
    by_id = {int(e["id"]): e for e in manifest}

    stats = {"populate": 0, "review": 0, "owner_approved": 0}

    for ids, lat, lng, band, source, assessment in BATCH17:
        for pid in ids:
            entry = by_id.get(pid)
            if entry is None:
                print(f"WARNING: post {pid} not in the manifest", file=sys.stderr)
                continue
            entry["owner_source"] = source
            entry["owner_assessment"] = assessment
            entry["candidate_lat"] = lat
            entry["candidate_lng"] = lng

            if band in ("HIGH", "MEDIUM-HIGH", "OWNER-DECISION"):
                entry["action"] = "POPULATE"
                entry["confidence"] = "HIGH" if band == "HIGH" else "MEDIUM"
                entry["proposed_lat"] = lat
                entry["proposed_lng"] = lng
                entry["reason"] = f"owner-supplied access point - {assessment}"
                if pid in OWNER_APPROVED:
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

    json.dump(manifest, open("/tmp/manifest-batch17.json", "w", encoding="utf-8"),
              ensure_ascii=False, indent=1)

    from collections import Counter
    print("actions:", Counter(e.get("action", "?") for e in manifest))
    pop = [e for e in manifest if e.get("action") == "POPULATE"]
    print("POPULATE entries:", len(pop), "unique posts:", len({e["id"] for e in pop}))
    print("batch17:", stats)
    return 0


if __name__ == "__main__":
    sys.exit(main())
