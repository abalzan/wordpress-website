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


Related: `docs/audit/` (engineering audits), `docs/engineering-standard.md`
(the normative standard these reports are measured against).

_Last verified: 2026-09-27 by the EN Leisure description rollout_
