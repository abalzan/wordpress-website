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
| `single-leisure.php` | `/lazer/{slug}/` | Dedicated leisure/tourism detail template. Phase 3A internal navigation: the header category chips link to the Lazer category filter (`/lazer/?categoria=slug` via `conexao_leisure_category_filter_url()`), the county in the location line links to the location filter (`/lazer/?county=slug` via `conexao_leisure_county_filter_url()`; towns have no filter and stay as display text), and the "Outros lugares relacionados" section is an internal-only hub: `conexao_leisure_related_internal_destinations()` (functions.php) returns up to 3 same-county eligible internal destinations, supplemented by same-category when short, excluding the current post and every record with an external destination (classified via the canonical `conexao_leisure_external_url()` in `inc/seo.php` — externally redirected records are never recommended). Each related card shows clickable category + county. When no eligible results exist the whole section is hidden. Phase 3B: the "Localização" block is fully conditional — it never renders as an empty section (town/county, verified address and links appear only when present; the heading is "Localização e Contato" only when address/website data exists, otherwise "Localização") — and the map link resolves through `conexao_leisure_map_url()` (functions.php): existing `_leisure_map_url`/verified address first, otherwise a deterministic Google Maps search URL derived at render time from title+town+county (official `?api=1&query=` scheme — no API key, no geocoding, no remote requests, no embed; descriptive `aria-label` "Ver localização de {nome} no mapa"). Authoritative display links ("Site oficial" / "Ver no Discover Ireland" / "Mais informações") render via `conexao_leisure_authoritative_links()` (functions.php) only on internal pages — the helper is gated on the canonical `conexao_leisure_external_url()` classification, so adding a display link never triggers the external redirect (the `_leisure_internal_page` meta flag, registered in conexao-data-model and edited via conexao-admin-ux, is the only redirect/display decoupling signal). The stale practical-notes message is truthful: it links to the official site / Discover Ireland / verification source only when a real link exists, otherwise it shows a neutral "Informações podem estar desatualizadas..." note Phase 3C: a "Próximos eventos" section (`.leisure-single-events`) renders ONLY on internal pages, built from the existing cached upcoming-event list (`Conexao_Event_Query::upcoming_events()` via `conexao_event_upcoming_ids()`) intersected by the destination's `conexao_county` term via `conexao_leisure_related_events()` (functions.php) — no second event-query system and no per-page transient; the runtime occurrence ordering is preserved; up to 3 cards reuse the EXISTING event-card component (same date / Hoje / Amanhã / recurrence labels and accessibility); the section renders nothing (never an empty "Nenhum evento encontrado") when the county has no upcoming events. Practical-attribute resolution is shared with the archive card via `conexao_leisure_attributes()` (functions.php), which normalizes the mutually exclusive environment attributes through `conexao_leisure_normalize_environment_attributes()` — when `Interior + exterior` is assigned, `Interior`/`Exterior` never render alongside it (card and single page cannot disagree). The hero image is served responsively via `conexao_leisure_hero_image()` (functions.php) using the WordPress responsive-image infrastructure — a WP-generated `srcset` (768/1024/1536/2048px proportional rungs) with explicit `sizes`, still eager with `fetchpriority="high"` because it is the LCP image, and kept in a constant 2:1 box via `object-fit: cover`. Internal leisure singles emit `TouristAttraction` JSON-LD (inc/seo.php) containing only actually-stored name/description/image/place — never opening hours, price, ratings, reviews, accessibility or geo coordinates — gated so externally-redirected pages never receive it |
| `single-sponsor.php` | `/apoiadores/{slug}/` | Dedicated Apoiador detail template: back link, portrait image (left column) beside name/description/“Entre em contato” buttons (right column) on desktop; single-column stack below 769px |
| `single.php` | `/{cpt}/{slug}/` | Single post for all other CPTs |
| `page.php` | `/{slug}/` | Static pages |
| `page-empregos.php` | `/empregos/` | Jobs Landing page — portrait image + editable body + optional "Mais informações" CTA |
| `page-landing.php` | specific pages | Landing page template |
| `home.php` | `/blog/` | Blog archive |
| `search.php` | `/search/` | Search results |
| `404.php` | 404 | 404 page |
| `header.php` | All | Site header, nav, mobile menu. The header hosts the "Anuncie Aqui" CTA in three responsive forms — full-text `.header-cta` (>1024px), compact `.header-cta-compact` in the mobile/tablet top bar (≤1024px; labelled ≥480px, 48px icon pill below), and the full-text drawer instance `.mobile-menu-cta` — all pointing to `/anuncie/`. See `docs/ui/mobile-anuncie-aqui-cta-report.md` |
| `footer.php` | All | Site footer |
| `sidebar.php` | All | Sidebar widget area |
| `comments.php` | Posts | Comments template |
| `searchform.php` | Search | Search form template |

## Template Parts (`template-parts/`)

| File | Used On |
|------|---------|
| `archive-header.php` | All CPT archives |
| `event-card.php` | Events archive grid — card banner carries the date badge (`12 / SET / SÁB`, Portuguese JAN–DEZ weekdays; multi-day via `_event_end_date` as `12-15` or `até 4 OUT`; subtle `Hoje`/`Amanhã` chip only for same/next-day events). Recurring events (Step 4): the badge adds a concise recurrence label (`Toda quarta-feira`, or the suffix-less multi-day form `Toda segunda e quarta`) and, when the series has an end date, a small uppercase `até D MMM` range line — badge is capped at `calc(100% - 32px)` so long labels wrap, never overflow. The `Hoje` chip for recurring events is decided by `Conexao_Event_Recurrence::occurs_on_date()` (one-time events keep the raw date comparison, so their output is unchanged). The visual badge is `aria-hidden`; the card body holds a sr-only `<time>` with the full date sentence (now suffixed `— <recurrence label>` for recurring events). Time (unchanged `_event_time`) is the first detail row; no duplicated inline date |
| `event-filters.php` | Events archive filter widget (event-only, Lazer/Empregos widget standard, AND-combined dimensions: `?county=` counties + `?cidade=` towns + `?categoria=` categories used by published events). Desktop: hyperlink dropdowns (listbox pattern, active dot + selected value on the trigger, client-side search for long option lists), active-filter chips (real hyperlinks that remove one filter), "Limpar filtros" and a `role="status"` result-count line. Mobile: full-width "Filtrar" button (active-count badge) opening the same modal bottom-sheet pattern as Lazer (radio fieldsets, search for long lists, focus trap, Escape/backdrop close, focus return; a radio change applies immediately by navigating to the clean filtered URL). County → City cascade: town options are scoped to the selected county via `conexao_get_event_towns()`; switching county clears a town from another county so combinations can never be invalid. Every option is a real hyperlink built through the shared `conexao_event_filter_url()` helper (changing one dimension never drops the others, page cursor resets, URLs stay shareable/refresh-safe). Filtered zero results render the shared `.event-filters-empty` state with a "Limpar filtros" reset (see `template-parts/content-none.php`). Styling: `.event-filters-*` in `main.css` + `dark-mode.css` (39c) |
| `course-filters.php` | Cursos archive filter bar (course-only: `?categoria=` provider categories from `_provider_category` meta; renders nothing when no categories exist) |
| `guide-filters.php` | Guias archive filter bar (`?categoria=` conexao_category terms used by published guides) |
| `event-preview.php` | Homepage events section — compact preview; recurring events append a small italic recurrence label (`Toda quarta-feira`) to the date/time meta line (`·`-separated), via `conexao_event_recurrence_label()` |
| `featured-sponsors.php` | Homepage Hero (Featured Apoiadores carousel; renders nothing when no supporter is featured) |
| `job-resources.php` | "Onde procurar emprego" — reusable external job-site cards reusing the Event card classes; data from `conexao_job_resources()` in `inc/job-resources.php`, filterable via `conexao_job_resources`. The cards are managed in wp-admin under **Empregos → Onde procurar emprego** (`conexao-job-resources` submenu, stored in the `conexao_job_resources` option; the built-in Jobs.ie / Indeed / IrishJobs defaults apply until first save). **Currently not rendered anywhere** — the Empregos landing intentionally stopped calling this template part (see the comment in `page-empregos.php`); the component, its data and its CSS are retained for future reuse. (The homepage uses the separate compact preview below.) |
| `employment-opportunities.php` | Empregos landing — the UNIFIED "Oportunidades de emprego" directory: recruitment agencies, official public-sector recruitment portals and Employment Permit-history employers in ONE shared, filterable grid. All cards reuse the Event card classes with narrow type modifiers (`.agency-card` / `.public-sector-card` / `.permit-employer-card`) and a small non-color type label. Data + filter state from `inc/employment-opportunities.php` (`conexao_employment_opportunities_filter_state()`), which reuses the per-type data helpers in `inc/recruitment-agencies.php` / `inc/permit-employers.php`. One filter toolbar (Tipo de oportunidade / Área de trabalho / Localização / Tipo de contrato) — see the Unified directory filters feature below. Above the filters sits ONE compact safety/permit notice (`.empregos-opportunities-notice`, consolidated from the former separate warning boxes) carrying the scam/payment warning, the agency-fee warning, the historical-only Employment Permit caveat (never current sponsorship) and the official rules link; the work-right reminder follows as a visually subordinate secondary sentence. Employers with `exception` permit status (e.g. Nua Healthcare) render as normal permit-history cards — the compact notice is the single Employment Permit explanation, and no separate exception block is displayed. Agency WRC licence numbers stay in the admin data — the card shows a simple "Licenciada" indicator only when a licence is present. Supersedes the former three separate sections (`recruitment-agencies.php`, `public-sector-jobs.php`, `permit-employers.php` template parts, removed) |
| `job-resources-preview.php` | Homepage `.section--jobs` slot ("Onde procurar emprego" — compact, text-only subset of the SAME `conexao_job_resources()` data used by the Empregos landing; first 3 resources only, no images/JS, cards reuse the Event card classes with `.jobs-home-grid` alignment tweaks in `main.css`) |
| `hero-events.php` | Retained; no longer rendered (hero uses committed full-bleed asset) |
| `leisure-card.php` | Lazer archive grid |
| `leisure-filters.php` | Lazer archive filter bar (desktop + mobile). Desktop: "Encontre o que fazer" heading, substantial (220–280px) Localização/Tipo dropdowns sharing the grid's horizontal rhythm (trigger shows the selected value while active), a client-side location search inside the Localização popover (filters the server-rendered options; no extra request), active-filter chip row (each chip a real hyperlink that removes that filter), "Limpar filtros" (only when active) and a result-count line read from the main query's `found_posts` (no extra query). Mobile: the mobile hierarchy re-orders the shared markup with CSS (flex `order` in the ≤768px block of `leisure.css`) so it reads: "Encontre o que fazer" heading, full-width "Filtrar" button (with active-count badge), active-filter chips, result count. The "Filtrar" button opens a modal bottom sheet of always-visible radio sections ("Localização", "Tipo" — only terms actually in use; the Localização section gets the same client-side search as the desktop popover when the list is long, and no search UI when it is short) with a sticky [Limpar] footer; the [Mostrar resultados] button is hidden while the instant-filter enhancement is active (class `leisure-mobile-form--instant` added by `main.js`) and remains only as the no-JS fallback. Radios apply immediately on selection — any selection auto-closes the sheet (focus returns to the "Filtrar" trigger, so the user lands directly on the filtered results); the sheet can be reopened to stack another filter (selections are preserved): the results wrapper (`data-leisure-results`, added in `archive.php`), chips, result count, "Filtrar" trigger badge and URL (`?county=`/`?categoria=`, unchanged scheme) are updated in place from a fetched server render of the target URL, with one abortable request per selection ("Atualizando..." while in flight, no flashing) and the infinite scroll re-bound to the swapped pagination so it keeps loading filtered pages; state is server-rendered from the URL and never reset on reopen; the sheet traps focus and returns it to the "Filtrar" button on close. On mobile the chip row and "Limpar filtros" also run through the same instant pipeline (chip/clear taps apply in place, and focus moves to the next chip — or the "Filtrar" trigger when cleared — instead of `<body>`), while desktop keeps those controls as plain hyperlinks with their original native navigation. The swapped `[data-leisure-results]` wrapper (in `archive.php`) contains the grid + pagination or — for a zero-result filter — the no-results empty state, so both render paths stay stable for the fetch+swap pipeline. |
| `provider-card.php` | Course providers archive/grid |
| `quick-access-card.php` | Homepage quick access grid |
| `newsletter-section.php` | Newsletter CTA (homepage + archives) |
| `quote-section.php` | Quote/testimonial section |
| `share-buttons.php` | Blog posts + Guias singles — canonical "Compartilhar" component (`.share-buttons`: Facebook, X, LinkedIn, Copiar link), rendered after the article content via `conexao_share_buttons()` (supports `post` and `guide`). Jetpack/WordPress.com's duplicate sharing output (`sharing_display` on `the_content`/`the_excerpt`) is removed in `functions.php` (`conexao_disable_jetpack_sharing()`), so this is the only share UI on articles. Copy-link behavior lives in `assets/js/main.js` (`.share-copy` + `data-copy-url`), styling in `main.css`/`dark-mode.css` |
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

- **Data source**: `conexao_get_featured_sponsors()` (functions.php). Only sponsors with **Apoiador em destaque** (`_sponsor_featured = 1`) appear; ordering uses **Ordem de exibição** (`_sponsor_display_order`) ascending with a title tiebreaker so equal values never shuffle between loads. Sponsors without an order value sort last. Editing a supporter in wp-admin updates the section automatically (transient-cached under `conexao_home_sponsors`, language-scoped by `conexao_lang_cache_key()` as `conexao_home_sponsors_pt` / `_en`, invalidated on save). On `/en/`, the secondary query follows the approved B2 rule: published EN sponsors plus published PT sponsors with no published EN translation, with PT masters replaced by linked EN translations so one identity renders once.
- **Link behavior**: every tile navigates INTERNALLY to the Apoiador detail page (`/apoiadores/{slug}/`, rendered by `single-sponsor.php`) — the carousel stays visually clean with no social icons over the Hero image. The official website and all other channels are offered as contact buttons on the detail page instead.
- **Placement**: called from `front-page.php` inside `.hero-content`, wrapped in its own `.hero-sponsors` layout column beside `.hero-copy` (the copy column holds badge → heading → description → CTAs). The template part renders nothing when no supporter is featured, and the wrapper is skipped entirely so no empty column is reserved; when it does render, `.hero-content` gets a `--with-sponsors` modifier that enables the two-column grid (all breakpoints; mobile-tuned proportions ≤768px), tightens the hero copy spacing, and keeps the min-height sizing model (3.71:1 kept as a minimum) so the section can grow instead of clipping.
- **Markup** (`template-parts/featured-sponsors.php`): scroll-snap track (`.sponsors-carousel-viewport` > `.sponsors-carousel-list` > `.sponsors-slide`) with one large sponsor card (`.sponsor-tile`) visible at a time, framed over the hero photo in both themes; each tile is an internal link to the Apoiador detail page (no external jump, no icons over the Hero image). Each slide's visual is a single responsive `<img>` built by `conexao_sponsor_carousel_image()` from the Apoiador's ONE canonical image (`_sponsor_image`, **Imagem do Apoiador**) — the SAME portrait asset serves desktop and mobile, with no `<picture>` source switching between different sponsor images. The `large` size plus srcset/sizes candidates keep artwork crisp at the enlarged display scale while WordPress serves appropriately sized derivatives; truthful intrinsic width/height metadata is carried, and one alt (attachment alt → sponsor name fallback) means screen readers announce the supporter exactly once. Missing artwork falls back gracefully (canonical → legacy `_sponsor_mobile_image` → `_sponsor_desktop_image` → `_sponsor_logo` → featured image) and a record with no usable image renders the SVG placeholder instead of a broken image.
- **Responsive**: EXACTLY ONE sponsor per view on every breakpoint — each slide fills the viewport edge-to-edge so no neighbour is ever partially visible. The tile frame is PORTRAIT 3:4 on every breakpoint — desktop and mobile share the same orientation and visual treatment, only scaled responsively (desktop height derives from the column width and stays pinned to the CTA baseline; mobile's shared-grid stretch chain may extend it taller, never wider or squarer; `object-fit: contain` letterboxes every source ratio onto the mat without cropping or distortion), with small translucent prev/next arrows vertically centered INSIDE the image and pagination dots at its bottom center (the hero variant keeps its arrows visible ≤768px alongside native swipe; they slim to 28px at ≤374px). With a single featured supporter the adaptive static mode hides the controls.
- **Card treatment**: semantic tokens declared once on the component root (`.sponsors-carousel--hero`: `--sponsor-surface`, `--sponsor-border`, `--sponsor-border-hover`, `--sponsor-accent`, `--sponsor-text`, `--sponsor-shadow`, `--sponsor-shadow-hover`) drive every card/arrow rule — no color literals repeated per rule. Light mode: very light warm neutral surface (`#fcfbf7`), subtle translucent green border, soft deep-green shadow, dark neutral caption text, and a slim inset Conexão-green accent line along the top edge (`.sponsor-tile::before`) as the only brand accent. Dark mode: `dark-mode.css` redefines ONLY these tokens — deep translucent dark-green surface, subtle light border, high-contrast light text, soft shadow with a faint green glow. Sponsor artwork is never recolored; the card frames it.
- **Autoplay**: Hero variant only, 10-second interval implemented in `main.js` as a single `setTimeout` chain per carousel (never `setInterval`; timer id doubles as the armed flag). The first sponsor stays visible a full 10 seconds after load; each automatic advance re-arms the next full interval. Autoplay pauses while the user hovers (only where `(hover: hover)` matches), focuses anything inside, presses/touches the component, or the tab is hidden, and resumes from a fresh full interval when engagement ends. Manual navigation (buttons, arrow keys, Home/End) and user swipes/drags restart the countdown instead of triggering an immediate follow-up advance (programmatic autoplay scrolls are excluded via an `autoScrolling` flag). `prefers-reduced-motion: reduce` disables autoplay entirely (checked live, manual navigation still works); static mode (everything fits) never autoplays.
- **Adaptive**: when every tile fits without scrolling, JS adds `.is-static` — arrows hide and the row centers (simple layout instead of a pointless carousel); wrap-around prev/next only when scrollable.
- **Accessibility**: `aria-roledescription="carousel"`/`"slide"`, per-slide "Apoiador X de Y" labels, keyboard support on the track (arrows/Home/End), labelled buttons, visible focus states, polite live region announcing position (also updated by autoplay advances), reduced-motion aware autoplay.
- **Styles**: `main.css` ("Sponsors Carousel" base + "Featured Apoiadores carousel — Hero variant" blocks) + `dark-mode.css` hero token overrides.

## Homepage Últimas Novidades Section

The "Últimas novidades" section (`front-page.php`, between Featured Content and Próximos Eventos — where the removed "Guia em Destaque" section used to sit) surfaces the 3 newest **Blog posts only**, answering "what's new?" (distinct from "Mais Lidos", which answers "what's popular?" — the two must not be merged):

- **Data source**: `conexao_latest_blog_posts( 3 )` (functions.php) — a single bounded `WP_Query` over `post` (Blog) only, `orderby date DESC`, `ignore_sticky_posts`, `no_found_rows`. Guias, Eventos, Cursos, Lazer, Empregos and Apoiadores are intentionally excluded. Post IDs are transient-cached under `conexao_home_latest` for 5 minutes and invalidated on save/delete (`conexao_homepage_cache_invalidate()`), so publishing a new post makes it appear without a manual purge.
- **Rendering**: the cached ID list is re-fetched with `post__in` + `orderby post__in` (preserves newest-first order) and rendered with the existing `.post-card` component inside the existing `.cards-grid` (auto-fill `minmax(300px, 1fr)` → 3 columns on desktop, 1 per row on mobile) — same image treatment (`conexao-card` size, `loading="lazy"`, `sizes="(max-width: 768px) 92vw, 380px"`), `.post-card-categories` wrapper, 15-word excerpt and date/reading-time meta as the Featured Content sidebar cards. The header reuses the standard `.section-header` pattern with a "Ver todos" `.section-link` to `/blog/`. The section renders nothing while there are no posts.
- **Mobile ordering**: `.section--latest-news` is slotted after `.help-section` (order 6) in the "Homepage Mobile Hierarchy" block in `main.css`; Jobs/Newsletter/Quote renumber to 7/8/9.
- **Dark mode / accessibility**: no new CSS surfaces — `.section--gray` and `.post-card` are already themed in `dark-mode.css`; the section uses `<section aria-labelledby>` + `<h2>` and the cards' existing link/focus patterns.

## Homepage "Precisa de ajuda?" Section

The "Precisa de ajuda?" section (`front-page.php`, between Featured Content and Últimas Novidades — the position where the removed "Guias em Destaque" section used to sit) is a compact, action-oriented **utility** section answering "what do I need right now?". It is deliberately NOT a second Quick Access grid: Acesso Rápido remains the broad navigation; this section shows only six problem-solving destinations (Moradia, Empregos, Documentos, Saúde, Benefícios, Finanças) as shortcut chips inside a single panel.

- **Single source of truth**: the shortcuts reuse the SAME card definitions the Acesso Rápido grid renders. Each Quick Access card in `front-page.php` carries an inert `key` slug (unused by the Quick Access rendering itself); the section selects an explicit, deliberately small subset via `$help_shortcut_keys` (`moradia`, `empregos`, `documentos`, `saude`, `beneficios`, `financas`). There is no second hard-coded label/icon/URL definition, so the two areas can never drift apart. Do NOT add new/removed destinations here — add/remove them in the Quick Access card array.
- **Rendering**: `template-parts/help-shortcut-card.php` — one `li.help-shortcut-item` per shortcut with an `a.help-shortcut` (decorative `aria-hidden` SVG icon chip + visible label that provides the accessible name). Destination resolution mirrors `template-parts/quick-access-card.php` using the same helpers (`conexao_get_guides_archive_url()`, `conexao_get_guide_category_url()`, `home_url()`); items without a resolvable destination are skipped. The section renders nothing when no shortcut resolves.
- **Markup/semantics**: `<section aria-labelledby="help-section-heading">` + `<h2>` "Precisa de ajuda?", a `<nav aria-label>` wrapping a `<ul class="help-shortcuts-grid">`. Static HTML/CSS only — no JavaScript, no extra queries, no dependencies.
- **Styles**: `main.css` "Precisa de ajuda? utility shortcuts" block — Quick Access visual language (design tokens, icon treatment, radius, borders) with its own `.help-*` class names so no Quick Access behavioural CSS (mobile priority ordering, horizontal shortcut scroll, mobile-only cards) can leak in. Desktop: 3-column chip grid; ≤768px: compact two-column grid (never a forced single row). Visible `:focus-visible` outline on chips.
- **Mobile ordering**: `.help-section` takes order 5 in the "Homepage Mobile Hierarchy" block in `main.css` (after Featured Content, before Últimas Novidades); Latest News/Jobs/Newsletter/Quote renumber to 6/7/8/9.
- **Dark mode**: dedicated block in `dark-mode.css` mirroring the Quick Access treatment (background section, surface panel, muted chips, `--conexao-primary-text` green icon glyphs for contrast).

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
- Directory filter widgets (`initDirectoryFilters` in main.js — one shared implementation of the Leisure filter interaction; every widget built on the `data-dropdown` / `data-mobile-*` contract reuses it): the /empregos/ agency directory (`initAgencyFilters` — see `template-parts/employment-opportunities.php`, `inc/employment-opportunities.php` and `inc/recruitment-agencies.php`) — server-side, URL-driven filtering of the unified "Oportunidades de emprego" directory via `?tipo=` (`agency` / `public_sector` / `permit_history` — canonical resource types; `permit_history` matches the structured `has_permit_history` flag from `_employer_permit_status`, never a visible card label), `?area=` (canonical `_agency_job_types` keys), `?localizacao=` (canonical location slugs normalized from `_agency_location` via `conexao_recruitment_agency_locations()` — "Nacional" matches every specific location; unknown segments are ignored) and `?contrato=` (`temporario`/`permanente` from the flag meta). AND logic between dimensions, single selection per dimension. The area/localização/contrato options derive from the agencies actually on display (an area/location/contract type with no agency is never offered; invalid URL values fail safe to "no filter"); those three dimensions apply only where structured data exists (the agencies), so when a single non-agency type is selected their dropdowns/fieldsets are hidden (active values still show as removable chips). Desktop: four hyperlink dropdowns with "Tipo de oportunidade" first (listbox pattern, active dot + selected value on the trigger, client-side search in the Localização popover), active-filter chips (real hyperlinks that remove one filter), "Limpar filtros" and a `role="status"` result-count line ("X oportunidades encontradas"). Mobile: full-width "Filtrar" button (active-count badge) opening the same modal bottom-sheet pattern as Lazer (radio fieldsets with "Tipo de oportunidade" first, client-side location search for long lists, focus trap, Escape/backdrop close, focus return); a radio change applies immediately by navigating to the clean filtered URL (empty "Todas"/"Todos" values stripped, `#empregos-opportunities-title` anchor preserved so the user lands on the directory; the legacy `#empregos-agencies-title`, `#empregos-public-sector-title` and `#empregos-permit-employers-title` anchors still resolve). Filter URLs land back on the directory; refresh, back/forward and shared URLs work natively (existing `/empregos/?area=…` bookmarks keep working unchanged); zero results render an accessible empty state ("Nenhuma oportunidade encontrada com esses filtros.") with a "Limpar filtros" reset. Styling: `.agency-filters-*` in `main.css` + `dark-mode.css` (39b) — leisure.css is not loaded on /empregos/, so the patterns are mirrored, not shared.
- Eventos archive filter (`initEventFilters`, same `initDirectoryFilters` contract as the agency directory — see `template-parts/event-filters.php`): server-side, URL-driven filtering of `/eventos/` via the event dimensions `?county=` (Localização), `?cidade=` (Cidade) and `?categoria=` (Categoria) — single-select per dimension, AND-combined (`?county=laois&cidade=portlaoise` = Laois AND Portlaoise), option URLs built through `conexao_event_filter_url()` (full state preserved, page cursor resets). Desktop: three hyperlink dropdowns (listbox pattern, active dot + selected value on the trigger, client-side search in the Localização/Cidade popovers when the option list is long), active-filter chips, "Limpar filtros" and a `role="status"` result count. Mobile: "Filtrar" bottom sheet (radio fieldsets + search; a radio change applies immediately by navigating to the clean filtered URL, empty "Todas"/"Todos" values stripped) — the County → City cascade stays server-side, so a county change re-renders the scoped town list and no invalid County + City combination can be built from the UI. Styling: `.event-filters-*` in `main.css` + `dark-mode.css` (39c).
- Sponsors carousel (homepage Apoiadores: scroll-snap track, arrows, keyboard nav, adaptive static mode, 10-second autoplay on the Hero variant with pause-on-interaction and reduced-motion support)
- Infinite scroll (`initInfiniteScroll`) — progressive enhancement on the Blog (`/blog/`), Guias (`/guias/`) and Lazer (`/lazer/`) archives, plus the Blog's own category-filtered views (`/blog/?categoria=slug`), and the unified Empregos directory (`/empregos/` — a static page, so pagination there is a hand-rendered `?pagina=N` component over the already-filtered merged opportunities array in `template-parts/employment-opportunities.php`: 24 cards per page, stable `id="opp-{key}"` per card, pagination URLs preserve every active filter, filter links never carry `pagina`). Grids are tagged `data-infinite-scroll` in `home.php`/`archive.php`; the JS reads the next-page URL from the server-rendered `.conexao-pagination` component, fetches the real `/page/N/` URL (so `?categoria=`/`?county=` filters and main-query ordering are preserved by construction), parses the document, dedupes by `id="post-{ID}"`, and appends the cards via an IntersectionObserver sentinel (no scroll listeners). One request at a time; `history.pushState` per loaded page; `aria-live="polite"` loading state; manual "Tentar novamente" retry on error; observer disconnects at the end of pagination. A `pageshow` (bfcache) guard resets an in-flight request abandoned by back/forward navigation so scrolling continues where it stopped. Without JavaScript the numeric pagination keeps rendering and working — it is only hidden via the `.conexao-pagination--infinite-hidden` class when the enhancement initializes.
- Manual Load More (`initLoadMore`) — explicit "Carregar mais" button on the Eventos (`/eventos/`) and Cursos (`/cursos/`) archives. Unlike the infinite scroll above, these archives NEVER load a batch automatically: the next page is fetched only after an explicit click. Grids are tagged `data-load-more` in `archive.php`; the mechanism is otherwise identical (next-page URL read from the server-rendered pagination component, real `/page/N/` fetch, `id="post-{ID}"` dedupe, append-only). The button is disabled with a "Carregando..." label while the request is active, announces "Mais N eventos/cursos carregados." via an `aria-live="polite"` status region, has a visible `:focus-visible` outline, is re-enabled by a `pageshow` (bfcache) guard after back/forward cache restore, and is removed with a "Você chegou ao fim." note when no further page exists. Without JavaScript the numeric pagination keeps working untouched.

## Custom WordPress Integration

### `functions.php`

Key functionality includes:

| Function | Purpose |
|----------|---------|
| `conexao_theme_setup()` | Theme supports, nav menus, content width |
| `conexao_enqueue_scripts()` | CSS/JS asset loading |
| `conexao_content_archive_query()` | `pre_get_posts` — archive ordering and filtering; on `/eventos/` feeds the recurrence-aware ordered ID list from the event runtime (`Conexao_Event_Query` via `conexao_event_upcoming_ids()`) with `post__in` + `orderby post__in`, keeping pagination/load-more and the `?cidade=`/`?categoria=` filters intact; legacy date-meta path when the runtime is inactive |
| `conexao_event_upcoming_ids()` | Shared ordered upcoming/recurring event ID list (thin wrapper over the event runtime's `Conexao_Event_Query`; used by the archive, hero widget, homepage events section, 404 and landing pages) |
| `conexao_event_display_date()` | Card display date: next occurrence for recurring events, stored `_event_date` otherwise |
| `conexao_event_is_recurring()` | Whether an event is a weekly recurring series (thin wrapper over `Conexao_Event_Recurrence::recurrence_type()`; false when the runtime is inactive) |
| `conexao_event_recurrence_label()` | Concise Portuguese recurrence label: `Toda quarta-feira` (single day, full `-feira` form) / `Toda segunda e quarta`, `Toda segunda, quarta e sexta` (multi-day drops `-feira`); empty string for one-time events. ISO weekday numbers → names mapping only; never exposes CSV or meta keys |
| `conexao_event_recurrence_end()` | Validated series end date (Y-m-d) or null when open-ended (thin wrapper over `Conexao_Event_Recurrence::recurrence_end()`) |

**Recurrence presentation** (Step 4): event cards (`template-parts/event-card.php`)
show a concise label (`Toda quarta-feira`, or the suffix-less multi-day form
`Toda segunda e quarta`) and, when the series has an end date, a small uppercase
`até D MMM` range line. The `Hoje`/`Amanhã` chip for recurring events is decided
by `Conexao_Event_Recurrence::occurs_on_date()` (one-time events keep the raw
date comparison, so their output is unchanged). The compact homepage preview
(`template-parts/event-preview.php`) appends the same recurrence label to the
date/time meta line. All recurrence logic lives in the runtime class; templates
only map ISO weekday numbers to Portuguese names — raw CSV and meta keys never
reach the markup.
| `conexao_get_terms_for_post_type()` | Filter-context term resolution |
| `conexao_get_provider_categories()` | Dynamic provider category discovery |
| `conexao_homepage_query()` | Transient-cached homepage queries |
| `conexao_homepage_cache_invalidate()` | Transient invalidation on save |
| `conexao_relabel_posts_to_blog()` | Label override: "Posts" → "Blog" |
| `conexao_modify_primary_nav_items()` | Nav item insertion/removal (priority 20) |
| `conexao_normalize_primary_nav_sections()` | Nav binding + active state (priority 25) |
| `conexao_bind_section_object()` | Binds a nav section to its canonical object; page sections resolve the current language’s LINKED Polylang translation (so EN Jobs binds to `/en/jobs/`). The Jobs section is page-backed (`path = empregos`) because the `job` CPT has `has_archive = false` — see CONEXAO_BR_EN_NAV_LANGUAGE_CONTEXT_FIX_REPORT.md |
| `conexao_posts_page_url( $target_slug )` | Language-aware URL of the posts page (Blog). Returns a real linked translation when one exists, otherwise the language home + the posts page path (`/blog/` → `/en/blog/`) — the approved B2 destination. Blog is allowlisted in `conexao_b2_page_allowlist()`; once the linked EN posts page exists (Stage 5) the EN Blog is a real English archive |
| `conexao_resolve_posts_page_request( $query_vars )` / `conexao_mark_posts_page_query( $query )` | Stage 5: resolve the posts-page request in the REQUESTED language. The posts page path is shared by both languages, so WordPress' language-blind page lookup would return the Portuguese page for `/en/blog/`; the request filter hands WordPress the posts page of the current language, and the query filter restores posts-archive semantics (`is_home` + `is_posts_page`, no `page_id`) so pagination and `?categoria=` keep working. Default-language requests are untouched |
| `conexao_fix_nav_active_states()` | Active state conflict resolution |
| `conexao_popular_posts()` | "Mais Lidos" query (ranks by `_conexao_view_count` recorded in `inc/post-views.php`) |
| `conexao_latest_blog_posts()` | "Últimas novidades" homepage query — 3 newest Blog posts by publication date (transient-cached under `conexao_home_latest`) |
| `conexao_maybe_count_view()` / `conexao_record_view()` | Server-side view counting for "Mais Lidos" (see `inc/post-views.php`) |
| `conexao_sponsor_image_id()` | Resolves an Apoiador's canonical Imagem do Apoiador attachment ID with legacy fallbacks (mobile → desktop → logo → featured) |
| `conexao_sponsor_carousel_image()` | Builds the carousel slide's responsive `<img>` from the single canonical Apoiador image (same asset desktop + mobile) |
| `conexao_get_featured_sponsors()` | Featured Apoiadores for the homepage carousel (transient-cached) |
| `conexao_sponsor_archive_ordered_ids()` | Ordered Apoiador post IDs for `/apoiadores/` (same `_sponsor_display_order` field as the hero; no-order supporters newest first; applied via `post__in` before pagination) |
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
  ↳ [ Ver vagas no Instagram ]  (optional; hidden when no link is set)
  ↳ Vagas / Openings  (Stage 6 — the real job CPT records, language-aware)
  ↳ Oportunidades de emprego  (unified opportunities directory)
```

- **Editing**: everything is managed from wp-admin (Páginas → Empregos): title,
  portrait featured image, body content, plus the optional CTA link.
- **CTA link**: `inc/empregos-landing.php` registers the `_empregos_link` page
  meta and renders a minimal "Jobs — Link" metabox. The button label is
  "Ver vagas no Instagram". When the URL is empty the button is not output at
  all; when external it opens in a new tab.
- **Vagas section (Stage 6)**: `conexao_empregos_current_jobs()`
  (`inc/empregos-landing.php`) lists the published `job` records in the current
  language — PT jobs on `/empregos/`, EN jobs on `/en/jobs/` (plus, while a job
  remains untranslated, its PT original as the approved B2 set — never both
  languages of one identity; cards reuse the shared `.archive-grid` /
  `.archive-card` markup and link to the record's own permalink). The ID list is
  cached in a language-scoped transient and flushed on job save/delete. The
  section is hidden entirely when no published job exists.
- **Breadcrumb language awareness (Stage 6)**:
  `conexao_empregos_page_url()` returns the linked translation's URL on a
  non-default-language request (the EN Jobs crumb on an EN job detail points at
  `/en/jobs/`, not `/empregos/`); on Portuguese it is byte-identical to before.
- **Layout/CSS**: `main.css` — desktop uses a two-column portrait+text grid;
  mobile (≤768px) stacks into a single natural column. Colours come entirely
  from the design-system tokens, so light and dark mode are automatic.

### Employment Opportunities data + filter layer (`inc/employment-opportunities.php`)

Shared module backing the unified /empregos/ directory. It gives every
employment resource one normalized representation with a canonical
`resource_type`, plus the unified directory's filter state:

- `agency` — the `recruitment_agency` CPT. Normalizing reuses the existing
  helpers (areas from `_agency_job_types` canonical keys via
  `conexao_recruitment_agency_area_keys()`, locations via the
  `conexao_recruitment_agency_locations()` registry, `temporario`/
  `permanente` from the flag booleans, `licensing_status` = `'licensed'`
  only when `_agency_wrc_licence` is present). Displayability follows the
  existing render rule (no `_agency_website` → no item).
- `public_sector` — `conexao_public_sector_jobs()`. No areas/locations/
  contract values are invented.
- `permit_history` — the `permit_employer` CPT. `has_permit_history` comes
  exclusively from `_employer_permit_status` via
  `conexao_permit_employer_status()`: `verified` AND `exception` are TRUE
  (both mean verified HISTORICAL DETE evidence; an exception record only
  adds the employer's current-position statement), `unverified` is FALSE.
  It never means "currently sponsoring" and no separate sponsor flag
  exists. Employers carry no areas/contract/licensing data and their
  free-text `_employer_location` stays a display string in `meta`.

Common item shape: `key` (`{type}:{ID|slug}`, unique — the collection
deduplicates), `title`, `url`, `resource_type`, `areas`, `locations`,
`contract_types`, `has_permit_history`, `description`, `phone`,
`licensing_status`, `meta` (per-type card-UI display data), `_source`
(original WP_Post/array). Fields without meaning for a type stay empty.

API: `conexao_employment_opportunities( $resource_types = array() )` (shared
collection, agency order), `conexao_employment_opportunities_filtered(
$area, $location, $contrato, $tipo = '' )` (AND logic — agency matching
delegates to `conexao_recruitment_agency_matches_filters()`, non-agency
items never match a non-empty area/location/contrato filter), the three
`conexao_employment_opportunity_from_*()` normalizers, and
`conexao_employment_opportunities_filter_state()` — the unified directory's
single filter entry point. It reads `?tipo=` (validated against the
canonical `conexao_employment_opportunity_types()`; `permit_history`
additionally requires the structured `has_permit_history` flag), the
existing `?area=`/`?localizacao=`/`?contrato=` dimensions (validated and
matched exactly like the agency directory, so existing URLs keep working),
derives the option lists from the displayable collection (area/location/
contract options only where structured data exists — the agencies), and
returns the filtered items plus all option lists for the template.


### Search (`inc/search.php`)

Makes the main public search accent-insensitive while preserving WordPress's
native search behavior (same WHERE clause over `post_title`, `post_excerpt`
and `post_content`, same relevance ordering). Stored content is never modified —
e.g. a page titled "Benefícios" stays "Benefícios"; it is the comparison
expression that changes.

The `posts_search` (WHERE) and `posts_search_orderby` (relevance ranking)
filters rewrite each search `LIKE` comparison so **both sides** are first
re-interpreted as raw bytes (`USING binary`) and then as utf8mb4, compared
under an explicit accent-insensitive collation at query time:

```sql
CONVERT(CONVERT(wp_posts.post_title USING binary) USING utf8mb4)
  COLLATE utf8mb4_unicode_ci
  LIKE CONVERT(CONVERT('%beneficios%' USING binary) USING utf8mb4)
```

`utf8mb4_unicode_ci` is the project's configured collation (see `compose.yaml`
`WORDPRESS_DB_COLLATION`) and is accent-insensitive for all Portuguese
characters (á, à, â, ã, ä, é, ê, í, ó, ô, õ, ö, ú, ü, ç and uppercase). It is
universally available (MySQL 5.6+/8.x, MariaDB).

**Why both sides go through `USING binary` — production root cause.** The
production WordPress.com host declares the `wp_posts` columns as latin1
(UpdraftPlus dumps: `DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci`; see
`scripts/restore-updraft-db.sh`) while the stored bytes are genuine UTF-8
("Saúde" = `5361C3BA6465`), and the WordPress connection itself uses latin1.

History of the three implementations:

1. Bare `wp_posts.post_title COLLATE utf8mb4_unicode_ci` → MySQL rejected the
   whole query (`ERROR 1253: COLLATION 'utf8mb4_unicode_ci' is not valid for
   CHARACTER SET 'latin1'`), WordPress swallowed the error, and every `?s=`
   search returned an empty result set — even ASCII terms such as `dublin`.
2. Single-sided `CONVERT(wp_posts.post_title USING utf8mb4)` → the COLLATE
   became valid, but `CONVERT` converts **from the declared charset
   (latin1)**, re-encoding the already-UTF-8 bytes into mojibake
   ("SaÃºde"), so unaccented patterns *still* never matched. Production kept
   returning results only for ASCII-only text.
3. Current two-sided byte re-interpretation (above): `USING binary` is
   byte-transparent, so the second `CONVERT` re-interprets the *stored/sent*
   bytes as utf8mb4 — the exact text — on both the column and the pattern
   literal (which is also sent as UTF-8 bytes over the latin1 connection).
   Verified against a byte-faithful production copy with a latin1 session:
   `saude` → 27 SQL matches (plain `LIKE`: 1), accented `saúde` → 5, and full
   front-end search works (`scripts/charset-migration/artifacts/
   search-fix-*`). The expression is also a no-op wrapper on utf8mb4 columns
   + utf8mb4 connections, so it behaves identically before and after any
   future storage migration.

- Scope: main public search only (`is_search` + `is_main_query`, not in
  `is_admin()`). Archive filters, the event importer's `_event_status` gating,
  admin searches and secondary `WP_Query` searches are untouched.
- The `?s=` search URL is preserved — no custom endpoint, no JS filtering, no
  external search service.
- The transformation is idempotent (safe against re-entry) and only touches
  `wp_posts.{post_title|post_excerpt|post_content}` immediately before a
  `(NOT )?LIKE`, so single terms, multiple terms, quoted phrases, exclusion
  terms and the native relevance ordering all behave exactly like WordPress
  native search, just accent-insensitive.
- Performance: a single `preg_replace` on a short SQL fragment per search
  request — no extra DB queries and no PHP loop over posts. Native search uses
  a leading-wildcard `LIKE` (no index possible), so the `CONVERT` wrapper adds
  no meaningful overhead.
- The collation is overridable via the `conexao_search_collation` filter.

### Apoiadores Directory (`/apoiadores/` in `archive.php` + `single-sponsor.php`)

Intentionally simple partner showcase — no search, no category filters, no user-facing sort controls (the partner count is small; browsing cards IS the experience). The card order is NOT publication date: **`/apoiadores/` follows the same editor-curated ordering as the homepage Hero carousel** — "Ordem de exibição" (`_sponsor_display_order`) ascending first, then supporters without an order value newest published first. Editing the field in wp-admin moves a supporter immediately; no code change is required for new supporters (they land in the second group at the right position automatically).

**Archive (`archive.php`, sponsor branch):**
- Header: shared `archive-header.php` with eyebrow "Parceiros da Comunidade" (complements the h1 "Apoiadores" instead of repeating it), title, and description "Conheça os negócios que apoiam a comunidade brasileira na Irlanda." (from `conexao_archive_description()` in `inc/seo.php`).
- Ordering: `conexao_sponsor_archive_ordered_ids()` (functions.php) computes the full ordered post-ID list — supporters with `_sponsor_display_order` first (ascending; ties by title ascending, then post ID — same tiebreaker the homepage carousel uses), then supporters without a value by `post_date` DESC (newest first, post ID DESC tiebreak). The main query is fed this list via `post__in` + `orderby => post__in` (the same mechanism the events archive uses), so ordering happens BEFORE pagination and every `/apoiadores` page keeps the fixed global order. Numeric `0` is a valid order value, never treated as empty.
- Cards reuse the shared `archive-card` in `archive-grid` (3 columns desktop → 1 mobile), plus:
  - Portrait image: `conexao_sponsor_carousel_image()` (canonical "Imagem do Apoiador", sponsor title as alt fallback) in the same 3:4 `object-fit: contain` frame as the Hero carousel (`.post-type-archive-sponsor` rules in `main.css`) — lazy-loaded, srcset/sizes/width/height, never cropped or stretched.
  - Lightweight body: optional category pill, business name (the title link carries the accessible name), trimmed excerpt (omitted when empty), and an `archive-card-cta` link ("Conhecer o Apoiador") — a real link to the Apoiador detail page, independently clickable and keyboard/touch accessible without JavaScript, pinned to the card bottom via `margin-top: auto`).
  - Internal navigation only: `.archive-card--clickable` affordances are a right-arrow image chip and title arrow (`→`) — NOT an external-link icon — because the card opens the Apoiador detail page where description and every configured contact channel live. No contact buttons on the card.
- Empty state (`content-none.php` sponsor branch): "Nenhum apoiador cadastrado ainda." / "Em breve, novos negócios estarão apoiando nossa comunidade."

**Detail page (`single-sponsor.php`):** unchanged structure — portrait image (LCP, eager) → name → category/county → description → "Entre em contato" buttons rendered ONLY for configured channels (via `conexao_sponsor_contact_rows()`, which folds in the legacy `_sponsor_link` website first). Dark mode and mobile behavior are token-driven (see `assets/css/sponsor.css` and the "Apoiador single" section of `dark-mode.css`).

**Deliberately out of scope:** search, user-facing filters/sort controls, contact buttons on cards, and any directory navigation — do not add them while the partner count is small. (The card ORDER is editor-controlled via "Ordem de exibição", as documented above; "sorting" here means visitor-facing sort widgets.)


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

Handles titles, meta descriptions, canonical URLs, Open Graph, Twitter Cards, schema.org (WebSite, Organization, Article, Event, JobPosting, sponsor), breadcrumbs, XML sitemap (`/sitemap.xml`), robots.txt, and legacy/English-to-Portuguese 301 redirects. Query-string pagination on static pages (`?pagina=N` with N > 1 — the unified /empregos/ directory) is noindexed and canonicalizes to the base page, exactly matching the `is_paged()` treatment of `/page/N/` archives. The theme's canonical is the ONLY canonical on the page: WordPress core's `rel_canonical()` is removed (`remove_action` in `inc/seo.php`) because otherwise singular pages emitted a second, unnormalized canonical tag alongside the theme's.

Breadcrumb hierarchy (`conexao_seo_breadcrumb_data()`), rendered by `header.php` via the shared `conexao-breadcrumbs` component and reused for the `BreadcrumbList` schema (single source, no duplicate markup):

- Blog archive `/blog/` (incl. `?categoria=` filtered views): Início → Blog (current page).
- Blog single: Início → Blog (`/blog/` posts page) → Category (native `category` taxonomy, i.e. `/category/{slug}/` — the `?categoria=` filter is only used by the archive UI) → Post title. With multiple categories, the first term returned by `get_the_terms()` (ordered by name) is used.
- Other CPT singles: Início → CPT archive → `conexao_category` term (when present) → `conexao_county` term for events/apoiadores → title.
- **Guides (`guide`)**: the `conexao_category` crumb links to the EXISTING filtered Guias archive (`/guias/?categoria=<slug>` — the same tax_query the archive filter bar applies), built through the shared `conexao_get_guide_category_url()` helper. It never links to the taxonomy term archive (`/categories/<slug>/` would 301 to the standalone `/<slug>/` static page, e.g. `/moradia/`). Multiple categories: first term returned by `get_the_terms()` (ordered by name) is used, the same deterministic rule applied before. See `docs/guias-breadcrumb-filter-report.md`.

### Navigation Logic

The primary navigation is dynamically modified at render time:

1. Remove "Notícias" items
2. Change "Home" label to "Início" (Portuguese) / "Home" (English)
3. Insert fallback "Blog" item immediately before "Contato"
4. Insert "Cursos" before "Empregos"
5. Ensure Lazer section exists
6. Bind each item to its canonical WordPress object
7. Fix active-state conflicts via URL pattern matching

Canonical order: Início, Apoiadores, Guias, Eventos, Cursos, Lazer e turismo, Empregos, Blog, Contato.

The Blog item is language-aware: it resolves to `/blog/` on Portuguese and to `/en/blog/` on English (an approved B2 destination — the posts page has no EN translation, so `/en/blog/` renders the Portuguese posts under the English shell instead of redirecting back to `/blog/`). See CONEXAO_BR_ENGLISH_BLOG_NAVIGATION_FIX_REPORT.md.

The stored menu order is the WordPress-native `nav_menu_item` `menu_order` (desktop and mobile share the same `primary` menu, so one order covers both); see `scripts/reorder-primary-menu-blog-apoiadores.php`.

Note: "Irlanda" is intentionally NOT a navigation item. The /irlanda/ page remains published and directly accessible; it is simply not linked from the main navigation (desktop and mobile share the same `primary` menu). See `scripts/remove-irlanda-menu-item.php` for removing any legacy "Irlanda" item from an existing menu.

Note: "Sobre Nós" is intentionally NOT a navigation item either. The /sobre-nos/ page remains published and directly accessible at /sobre-nos/; it is simply not linked from the main navigation (desktop and mobile share the same `primary` menu). See `scripts/remove-sobre-nos-menu-item.php` for removing any legacy "Sobre Nós" item from an existing menu.

Menu selection under Polylang: the header menu resolves through Polylang's per-language `nav_menus` option (see `scripts/assign-polylang-nav-menus.php` for PT and `scripts/create-en-primary-menu.php` for the EN "Main Menu" mirror; docs/routing.md §Navigation Architecture). The render-time nav layer is language-aware (language-resolved archive/page destinations, language-scoped active states), so both languages render their own curated menu. Both header `wp_nav_menu()` calls use the safe empty fallback `conexao_safe_nav_menu_fallback()` — never `wp_page_menu`'s automatic page list.

## Performance Optimizations

- Emoji script/style removal
- wp-embed script deregistration
- Block library CSS selectively dequeued (only loaded on pages using blocks or shortcodes)
- REST API link removal from `<head>`
- Homepage queries cached as transients (5 min, invalidated on save) — including the "Últimas novidades" Blog query (`conexao_home_latest`)
- CSS asset versioning via `filemtime()`
- Lazy loading on content images
- Fetchpriority="high" on hero image (LCP)

## Dependencies

- `conexao-data-model` (CPTs and taxonomies)
- `conexao-content` (shortcodes used in page content)
- `conexao-admin-ux` (status meta, editor enhancements)
- `conexao-event-runtime` (event meta, `conexao_town`, `_event_status` filtering via `pre_get_posts`)

## Files to Inspect First

- `functions.php` — all theme setup, queries, nav, shortcodes
- `inc/seo.php` — complete SEO module
- `inc/rest-language.php` — bilingual REST contract (Stage 4.1): `?lang=pt|en`
  collection filtering, the `conexao_language` record field, detail language
  rules and the REST event-status gate; verified by
  `tests/test-stage41-rest-language.php` and
  `scripts/stage41-rest-verify.py` (see docs/routing.md §English rollout state)
- `front-page.php` — homepage template
- `archive.php` — shared archive template
- `single-leisure.php` — leisure detail template
- `single-sponsor.php` — Apoiador detail template (description + contacts)
- `tests/test-leisure-related-events.php` — Phase 3C related-events helper tests (run: `docker compose exec wordpress php /var/www/html/wp-content/themes/conexao-br-irlanda/tests/test-leisure-related-events.php`)
- `tests/test-leisure-attribute-normalization.php` — leisure attribute environment normalization tests (`Interior + exterior` suppresses redundant `Interior`/`Exterior`, including the legacy-meta fallback; card renders through the shared helper; run: `docker compose exec wordpress php /var/www/html/wp-content/themes/conexao-br-irlanda/tests/test-leisure-attribute-normalization.php`)
- `assets/js/main.js` — frontend JavaScript