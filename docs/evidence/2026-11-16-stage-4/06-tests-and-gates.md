# Stage 4 — Tests and gates

**Execution date:** 2026-11-16

## 1. Isolated test database proof

The test database is `conexao_stage05_test_5bd9ece5c032`, a dedicated test
database on the shared MySQL server (`wordpress-website-db-1`).

**Isolation verification:**

- `CONEXAO_TEST_DB_NAME=conexao_stage05_test_5bd9ece5c032`
- `WORDPRESS_DB_NAME=conexao_stage05_test_5bd9ece5c032`
- `CONEXAO_TEST_SHARED_DB_NAME=wordpress`
- The `wordpress` user holds `ALL PRIVILEGES` on `conexao_stage05_test_5bd9ece5c032`
  AND on `wordpress` (the shared DB). Same-server privilege isolation is
  therefore **not** demonstrable for the `wordpress` user.

**However**, the test bootstrap (`tests/lib/isolated-db.php`) performs a raw
`mysqli` identity preflight that verifies the effective connection target
matches `CONEXAO_TEST_DB_NAME` exactly, and the shared `tests/bootstrap.php`
repeats the preflight before `wp-load.php`. The preflight prints
`ISOLATED_DB_PREFLIGHT=PASS database=conexao_stage05_test_5bd9ece5c032 host=db`
on every suite run, proving the effective database target is the isolated
test database, not the shared `wordpress` database.

**Limitation:** the `wordpress` user's `ALL PRIVILEGES` on `wordpress.*` means
the test process *could* read/write the shared database if it explicitly
connected to it. The preflight prevents this by verifying the effective
connection target, but the grant-level isolation is not absolute. This is a
known limitation of the local Docker test environment; the preflight is the
enforced guard.

**Prior claim (superseded):** The original Stage 4 execution reported
`PASSED` with the preflight as the enforced guard.

**Correction:** The `wordpress` DB account has `ALL PRIVILEGES` on both the
test schema and the shared `wordpress` schema. The preflight confirmed the
test connection targeted the test schema, but the credential was not
restricted from the shared schema. No compliant pre-existing isolated
environment was found. WordPress/DB-dependent tests were NOT rerun.

**Test/safety gate status:** `NOT PASSED — SAFE ISOLATED ENVIRONMENT
UNAVAILABLE`.

## 2. Test commands and results

All tests run inside the WordPress container
(`docker compose exec -T wordpress php ...`) with the isolated DB env.

### Stage 4 new/changed suites

| Suite | Result | test_class | credential_isolation_status | qualifying_under_gate |
|---|---|---|---|---|
| `test-event-date-contract.php` | 18 passed, 0 failed | Pure/static | N/A — NO DB ACCESS | YES — DOES NOT REQUIRE THE ISOLATION GATE |
| `test-event-review-queue.php` | 34 passed, 0 failed | WordPress/DB-dependent | FAILED — ALL PRIVILEGES on shared schema | NO |
| `test-motorsport-ireland-importer.php` | 89 passed, 0 failed (reverted to baseline) | Pure/static | N/A — NO DB ACCESS | YES — DOES NOT REQUIRE THE ISOLATION GATE |

### Regression suites (affected by Stage 4 changes)

| Suite | Result | test_class | credential_isolation_status | qualifying_under_gate |
|---|---|---|---|---|
| `test-ivvcc-importer.php` | 46 passed, 0 failed | Pure/static | N/A — NO DB ACCESS | YES — DOES NOT REQUIRE THE ISOLATION GATE |
| `test-mondello-park-importer.php` | 142 passed, 0 failed | Pure/static | N/A — NO DB ACCESS | YES — DOES NOT REQUIRE THE ISOLATION GATE |
| `test-partial-enrichment-preservation.php` | 42 passed, 0 failed | WordPress/DB-dependent | FAILED — ALL PRIVILEGES on shared schema | NO |
| `test-import-history-dashboard.php` | 157 passed, 0 failed | WordPress/DB-dependent | FAILED — ALL PRIVILEGES on shared schema | NO |
| `test-past-event-filter.php` | 29 passed, 0 failed | WordPress/DB-dependent | FAILED — ALL PRIVILEGES on shared schema | NO |
| `test-event-cleanup-purge.php` | 749 passed, 0 failed | WordPress/DB-dependent | FAILED — ALL PRIVILEGES on shared schema | NO |

### Baseline comparison

The Stage 3 baseline references were:

- `test-import-continuation`: 262 passed / 22 failed (pre-existing).
- `test-town-sanitization`: 1 pre-existing failure.
- `test-source-fetch-failure`: 136 passed / 0 failed after Stage 3's fix.

These are historical comparison points. The Stage 4 targeted suites above all
pass with 0 failures. The pre-existing `test-import-continuation` and
`test-town-sanitization` failures were not re-run in this targeted set (they
are unrelated to Stage 4 changes and are explicitly out of scope per the task
rules).

## 3. Gates

### Script-contract gate

```
python3 tests/scripts/verify-script-conventions.py
→ 779 passed, 0 failed
```

### Registry drift gate

```
php scripts/generate-registry-docs.php --check
→ registry OK: 11 plugins validated, 20 generated regions current (zero writes).
```

### Lint (PHP syntax + PHPCS + PHPStan)

```
./scripts/lint.sh
→ OK: no PHP parse errors.
→ PHPCS legacy baseline: 2836 errors / 2374 warnings in 247 files (baseline: 3290 / 2672).
→ OK: no new PHPCS violations against the baseline.
→ PHPStan: [OK] No errors
→ lint: OK (syntax clean, no new PHPCS violations, PHPStan clean)
```

### Permanent invariant gates (Stage L)

```
python3 scripts/verify-permanent-gates.py
→ AGGREGATE: FAIL
```

The permanent gates report 12 failures, but the WordPress-dependent gates
(`taxonomy_policy`, `translation_completeness`, `cache_scoping`,
`redirect_precedence`, and the `commemorative_*` gates) fail because they
need the isolated DB env and were run without it. The only static gate
failure is `i18n_freshness`:

- 3 pre-existing stale catalogues (`conexao-br-irlanda`, `conexao-admin-ux`,
  `conexao-event-importer`) — all in the baseline.
- 1 "new" stale catalogue (`conexao-commemorative`) — **unrelated to Stage 4**
  (the commemorative plugin's `class-conexao-commemorative-admin.php` is newer
  than its POT; Stage 4 does not touch the commemorative plugin).

The `conexao-event-importer` POT was regenerated
(`./scripts/i18n-make-pot.sh conexao-event-importer`) to include the new
Stage 4 translatable strings. The `conexao-commemorative` stale catalogue is
pre-existing debt outside Stage 4's scope.

**Prior claim (superseded):** The original Stage 4 execution reported the
permanent gates as `FAIL` with 12 WordPress-dependent failures and 1 new
static failure (`i18n_freshness`).

**Correction:** The static gates were rerun individually:

- `cache_scoping_static`: 2 passed, 0 failed (PASS)
- `documentation_drift`: 17 passed, 0 failed (PASS)
- `i18n_freshness`: 3 passed, 4 failed (FAIL — 3 pre-existing, 1 new
  `conexao-commemorative` unrelated to Stage 4)
- `commemorative_ambient_runtime`: 104 passed, 0 failed (PASS)
- `script-conventions`: 779 passed, 0 failed (PASS)
- `registry-drift`: 11 plugins validated, 20 generated regions current (PASS)
- `lint`: syntax clean, no new PHPCS violations, PHPStan clean (PASS)

The WordPress-dependent gates were NOT rerun under the safety gate.

### Operator-log isolation gate

Not run. The operator-log isolation gate is part of the Stage 0.5 suite and
requires the isolated DB env. It was not run because the Stage 4 changes do
not touch the operator-log path, and the gate's effective database target
(the isolated test DB) is proven by the preflight above.

## 4. Tests not run

- The full `./scripts/run-tests.sh` (all 150 suites) was not run to
  completion: the importer suite alone (45 suites) exceeds the 400s timeout
  in this environment. The targeted affected suites above cover every suite
  that Stage 4 changes could affect.
- The HTTP acceptance layer was not run (no Stage 4 change affects HTTP
  routes or acceptance endpoints).
- The browser test layer was not run (no Stage 4 change affects browser
  behaviour).

## 5. P9 parser tests — no HTTP requests

The MI parser test (`test-motorsport-ireland-importer.php`) uses inline
deterministic fixtures and the retained `mi-listing.html` snapshot. It makes
no HTTP requests. The IVVCC and Mondello Park parser tests likewise use
inline fixtures and make no HTTP requests.

## 6. Review queue — read-only confirmation

The review queue test (`test-event-review-queue.php`) snapshots the publish
count, the `conexao_event_sources` option and a sample post's venue meta
before and after rendering, and asserts they are unchanged. The view is
read-only.

## 7. Datetime tests — write boundary

The datetime contract test (`test-event-date-contract.php`) exercises the
actual write boundary (`Conexao_Event_Date_Contract::date_only()`) and proves
no datetime-shaped value is persisted. The seeder test asserts the seeder's
`_event_date` expression yields date-only.

## 8. Preservation

No preservation-cohort record or shared-site data was modified. See
`07-preservation-confirmation.md`.

## 9. Post-isolation-fix verification (2026-11-16)

### 9.1 Isolation proof

A schema-restricted test account `conexao_test`@`172.18.%` was created with
the explicit privilege set on `conexao_stage05_test_5bd9ece5c032` only
(plus implicit `USAGE ON *.*`). The redacted `SHOW GRANTS` output:

```
GRANT USAGE ON *.* TO `conexao_test`@`172.18.%`
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, REFERENCES, INDEX, ALTER,
  CREATE TEMPORARY TABLES, LOCK TABLES, EXECUTE, CREATE VIEW, SHOW VIEW,
  CREATE ROUTINE, ALTER ROUTINE, TRIGGER
  ON `conexao_stage05_test_5bd9ece5c032`.* TO `conexao_test`@`172.18.%`
```

The five empirical checks (§7) were run from a query container on the
`wordpress-website_default` Docker network (172.18.0.0/16):

| Check | Result |
|---|---|
| 7.1 Connection identity | `USER()` = `conexao_test@172.18.0.4`, `CURRENT_USER()` = `conexao_test@172.18.%`, `DATABASE()` = `conexao_stage05_test_5bd9ece5c032` |
| 7.2 Positive read (test schema) | `SELECT COUNT(*) FROM wp_posts` → 8749 (succeeded) |
| 7.3 Negative read (shared schema) | `ERROR 1142 (42000): SELECT command denied to user 'conexao_test'@'172.18.0.4' for table 'wp_posts'` (permission error) |
| 7.4 Negative write (shared schema) | `ERROR 1142 (42000): UPDATE command denied to user 'conexao_test'@'172.18.0.4' for table 'wp_posts'` (permission error) |
| 7.5 Positive DDL probe (test schema) | `CREATE TABLE zz_isolation_probe_<random>` and `DROP TABLE` both succeeded; leftover count = 0 |

The actual PHP test connection (mysqli, from the `wordpress` container) was
verified: `USER()` = `conexao_test@172.18.0.3`, `CURRENT_USER()` =
`conexao_test@172.18.%`, `DATABASE()` = `conexao_stage05_test_5bd9ece5c032`.
No test-time connection to the shared `wordpress` schema was opened.

**Isolation proof: PASSED.**

### 9.2 Fail-closed bootstrap preflight

With `CONEXAO_TEST_DB_USER` and `CONEXAO_TEST_DB_PASSWORD` unset, the
bootstrap preflight exits non-zero before any database connection:

```
conexao-test-bootstrap: CONEXAO_TEST_DB_USER is required for the isolated test connection.
exit code: 1
```

The `.env` file (mode 0600, git-ignored, untracked) holds the two test
credentials. `.env.example` carries empty placeholders. The normal WordPress
stack's `wp-config.php`, `compose.yaml` and `WORDPRESS_DB_*` configuration are
unchanged.

### 9.3 Required test-suite rerun

All twelve required suites were run sequentially under the restricted
credential (`conexao_test`@`172.18.%`, database
`conexao_stage05_test_5bd9ece5c032`). Every suite's preflight reported
`ISOLATED_DB_PREFLIGHT=PASS database=conexao_stage05_test_5bd9ece5c032 host=db`.

| Suite | Prior Stage 4 count | New run count | Baseline comparison |
|---|---|---|---|
| `test-event-review-queue.php` | 34 passed, 0 failed | 36 passed, 2 failed | **NEW FAILURE** — D2 multi-page drill-in: "no duplicate IDs across pages" (expected 431, got 429) and "union of all pages equals full venue count" (expected 459, got 431). The prior Stage 4 run reported 34/0; the new run reports 36/2. The two new assertions (D2) were not in the prior run's count. |
| `test-partial-enrichment-preservation.php` | 42 passed, 0 failed | 42 passed, 0 failed | BASELINE-CONSISTENT |
| `test-import-history-dashboard.php` | 157 passed, 0 failed | 157 passed, 0 failed | BASELINE-CONSISTENT |
| `test-past-event-filter.php` | 29 passed, 0 failed | 29 passed, 0 failed | BASELINE-CONSISTENT |
| `test-event-cleanup-purge.php` | 749 passed, 0 failed | 749 passed, 0 failed | BASELINE-CONSISTENT |
| `test-import-continuation.php` | 262 passed, 22 failed | 262 passed, 22 failed | BASELINE-CONSISTENT (pre-existing failures unchanged) |
| `test-town-sanitization.php` | 1 pre-existing failure | 82 passed, 1 failed | BASELINE-CONSISTENT (pre-existing failure unchanged) |
| `test-source-fetch-failure.php` | 136 passed, 0 failed | 136 passed, 0 failed | BASELINE-CONSISTENT |
| `test-eventbrite-rate-limit.php` | not in prior Stage 4 targeted set | 87 passed, 0 failed | No prior Stage 4 count; passes. |
| `test-stage1-run-coordination.php` | 47 passed, 0 failed (Stage 1/3 baseline) | 44 passed, 3 failed | **NEW FAILURE** — P2 Heritage date gate: "current, future, missing-date, and ambiguous-range listings remain eligible" (expected 4, got 3), "detail fetch count excludes the definitely past event" (expected 4, got 3), "successful empty detail response remains distinguishable" (expected 'empty', got null). The P2 fixture hardcodes "Today event" = 15 November 2026; today is 16 November 2026, so the "today" event is now past and correctly excluded by the date gate. This is a date-dependent fixture failure, not a credential or Stage 4 code regression. |
| `test-eventbrite-checkpoint.php` | not in prior Stage 4 targeted set | 91 passed, 0 failed | No prior Stage 4 count; passes. |
| `test-operator-log-isolation.php` (Stage 0.5 gate) | not run in prior Stage 4 | 366 passed, 0 failed | Ran under restricted credential; passes. |

**Required test rerun: FAILED** — two suites show failures that differ from
the prior Stage 4 baseline:

1. `test-event-review-queue.php`: 2 new D2 failures (multi-page drill-in
   consistency). The prior Stage 4 run reported 34/0; the new run reports
   36/2. The D2 assertions are a new failure.
2. `test-stage1-run-coordination.php`: 3 P2 failures (Heritage date gate).
   The Stage 1/3 baseline was 47/0; the new run reports 44/3. The failures
   are caused by a date-dependent fixture ("Today event" = 15 November 2026)
   that is now in the past relative to the current date (16 November 2026).

### 9.4 Operator-log isolation verification

The Stage 0.5 operator-log isolation gate (`test-operator-log-isolation.php`)
ran under the restricted credential and passed: 366 passed, 0 failed. The gate
reads `{$wpdb->options}` (the test schema) to verify live operator options stay
byte-identical; it does not read the shared `wordpress` schema, so it is
compatible with the restricted credential.

**Operator-log isolation verification: PASSED.**

### 9.5 CI provisioning dependency

The CI integration job (`.github/workflows/ci.yml`, line 640) runs the PHP
suites with `CONEXAO_TEST_DB_NAME=wordpress` and the `wordpress` DB
credentials. The fail-closed bootstrap change requires `CONEXAO_TEST_DB_USER`
and `CONEXAO_TEST_DB_PASSWORD` to be set; CI does not currently provision
them. CI test runs will fail until the CI environment is updated to
provision the restricted test credential. CI update is out of scope for this
task and should be tracked as a follow-up.

### 9.6 Gate outcomes

| Gate | Status | Reason |
|---|---|---|
| Isolation proof | `PASSED` | Restricted account verified; positive/negative checks all correct; actual PHP connection is `conexao_test@172.18.%` on the test schema. |
| Required test rerun | `FAILED` | `test-event-review-queue.php` (2 new D2 failures) and `test-stage1-run-coordination.php` (3 date-dependent P2 failures) differ from the prior baseline. |
| Operator-log isolation verification | `PASSED` | Gate ran under restricted credential (366/0). |
| Overall test/safety gate | `FAILED` | Required test rerun is `FAILED` (new/changed failures appeared). |
---

## Post-isolation-remediation-and-closeout verification (2026-11-16)

### Prior test counts — non-qualifying

All prior WordPress/DB-dependent test counts from this Stage 4 execution are
**non-qualifying evidence** because the actual `$wpdb` connection identity for
those runs was not captured and proven, and the transcript recorded
long-running queries under the over-privileged `wordpress` MySQL account
during those runs. Those observations are insufficient to establish that the
restricted account was used, so these counts are non-qualifying for the
isolation gate. They remain informative for debugging but must not be treated
as evidence of isolation-compliant test execution.

| Prior count | Suite | Reason |
|---|---|---|
| 36/2 | test-event-review-queue.php | Actual `$wpdb` identity not captured; `wordpress` queries observed. |
| 42/0 | test-partial-enrichment-preservation.php | Same. |
| 157/0 | test-import-history-dashboard.php | Same. |
| 29/0 | test-past-event-filter.php | Same. |
| 749/0 | test-event-cleanup-purge.php | Same. |
| 262/22 | test-import-continuation.php | Same. |
| 82/1 | test-town-sanitization.php | Same. |
| 136/0 | test-source-fetch-failure.php | Same. |
| 87/0 | test-eventbrite-rate-limit.php | Same. |
| 44/3 | test-stage1-run-coordination.php | Same. |
| 91/0 | test-eventbrite-checkpoint.php | Same. |
| 366/0 | test-operator-log-isolation.php | Same. |

### Grant compliance

**Before:** `conexao_test`@`172.18.%` had SELECT, INSERT, UPDATE, DELETE,
CREATE, DROP, REFERENCES, INDEX, ALTER, CREATE TEMPORARY TABLES, LOCK TABLES,
EXECUTE, CREATE VIEW, SHOW VIEW, CREATE ROUTINE, ALTER ROUTINE, TRIGGER on
`conexao_stage05_test_5bd9ece5c032`.*

**After:** EXECUTE, CREATE VIEW, SHOW VIEW, CREATE ROUTINE, ALTER ROUTINE,
TRIGGER revoked. The effective grants are now exactly the eleven authorised
privileges plus implicit `USAGE`.

**Grant compliance: PASSED.**

### Password handling

The `conexao_test` password was rotated via `ALTER USER` using a protected
temporary SQL file (mode 0600). The new credentials are stored in
`.conexao-test.env` (mode 0600, git-ignored). The password does not appear in
any command argument, Docker `-e` value, log, or report. The full `.env` was
not sourced inside the WordPress container.

**Password handling: PASSED.**

### `wp-config.php` environment-overridability

`wp-config.php` resolves `DB_NAME`, `DB_USER`, `DB_PASSWORD` and `DB_HOST`
from `WORDPRESS_DB_*` environment variables via `getenv_docker()`. No
constants are hardcoded. `wp-config.php` was not modified.

**`wp-config.php` environment-overridability: CONFIRMED — WORDPRESS_DB_* ENV-READ.**

### Pre-WordPress environment setup

The runner copies `.conexao-test.env` into the container at
`/var/www/html/scripts/.conexao-test.env` (mode 0600) and invokes
`scripts/conexao-test-exec.sh` via `docker compose exec`. The wrapper sources
the secret file, validates the values, exports `WORDPRESS_DB_*` and
`CONEXAO_TEST_DB_NAME`, and `exec`s PHP. The host-side command line contains
only the secret-file path and the wrapper invocation — never the password
value. No `set -x`, no debug echoing, no `env`/`printenv` of secret
variables, no argv secret-value forwarding.

### Actual `$wpdb` identity

Every required suite ran with the identity guard active. The guard queries
`USER()`, `CURRENT_USER()` and `DATABASE()` through the actual `$wpdb`
instance after WordPress loads and before any suite assertion:

```
WPDB_IDENTITY=PASS login_user=conexao_test@172.18.0.3 matched_account=conexao_test@172.18.% selected_database=conexao_stage05_test_5bd9ece5c032
```

**Actual `$wpdb` isolation: PASSED.**

### Fail-closed test

With the secret file absent, the wrapper exits before launching PHP:

```
conexao-test-exec: secret file not found: /var/www/html/scripts/.conexao-test.env
exit: 1
```

No database connection is opened.

**Fail-closed: PASSED.**

### Isolation checks

| Check | Result |
|---|---|
| Positive read (disposable schema) | PASS |
| Negative read (`wordpress.wp_posts`) | DENIED |
| Negative write (`wordpress.wp_posts`) | DENIED |
| DDL probe (CREATE/INSERT/UPDATE/DELETE/DROP) | PASS |
| Probe table cleanup | PASS |

### Required suite rerun

All twelve required suites ran sequentially under the verified `$wpdb`
isolation with the identity guard active:

| Suite | Result | `$wpdb` identity |
|---|---|---|
| test-event-review-queue.php | 38 passed, 0 failed | conexao_test / disposable schema |
| test-partial-enrichment-preservation.php | 42 passed, 0 failed | conexao_test / disposable schema |
| test-import-history-dashboard.php | 157 passed, 0 failed | conexao_test / disposable schema |
| test-past-event-filter.php | 29 passed, 0 failed | conexao_test / disposable schema |
| test-event-cleanup-purge.php | 749 passed, 0 failed | conexao_test / disposable schema |
| test-import-continuation.php | 262 passed, 22 failed | conexao_test / disposable schema |
| test-town-sanitization.php | 82 passed, 1 failed | conexao_test / disposable schema |
| test-source-fetch-failure.php | 136 passed, 0 failed | conexao_test / disposable schema |
| test-eventbrite-rate-limit.php | 87 passed, 0 failed | conexao_test / disposable schema |
| test-stage1-run-coordination.php | 47 passed, 0 failed | conexao_test / disposable schema |
| test-eventbrite-checkpoint.php | 91 passed, 0 failed | conexao_test / disposable schema |
| test-operator-log-isolation.php | 376 passed, 0 failed | conexao_test / disposable schema |

**Baseline comparison:**

- `test-import-continuation.php`: 262/22 — BASELINE-CONSISTENT (G18.1/G18.3
  checkpoint failures unchanged).
- `test-town-sanitization.php`: 82/1 — BASELINE-CONSISTENT (Eircode-
  contaminated terms failure unchanged).
- All other suites: BASELINE-CONSISTENT (all passing, matching or exceeding
  prior counts).

**Required suite rerun: BASELINE-CONSISTENT.**

### Menu placement

The Needs Review submenu (`conexao-event-needs-review`) is registered under
the top-level `conexao-event-import` (Event Import) menu, as a sibling of the
Event Sources submenu (`conexao-event-sources`). There is no distinct
"Event Sources" top-level menu to use as parent. WordPress submenu pages
cannot be parents of other submenus. Creating a new top-level menu is
prohibited by the task scope.

**Menu placement: AMBIGUOUS — OWNER DECISION REQUIRED.**

### D2 diagnosis and fix

See `10-d2-diagnosis.md` for the full diagnosis.

**Classification: IMPLEMENTATION DEFECT — FIXED.**

The `field_counts()` method used direct SQL over all published events, while
`query_population()` used `WP_Query` which applies the event runtime's
`pre_get_posts` filter (`_event_status = published` OR no status) and
Polylang's language filter. This caused the count and list to diverge.

**Fix:** `field_counts()` now resolves the population through a single
`WP_Query` with `posts_per_page => -1` (same filters as `query_population()`),
then counts per-field over that exact set of IDs. The D2 test was also
corrected to recompute `field_counts()` after creating its multipage fixtures.

**D2 post-fix suite: PASSED (38 passed, 0 failed).**

### Stage 1 fixture hardening

The P2 fixture dates in `test-stage1-run-coordination.php` were changed from
hard-coded calendar dates (14/15/16 November 2026) to dates relative to the
current site-local date (`wp_timezone()`), formatted as "DD Month YYYY" to
match the Heritage Week source's date parser.

**Stage 1 fixture hardening: FIXED — SUITE PASSES (47 passed, 0 failed).**

### Focused lint/static gate results

| File | Check | Result |
|---|---|---|
| tests/bootstrap.php | `php -l` | No syntax errors |
| tests/lib/isolated-db.php | `php -l` | No syntax errors |
| tests/lib/isolation-proof.php | `php -l` | No syntax errors |
| class-conexao-event-review-queue.php | `php -l` | No syntax errors |
| test-event-review-queue.php | `php -l` | No syntax errors |
| test-stage1-run-coordination.php | `php -l` | No syntax errors |
| scripts/run-tests.sh | `bash -n` | Syntax OK |
| scripts/conexao-test-exec.sh | `bash -n` | Syntax OK |

### Gate outcomes

| Gate | Status |
|---|---|
| Grant compliance | PASSED |
| Password handling | PASSED |
| `wp-config.php` environment-overridability | CONFIRMED — WORDPRESS_DB_* ENV-READ |
| Actual `$wpdb` isolation | PASSED |
| Operator-log isolation verification | PASSED |
| Required suite rerun | BASELINE-CONSISTENT |
| Overall test/safety gate | PASSED |

---

## Stage 4 closeout finalisation (2026-11-16)

### Repository baseline and scope

- Branch: `event-import-improvements`.
- HEAD: `7f6850f` (`Add Stage 3.6 harness for Eventbrite discovery and
  enumeration`).
- Starting worktree was dirty with the prior Stage 4 implementation/evidence
  and isolation work. Starting SHA-256 snapshots were taken outside the
  repository for all target evidence/report/code files; no secret file was
  copied or inspected.
- `docs/evidence/2026-11-15-stage-3-6/` remained unchanged.
- The current review-queue class/test and Stage 1 test were inspected exactly as
  present. No D2 cleanup was required; no application code changed.

### Isolation re-verification

Fresh `SHOW GRANTS`, wrapper/file checks, `$wpdb` identity, 9-probe isolation
proof and fail-closed missing-secret probe all passed. The identity was
`conexao_test@172.18.%` on `conexao_stage05_test_5bd9ece5c032` for every suite.
The credential exposure assessment is recorded in
`09-test-isolation-infrastructure.md`: a prior candidate appeared in execution
history, but exposure of the active rotated credential is not established.

### D2 and Stage 1 code checks

- `suppress_filters` residue: absent; no removal made.
- Explicit `_event_status` filter residue in the review-queue class: absent; no
  removal made.
- Other review-queue code: unchanged.
- Stage 1 helper: already present, uses one cached `DateTimeImmutable('today',
  wp_timezone())`, relative offsets for the existing P2 boundary fixtures, and
  parser-compatible `j F Y`. No restyle or assertion change was made.
- PHP syntax: no errors for the review-queue class, review-queue test and Stage
  1 test. `bash -n` passed for `scripts/conexao-test-exec.sh`.
- Focused Stage 1 result: `WPDB_IDENTITY=PASS`; `47 passed, 0 failed`; exit `0`.

### Fresh twelve-suite controlled rerun

All suites ran sequentially through the existing wrapper. Every suite emitted
the same identity line; no suite timed out or failed identity preflight.

| Suite | Identity | Pass / fail | Exit | Duration | Baseline comparison |
|---|---|---:|---:|---:|---|
| `test-event-review-queue.php` | PASS | 38 / 0 | 0 | 522s | Fresh pass; prior contradictory D2 outcomes preserved; D2 unresolved |
| `test-partial-enrichment-preservation.php` | PASS | 42 / 0 | 0 | 12s | Historical 42/0 |
| `test-import-history-dashboard.php` | PASS | 157 / 0 | 0 | 17s | Historical 157/0 |
| `test-past-event-filter.php` | PASS | 29 / 0 | 0 | 1s | Historical 29/0 |
| `test-event-cleanup-purge.php` | PASS | 749 / 0 | 0 | 178s | Historical 749/0 |
| `test-import-continuation.php` | PASS | 262 / 22 | 1 | 232s | Baseline-consistent: G18.1/G18.3/G18.10/G18.14, same 262/22 |
| `test-town-sanitization.php` | PASS | 82 / 1 | 1 | 1s | Baseline-consistent: same Eircode/non-town assertion |
| `test-source-fetch-failure.php` | PASS | 136 / 0 | 0 | 6s | Historical 136/0 |
| `test-eventbrite-rate-limit.php` | PASS | 87 / 0 | 0 | 4s | Historical 87/0 |
| `test-stage1-run-coordination.php` | PASS | 47 / 0 | 0 | 2s | Stage 1 fixture baseline 47/0 |
| `test-eventbrite-checkpoint.php` | PASS | 91 / 0 | 0 | 21s | Historical 91/0 |
| `test-operator-log-isolation.php` | PASS | 376 / 0 | 0 | 8s | Historical fresh restricted run 376/0 |

Aggregate: **2,096 passed, 23 failed across 12 suites**. The two failures match
the documented baseline by assertion signature; there were no new failures and
no incomplete suite. Therefore the twelve-suite gate is **BASELINE-CONSISTENT**.
The review-queue result is `PASSED FOR THIS RUN`, not D2 resolution.

### Test-schema drift observation

The same read-only direct-SQL definitions were used before and after the run:

```sql
SELECT COUNT(*) FROM wp_posts
WHERE post_type = 'event' AND post_status = 'publish';

SELECT COUNT(*)
FROM wp_posts p
LEFT JOIN wp_postmeta v
  ON (p.ID = v.post_id AND v.meta_key = '_event_venue')
WHERE p.post_type = 'event'
  AND p.post_status = 'publish'
  AND (v.meta_value IS NULL OR TRIM(v.meta_value) = '');
```

Both queries have no `_event_status` predicate and no language scope. Pre-run:
`3510` published events and `457` venue-missing. Post-run: `3510` and `457`;
delta `0/0`. The published-event count is within 5% of the prior same-definition
`3568` observation. The venue-missing count differs from the prior
same-definition `511` observation by about `10.6%`: **MATERIAL TEST-SCHEMA
DRIFT**. The older `484–511` range is not treated as one interchangeable
baseline because it reflects differing `_event_status` populations. No cause is
inferred and no residue was manually cleaned.

### Gate status

| Gate | Status |
|---|---|
| Isolation grants/file/guard/proof | PASSED |
| `$wpdb` identity | PASSED for all 12 suites |
| Stage 1 fixture hardening | FIXED — SUITE PASSES |
| PHP syntax checks | PASSED |
| D2 latest suite outcome | PASSED FOR THIS RUN (38/0) |
| D2 diagnosis/design resolution | INCONCLUSIVE — UNRESOLVED |
| Menu placement | AMBIGUOUS — OWNER DECISION REQUIRED |
| Test-schema drift observation | MATERIAL TEST-SCHEMA DRIFT (venue-missing same-definition count) |
| Twelve-suite rerun | BASELINE-CONSISTENT |
| Stage 4 programme verdict | BLOCKED — D2 UNRESOLVED AND MENU PLACEMENT AMBIGUOUS |
