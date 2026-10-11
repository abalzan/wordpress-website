# Plan — UI polish Phase 3 (Tier-2 convergence remainder)

| | |
|---|---|
| **Task title** | UI polish Phase 3 — Tier-2 convergent polish (component headings, card-CTA targets, single editorial measure) |
| **Date** | 2026-10-10 |
| **Author / agent** | Cline (AI agent) |
| **Branch** | `master` |
| **Repository baseline (starting SHA)** | `3b8532e` (clean tree, verified with `git status --short`) |
| **Applicable skill(s)** | `wp-repository`, `wp-frontend-perf` (primary), `wp-testing`, `wp-write-in-process-test`, `wp-update-docs` |
| **Plan status** | approved |

## 1. Scope

Implement the worthwhile, low-risk Tier-2 remainder of the 2026-10-03 UI/UX
audit (Direction A — Converged Minimal, plus Direction B's editorial measure
narrowly on singles), validated item-by-item against live Chromium renders on
`master`, which already contains convergence Phases 1+2 (T1.1–T1.14).
Three CSS-only changes in shared sources, one new in-process regression suite,
docs + evidence. No template, route, content, translation or behaviour change.

## 2. Explicit non-goals

T2.1 shared-card component (T1.3/T1.4 already solved divergence with a shared
selector list + tokens); T2.2 image-ratio unification (event/provider/archive
cards already 16:9, leisure text cards intentional, provider logo soft crop
intentional); T2.7 breakpoint rationalisation (13 values are mostly deliberate
tablet guards); T2.8 inline-style dequeue (core asset path); T2.4 filter
paradigm unification (filter ARIA is an audit-confirmed strength); enlarging
the 50 inline agency links on `/empregos/` (accepted WCAG 2.5.8 inline
exceptions — padding them would destroy the density the audit protects);
T1.9 date-badge consolidation (needs its own evidence pass); the 8 pre-existing
failing suites. No colour-palette or typography replacement, no new framework,
dependency or JS, no production action, no mobile-repo access.

## 3. Relevant authoritative documents

`AGENTS.md`; `docs/engineering-standard.md` §0/§3.1/§3.2/§8/§14;
`docs/frontend.md`; `docs/themes/conexao-br-irlanda.md`; `docs/routing.md` and
`docs/content-model.md` (read to *prove* no route/content change);
`docs/testing.md`; `docs/audit/2026-10-03-ui-ux-audit.md`; `plugins.json`
(untouched). Procedures live in the skills, not in this plan.

## 4. Current-state findings

Verified against the live Docker site (Chromium 1440x1000 and 390x844,
computed-style extraction) plus CSS source:

- **T1 convergence HOLDS**: one card-title treatment (18px/600) on every
  archive; event/provider cards 16:9; `--conexao-accent-text` 4.74:1 and
  `--conexao-gray-600` 4.69:1 on white; zero horizontal overflow; exactly one
  `h1` + one `main` per render; Empregos orphan grid fixed; leisure
  placeholders removed; heading jumps 0.
- **R1** — component-heading remainder: `.help-section-heading` 20px from a raw
  `1.25rem`; mobile `.quick-access-heading` 17px from an **off-scale**
  `1.0625rem`; `.leisure-filters-title` 18px/700 and
  `.empregos-opportunities-title` 28px/700 (both component roles, already
  token/clamp-bound). Archive section titles are already converged.
- **R2** — card-CTA heights: event 34px, archive 34px, leisure 29–31px, while
  `.provider-card-cta` already renders 44px and `.btn` guarantees 44px.
- **R3** — guide single body renders 800px wide; single body uses an
  off-scale `1.0625rem`/`1.8`.

## 5. Proposed approach (and rejected options)

1. **R1** token-bind the two headings (`.help-section-heading` →
   `var(--text-xl)`, identical pixels; mobile `.quick-access-heading` →
   `var(--text-base)`, one step down) and document the four deliberate
   exceptions with rationale comments. *Rejected:* forcing
   `leisure-filters-title`/`empregos-opportunities-title` to the section scale
   — that would weaken hierarchy, which the brief forbids.
2. **R2** add `min-height: 44px` to the three shared card-CTA rules.
   *Rejected:* enlarging inline agency text links (destroys density); merging
   all CTAs into one visual style (card roles legitimately differ in fill).
3. **R3** `.single-post-content` 800→720px; single body
   `1.0625rem/1.8` → `var(--text-lg)`/1.7. *Rejected:* a wider constraint or a
   heading-strength restyle of authored content.

Dark mode: no colour is introduced, so `dark-mode.css` needs no change
(verified by inspection — every touched property is size/layout, theme-agnostic).

## 6–20. Impact, files, tests, risks

- **Files changed**: `assets/css/main.css`, `assets/css/leisure.css`,
  `tests/test-ui-polish-p3.php` (new), `docs/frontend.md`, this plan, the
  report, `docs/evidence/2026-10-10-ui-polish/`.
- **Files NOT changed**: `plugins.json` + generated registry regions, all
  templates and `inc/*`, `dark-mode.css`, routes/redirects/sitemap/Polylang,
  fixtures, production, the Flutter/mobile repository.
- **Content/data**: none. **Routes/HTTP**: none. **Polylang**: PT immutable,
  no EN records. **Security**: none. **Performance**: 0 queries added, no new
  assets or requests, no cache keys.
- **Production**: nothing written, deployed, uploaded or activated.
- **Test plan**: new suite discovered by convention; existing
  `test-ui-convergence-t1/t2.php` must stay green; `./scripts/lint.sh`;
  theme-layer before/after comparison with the change stashed.
- **Rollback**: `git checkout --` the two CSS files + delete the test; proven
  by the stashed run turning the new suite red.

## 21. Completion criteria

Per engineering-standard §14: plan filed, smallest shared-source change,
behaviour preserved, real before/after numbers, report + evidence complete, no
production write, no mobile-repo access.
