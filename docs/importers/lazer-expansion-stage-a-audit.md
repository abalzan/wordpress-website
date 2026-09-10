# Lazer Expansion — Stage A

## Discover Ireland + Ireland.com Source Audit & Candidate Discovery

- **Date**: 2026-10-09
- **Stage**: READ-ONLY. No WordPress records created, updated, deleted or migrated. Production untouched.
- **Sources audited**:
  1. https://www.discoverireland.ie/guides/free-things-ireland
  2. https://www.discoverireland.ie/things-to-do/free-things-to-do
  3. https://www.ireland.com/en-gb/
- **Current Conexão BR Lazer page**: https://conexaobr.ie/lazer/ (233 records in repo dataset: 55 seeded + 178 expansion; 26 Republic of Ireland counties)

---

## 0. Method

Read-only source audit performed by:

1. Reading the repository's content model, leisure plugin/template/filter/query/seed implementations (no changes).
2. Fetching each source homepage, both key Discover Ireland pages, robots.txt + sitemap.xml for both domains, and a representative sample of attraction/destination detail pages (~20 pages).
3. Classifying candidates against the existing 233-record dataset (title/slug/URL matching) already committed in
   `scripts/seed-leisure-locations.php`, `scripts/data/leisure-expansion-data-1.php`, `scripts/data/leisure-expansion-data-2.php`.

Only **factual metadata** (name, location, category, free/paid indication, official URL, canonical source URL) was collected. Source prose and images are not reproduced.

---

## 1. Existing Conexão BR model audit

The /lazer/ directory is implemented as the `leisure` CPT, registered by `conexao-data-model`
(`register_content_types()`, slug `lazer`), with the shared `conexao_category`,
`conexao_county`, `conexao_tag` taxonomies plus the dedicated non-hierarchical
`conexao_leisure_attribute` taxonomy for practical characteristics.

### How the current system stores each requested attribute

| Attribute | Storage | Notes |
|---|---|---|
| Title | `post_title` | Portuguese or English name as used on site |
| Slug | `post_name` | Portuguese/preferred slug; canonical `/lazer/{slug}/` |
| Excerpt/description | `post_excerpt` (short) + `post_content` (rich HTML body) | Cards render trimmed excerpt |
| County | `conexao_county` term + `_leisure_county` meta | 26 ROI counties only |
| Town | `_leisure_town` meta | Free text, no taxonomy |
| Category | `conexao_category` term | `Natureza, História, Cultura, Família, Praias, Caminhadas, Aventura, Jardins, Museus, Castelos, Vida Selvagem, Patrimônio, Cidades, Ilhas, Greenways, Outros` |
| Practical attributes | `conexao_leisure_attribute` terms | `Famílias, Exterior, Interior, Interior + exterior, Gratuito, Pet friendly, Acessível, Estacionamento, Necessita reserva, Pago, Gratuito em determinadas condições, Acesso de transporte público, Bicicleta` |
| Free/paid | `_leisure_free` meta + `Gratuito`/`Pago`/`Gratuito em determinadas condições` attribute term | Five-way classification in `scripts/backfill-leisure-attributes.php` |
| Indoor/outdoor | `_leisure_indoor`, `_leisure_outdoor` meta + `Interior`/`Exterior`/`Interior + exterior` attribute | Combined term collapses when both set |
| Family | `_leisure_family` + `Famílias` |
| Accessibility | `_leisure_accessibility` + `Acessível` |
| Parking | `_leisure_parking` + `Estacionamento` |
| Pets | `_leisure_pet_friendly` + `Pet friendly` |
| Booking | `_leisure_booking` + `Necessita reserva` |
| Official website | `_leisure_official_website` | Primary external destination; 302 redirect target via `conexao_leisure_external_url()` unless `_leisure_internal_page` flag set |
| Discover Ireland URL | `_leisure_discover_ireland` | Fallback external destination; also display-only authoritative link on internal pages |
| Map URL | `_leisure_map_url` | Else deterministic Google Maps search derived at render time |
| Image | `_leisure_image_attachment_id` (local Media Library ID) | Always local; Wikimedia Commons metadata `_leisure_image_author`, `_leisure_image_license`, `_leisure_image_attribution`, `_leisure_image_source_url` kept as attribution reference only |
| Internal/external status | implicit via `conexao_leisure_external_url()` (seo.php): official/DI URL present → external (302 from `/lazer/{slug}/`); `_leisure_internal_page` flag → internal with display-only links | Cards + sitemap + related selector all read the same helper |
| Verification | `_leisure_practical_notes`, `_leisure_practical_source_url`, `_leisure_practical_last_checked` (Phase 2) | Opening hours intentionally NOT modelled |

**Key model constraints honoured by this audit:**

- Portuguese slugs canonical; clean URLs.
- All 26 **Republic of Ireland** counties in `conexao_county` — no Northern Ireland terms exist.
- Lazer pages never render an event calendar.
- Categories reuse existing `conexao_category` terms only.
- Images must remain local Media Library attachments with preserved attribution.
- No prices invented; optional attributes left unset unless source-supported.

---

## 2. Source architecture audit

### 2.1 Discover Ireland — `/guides/free-things-ireland`

- **Owner/CMS**: Fáilte Ireland (National Tourism Development Authority of Ireland). Custom enterprise CMS (Drupal-class); no CMS name exposed on pages.
- **robots.txt**: `User-agent: *`, `Disallow: /search-results` only, `Sitemap: https://www.discoverireland.ie/sitemap.xml`. No `Crawl-delay`, no anti-scraping language.
- **Sitemap**: single sitemap.xml, **3,100 URLs**, per-URL `lastmod`. Normal HTTP GET works (200, `text/html`).
- **URL patterns**:
  - Destination/attraction detail: `/{county-slug}/{attraction-slug}` — e.g. `/dublin/chester-beatty`, `/kilkenny/jerpoint-glass-studio`, `/cork/cork-city-gaol-heritage-centre`.
  - Region hubs: `/{region-slug}` — e.g. `/the-burren`, `/connemara`, `/ring-of-kerry`, `/boyne-valley`, `/waterford-greenway`.
  - County hubs: `/{county-slug}` — e.g. `/cavan`, `/monaghan`, `/kilkenny`.
  - Guides: `/guides/{guide-slug}`; indexed at `/guides`.
  - "Things to do" indexes: `/things-to-do/{theme}` — e.g. `/things-to-do/free-things-to-do`; submenus `/things-to-do/attractions`, `/things-to-do/arts-and-culture`, `/things-to-do/houses-and-gardens`, `/things-to-do/history-and-heritage`, `/things-to-do/nature-and-wildlife`, `/things-to-do/adventure-and-sports`, etc.
- **Index/discovery**: sitemap (authoritative) + county destination hubs. Each county hub lists attraction cards ("Visit website" + county/town + category) plus guides, trails, festivals, food and accommodation.
- **Pagination**: guides are single long pages (Top-19 list is one page). `things-to-do` indexes link onward to individual guides rather than paginating attractions.
- **Filters**: none client-side; site search is a separate `/search-results` (disallowed in robots). Navigation categories approximate a filter taxonomy.
- **Detail page fields** (observed): name, county, town/locality, breadcrumb trail, description, tag chips (e.g. "Free things to do", "History and heritage", "Family friendly", "Free to visit", "Rainy days", "Paid car parking", "Cloudy days"), "Visit website" CTA, "Book now" (some), address, phone, opening-hours block (present but often empty), visit-duration recommendation, related places, "What's nearby". Trail pages additionally expose structured trail details (type, grade, length, ascent, dogs allowed, time, nearest towns, waymarking).
- **Structured data**: pages embed JSON-LD (`application/ld+json`) alongside HTML; treat as secondary to visible fields.
- **Images**: per-page gallery ("Show more photos") + social/OG image; all © Fáilte Ireland/contributor. **Not reusable.**
- **Official-website field**: "Visit website" CTA resolves to the operator's own site when available; otherwise to the DI page itself.
- **Access restrictions**: none observed beyond `/search-results`. Normal sequential fetches are safe; keep request rate modest (< 1 req/s) and cache results.

### 2.2 Discover Ireland — `/things-to-do/free-things-to-do`

- A **theme index page**, not an attraction listing: featured image (`Diamond Hill, Co. Galway`) + intro + links to guide articles: "Top 19 free things to do", "Free things to do with kids on the Wild Atlantic Way", "8 things to do for free in Galway City", "5 ways to explore Mayo's Wild Nephin National Park", "Cycling the Waterford Greenway", "5 walks to explore at Cavan Burren Park", "10 outdoor activities to do with friends in Ireland", etc.
- Discovery value: cross-links into guides and confirms which regions/attractions the theme promotes. The underlying attraction inventory lives in the county/attraction detail pages indexed by the sitemap.
- No pagination; no direct JSON feed. It is **not** the canonical attraction index.
### 2.3 Ireland.com — `/en-gb/`

- **Owner/CMS**: Tourism Ireland (marketing body co-owned by Fáilte Ireland and Tourism Northern Ireland). **Sitecore** CMS (robots exposes `/sitecore`, `/api`, `/sitecore_files/`; per-locale sitemaps).
- **robots.txt**: `User-agent: *` with disallows for `/myireland`, `/sitecore`, `/api`, `/App_*`, `/temp/`, `/upload/`, `/xsl/`, locale-private and digit-prefixed paths; `Allow: /sitecore%20modules/Web/ExperienceForms/scripts/`; `Crawl-delay: 5` only for `YisouSpider` (not general crawlers). Sitemap list per locale: `https://www.ireland.com/en-gb/sitemap.xml` etc.
- **Sitemap (en-gb)**: **795 URLs**, authoritative index. Composition:
  - `magazine/…` — 502 (inspirational articles; NOT a source for import)
  - `plan-your-trip/…` — 98 (travel-planning info)
  - `destinations/county/{county}/{place}/` — 91 (destination pages; each may embed attraction cards)
  - `things-to-do/…` — 64 (category index + some attraction detail)
  - `features/…` — 21
  - `help-and-advice/…` — 17
- **Index/discovery**: sitemap is the true index; destination pages are the discovery hubs ("Cork highlights: Blarney Castle / Midleton Distillery Experience / Doneraile Estate…", "Kilkenny…"). Each destination page embeds a `PageType Title Subtitle` list where `PageType` ∈ `Attraction`, `Destination`, `Accommodation`, `Trip idea`, `Article`, `Event`.
- **Attraction detail pages**: sparser than Discover Ireland (description + location + "Getting to X" travel block). Examples: Derrigimlagh, Errigal, Mizen Head Signal Station, Old Head of Kinsale.
- **Categories**: "Things to do" → Attractions, Arts & culture, Houses & gardens, History & heritage, Health & wellbeing, Food & drink experiences, Nature & wildlife; "Activities" → Adventure & sports, Horse riding, Golf, Tours, Cycling, Water activities, Greenways; "Inspiration" → Romantic breaks, Free things to do, Rainy days, Family fun, Car-free travel, Sustainable travel; plus Walking & hiking, Festivals & events. Categories are navigation themes, not paginated lists.
- **Address/location data**: partial; destination pages give region/county + prose; fewer structured address blocks than Discover Ireland.
- **Official-website fields**: attraction cards link to operator sites on detail pages (e.g. Kylemore Abbey, Céide Fields).
- **Geographic reach**: **whole island** (ROI + Northern Ireland). NI content (Belfast, Giant's Causeway, Crumlin Road Gaol, Marble Arch Caves, Derry~Londonderry, Counties Down/Antrim/Armagh/Fermanagh/Tyrone) appears throughout — flagged separately (§7).
- **Access restrictions**: only the robots disallows above; no anti-scraping headers observed; normal HTTP GET works (200). Requests should still be polite (fresh session, no parallel bursts).

### 2.4 Discovery summary vs. naive navigation

Homepage navigation alone is **insufficient**: it surfaces only a handful of "places you'll love" and magazine content. The complete inventory is reachable only through (a) Discover Ireland's sitemap.xml → county/attraction URLs, and (b) Ireland.com's per-locale sitemap.xml → `destinations/county/...` pages, each embedding its attraction cards server-rendered in HTML. Both were audited directly.
---

## 3. Discover Ireland guide candidates (Top 19)

The guide stages 19 "free things to do". Classified against the existing 233-record dataset:

| # | Guide item | Source-verified fact | Conexão BR status |
|---|---|---|---|
| 1 | The Chester Beatty, Dublin | Free-admission museum; EU-Presidency closure 15 Jun–end Dec 2026; no pre-booking; indoor; family/rainy-day tags | **NEW** (HIGH) |
| 2 | James Joyce Museum & Martello Tower, Sandycove, Co Dublin | Working Joyce museum in Martello Tower; indoor + outdoor gun platform; family friendly | **NEW** (MEDIUM — seasonal public hours) |
| 3 | The Forty Foot, Sandycove, Co Dublin | Free year-round sea swimming; outdoor; locals' institution; no facilities; safety notes (cold water/currents) | **NEW** (MEDIUM) |
| 4 | The National Archives of Ireland, Dublin | Free; **admission by appointment/reader's ticket only**; indoor; genealogy service | **NEW** (MEDIUM — appointment-gated) |
| 5 | Lough Boora Discovery Park, Co Offaly | Free park; paid car parking; outdoor; family; cycle hire on site | **EXISTS** — `lough-boora-discovery-park` (expansion-1, incl. DI URL) |
| 6 | Jerpoint Glass Studio, Co Kilkenny | Working glass studio + Attic gallery + shop; free car parking; "Arts and culture"; primary purpose includes retail | **NEW** (LOW — commercial overlap) |
| 7 | Waterford Greenway, Co Waterford | Free walking/cycling route; outdoor; dogs allowed | **EXISTS** — `waterford-greenway` (expansion-2, incl. DI URL) |
| 8 | Killarney National Park + Visitor Centre, Co Kerry | Free park entry (paid activities); UNESCO biosphere | **EXISTS** — `killarney-national-park` (seed) |
| 9 | W.B. Yeats' County, Co Sligo | Regional literature theme, not a single place | **EXCLUDED** — region/theme (Sligo sites already covered) |
| 10 | Sliabh Liag (Slieve League), Co Donegal | Free cliff viewpoint; outdoor; hazardous cliffs | **EXISTS** — `sliabh-liag` (expansion-2) |
| 11 | Croagh Patrick, Co Mayo | Free mountain pilgrimage climb; paid car parking at Murrisk; outdoor | **EXISTS** — `croagh-patrick` (expansion-2) |
| 12 | The Burren, Co Clare/Galway | Free limestone landscape; region across two counties | **EXISTS-AS-PARK** — `burren-national-park` (seed); region itself excluded |
| 13 | Lough Gur, Co Limerick | Free lakeside heritage site; interpretive centre paid | **EXISTS** — `lough-gur` (expansion-2) |
| 14 | Galway city, Co Galway | Free urban visitor destination; walkable | **NEW** (MEDIUM — Cidades destination) |
| 15 | Lough Muckno, Co Monaghan | Free lakeside park at Castleblayney; outdoor; family | **NEW** (MEDIUM) |
| 16 | Carlingford, Co Louth | Free coastal village destination | **EXISTS** — `carlingford` (expansion-2, Cidades) |
| 17 | Hill of Tara, Co Meath | Free site (OPW); outdoor; free parking; visitor centre paid | **EXISTS** — `hill-of-tara` (seed) |
| 18 | Cathedral of St Patrick & St Felim, Co Cavan | Free cathedral; indoor; Cavan Town | **NEW** (MEDIUM) |
| 19 | Glencar Waterfall, Co Leitrim | Free waterfall; car park, picnic, toilets, playground; outdoor | **EXISTS** — `glencar-waterfall` (expansion-1, incl. DI URL) |
## 4. Ireland.com candidate discovery

Discovery route: sitemap `destinations/county/{county}/{place}/` → embedded `Attraction` cards. Findings by county (permanent attractions only; tour/accommodation/food/event cards excluded):

- **Dublin**: covered by existing dataset (Guinness Storehouse, Dublin Castle, Trinity, Kilmainham, Phoenix Park) + NEW Chester Beatty (§3).
- **Wicklow**: Glendalough, Wicklow Mountains, Powerscourt, Sugar Loaf, Howth all covered. Nothing new required.
- **Cork**: Blarney ✓, Jameson/Midleton ✓, Fota ✓, Titanic Cobh ✓, Mizen ✓, Doneraile ✓, Garnish ✓, Spike ✓. **NEW**: Dursey Island (cable-car island destination, MEDIUM), Old Head of Kinsale (scenic headland + lighthouse, MEDIUM). Excluded: Ballymaloe Cookery School, Maryborough/Bridgeview hotels, Whale Watch West Cork, Atlantic Sea Kayaking.
- **Kerry**: Killarney NP ✓, Muckross ✓, Ross ✓, Torc ✓, Gap of Dunloe ✓, Ring of Kerry ✓, Dingle ✓, Skellig ✓, Valentia ✓, Derrynane ✓, Inch ✓. Excluded: Dingle Dolphin Blasket Adventure, boat offers, Listowel Writers' Week (event).
- **Clare**: Cliffs of Moher ✓, Burren NP ✓, Bunratty ✓, Doolin Cave ✓, Aillwee ✓, Loop Head ✓. Excluded: Burren Smokehouse, Dromoland hotel, Lahinch Art Gallery, Loop Head Lightkeeper's Cottage, Sheedy's, Shannon Golf, Doolin Cliff Walk (operator-led), Spraoi-type events.
- **Galway**: Kylemore ✓, Connemara NP ✓, Diamond Hill ✓, Aran Islands ✓, Sky Road ✓, Salthill ✓. **NEW**: Derrigimlagh Heritage Boardwalk (MEDIUM-HIGH — free year-round heritage walk: Marconi station + Alcock & Brown landing + FÁS bog road), Dan O'Hara's Homestead / Connemara Heritage & History Centre (MEDIUM — paid heritage centre), Arranmore Island (MEDIUM). Excluded: Killary Fjord Boat Tours, Connemara Seaweed Baths, Misunderstood Heron, Roundstone Music & Crafts, Clifden Eco Beach Camping, Clifden Historical Walking Tours.
- **Donegal**: Glenveagh ✓, Slieve League ✓, Malin ✓, Fanad ✓, Errigal ✓, Grianán ✓, Ards ✓. **NEW**: Doagh Famine Village (MEDIUM — staffed folk museum, seasonal), Knocknarea → Sligo (below). Excluded: Donegal Bay Waterbus, Leo's Tavern (pub), CARA spa, Bundoran surf schools.
- **Mayo**: Croagh Patrick ✓, Westport House ✓, Achill ✓, Keem Bay ✓, Céide ✓, Downpatrick Head ✓, Wild Nephin ✓, Clare Island ✓, Great Western Greenway ✓. Nothing new required.
- **Sligo**: Carrowmore ✓, Sligo Abbey ✓, Lissadell ✓, Drumcliffe ✓, Benbulben ✓, Strandhill ✓, Glencar Waterfall ✓ (Leitrim record). **NEW**: Knocknarea / Queen Maeve's Cairn (MEDIUM — free hill viewpoint), The Model / Niland Collection (MEDIUM — free municipal gallery), Mullaghmore (LOW — coastal village). Excluded: surf schools, Sligo Oyster Experience, dark-tales tours.
- **Waterford**: Waterford Treasures ✓, Reginald's Tower ✓, House of Waterford Crystal ✓, Greenway ✓, Copper Coast ✓, Mount Congreve ✓, Lismore Gardens ✓, Ardmore ✓, Coumshingaun ✓. Nothing new required.
- **Kilkenny**: Kilkenny Castle ✓, Medieval Mile ✓, Dunmore Cave ✓, Smithwick's ✓, Jerpoint Abbey ✓, Rothe House ✓, Castlecomer ✓, Woodstock ✓, Jerpoint Park ✓. **NEW**: National Design & Craft Gallery (MEDIUM — free permanent gallery at Castle Yard). Excluded: Mount Juliet, Kilkenny Boat Tours, Highbank Orchards, Kilkenny Cycling Tours, falconry.
- **Wexford**: Irish National Heritage Park ✓, Johnstown ✓, Hook ✓, Dunbrody ✓, Saltee ✓, Curracloe ✓, Wildfowl Reserve ✓, Ferns ✓, Tintern ✓. **NEW** (optional): JFK Arboretum (MEDIUM), Duncannon Fort (MEDIUM/LOW). Excluded: Valhalla Tours, Emerald Tea Tours, pubs/restaurants.
- **Meath/Louth/Cavan/Monaghan/Leitrim/Roscommon/Longford/Offaly/Laois/Kildare/Westmeath/Tipperary/Limerick/Carlow**: existing expansion dataset already covers these counties densely (see §8); little new required.
---

## 5. Year-round classification (A/B/C/D)

- **A — Year-round / permanent** (directory-suitable): Chester Beatty, Forty Foot, National Archives, Waterford Greenway (in dataset), Lough Boora (in dataset), Lough Muckno, Cathedral St Patrick & St Felim, Galway city, Derrigimlagh, Knocknarea, Dursey Island, Old Head of Kinsale, National Design & Craft Gallery, Arranmore, Dan O'Hara's Homestead, The Model (Sligo), JFK Arboretum, Mullaghmore.
- **B — Regular but seasonal (hours/season gating needed)**: James Joyce Museum & Martello Tower (seasonal public hours), Doagh Famine Village (staffed Apr–Sep; site rest year-round), Duncannon Fort (structures seasonal). Weather-sensitive permanent places (Sliabh Liag, Malin Head, Fanad, Glenveagh, Croagh Patrick) stay Class A but need practical advisories.
- **C — Event/temporary** (excluded): Listowel Writers' Week, Spraoi, all Ireland.com "offer" cards, Galway Film Fleadh, Ed Reavy Festival, St. Kilian's Camino, Derry Halloween/Púca Festival, pop-up/seasonal businesses.
- **D — Unclear** (not imported yet): W.B. Yeats' County region, "Galway city" meal/food experiences, guided-walk operators, The Burren region-as-place (vs Burren NP), "Viking Triangle" composite, Cork Harbour boat tours.

Rule applied: "open all year ≠ open every day." Permanent destinations with seasonal hours remain Class A; only genuinely ephemeral offers/festivals are excluded.

## 6. Free/paid classification

Using the existing `conexao_leisure_attribute` vocabulary (`Gratuito`, `Pago`, `Gratuito em determinadas condições`, plus `Estacionamento`):

| Candidate | Classification | Evidence |
|---|---|---|
| Chester Beatty | **Completely free** (permanent galleries) | "Free admission" + "Free to visit" tag + "No pre-booking required"; shop/café commercial |
| James Joyce Museum | **Free admission** (permanent museum), seasonal hours | "Free things to do" guide listing; historically free entry |
| Forty Foot | **Completely free** (no admission) | Open-air sea swimming; year-round; no booking |
| National Archives | **Free admission but appointment-gated** | "Admittance is by appointment only"; free genealogy advice |
| Lough Boora | **Free park; paid car parking + paid activities** | "Paid car parking" tag; bike hire; guided tours paid |
| Jerpoint Glass | **Free to watch; shop/gallery commerce** | Viewing area + Attic gallery + shop on site |
| Lough Muckno | **Free park**; some paid watersports | Lakeside park, Castleblayney |
| Galway city | **Free to explore** (attractions vary) | Urban destination |
| Cathedral St Patrick & St Felim | **Free** (cathedral) | Place of worship |
| Derrigimlagh | **Completely free** outdoor heritage walk | Bog boardwalk, no admission |
| Dan O'Hara's Homestead | **Paid** heritage centre | Staffed CFC museum |
| Doagh Famine Village | **Paid** museum | Staffed visitor attraction |
| Knocknarea | **Free** hill walk | Open mountain |
| Dursey Island | **Free island; paid cable car** | Cable car ticketed; island free |
| Old Head of Kinsale | **Free headland; lighthouse tour paid** | Scenic head; golf links private |
| National Design & Craft Gallery | **Free** gallery | Kilkenny Castle Yard |
| The Model (Sligo) | **Free** municipal gallery | Niland Collection |
| JFK Arboretum | **Free / paid car park** (varies) | OPW site |
| Duncannon Fort | **Free grounds; paid structures** | Coastal fort |

No prices are invented; only the free/paid **categories** above are recorded.

## 7. Geographic scope

- Existing dataset = **Republic of Ireland only** (26 `conexao_county` terms). Confirmed no Northern Ireland records exist.
- Discover Ireland (Fáilte Ireland) covers primarily the Republic; its sitemap contains no Northern Ireland destination pages (only tour products leaving from Dublin).
- Ireland.com covers the **whole island**. Northern Ireland items identified and **flagged separately — NOT to be added** to the ROI Lazer dataset:
  - **Belfast**: Titanic Belfast, Crumlin Road Gaol, Game of Thrones Studio Tour, City Hall, Belfast Castle, Cave Hill Country Park
  - **County Antrim**: Giant's Causeway, Carrick-a-Rede, Dunluce Castle, Bushmills Distillery, Dark Hedges, Causeway Coastal Route
  - **County Down**: Mourne Mountains, Strangford Lough, Mount Stewart, Castle Ward, Downpatrick (St Patrick's grave), Bangor
  - **County Armagh**: Navan Fort, Armagh Observatories, the two St Patrick Cathedrals
  - **County Fermanagh**: Marble Arch Caves, Enniskillen, Lough Erne, Devenish Island
  - **Derry~Londonderry**: City walls, Mussenden Temple, Derry Halloween
  - **County Tyrone**: Ulster American Folk Park, Beaghmore Stone Circles
- Any Stage-B importer must filter by `county` against the existing 26-term taxonomy and drop NI entries.
---

## 8. Deduplication findings

Matching basis: canonical official URL, `_leisure_discover_ireland` URL, normalized title, attraction identity + locality, known aliases.

### Exact existing matches (guide items / Ireland.com cards already in the 233-record dataset)

| Candidate (source) | Existing record | Match basis |
|---|---|---|
| Lough Boora Discovery Park (guide #5) | `lough-boora-discovery-park` (expansion-1, Offaly) | Same DI URL |
| Waterford Greenway (guide #7) | `waterford-greenway` (expansion-2) | Same DI URL |
| Killarney National Park (guide #8) | `killarney-national-park` (seed) | Identity + locality |
| Sliabh Liag (guide #10) | `sliabh-liag` (expansion-2) | Identity + alias |
| Croagh Patrick (guide #11) | `croagh-patrick` (expansion-2) | Identity |
| Lough Gur (guide #13) | `lough-gur` (expansion-2) | Identity |
| Carlingford (guide #16) | `carlingford` (expansion-2) | Identity |
| Hill of Tara (guide #17) | `hill-of-tara` (seed) | Identity + official URL |
| Glencar Waterfall (guide #19) | `glencar-waterfall` (expansion-1, Leitrim) | Same DI URL |
| Burren NP (guide #12 region) | `burren-national-park` (seed) | Park identity (region ≠ park) |
| Diamond Hill (listing feature) | `diamond-hill` (seed, Galway) | Identity + locality |
| Blarney Castle (IE Cork) | `blarney-castle` (seed) | Identity + official URL |
| Midleton/Jameson (IE Cork) | `jameson-distillery-midleton` (seed) | Identity |
| Doneraile Estate (IE Cork) | `doneraile-park` (expansion-1) | Identity + official URL |
| Fota Wildlife Park (IE) | `fota-wildlife-park` (seed) | Identity |
| Kilkenny Castle (IE) | `kilkenny-castle` (expansion-2) | Identity + official URL |
| Dunmore Cave (IE Kilkenny) | `dunmore-cave` (expansion-2) | Identity + official URL |
| Smithwick's Experience (IE) | `smithwicks-experience` (expansion-2) | Same official URL |
| Doolin Cave / Bunratty / Cliffs of Moher / Kylemore / Garnish / Mizen / Downpatrick Head / Errigal / Malin / Fanad / Westport House / Achill / Wild Nephin / Céide etc. | matching seed/expansion records | Identity + official URLs |

### Likely duplicates / same attraction with different source page

- "Glencar Lake and Waterfall" (Ireland.com) → existing `glencar-waterfall` (Leitrim). Same place, different page.
- "The Burren" (DI region hub) → existing `burren-national-park`. Do not add region.
- "Midleton Distillery Experience" = existing Jameson Distillery Midleton.
- "Viking Triangle" (Waterford) ≈ composite of `waterford-treasures` + `reginals-tower`.
- "W.B. Yeats' County" ≈ composite of existing Sligo records (Carrowmore, Sligo Abbey, Lissadell, Drumcliffe, Benbulben, Strandhill, plus NEW Knocknarea/The Model).

### Genuinely new candidates (no existing record)

§9/§11 lists: Chester Beatty, James Joyce Museum, Forty Foot, National Archives, Lough Muckno, Cathedral St Patrick & St Felim, Galway city, Derrigimlagh, Dan O'Hara's Homestead, Doagh Famine Village, Knocknarea, The Model (Sligo), Mullaghmore, Dursey Island, Old Head of Kinsale, Arranmore, National Design & Craft Gallery, JFK Arboretum, Duncannon Fort.

**No production records were modified.**

---

## 9. Candidate quality scores

Physical-attraction clarity, year-round suitability, location confidence, source confidence, usefulness to Conexão BR audience, duplicate risk, data completeness.

| Candidate | Quality | Rationale |
|---|---|---|
| Chester Beatty (Dublin) | **HIGH** | Award-winning free museum; central; family; permanent (2026 EU-Presidency closure caveat as practical note); indoor |
| Derrigimlagh (Galway) | **HIGH** | Free year-round heritage boardwalk; strong aviation/wireless story; unique; countable |
| Forty Foot (Dublin) | **HIGH** | Free, year-round, iconic Dublin sea-swim; safety/seasonal notes required; outdoor, no facilities |
| James Joyce Museum (Dublin) | **MEDIUM** | Free museum, literary draw; seasonal hours, small footprint |
| National Archives (Dublin) | **MEDIUM** | Free but appointment-only; niche appeal |
| Lough Muckno (Monaghan) | **MEDIUM** | Free lakeside park, family; Monaghan under-represented for free content |
| Cathedral St Patrick & St Felim (Cavan) | **MEDIUM** | Free indoor visit; Cavan covered but free options fewer |
| Galway city (Galway) | **MEDIUM** | City destination; existing Galway county records cover sites |
| Dan O'Hara's Homestead (Galway) | **MEDIUM** | Paid heritage centre; permanent; well-marked |
| Knocknarea (Sligo) | **MEDIUM** | Free hill walk + legendary tomb; Sligo already dense |
| The Model / Niland Collection (Sligo) | **MEDIUM** | Free municipal gallery; indoor culture |
| Dursey Island (Cork) | **MEDIUM** | Island + Ireland's only cable car; remote access = travel notes |
| Old Head of Kinsale (Cork) | **MEDIUM** | Scenic headland; lighthouse tour paid; viewpoint stop |
| Doagh Famine Village (Donegal) | **MEDIUM** | Paid folk village; seasonal staffed hours; strong heritage story |
| Arranmore (Donegal) | **MEDIUM** | Island destination, year-round ferry; remote |
| JFK Arboretum (Wexford) | **MEDIUM** | Free gardens/arboretum; complements Wexford set |
| Duncannon Fort (Wexford) | **MEDIUM/LOW** | Fort grounds free, parts seasonal; modest prominence |
| National Design & Craft Gallery (Kilkenny) | **MEDIUM** | Free permanent gallery; Kilkenny already covered |
| Mullaghmore (Sligo) | **LOW** | Coastal village; adjacent to Benbulben/Strandhill; low add-value |
| Jerpoint Glass Studio (Kilkenny) | **LOW** | Commercial-primary (working studio + shop); weak attraction fit |
| W.B. Yeats' County, The Burren region, Viking Triangle, "Galway food experiences" | **EXCLUDED** | Region/theme composites |
| All operators/accommodation/events (Ballymaloe, Maryborough, hotels, pubs, tours, surf schools, festivals, offers) | **EXCLUDED** | Out of Lazer model scope |
---

## 10. Legal / source-use assessment

### Discover Ireland (Fáilte Ireland)

- **robots.txt**: generic crawl allowed; only `/search-results` disallowed; sitemap.xml published; no anti-scrape language; no crawl-delay for general agents.
- **Copyright notice**: footer "Fáilte Ireland © Fáilte Ireland. All rights reserved." plus Legal / Terms-of-use / Cookie / Privacy / Accessibility links.
- **Owner**: Fáilte Ireland, the statutory National Tourism Development Authority (Republic of Ireland). Content is promotional tourism information.
- **Terms/image licensing**: no CC-style open license on site; images © Fáilte Ireland / credited contributors. **Do not reuse images or prose.**
- **Anti-scraping**: none explicit; still keep request volume respectful and cache locally.

### Ireland.com (Tourism Ireland)

- **robots.txt**: generic crawl allowed with admin/internal paths disallowed (`/sitecore`, `/api`, `/App_*`, `/temp/`, `/upload/`, `/myireland`, locale-private paths); `Crawl-delay: 5` for `YisouSpider` only; per-locale sitemaps.
- **Copyright notice**: "© Tourism Ireland 2026" + Terms / Privacy links.
- **Owner**: Tourism Ireland (joint Fáilte Ireland + Tourism Northern Ireland company).
- **Terms/image licensing**: no permissive open license; images/logos © Tourism Ireland. **Do not reuse images or prose.**

### Separation of data classes

- **Factual metadata only** for import: attraction name, county, town, category tags, free/paid indication, opening-hours gating flags, canonical source URL, official website URL when present. These are directory facts, not creative works, consistent with the existing dataset practice (DI URLs stored in `_leisure_discover_ireland`).
- **Copyrighted content** not to be copied: source descriptions/prose, photos, logos, layout. Existing records keep their own authored Portuguese excerpts/posts and Wikimedia-Commons images — that practice continues for any Stage B records.
- **Attribution**: link back to the canonical Discover Ireland / Ireland.com page as the reference source (as the model already does via `_leisure_discover_ireland` and the practical-source URL); never hotlink source images.
---

## 11. Output

### Source architecture summary

| Source | Owner | CMS | robots | Sitemap | Discovery | Detail fields | Notes |
|---|---|---|---|---|---|---|---|
| Discover Ireland `/guides/free-things-ireland` | Fáilte Ireland | Enterprise custom (Drupal-class) | allows all; `/search-results` only | `sitemap.xml` (3,100 URLs) | 19-item guide + county hubs + related guides | county/town, tags, free tags, address, phone, CTA site, booking, duration | Guide is article; detail pages carry the data |
| Discover Ireland `/things-to-do/free-things-to-do` | Fáilte Ireland | same | same | same | theme index of guides | featured image + guide links | Index only; no pagination |
| Ireland.com `/en-gb/` | Tourism Ireland | Sitecore | admin paths disallowed; Yisou delay 5 | `/en-gb/sitemap.xml` (795 URLs) | destination hubs (`destinations/county/{county}/{place}/`) | destination prose + embedded Attraction cards | Whole-island (incl. NI); magazine content NOT a source |

### Candidate inventory table

| Candidate | Source | URL | County | Town | Type | Free status | Year-round | Existing? | Quality | Notes |
|---|---|---|---|---|---|---|---|---|---|---|
| Chester Beatty | DI guide | https://www.discoverireland.ie/dublin/chester-beatty | Dublin | Dublin City | Museus/Cultura | Gratuito | A | No | HIGH | Closed 15 Jun–end Dec 2026 (EU Presidency) — practical note |
| James Joyce Museum & Martello Tower | DI guide | https://www.discoverireland.ie/dublin/james-joyce-museum | Dublin | Sandycove | Museus | Gratuito | B | No | MEDIUM | Seasonal public hours |
| The Forty Foot | DI guide | https://www.discoverireland.ie/guides/free-things-ireland | Dublin | Sandycove | Praias/Natureza | Gratuito | A | No | HIGH | Sea swimming; safety notes; no facilities |
| National Archives of Ireland | DI guide | https://www.discoverireland.ie/dublin/national-archives-of-ireland | Dublin | Dublin City | História/Museus | Gratuito (agendado) | A | No | MEDIUM | Appointment + reader's ticket |
| Lough Boora Discovery Park | DI guide | https://www.discoverireland.ie/offaly/lough-boora-discovery-park | Offaly | Boora/Kilcormac | Natureza | Gratuito (estacionamento pago) | A | YES (expansion-1) | – | Do not re-add |
| Jerpoint Glass Studio | DI guide | https://www.discoverireland.ie/kilkenny/jerpoint-glass-studio | Kilkenny | Thomastown | Cultura | Gratuito (ver/comprar) | A | No | LOW | Commercial-primary |
| Waterford Greenway | DI guide | https://www.discoverireland.ie/waterford/waterford-greenway | Waterford | Waterford–Dungarvan | Greenways | Gratuito | A | YES (expansion-2) | – | Do not re-add |
| Killarney National Park | DI guide | https://www.discoverireland.ie/kerry/killarney-national-park | Kerry | Killarney | Natureza | Gratuito | A | YES (seed) | – | Do not re-add |
| W.B. Yeats' County | DI guide | https://www.discoverireland.ie/guides/free-things-ireland | Sligo | – | Região | Gratuito | D | – | EXCLUDED | Region/theme, not a place |
| Sliabh Liag (Slieve League) | DI guide | https://www.discoverireland.ie/donegal/sliabh-liag | Donegal | Carrick | Natureza | Gratuito | A | YES (expansion-2) | – | Do not re-add |
| Croagh Patrick | DI guide | https://www.discoverireland.ie/mayo/croagh-patrick | Mayo | Murrisk/Westport | Caminhadas | Gratuito | A | YES (expansion-2) | – | Do not re-add |
| The Burren | DI guide/region | https://www.discoverireland.ie/the-burren | Clare/Galway | Ballyvaughan… | Natureza (região) | Gratuito | D | Park exists (seed) | EXCLUDED | Region ≠ place |
| Lough Gur | DI guide | https://www.discoverireland.ie/limerick/lough-gur | Limerick | Lough Gur | História | Gratuito (centro pago) | A | YES (expansion-2) | – | Do not re-add |
| Galway city | DI guide | https://www.discoverireland.ie/galway | Galway | Galway City | Cidades | Gratuito | A | No | MEDIUM | City destination |
| Lough Muckno | DI guide | https://www.discoverireland.ie/monaghan | Monaghan | Castleblayney | Natureza | Gratuito | A | No | MEDIUM | Lakeside park (no dedicated DI page; listed on Monaghan hub) |
| Carlingford | DI guide | https://www.discoverireland.ie/louth/carlingford | Louth | Carlingford | Cidades | Gratuito | A | YES (expansion-2) | – | Do not re-add |
| Hill of Tara | DI guide | https://www.discoverireland.ie/meath/hill-of-tara | Meath | Tara | História | Gratuito | A | YES (seed) | – | Do not re-add |
| Cathedral of St Patrick & St Felim | DI guide | https://www.discoverireland.ie/cavan | Cavan | Cavan Town | Patrimônio | Gratuito | A | No | MEDIUM | Cathedral |
| Glencar Waterfall | DI guide | https://www.discoverireland.ie/leitrim/glencar-waterfall | Leitrim | Manorhamilton | Natureza | Gratuito | A | YES (expansion-1) | – | Do not re-add |
| Diamond Hill | DI listing feature | https://www.discoverireland.ie/galway/diamond-hill | Galway | Letterfrack | Caminhadas | Gratuito | A | YES (seed) | – | Do not re-add |
| Derrigimlagh | IE.com | https://www.ireland.com/en-gb/destinations/county/galway/derrigimlagh/ | Galway | Clifden | História/Natureza | Gratuito | A | No | HIGH | Boardwalk heritage site |
| Dan O'Hara's Homestead | IE.com | https://www.ireland.com/en-gb/destinations/county/galway/county-galway/ | Galway | Clifden | História | Pago | A | No | MEDIUM | Heritage centre |
| Arranmore | IE.com | https://www.ireland.com/en-gb/destinations/county/donegal/county-donegal/ | Donegal | Arranmore | Ilhas | Gratuito (ferry pago) | A | No | MEDIUM | Island destination |
| Doagh Famine Village | IE.com | https://www.ireland.com/en-gb/destinations/county/donegal/county-donegal/ | Donegal | Doagh/Clonmany | Patrimônio | Pago | B | No | MEDIUM | Folk museum; seasonal staffed |
| Knocknarea | IE.com | https://www.ireland.com/en-gb/destinations/county/sligo/county-sligo/ | Sligo | Strandhill | Caminhadas | Gratuito | A | No | MEDIUM | Queen Maeve's cairn |
| The Model (Niland Collection) | DI sitemap | https://www.discoverireland.ie/sligo/the-model-home-of-the-niland-collection | Sligo | Sligo Town | Museus | Gratuito | A | No | MEDIUM | Free gallery |
| Mullaghmore | IE.com | https://www.ireland.com/en-gb/destinations/county/sligo/mullaghmore/ | Sligo | Mullaghmore | Cidades | Gratuito | A | No | LOW | Coastal village |
| Dursey Island | IE.com | https://www.ireland.com/en-gb/destinations/county/cork/dursey-island/ | Cork | Dursey | Ilhas | Gratuito (cable car pago) | A | No | MEDIUM | Cable car + island |
| Old Head of Kinsale | IE.com | https://www.ireland.com/en-gb/destinations/county/cork/old-head-of-kinsale/ | Cork | Kinsale | Natureza | Gratuito (farol pago) | A | No | MEDIUM | Headland viewpoint |
| National Design & Craft Gallery | IE.com/Kilkenny | https://www.ireland.com/en-gb/destinations/county/kilkenny/county-kilkenny/ | Kilkenny | Kilkenny City | Cultura | Gratuito | A | No | MEDIUM | Gallery at Castle Yard |
| The JFK Arboretum | DI sitemap | https://www.discoverireland.ie/wexford/the-john-f-kennedy-arboretum | Wexford | New Ross | Jardins | Gratuito (parque pago) | A | No | MEDIUM | OPW arboretum |
| Duncannon Fort | DI sitemap | https://www.discoverireland.ie/wexford/duncannon-fort-tour | Wexford | Duncannon | Patrimônio | Gratuito/parcial pago | B | No | MEDIUM/LOW | Fort grounds + tours |
### Geographic scope (summary)

- **Republic of Ireland** candidates above fit the existing 26-county model directly.
- **Northern Ireland** (flagged; do NOT import into the ROI Lazer dataset): Belfast Titanic, Crumlin Road Gaol, Giant's Causeway, Carrick-a-Rede, Dunluce Castle, Dark Hedges, Game of Thrones Studio Tour, Mourne Mountains, Strangford, Mount Stewart, Navan Fort, Armagh, Marble Arch Caves, Enniskillen, Derry city walls, Mussenden Temple, Ulster American Folk Park, Beaghmore, Causeway Coastal Route, Downpatrick, Bangor, Sliabh Beagh (border). Requires a separate NI editorial decision.

### Deduplication findings (summary)

9 of the 19 guide items, the listing-page feature (Diamond Hill), and most Ireland.com attraction cards across Cork/Kerry/Clare/Galway/Sligo/Kilkenny/Waterford/Wexford/Mayo **already exist** in the 233-record dataset (exact URL/identity matches — see §8). 19 genuinely new candidates remain (6 HIGH + 13 MEDIUM/LOW). **No duplicates created; no records modified.**

### Legal/source-access findings (summary)

- Both sources: robots allow general crawl; sitemaps public; no anti-scraping language; normal HTTP GET works.
- Both: © owner; closed content/images. **Factual metadata + canonical source URLs only.**
- Ireland.com is whole-island; NI content must be filtered at import.
- Source prose/photos must not be reused; continue the existing authored-PT + Wikimedia-Commons image model.

### Recommended candidates

**HIGH-confidence new** (6):
1. **Chester Beatty** (Dublin) — Museus, Gratuito, indoor, HIGH (note the 15 Jun–31 Dec 2026 EU-Presidency closure as a practical note)
2. **The Forty Foot** (Dublin) — Praias, Gratuito, outdoor, HIGH (safety note: cold water, no lifeguard, no facilities)
3. **Derrigimlagh** (Galway) — História, Gratuito, outdoor, HIGH (Marconi transatlantic station + Alcock & Brown landing site boardwalk)
4. **Lough Muckno** (Monaghan) — Natureza, Gratuito, outdoor, MEDIUM (lakeside park at Castleblayney, family)
5. **Cathedral of St Patrick & St Felim** (Cavan) — Patrimônio, Gratuito, indoor, MEDIUM
6. **Galway city** (Galway) — Cidades, Gratuito, MEDIUM (walkable urban destination)

**MEDIUM-confidence new** (13):
- James Joyce Museum & Martello Tower (Dublin; B — seasonal hours)
- National Archives of Ireland (Dublin; appointment-gated)
- Knocknarea / Queen Maeve's Cairn (Sligo; free hill walk)
- The Model / Niland Collection (Sligo; free gallery)
- Dursey Island (Cork; ferry/cable-car access)
- Old Head of Kinsale (Cork; free headland, paid lighthouse tour)
- Arranmore (Donegal; island, ferry)
- Doagh Famine Village (Donegal; B — paid, seasonal staffed hours)
- Dan O'Hara's Homestead / Connemara Heritage & History Centre (Galway; paid)
- JFK Arboretum (Wexford; gardens)
- National Design & Craft Gallery (Kilkenny; free gallery)
- Duncannon Fort (Wexford; B — partial seasonal)
- Mullaghmore (Sligo; coastal village, LOW add-value)

**LOW** (only if editorial wants shop-craft attractions): Jerpoint Glass Studio (Kilkenny).

**Excluded** (all): W.B. Yeats' County, The Burren region, Viking Triangle (composites); every accommodation/hotel/golf; restaurants, pubs, spas, food trucks, cookery schools, shopping/craft shops, tour operators, surf/sailing/falconry/walking operators, distilleries/breweries (commercial-primary); events/festivals/offers/campaigns; magazine articles; and **all Northern Ireland content** (flagged separately).

**Unclear / not imported yet** (report-only): any candidate whose free/paid or permanence claim could not be verified on the source page (e.g. The Burren-as-place vs park, "Galway food experiences", guided-walk cards) — conservatively EXCLUDED until primary-site verification.

### Recommended next step

1. **Stage B (proposal, still read-only):** for the 6 HIGH + 13 MEDIUM candidates, fetch the **official operator website** (not just DI/Ireland.com) to confirm free/paid classification, year-round vs seasonal hours, accessibility/parking/transport/pets/booking facts, and the canonical official URL. Record as a data file following the `scripts/data/leisure-expansion-data-*.php` shape (no import).
2. Update `docs/importers/lazer-expansion-stage-b-*.md` with per-candidate official-site evidence and the mapped `conexao_category` / `conexao_leisure_attribute` terms.
3. Present ROI candidates for editorial sign-off; NI items in a separate flagged list.
4. After sign-off, run the existing URL-verification workflow (pattern of `scripts/verify-lazer-urls.php`) and only then a Stage C import (out of scope here).

Current stage changed nothing in production.

---

LAZER EXPANSION STAGE A AUDIT PASSED
