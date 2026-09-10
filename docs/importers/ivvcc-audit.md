# IVVCC Event Importer — Source + Architecture Audit (READ-ONLY)

> **Stage:** audit only. No importer code was written, no posts were created,
> no production data was modified, and no existing importer behavior was changed.
> All source evidence below was fetched with plain HTTP GET (browser User-Agent)
> from public pages. No images were downloaded. No authentication was bypassed.
> Audit date (UTC): 2026-09-10. Calendar state observed: September–December 2026
> upcoming events visible; Jan–Aug empty ("No Events").

- Primary source: `https://www.ivvcc.ie/`
- Calendar: `https://www.ivvcc.ie/upcoming-events-calendar/`
- Individual events: `https://www.ivvcc.ie/events/{slug}/` (12 upcoming slugs found)
- Sitemaps: `https://www.ivvcc.ie/sitemap_index.xml`,
  `https://www.ivvcc.ie/ajde_events-sitemap.xml` (541 event URLs, mostly archive),
  `robots.txt` (public, crawl-delay 10 — see §14)
- Privacy/copyright statement: `https://www.ivvcc.ie/privacy-policy/`

---

## 1. Source architecture (ivvcc.ie)

- **Platform:** WordPress (Yoast SEO sitemaps, `wp-content/uploads/…` assets) fronted
  by Cloudflare. Direct `wp-json` probing returns a Cloudflare block page —
  **no usable WP REST API**. Normal page GETs with a browser User-Agent succeed.
- **Calendar engine:** EventON (`MyEventON`, `data-cal_ver="2.6.16"`):
  `ajde_evcal_calendar` / `eventon_fullcal`, month grid (`evcal_month`,
  `data-cmonth="9" data-cyear="2026"`), AJAX month navigation
  (`/wp-admin/admin-ajax.php`), `data-filters_on="false"`,
  `data-hide_past="no"`, `data-hide_mult_occur="no"`.
- **Event identity:** each card carries a **stable numeric EventON ID**:
  `data-event_id="555046"` etc., plus `data-time="{start_unix}-{end_unix}"`
  (20 such cards on the calendar page at audit time).
- **Structured data (preferred over scraping):** every card embeds
  `evo_event_schema` microdata (`itemscope itemtype='http://schema.org/Event'`:
  `name`, `url`, `image`, `description`, `startDate` e.g. `2026-9-6T10:00`,
  `endDate` e.g. `2026-9-6T23:50`, `eventStatus` = `on-schedule`,
  `location` Place with `name` + PostalAddress `streetAddress`) **and** a
  duplicate `application/ld+json` Event block (21 on calendar, 2 per detail page:
  1 Yoast WebPage + 1 EventON Event). The JSON-LD **dates are malformed**
  (`"2026-9-19T19-19-00-00"` — dashes instead of colons, non-padded month) and
  its `image`/`description` are degenerate (image = page URL, description =
  title). **Never parse JSON-LD dates/naive fields; prefer microdata +
  `data-*` attributes + visible rows** (§13).
- **Per-event calendar export:** `admin-ajax.php?action=eventon_ics_download
  &event_id={id}&sunix={UTC}&eunix={UTC}&loca=&locn=…` plus `evo_ics_nCal`
  (ICS download) and `evo_ics_gCal` (Google Calendar template link). These
  `sunix`/`eunix` values are a reliable UTC cross-check for dates/times.
- **Archive vs upcoming:** the calendar shows *upcoming* events; past
  rallies/results/photo galleries live under a separate **Events Archive** menu
  and old `/events/…` sitemap entries. **Import scope = upcoming only** (§15).
- **Copyright gate:** privacy page states: *"All content on the ivvcc.ie
  website, unless otherwise stated is owned by the IVVCC and may not be
  reproduced without permission."* Import therefore requires permission and
  must carry source attribution (§10/§14).

## 2. Calendar architecture

- Single page (`/upcoming-events-calendar/`, ~174 KB) renders a full EventON
  month grid **plus all upcoming event cards inline** (Sep–Dec 2026 at audit
  time): `eventon_list_event evo_eventtop` cards with `data-event_id`,
  `data-time`, date block (`evcal_cblock`: `06 sep 10:00 am`), title
  (`evcal_desc2 evcal_event_title[itemprop=name]`), subtitle
  (`evcal_event_subtitle`), and expandable Time/Location/Organizer/Calendar
  rows (GoogleCal text link + hidden ICS/Google-Calendar anchors).
- Month navigation is AJAX (`evo_jmonth`, admin-ajax); empty months render
  "No Events". The calendar text dump for September 2026 (verbatim) lists 8
  events; October 3; November 0; December 1 (Mince Pie Run); Jan–Aug empty.
- 12 distinct upcoming detail URLs found on the calendar page (§1 links: 12):

| # | Calendar title | Detail URL |
|---|---|---|
| 1 | Cobh Classic Car Club — Dick O'Brien Memorial Run | `/events/cobh-classic-car-club-13/` |
| 2 | Baggatonia Festival (static display) | `/events/baggatonia-festival/` |
| 3 | Garden of Ireland VCC — Liam Kelly Memorial Run | `/events/garden-of-ireland-vintage-car-club-14/` |
| 4 | IVVCC Statham Rally (pre-1939) | `/events/ivvcc-statham-rally-3/` |
| 5 | IVVCC 11th Brass Brigade Run (to 1919) | `/events/ivvcc-11th-brass-brigade-run/` |
| 6 | Muskerry Vintage Club Annual Charity Run | `/events/muskerry-vintage-club-27/` |
| 7 | Blessington VCC Autumn Run (details later) | `/events/blessington-vintage-car-and-motorcycle-club-5/` |
| 8 | RIAC/IVVCC Cars and Breakfast | `/events/riac-ivvcc-cars-and-breakfast-4/` |
| 9 | IVVCC Autumn Run (to 2006) | `/events/ivvcc-autumn-run-3/` |
| 10 | Connacht V&V 15th Annual PreWar Run | `/events/connacht-veteran-vintage-motor-club-2/` |
| 11 | Kingdom VV&CC Autumn Run — Kenmare | `/events/kingdom-veteran-vintage-and-classic-car-club-6/` |
| 12 | Blessington VCC Mince Pie Run (Dec) | `/events/blessington-vintage-car-and-motorcycle-club-6/` |

- No cancelled/postponed/sold-out language anywhere on the calendar
  (counts: cancelled 0, postponed 0). No recurrence markers (`recurr` 0).
  Quality flags present: "Date to be advised" ×4, "details later" ×2.
- No category/type taxonomy on cards; no pagination beyond month nav; no
  result/archive/news content inside the calendar.

## 3. Individual-event architecture (`/events/{slug}/`, ~67–69 KB each)

- Same EventON card as the calendar (same `data-event_id`, same microdata +
  JSON-LD), plus full event body content below. 8 detail pages fetched live;
  representative dumps in §4.
- No featured/OG image (`og:image`/`twitter:image` absent; only sitemap
  `<image:loc>` on *old archive* events; current upload `<img>` hits are the
  global FIVA logo + blank-space placeholder). **Assume no per-event image.**
- No description paragraph beyond the card subtitle on any of the 8 pages
  checked — description = subtitle/title only (§8).
- No ticket/price/custom registration block; organizer row is the only
  contact/registration signal (§9).
- `eventStatus` microdata = `on-schedule` on all 8 pages; no
  cancelled/postponed vocabulary observed.
- `data-gmap_status="null"`, `data-location_status="true|false"`,
  `data-latlng` (only Statham has real coords `52.6497518,-7.2516362`),
  `data-location_name`, `data-location_address`, `data-location_type="lonlat"`.

## 4. Available fields — source-field inventory (8 real examples, verbatim)

`—` = field absent on that page. Emails below are Cloudflare-obfuscated
(`data-cfemail`) public organizer contacts, reproduced as displayed text only.

| # | Example (detail URL + event_id) | Title / subtitle | Date/Time row | Location row | Organizer row |
|---|---|---|---|---|---|
| 1 | Statham Rally `/events/ivvcc-statham-rally-3/` id `555046` — **one-day + full street address** | IVVCC Statham Rally / "Cars up to 1939" | `(Sunday) 10:00 am` (start only) | Venue `Pembroke Kilkenny Hotel` + street `Patrick Street, Kilkenny, 11 Patrick Street` (`location_status=true`, latlng `52.6497518,-7.2516362`) | `[email protected] and www.ivvcc.ie website` |
| 2 | Brass Brigade `/events/ivvcc-11th-brass-brigade-run/` id `555047` — **multi-day 19–20 Sep** | IVVCC 11th Brass Brigade Run / "cars up to 1919" | `19 (Saturday) 7:00 pm - 20 (Sunday) 5:00 pm` | `Park Hotel, Dungarvan, Co Waterford` (venue + town + county; NOT street-level) | `[email protected] and www.ivvcc.ie website` |
| 3 | RIAC Breakfast `/events/riac-ivvcc-cars-and-breakfast-4/` — **one-day + Eircode + external registration** | RIAC/IVVCC Cars and Breakfast / "Cars and Breakfast meet up €15. Breakfast at 11 am" | `(Sunday) 10:00 am - 12:30 pm` | `The Goat Bar and Grill, Clonskeagh, Dublin D14 PY56` (venue + Eircode) | `Riac Eventbrite or Ivvcc [email protected]` |
| 4 | Muskerry `/events/muskerry-vintage-club-27/` id `558190` — **affiliate-club, venue-only + instruction** | Muskerry Vintage Club / "Annual Charity Run" | `(Sunday) 10:00 am` (start only) | `College Car Park Registration from 10.00 am next door to Abbey Hotel Ballyvourney, Co Cork. Moving off at 3pm` | `Lar Cummins - Club Secretary, 087 2268752` |
| 5 | Garden of Ireland `/events/garden-of-ireland-vintage-car-club-14/` id `559452` — **multi-day, affiliate organizer, no address** | Garden of Ireland VCC / "Liam Kelly Memorial Garden of Ireland Run" | `4 (Friday) 10:00 am - 6 (Sunday) 6:00 pm` | — (no Location row at all) | `Eileen 087 8329235` |
| 6 | Cobh `/events/cobh-classic-car-club-13/` id `558743` — **month-long span, TBA date, no useful address** | Cobh Classic Car Club / "Dick O'Brien Memorial Run - scheduled for September 2026" | `1 (Tuesday) 10:00 am - 26 (Saturday) 5:00 pm` | `/Run to Kilmakilloge Harbour, with lunch at Helen's Bar via Healy Pass. Date to be advised.` | — (no Organizer row) |
| 7 | Blessington Autumn `/events/blessington-vintage-car-and-motorcycle-club-5/` — **venue-only, "details later"** | Blessington VCC / "(BVCMC) Autumn Run - details later" | `(Sunday) 10:00 am` (start only) | `Starting from Russborough House` (venue-only) | `[email protected]` |
| 8 | Baggatonia `/events/baggatonia-festival/` id `656421` — **placeholder 7:39 am time, email-only organizer** | Baggatonia Festival / "Seeking 20 pre 1970 cars for static display at Festival" | `(Friday) 7:39 am - 7:39 am` (**placeholder**) | `Pembroke Road/St Mary's Road, Dublin 4` | `Baggatonia Festival Organisers - [email protected]` |

Cross-example findings:

- **URL pattern:** `https://www.ivvcc.ie/events/{slug}/` (slug often
  `{club}-{n}/` versioned per year: `-3`, `-5`, `-6`, `-13`, `-14`, `-27`).
  `itemprop=url` on the card confirms the canonical detail URL.
- **Title:** post `<title>` minus suffix: `IVVCC 11th Brass Brigade Run`
  (`<title>IVVCC 11th Brass Brigade Run - IRISH VETERAN & VINTAGE CAR CLUB…`).
- **End time:** same-day (`10:00 am - 12:30 pm`) or multi-day
  (`19 … 7:00 pm - 20 … 5:00 pm`); many events start-only (§6/§19: 7:39, 23:50
  are EventON fallback end-times, not real data).
- **Locations:** full spectrum — street address (Ex.1), Eircode (Ex.3),
  venue+town+county (Ex.2), venue-only (Ex.7), route-description (Ex.6),
  absent (Ex.5). Microdata `streetAddress` mirrors the visible Location row
  (Ex.1 `Patrick Street, Kilkenny, 11 Patrick Street`).
- **Organizer ≠ IVVCC by default:** affiliate clubs (Muskerry, Garden of
  Ireland, Cobh, Blessington, Baggatonia), joint organizers (RIAC/IVVCC),
  personal contacts (Eileen + phone; Lar Cummins + phone). Preserve verbatim.
- **Description:** subtitle only on all 8 (no body paragraph) (§8).
- **Registration:** no URLs/buttons; "Riac Eventbrite" is plain text, no link
  (§9). **No category/type taxonomy** on any page. No recurrence anywhere.

## 5. Existing importer architecture (inspected, unchanged)

Pipeline (`Conexao_Event_Importer_Engine::run_all/run_source_unbuffered`):

1. `Conexao_Source_Base::fetch_html()` (browser UA, 30 s, throws
   `Conexao_Source_Fetch_Exception` on transport/non-200) → handler
   `fetch_events()` returns raw arrays (`title/url/start_date/start_time/
   end_date/end_time/location/description/image/source_id` + optional
   `venue/town/county/address/organizer/price/category`).
2. `Conexao_Event_Normalizer::normalize()` → dates to `Y-m-d`, times to `H:i`,
   location split (county/town/venue/address), category cleaned, validation
   errors (`Título/Data/URL ausente`, `Localização não identificada`).
3. `Conexao_Event_Date_Filter::evaluate()`: INVALID (bad/missing start date) /
   PAST (cutoff passed; multi-day uses end date/time; no time = end-of-day) /
   IMPORT. Past/invalid never touch WordPress.
4. `Conexao_Event_Deduplicator::find()` (§11) → `upsert_event()` (create /
   update / unchanged + `_event_last_checked` / preserve-manual-fields).
5. Post-run: `mark_missing_events()` (same-source URL set → `source_not_found`,
   never delete) + `Conexao_Event_Status::mark_expired_events()` (past →
   `expired`, no cron). Manual Cleanup deletes past events + orphan images.
6. Export v1.1 JSON (`uuid/post/meta/taxonomies/featured_image` incl. base64 ≤
   5 MB; localhost URLs skipped) → production import (UUID → source+source_id
   → URL → title+date; base64 → URL sideload fallback). Dry-run supported;
   buffered structured logs (`Conexao_Import_Log`), history, WP-CLI
   (`import/cleanup/status`), admin UI (Dashboard/Sources/History/Export/
   Import/Image Sync/Cleanup/Logs/Settings). No cron anywhere.

Handlers: `abstract-class-source.php` (contract + fetch/parse/resolve_url),
`sources/class-icalendar-source.php` (ICS incl. UTC→Europe/Dublin, per-source
county/category hints), `sources/class-laois-tourism-source.php` (generic
article/div card scrape), `sources/class-heritage-week-source.php` (paginated
cards + detail enrichment + image ranking — closest template for IVVCC),
`sources/class-eventbrite-source.php` + eventbrite client/normalizer/parser.
Helpers: address, location (Laois-town list — must extend for IVVCC, §6),
JSON-LD location (Event.location-only, no-guess), image handler (browser UA,
magic bytes, max 25 MB, dedupe by URL), date filter, cleanup, transfer admin.

## 6. Proposed mapping (existing fields only — no new meta)

Event CPT `event` (`/eventos/`), gated by `_event_status` (runtime
`pre_get_posts`: only `published`/legacy-no-status visible). Presentation:
`template-parts/event-card.php` (card: `_event_date/_event_time/
_event_location/_event_url/_event_banner/_event_registration`), `single.php`
+ `conexao_is_external_event_url()` (external `_event_url` → new tab).

| IVVCC source | Existing field | Notes |
|---|---|---|
| Card title (`evcal_event_title`) | post title | Verbatim, e.g. `IVVCC 11th Brass Brigade Run` |
| Subtitle (`evcal_event_subtitle`) | post content + excerpt (30 words) | Only rich text available (§8) |
| Start date (`data-time` start unix + Time row) | `_event_date` (Y-m-d) | Unix ts is authoritative; Time row is display |
| Start time | `_event_start_time` (H:i) + `_event_time` (`H:i — H:i` combo) | Normalizer `normalize_time()` handles `10:00 am` |
| End date | `_event_end_date` | Multi-day only; else empty |
| End time | `_event_end_time` | Same-day or multi-day end |
| Venue (`data-location_name` / Place `name`) | `_event_venue` + `_event_location` (venue-first label) | `_event_location` = venue → town → county fallback |
| Street/Eircode (`data-location_address` / PostalAddress) | `_event_address` **only if plausible** (§7) | `is_plausible()` gate; else venue-only, address empty |
| Map link | `_event_map_url` (derived, never overwrite non-empty) | `map_query(address → venue+location → location)` |
| Detail URL | `_event_url` + `_event_source_url` (both = detail URL, §9) | Card banner links to it; external → new tab |
| Organizer row | `_event_organizer` | Verbatim incl. affiliates/phones (§3 rule) |
| Registration text | `_event_registration` (text only) | No URL observed; leave empty rather than invent (§10) |
| Price | `_event_price` | `€15` from Ex.3 subtitle is the only price seen |
| Image | `_event_banner` + sideload → `_event_banner_attachment_id` + thumbnail | Almost always empty (§11) |
| Source | `_event_source` = `ivvcc`, `_event_source_id` = EventON id (§12) | |
| County / town | `conexao_county` / `conexao_town` (ensure_* creates terms) | Location helper needs national town/county data (§7) |
| Category | `conexao_category` default only (§16) | No source categories; never organizer names |
| Recurrence meta | **leave empty (one-time)** (§13) | No recurring schedule expressed |
| Status | `_event_status` = `published` on upsert; expiry → `expired` | Runtime gate hides the rest |

## 7. Address handling ("full address" rule — do NOT redefine)

- **Existing rule** (`Conexao_Event_Address::is_plausible()` + normalizer):
  store `_event_address` only for a plausible physical address — multi-part
  (`,`-separated) **or** street number **or** Eircode **or** `Co.` marker;
  single-part venue/town names are never addresses (no-guess rule). Stored
  address wins the map query; `_event_map_url` derived deterministically
  (`?api=1&query=`), never overwriting manual values; updates preserve a
  manually corrected address when the source supplies none
  (`resolve_stored()`).
- **IVVCC mapping:**
  - Ex.1 `Pembroke Kilkenny Hotel` + `Patrick Street, Kilkenny,
    11 Patrick Street` → venue + **address stored** (multi-part + number).
  - Ex.3 `The Goat Bar and Grill, Clonskeagh, Dublin D14 PY56` → venue +
    **address stored** (multi-part + Eircode).
  - Ex.8 `Pembroke Road/St Mary's Road, Dublin 4` → single-part, no number /
    Eircode / `Co.` → venue-only, address empty (map falls back to
    venue+location query).
  - Ex.2 `Park Hotel, Dungarvan, Co Waterford` → multi-part + `Co` marker, so
    it **passes** `is_plausible()` and would be stored as `_event_address`.
    Per the audit brief this is venue+town+county, **not** street-level: the
    implementation must decide explicitly whether to keep the pass (existing
    rule allows it) or tighten the rule — **do not silently pretend it is a
    street address**. Recommended: store it (existing rule wins) but surface
    only as venue + map search, never as a verified street address; any
    rule change belongs to the implementation stage, not this audit.
  - Ex.4 route/instruction text, Ex.6 route description, Ex.5/Ex.7 venue-only
    or absent → venue-only or county fallback, address empty.
- **Location helper gap:** `Conexao_Event_Location` knows Laois towns/counties
  only (`\bco\.?\s*laois\b`, Laois town list). IVVCC spans Kilkenny, Waterford,
  Dublin, Cork, Sligo, Kerry… The implementation needs a national
  county/town derivation (or explicit per-event county mapping) — otherwise
  county/town terms stay empty and the normalizer flags
  `Localização não identificada`.

## 8. Website URL handling

- **Rule: canonical source URL = the individual IVVCC event URL**
  (`https://www.ivvcc.ie/events/{slug}/`), confirmed by card
  `itemprop=url` — never the calendar page URL. Both `_event_url` and
  `_event_source_url` store it (existing engine writes both identically);
  theme cards link the banner to `_event_url` and open externals in a new tab.
- Footer links / `www.ivvcc.ie` mentions inside organizer text are not event
  URLs and must not be stored.

## 9. Registration handling

- No registration URLs, buttons, ticket blocks, prices (beyond `€15` in Ex.3
  subtitle), deadlines, or external booking links on any of the 8 detail pages.
  "Riac Eventbrite" (Ex.3) is unlinked plain text; "Registration from
  10.00 am" (Ex.4) is a location instruction, not a booking channel.
- **Map only into existing `_event_registration` (free text) when the source
  provides explicit registration text; there is no registration URL field in
  the model — do not invent one.** Leave empty when absent (the common case).

## 10. Image handling

- Origin: IVVCC WordPress media (`/wp-content/uploads/…`) + EventON cards; no
  hotlink protection observed beyond Cloudflare (normal GETs succeed).
- Current upcoming events have **no per-event featured image**: no `og:image`,
  no card `<img>`, JSON-LD `image` = page URL (degenerate), only global
  chrome (FIVA logo, blank-space) in upload paths. Old archive sitemap entries
  do carry `<image:loc>` (e.g. `20150920_120412.jpg`, `FESTIVAL-OF-MOTORS…`).
- Existing `Conexao_Event_Image_Handler` supports remote images (browser UA,
  magic-byte validation JPEG/PNG/WebP/GIF, 25 MB cap, dedupe-by-URL, export
  embeds ≤ 5 MB base64, localhost skipped, failure keeps external URL
  fallback) — reusable as-is, but expect empty `banner` for IVVCC.
- **Strategy: import imageless; never hotlink; sideload only if a real
  per-event image appears; no attribution/licensing metadata exists on event
  pages (none observed).** No images downloaded in this audit.

## 11. Deduplication

Existing order (`Conexao_Event_Deduplicator`): (1) `_event_source` +
`_event_source_id` → (2) `_event_url`/`_event_source_url` →
(3) content fallback (exact `sanitize_title` + `_event_date` + start-time /
venue / organizer comparisons).

- **Recommended stable identity: `_event_source = ivvcc` +
  `_event_source_id` = EventON numeric `data-event_id`** (e.g. `555047`,
  `555046`, `656421`): present on calendar + detail page, stable across
  months, independent of yearly versioned slugs (`…-club-13` vs `-14`) and
  recurring club names.
- URL match is a safe secondary key (detail URL is canonical, §8) but slugs
  version yearly, so it must not be primary. Never title-only (club names
  repeat yearly: `ivvcc-first-monday`, `donegal-…-club-{2…9}`, `muskerry-…-27`
  in the 541-URL sitemap; content fallback stays last-resort only).
- Cross-source collisions (same rally on Laois Tourism/Eventbrite) are handled
  by existing URL + content fallbacks without merging source identities.

## 12. Update behavior (existing — must be preserved)

- Creates (`wp_insert_post` publish + full meta), updates (`wp_update_post`
  title/content/excerpt + full meta), unchanged fast-path (only
  `_event_last_checked` + map backfill + missing-attachment sideload),
  per-event try/catch isolation, dry-run mode.
- Missing-from-source → `_event_status = source_not_found` (hidden, never
  deleted; reappearing events return to `published`). Past-end → `expired`
  at end of every import run. Manual Cleanup (only deleter) removes past
  events + exclusively-owned images (200/run cap).
- **Manual-edit protection (existing, verified in code):** `_event_address`
  preserved when source supplies none (`resolve_stored`); `_event_map_url`
  never overwritten when non-empty (refreshed only when it exactly equals the
  previously derived URL); image re-download skipped when the attachment URL
  matches. **IVVCC rule: same behavior — never overwrite manually curated
  fields without an explicit per-field rule; content updates overwrite title/
  content/excerpt/meta by design, so curators must know imported fields are
  source-owned.**

## 13. Fragility audit — prefer structured data

| Signal | Availability | Verdict |
|---|---|---|
| EventON microdata (`evo_event_schema`, `itemprop=name/url/startDate/endDate/location/eventStatus`) | All cards | **Primary** — semantic, stable |
| `data-*` attributes (`data-event_id`, `data-time` unix, `data-location_name/address/latlng`, `data-cmonth/cyear`) | All cards | **Primary** — machine-readable |
| `eventon_ics_download` links (`event_id/sunix/eunix/loca/locn`) | All cards | **Cross-check** for dates/times |
| Visible Time/Location/Organizer rows | All cards | **Authoritative display** (organizer only exists here) |
| EventON JSON-LD `Event` | Present but **malformed dates** (`2026-9-19T19-19-00-00`), degenerate image/description | **Do not parse dates from it** |
| Yoast WebPage JSON-LD / sitemaps | Navigation + 541-URL archive discovery | Sitemap = archive enumeration only |
| WP REST (`wp-json`) | Cloudflare-blocked | **Unavailable** |
| RSS/ICS feed, `event_type` taxonomy, `event_location/organizer` archives | None found (type sitemap stale 2016) | **Unavailable** |
| CSS classes (`evcal_desc2`, `evcal_event_subtitle`, `evcal_cblock`) | Stable in 2.6.16 but theme-dependent | Fallback only |

Selector risk: EventON 2.6.16 markup is the single point of brittleness
(upgrade restyles cards). Mitigation: parse microdata + `data-*` first, rows
second, CSS classes last; fail loudly (`Conexao_Source_Fetch_Exception`) on
structural change; IVVCC's Cloudflare requires the existing browser UA.

## 14. Robots / terms / source access

- `robots.txt`: public, no calendar/events disallow (only `wc-logs`,
  WooCommerce transient/cart paths, `/wp-admin/`); `Crawl-delay: 10`; Yoast
  `Allow: /wp-admin/admin-ajax.php` + full sitemap index. A normal paced HTTP
  importer (≤1 req/10 s, browser UA, public pages only) is consistent with
  these signals.
- **Copyright requires permission:** *"All content on the ivvcc.ie website,
  unless otherwise stated is owned by the IVVCC and may not be reproduced
  without permission."* (`/privacy-policy/`). Obtain IVVCC permission before
  importing; attribute every event to its detail URL (§8); reproduce facts
  (dates/venue/organizer) + short subtitle, not full articles.
- No authentication, paywall, or bot-wall beyond Cloudflare CDN encountered;
  do not circumvent; do not collect member-only content, payment data, or
  non-public personal data. Organizer emails/phones are public event contacts;
  store only the organizer text field, never build a contact database.

## 15. Import scope

**Upcoming events only** (calendar cards with end cutoff ≥ now, evaluated by
the existing date filter). Exclude: Events Archive (results/galleries/news),
membership/regalia/ARM/journal content, test events (`/events/test*/`),
`ajde_events` sitemap history (541 URLs — enumeration aid, not import list).
December Mince Pie Run proves the calendar already spans year-end; no
off-season special-casing needed. Cobh-style month-long TBA spans need the
§19 rule, not a scope exception.

## 16. Category strategy

Source has **no categories/types** (no `event_type` usage, no card taxonomy).
Do not create categories from organizer/club names (would spawn dozens:
Muskerry, Cobh, Blessington…). Use the existing convention only: the source
config's default `category` hint (cf. Heritage Week `Heritage`, Laois Tourism
none) — e.g. a single curated default if editorial approves — otherwise leave
`conexao_category` unassigned. Never invent taxonomy terms per event.

## 17. Recurrence

No genuine recurrence: no `recurr` markers, no `RRULE`/repeat UI, no weekly
schedule text; similar titles across years are **separate yearly editions**
with distinct EventON IDs and versioned slugs (`…-club-5` vs `-6`,
`muskerry-…-27`, `ivvcc-first-monday{,-2,-3,-4}`). **Leave all
`_event_recurrence*` meta empty (one-time);** the runtime weekly model
(`_event_recurrence=weekly` + days + start/end) is never triggered by this
source. Revisit only if a detail page explicitly states a weekly schedule.

## 18. Multi-day

Native support, no occurrence posts: Brass Brigade 19–20 Sep → `_event_date`
2026-09-19 + `_event_end_date` 2026-09-20 (+ start/end times); Garden of
Ireland 4–6 Sep likewise. Theme renders `12-15`-style ranges + ISO range;
expiry/cleanup use the end timestamp; date filter keeps running events
importable mid-span. Single-day events leave `_event_end_date` empty
(start-only Time rows are normal, not errors).

## 19. Data-quality risks → treatment (existing pipeline, no new rules)

| Risk (observed) | Treatment |
|---|---|
| Placeholder `7:39 am` (Baggatonia; EventON default, no real time) | Import date, **drop placeholder time** (empty = all-day), log warning |
| Fallback end `23:50` (Statham, Muskerry, Blessington single-days) | Treat as start-only; never display 23:50 |
| Missing location (Garden of Ireland: no Location row) | County/town derivation or hint fallback; else existing `Localização não identificada` skip |
| Venue-only (`Starting from Russborough House`) | Venue stored, `_event_address` empty, venue map query |
| Route/instruction locations (Muskerry, Cobh harbour route) | Verbatim venue text; never parse into addresses |
| "Date to be advised" + 1–26 Sep span (Cobh) | **Skip until date firms** (26-day span misleads); watchlist each run |
| "details later" (Blessington Autumn) | Import with subtitle; upsert fills details later |
| Missing organizer (Cobh) / personal phones (Eileen, Lar Cummins) | Verbatim or empty; never invent `IVVCC`; no contact harvesting |
| Non-`on-schedule` status (none observed; field exists) | Future stage: map to skip/`rejected` + log |
| Conflicting dates (unix vs row vs ICS sunix) | `data-time` unix wins; mismatch → warning |
| Malformed JSON-LD dates (`T19-19-00-00`) | Never parse JSON-LD dates (§13) |
| Duplicate club names across years | EventON-id dedupe (§11), never title-only |

## 20. Recommended implementation (future stage — NOT built)

1. New `Conexao_Source_Ivvcc` handler (model: `class-heritage-week-source.php` —
   calendar fetch → card extraction → detail enrichment, per-event try/catch),
   source id `ivvcc`, honoring `Crawl-delay: 10`.
2. Parse priority: microdata + `data-*` → ICS-link cross-check → visible rows
   (organizer/subtitle) → detail page. Skip TBA spans; drop placeholder times.
3. Unchanged pipeline (normalizer → date filter → dedupe → upsert →
   missing/expiry → export). Extend location derivation beyond Laois;
   default-only category; recurrence stays empty.
4. `--dry-run` + address audit first; IVVCC permission first (§14).

## Appendix A. Description policy (§8 detail)

All 8 detail pages: subtitle-only content (no body paragraph). Prefer detail
pages at build time in case copy is added later. Always strip chrome: nav,
archive menu, FIVA/affiliate blocks, footer, cookie/GDPR notices, search,
`admin-ajax` artifacts, email-protection markup. Never store forms, non-public
personal data, or footer content.

## Appendix B. Exact files inspected (repo)

`docs/architecture.md`, `docs/content-model.md`, `docs/routing.md`,
`docs/plugins/README.md`, `docs/plugins/conexao-event-importer.md`,
`docs/plugins/conexao-event-runtime.md`, `docs/plugins/conexao-data-model.md`,
importer `class-event-importer.php` (engine/upsert/meta/missing-marking),
`class-event-normalizer.php`, `class-event-deduplicator.php`,
`class-event-address.php`, `class-event-location.php`,
`class-event-jsonld-location.php`, `class-event-date-filter.php`,
`class-event-cleanup.php`, `class-event-image-handler.php`,
`class-event-export.php`, `class-event-import.php`, `class-event-sources.php`,
`sources/abstract-class-source.php`, `sources/class-icalendar-source.php`,
`sources/class-laois-tourism-source.php`,
`sources/class-heritage-week-source.php`,
runtime `conexao-event-runtime.php` + `includes/class-event-status.php`,
theme `single.php`, `template-parts/event-card.php/hero-events.php/
event-preview.php`, `functions.php` (external-URL + map helpers), `inc/seo.php`.

Live URLs fetched (GET only): `/`, `/upcoming-events-calendar/`, 8×
`/events/{slug}/` (§4), `/robots.txt`, `/sitemap_index.xml`,
`/ajde_events-sitemap.xml`, `/privacy-policy/` (`/about-us/` → 404).

## Appendix C. No files modified

Read-only audit: zero code/content/data changes. Sole write is this document
(`docs/importers/ivvcc-audit.md`). No posts, no production data, no behavior
changes, no image downloads.

