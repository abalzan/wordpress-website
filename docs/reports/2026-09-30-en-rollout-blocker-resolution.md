# EN TRANSLATION ROLLOUT — BLOCKER RESOLUTION: MISSING PT SOURCES + `newsletter` SHARED SLUG

| | |
|---|---|
| **Task** | Resolve the two blockers the completed EN translation dry-run reported: 9 authored rows with no current PT source, and the `newsletter` slug collision |
| **Date** | 2026-09-30 |
| **Branch** | `i18n` |
| **Start SHA** | `12a5e6e558b4a3a1eda5977e8c1501b3ed86cc48` |
| **Production target** | `https://conexaobr.ie` — **read-only, `GET` only** |
| **Production writes** | **0** |
| **EN records created** | **0** |
| **Translation links created** | **0** |
| **Status** | **PASS — the two blockers are resolved; the rollout is ready for Phase 3 apply** |

---

## 1. What this phase is, and what it is not

The EN Translation Rollout Phase 2 (dry-run + snapshot) closed **BLOCKED** on two
specific findings:

* `MISSING_SOURCE = 9` — nine authored EN rows whose PT source slug no longer
  resolves in production, and
* `unresolved_slug_conflicts = 1` — `en-page::newsletter`, which the dry-run
  showed would be created as `newsletter-2`.

This phase removes both blockers **in the repository and in the plan**, so the
next phase can apply cleanly. It performs **no** EN production creation, **no**
translation linking, **no** B2 field application, **no** EN taxonomy creation and
**no** production apply of any kind.

The result:

| blocker | before | after |
|---|---|---|
| `MISSING_SOURCE` | 9 | **7** — 2 re-keyed to their real PT record, 7 explicitly classified `NO_REAL_PT_SOURCE` |
| `unresolved_slug_conflicts` | 1 | **0** — `newsletter` plans at `newsletter`, never `newsletter-2` |
| `pt_overwrite_attempts` | 0 | **0** |
| `duplicate_target_identities` | 0 | **0** |
| production writes | 0 | **0** |

---

## 2. The nine missing PT sources

Full table with per-row evidence:
[`docs/evidence/2026-09-30-en-rollout-blocker-resolution/05-source-resolution-table.txt`](evidence/2026-09-30-en-rollout-blocker-resolution/05-source-resolution-table.txt).

Evidence was gathered in the order the task requires — repository-authored
data, then a fresh uncached production read, then stable record IDs, then exact
slug matches, then documented historical identity already committed here. **No
match was inferred from title similarity alone**; where a title is quoted it
corroborates an ID fact rather than standing in for one.

| # | Old authored source | Actual PT source | PT id | Type | Action |
|---|---|---|---|---|---|
| 1 | `guide::outono-irlanda-alimentacao-bem-estar` | `outono-irlanda-alimentacao-bem-estar` | 25028 | `post` | **RE-KEY (post type)** |
| 2 | `guide::carteira-de-motorista-2` | `carteira-motorista-brasileiros` | 25031 | `guide` | **RE-KEY (PT slug rename)** |
| 3 | `guide::learner-permit-theory-test-irlanda-cnh-brasileira` | — | — | — | `NO_REAL_PT_SOURCE` |
| 4 | `post::auxilios-e-apoio-relacionados-a-saude-e-bem-estar-na-irlanda` | — | — | — | `NO_REAL_PT_SOURCE` |
| 5 | `post::auxilios-para-familias-atipicas-na-irlanda` | — | — | — | `NO_REAL_PT_SOURCE` |
| 6 | `post::cursos-e-apoio-para-empreendedores` | — | — | — | `NO_REAL_PT_SOURCE` |
| 7 | `post::dica-de-saude-para-quem-viaja` | — | — | — | `NO_REAL_PT_SOURCE` |
| 8 | `post::guia-para-quem-esta-com-dificuldades-financeiras` | — | — | — | `NO_REAL_PT_SOURCE` |
| 9 | `post::guia-pratico-para-brasileiros-em-laois` | — | — | — | `NO_REAL_PT_SOURCE` |

### 2.1 The two re-keys, and why they are safe

**Row 1 — wrong post type.** The PT record exists in production with a
byte-identical `post_name`, but it is a **`post`** (id 25028), not a `guide`. The
row moved from the `guide` block to the `post` block of
`includes/manifest-data.php`.

**Row 2 — a real PT slug rename.** Committed local-instance evidence
(`docs/evidence/2026-09-28-b1-en-guides/dry-run.json`) records this row's PT
source as guide **id 463**, titled "Carteira de Motorista na Irlanda: Como Trocar
a CNH". Live production's guide id set is `457…486` with **exactly one gap, at
463** — the record was removed. Its successor is guide **25031**
`carteira-motorista-brasileiros`, created 2026-09-29T07:16, the only Brazilian
driving-licence guide on the site, and one of only two guides newer than the
rest of the set. The stable key changed; the authored English did not.

Both are **source-identity corrections**, not editorial rewrites, and that is
proven numerically rather than asserted:

| row | payload SHA-256 before | payload SHA-256 after |
|---|---|---|
| 1 | `d784c1a703acf1f6d424193ed2febc13af376cc480685613f70749618b2a1100` | `d784c1a703acf1f6d424193ed2febc13af376cc480685613f70749618b2a1100` |
| 2 | `fdc72265d332718fcb77ae06b0c09a79f6a6181f4bdbe684082b172dce5598a6` | `fdc72265d332718fcb77ae06b0c09a79f6a6181f4bdbe684082b172dce5598a6` |

The authored EN slug, title, excerpt, meta description and body are unchanged in
both cases; so is the B1 classification, and so is every other row.

### 2.2 The seven `NO_REAL_PT_SOURCE` rows

These were **not** deleted, **not** excluded silently and **not** given a
fabricated PT record. The task's default — *"do not delete the authored
translation merely to make the gate green"* — was followed exactly:

* every one of the 7 rows still exists, with its full authored English intact and
  byte-unchanged;
* no allowlist, skip-list or "ignore" entry was added anywhere;
* no placeholder PT content was created, in the repository or in production;
* no PT record was created, renamed, merged or deleted to make a row resolve.

They are classified **explicitly**, using the shared engine's own vocabulary —
`build_plan()` emits *"PT record absent in this site (documented exclusion)"*
into its `skip` bucket — so the classification is visible in the plan and named
in `04-summary-tables.txt` rather than hidden in a comment.

Each row still owes an editor **exactly one** decision: retire it, exclude it
explicitly with a reason, or re-key it later once a real PT source exists. That
decision is a content decision, not an engineering one, and this phase
deliberately does not make it.

Row 3 additionally carries a documented **leading hypothesis** for whoever
decides: the authored EN body covers the theory test, the learner permit, the EDT
and the driving test, and the replacement guide 25031's PT title names exactly
"CNH, IDP e Reduced EDT" — consistent with 25031 being a *merge* of the old
guides 463 and 10009. That hypothesis is **not** acted on, because it cannot be
established from available evidence and because mapping two authored EN rows onto
one PT record would be a duplicate source — a forbidden fork. It is recorded so
the next task starts from evidence instead of from a guess.

### 2.3 What the re-keys did to the manifest

```
en-guide  52 -> 51 rows      (row 1 left; row 2's key changed)
en-post   42 -> 43 rows      (row 1 arrived)
en-page   31 rows            (unchanged)
```

Total source objects 2,277 → **2,275**, and the difference is fully explained:
post 25028 and guide 25031 each moved from "unclaimed B1 record" to "claimed by
a stage", which is -2 `OUT_OF_SCOPE` rows under `(none)` and +2
`CREATE_CANDIDATE` rows. Nothing disappeared.

---

## 3. `newsletter` as a shared-slug B1 page (Option A)

Full detail: [`06-newsletter-policy.txt`](evidence/2026-09-30-en-rollout-blocker-resolution/06-newsletter-policy.txt).

### 3.1 Why the collision existed

`en-page` authors the EN slug `newsletter` — the very `post_name` the PT record
(production page id 13) already holds. WordPress makes page slugs unique per
tree, so a plain `wp_insert_post()` silently renames the EN record to
`newsletter-2`. `en-blog-page` and `en-jobs-page` never hit this because they
arm the scoped `wp_unique_post_slug()` exception; `en-page` did not. Phase 2
measured the cost: `/en/newsletter/` answers 200 with the **Portuguese** body
and a canonical pointing at PT — a B2-shaped response for a page the repository
classifies B1.

### 3.2 Why `newsletter-2` is rejected

It is not the authored identity, it is not the intended route
(`/en/newsletter/` is the PT-derived path every other EN route mirrors), and the
retired `conexao-page-translation` plugin already declared
`'newsletter' => array( 'en_slug' => 'newsletter', 'shared_slug' => true )` —
with `test-stage45-pages.php` still asserting that allowlist is exactly
`blog + newsletter`. The shared-slug intent is already recorded in this
repository; only the permit on the maintained engine was missing.

### 3.3 The mechanism that was extended — and nothing new

Two **existing** pieces were generalised. No second shared-slug system, no new
filter, no new transient, no second list:

* **the permit** — `conexao_en_translation_with_shared_page_slug()` and
  `conexao_en_translation_shared_page_slug_filter()`, unchanged in behaviour;
  still armed only for one stage's own writes, still limited to post type
  `page` **and** the exact declared slug.
* **the authoritative declaration** — `conexao_en_translation_shared_page_slug_for( $stage )`
  reads the ONE declared shared slug out of the stage's **own manifest** (a row
  whose `en_slug` equals its PT stable key), via the single
  `conexao_en_translation_stage_manifest()` resolver. The manifest stays the
  source of truth; there is no allowlist and no slug constant anywhere.
* **the guard** — `conexao_en_translation_shared_slug_page_adapter()`, generalised
  from `conexao_en_translation_blog_page_adapter()` (kept as a thin wrapper). It
  fires **only** for the one declared slug, which is what lets a 31-row stage
  hold the permit without touching its other 30 rows.
  `conexao_en_translation_stage_adapter()` pairs permit and guard in one
  decision, so the exception can never be armed without its protection.

| stage | declared shared slug | holds the permit |
|---|---|---|
| `en-blog-page` | `blog` | yes |
| `en-jobs-page` | `empregos` | yes |
| **`en-page`** | **`newsletter`** | **yes (new)** |
| `en-guide` / `en-post` / B2 stages / unknown stage | — | no (fail closed) |

`newsletter` is **B1 and stays B1**: it was *not* added to
`conexao_b2_page_allowlist()`, and a test now asserts its absence.

### 3.4 Tests

* **NEW** `wp-content/plugins/conexao-en-translation/tests/test-shared-slug-newsletter.php`
  — **37 assertions, 0 failed**, discovered automatically by the convention-based
  runner (no runner change, no list).
* **UPDATED** `wp-content/themes/conexao-br-irlanda/tests/test-en-jobs-shared-slug.php`
  (gate `shared_slug_page`) — **16 → 39 assertions, 0 failed**. The shared-slug
  permit is now a *declared data prerequisite*, so a missing plugin reports
  `insufficient data:` and exits non-zero instead of fataling inside
  `wp_insert_post()` (it did fatal at HEAD). **No existing assertion was weakened
  or removed.**

Required positives — all proven: the linked PT+EN pair shares the slug; the EN
request resolves to the EN record; the PT request is untouched; canonicals are
two **distinct** language-correct URLs (`/newsletter/` and `/en/newsletter/`);
hreflang is correct in both directions and the alternates do not collapse onto
one URL; the authored slug is retained.

Required negatives — all proven: an unpaired duplicate is not bound to a
language; two PT pages on the slug gain nothing; an EN page with no valid PT
counterpart gains nothing; an arbitrary page slug and an arbitrary post type are
both still governed by WordPress uniqueness; a linked pair with different slugs
is left to WordPress; `blog` and `empregos` are unchanged and the gate's
"no unexpected additional shared-slug pair" bound still holds.

### 3.5 One measurement caveat, recorded rather than hidden

Polylang memoises a record's translated URL per request, so in the *same*
request that creates the PT↔EN pair `get_permalink()` on the new EN record
returns the PT URL. This was measured to be invisible to `wp_cache_flush()`,
`clean_post_cache()` and the plugin's own routing-cache refresh. **The
production path never meets it** (one request creates, the next serves), so the
gate reads the two URLs across a real request boundary, where they are
`/newsletter/` and `/en/newsletter/`. This is a property of the measurement, not
of the route.

---

## 4. The regenerated manifest and the clean dry-run

Both are regenerated from the current HEAD against live production, not patched.

```
manifest digest : 2872808ca243ace1c9594a38729e8a77e045284ae1cc30fd3fd2a5b60d7c101f
plan digest     : 9c245082e490542e47ceaa6ebba7025249f06ad369b1480a9dbb86ffada1e96f
snapshot digest : b177ce5935de6ed80a89165d1bf3f3d300279949b2f3a29d0f529da26549d049
PT identity     : cfe4b7a670266da32ba635f3f46994328143d3de77a914aa52491d42e3a6f853  (== baseline)
```

| metric | value |
|---|---|
| total manifest objects | 2,275 |
| B1 objects / B2 objects (plan) | 127 / 299 = **426** |
| expected EN records (B1 creates) | **120** (50 guides + 37 posts + 31 pages + 1 blog page + 1 jobs page) |
| B1 translation links planned | 120 |
| B1 updates / already complete / conflicts | 0 / 0 / 0 |
| **missing source** | **7** (all `NO_REAL_PT_SOURCE`) |
| B2 field updates | **299** (289 `_leisure_excerpt_en` + 10 `_provider_excerpt_en`) |
| B2 EN records created | **0** |
| taxonomy creates | **13** EN `conexao_category`; `conexao_county` 26 and `conexao_town` 251 stay **shared** |
| orphans / duplicate targets / unclassified | 0 / 0 / 0 |
| **PT overwrite attempts** | **0** |
| **unresolved slug conflicts** | **0** |
| snapshot | 432 objects = 419 record operations + 13 taxonomy terms, reconciling 1:1 |

`en-page::newsletter` plans **target slug `newsletter`**, not `newsletter-2`:

```
en-page::newsletter   authored=newsletter  resolved=newsletter  permit=True -> deterministic safe target
```

The tools read the shared-slug permit **from the repository** (executed in PHP
via `conexao_en_translation_shared_page_slug_for()`), so the measured plan
cannot drift from the code that implements it — and a dump without that key is a
hard error rather than a silent "no stage holds the permit".

The engine is the repository's own, loaded **unmodified**
(sha256 `baf85283df95e80c6e1e2fccb0e1290c73f6269e290e33eb138ed2cfa36a6ce4`, the
same digest Phase 2 recorded), calling only `validate_manifest()`,
`build_plan()` and `calculate_gate()`. `Engine::run()` is never called;
`apply_invoked = False`.

---

## 5. Determinism

Two **independent, uncached** production reads. No cached response, no reuse of
the other run's live state, and no pre-fix or otherwise invalid run in the
comparison.

| check | result |
|---|---|
| snapshot JSON byte-identical | **yes** |
| plan JSON byte-identical (raw) | **no** — see below |
| plan JSON byte-identical (two documented non-content fields normalised) | **yes** |
| plan digest equal | **yes** |
| snapshot digest equal | **yes** |
| operation list identical (419 rows, order included) | **yes** |
| all 30 acceptance gates identical | **yes** (0 differing) |
| `newsletter` target identity identical | **yes** |
| missing-source classification identical | **yes** (same 7 rows) |
| PT identity baseline identical | **yes** |

The plan JSON is **not** byte-identical raw, and that is reported rather than
glossed. A recursive leaf-by-leaf diff finds **exactly two** differing leaves,
neither of which is plan content:

```
/report/generated_at_utc  run1='2026-09-30T08:30:10Z'  run2='2026-09-30T08:35:13Z'
/report/snapshot/path     run1='03-mutation-snapshot.json'  run2='snap-run2.json'
```

one is the run's own wall-clock timestamp and one is the `--snapshot` argument.
With exactly those two fields normalised the plan is byte-identical.

---

## 6. Safety

| | |
|---|---|
| production writes | **0** |
| EN records created | **0** |
| translation links created | **0** |
| B2 writes | **0** |
| taxonomy changes | **0** |
| PT drift | **0** |
| `.env` unchanged | **yes** (read only, to supply credentials from the environment) |
| secrets exposed | **NO** |

Production was revalidated with read-only requests: PT translated scope **2,175**,
EN **0** across all 8 post types, **0** links, **0** orphans, PT identity digest
**identical to the baseline**, Polylang configuration unchanged, no unexpected
objects. Routes: `/newsletter/` 200 PT (canonical `/newsletter/`);
`/en/newsletter/` 200 with canonical → PT — the **same B2-shaped response as
before**, because production still has no EN newsletter record;
`/en/newsletter-2/` **404**. `/en/newsletter/` has **not** been converted into a
real EN record.

The Flutter/mobile repository was not accessed, inspected, built, tested or
modified. The shared engine (`wp-content/plugins/conexao-translation-rollout/`)
is untouched.

## 7. Repository gates

| gate | result |
|---|---|
| `./scripts/run-tests.sh` — in-process PHP | **63/63 suites, 0 failed** (4,216 assertions, 0 failed) |
| `./scripts/run-tests.sh` — HTTP acceptance | **3/3 suites, 0 failed** |
| `./scripts/run-tests.sh` — script contracts | 5/6 (1 pre-existing failure, §7.1) |
| `./scripts/lint.sh` | **OK** — syntax clean, **no new** PHPCS violations, PHPStan clean |
| `php scripts/generate-registry-docs.php --check` | **OK** — 14 plugins, 23 generated regions current, 0 writes |
| `python3 scripts/verify-permanent-gates.py` | **6/7 pass** (1 pre-existing failure, §7.1) |
| documentation drift | 14 passed, 0 failed |
| **newly introduced failures** | **0** |

### 7.1 The one pre-existing failure, proven pre-existing

`verify-i18n-freshness.py` fails on 3 stale `.pot` catalogues
(`conexao-br-irlanda`, `conexao-content`, `conexao-event-runtime`). It was run
on a **stashed, clean tree** and again with this resolution applied; the two
`GATE-RESULT` lines are **byte-identical** — 8 passed / 3 failed / 3 violations /
2 pre-existing / 1 new, same three catalogues, same lag seconds. This resolution
touches no `.pot` file and adds no translatable string to a stale catalogue.

No assertion was weakened, no gate was baselined away, and
`tests/baseline/permanent-gates.json` is untouched.

Two defects in my own work were caught by the gates and fixed rather than
explained away: a missing closure binding in the generalised duplicate guard
(found by the new plugin test — PHP warned on an undefined `$post_type`) and a
redundant `??` plus array-alignment violations (found by PHPStan and the PHPCS
baseline gate).

Two local-only, `production: false` plugins were **activated in the local Docker
install** (`conexao-translation-rollout` and `conexao-en-translation`) because
the shared-slug permanent gate requires the permit to build its fixtures. That
is a dev-container options-table change; production is untouched.

---

## 8. Completion boundary

This task ends at **repository blocker resolution + regenerated inventory + clean
dry-run + verified snapshot**.

It did **not** and must not be read as having performed: EN production
creation, EN translation linking, B2 field application, EN taxonomy creation, or
any production apply.

The next task, only after this PASS, is:

> **EN Translation Rollout — Phase 3: Apply + Immediate Verification**

which will apply the planned **120 B1 record creates + 120 translation links**,
the **299 B2 field updates** and the **13 EN `conexao_category` terms** — and
which still needs an editor's decision on the **7 `NO_REAL_PT_SOURCE` rows**
documented in §2.2.

---

## 9. Evidence index

[`docs/evidence/2026-09-30-en-rollout-blocker-resolution/`](evidence/2026-09-30-en-rollout-blocker-resolution/)

| file | what it proves |
|---|---|
| `00-repository-stage-manifests.json` | the repository's own stage manifests, dumped from HEAD, incl. the shared-slug policy read from the code |
| `dump-stage-manifests.php` | the read-only dumper that produced it |
| `01-en-inventory-manifest.json` | the regenerated manifest + a fresh uncached production read |
| `02-dry-run-plan.json` | the clean dry-run: gates, operations, slug resolutions, missing sources |
| `03-mutation-snapshot.json` | the read-only mutation snapshot (432 objects) |
| `04-summary-tables.txt` | the same numbers as plain tables |
| `05-source-resolution-table.txt` | the nine rows: evidence, action, and the editorial decision each still owes |
| `06-newsletter-policy.txt` | the collision, Option A, the mechanism extended, the safeguards, the resulting target slug |
| `07-determinism.json` | the two-run comparison, field by field |
| `08-snapshot-verification.txt` | the snapshot reconciles 1:1 with the plan and carries no PT overwrite |
| `09-safety-verification.txt` | the safety ledger and the production revalidation |
| `10-permanent-gates.json` | `verify-permanent-gates.py` output |
| `11-gates.txt` | every repository gate with exact numbers, and the pre-existing-failure proof |
| `en-translation-inventory.py`, `en-dry-run.py` | the two read-only tools (`GET` only; no `--apply`) |

