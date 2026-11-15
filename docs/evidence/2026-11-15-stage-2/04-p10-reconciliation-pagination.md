# P10 — paginated absence reconciliation, gated on P4

## 1. Defect

`mark_missing_events()` evaluated at most 300 posts per source
(`WP_Query` with `posts_per_page => 300`, no pagination). A source with more
events — Dublin and Cork were measured at 848 and 561 — was only partially
reconciled: events beyond the first 300 were never checked for absence.

## 2. Implementation

`Conexao_Event_Importer_Engine::mark_missing_events()` is now ordered in three
steps, and that order is the guarantee:

### Step 1 — gates first (P4)

The method re-evaluates `is_handler_fetch_complete()` and
`is_handler_reconciliation_safe()` before doing anything else and returns `0`
with a warning when either is false. The call site in `run_source()` still
evaluates both gates as before; this second evaluation makes "gates before any
reconciliation query" a property of the method rather than of its caller.
An incomplete or resumed pass therefore performs **zero candidate queries** and
**zero mutations**.

### Step 2 — read-only candidate enumeration (keyset pagination)

`enumerate_source_owned_event_ids( $canonical_source )`:

```sql
SELECT p.ID
FROM wp_posts p
INNER JOIN wp_postmeta src
        ON src.post_id = p.ID AND src.meta_key = '_event_source' AND src.meta_value = %s
INNER JOIN wp_postmeta st
        ON st.post_id = p.ID AND st.meta_key = '_event_status'
WHERE p.post_type = 'event'
  AND p.ID > %d
  AND p.post_status IN ( <WP_Query default post statuses for this request> )
  AND st.meta_value NOT IN ( 'rejected', 'source_not_found' )
ORDER BY p.ID ASC
LIMIT %d
```

| Property | Value |
|---|---|
| Key | unique post ID (`p.ID`) |
| Ordering | `p.ID ASC` |
| Cursor | `p.ID > last_id`, advanced only from the last ID actually returned |
| Page size | `RECONCILIATION_PAGE_SIZE = 200` (class constant) |
| OFFSET | never used |
| Candidate filter | `_event_source = canonical source` (INNER join, as before) |
| Status filter | `_event_status` present and `NOT IN (rejected, source_not_found)` (INNER join — exactly what `meta_query NOT IN` produced before) |
| Post-status scope | `publish`, plus `private` for a reader allowed to read private posts — WP_Query's default scope for the previous implementation |
| Termination | a page shorter than the page size ends the walk; a full page is followed by one more probe |
| Abort conditions | SQL error (`$wpdb->last_error` set), non-array result, or a page whose IDs are not strictly ascending / do not advance the cursor |

All IDs are held in memory as integers only (no post objects); the set is bounded
by the number of source-owned events. No staging table was introduced, so no new
persistent store needed the Stage 0.5 isolation protections.

### Step 3 — bounded mutation after complete enumeration

Only after the full candidate set has been retrieved are absence-based changes
applied, in batches of `RECONCILIATION_BATCH_SIZE = 50`. Each candidate is
revalidated immediately before its write:

1. it is still an `event` post;
2. its `_event_source` still equals the canonical source;
3. its `_event_status` is not `rejected` or `source_not_found`.

An event whose stored `_event_url` is empty is still skipped (unchanged
behaviour), and an event whose URL is present in the run is left alone.

A failed, malformed or interrupted candidate page returns `null`, which aborts
the whole pass **before the first mutation** — page 1 is never applied while
page 2 is still being discovered.

## 3. Idempotence

An event already `source_not_found` is excluded by the candidate query, so
re-running reconciliation for the same source in the same state produces the
same statuses and zero new writes. A second pass over a fully-reconciled source
issues the same three candidate pages and returns `0` marked.

## 4. What did NOT change

- Identity rules (P11): an event is still matched to the run by `_event_url`.
- Source identity: `_event_source` (canonical source key) is unchanged.
- The `rejected` / `source_not_found` exclusions and the post-status scope.
- Behaviour for sources with fewer than 200 candidates (one page, one query).
- Event Runtime visibility rules and the meaning of `_event_status`.

## 5. Tests

New suite `wp-content/plugins/conexao-event-importer/tests/test-reconciliation-pagination.php`
(57 assertions) against a synthetic 401-event cohort (identical `_event_url` and
`_event_date` on every row, so only the ID can order them) plus a 5-event cohort:

| Acceptance item | Assertion |
|---|---|
| complete enumeration reconciles every source-owned event (>300) | 401 marked, including the highest ID |
| exact page boundaries | 3 candidate queries for 401 rows (200 + 200 + 1); each `LIMIT 200`; no `OFFSET` |
| cursor correctness across boundaries | page 2 cursor is the 200th ID; page 3 cursor is the 400th ID |
| non-unique sort values | every row shares the same URL/date; all 401 distinct IDs are returned once, ascending |
| no duplicates / no skips | `count(unique) === count(all)`, min/max present |
| incomplete enumeration → zero reconciliation | 0 marked, **0 candidate queries** (gate fires first) |
| checkpoint-resumed subset → zero reconciliation | 0 marked, **0 candidate queries** (safety gate fires first) |
| failure on a later candidate page | 0 marked, every status unchanged (page 1 never applied) |
| malformed (non-advancing) candidate page | 0 marked, every status unchanged |
| interruption before enumeration completes | the interruption propagates; 0 statuses changed; all 401 remain published |
| idempotence | second pass marks 0 and produces identical statuses |
| sources with <300 events unchanged | 5-event cohort: 5 marked in exactly one page |
| a translated record is never mutated | with Polylang active, only the import-language record is marked; the other-language record keeps its status |

The existing Cavan scenario in `test-source-pagination-completeness.php`
(complete walk reconciles, incomplete walk does not) still passes unchanged and
remains the engine-level control for the <300 case.
