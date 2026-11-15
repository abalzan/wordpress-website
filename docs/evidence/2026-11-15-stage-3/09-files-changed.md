# Stage 3 — exact files changed

Baseline: `270416911f918a849e34f77cacfc4d7d19925ad6`, clean worktree,
branch `event-import-improvements`. Nothing was committed, pushed, merged,
deployed or activated.

## 1. Production code (7 files, all under `wp-content/plugins/conexao-event-importer/includes/`)

| File | Change | Stage 1/2 file? |
|---|---|---|
| `class-import-log.php` | `CORRELATION_CONTEXT_KEYS` + `LEGACY_GROUP`; the `add()` allowlist now retains correlation keys; `persist_entries()` → `retain()`; new `entry_group_key()`, `retain()`, `active_group_keys()`, `get_retention_state()`, `get_for_group()`, `chronological()`, `preview()`; `index_entries_by_run()` also indexes by parent group; `export_run()` is group-scoped, chronological and carries a manifest; `prune_older_than_90_days()` made group-safe and documented as still unwired; `MAX_ENTRIES` 2,000 → 8,000 | **Stage 1** (it gained `run_id` correlation and the run index in Stage 1/G3). Changed because P12 requires the correlation keys to survive, and P13 requires group-aware retention in this store. |
| `class-import-history.php` | `MAX_ENTRIES` 100 → 1,200, new `MAX_GROUPS` 50 + `ACTIVE_STATUSES`; `record()` writes `incomplete_reason` and calls `retain()` instead of `array_slice`; new `entry_group_key()`, `is_group_terminal()`, `get_active_group_ids()`, `retain()`, `get_retention_state()` | **Stage 1** (parent/child rows, `record_parent()`). Changed because P13's retention unit is Stage 1's run group. |
| `class-import-result.php` | `add_incomplete_enumeration()` now yields `partial` (never `warning`) and records a `$reason_code`; new `add_warning()`, `get_incomplete_enumeration_reason()`; `mark_rate_limited()` records a reason code; `incomplete_reason` in `get_stats()`; status-vocabulary docblock | **Stage 1** (it introduced `partial`/`rate_limited`). Changed because P12's warning-vs-incomplete distinction lives here. |
| `class-event-importer.php` | `run_source()`'s `finally` passes `run_id`/`source_run_id`/`parent_run_group_id` to `reconcile()` unconditionally; `mark_fetch_incomplete_in_result()` records the checkpoint's stop reason; the checkpoint-resume caveat uses `add_warning()`; `update_source_stats_after_run()` maps every status truthfully and calls `record_incomplete()` for incomplete/rate-limited runs; new `incomplete_reason_text()` | **Stage 1/2** (run coordination, reconciliation gates, enrichment). The P1 breaker, P6 aggregation and the Stage 2 fetch/reconciliation/enrichment paths are **unchanged**; only status/diagnostic mapping moved. |
| `class-conexao-import-continuation.php` | record gains `source_run_id`, `continuation_id`; `reconcile()` persists all three identifiers; `schedule()` mints a stable `continuation_id`; `log()` merges `correlation_context()` and accepts the removed record; `clear()` passes it; `lifecycle`/`cause` tags on every entry; new `correlation_context()`, `STALE_RUNNING_SECONDS`, `classify()`, `get_diagnostics()`, `get_diagnostic_counts()`, `get_active_group_ids()` | **Stage 1 (G18)**. Changed because P12's correlation and stuck-state diagnostics live here. Scheduling, cooldown, lock and re-arm logic are unchanged. |
| `class-source-health.php` | new additive `record_incomplete()` (`last_incomplete`, `last_incomplete_status`, `last_incomplete_reason`); counters untouched | **Stage 1-era** (health tracker). Changed because a rate-limited run must be neither a success nor a failure. |
| `class-event-sources.php` | `events_imported` column relabelled `Created (last run)` + help text; Last Import column shows the status word for every outcome and the error for `partial`/`rate_limited`; history Status cell uses `history_status_label()` + `history_status_reason()`; Logs panel discloses shown/total/truncation; new `render_retention_state()` and `render_continuation_diagnostics()`; `history_progress_text()` refuses to claim a worker is running for a stale record; new `last_import_status_label()`, `is_last_import_successful()`, `history_status_label()`, `history_status_reason()` | **Stage 1/2** (history renderer, source health UI). Changed because P12/P13's operator-facing surface lives here. **No column was added to the 14-column Import History table**, and **no Sources overview was added to the main Import dashboard**. |

## 2. Admin styling (1 file)

| File | Change |
|---|---|
| `assets/admin.css` | one additive, scoped rule for `.conexao-help`, the inline affordance beside the relabelled `Created (last run)` header. No existing selector was modified. |

## 3. Tests (4 files)

| File | Change |
|---|---|
| `tests/test-stage3-diagnostics-and-retention.php` | **NEW.** 152 assertions across sections A–I (correlation, status model, stuck diagnostics, `events_imported`, full-run reconstruction/download, preview disclosure, legacy records, log retention at the bound, history retention). |
| `tests/test-import-history-dashboard.php` | `MAX_ENTRIES`/`MAX_GROUPS` expectations updated to the documented Stage 3 bounds; the cap test seeds 1,200 rows in one write instead of 1,201 individual rewrites (same property, no 512 MB OOM). **No assertion was removed or weakened.** |
| `tests/test-import-history-logs.php` | one assertion updated: `MAX_ENTRIES` 2,000 → 8,000. |
| `tests/test-import-log.php` | the old cap test asserted the removed blind 2,000-entry slice; it now proves the documented group-aware contract. 61/1 → 65/0. |
| `tests/test-source-fetch-failure.php` | `g1_assert_failed_source()` takes an expected status; the two HTTP 429 cases now assert the **documented Stage 1 contract** (`rate_limited`, incomplete enumeration, recorded cause) instead of the pre-Stage-1 `failed`. All non-429 cases still demand `failed` + `fatal_errors`. Result: 122/10 → **136/0**. |

## 4. Documentation (this directory) and the report

| File |
|---|
| `docs/evidence/2026-11-15-stage-3/investigation-source-not-found.md` |
| `docs/evidence/2026-11-15-stage-3/00-baseline-and-preservation.md` |
| `docs/evidence/2026-11-15-stage-3/01-continuation-call-site-inventory.md` |
| `docs/evidence/2026-11-15-stage-3/02-status-vocabulary-mapping.md` |
| `docs/evidence/2026-11-15-stage-3/03-stuck-continuation-rule.md` |
| `docs/evidence/2026-11-15-stage-3/04-events-imported-semantics.md` |
| `docs/evidence/2026-11-15-stage-3/05-retention-architecture.md` |
| `docs/evidence/2026-11-15-stage-3/06-shared-db-readonly-and-attribution.md` |
| `docs/evidence/2026-11-15-stage-3/07-tests.txt` |
| `docs/evidence/2026-11-15-stage-3/08-quality-gates.txt` |
| `docs/evidence/2026-11-15-stage-3/09-files-changed.md` |
| `docs/evidence/2026-11-15-stage-3/10-preservation-review.md` |
| `docs/reports/2026-11-15-stage-3-event-importer-diagnostics-retention.md` (new report) |
| `docs/reports/README.md` (index entry) |

## 5. Explicitly NOT changed

* `class-eventbrite-pacing.php`, `class-eventbrite-client.php`, `class-source-cooldown.php`, `class-source-enumeration-checkpoint.php`, `class-request-budget.php`, `class-request-reuse.php` — P1 and the Stage 1 budget/lock machinery: **zero diff**.
* `class-conexao-event-enrichment-state.php`, `class-event-normalizer.php`, `class-event-sources.php`'s Stage 2 reconciliation paths — P5/P10: **zero behavioural diff** (the P10 pagination code was not touched).
* `tests/lib/option-isolation.php`, `tests/bootstrap.php`, `tests/lib/isolated-db.php` — the Stage 0.5 protections: **zero diff** (no new store needed registering).
* The Stage 2 report, the Stage 2 evidence and the Stage 2 verification evidence: **zero diff**.
* Any event post, event meta, `_event_status`, source registry value, preservation cohort or operator log/history row in the shared database: **no write of any kind**.
