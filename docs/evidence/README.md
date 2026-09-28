# Evidence directory rule

Machine-readable proof for stage/feature work lives here, tracked in Git:
`docs/evidence/<date>-<stage>/` (matrices, `gate.json`, snapshots, curated
inventories) — see `docs/engineering-standard.md` §9.1.

## What belongs here vs. what is disposable

| Location | Status | Rule |
|---|---|---|
| `docs/evidence/<date>-<stage>/` | **Tracked, permanent** | Small, curated proof cited by a report in `docs/reports/`. Keep the rows that demonstrate the gate; drop raw dumps. |
| `stage*-work/`, `*-work/` | **Git-ignored, disposable** | Scratch work trees (probe output, HTTP captures, caches). Never commit them; regenerate on demand. |
| `*.body`, `*.log`, `.local/` | **Git-ignored, disposable** | Generated responses, run logs, local toolchains. Never commit. |
| `content-inventory/` | **Tracked** | Curated migration payloads (Wix inventory CSVs). |
| `dist/` | **Git-ignored** | Build output only — never the only copy of curated input. |

The ignore rules enforcing this live in the root `.gitignore`
(engineering standard §1.3).

## Current contents

| Directory | Proof for | Source |
|---|---|---|
| `stage-4-1-rest-matrix/stage41-rest-matrix.json` | Stage 4.1 bilingual REST contract — 59-row verification matrix | `scripts/verify-rest-english.py` output, cited by `docs/reports/CONEXAO_BR_ENGLISH_STAGE_4_1_REPORT.md` (moved from repo root in Stage B) |
| `lazer-wikimedia-image-seed/wikimedia-image-report.json` | Lazer Wikimedia image seeding result (attribution cross-check) | `scripts/seed-leisure-wikimedia-images.php` output (moved out of `wp-content/plugins/conexao-admin-ux/` in Stage B) |
| `2026-09-26-stage-h/` | Shared translation-rollout engine (Stage H) — baseline, engine contract tests, idempotence, PT-drift, failure proofs, numeric gate, registry and build checks | `wp-content/plugins/conexao-translation-rollout/tests/*` + local Docker verification, cited by `docs/reports/2026-09-26-stage-h-shared-rollout-engine.md` |
| `2026-09-26-stage-k/` | Agent skills + governance templates (Stage K) — the before/after skill inventory, the agent-governance gate output, the negative/failure proofs of that gate, the before/after test and acceptance summary, the Git scope audit and the final report | `python3 tests/scripts/verify-agent-governance.py` + `./scripts/run-tests.sh`, cited by `docs/evidence/2026-09-26-stage-k/2026-09-26-stage-k-agent-skills-templates.md` |
| `2026-09-26-stage-n/` | Remaining EN blog translations (Stage N) — fresh baseline inventory, the 34-post workset, the dry-run/apply guard proof, per-batch dry-run + apply + gate output, the PT immutability proof, the idempotence proof, seven negative proofs, the permanent-gate output, the before/after test summary, the HTTP acceptance summary, and the final report | `scripts/run-en-translation.php` + `python3 scripts/verify-permanent-gates.py` + `./scripts/run-tests.sh`, cited by `docs/evidence/2026-09-26-stage-n/2026-09-26-stage-n-complete-blog-translations.md` |
| `2026-09-26-stage-o/` | Real English Blog archive (Stage O) — the baseline runtime inventory, the Stage O plan, the PT pre-apply snapshot, the dry-run (proving zero writes), the apply, the PT immutability + Polylang relationship proof, the B2 allowlist transition proof, the `/en/blog/` HTTP + SEO verification, the pagination sweep, the idempotence proof, the full remove/re-apply cycle proof, eight negative proofs, the before/after test summary, the static-analysis summary, the Git scope audit and the final report | `scripts/run-en-translation.php` + `python3 scripts/verify-permanent-gates.py` + `./scripts/run-tests.sh`, cited by `docs/evidence/2026-09-26-stage-o/2026-09-26-stage-o-enable-english-blog-archive.md` |
| `2026-09-26-stage-j/` | Build, release & deploy verification (Stage J) — the 7-step local release workflow, the release-integrity gate, the Stage I script-contract gate, the release smoke-matrix acceptance run and a real `dist/release.json` | `./scripts/verify-release.sh`, `tests/scripts/verify-release-integrity.py`, `tests/acceptance/verify-release-http.py`, cited by `docs/reports/site/2026-09-26-stage-j-build-release-deploy-verification.md` |
| `2026-09-28-ci-integration-fixture/` | CI fresh-install diagnosis and isolated setup verification — original run totals, fresh Polylang setup, Jobs rollout and 16-assertion gate | GitHub Actions run 36429929963 + isolated Docker reproduction, cited by `docs/reports/site/2026-09-28-ci-integration-fixture.md` |

Historical note: the raw `stage*-work/` trees behind the Stage 1–9 reports
were removed from Git in Stage B. Their curated narrative is the report set
in `docs/reports/`; everything else was regenerable probe output.

_Last verified: 2026-09-25 by repository hygiene (Stage B)_

_Last verified: 2026-09-26 by Stage I — Scripts Standardisation_

_Last verified: 2026-09-26 by Stage O — Enable the Real English Blog Archive_

_Last verified: 2026-09-28 by CI integration setup recovery_
