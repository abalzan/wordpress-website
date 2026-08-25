# Conexão Event Importer

- **Path**: `wp-content/plugins/conexao-event-importer/`
- **Version**: 1.4.0
- **Purpose**: Local-only event aggregation. Fetches events from external sources (Laois Tourism, National Heritage Week, Eventbrite Laois) into the **local** WordPress installation, downloads all images into the local Media Library, then exports the complete event data as JSON for import into the production WordPress.com site.

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
- Log all import activity with download/clear capabilities
- Manual cleanup of expired events and their exclusively-owned images
- Auto-expire published events once their end date/time passes

## Key Components

| File | Class | Purpose |
|------|-------|---------|
| `conexao-event-importer.php` | `Conexao_Event_Importer` | Main plugin, boot all components |
| `includes/class-event-sources.php` | `Conexao_Event_Sources` | Source CRUD, admin pages, manual import trigger |
| `includes/class-event-status.php` | `Conexao_Event_Status` | Status constants, get/set/mark-expired, shared end-timestamp helper |
| `includes/class-event-normalizer.php` | `Conexao_Event_Normalizer` | Normalize raw event data |
| `includes/class-event-date-filter.php` | `Conexao_Event_Date_Filter` | Past-event filter: compare normalized dates against site-timezone now |
| `includes/class-event-location.php` | `Conexao_Event_Location` | Location normalization (county, town, venue) |
| `includes/class-event-deduplicator.php` | `Conexao_Event_Deduplicator` | Find existing events by source_id, URL, or content |
| `includes/class-event-importer.php` | `Conexao_Event_Importer_Engine` | Main import engine, upsert logic, dry-run support |
| `includes/class-event-image-handler.php` | `Conexao_Event_Image_Handler` | Download/sideload external images + create attachments from embedded base64 data |
| `includes/class-import-settings.php` | `Conexao_Import_Settings` | Settings storage (Eventbrite API token) + admin page |
| `includes/class-source-health.php` | `Conexao_Source_Health` | Informational per-source failure tracking (never auto-disables) |
| `includes/class-source-fetch-exception.php` | `Conexao_Source_Fetch_Exception` | Structured transport/HTTP fetch failure |
| `includes/class-import-cli.php` | `Conexao_Import_CLI` | WP-CLI commands (`wp conexao-events …`) |
| `includes/class-import-result.php` | `Conexao_Import_Result` | Structured import result tracking |
| `includes/class-import-log.php` | `Conexao_Import_Log` | Persistent log storage |
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
| `class-eventbrite-source.php` | `Conexao_Source_Eventbrite` | Eventbrite: official v3 API (when token set) or discovery-page scraping |

## Default Sources (seeded on activation)

| ID | Name | Type |
|----|------|------|
| `laois_tourism` | Laois Tourism | iCalendar (webcal) |
| `heritage_week` | National Heritage Week | Website |
| `eventbrite` | Eventbrite — Laois | Eventbrite |

### Cloudflare / bot protection

Some sources (e.g. `laoistourism.ie`) sit behind Cloudflare and return **403 to generic user agents**. All source HTTP requests therefore use a real-browser Chrome UA by default (overridable via the `conexao_event_importer_http_user_agent` filter). This works fine from a residential/local connection.

### Eventbrite sources

Two fetch modes, chosen automatically:

1. **Official API**: set a personal OAuth token in Event Import → Settings → Eventbrite API token. Sources then call `https://www.eventbriteapi.com/v3/events/search/`.
2. **HTML scraping** (fallback, no token): parses the public discovery page's `window.__SERVER_DATA__`. Works only from residential connections; datacenter hosts get blocked.

Create a free token at [developers.eventbrite.com](https://www.eventbrite.com/developers/v3/).

### iCalendar sources

- Accept `webcal://` or `https://` feed URLs; an uploaded `.ics` file overrides the URL.
- Events are deduplicated by the feed's native `UID`.
- `CATEGORIES` maps to the event category (first entry); per-source `county`/`category` hints apply when the feed omits them.

## Workflow (manual, on-demand)

1. On the **local** Docker WordPress, open **Event Import → Dashboard** and click **Import Events Now** (or run `wp conexao-events import`). Each source can also be imported individually from Event Sources.
2. The importer fetches each active source, normalizes events, filters out past/invalid-date events (see [Past-event filtering](#past-event-filtering-v14)), deduplicates against existing posts, and upserts. Images are downloaded into the local Media Library during import (`_event_banner_attachment_id` + post thumbnail).
3. Review needs-review events in the Events list if desired.
4. Open **Event Import → Export Events** and download the JSON file. The export embeds each event's featured image as base64 data (up to 5 MB per image).
5. On **production**, open **Event Import → Import Events** and upload the JSON. Attachments are recreated from the embedded bytes — no external requests are made.
6. Optionally run **Cleanup** locally before exporting to remove past events.

### Upload size limits

Because featured images are embedded as base64, export JSON files are often
10–20 MB. The **Import Events** screen therefore:

- displays the effective maximum upload size (`min(upload_max_filesize, post_max_size)`),
- validates the selected file client-side before submitting,
- redirects back with a clear error notice when PHP rejects an oversized POST
  (`post_max_size`) or the file exceeds `upload_max_filesize`.

Locally, `docker/php/uploads.ini` raises both limits to 64M (see
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
  Note this changes pre-1.4 behavior, where date-less events were imported as
  needs-review; they are now skipped outright.
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

### Error handling

- One failed event does not abort the source; one failed source does not abort the run.
- Fatal errors (source unreachable, HTTP errors) are reported per source with technical details.
- Image download failures keep the external URL as fallback meta (`_event_banner`) without failing the event.
- Per-source consecutive-failure counts are tracked informationally (Event Sources table / `wp conexao-events status`); sources are never auto-disabled.

## Event Meta (registered)

See `docs/content-model.md` for full meta field list. Key fields:

- `_event_source` / `_event_source_id` — deduplication key
- `_event_status` — visibility gate (published / needs_review / draft / expired / etc.)
- `_event_date` / `_event_time` / `_event_end_date` / `_event_end_time` — scheduling
- `_event_banner` / `_event_banner_attachment_id` — images
- `_event_url` — external ticket/info URL

## Taxonomy

- `conexao_town` — registered by this plugin, applied to events only

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
- `conexao_event_importer_run_source` — trigger single source import (sync)
- `conexao_event_importer_run_all` — trigger all sources import (sync; accepts args array with `dry_run`, `source_ids`)
- `conexao_event_importer_current_run_id` — expose current run ID
- `conexao_event_importer_get_handler` — register custom source handlers
- `conexao_event_image_download_args` — customize image download HTTP args

### Actions
- `pre_get_posts` — filter public event queries (`_event_status = published`)

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
- Settings — Eventbrite API token

## Known fixed pitfalls

- **meta_query OR-contamination (fixed):** the public status filter used to be appended flat into queries that already had a top-level `relation => 'OR'` meta_query (e.g. dedup lookups), producing `(url match) OR (published)` — matching every published event. The filter now nests existing clauses so the status constraint is always ANDed.
- **Legacy source type:** if a source's stored `type` doesn't match its handler (e.g. `website` instead of `icalendar`), the wrong parser runs silently. Verify type after editing URLs by hand in the DB.
- **Stale uploaded ICS:** an uploaded `.ics` file always overrides the feed URL. Clear the `ics_content` key to subscribe to the live URL again.

## Dependencies

- `conexao-data-model` (event CPT must exist)

## Files to Inspect First

- `includes/class-event-importer.php` — import engine, upsert, dry-run
- `includes/class-event-export.php` / `includes/class-event-import.php` — the local→production transfer (UUIDs + embedded images)
- `includes/class-event-sources.php` — source CRUD + dashboard