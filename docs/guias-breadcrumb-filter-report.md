# Guias Breadcrumb → Filter Navigation — Implementation Report

**Status:** GUIAS BREADCRUMB FILTER NAVIGATION PASSED

**Scope:** Guias Práticos (`guide` CPT) guide-detail breadcrumb category crumb.
No other vertical (Blog, Eventos, Lazer, Cursos, Empregos, Apoiadores) was
modified. No new taxonomy, no new page, no new routing system.

---

## 1. Current breadcrumb behavior (before this change)

On a guide detail page the breadcrumb rendered as:

```
Início › Guias Práticos › Moradia › Como comprar uma casa na Irlanda…
```

The **category crumb** was generated with `get_term_link( $terms[0] )` in
`conexao_seo_breadcrumb_data()` (`inc/seo.php`). Because `conexao_category`
uses the rewrite slug `categories`, that produces:

```
https://conexaobr.ie/categories/moradia/
```

The site's `.htaccess` then 301-redirects every `/categories/{slug}/` to
`/{slug}/` (`RewriteRule ^categories/([^/]+)/?$ /$1/ [R=301,L]`), so clicking
"Moradia" landed on the standalone `/moradia/` static page — NOT the Guias
archive with the Moradia filter applied. That was the defect.

## 2. Current Guias filter architecture

- **CPT:** `guide`, archive slug `guias` (helper `conexao_get_guides_archive_url()`).
- **Taxonomy:** `conexao_category` (shared across all six CPTs,
  hierarchical, rewrite slug `categories`) — registered in
  `conexao-data-model`.
- **Filter parameter (already canonical):** `/guias/?categoria=<term-slug>`.
  Documented in `docs/routing.md`.
- **Query layer:** `conexao_content_archive_query()` (`pre_get_posts`,
  `functions.php:2849-2868`) adds a `tax_query` on `conexao_category`
  (`field => slug`) for the guide archive when `?categoria=` is present.
  Invalid slugs match nothing gracefully.
- **Filter bar:** `template-parts/guide-filters.php` builds every option URL
  with `add_query_arg( 'categoria', $term->slug, $archive_url )`.
- **Reusable guide URL helper:** `conexao_get_guide_category_url(
  $identifier, $fallback_slug )` (`functions.php:179`) resolves the real
  `conexao_category` term slug and returns
  `add_query_arg( 'categoria', $term->slug, $archive_url )`. It is the
  single source of truth already used by the homepage Quick Access cards —
  **reused verbatim by the new breadcrumb logic** (no manual concatenation).

## 3. Selected URL / filter mechanism

The guide category crumb now points to the existing filtered archive:

```
/guias/?categoria=<slug>
```

- Built exclusively through the shared `conexao_get_guide_category_url()`
  helper (same principle as the Lazer/Event filter URL helpers: one URL
  builder, `add_query_arg`, canonical term slug).
- The archive URL is the exact same format the archive filter bar emits, so
  the breadcrumb, the filter bar and the homepage Quick Access cards can
  never drift apart.
- Deterministic, shareable, bookmarkable, refresh-safe, back/forward-safe:
  it is a plain GET archive URL with one query parameter already handled by
  `pre_get_posts`.

## 4. Implementation files changed

| File | Change |
|---|---|
| `wp-content/themes/conexao-br-irlanda/inc/seo.php` | In `conexao_seo_breadcrumb_data()`, the `conexao_category` crumb for `guide` posts now uses `conexao_get_guide_category_url( $terms[0]->slug, $terms[0]->slug )` instead of `get_term_link()`. All other CPTs keep `get_term_link()`. |
| `wp-content/themes/conexao-br-irlanda/tests/test-guide-breadcrumb-filter.php` | NEW automated test (36 assertions). |
| `docs/themes/conexao-br-irlanda.md` | Breadcrumb hierarchy section documents the guide category crumb behavior. |
| `docs/guias-breadcrumb-filter-report.md` | This report. |

No CSS, no JS, no templates, no plugin, no taxonomy, no route was changed.

Relevant change in `inc/seo.php`:

```php
if ( 'guide' === $post_type && function_exists( 'conexao_get_guide_category_url' ) ) {
    $term_link = conexao_get_guide_category_url( $terms[0]->slug, $terms[0]->slug );
} else {
    $term_link = get_term_link( $terms[0] );
}
## 5. Category-term behavior

- **Single category:** the crumb links to `/guias/?categoria=<that-term-slug>`.
- **Multiple categories:** the existing deterministic rule is preserved and
  unchanged — `get_the_terms()` is ordered by name, so `$terms[0]` is the
  primary term used for the crumb (same rule as Blog and the other CPTs).
  Documented in code comment; no taxonomy redesign was performed.
- **No category:** no category crumb is emitted; the trail stays
  Início → Guias Práticos → title. No invented category, no broken link.
- **Term name:** always the live taxonomy term name (`$terms[0]->name`) —
  nothing is hard-coded (the old behavior already used the real name; only
  the URL changed).

## 6. Representative category verification (local site, HTTP + render)

| Guide (sample) | Category | Breadcrumb href | Filtered archive load | Cards |
|---|---|---|---|---|
| `/guias/alugar-casa-2/` (Alugar uma Casa — proxy for the acceptance page `como-comprar-casa-irlanda-mortgage/`, same Moradia term) | Moradia | `/guias/?categoria=moradia` | HTTP 200 | 3 (HAP, RTB, Alugar uma Casa) |
| `/guias/pps-number-2/` (PPS Number) | Documentos | `/guias/?categoria=documentos` | HTTP 200 | 7 |
| `/guias/impostos-2/` (Impostos na Irlanda) | Impostos e Revenue | `/guias/?categoria=impostos-e-revenue` | HTTP 200 | 3 |
| `/guias/reclamacao-trabalhista-wrc-irlanda-brasileiros/` (WRC — subject area distinct from housing) | Empregos | `/guias/?categoria=empregos` | HTTP 200 | 3 |
| `/guias/autismo-na-irlanda-diagnostico-hse-apoio/` (Saúde) | Saúde | `/guias/?categoria=saude` | HTTP 200 | 5 |
| `/guias/reclamar-banco-seguro-servico-financeiro-irlanda/` (Finanças) | Finanças | `/guias/?categoria=financas` | HTTP 200 | 3 |

Coverage requirement met: high-volume (Documentos, 7), low-volume (Empregos /
Finanças, 3), different subject areas (housing, health, work, finance).

## 7. Archive verification

- Every sampled filtered URL returns HTTP **200**.
- The filter bar on `/guias/?categoria=moradia` renders with the Moradia pill
  marked `is-active` + `aria-current="true"`; "Todos" is inactive.
- Cards on `/guias/?categoria=moradia` are exactly the Moradia guides — no
  unrelated guides appear.
- The archive layout is byte-for-byte the existing shared `archive.php`
  rendering (no template changes).
- **Pagination / infinite scroll:** the filter is applied to the main query
  in `pre_get_posts`, so `paginate_links()` keeps the `?categoria=` parameter
  on `/page/N/` links and the infinite-scroll enhancement keeps working on
  top of the real page URLs (existing mechanism, untouched).
- Category-result correctness is additionally covered by the automated test
  (`?categoria=` → exact post set, invalid slug → zero results).

## 8. Mobile / accessibility verification

- **No CSS/JS change** — typography, spacing, separators, colors, hover
  styles, active/current styling, mobile media queries
  (`.conexao-breadcrumbs` @ `max-width: 768px`) and dark-mode selectors
  (`.conexao-breadcrumb-link`, `.conexao-breadcrumb-sep`,
  `.conexao-breadcrumb-current` in `dark-mode.css`) are all untouched.
- The category crumb remains a real `<a class="conexao-breadcrumb-link">`
  whose accessible name is exactly the category name.
- The current guide remains the final non-linked crumb
  (`<span class="conexao-breadcrumb-current" aria-current="page">`).
- Semantics (nav, aria-label, ol/li, aria-hidden separator) unchanged.
- The automated test asserts the rendered markup still emits every class the
  light/dark CSS relies on.
## 9. SEO / routing verification

- No second URL format: `/guias/?categoria=slug` was already the single
  canonical filter convention (`docs/routing.md`).
- Canonical behavior for filtered archive views is unchanged: the archive
  canonical stays at the base `/guias/` (`conexao_seo_canonical()`), exactly
  as it already was when the same URL is produced by the filter bar or Quick
  Access cards.
- Noindex rules, redirect maps, sitemap output are untouched.
- The taxonomy term archive `/categories/{slug}/` remains intact for direct
  access / wp-admin (unchanged, and no longer referenced by guide
  breadcrumbs).
- Blog / Eventos / Lazer / Apoiadores breadcrumbs keep their previous
  behavior (only the `guide` branch was switched — minimum viable scope).

## 10. Regression results

Automated test suite (`tests/*.php`, run inside the local Docker container):

| Test | Result |
|---|---|
| `test-guide-breadcrumb-filter.php` **NEW** | **36 passed, 0 failed** |
| `test-event-location-filters.php` | 45 passed, 0 failed |
| `test-leisure-attribute-normalization.php` | 22 passed, 0 failed |
| `test-leisure-card-map-action.php` | 44 passed, 0 failed |
| `test-leisure-multiselect-filters.php` | 62 passed, 0 failed |
| `test-leisure-related-events.php` | 19 passed, 0 failed |
| `test-sponsor-archive-ordering.php` | 15 passed, 0 failed |

Real-site sweep over all **51** published local guides:

- 51/51 guide pages render `Início › Guias Práticos › …`.
- 51/51 breadcrumb trails contain **zero** `/categories/` links.
- 51/51 categorized guides render exactly one filtered category crumb
  (`/guias/?categoria=…`).

The new test covers all requested cases:

| Requirement | Test |
|---|---|
| A. category breadcrumb exists | `C. category crumb exists…` |
| B. category breadcrumb href is correct | `D. href equals the shared filter URL helper output` |
| C. href → filtered Guias archive | `D. href points to the Guias archive…` |
| D. correct category filter encoded | `D. …categoria=gbf-alpha`; per-category loop |
| E. multiple categories / primary term | section 3 (first of `get_the_terms()`, by name) |
| F. guide without category | section 4 (3 crumbs, no filter/term URL) |
| G. archive receives filter correctly | `G/H.` section (pre_get_posts real hook path) |
| H. category result set correct | `G/H.` section (exact ID sets) |
| I. existing crumb levels unchanged | `A/B/E/F` assertions |
| J. mobile/dark-mode markup valid | Rendered-markup section (classes + semantics) |

## 11. Verification classifications

| Verification | Classification |
|---|---|
| Root cause (term link → `/categories/` → 301 → `/moradia/`) | **PASS** |
| Breadcrumb href is `/guias/?categoria=…` for all categories | **PASS** |
| Filtered archive returns HTTP 200 | **PASS** |
| Filter visibly active on the archive (is-active + aria-current) | **PASS** |
| Only matching guides shown | **PASS** |
| Pagination respects the filter (main-query mechanism) | **PASS** |
| No unrelated verticals affected (tests green) | **PASS** |
| Mobile layout / dark mode unchanged (no CSS/JS diff; markup classes asserted) | **PASS** |
| Accessibility (real link, name, current-item semantics) | **PASS** |
| No duplicate routing / second URL format | **PASS** |
| Regression suite | **PASS** |
| Production URL click-through on `https://conexaobr.ie` (no write/prod access) | **BLOCKED** |
| Production sitemap/indexability diff (no prod access) | **BLOCKED** |

## 12. Known limitations

- **Production verification was not performed**: the report evidences the
  same code path on the local instance (identical theme, plugins and data
  model). The specific acceptance URL `como-comprar-casa-irlanda-mortgage`
  does not exist in the local dataset; the Moradia category was verified with
  the equivalent local guide `alugar-casa-2`.
- The guide archive filter is single-select / single-slug (documented
  behavior): `?categoria=a,b` is treated as one (unknown) slug → zero
  results. This was **not changed** by this task and is now covered by a
  regression assertion.
- If `conexao_get_guide_category_url()` cannot resolve the term (only
  possible if taxonomy data were missing for a term already returned by
  `get_the_terms()`), the helper falls back to the plain `/guias/` archive —
  never a broken link.
- The `/categories/{slug}/` taxonomy archive and `/moradia/` static page
  still exist and remain reachable directly. Guide breadcrumbs simply no
  longer route through them.
- Events/Apoiadores `conexao_category` breadcrumbs still use
  `get_term_link()` (pre-existing behavior, out of scope).

---

**Final classification: GUIAS BREADCRUMB FILTER NAVIGATION PASSED**
```