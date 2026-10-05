# G18 — automatic continuation of a resumable enumeration

| | |
|---|---|
| **Stage** | G18 — make "resumable" mean resumable automatically |
| **Date** | 2026-10-05 |
| **Branch** | `master` |
| **Start SHA** | `a22b4c6` |
| **Skills followed** | `wp-write-in-process-test`, `wp-update-docs`, `wp-repository` |
| **Authoritative docs consulted** | `AGENTS.md`, `docs/engineering-standard.md`, `plugins.json`, `docs/plugins/conexao-event-importer.md`, `docs/reports/2026-10-04-eventbrite-g10-checkpoint.md`, `docs/reports/2026-10-04-eventbrite-g13-operationalization.md`, `docs/reports/2026-10-05-eventbrite-g17-reconciliation-fix.md`, `docs/testing.md` |
| **Plan** | [`2026-10-05-import-continuation-scheduler-plan.md`](2026-10-05-import-continuation-scheduler-plan.md) |
| **Evidence** | `docs/evidence/2026-10-05-import-continuation/` |
| **Production files changed** | **0** |
| **Production writes / deployments** | **0** |

## 1. Summary

A source that became `incomplete` + `resumable` had **no owner**. The
checkpoint was persisted, the operator-facing cell promised
`page 17 of 49 (time_budget) — resumable`, and nothing would ever come back for
it: the importer had no scheduler of any kind, and `Conexao_Source_Cooldown`
documented that absence as deliberate policy.

This stage supplies the missing transition — `resumable → scheduled` — and
nothing else. A resumable checkpoint now receives a **future execution** with a
server-enforced **30-minute cooldown**, that execution resumes from the
checkpoint through the existing import path, and the chain repeats until the
enumeration completes, at which point it clears itself.

| Measure | Before | After |
|---|---|---|
| A resumable source continues on its own | **never** | **yes**, after a ~30-minute cooldown |
| Cooldown enforced server-side | n/a | yes (`run_at` re-checked at execution) |
| Browser required to keep the job alive | **yes** (a human pressing Run) | **no** |
| Import History can say "resumable" with nothing scheduled | **yes** | **never** |
| Pending continuations per source | n/a | exactly **1** |
| Sources that can continue in parallel | n/a | **per source** (no global lock) |
| Regression suite | — | **265 passed, 0 failed** |
| Mutation negative controls | — | **15 / 15 proven** |

## 2. Root cause

There was no scheduler. Not a broken one, not a disabled one — **none**, in any
form:

- `docs/plugins/conexao-event-importer.md` states the plugin is dormant unless
  invoked: *"there is no cron, polling, background sync"*;
- `Conexao_Source_Cooldown` states the policy in its own docblock: *"It never
  schedules or performs retries. There is no automatic retry loop. Cooldown
  only reports; the operator decides when to try again."*;
- `grep -rn 'wp_schedule_event\|wp_schedule_single_event'` over the importer
  returned **zero** hits before this change.

So the state machine was complete except for its last edge:

| Question | Answer found (before) |
|---|---|
| 1. Is the checkpoint written? | yes — `Conexao_Source_Enumeration_Checkpoint::record()` |
| 2. Is the source marked resumable? | yes, implicitly (`last_page < page_count`, `last_failure` set) |
| 3. Is a next-run time recorded? | only a 20/40/60 s per-request cooldown; no run-level time |
| 4. **Is a continuation job scheduled?** | **NO — this is the break** |
| 5–8. Persisted / picked up / invoked correctly | n/a, nothing was created |
| 9–13. Resume, cooldown, completion, clearing | correct — **but only if a run happened** |

Everything downstream of the break already worked and was already covered by 77
assertions in `test-eventbrite-checkpoint.php`. Nothing upstream of it existed.

## 3. Lifecycle before the fix — where the chain breaks

```
run starts
  → page 1..17 fetched, merged
  → time_budget reached
  → checkpoint persisted        (last_page=17, page_count=49, complete=false)
  → run reported INCOMPLETE     (status=warning, complete=false)
  → Import History says "page 17 of 49 (time_budget) — resumable"
  ───────────────────────────────────────────────────────────────────────
        ✗ THE CHAIN ENDS HERE.  Nothing is scheduled.
          The job waits for a human, forever.
  ───────────────────────────────────────────────────────────────────────
  (days later, an operator presses Run)
  → resume from page 17 …
```

The cell's wording was the defect made visible: `resumable` described **state**
while reading as a **promise**. `class-event-sources.php` printed it from the
existence of a checkpoint with a failure class and never consulted any
scheduler — because there was none to consult.

## 4. Lifecycle after the fix

```
run starts
  → checkpoint saved                          (G10, unchanged)
  → run becomes incomplete/resumable          (G2B/G10, unchanged)
  → reconcile() reads the shared predicate
  → continuation record persisted (state=scheduled, run_at)
  → ONE wp_schedule_single_event armed at run_at
  → cooldown expires                          (server clock; no browser)
  → run_due() takes the per-source lock
  → run_source() resumes at the checkpoint     (G10, unchanged)
  → reconcile() again  →  repeat
  → enumeration complete
  → continuation record cleared, event disarmed
```

`resumable`, `scheduled`, `running`, `exhausted` and `failed` are now five
separate, independently observable states, and Import History reports which one
is true rather than collapsing them all into "resumable".


## 5. Scheduling — the mechanism the repository already has

**WP-Cron.** One `wp_schedule_single_event( $run_at, HOOK, array( $source_id ) )`
per source. No recurring schedule, no job per page, no queue table, no Action
Scheduler dependency, no polling loop.

The **record** is the authoritative state; the cron event is only the timer.
Two triggers consume it, and both are server-side:

| Trigger | When it fires | Why it exists |
|---|---|---|
| `Conexao_Import_Continuation::HOOK` (WP-Cron) | at `run_at` | the mechanism; needs **no visitor at all** on a host whose cron path works |
| `shutdown` (priority 20) → `run_due()` | on any request | the fail-safe, see below |

`run_due()` re-checks `now() >= run_at` before doing anything, so a trigger that
fires early sends **nothing**. The per-source lock makes it single. It is not a
polling loop: no timer, no AJAX, no outbound request, and the import itself only
starts once a cooldown has actually expired.

### 5.1 Why the fail-safe is not optional (a measured environment fact)

WP-Cron is a **pseudo-cron**: `wp_cron()` runs on `init`, defers to
`_wp_cron()` on `shutdown`, and `_wp_cron()` → `spawn_cron()` posts to
`site_url( 'wp-cron.php' )`.

Measured in this project's Compose stack:

```
site_url( 'wp-cron.php' )  ->  http://localhost:8080/wp-cron.php
```

`8080` is published to the **host**; inside the container nothing listens on it.
So the spawned request never arrives, the event never fires, and
`spawn_cron()` has additionally set the 60-second `WP_CRON_LOCK_TIMEOUT`
transient. Verified directly with a probe event that stayed `DUE` in the cron
array across three full page loads and a direct `wp-cron.php` call.

Relying on the cron event alone would therefore have reproduced the *exact*
defect this stage exists to remove — a scheduled job that never runs. The
`shutdown` fail-safe closes that hole, and `rearm_missing()` (on `wp_loaded`)
restores a timer that a restart, a cleared cron array or an early fire consumed.

## 6. Cooldown

| Property | Value |
|---|---|
| Default delay | **1800 s (30 minutes)** |
| Floor | the base delay itself: no input can produce a shorter retry |
| Escalation on repeated no-progress attempts | 30 → 60 → 120 min |
| Ceiling | `MAX_BACKOFF_SECONDS = 7200` (2 h) |
| Clock | server only (`time()`), injectable for tests |

30 minutes is the conservative end of the accepted 20-30 minute band, and it is
not invented: G5 measured that a real Eventbrite 429 survives a 20-second
cooldown and repeats across an entire 56-source run, so a **run-level** retry
has to be an order of magnitude calmer than the in-walk ladder to be worth
anything.

**The existing ladder is preserved, not replaced.** `Conexao_Source_Cooldown`
still owns the in-walk throttle state and still escalates 20 → 40 → 60 s; the
continuation reads its `next_eligible` and refuses to run before it. The two
ladders compose: `run_at = max( now + continuation_delay, cooldown.next_eligible )`.

**No early retry.** `run_due()` compares the server clock against the persisted
`run_at` even when its own trigger says "now", so a request that arrives one
second early does nothing at all — asserted directly, including the upstream
request count (zero).

## 7. Concurrency — how duplicate workers are prevented

| Mechanism | Where |
|---|---|
| One logical continuation per source | `schedule()` returns an existing pending record untouched — never duplicated, never postponed |
| One WordPress event per source | `arm_cron()` clears the hook's args before arming (also sidestepping WP's own 10-minute duplicate refusal) |
| One worker per source | `acquire_lock()` on `conexao_event_import_lock_<source>`, taken with the atomic `add_option()` (a single INSERT against a unique key, so there is no read-modify-write window) |
| Crash recovery | the lock carries `LOCK_TTL = 900 s`; a lock older than its TTL is treated as abandoned and taken over |
| Stale worker | `run_source()` re-reads the checkpoint at resume time, so a stale worker cannot corrupt a newer position |

The lock is **per source**. Cork's lock never blocks Wicklow; the suite asserts
that directly. There is deliberately **no global lock** in this class.

## 8. Checkpoint — proof of correct page restoration

From the suite's own deterministic trace (a 20-page fixture standing in for the
observed 49-page listings):

```
source=…probe_cork | page 3 of 20 | time_budget | checkpoint persisted | resumable
                   | cooldown=1800s | continuation scheduled | run_at=1800003600
attempt 1 | cooldown expired | continuation ran | served pages [1,2,3,4,5,6]     | now page 6 of 20
attempt 2 | cooldown expired | continuation ran | served pages [1,5,6,7,8,9]     | now page 9 of 20
attempt 3 | cooldown expired | continuation ran | served pages [1,8,9,10,11,12]   | now page 12 of 20
attempt 4 | cooldown expired | continuation ran | served pages [1,11,12,13,14,15] | now page 15 of 20
attempt 5 | cooldown expired | continuation ran | served pages [1,14,15,16,17,18] | now page 18 of 20
attempt 6 | cooldown expired | continuation ran | served pages [1,17,18,19,20]    | now page 20 of 20
final page 20 of 20 | checkpoint complete=true | continuation state="" | phase=none
```

| Recorded fact | Value |
|---|---|
| Checkpoint before interruption | page 3 of 20, `last_failure = time_budget` |
| Page the first continuation resumes at | **2** = `last_page − OVERLAP_PAGES` (the documented boundary rule) |
| Page the second continuation resumes at | **5** = `last_page(6) − 1`; pages 2, 3 and 4 are **not** re-requested |
| Pages re-walked per resume | exactly 1 (the G10 overlap), never the prefix |
| Checkpoint trajectory | 3 → 6 → 9 → 12 → 15 → 18 → 20 — monotonic, never backwards |
| Final page / total | 20 / 20, `complete = true` |
| Final counts | `found = new + updated + unchanged + skipped` reconciles on the final run |

Page 1 is always re-fetched: it carries the pagination metadata the G8
truncation guard depends on. That is pre-existing behaviour and is unchanged.

## 9. Multi-source — independent continuations, no starvation

Five resumable sources (Cork, Wicklow, Wexford, Waterford, Tipperary) were
driven into the resumable state and then allowed to continue:

| Assertion | Result |
|---|---|
| Each source has its own continuation record | 5 records, 1 per source |
| Each source has its own armed event | 5 events, one per source, all distinct |
| Each source holds its own checkpoint | yes, each at its own page 3 |
| One pass runs all five | 5 runs, each source exactly once |
| Each completes independently | all five reached page 6 of 6, `complete = true` |
| Each clears its own continuation | map empty afterwards |
| No cross-source contamination | a Wicklow event is never stored under the Cork source |

No source's cooldown or lock blocks another: they are per source by
construction, and the "one pass" assertion proves no starvation because every
ready source in that pass ran.

## 10. UI — Import History and Import Logs tell the truth

The Progress cell is now derived from the **live scheduler state** through one
extracted helper, `Conexao_Event_Sources::history_progress_text()`, so the words
and the behaviour cannot disagree. Every shape below is asserted through that
production helper:

| Phase | Cell text |
|---|---|
| scheduled, cooldown running | `page 3 of 20 (time_budget) — resumable — next run in 30 minutes` |
| cooldown elapsed, worker imminent | `page 3 of 20 (time_budget) — resumable — resume scheduled` |
| a worker holds the source | `page 3 of 20 (time_budget) — resuming — a worker is running it now` |
| **outstanding pages, nothing scheduled** | `page 3 of 20 (time_budget) — NOT scheduled — no continuation exists` |
| scheduling failed | `page 3 of 20 (time_budget) — NOT scheduled — the continuation could not be scheduled` |
| stalled out | `page 3 of 20 (time_budget) — NOT scheduled — the automatic continuation stopped; press Run to resume` |
| enumeration finished | `page 20 of 20 — complete` |
| a superseded row | `page 3 of 20 (time_budget)` — the historical fact, and **no** lifecycle claim |
| no checkpoint | `—` (unchanged) |

The word **"resumable" appears in exactly the three phases where something will
actually run.** The defect shape — outstanding pages with no continuation — now
reads `NOT scheduled`, which is what makes the UI incapable of repeating the
original false promise.

The minute count comes from `seconds_remaining`, computed from the **server**
clock; nothing in the cell reads a browser time.

Import Logs gain the continuation lifecycle as structured entries, so the whole
story is reconstructable:

| `reason` code | Meaning |
|---|---|
| `continuation_scheduled` | a continuation was recorded and armed, with its `run_at` and cooldown |
| `continuation_started` | a worker began, naming the page it resumes from |
| `continuation_rearmed` | a lost timer was restored from persisted state (warning) |
| `continuation_exhausted` | the automatic chain stopped — **error** |
| `continuation_scheduling_failed` | the timer could not be armed — **error** |
| `continuation_exception` | a worker threw — **error** |

No log entry claims success while a source is incomplete, and the suite asserts
that no Import History entry is ever `success` while `complete` is false.



## 11. Tests

### 11.1 The new suite

`wp-content/plugins/conexao-event-importer/tests/test-import-continuation.php`

```
G18 continuation: 265 passed, 0 failed
```

| Section | What it proves |
|---|---|
| G18.1 | a time-budget stop persists a checkpoint **and schedules a continuation**; exactly one event is armed, at the recorded `run_at` |
| G18.2 | the cooldown is enforced server-side: a trigger before it sends **zero** requests and consumes nothing; at the boundary it runs exactly once; a no-progress attempt escalates 30 → 60 min; the ladder is bounded and floored |
| G18.3 | resume from the checkpoint (never the prefix), repeat, reach the final page, clear the record; the deterministic trace |
| G18.4 | the log reconstructs the lifecycle, and no entry reports success for an incomplete listing |
| G18.5 | duplicate protection: repeated reconciles, repeated schedules and two simultaneous triggers produce **one** continuation and **one** run |
| G18.6 | the lock blocks a second worker, is per source (Wicklow runs while Cork is locked), and self-heals when abandoned |
| G18.7 | a scheduling failure is `failed`, logged as an error, and shown as `NOT scheduled` — never as success |
| G18.8 | a stall is declared VISIBLY after `MAX_STALLED`, and real progress clears it |
| G18.9 | a **rate-limited** stop keeps the checkpoint, removes nothing, hides nothing, schedules its own retry, makes no request before the cooldown, then resumes and completes |
| G18.10 | five sources continue independently in one pass, complete independently, and do not contaminate each other |
| G18.11 | the chain survives a restart that clears the entire cron schedule; the timer is re-armed at the same `run_at` |
| G18.12 | re-running a completed source creates **no** new events, no native id exists twice, and the G17 reappearance restoration still works |
| G18.13 | a **dry run** neither creates nor clears a continuation |
| G18.14 | Import History is truthful in all nine phases |

Determinism: an injected pacing clock and an injected continuation clock replace
wall-clock time, a `pre_http_request` double replaces the upstream, and the
suite sends **no real request and sleeps zero seconds**.

Test hygiene: the suite creates several hundred events, so its cleanup **pages
until a pass finds nothing** rather than deleting a single capped batch. A capped
first attempt left one event behind, which is exactly the kind of residue that
would later be mistaken for content drift; after the fix the measured state
leaves `_event_source LIKE 'g18%'` at **0 rows**, with zero continuation records
and zero armed events. The suite also isolates
`Conexao_Import_Continuation::OPTION_KEY`, and so do the six sibling suites that
drive the engine (see §12).

### 11.2 Mutation negative controls

`tests/experiments/g18-mutations.sh` breaks exactly one mechanism per mutation
and requires the suite to FAIL. A green suite under a mutation is reported as
`VACUOUS` and fails the script.

| id | Mutation | Expectation |
|---|---|---|
| M1 | the engine never reconciles | no continuation is ever created |
| M2 | the trigger is never armed | scheduled, but nothing will run |
| M3 | the cooldown is not enforced | a retry happens before it expires |
| M4 | the delay becomes 1 s | the 20-30 minute policy is gone |
| M5 | the resumable predicate is bypassed | a finished listing is rescheduled |
| M6 | `complete` is ignored by the predicate | an upstream-truncated listing retries forever |
| M7 | duplicate protection removed | one source acquires many jobs |
| M8 | the source lock is bypassed | two workers resume one source |
| M9 | the lock becomes global | one source blocks the others |
| M10 | the checkpoint is ignored on resume | the walk restarts from the beginning |
| M11 | the stall guard removed | a stalled source retries forever |
| M12 | a scheduling failure is hidden | the UI claims resumable while nothing runs |
| M13 | a dry run schedules | classification mutates scheduling state |
| M14 | a lost timer is never re-armed | a restart strands the job |
| M15 | the arming-failure branch is skipped | a failed schedule is reported as scheduled |

### 11.2.1 One mutation was VACUOUS, and was fixed rather than argued away

The first run of this script reported **M6 VACUOUS**: the suite stayed green
when `is_resumable()` stopped testing the checkpoint's `complete` flag.

The reason was real and worth recording. For a *reachable* state the `complete`
flag and the `page_count` comparison always agree — the walk sets `complete`
exactly when the confirmed page reaches the declared page count — so removing
one of the two terms changes no reachable answer. That made the term
undetectable, and it also means the justification originally written in the
docblock ("otherwise an upstream-truncated listing would be rescheduled
forever") was **not accurate**: the page-count term already excludes that case.

Two changes followed, rather than deleting the term or weakening the mutation:

1. the predicate's docblock now states what the term actually means — a
   generation that finished is finished, independently of what the page-index
   comparison happens to say — and the inaccurate justification is gone; and
2. the suite gained a **predicate-precision** block that asserts the flag
   directly, including the case that distinguishes it: a record whose
   generation is `complete` is **not** resumable even when a later listing
   declares more pages, while the same record with `complete = false` **is**.

M6 is now proven (see `02-mutations.txt`), and the earlier vacuous result is
kept in this report because it is the evidence that the mutation controls were
doing their job.


### 11.3 Verification commands and numbers

The exact numbers for `./scripts/run-tests.sh`, `./scripts/lint.sh`, the
registry drift gate and the permanent gates are recorded in
`docs/evidence/2026-10-05-import-continuation/` and summarised in §17.


## 12. Files added / modified

| Path | Change |
|---|---|
| `wp-content/plugins/conexao-event-importer/includes/class-conexao-import-continuation.php` | **new** — the continuation state machine, cooldown, per-source lock, triggers |
| `.../includes/class-source-enumeration-checkpoint.php` | `is_resumable()` — the single "pages still outstanding" predicate |
| `.../tests/test-error-handling.php`, `test-eventbrite-checkpoint.php`, `test-import-log-run-correlation.php`, `test-request-budget.php`, `test-source-fetch-failure.php`, `test-source-pagination-completeness.php` | each isolates `Conexao_Import_Continuation::OPTION_KEY` — the new option the engine writes must not leak between suites |
| `.../includes/class-event-importer.php` | `run_source()` reconciles the continuation for every real (non-dry) run |
| `.../includes/class-event-sources.php` | the Progress cell extracted to `history_progress_text()` and made phase-truthful |
| `.../conexao-event-importer.php` | loads the new class; registers the cron hook, the `shutdown` fail-safe and `rearm_missing()` |
| `.../tests/test-import-continuation.php` | **new** — 265 assertions |
| `tests/experiments/g18-mutations.sh` | **new** — 15 negative controls |
| `.../languages/conexao-event-importer.pot` | regenerated (§9.3) |
| `docs/plugins/conexao-event-importer.md` | documents the continuation, its cooldown and the UI states |
| `docs/reports/2026-10-05-eventbrite-g18-continuation.md` | this report |
| `docs/reports/2026-10-05-import-continuation-scheduler-plan.md` | the plan |
| `docs/reports/README.md` | index entry |

Nothing under `wp-content/plugins/conexao-event-runtime` was modified: the G17
reconciliation exemption and the `_event_status` public gate are untouched.

## 13. Limitations

1. **WP-Cron does not spawn in this Compose stack** (measured: the loopback
   target `http://localhost:8080/wp-cron.php` is not reachable from inside the
   container). The continuation therefore executes through the `shutdown`
   fail-safe on the next request. On a host whose cron path works, the same
   single event fires with no request at all. This is an environment limitation,
   not a mechanism limitation: the scheduler never depends on the browser.
2. **Pseudo-cron needs a request.** WordPress has no process that wakes up on
   its own. The fix makes the continuation independent of the **browser** — no
   tab, no JS, no AJAX, no admin page, no operator action — and independent of
   *which* request arrives. A site receiving no request at all and running no
   system cron would still need a real cron entry, which is the standard
   WP-Cron deployment note.
3. **An exhausted continuation stops.** After `MAX_STALLED` consecutive
   no-progress attempts (or the attempt ceiling), the chain stops and says so.
   That is deliberately visible rather than silent, but it is a stop.
4. **Local-only tooling.** `conexao-event-importer` is `production: false`, so
   none of this reaches the production steady state, which has no CLI and no
   scheduler of this kind by design.
5. **No live Eventbrite traffic was used**, per the brief: the rate limit is
   produced by a deterministic transport double, never by abusing the API.

## 14. Pre-existing failures and environmental notes

| Item | Status |
|---|---|
| `plugin/conexao-event-importer/test-export-language.php` | **pre-existing failure**, unchanged by this stage: `Allowed memory size of 536870912 bytes exhausted` in `class-event-export.php:937`. Present in the baseline taken before any edit. |
| `conexao-event-runtime` `.pot` staleness | **pre-existing**, recorded in the Stage L baseline; untouched here. |

## 15. Pre-existing behaviour deliberately preserved

| Property | Verified by |
|---|---|
| A resumed walk never authorises absence reconciliation | G18.3 asserts the whole chain hid **nothing** (`source_not_found = 0`) |
| A resumed walk is not recorded as a complete *listing* | G18.3 asserts no entry is ever both `success` and `complete = false` |
| G17 reappearance restoration | G18.12 hides an event, re-imports, and asserts exactly one record remains and is visible again |
| In-walk cooldown ladder, request budget, pacing, overlap, truncation guard | untouched; G18.3 asserts `OVERLAP_PAGES = 1` as a control |
| G7 token retirement | the new class contains no credential, no token and no client |
| No time-budget or request-budget increase | `TIME_BUDGET_SECONDS = 45`, `MAX_PAGES = 50` — unchanged |


## 16. Acceptance criteria — item by item

| # | Criterion | Where it is proven |
|---|---|---|
| 1 | A `time_budget` interruption produces a persistent checkpoint | G18.1 |
| 2 | A `rate_limited` interruption produces a persistent checkpoint | G18.9 |
| 3 | The source is marked resumable | G18.1, G18.9 (`is_resumable`) |
| 4 | A continuation is actually scheduled | G18.1 (record **and** armed event at the same `run_at`) |
| 5 | It does not execute before the cooldown expires | G18.2, G18.9 (zero runner calls **and** zero upstream requests) |
| 6 | The default cooldown is ~30 minutes | G18.1 (`COOLDOWN_SECONDS = 1800`), G18.2 (floor and cap) |
| 7 | It occurs without the browser remaining open | §18.2 — a request issued with no browser at all drives it; the mechanism is server-side |
| 8 | It resumes from the correct checkpoint | G18.3 (the second continuation starts at `last_page − 1`; pages 2-4 are not re-requested) |
| 9 | It eventually reaches `complete` | G18.3 (20/20, `complete = true`, record cleared), G18.9, G18.10, G18.11 |
| 10 | Multiple resumable sources continue independently | G18.10 (five sources, one pass, each exactly once) |
| 11 | Duplicate continuation jobs are prevented | G18.5 |
| 12 | Concurrent workers cannot corrupt one source's state | G18.6 (the lock blocks the second worker), G18.5 (two triggers → one run) |
| 13 | No duplicate events are introduced | G18.12 (count unchanged; ≤1 record per native id) |
| 14 | G17 reappearance restoration remains intact | G18.12 (hidden → restored, still one record, visible again) |
| 15 | Import History never claims resumable with no continuation | G18.14 (the orphan, failed and exhausted phases all read `NOT scheduled`) |
| 16 | Import Logs describe the interrupted/resumed lifecycle | G18.4 (scheduled/started present; no false success) |
| 17 | No arbitrary request/time-budget increase | §15 (`TIME_BUDGET_SECONDS = 45`, `MAX_PAGES = 50`, request budget unchanged) |
| 18 | Full tests and permanent gates executed with numeric evidence | §17 |
| 19 | No production changes | `plugins.json` marks the plugin `production: false`; no production host was contacted and no deployment ran |

## 17. Verification — commands and results

Recorded in `docs/evidence/2026-10-05-import-continuation/`. Every run below is
local-only; the plugin is `production: false` in `plugins.json`.

| Command | Result |
|---|---|
| `./scripts/run-tests.sh` (final tree) | **100 in-process suites (96 passed), 7712 assertions passed / 6 failed, 19 script-contract suites (17 passed), 4 acceptance suites (3 passed)** — full output in `03-baseline-and-full-run.txt`; **every failure attributed in `08-failure-attribution.txt`** |
| `./scripts/run-tests.sh --only conexao-event-importer` (final tree) | 32 suites, 30 passed / 2 failed; 2663 assertions passed / 1 failed — both failures pre-existing |
| `test-import-continuation.php` (inside both runs) | **265 passed, 0 failed** |
| `./scripts/lint.sh` | **OK** — no parse errors, no new PHPCS violations against the baseline, PHPStan level 5 clean (`04-static-quality.txt`) |
| `php scripts/generate-registry-docs.php --check` | `registry OK: 10 plugins validated, 19 generated regions current (zero writes)` |
| `python3 scripts/verify-permanent-gates.py` | 7 gates, **6 passed / 1 failed**, `violations: 1 (1 pre-existing, 0 new)` — the pre-existing `conexao-event-runtime` catalogue staleness |
| `python3 tests/scripts/verify-i18n-freshness.py` | 3 passed / 1 failed, **"stale catalogues: 1 pre-existing, 0 new"** — the importer catalogue this stage owns is fresh |
| `python3 tests/scripts/verify-script-conventions.py` | 735 passed, 0 failed |
| `python3 tests/scripts/verify-documentation-drift.py` | 14 passed, 0 failed |
| `tests/experiments/g18-mutations.sh` | **15 / 15 PROVEN, 0 vacuous** (`02-mutations.txt`) |
| `python3 tests/browser/verify-import-continuation-browser.py` | **20 passed, 0 failed** (`06-browser-continuation.txt`) |
| `python3 tests/browser/verify-import-continuation-browser.py --negative-control` | the cell stops saying `resumable` when scheduling is broken — the browser proof is not vacuous |

### 17.1 Every failing suite, attributed

| Suite | P/F | Attribution |
|---|---|---|
| `plugin/conexao-event-importer/test-export-language.php` | fail (exit 255) | **pre-existing** — OOM in `class-event-export.php`, present in the baseline taken before the first edit |
| `plugin/conexao-event-importer/test-town-sanitization.php` | 82/1 | **content drift** — the local database holds 272 `conexao_town` terms (measured: 0 Eircode-shaped), while the assertion expects the CI fixture site's set |
| `theme/conexao-br-irlanda/test-event-location-filters.php` | 62/1 | **content drift** — "all four test events" is a fixture expectation; the local site has thousands of real events |
| `theme/conexao-br-irlanda/test-stage41-rest-language.php` | 218/4 | **content drift** — needs a PT master + EN translation pair in county Dublin; the local durable Dublin records are single-language |
| `tests/scripts/verify-eventbrite-token-retirement.py` | fail | **pre-existing** — the named file (`tests/experiments/g16-source-eligibility.php`) belongs to G16 and is unmodified by this stage |
| `tests/scripts/verify-i18n-freshness.py` | fail | **pre-existing and baseline-recorded** — the `conexao-event-runtime` catalogue; 0 new |
| `tests/acceptance/verify-event-occurrence-http.py` | fail | **content drift** — the three CI fixture events exist in the local database but are marked `source_not_found` (hidden by an earlier Laois walk), so the Laois archive shows other series instead |

**Direct A/B.** The scheduler was disabled with a single `return` in
`Conexao_Import_Continuation::reconcile()` and the three in-process failures were
re-run. The numbers are **identical** (82/1, 62/1, 218/4), so this stage causes
none of them.

**Why the local run differs from CI.** CI's integration job builds the
deterministic synthetic site from the committed fixtures in an **ephemeral**
Docker WordPress and runs this same command (`AGENTS.md`, `docs/testing.md`). A
developer's local stack is a restored production-content database plus
accumulated local artefacts, so content-dependent assertions legitimately
differ. This stage changed neither those assertions nor the content they read.

**End state on this machine.** Zero continuation records, zero armed
continuation events, and zero `_event_source LIKE 'g18%'` rows: the probe
source, its events and its timers are all removed.


## 18. The final required proof

### 18.1 The deterministic trace

Produced by the suite itself, over an injected clock — **no real sleeping and no
real HTTP**:

```
source=…probe_cork (page 3 of 20)
  → time_budget
  → checkpoint persisted
  → resumable
  → cooldown = 1800 s
  → continuation scheduled (run_at = now + 1800)
  → cooldown not yet expired: NO request, NO run
  → cooldown expired
  → continuation executes
  → resume from page 3 (served 1,2,3,4,5,6)
  → … repeated …
  → final page 20 of 20
  → complete (checkpoint complete=true, continuation record cleared, nothing armed)
```

Asserted at every arrow; the raw output is
`docs/evidence/2026-10-05-import-continuation/01-g18-suite.txt`.

### 18.2 The browser is closed — and the server carries on

`tests/browser/verify-import-continuation-browser.py`, a real Chromium driven
through Playwright (the repository's opt-in browser layer in `tests/browser/`):

| Phase | What happened |
|---|---|
| 0 | a probe source was driven into the resumable state over a **local static fixture** — no external traffic, no API abuse |
| 1 | Chromium logged in and opened Import History. The cell read **`page 1 of 6 (time_budget) — resumable — next run in 1 minute`**. **The browser was then closed.** |
| 2 | a plain HTTP request before the cooldown: the phase stayed `cooling`, the page did **not** advance, the continuation was not consumed |
| 3 | the real, short cooldown window was waited out on a real clock |
| 4 | a plain HTTP request after it: **the server advanced the walk with no browser open** |
| 5 | plain requests only, until the enumeration completed |
| 6 | the browser was opened once more, and the cell read **`page 6 of 6 — complete`** |

```
browser proof: 20 passed, 0 failed
```

**The browser negative control.** `--negative-control` breaks the arming
(`arm_cron` returns false without scheduling) and then reads the same page. The
continuation is recorded `failed`, and the cell reads:

```
page 1 of 6 (time_budget) — NOT scheduled — the continuation could not be scheduled
```

```
negative control: 4 passed, 0 failed
```

That is the defect this stage exists to remove, asserted from the browser: a
source that cannot be continued says so instead of promising `resumable`. Exit
code `2` means the environment (Playwright, the site, or the account) was
unavailable and is **never** reported as a pass.

### 18.3 A second continuation cannot run for the same source

Proven three ways, because "cannot" deserves more than one argument:

1. **Same process, two triggers** — two `run_due()` calls at the same instant:
   the first runs the continuation, the second finds it consumed and
   re-schedules it; the runner is invoked **once** and the second call makes
   **zero** upstream requests (G18.5).
2. **A held lock** — with the source lock taken by another worker, `run_due()`
   returns `locked`, the runner is **not** invoked and **zero** upstream requests
   are made (G18.6).
3. **A real concurrent request** — with a worker holding the lock, a plain HTTP
   request is served without starting a second run, because the record's state
   and the per-source lock are persisted, not in memory.

## 19. Status

**PASS.** Every acceptance criterion in §16 is proven by a named assertion; the
suite is green (`265 passed, 0 failed`); all 15 mutation negative controls are
proven; the browser layer is green with its own negative control; and no
production state was touched. Every suite failure in the full run is attributed
in §17.1, including a direct A/B that rules this stage out as their cause. The
limitations in §13 are environmental or deliberate, and they are stated rather
than hidden.
