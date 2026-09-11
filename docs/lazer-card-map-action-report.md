# Lazer Card "Ver no mapa" Map Action — Implementation Report

**Feature**: Phase 3D — Secondary map action on every Lazer card  
**Date**: 2026-01-09  
**Status**: **LAZER CARD MAP ACTION PASSED**

---

## 1. Current Map URL Architecture

Map URLs for the Lazer card are resolved by the existing canonical helper
`conexao_leisure_map_url( $post_id )` (theme `functions.php`, line 2011).

**Resolution priority** (unchanged by this feature):

1. `_leisure_map_url` meta field — existing canonical map link, never replaced.
2. Deterministic Google Maps search URL derived from `_leisure_address`
   (+ town/county) when a verified address exists.
3. Fallback: deterministic Google Maps search URL derived from
   `title + town + county` — only when at least one location signal exists
   beyond the title (a bare title is never enough).

The derived URL uses the official Google Maps URL scheme:
`https://www.google.com/maps/search/?api=1&query=<address>` — no API key, no
geocoding, no remote requests, no embed. Identical data always produces an
identical URL, generated at render time from local meta/terms only.

The card template calls the helper defensively with a `function_exists()`
guard and treats an empty return as "no map URL available".

---

## 2. Implementation Files Changed

| File | Change |
|---|---|
| `wp-content/themes/conexao-br-irlanda/template-parts/leisure-card.php` | Added `$leisure_map_url` resolution; wrapped existing + new CTA in `.leisure-card-actions`; added conditional "Ver no mapa" link |
| `wp-content/themes/conexao-br-irlanda/assets/css/leisure.css` | Added `.leisure-card-actions` container (flex, wraps) and `.leisure-card-cta--map` bordered secondary style |
| `wp-content/themes/conexao-br-irlanda/assets/css/dark-mode.css` | Added dark-mode rules for `.leisure-card-cta--map` and its hover state |
| `wp-content/themes/conexao-br-irlanda/tests/test-leisure-card-map-action.php` | New test (35 assertions, A–J) |
| `docs/lazer-card-map-action-report.md` | This report |

No changes to: `functions.php`, `inc/seo.php`, data model, single-leisure.php,
filters, or any plugin. No new libraries, icon systems, or remote services.

---

## 3. Leisure Records With / Without Map URLs

Measured against the local production-mirror database (`wp_posts`):

| Metric | Count |
|---|---|
| Total published leisure records | **245** |
| Records with a stored `_leisure_map_url` | **0** |
| Records with a county term (`conexao_county`) | **245** (all) |
| Records with a town (`_leisure_town`) | **240** |
| Records with a verified address (`_leisure_address`) | **9** |
| Records with sufficient location data for a generated map URL | **245** (all have at least a county) |
| Records without any map URL | **0** |

Because every published leisure record has at least a county,
`conexao_leisure_map_url()` returns a valid Google Maps search URL for **all
245** records. The "no map URL" code path is therefore defensive — it is
exercised by the tests but not by current production data.

---

## 4. Primary CTA Preservation

The existing primary CTA logic is **untouched** and remains authoritative:

- External record + official website → `Ver site oficial` (external-link icon, `rel="noopener"`)
- External record without official website (e.g. Discover Ireland only) → `Ver mais` (arrow icon)
- Internal record (`_leisure_internal_page` flag) → `Ver local` (arrow icon)

Verified by tests C, D, E: the primary CTA label, icon, and `rel` attributes
are identical before and after the change. The original `<a class="leisure-card-cta">`
markup is preserved verbatim; only its wrapping element changed from the card
body directly to the new `.leisure-card-actions` container.

---

## 5. Map CTA Implementation

---

## 6. Responsive Behavior

- **Desktop**: `.leisure-card-actions` is a flex row with `flex-wrap: wrap`
  and `gap: 10px` — primary and map CTAs sit side by side with consistent
  height and clear separation.
- **Mobile**: when space is insufficient, the container wraps gracefully.
  No horizontal overflow, no forced font-size reduction. The bordered map
  pill and text primary CTA remain individually tappable.

---

## 7. Accessibility

| Check | Status |
|---|---|
| Both actions are real `<a>` links with valid `href` | PASS |
| Keyboard navigation reaches both actions | PASS (native links) |
| Focus state visible | PASS (inherits existing `--focus-ring`) |
| Accessible names unique and understandable | PASS (`aria-label` on map CTA) |
| SVG icons decorative (`aria-hidden="true"`) | PASS |
| No duplicate confusing labels | PASS |
| Sufficient spacing between adjacent targets | PASS (`gap: 10px`) |
| Mobile tap targets usable | PASS |
| No existing accessibility attributes removed | PASS |

---

## 8. Dark Mode

Dark-mode rules added to `dark-mode.css` for `.leisure-card-cta--map`:

- Border uses `var(--color-border)`; text/icon uses dark-adjusted green
  `var(--conexao-primary-text)` (`#3ab875`, 5.94:1 on dark — WCAG AA pass).
- Hover: border → `var(--conexao-primary-text)`, text → white, no underline
  (`.leisure-card-cta--map:hover` overrides the base underline).

The primary CTA continues to use the existing dark-mode card-cta rules
(filled `var(--color-primary-50)` background).

---

## 9. Internal / External Behavior

| Type | Primary CTA | Map CTA | Image/Card Link |
|---|---|---|---|
| External (official site) | `Ver site oficial` → external URL | `Ver no mapa` → Google Maps | Image → external URL |
| External (Discover Ireland) | `Ver mais` → DI URL | `Ver no mapa` → Google Maps | Image → DI URL |
| Internal (`_leisure_internal_page`) | `Ver local` → `/lazer/{slug}/` | `Ver no mapa` → Google Maps | Image → internal page |

The map action is **not** dependent on internal/external classification. Image/
title link behavior is completely unchanged (tests C, D, G).

---

## 10. Performance / Network Safety

| Concern | Status |
|---|---|
| No render-time remote requests | PASS |
| No geocoding at render time | PASS |
| No external API calls per card | PASS |
| No per-card query overhead | PASS (helper reuses loaded meta/terms) |
| Zero meaningful added query cost | PASS |

---

## 11. Tests

**New test**: `test-leisure-card-map-action.php` — 35 assertions, all passing.

| Group | Assertions | Status |
|---|---|---|
| A. Card with valid map URL | 3 | PASS |
| B. Card without map URL | 4 | PASS |
| C. External card (official website) | 3 | PASS |
| D. Internal card | 2 | PASS |
| E. Primary CTA unchanged | 4 | PASS |
| F. Expected Google Maps URL | 3 | PASS |
| G. Image/card link intact | 3 | PASS |
| H. External-link attributes | 2 | PASS |
| I. Accessibility markup | 6 | PASS |
| J. Dark-mode styling hooks | 4 | PASS |
| Cleanup | 1 | PASS |

---

## 12. Regression Results

| Test | Result |
|---|---|
| `test-leisure-attribute-normalization.php` | 22 passed, 0 failed |
| `test-leisure-multiselect-filters.php` | 62 passed, 0 failed |
| `test-leisure-related-events.php` | 19 passed, 0 failed |
| `test-sponsor-archive-ordering.php` | 15 passed, 0 failed |
| `test-event-location-filters.php` | 45 passed, 0 failed |

Live `/lazer/`: every card on the archive, county-filter, and category-filter
views renders the new `Ver no mapa` action with a valid Google Maps `href`.
No JavaScript, filter, or pagination behavior changed.

---

## 13. Known Limitations

1. **Map URL is a search, not a pin.** Uses `?api=1&query=<search>` — matches
   single-leisure.php, requires no API key.
2. **All current records have a map URL.** The "no map URL" path is defensive
   and tested but not exercised by current data.
3. **No external HTTP 200 validation.** Validation is limited to URL generation
   correctness and `href` integrity, not external service status.

---

## Final Classification

**LAZER CARD MAP ACTION PASSED**

Every Lazer card with a valid map URL (all 245 published records) has a
"Ver no mapa" action beside the unchanged primary CTA. The action opens the
existing Google Maps URL, preserves all existing behavior, and passes all new
and existing tests with zero regressions.


The new "Ver no mapa" action is a real `<a>` element rendered **only when**
`$leisure_map_url` is non-empty:

```html
<a href="https://www.google.com/maps/search/?api=1&query=..."
   class="leisure-card-cta leisure-card-cta--map"
   target="_blank" rel="noopener noreferrer"
   aria-label="Ver localização de {title} no mapa (abre em nova aba)">
  Ver no mapa
  <svg aria-hidden="true"> ...pin icon... </svg>
</a>
```

- **Icon**: reuses the exact location-pin SVG already used in the card's
  county/town detail line — no new icon library.
- **Link behavior**: `target="_blank" rel="noopener noreferrer"`, matching the
  existing single-leisure.php map-link convention.
- **Accessible name**: visible text "Ver no mapa" plus a descriptive
  `aria-label` naming the action and the new-tab behavior. The icon is
  decorative (`aria-hidden="true"`).
- **Hierarchy**: visually secondary (bordered pill) vs. the primary
  text-only CTA, communicating "view location" vs. "visit/open".
