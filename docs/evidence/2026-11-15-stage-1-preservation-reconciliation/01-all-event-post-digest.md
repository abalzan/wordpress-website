# Reconciliation A — all-event-post digest

## 1. The two captures

| | Before-edit | After-edit |
|---|---|---|
| Source file | `docs/evidence/2026-11-15-stage-1/00-before-edit-preservation-baseline.md` | `docs/evidence/2026-11-15-stage-1/03-shared-preservation-after.txt` |
| Capture date | 2026-11-15, before source edits | 2026-11-15, after implementation and isolated test runs |
| Database | `wordpress` (shared local) | `wordpress` (shared local) |
| Table | `wp_posts` | `wp_posts` |
| Scope | `post_type = 'event'`, all statuses | `post_type = 'event'`, all statuses |
| Columns | `ID, status, date, modified, slug, title` | same |
| Row count | 3,705 | 3,705 |
| Hash | SHA-256 | SHA-256 |
| Value | `86a39a84e474e52bb05f75ccea4d35716024e2586be757f4c315af8b70eb4c97` | `4ec41dc272253a448841665e2d7259b065ebb22f499739f228b4762217b9ef43` |

Both captures are from the **same database**, the **same table**, the **same
scope**, the **same column list** and the **same row count**. This is not a
cross-environment comparison.

## 2. Recovering the serialization

Neither capture documented its ordering or serialization. The baseline said only
"SHA-256 over deterministic, sorted raw MySQL output". The digest therefore
could not be recomputed as recorded, so the serialization had to be recovered
from evidence before any conclusion could be drawn.

The recovery used the third fingerprint in the same baseline, whose input is
small enough to test exhaustively: the 275-row `stage32_export_test`
`(post_id, source_id)` list, recorded as
`a77b0943883db818466e283d3783ba529b9d7aa695bb18cd8384ed8d3acacd3e`.

Testing separators against the live 275-row cohort:

| Serialization | SHA-256 |
|---|---|
| comma-separated | `46714d76049aa4c7a38bfc946a8d6d080becf4c4d8682d4614a3d9f481c9f630` |
| **tab-separated** | **`a77b0943883db818466e283d3783ba529b9d7aa695bb18cd8384ed8d3acacd3e`** |

The tab-separated form reproduces the recorded fingerprint **exactly**. The
serialization is therefore tab-separated, one row per line, ordered by `ID`.

## 3. Reproducing the after-edit digest

Applying the recovered serialization to the all-event query:

```sql
SELECT CONCAT_WS('\t', ID, post_status, post_date, post_modified, post_name, post_title)
FROM wp_posts
WHERE post_type = 'event'
ORDER BY ID;
```

Result: 3,705 rows, 481,098 bytes, SHA-256
`4ec41dc272253a448841665e2d7259b065ebb22f499739f228b4762217b9ef43`.

This matches the recorded after-edit digest **exactly**. The after-edit capture
is fully reproducible and is confirmed as a correct digest of the current
`wordpress.wp_posts` event rows.

## 4. Attempting to reproduce the before-edit digest

The before-edit digest could not be reproduced from current state. This is
expected and not itself a defect: the before-edit capture describes an earlier
snapshot, and the database has legitimately changed since.

The following hypotheses were tested and **all failed** to reproduce
`86a39a84…`:

| Hypothesis | Result |
|---|---|
| Different column subset (5 columns, 4 columns, with/without `post_title`, with `post_type`) | no match |
| Different status scope (`publish` only; excluding `trash`; excluding `auto-draft`; excluding both) | no match |
| Different ordering (`post_date_gmt`, `post_modified_gmt`, `post_title`, `post_name`) | no match |
| Different separator (comma, tab, CRLF) | no match |
| Different line ending / trailing newline / header row / sorted lines | no match |
| Pre-import reconstruction (drop the 5 events created 2026-11-15 07:42–07:45, revert `133818`'s `post_modified`) | no match (3,700 rows, `d5cc1ae4…`) |

The before-edit snapshot is not reconstructible from current state, because the
inputs that produced it — the exact row contents at that moment — no longer
exist. The `post_modified` values of the six externally imported events and of
`133818` were written after the baseline was captured, and the original values
are not recoverable from the database.

## 5. What changed between the two captures

The database did change between the captures, and the change is fully
documented and independently attributable — **not** to Stage 1.

Six event posts carry `post_modified` on 2026-11-15, all between 07:42:50 and
07:45:30:

| ID | `post_date` | `post_modified` | `_event_source` |
|---|---|---|---|
| 133818 | 2026-11-08 20:18:08 | 2026-11-15 07:42:50 | `eventbrite_cork` |
| 171003 | 2026-11-15 07:42:51 | 2026-11-15 07:42:51 | `eventbrite_cork` |
| 171004 | 2026-11-15 07:42:53 | 2026-11-15 07:42:53 | `eventbrite_cork` |
| 171006 | 2026-11-15 07:42:54 | 2026-11-15 07:42:54 | `eventbrite_cork` |
| 171007 | 2026-11-15 07:45:29 | 2026-11-15 07:45:29 | `eventbrite_donegal` |
| 171008 | 2026-11-15 07:45:30 | 2026-11-15 07:45:30 | `eventbrite_kildare` |

This is exactly the external modification already recorded in Stage 0.5's
`14-final-verification.txt`:

> An external admin-triggered import ran at 07:42-07:45 UTC on 2026-11-15.
> 6 new event posts created (4 eventbrite_cork, 1 eventbrite_donegal, 1
> eventbrite_kildare). 1 existing post updated. Shared DB event count:
> 3700 -> 3705. This was detected during initial state inspection; not caused
> by this session.

The row count is identical in both captures (3,705) because the baseline was
captured *after* that import had already run. The digest differs because the
`post_modified` column of `133818` and the five new rows changed content, not
because rows were added or removed.

## 6. Corroborating evidence that does reconcile

Every other fingerprint in the same before/after pair matches exactly, computed
with the same recovered tab-separated serialization:

| Dataset | Before | After | Match |
|---|---|---|---|
| Event postmeta (72,527 rows) | `ad87518f…` | `ad87518f…` | yes |
| `stage32_export_test` cohort (275 rows) | `a77b0943…` | `a77b0943…` | yes |
| `conexao_event_sources` option | `d6225636…` | `d6225636…` | yes |
| `conexao_event_import_history` option | `2968eb94…` | `2968eb94…` | yes |
| `conexao_event_import_continuations` option | `e7338423…` | `e7338423…` | yes |
| `cron` option | `eb5c121b…` | `eb5c121b…` | yes |
| Event post count | 3,705 | 3,705 | yes |
| Event postmeta count | 72,527 | 72,527 | yes |

The after-edit values for the cohort and postmeta fingerprints were confirmed
by direct recomputation during this reconciliation, not merely read from the
evidence file.

## 7. Row-level comparison

A row-level comparison was performed between the two Stage 0.5 TSV inventories
(`01-event-content-before.tsv`, 3,640 rows, and
`09-event-content-after-unexpected-change.tsv`, 3,687 rows). Those files record
`ID, post_status, post_date_gmt, post_modified_gmt, post_name, post_title` and
bracket the Stage 0.5 script-contract run.

The 47 added IDs are the `170878`–`170979` block — the externally triggered
import of 2026-11-14 21:28–21:29, already documented in
`09-trigger-analysis.md` and `09-content-change.md`. No protected record was
modified in that comparison.

For the Stage 1 before/after pair specifically, no row-level diff is possible:
the before-edit evidence preserves only the digest, not the row set. The
before-edit TSV does not exist for the Stage 1 window.

## 8. Classification

**`RECONCILED — CAPTURE MISMATCH`**

Basis:

1. The after-edit digest is exactly reproducible and confirmed correct.
2. The serialization was recovered from independent evidence and validated
   against a second fingerprint.
3. The before-edit digest is not reproducible because its snapshot inputs no
   longer exist — a documented, expected consequence of the passage of time, not
   evidence of a data change.
4. The only intervening writes are the six externally imported events already
   documented by Stage 0.5 as external and not caused by any session.
5. Every other fingerprint in the same before/after pair matches exactly.

The discrepancy is explained by the difference in snapshot timing and the
undocumented serialization, not by a preservation failure. It is **not**
evidence of data loss.

## 9. Limitation

The before-edit digest cannot be independently recomputed, so the claim "no
event post row changed between the two captures other than the six documented
external writes" rests on the matching postmeta, cohort and option fingerprints
plus the documented external import — not on a direct row-level diff of the two
captures. A direct row-level diff would require a before-edit row inventory that
was never captured.

## 10. Correction — 2026-11-15 (Reconciliation A reclassification)

**Previous classification:** `RECONCILED — CAPTURE MISMATCH` (§8).
**Corrected classification:** `UNRESOLVED`.

**Reason for the correction.** Reconciliation A compared two digests taken from the same database, table, scope and column list: the before-edit digest (`86a39a84…`) and the after-edit digest (`4ec41dc2…`). The after-edit digest was reproduced exactly using tab-separated output ordered by `ID` with six columns (`ID, post_status, post_date, post_modified, post_name, post_title`), so the after-state capture method is established. The before-edit digest was not reproduced under any tested variant: its exact columns, scope, ordering, serialization and/or hash inputs remain undocumented, and its snapshot inputs are no longer available.

An unknown or unreproducible before-state method is not a demonstrably different capture method, so the evidence does not support `RECONCILED — CAPTURE MISMATCH`. The earlier classification was incorrect because it attributed the digest difference to a specific capture difference (snapshot timing plus serialization) that the retained evidence cannot demonstrate.

**What the retained evidence still shows:**
- The after-digest remains exactly reproducible and is confirmed correct (`4ec41dc2…`).
- Matching corroborating fingerprints exist for event postmeta (72,527 rows), the 275-row `stage32_export_test` cohort and the four event options (`conexao_event_sources`, `conexao_event_import_history`, `conexao_event_import_continuations`, `cron`), plus equal event post counts (3,705) and equal postmeta counts (72,527).
- Those corroborating fingerprints support preservation **only within their specific scope** (the fields, records and environments they cover). They do not convert the all-event-post digest into a completed reconciliation, because no before-edit row inventory exists for a direct row-level diff.

**What would close this issue.** The original before-digest method (exact columns, scope, ordering, serialization and hash inputs) or a documented before-state snapshot sufficient for recomputation. Neither exists in the retained evidence.

The original digest values and historical investigation results recorded in §1–§9 are retained unchanged; this section corrects only the effective classification of Reconciliation A.

_Last verified: 2026-11-15 by the Stage 1 preservation reconciliation_