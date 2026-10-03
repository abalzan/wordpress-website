# Run and diagnose the test contract

## Purpose

How to **run**, **diagnose** and **compare** this repository's test contract, and
how to add a layer, a fixture or a permanent gate. The test *model* — what each
layer proves and how suites are classified — is a specification and lives in
`docs/testing.md`. This skill is the procedure for using it.

## When to use

- Running the suite, or a subset, and interpreting the result.
- A suite fails and you must decide whether it is a regression, pre-existing debt,
  or a broken environment.
- Bootstrapping the local Docker stack or the deterministic fixtures.
- Adding a new test layer, a new acceptance area, a fixture, or promoting a check
  to a permanent invariant gate.
- Producing the before/after regression comparison a report requires.

## When not to use

This skill does not teach you to *write* a suite. Use
`wp-write-in-process-test` for the in-process layer, and
`wp-http-acceptance-matrix` for request-visible behaviour. Use `wp-security-review`
for guard review and `wp-frontend-perf` for caching work.

## Required reading

- `AGENTS.md`.
- `docs/testing.md` — the authoritative testing model: the three layers, the suite
  classification, the permanent invariant gates, the shared bootstrap and
  assertion API, the base URL and the CI contract.
- `docs/engineering-standard.md` §8 (the normative testing standard) and §14.
- `scripts/run-tests.sh` — the one runner, read its header for the flags.
- `tests/bootstrap.php` and `tests/lib/assertions.php` — the shared harness.
- `tests/baseline/permanent-gates.json` — the recorded pre-existing debt.

## Authoritative sources

| Fact | Read it from |
|---|---|
| Layer definitions and what each proves | `docs/testing.md` |
| Test rules, MUST/SHOULD | `docs/engineering-standard.md` §8 |
| Permanent invariants and what they enforce | `docs/testing.md` + `docs/engineering-standard.md` §6.3 |
| Pre-existing failure baseline | `tests/baseline/permanent-gates.json` |
| Suite inventory (discovered, not hand-written) | `./scripts/run-tests.sh --list` |
| Fixture/bootstrap/assertion APIs | `tests/bootstrap.php`, `tests/lib/assertions.php` |

## Preconditions

- The local stack is up (`docker compose up -d`) for the in-process and
  acceptance layers. The **script-contract layer needs no stack** and always runs.
- `CONEXAO_TEST_BASE_URL` points at the local site. A production host is
  **refused** by the runner; never override it with one.
- You have the **before** result for whatever you are about to change. A
  comparison without a baseline is not a comparison.

## Steps

1. **Run the whole contract, not a convenient subset.**

   ```bash
   ./scripts/run-tests.sh
   ```

   Three layers, one aggregate exit code: in-process PHP, script contract, HTTP
   acceptance. Narrow with `--scripts`, `--acceptance`, `--php`, or
   `--only <component>`. `--list` shows what was discovered. An unknown `--only`
   value is an error, never "run everything".
2. **Read the verdict, not just the exit code.** `ALL TESTS PASSED` is exit 0;
   `TESTS FAILED` lists the failing suites and is exit 1. A `BLOCKED` line means
   the environment was unavailable — an unavailable environment is **not** a pass.
3. **Capture the baseline before changing anything.** Save the full log, the list
   of failing suites and the numeric totals. This is the "before" column of the
   regression comparison, and it is what makes "no new failures" a claim rather
   than an opinion.
4. **Classify every failure before touching code.** Use this order:
   - *Environment* — `insufficient data:`, `[BLOCKED]`, bootstrap failure. Fix the
     environment (stack, fixtures) or record it; it is not a code defect.
   - *Pre-existing debt* — present in the baseline you captured in step 3, or
     listed in `tests/baseline/permanent-gates.json`. Still fails; still exit
     non-zero. Never suppressed, never allowlisted away.
   - *Regression* — introduced by your change. This is the only category you fix
     as part of the task.
   Record the classification. A permanent gate prints `pre_existing` vs `new` to
   make this explicit; `new > 0` means you introduced it.
5. **Diagnose in the right layer.** A failure in the acceptance layer is usually
   a route/canonical/hreflang contract problem (`docs/routing.md`); a failure in
   the in-process layer is usually a logic or policy problem; a script-contract
   failure is almost always a contract violation in `scripts/` (shared bootstrap
   or REST helper, hard-coded production target, credential literal, missing
   catalogue entry, stale renamed path).
6. **Prove the test discriminates.** For a suite you added or a guard you
   implemented, revert the behaviour locally, confirm the suite fails, then
   restore. A test that still passes is vacuous and must be rewritten.
7. **Add a suite by convention, never by list.** An in-process suite is a correctly
   named file in a component's `tests/` directory. A new acceptance area is a
   `verify-<area>-http.py` plus its matrix. A new assertion goes into
   `tests/lib/assertions.php`, not into a suite. There is no hand-maintained suite
   list anywhere.
8. **Promote a check to a permanent gate deliberately.** A permanent gate runs in
   the **default** suite and **fails closed** — a real violation exits non-zero,
   and a missing prerequisite prints `insufficient data:` and also fails. A gate
   is never downgraded to a warning, never skipped, and never satisfied by an
   allowlist. Stage its debt in `tests/baseline/permanent-gates.json` if the
   repository already carries the violation; the baseline changes only the *label*,
   never the outcome.
9. **Re-run the full contract** after the change and produce the comparison:
   the failing-suite list before vs after, the numeric totals before vs after, and
   an explicit statement of which failures are pre-existing.
10. **Report honestly.** Paste the real numbers. A verifier that could not run is

## Guardrails

- **Never weaken a gate to get a green run.** Do not delete an assertion, relax an
  expectation, add an allowlist, or convert a failure into a skip to make a run
  pass. A permanent gate is fail-closed; a run that is green because it stopped
  checking is worse than a red run.
- **Never point a test at production.** The runner and the HTTP client both refuse
  a production base URL, and no request is sent when they do. Production checking
  is the separate read-only `scripts/verify-deploy.py --site <url>`.
- **No vacuous passes.** A suite that cannot run must fail loudly
  (`test_require`), not skip quietly.
- **A missing prerequisite is a failure, not a skip.** Suites declare their hint
  with `test_prerequisite_hint()` and enforce it with `test_require()`.
- Never hand-write a list of suite files; discovery is convention-based and CI
  calls the same command you do.
- Never add an assertion helper inside a suite; extend
  `tests/lib/assertions.php`.
- Never report "acceptance passes" without the exact row and assertion counts.
- Never touch the Flutter/mobile repository from this task.

## Verification

```bash
./scripts/run-tests.sh --list          # what is discovered
./scripts/run-tests.sh                 # the whole contract, one exit code
./scripts/run-tests.sh --scripts       # script-contract layer only (no stack)
./scripts/run-tests.sh --only theme    # one component
python3 scripts/verify-permanent-gates.py      # the aggregate permanent-gate record
python3 tests/scripts/verify-agent-governance.py
```

- The runner prints `ALL TESTS PASSED` (exit 0) or names the failing suites
  (exit 1). Paste the real totals.
- The failing-suite list after the change is **identical to** the baseline, or the
  new failures are explained and fixed.
- Every new suite is discovered by `--list` with no list file edited.
- A new permanent gate is proven to **fail** on a real violation and to fail on
  a missing prerequisite.
- Any verifier that could not run is recorded as blocked/not-tested, not passed.

## Failure handling

- *`insufficient data: <hint>`* — a data prerequisite is missing. Seed it
  (see `scripts/README.md` for the seed scripts) and re-run. Do not delete the
  prerequisite to make the suite pass.
- *`[BLOCKED] HTTP acceptance environment unavailable`* — the local site is not
  reachable. Start the stack, or record the layer as blocked. An unavailable
  layer is never a pass and is never silently skipped.
- *A permanent gate reports `new > 0`* — you introduced the violation. Fix it;
  do not move it to the baseline.
- *A permanent gate reports `pre_existing`* — the debt predates you. It still
  fails. Record it; do not delete the baseline entry to hide it.
- *A suite fails after a change you did not make* — confirm against the baseline
  before assuming causation, and confirm no other contributor's work is being
  attributed to you.
- *A test passes both before and after your change* — it is vacuous. Rewrite it so
  it fails when the behaviour is reverted.

## Evidence and reporting

Save the before and after logs and the regression comparison to
`docs/evidence/<date>-<stage>/`. The report must state: the suites and numeric
totals before and after, the permanent-gate result, the documentation-drift and
registry-drift results, the classification of every remaining failure, and the
verifiers that could not run. Use `docs/templates/report.md`.

## Definition of done

- [ ] `./scripts/run-tests.sh` was run for the whole contract, and the verdict and
      totals are recorded.
- [ ] A before/after regression comparison exists with the failing-suite list.
- [ ] Every failure is classified as environment, pre-existing or regression.
- [ ] No gate, assertion or expectation was weakened to obtain a pass.
- [ ] New suites/areas were added by convention; no hand-written suite list changed.
- [ ] A new permanent gate is fail-closed and proven to fail on a violation and on
      a missing prerequisite.
- [ ] No test contacted production; no production host was configured.
- [ ] Logs and the comparison are under `docs/evidence/<date>-<stage>/`.
- [ ] Blocked and not-tested verifiers are named explicitly, not reported as pass.

    recorded as blocked or not tested — never as verified.

