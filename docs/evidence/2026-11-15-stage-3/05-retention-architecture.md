# P13 retention architecture and rationale

## 1. What was inspected first

| Concern | Where |
|---|---|
| Log store, cap, pruning | `includes/class-import-log.php` (`OPTION_KEY`, `MAX_ENTRIES`, `persist_entries()`, `prune_older_than_90_days()`) |
| History store, cap | `includes/class-import-history.php` (`OPTION_KEY`, `MAX_ENTRIES`, `record()`, `record_parent()`) |
| Pruning callers | `grep -rn 'prune_older_than_90_days'` → **no caller anywhere** |
| Group identity | Stage 1's `run_id` / `source_run_id` / `parent_run_group_id` on history rows and log entries; `record_type` = `parent`/`child` |
| Operator-store isolation | `tests/lib/option-isolation.php` (four protected stores, per-process suffix, fail-closed bootstrap) |

Measured with the read-only shared-database path:

* 2,000 log entries spanning 23 hours; **468 bytes per entry**; 11 entries per
  logical run on average, **81 maximum** for one run;
* 100 history rows; **927 bytes per row**;
* **154** event sources registered → one Run All is 1 parent + up to 154 child
  rows, and roughly 1,700–3,900 log entries.

## 2. The two defects this replaces

1. `array_slice( $log, 0, 2000 )` dropped the OLDEST entries regardless of which
   run they belonged to, so a Run All could lose its own beginning while later
   sources were still executing.
2. `array_slice( $history, 0, 100 )` could not even hold one Run All (155 rows),
   so the parent row and the first sources were evicted mid-run.

Raising the two numbers would only postpone both. **Neither number alone is the
fix.**

## 3. The chosen architecture — per-store group-aware retention, no new store

**No custom table, no new option, no transient, no migration.** The retention
policy is implemented as a pure function over the existing arrays, applied at
every existing write.

Reasons this is the smallest safe architecture:

* The two stores already exist and are already protected by the Stage 0.5
  isolation (accessor + per-process suffix + fail-closed bootstrap). A new store
  would have had to be added to that registry and to the verification gate; a
  schema change would have needed disposable-database DDL privileges that Stage 3
  is not authorised to assume it has.
* The identity needed already exists (Stage 1's run group). Nothing had to be
  invented.
* Capacity pressure is derivable from the stored contents, so there is no cached
  counter to drift and no second source of truth.

### 3.1 Storage bounds (documented and enforced)

| Store | Bound | Documented value | Worst-case bytes at the measured rate |
|---|---|---|---|
| Log | `MAX_ENTRIES` **entries** | **8,000** | ≈3.7 MB in one non-autoloaded option |
| History | `MAX_ENTRIES` **rows** | **1,200** | ≈1.1 MB in one non-autoloaded option |
| History | `MAX_GROUPS` completed logical runs | **50** | binds first only for small runs |

Why 8,000 / 1,200 / 50:

* one Run All ≈ 1,700–3,900 log entries at the measured density → 8,000 holds
  **more than two** complete Run Alls;
* one Run All ≈ 155 history rows → 1,200 holds **about seven** complete Run Alls;
* 50 completed groups keeps **50 standalone source runs** in full, the same order
  of magnitude as the old 100-row cap, so no operator loses history they had.

### 3.2 What counts toward the bound

Every retained entry / row, regardless of age. Age is **not** an eviction trigger
(see §5).

### 3.3 Eviction eligibility

A logical group is evictable only when it is **terminal**:

* no row in the group has status `queued` or `running`
  (`Conexao_Import_History::is_group_terminal()`), and
* the group has no `scheduled` or `running` continuation
  (`Conexao_Import_Continuation::get_active_group_ids()`).

This is exactly Stage 1's model: a Run All writes its parent with status
`running` before the first source starts, and only the final `record_parent()`
makes it terminal.

### 3.4 Order, and the unit

* Oldest group first: by the group's oldest parseable timestamp, then by last
  activity, then — **critically** — by store position. The store is newest-first,
  so a larger index is older. Without that tie-break, a Run All (whose entries all
  share a second) would sort arbitrarily and could evict the newest group.
* **A group is never split.** The parent and every child row of a run are removed
  together, and a group's first log entry and last log entry are removed
  together. This is asserted (H2, I2).
* The **newest** completed group is never evicted to make room for itself: a
  single run larger than the bound stays readable, and its overage is reported as
  pressure.

### 3.5 Legacy records

Entries/rows with no run identity form an explicit `legacy` bucket. They cannot
be rebuilt into a run, so they are evicted **individually, oldest first, and only
after every eligible whole group has gone**. They are never assigned an invented
ID (G1–G3).

### 3.6 Capacity pressure

If the protected groups alone exceed the bound, **nothing protected is discarded**.
`Conexao_Import_Log::get_retention_state()` / `...History::get_retention_state()`
return `pressure => true` and a numeric `over_by`, and the Import History screen
prints the overage with an explanation. The behaviour is deterministic and does
not depend on timing (H5/H6).

## 4. Where pruning happens (safe lifecycle boundary)

Pruning is applied **inside the existing write paths** — `persist_entries()` for
logs and `record()` for history — not from a timer, a cron event or a page load.
That is inherently safe because eligibility is derived from the persisted state,
not from when the prune happens: an in-flight run is protected at every instant,
so a write in the middle of a Run All cannot evict it.

**No age-based prune was activated.** `prune_older_than_90_days()` still has no
caller; Stage 3 made it group-safe (an active group's entries are never removed
whatever their age) and documented why it stays unwired: age alone cannot bound
an option array, a second unsynchronised eviction policy would be worse than one,
and activating it would need authorisation.

## 5. Idempotence and no orphans

* `retain()` returns an equal array when the policy is already satisfied;
  asserted byte-for-byte (H3, I3).
* Because eviction is by group, a retained parent always has its children and a
  retained child always has its parent — asserted by counting rows per group after
  pruning (I2).

## 6. Full logical-run reconstruction and download

* `Conexao_Import_Log::get_for_group( $group )` returns **every** retained entry
  for the logical run — matched on the first-class `run_id` *and* on
  `context.parent_run_group_id` — with **no limit**.
* `Conexao_Import_Log::export_run( $group )` emits a chronological document with a
  manifest header (parent group, retained entry count, sources present, child run
  IDs present, legacy-entry count, the bound in force and any pressure warning).
* The existing protected endpoint (`admin-post.php?action=conexao_download_run_log`,
  `manage_options` + per-run nonce) is reused unchanged, so authorisation is not
  re-implemented.
* Proven with a synthetic Run All of 60 entries across a parent and three children
  (E1–E4): all 60 present, both ends of the run present, chronological order, and
  more than 50 lines so the export cannot be the inline preview.

## 7. Inline preview disclosure

`Conexao_Import_Log::preview( $entries, 50 )` returns `shown`, `total` and
`truncated`. The Logs panel **always** states the numbers:

* truncated: “Preview: showing the newest 50 of 60 retained entries. This is a
  truncated view — download the full run to read all 60.” + “Download the complete
  run (60 entries)”.
* not truncated: “Showing all 12 of 12 retained entries for this run.”

Asserted on the rendered HTML (F3–F5). Nothing silently presents the first 50 as
if they were the whole log.

## 8. P13 status

**Not BLOCKED.** The design required no custom table, so no schema change was
needed and the disposable-database DDL path was never exercised.
