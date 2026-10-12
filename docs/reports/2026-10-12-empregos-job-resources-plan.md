# Plan — Add job-search resource cards to /empregos/

| | |
|---|---|
| **Task title** | Add the "Onde procurar emprego" job-search resource cards to `/empregos/` |
| **Date** | 2026-10-12 |
| **Author / agent** | Cline (AI coding agent) |
| **Branch** | `master` |
| **Repository baseline (starting SHA)** | `ceb532f1463a54d2f38140d87696d7e7e516e1da` (clean working tree) |
| **Applicable skill(s)** | `wp-write-in-process-test`, `wp-http-acceptance-matrix`, `wp-update-docs`, `wp-testing` |
| **Plan status** | approved (task statement authorises the addition) |

## 1. Scope

Render the three existing job-search resources (Jobs.ie, Indeed Ireland,
IrishJobs) on `/empregos/` by restoring the already-built shared component
`template-parts/job-resources.php` (data: `conexao_job_resources()`), align its
CTA with the mandated "Acessar" pattern, add regression coverage (one
in-process suite + routing-matrix rows for PT and EN), update the theme
documentation row, and report real test numbers.

## 2. Explicit non-goals

- No new abstraction, data structure or template part: the component, option,
  admin screen and CSS already exist.
- No change to the homepage (`front-page.php` / `job-resources-preview.php`)
  beyond asserting it stays intact.
- No change to job listings, the employment-opportunities directory, its
  filters, pagination, or any other page section.
- No new translation lifecycle, no `.po`/`.pot` edits (every string needed is
  already catalogued), no EN content records.
- No production write, deploy, commit, or Flutter/mobile-repo access.

## 3. Relevant authoritative documents

| Fact | Owner |
|---|---|
| Engineering rules, testing standard §8, reporting §9/§13 | `docs/engineering-standard.md` |
| Theme internals (template-part inventory) | `docs/themes/conexao-br-irlanda.md` |
| Test layers, prerequisites, base URL | `docs/testing.md` |
| Content/language policy (PT immutable) | `docs/content-model.md` |
| Skills (procedures) | `wp-write-in-process-test`, `wp-http-acceptance-matrix`, `wp-update-docs`, `wp-testing` |

## 3a. Skill workflow

| | |
|---|---|
| **Skill** | `wp-write-in-process-test` + `wp-http-acceptance-matrix` (then `wp-testing` for the aggregate run) |
| **Its preconditions** | Local stack up (wordpress + db running, site 200 at localhost:8080); the assertion that would fail on revert is named (section call + rendered markup) |
| **Its guardrails that bind this task** | One bootstrap (`tests/bootstrap.php`), shared assertion API only, no bespoke assert helper, declared prerequisites, read-only or self-restoring fixtures, fixed matrix schema, local base URL only |
| **Its verification commands** | `./scripts/run-tests.sh --list`, focused suite run, `./scripts/run-tests.sh --acceptance`, `./scripts/run-tests.sh`, `./scripts/lint.sh` |
| **Its evidence location** | `docs/evidence/2026-10-12-empregos-job-resources/` |

## 4. Current-state findings (verified at baseline)

- Homepage: `front-page.php` §`section--jobs` renders
  `template-parts/job-resources-preview.php` — 3 compact
  `event-card job-resource-card` cards, "Acessar" CTA, `target="_blank"
  rel="noopener noreferrer"` (confirmed: `curl localhost:8080/` → 3
  `job-resource-card`).
- `/empregos/` (`page-empregos.php`) contains a documented comment where
  `get_template_part( 'template-parts/job-resources' )` was removed in
  `a035ce0` ("INTENTIONALLY not rendered ... To bring the section back,
  restore: ..."). The full component (`template-parts/job-resources.php`,
  `.empregos-job-resources` CSS in `main.css`, admin screen, option
  `conexao_job_resources`) exists and is currently rendered nowhere.
- Shared data source `conexao_job_resources()` (option, falling back to
  built-in defaults) already returns exactly the three spec resources with the
  exact spec URLs and descriptions.
- The full component's CTA reads "Saiba mais"; the homepage pattern mandated by
  the task is "Acessar". Both msgids and the `Acessar %s (abre em nova aba)`
  aria-label msgid already exist in `languages/conexao-br-irlanda.pot` and
  `en_US.po` — no catalogue edit needed.
- No existing test or matrix row covers job resources (verified by grep).
- `/en/empregos/` exists (shared-slug EN page, `lang="en-US"`); the EN
  catalogue renders "Where to look for jobs" for the section title (confirmed
  from EN homepage evidence).

## 5. Proposed approach

**Chosen:** smallest change consistent with the architecture, exactly what the
in-repo comment prescribes:

1. Restore `get_template_part( 'template-parts/job-resources' );` in
   `page-empregos.php` at the documented location (after the landing content,
   before the employment-opportunities directory), replacing the stale comment.
2. Align the restored component's CTA to the "Acessar" pattern (visible
   "Acessar" + `aria-label="Acessar %s (abre em nova aba)"`), the same
   contract the homepage preview uses; banner links, SVG, `title`, `target`
   and `rel` stay as designed.
3. New in-process suite `test-empregos-job-resources.php`: forces the built-in
   defaults via the shared option-isolation helper (self-restoring), renders
   both template parts, asserts exact spec content, link security attributes,
   shared data source, homepage intact and jobs-page preservation calls; plus
   an EN-locale rendering check.
4. Two `routing.json` rows: `pt-empregos-job-resources` and
   `en-empregos-job-resources`.
5. Update `docs/themes/conexao-br-irlanda.md` row for `job-resources.php`
   ("Currently not rendered anywhere" becomes false) + refresh its
   `_Last verified_` marker.

**Rejected options:** (a) duplicating three hard-coded cards in
`page-empregos.php` — a second source of truth; (b) reusing
`job-resources-preview.php` on `/empregos/` — it is explicitly the compact
homepage variant (first 3, no section heading); (c) a new shared-renderer
refactor — disproportionate; the repository already shares the *data* source,
the documented single-source-of-truth boundary.

## 6. Files expected to change

| Path | Change | Why |
|---|---|---|
| `wp-content/themes/conexao-br-irlanda/page-empregos.php` | modify | restore the section call + accurate comment |
| `wp-content/themes/conexao-br-irlanda/template-parts/job-resources.php` | modify | CTA "Saiba mais" → "Acessar" + aria-label (homepage pattern) |
| `wp-content/themes/conexao-br-irlanda/tests/test-empregos-job-resources.php` | add | regression coverage |
| `tests/acceptance/matrices/routing.json` | modify | 2 rows (PT + EN) |
| `docs/themes/conexao-br-irlanda.md` | modify | component row now rendered on `/empregos/` + marker |
| `docs/reports/2026-10-12-empregos-job-resources-plan.md` | add | this plan |
| `docs/reports/2026-10-12-empregos-job-resources.md` | add | final report |
| `docs/evidence/2026-10-12-empregos-job-resources/*` | add | machine evidence |

## 7. Files explicitly expected NOT to change

`plugins.json` + generated registry regions; `front-page.php` and
`template-parts/job-resources-preview.php` (homepage); `inc/job-resources.php`
(data source untouched); `assets/css/main.css` (styles already exist);
`inc/employment-opportunities.php` (filters/pagination); all `languages/*`
catalogues; routes/redirects/sitemap; any plugin; production; the
Flutter/mobile repository.

## 8. Content / data impact

- Records created/updated/deleted: **none** (no post writes; rendered content
  comes from the theme's built-in defaults, and the test only isolates and
  restores the `conexao_job_resources` option).
- Portable identifiers: not applicable.
- Does this write content? No. PT content unchanged — the three PT strings are
  reproduced verbatim from the existing shared defaults.
- PT content changed? **No** — only the explicitly requested addition.

## 9. Route / HTTP impact

- Routes added/removed/changed: **none** (same URLs; new section on an
  existing page). Redirects: none. Sitemap: unchanged.
- HTTP matrix rows to add: `pt-empregos-job-resources` (`/empregos/`),
  `en-empregos-job-resources` (`/en/empregos/`).

## 10. Polylang / English impact

- Translated post types/taxonomies affected: none.
- PT immutable: **yes** — PT slugs, titles and copy unchanged.
- B1/B2: not applicable (no new destination; the EN shared-slug page renders
  the same template and the existing catalogues translate the UI strings).
- Canonical/hreflang/language switcher: unchanged.
- Language-scoped cache keys: none affected (no new queries or transients).
- Completeness gate: none needed — no new msgid is introduced.

## 11. Security impact

- Capabilities/nonces: none added (front-end render only; admin screen
  untouched).
- Sanitisation/escaping: existing template escaping reused as-is (`esc_url`,
  `esc_html`, `esc_attr`; `target="_blank" rel="noopener noreferrer"` on every
  external link).
- SQL: none. REST: none. Secrets: none.

## 12. Performance impact

- Queries: +0 (one `get_option` read, same as the homepage already does).
- Caching: none new. Assets: none new (CSS already shipped; default cards are
  text/letter-fallback only, no image downloads).
- Measurable improvement: none claimed; the goal is feature parity.

## 13. Production impact

| Question | Answer |
|---|---|
| Will anything be written to production? | **no** |
| Will a deploy, upload or activation happen? | **no** |
| Who performs it, and how? | n/a — no production action in this task |
| Verification of the production result? | n/a |

## 14. Test plan

- In-process: `wp-content/themes/conexao-br-irlanda/tests/test-empregos-job-resources.php`
  (new) — spec content, URLs, security attributes, shared data source,
  homepage intact, jobs-page preservation, EN rendering.
- Acceptance: 2 rows in `tests/acceptance/matrices/routing.json`.
- Script contract: none (no `scripts/` change). Registry drift: n/a.
- Pass, numerically: new suite prints `N passed, 0 failed`; routing suite
  prints `N passed, 0 failed` including the 2 new rows; aggregate
  `./scripts/run-tests.sh` exits 0 with no new failing suites vs baseline.

## 15. Acceptance matrix plan

| id | url | expect_status | expect_contains | expect_absent |
|---|---|---|---|---|
| `pt-empregos-job-resources` | `/empregos/` | 200 | `class="empregos-job-resources"`, the three exact hrefs, the three exact descriptions, `target="_blank" rel="noopener noreferrer"`, `Acessar Jobs.ie (abre em nova aba)`, `Oportunidades de emprego` | `<html lang="en-US">` |
| `en-empregos-job-resources` | `/en/empregos/` | 200 | `Where to look for jobs`, the three exact hrefs, `target="_blank" rel="noopener noreferrer"` | `<html lang="pt-BR">` |

Each row carries a `note` explaining why it exists. Expected: routing suite
grows by 2 rows; exact totals recorded from the run in the report.

## 16. Rollback plan

`git checkout -- wp-content/themes/conexao-br-irlanda/page-empregos.php
wp-content/themes/conexao-br-irlanda/template-parts/job-resources.php
tests/acceptance/matrices/routing.json docs/themes/conexao-br-irlanda.md` and
delete the new test + report/evidence. Rollback does not cover anything in the
database (the task writes none). Pre-change state captured at baseline SHA
`ceb532f`.

## 17. Documentation plan

| Document | Change | Procedure moved to (skill) |
|---|---|---|
| `docs/themes/conexao-br-irlanda.md` | `job-resources.php` row: no longer "Currently not rendered anywhere"; renders on `/empregos/`; refresh `_Last verified_` | — |
| `docs/reports/…-plan.md`, report, `docs/evidence/…` | new plan/report/evidence per §9.2 | — |

## 18. Release implications

- Version bump: none (no plugin component changed; theme behaviour tracked by
  git). Artifact allowlist: unaffected (`tests/` never shipped).
- Is a release in scope? **No.**

## 19. Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| Scope creep into re-styling `/empregos/` sections | medium | only the documented restore + CTA alignment; no CSS changes |
| Breaking the employment-opportunities filters/pagination | low | static preservation assertions + existing acceptance rows + aggregate run |
| Vacuous tests | medium | option isolation forces known data; revert-the-change vacuity proof recorded as evidence |
| i18n freshness gate regression from a gettext-file edit | low | no new/removed msgid (`Acessar` already used by the preview); gate keyed on git commit times |
| Homepage drift | low | homepage template untouched; suite asserts its markup |

## 20. Verification gates

1. `php -l` on every edited PHP file.
2. Focused in-process suite run (container) — real `N passed, M failed`.
3. `./scripts/run-tests.sh --list` — suite discovered.
4. `python3 tests/acceptance/verify-routing-http.py` — rows pass; wrong-
   expectation negative proof for the new row.
5. `./scripts/lint.sh` — PHP syntax + PHPCS + PHPStan.
6. `./scripts/run-tests.sh` (aggregate, full) — report actual result.
7. `curl localhost:8080/empregos/` + `/en/empregos/` before/after capture.

## 21. Completion criteria

Per engineering-standard §14: plan filed, smallest shared-source change,
behaviour preserved (homepage, listings, filters, pagination), regression
coverage on, real numbers in the report, evidence under
`docs/evidence/2026-10-12-empregos-job-resources/`, no production write, no
commit, no mobile-repo access.



