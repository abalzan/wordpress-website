# Trigger analysis — what changed event content before any Stage 0.5 suite ran

## Timeline (UTC, 2026-11-14)

| Time | Event |
|---|---|
| ~20:5x | Baseline captured (`01-*`): 3640 event rows, `conexao_event_sources` = `0949ae6f…` (85703 bytes) |
| ~21:20 | `./scripts/run-tests.sh --scripts` started (verification step 2) |
| 21:25–21:30 | The script-contract gate `tests/scripts/verify-stage14-secret-scan.py` ran. It writes `tests/stage14-conformance-driver.php` and executes it with `docker compose exec wordpress php …` |
| **21:27:09** | Inside that CLI process, the importer logged *"Continuation starting for eventbrite_cork: resuming the enumeration from page 19 of 49"* — into `conexao_event_import_log__test__p17934_6eb86b11430b4a27` |
| 21:28:03–21:29:28 | 47 new `event` rows created and 13 existing ones updated, all `_event_source = eventbrite_cork` |
| ~21:30 | The gate hit the runner's 300 s per-suite timeout and was killed |
| ~21:31–21:33 | A second, manually started run of the same gate produced process `p18030` (log row 18753 bytes, still present) and logged a `eventbrite_louth` continuation at 21:33:04 |
| 21:35+ | The change was detected on the first re-export (verification step 6 was never reached; this was still step 3) |

## The mechanism (pre-existing, not introduced by Stage 0.5)

1. The gate's driver begins with
   `require_once __DIR__ . '/bootstrap.php';` — the shared test harness.
2. `tests/bootstrap.php` loads `wp-load.php`, which loads the active plugins.
3. `wp-content/plugins/conexao-event-importer/conexao-event-importer.php:345-350`
   registers `wp_loaded` → `Conexao_Import_Continuation::rearm_missing()`. That only
   re-arms cron timers; it does not import.
4. `conexao-event-importer.php:336-342` registers **`shutdown` (priority 20)** →
   `Conexao_Import_Continuation::run_due()`. **Every** process that loads WordPress
   therefore executes any due continuation at shutdown — including a CLI test
   process.
5. The local database held **25** continuation records, several due
   (`09-continuations-at-stop.txt`). `run_due()` ran `eventbrite_cork`.

Stage 0.5 changed only **where the diagnostics go**. The import's log entries were
written to the per-process test row (proved by the excerpts below); the live
`conexao_event_import_log` was **never written**. Content writes are outside this
stage's scope (there is no content isolation until the isolated-database stage).

## Proof that the log seam held under a REAL import

| Option | Baseline hash | Hash after the import | Result |
|---|---|---|---|
| `conexao_event_import_log` | `b6e8b137…` | `b6e8b137…` | **unchanged** |
| `conexao_event_import_history` | `56c9b788…` | `56c9b788…` | **unchanged** |
| `conexao_event_cleanup_status` | `3f4d9bfe…` | `3f4d9bfe…` | **unchanged** |
| `conexao_event_cleanup_history` | `84d44b6e…` | `84d44b6e…` | **unchanged** |
| `conexao_event_sources` | `0949ae6f…` | `d7963c00…` | **changed** (import bookkeeping) |

The run's diagnostics are in `conexao_event_import_log__test__p17934_…` (64578 bytes)
and `…p18030_…` (18753 bytes) — see `09-orphan-test-rows.txt`.

## Why the precondition was not satisfied

The plan's precondition **P6** is "no import running (no continuation lock)".
The live database had 25 continuation records, several due, plus
`conexao_event_import_lock_eventbrite_longford` / `…_louth` rows. That is why the
first WordPress-booting gate could import. The precondition check was not performed
before step 2; this is recorded as the process gap that allowed the incident.

## What was NOT done

- No suite of the importer plugin was run.
- The content was **not** restored, overwritten or reverted from the snapshot.
- No importer operation was triggered deliberately; no manual cleanup script was run.
