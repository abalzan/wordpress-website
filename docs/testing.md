# Testing

The authoritative **testing model** for this repository: the three layers, the
suite classification, the permanent invariant gates, the shared bootstrap and
assertion API, fixtures, the acceptance base URL, the baseline/pre-existing
failure policy and the CI contract. The normative policy is
[engineering-standard.md](engineering-standard.md) §8. Where they disagree, the
standard wins.

> **The procedure lives in the skills.** To *run*, diagnose or compare the test
> contract, and to add a layer, a fixture or a permanent gate, follow
> [`wp-testing`](../.agents/skills/wp-testing/SKILL.md). To *author* a suite, use
> [`wp-write-in-process-test`](../.agents/skills/wp-write-in-process-test/SKILL.md)
> or [`wp-http-acceptance-matrix`](../.agents/skills/wp-http-acceptance-matrix/SKILL.md).
> This document defines what each layer **means**; the skills define the workflow.

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
| `post_type:*:missing_en` | the EN translation was never authored | `php scripts/run-en-translation.php --dry-run --only=<type>`, add the row to the stage manifest, then `--apply`. `post` is now complete (34 → 0 in Stage N); the Blog post rows live in `includes/blog-translation-data.php` and the Blog **posts page** row in `includes/blog-page-data.php` (Stage O, stage `en-blog-page`, run with `--only=blog-page`) |
| `post_type:*:malformed_relationships` | an EN record with no PT master | `php scripts/remove-en-orphan-fixtures.php --dry-run`; a genuine orphan is a maintainer decision, the script only removes provable stage fixtures |
| `i18n:*:stale_catalogue` | a gettext-bearing source is newer than its `.pot` | `./scripts/i18n-make-pot.sh --apply` (regenerate; never hand-edit a catalogue) |

The last two rows matter: `i18n-make-pot.sh` is the **generator** that
`i18n-check.sh` was written to police. Before it existed the repository had a
freshness check with no remedy, so every source edit silently made a catalogue
stale and the only visible fix was a hand-edit. The generator is what stops the
debt recurring.

EN translations are produced through the **shared**
[`conexao-translation-rollout`](plugins/conexao-translation-rollout.md) engine
via the [`conexao-en-translation`](plugins/conexao-en-translation.md) stage.
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

### Script-contract suites build their own artifacts

The script-contract layer is static and needs no WordPress, but two of its
suites must inspect **built** release artifacts:

| Suite | Where it gets them |
|---|---|
| `tests/scripts/verify-stage14-secret-scan.py` | runs `scripts/build-plugins-zip.sh` **and** `scripts/build-theme-zip.sh` into a temporary directory it removes, and scans the 9 plugin ZIPs, the theme ZIP and the emitted release record |
| `scripts/verify-commissioning-readiness.py` (driven by `verify-stage13-commissioning-readiness.py`) | its own double build; the plugin header versions are compared against the release record **that build emits** |

Both deliberately do **not** read the git-ignored `dist/`. `dist/` is untracked
build output: it is absent on a fresh CI checkout, so depending on it made both
gates report a failure on a perfectly clean tree while passing on any developer
machine that happened to have run a build. Building the artifacts in-gate is a
strengthening — the gate always measures what the repository produces right now,
can never be satisfied by a stale or hand-modified directory, and behaves
identically on a laptop and on a fresh CI runner. A build failure is still a hard
failure, and no scan is skipped.

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

`.github/workflows/ci.yml` has independent jobs:

```
                 GitHub Actions
                       |
          +------------+------------+------------+
          |            |            |            |
     static-quality    release-      integration     (all blocking)
   Stage C/D gates    integrity      Docker Compose  (no advisory job,
                     (manifest)     (ephemeral)      no path filters)
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
- `integration` is a **required blocking check** on `push`, `pull_request` and
  `workflow_dispatch` under engineering-standard §1.7. The job starts the
  Compose stack, waits for readiness, installs WordPress and pinned Polylang,
  configures `pt`/`en`, activates the required test plugins, builds the
  deterministic synthetic site from the committed fixtures, and runs
  `./scripts/run-tests.sh`.
- The job was `workflow_dispatch`-only until the content-dependent suites had a
  deterministic synthetic site — previously they either 404'd on page 2 or, worse,
  passed **vacuously** over an empty population. That requirement is now
  satisfied by the committed fixtures, and hosted determinism was proven on two
  consecutive fresh `workflow_dispatch` runs (GitHub Actions runs 36575383129
  and 36579787054, branch `i18n`); see
  [`reports/2026-09-29-stage-p-ci-readiness.md`](reports/2026-09-29-stage-p-ci-readiness.md).
  The requirement itself is unchanged and still binding: **never** satisfy
  determinism by weakening assertions or committing a database dump.
- This is a blocking integration job with ordinary CI failure semantics. It is
  not perfect and not immune to infrastructure failure — a red runner, a slow
  image pull or a flaky network fails the run as any CI job would.
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
| `tests/scripts/verify-stage7-commissioning.py` | `SCRIPT_CONTRACT` (Stage 7) | yes — blocking; the Stage 6 **supersession contract**: exactly **1** approved admin-post endpoint **by exact action name**, registered once, in one approved file, and **0** forbidden entry surfaces / **0** webhooks. The pre-existing apply-capable engine endpoint is declared, not ignored. |
| `tests/scripts/verify-stage10-batch-boundary.py` | `SCRIPT_CONTRACT` (Stage 10) | yes — blocking; **injected-negative structural proofs for the Stage 10 batch layer**. Each case injects a REAL violation into a THROWAWAY copy of the repository and asserts the control-plane gate exits non-zero: the one-batch default raised, a mutation token added to a batch file, a loop added to the executor, the executor's own guards removed, the expansion gate made permissive, an unnamed batch file, and a batch admin endpoint. The clean state is asserted to pass FIRST, because a proof that passes because the baseline is already red proves nothing. **20 proofs, 0 missed**; four were missed on the first run and each exposed a genuinely missing gate assertion. |
| `tests/scripts/verify-stage8-control-plane.py` | `SCRIPT_CONTRACT` (Stage 8) | yes — blocking; **one production translation-mutation control plane**. Exactly **2** admin-post endpoints exist across `conexao-translation-rollout` + `conexao-translation-automation` (the approved proof entry + the closed legacy one, both by exact resolved action name and owning file). The closed legacy handler may contain no `call_user_func` / `run_callback` / `Engine::` reference, read no superglobal, and carry no apply/remove vocabulary. The approved entry file may not reach a stage `run_callback` or the engine directly. Ordering of lock → F7 → approval → snapshot-persist → `'dry_run' => false` is asserted on real call sites, and the engine core digest is re-pinned. | **STAGE 10 EXTENSION (no new gate, no new inventory):** a new section asserts the batch control plane in this same registry — every shipped batch file is named here, no batch file can reach a mutation path (`Rollout_Engine::`, `wp_insert_post`, `wp_update_post`, `wp_delete_post`, `delete_post_meta`, `pll_*`), the batch layer registers **no** admin-post endpoint, the explicitly-empty `BATCH_CONTROL_SURFACES` registry stays empty and fails closed on an undeclared batch endpoint, `MAX_PRODUCTION_BATCHES_PER_INVOCATION` is exactly `1`, the executor contains no loop construct and exactly one orchestrator call site and re-asserts the one-batch guard, the approval re-verification, the emergency stop, the abort and the plan-scope assertion, and the expansion gate still requires a verified predecessor, still derives eligibility from the unmet list and still requires all seven evidence keys. Gate total 90 -> **141**.
| `tests/scripts/verify-stage14-secret-scan.py` | `SCRIPT_CONTRACT` (Stage 14) | yes — blocking; **the hardened secret-scan contract**. The PHP runtime boundary and this gate enforce the SAME canonical corpus (`tests/fixtures/secret-scan-corpus.json`, 27 cases), and the gate asserts the two agree case-for-case, so agreement is **checked** rather than maintained twice; when the PHP side cannot run (no local WordPress) the conformance case is reported **unproven**, not passed. Negative: 11 real secret shapes (provider keys incl. `proj-`/`svcacct-`/`admin-`/`or-` variants, four PEM private-key forms, bearer/basic `Authorization` in header, PHP-array and JSON form, and the pre-existing four-group application password) are **refused**, not merely absent. Positive: 16 legitimate values (CSS colours and `rgb()`, SHA-256/MD5 digests, a UUID, version and semver strings, public and provider URLs, ordinary source code, authorization PROSE, a test NAME, credential/private-key prose, a stage slug and a run ID) are **not** false positives. Redaction: a finding reports category, location, reason and `redacted`, and the absence of the value, a prefix, a suffix, a middle slice, the length, SHA-256, SHA-1 and base64 is asserted. Containers: a secret is found in a nested array, an exception message, a provider result, audit metadata, release metadata, an environment-derived structure, a list element and a bare string. Artifacts: the same rules run over the source tree, the ten production ZIPs, the release record and the generated evidence; **no exemption applies inside a ZIP**; `--build` builds twice and requires byte-identical artifacts. **The allowlist is asserted, not trusted**: an exemption needs BOTH a declared literal AND a `tests/` location, the match is by substring in one direction only, and every declared fixture must be used and must appear only under `tests/`. **Fail-closed is proven by execution**: a real key injected into shipped plugin source turns the gate red naming the file and category, never the value. The four-group shape is deliberately NOT a text rule — it matches English prose — and applies only to structured payload values, where it stays exactly as strict as Stage 2 made it. 323 passed, 0 failed; **344 passed, 0 failed** with `--build`. |
| `tests/scripts/verify-stage13-commissioning-readiness.py` | `SCRIPT_CONTRACT` (Stage 13) | yes — blocking; **the commissioning-readiness contract**. The eighteen-entry prerequisite registry is COMPLETE (exact count, no duplicate IDs, every required entry has a callable validator, and the mapping onto Stage 12's eighteen prerequisites is **total**); readiness is **derived**, never assigned (no `ready = True`, no `auto_ready`, no client input); the report passes `assert_no_secrets()` and **seven injected secrets are proven refused**; the engine and preflight contain **no** production-write, provider, apply or commissioning capability (42 forbidden tokens scanned on code with comments and string literals stripped, so the scan tests capability rather than spelling); installation, provider-call and apply authorization are **independent** across all nine ordered pairs, and a wrong-`type` object is refused rather than coerced; the eight Stage 10 batch ceilings are checked against the **actual** configured limits with a missing limit failing closed; the emergency stop is asserted fail-closed and immovable from a request; the control plane is asserted `DECLARED`; the runbook is asserted generated from the registry. **44 injected-negative proofs, 0 missed** — the positive control is asserted at the gate's CEILING **first**, because a proof against an already-red baseline proves nothing. **These proofs found four real gate defects** (an unchecked `SERVER_CEILING_BATCH_RECORDS`, a `structural-only` mode inheriting the parent's failures, a capability scan a rename could evade, and a secret scanner firing on legitimate code). **STAGE 17 EXTENSION:** permanent AUTOMATIC commissioning is retired, so the status model gains `RETIRED`, the fully-evidenced positive control now asserts `RETIRED` **and** that every prerequisite still reached `READY` on its own merits (retiring the aggregate must not skip the evaluation), and the runbook is the ten-step MANUAL workflow. Two proofs asserted a specific aggregate status and were rewritten to assert the underlying property instead — digest drift must still be **detected and named in `failed_invariants`**, and client input must still not clear the emergency stop. 808 passed, 0 failed. |
| `tests/scripts/verify-stage17-retirement.py` | `SCRIPT_CONTRACT` (Stage 17) | yes — blocking; **the retirement contract**. Proves the retired architecture cannot silently return: the Stage 16 broker class, its tokens and its test are **absent**; the wake-up `Hooks` class and its suite are **absent**; no shipped file registers `save_post` / `before_delete_post` / `wp_trash_post` / `untrashed_post` / `set_object_terms`; no cron API is used; the `hook`/`scheduled` audit kinds are gone while `manual`/`admin_proof` remain; the plugin bootstraps **exactly one** registered surface (the manual admin trigger) and still does **not** register the batch-control endpoint; there is no anonymous, REST, AJAX or webhook surface; and the provider is invoked only from the manual control plane. Positively: the manual endpoint still exists with `manage_options`, nonce, POST-only and `MODE_PROOF`-only, reaches the engine through its `run_callback` contract and its Stage 11 `narrow_manifest` exact-scope method, and no shipped file calls `Engine::run()` directly. Every safety class is still shipped and still loaded; every behavioural suite that proves a safety property must still EXIST (so deleting coverage fails the gate); the engine digest is re-pinned and the Model A suite still asserts approved/planned/executed. Credential negatives: no provider-key shape in shipped code, docs or evidence, and no class declares an option that could persist a credential. Readiness: `AUTOMATIC_TRANSLATION_RETIRED` is true, the aggregate is `RETIRED`, commissioning is never permitted, **and an integrity failure is still surfaced under retirement**. **9 injected mutations, every one failed closed**; two of them exposed real defects in this gate (a single-quote-only hook pattern, and a scanner that blanked string literals and so could not see a hook name at all) which were fixed rather than documented away. 110 passed, 0 failed. |
| `tests/scripts/verify-stage18-operator-runbook.py` | `SCRIPT_CONTRACT` (Stage 18) | yes — blocking; **the manual operator runbook is true**. Treats `docs/translation-manual-operator-runbook.md` as a contract and checks it against the code it describes rather than against prose. Positively: the runbook exists, is linked from `docs/README.md` and from the automation plugin doc, and carries the `_Last verified:` marker; all eleven lifecycle stages and all eight required sections are present; the retired architecture (broker, cron, webhook) is not offered as a live option. **The stage list is read from the source**, both directions: every stage on `Orchestrator::allowed_stages()` must be documented, and every `` `en-*` `` stage the runbook names must really be allowlisted, so the document cannot drift from the control plane in either direction; the deliberately non-automatable `job` stage must be called out. All **twelve** retained safety controls are documented. **Every metric the runbook promises is checked to have a real producer** (`gate.eligible_public_pt`, `gate.pt_drift`, `scope.approved_count`, `scope.executed_count`, `provider_calls`, `mutation_occurred`), so the runbook cannot promise a number nobody can produce. Secrets: no credential shape in the document, the real `CREDENTIAL_ENV` name is present, and all **eight** forbidden storage locations must appear **inside** the prohibition block (a list of locations with the instruction removed fails). Honesty: WordPress.com Personal's lack of server-side environment variables is stated, and the disproven workarounds (Personal env vars, key in options, WP-CLI, SSH) are all absent; apply being uncommissioned is asserted positively; production work is pointed at `wp-production-operations`. The behavioural suites the runbook relies on must still EXIST, the named admin screen must really be registered, and the retirement flag must still be present. **15 injected mutations, every one failed closed**; two exposed real defects in this gate (a forbidden-location check that passed on an unrelated "never" in the same block, and an apply-status check satisfied by a *different* sentence) which were fixed rather than documented away. 111 passed, 0 failed. |
| `wp-content/plugins/conexao-translation-automation/tests/test-manual-translation-workflow.php` | `MANUAL_TEST` (Stage 17) | yes — blocking; **the RETAINED manual workflow, end to end**. Drives the real sequence — manual request → inventory → manifest → dry-run → snapshot → approval → explicit apply → verify → idempotence — through the **existing** shared engine with the repository's own in-memory adapter, so no second engine is built. Proves the manual endpoint accepts an authenticated + `manage_options` + nonced request and refuses each missing gate individually, that `mode=apply` is refused, that the dry run writes nothing, that `approved == planned == executed` and that the ACTUAL mutation set has no extras, that a second apply creates and updates nothing, and that PT content, PT language and PT identity are unchanged. Fails closed on a colliding `en_slug`, on PT drift and on an unknown mode or stage. Asserts the provider refuses with no credential supplied, that the credential is named by a public constant and read from the environment, and that no shipped class declares an option able to persist a credential. **Stage 18 extension:** a fifth section proves **every metric the operator runbook promises is a real field of a real run** — `records considered`, `records eligible`, `records planned`, `records approved`, `records executed`, `mutations`, `verification result`, `idempotence result`, `PT drift` — read out of the engine report rather than asserted from source, that `approved_count == executed_count` on an actual run, that the gate turns FAIL on a missing EN record and on PT drift alone, that the result contract surfaces the engine verdict verbatim, and that a proof-mode result can never claim a mutation. **No real provider call is made and none is fabricated.** 66 passed, 0 failed. |
| `tests/scripts/verify-stage8-release-consistency.py` | `SCRIPT_CONTRACT` (Stage 8) | yes — blocking; **release metadata equals source**. Asserts plugin header == `dist/release.json` == the version read from **inside** the built ZIP, for every buildable component, plus `Requires Plugins` == `plugins.json` dependencies. Carries the Stage 7 stale-`release.json` finding (automation `0.2.0` vs header `0.3.0`) as a permanent regression assertion. |
| `wp-content/plugins/conexao-translation-rollout/tests/test-legacy-apply-endpoint-closure.php` | `MANUAL_TEST` (Stage 8) | yes — blocking; proves the closed endpoint refuses every request shape (anonymous, no capability, `apply`, `remove`, `preview`, missing/invalid mode, malformed stage, alternate parameter names, GET) **and** that the engine `run_callback` is invoked **zero** times across all of them |
| `wp-content/themes/conexao-br-irlanda/tests/test-taxonomy-policy.php` | `PERMANENT_INVARIANT` (Stage L) | yes — blocking; §6.3 taxonomy policy |
| `wp-content/themes/conexao-br-irlanda/tests/test-translation-completeness.php` | `PERMANENT_INVARIANT` (Stage L) | yes — blocking; §6.1 completeness |
| `wp-content/themes/conexao-br-irlanda/tests/test-cache-language-scoping.php` | `PERMANENT_INVARIANT` (Stage L) | yes — blocking; §6.1 cache scoping |
| `wp-content/themes/conexao-br-irlanda/tests/test-redirect-precedence.php` | `PERMANENT_INVARIANT` (Stage L) | yes — blocking; §6.3 redirect precedence |

_Last verified: 2026-10-02 by Stage 21 — the ICS occurrence fixture and the two hermetic script-contract gates_

_Last verified: 2026-09-26 by Stage I — Scripts Standardisation_
_Last verified: 2026-09-26 by Stage J — Build, Release & Deploy Verification_

_Last verified: 2026-09-28 by CI integration setup recovery_
_Last verified: 2026-10-01 by Stage 7 — supersession contract gate registration_
_Last verified: 2026-09-26 by Stage K — Agent Skills + Templates_
_Last verified: 2026-09-26 by Stage O — Enable the Real English Blog Archive_

_Last verified: 2026-10-01 by Stage 10 — bounded batch suite (243 assertions) + 20 injected-negative structural proofs; Stage 8 control-plane gate extended to 141_

_Last verified: 2026-09-30 by the agent skills / documentation migration_
_Last verified: 2026-10-01 by Stage 13 — commissioning-readiness gate (768 assertions, 34 injected-negative proofs)_

_Last verified: 2026-10-02 by Stage 17 — retirement of automatic translation automation (Stage 13 gate 808 assertions / 44 proofs; new `verify-stage17-retirement.py` 110 assertions / 9 mutations; new manual-workflow suite 49 assertions)_

_Last verified: 2026-10-02 by Stage 18 — manual translation operator workflow (new `verify-stage18-operator-runbook.py` 111 assertions / 15 mutations; manual-workflow suite 49 -> 66)_
