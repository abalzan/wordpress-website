# Report — Final recovery closeout: translation finding, verification, baseline

| | |
|---|---|
| **Stage / task name** | Final recovery closeout (investigation + verification + baseline) |
| **Date** | 2026-09-27 |
| **Author / agent** | Cline |
| **Branch** | `i18n` |
| **Start SHA** | `cd800be1b76c84ffa07c61554b521cfefd1b8f17` (recovered baseline) |
| **Final SHA** | `cd800be1b76c84ffa07c61554b521cfefd1b8f17` (unchanged — see §3) |
| **Working tree at finish** | Clean. Only the intentional closeout evidence/report files are new. Zero tracked files modified. |
| **Final status** | **PASS WITH LIMITATION** |

## 1. Scope completed

This was the **final closeout only**. The broad restoration was already complete at
`cd800be`; this task did not reopen it. Exactly three things were done:

1. **Investigated the one new translation-completeness violation** (§4).
2. **Performed final full verification** — full runner, 7 permanent gates, HTTP
   matrix, browser, negative proofs, POT/catalogue/architecture integrity (§5–§10).
3. **Closed out the recovery and established the recovered baseline** (§13, §14).

Explicitly **not** done, by design: no feature, translation, taxonomy, routing,
performance or refactoring work; no restoration work; no gate changes.

## 2. Scope NOT completed

* **No implementation fix was made for the one new gate finding** — it was proven
  pre-existing, and the decision rule (§4.4 Case B) requires leaving it visible.
* **No EN content was created** to make any gate green, and **no content gap was
  papered over** (§7 limitations).
* **No production verification of any kind**, and no production write (§11).
* **The Flutter/mobile repository was never accessed** (§11).
* **PHPCS and PHPStan were not run successfully** — the toolchain is absent on this
  host (`composer: command not found`; PHPCS temp-report parse error). This is
  reported as an environment gap, not repaired, because the theme is byte-identical
  to the verified source and cannot have regressed (§6.4).

## 3. Files added / modified / deleted

| Path | Change | Note |
|---|---|---|
| `docs/reports/2026-09-27-recovery-closeout.md` | added | this report |
| `docs/evidence/2026-09-27-recovery-closeout/` | added | 13 evidence files |
| `docs/reports/README.md` | modified | registers the recovery report chain (assessment → implementation → closeout), which was previously unindexed |

**No source file, plugin, theme, catalogue or test was modified.** Verified:

```
git diff b47d098 -- wp-content/themes/   -> 0 lines
git diff cd800be -- wp-content/          -> 0 lines
git diff                                 -> 0 lines
```

Because the closeout is documentation-only, `cd800be` **remains** the recovered
baseline and HEAD. The final commit (if made) adds only this report and its evidence.

## 4. Translation completeness investigation

The gate reported `translation_completeness: FAIL` — 17/3, **53 violations, 52
pre-existing, 1 new**. The one "new" finding was investigated in full rather than
assumed. Full evidence:
`docs/evidence/2026-09-27-recovery-closeout/01-translation-completeness-provenance.txt`.

### 4.1 The exact violation

| Field | Value |
|---|---|
| Gate key | `post_type:event:malformed_relationships` (count 1) |
| Note | `en_without_pt:mabon-full-moon-day-retreat` |
| Post ID | `11553` — "Mabon & Full Moon Day Retreat" |
| Type / status | `event` / `publish` |
| Language | `en` (`pll_get_post_language()` = `en`) |
| PT relationship | **none** — `pll_get_post(11553,'pt')` = `0`; `translations = {"en":11553}` (self-referential) |
| Created | `2026-09-06 17:36:52` (post_modified identical) |
| Provenance meta | `_event_source = laois_tourism`, `_event_import_date = 2026-09-02`, `_event_url = laoistourism.ie/…` |

### 4.2 Reproduction on the recovered state

The gate itself reproduces it: `17 passed, 3 failed`, sample
`en_without_pt:mabon-full-moon-day-retreat`. Independently, a read-only probe of the
same predicate over the same data returns `["11553:mabon-full-moon-day-retreat"]`.

### 4.3 Reproduction against the destructive baseline `597b04e`

The gate **cannot run** at `597b04e` — that commit deleted the gate's test file and
the `conexao_b2_post_types()` B2 policy helper the gate requires as a prerequisite.
So the underlying predicate was evaluated instead, in a **disposable container** with
the `597b04e` theme mounted read-only against **the same database**, using
`git archive` (no history change, no worktree, no branch movement):

| Runtime | inc modules | B2 helper | events scanned | EN events | EN without PT master |
|---|---:|---|---:|---:|---|
| **A** — recovered `cd800be` | 43 | present | 1798 | 1 | `["11553:mabon-full-moon-day-retreat"]` |
| **B** — destructive `597b04e` | 8 | absent | 2225 | 3 | `["19489:…", "19238:…", "11553:mabon-full-moon-day-retreat"]` |

**The violation reproduces on `597b04e` — together with two more.** The recovery
*reduced* visible EN orphans from 3 to 1 (the recovered archive query filters past
events). The finding is therefore independent of the recovered theme source.

### 4.4 Decision: **Case B — proven pre-existing and unrelated**

Per the decision rule, it was **not** fixed. Supporting provenance:

* **Theme-independent.** The check reads only Polylang DB state. A probe recorded the
  `conexao_*` function table before and after evaluating the predicate: **unchanged**,
  proving no theme function participates.
* **Predates the regression by 21 days.** Created `2026-09-06`; `597b04e` is dated
  `2026-09-27 20:18`. It cannot be caused by a commit made three weeks later.
* **Attributable to the event importer, before the guard existed.** `_event_source =
  laois_tourism` proves the importer created it. The importer's language guard
  (`class-language-guard.php`) was added in **`8ac39b1`, 2026-09-21** — *fifteen days
  after* the record. At creation time nothing forced the import language.
* **The guard now self-protects.** `Conexao_Event_Importer_Language_Guard::is_import_target(11553)`
  returns **`false`**, so today's importer refuses to write this record. The defect
  cannot be recreated by the current code path; the record is legacy data.
* **The recovery touched no plugin.** `cd800be` changed 104 theme files and **zero**
  plugin files (`git diff 597b04e cd800be -- wp-content/plugins/` is empty).

**Why it prints as "new":** `tests/baseline/permanent-gates.json` records
`post_type:guide:malformed_relationships` but never
`post_type:event:malformed_relationships`. The key was simply never recorded when
Stage L captured the baseline; that file is unchanged since `b47d098`. The
classification is **reporting only** — per its own contract a baseline entry never
suppresses a failure, so the gate still fails and still exits non-zero.

**No code or data change was made for this finding. No allowlist or threshold was
added.** The record is *real imported content*, not a fixture — so the existing
`remove-en-orphan-fixtures.php` correctly refuses it, and fabricating a PT master
would be worse. Removing or translating it is a content decision for a maintainer,
outside a recovery closeout.

## 5. Tests — exact numeric results

Full runner: `./scripts/run-tests.sh` (evidence `04-full-runner.txt`).

| Metric | Closeout | Recovered baseline (`09b`) | Δ |
|---|---:|---:|---|
| In-process suites | 59 | 59 | 0 |
| — passed | 39 | 39 | 0 |
| — failed | 20 | 20 | 0 |
| Prerequisite failures | 2 | 2 | 0 |
| Assertions passed | 2900 | 2900 | 0 |
| Assertions failed | 102 | 102 | 0 |
| Script-contract | 6 / 6 | 6 / 6 | 0 |
| HTTP acceptance | 1 passed / 2 failed | 1 passed / 2 failed | 0 |

A **sorted diff of the failing-suite list against the recovered baseline is empty** —
byte-identical. No suite regressed, and none was fixed (correct: the closeout changed
no source and no data). All 20 failures are pre-existing and were traced in the
implementation report §13: 6 fail identically on `597b04e`, 14 fail on missing EN
content, plus the translation-completeness gate.

Script-contract detail: `verify-agent-governance` PASS, `verify-cache-key-scoping` 2/0,
`verify-documentation-drift` 14/0, `verify-i18n-freshness` 8/0, `verify-release-integrity`
210/0, `verify-script-conventions` 317/0.

PHP syntax: `php -l` over 462 files — **OK: no PHP parse errors**.

## 6. Permanent gates

Run individually (evidence `05-…txt`) and as an aggregate (`05b-…txt`).
**No gate configuration, threshold, baseline, allowlist or test was modified** —
`git status --short -- tests/ …/tests/` is empty.

| Gate | Result | Violations | New | Pre-existing |
|---|---:|---:|---:|---:|
| taxonomy policy | PASS 18/0 | 0 | 0 | 0 |
| translation completeness | **FAIL 17/3** | **53** | **1** | **52** |
| cache scoping runtime | PASS 9/0 | 0 | 0 | 0 |
| cache scoping static | PASS 2/0 | 0 | 0 | 0 |
| redirect precedence | PASS 15/0 | 0 | 0 | 0 |
| documentation drift | PASS 14/0 | 0 | 0 | 0 |
| i18n freshness | PASS 8/0 | 0 | 0 | 0 |
| **aggregate** | **FAIL (exit 1)** | **53** | **1** | **52** |

Aggregate: 7 gates, 6 passed, 1 failed, 0 blocked; 83 assertions passed, 3 failed.

The 53 violations decompose as: `event:malformed_relationships` 1 (investigated above),
`guide:missing_en` 48, `page:missing_en` 4, with 2054 records allowlisted **by the
pre-existing documented B2 policy** (`conexao_b2_post_types()` / `conexao_b2_page_allowlist()`).

> **No gate was weakened.** No threshold changed, no finding suppressed, no allowlist
> added or extended, and `tests/baseline/permanent-gates.json` is untouched. A known
> unrelated data limitation remains visible as a failing permanent gate; the recovery
> did not weaken the gate to manufacture a PASS. The single "new" finding was proven
> pre-existing and deliberately left visible — **it is not claimed to be fixed.**

### 6.4 PHP lint (reported, not repaired)

`./scripts/lint.sh` step 1 passed (462 files, no parse errors). Steps 2–3 could not
complete: PHPCS reported an invalid temp report and PHPStan reported
`composer: command not found`. These are **host toolchain gaps**, not source findings —
the theme is byte-identical to the verified restored source, so no code change could
have caused them. Recorded as an environment limitation.

## 7. HTTP acceptance

Evidence `06-http-acceptance-matrix.txt`. Local Docker only; **no production request**.

| Route | Expected | Actual | Lang | Canonical | hreflang | PHP errors | Redirects |
|---|---:|---:|---|---|---:|---:|---|
| `/en/` | 200 | 200 | en-US | self | 5 | 0 | 0 |
| `/en/cursos/` | 200 | 200 | en-US | self | 5 | 0 | 0 |
| `/en/guias/` | 200 | 200 | en-US | self | 5 | 0 | 0 |
| `/en/guias/page/2/` | 200 | **404** | en-US | — | 2 | 0 | 0 |
| `/en/lazer/` | 200 | 200 | en-US | self | 5 | 0 | 0 |
| `/en/blog/` | 200 | 200 | en-US | self | 7 | 0 | 0 |
| `/en/eventos/` | 200 | 200 | en-US | self | 7 | 0 | 0 |
| `/en/empregos/` | B1 redirect | **302 → PT** | — | PT target | 0 | 0 | 1 |
| `/` | 200 | 200 | pt-BR | self | 6 | 0 | 0 |
| `/cursos/` | 200 | 200 | pt-BR | self | 4 | 0 | 0 |
| `/guias/` | 200 | 200 | pt-BR | self | 4 | 0 | 0 |
| `/lazer/` | 200 | 200 | pt-BR | self | 4 | 0 | 0 |
| `/blog/` | 200 | 200 | pt-BR | self | 7 | 0 | 0 |
| `/eventos/` | 200 | 200 | pt-BR | self | 7 | 0 | 0 |
| `/empregos/` | 200 | 200 | pt-BR | self | 4 | 0 | 0 |

**Zero PHP errors on all 15 routes and the sitemap.** Every EN page serves
`<html lang="en-US">`, every PT page `pt-BR`. No unexpected redirects.

**B1 behaviour preserved** for `/en/empregos/`: `302 → http://localhost:8080/empregos/`
→ final 200, target canonical is the **PT** URL, and the EN URL is **absent from the
sitemap** (`/sitemap.xml`: 0 occurrences of `en/empregos`, 1 of the PT URL). This is
the established B1 policy and is **not** an implementation regression.
`/en/sitemap.xml` 404s by design — `inc/seo/sitemap.php` serves one sitemap and
disables core's to avoid competing sitemaps.

### Documented content limitations (not regressions)

* **`/en/guias/page/2/` → 404.** The local dataset has **0 EN guides** (48 PT, 0 EN),
  so there is no page 2. This is the documented cause of the one failing HTTP suite
  (`verify-guides-en-http.py`: page-2 404 + no discoverable EN guide link). No EN
  content was fabricated and no routing was changed to conceal it.
* **48 guide + 4 page completeness violations** — the same missing EN content.
* **20 failing in-process suites** — pre-existing; identical to the recovered baseline.

Performance note: `/en/eventos/` 16.6s and `/eventos/` 10.4s on a cold cache, every
other route < 0.13s. This is the known event-archive cost already investigated
(`docs/reports/2026-09-27-lazer-en-archive-perf.md`); with the theme byte-identical to
`b47d098`, no source change could have altered it. It also explains the
`verify-routing-http.py` **timeout**, which occurred identically in the recovered
baseline run.

## 8. Browser verification

Evidence `07-browser-verification.txt`. Playwright Chromium, **desktop 1440×900** and
**mobile 390×844** (is_mobile + has_touch), 11 routes × 2 viewports = **22 navigations**.

* Language shell correct on all 22; all routes HTTP 200.
* **No horizontal overflow** (scrollWidth ≤ clientWidth everywhere).
* **`role=listbox` 0, `role=option` 0, `aria-multiselectable` 0** on all 22.
* Active filters use **`aria-current`** (5–9 per page, every route/viewport).
* Header navigation present everywhere; **0 page errors** — no recovery-induced
  console or runtime failure.
* Expected EN routes render; no new broken navigation.

**Console output:** 29 identical 404 resource messages, **all** `wp-content/uploads/*`
(the uploads volume is absent locally). **Proven not a code regression** — the *same*
files 404 on PT and EN: `/lazer/` 404s on the same 10 uploads in both languages. EN
merely renders fewer images. Correctly classified as a local uploaded-media limitation;
media mutation is out of scope.

## 9. Negative proofs (fail-closed gates actually bite)

Evidence `08-negative-proofs.txt`. Each: establish PASS → break one thing → show FAIL →
restore → show PASS. Every restoration was verified clean **before** the next proof.

| # | Invariant | Recovered | Sabotaged | Restored |
|---|---|---|---|---|
| 1 | cache scoping (`404.php` → `597b04e` unscoped keys) | 0 unscoped, 2/0 PASS | **4 unscoped, 2/4 FAIL** | 0 unscoped, 2/0 PASS |
| 2 | i18n freshness (remove theme POT) | 8/0 PASS | **8/1 FAIL** | 8/0 PASS |
| 3 | accessibility (re-add `role="option"` / `aria-multiselectable`) | 103/0 PASS | **FAIL** (4 assertions) | 103/0 PASS |
| 4 | homepage content-type label (drop `__()`) | 29/0 PASS | **FAIL** (3 assertions) | 29/0 PASS |

**No sabotage remains**: `git status --short` shows only the closeout evidence
directory; theme diffs against both baselines are 0.

## 10. Event-importer POT, catalogue and architecture integrity

**POT** (evidence `09-…txt`) — repaired state preserved, **not regenerated**:
sha256 `6221c0ab…` identical worktree vs HEAD; **385** entries; **0** duplicate msgids;
481 source refs; 70646 bytes; 1573 lines; **body md5 `519d16cd1e4d62be926e8dc518d9f587`**
(md5 with the `POT-Creation-Date` line removed); `msgfmt --check` exit 0; `msgcat`
exit 0. `git diff b47d098` on the plugin shows the repaired `.pot` as the **only**
difference — **no `.po`/`.mo` changed**. Report and 20 evidence files intact.

**Theme catalogues** (evidence `10-…txt`) — `.pot`, `en_US.po`, `en_US.mo`, `pt_BR.po`,
`pt_BR.mo` all **IDENTICAL** to the recovered baseline. `git diff b47d098 -- theme` = **0**
and `git diff cd800be -- theme` = **0**. Freshness gate 8/0, 0 stale. **No catalogue
regeneration was performed** (none was needed — no code changed).

**Architecture** (evidence `11-…txt`) — **43 `inc/` modules**; **no consolidated
`inc/seo.php`**; modular `inc/seo/` with all 10 modules; `functions.php` is a
**112-line loader** with 43 `require_once` (vs the 3937-line `597b04e` monolith);
**0 duplicate `conexao_*` declarations** across 282 unique functions; loader order
preserved. All recovered features present: homepage content-type helper
(`inc/content.php:87`, 2 call sites), cache scoping (`inc/i18n/urls.php:29`, 4 scoped
keys in `404.php`), accessibility fixes (0 obsolete roles in production files; the only
remaining hits are negative assertions inside tests/).

## 11. Production and Flutter

> **No production verification or production write was performed.** No deploy, no
> publish, no production WordPress.com write, no production SSH/WP-CLI, no production DB
> or filesystem change. All verification ran against the **local Docker stack** at
> `http://localhost:8080`. The only writes were to disposable probe scripts and the
> closeout's own evidence/report files.

> **The Flutter/mobile repository was not accessed.** Not inspected, built, tested,
> modified or deployed. It is out of scope for this repository by `AGENTS.md`.

## 12. Limitations (evidence-supported only)

1. **`translation_completeness` permanent gate FAILS** — 53 violations (52 pre-existing,
   1 proven pre-existing importer record). Left visible and un-suppressed. *Cause: local
   data, not source.*
2. **48 EN guides and 4 EN pages missing** — 0 EN guide records exist locally; hence
   `/en/guias/page/2/` 404s and `verify-guides-en-http.py` fails. *Cause: missing local
   EN content, not an implementation regression.* Not fabricated.
3. **`/en/empregos/` 302-redirects to PT (B1)** — correct, documented policy, not a
   defect. Preserved as established.
4. **20 failing in-process suites** — all pre-existing and byte-identical to the
   recovered baseline (6 also fail on `597b04e`; 14 need missing EN content). *Cause: local
   data / environment.*
5. **`verify-routing-http.py` times out** — identical to the recovered baseline; caused by
   the known event-archive cost (10–17s on a cold cache), not by source. *Cause: local
   performance, not correctness.*
6. **Uploaded media 404 in the browser** — the uploads volume is absent locally; identical
   on PT and EN. *Cause: local environment, not code.*
7. **PHPCS and PHPStan could not run** — `composer` absent and a PHPCS temp-report parse
   error on this host. *Cause: local toolchain availability, not source.*
8. **No production verification** — production is WordPress.com (no SSH/CLI/DB/FS). The
   production effect is inferred from source and local measurement only. *Containment
   fact carried forward:* `597b04e` was unpushed (`origin/i18n` = `3cc6564`), so production
   was very likely never affected — but that cannot be proven from this repository.
9. **Event-archive cold-cache latency** — 10–17s for `/eventos/` routes; previously
   investigated, unchanged by this closeout.

## 13. Rollback

Rollback tag: **`recovery-snapshot-before-restore`** → `bd81ba5` (preserved, not moved).

The recovery was performed **forward-only**: no reset, revert, cherry-pick, merge, rebase,
history rewrite, force-push, or blanket rollback, and `597b04e` remains in the log and
fully auditable. This closeout added no source change, so the rollback position is
unchanged from `cd800be`.

## 14. Final status

# PASS WITH LIMITATION

**Every recovery-specific verification passed.** The recovered architecture is intact
(43 modules, loader, no duplicates, all recovered features), the full runner is
**byte-identical to the recovered baseline** (59/39/20, 2900/102, 6/6, 1p/2f), 6 of 7
permanent gates pass, all 15 HTTP routes are healthy with zero PHP errors, browser
verification is clean on desktop and mobile, and all four negative proofs demonstrate
the gates are genuinely fail-closed. POT, catalogue and source integrity are exact
(`git diff b47d098 -- theme` = 0). The working tree is clean and no gate was weakened.

The status is **not** plain PASS, for two honest reasons:

1. The **`translation_completeness` gate still fails**. Its one "new" finding was proven
   pre-existing and unrelated to the recovery (§4) and was deliberately left visible
   rather than fixed or allowlisted; the remaining 52 violations are documented local
   content debt. A known unrelated data limitation stays a failing permanent gate.
2. **Documented content and environment limitations persist** (§12) — most importantly
   0 EN guides locally, so `/en/guias/page/2/` legitimately 404s and the EN guide
   discoverability check legitimately fails. No content was invented and no routing was
   altered to conceal this.

`PASS` is not claimed, because a required gate is red for a documented data reason and
several verifiers (PHPCS/PHPStan) could not run in this environment.

## 15. Evidence paths

`docs/evidence/2026-09-27-recovery-closeout/`

| File | Proves |
|---|---|
| `00-frozen-starting-state.txt` | HEAD `cd800be` verified, clean tree, tag present, Docker up, scope/constraint confirmations |
| `01-translation-completeness-provenance.txt` | The one new finding: object, reproduction on `cd800be` and on `597b04e`, provenance, Case B conclusion |
| `04-full-runner.txt` | Full runner totals vs the recovered baseline; empty failing-suite diff |
| `05-permanent-gates-individual.txt` | All 7 gates run individually with real output; gates unmodified |
| `05b-permanent-gates-aggregate.txt` | Aggregate gate run: 7 gates, 6 pass / 1 fail, exit 1 |
| `06-http-acceptance-matrix.txt` | Route-by-route HTTP results, B1 proof, sitemap proof, documented limitations |
| `07-browser-verification.txt` | 22 desktop/mobile navigations; a11y, overflow, language, error results; media-404 symmetry |
| `08-negative-proofs.txt` | 4 fail-closed proofs, each fail → restore → pass, clean tree afterwards |
| `09-event-importer-pot-integrity.txt` | 385 entries, 0 duplicates, msgfmt/msgcat, body md5, no `.po`/`.mo` change |
| `10-catalogue-integrity.txt` | 5 theme catalogues identical; both theme diffs zero; freshness 8/0 |
| `11-source-architecture-integrity.txt` | 43 modules, no `inc/seo.php`, loader, 0 duplicates, recovered features present |
| `12-php-lint.txt` | 462 files no parse errors; PHPCS/PHPStan environment gaps; duplicate-declaration check |
| `13-working-tree-and-history.txt` | Clean tree, zero tracked changes, history integrity, tag preserved, no junk |
