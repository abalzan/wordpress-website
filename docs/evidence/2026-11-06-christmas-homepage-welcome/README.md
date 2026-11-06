# Evidence — Christmas homepage welcome modal (2026-11-06)

The machine-readable half of
[`docs/reports/2026-11-06-christmas-homepage-welcome-modal.md`](../../reports/2026-11-06-christmas-homepage-welcome-modal.md).
Everything here was produced locally by read-only verifiers against
`http://localhost:8080`; nothing was written to WordPress and no production host was
contacted.

| File | What it proves |
|---|---|
| `browser-verification.json` | The full browser result: public-route absence, landing-page absence, the signed-preview behaviour, the eight-profile modal matrix, the auto-open/dismissal/fresh-session/storage-denied/reduced-motion/dark-mode checks, the CTA navigation, the image derivative actually transferred at DPR 1 and 2, and the measured LCP for each route. |
| `welcome-harness-public.html` / `welcome-harness-preview.html` | The generated harnesses: the placement markup **verbatim** from the signed preview, with and without the private-preview attribute. Nothing is served from WordPress by them. |
| `welcome-preview-<profile>.png` | The modal on **real server-rendered markup**, driven through `ConexaoChristmas.openWelcome()` behind the signed preview (320×568, 375×667, 390×844, 360×800, 430×932, 768×1024, 1366×768, 844×390). |
| `welcome-harness-<profile>.png` | The auto-open path (no preview flag) at the same eight profiles. |
| `public-{pt,en,pt-landing,en-landing}-390x844.png` | The routes that must **not** show the welcome. |
| `php-theme-suites.txt` | The whole in-process theme layer: 66 suites, 3,909 assertions. |
| `node-runtime-gate.txt` | `commemorative_ambient_runtime` with the new `christmas-welcome.test.js` (66 passed, 0 failed). |
| `lint.txt` | PHP syntax + PHPCS + PHPStan. |
| `script-contract.txt` | The 21 script-contract suites. |
| `http-acceptance.txt` | The five HTTP acceptance suites. |
| `permanent-gates.txt` / `permanent-gates.json` | The fifteen permanent invariant gates (`--out` used so the tracked `gate.json` was not overwritten). |
| `http-asset-and-route-matrix.txt` | Raw HTTP presence/absence of the welcome markup and the Christmas assets, per route and per language. |
| `git-state-start.txt` / `git-state-end.txt` | Branch, HEAD, stash count and worktree at both ends of the task. |

Pre-existing failures are named and separated in the report (§11–§12); they are not
this task's regressions.
