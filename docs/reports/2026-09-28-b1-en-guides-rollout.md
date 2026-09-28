# Report — B1 English Guides content rollout (`/en/guias/`)

| | |
|---|---|
| **Stage / task name** | B1 English Guides content rollout (48 PT guides → real EN records) |
| **Date** | 2026-09-28 |
| **Author / agent** | coding agent (Cline) |
| **Branch** | `i18n` |
| **Start SHA** | `846fd018b2f88dc0e10c6d47a2c9b8e693772e3d` |
| **Final SHA** | `846fd01` + working-tree changes (**not committed by this task** — see §3) |
| **Working tree at finish** | dirty: the changes listed in §3, plus new evidence under `docs/evidence/2026-09-28-b1-en-guides/` |

## 1. Scope completed

Real, published, Polylang-linked `en` translations were created for **all 48
published PT `guide` records**, plus the **13 used `conexao_category` terms**, so
`/en/guias/` is now a populated B1 English archive.

The work used the **existing shared architecture**, exactly as required:
`conexao-translation-rollout` (the engine) → the `en-guide` stage declared by
`conexao-en-translation` (versioned data + stage config) →
`scripts/run-en-translation.php` (the existing CLI) → dry-run → snapshot → apply →
verify → numeric gate. **No second translation lifecycle was created**, and the
retired `conexao-guide-translation` plugin was **not** reactivated: only its
already-authored *data* was imported into the shared engine's stage.

| Result | Before | After |
|---|---:|---:|
| Published PT guides | 48 | **48** |
| Published EN guides | 0 | **48** |
| PT guides with EN translation | 0 | **48** |
| PT guides without EN translation | 48 | **0** |
| Orphan EN guides | 0 | **0** |
| Duplicate EN guide translations | 0 | **0** |
| Used PT `conexao_category` terms translated | 0/13 | **13/13** |
| `/en/guias/` `FOUND` | 0 | **48** |
| `post_type:guide:missing_en` | 48 | **0** |
| PT records changed | — | **0** |

## 2. Scope NOT completed

- **Production.** Nothing was written to production. Production is WordPress.com
  (no SSH / WP-CLI / filesystem / database), so a maintainer would operate the
  same stage through Tools → **Translation Rollouts** (Preview → Apply). Not in
  scope here and not attempted.
- **No commit and no deploy** were authorised, so none were performed. A commit
  (`cd8b6c6`) appeared mid-task that **I did not author** (authored by the
  repository owner, 12:38); see §3. I created no commit.
- **3 manifest rows + 1 Stage M row** have no PT guide on this site. They are
  reported as documented exclusions by the shared engine (§5) rather than
  fabricated or forced.
- **PHPStan via `./scripts/lint.sh`** reports a failure because the `composer`
  binary is absent in this environment. The analysis itself was run directly with
  the vendored `phpstan` and **passes**; see §9 and §13.
- The **16 pre-existing failing in-process suites** (Blog, Jobs, Lazer, events,
  i18n, stage41/45 fixtures) were **not** addressed. They fail identically
  before and after this change; fixing them is separate work.

## 3. Files added / modified / deleted

**3 added, 13 modified, 0 deleted.** Every `wp-content/` change is called out.

### Added

| Path | Note |
|---|---|
| `wp-content/plugins/conexao-en-translation/includes/guide-translation-data.php` | **51 rows**, machine-generated once from the retired plugin's authored English, keyed by PT slug. |
| `wp-content/plugins/conexao-en-translation/includes/guide-terms-data.php` | **13 rows** — the EN `conexao_category` terms the published PT guides actually use. |
| `wp-content/plugins/conexao-en-translation/includes/guide-stage.php` | The `en-guide` translated-taxonomy capability (declaration + Polylang calls only). |

### Modified (`wp-content/`)

| Path | Change |
|---|---|
| `conexao-en-translation/includes/translation-map.php` | Union the new guide dataset into the per-type manifest, fail-closed on a duplicate PT slug. |
| `conexao-en-translation/includes/stage-config.php` | Declare the optional taxonomy callbacks **for `guide` only**. |
| `conexao-en-translation/conexao-en-translation.php` | `require_once` the 3 new files; version `1.4.0` → `1.5.0`; description. |
| `conexao-translation-rollout/includes/class-conexao-translation-rollout-engine.php` | Additive optional `taxonomy_callback` / `taxonomy_gate_callback`; version `1.0.0` → `1.1.0`. |
| `conexao-translation-rollout/conexao-translation-rollout.php` | Version + description. |
| `conexao-translation-rollout/tests/test-translation-rollout-engine.php` | 15 new assertions for the optional capability. |
| `conexao-br-irlanda/tests/test-guide-en-translation.php` | Migrated off the retired plugin's `audit()` onto the shared engine (all substantive assertions kept). |
| `phpcs.xml.dist` | Excluded the machine-generated dataset, mirroring the existing `body-*.php` precedent. |

### Modified (docs)

`docs/routing.md`; `docs/plugins/conexao-en-translation.md`;
`docs/plugins/conexao-translation-rollout.md`;
`docs/plugins/conexao-guide-translation.md`; plus the generated registry regions
(`docs/architecture.md`, `docs/plugins/README.md`, `docs/project-inventory.md`)
produced by `php scripts/generate-registry-docs.php --write` — **never hand-edited**.

### Deliberately NOT touched

`wp-content/plugins/conexao-guide-translation/**` (dormant data source);
`conexao_b2_post_types()` in `inc/i18n/fallback.php`; `inc/i18n/guard.php`;
`plugins.json`; `tests/acceptance/matrices/guides-en.json`; the permanent gates
`test-taxonomy-policy.php` and `test-translation-completeness.php`; anything in the
Flutter/mobile repository.

### The `cd8b6c6` commit

A commit appeared during this task, authored by the repository owner
(`Andrei <andreibalzan@gmail.com>`, 12:38), containing the engine changes plus the
previously-untracked evidence directories. **I did not create it and did not
create any commit.** It is recorded here for accuracy, not endorsed.

## 4. Runtime impact

WordPress behaves differently in exactly one way: `/en/guias/` now returns **48
real English guides instead of 0 rows**, and each has a working
`/en/guias/{en-slug}/` single. No template, query, hook, cache key or redirect
policy changed — the change is confined to the files listed above, and the Guides
row semantics in `docs/routing.md` (200 EN singles, 302 replaced-master for the PT
slug) are unchanged.

## 5. Content / data impact

- **Created**: 48 EN `guide` posts (`publish`, `en`) + 13 EN `conexao_category`
  terms linked to their PT terms in both directions.
- **Updated / deleted**: 0 PT records, 0 unrelated records, 0 deletions.
- **Match strategy**: `stable-id: 48`, `slug: 0`, `title: 0` — every record was
  resolved by the authored **PT slug**, the only portable identity. No local
  post/term ID was used as identity anywhere.
- **Documented exclusions (4)**, reported by the engine, not silently dropped:
  `outono-irlanda-alimentacao-bem-estar` (the pre-existing Stage M row, whose PT
  guide does not exist on this site), `beneficios-pais-solteiros-irlanda`,
  `inverno-irlanda-depressao-sazonal-saude-mental`,
  `violencia-domestica-irlanda-onde-encontrar-ajuda`.
- The **dry-run performed zero writes**, proven after the fact: 0 EN guides and
  0 EN terms existed immediately after it.

## 6. Polylang impact

| Assertion | Result |
|---|---|
| PT language = `pt` (all 48) | **48/48** |
| EN language = `en` (all 48) | **48/48** |
| PT→EN relationship resolves | **48/48** |
| EN→PT relationship resolves | **48/48** |
| EN guides published | **48/48** |
| Orphan EN guides | **0** |
| Duplicate EN translations | **0** |
| `conexao_county` terms / new / EN-tagged / dup slugs | 29 / **0** / **0** / **0** |
| `conexao_town` terms / new / EN-tagged / dup slugs | 247 / **0** / **0** / **0** |
| `conexao_tag` terms | **0** |
| Completeness gate | `post_type:guide:missing_en` **48 → 0** |
| Stage gate | `eligible_public_pt=48 with_en=48 missing_en=0 conflicts=0 pt_drift=0 extra_failures=0` → **PASS** |

**PT immutability**, compared per record against
`docs/evidence/2026-09-28-b1-en-guides/pt-snapshot-pre.json` (captured before the
first write, with a SHA-256 per record and a whole-snapshot hash):

| Field group | Changed |
|---|---:|
| PT records compared | 48 |
| **PT records changed** | **0** |
| title / slug / content / excerpt / status / taxonomy | **0** each |
| unrelated post changes | **0** |
| whole-snapshot SHA-256 | `9924c011…0c94b2c0e58` (identical) |

## 7. Route / HTTP impact

**No route, redirect, canonical, hreflang or sitemap policy changed.** Only the
row set those routes return changed. Full results in
`docs/evidence/2026-09-28-b1-en-guides/http-verification.txt`.

| Check | Result |
|---|---|
| `/guias/` | **200**, 10 cards, `FOUND=48`, 5 pages (unchanged) |
| `/en/guias/` | **200**, 10 cards, `FOUND=48` (**was 0**), self-canonical, full `pt-BR`/`en`/`x-default` set |
| B2 fallback notice on `/en/guias/` | **absent** (0 occurrences) — no PT fallback rendered |
| PT card links inside the EN archive | **0** — the EN archive contains no PT records |
| `/en/guias/page/2/` | **200** (**was 404**) |
| `/en/guias/?categoria=documents` | **200** |
| `/en/guias/?categoria=saude` (PT slug in EN context) | **200** |
| `/guias/pps-number-2/` (PT single) | **200**, canonical unchanged |
| `/en/guias/pps-number-ireland/` (EN single) | **200**, self-canonical, `pt-BR` alternate, meta description, language switcher |
| `/en/guias/pps-number-2/` (PT slug under `/en/`) | **302 → `/guias/pps-number-2/`** — the documented B1 replaced-master, still in force |
| `/en/guias/nao-existe-este-slug/` | **404** |
| `conexao_is_b2_post_type('guide')` | **false** — `guide` is still B1; `conexao_b2_post_types()` unchanged |

## 8. Production actions

| Action | Performed? | Detail |
|---|---|---|
| Production write | **no** | local Docker WordPress only |
| Deploy / upload | **no** | no release, no tag, no upload |
| Plugin activation | **no** | the retired `conexao-guide-translation` was **deactivated** to restore its retired state; no production plugin state was touched |
| Content or DB mutation | **no** (production) | 48 EN posts + 13 EN terms written **locally** only |
| Commit / deploy | **no** | see §3 for the externally created `cd8b6c6` |

## 9. Verification commands and results

| Command | Exit | Result |
|---|---:|---|
| `run-en-translation.php --dry-run --only=guide` | 0 | `created=48 skipped=4 conflicts=0 PT-drift=0`; taxonomy `en_terms_planned=13 created=0`; `extra_failures=13` → `GATE FAIL` (correct: nothing translated yet) |
| `run-en-translation.php --apply --only=guide` | 0 | `created=48 updated=0 skipped=4 conflicts=0 errors=0 pt_changed=0`; **`GATE PASS`** |
| `run-en-translation.php --dry-run --only=guide` (2nd) | 0 | `created=0 updated=0 skipped=52 conflicts=0`; **`GATE PASS`** |
| `run-en-translation.php --apply --only=guide` (2nd) | 0 | `created=0 updated=0 skipped=52`; taxonomy `present=13 planned=0 created=0`; **`GATE PASS`** — idempotent |
| `python3 tests/acceptance/verify-guides-en-http.py` | 0 | **18 passed, 0 failed** (baseline 2 failed) |
| `python3 scripts/verify-permanent-gates.py` | 0 | **7/7 PASS, 86 assertions, 0 violations, 0 new** (baseline 6/7, 48 violations) |
| `php scripts/generate-registry-docs.php --check` | 0 | 14 plugins, 23 generated regions current, **zero writes** |
| `php -l` (466 files) | 0 | no parse errors |
| PHPCS (WordPress-Extra + Docs + PHPCompat) | 0 | **OK: no NEW violations**; 3207/2668 vs baseline 3290/2672 → **debt paid down in 20 sniffs**; baseline **not** regenerated |
| `./vendor/bin/phpstan analyse --memory-limit=2G` | 0 | **`[OK] No errors`** |
| `./scripts/lint.sh` | 1 | PHP syntax **OK**, PHPCS **OK**, PHPStan stage blocked by missing `composer` binary (§13) |
| `git diff --check` | 0 | clean |

### Numeric test results

`./scripts/run-tests.sh` — in-process PHP:

| | Before | After |
|---|---:|---:|
| suites | 60 | 60 |
| passed / failed | 42 / 18 | **44 / 16** |
| assertions passed | 2928 | **3669** |
| assertions failed | 76 | **64** |
| prerequisite failures | 1 | 1 |

**Suites fixed:** `test-guide-en-translation.php`,
`test-translation-completeness.php`. **New failing suites introduced: 0.**

Script-contract (`--scripts`): **6 suites, 6 passed, 0 failed** before and after
(agent-governance, cache-key-scoping 2/0, documentation-drift 14/0, i18n-freshness
8/0, release-integrity 210/0, script-conventions 317/0).

### HTTP acceptance

`guides-en` matrix: **18 assertions passed, 0 failed** (baseline 2 failed).
`verify-release-http`: **42 passed, 0 failed**. `verify-routing-http`: **2 matrix
rows failed**, both pre-existing Lazer card-excerpt fragments
(`pt-lazer-card-excerpt-stays-portuguese`, `en-lazer-card-excerpt-is-english`) —
**no guide row fails**, and the guide-specific REST row
`rest-invalid-lang-guide` **passes** (400 on `?lang=pt-br`).

### Static analysis

`php -l` clean; PHPCS no new violations (and net debt reduction); PHPStan level 5
`[OK] No errors`.

### Release / build results

**not in scope** — no commit, no tag, no build, no deploy. `plugins.json` build
flags are untouched, so the release allowlist is unchanged.

## 10. Failure proofs / negative tests

A gate that has never been seen to fail is not a gate.

| Proof | Result |
|---|---|
| The stage gate **before** the apply | `GATE FAIL — missing EN=48, extra_failures=13`. The gate blocks rather than passing on absent content. |
| Taxonomy gate with **one mutated expected slug** (in memory, no write) | **1 failure** → would FAIL. Real data re-checked afterwards: still **0** (nothing written). |
| Engine gate with `missing_en=1` | **FAIL** |
| Engine gate with `pt_drift=1` | **FAIL** |
| Engine gate with all counters zero | **PASS** — so the PASS above is earned, not default |
| Engine suite: taxonomy failure folds into `extra_failures` | **PASS** (asserted) |
| PHPCS baseline gate | **FAILED** when my first draft grew 4 sniffs. I fixed the code, not the baseline. Final: `OK: no new violations`. |
| Pre-existing red → green | `test-guide-en-translation.php` and `test-translation-completeness.php` were red at baseline and are green only **because** real EN content now exists. |

## 11. Regression comparison

| | Before | After |
|---|---:|---:|
| Failing in-process suites | 18 | **16** |
| Failing assertions | 76 | **64** |
| Failing script-contract | 0 | 0 |
| Failing acceptance assertions | 2 | **0** |
| Permanent gates failing | 1 (48 violations) | **0** |

The diff of the failing lists is **not** empty, and every removal is an
improvement: the 2 removed suites are the two this rollout was meant to fix. The
**16 suites failing in both** runs are pre-existing and unrelated to guides —
`test-ivvcc-importer`, `test-town-sanitization`, `test-excerpt-en-roundtrip`,
`test-language-uuid` (prerequisite), `test-blog-en-translation`,
`test-event-location-filters`, `test-header-menu-selection (pt)`,
`test-i18n-foundation`, `test-job-en-translation`, `test-jobs-en-language`,
`test-leisure-attribute-normalization`, `test-polylang-foundation`,
`test-stage32-bilingual`, `test-stage33-bilingual`, `test-stage41-rest-language`,
`test-stage45-pages`.

## 12. Known pre-existing failures

Unchanged before and after; each fails for a local content/fixture reason, not
because of this change:

- **Event importer** (`test-ivvcc-importer`, `test-town-sanitization`): importer
  dedup and Eircode-contaminated `conexao_town` term content.
- **Leisure migration** (`test-excerpt-en-roundtrip`, `test-language-uuid`):
  blocked on the `insufficient data: activate-plugin:conexao-leisure-migration`
  prerequisite.
- **Blog** (`test-blog-en-translation`): five Blog category terms
  (`saude-e-bem-estar`, `lazer`, …) have no linked EN counterpart. The same
  pre-existing `preg_match` `PCRE2 does not support \F` warning is present.
  **Distinct from this rollout:** the Blog stages own those terms, and I did not
  touch the Blog stages.
- **Jobs** (`test-job-en-translation`, `test-jobs-en-language`): local job
  fixtures and Portuguese string assertions.
- **i18n / header / events / stage41 / stage45 / lazer normalisation**:
  pre-existing content and locale assertions.

None is a guide row, and none was edited.

## 13. Limitations

1. **`composer` is not installed in this environment**, so `./scripts/lint.sh`'s
   third stage (`composer analyse`) cannot run and `lint.sh` exits 1. This is an
   **environmental/tooling failure, not a code failure**: the identical command
   the composer script wraps (`phpstan analyse --memory-limit=2G`) was run with
   the vendored `vendor/bin/phpstan` and reported **`[OK] No errors`**.
   `lint.sh` itself was not modified to hide this.
2. **PHPStan level 5 as configured runs on the vendored 2.0.x series** — the phar
   reports it is not PHPStan 2.2+. That is the repository's pinned toolchain, not
   a change made here.
3. **No production verification.** `scripts/verify-deploy.py` was not run against
   a real deployment; no deploy exists from this tooling.
4. **No browser/Playwright pass** was performed in this task (the earlier
   BLOCKED report's browser evidence is pre-existing and was not re-run).
5. **`phpcbf` is broken in this environment** (`One or more child processes failed
   to run`, reproducible on a 6-line file). Auto-fixing was therefore done by
   hand and verified with `phpcs` itself. This did not change any result.
6. **One style-gate exclusion was added** (`phpcs.xml.dist`) for the
   machine-generated dataset `guide-translation-data.php`, mirroring the
   repository's existing exclusion for the Stage 9 `body-*.php` files — the same
   class of artefact. The maintained code around it
   (`guide-stage.php`, `stage-config.php`, `translation-map.php`,
   `guide-terms-data.php`) remains **fully in scope and is PHPCS-clean**.

## 14. Evidence paths

`docs/evidence/2026-09-28-b1-en-guides/`:

| File | Proves |
|---|---|
| `plan.md` | The plan, its options-rejected section, and the pre-work measurements. |
| `pt-snapshot-pre.json` | The pre-write PT snapshot: 48 records, per-record SHA-256, whole-snapshot hash, dataset + engine versions. |
| `dry-run.json` | The dry-run plan: 48 creates, 4 exclusions, 13 term plans, 0 conflicts, and the pre-apply `GATE FAIL`. |
| `second-run-idempotence.json` | The second apply: 0 created, 0 updated, 0 category creates. |
| `rollout-summary.txt` | Every before/after number in one table. |
| `gate.json`, `permanent-gates-before.txt`, `permanent-gates-after.txt` | The 7 permanent gates, before and after, from real execution. |
| `baseline-run-tests.txt` | The full baseline suite output. |
| `http-verification.txt` | Every HTTP row, status, canonical, hreflang and the no-fallback proof. |
| `negative-proofs.txt` | The read-only proofs that the new verifiers can fail. |

## 15. Documentation updated

| Document | Change |
|---|---|
| `docs/routing.md` | The Guides `/en/` row now records the applied state (48/48 + 13 terms) and states explicitly that `guide` stays **B1** with **no B2 widening and no fallback**. |
| `docs/plugins/conexao-en-translation.md` | New `en-guide` section: strategy, the 51-row dataset, the 13-term dataset, the numeric gate, why the data was imported rather than re-authored, the 3 portable exclusions, the taxonomy capability, the KSES stored-form note, and the translated/shared taxonomy policy. |
| `docs/plugins/conexao-translation-rollout.md` | The optional `taxonomy_callback` / `taxonomy_gate_callback` contract: signature, ordering, gate integration and the additive-inert guarantee. |
| `docs/plugins/conexao-guide-translation.md` | A "Superseded" note **outside** the generated block: the data now lives in the shared `en-guide` stage, this plugin stays inactive, and its "15 terms" count predates the 13-term scope. |
| `docs/architecture.md`, `docs/plugins/README.md`, `docs/project-inventory.md` | Regenerated registry regions only (version bumps) — via the generator, never hand-edited. |
| `docs/evidence/2026-09-28-b1-en-guides/*`, this report | New. |

Each edited document ends with its own `_Last verified:_` line. Unrelated
architecture documentation was **not** rewritten, and `AGENTS.md` was **not**
hand-edited.

## 16. Rollback / recovery

**Content rollback (the documented path):**

```bash
php scripts/run-en-translation.php --remove --apply --only=guide
```

`en-guide` declares `allow_remove: true`, so the engine removes only the EN
records this manifest owns and, via the same path's taxonomy removal, only the EN
terms that are linked to a manifest PT term, carry the authored EN slug and are
attached to no post. **PT originals are never touched.** Recovery proof: re-hash
`pt-snapshot-pre.json` against the live PT records, re-run the permanent gates
(`missing_en` returns to 48) and confirm orphan EN returns to 0.

**Code rollback:** `git revert` of this change. Note that a code rollback does
**not** undo a content change — the content recovery above is separate and is
required first.

**Not covered by rollback:** the local MySQL data directory and anything outside
this repository. The retired plugin's own `apply.php` is *not* a rollback path
and must not be used.

**No rollback was needed**: no critical verification failed, and every gate passed
on the first apply.

## 17. Final status

| Status | Use when |
|---|---|
| `PASS` | Every applicable gate passed, with real numbers, and no required verifier was unavailable. |
| `PASS WITH LIMITATION` | The work is complete, but a **required** verification capability was unavailable. |
| `BLOCKED` | A required implementation could not be completed safely. |

**Final status: PASS WITH LIMITATION**

Every acceptance criterion is met with real numbers — 48/48 linked, published EN
guides; 13/13 category terms; **0** PT mutations against a checksummed snapshot;
all 7 permanent gates green (48 → 0 violations); the acceptance matrix 18/18; an
idempotent second run; and **0** new test failures. The single limitation is that
`./scripts/lint.sh` cannot run its PHPStan stage because the `composer` binary is
absent from this environment; the underlying analysis was run directly with the
vendored PHPStan and returned `[OK] No errors`, so CI remains authoritative for
that step.

_Last verified: 2026-09-28 by the B1 English Guides content rollout_




