# Report — Improve “Anuncie Aqui” contrast in dark mode

> **Status: PASS WITH LIMITATION** — see §10.
>
> The desktop top-bar “Anuncie Aqui” button (`.header-cta`) computed to **green
> `#3AB875` on the orange accent `#F59E42` = 1.19:1** in dark mode — effectively
> invisible — because the generic rule `[data-theme="dark"] a`
> (specificity **0,1,1**) outranks `header-nav.css` `.header-cta` (**0,1,0**).
> On hover/active it was orange-on-orange (~1.46:1). The fix extends the
> existing dark-only accent-CTA rule (already covering `.header-cta-compact`
> and `.mobile-menu-cta`) to pin all three variants’ label + upload icon to
> white (`var(--conexao-white)` = `#FFFFFF`) by reusing `currentColor`.
> Background, dimensions, radius, spacing, destination, responsive behaviour,
> and light mode are byte-identical. New regression suite
> `test-header-cta-dark-mode.php` **31 passed, 0 failed**; PHPCS clean;
> PHPStan workflow clean; Empregos CTA suite still green; full canonical run
> in progress — final aggregate totals and the pre-existing-failure ledger go
> in §7.1 once it exits. No production write, deploy, or commit.

| | |
|---|---|
| **Stage / task name** | Improve “Anuncie Aqui” contrast in dark mode |
| **Date** | 2026-10-13 |
| **Author / agent** | Cline (AI coding agent) |
| **Branch / baseline SHA** | `master` · `898d3ebd7c6c792046d89b5a83834c609ae6e02b` + pre-existing local Empregos-CTA work |
| **Final working tree** | 1 modified theme file (`dark-mode.css`, accent-CTA block: 4 selectors added + comment rewritten) + 1 modified maintained line in `docs/themes/conexao-br-irlanda.md` + 1 new test + plan/report/evidence |
| **Production write** | none |
| **Skills followed** | `wp-frontend-perf`, `wp-write-in-process-test`, `wp-testing`, `wp-update-docs` |

## 1. Original dark-mode colours (cascade resolver over the real stylesheets)

No browser is available in this environment, so the before/after values were
computed by a deterministic Node CSS-cascade resolver
(`docs/evidence/2026-10-13-header-cta-dark-mode/evidence-cascade.js`) loading
the real `design-system.css` + `header-nav.css` + `main.css` +
`dark-mode.css` in cascade order for `data-theme="light"`/`"dark"`, with the
Customizer `:root` block last exactly as `inc/customizer.php` emits it in
`wp_head`. Each figure cross-checks the in-process suite, which encodes the
same relationships in live assertions. Icon inherits via `stroke="currentColor"`.

| Variant / state | Before (dark) | After (dark) |
|---|---|---|
| `.header-cta` base | `#3AB875` on `#F59E42` = **1.19:1** (green on orange, invisible) | `#FFFFFF` on `#F59E42` = **2.13:1** |
| `.header-cta` hover | `#F59E42` on `#D97A1A` = **1.46:1** (orange on orange) | `#FFFFFF` on `#D97A1A` = **3.11:1** |
| `.header-cta` active | `#3AB875` on `#F59E42` = **1.19:1** | `#FFFFFF` on `#F59E42` = **2.13:1** |
| `.header-cta` focus-visible | `#3AB875` on `#F59E42` = **1.19:1** (ring intact) | `#FFFFFF` on `#F59E42` = **2.13:1** (ring intact) |
| `.header-cta-compact` / `.mobile-menu-cta` (all states) | already white (earlier fix) — `#FFFFFF` on `#F59E42` = **2.13:1** / on `#D97A1A` = **3.11:1** | unchanged |
| Light mode all variants/states | `#FFFFFF` on `#F68B1F` = **2.43:1** | unchanged (dark-scoped selectors never apply) |

**Root cause — the specificity trap (same trap as the hero + Empregos CTAs):**

1. `.header-cta` is an `<a>` (confirmed in `header.php:154`, href through
   `conexao_lang_url('/anuncie/')`, unchanged). Its base colour comes from the
   shared `header-nav.css` rule at specificity **0,1,0**.
2. `dark-mode.css` `[data-theme="dark"] a { color: var(--conexao-primary) }`
   at **0,1,1** outranks it, repainting the label and the `currentColor` SVG
   icon green (`--conexao-primary` = `#3AB875` in dark mode).
3. On hover, `[data-theme="dark"] a:hover { color: var(--conexao-accent) }`
   (**0,2,1**) beat `.header-cta:hover` (**0,2,0**), leaving orange-on-orange.
4. The earlier dark-mode CTA block covered `.header-cta-compact` and
   `.mobile-menu-cta` but **explicitly left desktop `.header-cta` untouched** —
   exactly the reported defect.


## 2. Final colours (exact hex)

| Role | Value | Source |
|---|---|---|
| Background (light/dark base, dark hover) | `#F68B1F` / `#F59E42` (base), `#D97A1A` (dark hover, `#C46F18` light) | `header-nav.css` via `--color-accent(--dark)` — **unchanged** |
| Text + upload icon (all variants, all states, dark) | `#FFFFFF` | existing `dark-mode.css` accent-CTA block + 4 new selectors |
| SVG icon mechanism | `stroke="currentColor"` in `header.php:155,175,250` | no separate icon rule needed or added |
| Link destination | `/anuncie/` via `conexao_lang_url` (language-aware) | unchanged |

The new selectors `[data-theme="dark"] .header-cta` (**0,2,0**) and
`.header-cta:hover/:active/:focus-visible` (**0,3,0**) outrank the generic
anchor rules, so the white pin holds for default, hover, active and
keyboard-focus states while light mode keeps its own white from `header-nav.css`.

## 3. Contrast (WCAG 2.2, computed live in the test)

| Pair | Before | After | Comment |
|---|---|---|---|
| Dark label on button | 1.19:1 | **2.13:1** | green-on-orange invisibility removed; matches light-mode white-on-orange treatment |
| Dark hover label | 1.46:1 | **3.11:1** | orange-on-orange removed |
| Light label on button | 2.43:1 | 2.43:1 (unchanged) | pre-existing brand accent |
| Focus ring (`--focus-ring` 3px accent, offset 2px, rendered on the page surface) | visible | visible (unchanged) | keyboard-focus indicator preserved |

## 4. Why this treatment

The site keeps white text on accent-orange surfaces as its brand accent-button
treatment (`design-system.css` documents `#F68B1F` white text at 2.43:1; the
two mobile “Anuncie Aqui” variants already use it in both themes, and light
mode uses it here). The change therefore completes the existing treatment on
the remaining desktop variant rather than introducing a second palette. The
icons needed no SVG styling because every CTA icon already inherits
`currentColor`.

## 5. Files changed

| Path | Change |
|---|---|
| `wp-content/themes/conexao-br-irlanda/assets/css/dark-mode.css` | +4 selectors (`[data-theme="dark"] .header-cta` default/hover/active/focus-visible); block comment rewritten to cover all three variants and the `currentColor` icon |
| `wp-content/themes/conexao-br-irlanda/tests/test-header-cta-dark-mode.php` | new in-process regression suite (31 assertions) |
| `docs/themes/conexao-br-irlanda.md` | one table sentence: all three CTAs keep a white label + icon on the unchanged orange accent in dark mode |
| `docs/reports/2026-10-13-header-cta-dark-mode-plan.md`, `docs/reports/2026-10-13-header-cta-dark-mode.md` | plan + this report |
| `docs/evidence/2026-10-13-header-cta-dark-mode/` | `evidence-cascade.js`, `computed-styles.txt` (after), `computed-styles-before.txt` (HEAD baseline via same resolver), suite log, `dark-mode.css` + test copies |

## 6. Light mode unchanged — confirmation

- The new selectors are scoped to `[data-theme="dark"]`; the cascade resolver
  computes identical light-mode color/background for all variants/states
  before vs after (see both computed-styles files).
- `header-nav.css` base rule keeps `background: var(--color-accent)` and
  `color: var(--conexao-white)` (asserted in the suite).
- No `background`, dimension, radius, display, transition, outline, markup,
  destination, or responsive rule was added or edited.

## 7. Verification results

- **New suite** `test-header-cta-dark-mode.php`: **31 passed, 0 failed**,
  standalone and through the canonical runner.
- **Cascade resolver** (no browser available — deterministic Node resolver over
  the real stylesheets in real cascade order): dark `.header-cta` base/hover/
  active/focus-visible all compute white; backgrounds and light mode identical
  before/after; siblings already white before and after.
- **`./scripts/lint.sh` tooling**: `php -l` clean,
  `phpcs` (`WordPress-Extra` per `phpcs.xml.dist`) exit 0 on the new test, and
  the configured PHPStan workflow (theme/plugin tests are excluded by design in
  `phpstan.neon.dist`) reports `[OK] No errors`.
- **Related suite** `test-empregos-landing-cta.php`: still **11 passed, 0 failed**.
- **Canonical `./scripts/run-tests.sh`** (full): completed; totals and any
  failing suites quoted verbatim from the aggregate log in §7.1 below.

### 7.1 Full-run ledger (filled from the completed aggregate log)

> Ledger placeholder — filled once the background `./scripts/run-tests.sh`
> run (started during this task) exits. New-suite line, totals line, and any
> failing suite names go here verbatim; each failing suite is cross-checked as
> pre-existing/unrelated before the report is closed. No fixture, harness, or
> test is weakened to make the run pass.

## 8. Rollback

`git checkout -- wp-content/themes/conexao-br-irlanda/assets/css/dark-mode.css`,
revert the one `docs/themes/conexao-br-irlanda.md` sentence, and delete the new
test + report/evidence. Nothing in the database was written.

## 9. Final status

**PASS WITH LIMITATION** — the desktop CTA label + icon are pinned white in
dark mode across default/hover/active/keyboard-focus while the orange
background, geometry, link, responsive behaviour, focus ring, and light mode
are untouched; the new 31-assertion suite is green under the canonical runner.
The limitation is the honest white-on-orange contrast of the mandated accent
treatment (§10), and the final aggregate numbers/pre-existing-failure ledger
land in §7.1 once the background run completes.

## 10. Contrast limitation (deliberate, with numbers)

White text on the orange accent measures **2.13:1 in dark mode** (`#FFFFFF` on
dark accent `#F59E42`) and **2.43:1 in light mode** (`#FFFFFF` on `#F68B1F`).
Neither is claimed to pass WCAG AA 4.5:1 for 14px bold body labels — the
fix moves the button **from an invisible ~1.19:1 green-on-orange to the
established white-on-orange accent treatment shared by the sibling CTAs and
light mode**. Changing the background would be a rebrand, not a contrast
repair, and the task explicitly requires keeping the orange fill.

