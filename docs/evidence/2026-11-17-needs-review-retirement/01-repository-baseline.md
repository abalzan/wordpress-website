# Stage 1 — Repository baseline (Needs Review retirement + local event cleanup)

**Date:** 2026-11-17
**Task:** Retire the Needs Review feature; permanently delete only local event records that have neither a usable county nor a usable event image.

## Branch / HEAD / worktree (captured at task start)

| Field | Value |
|---|---|
| Branch | `event-import-improvements` |
| HEAD commit | `e44487c Add date-only write contract and review queue for event posts` |
| Worktree (`git status --short`) | CLEAN — no modified, staged, untracked, or conflicted paths |
| Pre-existing changes in `docs/evidence/2026-11-15-stage-3-6/` | none (directory unmodified, clean) |
| Expected branch confirmed | YES |

## Authorised scope (per task)

- Remove Needs Review admin page registration, renderer, menu entry
- Remove `Conexao_Event_Review_Queue` class/service and its loader/require wiring
- Remove queue-only hooks/routes/assets; delete obsolete queue-only file if unreferenced
- Preserve: Event Import top-level menu, Event Sources submenu/page, importer, source registry, location queries, county extraction, image extraction, import history, operator logs, continuation coordination
- Delete only local development DB event records with (no usable county AND no usable image)
- Database backup before deletion; post-deletion verification; evidence + docs updates

## Preservation

No pre-existing worktree modifications or untracked files exist, so there is nothing to preserve beyond the baseline. No commit, push, deploy, or stash will be performed by this task.

## Local development database (proven local — see 02-local-database-identity.md)

| Fact | Value |
|---|---|
| Compose project | `wordpress-website` |
| Compose config file | `/home/andrei/IdeaProjects/wordpress-website/compose.yaml` |
| DB service | `db` |
| DB container | `wordpress-website-db-1` (image `mysql:latest`, healthy) |
| Compose project label | `com.docker.compose.project=wordpress-website` |
| Compose network | `wordpress-website_default` |
| WordPress container | `wordpress-website-wordpress-1` |
| `$wpdb->dbname` | `wordpress` |
| `$wpdb->dbhost` | `db:3306` |
| `DATABASE()` | `wordpress` |
| `CURRENT_USER()` | `wordpress@%` |
| `@@hostname` | `831e69426461` (matches DB container ID prefix `831e69426461dbcb...`) |
| `@@port` | `3306` |
| Disposable test schema | `conexao_stage05_test_5bd9ece5c032` — NOT the target |
