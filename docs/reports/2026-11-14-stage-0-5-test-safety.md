# Report — Stage 0.5: Event Importer test isolation

> **Verdict: `BLOCKED`** for *runtime* verification. The implementation is
> complete and statically proven (14-check gate, 227 assertions, negative
> controls N1–N8, lint clean); the runtime suites could not be run because the
> prescribed verification sequence's **step 2 itself** (`./scripts/run-tests.sh
> --scripts`, specifically the pre-existing `verify-stage14-secret-scan.py`
> gate) booted WordPress, and the importer's pre-existing `shutdown` hook ran a
> due `eventbrite_cork` continuation against the shared local database: **47
> event rows created, 13 updated, 0 removed**. Per the task's stop rule, the
> sequence was halted there and nothing was restored or overwritten. See §10
> and `docs/evidence/2026-11-14-stage-0-5/09-*`.

| | |
|---|---|
| **Stage / task name** | Stage 0.5 — Event Importer test isolation |
| **Date** | 2026-11-14 |
| **Author / agent** | Cline (agent) |
| **Branch** | `event-import-improvements` |
| **Start SHA** | `cda8b7b10b1e8112afc09222b70472bfc5d59ad7` |
| **Final SHA** | `cda8b7b10b1e8112afc09222b70472bfc5d59ad7` (no commit — not authorised) |
| **Working tree at finish** | dirty: the implementation + docs + evidence (uncommitted, as instructed), plus the Stage 0.5 plan and audit that were already untracked |
| **Skills followed** | `wp-testing`, `wp-write-in-process-test`, `wp-repository`, `wp-security-review` (read-only), `wp-update-docs` |
| **Authoritative docs consulted** | `docs/engineering-standard.md`, `docs/testing.md`, `docs/plugins/conexao-event-importer.md`, `docs/plans/2026-11-14-stage-0-5-test-safety-plan.md`, `docs/reports/2026-11-14-stage-0-5-test-safety-audit.md` |

## 1. Scope completed

| # | Requirement | Where |
|---|---|---|
| 1 | Four store accessors (`option_key()`, `status_option()`, `history_option()`), test prefixes, strict suffix validation, `RuntimeException` instead of a production fallback | the three importer classes |
| 2 | Per-process suffix `p<pid>_<16 hex>` established **before** `wp-load.php`; pre-defined suffix refused; pid/token validated; production host refused; post-load accessor verification; shutdown cleaner at priority 999 | `tests/bootstrap.php` |
| 3 | Restrictive helpers: allowlist, resolver with a closed reason vocabulary, validated writer, validate-all-then-delete reset, guard; `test_options_isolate()`/`test_options_restore()` refuse the four stores | `tests/lib/option-isolation.php` |
| 4 | 18 discovered suites + 1 experiment migrated (guard placement, redirected keys out of `test_options_isolate()`, the 8 direct writes retargeted); live-row assertions added | the 19 suite files |
| 5 | Destructive cleanup removed: no `run_cleanup()` in the suite, no live `delete_option()`, sentinel assertion, validated reset; the manual script no longer deletes operator rows | `test-event-cleanup-purge.php`, `scripts/test-event-cleanup.php` |
| 6 | Eight suites demoted to `SERIAL`; the stale parallel-safety comment corrected | `scripts/run-tests.sh` |
| 7 | Acceptance suite implementing the twelve criteria (114 assertion call sites) | `test-operator-log-isolation.php` |
| 8 | Controlled **class-level** production-path smoke test — no WordPress, no database, no live write | section J of the acceptance suite |
| 9 | 14-check static gate + negative controls N1–N8 on scratch copies | `tests/scripts/verify-operator-log-isolation.py` |
| 10 | Documentation, evidence and this report | `docs/plugins/conexao-event-importer.md`, `docs/testing.md`, `scripts/README.md`, `docs/README.md`, `docs/reports/README.md`, `docs/evidence/2026-11-14-stage-0-5/` |

## 2. Scope NOT completed

| Item | Status | Why |
|---|---|---|
| `./scripts/run-tests.sh --only conexao-event-importer` | **not run** | stop rule: event content changed before this step (§10) |
| Full suite, `CONEXAO_TEST_WORKERS=1` and `=3` | **not run** | same |
| Repeatability run (twice) | **not run** | same |
| Acceptance suite **execution** | **not run** | same. It is written and syntactically valid but has never been executed |
| Orphan count after a clean run | **not measured** (only the pre-run orphans are recorded) | same |
| Plan precondition `P6` (no import running) | **not checked before step 2** | this is the process gap that allowed the incident (§10) |

No assertion was weakened to obtain a pass; no suite was run and reported as green.

## 3. Files added / modified / deleted

**3 added, 26 modified, 0 deleted** (evidence files excluded from the count).

| Path | Change | Note |
|---|---|---|
| `wp-content/plugins/conexao-event-importer/includes/class-import-log.php` | modified | `TEST_OPTION_PREFIX`, `option_key()`, `test_option_suffix()`; 4 call sites |
| `wp-content/plugins/conexao-event-importer/includes/class-import-history.php` | modified | same shape; 3 call sites |
| `wp-content/plugins/conexao-event-importer/includes/class-event-cleanup.php` | modified | `status_option()`, `history_option()`, two prefixes, `test_option_suffix()`; 4 call sites |
| `tests/bootstrap.php` | modified | suffix **before** `wp-load.php`, pre-define refusal, pid/token validation, local-host allowlist, post-load verification, shutdown cleaner @999 |
| `tests/lib/option-isolation.php` | modified | allowlist, resolver, writer, reset, guard, refusal rules |
| `wp-content/plugins/conexao-event-importer/tests/test-import-log.php` | modified | guard; snapshot removed; 2 writes retargeted; write counter split (test row vs live); live byte-identity assertions |
| `…/tests/test-import-log-run-correlation.php` | modified | guard; redirected keys removed from the isolate list; 1 write retargeted; G3A section rewritten around the test store; live byte-identity assertions |
| `…/tests/test-import-history-dashboard.php` | modified | guard; 2 keys removed; 4 writes retargeted; render assertions extended to the row the renderer reads |
| `…/tests/test-import-history-logs.php` | modified | guard; both keys removed (no snapshot left); 1 write retargeted; render assertions extended |
| `…/tests/test-event-cleanup-purge.php` | modified | guard; `run_cleanup()` removed; per-fixture assertions; past-event sentinel; validated reset; live byte-identity assertions |
| `…/tests/test-import-settings-retirement.php`, `test-eventbrite-checkpoint.php`, `test-error-handling.php`, `test-eventbrite-api-contract.php`, `test-eventbrite-rate-limit.php`, `test-eventbrite-source-expansion.php`, `test-eventbrite-token-retirement.php`, `test-import-continuation.php`, `test-request-budget.php`, `test-request-retry-policy.php`, `test-request-reuse.php`, `test-source-fetch-failure.php`, `test-source-pagination-completeness.php` | modified | guard added; only the redirected keys removed from `test_options_isolate()` |
| `…/tests/g10-convergence-experiment.php` | modified | guard; redirected keys removed |
| `wp-content/plugins/conexao-event-importer/tests/test-operator-log-isolation.php` | **added** | the acceptance suite (sections A–K) |
| `tests/scripts/verify-operator-log-isolation.py` | **added** | the 14-check static gate |
| `scripts/test-event-cleanup.php` | modified | two `delete_option()` calls removed; local-write manual-tool header |
| `scripts/run-tests.sh` | modified | 8 `PARALLEL` entries removed; parallel-safety rule rewritten |
| `docs/plugins/conexao-event-importer.md`, `docs/testing.md`, `scripts/README.md`, `docs/README.md`, `docs/reports/README.md` | modified | documented seam, isolation rules, orphan sweep, gate, script row, index entries |
| `docs/reports/2026-11-14-stage-0-5-test-safety.md` | **added** | this report |
| `docs/evidence/2026-11-14-stage-0-5/` | **added** | 25 evidence files |

Nothing outside `wp-content/plugins/conexao-event-importer/`, `tests/`, `scripts/`
and `docs/` changed. The Stage 0 plan, report and evidence are byte-untouched.

## 4. Runtime impact

Production behaviour is unchanged by design:

- with neither harness marker defined, each accessor returns its existing
  production constant — one `defined()` check, no hook, no filter;
- the option names, autoload flags (`false`), caps (2000 / 100 / 50),
  sanitisation, buffering, flush priority (5) and retention (90 days, P13) are
  untouched;
- the only new production-adjacent behaviour is a `RuntimeException` that can
  fire only when a harness marker is defined.

Verified by the class-level smoke test (§9.2) and gate checks 1–3 and 11.
**Not** verified end-to-end against a live write: this stage deliberately does
not write the operator options.

## 4a. Scope integrity

| Check | Result |
|---|---|
| No production write, deploy, upload or activation | **violated indirectly, see §10** — a pre-existing `shutdown` hook imported 47 events into the *local* database during a test run. No production (conexaobr.ie) access of any kind |
| No Flutter/mobile repository accessed | confirmed |
| No `.env` or secret change | confirmed |
| No commit, push, merge, deploy, release or branch switch | confirmed — `HEAD` is still `cda8b7b1` |

## 5. Content / data impact

| Measure | Value |
|---|---|
| Event rows before | 3640 |
| Event rows after the incident | 3687 |
| Created | **47** (`_event_source = eventbrite_cork`) |
| Updated | **13** (same source) |
| Deleted | **0** |
| Operator options changed | **none** of the four protected stores |
| `conexao_event_sources` | changed (`0949ae6f…` → `d7963c00…`), import bookkeeping |

Full diff: `docs/evidence/2026-11-14-stage-0-5/09-content-change.md`.

## 6. Polylang impact

None. No post, term, translation or language assignment was created or changed by
this stage; the 47 events created by the incident are ordinary PT source records
with no translation work attached.

## 7. Route / HTTP impact

None. No route, rewrite, REST endpoint, redirect or template changed.

## 8. Verification results (actual)

| # | Command | Result |
|---|---|---|
| 1 | `php -l` on all changed/added PHP files | **passed** — 0 syntax errors (`03-php-syntax.txt`) |
| 2 | `./scripts/run-tests.sh --scripts` | **22 of 24 passed**, 2 pre-existing failures; **the new gate passed** (`04-scripts-gate.txt`) |
| 2a | `verify-i18n-freshness.py` | **failed — pre-existing**: it compares *git commit* timestamps; the three catalogues were committed 2026-11-06, their sources later. All six paths are clean in `git status` (`04c-preexisting-script-failures.md`) |
| 2b | `verify-stage14-secret-scan.py` | **TIMEOUT at 300 s** in this environment. This is the gate that triggered the incident (§10) |
| 3 | Operator-option baseline export | captured (`01-*`, `05-*`); no credential appears in any evidence file |
| 4 | Environment identity | local Compose install, `home = http://localhost:8080` (`05b-environment-identity.md`) |
| 5 | `./scripts/run-tests.sh --only conexao-event-importer` | **not run** (stop rule) |
| 6–11 | Re-exports, serial/parallel modes, repeatability | **not run** (stop rule) |
| 12 | Orphan classification | pre-run orphans recorded (`09-orphan-test-rows.txt`): 4 test rows from two **killed** processes |
| 13 | Gate negative controls N1–N8 | **all 8 behaved as required**; an unmodified scratch copy passes (`08-gate-negative-controls.txt`) |
| 14 | `./scripts/lint.sh` | **passed**: syntax clean, no new PHPCS violations, PHPStan level 5 clean (`10-lint.txt`) |
| 15 | Static gate on the working tree | **passed**: 227 assertions, 14 checks, `GATE PASS` |
| 16 | Accessor branch probe — the **real** classes, no WordPress, no database | **passed**: production mode resolves all four production names; `p4321_abcdefabcdefabcd` resolves all four test keys; missing and malformed suffixes throw in all four (`07-accessor-branch-probe.txt`) |
| 17 | Isolation-helper logic probe (stubbed stores, `delete_option()` only recorded) | **passed**: 20/20 — resolver reason vocabulary, mixed request deletes nothing, duplicate refused, two valid stores delete exactly two, empty request refused (`06-helper-logic-probe.txt`) |

### Operator-option hashes

| Moment | import_log | import_history | cleanup_status | cleanup_history | sources |
|---|---|---|---|---|---|
| Baseline (before any WP process) | `b6e8b137…` | `56c9b788…` | `3f4d9bfe…` | `84d44b6e…` | `0949ae6f…` |
| Before runs (after step 2) | `b6e8b137…` | `56c9b788…` | `3f4d9bfe…` | `84d44b6e…` | `fcb88cd2…` |
| After the incident | `b6e8b137…` | `56c9b788…` | `3f4d9bfe…` | `84d44b6e…` | `d7963c00…` |

**The four protected operator options are byte-identical throughout**, including
across a real import that produced ~80 KB of diagnostics. That is the strongest
runtime evidence this stage can offer: the logging seam held under a real run.
Full SHA-256 values: `01-operator-options-before.txt`,
`05-operator-options-before-runs.txt`, `09-operator-options-after-unexpected-change.txt`.

### Static assertion inventory

Measured with the same command before and after (`02-assertion-inventory-before.md`,
`02-assertion-inventory-after.md`). These are call sites, not runtime assertions.

| Suite | Before | After |
|---|---|---|
| `test-import-log.php` | 55 | 60 |
| `test-import-log-run-correlation.php` | 21 | 24 |
| `test-import-history-dashboard.php` | 120 | 121 |
| `test-import-history-logs.php` | 77 | 79 |
| `test-event-cleanup-purge.php` | 34 | 42 |
| the other 13 store-touching suites | unchanged | unchanged |
| all 37 discovered importer suites | 2245 | 2378 |
| of which the **new** acceptance suite | — | **114** |
| existing suites only | 2245 | 2264 (+19) |

**Direct comparison of runtime assertion totals is unavailable** — no suite ran, so
there is no runtime baseline and none is claimed. Every intentional change is
listed in §9.

## 9. Intentional assertion changes

| File | Change | Intent preserved |
|---|---|---|
| `test-import-log.php` | write counter retargeted to `option_key()` + a separate live-write counter (must be 0); live raw byte-identity + live entry count | yes — "one write per flush" is still measured exactly, now where the write lands |
| `test-import-log.php` | the old "restore the snapshot" section replaced by "the live row never moved, the test row holds the 200 measured entries" | **strengthened** |
| `test-import-log-run-correlation.php` | G3A rewritten: the 25 seeded entries go to the **test** row; two destructive passes; live byte-identity for log **and** history | **strengthened** (the live row is no longer the row under test) |
| `test-import-history-logs.php` | render-must-not-write: the live compare kept, the test-row compare added | strengthened |
| `test-import-history-dashboard.php` | same, plus "did not clear" now reads the row the renderer reads | strengthened |
| `test-event-cleanup-purge.php` | global `run_cleanup()` assertions replaced by per-fixture assertions + a past-event sentinel + reset-helper assertions + live byte-identity | **reduced in scope, intentionally** (§9.1) |

### 9.1 Deliberate coverage deferral (named, not hidden)

**Deferred:** the end-to-end `Conexao_Event_Cleanup::run_cleanup()` proof over the
real event population. **Why:** it deletes every past event in the shared
database (audit A3) and cannot be made safe without a dedicated test database.
**Replacement now:** per-fixture deletion proofs plus the sentinel-not-deleted
proof. **Owner of the deferred proof:** the future isolated-database stage.

### 9.2 The controlled class-level smoke test (limitation)

Section J loads the real `Conexao_Import_Log` class with **no WordPress and no
database**, stubs `current_time()`, `sanitize_key()`, `sanitize_text_field()`,
`get_option()` and `update_option()`, and asserts that a non-test process selects
`conexao_event_import_log` (not a `__test__` key), issues exactly one write with
`autoload = false`, and stores the entry. Its negative controls prove the same
code selects a test key with the markers, and throws without a suffix.

**This is a class-level smoke test.** It does not exercise the live WordPress
persistence path, and this report does not claim that it does. It was **not
executed** in this run (it lives inside the acceptance suite, which could not be
run — §10).

## 10. The incident — event content changed during verification

**What happened.** The prescribed step 2 (`./scripts/run-tests.sh --scripts`)
includes `tests/scripts/verify-stage14-secret-scan.py`. That gate writes a PHP
driver whose first line is `require_once __DIR__ . '/bootstrap.php'` and runs it
inside the WordPress container. Booting WordPress loads the importer, which
registers a **`shutdown` (priority 20) → `Conexao_Import_Continuation::run_due()`**
hook (`conexao-event-importer.php:336-342`). The local database held 25
continuation records, several due. At shutdown the due `eventbrite_cork`
continuation ran: **47 events created, 13 updated, 0 deleted** (21:28:03–21:29:28).

**Why this is not a Stage 0.5 defect.** The hook is pre-existing. What Stage 0.5
changed is *where the diagnostics go*: the entire run's ~80 KB of log entries went
to `conexao_event_import_log__test__p17934_…`, and the live
`conexao_event_import_log` was never written — its hash is identical to the
baseline. Before this stage the same boot would have appended those entries to
the **live** log. Content writes are explicitly out of scope until the
isolated-database stage.

**The process gap.** Plan precondition P6 ("no import running — no continuation
lock") was not checked before step 2. The database in fact had 25 continuation
records and two live lock rows, so the first WordPress-booting gate could import.

**What was done.** The sequence was halted at that step. The content was **not**
restored, overwritten or reverted; no further suite was run; the evidence is
preserved in `docs/evidence/2026-11-14-stage-0-5/09-*`.

**Also noted.** The same hook means *any* WordPress-booting test process can
perform a due import. That is precisely why the isolated test database (not this
stage) is the long-term answer for content writes. Two test-prefixed orphan rows
from the killed gate processes remain; they are the documented orphan class and
are listed in `09-orphan-test-rows.txt` for a manual, one-row-at-a-time sweep.

**What a future run needs.** A quiet database (no due continuations, no lock
rows), or — the correct long-term answer — the isolated test database that also
removes the remaining content-write hazard.

## 11. Gate negative controls (summary)

| # | Injected violation | Required | Result |
|---|---|---|---|
| N0 | none (unmodified scratch copy) | exit 0 | **exit 0** |
| N1 | `delete_option( 'conexao_event_cleanup_history' )` in a suite | check 9 | **FAIL 9**, exit 1 |
| N2 | guard removed from a suite | check 6 | **FAIL 6**, exit 1 |
| N3 | child code without the bootstrap require | check 7 | **FAIL 7**, exit 1 |
| N4 | production-key rejection branch deleted | check 8 | **FAIL 8** (2 findings), exit 1 |
| N5 | production file defines the suffix | check 11 | **FAIL 11**, exit 1 |
| N6 | `define` moved below the `wp-load.php` require | check 4 | **FAIL 4**, exit 1 |
| N7 | store-touching suite re-added to `PARALLEL` | check 14 | **FAIL 14**, exit 1 |
| N8 | referenced suite deleted | missing prerequisite | **FAIL 6 ×2**, exit 1 |

Full output: `docs/evidence/2026-11-14-stage-0-5/08-gate-negative-controls.txt`.

## 12. Deviations from the plan (each with its reason)

| Deviation | Reason |
|---|---|
| §12's compare-and-swap live write/restore smoke test was **not** implemented; the task's §10 class-level stub smoke test was implemented instead | the task text explicitly forbids touching the real local operator log in this stage |
| A `production` environment type is refused **unless the home host is a documented local host** | the existing local Compose volume has no `WP_ENVIRONMENT_TYPE` define, so WordPress reports `production`; refusing unconditionally would make the shared harness unusable on its own stack while adding nothing — `conexaobr.ie` / `www.conexaobr.ie` are refused unconditionally |
| §6.4's `unknown_store` label is folded into the single rule-7 reason `arbitrary_option` | §6.2 defines a closed vocabulary with one rule for "anything else"; a second reason for the same rule would be a distinction without a difference. Both `blogname` and `import_logs` are rejected, and the acceptance suite asserts `arbitrary_option` for both |
| The gate ignores PHP comments when it looks for code | the repository documents the very names the gate guards; a comment is evidence of intent, not behaviour |
| `scripts/README.md` was added to the documentation set | the plan named it in §7.2 / §16.6 |

## 13. Conclusion

| Question | Answer |
|---|---|
| Are the four stores isolated and fail-closed? | **yes** — statically proven (checks 1–5, 13), proven against the real classes by the accessor probe (§8 rows 16–17), and the live hashes are identical across a real import |
| Are the suites guarded and migrated? | **yes** — 19 files, guard before the first store reference (check 6) |
| Are the destructive cleanup operations removed? | **yes** — check 10; the live cleanup options are byte-identical |
| Does the static gate pass, and do its negative controls fail as intended? | **yes** — 227 assertions, N1–N8 all exit 1 |
| Does the acceptance suite pass? | **NOT RUN** — blocked by the stop rule |
| Do serial and parallel modes complete? | **NOT RUN** |
| Do the four operator options stay byte-identical? | **yes**, in every capture taken |
| Is the Stage 0 source registry unchanged? | **no** — an import updated it; recorded, not restored |
| Does existing event content stay unchanged? | **no** — 47 created / 13 updated by the incident; recorded, not restored |
| Does lint pass? | **yes** |

**Stage 0.5: `BLOCKED`.** The implementation, the migration, the gate and its
negative controls are complete and verified. The runtime verification sequence
(and therefore the acceptance-suite pass and the serial/parallel runs) could not
be completed because the shared local database performed a real import during
step 2 — a pre-existing content-write hazard outside this stage's scope. The next
attempt needs a quiet database and should restart at step 3 with precondition P6
checked first.




