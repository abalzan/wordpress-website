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
| Empregos landing | `/empregos/` | `/en/jobs/` (real translation since Stage 3.2; `/en/empregos/` 302 → PT) |
| Blog | `/blog/` | `/en/blog/` (302 → PT until translated; EN posts live at `/en/{post-slug}/`) |
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
  laois). Everything else stays B1 (302 → PT) or serves a real translation.
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