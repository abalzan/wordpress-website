# Mondello Park Importer — Stage B Documentation

**Date:** 2026-09-11
**Stage:** B — local-only implementation, tests, dry-run
**Source key:** `mondellopark`
**Status:** READ-ONLY — no production writes, no production activation.

---

## Source purpose

Import events from Ireland's National Motorsports Campus (Mondello Park) into
the local Conexão event database for eventual export to production. The source
provides motorsport event listings (car racing, drifting, rally, motorbike
racing, JDM, retro/historic, shows, IDS).

## Discovery

**Primary:** `https://mondellopark.ie/wp-json/wp/v2/events?per_page=100`

- WordPress REST API (self-hosted, SiteGround edge).
- `X-WP-Total: 29`; `per_page=100` returns all 29 events in a single JSON
  request (no pagination).
- Returns `id`, `slug`, `title`, `link`, `date` (post_modified), `modified`,
  `event_category` (term IDs), `featured_media`, `yoast_head`.
- **Does NOT return event date/time/venue/price/ticket.**

**Secondary (cross-verification):** `https://mondellopark.ie/events-sitemap.xml`

- Yoast sitemap, 29 URLs, each with `lastmod` = WP `modified_gmt`.
- Used only for cross-verification, not as the primary fetch source.

**Listing page (human):** `https://mondellopark.ie/events-tickets/`

- Shows only 6 upcoming events as `<article data-event-item>` cards.
- Not used for discovery (REST covers all published events).

## Detail URL

`https://mondellopark.ie/events/{slug}/`

- Authoritative event date, location, and ticket URL are extracted from the
  detail page HTML. The REST `date` field is the WP post_modified timestamp and
  is NEVER used as the event date.

## Identity strategy

- **Primary:** `_event_source = mondellopark`, `_event_source_id = {slug}`.
- The slug is stable and unique across the full discovered event set (verified
  in the live REST snapshot on 2026-09-11: 29/29 unique slugs, 29/29 unique
  WP IDs).
- The WordPress REST source post ID (`id` field) is recorded in
  `raw['wp_rest_id']` for reference but is never used as the primary identity.
- Matching order follows the existing Conexão convention:
  `source + source_id → URL → content title + date`.
- Re-running the same import does not create duplicates (deduplication by
  `source + source_id`).

## Date strategy

- **Source:** visible HTML text on the detail page.
- **Format:** US English `Month DD, YYYY` (e.g. `September 12, 2026`).
- **Range format:** `Month DD, YYYY - Month DD, YYYY` (e.g.
  `September 12, 2026 - October 14, 2026`).
- **Same-day range** (e.g. `Sep 6 - Sep 6` or `September 12, 2026 - September
  12, 2026`) collapses to a single day.
- **Parser:** `Conexao_Source_Mondello_Park::parse_date_range()` and
  `parse_us_month_day_year()`.
- **Whitespace normalization:** performed before parsing.
- **Punctuation normalization:** unicode dashes (`–`, `—`, `−`, `‐`, `⁔`)
  normalized to ASCII hyphen.
- **TBA/TBC/TBD/to-be-confirmed/to-be-advised:** rejected (no date parsed).
- **Invalid dates:** rejected (e.g. `February 30, 2026`). The existing
  `Conexao_Event_Date_Filter` handles these as `INVALID`.
- **No machine-readable date** exists anywhere in the source (no
  schema.org/Event, no ISO dates, no machine-readable metadata).

## Time strategy

- **Source:** none.
- No event start/end times are machine-readable anywhere in the source.
- `_event_start_time` and `_event_end_time` are left empty.
- Times are NOT inferred from ticketing "doors/opening" text or any other
  secondary information.
- If later source evidence proves a reliable event-time field, the parser can
  be extended — but as of Stage B no such evidence exists.

## Location strategy

- **Source-supplied values only.** The venue/location text is scraped from the
  detail page `<p class="event-location">` element when present.
- The footer address of Mondello Park (Donore, Naas, Co. Kildare, Eircode
  W91 T957) is NOT hard-coded into every event.
- Where the detail page omits address/town/county/Eircode, those fields are
  left empty rather than invented.
- **Source-scoped county hint (wired in Stage C preparation, 2026-09-12):**
  the source config carries `county => 'Kildare'` and `fetch_events()` now
  forwards that value into `raw['county']` for every Mondello Park event.
  The normalizer (`Conexao_Event_Normalizer`) applies this hint ONLY when the
  location string does not already yield a county, so:
  - **A. source supplies a county** (e.g. `Mondello Park, Co Wicklow`) → the
    source-supplied county is preserved (`Wicklow` wins over the hint).
  - **B. source omits the county** and the config declares `Kildare` →
    `Kildare` is applied as the source-scoped fallback county.
  - **C. config has no county** → an empty hint is forwarded and behavior is
    unchanged (event without location is still skipped).
  This is a county taxonomy tag only: no street address, town or Eircode is
  ever derived from it. Previously the declared hint was not forwarded, so
  valid future events reached location validation without county information
  and the dry-run reported `0 would-create / 0 would-update`.
- "Mondello Park" alone is a venue label, not an address — the existing
  `Conexao_Event_Address::is_plausible()` no-guess rule correctly rejects it as
  an address candidate, so no special source flag is required.
- Missing location data is preserved (not filled in).

## Category mapping

| Mondello Park term ID | Mondello Park slug | Conexão category name |
|---|---|---|
| 23 | car-racing | Car Racing |
| 22 | drifting | Drifting |
| 34 | rally | Rally |
| 38 | motorbike-racing | Motorbike Racing |
| 35 | jdm | JDM |
| 18 | retro-historic | Retro/Historic |
| 47 | shows | Shows |
| 36 | ids | IDS |

- Mapping is performed by `Conexao_Source_Mondello_Park::map_categories()`
  using the WordPress REST `event_category` term IDs.
- Multiple categories are preserved when actually present on the source event.
- Unknown category IDs are logged as warnings and skipped from mapping (never
guessed).
- The existing Conexão `conexao_category` taxonomy already contains all
  required terms; no new categories are created.

## Ticket URL strategy

- **Primary:** the "Book Now" link on the detail page, which points to
  Ticketsolve: `https://mondellopark.ticketsolve.com/ticketbooth/shows/{showId}`.
- **UTM stripping:** `utm_source`, `utm_medium`, `utm_campaign` and similar
  query parameters are stripped. `showId` is preserved.
- **Secondary show IDs:** if the detail page contains multiple Ticketsolve
  links, the "Book Now"-labelled link (see below) is used as the primary;
  secondary show IDs are recorded in `raw['_ticket_show_ids']` custom meta
  only — never in a URL field.
- **External ticket providers:** if an external (non-Ticketsolve) ticket link
  is explicitly present, it is captured as the ticket URL.
- **Winter Bash:** the detail page contains TWO Ticketsolve show IDs. The
  primary "Book Now" link encountered in the detail page is used. Both IDs are
  logged for reference. The canonical Mondello Park event page remains the event
  identity; the ticket URL is not identity.
- **Winter Bash resolution (verified against the live page, 2026-09-12):**
  the live detail page renders the secondary `DRIFT BASH TICKETS` button
  (`…/shows/1173670623`) **before** the primary `Book Now` button
  (`…/shows/1173670621`). The earlier "first Ticketsolve link encountered"
  heuristic therefore selected the wrong (secondary) show ID — the Stage C
  dry-run had recorded `1173670623` as primary. `extract_ticket_url()` now
  prefers: (1) a `book-now` class hook, (2) a Ticketsolve anchor labelled
  "Book Now", (3) first Ticketsolve link in DOM order. **Primary Book Now
  show ID = `1173670621`** (the Stage B value was correct); `1173670623` is
  the secondary "DRIFT BASH TICKETS" body link, recorded only in
  `raw['_ticket_show_ids']`.
- **James Deane 130 Showdown:** ticketing destination is external
  (130showdown.com). The Mondello Park event page itself is a valid event
  source; the event is imported with canonical + dates as a free event at
  admin discretion. The event is NOT skipped solely because ticketing is
external.

## Skip / edge-case behavior

- **Past-dated events:** skipped (the existing `Conexao_Event_Date_Filter`
  enforces "never creates past events" — mandatory because the source
  publishes past events).
- **FIA Euro RX (`fia-euro-rx`):** the detail page redirects externally
  (301 to `https://erxmondellopark.com/index.html`). The event is flagged as
  `_redirect` and reported. The canonical Mondello Park event identity
  (slug + REST metadata) can still be established from the listing; the detail
  page is not fetched successfully. At admin discretion the event may be
  imported with REST-metadata only or skipped.
- **James Deane 130 Showdown (`james-deane-130-showdown`):** external ticket
  destination only. The Mondello Park event page itself is a valid event source.
  Import canonical + dates as a free event at admin discretion.
- **Missing detail pages:** flagged `_no_detail`, reported in logs, no date
  parsed (event will be skipped by date filter as invalid).
- **Redirects:** flagged `_redirect`, reported in logs.
- **Malformed responses:** the source returns empty on non-200 or invalid JSON;
  logged as error.
- **Duplicate IDs / duplicate slugs:** collapsed within the REST response
  (defensive — the live REST set has 29/29 unique slugs and 29/29 unique IDs).

## Crawl delay

- 1.0 second between detail-page requests (polite crawl).
- Browser-like User-Agent + `Accept-Language: en-GB`.
- Exponential back-off on 403.
- Never hits `tickets.mondellopark.ie` or `ticketsolve …/api/`.

## Source-use / content boundary

**Imported (factual fields only):**

- Title
- Factual event date (from detail page)
- Factual end date (from detail page, when multi-day)
- Venue / location / address when source supplied
- Organizer when explicitly supplied
- Category (mapped from source term IDs)
- Ticket URL (primary Book Now link, utm-stripped)
- Canonical Mondello Park event URL
- Source identity (`_event_source`, `_event_source_id`)
- Source attribution where existing architecture supports it

**Not imported:**

- Promotional copy (`the-content` marketing text)
- Large images / logos
- PDFs
- Ticket terms
- Copyrighted marketing copy

## Update behavior

- Re-running the import does not create duplicates (dedup by
  `source + source_id`).
- Existing events are updated only when data changed (title, date, URL, venue,
  organizer, banner, address).
- Past events are never created or updated by an import run (date filter).
- Expired events are marked by the shared `Conexao_Event_Status::mark_expired_events()`
  at the end of each import run (no cron).

## Idempotency

- Same identities on every run.
- No identity drift.
- Second run produces zero unexpected creates.
- No duplicate source identities.

## Known limitations

1. **No event times.** No machine-readable event start/end time exists anywhere
   in the source. Time fields are always empty.
2. **No event dates in REST.** The REST `date` field is the WP post_modified
   timestamp. The authoritative event date is visible HTML text on the detail
   page only.
3. **No structured data.** No schema.org/Event anywhere in the source.
4. **Ticketsolve is a JS SPA.** Prices/availability are unreachable (no
   `/api/` access, 403). Only the "Book Now" URL is captured.
5. **Past events are published.** The source publishes all 29 events (6 upcoming
   + 23 past/archived). The date filter is mandatory.
6. **FIA Euro RX detail page redirects externally.** The event identity can be
   established from the listing; the detail page is not fetched successfully.
7. **James Deane 130 Showdown has external ticketing only.** The event is
   imported at admin discretion.
8. **Location data is inconsistent.** Some events have a location paragraph,
   some do not. Missing data is preserved (not invented).

## Production activation procedure

1. Review the local dry-run results.
2. If acceptable, activate the source in WordPress admin:
   - Event Import → Event Sources → Mondello Park → check "Active" → Save.
3. Run a dry-run import first:
   - `wp conexao-events import --source=mondello_park --dry-run`
4. Review the dry-run output.
5. If acceptable, run the live import:
   - `wp conexao-events import --source=mondello_park`
6. Verify events in wp-admin.
7. Export to production:
   - Event Import → Export Events → download JSON.
8. Import on production WordPress.com.

**Do not activate the source in production or create production events without
explicit Stage C authorization.**

---

## Stage C pre-production preparation (2026-09-12)

Read-only verification pass after wiring the source-scoped Kildare county hint
into `raw['county']`. No production writes, no production activation.

### Verification results

| Check | Result |
|---|---|
| County wiring fix (config hint → `raw['county']`) | PASS (behaviors A/B/C tested) |
| Focused Mondello suite | 130/130 PASS (was 106; +24 county-wiring assertions) |
| Full regression — Eventbrite | PASS 68/68 |
| Full regression — IVVCC | 45/46 — 1 PRE-EXISTING failure (`different ID+URL does not collapse`, unchanged from Stage B) |
| Full regression — Motorsport Ireland | PASS 60/60 |
| Full regression — shared address/normalizer | PASS 76/76 |
| Full regression — past-event filter / log / errors / image-sync | PASS 29/0, 53/0, 55/0, 30/0 |
| Full regression — Event Runtime (query/recurrence) | PASS (query suite is flaky only when suites run concurrently — ENVIRONMENTAL; 3/3 clean runs after the fix) |
| Live read-only dry-run | PASS (below) |
| Source key | `mondellopark` |
| Inactive by default | PASS (`get_defaults()` seeds `status = inactive`; `get_all()` merge preserves declared inactive status for missing defaults) |
| Production endpoints / credentials / TicketSolve API / production writes | NONE / NONE / NONE / 0 |

### Live dry-run after the fix (2026-09-12, UTC)

found=29, unique=29, parsed=27 (detail pages engaged; 1 detail fetch failure,
1 undated prose-only page), would-create=6, would-update=0, would-skip=23
(past=21, invalid_date=2), skipped=23, ambiguous=0, failed=0.

| slug | title | date | county | category | ticket URL | result |
|---|---|---|---|---|---|---|
| james-deane-130-showdown | James Deane – 130 Showdown | 2026-09-19 → 2026-09-20 | Kildare | Drifting | canonical detail URL (external ticketing) | would-create |
| drift-games-winter-bash | Drift Games Winter Bash | 2026-11-14 → 2026-11-15 | Kildare | Drifting | ticketsolve …/shows/1173670621 (primary) | would-create |
| iccr-september-2026 | ICCR September 2026 | 2026-09-12 → 2026-09-13 | Kildare | Car Racing | ticketsolve …/shows/1173669529 | would-create |
| irx-october-2026 | IRX October 2026 | 2026-10-03 → 2026-10-04 | Kildare | Rally | ticketsolve …/shows/1173669538 | would-create |
| iccr-october-2026 | ICCR October 2026 | 2026-10-18 | Kildare | Car Racing | ticketsolve …/shows/1173669532 | would-create |
| irx-november-2026 | IRX November 2026 | 2026-11-28 → 2026-11-29 | Kildare | Rally | ticketsolve …/shows/1173669539 | would-create |
| fia-euro-rx | FIA Euro RX | (none) | Kildare | Rally | canonical detail URL | would-skip (invalid date — audited external redirect; page date exists only as prose, never guessed) |
| masters-superbike-may-2026 | Masters Superbike | (none) | Kildare | Motorbike Racing | canonical detail URL | would-skip (invalid date — detail fetch failed, no invention) |
| (21 further events) | — | past | — | — | — | would-skip (past) |

Legitimate skips are expected and correct: the goal was that valid future
events are no longer discarded because the documented Kildare hint was not
forwarded — achieved.

### Winter Bash ticket-ID discrepancy — resolved (Stage C pre-write check, 2026-09-12)

Stage B evidence recorded the Winter Bash primary "Book Now" Ticketsolve show
ID as `1173670621`; the Stage C readiness dry-run recorded `1173670623`. Both
values were re-verified against the **current live source**
(`https://mondellopark.ie/events/drift-games-winter-bash/`):

| Live anchor (in DOM order) | href show ID | Role |
|---|---|---|
| `<a class="btn" …>DRIFT BASH TICKETS</a>` | `1173670623` | Secondary body link |
| `<a class="btn " …>Book Now</a>` | `1173670621` | **Primary "Book Now" destination** |

**Decision:** the live source proves the Stage C dry-run value (`1173670623`
as primary) was wrong — the extractor's "first Ticketsolve link encountered"
fallback picked the secondary button that precedes the Book Now button in the
DOM. The Stage B value (`1173670621`) is correct. The extractor was fixed
(prefer `book-now` class → "Book Now" label → DOM order), tests extended
(130 → 140 assertions, all PASS), and the documentation corrected. No
production writes occurred before this resolution.

### Stage C production import attempt — BLOCKED (2026-09-12, UTC)

The pre-write discrepancy (Winter Bash ticket ID) was resolved first (see
above). The production attempt then proceeded through a controlled REST
pipeline (`scripts/mondello-production-import.py`, modeled on the IVVCC
production scripts: `.env` application-password auth, six-identity allowlist,
hard gates on pre-existing Mondello identities/slug clashes, dry-run default,
before/after verification reads):

- Pre-flight: production baseline **43 published events**, **0** Mondello
  identities, **0** slug clashes; runtime meta registration visible (31 keys);
  export validated (6 allowlisted events).
- Dry-run mutation set: **6 would-create / 0 would-update / 0 failures** —
  exactly the approved scope.
- Apply: the FIRST create (`james-deane-130-showdown`) was created and
  published (post **11705**, author 283039558) but **every `_event_*` meta key
  was refused** — `403 rest_cannot_update` on `_event_date` (and all other
  `_event_*` keys). A follow-up diagnostic meta PATCH on 11705 (the IVVCC
  Phase C mechanism) also failed: `401 rest_cannot_edit`, and
  `/users/me` returns `401 rest_not_logged_in` with the same credentials that
  previously performed IVVCC Phase C meta writes (documented PASS).

**Result: Stage C = BLOCKED.** The remaining five events were NOT created
(the pipeline aborts on first failure). Production state after the attempt:

- 44 published events (43 baseline + 1 partial post 11705).
- Post 11705 = `james-deane-130-showdown`, publish, **no meta, no terms** —
  an incomplete artifact. Its detail URL resolves publicly (HTTP 200) but it
  carries no date/ticket data and does not appear in the `/eventos/` archive
  (the upcoming-events query requires `_event_date`).
- No non-Mondello event was modified (newest non-11705 `modified` timestamp
  is pre-attempt, 2026-09-10).
- Per the Stage C safety rules nothing was deleted and no manual edits were
  attempted beyond the single diagnostic PATCH probe (which was refused).

**Remediation requires an operator decision** (follow-up authorization):

1. Delete the partial post 11705, then re-run the import once the production
   auth/runtime environment is restored to the IVVCC Phase C state (runtime
   ≥ 1.2.0 with functional meta auth_callback); or
2. Keep 11705 and complete it (single meta PATCH + county) under the same
   restored environment, then create the remaining five via
   create-with-meta (or create + meta PATCH).

The export artifact `dist/mondello-only-export.json` remains valid and
verified (Winter Bash ticket URL `…/shows/1173670621` confirmed against the
live source).

### Phase 1/2 re-diagnostics — runtime gate FAIL (2026-09-12, UTC, after the BLOCKED attempt)

Re-run of production diagnostics (`scripts/mp-phase1-diagnostics.py`, read-only,
no writes) to establish the recovery path:

- **Authentication RESTORED since the blocked attempt.** `GET /users/me?context=edit`
  now returns **200** (`id=283039558`, `conexaobradmin`, administrator,
  `edit_posts`/`manage_options` true) and plain authenticated reads of 11705
  succeed. The `401 rest_not_logged_in` / `401 rest_cannot_edit` recorded above
  no longer reproduces — the credential was refreshed/re-authenticated between
  sessions. No write test has been performed (see gate below).
- **Post 11705 unchanged:** publish, correct title/slug, public URL HTTP 200,
  all `_event_*` meta empty (`_event_source=''`, `_event_date=''`).
- **Production census:** 44 published events (43 baseline + partial 11705);
  0 events carry a `_event_source` value (11705's is empty).
- **Phase 2 runtime gate: FAIL.** The edit-context meta schema on 11705 lists
  31 keys — all 26 pre-1.2.0 `_event_*` keys **but not `_event_export_uuid`**
  (registered only in runtime 1.2.0). Production is therefore running a
  **pre-1.2.0 conexao-event-runtime**. This also explains the original
  create-time `403 rest_cannot_update` on every `_event_*` key: the deployed
  registration lacks the v1.2.0 `auth_callback`, so protected-meta writes are
  refused even for an authenticated administrator.

**Result: PHASE 2 GATE = STOP.** Required operator action before any repair
or import:

1. Deploy the validated **`dist/conexao-event-runtime.zip` (v1.2.0,
   14.4 KB, built 2026-09-10)** to production (WordPress.com plugin upload /
   update). No other deployment is authorized.
2. Then re-run `python3 scripts/mp-phase1-diagnostics.py --probe` — the
   single authorized write — to confirm the auth_callback works
   (`PATCH 11705 meta._event_export_uuid`, value from the validated export).

No meta write, no repair, no remaining-event import has been attempted.
Post 11705 remains untouched.

### Edge cases reconfirmed

- FIA Euro RX: detail page unusable (external redirect / prose-only date);
  skipped, no date invented.
- Masters Superbike: past instances skipped as past; the undated instance is
  skipped as invalid date (detail fetch failure).
- James Deane 130 Showdown: importable with external ticketing (canonical URL
  kept when no explicit ticket link exists on the page).
- Winter Bash: primary Ticketsolve Book Now link used.
- No event times are invented (`time_source = none`).
- No address is invented ("Mondello Park" remains a venue label;
  `is_plausible()` rules unchanged).
- Past events remain filtered.
- Unknown categories are logged and never guessed.
