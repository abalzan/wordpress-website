# Report — Dark-mode appearance of the Empregos landing CTA

> **Status: PASS**
>
> The `.empregos-landing-cta .btn.btn-primary` CTA on `/empregos/` looked broken
> in dark mode because its label and arrow computed to **green `#3AB875` on the
> orange accent `#F59E42` = 1.19:1** — effectively invisible. The fix re-points
> the dark-mode surface to a deepened brand green `#287443` with white text
> (**5.72:1**, PASS) and hover `#1F5E2F` (**11.76:1**, PASS). Light mode is
> byte-identical before/after (identical screenshot hashes). New regression
> suite `test-empregos-landing-cta.php` **11 passed, 0 failed**; lint **OK**;
> the canonical `./scripts/run-tests.sh` completed with my suite green and every
> failure pre-existing (reproduced unrelated to this change). No production
> write, deploy or commit.

| | |
|---|---|
| **Stage / task name** | Dark-mode appearance of `.empregos-landing-cta .btn.btn-primary` |
| **Date** | 2026-10-12 |
| **Author / agent** | Cline (AI coding agent) |
| **Branch / baseline SHA** | `master` · `898d3ebd7c6c792046d89b5a83834c609ae6e02b` (clean tree) |
| **Final working tree** | 1 modified file (`dark-mode.css`, +31) + 1 new test + plan/report/evidence |
| **Production write** | none |
| **Skills followed** | `wp-frontend-perf`, `wp-write-in-process-test`, `wp-testing`, `wp-update-docs` |

## 1. Original dark-mode colours (measured in Chromium)

| Property | Before (dark) | Value |
|---|---|---|
| Background | `rgb(245,158,66)` | `#F59E42` — the bright orange accent (no dark rule existed) |
| Label + SVG arrow | `rgb(58,184,117)` | `#3AB875` — **green**, not white |
| **Text contrast on button** | **1.19:1** | FAIL (green-on-orange, effectively invisible) |
| Button on page | 8.47:1 | — |

**Root cause — two compounding defects:**
1. `.empregos-landing-cta .btn.btn-primary` had no dark-mode rule, so it kept
   the bright orange accent `#F59E42` — over-saturated for the dark interface.
2. The generic anchor rule `[data-theme="dark"] a { color: var(--conexao-primary) }`
   (specificity **0,1,1**) outranks `.btn-primary { color: var(--conexao-white) }`
   (**0,1,0**), so the label and arrow resolved to the dark green `#3AB875`.

## 2. Selected replacement colours (exact hex)

| Token | Value | Use | White-text contrast |
|---|---|---|---|
| `--conexao-cta-bg` | `#287443` | dark-mode default surface | **5.72:1** (PASS ≥ 4.5:1) |
| `--conexao-cta-bg-dark` | `#1F5E2F` | dark-mode hover surface | **11.76:1** (PASS) |
| `--conexao-white` (existing) | `#FFFFFF` | label + SVG arrow (`currentColor`) | — |

The new selector `[data-theme="dark"] .empregos-landing-cta .btn.btn-primary`
(**0,4,0**) also outranks the generic anchor rule, so the text and arrow render
white. Contrast for the non-text button surface against the page (`#0F1720`)
is **3.15:1** (PASS ≥ 3:1 for UI components).

## 3. Contrast ratios (WCAG 2.2 AA)

| Pair | Before | After | Requirement | Result |
|---|---|---|---|---|
| Label on button (dark) | 1.19:1 | **5.72:1** | ≥ 4.5:1 | FAIL → PASS |
| Hover label on button (dark) | (green on `#d97a1a`) | **7.78:1** | ≥ 4.5:1 | PASS |
| Button surface on page (dark) | 8.47:1 | **3.15:1** | ≥ 3:1 (UI) | PASS |
| Focus ring (`--focus-ring` `#F59E42`) on surface | — | 3px solid | visible | PASS |
| Light-mode label on button | 2.43:1 | 2.43:1 (unchanged) | pre-existing brand accent | unchanged |

## 4. Why this palette improves the result

The site's palette has **no blue**; the brand green is the established dominant
action colour and the natural, brand-compatible primary that integrates with the
dark interface. The bright orange was both over-saturated for a dark surface and
(more seriously) was being painted with **green text** by a higher-specificity
anchor rule. Using the deepened brand green with white text removes the
green-on-orange failure, reads as a calm, integrated primary action rather than a
neon accent, and requires **no new colour family** — only two tone tokens in the
existing `:root[data-theme="dark"]` architecture.

## 5. Files changed

| Path | Change |
|---|---|
| `wp-content/themes/conexao-br-irlanda/assets/css/dark-mode.css` | +2 tokens (`--conexao-cta-bg`, `--conexao-cta-bg-dark`) in the `:root[data-theme="dark"]` block; +2 scoped button rules in §33 |
| `wp-content/themes/conexao-br-irlanda/tests/test-empregos-landing-cta.php` | new in-process regression suite (11 assertions) |
| `docs/reports/2026-10-12-empregos-cta-dark-mode-plan.md` | plan |
| `docs/evidence/2026-10-12-empregos-cta-dark-mode/` | computed-styles.txt + before/after light/dark/hover/focus screenshots + the render harness (real CSS + exact markup) |

## 6. Light mode unchanged — confirmation

Before/after **light-mode screenshots are byte-identical** (same MD5
`00a61c383fb9fca7284f774a98e9490b`). The change is scoped to
`[data-theme="dark"] .empregos-landing-cta .btn.btn-primary` only; `main.css`
`.btn-primary` (orange accent) is untouched, and the global `.btn-primary`
contract used by the homepage hero, 404 and every other CTA is untouched (a test
asserts no unscoped `.btn-primary` selector was added to `dark-mode.css`).

## 7. Verification results

- **New suite** `test-empregos-landing-cta.php`: **11 passed, 0 failed**
  (standalone and inside the full aggregate run).
- **Standalone render harness** (real CSS files in the real cascade order + the
  exact button markup): before = orange/green 1.19:1; after = `#287443`/white
  5.72:1; hover = `#1F5E2F`/white 7.78:1; focus = 3px `#F59E42` outline.
- **`./scripts/lint.sh`**: OK (syntax clean, no new PHPCS violations, PHPStan clean).
- **`./scripts/run-tests.sh`** (canonical aggregate, full): completed.
  In-process PHP **104 total, 78 passed, 26 failed**; assertions **5762 passed,
  34 failed**; script-contract 12/19; HTTP acceptance 2/2 (+1 timeout).

### Remaining limitations

- The 26 failing suites are **all pre-existing** and unrelated to this change:
  `conexao-translation-automation/*` (19 suites — directory not mounted in this
  container), date-dependent importer assertions (today is 2026-10-12),
  Polylang term-linking in `test-blog/guide-en-translation`, `test-stage32/33/41/45`
  bilingual permutations, `test-leisure-related-events`, the Laois
  event-occurrence HTTP matrix, and Stage G registry drift for unmounted plugins
  (`conexao-page-translation`, `conexao-job-translation`). None reference
  `dark-mode.css`, the empregos CTA, or the new test. The same pre-existing
  failures are recorded in `docs/reports/2026-10-12-empregos-job-resources.md`.
- The live `/empregos/` page did not render the CTA in this local dev database
  (a page-drift/routing anomaly: `url_to_postid('/empregos/')` → 11086 while the
  served HTML shows `post-15`), so visual verification used a **standalone
  harness** loading the real CSS files and the exact markup rather than the live
  page. This is a local-environment state issue, not a code defect, and does not
  affect the CSS fix.

## 8. Rollback

`git checkout -- wp-content/themes/conexao-br-irlanda/assets/css/dark-mode.css`
and delete the new test + report/evidence. Nothing in the database was written.

## 9. Final status

**PASS** — the colour adjustment is implemented, visually verified in both
themes and all interactive states, passes WCAG AA, leaves light mode
byte-identical, and passes the new regression suite with no new failures in the
canonical aggregate run.
