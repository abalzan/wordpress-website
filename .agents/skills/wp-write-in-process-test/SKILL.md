# Write an in-process test

## When to use

Any change to PHP behaviour needs a test that fails when the change is reverted.
This is the **in-process PHP layer**: functions, queries, policies, taxonomies,
language logic, metadata and cache logic, exercised against a real WordPress.

For request-visible behaviour use `wp-http-acceptance-matrix` as well. New
feature work ships **one in-process suite plus one HTTP matrix addition**.

## Required reading

- `AGENTS.md`.
- `docs/engineering-standard.md` §8 (the testing standard).
- `docs/testing.md` — the practical companion: the three layers, prerequisites,
  fixtures, the acceptance base URL and the suite classification.
- `tests/bootstrap.php` — the single bootstrap. Read it before writing a line.
- `tests/lib/assertions.php` — the shared assertion API.
- An existing suite in the component you are testing, e.g.
  `wp-content/plugins/conexao-event-runtime/tests/`.

## Steps

1. **Place and name the file by convention.** A suite lives at
   `<component>/tests/test-<area>-<behaviour>.php` — that is
   `wp-content/themes/conexao-br-irlanda/tests/` or
   `wp-content/plugins/<slug>/tests/`. `scripts/run-tests.sh` discovers
   `test-*.php` by glob, so **no list anywhere is edited**. Naming it anything
   else means it silently never runs.
2. **Require the shared bootstrap — nothing else.**
   ```php
   require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';
   ```
   (depth depends on the component). Never locate or `require` the WordPress loader
   (the WordPress `wp-load.php` loader) yourself: §8.2 makes
   `tests/bootstrap.php` the only place allowed to.
3. **Use the shared assertions.** Import from `tests/lib/assertions.php`:
   `assert_true()`, `assert_equals()`, `assert_contains()`, `assert_absent()`,
   `assert_set()`, `assert_fail()`, plus `test_section()`, `test_title()`,
   `test_require()`, `test_prerequisite_hint()` and `test_finish()`. Do **not**
   define a suite-local `*_assert()` helper — that duplication is what Stage E
   removed.
4. **Structure the suite.** Title, then `test_section()` blocks, then
   `test_finish( '<suite label>' )` as the last statement. `test_finish()` is
   the single place the exit status is decided: it prints
   `<label>: N passed, M failed` and exits non-zero when `M > 0`.
5. **Declare data prerequisites and fail loudly.** Call
   `test_prerequisite_hint( 'seed-leisure' )` and
   `test_require( $have_data, 'hint', 'message' )` when the suite depends on
   seeded content. A missing prerequisite prints `insufficient data: <hint>` and
   **exits non-zero**. This is the anti-vacuous-testing rule: a suite that cannot
   run must fail, never pass quietly.
6. **Make it read-only by default.** A suite must not mutate content unless its
   name says so. A write-capable test creates **and removes** its own fixtures,
   and never touches records it did not create.
7. **Assert behaviour, not implementation trivia.** Assert the query returns the
   expected records, the policy holds, the cache key is language-scoped — not that
   a particular line was reached. Include at least one assertion that would fail
   if the change were reverted.
8. **Ship the negative case.** For a guard (nonce, capability, PT-drift, status
   gate), assert the guard actually *blocks* the bad input. A guard that is never
   proven to fail is untested.
9. **Permanent invariants go in the default suite.** Taxonomy policy, EN
   completeness gates and cache scoping are permanent invariants (§6.3) and run
   in the default suite, not behind a flag.
10. **Document the suite** in `docs/testing.md` only if it changes the layer's
    inventory or a prerequisite; otherwise the convention is enough.

## Guardrails

- **No bespoke assertion helpers** and no second bootstrap.
- **No vacuous passes**: an unrunnable suite fails loudly, it does not skip.
- Suites must not contact production, infer a production URL, activate plugins,
  run migrations or send external requests.
- `tests/` directories are **never shipped** in a release ZIP — that is enforced
  by `scripts/lib/zip-build.sh` and asserted by
  `tests/scripts/verify-release-integrity.py`.
- Heavy fixtures live in `tests/fixtures/` with a documented size budget, and
  are excluded from artifacts.
- Every new function needs a docblock with `@param` and `@return`
  (`scripts/lint.sh` enforces it).
- Assertions report what actually happened; never soften a failure into a skip.

## Verification

```bash
./scripts/run-tests.sh --list                     # the suite is discovered
./scripts/run-tests.sh --only conexao-<slug>      # run just this component
./scripts/run-tests.sh --php                      # the whole in-process layer
php -l <path/to/suite.php>                        # syntax
```

- The suite prints `N passed, M failed` and **exits 0**; paste the real numbers
  into the report.
- Prove the test actually discriminates: revert the behaviour locally, confirm
  the suite fails, then restore. If it still passes, the test is vacuous.
- Compare the failing-suite list against the baseline — a new failure is a
  regression, not noise.

## Definition of done

- [ ] The file is at `<component>/tests/test-<area>-<behaviour>.php` and is
      discovered by convention, with no hand-written list updated.
- [ ] It requires `tests/bootstrap.php` and resolves the WordPress loader
      nowhere else.
- [ ] All assertions come from `tests/lib/assertions.php`; no local helper.
- [ ] `test_finish()` ends the suite; it prints `N passed, M failed` and exits
      non-zero on failure.
- [ ] Data prerequisites are declared and a missing one fails loudly.
- [ ] The suite is read-only, or creates and removes exactly its own fixtures.
- [ ] At least one assertion fails if the change is reverted.
- [ ] Guards are proven negatively.
- [ ] `docs/testing.md` reflects any new prerequisite or layer inventory.
- [ ] The real pass/fail numbers appear in the report.
