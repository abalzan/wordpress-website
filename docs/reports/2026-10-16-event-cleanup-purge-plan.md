# Plan — Event Importer Cleanup: purge all eligible expired events

> Plan per `docs/engineering-standard.md` §13.2. Work performed on branch `master`, baseline SHA `f9b2757728843aeee91a7728b97102b0bcb8fc78`. No production writes, no deploy, no Flutter/mobile repo involvement.

| | |
|---|---|
| **Task title** | Event importer cleanup: fix batch-limit skip, purge all eligible expired events locally |
| **Date** | 2026-10-16 |
| **Author / agent** | Cline (AI coding agent) |
| **Branch** | master |
| **Repository baseline (starting SHA)** | f9b2757728843aeee91a7728b97102b0bcb8fc78 |
| **Applicable skill(s)** | `wp-content-change`, `wp-write-in-process-test`, `wp-security-review`, `wp-testing` |
| **Plan status** | draft |

## 1. Scope

Fix the event importer cleanup (`Conexao_Event_Cleanup`) so it purges **every** eligible expired event in the local WordPress/Docker environment, not merely the first SQL batch. Then execute a verified cleanup (Plan → Dry-run → Snapshot → Apply → Verify → Gate) and add regression tests. No production changes.
## 2. Explicit non-goals

- Do **not** touch production, WordPress.com, or any production database/filesystem.
- Do **not** touch the Flutter/mobile repository.
- Do **not** invent a new retention policy or change cleanup ownership (stays importer-owned events; `cleanup_scope = imported_only` respected).
- Do **not** delete events merely because their start date is in the past; expiry = end date/time passed (via `Conexao_Event_Status::get_event_end_timestamp`).
- Do **not** add a cron/scheduler or second cleanup lifecycle.
- Do **not** change `plugins.json`, the plugin registry, or permanent gates.

## 3. Relevant authoritative documents

- `AGENTS.md` — safety rules, scope, plugins registry, workflow.
- `docs/engineering-standard.md` — mandatory engineering rules (§13.2 plan, §4.2 admin, §7 security, §8 testing).
- `docs/content-model.md` — event post type, `_event_date`/`_event_end_date`, `_event_imported`, stable identity.
- `docs/plugins/conexao-event-importer.md` — event importer purpose, local-only tooling.
- `docs/testing.md` — test runner, permanent invariant gates.
- `docs/development.md` — local Docker workflow, snapshot/apply convention.

## 3a. Skill workflow

| | |
|---|---|
| **Skill** | `wp-content-change` + `wp-write-in-process-test` + `wp-security-review` + `wp-testing` |
| **Preconditions** | Local Docker stack running (confirmed: `wordpress` + `db` containers up). PHP 8.5.4 available. Branch `master` clean except pre-existing `theme functions.php` change (preserved). |
| **Guardrails** | No production writes; local-only; `plugins.json` authoritative; fail-closed gates preserved; pre-existing working-tree change untouched. |
| **Verification commands** | `./scripts/run-tests.sh --only conexao-event-importer`, focused cleanup test, `./scripts/run-tests.sh` aggregate, `php -l`, permanent gates. |
| **Evidence location** | `docs/evidence/2026-10-16-event-cleanup-purge/` |

## 4. Current-state findings

Verified by running code against the local Docker WordPress:

- `Conexao_Event_Cleanup::find_past_events()` runs ONE `WP_Query` with `posts_per_page => 200` (`MAX_EVENTS_PER_RUN`), `no_found_rows => true`, **no `ORDER BY`/pagination**, and **no date comparison in SQL** — the meta_query only asserts `_event_date` exists and is non-empty. The end-date/time comparison happens in PHP **after** the SQL `LIMIT 200`.
- Local DB: 2080 events, 2093 with `_event_date` set, 1202 with `_event_date < 2026-10-16`. So up to ~1202 expired candidates exist but only the 200 most-recently-created are ever examined.
- Reproduction: ran `run_cleanup()` → reported 19 found / 19 deleted / 0 errors, but a post-run re-query still returned **1 eligible candidate** (`#20090 "Jobs & Training Fair"`, end 2026-10-15 13:00, imported) that the first batch skipped because it falls beyond the 200-row SQL window.
- `cleanup_scope = imported_only` (option `conexao_event_importer_settings`), so manually managed events (no `_event_imported`) are protected — confirmed working.
- The importer does **not** re-create/restore expired events: `Conexao_Event_Date_Filter` skips past events before upsert; the reappearing lifecycle only restores `source_not_found`, never `expired`/`draft`/`rejected`. So no re-import restoration path exists.
- Deletion reliability (hard-delete + `get_post_status` verify, idempotency, error reporting) from the prior fix `f9b2757` is present and works.
- Polylang (`lang => ''`) and status-gate (`suppress_filters`) opt-outs are present and work.

**Root cause:** the cleanup stops after the first 200-row SQL batch and never paginates, so eligible expired events beyond the 200 most-recently-created are never selected and never purged. Re-running does not help because the same 200-row window is re-fetched each time.

## 5. Proposed approach

**Fix `Conexao_Event_Cleanup::find_past_events()` to paginate through every event that carries `_event_date`, applying the exact same PHP eligibility check (`Conexao_Event_Status::get_event_end_timestamp` + weekly-recurrence guard) to each, and return the complete stable candidate list.**

- Read phase: paginate `WP_Query` in batches of `MAX_EVENTS_PER_RUN` (200) using `offset`, `fields => 'ids'`, `orderby post_date DESC`, priming the postmeta cache via `update_meta_cache('post', $batch)` so `get_post_meta` stays cache-hit efficient.
- Collect ALL eligible IDs into a stable list **before** any deletion, so deleting records cannot cause offsets to skip candidates.
- Delete phase (`run_cleanup`, unchanged): processes the full list, reports accurate found/deleted/skipped/failed counts, throws on deletion failure, idempotent on already-gone posts.
- Add cache invalidation: call `Conexao_Event_Query::flush_cache()` (existing mechanism) after a successful cleanup run so event archive views reflect the cleaned dataset.

Options considered and rejected:
- Add a SQL date range (`_event_date <= today`) — rejected: cannot exactly replicate `get_event_end_timestamp` (end_date + end_time, end-of-day fallback, `_event_time` parsing) in SQL; would risk misclassifying multi-day/timed events.
- Raise `MAX_EVENTS_PER_RUN` — rejected: fragile hard cap, still skips beyond it.
- Delete inside the pagination loop — rejected: changing result set mid-pagination can skip/duplicate; stable list first is safer.

## 6. Files expected to change

| Path | Change | Why |
|---|---|---|
| `wp-content/plugins/conexao-event-importer/includes/class-event-cleanup.php` | Paginate `find_past_events()` over all candidates; add `flush_cache()` invalidation after deletion | Root-cause fix + cache invalidation requirement |
| `wp-content/plugins/conexao-event-importer/tests/test-event-cleanup-purge.php` | Add multi-batch (>200) selection test, post-cleanup re-query test, stronger cache invalidation test | Regression coverage per task §5 |
| `docs/reports/2026-10-16-event-cleanup-purge.md` | Final report (root cause, changes, purge execution, verification, production status) | Required report |
| `docs/evidence/2026-10-16-event-cleanup-purge/` | Machine evidence (dry-run plan, snapshot, apply result, verification, gate output) | Required evidence |

## 7. Files explicitly expected NOT to change

- `plugins.json` — plugin set/registry unchanged; permanent gates preserved.
- `wp-content/plugins/conexao-event-runtime/` — event status/query/recurrence logic reused, not modified.
- `wp-content/themes/conexao-br-irlanda/functions.php` — pre-existing working-tree change (language switcher flag), preserved untouched.
- Flutter/mobile repository — out of scope, never touched.

## 8. Content / data impact

- Records deleted: only locally eligible expired importer-owned events (end date/time passed), in the local Docker DB. Estimated from current DB: up to ~1202 with past `_event_date`, final count determined by dry-run.
- Records preserved: today/future events, ongoing multi-day, active recurring series, no-date/malformed-date events, manually managed events (scope `imported_only`).
- Portable identifier strategy: post IDs are local only; no local IDs used cross-environment. Purge is local-only.

