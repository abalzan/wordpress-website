# Report — Christmas welcome as a top-of-screen popup (preview auto-open)

| | |
|---|---|
| **Stage / task name** | Christmas welcome top-of-screen popup (signed-preview auto-open) |
| **Date** | 2026-11-07 |
| **Author / agent** | Implementation agent (Cline) |
| **Branch** | `commemorative-events` |
| **Start SHA** | `0318839c0d6c014d8fb4580875a4560f62e6e1cd` — the session began with the previous popup-UI-fix iteration uncommitted on this SHA; the operator committed that work mid-session as `af91ed86ec03f798cec910a7268a6bea778f4f31`, which is the effective baseline for this task |
| **Final SHA** | `af91ed86ec03f798cec910a7268a6bea778f4f31` (unchanged — this session made **no commit**) |
| **Working tree at finish** | dirty with exactly the files listed in §3 (11 modified + this report + its evidence directory) |
| **Skills followed** | `wp-frontend-perf`, `wp-write-in-process-test`, `wp-update-docs`, `wp-testing` (via `./scripts/run-tests.sh`), `wp-http-acceptance-matrix` (acceptance comparison only) |
| **Authoritative docs consulted** | `AGENTS.md`, `docs/engineering-standard.md`, `docs/commemorative-events.md`, `docs/themes/conexao-br-irlanda.md`, `docs/testing.md`, `docs/templates/report.md` |

## 1. Scope completed

The existing “Um Natal, dois céus” welcome now behaves exactly as requested: on
the initial homepage view it **auto-opens as a real modal at the TOP of the
screen** (horizontally centred, pinned below every top obstruction), both on the
public homepage and in the signed private preview, with the approved artwork,
copy and CTA untouched, and closing it leaves the homepage clean.

### 1.1 Root cause of the card appearing in normal page flow

Two defects were live at the baseline (`af91ed8`), both proven in Chromium
before any edit (`before/browser-verification.json`, `before-browser-run.txt`,
500 checks, 0 failures against the OLD test file):

1. **The preview contract deliberately suppressed the auto-open.**
   `bootWelcome()` branched on `controller.isPreview()` and only called
   `setState('preview')` — never `open()`. The stylesheet kept the compact
   fallback card **visible** in state `preview` (only `pending`/`open`/
   `dismissed` hid it), so `.ce-xmas-welcome__fallback` — heading, duplicate
   CTA and the revealed “Abrir a pré-visualização do boas-vindas” button —
   rendered in **normal document flow** inside the `homepage_hero` placement,
   across the lower hero. Measured in the real signed preview before the fix:
   `state=preview`, `isModal=false`, `fallbackVisible=true`,
   `triggerVisible=true`, `closeHidden=true`.
2. **The dialog, when it did open (via the trigger), was vertically centred.**
   The card rule was `position: fixed; inset: 0; margin: auto`, which centres on
   both axes — measured `box.y = 218` on a 768 px viewport
   (`(768 − 332) / 2 = 218`, exact centre), not a top-anchored popup — and the
   short-viewport `max-height: calc(100dvh − 12px)` ignored the top frame, so
   once the card moved to the top a landscape phone overflowed the bottom edge
   (caught by the new browser check, §10).

### 1.2 Why the preview state did not automatically open the dialog

It was a **deliberate design decision in the previous iteration**, recorded in
`docs/reports/2026-11-06-christmas-homepage-welcome-modal-plan.md`:
“Auto-open in private preview too → the `conexao-preview-banner` would sit
**behind** the top layer and become invisible”. The implementation followed
that plan: preview → state `preview`, trigger card instead of a modal. This
task overrules the decision while preserving its underlying concern: the card
is now **pinned below** the notice (and the admin bar and the sticky header)
via a controller-measured `--ce-xmas-welcome-obstruction-top`, and the
preview-only backdrop tint is lightened (`rgba(8,12,34,0.45)` vs the public
`0.66`, selected by a server mark `data-ce-welcome-preview="1"` on the dialog
itself) so the notice stays readable above the card. The preview close still
never reads or writes `ce_christmas_welcome_dismissed_v1`.
### 1.3 How the Halloween implementation was used as reference

`assets/js/presentations/halloween/halloween.js` → `initHalloweenWelcome()`
and `assets/css/presentations/halloween.css` were read as the behavioural and
layout reference (no Halloween file was modified — the Halloween suites still
pass and `git status` shows no Halloween path):

| Halloween behaviour | Christmas adoption |
|---|---|
| Auto-opens on the first eligible homepage view; no gesture | same — `bootWelcome()` opens on every eligible view (public and preview) |
| Dismissal is tab-`sessionStorage`, namespaced, reviewed on load; a clean session re-opens | same — `ce_christmas_welcome_dismissed_v1`, session-scoped, **own key**, never shared with Halloween |
| Card sits in the upper part of the screen (absolute inside the hero, below the sticky header), never viewport-centred | same reading — the card hangs from the top frame (`inset: var(--frame-top) 0 auto 0`), below header/notice/admin bar |
| Darkened backdrop separates the card from the page; the page behind is inert while open | native `<dialog>` `::backdrop` + `showModal()` inertness (Christmas keeps its own established native-dialog pattern from the welcome-modal iteration — no second modal framework was invented) |
| Close restores the page exactly; focus returns to where it came from | same — scroll lock/restore, focus restore, zero residue |
| No-JS: the presentation is revealed inline by `<noscript>` | Christmas keeps its own compact fallback card for no-JS / no-`showModal()` / failed-open only (state `fallback`), never while the modal enhancement is operating |

### 1.4 Delivered behaviour (mapped to the request)

1. **Auto-open on the initial homepage view** — public and signed preview, PT
   and EN, via `showModal()` (top layer, `:modal`, backdrop). The preview
   requires no click; the trigger button stays `hidden` for ever.
2. **Top-of-screen placement** — horizontally centred, pinned below
   `max(16px, env(safe-area-inset-top), obstruction)`, where the obstruction is
   the lowest bottom edge of `#wpadminbar`, `.site-header`,
   `.conexao-preview-banner` (+12 px gap), re-measured on `load`, `fonts.ready`
   and `resize` while open and cleared on settle. Real-preview measurements
   after the fix: 320×568 → y=222 (header bottom 209); 390×844 → y=196
   (header 184); 768×1024 → y=171 (header 158); 1366×768 → y=151 (header 139);
   844×390 → y=161 with the card height capped to 213 so `bottom=374 ≤ 390`;
   1024×768 → y=135. Every card is in the top half of the viewport and below
   every obstruction.
3. **Backdrop + inert background** — public `rgba(8,12,34,0.66)`, preview
   `rgba(8,12,34,0.45)`; `body` locked (`position: fixed` + negative `top`)
   and restored exactly on close.
4. **Close control** — revealed (`hidden` removed) whenever the modal opens,
   ≥44×44; Escape and backdrop click behave as before; clicks inside the card
   never close it.
5. **Clean close** — state `dismissed` (public) / `preview` (signed preview),
   dialog closed, fallback hidden, hero geometry identical across open/close/
   reload (620 px / 16 px padding on the harness), no duplicated CTA, no
   reserved space, no trigger button.
6. **CTA** — unchanged: `/natal/` (PT) and `/en/christmas/` (EN), label and
   destination untouched; navigation is never intercepted.
7. **Dismissal** — session-scoped, Christmas-only key, same frequency as
   Halloween; the preview close writes nothing, so a preview reload shows the
   popup again while a public reload in the same session stays closed.
8. **Preview notice** — kept visible and clear above the card at every profile
   (asserted: banner/header/admin-bar bottom ≤ card top); preview signature,
   nonce, expiry and capability checks untouched (zero plugin files changed).

## 2. Scope NOT completed

| Not done | Why |
|---|---|
| Commit / push / tag / release / deploy / activation | explicitly out of scope; nothing was committed by this session |
| WebKit / Safari and physical-device testing | Playwright WebKit is not installed in this environment (verified: `BrowserType.launch: Executable doesn't exist … webkit-2336`); Chromium 151 is the only engine that ran (§13) |
| Regenerating `conexao-br-irlanda.pot` (and the plugin catalogues) | the catalogues were already stale before this task (the same failing gate at `af91ed8` and in the 2026-09-27 evidence); regeneration is unrelated cleanup, explicitly refused (§12) |
| Fixing the pre-existing full-harness failures (11 PHP suites, 2 script-contract, 4 acceptance) | pre-existing data/fixture debt recorded since 2026-09; “no unrelated baseline repairs” |
| Verifying the PUBLIC homepage with the event active | the Christmas window is 10–25 December; today is 7 November. The public auto-open was measured on the generated harness built from the real server markup; the real markup itself was measured through the signed preview (§13) |

## 3. Files added / modified / deleted

| Path | Change | Note |
|---|---|---|
| `…/assets/js/presentations/christmas/christmas.js` | modified | preview auto-open in `bootWelcome()`; `applyObstructionOffset()` / `clearObstructionOffset()` / `reapplyObstructionOffset()` (measured on open, re-measured on `load` + `fonts.ready` + `resize`, cleared on settle); the preview trigger is wired but never revealed; docblocks |
| `…/assets/css/presentations/christmas.css` | modified | top-pinning (`inset: var(--frame-top) 0 auto 0`, `margin-inline: auto`), `--ce-xmas-welcome-obstruction-top` folded into the frame `max()`, frame-aware `max-height` on the base rule and the `max-height: 560px` media block, state `preview` hides the fallback, preview-only lighter `::backdrop`, comments |
| `…/template-parts/commemorative/christmas/hero.php` | modified | the server marks the **dialog** with `data-ce-welcome-preview="1"` in a signed preview (for the lighter backdrop); contract comments updated. **No content, artwork, alt text, CTA or ID changed** |
| `…/tests/test-commemorative-christmas-welcome.php` | modified | preview contract assertions rewritten; +13 assertions: top-pinning CSS/JS, obstruction selectors, preview-state-hides-fallback, no-trigger-reveal, dialog preview mark, lighter backdrop (132 → 145) |
| `tests/node/christmas-welcome.test.js` | modified | preview test rewritten to the auto-open contract (incl. reload-after-preview and preview-after-public-dismissal); new obstruction-offset case; fake DOM gained `style.setProperty`, view listeners and obstruction selectors (18 → 19 cases) |
| `tests/scripts/verify-commemorative-runtime.py` | modified | `min_cases` for the welcome suite 18 → 19 |
| `tests/browser/verify-christmas-welcome-browser.py` | modified | preview phase asserts auto-open + obstruction clearance + backdrop split + clean close + reload-opens; top-frame/not-centred checks in every profile; markup captured while closed; default `--out` moved to this task’s evidence directory |
| `docs/themes/conexao-br-irlanda.md` | modified | welcome rows (markup/controller/styles), preview row and the behaviour table rewritten; new `_Last verified: 2026-11-07 …` line |
| `docs/commemorative-events.md` | modified | the “hero may be presented as a welcome” rule rewritten (top-pinned card, preview auto-open); new `_Last verified_` line |
| `docs/testing.md` | modified | `commemorative_ambient_runtime` gate row updated (preview auto-open, obstruction offset, 19 cases); new `_Last verified_` line |
| `docs/reports/2026-11-07-christmas-welcome-top-popup.md` | added | this report |
| `docs/evidence/2026-11-07-christmas-welcome-top-popup/` | added | machine evidence (§14) |
| `docs/evidence/2026-09-26-stage-l/gate.json` | regenerated | the designed output of the documented `python3 scripts/verify-permanent-gates.py` run (docs/testing.md: “writes gate.json”); only the timestamp, repository SHA and live counts changed — no gate was edited or weakened |

All `…/` paths above start with `wp-content/themes/conexao-br-irlanda/`.
Counts: **0 deleted, 11 modified, 2 added** (report + evidence directory).
Every `wp-content/` change is theme presentation-layer code/tests for the
Christmas package only: no plugin, no route, no option, no metadata, no
scheduler, no content file.

## 4. Runtime impact

Yes — WordPress behaves differently in exactly one place: the Christmas
`homepage_hero` welcome. The dialog now auto-opens on the initial homepage view
(public **and** signed preview) as a top-pinned modal, the signed preview uses
a lighter backdrop and never shows the fallback card, and every other state is
unchanged from the previous iteration (compact fallback for no-JS/failure,
zero residue after dismissal). Routes, queries, the landing page, the sponsor
carousel, the header and every other package are untouched (dispatch 120/0,
landing 87/0, journey 88/0, Halloween suites green).

## 4a. Scope integrity

| Check | Result |
|---|---|
| No production write, deploy, upload or activation | **yes** — none performed; every verifier used is read-only and refuses production hosts |
| No production content, Polylang or database change | **yes** — the welcome suite still asserts the event record’s meta is byte-identical after rendering |
| No Flutter/mobile repository accessed | **yes** |
| No `.env` or secret change | **yes** — credentials came from the existing `WP_USERNAME` / `WP_TEST_USER_PASSWORD` environment defaults |
| Preview security untouched | **yes** — `conexao-commemorative` has zero changed files; the suite still asserts the signed preview authorises exactly as before and writes nothing |

## 5. Content / data impact

**None.** No record was created, updated or deleted. The approved artwork
(two-skies WebP derivatives, srcset, alt text), the star SVG, the kicker,
heading, message, CTA label and CTA destination are byte-identical: `git diff`
on `hero.php` contains only comments and the dialog’s preview attribute, and
the journey/welcome suites re-assert the copy, the single image, the single
dialog and both CTA hrefs (88/0 and 145/0). The browser run re-measured the
image transfer as unchanged (96,892 B at DPR1, 168,988 B at DPR2, exactly one
derivative per context).

## 6. Polylang impact

None: 0 PT records touched, 0 EN records created, no locale logic changed
(`conexao_current_locale()` flow untouched). The EN rendering assertions in the
welcome suite pass (English heading/copy/CTA/alt, no Portuguese leakage) and
the English signed preview auto-opens with `/en/christmas/` as the CTA. The
translation-completeness gate reports the same pre-existing violations as
2026-11-06 (§12); the allowlist is unchanged in kind.

## 7. Route / HTTP impact

None: no route, redirect, canonical, hreflang or sitemap line changed. The
HTTP acceptance layer produced the same result as the reference run: 5 suites,
1 passed, 4 failed — the same four pre-existing failures, with
`verify-commemorative-http.py` failing only the stale `bauble_ornament` landing
row exactly as at `af91ed8`.

## 8. Production actions

| Action | Performed? | Detail |
|---|---|---|
| Production write | **no** | |
| Deploy / upload | **no** | |
| Plugin activation | **no** | |
| Content or DB mutation | **no** | |

Production is WordPress.com (no SSH, no WP-CLI, no filesystem, no database);
nothing in this task required a production operation, and none was attempted.

## 9. Verification commands and results

| Command | Exit | Result |
|---|---|---|
| Focused PHP suites, **before** any edit (baseline) | 0 | welcome **132/0**, journey **88/0**, dispatch **120/0**, landing **87/0** (`baseline-php-*.txt`) |
| `node --test tests/node/christmas-welcome.test.js` (baseline) | 0 | **18/18** (`baseline-node-welcome.txt`) |
| browser verifier (before, old code + old tests) | 0 | **500 checks passed, 0 failed** (`before/`, `before-browser-run.txt`) |
| Focused PHP suites, after | 0 | welcome **145/0**, journey **88/0**, dispatch **120/0**, landing **87/0** (`after-php-*.txt`) |
| `node --test tests/node/christmas-welcome.test.js` | 0 | **19/19** |
| `python3 tests/scripts/verify-commemorative-runtime.py` | 0 | gate **`commemorative_ambient_runtime` pass — 76 passed / 0 failed** (11 + 8 + 19 cases) (`after-node-runtime-gate.txt`) |
| `./scripts/lint.sh` | 0 | PHP syntax clean (584 files), PHPCS no new violations vs baseline, PHPStan level 5 clean (`after-lint.txt`) |
| `./scripts/run-tests.sh` (full harness, after) | 1 | **all failures pre-existing** — see §11 (`after-run-tests.txt`) |
| `python3 scripts/verify-permanent-gates.py` | 1 | 13/15 gates pass; the 2 failing gates carry violations **byte-identical to the 2026-11-06 record** — see §12 (`after-permanent-gates.txt`) |
| browser verifier (after) | 0 | **546 checks passed, 0 failed** (`after/`, `after-browser-run.txt`) |
| same verifier, first attempt after the code change | 1 | **545 passed, 1 failed** — the new landscape check caught a real overflow (bottom 461 > 390); fixed by the frame-aware `max-height`, kept as `after-browser-run-attempt1-landscape-overflow.txt` |
| Vacuity: new tests × old (`af91ed8`) code | 1 / 1 / 1 | node **2 failed** of 19; PHP welcome suite **12 failed** (133 passed); browser **134 failed** of 546 — see §10 |

### Numeric test results

In-process PHP (focused): **4 suites, 4 passed, 0 failed**; **440 assertions
passed, 0 failed** (145 + 88 + 120 + 87; baseline was 427).
Full harness in-process PHP: **137 suites, 126 passed, 11 failed**;
**10 077 assertions passed, 38 failed** (reference run at `af91ed8`:
126/11 and 10 064/38 — the 13 additional passing assertions are this task’s
new welcome assertions; the 38 failures are the same pre-existing set).

### HTTP acceptance

**5 suites, 1 passed, 4 failed** — identical to the reference run: the same
four pre-existing failures (`commemorative-http` = only the stale
`bauble_ornament` row, `event-occurrence-http`, `release-http`,
`routing-http`); `guides-en-http` 20/0.

### Static analysis

PHPCS (WordPress-Extra + Docs + PHPCompatibilityWP) against the legacy
baseline: **no new violations**; PHPStan level 5: **no errors**; `php -l` on
584 files: **no parse errors**.

### Script-contract results

**21 suites, 19 passed, 2 failed** — the same two pre-existing failures as the
reference (`verify-i18n-freshness.py`, `verify-stage8-release-consistency.py`).
Inside that layer, `verify-agent-governance.py`,
`verify-documentation-drift.py` and `verify-commemorative-runtime.py` all
**PASS**.

### Release / build results

not in scope (no release or deploy was authorised).

## 10. Failure proofs / negative tests

A gate that has never been shown to fail is not a gate: each layer of the new
test suite was run against the **old (`af91ed8`) implementation** with the new
tests, and failed. The theme files were restored afterwards and verified by
`sha256sum -c` (all OK) before any further run.

| Proof | Result |
|---|---|
| New Node suite × old `christmas.js` (isolated copy in `/tmp`) | **exit 1 — exactly the 2 new/rewritten cases fail**: “the signed preview auto-opens …” (`0 !== 1`) and “the open card is pinned below the admin bar …”; the other 17 pass (`vacuity-node-new-tests-old-code.txt`) |
| New PHP welcome suite × old `hero.php` + `christmas.js` + `christmas.css` (swapped in place, then restored) | **exit 1 — 12 failed, 133 passed** (`vacuity-php-new-suite-old-code.txt`); restore verified `sha256sum -c` OK |
| New browser verifier × old theme files (swap → run → trap-restore) | **exit 1 — 134 failed, 412 passed**: 118 in the preview phase (auto-open, top-frame, obstruction clearance, clean close), 8 harness, 5 harness(preview), 3 en-preview (`vacuity-browser-run.txt`, `vacuity-browser/`); restore verified in the same log |
| Landscape overflow caught during development | first after-run: **1 failure** — `the dialog is fully inside the viewport vertically (161..461 of 390)`; the `max-height: 560px` media block ignored the top frame. Fixed, re-run green (`after-browser-run-attempt1-landscape-overflow.txt` vs `after-browser-run.txt`) |

## 11. Regression comparison

Before (the `af91ed8` reference full run,
`docs/evidence/2026-11-06-christmas-homepage-popup-fix/after-run-tests.txt`)
vs after (this task’s `./scripts/run-tests.sh`):

| | Reference (`af91ed8`) | After |
|---|---|---|
| In-process PHP suites failing | 11 | **11 — the same 11** |
| Assertions failing | 38 | **38** |
| Failing script-contract suites | 2 (`verify-i18n-freshness.py`, `verify-stage8-release-consistency.py`) | **2 — the same two** |
| Failing HTTP acceptance suites | 4 (`commemorative-http`, `event-occurrence-http`, `release-http`, `routing-http`) | **4 — the same four** |
| Permanent-gate violations (vs the 2026-11-06 record) | 3 + i18n failure | **byte-identical violations** (§12) |

**Diff of the failing-suite lists: mine-only = ∅, reference-only = ∅, common =
11.** No baseline file was edited to make anything pass; the four focused
Christmas suites pass both standalone and inside the full harness.

## 12. Known pre-existing failures

| Failure | What it is | Proof it is not this task’s |
|---|---|---|
| 11 PHP suites (`town-sanitization`, 10 translation/`empregos` suites) | local content-data / fixture debt | identical list in the `af91ed8` reference run and in the 2026-09-27/09-30 evidence |
| `verify-i18n-freshness.py` | `.pot` catalogues older than their sources | fails identically at `af91ed8` (same three catalogues); this task touched no plugin and regenerated nothing — the theme line merely names `hero.php` as the newest theme source now |
| `verify-stage8-release-consistency.py` | `dist/release.json` version drift | identical at `af91ed8` |
| 4 HTTP acceptance suites | stale `bauble_ornament` landing row, `/empregos/` 302s, Laois archive fixtures | identical row-level failures at `af91ed8` and in the 2026-11-06 record |
| `translation_completeness` gate: `guide:missing_en: 1`, `page:malformed_relationships: 2` | content-data debt | **byte-identical** in `docs/evidence/2026-11-06-commemorative-events/permanent-gates.txt` (2026-11-06, sha `50ae318`) and 2026-09-27 evidence — recorded before this session; `git status` shows zero content/plugin changes |
| `i18n_freshness` gate “1 new” (`conexao-commemorative` stale catalogue) | plugin `.pot` older than its admin class by 6083 s | the same delta appears in the `af91ed8` reference run; no plugin file was touched (`git status` → 0 plugin paths) |

## 13. Limitations

- **No WebKit / Safari run.** Playwright WebKit is not installed in this
  environment (verified: `Executable doesn't exist at
  /home/andrei/.cache/ms-playwright/webkit-2336/pw_run.sh`), and no physical
  device was available. Every browser claim in this report is **Chromium 151**
  (`browser-verification.json` → `browser`). The `<dialog>` top layer, `dvh`
  sizing, `env(safe-area-inset-*)` and the `position: fixed` scroll lock are
  designed defensively (vh fallback, frame-aware max-height, explicit
  `scrollTo` restore) but **not verified on WebKit/iOS Safari**.
- **No production verification.** Nothing was checked against conexaobr.ie;
  all results are local (Docker WordPress at `localhost:8080`).
- **Public homepage measured on the generated harness.** The Christmas window
  is 10–25 December and today is 7 November, so a public visit renders no
  welcome at all; the public auto-open/frequency/residue behaviour is measured
  on `welcome-harness-public.html`, built from the **real server markup**
  captured through the signed preview (only the preview attribute is removed).
  The real markup itself, with the notice/admin bar/header, is measured in
  Phase C on the signed preview.
- The full harness exits 1 because of the pre-existing failures listed in §12;
  this is the documented state of the repository at `af91ed8` as well.

## 14. Evidence paths

[`docs/evidence/2026-11-07-christmas-welcome-top-popup/`](../evidence/2026-11-07-christmas-welcome-top-popup/)
— its `README.md` maps every file to the claim it supports. Highlights:

| Evidence | Claim |
|---|---|
| `before/browser-verification.json`, `before-browser-run.txt` | root cause on the real preview before the fix: `state=preview`, not modal, fallback + trigger visible; dialog vertically centred (`y=218` of 768); 500 checks |
| `before/preview-en-closed-390x844.png` | the supplied-screenshot state: closed preview with the in-flow card and the “Abrir a pré-visualização do boas-vindas” button (EN) |
| `docs/evidence/2026-11-06-christmas-homepage-popup-fix/after-preview-closed-{desktop-1366x768,mobile-390x844}.png` | the same broken PT state at desktop and mobile (committed predecessor evidence) |
| `after/welcome-preview-desktop-1366x768.png`, `after/welcome-preview-iphone-modern-390x844.png`, `after/welcome-preview-iphone-small-320x568.png`, `after/welcome-preview-tablet-768x1024.png`, `after/welcome-preview-landscape-844x390.png` | the fixed signed preview: modal auto-open, top-pinned below notice/header/admin bar, all eight profiles |
| `after/preview-closed-390x844.png` | closing the preview leaves a clean homepage (no card, no trigger) |
| `after/preview-en-modal-390x844.png` | English signed preview auto-opens with English copy and `/en/christmas/` CTA |
| `after/welcome-harness-*.png` (8 profiles) + `welcome-harness-no-javascript-390x844.png` | public harness auto-open at every required viewport; no-JS fallback card |
| `after/browser-verification.json`, `after-browser-run.txt` | **546 checks, 0 failures** |
| `after-browser-run-attempt1-landscape-overflow.txt` | the caught-and-fixed landscape overflow (545/1) |
| `vacuity-*` files + `vacuity-browser/` | new tests fail on old code: node 2, PHP 12, browser 134 |
| `baseline-*`, `after-php-*`, `after-node-runtime-gate.txt`, `after-lint.txt`, `after-run-tests.txt`, `after-permanent-gates.txt` | the exact numbers in §9–§12 |

## 15. Documentation updated

| Document | Change |
|---|---|
| `docs/themes/conexao-br-irlanda.md` | the welcome markup/controller/styles rows, the preview row and the display/frequency/failure table rewritten for the top-pinned, preview-auto-open contract; new `_Last verified: 2026-11-07 …` line |
| `docs/commemorative-events.md` | the “hero may be presented as a welcome” rule rewritten (top-pinned card, preview auto-open, notice never covered); new `_Last verified_` line |
| `docs/testing.md` | `commemorative_ambient_runtime` gate row updated (preview auto-open, obstruction offset, 19 cases); new `_Last verified_` line |
| `docs/README.md`, `docs/reports/README.md` | report + evidence index rows added (with `_Last verified_` lines) |
| `docs/evidence/2026-11-07-christmas-welcome-top-popup/README.md` | file → claim map |

No procedure moved between documents; no generated region was hand-edited
(registry `--check` = zero writes; documentation-drift gate 14/0 both inside
the full harness and after the index rows were added).

## 16. Rollback / recovery

Revert the 11 modified files (theme template + package JS/CSS, the welcome
suite, the Node suite + gate `min_cases`, the browser verifier, three
behaviour documents, the two index files — plus the regenerated `gate.json`,
which the next `verify-permanent-gates.py` run rewrites anyway) and delete this
report plus its evidence directory. No content,
option, schema, cache or storage state is created by this change: a visitor’s
dismissal lives in their own tab and expires with it, and the preview writes
nothing anywhere.

## 17. Final status

| Status | Use when |
|---|---|
| `PASS WITH LIMITATION` | the work is complete, but a required verification capability was unavailable |

**Final status: PASS WITH LIMITATION**

Every locally runnable gate passed with real numbers (440 focused PHP
assertions, 19 Node cases / gate 76, 546 browser checks with 0 failures, clean
lint, empty failing-suite diff against the reference run, documentation-drift
and governance gates green); the limitations are environmental and stated in
§13 — Chromium only (no WebKit/Safari, no physical device), no production,
and the public auto-open measured on the generated harness because the event
window is inactive today.

## 18. Git / worktree preservation statement

- Session start: branch `commemorative-events`, HEAD
  `0318839c0d6c014d8fb4580875a4560f62e6e1cd` with the previous iteration’s
  changes uncommitted; **mid-session the operator committed that work as
  `af91ed86ec03f798cec910a7268a6bea778f4f31`**, which became this task’s
  effective baseline. Final HEAD: **`af91ed86…` — unchanged; this session made
  no commit, no push, no tag.**
- All **9 pre-existing stashes untouched**; no stash created, dropped or popped.
- No `reset`, `clean`, forced checkout, or discard/restore of user files. The
  temporary old-code swaps used for the §10 vacuity proofs were restored and
  verified by `sha256sum -c` (all OK) before the final runs.
- The worktree at finish is dirty with exactly the files listed in §3 (plus
  this report and the evidence directory).
- No production action of any kind: no production write, no database content
  write, no activation, no release, no deployment — confirmed by §4a/§8 and by
  the read-only design of every verifier used here.

_Last verified: 2026-11-07 by the Christmas welcome top-of-screen popup fix_



