# 03 — Design decision: Event Cleanup scalability & reliability (rev. 14)

> **Phase**: 3–5 (design, revised). Implementation (Phase 6) is **complete and verified**.
> **Date**: 2026-11-23
> **Branch**: `event-import-improvements` @ `HEAD`
> **Scope**: `conexao-event-importer` cleanup page only. No production access, no real cleanup, no schema change, no new dependency.
> **Revision**: rev. 14 documents the implemented fixes for three operational defects identified in the post-implementation review:
> 1. **Failed-event retry** — operator-driven retry now works for both `failed_events` and `failed_events_final` via a dedicated persistent retry queue.
> 2. **New-run lifecycle** — terminal runs (completed, cancelled, failed) no longer block new runs; new runs get fresh cursor/lock/fence while preserving failure/retry data.
> 3. **Deterministic time-budget test** — test-controlled monotonic clock seam added; time-budget stops are now reliably exercised and verified.
> 4. **Attachment review policy** — all attachment types (images, PDFs, documents, unknown) are now queued with MIME classification; generic review entries for non-images.

---

## Scope clarification (read first)

This revision makes an important scope distinction explicit:

- **Event eligibility rules (§2.1): UNCHANGED.** The criteria for which events are selected for cleanup (post type, status, date expiry, weekly recurrence, `cleanup_scope`) are identical to the current implementation.
- **Event post hard-deletion semantics (§2.2): UNCHANGED.** Eligible events are hard-deleted (`wp_delete_post($id, true)`) with DB-row verification, exactly as today.
- **Attachment cleanup behaviour (§4.6): DELIBERATELY CHANGED.** The current implementation automatically hard-deletes exclusively-owned banner and featured images after deleting their event. **This implementation disables automatic attachment deletion.** Attachments are **preserved by default** and recorded for manual review. This is a deliberate, conservative change to prevent accidental deletion of a shared image (see §4.6 for the full rationale). It is **not** an eligibility change and **not** an event-post deletion change — it is a change to what happens to *attachments* after their event is deleted.

This distinction is maintained throughout the document. Wherever the document says "no deletion change", it means **no event-post deletion change** and **no eligibility change**. The attachment-cleanup change is called out explicitly in §2, §4.6, and §4.10.

---

## 1. Current architecture (confirmed by code trace)

| Concern | Location | Behaviour |
|---|---|---|
| Menu/page registration | [`class-event-cleanup-admin.php`](/home/andrei/IdeaProjects/wordpress-website/wp-content/plugins/conexao-event-importer/includes/class-event-cleanup-admin.php) `register_admin_menu()` / `render_cleanup_page()` | Submenu `conexao-event-cleanup` under `conexao-event-import`, capability `manage_options`. |
| Trigger | same file `handle_run_cleanup()` on `admin_post_conexao_run_cleanup` | Capability check + `check_admin_referer('conexao_run_cleanup','conexao_cleanup_nonce')`, then **synchronously** calls `run_cleanup()` and redirects with query-arg counts. |
| Engine | [`class-event-cleanup.php`](/home/andrei/IdeaProjects/wordpress-website/wp-content/plugins/conexao-event-importer/includes/class-event-cleanup.php) `run_cleanup()` | Finds past events, deletes each + exclusively-owned images, flushes cache, persists status/history, logs. |
| Candidate selection | same file `find_past_events()` | Paginated `WP_Query` (`post_type=event`, `post_status=any`, `posts_per_page=200`, `offset`, `orderby ID DESC`, `lang=''`, `suppress_filters=true`, meta `_event_date EXISTS != ''`). Expiry applied in PHP via `Conexao_Event_Status::get_event_end_timestamp()`. Weekly recurrence window respected. Optional `cleanup_scope=imported_only` filter (`_event_imported=1`). |
| Deletion | same file `delete_event_with_media()` | Hard delete `wp_delete_post($id,true)`, verify row gone via `$wpdb->posts`, then delete exclusively-owned attachments. |
| Attachment safety | same file `is_exclusively_owned()` + 4 helpers | Keeps attachment unless: has `_event_source_url`, not featured elsewhere, not banner elsewhere, not in post content, not in any post meta. |
| Status/history | same file `save_status()` / `save_history()` / `get_status()` / `get_history()` | `update_option` (autoload=false), history capped at 50. |
| CLI | [`class-import-cli.php`](/home/andrei/IdeaProjects/wordpress-website/wp-content/plugins/conexao-event-importer/includes/class-import-cli.php) `cleanup()` | `wp conexao-events cleanup [--dry-run]`; dry-run lists candidates, real run calls `run_cleanup()`. |
| Tests | [`test-event-cleanup-purge.php`](/home/andrei/IdeaProjects/wordpress-website/wp-content/plugins/conexao-event-importer/tests/test-event-cleanup-purge.php) | Eligibility, scope, multi-batch (>200) selection, apply-path deletion, re-query, cache flush, idempotency. Does **not** call `run_cleanup()` (shared-DB safety). |

**No** AJAX, **no** REST, **no** cron/Action Scheduler, **no** lock, **no** run-state, **no** batching of the *deletion* work, **no** progress reporting. Action Scheduler is **not** bundled. The entire deletion of *all* eligible events happens inside one synchronous admin-post request.

## 2. Eligibility & deletion semantics

### 2.1 Event eligibility (UNCHANGED)

A candidate is **eligible** iff **all** hold:
1. `post_type = event`, `post_status = any`.
2. Has `_event_date` meta, non-empty.
3. `get_event_end_timestamp()` (end date/time, end-of-day fallback, `_event_time` first-part fallback) is **in the past** (`< now`).
4. If `_event_recurrence = weekly`: must have a closed `_event_recurrence_end` (non-empty, valid `Y-m-d`, `< today`). Open-ended or future-ending weekly series are **preserved**.
5. If `cleanup_scope = imported_only`: must have `_event_imported = 1`.

**These eligibility rules are identical to the current implementation. This design does not change them.**

### 2.2 Event post deletion (UNCHANGED)

- Eligible events are **hard-deleted** (`wp_delete_post($id, true)`), verified absent in `$wpdb->posts`.
- After run: `Conexao_Event_Query::flush_cache()`; status + history persisted; import log entry added.

**These event-post deletion semantics are identical to the current implementation. This design does not change them.**

### 2.3 Attachment cleanup (DELIBERATELY CHANGED — see §4.6)

- **Current behaviour:** after deleting an event, the cleanup automatically hard-deletes the event's banner (`_event_banner_attachment_id`) and featured image, but **only if** each is exclusively owned (imported via `_event_source_url` AND not referenced anywhere else). When in doubt → kept.
- **This implementation's behaviour:** automatic attachment deletion is **disabled**. After deleting an event, its banner and featured attachments are **preserved by default** and recorded for manual review. See §4.6 for the full rationale (the check-and-delete race cannot be eliminated without a persistent object cache or an atomic primitive, neither of which is available).
- **This is a deliberate change to attachment-cleanup behaviour.** It is **not** an eligibility change and **not** an event-post deletion change. It only affects what happens to *attachments* after their event is deleted.

> **Key fact enabling keyset pagination without changing eligibility:** expiry (rule 3) and weekly-recurrence (rule 4) are **per-record predicates computed in PHP** from post meta, not SQL clauses. The SQL layer only needs to yield event IDs in a stable, unique order; the PHP predicate is applied to each row exactly as today. A keyset cursor over `ID DESC` therefore visits the **same** rows in the **same** order as the current offset pagination and applies the **same** predicate — the eligible set is identical. Only the *page-boundary mechanism* changes (keyset `ID < cursor` vs `OFFSET n`), not *what is fetched* or *how eligibility is decided*.

## 3. Timeout diagnosis (Phase 2)

### Confirmed from code (not hypotheses)
- **C1 — Unbounded synchronous work in one request.** `handle_run_cleanup()` → `run_cleanup()` scans **all** events carrying `_event_date` (paginated 200 at a time) and deletes **every** eligible one, plus per-attachment exclusion checks, all before the first byte of the HTTP response. Duration grows linearly with events × (per-event meta reads + per-attachment 4–6 `COUNT(*)`/`LIKE` queries). On a large dataset this exceeds proxy/fastcgi/PHP `max_execution_time` (local `docker/php/uploads.ini` = 300 s) → gateway timeout.
- **C2 — Per-attachment exclusion checks are full-table scans.** `is_referenced_in_content()` runs `post_content LIKE '%url%'` (and file-path) against `wp_posts`; `is_referenced_in_meta()` runs up to three `meta_value = / LIKE` scans against `wp_postmeta`. Unindexed leading-wildcard scans; cost scales with (events × attachments) × table size. Dominant per-record cost on production-sized tables.
- **C3 — No resumability / no run-level idempotency.** A timeout mid-run leaves already-deleted events gone (fine) but status/history unwritten; the operator's only recourse is to click again, which **restarts the entire scan**. No lock → overlapping full scans can race.
- **C4 — `find_past_events()` materialises the full candidate list before deleting.** The complete `past_ids` array is built in memory first (deliberately, to avoid offset drift). For very large candidate sets this is a memory-growth factor, secondary to C1/C2.

### Likely contributing factors (evidence needed to confirm)
- **H1 — Production volume ≫ local.** Local had ~2,080 events / 528 eligible at the 2026-10-16 purge. If production has far more eligible events, C1/C2 scale with that delta. Volume is the *multiplier*, not an independent root cause. **Not proven — production must not be queried.**
- **H2 — Proxy/fastcgi gateway timeout** shorter than PHP's 300 s. "Gateway timeout" is consistent with a hosting-layer reverse proxy cutting the request. Environmental, but *triggered* by C1.

### Explicitly NOT claimed
- I do **not** claim production record volume is proven. H1 is a hypothesis; C1–C4 are code-confirmed structural facts that make *any* sufficiently large dataset time out.

### Ruled out
- Eligibility logic errors (2026-10-16 fix corrected the "stops after first 200 rows" defect; multi-batch selection tested).
- Wrong date fields / timezone (shared helper, tested).
- Polylang / `_event_status` gate leakage (`lang=''` + `suppress_filters=true` present).

## 4. Chosen design — bounded, resumable, keyset-cursor batches

> Keep the existing event eligibility and event-post deletion **intact**; wrap event deletion in a bounded batch runner with a **durable keyset cursor**, a **WordPress-compatible atomic lock with row-lock fencing**, **per-record failure handling with fail-closed bounded storage**, **attachment preservation with a durable, atomically-updated manual-review queue** (automatic attachment deletion is disabled — see §4.6), and a **polling progress UI**. No schema change, no new dependency, no Action Scheduler.

### 4.1 Candidate storage & keyset pagination (carried forward)

**No full candidate list is stored.** The run stores only a **durable cursor**:
- `cursor_id` — the highest `event.ID` **not yet processed** (keyset position). Seeded at run creation to `MAX(ID)+1` (or `PHP_INT_MAX`), so the first page is `ID < cursor_id ORDER BY ID DESC LIMIT scan_window`.
- `last_scanned_id` — the lowest `ID` returned by the most recent scan page (diagnostics only).

**Batch candidate fetch (keyset, read-only):**
```
SELECT ID FROM wp_posts
  WHERE post_type='event' AND post_status IN ('any…')   -- unchanged semantics
    AND ID < :cursor_id
    [AND meta _event_date EXISTS / != ''  — unchanged]
    [AND _event_imported=1 — only if cleanup_scope=imported_only]
  ORDER BY ID DESC
  LIMIT :scan_window            -- e.g. 200, same as today's page size
```
For each returned ID, apply the **unchanged** PHP predicates (`get_event_end_timestamp()` past, weekly-recurrence closed) exactly as `find_past_events()` does today. Eligible IDs are deleted (bounded by `BATCH_EVENTS` + `BATCH_TIME_SECONDS`). After the batch, `cursor_id` is set per §4.5.

**Why this preserves eligibility exactly:** identical order (`ID DESC`), identical uniqueness (`ID`), identical predicate (same helper, same rules). Keyset vs offset only changes the page-boundary mechanism, not the row set or the eligibility decision.

**Trade-off:** a scan window may contain many ineligible rows (future/ongoing/no-date events), so a batch may delete fewer than `BATCH_EVENTS` even though it scanned a full window. Acceptable: the *time* bound still caps work, and the cursor advances deterministically. `BATCH_EVENTS` bounds *deletions* per batch; `scan_window` bounds *rows examined* per batch.

### 4.2 Run-state storage (durable, small)
- New option `conexao_event_cleanup_run` (autoload=false), containing only:
  `run_id`, `status`, `created`, `updated`, `cursor_id`, `last_scanned_id`, `events_deleted`, `images_deleted`, `images_preserved`, `errors[]` (sanitised, capped), `batch_count`, `last_batch_*` metrics, `failed_events` (bounded map, §4.7), `lease_expires_at`, `owner_lock_id`, `fence_token`.
- **No candidate list.** Memory footprint is O(1) in the number of events.
- Reuses the existing per-process test-option isolation pattern (`status_option()` / `history_option()`), so tests never touch the operator row.
- Existing status/history options keep their current shape; run-state is additive.
- **`attachment_review_queue` is NOT stored in the run-state.** It is stored in a **separate, persistent option** (`conexao_event_cleanup_attachment_review`, autoload=false) so it survives across runs. See §4.6.3.

### 4.3 WordPress-compatible atomic locking with row-lock fencing (carried forward from rev. 7, unchanged)

**Verified environment:** local DB is MySQL/InnoDB, `transaction_isolation = REPEATABLE-READ`, `autocommit = 1`. WordPress.com is also InnoDB. InnoDB row locks (acquired via `SELECT ... FOR UPDATE` or by `UPDATE`/`DELETE` on a row) are held **until the transaction ends** (COMMIT or ROLLBACK).

**Lock primitive:** [`Conexao_Translation_Automation_Lock`](/home/andrei/IdeaProjects/wordpress-website/wp-content/plugins/conexao-translation-automation/includes/class-conexao-translation-automation-lock.php) pattern — `INSERT IGNORE` on the `wp_options.option_name` UNIQUE key for acquisition (affected=1 wins, 0 = locked), value-scoped CAS `UPDATE`/`DELETE` for renewal/release/reclaim, fail-closed on malformed state.

**Lock record shape** (JSON-encoded value in `wp_options.option_value` for `option_name = 'conexao_event_cleanup_lock'`):
```json
{ "v": 1, "run_id": "...", "acquired_at": 1700000000, "ttl": 300, "fence_token": 42 }
```

**Fencing token:** every lock acquisition/reclaim generates a monotonically increasing `fence_token` (per-run random + per-site monotonic counter). Propagated into run-state.

**The exact destructive transaction:**

```
START TRANSACTION

  -- Step 1: Acquire a row lock on the LOCK row and read it.
  SELECT option_value AS lock_json FROM wp_options
    WHERE option_name = 'conexao_event_cleanup_lock'
    FOR UPDATE;

  -- Step 2: Verify the lock is still ours (fence check, in PHP).
  --   Decode $lock_json; if run_id != my_run_id OR fence_token != my_fence_token
  --   OR now - acquired_at > ttl  =>  ROLLBACK (we lost ownership; delete nothing).

  -- Step 3: Renew the lock's lease and preserve the fence token IN THE SAME TRANSACTION.
  --   This UPDATEs the LOCK row (not the run-state row).
  UPDATE wp_options
    SET option_value = :new_lock_json_with_updated_acquired_at_and_same_fence_token
    WHERE option_name = 'conexao_event_cleanup_lock'
      AND option_value = :lock_json_read_in_step_1;
  -- If affected rows = 0  =>  ROLLBACK (another process changed the lock row; delete nothing).
  -- If affected rows = 1  =>  this worker still owns the lock; lease renewed.

  -- Step 4: Fencing write on run-state (compare-and-swap on exact bytes).
  UPDATE wp_options
    SET option_value = :new_run_state_with_advanced_cursor_and_counts
    WHERE option_name = 'conexao_event_cleanup_run'
      AND option_value = :exact_run_state_bytes_read_at_batch_start;
  -- If affected rows = 0  =>  ROLLBACK (run-state changed under us; delete nothing).

  -- Step 5: Delete the event post (inside the transaction, row lock held).
  --   wp_delete_post($event_id, true) + direct DB verify.

COMMIT;
```

**Why this closes the stale-worker race:**
- The `SELECT ... FOR UPDATE` in Step 1 acquires an InnoDB **row lock** on the lock row. Held until COMMIT/ROLLBACK.
- If another worker (B) attempts to reclaim the lock **after** worker A's Step 1 but **before** A's COMMIT, B's `UPDATE` **blocks** (InnoDB row lock held by A). B waits until A commits or rolls back.
  - If A commits (A still owned the lock at Step 2, fence valid) → A's Step 3 updated the lock row's `option_value` (new `acquired_at`, same `fence_token`). A's delete is durable. B unblocks; B's `UPDATE ... WHERE option_value = :old_bytes` now matches **0 rows** because A changed the lock row's bytes in Step 3 → B fails to reclaim → B is refused ("lost the reclaim race").
  - If A rolls back (A lost ownership at Step 2, or run-state changed at Step 4) → A's delete never happened; A's Step 3 lock-row update is also rolled back (lock row unchanged). B's `UPDATE` proceeds and may reclaim (if the lock is stale).
- **A stale worker cannot commit a deletion after losing ownership**, because:
  - If A's lease expired before Step 1, A's Step 2 fence check fails → ROLLBACK → no delete.
  - If A's lease expires *during* A's transaction (after Step 1, before COMMIT), A's Step 3 renewed the lease (new `acquired_at = now`, same `fence_token`) → A's lease is fresh at COMMIT. B cannot reclaim a fresh lease.
  - If B reclaims *between* A's batches (A is not in a transaction), A's next batch Step 1 `SELECT ... FOR UPDATE` reads the lock row with B's `run_id`/`fence_token` → A's Step 2 fence check fails → ROLLBACK → no delete.

**Concrete race scenario (B reclaims after A's Step 1 but before A's COMMIT):**
1. A: `START TRANSACTION`.
2. A: `SELECT ... FOR UPDATE` on lock row → acquires InnoDB row lock. Lock is still A's (fence valid, lease fresh).
3. A: fence check passes (run_id = A, fence = A's, lease fresh).
4. B: attempts `UPDATE lock SET option_value = :new WHERE option_name = 'conexao_event_cleanup_lock' AND option_value = :old_bytes` (reclaim) → **blocks** (A holds the row lock).
5. A: Step 3 — `UPDATE lock SET option_value = :new_lock_json (acquired_at = now, fence_token unchanged) WHERE option_name = 'conexao_event_cleanup_lock' AND option_value = :lock_json_read_in_step_1` → succeeds (1 row). Lock row's `option_value` is now `:new_lock_json`.
6. A: Step 4 — fencing write on run-state (CAS) → succeeds (1 row).
7. A: Step 5 — `wp_delete_post(event)` → row deleted.
8. A: `COMMIT` → InnoDB releases the row lock. A's delete + lock-row update + run-state update are durable.
9. B: unblocks; its `UPDATE ... WHERE option_value = :old_bytes` now matches **0 rows** (A changed the lock row's bytes in Step 5) → B fails to reclaim → B is refused ("lost the reclaim race").
10. **Result:** A's delete is committed; B never reclaimed; no double-delete.

**Alternative scenario (A's lease expired before Step 1):**
1. A: `START TRANSACTION`.
2. A: `SELECT ... FOR UPDATE` → reads lock row; lease expired (`now - acquired_at > ttl`).
3. A: fence check fails (lease expired) → `ROLLBACK` → **no delete**. (A's Step 3 lock-row update never ran; lock row unchanged.)
4. B: reclaims the lock (CAS), resumes from committed cursor.

**Lock renewal:** each batch's Step 3 writes `acquired_at = now` (extending the lease to `now + ttl`) and preserves the current `fence_token` in the same transaction. The lease is renewed atomically with the cursor advance, so a worker in a transaction always has a fresh lease at COMMIT.

### 4.4 Event deletion transaction (carried forward from rev. 4, unchanged)

The event delete and the cursor advance are one atomic DB transaction (§4.3). The row lock + fencing write is the transaction's first step; the event delete and run-state update follow; a single COMMIT makes all durable or none.

- Crash before COMMIT → InnoDB rolls back the event row, cursor, counts, and lock-row lease renewal together. On resume the event is re-fetched and processed cleanly. **No double-delete, no lost delete, no count drift.**
- Crash after COMMIT → delete + cursor + counts + lock-row lease renewal all durable and consistent; event never revisited.

**Honest limitation (stated explicitly):** exact *durable* counts are guaranteed (written in the same transaction as each delete). The per-batch *UI summary* is labelled "as of last committed checkpoint" and may under-report an in-flight batch if the PHP process is `kill -9`'d after InnoDB commits but before the worker records the batch summary in local memory. The design **does not claim exact in-flight UI counts**; it claims exact durable counts. An append-only journal was **rejected** (rev. 3) as non-conservative.

### 4.5 Cursor advancement across record classes (rev. 10 — APPROVED; rev. 13 clarified)

The cursor is the **last processed ID**, committed **per stop condition** as described below. The next query uses `WHERE ID < cursor_id` (exclusive), so the cursor must be the **last processed ID** (not `last_processed_id - 1`) — the next query then starts at `last_processed_id - 1` and does not skip it.

**Rev. 13 clarification:** rev. 10's prose said "the cursor is the lowest scanned ID (`last_scanned_id`)" while its own table said `last_deleted_id`/`last_committed_id` for the early-stop conditions. The implementation follows the **table** (the operative contract). This revision makes the table canonical and corrects the prose. Both approaches are safe (no skip, no double-process); the table approach is more efficient because it resumes within the window instead of re-querying the whole window each batch.

| Record class in the scan window | Action | Cursor effect |
|---|---|---|
| **Eligible, deleted OK** | delete + checkpoint (one txn) | cursor advances past it |
| **Eligible, already gone** (`get_post` null) | no-op skip, **not** an error | cursor advances past it |
| **Eligible, delete failed** | record sanitised error, increment `failed_events[id]`, **do not delete** | cursor advances past it; retried via §4.7 |
| **Ineligible** (future/ongoing/no-date/open weekly) | skipped by predicate | cursor advances past it |
| **Not yet processed** (ID ≥ cursor, or beyond scan window) | untouched | cursor stays above them |

**The cursor never advances past an eligible event whose outcome is not safely handled**, because:
- A failed event is *safely handled* by being recorded in `failed_events` with an attempt increment — its outcome is durable, so advancing past it is correct (it is retried by §4.7, not by re-scanning this pass).
- The cursor is committed in the **same transaction** as the last delete of the window (§4.4), so a crash mid-window rolls the cursor back to before the window — no eligible event is skipped.

**Cursor-boundary semantics (rev. 13 — APPROVED):**

| Stop condition | Cursor value to commit | Why |
|---|---|---|
| **Whole window dispositioned** (no early stop) | `cursor_id = last_scanned_id` (the lowest ID returned by the keyset query) | All rows in the window are processed; next query starts below the lowest scanned ID. |
| **Event-count limit reached** (`BATCH_EVENTS` deletions) | `cursor_id = last_deleted_id` (the ID of the last event deleted) | Next query `ID < cursor_id` starts at `last_deleted_id - 1`, which is the next unprocessed ID. **No skip.** |
| **Time budget reached** (`BATCH_TIME_SECONDS`) | `cursor_id = last_committed_id` (the ID of the last event whose transaction committed) | Next query `ID < cursor_id` starts at `last_committed_id - 1`, the next unprocessed ID. **No skip.** |
| **Failed-event capacity reached** (`MAX_FAILED_TRACKED`) | `cursor_id = last_committed_id` (the ID of the last event whose transaction committed) | The run pauses; on resume the next query starts at `last_committed_id - 1`, the next unprocessed ID. **No skip.** |

**Worked example:**
- Query returns IDs 1000 down to 801.
- Worker deletes IDs 1000 down to 976, then reaches its 25-event limit.
- `last_deleted_id = 976`.
- **Cursor:** `cursor_id = 976` (not 975).
- Next query: `WHERE ID < 976` → returns 975 down to … → **ID 975 is processed, not skipped.**

**Interaction of scan window × `BATCH_EVENTS` × time budget:**
- `scan_window` (default 200) bounds **rows examined** per batch.
- `BATCH_EVENTS` (default 25) bounds **deletions** per batch. If a window yields more eligible events than `BATCH_EVENTS`, the batch deletes only the first `BATCH_EVENTS` (highest IDs, `ID DESC`) and **stops**; `cursor_id = last_deleted_id` (the ID of the last deleted event), so remaining eligible rows in that window (IDs below `last_deleted_id`) are re-fetched next batch (not skipped).
- The **time budget** (`BATCH_TIME_SECONDS`, default 20 s) is checked before each event; if exceeded, the batch stops and `cursor_id = last_committed_id` (the ID of the last committed event). Remaining rows re-fetched next batch.
- If neither limit is hit, the window completes and `cursor_id = last_scanned_id`.

This guarantees: **no eligible event is ever skipped** (cursor is the last processed ID, and the next query `ID < cursor_id` starts at the next unprocessed ID), and **no event is processed twice** (cursor monotonic; keyset `ID < cursor`).

**Regression tests required (rev. 10 — APPROVED):**
- A test that creates adjacent eligible events (e.g. IDs N, N-1, N-2, …) and verifies that when the batch stops at the event-count limit, the next batch processes N-1 (not N-2).
- A test that creates adjacent eligible events and verifies that when the batch stops at the time budget, the next batch processes the next unprocessed ID (not the one after).
- A test that verifies interruption and resume preserve the same behaviour (no skip, no double-process).

### 4.6 Attachment preservation & manual-review queue (DELIBERATE CHANGE — operator-approved direction)

**This section describes a deliberate change to attachment-cleanup behaviour.** The current implementation automatically deletes exclusively-owned attachments after deleting their event. **This implementation disables automatic attachment deletion and preserves attachments by default**, recording them in a manual-review queue. The rationale and the exact behaviour follow.

#### 4.6.1 Why automatic attachment deletion is disabled

The check-and-delete sequence for attachments is **not atomic**:
1. Check whether the attachment is exclusively owned (`is_exclusively_owned()`).
2. Another process creates a reference to it.
3. Call `wp_delete_attachment($id, true)`.

The check in step 1 cannot detect a reference created in step 2. There is no atomic check-and-delete primitive in WordPress (`wp_delete_attachment()` deletes unconditionally; it does not re-check ownership). No persistent object cache is assumed (the repo does not require one), so no shared lock can prevent concurrent references during the check-and-delete interval. A timestamp heuristic (checking whether posts were created/modified after the event deletion's COMMIT) does **not** close the gap — a reference can be created after the ownership check itself.

**Therefore, automatic attachment deletion is disabled in this implementation.** The priority is avoiding accidental deletion of a shared image over automatically reclaiming every unused attachment. This is a deliberate, conservative choice.

#### 4.6.2 Two-phase boundary

**Phase A (inside the event-deletion transaction, §4.4):** delete the **event post only**. Do **not** call `wp_delete_attachment()` here. The transaction is: row-lock + fencing write → `wp_delete_post(event)` → run-state update → **enqueue the event's banner and featured attachment IDs into the persistent `attachment_review_queue`** (see §4.6.3, with atomic queue update per §4.6.5) → COMMIT. No file is touched.

**Phase B (after the event transaction commits, outside any DB transaction):** attachment review queue processing. For each **unresolved** attachment in `attachment_review_queue`:
1. **Re-check ownership** (the event row is already gone; the 4 ownership checks in `is_exclusively_owned()` are re-run against current DB state).
2. **Preserve the attachment** (default). The queue entry remains until an operator explicitly resolves it (see §4.6.4).
3. **Automatic deletion is disabled.** Even if the ownership check passes, the attachment is **not** automatically deleted, because the check-and-delete race cannot be eliminated. (A future enhancement with a persistent object cache or an atomic primitive could enable automatic deletion; that is out of scope and requires separate approval.)

#### 4.6.3 `attachment_review_queue` — durable cross-run manual-review queue

**`attachment_review_queue` is a manual-review queue, not an automatic-cleanup queue.** Its purpose is to durably record attachments that were preserved (not deleted) after their event was deleted, so an operator can review them later.

**Storage (separate from run-state, persistent across runs):**
- Stored in a **dedicated option** `conexao_event_cleanup_attachment_review` (autoload=false), **not** in the run-state option. This is the key difference from rev. 10: the queue is **not** tied to any single run's lifecycle.
- Shape: `{ entries: { attachment_id: { event_id, added_at, source: 'banner'|'featured', status: 'pending'|'reviewed', resolved_at, resolved_by, resolution: 'reviewed'|null } }, capacity: 500, overflow_count: 0 }`.
- **Survives across runs:** completed, cancelled, failed, and newly started runs all read and write the **same** persistent option. A new run does **not** reset or replace the queue. Entries remain `pending` until an operator explicitly resolves them (§4.6.4).

**Capacity (fail-closed, cross-run):**
- `MAX_ATTACHMENT_REVIEW_QUEUE = 500` **pending** entries (entries with `status = 'pending'`). Reviewed entries (`status = 'reviewed'`) do not count toward capacity but are retained for audit (bounded by `MAX_REVIEWED_HISTORY = 1000`; oldest reviewed entries are evicted first when the reviewed history is full — this is safe because reviewed entries are audit-only, not actionable).
- **Before deleting an event in Phase A**, the worker checks: would adding this event's attachment IDs cause the **pending** count to exceed 500?
  - If **yes** → the worker **does not delete the event**. It **pauses** the run (`paused_capacity_review`), surfaces an admin notice, and the cursor does **not** advance past this event. **No eviction of pending entries, ever.** **An event is never deleted without a durable record of the attachments requiring review** — the queue entry is committed in the same transaction as the event deletion.
  - If **no** → the event is deleted and its attachment IDs are added to the queue (status `pending`) in the same transaction.
- **Overflow is visible:** run status, admin UI, and import log report `paused_capacity_review` with the pending count.

**Recovery behaviour:**
- If the worker crashes after Phase A commits (event gone) but before Phase B runs, the attachments are in the queue (committed in Phase A, in the persistent option). On the next run, Phase B processes the queue: re-checks ownership (the event is gone) and **preserves** (default). The queue entry remains `pending` until an operator resolves it.
- **If recovery cannot be verified** (queue option corrupt, attachment ID invalid) → attachment **preserved** + manual-review item. The queue entry is flagged `status = 'pending'` with `resolution = 'recovery unverified'`.

#### 4.6.4 Operator resolution — mark an attachment as reviewed (without deleting it)

**Operator action: "Mark as reviewed"** (capability + nonce + audit):
- **Authorisation:** `current_user_can('manage_options')` + `check_admin_referer('conexao_mark_attachment_reviewed', 'conexao_attachment_review_nonce')`.
- **Effect:** sets the queue entry's `status = 'reviewed'`, `resolved_at = now`, `resolved_by = current_user_login()`, `resolution = 'reviewed'`. **The attachment is NOT deleted.** The entry remains in the queue (status `reviewed`) for audit.
- **Audit:** the resolution is recorded in the import log (`Conexao_Import_Log::add('attachment_review', 'info', ...)`) with the attachment ID, event ID, operator, timestamp, and resolution. Sanitised (no PII).
- **Capacity impact:** a reviewed entry no longer counts toward the 500 pending capacity. This **frees capacity** for new events to be deleted.
- **Resume after resolve:** once an entry is resolved (reviewed), the run is no longer paused for that entry. If the run was paused (`paused_capacity_review`) and the operator resolves enough entries to free capacity, the operator clicks **Continue** to resume the run. The run re-checks capacity at the next event and proceeds if there is room.

**Operator action: "Mark as reviewed" is the only resolution action.** There is no "delete" action — the attachment is never automatically or manually deleted through this queue. An operator who wants to delete a specific attachment uses the WordPress Media Library directly (outside this cleanup's scope).

#### 4.6.5 Atomic queue updates (operator issue — rev. 11 final clarification)

**The persistent queue option is shared across the cleanup worker and the operator's "Mark as reviewed" action. Its read-modify-write operations must be concurrency-protected so that two simultaneous updates cannot overwrite each other's changes.**

**Worker enqueue (inside the event-deletion transaction, §4.4):**
- The queue update is performed **inside the same DB transaction** as the event deletion and run-state checkpoint. Specifically:
  1. `START TRANSACTION`.
  2. `SELECT option_value FROM wp_options WHERE option_name = 'conexao_event_cleanup_attachment_review' FOR UPDATE;` — acquires an InnoDB row lock on the queue option row, held until COMMIT/ROLLBACK. This prevents any other transaction (including an operator's "Mark as reviewed") from reading or writing the queue option mid-transaction.
  3. Read the current queue value (from the `FOR UPDATE` read).
  4. Compute the new queue value (add the event's attachment IDs as `pending`, update `overflow_count` if needed). **Attachment info now includes MIME type and classification (image, pdf, document, unknown) — see §4.6.8.**
  5. `UPDATE wp_options SET option_value = :new_queue_value WHERE option_name = 'conexao_event_cleanup_attachment_review' AND option_value = :old_queue_value_read_in_step_3;` — compare-and-swap on the exact bytes read. If affected rows = 0 → another process changed the queue (should not happen because of the row lock, but fail closed anyway) → `ROLLBACK` (event not deleted, queue not updated).
  6. Capacity check: if adding the attachment IDs would exceed 500 pending → `ROLLBACK` (event not deleted, run pauses `paused_capacity_review`).
  7. `wp_delete_post(event)` + DB verify.
  8. Run-state checkpoint (cursor, counts).
  9. `COMMIT` — event deletion, queue update, and run-state checkpoint all commit together. If any step fails, `ROLLBACK` undoes all three.

**"Mark as reviewed" (operator action, outside the cleanup transaction):**
- Uses the **same row-lock + CAS pattern** on the queue option:
  1. `START TRANSACTION`.
  2. `SELECT option_value FROM wp_options WHERE option_name = 'conexao_event_cleanup_attachment_review' FOR UPDATE;` — acquires the row lock. If a cleanup worker is mid-transaction (holding the lock), the operator's `SELECT ... FOR UPDATE` **blocks** until the worker commits or rolls back.
  3. Read the current queue value.
  4. Compute the new queue value (set the entry's `status = 'reviewed'`, `resolved_at`, `resolved_by`, `resolution`).
  5. `UPDATE wp_options SET option_value = :new_queue_value WHERE option_name = 'conexao_event_cleanup_attachment_review' AND option_value = :old_queue_value_read_in_step_3;` — CAS. If affected rows = 0 → another process changed the queue → `ROLLBACK` (operator sees an error: "queue was modified by another process; please retry").
  6. `COMMIT` — the review update is durable.

**Why this is safe:**
- The `SELECT ... FOR UPDATE` row lock ensures that only one transaction (worker or operator) can read-modify-write the queue option at a time. The other blocks until the first commits or rolls back.
- The CAS (`WHERE option_value = :old`) is a second safeguard: even if the row lock is somehow bypassed (e.g. a different DB isolation level), the CAS fails if the bytes changed.
- **No lost updates:** an operator's "Mark as reviewed" cannot be lost when a worker enqueues a new entry, because the worker's transaction holds the row lock for the entire read-modify-write, and the operator's transaction blocks until the worker commits. When the operator proceeds, it reads the **committed** queue value (including the worker's new entry) and applies its review on top.
- **Fail closed:** if the CAS fails (affected rows = 0), the transaction rolls back. The event is **not deleted** (worker case) or the review is **not applied** (operator case). The operator sees an error and retries. No queue entry is silently lost.

**Summary of the atomicity rule:**
- **Worker enqueue + event deletion + run-state checkpoint commit or roll back together** (one transaction, row lock on the queue option).
- **"Mark as reviewed" uses the same row-lock + CAS pattern** (compatible with the worker's lock).
- **Any failed or conflicting write fails closed** (transaction rolls back; event not deleted or review not applied; operator notified).

#### 4.6.6 Crash during Phase B

Phase B does not call `wp_delete_attachment()`, so there is no file unlink and no filesystem risk. The only operation is the ownership re-check (read-only) and the run-state update. A crash during Phase B leaves the queue entry intact; the next run re-processes it. **No data loss, no orphaned file.**

#### 4.6.7 Summary of the attachment policy

- **Automatic attachment deletion: DISABLED.**
- **Default: preserve.** All attachments are preserved after their event is deleted.
- **Manual-review queue:** `attachment_review_queue` (stored in the persistent `conexao_event_cleanup_attachment_review` option) durably records preserved attachments for operator review. Survives across runs.
- **Atomic queue updates:** worker enqueue + event deletion + run-state checkpoint commit/rollback together (row lock + CAS on the queue option). "Mark as reviewed" uses the same pattern. No lost updates; fail closed on conflict.
- **Fail-closed capacity:** an event is never deleted without a durable queue entry for its attachments. Pending capacity is 500; reviewed history is 1000 (audit-only).
- **Operator resolution:** "Mark as reviewed" (capability + nonce + audit) sets the entry to `reviewed` without deleting the attachment. Frees capacity. Run resumes on Continue.
- **Admin UI:** preserved attachments are listed with their IDs, source event, MIME type, classification, reason, and a **Mark as reviewed** button. The UI makes clear these attachments were **preserved, not deleted**, and need manual review.
- **Future enhancement:** automatic deletion could be enabled if a reliable concurrency-prevention mechanism is demonstrated (persistent object cache with per-attachment lock, or a core `pre_delete_attachment` filter that re-checks ownership inside `wp_delete_attachment()`). Out of scope; requires separate approval.

#### 4.6.8 Attachment classification (NEW in rev. 14)

**All identifiable referenced attachment IDs are queued, not just images.** The worker collects banner and featured attachments with their MIME types and classifies them:

| Classification | MIME patterns | Admin UI label |
|---|---|---|
| `image` | `image/*` | "Image" |
| `pdf` | `application/pdf` | "PDF" |
| `document` | `application/*`, `text/*` | "Document" |
| `unknown` | anything else | "Unknown" |

- **Never misclassify:** a PDF is never classified as an image. Unknown types are preserved and flagged for manual review.
- **Preserve when uncertain:** if MIME type cannot be determined, classification defaults to `unknown` and the attachment is preserved.
- **Admin UI displays:** attachment ID, source event, classification, MIME type, reference source (banner/featured), and **Mark as reviewed** button.

### 4.7 Failed-event retry policy — fail-closed bounded storage with operator-driven retry queue (rev. 14)

**Policy: attempts accumulate across runs; the counter is persistent; capacity is fail-closed (stop, don't evict); overflow is visible to the administrator. Operator-driven retry is now supported for both initial failures (`failed_events`) and exhausted retries (`failed_events_final`).**

**Storage (all bounded, fail-closed):**
- `failed_events` — map in the **run-state** option (survives run completion): `{ event_id: { attempts, last_reason, last_attempt_at, first_failed_at } }`. Capacity `MAX_FAILED_TRACKED = 500`.
- `failed_events_final` — map in the **run-state** option: events that exhausted `MAX_ATTEMPTS = 3`. Capacity `MAX_FINAL_TRACKED = 1000`.
- `retry_queue` — **dedicated persistent option** (`conexao_event_cleanup_retry_queue`, autoload=false): operator-queued events for retry. Survives across runs (completed, cancelled, failed, new runs). Capacity `MAX_PENDING = 500`. Atomic row-lock + CAS updates.

**Fail-closed capacity policy:**
- When `failed_events` reaches 500 **and** a new event fails → run **pauses** (`paused_capacity`), **no eviction**, admin notice, cursor does not advance past the new failed event. Operator must resolve/export or raise capacity (approval required).
- Same for `failed_events_final` at 1000.
- When `retry_queue` reaches 500 pending → enqueue fails closed, operator notified.
- **Overflow is never silent:** run status, admin UI, and import log report `paused_capacity` with counts.

**Retry counter lifetime:** `attempts` is **persistent across runs** (run-state option). A fresh run does **not** reset it.

**Retry flow (automatic):**
1. Eligible event fails → `failed_events[id].attempts++`, sanitised reason, cursor advances.
2. Later run → scan re-fetches, re-attempts. Success → removed from `failed_events`. Failure → `attempts++`.
3. After `attempts >= 3` → moved to `failed_events_final`, reported `completed_with_errors`.

**Retry flow (operator-driven — NEW in rev. 14):**
1. Operator clicks **Retry** on any event in `failed_events` or `failed_events_final` (admin UI, capability + nonce).
2. Event is removed from failure list, `attempts` reset to 0, and added to persistent `retry_queue` with status `pending`.
4. Next batch run processes `retry_queue` **before** the normal scan window, through the same transactional worker (lock, fencing, batch/time limits).
5. On success: event removed from `retry_queue`, deleted, and cleared from any failure list.
6. On failure: `attempts` incremented in `retry_queue`, failure recorded in `failed_events`/`failed_events_final` as normal.

**No silent exclusion from eligibility:** `failed_events_final` events remain eligible per §2; scan still visits them; cleanup records "skipped: exhausted retries" and moves on. Visible in UI.

**Retry vs Dismiss — auditable resolution states:**
- **Retry** (operator, capability + nonce): removes ID from `failed_events` or `failed_events_final`, resets `attempts=0`, adds to `retry_queue`, records `resolution: retry` in audit log; next run re-attempts via queue.
- **Dismiss** (operator, capability + nonce): moves ID to `dismissed` state, records `resolution: dismiss` (operator, timestamp, reason) in audit log; next full cleanup **skips it permanently** (no auto-retry) until operator re-enables. Dismiss does **not** silently remove the record.
- **Re-enable** (operator): moves `dismissed` → `failed_events` with `attempts=0`; explicit.
- **No automatic retry loop.**

**Final status accuracy:**
- `completed` — cursor exhausted, zero failures, `failed_events_final` empty.
- `completed_with_errors` — cursor exhausted, ≥1 event in `failed_events_final`.
- `paused_capacity` — run stopped because a failure-tracking list is full.
- `paused_capacity_review` — run stopped because `attachment_review_queue` pending count is full.

### 4.8 Admin UI
- Page shows run state: status badge, `events_deleted / (events_deleted + remaining_estimate)`, progress bar, last batch duration, error count, `failed_events_final` list with **Retry** / **Dismiss** / **Re-enable** buttons (capability + nonce), `attachment_review_queue` list (preserved attachments with ID, source event, reason, and a **Mark as reviewed** button — **clearly labelled "PRESERVED — manual review required", not deleted**), and `paused_capacity` / `paused_capacity_review` banners.
- **Continue** button (same nonce) processes the next batch; auto-submits while a run is active and not terminal (JS with no-JS meta-refresh fallback). If the run is `paused_capacity_review`, Continue re-checks capacity and resumes if there is room.
- **Cancel** sets the run to `cancelled` at the next batch boundary.
- All existing status/history/summary rendering is preserved.

### 4.9 CLI
- `wp conexao-events cleanup` loops batches internally until terminal. `--dry-run` unchanged.

### 4.10 What is explicitly NOT done (conservative scope)
- **Event eligibility rules: UNCHANGED** (§2.1).
- **Event post hard-deletion semantics: UNCHANGED** (§2.2).
- **Automatic attachment deletion: DISABLED** (deliberate change — §4.6). Attachments are preserved by default and recorded in a manual-review queue.
- No schema change / index.
- No new dependency, no Action Scheduler, no cron.
- No production access, no real cleanup, no importer/Event Sources/Needs Review changes.
- No storage of the full candidate list in any option.
- No append-only delete journal (rejected in §4.4).
- No silent eviction of failure records (rejected in §4.7).
- No file deletion inside any DB transaction (rejected in §4.6).
- No silent eviction of `attachment_review_queue` pending entries (rejected in §4.6).
- No claim that the attachment check-and-delete is atomic (it is not).
- No timestamp heuristic claimed to eliminate the check-and-delete race (withdrawn in §4.6).
- No automatic attachment deletion unless a reliable concurrency-prevention mechanism is demonstrated (disabled by default in §4.6).

## 5. Rejected alternatives
| Alternative | Why rejected |
|---|---|
| Raise PHP/proxy timeouts | Disallowed by task; treats symptom, not cause. |
| Store full candidate list in an option | O(n) storage, drifts from DB truth. Replaced by keyset cursor. |
| Offset pagination for batches | Can skip/duplicate under concurrent deletes; keyset is strictly safer. |
| Auto-restart on stale lock | Risks re-scanning/re-deleting. Replaced by resume-from-checkpoint. |
| `JSON_EXTRACT()` on `wp_options.option_value` | option_value is PHP-serialized, not JSON. |
| `add_option()` as lock primitive | TOCTOU + `ON DUPLICATE KEY UPDATE` overwrites winner; cannot lock. |
| `wp_cache_add()` as lock primitive | Only atomic with persistent object cache; not durable; repo assumes none. |
| Fencing token alone (no row lock) | A worker can lose ownership between the token check and the delete. Replaced by `SELECT ... FOR UPDATE` row lock held for the transaction duration (§4.3). |
| Per-batch-only ownership check (no fencing) | A stale worker can still delete between checks. Replaced by fencing at the destructive boundary. |
| Append-only delete journal | Gives exact in-flight counts but adds persistent state + failure modes; rejected as non-conservative. |
| Per-run reset of retry counter | Inconsistent with cross-run accumulation. Replaced by persistent cross-run counter. |
| Silently excluding `failed_events_final` from eligibility | Violates "no silent exclusion"; replaced by "visit but don't auto-retry, show in UI". |
| Evicting old `failed_events` entries when full | Loses retry history; lets permanently failing events restart their cycle. Replaced by fail-closed pause. |
| Dismissing a failure by silently removing it | Next full cleanup would auto-retry it. Replaced by auditable `dismissed` state. |
| Deleting attachment files inside any DB transaction | File unlink is irreversible by DB rollback. Replaced by Phase B outside all transactions (§4.6). |
| Storing `attachment_review_queue` in the run-state option (rev. 10) | Run-state is replaced/reset per run; pending entries would be lost. Replaced by a dedicated persistent option (§4.6.3). |
| Evicting `attachment_review_queue` pending entries when full | Loses the durable record of attachments requiring review. Replaced by fail-closed pause (§4.6.3). |
| Committing an event deletion that would overflow `attachment_review_queue` | Would lose the durable record of attachments requiring review. Replaced by pre-delete capacity check (§4.6.3). |
| Claiming the attachment check-and-delete is atomic | It is not; the check and delete are separate operations. Replaced by preserve-by-default (§4.6). |
| Timestamp heuristic to eliminate the check-and-delete race | Does not close the gap (a reference can be created after the check). Withdrawn; replaced by preserve-by-default (§4.6). |
| Automatic attachment deletion without a reliable concurrency-prevention mechanism | Risks deleting a shared file. Disabled by default; preserve + manual review (§4.6). |
| `cursor_id = last_deleted_id - 1` (rev. 9) | Combined with `WHERE ID < cursor_id`, skips the event at `last_deleted_id - 1`. Replaced by `cursor_id = last_deleted_id` (§4.5, rev. 10 — approved). |
| Non-transactional queue read-modify-write (rev. 11) | Two simultaneous updates (worker enqueue + operator review) could overwrite each other. Replaced by row-lock + CAS in §4.6.5. |
| `wp_schedule_single_event` for attachment cleanup | Cron may not fire on a fully cached site; inline Phase B is simpler and sufficient. |
| Action Scheduler / background cron | Not bundled; adds dependency + scheduling surface; needs approval. |
| Single keyset bulk delete in one request | Still one long request → still times out. |
| Add DB indexes on `post_content`/`meta_value` | Leading-wildcard `LIKE` unusable; schema change needs approval + migration plan; defer. |
| Change eligibility (e.g. only delete N oldest) | Silently narrows deletion set — forbidden. |

## 6. Failure & recovery scenarios (summary)
| Scenario | Behaviour |
|---|---|
| Timeout mid-batch | Each event's delete+checkpoint is one transaction; committed events durable, uncommitted roll back. Resume from last committed cursor. No re-delete, no count drift. |
| Crash after event delete, before checkpoint | Same transaction → rollback restores event row, cursor, counts, lock-row lease renewal, and queue update. Re-processed cleanly on resume. **No lost delete, no double-count, no lost queue entry.** |
| Crash after event commit, before attachment review queue processing (Phase B) | Event gone (correct); attachments in `attachment_review_queue` (committed in Phase A, in the persistent option). Next run's Phase B re-checks ownership and **preserves** (default). Queue entry remains `pending` until operator resolves. |
| Worker A stalls past TTL, B reclaims | A's next batch `SELECT ... FOR UPDATE` reads B's lock row → A's fence check fails → ROLLBACK → **A deletes nothing**. B resumes from committed cursor. |
| B attempts reclaim after A's `SELECT ... FOR UPDATE` but before A's COMMIT | B's `UPDATE` **blocks** (A holds the InnoDB row lock). A commits (fence valid, lease renewed in Step 3) → B unblocks, its `UPDATE ... WHERE option_value = :old_bytes` matches 0 rows (A changed the lock row's bytes in Step 3) → B fails to reclaim → refused. **A's delete is committed; B never reclaimed.** |
| A's lease expired before A's `SELECT ... FOR UPDATE` | A's fence check fails → ROLLBACK → no delete. (A's Step 3 lock-row update never ran; lock row unchanged.) B reclaims. |
| Two starts race | `INSERT IGNORE` on UNIQUE key: exactly one wins; the other gets 0 rows → refused. |
| Run-state corrupt / cursor invalid | Run set to `failed` (`recovery_required`); admin notice; **no** automatic destructive restart. |
| One event always throws | Cursor advances; `failed_events[id].attempts` accumulates across runs; after 3 → `failed_events_final`, reported `completed_with_errors`, still visited by scan but not auto-retried, visible in UI with Retry/Dismiss. Never blocks the run, never loops. |
| `failed_events` reaches 500 and a new event fails | Run **pauses** (`paused_capacity`); **no eviction**; admin notice; cursor does not advance past the new failed event. Retry history preserved. |
| `failed_events_final` reaches 1000 | Run pauses (`paused_capacity`); no eviction; admin notice. |
| `attachment_review_queue` pending reaches 500 and an event has more attachments | Run **pauses** (`paused_capacity_review`) **before** deleting the event; **no eviction**; admin notice; cursor does not advance. Queue entries preserved. **An event is never deleted without a durable record of its attachments requiring review.** |
| Worker enqueues attachment while operator marks another reviewed | Worker's `SELECT ... FOR UPDATE` on the queue option holds the row lock; operator's `SELECT ... FOR UPDATE` **blocks** until the worker commits. Operator then reads the committed queue (including the worker's new entry) and applies its review on top. **No lost update.** |
| Operator marks reviewed while worker is mid-transaction | Operator's `SELECT ... FOR UPDATE` blocks until the worker commits or rolls back. If the worker rolls back (event not deleted), the operator's review proceeds on the unchanged queue. If the worker commits, the operator's review proceeds on the updated queue. |
| CAS conflict (affected rows = 0) | Transaction rolls back. Worker: event **not deleted**, run pauses. Operator: review **not applied**, error shown ("queue was modified by another process; please retry"). **No silent loss.** |
| Attachment referenced elsewhere after event deletion | **Preserved** (default). Ownership is uncertain → manual review. **No automatic deletion of a potentially shared file.** |
| Operator clicks "Mark as reviewed" on an attachment | Queue entry `status = 'reviewed'`, `resolved_at`, `resolved_by`, `resolution = 'reviewed'` recorded; import log audit entry; attachment **NOT deleted**; pending capacity freed. Run can resume on Continue. |
| Run is `paused_capacity_review`, operator resolves enough entries, clicks Continue | Run re-checks pending capacity; if there is room, resumes from cursor. If still full, remains paused. |
| New run starts while `attachment_review_queue` has pending entries | New run reads the **same** persistent queue option; pending entries are **not** reset or lost. Phase B processes them (preserves by default). |
| Run completes/cancels/fails while `attachment_review_queue` has pending entries | Pending entries **persist** in the dedicated option; they are not tied to the run's lifecycle. The next run (or the admin UI) can still process and resolve them. |
| Operator clicks Retry on a `failed_events_final` event | ID removed from final list, `attempts` reset to 0, `resolution: retry` audit entry; next run re-attempts. Explicit. |
| Operator clicks Dismiss on a `failed_events_final` event | ID moved to `dismissed` state, `resolution: dismiss` audit entry; next full cleanup **skips it permanently** (no auto-retry) until re-enabled. Auditable. |
| Operator clicks Re-enable on a `dismissed` event | ID moved back to `failed_events` with `attempts=0`; re-enters retry cycle. Explicit. |
| Rollback after cache invalidation | Cache/transient deletions survive (idempotent, conservative); next pass rebuilds lazily. No stale data, no data loss. |
| Cancel requested | Honoured at next batch boundary; run → `cancelled`. Pending `attachment_review_queue` entries persist. |
| Operator refreshes page | Run-state persists; page shows current progress; no duplicate run started. |
| Batch stops at event-count limit, adjacent eligible events | `cursor_id = last_deleted_id`; next query `ID < cursor_id` starts at `last_deleted_id - 1` → **no skip**. Regression test required. |
| Batch stops at time budget, adjacent eligible events | `cursor_id = last_committed_id`; next query `ID < cursor_id` starts at `last_committed_id - 1` → **no skip**. Regression test required. |
| Interruption and resume at event-count or time boundary | Cursor is durable (committed in the last event's transaction); resume continues from `cursor_id` with the same no-skip behaviour. Regression test required. |

## 7. Acceptance criteria mapping (implementation, Phase 6+)
| Criterion | How the design satisfies it |
|---|---|
| Cause evidence-based | §3 C1–C4 code-confirmed; H1/H2 labelled hypotheses. |
| No single long request | Batches bounded by count + time; page drives continuation. |
| Bounded work | `BATCH_EVENTS` + `BATCH_TIME_SECONDS` + `scan_window` per batch. |
| Progress survives refresh/interruption | Durable run-state + monotonic keyset cursor + transactional checkpoint. |
| Retry/concurrency/stale-lock safe | UNIQUE-key lock + **InnoDB row-lock fencing held for the transaction duration** + CAS renewal/reclaim on the lock row + resume-from-checkpoint + idempotent deletes. |
| Event eligibility & event-post deletion preserved | Engine untouched; only wrapped; keyset preserves order + predicate. |
| Cursor never skips an unprocessed eligible event | Cursor = last processed ID; next query `ID < cursor_id` starts at the next unprocessed ID. Regression tests at event-count and time boundaries. |
| Attachment cleanup behaviour change is explicit | Automatic attachment deletion disabled; attachments preserved by default; manual-review queue (`attachment_review_queue` in a dedicated persistent option) durably records preserved attachments across runs; fail-closed capacity; admin UI clearly labels them as preserved (not deleted) and needing manual review; operator "Mark as reviewed" action with audit. |
| Attachment queue updates are atomic | Worker enqueue + event deletion + run-state checkpoint commit/rollback together (row lock + CAS on the queue option). "Mark as reviewed" uses the same pattern. No lost updates; fail closed on conflict. |
| No unrelated data changed | Event deletion path unchanged; tests assert scope; hook-safety regression guard. |
| Failure tracking bounded & fail-closed | No silent eviction; run pauses at capacity; overflow visible; auditable Retry/Dismiss/Re-enable states. |
| Tests in disposable DB | New suite via `scripts/conexao-test-exec.sh` after `$wpdb` identity proof. |
| Synthetic multi-batch demo | Large-fixture test proving multi-batch completion + counts. |

## 8. Operator sign-off

**Status: AWAITING OPERATOR APPROVAL (rev. 12).**

Approval covers **only** the implementation approach in §4. It does **not** authorise production DB access, real cleanup, schema changes, new dependencies, provider calls, imports, or deployment. If declined or changed, I will revise this note and re-submit before any code is written (Phase 6).