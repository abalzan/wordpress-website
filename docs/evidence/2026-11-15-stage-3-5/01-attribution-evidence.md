# Task A — attribution evidence capture

**Capture timestamp:** `2026-11-15T19:22:20Z`
**Access:** `SET SESSION TRANSACTION READ ONLY; START TRANSACTION READ ONLY;`
— `SELECT @@transaction_read_only` returned **1** before every batch
(`07-shared-db-access-evidence.md`).
**Writes:** none. **Provider requests:** none. **Importer/continuation runs:** none.

---

## 1. Storage discovery — what the logical stores actually are

The prompt warned that names such as `conexao_event_sources`,
`conexao_event_import_continuations`, `conexao_event_import_history` and
`conexao_event_import_log` "may refer to logical option-backed stores rather than
SQL tables". They do. **There are no custom tables.** Each logical store is a
single row in `wp_options`, and the value is a PHP-serialised array that the
plugin reads with `get_option()`.

| Logical store | Physical storage | Declared by | Shape |
|---|---|---|---|
| Source registry | `wp_options` row, `option_name = 'conexao_event_sources'` | `class-event-sources.php:12` (`OPTION_KEY`) | `array<string source_id, array>` — 154 records |
| Continuation store | `wp_options`, `option_name = 'conexao_event_import_continuations'` | `class-conexao-import-continuation.php:98` | `array<string source_id, array>` — 22 records |
| Import history | `wp_options`, `option_name = 'conexao_event_import_history'` | `class-import-history.php:15` | `array<int, array>` — list of rows, 56 rows |
| Import log | `wp_options`, `option_name = 'conexao_event_import_log'` | `class-import-log.php:40` | `array<int, array>` — list of entries, 2,104 entries |
| Source health | `wp_options`, `option_name = 'conexao_event_source_health'` | `class-source-health.php:19` | `array<string source_id, array>` — 301 records |
| WP-Cron schedule | `wp_options`, `option_name = 'cron'` | WordPress core | `array<int timestamp, array<string hook, array>>` |

Verified sizes and autoload flags (read-only `SELECT`, no value fetched):

```sql
SELECT option_name, LENGTH(option_value) AS bytes, autoload
  FROM wp_options
 WHERE option_name IN ('cron','conexao_event_import_log','conexao_event_import_history',
                       'conexao_event_import_continuations','conexao_event_sources',
                       'conexao_event_source_health','active_plugins',
                       'timezone_string','gmt_offset')
 ORDER BY option_name;
```

```
conexao_event_import_continuations   9996    on
conexao_event_import_history        52980    off
conexao_event_import_log           983635    off
conexao_event_source_health         46981    off
conexao_event_sources               85760    off
cron                                 6683    on
active_plugins                       1189    on
gmt_offset                              0    yes
timezone_string                        13    yes     (Europe/Dublin)
```

No `conexao_event_importer_version`-style option exists, and no other persisted
store records the plugin version. Deployment identity therefore came from
file hashes, not from the database (`00-scope-and-baseline.md` §4).

### 1.1 Field shapes actually present (read from the data, not assumed)

| Store | Fields observed |
|---|---|
| Continuation record | `schema_version`, `source_id`, `state`, `run_at`, `scheduled_at`, `last_run_at`, `attempts`, `stalled`, `last_page`, `page_count`, `reason`, `run_id`, `source_run_id`, `parent_run_group_id`, `continuation_id`, `message` |
| History row | `time`, `source`, `found`, `new`, `updated`, `unchanged`, `duplicates`, `skipped`, `skipped_past`, `skipped_invalid_date`, `errors`, `status`, `message`, `source_skipped`, `source_skip_reason`, `rate_limit_state`, `run_id`, `source_run_id`, `parent_run_group_id`, `record_type`, `complete`, `incomplete_reason`, `checkpoint` |
| Log entry | `time`, `source`, `level`, `message`, `run_id`, `event_id`, `event_title`, `http_status`, `context` |
| Registry record | `name`, `url`, `type`, `status`, `last_import`, `last_import_status`, `events_imported`, `last_error`, `id`, `county`, `region_labels` |

**Explicitly NOT stored** (the prompt asked for this to be stated, not assumed):
`started_at`, `completed_at`, `created_at`, `updated_at` do **not** exist on a
continuation record. Continuation timing evidence is limited to
`scheduled_at`, `last_run_at` and `run_at`. In every one of the 22 records,
`run_id`, `source_run_id` and `continuation_id` are the **empty string**, even
though the keys exist — P12 added the keys, but the records were written before
any code populated them.

### 1.2 Extraction method for the serialised stores

The `mysql` client's `--raw` text transfer was **not** used, because it is lossy
across a charset conversion: a plain
`SELECT option_value` produced 52,861 bytes for the history option whose
`LENGTH()` is 52,980. A 119-byte shortfall cannot be reconciled with the stored
value, so the capture method was changed to the byte-exact route Stage 3 used:

```sql
SET SESSION TRANSACTION READ ONLY;
START TRANSACTION READ ONLY;
SELECT HEX(option_value) FROM wp_options WHERE option_name = '<option>';
```

The hex was decoded locally (`tr -d '\n' | xxd -r -p`) and parsed with PHP
8.5.4's native `unserialize()` outside the database. Decoded byte counts then
matched `LENGTH(option_value)` exactly for all six stores
(9,996 / 52,980 / 983,635 / 46,981 / 85,760 / 6,683).

**No serialised value was written to any TSV.** Filtering (hook names, time
windows, keywords) was done locally in PHP after unserialisation — never with
SQL `LIKE` against the serialised string.

---

## 2. The five evidence targets

### Target 1 — current WordPress cron option (snapshot only)

**Query**

```sql
SET SESSION TRANSACTION READ ONLY;
START TRANSACTION READ ONLY;
SELECT HEX(option_value) FROM wp_options WHERE option_name = 'cron';
```

`cron` is a serialised PHP array `array<timestamp, array<hook, array<key, array{schedule,args,interval}>>>`.
It was unserialised locally and filtered in PHP to
`hook === 'conexao_event_import_continuation'` (the only continuation hook;
`class-conexao-import-continuation.php:116`). The raw serialised value was never
exported, and unrelated hook arguments were never read into evidence.

**Result — 22 continuation entries present; `01a-cron-continuation-entries.tsv`.**
All 22 are one-off (`schedule` empty), each carrying exactly one argument (a
source slug). **Zero fall inside 13:55–14:15 on 2026-11-15.** The nearest are
`2026-11-15 08:15:30` (`eventbrite_limerick`) and `2026-11-15 15:01:21`
(`eventbrite_cork`).

**Status: `CURRENT SNAPSHOT — NO MATCHING ENTRY PRESENT`.**

Sixteen of the 22 entries are **overdue and unconsumed** at capture — fourteen
from 2026-11-14 (17:32:52 → 23:50:54), one from 2026-11-15 08:15:30, and
`eventbrite_cork` at 15:01:21. This is load-bearing evidence for §3, not a
curiosity: an entry WordPress cron had fired would have been deleted.

### Target 2 — source registry (current snapshot, not history)

**Query** — the registry is an option, so the row was fetched byte-exact and
unserialised locally:

```sql
SELECT HEX(option_value) FROM wp_options WHERE option_name = 'conexao_event_sources';
```

**Result — `01e-source-registry-snapshot.tsv` (9 rows), all labelled
`CURRENT REGISTRY SNAPSHOT` at `2026-11-15T19:22:20Z`:**

| Source | status | `last_import` | `last_import_status` | `events_imported` | `last_error` |
|---|---|---|---|---|---|
| `eventbrite_meath` | active | **2026-11-14 14:23:45** | success | 4 | *(empty)* |
| `eventbrite_laois` | active | **2026-11-14 14:17:00** | success | 1 | *(empty)* |
| `eventbrite_mayo` | active | **2026-11-14 14:23:24** | **error** | 0 | `Failed to fetch events from Eventbrite — Mayo. HTTP status: 429.` |
| `eventbrite_kerry` | active | **2026-11-14 14:11:46** | success | 2 | *(empty)* |
| `eventbrite_westmeath` | active | **2026-11-14 14:31:21** | success | 7 | *(empty)* |
| `eventbrite_clare` | active | **2026-11-14 14:02:10** | success | 5 | *(empty)* |
| `eventbrite_cork` | active | **2026-11-15 14:01:21** | success | 0 | *(empty)* |
| `eventbrite_leitrim` | active | **2026-11-14 14:17:10** | success | 1 | *(empty)* |
| `eventbrite_offaly` | active | **2026-11-14 14:24:51** | success | 2 | *(empty)* |

`conexao_event_source_health` corroborates the same timestamps
(`last_success 2026-11-14 14:0x–14:3x` for eight of the nine; Mayo
`last_failure 2026-11-14 14:23:24`, `consecutive_failures 1`).

**This is the most consequential row set in the task.** Eight of the nine
affected sources have a `last_import` of **2026-11-14** — the *previous day*,
not the 2026-11-15 14:01–14:10 window. Only `eventbrite_cork` has a
`last_import` inside the window.

**Why a current value cannot be presented as the window's value:** a later run
overwrites `last_import`. For eight sources the stored value is older than the
window, so it cannot be the window's status at all — those are labelled
`HISTORICAL RUN STATUS NOT RETAINED`. For `cork`, `2026-11-15 14:01:21`
coincides to the second with retained history row `#14` of `01c`, so that single
value is `HISTORICAL VALUE — RUN-LINKED`.

### Target 3 — continuation store

**Query** — option-backed, so:

```sql
SELECT HEX(option_value) FROM wp_options WHERE option_name = 'conexao_event_import_continuations';
```

**Result — `01b-continuation-store-records.tsv` (all 22 records, every stored
field, `NOT STORED` markers where a field does not exist).**

| Metric | Value |
|---|---|
| Total records | 22 |
| `state = running` with `run_at = 0` | **16** |
| `state = scheduled` with `run_at > 0` | 6 |
| Records with a non-empty `run_id` | 6 (all of them `scheduled`) |
| Records with `scheduled_at` or `last_run_at` in 13:55–14:15 on 2026-11-15 | **1** — `eventbrite_cork` (`scheduled_at` = `2026-11-15 14:01:21`) |

Only **one** of the nine affected sources has any continuation record at all:
`eventbrite_cork`. The other eight (`meath`, `laois`, `mayo`, `kerry`,
`westmeath`, `clare`, `leitrim`, `offaly`) have **no continuation record**. That
is decisive for Task C: the "nine continuations" and the "nine affected
sources" are **not the same nine**.

**Retention caveat, stated rather than glossed:** a completed continuation is
cleared (`Continuation cleared: this source is no longer resumable…` — 4 such
entries in the retained log). An absent record therefore does **not** prove that
no continuation ever existed for a source; it proves only that none is retained
now. `01b` cannot be read as a complete historical inventory.

### Target 4 — import history store

**Query**

```sql
SELECT HEX(option_value) FROM wp_options WHERE option_name = 'conexao_event_import_history';
```

**Result — `01c-history-store-window.tsv` (the 9 rows inside 13:55–14:15).**

| # | Time (UTC) | Source | Status | found | new | upd | unch | err |
|---|---|---|---|---|---:|---:|---:|---:|---:|
| 1 | 14:01:21 | `eventbrite_cork` | warning | 50 | 0 | 2 | 48 | 0 |
| 2 | 14:02:08 | `eventbrite_louth` | warning | 19 | 0 | 0 | 19 | 0 |
| 3 | 14:02:50 | `eventbrite_monaghan` | warning | 5 | 0 | 0 | 5 | 0 |
| 4 | 14:04:51 | `eventbrite_roscommon` | rate_limited | 0 | 0 | 0 | 0 | 0 |
| 5 | 14:06:52 | `eventbrite_waterford` | rate_limited | 0 | 0 | 0 | 0 | 0 |
| 6 | 14:07:14 | `eventbrite_carlow_events_today` | warning | 0 | 0 | 0 | 0 | 0 |
| 7 | 14:09:44 | `eventbrite_dublin_events_this_week` | warning | 221 | 54 | 2 | 163 | 0 |
| 8 | 14:10:31 | `eventbrite_longford_events_this_week` | warning | 2 | 0 | 0 | 2 | 0 |
| 9 | 14:10:57 | `eventbrite_wicklow_events_today` | warning | 5 | 2 | 0 | 3 | 0 |

**Only `eventbrite_cork` is one of the nine affected sources.** The other eight
sources in this window (`louth`, `monaghan`, `roscommon`, `waterford`,
`carlow_events_today`, `dublin_events_this_week`, `longford_events_this_week`,
`wicklow_events_today`) hold **none** of the 80 cohort events.

Row-structure check: all nine are `record_type = child` with
`parent_run_group_id == run_id` (self-referencing) and **no parent row exists** —
the retained history holds 15 `child` rows and **0 `parent` rows**. Stage 3's
"nine standalone automatically executed source runs, not a Run All" reading is
therefore independently corroborated: a Run All child carries the *parent's*
group id, never its own run id.

### Target 5 — import log store

**Query**

```sql
SELECT HEX(option_value) FROM wp_options WHERE option_name = 'conexao_event_import_log';
```

**Result — `01d-log-store-window.tsv`: 239 entries between 13:55 and 14:15.**

| Metric | Value |
|---|---|
| Entries in window | 239 (identical to Stage 3's count) |
| Distinct sources in window | 9 — the same nine as Target 4 |
| `Continuation starting for …` in window | **9** |
| `Continuation cleared …` in window | 2 |
| Entries containing `shutdown` | **0** — in the entire 2,104-entry log |
| Entries recording a *positive* `source_not_found` marking | **0** |
| Entries stating events were **not** marked `source_not_found` | 51 in the whole log |

Keyword coverage across the whole retained log (not only the window):
`Continuation starting` 91, `Continuation scheduled` 80, `Continuation cleared`
4, `re-armed` 1, `shutdown` **0**.

**The decisive entries.** Every window run carries a retained warning that its
absence-based reconciliation was refused:

* `eventbrite_cork` 14:01:21 — *"The source listing was only partially enumerated. Events absent from this walk were left untouched (not marked source_not_found)…"*
* `eventbrite_louth` 14:02:08 — same message
* `eventbrite_monaghan` 14:02:50 — same message
* `eventbrite_carlow_events_today` 14:07:14 — *"…resumed from its enumeration checkpoint. Absence-based reconciliation was skipped…"*, plus the partial-enumeration warning
* `eventbrite_wicklow_events_today` 14:10:57 — both messages, then *"Continuation cleared"*
* `eventbrite_dublin_events_this_week` 14:09:44 / `eventbrite_longford_events_this_week` 14:10:31 — partial-enumeration warnings
* `eventbrite_roscommon` 14:04:51 and `eventbrite_waterford` 14:06:52 — `rate_limited`, `found = 0`, `HTTP status: 429`

No entry anywhere in the retained log states that an event **was** marked
`source_not_found`. Every occurrence of the token is a negation.

---

## 3. Historical cron state — what could and could not be established

The current cron snapshot **cannot** establish what was scheduled at 14:00.
WordPress keeps no cron audit, and a fired single event is deleted. The prompt
required a contemporaneous snapshot or a persistent cron audit record before
absence could be claimed. The search for one:

| Candidate source | Result |
|---|---|
| A second cron option or a cron audit row | none — `wp_options` holds one `cron` row and no audit option |
| A read-only point-in-time database copy in the repository | none — `find . -name '*.sql' -o -name '*.sql.gz'` finds only `stage7-work/terms-backup-before-repair.sql`, a terms-only repair artefact |
| A Docker volume that is a snapshot rather than the live data directory | none — `wordpress-website_db_data` is the live `db` volume |
| UpdraftPlus database backups | **`updraft_backup_history` holds exactly one entry, `backup_2026-08-27-1953`, and it has NO `db` component** — plugins, themes, uploads, mu-plugins, others only. No database backup exists |

> **`HISTORICAL CRON STATE UNAVAILABLE`.** There is no contemporaneous
> cron-option snapshot, no point-in-time database copy from the window, and no
> persistent cron audit trail. A present-day absence of a matching entry is
> therefore **not** evidence that no matching entry existed at 14:00.

### 3.1 What the current cron state *can* prove about the mechanism

One inference survives, and it is mechanical rather than circumstantial:

* `eventbrite_limerick`'s continuation event is scheduled for **2026-11-15
  08:15:30** and is **still present and unconsumed** at 19:22.
* WordPress cron fires every due hook on a cron run and **deletes** each single
  event it fires.
* Limerick's continuation record is `running` / `run_at = 0`, and
  `rearm_missing()` only re-arms records whose state is `scheduled` with
  `run_at > 0`. The 08:15:30 event therefore **cannot have been recreated**
  after being fired.

If WordPress cron had executed at any point between 08:15:30 and 19:22 on
2026-11-15 — a span that includes the 13:55–14:15 window — the limerick event
would have been consumed and gone. It is still there. **WordPress cron did not
fire during the window.** The same reasoning rules out `wp-cron.php` over HTTP
and a `wp cron event run` CLI invocation: both consume due events.

That establishes "not WordPress cron". It does **not** by itself establish which
non-cron trigger ran the continuations — see §4.2.

---

## 4. Attribution of the 14:01–14:10 executions

### 4.1 What was observed (independently of Stage 3)

| Observation | Evidence |
|---|---|
| Nine continuation executions, 14:00:28 → 14:10:57 | 9 × `Continuation starting for <source>` in `01d` |
| Nine standalone history rows, each with its own self-referencing group, no parent | `01c`, plus 0 parent rows in the store |
| The runs walked in sequence with paired start/finish lifecycle entries | `01d`: `cork` starting 14:00:28 → scheduled 14:01:21 → `louth` starting 14:01:21 |
| Every run's absence-based reconciliation was refused | the `01d` warnings quoted in §2 Target 5 |
| Two of the nine fetched nothing at all (429) | `01c` rows 4 and 5 |

### 4.2 Attribution classification

| Candidate classification | Available evidence | Verdict |
|---|---|---|
| `CONFIRMED SHUTDOWN-TRIGGERED` | Requires a direct indicator — a shutdown log entry, an unambiguous fail-safe hook name, or equivalent. The log contains **0** occurrences of `shutdown`; `Continuation starting … No browser is involved.` names neither trigger. | **Not available** |
| `CONSISTENT WITH SHUTDOWN-TRIGGERED EXECUTION; SPECIFIC TRIGGER UNVERIFIED` | Requires independent evidence that no matching scheduled cron entry was in effect for the window, plus lifecycle evidence consistent with shutdown execution — with no direct shutdown indicator. §3.1 supplies the first; §4.1 the second. | **Applicable** |
| `ALTERNATE TRIGGER EVIDENCE PRESENT` | Code inspection finds exactly two callers of `run_due()`: the WP-Cron hook and the `shutdown` action (`conexao-event-importer.php` lines 328–342). No admin-AJAX, REST, WP-CLI or test path exists, and nothing in the retained evidence points elsewhere. | Not applicable |
| `TRIGGER UNVERIFIABLE` | The historical-cron-state gap is narrowed by §3.1 for the "was it WP-Cron?" question only. | Superseded, limitation stated |

> **Classification: `CONSISTENT WITH SHUTDOWN-TRIGGERED EXECUTION; SPECIFIC
> TRIGGER UNVERIFIED`.**
>
> Basis: (i) WordPress cron demonstrably did not fire in the window (§3.1);
> (ii) the only other registered caller of `run_due()` is the `shutdown` action;
> (iii) the retained lifecycle entries are consistent with `run_due()` walking
> the continuation map. This does **not** prove the fail-safe fired: no direct
> `shutdown` indicator exists and the historical cron option was never observed
> contemporaneously.

### 4.3 What the trigger is *not*

The retained row structure rules out an operator **Run All**: a Run All writes one
parent row plus children that share one group id, and the store contains no
parent rows and no shared group ids. Stage 1's P6 model makes that structural.

A **test leak** is also inconsistent with the evidence: the nine runs made real
outbound requests (request-accounting entries in `01d`, e.g. "6 outbound requests
sent, 6 of the 400 request budget used"), which a `pre_http_request` double would
not produce.

---

## 5. Observed retention state (measured, not inferred)

| Store | Observed at capture | Span | Code bound at HEAD |
|---|---|---|---|
| Import log | **2,104 entries** | 2026-11-14 14:43:00 → 2026-11-15 18:41:12 (~28 h) | `MAX_ENTRIES = 8000` (P13); pre-P13 was 2,000 |
| Import history | **56 rows** | 2026-11-14 19:29:49 → 2026-11-15 18:41:12 (23 h 11 m) | `MAX_ENTRIES = 1200`, `MAX_GROUPS = 50` (P13); pre-P13 was 100 |
| Continuations | 22 records | — | `MAX_SOURCES = 200` |
| Source health | 301 records | — | unbounded in code |

Two honest statements, and one that would be overreach:

1. A 2,000-entry store cannot contain 2,104 entries ⇒ **the 2,000-entry bound
   Stage 3 reported was not in force at 19:22.** That is arithmetic, not
   inference.
2. History fell from 100 rows (Stage 3) to 56 rows. Whole-group eviction can
   shrink a store well below its bound, so 56 rows is *consistent with* P13
   group-aware eviction but does not prove it.
3. **Which cap is deployed cannot be proven from the counts**, because both the
   old and the new bounds sit above the observed counts. No claim is made that
   8,000 / 1,200 / 50 are the running limits; they are the branch's declared
   limits, and the observed store is below them either way.

### 5.1 Retention gaps that directly bound this investigation

| Gap | Consequence |
|---|---|
| Log starts 2026-11-14 **14:43:00** | The eight sources whose `last_import` is 2026-11-14 **14:02–14:31** ran *before the oldest retained log entry*. Their runs are **evicted**, not absent-by-omission. |
| History starts 2026-11-14 **19:29:49** | Even later — every history row for the 2026-11-14 afternoon is evicted. |
| Continuations are cleared on completion | An absent continuation record proves "not retained", not "never existed". |
| No per-event status-change audit anywhere in the schema | See `03-connected-failure-analysis.md` §1. |

**This is an incomplete set of retained records and is not presented as a
complete historical capture.**

---

## 6. Is Stage 3's attribution independently supported?

| Stage 3 claim | Stage 3.5 verdict |
|---|---|
| The 14:00–14:10 activity was nine automatically executed continuation runs, not a Run All | **SUPPORTED** — 9 `Continuation starting` entries, 9 child rows with self-referencing groups, 0 parent rows |
| `wp_options.cron` holds no continuation event in 13:55–14:15 | **SUPPORTED as a current snapshot** (0 matching entries at 19:22); explicitly **not** as a historical statement |
| The `shutdown` fail-safe is "the more likely of the two server-side triggers" | **PARTIALLY SUPPORTED** — WP-Cron is ruled out (§3.1) and the fail-safe is the only remaining registered caller, but no direct shutdown indicator exists ⇒ `SPECIFIC TRIGGER UNVERIFIED` |
| §8.1: the 14:00–14:10 activity explains the suppressed events of the nine affected sources | **NOT SUPPORTED** — only 1 of the 9 window sources (`cork`) is an affected source, and the other eight affected sources last ran on **2026-11-14**, a day earlier. See `03-connected-failure-analysis.md` §1 |
| Stage 2's "externally triggered Run All" phrasing was wrong | **SUPPORTED** (row structure); Stage 3's correction stands |

---

## 7. Evidence at risk of aging out

The next retention cycle can remove:

* the nine 14:01–14:21 history rows and the 239 window log entries — they are the
  oldest rows in stores that are actively being written (new rows at 18:39–18:41,
  41 minutes before capture);
* the 16 overdue cron entries, if WordPress cron ever fires;
* `eventbrite_cork`'s continuation record, if it is ever reconciled or cleared.

That is why the capture was taken first and interpreted second, in that order.

---

## 8. SHA-256 of every exported TSV

```
dbbfa6d965f8405207ed8abe8b4a0e652783b9be39e9e55c25d5722f14776ea6  01a-cron-continuation-entries.tsv
8a318d3feab13251bd04212212c2ce8cc4792b4e8158f789258a724c9f94a7ce  01b-continuation-store-records.tsv
9d6ba168b959fcd3b93a782fd789dd15c5c304385b552b4f2f93ba3a127cd130  01c-history-store-window.tsv
58eb4aad010c0f8d898372673957a0d9bceedffe9de3bd8f15e6219625d10f93  01d-log-store-window.tsv
6e58c019e39713c5bc941783dce4a1b24244d9aebf47dda092c71084bd7dbf4f  01e-source-registry-snapshot.tsv
7683a5c8998927214eeeb7dc67dd337aa81faa903e26b54266b750ce42e0a364  02-suppressed-event-inventory.tsv
0002f7873683907a2431e73f83361f6e406037bec00923d176fc8a03fa203403  04-datetime-violations.tsv
```

Every TSV is tab-separated with one header row; embedded tabs and newlines in
free-text fields were normalised to spaces so the column count is stable. The
full query text for every capture is in `07-shared-db-access-evidence.md`.

---

## 9. Git-state reconciliation for the Stage 3 report

Stage 3's report §12 ("Git state at the end") states: *"HEAD unchanged at
`2704169`; nothing staged, committed, pushed, merged, deployed or activated…
Untracked: the new suite, the new report and `docs/evidence/2026-11-15-stage-3/`."*

That statement is **accurate at the time it was written**, and the state changed
afterwards. The evidence is timestamps, not opinion:

| Event | Timestamp (UTC) |
|---|---|
| Stage 3 baseline HEAD (`2704169`) | 2026-11-15 17:42:29 |
| Last Stage 3 file write (`08-quality-gates.txt`) | 2026-11-15 **18:26:54** |
| Stage 3 report last modified | 2026-11-15 **18:25:38** |
| Commit `77f09a3` authored **and** committed | 2026-11-15 **18:28:35** |
| Stage 3.5 start (clean worktree at `77f09a3`) | 2026-11-15 19:21 |

The commit is **1 minute 41 seconds after the last Stage 3 file was written**
and contains exactly Stage 3's artefacts:

```
$ git show --stat --oneline 77f09a3
… docs/evidence/2026-11-15-stage-3/ (12 files)
… docs/reports/2026-11-15-stage-3-event-importer-diagnostics-retention.md
… docs/evidence/README.md            | 2 +
… docs/plugins/conexao-event-importer.md | 121 +
… wp-content/plugins/conexao-event-importer/… (7 PHP/CSS files, 4 tests)
… tests/test-stage3-diagnostics-and-retention.php | 972 +
```

**Conclusion: "accurate when written; state changed after" — not "wrong when
written".** At the end of the Stage 3 session the work genuinely was
uncommitted; the commit and push happened between sessions. Stage 3's findings
and verdict are unaffected. The note appended to the Stage 3 report records this
without rewriting the original observation.




