# Plan — Christmas micro-animations

> This is a filled-in plan copied from `docs/templates/plan.md`. It lives under
> `docs/plans/` only.

| | |
|---|---|
| **Task title** | Christmas micro-animations (“One Christmas, Two Skies” shared-star motion language) |
| **Date** | 2026-11-08 |
| **Author / agent** | Cline |
| **Branch** | `commemorative-events` |
| **Repository baseline (starting SHA)** | `d7602d7556caabf59d00b70ef1aaaed2fb8cd267` |
| **Applicable skill(s)** | `wp-repository`, `wp-frontend-perf`, `wp-security-review`, `wp-testing`, `wp-write-in-process-test`, `wp-update-docs` |
| **Plan status** | draft |

## 1. Scope

Add a small, coherent set of Christmas micro-animations to the existing `christmas`
presentation package only, using its already registered assets, placements, script
(`assets/js/presentations/christmas/christmas.js`) and stylesheet
(`assets/css/presentations/christmas.css`). No new event, package, placement, route,
CPT, meta key, enqueue or plugin. The existing welcome modal, artwork, copy, CTAs and
landing-page content are preserved exactly; only animation hooks and the motion
themselves are added.

Assessed against the actual markup:
1. Welcome star twinkle — IMPLEMENT (CSS-only, driven by the dialog's existing open state).
2. Selected CTA sparkle — IMPLEMENT (welcome popup CTA + landing closing CTA, delegated click, bounded particles).
3. Gentle content reveal — IMPLEMENT (IntersectionObserver on landing-page journey/modules/closing; content stays visible without JS).
4. Sparse ambient star glints — RETAINED AS-IS (already implemented by the `site_wide_ambient` placement + shared scheduler; a second ambient scheduler is architecturally forbidden, so no duplicate is added).
5. Shared-star connection on the journey — IMPLEMENT as a subtle one-time glow pulse of the journey's shared star when it first becomes visible (no transform, so it cannot fight the user-controlled star travel).

## 2. Explicit non-goals

- No new event key, package, placement, scheduler, resolver, route, CPT, meta key, endpoint or activation path.
- No second ambient scheduler (the shared framework owns the single ambient timer).
- No generic site-wide click animation system; Christmas effects run only where the Christmas package renders.
- No changes to the welcome modal's open/close/session/preview/focus/scroll behaviour.
- No changes to the Halloween experience.
- No third-party animation library, remote service, external font, runtime network dependency or large animated image.
- No production write, deploy, activation, commit, push or stash.
- No redesign of the Christmas experience; no motion added just to reach a count of five.

## 3. Relevant authoritative documents

- `AGENTS.md` (orientation + non-negotiable rules)
- `docs/engineering-standard.md` (authoritative standard)
- `docs/content-model.md` (Polylang ownership, locale via `conexao_current_locale()`)
- `docs/routing.md` (routes, placements, the `/en/` layer)
- `docs/themes/conexao-br-irlanda.md` (theme internals)
- `docs/testing.md` (test layers, permanent gates)
- `plugins.json` (plugin registry — unchanged)
- The 2026-11-06 Christmas reports (signature experience, welcome modal, landing dispatch fix)

## 3a. Skill workflow

| Skill | Its preconditions | Its guardrails that bind this task | Its verification commands |
|---|---|---|---|
| `wp-repository` | Plan + report templates; baseline state recorded | Hold scope; prefer shared engines; review before merge | `./scripts/run-tests.sh`, plan/report in `docs/` |
| `wp-frontend-perf` | Templates, queries, caching, assets | transform/opacity only; no layout shift; bounded DOM | lint, browser matrix |
| `wp-security-review` | Capabilities, nonces, escaping, SQL | No new endpoints/superglobals; escaping | security review of any new JS surface |
| `wp-testing` / `wp-write-in-process-test` | tests/bootstrap.php + assertions.php | Fail-closed gates; real numbers | `node --test tests/node/*.test.js` |
| `wp-update-docs` | README index + standard §9 | One owner per fact; evidence under `docs/evidence/` | docs index row |

## 4. Current-state findings

- Branch `commemorative-events`, HEAD `d7602d75`, clean worktree, 9 pre-existing stashes (all preserved).
- Christmas package: 3 placements (`homepage_hero`, `site_wide_ambient`, `landing_page`), assets enqueued only when a Christmas placement renders (`commemorative-presentations.php` asset map).
- `christmas.js`: `createWelcome()` (dialog `showModal()`, session dismissal, no timers), `createJourney()` (tabs, star travel on user selection), `createStarDecoration()` (ambient star via shared scheduler), `boot()`.
- Welcome star markup: `.ce-christmas-hero__star-svg` inside `.ce-xmas-welcome__dialog`, opened via `.is-welcome-open` class.
- CTA markup: `.ce-christmas-hero__cta` (welcome), `.ce-christmas-closing__cta` (landing).
- Landing-only markup: `.ce-christmas-journey`, `.ce-module`, `.ce-christmas-closing` (confirmed landing-page-only via grep).
- Existing ambient (`site_wide_ambient`): 'calm' energy, 1 star at a time, edges, session cap 3, pauses on hidden/reduced motion, removes nodes — already satisfies Animation 4's constraints.
- Baseline node tests: 38 pass (welcome 19, journey 8, ambient 11).

## 5. Proposed approach

**Animation 1 — Welcome star twinkle (CSS only).** Add `@keyframes ce-xmas-welcome-star-twinkle` (~1.2s, `animation-iteration-count: 1`) scoped to `.ce-xmas-welcome__dialog.is-welcome-open .ce-christmas-hero__star-svg`, animating a soft gold glow/drop-shadow. `open()` already adds `.is-welcome-open`, so the twinkle fires exactly when the modal opens, with no JS timer, no delay to `showModal()`, no flash. `prefers-reduced-motion: reduce` → `animation: none`.

**Animation 2 — Selected CTA sparkle (JS).** Delegated `click` listener on document. When the target is `.ce-christmas-hero__cta` or `.ce-christmas-closing__cta` (and not reduced motion, primary button, no modifier keys), create 3–5 `.ce-xmas-sparkle` particles positioned at the button's bottom edge (never over the label), animate `transform` + `opacity` 450–750ms, remove via one-shot `setTimeout`, cap at 6 particles total, per-button cooldown to prevent double bursts. `preventDefault`/`stopPropagation` are never called; navigation continues. Keyboard Enter/Space fires `click`, so feedback is equivalent. `aria-hidden="true"`, `pointer-events: none`.

**Animation 3 — Gentle content reveal (JS + IntersectionObserver).** On landing pages (detected via `.ce-christmas-journey` presence), add `.ce-xmas-reveal-ready` (`opacity: 0`, `translateY(10px)`) via JS to `.ce-christmas-journey`, `.ce-christmas-closing` and `.ce-module`; an `IntersectionObserver` adds `.ce-xmas-revealed` (`opacity: 1`, `translateY: 0`) once per target and unobserves. Already-visible targets reveal immediately. No JS → ready class never added → content fully visible. Reduced motion → transition suppressed, content revealed instantly. No `height`/`margin`/geometry animation.

**Animation 4 — Sparse ambient star glints.** No new code. The existing `site_wide_ambient` placement already runs `createStarDecoration()` through the shared scheduler: one shared star at the edges, max 1 at once, calm 2–4s glints, generous randomised pause, pauses on `document.hidden`/reduced motion, removes nodes. Documented as retained and verified against the brief.

**Animation 5 — Shared-star journey connection (JS + CSS).** When `.ce-christmas-journey` first reveals (same observer as Animation 3), add `.ce-xmas-journey-star--pulse` to `.ce-christmas-journey__star-svg`: a one-time ~1s glow pulse (`filter`/`opacity`, no `transform` so it cannot fight the tab travel), removed after one cycle. Once per page view. Does not touch the interactive control.

**Reduced motion:** CSS `@media (prefers-reduced-motion: reduce)` overrides + JS guard that skips sparkle/reveal/pulse animation (content still revealed instantly).

**Scope guard:** All new JS reads the module-level `win` (already guarded) and only runs where christmas.js is enqueued (Christmas routes). Selectors are Christmas-specific class names, so nothing fires on unrelated routes.

## 6. Files expected to change

| Path | Change | Why |
|---|---|---|
| `wp-content/themes/conexao-br-irlanda/assets/css/presentations/christmas.css` | Add twinkle, sparkle, reveal, pulse keyframes + reduced-motion overrides | Visual motion for animations 1, 2, 3, 5 |
| `wp-content/themes/conexao-br-irlanda/assets/js/presentations/christmas/christmas.js` | Add sparkle, reveal, pulse controllers + `bootMicroAnimations()` wired into `boot()` | Triggers/orchestration |
| `tests/node/christmas-micro-animations.test.js` | New Node runtime suite for sparkle/reveal/pulse | Prove behaviour by running it |
| `tests/scripts/verify-commemorative-runtime.py` | Register the new test file in `TEST_FILES` | Gate runs the new suite |
| `docs/reports/2026-11-08-christmas-micro-animations.md` | Completion report | Required close-out |
| `docs/README.md` | Add report + evidence index rows | Documentation convention |

## 7. Files explicitly expected NOT to change

- `wp-content/plugins/*` — no plugin changes; `plugins.json` untouched (no registry drift).
- `wp-content/themes/conexao-br-irlanda/inc/commemorative-presentations.php` — no new placement, asset or dispatch change.
- Template parts (`hero.php`, `journey.php`, `landing.php`, `star.php`, `ambient.php`) — no markup change; animations hook existing classes.
- `assets/images/presentations/christmas/*` — no new artwork; reuse existing star SVG.
- Halloween files — untouched.

## 8. Content / data impact

- No records created/updated/deleted. No DB writes. No portable identifiers needed.
- PT content is immutable — no text, slug, URL or CTA destination changes.

## 9. Route / HTTP impact

- No routes added/removed/changed. No redirects. No sitemap change. No new HTTP matrix rows (animation is client-side decoration on existing routes).

## 10. Polylang / English impact

- No translated records affected. PT immutable. No EN records created. Locale read only via existing `conexao_current_locale()` (unchanged). No cache-key change.

## 11. Security impact

- No capabilities, nonces, endpoints or SQL. New JS uses `getBoundingClientRect` and `createElement` only; no `innerHTML`, no user data, no superglobals. Decorative particles `aria-hidden`.

## 12. Performance impact

- No queries. No new asset enqueues (reuses christmas.js / christmas.css). No preloaded animation artwork. Particles capped at 6, removed after one cycle; one `setTimeout` per burst, cleared when done. One `IntersectionObserver`. No continuous `requestAnimationFrame`. No layout-affecting properties.

## 13. Production impact

| Question | Answer |
|---|---|
| Will anything be written to production? | no |
| Will a deploy, upload or activation happen? | no |
| Who performs it, and how? | n/a — production has no SSH/WP-CLI; not in scope |
| What is the verification of the production result? | n/a — local only, no production contact |

## 14. Test plan

- New Node runtime suite `tests/node/christmas-micro-animations.test.js` (CTA sparkle, reveal, pulse, reduced motion, no duplicate init, bounded cleanup), registered in `verify-commemorative-runtime.py`.
- Re-run existing `christmas-welcome.test.js`, `christmas-journey.test.js`, `commemorative-ambient.test.js` to confirm no regression (welcome must stay "no scheduler").
- PHP syntax (`php -l`) on changed theme files; `./scripts/lint.sh` if runnable.
- Existing in-process Christmas suites (`test-commemorative-christmas-welcome.php`, `test-commemorative-christmas-journey.php`, `test-commemorative-landing-page.php`) confirm markup unchanged.
- Pass = all node cases green, existing suites green, lint clean, failing-suite diff empty vs baseline.

## 15. Acceptance matrix plan

No new HTTP acceptance rows (client-side only). Existing commemorative acceptance matrix (`/natal/`, `/en/christmas/`, homepage) continues to cover the routes; animations add no server assertions.

## 16. Rollback plan

Revert the two theme asset files, delete the new test file, revert `verify-commemorative-runtime.py`. No content/DB state created, so no data reversal. Worktree returns to clean baseline.

## 17. Documentation plan

| Document | Change | Procedure moved to (skill) |
|---|---|---|
| `docs/reports/2026-11-08-christmas-micro-animations.md` | New completion report | n/a |
| `docs/README.md` | Report + evidence index rows | n/a |

No procedure moves out of a document.

## 18. Release implications

No component version bump. No artifact allowlist change (`plugins.json` untouched). No release in scope (local implementation only, no production activation).

## 19. Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| Doing too much (scope creep into unrelated CSS/JS refactor) | Medium | Keep changes to the two Christmas asset files; no general-purpose animation system |
| Welcome twinkle adding a timer that breaks the "no scheduler" guarantee | Low | CSS-only twinkle; no JS timer in `open()` |
| CTA sparkle delaying navigation | Low | Never call `preventDefault`; fire-and-forget particles |
| Reveal hiding content if JS is slow | Low | Ready class added only by JS; no-JS = fully visible; already-visible targets reveal immediately |

## 20. Verification gates

1. `node --test tests/node/christmas-micro-animations.test.js` + existing Christmas node suites — all pass.
2. `php -l` on changed theme files; `./scripts/lint.sh` — clean.
3. Existing in-process Christmas suites green (markup contract unchanged).
4. `./scripts/run-tests.sh --scripts` — script gate green (no `plugins.json`/script changes).
5. Baseline failing-suite diff empty vs pre-change run.

## 21. Completion criteria

- Five animation ideas assessed against actual markup; only suitable effects implemented (4 implemented, 1 retained as existing ambient).
- Coherent shared-star design; existing modal, image, message, star, CTA and landing content intact.
- Effects subtle, short, bounded, one-shot, never blocking interaction.
- Reduced motion, keyboard, no-JS, preview behaviour preserved.
- PT/EN routes work; unrelated pages unaffected; no layout/perf regression.
- Tests and checks pass or limitations documented; worktree and repo state preserved; report complete.







