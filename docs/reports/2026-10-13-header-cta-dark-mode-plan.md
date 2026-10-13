# Plan — Improve “Anuncie Aqui” contrast in dark mode

**Date:** 2026-10-13  
**Branch:** `master`  
**Baseline:** `898d3ebd7c6c792046d89b5a83834c609ae6e02b` with pre-existing local work for the Empregos CTA task: uncommitted `dark-mode.css` changes and untracked test/report/evidence files.  
**Applicable skills:** `wp-frontend-perf`, `wp-write-in-process-test`, `wp-testing`, `wp-update-docs`  
**Plan status:** Approved — task statement authorises the targeted CSS/test change only.

## 1. Scope

Improve the header “Anuncie Aqui” button in dark mode by changing only its text/icon colour. The orange background remains unchanged. The behaviour, dimensions, rounded pill, spacing, link destination, and responsive layout remain unchanged.

## 2. Explicit non-goals

- No change to link destination, accessible labels, HTML, SVG markup, button size, border radius, padding, breakpoints, hover lift/shadow, or light-mode appearance.
- No new colour family or design-system token.
- No change to global anchors, buttons, hero CTAs, or page-specific CTAs.
- No production write, deploy, commit, upload, activation, or external-repository work.

## 3. Findings

The desktop top-bar `.header-cta` is an `<a>`:

- Base rule in `header-nav.css`: orange `background: var(--color-accent)` and `color: var(--conexao-white)`, specificity `(0,1,0)`.
- Generic dark-mode anchor rule: `[data-theme="dark"] a { color: var(--conexao-primary); }`, specificity `(0,1,1)`.
- The generic anchor rule therefore outranks `.header-cta` in dark mode.
- The existing dark-mode block already pinned white text for `.mobile-menu-cta` and `.header-cta-compact`, but not for desktop `.header-cta`.
- All three CTA SVGs use `stroke="currentColor"`, so correcting the anchor `color` also corrects the icon.
- The orange background comes from non-dark `header-nav.css` rules and must remain unchanged.
- Light mode uses the non-dark `.header-cta` rule, so dark-only `[data-theme="dark"]` selectors do not affect it.
- Keyboard-focus outline comes from the existing focus-visible rule and must remain available.

Representative before/after cascade results from actual stylesheets:

| Theme / state | Before | After |
|---|---|---|
| Dark default `.header-cta` | `#3ab875` on orange `#f59e42` = 1.19:1 | `#ffffff` on `#f59e42` = 2.13:1 |
| Dark hover `.header-cta` | orange `#f59e42` on `#d97a1a` = 1.46:1 | `#ffffff` on `#d97a1a` = 3.11:1 |
| Light default | `#ffffff` on `#F68B1F` = 2.43:1 | unchanged |

## 4. Change

1. Extend the existing dark-only accent-CTA selector block in `dark-mode.css` with:
   - `[data-theme="dark"] .header-cta`
   - `[data-theme="dark"] .header-cta:hover`
   - `[data-theme="dark"] .header-cta:active`
   - `[data-theme="dark"] .header-cta:focus-visible`
2. Keep the existing compact and drawer selectors.
3. Keep only `color: var(--conexao-white);` in that block.
4. Do not add `background`, dimensions, radius, display, transitions, outline, markup, destination, or light-mode rules.
5. Update the nearby comment to state that the desktop, compact, and drawer variants are covered.

## 5. Files

| Path | Change |
|---|---|
| `wp-content/themes/conexao-br-irlanda/assets/css/dark-mode.css` | Extend existing dark CTA block with desktop `.header-cta` default/hover/active/focus-visible |
| `wp-content/themes/conexao-br-irlanda/tests/test-header-cta-dark-mode.php` | New in-process regression suite |
| `docs/themes/conexao-br-irlanda.md` | One-sentence clarification that all three CTAs keep white label/icon on orange in dark mode |
| `docs/evidence/2026-10-13-header-cta-dark-mode/` | Real-CSS cascade resolver, before/after computed styles, suite log |
| `docs/reports/2026-10-13-header-cta-dark-mode-plan.md` | This plan |
| `docs/reports/2026-10-13-header-cta-dark-mode.md` | Final report |

## 6. Verification

1. PHP syntax check on the new test.
2. New test standalone: `test-header-cta-dark-mode` passes.
3. New suite is discovered by `./scripts/run-tests.sh --list`.
4. Existing related suite `test-empregos-landing-cta.php` still passes.
5. PHPCS on the new test file clean.
6. PHPStan config explicitly excludes theme/plugin tests; run the configured PHPStan workflow.
7. Run canonical `./scripts/run-tests.sh`; report the new suite result and distinguish pre-existing failures from this change.
8. Cascade resolver before/after confirms dark text/icon become white, orange background unchanged, and light mode identical.

## 7. Rollback

Revert `dark-mode.css`, delete the new test and the `2026-10-13-header-cta-dark-mode` report/evidence/docs sentence. No database, content, production, or deployment action is involved.
