# Plan — English descriptions for the `/en/cursos` course providers

| | |
|---|---|
| **Task title** | Add English descriptions to the `/en/cursos` course-provider archive |
| **Date** | 2026-09-27 |
| **Author / agent** | Cline |
| **Branch** | `i18n` |
| **Repository baseline (starting SHA)** | `53f178185bbcc1cdbc1c1a36c724dff5ba6e9030` ("Add English description stage for leisure records") |
| **Plan status** | approved |

## 1. Scope

Add an English card description for each published `course_provider` record so
that `/en/cursos` renders English text in `.provider-card-excerpt` instead of the
Portuguese `post_excerpt`, and make the theme's provider card read that English
description on an EN request. Implemented as one new stage —
`en-course-provider-description` — on the **existing shared** engine
(`conexao-translation-rollout`), supplied by the **existing** data+config
consumer (`conexao-en-translation`), exactly mirroring the completed Stage 7
Leisure description remediation (`en-leisure-description`).

## 2. Explicit non-goals

Named so a reviewer does not read them as oversights:

- **Not** redesigning the provider card, its markup, classes or CSS. Only the
  *source* of one string changes; `.provider-card-excerpt`, the 20-word
  `wp_trim_words()` trim and `esc_html()` are byte-identical in both languages.
- **Not** changing `/en/cursos` routing, canonical, hreflang or the B2 policy.
- **Not** creating linked EN `course_provider` posts. `course_provider` is a
  documented **B2** type (`conexao_b2_post_types()`,
  `inc/i18n/fallback.php:277`); creating EN records would fork identities, alter
  the B2 policy and create duplicates. Rejected in favour of an authored EN field
  on the same record (the sanctioned `_leisure_excerpt_en` precedent).
- **Not** reviving a retired translation plugin. `conexao-leisure-translation` is
  the precedent and is retired; no course-provider equivalent exists and none is
  created. There is no `apply.php` / `audit.php` / admin class copy.
- **Not** touching the provider CTA, `_provider_url`, `_provider_category`,
  `_provider_location`, the logo, or the `?categoria=` filter.
- **Not** translating `_provider_category` (a `course_provider` filter value) —
  that is a separate, out-of-scope taxonomy question.
- **Not** touching PT title/excerpt/content/slug/status/terms/meta, and not
  touching any other content type.
- **Not** weakening any gate, allowlist, threshold or baseline.
- **Not** deploying or writing to production. Production has no CLI; the stage is
  operated by a maintainer through the admin screen.
- **Not** touching the Flutter/mobile repository.

## 3. Relevant authoritative documents

`AGENTS.md`, `docs/engineering-standard.md` (§0 principles 1–7, §5.2 six-step
contract, §6.1 bilingual non-negotiables, §6.2, §6.3, §13.2, §14),
`docs/routing.md` §English rollout state (B1/B2 policy), `docs/content-model.md`
§Course Provider (meta keys), `docs/templates/plan.md`, `docs/templates/report.md`,
`docs/plugins/conexao-translation-rollout.md` (the shared engine + the additive
`stage_conflict` key), `docs/plugins/conexao-en-translation.md` (Stage 7
precedent), `docs/themes/conexao-br-irlanda.md` (card template facts),
`.agents/skills/wp-translation-rollout/SKILL.md`.

## 4. Current-state findings

Verified by reading the code and running commands, not assumed.

- **HEAD** is `53f1781`; the working tree is **clean** (`git status --porcelain`
  empty). No pre-existing modified or untracked files to preserve.
- **Defect reproduced over real HTTP.** All **10** cards on `/en/cursos` render
  Portuguese `.provider-card-excerpt` text; `/cursos` renders the same 10
  Portuguese strings.
- **Root cause is a data + read-path gap, not Polylang and not the archive/query.**
  - `course_provider` is a **B2** type (`conexao_b2_post_types()` →
    `array( 'event', 'leisure', 'sponsor', 'course_provider', 'job' )`).
    `/en/cursos` is a B2 destination: the PT records render under the EN shell
    with the notice. Routing, canonical and hreflang are correct and are not at
    fault.
  - There is **no EN `course_provider` record at all**: all 10 have
    `pll_get_post(id,'en') = 0`, and the completeness gate reports
    `course_provider: eligible 10, translated 0, allowlisted 10, missing_en 0`.
  - There is **no English description field**: no `_provider_*_en` key exists
    anywhere; the only `%excerpt_en%` meta in the database is
    `_leisure_excerpt_en` (289 rows, the Leisure layer).
  - **The provider card has no language-aware read path at all.**
    `template-parts/provider-card.php:40` calls
    `wp_trim_words( get_the_excerpt(), 20, '...' )` directly, whereas
    `template-parts/leisure-card.php:161` routes through
    `conexao_leisure_card_excerpt()` (which reads `_leisure_excerpt_en` on an EN

  | id | slug | title | PT excerpt | EN desc | EN record |
  |---|---|---|---|---|---|
  | 10063 | apprenticeship-ie | Apprenticeship.ie | present | empty | none |
  | 492 | courses-ie | Courses.ie | present | empty | none |
  | 493 | etbi | Education and Training Boards Ireland | present | empty | none |
  | 200 | fetch-courses | FETCH Courses | present | empty | none |
  | 491 | local-enterprise-office | Local Enterprise Office | present | empty | none |
  | 10064 | microcreds | MicroCreds | present | empty | none |
  | 201 | qualifax | Qualifax | present | empty | none |
  | 489 | skillnet-ireland | Skillnet Ireland | present | empty | none |
  | 490 | solas-ecollege | SOLAS eCollege | present | empty | none |
  | 202 | springboard-plus | Springboard+ | present | empty | none |

  Eligibility categories: **(1)** valid EN present: **0**; **(2)** EN empty with a
  valid PT source: **10** (all eligible); **(3)** no PT description, nothing to
  translate: **0**; **(4)** intentionally shared/B2: all 10 *are* B2 by post-type
  policy — that is the record-level strategy, not an exemption, so **0** records
  are excluded as "already translated"; **(5)** malformed/orphan translation
  relationships: **0**; **(6)** test/fixture records: **0**.

## 5. Proposed approach

**Strategy: an authored EN field on the same PT record** — `_provider_excerpt_en`
— the same choice Stage 7 made for Leisure, for the same reason: the post type is
B2, so there is no second identity to create.

Chosen field name: `_provider_excerpt_en`, following the existing
`_leisure_excerpt_en` convention and the `_provider_*` meta namespace. It is a new
key; `_leisure_excerpt_en` is not reused (wrong post type, wrong namespace).

Components, all on existing shared machinery:

1. **Versioned data** —
   `conexao-en-translation/includes/course-provider-description-data.php`,
   **10 rows** keyed by PT slug, each `{pt_source, pt_title, en_description}`.
   `pt_source` is the PT-drift reference; the English is a faithful translation of
   it with no invented course categories, providers, funding, eligibility or
   schedules.
2. **Stage adapter + config** —
   `.../includes/course-provider-description-stage.php`, registering stage
   `en-course-provider-description` on `Conexao_Translation_Rollout_Engine`.
   Contains **no** lifecycle code; reuses the engine unchanged, including the
   additive `stage_conflict` key for PT-drift refusal (same as the Leisure stage).
3. **Minimal theme read path** — add `conexao_provider_card_excerpt()` to
   `inc/i18n/fallback.php` (the proven shape of `conexao_leisure_card_excerpt()`)
   and call it from `template-parts/provider-card.php:40`. PT keeps the exact

## 6. Files expected to change

| Path | Change | Why |
|---|---|---|
| `wp-content/plugins/conexao-en-translation/includes/course-provider-description-data.php` | **added** | versioned 10-row authored EN dataset |
| `wp-content/plugins/conexao-en-translation/includes/course-provider-description-stage.php` | **added** | stage adapter + declarative config on the shared engine |
| `wp-content/plugins/conexao-en-translation/conexao-en-translation.php` | modified | require the two new files; register the stage; bump version |
| `wp-content/plugins/conexao-en-translation/includes/stage-config.php` | modified | add the stage id to `conexao_en_translation_stage_ids()` |
| `wp-content/themes/conexao-br-irlanda/inc/i18n/fallback.php` | modified | add `conexao_provider_card_excerpt()` |
| `wp-content/themes/conexao-br-irlanda/template-parts/provider-card.php` | modified | one call: read the description through the helper |
| `wp-content/themes/conexao-br-irlanda/tests/test-course-provider-card-excerpt-language.php` | **added** | in-process rendering + rollout-engine invariant checks |
| `tests/acceptance/matrices/routing.json` | modified | focused `/cursos` + `/en/cursos` rows |
| `docs/plugins/conexao-en-translation.md`, `docs/plugins/conexao-translation-rollout.md`, `docs/themes/conexao-br-irlanda.md`, `docs/content-model.md`, `docs/reports/2026-09-27-en-course-provider-descriptions.md` | modified/added | documentation, per the change→document map |

    request and the PT excerpt otherwise). So unlike Leisure, **both** the data
    and a minimal read path are missing here. The template is not "defective" in
    the sense of being wrong — its PT behaviour is correct and must not change —
    but it has no EN branch, so one must be added for the translation layer to be
    reachable at all. That is the minimum possible template change: one helper
    call, mirroring the proven Leisure helper, with identical trim/escaping.

  Inventory (10/10 records, all `publish`, all `lang=pt`, all with a non-empty
  `post_excerpt` and empty `post_content`; meta in use: `_provider_logo`,
  `_provider_category`, `_provider_location`, `_provider_url`, `_provider_status`,
  `_provider_order`):

## 7. Files explicitly expected NOT to change

| Path | Why it must stay untouched |
|---|---|
| Polylang configuration | No translation-policy change; B2 stays B2 |
| `wp-content/themes/conexao-br-irlanda/assets/css/*` | no CSS change |
| `wp-content/themes/conexao-br-irlanda/template-parts/leisure-card.php` | the Leisure card is already correct |
| `conexao_b2_post_types()`, `conexao_b2_page_allowlist()` | B1/B2 policy unchanged |
| `plugins.json` | no plugin added, removed or re-classified; registry drift must stay 0 |
| gate definitions / allowlists / baselines (`docs/evidence/*/gate.json`, `phpcs-baseline.json`, `phpstan-baseline.neon`) | gates stay fail-closed and unmodified |
| `conexao-leisure-translation` (retired) and all other retired rollout plugins | never revived |
| PT `wp_posts` rows / PT meta for the 10 providers | Portuguese is immutable |
| The Flutter/mobile repository | out of scope |

## 8. Content / data impact

- Records created: **0**. Records updated: **10** (one post-meta field each).
  Records deleted: **0**.
- Portable identifier strategy: the **PT slug** (`post_name`), the only portable
  identity. No local post ID is used as identity anywhere.
- Writes content? **Yes** → the six-step contract applies; the dry-run output is
  captured as evidence before any write.
- PT content changed? **No.** Only `_provider_excerpt_en`, an English-only field
  the PT render path never reads.

## 9. Route / HTTP impact

- Routes added/removed/changed: **none**.
- Redirects: **none**; no precedence decision is involved.
- Sitemap: **unchanged** — the stage adds no record, so B2 exclusion is unaffected.
- HTTP matrix rows to add: `/en/cursos/` EN description for FETCH Courses and for
  the full page-1 set, absence of the PT string from the corresponding EN card,
  `/cursos/` PT immutability at the render level, and `/en/cursos/` canonical +
  hreflang.

## 10. Polylang / English impact

- Translated post types/taxonomies affected: **none**. No term, no post language,
  no relationship. `course_provider` remains B2 with 0 linked EN records.
- Is PT immutable? **Yes** — enforced by the engine's `assert_no_pt_drift()`
  over title, slug, content, excerpt, status, date, author, menu order, thumbnail,
  terms, language and the `_provider_*` meta.
- B1/B2 per destination: `/en/cursos/` remains **B2** (PT records under the EN
  shell + notice). Unchanged.
- Canonical / hreflang / switcher: **unchanged**; asserted by HTTP rows.
- Language-scoped cache keys: **none** introduced — the field is read per request
  inside the card, exactly as the Leisure field is; no transient is added.
- Completeness gate: the existing `translation_completeness` gate must stay exactly
  as it is (`course_provider: missing_en 0, malformed 0`, allowlisted 10). The new
  stage carries its **own** numeric gate: eligible 10, with_en 10,
  **missing_en 0**, conflicts 0, pt_drift 0.

## 11. Security impact

- Capabilities: unchanged — the engine's admin screen still requires
  `manage_options`; this stage adds no new capability.
- Nonces: unchanged — `check_admin_referer()` on every write path.
- Sanitisation/escaping: the authored English is stored via `update_post_meta()`;
  the card renders it through the existing `esc_html()`. No new output path.
- SQL: no new query; WP APIs only.
- REST surface: **no** new endpoint.
- Secrets: none; credentials from the environment only.

## 12. Performance impact

- Queries added/removed: **none**. One `get_post_meta()` call per card in an
  archive page already reading six provider meta keys.
- Caching: no new transient; nothing language-scoped to invalidate because the
  value is read per request.
- Assets/images: none.
- Expected improvement: none claimed; the change is a text-source swap.

## 13. Production impact

| Question | Answer |
|---|---|
| Will anything be written to production? | **no** |
| Will a deploy, upload or activation happen? | **no** |
| Who performs it, and how (production has no SSH/WP-CLI)? | A maintainer, via Tools → **Translation Rollouts** → Preview → Apply (`--only=course-provider-description`). Not performed here. |
| What is the verification of the production result? | The stage's own numeric gate (`missing_en 0`, `conflicts 0`, `pt_drift 0`) plus the same HTTP rows, re-run on production by the operator. |

## 14. Test plan

- In-process suite to add:
  `wp-content/themes/conexao-br-irlanda/tests/test-course-provider-card-excerpt-language.php`,
  rendering the real `provider-card.php` in a PT and an EN context, plus the engine
  invariants (preview zero-writes, PT-drift refusal, idempotent re-apply, remove
  rollback, no-EN-source not translated, existing EN not clobbered).
- Acceptance rows: as in §9.
- Script contract: `./scripts/run-tests.sh --scripts` (unchanged; no new script).
- Registry drift: `php scripts/generate-registry-docs.php --check` must stay 0
  (`plugins.json` is not modified).
- Pass = numerically: stage gate `missing_en = 0`, `conflicts = 0`, `pt_drift = 0`;
  `pt_drift = 0` for the whole suite run; all new HTTP rows pass; all permanent
  gates pass; and the full run introduces **no new** failing suite.

   `get_the_excerpt()` pipeline; EN returns the authored English when present and
   the approved B2 fallback otherwise. Trim (20 words) and `esc_html()` unchanged.
4. **Registration** — add the stage to `conexao_en_translation_register_stages()`
   and `conexao_en_translation_stage_ids()`; one runner (`run-en-translation.php
   --only=course-provider-description`) already exists and needs no new script.

**Options rejected:** (a) linked EN `course_provider` posts — would change the B2
policy and duplicate identities; (b) a new/retired course-provider translation
plugin or `apply.php` — a second translation lifecycle, forbidden; (c) translating
`_provider_category` — out of scope; (d) a broad normalisation of the PT excerpt —
would risk hiding real content drift, so the drift guard stays narrow.

## 15. Acceptance matrix plan

Rows added to `tests/acceptance/matrices/routing.json` (schema `id`, `url`,
`expect_status`, `expect_contains`, `expect_absent`, `note`):

| id | url | contains | absent |
|---|---|---|---|
| `en-cursos-fetch-card-excerpt-is-english` | `/en/cursos/` | `Find training and continuing education courses throughout Ireland` | `Encontre cursos de formação e educação continuada em toda a Irlanda` |
| `en-cursos-no-portuguese-card-excerpt-leak` | `/en/cursos/` | `class="provider-card-excerpt"` | the 10 PT `.provider-card-excerpt` openings |
| `pt-cursos-card-excerpt-stays-portuguese` | `/cursos/` | `Encontre cursos de formação e educação continuada em toda a Irlanda` | `Find training and continuing education courses` |
| `en-cursos-canonical-and-hreflang` | `/en/cursos/` | self-canonical `/en/cursos/` + `hreflang="pt-BR" href=".../cursos/"` | PT canonical on the EN URL |

Expected new assertion count: **4 rows**.

## 16. Rollback plan

1. `php scripts/run-en-translation.php --remove --apply --only=course-provider-description`
   — deletes only `_provider_excerpt_en` on the 10 PT records and re-asserts PT
   immutability (`allow_remove: true`, same justification as the Leisure stage:
   the EN layer is a single reproducible field).
2. The B2 fallback re-engages automatically on `/en/cursos/`.
3. The theme read path is a pure addition and is inert without the field.
4. Pre-change state to capture: the snapshot payload the engine returns before
   the first write, stored as evidence, plus a `/cursos` HTTP body capture.
5. What rollback does **not** cover: a PT edit made by someone else between the
   snapshot and the rollback — the PT-drift guard reports that as a failure rather
   than silently reverting it.

## 17. Documentation plan

| Document | Change |
|---|---|
| `docs/plugins/conexao-en-translation.md` | document stage `en-course-provider-description` (strategy, field, 10 rows, gate numbers) |

## 18. Release implications

- Version bumps: `conexao-en-translation` 1.3.0 → 1.4.0 (plugin **header**, the
  authoritative source; `plugins.json` carries no version). The theme version is
  bumped only if the theme header convention requires it for a template change.
- Artifact allowlist: unchanged — both plugins are local-only tooling with
  `build: no`, so no ZIP content changes.
- Is a release in scope? **No.** No production deploy is in scope.

## 19. Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| The new theme helper changes the PT output | low | PT branch returns the exact `get_the_excerpt()` pipeline; asserted by a PT-render test and a `/cursos` HTTP row |
| A stale translation is applied after PT drift | medium | the stage sets the additive `stage_conflict` key; a changed `post_excerpt` becomes a hard conflict and fails the gate |
| Idempotence broken (field rewritten every run) | low | the write is a no-op when the stored value equals the authored value; asserted by a second-run count of 0 created / 0 updated |
| **Scope creep into unrelated i18n debt** | medium | explicit non-goals in §2; the baseline's pre-existing failures (event importer, blog category linkage, town sanitisation) are recorded and left alone |
| Over-broad normalisation hides real PT drift | low | reuse the Leisure normaliser unchanged (entities, whitespace, typographic punctuation only; never case/accents/words) |
| Duplicated EN identities | low | the stage mints no slug and no post; `slug_collision` always false and no `wp_insert_post()` path exists |
| Stage scope mistaken for a taxonomy/B2 change | low | the stage writes one meta key; the completeness gate's `course_provider` numbers must be identical before and after |

## 20. Verification gates

1. `php scripts/run-en-translation.php --dry-run --only=course-provider-description` — plan shows 10 creates, 0 conflicts, **0 writes**, and the PT snapshot.
2. `php scripts/run-en-translation.php --apply --only=course-provider-description` — gate `missing_en = 0`, `conflicts = 0`, `pt_drift = 0`.
3. Second `--apply` — `0 created, 0 updated, N skipped` (idempotence).
4. `docker compose exec wordpress php .../tests/test-course-provider-card-excerpt-language.php` — all assertions pass.
5. `./scripts/run-tests.sh` — no newly introduced failures vs the recorded baseline; all permanent gates pass.
6. `python3 scripts/verify-permanent-gates.py` — every gate reported with its exact number.
7. `php scripts/generate-registry-docs.php --check` — 0 drift.
8. HTTP: `/cursos` byte-identical to the pre-change capture; `/en/cursos` shows English.

## 21. Completion criteria

Per `docs/engineering-standard.md` §14: scope matches the request; PHP syntax +
PHPCS + PHPStan show no new violations; an in-process test and HTTP matrix rows
were added; content writes produced a dry-run, an idempotent apply, a
PT-unchanged assertion and a numeric gate of 0; EN work keeps §6.1 (no second
identity, canonical/hreflang/sitemap unchanged, cache scoping untouched, taxonomy
policy respected); documentation is updated in the same change; no report at the
repository root; no secrets, no committed build output; report written with real
numbers and stated limitations; Flutter/mobile untouched. Every rollout ends with
a number: **eligible public PT course providers missing an English description = 0**.

_Last verified: 2026-09-27 by the EN Course Provider description rollout_

| `docs/plugins/conexao-translation-rollout.md` | add the second `stage_conflict` consumer to the additive-extension section |
| `docs/themes/conexao-br-irlanda.md` | `provider-card.php` now language-aware via `conexao_provider_card_excerpt()` |
| `docs/content-model.md` | add `_provider_excerpt_en` to §Course Provider |
| `docs/reports/2026-09-27-en-course-provider-descriptions.md` | the report (`docs/templates/report.md`) |
| `docs/evidence/2026-09-27-en-course-provider-descriptions/` | machine evidence |
| `AGENTS.md` / registry | **not** edited by hand; the generator is run with `--check` and reports no drift |
