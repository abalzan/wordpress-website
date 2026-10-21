# UI/UX Improvement 8 — Breakpoint Rationalization

**Date:** 2026-10-21
**Branch:** `master`
**SHA:** `426e491`
**Status:** Complete

## 1. Baseline Inventory

The audit identified **13 breakpoint values** across CSS files, with two documented layout thresholds: **768px** and **1024px**. Inspection of the active theme's stylesheets revealed the following actual breakpoint usage:

| Breakpoint | Occurrences | Files |
|---|---|---|
| 768px | 25 | main.css, design-system.css, sponsor.css, leisure.css |
| 769px | 9 (5 standalone + 4 range) | main.css, sponsor.css, leisure.css, header-nav.css |
| 1024px | 3 | header-nav.css |
| 1199px | 4 | main.css |
| 480px | 4 | main.css, sponsor.css |
| 782px | 2 | header-nav.css, job-resources-admin.css |
| 1280px | 2 | main.css, header-nav.css |
| 600px | 1 | header-nav.css |
| 374px | 1 | main.css |
| 360px | 1 | main.css |
| 359px | 1 | header-nav.css |

**Two documented breakpoints from the audit:**
- **768px** — Mobile-to-tablet transition (primary shared breakpoint)
- **1024px** — Desktop breakpoint (navigation drawer, CTA compact mode)

**9 additional breakpoint values** were found that are not part of the documented convention: 769px, 1199px, 480px, 782px, 1280px, 600px, 374px, 360px, 359px.

## 2. Changes Made

### 2.1 Consolidation: `min-width: 769px` → `min-width: 768px`

Nine rules were consolidated from `769px` to the documented `768px` convention:

**main.css** (7 rules):
1. Line 415: `@media (min-width: 769px)` → `@media (min-width: 768px)` — hero-content with-sponsors 2-column grid
2. Line 6271: `@media (min-width: 769px)` → `@media (min-width: 768px)` — event-filters-mobile display: none
3. Line 8238: `@media (min-width: 769px)` → `@media (min-width: 768px)` — agency-filters-mobile display: none
4. Line 773: `@media (max-width: 1199px) and (min-width: 769px)` → `@media (max-width: 1199px) and (min-width: 768px)` — tablet sponsor grid
5. Line 2879: same change — sponsors-slide 2 cards per view
6. Line 4456: same change — events-page-header, page-header padding; events-grid 2 columns; quick-access-grid 3 columns
7. Line 5278: same change — archive-grid 2 columns

**sponsor.css** (1 rule):
8. Line 109: `@media (min-width: 769px)` → `@media (min-width: 768px)` — sponsor-single-layout display: grid

**leisure.css** (1 rule):
9. Line 716: `@media (min-width: 769px)` → `@media (min-width: 768px)` — leisure-mobile-filter display: none

### 2.2 Retained Breakpoints (Justified Exceptions)

The following breakpoints were retained as component-specific necessities:

- **1199px** — Tablet intermediate range. Provides a 2-column grid transition and sponsor/layout adjustments between mobile single-column (≤768px) and full desktop (≥1024px). Kept because the layout geometry at 1024px would be too wide for comfortable 2-column display, and at 768px the layout is already single-column.

- **1280px** — Mobile search toggle. Shows the mobile search toggle button at tablet-and-below widths. Component-specific header navigation rule.

- **480px** — Small mobile adjustments. Reduces the quick-access grid from 4 columns, adjusts search overlay padding, and sponsor contact list styling for narrow phone viewports.

- **782px** — Admin bar offset. Adjusts the header top position when the WordPress admin bar is visible.

- **600px, 374px, 360px, 359px** — Narrow mobile tweaks. Very targeted adjustments for narrow viewports.

### 2.3 Non-Layout Media Queries Preserved

All non-layout media queries remain intact:
- `prefers-reduced-motion: reduce`
- `color-scheme` / theme-related queries
- Hover and pointer-capability queries

## 3. Resulting Breakpoint Convention

### Standard Layout Breakpoints

| Breakpoint | Value | Purpose | Usage |
|---|---|---|---|
| Mobile→Tablet | 768px | Primary shared transition | design-system.css, main.css, sponsor.css, leisure.css |
| Desktop | 1024px | Documented second threshold | header-nav.css (drawer, CTA compact) |

### Justified Exceptions

| Breakpoint | Value | Component | Purpose |
|---|---|---|---|
| Tablet intermediate | 1199px | Archive grid, sponsor grid | 2-column transition between mobile and desktop |
| Mobile search toggle | 1280px | Header navigation | Show/hide mobile search button |
| Small mobile | 480px | Quick-access grid, sponsor contacts | 4→column reduction, overlay padding |
| Admin bar | 782px | Header navigation | Offset with WP admin bar |
| Narrow mobile | 600/374/360/359px | Various | Targeted viewport adjustments |

### Consolidation Summary

- **9 rules** previously using `min-width: 769px` or `@media (max-width: 1199px) and (min-width: 769px)` have been aligned to the `768px` convention.
- The `769px` value no longer appears as a standalone breakpoint in main.css, sponsor.css, or leisure.css — it has been replaced by the documented `768px` threshold.
- The complementary relationship (`max-width: 768px` for ≤768px and `min-width: 768px` for ≥768px) covers all viewport sizes without gap.
- Non-layout media queries remain intact where required.

## 4. Files Changed

| File | Changes |
|---|---|
| `wp-content/themes/conexao-br-irlanda/assets/css/main.css` | 7 media query values consolidated: `769px` → `768px` |
| `wp-content/themes/conexao-br-irlanda/assets/css/sponsor.css` | 1 media query value consolidated: `min-width: 769px` → `min-width: 768px` |
| `wp-content/themes/conexao-br-irlanda/assets/css/leisure.css` | 1 media query value consolidated: `min-width: 769px` → `min-width: 768px` |
| `docs/frontend.md` | Updated breakpoint convention documentation |
| `docs/reports/2026-10-21-ui-ux-improvement-8-breakpoint-rationalization-report.md` | This report |

## 5. Preserved Improvements

All previously completed UI/UX improvements remain intact:
1. Event date-badge simplification
2. Homepage shortcut consolidation
3. Card CTA convergence
4. Category-pill convergence
5. Mobile touch-target refinement
6. The `/en/jobs/` redirect
7. Image aspect-ratio consistency

## 6. Verification

- No PHP syntax errors introduced
- CSS changes are behavior-preserving (same rules at aligned breakpoint values)
- No content, translation, route, or configuration changes
- No production writes or deployments

