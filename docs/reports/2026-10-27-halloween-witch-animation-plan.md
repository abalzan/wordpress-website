# Plan — Halloween witch animation

| | |
|---|---|
| **Task title** | Add a cinematic witch-on-a-broom navigation effect |
| **Date** | 2026-10-27 |
| **Author / agent** | Copilot |
| **Branch** | `holidays-dates` |
| **Repository baseline (starting SHA)** | `82f5a28` |
| **Applicable skill(s)** | `wp-repository`, `wp-frontend-perf`, `wp-testing`, `wp-write-in-process-test`, `wp-update-docs` |
| **Plan status** | approved |

## 1. Scope

Add a locally stored, accessible-as-decoration SVG witch-and-broom illustration
as a rare option in the current Halloween navigation click effects. Let the
existing same-origin continuation carry its 3-second duration with a bounded
expiry. Keep the existing bat, pumpkin and ghost selection, cooldown, cleanup,
native navigation, and occasion-marker gate.

## 2. Explicit non-goals

No new occasion/date engine, scheduler, plugin, dependency, animation manager,
seasonal preference, route, production action, deployment, sound, or mobile
repository access. Do not change the occasion configuration or welcome-card
dismissal state; do not make the animation automatic or delay navigation.

## 3. Relevant authoritative documents

- `AGENTS.md`; `docs/engineering-standard.md` §§0, 3, 8, 9 and 14.
- `docs/frontend.md`; `docs/themes/conexao-br-irlanda.md`.
- `docs/testing.md`; `docs/templates/plan.md`; `docs/templates/report.md`.
- Current effect implementation: theme `header.php`, `assets/js/main.js`,
  `assets/css/main.css`, `inc/occasion.php`, and the Halloween navigation test.

## 3a. Skill workflow

| | |
|---|---|
| **Skill** | `wp-repository` plus frontend performance, test authoring/running, and docs workflows |
| **Its preconditions** | Confirm branch/worktree; inspect current implementation; use the local site only |
| **Its guardrails that bind this task** | Reuse the current effect system and occasion authority; version assets; preserve normal navigation; do not edit production |
| **Its verification commands** | Focused theme PHP suite, JS syntax, lint, and `./scripts/run-tests.sh` where supported |
| **Its evidence location** | `docs/evidence/2026-10-27-halloween-witch/` only if verification artifacts are captured |

## 4. Current-state findings

At `82f5a28`, `header.php` conditionally emits a Halloween marker based only on
`conexao_seasonal_variant()`. The deferred `main.js` listener selects bat,
pumpkin, or ghost for eligible, unmodified, same-origin `.nav-link` clicks;
it allows one active effect at a time and stores a one-shot, validated
sessionStorage handoff. The current effect duration is 1,000 ms and state
expires after 1,500 ms. CSS uses a fixed, pointer-transparent layer and includes
reduced-motion rules. Only unrelated local SVG image assets exist. The local
browser currently renders the marker at 1440px with reduced motion disabled.

## 5. Proposed approach

Add an original SVG in `assets/images/halloween/`; pass its escaped local URL
only in the server-emitted active-occasion marker. Select the witch on a
1-in-8 eligible effect-selection roll (not consecutively), while retaining the
three current effects and the single-flight guard. Animate the illustration
from wholly beyond the left viewport edge to wholly beyond the right in 3,000
ms, with restrained height drift and four finite CSS sparkles. Keep the current
continuity key and validation, but derive animation duration per effect and
raise its shared expiry ceiling to 3,500 ms. Reduced motion hides the witch and
stops initialization. Tests extend the existing Halloween nav suite.

Rejected: creating a separate component/manager or per-feature persistence
key, adding a new DOM initializer outside the existing Halloween marker, or
embedding complex artwork as unrelated CSS shapes.

## 6. Files expected to change

| Path | Change | Why |
|---|---|---|
| `wp-content/themes/conexao-br-irlanda/header.php` | Add escaped local artwork URL to the existing active marker | Eligibility and conditional asset availability |
| `wp-content/themes/conexao-br-irlanda/assets/images/halloween/witch-broom.svg` | Add original decorative artwork | Recognizable, reusable local character illustration |
| `wp-content/themes/conexao-br-irlanda/assets/js/main.js` | Add rare selection, per-effect timing, bounded artwork/trail creation and cleanup | Reuse navigation interaction and continuity |
| `wp-content/themes/conexao-br-irlanda/assets/css/main.css` | Add flight/trail styles and reduced-motion behavior | Transform/opacity-only responsive animation |
| `wp-content/themes/conexao-br-irlanda/tests/test-seasonal-halloween-nav-effects.php` | Extend focused regression assertions | Lock selection, 3-second path, gating, cleanup and native navigation |
| `docs/frontend.md`, `docs/themes/conexao-br-irlanda.md` | Record canonical effect behavior | Keep theme/frontend references current |
| `docs/README.md`, `docs/reports/README.md` | Index dated plan and report | Documentation discoverability |
| `docs/reports/2026-10-27-halloween-witch-animation-plan.md` | Record this plan | Required multi-file-work plan |
| `docs/reports/2026-10-27-halloween-witch-animation-report.md` | Report actual implementation and verification | Historical result |

## 7. Files explicitly expected NOT to change

`wp-content/themes/conexao-br-irlanda/inc/occasion.php` (sole eligibility
authority, unchanged); occasion configuration, greeting template/state,
`inc/assets.php` (existing main bundle already loads the animation code),
plugins, routes, content, databases, production, deployment files, and any
Flutter/mobile repository.

## 8. Content / data impact

- Records created / updated / deleted: **none**
- Portable identifier strategy: **not applicable**
- Content write: **no**
- PT content changed: **no**

## 9. Route / HTTP impact

- Routes added, removed or changed: **none**
- Redirects: **none**
- Sitemap coverage: **none**
- HTTP matrix rows: **none; no request/route contract changes**

## 10. Polylang / English impact

- Translated content/taxonomies: **none**
- PT immutable: **yes**
- B1/B2, canonical, hreflang, language switcher: **unchanged**
- Cache keys and completeness gate: **none**

## 11. Security impact

No request input, database, endpoint, privilege, nonce, SQL, or secret changes.
The only new output is the local theme asset URL, escaped with `esc_url()`.

## 12. Performance impact

No query or cache changes. The theme’s already-loaded deferred script and CSS
gain one rare, small local SVG presentation path; the SVG is only requested
when the witch is selected. Four particles are created once per flight, with
no animation loop or recurring element creation.

## 13. Production impact

| Question | Answer |
|---|---|
| Will anything be written to production? | **No** |
| Will a deploy, upload or activation happen? | **No** |
| Who performs it, and how? | Not in scope |
| Production verification? | Not applicable; production remains untouched |

## 14. Test plan

- Extend `wp-content/themes/conexao-br-irlanda/tests/test-seasonal-halloween-nav-effects.php`.
- Acceptance area/matrix: none; this changes no request-visible contract.
- Script contract and registry checks: no source change to either domain.
- Pass criteria: focused suite passes; JS syntax valid; all runnable applicable
  gates pass or are reported with exact limitations.

## 15. Acceptance matrix plan

No HTTP acceptance row: eligibility markup already exists, and the change adds
no route or response contract. In-process assertions cover the existing server
marker and frontend interaction contract.

## 16. Rollback plan

Remove the witch selection/creation path and CSS, remove its escaped URL
attribute, and delete the new SVG; restore the corresponding test/docs changes.
No database, content, production, or deployment rollback is needed.

## 17. Documentation plan

| Document | Change | Procedure moved to (skill) |
|---|---|---|
| `docs/frontend.md` | Document rare effect, 3-second continuation bound and reduced motion | None |
| `docs/themes/conexao-br-irlanda.md` | Document local illustration and active-occasion gate | None |
| `docs/README.md`, `docs/reports/README.md` | Index the new plan and report | None |
| `docs/reports/2026-10-27-halloween-witch-animation-report.md` | Record actual outcomes | None |

## 18. Release implications

- Theme version bump: **none planned**; follow existing component version practice.
- Artifact allowlist: **unchanged**.
- Release/deployment: **out of scope**.

## 19. Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| SVG silhouette becomes illegible at small width | Medium | Inspect at requested viewport widths and adjust artwork/sizing |
| Longer effect replays after navigation or refresh | Low | Reuse one-shot validation, navigation-type checks and 3,500 ms expiry; test rejection |
| Reduced-motion preference exposes static overlay | Low | Hide the witch under the media query and check runtime behavior |
| Scope expands into unrelated seasonal refactoring | Medium | Keep all changes within the existing effect selector and its docs/test |

## 20. Verification gates

1. `node --check wp-content/themes/conexao-br-irlanda/assets/js/main.js`
2. Focused Halloween nav PHP suite through the local WordPress runner.
3. `./scripts/lint.sh`
4. `./scripts/run-tests.sh` (report any pre-existing/environment failures honestly).
5. Local browser interaction and layout checks at 320, 375, 390, 768, 1024,
   and 1440px; inspect reduced-motion and cleanup.

## 21. Completion criteria

Recognizable witch and broom complete an approximately 3-second, bounded flight
and exit; rare existing navigation selection remains immediate and single-flight;
the existing occasion marker is the sole gate; reduced motion is respected;
the trail and temporary DOM are cleaned; responsive checks and applicable
tests/gates are reported with real results; no production/mobile scope is touched.

_Last verified: 2026-10-27 by Halloween witch animation plan_
