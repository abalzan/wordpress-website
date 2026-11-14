# Report — Stage 0.5 test-safety audit (operator logs vs. ordinary test execution)

| | |
|---|---|
| **Stage / task name** | Stage 0.5 — audit test isolation and operator-log safety |
| **Date** | 2026-11-14 |
| **Author / agent** | Cline (agent) |
| **Branch** | `event-import-improvements` |
| **Start SHA** | `274fc10ab9c4bc4e26ee15ed077c80182e9e739a` |
| **Final SHA** | `cda8b7b10b1e8112afc09222b70472bfc5d59ad7` — a commit made **externally** during the audit, not by this task (§2a) |
| **Working tree at finish** | only the two new files of this task are untracked; nothing else is dirty |
| **Skills followed** | `wp-testing`, `wp-repository`, `wp-content-change` (read-only planning use) |
| **Authoritative docs consulted** | `AGENTS.md`; `docs/engineering-standard.md` §§0, 5.2, 8, 9, 14; `docs/content-model.md`; `docs/testing.md`; `docs/plugins/conexao-event-importer.md`; `scripts/README.md`; `.agents/skills/wp-testing/SKILL.md` |

> **Nature of this document.** This is a **read-only audit**. No test suite, no
> importer operation, no database write, no option change and no Docker change
> was performed. Every claim below comes from reading the committed source. Where
> a claim could only be established dynamically, it is named as such in §10 and
> deliberately **not** asserted as fact.
>
> **P14 is the roadmap item under audit:** *normal tests must never destroy
> operator logs.* This report answers whether that holds today; the companion
> plan (`docs/plans/2026-11-14-stage-0-5-test-safety-plan.md`) proposes the
> smallest reliable fix. **No implementation is authorised or performed here.**

## 1. Scope completed

- Established the real repository state (§2).
- Traced the complete lifecycle of every operator-facing log/history store in the
  Event Importer (creation, persistence, read, append, clear, retention, storage
  mechanism, historical implementations) — §3.
- Enumerated and classified **every** test-side path that can mutate or destroy
  those stores, with file, method, line, call chain and target — §4.
- Established the test environment's actual isolation boundary (§5): what is
  merely *described* as a test environment versus what is *technically prevented*
  from reaching operator data.
- Produced a risk assessment (§7), a falsifiable safety verdict (§8), the findings
  that could not be established statically (§10), and the acceptance criteria for
  the later implementation (carried in the plan).

## 2. Repository state at audit time

| Fact | Value |
|---|---|
| Repository path | `/home/andrei/IdeaProjects/wordpress-website` |
| Branch | `event-import-improvements` |
| `HEAD` | `274fc10ab9c4bc4e26ee15ed077c80182e9e739a` (matches the expected baseline) |
| Stage 0 plan present and modified | `docs/plans/2026-11-14-event-importer-stage-0-probe-deactivation.md` (` M`) |
| Stage 0 report present and modified | `docs/reports/2026-11-14-stage-0-event-importer-probe-deactivation.md` (` M`) |
| Stage 0 evidence present | `docs/evidence/2026-11-14-stage-0-event-importer-probe-deactivation/` — 18 untracked files (`01-preflight-registry-inventory.json` … `10-content-untouched-verification.stderr`) |
| Additional user changes | **none.** `git status --short` lists the two modified Stage 0 documents and the 18 Stage 0 evidence files, and nothing else. |
| Unexpected changes | none found |
| Stage 0 files touched by this audit | **none** (read-only; not reformatted, renamed or deleted) |

The working tree was **dirty by design** at audit start, exactly as the brief
described. Nothing was reverted, stashed, cleaned or reformatted.

### 2a. HEAD moved during the audit — externally, not by this task

At audit start `HEAD` was `274fc10a…` and `git status --short` showed the 2
modified Stage 0 documents and 18 untracked Stage 0 evidence files (§2 above,
recorded verbatim). At audit end `HEAD` is `cda8b7b10b1e8112afc09222b70472bfc5d59ad7`:

```
cda8b7b Add verification files for event importer probe deactivation
274fc10 feat: add preflight plan, evidence, and report for Event Importer Stage 0 …
464a353 feat: remove Sources section from Event Import dashboard
```

What that commit contains is **exactly** the previously-dirty Stage 0 state — the
2 documents and the 18 evidence files (`git show --stat HEAD`: 20 files, 6,425
insertions, 108 deletions) — and nothing else.

**This audit did not create it.** No `git add`, `commit`, `amend`, `stash`,
`reset`, `restore`, `checkout` or branch operation was issued by this task at any
point; every command in §12 is a read (`git status`, `git log`, `reflog`, `show`,
`rev-parse`, `diff --stat`). The commit was made by the operator/IDE while the
audit was running.

Consequences, and why nothing is lost:

- The Stage 0 documentation and evidence are **preserved**, now as commit
  `cda8b7b` rather than as a dirty tree. Their contents are byte-identical to
  what §2 recorded: `git status --short` lists no modification to any of them.
- The audit's conclusion is unaffected: it was derived from code at `274fc10a`,
  and `cda8b7b` touches **only** `docs/` (verified by the stat above: no
  `wp-content/`, `tests/` or `scripts/` path appears).
- The final working tree contains exactly the two files this task added.



## 3. Operator log architecture (what must survive)

### 3.1 The primary operator log — `Conexao_Import_Log`

| Property | Value | Evidence |
|---|---|---|
| Storage mechanism | **single WordPress option** (not a table, file, transient or CPT) | `includes/class-import-log.php:25` `const OPTION_KEY = 'conexao_event_import_log';` |
| Autoload | **off** on every write | `class-import-log.php:226`, `:357`, `:565` |
| Cap | 2,000 entries, newest first, older dropped | `:38` `MAX_ENTRIES = 2000`; slice at `:224` |
| Write batching | buffered during a managed run; flush every 250 entries and once at the end | `:48` `BUFFER_FLUSH_AT = 250`; `:144-157`; `:167-189`; `:200-210` |
| Append path | read-merge-write: re-reads the stored log immediately before writing, prepends, slices to the cap | `:217-227` |
| Immediate write path | any `add()` outside `begin_run()`/`end_run()` | `:155-157` |
| Read path | `get_all()`, `get_for_source()`, `get_for_run()`, `get_run_ids()`, `index_entries_by_run()`, `entries_for_run_index()`, `get_last_error()` | `:234`, `:246`, `:266`, `:295`, `:326`, `:342`, `:369` |
| **Clear path** | `Conexao_Import_Log::clear()` → `update_option( OPTION_KEY, array(), false )` and drops the buffer | `:354-358` |
| **Retention** | `prune_older_than_90_days()` flushes, drops entries older than 90 days, rewrites the option | `:546-569` |
| Retention trigger | **none in production code today.** The only callers are tests (`tests/test-import-log.php:278`, `tests/test-import-history-logs.php:839`). `docs/plugins/conexao-event-importer.md:113-115` claims it runs “opportunistically when the Import History dashboard is rendered”; **no such call site exists under `includes/`**. Recorded as documentation/implementation drift in the same store. **Out of scope** for Stage 0.5 (P13). |
| Operator-facing clear action | `admin-post.php?action=conexao_clear_log`, `manage_options` + `check_admin_referer( 'conexao_clear_log' )`, then `clear()` | `includes/class-import-log-admin.php:208-230` |
| Operator-facing read/download | whole-log and single-run downloads through `admin_post_*` handlers | `class-import-log-admin.php:161-199` |
| Dashboard read | Import History builds a run index from `get_all()` and renders per-row log detail | `includes/class-event-sources.php:1933-1940`, `:2001` |
| Producers | ~40 call sites: engine, sources, client, source health, continuation scheduler | `class-event-importer.php:116 … 2219`; `class-eventbrite-client.php:256-410`; `sources/*.php`; `class-source-health.php:97`; `class-conexao-import-continuation.php:568` |

**What an operator reads it for:** per-source / per-page diagnostics with HTTP
status and URL context, and the per-run log opened from an Import History row
(`docs/plugins/conexao-event-importer.md:1336-1348`). It is the record of *what an
import actually did*; losing it loses the explanation of a failed run.

### 3.2 The run history — `Conexao_Import_History`

| Property | Value | Evidence |
|---|---|---|
| Storage | option `conexao_event_import_history`, autoload off | `includes/class-import-history.php:15`, `:100` |
| Cap | 100 rows | `:18` `MAX_ENTRIES = 100` |
| Append | `record()` → `array_unshift` + slice + `update_option` | `:30-103` |
| **Clear** | `clear()` → `update_option( OPTION_KEY, array(), false )` | `:168-170` |
| Producers | the engine, on every run outcome | `class-event-importer.php:278`, `:457`, `:499`, `:543`, `:576`, `:604`, `:911` |
| Operator surface | the Import History table (same dashboard screen) | `docs/plugins/conexao-event-importer.md:1320` |

### 3.3 The cleanup history and last-cleanup status

| Property | Value | Evidence |
|---|---|---|
| Storage | options `conexao_event_cleanup_status` and `conexao_event_cleanup_history`, autoload off | `includes/class-event-cleanup.php:25`, `:32` |
| Cap | last **50** cleanup runs | `:554-572`, cap comment `:569` |
| Writers | `save_status()` `:535-537`; `save_history()` `:554-572` | |
| Readers | `get_status()` `:545-547`; `get_history()` `:580-583` | |
| Nature | **operator-facing history of what was purged**: time, events found/deleted, images evaluated/deleted/preserved, error count (`:557-567`) — a log in the same sense as §3.2 | |


### 3.4 Adjacent operator state (not “logs”, but the same blast radius)

| Option | Owner | Why it matters |
|---|---|---|
| `conexao_event_sources` | `class-event-sources.php:12` | the 154-record source registry: `status`, `last_import`, `last_import_status`, `last_error`, `events_imported`. **Holds the Stage 0 result** (18 `g1_probe_*` records `inactive`). |
| `conexao_event_source_health` | `class-source-health.php:19` | per-source consecutive/total failures, last success/failure |
| `conexao_event_source_cooldown` | `class-source-cooldown.php:40` | why a source is cooling down and for how long |
| `conexao_event_enumeration_checkpoints` | `class-source-enumeration-checkpoint.php:82` | resume progress |
| `conexao_event_import_continuations` | `class-conexao-import-continuation.php:98` | scheduled continuations |
| `conexao_event_importer_settings` | `class-import-settings.php:43` | `cleanup_scope` |

### 3.5 Storage mechanisms and historical implementations

- **There is exactly one importer diagnostic log store and exactly one run
  history store.** No custom table, no log file, no transient, no CPT and no
  second logger implementation exists for the Event Importer. Grepping
  `includes/` for `OPTION_KEY` / `*_OPTION` returns precisely the ten keys of
  §3.1–§3.4; there is no hidden eleventh store.
- The **“Import Logs” admin screen was retired**; the log is read inline in the
  Import History table. `class-import-log-admin.php:1-26` states explicitly that
  only the *screen* was retired and that “the underlying logging system
  (`Conexao_Import_Log`), its storage option, buffering, retention, pruning and
  sanitisation are untouched”. The retired screen is **not** a second store.
- The **cleanup history** (§3.3) is a *different* store with a different owner,
  cap (50) and meaning (purge record, not import record). They must never be
  treated as interchangeable — §4 shows they are destroyed by different tests.

### 3.6 Classification of the stores for test purposes

| Class | Stores / facilities |
|---|---|
| **Live operator log/history that must be preserved verbatim** | `conexao_event_import_log`, `conexao_event_import_history`, `conexao_event_cleanup_history`, `conexao_event_cleanup_status` |
| **Operator state that is not a log but must not be destroyed** | `conexao_event_sources`, `conexao_event_source_health`, `conexao_event_source_cooldown`, `conexao_event_enumeration_checkpoints`, `conexao_event_import_continuations`, `conexao_event_importer_settings` |
| **Test-generated logs / fixtures** | **none exist.** There is no test-specific log store and no test-only logger; every entry a test produces is written into the live option (§4.3). |
| **Debug output that is not part of the operator log** | suite `echo`/`printf` output, `WP_Error` messages returned to callers, raw `WP_DEBUG` notices. None of these touch the option. |
| **Test cleanup / reset facilities** | `tests/lib/option-isolation.php` (`test_options_isolate()` / `test_options_restore()`), ad-hoc snapshot blocks inside suites (§4.5), and two suites that `delete_option()` the cleanup history outright (§4.1). |
| **Normal production retention / pruning** | the 2,000-entry cap and the 90-day prune of §3.1: real production behaviour, untouched by this stage (P13). |

## 4. Test-side destructive paths

### 4.0 Verdict summary

| # | Classification | Count | Operator data affected |
|---|---|---|---|
| A | **Confirmed destructive** — static code proves the test erases operator data | 2 suites + 1 documented script | cleanup history/status (a log); real past events (content) |
| B | **Confirmed destructive-in-window** — the isolation helper empties the live option and restores it later; destruction on abnormal termination or concurrent write | 20 suites | import log, import history, sources, health, cooldown, checkpoints, continuations, settings |
| C | **Confirmed parallel collision** — two concurrent suites snapshot/restore the *same* option; the later restore can write a stale (emptied) value | 6 suites, all classified `PARALLEL` | import log, import history |
| D | **Potentially destructive / contaminating** — writes reach the live log only on error or warning branches, or through a helper whose runtime target was not proven statically | 6 suites | import log (appends) |
| E | **Safe** — pure parsing/normalisation or an isolated temporary fixture with no operator-store reach | remainder | none |
| F | **Unresolved** | see §4.4 | — |

### 4.1 Confirmed destructive

#### A1 — `test-event-cleanup-purge.php` deletes the cleanup history and status options

| Field | Detail |
|---|---|
| Test | `wp-content/plugins/conexao-event-importer/tests/test-event-cleanup-purge.php` |
| Trigger | ordinary suite run (auto-discovered by `scripts/run-tests.sh`; classified **SERIAL**) |
| Operation | `delete_option( 'conexao_event_cleanup_status' );` `delete_option( 'conexao_event_cleanup_history' );` |
| Target | the **live** options `conexao_event_cleanup_status` and `conexao_event_cleanup_history` (the operator's 50-run purge history, §3.3) |
| Scope | **broad** — unconditional, not scoped to fixtures and not guarded by any snapshot or restore |
| Risk | **real operator data is destroyed** |
| Evidence | `test-event-cleanup-purge.php:536-537`, executed unconditionally just before `test_finish( 'Event Cleanup Purge' )` at `:539` |
| Call chain | `run-tests.sh` → `php …/test-event-cleanup-purge.php` → `tests/bootstrap.php:120` (`wp-load.php`, live DB) → suite tail → `delete_option()` × 2 → `test_finish()` |
| Recommendation | remove the two `delete_option()` calls; if the suite needs a clean cleanup store, isolate it through the same mechanism the import log gets (plan §5) — never delete the operator's rows |

**This is the single most clearly confirmed operator-log destruction in the
repository.** It fires on *every* full run, whatever the operator's history
contains, and it cannot be undone.

#### A2 — `scripts/test-event-cleanup.php` does the same

| Field | Detail |
|---|---|
| Test | `scripts/test-event-cleanup.php` (documented in `scripts/README.md:282` as `local-write`) |
| Trigger | manual invoke: `docker compose exec wordpress php /var/www/html/scripts/test-event-cleanup.php`. **Not** discovered by `run-tests.sh` (discovery is `wp-content/*/*/tests/test-*.php`, `tests/acceptance/`, `tests/scripts/`). |
| Operation | `delete_option( 'conexao_event_cleanup_status' );` `delete_option( 'conexao_event_cleanup_history' );` |
| Target | same live options as A1 |
| Scope | broad, unconditional |
| Risk | real operator data destroyed whenever this “test” is run |
| Evidence | `scripts/test-event-cleanup.php:241-242` |
| Recommendation | same as A1; also mark it clearly in `scripts/README.md` until fixed |

#### A3 — `test-event-cleanup-purge.php` runs the real cleanup engine against the whole database

| Field | Detail |
|---|---|
| Test | `test-event-cleanup-purge.php` (same file as A1) |
| Trigger | ordinary suite run |
| Operation | `$run_result = $cleanup->run_cleanup();` |
| Target | **every** past event in the live database, in both languages, not just the suite's fixtures |
| Scope | **broad / global** |
| Risk | **content destruction** on every run: `Conexao_Event_Cleanup::run_cleanup()` (`includes/class-event-cleanup.php:66-93`) calls `find_past_events()` (`:140`), which queries `post_type=event`, `post_status=any`, `lang=''` (all languages), and deletes each result plus its exclusively-owned images (`delete_event_with_media()`, `:84`). The suite's own assertions only check “at least the fixtures” (`:510-518`), so real expired events are deleted silently and the suite still passes. |
| Evidence | `test-event-cleanup-purge.php:508`; `class-event-cleanup.php:66-93`, `:140-…` |
| Recommendation | the suite must not call `run_cleanup()` against the live population. Either drive the per-event helper (`cleanup_test_apply_one()`, already used at `:304`, `:361`, `:382`, `:395`) or restrict the query to a synthetic scope (e.g. an `_event_imported` + test-marker filter) that provably cannot match real records. This is a content-scope change, so it needs its own explicit authorisation and must be listed in the Stage 0.5 plan as *test-only* — no production retention change (P13). |


### 4.2 Confirmed destructive-in-window — the isolation helper is not isolation

`tests/lib/option-isolation.php` is the repository's mitigation. It is well
argued and it has already prevented a real incident (`test-import-log.php` drove
the live log `599 → 91 → 0` before it was introduced — see the file's own
docblock `:1-31` and `docs/plugins/conexao-event-importer.md:1351-1376`). It is
**not**, however, isolation. Its contract is:

```php
$restore = test_options_isolate( array( 'conexao_event_import_log' ) ); // :51
// snapshot (:59-98) → seed EMPTY into the LIVE option (:101) → shutdown net (:108-114)
test_options_restore( $restore );                                      // :126
```

| Field | Detail |
|---|---|
| Trigger | any suite that calls `test_options_isolate()` — 20 files (list below) |
| Operation | `update_option( $option_name, array(), false )` against the **live** option, then `update_option()` back to the snapshot, or `delete_option()` when the option did not exist (`:101`, `:140`, `:163`) |
| Target | live operator options, per the array each suite passes |
| Scope | one suite, one option set — but the option itself is the operator's only copy |
| Risk | see the three failure modes below |
| Evidence | `tests/lib/option-isolation.php:51-117` (isolate), `:126-173` (restore); `tests/bootstrap.php:129` loads it for **every** suite |

**Failure mode 1 — abnormal termination loses the snapshot.** The restore runs
either from the suite's own call or from the `register_shutdown_function` net
(`:108-114`). PHP runs shutdown functions on normal termination, `exit()` and
fatal errors — **not** when the process is terminated by a signal it does not
handle. `scripts/run-tests.sh` wraps every suite in `timeout "$SUITE_TIMEOUT" php …`
(`run_php()`, and the parallel worker equivalent), i.e. SIGTERM after 300 s by
default. A suite that times out after seeding the empty store therefore leaves
the operator log **empty** with no restore. The runner reports `[TIMEOUT]`, so the
run is visibly red — but the data is already gone.

**Failure mode 2 — the window is writable by everyone.** Between the seed and the
restore, *any* writer to that option targets the emptied store: a concurrent
suite (mode 3), an operator clicking Import Now in wp-admin, or a
`wp cron`/admin request. Their entries are then overwritten by the restore, which
writes the *pre-window* snapshot back. This is silent, permanent loss of exactly
the entries an operator most wants after a failure.

**Failure mode 3 — the restore cannot distinguish “mine” from “theirs”.**
`test_options_restore()` unconditionally writes the snapshot (`:140`) or deletes
the option (`:163`). There is no ownership marker, no generation counter, no
compare-and-swap. Any write that happened in between is discarded.

**Suites that empty a live operator option today (all 20):**

| Suite | Options it seeds empty | Operator log/history among them |
|---|---|---|
| `test-import-log.php:42` | log | **log** |
| `test-import-log-run-correlation.php:38-45` | log, history | **log, history** |
| `test-import-history-logs.php:86-91` | log, history | **log, history** |
| `test-import-history-dashboard.php:88-94` | history, sources, log | **log, history** |
| `test-import-continuation.php:46-58` | history, log, sources, health, cooldown, checkpoints, continuations | **log, history** |
| `test-source-pagination-completeness.php:39-49` | history, log, continuations, sources | **log, history** |
| `test-source-fetch-failure.php:44-56` | history, log, continuations, sources | **log, history** |
| `test-request-budget.php:35-50` | history, log, sources, health, continuations, checkpoints | **log, history** |
| `test-request-retry-policy.php:31-45` | history, log, … | **log, history** |
| `test-request-reuse.php:37-50` | history, log, … | **log, history** |
| `test-eventbrite-checkpoint.php:37-51` | history, log, sources, health, cooldown, continuations, checkpoints | **log, history** |
| `test-eventbrite-rate-limit.php:36-50` | history, log, … | **log, history** |
| `test-eventbrite-api-contract.php:58-70` | history, log, … | **log, history** |
| `test-eventbrite-source-expansion.php:45-57` | history, log, … | **log, history** |
| `test-eventbrite-token-retirement.php:47-60` | settings, history, log | **log, history** |
| `test-error-handling.php:39-55` | history, log, sources, continuations, health | **log, history** |
| `test-import-settings-retirement.php:45-55` | settings, log | **log** |
| `g10-convergence-experiment.php:28-40` (manual, not auto-discovered) | history, log, sources, cooldown, checkpoints | **log, history** |
| `tests/experiments/g11-eventbrite-volatility.php` (manual) | log etc. | **log** |
| `wp-content/themes/conexao-br-irlanda/tests/test-empregos-job-resources.php` | job options (not importer) | none |

`test-import-log.php` is the best-behaved member of this set: it additionally
measures the live count **outside** the snapshot (`:41`) so the retention
assertion cannot pass vacuously (`:377-388`). That pattern is the correct
discipline, and it still cannot survive failure modes 1–3.


### 4.3 Confirmed parallel collision — suites classified `PARALLEL` share the same option

`scripts/run-tests.sh` classifies suites and runs the `PARALLEL` group through a
bounded worker pool of `CONEXAO_TEST_WORKERS` (`:845-888`; default `1`, `:55`;
CI sets `3`, `.github/workflows/ci.yml:652`). The classification comment states a
`PARALLEL` suite has “no `update_option`” (`scripts/run-tests.sh:285`). **That
invariant is false for the importer suites below**, because
`test_options_isolate()` *is* an `update_option()` on the live option.

| Suite | `PARALLEL` entry | Operator option it seeds empty |
|---|---|---|
| `test-request-budget.php` | `run-tests.sh:468` | log, history |
| `test-request-retry-policy.php` | `run-tests.sh:471` | log, history |
| `test-request-reuse.php` | `run-tests.sh:474` | log, history |
| `test-eventbrite-api-contract.php` | `run-tests.sh:441` | log, history |
| `test-eventbrite-rate-limit.php` | `run-tests.sh:447` | log, history |
| `test-eventbrite-source-expansion.php` | `run-tests.sh:450` | log, history |

Interleaving that destroys the live log, with only two workers:

```
worker A  snapshot(log = 100 entries)      # :91-98
worker A  update_option(log, [])           # :101  → live log now EMPTY
worker B  snapshot(log = 0 entries)        # sees A's empty seed
worker B  update_option(log, [])           # :101
worker A  test_options_restore(100)        # :140  → live log = 100
worker B  test_options_restore(0)          # :140  → live log = 0   ← PERMANENT WIPE
```

No snapshot anywhere still holds the 100 entries. This is the exact failure the
helper exists to prevent, reintroduced by concurrency — and it is **active in CI
today** (`CONEXAO_TEST_WORKERS=3`) and available locally
(`CONEXAO_TEST_WORKERS=2 ./scripts/run-tests.sh --php`).

| Field | Detail |
|---|---|
| Trigger | `CONEXAO_TEST_WORKERS > 1`, or any two of the six suites overlapping in time |
| Operation | `update_option()` seed + `update_option()`/`delete_option()` restore on a shared key |
| Target | live `conexao_event_import_log`, `conexao_event_import_history` |
| Scope | cross-suite, cross-process |
| Risk | **permanent loss of the operator log** |
| Evidence | `tests/lib/option-isolation.php:101`, `:140`, `:163`; `scripts/run-tests.sh:285`, `:435-478`, `:845-888`; `.github/workflows/ci.yml:652` |
| Recommendation | per-process/per-suite **storage redirection** so no two workers ever touch the same row (plan §5), plus correcting the `PARALLEL` classification and its stated invariant |

Two further `PARALLEL` entries in the same block are unsafe for adjacent reasons
and are recorded here because they share the blast radius:

- `test-county-registry.php` (`run-tests.sh:435`) is `PARALLEL` while it
  **replaces the entire live source registry** — see D1.
- `test-eventbrite-importer.php` (`run-tests.sh:444`) is `PARALLEL` while it
  performs `wp_insert_post`/`wp_delete_post` through `upsert_event()` — see D2.
  The classification comment's “no `wp_insert_post`” claim is equally inaccurate
  there.


### 4.4 Potentially destructive / contaminating (D)

These paths reach the live import log **only on error or warning branches**, or
through a helper whose exact runtime target could not be proven statically. They
cannot erase the log, but they can append test entries to the operator's record —
contamination, not isolation.

| # | Test / helper | Trigger | Operation | Target | Scope | Risk | Evidence | Recommendation |
|---|---|---|---|---|---|---|---|---|
| D1 | `test-county-registry.php` | ordinary run (`PARALLEL`) | `update_option( sources, $only_legacy )` (6 records), `delete_option( sources )`, then restore the snapshot | live `conexao_event_sources` — the 154-record registry carrying the **Stage 0 result** | broad (whole registry) | Every concurrent reader sees a 6-source registry while in place; a timeout/kill between `:365` and `:499` loses the operator registry and the 18 `inactive` probe statuses | `:351` snapshot, `:365`, `:476`, `:481`, `:490` `delete_option`, `:499-501` restore; **no `register_shutdown_function` net** | isolate; never `delete_option` the registry; add a shutdown net; keep `SERIAL` |
| D2 | `test-eventbrite-importer.php` | ordinary run (`PARALLEL`) | reflection `upsert_event()` ×4 (`:746`, `:755`, `:791`, `:857`), `mark_missing_events()` (`:866`) | live `conexao_event_import_log` **on the language-conflict / `WP_Error` / taxonomy-exception / image-failure branches** (`class-event-importer.php:1371`, `:1535`, `:1562`, `:1592`, `:1834`); also creates and `wp_delete_post()`s real `event` rows (`:703-712`, `:876`) | per-fixture | Appends test entries to the operator log on a failing branch; creates/deletes content | as cited | isolate the log; drop from `PARALLEL` |
| D3 | `test-source-not-found-reconciliation.php` | ordinary run (`SERIAL`) | reflection `upsert_event()` ×3 (`:262`, `:290`, `:313`) + `mark_missing_events()` | live log on the same branches; live `event` rows and `_event_status` | per-fixture | log contamination; own-fixture content changes | `:60-63`, `:262-320` | isolate the log |
| D4 | `test-reappearing-event-lifecycle.php` | ordinary run (`SERIAL`) | reflection `upsert_event()` (`:194`, `:210`, `:237`) | live log (failure branches); event rows | per-fixture | log contamination | `:127-130`, `:194-240` | isolate the log |
| D5 | `test-export-language.php` | ordinary run (`SERIAL`) | reflection `upsert_event()` (`:245`, `:256`, `:266`) | live log (failure branches); creates events | per-fixture | log contamination | `:216-266` | isolate the log |
| D6 | `test-language-identity.php` | ordinary run (`SERIAL`) | reflection `upsert_event()` (`:73`, `:93`, `:169`) | live log; Polylang translations created/deleted | per-fixture | log contamination | `:69-71`, `:73-175` | isolate the log |
| D7 | `test-bulk-actions.php` | ordinary run (`SERIAL`) | replaces the whole sources registry (`:62-74`), then runs the **real** `handle_bulk_action()` in a child PHP process (`:249-296`, called at `:687`, `:693`, `:702`) | live `conexao_event_sources`; the live log **only for the `delete` action** (`class-event-sources.php:1121-1128`), which this suite does not drive (it uses `enable`) | broad (registry) + child process | registry-replacement window with no shutdown net; a timeout between `:62` and the restore leaves the synthetic `src_a/src_b` registry in place | `apply_bulk_action` at `class-event-sources.php:1017` | isolate the registry; assert the log is untouched |
| D8 | `test-mondello-park-importer.php` | ordinary run (`SERIAL`) | flips `mondello_park.status` to `inactive` and back (`:853-858`, `:914-917`) | live `conexao_event_sources` | one source field | a death between the flip and the restore silently changes an operator-chosen status | as cited | isolate the registry |
| D9 | `test-source-county-hint.php` | ordinary run (`SERIAL`) | `stage7_set_sources()` / `stage7_restore_sources()` replace the registry in place (`:49-66`) | live `conexao_event_sources` | broad | same as D7 | as cited | isolate the registry |


### 4.5 Safe (E) — checked, not assumed

| Suite | Why it is safe |
|---|---|
| `test-multi-file-import.php` | `import_staged_file()` → `Conexao_Event_Import::import_event()`; `class-event-import.php` contains **no** `Conexao_Import_Log` or `Conexao_Import_History` call (grep: zero hits), so no log touch. It creates and deletes its own events, correctly scoped. |
| `test-multi-file-import-ui.php` | static analysis of the JS handler body; no store write |
| `test-ivvcc-importer.php`, `test-motorsport-ireland-importer.php`, `test-ics-occurrence-dates.php`, `test-town-sanitization.php`, `test-event-address.php`, `test-past-event-filter.php` | pure parser/normaliser/date logic, no `fetch()`, no engine run, no option write |
| `test-eventbrite-volatility.php` | measurement logic only: “no HTTP, no upstream, no production write” (`:30-36`) |
| `test-event-image-sync.php` | creates and removes its own attachments |
| `test-import-log.php`, `test-import-log-run-correlation.php`, `test-import-history-*` | touch the log/history **only** inside `test_options_isolate()` — safe in isolation semantics, not safe against §4.2 failure modes |
| `test-import-history-dashboard.php` direct `update_option( history, array(), false )` at `:507`, `:536`, `:863`, `:908` | they act on an option the suite has already isolated (`:88-94`); the danger is the isolation, not these calls |

A suite is **not** classified safe because its filename suggests a unit test. Each
row above was cleared by reading its store calls.

### 4.6 Unresolved (F)

| # | Item | Why it is unresolved |
|---|---|---|
| F1 | Whether any *other* repository test (theme, translation, commemorative) writes `conexao_event_*` options | grep shows no such reference outside the importer's own tests and the two `scripts/` files; but I did not read every one of the 137 suites in full, so this is a strong negative, not a proof |
| F2 | Whether `Conexao_Event_Query::flush_cache()` (called by `run_cleanup()`, `class-event-cleanup.php:95-98`) can invalidate a cache entry a concurrent admin request is reading | out of log scope; not proven either way |
| F3 | Whether the local database currently contains log rows a run would destroy | answering this requires reading the live DB, which this audit deliberately did not do (read-only, no WordPress boot). **The conclusion does not depend on it** — the destruction is proven by code alone |
| F4 | Whether `timeout(1)` delivers SIGTERM before SIGKILL here | GNU coreutils `timeout` sends SIGTERM by default and PHP does not run shutdown functions on an unhandled SIGTERM. Not executed here by instruction. The plan's fail-closed design deliberately removes any dependency on this detail |


## 5. Test environment and isolation boundaries

Is the test environment *prevented* from touching live operator data, or merely
*described* as a test environment?

| Question | Finding | Evidence |
|---|---|---|
| Dedicated WordPress installation for tests? | **No.** | no `wp-tests-config.php`, no `WP_TESTS_*`, no second `DB_NAME` anywhere in `tests/`, `scripts/` or CI |
| Does the test bootstrap connect to the same database the operator UI uses? | **Yes.** It requires `wp-load.php` from the repository root or `/var/www/html` — the live local install, whose DB is `WORDPRESS_DB_NAME` on the single `db_data` volume | `tests/bootstrap.php:56-90`, `:120`; `compose.yaml:6`, `:10-11`, `:46`, `:95` |
| Are plugin options shared between test and normal local execution? | **Yes**, byte for byte: same tables, same options | as above |
| Does test setup reset all WordPress options, or a controlled allowlist? | Neither globally. Each suite opts in to `test_options_isolate( <allowlist> )`; ~20 do, the rest rely on ad-hoc snapshot blocks or write nothing | `tests/lib/option-isolation.php:51`; §4 |
| Does teardown restore modified options? | Only for isolated options, and only if the process reaches shutdown or the explicit restore. Registry-mutating suites restore manually with **no** shutdown net | `option-isolation.php:108-114`, `:126`; `test-county-registry.php:499-501` |
| Can a test run affect logs created outside the test process? | **Yes**, both directions: it empties/rewrites the live option and overwrites entries written during its window | §4.2 |
| Can parallel tests collide? | **Yes** — six `PARALLEL` suites share `conexao_event_import_log`; CI runs three workers | §4.3; `.github/workflows/ci.yml:652` |
| Is test-environment identity checked before destructive operations? | **No.** Neither `tests/bootstrap.php` nor `tests/lib/option-isolation.php` calls `wp_get_environment_type()`, reads `WP_ENVIRONMENT_TYPE` or checks a DB name. The only environment guards are in manual `tests/experiments/` scripts the runner never executes | grep of `tests/`, `scripts/run-tests.sh`, all plugin suites; `g14-controlled-import.php:142` |
| Is database/filesystem isolation real, documented and enforced? | **Real only in CI, and only because the CI container is destroyed afterwards** (`docker compose down -v`) — that protects an ephemeral volume, not a developer's live site. On a developer machine there is **no** isolation. `docs/testing.md:388-400` states the rule (“tests are read-only unless the suite says otherwise … clean up every … option … it created”) but documents no mechanism, and does **not** mention `tests/lib/option-isolation.php`; the helper is documented only in `docs/plugins/conexao-event-importer.md:1351-1376` | `.github/workflows/ci.yml:774`; as cited |

**Conclusion:** a *documented* test environment with a *conventional*
snapshot/restore discipline. It is **not** technically prevented from writing to
live operator data, it cannot detect that it is doing so, and its only backstop
does not cover termination by signal.

## 6. Findings that could not be established statically

1. The current *contents* of the live options (F3). Not read: that requires
   booting WordPress, which the brief forbids.
2. Whether real signal termination leaves the option empty (F4). Reasoned from
   documented PHP/coreutils behaviour, not executed.
3. Whether concurrent writers have actually collided in a past run. The mechanism
   is proven by code; no historical evidence was sought — and such evidence would
   live in the very log a collision destroys.
4. The exact wall-clock overlap of the six `PARALLEL` suites. Their durations were
   not measured (that needs a run).
5. Whether `scripts/test-event-cleanup.php` has ever been executed against this
   local site. Its `delete_option` calls are unconditional either way.


## 7. Risk assessment

| Risk | Likelihood today | Impact | Detectability |
|---|---|---|---|
| Cleanup history/status erased by `test-event-cleanup-purge.php` | **certain on every full run** | Operator loses the purge record (50 runs) | **undetectable afterwards**; the suite prints PASS |
| Real expired events purged by `run_cleanup()` inside that suite | **certain on every full run, if such events exist** | Content loss; archive/discovery changes | only via a `wp_posts` diff; the assertions still pass |
| Live import log permanently emptied by parallel snapshot/restore | **high** with `WORKERS>1` (CI) | Loses all diagnostics | silent; the next run's log looks normal |
| Live import log emptied by a suite timeout | low–moderate | same | `[TIMEOUT]` in the runner output — data already gone |
| Operator entries written during an isolation window are overwritten | moderate if a real import runs during a test run | silent partial loss | undetectable |
| Source registry destroyed or downgraded to a 6-source map | low (needs a kill in the window) | Loses the registry **and the Stage 0 `inactive` result** | visible on the dashboard |
| Test entries appended to the operator log by non-isolated suites | **certain** on failure branches | Contaminated record, misleading diagnosis | indistinguishable from real entries |

## 8. Conclusion — is the ordinary test suite currently safe to run?

**No.** Not with the operator's log intact, and not merely “theoretically”:

1. **Confirmed and unconditional:** `test-event-cleanup-purge.php:536-537`
   deletes `conexao_event_cleanup_status` and `conexao_event_cleanup_history` on
   every full run, and `:508` runs the real cleanup engine over every past event.
   No snapshot, no restore, no guard. `scripts/test-event-cleanup.php:241-242`
   does the same when invoked manually.
2. **Confirmed by construction:** every one of the 20 option-isolating suites
   *empties* a live operator option and writes it back later. The write-back is
   conditional on the process surviving to shutdown. A timeout, a SIGTERM or an
   interleaved writer loses data.
3. **Confirmed and active in CI:** six `PARALLEL` suites share
   `conexao_event_import_log`; with `CONEXAO_TEST_WORKERS=3` (CI) or `2` (local)
   the later restore can write back a stale empty value — a permanent wipe.

Sequential execution on an idle site is safer than parallel execution but still
**not safe**: finding (1) alone answers “no”, and findings (2)–(3) mean the log is
never *provably* untouched even when nothing goes wrong.

**Operational consequence:** Stage 1 onward must not run the ordinary suite until
Stage 0.5 lands — unless the operator first exports the four log/history options
out of band and accepts that the export, not the option, is the record of truth
for that window. That is a workaround, not a fix, and it is recorded so a later
stage is not blocked silently.

## 9. Scope NOT completed

- **No implementation** — by instruction. The fix is specified in the plan only.
- **No tests executed** — by instruction, and because running them was the thing
  under investigation.
- **No repair of the adjacent defects found** (the missing 90-day prune call site,
  the inaccurate `PARALLEL` classification comment, the import-log paragraphs in
  `docs/testing.md`). Each is reported and assigned to the plan's exclusions.
- **No check of the live option contents** (§6 F3).

## 10. Files added / modified / deleted

**2 added, 0 modified, 0 deleted** by this task.

| Path | Change | Note |
|---|---|---|
| `docs/reports/2026-11-14-stage-0-5-test-safety-audit.md` | added | this report |
| `docs/plans/2026-11-14-stage-0-5-test-safety-plan.md` | added | the implementation plan |

Pre-existing, preserved: the two Stage 0 documents and the 18 Stage 0 evidence
files listed in §2. They were dirty at audit start and were committed externally
as `cda8b7b` during the audit (§2a); their contents are unchanged. `git status
--short` at finish shows exactly the two files above:

```
?? docs/plans/2026-11-14-stage-0-5-test-safety-plan.md
?? docs/reports/2026-11-14-stage-0-5-test-safety-audit.md
```

## 11. Runtime / content / Polylang / route impact

| Dimension | Result |
|---|---|
| Application behaviour (`wp-content/`) | **unchanged** — no file under `wp-content/` was created, modified or deleted |
| Content records | **none** created, updated or deleted |
| Polylang | no PT record touched, no EN record created, no language setting changed |
| Routes / redirects / sitemap | no change |
| Options / DB | no option written or deleted; no schema change |
| Docker | no container, volume or environment change |

### 11a. Scope integrity

| Check | Result |
|---|---|
| No production write, deploy, upload or activation | confirmed — none attempted |
| No production content, Polylang or database change | confirmed — production never contacted |
| No application behaviour change (`wp-content/` diff is empty) | confirmed |
| No test or importer execution | confirmed — see §13 |
| No operator log, option, event or Docker state modified | confirmed — every command was `git`/`grep`/`sed`/`wc`/`find`/`ls` |
| No Flutter/mobile repository accessed | confirmed |
| No `.env` or secret change | confirmed |
| Working tree not cleaned, reset, stashed or restored | confirmed |


## 12. Verification commands executed

All read-only. Exit codes as observed.

| Command (representative) | Exit | Result |
|---|---|---|
| `pwd && git rev-parse --show-toplevel && git rev-parse HEAD && git branch --show-current && git status --short` (audit **start**) | 0 | path, branch `event-import-improvements`, HEAD `274fc10a…`, 20 dirty entries (2 modified docs + 18 evidence) |
| `git log --oneline -5 && git show --stat --oneline HEAD && git --no-pager reflog -6` (audit **end**) | 0 | HEAD `cda8b7b…`, an external commit of the 20 Stage 0 files only; reflog shows no operation by this task |
| `git status --short` (audit **end**) | 0 | exactly 2 untracked files — this report and its plan |
| `wc -l docs/engineering-standard.md docs/content-model.md docs/plugins/conexao-event-importer.md docs/testing.md` | 0 | 875 / 529 / 1957 / 898 lines |
| `find wp-content/plugins/conexao-event-importer -type f -name '*.php' \| xargs wc -l` | 0 | 53 test files (53,608 lines) + 46 production files (25,237 lines) |
| `grep -rn 'Conexao_Import_Log::' --include=*.php …` | 0 | ~45 production call sites; producer list in §3.1 |
| `grep -rn 'Conexao_Import_History::\(record\|clear\)' --include=*.php wp-content/` | 0 | 7 engine `record()` sites; 3 test `clear()` sites |
| `grep -rn 'conexao_event_cleanup_history\|conexao_event_cleanup_status' --include=*.php .` | 0 | writers: `class-event-cleanup.php:536/572`; **deleters**: `test-event-cleanup-purge.php:536-537`, `scripts/test-event-cleanup.php:241-242` |
| `for f in test-*.php; do grep -q 'test_options_isolate' "$f" \|\| echo "$f"; done` | 0 | 20 importer suites without the helper |
| `grep -rln 'test_options_isolate' --include=*.php .` | 0 | 20 files + the helper itself |
| `grep -n 'CONEXAO_TEST_WORKERS\|DB_NAME' .github/workflows/ci.yml` | 0 | `CONEXAO_TEST_WORKERS=3` at `:652`; no test DB |
| `grep -rn 'wp_get_environment_type' tests/ scripts/run-tests.sh wp-content/plugins/*/tests/*.php` | 0 | only `tests/experiments/g14,g16` (manual, never run by the runner) |
| `grep -rn 'prune_older_than_90_days' --include=*.php .` | 0 | definition + 2 test callers, **no production caller** |
| `ls -la docs/reports/2026-11-14-stage-0-5-test-safety-audit.md docs/plans/2026-11-14-stage-0-5-test-safety-plan.md` | 2 (before creation) | neither file existed; no overwrite risk |
| `git diff --stat -- docs/plans/2026-11-14-event-importer-stage-0-probe-deactivation.md …` | — | attempted; produced no output before the shell error, so the Stage 0 files were re-confirmed only through `git status --short` |

### Numeric results

| Layer | Result |
|---|---|
| `./scripts/run-tests.sh` | **not run** (forbidden) |
| `./scripts/run-tests.sh --scripts` | **not run** (no stack needed, but the brief forbids test execution) |
| In-process PHP suites | **not run** |
| HTTP acceptance | **not run** |
| PHPUnit / Pest / Behat / Playwright / Cypress / browser | **not run** |
| `./scripts/lint.sh` | **not run** (would not change state, but it is a verification of code this task did not change) |
| `php scripts/generate-registry-docs.php --check` | **not run** (no registry change) |
| Importer run / Run All / Import Now / Retry | **not run** |

## 13. Limitations

- **No runtime evidence** by instruction: every finding is static. §6 lists the
  five things that could not be established this way.
- **The live option contents were not inspected**, so the report cannot say how
  much operator history is currently at risk — only that it is at risk.
- **No reproducer was executed.** The parallel interleaving in §4.3 is derived
  from the helper's own read/snapshot/seed/restore order, not observed.
- **Not every one of the 137 suites was read line by line**; the importer suites
  were read in full, the rest by targeted grep for option/store calls (F1).
- **CI was not observed.** The `WORKERS=3` claim comes from the workflow file.

## 14. Evidence paths

**None created.** The brief permits exactly two files, so no
`docs/evidence/2026-11-14-stage-0-5/` directory was created and no command output
was captured to disk. The command outputs quoted in §12 are the evidence, and
they are all reproducible read-only commands. The eventual implementation must
capture before/after option exports under `docs/evidence/2026-11-14-stage-0-5/`
(plan §14).

## 15. Documentation updated

| Document | Change |
|---|---|
| `docs/reports/2026-11-14-stage-0-5-test-safety-audit.md` | added (this report) |
| `docs/plans/2026-11-14-stage-0-5-test-safety-plan.md` | added (implementation plan) |

Deliberately **not** updated in this task: `docs/testing.md` (needs the isolation
mechanism once it exists), `docs/plugins/conexao-event-importer.md` (needs the
correction to the retention-trigger sentence and the new storage seam),
`docs/reports/README.md` / `docs/README.md` (indexes). Each is scheduled in the
plan, because documenting a mechanism that does not exist yet would create a
second source of truth for behaviour that is not there.

## 16. Rollback / recovery

Deleting the two files returns the working tree to exactly the committed state of
`cda8b7b`; nothing else was changed by this task, so there is nothing else to roll
back. The Stage 0 plan, report and evidence were never written to, moved,
reformatted or deleted by this task — they were ` M`/`??` at audit start and are
now committed verbatim in `cda8b7b` (§2a), which is outside this task's control
and outside its rollback.

## 17. Final status

**Final status: PASS WITH LIMITATION**

The audit is complete and its findings are conclusive on the question asked —
static code proves the destructive paths — but the brief forbade executing
anything, so no finding rests on a runtime observation, and the five items in §6
remain unverified by design (§13). Local test execution never proves release or
production readiness, and this document proves nothing about production.

_Last verified: 2026-11-14 by the Stage 0.5 test-safety audit (read-only; no test, importer or database action performed)_
