# Plan — Halloween mobile spontaneous experience

| | |
|---|---|
| **Task title** | Add occasional, safe Halloween appearances while browsing on mobile |
| **Date** | 2026-10-27 |
| **Author / agent** | Copilot |
| **Branch** | `holidays-dates` |
| **Repository baseline (starting SHA)** | `a6ad2632134d9ff8c33451044efc77223116dab4` |
| **Applicable skill(s)** | `wp-repository`, `wp-frontend-perf`, `wp-testing`, `wp-write-in-process-test`, `wp-update-docs`; `wp-security-review` checked and not applicable to this presentation-only change |
| **Plan status** | approved |

## 1. Scope

Extend the existing Halloween navigation-effect initializer with a single
mobile-only, client-side scheduler. After a randomized 20–30 second first
delay, it will select one of the existing bat, pumpkin, ghost, and witch
effects; later effects will be separated by randomized 25–35 second waits
after the previous animation has finished. The existing active-Halloween
marker remains the only eligibility signal. Place edge decorations in bounded
viewport zones after checking visible content and controls; use deliberate,
horizontal full-viewport paths for the bat and witch. Pause and clear scheduled
work when the page is hidden, the viewport is no longer mobile, motion is
reduced, or the Halloween marker is removed. A fresh visibility/UI resume keeps
the 20–30 second initial range until an effect actually appears, then uses the
25–35 second subsequent range. Do not retain spontaneous state across
navigation.

## 2. Explicit non-goals

- Do not change `inc/occasion.php`, the Halloween window, or occasion selection.
- Do not add a scheduler, preference key, persistent opt-out, settings UI,
  plugin, framework, animation library, asset download, or endpoint.
- Do not alter the welcome greeting's dismissal contract, the theme preference,
  navigation semantics, or the existing cross-page navigation handoff.
- Do not launch effects on desktop/tablet, add sound, animate continuously, or
  redesign mobile navigation or unrelated page content.
- Do not touch the frozen Flutter/mobile repository, production state, or
  deployment configuration; do not deploy.

## 3. Relevant authoritative documents

`AGENTS.md`; `docs/engineering-standard.md` §§0, 2.2–2.3, 3.3, 8, 9 and 14;
`docs/README.md`; `docs/frontend.md`; `docs/testing.md`;
`docs/themes/conexao-br-irlanda.md`; `docs/templates/plan.md`;
`docs/templates/report.md`; `wp-content/themes/conexao-br-irlanda/inc/occasion.php`;
the current Halloween template, marker, JS/CSS, witch SVG, theme toggle and
Halloween regression suites.

### 3a. Skill workflow

| | |
|---|---|
| **Skill** | `wp-repository`, `wp-frontend-perf`, `wp-testing`, `wp-write-in-process-test`, `wp-update-docs` |
| **Its preconditions** | Clean worktree; local WordPress and browser available; starting SHA and focused baseline recorded. |
| **Its guardrails that bind this task** | Reuse current occasion/effect/theme systems; no content, route, or endpoint change; version assets through the existing enqueue; respect reduced motion; use the shared PHP test harness; report only actual results. |
| **Its verification commands** | Focused Halloween PHP suites; JS syntax check; `./scripts/run-tests.sh`; `./scripts/lint.sh`; registry/documentation checks; live browser checks at mobile, tablet, and desktop sizes. |
| **Its evidence location** | `docs/evidence/2026-10-27-halloween-mobile-experience/` |

## 4. Current-state findings

- The working tree was clean at `a6ad2632134d9ff8c33451044efc77223116dab4`
  on `holidays-dates`.
- `inc/occasion.php` selects and filters active enabled occasions. The header's
  `seasonal-halloween-active-marker` is emitted only when
  `conexao_seasonal_variant()` returns `halloween`; the client does not own a
  date rule.
- `assets/js/main.js` already owns the four navigation effects, the
  single-effect guard, the 3,000 ms witch duration, reduced-motion checks, and
  the one-shot navigation handoff. The inline bat, pumpkin, and ghost art and
  `assets/images/halloween/witch-broom.svg` are local.
- The welcome uses its own occurrence-keyed session dismissal. There is no
  existing Halloween interaction opt-out preference. Closing the greeting
  therefore must not gate the new scheduler.
- The established `data-theme` CSS tokens already recolor effect layers in
  `main.css` and `dark-mode.css`. The theme toggle updates that attribute
  without reinitializing `main.js`.
- The existing focused baseline command,
  `./scripts/run-tests.sh --php --only theme`, ran 52 suites: 50 passed and 2
  failed (8 assertions). The failures were
  `test-event-filter-cache.php` (4) and `test-stage41-rest-language.php` (4).
  Existing Halloween navigation and presentation suites passed (69/69 and
  26/26, respectively).
- The shared local browser is currently at 320×568 on the eligible homepage.
  The Halloween marker is present; dismissing the greeting restores the hero.
  Existing homepage horizontal overflow is present before this change and is
  not part of this task.

## 5. Proposed approach

Extend `initHalloweenNavEffects()` rather than adding a second system. Keep
exactly one timeout handle; clear it before scheduling and whenever the page
becomes hidden or ineligible. Use a randomized 20,000–30,000 ms initial delay
and 25,000–35,000 ms subsequent delay, scheduled after an effect completes.
When the page becomes visible again, schedule a fresh delay in the range
appropriate to whether an effect has appeared rather than accounting for
hidden time. Share `pickEffect()`, `playEffect()`, and the
existing active-effect guard. A direct navigation interaction cancels an
in-flight spontaneous layer and retains priority; the next page never restores
spontaneous state.

For pumpkin and ghost, shuffle a small set of bounded edge/corner positions
inside the visible viewport and reject candidates that intersect headings,
text, links, controls, forms, the sticky header, or active overlays. Consider
safe-area insets in the bounds. For bat and witch, test a small set of
horizontal flight bands across the viewport against those same obstructions;
skip and reschedule if no band is safe. Give spontaneous sprites responsive
minimum sizes and keep movement to transform/opacity. Complete the ghost's
existing inline silhouette if needed for recognizability, without adding an
asset. Keep the witch on its local SVG and existing three-second flight.

Do not add an opt-out setting: the established reduced-motion preference is
the supported control, while the effects are sparse, decorative, and
non-interactive. Do not listen to the theme toggle; CSS tokens continue to
update the in-flight layer in place.

Rejected: another seasonal/date check (would fork the occasion authority);
persisting spontaneous state (could replay on refresh/history); unrestricted
random coordinates (could cover controls/content); and a continuously running
animation loop (unnecessary work and battery use).

## 6. Files expected to change

| Path | Change | Why |
|---|---|---|
| `wp-content/themes/conexao-br-irlanda/assets/js/main.js` | Add one eligible mobile scheduler, safe bounded placement, visibility/lifecycle cleanup, and direct-interaction priority to the existing effect initializer. | Reuse the established effect and continuity systems. |
| `wp-content/themes/conexao-br-irlanda/assets/css/main.css` | Add scoped spontaneous sprite sizing/path rules; preserve existing navigation behavior and reduced-motion behavior. | Keep effects recognizable and inside the viewport without layout work. |
| `wp-content/themes/conexao-br-irlanda/tests/test-seasonal-halloween-nav-effects.php` | Add regression assertions for ranges, single-timer lifecycle, eligibility, placement/path, reduced motion, cleanup, and no new persistence. | Extend the existing seasonal test convention. |
| `docs/frontend.md` | Document the mobile cadence, placement, eligibility, lifecycle, and reduced-motion contract. | Canonical frontend behavior. |
| `docs/themes/conexao-br-irlanda.md` | Document how spontaneous effects reuse the theme/occasion/navigation systems. | Canonical theme architecture. |
| `docs/README.md`, `docs/reports/README.md` | Index the dated plan and report. | Documentation navigation. |
| `docs/reports/2026-10-27-halloween-mobile-experience-plan.md` | This plan. | Multi-file workflow record. |
| `docs/reports/2026-10-27-halloween-mobile-experience-report.md` | Record actual implementation and verification. | Required outcome report. |
| `docs/evidence/2026-10-27-halloween-mobile-experience/verification-notes.md` | Record browser measurements and actual checks if the browser run succeeds. | Auditable browser evidence; no empty directory. |

## 7. Files explicitly expected NOT to change

`wp-content/themes/conexao-br-irlanda/inc/occasion.php`,
`front-page.php`, `header.php`, `template-parts/halloween-hero.php`,
`assets/css/dark-mode.css`, `assets/css/header-nav.css`,
`assets/css/design-system.css`, `assets/images/halloween/witch-broom.svg`,
`functions.php`, `inc/assets.php`, plugins, `plugins.json`, content, routes,
database, production state, and the Flutter/mobile repository. Their current
eligibility, markup, enqueue, theme, asset, navigation, and data boundaries
remain authoritative.

## 8. Content / data impact

- Records created / updated / deleted: **none**
- Portable identifier strategy: **not applicable**
- Content writes: **no**
- PT content changed: **no**

## 9. Route / HTTP impact

- Routes added, removed or changed: **none**
- Redirects needed: **none**
- Sitemap coverage: **unchanged**
- HTTP matrix rows: **none; no request/response contract changes**

## 10. Polylang / English impact

- Translated post types / taxonomies affected: **none**
- PT immutable: **yes**
- B1/B2 destinations: **not applicable**
- Canonical, hreflang, language switcher: **unchanged**
- Language-scoped cache keys: **none**
- Completeness gate: **unchanged**

## 11. Security impact

- Capabilities, nonces, sanitisation, SQL, REST, and secrets: **no changes**
- No request input or data access is added.
- No dynamic server output or unescaped template content is added.
- `wp-security-review` was checked; its scope explicitly excludes this
  presentation-only, no-endpoint change.

## 12. Performance impact

- Queries: **none added or removed**
- Caching: **none**
- Assets/images: **no new network assets; reuse local SVG/inline artwork and the
  existing deferred theme bundle**
- At most one timer and one main effect; no repeated polling or animation loop.
- Verify no added page overflow, correct lifecycle cleanup, and no duplicate
  effect/timer in the browser.

## 13. Production impact

| Question | Answer |
|---|---|
| Will anything be written to production? | **no** |
| Will a deploy, upload or activation happen? | **no** |
| Who performs it, and how? | Not applicable. |
| Production verification? | Not in scope. |

## 14. Test plan

- In-process suite: `wp-content/themes/conexao-br-irlanda/tests/test-seasonal-halloween-nav-effects.php`.
- Acceptance rows: **none**; no HTTP contract changes.
- Script contract / registry: **no changes expected**.
- Use the browser's controllable clock and deterministic random source for
  initial/subsequent bounds, effect completion, visibility pause/resume, theme
  switching, dismissal, and reduced motion where supported.
- Verify live responsive behavior at 320, 375, 390, 430, 768, 1024, and 1440
  CSS pixels, in light/dark mode and reduced motion.
- Pass criteria: the static suite and JS syntax check pass; one timer/effect at
  most; initial/subsequent waits stay within their stated bounds; no hidden-time
  burst; no unsafe placement or horizontal overflow introduced; occasion marker
  remains the sole eligibility signal; all repository gates are reported with
  actual results.

## 15. Acceptance matrix plan

No HTTP matrix row is warranted for a client-side decorative enhancement.
Verify the existing homepage and representative content page in the browser;
the server-rendered Halloween eligibility marker and routes must remain
unchanged.

## 16. Rollback plan

Revert the listed JS, CSS, test, documentation, report, and evidence changes.
No database, content, user preference, release artifact, or production rollback
is needed. No spontaneous state is persisted.

## 17. Documentation plan

| Document | Change | Procedure moved to (skill) |
|---|---|---|
| `docs/frontend.md` | Add the current seasonal mobile interaction contract. | None. |
| `docs/themes/conexao-br-irlanda.md` | Record ownership and reuse of the occasion and navigation systems. | None. |
| `docs/README.md`, `docs/reports/README.md` | Index the dated plan and report. | None. |
| Dated report and evidence note | Record actual outputs and limitations after verification. | None. |

## 18. Release implications

- Component version bump: **none** (theme source-only change; no component
  header changes).
- Artifact allowlist: **unchanged**.
- Release / deployment: **not in scope**.

## 19. Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| A candidate sprite or flight path covers content or fixed controls. | Medium | Measure viewport bounds and reject intersections; skip when no safe zone exists. |
| Delayed timers fire in a burst after backgrounding. | Medium | Clear on hidden/pagehide and schedule a fresh bounded delay on return. |
| Theme or eligibility changes create duplicate work. | Low | Keep one timeout handle; CSS owns theme changes; re-check the existing marker before every appearance. |
| Scope expands into unrelated mobile layout or global theme behavior. | Low | Limit implementation to the current effect initializer and seasonal CSS; leave shared systems untouched. |

## 20. Verification gates

1. `./scripts/run-tests.sh --php --only theme` — Halloween contract suite passes;
   baseline unrelated failures are compared accurately.
2. `node --check wp-content/themes/conexao-br-irlanda/assets/js/main.js` and
   `git diff --check` — syntax and whitespace pass.
3. `./scripts/run-tests.sh` and `./scripts/lint.sh` — report full results and
   classify failures against the recorded baseline.
4. Browser timer/position checks at the listed mobile/tablet/desktop sizes and
   theme/motion modes — record only actual viewport and interaction results.
5. `php scripts/generate-registry-docs.php --check` and
   `python3 tests/scripts/verify-agent-governance.py` — no registry or governance
   drift.

## 21. Completion criteria

The existing mobile experience gets one randomized, non-overlapping Halloween
surprise at the documented cadence only while the existing engine marks
Halloween active; safe positions vary within the visible viewport; the witch
retains its 3,000 ms flight; reduced motion, hidden tabs, direct navigation,
welcome dismissal, theme changes, and full-page navigation preserve their
existing contracts; tests and applicable gates are reported with real
outputs; the documentation and evidence reflect actual verification; no
production action or Flutter/mobile repository access occurs.

_Last verified: 2026-10-27 by Halloween mobile experience planning_
