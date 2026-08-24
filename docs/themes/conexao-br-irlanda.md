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
| `featured-sponsors.php` | Homepage Hero (Featured Apoiadores carousel; renders nothing when no supporter is featured) |
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
- **Styles** (`main.css`): desktop keeps the 3.71:1 cinematic proportion as a **minimum** via `min-height: calc(100vw / 3.71)` (same content-driven model as mobile) so the Featured Apoiadores carousel can grow the section instead of being clipped by `overflow: hidden`; a green gradient remains as loading/failure fallback. `.hero-background-img` fills it via `object-fit: cover` / `object-position: center`. The copy is vertically centered by a flex `.site-container` (`align-items: center`). A subtle soft green gradient (`.hero-content::before`) tints only the left text-safe zone for legibility without obscuring the castle/family/flags.
- **Two-column composition**: when featured sponsors exist, `.hero-content` gets a `--with-sponsors` modifier and becomes a CSS grid on ≥769px — hero copy left in `.hero-copy` (~55–60%, slightly reduced `clamp()` type scale), Featured Apoiadores right in `.hero-sponsors` (~40–45%), vertically centered with a responsive gap; tablet (769–1199px) tightens the ratio/gap. Without sponsors the modifier is omitted and the hero stays single-column (`max-width: 640px`). On ≤768px the columns stack (copy first, carousel beneath).
- **Responsive**: mobile switches to a dedicated `<picture>` source pair and its own sizing model — `aspect-ratio: auto; min-height: calc(100vw / 1.8)` so the hero is never shorter than the mobile image's 1.8:1 proportion while still growing naturally with its content; copy is bottom-anchored over the photo (`align-items: flex-end`) with the crop biased via `object-position: 38% center`. Compact mobile typography is retained. The `width`/`height` attributes on each `<source>`/`<img>` are truthful intrinsic metadata only; CSS fully determines the rendered box.
- **Accessibility**: the `<img>` is decorative (`alt=""`, `aria-hidden`); the `<section>` has an `aria-label`.
- **Performance**: `loading="eager"` + `fetchpriority="high"` (LCP).

The hero background is **not** a Customizer setting — the `conexao_hero_image` Media control was removed (the green text-safe zone and castle/family/flags are part of the fixed asset). Only `conexao_hero_title` / `conexao_hero_subtitle` remain customisable.

## Homepage Apoiadores Carousel

The Featured Apoiadores carousel is a compact supporting showcase **inside the homepage Hero** (right-hand column beside the copy on desktop/tablet; stacked beneath the copy on mobile), rendered by the reusable template part `template-parts/featured-sponsors.php`. It is a responsive, dependency-free carousel over the **existing sponsor data model** — no new content type, no duplicated supporter data:

- **Data source**: `conexao_get_featured_sponsors()` (functions.php). Only sponsors with **Apoiador em destaque** (`_sponsor_featured = 1`) appear; ordering uses **Ordem de exibição** (`_sponsor_display_order`) ascending with a title tiebreaker so equal values never shuffle between loads. Sponsors without an order value sort last. Editing a supporter in wp-admin updates the section automatically (transient-cached under `conexao_home_sponsors`, invalidated on save).
- **Placement**: called from `front-page.php` inside `.hero-content`, wrapped in its own `.hero-sponsors` layout column beside `.hero-copy` (the copy column holds badge → heading → description → CTAs). The template part renders nothing when no supporter is featured, and the wrapper is skipped entirely so no empty column is reserved; when it does render, `.hero-content` gets a `--with-sponsors` modifier that enables the two-column grid (≥769px), tightens the hero copy spacing, and keeps the min-height sizing model (3.71:1 kept as a minimum) so the section can grow instead of clipping.
- **Markup** (`template-parts/featured-sponsors.php`): scroll-snap track (`.sponsors-carousel-viewport` > `.sponsors-carousel-list` > `.sponsors-slide`) with one large sponsor card (`.sponsor-tile`) visible at a time, framed over the hero photo in both themes; link behavior preserved (`target="_blank" rel="noopener noreferrer"` for external links) and saved attachment thumbnails as visuals (requested at the `large` size so logos stay crisp at the enlarged display scale).
- **Responsive**: EXACTLY ONE sponsor per view on every breakpoint — each slide fills the viewport edge-to-edge so no neighbour is ever partially visible. The tile scales fluidly via `clamp()` (logo area ~150–260px tall desktop, ~88–120px mobile) and the labelled prev/next row sits beneath the tile in both themes (the hero variant keeps its arrows visible ≤768px alongside native swipe). With a single featured supporter the adaptive static mode hides the controls.
- **Card treatment**: semantic tokens declared once on the component root (`.sponsors-carousel--hero`: `--sponsor-surface`, `--sponsor-border`, `--sponsor-border-hover`, `--sponsor-accent`, `--sponsor-text`, `--sponsor-shadow`, `--sponsor-shadow-hover`) drive every card/arrow rule — no color literals repeated per rule. Light mode: very light warm neutral surface (`#fcfbf7`), subtle translucent green border, soft deep-green shadow, dark neutral caption text, and a slim inset Conexão-green accent line along the top edge (`.sponsor-tile::before`) as the only brand accent. Dark mode: `dark-mode.css` redefines ONLY these tokens — deep translucent dark-green surface, subtle light border, high-contrast light text, soft shadow with a faint green glow. Sponsor artwork is never recolored; the card frames it.
- **Autoplay**: Hero variant only, 10-second interval implemented in `main.js` as a single `setTimeout` chain per carousel (never `setInterval`; timer id doubles as the armed flag). The first sponsor stays visible a full 10 seconds after load; each automatic advance re-arms the next full interval. Autoplay pauses while the user hovers (only where `(hover: hover)` matches), focuses anything inside, presses/touches the component, or the tab is hidden, and resumes from a fresh full interval when engagement ends. Manual navigation (buttons, arrow keys, Home/End) and user swipes/drags restart the countdown instead of triggering an immediate follow-up advance (programmatic autoplay scrolls are excluded via an `autoScrolling` flag). `prefers-reduced-motion: reduce` disables autoplay entirely (checked live, manual navigation still works); static mode (everything fits) never autoplays.
- **Adaptive**: when every tile fits without scrolling, JS adds `.is-static` — arrows hide and the row centers (simple layout instead of a pointless carousel); wrap-around prev/next only when scrollable.
- **Accessibility**: `aria-roledescription="carousel"`/`"slide"`, per-slide "Apoiador X de Y" labels, keyboard support on the track (arrows/Home/End), labelled buttons, visible focus states, polite live region announcing position (also updated by autoplay advances), reduced-motion aware autoplay.
- **Styles**: `main.css` ("Sponsors Carousel" base + "Featured Apoiadores carousel — Hero variant" blocks) + `dark-mode.css` hero token overrides.

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
- Sponsors carousel (homepage Apoiadores: scroll-snap track, arrows, keyboard nav, adaptive static mode, 10-second autoplay on the Hero variant with pause-on-interaction and reduced-motion support)

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
| `conexao_get_featured_sponsors()` | Featured Apoiadores for the homepage carousel (transient-cached) |
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