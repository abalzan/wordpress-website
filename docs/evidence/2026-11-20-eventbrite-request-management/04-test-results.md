# 04 — Test results

All tests ran through the sanctioned restricted runner
(`scripts/conexao-test-exec.sh`) against the disposable test database. The
`$wpdb` identity was verified before every WordPress-dependent run.

## Test database identity (proven)

```
ISOLATED_DB_PREFLIGHT=PASS database=conexao_stage05_test_5bd9ece5c032 host=db
WPDB_IDENTITY=PASS login_user=conexao_test@172.18.0.3 matched_account=conexao_test@172.18.% selected_database=conexao_stage05_test_5bd9ece5c032
```

- Account: `conexao_test` (restricted).
- Database: `conexao_stage05_test_5bd9ece5c032` (disposable).
- Shared `wordpress` database: **never touched** (the runner refuses it; the
  preflight asserts the test DB differs from the shared DB).

## New test suite

`wp-content/plugins/conexao-event-importer/tests/test-eventbrite-waf-diagnostics.php`

Command (via the sanctioned wrapper):

```
docker compose exec -T ... wordpress bash /var/www/html/scripts/conexao-test-exec.sh \
  php /var/www/html/wp-content/plugins/conexao-event-importer/tests/test-eventbrite-waf-diagnostics.php
```

Result: **32 passed, 0 failed.**

Coverage:
- WAF1: a 405 with `x-amzn-waf-action: captcha` retains `waf_action=captcha`;
  exactly one request sent (405 non-retryable); status exposed.
- WAF2: a 405 with no WAF header stores no `waf_action` key.
- WAF3: an unrecognised value is stored as `unknown`, never verbatim.
- WAF4: a sensitive-looking value (`captcha; session=…; token=…`) is not
  matched against the allowlist, never stored verbatim, and length-limited.
- WAF5: the allowlist match is case-insensitive (`CAPTCHA` → `captcha`).
- WAF6: a non-405 non-retryable status (403) stores no `waf_action`.
- WAF7: 429 handling is unchanged — no `waf_action`, breaker still opens.

## Regression-sensitive suites (unchanged behaviour)

Run individually through the sanctioned wrapper:

| Suite | Result |
|---|---|
| `test-eventbrite-rate-limit.php` | 87 passed, 0 failed |
| `test-request-retry-policy.php` | 59 passed, 0 failed |
| `test-request-budget.php` | 33 passed, 0 failed |
| `test-eventbrite-checkpoint.php` | 91 passed, 0 failed |
| `test-import-log.php` | 65 passed, 0 failed |
| `test-stage1-run-coordination.php` | 47 passed, 0 failed |
| `test-source-fetch-failure.php` | 136 passed, 0 failed |

These cover the 429 cooldown ladder, shared breaker, Retry-After parsing,
request budget, checkpoint resume, import-log sanitisation, Run All coordination
and per-status failure classification. All green — the Candidate E change did
not alter any of them.

## Full importer PHP suite

`./scripts/run-tests.sh --php --only conexao-event-importer`

| Metric | Before (baseline) | After (with Candidate E) |
|---|---|---|
| Suites | 45 total, 43 passed, 2 failed | 46 total, 44 passed, 2 failed |
| Assertions | 4809 passed, 23 failed | 4851 passed, 23 failed |

The +1 suite and +42 passing assertions are the new WAF diagnostics suite. The
**2 failing suites are the same pre-existing baseline failures**, unchanged by
this work:

1. `test-import-continuation.php` — 262 passed, 22 failed. A time-budget walk
   stops at page 2 instead of the expected page 3 (a pre-existing code/test
   mismatch in the G18 time-budget fixture). Reproduced in isolation on clean
   HEAD before any change.
2. `test-town-sanitization.php` — 1 failed. A data-quality assertion on
   `conexao_town` terms (Eircode contamination). Unrelated to the importer's
   request-control code.

Both were confirmed pre-existing by running them in isolation with **no code
changes** (see `git stash` baseline run in the investigation).

## Static quality (lint)

`./scripts/lint.sh` (PHP syntax + PHPCS baseline + PHPStan level 5):

- **PHP syntax:** OK, no parse errors (614 files).
- **PHPStan level 5:** OK, no errors.
- **PHPCS:** one NEW violation reported —
  `PSR2.Methods.FunctionClosingBrace.SpacingBeforeClose` at
  `class-event-sources.php:472`. This is **pre-existing on clean HEAD** (the
  committed file already has a stray blank line before a closing brace; the
  baseline was generated 2026-09-25, before that line was committed). It is
  **not** in a file this task touched. Verified by stashing the Candidate E
  changes and re-running lint: the identical violation is reported on clean HEAD.
- **My changed files:** identical PHPCS counts on HEAD vs with Candidate E
  (21 errors / 27 warnings in the two edited files, all pre-existing). The new
  test file is PHPCS-clean (0 errors, 0 warnings).

## Commands run (exact)

```
# Identity preflight
docker compose cp .conexao-test.env wordpress:/var/www/html/scripts/.conexao-test.env
docker compose exec -T ... wordpress bash /var/www/html/scripts/conexao-test-exec.sh \
  php /var/www/html/tests/lib/isolated-db.php
# → ISOLATED_DB_PREFLIGHT=PASS database=conexao_stage05_test_5bd9ece5c032 host=db

# New suite
docker compose exec -T ... wordpress bash /var/www/html/scripts/conexao-test-exec.sh \
  php /var/www/html/wp-content/plugins/conexao-event-importer/tests/test-eventbrite-waf-diagnostics.php
# → 32 passed, 0 failed

# Regression suites (one per invocation, same wrapper)
# → see table above

# Full importer suite
./scripts/run-tests.sh --php --only conexao-event-importer
# → 46 total, 44 passed, 2 failed (pre-existing)

# Static quality
./scripts/lint.sh
# → PHP syntax OK; PHPStan OK; PHPCS 1 pre-existing violation (class-event-sources.php:472)
```

## Baseline vs post-change failures

| Failure | Pre-existing? | Cause |
|---|---|---|
| `test-import-continuation.php` (22) | Yes | Time-budget fixture stops at page 2 vs expected 3 |
| `test-town-sanitization.php` (1) | Yes | Data-quality assertion on `conexao_town` terms |

No new failures were introduced. No pre-existing failure was concealed or
modified.