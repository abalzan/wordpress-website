# Conexão BR Irlanda — Event Runtime

- **Path**: `wp-content/plugins/conexao-event-runtime/`
- **Version**: 1.0.0
- **Requires Plugins**: `conexao-data-model`
- **Purpose**: **Production dependency.** Owns all event runtime behavior the live site needs: event meta registration, the `conexao_town` taxonomy, the `_event_status` visibility gate on public event queries, and the event status admin UI. Contains **no** import/export tooling.

## Why this plugin exists

The Event Importer plugin used to own both the local import machinery **and**
the production-critical event runtime behavior (meta/taxonomy registration,
the `_event_status` public query gate, the status admin UI). That made
deactivating the importer on production unsafe: expired and
source-not-found events are WordPress `publish` posts that are only hidden
through the `_event_status` gate, so removing the gate would expose stale
events.

The runtime is now a separate plugin so production can run:

```
PRODUCTION (required):
  data-model + content + admin-ux + event-runtime

LOCAL (full setup):
  data-model + content + admin-ux + event-runtime + event-importer
```

Dependency direction is strictly **Importer Tools → Runtime** (never the
reverse): the importer declares `Requires Plugins: conexao-data-model,
conexao-event-runtime`, and it refuses to boot if the runtime's
`Conexao_Event_Status` class is not available.

## Responsibilities

| Area | Detail |
|---|---|
| Event meta | Registers all `_event_*` meta (including `_event_status` and the internal `_event_recurrence*` group) on the `event` post type, REST-visible |
| Taxonomy | Registers `conexao_town` (Cidades) for events, rewrite slug `towns` |
| Public gate | `pre_get_posts`: all frontend event queries (main archive + secondary `WP_Query` calls) are constrained to `_event_status = published` OR no status (legacy events). Published upcoming → visible; expired / source_not_found / rejected / draft → hidden. Behavior is byte-for-byte identical to the old importer implementation |
| Status admin UI | Status/Source columns + `?event_status=` filter dropdown on the events list (Admin UX summary cards link to these URLs), and the `conexao_event_status_box` meta box that Admin UX removes in favor of its own sectioned editor |
| `Conexao_Event_Status` | Status constants, get/set, legacy-default-to-published semantics, expiry marking, shared end-timestamp helper |
| `Conexao_Event_Recurrence` | Internal recurrence model + evaluator (`_event_recurrence*` meta, `occurs_on_date()`, `next_occurrence()`). Data model + evaluator only — not visible to editors and not consumed by queries/templates yet |

The Admin UX plugin consumes the runtime via `class_exists('Conexao_Event_Status')`
for event status get/set — that relationship is preserved.

## Non-goals

- No source fetching, HTTP clients, scrapers, or normalizers
- No import/export/transfer/cleanup UI or processors
- No CLI, no import logs, no source health, no Eventbrite configuration
- No cron (importing and cleanup remain manual and local-only)
- No data migration, no content changes on activation

## Activation / Deactivation

- **Activate**: registers meta + taxonomy and flushes rewrite rules. Nothing is deleted, imported, scheduled, or migrated.
- **Deactivate**: flushes rewrite rules; all event data is preserved. ⚠️ Deactivating on production removes the `_event_status` gate — keep this plugin active on production.

## Production Activation / Migration Plan

For an existing production site that still has the old combined importer:

1. Deploy/upload `conexao-event-runtime.zip` (runtime) and the updated `conexao-event-importer.zip`.
2. Activate **Event Runtime** first. Verify: `/eventos/` archive filters correctly, event editor status UI works, no PHP errors.
3. The importer may stay active during the transition — both running together is fully supported (that is exactly the local configuration).
4. Deactivate (and optionally delete) the Event Importer plugin. Public event behavior is unchanged because the runtime now owns the gate.

At no point is there a gap where the old importer is disabled and the runtime is not active: WordPress blocks deactivating the runtime while the importer is active thanks to the `Requires Plugins` header, and step 2 activates the runtime before any importer deactivation.

## Rollback

If anything goes wrong after deactivating the importer on production, re-activate
the importer (it is a no-op for public behavior) or roll the code release back.
The runtime plugin does not modify any data, so rollback is safe at any point.

## Recurrence model (internal — Step 1)

The runtime registers four internal `_event_recurrence*` meta keys on the
`event` post type. Step 1 ships the data model + a reusable evaluator only:
no admin UI, no archive/query changes, no occurrence posts, no REST routes,
no migration/backfill. Existing events without these keys behave exactly as
before.

| Meta key | Allowed values | Notes |
|---|---|---|
| `_event_recurrence` | `''` (one-time) or `weekly` | Empty/unsupported value = legacy one-time behavior |
| `_event_recurrence_days` | CSV of ISO weekdays `1` (Mon) … `7` (Sun), e.g. `3`, `1,3` | Invalid/empty entries are discarded |
| `_event_recurrence_start` | `Y-m-d` | Optional; falls back to `_event_date` when blank. Invalid = series undefined |
| `_event_recurrence_end` | `Y-m-d` | Optional; blank or invalid = open-ended |

Evaluator: `Conexao_Event_Recurrence` (`includes/class-event-recurrence.php`)

- `occurs_on_date( int $post_id, DateTimeImmutable $date ): bool` — true when
  the event occurs on the requested local calendar date. One-time events keep
  the exact `_event_date === requested local calendar date` comparison.
- `next_occurrence( int $post_id, DateTimeImmutable $from ): ?DateTimeImmutable`
  — next occurrence on/after `$from` (one-time: `_event_date` when on/after;
  weekly: scan of the next 7 calendar days). Returns `null` when the series
  has ended.

All arithmetic is calendar-day based in the WordPress timezone
(`wp_timezone()` + `DateTimeImmutable`, aligned with `current_datetime()`):
no UTC timestamps and no `strtotime()`/`date()`+`time()` in the recurrence
path. The evaluator is intentionally invisible to editors and to public
queries.

## Tests

`tests/test-plugin-separation.php` — run in both configurations from the
project root:

```bash
# Runtime-only (importer deactivated):
docker compose exec wordpress wp --allow-root plugin activate conexao-event-runtime
docker compose exec wordpress wp --allow-root plugin deactivate conexao-event-importer
docker compose exec wordpress php /var/www/html/wp-content/plugins/conexao-event-runtime/tests/test-plugin-separation.php no-tooling

# Runtime + tooling (local):
docker compose exec wordpress wp --allow-root plugin activate conexao-event-importer
docker compose exec wordpress php /var/www/html/wp-content/plugins/conexao-event-runtime/tests/test-plugin-separation.php with-tooling
```

Covers: runtime availability without tooling, tooling loading only with runtime,
retired sources still removed, past-event import filter, the full public-gate
matrix (upcoming / expired / source_not_found / dateless / invalid-date /
draft / rejected / legacy-no-status), Admin UX read/write via the runtime
class, and no cron hooks.

`tests/test-event-recurrence.php` — internal recurrence model/evaluator tests
(run in the current configuration, no plugin toggling required):

```bash
docker compose exec wordpress php /var/www/html/wp-content/plugins/conexao-event-runtime/tests/test-event-recurrence.php
```

Covers: meta registration, legacy one-time compatibility, weekly weekday
selection, inclusive start/end boundaries, open-ended and invalid-date
defensive handling, malformed weekday CSV, leap years, DST transitions,
timezone-sensitive calendar dates, and `next_occurrence()` for one-time /
weekly / ended-series events. Public behavior is untouched.

If WP-CLI is not available in the container, plugin activation can be toggled
directly through the `active_plugins` option, e.g.:

```bash
docker compose exec wordpress php -r 'require "/var/www/html/wp-load.php"; $p = get_option("active_plugins"); $p = array_values(array_diff($p, ["conexao-event-importer/conexao-event-importer.php"])); update_option("active_plugins", $p);'
```
