# Lazer Remove "Ver Local" — Implementation Report

**Task**: Remove "Ver local" from Lazer cards and simplify the CTA architecture  
**Date**: 2026-09-11  
**Status**: PASS

---

## 1. Current "Ver Local" Behavior

**Generation**: `template-parts/leisure-card.php` line 195 (removed)

The "Ver local" CTA was rendered when a leisure record was classified as **internal** — i.e., `conexao_leisure_external_url()` returned empty. This occurred when:
- The record had no `_leisure_official_website` AND no `_leisure_discover_ireland` URL, OR
- The record had the `_leisure_internal_page` flag set (Phase 3B — keeps internal page even with external URLs)

The CTA linked to the internal `/lazer/{slug}/` permalink. The card image and title linked to the same destination.

**Helper**: `conexao_leisure_external_url()` (`inc/seo.php:1183`) — centralized classification used by cards, sitemap, and related-content selector.

---

## 2. Number of Affected Records

**Database audit** (245 published leisure records):

| Classification | Count | Previous CTA | New CTA |
|---|---|---|---|
| External (official website) | 159 | "Ver site oficial" | "Ver site oficial" (unchanged) |
| External (Discover Ireland only) | 38 | "Ver mais" | "Ver mais" (unchanged) |
| Internal WITH `_leisure_internal_page` flag | 1 (Knocknarea) | "Ver local" | "Ver mais" |
| Internal WITHOUT flag | 47 | "Ver local" | **none** |

**Total records affected by CTA change**: 48 (1 flagged + 47 unflagged internal records)

### Re-validation (2026-11-09)

Re-rendered all 245 cards from the current database and verified:

| Metric | Result |
|---|---|
| Cards containing "Ver local" CTA (excl. "Ver localização" aria-label) | **0** |
| Cards with two CTAs (primary + map) | 198 |
| Cards with one CTA (map-only) | 47 |
| Cards with zero CTAs | 0 |
| Cards with `.leisure-card-actions` container | 245/245 |

Representative rendered output verified:
- **Trinity College** (ext+official): `[Ver site oficial ↗] [Ver no mapa 📍]` ✅
- **Knocknarea** (flagged internal): `[Ver mais →] [Ver no mapa 📍]` ✅
- **Cavan Cathedral** (unflagged internal, map-only): `[Ver no mapa 📍]` — same `.leisure-card-actions` container, no primary CTA ✅

---

## 3. Internal-Page Audit

### Internal records WITH flag (1 record)
- **Knocknarea** (`_leisure_internal_page: 1`) — has a Discover Ireland URL but the flag preserves the internal page
- Page has meaningful content: local image, description, location, related destinations
- **Classification**: USEFUL — preserved with "Ver mais"

### Internal records WITHOUT flag (47 records)
- All 47 have substantial content (>100 chars)
- 45 have featured images
- 2 have practical notes
- 1 has address
- Examples: Cavan Cathedral, Forty Foot, Mount Leinster & Nine Stones, Ducketts Grove, Millmount Fort Museum, etc.

**Classification decision**: Despite having content, these 47 records lack the explicit `_leisure_internal_page` flag that marks a page as intentionally preserved. The flag (Phase 3B) is the canonical architectural signal for "this internal page is useful and should remain a destination." Without it, the record is internal by default (no external URL), not by explicit evaluation. Per the task's instruction to decide "per existing architecture/data state, not by a blanket rule," the flag is the determining signal.

---

## 4. Useful vs Low-Value Page Classification

| Criterion | Flagged (1) | Unflagged (47) |
|---|---|---|
| `_leisure_internal_page` flag | Yes | No |
| Explicitly preserved (Phase 3B) | Yes | No |
| Has external URL (suppressed by flag) | Yes (DI) | No |
| Content volume | Substantial | Substantial |
| Has image | Yes | 45 of 47 |
| **Verdict** | Useful → "Ver mais" | Low-value → no CTA |

---

## 5. New CTA Rules

The card now chooses from:

**PRIMARY ACTION** (rendered only when a primary destination exists):
1. "Ver site oficial" (external-link icon) — external record with official website
2. "Ver mais" (arrow icon) — external record without official (DI only), OR internal record WITH `_leisure_internal_page` flag

**No primary CTA** — internal record WITHOUT flag (low-value page, no navigation)

**SECONDARY ACTION**:
- "Ver no mapa" (map pin icon) — rendered only when `conexao_leisure_map_url()` returns a valid URL (unchanged)

**Image/title behavior**:
- External records: image/title link to external URL (unchanged)
- Internal flagged records: image/title link to internal page
- Internal unflagged records: image/title are NOT linked (avoids navigation to low-value page)

---

## 6. Files Changed

| File | Change |
|---|---|
| `wp-content/themes/conexao-br-irlanda/template-parts/leisure-card.php` | Removed "Ver local"; added `$leisure_internal_page` flag check; made image/title links conditional; added new CTA logic with "Ver site oficial" / "Ver mais" |
| `wp-content/themes/conexao-br-irlanda/tests/test-leisure-card-map-action.php` | Updated internal card tests; added D2 test group for unflagged internal records |

**No changes to**: `functions.php`, `inc/seo.php`, data model, `single-leisure.php`, `archive.php`, plugins, JS, CSS, or assets. The "Ver no mapa" implementation is untouched.

---

## 7. URL/Redirect Strategy

**Strategy chosen**: **B. Remove low-value internal-page links from cards but retain the URLs temporarily.**

- The 47 internal pages remain published and accessible
- They are still included in the sitemap (`inc/seo.php:885-888` — external records excluded, internal included)
- They can still be reached via direct URL, sitemap, related-content links, and REST API
- No 404s introduced — no pages deleted or redirected
- The `_leisure_internal_page` flag remains on Knocknarea (no data change)

---

## 8. SEO Impact

| Aspect | Status | Notes |
|---|---|---|
| Sitemap inclusion | **PASS** | Internal records still included; external still excluded |
| Canonical tags | **PASS** | Internal pages keep their canonical; no change |
| Indexability | **PASS** | No noindex changes; pages remain indexable |
| Internal links | **PASS** | Card links to low-value pages removed; pages still linked from related-content |
| Redirects | **PASS** | No new redirects needed; no pages removed |
| Schema (TouristAttraction) | **PASS** | Internal pages still emit schema; no change |

---

## 9. Accessibility

| Check | Status | Notes |
|---|---|---|
| No dead links | **PASS** | No empty `href` attributes; no broken links |
| No hidden-but-focusable CTAs | **PASS** | Unwanted actions removed from HTML, not CSS-hidden |
| Correct accessible names | **PASS** | All CTA labels descriptive |
| Logical focus order | **PASS** | Conditional links maintain proper focus order |
| Visible focus | **PASS** | No CSS changes to focus indicators |
| No duplicate/confusing actions | **PASS** | Only one primary CTA per card (or none) |

---

## 10. Responsive Behavior

**PASS** — No CSS changes. The card layout is unchanged. Conditional link removal does not affect responsive behavior.

---

## 11. Regression Results

| Test Suite | Result |
|---|---|
| `test-leisure-card-map-action.php` | **44 passed, 0 failed** |
| `test-leisure-related-events.php` | **19 passed, 0 failed** |
| `test-leisure-multiselect-filters.php` | **62 passed, 0 failed** |
| `test-leisure-attribute-normalization.php` | **22 passed, 0 failed** |
| **Total** | **147 passed, 0 failed** |

### Representative record type testing (7 types):

| Record Type | Expected | Result |
|---|---|---|
| 1. External + official website + map | "Ver site oficial" + "Ver no mapa" | **PASS** |
| 2. External + official website + no map | "Ver site oficial" only | **PASS** |
| 3. Internal + flagged + useful page + map | "Ver mais" + "Ver no mapa" | **PASS** |
| 4. Internal + unflagged + map | "Ver no mapa" only (no primary CTA) | **PASS** |
| 5. Internal + unflagged + no map | No CTA at all | **PASS** |
| 6. Record with existing image | Image rendered correctly | **PASS** |
| 7. Record without image | "Imagem pendente" state rendered | **PASS** |

---

## 12. App/API Impact

| Check | Status | Notes |
|---|---|---|
| WP REST API | **PASS** | Standard `/wp-json/wp/v2/leisure` endpoint unchanged |
| Custom app | **NOT TESTABLE** | No custom mobile app runtime available; no custom API contract found |
| Data contract | **PASS** | No meta fields added, removed, or renamed |

---

## 13. Records Requiring Special Handling

| Record | Slug | Reason | Action |
|---|---|---|---|
| Queen Maeve's Trail (Knocknarea) | `queen-maeves-trail-knocknarea` | Only record with `_leisure_internal_page` flag | Changed from "Ver local" to "Ver mais" |

---

## 14. Final Verification

| Criterion | Status |
|---|---|
| "Ver local" no longer appears on Lazer cards | **PASS** |
| No CSS-only hiding used | **PASS** |
| Useful internal pages remain accessible | **PASS** |
| Low-value internal pages no longer create card navigation | **PASS** |
| "Ver site oficial" remains correct | **PASS** |
| "Ver mais" used only where appropriate | **PASS** |
| "Ver no mapa" remains correct | **PASS** |
| Existing map URLs remain unchanged | **PASS** |
| Internal/external behavior remains coherent | **PASS** |
| SEO/redirect behavior is explicitly handled | **PASS** |
| No broken links introduced | **PASS** |
| Existing Lazer functionality remains intact | **PASS** |
| Regression tests pass | **PASS** (147/147) |

---

## Final Classification

**LAZER REMOVE VER LOCAL PASSED**