# Report — Lazer header cleanup: removal of the “Mostrar distância de mim” button

| | |
|---|---|
| **Stage / task name** | Lazer page UI cleanup — remove the “Mostrar distância de mim” button; align the Lazer header with the reference branch |
| **Date** | 2026-11-05 |
| **Author / agent** | Cline (agent) |
| **Branch** | `leisure-location` |
| **Start SHA** | `83082edb3ff0a79616ee0b83e1e12ea234774965` |
| **Final SHA** | `83082edb3ff0a79616ee0b83e1e12ea234774965` (**no commit, no push** — working-tree change only) |
| **Working tree at finish** | dirty (this task's 2 code files + 3 docs + this report + `docs/evidence/2026-11-05-lazer-proximity-button-removal/`; plus the pre-existing Stage 7–9 documentation/evidence changes that were already present and were **not** touched) |
| **Skills followed** | `wp-frontend-perf` (templates/assets), `wp-testing`, `wp-security-review` (no request/SQL/capability surface changed), `wp-update-docs` |
| **Authoritative docs consulted** | `AGENTS.md`, `docs/engineering-standard.md` (§0, §3, §8, §9, §14), `docs/testing.md`, `docs/themes/conexao-br-irlanda.md`, `docs/templates/report.md`, `plugins.json` (read via `scripts/generate-registry-docs.php --check`) |

## 1. Scope completed

1. **The button is gone.** The
   `<button type="button" class="btn btn-outline-dark leisure-proximity-toggle">Mostrar distância de mim</button>`
   was **not** template markup and not a block/shortcode: it was created at
   runtime by `createControl()` inside `initLeisureProximity()` in
   `wp-content/themes/conexao-br-irlanda/assets/js/main.js` and inserted into
   `.page-header`. Its construction, insertion, its three-state label
   machinery (`setControlState`) and its click listener were removed.
2. **The Lazer header now matches the reference branch.** Two proofs:
   * *Source*: the whole header chain — `archive.php`,
     `template-parts/archive-header.php`, `template-parts/leisure-filters.php`,
     `header.php` — is **byte-identical to `master`** (SHA-256 comparison and
     `git diff master -- …` = 0 lines). The Lazer header difference was never
     template/CSS/theme-configuration: it was the two JS-injected nodes
     (`p.leisure-proximity-explanation`, `p.leisure-proximity-control`) appended
     inside `<header class="page-header">`.
   * *Runtime*: before = `[span.section-eyebrow, h1.page-title,
     p.page-description, p.leisure-proximity-explanation,
     p.leisure-proximity-control]`; after = `[span.section-eyebrow,
     h1.page-title, p.page-description]` — exactly the nodes
     `template-parts/archive-header.php` renders for every archive.
3. **The location service was preserved, not refactored.** `assets/js/leisure-proximity.js`
   (Haversine, formatting, ordering, classification, batch cursor), the injected
   `window.ConexaoLeisureLocation` config, `inc/leisure-location.php` (both REST
   endpoints), the one-shot `navigator.geolocation.getCurrentPosition()` call with
   its exact options, `onPosition`, `onPositionError`, `activate()`,
   `fetchCoordinates()`, `fetchCards()`, `buildFragment()`, `applyDistance()`,
   `swapGrid()`, proximity batch loading, `window.ConexaoLeisureProximityReapply`
   and the mobile filter-swap re-apply are all untouched.
4. **CSS**: only the rules that dressed the removed control
   (`.leisure-proximity-control`, `.leisure-proximity-toggle`, and the
   `.leisure-proximity-explanation` selector that no longer has a producer) were
   deleted. `.leisure-card-distance`, `.leisure-proximity-error` and
   `.leisure-proximity-note` remain (still produced by JS).

## 2. Scope NOT completed / dependency reported instead of silently changing behaviour

**The removed button was the only trigger for geolocation.** Nothing else in the
theme calls `requestPosition()`; `window.ConexaoLeisureProximityReapply()` only
*re-applies* an already-obtained position. Therefore, with the button gone, the
ordering/distance path is **unreachable** — the page now behaves exactly as it
did for a visitor who never pressed the button (no prompt, no distance, no
reorder). Per the task's own instruction, this dependency is **reported rather
than resolved by an unrequested behaviour change**:

* the geolocation entry point (`requestPosition()`) and every downstream step were
  **retained verbatim**, so re-enabling the feature is a one-call change; but
* no automatic/on-load request was introduced (that would change privacy
  behaviour and is explicitly forbidden), and
* no new prompt, button or public JS hook was invented (also explicitly
  forbidden).

**Decision required from the maintainer:** choose a replacement trigger (e.g. a
filter-bar affordance, a dedicated `/lazer/perto-de-mim/` route, or an explicit
opt-in elsewhere) — or retire the proximity feature deliberately. Until then the
service code is present, tested and green but has no in-page entry point.

Secondary, unavoidable consequence: the two **manual** browser acceptance suites
(`tests/browser/verify-lazer-proximity-browser.py`,
`verify-lazer-proximity-geolocation-readonly.py`) drive that button, so their
click scenarios cannot pass until a trigger exists. They were **not edited and
not weakened**; the stale UI contract is recorded in `docs/testing.md`.

The requested reference branch **`main` does not exist** in this repository
(`git ls-remote --heads origin` lists `master` only; `origin/HEAD →
origin/master`). The comparison was therefore made against **`master`**, the
repository's default branch — see
`docs/evidence/2026-11-05-lazer-proximity-button-removal/05-reference-branch-facts.txt`.

## 3. Files added / modified / deleted

**2 probes + 1 report added, 2 code files + 3 existing documents modified, 0 deleted.**

| Path | Change | Note |
|---|---|---|
| `wp-content/themes/conexao-br-irlanda/assets/js/main.js` | modified | **The fix.** Removed the in-header opt-in control: explanation note creation + `insertNearHeading(explanation)`, `createControl()`, `insertNearHeading(control.wrapper)`, `setControlState()`, the button's `click` listener, and the now-dangling `control`/`explanation` references in `requestPosition()`, `onActivated()`, `onPositionError()`. The geolocation call and all ordering/distance code are unchanged. |
| `wp-content/themes/conexao-br-irlanda/assets/css/leisure.css` | modified | Removed `.leisure-proximity-control`, `.leisure-proximity-toggle`, `.leisure-proximity-explanation` (no producer left). Distance/error/note rules kept. |
| `docs/themes/conexao-br-irlanda.md` | modified | The proximity section no longer documents a rendered control; records the open trigger dependency; files table and accessibility paragraph corrected; `_Last verified_` line appended. |
| `docs/testing.md` | modified | Notes that the two manual Lazer proximity browser suites encode the removed button and cannot pass until a trigger exists (no test weakened). |
| `docs/reports/README.md` | modified | One index row + `_Last verified_` line for this report. |
| `docs/reports/2026-11-05-lazer-proximity-button-removal.md` | added | This report. |
| `docs/evidence/2026-11-05-lazer-proximity-button-removal/` | added | Evidence: two read-only Playwright probes, before/after DOM JSON, header-chain hashes, greps, diff, gate logs, endpoint probes. |

No file outside `wp-content/themes/conexao-br-irlanda/assets/{js,css}` and `docs/`
was touched. **No PHP file changed** (`git status --porcelain | grep '\.php$'` →
empty), so no server-side rendering, route, REST contract, content or schema is
affected.

## 4. Runtime impact

Yes, and only in the browser, only on `/lazer/` and `/en/lazer/`:

* the archive header no longer gains the explanation paragraph and the button;
* no UI element on any page can now request the visitor's position;
* everything else — cards, filters, infinite scroll, the REST endpoints, the
  distance renderer and the ordering helpers — is unchanged.

## 4a. Scope integrity

| Check | Result |
|---|---|
| No production write, deploy, upload or activation | **confirmed** — no production host contacted; only `http://localhost:8080` |
| No production content, Polylang or database change | **confirmed** — probes are GET-only; local DB untouched |
| No Flutter/mobile repository accessed | **confirmed** |
| No `.env` or secret change | **confirmed** |
| No commit, push, reset, clean, checkout, restore, stash or discard | **confirmed** — `git status` at finish contains every pre-existing user change intact |

## 5. Content / data impact

**None.** No post, meta, term, option or user was written. The only requests
issued to the local stack were `GET`s (page loads, both public REST endpoints and
the acceptance matrices). The pre-existing local condition “289 of 293 Lazer
records carry coordinates” is unchanged (`17-localization-service-endpoints.txt`).

## 6. Polylang impact

**None.** No content, taxonomy, translation relationship or locale filter was
touched. `/lazer/` (PT) and `/en/lazer/` (EN) both still answer `200` with the
correct language attribute (`14-acceptance-release-smoke-matrix.txt`: rows
`smoke-archive-lazer`, `smoke-en-pair-lazer` PASS).

## 7. Route / HTTP impact

**None.** No route, filter, redirect, canonical, hreflang or sitemap code was
changed. `wp_add_inline_script`/`wp_enqueue_script` in `inc/assets.php` still
inject `window.ConexaoLeisureLocation` and load `leisure-proximity.js` on the
leisure archive (verified in the browser: `proximityJsLoaded: true`,
`locationConfig: [cardsUrl, coordinatesUrl, lang, locale, maxBatch]`).

## 8. Production actions

| Action | Performed? | Detail |
|---|---|---|
| Production write | **no** | |
| Deploy / upload | **no** | |
| Plugin activation | **no** | |
| Content or DB mutation | **no** | |
| Production read | **no** | The only reads were against the local Docker stack |

## 9. Verification commands and results

| Command | Exit | Result |
|---|---|---|
| `git rev-parse HEAD` / `git status --porcelain` / `git stash list` | 0 | Preflight state recorded (branch `leisure-location`, HEAD `83082ed`, 48 dirty entries = 2 modified docs + 46 untracked Stage 9 evidence/report, 9 stashes) — all preserved |
| `probe-lazer-header-readonly.py` (before) | 0 | `toggleCount: 1`, label `Mostrar distância de mim`, header 5 children, `geoCalls: 0`, cards 10 |
| `probe-lazer-header-readonly.py` (after) | 0 | `toggleCount: 0`, `controlCount: 0`, `proximityNodes: []`, header 3 children, `geoCalls: 0`, cards 10, `proximityJsLoaded: true`, `reapplyHook: "function"` |
| same probe `--path /eventos/` (untouched page) | 0 | `200`, header 3 children, 0 failed responses — proves the JS change is Lazer-scoped |
| `probe-lazer-listings-readonly.py` | 0 | cards **10 → 70** after scrolling (infinite scroll intact), 0 toggles, 0 proximity nodes, 0 page errors |
| `node --check assets/js/main.js` | 0 | `JS SYNTAX OK` |
| `node --test tests/js/test-leisure-proximity.mjs` | 0 | **75 passed, 0 failed** |
| `./scripts/run-tests.sh --scripts` | 0 | **20 script-contract suites: 20 passed, 0 failed** → `ALL TESTS PASSED` (incl. `verify-documentation-drift.py` 14/0, `verify-i18n-freshness.py` 3/0, `verify-script-conventions.py` 761/0, `verify-release-integrity.py` 224/0) |
| `./scripts/run-tests.sh --php --only theme` | 1 | **53 suites: 42 passed, 11 failed; 3300 assertions passed, 42 failed** — all 11 pre-existing (§12); `test-leisure-location-services.php` **102 passed, 0 failed** |
| `./scripts/run-tests.sh --acceptance` | 1 | **4 suites: 1 passed, 3 failed**; release smoke matrix **41 passed, 1 failed** (`/empregos/` 302, pre-existing); **`smoke-archive-lazer` and `smoke-en-pair-lazer` PASS** |
| `python3 tests/acceptance/verify-release-http.py` | 1 | 18 rows: `/lazer/` and `/en/lazer/` **PASS 200**; only `smoke-archive-empregos` fails (pre-existing) |
| `python3 tests/acceptance/verify-routing-http.py` | 1 | **120 passed, 6 failed**; all 8 `/lazer/` + `/en/lazer/` rows **PASS** (incl. `en-lazer-canonical-and-hreflang`, `a11y-lazer-no-option-role`); the 6 failures are all `/empregos/` (pre-existing) |
| `./scripts/lint.sh` | 1 | PHP syntax **OK (532 files)**; PHPStan **0 errors**; PHPCS **FAILED** — pre-existing baseline drift (already recorded as pre-existing at `HEAD` in `docs/reports/2026-11-05-leisure-coordinate-rest-permission-fix.md`); no PHP file was changed by this task |
| `php scripts/generate-registry-docs.php --check` | 0 | `registry OK: 10 plugins validated, 19 generated regions current (zero writes)` |
| `python3 -m py_compile` on both browser suites + both probes | 0 | all compile |
| GET `/wp-json/conexao/v1/leisure-coordinates` | 0 | `200`, `count: 293`, **289** items carry `lat`+`lng`, `lang: pt` |
| GET `/wp-json/conexao/v1/leisure-cards?ids=20948,10146` | 0 | `200`, returns `leisure-card` markup in the requested order |
| GET `/wp-json/conexao/v1/leisure-cards?ids=abc` | — | `400` (contract intact) |

### Numeric test results

In-process PHP (theme component): **53 suites, 42 passed, 11 failed**;
**3300 assertions passed, 42 failed**. Script-contract: **20 suites, 20 passed,
0 failed**. Node: **75/75**. Acceptance: **4 suites, 1 passed, 3 failed**
(**41/42** release-smoke-matrix assertions; 1 failing row).

### HTTP acceptance

Release smoke matrix (18 rows): **41 assertions passed, 1 failed** — the failure
is `smoke-archive-empregos` (`/empregos/` → 302), pre-existing and unrelated.

### Static analysis

PHP syntax: clean. PHPStan (level 5): no errors. PHPCS: red on a **pre-existing**
baseline drift that predates this task and involves PHP files this task never
touched.

### Script-contract results

`./scripts/run-tests.sh --scripts`: **20 suites, 20 passed, 0 failed**.

### Release / build results

Not in scope (no release was requested or performed).

## 10. Failure proofs / negative tests

| Proof | Result |
|---|---|
| The “control present” assertion genuinely discriminates | The **same** probe reported `toggleCount: 1` / `proximityLabel: "Mostrar distância de mim"` before the change and `toggleCount: 0` / `proximityNodes: []` after — it would still be red if the button were re-rendered. |
| The header comparison genuinely discriminates | Before: 5 `.page-header` children incl. both injected nodes. After: 3 children, identical to what `template-parts/archive-header.php` renders. `git diff master -- archive.php template-parts/archive-header.php template-parts/leisure-filters.php header.php` = **0 lines**. |
| No automatic geolocation was introduced | `getCurrentPosition` is instrumented before any page script; **0 calls** before *and* after, on `/lazer/`. |
| Infinite scroll still exercised | 10 → 70 cards; the probe exits non-zero if the count does not grow. |
| The endpoint contract still discriminates | `?ids=abc` → **400** (not silently 200). |

## 11. Regression comparison

| | Before | After |
|---|---|---|
| Lazer header children | 5 (incl. explanation + control) | 3 (shared archive header) |
| `.leisure-proximity-toggle` nodes | 1 | 0 |
| Geolocation requests on load | 0 | 0 |
| Cards rendered / after scrolling | 10 / not measured | 10 / **70** |
| Console 404s on `/lazer/` | 9 (missing local `uploads/2026/08/*-400x300.jpg`) | 9 (identical URLs) |
| JS page errors | 0 | 0 |
| Script-contract suites | — | 20/20 pass |
| In-process theme failures | 11 pre-existing suites | 11 (identical list, §12) |

No PHP or template file was modified, so the server-side suites cannot be
affected; the failing list is enumerated in §12 with the proof of pre-existence.

## 12. Known pre-existing failures

These fail for reasons outside this task; the proof is that the failing code was
never touched (no PHP file changed at all) and, where the suite inspects JS, the
asserted literal was already absent at `HEAD`:

| Suite | Failure | Pre-existence proof |
|---|---|---|
| `test-blog-en-translation.php`, `test-guide-en-translation.php`, `test-job-en-translation.php`, `test-jobs-en-language.php`, `test-en-jobs-shared-slug.php`, `test-stage33-bilingual.php`, `test-stage45-pages.php`, `test-translation-completeness.php`, `test-stage41-rest-language.php` | Local content/Polylang data conditions (a PT guide without an EN pair, the `/empregos/` page pair, EN category term links) | No PHP, content or Polylang change in this task; `git status` shows no PHP file modified |
| `test-event-filter-cache.php` | 5 assertions + Polylang `locale on null` warnings | untouched file/feature (event filters, not Lazer) |
| `test-seasonal-halloween-nav-effects.php` | expects `INITIAL_DELAY_MIN_MS = 20000` etc. | `git show HEAD:.../assets/js/main.js` already contains `= 5000` / `= 10000` — the mismatch predates this task; those constants are outside the edited region |
| `./scripts/lint.sh` PHPCS | baseline grew | Documented pre-existing at `HEAD` in `docs/reports/2026-11-05-leisure-coordinate-rest-permission-fix.md`; zero PHP changed here |
| `verify-event-occurrence-http.py` | Laois archive rows find 0 events | local fixture/content condition |
| `/empregos/` → **302** (release smoke matrix + routing suite) | Jobs landing redirect, together with its EN/PT pair failures above | local content condition; the `/lazer/` rows pass |

## 13. Limitations

* **No end-to-end geolocation run was possible.** The remaining geolocation entry
  point has no UI trigger, so a real permission-granted run (prompt → position →
  reorder → distances) cannot be driven without a code change. What *is*
  verified: the helper library (75 Node assertions), the two REST endpoints over
  real HTTP, the injected config in the browser, the unchanged
  `getCurrentPosition()` call, and the in-process suite (102 assertions).
  Behaviour after the removal was **inspected and statically verified**, not
  exercised end-to-end — the direct consequence of §2, reported rather than
  papered over.
* The two **manual** browser acceptance suites were **not** run to green; they
  encode the removed button (§2). They still compile and still refuse a
  production host.
* The reference branch named in the task (`main`) does not exist; `master` was
  used as the reference.
* No production comparison was made (no production read was authorised or
  performed). Production keeps its deployed theme until a release.

## 14. Evidence paths

`docs/evidence/2026-11-05-lazer-proximity-button-removal/`

| File | Proves |
|---|---|
| `probe-lazer-header-readonly.py` | The read-only probe used before/after (counts geolocation calls, dumps the header DOM). |
| `probe-lazer-listings-readonly.py` | The read-only listings/infinite-scroll probe. |
| `01-before-probe.json` | The button **was** rendered (count 1, label) and 0 automatic geolocation calls. |
| `02-after-probe.json`, `03-after-probe-detailed.json` | Button/control/explanation absent; header = 3 shared nodes; service surface still loaded; 0 geolocation calls; 0 page errors. |
| `04-untouched-page-eventos.json` | `/eventos/` unchanged (0 failed responses) — the change is Lazer-scoped. |
| `05-reference-branch-facts.txt` | `main` does not exist; `master` is the default branch. |
| `06-header-chain-vs-reference.txt` | `archive.php`, `archive-header.php`, `leisure-filters.php`, `header.php` are byte-identical to `master`. |
| `07-change-diff.patch` | The complete diff of this task (2 code files + 3 docs). |
| `08-reference-greps.txt` | No `leisure-proximity-toggle` / `-control` / `-explanation` reference remains under `wp-content/`; the geolocation call site and enqueue/inject code remain. |
| `09-script-contract-gate.log` | 20/20 script-contract suites pass. |
| `10-theme-in-process-suite.log` | 53 theme suites (42 pass) incl. `test-leisure-location-services.php` 102/0. |
| `11-lint.log` | PHP syntax + PHPStan clean; PHPCS pre-existing drift named. |
| `12-node-proximity-suite.log` | 75/75 proximity-helper assertions. |
| `13-acceptance-all-layers.log` | The full acceptance layer run (3 failing suites, all `/empregos/` or event-fixture conditions). |
| `14-acceptance-release-smoke-matrix.txt` | `/lazer/` and `/en/lazer/` PASS; only `/empregos/` fails. |
| `15-acceptance-routing-lazer-rows.txt` | All 8 Lazer routing rows pass (PT + EN, canonical/hreflang, a11y); the 6 failing rows are `/empregos/`. |
| `16-after-listings-infinite-scroll.json` | Listings render and infinite scroll still appends (10 → 70 cards). |
| `17-localization-service-endpoints.txt`, `17b-cards-endpoint-markup.txt` | Both REST endpoints still operate and still validate input. |
| `19-doc-gates-post-edit.txt` | Post-edit re-run of the documentation-drift gate (`14/0`, `GATE-RESULT pass`) and i18n-freshness (`3/0`, `stale catalogues: 0`). |
| `18-final-probe.json` | The mid-task confirmation run: header = 3 shared nodes, 0 button, 0 control, 0 page errors (pre-follow-up: 0 automatic requests). |
| `20-final-script-contract-gate.log` | The post-edit script-contract run (20/20 suites). |
| `21-autostart-granted-denied.txt` | **Post-follow-up:** granted → 1 request, note above the grid, 10 distances, order equal to the independent Haversine computation; denied → one note above the grid, cards intact, header untouched. |
| `22-geolocation-readonly-suite.log` | The maintained read-only browser suite, realigned to the automatic flow: **87 passed, 0 failed**. |
| `23-seeding-suite.log` | The maintained seeding browser suite, realigned: **47 passed, 0 failed**. |
| `24-final-after-autostart.json` | Final header probe after the follow-up: 3 shared nodes, 0 button/control, **1** geolocation request, 0 page errors. |
| `25-after-autostart-desktop.png` | Desktop screenshot with a simulated position: header without any location control, note above the cards, distances on the cards. |
| `26-final-script-contract-after-followup.log` | The post-follow-up script-contract run: **20 suites, 20 passed, `ALL TESTS PASSED`**. |

## 15. Documentation updated

| Document | Change |
|---|---|
| `docs/themes/conexao-br-irlanda.md` | Proximity section rewritten around the removed control + the open dependency note; files table (JS, i18n, CSS rows) and accessibility paragraph corrected; `_Last verified: 2026-11-05 by Lazer header cleanup (proximity control removed)_` appended. |
| `docs/testing.md` | Blockquote recording that the two manual Lazer proximity browser suites encode the removed button (not weakened); existing `_Last verified_` lines kept. |
| `docs/reports/README.md` | Index row for this report + `_Last verified_` line. |

The i18n catalogues (`inc/i18n.php`, `.pot`, `.po`, `.mo`) were deliberately **not**
touched: `leisureDistanceControl`, `leisureLocationExplanation` and
`leisureLocationLoading` stay in the catalogue so the preserved service keeps its
strings and the i18n-freshness gate (POT vs PHP commit time) stays green. No
markup renders them any more.

## 16. Rollback / recovery

`git checkout -- wp-content/themes/conexao-br-irlanda/assets/js/main.js
wp-content/themes/conexao-br-irlanda/assets/css/leisure.css` (plus the three docs)
restores the previous button exactly. Nothing outside the working tree changed, so
rollback has no data consequences. Rollback does **not** cover anything deployed:
nothing was deployed.

## 17. Final status

**Final status: PASS WITH LIMITATION** (task vocabulary: **PASS WITH
LIMITATIONS**)

Every applicable gate that could run was run with real numbers, the button is
provably gone, the header is provably the reference branch's header, and the
location service code is provably intact — but the removal leaves the geolocation
path with no in-page trigger, so the feature could not be exercised end-to-end and
the maintainer must decide on a replacement trigger (§2, §13).

_Last verified: 2026-11-05 by the Lazer header cleanup (proximity button removed; read-only probes, 20/20 script-contract suites, 102 leisure-location assertions)_

## 18. Follow-up (same day): the location service now starts automatically

**Maintainer decision.** Removing the button without a replacement trigger was
reported in §2 as an open dependency, not as a finished change. The maintainer
confirmed the intent: **the button goes, the location service stays** — the
visitor should still be asked for their position and still get distances.

**What changed now** (smallest possible delta on top of §3):

1. `initLeisureProximity()` ends with `requestPosition()` — the existing, unchanged
   one-shot geolocation function. No new API, no new control, no polling, no
   `watchPosition()`, one attempt per page load.
2. `insertNearHeading()` became `insertAboveResults()`: the transient proximity
   notes (the "Ordenado por proximidade" note and the friendly failure message)
   are inserted **directly above the results grid** instead of into
   `.page-header`. Without this the automatic flow would inject runtime nodes into
   the header and break the header-alignment requirement.
3. `assets/css/leisure.css`: the note/error rule gained a bottom margin
   (`8px 0 12px`) so the note sits correctly above the grid.
4. Both **manual** browser acceptance suites were realigned to the automatic
   contract (they encoded the removed control): the control/explanation/retry
   assertions were replaced by *stronger* ones — nothing injected into
   `.page-header`, exactly **one** geolocation request per page load, notes above
   the grid, no retry loop — and the datasets/independent maths are untouched.

**Behaviour change (stated plainly).** Before the 2026-11-05 cleanup the browser
permission prompt appeared only after a click; it now appears **on page load** on
`/lazer/` and `/en/lazer/`. That supersedes the opt-in stance of the 2026-11-04
reports, which stay untouched as history. Nothing else changed: coordinates still
live only in the closure, are never sent to WordPress, never stored and never put
in a query parameter.

**Verification of the follow-up (real numbers, local stack only):**

| Check | Result |
|---|---|
| `probe-lazer-proximity-autostart-readonly.py` | **23/23 checks PASS** — granted: header = `[eyebrow, h1, description]`, 0 button/control/explanation, **1** geolocation request, note above the grid, **10** distances (`490 m de você`, `520 m de você`, …), first 10 cards **equal to an independent Haversine nearest-first order**; denied: 1 attempt, one friendly note above the grid, 10 server-rendered cards intact, 0 distances, 0 page errors |
| `tests/browser/verify-lazer-proximity-geolocation-readonly.py` | **87 passed, 0 failed** (granted / denied / `POSITION_UNAVAILABLE` / `TIMEOUT` / unsupported; expected order + every distance recomputed independently in Python) |
| `tests/browser/verify-lazer-proximity-browser.py` (seeding) | **47 passed, 0 failed** (4 temporary fixtures seeded and deleted; batches match the globally sorted order; filtered page re-applies on its own) |
| `node --check assets/js/main.js` + `node --test tests/js/test-leisure-proximity.mjs` | syntax OK; **75 passed, 0 failed** |
| `python3 -m py_compile` on both suites | both compile |
| `./scripts/run-tests.sh --scripts` | **20/20 suites pass** (re-run after the doc edits) |

**Revised status: `PASS`.** The button is gone, the archive header is exactly the
reference header at runtime as well as in source, and the location service works
end to end without a control — proven in a real browser with a simulated
position, not just inspected.

_Last verified: 2026-11-05 by the automatic-start follow-up (87/87 read-only browser assertions, 47/47 seeding assertions, 23/23 autostart probe checks)_
