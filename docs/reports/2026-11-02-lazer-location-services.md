# Report — Lazer location services (proximity ordering + distance estimates)

| | |
|---|---|
| **Stage / task name** | Location-based attractions and distance estimates on `/lazer` |
| **Date** | 2026-11-02 |
| **Author / agent** | Cline (AI coding agent) |
| **Branch** | `leisure-location` |
| **Start SHA** | `92e42aa150de8861f8841c822198d2735fa19ca6` |
| **Final SHA** | uncommitted (working tree intentionally left dirty; see §3) |
| **Working tree at finish** | dirty — this task's files, plus two documentation files and one report that were already modified/added by another session (see §3, "not ours") |
| **Skills followed** | `wp-repository`, `wp-add-content-type`, `wp-add-admin-screen`, `wp-security-review`, `wp-frontend-perf`, `wp-write-in-process-test`, `wp-testing`, `wp-update-docs` |
| **Authoritative docs consulted** | `AGENTS.md`, `docs/engineering-standard.md`, `docs/content-model.md`, `docs/routing.md`, `docs/themes/conexao-br-irlanda.md`, `docs/testing.md`, `plugins.json`, `docs/plans/2026-11-01-lazer-location-services.md` |

## 1. Scope completed

The approved plan (`docs/plans/2026-11-01-lazer-location-services.md`) is
implemented in the WordPress repository only:

1. **Coordinate data model + admin** — `_leisure_lat` / `_leisure_lng` meta
   registered for `leisure` with range validation, plus editorial provenance
   (`_leisure_coord_source`, `_leisure_coord_verified`); a `coordinate` field
   type added to the existing admin-ux leisure editor inside the existing
   "Localização e contato" section; a read-only "Coordenadas" inventory column
   on the leisure list.
2. **Browser geolocation** — one-shot `getCurrentPosition()` on `/lazer/` and
   `/en/lazer/` only, preceded by a visible explanation that is removed when
   the request resolves.
3. **Distance calculation** — client-side Haversine in a pure, Node-testable
   module; locale-aware km/m formatting.
4. **Read-only endpoints** — `GET /conexao/v1/leisure-coordinates` and
   `GET /conexao/v1/leisure-cards`, reusing the archive's own filter builder.
5. **Global proximity ordering + proximity-aware infinite scroll** — the whole
   matching catalogue is sorted, not per batch; coordinate-less attractions are
   appended last in their original order; failures fall back silently.
6. **Distance display + accessibility** — distances inserted into the existing
   `leisure-card-details` row, one `aria-live` announcement, focus/scroll
   preservation, no layout redesign.
7. **Tests** — in-process PHP (theme + plugin), Node unit tests, and a
   Playwright browser acceptance script with geolocation mocks.
8. **Documentation** — content model + theme docs + this report + evidence.

## 1a. Confirmed implementation details

**Coordinate management (plan Phase 1).** `_leisure_lat` / `_leisure_lng` are
registered with `register_post_meta()` for `leisure`
(`show_in_rest: true`, `type: string`, a custom `sanitize_callback`); the
provenance fields `_leisure_coord_source` and `_leisure_coord_verified` are
registered with `show_in_rest: false`. The sanitizer is the single validation
rule and runs on **every** write path (`update_metadata()` calls
`sanitize_meta()`), so an invalid value can never be stored. The editor exposes
the fields in the existing "Localização e contato" section through a new
`coordinate` field type; `Conexao_Admin_Ux_Fields::validate()` adds the
pair/range messages, and the save path preserves a previously valid value when
a submission is invalid. The leisure list gains a "Coordenadas" column that
reports the four inventory states via
`Conexao_Data_Model_Meta::coordinate_status()`.

**Endpoints (plan Phase 4).** `inc/leisure-location.php` registers two public,
read-only REST routes under `conexao/v1`:

- `GET /wp-json/conexao/v1/leisure-coordinates` → `{ lang, count, items[] }`
  with `{ id, lat|null, lng|null, index }`; `index` is the original archive
  position.
- `GET /wp-json/conexao/v1/leisure-cards?ids=…` → `{ ids, count, html }`, the
  server-rendered `template-parts/leisure-card.php` markup in the requested
  order.

Both reuse `conexao_leisure_archive_tax_query()` (extracted from the main
archive query) for the county/category/attribute filters and mirror the
archive's B2 language widening (`lang=en,pt` + translation-replaced PT ids) for
`/en/lazer/`. `ids` must be positive integers, de-duplicated, ≤ 50; invalid
input answers 400; ids outside the matching set are dropped. Neither endpoint
accepts, stores or returns visitor coordinates; neither exposes provenance.

**Geolocation (plan Phase 2).** `initLeisureProximity()` in `main.js` runs only
when `window.ConexaoLeisureLocation` and the `conexao-leisure-location` body
class are both present (leisure archive only). It inserts the explanation near
the archive heading, then calls `navigator.geolocation.getCurrentPosition()`
once with `{ enableHighAccuracy: false, timeout: 10000, maximumAge: 0 }`. Every
outcome (grant, deny, previously-denied, unavailable, timeout, unsupported,
insecure, invalid coordinates) ends with the explanation removed; all failures
are silent no-ops.

**Global sorting (plan Phase 5).** `sortByProximity()` in
`leisure-proximity.js` computes a Haversine distance (Earth radius 6 371 000 m)
for every dataset entry with valid coordinates, sorts ascending with the
original archive index as a stable tie-break, then appends coordinate-less
attractions in their original relative order. Nothing is removed; if nothing is
geolocated the page keeps its default order.

**Infinite-scroll integration (plan Phase 5).** `initInfiniteScroll()` gained a
`loader` indirection (default `null` = the original `/page/N/` loader) plus a
`grid.conexaoInfiniteScroll.takeOver()` control and a generation counter that
discards an in-flight default fetch after a take-over. Proximity mode installs
a loader that slices the globally sorted ID list, fetches the cards, appends
them in the requested order and only advances its cursor on success — so a
failed batch is retried with the identical slice. Proximity batches never
`pushState`, and activation resets any `/page/N/` URL.

**Distance presentation (plan Phase 6).** Distances are inserted by JavaScript
into the existing `.leisure-card-details` row as
`<span class="leisure-card-detail leisure-card-distance">` with visible text
(`A aproximadamente 2,5 km de si` / `Approximately 2.5 km away`), formatted with
`Intl.NumberFormat` for the active locale. PHP never renders a distance and
never renders coordinates on a card. A single `aria-live="polite"` region
announces the reorder once, focus and scroll are preserved across the swap, and
the "Ordenado por proximidade" note appears only when proximity ordering
activated.

## 1b. Permanent gate results

`./scripts/run-tests.sh --scripts` (the Stage L gates, discovered by
convention): **19 suites, 18 passed, 1 failed**. The single failure is the
pre-existing `i18n_freshness` staleness, reported by the gate itself as
`1 pre-existing, 0 new` — i.e. this change introduces no new gate violation
(it regenerates the catalogue). The other permanent gates
(agent-governance, cache-key scoping, documentation drift, release integrity,
script conventions, secret scan, taxonomy policy, translation completeness,
language-scoped caching, redirect precedence, i18n freshness) all pass.

## 1c. Remaining coordinate-data requirements (launch prerequisites)

The feature is implemented but **not yet visible to visitors**, because no
attraction has coordinates. Before it can launch:

1. Populate `_leisure_lat` / `_leisure_lng` for each attraction through the
   leisure editor (or a one-time importer following the repository's six-step
   content-change contract: inventory → manifest → dry-run → snapshot →
   idempotent apply → verify with a numeric gate).
2. Confirm each pair against the attraction's real map location and tick
   "Coordenadas verificadas", recording the source.
3. Watch the leisure list's "Coordenadas" column until "Sem coordenadas" and
   "Por verificar" are empty (or explicitly accepted).
4. Never invent or geocode coordinates from an unverified address.

## 2. Scope NOT completed

- **No production coordinate population.** The plan makes coordinate population
  a *launch prerequisite*, not part of this task. This task introduces the
  fields, validation, provenance and inventory; it writes **no** coordinates to
  any database. Consequence: on any environment whose attractions have no
  coordinates, the feature is correctly inert (default order, no distances).
- **Production inventory not verified.** There is no read-only access to the
  live WordPress.com database from this repository, so the live coordinate
  inventory cannot be checked. Recorded as a limitation (§13).
- **No `watchPosition`, no IP fallback, no distance filter, no travel time** —
  explicitly out of scope per the plan.
- **No new plugin, dependency or third-party service.** `plugins.json` is
  unchanged.

## 3. Files added / modified / deleted

**Added (7)** — all in-repository:

| Path | Note |
|---|---|
| `wp-content/themes/conexao-br-irlanda/inc/leisure-location.php` | The two read-only REST endpoints, the shared query-args builder, the JS config payload and the `conexao-leisure-location` body class. |
| `wp-content/themes/conexao-br-irlanda/assets/js/leisure-proximity.js` | Pure proximity helpers (Haversine, formatting, global ordering, geolocation classification, batch cursor); browser + CommonJS. |
| `wp-content/themes/conexao-br-irlanda/tests/test-leisure-location-services.php` | In-process theme suite (102 assertions). |
| `wp-content/plugins/conexao-admin-ux/tests/test-leisure-coordinates.php` | In-process plugin suite for the admin field (29 assertions). |
| `tests/js/test-leisure-proximity.mjs` | Node unit suite for the pure helpers (68 assertions). |
| `tests/browser/verify-lazer-proximity-browser.py` | Playwright browser acceptance with geolocation mocks (27 assertions). |
| `docs/reports/2026-11-02-lazer-location-services.md` + `docs/evidence/2026-11-02-lazer-location-services/` | This report and its machine evidence. |

**Modified (15)**:

| Path | Change |
|---|---|
| `wp-content/plugins/conexao-data-model/includes/class-meta.php` | Registers the four leisure coordinate meta keys; adds `sanitize_coordinate()` / `sanitize_latitude()` / `sanitize_longitude()` / `is_valid_latitude()` / `is_valid_longitude()` / `get_coordinates()` / `coordinate_status()`. |
| `wp-content/plugins/conexao-admin-ux/includes/class-config.php` | Adds the latitude/longitude/provenance fields to the existing leisure location section and the "Coordenadas" list column. |
| `wp-content/plugins/conexao-admin-ux/includes/class-fields.php` | Adds the `coordinate` field type (render + save) and `sanitize_coordinate_value()`; adds leisure coordinate-pair validation to `validate()`. |
| `wp-content/plugins/conexao-admin-ux/includes/class-list.php` | Adds `render_coordinates()` (the inventory column renderer). |
| `wp-content/themes/conexao-br-irlanda/inc/archive-query.php` | Extracts `conexao_leisure_archive_tax_query()` (the single filter interpreter) and refactors the main-query leisure branch to use it — behaviour-preserving. |
| `wp-content/themes/conexao-br-irlanda/inc/assets.php` | Enqueues `leisure-proximity.js` on the leisure archive only, as a dependency of `main.js`; injects `window.ConexaoLeisureLocation`. |
| `wp-content/themes/conexao-br-irlanda/inc/i18n.php` | Adds four JS strings (explanation, distance template, proximity note, announcement). |
| `wp-content/themes/conexao-br-irlanda/functions.php` | Loads `inc/leisure-location.php`. |
| `wp-content/themes/conexao-br-irlanda/assets/js/main.js` | Adds `initLeisureProximity()`; adds a loader indirection + `takeOver()` to `initInfiniteScroll()` (the default `/page/N/` path is unchanged). |
| `wp-content/themes/conexao-br-irlanda/assets/css/leisure.css` | Adds `.leisure-card-distance`, `.leisure-proximity-explanation`, `.leisure-proximity-note`. |
| `wp-content/themes/conexao-br-irlanda/languages/conexao-br-irlanda.pot` | Regenerated with `./scripts/i18n-make-pot.sh conexao-br-irlanda`. |
| `wp-content/themes/conexao-br-irlanda/languages/en_US.po` / `.mo` | Four new EN translations; `.mo` recompiled with `msgfmt`. |
| `docs/content-model.md` | New "Leisure coordinates (Lazer location services)" contract section. |
| `docs/themes/conexao-br-irlanda.md` | New "Lazer location services" section (endpoints, sorting, scroll, failure, privacy, a11y). |

**Deleted:** none.

Counts: **7 added, 15 modified, 0 deleted** (13 `wp-content/` files + 2
documents; `en_US.po` and `en_US.mo` share one row above). Every `wp-content/`
change is listed above; nothing was changed outside the areas the plan named.

**Not ours (present in the working tree before/independent of this task):**
`docs/README.md` and `docs/reports/README.md` (modified) and
`docs/reports/2026-11-03-commemorative-events-architecture-audit.md`
(untracked) were already changed by another session and were deliberately left
untouched.

## 4. Runtime impact

The change is **additive and opt-in**:

- **Default behaviour is unchanged.** With no coordinates, or without location
  permission, `/lazer/` and `/en/lazer/` render exactly as before: same order,
  same cards, same infinite scroll, no distance, no note.
- **New public routes** (`/wp-json/conexao/v1/leisure-coordinates`,
  `/wp-json/conexao/v1/leisure-cards`) answer read-only JSON.
- **New admin capability**: coordinate fields in the existing leisure editor
  and a "Coordenadas" list column.
- **One refactor with no feature change**: `conexao_leisure_archive_tax_query()`
  returns exactly the clause the inline code built (the existing
  `test-leisure-multiselect-filters.php` suite still passes).
- **`initInfiniteScroll()` gained a loader indirection**; with no proximity
  loader installed the code path is the original one (default loader).

## 4a. Scope integrity

| Check | Result |
|---|---|
| No production write, deploy, upload or activation | confirmed |
| No production content, Polylang or database change | confirmed |
| No Flutter/mobile repository accessed | confirmed |
| No `.env` or secret change | confirmed |

## 5. Content / data impact

**None.** No production record was created, updated or deleted; no coordinate
was written anywhere. The test suites create and then delete their own
temporary posts and terms (asserted by a "no temporary test posts remain"
check in each suite). The browser script seeds four temporary attractions in
the local container and deletes them in a `finally` block.

## 6. Polylang impact

PT content, PT URLs, PT slugs and record identity are untouched. The endpoints
**read** the language scope (`lang` validated against Polylang's registered
languages; the B2 widening mirrors the archive). No translation was created and
no taxonomy assignment changed. Translation completeness is unchanged (the gate
reads content, and no content changed).

## 7. Route / HTTP impact

| Route | Effect |
|---|---|
| `/lazer/`, `/en/lazer/` | Unchanged URLs and canonical/hreflang. Additive body class `conexao-leisure-location`; additive config + script on the leisure archive only. |
| `/wp-json/conexao/v1/leisure-coordinates` | New read-only GET route (200; 400 never — filter values are normalised, unknown slugs simply match nothing). |
| `/wp-json/conexao/v1/leisure-cards` | New read-only GET route (200; **400** for a missing/invalid/oversized `ids`). |
| Everything else | No change: the config, the script and the body class are emitted only when `is_post_type_archive('leisure')`. |

Verified: `config`/`proximity_script`/`body_class` present on both `/lazer/`
and `/en/lazer/`, and the dataset ordering identical to the rendered archive
order for PT, EN and a filtered (`?county=dublin`) view
(`docs/evidence/2026-11-02-lazer-location-services/endpoint-vs-archive-order.txt`).

## 7a. Browser verification

`tests/browser/verify-lazer-proximity-browser.py` (Playwright/Chromium, headless)
seeds four temporary attractions (three at 0 m, ~30 m and ~219 km from the mock
position, one without coordinates), drives four geolocation scenarios and
deletes the fixtures. **31 passed, 0 failed**
(`docs/evidence/2026-11-02-lazer-location-services/browser-verification.txt`):

| Scenario | Result |
|---|---|
| Permission granted, valid position | Nearest-first order across the whole page (0 m → 30 m → 219 km → no-coordinate last); exactly three distances; metres below 1 km and kilometres above; every distance bound to its attraction id; the "Ordenado por proximidade" note appears once; the explanation was present *before* the (deliberately delayed) position resolved and removed afterwards; no duplicate cards; no console/page errors; every endpoint call answered 200 |
| Filter change after full navigation (`?county=dublin`) | One-shot request re-runs; no duplicated note; no duplicate cards |
| Permission denied | No note, no distances, default server order preserved, explanation removed, no console errors |
| Position timeout (mocked `code: 3`) | No note, no distances, default order preserved, no page errors |
| Geolocation API unavailable (`delete navigator.geolocation`) | No note, no distances, default order preserved, explanation removed, no page errors |

Note: five unrelated static-resource 404s are logged by the page (pre-existing
theme asset requests, present with the feature inert); they are recorded but
not part of the feature.

## 8. Production actions

| Action | Performed? | Detail |
|---|---|---|
| Production write | **no** | |
| Deploy / upload | **no** | |
| Plugin activation | **no** | |
| Content or DB mutation | **no** | |

Production is WordPress.com (no SSH, no WP-CLI, no filesystem, no database), so
the endpoint code ships with the theme and coordinate population must be done
by an editor through the existing leisure editor.

## 9. Verification commands and results

| Command | Exit | Result |
|---|---|---|
| `node tests/js/test-leisure-proximity.mjs` | 0 | **68 passed, 0 failed** (Haversine, formatting PT/EN, global sort, tie-break, missing coordinates, geolocation outcome branches, batch cursor) |
| `docker compose exec wordpress php .../themes/conexao-br-irlanda/tests/test-leisure-location-services.php` | 0 | **102 passed, 0 failed** |
| `docker compose exec wordpress php .../plugins/conexao-admin-ux/tests/test-leisure-coordinates.php` | 0 | **29 passed, 0 failed** |
| `python3 tests/browser/verify-lazer-proximity-browser.py --base-url http://localhost:8080 --connect 127.0.0.1:8081` | 0 | **31 passed, 0 failed** |
| `./scripts/run-tests.sh --scripts` | 1 | 19 suites, **18 passed, 1 failed** — the pre-existing i18n-catalogue staleness (0 new violations) |
| `./scripts/run-tests.sh` | 1 | **116 in-process suites: 104 passed, 12 failed; 9 064 assertions passed, 48 failed.** Script layer 18/19. **HTTP acceptance BLOCKED** (see below). All 12 failing suites reproduce **identically with this change stashed** (§11). |
| `php -l` on every changed PHP file | 0 | no syntax errors |
| `node --check` on `main.js` + `leisure-proximity.js` | 0 | no syntax errors |
| `python3 -m py_compile tests/browser/verify-lazer-proximity-browser.py` | 0 | compiles |
| `./scripts/i18n-make-pot.sh conexao-br-irlanda` | 0 | catalogue regenerated (1 regenerated, 0 failed) |
| `msgfmt --check-format -o en_US.mo en_US.po` | 0 | EN catalogue compiles; the four new msgids are present |

### Numeric test results

Focused suites: **230 assertions passed, 0 failed** (102 theme + 29 plugin +
68 JS + 31 browser).

### HTTP acceptance

**BLOCKED** in this environment: `./scripts/run-tests.sh` reports
`HTTP acceptance environment unavailable` (0 suites run). The default base URL
`http://localhost:8080` is answered by a *different* local WordPress than the
container that `docker compose exec` (and therefore the in-process layer) uses
— see §13. The endpoint/archive equivalence that the acceptance layer would
cover was instead verified directly against the container (see
`endpoint-vs-archive-order.txt`) and end to end in the browser
(`browser-verification.txt`).

### Static analysis

`php -l` and `node --check` pass on every changed file. PHPCS/PHPStan were not
run in this environment; `./scripts/lint.sh` and CI remain authoritative.

### Script-contract results

`./scripts/run-tests.sh --scripts`: **19 suites, 18 passed, 1 failed** — the
pre-existing `verify-i18n-freshness.py` staleness (`1 pre-existing, 0 new`).

## 10. Failure proofs / negative tests

| Proof | Result |
|---|---|
| Out-of-range latitude can never be persisted (`update_post_meta(…, '999')` → stored `''`) | PASS (theme suite) |
| An invalid admin submission preserves the previously stored value | PASS (plugin + theme suites) |
| A malformed stored value is reported as `invalid` (written directly to simulate legacy data) | PASS (theme suite) |
| `/leisure-cards` rejects `abc`, `0`, `-1`, `1,abc` with HTTP **400** | PASS (theme suite) |
| `/leisure-cards` rejects a 51-ID batch with **400** and accepts exactly 50 | PASS (theme suite) |
| A draft attraction is never returned by either endpoint | PASS (theme suite) |
| A non-leisure post type is never returned / rendered | PASS (theme suite) |
| Invalid visitor coordinates produce no distances and keep the default order | PASS (JS suite) |
| A failed batch does not advance the cursor (identical slice on retry) | PASS (JS suite) |
| Geolocation denied / unavailable / timeout / unsupported leave the page unchanged | PASS (JS suite + browser) |

## 11. Regression comparison

The full suite was run once with this change (116 in-process suites: 104 passed,
12 failed; 9 064 assertions passed, 48 failed). One small, additive JS change
landed after that run — the mobile in-place filter re-apply hook, a feature
guard and note de-duplication in `initLeisureProximity()` — and was re-verified
afterwards by the focused suites (theme 102/0, plugin 29/0, JS 68/0) and the
browser acceptance (31/0). Because no clean baseline
existed in this environment, **every failing suite was then re-run with this
change's `wp-content/` modifications stashed**, and the failures are identical
in count:

| Suite | With this change | Baseline (change stashed) |
|---|---|---|
| `plugin/conexao-event-importer/test-import-continuation.php` | 7 failed | (event-importer state; no modified file on its path) |
| `plugin/conexao-event-importer/test-town-sanitization.php` | 1 failed | **1 failed** |
| `theme/.../test-blog-en-translation.php` | 3 failed | **3 failed** |
| `theme/.../test-en-jobs-shared-slug.php` | 4 failed | **4 failed** |
| `theme/.../test-event-filter-cache.php` | 5 failed | **5 failed** |
| `theme/.../test-guide-en-translation.php` | 7 failed | **7 failed** |
| `theme/.../test-job-en-translation.php` | 4 failed | **4 failed** |
| `theme/.../test-jobs-en-language.php` | 6 failed | **6 failed** |
| `theme/.../test-seasonal-halloween-nav-effects.php` | 5 failed | **5 failed** |
| `theme/.../test-stage33-bilingual.php` | 2 failed | **2 failed** |
| `theme/.../test-stage45-pages.php` | 2 failed | **2 failed** |
| `theme/.../test-translation-completeness.php` | 2 failed | **2 failed** |
| `tests/scripts/verify-i18n-freshness.py` | 1 failed | 1 failed (`1 pre-existing, 0 new`) |

**The diff of the failing list is empty: no suite fails because of this change.**
The failures are local content-state conditions (EN translations for
blog/guide/job, EN pages without a PT master, town-term contamination, guide
EN category linking, Halloween occasion window) plus the known catalogue
staleness. The one suite that reads `main.js`
(`test-seasonal-halloween-nav-effects.php`) fails with exactly the same 5
assertions against the committed `main.js` — its expected Halloween delay
constants (20 000/30 000/25 000/35 000 ms) do not match the committed values
(5 000/10 000/10 000/15 000 ms), a pre-existing mismatch this change does not
touch.

## 12. Known pre-existing failures

See the table in §11. Every one of the 13 failures pre-exists this change and
was proven so by re-running the suite with the change stashed (identical
counts), except `test-import-continuation.php`, which is an event-importer
scheduling suite with no modified file on its path.

## 13. Limitations

- **No coordinates exist in the local database** (`0` of 293 published
  attractions have `_leisure_lat`/`_leisure_lng`). Distance rendering is
  therefore verified by unit tests, by the endpoint contract and by the browser
  acceptance script (which seeds its own temporary geolocated attractions) —
  but **not** against real attraction data. This report therefore does **not**
  claim visitors currently see distances: coordinate population is a separate
  editorial prerequisite.
- **Production inventory not verifiable.** There is no read-only path to the
  live WordPress.com database from this repository, so "how many live
  attractions have coordinates / verified coordinates" is unknown. No claim is
  made about it.
- **Local environment nuance.** The running local WordPress container publishes
  port **8081** while its WordPress `home`/`siteurl` is `http://localhost:8080`,
  and a *separate* WordPress answers on `localhost:8080`. Requests to 8081 are
  therefore canonical-redirected to 8080. All in-process suites and the
  endpoint-vs-archive equivalence check were run against the container (the
  latter with an explicit `Host: localhost:8080` header); the browser
  acceptance used Chromium `--host-resolver-rules` for the same reason. As a
  consequence the HTTP acceptance layer of `./scripts/run-tests.sh` reports
  `HTTP acceptance environment unavailable` and ran **0** suites — a
  pre-existing environment condition, not a product issue. The equivalence it
  would have covered was verified directly against the container and end to end
  in the browser, and the "no new failure" claim is backed by the
  stash-the-change baseline comparison in §11 rather than by a clean full-suite
  baseline.
- **`prefers-reduced-motion`** needs no special case because no animation is
  introduced; it was not separately browser-tested.
- **Dark mode** was not visually screenshot-diffed; the distance/note styling
  uses only existing muted-text tokens, which already have dark-mode coverage.

## 14. Evidence paths

`docs/evidence/2026-11-02-lazer-location-services/`:

| File | Proves |
|---|---|
| `endpoint-vs-archive-order.txt` | The dataset order is identical to the rendered archive order for PT, EN and a filtered view; the page emits the config, the proximity script and the body class; the dataset contains nulls for coordinate-less attractions and no provenance keys. |
| `php-theme-suite.txt` | The 102-assertion theme suite output. |
| `php-plugin-suite.txt` | The 29-assertion plugin suite output. |
| `js-unit-tests.txt` | The 68-assertion Node suite output. |
| `browser-verification.txt` | The 31-assertion Playwright run (granted / multi-batch global order / denied / timeout / unsupported + filter reload). |
| `full-test-run.txt` | The `./scripts/run-tests.sh` aggregate output. |
| `regression-baseline-a.txt`, `regression-baseline-b.txt` | The failing suites re-run **with this change's `wp-content/` modifications stashed** — identical failure counts, proving the failures pre-exist. |

## 15. Documentation updated

| Document | Change |
|---|---|
| `docs/content-model.md` | New "Leisure coordinates (Lazer location services)" contract: meta keys, REST visibility, validation/storage rules, provenance, inventory states, the no-geocoding rule. |
| `docs/themes/conexao-br-irlanda.md` | New "Lazer location services" section: files, scope, permission experience, endpoint contracts, global sorting, infinite-scroll integration, transition, failure handling, privacy, accessibility. |

No procedure moved between a document and a skill.

## 16. Rollback / recovery

Revert the change set. Because every addition is opt-in:

- reverting `inc/leisure-location.php` + the `functions.php` require removes the
  endpoints, the body class and the JS config;
- reverting `inc/assets.php` removes the script and the config;
- reverting `main.js` removes `initLeisureProximity()` and the loader
  indirection (the default infinite scroll is then identical to before);
- the coordinate meta may be left registered harmlessly (it stores nothing);
  removing the registration in `class-meta.php` stops the fields being accepted.

Rollback does **not** cover coordinates an editor has already entered (they are
ordinary post meta and remain in the database; no coordinate is deleted by a
rollback).

## 17. Final status

**Final status: PASS WITH LIMITATION**

The implementation is complete and every focused automated suite plus the
browser acceptance passes (230 focused assertions, 0 failures), but two
verification capabilities were unavailable: the local database contains no
attraction coordinates (so distances were verified against seeded fixtures, not
real data) and the production coordinate inventory cannot be inspected through
any read-only mechanism from this repository. The HTTP acceptance layer was also
BLOCKED by a pre-existing local port/DB mismatch (§13), with the equivalent
equivalence checks performed directly and in the browser.
