# CI database isolation and Stage L repair

| | |
|---|---|
| **Date** | 2026-11-26 |
| **Branch** | `event-import-improvements` |
| **Start / final SHA** | `3c67290f7987642f793363cd0f96e835595b9eab` / unchanged |
| **Production action** | None |
| **Final status** | **BLOCKED** — isolated setup, static quality and Stage L pass, but the full PHP suite still has 16 failures |

## Root causes and corrections

1. **Isolated database setup:** the old workflow looked for the database root
   password in the WordPress container, where it is not defined. It is defined
   only in the database container. The workflow now calls
   [`.github/scripts/isolated-test-database.sh`](../../.github/scripts/isolated-test-database.sh).
   The helper reads root credentials inside `db`, streams SQL through stdin,
   uses generated hex-only database/user/password values, clones with
   `--set-gtid-purged=OFF`, grants privileges only on the unique test schema,
   verifies access and denial of the shared schema, writes credential files
   with mode `0600`, and restores/drops temporary resources on cleanup. Safe
   failure diagnostics report the operation, command class and exit status,
   never SQL/password contents.
2. **ShellCheck SC2329:** `remove_test_secret_from_container()` was already
   called by `cleanup_runner_on_exit()` through the registered EXIT trap.
   ShellCheck did not follow that callback. The narrowly documented
   suppression is on the callback. Cleanup runs on success and failure, and a
   cleanup failure changes a successful test exit to failure.
3. **Stage L credential handoff:** the aggregate invoked PHP gates directly
   with Docker `-e` values for the database name/user, bypassing
   `conexao-test-exec.sh`, which is the sanctioned pre-bootstrap boundary for
   restricted credentials. The gates therefore failed before assertions with
   `CONEXAO_TEST_DB_PASSWORD is required`. The aggregate now copies the
   mode-0600 host secret to the protected container path, invokes the wrapper,
   and removes the copy in `finally`; passwords are never placed in argv or
   gate evidence. Evidence now uses `docs/evidence/<date>-stage-l/` instead of
   overwriting the historical 2026-09-26 artifact.
4. **Acceptance setup:** `.env` loading overwrote explicit workflow
   `WP_USERNAME` and password values. The test runner now preserves those
   explicit environment values. The routing matrix also had stale assertions
   for the event city-filter UI, which the current theme intentionally hides;
   it now checks the current county-only UI and valid EN legacy URL behavior.

The full-history i18n check also identified five stale `.pot` catalogues; they
were regenerated with the repository generator. Two importer test constructor
calls were updated to the current clock-injection API.

## Verification

| Command / check | Result |
|---|---|
| Isolated DB helper `create` against local Compose | Passed: generated unique schema/user; restricted account accessed only the test schema; `.conexao-test.env` mode `0600`; preflight passed |
| Isolated DB helper `cleanup` | Passed: WordPress restored to shared `wordpress`; generated schema/user counts both `0`; copied secret and generated Compose credential file absent |
| `bash -n .github/scripts/isolated-test-database.sh` | Passed |
| `shellcheck scripts/*.sh` | Passed |
| `shellcheck scripts/lib/*.sh` | Passed |
| `shellcheck .github/scripts/isolated-test-database.sh` | Passed |
| `./scripts/lint.sh` | Passed: PHP syntax clean (622 files), no new PHPCS baseline violations, PHPStan level 5 clean (253 files) |
| `./scripts/run-tests.sh --scripts-static` | Passed: 19 suites, 19 passed, 0 failed |
| `python3 tests/scripts/verify-script-conventions.py` | Passed: 803 assertions, 0 failed |
| Final `./scripts/run-tests.sh --acceptance` with the CI test account | Passed: 5 suites, 255 assertions, 0 failed |
| Final `python3 scripts/verify-permanent-gates.py` | **Passed: 15/15 gates, 259 assertions, 0 violations, 0 blocked** |
| `python3 scripts/verify-permanent-gates.py --list` | Passed |
| `git diff --check` | Passed |

### Stage L gate results

All passed: taxonomy (18), translation completeness (23; 289 documented
allowlisted records), runtime cache scoping (9), static cache scoping (2),
redirect precedence (15), documentation drift (17), i18n freshness (3),
commemorative no-asset-without-event (5), no-public-activation (5), CPT
privacy (11), single-package dispatch (6), ambient decoration (7), placement
assets (4), ambient runtime (104), and translation ownership (30).

Machine-readable evidence:
[gate.json](../evidence/2026-11-26-stage-l/gate.json).

## Full integration result and remaining blocker

`./scripts/run-tests.sh` did execute all layers against the isolated test
database, but did **not** pass:

- In-process PHP: 153 suites; 137 passed, 16 failed; 10,915 assertions passed,
  69 failed.
- Script contracts: 19/19 passed.
- HTTP acceptance in that full run: 3/5 passed. After fixing the explicit
  test-account environment handoff and stale routing expectations, a separate
  final `--acceptance` run passed 5/5 (255 assertions).

The 16 PHP failures were:
`test-event-cleanup-batch-runner.php`, `test-import-continuation.php`,
`test-stage1-run-coordination.php`, `test-christmas-egg.php`,
`test-en-course-provider-category-filter.php`, `test-event-filter-cache.php`,
`test-event-location-filters.php`, `test-filter-link-aria-semantics.php`,
`test-leisure-card-map-action.php`, `test-leisure-location-services.php`,
`test-leisure-multiselect-filters.php`, `test-leisure-related-events.php`,
`test-stage41-rest-language.php`, `test-commemorative-christmas-journey.php`,
`test-commemorative-christmas-welcome.php`, and `test-stage32-bilingual.php`.
Observed failures included wall-clock continuation expectations, fixture/data
assumptions, and existing theme assertions. The original CI failure prevented
these suites from running, so these failures cannot honestly be classified as
pre-existing; they remain unresolved and no assertions were suppressed.

The local database was restored after verification; no commit, push, merge,
deployment, production access, or production data change occurred.

_Last verified: 2026-11-26 by isolated CI database setup, static quality, acceptance and Stage L verification_
