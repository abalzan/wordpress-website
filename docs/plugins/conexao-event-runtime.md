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
| Event meta | Registers all `_event_*` meta (including `_event_status`) on the `event` post type, REST-visible |
| Taxonomy | Registers `conexao_town` (Cidades) for events, rewrite slug `towns` |
| Public gate | `pre_get_posts`: all frontend event queries (main archive + secondary `WP_Query` calls) are constrained to `_event_status = published` OR no status (legacy events). Published upcoming → visible; expired / source_not_found / rejected / draft → hidden. Behavior is byte-for-byte identical to the old importer implementation |
| Status admin UI | Status/Source columns + `?event_status=` filter dropdown on the events list (Admin UX summary cards link to these URLs), and the `conexao_event_status_box` meta box that Admin UX removes in favor of its own sectioned editor |
| `Conexao_Event_Status` | Status constants, get/set, legacy-default-to-published semantics, expiry marking, shared end-timestamp helper |

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

If WP-CLI is not available in the container, plugin activation can be toggled
directly through the `active_plugins` option, e.g.:

```bash
docker compose exec wordpress php -r 'require "/var/www/html/wp-load.php"; $p = get_option("active_plugins"); $p = array_values(array_diff($p, ["conexao-event-importer/conexao-event-importer.php"])); update_option("active_plugins", $p);'
```
