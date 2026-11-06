# Plan — Christmas homepage welcome modal (“One Christmas, Two Skies”)

| | |
|---|---|
| **Task title** | Turn the existing Christmas `homepage_hero` placement into an accessible, automatically opening welcome modal |
| **Date** | 2026-11-06 |
| **Author / agent** | Implementation agent (Cline) |
| **Branch** | `commemorative-events` |
| **Repository baseline (starting SHA)** | `448cd9dd8458c02430e11238a9fd9a5ab98ed70e` |
| **Applicable skill(s)** | `wp-add-content-type` (presentation change inside an existing content type), `wp-frontend-perf`, `wp-write-in-process-test`, `wp-http-acceptance-matrix`, `wp-security-review`, `wp-update-docs` |
| **Plan status** | approved (owner request: “I want this to be a popup … as soon as he opens the page”) |

## 1. Scope

The Christmas package already renders a `homepage_hero` placement on the front page
(`template-parts/commemorative/homepage-hero.php` → `christmas/hero.php`). This task
**re-frames that exact placement as a welcome dialog**:

1. The hero markup is rendered **once**, inside a native `<dialog>`, which ships
   `open` so the band is a normal, fully usable inline hero without JavaScript.
2. `assets/js/presentations/christmas/christmas.js` upgrades it: a new
   `createWelcome()` controller calls `showModal()`, locks background scrolling,
   restores focus on close, and records a **session-scoped** dismissal.
3. `assets/css/presentations/christmas.css` gains one new section that styles the
   dialog at every viewport (stacked on phones, two columns on desktop, internal
   scrolling at short heights, `dvh` + safe-area insets, light/dark, reduced motion).
4. Tests: one in-process PHP markup contract suite, one Node runtime suite
   (registered in the existing runtime gate), one opt-in real-browser verifier with
   the full mobile viewport matrix; documentation and a dated report with evidence.

## 2. Explicit non-goals

- **No new event, package, placement, CPT, meta key, scheduler, resolver, endpoint,
  query parameter, rewrite or activation path.** The package key `christmas`, the
  three approved placements and the `_ce_*` records are untouched.
- **No change to `conexao-commemorative`, `conexao-data-model`,
  `conexao-admin-ux`**, to Polylang ownership, annual precedence, event scheduling
  or cache invalidation.
- **No change to the `site_wide_ambient` scheduler**, its cadence or its cap. The
  welcome adds **no** timer, interval, `requestAnimationFrame`, autoplay, sound or
  confetti.
- **No global bundle change**: no new enqueue handle, no addition to
  `main.css` / `main.js` / `dark-mode.css`; the existing conditional Christmas
  asset plan already loads `christmas.css` + `christmas.js` for this placement.
- **No new route or redirect**; the CTA keeps resolving through the existing
  language-aware logic to `/natal/` or `/en/christmas/`.
- **No public preview bypass**, no preview-authentication change, no analytics,
  cookie, `localStorage`, account, option, post meta or remote storage.
- **No production action**: no deploy, upload, activation, release, commit or push.
- Deliberately refused adjacent work: restyling the community hero, the header,
  the navigation and the sponsors carousel; replacing the archival landing-page
  exception; general “dialog component” refactoring for other packages (no second
  package needs it yet).


## 3. Relevant authoritative documents

`AGENTS.md`, `docs/engineering-standard.md`, `docs/commemorative-events.md`,
`docs/commemorative-content.md`, `docs/themes/conexao-br-irlanda.md`,
`docs/testing.md`, `docs/routing.md`, and the Christmas signature-experience
report (`docs/reports/2026-11-06-commemorative-events-christmas-signature-experience.md`).
One owner per fact: the dispatcher/asset plan stays owned by
`inc/commemorative-presentations.php`; the package brief by
`docs/commemorative-content.md` §1.2; the theme-internals map by
`docs/themes/conexao-br-irlanda.md`.

## 3a. Skill workflow

| | |
|---|---|
| **Skill** | `wp-add-content-type` (presentation/markup of an existing content type) + `wp-frontend-perf` |
| **Its preconditions** | existing package registry and dispatcher are the only source of placement truth; no new registry entry |
| **Its guardrails that bind this task** | PT content immutable; no asset outside the package; fail-closed rendering; no timers |
| **Its verification commands** | `./scripts/run-tests.sh --only conexao-br-irlanda`, `./scripts/lint.sh`, the runtime gate, the browser verifier |
| **Its evidence location** | `docs/evidence/2026-11-06-christmas-homepage-welcome/` |

## 4. Current-state findings

- HEAD `448cd9d`, branch `commemorative-events`, **clean worktree**, 9 pre-existing
  stashes (untouched).
- The Christmas event record (`post 124276` PT / `124277` EN) is `fixed_annual`
  `12-25` with a 15-day lead-in, so the live window is **10–25 December**: the
  popup cannot appear today (2026-11-06) on the local site, and the homepage
  therefore renders no commemorative markup or asset at all.
- The rendered placement today (captured through the **signed private preview**,
  `?conexao_preview=christmas&_wpnonce=…&_ce_ts=…`) is exactly one
  `.ce-christmas-hero` inside `.ce-hero-shell` inside
  `.ce-placement--homepage_hero.ce-package--christmas`, containing one `<img>`
  (`two-skies-hero-1200.webp` + 840w `srcset`), one `<h2>`, one paragraph, one CTA
  and the decorative star SVG.
- `template-parts/commemorative/homepage-hero.php` prints a `<noscript>` block with
  `.ce-placement--homepage_hero [data-ce-reveal]` / `[data-ce-dismiss]` rules.
  **Finding:** those two hooks are real for **Halloween** (`halloween/hero.php`
  carries `data-ce-reveal` and a `data-ce-dismiss` close button) and **inert for
  Christmas** — the Christmas hero has never contained either attribute. The block
  is therefore a no-op for this package, not a working reveal/dismiss system. It is
  left untouched (it is the shared shell and Halloween depends on it) and the
  Christmas welcome ships its own explicit no-JS contract instead.
- There is **no** `<dialog>`/`showModal()` usage anywhere in the theme today, and no
  existing `createWelcome()`-style controller.
- `.hero-section` reserves `padding-bottom: clamp(286px, 21vw, 344px)` while the
  Christmas hero placement renders (desktop overlay band) and drops to `0` below

## 5. Proposed approach

**One node, two presentations.** The hero content is emitted once inside
`<dialog open>`. Without JavaScript (or if the package script fails) `open` makes
it a normal block in its usual place — the current band, unchanged. With
JavaScript, `showModal()` moves the same node to the top layer.

Rejected alternatives:

| Option | Why rejected |
|---|---|
| Second copy of the hero inside a modal wrapper | two independently editable copies of the headline/copy/SVG/CTA — forbidden |
| `<dialog>` without `open` + `<noscript>` reveal | invisible whenever JS is *enabled* but the asset fails — the exact failure the contract forbids |
| JS-built modal from scratch (no native dialog) | loses top-layer inertness and background focus containment that `<dialog>` gives for free |
| Closing the modal then hiding the band | leaves `.hero-section` with ~300 px of reserved, empty space (visible layout regression) |
| Auto-open in private preview too | the `conexao-preview-banner` would sit **behind** the top layer and become invisible |

**Frequency model.** `sessionStorage['ce_christmas_welcome_dismissed_v1']` is written
only when the visitor dismisses the modal. All storage access is guarded; an
unavailable/throwing `sessionStorage` falls back to a per-document in-memory flag.
A new tab session is eligible again. A visitor who dismisses keeps the **inline
band** (the pre-existing behaviour), so the page never loses the seasonal content
and the reserved hero space stays occupied.

**No flash.** A small inline guard script, emitted immediately before the dialog,
sets `data-ce-welcome="pending"` on `<html>` when — and only when — the browser
really supports `showModal()`. CSS hides the dialog in that state. The deferred
package script boots at `document.readyState === "interactive"` (before first paint
in practice), claims the node and calls `showModal()`. A `DOMContentLoaded`
fallback registered by the same guard script reveals the band inline if the package
script never claimed it. Trade-off recorded: if the guard script itself is blocked
(e.g. by a CSP), the band simply stays inline — never blank, never duplicated.

**States:** `pending` (JS present, pre-claim) → `open` (modal, top layer) →
`inline` (band in the flow). The close control is `hidden` until the controller
owns it (fail closed), and only visible while the dialog is modal.

**Preview.** The template adds `data-ce-welcome-preview="1"` when the engine reports
an authorised preview. The controller then wires everything but **does not
auto-open**, so the preview banner stays visible and the maintainer inspects the
hero inline exactly as before. Nothing about preview authentication changes. The
same controller can still be driven programmatically (`ConexaoChristmas.openWelcome()`),
which is how the real preview page is tested in a browser.

## 6. Files expected to change

| Path | Change | Why |
|---|---|---|
| `wp-content/themes/conexao-br-irlanda/template-parts/commemorative/christmas/hero.php` | rewrite the wrapper as the dialog + guard script | one source of hero content, no-JS fallback |
| `wp-content/themes/conexao-br-irlanda/assets/css/presentations/christmas.css` | new §11 “Homepage welcome dialog” | layout, viewport, safe-area, light/dark, reduced motion |
| `wp-content/themes/conexao-br-irlanda/assets/js/presentations/christmas/christmas.js` | `createWelcome()` + boot ordering | open/close, session flag, scroll lock, focus |
| `wp-content/themes/conexao-br-irlanda/tests/test-commemorative-christmas-welcome.php` | **new** in-process suite | PHP markup contract |
| `wp-content/themes/conexao-br-irlanda/tests/test-commemorative-christmas-journey.php` | two assertions replaced | the superseded “band, never a dismissible overlay” invariant |
| `tests/node/christmas-welcome.test.js` | **new** Node runtime suite | state, frequency, failure behaviour |
| `tests/scripts/verify-commemorative-runtime.py` | register the new suite | single runtime gate, no second runner |
| `tests/browser/verify-christmas-welcome-browser.py` | **new** opt-in verifier | real-browser matrix + screenshots + JSON |
| `docs/themes/conexao-br-irlanda.md` | Christmas internals table + behaviour | maintainer doc |
| `docs/commemorative-events.md` | one placement-rule line | “the hero may be presented as a welcome dialog” |
| `docs/testing.md` | the runtime gate row names both suites | keep the gate description true |
| `docs/README.md` | report index entry | index convention |
| `docs/reports/2026-11-06-christmas-homepage-welcome-modal.md` | **new** report | narrative + numbers |
| `docs/evidence/2026-11-06-christmas-homepage-welcome/` | **new** evidence | browser JSON, screenshots, lint/gate output |

## 7. Files explicitly expected NOT to change

`plugins.json` and everything under `wp-content/plugins/` (no engine change),
`inc/commemorative-presentations.php` (registry, catalogue, dispatcher and asset
plan are already correct), `template-parts/commemorative/homepage-hero.php` (shared
shell — Halloween depends on its `<noscript>` hooks), `assets/js/commemorative.js`
(shared ambient framework), `assets/css/commemorative.css`, the global bundles
(`main.js`, `main.css`, `dark-mode.css`), `front-page.php`, `header.php`,
`page-commemorative-landing.php`, `christmas/journey.php`, `christmas/landing.php`,
`christmas/ambient.php`, `christmas/star.php` and every `_ce_*` record.

  768 px. Any solution must keep that reservation truthful.


## 8. Content / data impact

- Records created / updated / deleted: **none**.
- Portable identifiers: unchanged (`_ce_event_key` = `christmas`).
- Writes content: **no**. The suite proves the `_ce_*` meta of the canonical record
  is byte-identical before and after rendering.
- PT content changed: **no**.

## 9. Route / HTTP impact

- Routes added/removed/changed: **none**. `/`, `/en/`, `/natal/`, `/en/christmas/`
  keep their current behaviour; the modal is not a route.
- Redirects: none. Sitemap: unchanged. New HTTP matrix rows: **none** — the modal is
  a browser behaviour, not an HTTP response, so it is proven by the PHP markup
  contract plus the browser verifier instead of an acceptance row.

## 10. Polylang / English impact

- Post types/taxonomies affected: **none** (no content change).
- PT immutable: **yes**.
- B1/B2: unchanged. Canonical/hreflang/language switcher: unchanged.
- Language-scoped cache keys: unchanged (the placement markup is not cached by the
  theme; the session flag is client-side only).
- Completeness gate: unchanged (no new translatable records); the new strings use
  `__()` with the theme text domain and exist in both languages in the template.

## 11. Security impact

- Capabilities/nonces: **none added**. Preview authorisation is read, never altered.
- Sanitisation/escaping: every printed value keeps `esc_html`/`esc_attr`/`esc_url`;
  the guard script is static, with no PHP interpolation.
- SQL: none. REST: none. Secrets: none.

## 12. Performance impact

- Queries: 0 added (same template, same records).
- Caching: no new transient; the session flag is `sessionStorage`, tab-scoped.
- Assets: **no new file request**. The modal image is the existing
  `two-skies-hero-1200.webp` / `840.webp` pair with the existing
  `loading="eager"` + `fetchpriority="low"`, so the homepage's real LCP image (the
  preloaded `conexaobr_Hero_image-1600.webp`, `fetchpriority="high"`) keeps
  priority. Measured at DPR 1/2 in the browser verifier.
- No third-party script, font, video, canvas/WebGL, analytics or timer.


## 13. Production impact

| Question | Answer |
|---|---|
| Will anything be written to production? | **no** |
| Will a deploy, upload or activation happen? | **no** |
| Who performs it, and how (production has no SSH/WP-CLI)? | nobody — out of scope |
| What is the verification of the production result? | not applicable; the release path is `wp-release-deploy`, not this task |

## 14. Test plan

- In-process: `wp-content/themes/conexao-br-irlanda/tests/test-commemorative-christmas-welcome.php`
  (markup contract, ids, PT/EN, preview flag, route absence, read-only proof) plus
  the two updated assertions in `test-commemorative-christmas-journey.php`.
- Node: `tests/node/christmas-welcome.test.js` via the existing
  `tests/scripts/verify-commemorative-runtime.py` gate (new `TEST_FILES` entry).
- Acceptance matrix: no new row (no new HTTP surface).
- Browser: `tests/browser/verify-christmas-welcome-browser.py` — public `/` and
  `/en/` absence, landing-page absence, the authenticated preview page (banner
  preserved, no auto-open, controller drives the modal), and a generated harness
  built from the **real preview markup** for the auto-open proof at
  320×568, 375×667, 390×844, 360×800, 430×932, 768×1024, 1366×768 and 844×390.
- Pass numerically: 0 failing suites, 0 failing assertions, 0 browser failures,
  0 new script-contract failures.

## 15. Acceptance matrix plan

No row is added. Justification: the matrix schema asserts HTTP status and response
substrings for route behaviour; the popup is DOM/JS behaviour. The equivalent
proof is the PHP markup contract (server side) + the browser verifier (client side).

## 16. Rollback plan

Revert the six implementation files (template, CSS, JS, two test files, one gate
entry) and the documentation. No content, option, schema or cache state is created
by this change, so rollback needs no data reversal. The captured before/after git
state and the pre-existing stashes are recorded in the report.

## 17. Documentation plan

| Document | Change | Procedure moved to (skill) |
|---|---|---|
| `docs/themes/conexao-br-irlanda.md` | Christmas internals: the welcome dialog row + display/frequency/preview/no-JS contract | — (no procedure moved) |
| `docs/commemorative-events.md` | one bullet: `homepage_hero` may be presented as a once-per-session welcome dialog | — |
| `docs/testing.md` | the runtime gate row lists both Node suites | — |
| `docs/README.md` | report index row | — |
| `docs/reports/2026-11-06-christmas-homepage-welcome-modal.md` | the implementation record | — |

## 18. Release implications

No component version bump (the theme version is bumped by its own release task);
no artifact allowlist change (`plugins.json` untouched); **no release in scope**.

## 19. Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| The band is briefly visible inline before `showModal()` | medium | parse-time guard script + `pending` state; `DOMContentLoaded` fail-safe shows the band rather than nothing |
| `showModal()` throws on an unusual engine | low | `try/catch`, revert to the inline band, no dead close button |
| Re-adding `open` after `close()` reintroduces a modal | low | state machine asserted in the Node suite (`isOpen()` false, `:modal` false in the browser) |
| Scroll lock jumps the page on iOS | medium | `position: fixed` + `top: -scrollY` + explicit `scrollTo` restore; measured in emulated mobile |
| Short landscape heights clip the close button | medium | grid row 1 is outside the scrolling area; 844×390 is a required profile |
| Scope creep into a generic dialog component / Halloween | medium | refused in §2; only the Christmas package files change |

## 20. Verification gates

1. `./scripts/lint.sh` → PHP syntax + PHPCS + PHPStan clean.
2. `./scripts/run-tests.sh --only conexao-br-irlanda --php` → the new suite passes.
3. `python3 scripts/verify-commemorative-runtime.py` → the Node suites pass (both files).
4. `./scripts/run-tests.sh --scripts` → the script-contract layer stays green.
5. `./scripts/run-tests.sh` → the whole aggregate gate.
6. `python3 scripts/generate-registry-docs.php --check` → registry drift 0.
7. `python3 tests/browser/verify-christmas-welcome-browser.py --out docs/evidence/2026-11-06-christmas-homepage-welcome`
   → 0 failures, screenshots + JSON written.

## 21. Completion criteria

Every applicable item of `docs/engineering-standard.md` §14, plus: the modal opens
immediately on an eligible homepage view, is dismissible once per tab session, keeps
a usable no-JS fallback, passes the required compact/landscape viewport matrix in
PT-BR and English, and leaves `git status` with only this task's files.

_Last verified: 2026-11-06 by the Christmas homepage welcome-modal implementation_
