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
