# Events Location Filters — Stage B Report (Filter UI Standardization)

**Scope:** bring the `/eventos/` filter UI onto the SAME filter UX standard as
`/lazer/` (and the `/empregos/` agency directory, which already mirrors it).
**UI/UX change only — no data-model rewrite.** The Event taxonomy
architecture and the URL contract are untouched.

## 1. What changed

| Area | Change |
|---|---|
| `template-parts/event-filters.php` | Pill bar → Lazer-standard filter widget: desktop hyperlink dropdowns (Localização / Cidade / Categoria) with active dot + caret triggers, listbox options (checkmark + `aria-selected`), per-dimension "Todas"/"Todos" reset options, client-side search for long option lists, active-filter chips, "Limpar filtros", `role="status"` result count; mobile "Filtrar" button (count badge) opening the modal bottom sheet with radio fieldsets + search + instant apply + clean-URL submit |
| `assets/css/main.css` | Old `.events-filter-*` pill-bar block replaced by the `.event-filters-*` widget block — a 1:1 mirror of the `.agency-filters-*` rules (same design tokens, spacing rhythm, breakpoints) |
| `assets/css/dark-mode.css` | Stale pill-bar dark rules removed; new section **39c** mirrors **39b** (agency) so all three widgets share the dark-mode treatment (including the `--conexao-primary-text` accent); reduced-motion lists updated |
| `assets/js/main.js` | `initAgencyFilters()` generalized into a shared `initDirectoryFilters(root, ns)`; new thin `initEventFilters()` wrapper binds `[data-event-filters]`. One code path now serves Lazer-style interaction on both /empregos/ and /eventos/ (bonus: fixes the latent scope-resolution bug in the agency desktop search) |
| `template-parts/content-none.php` | Eventos branch: filtered zero-results now render the shared `.event-filters-empty` state (dashed panel + "Limpar filtros") instead of the "nothing published yet" copy |
| `tests/test-event-location-filters.php` | Template-markup section rewritten for the widget (root contract, listbox semantics, search threshold, chips, mobile sheet form); all URL/query semantics tests unchanged |
| Docs | `docs/themes/conexao-br-irlanda.md`, `docs/frontend.md` updated |

## 2. What did NOT change (contracts preserved)

- **Taxonomies:** `conexao_county` (shared), `conexao_town` (events only),
  `conexao_category` (shared). No new taxonomies, no re-parenting, no data
  migration.
- **URL semantics:** `?county=slug`, `?cidade=slug`, `?categoria=slug` —
  single-select per dimension, **AND-combined**
  (`?county=laois&cidade=portlaoise` = Laois AND Portlaoise; verified by
  tests).
- **County → City cascade:** town options remain server-side scoped via
  `conexao_get_event_towns($county)`; every option URL is built through
  `conexao_event_filter_url()` (other dimensions preserved, page cursor
  resets, shareable/refresh-safe).
- **Invalid-combination safety:** switching county still clears a town from
  another county (dropdown links), and on mobile a county radio change
  applies instantly (navigates), re-rendering the scoped town list — so no
  invalid County + City combination can be produced from the UI.
- **Query layer:** `conexao_content_archive_query()` event branch untouched
  (recurrence-aware upcoming ID list + AND tax filters + graceful zero
  results for unknown slugs).

## 3. Reused standards (same widget contract as Lazer/Empregos)

Dropdown trigger/button behavior, chevron/caret, search behavior (> 8
options), checkmark/selected state, active state, dropdown panel, empty-state
treatment, spacing, typography, borders/radius, focus styles, dark-mode
behavior, mobile bottom-sheet behavior, accessibility semantics and
reset/clear behavior are all mirrored 1:1 from the established widget
(`leisure.css` / `.agency-filters-*`). Leisure CSS is not loaded on
`/eventos/`, so the patterns are mirrored in `main.css` (`.event-filters-*`),
exactly like the Empregos precedent.

## 4. Verification

- `tests/test-event-location-filters.php` — **45 passed, 0 failed**
  (includes AND semantics, cascade, URL helper, widget markup).
- `tests/test-leisure-multiselect-filters.php` — 62 passed, 0 failed.
- `conexao-event-runtime` `test-event-query.php` — 40 passed, 0 failed.
- `php -l` clean on all touched PHP; `node --check` clean on `main.js`;
  CSS brace balance verified in both edited stylesheets.
- Runtime mobile gestures (real touch device) not exercised in this
  environment, same as Stage A — the widget shares the battle-tested
  Empregos/Lazer interaction path.
