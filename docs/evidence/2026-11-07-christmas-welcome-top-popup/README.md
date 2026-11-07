# Evidence — Christmas welcome top-of-screen popup (2026-11-07)

Machine evidence for [`docs/reports/2026-11-07-christmas-welcome-top-popup.md`](../../reports/2026-11-07-christmas-welcome-top-popup.md).
All runs are local, read-only, against the Docker site at `localhost:8080`
(Chromium 151 via Playwright; PHP suites inside the WordPress container).

## Baseline (before any edit this session)

| File | Claim |
|---|---|
| `baseline-php-welcome.txt` / `baseline-php-journey.txt` / `baseline-php-dispatch.txt` / `baseline-php-landing.txt` | pre-edit focused suites: 132/0, 88/0, 120/0, 87/0 |
| `baseline-node-welcome.txt` | pre-edit Node suite: 18/18 |

## Before — the broken behaviour on the real signed preview (old code + old tests)

| File | Claim |
|---|---|
| `before/browser-verification.json` | 500 checks, 0 failures; `phases.preview.initial_state` = `state=preview`, `isModal=false`, `fallbackVisible=true`, `triggerVisible=true`, `closeHidden=true` — the in-flow card with the preview button; opened dialog `box.y=218` on vh=768 = vertically centred; `after_reload.isModal=false` (no auto-open) |
| `before-browser-run.txt` | the same run as text (PASS: 500, FAIL: 0) |
| `before/preview-en-closed-390x844.png` | **the supplied-screenshot state**: EN signed preview closed, fallback card + “Open the welcome preview” button in the hero flow |
| `before/welcome-preview-desktop-1366x768.png`, `before/welcome-preview-iphone-modern-390x844.png` | the dialog opened via the trigger — vertically centred, not top-anchored |
| `before/welcome-harness-*.png`, `before/welcome-harness-no-javascript-390x844.png` | public harness before (centred modal; no-JS inline card) |
| `before/welcome-harness-{public,preview}.html` | the captured harness pages used for the before measurements |

The canonical PT “card across the lower hero” screenshots from the predecessor
iteration are kept in
`docs/evidence/2026-11-06-christmas-homepage-popup-fix/after-preview-closed-desktop-1366x768.png`
and `…-mobile-390x844.png`.

## After — the fixed behaviour (new code + new tests)

| File | Claim |
|---|---|
| `after/browser-verification.json` | **546 checks, 0 failures**; preview auto-opens (`state=open`, `:modal`, close revealed, fallback/trigger hidden, 0 inline copies); banner/header/admin-bar bottoms ≤ card top at every profile; backdrop `rgba(8,12,34,0.45)` preview vs `0.66` public; Escape → `state=preview` with nothing visible; reload → auto-opens again; key never written; EN preview auto-opens with `/en/christmas/`; hero geometry 620/16 identical open/close/reload; image transfer unchanged |
| `after-browser-run.txt` | the same run as text (PASS: 546, FAIL: 0) |
| `after-browser-run-attempt1-landscape-overflow.txt` | the first after-run: 545 PASS / 1 FAIL — the new landscape check caught `bottom 461 > 390`; fixed with the frame-aware `max-height` and re-run green |
| `after/welcome-preview-desktop-1366x768.png` | real signed preview, desktop: modal auto-open, top-pinned (y=151 below header 139 + notice + admin bar) |
| `after/welcome-preview-iphone-modern-390x844.png` | real signed preview, mobile: y=196 below header 184 |
| `after/welcome-preview-iphone-small-320x568.png` | 320 px: y=222 below header 209, card bottom 552 ≤ 568 |
| `after/welcome-preview-tablet-768x1024.png`, `after/welcome-preview-laptop-1024x768.png`, `after/welcome-preview-wide-1440x900.png`, `after/welcome-preview-landscape-844x390.png`, `after/welcome-preview-iphone-se-375x667.png` | the remaining required profiles with the modal open on real markup |
| `after/preview-closed-390x844.png` | after Escape in the preview: clean homepage, no card, no trigger, notice still visible |
| `after/preview-en-modal-390x844.png` | English signed preview auto-open |
| `after/welcome-harness-*.png` (8 profiles) | public harness auto-open at every required viewport (320×568 → 1440×900 + 844×390 landscape), top-pinned, horizontally centred |
| `after/welcome-harness-no-javascript-390x844.png` | no-JS: compact fallback card, no dead close/trigger buttons, no dialog |
| `after/welcome-harness-{public,preview}.html` | the harness pages generated from the captured server markup |
| `after/public-{pt,en}-390x844.png`, `after/public-{pt,en}-landing-390x844.png` | public routes with no active event: no welcome, landing pages unchanged |

## Vacuity — the new tests fail against the old (`af91ed8`) code

| File | Claim |
|---|---|
| `vacuity-node-new-tests-old-code.txt` | new Node suite × old `christmas.js`: exit 1, exactly 2 failures (preview auto-open `0 !== 1`, obstruction pinning), 17 pass |
| `vacuity-php-new-suite-old-code.txt` | new PHP welcome suite × old template/JS/CSS: exit 1, 12 failed / 133 passed |
| `vacuity-browser-run.txt` | new browser verifier × old theme files: exit 1, 134 failed / 412 passed (118 preview, 8 harness, 5 harness(preview), 3 en-preview); the log ends with the `sha256sum -c` restore verification |
| `vacuity-browser/` | the JSON + screenshots produced by that failing run |

## Test-run outputs

| File | Claim |
|---|---|
| `after-php-test-commemorative-christmas-welcome.txt` | 145/0 (was 132) |
| `after-php-test-commemorative-christmas-journey.txt` | 88/0 |
| `after-php-test-commemorative-presentation-dispatch.txt` | 120/0 |
| `after-php-test-commemorative-landing-page.txt` | 87/0 |
| `after-node-runtime-gate.txt` | `commemorative_ambient_runtime` gate pass, 76/0 (11+8+19 cases) |
| `after-lint.txt` | PHP syntax + PHPCS baseline + PHPStan: OK |
| `after-run-tests.txt` | full `./scripts/run-tests.sh`: 137 PHP suites 126/11, assertions 10 077/38, script-contract 19/2, acceptance 1/4 — failing-suite list identical to the `af91ed8` reference (diff empty) |
| `after-permanent-gates.txt` | Stage L: 13/15 gates pass; the 2 failing gates’ violations are byte-identical to `docs/evidence/2026-11-06-commemorative-events/permanent-gates.txt` |

_Last verified: 2026-11-07 by the Christmas welcome top-of-screen popup fix_
