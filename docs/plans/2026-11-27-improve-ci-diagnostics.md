# Plan — CI reporting and failure diagnostics

| | |
|---|---|
| **Task title** | Improve CI test reporting and failure diagnostics |
| **Date** | 2026-11-27 |
| **Author / agent** | Cline (CI/CD + test architect) |
| **Branch** | `improve-ci-diagnostics` |
| **Repository baseline (starting SHA)** | `ceaa1c0` |
| **Applicable skill(s)** | `wp-testing`, `wp-repository` |
| **Plan status** | approved |

## 1. Scope

Make a red CI run diagnosable **from the run page**: one Markdown job summary per
blocking job, and an evidence artifact that contains the analyser, harness and
gate output the job actually produced. Add no job, no dependency and no second
execution of any analyser or test layer.

## 2. Explicit non-goals

- **No new CI job.** Four blocking jobs stay four.
- **No dependency-manager change**, no test framework, no reporting service.
- **No `continue-on-error` anywhere new.** A failure stays a failure.
- **Not** touching what the gates *check* — only what they *report*.
- **Not** changing the four existing jobs' names (branch protection may key on
  them).

## 3. Relevant authoritative documents

`AGENTS.md`; `docs/engineering-standard.md` §1.7 (actions, Node 24, blocking
semantics) and §8 (testing); `docs/development.md` §"Continuous integration";
`docs/testing.md` (exit-code vocabulary); `scripts/README.md` (script catalogue).

## 3a. Skill workflow

| | |
|---|---|
| **Skill** | `wp-testing` (run/diagnose/compare the test contract), `wp-repository` |
| **Its preconditions** | A real failing output sample to parse, not an invented schema |
| **Its guardrails that bind this task** | Never weaken a check; a check that did not run is never a pass; preserve blocking semantics |
| **Its verification commands** | `python3 tests/scripts/verify-ci-summary-output.py`, `./scripts/run-tests.sh --scripts-static` |
| **Its evidence location** | `docs/evidence/2026-11-27-ci-reporting/` |

## 4. Current-state findings

Measured by reading `.github/workflows/ci.yml` and the harness source:

| Finding | Detail |
|---|---|
| No job summary anywhere | `$GITHUB_STEP_SUMMARY` was never written, so a reviewer had to open raw logs to learn suite/assertion counts or which suite failed |
| Static-quality job had **no artifact** | PHPStan/PHPCS detail lived only in the step log and disappeared with log retention |
| `gate.json` was **never uploaded in practice** | `verify-permanent-gates.py` writes `docs/evidence/<today>-stage-l/gate.json`; the upload hardcoded `docs/evidence/2026-09-26-stage-l/gate.json`. On any other date the path does not exist and `if-no-files-found: warn` turned the miss into a warning — the single most valuable diagnostic artifact was silently absent |
| Integration artifacts carried no summary | the harness log was uploaded but never summarised |
| Release-integrity artifacts carried only `release.json` | the `FAIL:` lines that made the gate red were log-only |

## 5. Proposed approach

1. **One renderer, one place to fix it.** `scripts/ci-summary.php` (PHP CLI,
   read-only, always exit 0) turns each existing artifact into Markdown:
   `--kind=harness` (run-tests.sh log), `--kind=lint` (lint.sh log),
   `--kind=phpstan` (PHPStan JSON), `--kind=gates` (`gate.json`),
   `--kind=release` (verify-release-integrity log).
2. **Parse, do not re-run.** Every input is a file the job already produced.
   The only extra execution is a **failure-only** structured re-run of
   PHPStan/PHPCS on a red static-quality job, because `lint.sh` prints the human
   form and a machine-readable report is what makes the artifact useful.
3. **A gate over the renderer.** `tests/scripts/verify-ci-summary-output.py`
   (discovered by the existing `tests/scripts/verify-*.py` convention, therefore
   blocking with no workflow change) asserts every output shape against
   synthetic fixtures, asserts a credential-shaped log value never reaches the
   summary, and carries **mutation-based negative controls**.
4. **Fix the `gate.json` path** by passing `--out "$RUNNER_TEMP/ci-evidence/gate.json"`.
   Local runs keep the documented date-stamped directory.

**Rejected:** a second full analysis run in the static job (wasted CPU on green
runs), and inline `jq`/`grep` summary building in YAML (untestable, so it would
rot silently — which is exactly how the `gate.json` path rotted).

## 6. Files expected to change

| Path | Change | Why |
|---|---|---|
| `scripts/ci-summary.php` | **new** renderer | one testable place for all five formats |
| `tests/scripts/verify-ci-summary-output.py` | **new** blocking gate | proves the renderer and its safe-output contract |
| `.github/workflows/ci.yml` | capture + summary + artifact in all four jobs; `--out` for `gate.json` | the point of the task |
| `scripts/README.md` | catalogue the new script | §10.2 MUST |
| `docs/development.md` | document the reporting contract and its three safety rules | §9.2 |

## 7. Files explicitly expected NOT to change

`composer.json`/`composer.lock`, `plugins.json`, `phpstan.neon.dist`,
`phpcs.xml.dist`, any `wp-content/` file, and the four job **names**.

## 8. Content / data impact

None. No record, option, transient or file outside `$RUNNER_TEMP` is written.

## 9. Route / HTTP impact

None.

## 10. Polylang / English impact

None.

## 11. Security impact

- The evidence set is analyser/harness output only. `docker compose config`, the
  container environment, `.env`, `.conexao-test.env`, WP-CLI credentials and
  session cookies remain excluded.
- The renderer reads exactly one named file, so it cannot sweep a directory.
- The gate asserts a credential-shaped value in a log does not reach the output.
- **Verdict independence:** summary steps are `if: always()` with `|| true`; the
  renderer exits 0 by design; the blocking steps keep `${PIPESTATUS[0]}`.

## 12. Performance impact

Zero added analysis on a green run: all inputs already exist. A red
static-quality run pays one extra PHPStan + one extra PHPCS pass, which is
accepted because it only happens when the job is already failing.

## 13. Production impact

| Question | Answer |
|---|---|
| Will anything be written to production? | **no** |
| Will a deploy, upload or activation happen? | **no** (artifacts go to GitHub, not production) |
| What is the verification of the production result? | n/a |

## 14. Test plan

- New suite: `tests/scripts/verify-ci-summary-output.py` — pass/fail/ANSI/
  missing-input/secret-safety shapes plus two mutation negative controls.
- Existing suites: `./scripts/run-tests.sh --scripts-static` must stay green.
- Numeric pass: the new suite reports 0 failed, and the YAML still parses with
  four jobs.

## 15. Acceptance matrix plan

None.

## 16. Rollback plan

`git revert` — the workflow returns to log-only reporting and nothing else
changes. No data or artifact is permanent.

## 17. Documentation plan

| Document | Change |
|---|---|
| `docs/development.md` | new §"Reporting and failure diagnostics" (the per-job table and the three safety rules) |
| `scripts/README.md` | catalogue `scripts/ci-summary.php` |

## 18. Release implications

None. No component version bump: no shipped source changes.

## 19. Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| A summary step accidentally changes a verdict | Low | `if: always()`, `|| true`, renderer exits 0, and the blocking steps keep `PIPESTATUS` |
| The parser drifts from the harness output | Medium | the gate pins the exact shapes, including ANSI-coloured logs |
| A secret leaks into an artifact | Low | the evidence set is enumerated explicitly; the gate asserts the renderer cannot echo an unmatched secret |
| Scope creep into "improve the gates themselves" | Medium | listed as a non-goal |

## 20. Verification gates

1. `python3 tests/scripts/verify-ci-summary-output.py` → 0 failed.
2. `./scripts/run-tests.sh --scripts-static` → all suites pass.
3. The workflow parses as YAML with exactly four jobs and only approved actions.
4. A mutation of the renderer makes the gate go red (negative proof).

## 21. Completion criteria

Standard §14: blocking semantics preserved, the new gate green, real numbers in
the report, no production action.

## 22. Operational findings observed while delivering this change

Recorded here because they are properties of the workflow being changed, and this
plan is the record of that change.

1. **`conexao-event-importer/tests/test-import-continuation.php` is load
   sensitive.** On commit `e586e5b` the `push` run failed in *Run in-process PHP
   suites* while the `pull_request` run of the **same commit** passed, and a
   later `workflow_dispatch` run of the same commit passed both the PHP and the
   HTTP layers. The suite drives a continuation cooldown and a wall-clock budget,
   so it degrades when the runner is busy. It is not caused by this change (this
   change touches no PHP suite) and it is not masked by it — the harness still
   fails closed.
2. **A `workflow_dispatch` on a branch cancels an in-flight `push` run for the
   same ref**, because `concurrency.group` is keyed on `github.ref`. The cancelled
   run's checks are left `pending`, which makes the PR report `UNSTABLE` even
   though every completed check is green. Prefer letting the in-flight run finish,
   or re-run it afterwards, rather than dispatching a second one while it is live.
3. **A failed run's artifact can be absent even when the upload step reports
   success**, because `if-no-files-found: warn` treats "no files" as success. When
   diagnosing, confirm the artifact exists before concluding an upload path is
   correct — a green run is the positive control.

_Last verified: 2026-11-28 by the CI reporting workstream_


