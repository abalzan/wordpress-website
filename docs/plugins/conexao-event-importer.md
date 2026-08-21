# Conexão Event Importer

- **Path**: `wp-content/plugins/conexao-event-importer/`
- **Version**: 1.1.0
- **Purpose**: Automated event aggregation from external sources (Laois Tourism, Laois County Council, Local Enterprise Office Laois, National Heritage Week, Eventbrite Laois). Imports, normalizes, deduplicates, and syncs events into the event CPT.

## Responsibilities

- Manage event source configurations (add, edit, toggle, delete)
- Fetch events from external sources (iCalendar, HTML scraping, Eventbrite)
- Normalize raw event data into structured WP post data
- Deduplicate events by source + source_id (primary), URL, or content match
- Create/update/trash event posts with full meta, taxonomies, and images
- Manage event publishing status (`_event_status`): published, needs_review, draft, expired, etc.
- Filter public queries to only show published events
- Schedule weekly imports and weekly cleanup via WP-Cron
- Export/import events as JSON (with stable UUIDs)
- Download external event images into the Media Library
- Log all import activity with download/clear capabilities
- Clean up expired events and orphaned images

## Key Components

| File | Class | Purpose |
|------|-------|---------|
| `conexao-event-importer.php` | `Conexao_Event_Importer` | Main plugin, boot all components |
| `includes/class-event-sources.php` | `Conexao_Event_Sources` | Source CRUD, admin pages, import trigger |
| `includes/class-event-status.php` | `Conexao_Event_Status` | Status constants, get/set/mark-expired |
| `includes/class-event-normalizer.php` | `Conexao_Event_Normalizer` | Normalize raw event data |
| `includes/class-event-location.php` | `Conexao_Event_Location` | Location normalization (county, town, venue) |
| `includes/class-event-deduplicator.php` | `Conexao_Event_Deduplicator` | Find existing events by source_id, URL, or content |
| `includes/class-event-importer.php` | `Conexao_Event_Importer_Engine` | Main import engine, upsert logic |
| `includes/class-event-image-handler.php` | `Conexao_Event_Image_Handler` | Download external images into Media Library |
| `includes/class-import-scheduler.php` | `Conexao_Import_Scheduler` | Weekly cron scheduling + run now |
| `includes/class-import-result.php` | `Conexao_Import_Result` | Structured import result tracking |
| `includes/class-import-log.php` | `Conexao_Import_Log` | Persistent log storage |
| `includes/class-import-log-admin.php` | `Conexao_Import_Log_Admin` | Log admin UI, download, clear |
| `includes/class-import-history.php` | `Conexao_Import_History` | Import run history tracking |
| `includes/class-event-export.php` | `Conexao_Event_Export` | JSON export with UUIDs |
| `includes/class-event-import.php` | `Conexao_Event_Import` | JSON import with dry-run, dedupe, media handling |
| `includes/class-event-cleanup.php` | `Conexao_Event_Cleanup` | Weekly cleanup of expired events + orphaned images |
| `includes/class-event-transfer-admin.php` | `Conexao_Event_Transfer_Admin` | Export/import admin pages |
| `includes/class-eventbrite-client.php` | `Conexao_Eventbrite_Client` | HTTP client for Eventbrite pages |
| `includes/class-eventbrite-parser.php` | `Conexao_Eventbrite_Parser` | HTML parser for Eventbrite embedded data |
| `includes/class-eventbrite-normalizer.php` | `Conexao_Eventbrite_Normalizer` | Normalize Eventbrite data format |

### Sources (in `includes/sources/`)

| File | Class | Type |
|------|-------|------|
| `abstract-class-source.php` | `Conexao_Source_Base` | Base class for all sources |
| `class-icalendar-source.php` | `Conexao_Source_ICalendar` | iCalendar/Webcal feeds |
| `class-laois-tourism-source.php` | `Conexao_Source_Laois_Tourism` | HTML scraping |
| `class-heritage-week-source.php` | `Conexao_Source_Heritage_Week` | HTML scraping |
| `class-laois-council-source.php` | `Conexao_Source_Laois_Council` | HTML scraping |
| `class-leo-laois-source.php` | `Conexao_Source_Leo_Laois` | HTML scraping |
| `class-eventbrite-source.php` | `Conexao_Source_Eventbrite` | Eventbrite discovery page |

## Default Sources (seeded on activation)

| ID | Name | Type | Frequency |
|----|------|------|-----------|
| `laois_tourism` | Laois Tourism | iCalendar (webcal) | weekly |
| `laois_council` | Laois County Council | Website | weekly |
| `leo_laois` | Local Enterprise Office — Laois | Website | weekly |
| `heritage_week` | National Heritage Week | Website | weekly |
| `eventbrite` | Eventbrite — Laois | Eventbrite | daily |

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
| `conexao_event_import_cron` | Weekly (Sundays 02:00) | Import all active sources |
| `conexao_event_cleanup_cron` | Weekly (Sundays 03:00) | Cleanup expired events + images |

## Hooks

### Filters
- `conexao_event_importer_run_source` — trigger single source import
- `conexao_event_importer_run_all` — trigger all sources import
- `conexao_event_importer_current_run_id` — expose current run ID
- `conexao_event_image_download_args` — customize image download HTTP args

### Actions
- `pre_get_posts` — filter public event queries (`_event_status = published`)

## Admin UI

Menu: Event Import (top-level menu, icon dashicons-calendar-alt)

Submenus:
- Import Dashboard — overview, run all imports, next scheduled
- Event Sources — CRUD sources, toggle, run single import
- Import History — historical import runs
- Export Events — JSON download
- Import Events — JSON upload/import
- Image Sync — bulk sideload external event images
- Cleanup — manual trigger + status
- Import Logs — view, download, clear logs

## Dependencies

- `conexao-data-model` (event CPT must exist)

## Files to Inspect First

- `conexao-event-importer.php` — main plugin, boot, meta registration, taxonomy
- `includes/class-event-sources.php` — source management, defaults, admin UI
- `includes/class-event-importer.php` — import engine, upsert, image handling
- `includes/class-event-image-handler.php` — secure image sideloading
- `includes/class-event-status.php` — status constants and filtering