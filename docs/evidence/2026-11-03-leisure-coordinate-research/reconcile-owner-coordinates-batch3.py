#!/usr/bin/env python3
"""Reconcile the owner's third research batch into the manifest.

Input : /tmp/manifest-owner.json   (the manifest after batch 2)
Output: /tmp/manifest-final.json   (same shape, ready for build-deliverables)

Rules: identical to batch 2 - the owner's own assessment is the first signal,
the repository's verification rule the second.

  POPULATE    a clear, sourced visitor location with no uncertainty marker
              (official operator / UCC / Sport Ireland / tourism organisation
              published coordinates), and the owner's suggested default access
              points for the large-area attractions.
  CORROBORATE promoted to POPULATE only when an independent source agrees
              within 250 m.
  REVIEW      anything the owner explicitly left for review, every remaining
              conflict, and everything still lacking a verified entrance.
              A candidate coordinate is still recorded where one exists.
"""

from __future__ import annotations

import json
import math
import sys

TOLERANCE_M = 250.0


def hav(a, b, c, d):
    r = 6371000.0
    p1, p2 = math.radians(a), math.radians(c)
    dp, dl = math.radians(c - a), math.radians(d - b)
    x = math.sin(dp / 2) ** 2 + math.cos(p1) * math.cos(p2) * math.sin(dl / 2) ** 2
    return 2 * r * math.asin(math.sqrt(x))


# ids, lat, lng, decision, source, assessment
BATCH3 = [
    # -- section 1: strongest new candidates --
    ([10589], 52.929491, -8.429379, "POPULATE", "owner: Mountshannon Pier - official boat-trip departure point (Holy Island / Inis Cealtra)", "official boat-trip departure point"),
    ([10861], 53.270363, -6.949394, "POPULATE", "owner: official Lullymore Heritage Park visitor entrance", "official visitor entrance"),
    ([10762], 54.024505, -9.823505, "POPULATE", "owner: Ballycroy Visitor Centre (Mayo Dark Sky Park GPS)", "visitor centre"),
    ([10569], 53.678268, -6.256799, "POPULATE", "owner: Sonairte visitor Eco-Centre (UCC location record)", "visitor eco-centre"),
    ([10567], 53.726400, -6.870970, "POPULATE", "owner: Kells Courthouse Tourism and Cultural Hub - suggested trail starting point (Discover Boyne Valley)", "trail starting point"),
    ([10643], 53.253340, -7.500880, "POPULATE", "owner: current Tullamore D.E.W. distillery visitor location (Visit Offaly)", "distillery visitor location"),
    ([10779], 52.512267, -7.166894, "POPULATE", "owner: published Jerpoint Park visitor GPS location", "published visitor GPS"),
    ([10631], 52.917287, -7.346120, "POPULATE", "owner: Abbeyleix Heritage House and visitor parking (published coordinate pair)", "heritage house + visitor parking"),
    ([10647], 53.422827, -7.942676, "POPULATE", "owner: Athlone Castle location; an independent archaeological coordinate is very close", "castle location"),
    ([20944], 52.352945, -7.701398, "POPULATE", "owner: The Main Guard, Sarsfield Street (OPW address-consistent map location)", "building location"),
    ([10797], 51.943662, -7.734172, "POPULATE", "owner: Ardmore Round Tower - archaeological excavation record at the base of the tower", "the tower itself"),
    ([10700], 54.097752, -7.442228, "POPULATE", "owner: Belturbet historic railway station building, now a visitor/heritage centre", "station building"),
    # -- section 2: owner says keep REVIEW --
    ([10682], 54.041570, -8.048706, "REVIEW", "owner: Acres Cove Marina (Acres Lake Boardwalk)", "candidate - confirm the preferred boardwalk access point"),
    ([13863], 53.288650, -6.113640, "REVIEW", "owner: James Joyce Tower location at Sandycove Point", "candidate - tower location"),
    ([20923], 53.347948, -6.269320, "REVIEW", "owner: St Mary's Abbey archaeological site location", "candidate - confirm the public Chapter House entrance"),
    ([20910], 53.371338, -6.226992, "REVIEW", "owner: Casino Marino mapped building location", "candidate - check against the OPW visitor entrance"),
    ([10146], 53.343950, -6.256846, "REVIEW", "owner: Trinity College Old Library visitor entrance (official Trinity visitor information)", "candidate - Old Library visitor entrance"),
    ([20908], 51.893461, -8.302749, "REVIEW", "owner: UCC mapped arboretum point (UCC publishes 51.89346119, -8.302748502)", "candidate - visitor info notes a lower car park and a separate accessible garden entrance"),
    ([10799], 52.239230, -7.502340, "REVIEW", "owner: Coumshingaun car park / trailhead candidate", "candidate - car park / trailhead"),
    ([10827], 52.263080, -7.971860, "REVIEW", "owner: Killballyboy Wood car park (Bay Lough Loop trailhead)", "candidate - suits the Bay Lough Loop, not the Vee viewpoint"),
    ([10649], 53.369480, -7.502670, "REVIEW", "owner: mapped Kilbeggan distillery building", "candidate - confirm the visitor-centre entrance"),
    ([13868], 54.118000, -6.730000, "REVIEW", "owner: approximate mapped Lough Muckno park location", "candidate - not a verified entrance"),
    # -- section 3: conflicts nearer resolution, still REVIEW --
    ([10716], 54.635600, -8.645300, "REVIEW", "owner: the credible Sliabh Liag visitor-centre candidate (the other published pair is geographically inconsistent)", "candidate - the operator's pages still conflict; confirmation pending"),
    ([10179], 52.701260, -8.812342, "REVIEW", "owner: County Clare Heritage Office castle location; the operator confirms the visitor address and free car park", "candidate - the castle and the car-park/visitor entrance are different points"),
    ([10873], 54.050161, -6.191342, "REVIEW", "owner: Carlingford Marina endpoint (Omeath Pier is 54.088969, -6.257057)", "candidate - one official endpoint must be chosen"),
    # -- section 4: owner-suggested access points for the large areas --
    ([10150], 53.366050, -6.330930, "POPULATE", "owner: Phoenix Park Visitor Centre - the suggested access point for the park", "visitor centre (not the park centre)"),
    ([10173], 53.265980, -9.564100, "POPULATE", "owner: Rossaveel Ferry Terminal (Aran Island Ferries published GPS)", "ferry terminal"),
    ([10199], 52.139153, -10.274592, "POPULATE", "owner: Dingle Pier (Screen Kerry published coordinates)", "pier"),
    ([10198], 52.056831, -9.508254, "POPULATE", "owner: Killarney House & Gardens - the suggested access point for the Ring of Kerry", "visitor access point"),
    ([10193], 52.056831, -9.508254, "POPULATE", "owner: Killarney House & Gardens - the suggested access point for Killarney National Park", "visitor access point"),
    ([10154], 53.005028, -6.345286, "POPULATE", "owner: National Park Information Centre (NPWS/tourism visitor coordinates)", "information centre"),
    ([10684], 54.041570, -8.048706, "POPULATE", "owner: Acres Cove Marina, Drumshanbo - the suggested access point for Lough Allen", "marina"),
]

# Still unresolved: no coordinate to record, only the owner's reason.
REVIEW_ONLY = {
    10879: "Two candidate coordinates about 1 km apart; the main public entrance needs confirming",
    20905: "A location trace was found but not a sufficiently verified visitor entrance",
    10688: "Official address found; the exact cottage access point needs confirmation",
    10656: "A village reference point was found, not the specific heritage-attraction entrance",
    10708: "The town is known; the gallery entrance still needs confirmation",
    10732: "Need to distinguish the main beach car park from nearby facilities",
    20945: "Official address found; the exact visitor-entrance coordinate is not yet established",
    10785: "Multiple museums and buildings; one representative point must be selected",
    10664: "Need a preferred marina or visitor access point (Hodson Bay is a recognised public amenity but not chosen)",
    13866: "Need to decide whether to use the mainland cable-car station",
    10587: "Kilrush Marina is confirmed as the departure location; the exact marina pin is still to be confirmed",
    13867: "The public attraction is the Lusitania Museum & Old Head Signal Tower (Ballymackean, P17 T683); its entrance GPS is still outstanding",
    115357: "EN sibling of the Phoenix Park record: it shares `_leisure_export_uuid` with its PT master 10150, so the EN view resolves through the same identity and no separate coordinate is stored",
}


def main() -> int:
    manifest = json.load(open("/tmp/manifest-owner.json", encoding="utf-8"))
    by_id = {int(e["id"]): e for e in manifest}

    stats = {"populate": 0, "corroborated": 0, "review": 0, "not_corroborated": 0}

    for ids, lat, lng, decision, source, assessment in BATCH3:
        for pid in ids:
            entry = by_id.get(pid)
            if entry is None:
                print(f"WARNING: post {pid} not in the manifest", file=sys.stderr)
                continue

            dists = []
            for key in ("wikipedia", "osm"):
                src = entry.get(key) or {}
                if src.get("lat") is not None:
                    dists.append(hav(lat, lng, src["lat"], src["lng"]))
            nearest = round(min(dists), 1) if dists else None

            final = decision
            note = ""
            if decision == "CORROBORATE":
                if nearest is not None and nearest <= TOLERANCE_M:
                    final = "POPULATE"
                    note = f"; corroborated by an independent source within {nearest} m"
                    stats["corroborated"] += 1
                else:
                    final = "REVIEW"
                    note = "; not corroborated (nearest independent source " + (
                        f"{nearest} m away" if nearest is not None else "not found") + ")"
                    stats["not_corroborated"] += 1

            entry["owner_source"] = source
            entry["owner_assessment"] = assessment
            entry["owner_nearest_source_m"] = nearest
            entry["candidate_lat"] = lat
            entry["candidate_lng"] = lng

            if final == "POPULATE":
                entry["action"] = "POPULATE"
                entry["confidence"] = "HIGH"
                entry["proposed_lat"] = lat
                entry["proposed_lng"] = lng
                entry["reason"] = f"owner-supplied access point - {assessment}{note}"
                stats["populate"] += 1
            else:
                entry["action"] = "REVIEW"
                if entry.get("confidence") in (None, "HIGH"):
                    entry["confidence"] = "MEDIUM"
                entry["proposed_lat"] = None
                entry["proposed_lng"] = None
                entry["reason"] = f"owner-supplied candidate - {assessment}{note}"
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

    json.dump(manifest, open("/tmp/manifest-final.json", "w", encoding="utf-8"),
              ensure_ascii=False, indent=1)

    from collections import Counter
    print("actions:", Counter(e.get("action", "?") for e in manifest))
    pop = [e for e in manifest if e.get("action") == "POPULATE"]
    print("POPULATE entries:", len(pop), "unique posts:", len({e["id"] for e in pop}))
    print("batch3:", stats)
    return 0


if __name__ == "__main__":
    sys.exit(main())
