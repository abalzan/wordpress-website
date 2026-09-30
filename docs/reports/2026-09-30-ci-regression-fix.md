# CI regression fix — 2026-09-30

**Baseline CI run:** [36740739162](https://github.com/abalzan/wordpress-website/actions/runs/36740739162)
**Baseline HEAD:** `5820edec54624affdabf56a3dd011b67b60e0c71` (branch `i18n`)
**Last known-good CI:** `a4cd5f65` (2026-09-29, all three jobs green). The four
commits `33114c6` → `5820ede` had **no CI run** — the newsletter shared-slug
work, the guide retirement and the re-key first met the blocking integration
job at `5820ede`, which is the run fixed here.

## Baseline

Evidence: `docs/evidence/2026-09-30-ci-regression-fix/00-*` (the failed run's
own log and suite summary).

| Job | Step | Result |
|---|---|---|
| Release integrity (manifest + allowlist) | all | **PASS** |
| Static quality | `./scripts/lint.sh` | PASS |
| Static quality | `ShellCheck` | **FAIL** — SC2016 (info) `scripts/build-temporary-plugins-zip.sh:76` |
| Integration | Run the shared test harness | **FAIL** |
| Integration | Stage L — permanent invariant gate summary | **FAIL** (consequence) |
| Integration | HTTP acceptance suites | PASS |

Initial failing state: **4 failing PHP suites, 11 failing assertions**

1. `plugin/conexao-en-translation/test-shared-slug-newsletter.php` — 35 passed, **2 failed**
2. `theme/conexao-br-irlanda/test-en-jobs-shared-slug.php` — 33 passed, **6 failed**
3. `theme/conexao-br-irlanda/test-guide-en-translation.php` — 624 passed, **2 failed**
4. `tests/lib/permanent-gates.php` (`translation_completeness`) — 22 passed, **1 failed** + 2 violations (2 pre-existing, 0 new)

Bootstrap: `created this run: 151` — the first run against that database, so
the failures are deterministic fixture/test-contract defects, not residue.

## Root causes

### 1. ShellCheck (SC2016) — genuine defect introduced by `5820ede`

The new `scripts/build-temporary-plugins-zip.sh` passes PHP code to `php -r`
in single quotes and reads `$argv` inside it. ShellCheck correctly reports
SC2016 (info): expressions do not expand in single quotes. The single quotes are
**intentional** — `$argv` is PHP's argument array and no shell expansion is
wanted — but no exemption was declared. Not pre-existing (the file is new in
`5820ede`); not a false positive; classified as a genuine defect of the
"intentional construct without its exemption" class.

### 2. Newsletter shared-slug suites — test contract assumed a pre-pair database

The synthetic CI database runs the real `en-page` stage of the **shared**
translation-rollout engine (bootstrap step 6), which authors the EN newsletter
page as a shared-slug B1 page: PT `8` ↔ EN `509`, both slug `newsletter`,
correctly linked, no `newsletter-2`. Both suites were written against the
**pre-pair production state** ("today no EN `newsletter` record shares the
slug") and had never run in CI before — this was the first blocking run:

- `test-en-jobs-shared-slug.php`:
  - capped real shared-slug pairs at 2 while its own section 8 declares
    `newsletter` as the THIRD declared shared-slug page, so the legitimate
    newsletter pair was reported as "unexpected" (`shared_slug:unexpected_new_pair`);
  - created a SECOND EN page on the slug (id 1081) beside the real one (509) —
    exactly the duplicate EN identity the engine's own guard refuses — then
    expected the resolver to bind to its own duplicate; with two EN holders
    the resolver's "last EN in scan wins" binding picked 509 instead;
  - the sever / two-PT negatives ran against that ambiguous two-EN state;
  - the survivor check expected only the PT page to hold the slug, ignoring
    the real EN record.
- `test-shared-slug-newsletter.php`:
  - the "drifted slug, repairable `update`" case relied on pre-existing
    `newsletter-2` drift that does not exist in the synthetic database (the
    stage created the EN record with the authored slug), so the
    `en_slug_matches` assertion could not hold;
  - the survivor check expected only the PT page, ignoring the real EN record.

The production/shared implementation (resolver, scoped permit, fail-closed
guard, engine) was **not** defective: every failing assertion encodes a
pre-pair assumption, and the HTTP acceptance suite — which exercises the real
routing — passed throughout. No implementation change was needed or made.

### 3. Guide translation tests — stale synthetic fixture dataset

On 2026-09-30 the authored EN guide manifest changed (retirement + re-key,
`6bc4a6b`/`5820ede`) — one row retired (its PT source permanently deleted;
recorded in
`wp-content/plugins/conexao-en-translation/includes/exclusions-data.php`,
classification `NO_REAL_PT_SOURCE`), one row re-keyed
`carteira-de-motorista-2` → `carteira-motorista-brasileiros` (the real
replacement PT guide, production id 25031). But
`scripts/data/ci-fixture-guides.php` still created PT guides under both
retired slugs. Result: 52 eligible PT guides, 50 linked EN, missing exactly
`carteira-de-motorista-2` and
`learner-permit-theory-test-irlanda-cnh-brasileira` — the synthetic site was
resurrecting a **permanently deleted** PT source and seeding the retired
pre-re-key identity. Stale fixture data, not a stale expectation: the
completeness invariant itself was correct.

### 4. Completeness gate — consequence of 3

`translation_completeness` failed on `post_type:guide:missing_en` (the same
two records) — a data-driven consequence, not a gate defect. The generic
translation-completeness invariant was NOT weakened; the retirement/re-key
state is encoded in the authoritative fixture dataset, matching
`docs/plugins/conexao-en-translation.md`.

## Fixes

| File | Change | Why |
|---|---|---|
| `scripts/build-temporary-plugins-zip.sh` | line-scoped `# shellcheck disable=SC2016` with justification | `$argv` is PHP's argv, single quotes deliberate; uses the repo's established per-line inline-exemption mechanism (`scripts/i18n-make-pot.sh:85`), no broad exclusion |
| `scripts/data/ci-fixture-guides.php` | removed `learner-permit-theory-test-irlanda-cnh-brasileira`; re-keyed `carteira-de-motorista-2` → `carteira-motorista-brasileiros`; header documents both decisions | align the synthetic dataset with the retired/re-keyed manifest; never resurrect a deleted PT source; 50 slugs, all still a subset of the authored manifest, all 13 categories still covered |
| `tests/fixtures/README.md` | fixture inventory row: 50 PT guides + exclusion/re-key note | keep the fixture inventory in sync |
| `wp-content/themes/conexao-br-irlanda/tests/test-en-jobs-shared-slug.php` | section 7: declared-set check (`blog`, `empregos`, `newsletter`, no undeclared pair); section 8: state-aware pre-pair/post-pair, no duplicate EN fixture, the real EN record is never deleted, survivors expect the real pair | the approved policy IS a third declared shared-slug pair; the gate must hold in both the pre-pair production state and the post-pair synthetic state without creating the duplicate EN identity the engine forbids |
| `wp-content/plugins/conexao-en-translation/tests/test-shared-slug-newsletter.php` | drift created deterministically (rename linked EN to `newsletter-2`, assert the repairable `update` classification, restore the authored slug under the scoped permit, assert the restore); survivors expect PT + the real linked EN | prove (not assume) the drifted case in both states; recognise the real EN record as site content, not a removable fixture |

No production code was changed. No gate, invariant, allowlist or exclusion was
weakened. The resolver, the scoped permit, the engine and the fail-closed
guards are untouched. No second translation engine or lifecycle was created.

## Test results

Verified by real CI runs of `.github/workflows/ci.yml` on the fix branch
(this sandbox has no Docker and no PHP; GitHub Actions is the executor of
record — `PASS` claims below are the workflow's own output, not local runs).

**Fresh run 1 — [36756688155](https://github.com/abalzan/wordpress-website/actions/runs/36756688155)**
(SHA `80ce75a`, fresh ephemeral Docker database, `created this run: 150`):

- In-process PHP suites: **63/63 PASS** (plus the 2 per-language variants, PASS)
  — harness aggregate line: **`Assertions: 4204 passed, 0 failed`**
  (baseline aggregate: `4177 passed, 11 failed`, 4 failing suites)
- Script-contract gates: PASS (inside the same aggregate)
- Stage L permanent gates: **7/7 PASS** — `Gates: 7 total, 7 passed, 0 failed`,
  `Assertions: 89 passed, 0 failed`, **AGGREGATE: PASS**
  (`translation_completeness` now `23 passed, 0 failed, 0 violation(s)`,
  289 allowlisted by documented policy; the previously failing
  `post_type:guide:missing_en` passes with `missing EN=0`)
- `en-guide` stage: `eligible PT=50 with EN=50 missing EN=0 conflicts=0 PT drift=0`
- HTTP acceptance suites: PASS (`ALL TESTS PASSED`)
- ShellCheck: PASS (no findings); `./scripts/lint.sh` PASS
- Release integrity (manifest + allowlist): PASS
- Workflow conclusion: **success** (all three jobs)
- The four previously failing suites, individually:
  - `test-shared-slug-newsletter.php` — **39 passed, 0 failed** (was 35/2)
  - `test-en-jobs-shared-slug.php` — **39 passed, 0 failed** (was 33/6)
  - `test-guide-en-translation.php` — **638 passed, 0 failed** (was 624/2)
  - `translation_completeness` gate — **23 passed, 0 failed** (was 22/1)

## Determinism

Two independent fresh-database runs were executed (run 1 above; fresh run 2
appended below). Each CI integration job builds the ephemeral WordPress
database from nothing, runs the committed bootstrap (`created this run: 150`
on both) and then the complete shared harness. The two runs' test summaries,
gate summaries and fixture counts are identical on every metric the
determinism contract covers (63/63 suites, 4204/4204 in-process assertions,
7/7 gates, 89/89 gate assertions, `AGGREGATE: PASS`, `ALL TESTS PASSED`,
150 records) — see the comparison table under "Fresh run 2".

Test-order dependence: the four previously failing suites run in the fixed
`run-tests.sh` order against the same fresh database as the baseline run, and
every other suite result is unchanged between baseline and fix — the failures
were data/test-contract defects, not order defects. The newsletter suites now
restore every piece of state they touch (slug, translation links), which the
restored-state assertions prove rather than assume.

## Regression comparison

| Check | Baseline (36740739162) | After fix (both fresh runs) |
|---|---|---|
| In-process PHP suites | 59 pass / 4 fail (63 total) | **63 pass / 0 fail** |
| In-process assertions (aggregate line) | 4177 passed, 11 failed | **4204 passed, 0 failed** |
| Stage L gate rows | 6 pass / 1 fail, 2 pre-existing violations | **7 pass / 0 fail, 0 violations** |
| Stage L gate assertions | 88 passed, 1 failed | **89 passed, 0 failed** |
| HTTP acceptance | PASS | PASS (unchanged) |
| Release integrity | PASS | PASS (unchanged) |
| ShellCheck | FAIL (SC2016) | PASS |
| Registry drift | 0 | 0 (untouched; `plugins.json` unchanged) |
| Documentation drift gate | PASS | PASS |
| i18n freshness gate | PASS | PASS (POT untouched) |
| Bootstrap records created | 151 | 150 (one synthetic PT guide fewer — the retired deleted source is no longer resurrected) |

No unrelated test changed. No pre-existing failure was marked as passing: the
baseline run's last known-good predecessor (`a4cd5f65`) was fully green, so
all 11 failing assertions are new regressions and each is accounted for by the
four root causes above.

## Repository integrity

- `.env`: **unchanged** (never touched)
- `git diff --check`: clean (no whitespace errors)
- Registry drift: 0 — `plugins.json` not modified; documentation drift gate PASS
- Working tree at completion: clean, all work committed and pushed on
  `cline/f6f9r88a`

## Fresh run 2 (appended after completion)

**Fresh run 2 — [36757514831](https://github.com/abalzan/wordpress-website/actions/runs/36757514831)**
(SHA `9c96028`, second fresh ephemeral Docker database, run independently of
run 1 by a later push of the same content):

- Workflow conclusion: **success** (all three jobs)
- In-process PHP suites: **63/63 PASS**, aggregate `Assertions: 4204 passed, 0 failed`
- Stage L gates: `Gates: 7 total, 7 passed, 0 failed`, `Assertions: 89 passed, 0 failed`,
  `AGGREGATE: PASS`; HTTP acceptance `ALL TESTS PASSED`
- Bootstrap: `created this run: 150`

**Determinism comparison (run 1 vs run 2), extracted from both full logs:**

| Metric | Run 1 (36756688155) | Run 2 (36757514831) | Identical |
|---|---|---|---|
| In-process PHP suites (pass/fail) | 63 / 0 | 63 / 0 | yes |
| In-process aggregate assertions | 4204 passed, 0 failed | 4204 passed, 0 failed | yes |
| Stage L gate rows | 7 passed, 0 failed | 7 passed, 0 failed | yes |
| Stage L gate assertions | 89 passed, 0 failed | 89 passed, 0 failed | yes |
| `AGGREGATE` | PASS | PASS | yes |
| HTTP acceptance | ALL TESTS PASSED | ALL TESTS PASSED | yes |
| Bootstrap `created this run` | 150 | 150 | yes |

Both fresh-database runs are identical on every summary metric the determinism
contract covers. The raw logs are
`docs/evidence/2026-09-30-ci-regression-fix/01-*` and `02-*`.

## Final status

**PASS — CI regression fixed and full verification green**

- Workflow [36756688155](https://github.com/abalzan/wordpress-website/actions/runs/36756688155) (fix commit): **success** — release integrity, static quality (lint + ShellCheck), integration (Docker WordPress + `run-tests.sh` + Stage L gates + HTTP acceptance) all green on a fresh database.
- Workflow [36757514831](https://github.com/abalzan/wordpress-website/actions/runs/36757514831) (independent second fresh run): **success**, byte-identical summaries.
- All 11 previously failing assertions are fixed with root causes identified; no test, gate or invariant was weakened; no production code changed; `.env` untouched; registry and documentation drift zero.

**Limitation (PASS, with provenance):** this sandbox has no Docker and no PHP,
so `./scripts/run-tests.sh`, `./scripts/lint.sh`, `./scripts/verify-release.sh`,
`python3 scripts/release-manifest.py --verify` and
`php scripts/generate-registry-docs.php --check` could not be executed locally.
Their equivalents were executed by the repository's own CI workflow — the
same blocking jobs the failing run `36740739162` ran — twice, on two
independent fresh Docker databases: `./scripts/lint.sh` + ShellCheck
(static-quality job), the full `./scripts/run-tests.sh` aggregate including
the Stage L permanent gates, the script-contract gates and the canonical HTTP
acceptance suites (integration job), and the release-manifest/allowlist
verification (release-integrity job). All evidence is the workflows' own
output, captured in `docs/evidence/2026-09-30-ci-regression-fix/`.

Note on the verifier set: `./scripts/verify-release.sh` (local end-to-end
release proof) is a superset wrapper of the registry → build → manifest →
allowlist/hash → determinism → HTTP steps, whose constituent checks all ran
green in CI's release-integrity and integration jobs; it was not run as a
single local command for the sandbox limitation above, and nothing in this
change set touches any input it consumes (the registry, the build scripts'
packaging behaviour and the release manifest are unchanged — the only build
file change is a comment-only ShellCheck exemption).




