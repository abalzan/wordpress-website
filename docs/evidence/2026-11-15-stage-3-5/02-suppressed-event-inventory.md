# Task B — the 80-event Stage 3 cohort, reconciled against current state

**Capture timestamp:** `2026-11-15T19:22:20Z` (inventory rows carry `observed_at`)
**Inventory:** `02-suppressed-event-inventory.tsv` — **exactly 80 data rows**, one
per Stage 3 cohort ID, plus a header row.
**Writes:** none. **Upstream requests:** none.

---

## 1. How the cohort was fixed (and why it is not re-derived)

The 80 IDs were extracted **mechanically** from Stage 3 report §7 (the "Events
still suppressed with a CURRENT OR FUTURE date" table, 80 rows), with a
grep-count assertion of 80 before extraction. They were *not* re-queried by date
predicate, so the cohort cannot drift with the clock.

The extraction reproduced Stage 3's per-source totals exactly:

| Source | Stage 3 §7 | Extracted |
|---|---:|---:|
| `eventbrite_meath` | 22 | 22 |
| `eventbrite_laois` | 17 | 17 |
| `eventbrite_mayo` | 14 | 14 |
| `eventbrite_kerry` | 7 | 7 |
| `eventbrite_westmeath` | 5 | 5 |
| `eventbrite_clare` | 5 | 5 |
| `eventbrite_cork` | 4 | 4 |
| `eventbrite_leitrim` | 4 | 4 |
| `eventbrite_offaly` | 2 | 2 |
| **Total** | **80** | **80** |

The 25 past events and post `115352` are **outside** this cohort and are **not
enumerated** in the TSV. (Post `115352` is Task D's item:
`04-datetime-contract-violation.md`.)

## 2. TSV schema

One row per ID, 24 columns:

`post_id`, `title`, `baseline_source`, `baseline_source_id`,
`baseline_date_used`, `baseline_date_field`, `current_event_source`,
`current_event_source_id`, `current_event_url`, `current_event_date_raw`,
`current_event_end_date_raw`, `current_event_status`, `current_post_status`,
`post_modified`, `event_imported`, `current_cohort_state`, `baseline_drift`,
`baseline_state`, `upstream_presence_verified`, `enumeration_manifest_available`,
`upstream_enumeration_membership`, `provisional_classification`,
`classification_basis`, `observed_at`.

`baseline_state` is a **fixed string** recording Stage 3's classification
("source_not_found + current/future (Stage 3 §7)") and is kept separate from
every current observation, as required.

## 3. Current observation of all 80 IDs

Queries (read-only; full text in `07-shared-db-access-evidence.md`):

```sql
SELECT ID, post_status, post_type, post_title, post_date, post_modified, post_modified_gmt
  FROM wp_posts WHERE ID IN (<80 IDs>) ORDER BY ID;

SELECT post_id, meta_key, meta_value FROM wp_postmeta
 WHERE post_id IN (<80 IDs>)
   AND meta_key IN ('_event_source','_event_source_id','_event_url','_event_date',
                    '_event_end_date','_event_status','_event_imported',
                    '_event_start_time','_event_end_time')
 ORDER BY post_id, meta_key;
```

**Every one of the 80 IDs returned a post row and a full set of the nine meta
keys queried (720 = 80 × 9 rows).**

| `current_cohort_state` | Count |
|---|---:|
| still suppressed and current/future | **80** |
| `STATUS CHANGED (not source_not_found)` | 0 |
| `NO LONGER CURRENT/FUTURE (effective date now past)` | 0 |
| `POST MISSING` | 0 |
| **`baseline_drift` = BASELINE DRIFT — CURRENT STATE CHANGED** | **0** |

| Field observed across the 80 | Value |
|---|---|
| `current_event_status` | `source_not_found` × 80 |
| `current_post_status` | `publish` × 80 |
| `_event_date` / `_event_end_date` | all canonical `Y-m-d`; none missing |
| `_event_imported` | present on all 80 |

**No baseline drift.** All 80 still meet the original criteria 61 minutes after
Stage 3's capture — which is consistent with the last import activity before the
capture occurring at 18:35–18:41 and touching different sources.

Because there is no drift, no row needed the
`BASELINE DRIFT — CURRENT STATE CHANGED` label. The label, the column and the
rule remain in the schema so that a future re-run cannot silently drop a row.

## 4. Per-source counts

| Source | Original cohort (baseline) | Current restoration candidates |
|---|---:|---:|
| `eventbrite_meath` | 22 | 22 |
| `eventbrite_laois` | 17 | 17 |
| `eventbrite_mayo` | 14 | 14 |
| `eventbrite_kerry` | 7 | 7 |
| `eventbrite_westmeath` | 5 | 5 |
| `eventbrite_clare` | 5 | 5 |
| `eventbrite_cork` | 4 | **4 (conditional tier)** |
| `eventbrite_leitrim` | 4 | 4 |
| `eventbrite_offaly` | 2 | 2 |
| **Total** | **80** | **80** (76 high + 4 conditional) |

No cohort member was dropped, replaced or reclassified against its baseline.

## 5. Source status: current snapshot vs historical run status

Two different questions get two different answers, and they are kept apart
(`01e-source-registry-snapshot.tsv`).

| Source | Registry `status` (current) | `last_import` (current snapshot) | Label for that value | Historical status of the run in the 2026-11-15 14:00–14:11 window |
|---|---|---|---|---|
| `eventbrite_meath` | active | 2026-11-14 14:23:45 | `HISTORICAL RUN STATUS NOT RETAINED` | **no run in the window** |
| `eventbrite_laois` | active | 2026-11-14 14:17:00 | `HISTORICAL RUN STATUS NOT RETAINED` | **no run in the window** |
| `eventbrite_mayo` | active | 2026-11-14 14:23:24 | `HISTORICAL RUN STATUS NOT RETAINED` | **no run in the window** |
| `eventbrite_kerry` | active | 2026-11-14 14:11:46 | `HISTORICAL RUN STATUS NOT RETAINED` | **no run in the window** |
| `eventbrite_westmeath` | active | 2026-11-14 14:31:21 | `HISTORICAL RUN STATUS NOT RETAINED` | **no run in the window** |
| `eventbrite_clare` | active | 2026-11-14 14:02:10 | `HISTORICAL RUN STATUS NOT RETAINED` | **no run in the window** |
| `eventbrite_cork` | active | 2026-11-15 14:01:21 | **`HISTORICAL VALUE — RUN-LINKED`** (history row 14:01:21, same second) | **yes — `warning`, incomplete, reconciliation refused** |
| `eventbrite_leitrim` | active | 2026-11-14 14:17:10 | `HISTORICAL RUN STATUS NOT RETAINED` | **no run in the window** |
| `eventbrite_offaly` | active | 2026-11-14 14:24:51 | `HISTORICAL RUN STATUS NOT RETAINED` | **no run in the window** |

No current registry value is attributed to a 2026-11-15 14:00–14:11 execution
except `eventbrite_cork`, whose timestamp and history row establish the
association. The eight `success` values are snapshots of **2026-11-14** runs
whose log and history rows have been evicted — they are evidence about neither
direction of the incident window.

**Would P12 classify the observed failures differently?** Yes, if it were the
code writing those rows: `rate_limited` for the two 429s, `partial` for the
incomplete walks, `warning` only for the complete-but-resumed case
(`docs/evidence/2026-11-15-stage-3/02-status-vocabulary-mapping.md`). P12 is live
on this shared stack (`00-scope-and-baseline.md` §4b), and the 18:39–18:41 rows
show exactly that vocabulary — but **P12 does not retroactively change the
historical status of the 14:01–14:10 runs, and it does not change any event's
completeness retrospectively.**

## 6. Provisional per-event classification

| Tier | Events | Sources |
|---|---:|---|
| **HIGH — upstream verification required** | **76** | meath 22, laois 17, mayo 14, kerry 7, westmeath 5, clare 5, leitrim 4, offaly 2 |
| **CONDITIONAL — clean enumeration and stable-ID confirmation required** | **4** | cork 4 |
| LOW-PRIORITY / possible legitimate suppression | 0 | — |
| INDETERMINATE | 0 | — |
| `EXCLUDED FROM CURRENT CANDIDATES` (baseline drift) | 0 | — |

**Why the 76 are HIGH.** For these eight sources the only retained local evidence
is the registry snapshot and source health; the runs that produced the
suppression sit outside retention. There is no retained evidence that any of
these events is gone upstream, and none that the source is inactive or
persistently failing. They are therefore the first things worth *checking* —
a statement about verification priority, **not** about liveness or
restorability.

**Why cork's 4 are CONDITIONAL rather than HIGH.** Cork's retained run (14:01:21)
shows a **structurally truncated** listing: the fetch reported "this listing
declares 1252 objects but its own pagination exposes only 49 pages of 20", and
the walk stopped at page 38/49 on the time budget. Only 49 × 20 = 980 objects are
reachable through that listing at all, so a future enumeration of the Cork
*listing* can never demonstrate presence or absence for an event beyond page 49.
The decision must rest on a **per-event provider lookup**, not on "a clean
enumeration" — see `05-restoration-plan.md` §3.

**Why no LOW-PRIORITY or INDETERMINATE rows were forced.** Nothing in the
retained evidence distinguishes "legitimately gone" for any cohort member, and
nothing is so thin that a tier cannot be assigned. Rather than manufacture a LOW
tier, the contract-ambiguous items were kept out of the cohort entirely: post
`115352` (non-cohort, Task D) and the three events ending **today**
(`136466`, `136793`, `136876`). Those three stay in the HIGH tier as required —
they are current/future at capture — and are flagged in §7 for timing.

**No row is labelled confirmed live, confirmed restorable or safe to restore.**
Every row records `upstream_presence_verified = no`.

## 7. Per-event facts worth carrying into the plan

| Observation | Detail |
|---|---|
| Duplicate `_event_source_id` inside the cohort | **0** — no ID collision, and no evidence of a re-issued identifier *within* the cohort |
| Events whose end date is **today** (`2026-11-15`) | `136466`, `136793`, `136876` — an upstream check tomorrow may legitimately return "gone"; verification timing matters |
| Earliest cohort end dates still in future | `2026-11-17` (`136799`, `18544`), `2026-11-18` (several) — the cohort starts expiring within days |
| Longest-lived end date | `2027-08-22` (`138537`) |
| `post_status` | `publish` for all 80 — **no `post_status` change is warranted anywhere**; suppression is enforced by `_event_status` |
| `_event_date` / `_event_end_date` format violations in the cohort | **0** — the whole violation class is the synthetic-fixture set in `04-datetime-contract-violation.md` |

Because several members expire within days, the cohort is a **moving** target:
any plan executed against it must re-capture the inventory immediately before it
runs, and the three same-day events may fall out of scope on their own.

## 8. Is there an enumeration manifest? No — and that is structural

The prompt required checking whether a genuine per-run event-ID enumeration
manifest exists before making any upstream-membership claim.

**It does not, and its absence is structural rather than an accident of
retention:**

* A history row (`class-import-history.php`) stores **counters only** — `found`,
  `new`, `updated`, `unchanged`, `duplicates`, `skipped`, `errors` — plus status,
  message, identity and checkpoint fields. No ID list, no URL list.
* A log entry stores `event_id` **only for events the run acted on** (created or
  updated). Events the run never saw produce no entry, so the log cannot express
  "the enumeration contained these N events".
* `mark_missing_events()` returns a count and **logs nothing at all** — no
  per-event line, no summary line (`03-connected-failure-analysis.md` §1).
* P13 changed *how much* is retained; it did not add an enumeration manifest.

For all 80 rows, therefore:

> `enumeration_manifest_available = no`
> `upstream_enumeration_membership = UPSTREAM ENUMERATION MEMBERSHIP UNKNOWN`

No membership claim is made for any event, and none is inferred from a run
counter, a log row, or the absence of one.


