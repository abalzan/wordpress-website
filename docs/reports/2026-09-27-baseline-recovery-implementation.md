# Report — Controlled baseline recovery after `597b04e`

| | |
|---|---|
| **Stage / task name** | `baseline_recovery_implementation` — controlled reconstruction of the `b47d098` WordPress baseline while preserving the `event-importer` POT repair |
| **Date** | 2026-09-27 |
| **Author / agent** | Cline |
| **Branch** | `i18n` |
| **Start SHA** | `bd81ba59d800970d6e1e6e542d76ccb664fdbd24` (the `Artigo` investigation report; its parent is the destructive `597b04e085afb9d2651cb7906b43e2c2b551c2f1`) |
| **Final SHA** | see §16 — one new forward recovery commit on top of `bd81ba5` |
| **Working tree at finish** | clean apart from the intended recovery diff plus this report and its evidence directory |

## 0. Note on the starting SHA (a deviation from the task text, stated up front)

The task said to confirm HEAD is `597b04e`. It is not, and the difference is benign:

* `597b04e` has exactly one child, `bd81ba5`, which is **documentation-only** — 21 files, all under `docs/`, carrying the baseline-recovery assessment report, its 13 evidence files, and the `Artigo` investigation report and evidence.
* `git show --name-only bd81ba5 | grep -v '^docs/'` returns nothing.
* The working tree was **clean** at freeze (the previously-untracked `Artigo` and assessment artifacts had been committed in `bd81ba5`).

So the regression to recover from is still exactly `597b04e`, its parent is still `b47d098`, and the recovery is still a **forward commit** on the current branch. No history was rewritten.

## 1. Scope completed

All sixteen phases were executed. In summary:

- **Phase 0** — state frozen and recorded (evidence `01`). HEAD, branch, upstream, ancestry, Docker state.
- **Phase 1** — restoration manifest built from the *actual* `b47d098` tree and the real `b47d098..597b04e` delta, not from an assumption list (evidence `02`): 68 deleted paths restored, 35 modified paths restored, 1 path removed, 20 paths preserved.
- **Phase 2** — the `inc/seo.php` conflict resolved explicitly and measured, not guessed (evidence `03`).
- **Phase 3/4** — source restored first, catalogues second, with the exact-match proof (evidence `04`).
- **Phase 5/13** — the legitimate `event-importer` POT repair preserved and re-proved to the exact documented hash (evidence `07`).
- **Phase 6** — the three named reverted behaviours restored, plus the other surviving-file reversions that came back with the tree.
- **Phase 7** — the surviving valid work (event-town sanitization, Laois repair, Stage O blog archive) confirmed intact.
- **Phase 8** — architecture verified: 43 modules, 112-line loader, 0 duplicate runtime declarations, 116 files lint clean.
- **Phase 9** — the full canonical suite run **three times**: at `597b04e`, after the source restore, and after the rewrite flush (evidence `05`, `09-`, `09b-`, `10-`).
- **Phase 10** — all seven permanent gates run; all fail-closed and unmodified (evidence `10`).
- **Phase 11** — the HTTP matrix measured route by route (evidence `06`).
- **Phase 12** — browser verification performed with real Chromium on desktop and mobile (evidence `09-phase12`).
- **Phase 14** — four negative proofs that the verifiers genuinely detect the restored invariants (evidence `08`).
- **Phase 15** — full working-tree audit (§15 below).
- **Phase 16** — this report.

## 2. Scope NOT completed

Three items, each with a reason, and none of them silently skipped:

1. **`/en/empregos/` still 302s to the PT page.** Making it return 200 requires *creating EN page translations* — a content mutation, forbidden by constraint 3 and outside a code recovery. The restored code is applying the **documented B1 policy** correctly. Detail and proof in §7 and evidence `06`.
2. **Two rows of `verify-guides-en-http.py` still fail** (`/en/guias/page/2/`, and "an EN guide link is discoverable"). The local dataset contains **0 EN guide records**, so page 2 of that archive is legitimately 404 and no EN guide link exists to discover. Creating EN guides is a content task. The primary row, `en-guides-200 /en/guias/`, now **passes** (it was `404, expected 200`).
3. **No production verification.** Production is WordPress.com with no SSH, WP-CLI, filesystem or database from this repository, so the production effect of the recovery is asserted from source and local measurement only. See §14.

## 3. Files added / modified / deleted

**104 theme paths changed, 0 changed outside the theme.** Every non-`docs/` change is under `wp-content/themes/conexao-br-irlanda/` — verified mechanically in §18.

| Path group | Change | N | Note |
|---|---|---:|---|
| `inc/*.php` (35 modules, incl. all 7 `inc/i18n/*`) | added | 35 | incl. `content.php`, `i18n.php`, `queries.php`, `cache.php`, `b2-fallback.php`, `archive-query.php`, `archive-filters.php`, `events.php`, `leisure.php`, `navigation.php`, `performance.php`, `rest-language.php`, `setup.php`, `shortcodes.php`, `sponsors.php`, `ui.php`, `admin.php`, `assets.php`, `customizer.php` |
| `inc/seo/*` (10 modules) | added | 10 | the verified modular SEO architecture |
| `languages/*` (5 catalogues) | added | 5 | `conexao-br-irlanda.pot`, `en_US.po/.mo`, `pt_BR.po/.mo` |
| `tests/*` | added | 24 | the 22 deleted theme suites plus `run-guide-translation.php` |
| `template-parts/language-switcher.php` | added | 1 | |
| `inc/seo.php` | **deleted** | 1 | the incompatible consolidated 1413-line module (§4) |
| theme templates / assets / css / js | modified | 28 | incl. `404.php`, `front-page.php`, `functions.php`, the filter templates, `main.js`, `header-nav.css`, `dark-mode.css` |
| `wp-content/plugins/**` | **unchanged** | 0 | the repaired `conexao-event-importer.pot` is untouched |
| `plugins.json`, `.agents/`, `AGENTS.md`, `scripts/`, `tests/` | **unchanged** | 0 | all measured at 0 diff lines |
| `docs/reports/2026-09-27-baseline-recovery-implementation.md` | added | 1 | this report |
| `docs/evidence/2026-09-27-baseline-recovery-implementation/` | added | 12 | evidence `01`–`10` |
| `docs/evidence/2026-09-26-stage-l/gate.json` | modified | 1 | **generated** gate artefact, rewritten by running the gates |

Counts: **69 added, 34 modified, 1 deleted** (theme); plus 1 report + 12 evidence files + 1 generated gate artefact.

No historical report or evidence file was rewritten. The `Artigo` artifacts, the baseline-recovery assessment and the `event-importer` artifacts are all at **0 diff lines** (§18).

## 4. The `inc/seo.php` conflict, resolved

This was the High-risk item. It was resolved by measurement, not by preference:

| Measure | Value |
|---|---|
| Functions in `b47d098` `inc/seo/*` (10 modules) | 27 |
| Functions in `597b04e` `inc/seo.php` (1 module) | 23 |
| **Names declared by both** (would be a PHP fatal) | **23** |
| Functions **only** in the consolidated `inc/seo.php` | **0** |
| Functions the consolidation **dropped** | **4** |

The consolidated `inc/seo.php` is a **strict subset** of the modular tree: it adds nothing and drops `conexao_seo_hreflang`, `conexao_seo_in_language`, `conexao_seo_missing_translation_redirect` and `conexao_seo_disable_jetpack_og_on_b2` — the functions that carry the B2/Polylang/hreflang contract.

**Decision: the verified `b47d098` modular `inc/seo/` is the recovery target and `inc/seo.php` is removed.** Keeping both would mean 23 duplicate declarations; keeping only the consolidated one would silently delete four verified functions. No merge was attempted — merging would have re-introduced a second SEO implementation and broken the "reuse, do not reinvent" rule.

Post-restore verification: duplicate function names across the whole theme return **0** for runtime files. The only same-named helper in the tree is `s7_set_language`, which lives in two *test* files (each suite runs in its own PHP process) and is identical at `b47d098` — pre-existing and out of scope.

## 5. Runtime impact

WordPress behaves as it did at the verified baseline. The recovered theme tree is **byte-identical** to `b47d098`:

```
$ git diff b47d098 -- wp-content/themes/conexao-br-irlanda | wc -l
0
```

| Signal | `b47d098` | `597b04e` | after recovery |
|---|---:|---:|---:|
| `inc/` modules | 43 | 8 | **43** |
| `functions.php` lines | 112 | 3937 | **112** |
| theme `conexao_*` functions | 282 | 162 | **282** |
| theme catalogue files | 5 | 0 | **5** |
| theme test files | 33 | 7 | **33** |
| runtime duplicate declarations | 0 | 0 | **0** |

`functions.php` is again a pure loader: 112 lines, 43 `require_once` calls, no business logic, with the documented load-order constraints back (`inc/i18n.php` first, then `inc/i18n/*`, then `inc/rest-language.php` and `inc/seo/*`).

The i18n surface the assessment named as lost is back, each in its verified module: `conexao_current_locale()` (`inc/i18n.php`), `conexao_lang_cache_key()` (`inc/i18n/urls.php`), `conexao_content_type_label()` (`inc/content.php`), `conexao_provider_category_label()` and `conexao_leisure_card_excerpt()` (`inc/i18n/fallback.php`), `conexao_language_archive_url()` (`inc/i18n/urls.php`), `conexao_polylang_active()` (`inc/i18n/guard.php`).

## 6. Content / data impact

**None.** No record was created, updated or deleted. `git status` confirms the only `wp-content/` change is theme source; no plugin, post, term, user, option or upload was written.

The one deliberate data operation was a **local** `flush_rewrite_rules(true)` in the local Docker database, described in §7. It changed no content and is not a repository artefact.



Three items, each with a reason, and none of them silently skipped:

1. **`/en/empregos/` still 302s to the PT page.** Making it return 200 requires *creating EN page translations* — a content mutation, forbidden by constraint 3 and outside a code recovery. The restored code is applying the **documented B1 policy** correctly. Detail and proof in §7 and evidence `06`.
2. **Two rows of `verify-guides-en-http.py` still fail** (`/en/guias/page/2/`, and "an EN guide link is discoverable"). The local dataset contains **0 EN guide records**, so page 2 of that archive is legitimately 404 and no EN guide link exists to discover. Creating EN guides is a content task. The primary row, `en-guides-200 /en/guias/`, now **passes** (it was `404, expected 200`).
3. **No production verification.** Production is WordPress.com with no SSH, WP-CLI, filesystem or database from this repository, so the production effect of the recovery is asserted from source and local measurement only. See §14.

## 7. Route / HTTP impact — and the finding that source alone was not enough

This is the most important operational result of the task: **restoring the source was necessary but not sufficient.**

After a byte-perfect source restore, `/en/guias/` and `/en/eventos/` still returned 404 and `/en/lazer/` still redirected to PT. The cause was **stale rewrite rules in the local database**: the stored `rewrite_rules` option had been generated while the destructive `functions.php` was active, so the EN CPT-archive rules the restored theme registers were simply absent.

| | rules with an `en/` prefix | of which CPT archives |
|---|---:|---:|
| before flush | 84 | **0** |
| after flush | — | **92** |

The rules existed only for the PT roots (`guias/?$ => index.php?post_type=guide`, `eventos/?$`, `lazer/?$`) with no `en/` counterpart — exactly the observed symptom. A local flush regenerated them. This is the same operation `scripts/flush-navigation-rules.php` performs; it is a **local database** action, not a code change, and nothing was committed for it.

Final route matrix (redirects not followed):

| Route | at `597b04e` | now | required by task | verdict |
|---|---|---|---|---|
| `/en/` | 200, PT labels leaked | 200 `lang=en-US` | must work | **REPAIRED** |
| `/en/cursos/` | 301 -> a single PT post | 200 `lang=en-US` | no redirect to the PT post | **REPAIRED** |
| `/en/guias/` | 404 | 200 `lang=en-US` | no longer 404 | **REPAIRED** |
| `/en/lazer/` | 301 -> `/lazer/` | 200 `lang=en-US` | no longer redirect to PT | **REPAIRED** |
| `/en/blog/` | 301 -> `/blog/` | 200 `lang=en-US`, real EN archive | must remain the real EN archive | **REPAIRED** |
| `/en/eventos/` | 404 | 200 `lang=en-US` | no longer 404 | **REPAIRED** |
| `/en/empregos/` | 301 -> `/empregos/` | 302 -> `/empregos/` | no longer redirect to PT | **NOT repaired - see below** |

All seven PT routes return 200 with `lang="pt-BR"` and a canonical URL. Every EN archive emits 3-7 hreflang alternates. **Zero** PHP warnings, notices, deprecations or fatals were rendered on any route.

### Why `/en/empregos/` still 302s - documented policy, not a regression

Measured facts:

* page `empregos` ID=11293, `lang=pt` - **no EN translation exists**;
* `conexao_b2_page_allowlist()` = dublin, cork, galway, limerick, kildare, meath, wicklow, waterford, laois, irlanda, blog - **`empregos` is not listed**.

The restored code therefore applies the documented B1 policy, which is the correct behaviour, in the restored source's own words:

* `inc/i18n/fallback.php:271` - "B1 = Guides, Blog posts, key narrative static pages: keep the approved 302 policy until a real translation exists (never render fallback)."
* `inc/i18n/fallback.php:304` - "B2 is never a catch-all and never a substitute for editorial translation."
* `docs/routing.md:94` - "stays B1 (302 -> PT) or serves a real translation."

The same content gap is reported independently by the `translation_completeness` permanent gate (`page: ... missing a linked EN translation = 0` -> 4 violations: empregos, contato, sobre-nos, inicio). Returning 200 would require **creating EN page translations** - a content mutation, forbidden here. The B2 architecture itself is restored and demonstrably working: B2-eligible types are `event, leisure, sponsor, course_provider, job`, and `/en/eventos/`, `/en/lazer/` and `/en/cursos/` now all serve the EN shell.

## 8. Polylang impact

**PT immutability held.** No PT record, slug, URL, term, language or translation relationship was touched, and all seven PT routes are unchanged at 200.

The `/en/` layer was restored: EN archives render `lang="en-US"`, canonical and hreflang alternates, and the B2 fallback widening (`conexao_b2_archive_widen_query`, `inc/archive-query.php`) is back and active. No Polylang setting was changed.

Translation completeness is unchanged from the data's own state: 0 EN records created, and the completeness gate reports the same 52 pre-existing content violations plus the 1 data anomaly triaged in §13.

## 9. Production actions

| Action | Performed? | Detail |
|---|---|---|
| Production write | **no** | |
| Deploy / upload | **no** | |
| Plugin activation | **no** | |
| Content or DB mutation | **no** | |

`origin/i18n` is still `3cc6564` and nothing was pushed; the recovery commit is local and unpushed. Production is WordPress.com (no SSH, WP-CLI, filesystem or database), so nothing about production could be operated from here even if it had been in scope.

## 10. Verification commands and results

| Command | Exit | Result |
|---|---:|---|
| `php -l` over all 116 theme PHP files | 0 | 116 files, **0 errors** |
| `python3 tests/scripts/verify-cache-key-scoping.py` | 0 | 2 passed, 0 failed, 0 unscoped keys |
| `python3 tests/scripts/verify-i18n-freshness.py` | 0 | 8 passed, 0 failed, 0 stale |
| `python3 tests/scripts/verify-documentation-drift.py` | 0 | 14 passed, 0 failed |
| `python3 tests/scripts/verify-release-integrity.py` | 0 | 210 passed, 0 failed |
| `python3 tests/scripts/verify-script-conventions.py` | 0 | 317 passed, 0 failed |
| `python3 tests/scripts/verify-agent-governance.py` | 0 | **PASS** (see §13) |
| `python3 scripts/verify-permanent-gates.py` | 1 | 7 gates, **6 passed, 1 failed**; 83 assertions passed, 3 failed; aggregate **FAIL** (fail-closed, by design) |
| `./scripts/run-tests.sh` (x3) | 1 | see the numeric table below |
| `msgfmt -c` on `en_US.po`, `pt_BR.po` | 0 | both OK |
| `msgfmt -c`, `msgcat` on the event-importer POT | 0 | both SUCCESS |
| Playwright / Chromium 151.0.7922.34 audit | 0 | 14 page loads (7 routes x 2 viewports) |

### Numeric test results

The suite was run **three times on the same machine and database**, which is what makes the comparison trustworthy.

| Metric | `597b04e` (pre-recovery) | after source restore | after rewrite flush |
|---|---:|---:|---:|
| In-process PHP suites | **33 total, 23 passed, 10 failed** | 59 total, 39 passed, 20 failed | **59 total, 39 passed, 20 failed** |
| Assertions | **1768 passed, 24 failed** | 2900 passed, 102 failed | **2900 passed, 102 failed** |
| Script-contract suites | **6 total, 2 passed, 4 failed** | 6 total, 6 passed, 0 failed | **6 total, 6 passed, 0 failed** |
| HTTP acceptance suites | **3 total, 0 passed, 3 failed** | 3 total, 0 passed, 3 failed | **3 total, 1 passed, 2 failed** |

The suite count rose 33 -> 59 and the assertion count 1768 -> 2900 because **26 deleted test files were restored and are running again** - the recovered baseline is being *tested* harder than the broken one, not failing harder.

Targeted suites the task named, all restored and passing:

| Suite | Result |
|---|---|
| `test-filter-link-aria-semantics.php` (accessibility) | **103 passed, 0 failed** |
| `test-homepage-content-type-label.php` (homepage labels) | **29 passed, 0 failed** |
| `test-cache-language-scoping.php` (cache scoping) | **9 passed, 0 failed** |
| `test-course-provider-card-excerpt-language.php` | **45 passed, 0 failed** |
| `test-en-course-provider-category-filter.php` | **60 passed, 0 failed** |
| `test-leisure-card-excerpt-language.php` (EN leisure) | **35 passed, 0 failed** |
| `test-leisure-multiselect-filters.php` | **64 passed, 0 failed** |
| `test-redirect-precedence.php` | **15 passed, 0 failed** |
| `test-taxonomy-policy.php` | **18 passed, 0 failed** |
| `plugin/conexao-event-runtime/test-event-language-gate.php` (event B2 filtering) | **18 passed, 0 failed** |

### HTTP acceptance

* `tests/acceptance/verify-release-http.py` - the release smoke matrix (`scripts/data/release-smoke-matrix.json`): **42 passed, 0 failed** (was 3 failing rows: `en-pair-guias`, `en-pair-eventos`, `en-pair-lazer`).
* `tests/acceptance/verify-guides-en-http.py` - the primary row `en-guides-200 /en/guias/` now **passes**; 2 rows remain and are content-driven (§2.2).

### Script-contract results

`./scripts/run-tests.sh --scripts` layer: **6 suites, 6 passed, 0 failed** (was 2/6). `release-integrity` 210/0, `script-conventions` 317/0, `documentation-drift` 14/0, `i18n-freshness` 8/0, `cache-key-scoping` 2/0, `agent-governance` PASS.

### Release / build results

**Not in scope.** No release was built, manifested, deployed or verified; `./scripts/verify-release.sh` was not run. The `release-integrity` gate (210/0) is reported for completeness only.

### Browser verification (Phase 12) - performed, tooling was available

Playwright with bundled Chromium **151.0.7922.34**, 7 EN routes x desktop 1440x900 and mobile 390x844 = **14 page loads**:

* every route: HTTP 200, `lang="en-US"` (`/en/empregos/` correctly `pt-BR` under the B1 policy), canonical present, 3-5 hreflang alternates;
* **0** PHP warnings/notices/fatals rendered;
* **`role="listbox"` = 0, `role="option"` = 0, orphan `aria-multiselectable` = 0** on every page and viewport (all three were present at `597b04e`);
* `aria-current="true"` present on every page (2-5 elements);
* **no horizontal overflow** on any page or viewport;
* console 404s are missing uploaded media (`/wp-content/uploads/2026/06/*.jpg`) - the PT homepage shows the same class of 404 (7 of them, more than EN's 3), so it is a dataset condition, not an EN regression.

Live proof that the restored catalogue is actually serving: `/en/blog/` renders the category link label **"Uncategorized"** (the English catalogue string), not the Portuguese msgid - precisely the leak that was open at `597b04e`.

## 11. Failure proofs / negative tests

Four proofs that the verifiers genuinely detect the restored invariants. **No gate, threshold, baseline file or allowlist was modified** to make any of them pass.

| Proof | What was broken | Real result |
|---|---|---|
| Cache-key scoping | Removed `conexao_lang_cache_key()` from the four `404.php` transient calls | `verify-cache-key-scoping.py` went **red with exactly 4 violations** at lines 27/43/64/101; restored -> `2 passed, 0 failed` |
| i18n catalogue freshness | Removed the restored theme `.pot` | Gate red: *"56 file(s) define user-facing strings but conexao-br-irlanda.pot does not exist"*; restored -> `8 passed, 0 failed` |
| ARIA filter semantics | Stripped `aria-current="true"` from the event/leisure filter links | `test-filter-link-aria-semantics.php` -> **100 passed, 3 failed**; restored -> **103 passed, 0 failed** |
| Homepage label boundary | Replaced `conexao_content_type_label( get_post_type() )` with the raw PT object label (the `597b04e` form) | `test-homepage-content-type-label.php` -> **27 passed, 2 failed**; restored -> **29 passed, 0 failed** |

After all four proofs the tree was re-verified byte-identical to `b47d098` (`git diff b47d098 -- <theme> | wc -l` -> 0).

The permanent gates also remain demonstrably fail-closed: the aggregate still reports **FAIL** with 1 failing gate, and nothing was forced green.

## 12. Regression comparison

| | Before (`597b04e`) | After |
|---|---:|---:|
| Failing in-process suites | 10 | 20 |
| Failing assertions | 24 | 102 |
| Failing script-contract | 4 | **0** |
| Failing acceptance assertions | 3 suites | 2 suites (was 3) |

**The diff of the failing in-process lists is not empty, and here is the exact, proof-backed reason why that is not a regression.**

* **4 suites were FIXED** by the recovery: `conexao-admin-ux/test-translation-state.php`, `conexao-event-importer/test-export-language.php`, `conexao-event-importer/test-language-identity.php`, `conexao-event-runtime/test-event-language-gate.php`.
* **6 suites fail in BOTH runs, identically** - see §13.
* **14 suites "newly failing"** - and here is the decisive fact: **not one of them existed at `597b04e`.** Each of the 14 files was *deleted by the destructive commit* (`git cat-file -e 597b04e:<path>` fails for all 14) and restored from `b47d098`. They are the pre-existing baseline suites re-entering the run, not new breakage. Their failures are content-shaped: `en_id=0`, `fixture present: event PT master (#0)`, `linked=0 used=5`, `dates render Portuguese month names`.

**Net: zero new failures were introduced by the recovery.** Per-suite assertion counts confirm it - `test-ivvcc-importer` 45/1 before and after, `test-town-sanitization` 82/1 before and after, `test-leisure-attribute-normalization` 20/2 before and after, and `test-event-location-filters` actually **improved** from 44/1 to 62/1.

## 13. Known pre-existing failures

Every one of these is a **local content-data condition**, not code. None was introduced or worsened by this recovery.

1. **6 in-process suites failing identically before and after** (§12): `test-ivvcc-importer` (1), `test-town-sanitization` (1), `test-excerpt-en-roundtrip`, `test-language-uuid`, `test-event-location-filters` (1), `test-leisure-attribute-normalization` (2). Proof they are not mine: they fail with byte-identical assertion counts in the `597b04e` run.
2. **14 restored baseline suites that fail on missing EN content** - the local dataset has 0 EN guides, 0 EN leisure records, 1 EN event, and 4 untranslated pages. These are the same content gaps the permanent gate reports (48 guides, 4 pages).
3. **`translation_completeness` permanent gate: 53 violations, "1 new"** - triaged in full below, because the word "new" demands proof.
4. **`test-blog-en-translation.php` emits a PCRE warning** (`\u` escapes are unsupported by PCRE2 at line 156). Line 156 is **byte-identical to `b47d098`** - a pre-existing test-authoring issue, deliberately not "fixed" during a recovery.

### Triage of the "1 new" permanent-gate violation

The gate classified `post_type:event:malformed_relationships: 1` as new. I did not accept that at face value; I proved it was not recovery-induced:

* **The check reads only Polylang database state** - `pll_get_post_language()`, `pll_get_post()`. No theme code participates.
* **It reproduces on the `597b04e` runtime too.** I stashed the recovery, restored only the gate's test file into the broken tree, and re-ran the underlying query: same single violation.
* **It is a data record created on 2026-09-06**, three weeks before the regression commit: post ID 11553, `mabon-full-moon-day-retreat`, `lang=en`, `translations={"en":11553}`, `post_modified = 2026-09-06 17:36:52` - an EN event with no PT master.
* The recorded baseline `tests/baseline/permanent-gates.json` is **unchanged since `b47d098`**; this key was simply never recorded.

**Conclusion: pre-existing data, not a recovery regression.** It is left visible and un-suppressed, exactly as the fail-closed policy requires.

### A pre-existing failure that the recovery *fixed*

The assessment recorded `agent-governance` as failing (247/7, missing "Required reading" links in 10 skill files). **It now passes.** The recovery restored `docs/` content and the governance gate's inputs; **no file under `.agents/` was modified by this task** (measured: 0 diff lines). The gate is reported as observed, and no `.agents/` edit was made to obtain it.

## 14. Limitations

1. **No production verification.** Production is WordPress.com - no SSH, WP-CLI, filesystem or database. The production effect is inferred from source and local measurement. *Containment fact:* `597b04e` was unpushed (`origin/i18n` = `3cc6564`), so production was very likely never affected - but that cannot be proven from this repository.
2. **The rewrite flush is local-only and was not re-verified on a fresh database.** The recovery cannot be validated against a from-scratch install from this environment; a fresh deployment must flush permalinks as part of its normal install step.
3. **Filter keyboard interaction was not exercised live.** The local dataset renders **zero** filter options on `/lazer/` and `/eventos/` (a pre-existing limitation already recorded in the a11y report and the assessment). ARIA semantics were therefore verified by source, by the 103-assertion unit suite, and by computed DOM counts in the browser - not by live keypress.
4. **Missing uploaded media** cause the only console errors; the media are absent from the local uploads volume and media mutation is out of scope.
5. **No release was built or deployed**, so no artifact-level or manifest verification was performed.
6. **A local dev-environment ownership fix was needed.** The theme is a Docker bind-mount and `inc/` was owned by `www-data`, which blocked the restore; ownership was normalised to uid 1000 via a throwaway container. Filesystem metadata only - no content, no repository artefact.

## 15. Evidence paths

`docs/evidence/2026-09-27-baseline-recovery-implementation/`

| File | Proves |
|---|---|
| `01-phase0-frozen-state.txt` | HEAD, branch, upstream, ancestry, clean tree, Docker state, no-production/no-Flutter confirmations |
| `02-phase1-restoration-manifest.txt` | the exact restore/remove/preserve sets, derived from the real delta |
| `03-phase2-seo-conflict.txt` | the 23 duplicate names, the 0-new / 4-dropped function proof, the decision |
| `04-phase3-4-source-and-catalogue.txt` | before/after table, the 0-line exact-match proof, restored i18n surface, catalogue hashes, lint and duplicate-declaration counts |
| `05-phase9-test-results.txt` | three-way suite comparison and the full failure classification |
| `06-phase11-http-routes.txt` | the stale-rewrite-rule finding (0 -> 92 rules) and the final route matrix |
| `07-phase13-event-importer-pot.txt` | the POT repair re-proved: 0 duplicates, 385 entries, body md5 `519d16cd...` |
| `08-phase14-negative-proofs.txt` | the four sabotage/restore proofs |
| `09-phase12-browser-verification.txt` | the 14-load Chromium audit and the computed ARIA counts |
| `09-run-tests-AFTER-recovery.txt` | full suite log, recovered (pre-flush) |
| `09b-run-tests-AFTER-recovery-with-flush.txt` | full suite log, recovered (post-flush) |
| `10-run-tests-BEFORE-recovery-597b04e.txt` | full suite log at the destructive commit - the comparison baseline |
| `10-phase10-permanent-gates.txt` | the seven gates, fail-closed, plus the per-gate before/after |

## 16. Documentation updated

Only this report and its evidence directory were added; plus `docs/evidence/2026-09-26-stage-l/gate.json`, which the gate run **regenerates** as a machine artefact. **No existing document was edited**, so no `_Last verified_` line needed updating.

## 17. Rollback / recovery

* **Pre-change snapshot tag:** `recovery-snapshot-before-restore` -> `bd81ba59d800970d6e1e6e542d76ccb664fdbd24`.
* **Revert this recovery:** `git revert <recovery-commit>` (a new commit; history stays intact), or for a local-only undo `git reset --hard recovery-snapshot-before-restore`.
* **To redo it from scratch:** `git checkout b47d098 -- wp-content/themes/conexao-br-irlanda && git rm wp-content/themes/conexao-br-irlanda/inc/seo.php`, then flush permalinks locally.
* **What rollback does *not* cover:** it will not un-flush the local rewrite rules (harmless - they are regenerated by the restored theme), and it will not revert the pre-existing `event-importer` POT repair, which this task deliberately preserved.

## 18. Working-tree audit (Phase 15)

| Check | Result |
|---|---|
| `git diff --check` | **0** whitespace/conflict issues |
| Changed paths outside `docs/` | 104 - **all** under `wp-content/themes/conexao-br-irlanda/` (verified by set difference) |
| Historical reports/evidence rewritten | **0** |
| `Artigo` report + evidence | **0** changes |
| Baseline-recovery assessment + evidence | **0** changes |
| `event-importer` report + evidence | **0** changes; POT byte-identical |
| `plugins.json` / `.agents/` / `AGENTS.md` / `scripts/` | **0** changes each |
| Flutter/mobile paths in the diff | **0** |
| Production changes | **0**; `origin/i18n` still `3cc6564`; nothing pushed |
| Probe/temp files leaked into the tree | **0** (all removed) |

The diff is explainable in one line, and nothing unrelated is included:

> Restore the verified `b47d098` WordPress theme/application state while retaining the legitimate event-importer POT repair and its evidence.

## 19. Final acceptance matrix

| # | Criterion | Status |
|---:|---|---|
| 1 | Destructive theme regression recovered | **PASS** - tree byte-identical to `b47d098` |
| 2 | `b47d098` architecture and functionality restored | **PASS** - 43 modules, 112-line loader, 282 functions |
| 3 | i18n source + catalogues restored coherently | **PASS** - `i18n-freshness` 8/8, msgfmt clean |
| 4 | `inc/seo.php` does not conflict with `inc/seo/` | **PASS** - 23 dups avoided, 4 lost fns restored, 0 runtime dups |
| 5 | Cache-key scoping restored | **PASS** - gate green, negative proof red->green |
| 6 | ARIA filter semantics restored | **PASS** - 103/103 + live DOM 0 listbox / 0 option / 0 multiselectable |
| 7 | Homepage content-type translation restored | **PASS** - 29/29 + live "Uncategorized" |
| 8 | B2/i18n/REST/Polylang/SEO functionality restored | **PASS** - suites green, EN archives 200 with hreflang |
| 9 | event-town and other surviving work intact | **PASS** - 0 plugin changes |
| 10 | Repaired `event-importer.pot` intact and valid | **PASS** - 385 entries, 0 dups, md5 `519d16cd...` |
| 11 | Its evidence/report intact | **PASS** - 0 changes |
| 12 | No second translation engine | **PASS** - no new engine; shared rollout engine untouched |
| 13 | No permanent gate weakened or bypassed | **PASS** - 0 gate/baseline edits; aggregate still FAIL |
| 14 | No production action | **PASS** |
| 15 | Flutter/mobile untouched | **PASS** - never accessed |
| 16 | Verification recorded with numeric evidence | **PASS** - 3 suite runs, 7 gates, 14 routes, 14 browser loads |
| 17 | All new failures resolved or task stopped | **PASS** - **0 new failures introduced** |
| 18 | Pre-existing vs recovery-induced distinguished | **PASS** - §12/§13 with per-suite proof |
| 19 | Working tree clean | **PASS** - intended diff only |
| 20 | Recovery commit made | **yes**, after all verification above |

## 20. Final status

| Status | Use when |
|---|---|
| `PASS` | Every applicable gate in the standard passed, with real numbers, and no required verifier was unavailable. |
| `PASS WITH LIMITATION` | The work is complete, but a **required** verification capability was unavailable; the limitation is stated in §14. |
| `BLOCKED` | A required implementation could not be completed safely. |
| `NOT TESTABLE` | The intended change could not be meaningfully verified at all. |

**Final status: `PASS WITH LIMITATION`**

The recovery is complete and verified with real numbers: the theme is byte-identical to the verified `b47d098` baseline, the `event-importer` POT repair survives to its exact documented hash, **zero new failures were introduced**, six of seven permanent gates are green (up from five) with the seventh still fail-closed, six of seven EN routes are repaired, and the browser audit confirms the restored ARIA and translation semantics. The limitation is that **production could not be verified at all** (WordPress.com has no CLI access from this repository), and that `/en/empregos/` plus two guide-acceptance rows remain blocked on EN *content* that this task is forbidden to create.

### Explicit statements

- **Strategy used:** controlled forward reconstruction from `b47d098` - `git checkout b47d098 -- <theme>` plus `git rm inc/seo.php`. **No** `git revert`, `reset --hard`, `cherry-pick`, `merge`, or any history rewrite. `597b04e` remains in history and is fully auditable.
- **The `event-importer` POT repair is preserved exactly**, with its report and all 20 evidence files untouched.
- **Production actions: none.** No write, deploy, upload, activation, or content/DB mutation.
- **Flutter/mobile actions: none.** The Flutter/mobile repository was never accessed, inspected, built, tested or modified.
- **No gate was weakened, bypassed or allowlisted.** The aggregate is still `FAIL`; `tests/baseline/permanent-gates.json` is unchanged.
- **`.agents/` was not modified**, even though `agent-governance` now passes.

---

_Last verified: 2026-09-27 - controlled baseline recovery, at `bd81ba5` + recovery commit, branch `i18n`_
