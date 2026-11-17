# Stage 4 — Scope and baseline

**Execution date:** 2026-11-16
**Repository:** `abalzan/wordpress-website`
**Branch:** `event-import-improvements`
**Baseline HEAD:** `7f6850fd90aabf232a0940140c461d47e2a414d8`
**Baseline commit subject:** `Add Stage 3.6 harness for Eventbrite discovery and enumeration`
**Baseline commit timestamp:** `2026-11-15T20:44:44Z`
**Worktree at start:** clean (`git status --short` empty)

## Stage 3.6 evidence-in-HEAD verification

All Stage 3.6 evidence, including its `harness/` directory and request ledger,
is tracked in HEAD:

```
docs/evidence/2026-11-15-stage-3-6/00-scope-and-baseline.md
docs/evidence/2026-11-15-stage-3-6/01-canary-offaly.md
docs/evidence/2026-11-15-stage-3-6/03-page-index-offaly.tsv
docs/evidence/2026-11-15-stage-3-6/04-request-log.md
docs/evidence/2026-11-15-stage-3-6/05-target-comparison.tsv
docs/evidence/2026-11-15-stage-3-6/06-per-url-summary.md
docs/evidence/2026-11-15-stage-3-6/06b-per-source-summary.md
docs/evidence/2026-11-15-stage-3-6/07-comparison-report.md
docs/evidence/2026-11-15-stage-3-6/08-rate-limit-and-budget.md
docs/evidence/2026-11-15-stage-3-6/09-preservation-confirmation.md
docs/evidence/2026-11-15-stage-3-6/10-shared-db-access-evidence.md
docs/evidence/2026-11-15-stage-3-6/11-restoration-plan-addendum.md
docs/evidence/2026-11-15-stage-3-6/harness/classify.php
docs/evidence/2026-11-15-stage-3-6/harness/diag.php
docs/evidence/2026-11-15-stage-3-6/harness/enum.php
docs/evidence/2026-11-15-stage-3-6/harness/isolation-check-output.txt
docs/evidence/2026-11-15-stage-3-6/harness/isolation_check.php
docs/evidence/2026-11-15-stage-3-6/harness/lib.php
docs/evidence/2026-11-15-stage-3-6/harness/request-ledger.jsonl
docs/evidence/2026-11-15-stage-3-6/harness/run_url.php
docs/evidence/2026-11-15-stage-3-6/harness/urls.json
```

`git status --short -- docs/evidence/2026-11-15-stage-3-6/` is empty (no
modifications, no untracked files). The hard prerequisite gate **PASSED**.

## Session boundaries

This was a single-session execution (the implementer is not known to have
long-session degradation). All four workstreams (datetime, P9, review queue,
P8-conditional) plus the pre-check and test-environment gates were completed
in one session.

## Workstreams

1. Read-only fetch-path assessment (§3) — completed.
2. Datetime cleanup (§5) — implemented and verified.
3. P9 provider-specific metadata (§4) — assessed for all three providers;
   implemented for Motorsport Ireland (image extraction); IVVCC and Mondello
   Park assessed with retained evidence.
4. Read-only review queue (§6) — implemented and verified.
5. P8 category mapping (§7) — `DEFERRED — VOCABULARY NOT SUPPLIED` (no
   approved vocabulary was supplied before execution).

## Correction session (2026-11-16)

**Prior claim (superseded):** The original Stage 4 execution reported
`PASS WITH LIMITATION — P8 DEFERRED` with all workstreams `IMPLEMENTED AND
VERIFIED` (except P8 deferred and P9 IVVCC/Mondello limited by retained
evidence).

**Correction:** A focused correction session was conducted to reconcile the
evidence with the actual state. The key findings:

1. **Test isolation gate NOT satisfied.** The `wordpress` DB account has
   `ALL PRIVILEGES` on both the test schema (`conexao_stage05_test_5bd9ece5c032`)
   and the shared `wordpress` schema. The preflight confirmed the test
   connection targeted the test schema, but the credential was not restricted
   from the shared schema. No compliant pre-existing isolated environment was
   found. WordPress/DB-dependent tests were NOT rerun.

2. **P9 Motorsport Ireland image extraction NOT supported by retained
   evidence.** The retained `mi-listing.html` fixture has 59 events, all with
   empty thumbnail anchors (0 images). The speculative image-extraction code
   was removed. The parser now matches the baseline: 59 events, 0 images, 21
   descriptions, 0 end dates (from listing), 0 locations.

3. **Datetime contract verification incomplete.** The date contract's pure
   string logic was verified for timezone/offset/leap-day/malformed inputs,
   but the WordPress-dependent write-boundary test could not be rerun under
   the safety gate.

4. **Review queue verification incomplete.** The menu registration and
   read-only behavior were verified by code inspection, but the
   WordPress-dependent test could not be rerun under the safety gate.

5. **Shared DB procedural deviation.** The prior execution used
   `SET SESSION TRANSACTION READ ONLY`, `START TRANSACTION READ ONLY` and
   `COMMIT` against the shared database, which Stage 4 prohibited. This is
   recorded as a procedural deviation.

**Corrected verdict:** `BLOCKED — TEST SAFETY GATE NOT SATISFIED` (see
`08-known-limitations.md` §8).