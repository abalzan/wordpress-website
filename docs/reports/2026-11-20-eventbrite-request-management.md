# Report — Eventbrite request management (rate-limit scheduling, budgets, breaker recovery, checkpoint reuse)

| | |
|---|---|
| **Stage / task name** | Eventbrite request management investigation and improvement |
| **Date** | 2026-11-20 |
| **Author / agent** | Automated investigation (read-only) + approved Candidate E implementation |
| **Branch** | `event-import-improvements` |
| **Start SHA** | `9d97ba9d571cad95415d3de7fa5aba8d5c95f654` |
| **Final SHA** | `9d97ba9d571cad95415d3de7fa5aba8d5c95f654` (no commit; working tree carries the change) |
| **Working tree at finish** | dirty — 2 modified files + 1 new test + 5 evidence files (listed in §3); no user changes were present at start and none were touched |
| **Skills followed** | `wp-content-change` (read-only investigation), `wp-write-in-process-test` (new suite), `wp-testing` (runner + identity) |
| **Authoritative docs consulted** | `AGENTS.md`, `docs/engineering-standard.md`, `docs/reports/2026-11-20-event-importer-log-review.md`, `docs/evidence/2026-11-20-event-importer-log-review/` |

## 1. Scope completed

Investigated how the Conexao Event Importer schedules and controls Eventbrite
requests, to determine whether request scheduling, request budgets, recovery
coordination or checkpoint reuse can reduce avoidable Eventbrite traffic while
preserving completeness and data safety.

Delivered:

- A complete, evidence-backed map of the current request-control design
  (Phase 1; `01-current-request-flow.md`, `02-rate-limit-state-machine.md`).
- A diagnosis of six candidate improvement areas (Phase 2; `03-design-decision.md`).
- A design note classifying each candidate as implement / defer / do-not-implement
  (Phase 3; `03-design-decision.md`), approved by the operator.
- Implementation of the single approved change (Candidate E: bounded 405/WAF
  diagnostics) (Phase 4).
- A focused test suite proving the change (Phase 5;
  `test-eventbrite-waf-diagnostics.php`, 32 assertions).
- This report and its evidence (Phase 6).

**Key finding:** the existing request-control design already implements every
structural protection the brief asks about. The dominant 2026-11-20 failure
(463 × HTTP 429) is provider-side rate limiting, not an importer defect. One
small, evidence-backed change (405/WAF diagnostics) was justified and approved;
the other five candidates were correctly identified as already handled.

## 2. Scope NOT completed

- **Candidates A, B, C, D and F were not implemented** — by design. The Phase 3
  design note classifies each as "Do not implement" with a stated rationale
  (the existing control already handles it, or the change would add runtime /
  risk without addressing the demonstrated cause). The operator approved this.
- **No live provider requests, imports, retries, Run All, source activation or
  operational database mutations** were performed (prohibited by the brief).
- **No production action, commit, push or deploy** (prohibited by the brief).
- **The 3 stalled sources** (`eventbrite_sligo`, `eventbrite_kildare`,
  `eventbrite_waterford`) were **not** retried (prohibited by the brief).

## 3. Files added / modified / deleted

| Path | Change | Note |
|---|---|---|
| `wp-content/plugins/conexao-event-importer/includes/class-eventbrite-client.php` | modified | Candidate E: WAF allowlist constant, `get_waf_action()`, 405 log context |
| `wp-content/plugins/conexao-event-importer/includes/class-import-log.php` | modified | Candidate E: `waf_action` added to context allowlist |
| `wp-content/plugins/conexao-event-importer/tests/test-eventbrite-waf-diagnostics.php` | added | 7 sections, 32 assertions |
| `docs/evidence/2026-11-20-eventbrite-request-management/01-current-request-flow.md` | added | Phase 1 evidence |
| `docs/evidence/2026-11-20-eventbrite-request-management/02-rate-limit-state-machine.md` | added | Phase 1/2 evidence |
| `docs/evidence/2026-11-20-eventbrite-request-management/03-design-decision.md` | added | Phase 3 sign-off artifact |
| `docs/evidence/2026-11-20-eventbrite-request-management/04-test-results.md` | added | Phase 5 evidence |
| `docs/evidence/2026-11-20-eventbrite-request-management/05-change-manifest.md` | added | Phase 6 evidence |
| `docs/reports/2026-11-20-eventbrite-request-management.md` | added | this report |

**2 modified, 1 code file added, 6 docs/evidence files added, 0 deleted.**

All `wp-content/` changes are under `wp-content/plugins/conexao-event-importer/`.
No theme, no other plugin, no `scripts/`, no canonical `docs/` file, no
`plugins.json`, no `compose.yaml` was touched.

## 4. Runtime impact

WordPress behaves **identically** after this change **except** that an HTTP 405
response from Eventbrite now records a bounded, allowlisted `waf_action` value
(`captcha` / `challenge` / `block` / `allow` / `unknown`) in its log entry's
context. This is a logging-only change:

- 405 remains **non-retryable** (`RETRYABLE_STATUSES` unchanged).
- No request is added, removed, delayed or reordered.
- The 429 cooldown ladder, shared breaker, per-source cooldown, checkpoint,
  continuation scheduler and request budget are untouched.
- No provider request header is changed.

Verified by: the full importer PHP suite passing identically before and after
(44/46 suites, same 2 pre-existing failures), plus the regression-sensitive
suites (rate-limit, retry-policy, budget, checkpoint, import-log,
run-coordination, source-fetch-failure) all green.

## 4a. Scope integrity

This is **not** a documentation-, skills- or governance-only change: it touches
two `wp-content/` files and adds a test. The runtime-impact statement above is
the honest description. The change is confined to the Eventbrite importer
plugin; no other subsystem is affected.

## 5. Content / data impact

**None.** No event records, source registry data, options, scheduled jobs or
logs were created, updated or deleted by this task. The only database writes
performed were by the approved tests, confined to the restricted disposable test
database `conexao_stage05_test_5bd9ece5c032` (proven by
`WPDB_IDENTITY=PASS`). The shared `wordpress` database was never touched.

## 6. Polylang impact

**None.** No PT content, no EN translation, no taxonomy, no language-scoped
cache. The change is in the Eventbrite HTTP client and the import log, neither
of which touches Polylang. PT immutability is preserved trivially (no PT record
was read or written).

## 7. Route / HTTP impact

**None.** No route, redirect, canonical, hreflang or sitemap change. The change
affects only the *outbound* Eventbrite HTTP client's logging of a 405 response;
it does not change any inbound request handling, routing or the `/en/` layer.

## 8. Production actions

| Action | Performed? | Detail |
|---|---|---|
| Production write | **no** | — |
| Deploy / upload | **no** | — |
| Plugin activation | **no** | — |
| Content or DB mutation | **no** | — |

Production is WordPress.com (no SSH, no WP-CLI, no filesystem, no database).
Nothing in this task required production access.

## 9. Verification commands and results

| Command | Exit | Result |
|---|---|---|
| `ISOLATED_DB_PREFLIGHT` (via `conexao-test-exec.sh` + `isolated-db.php`) | 0 | `PASS database=conexao_stage05_test_5bd9ece5c032 host=db` |
| `test-eventbrite-waf-diagnostics.php` | 0 | 32 passed, 0 failed |
| `test-eventbrite-rate-limit.php` | 0 | 87 passed, 0 failed |
| `test-request-retry-policy.php` | 0 | 59 passed, 0 failed |
| `test-request-budget.php` | 0 | 33 passed, 0 failed |
| `test-eventbrite-checkpoint.php` | 0 | 91 passed, 0 failed |
| `test-import-log.php` | 0 | 65 passed, 0 failed |
| `test-stage1-run-coordination.php` | 0 | 47 passed, 0 failed |
| `test-source-fetch-failure.php` | 0 | 136 passed, 0 failed |
| `./scripts/run-tests.sh --php --only conexao-event-importer` | 1 | 46 suites, 44 passed, 2 pre-existing failures |
| `./scripts/lint.sh` | 1 | PHP syntax OK; PHPStan OK; PHPCS 1 pre-existing violation |

### Numeric test results

In-process PHP (importer component): **46 suites total, 44 passed, 2 failed**;
**4851 assertions passed, 23 failed**. The 2 failing suites and 23 failing
assertions are the **pre-existing baseline** (see §12); the Candidate E change
added 1 suite and 42 passing assertions with zero new failures.

### HTTP acceptance

**Not in scope.** No acceptance suite was added or run; the change is a logging
change with no HTTP surface. The acceptance layer was not invoked.

### Static analysis

- **PHP syntax** (`php -l`, 614 files): OK, no parse errors.
- **PHPStan level 5**: OK, no errors.
- **PHPCS**: one NEW violation reported by the baseline gate —
  `PSR2.Methods.FunctionClosingBrace.SpacingBeforeClose` at
  `class-event-sources.php:472`. This is **pre-existing on clean HEAD** (verified
  by stashing the Candidate E changes and re-running lint: the identical
  violation is reported). It is in a file this task did not touch. My two edited
  files have **identical** PHPCS counts on HEAD vs with Candidate E (21 errors /
  27 warnings, all pre-existing); the new test file is PHPCS-clean.

### Script-contract results

**Not in scope.** No script under `scripts/` was added or changed.

### Release / build results

**Not in scope.** No release, build, manifest or deploy was performed.

## 10. Failure proofs / negative tests

The Candidate E test suite proves the gate can fail by asserting the negative
cases directly:

| Proof | Result |
|---|---|
| A 405 with no WAF header stores no `waf_action` (WAF2) | PASS — proves the key is absent when the header is absent |
| An unrecognised value is stored as `unknown`, not verbatim (WAF3) | PASS — proves the allowlist rejects unknown values |
| A sensitive-looking value is not stored verbatim (WAF4) | PASS — proves sanitisation + length limit |
| A non-405 (403) stores no `waf_action` (WAF6) | PASS — proves the header is read only on 405 |
| A 429 carries no `waf_action` and still opens the breaker (WAF7) | PASS — proves 429 handling is unchanged |

These are genuine negative assertions: if the implementation stored the header
unconditionally, stored it verbatim, or changed 405 to retryable, the
corresponding assertion would fail.

## 11. Regression comparison

| | Before | After |
|---|---|---|
| Failing in-process suites (importer) | 2 (`test-import-continuation`, `test-town-sanitization`) | 2 (same) |
| Failing assertion count | 23 | 23 (same) |
| Failing script-contract | 0 | 0 |
| Failing acceptance assertions | not run | not run |

The diff of the failing lists is **empty**. Both failures pre-existed this
change and are unrelated to it (see §12).

## 12. Known pre-existing failures

1. **`test-import-continuation.php` — 22 assertions.** A G18 time-budget
   fixture expects the walk to stop at page 3, but it stops at page 2. This is a
   pre-existing code/test mismatch in the time-budget branch, reproduced in
   isolation on clean HEAD before any change. It is unrelated to the Eventbrite
   request-control code touched here.
2. **`test-town-sanitization.php` — 1 assertion.** A data-quality assertion that
   no Eircode-contaminated or non-town terms exist in `conexao_town`. This is a
   local content-data condition, not an importer defect.

Both were confirmed pre-existing by running them in isolation with the Candidate
E changes stashed (clean HEAD): they fail identically. Neither is in scope for
this task.

## 13. Limitations

- **No live Eventbrite request was made** (prohibited by the brief). The 405/WAF
  behaviour is therefore verified against mocked responses, not a live WAF
  challenge. The allowlist values (`captcha`, `challenge`, `block`, `allow`) are
  drawn from the 2026-10-04 G5 report's captured `x-amzn-waf-action: captcha`;
  other real-world values would be stored as `unknown` (safe, but less
  informative).
- **No production or staging site was exercised.** The change is verified only
  against the local Docker WordPress and the restricted test database.
- **The 2 pre-existing test failures** (`test-import-continuation`,
  `test-town-sanitization`) could not be fixed within this task's scope; they
  are documented in §12 and left untouched.
- **The 1 pre-existing PHPCS violation** (`class-event-sources.php:472`) is not
  in scope and was not fixed.

## 14. Evidence paths

| File | Proves |
|---|---|
| `docs/evidence/2026-11-20-eventbrite-request-management/01-current-request-flow.md` | The current request lifecycle, breaker scope, cooldown, Retry-After, checkpoint and continuation behaviour, with file/method citations. |
| `docs/evidence/2026-11-20-eventbrite-request-management/02-rate-limit-state-machine.md` | The three state layers, state transitions, Retry-After decision table, and what each mechanism does/does not do. |
| `docs/evidence/2026-11-20-eventbrite-request-management/03-design-decision.md` | The Phase 3 implement/defer/do-not-implement classification and the operator sign-off. |
| `docs/evidence/2026-11-20-eventbrite-request-management/04-test-results.md` | Test identity, exact commands, results and baseline distinctions. |
| `docs/evidence/2026-11-20-eventbrite-request-management/05-change-manifest.md` | Exact files changed and why; superseded-test count (0). |

## 15. Documentation updated

No canonical `docs/` file was changed. The plugin's behaviour is documented by
the new evidence files and this report. The plugin registry
(`plugins.json`) is unchanged — the plugin's version, class, status, build and
mount facts are all unchanged by a logging-only edit.

## 16. Rollback / recovery

Revert the two-file change:

```
git checkout -- wp-content/plugins/conexao-event-importer/includes/class-eventbrite-client.php \
                wp-content/plugins/conexao-event-importer/includes/class-import-log.php
rm wp-content/plugins/conexao-event-importer/tests/test-eventbrite-waf-diagnostics.php
```

Rollback removes the `waf_action` context key; no data migration, no option
change, no behaviour change. The importer returns to its prior 405 logging
(status + generic message only). The evidence and report files are documentation
and can remain or be removed independently.

## 17. Final status

**Final status: `PASS WITH LIMITATION`**

The approved Candidate E change is implemented, tested (32 focused assertions
plus 7 regression suites green) and documented; the limitation is that no live
Eventbrite 405 was exercised (mocked only) and two pre-existing, unrelated test
failures remain in the component suite.

---

## Appendix — operator summary

1. **Final disposition:** `PASS WITH LIMITATION` (complete; no live WAF
   challenge exercised; 2 pre-existing unrelated failures remain).
2. **Code changed:** yes — one approved change (Candidate E: bounded 405/WAF
   diagnostics). Candidates A–D and F were investigated and correctly classified
   as already handled.
3. **Key findings:**
   - **Scheduling:** Eventbrite sources run sequentially; the shared breaker
     already prevents the request storm (100 sources skipped after the first
     429 on 2026-11-20). Staggering would add runtime without addressing the
     cumulative-rate cause.
   - **Budgets:** all five bounds (requests/run, requests/source, elapsed time,
     pages, continuation attempts) already exist; exhaustion is already
     incomplete/deferred.
   - **Breaker recovery:** correct — process-local, cleared per logical run,
     composed with the per-source cooldown.
   - **Checkpoint reuse:** correct — advances only after fetch→parse→merge,
     1-page overlap, resumed walks are reconciliation-safe.
4. **Report and evidence paths:** this report plus the five evidence files in
   `docs/evidence/2026-11-20-eventbrite-request-management/`.
5. **Tests run:** new WAF suite (32/32), 7 regression suites (all green), full
   importer suite (44/46, 2 pre-existing failures), lint (PHP syntax + PHPStan
   clean; 1 pre-existing PHPCS violation).
6. **Safety confirmation:** no live imports, no provider requests, no database
   mutations outside the restricted test DB, no commits, no pushes, no
   deployments. The shared `wordpress` database was never touched.

_No claim is made that Eventbrite rate limiting has been eliminated. The
objective — reduce avoidable requests, coordinate recovery safely, and make
provider restrictions easier to diagnose — is met: the importer already minimises
avoidable requests and coordinates recovery safely, and the one change makes the
405/WAF classification provable from the log._

_Last verified: 2026-11-20 by the Eventbrite request-management task_