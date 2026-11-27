# `events_imported` — semantics before and after (P12)

## 1. What the field actually is

| Item | Finding |
|---|---|
| Storage | one integer in each `conexao_event_sources` registry record |
| Written by | `Conexao_Event_Importer_Engine::update_source_stats_after_run()` → `'events_imported' => $result->get_counts()['created']` |
| Meaning | **events CREATED by this source during its most recent run** |
| Aggregated anywhere? | **No.** It is per source, per last run. Nothing sums it. |
| Rendered by | `class-event-sources.php`, the Event Sources table |
| Was the label accurate? | **No.** The column header said **“Events Imported”**, which reads as a cumulative total |

This is the historical defect verbatim: “`events_imported` represented creations
in the latest run rather than a cumulative imported-event total”.

## 2. The option chosen, and why

The task offered two solutions: relabel accurately, or build a real cumulative
metric with a migration.

**Chosen: relabel accurately.** Reasons:

1. The stored value is not wrong — it is a per-run creation count, and that is a
   genuinely useful number (“what did last night's run add?”).
2. A cumulative total would need a backfill that no local evidence can supply:
   the shared store retains 100 history rows, so the historical created count per
   source is not reconstructable. A migration would therefore have to *invent*
   the starting value, which is exactly what this repository forbids.
3. Relabelling is the least disruptive correct change: no stored value is reset
   or reinterpreted, no reader changes, and no migration runs anywhere.

## 3. Before / after

| | Before | After |
|---|---|---|
| Column header | `Events Imported` | **`Created (last run)`** |
| Tooltip / help | none | “Events created by this source during its most recent run only. This is not a cumulative total.” |
| Stored value | last run's `created` count | **unchanged** |
| Historical values | — | **unchanged, not reset, not migrated** |
| Distinct counters | created / updated / skipped preserved in Import History | **unchanged** |

## 4. Compatibility

* No option key, record shape or type changed.
* `get()`/`save()`/`update_import_stats()` untouched.
* `scripts/run-event-import.php` and `scripts/run-eventbrite-import.php` still
  print `events={$source['events_imported']}` — unaffected, and they print the
  raw field rather than a claim.
* `scripts/set-event-source-status.php` still preserves the field byte for byte.

## 5. Tests

`test-stage3-diagnostics-and-retention`, section D:

* the rendered header contains `Created (last run)` and no longer contains
  `'Events Imported'`;
* the stored field still exists (no silent removal);
* `created` and `updated` remain separate per-run counters and are not collapsed.

## 6. Related labels corrected at the same time

The Event Sources **Last Import** column now shows the stored status word for
*every* outcome, using `last_import_status_label()`:

| Stored | Shown |
|---|---|
| `success` | success |
| `warning` | completed with warnings |
| `partial` | incomplete |
| `rate_limited` | rate limited — incomplete |
| `error` | failed |

and the last-error text is shown for `error`, `partial` and `rate_limited` (it
used to be shown only for `error`). The dashboard's **Last successful import**
figure now uses `is_last_import_successful()`, so a rate-limited or incomplete
run is not presented as a success.
