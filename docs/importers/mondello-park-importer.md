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
- The source config carries `county => 'Kildare'` as a source-scoped hint so
  that events whose detail page only surfaces the venue name ("Mondello Park")
  still receive a valid county term without inventing an address.
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
  links, the first encountered is used as the primary; secondary show IDs are
  recorded in `raw['_ticket_show_ids']` custom meta only — never in a URL
  field.
- **External ticket providers:** if an external (non-Ticketsolve) ticket link
  is explicitly present, it is captured as the ticket URL.
- **Winter Bash:** Stage A identified two Ticketsolve show IDs. The primary
  "Book Now" link encountered in the detail page is used. Both IDs are logged
  for reference. The canonical Mondello Park event page remains the event
  identity; the ticket URL is not identity.
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
