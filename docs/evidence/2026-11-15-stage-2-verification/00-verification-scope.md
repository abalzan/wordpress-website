# Stage 2 post-implementation verification — scope, baseline and safety

Verification and documentation only. **No application code, test, or database
record was modified.** No commit, push, merge or deploy was performed.

## 1. Purpose

Four verification gaps in `docs/reports/2026-11-15-stage-2-event-importer-fetch-reconciliation.md`
were addressed:

| Item | Gap | Outcome location |
|---|---|---|
| 1 | The Stage 2 report claimed "no read-only method exists", but the task's §3.7 engine-enforced read-only transaction path was never attempted | `01-readonly-transaction-and-preservation.md` |
| 2 | `test-source-fetch-failure.php` was classified pre-existing without a clean-state control against pre-Stage-2 code | `02-preexisting-failure-verification.md` |
| 3 | `test-import-continuation.php` was framed as a Stage 2 "pre-existing" failure instead of a Stage 1 open item | `03-continuation-reclassification.md` |
| 4 | Three modified files were claimed additive-only without a diff review | `04-additive-change-verification.md` |

## 2. Repository state at the start of this verification

| Item | Value |
|---|---|
| Branch | `event-import-improvements` |
| **Actual HEAD** | `3f6141a251365b8f7bdbfa0d2dd0fec92e9fefdd` — *"Add comprehensive tests for source fetch contract and handler completeness"* |
| `git status --porcelain` | empty — clean worktree |
| Pre-Stage-2 SHA | `b5a3ee92be0f3e547be1f3be7e291afd3d924e60` (the HEAD the Stage 2 report records at its start) |
| Stage 2 commit time | `2026-11-15T15:21:17Z` |
| Stage 2 diff | `git diff --stat b5a3ee9..HEAD` → 27 files, +3,918 / −152 |

Stage 2's reported HEAD (`b5a3ee9`, "no commit") is **no longer HEAD**: the Stage 2
work has since been committed as `3f6141a`. The Stage 2 diff was verified to be
intact and unmodified by this task (`git status` clean before and after).

## 3. Historical work preserved untouched

Read but not modified: Stage 0, Stage 0.5, Stage 1, Stage 1 preservation
reconciliation and Stage 2 evidence and reports. No file under
`docs/evidence/2026-11-14-stage-0-*`, `docs/evidence/2026-11-14-stage-0-5/`,
`docs/evidence/2026-11-15-stage-1/`,
`docs/evidence/2026-11-15-stage-1-preservation-reconciliation/` or
`docs/evidence/2026-11-15-stage-2/` was edited. Unresolved Stage 1 findings
(285-post clone membership, before-edit all-event digest method, post 170981
existence timing) were **not** attempted.

## 4. Databases and how read-only was enforced

| Environment | Database | Role in this task |
|---|---|---|
| Local shared WordPress | `wordpress` | Target of every data capture |
| Disposable Stage 0.5 clone | `conexao_stage05_test_5bd9ece5c032` | Target of the clean-state control test runs only |
| Unrelated test DB | `wp_multipart_test` | Not used |

Server: MySQL `9.7.1` (MySQL Community Server — GPL), host `db`, port `3306`.

**Account:** `root@localhost` — the MySQL administrative account supplied by the
local Docker configuration (`compose.yaml`, `MYSQL_ROOT_PASSWORD` in `.env`,
redacted). It is **not** the WordPress application account (`wordpress`), which
was never used for any shared-database query. No account, grant or privilege was
created, dropped, granted, revoked or altered.

**Enforcement:** `SET SESSION TRANSACTION READ ONLY;` + `START TRANSACTION READ ONLY;`,
verified empirically **before the first data query** — see `01-readonly-transaction-and-preservation.md`
§1. Raw transcript: `01b-readonly-probe.txt`.

Every capture ran with an explicit `SET SESSION TRANSACTION READ ONLY;` /
`START TRANSACTION READ ONLY;` preamble and a closing `ROLLBACK;`. No stored
procedure, function, `LOCK TABLES`, `SELECT … FOR UPDATE`, `INTO OUTFILE` or
`INTO DUMPFILE` was used. WordPress was never bootstrapped against `wordpress`;
no importer, scheduler, continuation, cron or hook was triggered against it; no
live provider request was made.


## 5. Read-only method used for captures

One short read-only transaction per dataset (not one long transaction). Each
dataset is a single `SELECT`, so per-dataset transactions give the same
consistency guarantee without holding a long transaction, and every dataset has
an independent, reproducible transcript.

Serialization for every fingerprint: tab-separated, LF line endings, no header,
`ORDER BY` documented per dataset, SHA-256 over the raw output of
`mysql --batch --raw --skip-column-names`. The tab-separated method was
**calibrated against a known-good value before use**: it reproduces the recorded
275-row `stage32_export_test` fingerprint `a77b0943…` exactly (§2 of
`01-readonly-transaction-and-preservation.md`).

## 6. Files created by this task

- `docs/evidence/2026-11-15-stage-2-verification/00-verification-scope.md`
- `docs/evidence/2026-11-15-stage-2-verification/01-readonly-transaction-and-preservation.md`
- `docs/evidence/2026-11-15-stage-2-verification/01a-source-not-found-inventory.tsv`
- `docs/evidence/2026-11-15-stage-2-verification/01b-readonly-probe.txt`
- `docs/evidence/2026-11-15-stage-2-verification/01c-cohort-fingerprints.txt`
- `docs/evidence/2026-11-15-stage-2-verification/01d-row-level-diff.txt`
- `docs/evidence/2026-11-15-stage-2-verification/02-preexisting-failure-verification.md`
- `docs/evidence/2026-11-15-stage-2-verification/02a-source-fetch-failure-pre-stage2.log`
- `docs/evidence/2026-11-15-stage-2-verification/02b-source-fetch-failure-head.log`
- `docs/evidence/2026-11-15-stage-2-verification/03-continuation-reclassification.md`
- `docs/evidence/2026-11-15-stage-2-verification/03a-continuation-pre-stage2.log`
- `docs/evidence/2026-11-15-stage-2-verification/03b-continuation-head.log`
- `docs/evidence/2026-11-15-stage-2-verification/04-additive-change-verification.md`

Modified: `docs/reports/2026-11-15-stage-2-event-importer-fetch-reconciliation.md`
(one appended, clearly labelled post-verification section; no existing text
changed).

## 7. Credentials

No credential value appears in this directory. The MySQL administrative password
and the local application password are read from `.env` at invocation time and
passed through the environment; only the account name (`root@localhost`) and the
server version are recorded.

## 8. One external-write disclosure, stated up front

Between the Stage 1 capture (`2026-11-15`, ~10:35 UTC) and this verification
(`2026-11-15`, 15:47–16:00 UTC) the **shared database changed**, and the change
is not attributable to Stage 2 or to this task:

- An externally triggered **"Run All" import** ran `14:01–14:10:57 UTC`
  (parent/child run group `source-ce1a8ed6-fd98-4fe3-b45e-cba7191a10f1`). It
  created 56 events (54 `eventbrite_dublin_events_this_week`,
  2 `eventbrite_wicklow_events_today`) and updated 4 pre-existing rows
  (`133818`, `171003`, `136014`, `136036`). The importer's own log records it;
  the newest entry in `conexao_event_import_log` is `2026-11-15 14:10:57`.
- It finished **before Stage 2's first code edit** (earliest Stage 2 file mtime
  `14:15:13`) and long before the Stage 2 commit (`15:21:17`), so it executed
  pre-Stage-2 code.
- It is the same category of event Stage 0.5 recorded at `07:42–07:45` and
  classified as an external admin-triggered import, not as session activity.

Consequences, applied consistently throughout this directory: the all-event
digest/count and the six-external-event cohort differ from their Stage 1 values,
and both differences are fully accounted for by this documented external import
(§4–§5 of `01-readonly-transaction-and-preservation.md`). No shared-database
write occurred during Stage 1's, Stage 2's or this task's windows.

_Last verified: 2026-11-15 by the Stage 2 post-implementation verification_
