# Report — Stage P: CI readiness (deterministic synthetic site for the integration job)

| | |
|---|---|
| **Stage / task name** | Stage P — CI readiness |
| **Date** | 2026-09-29 |
| **Author / agent** | Cline (agent) |
| **Branch** | `i18n` |
| **Start SHA** | `dbf8b13b260b588e80351f82098c6e9e9e84d760` |
| **Final SHA** | `dbf8b13b260b588e80351f82098c6e9e9e84d760` (uncommitted — see §17) |
| **Working tree at finish** | dirty — 16 paths, all of them this stage's work (§3) |

## 1. Scope completed

The GitHub Actions `integration` job was manual-only because it had no
deterministic site: on a fresh database the content-dependent suites 404'd on
page 2 or — worse — passed **vacuously** over an empty population. This stage
gives that job a reproducible synthetic WordPress site and makes the emptiness
itself a failure.

Delivered:

- **One fixture orchestrator**, `scripts/bootstrap-ci-fixtures.php`, which owns
  *order* and nothing else. Every unit of real work is either a committed
  dataset or an **existing** `seed-*.php` / `en-*` stage. No second fixture
  lifecycle, no second translation engine.
- **Committed synthetic datasets** for PT guides, PT blog posts, sponsors,
  events and the shared county/town taxonomy, plus the named cross-language
  pairs the Stage 3.2/4.1 suites identify by slug.
- **A fixture audit** (17 checks) that runs before the harness and fails with one
  named message when a population floor is not met.
- **Anti-vacuity in the translation-completeness gate**: a B1 type with an empty
  population is now a `FAIL`, not a pass.
- **CI hardening**: `if: always()` on the permanent-gate summary, failure
  artifacts (harness log, `gate.json`, service logs, `debug.log`, content
  inventory), and an explicit `setup-php` step.
- **Environment fixes the job needed**: a committed `docker/wordpress/Dockerfile`
  that adds gettext locales, and explicit `WPLANG`, timezone and date-format
  provisioning.
- **Removal of the dead diagnostic probe** `docker/mu-plugins/zzz-conexao-probe.php`.

## 2. Scope NOT completed

- **The job was NOT promoted to a required push/PR check.** §18 of the task
  requires two consecutive *green GitHub Actions dispatch runs*. This stage
  proved determinism with two consecutive green runs of the **identical job
  sequence performed locally** (`docs/evidence/…/run-1-*`, `run-2-*`), but
  dispatching to GitHub requires pushing this uncommitted work, which the task
  forbids ("Do not commit or deploy unless separately authorized"). The
  `if: github.event_name == 'workflow_dispatch'` condition is therefore left
  exactly as it was. This is a deliberate, documented deferral, not an omission.
- **No second run on GitHub-hosted runners** — same reason.

## 3. Files added / modified / deleted

**4 added** (3 PHP datasets, 1 Dockerfile), **1 orchestrator added**,
**1 deleted**, **7 modified**. Counts: **5 added, 7 modified, 1 deleted**.

| Path | Change | Note |
|---|---|---|
| `scripts/bootstrap-ci-fixtures.php` | added | The one orchestrator. 2,068 lines, mostly rationale. |
| `scripts/data/ci-fixture-guides.php` | added | 51 PT guides across all 13 authored categories. |
| `scripts/data/ci-fixture-posts.php` | added | 40 PT posts; slugs read from the authored manifest, not hand-listed. |
| `scripts/data/ci-fixture-events.php` | added | 13 events, 3 sponsors, 14 town terms, 12 term pairs, 1 job, 19 named pairs, and the documented minimums. |
| `docker/wordpress/Dockerfile` | added | `locales` + `locales-all`; build fails if pt_BR/en_US are absent. |
| `docker/mu-plugins/zzz-conexao-probe.php` | **deleted** | Temporary Phase 1/2 probe, self-declared. Grep found **zero** references anywhere in the repository. |
| `.github/workflows/ci.yml` | modified | `setup-php`; `wp language core install pt_BR`; timezone; date format; fixture step; `if: always()` gate summary; failure diagnostics; single evidence upload. |
| `compose.yaml` | modified | Builds the committed Dockerfile instead of using stock `wordpress:latest`. |
| `wp-content/plugins/conexao-en-translation/includes/manifest-data.php` | modified | **wp-content.** Added the 3 core-page rows (`inicio`/`sobre-nos`/`contato`) moved verbatim from the retired plugin; removed the legacy `jobs-2` row that collided with the dedicated `en-jobs-page` stage. |
| `wp-content/plugins/conexao-en-translation/includes/stage-fields.php` | modified | **wp-content.** `copy_fields` now copies `_wp_page_template` for `page` records. |
| `wp-content/themes/conexao-br-irlanda/tests/test-translation-completeness.php` | modified | **wp-content.** Anti-vacuity guard added (a test, not a gate relaxation). |
| `scripts/run-polylang-setup.php` | modified | Asserts/derives `WPLANG` from the default Polylang language. |
| `scripts/README.md` | modified | Catalogues the new orchestrator (the conventions gate requires this). |
| `tests/fixtures/README.md` | modified | Budget + rules for the new dataset. |
| `docs/evidence/2026-09-26-stage-l/gate.json` | modified | Regenerated by the gate run (expected). |

## 4. Runtime impact

**Production runtime: unchanged.** No renderer, template, query, REST route or
stylesheet was touched. The three `wp-content/` edits are: a manifest data
addition, a meta-copy rule for page templates, and a test-only anti-vacuity
assertion.

**Local/CI runtime: changed in four documented ways**, all provisioning rather
than product behaviour:

1. The WordPress image gains gettext locales, so `date_i18n()` renders
   Portuguese month names. This *fixes* a real product contract
   (`test-i18n-foundation.php` asserts "setembro") that a fresh CI runner could
   never satisfy before.
2. `WPLANG=pt_BR`, `timezone_string=Europe/Dublin` and
   `date_format=j \d\e F \d\e Y` are asserted by the orchestrator, which refuses
   to proceed on a site that has the wrong values.
3. The orchestrator performs one normalisation pass over the **ephemeral CI
   database**: it ages other leisure records so a pinned card leads page 1. It
   runs after the seeders, touches only `post_date`/`post_date_gmt`, is
   idempotent, and the database is destroyed by `docker compose down -v`.

## 5. Content / data impact

**None in production.** The only data written is into the ephemeral CI database,
which is destroyed at teardown. No production write, deploy, upload, plugin
activation or content/DB mutation was performed.

Fixture populations on a fresh database (from the audit):

| Population | Floor | Actual |
|---|---:|---:|
| PT guides | 11 | 104 |
| EN guides | 11 | 52 |
| PT posts | 11 | 70 |
| EN posts | 11 | 35 |
| Pages | 34 | 74 |
| Sponsors | 3 | 5 |
| Leisure | 1 | 247 |
| Course providers | 1 | 8 |
| Events | 3 | 24 |
| Jobs | 1 | 4 |
| County terms | 11 | 26 |
| Town terms | 18 | 22 |

Plus five invariant checks that must be exactly zero: `shared_taxonomy`,
`duplicate_slugs`, `pt_drift`, `orphan_en`, and menu `dupes`.

## 6. Polylang impact

- **PT immutability**: the orchestrator's `pt_drift` check compares the stored PT
  excerpt of every fixture-owned record against its committed value. Result
  **0**. The shared engine's own PT-drift gate also reports 0 for every stage.
- **EN records created** by the existing stages: 52 guides, 35 posts, 33 pages,
  289 leisure descriptions, 10 course-provider descriptions, 2 jobs.
- **Completeness gate**: `translation_completeness` passes with
  `eligible=0 missing=0` never reached — the anti-vacuity floor guarantees a
  real population. Measured: 104 PT guides / 52 EN pairs, 0 missing.
- **Taxonomy**: `conexao_county` / `conexao_town` remain **SHARED** (never
  duplicated per language, `shared_taxonomy = 0`). `conexao_category` and
  `category` remain **translated** with bidirectional links.

## 7. Route / HTTP impact

No route was added, removed or changed. HTTP acceptance: **3 suites, 0 failures**
(`routing`, `guides-en`, `release`). The rows that previously failed for lack of
content now pass: `pt-guides-page-2`, `en-guides-page-2`,
`en-archive-en-blog-page-2`, `en-eventos-county-filter-works` (Laois),
`en-eventos-town-filter-works` (Adare), `en-lazer-card-excerpt-is-english`,
`pt-lazer-card-excerpt-stays-portuguese`, and
`en-eventos-filters-english-copy` ("Search county"/"Search town").

## 8. Production actions

| Action | Performed? | Detail |
|---|---|---|
| Production write | **no** | |
| Deploy / upload | **no** | |
| Plugin activation | **no** | Only inside the ephemeral container. |
| Content or DB mutation | **no** | Only the ephemeral CI database. |
| Production credentials used | **no** | `WP_USERNAME`/`WP_APPLICATION_PASSWORD` are never referenced. |
| Database dump imported | **no** | |
| Git commit / push | **no** | Explicitly forbidden; the work is uncommitted. |
| Flutter/mobile repository touched | **no** | Out of scope, untouched. |

## 9. Verification commands and results

| Command | Exit | Result |
|---|---:|---|
| `./scripts/run-tests.sh` (run 1) | 0 | ALL TESTS PASSED |
| `./scripts/run-tests.sh` (run 2) | 0 | ALL TESTS PASSED — **numerically identical** |
| `python3 scripts/verify-permanent-gates.py` | 0 | 7/7 gates, AGGREGATE: PASS |
| `./scripts/lint.sh` | 0 | syntax clean, no new PHPCS violations, PHPStan clean |
| `php scripts/generate-registry-docs.php --check` | 0 | 14 plugins, 23 regions current, zero writes |
| `python3 tests/scripts/verify-release-integrity.py` | 0 | 210 passed, 0 failed |
| `python3 tests/scripts/verify-agent-governance.py` | 0 | 247 passed, 0 failed |
| `python3 tests/scripts/verify-script-conventions.py` | 0 | 321 passed, 0 failed |
| `git diff --check` | 0 | clean |

### Numeric test results

**Baseline (developer database, before this stage)**

```
In-process PHP suites: 62 total, 59 passed, 3 failed
Assertions: 4087 passed, 16 failed
Script-contract suites: 6 total, 6 passed, 0 failed
HTTP acceptance suites: 3 total, 2 passed, 1 failed   (verify-routing-http TIMEOUT)
```

**After (fresh database, deterministic synthetic site)**

```
In-process PHP suites: 62 total, 62 passed, 0 failed
Assertions: 4146 passed, 0 failed
Script-contract suites: 6 total, 6 passed, 0 failed
HTTP acceptance suites: 3 total, 3 passed, 0 failed
ALL TESTS PASSED
```

### HTTP acceptance

**3 suites** (`verify-routing-http`, `verify-guides-en-http`,
`verify-release-http`), **0 assertions failed**. Matrices:
`routing.json` (89 rows), `guides-en.json` (8 rows) and the fixed
`release-smoke-matrix.json` (18 rows), plus the Stage 4.1/3.2/7 in-process
bilingual suites. Every row that had content dependencies now passes.

### Static analysis

`php -l` clean over 472 files. PHPCS: **no new violations** against the baseline,
and the stage **paid down** debt in 19 sniffs (3211 errors / 2668 warnings vs a
recorded 3290 / 2672). PHPStan level 5: **no errors**.

### Script-contract results

`./scripts/run-tests.sh --scripts`: **6 suites, 6 passed, 0 failed**
(321 + 210 + 247 + 14 + 8 + 2 assertions across them).

### Release / build results

`not in scope` — no release was performed. `verify-release-integrity.py` passes
(210 assertions), which is the gate that proves the new `scripts/` files cannot
leak into a release artifact.

## 10. Failure proofs / negative tests

| Proof | Result |
|---|---|
| Anti-vacuity: `eligible=0` on a B1 type | Evaluated at 0/5/10/11 against floor 11 → **FAIL, FAIL, FAIL, PASS**. The guard fails closed on an empty population. |
| Fixture audit: a removed floor | With `town_terms` floor set to 20 against 19 actual, the run reported `town_terms 19 (floor 20) SHORTFALL` and exited non-zero with a named message. |
| Empty-population gate, real run | With no fixtures, `page` reported `sample-page` as a genuine missing EN translation — proving the gate is not merely warning. |
| Stage gates, real run | The leisure-description stage refused three records as "PT source changed" and its gate FAILed, which is how the fixture/seeder conflict was found. |
| Job landing gate | The retired job stage reported `page pair link broken` with `extra_failures=1` — a real, correctly-caught defect. |
| `git diff --check` | Detected and fixed a trailing blank line in `ci.yml`. |

## 11. Regression comparison

| | Before | After |
|---|---|---|
| Failing in-process suites | 3 | **0** |
| Failing assertion count | 16 | **0** |
| Failing script-contract | 0 | 0 |
| Failing acceptance suites | 1 (TIMEOUT) | **0** |
| Assertions passed | 4087 | **4146** |

The diff of the failing lists is **empty**: every previously failing suite now
passes. The three baseline failures were all developer-database drift
(52 PT guides vs 49 EN pairs; untranslated `post_status` state; a REST-language
mismatch) and vanished on a correctly seeded site.

## 12. Known pre-existing failures

**None.** On a correctly provisioned database there are no failing suites and no
failing assertions in any layer.

## 13. Limitations

1. **No GitHub Actions dispatch run was performed.** Two consecutive green runs
   were produced locally with the job's exact step sequence, but the required
   *hosted* runs need this work pushed, which is forbidden. The
   `workflow_dispatch` condition is unchanged. This is why §2 defers promotion.
2. **Timing was not measured.** Job, bootstrap and fixture durations were not
   instrumented; §26 of the task asks for them and they are available only from a
   real Actions run.
3. **The leisure archive-order normalisation is a fixture-set concern.** It
   rewrites `post_date` on 242 ephemeral records so one card leads page 1. It is
   idempotent and confined to the CI database, but it is a normalisation, not a
   rendering rule, and is documented as such at the call site.
4. **The course-provider seeder makes best-effort external logo requests.**
   That is pre-existing behaviour of a maintained script. A network failure
   degrades to the theme placeholder and cannot turn the suite red, because no
   acceptance row asserts on a logo — but it does make bootstrap duration
   network-dependent.

## 14. Evidence paths

`docs/evidence/2026-09-29-stage-p/`:

| File | Proves |
|---|---|
| `run-1-tests.txt` | Full harness output, run 1 — 62/62, 4146 assertions, ALL TESTS PASSED |
| `run-2-tests.txt` | Full harness output, run 2 — byte-identical results |
| `run-1-bootstrap.txt` | Fixture bootstrap on a fresh database — all 17 audit checks OK, 151 created, 0 errors |
| `run-2-bootstrap.txt` | Same sequence on a *second* fresh database — identical counts |
| `permanent-gates.txt` | 7/7 gates, AGGREGATE: PASS, 0 violations, 0 blocked |
| `lint.txt` | syntax clean, no new PHPCS violations, PHPStan clean |

## 15. Documentation updated

- `tests/fixtures/README.md` — budget, inventory and rules for the Stage P
  dataset. `_Last verified: 2026-09-29 by Stage P — CI readiness_`
- `scripts/README.md` — the orchestrator added to the script inventory, which
  `verify-script-conventions.py` requires. `_Last verified: 2026-09-29 by Stage P — CI readiness_`

**Not updated, deliberately**: `docs/testing.md` and `AGENTS.md` still describe
the `integration` job as blocked on fixtures. That text becomes inaccurate the
moment this work is committed, and it should be corrected **together with** the
commit — at which point the promotion decision in §2 is made and the wording
changes to match.

## 16. Rollback / recovery

- All work is uncommitted, so `git checkout -- .` plus removing the five added
  files reverts it completely.
- Nothing outside the working tree changed: no production write, no deploy, no
  database dump, no remote mutation.
- The only shared-state change is the local `docker compose` image, which is
  rebuilt on demand.

## 17. Final status

**Final status: `PASS WITH LIMITATION`**

Every applicable gate passed with real, reproducible numbers from two fresh
databases, and no required verifier was unavailable *locally*; the limitation is
that the two mandatory **hosted** GitHub Actions runs could not be performed
without committing, which the task forbids.

_Stage P — CI readiness_
