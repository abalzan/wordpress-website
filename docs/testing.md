# Testing

The reference for **how to run and write tests** in this repository. The
normative policy is [engineering-standard.md](engineering-standard.md) §8; this
document is the practical companion to it. Where they disagree, the standard
wins.

---

## The three-layer model

| Layer | What it proves | Where it lives | How it runs |
|---|---|---|---|
| **In-process PHP** | Functions, queries, policies, taxonomies, language logic, metadata and cache logic against a **real** WordPress | `wp-content/<kind>/<component>/tests/test-<area>-<behaviour>.php` | one PHP process per suite |
| **Script contract** (Stage I) | The `scripts/` estate obeys its contract: shared bootstrap/REST use, no hard-coded production target, no credentials, catalogue completeness, no stale renamed paths | `tests/scripts/verify-*.py` | one Python process per suite; static, no WordPress, no network |
| **HTTP acceptance** | What a real request returns: status, redirects, canonical, hreflang, sitemap, filters | `tests/acceptance/verify-<area>-http.py` + `tests/acceptance/matrices/*.json` | one Python process per suite |

All three run through **one** command with **one** aggregate exit code:

```bash
./scripts/run-tests.sh
```

"One runner" does **not** mean "one giant test file". The suites stay
independent and each runs in its own OS process, so a failure is reproducible
and isolated, while the developer still gets a single command and a single
verdict.

---

## Commands

| Command | What it does |
|---|---|
| `./scripts/run-tests.sh` | all three layers |
| `./scripts/run-tests.sh --scripts` | the script-contract gate only (static; needs no stack) |
| `./scripts/run-tests.sh --acceptance` | HTTP acceptance layer only |
| `./scripts/run-tests.sh --php` | in-process PHP layer only |
| `./scripts/run-tests.sh --only theme` | only the theme's maintained suites |
| `./scripts/run-tests.sh --only conexao-event-runtime` | only that plugin's suites |
| `./scripts/run-tests.sh --list` | discovered suites, manual suites, components |
| `./scripts/run-tests.sh --help` | usage |

An **unknown** `--only` value is an error, never "run everything":

```
$ ./scripts/run-tests.sh --only bogus
ERROR: unknown test component: bogus
$ echo $?
2
```

### Exit codes

| Situation | Result |
|---|---|
| Everything passes | `ALL TESTS PASSED`, exit **0** |
| Any suite fails | `TESTS FAILED` + the failing suite names, exit **1** |
| A suite times out | `[TIMEOUT]`, exit **1** |
| A data prerequisite is missing | `insufficient data: <hint>`, exit **1** |
| WordPress cannot bootstrap | `[BLOCKED]`, exit **1** |
| HTTP target unreachable | `BLOCKED: HTTP acceptance environment unavailable`, exit **1** |
| A matrix file is malformed | rejected before any request, exit **1** |
| A production base URL is configured | refused, exit **2**, **no request sent** |

An unavailable environment is **not** a pass. The runner never quietly
downgrades to "PHP only".

---

## Local Docker requirement

The in-process suites need a real WordPress. The runner detects where it is and
executes accordingly:

| Where the runner runs | How suites execute |
|---|---|
| On the host, Docker available | `docker compose exec -T wordpress php …` |
| Inside the WordPress container | `php …` directly |
| On CI | `docker compose exec` against the CI-local stack |

`compose.yaml` bind-mounts the repository's `tests/` and `scripts/` into the
container at `/var/www/html/tests` and `/var/www/html/scripts`. That is what
lets a suite reach the shared bootstrap. Both are **development-only** mounts
and are never part of a release ZIP.

```bash
docker compose up -d
./scripts/run-tests.sh
```

---

## The shared bootstrap

**`tests/bootstrap.php` is the only place allowed to locate `wp-load.php`.**

A maintained suite starts with:

```php
require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';
```

(`dirname( __DIR__, 4 )` is correct for both `wp-content/themes/<theme>/tests/`
and `wp-content/plugins/<plugin>/tests/` — the depth is the same, and it
resolves to the repository root on the host and to `/var/www/html` in the
container.)

The bootstrap:

- establishes the repository root and locates WordPress deterministically
  (`CONEXAO_TEST_WP_ROOT` → repository root → `/var/www/html`);
- sets the deterministic CLI request context Polylang needs;
- loads WordPress **exactly once**;
- loads the shared assertion library;
- **fails loudly** with a non-zero exit if WordPress cannot be bootstrapped.

It never connects to production, never mutates content, never runs a
migration, never sends an external request.

> **Documented exception.** `test-nav-language-context-logic.php` is a
> *standalone static-analysis* suite: it parses theme source with
> `token_get_all()` and supplies its own WordPress function stubs, and it
> documents "No WordPress, no database, no network". It therefore requires
> `tests/lib/assertions.php` only, not the WordPress bootstrap. Loading the
> bootstrap there would redeclare its stubs.

---

## The shared assertion API

**`tests/lib/assertions.php` is the only assertion library.** Suites do not
define their own.

| Function | Contract |
|---|---|
| `assert_true( $condition, $message, $detail = '' )` | Passes when `$condition` is **truthy** (legacy semantics: a loose boolean test, not `true === $condition`). |
| `assert_equals( $expected, $actual, $message )` | Passes when `$expected == $actual` (**loose**, as the migrated suites were). Appends `expected X, got Y` on failure. |
| `assert_set( $container, $key, $message )` | Passes when the key/property/offset is present. Uses `array_key_exists` (a present-`null` value counts as set). Works on arrays, `ArrayAccess`, objects and strings. |
| `assert_not_set( $container, $key, $message )` | The inverse of `assert_set()`. |
| `assert_contains( $needle, $haystack, $message )` | Passes when `$needle` is a substring of a string haystack, or a member of an array haystack. |
| `assert_not_contains( $needle, $haystack, $message )` | The inverse of `assert_contains()`. |
| `assert_fail( $message, $detail = '' )` | Records an unconditional failure; returns `false`. |

Plus the harness helpers:

| Function | Purpose |
|---|---|
| `test_section( $title )` | Section header. |
| `test_title( $title )` | Suite title banner. |
| `test_prerequisite_hint( $hint )` | Declares the setup hint for this suite. |
| `test_require( $condition, $hint, $message, $detail )` | Data prerequisite (see below). |
| `test_finish( $label = '' )` | Prints `N passed, M failed` and exits non-zero when `M > 0`. |

### Strictness is not a migration decision

`assert_true()` is truthy and `assert_equals()` is loose **on purpose** — that
is what every pre-Stage-E suite-local helper did. Suites that need identity
already expressed it at the call site (`$expected === $actual` passed as the
condition) and those call sites were left alone. Tightening the shared
defaults would silently change the meaning of existing assertions.

---

## Writing a new in-process test

```php
<?php
/**
 * What this suite proves, and what it depends on.
 *
 * @package conexao-br-irlanda
 */

// Shared Stage E test bootstrap: the only place allowed to locate wp-load.php.
require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

test_section( 'My area' );

// Data prerequisites are explicit and fail loudly.
test_require( function_exists( 'pll_set_post_language' ), 'polylang',
    'Polylang is active in the local environment',
    'docker compose exec wordpress wp --allow-root plugin activate polylang' );

assert_true( function_exists( 'conexao_lang_url' ), 'the resolver helper exists' );
assert_contains( 'Empregos', conexao_lang_url( '/empregos/' ), 'resolves a non-empty path' );

test_finish( 'my-area' );
```

Rules:

- end every suite with `test_finish()` so it prints `N passed, M failed`;
- use the standard file name `test-<area>-<behaviour>.php` so discovery finds it;
- **read-only by default**. If the suite writes, it creates its own fixtures,
  uses unique identifiers, and cleans up everything it created.

---

## Data prerequisites

A suite that depends on data or on a plugin declares that dependency. When the
prerequisite is missing the suite says so and **fails**:

```
insufficient data: seed-leisure
setup hint: run the documented local leisure seed
FAIL: the leisure dataset is present
```

The point is that a test must never pass just because the expected data was
absent and therefore nothing ran. Before Stage E, a missing Polylang or a
missing plugin made a suite print `SKIP` and `exit( 0 )` — a vacuous pass.
Those guards are now `test_require()` prerequisites that fail.

A prerequisite failure is a test failure. The only suites exempt from this are
the ones explicitly classified as manual (below).

---

## Test isolation

Tests are read-only unless the suite says otherwise. A write-capable suite
must:

- create its own fixtures;
- use deterministic, unique identifiers (never a production-looking slug);
- clean up every post, term, option, meta key and file it created;
- not depend on a previous run having cleaned up.

The migrated suites already followed this (they delete their fixtures at the
end). Running the suite twice in a row must give the same result — that is the
repeatability gate, and it is checked.



---

## HTTP acceptance layer

Maintained request-visible coverage lives in `tests/acceptance/`.

```
tests/acceptance/
├── verify-<area>-http.py     # one suite per area
├── matrices/
│   └── <area>.json           # its rows
└── lib/
    ├── http_client.py        # GET, no auto-redirect, local-only safety
    └── matrix.py             # schema validation + matrix runner
```

**Standard library only.** No `requests`, no `pytest`, no browser framework.

### Matrix schema — one format, no variants

```json
{
  "rows": [
    {
      "id": "en-home-canonical",
      "url": "/en/",
      "expect_status": 200,
      "expect_contains": ["rel=\"canonical\" href=\""],
      "expect_absent": ["language-fallback-notice"],
      "note": "the EN home is self-canonical and never shows the B2 notice"
    }
  ]
}
```

| Field | Rule |
|---|---|
| `id` | non-empty string, **unique within the matrix** |
| `url` | **relative** path starting with `/` (absolute URLs are rejected) |
| `expect_status` | integer |
| `expect_contains` | array of non-empty string fragments that must appear |
| `expect_absent` | array of non-empty string fragments that must not appear |
| `note` | non-empty: why this row exists |

Malformed matrices are rejected **before any HTTP request is made** — missing
fields, duplicate ids, absolute URLs, non-integer statuses, non-array fragment
lists and empty notes all fail loudly.

### Redirects

Redirects are **not** followed by default, so a `301/302/307/308` row asserts
the *first* response. The schema deliberately has no headers field, so the few
rows that must assert their redirect **target** use a small suite-level check
against the `Location` header (`verify-routing-http.py`, and the "replaced
master" row in `verify-guides-en-http.py`). This is the documented PHASE 35
exception, and it is the only one.

### Base URL and production safety

| | |
|---|---|
| Variable | `CONEXAO_TEST_BASE_URL` |
| Default | `http://localhost:8080` |
| CI value | `http://localhost:8080` (the Compose port) |
| Blocked | `conexaobr.ie`, `www.conexaobr.ie` |

Safety is enforced twice: `scripts/run-tests.sh` refuses a production base URL
before starting, and `tests/acceptance/lib/http_client.py` refuses again
before opening a socket. A production verifier is **not** a local regression
test; the production tools are classified `PRODUCTION_ONLY` and never run here.

### What lives where

| Path | Class | In the default runner? |
|---|---|---|
| `tests/acceptance/verify-*-http.py` | `ACCEPTANCE_TEST` | yes, under `--acceptance` |
| `wp-content/*/*/tests/test-*.php` | `DEFAULT_TEST` | yes |

---

## Manual, historical and production-only tests

**Not every verification script is a regression suite.** The distinction
matters, and Stage E records it explicitly:

- **Maintained tests** run through the shared runner, one command.
- **Manual tests** need operator choice or an unusual prerequisite. They stay
  documented and are reported by `--list`, but do not run by default. The
  current one is:

  | Suite | Why it is manual |
  |---|---|
  | `conexao-event-runtime/tests/test-plugin-separation.php` | It asserts plugin separation under a *different plugin-activation state* (importer deactivated, then activated). Running it means changing the environment, not testing it. Run it deliberately: `docker compose exec wordpress wp --allow-root plugin deactivate conexao-event-importer`, then `… php …/test-plugin-separation.php no-tooling` (and the `with-tooling` mode with the importer active). |

- **Historical verifiers** (`scripts/stage*-verify.*`, `scripts/c2-*.php`,
  one-off audits and probes) prove a *past* migration or release. They are kept
  intact and are not treated as current regression coverage. A stage
  verification script's existence does **not** mean the runner executes it.

- **Production verifiers** (`scripts/historical/c3-production-http-verify.py`,
  `scripts/historical/c3-production-verify.py`) intentionally target `conexaobr.ie`. They
  are manual operator tools, are never part of CI, and are never redirected
  toward the local site and relabelled as the same test.

---

## Fixtures

Fixtures are development/test-only, live under `tests/fixtures/` (or a
component's `tests/fixtures/`), and are excluded from release ZIPs. The
measured inventory, the size budget and the rules are in
[tests/fixtures/README.md](../tests/fixtures/README.md).

---

## CI

`.github/workflows/ci.yml` has two independent jobs:

```
                 GitHub Actions
                       |
          +------------+------------+
          |                         |
     static-quality            integration
   Stage C/D gates        Docker Compose (ephemeral)
                                 |
                        ./scripts/run-tests.sh
                                 |
                  +--------------+--------------+
                  |                             |
            in-process PHP                 HTTP acceptance
```

- `static-quality` is the Stage D job, **unchanged**: `composer validate`,
  `composer install`, `./scripts/lint.sh`, `shellcheck scripts/*.sh`, and the
  non-blocking raw-debt telemetry.
- `integration` starts the Compose stack, **waits for real readiness** (DB
  healthcheck + an HTTP 200 from the front page, not `sleep 10`), installs
  WordPress and the pinned Polylang, activates the repository theme/plugins,
  and runs `./scripts/run-tests.sh`.
- The integration database is **ephemeral** (`docker compose down -v` on
  cleanup). No developer's volume is reused and no database data is committed.
- No secrets, no write permissions, no `pull_request_target`, no
  `continue-on-error` on the test step, and no production host anywhere.

---

## Adding to the harness

- A new in-process test is just a correctly named file in a component's
  `tests/` directory — the runner discovers it automatically.
- A new acceptance area is a `verify-<area>-http.py` plus its matrix file.
- A new assertion goes into `tests/lib/assertions.php`, not into a suite.

Do not maintain a hand-written list of suite files anywhere: discovery is
convention-based, and CI calls the same command you do.

---


| `wp-content/plugins/conexao-event-runtime/tests/test-plugin-separation.php` | `MANUAL_TEST` | no — see below |
| `scripts/stage*-verify.*`, `scripts/c2-*.php` | `HISTORICAL_VERIFICATION` / `DIAGNOSTIC` | no |
| `scripts/historical/c3-production-*.py` | `PRODUCTION_ONLY` | never |
| `tests/scripts/verify-*.py` | `SCRIPT_CONTRACT` | yes — blocking |

_Last verified: 2026-09-26 by Stage I — Scripts Standardisation_
