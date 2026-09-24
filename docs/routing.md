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
| Guides | `/guias/` | `/en/guias/` |
| Events | `/eventos/` | `/en/eventos/` |
| Lazer | `/lazer/` | `/en/lazer/` |
| Courses | `/cursos/` | `/en/cursos/` |
| Sponsors | `/apoiadores/` | `/en/apoiadores/` |
| Empregos landing | `/empregos/` | `/en/jobs/` (real translation since Stage 3.2; **lists the real EN Job CPT records since Stage 6** — one «Vagas»/"Openings" card per job, language-aware; `/en/empregos/` 302 → PT) |
| Job singles | `/empregos/{slug}/` | `/en/empregos/{en-slug}/` (real EN translation since Stage 6; before that the approved B2 fallback — PT body under the EN shell + notice; once translated, the PT slug under `/en/` redirects to the PT job) |
| Blog | `/blog/` | `/en/blog/` — **real English archive since Stage 5** (linked EN posts page + translated EN posts); before that, the approved B2 fallback (PT posts under the EN URL + notice). EN posts live at `/en/{en-slug}/`. |
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
  `conexao_seo_missing_translation_redirect()` (theme `inc/seo.php`) issues the
  302 and `conexao_polylang_language_redirect_is_temporary()` intercepts
  Polylang's own 301 for exactly these requests.
- **Canonical + hreflang are owned by `inc/seo.php`**: self-canonical PT/EN
  URLs, `hreflang="pt-BR"`, `hreflang="en"`, `hreflang="x-default"` — only where
  a real translation relationship or a content-backed language archive exists.
- **Caches are per language** (`conexao_*_pt` / `conexao_*_en`).

Language assignment, URL mode and the translated post types/taxonomies are
configured by `scripts/stage2-polylang-setup.php` + `inc/polylang.php` (see
`docs/development.md` § Multilingual (EN) development).

### English rollout state (Stage 3.2)

- **`/en/` serves the English homepage directly** (the linked translation of
  the PT front page). `pll_home_url('en')` = `/en/`; `/en/home/` 301 → `/en/`
  (mirroring `/inicio/` 301 → `/`). Declared through Polylang's
  `pll_additional_language_data` / `pll_language_home_url` filters plus a
  self-heal guard in `inc/polylang.php` (Polylang builds its language list
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

- **`conexao_lang_url( $path )`** (`inc/polylang.php`) resolves a canonical
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
- **hreflang output** is emitted by the theme (`inc/seo.php`,
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
- **B2 retires for the Blog too (Stage 5)**: once the posts page has a
  published linked EN translation *and* the EN posts exist, `/en/blog/` is a
  genuine English archive (self-canonical, `hreflang` pair, EN pagination,
  EN category labels, no fallback notice). The allowlist entry `blog` and the
  `conexao_b2_posts_page_pre_query()` substitution remain in force only for
  installs where no EN posts page exists yet. See
  CONEXAO_BR_ENGLISH_BLOG_TRANSLATION_REPORT.md.
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
  `inc/polylang.php`); the default language path stays byte-identical to core.
- **Posts**: `/blog/{pt-slug}/` ↔ `/en/{en-slug}/` — natural English slugs derived
  from the English title, no `-en`/`-2` suffixes, PT slugs untouched. EN posts
  keep the PT featured image (shared media), date, author and taxonomy, and carry
  the linked EN `category` terms.
- **B2 for the Blog is retired automatically**: `conexao_should_render_b2_fallback()`
  reports false as soon as the posts page has a published linked EN translation,
  and the B2 post-set substitution
  (`conexao_b2_posts_page_pre_query()`) only runs while no EN posts page exists.
  The approved B2 architecture is untouched for every other destination.
- **Sitemap**: the theme sitemap now lists blog posts in both languages (PT and
  EN passes, mirrors a real translation pair, no duplicates, no fallback URLs).


### English rollout state (Stage 4.1 — bilingual REST contract)

Polylang Free sets the REST *language context* from a `lang` parameter but
does **not** filter post collections by language and exposes no translation
relationships (confirmed in the Stage 3.3 report §26). Stage 4.1 closes that
gap inside the existing REST API — no new endpoints, no second routing
system. Owner: **`inc/rest-language.php`** (theme, loaded from
`functions.php` after `inc/polylang.php`; every hook guarded by
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
  `scripts/stage41-rest-verify.py` (wire-level matrix, writes
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
| `/cursos/` | `?categoria=slug` | `_provider_category` (meta) |
| `/guias/` | `?categoria=slug` | `conexao_category` |
| `/blog/` | `?categoria=slug` | `category` (native) |
| `/empregos/` | `?tipo=` (`agency`/`public_sector`/`permit_history`), `?area=`, `?localizacao=`, `?contrato=` | canonical `resource_type` / `_agency_job_types` / normalized `_agency_location` / `_agency_temporary`+`_agency_permanent` (meta) — unified opportunities directory; `?permit_history` requires the structured `has_permit_history` flag |

Filters are content-type-aware: the same `?categoria=` parameter resolves to different taxonomies/meta on different archives. On `/eventos/` the three location/category dimensions (`?county=`/`?cidade=`/`?categoria=`) are AND-combined — a town from another county can never override the county selection, invalid slugs yield zero results gracefully, and option lists derive from counties/towns actually used by published events (county → city scoping via `conexao_get_event_towns()`; all option URLs are built through the shared `conexao_event_filter_url()` helper so filter changes never drop other dimensions and always reset to page 1). The same `?county=` convention is shared with `/lazer/`. Blog category links point at `/blog/?categoria=slug` (the Blog archive itself), not at the native `/category/{slug}/` archive — which remains intact for direct access, feeds and wp-admin. The `/empregos/` parameters are page-level (static landing template) and filter the unified "Oportunidades de emprego" directory rendered by `template-parts/employment-opportunities.php`: `?tipo=` selects the resource type (agencies / public-sector portals / permit-history employers, the latter via the structured `has_permit_history` flag) while `?area=`/`?localizacao=`/`?contrato=` keep their exact pre-existing agency-directory semantics (AND logic, backward-compatible URLs).

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

### 2. PHP (inc/seo.php — `conexao_seo_redirects()`)

Runs at `template_redirect` priority 2 (Stage 3.2: before Polylang's language canonical at priority 4, so legacy 301s keep precedence over slug collisions with EN pages). Handles:

- English guide paths: `/guides/{slug}` → `/guias/{slug}/`
- Legacy guide paths: `/guias-praticos/{slug}` → `/guias/{slug}/`
- English paths: `/events/{slug}`, `/jobs/{slug}`, `/courses/{slug}`, `/sponsors/{slug}`
- Legacy `/post/{slug}` → `/blog/{slug}/`
- Legacy `/blog/categories/{slug}` → `/category/{slug}/`
- Direct 301 map with ~60+ entries

### 3. Leisure External Redirect (PHP — `conexao_leisure_redirect()`)

Runs at `template_redirect` priority 6. Redirects individual leisure posts to their configured external URL (Official Website or Discover Ireland URL) via 302 if one is set. Classification is centralized in `conexao_leisure_external_url()` (inc/seo.php) — the sitemap, the archive cards and the related-destinations selector read the same function. Phase 3B: a record with the `_leisure_internal_page` flag keeps its internal page and its official/Discover Ireland URLs become display-only reference links; every record without the flag classifies and redirects exactly as before.

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