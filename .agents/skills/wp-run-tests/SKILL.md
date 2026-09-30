# Run the test contract

## Purpose

Run this repository's full verification contract — what to run, in what order,
how to diagnose a failure, how to tell a regression from pre-existing debt,
and when a change may be called verified. This skill is the **runner** side of
testing; writing new suites is `wp-write-in-process-test` (in-process) and
`wp-http-acceptance-matrix` (HTTP).

## When to use

- Before claiming any change is verified, and before every commit that changes
  behaviour, content, routes or tooling.
- When a suite or gate has failed and you need to know whether it is your
  change, pre-existing debt, or a broken environment.
- When preparing a release (`wp-release-deploy` requires a green run first).

## When not to use

- Writing or extending a test suite — use `wp-write-in-process-test` or
  `wp-http-acceptance-matrix`.
- Only checking documentation drift or agent governance in isolation — those
  gates run standalone (see Verification), but a change is never "verified" by
  one gate alone.

## Required reading

- `AGENTS.md` — the non-negotiable rules and the single test command.
- `docs/testing.md` — **the testing model**: the three layers, the suite
  classification table, the permanent gates, the baseline policy, prerequisites
  and the acceptance base URL.
- `docs/engineering-standard.md` §8 (the testing standard) and §6.3 (the
  permanent invariants).
- `docs/development.md` — the local Docker requirement and the quality
  toolchain behind `./scripts/lint.sh`.

## Authoritative sources

- `docs/testing.md` owns the layer model, suite classification and baseline
  policy — never restate a suite list from memory; run `--list`.
- `tests/baseline/permanent-gates.json` owns the recorded pre-existing
  permanent-gate debt (reporting only; it never suppresses a failure).
- `scripts/README.md` owns the script inventory; `./scripts/run-tests.sh` owns
  discovery — there is no hand-maintained suite list anywhere.

## Preconditions

- The local Docker Compose site is up (`docker compose up -d`) for the
  in-process and acceptance layers; the script-contract layer needs no
  WordPress and runs anywhere.
- `./scripts/lint.sh` needs the PHP toolchain (`composer install` once — see
  `docs/development.md`).
- For release work, `wp-release-deploy` lists its own preconditions.

## Steps

1. **Lint first.** `./scripts/lint.sh` (PHP syntax + PHPCS baseline + PHPStan).
   It fails on any *new* violation; legacy debt lives in recorded baselines
   and is not your failure unless it grew.
2. **Run the whole contract with one command.**
   ```bash
   ./scripts/run-tests.sh               # all layers, one aggregate exit code
   ```
   Narrow only while iterating: `--php` (in-process), `--scripts`
   (script-contract gate), `--acceptance` (HTTP), `--only <component>` (one
   component). `--list` prints every discovered suite plus the manual ones.
3. **Read the aggregate, then the failing suite.** The runner never silently
   downgrades: without a local site it reports
   `BLOCKED: HTTP acceptance environment unavailable` and exits non-zero.
   A blocked layer is an environment failure, not a pass.
4. **Classify every failure** using `docs/testing.md`'s classification table:
   - **Your regression** — the suite passes on the base commit and fails after
     your change. Fix the change, not the test.
   - **Pre-existing debt** — identical failure on the base commit (run
     `git stash` + the suite, or check out the base commit in a work tree).
     For permanent-gate debt, cross-check
     `tests/baseline/permanent-gates.json`; it records pre-existing failures
     so a gate can say "pre-existing" instead of "regression" — it never
     suppresses one.
   - **Environment** — Docker down, missing fixtures, missing toolchain.
     Repair the environment and re-run; never report a blocked layer as green.
5. **Run the permanent invariant gates.**
   ```bash
   python3 scripts/verify-permanent-gates.py          # every gate + gate.json
   python3 scripts/verify-permanent-gates.py --list
   ```
   They are ordinary fail-closed suites discovered by convention; a red gate
   is repaired by fixing the data or tool that produced it — **never the
   gate** (the per-gate remedy table is in `docs/testing.md`).
6. **Compare regressions, not snapshots.** The failing-suite list before your
   change and after it must be identical, except for suites your change
   deliberately fixes or adds. Record both lists in the report.
7. **Stop only when green or honestly limited.** Done = every layer exits 0,
   or the only failures are proven pre-existing (identical on the base commit)
   and recorded as such, or the verifier was unavailable — in which case the
   report says **blocked / not tested**, never "verified".

## Guardrails

- One runner, one aggregate exit code — never a second test entry point.
- Never weaken an assertion, delete a row, or widen a baseline to get green.
- Never treat a blocked environment as a pass; never skip a failing suite.
- The acceptance layer targets the local site only (production hosts are
  refused) — see `wp-http-acceptance-matrix`.
- Manual and production-only suites never run by default; do not "make them
  run" by renaming them into discovery.
- Report the real numbers: `N suites, N passed, N failed`,
  `N assertions passed, N failed`, `N rows` — a numberless "passes" is a
  reporting defect.
- Never touch the Flutter/mobile repository from this task.

## Verification

```bash
./scripts/lint.sh                                  # 0 new violations
./scripts/run-tests.sh                             # aggregate exit 0
python3 scripts/verify-permanent-gates.py          # every gate + gate.json
python3 tests/scripts/verify-agent-governance.py   # governance gate (static)
python3 tests/scripts/verify-documentation-drift.py # documentation drift gate
```

- Paste the per-layer totals and the permanent-gate output into the report.
- Prove at least one verifier can fail when it should (a wrong expectation
  fails a row; a tampered artifact fails release integrity) when you are
  relying on it for the first time.

## Failure handling

- **A suite fails and you did not touch its area:** classify it (step 4)
  before touching anything; a pre-existing failure is recorded, not "fixed"
  opportunistically (scope creep).
- **A gate fails on debt that predates your change:** record it in the report
  as pre-existing with the baseline reference; do not pay down unrelated debt
  inside your change.
- **The environment blocks a layer:** report blocked with the exact message;
  CI runs the same command on the same committed fixtures and is the
  authoritative re-run — link the CI run.
- **A failure only appears in CI:** CI is the same runner; reproduce locally
  with the committed CI fixtures before blaming CI.

## Evidence and reporting

- Save the raw runner output and the permanent-gate output under
  `docs/evidence/<date>-<stage>/`; cite them from the report.
- The report (from `docs/templates/report.md`) carries the numeric results
  section, the regression comparison (before/after failing lists), and the
  pre-existing failures listed separately.

## Definition of done

- [ ] `./scripts/lint.sh` ran and reports no new violation.
- [ ] `./scripts/run-tests.sh` ran all layers with one aggregate exit code,
      or every unavailable layer is reported as blocked.
- [ ] Every failure is classified: regression (fixed), pre-existing
      (baseline-referenced), or environment (repaired and re-run).
- [ ] The permanent gates ran; every red gate has its documented remedy
      applied or its debt recorded as pre-existing.
- [ ] The before/after failing-suite lists are recorded and identical apart
      from deliberate changes.
- [ ] The report contains real per-layer numbers and cites the evidence files.
- [ ] No assertion, gate or baseline was weakened to obtain a green run.
