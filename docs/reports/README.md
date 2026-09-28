# Reports index

Stage/feature history for the Conexão BR Irlanda website. Evergreen reference
lives in `docs/` (`architecture.md`, `routing.md`, `content-model.md`, …);
this directory holds the dated, point-in-time record of what was done and how
it was verified.

## Rule (engineering standard §9)

- **No root-level report or evidence file is added.** New stage/feature
  reports go here as `docs/reports/<date>-<stage>-<topic>.md`, written from
  `docs/templates/report.md`, and machine-readable proof (matrices,
  `gate.json`, snapshots) goes to `docs/evidence/<date>-<stage>/`.
- The twenty `CONEXAO_*.md` files below pre-date that convention; they were
  moved here from the repository root in Stage B (repository hygiene) with
  their original filenames preserved so existing citations keep resolving.
- Generated work trees (`stage*-work/`, `*.body`, `*.log`, …) are git-ignored
  and are never the permanent record — distil findings into a report here and
  keep only curated proof under `docs/evidence/`.

## English rollout (Stages 0–9)

| Report | Content |
|---|---|
| `CONEXAO_BR_ENGLISH_SUPPORT_AUDIT.md` | Pre-rollout English-support audit (2026-09-20, audit only) |
| `CONEXAO_BR_ENGLISH_ARCHITECTURE_DECISION.md` | Approved EN architecture: PT canonical, `/en/` via Polylang, linked translations (Stage 0) |
| `CONEXAO_BR_ENGLISH_STAGE_1_REPORT.md` | Stage 1 — Polylang foundation |
| `CONEXAO_BR_ENGLISH_STAGE_2_REPORT.md` | Stage 2 — Polylang setup, translated taxonomies, REST probe gate |
| `CONEXAO_BR_ENGLISH_STAGE_3_1_REPORT.md` | Stage 3.1 — shared-category EN terms |
| `CONEXAO_BR_ENGLISH_STAGE_3_2_REPORT.md` | Stage 3.2 — taxonomy language policy (category/tag translated; county/town shared) |
| `CONEXAO_BR_ENGLISH_STAGE_3_3_REPORT.md` | Stage 3.3 — shared content types / REST groundwork |
| `CONEXAO_BR_ENGLISH_STAGE_4_1_REPORT.md` | Stage 4.1 — bilingual REST contract (`?lang=`, 59-row matrix in `docs/evidence/stage-4-1-rest-matrix/`) |
| `CONEXAO_BR_ENGLISH_STAGE_4_2_REPORT.md` | Stage 4.2 — EN menus / navigation rollout |
| `CONEXAO_BR_ENGLISH_STAGE_4_3_REPORT.md` | Stage 4.3 — production deployment of the EN layer (see `docs/english-stage43-production-deployment.md`) |
| `CONEXAO_BR_ENGLISH_STAGE_4_5_REPORT.md` | Stage 4.5 — EN translation of all public pages |
| `CONEXAO_BR_ENGLISH_BLOG_TRANSLATION_REPORT.md` | Stage 5 — real EN blog posts replace B2 fallback |
| `CONEXAO_BR_ENGLISH_JOBS_TRANSLATION_REPORT.md` | Stage 6 — real EN job records + `/en/jobs/` |
| `CONEXAO_BR_ENGLISH_LEISURE_CARD_DESCRIPTION_REPORT.md` | Stage 7 — EN Leisure card descriptions (`_leisure_excerpt_en`) |
| `CONEXAO_BR_ENGLISH_HERO_SPONSORS_REPORT.md` | Stage 7.x — EN homepage hero sponsors |
| `CONEXAO_BR_ENGLISH_GUIDES_TRANSLATION_REPORT.md` | Stage 9 — EN guides (`/guias/` → `/en/guias/`) |

## Navigation fix reports

| Report | Content |
|---|---|
| `CONEXAO_BR_HEADER_NAVIGATION_REGRESSION_REPORT.md` | Header/primary-nav regression after the i18n work |
| `CONEXAO_BR_EN_HEADER_NAVIGATION_FIX_REPORT.md` | EN primary nav rendering an empty `<nav>` — fix |
| `CONEXAO_BR_EN_NAV_LANGUAGE_CONTEXT_FIX_REPORT.md` | EN nav items silently switching back to Portuguese — fix |
| `CONEXAO_BR_ENGLISH_BLOG_NAVIGATION_FIX_REPORT.md` | EN Blog nav item losing language context — fix |

## Repository engineering

| Report | Content |
|---|---|
| `2026-09-25-stage-b-repository-hygiene.md` | Stage B of the standardisation roadmap — baseline, cleanup inventory, verification numbers |
| `2026-09-26-stage-g-plugin-registry-lifecycle.md` | Stage G — `plugins.json` registry + lifecycle; generated load order, build list, Compose mounts and plugin docs, with a blocking CI drift gate |
| `site/2026-09-26-stage-h-shared-rollout-engine.md` | Stage H — the shared translation-rollout engine (`conexao-translation-rollout`) owning inventory → manifest → dry-run → snapshot → apply → verify + numeric gate, and the migration of one retired rollout (`conexao-job-translation`) onto it with its duplicated orchestration deleted |

Stage reports for the standardisation roadmap's repository-engineering stages
(Stage C static quality tooling, Stage D CI, Stage E test harness, Stage F theme
modularisation, …) live in [`site/`](site/) as
`<date>-<stage>-<topic>.md`.

| Repository-engineering stage report | What it covers |
|---|---|
| `site/2026-09-25-stage-c-static-quality-tooling.md` | Stage C — `scripts/lint.sh`, PHPCS/PHPStan baselines |
| `site/2026-09-25-stage-d-ci.md` | Stage D — the blocking CI workflow |
| `site/2026-09-25-stage-e-test-harness.md` | Stage E — the three-layer `scripts/run-tests.sh` harness |
| `site/2026-09-26-stage-f-theme-modularisation.md` | Stage F — theme module split |
| `site/2026-09-26-stage-g-plugin-registry-lifecycle.md` | Stage G — the `plugins.json` registry + drift gate |
| `site/2026-09-26-stage-h-shared-rollout-engine.md` | Stage H — the shared translation-rollout engine |
| `site/2026-09-26-stage-i-scripts-standardisation.md` | Stage I — script classification, bootstrap/REST libraries, catalogue |
| **`site/2026-09-26-stage-j-build-release-deploy-verification.md`** | **Stage J — the release contract: registry-derived artifact allowlist, deterministic packaging, `dist/release.json`, `scripts/verify-deploy.py` with a fixed smoke matrix, the release-integrity CI gate and the documented rollback procedure** |
| `2026-09-27-en-leisure-descriptions.md` | Stage 7 — EN Leisure card descriptions applied through the shared `en-leisure-description` stage (289 descriptions; `missing_en = 0`; `pt_drift = 0`); supersedes the retired `conexao-leisure-translation` lifecycle |


## Baseline recovery (2026-09-27)

The `597b04e` theme regression and its forward recovery. Read in order: the
assessment establishes the damage, the implementation restores it, and the closeout
investigates the one remaining translation finding and establishes the baseline.

| Report | Content |
|---|---|
| `2026-09-27-baseline-recovery-assessment.md` | Assessment of the `597b04e` regression — what it destroyed (120 functions lost, `inc/` cut 43 → 8, all 5 theme catalogues and 22 test files deleted) and the recovery options |
| `2026-09-27-baseline-recovery-implementation.md` | Forward reconstruction from `b47d098` (commit `cd800be`); no revert/reset/cherry-pick/merge; full verification numbers; triage of the one "new" permanent-gate violation |
| `2026-09-27-recovery-closeout.md` | **Final closeout** — the one new translation-completeness finding proven pre-existing (reproduces on `597b04e`; importer record predates the language guard), full runner byte-identical to the recovered baseline, 7 permanent gates, HTTP + browser verification, negative proofs, POT/catalogue/architecture integrity. Status: **PASS WITH LIMITATION** |
| `2026-09-28-en-archive-404-fix.md` | **Fix `/en/apoiadores/`, `/en/eventos/`, `/en/guias/`** — root cause is a persisted `rewrite_rules` set flushed before Polylang attaches its rewrite filters (336 rules / 0 `(en)/` post_type, reproduced exactly by disabling those filters); NOT caused by `597b04e` (theme byte-identical to `b47d098`, where the routes were 200). Adds a `wp_loaded@20` self-heal in `inc/i18n/guard.php` (no allowlist, no redirect, no new routing system) + a 17-assertion suite. Runner 59→60 suites / +17 assertions / 0 new failures; gates byte-identical; PT immutable. Status: **PASS WITH LIMITATION** |

## EN archive route 404 investigation (2026-09-28)

Follow-up to the baseline recovery: the three EN CPT-archive routes
(`/en/apoiadores/`, `/en/eventos/`, `/en/guias/`) reported 404 again with the
recovered code unchanged.

| Report | Content |
|---|---|
| `2026-09-28-en-archive-route-404-plan.md` | The investigation plan (route work; scenarios, non-goals, verification gates) |
| `2026-09-28-en-archive-route-404.md` | **Root cause proven: the registration code is byte-identical to the verified `b47d098` baseline; the 404s are the stale local `rewrite_rules` failure mode (no `en/`-prefixed CPT-archive rules). The minimal fix is the documented local rewrite flush (`scripts/flush-blog-rewrite-rules.php`) — a local DB operation, not a code change — now recorded in `docs/routing.md`. No code, gate or expectation changed. Status: BLOCKED (no runtime in the task environment to execute the flush).** |

## Related: `docs/audit/` (engineering audits), `docs/engineering-standard.md`
(the normative standard these reports are measured against).

_Last verified: 2026-09-27 by the recovery closeout_

_Last verified: 2026-09-28 by the EN archive route 404 investigation_
