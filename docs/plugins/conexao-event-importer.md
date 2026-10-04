# Conexão BR Irlanda — Event Importer (Local Tools)

<!-- BEGIN GENERATED PLUGIN REGISTRY: plugin lifecycle metadata -->
| | |
|---|---|
| **Status** | active |
| **Class** | tooling |
| **Production** | no |
| **Build** | yes |
| **Compose mount** | yes |
| **Dependencies** | `conexao-data-model`, `conexao-event-runtime` |
| **Version** | 1.9.0 (authoritative source: `wp-content/plugins/conexao-event-importer/conexao-event-importer.php` header) |
| **Registry** | [`plugins.json`](../../plugins.json) |

> **Local-only tooling.** Not a production steady-state dependency.
<!-- END GENERATED PLUGIN REGISTRY: plugin lifecycle metadata -->

- **Path**: `wp-content/plugins/conexao-event-importer/`
- **Version**: 1.7.1
- **Requires Plugins**: `conexao-data-model`, `conexao-event-runtime`
- **Purpose**: Local-only event import/export tooling. Fetches events from external sources (Laois Tourism, National Heritage Week, Eventbrite multi-county) into the **local** WordPress installation, downloads all images into the local Media Library, then exports the complete event data as JSON for import into the production WordPress.com site.
- **Rollout tooling — keep available on production.** All production-critical event behavior (meta/taxonomy registration, `_event_status` public query gate, status admin UI) lives in the separate [Event Runtime](conexao-event-runtime.md) plugin, so this plugin is **not required for runtime event rendering**. However, during the multi-county Events Expansion it is part of the rollout workflow and **must not be deactivated, removed, replaced, or treated as unnecessary production code**. It stays **dormant unless explicitly invoked**: there is no cron, polling, background sync, or frontend fetching, and production never contacts external sources.

## Runtime / tooling split (v1.5.0)

As of v1.5.0 this plugin contains **only local tooling**. The following moved
to the Event Runtime plugin:

| Moved item | Where it lives now |
|---|---|
| Event meta registration (`_event_*`, incl. `_event_status`) | `Conexao_Event_Runtime::register_meta()` |
| `conexao_town` taxonomy registration | `Conexao_Event_Runtime::register_town_taxonomy()` |
| `_event_status` public query gate (`pre_get_posts`) | `Conexao_Event_Runtime::filter_public_event_queries()` |
| Event status/source admin columns + `?event_status=` list filter | `Conexao_Event_Runtime` |
| `conexao_event_status_box` meta box + save handler | `Conexao_Event_Runtime` |
| `Conexao_Event_Status` class (statuses, get/set, expiry, end-timestamp helper) | `conexao-event-runtime/includes/class-event-status.php` |

The tooling still *uses* `Conexao_Event_Status` (post-import expiry marking,
cleanup end-timestamps) — the dependency direction is strictly
**Importer Tools → Runtime**, enforced by the `Requires Plugins` header and a
boot guard that refuses to load tooling without the runtime.

### Expiry execution path (no cron)

`Conexao_Event_Status::mark_expired_events()` is **not** scheduled. There is no
cron event, no daily/weekly WP-Cron schedule, and no REST trigger. It runs
synchronously at the end of every import run
(`Conexao_Event_Importer_Engine::run_all()` calls it after all sources have
been processed). Practical consequence: events whose end date/time has passed
stop appearing on public pages the next time an import is run — not on a
timer. If no import is ever run, published events stay visible until the
manual Cleanup removes them.

## Architecture: Local is the importer, production is only the destination

External event sources block requests coming from the production WordPress.com
site (and its images CDN blocks hotlinking). Therefore **the production site
never contacts external sources**. All fetching happens locally:

```
LOCAL (Docker WordPress):
  External sources → Importer → Event posts + Media Library images → Export JSON

PRODUCTION (WordPress.com):
  Import exported JSON → Event posts + Media Library attachments
```

There is no cron, no scheduled importing, no REST trigger, and no automatic
production-side fetching. Everything is manual/on-demand.

## Responsibilities

- Manage event source configurations (add, edit, toggle, delete)
- Fetch events from external sources (iCalendar, HTML scraping, Eventbrite discovery/API) — **local only**
- Normalize raw event data into structured WP post data
- Filter out events whose date/time has already passed — past events are never created or updated by an import run
- Deduplicate events by source + source_id (primary), URL, or content match — re-running never creates duplicates
- Create/update event posts with full meta, taxonomies, and featured images
- Download external event images into the local Media Library during import
- Export/import events as portable JSON with stable UUIDs and **embedded base64 image data**
- Log all import activity with download/clear capabilities (2,000-entry cap,
  90-day retention — see [Import logging](#import-logging))
- Manual cleanup of expired events and their exclusively-owned images
- Auto-expire published events once their end date/time passes — expiry
  marking runs at the end of each import run (no cron; see
  [Expiry execution path](#expiry-execution-path-no-cron) above)

## Import logging

`Conexao_Import_Log` stores structured entries in the
`conexao_event_import_log` option (autoload off):

- **Cap**: 2,000 entries (newest first); older entries are dropped
  automatically once the cap is reached.
- **Retention**: entries older than 90 days are pruned opportunistically when
  the Import Logs admin screen is opened.
- **Write behavior**: during an import run the engine wraps the run in
  `Conexao_Import_Log::begin_run()` / `end_run()`. Entries are buffered in
  memory and persisted with a small number of bounded read-merge writes
  (one write per ~250 buffered entries, plus one final flush) instead of
  rewriting the whole option for every log line. Calls made outside a managed
  run (e.g. the Cleanup tool) persist immediately as before.
- **Concurrency**: each flush re-reads the stored log before writing and only
  prepends its own entries, so a concurrent manual run's persisted entries are
  not overwritten.
- **Sensitive data**: keys matching token/password/api_key/authorization (and
  similar configured keys) are stripped from context before anything is
  persisted.

## Key Components

| File | Class | Purpose |
|------|-------|---------|
| `conexao-event-importer.php` | `Conexao_Event_Importer` | Main plugin, boot all components (local tooling only) |
| `includes/class-event-sources.php` | `Conexao_Event_Sources` | Source CRUD, admin pages, manual import trigger |
| `includes/class-event-normalizer.php` | `Conexao_Event_Normalizer` | Normalize raw event data |
| `includes/class-event-date-filter.php` | `Conexao_Event_Date_Filter` | Past-event filter: compare normalized dates against site-timezone now |
| `includes/class-event-location.php` | `Conexao_Event_Location` | Location normalization (county, town, venue) |
| `includes/class-event-deduplicator.php` | `Conexao_Event_Deduplicator` | Find existing events by source_id, URL, or content |
| `includes/class-event-importer.php` | `Conexao_Event_Importer_Engine` | Main import engine, upsert logic, dry-run support |
| `includes/class-event-image-handler.php` | `Conexao_Event_Image_Handler` | Download/sideload external images + create attachments from embedded base64 data |
| `includes/class-import-settings.php` | `Conexao_Import_Settings` | Settings storage (`cleanup_scope`) + admin page. **No Eventbrite API token** — the dead token setting was retired in G7 |
| `includes/class-source-health.php` | `Conexao_Source_Health` | Informational per-source failure tracking (never auto-disables) |
| `includes/class-source-fetch-exception.php` | `Conexao_Source_Fetch_Exception` | Structured transport/HTTP fetch failure |
| `includes/class-import-cli.php` | `Conexao_Import_CLI` | WP-CLI commands (`wp conexao-events …`) |
| `includes/class-import-result.php` | `Conexao_Import_Result` | Structured import result tracking |
| `includes/class-import-log.php` | `Conexao_Import_Log` | Persistent log storage (run-buffered writes, 2,000-entry cap, 90-day retention) |
| `includes/class-import-log-admin.php` | `Conexao_Import_Log_Admin` | Log admin UI, download, clear |
| `includes/class-import-history.php` | `Conexao_Import_History` | Import run history tracking |
| `includes/class-event-export.php` | `Conexao_Event_Export` | JSON export with UUIDs + embedded base64 images |
| `includes/class-event-import.php` | `Conexao_Event_Import` | JSON import with dedupe; creates attachments from embedded data |
| `includes/class-event-cleanup.php` | `Conexao_Event_Cleanup` | Manual cleanup of expired events + exclusively-owned images |
| `includes/class-event-transfer-admin.php` | `Conexao_Event_Transfer_Admin` | Export/import admin pages |
| `includes/class-event-image-sync-admin.php` | `Conexao_Event_Image_Sync_Admin` | Bulk sideload of any remaining external banner images |
| `includes/class-eventbrite-client.php` | `Conexao_Eventbrite_Client` | HTTP client for Eventbrite pages (retry/backoff) |
| `includes/class-eventbrite-parser.php` | `Conexao_Eventbrite_Parser` | HTML parser for Eventbrite embedded data |
| `includes/class-eventbrite-normalizer.php` | `Conexao_Eventbrite_Normalizer` | Normalize Eventbrite data format |

### Sources (in `includes/sources/`)

| File | Class | Type |
|------|-------|------|
| `abstract-class-source.php` | `Conexao_Source_Base` | Base class; browser-like User-Agent (Cloudflare-safe), `fetch_html()` throws on failure, `fetch_html_or_empty()` for tolerant pagination |
| `class-icalendar-source.php` | `Conexao_Source_ICalendar` | iCalendar/Webcal feeds |
| `class-laois-tourism-source.php` | `Conexao_Source_Laois_Tourism` | HTML scraping |
| `class-heritage-week-source.php` | `Conexao_Source_Heritage_Week` | HTML scraping (paginated + detail enrichment) |
| `class-county-registry.php` | `Conexao_County_Registry` | Authoritative 26-county registry (EB slugs, region labels, HW `where[]`) |
| `class-ivvcc-source.php` | `Conexao_Source_Ivvcc` | IVVCC EventON calendar scraping + detail enrichment (ships inactive; see [importers/ivvcc-importer.md](../importers/ivvcc-importer.md)) |
| `class-motorsport-ireland-source.php` | `Conexao_Source_Motorsport_Ireland` | Motorsport Ireland Squarespace master-calendar scraping + JSON-LD date enrichment (ships inactive; see [importers/motorsport-ireland.md](../importers/motorsport-ireland.md)) |
| `class-eventbrite-source.php` | `Conexao_Source_Eventbrite` | Eventbrite: official v3 API (when token set) or discovery-page scraping |

## Default Sources (seeded on activation)

On activation, the plugin seeds these default sources:

| Source ID | Name | Type | Status |
|---|---|---|---|
| `laois_tourism` | Laois Tourism | `icalendar` | active |
| `heritage_week` | National Heritage Week (Laois) | `website` | active |
| `eventbrite` | Eventbrite — Laois | `eventbrite` | active |
| `ivvcc` | IVVCC | `website` | inactive |
| `motorsport_ireland` | Motorsport Ireland | `website` | inactive |
| `mondello_park` | Mondello Park | `website` | inactive |

**Full registry size: 58 sources** — these 6 legacy defaults plus the 52 county
sources below. Both halves are declared in code (no operator `wp eval` needed).

### County Source Registry (v1.7.0)

The multi-county expansion introduces 52 additional source registrations
(26 Eventbrite + 26 Heritage Week) for the 26 Republic of Ireland counties.
Together with the six legacy defaults above this is a **58-source registry**.
All 52 ship **inactive**: registering a source never starts an import.

**Source key scheme:**
- Eventbrite: `eventbrite_<county-slug>` (e.g. `eventbrite_cork`)
- Heritage Week: `heritage_week_<county-slug>` (e.g. `heritage_week_dublin`)

**Authoritative registry:** `Conexao_County_Registry` (`includes/class-county-registry.php`)
is the single source of truth. It defines, per county: display name, slug,
jurisdiction, Eventbrite URL slug, Eventbrite accepted region labels, and
Heritage Week `where[]` filter values.

**Region-label policy:** An Eventbrite event is accepted only when its region
matches one of the configured `region_labels`. Unknown regions are REJECTED +
LOGGED (never silently accepted).

**Heritage Week `where[]` policy:** Multiple `where[]` values are walked within
a single source registration (relevant for Galway, Dublin). The 2026 form values
are audited in `docs/importers/events-expansion-stage-a-audit.md`.

### Eventbrite date-window sources (G8)

The county `all-events` listing **truncates its own pagination**: `page_count`
is pinned at exactly 49 for every county regardless of volume, while
`object_count` runs from 1,547 (Cork) to 5,722 (Cavan). A large share of a
county's events is therefore not reachable through it at all.

Eventbrite's **date-window** listings (`events-today`, `events-tomorrow`,
`events-this-week`) slice the same county population by time. Each slice is far
smaller and declares a page count it can actually serve, so it can be walked to
genuine completion.

Measured on Cork, 2026-10-04:

| Listing | declared pages | walked | unique events | outcome |
|---|---:|---:|---:|---|
| `all-events` | 49 (capped) | 31, then HTTP 429 | 618 | incomplete |
| `events-this-week` | 16 | 16 | 304 | **complete, 0 errors** |
| `events-today` | 2 | 2 | 23 | complete |
| `events-tomorrow` | 2 | 2 | 23 | complete |
| **window union** | — | 32 requests | 483 | **198 events the capped listing never reaches** |

**These are configuration, not new architecture.** A date-window source has
`type = eventbrite` and is handled by `Conexao_Source_Eventbrite` — the same
adapter as the county listing, with a different discovery URL. Source IDs are
`eventbrite_<county>_events_<window>` (e.g. `eventbrite_cork_events_this_week`).

Every date-window source **ships `inactive`**, exactly like every other registry
source. Enable one explicitly in Event Import → Event Sources.

`events-this-month` is deliberately **not** registered: it measured 48 declared
pages against the same 49-page cap, so it would cost requests without adding
reachable coverage.

Identity and deduplication are unchanged and deterministic:

- identity is the **native Eventbrite event id** (`_event_source_id`), never an
  array index, page number or title;
- the same event reached through two sources carries the same canonical
  Eventbrite URL, and `Conexao_Event_Deduplicator::find_by_url()` matches on it,
  so one WordPress event is created — no fuzzy matching was introduced;
- the county region gate is **unchanged**: eligibility comes from each event's
  own `locations[].address.region`. The window URL confers nothing, so an event
  outside the county is rejected even when a county window URL served it.

**Seeding:** the county registry is **self-healing**. `Conexao_Event_Sources::get_all()`
merges any missing county source on every read, so the complete county registry
(Eventbrite + Heritage Week + Eventbrite date-window sources) is correct on every
environment — a fresh activation, the local Docker stack, a CI fixture build and a
restored database alike. `seed_county_sources()` remains available as an explicit,
idempotent operator step, but it is **no longer required** to obtain the county
coverage. The registry count is derived from `Conexao_County_Registry`, never
hard-coded in a test.

This self-heal is deliberate and narrow:
- it **only inserts** source ids that do not exist, so an operator's activated,
  edited or re-pointed source is never reset;
- every county source ships `inactive`, so repairing the registry can never
  start an import, fetch a provider or write an Event post;
- it never resurrects a retired source (see Retired sources below);
- it is idempotent — repeated reads converge to the same registry.

**Retired sources:** `laois_council`, `leo_laois` and `local_enterprise_office_laois`
were deliberately retired (commit `042259d`, which deleted
`class-laois-council-source.php` and `class-leo-laois-source.php`). `get_all()`
actively strips these ids. They must never be restored; the events they
previously imported are preserved.

**Eventbrite pagination cap:** `page_count` is capped at 49 (~980 events).
Large counties (Dublin, Cork) may report much higher `object_count` values.
The deep tail is unreachable via the public page. This is a documented
provider limitation, not a bug.

**Eventbrite API:** the v3 Events Search API path (`/v3/events/search/`) was
removed in Stage B (verified 404) and re-verified in G6. The HTML discovery path
is the only supported mechanism, and **it requires no Eventbrite credential**.
The `eventbrite_api_token` setting that this file previously documented as an
API mode selector was dead — no code ever read it — and was retired in G7. See
[Eventbrite sources](#eventbrite-sources) above.

**Venue normalization fix:** The normalizer reads `primary_venue` (current
payload shape) with `venue` as a legacy fallback. This fixes the Stage A
regression where venue name/address were silently empty.

**Dry-run workflow:**
```bash
# Seed county sources (idempotent)
wp eval 'require "wp-content/plugins/conexao-event-importer/includes/class-event-sources.php"; (new Conexao_Event_Sources())->seed_county_sources();'

# Dry-run a single county source
wp conexao-events import --source=eventbrite_cork --dry-run
wp conexao-events import --source=heritage_week_dublin --dry-run

# Live probe (read-only verification)
php wp-content/plugins/conexao-event-importer/tests/probe-county-sources.php

# Full dry-run (all 52 sources)
php wp-content/plugins/conexao-event-importer/tests/dry-run-county-sources.php
```

**Local-only restriction:** All county sources must remain local-only.
Eventbrite discovery blocks datacenter IPs (405). No production-side fetching.
## Default Sources (seeded on activation)

| ID | Name | Type |
|----|------|------|
| `laois_tourism` | Laois Tourism | iCalendar (webcal) |
| `heritage_week` | National Heritage Week | Website |
| `eventbrite` | Eventbrite — Laois | Eventbrite |
| `ivvcc` | IVVCC — Irish Veteran & Vintage Car Club | Website (inactive) |
| `motorsport_ireland` | Motorsport Ireland | Website (inactive) |

### Cloudflare / bot protection

Some sources (e.g. `laoistourism.ie`) sit behind Cloudflare and return **403 to generic user agents**. All source HTTP requests therefore use a real-browser Chrome UA by default (overridable via the `conexao_event_importer_http_user_agent` filter). This works fine from a residential/local connection.

### Eventbrite sources

**One supported ingestion path: HTML scraping of the public county discovery
page.** There is no API mode and **no Eventbrite credential of any kind** — no
API token, no OAuth token, no `Authorization` header. Nothing needs to be
configured in wp-admin to import an Eventbrite source.

The handler parses the public discovery page's `window.__SERVER_DATA__`, walks
pagination, and filters by the configured county region labels. It works from
residential/local connections; datacenter hosts can be blocked by Eventbrite
(405), which is why Eventbrite sources are **local-only**.

**Why there is no API mode** — the official Eventbrite API cannot replace or
augment this path:

| Fact | Consequence |
|---|---|
| `/v3/events/search/` returns **404** (retired by Eventbrite) | no location-scoped event search exists |
| The only event collection is `/organizations/{id}/events/` | a county listing cannot be addressed |
| Organization discovery is **per-user** (`/users/me/` → own orgs) | you can only list events you **own** |
| A county aggregates independent organizers — measured: Cork **36**, Dublin **74**, Carlow **51 in 60** | no token can enumerate these sources |

G6 measured this and returned **`NOT VIABLE`**; G7 then retired the dead
`eventbrite_api_token` plumbing (a stored, never-read token) rather than leaving
it as a false prerequisite. Full evidence:
[`docs/reports/2026-10-04-eventbrite-api-feasibility.md`](../reports/2026-10-04-eventbrite-api-feasibility.md)
and [`docs/reports/2026-10-04-eventbrite-api-retirement.md`](../reports/2026-10-04-eventbrite-api-retirement.md).

**Reopening the question** is legitimate if an external fact materially changes
— e.g. Eventbrite restores a location-scoped/public discovery endpoint, or a
partnership or access model covers third-party events. If that happens, repeat
the cheap ownership/discovery measurement first. Do not add a credential before
then.

### iCalendar sources

- Accept `webcal://` or `https://` feed URLs; an uploaded `.ics` file overrides the URL.
- Events are deduplicated by the feed's native `UID`.
- `CATEGORIES` maps to the event category (first entry); per-source `county`/`category` hints apply when the feed omits them.

### The deterministic CI fixture (Stage 21)

`scripts/data/ci-fixture-laois-ics.php` emits a **real** iCalendar document, and
`scripts/bootstrap-ci-fixtures.php` **step 4c** imports it with the production
`Conexao_Event_Importer_Engine` under the production `laois_tourism` source id,
through the source's `ics_content` — the same slot an operator's ICS upload
fills. There is **no fixture-only import or recurrence path**: the parser, the
source-scoped `Conexao_Laois_Tourism_Series` adapter, the generic `RRULE` reader
and the recurrence meta writer are all the production ones, so the CI site is
exercised along the same code path production uses.

The document carries three deliberately different shapes so the whole occurrence
contract is covered:

| Shape | Encoding | Expected outcome |
|---|---|---|
| collapsed weekly series | timed VEVENT, whole-week span, same weekday on both ends, **no `RRULE`** | four weekly occurrences, stored as `_event_recurrence = weekly` |
| genuine multi-day | timed VEVENT, day span **not** a multiple of 7, weekdays differ | stays one-time, keeps `_event_end_date` |
| all-day range | `DTSTART;VALUE=DATE` … `DTEND;VALUE=DATE` | stays one-time, no clock time |

Two deliberate constraints:

- **Dates are relative** to one captured site-local "today", for the same reason
  `scripts/data/ci-fixture-events.php` uses relative dates: the event runtime
  lists only a `today .. today+7` window, so a fixed calendar date would
  silently empty `/eventos/` once it expired.
- **No `ATTACH` line**, or the importer would try to sideload a remote banner and
  an offline CI job would depend on the network. The `UID`s are date-independent,
  so a later run upserts the same three records instead of creating new ones.

`bootstrap-ci-fixtures.php --apply --verify` then asserts the contract
numerically, evaluating **every** calendar day of the series with
`Conexao_Event_Recurrence` and failing closed on a single false match — the
per-date half of the contract that the HTTP acceptance layer cannot observe,
because the public archive accepts no caller-supplied date.

### The per-source `county` hint (Stage 7.x)

A source's `county` field is **not decoration**. It is the only thing that gives
an imported event a `conexao_county` term, and the `/eventos` archive filters on
exactly that term (`?county=<slug>`). An empty hint therefore makes every event
from that source permanently invisible to the county filter.

Two rules protect it:

1. **`get_all()` self-heals a drifted hint.** When a stored source's `county`
   is empty but the source **ships** a default county, the default is restored
   and persisted. A *non-empty* stored value is never overwritten, so a
   deliberate operator override always wins. The drift was reachable through
   wp-admin: the "Add Source" form renders the County input empty (placeholder
   only), and `handle_save_source()` persists whatever is submitted — so
   re-adding a shipped source without retyping the county silently wiped it.
2. **The `"unchanged"` fast path reconciles taxonomies.** An event whose
   payload is unchanged returns early from `upsert_event()`. That path now calls
   `save_event_taxonomies()` too, so an event imported while the hint was empty
   acquires its county term on the next run instead of being stuck forever.

Events imported before the hint existed are repaired once by
`scripts/repair-event-county-terms.php` (dry-run by default). It assigns the
**existing** shared `conexao_county` term and never creates one — counties are
shared geography, so there is one physical term per county and no per-language
term (see the taxonomy policy in `AGENTS.md`).

## Workflow (manual, on-demand)

1. On the **local** Docker WordPress, open **Event Import → Dashboard** and click **Import Events Now** (or run `wp conexao-events import`). Each source can also be imported individually from Event Sources.
2. The importer fetches each active source, normalizes events, filters out past/invalid-date events (see [Past-event filtering](#past-event-filtering-v14)), validates required fields, deduplicates against existing posts, and upserts. Valid events are published immediately — there is no review/approval step. Images are downloaded into the local Media Library during import (`_event_banner_attachment_id` + post thumbnail).
3. Open **Event Import → Export Events** and download the JSON file. The export embeds each event's featured image as base64 data (up to 5 MB per image).
4. On **production**, upload the JSON. Attachments are recreated from the embedded bytes — no external requests are made. Since v1.5.0 the transfer/import screens are local tooling, so this step requires the Event Importer plugin to be **temporarily active on production** (Event Runtime must be active too — WordPress enforces this via `Requires Plugins`). Deactivate the importer again afterwards; this is safe and does not affect public event behavior. The package format is unchanged.
5. Optionally run **Cleanup** locally before exporting to remove past events.

### Required-field validation

Events missing required data (title, start date, source URL, or any location
identification) are **skipped** by the importer — they are never created as
posts and existing posts are left untouched. Skip reasons appear in Import
Logs and per-event results (`Skipped: missing required data (…)`).

### Upload size limits

Because featured images are embedded as base64, export JSON files are often
10–20 MB. The **Import Events** screen therefore:

- displays the effective maximum upload size (`min(upload_max_filesize, post_max_size)`),
- validates the selected file client-side before submitting,
- redirects back with a clear error notice when PHP rejects an oversized POST
  (`post_max_size`) or the file exceeds `upload_max_filesize`.

Locally, `docker/php/uploads.ini` raises both limits to 300M (see
`docs/development.md`). On production (WordPress.com) the limits are
platform-managed; if an export is too large, run **Cleanup** locally first or
split the export.

### Past-event filtering (v1.4)

Every import run evaluates each fetched event's scheduling fields
(`start_date`, `start_time`, `end_date`, `end_time` — stored as `_event_date`,
`_event_start_time`, `_event_end_date`, `_event_end_time`) against the current
site date/time (`current_datetime()` / `wp_timezone()` — never string compares,
never a hardcoded UTC assumption). The filter runs after normalization and
**before** deduplication/upsert, so expired source events never create or
update posts:

```
Fetch source → Parse → Normalize date/time → Compare with now
    → future/current → import (dedupe → upsert as before)
    → already ended  → skip ("Skipped (past)")
    → missing/invalid/unparseable date → skip ("Skipped (invalid date)")
```

Rules:

- **Multi-day events** use the end date/time as the cutoff when available —
  an event running 20–27 Aug still imports on 25 Aug.
- **Start-only events** import while their start moment is current or future,
  and are skipped once it has passed. With no time given, the event counts as
  running all day (end-of-day cutoff), matching `Conexao_Event_Status`
  expiry semantics.
- **Invalid dates** (missing required date, malformed format, impossible
  calendar dates like Feb 30) are safely skipped with reason
  "the event date could not be evaluated" — they are never treated as future.
- **Existing posts are never deleted or expired by this filter.** Previously
  imported events stay untouched; the separate Cleanup process and the
  auto-expire pass remain responsible for removing/hiding past events.
- Skipped events still count as "present" URLs for `mark_missing_events()`,
  so skipping cannot flip live events to Source Not Found.

Statistics are reported everywhere results are shown:
`skipped=N (past=X, invalid_date=Y)` appears in the run summary log line, the
WP-CLI report (`wp conexao-events import`), the admin result notice, and the
history entry; per-event skip reasons appear in Import Logs.

### Duplicate handling

Re-running the importer updates existing events instead of creating duplicates. Matching priority:

1. Source ID (`_event_source` + `_event_source_id`)
2. Source URL (`_event_url` / `_event_source_url`)
3. Content match (title slug + date + time + venue)

The export/import path additionally matches on the stable export UUID (`_event_export_uuid`) first.

### Failure semantics — a failed first page is a FAILED source, not an empty one (v1.7.2)

**"The source could not be reached" and "the source has no events" are different
facts and must never be recorded as the same thing.**

If the FIRST page of a source listing cannot be fetched or parsed, the importer
raises `Conexao_Source_Fetch_Exception` and the engine records the run as:

| Field | Value |
|---|---|
| `status` | `failed` |
| `fatal_errors` | ≥ 1, carrying the upstream HTTP status |
| `found` | not reported as a successful `0` |

Before v1.7.2 the Eventbrite and Heritage Week handlers logged the failure and
returned an empty array. The engine then ran `set_found( count( $raw_events ) )`
— i.e. `0` — and Import History showed `found=0, status=success, errors=0` for
21 of 26 Eventbrite and 22 of 26 Heritage Week sources while upstream was in
fact returning HTTP failures. That is the defect this section closes.

`run_source()` is the recording authority: it already converts
`Conexao_Source_Fetch_Exception` into a fatal error plus a `failed` history row.
Source handlers only raise the exception; they never re-implement recording.

**Partial-page tolerance is preserved.** A failure on a page *after* the first
does not abort the source: the events already fetched are still imported. Such a
run is recorded as an *incomplete enumeration* (below), never as a failed one,
and never as a complete one.

Provider classification is unchanged by this rule and remains as measured:

| Response | Behaviour |
|---|---|
| `401`, `403` | fail, no blind retry |
| `404` | fail, no retry |
| `408`, `502` | fail, no retry (existing policy) |
| `429`, `500`, `503` | existing 3-attempt retry with backoff (existing policy) |
| timeout / connection failure | retried per the existing policy, then failed |
| malformed (unparseable) payload | fail closed |

### Error handling

- One failed event does not abort the source; one failed source does not abort the run.
- Fatal errors (source unreachable, HTTP errors) are reported per source with technical details.
- Image download failures keep the external URL as fallback meta (`_event_banner`) without failing the event.
- Per-source consecutive-failure counts are tracked informationally (Event Sources table / `wp conexao-events status`); sources are never auto-disabled.

### Pagination semantics — a walk can be INCOMPLETE, and that changes what may be marked

A source enumeration is either **complete** (the returned list is the whole
upstream listing) or **incomplete** (the walk stopped early). The distinction is
first-class: it is exposed as `complete` on the per-source stats array (dry runs
included) and recorded on the Import History row.

`complete = false` when:

- the walk hit its time budget (Eventbrite 45 s, Heritage Week 120 s);
- a later page could not be fetched or parsed;
- pagination terminated unexpectedly, including a forward link that resolved to
  an already-visited page;
- the page limit was reached with pages still declared;
- the source failed before it could be enumerated at all;
- **the listing truncates its own pagination** (G8). Eventbrite pins
  `page_count` at exactly 49 for every county while declaring a much larger
  `object_count` — measured 2026-10-04: Cork 1,547, Dublin 5,162, Carlow
  4,764, Cavan 5,722 objects behind 980 reachable slots. When
  `object_count > page_count × page_size`, the walk marks itself incomplete
  however cleanly every declared page was fetched. This guard is conservative
  by construction: it can only downgrade complete → incomplete, which
  suppresses a destructive reconciliation, and never authorises one;
- **the source was skipped because it is in cooldown** (G9). See
  [Source cooldown and recovery](#source-cooldown-and-recovery).

**The critical rule: an incomplete enumeration must never trigger
`mark_missing_events()`.** Absence from a truncated listing is not evidence that
an event disappeared upstream. Marking it `source_not_found` hides the event
from every public query, because `Conexao_Event_Runtime` excludes that status
from public queries. The 2026-10-03 investigation proved this mechanism had
hidden currently-live events from the public site.

Events that *were* fetched in an incomplete run are still imported normally. The
run is recorded as a `warning` (or `failed`, when the source could not be
enumerated at all) — never as a bare `success` over a truncated list.

### Heritage Week pagination is forward-only

The Heritage Week navigation renders **"Previous" before "Next"** from page 2
onwards, so following the first anchor walked *backwards* and oscillated
p1 ↔ p2 until the page limit (measured: 651 requests, 25 unique URLs, 24 events
for Wicklow). `get_next_page_url()` now selects deterministically:

1. the anchor the page labels `Next`, matched in XPath;
2. otherwise a resolved URL that differs from the current page, has not already
   been visited this run, and carries a strictly greater page number;
3. never the current URL, an already-visited URL, an empty/invalid `href`, or a
   backwards destination.

A per-run visited-URL set is maintained, and the walk terminates when the next
URL equals the current URL, has already been visited, no valid forward link
exists, or the page limit is reached. URL comparison is normalized (fragment,
trailing slash and query-parameter order) so a "new" link cannot silently
re-point at the page just fetched. A "Previous" link pointing at an
already-visited page is the *normal* end of a listing and is never treated as a
pagination fault.

Measured after the fix on the same source: `p1 → p2 → … → p11`, 0 revisits, 132
requests, 0 duplicates, 121 events.

## Request efficiency, retry policy and bounded execution (G4)

Three independent mechanisms, added 2026-10-03. All three are **per run** and
none of them is persistent: nothing is written to an option, a transient, the
database or disk, so a response or a counter from a previous run can never
satisfy a later run. No second HTTP client was introduced — `wp_remote_get()`
remains the only transport, and `Conexao_Eventbrite_Client` remains the only
Eventbrite client.

### Request reuse (per run)

`Conexao_Request_Reuse` memoises **responses** for the duration of one run.

**Eligible:** `GET` and `HEAD` only, with no request body, no streaming/ranged
fetch, and no per-request `Authorization`, `Cookie`, `Range`, `If-None-Match` or
`If-Modified-Since`. In practice this covers the deterministic listing and detail
fetches (Heritage Week listing + detail pages, Mondello Park's JSON feed, the
Eventbrite discovery page).

**Never cached:** `POST` and every other state-changing method; requests with a
body; requests carrying per-request auth context; conditional/ranged requests;
and **every non-2xx response**. That last rule matters: a transient 429/5xx is
never memoised, so a failure cannot be replayed later as if it were page
content, and a retry always goes back to the network.

**Cache key.** Built from every response-relevant input: method, normalized URL,
the response-varying request headers (`Accept`, `Accept-Language`,
`Accept-Encoding`, `Authorization`, `Cookie`, `Referer`, `User-Agent`,
`X-Requested-With`) and the body. Normalization is limited to host case and the
fragment — neither is transmitted — so header case/order and `#fragment` cannot
create a false miss, while path, query and scheme case are preserved so
genuinely different targets cannot collide.

**Correctness.** A hit returns the stored response array exactly as the
transport produced it, so it flows through the identical downstream path: HTML
normalization, JSON decoding, status handling, exception classification and
logging are all unchanged. Reuse is **not** a shortcut around the parser — a
malformed cached body fails closed exactly as a malformed fresh body does.

**Observability.** `Conexao_Request_Reuse::get_stats()` exposes `hits`, `misses`,
`stores` and `bypassed`, so a hit (no outbound request) is distinguishable from a
miss (an outbound request). The engine writes **one** summary entry per run
(`Request accounting: …`), never one entry per hit.
### Retry behaviour

Implemented in `Conexao_Eventbrite_Client`. The attempt ceiling
(`MAX_RETRIES = 3`) is unchanged and remains authoritative: retry is never
unbounded, and a success on the final permitted attempt is honoured.

| Response | Retried? | Delay before the next attempt |
|---|---|---|
| `401` | **no** | — (an authentication failure is not made true by asking again) |
| `403` | **no** | — |
| `404` | **no** | — |
| `408` | **no** | existing policy |
| `429` | yes (max 3) | `Retry-After` when valid, else 2/4/8 s backoff |
| `500` | yes (max 3) | `Retry-After` when valid, else 2/4/8 s backoff |
| `502` | yes (max 3) | `Retry-After` when valid, else 2/4/8 s backoff |
| `503` | yes (max 3) | `Retry-After` when valid, else 2/4/8 s backoff |
| `504` | yes (max 3) | `Retry-After` when valid, else 2/4/8 s backoff |
| transport error (`WP_Error`) | yes (max 3) | 2/4/8 s backoff |

`502` and `504` became retryable in G4; before G4 the retried set was
`{429, 500, 503}`. Both are server-side gateway failures that a bounded retry
can genuinely recover from.

**`Retry-After`** is honoured in both standard forms — delta-seconds
(`Retry-After: 7`) and an HTTP-date. The parsed value is always clamped:

```
delay = min( parsed_value, MAX_RETRY_AFTER_SECONDS )   // cap = 60 s
```

so an upstream server can never impose an unbounded sleep. An empty,
non-numeric or unparseable value falls back to the existing exponential backoff
(2/4/8 s), and a past HTTP-date clamps to `0`. Delays are recorded on the client
(`get_applied_delays()`) and a sleeper can be injected (`set_sleeper()`), so the
timing policy is asserted in tests without any real sleep.

### Request budget

`Conexao_Request_Budget` bounds **outbound requests** per run.

- **Scope:** per importer run — `run_source()` opens a fresh scope, so a combined
  `run_all()` gives every source its own budget rather than letting one source
  consume the whole run's capacity.
- **Accounting:** counts requests that actually leave the process. A response
  served from the reuse store is **not** charged, because nothing was sent. A
  retry attempt *is* charged, because it is a real request.
- **Default ceiling:** `400` requests per run
  (`Conexao_Request_Budget::DEFAULT_LIMIT`), filterable with
  `conexao_event_importer_request_budget`. The value is derived from the measured
  post-G1–G3 runtime baseline: the largest legitimate single source (Heritage
  Week Wicklow) used 132 requests for a complete enumeration, and the whole
  56-source dry run used 1,525. The ceiling sits well above any legitimate
  single source and far below a runaway, so it prevents runaway behaviour
  without making normal imports fail.
- **On exhaustion:** `consume()` returns `false`, no request is issued, and the
  call surfaces as the ordinary fetch-failure path — which the completeness
  machinery above already maps to `complete = false`. The source becomes
  **incomplete**, never complete and never an empty success.
- **No data loss:** events fetched before the ceiling are retained, and because
  the walk is incomplete, `mark_missing_events()` is **not** called. This is
  covered by a cross-stage test *with its control*: with an ample budget the
  same absence *is* marked `source_not_found`, proving the guard is not simply
  disabled.

**Filters:** `conexao_request_reuse_enabled` (disable reuse),
`conexao_event_importer_request_budget` (change the ceiling).

### The invariants that survive G4

| Invariant | Enforced by |
|---|---|
| `failed fetch != empty success` | G1 (unchanged); a non-2xx is never cached |
| `incomplete walk != complete walk` | G2 (unchanged) + G4C exhaustion |
| `partial data != permission to mark missing` | G2 guard + the G4C cross-stage control |
| `retry != unbounded retry` | G4B: `MAX_RETRIES = 3`, hard `Retry-After` cap |
| `cache hit != stale cross-run data` | G4A: store reset per run, nothing persisted |
| `request budget != silent data loss` | G4C: exhaustion → incomplete, data retained |
| `diagnostics survive tests` | G3 (unchanged) |

## Eventbrite pacing and rate-limit handling (G5)

Eventbrite is an **external dependency that can rate-limit the importer**.
G5 does not make Eventbrite succeed. It makes the importer respond to that
condition in a bounded, sequential, truthful and operationally recoverable
manner. Evidence and measurements:
[`docs/reports/2026-10-04-eventbrite-rate-limit-strategy.md`](../reports/2026-10-04-eventbrite-rate-limit-strategy.md).

### High-volume sources are the stress cases

Cork (`eventbrite_cork`, ~49 pages / ~1550 objects) and Dublin
(`eventbrite_dublin`) are the primary operational stress cases, because a long
walk is what exposes rate limiting. Small sources such as `eventbrite_carlow`
are useful controls but must never stand in for Cork/Dublin.

### One policy, not scattered sleeps

All outbound Eventbrite timing lives in **one** class,
`Conexao_Eventbrite_Pacing` (`includes/class-eventbrite-pacing.php`).
`Conexao_Eventbrite_Client` and the source handler ask it; neither sleeps on
its own. It governs three distinct concerns:

| Concern | Mechanism | Default |
|---|---|---|
| Page pacing | `pace_page_request()` | `PAGE_DELAY_SECONDS = 2` |
| Retry pacing (5xx) | existing G4B backoff | 2 / 4 / 8 s |
| Rate-limit cooldown (429) | `apply_rate_limit_cooldown()` | 20 s, then 40 s, capped at 60 s |

The 2-second page delay is **measured, not guessed**: the G4 baseline reached
its first 429 after ~36 Eventbrite requests at a median 2.85 s interval, i.e.
sustained traffic of roughly 20 requests/minute. Doubling the inter-request
floor is the smallest conservative step that evidence supports.

Sleep and clock are injectable (`set_sleeper()`, `set_clock()`), so the whole
timing policy is asserted deterministically without a single real sleep.

### Normal operation

Eventbrite sources run **sequentially** — one source completes (or stops
incomplete) before the next begins. Parallel Eventbrite fetching is not
introduced and does not exist. The per-run request budget (400) and the 45-second
per-source time budget both remain authoritative; pacing never bypasses them.

### Rate limiting (HTTP 429)

A 429 is **not** treated as an ordinary transient page failure. It enters an
explicit rate-limited state which:

1. records the 429, the URL, the page, the attempt and the retry streak;
2. preserves every event already fetched;
3. keeps the enumeration **INCOMPLETE**;
4. applies exactly one **bounded** cooldown before the next attempt;
5. never retries immediately, and never exceeds `MAX_RETRIES = 3` attempts;
6. terminates the source cleanly once the ceiling is reached.

### `Retry-After`

`Retry-After` is honoured when present (delta-seconds and HTTP-date), and is
**hard-capped at 60 s** so Eventbrite can never dictate the length of the
process. An unparseable, negative or absent value falls back to the
deterministic 20 s ladder. **No `Retry-After` header has ever been observed in
a real Eventbrite response from this environment** (`responses_with_retry_after
= 0` across every measured run), so this path is **mechanically proven but not
live-observed**.

### Repeated 429 — fail fast

```text
429 → bounded retry (20s cooldown)
    → 429 → bounded retry (40s cooldown)
    → 429 → stop this Eventbrite source cleanly
```

The walk **stops at the rate-limited page** instead of continuing to the next
one. Measured justification: in the archived full-run trace, once the first 429
appeared, **24 of 24** remaining Eventbrite discovery paths answered 429 on
every attempt. Paginating onward was not recovering data — it was a request
storm. With the fail-fast removed, the same deterministic scenario issues
**118 requests and walks 33 pages** past the point where the walk should have
stopped (see the negative proofs).

The final state is `incomplete`, never `successfully complete` and never
`empty`, and absent events are **never** marked `source_not_found`.

### Rate limiting is source-wide (measured)

The G5E experiment is explicit evidence, not an assumption: after the first
429, all 24 subsequent Eventbrite paths were 100 % 429. A **run-scoped shared
cooldown** therefore applies: once one Eventbrite source in a run is rate
limited, later Eventbrite sources in **that same run** are skipped without
sending a single request, and are recorded as `rate_limited` rather than
skipped silently. The state is in-memory and reset by `begin_run()` — no
option, transient, table or scheduler — so a later run can always try again.

### Partial import and idempotence

Events fetched before a rate-limit stop are preserved and imported normally. A
retry later updates them by source identity rather than duplicating them. A
rate-limited or otherwise incomplete walk never triggers
`mark_missing_events()`, so previously imported events are untouched.

### Operator workflow

```text
run Eventbrite source (Events → Run, or the Import Dashboard)
    ↓
inspect the result in Import History / Import Log
    ↓
if rate limited:  stop, allow the cooldown, retry THAT source later
if complete:      continue with the next source
```

Individual Eventbrite sources are independently recoverable — recovering one
rate-limited county does **not** require re-running all 56 sources. The Import
Log states the reason explicitly (`reason=rate_limited`) together with the URL,
page, attempt count, applied cooldown, and whether a `Retry-After` was present
or capped.

Do **not** immediately re-run a rate-limited source: the cooldown is bounded,
but hammering the upstream immediately is exactly the behaviour G5 removes.
There is no permanent block and no scheduler: a new run always starts clean.

### Source cooldown and recovery (G9)

Measured 2026-10-04: a single Cork `all-events` walk was answered **HTTP 200 for
pages 1–31 and HTTP 429 for pages 32–55**. A source that keeps being refused
stays refused across runs, so G9 records that per source instead of letting the
next run re-send doomed requests.

`Conexao_Source_Cooldown` stores one bounded record per source
(`conexao_event_source_cooldown`): `consecutive`, `last_reason`,
`last_observed`, `cooldown_seconds`, `next_eligible`.

**Which failures earn a cooldown**

| Failure | Cooldown | Why |
|---|---|---|
| HTTP 429 | yes | re-sending immediately cannot help |
| HTTP 405 | yes | this provider's WAF refusal code |
| HTTP 5xx | yes | upstream is unhealthy |
| transport failure | yes | we never reached it |
| HTTP 404 | **no** | a fixable source must stay immediately retryable |
| malformed / parse error | **no** | a broken source, not a throttled one |

**Ladder.** Base 20 s (G5's measured rate-limit base), stepping to a ceiling of
60 s (G5's existing cooldown ceiling): **20 s → 40 s → 60 s → 60 s → 60 s**. A
different failure category restarts the ladder, so a WAF block does not inherit a
429's accumulated delay.

**What a cooldown does and does not do**

- It **skips the source and sends zero requests**. Import History records the run
  as `complete = false` with the reason and `next_eligible`.
- It **never** marks a source complete or empty. A source in cooldown is
  *unknown*, not *done*.
- It **never** runs `mark_missing_events()`: the gate returns before any fetch.
- It **never** disables a source, and **never** retries on its own. There is no
  scheduler and no automatic retry loop. Deferring the request is the whole
  intervention.
- It clears automatically once **one complete, non-empty enumeration** succeeds.
  An incomplete or empty walk does **not** prove recovery, so the cooldown stands.

Pressing *Run import* during the window reports:

> Not attempted: … is in cooldown after a rate_limited response. No request was
> sent. Try again in about 40 seconds, or clear the cooldown if you believe this
> is wrong.

**Cadence.** No automatic schedule is implemented, deliberately: choosing one
requires source-volatility measurements that have not been taken yet. Scheduling
by intuition was refused rather than shipped.

## Diagnostics: Import History, Import Logs and run correlation

## Diagnostics: Import History, Import Logs and run correlation

- **Import History** (`conexao_event_import_history`, 100 rows) records
  **run-level** status per source: counts, `status`, a human-readable message,
  `fatal_errors`, `failed_events`, `run_id`, the `complete` flag, and (G9) a
  bounded `cooldown` shape — `cooling_down`, `seconds_remaining`,
  `next_eligible`, `last_reason`, `consecutive`.

  The screen renders two columns from this (G9):

  | Column | Answers |
  |---|---|
  | **Listing** | Was the source fully enumerated, or did we see only part of it? |
  | **Cooldown** | Is it cooling down, why, and how long until I may retry? |

  `Listing = complete` is the **only** state in which a run may have marked
  absent events `source_not_found`, so it is the column that decides whether a
  re-run is worth anything.
- **Import Logs** (`conexao_event_import_log`, 2,000 entries) preserve
  **diagnostic** entries: per-page and per-source detail with HTTP status and
  URL context. These are the records an operator reads to explain a failed run.
- **Run correlation**: the engine publishes a run ID through the
  `conexao_event_importer_current_run_id` filter. Every Eventbrite, Heritage
  Week and source-health diagnostic entry passes it as `run_id`, so
  `Conexao_Import_Log::get_for_run()` resolves a run's entries and an Import
  History row links to the diagnostics that explain it. There is exactly one
  correlation identifier; no second one is invented. Entries written outside a
  run keep an empty `run_id` and remain readable.

### Test safety — diagnostics tests must not destroy operator history

`conexao_event_import_log`, `conexao_event_import_history`,
`conexao_event_source_health` and `conexao_event_sources` are **operator state**,
not test fixtures. A test that clears them destroys the record a maintainer
needs, which is a defect in its own right.

Diagnostic suites therefore use the shared harness helper
`tests/lib/option-isolation.php`:

```php
$restore = test_options_isolate( array( Conexao_Import_Log::OPTION_KEY ) );
... exercise the code under test ...
test_options_restore( $restore );
```

`test_options_isolate()` snapshots the current value (including "this option does
not exist"), seeds an empty store, and registers a shutdown safety net so a
suite that dies early still restores. `test_options_restore()` puts the snapshot
back verbatim.

This is not theoretical. Before v1.7.2 `test-import-log.php` called
`Conexao_Import_Log::clear()` against the live option and the canonical suite
drove it 599 → 91 → 0. The same discipline also fixed
`test-error-handling.php`, whose long-standing "History entry was added" failure
was caused by `MAX_ENTRIES` saturating the real history so the count could not
grow — an environment dependency, not a code defect.


## Event Meta (registered)

Event meta registration is owned by the [Event Runtime](conexao-event-runtime.md)
plugin since v1.5.0. See `docs/content-model.md` for the full meta field list. Key fields:

- `_event_source` / `_event_source_id` — deduplication key
- `_event_status` — visibility gate (published / draft / expired / etc.)
- `_event_date` / `_event_time` / `_event_end_date` / `_event_end_time` — scheduling
- `_event_banner` / `_event_banner_attachment_id` — images
- `_event_url` — external ticket/info URL

## Address support (v1.6.0)

Imported events carry a reliable physical address whenever the source provides
one. The primary consumer is the mobile app, which reads the address through
the existing REST meta.

### Fields

| Field | Meaning |
|---|---|
| `_event_venue` | Venue/place name only (e.g. `Electric Picnic`) |
| `_event_address` | Street-level address as supplied by the source (e.g. `Stradbally Hall, Stradbally, Co. Laois`) |
| `_event_location` | Short card label (unchanged behavior: venue → town → county) |
| `_event_map_url` | Deterministic Google Maps **search** URL derived at import time (see below) |

`_event_address` already existed and is **reused** — no duplicate field was
introduced. `_event_map_url` is the one new field (registered by Event
Runtime ≥ 1.1.0, REST-visible like the other `_event_*` meta).

### Extraction priority (per event)

1. **Structured location/address data from the source** — Eventbrite
   `venue.address` fields (API `expand=venue` and the discovery-page
   `window.__SERVER_DATA__`), composed from the supplied
   `address_1/address_2/city/region/postal_code` parts.
2. **JSON-LD structured data** (Heritage Week detail pages) — only
   `schema.org/Event.location` is read (`Place.name` → venue; `Place.address`
   as text or `PostalAddress` → address, composed from the supplied
   `streetAddress/addressLocality/addressRegion/postalCode/addressCountry`
   parts). Organizer/contact/ticket-office addresses elsewhere on the page
   are structurally rejected.
3. **Location-string heuristics** (iCalendar `LOCATION`, Heritage Week
   labelled details list, Laois Tourism) — the full supplied string is kept
   as the address only when it contains a deterministic address signal
   (street-number pattern or an Irish Eircode).

### No-guess rule

The importer never invents address content. `Dublin` stays a venue/town label
with an empty address (nothing is appended, not even "Ireland");
`Croke Park` never gets a street address; `Jones Road, Dublin 3, D03 P0K7` is
stored exactly as supplied (normalized only). A source value that fails
validation (malformed, markup, URLs, no address signal) is rejected and the
address stays empty.

### Normalization (`Conexao_Event_Address`)

HTML entities decoded, `<br>`/block-tag boundaries converted to `, `,
markup stripped, whitespace collapsed, duplicate separators collapsed,
leading/trailing separators trimmed. Content is otherwise preserved: no
translation, no removal of apartment/unit information, no alteration of
postal codes. Values are sanitized as plain text for storage.

### Map URL

`_event_map_url` is a plain `https://www.google.com/maps/search/?api=1&query=…`
link (same scheme as the theme's leisure map helper) — no Maps API, no
geocoding, no API key, no remote requests. Query priority: full address →
venue + location label → location label. An existing non-empty
`_event_map_url` is **never overwritten** (no ownership metadata exists, so
manual values cannot be distinguished from importer-derived ones — preserve
is always safe); events imported before this feature are backfilled on their
next import.

### Update / preservation behavior

No ownership metadata exists for event fields; the importer's existing
strategy is preserved and extended deterministically:

- The source address wins on re-import (importer-owned field on update).
- When the source supplies **no** address, an existing stored address
  (e.g. manually corrected in wp-admin) is **preserved**, not wiped.
- An unchanged address resolves to the identical value, so idempotent
  re-imports do not rewrite the meta (the unchanged fast-path is not
  triggered by it).
- `_event_map_url` is only written when currently empty (never overwritten).

### Dry-run address audit

`wp conexao-events import --dry-run` (or the equivalent engine call) reports
per-source address audit counters without writing anything:

```
address-audit: found=N unchanged=N changed=N no_source_address=N no_address=N ambiguous=N skipped=N
```

- `found` — source supplies a usable address (new or added to an event that had none)
- `unchanged` — source address matches the stored address
- `changed` — source address differs from the stored address
- `no_source_address` — source supplies none; the stored address would be preserved
- `no_address` — neither source nor stored event has one
- `ambiguous` — source supplied a value that failed validation (rejected)
- `skipped` — event skipped (past, invalid date, incomplete data)

Run this **before** any production migration; the dry run modifies nothing.

## Taxonomy

- `conexao_town` — registered by the [Event Runtime](conexao-event-runtime.md) plugin, applied to events only

## Export format (v1.1)

```json
{
  "manifest": { "format": "conexao-event-export", "version": "1.1.0", ... },
  "events": [
    {
      "uuid": "...",
      "post":   { "title", "content", "excerpt", "status", "slug", "date" },
      "meta":   { "_event_date", "_event_start_time", "_event_url", ... },
      "taxonomies": { "conexao_category": [], "conexao_county": [], ... },
      "featured_image": {
        "source_url":  "https://external/...",
        "banner_url":  "",
        "alt":         "",
        "filename":    "original.jpg",
        "mime_type":   "image/jpeg",
        "data_base64": "<embedded image bytes>"
      }
    }
  ]
}
```

Images larger than 5 MB are not embedded; the import then falls back to sideloading from the source URL when reachable.

## WP-CLI

```
wp conexao-events import [--source=<id>] [--dry-run]   # default: all active sources
wp conexao-events cleanup [--dry-run]
wp conexao-events status                                # per-source health table
```

## Hooks

### Filters
- `conexao_event_importer_http_user_agent` — override the browser-like UA used for source fetches
- `conexao_request_reuse_enabled` — disable per-run response reuse (default: enabled inside a run)
- `conexao_event_importer_request_budget` — the per-run outbound request ceiling (default: 400)
- `conexao_eventbrite_time_budget_seconds` — Eventbrite per-walk time budget
- `conexao_heritage_week_time_budget_seconds` — Heritage Week per-walk time budget
- `conexao_eventbrite_backoff_seconds` — the exponential backoff before retry attempt N
- `conexao_eventbrite_request_delay_seconds` — the polite delay between Eventbrite pages
- `conexao_event_importer_run_source` — trigger single source import (sync)
- `conexao_event_importer_run_all` — trigger all sources import (sync; accepts args array with `dry_run`, `source_ids`)
- `conexao_event_importer_current_run_id` — expose current run ID
- `conexao_event_importer_get_handler` — register custom source handlers
- `conexao_event_image_download_args` — customize image download HTTP args

### Actions

The public `_event_status` query gate is **not** registered here anymore —
it moved to the Event Runtime plugin (see the split section above).

## Multilingual (Stage 2) — language guard

`includes/class-language-guard.php` (`Conexao_Event_Importer_Language_Guard`)
protects event identity when Polylang is active:

- **Import language**: imported events are assigned the site's default language
  (`pt_BR`) on `save_post_event`, whichever path created them (single import,
  multi-import, JSON/ZIP transfer). An existing language is never reassigned, so
  a hand-made English translation keeps `en`. Source-language detection for
  English-native sources is Stage 3 work and deliberately not implemented.
- **Import target**: `upsert_event()` refuses to write to a record in another
  language — a translation is not the import target. The run reports a skip
  (`language conflict`) instead of overwriting translated content or creating a
  second record for the same source identity.
- **Deduplication**: `Conexao_Event_Deduplicator::find()` prefers the record in
  the import language (identity meta is shared verbatim with the translation, so
  a naive newest-first lookup could otherwise return the translation).
- Identity meta (`_event_source`, `_event_source_id`, `_event_export_uuid`,
  `_event_url`, scheduling/status fields) stays language-neutral — Polylang only
  adds the language relationship.

Gate coverage: `tests/test-language-identity.php`.
All helpers are no-ops when Polylang is inactive.

## Export language field (Stage 3.2)

The local export JSON gained ONE additive per-event field, `"lang"`:

- Values: `pt` | `en` | `other` | `unknown`. Source of truth: the event's
  `_event_source_language` meta (see the runtime plugin's
  `Conexao_Event_Source_Language`). `unknown` covers every record with no
  explicit classification (legacy imports, manual events, sources without a
  language signal) — the value is never guessed from content text.
- The field is ADDITIVE: every existing field (uuid, post, meta, taxonomies,
  featured_image) is unchanged, so existing consumers ignore it safely.
  `_event_source_language` also round-trips inside the `meta` bag (added to the
  transfer importer's allowlist), so production records receive the
  classification on import.
- The export set is constrained to the IMPORT language when Polylang is
  active: English translations are linked editorial records that share the
  identity meta, and exporting them would duplicate the identity in the
  package — the export contains exactly one row per production identity.
- Importer-side: `source_language` is honored from the normalized payload only
  when it is a valid explicit classification; the Eventbrite normalizer maps
  the event's declared `locale`; absent/invalid values store nothing.
- Flutter does NOT depend on this field in Stage 3.2.

## Admin UI

Menu: Event Import (top-level menu, icon dashicons-calendar-alt)

Submenus:
- Dashboard — overview, **Import Events Now**, Export Events link, recent history
- Event Sources — CRUD sources (incl. county, category), toggle, run single import
- Import History — historical import runs
- Export Events — JSON download (with embedded images)
- Import Events — JSON upload/import (creates attachments from embedded data)
- Image Sync — bulk sideload of remaining external banner images
- Cleanup — manual trigger + status/history
- Import Logs — view, download, clear logs
- Settings — importer settings storage (there is nothing to save; no Eventbrite API token)

## Known fixed pitfalls

- **meta_query OR-contamination (fixed):** the public status filter used to be appended flat into queries that already had a top-level `relation => 'OR'` meta_query (e.g. dedup lookups), producing `(url match) OR (published)` — matching every published event. The filter now nests existing clauses so the status constraint is always ANDed.
- **Legacy source type:** if a source's stored `type` doesn't match its handler (e.g. `website` instead of `icalendar`), the wrong parser runs silently. Verify type after editing URLs by hand in the DB.
- **Stale uploaded ICS:** an uploaded `.ics` file always overrides the feed URL. Clear the `ics_content` key to subscribe to the live URL again.

## Dependencies

- `conexao-data-model` (event CPT must exist)

## Recurrence / occurrence dates (Stage 20)

An imported event may be a **series**. The importer stores the series as
**one canonical event post** plus the four recurrence meta keys the data model
already had (`_event_recurrence`, `_event_recurrence_days`,
`_event_recurrence_start`, `_event_recurrence_end`). It never creates one post
per occurrence, and it never re-implements the evaluator — `Conexao_Event_Recurrence`
in the **Event Runtime** plugin remains the single place that answers "does
this event occur on date X". A manual rule written in wp-admin and an imported
rule are stored in exactly the same shape.

Resolution happens per VEVENT, standard mechanism first:

1. **`RRULE` (RFC 5545)** — read generically for every ICS source by
   `includes/class-conexao-ics-recurrence.php`. Supported subset:
   `FREQ=WEEKLY`, `INTERVAL=1`, `BYDAY`, `COUNT`, `UNTIL`. Anything outside
   that subset (e.g. `FREQ=DAILY`, `INTERVAL=2`, ordinal `2MO`) returns null
   and the event simply stays one-time: `DTSTART`/`DTEND` still describe a
   valid single event, so degrading is always safe.
2. **A source-specific adapter**, consulted only for source ids registered in
   `Conexao_Source_ICalendar::resolve_series_adapter()`. This is the isolation
   boundary that keeps one feed's convention from leaking into standard ICS
   semantics for any other feed.

### Laois Tourism: the collapsed-series convention

`laoistourism.ie` (`PRODID:-//Laois Tourism - ECPv6.18.0//`) publishes **no
`RRULE` at all** — measured across the whole feed: 30 VEVENTs, 0 RRULE,
0 EXDATE, 0 RDATE, 0 `X-` recurrence properties. A weekly course is encoded as
a single VEVENT whose `DTSTART` is the **first occurrence** and whose `DTEND`
is the end of the **last occurrence**.

Read literally that pair is indistinguishable from one event running
continuously for the whole window — which is why a four-week class used to be
listed on every day in between. `includes/class-conexao-laois-tourism-series.php`
recognises the pattern with a **structural** test, never a title or keyword
match: the event is timed, `weekday(DTSTART) === weekday(DTEND)`, the day span
is an exact multiple of 7, and the leftover time-of-day delta is a positive,
less-than-one-day single-session duration. All-day (`VALUE=DATE`) events and
genuine multi-day events fail the test and stay one-time.

The rule reproduces the occurrence counts the source states in its own
`DESCRIPTION` ("four-week Chair Yoga" → 4, "6-week Bootcamp" → 6,
"seven-week workshop" → 7), which is the independent corroboration that the
interpretation matches the publisher's intent.

All date arithmetic is calendar-based (never a site-timezone instant
conversion), so a `TZID=Europe/Dublin` series keeps its local dates across the
DST transition.

### Idempotence

The rule participates in change detection, so the first run after the series
becomes known reports the change and writes the rule, and every later run
compares equal. Measured: run 1 `created=30`, run 2 `created=0 updated=0
unchanged=30`. An event whose source stops declaring a series has the whole
meta group deleted, so a withdrawn series can never leave an orphan rule.

### Tests

- `tests/test-ics-occurrence-dates.php` — in-process suite (172 assertions)
  against the committed real-world fixture `tests/fixtures/ics/laois-tourism.ics`.
- `tests/stage20-laois-import-run.php` — baseline / dry-run / apply / verify
  runner used for the deterministic two-run evidence.
- `tests/acceptance/verify-event-occurrence-http.py` — HTTP acceptance.

## Files to Inspect First

- `includes/class-event-importer.php` — import engine, upsert, dry-run
- `includes/sources/class-icalendar-source.php` — ICS parsing + recurrence resolution
- `includes/class-conexao-ics-recurrence.php` — RFC 5545 RRULE reader (generic)
- `includes/class-conexao-laois-tourism-series.php` — Laois collapsed-series rule
- `includes/class-event-export.php` / `includes/class-event-import.php` — the local→production transfer (UUIDs + embedded images)
- `includes/class-event-sources.php` — source CRUD + dashboard