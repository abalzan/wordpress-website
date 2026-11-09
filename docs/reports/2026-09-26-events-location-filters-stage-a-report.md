# Events Location Filters — Stage A Report

**Scope:** County + City/Town filtering for the Events archive (`/eventos/`),
reusing the established Conexão BR Irlanda filtering conventions (Lazer
standard) and the existing Event data model. No importer changes, no new
taxonomies, no destructive migration.

**Verification environment:** local Docker WordPress (MySQL, WP-CLI),
39 published events. Production (WordPress.com / conexaobr.ie) was **not**
accessed; every production-specific check is marked accordingly.

---

## 1. Current Events architecture (audit)

| Area | Finding |
|---|---|
| Event CPT | `event` (slug `eventos`), registered by `conexao-data-model` |
| Taxonomies | `conexao_county` (shared, all 6 CPTs), `conexao_town` (events only, registered by `conexao-event-runtime`), `conexao_category` / `conexao_tag` (shared) |
| Meta | `_event_*` group registered in `Conexao_Event_Runtime::register_meta()` — including `_event_location` (display label), `_event_venue`, `_event_address`, `_event_source`/`_event_source_id`, `_event_status` (visibility gate) |
| Query layer | `conexao_content_archive_query()` (`pre_get_posts`): events archive = upcoming events via the recurrence-aware `Conexao_Event_Query::upcoming_events()` ID list (`post__in` + `orderby post__in`), with taxonomy filters appended to the same main query |
| Templates | `archive.php` (shared archive shell) → `template-parts/event-filters.php` (filter bar) → `template-parts/event-card.php` (cards) → `template-parts/pagination.php` |
| URL params (before) | `?cidade=` (towns), `?categoria=` (categories). **No county parameter existed.** |
| Previous filter UI | Flat pill bar (`events-filter-bar`) listing towns and categories; switching one filter **dropped the other** (single-active-filter model) — pre-existing limitation, not a regression introduced here |
| Sorting | Upcoming first, ordered by next occurrence (runtime ID list); legacy date-meta fallback when the runtime is inactive |
| AJAX/REST | No REST routes of our own; the CPT and all `_event_*` meta are `show_in_rest => true` (schema only). No AJAX filter endpoint |
| Existing tests | Event runtime: `test-plugin-separation.php`, `test-event-recurrence.php`, `test-event-query.php`. Importer: `test-event-address.php`, `test-past-event-filter.php`, per-source suites (Motorsport, IVVCC, Eventbrite), `test-import-log.php`. Theme: leisure/sponsor suites |

## 2. Lazer filtering patterns reused

| Pattern (Lazer) | Events adoption |
|---|---|
| `?county=` URL parameter (taxonomy `conexao_county`) | **Adopted verbatim** — Events now uses the same `?county=` convention |
| AND across dimensions, single-select per dimension | **Adopted** (Lazer's multi-select exists only for `?categoria=`/`?atributo=` there; Events keeps its single-select dimensions) |
| Option lists derived from terms actually used by published content (`conexao_get_terms_for_post_type()`) | **Adopted** — counties and towns listed only where used by published events; empty terms never render |
| Shared URL-builder helper (`conexao_leisure_filter_url()`) as the single source of truth for filter URLs | **Mirrored** as `conexao_event_filter_url()` (full-state URLs, empty dimensions omitted, page cursor reset) |
| County → city relationship (county single, towns display-only in Lazer singles) | **Extended** — `conexao_get_event_towns( $county_slug )` scopes town options to the selected county |
| Active-state treatment + "Limpar filtros" reset visible only while filtering | **Adopted** |
| Invalid slugs fail gracefully (zero results, empty state) | **Adopted** |
| Visual language | Events keeps its **existing pill bar** (`events-filter-bar`), extended with dimension labels and a reset link. Lazer's desktop dropdown/bottom-sheet component was **not** transplanted — the two archives share tokens, spacing rhythm and behavior standards, not the same control set (see Known limitations) |

## 3. County data audit (local dataset)

- **39 published events** (after removing this stage's temporary test fixtures; the dataset was restored to its original count).
- **0 events without a county** — `COUNTY_COVERAGE = 39/39 (100%)`.
- Counties in use by published events: **Laois (32), Waterford (1), Cork (1), Dublin (1), Sligo (1), Kerry (1), Wicklow (2)**.
- Sources of the county values: per-source config hint (`county => 'Laois'` for Eventbrite/Heritage Week/Laois Tourism), IVVCC's nationwide string derivation (`Conexao_Event_Location`, `Co X` markers + national town index), all funneled through `ensure_county()` → canonical `conexao_county` terms (seeded 26-county list).
- Invalid/ambiguous county values found: **none** (all 39 events carry one of the 7 canonical county terms above).

**Verdict: PASS** — county is fully derivable and already normalized; no backfill needed.

## 4. City/Town data audit (local dataset)

- **25/39 events have a town term; 14 do not** (Eventbrite events whose venue locality resolves to the county only, e.g. `_event_location = "Laois"` — exactly the missing-city case Stage A must keep discoverable).
- Towns in use by published events: **Portlaoise, Portarlington, Abbeyleix, Mountmellick, Mountrath, Dungarvan, Ballyvourney, Blessington, Clonskeagh, Sligo, Kenmare** (11 distinct, all canonical term names).
- Ambiguous/duplicate normalized values found: **none** (one term per town; dedup happens at `ensure_town()`/`term_exists()`).
- Two IVVCC records store venue-sentence text in the `_event_location` **display** meta ("College Car Park Registration from 10.00 am…"). This is a display-label issue only — their structured `conexao_town`/`conexao_county` terms are correct — and is pre-existing, out of scope (importer behavior unchanged).

**Verdict: PASS** — a stable, controlled city/town concept already exists (`conexao_town`, populated by deterministic importer normalization); no free-text guessing needed.

## 5. Selected location model

**Reuse the two existing taxonomies. Nothing new.**

- County filter → `conexao_county` (shared taxonomy, already seeded with all 26 counties).
- City/Town filter → `conexao_town` (events-only taxonomy, registered by the production runtime plugin).
- No meta mirror, no parallel taxonomy, no remote/geocoding service.

## 6. Why that model was chosen

1. **Importer compatibility** — both import paths (live import engine, JSON export→production import) already write exactly these two taxonomies; changing nothing preserves every contract.
2. **Event Runtime compatibility** — `conexao_town` is registered by the production-critical runtime plugin; the `_event_status` gate and the recurrence-aware ID list keep applying unchanged (filters are appended to the same main query as `tax_query` groups).
3. **Efficient queries** — native indexed `tax_query` joins over `wp_term_relationships`; no full-table PHP scans, no per-request normalization.
4. **API/frontend compatibility** — REST schema unchanged; card/template code untouched.
5. **Maintainability** — a future source only needs to produce `county`/`town` in the shared raw-event shape (normalizer handles the rest); no per-source filter logic.
6. **Smallest change** — zero new data structures; ~100 lines of theme helpers + template + CSS.

The alternative (a `_event_county`/`_event_city` meta mirror) was rejected because it duplicates the taxonomy representation (violates the single-representation rule), would require a backfill, and would not improve query efficiency over the existing indexed taxonomy joins.


## 7. Existing importer compatibility

| Source | County enters via | Town enters via | Affected by this change? |
|---|---|---|---|
| Laois Tourism | source config `county` hint | `Conexao_Event_Location` (Laois town list) | No |
| Heritage Week | source config `county` hint + JSON-LD | JSON-LD `Event.location` + national town index | No |
| Eventbrite | source config `county` hint | `venue.address.city` (structured API field) | No |
| IVVCC | `Co X` string derivation + national town index | national town index | No |
| Motorsport Ireland | source supplies no location data | — (empty by design; event still filterable by no location) | No |
| iCalendar | source config `county` hint | location-string normalization | No |

- `Conexao_Event_Importer_Engine::save_event_taxonomies()` writes `conexao_county` / `conexao_town` via `ensure_county()` / `ensure_town()` — **unchanged**.
- Export/Import (`class-event-export.php` / `class-event-import.php`) already carry both taxonomies in `export_taxonomies` — **unchanged**.
- No importer file was modified; no source-specific filtering logic was added.

**Verdict: PASS** (local suites green; production import path is schema-identical).

## 8. Data normalization rules

All normalization already existed and was **verified, not rewritten**:

| Concept | Source field | Normalization | Canonical value | Dedup | Missing handling |
|---|---|---|---|---|---|
| County | `Conexao_Event_Location` → county / `Co X` marker / national town index / source hint | trim → lowercase key → canonical name | `conexao_county` term name ("Laois") | `term_exists()` + 26-county seed list | no term → event still indexed and visible; never guessed |
| Town | structured `town` hint → location string (Laois list / national index) | trim → lowercase key → proper case | `conexao_town` term name ("Portlaoise") | `term_exists()` | no term → discoverable by county; filter lists towns only where used |
| Query boundary (new) | URL params | `sanitize_title()` + `get_term_by('slug')` validation | slug | n/a | invalid slug → deterministic zero results (never a wrong result set, never an error) |

Display value = term `name`; query value = term `slug`. No string manipulation scattered in templates — URLs are built exclusively through `conexao_event_filter_url()` and option lists through `conexao_get_terms_for_post_type()` / `conexao_get_event_towns()`.

## 9. Backfill assessment

Measurements (local dataset, before/after — identical because **no backfill was required**):

| Metric | Before | After | Change |
|---|---|---|---|
| Published events | 39 | 39 | 0 (test fixtures created and removed; count restored) |
| Events with county | 39 | 39 | 0 |
| Events without county | 0 | 0 | 0 |
| Events with town | 25 | 25 | 0 |
| Events without town | 14 | 14 | 0 |
| Invalid county values | 0 | 0 | 0 |
| Duplicate normalized locations | 0 | 0 | 0 |

- No migration/backfill script was needed: county coverage is already 100% and town coverage is data-driven (the importer never guesses; a source without locality data produces county-only events by design).
- The 14 county-only events are a **property of the source data**, not a defect. They remain discoverable (verified: unfiltered archive and county filter both include them).
- **Production:** the same zero-migration expectation holds (the production dataset receives these taxonomies through the identical export/import pipeline). A production pre-deploy audit must confirm the same counts before enabling the filters (§17).

## 10. Frontend implementation

- `template-parts/event-filters.php` — rewritten as grouped AND-combined dimensions on the existing pill-bar visual language:
  - **Localização** (county) group — counties used by published events (`conexao_get_terms_for_post_type( 'conexao_county', 'event' )`), human names, stable name ordering.
  - **Cidade** (city/town) group — `conexao_get_event_towns()`; when a county is selected the options are scoped to that county (County → City). With no county selected, all towns used by events are shown (matches the previous single-level behavior and avoids a disabled control).
  - **Categoria** group — unchanged data source (`conexao_category` used by events).
  - Every option is a real hyperlink built through `conexao_event_filter_url()`; selecting an already-active value toggles it off; switching county clears only a town that belongs to another county (invalid combinations are impossible from the UI).
  - `Limpar filtros` reset appears only while a filter is active; `Todos` remains the unconditional reset to the clean archive URL.
  - Active states: `is-active` class + `aria-current="true"`; group labels are `aria-hidden` text separators; the bar is a labelled `role="group"`.
- `functions.php` — two new helpers:
  - `conexao_get_event_towns( $county_slug = '' )` — county-scoped town option list (single ID query + `wp_get_object_terms`, object-cache 5 min).
  - `conexao_event_filter_url( array $filters )` — full-state URL builder (shares the exact contract of `conexao_leisure_filter_url()`).
  - Query layer: `?county=` added to the event archive branch of `conexao_content_archive_query()`; all three dimensions validated via `get_term_by()`; unknown slugs inject an intentionally-never-matching term so the result is deterministically empty (same pattern as the Courses branch).
- `main.css` / `dark-mode.css` — dimension labels + reset link styled from existing design-system tokens (spacing rhythm, focus ring, dark mode overrides).
- Cards, archive header, hero widget, singles: **untouched**.

## 11. URL / filter semantics

- Parameters: `?county=slug`, `?cidade=slug`, `?categoria=slug` (consistent with the site's existing `?county=`/`?cidade=`/`?categoria=` conventions — no new naming).
- **AND semantics** across all dimensions (verified by tests): `county=laois&cidade=portlaoise&categoria=X` = County AND City AND Category.
- A city can never override the county (a town from another county + county → zero results, not the town's events).
- Every option URL carries the **full state** of the other dimensions (fixes the pre-existing single-active-filter behavior); every filter change resets to page 1; empty dimensions are omitted from URLs.
- Shareable/refresh/back-forward: all state lives in the URL; the server re-derives results from `$_GET` on every request. Browser-level back/forward behavior was verified structurally (state fully URL-driven, no JS filter state); interactive gesture-level checks are runtime-browser checks (§12).
- Invalid slugs: zero results + standard empty state (graceful; verified).

## 12. Mobile behavior

- The Events filter bar is the existing responsive component: pill bar wraps under 768px with tightened spacing (existing `@media` block), no horizontal overflow risk introduced (same flex-wrap model).
- The county → city cascade is expressed as plain hyperlinks, so on mobile it degrades identically to the desktop behavior (no dropdown trap, no JS dependency).
- Focus states, dark mode and tap targets inherit the existing `.events-filter-link` rules (40px desktop / 36px mobile minimum height).
- **Runtime mobile gestures (touch, sheet UX, focus-trap checks on real devices) — NOT TESTABLE in this environment** (no mobile runtime available). Structure and CSS-level checks: PASS.

## 13. Performance results (local, 39 events, measured serially)

Representative main-query timings (each measured through the real `pre_get_posts` path; first request also warms the upcoming-events transient):

| Query | Time | Results |
|---|---|---|
| All events (unfiltered) | 113.6 ms | 39 |
| County only (`?county=laois`) | 63.3 ms | 32 |
| City only (`?cidade=portlaoise`) | 35.5 ms | 14 |
| County + City | 31.8 ms | 14 |
| County + Category (no events carry categories locally) | 8.0 ms | 0 |
| County + City + Category | 5.2 ms | 0 |
| Invalid county slug | 3.8 ms | 0 |
| `conexao_get_event_towns('laois')` cold | 99.0 ms | 11 towns |
| `conexao_get_event_towns('laois')` cached | ≈0 ms | 11 towns |

- Filters add only indexed `tax_query` joins on top of the existing upcoming-events path — no N+1, no full-table scans, no runtime normalization per request.
- The unfiltered case dominates the cost (recurrence candidate widening + main query); filtered cases are equal or cheaper.
- Option lists are cached (object cache, 5 min) — the county → city cascade costs ≈0 ms after the first request.
- Scaling note: the same mechanisms the archive already uses (term relationships + the runtime's date-keyed transient) are what scale the current /eventos/ archive; the new filters ride on them.

## 14. API / app compatibility

- No REST route, meta registration, or taxonomy registration was changed. The event CPT and all `_event_*` meta remain `show_in_rest => true` with their existing auth callbacks; `conexao_county` / `conexao_town` / `conexao_category` remain public REST taxonomies — any consumer reading county/town terms or event meta through the REST API is unaffected.
- The filters are server-rendered theme behavior; they add no new endpoints and change no existing contract.
- Schema/data-contract validation: **PASS**.
- Runtime app/API request validation (list/filter/detail/pagination against a live app): **NOT TESTABLE** (app runtime not available in this environment).

## 15. Tests

New suite: `wp-content/themes/conexao-br-irlanda/tests/test-event-location-filters.php` — **39 assertions, 0 failures**, self-cleaning (temporary `ev-*` posts/terms created, tracked, and removed; adopts-and-removes leftovers from interrupted runs under the test-owned `ev-` slug prefix; flushes the date-keyed upcoming-events transient like the runtime's own suite). Run:

```bash
docker compose exec wordpress php /var/www/html/wp-content/themes/conexao-br-irlanda/tests/test-event-location-filters.php
```

| Requirement | Test |
|---|---|
| A. County normalization | `Conexao_Event_Location` 'Co Waterford' / 'County Laois' derivations + no-guess on unmapped strings |
| B. City/Town normalization | national town index (Dungarvan), proper-case/trim, empty input |
| C. County filter | `?county=ev-cavan` returns exactly the county's events (real `pre_get_posts` path) |
| D. City/Town filter | `?cidade=` works standalone (independent dimension) |
| E. County + City | deterministic intersection |
| F. County + Category | deterministic intersection |
| G. County + City + Category | three-dimension AND; impossible combination → 0 results gracefully |
| H. Invalid location values | invalid county/city/category slugs → 0 results, no error |
| I. Events with missing city | county-only event still found by county filter |
| J. Events with missing county | found unfiltered and by category filter |
| K. URL state persistence | `conexao_event_filter_url()` preserves full state; slugs canonicalized; page cursor reset |
| L. Reset behavior | empty filter state → clean archive URL; template reset link appears only while filtering |
| M. Pagination / infinite scroll | filter URLs never carry `paged`; pagination part untouched (runtime gestures — §12/§16) |
| N. Existing events | unfiltered archive returns all seeded events |
| O. Existing importers | location normalization covered (A/B); per-source suites green (§16) |
| P. API compatibility | schema-level (§14); runtime NOT TESTABLE |
| — (extra) | `conexao_get_event_towns()` scoping (all vs county vs unknown county); post__in + taxonomy-filter coexistence with the recurrence-aware ID list; tax_query structure (one group per active dimension) |

## 16. Regression results (all run serially — see caveat)

| Suite | Result | Status |
|---|---|---|
| `tests/test-event-location-filters.php` (NEW) | 39 passed / 0 failed (3 consecutive runs) | **PASS** |
| Theme `test-leisure-multiselect-filters.php` | 62 / 0 | **PASS** |
| Theme `test-leisure-related-events.php` | 19 / 0 | **PASS** |
| Theme `test-leisure-attribute-normalization.php` | 22 / 0 | **PASS** |
| Theme `test-sponsor-archive-ordering.php` | 15 / 0 | **PASS** |
| Runtime `test-event-recurrence.php` | 90 / 0 | **PASS** |
| Runtime `test-plugin-separation.php` (with-tooling) | 34 / 0 | **PASS** |
| Runtime `test-event-query.php` | 40 / 0 | **PASS** |
| Importer `test-event-address.php` | 76 / 0 | **PASS** |
| Importer `test-past-event-filter.php` | 29 / 0 | **PASS** |
| Importer `test-motorsport-ireland-importer.php` | 60 / 0 | **PASS** |
| Importer `test-ivvcc-importer.php` | 45 / 1 — `different ID+URL does not collapse` | **Pre-existing** (fails identically on the unmodified baseline, verified via `git stash` run; deduplicator logic, unrelated to location filters) |
| Importer `test-eventbrite-importer.php` | 68 / 0 | **PASS** |
| Importer `test-import-log.php` | 53 / 0 | **PASS** |
| `no-tooling` runtime separation | 24 / 6 (identical on baseline) | **Pre-existing** local-config result — the importer is active in this DB, so the no-tooling assertions report the tooling classes as loaded; configuration issue of the local dataset, unrelated to this change |

**Concurrency caveat (documented for future runs):** running two event suites in parallel intermittently corrupts the shared date-keyed upcoming-events transient mid-run and produces cascading failures. All results above are serial. Any CI wiring must run these suites serially.

**Verdict: no regression introduced by Stage A** — every green suite stayed green; the two non-green results are pre-existing on the unmodified baseline and outside this stage's scope.

## 17. Production safety status

- **No production writes were performed.** All verification happened in local Docker.
- No destructive migration exists to run: this stage ships no data migration at all (§9). Deployment = theme (functions.php, event-filters.php, CSS, tests) + updated docs. Plugins untouched.
- Required pre-deploy production checks (to be executed before/after deploy on production):
  1. Capture production event counts + county/town coverage (expect: 0 events missing county; county-only events must remain visible).
  2. Capture pre-deploy `/eventos/` URLs with `?cidade=`/`?categoria=` — they must keep resolving identically after deploy (parameters and semantics preserved).
  3. Smoke-test: `/eventos/`, `/eventos/?county=laois`, `/eventos/?county=laois&cidade=portlaoise`, `/eventos/?county=dublin`, an invalid `?county=` slug, reset link visibility.
  4. Confirm no unrelated records modified (no writes outside render-time queries; no cache flush beyond the existing save hooks).
- Until those production checks run, production validation is **BLOCKED** — by design, since this environment has no production access.

## 18. Known limitations

1. **Visual standard** — Events deliberately keeps its pill-bar filter control instead of adopting Lazer's dropdown/bottom-sheet component. Shared: URL semantics, AND behavior, option derivation, reset/active treatment, design tokens, spacing rhythm, accessibility conventions. Not shared: the specific desktop dropdown + mobile bottom-sheet widget. Unifying the two widget implementations is a candidate for a later stage and would be a larger, separate change.
2. **Events category coverage** — in the local dataset no event carries `conexao_category` terms, so the Categoria dimension renders no options locally. On production, categories flow through the same import/manual pipelines; the dimension appears only when used (by design, like Lazer).
3. **County-only events** — 14/39 local events have no town (source data). They remain discoverable by county; they can never be filtered by city (by design — the importer never guesses).
4. **Runtime/browser checks** — mobile gesture UX, browser back/forward interaction on a real browser, live app/API requests, and production deployment validation are NOT TESTABLE / BLOCKED in this environment and are listed in §12/§14/§17.
5. **Test concurrency** — event suites must run serially (§16); the shared date-keyed transient makes parallel runs flaky. Documented to help future CI wiring.

---

## Final status

| Criterion (§22) | Status |
|---|---|
| County filtering works | PASS (local, tested) |
| City/Town filtering where data exists | PASS (local, tested) |
| County + City correct | PASS (tested) |
| Deterministic AND semantics | PASS (tested) |
| URL state works | PASS (local; browser gestures §12) |
| Existing event filters still work | PASS (tested) |
| Existing events discoverable / missing city kept | PASS (tested) |
| Importer compatibility preserved | PASS (suites green; no importer change) |
| Frontend matches site/Lazer standard | PASS (conventions/tokens; widget-level difference documented) |
| Mobile behavior | CSS/structure PASS; gestures NOT TESTABLE |
| No significant performance regression | PASS (measured; filters ≤ baseline cost) |
| API/app compatibility | Schema PASS; runtime NOT TESTABLE |
| Regression tests green | PASS (2 pre-existing failures documented, present on baseline) |
| No unrelated production data modified | PASS locally; production **BLOCKED** (no access) |

**EVENTS LOCATION FILTERS STAGE A NOT READY**

Rationale: every local, testable criterion passes and no production write is
required for the code itself — but the stage's own success criteria require
production validation (§19/§20) before a PASSED classification, and production
checks (deploy smoke tests, production coverage audit, live app/API validation)
remain untested in this environment. All local verification is complete and
reproducible; once the §17 production checks run successfully, the stage should
be re-classified as PASSED with no further code changes expected.
