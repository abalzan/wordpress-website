# Read-only shared-database access, and the Stage 2 carry-forward checks

## 1. Engine-enforced read-only — referenced, not repeated

The Stage 2 post-implementation verification established that this environment
has a verified, engine-enforced read-only transaction path against the shared
database. Its transcript is committed at
`docs/evidence/2026-11-15-stage-2-verification/01b-readonly-probe.txt`:

```
SET SESSION TRANSACTION READ ONLY;
START TRANSACTION READ ONLY;
SELECT @@transaction_read_only AS session_txn_ro_inside_txn;
UPDATE wordpress.wp_posts SET post_title = post_title WHERE 1 = 0;
SELECT 'PROBE_UNEXPECTEDLY_REACHED_AFTER_UPDATE' AS marker;
ROLLBACK;
-- session_txn_ro_inside_txn = 1
-- ERROR 1792 (25006) at line 4: Cannot execute statement in a READ ONLY transaction.
```

That transcript establishes (a) the account and server, (b) `session_txn_ro = 1`
inside the transaction, and (c) engine-level rejection of a write at statement
validation. **The write probe was NOT repeated in Stage 3.**

## 2. This session's own enforcement check (a safe SELECT, not a write probe)

Run before any Stage 3 data query:

```sql
SET SESSION TRANSACTION READ ONLY;
START TRANSACTION READ ONLY;
SELECT @@transaction_read_only AS session_txn_ro;
SELECT VERSION() AS version, CURRENT_USER() AS who;
```

Output:

```
session_txn_ro
1
version	who
9.7.1	root@localhost
```

`@@transaction_read_only` returned **1**, so this session's read-only transaction
was active for every query below. Account: `root@localhost` (the local Compose
administrative account). The WordPress application account (`wordpress`, which
holds ALL PRIVILEGES) was **not** used.

## 3. Exact query text used against the shared database

Every batch began with the two `SET SESSION` / `START TRANSACTION READ ONLY`
statements. The data statements were:

```sql
-- site timezone
SELECT option_name, option_value FROM wordpress.wp_options
 WHERE option_name IN ('timezone_string','gmt_offset');

-- captured post rows (99->106 IDs from the Stage 2 capture)
SELECT ID AS post_id, post_status, post_type, post_modified_gmt
  FROM wordpress.wp_posts WHERE ID IN (<the 106 captured post IDs>) ORDER BY ID;

-- captured meta
SELECT post_id, meta_key, meta_value FROM wordpress.wp_postmeta
 WHERE post_id IN (<the same 106 IDs>)
   AND meta_key IN ('_event_status','_event_date','_event_end_date','_event_source','_event_source_id')
 ORDER BY post_id, meta_key;

-- option sizes / presence
SELECT option_name, LENGTH(option_value) AS bytes, autoload FROM wordpress.wp_options
 WHERE option_name IN ('cron','conexao_event_import_log','conexao_event_import_history',
                       'conexao_event_import_continuations','conexao_event_source_health');

-- value extraction (exact bytes, so the serialized option could be parsed locally)
SELECT HEX(option_value) FROM wordpress.wp_options WHERE option_name = '<option>';
```

Query text for the two carry-forward checks is in §5 and §6 below. No `INSERT`,
`UPDATE`, `DELETE`, `ALTER`, `CREATE`, `DROP`, `TRUNCATE`, `GRANT` or `REVOKE`
was executed against the shared database, and no database account or privilege
was created, altered or inspected for modification.

## 4. Shared-database state observed

| Item | Value |
|---|---|
| Server | MySQL 9.7.1 (MySQL Community Server - GPL), `db:3306` |
| Account used | `root@localhost` inside the Compose `db` container |
| `wp_options.timezone_string` | `Europe/Dublin` |
| `wp_options.gmt_offset` | present, empty |
| `conexao_event_import_log` | 935,096 bytes, autoload `off`, **2,000 entries** (oldest `2026-11-14 14:43:00`, newest `2026-11-15 14:10:57`) |
| `conexao_event_import_history` | 92,686 bytes, autoload `off`, **100 rows** (oldest `2026-11-14 17:12:55`, newest `2026-11-15 14:10:57`) |
| `conexao_event_import_continuations` | 8,823 bytes, autoload `on`, **22 records** |
| `conexao_event_source_health` | 45,208 bytes, autoload `off` |
| `cron` | 6,683 bytes, autoload `on`, 38 scheduled entries |

Measured sizes are the basis for the Stage 3 storage bounds: **468 bytes per log
entry**, **927 bytes per history row**, **11 log entries per logical run** on
average with a maximum of 81 for a single run.

## 5. Carry-forward check A — attribution of the 14:01–14:10 activity

### 5.1 `wp_options.cron` in the 13:55–14:15 window

The `cron` option was extracted read-only and every scheduled event was listed.
**There is no `conexao_event_import_continuation` event scheduled anywhere inside
13:55–14:15 on 2026-11-15.** The nearest entries are `2026-11-15 08:15:30`
(`eventbrite_limerick`) and `2026-11-15 14:32:08` (`eventbrite_louth`).

The 14:32–15:01 entries are scheduled continuations that the 14:00–14:10 runs
**created**: `louth` 14:32:08, `monaghan` 14:32:50, `roscommon` 14:34:51,
`dublin_events_this_week` 14:39:44, `longford_events_this_week` 14:40:31,
`cork` 15:01:21, `waterford` 16:06:52. All of those timestamps are already in the
past at capture time and are **still present in the cron array**, which means
they were never executed (WordPress retains overdue events until they run).

### 5.2 Import History rows inside 14:00–14:11

Nine child rows:

```
2026-11-15 14:10:57 | eventbrite_wicklow_events_today        | warning      | child | grp=source-ce1a8ed6-… | src_run=source-733c632f-…
2026-11-15 14:10:31 | eventbrite_longford_events_this_week   | warning      | child | grp=source-2686f6fb-… | src_run=source-bc249431-…
2026-11-15 14:09:44 | eventbrite_dublin_events_this_week     | warning      | child | grp=source-bf0a7781-… | src_run=source-ecd3fb49-…
2026-11-15 14:07:14 | eventbrite_carlow_events_today         | warning      | child | grp=source-3043eb05-… | src_run=source-90c83842-…
2026-11-15 14:06:52 | eventbrite_waterford                   | rate_limited | child | grp=source-fe775fff-… | src_run=source-43436026-…
2026-11-15 14:04:51 | eventbrite_roscommon                   | rate_limited | child | grp=source-6781cf7d-… | src_run=source-955379c4-…
2026-11-15 14:02:50 | eventbrite_monaghan                    | warning      | child | grp=source-6d8dda64-… | src_run=source-86d63f62-…
2026-11-15 14:02:08 | eventbrite_louth                       | warning      | child | grp=source-c83908d9-… | src_run=source-530b5c52-…
2026-11-15 14:01:21 | eventbrite_cork                        | warning      | child | grp=source-e5ace6c3-… | src_run=source-9e6d775c-…
```

**Each row has a DIFFERENT parent group, and there is no parent row for any of
them.** A Run All writes one parent row plus children that all share one group
(Stage 1's P6 model). Nine rows with nine distinct self-referencing groups is
the signature of **nine standalone, automatically executed source runs**.

### 5.3 Importer log entries in the same window

The log holds 239 entries between 13:55 and 14:15, and they contain the
continuation lifecycle messages **in pairs**:

```
14:00:28 | eventbrite_cork                 | Continuation starting for eventbrite_cork: resuming the enumeration from page 38 of 49 …
14:01:21 | eventbrite_cork                 | Continuation scheduled for eventbrite_cork: resumable at page 38 of 49 (time_budget) …
14:01:21 | eventbrite_louth                | Continuation starting for eventbrite_louth: resuming the enumeration from page 15 of 49 …
14:02:08 | eventbrite_louth                | Continuation scheduled for eventbrite_louth: resumable at page 24 of 49 (rate_limited_deferred) …
… (one pair per source, up to 14:10:57)
```

### 5.4 Attribution — ATTRIBUTED (not “unattributed”)

**The 14:00–14:10 activity was a chain of automatic continuation executions of
the `conexao_event_import_continuation` path — not an operator Run All and not a
`Run All` at all.**

Evidence chain:

1. the log shows `Continuation starting for <source>` immediately before each
   run and `Continuation scheduled for <next source>` immediately after it, i.e.
   `run_due()` walking the continuation map;
2. the history rows are standalone child runs with distinct self-referencing
   groups and no parent row, which a Run All cannot produce;
3. `wp_options.cron` holds no continuation event inside the window, so the
   `shutdown` fail-safe (`run_due()`) is the more likely of the two server-side
   triggers — it fires from an ordinary request, which leaves no cron entry;
4. the continuations the runs created are still pending in `cron`, so the app's
   own scheduler is the thing that produced them.

**Consequence for the Stage 2 report:** its §15.3 phrase “an externally triggered
Run All import ran 14:01–14:10:57 UTC” is not supported by the row-level
structure. The correct description is “nine automatically executed continuation
runs”. The time and code-version bound Stage 2 recorded remain correct; only the
label changes. **The Stage 2 verdict is not edited by Stage 3.**

## 6. Carry-forward check B — the 13 unexplained new events

Stage 2's row-level diff contains 74 `NEW` rows: 56 in 14:xx, 5 in 07:xx, and
**13 at 2026-11-14 21:xx**. The 2026-11-14 inventory was captured at
**21:34 UTC** (`01-readonly-transaction-and-preservation.md:180`,
`01c-cohort-fingerprints.txt:137`).

Counted by hour:

```
13  2026-11-14 21
 5  2026-11-15 07
56  2026-11-15 14
```

The 13 rows are exactly the events created **at or after the 21:34 snapshot**:
`170981` (21:34:29), `170982`–`170988` (21:42:39–21:42:45), and
`170990`–`171001` (21:49:09–21:49:19). They are **timestamp-adjacent to the
2026-11-14 inventory capture** — they postdate it, so they could not be in it.

**Status: RESOLVED — the 13 are import writes that occurred after the 2026-11-14
21:34 capture, and 5 + 56 + 13 = 74 accounts for every new row.** No gap remains.
