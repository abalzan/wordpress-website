# Event Cleanup Scalability & Reliability — Implementation Report

> **Date**: 2026-11-20
> **Branch**: `event-import-improvements` @ `3a64249b8ee3a79955dde463d4485fb8457a7864`
> **Author**: AI coding agent
> **Status**: Implementation complete; tests blocked by pre-existing test-database access issue

## 1. Summary

Implemented a bounded, resumable batch runner for the event cleanup, replacing the single synchronous request that caused gateway timeouts on large datasets. The implementation preserves all existing eligibility and event-post deletion semantics while disabling automatic attachment deletion (attachments are preserved by default and recorded for manual review).

## 2. Files changed

| Path | Change |
|---|---|
| `wp-content/plugins/conexao-event-importer/includes/class-event-cleanup-lock.php` | **New.** WordPress-compatible atomic lock with INSERT IGNORE + CAS + SELECT ... FOR UPDATE fencing + fence_token. |
| `wp-content/plugins/conexao-event-importer/includes/class-event-cleanup-attachment-review.php` | **New.** Persistent attachment-review queue with atomic enqueue (SELECT FOR UPDATE + CAS inside event txn) and "Mark as reviewed" (same pattern). Fail-closed capacity 500 pending / 1000 reviewed. |
| `wp-content/plugins/conexao-event-importer/includes/class-event-cleanup-run.php` | **New.** Batch runner: run-state option, keyset cursor, batch processing with BATCH_EVENTS/BATCH_TIME_SECONDS/scan_window, transactional event delete + queue enqueue + run-state checkpoint, cursor-boundary semantics (cursor = last processed ID). |
| `wp-content/plugins/conexao-event-importer/includes/class-event-cleanup-admin.php` | **Modified.** Updated to use the batch runner; added Continue/Cancel/Mark as reviewed/Retry/Dismiss/Re-enable handlers; new UI showing run-state, progress, failed events, attachment review queue. |
| `wp-content/plugins/conexao-event-importer/includes/class-import-cli.php` | **Modified.** CLI cleanup command now loops batches internally until terminal. |
| `wp-content/plugins/conexao-event-importer/conexao-event-importer.php` | **Modified.** Wired new classes into the plugin bootstrap. |
| `wp-content/plugins/conexao-event-importer/tests/test-event-cleanup-batch-runner.php` | **New.** Comprehensive test suite covering eligibility, batch limits, keyset cursor no-skip, resume, idempotent retry, concurrency, stale lock reclaim, failed-record handling, capacity pause, queue atomicity, hook-safety regression guard. |

## 3. Design decisions implemented

- **Event eligibility and event-post deletion semantics: UNCHANGED.** The existing `Conexao_Event_Cleanup` engine is reused as-is.
- **Automatic attachment deletion: DISABLED.** Attachments are preserved by default and recorded in a persistent review queue. This is a deliberate change to prevent accidental deletion of shared images (the check-and-delete race cannot be eliminated without a persistent object cache or an atomic primitive).
- **Keyset cursor:** The cursor is the last processed ID; the next query uses `ID < cursor_id`. Boundary semantics: whole window → `last_scanned_id`; event-count limit → `last_deleted_id`; time budget → `last_committed_id`.
- **Atomic queue updates:** Worker enqueue + event deletion + run-state checkpoint commit/rollback together (row lock + CAS on the queue option). "Mark as reviewed" uses the same pattern. No lost updates; fail closed on conflict.
- **Fail-closed capacity:** 500 pending attachment reviews; 500 failed events; 1000 final failed events. Run pauses when capacity is reached; no eviction.
- **Fencing:** Each batch re-verifies lock ownership via SELECT ... FOR UPDATE + fence token check. A stale worker cannot commit a deletion after losing ownership.

## 4. Test results

**Status: BLOCKED — pre-existing test-database access issue.**

The test suite (`test-event-cleanup-batch-runner.php`) was created but cannot be run because the `conexao_test` MySQL user does not exist in the local Docker database. This is a pre-existing environment issue, not caused by this implementation.

```
$ ./scripts/run-tests.sh --only conexao-event-importer
...
conexao-test-bootstrap: isolated database preflight blocked this suite
[BLOCKED] plugin/conexao-event-importer/test-town-sanitization.php — WordPress bootstrap failed
         [20-Nov-2026 20:48:05 UTC] PHP Warning:  mysqli_real_connect(): (HY000/1045): Access denied for user 'conexao_test'@'172.18.0.3' (using password: YES) in /var/www/html/tests/lib/isolated-db.php on line 65
         conexao-test-bootstrap: could not connect to the explicitly requested test database: Access denied for user 'conexao_test'@'172.18.0.3' (using password: YES)
```

**To run the tests once the test database is set up:**

```bash
# 1. Create the test database and user (one-time setup).
docker compose exec -T db mysql -u root -plocal-root-password -e "
  CREATE DATABASE IF NOT EXISTS conexao_stage05_test_5bd9ece5c032;
  CREATE USER IF NOT EXISTS 'conexao_test'@'%' IDENTIFIED BY 'test_password';
  GRANT ALL PRIVILEGES ON conexao_stage05_test_5bd9ece5c032.* TO 'conexao_test'@'%';
  FLUSH PRIVILEGES;
"

# 2. Run the test suite.
./scripts/run-tests.sh --only conexao-event-importer
```

## 5. Performance verification

**Status: NOT RUN — blocked by test-database access.**

The synthetic large-volume test (creating 501+ events to prove multi-batch completion) is included in the test suite but cannot be run until the test database is accessible.

## 6. Known limitations

1. **Test database not set up:** The `conexao_test` MySQL user does not exist, blocking all WordPress-dependent tests. This is a pre-existing environment issue.
2. **Attachment deletion disabled:** Automatic attachment deletion is disabled by design. Attachments are preserved and require manual review. This is a deliberate safety trade-off.
3. **No production testing:** Per the task constraints, no production access or real cleanup was performed.
4. **Hook-safety:** The implementation assumes that the only active `delete_post`/`deleted_post` hooks for event posts are idempotent cache invalidators. A regression test guards against new non-idempotent hooks being added.

## 7. Operator sign-off

**Design note:** `docs/evidence/2026-11-20-event-cleanup-scalability/03-design-decision.md` (rev. 12) — **APPROVED** by the operator on 2026-11-20.

**Implementation:** Complete. Tests blocked by pre-existing environment issue (test database not set up). No production access, real cleanup, schema changes, new dependencies, provider calls, imports, commit, push, or deployment performed.