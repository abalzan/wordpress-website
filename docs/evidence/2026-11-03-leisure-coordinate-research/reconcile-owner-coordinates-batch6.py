#!/usr/bin/env python3
"""Reconcile the owner's sixth research batch (Cork) into the manifest.

Input : /tmp/manifest-batch5.json
Output: /tmp/manifest-batch6.json

The owner supplied a "reconciliation-ready" summary table and it is treated as
the authoritative recommendation (it is stricter than the prose in section 1):

  POPULATE  10188 Fota Wildlife Park  (official operator SatNav, HIGH)
            20907 Desmond Castle Kinsale (published GPS + independent point
                  within a few metres, HIGH)
            10603 Garnish Island - the owner's OWNER_DECISION resolved in favour
                  of the Blue Pool ferry departure point, consistent with the
                  island convention already applied to Aran Islands, Skellig
                  Michael and Clare Island. The alternative (the island point)
                  is recorded in the notes so it can be flipped in one line.
  REVIEW    10189 Jameson Distillery Midleton (verify the current visitor gate)
            10187 Titanic Experience Cobh (independent point ~60 m away)
            20908 Fota Arboretum & Gardens (site coordinate, entrance unresolved)
            20905 Annes Grove Gardens (exact public entrance not confirmed)
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


BATCH6 = [
    ([10188], 51.890600, -8.307720, "POPULATE", "owner: Fota Wildlife Park official operator SatNav / main entrance (fotawildlife.ie directions page)", "official sat-nav destination; the park's dedicated entrance is near Fota railway station"),
    ([20907], 51.707257, -8.524692, "POPULATE", "owner: Desmond Castle Kinsale published GPS (Failte Ireland) with an independent history source within a few metres; Heritage Ireland confirms Cork Street", "castle on Cork Street (site currently closed for conservation works; external view only)"),
    ([10603], 51.748777, -9.548521, "POPULATE", "owner decision: Garnish Island stored as the Blue Pool ferry departure point in Glengarriff (Failte Ireland lists both the Blue Pool embarkation point and the island)", "visitor access point - ferry departure; the island destination is 51.736770, -9.545526 and can be substituted if preferred"),
    ([10189], 51.913819, -8.168309, "REVIEW", "owner: Jameson Distillery Midleton (Outdooractive); an older Failte Ireland guide gives 51.9136, -8.1672, ~80 m away", "candidate - verify which point is the current visitor gate (Jameson confirms Distillery Walk, P25 Y394)"),
    ([10187], 51.849940, -8.295370, "REVIEW", "owner: Titanic Experience Cobh (Failte Ireland) at the White Star Line Building, 20 Casement Square; a local tourism guide gives 51.850285, -8.294663 ~60 m away", "candidate - reconcile the ~60 m offset and confirm the front entrance"),
    ([20908], 51.893461, -8.302749, "REVIEW", "owner: Fota Arboretum & Gardens - UCC published coordinate (the operator's own longitude text is malformed: W-8.3037369,569)", "candidate - identifies the arboretum, not the exact visitor entrance or car park"),
    ([20905], 52.194620, -8.465960, "REVIEW", "owner: Annes Grove Gardens GPS track (Heritage Ireland confirms Castletownroche, P51 K064, ~3 km from the Doneraile Road, with free onsite parking)", "candidate - the exact public entrance/parking point is not tied to this pair"),
]


def main() -> int:
    manifest = json.load(open("/tmp/manifest-batch5.json", encoding="utf-8"))
    by_id = {int(e["id"]): e for e in manifest}

    stats = {"populate": 0, "review": 0}

    for ids, lat, lng, decision, source, assessment in BATCH6:
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

            entry["owner_source"] = source
            entry["owner_assessment"] = assessment
            entry["owner_nearest_source_m"] = nearest
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

    json.dump(manifest, open("/tmp/manifest-batch6.json", "w", encoding="utf-8"),
              ensure_ascii=False, indent=1)

    from collections import Counter
    print("actions:", Counter(e.get("action", "?") for e in manifest))
    pop = [e for e in manifest if e.get("action") == "POPULATE"]
    print("POPULATE entries:", len(pop), "unique posts:", len({e["id"] for e in pop}))
    print("batch6:", stats)
    return 0


if __name__ == "__main__":
    sys.exit(main())
