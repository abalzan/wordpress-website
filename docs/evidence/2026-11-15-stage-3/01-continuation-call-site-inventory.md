# Continuation lifecycle call-site inventory and identifier-propagation matrix

## 1. Every continuation lifecycle log call site (final tree)

All sites are in `wp-content/plugins/conexao-event-importer/includes/class-conexao-import-continuation.php`
and all go through the single private helper `self::log()`.

| # | Lifecycle event | Method | Line (final) | Emitted before/after the state transition |
|---|---|---|---|---|
| 1 | `continuation_rearmed` | `rearm_missing()` | 1104 | after re-arming the cron event |
| 2 | `continuation_cleared` | `clear()` | 798 | **after** the record is removed |
| 3 | `continuation_exhausted` | `declare_exhausted()` | 928 | after the terminal state is saved |
| 4 | `continuation_scheduled` | `schedule()` | 746 | after `save()` + `arm_cron()` |
| 5 | `continuation_scheduling_failed` | `schedule()` | 726 | after the failed state is saved |
| 6 | `continuation_started` | `run_due()` | 1002 | after `state=running` is saved, before the runner |
| 7 | `continuation_exception` | `run_due()` | 1025 | after the runner threw |

There is no other emitter: `grep -n 'self::log(' class-conexao-import-continuation.php`
returns exactly these seven sites. No cancellation path exists in the codebase, so
`cancelled` is deliberately absent (see §4).

## 2. Identifier-propagation matrix

`✔` = carried and asserted by `test-stage3-diagnostics-and-retention`; `—` = not
applicable to that event; `legacy` = may legitimately be empty on a pre-Stage-3
record and is then reported as uncorrelated rather than invented.

| Event | source_id | child `source_run_id` | parent `run_group_id` | `continuation_id` | Survives schedule → resume |
|---|---|---|---|---|---|
| created/scheduled | ✔ (log `source`) | ✔ (`context.source_run_id`) | ✔ (`context.parent_run_group_id` + log `run_id`) | ✔ | ✔ — all four persisted in the record |
| deferred (cooldown) | ✔ | ✔ | ✔ | ✔ | ✔ — record untouched |
| started/resumed | ✔ | ✔ | ✔ | ✔ | ✔ — read from the persisted record, **not** re-minted |
| progress/checkpoint update | *(no log call; the checkpoint store owns progress)* | — | — | — | n/a |
| completed (`cleared`) | ✔ | ✔ | ✔ | ✔ | ✔ — the removed record is passed in explicitly |
| failed (scheduling) | ✔ | legacy | legacy | legacy | ✔ where the record existed |
| failed (exception) | ✔ | legacy | legacy | legacy | ✔ where the record existed |
| abandoned/stale | *(diagnostic only — no log, no state change)* | ✔ in `get_diagnostics()` | ✔ | ✔ | n/a |
| rearmed | ✔ | legacy | legacy | legacy | ✔ |

## 3. Where each identifier comes from

| Identifier | Producer | Stored in | Consumed by |
|---|---|---|---|
| `run_id` (owning History row) | `Conexao_Event_Importer_Engine::run_source()` → `current_run_id` | continuation record `run_id`; log entry first-class `run_id` | `Conexao_Import_Log::index_entries_by_run()`, `get_for_run()`, the Logs column |
| `source_run_id` (child attempt) | `run_source()` → `current_source_run_id = 'source-' . uuid4()` | continuation record `source_run_id`; log `context.source_run_id` | diagnostics, export manifest |
| `parent_run_group_id` | `run_all()` → `current_run_id = 'all-…'`; standalone run → its own `source-…` id | continuation record; log `context.parent_run_group_id` | retention grouping, diagnostics |
| `continuation_id` | `schedule()` on first creation (`'cont-' . wp_generate_uuid4()`) | continuation record; log `context.continuation_id` | diagnostics |

### 3.1 What was actually broken before Stage 3 (measured on the live store)

* 159 continuation lifecycle entries existed; **0 carried a `run_id`**.
* Those 159 entries had only `reason`, `reason_code` and `cooldown_seconds` in
  `context`: the log store's allowlist silently discarded `run_id`,
  `parent_run_group_id`, `attempts`, `stalled`, `run_at` and `cause`.
* The continuation record's `run_id` field existed but was **never populated**
  (`schedule()` preserved `$existing['run_id']`, which was always `''`), so even
  a retained `run_id` would have been empty.
* `reconcile()` passed only `parent_run_group_id`, never the child run id, and
  `run_source()` passed `array()` when `$stats` was null — i.e. on every early
  return, which is exactly the case that leaves a continuation behind.

### 3.2 What Stage 3 changed

1. `Conexao_Import_Log::CORRELATION_CONTEXT_KEYS` + `add()` now retain the
   correlation keys (identifiers, state words, bounded integers only).
2. `Conexao_Import_Continuation::correlation_context()` is the single source of
   the canonical context, and `log()` merges it into **every** lifecycle entry.
3. `reconcile()` persists `run_id`, `source_run_id` and `parent_run_group_id`
   from the run that produced the continuation.
4. `run_source()` now passes those identifiers **unconditionally** in its
   `finally` block, so an early return no longer produces an uncorrelated record.
5. `clear()` passes the removed record explicitly, so the terminal entry stays
   correlated after the record is gone.
6. `schedule()` mints one stable `continuation_id` per lifecycle.

## 4. Legitimate absences, recorded rather than guessed

| Case | Why the identifier may be absent | How it is reported |
|---|---|---|
| Pre-Stage-3 continuation records (22 on the shared site) | The field did not exist; no historical value can be recovered | `get_diagnostics()` sets `correlated => false` and the Event Sources panel prints “legacy — no run correlation recorded”. No ID is invented. |
| `rearmed` / `exception` events | The code path can run for a record written before Stage 3 | Same `legacy` handling. |
| A standalone import that genuinely has no parent group | `run_source()` uses its own run ID as the parent, so a parent ID is always present; the child ID is always present too | `correlated => true` with `parent_run_group_id === run_id`. |
| Legacy log entries with no `run_id` | Written before run correlation existed | `entry_group_key()` returns `legacy`; they are excluded from `index_entries_by_run()` and evicted individually, never grouped into a run. |
| Cancellation | **No cancellation path exists in the codebase** | Nothing is emitted; the task said “only where an existing supported cancellation path exists”. |

## 5. Duplicate/retried callbacks

`run_due()` takes the per-source lock before it sets `state=running`, so a second
worker cannot emit a second `started` entry for the same source. `reconcile()` is
idempotent and `schedule()` returns the existing record when one is already
pending without re-logging, so a repeated checkpoint write cannot duplicate a
`scheduled` entry. The `clear()` path logs only when `$had` is true, so a repeat
clear is silent.
