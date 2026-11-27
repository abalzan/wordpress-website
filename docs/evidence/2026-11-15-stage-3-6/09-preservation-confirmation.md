# Preservation confirmation

## 1. Git proof — no plugin, test or theme change

```
$ git diff -- wp-content/
(no output)

$ git diff --stat -- wp-content/
(no output)

$ git status --short
(no output at the time of the fetch run; the only later changes are the new
 files under docs/evidence/2026-11-15-stage-3-6/)
```

No file in `wp-content/` was created, modified or deleted. No Stage 3 code, no
Stage 3.5 evidence, no test and no plugin file was touched.

## 2. No database write

* Session `@@transaction_read_only` was **1** before the first data query, and was
  re-verified before the final target comparison (see
  `10-shared-db-access-evidence.md`).
* Only `SELECT` statements were issued, and only for the three authorised
  purposes: target inventory rows, source configuration / discovery URL, and the
  postmeta cross-check. No site-timezone query was needed or made.
* No `INSERT`, `UPDATE`, `DELETE`, `REPLACE`, `SET`, DDL, transaction-control or
  stored-procedure statement was issued. The Stage 2 `ERROR 1792` write probe was
  **referenced, not repeated**.
* No option, transient, object cache, log store, history store, continuation store
  or health record was written.

## 3. No importer, engine or scheduled path executed

| Path | Status |
|---|---|
| `run_source()` / `run_all()` | **not called** |
| `fetch_events()` / `fetch_via_scrape()` (source handler) | **not called, not even loaded** |
| reconciliation / `mark_missing_events()` | **not called** |
| `Conexao_Import_Continuation::run_due()` / `rearm_missing()` | **not registered and not called** — WordPress was never bootstrapped, so the `shutdown` and `wp_loaded` hooks never existed |
| WP-Cron (schedule / unschedule / rearm / run) | **not touched** |
| retention / pruning | **not run** |
| disposable database | **not used — none was needed** |

## 4. Preservation cohorts — untouched

| Cohort | Count | Touched |
|---|---|---|
| Incident-created events | 47 | No |
| Incident-updated events | 13 | No |
| Externally imported events (`133818`, `171003`, `171004`, `171006`, `171007`, `171008`) | 6 | No |
| Probe-associated imported events | 14 | No |
| Protected `stage32_export_test` posts | 275 | No |

The 285-post clone-cleanup population remains **UNRESOLVED — INSUFFICIENT IDENTITY
EVIDENCE** and was not investigated.

## 5. Event records — untouched by construction

All 76 targets still carry `_event_status = source_not_found`, verified by a
read-only `wp_postmeta` read **before** the fetch run (456 rows = 76 posts × 6
keys, 0 mismatches). This task performed **no** post, postmeta or status write, so
by construction they are unchanged after it. Nothing was restored.

## 6. Provider-request preservation

| Rule | Status |
|---|---|
| Discovery/listing GETs only | **yes** — 3 GETs, one URL |
| Event detail pages | **none** |
| Images / linked resources | **none** |
| Links followed from a provider response | **none** |
| Cork or any non-target source | **none** |
| `robots.txt` fetched | **no** — not fetched, and the Eventbrite client has no robots handling to record |
| Redirects followed | **none** — `Location` never present; cross-host following is disabled in the harness |
| Credentials / login / admin action | **none** |
| User-Agent | the configured value only; no additional browser identity spoofed |

## 7. No commit, push, deploy, activation or release

None was performed. This task added new files under
`docs/evidence/2026-11-15-stage-3-6/` only, and left them uncommitted.

## 8. Prior stage verdicts unchanged

Stage 2, Stage 3 and Stage 3.5 verdicts and every file in their evidence folders
are unmodified. Stage 3.5's `05-restoration-plan.md` was **not** edited; Stage 3.6
adds a separate `11-restoration-plan-addendum.md`.
