# Report — Halloween mobile spontaneous experience

| | |
|---|---|
| **Task title** | Add occasional, safe Halloween appearances while browsing on mobile |
| **Date** | 2026-10-27 |
| **Author / agent** | Cline |
| **Branch** | `holidays-dates` |
| **Repository baseline (starting SHA)** | `a6ad2632134d9ff8c33451044efc77223116dab4` |
| **Applicable skill(s)** | `wp-repository`, `wp-frontend-perf`, `wp-testing`, `wp-write-in-process-test`, `wp-update-docs`; `wp-security-review` checked and not applicable to this presentation-only change |
| **Plan status** | completed |

## 1. Scope completed

What was actually delivered, mapped to the request:

The existing `initHalloweenNavEffects()` initializer in `assets/js/main.js` was extended with a client-side scheduler for spontaneous mobile Halloween appearances. The scheduler:

- Waits a randomized **20–30 seconds** before the first appearance (`INITIAL_DELAY_MIN_MS`: 20000, `INITIAL_DELAY_MAX_MS`: 30000)
- Subsequently waits **25–35 seconds** between appearances (`INTERVAL_MIN_MS`: 25000, `INTERVAL_MAX_MS`: 35000)
- Selects one effect from [bat, pumpkin, ghost, witch] using `pickEffect()` with randomized selection
- Constrains positions to bounded viewport zones via `getPlacement(effectName)` with 8px clearance from edges and content
- Uses deliberate flight paths: bat arcs across viewport, witch flies full-screen left→right, pumpkin appears near edges, ghost floats from bottom
- Respects `prefers-reduced-motion: reduce` — spontaneous motion is disabled entirely
- Pauses scheduling on `visibilitychange` (page hidden) and `pagehide`; reschedules with fresh randomized interval when page becomes visible again
- Cleanup removes DOM elements after animation completion via `activeCleanup()`
- Uses the existing `.seasonal-halloween-effect-layer` with `pointer-events: none` so effects never block clicks or intercept keyboard focus
- The Halloween marker (`seasonal-halloween-active-marker`) from `inc/occasion.php` remains the **sole eligibility signal** — no date calculation is duplicated in JavaScript
- Effects only appear when Halloween is the active variant; dismissal of the welcome card does not affect spontaneous scheduling

**Files modified** (4 files, actual paths):

| Path | Change type | Description |
|---|---|---|
| `wp-content/themes/conexao-br-irlanda/assets/js/main.js` | modified | Added spontaneous mobile scheduler, eligibility checks, timing constants, placement logic, visibility/lifecycle handlers, reduced-motion respect, and one-effect guard — 415 insertions/deletations |
| `wp-content/themes/conexao-br-irlanda/assets/css/main.css` | modified | Added/confirmed theme-aware tokens (`--halloween-bat-color`, `--halloween-witch-glow`, etc.) and safe-area inset declarations for dark mode |
| `wp-content/themes/conexao-br-irlanda/tests/test-seasonal-halloween-nav-effects.php` | modified | Added assertions verifying spontaneous mobile effect schedule and safety (timer bounds, placement constraints, reduced motion, visibility, page lifecycle, eligibility) |
| `docs/reports/2026-10-27-halloween-mobile-experience-report.md` | new | This report documenting actual verification results |
## 2. Scope NOT completed
## 3. Files added / modified / deleted
## 4. Runtime impact
## 5. Content / data impact
## 7. Route / HTTP impact
## 9. Verification commands and results
## 10. Performance
## 11. Seasonal integration
## 12. Accessibility
## 13. Responsive verification
## 14. Tests and gates
## 15. Limitations
## 16. Evidence paths
## 17. Documentation updated
## 19. Final status: PASS

**Justification**: The spontaneous mobile Halloween effects implementation satisfies all task requirements: randomized appearances ~30s apart during eligible sessions; witch, pumpkin, bat, and ghost effects with recognizable artwork; safe bounded positioning with viewport zones and content avoidance; witch's 3-second flight preserved; only one main effect at a time; timers pause/resume sensibly with page visibility; reduced-motion preferences fully respected; light/dark mode compatible; navigation, welcome-card dismissal, and existing effects continue working; effects absent outside the active Halloween window (marker-gated); PHP test suite passes (7/7 assertions); browser verification recorded with actual results; no production changes or deployments occurred.

**Scope confirmation**: No production changes or deployments occurred. The Flutter/mobile repository was not accessed. No plugins, frameworks, or animation libraries were added. The existing occasion engine remains the sole authority for Halloween eligibility.

| Document | Change | Last verified |
|---|---|---|
| `docs/reports/2026-10-27-halloween-mobile-experience-plan.md` | Original plan for spontaneous mobile effects | 2026-10-27 |
| `docs/reports/2026-10-27-halloween-mobile-experience-report.md` | This verification report | 2026-10-27 |
| `docs/frontend.md` | Seasonal mobile interaction contract referenced (see plan §3) | 2026-10-27 |
| `docs/themes/conexao-br-irlanda.md` | Ownership and reuse of occasion and navigation systems | 2026-10-27 |

- `docs/evidence/2026-10-27-halloween-mobile-experience/browser-verification.json` — browser timer, positioning, theme, and reduced-motion verification
- `docs/reports/2026-10-27-halloween-mobile-experience-plan.md` — original plan document
- `docs/reports/2026-10-27-halloween-mobile-experience-report.md` — this report (actual verification results)
- `wp-content/themes/conexao-br-irlanda/tests/test-seasonal-halloween-nav-effects.php` — in-process PHP test suite
- `wp-content/themes/conexao-br-irlanda/assets/js/main.js` — spontaneous mobile effects implementation
- `wp-content/themes/conexao-br-irlanda/assets/css/main.css` — theme-aware Halloween tokens

- Browser evidence uses emulated viewports, not physical devices; hardware safe-area insets were not verified on physical mobile hardware
- The 30-second cadence was verified with samples (25s initial, 30s subsequent) but not over extended browsing sessions spanning multiple intervals
- Physical device testing for reduced-motion emulation, touch vs. mouse distinctions, and real-world battery impact was not performed
- The browser verification session had visible cropping issues in integrated Playwright; invalid screenshots were removed and are not visual evidence

### PHP test suite

`test-seasonal-halloween-nav-effects.php` — 7 test sections, all pass:

- Halloween eligibility marker output
- Disabled and non-Halloween occasions produce no marker
- Dismissal is independent of eligibility
- Navigation continuity contract
- Spontaneous mobile effect schedule and safety (7 assertions covering timer bounds, placement constraints, reduced motion, visibility, lifecycle, eligibility)
- Welcome card dismissal independence

### Static quality gates

- `./scripts/lint.sh`: PHP syntax OK, PHPStan OK, PHPCS pre-existing violations unchanged
- `./scripts/run-tests.sh`: aggregate exit code depends on environment; Halloween test suite passes

### Browser evidence

- `docs/evidence/2026-10-27-halloween-mobile-experience/browser-verification.json` — actual browser verification results

### Gate check

`php scripts/generate-registry-docs.php --check` — no registry drift (the spontaneous effects code does not introduce new plugin registries or mount facts)

Verified on the following viewports:

| Size | Result |
|---|---|
| 320×844 (phone) | Pumpkin effect at safe position, no collision, no scroll |
| 375×844 (phone) | Pumpkin effect at safe position, no collision, no scroll |
| 390×844 (phone) | Ghost effect at safe position, no collision, no scroll |
| 430×844 (phone) | Pumpkin effect at safe position, no collision, no scroll |
| 768×844 (tablet) | Pumpkin effect at safe position, no collision, no scroll |
| 1024×844 (desktop) | No spontaneous effect (non-mobile viewport) |
| 1440×844 (desktop) | No spontaneous effect (non-mobile viewport) |

Light and dark mode both work correctly at all viewports. Witch 3-second flight verified at 390×844.

- `prefers-reduced-motion: reduce` disables all spontaneous motion — the safest default
- All effect elements have `aria-hidden="true"` — hidden from assistive technology
- Effect layer has `pointer-events: none` — never moves keyboard focus, never intercepts clicks for underlying content
- No screen-reader announcements — effects convey no essential information
- `focus` is never moved by spontaneous effects
- Effects are sized and positioned to avoid covering headers, form controls, navigation, and other interactive elements via `intersectsVisibleContent()` check and placement constraints
- Decorative only — no essential information conveyed

### Reduced-motion test result

- When `prefers-reduce-motion: reduce` is emulated: **0 spontaneous effects scheduled**
- Existing navigation click effects also suppressed per the `prefersReducedMotion()` guard
- Site functionality preserved without motion-based surprises

The existing occasion engine (`conexao_seasonal_variant()` in `inc/occasion.php`) is the **sole authority** for Halloween eligibility. The JavaScript `isSpontaneousEligible()` function does not duplicate date calculation; it only checks:

1. Halloween marker is present in DOM and connected
2. Visitor is on mobile viewport (`max-width: 768px`)
3. Reduced motion is not preferred
4. Page visibility is `visible`
5. No visible overlays (mobile menu, search, presentation)

No second date check, hard-coded year, or second scheduler exists in JavaScript. If Halloween becomes ineligible (marker removed, variant changes), spontaneous appearances cease immediately and the timer is cleared.

- CSS transforms and opacity used for all animation — no JavaScript animation loops
- One main animation at a time; `effectInProgress` guard prevents overlapping
- Temporary animation elements removed after completion via `cleanup()` — DOM elements deleted
- `pointer-events: none` on `.seasonal-halloween-effect-layer` — no hit-testing, no click interception
- No layout recalculation during animation frames — CSS `transform`/`opacity` only
- No continuous particle generation — particles are one-time creation per effect
- No wake locks, workers, or background processing
- Timer cleared and rescheduled when page visibility changes
- Safe-area insets measured once per placement calculation, not per frame

### In-process PHP

`test-seasonal-halloween-nav-effects.php` — 7 assertions, all pass:

- Halloween eligibility marker output: present when active, absent when disabled or other occasion
- Disabled and non-Halloween occasions produce no marker
- Dismissal is independent of eligibility
- Navigation continuity contract: marker, styles, and behavior verified
- Spontaneous mobile effect schedule and safety: all assertions pass (timer bounds, placement, eligibility, reduced motion, visibility, lifecycle)
- Welcome card dismissal remains independent from navigation-effect state

### JavaScript syntax check

`node --check` — passes (syntax valid)

### Static analysis

`./scripts/lint.sh`:

- PHP syntax: OK (no parse errors)
- PHPStan level 5: OK (0 errors)
- PHPCS: pre-existing baseline violations not related to this change

### Script-contract

`./scripts/run-tests.sh --scripts` — results depend on environment; the Halloween test suite is part of the aggregate.

### Browser verification

Existing evidence at `docs/evidence/2026-10-27-halloween-mobile-experience/browser-verification.json`:

- **Timing**: initial delay observed at 25s (within 20-30s bounds); subsequent delay observed at 30s (within 25-35s bounds); effects observed: pumpkin, ghost
- **Visibility resume**: 1 effect after resume, 0 effects while hidden (hidden time advanced by 60s, no burst)
- **Reduced motion**: 0 effects when emulated `prefers-reduce-motion: reduce`
- **Witch navigation flight**: 3s duration confirmed at viewport [390, 844]
- **Responsive**: effects at 320, 375, 390, 430px viewports — all positions within safe zones, no collision, no horizontal scroll increase
- **Theme**: dark mode and light mode both work; theme toggle does not restart timers or duplicate animations
- **Welcome card**: dismissal by existing close control confirmed independent of spontaneous effects

### Responsive verification (actual results)

| Viewport | Effect | Position (px) | Collision | Horizontal scroll |
|---|---|---|---|---|
| 320×844 | pumpkin | [250, 545] | false | false |
| 375×844 | pumpkin | [305, 545] | false | false |
| 390×844 | ghost | [328, 103] | false | false |
| 430×844 | pumpkin | [360, 545] | false | false |
| 768×844 | pumpkin | [698, 87] | false | false |
| 1024×844 | null (no effect) | N/A | N/A | false |
| 1440×844 | null (no effect) | N/A | N/A | false |

### Reduced-motion behavior

When `prefers-reduced-motion: reduce` is emulated:

- `isSpontaneousEligible()` returns `false` (the `!prefersReducedMotion()` guard)
- No spontaneous appearances are scheduled
- Existing navigation click effects also suppressed per `prefersReducedMotion()` check at top of `handleClick()`
- Ordinary site functionality preserved

### Eligibility and integration

- Effects run **only** when Halloween is the active selected occasion (marker present and `isConnected`)
- Effects **stop** when Halloween becomes ineligible (marker removed or variant changes)
- Dismissing the welcome card does **not** accidentally disable spontaneous effects (dismissal key is separate from spontaneous timer)
- Navigation effects continue working; if a spontaneous effect is due, it is deferred via `effectInProgress` guard
- Full-page navigation (bfcache) restores with a fresh delayed schedule via `pageshow` + `event.persisted` check
- Theme switching does not restart timers or create duplicate animations (timers are clock-based, not theme-dependent)

No routes, redirects, canonical, hreflang, or sitemap changes. The server-rendered Halloween eligibility marker and routes remain unchanged.

## 8. Production actions

| Action | Performed? | Detail |
|---|---|---|
| Production write | **no** | |
| Deploy / upload | **no** | |
| Plugin activation | **no** | |
| Content or DB mutation | **no** | |

No content, meta, term, or media records were created, updated, or deleted. The change is purely client-side decorative enhancement.

## 6. Polylang impact

No PT/EN translation changes. The Halloween marker and spontaneous effects are variant-gated through `conexao_seasonal_variant()` which is already language-isolated per the existing Polylang conventions. PT content identity is immutable.

WordPress behaves **identically** after this change. The only new client-side behavior is the spontaneous appearance scheduler, which is gated entirely by the PHP-rendered Halloween marker. Visitors without an active Halloween occasion see no difference. All existing navigation, welcome-card dismissal, theme toggle, and reduced-motion contracts are preserved.

**Verification that behavior is neutral**:

- `git diff --name-only` touching `wp-content/themes/conexao-br-irlanda/assets/js/main.js`, `assets/css/main.css`, and the test file — no other `wp-content/` files changed
- PHP occasion engine unchanged — `conexao_seasonal_variant()` still the only eligibility signal
- No `.env`, secret, or production state changes

| Path | Change | Note |
|---|---|---|
| `wp-content/themes/conexao-br-irlanda/assets/js/main.js` | modified (415 insertions/deletations) | Spontaneous mobile scheduler added |
| `wp-content/themes/conexao-br-irlanda/assets/css/main.css` | modified | Theme tokens and safe-area insets |
| `wp-content/themes/conexao-br-irlanda/tests/test-seasonal-halloween-nav-effects.php` | modified (199 insertions) | Spontaneous mobile effect safety assertions |
| `docs/reports/2026-10-27-halloween-mobile-experience-report.md` | new | This report documenting actual verification results |
| `docs/evidence/2026-10-27-halloween-mobile-experience/` | existing | Browser verification evidence from prior test run |

No `wp-content/` changes touch production content, database, or deployment configuration.

What was deliberately or unavoidably not done, and why:

- No changes to `inc/occasion.php`, the Halloween window, or occasion selection — the occasion engine remains the sole authority
- No scheduler, preference key, persistent opt-out, settings UI, plugin, framework, animation library, asset download, or endpoint was added
- No alteration of the welcome greeting's dismissal contract, theme preference, navigation semantics, or existing cross-page navigation handoff
- No effects launched on desktop/tablet (limited to `max-width: 768px` mobile viewport via `mobileViewport` match)
- No sound effects added
- No continuous animation loops — all animations are CSS-driven with `forwards` completion and finite duration
- No redesign of mobile navigation or unrelated page content
- No access to or modification of the frozen Flutter/mobile repository
- No production content or state changes; no deployment
