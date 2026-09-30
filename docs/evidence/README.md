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

| `2026-09-30-en-translation-inventory/` | EN Translation Rollout **Phase 1** (production inventory, read-only, `Production writes: 0`, GET only) — the repository's own EN stage manifests, the classified EN manifest (2,277 objects → 430 expected EN objects, 0 conflicts), the live Polylang baseline, the PT/EN route matrix, the B2-resolution and EN-description-absence proofs, the 3-run determinism proof and the safety ledger with the PT protection digests | `docs/evidence/2026-09-30-en-translation-inventory/en-translation-inventory.py`, cited by `docs/reports/2026-09-30-en-translation-inventory.md` |
| `2026-09-30-en-rollout-blocker-resolution/` | EN Translation Rollout **blocker resolution** (read-only, `Production writes: 0`, GET only) — the repository's own stage manifests incl. the shared-slug policy read from the code, the REGENERATED manifest, the clean dry-run (newsletter target slug `newsletter`, `unresolved_slug_conflicts 0`), the 432-object snapshot and its 1:1 verification, the two-run determinism proof, the nine-row PT-source resolution table, the `newsletter` shared-slug policy record, the safety ledger and every repository gate | `en-translation-inventory.py` + `en-dry-run.py` + `dump-stage-manifests.php` (all in this directory), cited by `docs/reports/2026-09-30-en-rollout-blocker-resolution.md` |
| `2026-09-30-stage-1-auto-translation-plugin-boundary/` | Automatic PT→EN translation **Stage 1 permanent plugin boundary** (B1; `Production writes: 0`, production never contacted) — the engine integrity record (**SHA-256 `baf85283…a6ce4` before and after**, with the empty-`git diff` proof that the engine tree is untouched), the 15-case invocation matrix (1 authorised proof reaches the engine; **14 refusals each proven to refuse BEFORE the engine is entered**, 0 mutations permitted, 0 occurred), the registry/build/release evidence (the `plugins.json` entry, the release build, `dist/release.json` schema 1, and the deterministic packaging proof: 3 files, `tests/` excluded), the 132-assertion boundary suite plus the aggregate run (**64/64 suites, 4,348 assertions**; the single failing gate is the pre-existing `i18n_freshness` staleness, proven identical on a clean stashed tree), and the Stage 0 → Stage 1 blocker ledger explaining **why B1 is only partially resolved** | `./scripts/run-tests.sh` + `php scripts/generate-registry-docs.php --check` + `bash scripts/build-plugins-zip.sh` + `scripts/verify-permanent-gates.py` + `sha256sum`, cited by `docs/reports/2026-09-30-stage-1-auto-translation-plugin-boundary.md` |
| `2026-09-30-stage-0-auto-translation-architecture/` | Automatic PT→EN translation **Stage 0 architecture gate** (read-only, `Production writes: 0`, `GET` only) — the curated production plugin census (both rollout plugins now **absent**), the entry-point reachability audit (0 REST routes / 0 cron in source, 0 of 665 live routes, the live `tools.php` → 302 `wp-login.php?reauth=1` proof), the full test-run log, the engine lifecycle surface with its SHA-256 and 15 pre-write `WP_Error` sites, and the Option A vs Option B feasibility matrix with the recommended shape, open blockers and verification summary | `GET /wp-json/wp/v2/plugins` + `GET /wp-json/` + `./scripts/run-tests.sh` + `python3 scripts/verify-permanent-gates.py`, cited by `docs/reports/2026-09-30-stage-0-auto-translation-architecture.md` |

Historical note: the raw `stage*-work/` trees behind the Stage 1–9 reports
were removed from Git in Stage B. Their curated narrative is the report set
in `docs/reports/`; everything else was regenerable probe output.

_Last verified: 2026-09-25 by repository hygiene (Stage B)_

_Last verified: 2026-09-26 by Stage I — Scripts Standardisation_

_Last verified: 2026-09-26 by Stage O — Enable the Real English Blog Archive_

_Last verified: 2026-09-28 by CI integration setup recovery_

_Last verified: 2026-09-30 by the EN Translation Rollout — Phase 1 production inventory (read-only)_

## 2026-09-30 — Stage 2: engine promotion, lock and apply safety

| File | What it is |
|---|---|
| `baseline-stage1-tests.log` | The Stage 1 baseline test run, captured **before** any Stage 2 edit. The source of the `i18n_freshness` baseline comparison in §11 of the report. |
| `stage2-tests.log` | The full `./scripts/run-tests.sh` run after Stage 2: 67 in-process suites / 4521 assertions passing, with the same single pre-existing `i18n_freshness` failure. |
| `engine-digest-before.txt` | SHA-256 of all three `conexao-translation-rollout` source files **before** the promotion. |
| `engine-digest-after.txt` | The same digests **after** all Stage 2 work. Byte-identical to the before values and to the Stage 1 baseline. |
| `plugins-json-diff.txt` / `registry-diff-stat.txt` | The exact registry change: two plugin entries promoted, nothing else. |
| `promotion-gate.txt` | `tests/scripts/verify-stage2-promotion.py` — 28 checks: classification, lifecycle invariants (not weakened), dependency graph, header agreement, deterministic artifacts, engine byte-identity. |
| `registry-check.txt` | `php scripts/generate-registry-docs.php --check` — 15 plugins validated, 24 generated regions current. |
| `cache-scoping-gate.txt` | `verify-cache-key-scoping.py` after the conditional admin-user-scope exemption added in Stage 2 (report limitation 5). |
| `lint.txt` | `./scripts/lint.sh` — syntax clean, no new PHPCS violations, PHPStan level 5 clean. |

Cited by `docs/reports/2026-09-30-stage-2-engine-promotion-lock-safety.md`.
