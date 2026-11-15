# Reconciliation B — 275 versus 285 `stage32_export_test` posts

## 1. The two figures

| | 275 cohort | 285 cohort |
|---|---|---|
| Where recorded | `docs/evidence/2026-11-15-stage-1/00-before-edit-preservation-baseline.md`; `docs/evidence/2026-11-14-stage-0-event-importer-probe-deactivation/10-content-untouched-verification.csv` | `docs/evidence/2026-11-14-stage-0-5/14-final-verification.txt`; `docs/reports/2026-11-14-stage-0-5-test-safety.md` |
| Environment | `wordpress` (shared local) | `conexao_stage05_test_5bd9ece5c032` (disposable clone) |
| Described as | "protected preservation cohort" | "285 leftover fixture events from previous crashed run" |
| Exact ID list in evidence | **No** — only a count, a per-source breakdown and a fingerprint | **No** — only a count |
| Selection method | Documented SQL selector (below) | Not documented |
| Disposition | Present, protected | Deleted from the clone |

## 2. The 275 cohort

The baseline documents an exact selector:

```sql
SELECT p.ID, COALESCE(srcid.meta_value, '')
FROM wp_posts p
LEFT JOIN wp_postmeta src
  ON src.post_id = p.ID AND src.meta_key = '_event_source'
LEFT JOIN wp_postmeta srcid
  ON srcid.post_id = p.ID AND srcid.meta_key = '_event_source_id'
WHERE p.post_type = 'event'
  AND src.meta_value = 'stage32_export_test'
ORDER BY p.ID;
```

Per-source breakdown recorded in the baseline, and confirmed by direct
recomputation during this reconciliation:

| Source ID | Rows (baseline) | Rows (recomputed) |
|---|---:|---:|
| `stage32-exp-0001` | 55 | 55 |
| `stage32-exp-0002` | 110 | 110 |
| `stage32-exp-0003` | 55 | 55 |
| `stage32-exp-0004` | 55 | 55 |
| **Total** | **275** | **275** |

All 275 are `post_status = 'publish'`. ID range 137635–170259.

The `(post_id, source_id)` fingerprint recorded in the baseline
(`a77b0943…`) was reproduced exactly during this reconciliation using
tab-separated serialization, confirming the cohort is unchanged.

**The 275 cohort has no exact ID list in evidence.** The baseline records a
count, a per-source breakdown and a hash, but never enumerates the post IDs.

## 3. The 285 cohort

The 285 figure appears in exactly two places, both describing the same event:

`docs/evidence/2026-11-14-stage-0-5/14-final-verification.txt`:

> 2. test-export-language.php: 512 MiB memory exhaustion
>    - Root cause: build_export() called with no scope, loading all 3705 events with images.
>    - Also: 285 leftover fixture events from previous crashed run.
>    - Classification: environment-specific.
>    - Fix: Scoped build_export() to sources=['stage32_export_test']; deleted 285 leftover fixtures.

`docs/reports/2026-11-14-stage-0-5-test-safety.md`:

> The clone also had 285 leftover fixture events from a previous crashed run.
> ... Scoped both `build_export()` calls to `array( 'sources' => array( 'stage32_export_test' ) )`; deleted 285 leftover fixture posts and their orphan postmeta from the clone.

**The 285 cohort has no exact ID list in evidence.** No file in the repository
enumerates which 285 posts these were, how they were selected, or what their
source IDs were. The count is the only recorded fact.

## 4. Why the two figures cannot be equated

The two counts describe **different populations in different databases**, and
neither has an ID list:

- The 275 is a **shared-database** cohort, selected by `_event_source =
  'stage32_export_test'`, still present.
- The 285 is a **clone-only** set of leftover fixtures, deleted from the clone.

The Stage 1 baseline itself already reached this conclusion and recorded it:

> The 275-row `stage32_export_test` protected cohort is defined exactly by the
> read-only selector below; its post ID/source-ID list fingerprint is recorded so
> the same set can be compared after verification. The 285 rows mentioned in the
> Stage 0.5 report were clone-only leftovers and are not in this shared cohort.

Stage 1's `04-isolation-and-scope.md` repeats it:

> The 275-row shared `stage32_export_test` cohort and the 285 clone-only
> leftovers remain reconciled as separate environments; the latter were not
> counted as shared protected content.

That conclusion is consistent with the evidence, but it was reached from the
environment labels and the counts — **not** from an ID-level comparison, because
no ID list exists for either cohort.

## 5. Current-state verification

Read-only checks against both databases:

| Check | `wordpress` (shared) | `conexao_stage05_test_5bd9ece5c032` (clone) |
|---|---:|---:|
| Events with `_event_source = 'stage32_export_test'` | **275** | **0** |
| Events with `post_name LIKE 'stage32-exp%'` | 276 | 1 |
| Events with `post_title LIKE '%STAGE32-EXP%'` | 276 | 1 |
| Any postmeta containing `stage32` | — | 0 |
| Total event posts | 3,705 | 3,425 |

The clone's `stage32_export_test` cohort is **empty**, which is consistent with
the recorded deletion of the 285 leftover fixtures. The shared database's cohort
is **intact at 275**.

The clone retains exactly **one** post matching the `stage32-exp%` slug pattern.
It has no `_event_source` meta and no postmeta containing `stage32`, so it is
not part of the `_event_source`-selected cohort. It is a residual row from the
same fixture family, not evidence of a surviving cohort.

## 6. The 276-row discrepancy in the Stage 0.5 TSV

A related and previously unremarked discrepancy was found while comparing ID
sets. The Stage 0.5 before-inventory
(`01-event-content-before.tsv`) contains **276** rows matching `stage32`, not
275.

The difference is exactly one post: **ID 25441**.

Post 25441 in the current database:

| Field | Value |
|---|---|
| `post_type` | `event` |
| `post_status` | `publish` |
| `post_date` | 2026-09-26 09:59:04 |
| `post_modified` | 2026-09-26 09:59:04 |
| `post_name` | `stage32-exp-en` |
| `post_title` | `[STAGE32-EXP] English source` |
| `_event_export_uuid` | `2b30f8bf-d4ac-4072-b8aa-278fa74f28d4` |
| `_event_source` | **absent** |
| `_event_source_id` | **absent** |

Post 25441 matches the fixture family by slug and title but carries **no
`_event_source` meta**. The documented selector requires
`src.meta_value = 'stage32_export_test'`, so 25441 is correctly excluded from the
275 cohort. The 276-row TSV count is a slug/title match, not a cohort match.

This fully explains the 276-versus-275 difference and confirms the 275 cohort
definition is correct. It does not explain the 285, which is a different
population in a different database.

## 7. Membership comparison

| Comparison | Result |
|---|---|
| Unique IDs in the 275 cohort | **Not available** — no ID list in evidence |
| Unique IDs in the 285 cohort | **Not available** — no ID list in evidence |
| Intersection | **Not computable** |
| Exclusive to 275 | **Not computable** |
| Exclusive to 285 | **Not computable** |
| Duplicates | **Not determinable** |

The 275 cohort's ID list could be reconstructed from current database state, but
that would describe the cohort **today**, not at the time of the Stage 0.5
capture. The 285 cohort's ID list cannot be reconstructed at all: those rows were
deleted from the clone, and the clone's current state cannot establish which
posts were present at an earlier time.

The task's rule applies directly: current state cannot establish historical
cohort membership, and the missing list must not be reconstructed from it.

## 8. Classification

**`UNRESOLVED — INSUFFICIENT IDENTITY EVIDENCE`**

Basis:

- The **285 cohort's ID list is missing.** It is the list that cannot be
  recovered, because its records were deleted from the clone and no enumeration
  was captured at the time.
- The 275 cohort's ID list is also absent from the historical evidence, though
  its membership is well defined by a documented selector and is still
  reproducible today.
- Without both lists, no intersection, exclusive set or overlap can be computed.
  A numerical difference of ten is not evidence of any relationship between the
  populations.

What the evidence **does** support independently:

1. The 275 cohort is a real, intact, well-defined shared-database cohort,
   unchanged since the baseline (fingerprint reproduced exactly).
2. The 285 figure refers to clone-only leftovers that were deleted, and the
   clone's cohort is now empty — consistent with that deletion.
3. The two figures describe different environments and different dispositions
   (protected vs deleted), so they are **not interchangeable** and the 285 must
   not be treated as shared protected content.
4. The 276-versus-275 difference in the Stage 0.5 TSV is fully explained by post
   25441 lacking `_event_source` meta.

What remains unknown: whether the 285 clone leftovers overlapped the 275 shared
cohort, were a superset, or were entirely distinct. The evidence does not
establish this, and it cannot be established without the missing ID list.

## 9. Follow-up that would resolve this

Resolving the relationship would require evidence that no longer exists in the
repository — specifically, a captured ID list of the 285 clone leftovers from
before their deletion. If such a list exists outside this repository (for
example in an operator's session log or a database dump taken at the time), it
would allow the intersection to be computed. No such source is available here.

_Last verified: 2026-11-15 by the Stage 1 preservation reconciliation_