# Stage 3 — Event Importer diagnostics, continuation correlation, source health and run retention

**Date:** 2026-11-15
**Branch:** `event-import-improvements`
**Baseline HEAD:** `270416911f918a849e34f77cacfc4d7d19925ad6` (clean worktree)
**Roadmap items:** P12 (source health and continuation diagnostics), P13 (logical-run-aware
history and log retention), plus the read-only `source_not_found` investigation.
**Out of scope and not implemented:** P7, P8, P9, P11, Stage 4, Stage 5.

> **Note on the referenced audit/roadmap report.** The task asks for a Stage 3
> section to be added to “the 2026-11-14 report titled approximately *Event
> Importer — Final Audit and Implementation Roadmap*”. **No such file exists in
> this repository** (`docs/reports/` and the whole of `docs/` were searched for
> the title and for the P-numbers; the Stage 2 report recorded the same finding).
> Rather than invent a file, this report is the Stage 3 record and
> `docs/reports/README.md` gains the index entry. Every earlier Stage 0, 0.5, 1,
> 1-reconciliation and 2 document is untouched and none of their verdicts was
> altered.

---

## 1. Result summary

| Area | Result |
|---|---|
| **P12 — continuation correlation** | **PASS.** Every lifecycle entry now carries source, child `source_run_id`, parent `run_group_id`, `continuation_id` and a lifecycle word; the four identifiers are persisted in the record and survive schedule → deferral → resume → clear. Proven by a new suite (152 assertions). |
| **P12 — status truthfulness** | **PASS.** An incomplete enumeration is `partial`, never `warning`; a completed walk with a caveat stays `warning`; `rate_limited`/`failed` are never downgraded; the stop cause is a separate field; the registry maps every status truthfully. |
| **P12 — cooldown / budget explanations** | **PASS.** time budget, page cap, request budget, rate-limit cooldown, deferred Retry-After and upstream fetch failure are distinct, named reasons. P1 behaved identically (breaker untouched). |
| **P12 — stuck continuations** | **PASS.** Read-only classification with a documented 3,600 s threshold; running-with-`run_at=0` surfaced; no-timestamp cases labelled indeterminate; diagnostics proven not to mutate either store. |
| **P12 — `events_imported`** | **PASS.** Relabelled `Created (last run)` with a documented meaning; stored value untouched; no migration. |
| **P12 — UI placement** | **PASS.** Operational detail in Import History + Event Sources. No Sources overview added to the main Import dashboard; the 14-column Import History table is unchanged. |
| **Read-only `source_not_found` investigation** | **PASS.** 106/106 still suppressed, 0 missing, 80 with a current/future date, per-source counts and candidate lists delivered. No status changed, no upstream request made. |
| **P13 — retention** | **PASS.** Group-aware retention in the existing stores (no custom table, no migration, no BLOCKED). Documented bounds 8,000 log entries / 1,200 history rows / 50 completed runs. Active runs never evicted; whole-group eviction only; idempotent; pressure reported. |
| **P13 — reconstruction, downloads, preview** | **PASS.** Full logical-run reconstruction and download (>50 entries, multiple children, chronological, manifest); inline preview always discloses shown/total/truncation/download. |
| **Preservation** | **PASS.** Stage 0 / 0.5 / 1 / 2 code, tests and evidence intact; operator-log isolation gate 237/0; no shared-database write. |

**Overall verdict: PASS**, with the limitations listed in §11.

---

## 2. What was actually wrong (measured, not assumed)

Read-only inspection of the shared database and of the code, before any edit:

| Observation | Value |
|---|---|
| Continuation lifecycle log entries | **159** |
| …of those carrying a `run_id` | **0** |
| Context keys those entries retained | only `reason`, `reason_code`, `cooldown_seconds` |
| Continuation records | **22**, of which **15 in `state=running` with `run_at=0`** |
| …with a populated `run_id` | **0** |
| Log entries overall | 2,000 (the old cap) spanning 23 hours |
| History rows | 100 (the old cap) |
| Registered sources | 154 → one Run All is 155 history rows and ~1,700–3,900 log entries |

Three of those are direct defects, and each is fixed:

1. the log store's allowlist discarded every correlation key except `run_id`, and
   `run_id` was never populated — so a continuation entry could not be traced to
   any run;
2. `add_incomplete_enumeration()` only ran `maybe_warn()`, so an unfinished walk
   was recorded as `warning` — the same word as a finished walk with a caveat;
3. the registry mapped every non-`failed` result to `success`, so a rate-limited
   or truncated run read as a clean success (confirmed live: seven of the nine
   sources in the investigation show `success` despite leaving live events
   suppressed).

---

## 3. P12 — continuation correlation

Every lifecycle transition now emits a correlated entry:

| Event | Where |
|---|---|
| created/scheduled | `schedule()` |
| deferred (cooldown) | `schedule()` / `run_due()` (a cooling record is reported, not re-logged) |
| started/resumed | `run_due()` |
| completed (`cleared`) | `clear()` |
| failed (scheduling / exception) | `schedule()` / `run_due()` |
| exhausted | `declare_exhausted()` |
| rearmed | `rearm_missing()` |
| stale/abandoned | **diagnostic only** — no log, no state change |

Identifiers: `run_id` (the owning History row: the parent group for a child of a
Run All, the standalone run otherwise), `source_run_id` (child attempt),
`parent_run_group_id` (logical Run All), `continuation_id` (stable per
lifecycle). Full matrix: `docs/evidence/2026-11-15-stage-3/01-continuation-call-site-inventory.md`.

**No new run ID is minted on resume**: the identifiers are read from the persisted
record. `clear()` passes the removed record explicitly so the terminal entry is
still correlated. Legacy uncorrelated entries stay labelled `legacy` and are
never given an invented ID.

---

## 4. P12 — statuses and diagnostics

### 4.1 Mapping (no new status word introduced)

| Target outcome | Child status | Parent status |
|---|---|---|
| Success | `success` | `completed` |
| Warning (non-fatal, walk completed) | `warning` | `completed_with_errors` / `completed` |
| Partial | `partial` | `partial` |
| Incomplete | `partial` (budget/page/cap/interruption) or `rate_limited` (P1 breaker) | `rate_limited` or `partial` |
| Failed | `failed` | `failed` |

**Warning ≠ incomplete.** `add_incomplete_enumeration()` now sets `partial`
(never downgrading `rate_limited`/`failed`); a new `add_warning()` keeps the
completed-walk caveat at `warning`. The checkpoint-resume caveat (the walk *did*
reach its final page) therefore stays `warning`, which is correct.

### 4.2 Parent aggregation — preserved, including the one documented difference

`aggregate_parent_status()` is **unchanged**: `failed > rate_limited > partial >
completed_with_errors > completed`. A queued or running continuation cannot make
an unfinished run look complete, because a parent only becomes terminal through
the final `record_parent()` call.

The target table in the task says a parent should be `failed` only when no child
produced a result. Stage 1 returns `failed` as soon as any child failed. That
behaviour is asserted by `test-stage1-run-coordination` (47/0) and is part of the
contract Stage 3 was told to preserve, so it is **documented, not changed**; the
parent row still discloses `failed_sources`, `planned_source_count` and the
aggregate counters.

### 4.3 Reason codes

`incomplete_reason` is a separate field on the result and on the history row, and
Import History renders it: `time_budget`, `page_cap`, `budget_exhausted`,
`rate_limited`, `rate_limited_deferred`, `fetch_failed`, `parse_failed`,
`checkpoint_resume`, `incomplete_enumeration`. The task's specific trap — a
rate-limit cooldown misreported as time-budget exhaustion — cannot occur: the
cooldown path never enters the time-budget branch, and the code shown is the one
the client/checkpoint recorded.

### 4.4 Stuck continuations

`classify()` returns `queued`, `deferred`, `running`, `running_stale`,
`running_indeterminate`, `exhausted`, `failed`. Threshold
`STALE_RUNNING_SECONDS = 3600` = 4 × `LOCK_TTL`; a held lock is never stale. The
`running`/`run_at=0` shape is reported as a **fact** and only becomes a stall when
the recorded `last_run_at` says so; with no timestamp it is
`running_indeterminate`, never “stale”. Diagnostics are proven read-only (both
stores byte-identical across the read).

### 4.5 `events_imported`

Stored value = **events created by this source in its most recent run**. The
column is now **`Created (last run)`** with a tooltip stating “not a cumulative
total”. No value was reset, no migration exists, no reader changed. The per-run
created/updated/skipped counters remain distinct in Import History.

---

## 5. Read-only `source_not_found` investigation

Full report: `docs/evidence/2026-11-15-stage-3/investigation-source-not-found.md`.

| Result | Value |
|---|---|
| Capture | `01a-source-not-found-inventory.tsv`, 106 rows |
| SHA-256 | `f1d3eda8e4f1f0f6fbb1e10ca0a237bb715246282adc0f8d3c18475fdd3c74c4` — **exact match** with the full digest recorded in the Stage 2 verification evidence |
| Fixed timestamp / timezone | `2026-11-15T18:20:00+00:00`, `Europe/Dublin` (offset +00:00 that day) |
| Date-storage contract | `_event_date` / `_event_end_date` are **date-only `Y-m-d`** (normalizer + data-model), verified before parsing |
| Still suppressed | **106** |
| No longer suppressed | **0** |
| Post missing | **0** |
| Indeterminate status | **0** |
| `wp_posts.post_status` | `publish` for all 106 (reported separately from `_event_status`) |
| Still suppressed, **current/future** date | **80** |
| Still suppressed, past date | 25 |
| Still suppressed, indeterminate date | 1 (post `115352`, `2026-11-13 00:00:00` — a datetime where the contract is date-only) |
| Active sources holding current/future suppressed events | **9** (meath 22, laois 17, mayo 14, kerry 7, westmeath 5, clare 5, cork 4, leitrim 4, offaly 2) |
| Sources whose last run was incomplete but recorded `success` | **7 of those 9** — the defect P12 fixes going forward |

**No status was changed. No upstream verification was performed. No restoration
is authorised.** Follow-up items requiring separate authorisation are listed in
the report (§10 there).

---

## 6. P13 — retention

**Architecture: per-store, group-aware retention inside the existing option
arrays. No custom table, no new option, no transient, no migration → P13 is not
BLOCKED.**

| Bound | Value |
|---|---|
| Log entries | 8,000 (`Conexao_Import_Log::MAX_ENTRIES`) |
| History rows | 1,200 (`Conexao_Import_History::MAX_ENTRIES`) |
| Completed logical runs | 50 (`Conexao_Import_History::MAX_GROUPS`) |

* **Unit:** the whole logical group (Stage 1's `parent_run_group_id` / `run_id`).
  Never split — no orphan parent, no orphan child, no lost beginning.
* **Eligibility:** terminal groups only (no `queued`/`running` row, no
  `scheduled`/`running` continuation). Active groups are never evicted, at any
  pressure.
* **Order:** oldest group first, with a store-position tie-break so runs written
  inside the same second still order deterministically. The newest completed
  group is never evicted to make room for itself.
* **Legacy:** rows/entries with no run identity are an explicit bucket, evicted
  individually and last, never grouped into an invented run.
* **Pressure:** if protected groups exceed the bound, nothing is discarded and
  `get_retention_state()` reports `pressure` + `over_by`; Import History prints
  it.
* **Idempotent:** a second pass is byte-identical (asserted).
* **Timing:** applied inside the existing write paths, which is safe because
  eligibility is state-derived, not time-derived. The 90-day prune was made
  group-safe and deliberately **not** wired.

Rationale, alternatives and the measured sizing:
`docs/evidence/2026-11-15-stage-3/05-retention-architecture.md`.

---

## 7. How the requirements were verified

`test-stage3-diagnostics-and-retention.php` — **152 assertions, 0 failed** — proves:

| Requirement | Section |
|---|---|
| every lifecycle entry carries source / child / parent / continuation id, and they survive schedule → resume | A1–A6 |
| incomplete is `partial`, warning is `warning`, `rate_limited`/`failed` not downgraded, cause preserved | B1–B5 |
| registry mapping is truthful for all five statuses and the "last successful import" excludes the others | B6–B8 |
| rendered labels and reason lines distinguish every outcome | B9–B10 |
| recent record not flagged; expired fixture flagged; `running`/`run_at=0` surfaced; no timestamp = indeterminate | C1–C5 |
| diagnostics mutate neither the continuation nor the checkpoint store | C6–C7 |
| `events_imported` is labelled as a last-run creation count; created/updated not collapsed | D1–D3 |
| a logical Run All (parent + 3 children + 60 entries) reconstructs, downloads in full, chronologically | E1–E4 |
| preview discloses shown/total/truncation and the full-download path in the rendered HTML | F1–F5 |
| legacy records stay legacy and are never given an invented ID | G1–G3 |
| retention evicts whole groups, protects active runs, is idempotent, reports pressure | H1–H6 |
| history retention removes a logical run as one unit and protects an active oldest run | I1–I4 |

---

## 7.1 Test results (authoritative)

```
CONEXAO_TEST_DB_NAME=conexao_stage05_test_5bd9ece5c032 \
CONEXAO_TEST_SHARED_DB_NAME=wordpress CONEXAO_TEST_WORKERS=1 \
./scripts/run-tests.sh --php --only conexao-event-importer

In-process PHP suites: 43 total, 41 passed, 2 failed
Assertions:            4,672 passed, 23 failed
```

| Failing suite | Failures | Stage 3 regression? |
|---|---:|---|
| `test-import-continuation.php` | 22 | **No.** Identical set to the Stage 2 verification baseline at `b5a3ee9` and `3f6141a` (262/22); a Stage 1 carry-over. |
| `test-town-sanitization.php` | 1 | **No.** Documented fixture issue (four legacy clone terms). |

`22 + 1 = 23`: every failing assertion is one of the two documented pre-existing
failures. **Zero new failures.**

### Suites whose expectations Stage 3 updated (all strengthened, none weakened)

| Suite | Before → after | Why |
|---|---|---|
| `test-source-fetch-failure.php` | 122/10 → **136/0** | The ten failures encoded the **pre-Stage-1** contract (`429 ⇒ failed`). Stage 3 does touch the 429 path (the incomplete/rate-limit reason mapping), so — exactly as the task instructs — the test now asserts the **current documented contract**: `429 ⇒ rate_limited` + cooldown, incomplete enumeration, recorded cause. Every non-429 case still demands `failed` + `fatal_errors`. |
| `test-import-history-dashboard.php` | 156/1 → **157/0** | `MAX_ENTRIES`/`MAX_GROUPS` expectations moved to the documented Stage 3 bounds; the cap test seeds 1,200 rows in one write instead of 1,201 individual option rewrites (same property, and it removes the 512 MB OOM the larger bound exposed). |
| `test-import-history-logs.php` | 97/1 → **98/0** | One assertion: the log cap `2,000 → 8,000`. |
| `test-import-log.php` | 61/1 → **65/0** | The old cap test asserted the blind 2,000-entry slice, which Stage 3 removes. It now proves the documented group-aware contract: whole-group eviction, the oldest run dropped, and a single oversized run retained whole with pressure reported. |

No test was deleted, skipped or made vacuous.

---

## 8. Preservation and regression

| Stage | Verification |
|---|---|
| **Stage 0** probe deactivation | no probe reactivated; `g2_cavan_probe`/`g4c_budget_probe` not recreated; the 18 deactivated G1 probes untouched |
| **Stage 0.5** operator-log isolation | gate `242 passed, 0 failed` (14 checks) **after** implementation; bootstrap unchanged; no new store needed isolating |
| **Stage 1** P1 breaker | `class-eventbrite-pacing.php` and the Retry-After parsing: zero diff. `test-eventbrite-rate-limit` 87/0, `test-request-retry-policy` 59/0 |
| **Stage 1** P6 parent/child | `run_all()`, `record_parent()`, `refresh_parent()`, `aggregate_parent_status()`: zero diff. `test-stage1-run-coordination` 47/0 |
| **Stage 2** P3/P4/P5/P10 | `test-reconciliation-pagination` 57/0, `test-source-fetch-contract` 55/0, `test-partial-enrichment-preservation` 42/0, `test-source-not-found-reconciliation` 57/0 |
| **Stage 2 verdict** | unchanged — the Stage 2 report was not edited |

---

## 9. Database safety and no-write confirmation

* **Disposable database:** `conexao_stage05_test_5bd9ece5c032`, identity verified with
  `ISOLATED_DB_PREFLIGHT=PASS` before every WordPress-dependent suite (the runner
  re-runs the preflight per suite). No other database was used.
* **Shared-database access:** read-only only. `SET SESSION TRANSACTION READ ONLY;
  START TRANSACTION READ ONLY;` then `SELECT @@transaction_read_only` → **1**, in
  every batch. The engine-level enforcement transcript is the Stage 2
  `ERROR 1792 (25006)` probe (`docs/evidence/2026-11-15-stage-2-verification/01b-readonly-probe.txt`),
  **referenced, not repeated**. Query text: `docs/evidence/2026-11-15-stage-3/06-shared-db-readonly-and-attribution.md`.
* **No write of any kind** to the shared database: no `INSERT`/`UPDATE`/`DELETE`,
  no schema change, no account or privilege change, no event status change, no
  source registry change, no operator log/history change, no pruning.
* **No live provider request.** No import, Run All, continuation, cron event,
  AJAX endpoint or admin action was executed. All provider traffic in the tests
  is a `pre_http_request` double.
* **No commit, push, merge, deploy, activation or release.**

---

## 10. Stage 2 verification carry-forward

| Item | Result |
|---|---|
| Attribution of the 14:01–14:10 activity | **ATTRIBUTED.** It was **nine automatically executed continuation runs**, not a Run All: nine history rows, each with its own self-referencing parent group and **no parent row**, and paired `Continuation starting …` / `Continuation scheduled …` log entries. `wp_options.cron` holds **no** continuation event in 13:55–14:15, so the `shutdown` fail-safe (`run_due()`) is the more likely of the two server-side triggers. The Stage 2 report's phrase “an externally triggered Run All” is imprecise; its time/code-version bound stands. **The Stage 2 verdict was not edited.** |
| The 13 unexplained new events | **RESOLVED.** They are the 13 rows at `2026-11-14 21:xx`, i.e. created at/after the 2026-11-14 **21:34 UTC** inventory snapshot. `5 + 56 + 13 = 74` accounts for every new row. |

---

## 11. Known limitations

1. **`test-import-continuation.php` still fails 22 assertions** (`262 passed, 22 failed`,
   identical to the Stage 2 verification baseline at `b5a3ee9` and `3f6141a`). It
   is a Stage 1 carry-over, not a Stage 3 regression, and the failure set is
   unchanged. Stage 3's own continuation behaviour is covered by the new suite.
2. **`test-town-sanitization.php` still fails 1 assertion** (four legacy clone
   terms in `conexao_town`) — a documented fixture issue, untouched.
3. **The 15 `running`/`run_at=0` continuation records on the shared site were
   made visible, not recovered.** Resetting, retrying or rescheduling them is out
   of scope and unauthorised.
4. **80 currently-suppressed events have a current/future date.** They are
   candidates, not confirmed defects. Upstream verification and any restoration
   need separate authorisation.
5. **i18n catalogue not regenerated.** Stage 3 adds operator-facing strings to the
   importer plugin. The `i18n_freshness` gate compares **git commit time**, so it
   cannot flag uncommitted work (it reports the same three pre-existing stale
   catalogues as the baseline: theme, `conexao-admin-ux`, `conexao-commemorative`).
   `wp i18n make-pot` is **not available** in this environment (no WP-CLI in the
   WordPress container), so `wp-content/plugins/conexao-event-importer/languages/conexao-event-importer.pot`
   must be regenerated when the change is committed:
   `wp i18n make-pot wp-content/plugins/conexao-event-importer wp-content/plugins/conexao-event-importer/languages/conexao-event-importer.pot`.
6. **The referenced 2026-11-14 audit/roadmap report does not exist** in the
   repository, so its Stage 3 section could not be added to it; this report and the
   `docs/reports/README.md` index entry are the record.
7. **Legacy uncorrelated records remain uncorrelated.** 22 continuation records
   and 163 of the 2,000 retained log entries on the shared site carry no run
   identity. They are labelled `legacy` and were not given invented IDs.
8. **Retention cannot reconstruct what it never held.** The shared store's 2,000
   entries and 100 rows could not span the 2026-10-03 → 2026-11-15 interval, and
   Stage 3 does not attempt to.

---

## 12. Git state at the end

Branch `event-import-improvements`; HEAD unchanged at `2704169`; nothing staged,
committed, pushed, merged, deployed or activated. Modified: seven production PHP
files, one admin stylesheet, four test files and three documentation indexes.
Untracked: the new suite, the new report and `docs/evidence/2026-11-15-stage-3/`.
The exact list is in `docs/evidence/2026-11-15-stage-3/09-files-changed.md`. No file was deleted; no stash was
touched; no pre-existing work was discarded.
