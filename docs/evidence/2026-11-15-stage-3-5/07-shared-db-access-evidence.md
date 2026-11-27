# Shared-database access evidence — read-only, and no writes

## 1. Session enforcement check (before any data query)

```sql
SET SESSION TRANSACTION READ ONLY;
START TRANSACTION READ ONLY;
SELECT @@transaction_read_only AS ro, VERSION() AS v, CURRENT_USER() AS u;
```

Output:

```
ro      v       u
1       9.7.1   root@localhost
```

`@@transaction_read_only` returned **1**, so the read-only transaction was active
for every query in this task. Server: MySQL 9.7.1. Account: `root@localhost`
inside the Compose `db` container (the same administrative account Stage 3 used);
the WordPress application account was not used.

**The Stage 2 `ERROR 1792 (25006)` write probe was NOT repeated.** Its transcript
at `docs/evidence/2026-11-15-stage-2-verification/01b-readonly-probe.txt` proves
engine-level rejection of a write in this session type, and is referenced here
rather than re-run:

```
SET SESSION TRANSACTION READ ONLY;
START TRANSACTION READ ONLY;
SELECT @@transaction_read_only AS session_txn_ro_inside_txn;
UPDATE wordpress.wp_posts SET post_title = post_title WHERE 1 = 0;
-- session_txn_ro_inside_txn = 1
-- ERROR 1792 (25006) at line 4: Cannot execute statement in a READ ONLY transaction.
```

## 2. Every statement executed

Each batch began with `SET SESSION TRANSACTION READ ONLY;` +
`START TRANSACTION READ ONLY;`. Data statements only — `SELECT`; no `SHOW`
statement was needed, because schema discovery came from code plus `wp_options`.

| # | Statement | Purpose |
|---|---|---|
| 1 | `SELECT @@transaction_read_only AS ro, VERSION() AS v, CURRENT_USER() AS u` | session enforcement |
| 2 | `SELECT option_name, LENGTH(option_value), autoload FROM wp_options WHERE option_name IN ('cron','conexao_event_import_log','conexao_event_import_history','conexao_event_import_continuations','conexao_event_sources','conexao_event_source_health','active_plugins','timezone_string','gmt_offset') ORDER BY option_name` | storage discovery — sizes only, no values |
| 3 | `SELECT option_value FROM wp_options WHERE option_name='active_plugins'` | confirm the importer is active |
| 4 | `SELECT HEX(option_value) FROM wp_options WHERE option_name='cron'` | byte-exact cron capture |
| 5 | `SELECT HEX(option_value) FROM wp_options WHERE option_name='conexao_event_import_continuations'` | byte-exact continuation capture |
| 6 | `SELECT HEX(option_value) FROM wp_options WHERE option_name='conexao_event_import_history'` | byte-exact history capture |
| 7 | `SELECT HEX(option_value) FROM wp_options WHERE option_name='conexao_event_import_log'` | byte-exact log capture |
| 8 | `SELECT HEX(option_value) FROM wp_options WHERE option_name='conexao_event_sources'` | byte-exact registry capture |
| 9 | `SELECT HEX(option_value) FROM wp_options WHERE option_name='conexao_event_source_health'` | byte-exact health capture |
| 10 | `SELECT option_name, LENGTH(option_value) FROM wp_options WHERE option_name LIKE '%updraft%' OR option_name LIKE '%backup%'` | search for a point-in-time snapshot |
| 11 | `SELECT option_value FROM wp_options WHERE option_name='updraft_backup_history'` | confirm no DB backup exists |
| 12 | `SELECT option_value FROM wp_options WHERE option_name='updraft_last_scheduled_fd'` | same |
| 13 | `SELECT ID, post_status, post_type, post_title, post_date, post_modified, post_modified_gmt FROM wp_posts WHERE ID IN (<80 cohort IDs>) ORDER BY ID` | cohort post rows |
| 14 | `SELECT post_id, meta_key, meta_value FROM wp_postmeta WHERE post_id IN (<80 cohort IDs>) AND meta_key IN ('_event_source','_event_source_id','_event_url','_event_date','_event_end_date','_event_status','_event_imported','_event_start_time','_event_end_time') ORDER BY post_id, meta_key` | cohort meta |
| 15 | `SELECT post_id, meta_key, meta_value FROM wp_postmeta WHERE meta_key IN ('_event_date','_event_end_date') AND (meta_value LIKE '% %' OR meta_value LIKE '%T%') ORDER BY post_id, meta_key` | datetime violation class |
| 16 | `SELECT ID, post_title, post_status, post_type, post_modified FROM wp_posts WHERE ID IN (<20 violating IDs>) ORDER BY ID` | violation-class post rows |
| 17 | `SELECT post_id, meta_key, meta_value FROM wp_postmeta WHERE post_id IN (<20 violating IDs>) AND meta_key IN ('_event_source','_event_source_id','_event_status','_event_date','_event_end_date','_event_imported','_event_start_time','_event_end_time') ORDER BY post_id, meta_key` | violation-class meta |
| 18 | `SELECT DISTINCT meta_key FROM wp_postmeta WHERE post_id IN (13317,16652,137144) AND meta_key LIKE '%status%'` | prove no status-change audit meta exists |
| 19 | `SELECT meta_key, LEFT(meta_value,40) FROM wp_postmeta WHERE post_id=115352 ORDER BY meta_key` | post 115352 field inventory |
| 20 | `SELECT COUNT(*) …` for `_event_date` (3,406) and `_event_end_date` (3,134), plus distinct `_event_start_time` / `_event_end_time` samples | denominators for the class-vs-instance claim |

Shell commands used for capture and parsing, none of which touches the database
except to run the statements above:

```
docker compose exec -T db mysql … -e "<one of the statements above>"
tr -d '\n' | xxd -r -p          # decode HEX to bytes locally
php /tmp/stage35/*.php          # unserialise locally, filter in PHP
sha256sum                       # TSV digests
```

## 3. Capture-method note (why `HEX()`)

A plain `SELECT option_value` for the history store returned **52,861** bytes
against `LENGTH(option_value) = 52,980` — a 119-byte shortfall from a charset
conversion in the client, not from the data. Every store was therefore re-captured
through `HEX(option_value)` and decoded locally; decoded byte counts then matched
`LENGTH()` exactly (9,996 / 52,980 / 983,635 / 46,981 / 85,760 / 6,683). No
evidence file was produced from the lossy path.

## 4. Filters and parsing (kept outside SQL)

| Store | Parsing | Filtering | Output |
|---|---|---|---|
| `cron` | PHP `unserialize()` | `hook === 'conexao_event_import_continuation'`, then timestamp membership in the window | 22 rows (all matches) |
| continuations | PHP `unserialize()` | all records; window tie computed per record | 22 rows (all) |
| history | PHP `unserialize()` | `13:55:00 ≤ time ≤ 14:15:00` | 9 rows |
| log | PHP `unserialize()` | same window, plus keyword counts over the whole store | 239 rows |
| registry | PHP `unserialize()` | the nine source slugs | 9 rows |
| health | PHP `unserialize()` | the nine source slugs | 9 rows |

No raw serialised option value, and no unrelated hook argument, was written to any
committed file.

## 5. No-write confirmation

* **No `INSERT`, `UPDATE`, `DELETE`, `REPLACE`, `ALTER`, `CREATE`, `DROP`,
  `TRUNCATE`, `GRANT`, `REVOKE`, `LOCK`, `FLUSH`, `OPTIMIZE` or `ANALYZE`** was
  issued against the shared database.
* **No database account was created, dropped or altered**, and no privilege was
  granted or revoked. The session used the pre-existing `root@localhost`
  administrative account, read-only, and changed nothing about it.
* **No schema change.** No table, index, view, trigger or stored procedure was
  created, altered or dropped. The plugin's logical stores are `wp_options` rows;
  none was written.
* **No WordPress bootstrap** was performed against the shared database, and no
  hook, cron event, admin screen or REST route was invoked.
* **No retention or pruning run** was executed. The stores are exactly as the
  plugin left them; the observed shrink relative to Stage 3 is the plugin's own
  retention, not this task.
* Every statement above ran inside a session whose `@@transaction_read_only` was
  `1`, verified at the start and unchanged for the session.

