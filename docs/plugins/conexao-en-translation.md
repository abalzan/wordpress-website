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
| Authored copy | `includes/manifest-data.php` (Stage M: guide + page + the first 8 Blog rows), `includes/blog-translation-data.php` (Stage N: the remaining 34 Blog rows) and `includes/blog-page-data.php` (Stage O: the single Blog **posts page** row) — three separate versioned datasets, each keyed by PT slug |
| Adapter + config | `includes/stage-fields.php`, `includes/stage-config.php` (Stage O adds the `en-blog-page` stage: shared-slug filter, routing-cache refresh, shared-slug duplicate guard) |
| Manifest shape | `includes/translation-map.php` |
| Runner | `scripts/run-en-translation.php` |
| Admin screen | none (the rollout is operated through the shared engine's screen and the runner script) |
| Frontend effect | **none** — it creates EN content records only; no template, route or runtime hook |
| Safe to deactivate | yes, after the rollout has been applied and verified |

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
| **Version** | 1.2.0 (authoritative source: `wp-content/plugins/conexao-en-translation/conexao-en-translation.php` header) |
| **Registry** | [`plugins.json`](../../plugins.json) |

> **Local-only tooling.** Not a production steady-state dependency.
<!-- END GENERATED PLUGIN REGISTRY: plugin lifecycle metadata -->

_Last verified: 2026-09-26 by Stage N — Remaining EN Blog Translations_
