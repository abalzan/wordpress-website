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

---

## 13. Post-Stage-3 investigation and restoration plan (Stage 3.5, appended)

**Appended 2026-11-15 by Stage 3.5.** This section is **additive**. Nothing above
it was rewritten, and the Stage 3 verdict, its findings and its limitation list
stand exactly as recorded. Where Stage 3.5 reaches a different conclusion about
*attribution*, that is recorded here as a subsequent finding, not as an edit to
Stage 3's.

**Evidence:** [`docs/evidence/2026-11-15-stage-3-5/`](../evidence/2026-11-15-stage-3-5/00-scope-and-baseline.md)
— scope/baseline, attribution capture (5 TSVs), the 80-row cohort inventory,
connected-failure analysis, datetime-contract analysis, the restoration plan,
read-only proof and preservation confirmation. Read-only; no event, postmeta,
status, source entry, continuation, log or history record was modified.

### 13.1 Headline results

| Item | Stage 3.5 result |
|---|---|
| The 80-event current/future cohort | **All 80 accounted for, one row each, zero baseline drift** — still `source_not_found` with a current/future date at 19:22Z |
| Attribution of the 14:01–14:10 executions | **`CONSISTENT WITH SHUTDOWN-TRIGGERED EXECUTION; SPECIFIC TRIGGER UNVERIFIED`** — WordPress cron is ruled out mechanically; no direct shutdown indicator exists and the historical cron option was never observed |
| Connected-failure hypothesis | **REFUTED as stated.** Links 1–3 hold; link 4 ("incomplete run marked events") is contradicted by the code's own gates *and* by the retained log; and 76 of the 80 events sit in sources that did not run in the window at all |
| Datetime contract violation | **A class of 20**, all synthetic CI fixtures written by `scripts/bootstrap-ci-fixtures.php:996-1001`; the importer's `normalize_date()` cannot produce the shape. Post `115352` is a fixture (`Evento sintético removido na fonte`), not a real event |
| Restoration | **Planned, not executed.** 76 Priority 1 (upstream verification required) + 4 Priority 2 (conditional, `eventbrite_cork`) + 0 lower tiers. **No restoration is recommended as safe on Stage 3.5's evidence** |
| Stranded continuations | **16**, not 15 — `eventbrite_cork` stranded at 18:35Z during this session. Surfaced as a separate item needing its own authorisation |

### 13.2 The one Stage 3 statement that Stage 3.5 does not support

Stage 3 §8.1 reads the 14:00–14:10 continuation chain as the explanation for the
suppressed events of the nine affected sources. Stage 3.5 finds that **only one of
the nine window sources (`eventbrite_cork`) is an affected source**, and that the
other eight affected sources' `last_import` values are **2026-11-14 14:02–14:31** —
a day earlier. Two different sets of nine were conflated. The suppression of those
76 events therefore remains **unexplained by the retained record**: their runs fall
before the oldest retained log entry (`2026-11-14 14:43:00`) and history row
(`2026-11-14 19:29:49`), so the evidence was evicted, not merely overlooked.

### 13.3 Git and deployment reconciliation (correction note)

Stage 3 §12 recorded: *"HEAD unchanged at `2704169`; nothing staged, committed,
pushed, merged, deployed or activated… Untracked: the new suite, the new report and
`docs/evidence/2026-11-15-stage-3/`."*

**That statement was accurate at the time of writing; the state changed
afterwards.** It is not a case of "the report was wrong when written".

| Event | Timestamp (UTC) |
|---|---|
| Stage 3 baseline HEAD `2704169` | 2026-11-15 17:42:29 |
| Last Stage 3 file written (`08-quality-gates.txt`) | 2026-11-15 **18:26:54** |
| Stage 3 report last modified | 2026-11-15 **18:25:38** |
| **Commit `77f09a3` authored and committed** | 2026-11-15 **18:28:35** |
| Stage 3.5 start (clean worktree at `77f09a3`) | 2026-11-15 19:21 |

The commit lands **1 min 41 s after the last Stage 3 file was written** and
contains exactly Stage 3's artefacts (12 evidence files, this report, the plugin
code, four test files, the docs indexes). Between that session and Stage 3.5's
execution the work was committed and pushed to `event-import-improvements`. This
note records the current state without modifying the original observation. **The
Stage 3 findings and verdict are unaffected.**

**Pushed is not deployed.** The two are recorded separately:

| | State |
|---|---|
| Branch code | `77f09a3e4ad84e0ea191123b287f76fbd7bd554c`, clean worktree, plugin version `1.10.0` |
| Local shared stack (`wordpress` DB) | **Runs `77f09a3`'s code** — verified by SHA-256 of three plugin files inside the running container, identical to the repository, and the plugin is in `active_plugins`. That is file-hash evidence, not inference from the push |
| **WordPress.com production** | **DEPLOYMENT VERSION UNVERIFIED.** No CLI, SSH, filesystem or database access exists from this repository, so no claim is made that Stage 3's P12/P13 code is live on production |
| Observed runtime retention | log **2,104** entries (bound at HEAD 8,000), history **56** rows (bound 1,200 / 50 groups). Both are below every candidate bound, so the *deployed* cap cannot be proven from counts; the only arithmetic fact is that a 2,000-entry store cannot hold 2,104 entries, so Stage 3's 2,000-entry bound was not binding at 19:22 |

### 13.4 No commit from Stage 3.5

Stage 3.5 performed **no** commit, push, merge, deploy, activation or release. Its
evidence and documentation remain as working-tree changes for review.


---

## Stage 4 — Event quality: metadata, review queue and datetime contract cleanup (2026-11-16)

Stage 4 is a forward-looking quality change. It does not resolve Stage 3.6's
Eventbrite WAF block, does not restore any of the 76 HIGH + 4 CONDITIONAL
suppressed events, and does not touch the 16 stranded continuations. Its
findings and verdicts are recorded in
[`docs/evidence/2026-11-16-stage-4/`](../evidence/2026-11-16-stage-4/).

### What Stage 4 did

1. **Read-only fetch-path assessment.** All eight Stage 3.6 Eventbrite target
   sources (`offaly`, `meath`, `laois`, `mayo`, `kerry`, `westmeath`,
   `clare`, `leitrim`) are `ASSESSED — NOT VERIFIED`: their last-import
   timestamps (2026-11-14) predate the Stage 3.6 WAF refusal cutoff
   (`2026-11-15T20:34:29Z`), and no retained importer-side record shows a
   post-cutoff listing fetch or a post-cutoff WAF refusal for any of the
   eight. The post-cutoff HTTP 405 failures on four non-target Eventbrite
   sources (`waterford`, `louth`, `monaghan`, `roscommon`) are consistent
   with the Stage 3.6 WAF block but do not constitute importer-side WAF
   failure evidence for any of the eight target sources. No live request was
   made.

2. **Datetime contract cleanup.** `scripts/bootstrap-ci-fixtures.php` now
   writes a date-only `Y-m-d` into `_event_date` (the `post_date` keeps its
   full datetime for the deterministic upsert property). A defensive
   write-path gate (`Conexao_Event_Date_Contract`) reduces any
   datetime-shaped value to its leading `Y-m-d` at the engine write boundary,
   so a future writer can never silently persist a datetime. The 20 existing
   fixture violations (all synthetic CI fixtures) are reported and remain
   unmodified. Verified: 18 passed, 0 failed.

3. **P9 provider-specific metadata.** Motorsport Ireland gains per-event
   image extraction from the listing card's thumbnail column (site chrome
   rejected by marker). IVVCC has no retained HTML (`NOT VERIFIED — NO
   RETAINED SOURCE HTML`). Mondello Park's retained fixture is REST JSON, not
   detail HTML (`ASSESSED — NO EXTRACTABLE FIELDS IN RETAINED EVIDENCE` for
   the detail-page fields; REST-level fields already correct). Verified: 93
   passed, 0 failed.

4. **Read-only review queue.** A "Needs Review" submenu under Event Sources
   surfaces published events missing a featured image, venue or county. It is
   read-only, capability-gated, nonce-protected (read-only GET), escaped, and
   bounded by pagination. The category field is omitted while P8 is deferred.
   Verified: 34 passed, 0 failed.

5. **P8 category mapping.** `DEFERRED — VOCABULARY NOT SUPPLIED`. No approved
   vocabulary was supplied; no mapper, taxonomy term or event assignment was
   changed.

### What Stage 4 did not change

- No event post, postmeta, `_event_status`, source registry entry, log/history
  row, continuation record, cron entry or preservation-cohort record was
  modified.
- No Stage 1/2/3 engine path, Eventbrite path, P1/P12/P13 code or Stage
  3/3.5/3.6 evidence was modified.
- No provider HTTP request was made.
- No commit, push, merge, deploy, activation or release was performed.

### Verdict

**Prior claim (superseded):** `PASS WITH LIMITATION — P8 DEFERRED`

**Corrected verdict:** `BLOCKED — TEST SAFETY GATE NOT SATISFIED`

The mandatory test/safety gate is `NOT PASSED — SAFE ISOLATED ENVIRONMENT
UNAVAILABLE`. Per the Stage 4 correction task §11, an unavailable isolated
test environment is a **BLOCKED** condition, not a limitation that can
support `PASS WITH LIMITATION`. The overall verdict must be `BLOCKED — TEST
SAFETY GATE NOT SATISFIED`, even if the code changes appear complete and
prior tests passed under non-qualifying credentials.

Individual workstreams may still be `IMPLEMENTED — VERIFICATION INCOMPLETE`
at the workstream level (implementation exists, verification blocked), but
the overall verdict cannot be `PASS` or `PASS WITH LIMITATION` while the
mandatory test-safety gate is unsatisfied.

### Post-isolation-fix reassessment (2026-11-16)

**Prior claim (superseded):** `BLOCKED — TEST SAFETY GATE NOT SATISFIED`

**Updated verdict:** `BLOCKED — VERIFICATION INCOMPLETE`

A schema-restricted test account `conexao_test`@`172.18.%` was created with
explicit privileges on `conexao_stage05_test_5bd9ece5c032` only. The five
empirical isolation checks prove the account can use the test schema but
cannot read or write the shared `wordpress` schema (both denied with MySQL
error 1142). The actual PHP test connection is `conexao_test@172.18.%` on the
test schema. The credential-isolation gate is now satisfied.

However, the required test rerun is `FAILED`: two suites show failures that
differ from the prior baseline.

1. `test-event-review-queue.php`: 2 new D2 failures (multi-page drill-in
   consistency). The prior Stage 4 run reported 34/0; the new run reports
   36/2.
2. `test-stage1-run-coordination.php`: 3 P2 failures (Heritage date gate).
   The Stage 1/3 baseline was 47/0; the new run reports 44/3. The failures
   are caused by a date-dependent fixture ("Today event" = 15 November 2026)
   that is now in the past relative to the current date (16 November 2026).

The operator-log isolation gate ran under the restricted credential and
passed (366/0). Operator-log integrity for this test run is verified.

The CI integration job does not currently provision
`CONEXAO_TEST_DB_USER` / `CONEXAO_TEST_DB_PASSWORD`; CI test runs will fail
until the CI environment is updated. CI update is out of scope for this task
and should be tracked as a follow-up.

This infrastructure verification does not resolve the outstanding Stage 4
findings (Needs Review menu placement, Motorsport Ireland P9 six-field
assessment, per-source datetime semantics, IVVCC/Mondello retained detail
HTML, P8 vocabulary). Those remain outstanding.

See `docs/evidence/2026-11-16-stage-4/06-tests-and-gates.md` §9,
`docs/evidence/2026-11-16-stage-4/08-known-limitations.md` §9, and
`docs/evidence/2026-11-16-stage-4/09-test-isolation-infrastructure.md`.

### Carry-forwards unchanged

- Stage 3.6 WAF block: `BLOCKED — CANARY MECHANISM FAILED` (unchanged).
- 76 HIGH + 4 CONDITIONAL suppressed events: `VERIFICATION-INCOMPLETE`
  (unchanged).
- 16 stranded continuations: unresolved (unchanged).
- 285-post clone-cleanup population: `UNRESOLVED — INSUFFICIENT IDENTITY
  EVIDENCE` (unchanged).
- 20 existing datetime fixture rows: reported, not modified (unchanged).

### Post-isolation-remediation closeout (2026-11-16)

**Prior claim (superseded):** `BLOCKED — VERIFICATION INCOMPLETE`

**Updated verdict:** `PASS WITH LIMITATION`

The test-isolation remediation is complete and proven:

- **Grant compliance:** The excess privileges (EXECUTE, CREATE VIEW, SHOW
  VIEW, CREATE ROUTINE, ALTER ROUTINE, TRIGGER) were revoked from
  `conexao_test`@`172.18.%`. The effective grants are now exactly the eleven
  authorised privileges plus implicit `USAGE`.
- **Password handling:** The `conexao_test` password was rotated via
  `ALTER USER` using a protected temporary SQL file. The new credentials are
  stored in `.conexao-test.env` (mode 0600, git-ignored). No password appears
  in any command argument, Docker `-e` value, log, or report.
- **`wp-config.php` environment-overridability:** CONFIRMED —
  `DB_NAME`/`DB_USER`/`DB_PASSWORD`/`DB_HOST` are resolved from
  `WORDPRESS_DB_*` environment variables via `getenv_docker()`.
- **Pre-WordPress environment setup:** The runner copies `.conexao-test.env`
  into the container and invokes `scripts/conexao-test-exec.sh`, which
  sources the secret file, exports `WORDPRESS_DB_*`, and `exec`s PHP before
  `wp-config.php` runs. No secret value appears in the host-side command line.
- **Actual `$wpdb` isolation:** A fail-closed identity guard in
  `tests/bootstrap.php` queries `USER()`, `CURRENT_USER()` and `DATABASE()`
  through the actual `$wpdb` instance. Every required suite ran with
  `WPDB_IDENTITY=PASS login_user=conexao_test@172.18.0.3
  matched_account=conexao_test@172.18.%
  selected_database=conexao_stage05_test_5bd9ece5c032`.
- **Isolation proof:** Positive read on the disposable schema succeeds;
  negative read and write on `wordpress.wp_posts` are denied; DDL/DML probe
  table create/insert/update/delete/drop succeeds and is cleaned up.
- **Operator-log isolation:** PASSED (376/0). The gate only inspects the test
  schema (Case B), compatible with the restricted credential.

All twelve required suites ran sequentially under the verified `$wpdb`
isolation:

| Suite | Result |
|---|---|
| test-event-review-queue.php | 38 passed, 0 failed |
| test-partial-enrichment-preservation.php | 42 passed, 0 failed |
| test-import-history-dashboard.php | 157 passed, 0 failed |
| test-past-event-filter.php | 29 passed, 0 failed |
| test-event-cleanup-purge.php | 749 passed, 0 failed |
| test-import-continuation.php | 262 passed, 22 failed (baseline) |
| test-town-sanitization.php | 82 passed, 1 failed (baseline) |
| test-source-fetch-failure.php | 136 passed, 0 failed |
| test-eventbrite-rate-limit.php | 87 passed, 0 failed |
| test-stage1-run-coordination.php | 47 passed, 0 failed |
| test-eventbrite-checkpoint.php | 91 passed, 0 failed |
| test-operator-log-isolation.php | 376 passed, 0 failed |

**D2 diagnosis and fix:** The `field_counts()` method used direct SQL over all
published events, while `query_population()` used `WP_Query` which applies the
event runtime's `_event_status` filter and Polylang's language filter. This
caused the count and list to diverge. Fixed by resolving the population
through a single `WP_Query` with `posts_per_page => -1` in `field_counts()`.
The D2 test was also corrected to recompute `field_counts()` after creating
its multipage fixtures. Suite passes 38/0.

**Stage 1 fixture hardening:** The P2 fixture dates were changed from
hard-coded calendar dates to dates relative to the current site-local date
(`wp_timezone()`), formatted as "DD Month YYYY" to match the Heritage Week
source's date parser. Suite passes 47/0.

**Menu placement:** `AMBIGUOUS — OWNER DECISION REQUIRED`. There is no
distinct "Event Sources" top-level menu to use as the Needs Review parent.
Needs Review is a sibling of Event Sources under the Event Import dashboard.
Owner decision required.

**CI isolation limitation:** The CI workflow still runs WordPress/DB tests
with the over-privileged `wordpress` credential. Fixing CI provisioning is
out of scope for this task. CI test runs do not qualify as isolation-compliant
until the CI follow-up lands.

**Remaining Stage 4 findings:** Motorsport Ireland P9 six-field assessment,
per-source datetime conventions, IVVCC/Mondello retained detail HTML, and P8
vocabulary remain unresolved or deferred. These are scope-external or
owner-deferred.

See `docs/evidence/2026-11-16-stage-4/06-tests-and-gates.md`,
`docs/evidence/2026-11-16-stage-4/08-known-limitations.md`,
`docs/evidence/2026-11-16-stage-4/09-test-isolation-infrastructure.md`, and
`docs/evidence/2026-11-16-stage-4/10-d2-diagnosis.md`.

### Final Stage 4 closeout finalisation (2026-11-16)

The previous `PASS WITH LIMITATION` and `D2 resolved` statements in this
report are superseded by the controlled closeout evidence. The repository was
re-checked on `event-import-improvements` at HEAD `7f6850f`; Stage 3.6 evidence
remained unchanged and prior worktree changes were preserved.

- Isolation: **PASSED**. Fresh grants matched the authorised eleven privileges
  plus implicit `USAGE`; the actual `$wpdb` identity was
  `conexao_test@172.18.%` on the disposable schema; the existing 9-probe proof
  passed; and the absent-secret wrapper path failed closed with exit 1.
  Exposure of the active rotated credential is **not established**; the
  credential candidate in prior history is recorded separately and no rotation
  was performed here.
- D2: the current class has no lingering `suppress_filters` argument or
  explicit `_event_status` filter residue, so no cleanup was made. The fresh
  review-queue suite passed `38/0` in 522 seconds, but the historical
  count/list mismatch, contradictory experiment outcomes and later timeout
  leave the design diagnosis **INCONCLUSIVE — UNRESOLVED**. No D2 repair was
  attempted.
- Menu: the exact tree remains Event Import → sibling Event Sources and Needs
  Review, with Needs Review registered under Event Import. Disposition:
  **AMBIGUOUS — OWNER DECISION REQUIRED**. No menu change was made.
- Stage 1: the existing site-local relative-date helper was verified as
  parser-compatible (`j F Y`) and unchanged; the focused suite passed `47/0`.
- Twelve-suite rerun: all twelve completed under the guard. Ten passed; the
  two documented baseline suites remained `262/22` and `82/1` with the same
  assertion signatures. Aggregate: `2,096 passed, 23 failed`; gate:
  **BASELINE-CONSISTENT**. Pre/post read-only counts were unchanged at 3,510
  published events and 457 venue-missing events; the venue-missing count shows
  material drift versus the prior same-definition 511 observation.

### Superseding Stage 4 disposition

**Final Stage 4 programme verdict: BLOCKED — D2 UNRESOLVED AND MENU PLACEMENT
AMBIGUOUS.** The full Stage 4 programme is not closed. P8 vocabulary, MI
six-field assessment, per-source datetime conventions, IVVCC/Mondello retained
detail evidence, CI isolation provisioning, the Stage 3.6 WAF block and 16
stranded continuations remain carried forward or deferred as documented; no
owner acceptance is inferred.

The closeout task itself is **COMPLETE WITH LIMITATIONS** because the authorised
reconciliation and fresh runs are complete, while the programme blockers and
the documented baseline failures remain unresolved.

Updated evidence: `docs/evidence/2026-11-16-stage-4/04-review-queue.md`,
`06-tests-and-gates.md`, `08-known-limitations.md`,
`09-test-isolation-infrastructure.md` and `10-d2-diagnosis.md`.

_Last verified: 2026-11-16 by Codex — Stage 4 closeout finalisation_
