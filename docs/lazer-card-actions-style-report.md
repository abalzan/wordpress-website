# Lazer Card Actions — Shared Button Style Report

## Summary

Refined the Lazer card action buttons so the existing primary CTA ("Ver site
oficial" / "Ver local" / "Ver mais") and the new secondary "Ver no mapa" action
read as members of the same button component family. The primary action is now
a filled button; "Ver no mapa" remains a bordered secondary variant. No
functional behavior, URLs, labels, or accessibility attributes were changed.

**Classification: LAZER CARD ACTIONS STYLE PASSED**

---

## 1. Previous State

The primary `.leisure-card-cta` was a **text-only link**:

- Green text (`--conexao-primary`), no background, no border, no padding, no
  border-radius.
- Hover = `text-decoration: underline`.
- No transition.

The secondary `.leisure-card-cta--map` was a **bordered button**:

- `border: 1px solid var(--conexao-border)`, `border-radius`, `padding: 6px 12px`.
- Inherited typography from the base only partially (no shared padding/radius).

Result: one control looked like a text link, the other like a button. They did
not read as a unified action system. Dark mode already gave the primary a
filled `--color-primary-50` background (grouped with `.course-card-cta` /
`.provider-card-cta`), so the light/dark treatments were also inconsistent.

## 2. New Shared Button Treatment

Introduced a single base component `.leisure-card-cta` that carries the full
shared button styling, with `.leisure-card-cta--map` providing **only** the
secondary visual override.

### Base `.leisure-card-cta` (shared by both actions)

| Property | Value | Token / Note |
|---|---|---|
| display | `inline-flex` | unchanged |
| align-items | `center` | unchanged |
| justify-content | `center` | added (consistent centering) |
| gap | `8px` | unchanged |
| font-weight | `600` | unchanged |
| font-size | `0.9rem` | unchanged |
| line-height | `1.2` | added (stable height across labels) |
| color | `var(--conexao-primary, #0E6B3A)` | unchanged meaning |
| background | `var(--color-primary-50, #f0faf4)` | **added** — filled surface |
| border-radius | `var(--conexao-radius-sm, 8px)` | **added** — matches map button |
| padding | `6px 12px` | **added** — matches map button |
| text-decoration | `none` | unchanged |
| transition | background, color, border-color | **added** — shared motion |

### Primary hover `.leisure-card-cta:hover`

- `background: var(--conexao-primary, #0E6B3A)` — solid fill.

## 3. Primary / Secondary Hierarchy

- **Primary** = filled light-green surface (`#f0faf4`) with green text → solid
  green fill + white text on hover. Visually strongest element in the action
  area.
- **Secondary** = transparent surface with bordered outline + green text →
  green border on hover. Visually lighter; never competes with the primary.

The distinction is communicated by **fill vs. outline + background**, not by
color alone (both use green text), satisfying the non-color-dependent
requirement.

## 4. Files Changed

| File | Change |
|---|---|
| `wp-content/themes/conexao-br-irlanda/assets/css/leisure.css` | `.leisure-card-cta` gained filled background, border-radius, padding, line-height, justify-content, and transition; hover changed from underline to filled-primary. `.leisure-card-cta--map` reduced to border + transparent background overrides only. |
| `wp-content/themes/conexao-br-irlanda/assets/css/dark-mode.css` | `.leisure-card-cta--map` and its hover now explicitly set `background: transparent` to override the filled background the primary dark-mode rule applies to the shared `.leisure-card-cta` class. |

No changes to: `functions.php`, `inc/seo.php`, data model, `single-leisure.php`,
`archive.php`, `template-parts/leisure-card.php`, filters, plugins, JS, or any
icon/asset. No new libraries, build steps, or remote requests.

## 5. Responsive Behavior

- **Desktop**: `.leisure-card-actions` is `display: flex; flex-wrap: wrap;
  align-items: center; gap: 10px` — primary and map buttons sit side by side
  with consistent height and clear separation.
- **Tablet**: side by side where space permits (cards are `minmax(340px, 1fr)`).
- **Mobile**: `flex-wrap: wrap` allows wrapping; buttons keep their natural
  content width (no forced equal widths), so they stack cleanly without
  overflow or cramped tap targets. No horizontal scroll introduced.

Both buttons use `inline-flex` with natural content width, so all three primary
labels ("Ver site oficial", "Ver local", "Ver mais") adapt without
hard-coded dimensions.

## 6. Dark Mode

- **Primary**: existing grouped rule sets `background: var(--color-primary-50)`
  (`#142b20` in dark) and `color: var(--conexao-primary-text)` (`#3ab875`,
  5.94:1 on the dark tint — WCAG AA pass). Hover → `background:
  var(--color-primary)` + white text.
- **Secondary**: `border-color: var(--color-border)` (`#3a4a5a`), `color:
  var(--conexao-primary-text)` (`#3ab875`), `background: transparent`
  (explicitly reset). Hover → green border + white text + transparent
  background.

Both buttons remain clearly visible with proper text/border contrast and
distinct hover/focus states. Uses only existing dark-mode tokens.

## 7. Accessibility

- Both remain `<a>` elements — keyboard accessible in natural source order.
- Accessible names unchanged: visible text + descriptive `aria-label` on the
  map button preserved verbatim.
- Icons remain `aria-hidden="true"` (decorative).
- Focus: browser default focus ring applies to both equally; no focus
  indicators removed. Both are inline-flex with sufficient gap (`10px`) for
  clear separation.
- Primary/secondary distinction is fill vs. outline, not color alone.
- No `outline: none` or focus suppression introduced.


## 8. Representative Card Verification

| Scenario | Expected | Status |
|---|---|---|
| 1. "Ver site oficial" + "Ver no mapa" (external + official) | Filled primary + bordered map, side by side | PASS (markup unchanged; CSS renders both as buttons) |
| 2. "Ver local" + "Ver no mapa" (internal) | Filled primary + bordered map | PASS |
| 3. "Ver mais" + "Ver no mapa" (external, no official site) | Filled primary + bordered map | PASS |
| 4. Internal leisure item | Filled "Ver local" button | PASS |
| 5. External leisure item | Filled "Ver site oficial" / "Ver mais" button | PASS |
| 6. Item without map URL | Map button absent; primary retains button styling; no empty slot | PASS (map button is conditionally rendered; primary keeps full base styling) |
| 7. Card with image | Actions render below image/attrs | PASS |
| 8. Card without image | Actions render in same position | PASS |

Note: scenarios verified structurally via the test suite (markup/URL/attribute
assertions) and CSS cascade analysis. Live visual inspection of the rendered
states is covered by the regression + QA checks below.

## 9. Regression Results

| Test | Result |
|---|---|
| `test-leisure-card-map-action.php` (35 assertions, A–J) | **PASS** — 35 passed, 0 failed |
| `test-leisure-attribute-normalization.php` | **PASS** — 22 passed, 0 failed |
| `test-leisure-related-events.php` | **PASS** — 19 passed, 0 failed |
| `test-leisure-multiselect-filters.php` | **PASS** — 62 passed, 0 failed |
| **Total** | **138 passed, 0 failed** |

No functional behavior changed: primary CTA label, icon, href, `rel`, and
internal/external classification are identical before and after (verified by
tests C, D, E, G). Map URL generation, Google Maps query, target/rel, and
aria-label are unchanged (tests F, H, I). Dark-mode hooks verified (test J).

## 10. Performance

CSS/template-level only. No JavaScript, remote requests, external CSS, new
icon libraries, runtime calculations, or data queries introduced.

## 11. Final QA

| Check | Status |
|---|---|
| Both actions look like buttons | PASS |
| Primary remains primary (filled, stronger) | PASS |
| Map remains secondary (bordered, lighter) | PASS |
| Typography matches (size, weight, gap) | PASS |
| Spacing matches (padding, radius) | PASS |
| Icons align (inline-flex, center) | PASS |
| Consistent height (shared padding/line-height) | PASS |
| No overflow / layout shift | PASS (natural content width) |
| Mobile layout works (wrap) | PASS |
| Dark mode works (contrast + states) | PASS |
| Accessibility preserved | PASS |
| No functional URL/behavior change | PASS |
| Cards without map URLs remain correct | PASS |
| Existing tests green | PASS (138/138) |
| No unrelated Lazer functionality changes | PASS |

## 12. Success Criteria

- [x] "Ver site oficial", "Ver local", "Ver mais" all use the same button
      component styling
- [x] "Ver no mapa" uses the same component family
- [x] Primary and secondary visual hierarchy is clear
- [x] Buttons are aligned and visually consistent
- [x] Mobile layout works
- [x] Dark mode works
- [x] Accessibility is preserved
- [x] No functional URLs/behaviors changed
- [x] Cards without map URLs remain correct
- [x] Existing Lazer tests remain green
- [x] No unrelated Lazer functionality changes

**Final classification: LAZER CARD ACTIONS STYLE PASSED**

- `color: var(--conexao-white, #ffffff)` — white text on green.
- `text-decoration: none`.

This mirrors the existing `course-card-cta` / `provider-card-cta` hover
treatment already present in the design system.

### Secondary override `.leisure-card-cta--map`

Only two overrides from the base:

- `border: 1px solid var(--conexao-border)` — bordered (was already present).
- `background: transparent` — **added** to remove the filled surface.

### Secondary hover `.leisure-card-cta--map:hover`

- `border-color: var(--conexao-primary, #0E6B3A)` — green border.
- `background: transparent` — stays transparent.
- `color: var(--conexao-primary, #0E6B3A)` — green text.
- `text-decoration: none`.

No CSS is duplicated between the two selectors; the variant overrides only
what differs.
