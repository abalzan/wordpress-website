# Report — Lazer coordinate research and population

| | |
|---|---|
| **Stage / task name** | Research and populate coordinates for every published `/lazer` attraction |
| **Date** | 2026-11-03 |
| **Author / agent** | Cline (AI coding agent) |
| **Branch** | `leisure-location` |
| **Start SHA** | `80340da8eb3ea9b96f37d6bf3b264bce28fc04c3` |
| **Final SHA** | uncommitted (working tree left dirty; see §3) |
| **Working tree at finish** | dirty — this task's files, plus one report (`docs/reports/2026-11-03-commemorative-events-architecture-audit.md`) that was already untracked before the task started and is **not ours** |
| **Skills followed** | `wp-content-change`, `wp-repository`, `wp-update-docs`, `wp-testing` |
| **Authoritative docs consulted** | `AGENTS.md`, `docs/engineering-standard.md`, `docs/content-model.md`, `docs/testing.md`, `scripts/README.md`, `docs/templates/plan.md`, `docs/templates/report.md`, `docs/plans/2026-11-03-leisure-coordinate-research.md` |

## 1. Scope completed

1. **Inventory (read-only).** Every published `leisure` record in the local
   WordPress: id, slug, title, language, county, town, category, address,
   official website, Discover Ireland URL, existing coordinates and the
   portable `_leisure_export_uuid`.
2. **Research.** Two independent, citable sources per attraction — the English
   Wikipedia article coordinate (batched title-variant resolution) and
   OpenStreetMap / Nominatim — with a strict agreement rule.
3. **Manifest.** A portable JSON manifest keyed on `_leisure_export_uuid`
   (`scripts/data/leisure-coordinates-2026-11-03.json`) plus a CSV projection
   for review (`docs/reports/2026-11-03-leisure-coordinate-research.csv`).
4. **Apply.** `scripts/apply-leisure-coordinates.php` — a new script on the
   shared bootstrap contract (dry-run default, `--apply`, `--verify`,
   `--manifest=<path>`, `--json`), writing only the four coordinate meta keys on
   `POPULATE` + `HIGH` entries.
5. **Population.** **All 289** unique physical attractions carry a
   visitor-access coordinate — 67 from the two-source pipeline and 222 from the
   owner-supplied research batches.
6. **Owner-input list.** `docs/reports/2026-11-03-leisure-coordinate-owner-input.md`
   — 5 rows, which are the 4 CI fixtures and the Phoenix Park EN sibling
   coordinate where one exists, and the exact information needed.

## 1a. The numbers

| Measure | Value |
|---|---|
| Published `leisure` records in the local database | 294 (293 PT + 1 EN) |
| CI test fixtures, not attractions | 4 (`[PH3C TEST] Lazer …`) |
| **Total unique physical attractions** | **289** (294 − 4 fixtures − 1 EN sibling) |
| Attractions researched | **289** (every published record was resolved) |
| Records with pre-existing coordinates | **0** |
| Existing coordinates preserved | **0** (there were none to preserve) |
| Invalid or questionable existing coordinates | **0** (there were none) |
| HIGH-confidence coordinates found by the two-source pipeline | **67** |
| Owner-supplied rows reconciled | **342** across eighteen reconciliation passes (222 accepted) |
| **Coordinates populated** | **289** posts = **289** unique physical attractions (**100%**) |
| Attractions still requiring owner input | **0** (the 5 coordinate-less rows are the 4 CI fixtures and the EN sibling) |
| Wikipedia source coverage | 162 / 294 records |
| OpenStreetMap source coverage | 214 / 294 records |

Confidence distribution over the 294 records: **HIGH 272 · MEDIUM 18 · NOT_FOUND 4** (the four NOT_FOUND rows are the CI fixtures).
Manifest actions: **POPULATE 289 · REVIEW 1 · OWNER_INPUT_REQUIRED 4**.
Of the 289 populated records, **17 are owner-approved at MEDIUM** — the owner decided the point but did not claim HIGH. They are counted in every apply run (`owner_approved`), so they can never be mistaken for automatically-verified HIGH records.

### Research method (short form)

1. **Source 1 — Wikipedia (en).** `action=query&prop=coordinates` over title
   variants (name, `&`→`and`, the head before a conjunction, `, County X`,
   `(County X)`, `(Ireland)`, and common suffixes), batched 40 titles per
   request — 5 164 variants in ~130 requests.
2. **Source 2 — OpenStreetMap / Nominatim.** One query per attraction at the
   documented ≤1 request/second, `countrycodes=ie`, preferring a result whose
   name matches and whose `class`/`type` is a discrete visitor POI.
3. **Agreement rule.** HIGH requires both sources to resolve the same named
   attraction, both coordinates inside the island-of-Ireland bounding box, and
   a separation ≤ **250 m**; plus the Wikipedia article must match the attraction
   name (token overlap ≥ 0.5) and the feature must not be inherently areal
   (island / peninsula / mountain range / scenic route / national park / beach /
   greenway) nor a whole-site OSM feature (`peak`, `park`, `nature_reserve`,
   `forest`, `wood`, `beach`, `bay`, `cliff`, `island`, …) — for those the mapped
   point is a site centre or a summit, not the visitor access point the task
   requires. Anything else is MEDIUM/LOW/NOT_FOUND and goes to the owner list —
   never a guess.
4. **Proposed value.** The arithmetic midpoint of the two source points, stored
   to 6 decimal places by the data model's own sanitizer.

### Storage and implementation

- Meta keys (unchanged data model, registered by `conexao-data-model`):
  `_leisure_lat`, `_leisure_lng` (canonical decimal strings, REST-visible) and
  `_leisure_coord_source` (provenance text, not REST-visible).
  `_leisure_coord_verified` was **not** written.
- Written through `update_post_meta()`, so the registered `sanitize_callback`
  (`Conexao_Data_Model_Meta::sanitize_latitude/longitude`) validated and
  normalised every value on the way in.
- Identity: `_leisure_export_uuid` (portable) → slug → title, reported as
  `stable-id=67, slug=0, title=0`. Local post IDs are never used as identity.

## 2. Scope NOT completed

- **No production change.** The manifest is prepared for a later, separately
  authorised production content change; nothing was written, uploaded or
  activated in production.
- **No coordinate was populated for 5 records**, and none of them is a real
  attraction: the 4 `[PH3C TEST]` CI fixtures (which must not carry editorial
  coordinates) and the **Phoenix Park EN sibling (115357)**, which is the same
  physical attraction as 10150 and shares its `_leisure_export_uuid`. Every real
  attraction is populated.
- **102 of the owner-supplied rows were deliberately kept at REVIEW**, including
  every row the owner flagged (Sliabh Liag's conflicting website coordinates,
  Bunratty, Altamont, Curraghchase, Fota Arboretum, Old Head of Kinsale, Scattery
  Island, Dursey Island, the multi-endpoint greenway, and the lower-confidence
  leads). See §5.
- **No `_leisure_coord_verified = 1`.** The research is a machine two-source
  result, not an editor's on-map confirmation, so the records stay in the
  documented `unverified` ("Por verificar") state. Claiming verification would be
  false.
- **The English sibling `phoenix-park-en` keeps no coordinate of its own**, for
  the reason in §5.

## 3. Files added / modified / deleted

| Path | Change | Note |
|---|---|---|
| `scripts/apply-leisure-coordinates.php` | added | the dry-run/apply/verify tool |
| `scripts/data/leisure-coordinates-2026-11-03.json` | added | the portable manifest the tool consumes (294 entries) |
| `scripts/data/leisure-coordinates-selftest.json` | added | 3-entry fixture used to prove the dry-run/`UNRESOLVED` paths |
| `scripts/README.md` | modified | catalogues the new script (script-contract gate) |
| `docs/plans/2026-11-03-leisure-coordinate-research.md` | added | the plan |
| `docs/reports/2026-11-03-leisure-coordinate-research.md` | added | this report |
| `docs/reports/2026-11-03-leisure-coordinate-research.csv` | added | the full manifest as CSV |
| `docs/reports/2026-11-03-leisure-coordinate-owner-input.md` | added | the owner-input list |
| `docs/README.md`, `docs/reports/README.md` | modified | index the new documents |
| `docs/evidence/2026-11-03-leisure-coordinate-research/` | added | research tooling + all machine evidence |

**10 added, 3 modified, 0 deleted.** No file under `wp-content/` was changed —
verified by `git status --short` (§9).

## 4. Runtime impact

WordPress now behaves differently in exactly one way: the `/lazer/` archive and
its proximity features can resolve a distance for 289 of the 293 attractions in
the PT listing (previously zero). The coordinate endpoint
`GET /conexao/v1/leisure-coordinates` returns `count: 293` with 289 non-null
pairs, and the archive's client-side proximity ordering now has data to sort on.

No template, CSS, JavaScript, route or REST surface changed: the consumers of
`Conexao_Data_Model_Meta::get_coordinates()` were already written and inert for
want of data (`docs/reports/2026-11-02-lazer-location-services.md`).

## 4a. Scope integrity

| Check | Result |
|---|---|
| No production write, deploy, upload or activation | **confirmed** |
| No production content, Polylang or database change | **confirmed** |
| No application behaviour change beyond the populated data | **confirmed** — `git status --short` shows no `wp-content/` change |
| No Flutter/mobile repository accessed | **confirmed** |
| No `.env` or secret change | **confirmed** |

## 5. Content / data impact

**Records created / deleted: 0.** Records updated: **289** published `leisure`
posts. Match strategy reported by the tool: `stable-id = 289`, `slug = 0`,
`title = 0` — every entry matched on `_leisure_export_uuid`, the portable
identifier, and none fell back to slug or title.

Per-record changes, proven by the before/after snapshot diff:

- 294 records compared, **289 changed, 5 unchanged**;
- **no** non-coordinate field changed (title, slug, language and portable UUID
  are byte-identical before and after);
- exactly **867** new meta rows: 289 × `_leisure_lat`, 289 × `_leisure_lng`,
  289 × `_leisure_coord_source`; `_leisure_coord_verified` count is **0**;
- every populated pair is complete, numeric, inside `-90..90` / `-180..180` and
  inside the island-of-Ireland bounding box; every one carries provenance;
- **no** record has only one of the pair.

### The owner-supplied batch (second phase)

The owner supplied 125 researched rows with an explicit point/assessment for
each. They were reconciled by **post ID** and classified on two signals: the
owner's own assessment first, the repository's verification rule second.

| Outcome | Rows | Basis |
|---|---|---|
| **POPULATE** | **76** | A clear, sourced access point (official site, operator, OPW, Sport Ireland, tourism organisation, or two sources agreeing closely) with no uncertainty marker |
| **REVIEW** | **49** | The owner's own "needs checking / confirm / not yet proven / map point only", a lower-confidence lead, a multi-endpoint or large-area entry, or the owner's explicit do-not-populate list |

Of the 76 accepted rows:

- **30 are additionally corroborated** by an independent source (the Wikipedia or
  OSM point) within 250 m — the candidate was checked against the pipeline's own
  evidence and promoted on the strength of it;
- **46 rest on the owner's cited source alone**. That is deliberate and is the
  point of the batch: for a car park, visitor centre or trailhead the mapped
  *centre* of the feature is legitimately further than 250 m away, so the
  two-source agreement rule cannot be applied to those points. Their provenance
  records the owner's stated source, and it is listed in the CSV
  (`coordinate_source_1`) so each can be audited.

Rows the owner flagged were **never** promoted, even when the pipeline
corroborated them within a few metres — for example `custom-house-visitor-centre`
(7.5 m), `sally-gap` (9.2 m), `desmond-castle-kinsale` (16.5 m) and
`aillwee-caves` (19.8 m) all stayed REVIEW because the owner's own note says the
precise public entrance is still unconfirmed.

The owner's specific findings that shaped the outcome:

- **Sliabh Liag (10716)** — the official site publishes `54.635600, -8.645300`
  on one page and `52.469971, -6.869020` on another for the same visitor centre.
  The second is geographically impossible for Donegal. **Neither was imported**;
  the operator must confirm the pin.
- **Bunratty Castle & Folk Park (10179)** — a visitor-location candidate and a
  heritage building record for the castle differ by ~500 m. Kept REVIEW until the
  visitor entrance is chosen.
- **Fota Arboretum & Gardens (20908)** — the official coordinate text contains a
  formatting error (`-8.3037369,569`). Not imported.
- **Old Head of Kinsale (13867)** — the published sat-nav belongs to the golf
  links, not a verified public visitor entrance. Not imported.
- **Multi-endpoint and ferry entries** — Carlingford Lough Greenway (10873) has
  two official endpoints; the Blasket Centre (20929, imported) is deliberately
  **not** Dunquin Pier; Clare Island uses Roonagh Pier (10764, imported) as the
  mainland departure point; Spike Island uses the Kennedy Pier slipway (10605,
  imported), not the distant car park.
- **Large areas** — Phoenix Park (10150 and its EN sibling 115357), Lough Allen
  (10684), Lough Ree (10664), the Aran Islands (10173), the Ring of Kerry
  (10198) and the Dingle Peninsula (10199) were all left REVIEW: one point would
  be arbitrary without a chosen access point.
- **Newgrange (10162)** was populated with the **Brú na Bóinne visitor centre**,
  not the monument, because public access is by guided tour from the centre — the
  correct visitor access point by the task's own definition.

### The owner-supplied batch 3 (third phase)

44 further rows were reconciled the same way. **19 were accepted** and **26 kept
at REVIEW**, plus the standing unresolved list.

**Accepted (19).** Twelve newly identified, clearly-sourced visitor locations —
Mountshannon Pier for Holy Island / Inis Cealtra (10589), the official Lullymore
entrance (10861), Ballycroy Visitor Centre (10762), the Sonairte Eco-Centre
(10569), the Kells Courthouse tourism hub as the trail start (10567), the
Tullamore D.E.W. visitor location (10643), Jerpoint Park's published GPS
(10779), Abbeyleix Heritage House with its visitor parking (10631), Athlone
Castle (10647, the owner's independent archaeological coordinate agrees
closely), The Main Guard (20944), the Ardmore round tower itself (10797) and the
Belturbet station building (10700) — plus the owner's **seven suggested access
points for the large-area attractions**, which is exactly the resolution the
previous round asked for:

| Attraction | Post | Access point now stored |
|---|---|---|
| Phoenix Park | 10150 | Phoenix Park Visitor Centre |
| Aran Islands | 10173 | Rossaveel Ferry Terminal |
| Dingle Peninsula | 10199 | Dingle Pier |
| Ring of Kerry | 10198 | Killarney House & Gardens |
| Killarney National Park | 10193 | Killarney House & Gardens |
| Wicklow Mountains National Park | 10154 | National Park Information Centre |
| Lough Allen | 10684 | Acres Cove Marina, Drumshanbo |

**Kept at REVIEW (26).** Ten rows the owner explicitly marked "still requiring
review" (Acres Lake Boardwalk, James Joyce Tower, St Mary's Abbey, Casino Marino,
Trinity College Old Library, Fota Arboretum, Coumshingaun Loop, The Vee & Bay
Lough, Kilbeggan Distillery, Lough Muckno) — each with its candidate recorded —
plus the three nearer-resolved conflicts (Sliabh Liag 10716 with the credible
`54.635600, -8.645300` candidate; Bunratty 10179 with the castle coordinate,
which is *not* the visitor entrance; Carlingford Lough Greenway 10873 with the
Carlingford Marina endpoint), and thirteen rows still lacking any usable
coordinate.

**Still unresolved (13, owner input required).** Altamont Gardens (two candidates
~1 km apart), Annes Grove Gardens, Seán Mac Diarmada Cottage, Ardagh Heritage
Village, Carrickmacross Lace Gallery, Strandhill Beach, Roscrea Castle, Waterford
Treasures, Lough Ree, Dursey Island, Scattery Island (Kilrush Marina confirmed as
the departure location, exact pin outstanding), Old Head of Kinsale (the public
attraction is the **Lusitania Museum & Old Head Signal Tower**, Ballymackean,
P17 T683; its entrance GPS is outstanding), and the Phoenix Park EN sibling
115357.

**Data observation for the owner:** the Polylang pair `phoenix-park` (10150) and
`phoenix-park-en` (115357) **share the same `_leisure_export_uuid`**
(`ci-fixture-0000-0000-4000-8000-000000000002`). That is a CI-fixture artifact
and a genuine identity defect — a portable identifier must be unique. It means
the EN sibling cannot receive its own coordinate through the UUID-first matching
order; the EN view of Phoenix Park therefore inherits nothing. It does not affect
any other record. Fixing it is a separate data change, not part of this task.

### The owner-supplied batch 4 (fourth phase)

29 further rows. **21 were accepted** and **8 kept at REVIEW**.

**Accepted (21).** Fourteen strongly-sourced visitor locations — Roscrea Castle
(20945), Doolin Cave visitor centre (10181), Croagh Patrick's **trailhead car
park** (10746), Lough Gur visitor centre and parking (10845), Kerry Cliffs
(10613), the **Lusitania Museum & Old Head Signal Tower** (13867, which resolves
the earlier golf-links rejection), **Kilrush Marina** for Scattery Island (10587,
which closes the boarding-point gap), Donadea forest car park (10859), the Irish
National Stud entrance (10849), Lahinch beach access (10183), Slane Castle
(10166), Aughnanure Castle (20934), Desmond Castle Newcastlewest (20932) and
Coole Park's visitor-centre area (10579, corroborated by an independent source)
— plus five rows where the owner's §4 recommendation **is** the supplied
candidate: Dursey Island's mainland cable-car station (13866), Valentia Island's
Knightstown terminal (10611), Lough Ree's Hodson Bay Marina (10664), the Medieval
Museum for Waterford Treasures (10785) and Torc Waterfall's lower N71 car park
(10196). The Sky Road viewpoint/car park (10172) and the National Design & Craft
Gallery (13871) were promoted on independent corroboration.

**Croagh Patrick is the clearest vindication of the strict rule.** The earlier
pipeline had put the point on the *summit*; that was demoted after the Murrisk
Development Association's published trailhead (`53.77935, -9.63700`) showed the
car park 2.6 km away. The owner's Sport Ireland car-park coordinate
(`53.778110, -9.639078`) is **194 m** from that independent trailhead — two
independent sources agreeing well inside the 250 m rule. The record now points at
the place visitors actually park.

**Kept at REVIEW (8).** Annes Grove Gardens, Carrickmacross Lace Gallery, Dwyer
McAllister Cottage (conflicting published coordinates), Strandhill Beach,
Pollardstown Fen (NPWS identifies the reserve, not the access point), Dún
Aonghasa (the owner recommends the *visitor centre*, ~1 km from the fort, whose
coordinates are not yet confirmed), Kells Priory (exact pin to be checked) and
Spanish Arch & Long Walk (a correct reference point that does not represent the
whole Long Walk).

**Section 4 decisions taken.** Dursey Island → mainland cable-car station;
Valentia Island → Knightstown ferry terminal; Lough Ree → Hodson Bay Marina;
Waterford Treasures → the Medieval Museum (the umbrella attraction's
representative point); Strandhill Beach and Dún Aonghasa → left REVIEW because
the recommended point is a *different* location that has not been supplied.

### The owner-supplied batch 5 (Dublin-focused)

27 rows. **23 accepted** and **7 kept at REVIEW**.

**Accepted — Dublin (13).** The six the owner prioritised, each backed by two
independent points or an official entrance record: Portmarnock south beach car
park (10549, BirdWatch Ireland GPS and OSM parking ~30 m apart), St Audoen's
Church and visitor centre (20924, two records ~7 m apart), Pearse Museum (20918,
two published points ~10 m apart), the Irish National War Memorial Gardens
**South Circular Road entrance gate** (20916, the recorded gateway rather than
the garden centre), the Iveagh Gardens **Clonmel Street gate** (20917) and the
St Mary's Abbey **Chapter House on Meetinghouse Lane** (20923, converted from the
NIAH Irish Grid reference and confirmed by Heritage Ireland). Plus seven further
Dublin rows whose point is a specific building or museum with a named source:
Casino Marino (20910, the mapped point confirms the earlier candidate), Chester
Beatty (13860), Custom House Visitor Centre (20911, ~30 m from the previous
candidate), Glasnevin Cemetery Museum (10545, published museum GPS and operator
confirmation), James Joyce Tower (13863), the Phoenix Park People's Flower
Gardens (20920) and the Trinity College Old Library (10146). Government
Buildings (20914), St Stephen's Green (20925) and The Forty Foot (13861) were
promoted on independent corroboration.

**Accepted — Kerry and Wicklow (4).** The Gap of Dunloe is now anchored at
**Kate Kearney's Cottage and car park** (10197) rather than the pass itself;
Skellig Michael at the **Portmagee Marina departure point** (10609, two tour
operators publishing matching GPS — the island is offshore and is not where a
visit starts); Great Sugar Loaf at the **Red Lane car park / trailhead** (10159);
and The Wicklow Way at the **Marlay Park official trail start** (10561).

**Kept at REVIEW (7).** The four the owner asked to retain — Arbour Hill
Cemetery (20909), Dún Laoghaire East Pier (10547), Grangegorman Military
Cemetery (20915) and Royal Hospital Kilmainham (20922) — plus Dún Aonghasa
(10174, corrected from the fort to the **visitor centre** at `53.129930,
-9.757360`, still needing one independent check), Dwyer-McAllister Cottage
(20948, the NIAH point preferred over Visit Wicklow's but the entrance is
unconfirmed) and Avondale Forest Park (10161, three published points several
hundred metres apart).

**Noted for the owner.** The Dublin pattern is now consistent: wherever the
listing is a **park or a complex**, the stored point is a named gate or
entrance (Clonmel Street, South Circular Road, Meetinghouse Lane, the Marlay
Park trail start, the Red Lane trailhead), never the middle of the site. The
four retained rows are exactly the ones where no entrance record exists yet.

### The owner-supplied batch 6 (Cork)

7 rows — the owner's count is correct and mine was not: the outstanding Cork set
is seven attractions, not six. The owner supplied a "reconciliation-ready"
summary table, and it was treated as authoritative because it is **stricter than
the prose** in the same message: three rows the prose called "HIGH candidates"
are marked REVIEW in the table pending entrance verification.

**Accepted (3).**

| Attraction | Post | Point stored | Basis |
|---|---|---|---|
| Fota Wildlife Park | 10188 | `51.890600, -8.307720` | The park's own directions page publishes this SatNav point as the official driving destination |
| Desmond Castle, Kinsale | 20907 | `51.707257, -8.524692` | Fáilte Ireland GPS with an independent history source within a few metres; Heritage Ireland confirms Cork Street (the site is closed for conservation works and is external-view only — the coordinate still represents the attraction) |
| Garnish Island | 10603 | `51.748777, -9.548521` | **Owner decision applied**: the Blue Pool ferry departure point in Glengarriff, consistent with the island convention already used for Aran Islands (Rossaveel), Skellig Michael (Portmagee) and Clare Island (Roonagh Pier) |

The island destination point (`51.736770, -9.545526`) is recorded in that
record's notes, so switching to "the island itself" is a one-line change if the
owner prefers it.

**Kept at REVIEW (4).** Jameson Distillery Midleton (10189, the current visitor
gate needs confirming; an older Fáilte Ireland guide differs by ~80 m), Titanic
Experience Cobh (10187, an independent local point ~60 m away — reconcile the
offset), Fota Arboretum & Gardens (20908, UCC's point identifies the arboretum,
not the entrance; the operator's own longitude text is malformed), and Annes
Grove Gardens (20905, no source ties that pair to the public entrance/parking).

**Note recorded:** Fota Arboretum (20908) and Fota Wildlife Park (10188) are
different attractions on the same estate and must not share a point. They are
now distinct: the wildlife park has the operator's SatNav entrance; the
arboretum stays REVIEW.

### The owner-supplied batch 7 (Donegal + Monaghan)

11 rows. **4 accepted** and **7 kept at REVIEW** — following the owner's
section-3 reconciliation summary exactly.

**Accepted (4).**

| Attraction | Post | Point stored | Basis |
|---|---|---|---|
| Errigal | 10726 | `55.025047, -8.089997` | The dedicated **R251 trailhead car park** (Hiking Donegal ties these coordinates to the trailhead parking) — the access point, not the summit |
| Sliabh Liag | 10716 | `54.635600, -8.645300` | The **Sliabh Liag Visitor Centre** (operator Eircode F94 W8KC publishes 54.6356 N, 8.6453 W; parking and shuttle access to the cliffs). This **supersedes** the earlier conflicting-page question: the second official page's `52.469971, -6.869020` remains geographically impossible for Donegal |
| Castle Leslie Estate | 10702 | `54.318210, -6.895820` | The estate's own directions page publishes this exact SatNav coordinate — the strongest coordinate in the Monaghan batch |
| Patrick Kavanagh Centre | 10710 | `54.002924, -6.580940` | The centre/visitor entrance; the operator confirms Inniskeen A91 KT20 with parking directly across the road |

**Kept at REVIEW (7).** Doagh Famine Village (13865 — the OSM attraction point
is close to another published GPS, but the visitor entrance/parking pin needs
checking), Malin Head (10720 — Banba's Crown viewpoint/parking is a reasonable
candidate but Malin Head covers a larger area with several access points),
Newmills Corn & Flax Mills (20939 — an archaeological record gives
`54.929234, -7.806959`, ~100 m from the candidate; the visitor entrance/parking
should take precedence over a generic site point), Carrickmacross Lace Gallery
(10708 — Market Square/A81 K300 confirmed, no authoritative entrance pin), Lough
Muckno (13868 — which public entrance/parking point it represents is unconfirmed)
and Sliabh Beagh (10712 — a visitor/trailhead point at Braggan/Knockatallon off
the L1000, not a definitive point for the whole borderland area).

**Monaghan County Museum (10706) has no coordinate and needs a new one.** The
owner established that the museum **moved to The Peace Campus, H18 RP20** (not
the older Hill Street address), with underground parking and lift access. No
coordinate was supplied, so the record is flagged with that reason rather than
carrying a stale town-centre pin — an important catch, because a "reused old
coordinate" would have been a silent wrong answer.

### The owner-supplied batch 8 (Dublin + Wexford)

12 rows. **10 accepted** and **2 kept at REVIEW** — and both of the owner's open
decisions were resolved in the same message, so nothing was left hanging.

**Accepted (10).**

| Attraction | Post | Point stored | Basis |
|---|---|---|---|
| Arbour Hill Cemetery | 20909 | `53.350325, -6.286782` | GPS pin at the cemetery itself (not Collins Barracks); Heritage Ireland confirms the Arbour Hill address |
| Dún Laoghaire East Pier | 10547 | `53.294330, -6.128570` | The mapped East Pier feature, near the inland end — the pier is a long walking route, so the far lighthouse is not the access point |
| Grangegorman Military Cemetery | 20915 | `53.360360, -6.305700` | Site coordinate; Heritage Ireland confirms the Blackhorse Avenue address |
| **Áras an Uachtaráin** | 20926 | `53.366050, -6.330930` | **Owner decision applied**: the **Phoenix Park Visitor Centre**, because tours are ticketed there. This is deliberately the same point already stored for the Phoenix Park Visitor Centre record (20919) — the two records now share the correct tour meeting point |
| Ballyhack Castle | 20936 | `52.245850, -6.967226` | National Monuments Service excavation coordinate for the castle itself |
| **Curracloe Beach** | 10811 | `52.388350, -6.365580` | **Owner decision applied**: **White Gap** accepted as the representative entrance (the council lists Ballinesker, White Gap and Culletons Gap, White Gap being the largest) |
| Ferns Castle | 10815 | `52.590500, -6.499200` | OPW SatNav coordinates from a visitor guide; the castle is reported closed until 2027 but the coordinate is the site |
| **Saltee Islands** | 10809 | `52.171024, -6.588825` | The **Kilmore Quay ferry departure point**, not Great Saltee — consistent with the island convention already applied to Aran Islands, Skellig Michael, Clare Island and Garnish Island |
| JFK Arboretum | 13869 | `52.321047, -6.933696` | UCC tree-site coordinate for the arboretum near New Ross |
| Wexford Wildfowl Reserve | 10813 | `52.356250, -6.421700` | The **visitor-centre GPS** from the reserve's own brochure (the NPWS `52.359, -6.416` is a broader reserve reference) |

**Kept at REVIEW (2).** Royal Hospital Kilmainham (20922 — the mapped grounds
point; the OPW's Military Road pedestrian east gate is not precisely pinned), and
the **Phoenix Park EN sibling (115357)**, which the owner correctly identifies as
**not a separate attraction**. It shares `_leisure_export_uuid` with its PT
master 10150, so the UUID-first match resolves both to the same record; the
sibling's entry carries that explanation rather than an independent coordinate.

### The owner-supplied batch 9 (Sligo + Wicklow)

10 rows. **All 10 accepted** — the owner resolved both REVIEW cases in the same
message, so Sligo and Wicklow are now complete.

**Sligo (5).**

| Attraction | Post | Point stored | Basis |
|---|---|---|---|
| Carrowmore | 10734 | `54.250900, -8.519300` | OPW visitor-brochure SatNav; Heritage Ireland confirms the visitor centre, parking and facilities |
| Drumcliffe | 10742 | `54.326400, -8.493600` | OPW visitor-brochure point for the church and Yeats' Grave; the church guide confirms the churchyard and parking |
| Lissadell House | 10740 | `54.346936, -8.580878` | **Owner decision**: accept the published Fáilte Ireland site coordinate. The estate describes its entrance as the third after Carney village, opposite the Anglican church, but does not pin the gate; the estate is closed to the public until 29 May 2027 |
| Strandhill Beach | 10732 | `54.270400, -8.602750` | **Owner decision**: the main promenade / car park is the representative point — **with a recorded caveat** (below) |
| The Model | 13864 | `54.273040, -8.468420` | Existing pin retained as supported; the venue confirms Eircode F91 TP20, the building and parking on Connaughton Road |

**Wicklow (5).** Avondale Forest Park (10161, the operator's own directions —
which finally resolves the earlier three-point ambiguity in favour of the
operator), Brittas Bay Beach (10555, Wicklow County Tourism GPS), Dwyer
McAllister Cottage (20948, the Buildings of Ireland Irish Grid conversion for the
cottage itself, which beats the tourism point 400 m away), Sally Gap (10160, the
tourism GPS for the crossroads/pass — the correct interpretation for a road
junction) and Wicklow's Historic Gaol (10553, the tourism GPS; a heritage Irish
Grid conversion gives a point ~30 m away on the gaol).

**One caveat recorded deliberately.** For **Strandhill Beach** the owner chose the
"main promenade / car park" as the representative point, but also noted that the
supplied candidate came from a Sligo County Council *playground* guide and does
not by itself establish the car park. The coordinate is stored because the owner
made the decision and it sits on the Strandhill seafront, but the provenance
records both facts so a precise car-park pin can replace it in one line. This is
the only row in the whole task where the stored point and the owner's named point
are not provably the same thing, and it is labelled as such rather than presented
as verified.

### The owner-supplied batch 10 (Clare + Cork)

10 rows. **4 accepted** and **6 kept at REVIEW** — following the owner's closing
suggestion literally: accept the high-confidence points, review the
entrance-focused ones, and decide on Loop Head.

**Accepted (4).**

| Attraction | Post | Point stored | Basis |
|---|---|---|---|
| Aillwee Caves | 10180 | `53.089180, -9.143677` | Published GPS for the cave attraction |
| Spanish Point Beach | 10591 | `52.843178, -9.432872` | **Clare County Council** official beach location (parking, toilets, access) — preferred over the community-site GPS |
| Jameson Distillery Midleton | 10189 | `51.913819, -8.168309` | The existing candidate confirmed by the published Midleton Distillery Experience coordinates; Jameson confirms Distillery Walk, P25 Y394, parking outside the main gates |
| **Loop Head Lighthouse** | 10182 | `52.563000, -9.920500` | The owner asked the agent to decide: accepted, because it is a **HIGH-confidence official tourism SatNav** and the existing candidate (`52.56089, -9.93218`) is offset from the lighthouse visitor area. The site has dedicated parking |

**Kept at REVIEW (6).** Bunratty (10179 — the candidate is now the **visitor
entrance/parking** point `52.695300, -8.813900`, replacing the castle-centre pin
`52.701260, -8.812342`, but it rests on parking evidence rather than official
operator GPS), Ennis Friary (10585 — an approximate Abbey Street entrance/site
point, upgraded from "no coordinate" to a REVIEW candidate), Kilkee Cliffs
(10184 — the Diamond Rocks Café cliff-walk access/parking area), Annes Grove
(20905 — the map pin replaces the 170 m-away candidate but the entrance/parking
alignment is unconfirmed), Fota Arboretum (20908 — the existing UCC pin is
**deliberately kept**, and Fota House directs visitors to the House/Gardens car
park rather than the Wildlife Park) and Titanic Experience Cobh (10187 — the
local Cobh visitor-information pin recorded as the candidate, with the 30–40 m
source offset noted).

**Two candidate replacements worth flagging**, because they change *which* point
represents the attraction rather than just refining it:

- **Bunratty** now points at the **visitor entrance/parking** instead of the
  castle centre — the correct access point, but still REVIEW pending confirmation.
- **Loop Head** now points at the **lighthouse visitor location** instead of the
  offset cliff-area candidate — accepted on the official SatNav.

### The owner-supplied batch 11 (Limerick + Longford + Monaghan)

12 rows. **7 accepted** and **5 kept at REVIEW** — following the owner's
section-4 table and closing recommendation.

**Accepted (7).**

| Attraction | Post | Point stored | Basis |
|---|---|---|---|
| Adare | 10841 | `52.564560, -8.789674` | The Adare Heritage Centre visitor point with free parking behind it — the practical village navigation hub |
| Askeaton Castle | 20930 | `52.600449, -8.973985` | Archaeological records for the castle entrance/approach (OPW reports the castle closed, external viewing only) |
| **Curraghchase Forest Park** | 10847 | `52.592520, -8.867855` | **Sport Ireland's trail start and car park** (grid R 412 495) — this **resolves the earlier ~2.5 km discrepancy**; UCC's point is ~500 m away and is a general park location |
| St Mary's Cathedral | 10839 | `52.668600, -8.623300` | The existing candidate confirmed by Limerick's visitor guide as the cathedral SatNav coordinate |
| Granard Motte | 10658 | `53.775370, -7.500610` | Monument point on the hilltop. The owner's condition ("accept if the listing represents the Motte") is **satisfied**: the record is titled *Granard Motte & Bailey*, categorised História — the monument itself, not the Knights & Conquests centre at the foot of the Motte (recorded as a distinct location) |
| Royal Canal Greenway | 10660 | `53.722520, -7.797660` | **Greenways Ireland** lists this as the Longford main trailhead and confirms parking at the five main trailheads — preferable to an arbitrary point on a linear greenway |
| Sliabh Beagh | 10712 | `54.323730, -7.101280` | Peatland Passport Braggan GPS off the L1000 — a trail/visitor access point, not a summit |

**Kept at REVIEW (5).** Ardagh Heritage Village (10656 — the source is the
surrounding village, not a verified Heritage Centre entrance), Newcastle Wood
(10662 — UCC's point is a general woodland reference; Coillte confirms the **Inny
Bridge car park** is the access but no exact pin is published), Carrickmacross
Lace Gallery (10708 — the A81 K300 address is clear, the entrance pin is not),
Lough Muckno (13868 — the candidate is now the **Hope Castle car park**, replacing
the earlier approximate park pin) and **Monaghan County Museum (10706 — the Peace
Campus approximate pin**, with an explicit note that the old Hill Street location
must not be used).

### The owner-supplied batch 12 (Tipperary + Waterford)

8 rows. **All 8 accepted** — Tipperary and Waterford are now complete.

This batch was written specifically against the pipeline state, and it is the
clearest example of the owner rejecting a weak pipeline point rather than
accepting it:

| Attraction | Post | Point stored | What the pipeline had offered |
|---|---|---|---|
| Swiss Cottage | 10823 | `52.357305, -7.923365` | The OSM attraction polygon — **not** an entrance. Buildings of Ireland Irish Grid `205271, 122810` converted; OPW confirms parking and R670 access |
| Glen of Aherlow | 10825 | `52.429250, -8.167670` | The OSM **viewpoint** node — not the park access. Sport Ireland publishes Christ the King as the Millennium Stone Loop start, with Discover Ireland confirming the Nature Park entrance and parking |
| The Vee & Bay Lough | 10827 | `52.242200, -7.956810` | none. **Owner decision applied**: The Vee viewpoint car park, because the listing leads with "The Vee" |
| Ormond Castle | 10829 | `52.345280, -7.407136` | The OSM **guidepost** — a sign, not the castle. Castle coordinates from geotagged evidence, OSM building/attraction and archaeology |
| House of Waterford Crystal | 10783 | `52.259326, -7.106593` | OSM attraction geometry `52.25912, -7.10685`. The **visitor GPS** and main entrance on The Mall preferred; parking at Bolton Street |
| Lismore Castle Gardens | 10795 | `52.138240, -7.933187` | none. UCC-published garden/site coordinate; visitors park in the town car park ~150 m away |
| Coumshingaun Loop | 10799 | `52.239230, -7.502340` | none. Confirmed as the **named Coumshingaun Lake car park / Kilclooney Wood trailhead** on the R676 — this closes the earlier "car park or lake centre?" uncertainty |
| Dungarvan Castle | 20940 | `52.088981, -7.616245` | OSM castle geometry `52.089360, -7.616380`. The **archaeology coordinate** preferred; the earlier `52.088500, -7.621500` proposal was too far west |

**The Vee / Bay Lough choice is recorded in full.** The stored point is The Vee
viewpoint car park, as the owner decided. The Bay Lough Loop / Killballyboy Wood
official Sport Ireland trailhead (`52.263080, -7.971860`, grid S 01970 12339) is
kept in that record's notes, because these are two genuinely distinct visitor
destinations and the choice is a one-line reversal.

**Four pipeline points were explicitly rejected in this batch** (OSM attraction
polygon, OSM viewpoint, OSM guidepost, OSM attraction geometry). None of them was
ever promoted, which is the behaviour the REVIEW state exists to enforce.

### The owner-supplied batch 13 (Clare + Cork)

6 rows. **4 accepted** and **2 kept at REVIEW** — Clare is now complete.

| Attraction | Post | Disposition | Point stored / candidate |
|---|---|---|---|
| Bunratty Castle & Folk Park | 10179 | **Accepted** | `52.695300, -8.813900` — the **visitor parking/entrance area**, not the castle centre. Bunratty confirms free visitor parking and GPS address V95 TY02 |
| Ennis Friary | 10585 | **Accepted** | `52.845978, -8.981586` — the published SatNav coordinate on Abbey Street (OPW confirms V95 AN28), replacing the rounded candidate |
| **Kilkee Cliffs** | 10184 | **Correction accepted** | `52.681780, -9.665030` — the **Diamond Rocks Café / West End walk-start area**, replacing the cliff-viewpoint pin. The old pin was not geographically wrong; it was simply less practical as the primary visitor map pin |
| Fota Arboretum & Gardens | 20908 | **Accepted** | `51.893461, -8.302749` — UCC's exact coordinate, explicitly confirmed as the Arboretum/Gardens and **not** Fota Wildlife Park (which has its own separate entrance point at 10188) |
| Annes Grove Gardens | 20905 | **REVIEW** (owner choice) | `52.196140, -8.466130` — the OSM garden-feature point; OPW confirms onsite free parking but publishes no entrance coordinate. The reported trail start (`52.1946, -8.46596`) may be the arrival area, so the entrance-vs-garden-point choice is left open |
| Titanic Experience Cobh | 10187 | **REVIEW**, value preserved | `51.850285, -8.294663` — the existing pin kept **unchanged**, with the ~60 m Fáilte Ireland discrepancy recorded. Deliberately not changed on the older GPS alone |

**Two points worth keeping in view for later passes:** the **Annes Grove**
entrance-vs-garden-point choice, and the **Titanic Experience** ~60 m source
discrepancy — neither is a data error, both are genuinely unresolved, and both
stay in the owner-input list.

### The owner-supplied batch 14 (Donegal + Kildare)

6 rows. **All 6 accepted** — Donegal and Kildare are now complete.

| Attraction | Post | Point stored | Basis |
|---|---|---|---|
| Doagh Famine Village | 13865 | `55.309020, -7.335630` | Candidate retained. Operator confirms F93 PK19, onsite parking and a visitor centre; the alternate tourism GPS ~150 m west is not a reason to replace a better-aligned candidate |
| Malin Head | 10720 | `55.380948, -7.373576` | **Banba's Crown** visitor point, aligning with the upper car park beside the signal tower — not the abstract cape centre |
| Newmills Corn & Flax Mills | 20939 | `54.929070, -7.808440` | Candidate retained and the **earlier open question closed**: the OPW SatNav (54.9292, -7.8082) corroborates it, and the excavation coordinate `54.929234, -7.806959` belongs to a proposed development/testing site **east of the mill complex** — rejected for visitor use |
| Bog of Allen Nature Centre | 10853 | `53.278610, -6.942560` | The **building** point, preferred over the generic OSM community-centre polygon (R51 V293) |
| Mondello Park | 10863 | `53.257500, -6.745000` | **Owner-approved** address-based venue point (Donore, Naas, W91 T957) — see below |
| Pollardstown Fen | 10855 | `53.183591, -6.845753` | Candidate retained; NPWS publishes `53.1835908, -6.8457528` and its car-park meeting point `53.183600, -6.8457752`, i.e. the visitor access, not a wetland centroid |

**A new, narrow, documented exception in the apply tool.** Mondello Park is the
first entry the owner has **explicitly approved by hand at MEDIUM confidence** —
the operator confirms the address, Eircode, directions and onsite parking, but
the exact gate coordinate remains approximate. Rather than mislabel that as HIGH
or quietly drop it, the manifest carries `owner_approved: true` and the tool
gained one narrow, counted exception:

- `POPULATE` + `HIGH` is still the only automatic path;
- a `POPULATE` entry with `owner_approved` may be written at MEDIUM, because that
  is a **human decision about a named access point**, not an automatic promotion
  of an unverified coordinate;
- every such write is counted in a dedicated `owner_approved` summary field, so
  it can never pass unnoticed (the batch-14 apply reports `owner_approved=1`).

### Defect found and repaired: the stored provenance contradicted the stored point

While verifying batch 14 the stored `_leisure_coord_source` was inspected and
**was wrong for every owner-supplied row**. The formatting helper only knew the
pipeline's two sources, so a record whose point came from the owner still said
e.g. *"Wikipedia: Cliffs of Moher; OpenStreetMap: way/215488389; two-source
agreement; **4370 m apart**"* — a sentence that contradicts the coordinate it
describes. The coordinates were correct; the audit field was not.

Two fixes, both applied and verified:

1. **The helper now prefers the entry's `owner_source`.** A pipeline entry keeps
   the two-source text; an owner entry records the owner's stated source (and,
   for Mondello Park, the `[owner-approved at MEDIUM confidence]` marker).
2. **The apply now reconciles the provenance text on already-populated rows**
   (`provenance_repaired`), so the audit field converges instead of keeping a
   source that contradicts the point. The repair is idempotent: the following
   run reports `unchanged=259, provenance_repaired=0`.

Evidence: `dry-run-provenance-repair.txt` (planned 192 repairs, `written=0`),
`apply-run-30-provenance-repair.txt` (`provenance_repaired=192, unchanged=67`),
`apply-run-31-provenance-idempotence.txt` (`unchanged=259`). Only
`_leisure_coord_source` was touched — the snapshot diff still shows **no
non-coordinate field changed** and no coordinate value moved.

### The owner-supplied batch 15 (Kilkenny + Monaghan)

6 rows. **All 6 accepted** — Kilkenny and Monaghan are now complete, bringing
the finished counties to eleven.

| Attraction | Post | Point stored | Confidence | Basis |
|---|---|---|---|---|
| Castlecomer Discovery Park | 10778 | `52.807080, -7.202140` | **HIGH** | Sport Ireland's Visitor Centre car park (the loop start/finish) — much stronger than the OSM park polygon, which was not used |
| **Woodstock Gardens** | 10781 | `52.475540, -7.057800` | **HIGH** | **Correction applied** — Sport Ireland's gardens car park, replacing the offset candidate `52.475281, -7.060336`. The operator confirms access is through the paid car park, not the estate gateway |
| Kells Priory | 20941 | `52.536800, -7.268000` | MEDIUM (owner-approved) | Candidate retained — OPW confirms the L1023 car park is the access; the ruins are farther north-east |
| Carrickmacross Lace Gallery | 10708 | `53.978000, -6.719000` | MEDIUM (owner-approved) | Market Square / A81 K300 retained; the nearby NIAH Market House coordinates are **not** the Gallery and were not substituted |
| Lough Muckno Leisure Park | 13868 | `54.119120, -6.724540` | MEDIUM (owner-approved) | The Hope Castle car park — the park arrival point, **not** the lake centre and **not** Hope Castle itself |
| Monaghan County Museum | 10706 | `54.251823, -6.969879` | MEDIUM (owner-approved) | **Correction applied** — the Peace Campus coordinate from the official campus map, replacing the earlier approximation. Hill Street is explicitly excluded |

**How the confidence vocabulary was kept honest.** The owner gave **HIGH** for
two rows and **MEDIUM / MEDIUM-HIGH** for four, while accepting all six. Rather
than inflate the four to HIGH, they are recorded as **MEDIUM** with
`owner_approved: true` — the conservative reading of the owner's own hedge
("rather than claim absolute precision") — and the apply reports
**`owner_approved=5`** (Mondello Park plus these four). The count is visible in
every run, so an owner-approved MEDIUM can never be mistaken for an
automatically-verified HIGH.

### The owner-supplied batch 16 (nine 2-attraction counties)

18 rows. **16 accepted** and **2 kept at REVIEW** — twenty counties are now
complete, and the Carlingford endpoint decision is closed.

**Classification rule used for this batch** (stated so it can be checked):

| Owner's own confidence | Action |
|---|---|
| **HIGH** | POPULATE at HIGH — the only automatic path (9 rows) |
| **MEDIUM-HIGH**, or **MEDIUM with a clear recommendation and no review marker** | POPULATE at **MEDIUM + owner_approved** (7 rows) |
| **MEDIUM with an explicit "needs owner review" / "owner should confirm"** | **REVIEW**, candidate recorded (2 rows) |

**Accepted at HIGH (9):** Cavan Burren Park (10690 — the Geopark's main car
park, replacing the earlier candidate), Cavan Cathedral (13870 — with the
explicit note that this is Cavan town's cathedral, **not** Kilmore, and the
unrelated bus-stop point was not used), Sky Road (10172 — the IHPA
car-park/viewpoint, a meaningful visitor stop despite sitting on the road), Dún
Aonghasa (10174 — the visitor centre, not the remote fort), National Museum
Country Life (10754), Lough Key (10674), Roscommon Castle (10676 — new
archaeological site point), Glendeer Pet Farm (10653), and **Seán Mac Diarmada
Cottage (10688 — the cottage itself at Laghty Barr, explicitly not the
Kiltyclogher Heritage Centre)**.

**Accepted at MEDIUM + owner-approved (7):** Heywood Gardens (10627), Acres Lake
Boardwalk (10682 — a car park/arrival point, correct for a linear boardwalk),
Newcastle Wood (10662 — with the west-negative longitude sign corrected),
King John's Castle Carlingford (10891), Kilbeggan Distillery (10649), Ardagh
Heritage Village (10656 — a village-level location, not a building entrance), and
the **Carlingford Lough Greenway (10873)**.

**Owner decision resolved — Carlingford Lough Greenway.** The owner explicitly
selected **Carlingford Marina** (`54.050161, -6.191342`, the Carlingford-side
trailhead from the Sport Ireland greenway listing). **Omeath Pier
(`54.088969, -6.257057`) is deliberately not used.** The record's provenance
states the decision and the source verbatim, the entry carries
`owner_approved: true`, and `_leisure_coord_verified` remains **0** — the
owner-approved and independently-verified states stay distinct. This closes a
decision that had been open since the first Cork/Louth pass.

**Kept at REVIEW (2), with candidates recorded:** **Rock of Dunamase (10623)** —
the car park near the entrance, which the owner explicitly flagged for review
(the monument itself is available as `53.031783, -7.210743`) — and **Achill
Island (10748)** — the Achill Sound bridge/gateway candidate, where the owner
asked whether a bridge/gateway pin is wanted at all for a large island
destination.

### The final owner-supplied batch (the 8 remaining attractions)

8 rows. **5 accepted** and **3 left at REVIEW** — deliberately, because the
research author explicitly declined to owner-approve those three "without your
confirmation", and the owner confirmed only one of the four proposed decisions.

**Accepted (5).**

| Attraction | Post | Point stored | Confidence | Basis |
|---|---|---|---|---|
| Titanic Experience Cobh | 10187 | `51.850285, -8.294663` | HIGH | White Star Line Building, 20 Casement Square; Cobh Guide's address-linked GPS. The Fáilte Ireland point ~60 m away refers to the same attraction |
| Royal Hospital Kilmainham | 20922 | `53.343460, -6.302550` | HIGH | The OPW-confirmed **Military Road pedestrian east gate** (OPW warns the approach is steep) — not the grounds centre |
| Banna Strand | 10621 | `52.338745, -9.835651` | HIGH | IrishBeaches' explicitly **verified car-park pin**, not the beach centre |
| Loughcrew | 10167 | `53.744100, -7.118800` | MEDIUM (owner-approved) | The **car park at the foot of the trail**. The cairn point `53.744677, -7.112322` lies uphill and the pipeline's residential-road hit was rejected. MEDIUM-HIGH in the owner's assessment, so MEDIUM + `owner_approved` |
| **Rock of Dunamase** | 10623 | `53.032400, -7.208000` | MEDIUM (owner-approved) | **Owner decision applied — Option A, the visitor car park / arrival point.** Option B (the monument, `53.031783, -7.210743`) is explicitly rejected and recorded as such |

**Left at REVIEW (3), with candidates recorded — awaiting the owner's confirmation:**

- **Altamont Gardens (10879)** — four published points differ substantially:
  Direction Ireland `52.735567, -6.720599`, Tuatha `52.735152, -6.720796`, a
  regional-guide SatNav `52.738400, -6.720700`, and UCC's site coordinate
  `52.744497, -6.725076` about 1 km north. The recommendation is the visitor
  parking/entrance area.
- **Annes Grove Gardens (20905)** — the trail-recorded visitor entrance
  `52.194620, -8.465960` rather than the previously stored garden point
  `52.196140, -8.466130`.
- **Achill Island (10748)** — the Achill Sound / Michael Davitt Bridge gateway
  `53.926170, -9.936690`; the owner asked to confirm that the site should
  represent a large island by its bridge rather than a destination on it.

The apply reports **`owner_approved=14`** (Mondello Park, the four batch-15 rows,
the seven batch-16 rows and Loughcrew + Rock of Dunamase), so owner-approved
MEDIUM records remain distinguishable from automatically-verified HIGH ones.

### Final reconciliation — the three confirmed owner decisions

The owner confirmed all three rows the research author had declined to
owner-approve without confirmation. Per the owner's instructions each was
resolved from REVIEW to POPULATE at **MEDIUM** (not promoted to HIGH) with
`owner_approved: true`, and the decision plus the visitor-access rationale were
written into the provenance:

| Attraction | Post | Point stored | Rejected alternative |
|---|---|---|---|
| Altamont Gardens | 10879 | `52.735567, -6.720599` (visitor parking/entrance) | UCC's site coordinate `52.744497, -6.725076` (~1 km north, a site not an entrance) |
| Annes Grove Gardens | 20905 | `52.194620, -8.465960` (visitor entrance/arrival) | The previously stored garden point `52.196140, -8.466130` |
| Achill Island | 10748 | `53.926170, -9.936690` (Achill Sound / Michael Davitt Bridge gateway) | — the gateway is the chosen representation of a large island |

`_leisure_coord_verified` remains **0** for all three, the duplicate Phoenix Park
`_leisure_export_uuid` was **not touched**, and the apply reported
`owner_approved=17`.

### Result: 289 / 289 unique attractions populated (100%)

### Apply history (including the mid-task correction)

1. **Dry run (pipeline batch)** — `planned=76, written=0`, and a direct
   `SELECT COUNT(*)` on `_leisure_lat`/`_leisure_lng` returned `0` afterwards.
2. **Apply 1** — `written=75, unchanged=1, gate=0`. The `unchanged=1` is the
   Polylang pair `phoenix-park` (10150) ↔ `phoenix-park-en` (115357), which
   **shares the same `_leisure_export_uuid`** (a CI fixture artifact); the
   documented UUID-first matching order resolved both manifest entries to the
   same post, so the second was a no-op. One physical attraction, one coordinate
   entry.
3. **Classification tightened after an independent spot-check.** The Murrisk
   Development Association publishes the Croagh Patrick trailhead coordinate as
   `53.77935, -9.63700`; the two research sources had agreed on the *summit*,
   2.6 km away. The rule was therefore extended: an OSM whole-site feature
   (`peak`, `park`, `nature_reserve`, `forest`, `wood`, `beach`, `bay`, `cliff`,
   `island`, …) can never be HIGH, because its mapped point is a site centre or
   a summit rather than the visitor access point the task requires. That
   reclassified **9 entries / 8 unique attractions** (`phoenix-park`,
   `phoenix-park-en`, `slane-castle`, `dun-aonghasa`, `brigits-garden`,
   `croagh-patrick`, `irish-national-stud-japanese-gardens`,
   `irish-national-war-memorial-gardens`, `royal-hospital-kilmainham`) from HIGH
   to MEDIUM/REVIEW.
4. **Correction applied.** The three coordinate keys were deleted from the 8
   posts that had been populated under the old rule (24 meta rows), and the
   manifest, the CSV and the owner-input list were regenerated.
5. **Apply 3** — `written=0, unchanged=67, gate=0`.
6. **Owner batch reconciled** — 76 rows accepted, 49 kept REVIEW; the manifest,
   CSV, portable JSON and owner-input list regenerated.
7. **Dry run (owner batch)** — `populate_entries=143, planned=76, written=0,
   unchanged=67, gate=0`. Zero writes.
8. **Apply 4 (final)** — `written=76, unchanged=67, conflicts=0, invalid=0,
   unresolved=0, with_after=143, gate=0`.
9. **Apply 5 (idempotence)** — `written=0, unchanged=143, gate=0`.
10. **Owner batch 3 reconciled** — 19 rows accepted, 26 kept REVIEW; manifest,
    CSV, portable JSON and owner-input list regenerated.
11. **Dry run (batch 3)** — `populate_entries=162, planned=1, written=0,
    unchanged=161, gate=0`. Zero writes.
12. **Apply 6 (final, batch 3)** — `written=1, unchanged=161, conflicts=0,
    invalid=0, unresolved=0, with_after=162, gate=0`.
13. **Apply 7 (idempotence)** — `written=0, unchanged=162, gate=0`.
14. **Owner batch 4 reconciled** — 21 rows accepted, 8 kept REVIEW.
15. **Dry run (batch 4)** — `populate_entries=183, planned=21, written=0,
    unchanged=162, gate=0`. Zero writes.
16. **Apply 8 (final)** — `written=21, unchanged=162, conflicts=0, invalid=0,
    unresolved=0, with_after=183, gate=0`.
17. **Apply 9 (idempotence)** — `written=0, unchanged=183, gate=0`.
18. **Owner batch 5 reconciled** — 23 rows accepted, 7 kept REVIEW.
19. **Dry run (batch 5)** — `populate_entries=203, planned=20, written=0,
    unchanged=183, gate=0`. Zero writes.
20. **Apply 10 (final)** — `written=20, unchanged=183, conflicts=0, invalid=0,
    unresolved=0, with_after=203, gate=0`.
21. **Apply 11 (idempotence)** — `written=0, unchanged=203, gate=0`.
22. **Owner batch 6 (Cork) reconciled** — 3 rows accepted, 4 kept REVIEW.
23. **Dry run (batch 6)** — `populate_entries=206, planned=3, written=0,
    unchanged=203, gate=0`. Zero writes.
24. **Apply 12 (final)** — `written=3, unchanged=203, conflicts=0, invalid=0,
    unresolved=0, with_after=206, gate=0`.
25. **Apply 13 (idempotence)** — `written=0, unchanged=206, gate=0`.
26. **Owner batch 7 (Donegal + Monaghan) reconciled** — 4 rows accepted,
    7 kept REVIEW.
27. **Dry run (batch 7)** — `populate_entries=210, planned=4, written=0,
    unchanged=206, gate=0`. Zero writes.
28. **Apply 14 (final)** — `written=4, unchanged=206, conflicts=0, invalid=0,
    unresolved=0, with_after=210, gate=0`.
29. **Apply 15 (idempotence)** — `written=0, unchanged=210, gate=0`.
30. **Owner batch 8 (Dublin + Wexford) reconciled** — 10 rows accepted,
    2 kept REVIEW (both decisions resolved by the owner).
31. **Dry run (batch 8)** — `populate_entries=220, planned=10, written=0,
    unchanged=210, gate=0`. Zero writes.
32. **Apply 16 (final)** — `written=10, unchanged=210, conflicts=0, invalid=0,
    unresolved=0, with_after=220, gate=0`.
33. **Apply 17 (idempotence)** — `written=0, unchanged=220, gate=0`.
34. **Owner batch 9 (Sligo + Wicklow) reconciled** — 10 rows accepted,
    0 kept REVIEW (both decisions resolved by the owner).
35. **Dry run (batch 9)** — `populate_entries=230, planned=10, written=0,
    unchanged=220, gate=0`. Zero writes.
36. **Apply 18 (final)** — `written=10, unchanged=220, conflicts=0, invalid=0,
    unresolved=0, with_after=230, gate=0`.
37. **Apply 19 (idempotence)** — `written=0, unchanged=230, gate=0`.
38. **Owner batch 10 (Clare + Cork) reconciled** — 4 rows accepted,
    6 kept REVIEW.
39. **Dry run (batch 10)** — `populate_entries=234, planned=4, written=0,
    unchanged=230, gate=0`. Zero writes.
40. **Apply 20 (final)** — `written=4, unchanged=230, conflicts=0, invalid=0,
    unresolved=0, with_after=234, gate=0`.
41. **Apply 21 (idempotence)** — `written=0, unchanged=234, gate=0`.
42. **Owner batch 11 (Limerick + Longford + Monaghan) reconciled** —
    7 rows accepted, 5 kept REVIEW.
43. **Dry run (batch 11)** — `populate_entries=241, planned=7, written=0,
    unchanged=234, gate=0`. Zero writes.
44. **Apply 22 (final)** — `written=7, unchanged=234, conflicts=0, invalid=0,
    unresolved=0, with_after=241, gate=0`.
45. **Apply 23 (idempotence)** — `written=0, unchanged=241, gate=0`.
46. **Owner batch 12 (Tipperary + Waterford) reconciled** — 8 rows accepted,
    0 kept REVIEW.
47. **Dry run (batch 12)** — `populate_entries=249, planned=8, written=0,
    unchanged=241, gate=0`. Zero writes.
48. **Apply 24 (final)** — `written=8, unchanged=241, conflicts=0, invalid=0,
    unresolved=0, with_after=249, gate=0`.
49. **Apply 25 (idempotence)** — `written=0, unchanged=249, gate=0`.
50. **Owner batch 13 (Clare + Cork) reconciled** — 4 rows accepted,
    2 kept REVIEW.
51. **Dry run (batch 13)** — `populate_entries=253, planned=4, written=0,
    unchanged=249, gate=0`. Zero writes.
52. **Apply 26 (final)** — `written=4, unchanged=249, conflicts=0, invalid=0,
    unresolved=0, with_after=253, gate=0`.
53. **Apply 27 (idempotence)** — `written=0, unchanged=253, gate=0`.
54. **Owner batch 14 (Donegal + Kildare) reconciled** — 6 rows accepted
    (1 of them owner-approved at MEDIUM), 0 kept REVIEW.
55. **Dry run (batch 14)** — `populate_entries=259, planned=6, written=0,
    unchanged=253, owner_approved=1, gate=0`. Zero writes.
56. **Apply 28 (final)** — `written=6, unchanged=253, conflicts=0, invalid=0,
    unresolved=0, owner_approved=1, with_after=259, gate=0`.
57. **Apply 29 (idempotence)** — `written=0, unchanged=259, gate=0`.
58. **Provenance defect found during batch-14 verification** — the stored
    `_leisure_coord_source` contradicted the stored point for all 192
    owner-supplied rows.
59. **Dry run (provenance repair)** — `provenance_planned=192, written=0`.
    Zero writes.
60. **Apply 30 (provenance repair)** — `provenance_repaired=192,
    unchanged=67, gate=0`. Only `_leisure_coord_source` changed.
61. **Apply 31 (idempotence)** — `unchanged=259, provenance_repaired=0, gate=0`.
62. **Owner batch 15 (Kilkenny + Monaghan) reconciled** — 6 rows accepted
    (4 of them owner-approved at MEDIUM), 0 kept REVIEW.
63. **Dry run (batch 15)** — `populate_entries=265, planned=6, written=0,
    unchanged=259, owner_approved=5, gate=0`. Zero writes.
64. **Apply 32 (final)** — `written=6, unchanged=259, conflicts=0, invalid=0,
    unresolved=0, owner_approved=5, with_after=265, gate=0`.
65. **Apply 33 (idempotence)** — `written=0, unchanged=265, gate=0`.
66. **Owner batch 16 (nine 2-attraction counties) reconciled** — 16 rows
    accepted (7 owner-approved at MEDIUM), 2 kept REVIEW; the Carlingford
    Marina owner decision resolved.
67. **Dry run (batch 16)** — `populate_entries=281, planned=16, written=0,
    unchanged=265, owner_approved=12, gate=0`. Zero writes.
68. **Apply 34 (final)** — `written=16, unchanged=265, conflicts=0, invalid=0,
    unresolved=0, owner_approved=12, with_after=281, gate=0`.
69. **Apply 35 (idempotence)** — `written=0, unchanged=281, gate=0`.
70. **Final owner batch reconciled** — 5 rows accepted (2 owner-approved at
    MEDIUM), 3 left at REVIEW because the research author explicitly declined
    to owner-approve them without the owner's confirmation.
71. **Dry run (final batch)** — `populate_entries=286, planned=5, written=0,
    unchanged=281, owner_approved=14, gate=0`. Zero writes.
72. **Apply 36 (final)** — `written=5, unchanged=281, conflicts=0, invalid=0,
    unresolved=0, owner_approved=14, with_after=286, gate=0`.
73. **Apply 37 (idempotence)** — `written=0, unchanged=286, gate=0`.
74. **The owner confirmed all three remaining decisions** (Altamont Gardens,
    Annes Grove Gardens, Achill Island).
75. **Final dry run** — `populate_entries=289, planned=3, written=0,
    unchanged=286, owner_approved=17, gate=0`. Zero writes.
76. **Apply 38 (FINAL)** — `written=3, unchanged=286, conflicts=0, invalid=0,
    unresolved=0, owner_approved=17, with_after=289, gate=0`.
77. **Apply 39 (idempotence)** — `written=0, unchanged=289, gate=0`.
78. **Result: 289 / 289 unique attractions populated (100%).**

**Existing coordinates.** The pre-apply snapshot recorded **0** records with any
coordinate and **0** with an invalid value, so nothing was preserved and nothing
was silently replaced. No accepted owner row collided with an already-stored
value: this was checked before each apply, and every apply reported
`conflicts=0`. The script's conflict rule (preserve a differing existing value,
report it, never overwrite) was exercised by the self-test.

## 6. Polylang impact

- PT content is unchanged: only the four coordinate meta keys were written, on
the PT master records; no title, slug, content, excerpt, taxonomy or translation
link changed.
- The translation relationship is intact: the `post_translations` term for the
pair still reads `a:2:{s:2:"pt";i:10150;s:2:"en";i:115357;}`.
- No EN record was created; no duplicate physical attraction was created.
- No language-scoped cache key or invalidation rule changed (coordinates are not
part of any cache key).

## 7. Route / HTTP impact

No route, redirect, canonical, hreflang or sitemap change. The one observable
HTTP effect is the coordinate dataset:

```
GET http://localhost:8081/wp-json/conexao/v1/leisure-coordinates
-> 200, {"lang":"pt","count":293, ...} with 289 items carrying a lat/lng pair
```

## 8. Production actions

| Action | Performed? | Detail |
|---|---|---|
| Production write | **no** | |
| Deploy / upload | **no** | |
| Plugin activation | **no** | |
| Content or DB mutation | **no** | the only database writes were to the local Docker WordPress |

The local apply ran against the Compose stack (`http://localhost:8080` site URL,
container published on `8081`), which the bootstrap classifies as `local`. The
script is `production_capable`, so the run required `--confirm-production`; the
run header prints the resolved target and its classification before any write.

## 9. Verification commands and results

| Command | Exit | Result |
|---|---|---|
| `php -l scripts/apply-leisure-coordinates.php` | 0 | no syntax errors |
| `apply-leisure-coordinates.php --manifest=… --dry-run` | 0 | `planned=76, written=0, with_before=0, with_after=0, gate=0`; `SELECT COUNT(*)` on `_leisure_lat`/`_leisure_lng` = **0** immediately afterwards |
| `apply-leisure-coordinates.php --manifest=… --apply --confirm-production` | 0 | `written=75, unchanged=1, conflicts=0, invalid=0, unresolved=0, match_stable_id=76, with_after=75, gate=0` (before the §5 correction) |
| `apply-leisure-coordinates.php --manifest=… --apply --confirm-production` (second run) | 0 | `written=0, unchanged=76, with_after=75, gate=0` — **idempotent** |
| `apply-leisure-coordinates.php --manifest=… --apply --confirm-production` (final run, corrected manifest) | 0 | `populate_entries=67, written=0, unchanged=67, with_after=67, gate=0` — database and manifest agree |
| `apply-leisure-coordinates.php --manifest=… --dry-run` (owner batch) | 0 | `populate_entries=143, planned=76, written=0, unchanged=67, with_before=67, with_after=67, gate=0` — **zero writes** |
| `apply-leisure-coordinates.php --manifest=… --apply --confirm-production` (owner batch) | 0 | `written=76, unchanged=67, conflicts=0, invalid=0, unresolved=0, match_stable_id=143, with_after=143, gate=0` |
| `apply-leisure-coordinates.php --manifest=… --apply --confirm-production` (owner batch, second run) | 0 | `written=0, unchanged=143, with_after=143, gate=0` — **idempotent** |
| `apply-leisure-coordinates.php --verify` | 0 | `published_leisure=294, with_coordinates=289, verified_flag_set=0, missing_coordinates=5, invalid_coordinates=0` |
| before/after snapshot diff | — | 294 compared, 289 changed, 5 unchanged, **no non-coordinate field changed** |
| coordinate range + bbox + provenance check | — | 0 problems; 289 complete pairs; 0 half-pairs |
| pre-apply collision check (owner batch) | — | the accepted values vs the stored values at each apply: **0 conflicts** |
| `GET /wp-json/conexao/v1/leisure-coordinates` | 200 | `count=293`, **289** items with a lat/lng pair |
| Polylang translation term query | — | `a:2:{s:2:"pt";i:10150;s:2:"en";i:115357;}` (unchanged) |
| `./scripts/run-tests.sh --scripts` | 1 | 19 suites, **18 passed, 1 failed** — the failure is pre-existing (§12) |
| `./scripts/run-tests.sh` (full) | 1 | in-process **116 suites / 105 passed / 11 failed, 9 056 assertions passed / 41 failed**; script-contract 18/19; acceptance 3/4 — every failure pre-existing (§11, §12) |
| `./scripts/lint.sh` | 1 | PHP syntax **OK** (530 files); PHPCS baseline grew and PHPStan 1 error — **both pre-existing** (§12) |

### Numeric test results

`./scripts/run-tests.sh` (full run, `docs/evidence/2026-11-03-leisure-coordinate-research/full-test-run.txt`) — **re-run against the final, fully-populated database (289 coordinates)** to prove the data change introduced no regression:

- **In-process PHP: 116 suites, 105 passed, 11 failed; 9 056 assertions passed, 41 failed.**
- **Script-contract: 19 suites, 18 passed, 1 failed.**
- **HTTP acceptance: 4 suites, 3 passed, 1 failed.**

The pre-population run and this final run produce **identical** suite and assertion counts, so the coordinate population introduced no regression. The two suites that actually read leisure coordinates were also run directly against the populated data: `test-leisure-location-services.php` **102 passed, 0 failed** and `test-leisure-coordinates.php` **29 passed, 0 failed**.

Every one of the 11 failing in-process suites is on the pre-existing baseline
recorded in `docs/evidence/2026-11-02-lazer-location-services/regression-baseline-{a,b}.txt`,
with **identical suite names and identical assertion counts** — see §11. The
coordinate-relevant suite `plugin/conexao-admin-ux/test-leisure-coordinates.php`
passed with **29 assertions, 0 failures**, and the theme's
`test-leisure-location-services.php` is in the passing set. The full run finished
with `with_coordinates=67` still in place, so no test mutated the coordinate
dataset.

### Static analysis

`./scripts/lint.sh`: PHP syntax check passed over 530 files. PHPCS reported a
net **increase** over `phpcs-baseline.json`, and PHPStan reported **1** error.
Both are pre-existing at the start SHA and cannot be caused by this change: the
PHPCS ruleset (`phpcs.xml.dist`) analyses only `wp-content/themes/*` and
`wp-content/plugins/*`, and this change adds **no** file under `wp-content/`;
the PHPStan error is in
`wp-content/themes/conexao-br-irlanda/inc/leisure-location.php` (line 79), which
this task did not touch and which `git status` shows unmodified.

### Script-contract results

`./scripts/run-tests.sh --scripts`: **19 suites, 18 passed, 1 failed**. The single
failure is `verify-i18n-freshness.py` (pre-existing, §12).

### Release / build results

`not in scope` — no plugin or theme file changed, so no artifact and no version
is affected.

## 10. Failure proofs / negative tests

| Proof | Result |
|---|---|
| The dry run writes nothing | `--dry-run` reported `written=0` and a direct `SELECT COUNT(*)` on `_leisure_lat`/`_leisure_lng` returned **0** after it |
| The tool refuses to write without `--apply` | default mode is `dry-run`; `--dry-run` and `--apply` together exit 2 |
| The tool refuses an apply without `--confirm-production` | the first `--apply` exited **1** with `refusing to apply against target "http://localhost:8080" (classified as local) without --confirm-production` |
| The tool reports an unresolvable entry instead of guessing | the self-test manifest (`scripts/data/leisure-coordinates-selftest.json`) contains a `POPULATE` entry for a non-existent slug; the run reported `unresolved=1` and `errors=1`, exit 1 |
| The tool refuses a malformed coordinate | an invalid pair is rejected by `Conexao_Data_Model_Meta::sanitize_latitude()` / `sanitize_longitude()` and counted as `invalid` |
| Idempotence | a second `--apply` reported `written=0, unchanged=76` |

## 11. Regression comparison

| | Before | After |
|---|---|---|
| Failing in-process suites | 11 (baseline a+b, 2026-11-02) | 11 — **identical list** |
| Failing assertion count | 41 (baseline a+b) | 41 — **identical** |
| Failing script-contract | `verify-i18n-freshness.py` | `verify-i18n-freshness.py` (same) |
| Failing acceptance assertions | `verify-event-occurrence-http.py` against the unrelated `:8080` site | same suite, same failure |

The failing-set diff attributable to this change is **empty**: the 11 in-process
failures match `docs/evidence/2026-11-02-lazer-location-services/regression-baseline-a.txt`
and `-b.txt` suite-for-suite and assertion-for-assertion
(`test-event-filter-cache` 17/5, `test-stage33-bilingual` 196/2,
`test-translation-completeness` 21/2, `test-seasonal-halloween-nav-effects`
102/5, `test-town-sanitization` 82/1, `test-blog-en-translation` 25/3,
`test-en-jobs-shared-slug` 35/4, `test-job-en-translation` 28/4,
`test-jobs-en-language` 98/6, `test-stage45-pages` 364/2,
`test-guide-en-translation` 667/7 — 41 failed assertions in total). No test file
was modified by this task (`git status` shows none under `tests/`).

## 12. Known pre-existing failures

1. **`verify-i18n-freshness.py` FAIL** —
   `conexao-admin-ux/languages/conexao-admin-ux.pot` is older than
   `conexao-admin-ux/includes/class-fields.php`. `class-fields.php` was last
   modified by the **previous** commit (`80340da`, the start SHA) and the `.pot`
   was last regenerated on 2026-10-21; this task touched neither file. The
   failure therefore exists at the start SHA.
2. **PHPCS baseline grew** — new sniffs vs `phpcs-baseline.json`. The ruleset
   covers only `wp-content/`, which this task did not change, so the growth comes
   from the previous commit's new PHP files, not from this task.
3. **PHPStan 1 error** — `function.alreadyNarrowedType` at
   `wp-content/themes/conexao-br-irlanda/inc/leisure-location.php:79`, a file
   added by the previous commit and untouched here.
4. **11 in-process PHP suites fail** — all are local *content-data* conditions
   (EN translation coverage, shared-slug jobs pages, event-filter cache, town
   term contamination). They fail identically with the coordinate change
   absent, as proven by the 2026-11-02 regression baselines cited in §11. This
   task writes only `leisure` coordinate meta and cannot affect them.
5. **`verify-event-occurrence-http.py` FAIL** — the acceptance layer runs against
   `http://localhost:8080`, which in this environment is a *different* local
   WordPress than the Compose stack this task wrote to (see §13).

## 13. Limitations

- **Research coverage is bounded by public-API throttling.** The English
  Wikipedia search API throttled this environment so hard that a single request
  could cost ~35 s, so the per-attraction search fallback was abandoned in favour
  of a batched title-variant pass (5 164 variants in ~130 requests) plus a
  Nominatim pass at the documented 1 request/second. Result: Wikipedia resolved
  **162/294** records and OpenStreetMap resolved **214/294**. Records resolved by
  only one source are LOW and are listed for owner input rather than guessed.
- **The strict confidence bar is deliberate, and the owner batch is explicitly
  two-tier.** The pipeline populated 67 records only where two independent
  sources agreed within 250 m on a discrete POI. The owner batches added 222 more:
  30 were additionally corroborated by an independent source within 250 m, and
  46 rest on the owner's cited official/operator source because a car park,
  visitor centre or trailhead legitimately sits further than 250 m from the
  mapped centre of the feature — the exact distinction the task draws. Their
  provenance is recorded per record so each can be audited. 18 records are
  owner-approved MEDIUM and the remaining 271 are HIGH.
- **HTTP acceptance targets a different local site.** `run-tests.sh` defaults to
  `http://localhost:8080`, which in this environment is served by a *different*
  local WordPress (246 leisure items) than the Compose stack this task wrote to
  (published on `8081`, 293 items). The acceptance layer therefore ran and
  produced a result (3/4 suites, with `verify-event-occurrence-http.py` failing
  against that other site's event fixtures), but it is **not** a verification of
  this change. This is the same pre-existing port/DB mismatch recorded in
  `docs/reports/2026-11-02-lazer-location-services.md`. The equivalent checks were
  performed directly against the Compose stack (the endpoint query in §7 and the
  snapshot diff in §5).
- **`_leisure_coord_verified` is intentionally left at 0.** The coordinates are
  machine-researched and two-source verified, but no editor has confirmed them on
  a map. The admin list will show them as "Por verificar".
- **The production coordinate inventory is not inspectable from this repository**
  (WordPress.com: no SSH, no WP-CLI, no database). The local inventory is the
  only one measured here.
- **Local CI fixtures are in scope of the counts.** 4 published `[PH3C TEST]`
  records and 3 `(sintético)`-titled curated records exist only because the local
  database was built by `bootstrap-ci-fixtures.php`; they are handled explicitly
  (see §5 and the manifest `notes`).

## 14. Evidence paths

`docs/evidence/2026-11-03-leisure-coordinate-research/`:

| File | Proves |
|---|---|
| `research-leisure-coordinates.py` | The research pipeline itself (the methodology is reproducible, not narrated) |
| `research-run.txt` | The pipeline's full run log (per-record resolution) |
| `reconcile-owner-coordinates.py` | Owner batch 2: every row with its assessment, the decision rule, and the independent-corroboration check |
| `reconcile-owner-coordinates-batch3.py` | Owner batch 3: the same, including the suggested access points for the large-area attractions |
| `reconcile-owner-coordinates-batch4.py` | Owner batch 4: the same, including the section-4 decisions and the section-3 corroboration tie-breaks |
| `reconcile-owner-coordinates-batch5.py` | Owner batch 5 (Dublin-focused): the same rules, with the park-gate/entrance preference recorded per row |
| `reconcile-owner-coordinates-batch6.py` | Owner batch 6 (Cork): follows the owner's reconciliation-ready summary table, including the Garnish Island owner decision |
| `reconcile-owner-coordinates-batch7.py` | Owner batch 7 (Donegal + Monaghan): follows the owner's section-3 reconciliation summary |
| `reconcile-owner-coordinates-batch8.py` | Owner batch 8 (Dublin + Wexford): applies both owner decisions (Aras tour visitor centre, Curracloe White Gap) |
| `reconcile-owner-coordinates-batch9.py` | Owner batch 9 (Sligo + Wicklow): applies both owner decisions (Lissadell published coordinate, Strandhill promenade car park) |
| `reconcile-owner-coordinates-batch10.py` | Owner batch 10 (Clare + Cork): follows the owner's closing suggestion; replaces the Bunratty castle pin with the visitor entrance/parking candidate and the Loop Head pin with the official SatNav |
| `reconcile-owner-coordinates-batch11.py` | Owner batch 11 (Limerick + Longford + Monaghan): follows the owner's section-4 table |
| `reconcile-owner-coordinates-batch12.py` | Owner batch 12 (Tipperary + Waterford): resolves the Vee/Bay Lough choice and rejects the pipeline's guidepost/viewpoint/polygon points |
| `reconcile-owner-coordinates-batch13.py` | Owner batch 13 (Clare + Cork): applies the Kilkee walk-start correction and preserves the Titanic Experience discrepancy |
| `reconcile-owner-coordinates-batch14.py` | Owner batch 14 (Donegal + Kildare): closes the Newmills archaeology question, accepts Banba's Crown, and carries the owner-approved Mondello Park point |
| `reconcile-owner-coordinates-batch15.py` | Owner batch 15 (Kilkenny + Monaghan): replaces the offset Woodstock candidate and the Peace Campus approximation; four rows accepted at MEDIUM via owner_approved |
| `reconcile-owner-coordinates-batch16.py` | Owner batch 16 (the nine 2-attraction counties): applies the Carlingford Marina owner decision and the MEDIUM / MEDIUM-HIGH owner-approved rule |
| `reconcile-owner-coordinates-batch17.py` | Batch 17: applies the Rock of Dunamase Option A owner decision and leaves the three rows the research author declined to owner-approve at REVIEW |
| `reconcile-owner-coordinates-final.py` | FINAL reconciliation: the three confirmed owner decisions (Altamont, Annes Grove, Achill Island), recorded at MEDIUM with owner_approved |
| `build-deliverables.py` | How the CSV, the portable JSON and the owner-input list are projected from the manifest |
| `inventory-published-leisure.csv` | The read-only inventory baseline: 294 published records with county, town, URLs and existing coordinates |
| `pre-apply-snapshot.json` | The pre-write snapshot: 294 records, **0** with coordinates, 0 invalid |
| `post-apply-snapshot.json` | The final post-write state, from which the before/after diff is computed |
| `dry-run-owner-batch.txt` | The owner-batch dry run: `planned=76, written=0` — zero writes |
| `dry-run-final.txt` | The current dry run: `populate_entries=143, written=0, unchanged=143, gate=0` |
| `apply-run-4-owner-batch.txt` | The owner-batch apply: `written=76, unchanged=67, gate=0` |
| `apply-run-5-owner-idempotence.txt` | The owner-batch re-run: `written=0, unchanged=143` |
| `apply-run-6-batch3.txt` | The batch-3 apply: `written=1, unchanged=161, gate=0` |
| `apply-run-7-batch3-idempotence.txt` | The batch-3 re-run: `written=0, unchanged=162` |
| `verify-after-batch3.txt` | The `--verify` inventory: 294 published, **162** with coordinates, 132 missing, 0 invalid |
| `dry-run-batch3.txt` | The batch-3 dry run: `planned=1, written=0` |
| `apply-run-8-batch4.txt` | The batch-4 apply: `written=21, unchanged=162, gate=0` |
| `apply-run-9-batch4-idempotence.txt` | The batch-4 re-run: `written=0, unchanged=183` |
| `verify-after-batch4.txt` | The `--verify` inventory: 294 published, **183** with coordinates, 111 missing, 0 invalid |
| `dry-run-batch4.txt` | The batch-4 dry run: `planned=21, written=0` |
| `apply-run-10-batch5.txt` | The batch-5 apply: `written=20, unchanged=183, gate=0` |
| `apply-run-11-batch5-idempotence.txt` | The batch-5 re-run: `written=0, unchanged=203` |
| `verify-after-batch5.txt` | The `--verify` inventory: 294 published, **203** with coordinates, 91 missing, 0 invalid |
| `dry-run-batch5.txt` | The batch-5 dry run: `planned=20, written=0` |
| `apply-run-12-batch6.txt` | The batch-6 apply: `written=3, unchanged=203, gate=0` |
| `apply-run-13-batch6-idempotence.txt` | The batch-6 re-run: `written=0, unchanged=206` |
| `verify-after-batch6.txt` | The `--verify` inventory: 294 published, **206** with coordinates, 88 missing, 0 invalid |
| `dry-run-batch6.txt` | The batch-6 dry run: `planned=3, written=0` |
| `apply-run-14-batch7.txt` | The batch-7 apply: `written=4, unchanged=206, gate=0` |
| `apply-run-15-batch7-idempotence.txt` | The batch-7 re-run: `written=0, unchanged=210` |
| `verify-after-batch7.txt` | The `--verify` inventory: 294 published, **210** with coordinates, 84 missing, 0 invalid |
| `dry-run-batch7.txt` | The batch-7 dry run: `planned=4, written=0` |
| `apply-run-16-batch8.txt` | The batch-8 apply: `written=10, unchanged=210, gate=0` |
| `apply-run-17-batch8-idempotence.txt` | The batch-8 re-run: `written=0, unchanged=220` |
| `verify-after-batch8.txt` | The `--verify` inventory: 294 published, **220** with coordinates, 74 missing, 0 invalid |
| `dry-run-batch8.txt` | The batch-8 dry run: `planned=10, written=0` |
| `apply-run-18-batch9.txt` | The batch-9 apply: `written=10, unchanged=220, gate=0` |
| `apply-run-19-batch9-idempotence.txt` | The batch-9 re-run: `written=0, unchanged=230` |
| `verify-after-batch9.txt` | The `--verify` inventory: 294 published, **230** with coordinates, 64 missing, 0 invalid |
| `dry-run-batch9.txt` | The batch-9 dry run: `planned=10, written=0` |
| `apply-run-20-batch10.txt` | The batch-10 apply: `written=4, unchanged=230, gate=0` |
| `apply-run-21-batch10-idempotence.txt` | The batch-10 re-run: `written=0, unchanged=234` |
| `verify-after-batch10.txt` | The `--verify` inventory: 294 published, **234** with coordinates, 60 missing, 0 invalid |
| `dry-run-batch10.txt` | The batch-10 dry run: `planned=4, written=0` |
| `apply-run-22-batch11.txt` | The batch-11 apply: `written=7, unchanged=234, gate=0` |
| `apply-run-23-batch11-idempotence.txt` | The batch-11 re-run: `written=0, unchanged=241` |
| `verify-after-batch11.txt` | The `--verify` inventory: 294 published, **241** with coordinates, 53 missing, 0 invalid |
| `dry-run-batch11.txt` | The batch-11 dry run: `planned=7, written=0` |
| `apply-run-24-batch12.txt` | The batch-12 apply: `written=8, unchanged=241, gate=0` |
| `apply-run-25-batch12-idempotence.txt` | The batch-12 re-run: `written=0, unchanged=249` |
| `verify-after-batch12.txt` | The `--verify` inventory: 294 published, **249** with coordinates, 45 missing, 0 invalid |
| `dry-run-batch12.txt` | The batch-12 dry run: `planned=8, written=0` |
| `apply-run-26-batch13.txt` | The batch-13 apply: `written=4, unchanged=249, gate=0` |
| `apply-run-27-batch13-idempotence.txt` | The batch-13 re-run: `written=0, unchanged=253` |
| `verify-after-batch13.txt` | The `--verify` inventory: 294 published, **253** with coordinates, 41 missing, 0 invalid |
| `dry-run-batch13.txt` | The batch-13 dry run: `planned=4, written=0` |
| `apply-run-28-batch14.txt` | The batch-14 apply: `written=6, unchanged=253, owner_approved=1, gate=0` |
| `apply-run-29-batch14-idempotence.txt` | The batch-14 re-run: `written=0, unchanged=259` |
| `dry-run-provenance-repair.txt` | The dry run that planned the 192 provenance-text repairs (`written=0`) |
| `apply-run-30-provenance-repair.txt` | The provenance repair: `provenance_repaired=192, unchanged=67, gate=0` |
| `apply-run-31-provenance-idempotence.txt` | The post-repair re-run: `unchanged=259, provenance_repaired=0` |
| `verify-after-batch14.txt` | The `--verify` inventory: 294 published, **259** with coordinates, 35 missing, 0 invalid |
| `dry-run-batch14.txt` | The batch-14 dry run: `planned=6, written=0` |
| `apply-run-32-batch15.txt` | The batch-15 apply: `written=6, unchanged=259, owner_approved=5, gate=0` |
| `apply-run-33-batch15-idempotence.txt` | The batch-15 re-run: `written=0, unchanged=265` |
| `verify-after-batch15.txt` | The `--verify` inventory: 294 published, **265** with coordinates, 29 missing, 0 invalid |
| `dry-run-batch15.txt` | The batch-15 dry run: `planned=6, written=0` |
| `apply-run-34-batch16.txt` | The batch-16 apply: `written=16, unchanged=265, owner_approved=12, gate=0` |
| `apply-run-35-batch16-idempotence.txt` | The batch-16 re-run: `written=0, unchanged=281` |
| `verify-after-batch16.txt` | The `--verify` inventory: 294 published, **281** with coordinates, 13 missing, 0 invalid |
| `dry-run-batch16.txt` | The batch-16 dry run: `planned=16, written=0` |
| `apply-run-36-batch17.txt` | The final apply: `written=5, unchanged=281, owner_approved=14, gate=0` |
| `apply-run-37-batch17-idempotence.txt` | The final re-run: `written=0, unchanged=286` |
| `verify-after-batch17.txt` | The final `--verify`: 294 published, **286** with coordinates, 8 missing, 0 invalid |
| `dry-run-batch17.txt` | The batch-17 dry run: `planned=5, written=0` |
| `apply-run-38-final.txt` | The FINAL apply: `written=3, unchanged=286, owner_approved=17, with_after=289, gate=0` |
| `apply-run-39-final-idempotence.txt` | The final re-run: `written=0, unchanged=289` |
| `verify-final.txt` | The final `--verify`: 294 published, **289** with coordinates, 5 missing, 0 invalid |
| `dry-run-final.txt` | The final dry run: `planned=3, written=0` |
| `run-log-transcript.md` | The exact `summary:` line of every run in this task, including the earlier pipeline-batch runs and the two negative-path proofs |
| `full-test-run.txt` | The `./scripts/run-tests.sh` aggregate output |
| `lint.txt`, `script-contract.txt` | The static-quality and script-contract outputs |

## 15. Documentation updated

| Document | Change | Procedure moved to (skill) |
|---|---|---|
| `docs/reports/2026-11-03-leisure-coordinate-research.md` | new — this report | — |
| `docs/reports/2026-11-03-leisure-coordinate-research.csv` | new — the manifest | — |
| `docs/reports/2026-11-03-leisure-coordinate-owner-input.md` | new — the owner-input list | — |
| `docs/plans/2026-11-03-leisure-coordinate-research.md` | new — the plan | — |
| `docs/README.md` | indexes the three new documents and the plan | — |
| `docs/reports/README.md` | indexes the three new documents | — |
| `scripts/README.md` | catalogues `scripts/apply-leisure-coordinates.php` | — |

`docs/content-model.md` needed **no** change: the coordinate contract it
documents (keys, validation, provenance, inventory states, the no-geocoding
rule) is exactly what was used and remains true.

## 16. Rollback / recovery

Nothing outside the four coordinate meta keys was written, and the pre-apply
state is recorded in `pre-apply-snapshot.json` (all coordinate keys empty).

Rollback, in order:

1. Delete `_leisure_lat`, `_leisure_lng` and `_leisure_coord_source` from the 289
   affected post IDs listed in `post-apply-snapshot.json` (equivalently: from
   every published `leisure` record that has them). `_leisure_coord_verified` was
   never written.
2. Re-run `--verify`; it must report `with_coordinates=0, missing_coordinates=294`.

Rollback does **not** cover: the manifest or the report files (delete them
separately), and any coordinate an editor has since entered or corrected by hand.

## 17. Final status

**Final status: PASS WITH LIMITATION**

Every published attraction was inventoried and reviewed; **all 289** unique
physical attractions now carry a visitor-access coordinate populated through a
dry-run-first, idempotent, portable-identity apply; the numeric gate is `0`; the
every real attraction is populated and the 5 coordinate-less rows are accounted for;
and PT content plus the translation relationship
are provably unchanged — but two verification capabilities were unavailable: the
HTTP acceptance layer cannot run against this environment's split local site
(§13), and the production coordinate inventory is not inspectable from this
repository, so the local database is the only measured target.

_Last verified: 2026-11-03 by the Lazer coordinate research and population task_
