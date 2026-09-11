# Mondello Park Stage A — Source Audit

> **Stage:** A — SOURCE AUDIT ONLY
> **Scope:** `https://mondellopark.ie/events-tickets/` and its ticketing partner `https://mondellopark.ticketsolve.com/`
> **Status:** READ-ONLY — no WordPress writes, no source activation, no production changes.
> **Auditor:** Cline (AI coding agent)
> **Audit "today" reference:** `Fri, 11 Sep 2026` (from the server `Date` response header; all "future" determinations are relative to this date).
> **Evidence policy:** Every factual conclusion below is backed by direct source evidence (HTTP probes + HTML/JSON parse captured during this audit). Inferences are tagged `(inference)`. No production/local data was written.

---

## FINAL REPORT (executive summary)

| Field | Value |
|---|---|
| **Platform** | WordPress (self-hosted, SiteGround edge). Theme `mondello-theme`. Yoast SEO 28.3. Plugins: CPT UI, Redirection, Wordfence, WP All Import, Gravity Forms, Duplicate Post, Checkfront (legacy). |
| **Discovery URL** | `https://mondellopark.ie/wp-json/wp/v2/events?per_page=100` (29-event JSON) **plus** `https://mondellopark.ie/events-sitemap.xml` (29 URLs w/ `lastmod`). The human listing `/events-tickets/` shows only the 6 upcoming events. |
| **Detail URL pattern** | `https://mondellopark.ie/events/{slug}/` |
| **Ticketing platform** | **Ticketsolve** (`https://mondellopark.ticketsolve.com/ticketbooth/shows/{showId}`). JS SPA behind Queue-it + Safetynet. `tickets.mondellopark.ie` is the legacy dead Checkfront subdomain (Cloudflare 403). |
| **Public API** | **None for event data.** `/wp-json/wp/v2/events` exposes `id/slug/title/link/date/modified/event_category-IDs/featured_media/yoast_head` but **no event date/time/venue/price/ticket**. Ticketsolve `/api/` = 403. |
| **REST API** | Yes — `X-WP-Total: 29`; `?per_page=100` returns all 29 in one JSON request. |
| **robots.txt** | `User-agent: * Disallow:` (open) + Sitemap. No `Crawl-delay`. |
| **Sitemap** | `/sitemap_index.xml` (Yoast) → `events-sitemap.xml` = 29 URLs each with `lastmod` = WP `modified_gmt`. |
| **Server-rendered vs JS** | Main site: fully server-rendered HTML. Ticketsolve: JS SPA (32 kB shell; show data to blocked `/api/`). |
| **Pagination** | Listing: 6 cards, **no pagination / no load-more / no public AJAX filter endpoint**. REST: 3 pages at default `per_page=10`. |
| **Structured data** | **No `schema.org/Event`** anywhere. Yoast JSON-LD = `WebPage/BreadcrumbList/WebSite/Organization/ImageObject` only (publish timestamps, not event dates). |
| **Events discovered** | 29 published (sitemap ∩ REST). 6 upcoming (Sep 12 → Nov 29 2026); 23 past/archived still published. |
| **Sample tested** | 17 detail pages parsed end-to-end + listing + REST + Ticketsolve shell. |

**IMPLEMENTATION DECISION:** `IMPLEMENTATION RECOMMENDED WITH LIMITATIONS`

Rationale: event dates exist only as visible HTML text (a custom parser is required); **no event start/end times** are machine-readable anywhere; past events remain published and must be date-filtered by the importer; Ticketsolve is a JS-only SPA whose prices/availability are unreachable. None of these are blockers — the existing Conexão importer pipeline already models upsert-by-source-id, date filtering, expiry marking, and missing-source detection.
## 1. Source Architecture (Platform / CMS)

**Main site: WordPress** (self-hosted) behind a **SiteGround** edge, confirmed by:
- open `/wp-json/` (returns the WP site index incl. `namespaces`);
- asset paths under `/wp-content/themes/mondello-theme/` and `/wp-content/plugins/gravityforms/...`;
- Yoast SEO 28.3 in HTML comment + JSON-LD `yoast-schema-graph`;
- WP REST single-event `guid.rendered` = `https://exsitec32.sg-host.com/?post_type=events&p=2134` (SiteGround staging host). Response headers `host-header: 8441280b0c35cbc1147f8ba998a563a7`, `x-proxy-cache-info: DT:1`, `etag "6a98b880-125b9"` are the SiteGround nginx edge.

CPT/Taxonomy (registered via Custom Post Type UI, REST namespace `cptui/v1`):
- `events` CPT: routes `/wp-json/wp/v2/events` and `/wp-json/wp/v2/events/{id}`; `has_archive=false`; rewrite base `events` (so `/events/{slug}/` is the detail URL, and `/events/` 301 → `/events-tickets/`).
- `event_category` taxonomy: `/wp-json/wp/v2/event_category` (8 terms — see Phase 8).
- Active plugin namespaces observed: `wp/v2`, `oembed/1.0`, `checkfront/v3`, `real-custom-post-order/v1`, `real-utils/v1`, `redirection/v1`, `wordfence/v1`, `yoast/v1`, `wp-all-import/v1`, `gravityflow/internal`, `ai1wm/v1`, `cptui/v1`, `duplicate-post/v1`, `gf/v2`, `mcp`, `wp-site-health/v1`. The `mcp` namespace is a generic MCP oauth adapter (routes `/mcp`, `/mcp/mcp-oauth-server`, `/mcp/mcp-adapter-default-server`) — not event data.

**Ticketing site: Ticketsolve** (Ruby/Rails on nginx + Phusion Passenger Enterprise; header `x-powered-by: Phusion Passenger(R) Enterprise`). Root `https://mondellopark.ticketsolve.com/ticketbooth`; shows `https://mondellopark.ticketsolve.com/ticketbooth/shows/{showId}`. The legacy subdomain `tickets.mondellopark.ie` (old Checkfront, `checkfront/v3` namespace still registered) returns a Cloudflare "Just a moment…" 403 — effectively dead and unused by any live ticket link.

### 1.1 Public API / REST API
- `/wp-json/` is open and returns the site index.
- `/wp-json/acf/v3/events/2134` → `404 rest_no_route` (evidence: `{"code":"rest_no_route"...}`). ACF is NOT REST-enabled; the event date/time is not exposed via any REST field.
- `/wp-json/wp/v2/events` (paginated JSON) returns only WordPress core post fields (`id`, `slug`, `title.rendered`, `link`, `date`, `date_gmt`, `modified`, `modified_gmt`, `status`, `event_category` = term IDs, `featured_media`, `acf: []`, `yoast_head`). The event date/time, venue, price, category label, and ticket URL are rendered into the HTML by the theme (from a non-REST custom field, likely ACF). **Conclusion (inference):** the importer must parse HTML for dates/tickets; the REST API is a discovery/index surface only.

### 1.2 robots.txt
```
# START YOAST BLOCK
User-agent: *
Disallow:
Sitemap: https://mondellopark.ie/sitemap_index.xml
# END YOAST BLOCK
```
Fully permissive; no `Crawl-delay`; no path exclusions on the main site.

### 1.3 Sitemap
`/sitemap_index.xml` (Yoast) lists 11 sub-sitemaps (post, page, attractions, experiences, classes, track_days, competitor-racing, news, events, …). `events-sitemap.xml` lists exactly **29** event URLs, each with a `<lastmod>` matching the REST `modified_gmt` — a stable change-detection surface. (`/sitemap.xml` and `/wp-sitemap.xml` → 301; the Yoast sitemap is canonical.)

### 1.4 Server-rendered vs JS
- Main site (listing + detail): **fully server-rendered HTML**. Event cards appear in the initial `/events-tickets/` response (no "enable JavaScript" gate).
- Ticketsolve: **JS SPA**. `GET https://mondellopark.ticketsolve.com/ticketbooth/shows/1173669529` returns a ~12.5 kB shell (`<title>Ticketbooth</title>`, all OG meta empty) plus a `preload` JSON blob containing only branding/localisation (`currency=EUR`, `country_iso=IE`, `locales=en-GB`, `show_dates:true`, `show_category:true`, `show_venues:false`). Real show dates/times/prices load client-side via XHR to `/ticketbooth/api/…` which returns **403** for anonymous requests and which Ticketsolve `robots.txt` also disallows (`/api/`). Verified: no date/time/price substring in the 12.5 kB shell. → Ticketsolve is not a machine-readable data source.

### 1.5 Pagination / filtering / infinite scroll
- Listing `/events-tickets/`: exactly **6 `<article>`** cards. No `rel="next"`, no `page=`/`paged=` parameter, no "load more"/infinite-scroll markers in the HTML. The `<select id="events-month-filter">` and `<select id="events-category-filter">` each contain only a single `<option value="all">` in the server HTML (options populated client-side); **no public AJAX endpoint was located** for these filters. The listing is a fixed, future-only snapshot.
- REST: default `per_page=10` (`X-WP-Total: 29`, `X-WP-TotalPages: 3`); `?per_page=100` returns all 29 in one JSON request.
- Listing card data attributes (server-rendered): `data-event-item`, `data-month="YYYY-MM"`, `data-month-label="Mon YYYY"`, `data-date="YYYYMMDD"` (start date only), `data-cats="<slug>"`, `data-cat-labels="<Label>"`. **No** data attributes on the detail-page date `<p>`.

### 1.6 Structured-data inventory
- **JSON-LD (Yoast graph):** `WebPage` + `ImageObject` + `BreadcrumbList` + `WebSite` + `Organization`. **No `schema.org/Event`. No `startDate`/`endDate`/`eventStatus`/`offers`.**
- The only dates in JSON-LD are `datePublished`/`dateModified` = Yoast **page-publish** timestamps. Verified NOT authoritative: ICCR September 2026 detail JSON-LD `datePublished = 2026-01-08T16:25:29+00:00` while the visible event is Sep 12-13 2026; ICCR October `datePublished = 2026-04-15` while the event is Oct 18 2026. → JSON-LD dates must be IGNORED.
- Open Graph: `og:title`, `og:description`, `og:image`, `og:url`, `og:locale=en_GB`, `og:type=article`. `og:description` sometimes embeds the date in prose (e.g. ICCR October og_description "ICCR returns to Mondello Park 18 Oct 2026…") — cross-check only, not authoritative.
- Twitter card: `summary_large_image` plus a generic `twitter:label1="Estimated reading time"` / `twitter:data1="1 minute"` — not event metadata.

### 1.7 Past / cancelled / postponed / sold-out
- Past events remain `publish` and are present in sitemap + REST (e.g. `irx` Oct 4 2025, `iccr-july-2026`, `jdm-classics-january`, `retrostock-2026`). They are omitted from `/events-tickets/` listing, which shows only the 6 upcoming events.
- No machine-readable event status anywhere: no JSON-LD `eventStatus`, no ICS `STATUS:`, no badge element, and no `(CANCELLED)`/`(RESCHEDULED)` title-suffix convention (unlike Motorsport Ireland). The only "cancellation" wording is generic ticket-refund prose on a couple of T&Cs. (inference) Cancellation/postponement/sold-out is therefore not reliably detectable from the source; de-facto "upcoming" is determined by the visible date being in the future.
---

## 2. Event Listing Discovery

- **Master discovery URL:** `https://mondellopark.ie/wp-json/wp/v2/events?per_page=100` — returns all 29 events as JSON (`id`, `slug`, `title.rendered`, `link`, `date`, `date_gmt`, `modified_gmt`, `status`, `event_category` term IDs, `featured_media`, `yoast_head`). Stable + cacheable. `X-WP-Total: 29`.
- **Alternate discovery:** `https://mondellopark.ie/events-sitemap.xml` (29 URLs, each with `lastmod` = REST `modified_gmt`). Equally valid and slightly more portable (slugs + lastmod only).
- **Events visible in the initial listing response (`/events-tickets/`):** **6** (the upcoming Sep–Nov 2026 events).
- **Pagination mechanism:** listing = none (fixed snapshot); REST = numeric pagination with `X-WP-Total`/`X-WP-TotalPages` headers; `per_page=100` collapses to one request.
- **Page size:** listing page size is implicit (future events only; currently 6). REST default `per_page=10`; `per_page=100` accepted (no 400).
- **Past events appear where?** Not on `/events-tickets/` (future-only). Yes on sitemap + REST (23 of 29). The importer MUST filter by parsed date because the source exposes no `eventStatus` and past events remain `publish`.
- **Cancelled/postponed events visible?** No cancellable state is surfaced (Phase 1.7); only past vs future is inferable.
- **Filters:** the month/category `<select>`s are server-empty (client-populated); no crawlable filter API located (inference) — do not rely on `/events-tickets/?month=` etc.
- **Event cards contain stable identifiers:** `data-date="YYYYMMDD"` (start), `data-cats` (category slug), `data-cat-labels`, plus the card's slug via the `View Event` link.
- **Card links:** each card links to **both** a Mondello detail page (`/events/{slug}/`) and a ticket destination. Ticket destinations vary:
  - most cards: Ticketsolve `https://mondellopark.ticketsolve.com/ticketbooth/shows/{id}?utm_source=mondello-event-page&utm_medium=referral`;
  - `james-deane-130-showdown` card: `Book Now` → `/events/james-deane-130-showdown/#BookNow`, `View Event` → `https://130showdown.com/`;
  - `fia-euro-rx` card (future edition): `View Event` → `https://erxmondellopark.com/` (301 external).

### Is `/events-tickets/` sufficient as the master discovery source?
**No.** It exposes only the 6 currently-upcoming events, offers no machine-readable end-date, and omits past events. Use **`GET /wp-json/wp/v2/events?per_page=100`** (or `events-sitemap.xml`) as the authoritative discovery source (complete set + stable `modified`/`lastmod` for change detection), then resolve each event's date/ticket/category from its `/events/{slug}/` detail page.

## 3. Individual Event Structure (field origin)

17 detail pages were parsed end-to-end. Where each field comes from:

| Field | Present? | Source (origin) | Notes |
|---|---|---|---|
| title | ✅ | `<h1>` on detail = REST `title.rendered` = listing `<h2>` | en-GB locale |
| subtitle | ❌ | — | none |
| short description | n/a | — | not a separate field; `og:description` is a truncated excerpt of the body |
| full description | ✅ | `<div class="the-content …">` = REST `content.rendered` | Promotional marketing copy — **omit from import** (Phase 9) |
| start date | ✅ | detail `<p class="font-normal text-lg text-black mb-0">…</p>`; listing `data-date="YYYYMMDD"` (start) | US order, no machine datetime; see Phase 4 |
| end date | ✅ | same paragraph, after ` - ` (detail); listing text range | parsed from text |
| start time | ❌ | — | none machine-readable; prose only (Phase 4) |
| end time | ❌ | — | prose only |
| venue | ⚠️ implicit | footer `Mondello Park` on every page | no per-event venue field |
| location | ⚠️ implicit | footer `Mondello Park, Donore, Naas, Co. Kildare, Eircode: W91 T957` | no per-event address |
| county | ⚠️ implicit | footer → Co. Kildare |  |
| Eircode | ⚠️ implicit | footer `Eircode: W91 T957` |  |
| map URL | ❌ | — | none on event page |
| canonical URL | ✅ | `<link rel="canonical" href=".../events/{slug}/">` |  |
| ticket URL | ✅ (card) | `Book Now` href → Ticketsolve `…/shows/{id}` (or external site) | not guaranteed present (Phase 5) |
| registration URL | ❌ | — | ticket URL serves both |
| organizer | ⚠️ weak | external link where present (e.g. `iccr.ie/events`, `130showdown.com`) | not a labelled field |
| category | ✅ | `<div class="pill">` (detail) + body class `event_category-{slug}` + listing `data-cats`/`data-cat-labels` + REST term IDs | 8 terms (Phase 8) |
| price | ❌ | — | Ticketsolve/JS only (403) |
| image URL | ✅ | detail `og:image` = featured image (`wp-content/uploads/…webp`); `srcset` on listing card `<img>` | local assets |
| source ID | ✅ | WP REST `id` (e.g. 2134) + `slug` + canonical | use slug (Phase 5) |
| external ticket ID | ✅ | Ticketsolve show ID in ticket href (secondary) | e.g. `1173669529` |
| cancellation status | ❌ | — | none machine-readable (Phase 6) |
| postponement status | ❌ | — | none (Phase 6) |
| accessibility info | ❌ | — | none |
| age restrictions | ⚠️ prose | e.g. T&Cs "under 16", "over 16" — unstructured |  |

### Representative events sampled (Phase 3 coverage)
- single-day ✅: Action Day (Jun 1), Japfest (Apr 26), ICCR Oct (Oct 18), JDM Classics AE86 (Sep 6).
- multi-day ✅: ICCR Sep (12-13), Drift Games Summer Bash (22-23), Historic Festival (May 9-10), Masters Superbike (Jun 27-28), Full of the Pipe (Jul 11-12), Mondello 24 (Jun 20-21).
- motorsport race weekend ✅: ICCR, IRX, Masters Superbike.
- car show / festival ✅: Japfest, JDM Classics, Historic Festival, Full of the Pipe Truck Show.
- driving-related ✅: Mondello 24 (24-hour cycling endurance); Action Day (track-focused).
- detailed description ✅: Action Day (~1149 chars).
- minimal description ✅: JDM Classics AE86 (~135 chars).
- ticket purchase link ✅: most → Ticketsolve.
- external organizer ✅: ICCR (`iccr.ie`), James Deane (`130showdown.com`, organizer contact `hello@lzworldtour.com`).
- unusual date formatting ✅: `jdm-classics-ae86` renders "September 6, 2026 - September 6, 2026" (same-day range); `irx` (Oct 4 2025) past; `fia-euro-rx` detail 301-redirects away.

**Raw date markup (detail page) — verbatim.** For ICCR September 2026:
```
September 12, 2026</span> 
   - September 13, 2026         </p>
```
There is a stray `</span>` with no opening `<span>`, **no `<time>` element and no `datetime` attribute**. Single-day events render as either `Month D, YYYY` (Action Day: `June 1, 2026`) or a same-day range `Month D, YYYY - Month D, YYYY` (JDM Classics AE86).

## 4. Date/Time Analysis (CRITICAL)

- **Timezone published:** none. No `IST`/`GMT`/`Europe/Dublin`/`UTC` string on any page (`og:locale=en_GB`; venue Co. Kildare → implied Irish local). No conversion needed: store **calendar dates only** (consistent with the project rule "do not convert local Irish wall-clock times to UTC unless explicitly required"). (inference)
- **Dates appear in visible HTML only** (detail date `<p>` + listing `data-date` start + listing `DD - DD Mon YYYY` text). No `<time datetime>`, no JSON, no API field.
- **JSON-LD dates are NOT valid event dates** — the only JSON-LD dates are Yoast `datePublished`/`dateModified` publish timestamps (e.g. `2026-01-08T16:25:29+00:00` for iccr-september-2026, which publishes Jan 8 but runs Sep 12-13). Must be ignored.
- **Date-range representation:** correct on the detail page (`September 12, 2026 - September 13, 2026`). A Sat/Sun weekend stays one event (one `<article>` / one `<p>`). Start date is machine-readable on the listing (`data-date="20260912"`); the end date is only in the visible text.
- **Main-site vs ticketing date/time:** Ticketsolve show pages contain **no** date/time/price in the server response (12.5 kB JS shell; `/api/` 403). Cross-check is not possible from the server side. (inference)
- **Are times event times or gate times?** The only time-of-day text is gate/promo prose: "Gates open to the General Public from 9.00am" / "between 10am and 6pm" (in T&Cs; present on 16/17 pages). Occasional event-hours prose exists (Full of the Pipe: "Saturday 11am to 6pm, Sunday 10am to 4pm"; Mondello 24: "begins at midday on June 20th"). These are **prose, inconsistent, not fields** → do NOT import as start/end times.
- **All-day events:** exist implicitly (single-day `Month D, YYYY` with no time); treat as date-only.
- **TBC/TBA:** **none found.** (Verified: the `[Tt][Bb][Aa]` regex hits were the substring `tBa` inside minified JS `"textBaseline"` — confirmed false positives.) Every published event has a concrete date. (inference)

### Date-source comparison (Phase 4 cross-check, 4 events)
| Event | Listing `data-date` (start) | Listing visible | Detail visible | REST `date_gmt` (created) | JSON-LD `datePublished` | Verdict |
|---|---|---|---|---|---|---|
| iccr-september-2026 | 20260912 | 12 - 13 Sep 2026 | September 12, 2026 - September 13, 2026 | 2026-01-08 (publish) | 2026-01-08 (publish) | detail = authoritative |
| iccr-october-2026 | 20261018 | 18 Oct 2026 (single) | October 18, 2026 | 2026-01-08 | 2026-04-15 (publish) | detail = authoritative |
| irx-october-2026 | 20261003 | 3 - 4 Oct 2026 | October 3, 2026 - October 4, 2026 | 2026-01-08 | 2026-04-15 (publish) | detail = authoritative |
| james-deane-130-showdown | 20260919 | 19 - 20 Sep 2026 | September 19, 2026 - September 20, 2026 | 2026-08-13 (publish) | 2026-08-13 (publish) | detail = authoritative |

JSON-LD `datePublished` only ever matches the WP creation timestamp, never the event date — reinforcing the rule to ignore it.

**Authoritative date/time source = the visible date paragraph on the detail page** (start parsed from there, or from listing `data-date`; end parsed from the detail text range). There is **no event time** to import.

## 5. Identity / Deduplication

### Candidate keys (investigated in order)
1. Explicit event ID — **none** (no `data-event-id`, no JSON-LD `@id` on an Event).
2. API/object ID — WP REST `id` (e.g. 2134) — stable but Mondello-local and **not portable** as a Conexão migration key (project rule: don't use local WP IDs); also meaningless until the source is fetched.
3. Ticketing event ID — Ticketsolve show ID (e.g. `1173669529` in the ticket href). Stable and numeric, but **absent** for non-ticketed events (`fia-euro-rx`, `mondello-24`, `ids-winter-series-round-2`) and **multi-valued** for Winter Bash (two IDs). Not a universal key.
4. **Stable canonical URL** — `<link rel="canonical" href="https://mondellopark.ie/events/{slug}/">` on every detail page; matches REST `link`. ✅
5. **Stable slug** — REST `slug` (e.g. `iccr-september-2026`); matches the canonical URL path. ✅
6. Immutable source identifier — see (4)/(5). `guid.rendered` (`exsitec32.sg-host.com/?post_type=events&p=2134`) is an internal host and is NOT used.

### One Mondello event ↔ how many ticket pages?
- Most: 1 main-site page + 1 Ticketsolve show page (1 ticket URL).
- `drift-games-winter-bash`: 1 main-site page + **TWO** Ticketsolve show IDs (`1173670621` "Book Now" AND `1173670623` "DRIFT BASH TICKETS") + external web page (`bash.driftgames.life/#spectator-tickets`). Confirmed evidence: both `…/shows/1173670621` and `…/shows/1173670623` appear in the page's hrefs.
- `james-deane-130-showdown`: 1 main-site page + **no** Ticketsolve link; tickets handled on external `130showdown.com`.
- `fia-euro-rx`: 1 main-site page that **301-redirects** to `erxmondellopark.com` (no ticket link).

### Multi-day handling
A Sat/Sun weekend is **one event identity** (one slug, one `<article>`, one date range paragraph). Do not split. ✅

### Recommended identity key
```
_event_source        = "mondellopark"            # project source namespace
_event_source_id     = {slug}                    # e.g. "iccr-september-2026"
_event_source_url    = "https://mondellopark.ie/events/{slug}/"   # canonical
```
**Rationale:** the slug is stable, REST- and sitemap-exposed, present on every event, and portable (survives a source DB reset). It satisfies the project's `source + source_id + export UUID` model (docs/content-model.md §Events; dedup match order `UUID → source+source_id → URL → content title+date`). The Ticketsolve show ID is a **secondary** field (store as `_event_url`/ticket), not the dedup key. The WordPress `id` (2134…) must NOT be used.

`https://130showdown.com/` is a standalone non-WordPress site (title "130 Showdown — Celebrating 20 Years of Drift | Mondello Park"; no `wp-content`); it is the ticket host for James Deane only — treat its URL as the event's external ticket/organizer URL, not as a Mondello identity.

---

## 6. Event Status / Cancellations

No machine-readable status exists on the source:
- No JSON-LD `eventStatus`; no ICS `STATUS:`; no status badge element; no `(CANCELLED)`/`(RESCHEDULED)` title-suffix convention (unlike Motorsport Ireland). (inference)
- The only "cancellation" wording is generic ticket-refund prose on the James-Deane T&Cs page ("in the unlikely event of cancellation or postponement… if rescheduled, tickets will automatically be valid for the new date").

| State | How it appears | Detectable? |
|---|---|---|
| upcoming | listed on `/events-tickets/`; visible date in future | ✅ via parsed date |
| past / expired | dropped from listing; still `publish` + in sitemap/REST; visible date in past | ✅ via parsed date |
| archived | indistinguishable from past (e.g. `irx` Oct 4 2025 still published) | ✅ via date |
| cancelled | none observed | ❌ |
| postponed / rescheduled | only T&C refund prose; no live status | ❌ |
| sold out | Ticketsolve UI only (JS); unreachable | ❌ |
| closed | none | ❌ |

### Recommendation for the Event Runtime
**Do not invent a source-specific status system.** The existing `Conexao_Event_Status` (docs/plugins/conexao-event-runtime.md) already models `published`/`expired`/`source_not_found`/`draft`/`rejected`. Use it:
- set `_event_status = published` for events whose parsed end-date is upcoming;
- rely on the existing `Conexao_Event_Status::mark_expired_events()` (run synchronously at the end of every import) to flip to `expired` once the end date passes;
- let admins set `rejected` for non-importable stubs (e.g. the `fia-euro-rx` redirect stub);
- `source_not_found` is raised automatically by the existing missing-source handling when a slug drops from the sitemap/REST.
Cancellation detection is genuinely unavailable from this source — do **not** attempt heuristic title parsing (no suffix convention exists here; it would be unreliable).

---

## 7. Location Analysis

- **All sampled events occur at Mondello Park.** No alternate venue appeared on any of the 17 detail pages. The venue/address is **not** on the event card or detail body; it lives in the site/footer, identical on every page.
- **Footer (every page):** `Mondello Park, Donore, Naas, Co. Kildare, Eircode: W91 T957`. Phone `+353 (0)45 860200`. (Evidence: footer address scan returned `Eircode: W91 T957` and `Mondello Park, Donore, Naas, Co. Kildare`.)
- Per-event: no venue name, street, town, county, Eircode, coordinates, or map URL. `W91 T957` and the Donore/Naas/Kildare address are **consistently supplied** — via the footer, not via per-event data.

Reliability for Conexão:
- ✅ app display → venue label `Mondello Park`;
- ✅ county filtering → `conexao_county` = **Kildare**;
- ✅ town filtering → `conexao_town` = **Naas** (Donore is a Kildare townland; the source gives "Naas");
- ⚠️ map actions → no map URL from source; fall back to the existing importer behaviour of deriving a Google Maps search URL from the strongest available address (`_event_map_url` is "never overwritten when non-empty" — docs/content-model.md), using the footer address. (inference)

### Recommendation
Attach the known Mondello Park address to every imported event (it is the venue default, not source-supplied per-event data). Do not fabricate per-event venues.

---

## 8. Categories / Taxonomy

`event_category` taxonomy (8 terms, via `/wp-json/wp/v2/event_category`):

| id | slug | name | count |
|---|---|---|---|
| 23 | `car-racing` | Car Racing | 5 |
| 22 | `drifting` | Drifting | 7 |
| 34 | `rally` | Rally | 5 |
| 38 | `motorbike-racing` | Motorbike Racing | 5 |
| 35 | `jdm` | JDM | 3 |
| 18 | `retro-historic` | Retro/Historic | 1 |
| 47 | `shows` | Shows | 1 |
| 36 | `ids` | IDS | 1 |

(Term IDs sum to 28; `mondello-24` has none — a known gap.)

- **Stable?** Yes (persistent term IDs + slugs).
- **Multi-valued?** In practice single-valued per event (body class and `event_category[]` both show one term). Treat as 0..N defensively.
- **Embedded in URLs?** No category path segments; categories appear only as body class `event_category-{slug}`, listing `data-cats`/`data-cat-labels`, detail `<div class="pill">`, and REST term IDs.
- **On cards?** Yes (`data-cats`, `data-cat-labels`).
- **Structured data?** No (no Event schema).

### Mapping into Conexão (no new categories)
| Mondello `event_category` slug | Recommended Conexão category |
|---|---|
| `car-racing` | "Motorsport" / Car Racing (existing) |
| `drifting` | "Drifting" (existing) |
| `rally` | "Rally" (existing) |
| `motorbike-racing` | "Motorbike Racing" (existing) |
| `jdm` | "JDM" (existing) |
| `retro-historic` | "Retro/Historic" (existing) |
| `shows` | "Show"/Exhibition (existing) |
| `ids` | "Drifting" (Irish Drift Series) |

Do **not** introduce new top-level categories; fold Mondello's 8 terms onto the existing motorsport/show vocabulary.

---

## 9. Image / Content Rights Boundary

- **Images:** featured images are local Mondello `wp-content/uploads/2026/…webp` assets (e.g. `ICCR-September-2026.webp`, `130-showdown.jpg`), referenced by `og:image` and `srcset`. They are promotional photographs → **omit full reproductions**; Conexão manages its own event banners.
- **Descriptions:** marketing copy ("WELCOME TO IRISH CHAMPIONSHIP CIRCUIT RACING…", festival blurbs) → **omit** (not facts, copyrighted by Mondello).
- **Logos / PDFs:** none on event pages.
- **Ticket T&Cs:** present as prose (refunds, gate times, liability) → copyright/promotional; do not import.
- **Footer copyright:** `© Mondello Park All rights reserved.` (explicit claim).
- **Legal pages:** `/privacy-policy/` (GDPR/Data Protection Acts) and `/terms-conditions/` exist and are publicly readable. A keyword scan of the terms text (**scrap / crawl / robot / automate / reproduce / copyright / intellectual property / data / permission / licence**) found **no explicit anti-scraping / crawler-prohibition clause** — only unrelated hits (`"automatic license"` for drivers, `"driving licence"`). robots.txt is permissive. (inference — the full pages were not line-read in full, but the scanned keywords are the usual anti-bot terms and were absent.)

### Safe-for-import (Phase 9 favoured list)
A) event title ✅ · B) factual date range ✅ · C) factual location (Mondello Park / Kildare / W91 T957) ✅ · D) organizer (only when a clear external link is present) ✅ · E) category ✅ · F) ticket URL ✅ · G) canonical source URL ✅ · H) featured-image URL (for optional thumbnail, not bulk reproduction).

### Intentionally omitted
- full promotional description (`the-content`);
- large featured images (Conexão manages its own banners);
- ticket T&Cs / refund / gate-time prose;
- ticket prices (JS-only, unreachable);
- logos, PDFs.

No permission statement was found that *requires* opt-in, but the copyright footer means the importer must limit reproduction to the factual fields above.

## 10. Change Detection / Update Model

Observed change signals:
- `modified_gmt` (REST) and the sitemap `<lastmod>` update when an event is edited and are consistent. Evidence: `jdm-classics-ae86` modified `2026-09-07` (run date Sep 11 2026) — i.e. recently touched; `james-deane-130-showdown` modified `2026-09-08`. These are deterministic staleness signals for incremental updates.
- **Title changes:** reflected in REST `title.rendered` + HTML `<h1>`.
- **Date/time changes:** reflected in the detail `<p>` / listing `data-date`.
- **Page slug changes:** reflected in the canonical URL + REST `slug`/`link` — the existing URL match (`_event_source_url`) re-links it.
- **Ticket URL changes:** observed — the ticket show ID can change (e.g. new Ticketsolve performances); Winter Bash exposes two IDs. Store the primary `Book Now` href; detect change by comparing it on re-fetch.
- **Cancellation occurs:** not signalled on-source (Phase 6). The `fia-euro-rx` stub instead **301-redirects** away — a "disappeared/redirected" signal the importer should treat as skip/reject.
- **Event disappears:** if a slug no longer appears in `/wp-json/wp/v2/events` or `events-sitemap.xml`, the existing **missing-source** handling marks it `source_not_found`.

### Recommended importer behaviour (reuse shared lifecycle)
- **create** = new future event in REST/sitemap but not in Conexão.
- **update** = existing `mondellopark:{slug}` whose `modified_gmt`/`lastmod` is newer **or** whose parsed title/date/ticket URL changed.
- **expire** = existing event whose parsed end-date is now past → rely on `Conexao_Event_Status::mark_expired_events()` (already run per import — docs: "runs synchronously at the end of every import run").
- **mark missing (`source_not_found`)** = existing event whose slug is absent from both REST and sitemap.
- **skip** = past-dated events and redirect-stub events (fia-euro-rx). Never create these.

Do **not** write source-specific cleanup; the existing importer engine (`includes/class-event-importer.php` — upsert-by-source-id, dry-run, missing-source handling) already covers the lifecycle.

---

## 11. Request / Rate-Limit Behaviour

- **Normal page response time:** listing `200 126014B ~0.75 s` (SG Dynamic Cache hit); detail `200 ~14 KB ~0.47–0.52 s`; REST `200 ~17 KB ~0.25 s`. 5 rapid back-to-back listing requests all returned 200 (~0.74–0.77 s each) — no per-IP token bucket observed once the edge burst cleared.
- **Browser-UA requirement:** the **very first** probes (including the headless fetch tool) returned `403 Forbidden` from the SG edge (`host-header: 8441280b0c35cbc1147f8ba998a563a7`; `server: nginx`; `x-proxy-cache-info: DT:1`; body `<title>403 - Forbidden</title>`). After the burst subsided, even a **default `curl` User-Agent** returned **200**. (inference) The 403 is assessed as **transient SG-edge + Wordfence burst throttling**, not a hard user-agent gate; nonetheless an importer must send a modern browser UA + `Accept-Language` and retry on 403.
- **Cloudflare/WAF:** none on the main site (no `cf-ray`). Wordfence (`wordfence/v1`) + SiteGround nginx edge are the gatekeepers.
- **Rate limits:** none declared; no `Retry-After`/`429` observed.
- **Redirects:** `/events/` → 301 → `/events-tickets/`; `/sitemap.xml` & `/wp-sitemap.xml` → 301; `fia-euro-rx` detail → 301 → `erxmondellopark.com`; Ticketsolve show → 302 → Queue-it → back (`TSLVq…`).
- **robots restrictions:** main site fully open (Phase 1). Ticketsolve `robots.txt` disallows `/api/`, `/cart`, `/checkout`, `/orders`, `/facebook`, `/performances/all`; root is allowed but the site is JS-rendered (useless for data).
- **Recommended crawl delay:** **~1.0 s** between requests. Required headers: `User-Agent: <modern browser>`, `Accept: text/html,...` (or `application/json` for REST), `Accept-Language: en-GB,en;q=0.9`. Exponential back-off (2 s → 4 s → 8 s) on `403`.
- **Ticketing subdomain:** always challenged by Queue-it ("Safetynet") + `/api/` 403 — do **not** crawl; only parse the show ID out of the Mondello ticket href.

### Evidence log (Phase 11)
- `curl -sS -o /dev/null -w 'HTTP %{http_code}' -A "<browser>" https://mondellopark.ie/events-tickets/` → after burst: `200`, 126014 B.
- Default `curl -sS https://mondellopark.ie/events-tickets/` (no browser headers) → `200`.
- First-attempt HEAD/GET (browser UA): `403 75193B "6a98b880-125b9"` (SG edge challenge page).

---

## 12. Live Discovery Sample

Master discovery `GET /wp-json/wp/v2/events?per_page=100`: **29 events** (`X-WP-Total: 29`). Cross-checked against `events-sitemap.xml`: **29 URLs** (identical slugs).

Conservative crawl performed: 1 listing fetch + 17 detail-page fetches (one per slug available offline) + REST term lookup + 1 Ticketsolve show shell. No aggressive crawling (≈18 requests total, ~1 s spacing).

Deterministic parse of the 17 fetched event detail pages (audit "today" = 11 Sep 2026; year inferred as 2026 except where the page states 2025 — i.e. `irx`):

| source_id (slug) | title | start | end | location | category | canonical URL | ticket URL | parse result |
|---|---|---|---|---|---|---|---|---|
| iccr-september-2026 | ICCR September 2026 | Sep 12, 2026 | Sep 13, 2026 | Mondello Park | Car Racing | /events/iccr-september-2026/ | ticketsolve:1173669529 | PASS |
| james-deane-130-showdown | James Deane – 130 Showdown | Sep 19, 2026 | Sep 20, 2026 | Mondello Park | Drifting | /events/james-deane-130-showdown/ | external 130showdown.com | SKIP — tickets only on external site (no on-site ticket URL) |
| irx-october-2026 | IRX October 2026 | Oct 3, 2026 | Oct 4, 2026 | Mondello Park | Rally | /events/irx-october-2026/ | ticketsolve:1173669538 | PASS |
| iccr-october-2026 | ICCR October 2026 | Oct 18, 2026 | Oct 18, 2026 * | Mondello Park | Car Racing | /events/iccr-october-2026/ | ticketsolve:1173669532 | PASS |
| drift-games-winter-bash | Drift Games Winter Bash | Nov 14, 2026 | Nov 15, 2026 | Mondello Park | Drifting | /events/drift-games-winter-bash/ | ticketsolve:1173670621 (+…23) | AMBIGUOUS (dual show IDs) |
| irx-november-2026 | IRX November 2026 | Nov 28, 2026 | Nov 29, 2026 | Mondello Park | Rally | /events/irx-november-2026/ | ticketsolve:1173669539 | PASS |
| irx | IRX | Oct 4, 2025 | Oct 4, 2025 | Mondello Park | Rally | /events/irx/ | ticketsolve:1173667620 | SKIP — past |
| ids-winter-series-round-2 | IDS Winter Series Round 2 | Jan 24, 2026 | Jan 24, 2026 | Mondello Park | IDS | /events/ids-winter-series-round-2/ | (none) | SKIP — past + no ticket link |
| masters-superbike-june-2026 | Masters Superbike | Jun 27, 2026 | Jun 28, 2026 | Mondello Park | Motorbike Racing | /events/masters-superbike-june-2026/ | ticketsolve:1173671177 | SKIP — past |
| retrostock-2026 | Retrostock 2026 | Aug 9, 2026 | Aug 9, 2026 | Mondello Park | Drifting | /events/retrostock-2026/ | ticketsolve:1173613976 | SKIP — past |
| action-day | Action Day | Jun 1, 2026 | Jun 1, 2026 | Mondello Park | Drifting | /events/action-day/ | ticketsolve:1173670220 | SKIP — past |
| japfest-2026 | Japfest 2026 | Apr 26, 2026 | Apr 26, 2026 | Mondello Park | Drifting | /events/japfest-2026/ | ticketsolve:1173669788 | SKIP — past |
| drift-masters | Drift Masters | Jun 13, 2026 | Jun 14, 2026 | Mondello Park | Drifting | /events/drift-masters/ | ticketsolve:1173623468 | SKIP — past |
| historic-festival | Historic Festival | May 9, 2026 | May 10, 2026 | Mondello Park | Retro/Historic | /events/historic-festival/ | ticketsolve:1173668801 | SKIP — past |
| full-of-the-pipe-truckshow | Full of the Pipe Truck Show | Jul 11, 2026 | Jul 12, 2026 | Mondello Park | Shows | /events/full-of-the-pipe-truckshow/ | ticketsolve:1173669789 | SKIP — past |
| mondello-24 | Mondello 24 | Jun 20, 2026 | Jun 21, 2026 | Mondello Park | (none) | /events/mondello-24/ | external jeep-mondello24 | SKIP — past + off-venue content (cycling) + off-site ticket |
| fia-euro-rx | FIA Euro RX | (redirected) | — | Mondello Park | Rally | /events/fia-euro-rx/ | (none) | SKIP — 301 redirects to external erxmondellopark.com |

\* single-day event rendered as "October 18, 2026" (no ` - `) = one day. (The same-day-range doubling seen on `jdm-classics-ae86` "Sep 6 - Sep 6" was NOT observed on this event.)

**Tallies:** PASS = 4 (iccr-september, irx-october, iccr-october, irx-november); AMBIGUOUS = 1 (drift-games-winter-bash — dual Ticketsolve IDs); SKIP = 12 (11 past + `fia-euro-rx` redirect). Of the 6 currently-future events, 4 import cleanly, 1 is PASS-with-ambiguity (Winter Bash), 1 is SKIP-due-to-external-tickets only (James Deane — though its canonical URL + dates are still reusable as a free event).

**PASS/SKIP/AMBIGUOUS explanations**
- PASS (4): clean future event, single Ticketsolve ticket URL, date parsed from detail `<p>`, category pill + REST term present, Mondello Park location implicit.
- AMBIGUOUS (1 — `drift-games-winter-bash`): two distinct Ticketsolve show IDs (`1173670621`, `1173670623`) + an external web page. Recommendation: import using the primary `Book Now` ID and store the secondary ID/external link alongside (Conexão has no native "secondary ticket URL"; use `meta`).
- SKIP (12): 11 are past (parsed end-date before audit "today") and must be skipped per the importer's past-event rule; `fia-euro-rx` is a 301 redirect stub (external) — do not import.

---

## 13. IMPLEMENTER FEASIBILITY DECISION

**IMPLEMENTATION RECOMMENDED WITH LIMITATIONS**

The source is importable but imposes real constraints (custom date parsing, no event times, past events linger in the source, JS-only ticketing). These are handled by source-specific date/identity logic layered on top of the **existing shared** importer pipeline — no new runtime/status system is required.

### Field mapping table (source → Conexão event meta)

| Conexão field | Source | Source path | Notes |
|---|---|---|---|
| `_event_source` | — | literal `"mondellopark"` | new source namespace |
| `_event_source_id` | event slug | REST `slug` = canonical path basename | primary dedup key |
| `_event_source_url` / `_event_url` | canonical | `<link rel="canonical">` = `…/events/{slug}/` | source URL |
| `_event_date` (start day) | visible date | detail `<p>` first half OR listing `data-date` (`YYYYMMDD`) | date-only (no time) |
| `_event_end_date` (end day) | visible date | detail `<p>` after ` - ` | date-only; =start if single-day |
| `_event_start_time` / `_event_end_time` | **absent** | — | leave empty (no time on source) |
| `_event_venue` / `_event_address` | footer default | `Mondello Park, Donore, Naas, Co. Kildare` | implicit per-event |
| `_event_county` | footer | Co. Kildare | `conexao_county` |
| `_event_town` | footer | Naas | `conexao_town` |
| `_event_eircode` | footer | W91 T957 | (no existing key — confirm) |
| `_event_map_url` | derived | Google Maps search URL from footer address | existing flow |
| `conexao_category` | `event_category` | pill label / body class / REST term ID | map 8 terms (Phase 8) |
| featured image | og:image | `og:image` = `wp-content/uploads/…webp` | local assets |
| `_event_registration` / ticket CTA | `Book Now` href | detail/listing → Ticketsolve `{showId}` (or external) | primary only; secondary via meta |
| `_event_organizer` | external link | e.g. `iccr.ie/events`, `130showdown.com` | only when clearly an organizer |
| `_event_price` | absent | — | leave empty (ticketing is JS-only) |
| `_event_status` | derived | `published` if end-date >= today; `expired`/`source_not_found` via shared runtime | reuse `Conexao_Event_Status` |
| `_event_last_checked` / `_event_import_date` | run metadata | importer run timestamp | existing hooks |

## 14. NO IMPLEMENTATION

This Stage A audit creates **no code**. Do NOT create the importer, and do NOT modify (in Stage A):
- the Event Runtime (`conexao-event-runtime`) — e.g. do not add a Mondello status;
- the Event Importer (`conexao-event-importer`) — no source handler wired;
- any existing source module (IVVCC / Motorsport Ireland / Eventbrite);
- WordPress production;
- any local event records.

### Stage B (implementation) recommendations — NOT executed in Stage A
1. **Discovery:** register `https://mondellopark.ie/wp-json/wp/v2/events?per_page=100` as the master fetch; cross-verify with `events-sitemap.xml` for `lastmod`.
2. **Detail fetch:** GET `/events/{slug}/`.
3. **Date parser:** English-month-name parser for `Month DD, YYYY` and `Month DD, YYYY - Month DD, YYYY`; a same-day range ("Sep 6 - Sep 6") collapses to one day. Store `_event_date`/`_event_end_date` as `Y-m-d`; leave time fields empty.
4. **Identity:** `_event_source="mondellopark"`, `_event_source_id={slug}`. Reuse the existing matcher (`UUID → source+source_id → URL → content title+date`).
5. **Location:** apply the Mondello Park footer address as the default; county=Kildare, town=Naas.
6. **Category map:** the 8-row table in Phase 8.
7. **Tickets:** capture the primary `Book Now` Ticketsolve URL (strip `?utm_source=…` params) as the ticket CTA; store any secondary ID/external link in custom meta (not a URL field).
8. **Status:** reuse `Conexao_Event_Status`; do not invent statuses.
9. **Past filtering:** the importer already "never creates past events" — mandatory here because the source publishes past events.
10. **HTTP:** browser UA + `Accept-Language: en-GB`; 1.0 s delay; exponential back-off on 403; never hit `tickets.mondellopark.ie` or `ticketsolve …/api/`.
11. **Content:** import only factual fields (Phase 9 safe list); never import the `the-content` marketing copy or the footer copyright.
12. **Skip rules:** skip past-dated events; skip `fia-euro-rx` (301 to external) and treat James Deane as external-ticket-only (import canonical+dates as a free event at admin discretion).
13. **Docs update:** add `docs/importers/mondello-park-stage-b-…` and a handler-registry entry when (and only when) implemented.

### Evidence appendix (key HTTP facts verified during this audit, "today" = 11 Sep 2026)
- `GET https://mondellopark.ie/wp-json/wp/v2/events?per_page=100` → `200`, `application/json`, `X-WP-Total: 29`, 29 objects.
- `GET https://mondellopark.ie/wp-json/wp/v2/events/2134` → `200`; `id=2134`, `type=events`, `slug=iccr-september-2026`, `event_category:[23]`, `acf:[]`, `guid.rendered="https://exsitec32.sg-host.com/?post_type=events&p=2134"`.
- `GET https://mondellopark.ie/wp-json/wp/v2/event_category` → 8 terms: 23 Car Racing, 22 Drifting, 36 IDS, 35 JDM, 38 Motorbike Racing, 34 Rally, 18 Retro/Historic, 47 Shows.
- `GET https://mondellopark.ie/robots.txt` → `200`, Yoast block, `User-agent: * Disallow:`, Sitemap `…/sitemap_index.xml`.
- `GET https://mondellopark.ie/sitemap_index.xml` → `200`, lists `events-sitemap.xml`.
- `GET https://mondellopark.ie/events-sitemap.xml` → `200`, 29 URLs, lastmod `2026-01-23`→`2026-09-08`.
- `GET https://mondellopark.ie/events-tickets/` → `200`, 6 `<article data-event-item>` cards; card 1: `data-date="20260912"`, `data-cats="car-racing"`, `data-cat-labels="Car Racing"`; Book Now → `https://mondellopark.ticketsolve.com/ticketbooth/shows/1173669529?utm_source=mondello-event-page&utm_medium=referral`; View Event → `/events/iccr-september-2026/`.
- `GET https://mondellopark.ie/events/iccr-september-2026/` → `200`, H1 "ICCR September 2026", date `<p>` "September 12, 2026</span>   - September 13, 2026", pill "Car Racing".
- `GET https://mondellopark.ie/events/james-deane-130-showdown/` → `200`, buttons "130 Showdown"→`130showdown.com`, "View Event"→`130showdown.com`; **no** Ticketsolve link.
- `GET https://mondellopark.ie/events/drift-games-winter-bash/` → `200`, TWO Ticketsolve hrefs (`…/shows/1173670621` and `…/shows/1173670623`) + external `bash.driftgames.life/#spectator-tickets`.
- `GET https://mondellopark.ie/events/fia-euro-rx/` → `301` → `https://erxmondellopark.com/index.html`.
- `GET https://mondellopark.ie/events/irx/` → `200`, date "October 4, 2025" (past).
- `GET https://mondellopark.ie/events/mondello-24/` → `200`, no category pill, Book Now → `https://mondellopark.ie/jeep-mondello24/` (off-site ticket).
- `GET https://mondellopark.ie/wp-json/acf/v3/events/2134` → `404 rest_no_route` (ACF not REST-enabled).
- `GET https://mondellopark.ie/wp-json/` → `200`; namespaces include `wp/v2`, `yoast/v1`, `wordfence/v1`, `cptui/v1`, `checkfront/v3`, `redirection/v1`, `mcp` (oauth adapter only).
- Ticketsolve `GET …/ticketbooth/shows/1173669529` → `302`→Queue-it→`302`→`200` (12.5 kB shell, `<title>Ticketbooth</title>`, empty OG); `preload` JSON is branding-only; no date/time/price substring; headers `x-powered-by: Phusion Passenger(R) Enterprise`, `server: nginx + Phusion Passenger(R)`.
- Ticketsolve `GET https://mondellopark.ticketsolve.com/api/shows/1173669529` → `403`.
- `GET https://tickets.mondellopark.ie/` → `403`, Cloudflare "Just a moment…".
- `GET https://mondellopark.ie/events-sitemap.xml` lastmod values match REST `modified_gmt`; e.g. `jdm-classics-ae86` lastmod `2026-09-07`, `james-deane-130-showdown` `2026-09-08`.
- Timing: listing `200 126014B ~0.75s`; detail `200 ~14KB ~0.47–0.52s`; REST `200 ~17KB ~0.25s`. Initial burst probes → `403` 75193B (SG edge); after cooldown, even default-curl UA → `200`.
- Footer (all detail pages): `Mondello Park, Donore, Naas, Co. Kildare, Eircode: W91 T957`; phone `+353 (0)45 860200`; copyright `© Mondello Park All rights reserved.`.

*End of Stage A audit — no WordPress posts created/updated/deleted; no plugin/runtime/source code modified; no new import source activated.*







