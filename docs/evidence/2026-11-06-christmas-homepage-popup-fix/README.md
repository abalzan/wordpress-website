# Evidence — Christmas homepage popup UI fix (2026-11-06)

Machine evidence for [`docs/reports/2026-11-06-christmas-homepage-popup-ui-fix.md`](../../reports/2026-11-06-christmas-homepage-popup-ui-fix.md).
Everything here was produced read-only against the local stack; nothing was
written to WordPress or to any production surface.

## Before (root cause, real browser)

| File | Claim it supports |
|---|---|
| `before-browser-state.json` | The signed preview in Chromium BEFORE the fix: `state="inline"`, `dialogOpen=true`, `isModal=false`, close button hidden, hero `paddingBottom=286.86px` (hero 850 px vs 563 px without the event), placement `position:absolute; inset:0; z-index:20`, banner visible, `sessionStorage` untouched, zero console/page errors. |
| `before-preview-desktop-1366x768.png` | The broken state at desktop: the wide Christmas card sitting on the bottom edge of the inflated hero. |
| `before-preview-viewport-1366x768.png` | Same state at scroll 0 (viewport framing). |
| `before-preview-mobile-390x844.png` | The same non-modal inline card at 390 × 844. |

## After (the fixed behaviour)

| File | Claim it supports |
|---|---|
| `browser-verification.json` | The full structured report of `tests/browser/verify-christmas-welcome-browser.py` (phases A–D, per-profile metrics, EN preview, error lists). |
| `browser-verify.log` | The complete stdout of that run: **501 checks, 0 failed, exit 0** — public routes, landing routes, signed preview (closed dialog + trigger card), the PT and EN preview contracts, the generated harness (auto-open at eight viewports, dismissal residue, hero geometry, scroll lock/restore, storage denial, reduced motion, light/dark, CTA navigation, image transfer) and the no-JavaScript fallback. |
| `welcome-preview-*.png` (8) | The real signed-preview modal at 320×568, 375×667, 390×844, 768×1024, 1024×768, 1366×768, 1440×900 and 844×390: centred, backdrop visible, close control unclipped. |
| `welcome-harness-*.png` (8) | The public auto-open path (preview flag stripped from the same real markup) at the same eight viewports. |
| `welcome-harness-no-javascript-390x844.png` | JavaScript disabled: the compact fallback card only; dialog closed; no dead close or preview-trigger button. |
| `preview-en-closed-390x844.png` | The English signed preview with the dialog closed and the fallback trigger card visible. |
| `after-browser-state.json` | The signed preview AFTER the fix (1366×768): `state="preview"`, dialog `display:none` (closed), fallback + preview trigger visible, placement `position: static`, hero `701 px / padding-bottom: 0` (was 850 / 286.86), banner visible, `sessionStorage` null; modal opened via the trigger (`:modal` true, 960 × 332 centred, backdrop `rgba(8, 12, 34, 0.66)`), Escape returns to state `preview` with the key still null; public homepage: 0 welcome roots, 0 dialogs; zero console/page errors. |
| `after-preview-closed-desktop-1366x768.png`, `after-preview-closed-mobile-390x844.png` | The fixed signed preview with the dialog closed: compact trigger card inside the hero's normal flow, no inflated hero, no inline dialog. |
| `after-preview-modal-desktop-1366x768.png` | The same preview opened through the preview trigger: centred modal over the dimmed viewport. |
| `after-public-homepage-1366x768.png` | The public homepage (no active event): completely unchanged, no welcome. |
| `public-{pt,en}{,-landing}-390x844.png` | Phase A/B: no welcome markup on the public homepage (out of window) and on `/natal/` / `/en/christmas/`. |
| `welcome-harness-public.html`, `welcome-harness-preview.html` | The generated harness pages (real captured markup; preview flag stripped in the public copy; controller-owned `data-ce-welcome-claimed` removed and the preview trigger re-`hidden` to restore server truth). |

## Test / gate outputs

| File | Claim it supports |
|---|---|
| `php-test-commemorative-christmas-welcome.txt` | The in-process suite: **132 passed, 0 failed**. |
| `php-test-commemorative-christmas-journey.txt` | **88 passed, 0 failed** (the one-CTA rule restated for the two-state CTA). |
| `php-test-commemorative-presentation-dispatch.txt` | **120 passed, 0 failed** (placement/package dispatch untouched). |
| `php-test-commemorative-landing-page.txt` | **87 passed, 0 failed** (landing routes never carry the welcome). |
| `node-runtime-gate.txt` | `commemorative_ambient_runtime`: **74 passed, 0 failed** (18 welcome cases). |
| `lint.txt` | `./scripts/lint.sh`: syntax clean, no new PHPCS violations, PHPStan clean, exit 0. |
| `vacuity-proof-reintroduced-open-and-reservation.txt` | Negative proof: re-adding the server-rendered `open` attribute and the 286 px hero reservation makes the suite fail exactly the three assertions that guard them (**129 passed, 3 failed**); the files were restored byte-identically afterwards (md5 checked). |
| `baseline-run-tests.txt` | The full-harness baseline attempt started on the clean tree. **Caveat:** its later suites executed while the fix was already landing on disk, so its welcome/journey rows are mixed-state; the honest pre-existing failing list is the one recorded in the report §12 (identical in the post-change full run). |
| `after-run-tests.txt` | The full harness AFTER the change: `./scripts/run-tests.sh`, one aggregate exit code, failing-suite list compared with the baseline. |

## Reproduction

```bash
python3 tests/browser/verify-christmas-welcome-browser.py \
  --out docs/evidence/2026-11-06-christmas-homepage-popup-fix
```

The script is read-only, local-only (refuses production hosts) and requires the
local stack plus the local test administrator (`WP_USERNAME` / `WP_TEST_USER_PASSWORD`).
