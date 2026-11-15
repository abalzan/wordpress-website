# Stage 3 — final diff and preservation review

## 1. What the diff contains

```
$ git status --short
 M docs/evidence/README.md
 M docs/plugins/conexao-event-importer.md
 M docs/reports/README.md
 M wp-content/plugins/conexao-event-importer/assets/admin.css
 M wp-content/plugins/conexao-event-importer/includes/class-conexao-import-continuation.php
 M wp-content/plugins/conexao-event-importer/includes/class-event-importer.php
 M wp-content/plugins/conexao-event-importer/includes/class-event-sources.php
 M wp-content/plugins/conexao-event-importer/includes/class-import-history.php
 M wp-content/plugins/conexao-event-importer/includes/class-import-log.php
 M wp-content/plugins/conexao-event-importer/includes/class-import-result.php
 M wp-content/plugins/conexao-event-importer/includes/class-source-health.php
 M wp-content/plugins/conexao-event-importer/tests/test-import-history-dashboard.php
 M wp-content/plugins/conexao-event-importer/tests/test-import-history-logs.php
 M wp-content/plugins/conexao-event-importer/tests/test-import-log.php
 M wp-content/plugins/conexao-event-importer/tests/test-source-fetch-failure.php
?? docs/evidence/2026-11-15-stage-3/
?? docs/reports/2026-11-15-stage-3-event-importer-diagnostics-retention.md
?? wp-content/plugins/conexao-event-importer/tests/test-stage3-diagnostics-and-retention.php
```

The documentation edits are `docs/reports/README.md`, `docs/evidence/README.md` and
`docs/plugins/conexao-event-importer.md`; the new report and the new evidence
directory are the two untracked documentation paths above.

Every modified path is a Stage 1 or Stage 2 file. Each is justified in
`09-files-changed.md`; the justification in one line:

| File | Why a Stage 1/Stage 2 file had to change |
|---|---|
| `class-import-log.php` | P12 requires the correlation keys to survive sanitisation; P13 requires group-aware retention in this store. |
| `class-import-history.php` | P13's retention unit IS Stage 1's run group; the history store owns those rows. |
| `class-import-result.php` | P12's warning-vs-incomplete distinction is implemented by this class's status methods. |
| `class-event-importer.php` | The correlation context and the truthful registry mapping originate in `run_source()` / `update_source_stats_after_run()`. |
| `class-conexao-import-continuation.php` | P12's lifecycle correlation and stuck-state diagnostics are this class's subject. |
| `class-source-health.php` | A rate-limited/incomplete run needs a health outcome that is neither success nor failure. |
| `class-event-sources.php` | All operator-facing rendering (labels, preview disclosure, diagnostics panel) lives here. |

## 2. Files with ZERO diff (the protections)

Verified with `git diff --name-only` and `git status --porcelain`:

* `class-eventbrite-pacing.php`, `class-eventbrite-client.php` — P1 breaker and
  Retry-After handling.
* `class-source-cooldown.php`, `class-source-enumeration-checkpoint.php`,
  `class-request-budget.php`, `class-request-reuse.php` — Stage 1 budget/lock/
  checkpoint machinery.
* `class-conexao-event-enrichment-state.php`, `class-event-normalizer.php` — P5.
* `tests/lib/option-isolation.php`, `tests/bootstrap.php`,
  `tests/lib/isolated-db.php` — Stage 0.5 isolation.
* `conexao-event-importer.php` (the plugin bootstrap: no new class to require).
* `docs/reports/2026-11-15-stage-2-event-importer-fetch-reconciliation.md` and
  `docs/evidence/2026-11-15-stage-2/`, `docs/evidence/2026-11-15-stage-2-verification/`.
* `docs/evidence/2026-11-14-stage-0*/`, `docs/evidence/2026-11-15-stage-1*/`.

## 3. P1 and P6 confirmed unchanged

| Claim | How it was checked |
|---|---|
| P1 breaker semantics unchanged | `class-eventbrite-pacing.php` and `class-eventbrite-client.php` show **no diff**; `test-eventbrite-rate-limit` 87/0, `test-request-retry-policy` 59/0, and the new suite asserts `Conexao_Eventbrite_Pacing::is_rate_limited()` is `false` after all its work (B11). |
| P1 budget accounting unchanged | `class-request-budget.php` no diff; `test-request-budget` 33/0. |
| P6 parent/child identity unchanged | `run_all()`, `record_parent()`, `refresh_parent()`, `aggregate_parent_status()` are untouched; `test-stage1-run-coordination` 47/0 includes the parent/child identity and precedence assertions. |
| P3/P4/P5/P10 unchanged | `test-source-fetch-contract` 55/0, `test-reconciliation-pagination` 57/0, `test-partial-enrichment-preservation` 42/0, `test-source-not-found-reconciliation` 57/0. |

## 4. No shared-database mutation

| Claim | Evidence |
|---|---|
| No event post created/updated/deleted | No WordPress bootstrap ever pointed at `wordpress`; every DB-backed test used `conexao_stage05_test_5bd9ece5c032`. The Stage 3 suite creates **no post at all** (its fixtures are options and in-memory arrays). |
| No `_event_status` change | The investigation's re-read found the same 106 records still `source_not_found`; no write statement was issued. |
| No source-registry change | The only registry writes were inside the disposable database's own `conexao_event_sources` option, restored by the test harness. |
| No operator log/history change | Both stores are redirected to per-process test rows by their accessors; the live rows are never opened for writing. The operator-log isolation gate passes **242/0**. |
| No retention/prune executed against the shared database | `prune_older_than_90_days()` was not called anywhere; no `retain()` ran against the shared store. |
| No schema change, account or grant change | Only `SELECT`/`SHOW` were issued, inside a read-only transaction. |

A byte-level fingerprint of the live operator log was **not** captured for Stage 3:
the read-only authorisation for this task covered the `source_not_found`
investigation, the attribution check and the 13-event resolution, and no broader
fingerprint was taken. The evidence for “the live store was not modified” is the
**test-isolation proof** (per-process option rows + the 242/0 gate), not a live
data fingerprint — stated plainly rather than conflated.

## 5. No provider traffic, no run, no deployment

* 0 HTTP requests left the process: every provider interaction in the suites goes
  through a `pre_http_request` double.
* 0 imports, Run Alls, continuations, cron events or admin AJAX actions executed.
* 0 production writes, activations, migrations, releases or deployments.
* 0 commits, pushes or merges. HEAD is still `2704169`.

## 6. Fixture hygiene

* Every fixture created by the new suite is synthetic and `stage3_`-prefixed, or
  lives only inside a per-process option row that the harness removes at
  shutdown.
* No event post, term, attachment or source was created outside those rows.
* A temporary probe script was copied into the container to diagnose one failure
  and was removed again (`docker compose exec wordpress rm -f …/stage3-probe*.php`);
  it is not in the worktree.
* No stash was created, applied or dropped; the nine pre-existing stashes are
  untouched.
