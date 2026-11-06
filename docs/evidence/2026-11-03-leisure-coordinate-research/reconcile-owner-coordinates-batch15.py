#!/usr/bin/env python3
"""Reconcile the owner's fifteenth research batch (Kilkenny + Monaghan).

Input : /tmp/manifest-batch14.json
Output: /tmp/manifest-batch15.json

Follows the owner's suggested disposition:

  ACCEPT (HIGH)              Castlecomer Discovery Park (Sport Ireland
                             visitor-centre car park), Woodstock Gardens
                             (Sport Ireland gardens car park, replacing the
                             offset candidate)
  ACCEPT at MEDIUM,          Kells Priory (OPW L1023 car park; owner says
  owner-approved             MEDIUM-HIGH, so MEDIUM is recorded and the
                             owner's acceptance is what makes it actionable),
                             Carrickmacross Lace Gallery (Market Square,
                             owner says MEDIUM), Lough Muckno (Hope Castle
                             car park, owner says MEDIUM-HIGH), Monaghan
                             County Museum (Peace Campus, owner says
                             MEDIUM-HIGH, replacing the old approximation)

Recording MEDIUM rather than HIGH for the four "MEDIUM-HIGH" rows is the
conservative reading of the owner's own hedge ("rather than claim absolute
precision"); the owner-approved flag is what permits the write and it is counted
in the apply summary.
"""

from __future__ import annotations

import json
import sys

BATCH15 = [
    ([10778], 52.807080, -7.202140, "POPULATE", "owner: Castlecomer Discovery Park - Sport Ireland gives the Visitor Centre car park as the loop start/finish and publishes these coordinates; the operator confirms the main car park is close to the Visitor Centre. Much stronger than the generic OSM park polygon, which was NOT used", "visitor-centre car park"),
    ([10781], 52.475540, -7.057800, "POPULATE", "owner: Woodstock Gardens - Sport Ireland publishes this coordinate for the Woodstock Gardens car park and the operator confirms visitors access the gardens through the paid car park, not the main estate gateway. This REPLACES the offset candidate 52.475281, -7.060336", "gardens car park (replaces the offset candidate)"),
    ([20941], 52.536800, -7.268000, "POPULATE", "owner: Kells Priory - candidate retained. OPW confirms the current access is the car park on the L1023; the priory ruins themselves are farther north-east (archaeology point ~52.538317, -7.266051), so this is the better visitor-access pin. OPW does not publish the parking GPS directly, hence the owner's MEDIUM-HIGH assessment", "L1023 car park / visitor access"),
    ([10708], 53.978000, -6.719000, "POPULATE", "owner: Carrickmacross Lace Gallery - candidate retained as the Market Square visitor location (official listings confirm Market Square / A81 K300). The exact shop-door GPS is not independently published; the nearby NIAH Market House coordinates are NOT the Gallery and were not substituted", "Market Square town-centre reference"),
    ([13868], 54.119120, -6.724540, "POPULATE", "owner: Lough Muckno Leisure Park - Hope Castle car-park/access candidate retained (Geograph labels this point 'Muckno Lough from Hope Castle Car Park'). This is the park arrival/access point, NOT the lake centre and NOT Hope Castle itself (which is farther west)", "Hope Castle car park / park arrival point"),
    ([10706], 54.251823, -6.969879, "POPULATE", "owner: Monaghan County Museum - the Peace Campus coordinate from the official campus site's map (54.25182317256572, -6.969878723171007). The museum is inside the Peace Campus at Plantation Road, H18 RP20, with underground visitor parking and lift access. This REPLACES the earlier approximation 54.2478, -6.968, and the former Hill Street location MUST NOT be used", "Peace Campus, Plantation Road (replaces the approximation; Hill Street excluded)"),
]

# Rows the owner accepted at MEDIUM / MEDIUM-HIGH rather than HIGH.
OWNER_APPROVED = {20941, 10708, 13868, 10706}


def main() -> int:
    manifest = json.load(open("/tmp/manifest-batch14.json", encoding="utf-8"))
    by_id = {int(e["id"]): e for e in manifest}

    stats = {"populate": 0, "review": 0, "owner_approved": 0}

    for ids, lat, lng, decision, source, assessment in BATCH15:
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

    json.dump(manifest, open("/tmp/manifest-batch15.json", "w", encoding="utf-8"),
              ensure_ascii=False, indent=1)

    from collections import Counter
    print("actions:", Counter(e.get("action", "?") for e in manifest))
    pop = [e for e in manifest if e.get("action") == "POPULATE"]
    print("POPULATE entries:", len(pop), "unique posts:", len({e["id"] for e in pop}))
    print("batch15:", stats)
    return 0


if __name__ == "__main__":
    sys.exit(main())
