# PT-BR TRASH RESTORATION — six historical sources, phase 1 of the EN apply

> **This phase performed an intentional, authorised production content write**:
> six PT-BR `post` records restored from Trash to `publish`. Nothing else in
> production changed. No EN record was created, no translation was applied, the
> translation engine was never invoked, and Polylang was not touched.

| | |
|---|---|
| **Task** | Restore the six trashed PT-BR article records so they can serve as the real PT sources for the six remaining authored EN rows |
| **Date** | 2026-09-30 |
| **Branch** | `i18n` |
| **HEAD** | `6bc4a6be8654cc083a162445491227a278ce0a47` (unchanged — no commit) |
| **Production target** | `https://conexaobr.ie` (WordPress.com) |
| **Production writes** | **6** — status restoration only, on the six authorised IDs |
| **EN records created** | **0** |
| **Translations applied** | **0** |
| **Repository source changes** | **0** — evidence + this report only |
| **Status** | **PASS — SIX SOURCES RESTORED, `missing_source` 6 → 0** |

---

## 1. What was done, in order

The full six-step content-change contract was followed, with the write gated
behind an exact dry-run. Evidence lives in
[`docs/evidence/2026-09-30-pt-trash-restoration/`](../evidence/2026-09-30-pt-trash-restoration/).

| # | Step | Artefact | Result |
|---|---|---|---|
| 1 | Pre-write snapshot of all six records | `01-pre-write-snapshot.json` | 6/6 captured: identity, content hashes, meta, taxonomy |
| 2 | Preconditions, slug occupancy, corpus baseline | `02-preconditions.json`, `03-corpus-before.json` | all gates PASS |
| 3 | Dry-run | `04-dry-run-plan.json` | exactly 6 restorations, 0 other operations |
| 4 | Apply (the write) | `05-apply-log.json` | 6 writes, 1 field each |
| 5 | Post-restore verification | `06-post-restore-verification.json` | 6/6 integrity PASS |
| 6 | Post-write corpus comparison | `07-corpus-after.json` | 0 pre-existing records changed |
| 7 | EN manifest regenerated from restored production | `08-…`, `09-en-inventory-manifest.json` | `CREATE_CANDIDATE` 432 → 438 |
| 8 | Full EN dry-run re-run | `10-dry-run-plan.json`, `11-mutation-snapshot.json` | `missing_source` **0** |
| 9 | Independent second run + determinism | `12-…` … `15-mutation-snapshot-run2.json` | digests identical |
| 10 | Permanent gates + full test suite | `16-permanent-gates.txt`, `17-tests-after.log` | 6/7 gates pass; 1 pre-existing failure |

Full numbers: `18-summary-tables.txt`. Safety ledger: `19-safety-ledger.txt`.

## 2. The six records — before and after

Every record was `trash` with WordPress's `__trashed` suffix on `post_name`.
Restoring the status to `publish` made WordPress strip that suffix itself; the
slug was **never** sent in any payload.

| Production ID | PT slug (clean, after) | Before | After |
|---:|---|---|---|
| `10373` | `auxilios-e-apoio-relacionados-a-saude-e-bem-estar-na-irlanda` | `trash` | `publish` |
| `10374` | `auxilios-para-familias-atipicas-na-irlanda` | `trash` | `publish` |
| `10369` | `cursos-e-apoio-para-empreendedores` | `trash` | `publish` |
| `10367` | `dica-de-saude-para-quem-viaja` | `trash` | `publish` |
| `10375` | `guia-para-quem-esta-com-dificuldades-financeiras` | `trash` | `publish` |
| `10376` | `guia-pratico-para-brasileiros-em-laois` | `trash` | `publish` |

These are the same six IDs, slugs, titles and dates recorded by the
[PT source reconciliation](2026-09-30-en-pt-source-reconciliation.md)
investigation. No substitute record was used.

## 3. Exact production write count

| Measure | Value |
|---|---|
| HTTP POST requests issued | **6** |
| Distinct records written | **6** (the authorised set, nothing else) |
| Fields sent per write | **1** — `{"status": "publish"}` |
| Payload total | 6 status assignments and nothing else |
| EN records created | 0 |
| Translation links created | 0 |
| Taxonomy / Polylang / custom-field writes | 0 |
| Records deleted, re-keyed or renamed | 0 |

The dry-run asserted this shape *before* the write, and the apply script
re-verified each record was still `trash` immediately before touching it. An
abort would have stopped the run; no abort occurred.

## 4. Identity and content integrity

Each field was compared byte-for-byte against the pre-write snapshot — 8 checks
per record, 48 in total:

| ID | title | content | excerpt | date | author | featured image | categories | tags |
|---:|---|---|---|---|---|---|---|---|
| `10367` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `10369` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `10373` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `10374` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `10375` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `10376` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |

Exactly three fields moved across the whole set, all required by restoration or
set by the platform itself:

| Field | Change | Who caused it |
|---|---|---|
| `status` | `trash` → `publish` | the authorised write |
| `slug` | `<clean>__trashed` → `<clean>` | WordPress, as un-trash semantics |
| `meta.jetpack_social_post_already_shared` | `false` → `true` | WordPress.com housekeeping on publish |

The third is a Jetpack/WordPress.com internal social-sharing flag. It was never
sent by this operation and carries no authored content; it is recorded here
rather than hidden.

Content bodies are intact, including the real 6 333-byte article on `10369` and
the 119-byte byline stub on `10367`. The four placeholder records remain the
faithful 7-byte `<p></p>` they were published with — they were **not** filled
with invented articles.

## 5. No other production content changed

The full PT corpus (`posts`, `pages`, `guide`) was captured before and after and
compared record by record:

| Post type | Before | After | Delta | Identity digest changed |
|---|---:|---:|---:|---|
| `post` | 37 | 43 | **+6** | yes — the six restored records |
| `page` | 45 | 45 | 0 | no |
| `guide` | 56 | 56 | 0 | no |

**Pre-existing records with any field changed: 0.** The added set is exactly the
six authorised IDs; nothing was removed. The six appear as "added" only because
trashed records do not appear in a default listing — they were present, in the
trash, before.

## 6. EN reconciliation

Regenerated from the now-restored production state, using the repository's own
read-only tooling and the unmodified shared engine:

| Gate | Before | After |
|---|---:|---:|
| **`missing_source`** | **6** | **0** |
| `b1_missing_pt_source` | 6 | 0 |
| `b1_skipped_absent_pt_source` | 6 | 0 |
| `b1_creates` | 120 | 126 |
| `total_manifest_objects` | 425 | 425 (b1 126 + b2 299) |
| `b1_updates` | 0 | 0 |
| `duplicate_target_identities` | 0 | 0 |
| `pt_overwrite_attempts` | 0 | 0 |
| `unclassified_objects` | 0 | 0 |
| `conflicts` / `invalid` / `orphan` | 0 / 0 / 0 | 0 / 0 / 0 |
| `unresolved_slug_conflicts` | 0 | 0 |
| `b2_creates_en_records` | 0 | 0 |

Expected outcomes, all met:

* `missing_source` **6 → 0**.
* `en-guide` remains **50 rows**; the retired permanently-deleted row stays
  excluded and appears nowhere in the plan.
* No new authored EN rows; no manifest re-keying was performed.
* No PT identity was invented — all six rows resolved to their own historical
  production IDs (`10373`, `10374`, `10369`, `10367`, `10375`, `10376`), each
  with `slug_collision: false`.
* `newsletter` still resolves as `newsletter`, never `newsletter-2`; the string
  `newsletter-2` does not occur anywhere in the plan.
* `en-post` moved from 37 to 43 creates — the six restored sources and nothing
  else.

## 7. Determinism

An independent second inventory + dry-run + snapshot reproduced every number:

| Digest | Run 1 | Run 2 | Equal |
|---|---|---|---|
| inventory manifest digest | `973a4dcae4cd55a4…` | `973a4dcae4cd55a4…` | ✅ |
| plan digest | `7dc4f56ed45121fa…` | `7dc4f56ed45121fa…` | ✅ |
| snapshot digest | `d679f1c1eddb1f59…` | `d679f1c1eddb1f59…` | ✅ |
| PT identity digest | `76620b661bc72aeb…` | `76620b661bc72aeb…` | ✅ |
| snapshot objects | 438 | 438 | ✅ |

A structural diff of the two plan files found **exactly 2 differing leaves**, both
cosmetic: `report.generated_at_utc` (the clock) and `report.snapshot.path` (the
output filename chosen for run 2). Every plan row, every gate counter and both
digests are byte-identical. Snapshot object count 438; manifest object count 425.

## 8. PT drift by translated post type

| Post type | Records before | Records after | Δ |
|---|---:|---:|---:|
| `course_provider` | 11 | 11 | 0 |
| `event` | 1 807 | 1 807 | 0 |
| `guide` | 56 | 56 | 0 |
| `job` | 1 | 1 | 0 |
| `leisure` | 289 | 289 | 0 |
| `page` | 43 | 43 | 0 |
| **`post`** | **37** | **43** | **+6** |
| `sponsor` | 10 | 10 | 0 |

Polylang PT scope **2174 → 2174**, unchanged. The only digest that moved is
`post`, and it moved by exactly the six authorised records.

## 9. Permanent gates

| Gate | Result |
|---|---|
| taxonomy policy | **PASS** — 18 passed, 0 failed |
| translation completeness | **PASS** — 23 passed, 0 failed, 319 allowlisted |
| cache scoping (runtime) | **PASS** — 9 passed, 0 failed |
| cache scoping (static) | **PASS** — 2 passed, 0 failed |
| legacy EN→PT redirect precedence | **PASS** — 15 passed, 0 failed |
| documentation drift | **PASS** — 14 passed, 0 failed |
| **i18n catalogue freshness** | **FAIL — pre-existing, see §10** |

## 10. Full test suite vs the previous verified baseline

The suite was run **before** any production write to establish the baseline, and
again after.

| Measure | Baseline (pre-write) | After restoration | Delta |
|---|---|---|---|
| In-process PHP suites | 63 passed, 0 failed | 63 passed, 0 failed | none |
| Assertions | **4 216 passed, 0 failed** | **4 216 passed, 0 failed** | none |
| Script-contract suites | 5 passed, 1 failed | 5 passed, 1 failed | none |
| HTTP acceptance suites | 3 passed, 0 failed | 3 passed, 0 failed | none |

**No new failures. No assertion was weakened, removed or skipped.** The suite
exit code is 1 both before and after, for the same pre-existing reason.

### Pre-existing / environmental failures (not caused by this task)

`tests/scripts/verify-i18n-freshness.py` fails on **3 stale `.pot` catalogues**:
`conexao-br-irlanda`, `conexao-content` and `conexao-event-runtime`. This is a
**repository condition, not an environmental or network fault**, and it is
unrelated to this restoration:

* the identical 3 failures are present in the baseline run taken **before** the
  first production write;
* all three source files are **unmodified at HEAD** (`navigation.php`,
  `conexao-content.php`, `conexao-event-runtime.php`);
* the committed `docs/evidence/2026-09-26-stage-l/gate.json` already records this
  gate as `fail` with the same violations.

The gate's "1 new" label compares against a stale committed baseline of an older
commit; it is not evidence of a new violation from this task. Fixing it requires
regenerating the catalogues with `wp i18n make-pot`, which is out of scope here
and was deliberately not done.

Two other notes on repository state: running the gate runner rewrote
`docs/evidence/2026-09-26-stage-l/gate.json` (timestamp plus locally-derived
allowlist counts); that file was **restored with `git checkout`** so repository
data stays as it was. The modified files under `wp-content/` and `scripts/`
visible in `git status` all pre-date this task (they are the prior phases'
uncommitted work) and were not touched here.

## 11. Safety ledger

| | |
|---|---|
| Production content writes | **6** — authorised IDs only |
| Records touched | **6** |
| Pre-existing records changed | **0** |
| EN records created / links created | **0 / 0** |
| Translation engine invoked | **no** |
| Translation applied | **no** |
| Manifest rows re-keyed | **0** |
| Polylang configuration changed | **0** |
| Plugins / theme / tests / scripts changed | **0** |
| `.env` | read only, never modified, never printed |
| Secrets exposed | **NO** |
| Flutter/mobile repository | not accessed |

## 12. What was deliberately not done

No EN record was created. No translation was applied. The translation engine was
never invoked. No Polylang configuration changed. No manifest row was re-keyed.
Phase 3 EN apply has **not** been started and must not be, until this phase's
regenerated dry-run and snapshot are accepted.

## 13. Next step (not performed here)

The six `en-post` rows now resolve to real PT sources, so the plan is
deterministic and blocker-free: 126 B1 creates, 0 conflicts, 0 missing sources,
0 PT overwrites. Phase 3 may apply the EN rollout from this baseline. That is a
separate authorised step.

---

_Last verified: 2026-09-30 by the PT-BR trash restoration (six authorised production restorations, `missing_source` 6 → 0)_