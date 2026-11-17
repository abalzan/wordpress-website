# Plan — Stage 4 closeout finalisation

| | |
|---|---|
| **Task title** | Stage 4 closeout — evidence reconciliation, Stage 1 fixture verification and final suite rerun |
| **Date** | 2026-11-16 |
| **Author / agent** | Codex |
| **Branch** | `event-import-improvements` |
| **Repository baseline (starting SHA)** | `7f6850f` (`7f6850fd90aabf232a0940140c461d47e2a414d8`) |
| **Applicable skill(s)** | `wp-repository`, `wp-testing` |
| **Plan status** | approved |

## 1. Scope

Reconcile the current repository, isolation evidence, D2 history, menu placement,
Stage 1 fixture helper and one controlled twelve-suite rerun. Append truthful
Stage 4 evidence and report the Stage 4 programme as blocked while preserving
the separate task-execution status.

## 2. Explicit non-goals

Do not repair or redesign D2, change the menu hierarchy, alter D2 assertions or
fixtures, touch importer/runtime application code, modify Stage 3.5/3.6
evidence, clean the disposable schema manually, or perform production,
deployment, activation, commit, push, merge, reset, stash or release actions.

## 3. Relevant authoritative documents

`AGENTS.md`, `docs/engineering-standard.md`, `docs/testing.md`,
`scripts/run-tests.sh`, `tests/bootstrap.php`,
`tests/lib/assertions.php`, `tests/baseline/permanent-gates.json`, the current
Stage 4 evidence files, and the Stage 3 report section.

## 3a. Skill workflow

| | |
|---|---|
| **Skill** | `wp-repository` for scope/plan/report discipline; `wp-testing` for isolated sequential execution and baseline comparison |
| **Its preconditions** | Correct branch, recorded dirty worktree, unchanged Stage 3.6 evidence, running local stack, existing restricted wrapper and proof |
| **Its guardrails that bind this task** | Preserve pre-existing changes; use the existing harness; never weaken tests; report blocked/incomplete results honestly |
| **Its verification commands** | Git baseline/status, `docker compose ps`, grant/proof checks, `php -l`, focused Stage 1 suite, controlled twelve-suite run, post-run counts |
| **Its evidence location** | `docs/evidence/2026-11-16-stage-4/` |

## 4. Current-state findings

The branch is correct at `7f6850f`; the worktree already contains unrelated and
prior Stage 4 changes, including the Stage 1 test, review-queue implementation,
isolation wrapper/proof and Stage 4 evidence. Stage 3.6 evidence is clean. The
current review-queue implementation has no `suppress_filters` argument or
explicit `_event_status` filter to remove. The Stage 1 helper already caches a
site-local `wp_timezone()` date and emits the parser-compatible `j F Y` format.

## 5. Proposed approach

Verify existing infrastructure and code, make no review-queue or Stage 1 code
change unless the inspected state requires the narrowly authorised cleanup,
run the focused and twelve-suite checks through the existing wrapper, then
append reconciled evidence. The D2 repair options are recorded only as future
design options; none is selected here.

## 6. Files expected to change

| Path | Change | Why |
|---|---|---|
| `docs/plans/2026-11-16-stage-4-closeout-finalisation.md` | Add | Required execution plan |
| `docs/evidence/2026-11-16-stage-4/04-review-queue.md` | Append | Menu and D2 dispositions |
| `docs/evidence/2026-11-16-stage-4/06-tests-and-gates.md` | Append | Fresh gates, suite table and drift |
| `docs/evidence/2026-11-16-stage-4/08-known-limitations.md` | Append | Final sub-statuses and owner acceptance |
| `docs/evidence/2026-11-16-stage-4/09-test-isolation-infrastructure.md` | Append | Fresh isolation and credential assessment |
| `docs/evidence/2026-11-16-stage-4/10-d2-diagnosis.md` | Append | Chronological D2 reconciliation |
| `docs/reports/2026-11-15-stage-3-event-importer-diagnostics-retention.md` | Append | Superseding Stage 4 closeout section |
| `docs/reports/2026-11-16-stage-4-closeout-finalisation.md` | Add | Task report |

No application-code change is expected.

## 7. Files explicitly expected NOT to change

The review-queue class/test, Stage 1 test, importer runtime, `wp-config.php`,
`compose.yaml`, CI, plugin registry, Stage 3.5/3.6 evidence and all live/shared
or disposable-site data outside normal test fixtures remain untouched.

## 8. Content / data impact

- Records created / updated / deleted: no manual records; test suites may create
  and remove disposable-schema fixtures under their existing contract.
- Portable identifier strategy: not applicable.
- This task does not perform a content write workflow.
- PT content changed: no.

## 9. Route / HTTP impact

No route, redirect, sitemap or HTTP matrix change.

## 10. Polylang / English impact

None. PT remains immutable and no translation/content operation is performed.

## 11. Security impact

Verification only: the existing restricted account, protected secret-file
wrapper, `$wpdb` identity guard and narrowly scoped negative probes. No secret is
read into output and no credential rotation is performed.

## 12. Performance impact

No application query or cache change. Suite duration is recorded, including the
slow review-queue run.

## 13. Production impact

| Question | Answer |
|---|---|
| Will anything be written to production? | no |
| Will a deploy, upload or activation happen? | no |
| Who performs it, and how? | not applicable |
| What is the verification of the production result? | not applicable |

## 14. Test plan

Run the existing isolation proof, the Stage 1 coordination suite, and the twelve
specified importer suites sequentially through `scripts/conexao-test-exec.sh`.
Record identity, counts, exit codes, durations, failures, timeout state and
pre/post disposable-schema counts.

## 15. Acceptance matrix plan

Not applicable; no HTTP acceptance row changes.

## 16. Rollback plan

No application rollback is needed. Evidence additions can be removed by a
reviewer using the pre-edit snapshots recorded outside the repository. Do not
restore snapshots over files automatically and do not undo pre-existing dirty
work.

## 17. Documentation plan

Append the specified Stage 4 evidence and Stage 3 report history, plus this
task report. No canonical procedure or index is changed.

## 18. Release implications

No component version, artifact, release or deployment change.

## 19. Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| Attributing prior contradictory claims to the fresh run | medium | Keep chronological evidence and separate current output |
| A slow suite times out | medium | 600-second per-suite boundary; stop only that process and record incomplete |
| Scope creep into D2/menu repair | high | no code changes unless the narrowly authorised residue is present |
| Test fixtures leave disposable residue | low | use existing suites only; record observed count delta; do not manually clean |

## 20. Verification gates

1. Fresh grants, file/guard inspection and 9-probe isolation proof.
2. Stage 1 focused suite: identity pass and `47 passed, 0 failed`.
3. Twelve sequential suites with terminal outcomes and exact baseline comparison.
4. `php -l` on any changed PHP file; no review-queue or Stage 1 code change is expected.
5. Evidence append and final Stage 4 verdict: `BLOCKED — D2 UNRESOLVED AND MENU PLACEMENT AMBIGUOUS`.

## 21. Completion criteria

The authorised evidence reconciliation is complete, no unauthorised code or
production change is made, all required fresh results are recorded honestly,
Stage 4 remains blocked for the unresolved D2/menu decisions, and task
execution status is reported separately.

_Last verified: 2026-11-16 by Codex — Stage 4 closeout finalisation_

