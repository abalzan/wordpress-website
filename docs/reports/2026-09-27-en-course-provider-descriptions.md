# Report — STAGE 8: English descriptions for the `/en/cursos` course providers

| | |
|---|---|
| **Stage / task name** | STAGE 8 — `en-course-provider-description` |
| **Date** | 2026-09-27 |
| **Author / agent** | Cline |
| **Branch** | `i18n` |
| **Start SHA** | `53f178185bbcc1cdbc1c1a36c724dff5ba6e9030` |
| **Final SHA** | *(working tree; not committed)* |
| **Working tree at finish** | dirty — only the files listed in §3, all of them this stage's own work |

Plan: [`docs/reports/2026-09-27-en-course-provider-descriptions-plan.md`](2026-09-27-en-course-provider-descriptions-plan.md)

## 1. Scope completed

`/en/cursos` now renders an **English** description on all **10** course-provider
cards instead of Portuguese. `FETCH Courses` renders:

> Find training and continuing education courses throughout Ireland, including apprenticeships, traineeships, PLC courses, adult education and other FET opportunities.

Delivered through the **existing** shared engine and the **existing** data+config
consumer, as one new stage on `conexao-translation-rollout` operated by
`conexao-en-translation` — no second translation lifecycle, no revived retired
plugin, no new script, no new admin screen.

Delivered: the 10-row authored English dataset, the
`en-course-provider-description` stage adapter/config, the minimal theme read path
(`conexao_provider_card_excerpt()`), the focused in-process suite, the four HTTP
acceptance rows, and the documentation set.

## 2. Scope NOT completed

- **Browser (desktop/mobile) verification was not performed** — no browser
  automation tooling is available in this environment. Real HTTP verification was
  performed instead (§9). This is the reason for `PASS WITH LIMITATION`.
- **No production action.** Deliberately out of scope (§8).
- **No `_provider_category` translation.** Explicitly a non-goal: it is a filter
  value, a separate taxonomy question.
- **Unrelated pre-existing i18n failures were not fixed** (§12) — fixing them would
  be out of scope and would be scope creep.

## 3. Files added / modified / deleted

**3 added, 12 modified, 0 deleted.** `wp-content/` changes are called out below.

| Path | Change | Note |
|---|---|---|
| `wp-content/plugins/conexao-en-translation/includes/course-provider-description-data.php` | **added** | `wp-content/`. Versioned dataset v1: **10 rows** keyed by PT slug, each `{pt_source, pt_title, en_description}`. |
| `wp-content/plugins/conexao-en-translation/includes/course-provider-description-stage.php` | **added** | `wp-content/`. Stage adapter + declarative config. **No lifecycle code**; the engine file is untouched. |
| `wp-content/themes/conexao-br-irlanda/tests/test-course-provider-card-excerpt-language.php` | **added** | `wp-content/`. 45 assertions (§9). |
| `wp-content/plugins/conexao-en-translation/conexao-en-translation.php` | modified | `wp-content/`. Require the 2 new files; register the stage; header version `1.3.0 → 1.4.0`. |
| `wp-content/plugins/conexao-en-translation/includes/stage-config.php` | modified | `wp-content/`. `conexao_en_translation_stage_ids()` gains `en-course-provider-description`. |
| `wp-content/themes/conexao-br-irlanda/inc/i18n/fallback.php` | modified | `wp-content/`. Adds `conexao_provider_card_excerpt()` (additive; no existing function touched). |
| `wp-content/themes/conexao-br-irlanda/template-parts/provider-card.php` | modified | `wp-content/`. **One** expression changed: `get_the_excerpt()` → `conexao_provider_card_excerpt( $provider_id )`, inside the same `wp_trim_words( …, 20, '...' )` + `esc_html()` pipeline. |
| `tests/acceptance/matrices/routing.json` | modified | +4 rows (additive; 68 → 72). |
| `docs/plugins/conexao-en-translation.md` | modified | Stage 8 section; the lifecycle-metadata region was **regenerated**, not hand-edited. |
| `docs/plugins/conexao-translation-rollout.md` | modified | `stage_conflict` now documents its second consumer. |
| `docs/themes/conexao-br-irlanda.md` | modified | `provider-card.php` language-awareness. |
| `docs/content-model.md` | modified | `_provider_excerpt_en` under §Course Provider. |
| `docs/architecture.md`, `docs/project-inventory.md`, `docs/plugins/README.md` | modified | **Generator output only** — `generate-registry-docs.php --write` after the version bump. |

## 4. Runtime impact

WordPress behaves differently in exactly one place: **on an EN request, the
course-provider card description comes from `_provider_excerpt_en` instead of the
PT `post_excerpt`.** Nothing else changes. Verified how:

- the PT path returns the literal `get_the_excerpt()` value, asserted in-process;
- `/cursos` is **byte-for-byte identical** before and after the apply (SHA-256
  `1b7280090bca…` on both captures, §14) — the strongest available proof that PT
  behaviour is untouched;
- the EN path is asserted over the real card template part in the in-process suite.

## 5. Content / data impact

- Records **created: 0**. Records **updated: 10** (one post-meta field each).
  Records **deleted: 0**.
- Match strategy: **stable-id (PT slug)** for all 10; 0 slug/title fallbacks.
  Local post IDs are never used as identity.
- The **only** field written is `_provider_excerpt_en`. No `wp_insert_post()` path
  exists in the stage, no taxonomy, no option, no rewrite rule.

## 6. Polylang impact

- **PT immutability: `pt_drift = 0`.** Asserted two ways: the engine's own
  `assert_no_pt_drift()` gate, and an independent before/after JSON snapshot of all
  10 records (title, slug, content, excerpt, status, date, author, menu order,
  thumbnail, the four taxonomy assignments, all six `_provider_*` meta keys, the
  Polylang language and the EN translation link). The two snapshots are
  **byte-identical** — same SHA-256 `c875ab01f5b7a77a…`.
- **EN records created: 0.** `course_provider` remains a B2 post type with **0**
  linked EN translations, before and after. `conexao_b2_post_types()` is unchanged.
- **No duplicate identities:** 10 records, 10 distinct slugs, 0 EN records.
- **Completeness gate unchanged:** `course_provider: eligible 10, translated 0,
  allowlisted 10, missing_en 0, malformed 0` — identical before and after, and the
  permanent `translation_completeness` gate still **passes**.
- **This stage's own numeric gate:** `eligible PT = 10, with EN = 10,
  missing_en = 0, conflicts = 0, PT drift = 0` → **PASS** (was `missing_en = 10`

## 7. Route / HTTP impact

No route, redirect or sitemap change. Verified over real HTTP: `/cursos/` → 200,
`/en/cursos/` → 200, `/en/cursos/?categoria=formacao-profissional` → 200. On
`/en/cursos/` the canonical stays self-referential (`/en/cursos/`) and the
documented `pt-BR ↔ en` hreflang pair plus `x-default` are unchanged.

## 8. Production actions

| Action | Performed? | Detail |
|---|---|---|
| Production write | **no** | |
| Deploy / upload | **no** | |
| Plugin activation | **no** | |
| Content or DB mutation | **no** (local Docker only) | |

Production is WordPress.com (no SSH, no WP-CLI, no filesystem, no database). The
stage ships as a plan + dataset; a maintainer operates it in wp-admin (Tools →
**Translation Rollouts** → Preview → Apply, or
`run-en-translation.php --only=course-provider-description`). **Not performed
here, and not authorised by the task.**

| `docs/evidence/2026-09-27-en-course-provider-descriptions/` | **added** | 14 evidence files (§14). |
| `docs/reports/2026-09-27-en-course-provider-descriptions-plan.md` | **added** | The plan. |

## 9. Verification commands and results

| Command | Exit | Result |
|---|---|---|
| `run-en-translation.php --dry-run --only=course-provider-description` | 0 | 10 would-create, **0 conflicts, 0 PT-drift, 0 writes**; gate correctly `missing EN=10` (pre-apply) |
| `run-en-translation.php --apply --only=course-provider-description` | 0 | created=10; **GATE PASS: missing EN=0, conflicts=0, PT drift=0** |
| `run-en-translation.php --apply` (2nd run) | 0 | **created=0, updated=0, skipped=10** → idempotent |
| `tests/test-course-provider-card-excerpt-language.php` | 0 | **45 passed, 0 failed** |
| `./scripts/run-tests.sh --acceptance` | 0 | **3 suites, 3 passed, 0 failed**; routing matrix **75** passed (71 before + 4 new) |
| `python3 scripts/verify-permanent-gates.py` | 1 | **7 gates, 6 passed, 1 failed; 86 passed / 1 failed; 1 violation, 1 pre-existing, 0 new** |
| `php scripts/generate-registry-docs.php --check` | 0 | `14 plugins validated, 23 generated regions current (zero writes)` |
| `php -l` on all 6 changed/new PHP files | 0 | no syntax errors |

### Numeric test results

**In-process PHP (this stage's suite): 1 suite, 45 assertions passed, 0 failed.**

**Full suite `./scripts/run-tests.sh` (exit 1, from the 12 pre-existing failures):**

- In-process PHP: **56 suites total, 45 passed, 11 failed**
- Assertions: **3555 passed, 44 failed**
- Script-contract: **6 suites total, 5 passed, 1 failed**
- HTTP acceptance: **3 suites total, 3 passed, 0 failed**

The aggregate exit code is 1 **only** because of failures that pre-date this
change; the failing set is byte-identical to the recorded baseline (§11).

### HTTP acceptance

**3 suites, 135 assertions passed, 0 failed** (guides-en 18, release 42, routing
75). The 4 new rows are `en-cursos-fetch-card-excerpt-is-english`,
`en-cursos-no-portuguese-card-excerpt-leak`,
`pt-cursos-card-excerpt-stays-portuguese` and
`en-cursos-canonical-and-hreflang` in `tests/acceptance/matrices/routing.json`.

### Static analysis

- **PHP syntax:** `php -l` clean on all changed/new PHP files.
- **PHPStan / PHPCS:** **not run** — `./scripts/lint.sh` requires the Composer dev
  toolchain, which is not installed in this environment. Recorded as a
  limitation, not as a pass.

### Script-contract results

`./scripts/run-tests.sh --scripts` runs inside the aggregate command: **6 suites,
5 passed, 1 failed** — the single failure is `verify-i18n-freshness.py`, proven
pre-existing in §12.

### Release / build results

**not in scope** — no release was requested and no deploy is authorised.

## 10. Failure proofs / negative tests

The focused suite proves the verifier can fail; each negative was exercised and
observed, not merely asserted in theory.

| Proof | Result |
|---|---|
| PT source drift is refused | A record whose `post_excerpt` differs from the authored `pt_source` yields a non-empty `stage_conflict`, `en_id = 0`, and **nothing is written** |
| Drift normalisation is narrow, not a blanket pass | entity `&#8217;` ≡ `’`, en/em dash ≡ `-`, whitespace runs collapse → equal; but a changed word, a case change and an accent change all still compare **different** |
| A record with no PT source is not translated | an empty `expected_source` disables the guard rather than faking a conflict, so the stage never invents a translation |
| A valid existing EN description is not clobbered | re-writing an identical description is a **no-op** returning `true`; the stored bytes are unchanged |
| Remove is safe | `remove_en()` deletes **only** `_provider_excerpt_en`; the PT excerpt and title are byte-identical afterwards |
| Idempotence | second full `--apply` → `0 created, 0 updated, 10 skipped` |
| Dry-run writes nothing | after the dry-run, `SELECT COUNT(*) … meta_key='_provider_excerpt_en'` = **0** |

## 11. Regression comparison

Identical environment, `./scripts/run-tests.sh` before vs after.

| | Before (baseline, recorded) | After |
|---|---|---|
| In-process PHP suites | 55 total, 44 passed, **11 failed** | 56 total, 45 passed, **11 failed** |
| Assertions | 3510 passed, 44 failed | **3555 passed, 44 failed** |
| Script-contract | 6 total, 5 passed, 1 failed | 6 total, 5 passed, 1 failed |
| HTTP acceptance | 3 suites, 3 passed, 0 failed | 3 suites, 3 passed, 0 failed (routing 71 → 75) |
| Permanent gates | 6/7 (i18n_freshness fail) | 6/7 (same failure, 0 new) |

**The diff of the failing list is empty** (verified by `diff` of the two harness
failure lists). The same 11 in-process suites and the same 1 script-contract suite
fail before and after; the new suite adds **45 passing assertions and 0 failures**.
Total assertions rose by exactly 45 — the new suite — and the failure count is
**unchanged at 44**. Every failure pre-existed this change.

## 12. Known pre-existing failures

**12 items, none introduced by this change.** Proven for the one that matters
(i18n_freshness) by re-running with this change **fully stashed**:

```
$ git stash push --include-untracked && python3 tests/scripts/verify-i18n-freshness.py
FAIL: conexao-br-irlanda: …pot is older than …/inc/queries.php by 66437s
8 passed, 1 failed — 1 pre-existing, 0 new
```

It is caused by `inc/queries.php` (last committed in `61131a4`, untouched here)
being newer than the `.pot` catalogue. `git status` confirms `queries.php` is not
modified by this change.

The remaining 11 in-process failures are local **content-data** conditions
unrelated to this task: `conexao-event-importer` (`test-error-handling`,
`test-ivvcc-importer`, `test-town-sanitization`) and theme suites
(`test-blog-en-translation`, `test-event-location-filters`,
`test-header-menu-selection`, `test-leisure-related-events`,
`test-stage32-bilingual`, `test-stage33-bilingual`, `test-stage41-rest-language`,
`test-stage45-pages`). They fail identically at the pre-change baseline. **Out of
scope; not suppressed.**

## 13. Limitations

1. **No browser verification** (desktop/mobile, console errors, layout). No
   browser automation is available in this environment. Phase 11 was therefore
   **not tested**, not passed. Real HTTP verification was performed instead and
   does prove the served markup, but it does not prove rendering, wrapping or
   JavaScript behaviour.
2. **PHPStan and PHPCS were not run** — the Composer dev toolchain is not
   installed here, so `./scripts/lint.sh` cannot run. CI is authoritative. PHP
   syntax **was** checked and is clean.
3. **No production verification**, by design (§8).

## 14. Evidence paths

`docs/evidence/2026-09-27-en-course-provider-descriptions/`

| File | Proves |
|---|---|
| `dry-run.txt` | the pre-apply plan: 10 would-create, 0 conflicts, 0 writes |
| `apply.txt` | the apply and the passing gate (`missing EN=0`) |
| `apply-second-run.txt` | idempotence: `0 created, 0 updated, 10 skipped` |
| `pt-snapshot-before.json` / `pt-snapshot-after.json` | PT immutability — byte-identical, same SHA-256 |
| `http-cursos-before.html` / `http-cursos-after.html` | `/cursos` byte-identical (same SHA-256) |
| `http-en-cursos-before.html` / `http-en-cursos-after.html` | the defect, then the fix, on the English archive |
| `inprocess-course-provider-card-test.txt` | 45 passed / 0 failed |
| `http-acceptance.txt` | 3 suites, 135 assertions, 0 failed |
| `permanent-gates.json` | the 7 permanent gates with exact numbers |
| `baseline-run-tests.txt` | the pre-change baseline used in §11 |

## 15. Documentation updated

| Document | Change |
|---|---|
| `docs/reports/2026-09-27-en-course-provider-descriptions-plan.md` | the plan (added) |
| `docs/plugins/conexao-en-translation.md` | Stage 8 section; lifecycle-metadata region regenerated |
| `docs/plugins/conexao-translation-rollout.md` | `stage_conflict` second consumer |
| `docs/themes/conexao-br-irlanda.md` | `provider-card.php` language-awareness |
| `docs/content-model.md` | `_provider_excerpt_en` |
| `docs/architecture.md`, `docs/project-inventory.md`, `docs/plugins/README.md` | generator output only |

`AGENTS.md` and `plugins.json` were **not** hand-edited: the plugin set, load
order, mounts and class did not change, so `--check` reports zero drift.

## 16. Rollback / recovery

```bash
php scripts/run-en-translation.php --remove --apply --only=course-provider-description
```

Deletes only `_provider_excerpt_en` on the 10 PT records and re-asserts PT
immutability (`allow_remove: true`). `/en/cursos/` then returns to the approved B2
fallback automatically. The theme helper is a pure addition and is inert without
the field, so it can stay in place. No production rollback is needed because
nothing was applied there.

**What rollback does not cover:** a PT edit made by someone else in the meantime
— the PT-drift guard reports that as a failure rather than silently reverting it.

## 17. Final status

**Final status: PASS WITH LIMITATION**

The work is complete and every executed verifier passed with real numbers, but
**browser verification (Phase 11) and PHPStan/PHPCS could not be run** in this
environment, so `PASS` would be a claim I cannot back. The one failing permanent
gate is **proven pre-existing** and was deliberately left failing rather than
suppressed.

_Last verified: 2026-09-27 by STAGE 8 — the EN course-provider description rollout_

| A real regression is caught | the suite failed **twice** on first run (10/11 instead of 10/10, and a whitespace case) — proving it genuinely fails; both were test-harness defects (the suite's own temp fixture being counted, and a `\t` inside single quotes), fixed in the test, **not** by relaxing any assertion |

  / FAIL before the apply).
