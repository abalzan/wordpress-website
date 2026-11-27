# Status vocabulary mapping (P12)

## 1. What was verified before anything changed

The existing vocabulary and the parent aggregation were read first, not
replaced:

* `Conexao_Import_Result` child/source statuses: `success`, `warning`,
  `partial`, `rate_limited`, `failed`.
* Stage 1 parent statuses: `completed`, `completed_with_errors`, `partial`,
  `rate_limited`, `failed` (plus the non-terminal `queued`/`running`).
* `Conexao_Event_Importer_Engine::aggregate_parent_status()` implements one
  documented precedence: `failed > rate_limited > partial >
  completed_with_errors > completed`.
* Stage 1's own test (`test-stage1-run-coordination`, 47 assertions) asserts that
  precedence, including `rate_limited` for a Run All with an active breaker.

**Stage 3 introduces no new status word.** Every change is a mapping/rendering
change inside the existing vocabulary.

## 2. The mapping table (target outcome → existing vocabulary)

| Target outcome | Child status | Parent status | Evidence |
|---|---|---|---|
| **Success** | `success` | `completed` | unchanged |
| **Warning** — a non-fatal issue on a COMPLETED walk | `warning` | `completed_with_errors` if failures also exist, else `completed` | `add_warning()`; a skipped past event still warns |
| **Partial** | `partial` | `partial`, or `completed_with_errors` when every child completed but some events failed | per-event failure path (`add_failed()`) |
| **Incomplete** | `partial` (interruption, time budget, page cap, request budget, resumed subset that could not be reconciled) / `rate_limited` (the shared P1 breaker stopped planned work) | `rate_limited` when the breaker is the reason; `partial` when a budget/page/cap stopped the walk | `add_incomplete_enumeration()` + `mark_rate_limited()` |
| **Failed** | `failed` | `failed` | `add_fatal_error()` |

### 2.1 The one place the implementation differs from the target table — DOCUMENTED, NOT CHANGED

The target table says a parent should be `failed` **only when no child produced a
final effective result**, and `partial` when at least one child completed.

Stage 1's implemented and **tested** precedence returns `failed` as soon as **any**
child failed, even when other children completed. That behaviour is asserted by
`test-stage1-run-coordination` and is part of the Stage 1 contract the task told
Stage 3 to preserve. Stage 3 therefore **keeps it** and discloses the difference
here instead of silently rewriting the aggregation.

The information the target outcome needs is not lost: the parent row carries
`failed_sources` (every failed source, not capped at 20),
`planned_source_count`, `aggregate_error_count` and the created/updated/skipped
aggregate counters, so “one of 154 failed” is visible on the row even though the
status word is `failed`.

## 3. The warning-vs-incomplete distinction, and the defect it removes

Before Stage 3, `add_incomplete_enumeration()` only called `maybe_warn()`, which
upgrades `success` → `warning`. Consequences:

* a walk that stopped at page 13 of 49 because its time budget ran out was
  recorded as **`warning`** — the same status as a completed run with one skipped
  event;
* the operator could not tell “finished, with a caveat” from “did not finish”;
* the live shared store shows exactly this: the 14:00–14:10 continuation runs are
  all `warning`.

Stage 3 splits the two:

| Situation | Method | Status |
|---|---|---|
| Walk stopped early (time budget, page cap, request budget, fetch interruption) | `add_incomplete_enumeration( $message, $reason_code )` | `partial` |
| Walk COMPLETED, but the already-confirmed pages were not re-requested (checkpoint resume) | `add_warning( $message )` | `warning` |

`add_incomplete_enumeration()` never downgrades `failed` or `rate_limited`, so
the P1 breaker and the fatal paths keep their meaning.

## 4. The separate diagnostic field

Every incomplete/rate-limited outcome now carries a machine-readable cause,
independent of the status word:

* `Conexao_Import_Result::$incomplete_enumeration_reason` (surfaced as
  `incomplete_reason` in `get_stats()` and `incomplete_reason` on the history
  row);
* the checkpoint's own `last_failure` is the source of the code when the run did
  not carry one.

Rendered by `Conexao_Event_Sources::history_status_reason()`:

| Code | Shown as |
|---|---|
| `time_budget` | time budget exhausted |
| `page_cap` | page cap reached |
| `budget_exhausted` | request budget exhausted |
| `rate_limited` | rate-limit cooldown |
| `rate_limited_deferred` | rate-limit cooldown deferred (Retry-After) |
| `fetch_failed` | upstream fetch failure |
| `parse_failed` | parsing failure |
| `checkpoint_resume` | resumed from checkpoint |
| `incomplete_enumeration` | enumeration incomplete |

## 5. Rendered labels (not only internal return values)

`Conexao_Event_Sources::history_status_label()` — asserted in the suite:

| Stored status | Rendered |
|---|---|
| `completed` | completed |
| `completed_with_errors` | completed with errors |
| `success` | success |
| `warning` | completed with warnings |
| `partial` | incomplete or partial |
| `rate_limited` | **rate limited — incomplete** |
| `failed` / `error` | failed |
| `queued` / `running` | queued / running |

`Conexao_Event_Sources::last_import_status_label()` renders the registry value,
and `is_last_import_successful()` keeps a `partial`/`rate_limited`/`failed` run
out of the dashboard's “Last successful import” figure.

## 6. Registry status mapping (was: everything except `failed` became `success`)

`update_source_stats_after_run()` now maps explicitly:

| Result status | Stored `last_import_status` | Stored `last_error` |
|---|---|---|
| `success` | `success` | empty |
| `warning` | `warning` | empty |
| `partial` | `partial` | the cause (time budget / page cap / …) or “N event(s) failed…” |
| `rate_limited` | `rate_limited` | the cooldown message |
| `failed` | `error` (pre-Stage-3 value preserved) | the fatal message |

Backward compatibility: historical records can only hold `success` or `error`,
and both still mean exactly what they meant. The two existing readers
(`'error' === $last_import_status`) keep working.

## 7. Source health

| Result status | Health call | Why |
|---|---|---|
| `success`, `warning` | `record_success()` | the run completed |
| `failed` | `record_failure()` | a real failure |
| `partial`, `rate_limited` | `record_incomplete()` (new) | resets nothing and increments nothing: an incomplete run is neither |

`record_incomplete()` stores `last_incomplete`, `last_incomplete_status` and
`last_incomplete_reason`, which is additive (no existing key changed).

## 8. Parent aggregation is unchanged

`aggregate_parent_status()` was **not** modified. It still returns
`failed > rate_limited > partial > completed_with_errors > completed`, and a
queued/running continuation cannot make an unfinished logical run look complete
because a parent only becomes terminal through the final `record_parent()` call.
This is verified by `test-stage1-run-coordination` (47/0) which still passes.
