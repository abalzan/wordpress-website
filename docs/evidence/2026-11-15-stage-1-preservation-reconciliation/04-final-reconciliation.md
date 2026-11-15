# Final reconciliation — executive summary

## What was reconciled

Two outstanding evidence questions from the Stage 1 implementation report were
investigated read-only, with no database writes, no WordPress bootstrap, no
importer or scheduler activity, and no application code changes.

### Reconciliation A — all-event-post digest

**`RECONCILED — CAPTURE MISMATCH`**

The before-edit digest (`86a39a84…`) and after-edit digest (`4ec41dc2…`) were
both taken from the same `wordpress` database, the same table, the same scope,
the same six columns and the same 3,705-row count. Neither capture documented
its serialization.

The serialization was recovered from independent evidence: the baseline's
275-row cohort fingerprint (`a77b0943…`) is reproducible only with
**tab-separated** output, ordered by `ID`. Applying that method to the
all-event query reproduces the after-edit digest **exactly**
(`4ec41dc2…`), confirming the after-edit capture is correct and the method is
right.

The before-edit digest is not reproducible from current state, because its
snapshot inputs no longer exist. Every plausible variant was tested and none
matched: column subsets, status scopes, orderings, separators, line endings,
headers, and a pre-import reconstruction.

The database did change between the captures, and the change is fully
documented and independently attributable: six event posts were written by an
external admin-triggered import at 2026-11-15 07:42–07:45 UTC, already recorded
by Stage 0.5 as external and not caused by any session. The row count is
identical in both captures because the baseline was taken after that import.

Every other fingerprint in the same before/after pair matches exactly: event
postmeta (72,527 rows), the 275-row cohort, and all four option digests.

The discrepancy is explained by snapshot timing and an undocumented
serialization. It is **not** evidence of data loss.

### Reconciliation B — 275 versus 285 `stage32_export_test` posts

**`UNRESOLVED — INSUFFICIENT IDENTITY EVIDENCE`**

The 275 is a shared-database cohort, defined by a documented selector, still
present and fingerprint-identical. The 285 is a clone-only set of leftover
fixtures, deleted from the disposable clone.

Neither cohort has an exact ID list in evidence. The 285's list cannot be
recovered at all: those rows were deleted, and the clone's current state cannot
establish which posts were present at an earlier time. Without both lists, no
intersection, exclusive set or overlap can be computed. A numerical difference
of ten is not evidence of any relationship.

Current-state verification confirms the shape of the evidence: the shared
database holds exactly 275 `stage32_export_test` posts; the clone holds zero.

A related discrepancy was resolved: the Stage 0.5 before-inventory contains 276
rows matching `stage32`, not 275. The difference is exactly one post, **ID
25441**, which matches the fixture family by slug and title but carries no
`_event_source` meta and is therefore correctly excluded from the cohort. This
confirms the 275 cohort definition is correct.

## What remains unresolved

1. **The 285 cohort's membership.** No ID list exists, and none can be
   reconstructed. Whether the 285 clone leftovers overlapped the 275 shared
   cohort, were a superset, or were entirely distinct is unknown and cannot be
   determined from available evidence.
2. **The before-edit all-event digest.** It cannot be recomputed, so the claim
   that no event row changed other than the six documented external writes rests
   on the matching postmeta, cohort and option fingerprints rather than on a
   direct row-level diff. A before-edit row inventory was never captured.
3. **The 14 probe-associated events.** No enumerated ID list or before/after
   fingerprint exists; the claim rests on counts.

## Whether any protected record was changed

**No protected record was identified as changed.**

- The 275-row shared cohort is fingerprint-identical before and after.
- Event postmeta (72,527 rows) is fingerprint-identical.
- All four option digests are identical.
- The 60 enumerated incident rows are documented with before/after fingerprints.
- The only writes between the captures are the six externally imported events,
  already recorded as external.

No evidence of a preservation violation was found in any cohort.

## Whether the Stage 1 preservation evidence can be upgraded

**Partially, and only in one direction.**

The Stage 1 report stated that the all-event-post digest mismatch meant "full
all-event post-row equality is not claimed". That remains correct — full
row-level equality is still not established, because the before-edit row
inventory does not exist.

What the reconciliation adds is an **explanation** for the mismatch: it is a
capture-timing and serialization artifact, not a data change. The Stage 1
report's caution was appropriate and should be retained, but the reason for the
mismatch is now known and documented.

The Stage 1 verdict of `PASS WITH LIMITATION` is **not upgraded** by this task.
The limitation is now better characterised, not removed.

## Follow-up requiring separate authorisation

1. **Capturing a before-edit row inventory** for any future change of this kind,
   so that a direct row-level diff becomes possible. This is a process change,
   not a database operation.
2. **Locating any external record of the 285 clone leftovers** (operator session
   log, database dump taken at the time) that would allow the intersection to be
   computed. No such source exists in this repository.
3. **Recording the digest serialization in the evidence template**, so that
   future baselines are reproducible without recovery work.

None of these were performed. Each requires separate authorisation.

## Final verdict

**`UNRESOLVED`**

Derivation per the task's table:

| Reconciliation A | Reconciliation B | Final verdict |
|---|---|---|
| `RECONCILED — CAPTURE MISMATCH` | `UNRESOLVED — INSUFFICIENT IDENTITY EVIDENCE` | `UNRESOLVED` |

The task's rule is explicit: if either reconciliation is `UNRESOLVED`, the final
verdict is `UNRESOLVED`, and a `RECONCILED` verdict must not be used while any
material preservation question remains unresolved. Reconciliation B is
unresolved, so the final verdict is `UNRESOLVED`.

This is the honest classification and it is recorded rather than softened. The
task also states that the Stage 1 verdict of `PASS WITH LIMITATION` must not be
upgraded by this task, and downgrading it requires explicit user authorisation
and is out of scope here. The Stage 1 verdict therefore stands unchanged.

### What is and is not established

The `UNRESOLVED` verdict reflects one specific gap — the 275-versus-285
relationship — not a general failure of the preservation evidence. It is worth
separating the two:

**Established:**

- The after-edit all-event digest is exactly reproducible and correct.
- The digest serialization is recovered and validated against a second
  fingerprint.
- The all-event digest mismatch is explained by snapshot timing and six
  externally documented writes, not by a data change.
- Every comparable fingerprint matches exactly: event postmeta, the 275-row
  cohort, and all four option digests.
- The 275-row shared cohort is fingerprint-identical before and after Stage 1.
- The 276-versus-275 TSV discrepancy is fully explained by post 25441.
- No protected record was identified as changed.

**Not established:**

- The relationship between the 275 shared cohort and the 285 clone leftovers.
- A direct row-level diff of the before/after all-event captures.
- An ID-level or fingerprint-level preservation claim for the 14 probe events.

The first of these is what forces the `UNRESOLVED` verdict. It is a gap in the
historical evidence, not evidence of a preservation failure.

_Last verified: 2026-11-15 by the Stage 1 preservation reconciliation_