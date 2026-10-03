# Report — Stage M: Permanent Invariant Debt Remediation

| | |
|---|---|
| **Stage / task name** | Stage M — Permanent Invariant Debt Remediation |
| **Date** | 2026-09-26 |
| **Author / agent** | Cline (AI agent) |
| **Branch** | `i18n` |
| **Start SHA** | `ed9470517aabd9cf27939729d9cb19a855d4af45` |
| **Final SHA** | `9a928a02a18f798b8e887ec784a7b8b9573225fb` |
| **Working tree at finish** | clean |
| **Final status** | **BLOCKED** — see §30 |

> Every number below came from an executed command; the machine output is in
> [`docs/evidence/2026-09-26-stage-m/`](.). No gate, allowlist, baseline or
> aggregate was modified. Nothing was written to production.

## 1. Scope completed

Stage L installed seven fail-closed invariant gates and left 361 pre-existing
violations red. Stage M remediated the debt at its origin and repaired the data.

| # | Debt | Baseline | Final | Status |
|---|---|---|---|---|
| 1 | `conexao_county` language-tagged terms | 32 | **0** | done |
| 1 | `conexao_town` language-tagged terms | 250 | **0** | done |
| 2 | `guide` missing EN | 1 | **0** | done |
| 2 | `page` missing EN | 29 | **0** | done |
| 2 | `post` missing EN | 42 | **34** | **partial — 8 of 42** |
| 3 | `guide` malformed relationships | 1 | **0** | done |
| 4 | Stale i18n catalogues | 6 | **0** | done |
| | **Total violations** | **361** | **34** | |

Aggregate: **7 gates, 6 passed, 1 failed, 0 blocked; 85 passed / 1 failed
assertions; 34 violations (34 pre-existing, 0 new); exit 1** — was
7 gates / 4 passed / 3 failed / 79 passed / 12 failed / 361 violations / exit 1.

The one remaining failure is `post_type:post:missing_en: 34`.

## 2. Scope NOT completed

**34 of the 42 eligible PT blog posts still have no EN translation**, so the
translation-completeness gate is red and the aggregate exits 1.

This is unfinished work, stated plainly rather than reframed:

- The 34 remaining posts are **140k characters of Portuguese editorial content**
  authored by named third parties (guest nutritionists, therapists, chefs and
  business consultants, each with their own byline and Instagram handle). It is
  roughly 24,000 words of authored English.
- 8 of the 42 were completed and verified as **batch 1** to prove the pipeline
  end-to-end. The batching, verification and rollback machinery is in place; the
  remaining authoring is the outstanding work.
- **No EN allowlist was added for blog posts.** `post` is a B1 type with no B2
  fallback, so every eligible PT post genuinely owes an EN record. Adding an
  allowlist would turn the aggregate green while hiding real debt, which the
  engineering standard and the Stage M hard rules both forbid.
- The gate was **not** relaxed, downgraded, or excluded, and the aggregate was
  not changed to accommodate the remainder.

Everything else in the stage is complete.

## 3. Files added / modified / deleted

**53 files changed, 5083 insertions(+), 1208 deletions(-)** across 8 commits.

Added:
- `scripts/remediate-shared-taxonomy-language.php` — clears the stale language
  tag from shared proper-name terms.
- `scripts/remove-en-orphan-fixtures.php` — removes EN records with no PT master
  that are provably stage fixtures; `--restore` replays a snapshot.
- `scripts/i18n-make-pot.sh` — the **missing catalogue generator** (the root
  cause of the i18n debt).
- `scripts/run-en-translation.php` — thin CLI in front of the shared engine.
- `wp-content/plugins/conexao-en-translation/` (5 files) — the Stage M stage:
  a DATA + CONFIG consumer of `conexao-translation-rollout`.
- `docs/plugins/conexao-en-translation.md`, the Stage M plan, and 12 evidence
  files under `docs/evidence/2026-09-26-stage-m/`.

Modified:
- 8 `.pot` catalogues (regenerated).
- `plugins.json` + 8 generated regions (registry).
- `docs/content-model.md`, `docs/routing.md`, `docs/testing.md`,
  `docs/plugins/conexao-guide-translation.md`, `scripts/README.md`.

Deleted: none.

**`wp-content/` runtime change:** one new local-only **tooling** plugin
(`conexao-en-translation`, `production: false`, `build: false`). It has no
frontend behaviour and no template/route change; it creates EN content records.
The existing production plugins were not modified.

## 4. Runtime impact

No shipped runtime behaviour changed. The theme, the four production plugins,
the i18n/guard/fallback policy modules and the REST contract are untouched.

- `conexao_polylang_translated_taxonomies()` (the taxonomy policy) — unchanged.
- `conexao_b2_post_types()` / `conexao_b2_page_allowlist()` — unchanged.
- `.htaccess`, permalinks, rewrites — unchanged.

Verified by `git diff --name-only ed94705..HEAD` over `wp-content/`: the only
`wp-content/` paths touched are the new plugin and the 8 generated `.pot`
files.

## 5. Content / data impact

| Operation | Count | Identity key |
|---|---|---|
| Terms updated (language tag cleared) | **282** (32 county + 250 town) | (taxonomy, slug) |
| Records deleted | **1** (EN orphan fixture) | PT-relative slug |
| EN records created | **38** (1 guide + 29 pages + 8 posts) | PT slug |

**PT records modified: 0.** Every PT record was read-only throughout; the
engine re-captured a full PT snapshot after each apply and reported
`PT drift = 0` on every run.

## 6. Polylang impact

- **PT immutability: proven.** The shared engine compares a before/after PT
  snapshot (title, slug, body, excerpt, status, date, author, menu order,
  thumbnail, all four taxonomies, language, meta description) and reports any
  difference as a gate failure. Every apply reported `PT drift = 0`.
- **EN records created: 38**, each a *linked translation* — never a second
  identity. Every pair was verified in **both** directions
  (`pll_get_post(pt,'en') === en` **and** `pll_get_post(en,'pt') === pt`).
- **Shared proper-name terms:** 282 terms de-tagged; the same physical term is
  now used by both languages. `conexao_category` was **linked** to its EN
  counterpart, never duplicated (e.g. PT term 1498 → EN term 3001).
- **Completeness gate:** `guide` 0, `page` 0, `post` **34**.
- **B2 allowlist: 1821 → 1821, unchanged.** Not one entry was added.

## 7. Taxonomy remediation results

The origin was proven before anything was changed, not assumed:

- `conexao_polylang_translated_taxonomies()` declares only
  `conexao_category`/`conexao_tag` as translated; county/town are shared.
- The seed (`Conexao_Data_Model_Relationships::seed_terms()`) uses a bare
  `wp_insert_term()`, and a **probe term inserted at the start SHA returned no
  language**, then was deleted. The seed is already correct.
- Therefore the 282 tags are **historical residue** from before the Stage 3.2
  policy correction, and the remediation is a **data cleanup only**. The seed
  and the policy were deliberately NOT changed.

Safety proven before the write: every county/town term sat in a
`term_translations` group of **size 1** (no cross-language counterpart), there
were no suffixed duplicates and no duplicate slugs, and the theme queries these
taxonomies **by slug** (`?county=`, `?cidade=`), never by language.

Result: **282 → 0**. Gate `test-taxonomy-policy.php`: 18 passed, 0 failed,
0 violations. `conexao_category` (58 PT / 16 EN) and all 282 translation
groups untouched. Frontend filters re-checked after the write: `/lazer/?county=dublin`,
`/en/lazer/?county=dublin`, `/eventos/?cidade=dublin`,
`/en/eventos/?cidade=dublin` all **200**.

**A process defect is recorded honestly:** the first `--dry-run` run fell
through into the apply block because the mode string was compared as
`dry_run` instead of `dry-run`, and the verification then mis-read a void
return value. The write therefore happened during a run labelled dry-run. It was
the intended remediation and the gate confirmed it, but the safeguard was
broken; both defects are fixed (`i18n`-style fail-closed mode check, and
success is now proven by re-reading the stored value after clearing the
term cache), and the re-run reports 0 remaining.

## 8. Translation remediation results

| Type | Missing EN before | Created | After |
|---|---|---|---|
| `guide` | 1 | 1 | **0** |
| `page` | 29 | 29 | **0** |
| `post` | 42 | 8 (batch 1) | **34** |

Every created EN record was independently verified after the apply:
bidirectional link, `en` language, PT publication date preserved, **EN body is
not a copy of the PT body**, `conexao_category` linked (not copied), correct
canonical, correct `hreflang` pair, and the B1 behaviour preserved — the PT
slug under `/en/` still **302s to the PT record**.

Spot-checked over HTTP: `/en/guias/autumn-in-ireland-eating-and-wellbeing/`,
`/en/housing/`, `/en/privacy-policy/`, `/en/categories/`, `/en/europe/`,
`/en/about-redirect/`, `/en/where-to-eat/`, `/en/cookie-policy/` — all **200**.

## 9. Malformed-relationship remediation

The single malformed relationship was **EN guide id 22184,
`stage32-editorial-translation`**:

- title `[STAGE32-EDITORIAL] translation`, body
  `Stage 3.2 editorial state fixture.`
- `pt_master = 0`, no meta, no taxonomy terms, no featured image
- its own `en` link pointed at **itself** (22184 → 22184)
- publicly reachable (HTTP 200) and present in the sitemap
- already documented as pre-existing debt in
  `docs/plugins/conexao-guide-translation.md`

**A fixture is repaired by removing it.** Fabricating a PT master would create
fake editorial content, which is a worse defect than a stray test record. The
script therefore only ever removes a record whose slug **and** title both carry
a stage marker and that has no PT master; anything else is reported for a
maintainer and left untouched.

The linked `stage42-contract-fixture-en` / `stage32-editorial-source` pair is a
**valid** bidirectional fixture and was deliberately left in place.

**Rollback proven in both directions:** `--restore` returned the gate to
`malformed = 1`; re-applying returned it to `malformed = 0`. Full record
snapshot in `en-orphan-fixture-snapshot.json`.

## 10. B1/B2 policy decisions

- **`guide`, `page`, `post` are B1** and were translated.
- The B2 directory types (`event`, `leisure`, `sponsor`, `course_provider`,
  `job`) were **not** touched: they are already covered by the documented B2
  policy, and adding them to the stage would create a second competing policy.
- **The B2 allowlist was audited and left at 1821.** Every allowlisted record is
  covered by `conexao_b2_post_types()` or `conexao_b2_page_allowlist()`; the
  per-type counts (event 1503, leisure 293, course_provider 10, sponsor 3,
  page 11) are unchanged from Stage L. No entry was added, and the gate still
  reports the allowlist explicitly.
- **Conflict:** the engine refused the EN slug `about-us` because it is already
  held by the linked EN translation of `/sobre-nos/`. Rather than force a
  duplicate identity, the stub's slug was changed and the collision check was
  tightened to refuse **any** live slug rather than only unlinked ones.

**A documented disagreement was found and resolved honestly.** `docs/routing.md`
(Stage 4.5) records `about`, `jobs-2`, the `sobre`/`termos`/`privacidade`
aliases and `search` as *excluded technical pages*, while the gate measures
every published page as eligible. Both documents could not be right. The gate
was not relaxed; the pages were **translated faithfully as published**, because
deleting or rewriting them is a PT content change this stage is not authorised to
make, and inventing real content would be a second, worse defect. They are
recorded in `docs/routing.md` as a maintainer decision.

## 11. i18n regeneration results

**Root cause found and fixed, not just this one diff.** The repository shipped a
freshness *check* (`i18n-check.sh`, Stage L) with **no generation script** — the
gap audit finding F-04 recommended. Every source edit silently made a `.pot`
stale and the only visible remedy was a hand-edit, which §9.3 forbids.

`scripts/i18n-make-pot.sh` is that generator, built to stop the debt recurring:

- components come from the **freshness gate's own discovery**, so the generator
  and the check can never disagree about which catalogues exist;
- a component with **no user-facing string gets no empty catalogue** (6 retired
  rollout components are correctly skipped);
- extraction is by **text domain, not by directory** — `conexao-data-model`
  tags its 12 job-type labels with the **theme** domain, and a per-directory
  extraction would have silently dropped them. A naive `make-pot` first removed
  all 12; they were caught by a per-msgid diff and are now correctly in the
  theme catalogue, verified individually.

Result: **6 stale → 0**; 8 catalogues regenerated with WP-CLI-compatible
`xgettext`. Verified by comparing against WP-CLI's own `make-pot` output: my
output is a strict **superset** (identical msgids plus the component `Version`
header string). **0 user-facing strings removed.** The `.po`/`.mo` translated
catalogues are **byte-identical** (`git diff` on `*.po`/`*.mo` is empty), so no
translation data was lost.

## 12. PT records touched

**0.** No PT record was created, edited, re-slugged or deleted.

## 13. EN records created

**38**: 1 guide, 29 pages, 8 blog posts. All linked translations, all verified
bidirectionally, none a copy of the PT body.

## 14. Snapshots

| Snapshot | Purpose | Rollback proven |
|---|---|---|
| `taxonomy-snapshot.json` + `town-terms-prechange.txt` | 282 terms, pre-change language | **Yes** — replayed, restored 282/282 and re-measured 282 language-tagged terms |
| `en-orphan-fixture-snapshot.json` | full pre-change record of EN guide 22184 | **Yes** — `--restore` returned the gate to `malformed=1` |
| engine PT snapshot (per apply) | every affected PT record | compared automatically; `PT drift = 0` on every run |

The generated `.pot` files are version-controlled, so `git checkout --` restores
them exactly.

## 15. Dry-run results

Every write-capable script is **dry-run by default** and was dry-run first:

- `remediate-shared-taxonomy-language.php --dry-run` — printed the plan, zero
  writes (after the safeguard fix: `to_clear=0`, `MODE: dry-run - ZERO writes`).
- `remove-en-orphan-fixtures.php --dry-run` — listed exactly the 1 fixture.
- `i18n-make-pot.sh --dry-run` — 8 to regenerate, 6 skipped, 0 failed.
- `run-en-translation.php --dry-run` — per type: `guide` 1 to create,
  `page` 29, `post` batch 1 = 8. A re-run after the apply reports
  **36 skipped, 0 conflicts, GATE PASS on all three** — the stage is idempotent.

## 16. Apply results

| Apply | Created/updated | Conflicts | PT drift | Gate |
|---|---|---|---|---|
| taxonomy | 282 cleared / 282 | 0 | 0 | PASS |
| EN guide | 1 created, 6 fields copied | 0 | 0 | PASS |
| EN pages | 29 created | 0 | 0 | PASS |
| EN posts (batch 1) | 8 created | 0 | 0 | PASS |
| EN orphan removal | 1 removed | — | — | PASS |

## 17. Permanent-gate results

```
Gates: 7 total, 6 passed, 1 failed, 0 blocked
Assertions: 85 passed, 1 failed
Violations: 34 (34 pre-existing, 0 new)
AGGREGATE: FAIL   (exit 1)
```

`taxonomy_policy` PASS · `translation_completeness` **FAIL (34)** ·
`cache_scoping` PASS · `cache_scoping_static` PASS · `redirect_precedence` PASS
· `documentation_drift` PASS · `i18n_freshness` PASS.

## 18. Regression comparison

`./scripts/run-tests.sh`, before vs after:

| Metric | Before | After |
|---|---|---|
| In-process PHP suites | 54 total, 37 passed, **17 failed** | 54 total, 40 passed, **14 failed** |
| Assertions | 3362 passed, 65 failed | **3385 passed, 56 failed** |
| Script-contract suites | 6 total, 5 passed, 1 failed | **6 total, 6 passed, 0 failed** |
| HTTP acceptance | 3 total, 3 passed, 0 failed | 3 total, 3 passed, 0 failed |

**Newly fixed (3):** `test-guide-en-translation.php`,
`test-polylang-foundation.php`, `test-taxonomy-policy.php`.

**Regressions: none.** No previously-passing suite broke.

**Still failing (14)** are the pre-existing failures inherited from Stage L, of
which **13 were already failing before Stage L** (the 15-suite list in the Stage
L report) and one is `test-translation-completeness.php`, which is the gate
itself and is red on the 34 real remaining violations.

## 19. HTTP acceptance

**3/3 suites, 119 assertions, 0 failed** — `verify-guides-en-http.py` 18/18,
`verify-routing-http.py` 59/59, `verify-release-http.py` 42/42. No matrix row
was added: the existing rows already covered the new EN routes, canonical,
hreflang and the B1 302, and a new row would have duplicated coverage.

## 20. Static tooling

| Check | Result |
|---|---|
| `php -l` | **446 files, 0 parse errors** |
| `python3 -m py_compile` | clean |
| `bash -n` | clean |
| PHPCS (new files) | **0 errors, 0 warnings** |
| PHPStan level 5 (new plugin) | **No errors** |
| Registry drift `--check` | **14 plugins, 23 regions, zero writes** |
| Stage I script contract | **305 passed, 0 failed** |
| `shellcheck` | **unavailable** in this environment (not installed) |
| PHPCS legacy baseline gate | **FAILS — pre-existing, proven** |

The PHPCS baseline gate was investigated rather than waved through. It reports
the same 7 growing sniffs **at the Stage L start SHA** (verified in a git
worktree at `ed94705`). A per-sniff measurement of both trees gives a
**delta of 0 for every sniff** (e.g. `Squiz.Commenting.FunctionComment.Missing`
151 → 151, `SpacingBefore` 110 → 110). `phpcs-baseline.json` is simply stale
relative to the committed code. Regenerating it would hide ~3300 pre-existing
violations behind a fresh number, so it was **not** regenerated.

## 21. Negative proofs

All seven re-run after remediation; the gates **still fail closed**.
Full detail in `negative-proofs.txt`.

| | Proof | Result |
|---|---|---|
| A | language tag on a shared term | **exit 1**, reported as *new* |
| B | missing EN record | **exit 1** (the live 34) |
| C | unscoped cache key | **2 violations, exit 1** |
| D | redirect hook priority 2 → 9 | **exit 1**, reported as *new*, reverted |
| E | hand-edited generated doc region | **exit 1** |
| F | gettext source newer than its `.pot` | **exit 1** |
| G | aggregate with one failing gate | **exit 1** (6 passed, 1 failed) |

The working tree is verified clean afterwards; no failed fixture remains.

## 22. Production writes

**0.** No upload, no activation, no production REST call, no production
filesystem or database access, no ZIP built or deployed. The remediation
scripts refuse a production target and Stage M never passed the confirmation
flag.

## 23. Runtime impact

See §4: no shipped runtime behaviour changed. One new local-only tooling
plugin; no theme, no production plugin, no route, no REST contract.

## 24. Documentation updates

- `docs/content-model.md` — shared proper-name terms are language-neutral in
  **data**, not only in policy, and the one-time cleanup contract.
- `docs/testing.md` — a "Repairing a failed gate" table mapping each failure to
  its root cause and its single documented remedy; the i18n generator is named
  as what stops that debt recurring.
- `docs/routing.md` — the Stage M EN coverage state, including the honest
  resolution of the Stage 4.5 "excluded pages" vs gate disagreement, and an
  explicit statement that blog posts remain partly untranslated.
- `docs/plugins/conexao-guide-translation.md` — the stale
  "EN guides missing PT translation = 1" debt note corrected.
- `docs/plugins/conexao-en-translation.md` — new plugin doc.
- `scripts/README.md` — the four new scripts catalogued.
- `AGENTS.md` and `docs/releases.md` were **not** changed: no enduring agent
  rule changed and no releasable runtime change occurred.

No raw telemetry was written into permanent documentation.

## 25. Release implications

**No release.** `production` and `build` are unchanged for every existing
component; the new plugin is `production: false, build: false`, so it is in no
release ZIP. No version was bumped, which is correct for a data-and-tooling
change. Registry regeneration was the only derived change.

Production remediation of the same debt is a **separate, explicitly
authorised** task. Because WordPress.com has no SSH/WP-CLI, it must be driven
from wp-admin; the scripts here are dry-run capable and refuse production on
their own.

## 26. Limitations

1. **34 blog posts are untranslated** — the reason the aggregate is red. See §2.
2. **`shellcheck` was not available**, so the new `i18n-make-pot.sh` has
   `bash -n` and a PHPCS-equivalent review but no shellcheck. `i18n-check.sh`
   itself is unchanged and still green.
3. **The PHPCS legacy baseline gate fails** (pre-existing, proven, §20). Full
   `./scripts/lint.sh` cannot complete here because `composer` is absent; the
   stages were run individually with the vendored binaries.
4. The 13 other pre-existing failing suites were left alone as out of scope.

## 27. Rollback / recovery

| Change | Rollback |
|---|---|
| 282 term language tags | `php scripts/remediate-shared-taxonomy-language.php --apply` after restoring the snapshot — **proven**, restores 282/282 |
| EN guide 22184 removed | `php scripts/remove-en-orphan-fixtures.php --apply --restore=<snapshot>` — **proven** |
| 38 EN records created | `php scripts/run-en-translation.php --remove --apply` (engine `allow_remove: true`); never touches PT |
| `.pot` regenerated | `git checkout -- <path>` |
| New plugin | deactivate and remove; no frontend effect |

## 28. Evidence

`docs/evidence/2026-09-26-stage-m/`: `baseline-inventory.txt`,
`2026-09-26-stage-m-plan.md`, `taxonomy-dry-run.txt`, `taxonomy-apply.txt`,
`taxonomy-snapshot.json`, `town-terms-prechange.txt`, `restore-proof.txt`,
`en-orphan-dry-run.txt`, `en-orphan-fixture-snapshot.json`,
`en-orphan-removal.txt`, `en-guide-apply.txt`, `en-page-dry-run.txt`,
`en-page-apply.txt`, `i18n-dry-run.txt`, `i18n-prechange.txt`, `i18n-apply.txt`,
`i18n-diff-analysis.txt`, `negative-proofs.txt`, `test-summary.txt`,
`gate.json`, `gate-output.txt`.

## 29. Definition of done, honestly

| Item | Status |
|---|---|
| Taxonomy 282 → 0 | done |
| `guide` missing EN → 0 | done |
| `page` missing EN → 0 | done |
| `post` missing EN → 0 | **not done (34 remain)** |
| Malformed relationships → 0 | done |
| i18n stale catalogues → 0 | done |
| Cache, redirect, documentation gates green | done |
| No gate weakened / allowlist broadened / CI tolerated | done — verified by 7 negative proofs |
| No regression | done — 3 newly fixed, 0 broken |
| PT immutable | done — 0 modified, PT drift 0 |
| No production writes | done — 0 |
| Flutter/mobile untouched | done — never accessed |

## 30. Final status

**BLOCKED.**

The stage is not `PASS` and not `PASS WITH LIMITATION`, because the authoritative
dataset still violates a permanent invariant: `post_type:post:missing_en = 34`
and the aggregate exits 1. Calling it `PASS` would be exactly the failure mode
this stage exists to prevent — reporting green while the repository does not
satisfy the invariant.

It is `BLOCKED` rather than merely incomplete because the outstanding work
(≈24,000 words of authored English for third-party editorial content) is real
work that was not completed, not a tooling or environment limitation. The
machinery to finish it — manifest, engine, batching, verification, rollback and
per-category gates — is in place, proven on 38 records, and the gate that
measures the remainder is untouched and still reporting the true number.

**Flutter / mobile confirmation: the Flutter/mobile repository was not touched,
not read, and not accessed.** Every path in this stage is inside
`/home/andrei/IdeaProjects/wordpress-website`. The scope freeze was respected in
full.

_Last verified: 2026-09-26 by Stage M — Permanent Invariant Debt Remediation_
