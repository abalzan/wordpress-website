# Frontend / UI

## Design System

Defined in `assets/css/design-system.css`. Uses CSS custom properties mapped from WordPress Customizer values.

### Colors

| Variable | Default | Purpose |
|----------|---------|---------|
| `--color-primary` | `#0E6B3A` (green) | Main brand, buttons, links |
| `--color-primary-dark` | darkened 20% | Hover states |
| `--color-accent` | `#F68B1F` (orange) | CTAs, highlights |
| `--color-whatsapp` | `#25d366` | WhatsApp button |
| `--color-background` | `#ffffff` | Page background |
| `--color-surface` | `#ffffff` | Card/component backgrounds |
| `--color-surface-muted` | `--conexao-light-gray` | Muted sections |
| `--color-text` | `--conexao-dark` | Body text |
| `--color-text-secondary` | gray-600 | Secondary text/meta |
| `--color-border` | `--conexao-border` | Borders |

### Typography

- **Display (headings)**: Poppins (weights 500, 600, 700) via Google Fonts
- **Body**: Inter (weights 400, 500, 600, 700) via Google Fonts
- Scale: xs (0.75rem) through 5xl (3rem)

### Container

`.site-container` — `min(1200px, calc(100% - 48px))` on desktop, `min(100%, calc(100% - 32px))` on mobile. Uses `margin-inline: auto`.

### Breakpoints

- Mobile: up to 768px
- Desktop: 769px+
- Reduced motion: `prefers-reduced-motion: reduce`

## CSS Files (load order)

1. **Google Fonts** (external)
2. **design-system.css** — Design tokens, typography, buttons, cards, filters, page headers, empty states
3. **header-nav.css** — Header layout, navigation, mobile menu, search
4. **main.css** — Hero, sections, archive grids, cards, footer, responsive
5. **leisure.css** — Leisure archive filters (discovery heading, dropdowns, active-filter chips, result count, mobile sheet), single leisure layout, practical-notes block (`.leisure-practical-notes`, `.leisure-practical-verified`, `.leisure-practical-stale`)
6. **dark-mode.css** — `[data-theme="dark"]` overrides for all components

Asset versioning uses `filemtime()` for cache busting. `.htaccess` sets `Cache-Control: public, max-age=31536000, immutable` on CSS/JS.

## JavaScript

Single file: `assets/js/main.js` (deferred, no dependencies).

Features:
- **Theme toggle** — dark/light mode, persisted to `localStorage`, syncs `aria-pressed`
- **Mobile menu** — full-screen overlay, focus trap, submenu toggles, Escape to close
- **Mobile search** — full-screen overlay, auto-focus on open
- **Copy buttons** — share link copy to clipboard with visual feedback
- **Leisure filters** — desktop dropdowns (one open at a time); Tipo and Características are true multi-select listboxes (`aria-multiselectable`): every option is a real hyperlink that toggles its slug inside a shared filter-state snapshot, so selections accumulate across open/close cycles and all option URLs are built through the shared `conexao_leisure_filter_url()` helper (empty dimensions omitted, no `pagina` — any filter change resets to page 1). The group label ("Todos"/"Todas") is a reset action that clears ONLY its own dimension; triggers collapse to a single label or a "N selecionados" count (full selection stays in the `aria-label`); chips render one per selected value; OR within a dimension, AND between dimensions (`?categoria=a,b` / `?atributo=a,b` are tax_query IN). Localização stays single-select; mobile: prominent full-width "Filtrar" button (active-count badge) opening a modal bottom sheet with always-visible sections — Localização keeps instant-apply radios; Tipo/Características are checkbox groups (`categoria[]`/`atributo[]`) whose selections accumulate while the sheet stays open and are submitted together by "Mostrar resultados" (each section's nameless "Todos"/"Todas" checkbox is a reset control, never submitted). Results, chips, count badge and URL update in place with an "Atualizando..." state and one abortable request per apply — a late response can never overwrite a newer one. Focus trap, Escape/backdrop close and focus return; the chip/"Limpar filtros" controls run through the same instant pipeline on mobile only — on desktop they remain plain hyperlinks (native navigation). The in-place swap targets the stable `[data-leisure-results]` wrapper (`archive.php`, grid + pagination or the no-results empty state), re-binds the desktop dropdowns and re-runs the infinite scroll so it keeps loading the filtered pages; if the tapped chip is removed by the swap, focus moves to its successor instead of dropping to `<body>`.
- **Unified directory filters** (`initAgencyFilters`, /empregos/ only — see `template-parts/employment-opportunities.php` + `inc/employment-opportunities.php`) — the same interaction pattern as the Leisure filters applied to the unified "Oportunidades de emprego" directory (agencies + public-sector portals + Employment Permit-history employers in one grid): desktop hyperlink dropdowns (`?tipo=agency|public_sector|permit_history` — `permit_history` uses the structured `has_permit_history` flag; plus the existing `?area=`/`?localizacao=`/`?contrato=`, AND logic, area/location/contract options derived from the agency data only), active-filter chips, `role="status"` result count ("X oportunidades encontradas"), "Limpar filtros"; mobile "Filtrar" button + modal bottom sheet (radio fieldsets, focus trap, Escape/backdrop close). A radio change applies immediately by navigating to the clean filtered URL (empty values stripped, `#empregos-opportunities-title` anchor preserved; legacy section anchors still resolve). Server-side filtering — no card-hiding JS. The area/localização/contrato dimensions apply only where structured data exists (agencies): when a single non-agency type is selected their controls are hidden and active values remain as removable chips. Styles mirrored from leisure.css into `.agency-filters-*` in main.css/dark-mode.css (39b) because leisure.css is not enqueued on /empregos/.
- **Infinite scroll** — automatic on Blog/Guias/Lazer + category archives (IntersectionObserver sentinel, 480px rootMargin, one page at a time; bfcache `pageshow` guard so back/forward navigation never leaves a stuck request). Also on the unified `/empregos/` directory: that grid is a static-page PHP collection (not the main query), so `template-parts/employment-opportunities.php` paginates the already-filtered merged opportunities array server-side (`?pagina=N`, 24 per page, filter → count → slice, out-of-range `pagina` clamped to the last page) and renders the same `.conexao-pagination` contract (cards carry stable `id="opp-{key}"` DOM ids for dedupe; paginated URLs preserve all active filters; filter links never carry `pagina`, so any filter change resets to page 1; the final page renders no next link and single-page/empty results render no pagination at all).
- **Load More** — manual "Carregar mais" button on `/eventos/` and `/cursos/` only (no auto-loading): fetches the real next `/page/N/` URL on click, appends cards with dedupe, polite live-region announcements, disabled while loading, removed at end of results, re-enabled after back/forward cache restore

## Shared Template Parts

All in `template-parts/`:

| File | Used On |
|------|---------|
| `archive-header.php` | All CPT archives (title, eyebrow, description, filters) |
| `event-card.php` | Events archive grid |
| `event-filters.php` | Events archive filter bar (event-only: `?cidade=` towns + `?categoria=` categories used by published events) |
| `course-filters.php` | Cursos archive filter bar (course-only: `?categoria=` provider categories from `_provider_category` meta; renders nothing when no categories exist) |
| `guide-filters.php` | Guias archive filter bar (`?categoria=` conexao_category terms used by published guides) |
| `event-preview.php` | Homepage events section |
| `leisure-card.php` | Lazer archive grid — cards show a capped, prioritized set of Características attributes (max 4): Entrada (Gratuito / Pago / Gratuito em determinadas condições) → Ambiente (Interior + exterior / Exterior / Interior) → Acessibilidade → Estacionamento, then Famílias / Necessita reserva / Pet friendly. Environment attributes are normalized by the shared helper (`conexao_leisure_normalize_environment_attributes()` in functions.php): when `Interior + exterior` is assigned, the individual `Interior` / `Exterior` attributes never render alongside it (redundant legacy `_leisure_indoor`/`_leisure_outdoor` meta included); this is display normalization only — taxonomy data and `?atributo=` filter semantics are unchanged. Transporte público and Bicicleta are deliberately card-excluded (individual page only). Practical notes, verification dates and source URLs never appear on cards |
| `leisure-filters.php` | Lazer archive filter bar (desktop: discovery heading + wide dropdowns with location search + chips + count; mobile: bottom sheet with location search for long lists). Tipo and Características are standardized multi-select dropdowns (shared filter-state snapshot + `conexao_leisure_filter_url()` in functions.php; `?categoria=a,b` / `?atributo=a,b` = OR within the dimension, AND between dimensions; "Todos"/"Todas" reset only their own dimension). The Características dropdown reads `conexao_leisure_attribute` dynamically, so newly seeded attribute terms (e.g. Pago, Bicicleta) become filterable automatically via `?atributo=` |
| `provider-card.php` | Course providers archive/grid |
| `quick-access-card.php` | Homepage quick access grid |
| `help-shortcut-card.php` | Homepage "Precisa de ajuda?" compact utility shortcut chip (reuses Quick Access card data) |
| `newsletter-section.php` | Newsletter CTA section (homepage + archives) |
| `quote-section.php` | Testimonial/quote section (homepage + archives) |
| `pagination.php` | Archive pagination |
| `content-none.php` | Empty state |

## Dark Mode

- Toggled via `.theme-toggle` button
- State persisted in `localStorage` key `conexao-theme`
- Applied via `data-theme="dark"` on `<html>`
- Inline `<script>` in `<head>` prevents FOUC by reading localStorage before paint
- All CSS variables get dark overrides in `dark-mode.css`
- The `main.js` syncs `aria-pressed` on the toggle button
- **Green text/icons on dark surfaces** (`.section-eyebrow`, `.section-link`,
  `.featured-article-category`, `.events-section-link`,
  `.quote-author`, `.leisure-card-cta`, `.leisure-card-attr`,
  `.leisure-card-category`, `.conexao-breadcrumb-link`, and the active/hover
  states of the `/lazer/` filter UI — chips, dropdowns, checked radios): the
  dark-mode color is deliberately the dark brand green `#3ab875`
  (`--conexao-primary-text`, defined in the `dark-mode.css` token block)
  rather than `var(--color-primary)`. When page-optimize concatenates CSS, a
  later `:root` — `main.css`'s palette AND the `conexao_customizer_css()`
  inline block in `functions.php` — wins the cascade over the
  `[data-theme="dark"]` token block (equal specificity), so
  `--color-primary` stays at the light value `#0e6b3a` (contrast ~2.3:1 on
  dark tints, ~2.7:1 on the dark background). Green **backgrounds** keep
  `var(--color-primary)`: white text on `#0e6b3a` is 6.5:1 (on `#3ab875` it
  would fail at 2.5:1). Keep this split; don't revert text colors to
  `var(--color-primary)`.

## Accessibility details

- **Carousel pagination dots** (`.sponsors-carousel-dot`): each button is a
  24×24px hit target (WCAG 2.5.8) with the small visible dot drawn via
  `::before`; buttons sit 24px apart (`gap: 0`) so no target is obscured.
- **WhatsApp float**: wrapped in an `<aside>` (complementary landmark) in
  `conexao_whatsapp_button()` so it is contained by a landmark (axe `region`).

## Homepage Hero

The front-page hero is a full‑bleed cinematic image with overlaid text (the old green panel + separate right‑hand image card / `.hero-layout` grid has been removed):

- **Assets**: committed project files output in `front-page.php` via `get_template_directory_uri()` inside a responsive `<picture>` in `.hero-background`. Desktop fallback `<img>`: `conexaobr_Hero_image.png` (2057×764). Desktop WebP source (≥769px): a two-candidate `srcset` ladder — `conexaobr_Hero_image-1600.webp` (1600×594, q88, ≈140 KB) plus the `conexaobr_Hero_image.webp` master (2057×764, q88, ≈225 KB) with `sizes="100vw"`, so DPR-1 desktops rendering the full-bleed hero at ≤1600 CSS px download the 1600w derivative (~85 KiB saved — the hero measured ~1516×563 rendered while the 2057w master was delivered; q88 matches the master encoding so there is no visual difference). Mobile sources (≤768px): `conexaobr_Hero_image_mobile.webp` (900×500, q85, ≈100 KB — sized for DPR-2 phones) with PNG fallback (1683×935) — both 1.8:1. Resolves identically in local and production.
- **Sizing model** (one source of truth — the section controls dimensions):
  - Desktop (≥769px): `.hero-section { aspect-ratio: 3.71 / 1 }`; the image fills it via `object-fit: cover` / `object-position: center`; content vertically centered by a flex `.site-container`. Without featured sponsors the copy is single-column (`max-width: 640px`); with them, `.hero-content--with-sponsors` becomes a two-column grid — hero copy left in `.hero-copy` (~55–60%, slightly reduced `clamp()` type scale), Featured Apoiadores carousel right in `.hero-sponsors` on a subtle translucent green glass surface kept deliberately ~10–15% smaller than the copy column (sponsor column `minmax(0, 0.75fr)` desktop / `0.88fr` tablet; `sizes` hint `(min-width: 769px) 30vw`) so it reads as a supporting element (tablet tightens the ratio/gap).
  - Mobile (≤768px): `aspect-ratio: auto; min-height: calc(100vw / 1.8)` — the hero is never shorter than the mobile image's own 1.8:1 proportion and grows naturally with its content (no fixed pixel heights, nothing clipped). Copy is bottom-anchored over the photo (`align-items: flex-end`), image crop biased via `object-position: 38% center`.
  - The `width`/`height` attributes on each `<source>`/`<img>` carry truthful intrinsic metadata only; CSS fully determines the rendered box, so they never stretch or size the image.
- **Text safety**: a subtle left‑side green gradient (`.hero-content::before`, transparent after ~60–70%) darkens only the text‑safe zone; the castle/family/flags on the right stay unobscured. The right-hand sponsor carousel sits on its own light-touch glass surface (`.hero-sponsors`) so label/tiles stay legible over the photo without hiding it.
- **Accessibility**: decorative `<img>` (`alt=""`, `aria-hidden`); `<section aria-label>`.
- **Performance**: `loading="eager"` + `fetchpriority="high"` (LCP). The homepage `<head>` emits two media-matched `rel="preload" as="image" fetchpriority="high"` hints, hardcoded at the very top of `header.php` (BEFORE the inline theme-detection `<script>` — the LCP fetch is intentionally the FIRST resource hint in the head, on the front page only; `conexao_hero_preload()` in `functions.php` is kept unhooked for reference and mirrors the same markup): the mobile WebP ≤768px, and the desktop WebP ≥769px as an `imagesrcset`/`imagesizes` ladder (1600w + 2057w, `imagesizes="100vw"`) that resolves to the SAME candidate the `<picture>` srcset selects per device — DPR-1 desktops preload the 1600w derivative, DPR-2/wide screens the 2057w master (the plain `href` fallback keeps legacy browsers behaving exactly as before, i.e. fetching the master). The browser therefore starts the LCP fetch in parallel with render-blocking CSS with nothing competing for the first network slot. The Google Fonts stylesheet (`conexao-fonts` handle) is loaded **non-render-blocking** via the async-CSS pattern (`media="print" onload` → `media="all"`, plus `<noscript>` fallback in `conexao_fonts_non_blocking()`): previously this third-party stylesheet blocked every first paint (DNS+TLS to fonts.googleapis.com), which was the dominant cause of the mobile hero's ~1.06 s LCP element render delay. The fonts `preconnect` hints in `conexao_fonts_preconnect()` are kept (they warm DNS/TLS), but the previously-emitted **cross-origin `rel="preload" as="style"` hint for Google Fonts was removed**: it is redundant (the non-blocking `media="print"` stylesheet is still downloaded by the browser, and `font-display:swap` is already in the URL) and it was the only subresource sitting ahead of the LCP hero image preload in the head, competing for the first network slot and adding a third-party DNS+TLS round-trip on the critical path. `font-display:swap` keeps text rendering identical.
- **Not in Customizer**: the `conexao_hero_image` Media control was removed; only `conexao_hero_title`/`conexao_hero_subtitle` are customisable.
