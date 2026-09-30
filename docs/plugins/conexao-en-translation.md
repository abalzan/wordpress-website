# conexao-en-translation

## Purpose

The EN translation rollout (Stage M/N, completed for the Blog archive by Stage O). It closes the **pre-existing EN translation
completeness debt** that the permanent `translation_completeness` gate reports
for the three B1 content types — `guide`, `page` and `post` — by creating one
linked EN translation per eligible public PT record.

It is a **DATA + CONFIG consumer** of the shared
[`conexao-translation-rollout`](conexao-translation-rollout.md) engine, exactly
like the earlier `job` / `guide` / `blog` / `page` rollouts. It owns no
orchestration, no planning and no gate calculation: the engine implements the
content-change contract (inventory → manifest validation → dry-run plan →
snapshot → apply → verify + numeric gate → remove) and this plugin supplies only
the authored English and the WordPress-bound adapter.

| | |
|---|---|
| Folder | `wp-content/plugins/conexao-en-translation/` |
| Authored copy | `includes/guide-translation-data.php` (the **51-row EN Guide dataset** — see "The `en-guide` stage" below), `includes/guide-terms-data.php` (the **13** EN `conexao_category` terms used by those guides), `includes/manifest-data.php` (Stage M: the 1 earlier guide row + 29 pages + the first 8 Blog rows), `includes/blog-translation-data.php` (Stage N: the remaining 34 Blog rows), `includes/blog-page-data.php` (Stage O: the single Blog **posts page** row), `includes/jobs-page-data.php` (the single EN **Jobs landing page** row, `jobs-page-v1`), `includes/leisure-description-data.php` (Stage 7: the 289 Leisure card descriptions) and `includes/course-provider-description-data.php` (Stage 8: the 10 course-provider card descriptions) — separate versioned datasets, each keyed by PT slug |
| Adapter + config | `includes/stage-fields.php`, `includes/stage-config.php` (Stage O adds the `en-blog-page` stage: shared-slug filter, routing-cache refresh, shared-slug duplicate guard; the `en-jobs-page` stage reuses all three, with `conexao_en_translation_with_shared_page_slug()` as the single shared-slug permit), `includes/guide-stage.php` (the `en-guide` translated-taxonomy capability), `includes/leisure-description-stage.php` (Stage 7 adds the `en-leisure-description` stage) and `includes/course-provider-description-stage.php` (Stage 8 adds the `en-course-provider-description` stage) |
| Manifest shape | `includes/translation-map.php` |
| Runner | `scripts/run-en-translation.php` |
| Admin screen | none (the rollout is operated through the shared engine's screen and the runner script) |
| Frontend effect | **none** — it creates EN content records only; no template, route or runtime hook |
| Safe to deactivate | yes, after the rollout has been applied and verified |

## The `en-guide` stage — the real English Guides archive

`guide` is a **B1** type: `/en/guias/` must be populated by real, authored EN
`guide` records and must **never** render the Portuguese body under an English
shell. `conexao_b2_post_types()` deliberately omits `guide`, so nothing about the
B1/B2 policy changed to make this work — the archive was empty only because the EN
records did not exist yet.

| | |
|---|---|
| Stage id | `en-guide` |
| Strategy | **linked EN record** (one real, published, Polylang-linked EN `guide` per eligible public PT guide) |
| Dataset | `includes/guide-translation-data.php` — **51 rows** keyed by PT slug, each `{en_slug, en_title, en_excerpt, en_meta_description, en_content}` |
| Term dataset | `includes/guide-terms-data.php` — **13 rows** keyed by PT term slug, the EN `conexao_category` terms the published PT guides actually use |
| Adapter + config | `includes/guide-stage.php` (taxonomy capability) + the shared `includes/stage-config.php` config for `guide` |
| Records created | **48** EN `guide` posts + **13** EN `conexao_category` terms |
| Numeric gate | `eligible_public_pt = 48`, `with_en = 48`, **`missing_en = 0`**, `conflicts = 0`, `pt_drift = 0`, `extra_failures = 0` |
| `allow_remove` | `true` — remove deletes only the EN records and EN terms this stage owns |

### Why the data was imported, not re-authored

The complete authored English for these guides already existed in the **retired**
[`conexao-guide-translation`](conexao-guide-translation.md) plugin (Stage 9). That
plugin's *lifecycle* is retired and stays dormant — it is not activated, and it is
not a second translation engine. Its *data* is authoritative, so it was imported
into this stage once, mechanically, rather than re-translated. Concretely: the
`en-guide` manifest previously held **1** row whose PT guide does not exist on
this site, so `/en/guias/` had nothing to show.

Three of the 51 rows have no PT guide on this site
(`beneficios-pais-solteiros-irlanda`,
`inverno-irlanda-depressao-sazonal-saude-mental`,
`violencia-domestica-irlanda-onde-encontrar-ajuda`). They are **portable
exclusions**: the shared engine reports each one as
`PT record absent in this site (documented exclusion)`, so the dataset stays
portable and nothing is silently dropped.

### The translated-taxonomy capability

An EN guide must be filed under the **EN counterpart** of its PT
`conexao_category` term, so the EN term has to exist first. The shared engine
originally owned no term-creation step, which meant a stage had to re-implement a
lifecycle to get one — forbidden. The engine therefore gained two **optional,
additive** keys, `taxonomy_callback` and `taxonomy_gate_callback`; the engine
still owns the ordering (taxonomy before the record plan, dry-run aware) and
folds the taxonomy failure count into the same numeric gate. Only `en-guide`
declares them, so every other stage's plan, counters and gate are unchanged.

`conexao_en_translation_guide_term_stored()` is worth knowing about:
`wp_insert_term()` runs a term name through KSES, so the authored
`'Immigration & Visas'` is stored as `'Immigration &amp; Visas'`. The repair
comparison and the taxonomy gate both normalise to the **stored** form, without
which a term containing `&` would be rewritten on every run and the idempotence
guarantee would be false.

### Taxonomy policy

`conexao_category` is **translated**; `conexao_county` and `conexao_town` are
**shared** and are never created, renamed, re-slugged, duplicated or translated
here. `conexao_en_translation_guide_taxonomy_gate()` re-asserts that on every run
(0 failures), alongside the permanent `test-taxonomy-policy.php` gate.

## Stage 7 — the `en-leisure-description` stage

The Leisure archive is a **B2** type: `/en/lazer/` serves the Portuguese records
under the English shell. Stage 7 does not change that policy. It translates the
one thing the B2 fallback got wrong for a reader — the **card description** —
by writing an authored English field onto the **same** PT record.

| | |
|---|---|
| Stage id | `en-leisure-description` |
| Strategy | **authored EN field on the same record** (`_leisure_excerpt_en` post meta). The alternative the `wp-translation-rollout` skill sanctions, and the correct one here: creating linked EN `leisure` posts would change the B2 policy, canonical/hreflang/sitemap behaviour and duplicate identities. |
| Dataset | `includes/leisure-description-data.php` — **289 rows**, keyed by PT slug, each `{pt_source, pt_title, en_description}` |
| Adapter + config | `includes/leisure-description-stage.php` |
| Records created | **0** — there is no `wp_insert_post()` path in the stage at all |
| Fields written | **1** — `_leisure_excerpt_en` on the existing PT record |
| Numeric gate | `eligible_public_pt = 289`, `with_en = 289`, **`missing_en = 0`**, `conflicts = 0`, `pt_drift = 0` |
| `allow_remove` | `true` — the EN layer is a single reproducible field, so remove is a safe first-class rollback |
| Supersedes | the retired `conexao-leisure-translation` plugin's private `apply.php` / `audit.php` lifecycle |

### How a field-on-the-same-record stage maps onto the engine

The engine's vocabulary is record-oriented; the stage maps it explicitly rather
than pretending:

| Engine concept | This stage |
|---|---|
| `find_pt()` | the published PT `leisure` record for the manifest slug (`lang => ''` — the records are PT) |
| `find_en_for_pt()` | "already translated" **iff** a non-empty `_leisure_excerpt_en` is stored; also reports a `stage_conflict` when the PT source has drifted |
| `create_en()` / `repair_en()` | the one `update_post_meta()` write |
| `link_pair()` | a **deliberate no-op** — there is no second identity, so there is no pair to link |
| `pair_ok()` | the stored value equals the authored English, byte-for-byte |
| `remove_en()` | `delete_post_meta()` — the B2 fallback re-engages automatically |
| `slug_collision()` | always `false` — the stage mints no slug, so no URL can collide |

### PT-drift guard

Every dataset row carries the Portuguese description the English was authored
against. If the live `post_excerpt` no longer matches, the row is declared a
**hard conflict**: nothing is written and the numeric gate fails, so a stale
translation can never silently land.

The comparison is **normalised on both sides** (HTML entities decoded,
whitespace collapsed, typographic punctuation folded). This is what separates an
encoding artifact from a content change: the authored dataset was captured from a
REST response, so 11 of its titles arrive as `King John&#8217;s Castle`, and
`sliabh-liag` carries `One Man’s Pass` (U+2019) where the restored local record
has `One Man's Pass` (U+0027). Neither is a content change, and neither is
allowed to block the rollout. Case, accents and word characters are **not**
folded, so a real edit to the Portuguese still refuses.

### Ineligible records

A published record whose `post_excerpt` is **empty** has no Portuguese source to
translate. Such a record is **ineligible by rule** — not allowlisted — and no
gate is edited to accommodate it. The rule is asserted by
`test-leisure-card-excerpt-language.php`, which reports the eligible and
ineligible counts separately.

### Usage

```bash
# dry run (zero writes)
php scripts/run-en-translation.php --dry-run --only=leisure-description

# apply
php scripts/run-en-translation.php --apply --only=leisure-description

# rollback: deletes only _leisure_excerpt_en and re-asserts PT immutability
php scripts/run-en-translation.php --remove --apply --only=leisure-description
```

On production the same operations run through the shared engine's **Tools →
Translation Rollouts** screen (Preview → Apply → Remove), because WordPress.com
has no WP-CLI.

## Why this stage exists

Stage L installed a permanent, fail-closed completeness gate
(`test-translation-completeness.php`) that measures
`eligible public PT <type> missing EN = 0`. Against the dataset it correctly
reported **72 missing EN translations** as pre-existing debt:

| Post type | Eligible PT | Translated | Missing EN | Policy |
|---|---|---|---|---|
| `guide` | 53 | 52 | 1 | B1 |
| `page` | 44 | 4 | 29 | B1 (11 are B2-allowlisted by policy) |
| `post` | 42 | 0 | 42 | B1 |

The B2 directory types (`event`, `leisure`, `sponsor`, `course_provider`,
`job`) are **not** in this stage: they are already covered by the documented B2
policy (`conexao_b2_post_types()`), which the gate reports explicitly. Adding
them here would create a second, competing policy — exactly what
`docs/routing.md` forbids.

## The rules this stage must not break

- **Portuguese is immutable.** A PT record is only ever read. The engine
  re-captures a full PT snapshot after an apply and reports any difference as
  `PT drift`, which fails the numeric gate.
- **English is a layer, never a fork.** Every EN record is created as a
  *linked translation* of the PT master and the pair is verified in **both**
  directions (`pll_get_post(pt,'en') === en` and `pll_get_post(en,'pt') === pt`).
- **The PT slug is the only portable identity.** The manifest is keyed by PT
  slug; local post IDs are never used as cross-environment identity.
- **The EN body is authored English**, never a copy of the PT body.
- **Shared proper-name terms stay shared.** `conexao_county` and `conexao_town`
  are copied as the *same physical term*; `conexao_category` is linked to its EN
  counterpart through Polylang, never duplicated.
- **No second engine.** Everything lifecycle-related is the shared engine's.

## The numeric gate

```
eligible public PT <type> = N
with EN                  = N
missing EN               = 0     <-- the invariant
conflicts                = 0
PT drift                 = 0
GATE                     = PASS
```

A conflict is a *hard stop*, never a silent overwrite: an EN slug already used
by an unlinked record, or an existing EN record whose pair link is broken, is
reported and left alone for a maintainer to resolve.

## Stage N — the remaining 34 Blog translations

Stage M closed the `post` debt only partially (8 of 42). Stage N authored the
remaining **34** PT blog posts in a separate, versioned data file
(`includes/blog-translation-data.php`, data version `blog-v1`) and merged it
into the `post` manifest in `includes/translation-map.php`. The merge is a
**union keyed by PT slug** and fails closed on a duplicate slug, so no PT slug
can ever reach the engine twice.

`conexao_en_translation_blog_batches()` records the batch each row was applied
in — six batches of 4–6 posts, applied cumulatively, dry-run → snapshot →
`--apply` → gate after each one. The manifest is cumulative precisely so that a
re-run reports the earlier batches as `skipped`: the idempotence proof and the
batch record are the same artefact.

The shared engine, the stage adapter and the runner were **not** changed. The
only non-data edits are the plugin header (version 1.1.0 plus the new file in
its `require_once` list) and the merge in `translation-map.php`.

### Editorial rules applied to every row

- the third-party byline is preserved; a **name** is a proper noun and is never
  translated, while the **role** ("Terapeuta Sistônica" → "Systemic Therapist")
  is;
- every Instagram handle and every external URL is preserved byte-for-byte,
  including the advertising parameters on the support-organisation links;
- headings, lists, emphasis and block structure mirror the PT source;
- a preserved Portuguese **bibliography** is cited, not translated — a citation
  is not prose;
- no internal `conexaobr.ie` link is invented. None of the 34 PT sources contains
  a single `href`, so there was no Blog internal link to localise and none was
  fabricated.

### Result

`post_type:post:missing_en` went **34 → 0**, and the permanent-gate aggregate is
**7/7 passing, 0 violations**. The 42 eligible public PT blog posts now each have
exactly one linked EN translation. No allowlist was added, and
`conexao_b2_post_types()` / `conexao_b2_page_allowlist()` are untouched.

### Stage O — the EN blog archive is no longer B2

`/en/blog/` is a **real English archive**: 200, English shell, no
`language-fallback-notice`, self-canonical to `/en/blog/`, the full
`en` / `pt-BR` / `x-default` hreflang set, 42 English posts across 5 EN
pagination pages (page 6 is a 404), and `/blog/` unchanged.

Stage N reported the remaining item as a **policy fact, not debt**, and left it
deliberately:

- the Blog **archive** is the static posts page (`page_for_posts`, slug `blog`),
  and `blog` is on `conexao_b2_page_allowlist()` by policy;
- the documented retirement condition (`inc/b2-fallback.php`, Stage 5 comment) is
  *"a real, published EN posts page"* — a linked EN translation of the `blog`
  **page record**;
- creating that page record moves `blog` out of the allowlist and changes the
  aggregate allowlist count from **1821 to 1820**, which Stage N's acceptance
  criteria forbade.

Stage O is the stage that was authorised to accept that transition.

#### The Stage O data file

`includes/blog-page-data.php` — a **separate, single-record, versioned**
dataset (`blog-page-v1`) holding the authored English for the Blog **page**.
It is deliberately not merged into `manifest-data.php` (Stage M: guide + page)
nor into `blog-translation-data.php` (Stage N: the 42 `post` rows): the posts
page is a different post type, a different identity and a different lifecycle
stage, and mixing them would make one reviewable unit impossible.

Identity is the **PT page slug `blog`** — never a local post ID. The EN body
is a short authored-English archive introduction, not a copy of the PT body;
the visible archive is template-driven by `home.php`, so no layout content is
fabricated for the page.

#### The `en-blog-page` stage

A second stage in this plugin, applied through the **same shared engine**
(`Conexao_Translation_Rollout_Engine::run()`) and the **same runner**
(`scripts/run-en-translation.php --only=blog-page`). It is not a second engine
and not a second lifecycle. It exists separately from `en-page` for two
verified reasons:

1. **Scope.** `en-page` already plans two unrelated slug repairs (`jobs-2`,
   `newsletter` drifted to `jobs-2-2` / `newsletter-2` before the shared-slug
   hook existed). Applying `en-page` would have performed those two writes too.
2. **Shared slug.** The EN posts page must reuse the PT `post_name` so the
   archive stays at `/en/blog/`. WordPress makes page slugs unique per tree, so
   a plain `wp_insert_post()` would silently rename it to `blog-2`.

Two stage-level mechanics make that safe, both scoped to this one record:

- **the shared-slug filter**, armed around the *whole* apply (not a single
  write, because `copy_fields` runs `wp_update_post()` after the insert and core
  would re-uniquify the slug on that second pass);
- **the routing-cache refresh**, because Polylang caches each language's
  `page_for_posts` and would otherwise keep answering with the pre-apply
  mapping, leaving `/en/blog/` at 302 → `/blog/`.

Plus a **shared-slug duplicate guard** in `find_en_for_pt`: with a shared slug
the PT record shadows the generic `slug_collision` check, so an unlinked page
already sitting on the shared slug would have been invisible and a second EN
identity could have been created beside it. The guard reports that record as a
hard conflict instead, which is what the negative proofs exercise.

#### The `en-jobs-page` stage

A third stage, and the **second** single-record page stage, applied through the
same shared engine and the same runner
(`scripts/run-en-translation.php --only=jobs-page`). It reuses the Stage O
mechanics rather than copying them: the same engine adapter, the same
shared-slug duplicate guard, and the **same** `wp_unique_post_slug` permit —
`conexao_en_translation_with_shared_page_slug()` is now the single
implementation, and `conexao_en_translation_blog_page_with_shared_slug()` is a
thin alias over it, so the two stages cannot hold conflicting permits for the
same core hook.

It exists for the same two reasons:

1. **Scope.** `en-page` owns 30 unrelated rows; applying it would perform the
   `jobs-2` / `newsletter` repairs this stage must not touch.
2. **Shared slug.** `/en/empregos/` must reuse the PT `empregos` `post_name`,
   so the EN Jobs landing is the **shared-slug** shape already used by
   `/en/blog/`, rather than a second translated slug.

The stage writes **one field on one EN record**: `post_name` (`jobs` →
`empregos`). The authored EN title, body, excerpt and meta description are
carried verbatim in `includes/jobs-page-data.php` (version `jobs-page-v1`) so
that repairing the slug through the engine's `update` path cannot rewrite
content; the PT page is only ever read and the apply reports `PT-drift=0`.

#### The shared-slug policy, and `newsletter` as the third shared-slug page

The permit, the duplicate guard and the routing resolver are **one** mechanism
shared by every page stage that needs them. What changed on 2026-09-30 is not
the mechanism but **where the decision is recorded**: previously only
`en-blog-page` and `en-jobs-page` armed it, by construction, and there was no
single place that answered "may this stage reuse a PT `post_name`?".

`conexao_en_translation_shared_page_slug_for( $stage )` is that place. It reads
the stage's **own manifest** (via
`conexao_en_translation_stage_manifest()`) and returns the ONE slug the stage
declares — where "declares" means a row whose `en_slug` equals its PT stable
key. There is no allowlist, no slug constant and no per-page branch:

| stage | declared shared slug | holds the permit |
|---|---|---|
| `en-blog-page` | `blog` | yes |
| `en-jobs-page` | `empregos` | yes |
| `en-page` | `newsletter` | yes (since 2026-09-30) |
| `en-guide` | — | no |
| `en-post` | — | no |
| B2 field stages | — | no |
| an unknown stage | — | no (fail closed) |

It is **fail-closed in both directions**: zero declared slugs returns `''`, and
*more than one* also returns `''`. A stage in that ambiguous state gets no
exception, WordPress uniquifies normally, and the resulting drift is reported by
the dry-run instead of being silently permitted.

`conexao_en_translation_stage_adapter()` pairs the permit with the guard in one
decision, so the exception can never be armed without the duplicate protection
that makes it safe. The guard itself
(`conexao_en_translation_shared_slug_page_adapter()`, generalised from the
former `conexao_en_translation_blog_page_adapter()`, which remains as a thin
wrapper) fires **only** for the one declared slug — that scoping is what lets a
31-row stage such as `en-page` hold the permit without altering the generic
collision rules of its other 30 rows. `en-page` declares exactly one of 31.

`newsletter` therefore resolves at `/en/newsletter/` and never at
`/en/newsletter-2/`. It stays **B1**: it is deliberately absent from
`conexao_b2_page_allowlist()`, and the theme's own comment already classified
the narrative/utility pages (category hubs, `newsletter`, `revista`, `anuncie`,
`search`) as B1. The retired `conexao-page-translation` plugin had declared
`'newsletter' => array( 'en_slug' => 'newsletter', 'shared_slug' => true )`, so
this restores a documented intent rather than inventing one.

Two permanent contracts cover it:
`wp-content/plugins/conexao-en-translation/tests/test-shared-slug-newsletter.php`
(the policy, the permit's narrowness and the guard) and the `shared_slug_page`
permanent gate in
`wp-content/themes/conexao-br-irlanda/tests/test-en-jobs-shared-slug.php` (the
runtime resolution, canonical and hreflang). Both are discovered by convention
(`wp-content/plugins/*/tests/test-*.php`, `wp-content/themes/*/tests/test-*.php`).

#### The two re-keyed source identities (2026-09-30)

Two authored rows pointed at a PT identity that production no longer serves
under that key. Both were corrected as **source-identity** changes only, with
the authored English carried across byte-for-byte (verified by payload
SHA-256, see
`docs/reports/2026-09-30-en-rollout-blocker-resolution.md`):

- `outono-irlanda-alimentacao-bem-estar` moved from the `guide` block to the
  `post` block of `includes/manifest-data.php`: the real PT record is a
  **`post`** (production id 25028), not a `guide`.
- `carteira-de-motorista-2` → `carteira-motorista-brasileiros` in
  `includes/guide-translation-data.php`: the PT guide the row was authored
  against (id 463) was removed and its successor is production id 25031.

Seven further authored rows have **no** real PT source and are classified
`NO_REAL_PT_SOURCE`, an explicit editorial decision — not a silent exclusion and
not a deletion. They are listed with their evidence in
`docs/evidence/2026-09-30-en-rollout-blocker-resolution/05-source-resolution-table.txt`.

Note the data-level change is not sufficient on its own: WordPress resolves
`pagename` language-blind, so two pages on one slug made the request resolve to
the PT record and `/en/empregos/` still answered 302 → `/empregos/`. The
companion theme helper `conexao_resolve_shared_slug_page_request()`
(`inc/i18n/urls.php`) resolves that ambiguity — see
[`docs/routing.md` §"Shared-slug pages"](../routing.md).

#### Result

| Metric | Before Stage O | After Stage O |
|---|---|---|
| EN `blog` page | none | **1**, published, `en`, `/en/blog/` |
| PT ↔ EN bidirectional | n/a | yes |
| `page` allowlisted (aggregate) | 11 | **10** |
| aggregate allowlist count | 1821 (+30 unrelated events, see the Stage O report) | **1820 for the `page` bucket transition** |
| `post_type:post:missing_en` | 0 | **0** (Blog posts stay B1, never allowlisted) |
| `/en/blog/` | 200 + B2 notice, canonical → `/blog/` | 200, no notice, self-canonical |
| PT drift | — | **0** |

The `1821 → 1820` transition is a **consequence of the data change**: the
completeness gate counts a record as *allowlisted* only while it has no EN
translation, so creating the EN page moves `blog` from the `allowlisted` bucket
to the `translated` bucket on its own. `conexao_b2_page_allowlist()` still
contains the string `blog` (it is the policy definition, and the theme comment
says the entry is retained for installs that have not run the translation), but
`conexao_should_render_b2_fallback( blog )` and
`conexao_b2_posts_page_is_en_request()` both return `false` on this site, so the
B2 path is unreachable here. No gate, baseline, threshold or allowlist function
was edited to produce any of these numbers.

## Stage 8 — the `en-course-provider-description` stage

The Cursos archive is a **B2** type, exactly like Lazer: `course_provider` is in
`conexao_b2_post_types()`, so `/en/cursos/` serves the Portuguese records under the
English shell. Stage 8 does not change that policy. It translates the one thing
the B2 fallback got wrong for a reader — the **card description** — by writing an
authored English field onto the **same** PT record.

It exists because the provider card was the one B2 card with **no** language-aware
read path at all: `template-parts/provider-card.php` called `get_the_excerpt()`
directly, so there was neither data nor anywhere to put it. Stage 7 Leisure
already had `conexao_leisure_card_excerpt()`; Stage 8 adds the exact counterpart
`conexao_provider_card_excerpt()`.

| | |
|---|---|
| Stage id | `en-course-provider-description` |
| Strategy | **authored EN field on the same record** (`_provider_excerpt_en` post meta). Creating linked EN `course_provider` posts would change the B2 policy, canonical/hreflang/sitemap behaviour and duplicate identities. |
| Dataset | `includes/course-provider-description-data.php` — **10 rows**, keyed by PT slug, each `{pt_source, pt_title, en_description}` |
| Adapter + config | `includes/course-provider-description-stage.php` |
| Records created | **0** — there is no `wp_insert_post()` path in the stage at all |
| Fields written | **1** — `_provider_excerpt_en` on the existing PT record |
| Numeric gate | `eligible_public_pt = 10`, `with_en = 10`, **`missing_en = 0`**, `conflicts = 0`, `pt_drift = 0` |
| `allow_remove` | `true` — the EN layer is a single reproducible field, so remove is a safe first-class rollback |
| B2 / completeness | `course_provider` stays a B2 type with **0** linked EN records; the permanent `translation_completeness` gate numbers are unchanged (`eligible 10, translated 0, allowlisted 10, missing_en 0, malformed 0`) |

The field name follows the existing `_leisure_excerpt_en` convention inside this
post type's own `_provider_*` meta namespace. `_leisure_excerpt_en` is **not**
reused: wrong post type, wrong namespace.

### Engine mapping

Identical to Stage 7 (see the table above and the equivalent rows for
`en-leisure-description`): `find_pt()` resolves the published PT
`course_provider` for the slug (language-unfiltered, `suppress_filters`),
`find_en_for_pt()` reports "already translated" only when a non-empty
`_provider_excerpt_en` is stored and raises the additive `stage_conflict` key
when the PT `post_excerpt` has drifted, `create_en()`/`repair_en()` perform the
one `update_post_meta()` write, `link_pair()` is a deliberate no-op (there is no
second identity to link), `pair_ok()` asserts the stored value equals the
authored English, `remove_en()` deletes the field, and `slug_collision()` is
always false because the stage mints no slug.

The PT snapshot deliberately **excludes** `_provider_excerpt_en` (it is the field
this stage owns) and **includes** everything that defines the Portuguese record:
title, slug, content, excerpt, status, date, author, menu order, thumbnail, the
four taxonomy assignments, the Polylang language and the `_provider_*` meta.

The drift normaliser is the Stage 7 one, reused unchanged (HTML entities,
whitespace runs, typographic punctuation; never case, accents or word
characters), so a representation difference is not content drift but a real
Portuguese edit is still refused.

### Result

| Metric | Before Stage 8 | After Stage 8 |
|---|---|---|
| `/en/cursos` provider descriptions | 0 English (10 Portuguese) | **10 English, 0 leaks** |
| `_provider_excerpt_en` rows | 0 | **10** |
| `course_provider` records / EN records | 10 / 0 | 10 / **0** (unchanged) |
| `/cursos` rendered output | Portuguese | **byte-identical** |
| PT drift | — | **0** |
| Stage gate | `missing_en = 10` (FAIL) | **`missing_en = 0`** (PASS) |

## Rollback

`allow_remove: true`, so the rollback is a first-class operation:

```bash
php scripts/run-en-translation.php --remove --apply --only=guide
```

It deletes only the EN records this manifest owns and re-asserts that the PT
originals are unchanged. It can never touch a PT record.

## Verification

```bash
php scripts/run-en-translation.php --dry-run          # plan, zero writes
php wp-content/themes/conexao-br-irlanda/tests/test-translation-completeness.php
```

<!-- BEGIN GENERATED PLUGIN REGISTRY: plugin lifecycle metadata -->
| | |
|---|---|
| **Status** | active |
| **Class** | tooling |
| **Production** | no |
| **Build** | no |
| **Compose mount** | yes |
| **Dependencies** | `conexao-translation-rollout` |
| **Version** | 1.5.0 (authoritative source: `wp-content/plugins/conexao-en-translation/conexao-en-translation.php` header) |
| **Registry** | [`plugins.json`](../../plugins.json) |

> **Local-only tooling.** Not a production steady-state dependency.
<!-- END GENERATED PLUGIN REGISTRY: plugin lifecycle metadata -->

_Last verified: 2026-09-27 by the EN Leisure description rollout — Stage 7 `en-leisure-description` on the shared engine_

_Last verified: 2026-09-28 by the B1 English Guides content rollout — the `en-guide` stage now owns the 51-row Guide dataset + 13 EN `conexao_category` terms and applies them through the shared engine (48/48, `missing_en = 0`)_
