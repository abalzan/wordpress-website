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

### The release suites (Stage J)

The release contract has its own coverage on both sides of the deployment line,
so a release gate is never exercised for the first time at release time:

| Suite | Proves | Layer |
|---|---|---|
| `tests/scripts/verify-release-integrity.py` | The two release invariants: `plugins.json` derives the artifact allowlist, and `release.json` records exactly what was built. Builds the real artifacts **twice** and proves they are byte-identical, proves no `tests/`/`fixtures/`/`*.json` report ships, and **proves the verifier fails on a tampered artifact** and on a non-allowlisted one. | Script contract (no WordPress, no network) |
| `tests/acceptance/verify-release-http.py` | The **fixed** release smoke matrix against the local site, plus one single per post type and the PT/EN language-layer checks. It imports the same matrix file and the same check functions as `scripts/verify-deploy.py`, so the gate and the regression suite cannot drift. | HTTP acceptance |

### The governance suite (Stage K)

| Suite | Proves | Layer |
|---|---|---|
| `tests/scripts/verify-agent-governance.py` | The agent-governance contract of engineering standard §13: the WordPress skills exist under `.agents/skills/`, each has `SKILL.md` with the six required sections in order, every repository path a skill references actually exists, no obsolete Dart/Flutter/mobile skill sits in the active namespace, no second plugin registry was introduced, `AGENTS.md` points at the standard and the templates, the PR template carries the standard's checks, the documentation index exposes the workflow, and no governance file hard-codes a local URL, a credential or an external path. Static, no WordPress, no network, zero writes. | Script contract (no WordPress, no network) |

It is discovered by convention (`tests/scripts/verify-*.py`), so it is blocking
in CI with no workflow change. Run it alone with
`python3 tests/scripts/verify-agent-governance.py`.

### The permanent invariant gates (Stage L)

Engineering standard §6.3 makes four invariants permanent, and §9.3 adds the
i18n freshness check. Stage L turns them into **standing, fail-closed gates**
that run in the default suite. They are ordinary suites — discovered by the
same conventions, no special runner, no hand-maintained list.

| Gate | Proves | Suite | Layer |
|---|---|---|---|
| Taxonomy policy | `conexao_county`/`conexao_town` are shared: no per-language suffixed duplicates, no duplicate slugs, no language tag. `conexao_category`/`conexao_tag` are translated and every pair is linked **both ways**. The shared/translated split is read from the runtime (`conexao_polylang_translated_taxonomies()` + `PLL()->model`), never re-declared in the test. | `test-taxonomy-policy.php` | In-process PHP |
| Translation completeness | `eligible public PT <type> missing EN = 0` for every public content type discovered at runtime. B2 fallback types (`conexao_b2_post_types()`) and the B2 page allowlist (`conexao_b2_page_allowlist()`) are exempt **by documented policy** and are counted and reported. Malformed/one-way links are counted separately. | `test-translation-completeness.php` | In-process PHP |
| Language-scoped caching | (a) **static**: a `token_get_all` scan of production source finds no unscoped `conexao_*` transient/object-cache key; (b) **runtime**: PT and EN keys differ, an EN read never returns the PT value, and `conexao_flush_language_cache()` clears every language variant. | `verify-cache-key-scoping.py`, `test-cache-language-scoping.php` | Script contract + in-process PHP |
| Legacy EN→PT redirect precedence | A legacy EN path keeps its **301** to the **PT** destination even when a newly created EN page has the same slug, and the legacy table still runs before Polylang's canonical. The conflicting page is created and deleted inside the suite. | `test-redirect-precedence.php` | In-process PHP |
| Documentation drift | Orchestrates the Stage G/I/K gates (it does not reimplement them) and owns the §9.2 rules: `_Last verified:` markers on the living reference set, no root-level report/evidence file, `AGENTS.md` stays orientation, the authoritative sources still exist. | `verify-documentation-drift.py` | Script contract |
| i18n catalogue freshness | A `.pot` is never older than the PHP defining its strings. Signal: **git commit time**, not mtime (a fresh clone gives every file the checkout time, so mtime cannot express this rule). | `verify-i18n-freshness.py`, `scripts/i18n-check.sh` | Script contract |

Run them all and get one machine-readable summary:

```bash
python3 scripts/verify-permanent-gates.py            # runs every gate, writes gate.json
python3 scripts/verify-permanent-gates.py --list     # list the gates
./scripts/i18n-check.sh                              # §9.3's documented entry point
```

#### Repairing a failed gate

A gate reports a violation; repairing it means fixing the **data or the tool
that produced it**, never the gate. Each debt class has one documented remedy:

| Gate failure | Root cause to check first | Repair |
|---|---|---|
| `taxonomy:*:language_tagged_terms` | a seed/import that assigns a language to a shared taxonomy — today the seed is already correct, so a failure means something re-tagged the term | `php scripts/remediate-shared-taxonomy-language.php --dry-run` then `--apply` |
| `taxonomy:*:suffixed_duplicate_terms` | a `dublin-en`-style duplicate exists | remove the duplicate; never create the translated term |
| `post_type:*:missing_en` | the EN translation was never authored | `php scripts/run-en-translation.php --dry-run --only=<type>`, add the row to the stage manifest, then `--apply`. `post` is now complete (34 → 0 in Stage N); the Blog rows live in `includes/blog-translation-data.php` |
| `post_type:*:malformed_relationships` | an EN record with no PT master | `php scripts/remove-en-orphan-fixtures.php --dry-run`; a genuine orphan is a maintainer decision, the script only removes provable stage fixtures |
| `i18n:*:stale_catalogue` | a gettext-bearing source is newer than its `.pot` | `./scripts/i18n-make-pot.sh --apply` (regenerate; never hand-edit a catalogue) |

The last two rows matter: `i18n-make-pot.sh` is the **generator** that
`i18n-check.sh` was written to police. Before it existed the repository had a
freshness check with no remedy, so every source edit silently made a catalogue
stale and the only visible fix was a hand-edit. The generator is what stops the
debt recurring.

EN translations are produced through the **shared**
[`conexao-translation-rollout`](../plugins/conexao-translation-rollout.md) engine
via the [`conexao-en-translation`](../plugins/conexao-en-translation.md) stage.
There is one engine and one authored manifest; a stage is configuration, not a
second implementation.

#### Reading a failed gate

A permanent gate **fails closed**: a real violation exits non-zero, and a
missing prerequisite prints `insufficient data:` and also fails. A gate is never
downgraded to a warning, never skipped, and never satisfied by an allowlist.

Each gate separates **pre-existing debt** from a **new regression**:

- `tests/baseline/permanent-gates.json` records the violations that already
  existed when Stage L started.
- A violation listed there still **fails** and still exits non-zero. The
  baseline only changes the *label* printed in the summary and in `gate.json`
  (`pre_existing` vs `new`). It never suppresses anything.
- `new > 0` means **you** introduced a violation. `new = 0` with `failed > 0`
  means the repository already had the debt and Stage L merely made it visible.

Evidence lands in `docs/evidence/2026-09-26-stage-l/gate.json`, generated from
real execution output by `scripts/verify-permanent-gates.py` — never hand-edited.

`./scripts/verify-release.sh` runs the whole release workflow end to end locally
(registry gate → build → manifest → allowlist/hash verification → determinism →
exclusion proof → HTTP verification). It is the single command to run before
relying on the release machinery, and it never touches production.

See [releases.md](releases.md) for the contract these suites enforce.


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
| `tests/scripts/verify-release-integrity.py` | `SCRIPT_CONTRACT` (Stage J) | yes — blocking, also its own CI job |
| `tests/scripts/verify-agent-governance.py` | `SCRIPT_CONTRACT` (Stage K) | yes — blocking; the agent-governance contract |
| `tests/acceptance/verify-release-http.py` | `HTTP_ACCEPTANCE` (Stage J) | yes — blocking; runs the release smoke matrix |
| `tests/scripts/verify-cache-key-scoping.py` | `SCRIPT_CONTRACT` (Stage L) | yes — blocking; static half of the cache-scoping invariant |
| `tests/scripts/verify-documentation-drift.py` | `SCRIPT_CONTRACT` (Stage L) | yes — blocking; documentation drift |
| `tests/scripts/verify-i18n-freshness.py` | `SCRIPT_CONTRACT` (Stage L) | yes — blocking; i18n catalogue freshness |
| `wp-content/themes/conexao-br-irlanda/tests/test-taxonomy-policy.php` | `PERMANENT_INVARIANT` (Stage L) | yes — blocking; §6.3 taxonomy policy |
| `wp-content/themes/conexao-br-irlanda/tests/test-translation-completeness.php` | `PERMANENT_INVARIANT` (Stage L) | yes — blocking; §6.1 completeness |
| `wp-content/themes/conexao-br-irlanda/tests/test-cache-language-scoping.php` | `PERMANENT_INVARIANT` (Stage L) | yes — blocking; §6.1 cache scoping |
| `wp-content/themes/conexao-br-irlanda/tests/test-redirect-precedence.php` | `PERMANENT_INVARIANT` (Stage L) | yes — blocking; §6.3 redirect precedence |

_Last verified: 2026-09-26 by Stage I — Scripts Standardisation_
_Last verified: 2026-09-26 by Stage J — Build, Release & Deploy Verification_
_Last verified: 2026-09-26 by Stage K — Agent Skills + Templates_
_Last verified: 2026-09-26 by Stage N — Remaining EN Blog Translations_
