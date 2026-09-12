# Motorsport Ireland Event Importer

> **Source:** `https://www.motorsportireland.com/events` — Motorsport Ireland
> master calendar (aggregates all 32 affiliated clubs).
> **Stage B implementation** of [motorsport-ireland-audit.md](motorsport-ireland-audit.md).
> **Status:** implemented + dry-run validated. The source ships **inactive** —
> see [Stage C gate](#stage-c-production-import-gate) below.

## Source

- **Platform:** Squarespace 6 (no public API). `robots.txt` blocks the
  per-event ICS feeds (`?format=ical`) — the importer never requests ICS.
- **Master calendar only.** The authoritative discovery source is the single
  `/events` listing page (no pagination, no AJAX). Affiliated-club websites
  are **never** crawled (audit §1/§30).
- **Access:** plain browser-UA HTTP GET (inherited from
  `Conexao_Source_Base::get_http_user_agent()`), public pages only.

## Files

| File | Role |
|---|---|
| `wp-content/plugins/conexao-event-importer/includes/sources/class-motorsport-ireland-source.php` | `Conexao_Source_Motorsport_Ireland` handler |
| `wp-content/plugins/conexao-event-importer/tests/test-motorsport-ireland-importer.php` | Parser/identity/date/dedup/idempotency + CSS-leak regression tests (§31) |
| `wp-content/plugins/conexao-event-importer/tests/probe-mi-css-leak.php` | Read-only reproduction + DB scan for the CSS leak (0-dirty check) |
| `wp-content/plugins/conexao-event-importer/tests/repair-mi-css-leak.php` | Update-only local record repair for the CSS leak (dry-run by default) |
| `wp-content/plugins/conexao-event-importer/tests/dry-run-motorsport-ireland.php` | Full read-only dry run (temp-activates the local source config) |
| `wp-content/plugins/conexao-event-importer/tests/dry-run-mi-quick.php` | Capped dry run (3 detail pages, fast) for repeat testing |
| `wp-content/plugins/conexao-event-importer/tests/fixtures/mi-listing.html` | Saved live listing snapshot (parser fixture source) |
| `includes/class-event-importer.php` | `motorsport_ireland` handler routing case |
| `includes/class-event-sources.php` | Default **inactive** `motorsport_ireland` source entry |
| `includes/class-event-normalizer.php` | Additive `location_optional` opt-in (see below) |

## Source identity

- `_event_source = motorsport_ireland`
- `_event_source_id = hexadecimal Squarespace UID`, extracted from the
  `id="item-{hex}"` element. On the live listing the UID sits on a descendant
  `div[data-type="item"]` inside `eventlist-description` (not on the
  `<article>` itself) — the parser checks the article id first, then
  descendants. Same UID as the (robots-blocked, unused) ICS feed.

Primary dedup key. Never title-only. Individual event URLs
(`/events/{slug}`) are stored in `_event_url`/`_event_source_url` but are
never the identity.

## Dates — JSON-LD with IST offset is authoritative

`startDate`/`endDate` from the detail-page JSON-LD
(e.g. `2026-09-13T13:00:00+0100`) map into `_event_date`,
`_event_start_time`, `_event_end_date`, `_event_end_time`.

- The **wall-clock event time is preserved verbatim** (`13:00+0100` stays
  `13:00`) — never reinterpreted as UTC. Validated live: JSON-LD
  `13:00+0100` = ICS `12:00Z` = displayed `13:00` (audit §2.2).
- Fallback (only when JSON-LD is unavailable): the card's
  `<time datetime="…">` attribute. Display text is never parsed for dates.
- Missing end date stays empty (start-only event). Malformed dates yield
  empty — never invented.

## Field mapping

| Source field | Event model |
|---|---|
| `eventlist-title` link text | `post_title` |
| Detail `eventitem-column-content` / card `eventlist-description` **visible text only** (Squarespace `.sqs-html-content` blocks; design markup stripped — see [CSS leak guard](#content-extraction-boundary--csspage-builder-leak-guard-2026-09-fix)) | `post_content` + `post_excerpt` (engine-derived) |
| JSON-LD `startDate` / `endDate` | `_event_date` / `_event_start_time`, `_event_end_date` / `_event_end_time` |
| First `eventlist-cats` link (club) | `_event_organizer` |
| Second `eventlist-cats` link (type, e.g. Rally) | `conexao_category` term |
| `/events/{slug}` | `_event_url` + `_event_source_url` |
| `item-{hex}` UID | `_event_source`, `_event_source_id` |
| — | `_event_venue`, `_event_address`, `_event_location`, `_event_price`, `_event_banner`: **always empty** (source publishes none — nothing invented) |

The shared engine owns meta writes (`save_event_meta`), taxonomy assignment,
map-URL backfill and image handling; the adapter produces only the raw array.

## Content extraction boundary — CSS/page-builder leak guard (2026-09 fix)

> This was an **importer data-contamination bug**, not a frontend CSS bug.

### Root cause

Squarespace 6 nests page-builder design markup **inside** the content
containers the parser reads:

- inline `<style id="container-styles">` / `<style id="override-container-styles">`
  blocks carrying the block's design CSS —
  `#block-{hex} { --stroke-style: none;--stroke-thickness: 6px; }`,
  `--tweak-text-block-*`, `@media`, `mix-blend-mode` —
- occasional page-builder `<script>` payload blocks.

Both sit inside `.eventlist-description` (listing card) and
`.eventitem-column-content` (detail page), beside the actual text block
(`.sqs-html-content`). `DOMNode::textContent` includes `<style>`/`<script>`
text verbatim, so `parse_listing_card()` and `parse_detail_description()`
imported raw CSS as the normalized `description` → `post_content` +
`post_excerpt`. Symptom on the public events page:

```
#block-849555a57974f020f868 { --stroke-style: none;--stroke-thickness: 6px; }
```

21 of 59 live listing cards carry such an inline `<style>` block (fixture
verified).

### Content boundary (parser)

`Conexao_Source_Motorsport_Ireland::extract_visible_text()` is the single
extraction point for both containers:

1. `<style>` / `<script>` descendants are removed from a detached clone.
2. The narrowest visible-text containers — **all** Squarespace
   `.sqs-html-content` text blocks, in document order — supply the text.
   This preserves everything the source actually displays, including
   rescheduling notices ("Rescheduled from … to …") and championship lines.
   A description made only of design markup yields `''` (never CSS).
3. Fallback (no text block): remaining visible text of the container.

Never ingested: `<style>`, `<script>`, page CSS, Squarespace configuration
(`data-block-*`), layout wrappers, header/footer chrome.

### Sanitization

`strip_css_leak()` — source-specific (Motorsport Ireland only, never applied
to other sources), text-level safety net behind the DOM removal:

- repeated removal of `#block-{id} … { … }` block-selector rules,
- `@media … { … }` wrappers,
- CSS custom-property declarations (`--prop: value;`),
- orphan `{` / `}` rule braces,
- residual string-level `<style>`/`<script>` tags.

Legitimate plain text (event names, venue/club names, cancellation and
reschedule notices, organizer information) is preserved.

### Regression fixture

`tests/test-motorsport-ireland-importer.php`, section 31 (plus 31b–31e):

- real markup pattern from the live source (text block + container-styles +
  override styles + script payload + reschedule notice) — asserts the
  normalized description contains `National Championship` and the reschedule
  notice and **never** contains `--stroke-style`, `--stroke-thickness`,
  `#block-`, `@media`, `mix-blend-mode`, `<style`, `<script`;
- full sweep of the saved live snapshot `tests/fixtures/mi-listing.html`:
  0 of 59 cards may contain any CSS fragment; the ALMC card
  (`6989d37d36d357787c3539f4`) must normalize to exactly
  `National Championship`;
- detail-page guard, `strip_css_leak()` unit checks, design-only → empty.

### Existing-record repair (local)

`tests/repair-mi-css-leak.php` — update-only, dry-run by default:

```bash
# Dry run (report only):
docker compose exec wordpress php /var/www/html/wp-content/plugins/conexao-event-importer/tests/repair-mi-css-leak.php
# Apply:
docker compose exec wordpress php /var/www/html/wp-content/plugins/conexao-event-importer/tests/repair-mi-css-leak.php --apply
```

Targets only `_event_source = motorsport_ireland` posts whose
content/excerpt contains the leak fragments; re-derives clean text from the
saved fixture matched by stable `_event_source_id`; rewrites **only**
`post_content` + `post_excerpt` (mirroring the importer's own mapping);
never creates/deletes posts, never touches title/dates/category/identity or
other sources. Records without a fixture match are reported and left
unchanged.

Local repair executed 2026-09 (this fix): affected 21 / repaired 21 /
unchanged 0 / errors 0. Post-repair DB scan: 59 events, 0 dirty. Frontend
verified: `/eventos/` and `/eventos/almc-grass-surface-autocross/` render
zero CSS fragments with title/date/category/canonical link intact.

### Production repair (NOT PERFORMED — recommendation only)

Production contains the same 21-record contamination (production events were
exported from this local DB via the event-transfer pipeline). A controlled
production repair must be operator-authorized, update-only and dry-run
first:

1. Ship the fixed importer + repair script, run `--dry-run` on production and
   attach the output (post IDs, source IDs, before/after text) to the change
   record; require explicit operator authorization before any write.
2. Apply `--apply` — by design it can only rewrite `post_content`/
   `post_excerpt` of already-matching motorsport_ireland records; it cannot
   create or delete posts and cannot affect other sources.
3. Re-scan production (0 dirty expected) and spot-check the archive/single
   rendering.

## Multi-day / recurrence

A start/end JSON-LD range stays **one event post** with native
`_event_date`/`_event_end_date` (no post-per-occurrence, no new recurrence
system). The card's `eventlist-event--multiday` class is captured as
`_is_multiday` for diagnostics only. Each source event is standalone.

## Cancellation / rescheduling

- `(CANCELLED)` title suffix → event is **dropped inside `fetch_events()`**
  (IVVCC pattern): logged, never returned to the engine, never imported.
  There is no machine-readable status at the source (audit §2.5).
- `(Rescheduled)` suffix → suffix stripped; the event imports with its
  current (authoritative) JSON-LD date. Obsolete dates are never preserved —
  each run re-reads the live source state.

## TBA / TBC

No date value is ever manufactured. A malformed/absent JSON-LD date yields
empty fields → the shared engine's date filter classifies the event INVALID
and skips it.

## Location — `location_optional` opt-in

The source publishes **no location data** (listing card, detail page and
JSON-LD `location` are all empty — verified live and in audit §2.7). The
adapter therefore sets `location_optional` on its raw events and the
normalizer accepts an empty location for events carrying that flag instead
of raising `Localização não identificada` (which the engine's validation
gate treats as a skip). The unknown state is preserved — venue/town/county
stay empty; no county is inferred from club names; no street address is
invented. The flag is **additive and opt-in**: sources that do not set it
(IVVCC, Heritage Week, …) keep the exact previous skip behavior. Map-link
behavior is unaffected (the shared map-URL backfill skips events without
venue/town).

## Images

No event-specific images exist at the source (audit §2.8): `_event_banner`
stays empty and no media is downloaded. Image availability is never a
requirement for a valid event.

## Deduplication / date window

Entirely the shared engine's: source+UID first, canonical URL second,
content last (never title-only). Date window = shared policy
(`Conexao_Event_Date_Filter`): past events skipped (multi-day uses the end
date), invalid dates skipped, upcoming events imported. The listing page
splits past events into `eventlist-event--past` cards, which the parser
ignores by design — only upcoming events are discovered.

## Crawl policy

- One listing GET + one GET per unique upcoming event detail page
  (`MAX_DETAIL_PAGES = 60`, 2 s between detail requests).
- `crawl_delay` / `max_detail_pages` source-config overrides exist for
  controlled testing.
- Browser User-Agent only; no ICS (`robots.txt`-blocked), no club-site
  crawling, no bypass of access restrictions.

## Dry run

```bash
# Full (≈2–4 min: 59 detail pages × 2 s crawl delay):
docker compose exec wordpress php \
  /var/www/html/wp-content/plugins/conexao-event-importer/tests/dry-run-motorsport-ireland.php

# Fast (capped to 3 detail pages):
docker compose exec wordpress php \
  /var/www/html/wp-content/plugins/conexao-event-importer/tests/dry-run-mi-quick.php
```

Both temp-activate the local source config, run the shared engine with
`dry_run = true` (fetch → normalize → date filter → dedupe → report), then
restore the previous status. Zero writes: no posts, no media, no taxonomy
changes, no options beyond the temp status flip.

## Activation requirements / Stage C production-import gate

1. The source ships **inactive** (`status => 'inactive'`) and is never run
   automatically (no cron, no REST/AJAX trigger — the importer is
   local-only tooling).
2. Stage C production import requires explicit operator confirmation before production writes. This is an internal deployment safety gate and is not a representation that Motorsport Ireland requires permission to reference or link to its public event pages. No Motorsport Ireland website requirement for operator authorization before this internal production import was identified during the project audit. This Stage C confirmation is an internal production-safety control, not a claim that Motorsport Ireland granted or requires permission.
3. Note: motorsport events carry no county/town, so they will not appear in
   county-filtered archive views; cards still show title, date, club and a link to the source event page.
