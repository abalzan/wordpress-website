# Stage 3 — baseline, preservation and scope

## 1. Actual baseline (inspected before any edit)

```
$ git log -1 --format='%H%n%ad%n%s'
270416911f918a849e34f77cacfc4d7d19925ad6
Sun Nov 15 17:42:29 2026 +0000
Add Stage 2 verification evidence and reclassifications

$ git rev-parse --abbrev-ref HEAD
event-import-improvements

$ git rev-parse HEAD
270416911f918a849e34f77cacfc4d7d19925ad6

$ git status --short
(no output — clean worktree)

$ git status --porcelain=v1 --untracked-files=all | wc -l
0
```

**The recorded expectations did not hold, and were not used:**

| Task said | Actual |
|---|---|
| “likely `3f6141a` or later” | **`2704169`**, one commit *after* `3f6141a` |
| “Stage 2 recorded HEAD `b5a3ee92…` with uncommitted work” | Stage 2 is **committed**; no uncommitted work exists |
| “only the post-verification evidence still untracked” | The post-verification evidence is **committed** too (`2704169`) |

The worktree was **clean**, so there were no pre-existing Stage 2 modifications to
preserve — there were no modifications of any kind. Nothing was reset, cleaned,
stashed, checked out or discarded. The nine stash entries present in the
repository are unrelated and were not touched.

## 2. Stage 2 and earlier work confirmed present and intact

| Item | Evidence |
|---|---|
| Stage 0 probes deactivated | `docs/evidence/2026-11-14-stage-0-event-importer-probe-deactivation/` present; no probe reactivated |
| Stage 0.5 operator-log isolation | `tests/lib/option-isolation.php` registry of four protected stores; `tests/bootstrap.php` fail-closed preflight; gate passes (see `07-tests.txt`) |
| Stage 1 P1 breaker | `Conexao_Eventbrite_Pacing` + `parse_retry_after*` untouched except the additive reason code in `mark_rate_limited()`; `test-stage1-run-coordination` 47/0 |
| Stage 1 P6 parent/child model | `run_all()` / `record_parent()` / `refresh_parent()` / `aggregate_parent_status()` untouched |
| Stage 2 P3/P4/P5/P10 | `test-reconciliation-pagination` 57/0, `test-source-fetch-contract` 55/0, `test-partial-enrichment-preservation` green; `class-conexao-event-enrichment-state.php` unmodified |
| Stage 2 report | `docs/reports/2026-11-15-stage-2-event-importer-fetch-reconciliation.md` unmodified — **its verdict was neither upgraded nor downgraded** |
| Stage 2 evidence | `docs/evidence/2026-11-15-stage-2/` unmodified |
| Stage 2 verification evidence | `docs/evidence/2026-11-15-stage-2-verification/` unmodified (the 106-row TSV digest re-verified: `f1d3eda8…`) |

## 3. Database safety established before any test

```
$ docker compose exec -T \
    -e WORDPRESS_DB_NAME=conexao_stage05_test_5bd9ece5c032 \
    -e CONEXAO_TEST_DB_NAME=conexao_stage05_test_5bd9ece5c032 \
    -e CONEXAO_TEST_SHARED_DB_NAME=wordpress \
    -e GITHUB_ACTIONS=false wordpress php /var/www/html/tests/lib/isolated-db.php
ISOLATED_DB_PREFLIGHT=PASS database=conexao_stage05_test_5bd9ece5c032 host=db
```

Every WordPress-dependent suite in this task ran with exactly those four
environment variables, so the bootstrap's own identity check re-ran for every
suite. `worpress` (the shared development database) was never the target of a
WordPress bootstrap, and `conexao_stage05_test_5bd9ece5c032` is the only test
database used.

## 4. Operator-log isolation gate (before implementation)

```
$ python3 tests/scripts/verify-operator-log-isolation.py
  237 passed, 0 failed (14 checks)
GATE-RESULT operator_log_isolation: passed=237 failed=0
GATE PASS
```

Re-run after implementation: see `07-tests.txt`.

## 5. New persistent stores introduced by Stage 3

**None.** Stage 3 adds no option, no transient, no custom table and no schema
change. It only changes how the two existing stores (`conexao_event_import_log`,
`conexao_event_import_history`) decide what to keep. Consequently the Stage 0.5
isolation registry needed no new entry, and no migration exists to run.
