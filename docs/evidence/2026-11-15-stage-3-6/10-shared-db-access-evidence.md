# Shared-database access evidence — read-only, no writes

## 1. Session enforcement, before any data query

```sql
SET SESSION TRANSACTION READ ONLY;
START TRANSACTION READ ONLY;
SELECT @@transaction_read_only AS ro, VERSION() AS v, CURRENT_USER() AS u;
```

```
ro      v       u
1       9.7.1   root@localhost
```

`@@transaction_read_only` returned **1**. Server MySQL `9.7.1`, account
`root@localhost` inside the Compose `db` container — the same administrative
account Stage 3 and Stage 3.5 used. **The WordPress application account was not
used for any direct database query.**

**The Stage 2 `ERROR 1792 (25006)` write probe was NOT repeated.** Its transcript at
`docs/evidence/2026-11-15-stage-2-verification/01b-readonly-probe.txt` proves
engine-level rejection of a write in this session type and is referenced here
rather than re-run.

## 2. Re-verification before the final target comparison

```sql
SET SESSION TRANSACTION READ ONLY;
START TRANSACTION READ ONLY;
SELECT @@transaction_read_only AS ro, VERSION() AS v, CURRENT_USER() AS u;
SELECT meta_key, meta_value, COUNT(*) AS n
  FROM wordpress.wp_postmeta
 WHERE post_id IN (<the 76 target post IDs>)
   AND meta_key = '_event_status'
 GROUP BY meta_key, meta_value;
```

```
ro      v       u
1       9.7.1   root@localhost
meta_key        meta_value              n
_event_status   source_not_found        76
```

Read-only still **1**, and all 76 targets were still `source_not_found` at the end
— no drift occurred during the stage.

## 3. Every statement executed

Each batch began with `SET SESSION TRANSACTION READ ONLY;` +
`START TRANSACTION READ ONLY;`. Only `SELECT` was used; no `SHOW` statement was
needed.

| # | Statement | Authorised purpose (§2.1) |
|---|---|---|
| 1 | `SELECT @@transaction_read_only AS ro, VERSION() AS v, CURRENT_USER() AS u` | session enforcement |
| 2 | `SELECT HEX(option_value) AS hex FROM wordpress.wp_options WHERE option_name='conexao_event_sources'` | **(b)** source configuration / discovery URL |
| 3 | `SELECT @@transaction_read_only AS ro` + `SELECT post_id, meta_key, meta_value FROM wordpress.wp_postmeta WHERE post_id IN (<76 IDs>) AND meta_key IN ('_event_source','_event_source_id','_event_url','_event_status','_event_date','_event_end_date') ORDER BY post_id, meta_key` | **(a)** target inventory cross-check (456 rows = 76 × 6, 0 mismatches) |
| 4 | `SELECT @@transaction_read_only AS ro, VERSION() AS v, CURRENT_USER() AS u` + `SELECT meta_key, meta_value, COUNT(*) AS n FROM wordpress.wp_postmeta WHERE post_id IN (<76 IDs>) AND meta_key='_event_status' GROUP BY meta_key, meta_value` | **(a)** final re-verification before the comparison (76 × `source_not_found`) |

**No site-timezone query was made.** The comparison is on provider event IDs, not
dates, so the timezone was unnecessary (§2.1).

## 4. Capture method for the options row

A plain `SELECT option_value` was avoided: Stage 3.5 measured a 119-byte shortfall
from client charset conversion on a large serialised option. The sources option was
therefore captured through `HEX(option_value)` (170,988 hex characters → 85,494
decoded bytes) and decoded locally with `unserialize()` in a standalone PHP script
with no WordPress bootstrap. Decoded size matched the stored length; no evidence was
produced from the lossy path.

## 5. No-write confirmation

* **No `INSERT`, `UPDATE`, `DELETE`, `REPLACE`, `ALTER`, `CREATE`, `DROP`,
  `TRUNCATE`, `GRANT`, `REVOKE`, `LOCK`, `FLUSH`, `OPTIMIZE` or `ANALYZE`** was
  issued.
* **No `SET`** other than `SET SESSION TRANSACTION READ ONLY`, and **no transaction
  control** other than `START TRANSACTION READ ONLY` / implicit commit on
exit. No statement ran outside a read-only transaction.
* **No schema change**, no account change, no privilege change.
* **No WordPress bootstrap** against the shared database; no hook, cron event,
  admin screen or REST route was invoked.
* **No option was written**, including `conexao_event_sources`,
  `conexao_event_source_health`, the log/history stores and the continuation store.
* **No retention or pruning** was run. The stores are exactly as the plugin left
  them.
