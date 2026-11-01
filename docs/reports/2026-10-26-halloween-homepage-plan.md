# Plan — Halloween Homepage Experience

| | |
|---|---|
| **Task title** | Redesign and Refine the Halloween Homepage Experience |
| **Date** | 2026-10-26 |
| **Author / agent** | Copilot AI |
| **Branch** | `holidays-dates` |
| **Repository baseline (starting SHA)** | `98af8fd52fb93baa4cad3fba95c112e3fa89f5dd` |
| **Applicable skill(s)** | `wp-repository`, `wp-frontend-perf`, `wp-write-in-process-test`, `wp-testing`, `wp-update-docs` |
| **Plan status** | approved |

## 1. Scope

Refine the existing Halloween-only homepage presentation into a centered,
cinematic hero treatment with original local SVG bat and pumpkin artwork,
localized PT/EN content, responsive styling, reduced-motion support, and a
keyboard-accessible close button. Dismissal is stored in `sessionStorage` with
an occasion-and-occurrence-year key. The existing occasion engine remains the
sole eligibility authority.

## 2. Explicit non-goals

- Do not alter occasion dates, enabled state, selection order, or the seasonal
  engine.
- Do not change sponsor data, carousel behavior, the normal hero design, routes,
  content, Polylang configuration, or site-wide design tokens.
- Do not add remote artwork, dependencies, a plugin, cookie infrastructure, or
  server-side writes.
- Do not deploy, change production, or access the frozen mobile application.
- Do not redesign other homepage sections or build other seasonal themes.

## 3. Relevant authoritative documents

- `AGENTS.md`
- `docs/engineering-standard.md`
- `docs/README.md`
- `docs/frontend.md`
- `docs/themes/conexao-br-irlanda.md`
- `docs/testing.md`
- `docs/templates/plan.md` and `docs/templates/report.md`
- `docs/reports/2026-10-26-seasonal-foundation-report.md` (completed foundation;
  retained without edits)

### 3a. Skill workflow

| | |
|---|---|
| **Skill** | `wp-repository`, `wp-frontend-perf`, `wp-write-in-process-test`, `wp-testing`, `wp-update-docs` |
| **Its preconditions** | Preserve the dirty worktree, use the current WordPress test/bootstrap conventions, capture the pre-change suite result, and inspect the local homepage at `http://localhost:8080`. |
| **Its guardrails that bind this task** | Existing occasion engine is authoritative; no production writes; no remote assets; no duplicate date logic; no test weakening; document the actual visual and test results. |
| **Its verification commands** | Focused seasonal presentation suite; `./scripts/run-tests.sh`; `./scripts/lint.sh`; browser verification at 320, 375, 390, 768, 1024, and 1440 CSS px. |
| **Its evidence location** | `docs/evidence/2026-10-26-halloween-homepage/` (only if actual screenshots or machine evidence are saved) |

## 4. Current-state findings

- The existing occasion foundation is in `inc/occasion.php`; it selects only
  enabled, currently active occasions and gives Halloween a 15-day lead-in
  ending inclusively on October 31. Its rules are not changed here.
- The current working tree already contains uncommitted Halloween work:
  modifications to `front-page.php`, `assets/css/main.css`, and
  `tests/test-seasonal-occasion.php`, plus an untracked
  `template-parts/halloween-hero.php`. These edits are preserved and reviewed
  in place; the existing occasion test is not rewritten.
- The current template places a lower-right message over the hero area that
  also contains the sponsor carousel. Artwork is made from CSS clip paths and
  basic shapes, there is no close control, and the text includes an emoji.
- The pre-edit baseline `./scripts/run-tests.sh` exited 1: 112 in-process
  suites (106 passed, 6 failed; 8,758 assertions passed, 19 failed), 19
  script-contract suites (18 passed, 1 failed), and 4 HTTP acceptance suites
  (3 passed, 1 failed). The failing baseline suites were
  `test-export-language.php`, `test-town-sanitization.php`,
  `test-event-query.php`, `test-event-filter-cache.php`,
  `test-stage41-rest-language.php`, `test-stage7-hero-sponsors.php`,
  `verify-i18n-freshness.py`, and `verify-event-occurrence-http.py`.
- The integrated browser is on `http://localhost:8080/`; the supplied/current
  screenshot confirms the carousel and Halloween message visually compete.

## 5. Proposed approach

Use a full-hero, non-modal presentation layer centered over the hero, with a
darkened moonlit backdrop and a contained greeting card. The layer intercepts
pointer input over the hero while visible and marks the underlying hero content
inert for keyboard and assistive-technology navigation. It is initially hidden
in HTML so the normal homepage remains usable without JavaScript; on
dismissal it is hidden immediately, hero inertness is removed, and focus returns
to the first hero CTA only when the close button held focus.

Create original inline SVG bat and jack-o'-lantern artwork in the existing
template part. Animate only a few bats with short, restrained CSS paths and
wing movement; remove all continuous seasonal animation under
`prefers-reduced-motion: reduce`. Use a restrained twilight backdrop, moon
halo, stars, and low-contrast mist; keep card typography centered, compact, and
high-contrast.

Store dismissal only in `sessionStorage` under a key containing the Halloween
occasion ID and the occurrence year returned by the existing occasion object.
If storage is unavailable, still dismiss immediately and allow the experience
to appear on a later page load.

Rejected options: a dialog/modal would unnecessarily trap and disable the
whole homepage; a fixed/local-storage dismissal would persist beyond the
session/year; remote illustration assets and new dependencies are unnecessary.

## 6. Files expected to change

| Path | Change | Why |
|---|---|---|
| `wp-content/themes/conexao-br-irlanda/front-page.php` | Pass the selected active Halloween occasion into the seasonal partial; preserve existing homepage markup. | Keep eligibility owned by the current engine and avoid duplicate lookups. |
| `wp-content/themes/conexao-br-irlanda/template-parts/halloween-hero.php` | Replace the existing simple decoration and card with hidden, localized, accessible presentation markup and inline SVG artwork. | Provide the redesigned visual composition and dismissal key. |
| `wp-content/themes/conexao-br-irlanda/assets/css/main.css` | Replace only the existing seasonal style block. | Center the card, establish scene depth, add responsive artwork and reduced-motion styling. |
| `wp-content/themes/conexao-br-irlanda/assets/js/main.js` | Add one initialization routine to the existing deferred bundle. | Session-scoped dismissal, hero inertness, and logical focus return. |
| `wp-content/themes/conexao-br-irlanda/tests/test-seasonal-halloween-presentation.php` | Add an in-process template-rendering regression suite. | Verify active Halloween renders while inactive, disabled, and other selected occasions do not. |
| `wp-content/themes/conexao-br-irlanda/languages/conexao-br-irlanda.pot` | Regenerate using `scripts/i18n-make-pot.sh` after adding translatable seasonal strings. | Keep the authoritative source-language catalogue fresh without hand-editing it. |
| `docs/frontend.md` | Document the seasonal presentation's responsive/accessibility contract. | Keep theme/frontend reference current. |
| `docs/themes/conexao-br-irlanda.md` | Document the homepage seasonal component and its relationship to the hero/carousel. | Keep the theme reference current. |
| `docs/reports/2026-10-26-halloween-homepage-plan.md` | This plan. | Required multi-file change planning record. |
| `docs/reports/2026-10-26-halloween-homepage-report.md` | Add the completed implementation report after verification. | Required dated implementation record. |
| `docs/reports/README.md`, `docs/README.md` | Link the plan/report in the relevant indexes. | Keep documentation discoverable. |
| `docs/evidence/2026-10-26-halloween-homepage/` | Add only actual browser screenshots/machine evidence if the tool can save them. | Preserve visual verification evidence without empty directories. |

## 7. Files explicitly expected NOT to change

- `wp-content/themes/conexao-br-irlanda/inc/occasion.php` — date rules and
  eligibility stay authoritative and unchanged.
- `wp-content/themes/conexao-br-irlanda/template-parts/featured-sponsors.php`
  and sponsor query/data code — carousel data and functionality stay unchanged.
- `wp-content/themes/conexao-br-irlanda/assets/css/dark-mode.css` and
  `assets/css/design-system.css` — no global palette or theme-mode changes.
- `docs/reports/2026-10-26-seasonal-foundation-plan.md` and
  `docs/reports/2026-10-26-seasonal-foundation-report.md` — completed foundation
  records must not be overwritten.
- `plugins.json`, routes, production data, deployment configuration, mobile
  repository, and unrelated homepage sections.

## 8. Content / data impact

- Records created / updated / deleted: none.
- Portable identifier strategy: no content identifiers; dismissal key uses the
  stable occasion slug and its occurrence year.
- Does this write content? No.
- PT content changed? No.

## 9. Route / HTTP impact

- Routes added, removed or changed: none.
- Redirects needed: none.
- Sitemap coverage: unchanged.
- HTTP matrix rows to add or update: none; the homepage route is unchanged and
  its active/inactive seasonal presentation is covered by template tests and
  local browser verification.

## 10. Polylang / English impact

- Translated post types / taxonomies affected: none.
- Is PT immutable in this change? Yes.
- B1/B2 handling: not applicable.
- Canonical, hreflang and language-switcher behaviour: unchanged.
- Language-scoped cache keys affected: none.
- Completeness gate: not applicable; verify the PT and EN homepages.

## 11. Security impact

- Capabilities/nonces: none; no admin write surface.
- Sanitisation / escaping: localized copy is escaped; destination uses the
  existing language-aware URL helper; dismissal key is escaped as an attribute.
- SQL: none.
- REST surface change: none.
- Secrets involved: none.

## 12. Performance impact

- Queries added or removed: none; use the already-resolved active occasion.
- Caching: no content cache/transient introduced; browser-only session storage.
- Assets or images affected: inline SVG only; no network request or dependency.
- Expected measurable improvement: no performance claim; confirm no new
  requests and inspect layout/interaction in the browser.

## 13. Production impact

| Question | Answer |
|---|---|
| Will anything be written to production? | **no** |
| Will a deploy, upload or activation happen? | **no** |
| Who performs it, and how? | n/a |
| What is the verification of the production result? | n/a |

## 14. Test plan

- In-process suite: `wp-content/themes/conexao-br-irlanda/tests/test-seasonal-halloween-presentation.php`.
- Acceptance area / matrix rows: none; no route contract changes.
- Script contract impact: none expected.
- Registry drift check: not applicable.
- Pass condition: focused template suite passes; full runner introduces no new
  failures relative to the pre-edit baseline; lint has no new violations;
  actual browser checks confirm dismissal, localization, responsive sizing,
  no horizontal overflow, and reduced motion.

## 15. Acceptance matrix plan

No HTTP matrix change: the route, canonical, hreflang, and page data contracts
are unchanged. Verify the existing local homepage in PT and `/en/` in-browser.

## 16. Rollback plan

Restore the prior seasonal template/style behavior, remove the added JS
initializer and presentation test, and revert the seasonal documentation/report
entries. Keep all unrelated pre-existing worktree changes untouched. No database
or production rollback is needed.

## 17. Documentation plan

| Document | Change | Procedure moved to (skill) |
|---|---|---|
| `docs/frontend.md` | Describe seasonal hero presentation, responsive layout, storage, and reduced motion. | n/a |
| `docs/themes/conexao-br-irlanda.md` | Describe the Halloween hero presentation and sponsor layering. | n/a |
| `docs/reports/README.md`, `docs/README.md` | Index dated plan/report. | n/a |
| `docs/reports/2026-10-26-halloween-homepage-report.md` | Record actual design, accessibility, tests, evidence, and limitations. | `wp-update-docs` |

## 18. Release implications

- Component version bump: none; this is a presentation refinement within the
  existing theme versioning scheme.
- Artifact allowlist: unchanged.
- Release/deployment: not in scope.

## 19. Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| Seasonal layer hides hero content when JavaScript is unavailable. | low | Keep the markup `hidden` until the deferred initializer reveals it. |
| Card or artwork collides with carousel/control content at narrow widths. | medium | Cover the hero with one centered layer; test all six requested viewport widths. |
| Session storage is unavailable. | low | Catch storage access errors; dismissal still hides immediately. |
| Scope expands into the sponsor carousel or global theme. | medium | Leave sponsor files and global tokens untouched. |

## 20. Verification gates

1. `./scripts/run-tests.sh --php --only theme` for the focused theme layer,
   including the seasonal presentation suite.
2. `./scripts/run-tests.sh` — compare every failure against the pre-edit baseline.
3. `./scripts/lint.sh`.
4. Browser checks on the local homepage at 320, 375, 390, 768, 1024, and 1440 CSS px, plus PT/EN, close/session behavior, and reduced motion.

## 21. Completion criteria

The task is complete when the centered greeting is legible and balanced, bats
are recognizably illustrated, the pumpkin is a deliberate local illustration,
closing restores normal hero interaction, English and Portuguese render
correctly, responsive and reduced-motion behavior are verified, the occasion
engine is unchanged, tests/lint are compared with baseline, documentation
contains real evidence and limitations, and no production/mobile scope was
touched.

_Last verified: 2026-10-26 by Halloween homepage implementation_
