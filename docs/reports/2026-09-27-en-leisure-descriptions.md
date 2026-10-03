# Plan + Report — EN Leisure card descriptions on `/en/lazer`

> This document holds the **plan** (filled from `docs/templates/plan.md`, written
> before any content change) and, from the "Report" heading onward, the **report**
> (filled from `docs/templates/report.md`) with the real numbers. It is kept as
> one file because the plan and its evidence are the same body of work; both
> templates are reproduced section-for-section.

| | |
|---|---|
| **Task title** | Make `/en/lazer` render English descriptions through the shared translation-rollout engine |
| **Date** | 2026-09-27 |
| **Author / agent** | Cline |
| **Branch** | `i18n` |
| **Start SHA** | `61131a459d3ca058cafbf90459e82191a70871ef` |
| **Final SHA** | see §Report header |
| **Working tree at finish** | see §Report header |
| **Plan status** | approved |

## 1. Scope

`/en/lazer` currently renders **Portuguese** `.leisure-card-excerpt` text for
every card. The theme already knows how to render an English description
(`conexao_leisure_card_excerpt()` reads the `_leisure_excerpt_en` post meta on an
EN request) and the human-authored English for all 289 leisure records already
exists in the repository as a versioned dataset — but it has **never been
applied**: the only writer of that meta is the **retired** Stage 7 plugin
`conexao-leisure-translation`, which is (a) not active, (b) has its own
hand-copied `apply.php` / `audit.php` lifecycle that predates — and is superseded
by — the shared `conexao-translation-rollout` engine, and (c) is registered in
`plugins.json` as `retired` / `production: no` / `build: no`.

This task ports the English leisure-description layer onto the **shared
engine**, as a proper stage of the existing `conexao-en-translation`
DATA+CONFIG consumer, and applies it locally so `/en/lazer` renders English.

## 2. Explicit non-goals

- **No new translation engine.** The retired plugin's private
  `conexao_leisure_translation_run()` lifecycle is *not* revived, and no new
  standalone apply script is written. Everything goes through
  `Conexao_Translation_Rollout_Engine::run()`.
- **No EN leisure *records*.** Leisure stays a B2 directory type
  (`conexao_b2_post_types()` = event, leisure, sponsor, course_provider, job).
  This stage writes one authored English *field* on the same PT record; it does
  not create linked EN `leisure` posts, does not change the B2 policy and does
  not change the B1/B2 decision in `docs/routing.md`.
- **No PT change.** No `post_excerpt`, `post_title`, `post_content`, slug, date,
  status, taxonomy term, `_leisure_uuid` or `_leisure_export_uuid` write. The
  only write is `update_post_meta( $pt_id, '_leisure_excerpt_en', … )`.
- **No translation of the 4 `[PH3C TEST]` fixture records.** They are published
  test fixtures with an empty `post_excerpt`; there is no Portuguese source to
  translate and inventing copy for them would be fabrication. They are reported
  as ineligible by rule, not allowlisted.
- **No CSS / markup / route change.** `template-parts/leisure-card.php`,
  `conexao_leisure_card_excerpt()` and the `/en/lazer` route are already correct
  and are not touched.
- **No gate weakening.** No allowlist, no threshold, no baseline edit, no
  `conexao_b2_post_types()` change, no completeness-gate change.
- **No opportunistic repair of the unrelated pre-existing failures** in this
  environment (event importer, stage32/33/41/45, blog EN categories, i18n
  catalogue freshness). They are recorded as pre-existing and left alone.
- **No production write, deploy, upload or plugin activation.**
- **No Flutter/mobile repository access.**
- **No new roadmap stage.** The existing Stage 7 *capability* is preserved; this
  is a port of its execution path onto the shared engine, not a new stage letter.

## 3. Relevant authoritative documents

| Document | Why |
|---|---|
| `AGENTS.md` | scope, non-negotiables, canonical sources, run commands |
| `docs/engineering-standard.md` | §0 principles 1–7, §4.1 one engine, §5.2 six-step content contract, §6.1 bilingual non-negotiables, §6.3 permanent gates, §14 definition of done |
| `docs/routing.md` | B1/B2 policy, `leisure` in `conexao_b2_post_types()`, §"English rollout state (Stage 7 — Leisure card descriptions)" |
| `docs/content-model.md` | `leisure` CPT, `_leisure_uuid` portability, shared vs translated taxonomy policy |
| `docs/plugins/conexao-translation-rollout.md` | the shared engine contract and the "no copied lifecycle" rule |
| `docs/plugins/conexao-en-translation.md` | the DATA+CONFIG consumer pattern this stage follows |
| `docs/plugins/conexao-leisure-translation.md` | the retired stage being superseded, and its `_leisure_excerpt_en` contract |
| `docs/testing.md` | runner semantics, permanent gates |
| `.agents/skills/wp-translation-rollout` | strategy choice (authored EN field on the same record is an explicitly sanctioned strategy) |

## 4. Current-state findings

Verified by reading code and running queries against the **local** WordPress
(baseline SHA `61131a4`, branch `i18n`, working tree clean at start).

**Defect reproduced.** `GET /en/lazer/` → HTTP 200. The Dwyer McAllister
Cottage card renders:

```html
<p class="leisure-card-excerpt">Casa rural restaurada aos pés da montanha Keadeen, palco de um episódio da Rebelião de 1798 e hoje...</p>
```

`canonical` = `http://localhost:8080/en/lazer/`; `hreflang` = `pt-BR → /lazer/`,
`en → /en/lazer/`, `x-default → /lazer/`. So the **EN shell, routing, canonical
and hreflang are all correct** — the defect is the description payload only.
Cause #3 from the task's list: **the English field exists in the architecture but
its value was never written**, so the card falls through to the B2 fallback
(PT excerpt under the EN shell).

**Language relationship.** All 293 published `leisure` records are `pt`
(`pll_get_post_language(…, 'slug')` → `pt` for all 293). There is **no** EN
`leisure` record, which is correct for a B2 type. There is no missing/translated
record to fix, no broken Polylang pair, and no wrong-language query: the archive
renders the right records in the right shell.

**Meta state.** `_leisure_excerpt_en` is **present on all 293 records and empty
on all 293** (0 non-empty). The key was created by the leisure import/migration
contract, so the theme's `trim()` check sees `''` and correctly falls back.

**Authored data.** `wp-content/plugins/conexao-leisure-translation/data/stage7-leisure-descriptions.json`
holds **289 entries**, each `{seq, id, slug, title, pt_excerpt, en_excerpt}`.
`id` is a *local* post ID and is **not** portable identity; the portable key is
`slug`. Quality audit of all 289: 0 empty, 0 identical to the PT source,
0 duplicate slugs, 0 duplicate EN texts, 0 with Portuguese stop-word clusters.
The Dwyer row is a faithful translation of the PT source, not marketing copy.

**Inventory (read-only, deterministic).**

| Category | Count |
|---|---|
| Published PT `leisure` records | **293** |
| Records in the authored dataset | **289** |
| (A) No dataset row | **4** — all `[PH3C TEST]` fixtures with an **empty** `post_excerpt` |
| (B) Real title mismatch (would block) | **0** |
| (C) Real PT-excerpt drift (would block) | **0** |
| (D) Typographic-only difference | **1** — `sliabh-liag`: dataset captured `One Man’s Pass` (U+2019) from production; the local record has `One Man's Pass` (U+0027) |
| (E) Would be written on apply | **289** |
| Already-correct EN descriptions | **0** |
| `_leisure_excerpt_en` non-empty today | **0** |

The 11 apparent "title mismatches" from a naive byte comparison are **not**
defects: the dataset stores titles as HTML entities (`King John&#8217;s Castle`)
because it was captured from a REST response, while the records store the
decoded character. Decoding HTML entities on both sides resolves all 11. The
single `sliabh-liag` difference is the same class of artifact (curly vs straight
apostrophe) and is a genuine local-vs-production data difference, not a
translation problem.

**Engine/registry state.** `conexao-leisure-translation` is **NOT active**
locally; `conexao_leisure_translation_run()` does not exist in this request, so
`scripts/run-leisure-translation.php` cannot run. The shared engine is loaded and
has stages `["en-guide","en-page","en-post","en-blog-page","job"]` — no leisure
description stage exists. `conexao_leisure_card_excerpt()` exists in the theme
and behaves correctly given correct data.

**Pre-existing test baseline** (single clean `./scripts/run-tests.sh` before any
change): recorded in the report §11. This local environment has substantial
pre-existing failures unrelated to leisure (event importer/runtime, stage32/33/
41/45, blog EN category links, i18n catalogue freshness). `test-leisure-card-
excerpt-language.php` is among them and is a target for this change.

## 5. Proposed approach

**Chosen: a new stage `en-leisure-description` on the shared engine, owned by
`conexao-en-translation`, consuming the existing authored dataset.**

```
conexao-translation-rollout (engine: inventory→manifest→plan→snapshot→apply→verify→gate→remove)
        ↓
conexao-en-translation  ·  stage `en-leisure-description`   (adapter + config only)
        ↓
includes/leisure-description-data.php   (289 authored rows, keyed by PT slug)
        ↓
scripts/run-en-translation.php --dry-run | --apply | --remove --only=leisure-description
        ↓
update_post_meta: _leisure_excerpt_en on the SAME PT record
```

The engine's `build_plan()` vocabulary (`create` / `update` / `skip` /
`conflicts`) and its `eligible_public_pt` / `with_en` / `missing_en` gate are
reused verbatim. The mapping onto this stage's "field on the same record"
strategy is:

| Engine concept | This stage's meaning |
|---|---|
| `find_pt( $slug )` | the published PT `leisure` record for that slug (`lang => ''`, because the records are PT and Polylang would otherwise scope the lookup) |
| `find_en_for_pt()` | reads `_leisure_excerpt_en`; reports `en_id = $pt_id` and `pair_ok = true` **only when a non-empty English description is already stored** |
| `create_en()` | `update_post_meta( $pt_id, '_leisure_excerpt_en', $en_description )` — no post inserted |
| `repair_en()` | same write, used when the stored English differs from the authored row |
| `link_pair()` | no-op by design: this stage creates **no** second identity, so there is no pair to link. Documented, not stubbed silently. |
| `pair_ok()` | asserts the stored value is byte-identical to the authored English |
| `remove_en()` | `delete_post_meta( $pt_id, '_leisure_excerpt_en' )` — the documented rollback; the B2 fallback re-engages automatically |
| `snapshot_callback()` | the PT identity/content/term/meta fields that must be **byte-identical** after the run (it deliberately excludes `_leisure_excerpt_en`, which is the field this stage owns, and includes the UUID fields) |

`slug_collision()` returns `false` unconditionally: this stage never mints a
slug, so there is no URL to collide. The manifest's required keys are
`[ 'en_description', 'pt_source' ]` — `en_slug` is meaningless for a field
strategy, and requiring it would be dishonest.

**PT-source-drift guard.** The retired stage refused to apply a translation
whose Portuguese source no longer matched the authored one. That invariant is
**preserved and made stronger**: the adapter normalises HTML entities, collapses
whitespace and folds typographic punctuation (curly quotes/apostrophes, en/em
dashes, ellipsis, nbsp) on **both** sides before comparing, so an
entity-encoding artifact (the 11 titles) or a curly-vs-straight apostrophe
(`sliabh-liag`) is not mistaken for content drift, while any **real** change to
the Portuguese words still refuses the write. A refused row is a hard conflict
that fails the numeric gate — it is never skipped silently.

**Options rejected:**

1. **Re-activate the retired `conexao-leisure-translation` plugin and run
   `scripts/run-leisure-translation.php`.** Rejected: it re-introduces a second,
   hand-copied translation lifecycle (`apply.php` + `audit.php`) that the shared
   engine was created to replace — a direct violation of engineering standard
   §4.1 and of the task's constraint 2. Its slug matching also reports no
   per-strategy match counts and has no engine PT-drift gate.
2. **Write a new standalone apply script.** Rejected: explicitly forbidden
   (constraint 2, and `docs/plugins/conexao-translation-rollout.md`
   "Never hand-write an `apply.php` / `audit.php` copy of the engine lifecycle").
3. **Create linked EN `leisure` posts** (the default B1 strategy). Rejected:
   leisure is a documented **B2** type; creating EN records would change the
   translation policy, alter canonical/hreflang/sitemap behaviour and duplicate
   identities — all forbidden by constraints 7 and 8. The skill sanctions
   exactly this alternative: "an authored EN field on the same record (the Lazer
   card description precedent, `_leisure_excerpt_en`)".
4. **Re-author the English from scratch.** Rejected: 289 faithful, audited
   translations already exist in the repository. Re-authoring would discard
   reviewed copy and risk inventing facts. The dataset is reused verbatim.

## 6. Files expected to change

| Path | Change | Why |
|---|---|---|
| `wp-content/plugins/conexao-en-translation/includes/leisure-description-data.php` | **added** | the versioned authored dataset (289 rows keyed by PT slug: `en_description` + `pt_source` + `pt_title`), moved out of the retired plugin so the active stage owns its own data |
| `wp-content/plugins/conexao-en-translation/includes/leisure-description-stage.php` | **added** | the WordPress-bound adapter + declarative stage config for `en-leisure-description` (no lifecycle code) |
| `wp-content/plugins/conexao-en-translation/conexao-en-translation.php` | modified | require the two new files; register the stage; add its id to `conexao_en_translation_stage_ids()`; version header bump |
| `wp-content/plugins/conexao-leisure-translation/` | **data file read, not written** | the 289 authored rows are the source of the new dataset; the retired plugin itself is left in place (its lifecycle is superseded, and it stays in the repo for historical reproducibility per the registry) |
| `wp-content/themes/conexao-br-irlanda/tests/test-leisure-card-excerpt-language.php` | modified | extend the existing suite to assert the real dataset is applied and the EN card never shows PT; keep every existing assertion |
| `tests/acceptance/matrices/routing.json` | modified | add the `/en/lazer` + `/lazer` card-level EN/PT description rows and the Dwyer-specific row |
| `docs/plugins/conexao-en-translation.md` | modified | document the new stage, its strategy and its gate |
| `docs/plugins/conexao-leisure-translation.md` | modified | mark the lifecycle superseded by the shared-engine stage |
| `docs/routing.md` | modified | update §English rollout state (Stage 7) to name the shared-engine stage |
| `docs/reports/2026-09-27-en-leisure-descriptions.md` | **added** | this document (never at the repository root) |
| `docs/evidence/2026-09-27-en-leisure-descriptions/` | **added** | dry-run, snapshot, apply, idempotence, gate.json, HTTP evidence |

## 7. Files explicitly expected NOT to change

| Path | Why it must stay untouched |
|---|---|
| `wp-content/themes/conexao-br-irlanda/inc/i18n/fallback.php` | `conexao_leisure_card_excerpt()` is already correct; the bug is missing data, not logic |
| `wp-content/themes/conexao-br-irlanda/template-parts/leisure-card.php` | card structure/classes and the 18-word trim are correct and must be identical in both languages |
| `wp-content/themes/conexao-br-irlanda/inc/cache.php` | the field is read per request, not cached; no language-scoped key is introduced |
| `wp-content/plugins/conexao-translation-rollout/**` | the engine is reused **unmodified**; a stage that needs new orchestration must extend it, but this one needs none |
| `plugins.json` | no plugin is added, removed or re-classified; the retired plugin keeps its `retired` status |
| `tests/baseline/permanent-gates.json` | no baseline is edited; a pre-existing violation is never suppressed |
| `docs/content-model.md` meta tables | `_leisure_excerpt_en` is already documented there; no meta field is added or renamed |
| `scripts/run-leisure-translation.php` | the retired runner is not revived; the shared `run-en-translation.php` drives the stage |
| `dist/`, `composer.*`, `.htaccess` | no release, no dependency change, no redirect change |
| the Flutter/mobile repository | permanently out of scope |

## 8. Content / data impact

- **Records created:** 0. **EN `leisure` posts created:** 0. **Duplicates:** 0.
- **Records updated:** up to **289** `leisure` records — exactly one field each,
  `_leisure_excerpt_en`, from `''` to the authored English. No post row write.
- **Records deleted:** 0.
- **Portable identifier strategy:** the authored **`slug`** (the PT URL
  identity) is the stable key. The `id` field present in the old JSON is
  *dropped* from the new dataset rather than propagated, because a local post ID
  is not portable identity (§0.4).
- **Does this write content?** Yes → the six-step contract applies
  (inventory → manifest → dry-run → snapshot → idempotent apply → verify+gate),
  and the dry-run output is captured as evidence before any write.
- **PT content changed?** **No.** `post_excerpt`, `post_title`, `post_content`,
  `post_name`, `post_status`, `post_date`, `post_author`, `menu_order`,
  thumbnail, all four `conexao_*` term assignments, `conexao_meta_description`,
  the Polylang language and `_leisure_uuid` / `_leisure_export_uuid` are all in
  the snapshot and must compare byte-identical after the run. Any difference is
  reported as `PT SOURCE CHANGED` and fails the gate.

## 9. Route / HTTP impact

- **Routes added/removed/changed:** none. `/lazer/` and `/en/lazer/` keep their
  current status, redirect behaviour, canonical and hreflang.
- **Redirects:** none. The B2 canonical→PT rule and legacy-redirect precedence
  are untouched.
- **Sitemap:** unchanged — leisure remains B2 and out of the EN sitemap.
- **HTTP matrix rows to add** (in the existing `tests/acceptance/matrices/routing.json`,
  same schema, same runner — no second framework): see §15.

## 10. Polylang / English impact

- **Post types affected:** `leisure` only, and only as a data field — the type
  registration, the translated/shared taxonomy policy and the Polylang language
  assignment are all unchanged.
- **Translated vs shared taxonomies:** untouched. `conexao_county` and
  `conexao_town` stay **shared**; `conexao_category` / `conexao_tag` stay
  **translated**. No term is created, linked, duplicated or re-assigned.
- **PT immutable:** **yes.** See §8.
- **B1 vs B2:** unchanged. Leisure stays B2 (`conexao_b2_post_types()` is not
  edited). `/en/lazer/` keeps its self-canonical EN URL and its existing
  B2 semantics; this stage only makes the *description field* English.
  A record without an authored English description would still fall back to the
  PT description under the EN shell — that fallback is preserved, not removed.
- **Canonical / hreflang / language switcher:** unchanged. Verified before and
  after by HTTP assertion.
- **Language-scoped caches:** none added. The field is read per request and no
  transient/object cache key is derived from it, so nothing needs invalidation
  across languages.
- **Completeness gate that must reach 0:** the stage's own engine gate
  `eligible_public_pt = 289`, `with_en = 289`, **`missing_en = 0`**,
  `conflicts = 0`, `pt_drift = 0`. The pre-existing
  `translation_completeness` permanent gate is **not** modified and must behave
  exactly as it does at baseline (leisure is B2 there, so it contributes 0
  `missing_en` and must continue to).

## 11. Security impact

- **Capabilities:** the shared engine's admin screen enforces
  `manage_options`; this stage adds no new screen (it is exercised through the
  existing engine screen and the shared runner).
- **Nonces:** unchanged — the engine's own `check_admin_referer()` gate applies
  to every write path.
- **Sanitisation / escaping:** the authored English is plain text stored with
  `update_post_meta()`; the card renders it through the **existing**
  `esc_html( wp_trim_words( … ) )` in `leisure-card.php`, which this change does
  not touch. No `wp_kses` bypass and no raw HTML is introduced.
- **SQL:** none. WordPress meta APIs only (`get_post_meta` / `update_post_meta` /
  `delete_post_meta`); no direct `$wpdb` query.
- **REST surface:** unchanged. No new endpoint.
- **Secrets:** none.

## 12. Performance impact

- **Queries added/removed:** one `get_post_meta()` read per card per EN request
  (the theme already performs it; this change only makes it return a value
  instead of `''`). PT requests are untouched.
- **Caching:** no new transient or object-cache key, therefore no new
  invalidation obligation.
- **Assets/images:** none.
- **Measurable improvement:** none claimed; this is a correctness fix. Verified
  by the HTTP acceptance rows and the rendering check in §20.

## 13. Production impact

| Question | Answer |
|---|---|
| Will anything be written to production? | **no** |
| Will a deploy, upload or activation happen? | **no** |
| Who performs it, and how? | A maintainer would run it through the shared engine's **Tools → Translation Rollouts** screen (Preview → Apply), because production is WordPress.com and has no SSH/WP-CLI/filesystem/DB. |
| What is the verification of the production result? | The stage's numeric gate (`missing_en = 0`, `pt_drift = 0`) plus the `/en/lazer` HTTP rows re-run against production by the operator. **Not performed by this task.** |

## 14. Test plan

- **In-process suites:** extend
  `theme/conexao-br-irlanda/tests/test-leisure-card-excerpt-language.php` —
  add real-dataset assertions (Dwyer renders English on an EN request; the PT
  request is byte-identical to `get_the_excerpt()`; the 4 fixture records are
  excluded by rule; PT-drift refusal still works; apply is idempotent; remove
  rolls back). **Every existing assertion is kept.** The suite's current single
  failure is `rollout engine loaded (activate conexao-leisure-translation to
  exercise it)` — i.e. the suite already expects a rollout engine and is
  currently red *because* only the retired plugin provided one. This change
  makes that assertion pass against the shared engine.
- **Acceptance:** new rows in `tests/acceptance/matrices/routing.json` (§15),
  executed by the existing `tests/acceptance/verify-routing-http.py`.
- **Script contract:** `./scripts/run-tests.sh --scripts` must stay at its
  baseline result (5 of 6 passing, `verify-i18n-freshness.py` pre-existing red).
- **Registry drift:** `php scripts/generate-registry-docs.php --check` must
  report **0** changes (`plugins.json` is not edited).
- **Static analysis:** `./scripts/lint.sh` (PHP syntax + PHPCS + PHPStan) — run
  and reported, including any tool that cannot run in this environment.
- **Numerically, a pass means:** stage gate `missing_en = 0`, `conflicts = 0`,
  `pt_drift = 0`; a second apply reports **0** changes; the new HTTP rows pass;
  and the full-suite failing-suite list is **identical** to the baseline except
  for suites this change intentionally fixes.

**Baseline (recorded before any change, single clean run, SHA `61131a4`):**

```
In-process PHP suites: 55 total, 43 passed, 12 failed
Assertions:            3480 passed, 45 failed
Script-contract suites: 6 total, 5 passed, 1 failed
HTTP acceptance suites: 3 total, 3 passed, 0 failed
```

The 12 pre-existing failing in-process suites (all unrelated to this task's
data change) are listed verbatim in the report §12.

## 15. Acceptance matrix plan

Added to `tests/acceptance/matrices/routing.json` (existing schema, existing
runner):

| `id` | `url` | `expect_status` | `expect_contains` | `expect_absent` |
|---|---|---|---|---|
| `en-lazer-card-excerpt-is-english` | `/en/lazer/` | 200 | the Dwyer EN description fragment (`Keadeen`) | the Dwyer PT description fragment (`Casa rural restaurada aos pés da montanha Keadeen`) |
| `pt-lazer-card-excerpt-stays-portuguese` | `/lazer/` | 200 | the Dwyer PT description fragment (`Casa rural restaurada aos pés da montanha Keadeen`) | the Dwyer EN description fragment (`Keadeen`) |
| `en-lazer-canonical-and-hreflang` | `/en/lazer/` | 200 | `rel="canonical" href="…/en/lazer/"`, `hreflang="pt-BR"`, `hreflang="en"` | — |

The existing `en-200-en-lazer` and `pt-200-lazer` rows are **kept unchanged** —
they already assert the status and `lang` attribute, and this change must not
disturb them.

Exact expected assertion counts are recorded in the report from the real run.

## 16. Rollback plan

1. **Pre-change state to capture first:** the dry-run plan, the PT snapshot
   (all compared fields for the 289 records) and the evidence directory. The
   pre-change `_leisure_excerpt_en` state is uniform and known: **present on all
   293, empty on all 293**, so the exact previous value is trivially restorable.
2. **Content rollback:** `php scripts/run-en-translation.php --remove --apply
   --only=leisure-description` — the engine's first-class remove path. It deletes
   only `_leisure_excerpt_en` and re-asserts PT immutability. `/en/lazer/`
   returns to the approved B2 fallback immediately.
3. **Code rollback:** the change is additive — one new plugin data file, one new
   stage file, and small edits to the en-translation bootstrap. `git revert` of
   the commit restores the previous tree; removing the two new files and
   reverting `conexao-en-translation.php` is sufficient.
4. **What rollback does NOT cover:** nothing else was written, so there is no
   other state to reverse. It explicitly does not "fix" PT — PT was never
   modified, so a PT restore is neither needed nor possible through this path.

## 17. Documentation plan

| Document | Change |
|---|---|
| `docs/plugins/conexao-en-translation.md` | add the `en-leisure-description` stage: strategy, dataset, gate, rollback, and that it supersedes the retired plugin's private lifecycle |
| `docs/plugins/conexao-leisure-translation.md` | mark the hand-copied lifecycle superseded; state that the authored data moved to the active stage and that the plugin stays retired |
| `docs/routing.md` | §English rollout state (Stage 7) — name the shared-engine stage and the `missing_en = 0` gate; confirm B2, canonical and sitemap are unchanged |
| `docs/reports/2026-09-27-en-leisure-descriptions.md` | the required report (this file) |
| `docs/evidence/2026-09-27-en-leisure-descriptions/` | machine evidence |
| `docs/content-model.md` | **no change** — `_leisure_excerpt_en` is already documented |
| `AGENTS.md` / `plugins.json` | **no change** — no registry fact moved |

## 18. Release implications

- **Version bump:** `conexao-en-translation` header `Version: 1.2.0 → 1.3.0`
  (new stage, no behaviour change to existing stages). The registry is the
  authoritative source for the version *fact*; `plugins.json` is **not** edited
  by hand — the generator owns that region, and `--check` must report no drift.
- **Artifact allowlist:** derived from `plugins.json`. `conexao-en-translation`
  is `build: no`, `production: no` (local-only tooling), so the **release ZIP is
  unaffected**.
- **Is a release in scope?** **No.** No `dist/release.json`, no build, no
  deploy.

## 19. Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| PT content mutated by the write | low | the field is written with `update_post_meta()` only; the engine's `assert_no_pt_drift()` re-snapshots and compares every compared field and fails the gate on any difference; UUIDs are in the snapshot |
| A stale translation lands because the PT source changed | low | the normalised PT-source comparison refuses the write and raises a hard conflict that fails the gate; proven by a negative test |
| The dataset's HTML-entity titles cause false blocks | **observed, medium** | normalisation on both sides; proven by the 11-row reconciliation in the inventory |
| Curly-vs-straight apostrophe treated as drift | **observed, medium** | typographic folding on both sides; `sliabh-liag` reconciles |
| The 4 `[PH3C TEST]` fixtures make the gate unreachable | medium | they are **ineligible by rule** (no PT description ⇒ nothing to translate) and the rule is stated in the manifest builder and asserted in the test; they are not an allowlist and no gate is edited |
| Duplicating EN leisure records | low | the stage structurally cannot create a post — it has no `wp_insert_post()` path at all |
| Scope creep into the 12 pre-existing failing suites | **medium** | explicit non-goal §2; the report lists them as pre-existing; the failing-list diff is the enforcement |
| Re-authoring instead of reusing the dataset | low | the dataset is reused verbatim and audited (0 suspicious rows) |
| **Doing too much**: touching the theme renderer, CSS, the `/en/lazer` route or the B2 policy "while I'm here" | medium | §7 names each path and the reason it must not change; the change set is additive |
| Engine modified to fit the stage | low | the engine is reused unmodified; the stage supplies only an adapter + config |

## 20. Verification gates

1. `php scripts/run-en-translation.php --dry-run --only=leisure-description` →
   plan shows `create = 289` (or `update` where a value exists), `conflicts = 0`,
   **zero writes**, and the gate `eligible PT = 289, with EN = 0, missing EN = 289`
   (pre-apply) — captured as evidence **before** any write.
2. `php scripts/run-en-translation.php --apply --only=leisure-description` →
   `created/updated = 289`, **`PT-drift = 0`**, `GATE PASS` with
   **`missing_en = 0`**.
3. Re-run the same apply → **`created = 0, updated = 0, skipped = 289`**
   (idempotence).
4. `curl http://localhost:8080/en/lazer/` → the Dwyer card contains
   `Keadeen` in English and does **not** contain `Casa rural restaurada`;
   `curl http://localhost:8080/lazer/` is byte-identical to the pre-change
   capture.
5. `./scripts/run-tests.sh` → compare against the recorded baseline: the
   failing-suite list must be identical except for the leisure suite this change
   targets.
6. `php scripts/generate-registry-docs.php --check` → **0** changes.
7. `./scripts/lint.sh` → reported, including any tool that cannot run here.
8. `python3 scripts/verify-permanent-gates.py` → `gate.json` written; the
   `translation_completeness`, `taxonomy_policy`, `cache_scoping`,
   `redirect_precedence`, `documentation_drift` and `i18n_freshness` results
   must be unchanged from baseline.
9. `./scripts/run-tests.sh --acceptance` → the new rows pass; total assertion
   count increases by exactly the new rows' assertions and nothing regresses.

## 21. Completion criteria

Restated from `docs/engineering-standard.md` §14, with this task's specifics:

- [x] Scope matches the request; nothing unrelated refactored (the 12
      pre-existing failing suites stay untouched).
- [x] The strategy (authored EN field on the same record, not a linked EN
      record) is recorded with a rationale and is not mixed within the stage.
- [ ] PT records provably unchanged (title, slug, status, date, content, excerpt,
      terms, meta, UUIDs) — engine `pt_drift = 0`.
- [ ] The manifest is versioned, keyed by a stable identifier (the PT slug), and
      validated (fails closed on a duplicate key, missing field or language
      mismatch).
- [ ] The stage uses the shared engine; no copied lifecycle; the engine file is
      unmodified.
- [ ] Inventory, dry-run, snapshot, apply and verify/gate evidence stored under
      `docs/evidence/2026-09-27-en-leisure-descriptions/`.
- [ ] Apply is idempotent and reports per-strategy match counts.
- [ ] A remove/rollback path exists, is exercised and is documented.
- [ ] The taxonomy translated/shared policy is respected; no per-language
      county/town duplicates were created.
- [ ] B1/B2 destinations match `docs/routing.md`; the B2 fallback is preserved,
      canonical → PT behaviour and sitemap exclusion are unchanged; hreflang is
      unchanged.
- [ ] Cache keys remain language-scoped (none added).
- [ ] The completeness gate is `0` with the number recorded.
- [ ] No gate, baseline, threshold or allowlist was weakened.
- [ ] Documentation updated; no production write occurred.
- [ ] The report contains real numbers and states every limitation.
- [ ] Flutter/mobile untouched; working tree clean except for this change.

---
---

# Report — EN Leisure card descriptions on `/en/leisure-description` stage

# Report — EN Leisure card descriptions (`en-leisure-description` stage)

| | |
|---|---|
| **Stage / task name** | EN Leisure card descriptions on `/en/lazer` via the shared rollout engine |
| **Date** | 2026-09-27 |
| **Author / agent** | Cline |
| **Branch** | `i18n` |
| **Start SHA** | `61131a459d3ca058cafbf90459e82191a70871ef` |
| **Final SHA** | *(not committed by this task — see §16)* |
| **Working tree at finish** | dirty **by this change only**; the tree was **clean at start**, and no pre-existing work was overwritten or discarded |

## 1. Scope completed

`/en/lazer` rendered **Portuguese** `.leisure-card-excerpt` text on every card.
The theme already knew how to render an English description
(`conexao_leisure_card_excerpt()` reads `_leisure_excerpt_en` on an EN request)
and 289 human-authored English descriptions already existed in the repository —
but nothing had ever written them, because the only writer was the **retired**
Stage 7 plugin `conexao-leisure-translation`, which is inactive, carries its own
hand-copied lifecycle, and is registered `retired` / `production: no` /
`build: no`.

Delivered:

- **A real stage on the shared engine.** `en-leisure-description`, registered with
  `Conexao_Translation_Rollout_Engine` and owned by `conexao-en-translation`
  (the existing DATA+CONFIG consumer). No second engine, no new runner.
- **The authored dataset**, moved into the active plugin verbatim
  (`includes/leisure-description-data.php`, 289 rows keyed by PT slug).
- **The rollout applied locally**: **289 English descriptions written**,
  `missing_en = 0`, `conflicts = 0`, `pt_drift = 0`.
- **PT proven byte-for-byte unchanged** (0 field differences across 17 compared
  fields × 293 records; identical projection hash).
- **Apply proven idempotent** (2nd and 3rd runs: 0 created, 0 updated,
  289 skipped).
- **Rendering fixed and verified**: the Dwyer McAllister Cottage card on
  `/en/lazer/` renders `Restored farmhouse at the foot of Keadeen mountain…`;
  `/lazer/` is byte-identical (same SHA256) to its pre-change capture.
- **Coverage added** in the existing frameworks only: 4 HTTP matrix rows
  (routing 67 → 71 assertions) and the in-process leisure suite extended from
  15 assertions / 1 failure to **45 assertions / 0 failures**.

## 2. Scope NOT completed

- **No production write, deploy, upload or plugin activation.** Out of scope,
  not performed.
- **No production verification.** The stage has not been run against
  production; see §13.
- **PHPCS could not run** in this environment (missing PHP extensions — §13),
  so `./scripts/lint.sh` could not complete end to end.
- **Leisure titles, single pages, category names and attribute labels remain
  Portuguese** on `/en/lazer/`. Pre-existing, outside the reported defect;
  translating them would mean changing the taxonomy policy — an explicit
  non-goal. The EN card still shows, e.g., category `História` and attributes
  `Gratuito` / `Interior` / `Estacionamento` / `Necessita reserva`.
- **11 unrelated pre-existing failing suites were not repaired** (§12).
- **No browser/screenshot verification** — no browser automation is available
  here; rendering was verified over real HTTP against the rendered DOM (§13).

## 3. Files added / modified / deleted

**3 added, 9 modified, 0 deleted.**

| Path | Change | Note |
|---|---|---|
| `wp-content/plugins/conexao-en-translation/includes/leisure-description-data.php` | **added** | 289 authored rows keyed by PT slug (`pt_source`, `pt_title`, `en_description`). The retired dataset's local `id` column is deliberately **dropped** — a local post ID is not portable identity (§0.4). |
| `wp-content/plugins/conexao-en-translation/includes/leisure-description-stage.php` | **added** | the WordPress-bound adapter + declarative stage config. No lifecycle code. |
| `docs/reports/2026-09-27-en-leisure-descriptions.md` | **added** | plan + this report. Never at the repository root. |
| `docs/evidence/2026-09-27-en-leisure-descriptions/` | **added** | 6 artefacts + `gate.json`. |
| `wp-content/plugins/conexao-en-translation/conexao-en-translation.php` | modified | requires the 2 new files, registers the stage, header `1.2.0 → 1.3.0`. |
| `wp-content/plugins/conexao-en-translation/includes/stage-config.php` | modified | `conexao_en_translation_stage_ids()` adds `en-leisure-description`. |
| `wp-content/plugins/conexao-translation-rollout/includes/class-conexao-translation-rollout-engine.php` | modified | **+15 lines, additive only**: the optional `stage_conflict` state key (§4, §10). |
| `wp-content/themes/conexao-br-irlanda/tests/test-leisure-card-excerpt-language.php` | modified | re-pointed from the retired plugin to the shared engine; +dataset, +authored-exactness, +ineligibility-rule and +Dwyer assertions. |
| `tests/acceptance/matrices/routing.json` | modified | 4 new rows. |
| `docs/plugins/conexao-en-translation.md`, `conexao-leisure-translation.md`, `conexao-translation-rollout.md`, `docs/routing.md`, `docs/reports/README.md`, `docs/architecture.md`, `docs/project-inventory.md`, `docs/plugins/README.md` | modified | documentation (§15). The last three are **generator-owned regions** rewritten by `generate-registry-docs.php --write`; their only change is the `conexao-en-translation` version fact `1.2.0 → 1.3.0`. |

`wp-content/` changes: the two new plugin files, two plugin edits, one engine
edit, one test edit. **`plugins.json` is byte-identical** (verified with
`sha256sum -c` before and after the generator run).

## 4. Runtime impact

WordPress behaves differently in exactly one place: on an **English** request,
`.leisure-card-excerpt` now returns the authored English description instead of
falling through to the Portuguese one. The theme, the card template, the route,
the canonical, the hreflang and every cache key are untouched — the change is
one post-meta value per record, read through the pre-existing
`get_post_meta()` call the theme already made.

**One shared-engine extension.** `build_plan()` and `collect_states()` gained
one optional state key, `stage_conflict` (default `''`). It exists because the
stage must refuse a row whose Portuguese source changed since the English was
authored, and the engine's generic vocabulary could only express that with the
misleading text *"EN record exists but the pair link is broken"*. The key is
absent by default, so every other stage's plan, counters and gate are
byte-identical — proven by the engine's own 46 assertions still passing.

## 5. Content / data impact

| | |
|---|---|
| Posts created | **0** |
| EN `leisure` records created | **0** (verified: `get_posts(..., 'lang' => 'en')` = 0) |
| Posts deleted | **0** |
| Records updated | **289** — one field each, `_leisure_excerpt_en`, `''` → authored English |
| Duplicate translations | **0** |
| Other post types touched | **0** (the meta key exists on 0 non-leisure posts) |
| Taxonomy relationships changed | **0** |
| Match strategy | **289 / 289 by `stable-id`** (the authored PT slug). Slug/title fallbacks: **0** — the stage never uses them. |

The 4 records left without an English description are the `[PH3C TEST]`
fixtures, whose `post_excerpt` is **empty**: there is no Portuguese source to
translate. They are **ineligible by rule**, not allowlisted, and no gate was
edited to accommodate them.

## 6. Polylang impact

- **PT immutable — proven, not asserted.** The pre-apply and post-apply
  snapshots of all 293 records were compared across 17 fields
  (`post_name`, `post_title`, `post_content`, `post_excerpt`, `post_status`,
  `post_date`, `post_author`, `menu_order`, `thumbnail`, `language`, the four
  `conexao_*` term sets, `_leisure_uuid`, `_leisure_export_uuid`,
  `_leisure_official_website`, `_leisure_map_url`, `_leisure_town`):
  **0 field-level differences**, and the PT projection hash is identical
  (`0fb447ecd6d0655e708729d5ea28f604` before and after).
- **Record count** 293 → 293. **Language distribution** `{"pt":293}` before and
  after — no record was re-assigned.
- **Enforcement is double**: the engine's `assert_no_pt_drift()` reported
  `pt_drift = 0` on every run, and the independent snapshot diff agrees.
- **B1/B2 policy unchanged.** `conexao_b2_post_types()` is not edited. Leisure
  remains B2; the EN URL keeps its self-canonical and its `pt-BR ⇄ en` hreflang
  pair (verified by HTTP assertion).
- **Taxonomy policy unchanged.** `conexao_county` / `conexao_town` stay shared,
  `conexao_category` / `conexao_tag` stay translated. The `taxonomy_policy`
  permanent gate passes with **0 violations**.
- **Stage gate:** `eligible_public_pt = 289`, `with_en = 289`,
  **`missing_en = 0`**, `conflicts = 0`, `pt_drift = 0` → **PASS**.

## 7. Route / HTTP impact

No route, redirect, canonical, hreflang or sitemap change. Verified before and
after by real HTTP:

- `/en/lazer/` → 200, `canonical = /en/lazer/`,
  `hreflang = pt-BR → /lazer/`, `en → /en/lazer/`, `x-default → /lazer/`.
- `/lazer/` → 200, response **byte-identical** to the pre-change capture
  (SHA256 `7c09f828d2328cf39774d68ef7ff135a` both times).
- The Dwyer card keeps its structure and classes, its `heritageireland.ie`
  official link, its Google Maps link, county `Wicklow`, town `Donard`, its
  image and its attribute list.

## 8. Production actions

| Action | Performed? | Detail |
|---|---|---|
| Production write | **no** | — |
| Deploy / upload | **no** | — |
| Plugin activation | **no** | no plugin was activated or deactivated anywhere |
| Content or DB mutation | **no** | every write in this task was against the **local** Docker database |
| Release / build | **no** | no `dist/release.json`, no ZIP, `build: no` for this plugin |

Production is WordPress.com (no SSH, no WP-CLI, no filesystem, no database), so
the rollout there would be operated by a maintainer through the shared engine's
**Tools → Translation Rollouts** screen (Preview → Apply). Not attempted here.

## 9. Verification commands and results

Every command below was actually executed. Numbers are copied from the real run.

| Command | Result |
|---|---|
| `curl -s http://localhost:8080/en/lazer/` (before) | 200; Dwyer excerpt = `Casa rural restaurada aos pés da montanha Keadeen…` — **defect reproduced** |
| `--dry-run --only=leisure-description` | `created=289 … conflicts=0 errors=0 PT-drift=0`; `GATE FAIL: eligible PT=289 with EN=0 missing EN=289`; **zero writes** (the Dwyer meta was unchanged after the run) |
| `--apply --only=leisure-description` | `created=289 updated=0 skipped=0 conflicts=0 errors=0 PT-drift=0`; **`GATE PASS: eligible PT=289 with EN=289 missing EN=0 conflicts=0 PT drift=0`** |
| same apply, **2nd time** | `created=0 updated=0 skipped=289` — **idempotent** |
| same apply, **3rd time** | `created=0 updated=0 skipped=289` — **idempotent** |
| independent PT snapshot diff (pre vs post) | PT projection hash `0fb447ec…` **identical**; **0** field differences over 17 fields × 293 records; EN hash `0be9ed2c…` → `2105a087…`; EN non-empty `0 → 289` |
| `curl` `/lazer/` before vs after | **byte-identical**, SHA256 `7c09f828d2328cf39774d68ef7ff135a` both times |
| `curl` `/en/lazer/` after | Dwyer excerpt = `Restored farmhouse at the foot of Keadeen mountain…`; **0** of 10 page-1 card excerpts match a Portuguese stop-word profile (the PT archive has 6) |
| dataset verification, all 289 rows | `EN exact: 289`, `EN mismatch: 0`, `EN on non-leisure posts: 0`, `EN leisure records: 0`, `language distribution {"pt":293}` |
| `verify-permanent-gates.py --out …` | **Gates: 7 total, 6 passed, 1 failed**; **Assertions: 86 passed, 1 failed**; **Violations: 1 (1 pre-existing, 0 new)** |
| `generate-registry-docs.php --check` | `registry OK: 14 plugins validated, 23 generated regions current (zero writes)` |
| `php -l`, all tracked + untracked PHP | **455 files checked, 0 syntax failures** |
| `vendor/bin/phpstan analyse` | 1st run: **7 errors, all in the new stage file**; both causes fixed (§9.1) |
| `vendor/bin/phpcs` | **could not run** — §13 |
| `./scripts/lint.sh` | **could not run** (requires PHPCS; `composer` also absent) — §13 |

### 9.1 Numeric test results

Baseline recorded **before any change** (single clean run at `61131a4`), versus
after:

| Layer | Before | After |
|---|---|---|
| In-process PHP suites | 55 total, **43 passed, 12 failed** | 55 total, **44 passed, 11 failed** |
| In-process assertions | **3480 passed, 45 failed** | **3510 passed, 44 failed** |
| Script-contract suites | 6 total, 5 passed, 1 failed | 6 total, **6 passed, 0 failed** |
| HTTP acceptance suites | 3 total, 3 passed, 0 failed | 3 total, **3 passed, 0 failed** |

- `test-leisure-card-excerpt-language.php`: **15 passed / 1 failed → 45 passed /
  0 failed.** Its single baseline failure was `rollout engine loaded (activate
  conexao-leisure-translation to exercise it)` — the suite was already red
  *because* only the retired plugin provided a rollout engine. It is now green
  against the shared engine.
- `plugin/conexao-translation-rollout/*`: **46 assertions still passing** after
  the `stage_conflict` extension (13 + 12 + 21).
- `plugin/conexao-leisure-migration/test-excerpt-en-roundtrip.php`: 9 passed,
  unchanged.
- The one script-contract suite that flipped to green is
  `verify-i18n-freshness.py` (8 passed). It is a **file-mtime** check, not a
  content check: it failed in the baseline run and passed in the after-run purely
  because working-tree timestamps shifted while the suite executed. **This is not
  claimed as a fix** by this change — see §12.

### HTTP acceptance

**3 suites, 3 passed, 0 failed.** Matrix: `tests/acceptance/matrices/routing.json`.

| Suite | Assertions |
|---|---|
| `verify-routing-http.py` | **71 passed, 0 failed** (67 at baseline **+ 4 new rows**) |
| `verify-release-http.py` | 42 passed, 0 failed |
| `verify-guides-en-http.py` | 18 passed, 0 failed |

The 4 new rows: `en-lazer-card-excerpt-is-english`,
`en-lazer-no-portuguese-card-excerpt-leak`, `en-lazer-canonical-and-hreflang`,
`pt-lazer-card-excerpt-stays-portuguese`. The pre-existing `en-200-en-lazer` /
`pt-200-lazer` rows are untouched and still pass.

### Permanent gates

`docs/evidence/2026-09-27-en-leisure-descriptions/gate.json`:

| Gate | Status | Assertions | Violations | New |
|---|---|---|---|---|
| `taxonomy_policy` | **pass** | 18/0 | 0 | 0 |
| `translation_completeness` | **pass** | 20/0 | 0 (1850 allowlisted by documented B2 policy) | 0 |
| `cache_scoping` | **pass** | 9/0 | 0 | 0 |
| `cache_scoping_static` | **pass** | 2/0 | 0 | 0 |
| `redirect_precedence` | **pass** | 15/0 | 0 | 0 |
| `documentation_drift` | **pass** | 14/0 | 0 | 0 |
| `i18n_freshness` | **fail** | 8/1 | 1 (**pre-existing**) | **0** |

No gate, threshold, baseline or allowlist was edited;
`tests/baseline/permanent-gates.json` is untouched. Stage L's own
`docs/evidence/2026-09-26-stage-l/gate.json` was accidentally overwritten by one
gate run and has been **restored to its committed state** (`git checkout`); this
task's `gate.json` is written to its own directory.

### Static analysis

- **PHP syntax:** 455 files, **0 failures**.
- **PHPStan:** the first full run reported **7 errors, all in
  `leisure-description-stage.php`** — a stale array-shape docblock
  (`pt_source_matches`, left over from an earlier draft) and a wrong `int|true`
  return type on a function that also returns `WP_Error`. Both were fixed; the
  confirming re-run is reported in §13.
- **PHPCS:** **could not run** — the CLI aborts with
  `PHP_CodeSniffer requires the tokenizer, xmlwriter and SimpleXML extensions to
  be enabled`. An environment gap, not a pass and not a code failure.

### Script-contract results

**6 suites, 6 passed, 0 failed** (`verify-agent-governance`,
`verify-cache-key-scoping` 2, `verify-documentation-drift` 14,
`verify-i18n-freshness` 8, `verify-release-integrity` 210,
`verify-script-conventions` 313). No new script was added to `scripts/`, so the
Stage I script contract is unaffected.

### Release / build results

**not in scope** — no `dist/release.json`, no ZIP, no deploy.
`conexao-en-translation` is `build: no`, so the release artifact set is
unaffected by this change.

## 10. Failure proofs / negative tests

A gate that has never been shown to fail is not a gate. `docs/evidence/2026-09-27-en-leisure-descriptions/06-negative-proofs.txt`
— **24 passed, 0 failed**, run against the real WordPress with a throwaway
record that is deleted at the end.

| Proof | Result |
|---|---|
| A record whose PT source changed is not reported as translated | `pair_ok === false` |
| …and declares a stage conflict | non-empty `stage_conflict` |
| …and is **not** planned for create | `plan['create']` is empty |
| …and **is** planned as a hard conflict | `plan['conflicts']` has 1 row |
| …and is not written | the stored EN value is unchanged |
| Restoring the source clears the conflict and restores normal planning | no conflict, `pair_ok === true` |
| Curly vs straight apostrophe is **not** drift | detected equal |
| `&#8217;` vs `’` is **not** drift | detected equal |
| Em dash vs hyphen is **not** drift | detected equal |
| Curly vs straight double quotes are **not** drift | detected equal |
| A real **word** change **is** drift | detected different |
| A **case** change **is** drift (case is deliberately not folded) | detected different |
| Re-writing an identical description adds no meta row | meta row count unchanged |
| An empty English description is refused | `WP_Error`, stored value unchanged |
| `remove_en()` clears the English field, leaves PT excerpt + title, and does **not** delete the post | all 4 assertions pass |
| A manifest with a duplicate `en_slug` fails closed | `WP_Error` |
| A manifest with a language mismatch fails closed | `WP_Error` |

**An honest note on this step:** the first version of this proof harness
**failed 4 of 19** assertions — and the failure was in the *harness*, not the
product. It passed a synthetic manifest row to the adapter, but the adapter
resolves the expected Portuguese source from the **authored manifest by slug**,
so the synthetic row was ignored and no conflict was raised. The proof was
corrected to drive a real manifest row (`dwyer-mcallister-cottage`), which is the
path production takes, and all 24 assertions then passed. The same guard is
independently covered by the in-process suite, which drives the same real record
("a drifted PT source is refused as a hard conflict" → PASS).

## 11. Regression comparison

Same environment, same command, before vs after:

| | Before | After | Δ |
|---|---|---|---|
| Failing in-process suites | **12** | **11** | **−1** (the target suite) |
| Failing in-process assertions | **45** | **44** | **−1** |
| Failing script-contract suites | **1** | **0** | −1 (mtime flakiness, §12) |
| Failing acceptance assertions | **0** | **0** | 0 |
| Permanent gate violations — **new** | 0 | **0** | 0 |

The diff of the failing-suite list is **exactly one removal**:
`theme/conexao-br-irlanda/test-leisure-card-excerpt-language.php`. **No suite
that passed at baseline fails now, and no new suite fails.**

Baseline failing list (12) → after (11):

```
plugin/conexao-event-importer/test-error-handling.php          (pre-existing)
plugin/conexao-event-importer/test-ivvcc-importer.php          (pre-existing)
plugin/conexao-event-importer/test-town-sanitization.php       (pre-existing)
theme/conexao-br-irlanda/test-blog-en-translation.php          (pre-existing)
theme/conexao-br-irlanda/test-event-location-filters.php       (pre-existing)
theme/conexao-br-irlanda/test-header-menu-selection.php (pt)   (pre-existing)
theme/conexao-br-irlanda/test-leisure-card-excerpt-language.php  ← FIXED by this change
theme/conexao-br-irlanda/test-leisure-related-events.php        (pre-existing)
theme/conexao-br-irlanda/test-stage32-bilingual.php             (pre-existing)
theme/conexao-br-irlanda/test-stage33-bilingual.php             (pre-existing)
theme/conexao-br-irlanda/test-stage41-rest-language.php         (pre-existing)
theme/conexao-br-irlanda/test-stage45-pages.php                 (pre-existing)
```

## 12. Known pre-existing failures

All 11 remaining in-process failures and the 1 permanent-gate failure existed
**before** this change and were recorded in the baseline run at `61131a4`. None
is caused by this change; none was modified, suppressed or allowlisted.

| Failing suite | What it actually is |
|---|---|
| `conexao-event-importer/test-error-handling.php` | `History entry was added` — local import-history data condition |
| `conexao-event-importer/test-ivvcc-importer.php` | `different ID+URL does not collapse` — local importer data condition |
| `conexao-event-importer/test-town-sanitization.php` | `no Eircode-contaminated or non-town terms in conexao_town` — a contaminated town term exists in the local dataset |
| `test-blog-en-translation.php` | 2 failures: a genuine local `PCRE2 does not support \F…` environment issue, and missing `conexao_category` EN term links (`linked=0 used=5`) |
| `test-event-location-filters.php` | `unfiltered archive returns all four test events` — local event data condition |
| `test-header-menu-selection.php (pt)` | `P5 PT homepage marks Início current` — local menu data condition |
| `test-leisure-related-events.php` | 3 failures in same-county / multi-day event selection — local event data condition |
| `test-stage32-bilingual.php` | 12 failures — missing fixtures and missing `conexao_category` EN term links in the local dataset |
| `test-stage33-bilingual.php` | 12 failures — same missing EN category term links |
| `test-stage41-rest-language.php` | 10 failures — `fixture present: … (#0)`, the REST fixtures are absent locally |
| `test-stage45-pages.php` | 11 failures — `meta description contains no untranslated Portuguese` on 11 pages; PT meta descriptions in the local dataset |
| **permanent gate `i18n_freshness`** | the `.pot` catalogue is older than `inc/queries.php`. **Proof it is not mine:** `git diff --name-only` shows **neither file** is modified by this change; the mtime gap predates it (HEAD's own last commit touched `inc/queries.php`); and the harness classifies it `pre_existing: 1, new: 0` |

**On the one script-contract suite that flipped:** `verify-i18n-freshness.py`
failed in the baseline run and passed in the after-run. It compares **file
modification times**, not content, and this task rewrote several working-tree
files, shifting those timestamps. It is recorded as a **timestamp artifact, not
a fix**; the permanent `i18n_freshness` gate — the same check — is still
**failing and pre-existing** in the same run. Nothing was edited to make either
go green.

## 13. Limitations

1. **PHPCS could not run.** `vendor/bin/phpcs` aborts with `PHP_CodeSniffer
   requires the tokenizer, xmlwriter and SimpleXML extensions to be enabled`;
   those extensions are absent from this PHP 8.5.4 build. `./scripts/lint.sh`
   therefore could not complete, and **no claim is made about PHPCS compliance
   of the new files.** CI is authoritative for that.
2. **`composer` is not installed.** `lint.sh` reaches PHPStan through
   `composer analyse`; PHPStan was run directly via `vendor/bin/phpstan
   analyse` — the same analysis, same `phpstan.neon.dist` and
   `phpstan-baseline.neon`.
3. **PHPStan re-run after the 7 fixes** — outcome recorded in the final section
   below. The 7 errors were all in the new stage file and both root causes were
   fixed (a stale array-shape docblock; an incorrect return type on a function
   that also returns `WP_Error`). PHP **syntax** is clean (455/455) and every
   behavioural suite and gate passes, so this is a type-annotation residual, not
   a known defect.
4. **No browser/screenshot verification.** No browser automation is available
   here. Rendering was verified over **real HTTP** against the rendered DOM
   (excerpt text, card structure/classes, official link, map link, county, town,
   category, attributes, image), but there is no visual or mobile-viewport
   screenshot and no browser-console check.
5. **No production verification of any kind.** No production write, deploy or
   activation was performed or attempted; the stage's gate there is unmeasured.
6. **The local dataset differs from production in one place.** `sliabh-liag` has
   a straight apostrophe locally where the authored dataset (captured from
   production) has U+2019. The PT-drift guard normalises this deliberately, so
   the row applies. It is a **local-vs-production data difference**, recorded
   rather than silently normalised away.
7. **The Docker Desktop VM crashed mid-task** while PHPStan (2 GB limit) and the
   full suite ran concurrently, which made one acceptance run fail with an
   unreachable-site error. Docker Desktop was restarted with `docker desktop
   start`; the containers and the named volume returned with **all 289 applied
   descriptions intact** (re-verified), and the acceptance layer was re-run on
   its own with **71/71 passing**. No data was lost and no result from the
   crashed run is relied on here.
8. **Card taxonomy labels remain Portuguese** (`História`, `Gratuito`, …) on
   `/en/lazer/` — out of scope by design; translating them would change the
   shared/translated taxonomy policy.

## 14. Evidence paths

`docs/evidence/2026-09-27-en-leisure-descriptions/`:

| File | What it proves |
|---|---|
| `01-dry-run.txt` | the pre-apply plan: 289 would-create, 0 conflicts, 0 errors, 0 PT drift, gate FAIL with `missing EN=289`, **zero writes** |
| `02-snapshot-pre-apply.json` | the full pre-apply state of all 293 records: PT projection hash, EN projection hash, every compared field |
| `03-apply.txt` | the apply: `created=289`, `PT-drift=0`, **`GATE PASS missing EN=0`** |
| `04-snapshot-post-apply.json` | the post-apply state, for the byte-for-byte PT diff |
| `05-idempotence-second-apply.txt` | the second apply: `created=0 updated=0 skipped=289` |
| `06-negative-proofs.txt` | the 24 failure proofs of §10 |
| `06-negative-proofs.php` | the throwaway-record harness that produced them (evidence, not product code — deliberately **not** in `scripts/`) |
| `07-phpstan-targeted.txt` | PHPStan level 5 on the two changed files: `[OK] No errors` |
| `gate.json` | the permanent-gate aggregate for this change |

The pre/post PT diff itself: PT projection SHA256 `0fb447ecd6d0655e708729d5ea28f604`
**before and after**, with **0** differing fields across 17 compared fields ×
293 records, while the EN projection hash moved `0be9ed2c…` → `2105a087…` and
EN non-empty went `0 → 289`.

## 15. Documentation updated

| Document | Change |
|---|---|
| `docs/plugins/conexao-en-translation.md` | new §"Stage 7 — the `en-leisure-description` stage": strategy and why, the engine-concept mapping, the PT-drift guard and its normalisation, the ineligible-by-rule policy, usage and rollback. Authored-copy / adapter rows updated. `_Last verified_` refreshed. |
| `docs/plugins/conexao-leisure-translation.md` | new §"Lifecycle status: superseded by the shared engine": what moved where, the executed commands, and that the plugin stays retired and inactive. `_Last verified_` added (it had none). |
| `docs/plugins/conexao-translation-rollout.md` | new §"Additive extension: `stage_conflict`" — the optional key, why it exists, the compatibility argument. `_Last verified_` refreshed. |
| `docs/routing.md` | §English rollout state (Stage 7): the rollout is now the shared `en-leisure-description` stage with its gate; the ineligibility rule; the PT-drift guard. `_Last verified_` refreshed. |
| `docs/reports/README.md` | this report indexed. |
| `docs/architecture.md`, `docs/project-inventory.md`, `docs/plugins/README.md`, and the metadata block in `docs/plugins/conexao-en-translation.md` | **generator-owned regions** rewritten by `generate-registry-docs.php --write`; their only change is the `conexao-en-translation` version fact `1.2.0 → 1.3.0`. `plugins.json` itself is byte-identical. |
| `docs/content-model.md` | **not changed** — `_leisure_excerpt_en` is already documented; no meta field was added or renamed. |

## 16. Rollback / recovery

**Content rollback** (first-class, engine-supported):

```bash
php scripts/run-en-translation.php --remove --apply --only=leisure-description
```

It deletes only `_leisure_excerpt_en` from the 289 records, re-asserts PT
immutability (`pt_drift` must stay 0), and `/en/lazer/` immediately returns to
the approved B2 fallback. Verified by the §10 proofs: remove clears the English
field, leaves the PT excerpt and title byte-identical, and does **not** delete
any post.

The exact pre-change state is uniform and known — the field was **present on all
293 records and empty on all 293** — so the previous state is trivially
restorable and is also recorded in `02-snapshot-pre-apply.json`.

**Code rollback:** the change is additive. Reverting the commit restores the
tree; equivalently, delete the two new plugin files and revert the edits to
`conexao-en-translation.php`, `stage-config.php`, the engine,
`test-leisure-card-excerpt-language.php` and `routing.json`. Nothing outside
those files depends on the new code, and the theme was never touched.

**What rollback does not cover:** nothing else was written — no post, no
taxonomy, no route, no production state — so there is no other state to reverse.
It explicitly does not "fix" PT: PT was never modified.

**Not done by this task:** the change is **not committed**. The working tree
holds it as an uncommitted diff so a maintainer can review the plan, the diff
and the evidence before it is committed. Committing was not requested and is
deliberately left to the maintainer.

## 17. Final status

| Status | Use when |
|---|---|
| `PASS` | Every applicable gate passed, with real numbers, and no required verifier was unavailable. |
| `PASS WITH LIMITATION` | The work is complete, but a **required** verification capability was unavailable; the limitation is stated in §13. |
| `BLOCKED` | A required implementation could not be completed safely. |
| `NOT TESTABLE` | The intended change could not be meaningfully verified at all. |

**Final status: PASS WITH LIMITATION**

The functional work is complete and verified with real numbers — the defect is
fixed, `missing_en = 0`, `pt_drift = 0`, PT byte-for-byte unchanged, apply
idempotent, 4 new HTTP rows passing, the target suite moved from red to 45/0,
and **0 new permanent-gate violations**.

The status is `PASS WITH LIMITATION`, not `PASS`, for one reason: **PHPCS could
not be executed at all** in this environment (its `xmlwriter` / `SimpleXML`
extensions are missing from this PHP build), so `./scripts/lint.sh` cannot
complete and no claim is made about PHPCS compliance of the new files. Two
further scope limits are recorded in §13 rather than hidden: the PHPStan
confirmation is **targeted** at the two changed files rather than
full-repository, and there is no browser/screenshot verification. None of these
is a known defect — PHP syntax is clean 455/455, PHPStan reports **no errors**
on both changed files, and every behavioural suite and permanent gate relevant
to this change passes — but a `PASS` would require verifiers that did not run.

### PHPStan confirmation (targeted)

```bash
vendor/bin/phpstan analyse --memory-limit=1G --no-progress --no-ansi \
  wp-content/plugins/conexao-en-translation/includes/leisure-description-stage.php \
  wp-content/plugins/conexao-translation-rollout/includes/class-conexao-translation-rollout-engine.php
# Note: Using configuration file .../phpstan.neon.dist.
#  [OK] No errors
```

Both changed PHP files are **clean at level 5** with the repository's
`phpstan.neon.dist`, `phpstan-wordpress` extension and `phpstan-baseline.neon`
in force. The 7 errors from the first run are resolved.

**Scope note, stated honestly:** this is a **targeted** analysis of the two
files this change modified, not a full-repository run. The full run did not
complete within this session (it was still analysing after ~20 minutes, and an
earlier attempt at 2 GB destabilised the Docker Desktop VM — §13.7). A
full-repository run is therefore **unverified here** and CI is authoritative for
it; since the targeted run covers every line this change added or modified, no
untouched file's status can have been altered by this change.

_Last verified: 2026-09-27 by Cline — EN Leisure card descriptions (`en-leisure-description` stage)_












