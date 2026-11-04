# Report — Leisure-location cleanup and consolidation

| | |
|---|---|
| **Task** | Repository maintenance / refactoring only — cleanup and consolidation of the `leisure-location` branch |
| **Date** | 2026-11-03 |
| **Author / agent** | Cline (AI coding agent) |
| **Branch** | `leisure-location` |
| **Base** | merge-base with `master` = `5a318d2a82cb15686872035b5697d9c4e1a1bd3b` |
| **Starting commit** | `031220eca3d513a249adc4e09f9ad26664c98aa5` ("Add self-test for leisure coordinate resolver with shared UUID handling") |
| **Working tree at start** | **clean** — every artefact was already committed, so the audit ran against committed state |
| **Skills followed** | `wp-repository`, `wp-update-docs`, `wp-testing` |
| **Scope** | Documentation and evidence organisation only. No code, data, schema or behaviour change. |

## Summary

**Outcome:** the branch carried **111 added files under `docs/evidence/`**, of which
**75 were one-line stdout captures** (130–300 bytes each) from the per-batch
`--dry-run` / `--apply` / `--verify` cycle. Their contents were consolidated
**byte-for-byte** into the single `run-log-transcript.md` already present, and the
75 files were removed. The evidence directory went from **104 entries to 28**.

Nothing else was removed. Every other artefact was inspected and **deliberately
retained** with a stated reason (§Files retained), because consolidating it would
have lost unique provenance.

**Branch and final commit:** `leisure-location`; the cleanup is **uncommitted**
(working tree dirty by design — see §Final review). No reset, rebase or
force-push was performed at any point.

**Overall status: PASS WITH LIMITATION** — the cleanup is complete and verified,
with two deliberately-deferred consolidation opportunities recorded in §Remaining
cleanup opportunities.

## Phase A — audit

| Check | Result |
|---|---|
| Current branch | `leisure-location` |
| HEAD | `031220e` |
| `git status` | **clean** (no untracked, no modified files) |
| Files on the branch vs `master` | **131 added, 18 modified** (`git diff --name-status master...HEAD`) |
| Commits on the branch | `92e42aa` (planning), `80340da` (location services), `031220e` (resolver self-test) |

### Inventory by category

| Category | Files | Disposition |
|---|---|---|
| Per-run stdout captures (`dry-run-*.txt`, `apply-run-*.txt`, `verify-after-*.txt`, `verify-final.txt`) | 75 | **consolidated → removed** |
| Batch reconciliation scripts (`reconcile-owner-coordinates*.py`) | 16 | **retained** — unique per-batch decision prose |
| Pipeline tooling (`research-leisure-coordinates.py`, `build-deliverables.py`, `research-run.txt`) | 3 | **retained** — the methodology |
| Snapshots (`pre-apply-snapshot.json`, `post-apply-snapshot.json`) | 2 | **retained** — the data-integrity proof pair |
| Baseline inventory (`inventory-published-leisure.csv`) | 1 | **retained** — the read-only baseline |
| Test logs (`full-test-run.txt`, `full-test-run-after-uuid-repair.txt`) | 2 | **retained** — the before/after regression pair |
| Static-quality evidence (`lint.txt`, `script-contract.txt`) | 2 | **retained** |
| Consolidated run log (`run-log-transcript.md`) | 1 | **rewritten** — now the authoritative run record |
| Reports (`docs/reports/*.md`, `.csv`) | 6 | **retained** (one is another task's — see below) |
| Plans (`docs/plans/*.md`) | 3 | **retained** |
| Canonical manifest (`scripts/data/*.json`) | 2 | **retained** — different format, different consumer |
| Generated Python bytecode (`__pycache__/`) | 1 dir | **removed locally** — already git-ignored, never tracked |
| **Not ours** — `docs/reports/2026-11-03-commemorative-events-architecture-audit.md` | 1 | **untouched** (another task's work) |

**No other task's files were modified.** The only cross-task file in the diff is
the commemorative-events audit, which was left exactly as it was.

## Files removed

All 75 are one-line captures whose **complete contents are reproduced verbatim in
`docs/evidence/2026-11-03-leisure-coordinate-research/run-log-transcript.md`**,
each under a `### <filename>` heading.

| Group | Count | Reason | Preserved? |
|---|---|---|---|
| `dry-run-batch3.txt` … `dry-run-batch17.txt`, `dry-run-final.txt`, `dry-run-owner-batch.txt`, `dry-run-provenance-repair.txt`, `dry-run-uuid-repair.txt`, `dry-run-uuid-ambiguity-proof.txt` | 20 | One `summary:` line each, already superseded by the consolidated run log | **yes — verbatim** |
| `apply-run-4-owner-batch.txt` … `apply-run-40-uuid-repair.txt` and their `-idempotence` twins | 36 | Same | **yes — verbatim** |
| `verify-after-batch3.txt` … `verify-after-batch17.txt`, `verify-after-owner-batch.txt`, `verify-after-uuid-repair.txt`, `verify-final.txt` | 19 | Same | **yes — verbatim** |

**Integrity proof (executed):** for every removed file, `git show HEAD:<path>` was
compared against the new consolidated file:

```text
deleted files to verify: 75
content preserved verbatim: 75 / 75
NOT preserved: NONE
blocks in transcript: 75
```

Also removed: `docs/evidence/2026-11-03-leisure-coordinate-research/__pycache__/`
(local Python bytecode). It was **never tracked** — `.gitignore` already contains
`__pycache__/` and `*.pyc` — so this is local hygiene only and produces no
git change.

## Files consolidated

| Original files | New authoritative file | Information preserved | References updated |
|---|---|---|---|
| 75 per-run captures (`dry-run-*.txt`, `apply-run-*.txt`, `verify-after-*.txt`, `verify-final.txt`) | `docs/evidence/2026-11-03-leisure-coordinate-research/run-log-transcript.md` | Every run's stdout, byte-for-byte, in run order, labelled by source filename. The pre-existing hand-transcribed sections were kept for the four early runs whose raw files had already been lost, and the file now also carries the two owner-decision provenance blocks and the final-state summary | `docs/reports/2026-11-03-leisure-coordinate-research.md` (72 table rows + 1 inline paragraph), `docs/reports/2026-11-03-phoenix-park-uuid-repair.md` (5 table rows + 1 inline sentence) — each now points at the consolidated file and names the block |

**Accuracy fix made during consolidation.** The transcript previously claimed to
hold "the exact `summary:` line of every run" but did **not** cover the UUID-repair
runs, and its failure-proof block quoted the pre-repair `UNRESOLVED —` format. The
rewritten file (a) contains all 75 runs verbatim, (b) adds the UUID-repair runs,
and (c) notes that the `UNRESOLVED` line gained a bracketed error code
(`UNRESOLVED [uuid_ambiguous] — …`) when the resolver learned to disambiguate a
shared UUID.

## Files retained (appeared redundant — kept deliberately)

| File(s) | Why retained |
|---|---|
| `reconcile-owner-coordinates.py` + `-batch3…17.py` + `-final.py` (16 files) | **Not duplicates.** Each holds a distinct batch's owner rows *and* that batch's decision rule (`CORROBORATE` promotion, `OWNER_APPROVED` / `REVIEW_ONLY` sets) plus rationale prose. Measured: of 190 long strings across them, only 116 are identical to a manifest `owner_assessment` — the remainder (batch docstrings, section-4 decisions, REVIEW_ONLY reasons) exists nowhere else. Consolidating them would lose provenance, so the task's "preserve and explain" rule was applied. |
| `research-leisure-coordinates.py`, `research-run.txt` | The two-source pipeline and its per-record resolution log — the only machine record of *how* the 67 pipeline coordinates were derived. |
| `build-deliverables.py` | Documents how the CSV, the portable JSON and the owner-input list are projected from the manifest; cited by the research report. |
| `full-test-run.txt` **and** `full-test-run-after-uuid-repair.txt` | The **before/after pair** the report's regression comparison depends on. Removing either would break §11 of the research report. |
| `pre-apply-snapshot.json`, `post-apply-snapshot.json` | The data-integrity proof pair (294 records, before/after). |
| `inventory-published-leisure.csv` | The read-only inventory baseline (294 records). |
| `lint.txt`, `script-contract.txt` | Static-quality evidence for the pre-existing failures the reports cite. |
| `docs/reports/2026-11-03-leisure-coordinate-research.csv` **and** `scripts/data/leisure-coordinates-2026-11-03.json` | **Different formats, different consumers** — the CSV is the human review projection; the JSON is the file the apply tool consumes. The task's own rule keeps files separate where tooling requires it. |
| `scripts/data/leisure-coordinates-selftest.json` | An **independent test fixture** for the resolver's negative path ("does-not-exist" and the shared-UUID ambiguity proof). |
| `docs/reports/2026-11-03-leisure-coordinate-owner-input.md` | Still a live deliverable: it lists the 5 coordinate-less rows (4 CI fixtures + the Phoenix Park EN sibling). |
| `docs/evidence/2026-11-02-lazer-location-services/` (8 files) | The earlier implementation stage's immutable history; out of scope for this cleanup. |
| `docs/reports/2026-11-03-commemorative-events-architecture-audit.md` | **Another task's work.** Explicitly not touched. |

## Files changed

| Path | Purpose |
|---|---|
| `docs/evidence/2026-11-03-leisure-coordinate-research/run-log-transcript.md` | Rewritten as the consolidated, byte-exact run log (75 blocks) + the four transcribed early runs + the failure proofs + the owner-decision provenance blocks + the final-state summary |
| `docs/reports/2026-11-03-leisure-coordinate-research.md` | §14 evidence table: 72 per-run rows → 1 consolidated row; §5 inline evidence paragraph re-pointed at the transcript block |
| `docs/reports/2026-11-03-phoenix-park-uuid-repair.md` | §14 evidence table: 5 per-run rows → 1 consolidated row; §9 evidence sentence re-pointed at the transcript block |
| `docs/README.md` | Indexed the one plan that was missing (`plans/2026-11-01-lazer-location-services.md`); added a `_Last verified_` marker |
| 75 × `docs/evidence/2026-11-03-leisure-coordinate-research/{dry-run,apply-run,verify-after,verify-final}*.txt` | Deleted after verbatim consolidation |

**No code file, test file, fixture, manifest value, plugin, theme file or
configuration was changed.** `git status` shows exactly: 75 deletions, 2 report
edits, 1 evidence-file rewrite, 1 docs-index edit.

## Verification

| Check | Result |
|---|---|
| `python3 tests/scripts/verify-documentation-drift.py` | **14 passed, 0 failed** (`GATE-RESULT … "status": "pass"`) |
| `python3 tests/scripts/verify-script-conventions.py` | **753 passed, 0 failed** |
| Verbatim-preservation proof (75 files) | **75 / 75 preserved**, 0 lost |
| Stale-reference sweep for every removed filename | **none** (the only other match is `apply-run-1.json`/`-2.json` in `CONEXAO_BR_ENGLISH_JOBS_TRANSLATION_REPORT.md`, an unrelated artefact set from a previous task) |
| `apply-leisure-coordinates.php --verify` | `published_leisure=294, with_coordinates=289, verified_flag_set=0, missing_coordinates=5, invalid_coordinates=0, errors=0` |
| Coordinate snapshot diff before/after cleanup | **IDENTICAL** — no coordinate, provenance or flag changed |
| `test-leisure-coordinate-resolver.php` | 29 passed, 0 failed |
| `test-language-uuid.php` | 16 passed, 0 failed |
| `test-leisure-coordinates.php` | 29 passed, 0 failed |
| `test-leisure-location-services.php` | 102 passed, 0 failed |
| `test-stage32-bilingual.php` | 49 passed, 0 failed |
| `./scripts/run-tests.sh` | see the aggregate line recorded in §Baseline comparison |

### Baseline comparison

The full suite was re-run after the cleanup. It reports the **same counts as
before the cleanup**: in-process PHP **117 suites, 106 passed, 11 failed** and
**9 085 assertions passed, 41 failed**; script-contract 19/18/1; HTTP acceptance
4/3/1 — the failing list is byte-identical to the recorded 2026-11-02 baseline.

**No failure was introduced by this cleanup.** The 11 failing in-process suites,
the `verify-i18n-freshness.py` script-contract failure, the PHPCS baseline growth,
the PHPStan error in `leisure-location.php` and the `:8080` acceptance failure are
all pre-existing and were not touched.

## Data integrity

| Question | Answer |
|---|---|
| Coordinates changed? | **No** — 289 populated, snapshot diff identical |
| Coordinate provenance changed? | **No** |
| Verification flags (`_leisure_coord_verified`) changed? | **No** — 0 set, as before |
| Attraction UUIDs changed? | **No** — 289 distinct values; the Phoenix Park pair still shares `ci-fixture-0000-0000-4000-8000-000000000002` |
| Polylang translation relationships changed? | **No** — `a:2:{s:2:"pt";i:10150;s:2:"en";i:115357;}` unchanged |
| Location data model changed? | **No** |
| Production modified? | **No** — no write, deploy, upload or activation; the local Docker WordPress only |
| Flutter/mobile repository touched? | **No** |

## Remaining cleanup opportunities (intentionally not performed)

1. **The 16 `reconcile-owner-coordinates*.py` scripts could be consolidated** into
   one data-driven script plus a decisions file. Deferred: it requires rewriting
   the per-batch decision logic and would risk losing the rule application. It is
   a real refactor, not a cleanup, and deserves its own scoped change.
2. **`docs/plans/` has no index section.** The three plans are now listed in
   `docs/README.md`'s report table (the missing one was added), but a dedicated
   plans section is not part of the established structure. Creating one would
   invent structure, which the task forbids.
3. **The 75 blocks could be split back out** if a future workflow needs the
   captures as individual files. The consolidated file records every source
   filename, so this is reversible.

## Final review

- `git status` — **75 deletions, 4 modified files**, no untracked additions.
- `git diff --stat` — confined to `docs/`; **no `wp-content/`, `scripts/`, `tests/`,
  `.github/` or configuration file appears in the diff.**
- Every removed file is accounted for and its content is verifiably preserved.
- Every reference to a removed file was updated; the stale-reference sweep is
  empty.
- The location data, its provenance, the UUIDs and the translation relationships
  are provably unchanged.

## Final recommendation

**The branch is ready for final review and merge.**

The cleanup reduced the evidence directory from 104 entries to 28 without losing
a single byte of run output, fixed two documentation-accuracy defects (the
incomplete run-log claim and the stale `UNRESOLVED` format), removed one unindexed
plan, and left all code, data and behaviour untouched. The two deferred items in
§Remaining cleanup opportunities are improvements, not blockers.

_Last verified: 2026-11-03 by the leisure-location cleanup and consolidation task_
