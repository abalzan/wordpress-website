# Plan — B1 English Guides content rollout (48 PT guides → real EN records)

| | |
|---|---|
| **Task title** | B1 English Guides content rollout for `/en/guias/` |
| **Date** | 2026-09-28 |
| **Author / agent** | coding agent (Cline) |
| **Branch** | `i18n` |
| **Repository baseline (starting SHA)** | `846fd018b2f88dc0e10c6d47a2c9b8e693772e3d` |
| **Plan status** | approved |

## 1. Scope

Roll out real `en` translations for all **48 published PT `guide` records**, plus
the **13 used `conexao_category` terms**, so that `/en/guias/` becomes a populated
B1 English archive. The work uses the **existing shared translation rollout
architecture**: the `conexao-translation-rollout` engine, the existing `en-guide`
stage declared by `conexao-en-translation`, the existing
`scripts/run-en-translation.php` CLI, and the existing permanent gates. The
already-authored English Guide dataset living in the **retired**
`conexao-guide-translation` plugin is **imported into the shared engine's stage
data**, not re-activated as a second lifecycle.

## 2. Explicit non-goals

- **Not** widening `conexao_b2_post_types()` and **not** making `guide` a B2 post
  type. `guide` stays B1 (`inc/i18n/fallback.php`); the archive gets real records,
  never a PT fallback body.
- **Not** re-activating `conexao-guide-translation` as a live lifecycle. Its
  `apply*.php` / `audit.php` / `admin.php` stay dormant and unregistered; only its
  authored *data* is imported.
- **Not** adding a second runner, engine or admin screen. The existing `en-guide`
  stage and the existing CLI are used.
- **Not** translating `conexao_county` or `conexao_town` — they stay **shared**,
  one physical term for both languages.
- **Not** touching `conexao_tag` or unrelated content (Blog, Jobs, Lazer, Events,
  pages, courses, sponsors).
- **Not** changing routing, redirect precedence, sitemap or hreflang *policy*:
  the routes already work; only the data they select changes.
- **Not** writing to production, committing, or deploying (no authorisation).
- **Not** touching the Flutter/mobile repository.

## 3. Relevant authoritative documents

`AGENTS.md`; `docs/engineering-standard.md` (§0 principles, §5.2 six-step
content-change contract, §6.1–6.3, §9.2, §13.2, §14); `docs/routing.md` (§English
`/en/` layer, the Guides row, the B1/B2 policy); `docs/content-model.md` (post
types, taxonomy translated/shared split); `docs/releases.md`;
`docs/templates/plan.md`; `docs/templates/report.md`;
`docs/plugins/conexao-translation-rollout.md` (the shared engine);
`docs/plugins/conexao-en-translation.md` (the stage layer);
`docs/plugins/conexao-guide-translation.md` (the dormant data source);
`.agents/skills/wp-translation-rollout/SKILL.md`; `docs/testing.md`;
`scripts/README.md`; `plugins.json`.

## 4. Current-state findings

Measured against the local Docker WordPress at the starting SHA, not assumed:

| Metric | Count |
|---|---:|
| Published PT `guide` records | **48** |
| Published EN `guide` records | **0** |
| PT guides with a linked EN translation | **0** |
| Orphan EN guides | **0** |
| PT `conexao_category` terms | **40** |
| PT `conexao_category` terms **actually used by guides** | **13** |
| EN `conexao_category` terms | **0** |
| Guides with a featured image / county / town / tag | **0** (category only) |
| `conexao_meta_description` on PT guides | **48** |

Baseline suite (`./scripts/run-tests.sh`): **60 in-process suites, 42 passed,
18 failed, 2970 assertions passed / 76 failed**; script-contract 6/6; HTTP
acceptance: `verify-guides-en-http` fails on exactly 2 content-driven rows
(`/en/guias/page/2/` 404-vs-200, "an EN guide link is discoverable"),
`verify-release-http` 42/0. The permanent translation-completeness gate
independently reports `guide: missing_en = 48` with `translated: 0`.

**`conexao_is_b2_post_type('guide')` is `false` by design** (`guide` is absent
from `conexao_b2_post_types()`), and `docs/routing.md` line 49 already documents
`/en/guias/` as *"a real English archive since Stage 9: one linked EN `guide` per
public PT guide"*. The documented contract is already in place — only the
**content** is missing. The prior task
(`docs/reports/2026-09-28-en-guides-archive-blocked.md`) correctly stopped at this
content boundary and produced no code change.

**The gap found in the code:** the shared `en-guide` stage
(`conexao-en-translation`, `stage-config.php` line 178) is registered and working,
but its **authored manifest holds only 1 row**
(`outono-irlanda-alimentacao-bem-estar`, Stage M), and that row's PT guide **does
not exist in this database** — it is reported as a documented exclusion. The
complete authored English for the real 48 guides lives in the retired
`conexao-guide-translation` plugin (`includes/guides-*.php` +
`includes/body-*.php` + `includes/terms.php`).

Measured quality of that retired dataset (loaded read-only; the plugin is not
activated by the rollout):

- **51 rows**, 0 missing `en_title`/`en_content`, 0 missing `en_excerpt`,
  0 missing `en_meta_description`, **0 duplicate `en_slug`**.
- All **48** live PT guide slugs are covered. **3** extra rows
  (`beneficios-pais-solteiros-irlanda`,
  `inverno-irlanda-depressao-sazonal-saude-mental`,
  `violencia-domestica-irlanda-onde-encontrar-ajuda`) have no PT record in this
  site — portable exclusions, reported not silently dropped.
- **15** term rows authored; **13** are the terms actually used here
  (`saude-e-bem-estar`, `seguranca-e-direitos` are authored but unused by guides).

## 5. Proposed approach

**Chosen: import the authored dataset into the existing `en-guide` stage of the
shared engine, and add the missing taxonomy-translation capability as an
engine-owned, stage-driven lifecycle extension.**

1. **Data (new, versioned, in the stage plugin).** Add
   `includes/guide-translation-data.php` (the authored English keyed by the **PT
   slug** — the only portable identity) and `includes/guide-terms-data.php` (the
   PT→EN category terms). The rows are generated mechanically from the retired
   dataset, so no English is re-authored and none is invented. The retired plugin
   keeps its files; the stage no longer depends on it at run time.

2. **Merge into the existing stage manifest.** Extend
   `conexao_en_translation_map()` so `guide` is the **union** of the existing
   Stage M row and the new data, failing closed on a duplicate PT slug. The
   `en-guide` stage id, config and adapter are otherwise unchanged.

3. **Taxonomy translation through the shared mechanism.** The shared
   `conexao_en_translation_copy_fields()` already files an EN record under the
   **EN counterpart** of the PT `conexao_category` term via `pll_get_term()`, and
   already copies the **shared** county/town terms by identity. What the shared
   engine lacks is a *term-creation* step, so the engine is given one generic,
   optional, additive capability (`taxonomy_callback` + `taxonomy_gate_callback`),
   owned by the engine and driven by the stage. This is an **extension of the one
   engine**, not a second engine, and a no-op for every existing stage (the keys
   are optional and absent elsewhere).

4. **Run the six steps** through the existing CLI
   `scripts/run-en-translation.php --only=guide`: inventory → manifest validation
   → dry-run → snapshot → apply → verify + numeric gate.

**Rejected alternatives and why:**

- *Make `guide` B2 / widen the EN query.* Forbidden by the task and by
  `docs/routing.md`; it would render Portuguese under an English URL.
- *Reactivate `conexao-guide-translation` and run its own `apply.php`.* That
  reactivates a second, independent translation lifecycle and a second admin
  screen — exactly what the task and `AGENTS.md` forbid. Its **data** is
  authoritative and reusable; its **lifecycle** is not.
- *Copy the dataset and write a bespoke `run-guides-en.php`.* A second runner and
  a second copy of the six-step contract. Rejected.
- *Author 48 new English bodies.* Unnecessary: the authoritative dataset already
  exists, is complete and passes the leak check. Re-translating would risk a
  lower-quality result and could not be verified against the authored source.

## 6. Files expected to change

| Path | Change | Why |
|---|---|---|
| `.../conexao-en-translation/includes/guide-translation-data.php` | **new** | Versioned authored English for the PT guides, keyed by PT slug. |
| `.../conexao-en-translation/includes/guide-terms-data.php` | **new** | Versioned PT→EN `conexao_category` term translations. |
| `.../conexao-en-translation/includes/translation-map.php` | modified | Union the new guide data into the per-type manifest, fail-closed on duplicate key. |
| `.../conexao-en-translation/includes/guide-stage.php` | **new** | The `en-guide` stage's taxonomy-term adapter + numeric taxonomy gate. Declaration only. |
| `.../conexao-en-translation/includes/stage-config.php` | modified | Register the taxonomy callbacks and the term-owning run wrapper for `en-guide`. |
| `.../conexao-en-translation/conexao-en-translation.php` | modified | `require_once` the new files; version bump; description update. |
| `.../conexao-translation-rollout/includes/class-conexao-translation-rollout-engine.php` | modified | Additive, optional `taxonomy_callback` / `taxonomy_gate_callback`. |
| `.../conexao-translation-rollout/conexao-translation-rollout.php` | modified | Version bump. |
| `.../conexao-translation-rollout/tests/test-translation-rollout-engine.php` | modified | Cover the new optional keys + prove existing stages are unchanged. |
| `docs/evidence/2026-09-28-b1-en-guides/*` | **new** | Machine evidence. |
| `docs/reports/2026-09-28-b1-en-guides-rollout.md` | **new** | The completion report. |

(all `.../` prefixes above are `wp-content/plugins/`)

## 7. Files explicitly expected NOT to change

| Path | Why it must stay untouched |
|---|---|
| `wp-content/themes/conexao-br-irlanda/inc/i18n/fallback.php` | Holds `conexao_b2_post_types()`; `guide` must stay absent (B1). |
| `wp-content/themes/conexao-br-irlanda/inc/i18n/guard.php` | Translated-taxonomy policy; already correct. |
| `wp-content/plugins/conexao-guide-translation/**` | Dormant data source, kept for history. Not activated, not edited. |
| `wp-content/plugins/conexao-content/**`, `conexao-data-model/**` | Platform runtime; not involved in an EN data rollout. |
| `plugins.json` | No plugin added, removed or re-classified; the registry stays authoritative and unchanged. |
| `tests/acceptance/matrices/guides-en.json` | Already states the target contract; red **because** the content is missing. It must go green, not be edited to pass. |
| `.../tests/test-taxonomy-policy.php`, `test-translation-completeness.php` | Permanent gates; never weakened. |
| Anything under the Flutter/mobile repository | Out of scope, forbidden. |

## 8. Content / data impact

- Records **created**: 48 EN `guide` posts (`publish`, lang `en`) + EN
  `conexao_category` terms for the 13 used PT terms, each linked to its PT term in
  both directions.
- Records **updated**: 0 PT records. 0 unrelated records.
- Records **deleted**: 0.
- Portable identifier strategy: the **PT slug** is the stable key for guides and
  the **PT term slug** for terms. No local post/term ID is ever used as identity.
- This **writes content**, so the six-step contract applies and the dry-run output
  is captured as evidence.
- **PT content changed? No.** Only the tolerated Polylang language backfill
  (`'' → 'pt'`) on a record that had no language at all, which the engine already
  permits and reports.

## 9. Route / HTTP impact

- Routes added/removed/changed: **none**. `/guias/` and `/en/guias/` already
  resolve; only the row set they return changes.
- Redirects: **none added**. The documented B1 replaced-master rule
  (`/en/guias/<pt-slug>/` → 302 → `/guias/<pt-slug>/`) continues to apply to the
  PT slug; the real EN slugs serve 200 at `/en/guias/<en-slug>/`. No redirect is
  suppressed or newly introduced.
- Sitemap coverage: unchanged (B1 real records are not a fallback, so no sitemap
  policy change is required).
- HTTP matrix rows: `tests/acceptance/matrices/guides-en.json` is **not**
  modified. The rows `en-guides-page-2` and "an EN guide link is discoverable"
  must turn green on their own. Representative-single / 404 / replaced-master rows
  are added **only if** an equivalent row does not already exist.

## 10. Polylang / English impact

- Translated post types: `guide` (already registered by
  `conexao_polylang_translated_post_types()`); translated taxonomy
  `conexao_category` (already registered). `conexao_county` / `conexao_town` stay
  **shared** and are never duplicated or translated.
- PT immutable: **yes.**
- B1/B2: `guide` remains **B1** — real EN records only, never a fallback body.
- Canonical / hreflang / language switcher: unchanged code; they now have a real
  EN↔PT pair to describe, so EN singles become self-canonical with the full
  `pt-BR` / `en` / `x-default` set.
- Language-scoped cache keys: none added or changed.
- Completeness gate that must reach **0**: `post_type:guide:missing_en: 48 → 0`,
  and the stage gate `eligible_public_pt = 48, with_en = 48, missing_en = 0,
  conflicts = 0, pt_drift = 0`.

## 11. Security impact

- Capabilities: unchanged. The stage is CLI/`tools`-driven; the shared admin
  screen's capability + nonce flow is untouched.
- Nonces: none added or changed.
- Sanitisation/escaping: authored content is inserted through `wp_insert_post()`
  with KSES applied by WordPress, exactly as every existing stage does. Term
  creation uses `wp_insert_term()`.
- SQL: WordPress APIs / the shared engine only. No direct SQL.
- REST surface: **no** change.
- Secrets: **none**.

## 12. Performance impact

- Queries: no new frontend query. The EN archive begins returning rows it
  previously did not (0 → 48), which is the intended outcome.
- Caching: no new transient or object-cache key; invalidation is unchanged.
- Assets/images: unchanged (no guide has a featured image).
- Measurable improvement: `FOUND` on `/en/guias/` goes **0 → 48**;
  `post_type:guide:missing_en` goes **48 → 0**.

## 13. Production impact

| Question | Answer |
|---|---|
| Will anything be written to production? | **no** |
| Will a deploy, upload or activation happen? | **no** |
| Who performs it, and how (production has no SSH/WP-CLI)? | A maintainer, later, through Tools → **Translation Rollouts** (Preview → Apply). Not in scope here. |
| What is the verification of the production result? | `python3 scripts/verify-deploy.py --site https://<host>` plus the same HTTP matrix. Not run here. |

## 14. Test plan

- In-process suites to **update**: the shared engine suite (cover the new optional
  keys, and prove the record-creating stages' plans/counters are unchanged).
- In-process suites that must go **green**:
  `test-translation-completeness.php` (guide 48 → 0) and the guide EN suite's
  content-driven assertions.
- Acceptance area / matrix rows: `tests/acceptance/matrices/guides-en.json`
  (unchanged file, must pass), plus representative EN/PT singles.
- Script-contract impact: `verify-documentation-drift.py` and
  `verify-i18n-freshness.py` may react to new plugin source and new docs — they
  must be **fixed by fixing the drift**, never by editing a gate.
- Registry drift check: `php scripts/generate-registry-docs.php --check`. A plugin
  header version changes, so the generated blocks must be regenerated by the
  generator, not hand-edited.
- Numeric pass criteria: 48 created, 0 updated, 0 removed, 0 conflicts,
  **0 PT drift**, gate `PASS`, second run 0/0/0.

## 15. Acceptance matrix plan

Existing rows in `guides-en.json` (all must pass, file unchanged):

| id | url | expect_status | expect_contains | expect_absent |
|---|---|---|---|---|
| `pt-guides-200` | `/guias/` | 200 | canonical, `/guias/` | `language-fallback-notice` |
| `en-guides-200` | `/en/guias/` | 200 | canonical `/en/guias/`, hreflang pt-BR/en/x-default | `language-fallback-notice` |
| `pt-guides-page-2` | `/guias/page/2/` | 200 | — | — |
| `en-guides-page-2` | `/en/guias/page/2/` | 200 | — | — |
| `en-filter-en-slug` | `/en/guias/?categoria=documents` | 200 | — | `language-fallback-notice` |
| `en-filter-pt-slug` | `/en/guias/?categoria=saude` | 200 | — | — |
| `en-filter-unknown-slug` | `/en/guias/?categoria=naoexiste` | 200 | — | — |
| `pt-filter-200` | `/guias/?categoria=documentos` | 200 | — | — |

Rows to add (same schema, same file) only if not already covered: a
representative EN single (200, self-canonical `/en/guias/…`), a representative PT
single (200, unchanged), `/en/guias/<pt-slug>/` (302 → PT, the B1
replaced-master), and an invalid EN guide URL (404).

## 16. Rollback plan

1. Before any write: capture a JSON snapshot of all 48 PT guides (id, slug,
   title, content, excerpt, status, date, author, menu_order, language, meta, all
   four taxonomy term sets, thumbnail) with a SHA-256 per record, plus the full
   `conexao_category` term table and the EN state.
2. The shared engine's own `assert_no_pt_drift()` re-compares that snapshot after
   every apply and fails the gate on any drift.
3. Rollback of the rollout: `php scripts/run-en-translation.php --remove --apply
   --only=guide` (the stage declares `allow_remove: true`) — it deletes only the
   EN records this manifest owns and never a PT original. The EN terms are removed
   by the same path's taxonomy removal.
4. Rollback of the *code* is `git revert` of this change; a code rollback does
   **not** undo a content change, so (3) is the content recovery.
5. Recovery proof after any rollback: PT snapshot re-hashed and compared, the
   completeness gate returns to its baseline number, orphan EN count returns to 0.

## 17. Documentation plan

| Document | Change |
|---|---|
| `docs/routing.md` | Only the Guides `/en/` row + the rollout-state line: the real EN records now exist, B1 policy unchanged. |
| `docs/plugins/conexao-en-translation.md` | The new `en-guide` dataset + taxonomy capability, and that this stage/dataset is now the authoritative source. |
| `docs/plugins/conexao-translation-rollout.md` | The additive optional taxonomy keys on the engine. |
| `docs/plugins/conexao-guide-translation.md` | A pointer that the data now lives in the shared engine and the plugin stays dormant. |
| `docs/reports/2026-09-28-b1-en-guides-rollout.md` | The completion report. |
| `docs/evidence/2026-09-28-b1-en-guides/*` | Machine evidence. |
| `AGENTS.md` / generated registry blocks | **Not hand-edited**; only through the generator. |
| Unrelated architecture docs | **Not rewritten.** |

## 18. Release implications

- Component version bumps: `conexao-en-translation` and
  `conexao-translation-rollout` headers (version source is the plugin main file,
  not `plugins.json`).
- Effect on the artifact allowlist: **none**. `plugins.json` `build` flags are
  untouched, so no release ZIP content changes.
- Is a release in scope? **No.** No commit, no tag, no deploy.

## 19. Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| Importing 51 rows creates duplicate EN identities for the 3 rows with no PT record | low | The engine skips a row whose PT record is absent (`PT record absent in this site (documented exclusion)`), and `slug_collision()` refuses any EN slug already held by an unlinked record. Both are verified in the dry-run. |
| A term-creation step writes a county/town duplicate | low | The new capability is scoped by the stage to `conexao_category` only; `conexao_county`/`conexao_town` are never in the term manifest. `test-taxonomy-policy.php` (18 assertions) is the gate. |
| Copying the retired data imports an error the retired code smoothed over | medium | Every imported row is measured: 0 missing title/body/excerpt/meta, 0 duplicate EN slugs, all 48 PT slugs covered. The retired suite's "EN body is not the PT body / no PT stop-words" assertions are re-run. |
| The engine change perturbs the other stages (`en-page`, `en-post`, `en-blog-page`, the two description stages) | medium | The new config keys are **optional and absent** everywhere else, so their plans, counters and gates are byte-identical. The engine suite is extended to assert exactly that. |
| The category filter or archive query needs a code fix afterwards | low | `/en/guias/?categoria=documents` already passes and `conexao_guide_category_filter_term_id()` already resolves cross-language slugs. Any fix is a separate, separately-verified change. |
| **Scope creep** into "improving" the Blog/Jobs/Lazer translation debt the same run exposes | medium | Those suites fail at baseline for unrelated reasons. They are recorded as pre-existing and left untouched. |
| The permanent completeness gate moves and a *different* type regresses | low | The gate output is captured before and after and compared per type; any non-guide regression is a failure of this change. |

## 20. Verification gates

1. `php scripts/run-en-translation.php --dry-run --only=guide` → 48 planned
   creates, 0 conflicts, 0 duplicates, 13 term creates, 0 PT changes.
2. Snapshot written and **verified** before apply.
3. `php scripts/run-en-translation.php --apply --only=guide` → `created=48`,
   `pt_changed=0`, `GATE PASS`.
4. PT snapshot re-hashed post-apply → **48/48 byte-identical**.
5. `php scripts/run-en-translation.php --dry-run --only=guide` (second run) →
   `created=0 updated=0`, terms 0.
6. `./scripts/run-tests.sh` → compared numerically against the recorded baseline.
7. `python3 scripts/verify-permanent-gates.py` → 7 gates; translation completeness
   48 → **0**.
8. `php scripts/generate-registry-docs.php --check` → exit 0.
9. `./scripts/lint.sh` → PHPCS/PHPStan/syntax clean or a documented baseline.
10. `git diff --check` → clean.

## 21. Completion criteria

Restated from `docs/engineering-standard.md` §14 and the task: 48/48 PT guides
have real, published, linked EN translations; 0 untranslated; 0 orphan EN; 0
duplicate EN; 13/13 used `conexao_category` terms translated correctly with
county/town untouched and still shared; PT content byte/value identical to the
pre-rollout snapshot; `/en/guias/` populated with no PT fallback rendered;
representative EN singles, pagination and category filtering working; canonical
and hreflang correct; the second run idempotent; no B2 widening, no fallback, no
second translation lifecycle; all applicable permanent gates pass; full numeric
test results recorded; `git diff --check` clean; no production write; no
Flutter/mobile file touched.

_Last verified: 2026-09-28 by the B1 English Guides content rollout_





