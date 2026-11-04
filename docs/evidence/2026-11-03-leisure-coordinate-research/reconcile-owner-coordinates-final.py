#!/usr/bin/env python3
"""Final reconciliation: the three confirmed owner decisions.

Input : /tmp/manifest-batch17.json
Output: /tmp/manifest-final.json

The owner has now explicitly confirmed all three rows that the research author
declined to owner-approve without confirmation. Per the owner's instructions:

  * resolve all three REVIEW entries as POPULATE;
  * record the owner decision and the visitor-access rationale in the provenance;
  * preserve confidence as MEDIUM - do NOT promote these to HIGH;
  * set owner_approved: true;
  * leave _leisure_coord_verified untouched (it stays 0);
  * do not touch the duplicate Phoenix Park `_leisure_export_uuid`.
"""

from __future__ import annotations

import json
import sys

FINAL = [
    ([10879], 52.735567, -6.720599, "OWNER DECISION (confirmed): Altamont Gardens - the visitor parking/entrance area, chosen over the garden-centroid alternative. Four published points differed substantially: Direction Ireland 52.735567, -6.720599 (used); Tuatha 52.735152, -6.720796; a regional visitor-guide SatNav 52.738400, -6.720700; and UCC's site coordinate 52.744497, -6.725076, ~1 km north (rejected as a site rather than an entrance). OPW confirms the visitor car park and the address Altamont, Tullow, R93 N882. MEDIUM because this is not a definitive entrance survey", "visitor parking / entrance (owner decision; UCC site point rejected)"),
    ([20905], 52.194620, -8.465960, "OWNER DECISION (confirmed): Annes Grove Gardens - the visitor entrance/arrival point, chosen over the previously stored garden point 52.196140, -8.466130 (which may represent the gardens rather than the entrance). The candidate is the trail-recorded entrance/start location. OPW confirms the gardens are open with onsite parking and the route via Castletownroche and the Doneraile Road, but does not publish the parking entrance as a GPS pair, hence MEDIUM", "visitor entrance / arrival point (owner decision; replaces the garden point)"),
    ([10748], 53.926170, -9.936690, "OWNER DECISION (confirmed): Achill Island is represented by the Achill Sound / Michael Davitt Bridge island gateway (Placenames.ie records Achill Sound at exactly these coordinates). Achill Island is a large geographic destination with no natural single centre, so the bridge/gateway is the visitor arrival point for anyone approaching the island. MEDIUM because it is a gateway rather than a surveyed attraction entrance", "Achill Sound / Michael Davitt Bridge gateway (owner decision)"),
]


def main() -> int:
    manifest = json.load(open("/tmp/manifest-batch17.json", encoding="utf-8"))
    by_id = {int(e["id"]): e for e in manifest}

    stats = {"populate": 0, "owner_approved": 0}

    for ids, lat, lng, source, assessment in FINAL:
        for pid in ids:
            entry = by_id.get(pid)
            if entry is None:
                print(f"WARNING: post {pid} not in the manifest", file=sys.stderr)
                continue
            entry["owner_source"] = source
            entry["owner_assessment"] = assessment
            entry["candidate_lat"] = lat
            entry["candidate_lng"] = lng
            entry["action"] = "POPULATE"
            entry["confidence"] = "MEDIUM"
            entry["owner_approved"] = True
            entry["proposed_lat"] = lat
            entry["proposed_lng"] = lng
            entry["reason"] = f"owner-supplied access point - {assessment}"
            stats["populate"] += 1
            stats["owner_approved"] += 1

    json.dump(manifest, open("/tmp/manifest-final.json", "w", encoding="utf-8"),
              ensure_ascii=False, indent=1)

    from collections import Counter
    print("actions:", Counter(e.get("action", "?") for e in manifest))
    pop = [e for e in manifest if e.get("action") == "POPULATE"]
    print("POPULATE entries:", len(pop), "unique posts:", len({e["id"] for e in pop}))
    print("owner_approved entries:", sum(1 for e in manifest if e.get("owner_approved")))
    print("final batch:", stats)
    return 0


if __name__ == "__main__":
    sys.exit(main())
