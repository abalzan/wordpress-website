# Stage H — Shared Translation Rollout Engine

**Stage:** H of the standardisation roadmap (`docs/engineering-standard.md`
§15; audit §Phase 6 row H). **Depends on:** Stage E (test harness) and Stage G
(registry). **Scope:** one new shared rollout-engine plugin + the migration of
**exactly one** existing retired rollout (`conexao-job-translation`) onto it.
**No production request, no production write, no production content change, no
Polylang/REST/CPT/taxonomy/routing change, no Flutter/mobile change.**
**Status:** PASS WITH LIMITATION (§13 — `./scripts/lint.sh` cannot complete on
this host; its three steps were run directly and equivalently, and the one
failing check is a pre-existing baseline drift that Stage H *reduces*).

---

## 1. Baseline

| Measure | Value |
|---|---|
| Branch | `i18n` |
| Stage G final SHA / Stage H start SHA | `7144d79bbbc8c4615c961966d86527b8eb0f9361` |
| Working tree at start | **clean** (`git status --short` empty) |
| Registry | 12 plugins validated, 21 generated regions current (`--check` exit 0) |
| Existing translation-rollout plugins | **5** (page, blog, job, leisure, guide — all `class: rollout`, `status: retired`, `build: false`) |
| Total rollout LOC (all five, incl. authored data) | **10 905** |
| Total *orchestration* LOC (bootstrap + apply + audit + admin, excl. data) | **3 372** |
| Rollout-specific tests before Stage H | **0** (no `wp-content/plugins/conexao-*-translation/tests/`) |
| Test baseline (full, in-process) | 46 suites — 31 passed, **15 failed**; 3 244 assertions passed, 59 failed |
| Test baseline (theme) | 25 suites — 14 passed, 11 failed |
| Test baseline (acceptance) | 2 suites — 2 passed, 0 failed |
| Local environment | Docker WordPress live at `http://localhost:8080` (200), Polylang active |

Orchestration LOC per rollout (bootstrap + apply/apply-* + audit + admin):

| Plugin | Files | LOC |
|---|---|---|
| conexao-page-translation | bootstrap 194 + apply 397 | 591 |
| conexao-blog-translation | bootstrap 190 + apply 645 + audit 187 | 1 022 |
| **conexao-job-translation** | bootstrap 203 + apply 400 + audit 163 | **766** |
| conexao-leisure-translation | bootstrap 201 + apply 247 + audit 107 | 555 |
| conexao-guide-translation | bootstrap 83 + apply 74 + apply-2a 22 + apply-2b1 16 + apply-2b2 110 + audit 71 + admin 62 | 438 |

Duplicated generic operations found across the five rollouts (all five
implement their own copy): inventory/live-state discovery, dry-run branch,
PT snapshot capture, PT drift comparison, apply traversal, EN create, EN slug
collision gate, Polylang link + both-directions verification, translation
metadata/field copy, EN-without-PT scan, completeness counter table, numeric
gate, per-stage admin screen with capability + nonce, per-stage report
formatting, and a per-stage WP-CLI runner.

---

## 2. Architecture before

`conexao-job-translation` (766 orchestration LOC) owned the **entire**
lifecycle in its own files:

| File | LOC | Duplicated responsibility |
|---|---|---|
| `conexao-job-translation.php` | 203 | loader + `Conexao_Job_Translation_Admin` (cap, nonce, menu, report rendering) |
| `includes/apply.php` | 400 | `conexao_job_translation_run()` — inventory, dry-run flag, snapshot capture, apply loop, slug-collision gate, Polylang link, field copy, PT regression gate |
| `includes/audit.php` | 163 | `conexao_job_translation_audit()` — completeness counters + numeric gate |
| `includes/translation-map.php` | 96 | authored EN data (the only thing that *should* have been there) |
| `scripts/stage6-job-translate.php` | 81 | WP-CLI runner coupled to `conexao_job_translation_run()` |

Concretely: `apply.php:171-400` was the generic run loop, `apply.php:37-58` the
generic snapshot shape, `apply.php:559-581` the generic PT-drift gate,
`audit.php:29-143` the generic inventory+gate, and the admin class the generic
capability/nonce/render flow. **None of that is job-specific.**

---

## 3. Architecture after

```text
shared engine  wp-content/plugins/conexao-translation-rollout/
    ├── conexao-translation-rollout.php            loader
    ├── includes/class-conexao-translation-rollout-engine.php   lifecycle
    ├── includes/class-conexao-translation-rollout-admin.php    Tools screen
    ├── languages/                                  (empty; no runtime strings)
    └── tests/                                      3 suites, 46 assertions
        ↓ register_stage( config )   [pure in-memory, zero writes]
stage config  conexao-job-translation/includes/stage-config.php   (186 LOC)
        ↓ manifest_callback
stage data    conexao-job-translation/includes/translation-map.php (96 LOC, UNCHANGED)
```

### Ownership boundary

| Concern | Owner |
|---|---|
| inventory, live-state discovery | **engine** `collect_states()` |
| manifest validation (fail-closed) | **engine** `validate_manifest()` |
| deterministic dry-run plan (`create`/`update`/`skip`/`conflicts`) | **engine** `build_plan()` |
| snapshot capture orchestration | **engine** `capture_snapshots()` |
| apply traversal, idempotence, match-strategy counts | **engine** `apply_plan()` |
| PT-drift guard | **engine** `assert_no_pt_drift()` |
| verify counters, numeric gate | **engine** `collect_verify()` + `calculate_gate()` |
| result formatting, admin capability/nonce flow, remove traversal | **engine** (admin class) |
| authored EN copy, portable stable keys, stage identity, languages | **stage** |
| job field mapping (`_job_*`, thumbnail), eligibility, landing-page check, remove-safety declaration | **stage** |

The engine contains **no** translated titles or bodies, **no** stage record
list, **no** inline translation payload and **no** production identifier. That
was verified by inspection of the 891-line engine: its only literals are
error codes, messages, plan reasons and gate keys.

### Admin workflow

Tools → **Translation Rollouts** (shared). Stage selector is limited to
registered stages; **Preview (dry run)** and **Apply** (and **Remove** for
stages that declare `allow_remove`); capability `manage_options` +
`check_admin_referer()` on every write path; no writes on page load or GET.

### Rollback / remove contract

`mode => 'remove'` returns `WP_Error` and performs **zero** writes unless the
stage declares `allow_remove => true`. The migrated Job stage declares
`allow_remove => false` — see §4 for why — so the engine refuses a destructive
remove rather than claiming a reversibility it cannot deliver.

---

## 4. Selected rollout migration

**Selected: `conexao-job-translation` (Stage 6).**

Why this one, on evidence:
- **Clearest data/orchestration split**: its authored data is a single
  96-LOC `translation-map.php`; the other four mix data and orchestration
  (`page` 368-LOC map, `blog` a 2 056-LOC map interleaved with 645 LOC of
  apply, `leisure` a 2 320-line JSON manifest with a description-only model,
  `guide` ~70 body files plus four apply fragments).
- **Manageable mapping contract**: one post type, one field layer
  (`_job_*` verbatim), one eligibility rule (published PT job), one
  gate-input pair.
- **Its historical semantics are fully expressible declaratively**: identity
  is the PT slug, the field policy is "translate 4 fields + copy the rest",
  and the landing-page check is a *verify-only* gate input.
- **Migratable without changing historical content data**: the manifest
  needed only an adapter wrapper — the authored bytes were never touched
  (asserted byte-equivalence in the test suite).
- The other four are *not* equivalent candidates today: `blog`/`page` need
  internal-link localisation and a shared-slug filter, `leisure` is a
  meta-only model with a different (already-safe) remove, and `guide` is
  spread over four `apply-*.php` fragments — none of which a first engine
  contract should be designed around.

| | Before | After |
|---|---|---|
| Orchestration LOC | **766** | **316** (−450, **−58.8 %**) |
| `includes/apply.php` | 400 | **deleted** |
| `includes/audit.php` | 163 | **deleted** |
| `Conexao_Job_Translation_Admin` | ~150 | **deleted** |
| `includes/translation-map.php` | 96 | 96 (**unchanged**) |
| `includes/stage-fields.php` | — | 159 (new; stage-specific mapping) |
| `includes/stage-config.php` | — | 186 (new; declarative config) |
| `conexao-job-translation.php` | 203 | 71 (loader + registration) |

Retained, **stage-specific**, each verified byte-identical to its pre-Stage-H
body:

| Function | Why it belongs in the stage, not the engine |
|---|---|
| `conexao_job_translation_snapshot_job()` | the exact PT field list to protect (name/title/content/excerpt/status/date/author/menu_order/thumbnail/`conexao_*` terms/**every** `_job_*` meta/Polylang language/meta description) — content-model knowledge, not orchestration |
| `conexao_job_translation_copy_fields()` | the verbatim `_job_*` layer + shared featured image — a job field policy, not a generic copy |
| `conexao_job_translation_verify_jobs_page()` | Stage 4.5 owns the Jobs landing pages; this only *verifies* the pair — a job-specific gate input |
| `conexao_job_translation_pair_ok()` | the both-directions check, kept as the stage's own assertion helper used by the adapter |

Also updated (repository scripts directly required by the migration):
`scripts/stage6-job-translate.php` became a thin engine driver (no
orchestration), and `scripts/stage6-job-inventory.php` now reports the
engine's numeric gate instead of the deleted audit function.

**The other four retired rollouts are byte-for-byte untouched** — verified
with `git status --short` over all four plugin directories (empty output).

---

## 5. Content-change contract

| Step | Engine entry point | Writes | Guarantee |
|---|---|---|---|
| 1. Inventory | `run()` → `collect_states()` | none | stable key, PT record, status, EN relation, pair link, EN slug match, slug collision, match-strategy counters |
| 2. Manifest | `validate_manifest()` | none | fails closed on missing key, language mismatch, malformed row, missing required field, **duplicate stable identifier** |
| 3. Dry-run plan | `build_plan()` | none | `create`/`update`/`skip`/`conflicts`, each with a `reason`; deterministic (asserted) |
| 4. Snapshot | `capture_snapshots()` | none | every affected PT record captured before the first write, from the stage's field list |
| 5. Apply | `apply_plan()` / `apply_removals()` | explicit only | idempotent; every write reports its match strategy with per-strategy counts (`match.stable-id`/`slug`/`title`) |
| 6. Verify + gate | `collect_verify()` + `calculate_gate()` | none | numeric counters + machine-readable `gate.json` |

Dry-run performs **zero writes by construction** — the apply path is not
entered when `dry_run => true` (asserted in two suites).

**PT-drift guard:** `assert_no_pt_drift()` re-captures every snapshotted PT
record after the run and reports `PT SOURCE CHANGED: <fields>` for any
difference. The single tolerated delta is a Polylang language backfill
(`'' → 'pt'`) on a record that had no language — preserved from the original
Stage 6 behaviour.

**Numeric gate:** `PASS` requires `missing_en = 0` **and** `conflicts = 0`
**and** `pt_drift = 0` **and** `extra_failures = 0` (the extra counter folds
in the stage's `verify_landing_callback` and `extra_gate_callback`). A textual
"looks good" result is not produced anywhere.

---

## 6. Idempotence

Isolated local Docker DB, disposable synthetic `job` fixtures (unique slugs per
run), removed afterwards.

| Run | created | updated | skipped | conflicts |
|---|---|---|---|---|
| first apply | **2** | 0 | 0 | 0 |
| second apply | **0** | **0** | 2 | 0 |

No duplicate EN posts, no duplicate translation links, no duplicate terms or
meta. The job namespace returned to its exact pre-test ID set (asserted:
`fixtures fully removed; job namespace restored`; verified afterwards: 0
`stageh-%` rows, 2 job records = the pre-existing PT job + its pre-existing EN
translation).

On the **real** migrated stage (local site, dry-run only, no writes):
`created=0 updated=0 skipped=1 conflicts=0 match stable-id=1 slug=0 title=0`.

---

## 7. PT-drift protection

Live local proof (`test-rollout-failure-proofs.php`):

1. inventory target + snapshot the PT field set;
2. mutate a protected PT field (`post_title` → `"Stage H proof MUTATED"`);
3. re-snapshot and compare.

Result: **1 changed field detected** (`post_title`), reported as
`PT SOURCE CHANGED`, `pt_changed = 1`, `errors` incremented, and the gate
forced to **FAIL**. The guard is the engine's `diff_snapshots()` +
`assert_no_pt_drift()` — the guard was not weakened to pass the test; the test
was built around the guard.

---

## 8. Failure proofs

Every defect below was injected, observed, and reverted. Final tree contains
none of them.

| # | Injected defect | Observed result | Writes |
|---|---|---|---|
| A | invalid stage config (`stage => 'BAD STAGE'`, no callbacks) | `run()` → `WP_Error conexao_rollout_bad_config` | 0 |
| B | malformed manifest (row missing `en_slug`) | `run()` → `WP_Error conexao_rollout_bad_manifest` | 0 |
| C | duplicate stable identifier (two rows, same `en_slug`) | `validate_manifest()` → `WP_Error`, "Duplicate en_slug ... (zero writes)" | 0 |
| D | PT drift (protected field mutated between snapshot and apply) | 1 `PT SOURCE CHANGED` row, `pt_changed=1`, gate FAIL | 0 additional |
| E | non-idempotent second apply | `create=0, update=0` (2 records skipped) | 0 |
| F | missing gate condition (`missing_en=1`) | gate `FAIL` — no false PASS | 0 |
| G | admin action without capability (anonymous request) | `current_user_can('manage_options')` false → blocked before any write; job count 2 before / 2 after | 0 |
| H | admin action with missing/invalid nonce | `check_admin_referer()` dies ("Este link expirou"), exit 1 | 0 |
| R1 | registry: nonexistent documentation path | `--check` exit 1; `build-plugins-zip.sh` refuses (exit 1) | — |
| R2 | registry: engine `build: true` (drift) | `--check` exit 1 listing 8 stale generated regions; build refuses | — |

---

## 9. Registry

`plugins.json` was edited (it is authoritative); every generated surface was
produced by the Stage G generator only.

| Field | Value | Why |
|---|---|---|
| slug | `conexao-translation-rollout` | |
| class | `tooling` | maintainer tooling, not a one-shot rollout, not a production runtime plugin |
| status | `active` | the engine is a current capability |
| production | `false` | never a production steady-state dependency |
| build | `false` | it is rollout-adjacent tooling: locally mounted, deployable when an actual rollout requires it, not part of a normal release |
| mount | `true` | needed for local development + tests |
| dependencies | `[]` | no `Requires Plugins` header, so no edge may be invented |
| documentation | `docs/plugins/conexao-translation-rollout.md` | |

Position: inserted **after** the local tooling and **before** the retired
rollouts, matching the existing grouping.

- `php scripts/generate-registry-docs.php --check` → **exit 0**,
  "13 plugins validated, 22 generated regions current (zero writes)".
- Regenerated via `--write` only: `AGENTS.md`, `README.md`,
  `docs/architecture.md`, `docs/deployment.md`, `docs/plugins/README.md`,
  `docs/project-inventory.md`, `scripts/build-plugins-zip.sh`, `compose.yaml`
  and the per-plugin metadata block. No generated block was hand-edited.
- **Build:** `rm -rf dist && ./scripts/build-plugins-zip.sh` → exit 0,
  **7 ZIPs** (unchanged from Stage G). The engine is **not** in the build set;
  **no retired rollout** is in the build set; **0** test/fixture/log entries in
  any ZIP. `dist/` is git-ignored build output.
- `docker compose config` → exit 0; the engine mount is present in the
  generated mounts block.

---

## 10. Tests

| Run | Result |
|---|---|
| `./scripts/run-tests.sh` (full) | **50 suites — 35 passed, 15 failed**; 3 306 assertions passed, 59 failed; acceptance 2/2 passed |
| Stage G baseline (measured by stashing all Stage H work) | 46 suites — 31 passed, 15 failed; 3 244 assertions passed, 59 failed |
| `./scripts/run-tests.sh --only theme` | 25 suites — 14 passed, 11 failed; 1 805 assertions passed, 54 failed |
| `./scripts/run-tests.sh --acceptance` | 2 suites — 2 passed, 0 failed |
| shared engine (3 new suites) | **46 assertions — 46 passed, 0 failed** |
| migrated stage (1 new suite) | **16 assertions — 16 passed, 0 failed** |

`diff` of the failing-suite lists before/after: **identical** (15 suites, same
names). The 15 known content/data failures are unchanged and remain visible;
none was masked, fixed or silenced. **+4 suites, +62 passing assertions, zero
new failures, zero removed assertions.**

---

## 11. Static verification

Full detail in `docs/evidence/2026-09-26-stage-h/static-verification.txt`.

| Check | Result |
|---|---|
| `php -l` (432 files, `lint.sh` step 1) | no parse errors; every Stage H file verified individually |
| **PHPStan** level 5 | **`[OK] No errors` (exit 0)** |
| **PHPCS** baseline gate | 3 293 E / 2 669 W (Stage H) vs **3 313 E / 2 671 W (Stage G)** → Stage H **reduces** debt by 20 E / 2 W |
| New Stage H files | **0 errors, 0 warnings each** |
| `phpcs-baseline.json` | **not modified** |
| `phpstan-baseline.neon` | one **stale** entry deleted (for the removed `includes/apply.php`); no entry added or relaxed |
| **ShellCheck** (`build-plugins-zip.sh`, the only shell file changed, and only inside a generated region) | exit 0, no findings |
| `./scripts/lint.sh` | **NOT RUNNABLE end-to-end** — see §13 |

---

## 12. Runtime safety

| Surface | Result |
|---|---|
| Production HTTP requests | **0** |
| Production DB / content / plugin-activation / Polylang / REST changes | **NONE** |
| Theme runtime changes | **NONE** |
| Plugin runtime changes | only the new shared engine + the selected retired rollout migration (+ its two local WP-CLI scripts) |
| The other four retired rollouts | **unchanged** |
| New CPT / taxonomy / route / sitemap / canonical / hreflang | **NONE** |
| Language accessors | none added; Polylang APIs + the existing repository conventions only |
| Flutter/mobile | **NONE** |
| Local fixture data | created and **fully removed** (0 `stageh-%` rows; job namespace restored to its pre-test ID set) |
| Production-looking URLs in new/changed files | **none** (`conexaobr.ie` / `conexaobrirlanda.com` absent) |
| Temporary proof artefacts | all removed (`.stageh-*` / `.clean-phpcs.*` scratch files deleted) |
| Language accessors / REST / theme | untouched — the engine is orchestration-only and has **no frontend effect** |

---

## 13. Limitations

1. **`./scripts/lint.sh` cannot complete on this host.**
   NOT RUNNABLE: the host PHP CLI lacks the `xmlwriter`/`SimpleXML`/`tokenizer`
   extensions PHPCS requires ("ERROR: PHPCS requires the tokenizer, xmlwriter
   and SimpleXML extensions to be enabled"), and `composer` is not on PATH
   (`line 83: composer: command not found`).
   *Compensating evidence:* all three steps were executed directly and
   equivalently — the same `php -l` sweep, the same
   `scripts/phpcs-baseline.php check` against a real PHPCS JSON report produced
   in a `php:8.5-cli` container, and the same PHPStan invocation with the same
   `phpstan.neon.dist` + baseline (memory limit raised to 2G because the
   default 128M crashes a parallel worker). Syntax: PASS. PHPStan: PASS.
   PHPCS baseline: **FAILS on six sniffs that are byte-identical in the clean
   Stage G tree** — a pre-existing drift between the committed baseline and
   this environment's PHPCS/PHP build, which Stage H both fails to fix and
   measurably improves. Hosted CI was **not** run.
2. **No `git commit` was made.** The working tree is delivered uncommitted so
   the reviewer sees the exact diff; the "final SHA" below is therefore the
   start SHA.
3. **A `plugins_loaded` hook replaced direct registration** in the stage
   bootstrap, because `active_plugins` order is not guaranteed. This is a
   deliberate robustness decision, documented in the bootstrap, with zero
   writes on registration.
4. **The engine's remove path is implemented and unit-proved, but the migrated
   stage declares `allow_remove => false`**, so it was not exercised against
   real EN job records. Removal safety for that stage is documented, not
   claimed (§14).
5. The 15 pre-existing failures include suites that need a content state this
   local site does not have (no EN blog posts, no English theme page pair, …).
   They were left visible and untouched, as required.

---

## 14. Future rollout recipe

A new translation rollout now needs **three artefacts and zero copied
orchestration**:

1. **A versioned data manifest** — authored translations keyed by a portable
   stable identity (PT slug / `_leisure_uuid` / `source + source_id + UUID`).
   Never a local post ID.
2. **A small stage config** — `includes/stage-config.php` declaring stage id,
   post type, source/target language, the manifest/snapshot/build/copy
   callbacks, optional landing + extra gate callbacks, an allowlist, and
   `allow_remove`.
3. **A registration + a test** — one
   `Conexao_Translation_Rollout_Engine::register_stage()` call and a gate test.

Then: inventory → dry-run → review snapshot → apply → verify PT-unchanged →
verify the numeric gate → record evidence → roll back/remove if the stage
declared it safe.

For the **Job** stage specifically, `allow_remove` is `false` because its EN
records carry a verbatim `_job_*` meta layer and shared featured media whose
pre-removal state is not reconstructible from the manifest alone. Recovery is
therefore **snapshot-based**: the engine's PT snapshot plus the versioned
manifest reproduce every EN field, and re-applying the stage is idempotent. A
destructive remove is **not** claimed.

---

## 15. Final state

| | |
|---|---|
| Stage H start SHA | `7144d79bbbc8c4615c961966d86527b8eb0f9361` |
| Final SHA | `7144d79bbbc8c4615c961966d86527b8eb0f9361` (working tree uncommitted — see §13.2) |
| Shared engine | `wp-content/plugins/conexao-translation-rollout/` (4 files + 3 test suites) |
| Migrated rollout | `conexao-job-translation` (766 → 316 orchestration LOC) |
| Remaining four retired rollouts | `conexao-page-translation`, `conexao-blog-translation`, `conexao-leisure-translation`, `conexao-guide-translation` — **unchanged** |
| Tests | full 50/35/15 · theme 25/14/11 · acceptance 2/2/0 · engine 46/46/0 · stage 16/16/0 |
| Idempotence | 2 → 0 creates, 0 → 0 updates |
| PT drift | detected (1 field), gate forced FAIL |
| Numeric gate | `missing_en=0 conflicts=0 pt_drift=0` → **PASS** (`docs/evidence/2026-09-26-stage-h/gate.json`) |
| Registry | `--check` exit 0, 13 plugins, 22 regions current |
| Build | exit 0, 7 ZIPs, engine + all retired rollouts excluded, 0 test debris |
| Compose | `docker compose config` exit 0, engine mount present |
| Static | syntax PASS · PHPStan PASS · PHPCS reduces debt · ShellCheck PASS · `lint.sh` NOT RUNNABLE (§13) |
| Production impact | **0 requests, 0 writes, 0 content, 0 Polylang, 0 REST, 0 Flutter** |
| Working tree | clean of every deliberate defect and every temporary artefact |

---

**Status: PASS WITH LIMITATION** — a functioning shared engine, one genuinely
migrated rollout with its duplicated orchestration deleted, a real idempotence
proof, real PT-drift protection, a numeric gate, consistent registry and build,
a full regression whose failure set is identical to the Stage G baseline, and
zero production impact. The single limitation is environmental (§13.1):
`./scripts/lint.sh` cannot complete on this host, its three steps were run
directly and equivalently instead, and the one failing check is a pre-existing
PHPCS baseline drift that Stage H reduces rather than introduces.

_Last verified: 2026-09-26 by Stage H — Shared Translation Rollout Engine_

---

## Appendix A — Engine / stage separation review (Phase 22)

The repository was re-scanned after the migration for duplicated rollout
operations. The migrated stage now contains **zero** implementations of:

| Generic operation | Where it lives now |
|---|---|
| generic plan execution / traversal | engine `apply_plan()` |
| generic apply loop | engine `apply_plan()` |
| generic snapshot handling | engine `capture_snapshots()` + `assert_no_pt_drift()` |
| generic verify / gate framework | engine `collect_verify()` + `calculate_gate()` |
| generic result formatting | engine admin `render_report()` |
| generic admin security flow | engine admin `handle_run()` (capability + nonce) |
| generic inventory / state discovery | engine `collect_states()` |
| generic dry-run plan | engine `build_plan()` |
| generic manifest validation | engine `validate_manifest()` |
| generic remove traversal | engine `apply_removals()` |

Functions still owned by the migrated stage, and why each belongs there:

| Function | Why it is stage-owned |
|---|---|
| `conexao_job_translation_manifest()` | the authored English **data** (versioned content, not code) |
| `conexao_job_translation_snapshot_job()` | the **content model**: exactly which PT fields of a `job` must be protected (`_job_*` meta, `conexao_*` terms, thumbnail) |
| `conexao_job_translation_pair_ok()` | the job-pair both-directions assertion, reused by the adapter |
| `conexao_job_translation_verify_jobs_page()` | Stage 4.5 owns the Jobs landing pages; this **verifies only** — a job-specific gate input |
| `conexao_job_translation_copy_fields()` | the job **field policy**: verbatim `_job_*` + shared featured image |
| `conexao_job_translation_engine_manifest()` | normalises the historical map into the engine's manifest shape (no new content) |
| `conexao_job_translation_engine_adapter()` | the WordPress-bound primitives (lookup / create / repair / link / verify / remove) |
| `conexao_job_translation_engine_config()` | the declarative stage configuration |

Write-call audit: the stage bootstrap contains **0** write call sites; the
5 write primitives in `stage-config.php` are the adapter's `create_en`,
`repair_en`, `link_pair` and `remove_en` plus the collision probe — i.e. the
irreducible WordPress I/O the engine orchestrates; the 1 write in
`stage-fields.php` is the job field policy.

The engine is therefore genuinely reusable: `test-future-stage-smoke.php`
drives a **completely different synthetic stage** (different post type,
different manifest, different eligibility) through the same
inventory/plan/snapshot/apply/verify/gate behaviour with no stage-specific
orchestration.
