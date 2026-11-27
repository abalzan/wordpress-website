# Report — Event cleanup batch runner: retry accounting and resume determinism

| | |
|---|---|
| **Stage / task name** | Fix the final failing in-process suite (`plugin/conexao-event-importer/test-event-cleanup-batch-runner.php`) |
| **Date** | 2026-11-27 |
| **Author / agent** | Cline (agent), for the repository owner |
| **Branch** | `event-import-improvements` |
| **Start SHA** | `ee45a23490e4ae2cd40d4678bcdb6ea2660193a8` |
| **Final SHA** | uncommitted (the task forbids commit/push; working tree holds the change) |
| **Working tree at finish** | dirty — the files listed in §3 |
| **Skills followed** | `wp-repository`, `wp-write-in-process-test`, `wp-security-review`, `wp-update-docs` |
| **Authoritative docs consulted** | `docs/engineering-standard.md`, `docs/testing.md`, `docs/content-model.md`, `docs/evidence/2026-11-20-event-cleanup-scalability/03-design-decision.md`, `docs/plugins/conexao-event-importer.md` |

## 1. Scope completed

The single failing PHP suite from CI run
[45963391629](https://github.com/abalzan/wordpress-website/actions/runs/45963391629) is
green **and** the defects behind its failures are fixed at the source, not at
the assertion:

1. **Runtime fix — a retry deletes exactly the event it names.** The retry-queue
   path in `Conexao_Event_Cleanup_Run::process_retry_queue()` hard-deleted
   whatever post carried a queued ID and counted it as a deleted event. It now
   re-verifies the target (post exists, is an `event`, is still eligible under
   the unchanged §2.1 predicate) and resolves the entry without a deletion and
   without incrementing `events_deleted` otherwise.
2. **Runtime guard — `delete_event_transactional()` refuses a non-`event`
   post** before opening its transaction (fail-closed backstop on the only
   hard-delete primitive in the cleanup).
3. **Test determinism — the lifecycle and resume sections own their state.**
   Sections 22, 23, 24 and the new 24b/27 no longer depend on the shared test
   database containing no other cleanup candidates.
4. **Documentation** — the retry contract clarification is recorded in the
   authoritative design record (§4.7, rev. 15).

## 2. Scope NOT completed

- No commit, push, merge, deploy or production action (explicitly forbidden).
- The CI job itself was **not** re-run (it cannot be triggered from here without
  a push). Verification is local, using the same harness and the same worker
  count (`CONEXAO_TEST_WORKERS=3`).
- Two pre-existing latent issues were **found but deliberately not changed**
  (they are not implicated by the three failures, and changing them would be an
  unrelated behaviour change): see §13.

## 3. Files added / modified / deleted

| Path | Change | Note |
|---|---|---|
| `wp-content/plugins/conexao-event-importer/includes/class-event-cleanup-run.php` | modified | retry target re-verification; non-`event` backstop in the delete helper |
| `wp-content/plugins/conexao-event-importer/tests/test-event-cleanup-batch-runner.php` | modified | section 22 (own retry fixture, bounded window), 23 (bounded window), 24 split into 24/24b, new section 27, section 5 time-boundary determinism, header coverage list |
| `docs/evidence/2026-11-20-event-cleanup-scalability/03-design-decision.md` | modified | §4.7 "retry target re-verification (rev. 15)" clarification |
| `docs/reports/2026-11-27-event-cleanup-batch-runner-retry-accounting.md` | added | this report |
| `docs/evidence/2026-11-27-stage-l/gate.json` | added (generated) | `scripts/verify-permanent-gates.py` output for the verification run |

**4 modified, 2 added, 0 deleted.** Two of the modified files are under
`wp-content/`; no file outside the areas named above changed.


## 4. Runtime impact

WordPress behaviour changes in exactly one path:

| Situation | Before | After |
|---|---|---|
| Retry entry names an existing **non-event** post (page, guide, sponsor, attachment, revision) | the post was **hard-deleted** and counted in `events_deleted` | post untouched; entry resolved (removed when the persistent queue holds it); counter unchanged |
| Retry entry names an **event that is no longer eligible** (moved into the future, left `cleanup_scope`) | deleted and counted | untouched; entry resolved; counter unchanged |
| Retry entry names an **event that is gone** | resolved, not counted (unchanged) | same |
| Retry entry names a **still-eligible event** | deleted and counted (unchanged) | same |
| Retry entry whose deletion fails | attempt recorded, not counted (unchanged) | same |
| Any call to the delete helper with a non-`event` ID | `wp_delete_post()` ran | refused with `not_an_event`, nothing deleted, nothing counted |

No eligibility rule, no cursor rule, no lock/fence rule, no queue-capacity rule
and no attachment-preservation rule changed. The scan path (`process_batch`
main loop) is untouched.

## 5. Content / data impact

None on production. The verification runs wrote and deleted content **only** in
the disposable isolated test schema (`conexao_stage05_test_…`) through the
repository's own harness, exactly as the suite already does on every run. Local
leftovers from previously killed local runs (thousands of imported events and
`[CLEANUP TEST]` fixtures) were drained with the runner itself so the local
schema resembles the deterministic CI site; nothing outside the test schema was
touched.

## 6. Polylang impact

None. No PT record, slug or translation link is written. The change adds one
`get_post_type()` read and reuses the existing `is_eligible()` predicate. The
suite's PT-immutability and live-option byte-identity assertions still pass.

## 7. Route / HTTP impact

None. No route, redirect, filter, REST endpoint or sitemap path changed.

## 8. Production actions

| Action | Performed? | Detail |
|---|---|---|
| Production write | **no** | |
| Deploy / upload | **no** | |
| Plugin activation | **no** | |
| Content or DB mutation | **no** (production) | |


## 9. Verification commands and results

| Command | Exit | Result |
|---|---|---|
| targeted suite via `scripts/conexao-test-exec.sh` (isolated DB) | 0 | `Event Cleanup Batch Runner: 226 passed, 0 failed` |
| `./scripts/lint.sh` | 0 | `lint: OK (syntax clean, no new PHPCS violations, PHPStan clean)` |
| `shellcheck scripts/*.sh scripts/lib/*.sh` | 0 | no findings |
| `python3 scripts/verify-permanent-gates.py` (with the isolated-DB env) | 0 | `Gates: 15 total, 15 passed, 0 failed, 0 blocked`; `Assertions: 259 passed, 0 failed`; `AGGREGATE: PASS` |
| `CONEXAO_TEST_WORKERS=3 ./scripts/run-tests.sh --php` | see §9.1 | full in-process layer |

### 9.1 Numeric test results

Targeted suite (the CI failure): **226 passed, 0 failed** — CI reported
**183 passed, 3 failed** on the same file. The three failing assertions now pass;
the assertion count rose by 43 because of the added coverage.

Full in-process PHP layer (`CONEXAO_TEST_WORKERS=3 CONEXAO_TEST_TIMEOUT=900 ./scripts/run-tests.sh --php`):

```
In-process PHP suites: 153 total, 149 passed, 3 failed
Assertions: 11099 passed, 46 failed
WordPress bootstrap failures: 1
failing suites:
  - plugin/conexao-event-importer/test-import-continuation.php
  - theme/conexao-br-irlanda/test-stage41-rest-language.php
  - theme/conexao-br-irlanda/test-stage32-bilingual.php
```

**The suite this task targets passed inside that same run:**

```
[PASS] plugin/conexao-event-importer/test-event-cleanup-batch-runner.php (251.90s) — 226 passed, 0 failed
```

(The suite takes 46s in CI and 252s locally; the local per-suite timeout was
raised with the documented `CONEXAO_TEST_TIMEOUT`, which changes no assertion.)

The three remaining failures are **pre-existing local content conditions**, proven
by re-running each one with this change reverted (`git checkout --` on both
`wp-content` files, then restored and checksum-verified):

| Suite | With this change | With the change reverted (baseline `ee45a23`) |
|---|---|---|
| `test-import-continuation.php` | PHP fatal `array_filter(): Argument #1 must be of type array, null given` at line 1147 (G18.1 time budget stopped the walk at page 0) | **identical fatal at the same line** |
| `test-stage41-rest-language.php` | 185 passed, 37 failed | **185 passed, 37 failed** |
| `test-stage32-bilingual.php` | 41 passed, 9 failed | **41 passed, 9 failed** |

They need the deterministic pilot dataset (`festa-junina-dublin-2026`, …) or a
machine fast enough for a 1-second import budget; the local isolated schema is a
clone of the developer database (2,000+ real imported events), not
`bootstrap-ci-fixtures.php`'s site. Both `stage41` and `stage32` also appear as
failures in the **previous** CI run on this branch
([45899198328](https://github.com/abalzan/wordpress-website/actions/runs/45899198328))
and pass in the current one
([45963391629](https://github.com/abalzan/wordpress-website/actions/runs/45963391629)),
which is exactly the content-vs-environment distinction above.

Script contracts (`./scripts/run-tests.sh --scripts-static`): **19 suites, 19
passed, 0 failed** — including the documentation-drift gate (17 assertions) and
`verify-script-conventions.py` (803 assertions), so the new report and the
design-record edit keep the governance gates green.


## 10. Failure proofs / negative tests

**Vacuity proof (required by `wp-write-in-process-test`):** with the runtime fix
reverted (`git checkout --` on `class-event-cleanup-run.php`) and the new tests
kept, the suite reports **222 passed, 4 failed**:

```
FAIL: a retry naming a page does NOT delete the page
FAIL: a resolved non-event entry is not counted as a deletion — expected 20, got 21
FAIL: a retry naming an event that is no longer eligible does NOT delete it
FAIL: a resolved ineligible entry is not counted as a deletion — expected 20, got 21
Event Cleanup Batch Runner: 222 passed, 4 failed
```

With the fix restored: **226 passed, 0 failed**. The `expected 20, got 21` shape
is the local reproduction of CI's `expected 11, got 12`.

Discovery: `./scripts/run-tests.sh --list` →
`plugin/conexao-event-importer/test-event-cleanup-batch-runner.php [conexao-event-importer] SERIAL`
(glob discovery; no list edited anywhere).

| Other proof | Result |
|---|---|
| **Probe (throwaway, deleted):** seed a run whose retry queue names an existing **page** and run one batch — *before* the fix | page **deleted**, `events_deleted` 10 → **11** (isolates the CI mechanism) |
| Same probe *after* the fix | page **survives**, `events_deleted` stays **10** |
| Suite section 24 — resume with an exhausted cursor and an eligible fixture **above** the cursor | cursor/counters preserved **and** the fixture untouched (a reset to `PHP_INT_MAX` would delete it) |

## 11. Regression comparison

| | Before (CI run 45963391629) | After (local full layer, this harness) |
|---|---|---|
| Failing in-process suites | 1 (`test-event-cleanup-batch-runner.php`) | 3 — all three proven identical on the reverted baseline (§9.1) |
| Failing assertions (target suite) | 3 | **0** (`226 passed, 0 failed`, inside the full run too) |
| Failing script-contract | 0 | 0 (19/19 static suites; `shellcheck` clean) |
| Failing acceptance assertions | 0 | not re-run (untouched surface) |
| Stage L gates | 15/15 pass in CI | 15/15 pass locally |

The diff of the failing-suite list against the **local baseline** is empty: the
three local failures fail identically with this change reverted.

## 12. Known pre-existing failures

1. `theme/conexao-br-irlanda/test-stage32-bilingual.php` — 9 failures, all
   "pilot fixture missing" (the local isolated schema has no
   `festa-junina-dublin-2026` etc.). Identical on the baseline.
2. `theme/conexao-br-irlanda/test-stage41-rest-language.php` — 37 failures, same
   missing pilot dataset (`fixture present: event PT master (#0)`). Identical on
   the baseline, and also failed in the earlier CI run 45899198328.
3. `plugin/conexao-event-importer/test-import-continuation.php` — PHP fatal at
   line 1147: the 1-second G18.1 budget expires before the first page on this
   machine (`got 0`), so a `null` list reaches `array_filter()`. Identical on the
   baseline; the same class of failure appears in CI run 45899198328
   (`got 2`) and passes on the CI machine at HEAD.
4. Section 5 of the target suite (`Keyset cursor — no skip at time boundary`)
   failed on the first local verification run because it compared the cursor with
   `last_scanned_id` (the window end) after a 1-second budget — true only when
   the whole window fits in one second. It is unrelated to this change and has
   been re-encoded deterministically with an injected clock (§3).
5. The local test schema had accumulated thousands of imported events from
   earlier local importer runs, which made suites slow but never wrong; they were
   drained with the runner itself before the recorded runs.

## 13. Limitations

1. **No CI run.** The CI job was not re-executed from here (no push allowed); the
   local run uses the same harness, the same worker count and the same
   isolated-DB wrapper, but it is not the CI environment.
2. **Pre-existing, unchanged — keyset window application.**
   `fetch_candidate_ids()` runs `WP_Query` with `posts_per_page = scan_window`
   and *then* drops rows with `ID >= cursor_id` in PHP. If more than
   `scan_window` candidates sit at or above the cursor, the filtered window is
   empty and the run completes early. Not triggered by the three CI failures,
   not implicated in the fixed defect, deliberately left unchanged as an
   unrelated behaviour change.
3. **Pre-existing, unchanged — retry IDs and the scan cursor.** A retry entry
   that is resolved without a deletion still sets the batch's
   `last_committed_id` (already true of the "already gone" branch). If that same
   batch then stops at the time/failed-capacity boundary before committing a
   *scanned* event, the scan cursor adopts the retry's ID. The hazard pre-dates
   this change, needs a mid-batch stop to manifest, and no test implicates it.
   Documented here rather than changed.
4. **Concurrency.** CI runs three workers; this verification reproduced the
   worker count, but CI's exact interleaving is not reproducible.
5. **No production verifier.** Nothing in this task touches production, so no
   production audit was required or run.

## 14. Evidence paths

- `docs/evidence/2026-11-27-stage-l/gate.json` — the 15 permanent gates, all
  passing, with assertion counts parsed from each gate's own output.
- Suite output, probe output, lint and full-suite logs: local run logs held
  outside the repository (`/tmp/…`); the numbers are quoted in §9 and §10.

## 15. Documentation updated

| Document | Change |
|---|---|
| `docs/evidence/2026-11-20-event-cleanup-scalability/03-design-decision.md` | §4.7 gains "Retry target re-verification (rev. 15)": the retry premise (an eligible `event`), the three no-deletion outcomes, the queue-resolution rule and the fail-closed backstop |

No `_Last verified_` marker exists on that dated decision record; the living
reference set in `docs/README.md` is unchanged by this fix (no route, content
model or plugin-registry fact changed).

## 16. Rollback / recovery

`git checkout -- wp-content/plugins/conexao-event-importer/includes/class-event-cleanup-run.php`
and the test file restore the previous behaviour. Rollback re-introduces the
delete-by-ID defect in the retry path (a stale queue entry can destroy an
unrelated record), so it is only appropriate together with a decision to stop
using the retry queue. The documentation clarification is additive and safe to
keep.

## 17. Final status

**Final status: PASS WITH LIMITATION.**

The targeted suite (226 assertions, 0 failed — including inside the full
153-suite layer), `lint.sh`, ShellCheck, the 19 static script-contract suites and
all fifteen permanent invariant gates pass with real numbers. The limitation is
that the local full layer still shows three suites red for **local data/speed**
reasons that reproduce identically on the reverted baseline, and that the CI job
itself could not be re-executed from here (§13.1). CI remains the authoritative
verdict for the full layer.

