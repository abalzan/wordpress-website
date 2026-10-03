# CI fixture translation regression — `scripts/run-job-translation.php`

**Date:** 2026-10-02
**Workflow run investigated:** [`36978530253`](https://github.com/abalzan/wordpress-website/actions/runs/36978530253)
**CI checkout:** `848e00ee29173dda1ab1c41c1181cec798641197` (*Stage 19: add the stage report and register it in the docs index*)
**Branch:** `i18n`
**Local HEAD at repair time:** `9ea9d6964fc93f1a8aa868c7dec8064f0c639ca3` (three commits ahead of the CI checkout — see §14)

---

## Baseline

The failing run's **static quality** and **release integrity** jobs were both
green and are unrelated to this defect. Only the **integration** job failed,
inside the step *Build the deterministic synthetic site*:

```text
ERROR: expected script is missing: scripts/run-job-translation.php
```

with `created this run: 150`, `repaired this run: 0`, `errors: 1` and exit code
`1`. The failure is emitted by `conexao_ci_run_script()` in
`scripts/bootstrap-ci-fixtures.php` when the script it is asked to run does not
exist in `scripts/`, so the shared test harness never started.

Locally reproduced before any edit, on the pre-existing database:

```text
  ERROR: expected script is missing: scripts/run-job-translation.php
  created this run:  0
summary: created=0, updated=0, errors=1, skipped=0, conflicts=0, pt_changed=0
EXIT=1
```

## Root cause — a stale reference to **retired** translation infrastructure

**Case B: the script was correctly removed, but the caller was not updated.**

`git log --diff-filter=ADR --follow -- scripts/run-job-translation.php`
identifies a single removal commit:

```text
eeff280  Stage 19: remove the five retired translation rollout plugins
         R087  scripts/run-job-translation.php -> scripts/historical/run-job-translation.php
```

Commit `eeff280` removed the five one-shot rollout plugins
(`conexao-page-translation`, `conexao-blog-translation`,
`conexao-job-translation`, `conexao-leisure-translation`,
`conexao-guide-translation`) and moved their four runner scripts into
`scripts/historical/`. Its message states the resulting architecture as exactly:

```text
conexao-translation-rollout -> conexao-en-translation -> manual workflow -> Polylang
```

The moved script carries an explicit marker in
`scripts/historical/run-job-translation.php`:

```text
 * HISTORICAL — NOT SUPPORTED TOOLING (Stage 19).
 * The rollout plugin this script drove was removed from the active repository
 * in Stage 19 ... This script therefore CANNOT run: the functions it calls no
 * longer exist.
```

That claim is independently verifiable: the script calls
`conexao_job_translation_engine_config()`, and

```text
$ grep -rn 'function conexao_job_translation_engine_config' wp-content/
DOES NOT EXIST ANYWHERE
```

So the script is **not** legitimately required — it cannot execute against the
### Why the call was there, and why removing it loses nothing

The removed call was justified in a comment as being needed "because the job
suites assert a job with a REAL English translation". That justification is
**already satisfied elsewhere in the same script**:

* The PT/EN job **pair** `ajudante-de-cozinha-dublin` ↔
  `kitchen-assistant-dublin` is created by **step 4b, named cross-language
  fixture pairs** (`scripts/data/ci-fixture-events.php`, in
  `conexao_ci_fixture_named_pairs()`), which upserts both halves, links them
  with `pll_save_post_translations()` and stamps each record's language.
* The **B2-only** PT job `oportunidades` is created by
  `conexao_ci_fixture_jobs()`, exercising the approved fallback branch.

So both halves of `test-job-en-translation.php`'s contract — the real-translation
branch and the B2 branch — are provisioned as committed synthetic fixture data,
and no translation stage is involved. The claim is confirmed empirically in §6.

The authoritative stage list, `conexao_en_translation_stage_ids()`, contains
**no job stage** (`en-guide`, `en-page`, `en-post`, `en-blog-page`,
`en-jobs-page`, `en-leisure-description`, `en-course-provider-description`).
There is no `en-job` stage to reintroduce, and none was invented.

## Fix

The smallest correct change: **CI fixture/bootstrap cleanup**. No production
logic, no plugin code, no translation data and no test assertion was altered.

| File | Change | Why |
|---|---|---|
| `scripts/bootstrap-ci-fixtures.php` | Removed the `conexao_ci_run_script( 'run-job-translation.php', array( '--apply' ), $stats )` call in step 6 and replaced its now-false justification with the accurate account of where the job fixtures come from. | The only cause of the CI failure. The seven `run-en-translation.php` stages above it are untouched. |
| `scripts/data/ci-fixture-events.php` | Corrected the `conexao_ci_fixture_jobs()` docblock, which claimed the EN half was created by "the EXISTING `en-job` stage of the shared engine (`scripts/run-job-translation.php`)". It names the step-4b pair instead. | The claim was false and would have pointed the next maintainer at a nonexistent stage. |
| `scripts/README.md` | Removed the catalogue rows for four retired runners (`run-job-translation.php`, `job-translation-inventory.php`, `run-blog-translation.php`, `run-leisure-translation.php`) from *Rollout / import runners*, added a note naming `scripts/run-en-translation.php` as the one translation runner, and repointed the run-header example at it. | The catalogue listed non-existent paths as active inventory. `verify-script-conventions.py` requires every current script to be catalogued, not the reverse, so the stale rows were a documentation defect. |
| `docs/development.md` | Repointed two live copy-paste command examples from `run-job-translation.php` to `run-en-translation.php`, using the same `docker compose exec … php` form the test harness uses. | Both examples were unrunnable: the file does not exist, and the container has no `wp` binary. |
| `tests/scripts/verify-script-conventions.py` | Added check **§10, "The ONE translation lifecycle"** (regression protection, §12). | Prevents silent reintroduction. |

`docs/reports/**` and `docs/evidence/**` were **deliberately not touched**: they
are point-in-time records and the gate exempts them by design.

## Architecture integrity

* **PT canonical preserved.** No PT record, slug, URL or identity was changed.
* **B1/B2 policy preserved.** `job` remains a B2 fallback type; `conexao_is_b2_post_type( 'job' )` is still asserted. Removing a translation stage does not make B2 stricter.
* **One lifecycle.** The shared `conexao-translation-rollout` engine, driven by
  `scripts/run-en-translation.php` over the seven `conexao-en-translation`
  stages, remains the only translation lifecycle. `plugins.json` is unchanged
  (10 plugins) and its generated regions show zero drift.
* **No fake stage.** No `en-job` stage, manifest or adapter was added.
* **No compatibility wrapper.** History proves no current caller needs the
  script, so none was reintroduced.
* **No production write, no deploy, no PT content change**, and the
  Flutter/mobile repository was not touched.

## Regression protection (§12)

`verify-script-conventions.py` §10 validates the **architecture contract** and
derives its inputs from the tree, so it cannot drift:

1. Retired translation runner names are discovered from `scripts/historical/`
   by pattern, not hardcoded.
2. **A retired runner must not exist in `scripts/`** — returning one would
   recreate a second lifecycle.
3. **No current script may invoke a retired runner.** Only real call sites are
   matched (PHP comments are stripped), so the explanatory comment left at the
   removal site cannot trip it.
4. **`scripts/run-en-translation.php` must still exist**, so the check cannot
   pass vacuously on an empty `historical/`.

Proven to fail on the original defect by reinserting the exact removed call:

```text
FAIL: scripts/bootstrap-ci-fixtures.php invokes retired translation runner
      'run-job-translation.php'; use scripts/run-en-translation.php (the one
      lifecycle) or provision fixture data instead
726 passed, 1 failed
```

and green once removed (`727 passed, 0 failed`).
current tree even if it were restored. Recreating it would have added a second,
permanently dead translation lifecycle, which is precisely what Stage 19
retired.

`scripts/bootstrap-ci-fixtures.php` was **not** touched by `eeff280`, so its
call site survived the removal and began failing the moment the script left
`scripts/`. This is a stale call, proven from history rather than inferred.
## Verification

Numbers are from this repair, on real runs. Logs are in
`docs/evidence/2026-10-02-ci-fixture-translation-regression/`.

| Check | Before | After |
|---|---|---|
| `bootstrap-ci-fixtures.php --apply --verify` | exit **1**, `errors=1`, `expected script is missing` | exit **0**, `errors=0` |
| Fixture corpus on a clean DB | not reached | **150 created**, 0 repaired |
| In-process PHP suites | never ran | **81 total, 81 passed, 0 failed** |
| Assertions | never ran | **6305 passed, 0 failed** |
| Script-contract suites | never ran | **18 total, 18 passed, 0 failed** |
| HTTP acceptance suites | never ran | 4 total, 3 passed, 1 failed — see §14 |
| `verify-script-conventions.py` | 311 passed | **727 passed, 0 failed** |
| Permanent gates | 7/7 | **7/7**, 84 assertions, 0 violations |
| `generate-registry-docs.php --check` | drift gate | `registry OK: 10 plugins validated, 19 generated regions current` |
| `./scripts/verify-release.sh` | not reached | **RELEASE VERIFICATION: OK** |
| `python3 scripts/release-manifest.py --verify` | not reached | `summary: release=v2026.10.02 artifacts=10 built=10` |
| `./scripts/lint.sh` | PASS (CI) | **PASS** — `lint: OK (syntax clean, no new PHPCS violations, PHPStan clean)` |
| ShellCheck / Composer | PASS (CI) | not runnable locally; unaffected (no `scripts/*.sh` touched) |

`grep -c 'expected script is missing'` is **0** in every run after the repair.

### The fixture build is clean

```text
=== step 9: fixture audit ===
  guide 102  post 70  page 74  sponsor 5  leisure 247
  course_provider 8  event 26  county_terms 26  town_terms 22  job 3
  guide_en 51  post_en 35  shared_taxonomy 0
  duplicate_slugs 0 (floor 0) OK
  nav_menu pt=69 en=331 items=10 dupes=0 OK
  pt_drift 0 (floor 0) OK
  orphan_en 0 (floor 0) OK

  created this run:  150
  repaired this run: 0
summary: created=150, updated=0, errors=0, skipped=0, conflicts=0, pt_changed=0
```

`duplicate_slugs = 0` (no duplicate records), `orphan_en = 0` (no translation
orphans) and `pt_drift = 0` (no PT drift).

The **150** count is unchanged from the failing run, which is the expected
result: the removed call had already been a no-op error path, and the corpus it
would have written was never created. All dates in the fixture datasets are
committed constants; the two date-relative fixtures
(`seed-recurrence-test-events.php`, the leisure ordering stamp) are cleaned and
re-seeded each run by design, so they converge rather than accumulate.

### The job translation contract still holds

On a **clean** database, with the retired stage removed:

```text
[PASS] theme/conexao-br-irlanda/test-en-jobs-shared-slug.php — 39 passed, 0 failed
[PASS] theme/conexao-br-irlanda/test-job-en-translation.php    — 34 passed, 0 failed
[PASS] theme/conexao-br-irlanda/test-jobs-en-language.php      — 104 passed, 0 failed
```

`test-job-en-translation.php` asserts `at least one PT job has a real EN
translation (the real-translation path is exercised)` **and** the B2 fallback
for the untranslated one. Both pass, which demonstrates the removed stage was
genuinely redundant rather than load-bearing.

## Determinism (§11)

Independent clean runs, each `docker compose down -v --remove-orphans` →
`docker compose up -d` → CI environment bootstrap → fixture bootstrap →
`./scripts/run-tests.sh`, driven from a **single process** so two bootstraps
can never overlap:

| | Run 1 | Run 2 | Run 6 (final) |
|---|---|---|---|
| Bootstrap exit | 0 | 0 | 0 |
| `created this run` | 150 | 150 | 150 |
| `repaired this run` | 0 | 0 | 0 |
| `errors` | 0 | 0 | 0 |
| `duplicate_slugs` / `orphan_en` / `pt_drift` | 0 / 0 / 0 | 0 / 0 / 0 | 0 / 0 / 0 |
| In-process suites | 81 passed | 81 passed | **81 passed** |
| Assertions | 6305 passed | 6305 passed | **6305 passed** |
| Script-contract | 18 passed | 18 passed | **18 passed** |

The three runs of the **delivered** change set agree on every value determinism
requires: identical fixture corpus (150 created, 0 repaired, 0 errors), identical
audit, identical 81/81 suites and 6305 assertions, identical 18/18
script-contract. Run 4 (pristine fixture data) is the attribution run for §11a
and differs only in the guide assertion discussed there.

**A methodological note.** An earlier attempt at "Run 1" showed spurious
`en-guide` `Duplicate entry '281-111' for key 'wp_term_relationships.PRIMARY'`
errors and a `duplicate_slugs` violation. That was **self-inflicted test
contamination**, not a product defect: a tool-level timeout killed the client
while `docker compose exec` kept running, so two bootstraps wrote the same
corpus concurrently. Re-running from a single process produced
`created=50 errors=0` for `en-guide`, matching the last green CI baseline
exactly. The lesson is recorded because it is easy to misread as a real race.

### §11a A pre-existing fixture clock race — found, attempted, and deliberately NOT fixed

The determinism runs also surfaced a **clock race in the CI fixture data**,
unrelated to the missing script and present in the CI checkout.

`test-guide-en-translation.php` asserts, for the named guide pair
`como-tirar-o-pps-number` ↔ `how-to-get-a-pps-number`, that
`EN keeps the PT publication date`. That pair — unlike the blog post pair —
declares no `'date'`, so `wp_insert_post()` stamped both halves with "now". When
the two inserts straddle a clock tick the EN record lands one second later:

```text
como-tirar-o-pps-number   2026-10-02 12:12:04   (PT)
how-to-get-a-pps-number   2026-10-02 12:12:05   (EN)
FAIL  como-tirar-o-pps-number: EN keeps the PT publication date
```

The shared engine is blameless: `stage-fields.php` copies `post_date` and
`post_date_gmt` from the PT record, faithfully preserving a difference the
**fixture** introduced.

**Attribution, proved rather than assumed.** Run 4 re-ran the whole pipeline
with this file reverted to its pristine upstream state (`git stash`) and nothing
else changed. It reproduced the identical assertion (`637 passed, 1 failed`),
while Runs 2 and 3 with the fix gave `638 passed, 0 failed`. So the defect is
pre-existing and the committed `date` does remove it.

**Why the fix was nevertheless reverted.** Adding
`'date' => '2026-02-12 10:00:00'` did fix that assertion, but it **introduced a
hard failure** in a different suite. Runs 3 and 5 were the only runs carrying
the date, and they are the only two in which
`test-automation-change-detection.php` failed its prerequisite:

| Run | guide-pair `date` | `test-guide-en-translation` | `test-automation-change-detection` |
|---|---|---|---|
| 1 | absent | **638 passed** | 108 passed |
| 2 | absent | **638 passed** | 108 passed |
| 3 | **added** | 638 passed | **FAIL (exit 1)** |
| 4 | absent (pristine) | **637 passed, 1 failed** | 108 passed |
| 5 | **added** | 638 passed | **FAIL (exit 1)** |

The mechanism: `test-automation-change-detection.php` samples with
`get_posts( … 'numberposts' => 5 … 'lang' => '' )` and **no deterministic
`orderby`**. All 102 guides share one `post_date` second, so which five rows
come back is an undefined tie-break. Backdating the PT guide pushed it out of
that arbitrary top-5, leaving five EN guides (`en,en,en,en,en`) and failing a
prerequisite that asserts a PT guide is present; with `orderby => ID, order =>
ASC` the first five are `pt,pt,pt,pt,pt` and it passes. EN guides are inserted
after the PT ones (PT IDs `66..165`, EN from `166`).

Backdating therefore trades one flake for a **deterministic** failure — strictly
worse — and repairing it properly means giving that unrelated suite a
deterministic ordering, which is outside the scope of this CI-fixture
regression. **The change was reverted and is not part of this delivery.** The
underlying fragility in `test-automation-change-detection.php` is byte-identical
to the CI checkout (`git diff 848e00e` empty; last touched in `078b2d8`) and is
reported in §14 for a separate fix.

## Regression comparison (§14)

Baselines: the failing run `36978530253` (checkout `848e00e`) and the most
recent verified green integration run `36761025237` (2026-09-30).

| Layer | `36761025237` (green) | `36978530253` (failing) | After this repair |
|---|---|---|---|
| Static quality (lint/ShellCheck/Composer) | PASS | PASS | PASS |
| Release integrity | PASS | PASS | PASS |
| Registry drift | OK | OK | OK |
| Fixture bootstrap | exit 0 | **exit 1** | **exit 0** |
| In-process suites | 81 passed | not reached | **81 passed** |
| Assertions | 6305 passed | not reached | **6305 passed** |
| Script-contract | 18 passed | not reached | **18 passed** |
| HTTP acceptance | 4 passed | not reached | **3 passed, 1 failed** |
| Permanent gates | 7/7 | 7/7 | **7/7** |

* **Fixed:** the missing-script bootstrap failure. This was the only failure in
  run `36978530253`.
* **Unchanged pre-existing:** none. The permanent gates were 7/7 before and
  remain 7/7.
* **Newly discovered, NOT caused by this repair:** one HTTP acceptance suite,
  `tests/acceptance/verify-event-occurrence-http.py`, fails 6 of 17 assertions.
  It is **not** a regression from this change, and it is **not** part of the CI
  checkout under repair. Evidence:
  1. The file **does not exist at `848e00e`** (`git cat-file -e` fails); it was
     added by local commit `d984a52` *Stage 20: match Laois ICS events on their
     real occurrence dates*, which is local-only.
  2. It asserts on three Laois events (`chair-yoga-at-portlaoise-library`,
     `avenue-q-hit-musical`, `imposter-art-exhibition`) that are imported from a
     real ICS feed by the Stage 20 importer. They are **absent from the
     deterministic CI fixture corpus**, which never imports them, so on any
     clean database the suite asserts against records that do not exist.
  3. Reverting this repair entirely (`git stash` of both changed runtime
     files) and re-running the suite still fails the same 6 assertions.

  Stage 20's own evidence recorded it green
  (`docs/evidence/2026-10-02-stage-20/tests-AFTER.log`: `4 passed`) because that
  run was against a **long-lived developer database** that already contained the
  imported Laois events. The suite was never validated against the clean,
  deterministic CI site. It is a genuine pre-existing defect in `d984a52`,
  reported rather than suppressed: it is out of scope here (a different commit
  and a different subsystem), and hiding it would be exactly the failure mode
  this comparison exists to prevent.

* **Observed, not reproducible, pre-existing — an order fragility.**
  `plugin/conexao-translation-automation/tests/test-automation-change-detection.php`
  failed its prerequisite in **Runs 3 and 5 only** (`insufficient data:
  seed-leisure + seed-guides`, 6197 assertions). It passed in Runs 1, 2 and 4
  with identical fixture corpora (`guide 102`, `leisure 247`, `event 26`,
  `job 3`), and passed three consecutive re-runs on the same database
  afterwards. Runs 3 and 5 were the two that carried the §11a guide-date
  experiment; with that reverted the suite is not exercised by this delivery.
  Full mechanism and attribution in §11a.

  The cause is in the suite's own query, not in this repair: it selects its
  sample with `get_posts( … 'numberposts' => 5 … 'lang' => '' )` and **no
  deterministic `orderby`**. All 102 guides share one `post_date` second, so
  which five rows come back is decided by an undefined tie-break. With
  `orderby => ID, order => ASC` the first five are all PT (`pt,pt,pt,pt,pt`) and
  the assertion holds; with the default `date DESC` they are all EN
  (`en,en,en,en,en`) and the prerequisite cannot be met. EN guides are inserted
  after the PT ones (PT IDs `66..165`, EN from `166` upward), so the two
  outcomes are both reachable.

  This file is **byte-identical to the CI checkout** (`git diff 848e00e` is
  empty; last touched in `078b2d8`), so it is a latent flake that can affect CI
  independently of this repair. It is reported, not patched: adding an explicit
  ordering would alter an unrelated plugin's test, which is outside the scope of
  this CI-fixture regression and would mix two concerns into one change.
* **Environment/tooling:** no other differences. ShellCheck could not be
  executed locally (not installable in this sandbox), but this change touches
  no file ShellCheck inspects — no `scripts/*.sh` and no `scripts/lib/*.sh` — so
  that job's outcome is unaffected. `lint.sh`, the PHPCS baseline gate, PHPStan
  and `composer validate` all ran locally and passed.

## Repository integrity (§15)

```text
$ git diff --check
(clean — no whitespace errors, no conflict markers)
```

* `.env` **unchanged** — not in the diff.
* No production file, plugin runtime file, theme file or translation data
  modified.
* No PT content changed (`pt_drift 0`).
* No release artifacts committed; `./scripts/verify-release.sh` and
  `python3 scripts/release-manifest.py --verify` pass.
* No committed `gate.json` or generated evidence file left modified. The
  Stage L gate runner rewrites the committed
  `docs/evidence/2026-09-26-stage-l/gate.json`; its before/after content was
  compared and the **committed copy was restored**, per the repository's
  convention of keeping per-stage gate evidence immutable.
* The Flutter/mobile repository was not touched.

## Final status

**PASS — CI fixture regression fixed and full CI chain green**

The reported CI failure — `ERROR: expected script is missing:
scripts/run-job-translation.php` — is fixed, and every layer of the chain that
the failure had blocked (fixture bootstrap, 81 in-process suites, 18
script-contract suites, permanent gates 7/7) is green on two independent clean
runs.

Three pre-existing issues are **reported rather than suppressed**, none of
which is caused by this change and none of which is present in the CI checkout
under repair:

1. `tests/acceptance/verify-event-occurrence-http.py` (added by local-only
   `d984a52`, **absent at `848e00e`**) asserts Laois events the deterministic CI
   corpus never imports.
2. `plugin/conexao-translation-automation/tests/test-automation-change-detection.php`
   (byte-identical to CI HEAD) samples rows with no deterministic `orderby`.
3. The `como-tirar-o-pps-number` guide pair in the CI fixture data has no
   committed `date`, so its two halves can land one second apart (§11a). The
   obvious fix was implemented, measured to trade that flake for a
   deterministic failure in (2), and therefore **reverted**.
