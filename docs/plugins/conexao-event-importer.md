# Conexão Event Importer

- **Path**: `wp-content/plugins/conexao-event-importer/`
- **Version**: 1.2.0
- **Purpose**: Autonomous event aggregation from external sources (Laois Tourism, Laois County Council, Local Enterprise Office Laois, National Heritage Week, Eventbrite Laois). Fetches, normalizes, deduplicates, imports, expires and cleans up events with minimal manual intervention.

## Responsibilities

- Manage event source configurations (add, edit, toggle, delete) including per-source import frequency
- Fetch events from external sources (iCalendar, HTML scraping, Eventbrite discovery)
- Normalize raw event data into structured WP post data
- Deduplicate events by source + source_id (primary), URL, or content match
- Create/update/trash event posts with full meta, taxonomies, and images
- Run a fully autonomous chunked import pipeline over WP-Cron (one source per tick)
- Track per-source health: consecutive failures, staleness, auto-disable
- Email administrators when automated runs fail or a source is auto-disabled
- Expose token-protected REST endpoints for external triggers and monitoring
- Provide WP-CLI commands for ops (`wp conexao-events …`)
- Export/import events as JSON (with stable UUIDs)
- Download external event images into the Media Library (inline during import + nightly sweeper)
- Log all import activity with download/clear capabilities
- Clean up expired events and orphaned images weekly
- Auto-expire published events once their end date/time passes

## Key Components

| File | Class | Purpose |
|------|-------|---------|
| `conexao-event-importer.php` | `Conexao_Event_Importer` | Main plugin, boot all components |
| `includes/class-event-sources.php` | `Conexao_Event_Sources` | Source CRUD, admin pages, import trigger |
| `includes/class-event-status.php` | `Conexao_Event_Status` | Status constants, get/set/mark-expired, shared end-timestamp helper |
| `includes/class-event-normalizer.php` | `Conexao_Event_Normalizer` | Normalize raw event data |
| `includes/class-event-location.php` | `Conexao_Event_Location` | Location normalization (county, town, venue) |
| `includes/class-event-deduplicator.php` | `Conexao_Event_Deduplicator` | Find existing events by source_id, URL, or content |
| `includes/class-event-importer.php` | `Conexao_Event_Importer_Engine` | Main import engine, upsert logic, dry-run support |
| `includes/class-event-image-handler.php` | `Conexao_Event_Image_Handler` | Download external images into Media Library |
| `includes/class-import-scheduler.php` | `Conexao_Import_Scheduler` | Daily kickoff + chunked ticks, run lock, retries, finalize/notify |
| `includes/class-import-settings.php` | `Conexao_Import_Settings` | Automation settings storage + Settings admin page |
| `includes/class-source-health.php` | `Conexao_Source_Health` | Consecutive failures, staleness detection, auto-disable |
| `includes/class-import-notifier.php` | `Conexao_Import_Notifier` | Failure email alerts + auto-disable alerts |
| `includes/class-source-fetch-exception.php` | `Conexao_Source_Fetch_Exception` | Structured transport/HTTP fetch failure |
| `includes/class-import-rest.php` | `Conexao_Import_Rest` | Token-protected `/run` and `/status` REST endpoints |
| `includes/class-import-cli.php` | `Conexao_Import_CLI` | WP-CLI commands (`wp conexao-events …`) |
| `includes/class-image-sync-scheduler.php` | `Conexao_Event_Image_Sync_Scheduler` | Nightly sweeper localizing external banner images |
| `includes/class-import-result.php` | `Conexao_Import_Result` | Structured import result tracking |
| `includes/class-import-log.php` | `Conexao_Import_Log` | Persistent log storage |
| `includes/class-import-log-admin.php` | `Conexao_Import_Log_Admin` | Log admin UI, download, clear |
| `includes/class-import-history.php` | `Conexao_Import_History` | Import run history tracking |
| `includes/class-event-export.php` | `Conexao_Event_Export` | JSON export with UUIDs |
| `includes/class-event-import.php` | `Conexao_Event_Import` | JSON import with dry-run, dedupe, media handling |
| `includes/class-event-cleanup.php` | `Conexao_Event_Cleanup` | Weekly cleanup of expired events + orphaned images |
| `includes/class-event-transfer-admin.php` | `Conexao_Event_Transfer_Admin` | Export/import admin pages |
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
| `class-laois-council-source.php` | `Conexao_Source_Laois_Council` | HTML scraping |
| `class-leo-laois-source.php` | `Conexao_Source_LEO_Laois` | ASP.NET JSON/HTML API |
| `class-eventbrite-source.php` | `Conexao_Source_Eventbrite` | Eventbrite: official v3 API (when token set) or discovery-page scraping, time-budgeted |

## Default Sources (seeded on activation)

| ID | Name | Type | Frequency |
|----|------|------|-----------|
| `laois_tourism` | Laois Tourism | iCalendar (webcal) | weekly |
| `laois_council` | Laois County Council | Website | weekly |
| `leo_laois` | Local Enterprise Office — Laois | Website/API | weekly |
| `heritage_week` | National Heritage Week | Website | weekly |
| `eventbrite` | Eventbrite — Laois | Eventbrite | daily |

Frequency is editable per source on Event Import → Event Sources → Edit. The scheduler respects it.

### Cloudflare / bot protection

Some sources (e.g. `laoistourism.ie`) sit behind Cloudflare and return **403 to generic user agents**. All source HTTP requests therefore use a real-browser Chrome UA by default (overridable via the `conexao_event_importer_http_user_agent` filter). If a source starts failing with HTTP 403, check whether its WAF started requiring JavaScript challenges — a plain UA no longer suffices there.

### Eventbrite sources

Two fetch modes, chosen automatically:

1. **Official API** (recommended for production): set a personal OAuth token in Event Import → Settings → Eventbrite API. Sources then call `https://www.eventbriteapi.com/v3/events/search/` (location: Laois, 40 km radius, venue+logo expanded). Required on hosts whose IPs Eventbrite blocks — WordPress.com receives **HTTP 405** from the discovery page.
2. **HTML scraping** (fallback, no token): parses the public discovery page's `window.__SERVER_DATA__`. Works only from residential connections; datacenter hosts get blocked.

Create a free token at [developers.eventbrite.com](https://www.eventbrite.com/developers/v3/).

### iCalendar sources

- Accept `webcal://` or `https://` feed URLs; an uploaded `.ics` file overrides the URL (delete the stored file to return to live polling).
- Events are deduplicated by the feed's native `UID`.
- `CATEGORIES` maps to the event category (first entry); per-source `county`/`category` hints apply when the feed omits them.
- County-level location is sufficient for auto-publishing — feeds scoped to one county may omit precise venues.

## Autonomy model

1. A **daily kickoff** cron (03:00 Europe/Dublin) checks every active source and queues those **due** according to their `import_frequency` (daily ≈ every 23h, weekly ≈ every 7 days, with grace).
2. Each queued source is processed in its **own cron tick** (`conexao_event_import_tick`, chained ~60s apart) so one slow source cannot blow the PHP time limit of the whole run.
3. A **transient lock** prevents overlapping runs (cron vs manual vs REST). Stale locks (>2h) are taken over; `wp conexao-events unlock` recovers manually.
4. Sources that fail **fatally** are retried up to 2 extra times within the same run before being declared failed.
5. When the queue empties the run is **finalized**: combined history entry + failure notification email (if enabled).
6. Failures feed the **health tracker**: consecutive failure counts, staleness detection, and optional auto-disable after N consecutive failures (default 5, configurable).
7. Published events are auto-transitioned to `expired` when their end date/time passes (checked at each kickoff and after each import).

## Event Meta (registered)

See `docs/content-model.md` for full meta field list. Key fields:

- `_event_source` / `_event_source_id` — deduplication key
- `_event_status` — visibility gate (published / needs_review / draft / expired / etc.)
- `_event_date` / `_event_time` / `_event_end_date` / `_event_end_time` — scheduling
- `_event_banner` / `_event_banner_attachment_id` — images
- `_event_url` — external ticket/info URL

## Taxonomy

- `conexao_town` — registered by this plugin, applied to events only

## Cron Jobs

| Hook | Schedule | Handler |
|------|----------|---------|
| `conexao_event_import_cron` | Daily (03:00 Europe/Dublin) | Kickoff: queue due sources, start chunked run |
| `conexao_event_import_tick` | Single events (chained) | Process ONE queued source per request |
| `conexao_event_cleanup_cron` | Weekly (Mondays 03:30 Dublin) | Cleanup expired events + images |
| `conexao_event_image_sync_cron` | Daily (04:00 Dublin) | Localize up to N external banner images |

Existing installs are migrated from the legacy weekly kickoff automatically (scheduler version option).

## REST API

Namespace: `conexao-events/v1`. Auth: secret token via `?token=…` or `X-Conexao-Token` header (managed on Event Import → Settings).

| Endpoint | Methods | Purpose |
|----------|---------|---------|
| `/wp-json/conexao-events/v1/run` | GET, POST | Start an async chunked import run. Returns `{ok, run_id, queued, message}`. 409 when a run is already active. |
| `/wp-json/conexao-events/v1/status` | GET | JSON health snapshot: next runs, last run, pending review count, per-source health/staleness/failures. |

Example external cron:

```
curl -fsS "https://conexaobr.ie/wp-json/conexao-events/v1/run?token=$TOKEN"
```

## WP-CLI

```
wp conexao-events import [--source=<id>] [--all] [--dry-run]   # default: due sources only
wp conexao-events cleanup [--dry-run]
wp conexao-events status                                        # schedules + per-source health table
wp conexao-events unlock                                        # force-release a stuck run lock
```

## Hooks

### Filters
- `conexao_event_importer_http_user_agent` — override the browser-like UA used for source fetches
- `conexao_event_importer_run_source` — trigger single source import (sync)
- `conexao_event_importer_run_all` — trigger all sources import (sync; accepts args array with `dry_run`, `source_ids`)
- `conexao_event_importer_current_run_id` — expose current run ID
- `conexao_event_importer_get_handler` — register custom source handlers
- `conexao_event_image_download_args` — customize image download HTTP args

### Actions
- `pre_get_posts` — filter public event queries (`_event_status = published`)
- `conexao_event_import_cron` / `conexao_event_import_tick` — automation pipeline

## Admin UI

Menu: Event Import (top-level menu, icon dashicons-calendar-alt)

Submenus:
- Import Dashboard — overview, run all imports, next scheduled
- Event Sources — CRUD sources (incl. frequency, county, category), toggle, run single import
- Import History — historical import runs
- Export Events — JSON download
- Import Events — JSON upload/import
- Image Sync — bulk sideload external event images (manual complement to the nightly sweeper)
- Cleanup — manual trigger + status
- Import Logs — view, download, clear logs
- Settings — notification email, failure alerts toggle, auto-disable threshold, cleanup scope, REST token, image-sync batch size

A persistent **health banner** appears on importer screens when sources are failing/stale/auto-disabled or a run lock looks stuck.

## Known fixed pitfalls

- **meta_query OR-contamination (fixed):** the public status filter used to be appended flat into queries that already had a top-level `relation => 'OR'` meta_query (e.g. dedup lookups), producing `(url match) OR (published)` — matching every published event. The filter now nests existing clauses so the status constraint is always ANDed.
- **Legacy source type:** if a source's stored `type` doesn't match its handler (e.g. `website` instead of `icalendar`), the wrong parser runs silently. Verify type after editing URLs by hand in the DB.
- **Stale uploaded ICS:** an uploaded `.ics` file always overrides the feed URL. Remove it (Edit Source → re-save without a file clears nothing today — clear the `ics_content` key) to subscribe to the live URL again.

## Dependencies

- `conexao-data-model` (event CPT must exist)

## Files to Inspect First

- `includes/class-import-scheduler.php` — the autonomous pipeline (kickoff/ticks/lock/retries/finalize)
- `includes/class-event-importer.php` — import engine, upsert, dry-run
- `includes/class-source-health.php` + `includes/class-import-notifier.php` — self-healing & alerting
- `includes/class-import-rest.php` + `includes/class-import-cli.php` — external entry points