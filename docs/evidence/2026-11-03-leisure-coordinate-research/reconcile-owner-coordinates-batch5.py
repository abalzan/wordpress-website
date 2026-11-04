#!/usr/bin/env python3
"""Reconcile the owner's fifth research batch (Dublin-focused) into the manifest.

Input : /tmp/manifest-batch4.json
Output: /tmp/manifest-batch5.json

Decision rules:
  POPULATE    the six "strongest candidates" (each sourced by two independent
              points or an official entrance record); the additional Dublin
              rows whose assessment names a specific building/museum location
              with a source and carries no uncertainty marker; and the Kerry and
              Wicklow rows on the owner's priority shortlist.
  CORROBORATE the remaining additional Dublin rows (mapped points): promoted
              only when an independent source agrees within 250 m.
  REVIEW      the four Dublin rows the owner asked to retain (Arbour Hill,
              Dun Laoghaire East Pier, Grangergorman, Royal Hospital
              Kilmainham), St Stephen's Green and Government Buildings pending
              corroboration, and the rows the owner explicitly left for
              confirmation (Dun Aonghasa's visitor centre, Dwyer-McAllister
              Cottage, Avondale Forest Park).
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


BATCH5 = [
    # ---- Dublin: strongest candidates (two independent points or an official entrance record)
    ([10549], 53.416000, -6.128000, "POPULATE", "owner: Portmarnock south beach car park (BirdWatch Ireland GPS; OSM beach parking ~30 m away)", "south beach car park"),
    ([20924], 53.343721, -6.274203, "POPULATE", "owner: St Audoen's Church and visitor centre (heritage source + archaeological record ~7 m apart; Heritage Ireland confirms Corn Market access)", "church and visitor centre"),
    ([20918], 53.283004, -6.281551, "POPULATE", "owner: Pearse Museum (two independent published points ~10 m apart)", "museum"),
    ([20916], 53.344745, -6.308281, "POPULATE", "owner: Irish National War Memorial Gardens, South Circular Road entrance gate (recorded gateway coordinates; OPW lists the entrances)", "South Circular Road entrance gate, not the garden centre"),
    ([20917], 53.336000, -6.262000, "POPULATE", "owner: Iveagh Gardens, Clonmel Street gate (Open House Dublin meeting point)", "Clonmel Street gate, not the park centre"),
    ([20923], 53.347474, -6.269293, "POPULATE", "owner: St Mary's Abbey Chapter House, Meetinghouse Lane (converted from the NIAH Irish Grid reference; Heritage Ireland confirms guided-tour access at 4 Meetinghouse Lane)", "Chapter House, Meetinghouse Lane"),
    # ---- Dublin: additional rows with a specific, sourced building/museum location
    ([20910], 53.371320, -6.227040, "POPULATE", "owner: Casino Marino mapped building point - essentially confirms the previous candidate 53.371338, -6.226992", "mapped building point, twice confirmed"),
    ([13860], 53.342200, -6.267450, "POPULATE", "owner: Chester Beatty museum point inside Dublin Castle", "museum location"),
    ([20911], 53.348660, -6.253170, "POPULATE", "owner: Custom House Visitor Centre building point, ~30 m from the previous candidate", "building point, twice confirmed"),
    ([10545], 53.372614, -6.276813, "POPULATE", "owner: Glasnevin Cemetery Museum published museum GPS; operator confirms the visitor centre and onsite parking on Finglas Road", "official visitor-centre location"),
    ([13863], 53.288650, -6.113640, "POPULATE", "owner: James Joyce Tower Museum at Sandycove Point", "tower museum"),
    ([20920], 53.352192, -6.299305, "POPULATE", "owner: Phoenix Park - People's Flower Gardens heritage-record coordinate", "garden location"),
    ([10146], 53.343940, -6.256800, "POPULATE", "owner: Trinity College Old Library building (the Book of Kells visitor attraction)", "Old Library building"),
    # ---- Dublin: mapped points - corroborate or keep REVIEW
    ([20914], 53.339211, -6.253563, "CORROBORATE", "owner: Government Buildings, coordinate derived from Irish Grid data", "building point"),
    ([20925], 53.338100, -6.259170, "CORROBORATE", "owner: St Stephen's Green park map point", "park map point"),
    ([13861], 53.289490, -6.113700, "CORROBORATE", "owner: The Forty Foot mapped bathing-place point", "mapped bathing place"),
    # ---- Dublin: the owner's retain-REVIEW list
    ([20909], 53.350420, -6.287190, "REVIEW", "owner: Arbour Hill Cemetery map feature", "candidate - the entrance needs checking"),
    ([10547], 53.294330, -6.128570, "REVIEW", "owner: Dun Laoghaire East Pier map point", "candidate - not necessarily the entrance"),
    ([20915], 53.360360, -6.305700, "REVIEW", "owner: Grangegorman Military Cemetery map feature", "candidate - the entrance needs checking"),
    ([20922], 53.343460, -6.302550, "REVIEW", "owner: Royal Hospital Kilmainham complex map point", "candidate - the visitor gate needs checking"),
    # ---- Kerry
    ([10197], 52.040799, -9.631520, "POPULATE", "owner: Gap of Dunloe - Kate Kearney's Cottage and car park (tourism and location sources independently publish this GPS)", "visitor starting point / car park"),
    ([10609], 51.886400, -10.365600, "POPULATE", "owner: Skellig Michael - Portmagee Marina departure point (two tour operators publish matching GPS)", "mainland departure point (the island is offshore)"),
    # ---- Wicklow
    ([10159], 53.144956, -6.155013, "POPULATE", "owner: Great Sugar Loaf - Red Lane car park / trailhead", "trailhead car park"),
    ([10561], 53.277150, -6.268290, "POPULATE", "owner: The Wicklow Way - Marlay Park official trail starting point", "official trail start"),
    ([20948], 52.961976, -6.564829, "REVIEW", "owner: Dwyer-McAllister Cottage building (NIAH Irish Grid conversion, consistent with another mapped building coordinate)", "candidate - conflicts with Visit Wicklow 52.964252, -6.568022; the entrance needs confirming"),
    ([10161], 52.911583, -6.219361, "REVIEW", "owner: Avondale Forest Park - official Beyond the Trees operator GPS", "candidate - three published points several hundred metres apart; the correct visitor entrance needs confirming"),
    # ---- Galway correction
    ([10174], 53.129930, -9.757360, "REVIEW", "owner: Dun Aonghasa visitor centre (OSM building); Heritage Ireland puts the centre ~1 km from the fort", "candidate - replaces the fort point; a final independent check is still needed"),
]


def main() -> int:
    manifest = json.load(open("/tmp/manifest-batch4.json", encoding="utf-8"))
    by_id = {int(e["id"]): e for e in manifest}

    stats = {"populate": 0, "corroborated": 0, "review": 0, "not_corroborated": 0}

    for ids, lat, lng, decision, source, assessment in BATCH5:
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

    json.dump(manifest, open("/tmp/manifest-batch5.json", "w", encoding="utf-8"),
              ensure_ascii=False, indent=1)

    from collections import Counter
    print("actions:", Counter(e.get("action", "?") for e in manifest))
    pop = [e for e in manifest if e.get("action") == "POPULATE"]
    print("POPULATE entries:", len(pop), "unique posts:", len({e["id"] for e in pop}))
    print("batch5:", stats)
    return 0


if __name__ == "__main__":
    sys.exit(main())
