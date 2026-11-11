# Homepage Hero "Ver Eventos" CTA Hover Fix — Report

**Date:** 2026-11-09
**Scope:** Homepage hero secondary CTA (`Ver Eventos`) hover readability.
**Classification:** **HERO EVENTS CTA HOVER FIX PASSED**

---

## 1. Root cause

The homepage hero renders (front-page.php:119-130):

```html
<div class="hero-ctas">
  <a href="..." class="btn btn-primary">…Explorar Guias…</a>
  <a href="..." class="btn btn-outline">Ver Eventos</a>
</div>
```

The **global** `.btn-outline:hover` rule sets the hover fill to white and the text
to the brand green:

```css
/* main.css:1192-1197 (mirrored in design-system.css:290-295) */
.btn-outline:hover {
  background: var(--conexao-white);   /* #ffffff */
  color: var(--conexao-primary);      /* #0E6B3A  */
  border-color: var(--conexao-white);
  transform: translateY(-2px);
}
```

That pair (green on white) is readable — **6.6:1** — and is the intended design
behavior in **light mode**.

The bug only reproduces in **dark mode**. The dark theme pins the hero CTAs to
white text so they read over the dark-green photographic gradient:

```css
/* dark-mode.css:1416-1419 */
[data-theme="dark"] .hero-ctas .btn-primary,
[data-theme="dark"] .hero-ctas .btn-outline {
  color: var(--conexao-white);
}
```

This rule has specificity **(0,3,0)** — higher than `.btn-outline:hover` **(0,2,0)**.
CSS cascade is decided by specificity + order, *not* by state, so this rule keeps
winning for `color` **even while the button is hovered**. On hover the element
therefore receives:

- `background` → `#ffffff` (from `.btn-outline:hover`, no competing rule)
- `color` → `#ffffff` (pinned by the dark-mode hero rule, which outranks the
  hover rule)

→ white text on a white fill (contrast **1.0:1**), i.e. the reported bug.

**Browser-confirmed on production** (https://conexaobr.ie/, Chromium 1234,
`prefers-color-scheme: dark`):

| State | Computed color | Computed background | Contrast |
|---|---|---|---|
| `Ver Eventos` hover | `rgb(255,255,255)` | `rgb(255,255,255)` | **1.0 (FAIL)** |

Pixel audit of the rendered `Ver Eventos` text zone while hovered: **100 % white,
0 % glyph pixels** — the label is invisible.

Light mode was already correct before the fix (hover = `rgb(14,107,58)` on
white, 6.6:1).

## 2. CSS rule responsible

`dark-mode.css:1416-1419` — `[data-theme="dark"] .hero-ctas .btn-outline { color: var(--conexao-white); }`.
Its specificity exceeds the global `.btn-outline:hover` color declaration and
silently overrides it on hover.

## 3. Exact fix

One rule appended directly beneath the culprit rule in `dark-mode.css`:

```css
/* Hero secondary CTA hover — the rule above pins white text on the hero's
   dark green gradient, but its specificity (0,3,0) also beats the global
   .btn-outline:hover (0,2,0), so on hover the text stays white over the
   white hover background → unreadable. Restore the shared outline idiom
   (green text on the white hover fill) for .hero-ctas only. */
[data-theme="dark"] .hero-ctas .btn-outline:hover {
  color: var(--color-primary);
}
```

Specificity **(0,4,0)** now outranks both the dark-mode hero pin **(0,3,0)** and
the global hover rule **(0,2,0)** for `color` on hover.

`--color-primary` resolves to `#0E6B3A` in **both** modes: the alias
`--color-primary: var(--conexao-primary)` (design-system.css:12) and the
customizer re-declares `--conexao-primary` on `:root` *after* `dark-mode.css`
(functions.php `conexao_customizer_css()`), overriding dark mode's own
`--conexao-primary: #3ab875` — a behavior explicitly documented in
`dark-mode.css:16-23`. This is the same green the global `.btn-outline:hover`
uses, so the hover pair is byte-identical to every other enabled outline button
(**Option A: light background, dark-green text**, matching the design system —
e.g. `.btn-outline-dark:hover` uses `--color-primary-50` + `--color-primary`).

No `!important`, no new tokens, no hard-coded colors, no JavaScript, no markup
changes.

## 4. Selector scope

`[data-theme="dark"] .hero-ctas .btn-outline:hover` — dark mode only, `.hero-ctas`
only. `.hero-ctas` exists solely on the homepage hero (front-page.php:119-130).
A template grep confirms `.btn-outline` (plain variant) is used **only** for the
hero "Ver Eventos" link; the only other outline button on the site is the
different class `.btn-outline-dark` ("Nova busca", content-none.php:10), which is
not matched by this selector. No global `.btn-outline` rule was touched.

## 5. Light-mode result — **PASS**

No light-mode rule was added (the global hover behavior was already correct).
Browser-verified on local (repo CSS) and live, desktop/tablet/mobile:

- Default: text `rgb(255,255,255)`, transparent fill, border `rgba(255,255,255,0.5)` — unchanged.
- Hover: text `rgb(14,107,58)` on `rgb(255,255,255)` — **contrast 6.6:1** (WCAG AA ≥ 4.5:1).
- Pixel audit of the hovered text zone: 82.2 % white fill with 3.7 % green glyph
  pixels + antialiasing — label clearly rendered.

## 6. Dark-mode result — **PASS**

- Live (before fix): hover = white on white, **1.0:1** — reproducible bug (evidence above).
- Live with the exact fix rule injected ad hoc: hover = `rgb(14,107,58)` on
  `rgb(255,255,255)`, **6.6:1** — the fix works against production markup.
- Local (repo `dark-mode.css` served): hover = `rgb(14,107,58)` on
  `rgb(255,255,255)`, **6.6:1** at every viewport; pixel audit shows green glyphs
  on the white fill (identical to light mode).
- Default dark state (white text on the hero's dark gradient) is untouched.

## 7. Accessibility result — **PASS**

| Check | Result |
|---|---|
| Default text contrast | White on hero photo, unchanged from before the fix (pre-existing, intact). |
| Hover text contrast | `#0E6B3A` on `#FFFFFF` = **6.6:1** (WCAG AA) in both modes. |
| Focus text contrast | Focus state keeps the default colors (text `rgb(255,255,255)` on transparent fill); readable, unchanged. |
| Focus indicator | `:focus-visible` reached via Tab in both modes: **`rgb(246,139,31)` solid 3px** (dark) / **`rgb(255,255,255)` solid 3px** (light) with the existing 2px offset — ring retained, not removed. |
| Keyboard navigation | Tab reaches `Ver Eventos` (`reached = true`, `matches(':focus-visible') = true`) in live and local, both modes. |

No focus/style removal was needed for the fix, so keyboard visibility is
preserved.

## 8. Responsive result — **PASS**

Hover measured at 1280×800 (desktop), 820×1100 (tablet) and 390×844 (mobile),
both modes (local): **6.6:1 on all six combinations**. The change only adds a
color declaration — it cannot alter layout — and no layout/spacing rule was
touched. Screenshots confirm the two-column hero (copy + Featured Apoiadores
carousel) and CTA row render identically.

## 9. Unrelated `.btn-outline` regression check — **PASS**

- The only other outline button, `.btn-outline-dark` ("Nova busca" on the
  no-results search page), was hovered in dark mode on local: text
  `rgb(14,107,58)` on `rgb(20,43,32)` (`--color-primary` over
  `--color-primary-50`) — exactly its pre-existing `dark-mode.css:1426-1430`
  hover behavior, unchanged by this fix.
- No other `.btn-outline` elements exist in the theme templates (grep: only
  front-page.php:127 uses the plain class).
- No global `.btn-outline` rule was modified, so every other button (including
  `.btn-primary`/`.btn-green`/`.btn-accent`) is unaffected.

## 10. Tests / browser verification

| Item | Result | Evidence |
|---|---|---|
| Live site — dark hover (bug repro) | **PASS (bug reproduced)** | Computed white-on-white, contrast 1.0; hovered text zone 100 % white. |
| Live site + injected fix — dark hover | **PASS** | Contrast 6.6; green glyph pixels present. |
| Live site — light hover | **PASS** | Contrast 6.6 (already correct). |
| Local repo CSS — dark & light × desktop/tablet/mobile hover | **PASS** | 6.6:1 in 6/6 combinations. |
| Local repo CSS — default states (both modes) | **PASS** | White text / transparent fill / 0.5-white border, unchanged. |
| Focus (Tab, `:focus-visible`, outline) | **PASS** | Ring present and visible; defaults preserved. |
| Primary CTA regression | **PASS** | Identical everywhere: default white on `rgb(246,139,31)`, hover white on `rgb(196,111,24)`. |
| `.btn-outline-dark` regression | **PASS** | Unchanged pre-existing hover behavior. |
| Theme PHP test suites (docker) | **PASS** | sponsor-archive-ordering 15/0 · event-location-filters 45/0 · guide-breadcrumb-filter 36/0 · leisure-attribute-normalization 22/0 · leisure-card-map-action 44/0 · leisure-multiselect-filters 62/0 · leisure-related-events 19/0 → **243 passed, 0 failed**. |
| Frontend/visual automated suite | **NOT TESTABLE** | The theme has no automated CSS/browser test harness (assets are plain files); browser QA was performed manually with Playwright (evidence above). |
| Homepage / hero / carousel / navigation / CTA links | **PASS** | Markup untouched; screenshots at 3 viewports × 2 modes; links still point to `/guias/` (via `conexao_get_guides_archive_url()`) and `/eventos/`; no JS added. |

Browser artifacts (screenshots, 3 viewports × 2 modes, plus bug/fix pairs and
precise text-zone crops): `/tmp/qa_hero_fix_artifacts/`.

## Changes

Single file, one addition:

```
wp-content/themes/conexao-br-irlanda/assets/css/dark-mode.css
+[data-theme="dark"] .hero-ctas .btn-outline:hover { color: var(--color-primary); }
```

## Final classification

**HERO EVENTS CTA HOVER FIX PASSED**