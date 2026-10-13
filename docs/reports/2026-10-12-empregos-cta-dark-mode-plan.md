# Plan — Dark-mode appearance of the Empregos landing CTA

| | |
|---|---|
| **Task title** | Audit and improve the dark-mode appearance of `.empregos-landing-cta .btn.btn-primary` |
| **Date** | 2026-10-12 |
| **Author / agent** | Cline (AI coding agent) |
| **Branch** | `master` |
| **Repository baseline (starting SHA)** | `898d3ebd7c6c792046d89b5a83834c609ae6e02b` (clean working tree) |
| **Applicable skill(s)** | `wp-frontend-perf`, `wp-write-in-process-test`, `wp-testing`, `wp-update-docs` |
| **Plan status** | approved (task statement authorises the targeted change) |

## 1. Scope

Improve the dark-mode appearance of the single primary CTA on `/empregos/`
(`.empregos-landing-cta .btn.btn-primary`, "Ver vagas no Instagram") by
replacing its over-saturated orange accent surface and its mis-computed green
label with a deepened brand green carrying white text. The change is scoped to
one dark-mode selector, uses a new token in the existing dark-mode token
architecture, leaves light mode byte-identical, and adds one in-process
regression suite.

## 2. Explicit non-goals

- No change to the CTA's destination URL, label, HTML semantics, or external-link
  security attributes (`target="_blank" rel="noopener noreferrer"`).
- No redesign of the button (dimensions, padding, typography, radius, icon,
  layout preserved) or the surrounding section.
- No change to the global `.btn-primary` contract or any other CTA (homepage
  hero, 404, events, leisure).
- No new colour system; the deepened green lives in the existing
  `:root[data-theme="dark"]` token block in `dark-mode.css`.
- No light-mode change; no production write, deploy, commit, or mobile-repo access.

## 3. Relevant authoritative documents

| Fact | Owner |
|---|---|
| Engineering rules, testing §8, reporting §9 | `docs/engineering-standard.md` |
| Design tokens, dark-mode variables, frontend conventions | `docs/frontend.md`, `docs/themes/conexao-br-irlanda.md` |
| CSS conventions (design-system + dark-mode variables, no page hacks) | `.agents/skills/wp-frontend-perf/SKILL.md` |
| Test layers, base URL, suite discovery | `docs/testing.md` |

## 4. Current-state findings (measured at baseline, in Chromium)

A standalone harness loading the **real** `design-system.css` + `main.css` +
`dark-mode.css` in the real cascade order (customizer `:root` block last, as
`inc/assets.php` emits it) plus the exact button markup measured:

| State | Background | Text + SVG icon | Text contrast |
|---|---|---|---|
| light | `#F68B1F` orange accent | white | 2.43:1 (pre-existing brand accent) |
| dark  | `#F59E42` orange accent | `#3AB875` **green** | **1.19:1 — FAIL** |

**Root cause (two compounding defects):**
1. `.empregos-landing-cta .btn.btn-primary` has no dark-mode rule, so it keeps
   the bright orange accent `#F59E42` — over-saturated for the dark interface.
2. The generic anchor rule `[data-theme="dark"] a { color: var(--conexao-primary) }`
   (specificity **0,1,1**) outranks `.btn-primary { color: var(--conexao-white) }`
   (**0,1,0**), so the label and arrow compute to the dark green `#3AB875` —
   green-on-orange at 1.19:1, effectively invisible.

## 5. Chosen direction (measured, not preference)

Replace the dark-mode surface with the deepened brand green `#287443` (white
text **5.72:1**, PASS) and hover `#1F5E2F` (white text **11.76:1**, PASS). The
new selector `[data-theme="dark"] .empregos-landing-cta .btn.btn-primary`
(**0,4,0**) also outranks the generic anchor rule, so the text and arrow render
white. The palette has no blue; the brand green is the brand-compatible primary
that integrates with the interface, and it is already the site's dominant
action colour. No new colour family is introduced.

## 6. Files to change

| Path | Change |
|---|---|
| `wp-content/themes/conexao-br-irlanda/assets/css/dark-mode.css` | +2 tokens (`--conexao-cta-bg`, `--conexao-cta-bg-dark`) in the `:root[data-theme="dark"]` block; +2 scoped button rules in §33 |
| `wp-content/themes/conexao-br-irlanda/tests/test-empregos-landing-cta.php` | new in-process regression suite (11 assertions) |
| `docs/themes/conexao-br-irlanda.md`, `docs/frontend.md` | record the materially changed design rule (if the doc rows describe the CTA) |
| `docs/reports/…`, `docs/evidence/…` | plan, report, evidence per §9.2 |

## 7. Verification gates

1. `php -l` on the new test.
2. `docker compose exec wordpress php …/test-empregos-landing-cta.php` — 11 passed, 0 failed.
3. Standalone harness: before/after computed styles + screenshots in light/dark/hover/focus.
4. `./scripts/lint.sh` — syntax + PHPCS no-new + PHPStan clean.
5. `./scripts/run-tests.sh` (canonical aggregate) — report actual result; my suite green, failures pre-existing and reproduced at baseline.

## 8. Rollback plan

`git checkout -- wp-content/themes/conexao-br-irlanda/assets/css/dark-mode.css`
and delete the new test + report/evidence. Nothing in the database is written.
