# Report — Stage 4 closeout finalisation

| | |
|---|---|
| **Stage / task name** | Stage 4 evidence reconciliation, Stage 1 fixture verification and final suite rerun |
| **Date** | 2026-11-16 |
| **Author / agent** | Codex |
| **Branch** | `event-import-improvements` |
| **Start SHA** | `7f6850fd90aabf232a0940140c461d47e2a414d8` |
| **Final SHA** | `7f6850fd90aabf232a0940140c461d47e2a414d8` |
| **Working tree at finish** | dirty; pre-existing Stage 4/application work plus this task's evidence/plan/report additions |
| **Skills followed** | `wp-repository`, `wp-testing` |
| **Authoritative docs consulted** | `AGENTS.md`, `docs/engineering-standard.md`, `docs/testing.md`, `scripts/run-tests.sh`, `tests/bootstrap.php`, `tests/lib/assertions.php`, `tests/baseline/permanent-gates.json` |

## 1. Scope completed

The branch, HEAD, worktree and Stage 3.6 evidence were reconciled. Existing
isolation infrastructure was freshly verified without rebuilding it. The
review-queue implementation was inspected and required residue cleanup was not
needed. D2 history and the menu tree were reconciled, the existing Stage 1
relative-date helper was verified, the focused Stage 1 suite passed, and all
twelve required suites were run sequentially through the restricted wrapper.
Evidence and the Stage 3 report were appended without deleting history.

## 2. Scope NOT completed

D2 was not repaired or declared resolved. The menu was not changed. No
production or shared-schema application write, CI change, deploy, activation,
commit, push, merge, reset, stash or release occurred. The Stage 4 programme
therefore remains blocked by the required unresolved D2 and menu decisions.

## 3. Files added / modified / deleted

| Path | Change | Note |
|---|---|---|
| `docs/plans/2026-11-16-stage-4-closeout-finalisation.md` | added | Task plan |
| `docs/reports/2026-11-16-stage-4-closeout-finalisation.md` | added | This report |
| `docs/evidence/2026-11-16-stage-4/04-review-queue.md` | modified | Appended menu/D2 final disposition |
| `docs/evidence/2026-11-16-stage-4/06-tests-and-gates.md` | modified | Appended fresh gates, suites and schema drift |
| `docs/evidence/2026-11-16-stage-4/08-known-limitations.md` | modified | Appended final sub-status/acceptance table |
| `docs/evidence/2026-11-16-stage-4/09-test-isolation-infrastructure.md` | modified | Appended fresh isolation and exposure assessment |
| `docs/evidence/2026-11-16-stage-4/10-d2-diagnosis.md` | modified | Appended chronological D2 reconciliation |
| `docs/reports/2026-11-15-stage-3-event-importer-diagnostics-retention.md` | modified | Appended superseding Stage 4 section |

Task delta: **2 added, 6 modified, 0 deleted**. The review-queue class/test,
Stage 1 test and all other application files were pre-existing worktree paths;
they were inspected but not changed by this task.

## 4. Runtime impact

No runtime or application-code change was made by this task. The fresh suites
ran against the existing local stack and disposable schema.

## 4a. Scope integrity

| Check | Result |
|---|---|
| No production write, deploy, upload or activation | confirmed |
| No production content, Polylang or database change | confirmed |
| No application behaviour change by this task | confirmed; no `wp-content/` file changed |
| No Flutter/mobile repository accessed | confirmed |
| No `.env` or secret change | confirmed; secret contents not displayed |

## 5. Content / data impact

No manual content or shared-site data mutation. The existing proof created and
removed one temporary table in the disposable schema; the twelve suites used
their existing disposable fixtures. Pre/post read-only counts were unchanged,
so no test-created count residue was observed. No manual cleanup was performed.

## 6. Polylang impact

None. PT content and language configuration were not changed.

## 7. Route / HTTP impact

None. No route, redirect, canonical, hreflang, sitemap or HTTP acceptance row
was changed.

## 8. Production actions

| Action | Performed? | Detail |
|---|---|---|
| Production write | **no** | local verification only |
| Deploy / upload | **no** | none |
| Plugin activation | **no** | none |
| Content or DB mutation | **no** | no manual shared-schema mutation |

## 9. Verification commands and results

| Command/check | Exit | Result |
|---|---:|---|
| `git branch --show-current` / `git log -1` / `git status --short` | 0 | branch and dirty baseline recorded |
| `docker compose ps` | 0 | local DB and WordPress healthy/running |
| `SHOW GRANTS FOR 'conexao_test'@'172.18.%'` | 0 | exact authorised grant set |
| existing `tests/lib/isolation-proof.php` through wrapper | 0 | 9/9 probes passed; identity passed |
| absent-secret wrapper probe | 1 expected | failed closed before PHP |
| focused `test-stage1-run-coordination.php` | 0 | 47 passed, 0 failed |
| 3 PHP syntax checks | 0 | no syntax errors |
| `bash -n scripts/conexao-test-exec.sh` | 0 | syntax valid |
| twelve direct suites through wrapper | aggregate non-zero | 12/12 terminal; 2 documented baseline failures |
| pre/post schema count queries | 0 | 3510/457 before and after |

### Numeric test results

The controlled run covered 12 in-process PHP suites: **2,096 assertions passed,
23 failed**. Ten suites passed; `test-import-continuation.php` was 262/22 and
`test-town-sanitization.php` was 82/1, matching the documented baseline
signatures.

### HTTP acceptance

Not run; this task required the twelve specified importer suites only.

### Static analysis

The targeted PHP syntax and wrapper shell checks passed. Full PHPCS/PHPStan was
not in the bounded closeout scope.

### Script-contract results

`./scripts/run-tests.sh --scripts` was not run; no script contract change was
authorised. The wrapper was checked with `bash -n` and its fail-closed path.

### Release / build results

Not in scope.

## 10. Failure proofs / negative tests

The existing isolation proof supplied the permitted negative shared-schema read
and zero-row write denials. The absent-secret wrapper proof exited `1` with
`secret file not found` and launched no PHP process.

## 11. Regression comparison

| | Before / documented baseline | Fresh closeout |
|---|---|---|
| Review queue | contradictory 34/0, 36/2, 37/1, timeout and 38/0 claims | 38/0, 522s |
| Stage 1 coordination | 47/0 historical | 47/0 |
| Import continuation | 262/22 | 262/22, same G18.1/G18.3/G18.10/G18.14 signatures |
| Town sanitization | 82/1 | 82/1, same Eircode/non-town assertion |
| Other 8 required suites | documented passing counts | matching passing counts |

The failing-list difference for the two baseline suites is empty. The review
queue result is a current run result only and does not resolve D2.

## 12. Known pre-existing failures

The continuation and town-sanitization failures predate this closeout and match
the retained baseline evidence. They were not modified or weakened. The current
task changed no application file on either failure path.

## 13. Limitations

- D2 remains an unresolved design/contract decision despite the fresh 38/0 run.
- Menu placement remains ambiguous and requires an owner decision.
- The venue-missing direct-SQL count is materially different from the prior
  same-definition 511 observation; no cause is inferred.
- Operator-log isolation verifies the disposable test schema, not a byte-for-
  byte read of live shared `wordpress.wp_options`.
- CI provisioning, P8 vocabulary, MI six-field assessment, per-source datetime
  conventions, retained IVVCC/Mondello detail HTML, Stage 3.6 WAF and stranded
  continuations remain outside this bounded task.

## 14. Evidence paths

- `docs/evidence/2026-11-16-stage-4/04-review-queue.md`: menu and D2 disposition.
- `06-tests-and-gates.md`: fresh suite table, gate states and schema drift.
- `08-known-limitations.md`: sub-status and owner acceptance table.
- `09-test-isolation-infrastructure.md`: fresh isolation proof and credential assessment.
- `10-d2-diagnosis.md`: chronological D2 reconciliation.
- `docs/reports/2026-11-15-stage-3-event-importer-diagnostics-retention.md`: superseding Stage 4 report section.

## 15. Documentation updated

The plan, five Stage 4 evidence files and Stage 3 report were updated in the
same task. This report records the real verification numbers. No documentation
index, plugin documentation, CI or Stage 3.5/3.6 evidence was changed.

## 16. Rollback / recovery

The evidence additions and this plan/report can be reviewed or removed using
the pre-edit SHA-256 snapshots kept outside the repository. No snapshot should
be restored over a file automatically; pre-existing worktree changes must be
preserved. No content rollback is applicable.

## 17. Final status

**Task execution status: COMPLETE WITH LIMITATIONS.** The authorised closeout,
fresh isolation verification, Stage 1 verification, twelve-suite terminal run
and evidence reconciliation are complete; the Stage 4 programme verdict remains
**BLOCKED — D2 UNRESOLVED AND MENU PLACEMENT AMBIGUOUS**.

_Last verified: 2026-11-16 by Codex — Stage 4 closeout finalisation_
