#!/usr/bin/env python3
"""Reconcile the owner's sixteenth research batch (nine 2-attraction counties).

Input : /tmp/manifest-batch15.json
Output: /tmp/manifest-batch16.json

Classification rule (stated in the report):
  HIGH  -> POPULATE at HIGH (the only automatic path).
  MEDIUM-HIGH or MEDIUM with a clear recommendation and NO explicit review
        marker -> POPULATE at MEDIUM with owner_approved, because the owner
        recommends the point but has not claimed HIGH.
  MEDIUM with an explicit "needs owner review" / "owner should confirm"
        -> REVIEW with the candidate recorded.

Owner decision resolved: Carlingford Lough Greenway uses CARLINGFORD MARINA
(54.050161, -6.191342), owner-approved; Omeath Pier is not used.
"""

from __future__ import annotations

import json
import sys

BATCH16 = [
    # ---- HIGH -> POPULATE at HIGH ----
    ([10690], 54.264734, -7.888553, "HIGH", "owner: Cavan Burren Park - Cuilcagh Lakelands Geopark's published main car park coordinates. This REPLACES the earlier candidate 54.26298, -7.890357", "main car park (replaces the earlier candidate)"),
    ([13870], 53.9984, -7.3610, "HIGH", "owner: Cavan Cathedral - published cathedral location, close to the earlier candidate 53.99505, -7.36199. NOTE: this is Cavan Cathedral in Cavan town, NOT St Fethlimidh's Cathedral at Kilmore several kilometres away; the unrelated OSM bus-stop point was NOT used", "cathedral location (distinct from Kilmore cathedral; bus stop rejected)"),
    ([10172], 53.500679, -10.096485, "HIGH", "owner: Sky Road - candidate retained. IHPA identifies this as the small car park/viewpoint along Sky Road; although it sits on the road, it is a meaningful visitor stop, not a random road segment (the pipeline's road-feature hit was not used as such)", "car park / viewpoint stop along Sky Road"),
    ([10174], 53.129930, -9.757360, "HIGH", "owner: Dun Aonghasa - visitor-centre coordinate retained. The fort itself is roughly 1 km away on foot, so the visitor centre is the appropriate arrival point", "visitor centre (not the remote fort)"),
    ([10754], 53.883986, -9.212476, "HIGH", "owner: National Museum of Ireland - Country Life - candidate retained; the museum has a formal entrance and a large visitor car park confirmed by its official visitor information", "museum entrance / visitor car park"),
    ([10674], 53.982051, -8.245974, "HIGH", "owner: Lough Key Forest & Activity Park - candidate retained; the published location matches the park's visitor destination, which has a designated visitor centre and car park", "visitor centre / car park"),
    ([10676], 53.635378, -8.194071, "HIGH", "owner: Roscommon Castle - new candidate from archaeological site records, close to the castle ruins", "castle ruins monument point"),
    ([10653], 53.758454, -8.091431, "HIGH", "owner: Glendeer Pet Farm - candidate retained; a visitor listing publishes these coordinates and the operator confirms the address at Curryroe, Drum", "farm entrance (address confirmed)"),
    ([10688], 54.323200, -8.015100, "HIGH", "owner: Sean Mac Diarmada Cottage - new candidate from the cottage's mapped object location. OPW identifies the cottage at Laghty Barr, which is SEPARATE from the Heritage Centre in Kiltyclogher village; this point is the cottage itself", "the cottage itself (not the Kiltyclogher Heritage Centre)"),
    # ---- MEDIUM-HIGH / MEDIUM with a clear recommendation -> MEDIUM + owner-approved ----
    ([10627], 52.885278, -7.296000, "MEDIUM-HIGH", "owner: Heywood Gardens - candidate retained; OPW describes the visitor route starting beside the Gate Lodge. The owner asks that it be confirmed as the entrance/parking rather than an interior garden point", "entrance/parking beside the Gate Lodge (to be confirmed)"),
    ([10682], 54.041570, -8.048706, "MEDIUM-HIGH", "owner: Acres Lake Floating Boardwalk - candidate retained. The boardwalk is a LINEAR feature, so a car park/visitor arrival point is more useful than its centre; Failte Ireland documents the site's car park and boardwalk access", "car park / visitor arrival point for a linear boardwalk"),
    ([10662], 53.566700, -7.766700, "MEDIUM-HIGH", "owner: Newcastle Wood - candidate retained as geographically appropriate. UCC lists this site coordinate but displays the longitude without the west-negative sign; the corrected Irish longitude is negative (as stored here)", "woodland site (longitude sign corrected)"),
    ([10891], 54.043177, -6.186830, "MEDIUM-HIGH", "owner: King John's Castle (Carlingford) - new candidate for the castle itself, a site/monument coordinate rather than a nearby town or marina point", "castle monument"),
    ([10649], 53.369480, -7.502670, "MEDIUM-HIGH", "owner: Kilbeggan Distillery Experience - candidate retained; the operator confirms the distillery visitor experience and its location off the M6/Junction 5. The owner asks that the pin alignment with the entrance/parking be verified", "distillery visitor entrance (alignment to be verified)"),
    ([10873], 54.050161, -6.191342, "MEDIUM-HIGH", "OWNER DECISION (resolved): Carlingford Lough Greenway uses CARLINGFORD MARINA as its representative entry point (Carlingford-side trailhead, Sport Ireland greenway listing). OMEATH PIER (54.088969, -6.257057) is deliberately NOT used. This closes the endpoint decision that had been open since the first Cork/Louth pass", "Carlingford Marina trailhead (owner decision; Omeath Pier excluded)"),
    ([10656], 53.664300, -7.678700, "MEDIUM", "owner: Ardagh Heritage Village - existing village point retained. This is a village-level attraction rather than a single building, so a broad visitor location is reasonable rather than a precise heritage-centre entrance", "village-level visitor location"),
    # ---- MEDIUM with an explicit review marker -> REVIEW ----
    ([10623], 53.032400, -7.208000, "MEDIUM-REVIEW", "owner: Rock of Dunamase - candidate for the car park near the monument entrance (OPW confirms limited parking close to the entrance); the owner explicitly flags this for review. The monument itself is available as an alternative site point around 53.031783, -7.210743", "candidate - car park near the entrance; owner review requested"),
    ([10748], 53.926170, -9.936690, "MEDIUM-REVIEW", "owner: Achill Island - candidate for the Achill Sound bridge/access gateway. Achill Island is a large geographic destination with no single natural point, and the owner explicitly asks whether a bridge/gateway pin is desired", "candidate - gateway/bridge access point; owner confirmation requested"),
]

OWNER_APPROVED = {10627, 10682, 10662, 10891, 10649, 10873, 10656}
REVIEW_ONLY = {10623, 10748}


def main() -> int:
    manifest = json.load(open("/tmp/manifest-batch15.json", encoding="utf-8"))
    by_id = {int(e["id"]): e for e in manifest}

    stats = {"populate": 0, "review": 0, "owner_approved": 0}

    for ids, lat, lng, band, source, assessment in BATCH16:
        for pid in ids:
            entry = by_id.get(pid)
            if entry is None:
                print(f"WARNING: post {pid} not in the manifest", file=sys.stderr)
                continue
            entry["owner_source"] = source
            entry["owner_assessment"] = assessment
            entry["candidate_lat"] = lat
            entry["candidate_lng"] = lng

            if band in ("HIGH", "MEDIUM-HIGH", "MEDIUM"):
                entry["action"] = "POPULATE"
                entry["confidence"] = "HIGH" if band == "HIGH" else "MEDIUM"
                entry["proposed_lat"] = lat
                entry["proposed_lng"] = lng
                entry["reason"] = f"owner-supplied access point - {assessment}"
                if pid in OWNER_APPROVED or band != "HIGH":
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

    json.dump(manifest, open("/tmp/manifest-batch16.json", "w", encoding="utf-8"),
              ensure_ascii=False, indent=1)

    from collections import Counter
    print("actions:", Counter(e.get("action", "?") for e in manifest))
    pop = [e for e in manifest if e.get("action") == "POPULATE"]
    print("POPULATE entries:", len(pop), "unique posts:", len({e["id"] for e in pop}))
    print("batch16:", stats)
    return 0


if __name__ == "__main__":
    sys.exit(main())
