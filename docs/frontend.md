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
5. **leisure.css** — Leisure archive filters (dropdowns, mobile sheet), single leisure layout
6. **dark-mode.css** — `[data-theme="dark"]` overrides for all components

Asset versioning uses `filemtime()` for cache busting. `.htaccess` sets `Cache-Control: public, max-age=31536000, immutable` on CSS/JS.

## JavaScript

Single file: `assets/js/main.js` (deferred, no dependencies).

Features:
- **Theme toggle** — dark/light mode, persisted to `localStorage`, syncs `aria-pressed`
- **Mobile menu** — full-screen overlay, focus trap, submenu toggles, Escape to close
- **Mobile search** — full-screen overlay, auto-focus on open
- **Copy buttons** — share link copy to clipboard with visual feedback
- **Leisure filters** — desktop dropdowns (one open at a time), mobile bottom sheet with accordions

## Shared Template Parts

All in `template-parts/`:

| File | Used On |
|------|---------|
| `archive-header.php` | All CPT archives (title, eyebrow, description, filters) |
| `event-card.php` | Events archive grid |
| `event-filters.php` | Events archive filter bar |
| `event-preview.php` | Homepage events section |
| `leisure-card.php` | Lazer archive grid |
| `leisure-filters.php` | Lazer archive filter bar (desktop + mobile) |
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

## Homepage Hero

The front-page hero is a full‑bleed cinematic image with overlaid text (the old green panel + separate right‑hand image card / `.hero-layout` grid has been removed):

- **Assets**: committed project files output in `front-page.php` via `get_template_directory_uri()` inside a responsive `<picture>` in `.hero-background`. Desktop fallback `<img>`: `conexaobr_Hero_image.png` (2057×764). Mobile sources (≤768px): `conexaobr_Hero_image_mobile.webp` (1080×600) with PNG fallback (1683×935) — both 1.8:1. Resolves identically in local and production.
- **Sizing model** (one source of truth — the section controls dimensions):
  - Desktop (≥769px): `.hero-section { aspect-ratio: 3.71 / 1 }`; the image fills it via `object-fit: cover` / `object-position: center`; content vertically centered by a flex `.site-container`. Without featured sponsors the copy is single-column (`max-width: 640px`); with them, `.hero-content--with-sponsors` becomes a two-column grid — hero copy left in `.hero-copy` (~55–60%, slightly reduced `clamp()` type scale), Featured Apoiadores carousel right in `.hero-sponsors` on a subtle translucent green glass surface (tablet tightens the ratio/gap).
  - Mobile (≤768px): `aspect-ratio: auto; min-height: calc(100vw / 1.8)` — the hero is never shorter than the mobile image's own 1.8:1 proportion and grows naturally with its content (no fixed pixel heights, nothing clipped). Copy is bottom-anchored over the photo (`align-items: flex-end`), image crop biased via `object-position: 38% center`.
  - The `width`/`height` attributes on each `<source>`/`<img>` carry truthful intrinsic metadata only; CSS fully determines the rendered box, so they never stretch or size the image.
- **Text safety**: a subtle left‑side green gradient (`.hero-content::before`, transparent after ~60–70%) darkens only the text‑safe zone; the castle/family/flags on the right stay unobscured. The right-hand sponsor carousel sits on its own light-touch glass surface (`.hero-sponsors`) so label/tiles stay legible over the photo without hiding it.
- **Accessibility**: decorative `<img>` (`alt=""`, `aria-hidden`); `<section aria-label>`.
- **Performance**: `loading="eager"` + `fetchpriority="high"` (LCP).
- **Not in Customizer**: the `conexao_hero_image` Media control was removed; only `conexao_hero_title`/`conexao_hero_subtitle` are customisable.
