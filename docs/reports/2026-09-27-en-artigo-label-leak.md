# Report — English `Artigo` label investigation on `/en/`

| | |
|---|---|
| **Task** | Investigate and fix the English `Artigo` label leak on `/en/` |
| **Date** | 2026-09-27 |
| **Agent** | Cline |
| **Branch** | `i18n` |
| **Baseline SHA** | `597b04e085afb9d2651cb7906b43e2c2b551c2f1` (clean tree) |
| **Final SHA** | `597b04e` — **unchanged; no code was modified** |
| **Working tree at finish** | clean, except the new evidence directory `docs/evidence/2026-09-27-en-artigo-label-leak/` |
| **Final status** | **`BLOCKED`** |

## 1. Scope completed

The investigation was completed in full. **No implementation was made**, and
the completion rule turned out to be the operative instruction: the premise
given in the task is factually incorrect, and the real cause is a site-wide
regression introduced by the baseline commit itself.

Phases 0–6 and 15 were executed with real measurements; Phases 7–14 were
correctly *not* executed as implementation phases because Phase 3/4
falsified their premises.

## 2. Scope NOT completed

- **No fix was implemented** — see §3 and
  `docs/evidence/2026-09-27-en-artigo-label-leak/03-why-no-implementation.md`.
- **No regression tests, acceptance rows, or HTTP-matrix changes were added.**
  Adding a test asserting "English label on `/en/`" would assert a behaviour
  the repository cannot currently produce for *any* string.
- **No browser (Playwright/Chromium) verification** — there is no page state
  at HEAD for which a passing result is meaningful, and the task's own
  instruction is to document rather than fabricate.

## 3. Files added / modified / deleted

**0 modified, 0 deleted. 7 files added**, all under
`docs/evidence/2026-09-27-en-artigo-label-leak/`:

| Path | Change | Note |
|---|---|---|
| `01-source-trace.md` | added | where `Artigo` comes from, proven |
| `02-wp-core-locale-and-catalogue.md` | added | core locale + core label + deleted-catalogue proof |
| `03-why-no-implementation.md` | added | the decision and its justification |
| `http-en-homepage-AT-HEAD-597b04e.html` | added | baseline `/en/` HTML |
| `http-pt-homepage-AT-HEAD-597b04e.html` | added | baseline `/` HTML |
| `permanent-gates-AT-HEAD-597b04e.txt` | added | gate run, preserved separately |
| `run-tests-AT-HEAD-597b04e.log` | added | full suite log at baseline |

**No `wp-content/` file was touched.** The one temporary PHP probe was
written to `/tmp` inside the container and deleted; the working tree was
verified clean afterwards.

## 4. Runtime impact

**None.** WordPress behaves exactly as it did at `597b04e`. No hook, filter,
template, helper, catalogue or option was changed.

## 5. Content / data impact

**None.** No records created, updated or deleted. No dry run was required
because nothing was written. Confirmed by `git status --short` showing only
the untracked evidence directory.

## 6. Polylang impact

**None.** Polylang configuration and policy are untouched. No language,
translation relationship, redirect, canonical or hreflang was modified. No
completeness gate number changed — the gates are already red at baseline
(§9) and are unchanged by this task.

## 7. Route / HTTP impact

No routes were added, removed or changed. Baseline HTTP measurements,
recorded as evidence only:

| Route | Status | Expected at this baseline |
|---|---|---|
| `/` | 200 | 200 ✓ |
| `/en/` | 200 | 200 ✓ — but renders the **blog**, not the front page |
| `/guias/` | 200 | 200 ✓ |
| `/en/guias/` | **404** | 200 ✗ |
| `/en/lazer/` | **301** | 200 ✗ |
| `/en/blog/` | **301** | 200 ✗ |

## 8. Production actions

| Action | Performed? | Detail |
|---|---|---|
| Production write | **no** | |
| Deploy / upload | **no** | |
| Plugin activation | **no** | |
| Content or DB mutation | **no** | |

All work was local (Docker on `localhost:8080`, read-only SQL `SELECT`s).

## 9. Verification commands and results

| Command | Exit | Result |
|---|---|---|
| `curl -s -o /dev/null -w '%{http_code}' localhost:8080/en/` | 0 | 200, 61 498 bytes |
| `curl … localhost:8080/` | 0 | 200, 92 366 bytes |
| `grep -c Artigo` on `/en/` HTML | 0 | **1** occurrence |
| `grep -rn 'Artigos e Notícias' wp-content/themes/` | 0 | `home.php:18` |
| `ls wp-content/themes/…/languages` | 2 | **No such file or directory** |
| core probe (container `/tmp`, deleted) | 0 | `get_locale()=pt_BR`; `post` label `Artigo`→`Post` under `en_US`; textdomain **NO** |
| `.mo` translation proof (deleted catalogue) | 0 | `Artigos e Notícias => Articles & News`; no bare `Artigo` entry |
| `./scripts/run-tests.sh` | 1 | **24 PASS / 17 FAIL** suites |
| `python3 scripts/verify-permanent-gates.py` | 1 | **0/7 gates pass**, 23 assertions passed, 6 failed |

### Numeric test results

In-process PHP: **41 suites total, 24 passed, 17 failed** at the baseline,
before any change by this task. Full log:
`docs/evidence/2026-09-27-en-artigo-label-leak/run-tests-AT-HEAD-597b04e.log`.

Representative failures: `test-translation-state.php` (9/23),
`test-event-query.php` (33/41), `test-export-language.php` (56/58),
`test-ivvcc-importer.php` (45/46), `test-language-identity.php` (27/29),
`test-event-language-gate.php` (17/18), `test-event-location-filters.php`
(44/45), `test-excerpt-en-roundtrip.php`, `test-language-uuid.php`.

### HTTP acceptance

**2 suites failed** (`tests/acceptance/verify-routing-http.py`,
`verify-release-http.py`) — all on the EN routes listed in §7, e.g.
`/en/guias/` 404 and `/en/lazer/` 301. Baseline failures, not caused by this
task.

### Static analysis

Not run. With zero code changed there is nothing new to analyse, and
`composer` dev dependencies are not required for a no-op task. **Stated as
not run rather than as passing.**

### Script-contract results

Included in the aggregate run above; the script-contract stage did not
produce an independent pass.

### Release / build results

`not in scope`.


## 10. Failure proofs / negative tests

The causal claim was proven by a **positive control**, which is stronger than
a negative test: the deleted `en_US.mo`, loaded from outside the repository,
translates `Artigos e Notícias → Articles & News` and
`Guia Prático → Practical Guide` in a real `MO` object
(`MO imported: YES`). The strings are untranslated in the running site only
because the file is absent from `languages/`. The regression therefore
reproduces deterministically and its cause is isolated to file deletion.

The gate runner was also shown to still block: it returned aggregate `FAIL`
with 7/7 gates red and was **not** weakened, allowlisted or edited.

## 11. Regression comparison

| | Before | After |
|---|---|---|
| Failing in-process suites | 17 | **17** |
| Failing acceptance suites | 2 | **2** |
| Permanent gates passing | 0 / 7 | **0 / 7** |

The diff of the failing lists is **empty** — this task introduced no
regression because it changed no code.

## 12. Known pre-existing failures

All 17 failing suites, both failing acceptance suites and all 7 failing gates
are **pre-existing at `597b04e`** and were present before this task started.
They are caused by the same commit, which deleted the theme `languages/`
catalogue, `inc/i18n/**`, `inc/seo/**`, `inc/content.php` and 26 theme test
files. They are out of scope here and are **not** attributed to this task.

## 13. Limitations

- **No browser verification.** Playwright/Chromium was not exercised.
  Justified because `/en/` is not the front page at this baseline and the
  whole EN layer is degraded, so there is no meaningful passing browser state
  to verify.
- **No static analysis** (§9).
- **No production verification** — none was authorised, and production has no
  CLI.
- The `597b04e` regression was **not** diagnosed to root-cause depth: that is
  a separate task. This report establishes only that it is the proximate cause
  of the reported symptom.
- The exact intent behind `597b04e` is unknown; it is committed and
  local-only, so whether the catalogue deletion was deliberate is a
  maintainer decision, not something to infer.

## 14. Evidence paths

`docs/evidence/2026-09-27-en-artigo-label-leak/`:

| File | Proves |
|---|---|
| `01-source-trace.md` | the string is `home.php:18`, a theme literal, not a core label; the textdomain path is missing; `597b04e` deleted the catalogue and the prior fix |
| `02-wp-core-locale-and-catalogue.md` | WP 7.1.2, `get_locale()=pt_BR`, core `Post`/`Artigo` both correct, and the deleted `.mo` contained `Articles & News` |
| `03-why-no-implementation.md` | why the premise is false and why hardcoding is the only "quick" fix — and therefore forbidden |
| `http-en-homepage-AT-HEAD-597b04e.html` | baseline `/en/` (contains the `Artigo` at line 299) |
| `http-pt-homepage-AT-HEAD-597b04e.html` | baseline `/` (PT front page renders correctly) |
| `run-tests-AT-HEAD-597b04e.log` | 24 PASS / 17 FAIL |
| `permanent-gates-AT-HEAD-597b04e.txt` | 0/7 gates pass |

**Historical evidence was not altered.** The gate runner overwrote the tracked
`docs/evidence/2026-09-26-stage-l/gate.json`; it was restored with
`git checkout --` and this run's output preserved under the new evidence
directory instead.

## 15. Documentation updated

None. No `wp-content/`, route, plugin, content-model or registry change
occurred, so no authoritative document needed correcting. Adding a note to
`docs/themes/conexao-br-irlanda.md` describing `conexao_content_type_label()`
would be **wrong** at this baseline: that helper no longer exists.

## 16. Rollback / recovery

`rm -rf docs/evidence/2026-09-27-en-artigo-label-leak/` restores the exact
baseline. There is nothing else to undo — no code, content, option or
configuration was modified. The pre-existing `597b04e` regression is
deliberately left in place.


## 17. Answers to the required questions

1. **Where does `Artigo` come from?** `home.php:18` —
   `__( 'Artigos e Notícias', 'conexao-br-irlanda' )`. A **theme literal**,
   the blog-archive section eyebrow. It is *not* a core post-type label. It
   renders untranslated because the theme's `languages/` directory (deleted
   by `597b04e`) holds no `.mo`, so `load_theme_textdomain()` loads nothing
   and the msgid is returned verbatim.
2. **What locale is WordPress using?** `pt_BR` at the site level
   (`get_locale()`), with Polylang driving per-request language.
3. **What locale is Polylang using?** `pt` by default; `en` on `/en/`
   (`pll_language=en` cookie observed).
4. **Does core already contain the English translation?** **Yes** — and it is
   not the relevant one. Core's `post` label is msgid `Post`, rendering
   `Artigo` under `pt_BR` and `Post` under `en_US`. Both correct.
5. **Why isn't it appearing?** Because the on-screen string never goes
   through core at all, and the theme catalogue that would have translated it
   was deleted. The project's gettext boundary is **intact** — the file is
   missing.
6. **Is there an existing helper that solves it?** The deleted
   `conexao_content_type_label()` was the right mechanism for *post-type
   chips* — but it is absent at HEAD, and the current leak is not a
   post-type chip, so it would not fix this anyway.
7. **What changed?** **Nothing.** No code change was made.
8. **Is the fix frontend-only?** N/A — no fix was implemented.
9. **Did PT stay byte-identical?** Yes — `/` was not modified. PT rendered
   correctly before and after (`200`, front page, `Guia Prático` chips).
10. **Did catalogue files change?** **No** — and restoring them is precisely
    the out-of-scope work; doing it here would mean absorbing `597b04e`.
11. **Did Polylang/content data change?** **No.**
12. **Tests passed/failed?** Baseline: 24 suites passed, 17 failed. Unchanged
    by this task. No focused tests were added, because the defect's true
    cause is a regression rather than a correctable boundary bug.
13. **Permanent-gate results?** **0 of 7 passed**, aggregate `FAIL`; 23
    assertions passed, 6 failed; 0 allowlisted. Gates left fail-closed and
    unweakened.
14. **Pre-existing failures remaining?** 17 in-process suites, 2 acceptance
    suites, 7 gates — all attributable to `597b04e`, none to this task.
15. **Environmental limitations?** No browser verification, no static
    analysis, no production verification — §13.

---

## Conclusion

**`BLOCKED`** — the required implementation cannot be completed safely. The
defect as described (a core post-type label bypassing translation) does not
exist; the actual fault is a site-wide EN translation outage plus routing
breakage introduced by the local-only baseline commit `597b04e`, which I am
explicitly instructed not to absorb or revert. The remedy — restoring the
theme `languages/` catalogue and the `inc/i18n/**` module structure — is a
larger change requiring its own plan and a maintainer decision.

## 18. Recommended next step

Restore the pre-`597b04e` theme state (`b47d098`) or forward-port the
catalogue and `inc/` modules into the flattened `functions.php`, then re-run
this task. That is a distinct change with its own plan; it is **not**
performed here.

_Last verified: 2026-09-27 — investigation only, no code change_
