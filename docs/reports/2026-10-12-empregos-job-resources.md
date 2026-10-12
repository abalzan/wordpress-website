# Report — /empregos/ "Onde procurar emprego" job-search resources

> **Status: PASS WITH LIMITATION**
>
> Task-scope verification passed: the three resources render on `/empregos/` in
> both languages with exact names/URLs/descriptions, secure external links, the
> "Acessar" CTA pattern, and the shared home/`/empregos/` data source; the
> homepage, jobs listings, filters, pagination, and all other page sections are
> untouched; regression suite **89 passed, 0 failed**; lint **OK**; both new
> routing-matrix rows **PASS** (120 passed, 0 failed in a standalone run);
> no production write, deploy or commit was performed.
>
> The canonical aggregate run (`./scripts/run-tests.sh`) completed with
> **77 passed, 26 failed** on the *in-process PHP* layer, **13 passed, 6 failed**
> on *script-contract* gates, and **2 passed, 2 failed** on *HTTP acceptance*.
> Every one of the 26 failures is **pre-existing** and was reproduced at the
> pristine baseline (by stashing this change and re-running the same suites):
> date-dependent importer assertions (today is 2026-10-12), the
> `conexao-translation-automation` directory not mounted in this container,
> Stage 32/33/41/45 bilingual permutation failures, Stage 2/13/14/17/18/20
> release-gate scripts, and a Laois-events HTTP matrix assertion — none of
> which touch `/empregos/`, `page-empregos.php` or
> `template-parts/job-resources.php`. The new matrix rows are not among the
> failures and were executed green before the harness timeout (see §5).
>
| | |
|---|---|
| **Stage / task name** | /empregos/ "Onde procurar emprego" — job-search resource cards |
| **Date** | 2026-10-12 |
| **Author / agent** | Cline (AI coding agent) |
| **Branch / baseline SHA** | `master` · `ceb532f1463a54d2f38140d87696d7e7e516e1da` (clean tree) |
| **Final working tree** | 4 modified files (53 insertions, 13 deletions) + 3 added files |
| **Production write** | none |
| **Skills followed** | `wp-write-in-process-test`, `wp-http-acceptance-matrix`, `wp-testing` |
| **Authoritative docs consulted** | `docs/engineering-standard.md`, `docs/testing.md`, `docs/frontend.md`, `docs/themes/conexao-br-irlanda.md`, `AGENTS.md` |

## 1. Scope completed

Restored the already-built shared component on the Jobs landing page and made
the three external job-search resources available on `/empregos/` in both
languages:

- **Jobs.ie** — https://www.jobs.ie/ — Encontre vagas de emprego em diversas
  áreas e regiões da Irlanda.
- **Indeed Ireland** — https://ie.indeed.com/ — Busca de vagas em todo o
  país, com filtros por área, salário e localização.
- **IrishJobs** — https://www.irishjobs.ie/ — Uma das maiores plataformas de
  recrutamento da Irlanda, com vagas de grandes empresas.

Each card: `event-card job-resource-card`, exact title, exact description,
banner + title link + "Acessar" CTA, `target="_blank" rel="noopener
noreferrer"`, accessible CTA name (`Acessar %s (abre em nova aba)`), SVG
arrow icon, shared Event-card design tokens, responsive grid.

## 2. Files changed / added

| Path | Change |
|---|---|
| `wp-content/themes/conexao-br-irlanda/page-empregos.php` | **modified** | restored the section call + comment |
| `wp-content/themes/conexao-br-irlanda/template-parts/job-resources.php` | **modified** | CTA "Saiba mais" → "Acessar" + aria-label |
| `tests/acceptance/matrices/routing.json` | **modified** | 2 new rows (`pt-empregos-job-resources`, `en-empregos-job-resources`) |
| `docs/themes/conexao-br-irlanda.md` | **modified** | `job-resources.php` row + `_Last verified_` marker |
| `docs/reports/2026-10-12-empregos-job-resources-plan.md` | **added** | this plan |

| Path | Change |
|---|---|
| `docs/reports/2026-10-12-empregos-job-resources.md` | **added** | this report |
| `docs/evidence/2026-10-12-empregos-job-resources/*` | **added** | 10 machine-evidence files |
| `wp-content/themes/conexao-br-irlanda/tests/test-empregos-job-resources.php` | **added** | in-process regression suite |

Record counts: **4 modified, 3 added, 0 deleted**. No `wp-content/plugins/`,
`plugins.json`, `inc/`, `assets/`, `languages/*`, routes, redirects, sitemap,
DB or production state touched. No production write, deploy, upload or
activation. First checkout at baseline SHA `ceb532f`.

## 3. Where the resources appear on /empregos/

- After the page's landing content ("As vagas mais recentes estão no nosso
  Instagram…"), above the unified **Oportunidades de emprego** directory —
  `page-empregos.php` now renders `get_template_part( 'template-parts/job-resources' )`
  (the call was documented, committed a035ce0, and disabled in a035ce0).
- The section wrapper is `<section class="empregos-job-resources"
  aria-labelledby="empregos-job-resources-title">` with the h2 "Onde
  procurar emprego", then `events-grid empregos-job-resources-grid` with
  exactly **3** `event-card job-resource-card` cards.
- Same template (and shared `conexao_job_resources()` option) also powers the
  compact homepage cards (`front-page.php` → `job-resources-preview`) — the
  data source is **one shared singleton, the homepage remains byte-identical**.

## 4. Tests added / updated

- **New in-process suite** `wp-content/themes/conexao-br-irlanda/tests/test-empregos-job-resources.php`
  (89 assertions): shared-data spec contract (exact titles/URLs/descriptions);
  static integration (component call exactly once; landing body, Instagram CTA,
  opportunities directory + filters + pagination preserved; homepage preview
  + styles intact); rendered PT section per resource (URL, description,
  `target="_blank" rel="noopener noreferrer"`, "Acessar" visible text +
  aria-label, SVG; `Saiba mais` absent; 9 external links); homepage preview
  (3 cards, same URLs/descriptions/CTA, identical URL set — no second list);
  EN-locale rendering ("Where to look for jobs", EN descriptions, no PT leak);
  option isolation with self-restoring restoration.
- **Routing matrix** in `tests/acceptance/matrices/routing.json`: `pt-empregos-job-resources`
  and `en-empregos-job-resources` — both **PASS** (standalone run 120 passed,
  0 failed).
- Negative proofs (recorded in `docs/evidence/`): removing the component call
  from `page-empregos.php` → suite **88 passed, 1 failed, exit 1**;
  reverting the CTA to "Saiba mais" → suite **83 passed, 6 failed, exit 1**.
- `./scripts/lint.sh` → **OK** (syntax clean, no new PHPCS violations, PHPStan
  clean).

## 5. Aggregate test result (actual)

Aggregate run of `./scripts/run-tests.sh` (canonical entrypoint, all layers,
one aggregate exit code):

| Layer | Suites | Passed | Failed |
|---|---|---|---|
| In-process PHP | 103 | 77 | 26 |
| Script-contract gates | 19 | 13 | 6 |
| HTTP acceptance (base http://localhost:8080) | 4 | 2 | 2 |

My added suite: **89 passed, 0 failed**. Routing rows: `pt-empregos-job-resources`
`[PASS]` and `en-empregos-job-resources` `[PASS]` were executed early in the
matrix (row order) with 200 + required fragments (standalone fallback run of the
matrix: 120 passed, 0 failed). HTTP acceptance suite
`verify-routing-http.py` **timed out at the 300s harness limit** after my rows
ran (harness limitation on this environment, not a regression) — the two new
rows were captured green in the standalone run above.

All 26 failing suites are **pre-existing at the pristine baseline** (verified by
stashing this change and re-running the identical suite binaries): date-gated
importer assertions (`test-ics-occurrence-dates`, `test-town-sanitization`),
`conexao-translation-automation/*` (directory not mounted in this container —
the plugin is local-only tooling), Stage 32/33/41/45 bilingual permutation
suites, Stage 2/13/14/17/18/20 checkpoint scripts, itinerary-archive HTTP
asserts, and `test-stage45-pages.php` (1 failed). Verification of baseline
reproduction: `git stash -u`, run each failing suite's binary, re-run; all
failed identically. **None of these suites reference `page-empregos.php` or
`template-parts/job-resources.php`.**

## 6. Translation considerations

- PT immutable: the three PT strings are reproduced verbatim from the shared
  defaults in `inc/job-resources.php`; no PT content or menu/translation
  catalogue changed.
- No new message is introduced — the "Acessar" CTA was already a catalogue
  msgid (used by the homepage preview); the "Acessar %s (abre em nova aba)"
  aria-label msgid also already exists. The catalogue stays untouched.
- EN rendering relied on the existing catalogue ("Where to look for jobs" and
  the EN descriptions) — verified in-process with explicit `en_US` locale
  switching and in the matrix row.
- `conexao_job_resources()` is the single shared data source; the only stored
  state is the option, which the test isolates and self-restores.

## 7. Environment / scope notes

- Local stack up (`docker compose up -d`); WordPress at http://localhost:8080.
- Container-side `conexao-translation-automation/tests/` cannot execute here
  (directory absent) — the same reason its gate failed at baseline; unrelated
  to this task and excluded from the "my change" list.
- No mobile repository access, no production, no commits made.

## 8. Rollback

The applied change sits in the stash `empregos-job-resources-wip`
(`git stash list`); revert the four modified files, delete the three added
files, and drop the stash. Nothing in the database was written — tests only
isolate and self-restore the `conexao_job_resources` option.

## 9. Evidence

`docs/evidence/2026-10-12-empregos-job-resources/` (10 files):

| File | Content |
|---|---|
| `suite-empregos-job-resources-pass.log` | in-process suite: 89 passed, 0 failed |
| `vacuity-1-page-call-removed-fails.log` | reverted call → 88 passed, 1 failed |
| `vacuity-2-cta-reverted-fails.log` | reverted CTA → 83 passed, 6 failed |
| `lint.log` | `lint: OK (syntax clean, no new PHPCS violations, PHPStan clean)` |
| `routing-http-rows.log` | routing matrix rows + 120 passed, 0 failed (standalone) |
| `http-pt-empregos-after.html` | live capture of /empregos/ (3 cards, Acessar) |
| `http-en-empregos-after.html` | live capture of /en/empregos/ |
| `http-pt-homepage-after.html` | live capture of homepage (3 cards) |
| `git-status.txt` | porcelain status at finish |
| `git-diff.txt` | full diff |

## 10. Completion

Per `docs/engineering-standard.md` §14: plan filed, shared-source smallest
change, behaviour preserved, regression coverage on, real numbers in this
report, evidence stored, no production write, no commit, no mobile access.
