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
| `single-sponsor.php` | `/apoiadores/{slug}/` | Dedicated Apoiador detail template: back link, portrait image (left column) beside name/description/“Entre em contato” buttons (right column) on desktop; single-column stack below 769px |
| `single.php` | `/{cpt}/{slug}/` | Single post for all other CPTs |
| `page.php` | `/{slug}/` | Static pages |
| `page-empregos.php` | `/empregos/` | Jobs Landing page — portrait image + editable body + optional "Mais informações" CTA |
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
| `job-resources.php` | Empregos landing ("Onde procurar emprego" — external job-site cards reusing the Event card classes; data from `conexao_job_resources()` in `inc/job-resources.php`, filterable via `conexao_job_resources`). The cards are managed in wp-admin under **Empregos → Onde procurar emprego** (`conexao-job-resources` submenu, stored in the `conexao_job_resources` option; the built-in Jobs.ie / Indeed / IrishJobs defaults apply until first save) |
| `hero-events.php` | Retained; no longer rendered (hero uses committed full-bleed asset) |
| `leisure-card.php` | Lazer archive grid |
| `leisure-filters.php` | Lazer archive filter bar (desktop + mobile). Desktop: "Encontre o que fazer" heading, substantial (220–280px) Localização/Tipo dropdowns sharing the grid's horizontal rhythm (trigger shows the selected value while active), a client-side location search inside the Localização popover (filters the server-rendered options; no extra request), active-filter chip row (each chip a real hyperlink that removes that filter), "Limpar filtros" (only when active) and a result-count line read from the main query's `found_posts` (no extra query). Mobile: the same heading, chips, "Limpar filtros" and result count stay visible; only the dropdown toolbar is replaced by a full-width "Filtrar" button (with active-count badge) that opens a modal bottom sheet of always-visible radio sections ("Localização", "Tipo" — only terms actually in use; the Localização section gets the same client-side search as the desktop popover when the list is long, and no search UI when it is short) with a sticky [Limpar] / [Mostrar resultados] footer. Radios apply immediately on selection — any selection auto-closes the sheet (focus returns to the "Filtrar" trigger, so the user lands directly on the filtered results); the sheet can be reopened to stack another filter (selections are preserved): the results wrapper (`data-leisure-results`, added in `archive.php`), chips, result count, "Filtrar" trigger badge and URL (`?county=`/`?categoria=`, unchanged scheme) are updated in place from a fetched server render of the target URL, with one abortable request per selection ("Atualizando..." while in flight, no flashing) and the infinite scroll re-bound to the swapped pagination so it keeps loading filtered pages; state is server-rendered from the URL and never reset on reopen; the sheet traps focus and returns it to the "Filtrar" button on close. On mobile the chip row and "Limpar filtros" also run through the same instant pipeline (chip/clear taps apply in place, and focus moves to the next chip — or the "Filtrar" trigger when cleared — instead of `<body>`), while desktop keeps those controls as plain hyperlinks with their original native navigation. The swapped `[data-leisure-results]` wrapper (in `archive.php`) contains the grid + pagination or — for a zero-result filter — the no-results empty state, so both render paths stay stable for the fetch+swap pipeline. |
| `provider-card.php` | Course providers archive/grid |
| `quick-access-card.php` | Homepage quick access grid |
| `newsletter-section.php` | Newsletter CTA (homepage + archives) |
| `quote-section.php` | Quote/testimonial section |
| `pagination.php` | Archive pagination |
| `content-none.php` | Empty state / no results |

## Homepage Hero

The front-page hero is a single full-bleed cinematic image with overlaid text (the old green panel + separate right-hand image card / `.hero-layout` grid has been removed):

- **Assets**: committed project files output in `front-page.php` via `get_template_directory_uri()` inside a responsive `<picture>` in `.hero-background`. Desktop fallback `<img>`: `conexaobr_Hero_image.png` (2057×764). Desktop WebP source (≥769px): two-candidate `srcset` — `conexaobr_Hero_image-1600.webp` (1600×594, q88, ≈140 KB) + `conexaobr_Hero_image.webp` master (2057×764, q88, ≈225 KB), `sizes="100vw"` (DPR-1 desktops get the 1600w derivative, ~85 KiB saved; see docs/frontend.md). Mobile sources (≤768px): `conexaobr_Hero_image_mobile.webp` (900×500, q85, ≈100 KB — sized for DPR-2 phones) with PNG fallback (1683×935) — both 1.8:1. Resolves identically in local and production (no Media Library / localhost URL).
- **Styles** (`main.css`): desktop keeps the 3.71:1 cinematic proportion as a **minimum** via `min-height: calc(100vw / 3.71)` (same content-driven model as mobile) so the Featured Apoiadores carousel can grow the section instead of being clipped by `overflow: hidden`; a green gradient remains as loading/failure fallback. `.hero-background-img` fills it via `object-fit: cover` / `object-position: center`. The copy is vertically centered by a flex `.site-container` (`align-items: center`). A subtle soft green gradient (`.hero-content::before`) tints only the left text-safe zone for legibility without obscuring the castle/family/flags.
- **Two-column composition**: when featured sponsors exist, `.hero-content` gets a `--with-sponsors` modifier and becomes a CSS grid at every breakpoint — hero copy left in `.hero-copy`, Featured Apoiadores right in `.hero-sponsors`, with both columns sharing one invisible grid (`align-items: stretch` + mirrored flex stacks): the badge top ↔ the panel top, and the CTA bottom ↔ the carousel viewport's bottom (a shared `--hero-panel-inset` token is reserved by the glass panel and mirrored as `padding-bottom` on `.hero-copy`, so `.hero-ctas` ends exactly on the viewport line instead of on the raw row line). Desktop/tablet (≥769px) present the sponsor as a **portrait 3:4 feature card**: a moderate fr-based column (`minmax(0, 1.6fr) minmax(0, 0.75fr)`; tablet 769–1199px tightens to `minmax(0, 1.05fr) minmax(0, 0.88fr)`; slightly reduced `clamp()` type scale), with the carousel pinned to the CTA baseline via `margin-top: auto` so its height derives from the column width instead of stretching to the row height. Mobile (≤768px) STAYS SIDE-BY-SIDE as one cohesive composition over the shared full-bleed photo (~55/45, rebalancing to ~53/47 at ≤374px so the portrait panel keeps usable width) with a scoped compact type scale and the portrait panel stretching down to the CTA line. Without sponsors the modifier is omitted and the hero stays single-column (`max-width: 640px`).
- **Responsive**: mobile switches to a dedicated `<picture>` source pair and its own sizing model — `aspect-ratio: auto; min-height: calc(100vw / 1.8)` so the hero is never shorter than the mobile image's 1.8:1 proportion while still growing naturally with its content; copy is bottom-anchored over the photo (`align-items: flex-end`) with the crop biased via `object-position: 38% center`. Compact mobile typography is retained. The `width`/`height` attributes on each `<source>`/`<img>` are truthful intrinsic metadata only; CSS fully determines the rendered box.
- **Accessibility**: the `<img>` is decorative (`alt=""`, `aria-hidden`); the `<section>` has an `aria-label`.
- **Performance**: `loading="eager"` + `fetchpriority="high"` (LCP). `conexao_hero_preload()` in `functions.php` emits media-matched `rel="preload" as="image" fetchpriority="high"` hints for the mobile/desktop hero WebP (front page only, `wp_head` priority 1 — the LCP fetch is the first resource hint in the head), URL-identical to the `<picture>` sources. The Google Fonts stylesheet is loaded non-render-blocking (async-CSS `media="print" onload` pattern + `<noscript>` fallback; see `conexao_fonts_non_blocking()` in `functions.php`) — it previously blocked first paint and caused the mobile hero's ~1.06 s LCP element render delay. The fonts `preconnect` hints are kept, but the redundant cross-origin `preload as="style"` hint for Google Fonts was removed (it sat ahead of the LCP image preload in the head and competed for the first network slot); the non-blocking stylesheet plus `font-display:swap` already handle the font load.

The hero background is **not** a Customizer setting — the `conexao_hero_image` Media control was removed (the green text-safe zone and castle/family/flags are part of the fixed asset). Only `conexao_hero_title` / `conexao_hero_subtitle` remain customisable.

## Homepage Apoiadores Carousel

The Featured Apoiadores carousel is a compact supporting showcase **inside the homepage Hero** (right-hand column beside the copy at every breakpoint, including mobile), rendered by the reusable template part `template-parts/featured-sponsors.php`. It is a responsive, dependency-free carousel over the **existing sponsor data model** — no new content type, no duplicated supporter data:

- **Data source**: `conexao_get_featured_sponsors()` (functions.php). Only sponsors with **Apoiador em destaque** (`_sponsor_featured = 1`) appear; ordering uses **Ordem de exibição** (`_sponsor_display_order`) ascending with a title tiebreaker so equal values never shuffle between loads. Sponsors without an order value sort last. Editing a supporter in wp-admin updates the section automatically (transient-cached under `conexao_home_sponsors`, invalidated on save).
- **Link behavior**: every tile navigates INTERNALLY to the Apoiador detail page (`/apoiadores/{slug}/`, rendered by `single-sponsor.php`) — the carousel stays visually clean with no social icons over the Hero image. The official website and all other channels are offered as contact buttons on the detail page instead.
- **Placement**: called from `front-page.php` inside `.hero-content`, wrapped in its own `.hero-sponsors` layout column beside `.hero-copy` (the copy column holds badge → heading → description → CTAs). The template part renders nothing when no supporter is featured, and the wrapper is skipped entirely so no empty column is reserved; when it does render, `.hero-content` gets a `--with-sponsors` modifier that enables the two-column grid (all breakpoints; mobile-tuned proportions ≤768px), tightens the hero copy spacing, and keeps the min-height sizing model (3.71:1 kept as a minimum) so the section can grow instead of clipping.
- **Markup** (`template-parts/featured-sponsors.php`): scroll-snap track (`.sponsors-carousel-viewport` > `.sponsors-carousel-list` > `.sponsors-slide`) with one large sponsor card (`.sponsor-tile`) visible at a time, framed over the hero photo in both themes; each tile is an internal link to the Apoiador detail page (no external jump, no icons over the Hero image). Each slide's visual is a single responsive `<img>` built by `conexao_sponsor_carousel_image()` from the Apoiador's ONE canonical image (`_sponsor_image`, **Imagem do Apoiador**) — the SAME portrait asset serves desktop and mobile, with no `<picture>` source switching between different sponsor images. The `large` size plus srcset/sizes candidates keep artwork crisp at the enlarged display scale while WordPress serves appropriately sized derivatives; truthful intrinsic width/height metadata is carried, and one alt (attachment alt → sponsor name fallback) means screen readers announce the supporter exactly once. Missing artwork falls back gracefully (canonical → legacy `_sponsor_mobile_image` → `_sponsor_desktop_image` → `_sponsor_logo` → featured image) and a record with no usable image renders the SVG placeholder instead of a broken image.
- **Responsive**: EXACTLY ONE sponsor per view on every breakpoint — each slide fills the viewport edge-to-edge so no neighbour is ever partially visible. The tile frame is PORTRAIT 3:4 on every breakpoint — desktop and mobile share the same orientation and visual treatment, only scaled responsively (desktop height derives from the column width and stays pinned to the CTA baseline; mobile's shared-grid stretch chain may extend it taller, never wider or squarer; `object-fit: contain` letterboxes every source ratio onto the mat without cropping or distortion), with small translucent prev/next arrows vertically centered INSIDE the image and pagination dots at its bottom center (the hero variant keeps its arrows visible ≤768px alongside native swipe; they slim to 28px at ≤374px). With a single featured supporter the adaptive static mode hides the controls.
- **Card treatment**: semantic tokens declared once on the component root (`.sponsors-carousel--hero`: `--sponsor-surface`, `--sponsor-border`, `--sponsor-border-hover`, `--sponsor-accent`, `--sponsor-text`, `--sponsor-shadow`, `--sponsor-shadow-hover`) drive every card/arrow rule — no color literals repeated per rule. Light mode: very light warm neutral surface (`#fcfbf7`), subtle translucent green border, soft deep-green shadow, dark neutral caption text, and a slim inset Conexão-green accent line along the top edge (`.sponsor-tile::before`) as the only brand accent. Dark mode: `dark-mode.css` redefines ONLY these tokens — deep translucent dark-green surface, subtle light border, high-contrast light text, soft shadow with a faint green glow. Sponsor artwork is never recolored; the card frames it.
- **Autoplay**: Hero variant only, 10-second interval implemented in `main.js` as a single `setTimeout` chain per carousel (never `setInterval`; timer id doubles as the armed flag). The first sponsor stays visible a full 10 seconds after load; each automatic advance re-arms the next full interval. Autoplay pauses while the user hovers (only where `(hover: hover)` matches), focuses anything inside, presses/touches the component, or the tab is hidden, and resumes from a fresh full interval when engagement ends. Manual navigation (buttons, arrow keys, Home/End) and user swipes/drags restart the countdown instead of triggering an immediate follow-up advance (programmatic autoplay scrolls are excluded via an `autoScrolling` flag). `prefers-reduced-motion: reduce` disables autoplay entirely (checked live, manual navigation still works); static mode (everything fits) never autoplays.
- **Adaptive**: when every tile fits without scrolling, JS adds `.is-static` — arrows hide and the row centers (simple layout instead of a pointless carousel); wrap-around prev/next only when scrollable.
- **Accessibility**: `aria-roledescription="carousel"`/`"slide"`, per-slide "Apoiador X de Y" labels, keyboard support on the track (arrows/Home/End), labelled buttons, visible focus states, polite live region announcing position (also updated by autoplay advances), reduced-motion aware autoplay.
- **Styles**: `main.css` ("Sponsors Carousel" base + "Featured Apoiadores carousel — Hero variant" blocks) + `dark-mode.css` hero token overrides.

## CSS Architecture

7 files, loaded in order via `functions.php`:

1. **Google Fonts** — Inter + Poppins (external)
2. **design-system.css** — Design tokens, typography, buttons, cards, filters, page headers, empty states, focus, responsive, reduced motion
3. **header-nav.css** — Header layout, primary nav, mobile menu, search
4. **main.css** — Hero, sections, grids, cards, footer, responsive breakpoints
5. **leisure.css** — Leisure archive + single layout
6. **sponsor.css** — Apoiador single layout + contact buttons (per-platform icon accents)
7. **dark-mode.css** — `[data-theme="dark"]` overrides

Versioning: `filemtime()` for cache busting (see `conexao_asset_version()` in functions.php).

## JavaScript

Single file: `assets/js/main.js` (deferred, no dependencies).

Features:
- Theme toggle (dark/light, localStorage)
- Mobile menu (full-screen overlay, focus trap, submenu toggles)
- Mobile search overlay
- Copy-to-clipboard buttons
- Leisure filters (desktop dropdowns + mobile "Filtrar" bottom sheet: always-visible radio sections, client-side location search for long lists, immediate apply on selection with in-place result swap — any selection auto-closes the sheet and returns focus to the "Filtrar" trigger; the sheet can be reopened to stack another filter (selections are preserved); focus trap, Escape/backdrop close, focus return)
- Sponsors carousel (homepage Apoiadores: scroll-snap track, arrows, keyboard nav, adaptive static mode, 10-second autoplay on the Hero variant with pause-on-interaction and reduced-motion support)
- Infinite scroll (`initInfiniteScroll`) — progressive enhancement on the Blog (`/blog/`), Guias (`/guias/`) and Lazer (`/lazer/`) archives, plus the Blog's own category-filtered views (`/blog/?categoria=slug`). Grids are tagged `data-infinite-scroll` in `home.php`/`archive.php`; the JS reads the next-page URL from the server-rendered `.conexao-pagination` component, fetches the real `/page/N/` URL (so `?categoria=`/`?county=` filters and main-query ordering are preserved by construction), parses the document, dedupes by `id="post-{ID}"`, and appends the cards via an IntersectionObserver sentinel (no scroll listeners). One request at a time; `history.pushState` per loaded page; `aria-live="polite"` loading state; manual "Tentar novamente" retry on error; observer disconnects at the end of pagination. A `pageshow` (bfcache) guard resets an in-flight request abandoned by back/forward navigation so scrolling continues where it stopped. Without JavaScript the numeric pagination keeps rendering and working — it is only hidden via the `.conexao-pagination--infinite-hidden` class when the enhancement initializes.
- Manual Load More (`initLoadMore`) — explicit "Carregar mais" button on the Eventos (`/eventos/`) and Cursos (`/cursos/`) archives. Unlike the infinite scroll above, these archives NEVER load a batch automatically: the next page is fetched only after an explicit click. Grids are tagged `data-load-more` in `archive.php`; the mechanism is otherwise identical (next-page URL read from the server-rendered pagination component, real `/page/N/` fetch, `id="post-{ID}"` dedupe, append-only). The button is disabled with a "Carregando..." label while the request is active, announces "Mais N eventos/cursos carregados." via an `aria-live="polite"` status region, has a visible `:focus-visible` outline, is re-enabled by a `pageshow` (bfcache) guard after back/forward cache restore, and is removed with a "Você chegou ao fim." note when no further page exists. Without JavaScript the numeric pagination keeps working untouched.

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
| `conexao_popular_posts()` | "Mais Lidos" query (ranks by `_conexao_view_count` recorded in `inc/post-views.php`) |
| `conexao_maybe_count_view()` / `conexao_record_view()` | Server-side view counting for "Mais Lidos" (see `inc/post-views.php`) |
| `conexao_sponsor_image_id()` | Resolves an Apoiador's canonical Imagem do Apoiador attachment ID with legacy fallbacks (mobile → desktop → logo → featured) |
| `conexao_sponsor_carousel_image()` | Builds the carousel slide's responsive `<img>` from the single canonical Apoiador image (same asset desktop + mobile) |
| `conexao_get_featured_sponsors()` | Featured Apoiadores for the homepage carousel (transient-cached) |
| `conexao_sponsor_contact_rows()` | Merges `_sponsor_link` + `_sponsor_contacts` into ordered, deduplicated display rows for the detail page |
| `conexao_contact_label()` / `conexao_contact_icon()` | Frontend label + inline-SVG icon per contact type (reuses existing brand paths) |
| `conexao_customize_register()` | Customizer sections (colors, social, hero [title/subtitle only], footer) |
| `conexao_accent_insensitive_posts_search()` / `conexao_accent_insensitive_posts_orderby()` | `posts_search` / `posts_search_orderby` filters — makes the main public search accent-insensitive over post_title, post_excerpt and post_content via an explicit `COLLATE utf8mb4_unicode_ci` on each LIKE comparison (see `inc/search.php`) |

### Image Sizes

| Size | Width x Height | Crop |
|------|---------------|------|
| `conexao-card` | 400 × 300 | Hard |
| `conexao-hero` | 1200 × 600 | Hard |
| `conexao-thumb` | 200 × 150 | Hard |
| `conexao-event-banner` | 640 × 360 | Hard |
| `conexao-provider-logo` | 320 × 180 | Soft |
| `conexao-job-portrait` | 1080 × 1920 | Soft |

**Responsive `sizes` hints (below-the-fold cards).** Templates that render
card thumbnails pass an explicit `sizes` attribute matched to the actual
rendered card width, instead of relying on WordPress's default
`(max-width: Wpx) 100vw, Wpx` hint. The default hint makes DPR-2 browsers
compute a needed width larger than the hard-cropped derivative, causing the
browser to download the uncropped full-size original (for event images this
was a multi-hundred-KiB portrait source displayed inside a 16:9 card).
Current hints:

- `event-preview-img` (homepage "Próximos Eventos"):
  `(max-width: 480px) 76px, (max-width: 768px) 92px, 320px` — capped at 320px
  so the DPR-2 need stays ≤640 device px and the browser always picks the
  640×360 `conexao-event-banner` crop.
- `event-card-banner-img` (`event-card.php`):
  `(max-width: 768px) 85vw, 320px` — same 640px-ceiling rationale.
- Featured article (`front-page.php`): `(max-width: 1024px) 92vw, 66vw`.
- Featured sidebar cards (`front-page.php`): `(max-width: 768px) 92vw, 380px`.

All below-the-fold card images keep `loading="lazy"`; the Hero `<picture>`
stays eager with `fetchpriority="high"`.

The `conexao-job-portrait` size is used by the Empregos (Jobs) artwork: the job

single template (`single.php`, job branch) and the `/empregos/` Jobs Landing
page (`page-empregos.php`). Job/landing artwork is authored vertically for
Instagram Stories (9:16 preferred, 2:3 acceptable); the soft crop fits the
image inside the box without cropping, so portrait compositions are preserved
in full and legacy landscape images render at their own natural ratio (never
distorted). Sources already smaller than the box fall back to the original
file, so existing Jobs need no thumbnail regeneration. Editors see a format
hint under the Featured Image box via the `admin_post_thumbnail_html` filter:
`conexao_job_featured_image_hint()` (jobs) and
`conexao_empregos_featured_image_hint()` (landing page).

### Empregos Landing page (`page-empregos.php` + `inc/empregos-landing.php`)

The `/empregos/` URL is a normal WordPress page (the `job` CPT archive is
disabled; single job posts keep their `/empregos/{slug}/` URLs). The page is
rendered by `page-empregos.php` as a clean information hub:

```
Empregos
  ↳ Portrait image   (featured image — conexao-job-portrait, Instagram-style)
  ↳ Jobs information (editable page body)
  ↳ Instagram / county guidance
  ↳ [ Mais informações ]  (optional; hidden when no link is set)
```

- **Editing**: everything is managed from wp-admin (Páginas → Empregos): title,
  portrait featured image, body content, plus the optional CTA link.
- **CTA link**: `inc/empregos-landing.php` registers the `_empregos_link` page
  meta and renders a minimal "Jobs — Link 'Mais informações'" metabox. When the
  URL is empty the button is not output at all; when external it opens in a new
  tab.
- **Layout/CSS**: `main.css` — desktop uses a two-column portrait+text grid;
  mobile (≤768px) stacks into a single natural column. Colours come entirely
  from the design-system tokens, so light and dark mode are automatic.

### Search (`inc/search.php`)

Makes the main public search accent-insensitive while preserving WordPress's
native search behavior (same WHERE clause over `post_title`, `post_excerpt`
and `post_content`, same relevance ordering). Stored content is never modified —
e.g. a page titled "Benefícios" stays "Benefícios"; it is the comparison
collation that changes.

The `posts_search` (WHERE) and `posts_search_orderby` (relevance ranking)
filters append an explicit `utf8mb4_unicode_ci` collation to each search `LIKE`
comparison at query time:

```sql
wp_posts.post_title COLLATE utf8mb4_unicode_ci LIKE '%beneficios%'
```

`utf8mb4_unicode_ci` is the project's configured collation (see `compose.yaml`
`WORDPRESS_DB_COLLATION`) and is accent-insensitive for all Portuguese
characters (á, à, â, ã, ä, é, ê, í, ó, ô, õ, ö, ú, ü, ç and uppercase). It is
universally available (MySQL 5.6+/8.x, MariaDB), so this works identically on
the local Docker stack and on the WordPress.com production host. When a
column is already accent-insensitive (the local stack) the COLLATE is a
harmless no-op; when it is accent-sensitive it forces a correct match.

- Scope: main public search only (`is_search` + `is_main_query`, not in
  `is_admin()`). Archive filters, the event importer's `_event_status` gating,
  admin searches and secondary `WP_Query` searches are untouched.
- The `?s=` search URL is preserved.
- Performance: a single `preg_replace` on a short SQL fragment per search
  request — no extra DB queries and no PHP loop over posts.
- The collation is overridable via the `conexao_search_collation` filter.

### Post views / "Mais Lidos" (`inc/post-views.php`)

Records one view per front-end content page load into the `_conexao_view_count`
post meta. The homepage "Mais Lidos" sidebar (`front-page.php`) ranks content
by that meta via `conexao_popular_posts()` (`ORDER BY meta_value_num DESC`,
single indexed meta query, `no_found_rows`, transient-cached under
`conexao_home_popular` for 5 minutes and invalidated on save/delete).

Design constraints:

- **Server-side only** — no AJAX beacon, no third-party script, no extra
  homepage query. The sole cost is one indexed postmeta `UPDATE` per content
  page view, performed atomically (`meta_value = meta_value + 1`) so
  concurrent requests never lose an increment; the meta row is inserted only
  on the first view (`add_post_meta` with unique key).
- **One increment per request** — `is_main_query()` plus a per-request static
  flag guarantee secondary `WP_Query` calls and repeated hooks never
  double-count.
- **Scope** — "Mais Lidos" is an informational-content ranking: **Blog
  (`post`) + Guias (`guide`) only**. Events, jobs, apoiadores, cursos and
  lazer are never counted or ranked, even when they have higher view counts.
  The scope lives in `conexao_view_count_post_types()` and is shared by both
  the counting gate and `conexao_popular_posts()`.
- **Inflation guards** — admin/AJAX/cron/REST requests, previews, feeds,
  embeds, logged-in users and common bots/crawlers (user-agent hint list) are
  not counted. Re-fetching a page counts as a genuine view (standard
  server-side behavior) — no aggressive bot infrastructure.
- **Freshness** — the ranking refreshes at most 5 minutes after new views
  (transient TTL); no per-view invalidation, so the homepage keeps hitting
  the cache between refreshes.

Content without any views yet falls back to "recent content" (date DESC), so
the section always renders even before view data accumulates.

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

Canonical order: Início, Blog, Guias, Eventos, Cursos, Lazer, Empregos, Apoiadores, Contato.

Note: "Irlanda" is intentionally NOT a navigation item. The /irlanda/ page remains published and directly accessible; it is simply not linked from the main navigation (desktop and mobile share the same `primary` menu). See `scripts/remove-irlanda-menu-item.php` for removing any legacy "Irlanda" item from an existing menu.

Note: "Sobre Nós" is intentionally NOT a navigation item either. The /sobre-nos/ page remains published and directly accessible at /sobre-nos/; it is simply not linked from the main navigation (desktop and mobile share the same `primary` menu). See `scripts/remove-sobre-nos-menu-item.php` for removing any legacy "Sobre Nós" item from an existing menu.

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
- `single-sponsor.php` — Apoiador detail template (description + contacts)
- `assets/js/main.js` — frontend JavaScript