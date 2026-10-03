# Report — i18n Catalogue Freshness Violation (i18n_freshness)

| | |
|---|---|
| **Stage / task name** | Resolve the `i18n_freshness` permanent-gate violation (`conexao-br-irlanda.pot` older than `front-page.php`) |
| **Date** | 2026-09-27 |
| **Author / agent** | Cline (cline@example.invalid) |
| **Branch** | `i18n` |
| **Start SHA** | `8f07f3027734b99546931295173369dbdb2953dc` |
| **Final SHA** | `88bdf22cde7e171afaf9f2b898569238de12b649` |
| **Working tree at finish** | dirty only with this task's untracked evidence/report, plus the **pre-existing** untracked artefacts of the earlier lazer task (`docs/evidence/2026-09-27-lazer-en-archive-perf/`, `docs/reports/2026-09-27-lazer-en-archive-investigation.md`), which were left untouched |

## 1. Scope completed

The violation was investigated to its root cause, classified, and resolved
through the repository's own catalogue workflow. The catalogue was **genuinely
stale** (not a timestamp artefact), so it was regenerated — but the documented
generator turned out to be silently broken and had to be repaired first.

- Reproduced the violation and traced it with Git history, not filesystem mtime.
- Compared the real extractable source strings against the committed `.pot`.
- Evaluated the preceding `Guia Prático` fix explicitly.
- Repaired three defects in `scripts/i18n-make-pot.sh` (the canonical workflow).
- Regenerated **only** the stale catalogue, through that workflow.
- Proved no translation, no PT content and no runtime output changed.
- `i18n_freshness` is now **PASS (8/8, 0 stale)** with the gate unweakened.

## 2. Scope NOT completed

- **The other corrupted catalogue was deliberately left alone.**
  `conexao-event-importer.pot` carries 3 duplicate-msgid errors from the same
  broken run (`88e8ec0`). It is **fresh** per the gate, so it is out of this
  task's scope. The repair is proven to work there (dup 3 → 0, verified in a
  temporary probe that was then reverted) and is recommended as follow-up work.
- **No production action of any kind** (see §8).
- **Flutter/mobile was not accessed**, inspected, built or modified.
- **No `.po`/`.mo` update.** Not required: the 16 added msgids are 5
  already-translated English labels, 7 REST-contract developer strings and the
  English B2 notice. Adding a translated string is outside a refresh's remit
  and would mean fabricating a translation for an English msgid.

## 3. Files added / modified / deleted

**0 added, 3 modified, 0 deleted** (plus this report and the evidence directory).

| Path | Change | Note |
|---|---|---|
| `wp-content/themes/conexao-br-irlanda/languages/conexao-br-irlanda.pot` | regenerated | **The only `wp-content/` change.** Generated artefact, never loaded at runtime. 403 → 417 msgids. |
| `scripts/i18n-make-pot.sh` | fixed | 3 defects (§10). No behaviour change to any gate. |
| `scripts/README.md` | updated | Script inventory row: scoping semantics + last-verified date. |

No PHP source, no template, no `plugins.json`, no Polylang configuration, no
baseline file and no test was modified.

## 4. Runtime impact

**None — behaviour-neutral, and proven so rather than asserted.** WordPress
loads `languages/<locale>.mo`; it never loads a `.pot`. The five pages were

## 5. Content / data impact

**None.** No post, page, term, meta, user or option was created, updated or
deleted; no database write was performed. Confirmed by `git status` (3 modified
files, all non-content) and by the byte-identical PT homepage.

## 6. Polylang impact

**None.** No Polylang configuration, language, translation relationship or
term was touched. The PT-unchanged assertion is demonstrated by `/` being
byte-identical before and after. `translation_completeness` is unchanged at
**35 violations (34 pre-existing, 1 new)** — identical at the previous commit
(§12), so this task introduced none of it.

## 7. Route / HTTP impact

**None.** No route, redirect, canonical, hreflang or sitemap behaviour changed.
`redirect_precedence` gate: **15 passed, 0 failed**.

## 8. Production actions

| Action | Performed? | Detail |
|---|---|---|
| Production write | **no** | Local Docker only (`localhost:8080`). |
| Deploy / upload | **no** | No build, no release, no upload. |
| Plugin activation | **no** | |
| Content or DB mutation | **no** | Read-only HTTP requests and test suites only. |

## 9. Verification commands and results

| Command | Exit | Result |
|---|---|---|
| `python3 tests/scripts/verify-i18n-freshness.py` (before) | 1 | 8 passed, 1 failed; 1 stale, **1 pre-existing, 0 new** |
| `./scripts/i18n-check.sh` (before) | 1 | same violation, 93649 s lag |
| `./scripts/i18n-make-pot.sh --dry-run conexao-br-irlanda` | 0 | planned 1 write, 0 failures |
| `./scripts/i18n-make-pot.sh conexao-br-irlanda` (after fix) | 0 | 417 msgids, 0 duplicates, 0 absolute paths |
| `msgfmt --check` on the new `.pot` | 0 | 0 duplicate/format errors |
| generator run twice, compared md5 | — | **byte-identical** (`ec6b708e…`) |
| `python3 tests/scripts/verify-i18n-freshness.py` (after) | **0** | **8 passed, 0 failed, 0 stale** |
| `python3 scripts/verify-permanent-gates.py` | 1 | 7 gates: 6 PASS, 1 FAIL (`translation_completeness`, pre-existing) |
| `./scripts/run-tests.sh` | 1 | see §11 |
| `php -l` (461 files) via `./scripts/lint.sh` | 0 | no parse errors |
| `vendor/bin/phpstan analyse` | 1 | 2 errors, both pre-existing in `fallback.php` |

### Numeric test results

In-process PHP: **59 suites total, 35 passed, 24 failed**. Script-contract:
**6 suites, 5 passed, 1 failed**. HTTP acceptance: **3 suites, 1 passed,
2 failed**. Full transcript: `evidence/18-…`, `evidence/21-…`.

### HTTP acceptance

3 suites, run by the standard runner against `http://localhost:8080`.
1 passed (`verify-release-http.py`, 29 passed / 0 failed), 2 failed —
`verify-guides-en-http.py` and `verify-routing-http.py`, **both pre-existing**
(present in the baseline, §11). Manual checks: `/` 200, `/en/` 200,
`/en/cursos/` 200, `/en/lazer/` 200, `/en/eventos/` 200.

### Static analysis

- **PHP syntax:** `php -l` over 461 files — **OK, no parse errors**.
- **PHPCS: could not run.** PHP_CodeSniffer requires the `tokenizer`,
  `xmlwriter` and `SimpleXML` extensions; only `tokenizer` is loaded
  (`dom`, `xml`, `SimpleXML`, `xmlwriter` are all absent). `composer` is also
  not installed on this host, so `scripts/lint.sh` step 3 could not run either.
  **Pre-existing environment limitation**; no PHP source was changed here.
- **PHPStan level 5:** 2 errors, both in
  `inc/i18n/fallback.php` ("Else branch is unreachable because ternary
  condition is always true"). **Verified pre-existing**: the identical 2 errors
  are reported with this task's changes stashed. `fallback.php` is not a file
  this task modified.

### Script-contract results

`./scripts/run-tests.sh --scripts`: **6 suites, 5 passed, 1 failed** — the one
failure being `verify-i18n-freshness.py`, which is correct while the regenerated
catalogue is uncommitted (the gate's signal is git commit time, §10).

### Release / build results

`not in scope` — no release or build was part of this task.

## 10. Failure proofs / negative tests

A gate never shown to fail is not a gate. Three proofs:

| Proof | Result |
|---|---|
| **The gate still fails on a stale catalogue.** Reverting the `.pot` to `8f07f30` with the fix in place reproduces the violation (exit 1, 93649 s lag). The gate was not weakened, allowlisted or re-baselined to obtain green. | Gate fails as designed |
| **The original broken generator is caught.** `printf '<list>' \| xgettext --files-from=- --output=t.pot` → 7 msgids, file created. The same command with the generator's `</dev/null` → **0 msgids, no file created, exit 0**. A silent, zero-extraction "success". | Root cause proven |
| **The broken generator produced a corrupt catalogue.** Running the *unfixed* script produced a `.pot` whose first 1543 lines were byte-identical to the old file, with 5 duplicate msgid blocks appended; `msgcat` rejects it with "**found 5 fatal errors**". | Corruption reproduced |

### The three generator defects fixed

1. **`</dev/null` nulled the file list (root cause).** The script piped the
   source list to `xgettext --files-from=-` and then closed stdin on the same
   command line. `xgettext` read an **empty** list, extracted nothing, never
   created `--output`, and **still exited 0** — so the old catalogue survived
   untouched and `add_header_strings()` appended a *second* copy of the five
   `style.css` header blocks. This is why the debt never stopped recurring: the
   documented remedy could not work.
2. **`<component-slug>` narrowed the scan, not just the output.** The scope
   argument was also applied to the union of source roots, so a scoped run
   silently dropped strings that *other* components tag with the selected
   domain — the 12 job-type labels in
   `conexao-data-model/includes/class-agency.php`. The slug now scopes only
   which catalogue is **written**; the scan is always the full union.
3. **Absolute local paths leaked into a tracked artefact.** `xgettext` records
   the filename it is given and the roots are absolute, so all 601 references
   would have carried `/home/andrei/IdeaProjects/wordpress-website/…`. Added
   `make_references_relative()`, a pure reference rewrite that never touches a
   `msgid` or `msgstr`, so no translation can be altered or orphaned — and which
   makes regeneration **byte-reproducible** (verified: two runs, same md5).


## 11. Regression comparison

Baseline: `docs/evidence/2026-09-27-a11y-filter-option-role/baseline-run-tests.txt`
— the pristine-HEAD run committed by the **previous** task, same environment.

| | Before | After |
|---|---|---|
| In-process suites | 58 total, 34 passed, 24 failed | 59 total, **35 passed**, 24 failed |
| Failing script-contract | 1 | 1 |
| Failing acceptance suites | 2 | 2 |

**The diff of the failing lists is empty: 0 new failures, 0 fixed.** The
`+1` suite is `test-filter-link-aria-semantics.php`, added at `8f07f30` by the
previous task; it passes. An earlier run of this task appeared to show one new
failure (`test-event-query.php`); that run had the `.pot` stashed mid-flight, and
the suite passes deterministically in isolation and in the clean re-run
(**41 passed, 0 failed**).

## 12. Known pre-existing failures

All 24 in-process, 1 script-contract and 2 acceptance failures pre-date this
task and are **proven** pre-existing by the committed baseline (§11). Most are
local-database data conditions. In particular:

- **`translation_completeness` (35 violations: 34 pre-existing, 1 new).**
  Re-run at the **previous commit** `8f07f30` with this task's changes reverted,
  it reports the **identical** `15 passed, 5 failed, 35 violations (34
  pre-existing, 1 new)`, with the same samples (`guia-de-teste-local`,
  `local-test-guide`, `hello-world`, `local-en-post`). These are local test
  fixtures, not a catalogue effect. Not fixed here: fixing them is a content
  task, not an i18n-catalogue task.
- **`conexao-event-importer.pot` has 3 duplicate-msgid errors** from the same
  broken generator run (`88e8ec0`). It is *fresh*, so the gate does not flag it.
  Confirmed repairable by the fixed generator (3 → 0 duplicates) in a reverted
  probe. **Recommended follow-up.**

## 13. Limitations

- **PHPCS could not run** (missing `xmlwriter`/`SimpleXML`/`dom` PHP
  extensions) and **`composer` is not installed**, so `scripts/lint.sh` could
  not complete. `vendor/bin/phpstan` was invoked directly instead. CI is
  authoritative for PHPCS. This is pre-existing and unrelated: no PHP source was
  modified by this task.
- **No browser tooling was used.** The previous task drove Playwright; this
  environment has no configured browser runner, so verification was HTTP-level:
  status codes plus **byte-for-byte** comparison of five pages before and after
  the change, which is a stronger regression signal for a catalogue-only change
  than a screenshot.
- **The gate's signal is git commit time**, so it only reflects the regenerated
  catalogue once committed. This is the gate's documented, deliberate design
  (mtime is unusable in CI), not a workaround; the work is committed at
  `88bdf22` and the gate is green there.
- Not verified here: CI on a fresh clone, and production (out of scope).

## 14. Evidence paths

`docs/evidence/2026-09-27-i18n-catalogue-freshness/`

| File | Proves |
|---|---|
| `00-baseline-repository-state.txt` | HEAD, branch, upstream, dirty tree, PHP/gettext/python/composer |
| `01-baseline-i18n-freshness-fail.txt` | The violation reproduced (exit 1) |

## 15. Documentation updated

- `scripts/README.md` — the `i18n-make-pot.sh` row now states that the slug
  scopes which catalogue is *written*, never which files are *scanned*, and
  that references are written repository-relative; last-verified bumped to
  2026-09-27. `documentation_drift` gate: **14 passed, 0 failed**.
- This report. No doc at the repository root was added.

## 16. Rollback / recovery

```bash
git revert 88bdf22cde7e171afaf9f2b898569238de12b649
```

This restores the previous catalogue and the broken generator, and returns
`i18n_freshness` to its recorded pre-existing failure. No data rollback is
needed: no content, database or configuration was changed, and no `.po`/`.mo`
was modified. The evidence directory can simply be deleted.

## 17. Final status

**Final status: PASS WITH LIMITATION**

The violation was genuinely stale, was resolved through the repository's own
workflow, and `i18n_freshness` is now green with the gate unweakened and no
translation touched — but PHPCS could not run in this environment (missing PHP
extensions, no `composer`), so the full static-quality gate was not satisfied
here and CI remains authoritative for it.

## 17b. Answers to the specific questions asked

1. **Why was the violation reported?** `conexao-br-irlanda.pot` was last
   committed at `1790431901`; the newest theme source that defines strings,
   `front-page.php`, at `1790525550` — 93649 s later.
2. **Is the catalogue semantically stale?** **Yes.** 16 real strings declared
   with the theme domain (`inc/i18n/fallback.php`, `inc/rest-language.php`) were
   absent from the `.pot`. It was not a timestamp-only artefact.
3. **What changed `front-page.php`?** `8f07f30` replaced
   `$content_type->labels->singular_name` with
   `conexao_content_type_label( get_post_type() )` in two places.
4. **Did that fix introduce a new gettext string?** **No.** The helper does
   `__( $object->labels->singular_name, … )` — a **dynamic** msgid that
   `xgettext` cannot extract as a literal, so the set of extractable strings is
   unchanged. It only moved the *timestamp*.
5. **Does the POT contain the relevant strings?** It did **not** contain the 16
   the gate had been reporting since they were introduced on 2026-09-22 —
   because the generator had never successfully extracted anything.
6. **Was regeneration required?** **Yes**, and so was repairing the generator:
   the documented remedy silently extracted zero files.
7. **What changed?** **+16 msgids, −2.** The 2 removed
   (`Empregos — Página de Difusão`, `Landing Page (SEO)`) are `Template Name:`
   docblock headers — not gettext calls, never rendered through a text domain.
   403 → 417 msgids; 0 duplicates; 0 absolute paths; byte-reproducible.
8. **Did any existing translation change?** **No.** `en_US.po/.mo` and
   `pt_BR.po/.mo` are byte-identical, md5-verified before and after, and both
   remain in sync with their compiled `.mo`. `Guia Prático → Practical Guide`
   is intact in both the `.po` and the `.mo`.
9. **Did PT remain unchanged?** **Yes** — `/` is byte-identical.
10. **Did the freshness gate become green?** **Yes: `i18n_freshness: PASS`,
    8 passed, 0 failed, 0 stale, 0 pre-existing, 0 new.**
11. **Other gate failures?** `translation_completeness` — 35 violations
    (34 pre-existing, 1 new), **proven identical at the previous commit**
    (§12). Aggregate 6/7 gates PASS.
12. **Tooling limitations?** PHPCS and `composer` unavailable (missing PHP
    extensions) — see §13.

_Last verified: 2026-09-27 by Cline — i18n Catalogue Freshness_

| `02-git-history-front-page-analysis.txt` | What changed `front-page.php`, and the source ts census |
| `03-guia-pratico-label-fix-evaluation.txt` | The `Guia Prático` fix: dynamic msgid, no new string, already translated |
| `04-source-vs-catalogue-msgid-comparison.txt` | The 16 missing vs 7 header-only msgids |
| `05-pre-change-catalogue-state.txt` | Pre-change md5s, counts, po/mo sync |
| `06/07` regeneration dry-run + apply | The canonical workflow, before and after the fix |
| `08-catalogue-diff.txt` | What the *broken* generator did (5 duplicate blocks) |
| `09-generator-defect-dev-null-breaks-files-from.txt` | Root cause: the empty-file-list experiment |
| `10-generator-defect-scope.txt` | Defect scope + corruption spread to plugin catalogues |
| `11-catalogue-msgid-diff.txt` | **The semantic result: +16 / −2** |
| `12/23` gate before commit / after commit | FAIL → **PASS (8/8, 0 stale)** |
| `13-pt-en-homepage-labels.txt` | `Practical Guide` on `/en/`, `Guia Prático` on `/` |
| `14-pt-immutability-and-scope.txt` | Only the POT changed; `.po`/`.mo` untouched |
| `15-http-before-after-byte-comparison.txt` | Five pages byte-identical |
| `18/21` full suite transcripts | With changes |
| `19-test-regression-comparison.txt` | 0 new / 0 fixed vs the committed baseline |
| `20-lint-and-static-analysis.txt` | php -l OK; PHPCS blocked; PHPStan 2 pre-existing |
| `24-permanent-gates.txt`, `gate.json` | This run's permanent-gate result (history restored) |
| `25-translation-completeness-preexisting.txt` | The 35 violations are identical at the previous commit |

**Historical gate evidence was not falsified.** `verify-permanent-gates.py`
writes to a fixed path, `docs/evidence/2026-09-26-stage-l/gate.json`, which is a
tracked historical artefact. This run's output was first copied to
`evidence/…/gate.json` and the historical file was then restored with
`git checkout`, so it is byte-identical to its committed state.

fetched with the old and the new catalogue and compared byte-for-byte:

| Page | Bytes | Result |
|---|---|---|
| `/` | 70511 | **IDENTICAL** |
| `/en/` | 69112 | **IDENTICAL** |
| `/en/cursos/` | 43007 | **IDENTICAL** |
| `/en/lazer/` | 43574 | **IDENTICAL** |
| `/en/eventos/` | 43191 | **IDENTICAL** |
