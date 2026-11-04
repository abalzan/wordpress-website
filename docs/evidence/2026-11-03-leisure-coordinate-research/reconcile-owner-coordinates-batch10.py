#!/usr/bin/env python3
"""Reconcile the owner's tenth research batch (Clare + Cork).

Input : /tmp/manifest-batch9.json
Output: /tmp/manifest-batch10.json

The owner's closing suggestion is followed literally:
  accept the high-confidence points (Aillwee, Spanish Point, Jameson),
  review the entrance-focused ones (Bunratty, Ennis, Kilkee, Annes Grove,
  Titanic), and decide whether Loop Head should replace its existing pin.

Loop Head is accepted: it carries a HIGH-confidence official tourism SatNav
point and the owner recommends replacing the offset existing candidate; the
established convention throughout this task is the visitor access point.
"""

from __future__ import annotations

import json
import sys

BATCH10 = [
    # ---- Clare: accepted ----
    ([10180], 53.089180, -9.143677, "POPULATE", "owner: Aillwee Caves published GPS coordinates for the cave attraction (ifcurious.ie)", "cave attraction location"),
    ([10591], 52.843178, -9.432872, "POPULATE", "owner: Spanish Point Beach - Clare County Council official beach location (confirms parking, toilets and access; preferred over the community-site GPS)", "official beach location with parking and access"),
    ([10182], 52.563000, -9.920500, "POPULATE", "owner: Loop Head Lighthouse visitor location from the published tourism SatNav coordinate (the site has dedicated parking). This REPLACES the earlier candidate 52.56089, -9.93218, which is offset from the lighthouse visitor area", "lighthouse visitor location / parking (owner asked the agent to decide; HIGH-confidence official SatNav)"),
    # ---- Clare: review ----
    ([10179], 52.695300, -8.813900, "REVIEW", "owner: Bunratty Castle & Folk Park visitor entrance/parking pin (visitor parking is opposite/adjacent to the entrance); this REPLACES the castle-centre candidate 52.701260, -8.812342 which is not the visitor access point", "candidate - visitor entrance/parking (parking evidence, not official operator GPS)"),
    ([10585], 52.846000, -8.981000, "REVIEW", "owner: Ennis Friary approximate site/entrance location from the Abbey Street address and the mapped friary (OPW confirms Abbey Street, Ennis, V95 AN28; no official numeric GPS published)", "candidate - approximate entrance/site"),
    ([10184], 52.677100, -9.676500, "REVIEW", "owner: Kilkee Cliffs cliff-walk access/parking point near the Diamond Rocks Cafe starting area (Discover Ireland confirms the cliff walk starts at Diamond Rocks Cafe)", "candidate - cliff-walk access/parking (visitor-parking evidence, not official GPS)"),
    # ---- Cork ----
    ([10189], 51.913819, -8.168309, "POPULATE", "owner: Jameson Distillery Midleton - published coordinates for the Midleton Distillery Experience; Jameson confirms Distillery Walk, Eircode P25 Y394, and parking outside the main gates / on Distillery Lane", "distillery visitor entrance (existing candidate confirmed)"),
    ([20905], 52.196140, -8.466130, "REVIEW", "owner: Annes Grove Gardens map-based pin (OPW confirms the site address and car park; no official numeric coordinate published). This REPLACES the earlier candidate 52.19462, -8.46596, ~170 m away", "candidate - entrance/parking alignment not confirmed"),
    ([20908], 51.893461, -8.302749, "REVIEW", "owner: Fota Arboretum & Gardens - keep the existing UCC coordinate pending better entrance evidence (Heritage Ireland confirms Fota Estate, parking and public access; Fota House directs visitors to the House/Gardens car park, not the Wildlife Park parking)", "candidate - keep existing pin; garden entrance not confirmed"),
    ([10187], 51.850285, -8.294663, "REVIEW", "owner: Titanic Experience Cobh - the local Cobh visitor-information pin at the White Star Line Building, 20 Casement Square (another source gives 51.850160, -9.295021-ish, a 30-40 m offset; the earlier Failte Ireland candidate was 51.849940, -8.295370)", "candidate - confirm the entrance/centre-front facade"),
]


def main() -> int:
    manifest = json.load(open("/tmp/manifest-batch9.json", encoding="utf-8"))
    by_id = {int(e["id"]): e for e in manifest}

    stats = {"populate": 0, "review": 0}

    for ids, lat, lng, decision, source, assessment in BATCH10:
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

    json.dump(manifest, open("/tmp/manifest-batch10.json", "w", encoding="utf-8"),
              ensure_ascii=False, indent=1)

    from collections import Counter
    print("actions:", Counter(e.get("action", "?") for e in manifest))
    pop = [e for e in manifest if e.get("action") == "POPULATE"]
    print("POPULATE entries:", len(pop), "unique posts:", len({e["id"] for e in pop}))
    print("batch10:", stats)
    return 0


if __name__ == "__main__":
    sys.exit(main())
