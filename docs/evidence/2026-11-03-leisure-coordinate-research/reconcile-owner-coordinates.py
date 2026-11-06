#!/usr/bin/env python3
"""Reconcile the owner-supplied coordinate research into the manifest.

Inputs:
  /tmp/manifest.json  - the two-source research manifest (294 records)
  /tmp/cache.json     - the per-record source cache (wikipedia / osm points)

Rules (the owner's own assessment is the first signal, the repository's
verification rule the second):

  POPULATE    the owner supplied a clear, sourced access point
              (official / operator / OPW / Sport Ireland / tourism org, or two
              sources agreeing closely) with no uncertainty marker.
  CORROBORATE the owner's own note is a *candidate*, but it is checked here
              against the independent sources: it is promoted to POPULATE only
              when an independent source agrees within 250 m.
  REVIEW      everything else - an explicit "needs checking / confirm /
              not yet proven", a single map point, a lower-confidence lead, a
              large-area or multi-endpoint attraction, or the owner's own
              do-not-populate list. The candidate coordinate is still recorded.

Output: /tmp/manifest-owner.json (same shape as the research manifest, so the
CSV / owner-input / portable-JSON projection is unchanged).
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


# ids, lat, lng, decision, source (provenance), assessment
OWNER = [
    # ---- batch 1: clear, prioritised access points ----
    ([10883], 52.692097, -6.646664, "POPULATE", "owner: official sat-nav point (Huntington Castle); car park / main entrance", "car park / main entrance"),
    ([10889], 52.636843, -6.793677, "POPULATE", "owner: Nine Stones car park", "Nine Stones car park"),
    ([10887], 52.865393, -6.897960, "POPULATE", "owner: Oak Park forest-park car park", "forest park car park"),
    ([10690], 54.262980, -7.890357, "CORROBORATE", "owner: Cavan Burren Park visitor area", "visitor-area candidate; visitor centre nearby"),
    ([10694], 53.919820, -6.793000, "POPULATE", "owner: Dun a Ri forest-park car park", "forest park car park"),
    ([10692], 54.006700, -7.468134, "POPULATE", "owner: Killykeen main car park", "main car park"),
    ([10178], 52.944953, -9.063026, "POPULATE", "owner: official recommended visitor starting point, Corofin (Burren NP)", "official recommended visitor starting point"),
    ([10177], 52.972010, -9.425920, "POPULATE", "owner: official Cliffs of Moher visitor centre", "official visitor centre"),
    ([10595], 52.631415, -9.476517, "POPULATE", "owner: official GPS (Vandeleur Walled Garden)", "official GPS"),
    ([10601], 51.899880, -8.402870, "POPULATE", "owner: official visitor location (Blackrock Castle Observatory)", "official visitor location"),
    ([10192], 51.451600, -9.811097, "POPULATE", "owner: official Mizen Head visitor-centre GPS", "official visitor-centre GPS"),
    ([10151], 53.352833, -6.304694, "POPULATE", "owner: official GPS (Dublin Zoo)", "official GPS"),
    ([10543], 53.371700, -6.269600, "POPULATE", "owner: official vehicle access (National Botanic Gardens)", "official vehicle access"),
    ([20919], 53.366050, -6.330930, "POPULATE", "owner: OSM visitor-centre point matching the official location (Phoenix Park VC)", "OSM visitor-centre point"),
    ([10170, 10175], 53.550845, -9.945030, "POPULATE", "owner: Connemara NP visitor centre / Diamond Hill trailhead", "visitor-centre / trailhead"),
    ([13862], 53.461723, -10.023524, "POPULATE", "owner: Derrigimlagh main car park (independently corroborated)", "main car park"),
    ([20935], 53.388500, -9.617917, "POPULATE", "owner: official OPW GPS, converted from DMS (Pearse's Cottage)", "official OPW GPS"),
    ([10176], 53.259807, -9.074272, "CORROBORATE", "owner: Galway Atlantaquaria visitor-location candidate", "visitor-location candidate"),
    ([10768], 52.651811, -7.252209, "POPULATE", "owner: official GPS (Medieval Mile Museum)", "official GPS"),
    ([20942], 52.628983, -7.064710, "POPULATE", "owner: multiple sources agree closely (St Mary's, Gowran)", "multiple sources agree"),
    ([10774], 52.654334, -7.254240, "POPULATE", "owner: two sources agree closely; official address matches (Smithwick's Experience)", "two sources agree"),
    ([10629], 52.960389, -7.203389, "POPULATE", "owner: multiple sources agree closely (Timahoe Round Tower)", "multiple sources agree"),
    ([10633], 53.122720, -7.450260, "POPULATE", "owner: official trail register car park, Glenbarrow trailhead (Slieve Bloom)", "official trail register car park"),
    ([10686], 53.903160, -7.855420, "POPULATE", "owner: official GPS, estate entrance (Lough Rynn Castle)", "official GPS; estate entrance"),
    ([10843], 52.611581, -9.109658, "POPULATE", "owner: Foynes museum visitor location; independent map point very close", "visitor location"),
    ([10839], 52.668600, -8.623300, "CORROBORATE", "owner: tourism-guide coordinate (St Mary's Cathedral, Limerick)", "tourism-guide coordinate"),
    ([10654], 53.612260, -7.844691, "POPULATE", "owner: Corlea Trackway visitor-centre coordinate", "visitor-centre coordinate"),
    ([10756], 54.322668, -9.345669, "POPULATE", "owner: Sport Ireland car park / trailhead (Downpatrick Head)", "Sport Ireland car park / trailhead"),
    ([10754], 53.883986, -9.212476, "CORROBORATE", "owner: National Museum of Country Life visitor location", "visitor-location candidate"),
    ([10752], 53.800270, -9.546854, "POPULATE", "owner: published visitor-attraction GPS (Westport House)", "published visitor GPS"),
    ([10750], 53.967900, -10.194200, "POPULATE", "owner: verified Keem Bay car-park pin", "verified car-park pin"),
    ([10162], 53.694567, -6.446300, "POPULATE", "owner: Bru na Boinne visitor centre - the guided-tour access point for Newgrange", "visitor centre (not the monument)"),
    ([10571], 53.703644, -6.450270, "POPULATE", "owner: direct access to the Dowth mound", "direct access"),
    ([10164], 53.581300, -6.609630, "POPULATE", "owner: published Hill of Tara visitor/site coordinate", "published visitor coordinate"),
    ([10875], 53.711900, -6.350240, "POPULATE", "owner: two visitor sources agree closely (Millmount Fort)", "two sources agree"),
    ([10869], 53.777761, -6.417839, "POPULATE", "owner: Monasterboice site coordinate; public car park opposite", "site coordinate"),
    ([10736], 54.253200, -8.557830, "POPULATE", "owner: Sport Ireland trailhead car park (Queen Maeve's Trail)", "Sport Ireland trailhead car park"),
    ([10730], 54.361492, -8.501434, "POPULATE", "owner: Gortarowey forest entrance car park; independent source close", "forest entrance car park"),
    ([10744], 54.103450, -8.567550, "POPULATE", "owner: official Eagles Flying entrance GPS", "official entrance GPS"),
    ([10155], 53.010446, -6.323834, "POPULATE", "owner: Glendalough Visitor Centre (Upper Lake car park is a different point)", "visitor centre"),
    ([10157], 53.146700, -6.210500, "POPULATE", "owner: official Powerscourt Waterfall visitor access / car park", "official visitor access"),
    ([20947], 52.934100, -6.152900, "POPULATE", "owner: official GPS (National Botanic Gardens, Kilmacurragh)", "official GPS"),
    ([10807], 52.393600, -6.947600, "POPULATE", "owner: operator GPS (Dunbrody Famine Ship)", "operator GPS"),
    ([10805], 52.213542, -6.951708, "POPULATE", "owner: official operator sat-nav (Hook Lighthouse)", "official operator sat-nav"),
]

OWNER += [
    # ---- batch 2: operators + map-based candidates that still need checking ----
    ([13870], 53.995050, -7.361990, "REVIEW", "owner: map feature only (Cavan Cathedral)", "map feature; needs confirmation"),
    ([10698], 53.865225, -7.200880, "CORROBORATE", "owner: Cavan County Museum location; a heritage record places the building nearby", "museum location candidate"),
    ([10607], 52.219069, -8.580318, "POPULATE", "owner: published car park point (Doneraile Park)", "published car park point"),
    ([20907], 51.707400, -8.524600, "REVIEW", "owner: older tourism sat-nav (Desmond Castle, Kinsale)", "check current access"),
    ([10605], 51.849874, -8.293713, "POPULATE", "owner: Kennedy Pier slipway - Spike Island ferry terminal (not the Five Foot Way car park)", "ferry slipway"),
    ([10728], 55.158000, -7.892500, "POPULATE", "owner: Donegal Film Office public-access GPS (Ards Forest Park)", "public-access GPS"),
    ([20938], 55.004383, -7.903299, "POPULATE", "owner: Glebe Gallery GPS; on-site car parking", "gallery GPS"),
    ([20939], 54.929070, -7.808440, "REVIEW", "owner: map feature for the mill (Newmills Corn and Flax Mills)", "map feature; needs confirmation"),
    ([10722], 55.276580, -7.636290, "POPULATE", "owner: Fanad Head Lighthouse visitor-centre map point (distinct from the lighthouse)", "visitor-centre point"),
    ([10678], 54.340500, -8.367700, "POPULATE", "owner: published Glencar Waterfall location with on-site car park", "published location"),
    ([20931], 52.564700, -8.790000, "POPULATE", "owner: Adare Heritage Centre - required visitor access for castle tours", "visitor access point"),
    ([10764], 53.761803, -9.904518, "POPULATE", "owner: Roonagh Pier, mainland ferry departure for Clare Island", "ferry departure point"),
    ([10760], 53.801034, -9.521637, "POPULATE", "owner: Westport trailhead (Great Western Greenway; other end 53.934061, -9.917885)", "trailhead"),
    ([10674], 53.982051, -8.245974, "REVIEW", "owner: published Lough Key park/visitor-centre location", "needs entrance confirmation"),
    ([10670], 53.777940, -8.097939, "POPULATE", "owner: official operator coordinates (Strokestown Park)", "official operator"),
    ([10734], 54.250810, -8.519340, "REVIEW", "owner: published Carrowmore site coordinate", "confirm against visitor-centre entrance"),
    ([13864], 54.273040, -8.468420, "REVIEW", "owner: OSM building/location point (The Model)", "single map source"),
    ([10831], 52.305760, -8.104500, "POPULATE", "owner: official operator latitude/longitude (Mitchelstown Cave)", "official operator"),
    ([10795], 52.138240, -7.933187, "REVIEW", "owner: Lismore Castle Gardens site location", "entrance still needs checking"),
    ([10793], 52.240900, -7.214000, "POPULATE", "owner: official sat-nav coordinates (Mount Congreve Gardens)", "official sat-nav"),
    ([10789], 52.264343, -7.122586, "POPULATE", "owner: Grattan Quay start (Waterford Greenway; Dungarvan end 52.092490, -7.616950)", "trailhead"),
    ([13869], 52.321047, -6.933696, "REVIEW", "owner: UCC site location (JFK Arboretum)", "verify against the visitor entrance"),
    ([10158], 53.187170, -6.099170, "POPULATE", "owner: Sport Ireland trailhead at the Vevay Road steps (Bray Head)", "Sport Ireland trailhead"),
    ([10555], 52.877419, -6.061707, "CORROBORATE", "owner: Brittas Bay tourism location; separate south-beach car park ~270 m away", "two nearby points"),
    ([10161], 52.911839, -6.222067, "REVIEW", "owner: UCC site location (Avondale Forest Park)", "not yet proven to be the entrance"),
    # ---- batch 3 ----
    ([20912], 53.366100, -6.351600, "POPULATE", "owner: official OPW sat-nav coordinates (Farmleigh House and Gardens)", "official OPW"),
    ([10152], 53.388820, -6.074220, "POPULATE", "owner: trailhead at Howth railway station (Howth Cliff Walk)", "trailhead"),
    ([20913], 53.353987, -6.263853, "POPULATE", "owner: two map references identify closely matching points (Garden of Remembrance)", "two sources agree"),
    ([20924], 53.343400, -6.274200, "REVIEW", "owner: map location candidate (St Audoen's Church)", "confirm the precise public entrance"),
    ([20911], 53.348400, -6.253100, "REVIEW", "owner: visitor-location map candidate (Custom House Visitor Centre)", "confirm the precise public entrance"),
    ([10180], 53.089000, -9.143710, "REVIEW", "owner: OpenStreetMap attraction point (Aillwee Caves)", "entrance/parking still needs confirmation"),
    ([10182], 52.560890, -9.932180, "REVIEW", "owner: Loop Head lighthouse building point", "not explicitly the visitor car park"),
    ([10575], 53.269832, -9.053629, "POPULATE", "owner: Visit Galway visitor-location; museum confirms its Spanish Parade location", "tourism org + operator address"),
    ([10577], 53.385304, -9.212480, "CORROBORATE", "owner: Brigit's Garden tourism-directory candidate; OSM point ~70 m away; official private visitor parking", "corroborated by OSM within 70 m"),
    ([10714], 55.056490, -7.936860, "POPULATE", "owner: Glenveagh National Park visitor-centre building point", "visitor centre (not the park centre)"),
    ([10873], 54.050161, -6.191342, "REVIEW", "owner: Carlingford Lough Greenway has two endpoints - Carlingford Marina 54.050161, -6.191342 and Omeath Pier 54.088969, -6.257057", "needs one preferred starting point"),
    ([10680], 54.264639, -8.334046, "POPULATE", "owner: published Parke's Castle coordinate; OPW confirms visitor access and a car park", "published + OPW"),
    ([10835], 52.669671, -8.625577, "POPULATE", "owner: architectural plan and archaeological record within metres (King John's Castle)", "two independent records agree"),
    ([10639], 53.223583, -7.729028, "POPULATE", "owner: operator's published GPS, main visitor hub and car park (Lough Boora)", "operator GPS"),
    ([10635], 53.325930, -7.986970, "POPULATE", "owner: Visit Offaly published location, site access (Clonmacnoise; visitor centre closed for works)", "tourism org"),
    ([10641], 53.260460, -7.527920, "POPULATE", "owner: Visit Offaly published location (Charleville Castle)", "tourism org"),
    ([10637], 53.096704, -7.914320, "POPULATE", "owner: published Birr Castle visitor-entrance coordinate; another source ~55 m away; parking opposite", "visitor entrance"),
    ([10645], 53.475600, -7.367330, "POPULATE", "owner: official operator sat-nav coordinates (Belvedere House); parking onsite", "official operator"),
    ([10651], 53.748869, -7.273165, "POPULATE", "owner: Sport Ireland coordinates for Mullaghmeen Forest car park", "Sport Ireland car park"),
    ([10653], 53.758454, -8.091431, "CORROBORATE", "owner: map-directory coordinate (Glendeer Pet Farm); operator confirms address and onsite parking", "map-directory candidate"),
    ([10702], 54.318210, -6.895820, "CORROBORATE", "owner: official estate GPS candidate (Castle Leslie Estate)", "official estate GPS candidate"),
    ([10710], 54.002924, -6.580940, "REVIEW", "owner: tourism-directory candidate (Patrick Kavanagh Centre)", "final entrance check needed"),
    ([10704], 54.227745, -6.996775, "CORROBORATE", "owner: Rossmore Forest Park visitor car-park candidate", "car-park candidate"),
    ([10621], 52.338745, -9.835651, "CORROBORATE", "owner: Banna Strand beach car-park candidate; second beach source nearby", "car-park candidate"),
    ([10200], 52.142225, -9.981935, "CORROBORATE", "owner: Inch Beach car-park candidate; other beach references nearby", "car-park candidate"),
    ([20929], 52.132286, -10.461605, "POPULATE", "owner: Blasket Centre visitor-centre location (Dunquin Pier is a separate point)", "visitor centre"),
    ([10615], 51.763449, -10.128605, "POPULATE", "owner: Derrynane House visitor car-park point (the house is separately mapped)", "visitor car park"),
    ([10791], 52.142309, -7.365009, "POPULATE", "owner: official Copper Coast Geopark visitor-centre GPS", "official visitor-centre GPS"),
    ([10813], 52.356269, -6.422013, "REVIEW", "owner: Wexford Wildfowl Reserve visitor car-park candidate; NPWS publishes 52.359, -6.416 for the reserve area", "needs confirmation"),
    # ---- lower-confidence leads (owner: keep REVIEW) ----
    ([10627], 52.885278, -7.296000, "REVIEW", "owner: Heywood Gardens entrance-gate candidate", "lower confidence"),
    ([10781], 52.475281, -7.060336, "REVIEW", "owner: UCC site location (Woodstock Gardens)", "lower confidence; not confirmed as the entrance"),
    ([10160], 53.137792, -6.312171, "REVIEW", "owner: Sally Gap roadside-viewpoint candidate", "lower confidence"),
    ([10553], 52.979150, -6.037850, "REVIEW", "owner: published tourism location (Wicklow's Historic Gaol)", "lower confidence; needs an entrance check"),
]

# The owner's explicit "do not populate automatically yet" list. Kept REVIEW with
# the owner's own reason; no coordinate is recorded where none was supplied.
OWNER_REVIEW_ONLY = {
    10879: "Two published candidate points are ~1 km apart; the main public entrance needs confirming",
    10179: "Castle point and another visitor-location candidate differ by ~500 m; the visitor entrance must be selected",
    10847: "Available sources differ by ~2.5 km; a specific park entrance or main car park is needed",
    10716: "The official website publishes conflicting coordinates on two pages (54.635600, -8.645300 vs 52.469971, -6.869020); the operator must confirm the pin",
    20908: "The official website's coordinate text contains a formatting error (-8.3037369,569); needs confirmation",
    13867: "The published sat-nav coordinate belongs to Old Head Golf Links, not a verified public visitor entrance",
    10587: "The ferry operator confirms Kilrush Marina departures but the exact boarding-point coordinate is not established",
    13866: "Undecided whether the card should point to the mainland cable-car station or a point on the island",
    10173: "Represents several islands, not one visitor location; a specific island or ferry departure point must be chosen",
    10199: "Large geographical area; needs a specific visitor centre, trailhead or viewpoint",
    10198: "Large geographical area; needs a specific visitor centre, trailhead or viewpoint",
    10150: "Large area with multiple entrances; a preferred visitor access point must be chosen",
    115357: "EN sibling of the Phoenix Park record; same large-area decision pending",
    10684: "Large area; needs a preferred visitor access point",
    10664: "Large area; needs a preferred visitor access point",
    # "no sufficiently reliable point yet" - recorded by the owner
    20910: "No sufficiently reliable point established yet (owner: needs more research)",
    13861: "No sufficiently reliable point established yet (owner: needs more research)",
    20917: "No sufficiently reliable point established yet (owner: needs more research)",
    10545: "No sufficiently reliable point established yet (owner: needs more research)",
    10585: "No sufficiently reliable point established yet (owner: needs more research)",
    10682: "No sufficiently reliable point established yet (owner: needs more research)",
    20932: "No sufficiently reliable point established yet (owner: needs more research)",
}


def main() -> int:
    manifest = json.load(open("/tmp/manifest.json", encoding="utf-8"))
    cache = json.load(open("/tmp/cache.json", encoding="utf-8"))
    by_id = {int(e["id"]): e for e in manifest}

    owner_by_id = {}
    for ids, lat, lng, decision, source, assessment in OWNER:
        for pid in ids:
            owner_by_id[pid] = (lat, lng, decision, source, assessment)

    stats = {"populate": 0, "corroborated": 0, "review": 0, "not_corroborated": 0}

    for pid, (lat, lng, decision, source, assessment) in owner_by_id.items():
        entry = by_id.get(pid)
        if entry is None:
            print(f"WARNING: post {pid} not in the manifest", file=sys.stderr)
            continue
        slot = cache.get(str(pid), {})
        dists = []
        for key in ("wikidata", "osm"):
            src = slot.get(key) or {}
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
                note = (
                    "; not corroborated (nearest independent source "
                    + (f"{nearest} m away" if nearest is not None else "not found")
                    + ")"
                )
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

    for pid, reason in OWNER_REVIEW_ONLY.items():
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

    # Normalise the action of every remaining record.
    for entry in manifest:
        if "action" not in entry or entry.get("owner_deferred"):
            continue
        if entry["action"] == "POPULATE" and entry.get("confidence") != "HIGH":
            entry["action"] = "REVIEW"

    json.dump(manifest, open("/tmp/manifest-owner.json", "w", encoding="utf-8"),
              ensure_ascii=False, indent=1)

    from collections import Counter
    print("actions:", Counter(e.get("action", "?") for e in manifest))
    print("owner rows:", stats)
    return 0


if __name__ == "__main__":
    sys.exit(main())
