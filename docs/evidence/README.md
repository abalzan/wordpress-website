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
| `2026-10-02-stage-21/` | Stage 21 — fix CI run `37009756493` (`PASS`, `Production writes: 0`): the full test run and its exit code (`81/81` PHP suites, 6305 assertions; `18/18` script-contract; `4/4` HTTP acceptance), the rewritten event-occurrence acceptance output, the Stage 13 and Stage 14 gate outputs **with** a local `dist/`, the lint output, the permanent-gate aggregate, the ICS fixture occurrence state before and after two deterministic import runs, and the post-type inventory proving `event publish` 29 → 32 with every other type unchanged | `./scripts/run-tests.sh` + `python3 tests/scripts/verify-*.py` + `docker compose exec … bootstrap-ci-fixtures.php --apply --verify`, cited by `docs/reports/2026-10-02-stage-21-ci-ics-fixture-and-hermetic-gates.md` |

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

## 2026-09-30 — Stage 3: change detection, provider boundary, trigger and audit

| File | What it is |
|---|---|
| `00-baseline-tests.log` | The full suite **before** any Stage 3 edit: 67 in-process suites / 4,521 assertions passing, with the single pre-existing `i18n_freshness` failure. The source of the catalogue-freshness comparison in the report. |
| `01-stage3-tests.log` | The full `./scripts/run-tests.sh` after Stage 3: **72 in-process suites (71 passed)**, 5,084 assertions, 8 script-contract suites (7 passed), 3/3 HTTP acceptance. |
| `02-lint.txt` | `./scripts/lint.sh` — `lint: OK (syntax clean, no new PHPCS violations, PHPStan clean)`. |
| `03-stage3-structural-gate.txt` | `tests/scripts/verify-stage3-automation.py` — **116 checks**: engine byte-identity, registry classification and load order, no second engine, no WordPress content write, provider interface-only, no vendor, no cron, no public route, namespaced options, no credentials. |
| `04-release-integrity.txt` | `verify-release-integrity.py` — 229/229; deterministic plugin builds. |
| `05-stage2-promotion-gate.txt` | `verify-stage2-promotion.py` — 28/28; the Stage 2 promotion invariants still hold unchanged. |
| `06-cache-scoping-gate.txt` | `verify-cache-key-scoping.py` — 2/2. The Stage 2 admin-user-scope exemption is unchanged and Stage 3 adds no cache key. |
| `07-registry-check.txt` | `generate-registry-docs.php --check` — 15 plugins validated, 24 generated regions current, zero writes. |
| `08-engine-digest-after.txt` | SHA-256 of all three `conexao-translation-rollout` source files after Stage 3. **Identical to the Stage 2 baseline.** |
| `09-stage3-suites.txt` | Per-suite results for all nine `conexao-translation-automation` suites. |
| `10-no-provider-no-cron-no-route.txt` | Source scan proving no transient/object-cache key, no outbound HTTP, no vendor, no cron and no public route in the plugin. Includes the note explaining why two grep hits are docblock prose and not executable code. |
| `11-pre-existing-failure-attribution.txt` | The controlled experiment attributing `test-en-jobs-shared-slug.php`: the identical failure reproduces with **all Stage 3 changes stashed**, so it is not a Stage 3 regression. |
| `12-digest-runtime-proof.txt` | Runtime proof of the digest contract against a **real live PT record**: same state → same digest; EN translation changed → same digest; `post_excerpt` edited → different digest; key reorder → same digest; payload excludes the B2 EN meta key. |
| `13-change-stat.txt` | The exact change set. `plugins.json` is **unchanged**, and no engine file appears. |

Cited by `docs/reports/2026-09-30-stage-3-change-detection-provider-trigger.md`.

_Last verified: 2026-09-30 by Stage 3 — change detection, provider boundary, trigger and audit_

## 2026-09-30 — Stage 4: translation provider and translation-plan adapter

| File | What it is |
|---|---|
| `00-baseline-tests.log` | The suite **before** any Stage 4 edit: 72 in-process suites with `test-en-jobs-shared-slug.php` failing. The comparison point for every number in the report. |
| `01-stage4-structural-gate.txt` | `verify-stage4-provider.py` — **110 checks**: exactly one provider implementation, the outbound request confined to the designated file, no WordPress write in any file, no cron, no public route, no second engine, no apply path, no approval state, the credential named in one file only. |
| `02-stage3-gate-after-supersession.txt` | `verify-stage3-automation.py` — **123 checks** after the single superseded assertion was narrowed. Everything Stage 3 proved still holds. |
| `03-engine-digest-before-after.txt` | SHA-256 of all three `conexao-translation-rollout` files before and after Stage 4. The engine is `baf85283…a6ce4` — **exact equality** with the baseline — and `git diff` over that plugin is **empty**. |
| `04-provider-structural-properties.txt` | Per-file scan of the four new files (comments stripped) against nine forbidden categories: no write, no Polylang, no cron, no route, no persistence, no engine vocabulary, no apply path, no self-validation, no credential literal. |
| `05-live-smoke-skips-cleanly.txt` | The live-provider smoke test skips with exit 0 both without authorisation and without a credential, makes no call, and is **not discovered** by the test runner. |
| `06-lint.txt` | `./scripts/lint.sh` — `lint: OK`. Records that 2 real PHPCS violations were found and **fixed in the code**, not baselined away. |
| `07-end-to-end-dry-run.txt` | The whole chain on a real record: provider → validator → plan (`REVIEW_REQUIRED`) → stale-source rejection → composed manifest → engine gate. |
| `08-pt-immutability-proof.txt` | PT immutability across the dry run using object accessors: 8 post fields, the B2 EN meta, **all** post meta, category terms and Polylang translations. Whole snapshot identical — zero writes. |
| `09-pre-existing-failure-attribution.txt` | The controlled experiment: `test-en-jobs-shared-slug.php` fails **identically with all Stage 4 changes stashed**, so it is not a Stage 4 regression. |
| `10-final-full-suite.txt` | The final `./scripts/run-tests.sh` with both failures attributed. |
| `11-credential-boundary.txt` | The credential boundary at runtime: fails closed with 0 HTTP calls; a credential-shaped fake appears in no observable output; it reaches the header and never the body. |

Cited by `docs/reports/2026-09-30-stage-4-provider-and-translation-plan.md`.

_Last verified: 2026-09-30 by Stage 4 — translation provider and translation-plan adapter_

## 2026-09-30-stage-5-production-commissioning-canary

Stage 5 is the first stage permitted to cross the production boundary, and the
boundary was **not crossed**: `BLOCKED`, `Production writes: 0`.

| File | Contents |
|---|---|
| `01-integrity-and-artifacts.txt` | Engine SHA-256 before and after, plus the built artifact hashes |
| `02-full-test-suite.log` | Complete `./scripts/run-tests.sh` output, including the reproduced pre-existing failures |
| `03-release-build.log` | `./scripts/build-plugins-zip.sh` output |
| `04-preconditions-and-live-probe.md` | The twelve preconditions, the entry-point scan, the live provider probe, and the transport-defect proof |

Key facts: engine `baf85283df95e80c6e1e2fccb0e1290c73f6269e290e33eb138ed2cfa36a6ce4`
before and after (exact equality); no production credential in the environment;
live provider call returned **HTTP 429 `insufficient_quota`**; and a newly proven
defect in `Provider_OpenAI::normalise()` that would make every real provider call
fail closed.

Cited by `docs/reports/2026-09-30-stage-5-production-commissioning-canary.md`.

_Last verified: 2026-09-30 by Stage 5 — production commissioning and canary_

## 2026-10-01-stage-9-production-commissioning

Stage 9 was the first stage permitted to commission infrastructure in
production. The boundary was **not** crossed: `BLOCKED`, `Production writes: 0`,
installs `0`, provider requests `0`, mutations `0`.

| File | Contents |
|---|---|
| `01-artifact-hashes-build2.txt` | SHA-256 of all 10 ZIPs from the second build — the reproducibility record |
| `02-permanent-gates.txt` | Full `scripts/verify-permanent-gates.py` output (7 gates, 6 pass, 1 carried-forward `i18n_freshness` failure) |
| `03-release.json` | The release manifest as built (`v2026.10.01`, clean tree at `d5f5f6c`) |
| `04-run-tests.txt` | Full `./scripts/run-tests.sh` output — 5600 assertions passed / 6 failed |
| `05-preconditions-and-credentials.txt` | Environment probe (names only, values never read), the `.env` local-only scope note, and the provider metadata read through the **real** `credentials_available()` / `configuration()` code path |
| `06-legacy-endpoint-closure.txt` | The `410` closure evidence in artifact and gates — **and an explicit record of what was NOT proven in production** |
| `07-source-and-artifact-preflight.txt` | Git state, engine SHA-256 before/after, two-build reproducibility, ZIP hygiene, release-metadata consistency |
| `08-gates-and-tests.txt` | The §36 required-gate matrix and the §37 test matrix, with the failing set attributed to the carried-forward conditions |

Key facts: engine SHA-256
`baf85283df95e80c6e1e2fccb0e1290c73f6269e290e33eb138ed2cfa36a6ce4`
**before and after, exact equality**; two builds **10/10 byte-identical**;
`credentials_available()` = **FALSE** through the real code path; **zero
provider requests**; and **no production credential was used, printed or
persisted**.

No file in this directory contains a credential, an application password, a
nonce, a cookie, an authorization header or a `.env` file.

Cited by `docs/reports/2026-10-01-stage-9-production-commissioning.md`.

_Last verified: 2026-10-01 by Stage 9 — production commissioning (`BLOCKED`)_

## 2026-10-01-stage-10-bounded-expansion

Stage 10 implemented and locally proved the bounded multi-record expansion
controls. It is **credential-independent**: `PASS WITH CONDITIONS`,
`Production writes: 0`, installs `0`, provider requests `0`, mutations `0`.

| File | Contents |
|---|---|
| `01-baseline-full-test-run.txt` | The full suite **before** any Stage 10 change — the comparison baseline |
| `02-stage10-batch-suite.txt` | The Stage 10 in-process suite: 17 required fixtures, 243 assertions |
| `03-negative-proofs.txt` | 20 injected-negative structural proofs, each run against a throwaway repository copy |
| `04-control-plane-and-stage-gates.txt` | Stage 2/3/4/6/7/8 gates after the extension |
| `05-engine-integrity.txt` | Engine core SHA-256 before and after |
| `06-full-test-run.txt` | The full suite **after** the change |
| `07-permanent-gates.txt` | The Stage L permanent-invariant aggregate |

Key facts: engine SHA-256
`baf85283df95e80c6e1e2fccb0e1290c73f6269e290e33eb138ed2cfa36a6ce4`
**before and after, exact equality**; **20 of 20** injected-negative proofs
held; the extended control-plane gate asserts **141** checks with **0** failed;
`MAX_PRODUCTION_BATCHES_PER_INVOCATION` is **1**; **zero** endpoints were
registered; and **no production credential was used, printed or persisted**.

No file in this directory contains a credential, an application password, a
nonce, a cookie, an authorization header or a `.env` file.

Cited by `docs/reports/2026-10-01-stage-10-bounded-expansion.md`.

_Last verified: 2026-10-01 by Stage 10 — bounded multi-record expansion controls (`PASS WITH CONDITIONS`)_

---

## `2026-10-01-stage-11-model-a-scope-contract-and-batch-control/`

| File | Content |
|---|---|
| `00-engine-baseline.txt` | the shared engine's SHA-256 recorded **before** any Stage 11 edit |
| `01-scope-gap-trace.txt` | the §3 trace: the exact line where the approved subset was lost |
| `02-model-a-decision-gate.txt` | the §2 feasibility path and its ten answers, written before implementation |
| `03-model-a-suite.txt` | the 145-assertion Model A suite |
| `04-stage10-regression.txt` | the Stage 10 suite re-run after Stage 11 (243 assertions) |
| `05-control-plane-and-gates.txt` | every gate re-run after the change |
| `06-engine-integrity.txt` | §33 starting digest, ending digest, and the intentional diff |
| `07-release-consistency.txt` | §34 two independent builds, artifact hashes, release manifest |
| `08-full-test-run.txt` | §36 the full suite after the change |
| `09-baseline-classification.txt` | §35 the baseline failing suites, separately classified |
| `10-permanent-gates.txt` | the Stage L permanent-invariant aggregate |

No file in this directory contains a credential, an application password, a
nonce, a cookie, an authorization header or a `.env` file.

Cited by `docs/reports/2026-10-01-stage-11-model-a-scope-contract-and-batch-control.md`.

_Last verified: 2026-10-01 by Stage 11 — Model A scope contract and batch control (`PASS WITH CONDITIONS`)_

---

## `2026-10-01-stage-12-production-commissioning-model-a-canary/`

| File | Content |
|---|---|
| `01-environmental-prerequisite-gate.txt` | §3 the environmental gate: presence flags only, the three permissions judged independently, the read-only reachability probe, and why the local Docker and unrelated `OPENAI_API_KEY` credentials were not used |
| `02-source-and-artifact-preflight.txt` | §4 git status, the full engine/automation/rollout SHA-256 recomputed from the tree, and the plugin headers |
| `03-artifact-hashes-build-twice.txt` | §5 both builds, artifact hashes, header/registry/release agreement, dependency ordering, and the secret, test-file and Docker-file scans |
| `04-control-plane-legacy-endpoint-emergency-stop.txt` | §§7–10 the control-plane registry, the batch-control action and owning file, the security contract, the forbidden-surface counts, the `410` source, and the emergency-stop fail-closed semantics |
| `05-required-tests.txt` | §38 the preflight gate results, suite by suite |
| `06-baseline-classification.txt` | §35 the Stage 12 failure set beside Stage 11's, and each known condition named |
| `07-model-a-production-proof.txt` | §§2/21/28 why the production Model A proof is `NOT PERFORMED`, and what remains locally proven |
| `08-engine-integrity.txt` | §36 the before/after SHA-256 and the corroborating assertion |
| `09-steps-not-performed.txt` | §§11–34 every unperformed step with its reason, each reported as `NOT PERFORMED` |
| `10-full-test-run.txt` | the full suite summary |
| `11-permanent-gates.txt` | the Stage L permanent-invariant aggregate |

No file in this directory contains a credential, an application password, a nonce, a
cookie, an authorization header or a `.env` file.

Cited by `docs/reports/2026-10-01-stage-12-production-commissioning-model-a-canary.md`.

_Last verified: 2026-10-01 by Stage 12 — production commissioning and the Model A canary (`BLOCKED`)_


---

## `2026-10-01-stage-13-commissioning-readiness/`

| File | Content |
|---|---|
| `01-commissioning-readiness-report.txt` | The aggregate readiness report: `COMMISSIONING READINESS: BLOCKED`, all eighteen prerequisites with their own status and reason, and the derived `commissioning_permitted` / `production_mutation_permitted` / `canary_selection_allowed` / `emergency_stop_clearance` / `control_plane` / `model_a_production_proof` / provider lines |
| `02-commissioning-readiness-report.json` | The same report machine-readable, including every prerequisite's declared evidence source, observed provenance, freshness rule and detail |
| `03-operator-runbook.md` | The 19-step commissioning runbook, GENERATED from the prerequisite registry (not hand-maintained), each step naming the prerequisites it requires and the ones it clears |
| `04-permanent-gate-stage13.txt` | The permanent Stage 13 gate: 768 assertions and 34 injected-negative proofs, with the `GATE-RESULT` line |
| `05-environment-and-credentials.txt` | Environment **presence** flags (names only), the explicit refusal of the unrelated `OPENAI_API_KEY`, and the local Docker `.env` classification — the file Stage 13 never opens |
| `06-authorization-separation.txt` | The three-permission separation table, the authorization evidence schema, the server-side expiry rule, and the nine ordered-pair proofs |
| `07-artifact-and-engine-integrity.txt` | Both builds with their SHA-256s, header/registry/release agreement, the secret/test/local-file scans, all eight batch ceilings against the actual configured limits, and the control-plane state |
| `08-negative-proofs.txt` | Every injected failure and its result, with the positive control asserted green first, and the note that four real gate defects were found by running these proofs |
| `09-prerequisite-registry.txt` | The eighteen-entry registry with evidence source, freshness, Stage 12 mapping and current status, plus the `READY 1 / BLOCKED 17` tally |
| `10-safety-properties.txt` | The eight structural reasons readiness cannot mutate production or invoke the provider |
| `11-tests-and-baseline.txt` | Every command and result, and the four known baseline conditions carried forward unchanged |

No file in this directory contains a credential, an application password, a nonce, a cookie, an authorization header, a
`.env` file or an environment dump. Every file was scanned for secret shapes.

Cited by `docs/reports/2026-10-01-stage-13-commissioning-readiness.md`.

_Last verified: 2026-10-01 by Stage 13 — the commissioning-readiness gate (`PASS`; commissioning readiness `BLOCKED`, production writes 0)_

---

## `2026-10-01-stage-14-secret-hardening-production-canary/`

Stage 14 — Gate A (secret-scan hardening) complete and proven; Gate B (production
commissioning and the Model A canary) **NOT PERFORMED**. Production writes `0`.

| File | Content |
|---|---|
| `01-secret-scan-gate.txt` | The permanent Stage 14 gate: PHP↔Python conformance over the 27-case corpus (0 mismatches), 11 secret shapes refused, 16 legitimate values allowed, redaction, 8 container shapes, 1 552 source files scanned, 17 declared fixtures each confined to `tests/`, 10 ZIPs + release record scanned, engine digest. `323 passed, 0 failed` |
| `02-secret-scan-gate-double-build.txt` | The same gate with `--build`: **9 artifacts byte-identical across two consecutive builds**, zero credential-shaped values in any rebuilt ZIP. `344 passed, 0 failed` |
| `03-lint.txt` | `./scripts/lint.sh` — syntax clean, no new PHPCS violations, PHPStan level 5 clean. Legacy warnings 2 672 → 2 668 |
| `04-full-suite-summary.txt` | `./scripts/run-tests.sh` per-suite PASS/FAIL list and the three aggregate lines |
| `05-full-run-tests-output.txt` | The complete untruncated suite output |
| `06-stage13-readiness-gate.txt` | The Stage 13 permanent gate re-run against the hardened scanner: `768 passed, 0 failed`, 34/34 injected-negative proofs |
| `07-defect-before-after.txt` | The Stage 13 defect reproduced against the PRE-fix scanner (`sk-`/PEM/`Authorization` all `MISSED`) beside the post-fix proof |
| `08-suite-secret-scan.txt` | The focused PHP suite: negative, positive, redaction, containers and no-regression sections. `113 passed, 0 failed` |
| `09-suite-provider-secrets.txt` | The provider-containment regression, run against a fake transport (no provider call). `17 passed, 0 failed` |
| `10-engine-integrity.txt` | Engine SHA-256 before and after Stage 14, with the empty `git diff` over `conexao-translation-rollout/` |
| `11-commissioning-readiness-recheck.txt` | The readiness gate re-run in this environment: `BLOCKED`, all eighteen prerequisites `BLOCKED`, and the explicit refusal to alias `OPENAI_API_KEY` |
| `12-artifact-hashes.txt` | Exact SHA-256 of all ten artifacts, the release record, the production dependency order and the two translation-plugin versions |
| `13-verify-release.txt` | `./scripts/verify-release.sh --skip-http` — all 7 stages, including the determinism proof and the no-tests-in-artifacts proof |
| `14-provider-credential-handling.txt` | Credential handling: presence-only checks, the approved variable absent, `OPENAI_API_KEY` present and deliberately unused, `.env` never opened |
| `16-intermediate-failure-investigation.txt` | An intermediate full-suite failure (`test-error-handling.php`) investigated to its root cause: accumulated LOCAL DB state against `MAX_ENTRIES = 100`, not a code regression. Stage 14 changed nothing in the event importer. Verified by clearing the saturated option (`55 passed, 0 failed`) |
| `17-final-suite-result.txt` | The definitive full-suite result on a clean local state: 79/81 in-process, 6 123 assertions, 15/16 script, 2/3 acceptance — identical to the baseline, **no new failure** |
| `18-i18n-freshness-new-label-explained.txt` | Why the Stage L aggregate labels one of the three known stale catalogues `new`: the gate reads GIT commit timestamps (Stage 14 is uncommitted and therefore invisible to it), the catalogues date from 2026-09-29, and the `new` label comes from a baseline list that omits `conexao-content`. Not a Stage 14 regression |
| `15-gate-b-not-performed.txt` | The explicit record that Gate B was not entered, with each absent prerequisite and the resulting `NO` for every production action |

Every file here was scanned by the Stage 14 gate itself (1 568 files across the source tree, including this directory). The declared test
fixtures in this directory are deliberate, obviously fake, and confined to
`tests/`; no real credential, application password, nonce, cookie, authorization
header, `.env` or environment dump appears in any of them.

Cited by `docs/reports/2026-10-01-stage-14-secret-hardening-production-canary.md`.

---

## `2026-10-02-stage-17-retirement/`

| File | What it proves |
|---|---|
| `01-baseline-full-test-run.txt` | the full suite **before** the change, on `d4af7d2` plus uncommitted Stage 16 — the regression baseline |
| `02-final-full-test-run.txt` | the full suite **after**: 81 in-process suites / 6,118 assertions passed / 6 failed, 17 script-contract, 3 HTTP acceptance |
| `03-engine-integrity.txt` | the shared engine SHA-256 `264cc6c4…6912` and an **empty** `git diff` over `conexao-translation-rollout` — Stage 11 Model A preserved |
| `04-retirement-gate.txt` | `verify-stage17-retirement.py`: **110 passed, 0 failed** — the broker, the wake-up hook, the scheduler, the anonymous surfaces and the `hook`/`scheduled` vocabulary are all proven absent, and the manual path plus every safeguard proven present |
| `05-stage13-readiness-gate.txt` | the commissioning-readiness gate after the retirement: **808 passed, 44 negative proofs, 0 missed** |
| `06-retirement-gate-mutations.txt` | the **9 injected mutations**, every one failing closed, source restored and re-verified green after each |
| `07-manual-workflow-e2e.txt` | the retained manual workflow end to end: **49 passed, 0 failed** — manual request → inventory → manifest → dry-run → snapshot → approval → apply → verify → idempotence, with measured numbers |
| `08-readiness-preflight-retired.txt` | the preflight reporting `RETIRED` and exiting 0 — a definitive answer, not an enabled capability |
| `09-manual-operator-runbook.md` | the generated ten-step **manual** operator runbook, replacing the automatic-commissioning runbook |
| `10-lint.txt` | `lint: OK` — syntax clean, no new PHPCS violations, PHPStan level 5 clean |

Cited by `docs/reports/2026-10-02-stage-17-retire-automatic-translation.md`.

---

## `2026-10-02-stage-18-operator-runbook/`

Stage 18 documents the retained manual translation workflow for an operator and
then **executes the documented procedure locally**. Production writes `0`.

| File | What it proves |
|---|---|
| `01-dry-run-en-post.txt` | the runbook's §7 command, executed: `--dry-run --only=post` → `GATE PASS: eligible PT=34 with EN=34 missing EN=0 conflicts=0 PT drift=0`, exit 0 |
| `02-dry-run-all-stages.txt` | every stage, executed: 6 of 7 gates PASS; `en-leisure-description` **FAILS** (`missing EN=1 conflicts=1`) and the runner exits non-zero — the PT-drift guard refusing the stale `dwyer-mcallister-cottage` translation. Reported, not hidden. |
| `03-runbook-gate.txt` | `verify-stage18-operator-runbook.py`: **111 passed, 0 failed** — the runbook checked against the code it describes |
| `04-gate-mutations.txt` | the **15 injected mutations**, every one failing closed, with a vacuity check so a no-op edit cannot be reported as a pass |
| `05-manual-workflow-suite.txt` | the manual-workflow suite, **66 passed, 0 failed** (49 + the 17 new "every promised metric is a real field" assertions) |
| `06-lint.txt` | `lint: OK` |
| `07-full-test-run.txt` | the full suite after the change |

Cited by `docs/reports/2026-10-02-stage-18-manual-translation-operator-workflow.md`.

## `2026-10-01-stage-15-final-production-commissioning-canary/`

Stage 15 — an **operational execution** stage, stopped at its first gate.
The Stage 13 readiness gate returned `BLOCKED` in this environment, so no
production step was entered. Production changes `0`.

| File | Content |
|---|---|
| `01-readiness-gate.txt` | The §2 absolute rule: the readiness gate run **in this environment**, `COMMISSIONING READINESS: BLOCKED`, all eighteen prerequisites, `commissioning_permitted=false`, `production_mutation_permitted=false`, `emergency_stop_clearance=NOT_GRANTED`, exit 1, plus the machine-readable `production_actions` counters |
| `02-authorizations.txt` | Credentials observed by **NAME only** (`CONEXAO_TRANSLATION_PROVIDER_KEY` absent; `OPENAI_API_KEY` present and deliberately unused and unaliased; `.env` never opened) and every authorization **independently** `ABSENT` — installation, provider-call, canary, apply, commissioning, batch-control, reviewer — with the Stage 13 negative proofs making non-inference structural rather than promised |
| `04-artifact-preflight.txt` | Exact git state (branch `i18n`, HEAD `7c99346`, **clean tree**), engine SHA-256, both plugin versions agreeing across header/registry/release, load order and dependency, registry check, **artifacts built twice and byte-identical** (10/10), exclusion proof (no tests, no Docker files), release metadata verification, and the source + artifact secret scan |
| `05-steps-not-performed.txt` | The `NOT PERFORMED` record for §6–§33 — every production step named, none written as "passed", with an explicit "not claimed anywhere" list and `Production changes = 0` |
| `06-tests-preproduction.txt` | The credential-independent half of the §37 matrix: lint, registry, Stage 14 secret-hardening gate (323), Stage 13 readiness gate (**771 with 44/44 negative proofs**), Model A (145), batch control (243), control plane (149), legacy closure (36) and the remaining stage gates |
| `07-artifact-facts-control-plane.txt` | Artifact-level properties only — the two declared automation surfaces, `register_rest_route()` 0, anon AJAX 0, webhook 0, cron 0, legacy closure 36, batch control `DECLARED / NOT COMMISSIONED` with its security contract, fail-closed emergency stop, provider config. Every row is explicitly **not** a production claim |
| `08-full-suite.txt` | `./scripts/run-tests.sh` totals (**79/81** suites, **6123 passed / 6 failed**), the failing suites mapped to the §34 carried-forward conditions, the Stage L permanent gates (**6/7**, `i18n_freshness` red), and the disclosed investigation of a spurious `model-a` failure caused by my own concurrent test activity |
| `09-p15-emergency-stop.txt` | §8 P15 verification: **NOT PERFORMED**, no stop-state evidence fabricated, why it cannot be produced without production access, the **artifact** fail-closed table read from source, `emergency_stop_clearance=NOT_GRANTED` / `emergency_stop_clears=0`, and the §32/§33 final state (bulk, next batch, autonomous approval, cron, automatic progression all OFF) |
| `10-p15-fix.txt` | **Addendum — the P15 defect and its fix**, plus the Stage 15 independent re-verification: the validator at `commissioning.py:956-1004` reads production evidence and requires the repository fail-closed fact **first**, the permanent gate's 44 negative proofs, and the confirmation that the fix is intact without unblocking anything |

No credential, application password, nonce, cookie, authorization header, `.env`
or environment dump appears in any file here — presence flags carry **names
only**. The Stage 14 gate scans this directory along with the rest of the source
tree and reported `0` undeclared credential-shaped values.

Cited by `docs/reports/2026-10-01-stage-15-final-production-commissioning-canary.md`.

_Last verified: 2026-10-01 by Stage 15 — final production commissioning and canary (`BLOCKED` at the §1 readiness gate; production changes 0; engine integrity before == after)_

---

## `2026-10-01-stage-15-personal-production-commissioning-model-a-canary/`

Stage 15 — the **Personal-plan** operational commissioning attempt. Status
**`BLOCKED`**, and blocked for a *new and better-understood* reason than the
preceding attempts: the §4 provider-path investigation was actually performed
and **Path A was rejected on evidence**, and a genuine repository gap was
found in the P15 stop-state read surface. Production changes `0`.

| File | Content |
|---|---|
| `01-readiness-gate.txt` | The Stage 13 readiness gate re-run **in this environment**: `COMMISSIONING READINESS: BLOCKED`, all eighteen P01–P18 statuses, `commissioning_permitted=false`, `production_mutation_permitted=false`, `canary_selection_allowed=false`, `emergency_stop_clearance=NOT_GRANTED`, `model_a_production_proof=NOT_PROVEN`, `provider.key_present=false`, `provider.quota=NOT_PROVEN`, `provider.requests_from_this_gate=0`, exit 1 |
| `02-personal-plan-and-provider-path.txt` | **The stage's central investigation.** (A) The WordPress.com Personal capability boundary, quoted from vendor sources fetched during the run: plugin **ZIP upload via wp-admin IS available**; SFTP/SSH, WP-CLI, Git/GitHub Deployments and real-time backups are **Business/Commerce**. (B) The §4 provider path: **Path A REJECTED** on the quoted source of `_wp_connectors_get_api_key_source()` (env → constant → **database**) plus the documented Connectors "paste key → Save" UI and the `connectors_ai_{provider}_api_key` option name from plugin-check#1342 — i.e. the connector key **is a `wp_options` row**, which violates Stage 0 control S7; two further independent blockers (our fixed-model/ceiling/identity contract is not expressible through the AI Client, and the enabling WordPress 7.0 version is **not observable** anonymously). **Path B** not available. **⇒ Path C, `BLOCKED`**, with the key placed in nothing. (C) Anonymous read-only production observations |
| `03-credentials-and-authorizations.txt` | Credentials by **NAME only** (`CONEXAO_TRANSLATION_PROVIDER_KEY` absent; `WP_USERNAME`/`WP_APPLICATION_PASSWORD` absent and — per §3 — correctly **not** treated as a deployment prerequisite; `OPENAI_API_KEY` present, **not used, not aliased**; `.env` never opened) and every authorization **independently ABSENT**. Records explicitly that reachability and the `/wp-admin/` 302 are **observation only**, not access and not authorization |
| `04-artifact-preflight.txt` | Git state, engine SHA-256, both plugin versions agreeing across header/registry/release, dependency order, registry check, **double build byte-identical**, exclusion proof, release verification, secret scan and lint — with the explicit note that the artifacts are **proven ready for upload but were not uploaded** (P02 absent) |
| `05-p15-emergency-stop.txt` | §15: **NOT PERFORMED**, no stop-state object fabricated, why it cannot be produced — plus the stage's **second substantive finding**: the emergency stop's only runtime consumer is `assert_may_start()`, so there is **no admin display, REST route, AJAX action, cron trigger or diagnostics endpoint** that reads it. Even a successful manual login cannot read it, so P15 is **`NOT OBSERVABLE THROUGH PERSONAL PLAN`** — a repository gap, not a hosting gap. The artifact half is proven fail-closed |
| `06-lint.txt` | `./scripts/lint.sh` — PHP syntax (527 files), PHPCS baseline gate, PHPStan level 5, all `OK`, exit 0 |
| `07-legacy-410-and-control-plane.txt` | §12/§13: the required authenticated **`410` was NOT obtained**; the anonymous `400` is explicitly **not** offered as a substitute, and no POST / `mode=apply` was ever issued. The anonymous control-plane half **is** verified clean (no translation REST namespace among 23, no anonymous admin-post, no webhook, no cron trigger). The authenticated half is recorded as not observable without a session. Batch control re-confirmed `DECLARED / NOT COMMISSIONED` from source |
| `08-preproduction-tests.txt` | The §42 before-production matrix: full-suite totals, every failure mapped 1:1 to a §40 carried-forward condition with nothing reclassified, all Stage-15-required suites passing (Model A 145, batch 243, Stage 13 gate 771, Stage 14 gate 323, control plane 149, legacy closure 36, release integrity 229, documentation drift 14, governance), Stage L permanent gates **6/7** with `i18n_freshness` red, lint and registry |
| `08-full-suite.txt` | Raw `./scripts/run-tests.sh` output for this run |
| `09-steps-not-performed.txt` | The `NOT PERFORMED` record for every operational step of §9–§38, each with its absent prerequisite; readiness re-run, bulk/batch/cron/autonomous flags all OFF, counters all `0` |
| `10-engine-integrity.txt` | §41: engine SHA-256 **BEFORE == AFTER == EXPECTED** `264cc6c4…16912`, `git diff HEAD` over the whole engine directory **empty** — the engine was not modified |

No credential, application password, nonce, cookie, authorization header, `.env`
or environment dump appears in any file here — presence flags carry **names
only**, and no `connectors_ai_*` option was ever read or written.

Cited by `docs/reports/2026-10-01-stage-15-personal-production-commissioning-model-a-canary.md`.

_Last verified: 2026-10-01 by Stage 15 — Personal production commissioning and Model A canary (`BLOCKED`; Path C after an evidence-based rejection of Path A; P15 `NOT OBSERVABLE THROUGH PERSONAL PLAN`; production changes 0; engine integrity before == after)_


_Last verified: 2026-10-01 by Stage 14 — secret-scan hardening and production commissioning (Gate A `PASS`; Gate B `NOT PERFORMED`; commissioning readiness `BLOCKED` 18/18, production writes 0)_
