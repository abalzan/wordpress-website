# Report — Christmas homepage welcome modal (“One Christmas, Two Skies”)

| | |
|---|---|
| **Stage / task name** | Christmas homepage welcome modal |
| **Date** | 2026-11-06 |
| **Author / agent** | Implementation agent (Cline) |
| **Branch** | `commemorative-events` |
| **Start SHA** | `448cd9dd8458c02430e11238a9fd9a5ab98ed70e` |
| **Final SHA** | `448cd9dd8458c02430e11238a9fd9a5ab98ed70e` (unchanged — no commit was made) |
| **Working tree at finish** | dirty with **exactly** the 12 modified + 6 new paths in §3; 9 pre-existing stashes untouched |
| **Skills followed** | `wp-add-content-type`, `wp-frontend-perf`, `wp-write-in-process-test`, `wp-http-acceptance-matrix`, `wp-security-review`, `wp-update-docs`, `wp-repository` |
| **Authoritative docs consulted** | `AGENTS.md`, `docs/engineering-standard.md`, `docs/commemorative-events.md`, `docs/commemorative-content.md`, `docs/themes/conexao-br-irlanda.md`, `docs/testing.md`, the Christmas signature-experience report |
| **Plan** | [`2026-11-06-christmas-homepage-welcome-modal-plan.md`](2026-11-06-christmas-homepage-welcome-modal-plan.md) |
| **Evidence** | [`docs/evidence/2026-11-06-christmas-homepage-welcome/`](../evidence/2026-11-06-christmas-homepage-welcome/) (33 files) |

## 1. Scope completed

“One Christmas, Two Skies” is now the homepage welcome: the existing Christmas
hero opens as an accessible modal on the first eligible homepage view of a tab
session, and closes into the seasonal band the package already shipped.

1. **One node, two presentations.** `template-parts/commemorative/christmas/hero.php`
   renders the hero **once**, inside a native `<dialog open>`. No second copy of the
   headline, paragraph, star, image or CTA exists anywhere.
2. **Immediate open, session-capped.** `createWelcome()` in
   `assets/js/presentations/christmas/christmas.js` calls `showModal()` as soon as the
   deferred package script runs — no scroll, hover, timer or second gesture — and
   writes `sessionStorage['ce_christmas_welcome_dismissed_v1']` **only** on dismissal.
3. **True modal semantics** from the engine itself: top layer, inert background, focus
   containment, `Escape` and the visible close button all dismiss; focus and the exact
   scroll offset are restored.
4. **No-JS fallback that stays useful.** Without JavaScript (or if the script never
   runs) the band is simply the inline hero it has always been, with a working CTA and
   no dead close button.
5. **Design that continues the package.** Twilight card, the shared star, the gold
   palette, art and copy as siblings, stacked on phones, two columns on desktop,
   internal scrolling at short heights, `dvh` + `env(safe-area-inset-*)`, light/dark,
   reduced motion.
6. **Tests and evidence**: a new in-process suite (109 assertions), a new Node runtime
   suite (14 cases, registered in the existing runtime gate), and a new opt-in
   real-browser verifier covering the required eight-profile matrix in both languages.

## 2. Scope NOT completed

| Not done | Why |
|---|---|
| Production deploy / release / activation | explicitly out of scope; nothing was written to production |
| A public “reopen” launcher | no demonstrated usability need; the request was a welcome, not a widget (plan §2) |
| Fixing the **pre-existing** `/natal/` in-window dispatch defect (§12.1) | it is in `inc/commemorative-presentations.php`, unrelated to this task, and the standard forbids mixing a behaviour fix with a feature change |
| Fixing the **pre-existing** stale `bauble_ornament` acceptance row (§12.2) | the matrix row belongs to the previous stage; “do not rewrite baselines” |
| `.pot` regeneration for the two new strings | the repository defers catalogue regeneration to the release step (previous report §13.2); the literals resolve correctly without it |
| WebKit/Safari run | Playwright WebKit is **not installed** in this environment (§13) |
| Physical-device testing | only browser emulation was available (§13) |

## 3. Files added / modified / deleted

**6 added, 12 modified, 0 deleted.** Every path is inside the plan's §6 list (plus the two documentation indexes the repository requires); no
`wp-content/plugins/` file, no `plugins.json`, no `dist/`, no global bundle changed.

| Path | Change | Note |
|---|---|---|
| `wp-content/themes/conexao-br-irlanda/template-parts/commemorative/christmas/hero.php` | modified | the hero becomes the dialog + parse-time guard; all copy/art/CTA unchanged |
| `wp-content/themes/conexao-br-irlanda/assets/css/presentations/christmas.css` | modified | new §11 “The homepage welcome dialog” (appended; nothing existing altered) |
| `wp-content/themes/conexao-br-irlanda/assets/js/presentations/christmas/christmas.js` | modified | `createWelcome()`, `createSessionFlag()`, `welcomeStorage()`, `bootWelcome()`, boot order, public API |
| `wp-content/themes/conexao-br-irlanda/tests/test-commemorative-christmas-welcome.php` | **added** | 109-assertion in-process contract suite |
| `wp-content/themes/conexao-br-irlanda/tests/test-commemorative-christmas-journey.php` | modified | one superseded assertion replaced by three contract assertions |
| `wp-content/themes/conexao-br-irlanda/tests/test-commemorative-presentation-dispatch.php` | modified | one superseded assertion replaced by two contract assertions |
| `tests/node/christmas-welcome.test.js` | **added** | 14 runtime cases |
| `tests/scripts/verify-commemorative-runtime.py` | modified | the new suite joins the existing runtime gate (no second runner) |
| `tests/browser/verify-christmas-welcome-browser.py` | **added** | four-phase real-browser verifier |
| `docs/themes/conexao-br-irlanda.md` | modified | welcome rows + the display/frequency/failure table |
| `docs/commemorative-events.md` | modified | one presentation rule: a hero may be presented as a welcome |
| `docs/testing.md` | modified | the runtime gate row now names all three Node suites |
| `docs/README.md` | modified | report index row |
| `docs/reports/README.md` | modified | report index row |
| `docs/evidence/README.md` | modified | evidence-folder index row + the corrected folder/file counts |
| `docs/reports/2026-11-06-christmas-homepage-welcome-modal-plan.md` | **added** | the plan |
| `docs/reports/2026-11-06-christmas-homepage-welcome-modal.md` | **added** | this report |
| `docs/evidence/2026-11-06-christmas-homepage-welcome/` | **added** | 33 evidence files |

## 4. Runtime impact

WordPress serves the same routes, the same records and the same assets. On the
homepage, while the Christmas event is window-eligible, the placement markup is now
`<div class="ce-xmas-welcome" data-ce-welcome><script>…guard…</script><dialog …>hero</dialog></div>`
instead of the bare hero. Everything else is unchanged: the same one `<img>` with the
same `srcset`, the same heading, paragraph, star SVG and CTA, the same conditional
asset plan, the same three placements, the same event record.

Client behaviour, stated exactly:

| Situation | Result |
|---|---|
| Homepage, event eligible, first view of a tab session | the dialog opens immediately (`showModal()`), background inert and scroll-locked |
| Dismiss (button / `Escape` / backdrop outside the card) | the flag `ce_christmas_welcome_dismissed_v1` is written to `sessionStorage`; the node returns to the inline band; focus and scroll restored |
| Reload, or return from `/natal/`, in the same tab session | does **not** reopen (band inline) |
| New tab session | eligible again |
| `sessionStorage` denied/throwing | the welcome still opens, the close still works, the in-memory flag caps the document |
| JavaScript disabled / package script never runs | the band is inline and usable; the guard's `DOMContentLoaded` fallback clears the “pending” state; no close button is offered |
| Signed private preview | never auto-opens (`data-ce-welcome-preview="1"`), so the `conexao-preview-banner` stays visible |
| Landing page, internal page, no event, other packages | no welcome markup, no dialog, no new asset |

## 4a. Scope integrity

| Check | Result |
|---|---|
| No production write, deploy, upload or activation | confirmed — no such command was run |
| No production content, Polylang or database change | confirmed — no `wp`/SQL/REST write; the suites are read-only |
| No global bundle change | confirmed — `main.js`, `main.css`, `dark-mode.css` are not in the diff |
| No plugin or registry change | confirmed — `git status wp-content/plugins plugins.json dist` is empty |
| No Flutter/mobile repository accessed | confirmed |
| No `.env` or secret change | confirmed — credentials were read from the environment only |

## 5. Content / data impact

**None.** No post, page, event record, term, user, attachment, option or meta was
created, updated or deleted. The in-process suite proves it: `get_post_meta()` for the
canonical `christmas` record is compared before and after rendering and is
byte-identical (one assertion), and every commemorative gate that reads record state
still passes.

## 6. Polylang impact

- PT content: **unchanged** (immutable in this task).
- The canonical record is still the single owner of the shared `_ce_*` keys
  (`_ce_event_key=christmas`, `_ce_presentation=christmas`, `_ce_rule=fixed_annual`,
  `_ce_date=12-25`) — asserted in the new suite.
- No EN record was created, changed or linked. The completeness gate number is
  unchanged (`translation_completeness`: the same 3 pre-existing violations, §12).
- Language routing is untouched: the CTA keeps resolving through
  `conexao_commemorative_resolve_record()` + `get_permalink()` to `/natal/` or
  `/en/christmas/`, and the suite asserts the exact permalink per language.

## 7. Route / HTTP impact

No route, redirect, canonical, hreflang or sitemap change. Measured over plain HTTP
(`http-asset-and-route-matrix.txt`):

| Route | status | welcome markup | `<dialog>` | Christmas assets |
|---|---|---|---|---|
| `/` | 200 | 0 | 0 | 0 |
| `/en/` | 200 | 0 | 0 | 0 |
| `/guias/` | 200 | 0 | 0 | 0 |
| `/natal/` | 200 | 0 | 0 | 7 CSS handle refs / 2 JS (the documented archival exception) |
| `/en/christmas/` | 200 | 0 | 0 | 7 / 2 (archival exception) |

The event window is 10–25 December, so no homepage route is eligible today; the
previewed homepages were checked instead:

| Preview | welcome roots | `<dialog>` | preview attribute | PT heading | EN heading | `Fechar` | `Close` |
|---|---|---|---|---|---|---|---|
| `/` (PT) | 6 | 1 | 1 | 1 | 0 | 1 | 0 |
| `/en/` (EN) | 6 | 1 | 1 | 0 | 1 | 0 | 1 |

(`welcome roots = 6` counts the `data-ce-welcome` substring across the root, dialog,
close button and guard script; the browser verifier counts elements and reports
**1** real component.)

## 8. Production actions

| Action | Performed? | Detail |
|---|---|---|
| Production write | **no** | — |
| Deploy / upload | **no** | — |
| Plugin activation | **no** | — |
| Content or DB mutation | **no** | — |
| Cache purge | **no** | — |
| Commit / tag / push / release artifact | **no** | — |

## 9. Verification commands and results

| Command | Exit | Result |
|---|---|---|
| `./scripts/lint.sh` | 0 | `lint: OK (syntax clean, no new PHPCS violations, PHPStan clean)` |
| `./scripts/run-tests.sh --only conexao-br-irlanda --php` | 1 | 66 suites: **56 passed, 10 failed**; **3,909 assertions passed, 37 failed** — the 10 failures are the **pre-existing** localisation/jobs suites (§12.3) |
| `python3 tests/scripts/verify-commemorative-runtime.py` | 0 | `GATE-RESULT … status: pass, passed: 66, failed: 0` (ambient 11 + journey 8 + **welcome 14**) |
| `./scripts/run-tests.sh --scripts` | 1 | 21 suites: **19 passed, 2 failed** (`i18n_freshness`, `stage8-release-consistency` — both pre-existing, §12.4) |
| `./scripts/run-tests.sh --acceptance` | 1 | 5 suites: **1 passed, 4 failed** — all four traced to pre-existing local data/matrix state (§12.1–§12.2, §12.5) |
| `python3 scripts/verify-permanent-gates.py --out /tmp/gate.json` | 1 | **15 gates: 13 passed, 2 failed, 0 blocked; 216 assertions passed, 5 failed** — `translation_completeness` and `i18n_freshness`, both pre-existing; **all eight commemorative gates pass, including the runtime gate (66)**. `--out` was used so the tracked Stage L `gate.json` was not overwritten |
| `php scripts/generate-registry-docs.php --check` | 0 | `registry OK: 11 plugins validated, 20 generated regions current (zero writes)` |
| `python3 tests/browser/verify-christmas-welcome-browser.py --out docs/evidence/…` | 0 | `PASS: 0 failure(s)` — **408 checks passed** |

### Numeric test results

- In-process PHP (whole theme layer): **66 suites, 56 passed, 10 failed; 3,909 assertions passed, 37 failed**.
- The **new** suite: **109 assertions, 0 failed**; the two updated suites still pass
  (`test-commemorative-christmas-journey.php` 84, `test-commemorative-presentation-dispatch.php` 100).
- Node: **14 new cases, 0 failed**; the shared gate reports **66 passed, 0 failed**.
- Browser: **408 checks, 0 failures**.

### HTTP acceptance

5 suites, **1 passed, 3 failed, 1 failed** (see the table) — the four failures are the
pre-existing rows of §12; no new matrix row was added, because the popup is DOM/JS
behaviour, not an HTTP response (plan §15).

### Static analysis

PHP syntax, PHPCS and PHPStan all clean (`lint.txt`).

### Script-contract results

21 suites, 19 passed, 2 failed — both pre-existing.

### Release / build results

Not in scope: no version bump, no artifact, no deploy.

### 9.1 The eight required viewport profiles (measured, not asserted by eye)

Every row is a `PASS` in `browser-verification.json` (`phases.preview.profiles` and
`phases.harness.profiles`):

| Profile | Viewport | Dialog box (x, y, w × h) | Close control | Panorama ratio (natural → rendered) | Result |
|---|---|---|---|---|---|
| Small iPhone class | 320 × 568 | 12, 19, 296 × 531 | 100 × 44 | 1.771 → 1.778 | PASS |
| iPhone SE / compact | 375 × 667 | 12, 78, 351 × 511 | 100 × 44 | 1.777 → 1.778 | PASS |
| Modern iPhone | 390 × 844 | 12, 163, 366 × 519 | 100 × 44 | 1.781 → 1.778 | PASS |
| Small Android | 360 × 800 | 12, 136, 336 × 528 | 100 × 44 | 1.783 → 1.778 | PASS |
| Large phone | 430 × 932 | 12, 195, 406 × 541 | 100 × 44 | 1.777 → 1.778 | PASS |
| Tablet portrait | 768 × 1024 | 104, 210, 560 × 604 | 100 × 44 | 1.778 → 1.778 | PASS |
| Desktop | 1366 × 768 | 203, 218, 960 × 332 | 100 × 44 | 1.778 → 1.778 | PASS |
| Landscape phone | 844 × 390 | 25, 45, 793 × 300 | 100 × 44 | 1.778 → 1.778 | PASS |

For each profile the verifier also asserts: no horizontal page overflow, the card is
fully inside the viewport on both axes and horizontally centred, the background is
`position: fixed` (no page scroll), focus is inside the dialog, the close control is
≥ 44 × 44 and inside the card and the viewport, the CTA is ≥ 44 px tall and reachable
(by scrolling **inside** the dialog when needed), and there is no console error, page
error or failed request.

### 9.2 Frequency, failure and preference behaviour (real browser)

| Behaviour | Evidence |
|---|---|
| Opens with no user gesture | `wait_for_function(:modal)` after `networkidle` at all eight profiles |
| Dismissal is per tab session | close button → `sessionStorage['ce_christmas_welcome_dismissed_v1'] === '1'`; `localStorage` for that key is `null` |
| Reload in the same session does not reopen | reload → `:modal` false, band visible |
| A new session is eligible again | fresh context → opens |
| `sessionStorage` denied | `sessionStorage` getter throws → welcome opens, close works, no page error |
| `Escape` and the close button | both dismiss; the band returns; the banner (in preview) stays visible |
| No reopen on resize/orientation/focus | the runtime suite asserts only `close` + `click` are registered on the dialog and only `click` on the control |
| CTA keeps navigating | click → lands on `http://localhost:8080/natal/` |
| Exact scroll restore | pre-scroll to 260 → open (lock `top: -260px`) → close → `scrollY === 260` |
| **JavaScript disabled** (real Chromium, `java_script_enabled=False`) | band visible inline, CTA visible and pointing at `/natal/`, heading is server text, **no close control** and no dead modal |
| Reduced motion | dialog and `::backdrop` `animation-name: none` |
| Light and dark | title ink `rgb(27,36,71)` → `rgb(253,247,238)`; CTA gold `rgb(232,194,115)` in both |
| Preview preserved | banner visible before, during (never opened) and after the driven modal |

### 9.3 Performance, measured

| Measurement | Result |
|---|---|
| Derivative transferred, mobile (390 × 844) DPR 1 | `two-skies-hero-840.webp`, **96,892 B** |
| Derivative transferred, mobile DPR 2 | `two-skies-hero-840.webp`, **96,892 B** |
| Derivative transferred, desktop (1366 × 768) DPR 1 | `two-skies-hero-840.webp`, **96,892 B** |
| Derivative transferred, desktop DPR 2 | `two-skies-hero-1200.webp`, **168,988 B** |
| Requests for the panorama per load | **exactly 1** at every profile |
| LCP, `/` (no event) | `conexaobr_Hero_image_mobile.webp` (IMG) |
| LCP, previewed homepage with the band inline | `conexaobr_Hero_image_mobile.webp` (IMG) |
| LCP, previewed homepage with the modal open | `conexaobr_Hero_image_mobile.webp` (IMG) — **unchanged** |
| LCP, `/natal/` | `star-bridge-780.webp` (IMG) |
| New third-party script, font, video, canvas/WebGL, analytics, timer | **none** (source and runtime) |

The modal image is `loading="eager"` + `fetchpriority="low"` exactly as before, and
the measurements confirm the community hero keeps LCP in all three states. No new
file request exists: the welcome reuses the placement's own two derivatives.

## 10. Failure proofs / negative tests

The new suites were shown to **fail** before they were trusted:

| Proof | Result |
|---|---|
| `sessionStorage` denial | first harness run reported 3 failures (scroll restore) before the test scrolled the page in a session where the welcome was already dismissed — the assertion was fixed, not the product |
| Chromium refuses `showModal()` on a dialog that still carries `open` | observed live: `InvalidStateError: The dialog is already open as a non-modal dialog`. The controller now releases `open` in the same task and re-adds it on close; the Node suite encodes that engine behaviour in its fake `showModal()` |
| Missing close control / missing `showModal()` | the Node suite asserts `createWelcome()` returns `null`, the band stays inline and no close button is revealed |
| Stale `[data-ce-reveal]` hooks | inspected: real for Halloween, dead for Christmas (`grep` in §4 of the plan); documented instead of assumed |
| The wrong asset stack in the harness | the first harness run produced 0 CSS links because the local stack serves one concatenated sheet; the verifier now takes the page's whole stylesheet stack |
True negative proofs of the *gates* themselves (the repository's “a gate that never failed is not a gate” rule) are owned by the existing suites that already carry fixture-based failure proofs; none was weakened.

## 11. Regression comparison

| | Before (documented baseline) | After (this run) |
|---|---|---|
| Failing in-process suites | 10 (`test-blog-en-translation`, `test-en-jobs-shared-slug`, `test-event-filter-cache`, `test-guide-en-translation`, `test-job-en-translation`, `test-jobs-en-language`, `test-stage33-bilingual`, `test-stage41-rest-language`, `test-stage45-pages`, `test-translation-completeness`) | **the same 10** |
| Failing assertion count | 37 | **37** |
| Failing script-contract suites | 2 (`i18n_freshness`, `stage8-release-consistency`) | **the same 2** |
| Failing acceptance assertions | not measured by the previous stage | 4 suites, all traced to pre-existing data (§12) |
| Failing permanent gates | 2 (`translation_completeness`, `i18n_freshness`) | **the same 2** |

“Before” is the baseline recorded in
[`2026-11-06-commemorative-events-christmas-signature-experience.md`](2026-11-06-commemorative-events-christmas-signature-experience.md)
§13.3 (same 10 suites, 37 failed assertions) and §13.2 (same 2 gates). The diff of the
failing lists is **empty**; the new suite, the new Node suite and all commemorative
gates pass.

## 12. Known pre-existing failures

None of these is attributable to this task: no database write was performed, and the
suites involved reference neither the Christmas hero nor the welcome.

1. **The Christmas landing page is empty *while its own event is active*.**
   Found (not created) during this work. `conexao_commemorative_placement_context()`
   returns the **event** branch for `landing_page` whenever the package declares that
   placement on the `landing` route, and that branch carries `page_id = 0`; the shared
   landing shell then returns early. Reproduced with plain HTTP on the real site:
   `GET /natal/?conexao_preview=christmas&…` renders **0** `.ce-landing__title`,
   while the same URL without the preview renders **1**. The motif/markup was not
touched by this task and `inc/commemorative-presentations.php` is **not** in the
   diff. **Recommended:** a separate one-line fix (`$plan['landing']['page_id']` must
   win when the landing placement is present), with its own plan.
2. **`verify-commemorative-http.py`: `pt-landing-page /natal/` expects
   `data-ce-motif="bauble_ornament"`.** The retired motif; the package has declared
   `shared_star` since `448cd9d`, and `grep -r bauble_ornament wp-content/themes`
   returns nothing. The row is stale relative to HEAD, not to this task.
3. **The ten red theme suites** — local EN translation / Polylang relationship data
   (`missing EN translation`, `en_without_pt:empregos`, orphan EN pages). Identical set
   and identical 37 failing assertions as the recorded baseline.
4. **`verify-i18n-freshness.py`** — three stale `.pot` catalogues. The gate compares
   **git commit** timestamps, so uncommitted work cannot influence it; the three are
   the same ones the previous report records (2 pre-existing + 1 from that stage's
   external commit). POT regeneration stays deferred to the release step.
5. **`verify-stage8-release-consistency.py`** — `conexao-data-model` header `1.7.0`
   vs `dist/release.json` `1.6.3`, `conexao-admin-ux` `1.0.9` vs `1.0.8`: plugin
   versions were bumped without rebuilding `dist/`. No plugin file and no `dist/` file
   is in this task's diff.
6. **`verify-event-occurrence-http.py` / `verify-release-http.py` /
   `verify-routing-http.py`** — the Laois weekly/all-day fixtures and the `/empregos/`
   page relationship (302 instead of 200). Local content conditions recorded by
   earlier reports.

## 13. Limitations

- **Browser emulation only.** Chromium 151.0.7922.34 through Playwright, no physical
  device. `390 × 844`, `320 × 568`, `430 × 932` etc. are *viewports*, not hardware.
- **No WebKit / iOS Safari run.** Playwright WebKit is not installed in this
  environment, so the Safari-specific `<dialog>`, `dvh` and `position: fixed` scroll
  lock behaviour is designed defensively (`dvh` fallback to `vh`, negative-`top` lock,
  explicit `scrollTo` restore) but **not verified on that engine**.
- **No real production render.** The popup uses the existing placement, and the live
  event window is 10–25 December; the homepage states here are the signed private
  preview and a harness generated from that exact markup.
- **No production deploy.** Out of scope; nothing was pushed, activated or released.
- **Lighthouse / field data absent.** LCP was measured with a `PerformanceObserver` in
  Chromium, not with field RUM data.
- **The `[data-ce-reveal]` shell hooks** remain inert for Christmas (documented, not
  removed) because Halloween depends on them.
- **The inline band keeps the site-wide `.btn-primary` pairing** (white on the theme
  orange) exactly as before this task; only the *modal* states the package's
  gold-on-navy CTA pair. Changing the global button is a site-wide design decision,
  not part of a Christmas presentation task, and doing it here would have made the
  band differ from every other primary button on the site.

## 14. Evidence paths

[`docs/evidence/2026-11-06-christmas-homepage-welcome/`](../evidence/2026-11-06-christmas-homepage-welcome/)
(33 files) — `browser-verification.json`, 20 screenshots (8 preview profiles, 8
harness profiles, 4 public routes), the two generated harness pages, the suite/gate/lint
outputs, the HTTP route+asset matrix and the git state at both ends. The folder's
`README.md` maps each file to the claim it supports.

## 15. Documentation updated

| Document | Change |
|---|---|
| `docs/themes/conexao-br-irlanda.md` | three new rows (welcome markup, controller, styles) and a new “Homepage welcome — display, frequency and failure behaviour” table |
| `docs/commemorative-events.md` | one presentation rule: a `homepage_hero` may be presented as a once-per-tab-session welcome, same placement/template/record/assets |
| `docs/testing.md` | the `commemorative_ambient_runtime` gate row now describes and lists all three Node suites |
| `docs/README.md` | report + evidence index rows |
| `docs/reports/README.md` | index entry for the plan and the report |

No procedure moved out of a document into a skill, so nothing is lost.

## 16. Rollback / recovery

Revert the six implementation files (template, CSS, JS, two test files, one gate
entry) and the documentation; no content, option, schema, cache or storage state is
created by this change, so no data reversal is needed. A visitor's dismissal lives in
their own tab and expires with it.

## 17. Final status

**Final status: PASS WITH LIMITATION**

Everything the task could verify here passed with real numbers: 109 in-process
assertions, 14 Node runtime cases (66 in the shared gate), 408 browser checks across
the eight required viewport profiles in both languages, clean lint, zero registry
drift, and an empty diff in the failing-suite lists. The limitation is environmental
and stated in §13: only Chromium emulation was available — **no WebKit/Safari and no
physical device** — and the live homepage could only be exercised through the signed
private preview plus a harness generated from its exact markup, because the Christmas
window is 10–25 December.

## 18. Acceptance verdict against the brief

| Requirement | Result |
|---|---|
| Uses the existing placement; no new event system | **met** — same record, package, placement, template, asset plan; no new CPT/meta/route/query parameter |
| Opens immediately for eligible visitors, once per session | **met** — auto-open with no gesture; `sessionStorage` cap; verified in the browser |
| No-JS fallback remains useful | **met** — `dialog open` band, guard fallback, no dead control |
| Keyboard, screen-reader, reduced-motion, focus | **met** — native modality + `aria-labelledby`/`aria-describedby`, Escape/close, focus restore, `animation: none` |
| Mobile matrix incl. 320 × 568 and landscape | **met** — 8/8 profiles; no clipping, crop, overflow or background scroll |
| PT-BR and English | **met** — headings, copy, alt text, close label and CTA permalink asserted per language |
| No regressions on homepage, routes, assets, bundles, gates | **met** — failing lists unchanged, all commemorative gates green, no bundle touched |
| Worktree and pre-existing changes preserved | **met** — HEAD, branch and 9 stashes unchanged; only this task's 18 paths are dirty |

_Last verified: 2026-11-06 by the Christmas homepage welcome-modal implementation_
