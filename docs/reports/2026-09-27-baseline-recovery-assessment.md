# Report — Baseline recovery assessment after the `597b04e` theme regression

| | |
|---|---|
| **Stage / task name** | `baseline_recovery_assessment` — read-only recovery assessment of the `597b04e` theme regression |
| **Date** | 2026-09-27 |
| **Author / agent** | Cline |
| **Branch** | `i18n` |
| **Start SHA** | `597b04e085afb9d2651cb7906b43e2c2b551c2f1` |
| **Final SHA** | `597b04e085afb9d2651cb7906b43e2c2b551c2f1` — **unchanged; no implementation was performed** |
| **Working tree at finish** | dirty only by **pre-existing** untracked artifacts (`docs/reports/2026-09-27-en-artigo-label-leak.md`, `docs/evidence/2026-09-27-en-artigo-label-leak/`) **plus this task's evidence directory and this report**. No tracked file was modified. |
| **Final status** | **`PASS WITH LIMITATION`** — the assessment is complete with real measurements; the *recovery itself* is deliberately not implemented (out of scope) and no production verification is possible from this repository. |

## 1. Scope completed

A full read-only recovery assessment, Phases 0–12:

- **Phase 0** — current state frozen and independently verified (§4, evidence `01`).
- **Phase 1** — `b47d098` independently established as the last verified baseline (§4.2, evidence `02`, `11`).
- **Phase 2** — complete structural comparison, `123 files changed, 8044 insertions(+), 25428 deletions(-)` (§5, evidence `02`).
- **Phase 3** — lost verified functionality inventoried at function-surface level: **282 → 162 theme `conexao_*` functions, 120 lost, 0 added** (§6, evidence `03`, `04`).
- **Phase 4** — catalogue/i18n damage assessed, including whether a catalogue-only restore could ever suffice (§7, evidence `05`).
- **Phase 5** — theme-structure intent assessed, distinguishing the *documented* commit intent from the *observed* effects (§8, evidence `06`).
- **Phase 6** — route regression inventory measured live (§9.1, evidence `07`).
- **Phase 7** — completed-task history cross-referenced, per-commit file survival proven rigorously (§10, evidence `10`, `11`).
- **Phases 8–9** — recovery strategy selected with alternatives and risks (§11, §12, evidence `12`).
- **Phase 11** — read-only verification ledger (§14, evidence `13`).

**No implementation was performed.** No file in `wp-content/` was modified, no catalogue regenerated, no route fixed, no gate weakened.

## 2. Scope NOT completed

- **No restoration, reset, revert, cherry-pick, merge or forward-port** — forbidden by the task and correctly out of scope. The recommendation in §11 is a *proposal*, not an applied change.
- **No branch reset and no revert of `597b04e`** — forbidden, and evidence §8 shows `597b04e` also carries legitimate new work that a naive revert would destroy.
- **`./scripts/run-tests.sh` (full suite) was not run.** Its in-process and acceptance layers create fixtures and touch the database; the task forbids mutating suites. The read-only static gate subset **was** run — see §14.
- **No production verification.** Production is WordPress.com with no SSH, WP-CLI, filesystem or database from this repository, so the production impact of the regression is assessed **by source and local measurement only**. This is the limitation behind the `PASS WITH LIMITATION` status.
- **No browser/Chromium or Playwright verification** — not required to establish the regression scope, and no meaningful "good" page state exists at this baseline.
- **Historical evidence was not re-run or rewritten.** Prior reports are cited as recorded.
- **Flutter/mobile was never accessed, inspected, built or tested.**

## 3. Files added / modified / deleted

**0 modified, 0 deleted in tracked code.** Additions are documentation/evidence only.

| Path | Change | Note |
|---|---|---|
| `docs/reports/2026-09-27-baseline-recovery-assessment.md` | added | This report. |
| `docs/evidence/2026-09-27-baseline-recovery/` | added | 13 evidence files, `01`–`13`. |

**No `wp-content/` file was touched.** `git status --short` confirms only untracked documentation paths.

**Pre-existing working-tree changes preserved, not modified:** `docs/reports/2026-09-27-en-artigo-label-leak.md` and `docs/evidence/2026-09-27-en-artigo-label-leak/` (from the `Artigo` investigation). Their content is *read and cited* below but never rewritten.

## 4. Current HEAD and last verified baseline

### 4.1 Current state (Phase 0, evidence `01`)

| Item | Value |
|---|---|
| **HEAD** | `597b04e085afb9d2651cb7906b43e2c2b551c2f1` — **confirmed**, matches the SHA named in the task |
| Branch | `i18n` |
| Upstream | `origin/i18n` (at `3cc6564`) — **`597b04e` is unpushed** |
| Commit subject | `Refactor code structure for improved readability and maintainability` |
| Commit date | Sun Sep 27 20:18:13 2026 +0100 |
| Unpushed commits | `597b04e`, `b47d098`, `b32fba3`, `88bdf22`, `8f07f30`, `8496245` |
| Docker | `default` context; `db` and `wordpress` (`:8080`) up |
| Working tree | 2 pre-existing untracked paths (§3) |

**Critically:** `597b04e` has **exactly one parent, `b47d098`**, and `git merge-base b47d098 597b04e` returns `b47d098`. The relationship is **linear, not a merge**, and `git log --ancestry-path b47d098..597b04e` returns **exactly one commit**. So the entire regression is contained in one commit, and `b47d098` is literally its parent.

### 4.2 Last verified baseline: `b47d098` — confirmed

`b47d098857d2348feafb1071b8713c6a43359e05` is independently verified as the last verified state, on four independent grounds:

1. **It is the direct parent of the regression** (`git log -1 --format='%P' 597b04e`).
2. **It carries the last completed task's evidence as its own tree** — the `event-importer` POT report records `Final SHA: b47d098`, and that commit *contains* the POT regeneration, the report and the 19 evidence files.
3. **Per-commit file-survival proof** (evidence `11`): every completed task before it has all its files present at `b47d098`, and the `597b04e` diff is the only thing that removes them.
4. **Its own report is an investigation, not an implementation** (`Add investigation report and SQL logs for /en/lazer/ archive performance`) — it changed no theme code, so the theme state it inherits is the last fully verified one.

`b47d098` **contains** the `Guia Prático` fix, the translation catalogues, the 43-file `inc/` tree, the accessibility `aria-current` semantics, the `/en/cursos/` provider-category filter work, the leisure investigation state and the B2/event-filter work. It is therefore a valid recovery candidate on the evidence, not merely by assumption.

## 5. Exact diff statistics (Phase 2, evidence `02`)

```
$ git diff --shortstat b47d098 597b04e
 123 files changed, 8044 insertions(+), 25428 deletions(-)

$ git diff --name-status b47d098 597b04e | awk '{print $1}' | sort | uniq -c
     68 D
     36 M
     19 A

$ git log --oneline --decorate --ancestry-path b47d098..597b04e
 597b04e (HEAD -> i18n) Refactor code structure for improved readability and maintainability
```

Per-area aggregation:

| Area | Files | Added | Deleted |
|---|---:|---:|---:|
| `theme/languages/` | 5 | 0 | **5644** |
| `theme/inc/` | 41 | 1425 | **10683** |
| `theme/tests/` | 33 | 472 | **7991** |
| `theme/root-templates` | 11 | 3934 | 216 |
| `theme/template-parts/` | 10 | 64 | 141 |
| `theme/assets/` | 4 | 24 | 203 |
| `plugins/` | 1 | 649 | 550 |
| `docs/` | 18 | 1476 | 0 |

**Classification of every significant change:**

| Class | Count | Detail |
|---|---:|---|
| **deleted** | 68 | 35 `inc/` modules, 5 `languages/*` catalogues, 22 theme tests, `template-parts/language-switcher.php` |
| **modified** | 36 | 11 root templates, 10 template parts, 4 assets, 7 surviving theme tests, 1 plugin POT, 3 theme `inc/` survivors |
| **added** | 19 | 18 `docs/evidence/2026-09-27-event-importer-pot/*` + 1 report |
| **renamed** | 0 | none |
| **structural refactor** | 1 | `inc/seo/*` (10 files, 1884 lines) → `inc/seo.php` (1413 lines) |
| **translation/catalogue** | 5 deleted | theme `languages/**` removed entirely |
| **theme/template** | 21 | 11 root + 10 template parts |
| **test** | 29 | 22 deleted outright, 7 surviving test files rewritten |
| **documentation** | 18 added | event-importer POT report + evidence |
| **generated artefact** | 1 modified | `conexao-event-importer.pot` regenerated (legitimate) |

## 6. Previously verified functionality lost (Phase 3, evidence `03`, `04`)

The theme function surface was measured by `git grep` of `function conexao_*` across all theme PHP at each commit:

| Metric | `b47d098` | `597b04e` | Delta |
|---|---:|---:|---:|
| `conexao_*` functions defined | **282** | **162** | **−120** |
| Functions added | — | **0** | net loss |
| `inc/` files | **43** | **8** | −35 |
| `functions.php` lines | 112 | **3937** | +3825 |
| `languages/` files | 5 | **0** | −5 |
| Theme test files | 30 | 8 | −22 |

**No function was introduced.** Nothing was "moved and renamed" — the count only went down. 120 functions were deleted outright.

### 6.1 The requested cross-reference table

Presence is verified by source, not inferred from file names.

| Previously verified functionality | Present at `b47d098` | Present at `597b04e` | Status |
|---|---|---|---|
| `/en/cursos/` filter restoration | yes — `inc/archive-query.php`, `inc/i18n/fallback.php`, `template-parts/course-filters.php`, `tests/test-en-course-provider-category-filter.php` | partial — `course-filters.php` survives; `archive-query.php`, `i18n/fallback.php` **deleted**; test **deleted** | **LOST** |
| provider category English labels | yes — `conexao_provider_category_label()` | **no** (function absent) | **LOST** |
| `Guia Prático` fix | yes — `inc/queries.php`, `template-parts/guide-filters.php`, `test-guide-en-translation.php` | `guide-filters.php` survives; `queries.php` and test **deleted** | **PARTIALLY LOST** |
| accessibility filter semantics | yes — `aria-current="true"` (commit `8f07f30`) | **no** — reverted to `role="listbox"`/`role="option"` | **REVERTED** (see §9.2) |
| i18n catalogue freshness | yes — POT + `en_US`/`pt_BR` `.po`/`.mo` (commits `88bdf22`, `8f07f30`) | **no** — `languages/` directory absent | **LOST** |
| event filter B2 widening | yes — `conexao_b2_*` (10 fns, commit `61131a4`) | **no** — all 10 gone; `inc/queries.php` deleted | **LOST** |
| leisure EN descriptions | yes — `conexao_leisure_card_excerpt()` (commit `53f1781`) | **no** — function absent | **LOST** |
| course-provider EN descriptions | yes — `provider-card.php` + `test-course-provider-card-excerpt-language.php` (commit `3cc6564`) | test **deleted**; `conexao_provider_card_excerpt` absent | **LOST** |
| event-town sanitization | yes — commit `d3ae9fb` | files survive (plugin/data-model side) | **INTACT** |
| EN blog archive | yes — commit `7778d76` (Stage O) | files survive | **INTACT at source, broken at runtime** (see §10) |
| other verified work: REST bilingual contract | yes — 18 `conexao_rest_language_*` functions | **no** — all 18 gone | **LOST** |
| other verified work: SEO suite | yes — 22 `conexao_seo_*` functions | 18 of 22 in new `inc/seo.php`; 4 gone | **PARTIALLY LOST** |
| other verified work: Polylang/locale/URL layer | yes — 26 `conexao_polylang_*`/`lang_*`/`hreflang_*` functions | **no** — all gone, incl. `conexao_current_locale()` | **LOST** |
| other verified work: B2 fallback + allowlist | yes — `inc/b2-fallback.php` | **no** — module deleted | **LOST** |

**Lost subsystem totals (evidence `03`):** `rest_language` **18**, Polylang/i18n/locale/URL/switcher **26**, `b2_*` **10**, `seo_*` **4** — plus the rest of the 120.

Note the specific breakage: the AGENTS.md safety rule *"Locale is read only through `conexao_current_locale()`"* is now unsatisfiable, because **`conexao_current_locale()` no longer exists**. The same is true of `conexao_lang_cache_key()`, `conexao_polylang_active()` and the whole B2 fallback contract.

## 7. Catalogue and i18n damage (Phase 4, evidence `05`)

| Catalogue | `b47d098` | `597b04e` |
|---|---|---|
| `themes/conexao-br-irlanda/languages/conexao-br-irlanda.pot` | present | **deleted** |
| `…/en_US.po` | present, **424 msgids** | **deleted** (0) |
| `…/en_US.mo` | present | **deleted** |
| `…/pt_BR.po` | present | **deleted** |
| `…/pt_BR.mo` | present | **deleted** |
| All 7 plugin `.pot` files | present | present (untouched) |

**Deletion is complete, not partial** — all 5 theme catalogue files are gone (5644 lines), and the directory itself no longer exists in the worktree. `ls wp-content/themes/conexao-br-irlanda/languages/` → *No such file or directory*.

**The generator survived and is still the repaired deterministic version.** `scripts/i18n-make-pot.sh` is **unchanged by `597b04e`** — byte-identical `sha256 de137d4aadf6d2d63cffba640de0ead41288aa24096a36c9b63e36f39877918d` at both commits, and `git diff b47d098 597b04e -- scripts/` is empty. The repair from commit `88bdf22` is intact.

**Is a catalogue-only restore sufficient? No.** Source-side infrastructure is also required, on three independent grounds:

1. **The strings' source is gone.** The `.pot` is generated *from* the theme. The `i18n-freshness` gate now reports *"39 file(s) define user-facing strings but `conexao-br-irlanda.pot` does not exist"* — restoring the file would satisfy that one check, but…
2. **The `__()`/`_e()` calls still exist in templates but have no runtime loader.** `load_theme_textdomain( 'conexao-br-irlanda', CONEXAO_THEME_DIR . '/languages' )` is still called by the new `functions.php` against a directory that no longer exists — so every translated string silently falls back to its **Portuguese** msgid. This is the proven mechanism of the `Artigo` leak (§9.3).
3. **Whole subsystems that *use* those labels are deleted.** `conexao_content_type_label()`, `conexao_provider_category_label()`, `conexao_leisure_card_excerpt()` and the entire B2/Polylang layer are gone. Restoring catalogues cannot restore behaviour that no longer has a caller.

So: **restoring catalogues alone fixes no user-visible defect.** The correct order is source infrastructure first, then regenerate/verify catalogues with the surviving generator.

## 8. Theme structure and commit intent (Phase 5, evidence `06`)

**Documented intent** (the entire commit message, with no body and no trailers):

> `Refactor code structure for improved readability and maintainability`

**Observed effects:** 35 modules deleted, 5 catalogues deleted, 22 tests deleted, 120 functions lost with none added, 3 previously-verified behaviours actively reverted in files that survived.

**Assessment: this is an accidental destructive commit, not a refactor.** The evidence does not support the documented intent:

- A refactor is **behaviour-preserving by definition**. This one is not: 120 functions disappeared, and cache-key scoping, ARIA semantics and the homepage label call were all *reverted to earlier states*.
- A refactor **moves** code; here the net function count only decreased and 0 functions were added, so nothing was relocated under a new name.
- The commit **carries no plan, no report, no evidence and no test updates** for a change of this size. Every other substantive task in this repository has a report and an evidence directory; this one has none.
- It **also carries unrelated legitimate work** — the `event-importer` POT repair, whose own report §3.1 *discloses* this very refactor as "a large concurrent external theme refactor" that the author deliberately did not touch. That is a contemporaneous statement that the refactor was **external, concurrent and unverified**.
- The documented load-order rationale in the `b47d098` `functions.php` (three load-bearing ordering constraints) has no counterpart in the new file.

The one genuinely refactor-shaped element is the SEO consolidation: `inc/seo/*` (10 files, 1884 lines) → `inc/seo.php` (1413 lines), preserving 18 of 22 functions. But even that is **incomplete** — it drops `conexao_seo_hreflang`, `conexao_seo_in_language`, `conexao_seo_missing_translation_redirect` and `conexao_seo_disable_jetpack_og_on_b2`.

## 9. Route regressions and behaviour reversions

### 9.1 Live route inventory (Phase 6, evidence `07`)

Read-only `curl` against the running local container at the current baseline:

| Route | Status | Redirect / canonical | Major visible regression |
|---|---|---|---|
| `/` | 200 | — | none observed |
| `/cursos/` | 200 | — | none observed |
| `/en/` | 200 | `lang="en-US"`, canonical `/en/` | renders, but content labels untranslated (see §9.3) |
| `/en/cursos/` | **301 → 200** | → `/2026/03/19/cursos-e-apoio-para-empreendedores/` | **broken**: lands on a single PT page, not the EN archive |
| `/en/guias/` | **404** | — | **broken**: no EN guides archive |
| `/en/lazer/` | **301 → `/lazer/`** | → PT | **broken**: EN leisure archive lost |
| `/en/blog/` | **301 → `/blog/`** | → PT | **broken**: real EN blog archive (Stage O, `7778d76`) lost |
| `/eventos/` | 200 | — | none observed |
| `/en/eventos/` | **404** | — | **broken**: no EN events archive |
| `/empregos/` | 200 | — | none observed |
| `/en/empregos/` | **301 → `/empregos/`** | → PT | **broken**: EN redirects to PT |

Every EN archive is either 404 or silently redirected to its PT counterpart — the direct consequence of deleting `conexao_language_archive_url()`, the B2 fallback shell and the archive query modules. **PT content and PT URLs are intact**, consistent with the AGENTS.md rule that PT is immutable; the damage is confined to the `/en/` layer.

`/` returns 2 `hreflang` links, but this comes from the surviving `inc/seo.php` plus core, not from the deleted `conexao_hreflang_links()` / `conexao_seo_hreflang()`.

### 9.2 Behaviour actively reverted in surviving files — the causation proof (evidence `08`)

This is the decisive finding: `597b04e` did not merely *omit* verified work, it **wrote older behaviour back over it** in files it kept.

**A. Language-scoped cache keys (Stage 2 / AGENTS.md invariant)** — `404.php`:

```php
// b47d098  — language-scoped (correct)
$popular_guides = get_transient( conexao_lang_cache_key( 'conexao_404_guides' ) );
set_transient( conexao_lang_cache_key( 'conexao_404_events' ), $upcoming_events, 300 );

// 597b04e — scoping REMOVED
$popular_guides = get_transient( 'conexao_404_guides' );
set_transient( 'conexao_404_events', $upcoming_events, 300 );
```

The **`cache-key-scoping` permanent gate is now RED** with 4 violations — it was green at `b47d098`. AGENTS.md requires *"Content-derived cache keys are language-scoped"*; that invariant is broken at the current baseline.

**B. Accessibility filter semantics** (verified task, final SHA `8496245`, report `2026-09-27-a11y-filter-option-role.md`):

| File | `b47d098` | `597b04e` |
|---|---|---|
| `event-filters.php` | `aria-current="true"`, native link role | `role="listbox"` + `role="option"` + `aria-selected` |
| `leisure-filters.php` | `aria-current="true"` | `role="listbox"` + `role="option"` + `aria-selected` |

The 20-assertion a11y fix was **reverted to the invalid pattern it removed**.

**C. Homepage content-type label** — `front-page.php` called `conexao_content_type_label( get_post_type() )` twice at `b47d098`; at `597b04e` both calls are **gone**, replaced by `get_post_type_object()`. The helper itself was deleted with `inc/content.php`.

### 9.3 The `Guia Prático` and `Artigo` regressions explained

The task requires the `Guia Prático` regression to be explained as part of this baseline regression, and the `Artigo` investigation result to be incorporated. Both are the same root cause:

1. `597b04e` deleted `inc/i18n/*` and `inc/content.php`, removing the helpers and the fallback path.
2. It deleted all 5 theme catalogues, so `load_theme_textdomain()` now points at a non-existent directory.
3. Therefore **every** `__()`/`_e()` string resolves to its Portuguese msgid, site-wide — for **all** content types, not just blog posts.
4. The `Artigo` report already proved this at the same baseline SHA: `/en/` renders `Artigos e Notícias` because `home.php:18` passes the PT literal through `__()`, and the catalogue that would translate it no longer exists. The report's own source trace notes `conexao_content_type_label()` "does not appear on `/en/` at this baseline" — consistent with §9.2C.

So the `Artigo` leak and the `Guia Prático` regression are **not independent bugs**. They are two symptoms of one site-wide catalogue-and-helper loss introduced by `597b04e`. The `Artigo` report's `BLOCKED` status ("a site-wide regression introduced by the baseline commit itself") is **confirmed correct** by this assessment and is incorporated, not overturned.

## 10. Comparison against existing evidence (Phase 7, evidence `10`, `11`)

Per-commit file-survival proof (`git cat-file -e 597b04e:<path>` for every file each completed task touched):

| Commit | Task | Files | Deleted at `597b04e` | Verdict |
|---|---|---:|---:|---|
| `61131a4` | B2 filter helper language widening | 3 | 1 (`inc/queries.php`) | **implementation lost** |
| `d3ae9fb` | event-town sanitization | 14 | 0 | intact |
| `89c5889` | Laois events visibility repair | 19 | 0 | intact |
| `7778d76` | real English blog archive (Stage O) | 34 | 0 | intact at source, **broken at runtime** (route 301 → PT, §9.1) |
| `8496245` | provider category filter + EN labels | 25 | 5 (`inc/i18n/fallback.php`, `inc/queries.php`, …) | **implementation lost** |
| `8f07f30` | ARIA semantics + homepage label tests | 58 | 3 (`inc/content.php`, `test-filter-link-aria-semantics.php`, …) | **implementation lost and reverted** |
| `88bdf22` | regenerate stale theme catalogue + repair generator | 3 | 1 (the theme `.pot`) | **catalogue lost, generator intact** |
| `b32fba3` | i18n catalogue freshness investigation | 28 | 0 | intact (report survives) |
| `3cc6564` | EN course-provider descriptions | 31 | 2 (`inc/i18n/fallback.php`, `test-course-provider-card-excerpt-language.php`) | **implementation lost** |
| `53f1781` | EN leisure descriptions | 25 | 1 | **implementation lost** |

`7778d76` deserves emphasis: its files all survive, yet `/en/blog/` now 301s to PT. The EN blog archive was broken **indirectly**, by deleting the B2/Polylang layer it depends on. This proves that "file still present" is not sufficient evidence of functionality — behaviour had to be verified, as this task required.

**No historical evidence was modified.** The acceptance matrix `tests/acceptance/matrices/routing.json` is **untouched by `597b04e`** (`git diff b47d098 597b04e -- tests/` is empty) and still contains **40 `/en/` rows** asserting the old, correct behaviour — which is why the running site now contradicts its own committed acceptance matrix.

## 11. Recommended recovery strategy

### Recommendation: **Option A (restore `b47d098`), with a small, explicit carve-out to re-apply the `event-importer` POT repair.**

**Recommended form of action — a non-destructive restore to a new state, not a branch reset:**

1. Recover the theme tree to `b47d098` (e.g. `git restore --source=b47d098 -- wp-content/themes/conexao-br-irlanda/`, or an equivalent revert of `597b04e` restricted to that path).
2. Keep the `event-importer` POT regeneration, its report and its 19 evidence files (see below).
3. Delete the new `inc/seo.php` so the restored `inc/seo/*` (10 modules, 22 functions) is not shadowed by a duplicate that would cause **fatal redeclaration**.
4. Re-run the full suite and the permanent gates; expect `cache-key-scoping` and `i18n-freshness` to return to green.
5. Record the regression and its cause in the next report.

**Why Option A, on the evidence:**

- `b47d098` is the **direct parent** of the regression and the last state in which every completed task's files were present (§4, §10).
- `597b04e` is **demonstrably destructive**, not a refactor: it reverts verified behaviour in surviving files (§8, §9.2). Its intent is documented only by a one-line message, and a contemporaneous report flags it as external/concurrent/unverified.
- There is **no newer intentional website work** that must be preserved. The only new work in `597b04e` is the `event-importer` POT repair — a **generated artefact + documentation**, not website behaviour. A carve-out keeps it at near-zero cost.
- Restoring the known-good state is the **lowest-risk** recovery: it restores 120 functions, 5 catalogues, 22 tests and 3 reverted invariants in one step, all of which are already proven.

**Why not Option B (forward-port):** the amount to port is the whole verified surface — 120 functions, the full Polylang/B2/REST layer, 5 catalogues and 22 test suites — against a baseline that has none of them. That is functionally a re-implementation with a much higher defect risk than a restore, and it would leave the reverted call sites (`404.php`, the two filter template parts, `front-page.php`) to be found one by one.

**Why not Option C (finish a migration):** rejected because the evidence does not support an intentional migration (§8), and because the only migration-shaped element (SEO consolidation) is itself incomplete, dropping 4 SEO functions. If a modular→monolithic direction is genuinely wanted, that is a *separate, planned* task to run **after** the restore on a green baseline — not a recovery.

**Carve-out justification (the only new work to preserve):**

| Path | Why it must be kept |
|---|---|
| `wp-content/plugins/conexao-event-importer/languages/conexao-event-importer.pot` | Regenerated catalogue; 3 duplicate msgids → 0; `msgfmt --check`/`msgcat` exit 0; proven deterministic and idempotent. Unrelated to the theme regression. |
| `docs/reports/2026-09-27-event-importer-pot-duplicate-msgids.md` + `docs/evidence/2026-09-27-event-importer-pot/` | The task's report and 19 evidence files. Historical evidence must not be lost. |

## 12. Alternatives considered and risks

### 12.1 Alternatives

| Option | Verdict | Reason |
|---|---|---|
| **A — restore `b47d098`** (+ POT carve-out) | **RECOMMENDED** | Parent = last verified state; regression proven destructive; nothing of behavioural value lost except the one artefact. |
| B — forward-port selected changes | rejected | 120 functions to port against a baseline with none; equivalent to re-implementation. |
| C — resolve a structural migration | rejected | No evidence of an intentional migration; the one consolidation present is incomplete. |
| Do nothing / treat `597b04e` as new normal | rejected | Would ratify 120 lost functions, a red cache-scoping gate, a red i18n gate and 4 broken EN routes. |

### 12.2 Risks of the recommended recovery

| Risk | Severity | Assessment / mitigation |
|---|---|---|
| **Duplicate function redeclaration (fatal)** | **High** | Restoring `inc/seo/*` while `inc/seo.php` survives would redeclare 18 functions → PHP fatal, white screen. **Mitigation: delete `inc/seo.php` in the same change** (§11 step 3). |
| **Catalogue/source ordering** | **High** | Restoring `.po`/`.mo` without the helpers that call them is inert. **Mitigation: restore source first, verify, then regenerate/validate catalogues with the surviving generator.** |
| **High-conflict files** | Medium | `functions.php`, `404.php`, `front-page.php`, `template-parts/event-filters.php`, `template-parts/leisure-filters.php`, `inc/seo.php` vs `inc/seo/*`, and `conexao-event-importer.pot`. All are touched by both sides. |
| **Acceptance-matrix contradiction** | Medium | `routing.json` (40 `/en/` rows) currently contradicts the running site. It should go **green** after the restore — use it as the primary acceptance signal. |
| **Test-suite restoration** | Medium | 22 theme test files and 7991 lines return; the runner discovers suites by convention, so they re-enter automatically. Expect a larger green surface. |
| **Polylang** | Low–Medium | No Polylang *configuration* was changed by `597b04e`, and PT is intact. Risk is limited to EN redirect/canonical behaviour, exactly what the restored B2 layer fixes. Verify `/en/*` 200s and hreflang after restore. |
| **B1/B2** | Low–Medium | The 10 `conexao_b2_*` functions and `inc/b2-fallback.php` return. Re-verify the B2 fallback is *canonical → PT, noticed, out of the sitemap* after restore. |
| **Content / data** | **Low** | No content, DB or taxonomy mutation is involved. PT content is immutable and untouched. Event-town sanitization (`d3ae9fb`) and the Stage O blog archive (`7778d76`) are unaffected. |
| **Cache-scoping invariant** | Low | The regression is a direct consequence; restoring `conexao_lang_cache_key()` usage in `404.php` returns the gate to green. Verify explicitly. |
| **Documentation drift** | Low | `docs/` gained 18 files but lost none. The `agent-governance` gate is red at this baseline (247/7) and should be re-checked after restore. |
| **Generated-artefact risk** | Low | Keep the repaired `event-importer.pot`; do **not** re-run the generator over the theme unless intended, and never hand-edit a catalogue. |
| **Production** | Low | No production action is authorised or needed. The regression has not been deployed (`597b04e` is **unpushed**, `origin/i18n` = `3cc6564`), so **production is unaffected** — a materially important containment fact. |

## 13. Files the next implementation task must change

**Restore (from `b47d098`), all under `wp-content/themes/conexao-br-irlanda/`:**

- **35 deleted `inc/` modules**: `admin.php`, `archive-filters.php`, `archive-query.php`, `assets.php`, `b2-fallback.php`, `cache.php`, `content.php`, `customizer.php`, `events.php`, `i18n.php`, `i18n/{fallback,guard,hreflang,locale,switcher,terms,urls}.php`, `leisure.php`, `navigation.php`, `performance.php`, `queries.php`, `rest-language.php`, `seo/{canonical,hreflang,meta,open-graph,redirects,related,robots,schema,sitemap,titles}.php`, `setup.php`, `shortcodes.php`, `sponsors.php`, `ui.php`
- **5 catalogues**: `languages/conexao-br-irlanda.pot`, `en_US.po`, `en_US.mo`, `pt_BR.po`, `pt_BR.mo`
- **22 theme tests** (incl. `test-filter-link-aria-semantics.php`, `test-homepage-content-type-label.php`, `test-en-course-provider-category-filter.php`, `test-guide-en-translation.php`, `test-stage41-rest-language.php`, `test-taxonomy-policy.php`, `test-translation-completeness.php`, …)
- **`template-parts/language-switcher.php`**
- **`functions.php`** — restore the 112-line leader with its load-order constraints
- **Revert 3 call-site regressions**: `404.php` (`conexao_lang_cache_key()`), `template-parts/event-filters.php` + `template-parts/leisure-filters.php` (`aria-current`), `front-page.php` (`conexao_content_type_label()`)
- Review the other 32 modified templates/assets against `b47d098`; `assets/css/main.css` and `assets/js/main.js` are the largest.

**Delete:** `wp-content/themes/conexao-br-irlanda/inc/seo.php` (prevents fatal redeclaration).

**Keep (do not revert):** the `event-importer.pot` regeneration, its report and its 19 evidence files.

**Likely unchanged:** `plugins.json`, `AGENTS.md`, `docs/plugins/**`, `tests/**` (all verified untouched by `597b04e`).

## 14. Verification commands and results (Phase 11, evidence `13`)

Read-only only.

| Command | Result |
|---|---|
| `git rev-parse HEAD` | `597b04e085afb9d2651cb7906b43e2c2b551c2f1` — **matches the task's SHA** |
| `git log -1 --format=%P 597b04e` | single parent `b47d098` — linear, not a merge |
| `git merge-base b47d098 597b04e` | `b47d098` |
| `git log --ancestry-path b47d098..597b04e` | exactly **1** commit |
| `git diff --shortstat b47d098 597b04e` | **123 files, +8044, −25428** |
| `git diff --name-status … \| awk '{print $1}' \| sort \| uniq -c` | **68 D, 36 M, 19 A** |
| theme `conexao_*` function count, both commits | **282 → 162 (−120, 0 added)** |
| `inc/` file count | **43 → 8** |
| `functions.php` lines | **112 → 3937** |
| theme `languages/` files | **5 → 0**; `en_US.po` msgids **424 → 0** |
| `sha256sum scripts/i18n-make-pot.sh` at both commits | **identical** → repaired generator survives |
| `git diff b47d098 597b04e -- tests/` | **empty** → acceptance matrix still asserts the old good behaviour (40 `/en/` rows) |
| `curl` route sweep (11 routes) | 3 × **200 OK**, 3 × **404**, 2 × **301 to PT**, 1 × **301 to a PT page**, `/en/` 200 — see §9.1 |
| `git status --short` | only untracked docs; **0 tracked modifications** |

### Numeric gate results at the current (broken) baseline

Run read-only, **not** to make them pass — these numbers describe the regression:

| Gate | Result |
|---|---|
| `verify-i18n-freshness.py` | **FAIL** — 8 passed / 1 failed: theme `.pot` does not exist (8 plugin catalogues `fresh`) |
| `verify-cache-key-scoping.py` | **FAIL** — 2 passed / 4 failed: unscoped keys in `404.php` |
| `verify-documentation-drift.py` | **FAIL** — 13 passed / 1 failed (agent-governance 247/7) |
| `verify-agent-governance.py` | **FAIL** — 247 passed / 7 failed |
| `verify-release-integrity.py` | **PASS** — 210 passed / 0 failed |

**Not run, deliberately:** `./scripts/run-tests.sh` full suite; any acceptance suite that writes fixtures; any catalogue regeneration; `git restore/reset/cherry-pick/merge`. No mutating test ran against the database, and no fixture was created.

## 15. Failure proofs / negative tests

The permanent gates themselves prove the regression is detectable and were not weakened to hide it:

- `verify-cache-key-scoping.py` correctly reports **4** violations with exact file/line/key, and correctly distinguishes the 2 passing components.
- `verify-i18n-freshness.py` correctly reports the **theme** catalogue missing while all **8 plugin** catalogues are `fresh` — a precise, non-blanket failure.
- `verify-documentation-drift.py` correctly propagates the nested governance failure rather than swallowing it.
- `verify-release-integrity.py` returning **210/0** confirms the failures are scoped, not a broken harness.

No gate was modified, bypassed or weakened, and no historical gate evidence was altered.

## 16. Regression comparison

| Signal | `b47d098` (verified) | `597b04e` (current) |
|---|---|---|
| Theme `conexao_*` functions | 282 | **162** |
| `inc/` modules | 43 | **8** |
| `functions.php` lines | 112 | 3937 |
| Theme catalogues | 5 | **0** |
| Theme test files | 30 | **8** |
| EN routes returning 200 | `/en/`, `/en/cursos/`, `/en/guias/`, `/en/lazer/`, `/en/blog/`, `/en/eventos/`, `/en/empregos/` | only `/en/` |
| `cache-key-scoping` gate | green | **red (4)** |
| `i18n-freshness` gate (theme) | green | **red (1)** |
| `release-integrity` gate | green | green (210/0) |

The diff of the failing list is **not empty**: two permanent invariant gates regressed. No failure in the table is pre-existing relative to `b47d098`.

## 17. Known pre-existing failures

- The `agent-governance` gate (247 passed / 7 failed) reports a missing "Required reading" link in **10 skill files** (`.agents/skills/*`). This is **not** caused by the theme regression — `597b04e` touched no `.agents/` file. It is recorded, left untouched, and is out of scope.
- `release-integrity` is green and is reported for completeness.

## 18. Limitations

1. **No production verification.** Production is WordPress.com (no SSH, WP-CLI, filesystem or database). The production impact of the regression is inferred from source and local measurement only. **Containment fact:** `597b04e` is unpushed (`origin/i18n` = `3cc6564`), so production is very likely unaffected — but this cannot be *proven* from this repository.
2. **Full test suite not run.** `./scripts/run-tests.sh` was not executed because its in-process and acceptance layers create fixtures and touch the database, which this task forbids. The baseline therefore has no recorded in-process/acceptance pass/fail counts, only the read-only static gates.
3. **Browser-level verification not performed.** Route and content checks are `curl`-based; no rendered-DOM or accessibility-tree verification was done, so client-side breakage beyond the HTTP responses is not characterised.
4. **Local dataset caveats.** Per the existing a11y report, the local dataset renders **zero** filter options for `/lazer/` and `/eventos/`, so those filter widgets were assessed by source inspection, not live interaction.
5. **Intent is inferred from effects.** The author of `597b04e` was not available. The conclusion "accidental destructive commit" rests on the objective facts in §8 (behaviour reverted, 0 functions added, no report/plan, disclosed as external by a contemporaneous report) — not on any statement about the author's state of mind.
6. **`Artigo` investigation was read, not re-run.** Its conclusion is corroborated by this assessment's independent measurements (§9.3) but its evidence files were not regenerated.

## 19. Evidence paths

`docs/evidence/2026-09-27-baseline-recovery/`

| File | Proves |
|---|---|
| `01-phase0-frozen-state.txt` | HEAD, branch, upstream, status, commit date, unpushed list, Docker context |
| `02-phase2-structural-diff.txt` | ancestry, single parent, shortstat, per-area numstat, `inc/` and `functions.php` sizes |
| `03-phase3-function-surface.txt` | 282 → 162 function surface, subsystem breakdown, full list of 120 lost functions |
| `04-phase3-feature-presence.txt` | named-feature presence matrix for the previously verified fixes |
| `05-phase4-catalogue-damage.txt` | catalogue inventory both commits, msgid counts, generator identity, i18n infra deletions |
| `06-phase5-theme-structure.txt` | commit metadata, `inc/` inventory, new `inc/seo.php` vs old `inc/seo/*`, lost SEO functions |
| `07-phase6-route-inventory.txt` | 11-route HTTP sweep, lang/canonical/hreflang, EN label sample |
| `08-causation-behaviour-reverted.txt` | cache-key, ARIA and homepage-label reversions with side-by-side code |
| `09-permanent-gates-AT-BASELINE.txt` | read-only gate output at the broken baseline |
| `10-phase7-evidence-crossref.txt` | report final SHAs, acceptance matrix and registry untouched by `597b04e` |
| `11-phase7-per-commit-survival.txt` | rigorous per-commit file-survival proof |
| `12-phase8-9-recovery-options.txt` | what must be preserved, restore blast radius, high-conflict files |
| `13-phase11-verification-ledger.txt` | read-only ledger of everything run and deliberately not run |

## 20. Documentation updated

Only this report and its evidence directory were added. **No existing document was modified**, so no `_Last verified_` line needed updating. In particular, no theme documentation was touched.

## 21. Rollback / recovery

This task changed **no tracked file**, so it needs no rollback: `git status --short` shows only untracked documentation. Deleting `docs/evidence/2026-09-27-baseline-recovery/` and this report fully reverts it. The repository HEAD remains `597b04e`, and the regression itself is **not** rolled back here — that is §11's proposal for a separate, authorised implementation task.

## 22. Final status

| Status | Use when |
|---|---|
| `PASS` | Every applicable gate passed with real numbers, no required verifier unavailable. |
| **`PASS WITH LIMITATION`** | The work is complete, but a **required** verification capability was unavailable. |
| `BLOCKED` | A required implementation could not be completed safely. |
| `NOT TESTABLE` | The change could not be meaningfully verified. |

**Final status: `PASS WITH LIMITATION`**

The read-only assessment completed in full with real measurements: HEAD independently confirmed as `597b04e`; its single parent `b47d098` independently established as the last verified baseline; the full `b47d098..597b04e` delta assessed (123 files, −25428/+8044); 120 lost functions, 5 deleted catalogues and 22 deleted test suites inventoried; 3 previously-verified behaviours proven actively reverted; EN route regressions documented live; a single recovery strategy (Option A with an `event-importer` POT carve-out) recommended with evidence, alternatives and risks. The limitations are that the full test suite, production and browser-level verification were not run, as recorded in §18.

### Explicit statements

- **No implementation was performed.** No source change, no catalogue regeneration, no database change, no Polylang change, no route change. Nothing was restored, reset, reverted, cherry-picked, merged or forward-ported.
- **Production actions: none.** No production write, deploy, upload, activation or content/DB mutation.
- **Flutter/mobile actions: none.** The Flutter/mobile repository was never accessed, inspected, built, tested or modified.
- **No gate was weakened** and no historical evidence was altered or deleted.
- **Pre-existing working-tree changes were preserved** (`docs/reports/2026-09-27-en-artigo-label-leak.md` and its evidence directory).

---

_Last verified: 2026-09-27 — baseline recovery assessment, read-only, at `597b04e`_








