# Events Expansion — Stage A Audit (Eventbrite + National Heritage Week)

**Scope:** Audit only. No Event records created, no production changes, no new
sources activated, no export built. Event Runtime untouched.

**Objective:** Determine whether the existing Event Importer architecture can
support one independently identifiable source per county per provider
(`eventbrite_<county>` / `heritage_week_<county>`) backed by **one reusable
provider implementation**, and audit both public provider surfaces as they
exist today (evidence date: **12 September 2026**, unless noted).

**Audit environment:** Local repository code (`conexao-event-importer`
v1.6.0) + live HTTP fetches from this workstation (residential IP, browser-like
User-Agent). Production (WordPress.com / conexaobr.ie) was **not** accessed.

---

## 0. Verdict (summary)

| Question | Answer |
|---|---|
| One implementation + many source registrations? | **Yes, by design** — but both provider handlers are currently **county-hardcoded** (Laois) and need a bounded parameterization change (§1.5, §3.5) |
| Independent enable/disable per source? | **Yes** (per-source `status` in the Event Sources CRUD) |
| Separate source IDs, logs, health, history per county? | **Yes** — once `get_id()` returns the config ID instead of a hardcoded slug |
| Eventbrite public pages still accessible? | **Yes** (verified live; `window.__SERVER_DATA__` intact on `/d/` discovery pages) |
| Eventbrite Events Search API usable? | **No** — `GET /v3/events/search/` returns `404 NOT_FOUND` (endpoint shut down, verified empirically). The code path using it is **dead and must be removed** |
| Heritage Week listing accessible? | **Yes** (verified live; robots.txt `Allow: /`; no API/JSON-LD) |
| Primary risk found | Eventbrite pagination caps at `page_count = 49` (~980 events); Dublin reports `object_count = 5437` → deep tail unreachable (§13.1) |

**FINAL CLASSIFICATION: EVENTS EXPANSION STAGE A PASSED**
(conditional on the Stage B work items in §14 — none is an architecture
blocker; all are bounded configuration/parameterization work in the
local-only importer plugin.)

---

## 1. Current architecture audit (inspected, unchanged)

### 1.1 Pipeline

```
Provider (public page / feed)
  → Conexao_Source_Base subclass (fetch + parse → raw event arrays)
  → Conexao_Event_Normalizer (dates, times, location, address)
  → Conexao_Event_Deduplicator (find existing)
  → Conexao_Event_Importer_Engine::upsert_event (create/update/unchanged)
  → images → local Media Library
  → Conexao_Event_Export (JSON + embedded images, UUIDs)
  → production JSON import (manual)
```

- Everything is **local-only, manual, on-demand**. No cron, no REST trigger,
  no production-side fetching (external sources block datacenter IPs — by
  design, see `docs/plugins/conexao-event-importer.md`).
- Past-event filtering runs before upsert;
  `Conexao_Event_Status::mark_expired_events()` runs after every run.
- Per-event try/catch: one failing event never aborts the source.

### 1.2 Source registry / configuration

`Conexao_Event_Sources` (option `conexao_event_sources`) stores **arbitrary,
user-defined source IDs** with per-source fields:

| Field | Purpose |
|---|---|
| `id` | Stable source slug (dedup/log identity) — user-defined, not URL-derived |
| `name` | Display name |
| `url` | Feed/listing URL (config, **not** identity) |
| `type` | Handler routing (`icalendar`, `website`, `eventbrite`, …) |
| `status` | `active` / `inactive` — independent enable/disable ✔ |
| `county` | County hint fed to the normalizer |
| `category` | Category hint (e.g. `Heritage`) |
| `last_import`, `last_import_status`, `events_imported`, `last_error` | Per-source health ✔ |

Defaults are seeded on activation; new sources are added via the admin CRUD.
**Nothing in the storage model prevents `eventbrite_laois`,
`eventbrite_cork`, `eventbrite_dublin` coexisting.**

### 1.3 Handler routing

`Conexao_Event_Importer_Engine::get_source_handler()`:

- `type = icalendar` → `Conexao_Source_ICalendar` (any ID)
- `type = eventbrite` → `Conexao_Source_Eventbrite` (**any ID** ✔ — county
  variants already route correctly)
- Exact-ID switch for legacy/website sources: `laois_tourism`,
  `heritage_week` / `national_heritage_week`, `ivvcc`, `motorsport_ireland`,
  `mondello_park`
- Fallback: `conexao_event_importer_get_handler` filter

**Gap:** a source with id `heritage_week_cork` and type `website` falls
through to the filter and returns **null** → the run fails. Stage B must route
Heritage Week county sources (new dedicated `type`, or prefix match).

### 1.4 Source identity & logs

- Every event stores `_event_source` = handler-reported source slug and
  `_event_source_id` = provider event ID. Primary dedup key is the pair.
- `Conexao_Import_Log::add( $source_id, … )` — structured entries keyed by
  source slug, grouped by `run_id`, capped at 2000 entries / 90 days.
- `Conexao_Source_Health` tracks consecutive failures per source.
- Import History records one row per run per source.

**Gap:** both provider handlers hardcode `get_id()`
(`'eventbrite'`, `'heritage_week'`), and `Conexao_Eventbrite_Client`
hardcodes `'eventbrite'` in its log calls. County variants must report
**their own config ID** or all counties share one log stream and one
`_event_source` value.

### 1.5 County hardcoding (the core parameterization gap)

| Location | Hardcode |
|---|---|
| `class-eventbrite-normalizer.php` | `'source' => 'eventbrite'`, `'county' => 'Laois'` |
| `sources/class-eventbrite-source.php` | `is_laois_event()` accepts only region name `'Laois'`; `API_LOCATION = 'Laois, Ireland'` |
| `sources/class-heritage-week-source.php` | `'county' => 'Laois'`; default URL is the Laois filter |
| `class-eventbrite-client.php` | Laois default URL; log source `'eventbrite'` |

The URL is already config-driven in both handlers (`config['url']`), and the
normalizer already honors a per-source `county` **hint**. The shape of the
solution exists; the values must become config-driven.

### 1.6 Deduplication (existing, unchanged)

`Conexao_Event_Deduplicator::find()` order:

1. `_event_source` + `_event_source_id` (exact meta pair)
2. `_event_url` / `_event_source_url` (exact URL match)
3. Content: normalized title + `_event_date` + start time + venue + organizer

### 1.7 Export / production transfer

- Export format v1.1: manifest + events; each event carries `uuid`, `post`,
  `meta` (incl. `_event_source`, `_event_source_id`), `taxonomies`
  (`conexao_category`, `conexao_county`, `conexao_town`), and
  `featured_image` with `data_base64` (>5 MB images fall back to sideload).
- Production matching order (verified in `scripts/ivvcc-production-import.py`
  and `docs/content-model.md`): **UUID → source+source_id → URL → content
  title+date**. County-source-key agnostic; works with new keys as-is.

### 1.8 Architecture verdict for multi-county

| Requirement | Supported today? | Notes |
|---|---|---|
| One implementation | ✔ (with gaps) | Handlers are reusable; values hardcoded |
| Multiple registrations | ✔ | Source CRUD accepts arbitrary IDs |
| Source-specific configuration | ✔ | URL, county, category, type per source |
| Independent enable/disable | ✔ | `status` per source |
| Separate source IDs | ◑ | Storage yes; handlers must report `config['id']` |
| Separate import logs | ◑ | Log infra yes; handlers/client must use config ID |
| Separate health/history | ✔ | Keyed by source ID |
| Dead code | ✘ | `fetch_via_api()` uses the shut-down `/v3/events/search/` endpoint (§3.9) |
| Venue regression | ✘ | Scrape payload now exposes `primary_venue`; normalizer reads `venue` (§3.3) |

---

## 2. Provider audit method

All fetches were plain HTTPS GETs with the project's standard browser-like
User-Agent and `Accept-Language: en-IE,en;q=0.9`. Raw HTML was saved and
inspected (regex/JSON extraction, not headless rendering). Every claim below
marked **verified** was reproduced from the saved responses on the evidence
date. Evidence artifacts (informal, not committed):
`/tmp/eb-{laois,cork,cork-p2,dublin,galway}.html`, `/tmp/hw-laois*.html`.

---

## 3. Eventbrite audit

### 3.1 URL pattern and stability

- Pattern: `https://www.eventbrite.ie/d/ireland--{place-slug}/all-events/`
  (all-events is the default tab; `?page=N` appends cleanly).
- Slugs are **not uniformly county names**: County Cork also resolves as
  `ireland--cork--85684963` (slug + Eventbrite region ID). Verified 200 for:
  `laois`, `cork`, `dublin`, `galway`, `kerry`, `cork-city`,
  `cork--85684963`.
- Northern Ireland lives under a different country segment:
  `https://www.eventbrite.ie/d/united-kingdom--antrim/all-events/` (verified
  200). **Jurisdiction is encoded in the URL country segment** — a useful
  guard for the scope rule (§6).
- **URLs must never be the source identity**: slugs are aliased
  (`cork` vs `cork--85684963`), can gain region-ID suffixes, and are
  marketing surfaces. Identity is the county registry entry (§7); the URL is
  per-source configuration.

### 3.2 Machine-readable structure (verified)

The discovery pages embed a full JSON payload in
`window.__SERVER_DATA__` (one occurrence per page, extracted with the
existing brace-matching parser — `Conexao_Eventbrite_Parser` still parses
current pages correctly):

```
window.__SERVER_DATA__
  └── search_data
       └── events
            ├── results[]      (event objects)
            └── pagination     {object_count, page_count, page_number,
                                page_size, continuation}
```

Observed `pagination` values (first page, 12 Sep 2026):

| County page | object_count | page_count | page_size | Rendered pager |
|---|---|---|---|---|
| Laois | small | 2 | 20 | "1 of 2" |
| Cork | 1562 | 49 | 20 | "1 of 79"* |
| Dublin | 5437 | 49 | 20 | "1 of 272"* |
| Galway | 1288 | 49 | 20 | — |

\* The human-readable pager advertises more pages than the JSON delivers:
`page_count` is **capped at 49** (≈980 events). See §13.1.

### 3.3 Event result fields (verified, Cork page 1 sample of 20)

| Field | Present | Notes |
|---|---|---|
| `id` / `eid` / `eventbrite_event_id` | ✔ | Numeric, e.g. `1998481264259`; stable identity |
| `url` | ✔ | `https://www.eventbrite.ie/e/{slug}-tickets-{id}` — trailing numeric ID is the canonical ID |
| `name` | ✔ | Event title |
| `start_date` / `start_time` | ✔ | ISO date + `HH:MM`, e.g. `2026-09-14`, `22:00` |
| `end_date` / `end_time` | ✔ | e.g. `2026-09-15`, `02:30` (overnight ranges supported) |
| `timezone` | ✔ | `Europe/Dublin` observed |
| `locations[]` | ✔ | Hierarchical: `continent`, `country` (`Ireland`), `region`, `locality`, `neighbourhood` |
| `primary_venue` | ✔ | `{name, address{address_1, address_2, city, region, postal_code, latitude, longitude, localized_address_display}, id, venue_profile_id, venue_profile_url}` |
| `venue` | ✘ | **Absent** from the current payload (see §13.3) |
| `image` | ✔ | `image.url` + `image_sizes{...}` |
| `tags[]` | ✔ | `EventbriteCategory/113` → display_name `"Community & Culture"`; plus SubCategory and Format tags |
| `is_online_event` | ✔ | Boolean; online events skipped by the current source (keep) |
| `is_cancelled` | ✔ | Present (null when false) — cancellation indicator |
| `is_protected_event` | ✔ | Present; protected/private events should be skipped |
| `urgency_signals` | ✔ | `{messages:[], categories:[]}` — messaging surface (sold-out class signals live here; empty in sample) — **do not import**, informational only |
| `summary` / `full_description` | ✔ | Short summary + long copy (data-boundary decision, §10) |
| `tickets_url` | ✔ | Ticket/registration URL (often equal to `url`) |
| `primary_organizer_id` | ✔ | Organizer ID; organizer **name** is not in the event object (list cards show organizer pages separately) |
| `series_id` / `parent_url` / `num_children` | ✔ | All observed `null`/`1` in samples — see §8.5 recurrence |
| `hide_start_date` / `hide_end_date` / `published` / `language` | ✔ | Present |
| `dedup` / `checkout_flow` / `debug_info` | ✔ | Eventbrite-internal; ignore |

### 3.4 Region labels (critical for county validation)

The `locations[]` `region` entry is **not** always the county name:

| County page | `region` names observed on page 1 | venue.address.region |
|---|---|---|
| Laois | `Laois` | — |
| Cork | `Cork City`, `Cork` | `Cork` |
| Dublin | `Dublin City`, `Dunlaoghaire-Rathdown` (no fada) | `Dublin` |
| Galway | `Galway City`, `Galway` | — |

Consequence: the current `is_laois_event()` single-string match must become a
**per-county accepted-region set** held in source configuration (§7.2).
Never search titles/descriptions for the county (existing rule, keep).

### 3.5 Required configuration per county source (Stage B)

1. `id` (e.g. `eventbrite_cork`) — used for `_event_source`, logs, health
2. `county` (canonical county name, feeds the county hint)
3. `url` (the `/d/ireland--{slug}/all-events/` page)
4. accepted `region` label set (from the registry, §6)

### 3.6 Pagination (verified)

- `?page=2` on the Cork page returns `page_number: 2` with a different result
  set — the existing `Conexao_Eventbrite_Client::build_page_url()` pattern is
  still valid.
- `page_size = 20`; `page_count` capped at 49 (§13.1).
- Existing protections: `MAX_PAGES = 50`, 45 s time budget per run, 1 s
  inter-page delay, exponential backoff on 429/500/503. Budget-truncated walks
  resume on the next run via dedup (unchanged behavior).

### 3.7 Sorting / ordering

Default ordering is relevance/recommendation, **not** stable chronological.
Do not rely on list position for anything (identity rule, §8). No sort
parameter is needed because dedup is ID-based.

### 3.8 Behavioral observations

- **Recurring/series events:** `num_children`, `series_id`, `parent_url`
  exist but every observed sample had `num_children = 1` and null series
  fields. Eventbrite repeating events appear either as separate events or as
  parent/child via `parent_url`. Policy: import each listed event as its own
  Event record; **never synthesize a recurrence schedule** from Eventbrite
  data (the recurrence-aware runtime model is fed only by sources that
  provide real recurrence data).
- **Cancellation:** `is_cancelled` flag available on the result object.
  Policy: skip cancelled events (factual availability, reliably exposed).
- **Sold out:** no reliable per-event sold-out flag in the discovery payload;
  `urgency_signals` was empty in all samples. Treat sold-out as
  **not reliably exposed** → do not import availability status from it.
- **Online events:** `is_online_event = true` events are skipped by the
  current source (physical calendar policy). Preserve this per-source toggle
  (make it configurable, default skip).
- **Duplicate events:** `dedup` field exists (Eventbrite-internal); real
  dedup stays on our side by event ID.
- **Venue "TBA":** observed (`Marino · TBA` on the Dublin page). Venue may be
  missing while the date is present — event remains valid with no venue.

### 3.9 API status (verified — do NOT use the search API)

- `GET https://www.eventbriteapi.com/v3/events/search/?location.address=…`
  → `404` `{"error":"NOT_FOUND","error_description":"The path you requested
  does not exist."}` — the deprecated public Event Search endpoint is **shut
  down**.
- `GET /v3/destination/events/` → `401 NO_AUTH`, **and** the same path is
  explicitly `Disallow`-ed in robots.txt → must not be used even with a token.
- Therefore `Conexao_Source_Eventbrite::fetch_via_api()` (which still targets
  `self::API_SEARCH_URL`) is **dead code** and must be removed in Stage B.
  The HTML discovery path is the only supported mechanism.

### 3.10 Access / anti-bot (verified + documented behavior)

- robots.txt (`eventbrite.ie` and `.com` identical): **no Disallow for `/d/`
  discovery pages or `/e/` event pages**; sitemaps published; crawl-delay only
  for Facebot/Slurp/CCBot/AhrefsBot. Obsolete bot UAs (Wget, HTTrack, …) are
  blocked → the existing browser-like UA requirement is correct and must be
  kept.
- Datacenter IPs (e.g. WordPress.com) receive **HTTP 405** from the discovery
  pages (documented from the original Laois import experience; revalidated by
  the architecture note in the plugin docs). Local-only fetching is mandatory.
- Rate-limit behavior: 429/500/503 are retried with exponential backoff
  (existing); 401 and 404 abort the page (existing).
- Keep ≤1 request/second and the existing retry policy.

---

## 4. National Heritage Week audit

### 4.1 Canonical listing URL (verified)

- `https://www.heritageweek.ie/event-listings?q=&where%5B%5D=laois` → 200,
  no redirect, server-rendered HTML.
- Edition shown: **"2026 Events — National Heritage Week 15th – 23rd August
  2026"**. Laois filter returned **77 events**, 12 cards per page → ~7
  pagination pages.
- No public API, RSS, JSON endpoint, or JSON-LD (verified previously and
  re-confirmed: no structured-data blocks on listing or detail HTML).

### 4.2 County filter parameter (verified — exact values from the form HTML)

The location filter is the repeated GET parameter **`where[]`**. Verified
option values (from the raw `<option value="…">` list):

```
antrim  armagh  carlow  cavan  clare  cork  cork-county  derry  donegal
down  dublin-city  dublin-dunlaoghaire-rathdown  dublin-fingal  dublin-south
fermanagh  galway-city  galway-county  kerry  kildare  kilkenny  laois
leitrim  limerick  longford  louth  mayo  meath  monaghan  offaly
roscommon  sligo  tipperary  tyrone  waterford  westmeath  wexford  wicklow
```

Critical label/value mapping (do not guess from the slug):

| where[] value | Display label |
|---|---|
| `cork` | **Cork City** |
| `cork-county` | **Cork County** |
| `galway-city` / `galway-county` | Galway – City / Galway – County |
| `dublin-city`, `dublin-dunlaoghaire-rathdown`, `dublin-fingal`, `dublin-south` | Dublin – Dublin City / Dún Laoghaire-Rathdown / Fingal / South Dublin |
| `laois`, `kerry`, … | plain county names |
| `antrim`, `armagh`, `derry`, `down`, `fermanagh`, `tyrone` | Northern Ireland counties (out of scope, §6) |

Other filter parameters (verified): `q` (free text), `eventDates[]`,
`otherEventDays[]`, `eventType[]`, `eventFeatures[]`.

**Future-year risk:** the 2026 filter structure is unlikely to remain
identical forever. Stage B must (a) treat `where[]` values as per-year
configuration re-verifiable from the form HTML, and (b) fail loudly (empty
fetch + log error) rather than silently import zero events when the filter
shape changes.

### 4.3 Pagination (verified — important)

- The query-string form `?page=2` is **ignored** (returns page 1 — verified:
  identical `data-id` sequence to page 1).
- Real pagination is **path-based**:
  `https://www.heritageweek.ie/event-listings/p2?q&where%5B0%5D=laois`
  (note the normalized `where[0]=…` in the Next-URL), discovered from the
  `nav.paging` → `a.btn-paging` **"Next"** link (`/p3`, etc.).
- The existing `Conexao_Source_Heritage_Week::get_next_page_url()` follows
  exactly this link and already walks all pages correctly. Do not "optimize"
  it into a `?page=N` loop.

### 4.4 Event card structure (verified)

```html
<article class="item item-summary item-full-project col-md-6 …" data-id="1633991">
  <a href="https://www.heritageweek.ie/event-listings/18th-century-female-influencers-at-emo-court">
    <figure class="portrait" style="background-image:url(…/_740x400_fit…/HW-Angelica.jpg)">…
  <header>
    <a class="link-block" href="…">
      <h3 class="title">18th Century Female Influencers at Emo Court</h3>
      <ul class="list-details">
        <li><strong>OPW Emo Court</strong></li>          <!-- organiser -->
        <li>Emo Court, Co. Laois</li>                    <!-- venue/location -->
        <li>OPW Emo Court</li>
        <li>20 August, 2pm - 3pm</li>                    <!-- date/time line(s) -->
      </ul>
```

- **`data-id`** is the only stable event identifier (see 4.5).
- Cards carry no JSON-LD; all data is visible text.
- Multi-session events repeat date lines on one card (e.g. 9 sessions across
  18–22 August) → parsed as one event, start = first session, end = last
  (existing behavior, keep).

### 4.5 Detail URL structure and identity

- Detail URLs are **slug-only** and contain no numeric ID:
  `https://www.heritageweek.ie/event-listings/{slug}`.
- Observed title-collision suffixes (`…-a-georgian-lady-3`, `…-lady-4`) prove
  slugs are de-duplicated per title and therefore **mutable** — they must not
  be the primary identity. The card `data-id` is the strongest identity.
- The existing source already visits each detail page for enrichment
  (description, full location, event type, organiser contact) with a
  fail-soft per-event try/catch. Detail-page cost: ~1 request per event
  (77 for Laois; Dublin is expected to be several hundred → §13.6).

### 4.6 Availability / status indicators

- Listing cards expose no cancelled/sold-out indicators; not verified on
  detail pages → **unknown**, do not import availability status from this
  source.
- `eventFeatures[]` filter values exist per event (see 4.8) — free / paid and
  accessibility are **declared by the organiser**, importable as factual
  fields if Stage B decides to surface them (currently only price text is
  carried through the shared normalizer).

### 4.7 Year behavior (verified)

- Year is detected from the page title (`"2026 Events"` —
  `detect_year()`). The listing represents a **single festival edition per
  year**; outside the season the current-edition listing may be empty or
  show the previous edition. Stage B must log a warning when a fetch returns
  zero cards (already fatal-logged) and must never back-fill a year by
  guessing.
- Historical/archived editions remain reachable by URL but are out of scope
  (past-event filter removes them anyway).

### 4.8 Declared attributes (verified filter values)

- `eventType[]`: `tour`, `exhibition`, `workshop`, `talk`,
  `performance-or-reenactment`, `festival`, `an-opw-site`,
  `heritage-open-doors`, `online`.
- `eventFeatures[]`: `free`, `includes-an-admission-fee`,
  `fully-wheelchair-accessible`, `accessible-by-public-transport`,
  `car-parking-available`, `autism-friendly`,
  `interpreted-with-irish-sign-language-isl`, `vision-impaired-friendly`,
  `as-gaeilge`, `specifically-an-event-for-children`, `suitable-for-families`.
- These are card/detail attributes, not listings metadata; per-event values
  are only fully reliable on the detail page.

### 4.9 Images

- Card images are organiser-uploaded project images on the Heritage Week CDN
  (`ca1-cdn.heritageweek.ie/projects/…`), with logo/placeholder paths
  (`/logos/`) already filtered out by the existing parser (keep).

### 4.10 Access evidence

- robots.txt: `User-agent: *` / `Allow: /` — fully open, no crawl-delay.
- No captcha/bot challenge observed on listing or detail pages.
- Server-rendered HTML (no JS required) — the existing parser approach is
  correct.

---

## 5. Provider county-page evidence table (Eventbrite)

Live evidence per target location (HTTP status + payload check on the
evidence date). "Slug verified" = HTTP 200 on `/d/ireland--{slug}/all-events/`;
"regions verified" = accepted region-label set read from page-1 payloads.

| County (display) | EB URL slug | HTTP | Region labels observed | Verification |
|---|---|---|---|---|
| Laois | `ireland--laois` | 200 | `Laois` | Fully verified (payload parsed) |
| Cork | `ireland--cork` | 200 | `Cork`, `Cork City` | Fully verified (payload parsed; venue region `Cork`) |
| Cork — alias | `ireland--cork--85684963` | 200 | — | Alias resolved (redirect-free) |
| Cork City | `ireland--cork-city` | 200 | — | Slug verified (NOT a separate source; see §6) |
| Dublin | `ireland--dublin` | 200 | `Dublin City`, `Dunlaoghaire-Rathdown` | Fully verified (payload parsed) |
| Galway | `ireland--galway` | 200 | `Galway City`, `Galway` | Fully verified (payload parsed) |
| Kerry | `ireland--kerry` | 200 | — | Slug verified only |
| Antrim (NI) | `ireland--antrim` → `united-kingdom--antrim` | 200 | — | NI namespace verified; out of scope |
| All other ROI counties | `ireland--{county-slug}` (assumed) | — | — | **To verify in Stage B probe** (§14) |

Known Eventbrite region-label rules so far: cities get `<X> City` labels
(`Cork City`, `Dublin City`, `Galway City`), counties get the bare name
(`Cork`, `Galway`, `Laois`), Dublin has four administrative areas. Every
county's accepted-region set must be recorded in the registry (§6) and
re-verified from a live page before activation.

---

## 6. County scope registry

**Scope decision:** Stage B targets the **26 Republic of Ireland counties**.
Northern Ireland (Antrim, Armagh, Derry, Down, Fermanagh, Tyrone — exposed by
both providers) is **out of scope** for this phase; it is recorded here to
make the exclusion explicit, not silently implicit. Online-only events are
excluded (existing `is_online_event` skip). No county is duplicated because a
provider uses multiple labels — multiple provider labels map to **one**
registry row.

Columns: `county` = canonical `conexao_county` term (already seeded, 26
counties, see `docs/events-location-filters-stage-a-report.md`); slug = its
URL slug; HW = the `where[]` value(s) a single Heritage Week source for that
county must request; EB = the Eventbrite discovery slug + accepted region
labels for a single Eventbrite source.

| County | Slug | Jurisdiction | HW `where[]` value(s) | EB slug | EB accepted regions |
|---|---|---|---|---|---|
| Carlow | carlow | ROI | carlow | carlow | Carlow *(tbc)* |
| Cavan | cavan | ROI | cavan | cavan | Cavan *(tbc)* |
| Clare | clare | ROI | clare | clare | Clare *(tbc)* |
| Cork | cork | ROI | **cork-county** | cork | `Cork`, `Cork City` ✔ |
| Donegal | donegal | ROI | donegal | donegal | Donegal *(tbc)* |
| Dublin | dublin | ROI | **dublin-city, dublin-dunlaoghaire-rathdown, dublin-fingal, dublin-south** | dublin | `Dublin City`, `Dunlaoghaire-Rathdown` ✔ (+ Fingal, South Dublin *(tbc)*) |
| Galway | galway | ROI | **galway-county, galway-city** | galway | `Galway`, `Galway City` ✔ |
| Kerry | kerry | ROI | kerry | kerry | Kerry *(tbc)* |
| Kildare | kildare | ROI | kildare | kildare | Kildare *(tbc)* |
| Kilkenny | kilkenny | ROI | kilkenny | kilkenny | Kilkenny *(tbc)* |
| Laois | laois | ROI | laois ✔ | laois ✔ | `Laois` ✔ |
| Leitrim | leitrim | ROI | leitrim | leitrim | Leitrim *(tbc)* |
| Limerick | limerick | ROI | limerick | limerick | Limerick *(tbc)* |
| Longford | longford | ROI | longford | longford | Longford *(tbc)* |
| Louth | louth | ROI | louth | louth | Louth *(tbc)* |
| Mayo | mayo | ROI | mayo | mayo | Mayo *(tbc)* |
| Meath | meath | ROI | meath | meath | Meath *(tbc)* |
| Monaghan | monaghan | ROI | monaghan | monaghan | Monaghan *(tbc)* |
| Offaly | offaly | ROI | offaly | offaly | Offaly *(tbc)* |
| Roscommon | roscommon | ROI | roscommon | roscommon | Roscommon *(tbc)* |
| Sligo | sligo | ROI | sligo | sligo | Sligo *(tbc)* |
| Tipperary | tipperary | ROI | tipperary | tipperary | Tipperary *(tbc)* |
| Waterford | waterford | ROI | waterford | waterford | Waterford *(tbc)* |
| Westmeath | westmeath | ROI | westmeath | westmeath | Westmeath *(tbc)* |
| Wexford | wexford | ROI | wexford | wexford | Wexford *(tbc)* |
| Wicklow | wicklow | ROI | wicklow | wicklow | Wicklow *(tbc)* |
| *Excluded:* Antrim, Armagh, Derry, Down, Fermanagh, Tyrone | — | **NI** | values exist | `united-kingdom--{slug}` | — out of scope |

Rules encoded by this registry:

1. **One source per county per provider.** Cork City / Galway City / Dublin
   subdivisions are **not** separate sources; their provider labels are
   folded into the parent county's source configuration.
2. Heritage Week's Cork/Galway/Dublin splits mean the HW source for those
   counties must issue **multiple filtered listing walks** (one per
   `where[]` value) within one source.
3. `*(tbc)*` region sets are Stage B verification items: one live page fetch
   each before a source is activated (never guess a region label — the
   default when unknown is **reject**, not accept).
4. HW `where[]` values are verified for the 2026 form; re-verify each new
   edition year (§4.2).

---

## 7. Source key design

### 7.1 Scheme

```
eventbrite_<county-slug>       e.g. eventbrite_laois, eventbrite_cork, eventbrite_dublin
heritage_week_<county-slug>    e.g. heritage_week_laois, heritage_week_cork
```

- `<county-slug>` = the registry slug (§6), identical to the
  `conexao_county` term slug. Lowercase `a-z0-9_` (survives
  `sanitize_key()` used by the importer).
- Keys are **provider + county only**. Never:
  - display names (`eventbrite_Cork County` ✘)
  - URLs or URL slugs (`eventbrite_cork_85684963` ✘ — alias-prone)
  - page positions (`eventbrite_p3` ✘)
  - labels that change between editions (`where[]` spellings ✘)
- Keys stay stable even if page structure, slugs, or filter values change,
  because all provider specifics live in the per-source configuration, not in
  the key.

### 7.2 Source configuration schema (per county source)

| Key | Eventbrite source | Heritage Week source |
|---|---|---|
| `id` | `eventbrite_cork` | `heritage_week_cork` |
| `type` | `eventbrite` (existing handler routing ✔) | new `heritage_week` type (Stage B) |
| `county` | `Cork` | `Cork` |
| `url` | `…/d/ireland--cork/all-events/` | `…/event-listings?q=&where[]=cork-county` |
| `region_labels` (new) | `Cork`, `Cork City` | — |
| `hw_where` (new) | — | `cork-county` (array — Dublin/Galway have several) |
| `status` | `inactive` until verified | `inactive` until verified |
| `category` | optional | `Heritage` |

---

## 8. Event identity & deduplication

### 8.1 Primary identity per provider

| Provider | ID | Where obtained | Stability |
|---|---|---|---|
| Eventbrite | trailing numeric ID (`id`, `eventbrite_event_id`, also the URL tail `…-tickets-{id}`) | discovery payload | Global, unique across all counties — **strongest** |
| Heritage Week | card `data-id` (e.g. `1633991`) | listing card attribute | Unique within the platform; detail URLs are slug-only and mutable → data-id is **required** |

Stored as: `_event_source` = county source key (§7.1), `_event_source_id` =
provider event ID. Dedup step 1 (`source` + `source_id`) matches re-runs of
the same source exactly.

### 8.2 Fallback identity: canonical detail URL

Dedup step 2 (exact `_event_url` / `_event_source_url` match) already
covers the loss-of-ID case. Eventbrite URLs embed the numeric ID, so URL
matching is strong there. Heritage Week detail URLs are slug-based and
can gain `-N` suffixes on title collisions — URL matching is a weaker
fallback for HW; acceptable as fallback only.

### 8.3 Last resort: content match

Title + date + time + venue + organizer (existing
`find_by_content()`). Used only when neither ID nor URL matched. It is the
bridge for cross-provider duplicates; see §9.

### 8.4 Same-provider duplicates across county sources

The same Eventbrite event can surface near county borders on two discovery
pages. Protection layers:

1. The accepted-region-label filter (§3.4) assigns each event to exactly one
   county source — overlap becomes rare.
2. If the same event ID/URL is nonetheless fetched by two county sources,
   dedup step 2 (URL) prevents a second Event record; the second county
   source simply updates the existing record (its `_event_source` keeps the
   first key). Verified acceptable: an Event's county term is set from the
   source config, so a border event keeps its first-imported county. Stage B
   must log `cross-source url match` at info level so this is visible.
3. **Never** weaken `_event_source_id` uniqueness (e.g. prefixing with
   county) to make cross-county matching work — that would break the
   provider-ID identity for no benefit.

### 8.5 Recurring-event records

Eventbrite: `series_id`/`parent_url`/`num_children` were all inert in the
audited payloads; each listed occurrence is treated as an independent event.
Heritage Week: multi-session events are one record with first→last session
range. The recurrence-aware runtime (`Conexao_Event_Query`) handles multi-day
records already; no recurrence synthesis from either provider.

---

## 9. Cross-provider duplication risk (Eventbrite ↔ Heritage Week ↔ existing)

The same physical event may be listed on Eventbrite, Heritage Week, and
existing sources (Laois Tourism ICS, IVVCC, Motorsport Ireland, Mondello
Park). The existing importer handles this **conservatively** and the audit
recommends keeping it that way:

### 9.1 Existing cross-source behavior

- Dedup steps 1–2 are provider-scoped (source key + provider ID; source URL).
  They never merge across providers.
- Dedup step 3 (content: title + date + time + venue + organizer) **does**
  match across providers. First import wins: the existing record keeps its
  `_event_source`, and later providers' matches take the `unchanged`/`updated`
  path instead of creating a second Event.
- There is **no canonical-source priority list** and **no secondary-URL
  preservation** today (`_event_source_url` is single-valued). Both are
  pre-existing characteristics, not regressions.

### 9.2 Policy for the expansion (documented rules)

1. **Same provider, same county, re-run:** source+ID match → update in place
   (no duplicates). Established behavior, verified in production usage.
2. **Same provider, different county sources:** handled by URL matching +
   logging (§8.4). Region filtering makes this rare.
3. **Cross-provider:** content match may merge when the evidence is strong
   (title equal after normalization, same start date, same venue). This is
   correct for Heritage Week ↔ Eventbrite overlaps of the same organised
   event (e.g. an OPW property open day listed in both).
4. **Ambiguity rule (do not merge):** when venue or time is missing on either
   side, or only the title matches, do **not** merge silently. Events stay
   separate records; the next import simply leaves them unchanged. No
   identity weakening is acceptable to force merges — a false merge destroys
   a real event; a near-duplicate costs a listing row.
5. **Secondary source URLs:** not preserved today. Optional Stage B
   enhancement: `_event_source_url_alt` (append-only). Not required for the
   expansion; the detail page link (primary source URL) is always shown.
6. **Canonical source selection:** none. First import wins; content matches
   update facts only (upsert's unchanged/updated path). Acceptable: both
   providers expose equivalent factual fields, and source attribution stays
   visible on the card (`_event_source` admin column).
7. Stage B should add an **info-level log entry on every cross-source content
   match** (currently silent) so near-duplicate pairs can be reviewed in the
   Import Logs screen.

---

## 10. Data / content boundary

The importers collect **factual event information only** and link back to the
source. This reuses the project's established source-content boundary (see
existing importer audits in `docs/importers/`).

### 10.1 Import (allowed)

| Field | Eventbrite | Heritage Week |
|---|---|---|
| title | `name` | `h3.title` |
| date / time | `start_date/time`, `end_date/time` (ISO) | session lines parsed to Y-m-d / H:i |
| venue | `primary_venue.name` | card + detail location lines |
| address | `primary_venue.address` (Eircode included) | detail address lines (deterministic signals only) |
| town | `locations[].locality` / venue `city` | venue-line normalization (national town index) |
| county | source config (region-validated) | source config |
| organizer | organizer name when exposed; `primary_organizer_id` otherwise | `<strong>` organiser |
| category | `tags[]` EventbriteCategory display name | `Heritage` (source hint; detail event type when available) |
| source URL | event `url` (detail) | detail URL |
| ticket URL | `tickets_url` | detail URL (registration is the detail page) |
| price | `is_free`/price text when exposed | free / admission fee (declared) |
| image | single event image (`image_sizes.medium`) | single card image (`/projects/` only) |
| availability | `is_cancelled` (skip cancelled) | none exposed |

### 10.2 Not imported (boundary)

- Long promotional copy: Eventbrite `full_description` is editorial text —
  prefer `summary` (short, organiser-written factual teaser). Heritage Week
  detail descriptions are organiser-provided; keep the existing import but
  **cap stored length** (Stage B constant, e.g. first ~500 words) and strip
  embedded navigation.
- Site navigation, marketing banners, cookie/consent text.
- Provider logos and placeholder images (already filtered:
  `/logos/`, `placeholder`, `default` patterns).
- `urgency_signals`, `dedup`, `checkout_flow`, `debug_info`, tracker URLs.
- No new images beyond the single event banner (existing image handler
  behavior).

---

## 11. Location normalization strategy

The site supports county + city/town filtering (`conexao_county` /
`conexao_town`; see `docs/events-location-filters-stage-a-report.md`). The
existing pipeline already guarantees: county term always set (source hint),
town term optional, and county-only events render fine (`event_location`
display falls back venue → town → county; 14/39 current events are
county-without-town by design).

| | Eventbrite | Heritage Week |
|---|---|---|
| **Direct source value (county)** | `locations[]` region entry — validated against the source's accepted-region set; county assigned from **source config**, never from the label | card location lines (`Co. Laois`) + source config hint |
| **Direct source value (town)** | `locations[]` `locality` entry; venue `address.city` | venue lines (e.g. `O'Moore Street, Mountmellick`); detail page address |
| **Normalization rule** | locality/city string → `Conexao_Event_Location` → `ensure_town()` (exact/national index; else created) | same shared pipeline |
| **Missing town** | valid event (venue/county remain) | valid event |
| **Ambiguous town** | locality equal to county name (`Cork`, `Dublin`) → town term = county name; acceptable (existing rule) | same |
| **Rejected** | events without an accepted region label (default **reject**) | events outside the configured `where[]` scope cannot appear |

Do not guess towns from titles or venue names not in the index — the existing
rule (deterministic signals only) stands.

---

## 12. Date / time strategy

Reuse the existing Event date/time model (`_event_date`, `_event_end_date`,
`_event_start_time`, `_event_end_time`; recurrence-aware runtime). Never
invent times.

| Capability | Eventbrite | Heritage Week |
|---|---|---|
| Single-day events | ✔ (end defaults to start) | ✔ (one session line) |
| Multi-day ranges | ✔ (`end_date` distinct from `start_date`) | ✔ (first→last session; existing parser) |
| Start time | ✔ exact (`22:00`) | ✔ (`2pm` → `14:00`) |
| End time | ✔ exact (incl. past midnight, `02:30`) | ✔ (last session end) |
| Recurring events | appear as separate events / parent-child; **no schedule synthesis** | multi-session cards → one record spanning sessions |
| TBC/TBA dates | not observed (dates always present; venue can be `TBA`) | none observed within an edition |
| Timezone | explicit `Europe/Dublin` per event | implicit Europe/Dublin (Irish festival week, no DST hazard); year from page title |
| Model mapping | `_event_date` + `_event_start_time` (+ end pair) | same |

---

## 13. Technical limitations & risks

1. **Eventbrite pagination cap (verified).** `page_count` is capped at 49
   (page_size 20 ⇒ ≈980 reachable events) even when `object_count` is much
   larger (Cork 1562, Dublin 5437). Deep-tail events are unreachable via the
   public page. Mitigations to probe in Stage B (none verified yet):
   date-filtered discovery URLs (e.g. `/d/ireland--dublin/all-events/?date=…`)
   to split the walk into windows; city sub-pages for Dublin
   (`/d/ireland--dublin--{city}/`). Accepted residual risk: large metros may
   import a rolling window of the next ~980 events, which is far beyond the
   site's display horizon (upcoming events only).
2. **Dead API path.** `fetch_via_api()` targets the shut-down
   `/v3/events/search/` → must be removed (§3.9). No sanctioned replacement
   endpoint is both authorized and robots-permitted.
3. **Venue payload drift (verified).** The scrape payload now carries
   `primary_venue` (name + address); the normalizer reads the old `venue`
   key → venue name/address silently empty on the current page shape. Fix in
   Stage B (read `primary_venue`, keep `venue` for compatibility).
4. **Datacenter blocking.** Eventbrite discovery blocks datacenter IPs (405).
   Imports must remain local-only (existing architecture; no change).
5. **Heritage Week seasonality.** One festival edition per year; empty
   listing outside the season. Empty-fetch must log an error (existing fatal
   log) and stage activation accordingly.
6. **Heritage Week detail-page cost.** One request per event for enrichment
   (77 for Laois; Dublin expected several hundred). No time budget exists in
   the HW source; Stage B should add a budget/warning similar to the
   Eventbrite source.
7. **`?page` ignored by Heritage Week** — any refactor must keep the
   Next-link walk (§4.3).
8. **Region-label drift.** Eventbrite region labels are not guaranteed
   stable; the accepted-region sets are config, re-verifiable per activation
   (§14). Default policy when a label is unknown: reject + log, never accept.
9. **Duplicate-risk visibility.** Cross-source content merges are silent
   today; add info-level logging (§9.2.7).
10. **Filter-structure drift (Heritage Week).** `where[]` values may change
    between editions; re-verify per year (§4.2).

---

## 14. Stage B pre-activation verification probes (required)

Per county, before its source is switched to `active`:

1. Eventbrite: fetch `/d/ireland--{slug}/all-events/` → expect 200 +
   `__SERVER_DATA__`; record the page-1 `region` label set into the registry;
   confirm `page_count`/`object_count`.
2. Heritage Week: fetch the filtered listing → expect ≥1 `item-summary` card
   or a logged empty-edition warning; confirm the `where[]` value from the
   form HTML; confirm the Next-link pagination still matches
   `nav.paging .btn-paging`.
3. Both: dry-run import (`wp conexao-events import --source=<key> --dry-run`)
   and review counts/skips before enabling.

---

## 15. Recommended implementation architecture (Stage B)

**Principle: prefer the existing architecture.** No engine, dedup, export,
runtime, or admin-UI redesign is required. The expansion is:

1. **Parameterize the two provider handlers** (one implementation each):
   - `get_id()` → `config['id']` (fallback to legacy slug); pass the source
     ID into `Conexao_Eventbrite_Client` for logging.
   - County from `config['county']`; Eventbrite region validation from
     `config['region_labels']` (replaces `is_laois_event()`).
   - Heritage Week: support multiple `where[]` walks per source
     (`config['hw_where']` array).
   - Remove `fetch_via_api()` and the Laois constants.
   - Normalizer: read `primary_venue` (keep `venue` fallback).
2. **Handler routing:** keep `type = eventbrite`; add a Heritage Week type
   (or prefix-match `heritage_week_*`) so `heritage_week_cork` resolves to
   `Conexao_Source_Heritage_Week`.
3. **County registry as code:** a single array (county → EB slug, EB region
   labels, HW `where[]` values) that (a) generates source configs and (b)
   is the single source of truth for activation probes (§14).
4. **Seed script (WP-CLI or activation-safe):** creates/updates the N county
   sources with `status = inactive`; enable county-by-county after probes.
5. **Legacy key migration (one-time, local):** existing events carry
   `_event_source = 'eventbrite'` / `'heritage_week'` (Laois). Either keep
   those keys for Laois (simplest; document the exception in the registry) or
   rename to `eventbrite_laois` / `heritage_week_laois` with a one-off
   meta update (`_event_source` bulk set) **before** the first import under
   new keys, so dedup step 1 keeps matching. Do not leave mixed keys for the
   same provider+county.
6. **Logging:** cross-source content matches logged at info level (§9.2.7);
   empty HW fetches and unknown EB region labels logged as errors.
7. **Docs:** update `docs/plugins/conexao-event-importer.md` (source schema,
   county registry, Stage B report) in the same change.

Suggested Stage B split (mirrors the Lazer expansion pattern): Stage B =
parameterization + registry + probes (dry-run only), Stage C = enable
first county batch + first export, Stage D = production import + report.

---

## 16. Final classification

**EVENTS EXPANSION STAGE A PASSED**

Justification: both providers are verifiably accessible today with factual,
stable identities (Eventbrite numeric ID; Heritage Week card `data-id`);
the existing source-registry architecture already supports multiple,
independently configured, independently toggleable county sources with
separate logs and health; and all gaps found are bounded, local-only
parameterization work (§15) — none alters production behavior, the Event
Runtime, or the data model. The audit deliberately created no Events, no
sources, and no exports.
