# IVVCC Event Importer

> **Source:** `https://www.ivvcc.ie/` — Irish Veteran & Vintage Car Club.
> **Stage B implementation** of [ivvcc-audit.md](ivvcc-audit.md).
> **Status:** implemented + dry-run/live-parse validated. The source ships
> **inactive** — activate it in *Event Import → Event Sources* for local
> import/transfer only after operator confirmation as an internal deployment
> control. This is separate from any source-owner permission requirement.
>
> **Stage C source-use determination (2026-09-10):** `IVVCC STAGE C
> SOURCE-USE PASSED` for the intended A + limited B use case (link to public
> IVVCC event pages + display of factual event metadata with source
> attribution). See [ivvcc-stage-c-source-use-report.md](ivvcc-stage-c-source-use-report.md).
>
> **Stage D production import:** Requires explicit operator confirmation before
> any production write. Production activation is an internal deployment control
> only. See [ivvcc-stage-d-report.md](ivvcc-stage-d-report.md) when available.

## Source

- **Platform:** WordPress + Cloudflare + EventON 2.6.16 (`data-cal_ver`).
- **REST API:** blocked by Cloudflare — never used, never bypassed.
- **Access:** plain browser-UA HTTP GET (inherited from
  `Conexao_Source_Base::get_http_user_agent()`), public pages only.

## Files

| File | Role |
|---|---|
| `wp-content/plugins/conexao-event-importer/includes/sources/class-ivvcc-source.php` | `Conexao_Source_Ivvcc` handler |
| `wp-content/plugins/conexao-event-importer/tests/test-ivvcc-importer.php` | Parser/location/dedup tests |
| `wp-content/plugins/conexao-event-importer/tests/live-ivvcc-parse.php` | Read-only live parse validation |
| `wp-content/plugins/conexao-event-importer/tests/dry-run-ivvcc.php` | Dry-run validation (temp-activates the local source config) |
| `includes/class-event-location.php` | Generalized nationwide county/town derivation (see below) |
| `includes/class-event-importer.php` | `ivvcc` handler routing case |
| `includes/class-event-sources.php` | Default (inactive) `ivvcc` source entry |

## Calendar discovery

Primary: `GET /upcoming-events-calendar/` (~174 KB). Every upcoming EventON
card (`eventon_list_event`) is extracted server-side from the rendered HTML —
no JS execution, no month AJAX. Duplicate cards (some events render twice)
are collapsed by EventON numeric ID in memory.

Detail enrichment: each unique event's canonical page
(`https://www.ivvcc.ie/events/{slug}/`) is fetched politely
(`Crawl-delay: 10` seconds between requests, `MAX_DETAIL_PAGES = 40` cap) and
parsed for subtitle/location/organizer/price/image. A failed detail fetch
keeps the calendar record.

## Source identity

- `_event_source = ivvcc`
- `_event_source_id = data-event_id` (EventON numeric ID, e.g. `555046`)

Primary dedup key. Yearly versioned slugs (`-3`, `-5`, `-27`) are stored in
`_event_url`/`_event_source_url` but are **never** the identity. Same title,
different EventON ID → different events (separate yearly editions).

## Dates — `data-time` is authoritative

`data-time="{start_unix}-{end_unix}"` maps into `_event_date`,
`_event_start_time`, `_event_end_date`, `_event_end_time`.

**Timezone finding (validated live):** EventON on ivvcc.ie stores wall-clock
times *encoded as UTC* — the rendered cards and the ICS export both display
the timestamp's UTC wall time verbatim (Cobh: unix → `T100000Z` renders as
"10:00 am"; Muskerry "Registration from 10.00 am" ↔ 10:00 UTC wall). The
parser therefore reads the **UTC wall time** as the source's usable local
time. Converting with a `Europe/Dublin` offset would shift every time by
+1 h away from every human-readable rendering on the source site; the audit's
`23:50` fallback observation is only visible in these UTC walls, which
confirms the encoding. Dates are unaffected for all realistic event hours.

Multi-day events map natively (e.g. Brass Brigade 19→20 Sep, Garden of
Ireland 4→6 Sep); no occurrence posts, no recurrence inference
(`_event_recurrence*` stay empty).

Secondary cross-check (unused by default): EventON ICS endpoint
`admin-ajax.php?action=eventon_ics_download&event_id=&sunix=&eunix=`.

## JSON-LD exclusion

EventON JSON-LD dates are malformed (`"2026-9-19T19-19-00-00"`). They are
**never parsed** — not for dates, times, images (degenerate: page URL) or
description (degenerate: title). Tests prove `data-time` wins.

## Time quality rules

| Situation | Behavior |
|---|---|
| Placeholder start (`07:39`, compared in Dublin and UTC wall time) | Dropped; event imported date-only (all-day semantics) + warning |
| Fallback end `23:50` (UTC wall) | End time dropped, never invented; end date kept when the span reaches another day, dropped for same-day (start-only) |
| Identical wall times exactly 24 h apart (e.g. Kingdom `01:00-01:00`) | No usable time info: date span kept, both times dropped (all-day semantics) + warning |
| Start only (equal unix stamps) | Start stored, end empty |
| `TBA` / "Date to be advised" (e.g. Cobh record) | Event skipped (`SKIP: date not firm`); never inferred from calendar placement |
| "details later" | Imported with subtitle; not a skip reason |

## Location / address / map

The reusable `Conexao_Event_Location` was generalized from Laois-only to a
nationwide county + town gazetteer (32 counties; IVVCC-relevant towns incl.
Dungarvan, Blessington, Kenmare, Sligo, Cobh, Ballyvourney, Clonskeagh…).
Legacy Laois behavior is preserved (Laois town list wins first).

- Venue text is preserved verbatim; venue-only strings stay in the venue
  field (standalone place names like "Dublin" remain venue labels).
- `_event_address` is stored only when the existing plausibility gate passes
  (number, Eircode, or multi-part with county marker). Route/instruction
  text is never parsed into an address.
- Map URLs use the existing deterministic helper
  (`Conexao_Event_Address::map_query()` + `map_url()`); manually curated
  `_event_map_url` values are never overwritten.

## Organizer / registration / price / categories

- **Organizer:** stored verbatim (`_event_organizer`) — IVVCC, affiliated
  clubs, joint organizers, contact names. Never forced to `IVVCC`; emails
  (Cloudflare-obfuscated) are stripped, no contact harvesting.
- **Registration:** no registration URL field exists in the importer writer.
  Clear registration text (e.g. `Riac Eventbrite…`) is preserved appended to
  the description as `Registration: …`. No URL invented; "Registration from
  10am" is not treated as online registration.
- **Price:** only explicit `€NN` amounts in the subtitle map to `_event_price`
  (e.g. `€15`). Membership text/numbers are never parsed as prices.
- **Categories:** none — the source has no reliable categories and no
  default category is configured for this source.

## Images

IVVCC upcoming events currently have no usable per-event images. The parser
therefore yields no image: the calendar/detail page URL is never treated as
an image, FIVA/logo/header/footer chrome is rejected
(`Conexao_Source_Ivvcc::is_chrome_image()`), and only a real raster image
(`.jpg/.png/.gif/.webp`, non-chrome, non-page-URL) found inside an event card
would flow through the existing `Conexao_Event_Image_Handler`.

## Content model — approved production boundary

The Stage C source-use report approved the **A + limited B** model:

- **A (LINK/REFERENCE):** Canonical IVVCC event URL stored as `_event_url`/
  `_event_source_url`; displayed as "Ver evento no IVVCC" link.
- **Limited B (FACTUAL METADATA AGGREGATION):** Title, start date, start time,
  end date, end time, published location, published organizer, and explicitly
  published price when represented by the existing importer.

### Explicitly excluded (per IVVCC privacy policy)

The IVVCC privacy policy states:

> "All content on the ivvcc.ie website, unless otherwise stated is owned by
> the IVVCC and may not be reproduced without permission."

The importer respects this by excluding:

- event photographs;
- logos (FIVA, header, footer chrome rejected by `is_chrome_image()`);
- PDFs;
- registration forms (no registration URL field exists; registration text
  preserved as factual note only when explicitly present);
- full descriptions (none exist in source — only subtitles present);
- other page content (nav, footer, cookie UI, EventON controls, email-
  protection markup all stripped).

### Subtitle/description — conservative production configuration

**Status: OMITTED under conservative mode.**

The Stage C source-use report identified the subtitle as a gray area. The
subtitle is the event's own short descriptive text from EventON (typically a
phrase like "Cars up to 1919" or "Brass Brigade Run - cars up to 1919"),
not a full article. Under the conservative production configuration, the
subtitle is **not stored** as the event description. Production events will
have:

- title;
- date/time;
- location;
- organizer;
- price when explicitly available;
- source link;
- source attribution.

To include the subtitle, the operator must explicitly accept the documented
 Stage C interpretation that a short factual subtitle displayed as a label
is acceptable. This decision must be recorded in the Stage D report. Do not
silently include the subtitle.

## Update / missing / expiry

Handled entirely by the shared engine:

- Upsert updates only importer-owned fields; manually curated address, map
  URL, image and unrelated meta are preserved (`Conexao_Event_Address::resolve_stored()`
  keeps the stored address when the source supplies none).
- Disappearing events → `mark_missing_events()` → `_event_status = source_not_found`
  (never deleted; reappearing events return to published).
- Past events → existing `mark_expired_events()` pass at end of run; date
  filter already blocks creating/updating past events.

## Dry-run

```bash
# via WP-CLI (activate the source first, or use the validation script):
wp conexao-events import --source=ivvcc --dry-run

docker compose exec wordpress php \
  /var/www/html/wp-content/plugins/conexao-event-importer/tests/dry-run-ivvcc.php
```

Fetches, parses, normalizes, date-filters and deduplicates, reporting
CREATE/UPDATE/UNCHANGED/SKIP per event — no posts, taxonomies, images or
production data are touched.

## Crawl policy

- One calendar GET + one GET per unique upcoming event page.
- `sleep(10)` between detail requests (`Crawl-delay: 10`).
- Browser User-Agent only; no Cloudflare bypass, cookies, credentials or
  search-engine spoofing.
- Optional `crawl_delay` / `max_detail_pages` source-config overrides exist
  for controlled testing.

## Known limitations

1. **Operator confirmation required:** Production activation requires operator
   confirmation as an internal deployment control. This is separate from any
   source-owner permission requirement. The Stage C source-use report
   concluded that the intended A + limited B use case (linking + factual
   metadata) is supported by available evidence, but the organization must
   make its own decision to proceed.
2. Month-grid AJAX navigation is not followed — only the inline upcoming
   cards on the calendar page (matches "upcoming only" scope).
3. Organizer contact details are partially Cloudflare-email-obfuscated;
   obfuscated addresses are stripped rather than decoded (privacy-first).
4. No registration URL, category, or (currently) image data exists at the
   source; corresponding fields stay empty rather than being invented.
5. `07:39` placeholders are matched as a fixed list; a new EventON fallback
   value would need to be appended to `PLACEHOLDER_TIMES`.
6. Detail-page structure is EventON-version dependent (audit §13); a major
   EventON upgrade may require selector revalidation.
