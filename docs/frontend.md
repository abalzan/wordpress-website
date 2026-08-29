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
5. **leisure.css** — Leisure archive filters (discovery heading, dropdowns, active-filter chips, result count, mobile sheet), single leisure layout
6. **dark-mode.css** — `[data-theme="dark"]` overrides for all components

Asset versioning uses `filemtime()` for cache busting. `.htaccess` sets `Cache-Control: public, max-age=31536000, immutable` on CSS/JS.

## JavaScript

Single file: `assets/js/main.js` (deferred, no dependencies).

Features:
- **Theme toggle** — dark/light mode, persisted to `localStorage`, syncs `aria-pressed`
- **Mobile menu** — full-screen overlay, focus trap, submenu toggles, Escape to close
- **Mobile search** — full-screen overlay, auto-focus on open
- **Copy buttons** — share link copy to clipboard with visual feedback
- **Leisure filters** — desktop dropdowns (one open at a time); mobile: prominent full-width "Filtrar" button (active-count badge) opening a modal bottom sheet with always-visible radio sections, staged "Mostrar resultados" apply, focus trap, Escape/backdrop close and focus return
- **Infinite scroll** — automatic on Blog/Guias/Lazer + category archives (IntersectionObserver sentinel, 480px rootMargin, one page at a time; bfcache `pageshow` guard so back/forward navigation never leaves a stuck request)
- **Load More** — manual "Carregar mais" button on `/eventos/` and `/cursos/` only (no auto-loading): fetches the real next `/page/N/` URL on click, appends cards with dedupe, polite live-region announcements, disabled while loading, removed at end of results, re-enabled after back/forward cache restore

## Shared Template Parts

All in `template-parts/`:

| File | Used On |
|------|---------|
| `archive-header.php` | All CPT archives (title, eyebrow, description, filters) |
| `event-card.php` | Events archive grid |
| `event-filters.php` | Events archive filter bar |
| `event-preview.php` | Homepage events section |
| `leisure-card.php` | Lazer archive grid |
| `leisure-filters.php` | Lazer archive filter bar (desktop: discovery heading + dropdowns + chips + count; mobile: bottom sheet) |
| `provider-card.php` | Course providers archive/grid |
| `quick-access-card.php` | Homepage quick access grid |
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
  `.featured-article-category`, `.guide-link`, `.events-section-link`,
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
