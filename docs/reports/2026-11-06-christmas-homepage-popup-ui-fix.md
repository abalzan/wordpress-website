# Report — Christmas homepage popup UI fix (“Um Natal, dois céus” as a real modal)

| | |
|---|---|
| **Stage / task name** | Christmas homepage popup UI fix |
| **Date** | 2026-11-06 |
| **Author / agent** | Implementation agent (Cline) |
| **Branch** | `commemorative-events` |
| **Start SHA** | `0318839c0d6c014d8fb4580875a4560f62e6e1cd` |
| **Final SHA** | `0318839c0d6c014d8fb4580875a4560f62e6e1cd` (unchanged — no commit was made) |
| **Working tree at finish** | dirty with exactly the files listed in §3; **9 pre-existing stashes untouched** |
| **Skills followed** | `wp-frontend-perf`, `wp-write-in-process-test`, `wp-update-docs` (+ `wp-testing` conventions via `./scripts/run-tests.sh`) |
| **Authoritative docs consulted** | `AGENTS.md`, `docs/engineering-standard.md`, `docs/commemorative-events.md`, `docs/themes/conexao-br-irlanda.md`, `docs/testing.md`, `docs/commemorative-content.md` |

## 1. Scope completed

The Natal homepage welcome now behaves as a **real, accessible modal** instead of
the visible card straddling the bottom of the homepage hero:

1. **The dialog ships CLOSED.** `template-parts/commemorative/christmas/hero.php`
   no longer renders `open`; the only way the dialog opens is
   `HTMLDialogElement.showModal()` from `createWelcome()`, so the card is always
   in the browser's modal top layer with the viewport `::backdrop`, centred and
   independent of `.hero-section` positioning/overflow/stacking.
2. **A compact fallback card** (heading + the same landing CTA, no artwork)
   replaces the old full-width inline band for the no-JS / no-`showModal()` /
   failed-`showModal()` / guard-timeout paths. It sits in the normal flow under
   the hero content, so it can never cover the copy or the sponsor carousel.
3. **The preview contract is explicit.** The signed preview never auto-opens,
   never renders an inline dialog, and never touches the visitor dismissal key;
   the fallback card carries a hidden-until-wired **preview trigger**
   (`data-ce-welcome-preview-open`) that opens the exact same modal through
   `showModal()` and returns to the trigger card on close (state `preview`).
4. **Dismissal leaves nothing behind.** The post-dismissal “inline band” is gone:
   the dialog stays closed, the fallback stays hidden
   (`data-ce-welcome-state="dismissed"`), the 286 px `.hero-section`
   `padding-bottom` reservation is removed and the Christmas placement is
   `position: static` at every width — measured hero geometry is identical at
   open, after close and after reload (`620 px / 16 px padding` in all three
   states on the harness).
5. **Deterministic progressive-enhancement hand-off.** The parse-time guard still
   capability-tests `showModal()` and marks the page `pending` (hiding the
   fallback before first paint); its `DOMContentLoaded` fallback now reveals the
   **fallback** (state `fallback`) instead of an inline band, and a controller
   that arrives later legitimately overrides that state. Claiming a root is
   idempotent (`data-ce-welcome-claimed`), so a repeated boot adds no listeners
   and never opens twice.
6. **Everything else preserved:** artwork, copy, shared Brazil/Ireland identity,
   the `/natal/` + `/en/christmas/` CTA destinations, the session-scoped
   `ce_christmas_welcome_dismissed_v1` key, preview security, the one-event →
   one-package → one-placement model, and the recently fixed landing-page
   dispatch (re-verified: landing suites green, landing routes carry no welcome).

## 2. Scope NOT completed

| Not done | Why |
|---|---|
| Production deploy / release / activation / commit / push | explicitly out of scope; nothing was written to production |
| WebKit / iOS Safari verification | Playwright WebKit is not available in this environment — recorded as a limitation (§13), not implied as verified |
| A browser-level test with `showModal` removed from the DOM API | the unsupported-engine path is proven in the Node runtime suite instead (scene `showModal: false`); the browser harness covers no-JavaScript, which exercises the same fail-closed markup state |
| Editing the stale `bauble_ornament` acceptance row or the `/empregos/` 302 rows | pre-existing failures unrelated to this task; “do not rewrite baselines” (§12) |
| `.pot` regeneration for the two new strings (`Open the welcome preview` / `Abrir a pré-visualização do boas-vindas`) | same policy as the previous welcome stage: the theme uses `$ce_english ? __( … ) : __( … )` for immediate correctness; recorded as a follow-up |

## 3. Files added / modified / deleted

| Path | Change | Note |
|---|---|---|
| `wp-content/themes/conexao-br-irlanda/template-parts/commemorative/christmas/hero.php` | modified | dialog renders closed; guard hands off to `fallback`; compact fallback card + hidden preview trigger added; docblock rewritten |
| `wp-content/themes/conexao-br-irlanda/assets/css/presentations/christmas.css` | modified | hero reservation removed; placement `static` at all widths for this package; §11 rewritten (closed-dialog backstop, state-scoped visibility, fallback card, preview trigger); no global selectors |
| `wp-content/themes/conexao-br-irlanda/assets/js/presentations/christmas/christmas.js` | modified | state machine (`open`/`dismissed`/`fallback`/`preview`), idempotent claim, preview trigger wiring, preview never writes the dismissal key, settle() never re-opens the dialog |
| `wp-content/themes/conexao-br-irlanda/tests/test-commemorative-christmas-welcome.php` | modified | 109 → **132** assertions: closed-dialog contract, fallback/trigger markup, no-reservation CSS, scoped selectors, controller states |
| `wp-content/themes/conexao-br-irlanda/tests/test-commemorative-christmas-journey.php` | modified | one-CTA rule restated: two anchors, same destination (+5 assertions, 83 → **88**) |
| `tests/node/christmas-welcome.test.js` | modified | 14 → **18** cases: closed dialog, throwing `showModal()`, idempotent boot, late-controller hand-off, preview trigger/isolation, focus enter/return |
| `tests/scripts/verify-commemorative-runtime.py` | modified | welcome `min_cases` 14 → 18 (gate total 66 → **74**) |
| `tests/browser/verify-christmas-welcome-browser.py` | modified | preview phase drives the trigger; closed-dialog/no-inline assertions; no-JS fallback phase; dismissal residue + hero-geometry checks; EN preview; viewport matrix now 320×568, 375×667, 390×844, 768×1024, 1024×768, 1366×768, 1440×900, 844×390; harness capture restores server truth (`data-ce-welcome-claimed` stripped, trigger re-`hidden`) |
| `docs/themes/conexao-br-irlanda.md` | modified | welcome rows + display/frequency/failure table rewritten for the closed-dialog contract |
| `docs/commemorative-events.md` | modified | the “hero as a welcome” presentation rule rewritten |
| `docs/testing.md` | modified | `commemorative_ambient_runtime` gate row updated |
| `docs/README.md` | modified | report + evidence index row added |
| `docs/reports/README.md` | modified | index row added; previous welcome-modal row kept with a “superseded” pointer |
| `docs/reports/2026-11-06-christmas-homepage-popup-ui-fix.md` | added | this report |
| `docs/evidence/2026-11-06-christmas-homepage-popup-fix/` | added | before/after browser state, screenshots, suite/gate/lint outputs (see its `README.md`) |

Counts: **1 added report + 1 added evidence directory, 13 modified files, 0 deleted.**
All `wp-content/` changes are inside the theme's Christmas presentation
(template, package CSS, package JS) and its two test suites — no plugin, no
engine, no `functions.php`, no `inc/` file changed.

## Root cause (proven from source and browser runtime)

**The capture was a signed private preview, not ordinary public traffic.**
`data-ce-welcome-preview="1"` is emitted only by
`template-parts/commemorative/christmas/hero.php` when
`conexao_commemorative_is_preview()` is true — the engine's signed capability
(`?conexao_preview=christmas&_wpnonce=…&_ce_ts=…`, honoured only for a logged-in
`manage_options` user holding `edit_post` on the canonical record and a valid
per-key nonce). Proven at runtime: `before-browser-state.json` records
`preview: true` with the `conexao-preview-banner` visible, while the public
homepage renders **0** welcome roots and **0** dialogs (event window is
10–25 December; today is 6 November).

**What was actually broken, measured in Chromium before the fix**
(`before-browser-state.json` + `before-preview-*.png`):

| Measurement | Before |
|---|---|
| `document.documentElement[data-ce-welcome-state]` | `"inline"` |
| `dialog.hasAttribute('open')` | `true` |
| `dialog.matches(':modal')` | **`false`** — a non-modal dialog is not in the top layer, has no backdrop and is not centred by the UA |
| close control | `hidden`, not visible |
| computed `dialog` box | `position: static`, 958 × 253 at `y=715` — laid out inside the hero, not the viewport |
| computed placement | `position: absolute; inset: 0; z-index: 20` inside `.hero-section` (`position: relative; overflow: hidden`) |
| `.hero-section` `padding-bottom` | **`286.86px`** — the reservation rule in `christmas.css` (`clamp(286px, 21vw, 344px)`), inflating the hero from 563 px (no event) to **850 px** |
| console / page errors | none — this was a working *design*, not a crash |

**Why the card straddled the boundary:** the placement is a bottom-aligned
absolute overlay (`align-items: flex-end`) whose bottom edge is the hero's
bottom edge, so the 253 px card sat flush on the inflated hero's lower boundary
with its shadow crossing into the next section — exactly the screenshot.

**Why the inline dialog existed at all:** three cooperating pieces of the
previous contract — (a) the server rendered `<dialog open>` so the no-JS
visitor had an inline hero; (b) the preview contract suppressed the auto-open,
leaving that `open` dialog non-modal and visible (state `inline`); (c) `settle()`
re-added `open` after any dismissal, so the same band reappeared for public
visitors too. An `open` attribute alone never calls `showModal()`, so the page
never entered the modal top layer in any of those states.

**The guard hand-off (why the controller was not “broken”):** the inline
parse-time script capability-tests `showModal()` and sets `pending`; the package
script is enqueued in the footer with `strategy => defer`
(`conexao_commemorative_enqueue_assets()`, hook `wp_enqueue_scripts` priority
20 → shared `commemorative.js` + `presentations/christmas/christmas.js`, styles
`commemorative.css` + `presentations/christmas.css` declared after
`conexao-dark-mode`). Deferred scripts execute at `readyState === "interactive"`,
**before** `DOMContentLoaded`, so `boot()` → `bootWelcome()` claims the root
first and the guard's DCL fallback only fires when the controller genuinely
never arrived. The controller therefore *did* run in the capture — it simply
honoured the preview rule and never opened. The fix keeps this hand-off and
changes only what the states *display* (`inline` → `fallback` / `preview` /
`dismissed`) plus the markup they apply to (closed dialog + fallback card).

## Before / after behaviour

| Path | Before | After |
|---|---|---|
| Public homepage, first eligible view of a session | `showModal()` opened the modal (already correct), but the server-rendered `open` meant any failure/preview/no-JS state showed a full-width card in an inflated hero | identical auto-open via `showModal()` (state `open`, `:modal` true, backdrop `rgba(8,12,34,0.66)`, centred); the dialog ships closed so no state can paint an inline card |
| Public homepage after dismissal | band reappeared inline (`open` re-added, state `inline`) inside a hero padded +286 px | state `dismissed`: dialog closed, fallback hidden — hero geometry identical to the pre-modal page (harness: 620/16 in all three states), no ghost card, no gap |
| Signed preview | `data-ce-welcome-preview="1"` + visible non-modal `<dialog open>` card at the hero bottom = the screenshot | state `preview`: dialog closed, compact fallback card with a **revealed preview trigger**; clicking it opens the same modal (`:modal`, backdrop, centred); Escape/close returns to the trigger card; `sessionStorage` key stays `null`; banner never covered |
| No JavaScript | full hero band inline (243–261 px) inside the reserved hero | compact fallback card (heading + CTA) in normal flow; dialog closed; no dead close/trigger buttons; hero reservation removed |
| `showModal()` throws / API missing / controller never arrives | band inline (state `inline`) | state `fallback`: dialog closed, fallback card visible, close stays hidden (Node-proven for the throwing and missing-API cases) |
| Public homepage, no active event | no welcome | unchanged: 0 welcome roots, 0 dialogs, no commemorative asset (phase A) |

## 4. Runtime impact

WordPress behaves differently **only** on the Christmas `homepage_hero`
placement, and only in presentation:

- the server-rendered dialog loses its `open` attribute (closed by default);
- a compact fallback card + hidden preview trigger are emitted with the same
  welcome component;
- the hero no longer reserves `padding-bottom: clamp(286px, 21vw, 344px)` and
  the placement wrapper is `position: static` for this package at every width;
- the controller owns five explicit page states instead of “inline vs open”.

Verified: the public homepage with no active event still emits **0** welcome
roots and **0** dialogs (`after-browser-state.json` → `public_homepage`), the
landing suite (87 assertions) and presentation-dispatch suite (120) are green,
and no route, query, meta, cache key or enqueue condition changed (the asset
plan is produced by the same dispatcher from the same plan object).

## 4a. Scope integrity

| Check | Result |
|---|---|
| No production write, deploy, upload or activation | **none performed** |
| No production content, Polylang or database change | **none** (all suites read-only; rendering asserted byte-identical event meta) |
| No engine / plugin / CPT / meta / route / endpoint / scheduler added | **confirmed** — `git diff --stat` touches only theme presentation, theme tests, shared test files and docs |
| No Flutter/mobile repository accessed | **confirmed** |
| No `.env` or secret change | **confirmed** |

## 5. Content / data impact

**None.** No record was created, updated or deleted; `git status` shows only
the files in §3. The welcome dismissal lives in the visitor's own tab
(`sessionStorage`) and is written **only** by a public close — the preview
close is asserted to leave the key `null` (browser + Node).

## 6. Polylang impact

**None.** No Polylang rule, translation or meta was touched. PT and EN strings
resolve through the template's existing `$ce_english ? __( … ) : __( … )`
convention; the EN preview phase asserts `One Christmas, two skies`, the EN
fallback heading and the `/en/christmas/` CTA, and the PT suite asserts no PT
string leaks into the EN rendering (and vice versa). The two new UI strings are
an EN/PT pair pending a `.pot` regeneration (§2).

## 7. Route / HTTP impact

No route, redirect, canonical, hreflang or sitemap change. The HTTP acceptance
rows re-ran in the full harness (§9); `/natal/` and `/en/christmas/` still
render the landing experience without the welcome (browser phase B: 0 welcome
roots, 0 dialogs, only the package's own assets for the archival exception),
and the CTA navigation lands on `/natal/` from the PT modal and on
`/en/christmas/` from the EN preview.

## 8. Production actions

| Action | Performed? | Detail |
|---|---|---|
| Production write | **no** | |
| Deploy / upload | **no** | |
| Plugin activation | **no** | |
| Content or DB mutation | **no** | local suites are read-only; no seed, no migration, no option write |

## 9. Verification commands and results

| Command | Exit | Result |
|---|---|---|
| `node --test tests/node/christmas-welcome.test.js` | 0 | **18 tests, 18 pass, 0 fail** |
| `python3 tests/scripts/verify-commemorative-runtime.py` | 0 | `commemorative_ambient_runtime`: **74 passed, 0 failed** (gate `GATE-RESULT … "status":"pass"`) |
| `docker compose exec -T wordpress php …/tests/test-commemorative-christmas-welcome.php` | 0 | **132 passed, 0 failed** |
| `… test-commemorative-christmas-journey.php` | 0 | **88 passed, 0 failed** |
| `… test-commemorative-presentation-dispatch.php` | 0 | **120 passed, 0 failed** |
| `… test-commemorative-landing-page.php` | 0 | **87 passed, 0 failed** |
| `./scripts/lint.sh` | 0 | “lint: OK (syntax clean, no new PHPCS violations, PHPStan clean)” |
| `php scripts/generate-registry-docs.php --check` | 0 | “registry OK: 11 plugins validated, 20 generated regions current (zero writes)” |
| `python3 tests/scripts/verify-agent-governance.py` | 0 | **357 passed, 0 failed** |
| `python3 tests/scripts/verify-documentation-drift.py` | 0 | **14 passed, 0 failed** (`documentation_drift` gate pass) |
| `python3 tests/browser/verify-christmas-welcome-browser.py --out docs/evidence/2026-11-06-christmas-homepage-popup-fix` | 0 | **501 checks, 0 failed** — phases A–D, eight viewports, PT + EN (chromium 151.0.7922.34) |
| `./scripts/run-tests.sh` (full harness, after) | 1 | 137 PHP suites **126 passed / 11 failed** (assertions **10064 / 38 failed**); script-contract **19/2**; acceptance **1/4** — every failure pre-existing (§12); failing-suite diff vs baseline contains **no new failure** (§11) |

### Numeric test results

In-process PHP (focused, all green): **4 suites, 427 assertions passed,
0 failed** (132 welcome + 88 journey + 120 dispatch + 87 landing).
Full-harness totals: §11.

### HTTP acceptance

Re-run inside the full harness (§11): the same five acceptance suites as the
baseline, including `verify-commemorative-http.py` (commemorative matrix —
public rows, preview-without-session rows, landing rows) and
`verify-routing-http.py`. Row-level counts: §11.

### Static analysis

`./scripts/lint.sh`: PHP syntax clean, **no new PHPCS violations**, PHPStan
clean — exit 0 (`lint.txt`).

### Script-contract results

`commemorative_ambient_runtime` **74/0**; the remaining script-contract suites
run inside the full harness (§11) — the baseline's two pre-existing failures
(`verify-i18n-freshness.py`, `verify-stage8-release-consistency.py`) are
expected to persist unchanged.

### Release / build results

not in scope (no release was produced or verified).

## 10. Failure proofs / negative tests

| Proof | Result |
|---|---|
| Re-introduce the server-rendered `open` attribute **and** the 286 px hero reservation, then run the welcome suite | **3 assertions fail exactly as designed** (`no server-rendered open attribute…`, `the preview never renders a non-modal open dialog…`, `the hero reserves no band height…`), 129 passed / 3 failed — `vacuity-proof-reintroduced-open-and-reservation.txt`; both files restored byte-identically (md5 `ffafc00e…` / `ec6229aa…` re-checked) |
| Browser assertions discriminate (organic) | the first verifier run against an intermediate build failed **96 layout assertions** (a state rule that hid the dialog while open) plus 2 EN-markup assertions; after the fix the same verifier passes **501/501** — `browser-verify.log` |
| Preview isolation is asserted positively, not assumed | browser: `sessionStorage` key is `null` after preview open + Escape + reload; Node: `store.getItem(KEY) === null` after a preview close |
| The fail-closed path is proven negatively | Node: scene with `showModal: false` and with `showModal()` throwing both end in state `fallback`, dialog closed, close hidden, no scroll lock |

## 11. Regression comparison

Before (baseline, started on the clean tree — see the caveat in §12) vs after
(the same `./scripts/run-tests.sh` on the finished tree):

| | Before (baseline) | After |
|---|---|---|
| In-process PHP suites failing | 13 (incl. 2 mixed-state rows of this task's suites, see §12) | **11** |
| Assertions failing | 43 | **38** |
| Script-contract suites failing | 2 (`verify-i18n-freshness.py`, `verify-stage8-release-consistency.py`) | **2 — the same two** |
| HTTP acceptance suites failing | 4 (`commemorative-http`, `event-occurrence-http`, `release-http`, `routing-http`) | **4 — the same four** |

**Diff of the failing-suite lists:** baseline-only = `test-commemorative-christmas-welcome.php`
and `test-commemorative-christmas-journey.php` (the two mixed-state rows of §12,
both green post-change: **132/0**, **88/0**); after-only = **∅**; common = **17**,
every one pre-existing. **The diff contains no new failure** — no baseline
fixture was edited to make anything pass.

## 12. Known pre-existing failures

Each of these fails for reasons unrelated to this change (none touch the
Christmas welcome, the theme presentation files or their tests), and each was
already failing in the recorded baseline:

| Suite / gate | Why it is not this task's |
|---|---|
| `plugin/conexao-event-importer/test-town-sanitization.php` (1 assertion) | importer town-name sanitisation fixture |
| `theme/…/test-blog-en-translation.php` (3), `test-guide-en-translation.php` (7), `test-job-en-translation.php` (4), `test-jobs-en-language.php` (6), `test-en-jobs-shared-slug.php` (4), `test-translation-completeness.php` (2), `test-stage33-bilingual.php` (2), `test-stage41-rest-language.php` (2), `test-stage45-pages.php` (2) | the standing English-coverage debt of the site's content (documented in earlier stage reports); this task changed no translation, slug or REST surface |
| `theme/…/test-event-filter-cache.php` (5) | event-filter transient/cache fixture, unrelated to the welcome |
| `tests/scripts/verify-i18n-freshness.py`, `verify-stage8-release-consistency.py` | the standing `.pot`-freshness / release-version drift documented by earlier stages |
| `tests/acceptance/verify-commemorative-http.py` — stale `bauble_ornament` landing row | recorded as a pre-existing stale matrix row by the previous welcome report (§12.2); the matrix belongs to an earlier stage and is not rewritten here |
| `tests/acceptance/verify-event-occurrence-http.py` — Laois archive fixture rows | local content-fixture condition |
| `tests/acceptance/verify-release-http.py`, `verify-routing-http.py` — `/empregos/` 302 + hreflang rows | pre-existing local routing condition on `/empregos/`, unrelated to Christmas |

Two baseline rows were **mixed-state** (the baseline run overlapped this
change landing on disk): `test-commemorative-christmas-welcome.php` (4) and
`test-commemorative-christmas-journey.php` (1) — both are green when run
directly after the change (**132/0** and **88/0**) and their “failures” were the
old assertions meeting the new template.

## 13. Limitations

- **No WebKit / iOS Safari**: only Chromium (151.0.7922.34) was available;
  cross-engine verification is **not claimed**.
- **No physical devices**: viewports are emulated (the eight required profiles).
- **The public auto-open path was measured on the generated harness**, not on
  the live homepage, because the Christmas window is 10–25 December and the
  task forbade changing event dates or writing database state. The harness uses
  the exact signed-preview markup with only the preview flag removed — the same
  approach the previous stage documented — plus the new controller-owned
  attributes restored to server truth.
- **No production access**: nothing was verified against conexaobr.ie; all
  results are local.
- The unsupported-engine (`showModal` removed) path is runtime-proven in Node,
  not in Chromium (§2).

## 14. Evidence paths

[`docs/evidence/2026-11-06-christmas-homepage-popup-fix/`](../evidence/2026-11-06-christmas-homepage-popup-fix/)
— its `README.md` maps every file to the claim it supports: `before-*`
(root cause in the real browser), `after-*` (fixed preview + public homepage),
`browser-verification.json` + `browser-verify.log` (501 checks),
`welcome-preview-*` / `welcome-harness-*` / `welcome-harness-no-javascript-*`
(29 screenshots incl. all required viewports), the four focused PHP suite
outputs, `node-runtime-gate.txt`, `lint.txt`, `vacuity-proof-*.txt`, and the
baseline/after full-harness logs.

## 15. Documentation updated

| Document | Change |
|---|---|
| `docs/themes/conexao-br-irlanda.md` | welcome rows (markup/controller/styles), the reservation paragraph and the display/frequency/failure table rewritten for the closed-dialog contract; new `_Last verified: 2026-11-06 by the Christmas homepage popup UI fix …` line |
| `docs/commemorative-events.md` | the “hero may be presented as a welcome” rule rewritten (closed dialog, compact fallback, zero-residue dismissal, preview trigger); new `_Last verified_` line |
| `docs/testing.md` | `commemorative_ambient_runtime` gate row updated for the new runtime cases |
| `docs/README.md` | report + evidence index row added |
| `docs/reports/README.md` | index row added; the previous welcome-modal row kept with an explicit “inline-band contract superseded” pointer (history not rewritten) |
| `docs/evidence/2026-11-06-christmas-homepage-popup-fix/README.md` | file → claim map |

No procedure moved between documents; no generated region was hand-edited
(registry `--check` = zero writes; documentation-drift gate 14/0).

## 16. Rollback / recovery

Revert the 13 modified files (template, package CSS/JS, two theme suites, two
shared test files, the gate `min_cases`, five documents) and delete the two new
paths (this report + its evidence directory). No content, option, schema,
cache or storage state is created by this change, so no data reversal is needed;
a visitor's dismissal lives in their own tab and expires with it.

## 17. Final status

**Final status: PASS WITH LIMITATION**

Every locally runnable gate passed with real numbers (427 focused PHP
assertions, 18 Node cases / gate 74, 501 browser checks, clean lint, zero
registry drift, 357 governance + 14 documentation-drift assertions, and the
full harness compared against the recorded baseline in §11); the limitations
are environmental and stated in §13 — Chromium only, no production, and the
public auto-open measured on the generated harness rather than the
out-of-window live homepage.

## 18. Git / worktree preservation statement

- Branch `commemorative-events`, HEAD `0318839c0d6c014d8fb4580875a4560f62e6e1cd`
  — **unchanged**; no commit, no push, no tag.
- All **9 pre-existing stashes** untouched; no stash created, dropped or popped.
- No `reset`, `clean`, forced checkout, or discard/restore of user files; the
  worktree changes are exactly the files listed in §3 (plus this report and the
  evidence directory).
- No production action of any kind: no production write, no database content
  write, no activation, no release, no deployment — confirmed by §4a/§8 and by
  the read-only design of every verifier used here.

_Last verified: 2026-11-06 by the Christmas homepage popup UI fix_


