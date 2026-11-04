# Report — Lazer distance indicator: explicit opt-in, friendly failure, approved display table

| | |
|---|---|
| **Stage / task name** | Visitor-activated distance indicator on `/lazer/` attractions |
| **Date** | 2026-11-04 |
| **Author / agent** | Cline (AI coding agent) |
| **Branch** | `leisure-location` |
| **Start SHA** | `5fb0bb3537d3ec2cccbd741691560ad0ff1a6935` |
| **Final SHA** | uncommitted (working tree intentionally left dirty; see §3) |
| **Working tree at finish** | dirty — this task's files only (see §3) |
| **Skills followed** | `wp-frontend-perf`, `wp-write-in-process-test`, `wp-http-acceptance-matrix`, `wp-update-docs`, `wp-repository`, `wp-security-review` |
| **Authoritative docs consulted** | `AGENTS.md`, `docs/engineering-standard.md`, `docs/content-model.md`, `docs/routing.md`, `docs/themes/conexao-br-irlanda.md`, `docs/testing.md`, `scripts/README.md`, `docs/plans/2026-11-01-lazer-location-services.md`, `docs/reports/2026-11-02-lazer-location-services.md`, `docs/templates/plan.md`, `docs/templates/report.md` |

## 0. Audit first — the feature already existed, and that decided the integration point

The brief said: *"First inspect the existing implementation and determine the
best integration point. Do not assume the card structure, templates, JavaScript,
or data source."* Doing exactly that produced the most important finding of this
task.

**The feature already existed on this branch.** It was planned in
`docs/plans/2026-11-01-lazer-location-services.md`, implemented in commit
`80340da` ("Add Lazer location services with REST endpoints and tests") and
reported in `docs/reports/2026-11-02-lazer-location-services.md`. The branch
(`leisure-location`, HEAD `5fb0bb3`) was **clean** — everything was committed.

The existing architecture, which the audit confirmed and which this task reuses
rather than replaces:

| Concern | Where it lives |
|---|---|
| Attraction card | `template-parts/leisure-card.php`; the distance goes in the existing `<div class="leisure-card-details">` row (county · town), line 141 |
| Card styling | `assets/css/leisure.css` (`.leisure-card-detail` + the proximity classes); tokens from `design-system.css` |
| Listing / filtering | `archive.php` + `inc/archive-query.php` → `conexao_leisure_archive_tax_query()` (the single filter interpreter) |
| Coordinates — storage | `_leisure_lat` / `_leisure_lng` post meta, registered by `Conexao_Data_Model_Meta::register_leisure_coordinates()` |
| Coordinates — exposure to the front end | `GET /wp-json/conexao/v1/leisure-coordinates` (`inc/leisure-location.php`) — never raw post meta on the page |
| Card HTML for a reordered list | `GET /wp-json/conexao/v1/leisure-cards?ids=…`, which reuses `template-parts/leisure-card.php` (no second renderer) |
| Distance maths + formatting | `assets/js/leisure-proximity.js` — pure, side-effect-free, Node-testable |
| DOM orchestration | `initLeisureProximity()` in `assets/js/main.js` |
| Pagination / dynamic rendering | Infinite scroll (`[data-infinite-scroll]`) with a loader indirection; proximity batches reuse the same sentinel/observer/retry UI. The mobile filter sheet swaps the grid **in place**. |
| Assets | `inc/assets.php` — the pure module loads only on the leisure archive; the config is injected as `window.ConexaoLeisureLocation` |
| Strings | `inc/i18n.php` → `conexao_js_i18n_strings()`, injected as `window.ConexaoI18n` |

**Confirmed data facts (measured, not assumed):**

| Check | Result | How |
|---|---|---|
| Published `leisure` posts in the Compose stack | 293 | `docker compose exec wordpress php -r …WP_Query…` |
| Attractions with a **valid coordinate pair** | **289** | same |
| Coordinate dataset returned by the endpoint | 293 items, **289 with coordinates** | `curl …/conexao/v1/leisure-coordinates?lang=pt` against the stack |
| Cards reused elsewhere? | Yes — the archive **and** `/conexao/v1/leisure-cards` both render `template-parts/leisure-card.php`. No other consumer. | `grep -rn leisure-card --include=*.php` |

So this task did **not** introduce a new architecture. It closed the gaps
between the existing implementation and the brief (§1).

## 1. Scope completed

Four changes, all inside the existing architecture:

1. **Explicit opt-in control.** `initLeisureProximity()` previously called
   `navigator.geolocation.getCurrentPosition()` automatically on page load. It
   now creates a real `<button type="button" class="btn btn-outline-dark
   leisure-proximity-toggle">` labelled **"Mostrar distância de mim"** and
   requests location **only** when that button is activated. Feature detection
   runs first, so a browser that cannot geolocate (or an insecure context) is
   never shown a control that could not work.
2. **Friendly failure message + retry.** Every failure path (denied,
   unavailable, timeout, invalid position, dataset/card fetch failure) now shows
   one `role="status"` message — *"Não foi possível obter sua localização. Você
   pode continuar explorando as atrações normalmente."* — keeps the default
   server order, and relabels the control to **"Tentar novamente"**. The retry
   issues a new request and never stacks a second message.
3. **Approved display table** in `formatDistanceValue()`: metres (nearest 10)
   below 1 km; one decimal from 1–10 km; whole kilometres from 10 km on.
4. **Card wording** `A aproximadamente %s de si` → `%s de você` (the brief's
   format; the site's established informal voice, cf. `Você chegou ao fim.`).

## 2. Scope NOT completed

Nothing in the brief was skipped. Two things were deliberately **not** done:

- **The pre-existing `conexao-admin-ux` stale `.pot`** (`scripts/i18n-check.sh`)
  was left alone: it is another task's debt, recorded as pre-existing in
  `tests/baseline/permanent-gates.json`, and regenerating it would be an
  unrelated change.
- **The pre-existing PHPCS/PHPStan debt and the 11 pre-existing failing
  in-process suites** were not repaired (§8).

## 3. Files added / modified / deleted

**0 deleted. 11 modified. 3 added.**

| Path | Change | Note |
|---|---|---|
| `wp-content/themes/conexao-br-irlanda/assets/js/main.js` | modified | `initLeisureProximity()`: opt-in control, loading state, friendly failure + retry; `activate()` now rejects instead of swallowing failures; the distance template. |
| `wp-content/themes/conexao-br-irlanda/assets/js/leisure-proximity.js` | modified | `formatDistanceValue()` implements the approved display table. |
| `wp-content/themes/conexao-br-irlanda/inc/i18n.php` | modified | 3 new JS strings, 2 reworded; the leisure block's `=>` alignment normalised (see §8c). |
| `wp-content/themes/conexao-br-irlanda/assets/css/leisure.css` | modified | `.leisure-proximity-control`, `.leisure-proximity-toggle`; `.leisure-proximity-error` added to the shared note rule. |
| `wp-content/themes/conexao-br-irlanda/languages/conexao-br-irlanda.pot` | modified | Regenerated with `./scripts/i18n-make-pot.sh --apply conexao-br-irlanda` (standard §9.3 — never hand-edited). |
| `wp-content/themes/conexao-br-irlanda/languages/en_US.po` | modified | The reworded strings re-translated and the 3 new strings translated (human-authored). |
| `wp-content/themes/conexao-br-irlanda/languages/en_US.mo` | modified | Recompiled with `msgfmt` (33 635 → 33 927 bytes). |
| `tests/js/test-leisure-proximity.mjs` | modified | Display-table section rewritten: 68 → **75** assertions. |
| `tests/browser/verify-lazer-proximity-browser.py` | modified | Drives the opt-in control; proves *no* automatic request; adds retry + failure-message assertions; expectations now derived from the live dataset; the "unsupported" scenario genuinely removes the API. 31 → **51** assertions. |
| `docs/themes/conexao-br-irlanda.md` | modified | The "Lazer location services" section: opt-in, display table, failure/retry, privacy, accessibility, file roles. |
| `docs/README.md` | modified | The documentation index: the plan and the report added to the reports/plans table. |
| `docs/plans/2026-11-04-leisure-distance-optin.md` | added | The required plan. |
| `docs/evidence/2026-11-04-leisure-distance-optin/` | added | Machine evidence (§9). |
| `docs/reports/2026-11-04-leisure-distance-optin.md` | added | This report. |

Everything under `wp-content/` is confined to the **theme**. No plugin, no data
model, no schema, no route and no template was touched. `docs/content-model.md`
was **not** changed because the coordinate data model is unchanged.

## 4. Runtime impact

Yes — a deliberate, visible behaviour change on `/lazer/` and `/en/lazer/` only:

- **Before:** the browser permission prompt appeared automatically on page load,
  and distances appeared with no visitor action.
- **After:** nothing is requested until the visitor activates *"Mostrar distância
  de mim"*. A visitor who ignores the control browses exactly as before, and no
  permission prompt is shown.

Everything else is behaviour-preserving: the endpoints, the ordering algorithm,
the card template, the infinite-scroll indirection and the filter/pagination
behaviour are untouched.

## 4a. Scope integrity

- `git status --short` shows only the 13 paths above — nothing else.
- No file outside `wp-content/themes/conexao-br-irlanda/`, `tests/` and `docs/`
  was modified.
- No production write, deploy, upload or activation was performed.
- No Flutter/mobile repository was touched.

## 5. Privacy (explicit confirmation, as the brief requires)

| Question | Answer |
|---|---|
| Is the visitor's location **stored**? | **No.** No post meta, no option, no transient, no cookie, no `localStorage`/`sessionStorage`. |
| Is it **transmitted to WordPress**? | **No.** The coordinates live only in the `initLeisureProximity()` closure. The two REST calls carry **no** coordinate parameter — verified by reading `fetchCoordinates()`/`fetchCards()` and the endpoint `args` schemas. |
| Is it transmitted to **third parties**? | **No.** No Google Maps, Mapbox, routing service, geocoder or analytics. The only network calls are same-origin `fetch()` to the two read-only endpoints. |
| Are coordinates **logged**? | **No.** Nothing is written to the console, the PHP log or any log sink. The browser test asserts *no console/page errors* on every path. |
| Is the request **explicit**? | **Yes** — proven, not asserted: the browser test wraps `getCurrentPosition` with a counter and asserts `requests_before_click == 0`, `requests_after_click == 1`. |
| How long are the coordinates kept? | For the lifetime of the page only, in one closure variable. A reload discards them; `maximumAge: 0` guarantees a fresh fix, so a retry never reuses a stale position. |

## 6. Compatibility

| Concern | Behaviour |
|---|---|
| **Desktop layout** | The control sits under the archive heading, reusing `.btn .btn-outline-dark` (44 px pill). The distance sits in the existing `.leisure-card-details` row with `white-space: nowrap`, so it cannot wrap mid-value. |
| **Mobile layout** | `.leisure-proximity-control` is a wrapping flex row (`flex-wrap: wrap`, `gap: 8px`); `.leisure-proximity-toggle` sets `max-width: 100%; white-space: normal`, so a narrow heading column wraps the label instead of overflowing. |
| **Filtering** | Desktop filter links navigate normally and re-render the page — no automatic request, and the control is offered again (asserted). The mobile in-place filter swap re-applies the ordering through `window.ConexaoLeisureProximityReapply` using the already-obtained position, and never re-prompts. |
| **Pagination / dynamic rendering** | Proximity batches use the existing infinite-scroll loader indirection; every appended batch re-applies its distance from the ID-keyed `Map` (`applyDistance()` inside `buildFragment()`). Verified end to end: 30 cards across several batches match the independently computed global order exactly. |
| **Missing / malformed coordinates** | `haversineMeters()` returns `null` for a missing, non-numeric, out-of-range, `NaN` or `Infinity` input, so that card gets **no** indicator — never a placeholder or a misleading distance. Coordinate-less attractions are appended last in their original order and are never dropped. |
| **JavaScript unavailable or failing** | The whole feature is JS-created and JS-gated; without JS the control never appears and the server-rendered archive (including numeric pagination) works exactly as before. Every orchestration step is wrapped so a failure cannot break the page. |
| **Browser support** | Requires `fetch`, `Promise`, `Map`, `DOMParser`, `URLSearchParams`, `Intl.NumberFormat` and `navigator.geolocation`. The first five are feature-detected at the top of `initLeisureProximity()`; geolocation support and secure-context are classified before the control is offered. Any missing capability degrades to "feature absent", never to a broken page. |
| **Insecure context** | `classifyGeolocationSupport()` returns `insecure` for a non-secure context, so no control is offered. |
| **Dark mode** | The control uses design-system tokens (`--color-border`, `--color-text`, `--color-primary-50`) and the notes use the existing muted-text token, so both follow the existing dark-mode layer. **Not screenshot-diffed** — see §10. |

## 7. Straight-line distance — stated explicitly

The indicator is a **straight-line (great-circle / Haversine) distance**, not road
distance and not travel time. It is never labelled as driving distance; the card
text is the neutral `%s de você`. The Earth radius used is the mean 6 371 000 m,
so a value is an estimate — expect roughly ±0.5 % of the true great-circle
distance, plus whatever the visitor's own location accuracy adds. This limitation
is documented in `docs/themes/conexao-br-irlanda.md` and in the module header of
`leisure-proximity.js`.

## 8. Testing

### 8a. Tests added / changed

| Suite | Change |
|---|---|
| `tests/js/test-leisure-proximity.mjs` | The display-table section was rewritten: sub-kilometre metres, the 1–10 km one-decimal band, the 10 km boundary, 24 km / 125 km whole-kilometre values, and `Infinity` rejection. 68 → **75** assertions. |
| `tests/browser/verify-lazer-proximity-browser.py` | The granted scenario now counts geolocation calls (`__geoRequests`) and asserts **no** request before the control is activated; assertions are derived from the live dataset (the DB now has 289 coordinates, so the old hard-coded fixture order was no longer valid); denial/timeout assert the friendly message and the retry; denial additionally asserts the retry issues a second request and that messages never stack; the "unsupported" scenario now genuinely removes the API. 31 → **51** assertions. |

### 8b. Results

| Verifier | Command | Result |
|---|---|---|
| JS unit suite | `node tests/js/test-leisure-proximity.mjs` | **75 passed, 0 failed** (baseline 68 / 0) |
| Theme in-process suite | `docker compose exec wordpress php …/tests/test-leisure-location-services.php` | **102 passed, 0 failed** (unchanged) |
| Plugin in-process suite | `docker compose exec wordpress php …/conexao-admin-ux/tests/test-leisure-coordinates.php` | **29 passed, 0 failed** (unchanged) |
| Browser acceptance | `python3 tests/browser/verify-lazer-proximity-browser.py --base-url http://localhost:8080 --connect 127.0.0.1:8081` | **51 passed, 0 failed** (no runnable baseline — see §8d) |
| i18n freshness (§9.3) | `./scripts/i18n-check.sh` | `conexao-br-irlanda … fresh`; 3 passed, 1 failed — the failure is the **pre-existing** `conexao-admin-ux` stale `.pot` (`1 pre-existing, 0 new`) |
| `.po` validity | `msgfmt --check --statistics -o /dev/null en_US.po` | 426 translated messages, 0 errors |
| Whole suite | `./scripts/run-tests.sh` | see §8e |
| Static quality | `./scripts/lint.sh` | see §8f |

### 8c. The three verification findings worth recording

**(i) The browser suite's granted expectations were stale, not the product.**
Against the now-populated dataset the old assertions failed with
`exactly the three geolocated cards show a distance (10)` — i.e. the product
correctly showed **ten** distances on a ten-card page, because the DB now
carries 289 real coordinates instead of none. The expectations were re-derived
from the live dataset (the suite's own `leisure-coordinates` fetch), so the
test now proves the same properties without assuming the data.

**(ii) The "unsupported" scenario never actually removed geolocation.**
`delete navigator.geolocation` is a **no-op** — the property lives on
`Navigator.prototype` — so that scenario was silently testing "geolocation
available but no position", and the old `note_count == 0` assertion passed for
the wrong reason. It is now removed with an own-property getter
(`Object.defineProperty(navigator, 'geolocation', { get: () => undefined })`),
and the scenario asserts the genuinely correct contract: **no control is
offered** and no request is attempted.

**(iii) My own first attempt added 3 PHPCS warnings; that was fixed, not
baselined.** `WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned`
flagged the 3 new array entries in `conexao_js_i18n_strings()` whose `=>` did not
line up. Measured per file: the original `inc/i18n.php` had **21** warnings, my
first version **24**, the corrected version **18** — the whole leisure block is
now aligned to its longest key. The project total fell from 2265 to **2259**
warnings and the gate's "per-sniff counts grew" list became byte-identical to
the pre-existing one, i.e. **this change adds 0 PHPCS violations**. The baseline
was **not** regenerated.

### 8d. The browser baseline could not be recorded, and why

The browser acceptance suite had **no runnable baseline** in this environment,
and that is an environment fact rather than a product one:

- Port `8080` is served by a **different** WordPress install than the Docker
  Compose stack (`Server: Apache/2.4.68`, `X-Powered-By: PHP/8.3.35`, 246 leisure
  posts, **0** coordinates), while the stack under test publishes `8081`
  (`PHP/8.3.33`, 293 published posts, 289 with coordinates). A browser run
  against `:8080` therefore exercises a stale site that cannot see the feature's
  data at all — which is exactly why the first attempt timed out waiting for a
  proximity note.
- The suite's own documented `--connect 127.0.0.1:8081` mechanism (Chromium
  `--host-resolver-rules`, so the request still carries `Host: localhost:8080`)
  is the correct target, and is what every successful run in §8b used.

So the browser suite's "before" number is **not available**; its "after" number
is **51 passed / 0 failed**. This is reported as a limitation, not as a pass.

### 8e. Whole-suite comparison (`./scripts/run-tests.sh`)

Baseline for comparison — the recorded 2026-11-03 state, whose figures
(`117 / 106 / 11`, `9 085 / 41`, `19 / 18 / 1`, `4 / 3 / 1`) are quoted in
`docs/reports/2026-11-03-leisure-location-cleanup.md`:

| Layer | Baseline | This run | Verdict |
|---|---|---|---|
| in-process PHP suites | 117 total, 106 passed, **11 failed** | 117 total, 106 passed, **11 failed** | **identical** |
| in-process PHP assertions | 9 085 passed, **41 failed** | 9 085 passed, **41 failed** | **identical** |
| script contract | 19 total, 18 passed, **1 failed** | 19 total, 18 passed, **1 failed** | **identical** |
| HTTP acceptance | 4 total, 3 passed, **1 failed** | 4 total, 3 passed, **1 failed** | **identical** |

**The failing-suite list is byte-identical to the baseline — 0 new failures:**

| # | Failing suite (pre-existing) |
|---|---|
| 1 | `plugin/conexao-event-importer/test-town-sanitization.php` (82 passed, 1 failed) |
| 2 | `theme/conexao-br-irlanda/test-blog-en-translation.php` (25 passed, 3 failed) |
| 3 | `theme/conexao-br-irlanda/test-en-jobs-shared-slug.php` (35 passed, 4 failed) |
| 4 | `theme/conexao-br-irlanda/test-event-filter-cache.php` (17 passed, 5 failed) |
| 5 | `theme/conexao-br-irlanda/test-guide-en-translation.php` (667 passed, 7 failed) |
| 6 | `theme/conexao-br-irlanda/test-job-en-translation.php` (28 passed, 4 failed) |
| 7 | `theme/conexao-br-irlanda/test-jobs-en-language.php` (98 passed, 6 failed) |
| 8 | `theme/conexao-br-irlanda/test-seasonal-halloween-nav-effects.php` (102 passed, 5 failed) |
| 9 | `theme/conexao-br-irlanda/test-stage33-bilingual.php` (196 passed, 2 failed) |
| 10 | `theme/conexao-br-irlanda/test-stage45-pages.php` (364 passed, 2 failed) |
| 11 | `theme/conexao-br-irlanda/test-translation-completeness.php` (21 passed, 2 failed) |
| 12 | `tests/scripts/verify-i18n-freshness.py` (the pre-existing `conexao-admin-ux` stale `.pot`) |
| 13 | `tests/acceptance/verify-event-occurrence-http.py` (the `:8080` install mismatch, §8d) |

Every one of these appears, with the same counts, in the recorded baseline
`docs/evidence/2026-11-02-lazer-location-services/full-test-run.txt`. The suite
that matters to this change, `theme/conexao-br-irlanda/test-leisure-location-services.php`,
**passes 102 / 0**.

**One discrepancy in the recorded baseline, reported rather than smoothed over:**
`docs/evidence/2026-11-02-lazer-location-services/full-test-run.txt` (the primary
capture) recorded **116 / 104 / 12** suites, **9 064 / 48** assertions and
**0** HTTP acceptance suites ("BLOCKED"), whereas the 2026-11-03 cleanup report
recorded 117 / 106 / 11, 9 085 / 41 and 4 / 3 / 1. This run matches the
**cleanup report's** figures exactly and the primary capture's *failing-suite
list* exactly; the numeric differences are between the two baseline documents,
not between a baseline and this run.

### 8f. Static quality (`./scripts/lint.sh`)

| Check | Result |
|---|---|
| `php -l` over 531 files | **OK — no parse errors** |
| PHPCS + legacy baseline gate | **FAILED — pre-existing only.** Total debt fell 2265 → 2259 warnings (my alignment fix). The gate's growth list (5 sniffs grew, 1 sniff new) is byte-identical to the pre-existing state, and none of those sniffs is in a file this change touched. The gate was **not** silenced by regenerating the baseline. |
| PHPStan level 5 | **FAILED — pre-existing.** One error at `themes/conexao-br-irlanda/inc/leisure-location.php:79` (`function.alreadyNarrowedType`), a file this change did not modify. Already recorded as pre-existing on 2026-11-03. |

`lint: FAILED` is therefore **PASS WITH LIMITATION** for this change: the two
failing checks are pre-existing, are in files this change does not touch, and
this change strictly reduces the PHPCS debt. No new violation was introduced.

## 9. Evidence paths

`docs/evidence/2026-11-04-leisure-distance-optin/`:

| File | Proves |
|---|---|
| `change-set.txt` | The exact change set (`git status --short`, `git diff --stat`). |
| `js-unit-tests.txt` | The 75-assertion Node suite output. |
| `php-theme-suite.txt` | The 102-assertion theme suite output. |
| `browser-verification.txt` | The 51-assertion Playwright run, with the `:8080`-vs-`:8081` environment note. |
| `i18n-catalogue.txt` | The catalogue regeneration, `msgfmt --check` and the `.mo` content check. |
| `lint.txt` | The `./scripts/lint.sh` output (and the per-file PHPCS attribution measurement). |
| `full-test-run.txt` | The `./scripts/run-tests.sh` aggregate output. |

## 10. Limitations

- **Dark mode was not screenshot-diffed.** The control and the notes use only
  existing design-system/muted-text tokens that already have dark-mode coverage,
  but no visual diff was produced.
- **The browser suite is not part of `./scripts/run-tests.sh`** and needs a live
  local stack plus Playwright; it must be run explicitly, and in this
  environment it needs `--connect 127.0.0.1:8081`.
- **No real-device verification.** iOS Safari and Android Chrome were not tested;
  only Chromium via Playwright, with the position mocked.
- **The straight-line approximation is inherent** (§7), not a defect.
- **Retry-after-movement** is implemented (`maximumAge: 0` forces a fresh fix)
  and the retry is asserted to issue a new request, but a *changed position*
  producing *changed distances* was not exercised (the mock returns a fixed
  point).
- **The pre-existing lint debt** (PHPCS baseline growth, the one PHPStan error)
  and the pre-existing failing in-process suites remain; they are reported, not
  fixed, because they belong to other components and other tasks.
- The **HTTP acceptance layer** of `./scripts/run-tests.sh` is subject to the same
  `:8080`/`:8081` mismatch noted in §8d; see `full-test-run.txt`.

## 11. Rollback / recovery

Revert the change set. Every addition is opt-in and additive:

- reverting `assets/js/main.js` restores the previous automatic-request flow and
  the previous distance wording;
- reverting `assets/js/leisure-proximity.js` restores the one-decimal format;
- reverting `inc/i18n.php` plus the three catalogue files restores the previous
  strings;
- reverting `assets/css/leisure.css` removes the control/error styles (the
  control would then render with the bare design-system button, still usable);
- the tests and docs revert with them.

No data is created by this change, so a rollback leaves nothing behind.

## 12. Final status

**Final status: PASS WITH LIMITATION**

Every focused verifier passes and nothing regressed, but three limitations are
stated rather than glossed: the browser suite has no runnable baseline in this
environment (§8d), dark mode was not visually diffed, and the two failing static
checks are pre-existing debt in files this change does not touch (§8f).

**Ready for review.** No production deployment or write was performed, and none
is proposed by this task.

_Last verified: 2026-11-04 by the Lazer distance opt-in task_
