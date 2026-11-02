# Plan — Location-based Attractions and Distance Estimates on `/lazer`

> **This is a planning-only document.** No application code, production content, or database changes are introduced by this task. The plan is the deliverable.

| | |
|---|---|
| **Task title** | Location-based Attractions and Distance Estimates on `/lazer` |
| **Date** | 2026-11-01 |
| **Author / agent** | Cline (AI coding agent) |
| **Branch** | `holidays-dates` |
| **Repository baseline (starting SHA)** | `081ac8a8b45d3eb12b685b60af69f5861e906b80` |
| **Applicable skill(s)** | `wp-repository` (orientation, planning, scope control); supporting skills named where relevant |
| **Plan status** | draft |

---

## 1. Scope

This task produces a **technical implementation plan** for adding optional, location-based proximity ordering and distance estimates to the `/lazer` (Lazer & Turismo) archive page of the Conexão BR Irlanda WordPress site.

When a visitor grants browser geolocation permission on `/lazer`:
1. Attractions are ordered by proximity, nearest first.
2. Each attraction card displays an estimated straight-line distance from the visitor (e.g. `A 2,5 km de si` / `A 2,5 km away`).
3. The visitor can discover the closest attractions without manual search.

When the visitor does not share their location, denies permission, or location cannot be obtained, `/lazer` must behave exactly as it does today.

**Explicitly out of scope for this task:** implementing the feature, modifying production content, changing the existing `/lazer` page, writing coordinates, deploying, or touching the Flutter/mobile repository.

## 2. Explicit non-goals

The following tempting adjacent work is deliberately **not** part of this plan:

- No driving-distance or travel-time calculations (straight-line estimate only, clearly labelled as an estimate).
- No automatic IP-based geolocation fallback.
- No third-party geocoding/maps/routing service unless a future phase documents a justified need.
- No persistent storage of visitor coordinates (WordPress, cookies, localStorage) unless a separate privacy design is proposed.
- No continuous `watchPosition` tracking — a single `getLocation()` request per page visit.
- No distance-radius filter in this phase.
- No changes to the Flutter/mobile application repository (out of scope for this repository).
- No modification of canonical Portuguese content, except where explicitly justified and documented.
- No new plugin, framework, dependency, or external service without documented justification.
- No implementation during this task; no production writes or deploys.



## 3. Relevant authoritative documents

| Document | Role |
|---|---|
| `AGENTS.md` | Repository orientation and non-negotiable rules |
| `docs/engineering-standard.md` | Mandatory engineering standard (WP-ES) |
| `docs/content-model.md` | Content types, taxonomies, meta, leisure data model |
| `docs/routing.md` | Routes, `/en/` layer, Polylang behaviour |
| `docs/frontend.md` | Frontend/CSS conventions, infinite scroll |
| `docs/themes/conexao-br-irlanda.md` | Theme internals and template inventory |
| `docs/testing.md` | Test runner and permanent invariant gates |
| `docs/templates/plan.md` | Plan template (this document's structure) |
| `plugins.json` | Authoritative plugin registry (no plugin changes here) |

**No procedure is restated here.** Where a skill applies, the skill is named rather than re-planning its steps.

## 4. Current-state findings (audit)

All findings below are verified by reading the code. Nothing is assumed.

### 4.1 `/lazer/` page rendering

- Route: `/lazer/` (PT canonical) and `/en/lazer/` (EN, via Polylang). `routing.md` § "Custom Post Type Archives".
- Template: `wp-content/themes/conexao-br-irlanda/archive.php` (shared CPT archive shell).
- The archive branch sets `$container_class .= ' leisure-page'` and `$archive_header.filters = 'leisure'`.
- Header rendered by `template-parts/archive-header.php` with the leisure filter bar (`template-parts/leisure-filters.php`).
- The leisure grid is wrapped in `<div class="leisure-results" data-leisure-results>` (stable node for in-place filter swaps).
- Cards rendered via `get_template_part( 'template-parts/leisure', 'card' )` → `template-parts/leisure-card.php`.
- Grid tag: `<div class="events-grid" data-infinite-scroll>` — automatic infinite scroll is the enhancement; normal `.conexao-pagination` is the no-JS fallback.
- Pagination: `template-parts/pagination.php` rendered after the grid.

### 4.2 Leisure card structure (`template-parts/leisure-card.php`)

Each card renders, in order:
1. Image wrapper (featured thumbnail, lazy-loaded `conexao-card` size) or an "Image pending" state; attribution overlay when applicable.
2. Category chip (`leisure-card-category`) — first `conexao_category` term.
3. Title (`leisure-card-title`), linked when a primary destination exists.
4. Details row (`leisure-card-details`): county (`conexao_county` term) + `·` + town (`_leisure_town` meta), with a map pin SVG.
5. Excerpt (`leisure-card-excerpt`, 18 words via `conexao_leisure_card_excerpt()`).
6. Practical attributes (`leisure-card-attrs`, capped at 4, priority ordered).
7. Actions (`leisure-card-actions`): primary CTA (`leisure-card-cta`) + optional "Ver no mapa" (`leisure-card-cta--map`) using `conexao_leisure_map_url()`.

There is **no distance value, no proximity indicator, and no location-request UI** on the card today.

### 4.3 Leisure filters (`template-parts/leisure-filters.php`)

Server-side, URL-driven filtering:
- `?county=slug` — single county (`conexao_county` taxonomy).
- `?categoria=slug` or `?categoria=a,b` — category (`conexao_category` taxonomy), single or OR list.
- `?atributo=slug` or `?atributo=a,b` — attribute (`conexao_leisure_attribute` taxonomy).
- Every filter change resets to page 1; filter state is preserved across dimensions via `conexao_leisure_filter_url()`.
- Desktop: dropdown hyperlink menus + active-filter chip row + "Limpar filtros" + result count.
- Mobile: bottom-sheet modal with a "Mostrar resultados" submit.
- Localização popover has a client-side search over server-rendered county options (cosmetic only).
- Town-level filtering does **not** exist — towns are display text only.

### 4.4 Infinite scroll / loading

`assets/js/main.js` `initInfiniteScroll()`:
- Binds to `[data-infinite-scroll]`, uses `IntersectionObserver` (rootMargin 480px), fetches the real `/page/N/` URL from `.conexao-pagination a.page-numbers.next`, deduplicates by `id="post-{ID}"`, appends-only, one history entry per loaded page via `pushState`.
- Status region with `aria-live="polite"`, loading spinner, error + retry, "Você chegou ao fim." end state.
- Strings come from `window.ConexaoI18n` (loading / reachedEnd etc.).
- No-JS: numeric pagination works untouched.

### 4.5 Attraction data model (`content-model.md` § Leisure)

Registered by `conexao-data-model`; admin UI via `conexao-admin-ux` `Conexao_Admin_Ux_Config::leisure_config()`.

Stored fields relevant to location:
- `_leisure_county` (meta; also assigned as `conexao_county` taxonomy term)
- `_leisure_town` (meta)
- `_leisure_address` (meta)
- `_leisure_map_url` (meta)
- `_leisure_internal_page` (boolean meta)
- `_leisure_official_website`, `_leisure_discover_ireland`, `_leisure_website` (legacy)
- Category: `conexao_category` taxonomy; attributes: `conexao_leisure_attribute` taxonomy.

**Confirmed: no latitude/longitude/geolocation field exists anywhere for leisure.** Searches for `_leisure_lat`, `_leisure_lng`, `_leisure_coords`, `latitude`, `longitude`, `geolocation`, `haversine` across `wp-content/` return no leisure coordinate storage. Events have lat/lng only in third-party import fixtures (not this site's leisure data).

### 4.6 Coordinate availability
- **No coordinate fields defined in code:** Confirmed by repository-wide search — no `_leisure_lat`, `_leisure_lng`, `_leisure_coords`, `latitude`, `longitude`, `geolocation`, or `haversine` references exist for leisure in `wp-content/`.
- **Live database state:** Unverified. The code audit can confirm that no coordinate fields are *defined* in the committed code, but it cannot independently prove that no latitude/longitude values exist in the live WordPress database. Database inspection is required in Phase 1 to rule that out.
- Completeness = 0% (no contract exists in code). Format = none. Validation = none.

The only location signals are county (taxonomy + meta), town (meta), and address (meta) — human-readable, not coordinates.

### 4.7 Polylang / translation

- Bilingual PT (default) / EN. `/lazer/` ↔ `/en/lazer/`.
- Leisure records are Polylang-translated posts; `_leisure_*` meta is not language-scoped (meta is shared across translations, consistent with existing practice).
- JS strings: `window.ConexaoI18n` injected via `wp_add_inline_script( 'conexao-main', … )` from `conexao_js_i18n_strings()` in `inc/i18n.php`, gettext domain `conexao-br-irlanda`. `main.js` reads via a local `t(key, fallback)` with Portuguese fallback literals.

### 4.8 Caching

- Theme `inc/cache.php` handles object-cache/transient keys; language-scoped via `conexao_lang_cache_key()` per WP-ES §0.9.
- No existing cache key involves leisure distance or visitor location (visitor location is not stored server-side).

## 5. Confirmed attraction data structure and coordinate availability

| Item | Status | Evidence |
|---|---|---|
| CPT | `leisure`, public, `has_archive`, `show_in_rest` | `docs/content-model.md`, `conexao-data-model` |
| Archive route | `/lazer/` (PT), `/en/lazer/` (EN) | `docs/routing.md`, `archive.php` |
| Template | `archive.php` → `template-parts/leisure-card.php` | `archive.php` L99-129 |
| Grid enhancement | Infinite scroll (`data-infinite-scroll`) + pagination fallback | `archive.php` L120-122, `main.js` `initInfiniteScroll()` |
| Filters | `?county=`, `?categoria=`, `?atributo=` (server-side, URL-driven) | `template-parts/leisure-filters.php`, `inc/archive-filters.php`, `inc/archive-query.php` |
| Location fields stored | `_leisure_county`, `_leisure_town`, `_leisure_address`, `_leisure_map_url` | `docs/content-model.md` § Leisure, `leisure_config()` |
| Latitude field defined in code | **NO** | grep across `wp-content/` — no leisure lat field |
| Longitude field defined in code | **NO** | grep across `wp-content/` — no leisure lng field |
| **Geolocation code** | **NO** | grep for `geolocation`, `navigator.geolocation` — none |
| **Distance calc code** | **NO** | grep for `haversine` — none |
| Coordinate fields defined in code | **NONE** | Repository-wide grep across `wp-content/` found no `_leisure_lat`, `_leisure_lng`, `_leisure_coords`, `latitude`, `longitude`, or `geolocation` references for leisure. |
| Coordinates stored in live database | **UNVERIFIED** | This audit can confirm that no coordinate fields are *defined* in the committed code, but it cannot independently prove that no latitude/longitude values exist in the live WordPress database. Database inspection would be required to rule that out. The plan treats the absence as a working assumption to be verified in Phase 1. |
| Coordinates complete for every attraction? | **N/A — none defined** | No coordinate storage contract exists in code. |
| Coordinates available to front end? | **N/A — none defined** | No mechanism exists to expose coordinates to cards. |

**Conclusion:** No coordinate storage contract exists in the committed code, and no mechanism exposes coordinates to the front end. Whether any coordinate values happen to exist in the live database is unverified and must be checked in Phase 1. Coordinates must be introduced before proximity ordering and distance display can work.


## 6. Recommended technical architecture

### Principle
Progressive enhancement: the server renders `/lazer` exactly as today. JavaScript optionally requests location, calculates distances client-side, and loads/reorders cards only when location is obtained. PHP never receives or stores visitor coordinates.

### Responsibility split

**PHP (server):**
- Renders the leisure archive unchanged (cards, filters, pagination, infinite scroll).
- Provides a lightweight coordinate dataset endpoint (new REST route or admin-ajax-style handler) that returns, for all attractions matching the current filters: attraction ID, latitude, longitude. The endpoint receives the current filter parameters (`?county=`, `?categoria=`, `?atributo=`) and returns public attraction coordinate data only — never visitor data.
- Provides a card-fetch endpoint (or reuses the existing infinite-scroll `/page/N/` mechanism) that returns attraction cards for a given ordered list of IDs, so JS can load cards in proximity order.
- No change to the main query, ordering, filters, or pagination when location is unavailable.
- **PHP does NOT render distance information.** It cannot know whether the visitor granted browser location permission.
- **PHP does NOT render coordinate attributes on cards.** The coordinate dataset fetched by JavaScript is the single source of truth for distances; a JavaScript Map keyed by attraction ID associates calculated distances with cards.

**JavaScript (client, `assets/js/main.js`):**
- On `/lazer` only (detected via `document.body.classList.contains('leisure-page')` or a dedicated marker), once, on `DOMContentLoaded`:
  1. Check `navigator.geolocation` exists and context is secure (HTTPS or localhost).
  2. Render the pre-permission explanation near the heading/filters (see Section 7).
  3. Call `getCurrentPosition()` once (no `watchPosition`), with `enableHighAccuracy: false`, bounded timeout and `maximumAge`.
  4. On success: read visitor coords; fetch the lightweight coordinate dataset for all matching attractions; compute Haversine distances; build a globally sorted list of attraction IDs using a Map keyed by attraction ID; load and render cards in that order (in batches, preserving infinite scroll UI); insert distance values into each card dynamically; communicate "ordenado por proximidade".
  5. On denial/unavailability/timeout/failure: remove the pre-permission explanation if shown; do nothing else — page stays as rendered. No error message, no retry prompt, no repeated request.
- Distance formatting: localized via `window.ConexaoI18n` keys (PT/EN), number formatting per language, distances rounded to one decimal in km; sub-1 km shown in metres. Distances are inserted into cards by JS only after a successful location retrieval.
- Cards without coordinates: appear after geolocated cards in their original relative order; no distance value rendered on them.
- Visitor location is held in memory only for the page lifetime, never written to `localStorage`, cookies, or sent to the server.

### Why client-side calculation
- Avoids transmitting precise visitor coordinates to the server (privacy).
- Avoids per-attraction external API calls (performance, no third-party dependency).
- Haversine in JS is trivial and accurate enough for an "estimated straight-line distance" label.

### Coordinate dataset approach (recommended: Approach A)
The initial page renders only the current batch of cards, so Approach B (embedding all coordinates in the initial HTML) cannot achieve global nearest-first ordering — subsequent batches are not in the DOM. Instead:

1. After location is obtained, JS fetches a compact coordinate dataset (attraction ID + lat + lng) for **all** attractions matching the current filters, from a server endpoint.
2. JS computes Haversine distances in the browser and produces a globally sorted list of attraction IDs.
3. JS loads the attraction cards in that sorted order, in batches, reusing the existing infinite-scroll UI (next-page URL / skeleton markup) but ordered by proximity.
4. Infinite scroll continues to work, following the proximity order rather than the original server order.
5. The visitor's coordinates never leave the browser; the server only provides public attraction coordinate data and receives attraction IDs to retrieve cards.

**Approach C** (server-side proximity-aware query ordering the main query by computed distance) is explicitly not adopted in this phase because it requires transmitting visitor coordinates to the server, which conflicts with the privacy requirement.

### Caching
- No server-side cache change for the base page.
- Visitor location is held in memory only for the page lifetime (a module-level variable), never written to `localStorage`, cookies, or sent to the server.


## 7. Geolocation and permission strategy

### Request model
- Single `navigator.geolocation.getCurrentPosition(success, error, options)` on `/lazer` only.
- `options = { enableHighAccuracy: false, timeout: 10000, maximumAge: 0 }` — one-shot, fresh position, bounded wait. `enableHighAccuracy: false` is sufficient because the feature reports approximate straight-line distance, not navigation-grade precision; it also avoids unnecessarily powering GPS hardware.
- No `watchPosition`. No repeat request after a denial or failure.
- Only on `document.body` leisure-page marker; never on unrelated pages.

### Pre-permission explanation (shown only while awaiting a decision)
- Before the browser permission prompt appears, render a small, non-blocking explanation near the page heading or filters, in the current language:
  - PT: `Encontre atrações perto de si. Permita o acesso à sua localização para ordenar as atrações pela distância.`
  - EN: `Find attractions near you. Allow location access to sort attractions by distance.`
- The copy states that sharing location is optional and is used only to sort nearby attractions. It does not block page rendering.
- The `getCurrentPosition()` call is issued only after this explanation is visible; the browser's own permission prompt follows from that call.
- **Removal rule:** once the visitor makes a decision (grant, deny, or the request times out / fails), the explanation is removed from the page. If permission is denied, unavailable, or times out, the page returns to its original appearance exactly as it was before the explanation appeared — no message, no placeholder, no error is left behind.

### Permission / outcome handling (all must keep the page fully functional)

| Scenario | Behaviour |
|---|---|
| Permission granted, position obtained | Explanation removed. Distances calculated, cards ordered nearest-first globally, "ordered by proximity" communicated. |
| Permission denied (`PERMISSION_DENIED`) | Explanation removed. No UI change; no distance; no repeated request. Page appears as before the request. |
| Previously denied (immediate error) | Same as denied — silent fallback. No explanation is shown (browser denies immediately). |
| Position unavailable (`POSITION_UNAVAILABLE`) | Explanation removed if shown. Silent fallback. |
| Timeout | Explanation removed if shown. Silent fallback. |
| Insecure context (non-HTTPS, non-localhost) / `navigator.geolocation` undefined | Feature not initialised; no explanation shown; silent fallback. |
| Visitor leaves before completion | Pending request is simply abandoned; no unload handling needed since no state is persisted. |
| Invalid visitor coordinates | Guarded (NaN/out-of-range check) → treat as failure, silent fallback. |

### Browser permission persistence
- Request location **once per page load**. If permission was previously granted and the browser retains it, `getCurrentPosition()` should normally complete without showing a new prompt. However, browser permissions may be temporary, one-time, or revoked at any time — the implementation must **not** depend on permission being permanently remembered. Always handle denial, expiration, or permission changes gracefully on each page load.

### Privacy wording (visitor-facing copy)
- Use precise wording that does not overclaim: *"The website does not store your location or send your precise coordinates to its server. Your location is used locally in your browser to calculate estimated distances."* This refers to the website's own handling only — it makes no claim about the browser's or operating system's location services.

### No misleading UI
- No persistent error messages.
- No empty distance placeholders.
- No location status indicator that implies the page depends on location.
- An optional, dismissible, low-prominence "ordenado por proximidade" note only appears after a successful location result (and only while on that page load).

## 8. Distance calculation method

### Formula
Haversine great-circle distance (straight-line, Earth approximated as a sphere, radius 6371 km):
```
a = sin²(Δφ/2) + cos φ1 · cos φ2 · sin²(Δλ/2)
c = 2 · atan2(√a, √(1−a))
d = R · c
```
Implemented in vanilla JS in `main.js` (or a small inline module), no library.

### Inputs
- Visitor: `position.coords.latitude`, `position.coords.longitude` (browser-provided).
- Attractions: a lightweight coordinate dataset fetched from the server endpoint containing attraction ID, latitude, and longitude for every attraction matching the current filters. Attractions without coordinates are included with `null` latitude/longitude and a coordinate status.

### Rounding & formatting
- Distances ≥ 1 km: rounded to one decimal, unit `km`, e.g. `2,5 km` (PT) / `2.5 km` (EN).
- Distances < 1 km: shown in metres, rounded to nearest 10 m or nearest metre, e.g. `400 m` (PT/EN).
- Label format examples: `A 2,5 km de si` (PT) / `A 2,5 km away` (EN); metres: `A 400 m de si`.
- Number formatting follows language conventions: PT uses `,` as decimal separator; EN uses `.`.
- Each distance prefixed with an "approximately" sense (e.g. "cerca de" / "approx.") or visibly described as an estimate so it is never presented as an exact measurement. Wording to be confirmed against Polylang catalogues.

### Missing / invalid coordinates
- The coordinate dataset includes every matching attraction, including those without coordinates (latitude/longitude = `null`, coordinate status = "missing").
- JS sorting: attractions with valid coordinates are sorted by distance ascending; attractions without coordinates are appended after all geolocated attractions in their original archive order; no distance value is rendered for them. All attractions remain available.
- Attractions with invalid coordinates (out of range, non-numeric) are treated as missing.

### What is NOT described as distance
- Never driving distance, travel time, or route. Straight-line estimate only.


## 9. Proximity sorting behavior

### Goal: global nearest-first across the complete catalogue
Proximity ordering must be **global** — nearest first across the complete set of matching attractions, not merely within each infinite-scroll batch. Sorting each batch independently would produce incorrect results (e.g., a 2 km attraction on page 3 appearing after an 18 km attraction on page 1). This is a hard acceptance criterion.

### How global ordering is achieved with infinite scroll
The existing page loads attractions incrementally via infinite scroll, each batch carrying its own server-determined order. Because the initial HTML contains only the current batch, coordinates embedded in that HTML are insufficient for global ordering. The plan therefore uses **Approach A** (lightweight coordinate dataset fetch):

| Approach | Mechanism | Trade-offs |
|---|---|---|
| **A. Lightweight coordinate dataset fetch** (recommended) | After location is obtained, JS fetches a compact dataset (attraction ID + lat + lng for all matching attractions) from a server endpoint, computes distances globally, builds a sorted ID list, and loads cards in that order in batches. | Requires a new lightweight endpoint that returns public attraction coordinate data. Adds one request but keeps pagination/infinite-scroll UI intact. Visitor coordinates never leave the browser. |
| **B. Embed all coordinates in initial HTML** | Not viable for global ordering — the initial HTML contains only the current batch, so coordinates for attractions on subsequent pages are not present in the DOM for JS to reorder. | Rejected: cannot achieve global nearest-first across the complete catalogue. |
| **C. Server-side proximity-aware query** | PHP orders the main query by computed distance using stored attraction coordinates + visitor coords passed as a request parameter. | Requires transmitting visitor coordinates to the server (privacy concern), a new query mechanism, and changes to the main query. Rejected for Phase 1 due to privacy and complexity. |

Implementation with Approach A:
1. After location is granted, JS fetches the coordinate dataset (ID + lat + lng) for all attractions matching the current filters from the server endpoint.
2. JS computes Haversine distances in the browser for every attraction in the dataset.
3. JS builds a globally sorted list of attraction IDs (nearest first), with attractions without coordinates appended at the end in their original archive order.
4. JS loads attraction cards in that sorted order, in batches, reusing the existing infinite-scroll UI but ordered by proximity rather than the original server order.

**Proximity-mode batch-loading behavior:**
- The card-fetch endpoint (or a proximity-mode batch loader) accepts the next slice of sorted attraction IDs and returns server-rendered card HTML for exactly those IDs in the requested order.
- When proximity mode is active, the infinite-scroll mechanism uses this proximity batch loader instead of the default server-ordered `/page/N/` pagination.
- When location is unavailable or denied, the existing pagination and infinite-scroll mechanism remain untouched — cards load in the original server order.
- The initial default-order cards (rendered server-side) remain in place until the proximity results are ready; they are then replaced by the proximity-ordered cards.
- **Failure handling for initial load:** If the coordinate dataset fetch fails, or the first proximity batch fails to load, the original server-rendered card list is preserved. No partial or duplicated card state is left visible. The page falls back to the default order silently.
- **Failure handling for subsequent batches:** If the visitor has already loaded one or more proximity-sorted batches and a later batch fails, all successfully loaded proximity-sorted attractions remain visible in their existing order. The existing infinite-scroll error/retry mechanism is used. Retry resumes from the correct position in the sorted ID list — no IDs are skipped or duplicated. The page is never reverted to the original server ordering after proximity mode has successfully started.

### If filters are active
Proximity ordering applies to the currently filtered set. When a filter changes:
- The existing filter system is **server-side and URL-driven** (`?county=`, `?categoria=`, `?atributo=`). Selecting a filter triggers a full page navigation to the filtered URL, not an AJAX update.
- On a full page reload, JavaScript memory (visitor coordinates) is lost. On reload:
  - If the visitor previously **granted** permission and the browser retains it, `getCurrentPosition()` may complete without showing a new prompt. Location is requested once per page load (see Section 7 — permission persistence is not guaranteed).
  - If the visitor previously **denied** permission, the browser will deny immediately (no new prompt), and the page falls back to the default order. No repeated request is made after denial.
- After the filtered page loads and location is re-obtained, JS fetches the coordinate dataset for the filtered set and applies global proximity ordering to that set.
- Proximity ordering remains active and consistent across filter changes because location is re-requested on each page load and applied to whatever set is rendered.

### Visitor leaves before location completes
The pending `getCurrentPosition()` request is abandoned. No state is persisted, so no unload handling is needed. The page remains fully functional in its default order.

### Attractions without coordinates
- Appear after all geolocated attractions, in their original relative order, with no distance value. All attractions remain available regardless of distance.

### No radius filter
- Not introduced in this phase. All attractions remain available regardless of distance.

## 10. Fallback behavior (location not shared)

This is the critical backward-compatibility requirement. In every scenario below, `/lazer` behaves exactly as today:
- Existing attraction order preserved (server default ordering via `conexao_content_archive_query()`).
- Existing card layout preserved.
- No distance values; no empty placeholders.
- No attractions hidden or removed.
- No persistent error messages.
- Page rendering not blocked.
- No repeated location request after denial/timeout.
- All current functionality (filters, search, pagination/infinite scroll, links) preserved.

This applies to: denial, previous denial, unavailability, timeout, invalid coordinates, insecure context, unsupported browser, JS disabled.

## 11. Privacy and performance considerations

### Privacy
- Location requested only on `/lazer`, once per page load, with `enableHighAccuracy: false` and no continuous tracking.
- A pre-permission explanation (Section 7) states that sharing location is optional, is used only to sort nearby attractions, and is not stored or transmitted.
- Precise visitor coordinates never leave the browser: they are never transmitted to the WordPress server, never stored in cookies/localStorage/WordPress, and held in memory for the page lifetime only.
- No third-party geolocation service; no IP-based fallback.
- **Browser permission and privacy-policy obligations are separate considerations.** This plan does not conclude that a privacy-policy update is unnecessary. Whether the privacy notice (`/politica-de-privacidade/`) or any consent wording requires updating is left to an appropriate privacy review.

### Performance
- One coordinate dataset request after location is granted, returning attraction ID + latitude + longitude for all matching attractions. Then one card-fetch request per batch as the visitor scrolls. No requests per attraction.
- Haversine calculation is O(n) over the coordinate dataset, trivial client-side cost.
- Mobile-friendly: single dataset request, local calculation, no layout-blocking.
- Infinite scroll and pagination unchanged when location is unavailable.
- No render-blocking: the location request and dataset fetch are asynchronous; the page renders attractions immediately in the default order.

### Security
- **New read-only server endpoints:** The proximity feature introduces two lightweight endpoints: (1) a coordinate dataset endpoint returning attraction ID + latitude + longitude for all attractions matching the current filters, and (2) a card-fetch endpoint returning attraction cards for an ordered list of IDs. These endpoints return only public attraction data — never visitor coordinates.
- Endpoint access rules: return only published leisure attractions; respect the current language and active filters (`?county=`, `?categoria=`, `?atributo=`); validate and constrain all incoming parameters; never accept or process visitor coordinates; never expose drafts, private posts, or unrelated post types; preserve the requested attraction order when returning card HTML.
- A public, read-only endpoint may not need authentication or a nonce, but its access rules and input validation must be explicitly defined.
- Data attributes escaped on output (`esc_attr`); numeric values validated on save (range −90..90, −180..180).
- No nonces needed for the location feature itself (no state-changing request; location never leaves the browser).


## 12. UI and accessibility approach

### Where distance appears
- Inside the existing `leisure-card-details` row (county · town), as an additional detail: a location pin/svg + distance text, e.g. `📍 A 2,5 km de si`.
- **PHP does NOT render distance.** It cannot know whether the visitor granted browser location permission. PHP does not render coordinate attributes on cards; the coordinate dataset fetched by JavaScript is the single source of truth.
- **JavaScript inserts the distance dynamically** into each card only after a successful location retrieval and after fetching the coordinate dataset. If location is denied, unavailable, or times out, no distance value is rendered and the card layout is unchanged.
- CSS: reuse existing `.leisure-card-detail` token styling (muted text, `var(--color-text-muted)`), consistent with dark mode via `dark-mode.css`.

### Asynchronous transition behavior
Because the location request is asynchronous, the visitor initially sees the existing attraction order in the default server rendering. Once permission is granted and the proximity dataset is processed, the list transitions to proximity ordering. The implementation must ensure:
- The initial attractions remain fully usable during the location request — no blocking, no disabled interactions.
- The transition to proximity ordering does not cause unnecessary page jumps or layout shifts.
- No duplicated attractions are visible while proximity batches are loading.
- Scroll position is handled sensibly during the transition, especially on mobile — the visitor's position in the list is preserved where practical.
- If proximity loading fails at any point, the original default-order card list remains visible (see failure handling in Section 9).

### Communicating proximity ordering
- A single low-prominence note (e.g. "Ordenado por proximidade" / "Ordered by proximity") inserted near the archive header only after a successful location result; dismissed naturally on navigation. Not a persistent banner. The note is removed along with the pre-permission explanation if location is not granted.

### Location / retry control
- No manual "retry location" button (permission denial is sticky in browsers; a retry would repeat a denied prompt). Documented as an open UX decision.
- No location status indicator that implies dependency.

### Responsive
- Fits within the existing card grid (`.leisure-page .events-grid`, `minmax(340px, 1fr)` desktop, 1 column ≤768px). Distance text is short; no card redesign.
- Dark mode: reuse `--color-text-muted`; no new colour tokens.

### Accessibility
- Distance text is plain visible text (no icon-only meaning); `aria-label` on the detail where helpful.
- Keyboard navigation unaffected (no new focusable controls).
- Screen-reader-friendly: distance announced as part of the card; the "ordered by proximity" note uses `aria-live="polite"` if dynamically inserted.
- **Focus preservation on reorder:** when cards are loaded/reordered after geolocation resolves, the currently focused element (if inside a card that moves) retains focus. Card insertion is performed without removing/re-adding focusable elements, and no focus trap is introduced. If no element was focused, no focus is set.
- **Communicating reordering:** when proximity ordering is applied, an `aria-live="polite"` announcement informs screen-reader users that the list has been reordered by proximity (e.g. "Atrações ordenadas por proximidade" / "Attractions ordered by proximity"). This is announced once, not on every subsequent re-sort.
- Reduced motion: no animations introduced for reordering (simple DOM operations); if a future phase animates, respect `prefers-reduced-motion`.
- Reordering via DOM move can shift focus only if a focused element moves; cards aren't focusable containers, so normal keyboard navigation is preserved.

## 13. Translation considerations

- All new user-facing text uses the `conexao-br-irlanda` gettext domain via `conexao_js_i18n_strings()` (PT source) and the Polylang translation workflow for EN.
- New JS string keys needed (examples):
  - `leisureDistanceKm` → `A %s km de si` / `A %s km away` (with number formatted by JS per locale).
  - `leisureDistanceM` → `A %s m de si` / `A %s m away`.
  - `leisureOrderedByProximity` → `Ordenado por proximidade` / `Ordered by proximity`.
  - `leisureApproximately` → `cerca de` / `approx.` (if used as a prefix).
- Number formatting: JS `Intl.NumberFormat` per language (`pt-BR` → `2,5`, `en` → `2.5`) OR PHP-prepared templates. Decision to be made in implementation; `Intl.NumberFormat` is widely supported.
- PT is canonical and immutable; EN strings are translations via the existing rollout engine. No PT slug/URL change.
- Translation completeness gate: any new string must reach the EN translation gate before being considered complete on `/en/lazer/`.


## 14. Implementation phases

### Phase 1 — Data readiness (prerequisite for launch)
**Goal:** Introduce the coordinate storage contract, add latitude/longitude fields to the attraction editor with validation, make coordinates manageable through the existing WordPress admin, and establish a process to populate coordinates for existing attractions. Coordinate availability is a prerequisite for launching the feature, not optional future work.
- **Affected files:** `docs/content-model.md` (document contract), `wp-content/plugins/conexao-admin-ux/includes/class-config.php` (leisure editor fields: `_leisure_lat`, `_leisure_lng`), `wp-content/plugins/conexao-data-model/includes/class-meta.php` (meta registration + sanitization), `wp-content/plugins/conexao-content/seed-leisure.php` (optional seed/migration script for populating existing attractions).
- **Dependencies:** None.
- **Activities:**
  1. Verify whether any latitude/longitude values exist in the live WordPress database (database inspection; the code audit alone cannot rule this out).
  2. Register `_leisure_lat` / `_leisure_lng` as `leisure` post meta with `register_post_meta()` (sanitize numeric, range −90..90 / −180..180, `show_in_rest => true`).
  3. Add latitude and longitude fields to the `conexao-admin-ux` leisure editor (`leisure_config()`), in the existing "Localização e contato" section, with number inputs and validation help text.
  4. Validate on save: reject out-of-range or non-numeric values with a clear admin error; never save invalid coordinates.
  5. Identify which existing attractions have coordinates and which are missing them (admin list view column or audit report).
  6. Establish a process to populate coordinates for existing attractions (manual entry via admin, or a one-time seed/migration script — not automatic geocoding on every page visit). **Record the source and verification status of each coordinate** (e.g. "map pin verified", "not yet verified") so inaccurate coordinates do not produce inaccurate distances. Coordinates must never be guessed or populated from unverified addresses.
  7. Any bulk coordinate population follows the repository's existing planning, dry-run, snapshot, apply, and verification safeguards (six-step content-change contract).
  8. Document the storage contract in `docs/content-model.md`.
- **Risks:** Coordinate population is an editorial task; if existing attractions are not populated, the feature will have little visible effect at launch. Inaccurate coordinates produce inaccurate distances even when the calculation is correct.
- **Acceptance:** `_leisure_lat` / `_leisure_lng` fields exist in the leisure editor with validation; a report identifies which attractions have missing coordinates and their verification status; storage contract documented in `docs/content-model.md`.
- **Testing:** PHP unit tests for meta sanitization/validation; admin UI test that latitude/longitude fields render and save correctly.

### Phase 2 — Geolocation foundation
**Goal:** One-shot browser location request on `/lazer` only, preceded by a pre-permission explanation that is removed after a decision, with silent fallback in every failure/denial scenario; page unaffected when location unavailable.
- **Affected files:** `wp-content/themes/conexao-br-irlanda/assets/js/main.js` (new `initLeisureProximity()` + pre-permission explanation UI + removal logic), `inc/i18n.php` (new pre-permission explanation string keys), `archive.php` (leisure-page marker — reuse `leisure-page` class).
- **Dependencies:** Phase 1 contract (keys named, even if no data yet).
- **Activities:** Implement `initLeisureProximity()`: detect leisure page, secure-context check, render pre-permission explanation near heading/filters, single `getCurrentPosition()` with `enableHighAccuracy: false`, store visitor coords in a module variable, remove the explanation after any decision (grant/deny/timeout/failure), handle all error callbacks as silent no-ops. Wire into `DOMContentLoaded` alongside existing inits. No distance UI yet (that comes in Phase 3).
- **Risks:** JS errors breaking the page — mitigated by try/catch and feature detection; pre-permission explanation must not block rendering or shift layout and must be removed on denial.
- **Acceptance:** On grant, coords held in memory; explanation removed; on denial/timeout/unsupported, page identical to baseline (explanation removed, no distance). No console errors.
- **Testing:** Functional tests 1–5, 14; regression 1.

### Phase 3 — Distance calculation
**Goal:** Haversine calculation in the browser using the coordinate dataset; formatted, estimated distance values; handle missing coordinates.
- **Affected files:** `assets/js/main.js` (Haversine + formatter), `inc/i18n.php` (new distance string keys).
- **Dependencies:** Phase 2; coordinate dataset available from the server endpoint.
- **Activities:** Implement Haversine + formatting (km/m, one decimal, language number format, "approximately" estimate wording). Use the coordinate dataset (fetched from the server endpoint) as the source of distances — not card attributes. Attach distances to cards in memory using a JS Map keyed by attraction ID. No rendering yet.
- **Risks:** Number formatting inconsistencies across locales; invalid coordinates.
- **Acceptance:** Correct distances for known coordinate pairs; metres for <1 km; no distance for missing coords.
- **Testing:** Functional tests 6–9; unit-style JS checks.


### Phase 4 — Proximity ordering
**Goal:** Load attraction cards in global proximity order (nearest first across the complete catalogue); preserve filters/search/pagination; communicate ordering.
- **Affected files:** `assets/js/main.js` (fetch coordinate dataset, compute Haversine, build sorted ID list, load cards in proximity order in batches, infinite-scroll integration), `archive.php` (no change expected).
- **Dependencies:** Phase 3 (Haversine + formatter), Phase 1 (coordinate data exists).
- **Activities:** On location grant, fetch the lightweight coordinate dataset (ID + lat + lng) for all attractions matching the current filters from the server endpoint; compute Haversine distances in the browser; build a globally sorted list of attraction IDs; load attraction cards in that sorted order in batches, reusing the existing infinite-scroll UI but ordered by proximity; insert distance values into each card dynamically; insert "ordered by proximity" `aria-live="polite"` announcement once after initial load. On filter change (full page reload), location is re-requested on the new page load; if granted, the coordinate dataset is fetched again for the filtered set and proximity ordering is applied. Preserve focus during card insertion/loading.
- **Risks:** New server endpoint required for coordinate dataset; card-fetch endpoint must return cards for arbitrary ID lists in a given order; interaction between infinite scroll and proximity-ordered loading; focus management during dynamic card loading.
- **Acceptance:** Cards loaded nearest-first globally when location granted; infinite scroll follows proximity order; filters still work; no distant attraction removed; pagination/infinite scroll intact; focus preserved; screen-reader announcement on initial load.
- **Testing:** Functional tests 8–13; regression 1–3.

### Phase 5 — UX, accessibility, and translations
**Goal:** JS inserts distance detail into cards after successful location retrieval; verify mobile/desktop/light/dark; translations complete; accessibility review.
- **Affected files:** `assets/js/main.js` (insert distance detail into cards dynamically), `assets/css/leisure.css` (minor, reuse tokens), `inc/i18n.php` (string keys), `.pot`/translation catalogue.
- **Dependencies:** Phase 4.
- **Activities:** Implement JS-side insertion of distance detail into each card's `leisure-card-details` row only after a successful location retrieval; CSS reuse existing `.leisure-card-detail`; translate new strings; a11y review (aria-live note, keyboard, screen reader, reduced motion). PHP template does NOT render distance — it does not render coordinate attributes either; the coordinate dataset fetched by JavaScript is the single source of truth.
- **Risks:** Layout shift when distance values appear after location resolves; dark-mode contrast; JS insertion must not break existing card markup.
- **Acceptance:** Distance fits card without layout shift; dark mode contrast ≥ AA; strings translated EN; keyboard/screen reader OK; distance appears only when location granted.
- **Testing:** Responsive + accessibility tests; regression 4–6 (light/dark, translations).

### Phase 6 — Verification
**Goal:** Tests, gates, documentation, limitations recorded.
- **Affected files:** `wp-content/themes/conexao-br-irlanda/tests/test-leisure-proximity.php` (new), `docs/plans/` (this plan), `docs/evidence/` (test output).
- **Dependencies:** All prior phases.
- **Activities:** Add in-process PHP tests + JS-behaviour assertions where feasible; run `./scripts/run-tests.sh`; run permanent gates; write report in `docs/evidence/<date>-<stage>/`.
- **Risks:** Test environment cannot grant browser geolocation permission — tests must mock/feature-detect; document limitation.
- **Acceptance:** `./scripts/run-tests.sh` green (or PASS WITH LIMITATION where geolocation cannot be simulated); gates pass.
- **Testing:** Full suite + new focused tests.


## 15. Files and components likely to change

| Path | Likely change | Why |
|---|---|---|
| `wp-content/themes/conexao-br-irlanda/assets/js/main.js` | Add `initLeisureProximity()` + pre-permission explanation + Haversine + formatter + coordinate dataset fetch + proximity-ordered card loading | Client-side geolocation, distance, global ordering |
| `wp-content/themes/conexao-br-irlanda/inc/i18n.php` | Add pre-permission explanation + distance/proximity string keys to `conexao_js_i18n_strings()` | Polylang gettext source for JS strings |
| `wp-content/themes/conexao-br-irlanda/template-parts/leisure-card.php` | No coordinate attributes (coordinate dataset is single source of truth); CSS hooks for JS-inserted distance detail | Coordinate exposure via dataset, not card attributes |
| `wp-content/themes/conexao-br-irlanda/assets/css/leisure.css` | Minor (reuse existing tokens; possible `.leisure-card-detail--distance`) | Card styling, dark mode |
| `wp-content/plugins/conexao-admin-ux/includes/class-config.php` | Add `_leisure_lat` / `_leisure_lng` fields to leisure editor | Coordinate management in WP admin |
| `wp-content/plugins/conexao-data-model/includes/class-meta.php` | Register `_leisure_lat` / `_leisure_lng` post meta with numeric validation | Storage contract + sanitization |
| `wp-content/plugins/conexao-content/seed-leisure.php` | Optional seed/migration script for populating existing attraction coordinates | Coordinate population process |
| `wp-content/themes/conexao-br-irlanda/archive.php` | Leisure-page marker (reuse `leisure-page` class — likely no change) | Detect `/lazer` in JS |
| `wp-content/themes/conexao-br-irlanda/functions.php` or plugin | New lightweight coordinate dataset endpoint (ID + lat + lng for all matching attractions) | Server provides public attraction coordinates to JS |
| `wp-content/themes/conexao-br-irlanda/functions.php` or plugin | New card-fetch endpoint (cards for ordered ID list) or reuse infinite-scroll `/page/N/` | JS loads cards in proximity order |
| `wp-content/themes/conexao-br-irlanda/tests/test-leisure-proximity.php` | New test file | Regression + functional coverage |
| `docs/content-model.md` | Document `_leisure_lat` / `_leisure_lng` contract | Data model of record |
| `docs/themes/conexao-br-irlanda.md` | Note proximity enhancement (optional) | Theme internals doc |

**Files explicitly NOT expected to change:**
- `plugins.json` (no plugin added/removed).
- Canonical Portuguese content / slugs / URLs.
- `single-leisure.php` (out of scope; this plan covers the archive only).
- Flutter/mobile repository (out of scope).

## 16. Testing and regression strategy

### Functional tests (new `test-leisure-proximity.php` + JS assertions)
1. Location permission granted → distances present, nearest-first order **globally across all attractions**.
2. Permission denied → page identical to baseline; no distance, no repeat request.
3. Location unavailable → silent fallback.
4. Geolocation timeout → silent fallback.
5. Invalid visitor coordinates → silent fallback (guarded).
6. Pre-permission explanation visible before the browser permission prompt.
7. Pre-permission explanation removed after grant, deny, timeout, or failure.
8. Attraction with valid coordinates → distance computed.
9. Attraction without coordinates → no distance; appears after geolocated cards.
10. Attractions ordered by increasing distance **across the complete catalogue, not per infinite-scroll batch**.
11. Distance displayed correctly (km one decimal; metres <1 km; language number format).
12. All attractions remain available including distant ones (none removed).
13. Existing filters continue working (county/categoria/atributo) after full page reload; proximity ordering re-applied on filtered set.
14. Existing search continues working.
15. Infinite scroll follows the global proximity order; cards are loaded in batches in proximity order.
16. Coordinate dataset endpoint returns ID + lat + lng for all attractions matching current filters.
17. Page works without JavaScript / geolocation (server render unchanged).
18. Filter change (full reload) re-requests location; no prompt shown if already granted; silent fallback if previously denied.
19. Coordinate dataset endpoint returns ID + lat + lng for every matching attraction, including those without coordinates (null lat/lng).
20. Coordinate dataset endpoint never accepts visitor coordinates; returns only published leisure attractions; respects filters and language.
21. Proximity batch failure: original default-order card list preserved; no partial/duplicated cards; silent fallback to default order.
22. Asynchronous transition: initial attractions remain usable during location request; no page jumps or duplicated cards; scroll position preserved where practical.

### Responsive and accessibility tests
- Mobile layout (≤768px): distance fits card, no overflow.
- Desktop layout.
- Keyboard navigation: no new focusable controls; tab order unchanged; focus preserved during dynamic card loading.
- Screen-reader: distance is visible text; proximity note `aria-live="polite"`; reordering announcement once.
- Loading/status: no blocking indicators, no persistent errors.
- No animation/reduced-motion barriers (simple DOM operations).

### Regression tests
- Existing ordering when location unavailable.
- Existing cards and links.
- Existing filters and search.
- Existing translations (PT unchanged; EN strings added).
- Light and dark modes (contrast via existing tokens).
- No changes to unrelated WordPress pages (geolocation only on `/lazer`).

### Execution
- Run `./scripts/run-tests.sh` (all layers, one aggregate exit code).
- Permanent invariant gates (Stage L) run inside the suite — fail closed.
- Browser geolocation permission cannot be reliably simulated in the PHP test suite; document this limitation and rely on JS unit checks + manual browser verification for the permission matrix.


## 17. Risks, dependencies, and open decisions

### Risks
| Risk | Likelihood | Mitigation |
|---|---|---|
| Coordinates missing for most attractions → feature has little effect | High | Phase 1 makes coordinate population a launch prerequisite; admin fields + validation added; missing-coordinate report identifies gaps. |
| Live DB may already contain unverified coordinates | Medium | Phase 1 includes database inspection to check for existing lat/lng values before defining the storage contract. |
| New server endpoint for coordinate dataset adds surface area | Low/Medium | Endpoint returns only public attraction coordinate data (ID + lat + lng), never visitor data; no authentication needed; no state changes. |
| Card-fetch endpoint must return cards for arbitrary ID lists in a given order | Medium | Reuse existing infinite-scroll `/page/N/` mechanism where possible; otherwise a lightweight custom endpoint. |
| Interaction between infinite scroll and proximity-ordered loading | Medium | Infinite scroll continues to load batches, but in proximity order rather than server order; test batched loading carefully. |
| Browser geolocation cannot be mocked in PHP test suite | Medium | Rely on JS unit checks + manual browser verification; state limitation in report. |
| Layout shift when distance values appear after location resolves | Low | Distance is short text inside existing details row; cards keep fixed grid. |
| Focus lost during dynamic card loading | Low | Focus preservation explicitly implemented; cards aren't focusable containers. |
| Scope creep (adding maps, radius filter, persistent visitor storage) | Medium | Explicitly non-goals; each is a separate future phase with its own plan. |

### Dependencies
- Coordinate data must exist (editorial population via Phase 1 admin fields) for the feature to be visibly useful — this is part of the implementation scope, not deferred.
- New server endpoints required: lightweight coordinate dataset (ID + lat + lng) and card-fetch (or reuse of infinite-scroll `/page/N/`).
- No new plugins/dependencies (WP-ES §0; `plugins.json` unchanged).
- `Intl.NumberFormat` for number formatting — universally supported in modern browsers; fallback to manual replace.

### Open decisions (to be resolved during implementation, documented not guessed)
1. Exact meta key names (`_leisure_lat`, `_leisure_lng` proposed — to be confirmed).
2. Endpoint implementation: new custom REST route vs. reuse of existing admin-ajax/infinite-scroll mechanisms.
3. No coordinate attributes on cards — the coordinate dataset fetched by JavaScript is the single source of truth.
4. Exact EN/PT distance wording and the "approximately" prefix — to be authored via gettext and validated against the translation catalogue.
5. Pre-permission explanation copy — PT/EN strings to be authored via gettext; proposed wording in Section 7.
6. Whether a manual retry control is offered (recommended: no, because browser-denied permission is sticky).
7. Whether proximity ordering survives a full page reload (it does not — location is per-page-lifetime; on filter-triggered reload, location is re-requested and no prompt is shown if already granted).
8. Privacy policy / consent wording update requirement — left to an appropriate privacy review; not decided by this plan.

## 18. Acceptance criteria

### Plan completeness (this task)
The plan is complete when:
1. All 17 sections above are filled with repository-verified facts (confirmed) or clearly-labelled recommendations/assumptions.
2. Coordinate storage contract is documented; the distinction between "no fields in code" and "unverified in live DB" is stated.
3. No application code or production content has been changed (verified: `git status` clean; this task only adds `docs/plans/...`).
4. Open questions are documented as open, not guessed.
5. The plan distinguishes confirmed facts from recommendations and assumptions throughout.

### Implementation acceptance (for a future phase, not this task)

| Requirement | Expected result |
|---|---|
| Location granted | Attractions ordered nearest first **globally across the complete catalogue** |
| Distances | Estimated distance displayed on each attraction with coordinates (km one decimal; metres <1 km; language number format; prefixed as an estimate) |
| Global ordering | Nearest-first across the complete attraction catalogue, not per infinite-scroll batch |
| Missing coordinates | Attraction remains visible, without a distance; appended after geolocated cards; included in coordinate dataset with null lat/lng |
| Coordinate dataset | Endpoint returns every matching attraction (with or without coordinates); never accepts visitor coordinates; respects filters and language |
| Card-fetch endpoint | Returns server-rendered cards for an ordered ID list in the requested order; preserves requested order |
| Location denied | Existing page behavior preserved; no distance, no repeat request; pre-permission explanation removed |
| Location unavailable | Existing page behavior preserved; pre-permission explanation removed if shown |
| Location timeout | Existing page behavior preserved; pre-permission explanation removed if shown |
| Pre-permission explanation | Visible near heading/filters before the browser permission prompt; states location sharing is optional; removed after any decision |
| Filters | County, category, and attribute filters continue working after full page reload; proximity ordering re-applied on the filtered set |
| Infinite scroll | Follows global proximity order when location granted; default server order when location unavailable; no conflicting or incorrectly ordered batches |
| Asynchronous transition | Initial attractions remain usable during location request; no page jumps or duplicated cards during batch loading; scroll position preserved where practical; failure preserves original default-order list |
| Mobile | Distance information fits naturally into cards; no overflow |
| Translations | Portuguese and English both supported via `conexao-js-i18n` strings |
| Privacy | Precise visitor coordinates stay in the browser; never transmitted to WordPress; never stored persistently |
| Coordinates | Administrators can maintain attraction coordinates via the existing WP admin editor with validation; source and verification status recorded |
| Accessibility | Focus preserved during dynamic card loading; screen-reader announcement on reordering; keyboard navigation unchanged |

## 19. Estimated implementation complexity

**Overall: Moderate-High.** The feature includes server-side coordinate management (admin fields, validation, meta registration, new endpoints) plus client-side geolocation, coordinate dataset fetch, Haversine calculation, proximity-ordered card loading in batches, filter-change handling, translations, and accessibility. The expanded scope reflects the requirement that coordinate management be part of the implementation and that global proximity ordering work across the complete catalogue with infinite scroll.

Assumptions behind this estimate:
- Coordinate fields are added to the existing `conexao-admin-ux` leisure editor (Phase 1); no new admin screen is created.
- New lightweight server endpoints are created for the coordinate dataset and card-fetch (or existing infinite-scroll `/page/N/` is reused).
- All attractions can be sorted client-side from the coordinate dataset (no server-side proximity query).
- A single one-shot geolocation request per page load is sufficient.
- `enableHighAccuracy: false` is sufficient for approximate straight-line distance.
- No third-party mapping/routing API is introduced.

If any assumption changes (e.g., server-side proximity query adopted, or card-fetch endpoint proves complex), complexity increases and a new plan amendment is required.

## Confirmation

- **Current state of `/lazer`:** Leisure CPT archive rendered by `archive.php` → `template-parts/leisure-card.php`; infinite scroll (`data-infinite-scroll`) with pagination fallback; server-side county/category/attribute filters; no location, distance, or proximity functionality; no coordinate storage contract in code.
- **Attraction coordinates:** No coordinate fields are defined in the committed code (confirmed by repository-wide search). Whether any coordinate values exist in the live WordPress database is unverified — database inspection is required in Phase 1 to rule this out.
- **Recommended technical approach:** Progressive enhancement — pre-permission explanation near the heading/filters, then one-shot browser `navigator.geolocation.getCurrentPosition()` on `/lazer` only (`enableHighAccuracy: false`); client-side Haversine straight-line distance; JS fetches a lightweight coordinate dataset (ID + lat + lng) for all matching attractions from a server endpoint, computes distances globally, and loads attraction cards in proximity order in batches; PHP never receives visitor coordinates. Coordinate management (admin fields + validation) is part of Phase 1 as a launch prerequisite.
- **Filter interaction:** Existing filters are server-side URL-driven (full page reload). On reload, location is re-requested; no browser prompt is shown if already granted; silent fallback if previously denied. Proximity ordering is re-applied globally to the filtered set.
- **Proposed implementation phases:** 1 Data readiness (coordinate management as prerequisite) → 2 Geolocation foundation → 3 Distance calculation → 4 Proximity ordering → 5 UX/accessibility/translations → 6 Verification.
- **Main risks/dependencies:** Coordinates must be editorially populated via Phase 1 admin fields; live-DB coordinate check required; new server endpoints for coordinate dataset and card-fetch; geolocation can't be simulated in the PHP suite; no new plugins/dependencies.
- **Plan document path:** `docs/plans/2026-11-01-lazer-location-services.md`
- **Implementation/production changes made:** None. This task is planning only; no code or production content was modified.

**Status: PASS** — The audit is complete and the implementation plan is documented with all critical technical decisions supported by repository evidence. Coordinate management is included as a launch prerequisite; global proximity ordering via Approach A (coordinate dataset fetch) is a hard acceptance criterion; filter interaction and pre-permission explanation are fully specified; privacy-policy and accessibility focus decisions are left to appropriate reviews rather than assumed.

