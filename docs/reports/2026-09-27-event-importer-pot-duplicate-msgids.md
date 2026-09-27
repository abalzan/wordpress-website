# Report — Repair duplicate msgids in `conexao-event-importer.pot`

| | |
|---|---|
| **Stage / task name** | `event_importer_pot_duplicate_msgids` — repair the 3 duplicate-msgid errors in the event-importer catalogue |
| **Date** | 2026-09-27 |
| **Author / agent** | Cline (cline@example.invalid) |
| **Branch** | `i18n` |
| **Start SHA** | `b32fba3cebb506342ac6ae3e049cef71bfe3602b` |
| **Final SHA** | `b47d098857d2348feafb1071b8713c6a43359e05` *(moved by a concurrent process during this task — see §3.1)* |
| **Working tree at finish** | dirty with **this task's single tracked modification** (the regenerated `.pot`), this task's evidence directory, **and** a large concurrent external theme refactor that this task deliberately did not touch (§3.1) |

## 1. Scope completed

The catalogue-integrity defect was repaired through the repository's own canonical
workflow, with no hand-editing and no weakening of any validation.

- Reproduced the **3 duplicate-msgid errors** on the pristine tracked catalogue.
- Traced each duplicated msgid to its real source and proved they are **one logical
  msgid each**, not genuine contexts.
- Verified the repaired `scripts/i18n-make-pot.sh` against the five previously
  identified defects before using it.
- Regenerated `conexao-event-importer.pot` with the documented command.
- Proved the extraction is **deterministic** and the generator is **idempotent**.
- Result: **3 → 0 duplicate-msgid errors**, `msgfmt --check` and `msgcat` both exit 0.
- Proved no translation, no runtime output, no content and no gate outcome changed.

## 2. Scope NOT completed

- **The generator was not modified.** The evidence did not show its repair to be
  incomplete, so it was left untouched (§5.1).
- **One `#.` translator comment is not extracted** (§5.2). Repairing it would require
  editing plugin PHP source or hand-editing the POT, both forbidden here.
- **The `POT-Creation-Date` field is not reproducible byte-for-byte** across clock
  minutes (§6.2). It is xgettext's own wall-clock stamp, outside the script's control.
- **A latent no-op guard in `add_header_strings()` was found but not changed** (§5.1).
  It cannot cause a duplicate in the canonical flow; changing it is unrelated work.
- **No `.po`/`.mo` update** — not required and deliberately not done.
- **No production action of any kind** (§8). **Flutter/mobile was never accessed.**

## 3. Files added / modified / deleted

**1 modified, 0 added, 0 deleted** in tracked code (plus this report and the evidence
directory).

| Path | Change | Note |
|---|---|---|
| `wp-content/plugins/conexao-event-importer/languages/conexao-event-importer.pot` | regenerated | **The only `wp-content/` change.** Generated artefact, never loaded at runtime. 388 → 385 entries, duplicates 3 → 0. |
| `docs/evidence/2026-09-27-event-importer-pot/` | added | 19 evidence files. |
| `docs/reports/2026-09-27-event-importer-pot-duplicate-msgids.md` | added | This report. |

**No PHP source, no script, no test, no template, no `plugins.json`, no Polylang
configuration, no baseline file and no `.po`/`.mo` was modified.**

### 3.1 A concurrent external change — disclosed

At **19:56:58 +0100**, while this task was running, another process:

- moved `HEAD` `b32fba3` → `b47d098` (committing this task's evidence files 00–02
  into that commit), and
- rewrote the theme in the working tree (**68 files deleted, 36 modified**),
  including removing `wp-content/themes/conexao-br-irlanda/languages/` entirely.

That change is **not** this task's. This task's only write is the `.pot` above, which
`git status --short -- wp-content/plugins/ scripts/ tests/` confirms.

Its consequences were **measured and attributed, not absorbed**:

## 4. Runtime impact

**None — behaviour-neutral, and proven rather than asserted.**

- A `.pot` is a translator template. WordPress loads `<locale>.mo` at runtime; the
  event-importer ships **no `.po` and no `.mo` at all**, so the file is never read by
  the running site. Its only textdomain call is `load_plugin_textdomain()`
## 5. Catalogue integrity — findings

### 5.1 The three duplicates, their source, and why they existed

All three duplicated msgids are the **plugin docblock header strings**, declared at
`conexao-event-importer.php:1` and synthesised by `add_header_strings()` in
`scripts/i18n-make-pot.sh` (which is what `wp i18n make-pot` also does):

| # | msgid | Reference in **both** copies |
|---|---|---|
| 1 | `Conexão BR Irlanda — Event Importer (Local Tools)` | `wp-content/plugins/conexao-event-importer/conexao-event-importer.php:1` |
| 2 | `Local-only event import/export tooling. Fetches, normalizes, …` | same |
| 3 | `1.7.1` | same |

- Each pair is **byte-identical** (comments, references and flags included).
- The file has **0 `msgctxt` entries**, so nothing distinguishes them by context:
  this is accidental duplication of the same msgid, **not** Outcome D.
- They are **not** gettext call sites: the strings appear in the plugin only in the
  docblock and in a `define()`; the one `esc_html_e()` occurrence is a *different*,
  longer sentence.

**Why they existed:** commit `88e8ec0` ran the then-broken generator, whose xgettext
pipeline ended in `</dev/null` while reading the file list on stdin. xgettext received
an **empty** list, extracted nothing, never created `--output`, and still exited 0 —
so the old catalogue survived untouched and `add_header_strings()` appended the three
header blocks **a second time**. The prior task repaired that defect (`88bdf22`).

**Latent observation, not changed:** the dedupe guard in `add_header_strings()`
compares a two-line string (`msgid` + `msgstr`) against a set of single `msgid` lines,
so it can never match. It is **harmless** in the canonical flow, because
`xgettext --output` overwrites the catalogue and never extracts those docblock
strings, so nothing is added twice. Proof 3 (evidence 17) demonstrates the idempotence
empirically. Fixing it would be unrelated work and is left to a follow-up.

### 5.2 One translator comment is not extracted

`#. translators: %d: number of parts generated before the failure` (1 of 85) is present
in the old catalogue and absent from the new one.

Root cause, proven with minimal reproductions: the comment sits **inside a `sprintf(`
argument list**, one line above a **multi-line `_n(`** call. Stock xgettext 0.23.2
associates a `translators` comment with a gettext call only when the call is on one
line — verified both ways.

This is **pre-existing generator behaviour, not a defect introduced here**: the same
engine and flags produced the theme catalogue already committed at `88bdf22`. The
plugin source is correct and the **msgid is fully preserved**; only a translator hint
is lost. Repairing it would mean editing plugin PHP or hand-editing the POT — both
outside this task.

## 6. Regeneration, determinism and exactly what changed

Command (the repository's documented canonical workflow):

```bash
./scripts/i18n-make-pot.sh --dry-run conexao-event-importer   # regenerated=1 failed=0
./scripts/i18n-make-pot.sh conexao-event-importer             # exit 0
```

| Metric | Before | After |
|---|---|---|
| entries (parsed) | 388 | **385** |
| unique `(msgctxt, msgid)` | 385 | 385 |
| **duplicate keys** | **3** | **0** |
| msgctxt entries | 0 | 0 |
| `#:` reference lines | 484 | 481 |
| absolute local paths | 0 | 0 |
| true header blocks | 1 | 1 |
| bytes | 51308 | 70646 |

**The msgid set is semantically identical: 0 removed, 0 added, 385 in both.** The
only content change is the removal of the 3 duplicate entries.

Other differences, all expected and documented:

- **355 entries** changed reference *path prefix* only, from plugin-relative to
  repository-relative — the canonical generator's documented behaviour, matching the
  theme catalogue regenerated at `88bdf22`.
- **111 entries** changed line numbers — real source drift since the catalogue was
  last extracted on 2026-09-21. Line numbers are informational; no msgid changed, so
  **no translation is orphaned**.
- The header lost the WP-CLI-only `X-Generator` and `X-Domain` fields; the component is
  still identified by the preserved `Project-Id-Version`.

### 6.2 Determinism

| Run | sha256 |
|---|---|
| 1 (unpinned) | `faa7b1bc2d4f8bc…` |
| 2 (unpinned, +75 s) | `99ddae75a28602ca…` |
| 3 (`SOURCE_DATE_EPOCH` pinned) | `faa7b1bc2d4f8bc…` |
| 4 (same pin, back-to-back) | `faa7b1bc2d4f8bc…` |

Runs 3 and 4 are **byte-identical including the timestamp**. Runs 1 and 2 differ in
**exactly one line** — `POT-Creation-Date`, xgettext's own wall-clock stamp — and the
catalogue **body is identical** (body md5 `519d16cd1e4d62be926e8dc518d9f587` in all
four runs). The extraction is fully deterministic; the timestamp is not under the
script's control. This matches the behaviour already accepted for the theme catalogue.

## 7. Polylang / route impact

**None.** No Polylang configuration, language, translation relationship or term was
touched; PT content is untouched. No route, redirect, canonical, hreflang or sitemap
behaviour changed — all 8 regression routes are byte-identical.

## 8. Production actions

| Action | Performed? | Detail |
|---|---|---|
| Production write | **no** | Local Docker only (`localhost:8080`). |
| Deploy / upload | **no** | No build, no release, no upload. |
| Plugin activation | **no** | |
| Content or DB mutation | **no** | No post, page, term, meta, user or option written. |
| Event data mutation | **no** | The event importer was never run. |
| Production action of any kind | **no** | |
| **Flutter / mobile** | **no** | Not accessed, inspected, built, tested or modified. |

- The worktree copies of the four translation files are currently absent, so Phase 8
  was proven by **git object identity** instead (§9, evidence 08) — identical blob
  ids, and the sha256 values recorded at task start still match the committed bytes.
- The main worktree's `i18n_freshness` gate now fails because the **theme** catalogue
  no longer exists. A detached worktree at `HEAD` was used to prove this is unrelated
  to the `.pot` (§9, evidence 10).
- This task did **not** revert, absorb or "fix" any of the external changes.

## 9. Verification commands and results

| Command | Exit | Result |
|---|---|---|
| `msgfmt --check` (pristine catalogue) | 1 | **3 fatal errors** — defect reproduced |
| `msgfmt --check` (regenerated) | **0** | **0 duplicate/format errors** |
| `msgcat` (regenerated) | **0** | clean |
| `./scripts/i18n-make-pot.sh --dry-run conexao-event-importer` | 0 | 1 planned, 0 failures |
| `./scripts/i18n-make-pot.sh conexao-event-importer` | 0 | regenerated; run 4× |
| `verify-i18n-freshness.py` — **isolated worktree, with change** | **0** | **8 passed, 0 failed, 0 stale** |
| `verify-i18n-freshness.py` — **isolated worktree, baseline** | 0 | 8 passed, 0 failed (identical) |
| `verify-permanent-gates.py` — isolated, with vs without change | 1 | **byte-identical**: 3 passed / 4 failed, 24 assertions, **0 violations, 0 new** |
| `./scripts/run-tests.sh` — with vs without change | 1 | **identical** (see below) |
| 8 regression routes, before vs after | 0 | all **200**, all **byte-identical** |
| `php -l` over 399 existing PHP files | 0 | **0 parse errors** |
| `vendor/bin/phpcs` | — | **COULD NOT RUN** (see §13) |
| `vendor/bin/phpstan` | 1 | 48 errors, all in the concurrently refactored theme |

### Numeric test results — with vs without this change

| | With change | Without (baseline) |
|---|---|---|
| In-process PHP | 33 total, 25 passed, **8 failed** | 33 total, 25 passed, **8 failed** |
| Assertions | **1766 passed, 26 failed** | **1766 passed, 26 failed** |
| Script-contract | 6 total, 2 passed, 4 failed | 6 total, 2 passed, 4 failed |
| HTTP acceptance | 3 total, 0 passed, 3 failed | 3 total, 0 passed, 3 failed |
| Failing-suite list | — | **IDENTICAL** |

**0 newly introduced failures, 0 fixed.** Every failure pre-exists this change.

### Script-contract results

`./scripts/run-tests.sh --scripts` is part of the run above: **6 suites, 2 passed,
4 failed**, identical with and without the change. The 4 failures include
`verify-i18n-freshness.py` in the **main** worktree, which fails only because the
concurrent refactor deleted the **theme** catalogue — proven green in isolation.

## 10. Pre-existing failures (not caused by this change)

Proven by running `./scripts/run-tests.sh` with the `.pot` reverted in place and
diffing (evidence 13 vs 14):

- `plugin/conexao-admin-ux/test-translation-state.php`
- `plugin/conexao-event-importer/test-export-language.php`
- `plugin/conexao-event-importer/test-language-identity.php`
- `plugin/conexao-event-importer/test-town-sanitization.php`
- `plugin/conexao-event-runtime/test-event-language-gate.php`
- `plugin/conexao-leisure-migration/test-excerpt-en-roundtrip.php`
- `plugin/conexao-leisure-migration/test-language-uuid.php`
- `theme/conexao-br-irlanda/test-leisure-attribute-normalization.php`

These are local database/content conditions. The 3 HTTP acceptance suites fail on
hreflang/canonical/EN-layer assertions whose theme files were **deleted by the
concurrent external refactor** (§3.1). The 4 PHP permanent gates that report
"0 assertions, exit 1" are an **environment limitation**: run directly they cannot
locate `wp-load.php` and must be executed inside the WordPress container via
`run-tests.sh`.

## 11. Failure proofs — the validators can fail

A gate never shown to fail is not a gate (evidence 17):

1. `msgfmt --check` on a **copy** of the pristine catalogue → **exit 1, 3 errors**.
2. `msgfmt --check` on the repaired catalogue → **exit 0, 0 duplicates**.
3. **Idempotence:** re-running the generator changes only `POT-Creation-Date`; the
   body is byte-identical. This is the exact opposite of the root-cause defect, where
   a second run appended 3 more duplicate blocks.
4. **Freshness sensitivity:** committing a one-line change to
   `conexao-event-importer.php` in a throwaway worktree turns the event-importer row
   **STALE** and the gate **fails** — proving the gate is not hard-coded green.

## 12. Baseline integrity

`verify-permanent-gates.py` writes to a **fixed tracked path**,
`docs/evidence/2026-09-26-stage-l/gate.json`. This run's output was copied to
`11-permanent-gates-MAIN-WORKTREE.json` and the historical file was restored with
`git checkout`, leaving it byte-identical to its committed state
(**md5 `a3ebe9820a73f885f91169d75123ef29`**).

`tests/baseline/permanent-gates.json` was **not** modified. **No gate was weakened,
no allowlist was added, and no duplicate-msgid error was allowlisted.**

## 13. Limitations

- **PHPCS could not run** — it requires `tokenizer`, `xmlwriter` and `SimpleXML`;
  only `tokenizer` is loaded, and `composer` is not installed, so
  `scripts/lint.sh` cannot complete. CI is authoritative. Pre-existing and identical
  to the previous task; this task changed **no PHP source**, so it cannot introduce a
  coding-standard violation.
- **PHPStan reports 48 errors**, all in the concurrently refactored theme. The prior
  committed baseline was 2 errors, both in `inc/i18n/fallback.php` — a file the
  external refactor has since deleted. None is in a file this task touched.
- **No browser tooling** was used. Verification was HTTP-level: status codes plus
  **byte-for-byte** comparison of 8 pages before and after, which is a stronger
  regression signal for a catalogue-only change than a screenshot.
- **The main worktree's gate results are degraded** by the concurrent theme refactor
  (§3.1); the authoritative before/after numbers therefore come from a detached
  worktree, which the concurrent change cannot affect.
- `POT-Creation-Date` is not byte-reproducible across clock minutes (§6.2).
- Not verified here: CI on a fresh clone, and production (out of scope).

## 14. Evidence paths

`docs/evidence/2026-09-27-event-importer-pot/`

| File | Proves |
|---|---|
| `00-baseline-repository-state.txt` | HEAD, branch, upstream, dirty tree, PHP/gettext/python/composer, Docker |
| `01-baseline-duplicate-msgid-errors.txt` | **The 3 duplicate errors reproduced**, exit 1, full statistics |
| `02-po-mo-hashes-BEFORE.txt` | Pre-change `.po`/`.mo` md5, sha256 and sizes |
| `03-duplicate-blocks-in-context.txt` | The duplicates in context; each pair **byte-identical**; no msgctxt |
| `04-source-trace-of-duplicates.txt` | **Every msgid traced** to `conexao-event-importer.php:1`; not gettext call sites |
| `05-generator-verification.txt` | The 5 checks on the repaired generator + the latent no-op guard |
| `06-deterministic-regeneration.txt` | Generator command, **4 run hashes**, determinism analysis |
| `07-outcome-and-comparison.txt` | Outcome A+C; metric table; msgid set diff; header diff; the comment finding |
| `08-po-mo-hashes-AFTER.txt` | **PO/MO byte-identity** by git blob id + sha256 cross-check |
| `09-catalogue-validation.txt` | `msgfmt --check`, `msgcat`, structural parse, counters, freshness gate |
| `10-isolated-before-after.txt` | **Isolated 3→0 with the gate green**, and the attribution of the main-worktree failure |
| `11-permanent-gates-MAIN-WORKTREE.{txt,json}` | The raw main-worktree gate run (degraded by the external refactor) |
| `12-permanent-gates.txt` | **Baseline vs with-change: byte-identical gate outcomes**; baseline restoration |
| `13-run-tests-WITH-change.txt` | Full suite, with the change |
| `14-run-tests-WITHOUT-change-BASELINE.txt` | Full suite, change reverted — **the comparison that attributes every failure** |
| `15-http-regression.txt` | 8 routes, 200 + byte-identical before/after |
| `16-static-analysis-and-scope.txt` | `php -l`, PHPCS/PHPStan status, security & performance audit |
| `17-failure-proofs.txt` | **4 negative proofs** that the validators can fail; idempotence; final state |
| `18-final-acceptance-sweep.txt` | Criteria A–Q checked one by one |

## 15. Documentation updated

This report and the evidence directory only. No source document needed a change: the
generator, its documented behaviour and the catalogue format are all unchanged, and
`scripts/README.md` was already correct for this workflow.

## 16. Rollback / recovery

```bash
git checkout -- wp-content/plugins/conexao-event-importer/languages/conexao-event-importer.pot
```

That restores the defective catalogue (`sha256 1fb18c4f8059e47b…`) and returns
`msgfmt --check` to exit 1 with 3 duplicate errors — i.e. it re-creates the defect,
which is the correct demonstration that the change is what fixed it.

**No data rollback is needed**: no content, database, configuration, translation or
`.po`/`.mo` was modified, and no production state was touched. Rollback does **not**
cover the concurrent external theme refactor (§3.1), which is a separate change owned
by another process and deliberately left untouched.

## 17. Final status

**Final status: PASS WITH LIMITATION**

The duplicate-msgid defect is genuinely repaired end-to-end —
`source → canonical generator → valid, deterministic, duplicate-free catalogue` — with
3 → 0 errors, zero translations touched, zero runtime impact and zero new failures,
proven by an isolated before/after with an identical failing-suite list; but PHPCS
could not run in this environment (missing PHP extensions, no `composer`), so the full
static-quality gate is not satisfied here and CI remains authoritative for it.

_Last verified: 2026-09-27 by Cline — Event Importer POT Duplicate Msgids_
