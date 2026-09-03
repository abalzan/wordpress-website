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
| `Conexao_Event_Recurrence` | Internal recurrence model + evaluator (`_event_recurrence*` meta, `occurs_on_date()`, `next_occurrence()`). Consumed by the public query helper below since Step 3 |
| `Conexao_Event_Query` | Public query/candidate helper for recurring events: SQL candidate widening → batch meta load → exact PHP evaluation via the evaluator → ordered ID list (`upcoming_event_ids()`), date-keyed transient cache. Consumed by the theme's event archive + secondary event surfaces (Step 3) |

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
path. The evaluator is intentionally invisible to editors; since Step 3 it
is consumed by the public query helper below.

## Public query integration (Step 3)

`Conexao_Event_Query` (`includes/class-event-query.php`) makes recurring
events participate in the existing event surfaces without occurrence posts:

1. **SQL candidate widening** — one lightweight `wpdb` query over-selects:
   every one-time event with `_event_date >= today` (the legacy archive
   restriction, untouched) plus every weekly series that can occur in the
   local `[today, today+7]` window (not ended before today; started no later
   than the window end). The public `_event_status` gate (published OR
   legacy no-status) is replicated in SQL. No `FIND_IN_SET()`, no
   `WP_Date_Query` weekday logic, no new tables.
2. **Batch meta load** — `update_meta_cache()` loads all postmeta for the
   candidates in a single query; the evaluator then runs from cache.
3. **Exact PHP evaluation** — `next_occurrence()` decides per candidate.
4. **Ordered ID list** — sorted by next occurrence ascending (ties by post
   ID); returned by `upcoming_event_ids()` / `upcoming_events()` (ID ⇒
   next occurrence date).

Semantics: weekly events enter the result set when they have an occurrence
on/after the current local date within the 7-day window (an active weekly
series always repeats within 7 days, so nothing active is missed). One-time
events keep their existing "today or later" behavior — strictly additive.

Consumers: the theme calls `conexao_event_upcoming_ids()` (null when the
plugin is inactive → legacy query path preserved) in
`conexao_content_archive_query()` (main `/eventos/` query, fed via
`post__in` + `orderby => post__in` so pagination, load-more, the
`?cidade=`/`?categoria=` tax filters and the status gate all keep operating
on real event posts) and in `template-parts/hero-events.php`,
`front-page.php`, `404.php` and `page-landing.php`. Event cards show the
next occurrence via `conexao_event_display_date()`.

**Cache**: a single transient keyed by the site-local calendar date
(`conexao_event_upcoming_YYYYMMDD`), expiring at the next local midnight —
results are stable all day and the day rollover naturally rebuilds the set.
`Conexao_Event_Query::flush_cache()` is called by the theme's
`conexao_homepage_cache_invalidate()` whenever an event is saved/deleted.
No cron, no persistent scheduler.

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

`tests/test-event-query.php` — public query/candidate helper tests
(`Conexao_Event_Query`, Step 3):

```bash
docker compose exec wordpress php /var/www/html/wp-content/plugins/conexao-event-runtime/tests/test-event-query.php
```

Covers: one-time events in/out of the list, weekly active / not-started /
ended / twice-weekly / open-ended / weekday-mismatch series, the status
gate, single-appearance of each event, next-occurrence sorting, the
`post__in` + `orderby => post__in` pattern (including `tax_query`
narrowing and the empty-list `array( 0 )` case), and the date-keyed
transient cache + flush.

Local test data: `scripts/seed-recurrence-test-events.php` seeds the
9-event Step 3 test matrix (prefixed `[REC-TEST]`, weekdays relative to
the current local day); run it with the `cleanup` argument to remove.

If WP-CLI is not available in the container, plugin activation can be toggled
directly through the `active_plugins` option, e.g.:

```bash
docker compose exec wordpress php -r 'require "/var/www/html/wp-load.php"; $p = get_option("active_plugins"); $p = array_values(array_diff($p, ["conexao-event-importer/conexao-event-importer.php"])); update_option("active_plugins", $p);'
```
