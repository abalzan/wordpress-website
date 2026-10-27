# Plan — Halloween Light/Dark Theme Compatibility

| | |
|---|---|
| **Task title** | Refine the existing Halloween experience for light and dark themes |
| **Date** | 2026-10-27 |
| **Author / agent** | Copilot AI |
| **Branch** | `holidays-dates` |
| **Repository baseline (starting SHA)** | `f378c2f4d3fbea4a320e0197f8949d5f4cff23fe` |
| **Applicable skill(s)** | `wp-repository`, `wp-frontend-perf`, `wp-testing`, `wp-update-docs` |
| **Plan status** | approved |

## 1. Scope

Refine the existing Halloween presentation and click effects to respond to the
site's established `data-theme` CSS state. Use component-scoped Halloween color
tokens in the existing light and dark stylesheets, preserve presentation,
dismissal, navigation and occasion behavior, and verify both themes in the
browser across the requested viewport widths and reduced-motion mode.

## 2. Explicit non-goals

- Do not add a theme toggle, preference key, event system, seasonal lifecycle,
  date calculation, plugin, dependency, or animation library.
- Do not change global theme behavior, content, routes, navigation, sponsors,
  homepage structure, the seasonal occasion engine, or the witch flight path.
- Do not alter unrelated design-system rules or visit the Flutter/mobile repo.
- Do not deploy or change production state.

## 3. Relevant authoritative documents

`AGENTS.md`; `docs/engineering-standard.md` §§0, 2.3, 3, 8, 9, 14;
`docs/README.md`; `docs/frontend.md`; `docs/testing.md`;
`docs/themes/conexao-br-irlanda.md`; existing Halloween reports and
`docs/templates/{plan,report}.md`.

### 3a. Skill workflow

| | |
|---|---|
| **Skill** | `wp-repository`, `wp-frontend-perf`, `wp-testing`, `wp-update-docs` |
| **Its preconditions** | Clean worktree; local WordPress and browser available; baseline revision recorded. |
| **Its guardrails that bind this task** | Reuse the current theme and occasion engines; no global theme or seasonal lifecycle changes; verify light, dark, responsive and reduced-motion behavior; report only actual results. |
| **Its verification commands** | Focused Halloween PHP suites; `./scripts/run-tests.sh`; `./scripts/lint.sh`; browser verification. |
| **Its evidence location** | `docs/evidence/2026-10-27-halloween-theme-compatibility/` |

## 4. Current-state findings

- The site initializes `html[data-theme]` before paint from the existing
  `conexao-theme` localStorage value or OS preference. The existing deferred
  toggle changes that attribute in place and persists the same key.
- The welcome uses session-only dismissal keyed by Halloween occurrence year;
  `main.js` does not reinitialize it on theme changes.
- On the local Halloween-eligible homepage at 320px, the presentation is open
  in light mode, but its card has a fixed midnight surface, white text and
  hard-coded orange details. The close button measures 44×44px.
- Bat, pumpkin, ghost, witch and particles use hard-coded CSS/inline SVG colors.
  The navigation witch flight is 3s and the current reduced-motion rules remain
  in effect.
- The starting Git tree is clean at the baseline SHA above.

## 5. Proposed approach

Add a small Halloween-scoped semantic palette to the presentation and
navigation-effect layer in `main.css`, with independent overrides selected by
the existing root `data-theme="dark"` selector in `dark-mode.css`. Consume
these variables in the card, buttons, scene decorations and navigation effects.
Keep the SVG witch's artwork and warm outline intact; improve its theme-specific
separation with CSS drop-shadows. Do not listen for theme events or modify
animation state. Rejected: fixed dark styling in both modes (the demonstrated
light-mode mismatch), inversion filters (distort artwork), and JavaScript theme
state (duplicates existing state).

## 6. Files expected to change

| Path | Change | Why |
|---|---|---|
| `wp-content/themes/conexao-br-irlanda/assets/css/main.css` | Light-mode Halloween tokens and tokenized seasonal styles. | Warm autumn treatment and CSS-driven effect colors. |
| `wp-content/themes/conexao-br-irlanda/assets/css/dark-mode.css` | Dark-mode Halloween token overrides. | Cinematic treatment under the existing root theme selector. |
| `wp-content/themes/conexao-br-irlanda/template-parts/halloween-hero.php` | Inline pumpkin SVG uses inherited Halloween color tokens. | Keep its shading controllable without replacing artwork. |
| `wp-content/themes/conexao-br-irlanda/assets/js/main.js` | Navigation pumpkin/ghost SVG colors use inherited CSS variables only. | Let in-flight effects follow theme CSS without state duplication. |
| `wp-content/themes/conexao-br-irlanda/tests/test-seasonal-halloween-presentation.php` | Assert scoped palette usage and preserved presentation/dismissal contract. | Guard both theme styles and original rendering. |
| `wp-content/themes/conexao-br-irlanda/tests/test-seasonal-halloween-nav-effects.php` | Assert theme-variable integration and unchanged duration/eligibility. | Guard animated effects and current lifecycle. |
| `docs/frontend.md` | Document the theme-aware Halloween presentation and existing theme mechanism. | Keep the current frontend contract accurate. |
| `docs/themes/conexao-br-irlanda.md` | Document light/dark presentation behavior. | Keep theme integration facts canonical. |
| `docs/reports/2026-10-27-halloween-theme-compatibility-plan.md` | This plan. | Multi-file workflow record. |
| `docs/reports/2026-10-27-halloween-theme-compatibility-report.md` | Actual implementation and verification results. | Required outcome report. |
| `docs/reports/README.md`, `docs/README.md` | Index plan and report. | Documentation navigation. |
| `docs/evidence/2026-10-27-halloween-theme-compatibility/` | Captured screenshots and concise verification evidence, if export works. | Browser proof. |

## 7. Files explicitly expected NOT to change

`inc/occasion.php`, `front-page.php`, `header.php`, `assets/images/halloween/witch-broom.svg`,
`design-system.css`, theme initialization/toggle and persistence code, plugins,
routes, mobile repository, production data and deployment configuration.

## 8. Content / data impact

- Records created / updated / deleted: **none**
- Portable identifier strategy: **not applicable**
- Content writes: **no**
- PT content changed: **no**

## 9. Route / HTTP impact

- Routes added, removed or changed: **none**
- Redirects needed: **none**
- Sitemap coverage: **unchanged**
- HTTP matrix rows: **none required for this visual-only change**

## 10. Polylang / English impact

- Translated post types / taxonomies affected: **none**
- PT immutable: **yes**
- B1/B2 destinations: **not applicable**
- Canonical, hreflang, language switcher: **unchanged**
- Language cache keys: **none**
- Completeness gate: **unchanged**

## 11. Security impact

- Capabilities, nonces, sanitization, SQL, REST and secrets: **no changes**
- Existing escaped template output remains unchanged.

## 12. Performance impact

- Queries: **none added or removed**
- Caching: **none**
- Assets/images: **no new network assets; existing inline art and local witch SVG reused**
- Expected effect: CSS-variable recoloring should not restart animations or alter layout; verify in browser.

## 13. Production impact

| Question | Answer |
|---|---|
| Will anything be written to production? | **no** |
| Will a deploy, upload or activation happen? | **no** |
| Who performs it, and how? | Not applicable. |
| Production verification? | Not in scope. |

## 14. Test plan

- In-process suites: `test-seasonal-halloween-presentation.php` and
  `test-seasonal-halloween-nav-effects.php`.
- Acceptance rows: **none; no route or response contract changes**.
- Script contract / registry: **no changes expected**.
- Pass criteria: focused suites pass; full runner and lint results reported
  accurately; both themes, all six widths and reduced motion inspected live.

## 15. Acceptance matrix plan

No HTTP acceptance row is warranted for a CSS-only presentation refinement.
The browser matrix covers the active welcome, dismissal, bat, pumpkin, witch,
navigation effect, reduced motion and responsive layouts.

## 16. Rollback plan

Revert only the listed theme, test, documentation and evidence changes. No
content, storage migration or production rollback is needed. The existing theme
preference and Halloween dismissal values remain untouched.

## 17. Documentation plan

| Document | Change | Procedure moved to (skill) |
|---|---|---|
| `docs/frontend.md` | Record the theme-aware seasonal visual contract. | None. |
| `docs/themes/conexao-br-irlanda.md` | Record theme-scoped implementation facts. | None. |
| `docs/README.md`, `docs/reports/README.md` | Index the new plan and report. | None. |
| New dated report | Capture actual outcomes and limitations. | None. |

## 18. Release implications

- Component version bump: **none** (theme stylesheet/template change only).
- Artifact allowlist: **unchanged**.
- Release / deployment: **not in scope**.

## 19. Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| A token override fails due stylesheet cascade order. | Medium | Verify computed values in the live browser after toggling both modes. |
| Fixed witch artwork becomes indistinct over a particular background. | Medium | Inspect a 1.5s flight midpoint in both themes and adjust only its CSS separation. |
| Too much scope creeps into global theme or unrelated homepage styling. | Low | Keep all new palette selectors seasonal and compare the ordinary homepage. |

## 20. Verification gates

1. `./scripts/run-tests.sh --php --only theme` — seasonal suites included.
2. `./scripts/run-tests.sh` — complete contract, with baseline failures classified.
3. `./scripts/lint.sh` — PHP syntax, PHPCS and PHPStan.
4. Browser checks at 320, 375, 390, 768, 1024 and 1440px in both themes, then reduced motion in both.

## 21. Completion criteria

The theme toggle and persisted preference remain unchanged; all Halloween
illustrations and controls are visible and usable in both themes; dismissal and
animation state remain intact across theme changes; reduced motion still applies;
the normal homepage and seasonal eligibility remain unchanged; tests, quality
gates and evidence are reported with actual outcomes; no production action or
mobile-repository access occurs.

_Last verified: 2026-10-27 by Halloween theme compatibility planning_
