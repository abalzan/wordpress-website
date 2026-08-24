# Conexão BR Irlanda Theme

- **Path**: `wp-content/themes/conexao-br-irlanda/`
- **Version**: 1.0.0
- **Status**: Active (only theme in repository)
- **Parent theme**: None (standalone custom theme)

## Purpose

Modern community portal theme for Conexão BR Irlanda. Features a green/orange palette, responsive layout, full Gutenberg support, built-in SEO, and shared template components.

## Template Files

| File | Route | Description |
|------|-------|-------------|
| `front-page.php` | `/` | Homepage with full-bleed hero image, quick access cards, sections |
| `archive.php` | CPT archives | Shared archive for all 6 CPTs |
| `single-leisure.php` | `/lazer/{slug}/` | Dedicated leisure/tourism detail template |
| `single.php` | `/{cpt}/{slug}/` | Single post for all other CPTs |
| `page.php` | `/{slug}/` | Static pages |
| `page-landing.php` | specific pages | Landing page template |
| `home.php` | `/blog/` | Blog archive |
| `search.php` | `/search/` | Search results |
| `404.php` | 404 | 404 page |
| `header.php` | All | Site header, nav, mobile menu |
| `footer.php` | All | Site footer |
| `sidebar.php` | All | Sidebar widget area |
| `comments.php` | Posts | Comments template |
| `searchform.php` | Search | Search form template |

## Template Parts (`template-parts/`)

| File | Used On |
|------|---------|
| `archive-header.php` | All CPT archives |
| `event-card.php` | Events archive grid |
| `event-filters.php` | Events archive filter bar |
| `event-preview.php` | Homepage events section |
| `hero-events.php` | Retained; no longer rendered (hero uses committed full-bleed asset) |
| `leisure-card.php` | Lazer archive grid |
| `leisure-filters.php` | Lazer archive filter bar (desktop + mobile) |
| `provider-card.php` | Course providers archive/grid |
| `quick-access-card.php` | Homepage quick access grid |
| `newsletter-section.php` | Newsletter CTA (homepage + archives) |
| `quote-section.php` | Quote/testimonial section |
| `pagination.php` | Archive pagination |
| `content-none.php` | Empty state / no results |

## Homepage Hero

The front-page hero is a single full-bleed cinematic image with overlaid text (the old green panel + separate right-hand image card / `.hero-layout` grid has been removed):

- **Assets**: committed project files output in `front-page.php` via `get_template_directory_uri()` inside a responsive `<picture>` in `.hero-background`. Desktop fallback `<img>`: `conexaobr_Hero_image.png` (2057×764). Mobile sources (≤768px): `conexaobr_Hero_image_mobile.webp` (1080×600) with PNG fallback (1683×935) — both 1.8:1. Resolves identically in local and production (no Media Library / localhost URL).
- **Styles** (`main.css`): desktop uses `.hero-section { aspect-ratio: 3.71 / 1 }` with a green gradient loading/failure fallback; `.hero-background-img` fills it via `object-fit: cover` / `object-position: center`. The copy is vertically centered by a flex `.site-container` (`align-items: center`), `max-width: 640px`, `padding: 60px 0`. A subtle soft green gradient (`.hero-content::before`) tints only the left text-safe zone for legibility without obscuring the castle/family/flags.
- **Responsive**: mobile switches to a dedicated `<picture>` source pair and its own sizing model — `aspect-ratio: auto; min-height: calc(100vw / 1.8)` so the hero is never shorter than the mobile image's 1.8:1 proportion while still growing naturally with its content; copy is bottom-anchored over the photo (`align-items: flex-end`) with the crop biased via `object-position: 38% center`. Compact mobile typography is retained. The `width`/`height` attributes on each `<source>`/`<img>` are truthful intrinsic metadata only; CSS fully determines the rendered box.
- **Accessibility**: the `<img>` is decorative (`alt=""`, `aria-hidden`); the `<section>` has an `aria-label`.
- **Performance**: `loading="eager"` + `fetchpriority="high"` (LCP).

The hero background is **not** a Customizer setting — the `conexao_hero_image` Media control was removed (the green text-safe zone and castle/family/flags are part of the fixed asset). Only `conexao_hero_title` / `conexao_hero_subtitle` remain customisable.

## CSS Architecture

6 files, loaded in order via `functions.php`:

1. **Google Fonts** — Inter + Poppins (external)
2. **design-system.css** — Design tokens, typography, buttons, cards, filters, page headers, empty states, focus, responsive, reduced motion
3. **header-nav.css** — Header layout, primary nav, mobile menu, search
4. **main.css** — Hero, sections, grids, cards, footer, responsive breakpoints
5. **leisure.css** — Leisure archive + single layout
6. **dark-mode.css** — `[data-theme="dark"]` overrides

Versioning: `filemtime()` for cache busting (see `conexao_asset_version()` in functions.php).

## JavaScript

Single file: `assets/js/main.js` (deferred, no dependencies).

Features:
- Theme toggle (dark/light, localStorage)
- Mobile menu (full-screen overlay, focus trap, submenu toggles)
- Mobile search overlay
- Copy-to-clipboard buttons
- Leisure filters (desktop dropdowns + mobile bottom sheet)

## Custom WordPress Integration

### `functions.php`

Key functionality includes:

| Function | Purpose |
|----------|---------|
| `conexao_theme_setup()` | Theme supports, nav menus, content width |
| `conexao_enqueue_scripts()` | CSS/JS asset loading |
| `conexao_content_archive_query()` | `pre_get_posts` — archive ordering and filtering |
| `conexao_course_providers_shortcode()` | `[conexao_course_providers]` shortcode |
| `conexao_get_terms_for_post_type()` | Filter-context term resolution |
| `conexao_get_provider_categories()` | Dynamic provider category discovery |
| `conexao_homepage_query()` | Transient-cached homepage queries |
| `conexao_homepage_cache_invalidate()` | Transient invalidation on save |
| `conexao_relabel_posts_to_blog()` | Label override: "Posts" → "Blog" |
| `conexao_modify_primary_nav_items()` | Nav item insertion/removal (priority 20) |
| `conexao_normalize_primary_nav_sections()` | Nav binding + active state (priority 25) |
| `conexao_fix_nav_active_states()` | Active state conflict resolution |
| `conexao_popular_posts()` | "Mais Lidos" query (view-count ready) |
| `conexao_customize_register()` | Customizer sections (colors, social, hero [title/subtitle only], footer) |

### Image Sizes

| Size | Width x Height | Crop |
|------|---------------|------|
| `conexao-card` | 400 × 300 | Hard |
| `conexao-hero` | 1200 × 600 | Hard |
| `conexao-thumb` | 200 × 150 | Hard |
| `conexao-event-banner` | 640 × 360 | Hard |
| `conexao-provider-logo` | 320 × 180 | Soft |

### SEO (`inc/seo.php`)

Handles titles, meta descriptions, canonical URLs, Open Graph, Twitter Cards, schema.org (WebSite, Organization, Article, Event, JobPosting, sponsor), breadcrumbs, XML sitemap (`/sitemap.xml`), robots.txt, and legacy/English-to-Portuguese 301 redirects.

### Navigation Logic

The primary navigation is dynamically modified at render time:

1. Remove "Notícias" items
2. Change "Home" label to "Início"
3. Insert "Blog" before "Guias"
4. Insert "Cursos" before "Empregos"
5. Ensure Lazer section exists
6. Bind each item to its canonical WordPress object
7. Fix active-state conflicts via URL pattern matching

Canonical order: Início, Blog, Guias, Eventos, Cursos, Lazer, Empregos, Apoiadores, Irlanda, Sobre Nós, Contato.

## Performance Optimizations

- Emoji script/style removal
- wp-embed script deregistration
- Block library CSS selectively dequeued (only loaded on pages using blocks or shortcodes)
- REST API link removal from `<head>`
- Homepage queries cached as transients (5 min, invalidated on save)
- CSS asset versioning via `filemtime()`
- Lazy loading on content images
- Fetchpriority="high" on hero image (LCP)

## Dependencies

- `conexao-data-model` (CPTs and taxonomies)
- `conexao-content` (shortcodes used in page content)
- `conexao-admin-ux` (status meta, editor enhancements)
- `conexao-event-importer` (event status filtering via `pre_get_posts`)

## Files to Inspect First

- `functions.php` — all theme setup, queries, nav, shortcodes
- `inc/seo.php` — complete SEO module
- `front-page.php` — homepage template
- `archive.php` — shared archive template
- `single-leisure.php` — leisure detail template
- `assets/js/main.js` — frontend JavaScript