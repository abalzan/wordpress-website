# Routing

## Canonical URL Structure

Portuguese slugs are canonical. English URLs redirect to Portuguese equivalents via 301.

### Custom Post Type Archives

| Content Type | Portuguese URL | English URL (redirects) |
|-------------|----------------|------------------------|
| Guides | `/guias/` | `/guides/` |
| Events | `/eventos/` | `/events/` |
| Courses | `/cursos/` | `/courses/` |
| Jobs | `/empregos/` — **landing page** (job archive disabled) | `/jobs/` |
| Sponsors | `/apoiadores/` | `/sponsors/` |
| Lazer | `/lazer/` | `/leisure/` |

> **Note (Empregos):** the `job` CPT archive is disabled (see
> [`plugins/conexao-data-model`](plugins/README.md)). `/empregos/` is now a
> normal WordPress page — the "Jobs Landing" hub — rendered by the theme's
> `page-empregos.php` template. Individual job posts keep their
> `/empregos/{slug}/` URLs (e.g. `/empregos/oportunidades/`).
| Blog | `/blog/` | n/a |

### Single Items

- `/{post_type}/{slug}/` — all CPTs except leisure (which uses `single-leisure.php`)
- `/lazer/{slug}/` — uses `single-leisure.php`
- `/blog/{slug}/` — native WordPress posts

### Static Pages

Created by `conexao-content` plugin:

- `/inicio/`, `/blog/`, `/sobre-nos/`, `/contato/`, `/newsletter/`, `/revista/`, `/anuncie/`
- `/politica-de-privacidade/`, `/termos-de-uso/`, `/cookies/`
- `/irlanda/`, `/europa/`, county pages (e.g., `/laois/`, `/dublin/`)
- Category landing pages: `/moradia/`, `/saude/`, `/familia/`, etc.

## English (`/en/`) — Stage 2

English is an **additional language layer** delivered by Polylang (Stage 2 of
`CONEXAO_BR_ENGLISH_ARCHITECTURE_DECISION.md`). Portuguese URLs are unchanged;
English URLs wrap the same paths in `/en/`:

| Context | Portuguese | English |
|---|---|---|
| Home | `/` | `/en/` |
| Guides | `/guias/` | `/en/guias/` — **real English archive, applied 2026-09-28**: one linked EN `guide` per public PT guide (48/48), plus the 13 linked EN `conexao_category` terms those guides use. `guide` stays **B1** — no B2 fallback and no query widening were introduced; the archive was previously empty only because the EN records did not exist. EN singles live at `/en/guias/{en-slug}/` and are self-canonical with the `pt-BR`/`en`/`x-default` set; the PT slug under `/en/` is a replaced master and answers **302 → the PT guide**. `/en/guias/?categoria=documents` filters the EN archive; a slug from the other language resolves to the same concept through the Polylang term relationship. Authored data + the translated-taxonomy step live in the `en-guide` stage of `conexao-en-translation` and are applied by the shared `conexao-translation-rollout` engine (`scripts/run-en-translation.php --only=guide`); the retired `conexao-guide-translation` plugin stays dormant. |
| Events | `/eventos/` | `/en/eventos/` |
| Lazer | `/lazer/` | `/en/lazer/` |
| Courses | `/cursos/` | `/en/cursos/` |
| Sponsors | `/apoiadores/` | `/en/apoiadores/` |
| Empregos landing | `/empregos/` | `/en/jobs/` (real translation since Stage 3.2; **fully bilingual since Stage 8** — the same template, layout and filter values render every user-facing string in the requested language; `/en/empregos/` 302 → PT). The Stage 6 «Vagas»/"Openings" card preview was **removed by product decision (rendering only)** — the EN job records remain real translations at `/en/empregos/{en-slug}/` and the language-aware listing query is retained) |
| Job singles | `/empregos/{slug}/` | `/en/empregos/{en-slug}/` (real EN translation since Stage 6; before that the approved B2 fallback — PT body under the EN shell + notice; once translated, the PT slug under `/en/` redirects to the PT job) |
| Blog | `/blog/` | `/en/blog/` — **real English archive, complete since Stage O** (linked, published EN posts page created by stage `en-blog-page` + the 42 translated EN posts). Before Stage O it was the approved B2 fallback (PT posts under the EN URL + notice). EN posts live at `/en/{en-slug}/`; the archive paginates `/en/blog/page/N/`. |
| County pages + `/irlanda/` | `/dublin/`, `/irlanda/`, … | `/en/dublin/`, `/en/irlanda/` (B2: PT body under EN shell + notice) |
| Filters | `/eventos/?cidade=dublin` | `/en/eventos/?cidade=dublin` |

Rules:

- **CPT rewrite slugs are never renamed.** The Portuguese slug (`guias`,
  `eventos`, `lazer`, `blog`, `empregos`, `cursos`, `apoiadores`) is canonical
  in both languages; English only adds the `/en/` prefix.
- **Existing EN→PT 301 redirects are untouched** (`/guides/` → `/guias/`, …).
  `/en/…` paths can never match them (all rules are anchored to the root path).
- **Untranslated content is answered with a 302 to the Portuguese URL** while no
  English version exists — never a 301, never a fake English detail page.
  `conexao_seo_missing_translation_redirect()` (theme `inc/seo/redirects.php`) issues the
  302 and `conexao_polylang_language_redirect_is_temporary()` intercepts
  Polylang's own 301 for exactly these requests.
- **Canonical + hreflang are owned by `inc/seo/`**: self-canonical PT/EN
  URLs, `hreflang="pt-BR"`, `hreflang="en"`, `hreflang="x-default"` — only where
  a real translation relationship or a content-backed language archive exists.
- **Caches are per language** (`conexao_*_pt` / `conexao_*_en`).

Language assignment, URL mode and the translated post types/taxonomies are
configured by `scripts/run-polylang-setup.php` + `inc/i18n/guard.php` (see
`docs/development.md` § Multilingual (EN) development).

### EN archive route resolution (rewrite rules and the local flush)

`/en/` archive URLs (`/en/apoiadores/`, `/en/eventos/`, `/en/guias/`,
`/en/lazer/`, `/en/cursos/`, `/en/blog/`) are resolved by WordPress **rewrite
rules stored in the database**, not by theme code. The theme registers no
rewrite rule of its own (`grep add_rewrite_rule inc/` is empty); the rules are
generated **at flush time** from the `conexao-data-model` CPT registration plus
the theme's translated-post-types declaration (`inc/i18n/guard.php` via
`pll_get_post_types`) — Polylang emits the `en/`-prefixed variants of every
archive permastruct **for translated post types only**.

The failure mode this creates: a `rewrite_rules` set generated while that
declaration is absent carries no `en/`-prefixed CPT-archive rule at all, so
those routes fall through to page-name lookup and return **404 even though the
code is byte-identical and correct**. This was proven during the baseline
recovery: after a byte-perfect theme restore, `/en/guias/` and `/en/eventos/`
still 404'd until a local flush took the `en/`-prefixed CPT-archive rules from
**0 to 92** (`docs/evidence/2026-09-27-baseline-recovery-implementation/06-phase11-http-routes.txt`).
The same applies to any local database restored from a snapshot that predates
the current registration state (see `scripts/restore-updraft-db.sh`).

The documented remedy is a **local database operation, never a code fix**: run
`scripts/flush-blog-rewrite-rules.php` (a pure `flush_rewrite_rules()`; see
`scripts/README.md` for the run command) or save Settings → Permalinks in local
wp-admin, then verify with `./scripts/run-tests.sh --acceptance`. Do **not** use
`scripts/flush-navigation-rules.php` for this — it also deletes pages and
rebuilds menus. Production is unaffected by local flushes: the documented
release sequence uploads the theme **before** activating the platform plugins,
and `conexao-data-model::activate()` flushes with the declarations live, so
production regenerates the full rule set on every release (see
[`docs/releases.md`](releases.md)).

### English rollout state (Stage 3.2)

- **`/en/` serves the English homepage directly** (the linked translation of
  the PT front page). `pll_home_url('en')` = `/en/`; `/en/home/` 301 → `/en/`
  (mirroring `/inicio/` 301 → `/`). Declared through Polylang's
  `pll_additional_language_data` / `pll_language_home_url` filters plus a
  self-heal guard in `inc/i18n/guard.php` (Polylang builds its language list
  before the theme loads).
- **B2 fallback** (PT record under EN shell + notice, canonical → PT, not in
  the sitemap): events, lazer, sponsors, courses, jobs, plus the explicit
  **page allowlist** `conexao_b2_page_allowlist()` — `/irlanda/` + the county
  pages (dublin, cork, galway, limerick, kildare, meath, wicklow, waterford,
  laois) + the **Blog posts page** (`/blog/` → `/en/blog/`). Everything else
  stays B1 (302 → PT) or serves a real translation.
- **Taxonomy policy (Stage 3.2 correction)**: `conexao_category` and
  `conexao_tag` are Polylang-translated (shared concept identity via linked
  EN terms, e.g. `natureza` ↔ `nature`). **`conexao_county` and
  `conexao_town` are NOT translated** — one shared term per county/town, the
  same term on PT and EN records, so `?county=` / `?cidade=` filters match
  both languages' records identically. (Polylang Free ≥ 3.5 auto-creates
  suffixed term copies when a term is assigned across languages; keeping
  proper-noun taxonomies out of Polylang avoids that entirely — decision §1:
  "Counties/towns are shared — no per-language duplicate terms".)
- **Category filter slugs are language-specific by design**: a PT slug
  (`?categoria=festivais`) matches PT records (including B2 fallback records
  in EN context); an EN slug (`?categoria=festivals`) matches EN records.
  County/town filters use the one shared slug in both languages.
- **A record never appears twice**: once a published EN translation exists,
  the EN translation replaces its PT master in EN archives/search
  (`conexao_b2_translation_replaced_pt_ids()`), and untranslated PT records
  keep rendering as B2 fallbacks.
- **Legacy redirect precedence**: `conexao_seo_redirects()` runs at
  `template_redirect` priority 2 (before Polylang's canonical at 4) so the
  production EN→PT 301s keep winning even where an EN page now exists with
  the same slug as a legacy source path (`/jobs/`, `/about-us/`, `/contact/`).

### English rollout state (Stage 3.3)

Stage 3.2 left one visible UX gap: theme chrome built internal links with
`home_url( '/eventos/' )`-style calls, and Polylang only rewrites the **bare**
home URL (`PLL_Frontend_Filters_Links::home_url()` returns the URL untouched as
soon as `$path` is non-empty), so the English homepage still linked to
Portuguese destinations. Stage 3.3 closes that:

- **`conexao_lang_url( $path )`** (`inc/i18n/urls.php`) resolves a canonical
  Portuguese path to the destination the current language must reach:
  1. Polylang inactive / default language / empty language → `home_url( $path )`
     **byte-identical to the pre-Polylang output** (Portuguese cannot change);
  2. the path addresses a post type archive → `conexao_language_archive_url()`
     (`/eventos/` → `/en/eventos/`); the Blog (`post`) archive is resolved
     through the same helper, which checks for a translated Blog page
     (page_for_posts) and falls back to the target-language home when no
     translation exists — keeping the Blog navigation in the current language;
  3. the path addresses a page/object with a published translation → that
     translation's permalink (`/empregos/` → `/en/jobs/`,
     `/politica-de-privacidade/` → `/en/privacy-policy/`);
  4. otherwise → `home_url( $path )`: B1 (untranslated) and B2 pages are never
     auto-promoted to an invented EN URL.
- Used by the homepage quick-access cards, the "Precisa de ajuda?" shortcuts,
  the hero/section links and the footer legal links.
- **Guides category cards** resolve the *linked English term* when it exists and
  actually carries published English guides (`conexao_lang_term()` +
  `conexao_find_term_across_languages()`), e.g. `/en/guias/?categoria=documents`;
  otherwise they fall back to the plain English archive. A Portuguese term slug
  is never emitted under `/en/`.
- **Guides `?categoria=` filter (Stage 9)** is language-neutral at the query
  level: `conexao_guide_category_filter_term_id()` (theme `functions.php`)
  resolves the slug to a term in the **current** language — the current-language
  slug first, otherwise the counterpart through `pll_get_term()`. This is what
  lets a shared/legacy Portuguese slug (or a homepage Quick Access card) filter
  `/en/guias/` with the English content instead of bouncing the visitor to
  `/guias/`. An unknown slug yields an empty result set (never a
  cross-language redirect). The filter bar itself always links with the current
  language's slugs.
- **EN primary navigation**: an English menu ("Main Menu", term 2933 locally) is
  assigned to the `primary` location through Polylang's per-language
  `nav_menus` option, exactly like the Portuguese "Menu Principal" menu. It
  mirrors the PT stored structure (custom links to the canonical PT paths +
  page objects for Contact/About Us) with English titles; every destination is
  resolved to the current language at render time by the theme's Stage 3.3
  language-aware layer (`conexao_primary_nav_archive_url()`,
  `conexao_bind_section_object()`, `conexao_lang_url()`), so `/en/` links never
  fall back to the Portuguese URL space.
- **Jobs in the EN nav is page-backed**: the `job` CPT is registered with
  `has_archive = false` (its `rewrite` slug stays `empregos` for job singles),
  so `/empregos/` belongs to the static landing page and `/en/jobs/` is its
  real linked Polylang translation. `conexao_primary_nav_sections()` therefore
  models the Jobs section as a **page** (`path = empregos`), not a CPT archive,
  and `conexao_bind_section_object()` resolves it to the linked EN translation
  exactly like Contact/About Us. Modelling it as an archive returned an empty
  archive URL and fell back to the Portuguese `/empregos/`, which switched an EN
  visitor back to Portuguese — fixed in
  CONEXAO_BR_EN_NAV_LANGUAGE_CONTEXT_FIX_REPORT.md.
- **Blog in the EN nav is `/en/blog/`.** While the posts page
  (`page_for_posts`) has no EN translation Blog is allowlisted as B2:
  `/en/blog/` renders the Portuguese posts under the English shell (notice +
  canonical → PT) and the EN nav item resolves to `/en/blog/` — it never
  redirects back to the Portuguese `/blog/`. `conexao_lang_url('/blog/')` is
  byte-identical to `home_url('/blog/')` on Portuguese and resolves through
  `conexao_posts_page_url()` for other languages. Every EN primary-nav item
  now stays in the `/en/` context. See
  CONEXAO_BR_ENGLISH_BLOG_NAVIGATION_FIX_REPORT.md, `scripts/create-en-primary-menu.php`
  and CONEXAO_BR_EN_HEADER_NAVIGATION_FIX_REPORT.md.
- **hreflang output** is emitted by the theme (`inc/seo/hreflang.php`,
  `conexao_hreflang_links()`), which is the single SEO owner; Polylang's own
  `wp_head` rel-alternate set is additionally present in the local environment
  (pre-existing since Stage 2, to be consolidated in Stage 4 — see the Stage 3.3
  report §14/§23).

### English rollout state (Stage 4.5 — page translations)

Stage 4.5 translates **every eligible public Page** (the website's editorial
pages) into real linked Polylang translations. See
`CONEXAO_BR_ENGLISH_STAGE_4_5_REPORT.md` and
`docs/plugins/conexao-page-translation.md`.

- **All 37 eligible pages get real EN translations** (the 7 Stage 3.2 pages
  keep their approved slugs: `home`, `about-us`, `contact`, `jobs`,
  `privacy-policy`, `terms-of-use`, `cookie-policy`). Topic hubs use natural
  English slugs (`/en/housing/`, `/en/healthcare/`, `/en/documents/`, …),
  county pages use `county-<name>` (`/en/county-dublin/`, …), and
  `/irlanda/` becomes `/en/ireland/`.
- **B2 has RETIRED for the Blog (Stage 5 policy, completed in Stage O)**: the
  posts page now HAS a published, linked EN translation (created by the
  `en-blog-page` stage of `conexao-en-translation`, data
  `includes/blog-page-data.php`, version `blog-page-v1`), so `/en/blog/` is a
  genuine English archive: 200, self-canonical to `/en/blog/`, the full
  `en` / `pt-BR` / `x-default` hreflang set, EN-only posts, EN pagination, no
  `language-fallback-notice`. The `blog` string stays in
  `conexao_b2_page_allowlist()` because that function is the *policy
  definition* — it is what an install without the EN posts page needs — but on
  this site it is inert: `conexao_should_render_b2_fallback( blog )` returns
  `false` and `conexao_b2_posts_page_is_en_request()` returns `false`, so the
  `conexao_b2_posts_page_pre_query()` substitution is never reached. The
  **aggregate** permanent-gate allowlist count therefore moved **1821 → 1820**:
  the `blog` page record is now counted as *translated* rather than
  *allowlisted*, and it moved on its own. Nothing in the gate, the baseline or
  the allowlist function was edited to produce that number.
- **B2 retires for pages**: `conexao_b2_page_allowlist()` still exists (it
  stays the mechanism for future content) but every page it allowlisted
  (`irlanda` + the 9 counties) now has a real EN page, so no page renders the
  B2 fallback anymore. B2 remains in force for the CPT allowlist
  (event, leisure, sponsor, course_provider, job).
- **B1 retires for pages**: every translated page's `/en/<pt-slug>/` URL now
  resolves to the EN page (Polylang canonical) instead of the 302. The
  missing-translation redirect stays for everything else (e.g. the excluded
  `search` utility page).
- **The Blog posts page is translated with a shared slug** (`/en/blog/`,
  same `post_name`): Polylang Free needs the scoped `wp_unique_post_slug`
  permit that `conexao-page-translation` installs while it runs, and
  `conexao_lang_url_object()` normalises page lookups to the default-language
  source when two pages share a slug. The EN nav Blog item now resolves to
  `/en/blog/` (the EN posts archive — EN-language posts only).
- **Theme chrome for EN pages** ships through the completed `en_US` gettext
  catalog (`languages/en_US.po`/`.mo`, 384 entries — the Stage 1 follow-up):
  homepage hero/quick-access/sections, page eyebrows, header/footer, filters,
  empty states. Page eyebrows resolve through the translation group
  (`page.php`), and the header CTA uses `conexao_lang_url( '/anuncie/' )`
  so it resolves to `/en/advertise/` in English.
- **Excluded technical pages** (documented with evidence in the Stage 4.5
  report): the WordPress installer `about` sample, the shadowed legacy alias
  pages (`sobre`, `termos`, `privacidade` — permanently 301'd before they can
  render), the `search` utility page (sitemap-excluded, robots-disallowed,
  body is a single search block) and the shadowed `lazer` page
  (`/lazer/` is the leisure CPT archive, so the page's permalink never
  renders).
- **REST is unchanged**: the Stage 4.1 contract deliberately excluded
  `page`, and Stage 4.5 does not expand it (`inc/rest-language.php` is
  untouched). All Pages being translated does not change the Flutter-facing
  REST surface.

### English rollout state (Stage M — closing the measured completeness debt)

Stage 4.5 recorded the pages above as *excluded technical pages*, but the
permanent `translation_completeness` gate measures **eligible public PT
records**, and a published page is eligible whatever its editorial merit. The
two documents therefore disagreed, and the gate was the one that decided.

Stage M resolves the disagreement by **translating them faithfully as
published** rather than by relaxing either side:

- the `about` sample page and the `jobs-2` page (a duplicate of the real
  `/empregos/` landing) are translated as they stand. Deleting or rewriting
  them would be a PT content change, which an EN change may not make; inventing
  real content to replace them would be a second, worse defect. They remain
  published, and they remain a maintainer decision;
- the `sobre` / `termos` / `privacidade` legacy aliases get EN translations
  whose links point at the **EN** target page, so a visitor is never sent from
  an English URL back to a Portuguese one;
- the `search` utility page gets a minimal EN page so the search block renders
  in the EN shell.

The gate is the authority on *whether* coverage is complete, and it is unchanged.
If a maintainer later decides these pages should not exist at all, that is a PT
content change with its own plan — not a change to the gate.

EN coverage is now produced by the shared
[`conexao-translation-rollout`](../plugins/conexao-translation-rollout.md) engine
through the [`conexao-en-translation`](../plugins/conexao-en-translation.md)
stage:

```bash
php scripts/run-en-translation.php --dry-run            # plan, zero writes
php scripts/run-en-translation.php --apply --only=guide # one content type
php scripts/run-en-translation.php --remove --apply     # rollback
```

**Blog posts are still partly untranslated.** `post` is a B1 type with no B2
fallback, so every eligible PT post owes an EN record; the gate keeps reporting
the real number and the EN blog stays a partial archive until the remaining
authoring is done. This is recorded as open work, not as a policy exemption:
adding an EN allowlist for blog posts would buy a green run by hiding real debt,
which the standard forbids.

### English rollout state (Stage 5 — Blog translation)

Stage 5 gives the Blog a **real English translation**: the linked EN posts page
(`/en/blog/`) plus one linked EN translation per public Portuguese post. See
`CONEXAO_BR_ENGLISH_BLOG_TRANSLATION_REPORT.md` and
`docs/plugins/conexao-blog-translation.md`.

- **`/en/blog/` is a genuine English archive**: HTTP 200, `<html lang="en-US">`,
  English archive chrome (theme `.mo` strings), English posts, English pagination,
  English category labels, self-canonical `/en/blog/`, `hreflang` pair with
  `/blog/`, and **no B2 fallback notice**. `/blog/` is unchanged and remains
  self-canonical.
- **Posts page pair**: the EN posts page is the linked Polylang translation of the
  PT posts page and reuses the canonical `blog` path (`/blog/` ↔ `/en/blog/`).
  Because WordPress resolves the posts-page path with a language-blind page
  lookup, the theme resolves it explicitly in the requested language
  (`conexao_resolve_posts_page_request()` + `conexao_mark_posts_page_query()` in
    `inc/i18n/urls.php`); the default language path stays byte-identical to core.
- **Posts**: `/blog/{pt-slug}/` ↔ `/en/{en-slug}/` — natural English slugs derived
  from the English title, no `-en`/`-2` suffixes, PT slugs untouched. EN posts
  keep the PT featured image (shared media), date, author and taxonomy, and carry
  the linked EN `category` terms.
- **B2 for the Blog is retired automatically**: `conexao_should_render_b2_fallback()`
  reports false as soon as the posts page has a published linked EN translation,
  and the B2 post-set substitution
  (`conexao_b2_posts_page_pre_query()`) only runs while no EN posts page exists.
  The approved B2 architecture is untouched for every other destination.
  **Stage N state (2026-09-26):** every eligible public PT blog post has a
  linked EN translation (`post_type:post:missing_en = 0`, 42/42), and each EN post
  resolves in the EN context with no B2 notice. The **archive** at `/en/blog/`
  still served the PT posts with the B2 notice, because the retirement condition
  is a linked EN translation of the `blog` **page record**.
  **Stage O state (2026-09-26):** that page record now exists (stage
  `en-blog-page`), so `/en/blog/` renders the 42 English posts and the B2
  notice is gone for good. See
  [`plugins/conexao-en-translation.md`](plugins/conexao-en-translation.md).
- **Sitemap**: the theme sitemap now lists blog posts in both languages (PT and
  EN passes, mirrors a real translation pair, no duplicates, no fallback URLs).


### English rollout state (Stage 7 — Leisure card descriptions)

Stage 7 translates the description rendered by `.leisure-card-excerpt` on the
Leisure archive. See `CONEXAO_BR_ENGLISH_LEISURE_CARD_DESCRIPTION_REPORT.md` and
`docs/plugins/conexao-leisure-translation.md`.

- **The English layer is a description-level translation on the SAME records**:
  one authored English description per published Portuguese `leisure` record,
  stored in `_leisure_excerpt_en` post meta. No EN leisure records, no duplicated
  records, no `_leisure_uuid` / `_leisure_export_uuid` changes (Leisure *records*
  remain a B2 directory; only the card description is translated in this stage).
- **Rendering**: the theme selects the description source per request language
  (`conexao_leisure_card_excerpt()` in `inc/i18n/fallback.php`); the presentation
  pipeline in `template-parts/leisure-card.php` (18-word `wp_trim_words()` +
  `esc_html()`) is identical in both languages. PT requests keep the exact
  pre-Stage-7 output (`get_the_excerpt()`).
- **B2 preserved**: a record without an authored EN description still renders the
  Portuguese description under the English shell (the approved fallback) — the
  `/en/lazer/` archive, its `/page/N/` views and its `?county=` / `?categoria=`
  filter views are otherwise unchanged (same cards, order, links, images).
- **Language selection** rides the existing Polylang helpers
  (`conexao_current_language_slug()`), never URL string guessing, and the
  per-language rendering is intrinsically cache-safe (separate URLs, no archive
  transient).
- **Portability**: `conexao-leisure-migration`'s exporter/importer carry
  `_leisure_excerpt_en`, so the field travels with the leisure dataset.
- **Rollout**: executed by the shared
  [`conexao-translation-rollout`](conexao-translation-rollout.md) engine through
  stage `en-leisure-description`, owned by `conexao-en-translation`
  (dataset `includes/leisure-description-data.php`, adapter/config
  `includes/leisure-description-stage.php`). Driven by the shared runner
  `scripts/run-en-translation.php` (`--dry-run` / `--apply` / `--remove
  --only=leisure-description`) or by the engine's **Tools → Translation Rollouts**
  screen. **The retired `conexao-leisure-translation` plugin is no longer the
  executed path** — its hand-copied lifecycle was superseded; see
  [`plugins/conexao-leisure-translation.md`](plugins/conexao-leisure-translation.md).
  The numeric gate is `eligible public PT leisure = 289`, `with EN = 289`,
  **`missing EN = 0`**, `conflicts = 0`, `PT drift = 0`.
- **Ineligible by rule**: a published record with an **empty** `post_excerpt` has
  no Portuguese source to translate and is excluded by rule, not by allowlist. No
  gate, baseline or threshold is edited to accommodate it.
- **PT-drift guard**: a row whose live `post_excerpt` no longer matches the
  Portuguese source the English was authored against is a **hard conflict** — it
  is not written and it fails the numeric gate. The comparison normalises HTML
  entities, whitespace and typographic punctuation on both sides so an
  encoding artifact is not mistaken for a content change, while case, accents and
  word characters are not folded, so a real edit still refuses.


### English rollout state (Stage 4.1 — bilingual REST contract)

Polylang Free sets the REST *language context* from a `lang` parameter but
does **not** filter post collections by language and exposes no translation
relationships (confirmed in the Stage 3.3 report §26). Stage 4.1 closes that
gap inside the existing REST API — no new endpoints, no second routing
system. Owner: **`inc/rest-language.php`** (theme, loaded from
`inc/rest-language.php` after `inc/i18n/`; every hook guarded by
`conexao_polylang_active()`, so a single-language site is unaffected).

- **Request model** on the existing endpoints: `?lang=pt`, `?lang=en`, or no
  `lang` at all. Omitting `lang` preserves the exact pre-Stage-4.1 behaviour
  (unfiltered collections, any-language detail) — no existing consumer has to
  add `lang=pt`.
- **Invalid `lang`** → HTTP 400 `conexao_rest_invalid_lang` (accepted values
  discovered from Polylang, currently `pt`, `en`); never Polylang's silent
  fall-back to the default language.
- **Collection membership** mirrors the front-end policy: `lang=pt` →
  Portuguese records; `lang=en` → real EN records + B2-eligible PT fallbacks
  (event, leisure, sponsor, course_provider, job) with translated PT masters
  *replaced* (never duplicated); B1 types (guide, post) get real EN records
  only; hidden events never appear.
- **`conexao_language` field** on every record of the seven content types,
  the six contract taxonomies and `/wp/v2/search` results:
  `{ lang, is_fallback, translations: { <lang>: { id, url } } }` — enough for
  a client to distinguish real PT / real EN / B2 fallback / source-inherited
  EN without scraping HTML. The existing `link` field already carries the
  language-correct canonical (EN self / PT for B2).
- **Detail rules**: matching language → 200; PT B2 record without an EN
  translation under `lang=en` → 200 with `is_fallback=true`; any other
  language mismatch → 404 `conexao_rest_language_unavailable` with the linked
  translation ids in `data.translations`.
- **Event hard gate on details**: expired/rejected/source_not_found events
  answer the same 404 (`rest_post_invalid_id`) the front-end singles produce,
  for public (unauthenticated) requests — the collection gate already did
  this via `pre_get_posts`; REST details were the only leak.
- **Taxonomies**: translated taxonomies (`conexao_category`, `conexao_tag`,
  `category`, `post_tag`) filter to the requested language (Polylang's own
  REST term filtering); shared `conexao_county`/`conexao_town` return the
  same shared terms for every language (`lang: null` in the field).
- **No new cache**: the only reused cache is the language-independent
  `conexao_b2_translation_replaced_pt_ids()` set, invalidated on save/delete
  by the theme's existing hooks — PT and EN responses can never share a cache
  key.
- Verification: `wp-content/themes/conexao-br-irlanda/tests/test-stage41-rest-language.php`
  (in-process REST dispatch, 212 assertions) and
  `scripts/verify-rest-english.py` (wire-level matrix, writes
  `stage41-rest-matrix.json`). Local/staging only — nothing deployed.

### Filters (Query Parameters)

| Archive | Parameters | Taxonomy/Meta |
|---------|-----------|---------------|
| `/eventos/` | `?county=slug` | `conexao_county` |
| `/eventos/` | `?cidade=slug` | `conexao_town` |
| `/eventos/` | `?categoria=slug` | `conexao_category` |
| `/lazer/` | `?county=slug` | `conexao_county` |
| `/lazer/` | `?categoria=slug` | `conexao_category` |
| `/lazer/` | `?atributo=slug[,slug]` | `conexao_leisure_attribute` (multi-select OR) |
| `/cursos/`, `/en/cursos/` | `?categoria=slug` | `_provider_category` (meta) — same slug in both languages |
| `/guias/` | `?categoria=slug` | `conexao_category` |
| `/blog/` | `?categoria=slug` | `category` (native) |
| `/empregos/` | `?tipo=` (`agency`/`public_sector`/`permit_history`), `?area=`, `?localizacao=`, `?contrato=` | canonical `resource_type` / `_agency_job_types` / normalized `_agency_location` / `_agency_temporary`+`_agency_permanent` (meta) — unified opportunities directory; `?permit_history` requires the structured `has_permit_history` flag |

Filters are content-type-aware: the same `?categoria=` parameter resolves to different taxonomies/meta on different archives. On `/eventos/` the three location/category dimensions (`?county=`/`?cidade=`/`?categoria=`) are AND-combined — a town from another county can never override the county selection, invalid slugs yield zero results gracefully, and option lists derive from counties/towns actually used by published events (county → city scoping via `conexao_get_event_towns()`; all option URLs are built through the shared `conexao_event_filter_url()` helper so filter changes never drop other dimensions and always reset to page 1). The same `?county=` convention is shared with `/lazer/`. `/cursos/` is a **B2** archive, so the same `?categoria=slug` (the `sanitize_title()` of the stored Portuguese `_provider_category` value, identical in both languages) applies on `/en/cursos/`; the option list is discovered by `conexao_get_provider_categories()`, whose secondary query goes through the shared `conexao_b2_widen_query_args()` boundary so an EN request sees the same B2 provider records the archive itself lists, and the option text is language-aware via `conexao_provider_category_label()` while the stored meta is never rewritten. Blog category links point at `/blog/?categoria=slug` (the Blog archive itself), not at the native `/category/{slug}/` archive — which remains intact for direct access, feeds and wp-admin. The `/empregos/` parameters are page-level (static landing template) and filter the unified "Oportunidades de emprego" directory rendered by `template-parts/employment-opportunities.php`: `?tipo=` selects the resource type (agencies / public-sector portals / permit-history employers, the latter via the structured `has_permit_history` flag) while `?area=`/`?localizacao=`/`?contrato=` keep their exact pre-existing agency-directory semantics (AND logic, backward-compatible URLs).

## Redirect Architecture

Two-layer redirect system:

### 1. .htaccess (Apache mod_rewrite)

English → Portuguese CPT archives:
```
/guides/ → /guias/
/events/ → /eventos/
/courses/ → /cursos/
/jobs/ → /empregos/
/sponsors/ → /apoiadores/
```

English → Portuguese static pages:
```
/ireland/ → /irlanda/
/about-us/ → /sobre-nos/
/contact/ → /contato/
```

Legacy Wix redirects:
```
/turismo-e-lazer/ → /eventos/
/guias-praticos/ → /guias/
/post/{slug}/ → /blog/{slug}/
/categories/{slug}/ → /{slug}/
/counties/{slug}/ → /{slug}/
```

### 2. PHP (inc/seo/redirects.php — `conexao_seo_redirects()`)

Runs at `template_redirect` priority 2 (Stage 3.2: before Polylang's language canonical at priority 4, so legacy 301s keep precedence over slug collisions with EN pages). Handles:

- English guide paths: `/guides/{slug}` → `/guias/{slug}/`
- Legacy guide paths: `/guias-praticos/{slug}` → `/guias/{slug}/`
- English paths: `/events/{slug}`, `/jobs/{slug}`, `/courses/{slug}`, `/sponsors/{slug}`
- Legacy `/post/{slug}` → `/blog/{slug}/`
- Legacy `/blog/categories/{slug}` → `/category/{slug}/`
- Direct 301 map with ~60+ entries

**The priority-2 registration is a permanent invariant, not an accident.** The
legacy table must run before Polylang's language canonical (priority 4),
otherwise a newly created EN page whose slug collides with a legacy path would
win and the production 301 to the canonical PT URL would be lost. The Stage L
gate `test-redirect-precedence.php` enforces all of this on every default test
run:

- the legacy path is detected and keeps its **301**;
- the destination is the **PT** path, never `/en/`;
- a conflicting EN page with the same slug does **not** take precedence
  (the suite creates that page, asserts the collision is real, and deletes it);
- `conexao_seo_redirects` is still registered on `template_redirect` at a
  priority lower than 4.

The HTTP acceptance matrix rows `legacy-301-*` in
`tests/acceptance/matrices/routing.json` cover the same property over real
requests. The in-process gate is the permanent one; the matrix rows are the
request-level regression.

### 3. Leisure External Redirect (PHP — `conexao_leisure_redirect()`)

Runs at `template_redirect` priority 6. Redirects individual leisure posts to their configured external URL (Official Website or Discover Ireland URL) via 302 if one is set. Classification is centralized in `conexao_leisure_external_url()` (inc/seo/redirects.php) — the sitemap, the archive cards and the related-destinations selector read the same function. Phase 3B: a record with the `_leisure_internal_page` flag keeps its internal page and its official/Discover Ireland URLs become display-only reference links; every record without the flag classifies and redirects exactly as before.

## Template Routing

```
front-page.php          → /
archive.php             → /guias/, /eventos/, /cursos/, /apoiadores/, /lazer/
page-empregos.php       → /empregos/ (Jobs Landing page; job archive disabled)
single-leisure.php      → /lazer/{slug}/
single.php              → /{cpt}/{slug}/ (all other CPTs)
page.php                → /{slug}/ (static pages)
home.php                → /blog/
search.php              → /search/
404.php                 → 404 errors
page-landing.php        → landing page template (specific pages)
```

## Navigation Architecture

The theme dynamically modifies the primary navigation at render time via `wp_nav_menu_objects` filters:

1. **Priority 20** (`conexao_modify_primary_nav_items`): Removes "Notícias" and "Sobre Nós" items, changes "Home" to "Início", inserts a fallback "Blog" item immediately before "Contato".
2. **Priority 25** (`conexao_normalize_primary_nav_sections`): Binds each nav item to its canonical WordPress object for reliable active-state detection.

Canonical nav order: Início, Apoiadores, Guias, Eventos, Cursos, Lazer e turismo, Empregos, Blog, Contato.

The stored menu order lives in the WordPress `nav_menu_item` post `menu_order` values (managed via wp-admin drag-and-drop or `scripts/reorder-primary-menu-blog-apoiadores.php`); the render-time filters never reorder existing items, they only insert missing ones and normalize binding.

Note: "Irlanda" is intentionally NOT a navigation item. The /irlanda/ page remains published and directly accessible; it is simply not linked from the main navigation (desktop and mobile share the same `primary` menu). See `scripts/remove-irlanda-menu-item.php` for removing any legacy "Irlanda" item from an existing menu.

Note: "Sobre Nós" is intentionally NOT a navigation item either. The /sobre-nos/ page remains published and directly accessible at /sobre-nos/; it is simply not linked from the main navigation (desktop and mobile share the same `primary` menu). See `scripts/remove-sobre-nos-menu-item.php` for removing any legacy "Sobre Nós" item from an existing menu.

Active-state resolution uses URL pattern matching in `conexao_fix_nav_active_states()`.

### Menu selection with Polylang (per-language assignment)

The plain WordPress location assignment (`nav_menu_locations.primary`) is NOT
what resolves the header menu once Polylang is active. Polylang's
`theme_mod_nav_menu_locations` filter overwrites every registered location
with the per-language assignment in Polylang's `nav_menus` option
(`nav_menus[stylesheet][location][language-slug]`), or with `0` when that
per-language entry is missing — for every language, including Portuguese.
A missing `['pt']` entry therefore silently degraded the header to the
automatic page list (see CONEXAO_BR_HEADER_NAVIGATION_REGRESSION_REPORT.md).

- Run `scripts/assign-polylang-nav-menus.php` (idempotent) to write
  `nav_menus['primary']['pt'] = "Menu Principal"`, and
  `scripts/create-en-primary-menu.php` (idempotent, data-only) to create the
  English "Main Menu" mirror and write `nav_menus['primary']['en']`. Missing
  per-language entries are the observed cause of the empty EN header.
- The render-time nav layer is language-aware: `conexao_primary_nav_sections()`
  resolves archive/page destinations through `conexao_lang_url()` /
  `conexao_language_archive_url()` semantics, `conexao_bind_section_object()`
  binds the linked translation of a page when one exists, and
  `conexao_fix_nav_active_states()` strips the current language prefix before
  matching its canonical PT path rules. On Portuguese (the default language)
  every helper is byte-identical to the pre-Polylang behaviour.
- Both header `wp_nav_menu()` calls (desktop + mobile drawer) use the safe
  empty fallback `conexao_safe_nav_menu_fallback()` (functions.php): when the
  location has no valid menu for the current language, the header renders no
  navigation items. It must never be changed back to `wp_page_menu`.
_Last verified: 2026-09-26 by Stage I — Scripts Standardisation_

_Last verified: 2026-09-26 by Stage N — Remaining EN Blog Translations_

_Last verified: 2026-09-27 by the EN Leisure description rollout — Stage 7 executed by the shared `en-leisure-description` stage; B2 policy, canonical, hreflang and sitemap unchanged_

### The `/en/<archive>` rewrite rules are a self-healed invariant

The `/en/<archive>/` rules for every translated post type with a public archive
(`/en/guias/`, `/en/eventos/`, `/en/apoiadores/`, `/en/lazer/`, `/en/cursos/`)
are **generated by Polylang**, not by this repository: it filters the
per-post-type `{$post_type}_rewrite_rules` sets and attaches those filters on
`wp_loaded` at priority 9. A `flush_rewrite_rules()` that runs **before** that
point therefore persists a rule set with **no** `(en)/` archive rules, in which
the Portuguese archives still work and every English archive 404s. The
`597b04e` → `cd800be` recovery cycle hit exactly that window (plugin activation
hooks flush early).

`conexao_polylang_rewrite_rules_ensure()` (`inc/i18n/guard.php`, `wp_loaded`
priority 20 — after Polylang's prepare step) detects a persisted rule set that is
missing those rules and re-flushes once. It repairs persisted state only: no
route allowlist, no redirect, no second routing system, and Polylang remains the
sole generator of the rules. The post types it protects are derived at read time
from `conexao_polylang_translated_post_types()`, so there is no second list.
See `docs/reports/2026-09-28-en-archive-404-fix.md`.

_Last verified: 2026-09-28 by the EN archive 404 fix — the three routes were
restored, `/en/cursos/` recovered from a stray 301, PT unchanged_

_Last verified: 2026-09-28 by the B1 English Guides content rollout — 48/48 linked EN guides + 13 EN `conexao_category` terms applied through the shared `en-guide` stage; `guide` remains B1, no fallback, no B2 widening, canonical/hreflang/redirect policy unchanged_
