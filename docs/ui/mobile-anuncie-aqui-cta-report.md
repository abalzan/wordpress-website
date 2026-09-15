# Mobile "Anuncie Aqui" CTA (header → mobile drawer)

## Summary

The existing **"Anuncie Aqui"** conversion action is now part of the mobile-menu
experience. It sits **after the primary navigation list and before the social
footer** inside the existing side drawer, in the same position the task
recommended. It keeps the same destination (`/anuncie/`), the same label, the
same upload icon and the same visual language as the desktop `.header-cta`
(accent fill, white label, pill radius), re-proportioned for the drawer rather
than copied at desktop dimensions.

This is a navigation/UX change only. The desktop header, the Anuncie page, the
primary navigation, the mobile search, the social links and the menu open/close
behaviour are unchanged.

Verified live against the local site (`http://localhost:8080`, Docker
`wordpress` + MySQL) with headless Chromium (Playwright): real DOM, real
computed styles, real interactions.

## Files Changed

| File | Change |
|---|---|
| `wp-content/themes/conexao-br-irlanda/header.php` | New `.mobile-menu-cta-wrap` block rendered between `</nav>` (`.mobile-menu-nav`) and `.mobile-menu-footer`. Link `href` built with `home_url( '/anuncie/' )`, label via `esc_html_e( 'Anuncie Aqui', … )`, SVG icon `aria-hidden="true"`. |
| `wp-content/themes/conexao-br-irlanda/assets/css/header-nav.css` | (a) `.mobile-menu-cta` added to the **existing** `.header-cta` base rule (selector list only — no declaration changed); (b) new *"Mobile Menu: Anuncie Aqui CTA"* block (`.mobile-menu-cta-wrap`, `.mobile-menu-cta`, `:hover`/`:active`); (c) `.mobile-menu-cta:focus-visible` added to the existing focus-visible list; (d) `.mobile-menu-cta` added to the reduced-motion transition-suppression list. |
| `wp-content/themes/conexao-br-irlanda/assets/css/dark-mode.css` | One dark-scoped rule pinning the drawer CTA label to `var(--conexao-white)` (see *Dark-mode fix*). |
| `wp-content/themes/conexao-br-irlanda/assets/css/design-system.css` | `.mobile-menu-cta` added to the **existing** shared button-typography group (`.btn, .header-cta, …`). |
| `docs/ui/mobile-anuncie-aqui-cta-report.md` | This report. |

**Not changed:** `assets/js/main.js` (no JS change needed), the `/anuncie/` page,
desktop header markup, `wp_nav_menu` structure, mobile search markup/logic,
social links, dark-mode tokens, events/lazer/filter code, any other template.

## Implementation Approach

1. **Reuse, don't duplicate.** The drawer CTA reuses the desktop CTA's rule set:
   `.header-cta, .mobile-menu-cta { … }` in `header-nav.css` is the single
   definition (display, accent fill, white label, gap, pill radius, 44px+
   target, nowrap, transition). Only *two* mobile-specific rules are added
   (full-width + centred + 52px; hover/active without the desktop's lift). No
   second CTA component and no new colour system:
   `design-system.css` already owns the shared button typography, so
   `.mobile-menu-cta` was added to that group too.
2. **A distinct class is required.** The desktop CTA is `display: none` at
   ≤1024px (rules in the tablet and mobile blocks). The drawer instance
   therefore uses its own class so those hide rules cannot apply, while still
   sharing the desktop visual language.
3. **Placed outside the scroll region.** `.mobile-menu-nav` is
   `flex: 1; overflow-y: auto`; the wrapper is a `flex-shrink: 0` sibling placed
   *after* it and *before* `.mobile-menu-footer`. The CTA therefore never
   scrolls out of reach, never overlaps scrolling nav content, and is **not**
   fixed/floating — it simply participates in the drawer's flex column.
4. **No JavaScript change.** The existing focus trap enumerates
   `menuOverlay.querySelectorAll('a[href], button…')`, so the new link is
   trapped/tabbed automatically; the drawer overlay is `display: none` unless
   `.active`, so the link is neither focusable nor visible while the drawer is
   closed (and never on desktop).

## Exact Mobile-Menu Position

DOM order inside `.mobile-menu-content` (verified in the rendered HTML):

```
.mobile-menu-content
├── .mobile-menu-header        (title "Menu" + close button)      flex-shrink: 0
├── .mobile-menu-search        (search field + submit)            flex-shrink: 0
├── nav.mobile-menu-nav        (9 primary links, overflow-y:auto) flex: 1
├── .mobile-menu-cta-wrap      ← NEW                              flex-shrink: 0
│   └── a.mobile-menu-cta  href="/anuncie/"  "Anuncie Aqui"
└── .mobile-menu-footer        (Instagram + WhatsApp)             flex-shrink: 0
```

Measured: `.mobile-menu-cta` is inside `.mobile-menu-overlay` and **outside**
`.mobile-menu-nav`; its wrapper precedes `.mobile-menu-footer` in document order
(`compareDocumentPosition` → `DOCUMENT_POSITION_FOLLOWING` for both relations).

Nav-scroll invariant (measured at 390 × 844): `.mobile-menu-nav` holds 587px of
content in a 465px region (9 links, `Início … Contato`), so it scrolls
internally. Scrolling it to the end reveals the last link while the CTA's
bounding box stays exactly at y 655–707 — the CTA does not move with the nav
scroll and is never clipped by it.

Visual separation: `.mobile-menu-cta-wrap` has `padding: var(--space-5)` (24px)
with a `border-top: 1px solid var(--color-border)` above it, and the existing
`.mobile-menu-footer` border-top below it — so the CTA reads as its own
primary-action band between the nav list and the social row rather than as
another menu item.

## Responsive Behavior (measured, headless Chromium)

| Viewport | Desktop `header .header-cta` | Hamburger | Drawer CTA box (w × h) | CTA vertical position | H-scroll |
|---|---|---|---|---|---|
| 320 × 568 | hidden | visible | 234 × 52 | y 379–431 | none |
| 375 × 667 | hidden | visible | 282 × 52 | y 478–530 | none |
| 390 × 844 | hidden | visible | 295 × 52 | y 655–707 | none |
| 430 × 932 | hidden | visible | 330 × 52 | y 743–795 | none |
| 768 × 1024 | hidden | visible | 372 × 52 | y 835–887 | none |
| 1024 × 768 | hidden | visible | 372 × 52 | y 579–631 | none |
| 1440 × 900 | **visible, unchanged** | hidden | 0 (overlay `display:none`) | — | none |

- Desktop (>1024px): the drawer is not rendered (`rect` width 0), so the mobile
  CTA is **not** an additional visible desktop element — only the existing
  desktop CTA exists.
- 769–1024px (tablet) and ≤768px (phone): the desktop CTA is hidden by the
  pre-existing rules and the drawer CTA is the only "Anuncie Aqui" affordance.
- The CTA is visible **without scrolling** inside the drawer at every tested
  size (it sits above the social footer). On an ultra-short viewport
  (320 × 400) it stays reachable: fully visible at y 211–263 after scrolling the
  drawer's own scroll container.
- No horizontal scrolling at any width
  (`document.documentElement.scrollWidth <= innerWidth`).

## Accessibility Checks

| Check | Result |
|---|---|
| Link semantics | `<a href="/anuncie/">` — a real link; no `role`, no `tabindex`, no `aria-controls`, never a `<button>`. |
| Accessible name | `"Anuncie Aqui"` from the visible label; the SVG is `aria-hidden="true"` and is not the only content. |
| Keyboard reachable | Yes — drawer tab order: close → search field → search submit → 9 nav links → **CTA** → social links. |
| Visible focus | `:focus-visible` matches; computed `outline: 3px solid rgb(246,139,31)` (the shared `--focus-ring` token) with `outline-offset: 2px`. |
| Focus trap | Covered by the existing trap (the link is a focusable descendant of `#mobile-menu`); first/last wrap behaviour still works. |
| Touch target | 52px tall × 233–372px wide (≥ 44 × 44). |
| Reading order | DOM order == visual order (search → nav → CTA → social). |
| No text loss on interaction | Label stays visible and white in base / hover / active / focus states, light and dark. |
| Contrast (measured, both themes) | Label on fill: **2.43:1** base, **3.73:1** hover/active. CTA fill vs drawer surface (dark): **6.77:1**. Focus ring: 2.43:1 on the light drawer surface, 6.77:1 on the dark one. |
| Reduced motion | `prefers-reduced-motion: reduce` → computed `transition-duration: 0s` on the CTA. |

**On the 2.43:1 label contrast:** that is the site's existing brand pairing for
the accent CTA — the desktop `.header-cta` in light mode renders exactly the
same `#ffffff` on `#F68B1F`, as does `.btn-accent`. It is therefore *inherited,
not introduced*, and identical across the two CTA instances. Raising it (darker
fill, or dark text on orange) would be a site-wide brand decision affecting every
accent CTA and the desktop header, both explicitly excluded from this task. It is
recorded here as a known limitation rather than silently reported as passing.

## Light / Dark Mode

The component is token-driven — `--color-accent`, `--color-accent-dark`,
`--conexao-white`, `--color-surface`, `--color-border`, `--space-*`,
`--radius-full`, `--focus-ring` — so dark mode is inherited from the theme token
layer (`dark-mode.css` remaps those tokens at `[data-theme="dark"]`). No new
colour literals were introduced.

Measured after the change (390 × 844, drawer open):

| Theme | State | Label | Fill | Contrast |
|---|---|---|---|---|
| light | base | `#ffffff` | `#F68B1F` | 2.43:1 |
| light | hover / active | `#ffffff` | `#c46f18` | 3.73:1 |
| dark | base | `#ffffff` | `#F68B1F` | 2.43:1 |
| dark | hover / active | `#ffffff` | `#c46f18` | 3.73:1 |

Wrapper (measured): light `background #ffffff`, border-top from
`--color-border`; dark `background #16202b`, border-top `#3a4a5a` — both from
tokens, so no dark-mode rule is needed for the wrapper itself.

### Dark-mode fix (why one extra rule was necessary)

Without a dark-scoped pin, the generic dark anchor rules in `dark-mode.css`
(`[data-theme="dark"] a` = specificity 0,1,1 and `a:hover` = 0,2,1) outrank the
CTA's class rules from `header-nav.css` (0,1,0). Measured **before** the fix, in
the drawer: label `#0E6B3A` (green) on `#F68B1F` = **2.71:1**, and on hover the
label became `#f59e42` (orange) on `#c46f18` = **1.52:1** — the label effectively
disappeared, the exact failure mode of the earlier hero-CTA hover report. The
fix mirrors the pattern this codebase already uses for those hero CTAs
(`[data-theme="dark"] .hero-ctas .btn-primary { color: var(--conexao-white); }`):

```css
[data-theme="dark"] .mobile-menu-cta,
[data-theme="dark"] .mobile-menu-cta:hover,
[data-theme="dark"] .mobile-menu-cta:active,
[data-theme="dark"] .mobile-menu-cta:focus-visible {
  color: var(--conexao-white);
}
```

It is scoped to the **drawer CTA only**. The desktop `.header-cta` is
deliberately left untouched (see *Out-of-scope observations*), so this task
changes nothing about the desktop CTA in any theme.

## Functional Tests

Run against the live local site with headless Chromium (real clicks/keys):

| # | Test | Result |
|---|---|---|
| 1 | Mobile menu opens | PASS — `.mobile-menu-overlay.active`, `aria-hidden="false"`, toggle `aria-expanded="true"` |
| 2 | CTA is clearly visible in the drawer | PASS — 52px-high accent pill directly above the social footer, visible without scrolling |
| 3 | CTA points to `/anuncie/` | PASS — `href="http://localhost:8080/anuncie/"` (= `home_url( '/anuncie/' )`) |
| 4 | Tapping the CTA navigates | PASS — lands on `/anuncie/`, document title `Anuncie Aqui – Conexão BR` |
| 5 | Menu closes normally | PASS — close button, `Escape` (with focus returning to the hamburger) and backdrop click all close it |
| 6 | Drawer search still works | PASS — `dublin` + submit → `/?s=dublin` |
| 7 | Navigation links still work | PASS — 9 links intact (`Início … Contato`); nav scrolls (587px content in a 465px region), the last link becomes visible after scrolling, and first link (`Início`) → `/` |
| 8 | Social links still work | PASS — Instagram + WhatsApp, `target="_blank"`, `rel="noopener noreferrer"`, `aria-label` preserved |
| 9 | CTA present on every template | PASS — drawer CTA with `href=/anuncie/` found on `/`, `/eventos/`, `/lazer/`, `/blog/`, `/guias/`, `/cursos/`, `/empregos/`, `/apoiadores/` |
| 10 | Reduced motion | PASS — `transition-duration: 0s` |

## Desktop Regression Tests

| Check | Result |
|---|---|
| Desktop CTA still rendered once | PASS — single `header .header-cta` in the markup; `href`/label unchanged |
| Desktop CTA computed style | PASS — `display:flex`, `background #F68B1F`, light-mode `color #ffffff`, `padding 8px 12px`, `font-size 14px`, `min-height 44px`, `border-radius 9999px` = exactly the pre-change values |
| Desktop CTA visibility by width | PASS — visible at 1440/1100/1025, hidden at ≤1024 (unchanged rules) |
| No duplicate CTA on desktop | PASS — the drawer is `display:none` above 1024px (`rect` width 0) and its contents are absent from the desktop tab order |
| Desktop tab order | PASS — `… theme-toggle → search field → search submit → A.header-cta → hero buttons …`; no drawer element reachable on desktop |
| Shared-style adjustment risk | PASS — the only change to the pre-existing `.header-cta` rule is the **selector list** (`.header-cta, .mobile-menu-cta`); no declaration was altered, so desktop rendering is unchanged by construction |
| JS / behaviour | PASS — no JS changes; menu open/close, focus trap, search and submenu toggles untouched |
| Content/data layer | PASS — no changes to events, lazer, filters, taxonomies, routes or any other template |

## Out-of-scope Observations (unchanged by this task)

1. **Desktop `.header-cta` in dark mode is mis-coloured** by the same generic
   dark anchor rules: measured `#0E6B3A` on `#F68B1F` (2.71:1) and, on hover,
   `#f59e42` on `#c46f18` (1.52:1) — i.e. the desktop CTA label is hard to read
   on hover in dark mode **before and after this change**. Left untouched
   because the brief forbids changing the desktop CTA; the one-line follow-up is
   to add `.header-cta` (and its `:hover`/`:active`) to the dark-mode rule added
   above, which would make both instances identical in both themes.
2. **`--focus-ring` uses the accent orange**, measured 2.43:1 against the light
   drawer surface — below WCAG 1.4.11's 3:1 for focus indicators (6.77:1 on the
   dark surface). It is the site-wide token shared by every control; unchanged.
3. **Base label contrast (2.43:1)** is the pre-existing brand pairing described
   under *Accessibility Checks*; unchanged and inherited.

## Test Method

```bash
docker compose up -d          # site at http://localhost:8080
# headless Chromium (Playwright) against the live site:
#   - rendered-HTML assertions (markup order, href, accessible name)
#   - viewport sweep 320/375/390/430/768/1024/1440
#   - drawer interactions (open, Escape, backdrop, CTA click, search, nav, social)
#   - computed styles + contrast maths, light and dark, with transitions settled
```

Note: the theme's CSS is concatenated/minified by Jetpack into a single
`_static/` stylesheet, so the checks were run against the served CSS (verified
to contain the new rules) rather than the source files alone.

## Status

**PASSED** — "Anuncie Aqui" is discoverable and tappable in the mobile menu,
links to `/anuncie/`, is visually prominent yet consistent with the design
system and `.header-cta`, works in light and dark mode, is keyboard/screen-reader
accessible with a visible focus state, and no unrelated functionality regressed.

Known limitations (pre-existing, documented above, not introduced here): the
accent-fill label contrast is 2.43:1 because it inherits the brand's
white-on-orange CTA pairing, and the desktop `.header-cta` keeps its own
pre-existing dark-mode colour bug (out of scope by instruction).

