# Motorsport Ireland Importer — Stage B Report

**Date:** 2026-09-10
**Stage:** B — importer implementation, tests, documentation, read-only dry run
**Authority:** [motorsport-ireland-audit.md](motorsport-ireland-audit.md) (Stage A)
**Scope:** Production event creation/update is OUT OF SCOPE (Stage C gate).

---

## Implementation

### Files created

| File | Why |
|---|---|
| `wp-content/plugins/conexao-event-importer/includes/sources/class-motorsport-ireland-source.php` | `Conexao_Source_Motorsport_Ireland` — Motorsport-ireland-specific acquisition/parsing only (listing scrape, UID extraction, JSON-LD IST dates, club/category extraction, cancellation/reschedule suffix rules). Follows the `Conexao_Source_Ivvcc` structural pattern (static fixture-safe parsers, per-event try/catch, bounded detail enrichment). |
| `wp-content/plugins/conexao-event-importer/tests/test-motorsport-ireland-importer.php` | 60 focused assertions: parser, UID, identity, IST dates, cancellation/reschedule, malformed isolation, DB dedup, full second-run idempotency. |
| `wp-content/plugins/conexao-event-importer/tests/dry-run-motorsport-ireland.php` | Full read-only dry run (temp-activates local source config, restores status). |
| `wp-content/plugins/conexao-event-importer/tests/dry-run-mi-quick.php` | Capped dry run (3 detail pages) for fast repeat runs. |
| `wp-content/plugins/conexao-event-importer/tests/live-motorsport-ireland-parse.php` | Read-only live parse with representative normalized samples. |
| `wp-content/plugins/conexao-event-importer/tests/fixtures/mi-listing.html` | Saved live listing snapshot (parser development fixture). |
| `docs/importers/motorsport-ireland.md` | Importer documentation (this stage). |

### Files modified

| File | Change |
|---|---|
| `conexao-event-importer.php` | `require_once` for the new source class. |
| `includes/class-event-importer.php` | One `case 'motorsport_ireland'` in `get_source_handler()` routing. No other engine changes. |
| `includes/class-event-sources.php` | Default source entry, **`status => 'inactive'`** (IVVCC precedent). |
| `includes/class-event-normalizer.php` | **Additive opt-in:** raw key `location_optional` suppresses the `Localização não identificada` validation error for sources that genuinely publish no location data. Sources that do not set the flag keep byte-identical behavior (all existing sources are unaffected — regression suite green). Motivation documented inline. |

No other importer, plugin, theme or infrastructure file was touched. No cron,
REST/AJAX endpoints, admin automation, external services or database tables
were added.

---

## Source behavior validated (live)

Verified against `https://www.motorsportireland.com/events` on 2026-09-10
(read-only HTTP GET):

- Single-page master calendar, **59 upcoming cards**
  (`article.eventlist-event--upcoming`); past events
  (`eventlist-event--past`) are excluded by design. No pagination/AJAX.
- **Stable hex UID present** — matches the audit (`item-{hex}`). Live
  correction vs audit: the UID lives on a descendant
  `div[data-type="item"]` inside `eventlist-description`, **not** on the
  `<article>` element itself; the parser checks both.
- **JSON-LD dates authoritative and IST-correct** — e.g. Wexford Stages
  Rally: `2026-09-12T12:30/13:30+01:00` → stored wall-clock `12:30`/`13:30`
  (never reinterpreted as UTC). Multi-day ranges parse to native
  start/end. Malformed dates yield empty (never invented).
- **JSON-LD `location` is an empty `Place`** (`name:""`, `address:""`) —
  confirms audit §2.7: the source publishes no location data at all.
- **Club/type categories:** 51 cards carry two `eventlist-cats` links
  (club + discipline); 3 carry a single link whose value is always a
  discipline (`Rally`, `Karting`); 5 carry none.
- **Cancellation/rescheduling:** no `(CANCELLED)`/`(Rescheduled)` cards in
  the current live upcoming list (0 dropped). Behavior is covered by
  fixture tests (suffix skip / suffix-strip) since the live edge case is
  not currently present.
- No event-specific images anywhere (audit §2.8). ICS is robots.txt-blocked
  and never requested.

---

## Mapping (final)

| Source | Event model | Notes |
|---|---|---|
| `eventlist-title` link | `post_title` | |
| Detail JSON-LD description | `post_content` | "Posted In:" meta junk rejected; usually empty |
| JSON-LD `startDate` | `_event_date` + `_event_start_time` | wall-clock IST preserved |
| JSON-LD `endDate` | `_event_end_date` + `_event_end_time` | empty when absent |
| `eventlist-cats` link 1 (two-link cards) | `_event_organizer` | affiliated club |
| `eventlist-cats` link 2 (two-link cards) | `conexao_category` term | discipline |
| `eventlist-cats` single link | `conexao_category` term | a club never appears alone live — never mis-attributed as organizer |
| `/events/{slug}` | `_event_url` + `_event_source_url` | secondary identity |
| `item-{hex}` | `_event_source`/`_event_source_id` | primary identity |
| — | `_event_venue`, `_event_address`, `_event_location`, `_event_price`, `_event_banner`, county/town | always empty — source publishes none; nothing invented |

Recurrence: none at source; start/end ranges stay one post with native
dates (`_event_date`/`_event_end_date`). No new recurrence system.

Cancellation/rescheduling: `(CANCELLED)` → dropped inside `fetch_events()`
(IVVCC pattern), never imported; `(Rescheduled)` → suffix stripped, event
imports with the current authoritative JSON-LD date.

## Identity

- **Primary:** `source = motorsport_ireland` + `source_id = {hex UID}`
  (deduplicator's strongest match — tested against the DB).
- **Secondary:** canonical `/events/{slug}` URL.
- Title-only differences never create separate source events (same UID =
  same identity); different UIDs with the same title stay separate events.
- The deduplicator requires `_event_source` AND `_event_source_id` to match
  for the primary key, so an unrelated source reusing a UID value cannot
  collide (tested).

## Tests

**Focused Motorsport Ireland suite: 60 passed, 0 failed**
(`tests/test-motorsport-ireland-importer.php`) covering: hex UID extraction
(incl. descendant-element live variant), title/URL extraction, club/category
extraction (two-link + single-link), HTML datetime fallback, multi-day
detection, cancellation skip, reschedule suffix-strip, JSON-LD IST parsing
(+0100 / +01:00 / Z / date-only), UTC non-reinterpretation, missing end,
malformed dates, invalid-UID rejection, identity (same UID / title change /
different UID), duplicate calendar cards, malformed-card isolation, DB dedup
(primary key, URL key, non-collapse, cross-source non-collision),
manual-field preservation, **full second-run idempotency** (create →
same-record → unchanged; changed date → update, not duplicate),
deterministic re-parse, URL resolution, no invented location/image/price.

## Regression

Existing suites, all green after the changes:

| Suite | Result |
|---|---|
| IVVCC importer | 46 passed, 0 failed |
| Event address | 76 passed, 0 failed |
| Past-event filter | 29 passed, 0 failed |
| Error handling | 55 passed, 0 failed |
| Import log | 53 passed, 0 failed |
| Eventbrite importer | 68 passed, 0 failed |
| Event image sync | 30 passed, 0 failed |
| **Total** | **357 passed, 0 failed** |

`php -l` clean on all created/modified PHP files.


## Dry run (live, read-only)

`tests/dry-run-motorsport-ireland.php` — full run, all 59 detail pages,
2 s crawl delay, `dry_run = true`:

| Metric | Count |
|---|---|
| raw / discovered (upcoming cards) | 59 |
| unique (hex UIDs) | 59 |
| parsed + normalized | 59 |
| skipped as past | 0 |
| skipped as invalid | 0 |
| skipped as TBA/TBC | 0 |
| would-create | **59** |
| would-update | 0 |
| unchanged | 0 |
| errors | 0 |

Run status: `success`; source status restored to `inactive` afterwards.
Representative samples (from `tests/live-motorsport-ireland-parse.php`):

```
UID 6989d2ba54428f7993da4e3e | Wexford Motor Club Wexford Stages Rally | 2026-09-12 12:30 -> 2026-09-13 13:30 | club=Wexford Motor Club | cat=Rally
UID 6989d33100b2e963a23e09e9 | Mondello Park ICCR Race Meeting | 2026-09-12 12:30 -> 2026-09-13 13:30 | club=Mondello Park Sports Club | cat=Circuit Racing
UID 6989d37d36d357787c3539f4 | ALMC Grass Surface Autocross | 2026-09-13 13:00 -> 2026-09-13 14:00 | club=ALMC Motor Club | cat=Autocross
UID 6989d3e74317a939c8c217ac | Omagh MC Bushwhacker Forestry Rally | 2026-09-19 | club=(none) | cat=Rally
```

Covers ordinary future events, multi-day events (Wexford, Mondello), club
attribution, and incomplete-location events (venue empty on all).
Cancellation/rescheduling is not present in the current live upcoming list
and is covered by fixture tests instead — no synthetic production data.

Idempotency: the dry run performs **zero writes**, so a second dry run
re-derives identical results (deterministic identity proven by the fixture
determinism test + two identical full runs: 59/59/0/0 both times). The
no-duplicate proof on real records is the DB idempotency test (section 29):
write-once → second run resolves to the same post as UNCHANGED; changed
field → same post via the update path. First production run `create > 0`,
second run `create = 0` follows from the shared engine's
unchanged/update/dedup logic (covered by the existing suites and the
focused DB tests).

## Production safety

- **No production posts created/updated/deleted** — `WP_Query` for
  `_event_source = motorsport_ireland` returns **0**; published event count
  unchanged at **90** (baseline).
- **No production media created** — attachment count unchanged at **427**.
- **No unrelated data changed** — source option `events_imported = 0`,
  status `inactive`; no taxonomy terms created; the only option touched was
  the dry run's temp status flip (restored); no cron/REST/AJAX added; no
  database tables created.
- The importer remains **inactive from automatic production execution**
  (local-only tooling; source ships `status => 'inactive'`).

## Known limitations

1. **No location data at source.** Events import without county/town/venue
   and will not appear in county-filtered archive views (cards still show
   title, date, club, source link). The `location_optional` normalizer
   opt-in was required because the shared validation gate would otherwise
   skip every event of this source.
2. **Cancellation detection is title-suffix based.** The source exposes no
   machine-readable status (`eventlist-datetag-status` always empty; JSON-LD
   has no `eventStatus`); a cancellation phrased differently than a
   `(CANCELLED)` suffix would not be detected.
3. **Single-link category cards** are classified as disciplines (observed
   live: the sole link is always a discipline). A future card whose only
   category link is a club name would be classified as a category, not an
   organizer — positional data cannot disambiguate.
4. **Detail-page structure is Squarespace-version dependent**; a major
   platform change may require selector revalidation (UID, title, cats,
   JSON-LD).
5. Club attribution exists for 53/59 cards: 5 cards carry no categories at
   all and 3 carry a discipline only.

## Stage C handoff

Before any production import (explicit production-import authorization
required — Stage C gate):

1. Obtain production-import authorization (owner decision; this report does
   not grant it).
2. Decide the editorial position on location-less events (they cannot be
   county-filtered; consider a default presentation note for the
   Brazilian-community audience).
3. On the local importer environment: activate the source
   (*Event Import → Event Sources*), run the full import, verify 59 events,
   then transfer via the existing local→production event export pipeline
   (JSON + embedded images) — no new transfer mechanism.
4. Re-run the dry run immediately before production to reconfirm counts.
5. Post-import audits: dedup re-run (`create = 0`), archive rendering,
   county-filter impact, `_event_status` gate behavior.
6. Keep the source **inactive** on production (the importer plugin itself is
   never deployed to production).

---

**MOTORSPORT IRELAND STAGE B PASSED**

