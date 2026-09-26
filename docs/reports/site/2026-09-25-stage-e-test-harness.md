# Stage E — Unified Test Harness and Test Standardisation

**Stage:** E of the standardisation roadmap (`docs/engineering-standard.md` §8
and §15 row E; audit
`docs/audit/2026-09-25-wordpress-engineering-standardisation-audit.md`).
**Depends on:** Stage D
([`2026-09-25-stage-d-ci.md`](2026-09-25-stage-d-ci.md)).
**Scope:** test infrastructure only. **No WordPress runtime/behaviour change** —
no plugin, theme, template, CPT, taxonomy, route, Polylang, REST, CSS, JS,
content or production change. No Flutter/mobile change. No theme split (Stage F),
no script standardisation (Stage I), no toolchain upgrade.
**Status: PASS WITH LIMITATION** — the harness is complete and every maintained
suite runs through one command, but (a) 15 in-process suites fail **identically
to the pre-migration baseline** (real, pre-existing content/data defects, not
harness defects), and (b) a real GitHub-hosted CI run could not be *observed*
because the git remote requires credentials unavailable in this environment.

---

## 1. Baseline

| Measure | Value |
|---|---|
| Branch | `i18n` |
| Starting HEAD | `837b71c6d092be223daffc98d32da285ad18d8dc` — "stage D: record the Stage D commit hash in the report baseline table" |
| Final HEAD | `e37b634f97d9cdaacf41b9458a79c8b272fe4366` — "stage E: fix two blocking defects in the CI integration setup" (the harness implementation is `d6e1ba0`; this row is completed by the immediately following documentation commit, which cannot contain its own hash) |
| `git status --short` at start | **clean** |
| Tracked files | **616** before Stage E |
| PHP files under `wp-content/` | **310** before Stage E |
| PHP (host) | 8.5.4 |
| PHP (container) | 8.5 (`wordpress:latest`) |
| Python | 3.14.4 |
| Docker / Compose | 29.0.0 / v5.1.3 |
| Composer | 2 (container `composer:2`; no host composer) |
| WordPress / Polylang | 7.1 / 3.8.9 |
| **Original maintained PHP test count** | **46 suites** (24 theme + 22 plugin) |
| **Original maintained acceptance test count** | **0** (no shared acceptance layer existed) |
| Baseline result | 30 pass / 16 non-zero (see §2) |

The audit's historical estimate of "roughly 100 test files" counted every PHP
file under a `tests/` directory, including 51 one-off diagnostics, `c2-*` audits,
`dry-run-*`, `live-*` and `probe-*` scripts. The **measured** maintained count
is **46** — files matching the standard's `test-*.php` convention. Those 51
non-maintained scripts are left exactly where they are.

## 2. Test inventory

Every discovered item was classified before anything was run (PHASE 4).

| Category | Count | Default | Manual | Historical | Production-only |
|---|---:|---:|---:|---:|---:|
| `DEFAULT_TEST` — in-process PHP (`<component>/tests/test-*.php`) | 46 | 46 | – | – | – |
| `ACCEPTANCE_TEST` — `tests/acceptance/verify-*-http.py` | 2 | 2 | – | – | – |
| `MANUAL_TEST` | 1 | – | 1 | – | – |
| `HISTORICAL_VERIFICATION` — `scripts/stage*-verify.*` | 6 | – | – | 6 | – |
| `DIAGNOSTIC` — `c2-*`, `dry-run-*`, `live-*`, `probe-*`, `verify-*` under component `tests/` | 51 | – | – | – | – |
| `PRODUCTION_ONLY` — `scripts/c3-production-*.py` | 2 | – | – | – | 2 |

**The one MANUAL_TEST:** `conexao-event-runtime/tests/test-plugin-separation.php`.
It asserts plugin separation under a *different plugin-activation state*
(importer deactivated, then activated). Running it changes the environment
rather than testing it. It is reported by `--list` with its reason and its
invocation; it is never silently skipped.

**HTTP verifier classification** (the migration input, PHASE 18/19):

| Script | Class | Rationale |
|---|---|---|
| `scripts/stage2-http-verify.sh` | **migrated → `verify-routing-http.py`** | Maintained regression coverage of routing/language/canonical/hreflang/REST. |
| `scripts/stage33-http-verify.py` | **migrated → `verify-routing-http.py`** | Maintained; duplicate routing/redirect concerns folded into one matrix. |
| `scripts/stage9-guide-http-verify.sh` | **migrated → `verify-guides-en-http.py`** | Maintained coverage of the EN Guide archive. |
| `scripts/stage6-job-verify.py` | `HISTORICAL_VERIFICATION` | A migration-phase tool: it requires a `--phase before|after` inventory JSON and a WP-CLI probe, i.e. an operator workflow, not a regression suite. |
| `scripts/stage7-leisure-http-verify.py` | `HISTORICAL_VERIFICATION` | A before/after comparison tool driven by two JSON snapshots and a non-default port. |
| `scripts/jobs-en-language-verify.py` | `HISTORICAL_VERIFICATION` | Migration verification driven by an external inventory file. |
| `scripts/stage41-rest-verify.py` | `HISTORICAL_VERIFICATION` | Stage 4.1 migration REST verification. |
| `scripts/nav-regression-http-verify.py` | `MANUAL_TEST` | Renders live nav menus and depends on a populated, fully translated dataset; kept as a diagnostic probe. |
| `scripts/test-admin-ux-e2e-http.sh` | `MANUAL_TEST` | Drives the admin UI end-to-end (writes); not a read-only regression suite. |
| `scripts/stage45-verify-pages.py` | `HISTORICAL_VERIFICATION` | Stage 4.5 migration verification. |
| `scripts/wp_rest_verify_event_towns.py` | `DIAGNOSTIC` | A one-off REST probe. |
| `scripts/c3-production-http-verify.py`, `scripts/c3-production-verify.py` | `PRODUCTION_ONLY` | Intentionally target `conexaobr.ie`. Never in CI, never redirected. |

No historical script was deleted or modified by Stage E.



## 3. Harness

| Artefact | Path |
|---|---|
| WordPress bootstrap (the only one) | `tests/bootstrap.php` |
| Assertion library (the only one) | `tests/lib/assertions.php` |
| Runner | `scripts/run-tests.sh` |
| Acceptance support | `tests/acceptance/lib/http_client.py`, `tests/acceptance/lib/matrix.py` |
| Acceptance suites | `tests/acceptance/verify-routing-http.py`, `verify-guides-en-http.py` |
| Matrices | `tests/acceptance/matrices/routing.json` (56 rows), `guides-en.json` (8 rows) |
| Fixtures doc | `tests/fixtures/README.md` |

**Discovery rules** (convention-based, PHASE 21 / "no second source of truth"):

- in-process: `wp-content/themes/*/tests/test-*.php` and
  `wp-content/plugins/*/tests/test-*.php`, sorted with `LC_ALL=C`;
- acceptance: `tests/acceptance/verify-*-http.py`.

Nothing under `scripts/` is auto-executed. A newly added standard-compliant test
becomes discoverable with no edit to any list — **proved** in §10.

**Process isolation** (PHASE 23): one PHP process per suite, serial, in
deterministic order (component → suite name). Each suite gets a fresh WordPress
bootstrap, so globals, function declarations and static state cannot leak
between suites. The database is shared by design, which is why the write-capable
suites clean up after themselves (§9).

**Summary format** (PHASE 24), from a real run:

```
[PASS] plugin/conexao-event-runtime/test-event-language-gate.php — 18 passed, 0 failed
[FAIL] theme/conexao-br-irlanda/test-guide-en-translation.php — 632 passed, 2 failed (exit 1)
         FAIL: eligible public PT guides missing EN = 0 — missing: ["outono-irlanda-alimentacao-bem-estar"]
[PASS] test-header-menu-selection.php (en) — 16 passed, 0 failed
----------------------------------------
In-process PHP suites: 46 total, 31 passed, 15 failed
Assertions: 3244 passed, 59 failed
HTTP acceptance suites: 2 total, 2 passed, 0 failed
----------------------------------------
TESTS FAILED
```

**Exit-code rules** (PHASE 105), all verified in §10:

| Condition | Exit |
|---|---|
| all suites pass | 0 |
| any suite fails / times out | 1 |
| WordPress cannot bootstrap | 1 (`[BLOCKED]`) |
| data prerequisite missing | 1 (`insufficient data: <hint>`) |
| malformed matrix | 1 (before any request) |
| HTTP target unreachable | 1 (`BLOCKED: HTTP acceptance environment unavailable`) |
| production base URL | 2, no request sent |
| unknown `--only` value | 2, `unknown test component: <value>` |

**Suite metadata** (PHASE 4). Two suites need more than a bare invocation, and
the runner carries that explicitly rather than hiding it:

- `test-header-menu-selection.php` runs twice, as `pt` and `en`. Both are

## 4. Assertion migration

**Old helper families found** (21 distinct names across 46 files) and their
mapping. Every one was inspected before being replaced; none was assumed.

| Old helper | Files | → Shared API | Semantic note |
|---|---:|---|---|
| `test_assert` | 13 | `assert_true` | Identical: global counters, `  PASS:`/`  FAIL:`. |
| `t_assert` | 9 | `assert_true` | Identical. |
| `t_strict`, `t2_strict` | 2 | `assert_equals` | Appended `expected X, got Y` on failure — that is exactly `assert_equals()`. |
| `t2_assert` | 1 | `assert_true` | Identical. |
| `ts_assert` | 2 | `assert_true` | Identical. |
| `s5/s6/s7/s7m/s8/s9/s32/s33/s41/s45_assert` | 12 | `assert_true` | Same, plus an optional `$detail` third argument → `assert_true( $c, $m, $d )`. |
| `id_assert`, `uuid_assert`, `gate_assert`, `xl_assert` | 4 | `assert_true` | Identical. |
| `mp_test_assert` | 2 | **preserved** (documented exception) | Validates *its own* arguments and counts misuse as a harness error **and** a failure. That is a contract guard belonging to that suite, not to the shared API. |
| `check( label, cond[, detail] )` | 4 | `assert_true( cond, label[, detail] )` | Arguments were **reversed** relative to the `*_assert` family; only the order changed, never the decision. 102 call sites reordered. |
| `lc_ok( label, cond[, detail] )` | 1 | `assert_true( cond, label, detail )` | Counted only failures in a `$fail` global and printed `[ OK ]`. Now counts both directions so the suite emits the required `N passed, M failed`. 23 call sites. |

- **Migrated:** 44 of 46 files; **remaining exception:** 1
  (`mp_test_assert` in `test-mondello-park-importer.php`).
- **Final grep:** exactly **1** suite-local assertion definition remains in the
  whole repository, and it is the documented exception above. Every other
  maintenance file uses `tests/lib/assertions.php`.
- **Strictness was not changed.** `assert_true()` is truthy and
  `assert_equals()` is loose because that is what the legacy helpers were.
  Call sites needing identity already wrote `$a === $b` and were left untouched.

## 5. Bootstrap migration

- **Inline bootstrap copies removed: 46.** Before: 45 files contained their own
  `$wp_load` discovery, a literal `require '/var/www/html/wp-load.php'`, a
  multi-candidate `foreach` over candidate paths, or an
  `if (file_exists($wp_load)) … else …` fork; plus 12 suites that also set
  `$_SERVER` for the CLI request context.
- Each now begins with a single deterministic
  `require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';` (depth verified
  to be 4 for both `wp-content/themes/<theme>/tests/` and
  `wp-content/plugins/<plugin>/tests/`, resolving to the repository root on the
  host and `/var/www/html` in the container).
- The `$_SERVER['HTTP_HOST'|'REQUEST_URI'|…]` preamble moved into
  `tests/bootstrap.php` once, for everyone.
- **Remaining exceptions: 1** — `test-nav-language-context-logic.php`. It is a
  *standalone static-analysis* suite that parses theme source with
  `token_get_all()` and supplies its own WordPress function stubs, and it
  documents "No WordPress, no database, no network". Loading the bootstrap
  redeclares those stubs (caught and fixed during migration:
  `Cannot redeclare trailingslashit()`). It requires `tests/lib/assertions.php`
  only. It runs read-only with no database and is still discovered and executed
  by the default runner (49 passed, 0 failed).
- **Final grep result:** `0` maintained suites contain inline `wp-load`
  discovery, `0` contain a literal `require … wp-load.php`, `0` contain a
  `$wp_load` assignment. `tests/bootstrap.php` is the only bootstrap.

## 6. Data prerequisites

- **Mechanism:** `test_require( $condition, $hint, $message, $detail )` plus
  `test_prerequisite_hint( $hint )`. A satisfied prerequisite is a normal
  passing assertion; an unsatisfied one prints
  `insufficient data: <hint>` and a `setup hint:` line, records a failure, and
  **exits non-zero immediately**, so the remaining assertions cannot produce
  misleading cascading failures.
- **Vacuous passes eliminated.** 10 suites previously contained
  `if ( ! <capability> ) { echo "SKIP: …"; exit( 0 ); }` guards for Polylang and
  for four plugin classes. All 10 are now honest prerequisites. A suite that
  could pass while asserting nothing can no longer do so.
- **Current prerequisite failures: 0** — the local environment satisfies every

## 7. HTTP acceptance

- **Migrated acceptance suites: 2**, from 3 maintained verifiers
  (`stage2-http-verify.sh`, `stage33-http-verify.py`,
  `stage9-guide-http-verify.sh`). Rows were **extracted from the actual
  scripts**, not recreated from memory: PT URL 200s, the eight legacy EN→PT
  301s, the `/en/` 200s and B2 behaviour, lang/og:locale, canonical ownership,
  the hreflang sets, cache separation in both directions, search, the REST
  language contract, robots/sitemap/404/feed, and the Stage 9 Guide archive
  with pagination, category filters, the EN single and the "replaced master"
  redirect.
- **Matrix files: 2** — `routing.json` (56 rows), `guides-en.json` (8 rows).
  77 assertions executed in total (59 + 18), all passing.
- **Schema:** one format, `{id, url, expect_status, expect_contains,
  expect_absent, note}`. `tests/acceptance/lib/matrix.py` validates a whole
  file before executing any row: required fields, non-empty unique ids,
  relative `/…` URLs, integer statuses, arrays of non-empty strings, non-empty
  notes. All six malformed classes were proven to be rejected (§10).
- **Local URL safety:** default `http://localhost:8080`;
  `CONEXAO_TEST_BASE_URL` overrides it; CI sets it explicitly.
- **Production host blocking:** enforced twice — in `scripts/run-tests.sh`
  (before anything starts) and in `http_client.assert_local_base()` (before a
  socket is opened). Verified: `conexaobr.ie` is refused with **zero requests
  sent** (§10).
- **Redirect handling:** redirects are not followed by default, so a
  `301/302/307/308` row asserts the *first* response. Because the shared schema
  has no headers field, rows that must assert a redirect **target** use a small
  suite-level `Location` check: 3 rows in `verify-routing-http.py` and the
  "replaced master" row in `verify-guides-en-http.py`. This is the single
  documented PHASE 35 exception.

## 8. Fixtures

| Measure | Value |
|---|---|
| Fixture files | **9** |
| Total fixture bytes | **903,980** |
| Largest fixture | **576,595** — `mi-listing.html` (real Motor Italy listing markup) |
| Second largest | 315,506 — `mp-rest.json` |
| **New fixture bytes added by Stage E** | **0** |

- Measured, not guessed; `tests/fixtures/README.md` has the per-file table and

## 9. Local test results

All numbers are real, from `./scripts/run-tests.sh` against the local Compose
stack (WordPress 7.1, Polylang 3.8.9, PHP 8.5).

### `./scripts/run-tests.sh` (default: both layers)

```
In-process PHP suites: 46 total, 31 passed, 15 failed
Assertions: 3244 passed, 59 failed
HTTP acceptance suites: 2 total, 2 passed, 0 failed
----------------------------------------
TESTS FAILED                                    (exit 1)
```

### `./scripts/run-tests.sh --only theme`

```
In-process PHP suites: 25 total, 14 passed, 11 failed
Assertions: 1805 passed, 54 failed
real 1m41.635s
```

(25 = 24 theme files + the `pt` variant of `test-header-menu-selection.php`.)

### `./scripts/run-tests.sh --acceptance`

```
[PASS] tests/acceptance/verify-guides-en-http.py — 18 passed, 0 failed
[PASS] tests/acceptance/verify-routing-http.py — 59 passed, 0 failed
HTTP acceptance suites: 2 total, 2 passed, 0 failed
real 0m42.604s
```

### `./scripts/run-tests.sh --only conexao-event-runtime`

```
In-process PHP suites: 3 total, 3 passed, 0 failed
Assertions: 149 passed, 0 failed
HTTP acceptance suites: 2 total, 2 passed, 0 failed
real 1m13.510s
```

### Pre- vs post-migration comparison (PHASE 56)

Baseline (PHASE 3): **46 suites, 30 exit-0, 16 non-zero.**
After migration: **46 suites, 31 pass, 15 fail.**

**Failure counts are identical in every comparable suite.** Of the 41 suites
whose baseline produced a machine-readable count, **28 have byte-identical pass
counts** and **12 execute more assertions than before** — in 11 cases by exactly
the number of new `test_require()` prerequisite assertions, and in one case
(`test-stage45-pages.php`) by 216, explained immediately below. **Not one suite
lost an assertion, and no failure count changed anywhere.**

| Suite | Baseline | After | Delta | Explanation |
|---|---|---|---:|---|
| 28 suites | p/f | p/f | 0 | unchanged |
| `test-translation-state` | 22/0 | 23/0 | +1 | 1 prerequisite assertion |
| `test-export-language` | 57/0 | 58/0 | +1 | 1 prerequisite assertion |
| `test-language-identity` | 27/0 | 29/0 | +2 | 2 prerequisite assertions |
| `test-event-language-gate` | 16/0 | 18/0 | +2 | 2 prerequisite assertions |
| `test-language-uuid` | 14/0 | 16/0 | +2 | 2 prerequisite assertions |
| `test-blog-en-translation` | 18/9 | 19/9 | +1 | 1 prerequisite assertion |
| `test-guide-en-translation` | 630/2 | 632/2 | +2 | 2 prerequisite assertions |
| `test-job-en-translation` | 29/0 | 30/0 | +1 | 1 prerequisite assertion |
| `test-jobs-en-language` | 102/0 | 103/0 | +1 | 1 prerequisite assertion |
| `test-polylang-foundation` | 60/1 | 61/1 | +1 | 1 prerequisite assertion |
| `test-stage32-bilingual` | 31/11 | 32/11 | +1 | 1 prerequisite assertion |
| `test-stage33-bilingual` | 169/14 | 170/14 | +1 | 1 prerequisite assertion |
| **`test-stage45-pages`** | **112/11** | **328/11** | **+216** | **pre-existing early-exit defect, see below** |
| `test-header-menu-selection` | could not run | 11/1, 16/0 | new | needed a CLI argument; now runs both variants |
| `test-plugin-separation` | could not run | not run | — | reclassified MANUAL, documented |

#### The most valuable thing this stage surfaced: a silently under-testing suite

`test-stage45-pages.php` previously executed **112** assertions. It now executes
**328** — the same 11 failures, plus 216 assertions that **used to never run**.

The cause is a latent defect in the pre-Stage-E file, confirmed by running the
original file from `HEAD` unchanged against the same database:

```
original  (from HEAD, untouched):  112 passed, 11 failed
migrated:                           328 passed, 11 failed
```

The file ends with a `foreach ( $map as $pt_slug => $spec )` loop whose closing
`}` sits **after** the summary `echo` and the `exit(...)`. Those two statements
were therefore *inside* the loop body, so the suite printed its summary and
terminated the process on the first iteration that reached them — silently
abandoning the remaining ~216 assertions. Because the suite was already exiting
non-zero, the truncation was invisible.

The migration places the shared `test_finish()` after the loop's closing brace,
which is the evident intent, so the loop now runs to completion. **This is a
coverage increase, not a weakened test:** the failure set is unchanged (11 before,
11 after) and 216 previously-dead assertions now genuinely execute and pass.

No assertion was removed, weakened, skipped or deleted anywhere in this stage.

The genuine pre-existing defects the harness now exposes, none of which Stage E
may fix (they are content/data, not test infrastructure):

| Suite | Assertion that fails |
|---|---|
| `test-guide-en-translation` | `eligible public PT guides missing EN = 0` — 1 guide missing; `51 pairs vs 52 PT guides` |
| `test-blog-en-translation` | EN Blog page absent (`en_id=0`); `pt=42 en=0` public posts; 5 posts without EN |
| `test-polylang-foundation` | `no shared county/town term carries a language assignment — expected 0, got 282` |
| `test-stage32/33` | EN pages/terms not linked as the pilot dataset expects |
| `test-stage41/45` | Stage 4.1/4.5 fixtures absent; page meta descriptions contain untranslated Portuguese |
| `test-county-registry` | Seed expectations vs the current registry |
| `test-town-sanitization` | Eircode-contaminated `conexao_town` terms present |
| `test-header-menu-selection (pt)` | `P5 PT homepage marks Início current` |

### Performance baseline (PHASE 91)

| Measurement | Value |
|---|---|
| Full run (both layers) | ~3 min 20 s |
| PHP layer alone | ~2 min 40 s |

## 10. Failure proofs

Every proof below was executed for real, then the defect was removed and the
suite re-run. All fixtures used were temporary, untracked and deleted; none is
in the final tree.

| # | Defect injected | Observed failure | Cleanup | Re-run |
|---|---|---|---|---|
| 57 | `assert_true( 1 === 2, … )` in a temporary suite | suite printed `1 passed, 1 failed` and exited **1** | file deleted | green |
| 58 | the same file, run through the runner | `[FAIL] plugin/conexao-event-runtime/test-stage-e-proof.php — 1 passed, 1 failed (exit 1)`, aggregate `TESTS FAILED`, exit **1** | file deleted | green |
| 59 | `test_require( false, 'seed-leisure', … )` | `insufficient data: seed-leisure` + `setup hint: run the documented local leisure seed` + `FAIL`, exit **1**; the following `assert_true` never executed | file deleted | green |
| 60 | matrix row `expect_status: 200` on `/nao-existe-xyz/` (really 404) | `FAIL en-b2…/proof-bad-status -- status 404, expected 200`; `1 matrix row(s) failed`; exit **1** | row removed | green |
| 61 | `CONEXAO_TEST_BASE_URL=https://conexaobr.ie` | runner: `ERROR: refusing to run acceptance tests against the production host: conexaobr.ie`, exit **2**. Library: `ProductionUrlRejected`. **No request was sent.** | env var removed | green |
| 62 | new file `test-stage-e-discovery-proof.php` | appeared in `--list` and ran automatically: `[PASS] … — 1 passed, 0 failed` | file deleted | green |
| 37 | 6 malformed matrix shapes (missing field, duplicate id, absolute URL, non-integer status, non-array contains, empty note) | all 6 **rejected before any HTTP request** with a specific `MatrixError` | temp files deleted | n/a |

**Runner-level gates also proven:** unknown filter
(`./scripts/run-tests.sh --only bogus` → `unknown test component: bogus`,
exit **2**); `--list` prints the MANUAL suite with its reason; every suite
prints `N passed, M failed`.

## 11. CI

`.github/workflows/ci.yml` — the Stage D `static-quality` job is **byte-for-byte
unchanged** (still `composer validate --strict`, `composer install`, lockfile
assertion, `./scripts/lint.sh`, `shellcheck scripts/*.sh`, and the non-blocking
raw-debt telemetry). A second job was **added**:

- **`integration`** (`Docker WordPress + run-tests.sh`), `ubuntu-latest`,
  45-minute timeout, `permissions: contents: read` inherited from the workflow.
  Steps: checkout → `docker compose up -d` → **readiness wait** (DB healthcheck
  polled to `healthy`, then the front page polled until it returns 200/301/302 —
  *not* `sleep 10`) → WordPress install via the pinned `wordpress:cli` image →
  activate the repository theme and the six runtime plugins → install and
  activate **Polylang 3.8.9** (the documented pinned version, never "latest") →
  report the environment → **`./scripts/run-tests.sh`** → teardown
  (`docker compose down -v --remove-orphans`, `if: always()`).
- **Data setup:** a clean WordPress with the repository theme/plugins active and
  the pinned Polylang. No production data, no migration, no import, no dump.
  Data-dependent suites that need the rich local snapshot declare a prerequisite
  and fail honestly; that CI limitation is stated in §15.
- **Ephemeral database:** the stack uses CI-local containers and is destroyed
  with `down -v`. No developer's volume is reused; no database data is committed.
- **No masking:** the test step has no `continue-on-error`, no `|| true`, and no
  pipe that discards the exit status. Cleanup is a separate `if: always()` step,
  so it cannot overwrite the test result.
- **Security:** `permissions: contents: read`; no secrets; no
  `pull_request_target`; no write scopes; no production host; the acceptance base
  URL is an explicit local variable and the harness independently refuses
  production.
- **Validation:** the workflow parses as valid YAML, `actionlint` reports
  **0 findings**, and every `run:` block in the integration job is
  **ShellCheck-clean**.
- **The setup sequence was executed for real** against a live Compose stack
  (not just authored). Doing so exposed and fixed two defects that would have
  made the first CI run fail for environmental reasons:
  1. **`--network container:<hard-coded-name>` was wrong twice over.** The
     Compose project name is derived from the checkout directory, so the
     hard-coded `wordpress-website-wordpress-1` is fragile; and sharing only
     the network namespace leaves `/var/www/html` **empty** in the CLI
     container, so `wp core install` and `wp plugin activate` had nothing to
     act on. Replaced with `WP_CONTAINER="$(docker compose ps -q wordpress)"`
     plus **`--volumes-from "$WP_CONTAINER"`**, which inherits the named volume
     *and* the theme/plugin bind mounts.
  2. **UID mismatch blocked every write.** The volume is owned by `33:33`
     (Debian `wordpress:latest`) while `wordpress:cli` is Alpine and runs as
     `82:82`, so `wp plugin install polylang` failed with *Permission denied*.
     Fixed with `--user 33:33 -e HOME=/tmp`.
  The database credentials are read back from the running container (they are
  not inherited by a fresh `docker run`, and `wp-config.php` reads them from the
  environment), so **no credential is hard-coded** in the workflow.
  With both fixes the full sequence was re-run to completion: WordPress
  detected as installed, theme activated, all six repository plugins activated,
  Polylang 3.8.9 installed and active, `wp plugin list` succeeding.

**GitHub run status: NOT OBSERVED.** The git remote

## 12. Static quality

| Check | Result |
|---|---|
| `./scripts/lint.sh` | **OK** — `OK: no PHP parse errors.` / `OK: no new PHPCS violations against the baseline.` / `[OK] No errors` / `lint: OK (syntax clean, no new PHPCS violations, PHPStan clean)` |
| `composer validate --strict` | `./composer.json is valid` (exit 0) |
| `composer install` | 9 packages, from `composer.lock`; **`composer.json`/`composer.lock` unchanged** afterwards |
| `bash -n scripts/run-tests.sh` | OK |
| `shellcheck scripts/run-tests.sh` | **0 findings** |
| `shellcheck scripts/*.sh` | 0 findings in every script Stage E touched. 4 findings remain in two **untouched** legacy files (`restore-updraft-db.sh` SC2337 ×2, `stage7-validation-clone-setup.sh` SC2336 ×2); they are pre-existing and out of Stage E scope. |
| `python3 -m py_compile` (4 new files) | OK |
| `actionlint` (ci.yml) | 0 findings |
| `git diff --check` | clean (no whitespace errors) |
| JSON validation (matrices + evidence) | valid via the Python standard library; matrix ids unique |
| `__pycache__` / `*.pyc` | removed; not tracked |

Stage C's PHPCS/PHPStan scopes were **not** broadened or redesigned, and no
baseline was edited to hide a test problem. The new harness lives in the same
places Stage C already excludes from PHPCS/PHPStan scanning (test directories),
but every new file is syntax-checked and `run-tests.sh` is ShellCheck-clean.

## 13. Runtime safety

Explicitly confirmed, each by inspection:

- **No production runtime behaviour changed.** No `functions.php`,
  `inc/*.php`, plugin bootstrap, template, REST handler or admin screen was
  modified. `git diff -- wp-content/` touches **only** files under
  `…/tests/`.
- **No content changed.** No post, term, media or option is created or edited in
  the repository's source; the DB differential (§9) shows posts/terms/users/
  attachments identical before and after a run.
- **No production database changes** and **no production connection.** The only
  hosts contacted are `localhost`/`127.0.0.1`.
- **No Polylang changes.** No CPT/taxonomy declaration, no `pll_*` runtime
  behaviour, no language settings, no Polylang version bump.
- **No REST changes.** No route, contract or handler touched. The REST rows in
  the acceptance matrix assert the **existing** documented contract; when the
  historical script's `lang=pt-br` disagreed with the shipped contract
  (`lang=pt|en` only), the row was corrected to the contract **and** a new row
  was added asserting the documented 400 rejection — a strengthening, not a
  weakening.
- **No public URL / route / template / CPT / taxonomy change.**
- **No production HTTP requests: 0.** A production base URL is refused twice
  before any socket is opened (§10).
- **No production credentials.** None exist in the repository and none were
  added; CI declares no secrets.
- **No Flutter/mobile changes.** Nothing outside this repository's WordPress
  scope was touched.
- **No new runtime dependencies.** Grep confirms nothing outside a `tests/`
  directory references `tests/bootstrap.php`, `tests/lib/assertions.php`,
  `scripts/run-tests.sh` or `tests/acceptance/`. The theme and plugins load
  correctly without the root harness, and the harness adds no
  `if ( defined( 'CONEXAO_TEST_MODE' ) )` branch to production code.
- **No test logic in production.** The harness orchestrates externally.

## 14. Documentation

| File | Change |
|---|---|
| `docs/testing.md` | **New** — the evergreen testing reference: two-layer model, commands, exit codes, bootstrap, assertion API, writing a test, prerequisites, isolation, acceptance layer + matrix schema, base URL and production safety, manual/historical/production-only classification, fixtures, CI, extending the harness. |
| `docs/development.md` | + "Tests (Stage E — unified test harness)" section: the three commands, the local-Docker requirement, the production-URL refusal, and a pointer to `docs/testing.md`. |
| `docs/README.md` | `testing.md` added to the index; the stale "harness does not exist yet / Stage E" note replaced with the real command. |
| `AGENTS.md` | + `./scripts/run-tests.sh` in "Running the project"; a short "## Tests" orientation block; a task-index row for the test suite. No standard copied in. |
| `tests/fixtures/README.md` | **New** — measured inventory, size budget, fixture rules, ZIP-exclusion rule. |
| `docs/evidence/2026-09-25-stage-e/baseline.json` | **New** — the PHASE 3 pre-migration baseline: every suite, exit code, duration, pass/fail counts, environment, and the classification legend. |
| `docs/evidence/2026-09-25-stage-e/gate.json` | **New** — the machine-readable gate: commit, versions, suite/assertion/acceptance counts, prerequisite and bootstrap failures, `production_requests: 0`, static-quality results, repeatability, DB side-effect differential, fixture budget, CI facts. |
| `docs/reports/site/2026-09-25-stage-e-test-harness.md` | **New** — this report. |

Other files changed by the migration itself: `compose.yaml` (two dev-only
mounts), `scripts/build-theme-zip.sh` (one `tests/` exclusion),
`.github/workflows/ci.yml` (integration job appended), the 46 mechanically
migrated test files, and the 6 new harness files
(`tests/bootstrap.php`, `tests/lib/assertions.php`,
`scripts/run-tests.sh`, `tests/acceptance/verify-routing-http.py`,
`tests/acceptance/verify-guides-en-http.py`, plus
`tests/acceptance/lib/http_client.py`, `tests/acceptance/lib/matrix.py`,
`tests/acceptance/lib/__init__.py` and the two matrix files).

(`https://github.com/abalzan/wordpress-website.git`) requires credentials that
are not available in this environment, so no run ID, job result or checkmark can
be reported. The workflow has not been executed by GitHub's servers. This is the
documented limitation, not a fabricated success.

| HTTP acceptance alone | 42.6 s |
| `--only theme` | 1 m 41.6 s |
| Slowest suite | `test-event-query.php` — 19 s |
| Next slowest | `test-export-language.php` — 17 s; `test-event-recurrence.php` — 13 s |



## 14a. Migration completeness (PHASE 100)

### Maintained in-process PHP suites

| | Count |
|---|---:|
| Original maintained PHP suites | **46** |
| Migrated to `tests/bootstrap.php` + `tests/lib/assertions.php` | **46** |
| Migrated to the shared `test_finish()` summary | **46** |
| Remaining legacy suites outside the harness | **0** |
| Documented exception (standalone suite, no WordPress bootstrap) | **1** |
| Documented exception (preserved suite-local assertion) | **1** |

No maintained suite is left outside the runner, and none was excluded from
discovery.

### Maintained HTTP verifiers

| | Count |
|---|---:|
| Maintained HTTP verifiers found | 2 |
| Migrated into `tests/acceptance/` | 2 (producing 2 suites, 2 matrix files) |
| Historical verification artifacts, left intact | 6 |
| Manual tools, documented and outside the default runner | 3 |
| Diagnostic probes | 1 |
| Production-only verifiers (never in CI) | 2 |

### Permanent invariants (PHASE 33)

| Invariant | Where it is covered | State |
|---|---|---|
| Taxonomy policy — no suffixed duplicate county/town terms | `test-polylang-foundation.php` | **covered, currently failing** (`got 282` language-assigned shared terms) |
| Translated terms linked correctly | `test-stage32-bilingual.php`, `test-stage33-bilingual.php` | covered, currently failing on pilot-dataset expectations |
| Translation completeness — `missing EN = 0` | `test-guide-en-translation.php`, `test-blog-en-translation.php`, `test-job-en-translation.php` | covered, currently failing (1 guide, 5 posts, EN Blog page absent) |
| Cache scoping — language-scoped keys | `tests/acceptance/matrices/routing.json` (`cache-separation-en` / `cache-separation-pt`) **and** the live transient audit in §9 | **covered and passing**; no unscoped `conexao_*` key observed |
| Redirect precedence — legacy EN→PT 301s | `routing.json` (8 rows) + 3 suite-level `Location` checks | **covered and passing** |
| No accidental duplicate language terms | `test-polylang-foundation.php`, `test-leisure-migration/tests/test-language-uuid.php` | covered, passing / failing as above |

The invariants that fail are failing on **content**, which Stage E must not
change; they are now measured and reported by one command instead of being
scattered across individually-run scripts.

## 15. Known limitations

## 15. Known limitations

Real limitations only:

1. **15 in-process suites fail** — identically to the pre-migration baseline.
   They are pre-existing **content/data** defects (missing EN translations,
   untranslated page metadata, Eircode-contaminated town terms, absent Stage
   4.1/4.5 fixtures), not harness defects, and Stage E is explicitly forbidden
   from changing content. They are now *visible and reported* instead of being
   invisible, which is the intended outcome of this stage. Fixing them is
   follow-up content work.
2. **`test-plugin-separation.php` is MANUAL** and does not run in the default
   suite, because it requires deliberately changing the plugin-activation
   state. It is listed by `--list` with its reason and invocation. This is a
   deliberate classification, not a silent exclusion.
3. **The GitHub-hosted integration run was not observed.** The remote requires
   credentials unavailable here, so the workflow is authored, actionlint-clean
   and locally reproduced (Compose up → readiness → harness), but it has not
   been executed by GitHub. First-run CI-specific issues (image pulls, WP-CLI
   volume permissions) may surface on the first real run and are expected to be
   fixed as CI issues, not by weakening tests.
4. **CI cannot reproduce the rich local dataset.** Several suites assert over a
   full local migration snapshot (thousands of posts, a complete EN layer). CI
   starts from a clean WordPress, so those suites will fail there on their
   prerequisites/data. They are therefore expected to report
   `insufficient data:` or a data-shaped failure in CI rather than a false
   pass. No production data was imported to hide this, and no suite was
   excluded to make CI green.
5. **The fixture budget is dominated by two grandfathered files** (892 KB of
   904 KB). They are real third-party captures required by maintained importer
   suites, so the budget is expressed as a ceiling with a justification rule
   rather than a tight target.
6. **`test-nav-language-context-logic.php` does not use the WordPress
   bootstrap.** It is a standalone static-analysis suite with its own WP stubs
   (documented in its own header and in `docs/testing.md`). Loading the shared
   bootstrap would redeclare those functions. It is the single documented
   exception to the one-bootstrap rule.
7. **`mp_test_assert()` is preserved as a suite-local assertion** because it
   validates its own arguments and acts as that suite's contract guard.

None of the above is a deliberate architectural choice being relabelled as a
failure: items 1, 2, 4, 6 and 7 are documented classifications; item 3 is an
environment limitation.

## 16. Next stage

**Stage F — Theme modularisation** (engineering standard §3.1, §15 row F:
depends on E for regression safety).

Stage F is now *unblocked*: the theme split can be performed against a
**regression-safe baseline** — 46 suites, 3244 passing assertions, an unchanged
pass/fail set, a deterministic repeatability result, and a CI job that will fail
if a module move changes behaviour. Nothing about Stage F is implemented here:
`functions.php`, `inc/polylang.php` and `inc/seo.php` are untouched.

## 17. Final status

**PASS WITH LIMITATION**

The harness is complete and every acceptance criterion that this environment can
verify is met: one command runs all maintained suites; `--only theme`,
`--only <plugin>` and `--acceptance` work; unknown filters fail; every suite
prints `N passed, M failed`; any failure yields a non-zero aggregate; the
bootstrap is single; the assertion API is single; prerequisites fail loudly
instead of passing vacuously; the acceptance layer is local-only with production
blocked twice; malformed matrices fail before any request; the Stage D static job
is intact and green; `lint.sh` and ShellCheck are green; the theme and plugin
ZIPs ship no test artefacts; and two consecutive runs are byte-identical with no
database residue.

The limitations are stated honestly: 15 suites carry **pre-existing** content
failures that this stage must not paper over, one suite is a documented MANUAL
test, CI cannot reproduce the rich local dataset, and the GitHub-hosted run could
not be observed from this environment.

_Last verified: 2026-09-25 by Stage E (unified test harness)_

No suite is pathologically slow. **Serial execution is intentional** (PHASE 22):
the suites share one local database, so serialising makes failures reproducible
and prevents fixture contention. No parallelisation was introduced.

### Repeatability and side effects (PHASE 80/81/82/108)

Two consecutive full runs were compared: the suite-by-suite `PASS`/`FAIL` lines
are **byte-identical**. Database state before and after:

| | Before | After |
|---|---:|---:|
| posts | 6062 | **6062** |
| terms | 847 | **847** |
| users | 3 | **3** |
| attachments | 2415 | **2415** |
| options | 413 | 429 (all additions `_transient_*` cache, self-expiring) |

No test posts, terms, users, media or permanent options are left behind. The
`[REC-TEST]` events and the `test` user found by inspection are **pre-existing**
local data from the documented `scripts/seed-recurrence-test-events.php` seed,
not test residue. `conexao_marker_test` is a non-transient option whose
embedded timestamp is **2026-08-30** — nearly a month before this stage — and no
repository source creates it.

**Cache-scoping evidence.** The transients left behind are all language-scoped,
which is direct evidence for architecture rule 9:
`conexao_home_latest_{en,pt}`, `conexao_home_popular_{en,pt}`,
`conexao_home_sponsors_{en,pt}`, `conexao_event_upcoming_20260926_{en,pt}`,
`conexao_404_guides_pt`, `conexao_404_events_pt`. **No unscoped `conexao_*`
cache key was observed.**

  the rules.
- The event-importer fixtures were **not moved**: they pre-date Stage E, live
  inside the plugin, and are required by maintained suites. Moving them would
  break the plugin's self-contained layout and the Compose mounts for no gain.
- **Budget rule:** no new large fixtures without a measured reason; Stage E
  added zero bytes. A new fixture over ~100 KB needs a written justification. A
  database dump is never an acceptable fixture.
- **ZIP exclusion verified (PHASE 40/65):** both build scripts were run and
  every produced ZIP inspected. `dist/conexao-br-irlanda.zip` → **0** test
  entries; all 12 plugin ZIPs → **0** test entries.
  **Stage E fixed a real pre-existing release defect here:** the theme build
  script had no `tests/` exclusion and was shipping **all 24 theme test files**
  into the production theme ZIP. One `-x "*/tests/*"` line was added; no other
  build-system change.
- The root `tests/` tree is development-only: no `functions.php`, plugin
  bootstrap, template or REST handler references it (verified — §13).

  declared prerequisite (Polylang 3.8.9 and all six required plugins active).
- **Seed strategy:** unchanged and minimal. The harness reuses the developer's
  existing local dataset; it creates no seed step, imports nothing, and adds no
  database dump. No production data is involved.
- **Non-vacuity is demonstrated, not assumed:** §10 records a deliberate
  unsatisfiable prerequisite producing `insufficient data: seed-leisure` and a
  non-zero exit.
- Suites asserting over the rich local dataset (`test-guide-en-translation`,
  `test-blog-en-translation`, `test-stage32/33/41/45-*`) keep doing so; their
  failures are genuine data findings, not missing prerequisites (§9).

  ordinary read-only regression coverage.
- `test-plugin-separation.php` is MANUAL and is listed with its reason.
