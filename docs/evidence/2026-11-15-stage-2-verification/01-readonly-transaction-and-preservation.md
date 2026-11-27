# Item 1 — engine-enforced read-only transaction path and shared-database preservation

**Attempted: yes. Verified: yes. Captures taken: yes.**
Raw transcripts: `01b-readonly-probe.txt` (probe), `01c-cohort-fingerprints.txt`
(captures and hashes), `01a-source-not-found-inventory.tsv` (full inventory),
`01d-row-level-diff.txt` (row-level diff).

## 1. The enforcement probe

Run **before any data query**, on the account available through
`docker compose exec -T db mysql -uroot`:

```sql
SET SESSION TRANSACTION READ ONLY;
START TRANSACTION READ ONLY;
SELECT @@transaction_read_only;                                    -- 1
UPDATE wordpress.wp_posts SET post_title = post_title WHERE 1 = 0;
```

Result (exit status 1):

```
session_txn_ro_inside_txn
1
ERROR 1792 (25006) at line 4: Cannot execute statement in a READ ONLY transaction.
```

The `WHERE 1 = 0` predicate matches no row, so the rejection happened at
statement validation, not after touching data. `SQLSTATE 25006` / error `1792` is
the engine's read-only-transaction rejection, **not** an access-denied error
(`1142`), so this is transaction-state enforcement and not a privilege
restriction. The client aborted the script at the error, so the closing
`ROLLBACK` never ran and no `COMMIT` was issued: the session closed and ended the
transaction.

The identical probe was re-run after all captures and produced the same
`ERROR 1792`, confirming enforcement was in effect for the whole capture window.

## 2. Why the Stage 2 report's conclusion was wrong

The Stage 2 report and `docs/evidence/2026-11-15-stage-2/00-baseline-and-scope.md`
§4 concluded that "no verified read-only method exists" because the only
accounts were the application account (forbidden) and `root`, and because
"creating/altering accounts or privileges is forbidden". That conflates:

| Action | Stage 2 rule | Status |
|---|---|---|
| Create/grant a read-only account (`conexao_ro`, as Stage 1 did) | forbidden | correctly avoided — no account was created |
| **Use an existing administrative account inside a read-only transaction** | not forbidden | **available, not attempted — this task used it** |

The path was available, required no access-control change, and satisfies the
task's §3.7 requirement ("an engine-enforced read-only transaction... only when
its read-only enforcement can be verified empirically before the first query").
The Stage 2 limitation was a limitation of the attempt, not of the environment.

## 3. Method and calibration

Every dataset is one `SELECT` inside its own `SET SESSION TRANSACTION READ ONLY;`
`START TRANSACTION READ ONLY; … ROLLBACK;` session. Serialization: tab-separated,
LF, no header, SHA-256 over `mysql --batch --raw --skip-column-names` output.

Calibration before trust: the 275-row `stage32_export_test` cohort fingerprint
reproduced the value recorded by Stage 0.5 and Stage 1 **exactly**
(`a77b0943…`), and the all-event digest query shape is the one the Stage 1
reconciliation validated against its own recorded value. The method is therefore
proven, not assumed.

## 4. `source_not_found` inventory (new baseline)

Scope: `wp_posts` where `post_type='event'` **and** the post has
`_event_status='source_not_found'`, joined to `_event_source`,
`_event_source_id`, `_event_url`; columns `ID, post_title, _event_source,
_event_source_id, _event_url, _event_status`; `ORDER BY ID`; tab-separated;
no header; SHA-256.

| Metric | Value |
|---|---|
| Rows | **106** |
| Bytes | 19,838 |
| SHA-256 | `f1d3eda8e4f1f0f6fbb1e10ca0a237bb715246282adc0f8d3c18475fdd3c74c4` |
| `post_status` of every row | `publish` (106/106) |

By `_event_source`: `eventbrite_meath` 22, `eventbrite_laois` 21,
`eventbrite_kerry` 15, `eventbrite_mayo` 14, `eventbrite_westmeath` 7,
`eventbrite_clare` 6, `eventbrite_cork` 5, `eventbrite_leitrim` 5,
`eventbrite_cork_events_tomorrow` 5, `eventbrite_offaly` 4, `(none)` 1,
`eventbrite_mayo_events_tomorrow` 1.

Whole-database `_event_status` distribution for reference: `published` 3,228,
`(no status meta)` 347, `source_not_found` 106, `expired` 71, `rejected` 9.

The full 106-row list with titles and URLs is `01a-source-not-found-inventory.tsv`.
**No status was changed, and none was "restored".**

*Encoding note:* the capture is the raw client output, so a few titles carry
non-UTF-8 bytes exactly as stored in the database (e.g. `…gra` for `Ógra`). This
matches the existing Stage 0.5 raw TSVs and keeps the file hash-verifiable
(`f1d3eda8…`); it has not been sanitised.

### 4.1 Comparison with the 2026-10-03 figure (18 events)

| | 2026-10-03 diagnosis | This capture (2026-11-15) |
|---|---|---|
| Count | 18 | 106 |
| Sources | 12 `laois_tourism`, 5 `eventbrite_cavan`, 1 other | 12 current `eventbrite_*` keys; `laois_tourism` **0**, `eventbrite_cavan` **0** |

The two figures are **not like-for-like**, for two independently checkable
reasons:

1. **The source keys changed.** The 2026-10-03 capture is keyed on
   `laois_tourism` and `eventbrite_cavan`. Today the shared database has 3 events
   with `laois_tourism`, 33 with `eventbrite_laois`, 34 with `eventbrite_cavan`
   and 1 with `g2_cavan_probe`; **neither `laois_tourism` nor `eventbrite_cavan`
   has a single `source_not_found` row**. That 18-record population was
   re-keyed, restored or removed between the two captures, so it cannot be
   subtracted from the current 106 as if it were the same population.
2. **Six weeks of absence reconciliation are unrecorded.** `mark_missing_events()`
   runs on every source run. The importer keeps only its last 100 history rows
   and last 2,000 log entries (current window: 2026-11-14 13:49 → 2026-11-15
   14:10:57), so the runs that added marks between 2026-10-03 and 2026-11-14 left
   no retrievable per-run record.

**Regression assessment.** The growth is **not attributable to Stage 1 or
Stage 2**, established independently of the count:

- The only path that writes `_event_status = 'source_not_found'` is
  `Conexao_Event_Status::set_status()` called from `mark_missing_events()`, which
  runs only inside a source import run.
- The newest entry in `conexao_event_import_log` and in
  `conexao_event_import_history` is **2026-11-15 14:10:57** — the external
  "Run All" — which is **before** Stage 2's first code edit (`14:15:13`), its
  first evidence file (`14:33`) and its commit (`15:21:17`). No importer run
  exists in either stage's window, so neither stage could mark anything in the
  shared database.
- `set_status()` writes meta only and never touches `wp_posts`, so a marking is
  invisible to the row-level diff in §5; the log/history window is the
  determining evidence here, and it is unambiguous.

The defensible statement: **106 is the new baseline; the difference from 18 is
explained by elapsed scheduled imports plus a source-key change, and Stage 1 /
Stage 2 involvement is excluded because no importer ran against the shared
database in either window.** It is **not** claimed that 18 → 106 was reconciled
run by run — it was not, and the retained logs cannot do it.


## 5. Preservation cohort fingerprints and delta versus Stage 1

| Cohort | Method | Current | Stage 1 / baseline | Delta |
|---|---|---|---|---|
| 60 incident rows (47 created + 13 updated) | 7 fields, `ORDER BY ID`, tab-separated | 60 rows, `dd49a34a…` | baseline enumeration (`11-shared-preservation-baseline.txt`) | **none — 60/60 rows byte-identical** (direct `diff`) |
| 60 incident postmeta | `post_id, meta_key, meta_value`, `ORDER BY post_id, meta_key` | **1,333** rows, `c70eae17…` | 1,333 rows, `5c8a3f54…` | count **identical**; content digest not comparable (baseline method undocumented) |
| 6 externally imported (133818, 171003, 171004, 171006, 171007, 171008) | 7 fields, `ORDER BY ID` | 6 rows, `b31be54a…` | Stage 1 recon. + 11-14 inventory | **2 rows differ**, `post_modified` only (133818, 171003), from the 14:01 external import; 4 unchanged |
| 275 `stage32_export_test` | documented selector, `(post_id, source_id)`, `ORDER BY ID` | 275 rows, `a77b0943…` | `a77b0943…` | **none — exact match** |
| 14 probe-associated | pattern `_event_source LIKE '%probe%'` | 14 rows, `77b87490…` | **no ID list, no fingerprint** | **no baseline for comparison** (pattern-based, count 14 matches Stage 1's pattern count) |

Per-cohort status in the terms the task requires:

- **60 incident rows — PRESERVED, proven directly.** Row-for-row identity with
  the authoritative baseline enumeration. The recorded baseline hash `12d4d9bc…`
  could not be reproduced because it documents neither ordering nor
  serialization; the substitute is stronger, not weaker: a digest can collide or
  be computed over a different column set, whereas 60/60 identical rows with
  identical `post_modified_gmt` values also proves no write touched them.
- **275 `stage32_export_test` — PRESERVED, fingerprint-identical** to Stage 0.5
  and both Stage 1 captures. Membership vs the 285 clone leftovers stays
  `UNRESOLVED — INSUFFICIENT IDENTITY EVIDENCE`; that question is untouched.
- **6 externally imported — DIFFERS from Stage 1 by 2 rows**, and the difference
  is the documented external import at 14:01 (cork source refreshed 133818 and
  171003). Content, source identity and source IDs are unchanged; only
  `post_modified` moved. Not a Stage 1/Stage 2 write.
- **14 probe-associated — NO BASELINE FOR COMPARISON.** Pattern-based only. It
  is *not* reported as preserved by count alone.

## 6. Row-level diff — the strongest available preservation evidence

The Stage 1 reconciliation recorded that a direct row-level diff was impossible
("no before-edit row inventory exists"). That is no longer true: the retained
Stage 0.5 inventory
`docs/evidence/2026-11-14-stage-0-5/09-event-content-after-unexpected-change.tsv`
(3,687 rows, 2026-11-14 21:34 UTC) has exactly the six columns of the all-event
query, so it can be diffed against current state.

| Result | Count |
|---|---|
| Unchanged | 3,682 |
| **CHANGED** | **5** — each differs only in `post_modified_gmt` |
| **NEW** | **74** — 13 @ 2026-11-14 21:xx, 5 @ 2026-11-15 07:xx, 56 @ 2026-11-15 14:xx |
| **DELETED** | **0** |

All five changes are importer writes at `2026-11-14 21:42` (two runs after the
snapshot) or `2026-11-15 14:01–14:08` (the external Run All). All 74 new rows are
created outside Stage 1's and Stage 2's windows.

**No event post row was created or modified inside Stage 1's window
(09:34–13:50) or Stage 2's window (14:15–15:21).**

## 7. All-event digest (new baseline only)

The before-edit method is still unknown (`UNRESOLVED`, Stage 1). The *after*
method is established and was used to record a new, reproducible baseline:

| Form | Rows | SHA-256 |
|---|---|---|
| `ID, post_status, post_date, post_modified, post_name, post_title` (Stage 1's validated method) | 3,761 | `5ae73e62…` |
| same, GMT columns | 3,761 | `e0454927…` |

Neither is compared to Stage 1's `4ec41dc2…` (3,705 rows): the population differs
by the 56 externally created events plus the two `post_modified` bumps, all
accounted for in §6. **This does not reconcile the Stage 1 before/after
question, and no such claim is made.**

## 8. Postmeta identity check

`73,759 − 1,628 = 72,131` and `72,527 − 396 = 72,131`: the postmeta held by
pre-existing events is identical at the Stage 1 capture and now. No postmeta row
was inserted into or deleted from any pre-existing event. (Values can still have
been updated in place and are not proven unchanged by this identity — see §9.)

## 9. What this capture does and does not prove

Proved:

- A verified read-only path exists and was used; the probe is engine-enforced.
- The `source_not_found` inventory is captured in full (106) as a new baseline.
- The 60-row incident cohort is **byte-identical** to its authoritative baseline.
- The 275-row `stage32_export_test` cohort is **fingerprint-identical**.
- No event post row and no postmeta row count changed inside Stage 1's or
  Stage 2's window; every change is attributed to a documented importer run.

Not proved, and not claimed:

- That 18 (2026-10-03) → 106 (now) is a like-for-like delta. It is not: the
  source keys changed and the per-run records are outside the retained window.
- That every `_event_status` *value* of pre-existing events is unchanged. Status
  changes write meta in place and leave no timestamp; the exclusion rests on the
  absence of any importer run in either stage's window, not on a row-level
  postmeta diff.
- Anything about the 14 probe-associated events beyond their cardinality.

_Last verified: 2026-11-15 by the Stage 2 post-implementation verification_

