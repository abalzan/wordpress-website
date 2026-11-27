# Protected cohort verification status

Verification of the remaining protected cohorts named in the Stage 1 evidence.
This is a verification exercise only; no cohort membership was re-investigated
beyond confirming presence and fingerprints.

## Summary

| Cohort | Exact IDs documented | Before fingerprint | After fingerprint | Same environment | Status |
|---|---|---|---|---|---|
| 47 incident-created events | Yes | Yes | Yes | `wordpress` | Supported |
| 13 incident-updated events | Yes | Yes | Yes | `wordpress` | Supported |
| 6 externally imported events | Yes | Yes | Yes | `wordpress` | Supported |
| 14 probe-associated imported events | Partial | No | No | `wordpress` | Partially supported |
| 275 protected `stage32_export_test` posts | No (selector + hash only) | Yes | Yes | `wordpress` | Supported (membership unresolved — see §4) |

## 1. The 47 incident-created and 13 incident-updated events

The Stage 0.5 baseline
(`docs/evidence/2026-11-14-stage-0-5/11-shared-preservation-baseline.txt`)
enumerates all 60 protected incident rows individually, with `ID`, `post_status`,
`post_date_gmt`, `post_modified_gmt`, `slug`, `_event_source` and
`_event_source_id`. It records:

- Protected incident `wp_posts` rows: 60, SHA-256 `12d4d9bc…`
- Protected incident `wp_postmeta` rows: 1,333, SHA-256 `5c8a3f54…`

Both fingerprints are recorded over the same row set, in the same environment,
with the same field list. The 60 rows are the 47 incident-created plus the 13
incident-updated events.

Current-state check: a `post_modified` window query over the same date range
returns **61** event posts, one more than the baseline's 60. The extra row was
identified exactly: **ID 170981** (`eventbrite_louth`,
`lgbt-young-adults-group`, `post_modified` 2026-11-14 21:34:29).

170981 is **not** in the baseline's 60-row cohort, and it is absent from both
Stage 0.5 TSV inventories. It is a separate row that falls inside the date
window but outside the protected cohort as defined. The 60-row enumerated list
in the baseline remains the authoritative cohort definition, and the baseline's
own fingerprint is the comparison basis.

The 60 enumerated IDs were verified to match the baseline exactly; the window
query is recorded here only to document why a naive date-range recount gives 61
rather than 60.

**Status: supported.** Exact IDs are documented, before and after fingerprints
exist, the environment is the same, and the fields are equivalent. The
preservation claim for this cohort is supported by the evidence.

## 2. The 6 externally imported events

Recorded in `docs/evidence/2026-11-14-stage-0-5/14-final-verification.txt` as
the external admin-triggered import of 2026-11-15 07:42–07:45 UTC:

- 4 `eventbrite_cork`, 1 `eventbrite_donegal`, 1 `eventbrite_kildare`
- Shared DB event count 3,700 → 3,705

Current-state check confirms exactly 6 event posts with `post_modified` in the
2026-11-15 07:42–07:46 window, with the expected source distribution:

| ID | `_event_source` |
|---|---|
| 133818 | `eventbrite_cork` |
| 171003 | `eventbrite_cork` |
| 171004 | `eventbrite_cork` |
| 171006 | `eventbrite_cork` |
| 171007 | `eventbrite_donegal` |
| 171008 | `eventbrite_kildare` |

**Status: supported.** The cohort is enumerated, present, and matches the
recorded source distribution. These are the same six writes that explain the
all-event-post digest difference (see `01-all-event-post-digest.md` §5).

## 3. The 14 probe-associated imported events

The Stage 0 probe report
(`docs/reports/2026-11-14-stage-0-event-importer-probe-deactivation.md`) records
"12 `g1_probe_` events; 2 G2/G4C probe events" — 14 in total.

Current-state check:

- Events with `post_name LIKE '%g1_probe%'`: **0**
- Events with an `_event_source` containing `probe`: **14**

The 14 probe-associated events are present in the current database, but they are
identified by a source-meta pattern rather than by an enumerated ID list, and no
before/after fingerprint pair exists for them specifically. The Stage 0
verification CSV records only aggregate counts (`g1_probe_events 12`,
`g2_g4c_probe_events 2`), not IDs or hashes.

**Status: partially supported.** The cohort is present in the expected
cardinality, but the evidence does not document exact IDs or a before/after
fingerprint, so the preservation claim rests on counts alone. Per the standard,
a count match does not upgrade a partial claim to complete.

## 4. The 275 protected `stage32_export_test` posts

Per the task's dependency rule, this cohort's status follows Reconciliation B
(`02-stage32-fixture-cohorts.md`), which is
`UNRESOLVED — INSUFFICIENT IDENTITY EVIDENCE`.

- Exact IDs documented: **no** — a selector, a per-source breakdown and a
  fingerprint are documented, but no ID enumeration.
- Before fingerprint: **yes** — `a77b0943…`, reproduced exactly during this
  reconciliation.
- After fingerprint: **yes** — identical, confirmed by direct recomputation.
- Same environment: **yes** — `wordpress` in both captures.
- Fields equivalent: **yes** — `(post_id, source_id)`, tab-separated, ordered
  by `ID`.

**Status: membership unresolved; fingerprint comparison limited to the records
identified in §4.1.** The cohort's *fingerprint* is confirmed unchanged, which
establishes that the same 275 rows are present with the same source IDs. What
remains unresolved is the cohort's *membership relative to the 285 clone
leftovers*, which is a separate population question and is not answerable from
the available evidence.

The fingerprint match does establish one thing independently: the 275-row cohort
in the shared database is byte-identical before and after Stage 1. That is a
genuine preservation result for the shared cohort, and it is not affected by the
unresolved 285 comparison.

## 5. Cross-cutting observations

- Every cohort that has both an enumerated ID list and a before/after
  fingerprint is supported.
- The one cohort with a fingerprint but no ID list (275) has a confirmed
  fingerprint match but an unresolved membership question against a different
  population.
- The one cohort with neither an ID list nor a fingerprint (14 probe events) is
  only partially supported.
- No cohort shows evidence of a preservation violation.

## 6. Follow-up — post 170981 creation timing (2026-11-15)

Post 170981 (`eventbrite_louth`, slug `lgbt-young-adults-group`) appeared as a 61st row in a `post_modified` date-window check over the incident range and was investigated for its relationship to the incident window (`2026-11-14 21:28:00` to `2026-11-14 21:35:00`).

### Recorded values (existing evidence only; no database was queried for this follow-up)

| Field | Value | Source |
|---|---|---|
| `ID` | 170981 | `03-protected-cohort-status.md` §1 window check |
| `post_type` | `event` (window check over event posts) | `03-protected-cohort-status.md` §1 |
| `post_status` | not recorded in evidence | — |
| `post_name` | `lgbt-young-adults-group` | `03-protected-cohort-status.md` §1 |
| `post_date` | **not recorded** | not in any Stage 0/0.5/1 evidence file |
| `post_date_gmt` | **not recorded** | not in any Stage 0/0.5/1 evidence file |
| `post_modified` | `2026-11-14 21:34:29` | `03-protected-cohort-status.md` §1 |
| `post_modified_gmt` | **not recorded** | not in any Stage 0/0.5/1 evidence file |
| `_event_source` | `eventbrite_louth` | `03-protected-cohort-status.md` §1 |
| `_event_source_id` | **not recorded** | not in any Stage 0/0.5/1 evidence file |
| `_event_imported` | **not recorded** | not in any Stage 0/0.5/1 evidence file |

No database query was performed for this follow-up: safe read-only access was not available (the `conexao_ro` account was dropped and no read-only credentials were available), and no account, grant or privilege was created or changed. The values above come exclusively from existing evidence; nothing was reconstructed from current database contents.

### Importer semantics

The importer (`Conexao_Event_Importer_Engine`) creates event rows with `wp_insert_post()` without an explicit `post_date`, so WordPress defaults `post_date` and `post_modified` to `current_time('mysql')` (site-local) at insertion. On import it writes meta `_event_imported = '1'` (a flag, not a timestamp) and `_event_import_date = current_time('mysql')` (site-local import time). Per `docs/content-model.md`, `_event_imported` and `_event_import_date` are recorded meta keys, but neither is an independently verifiable creation-time record for a specific row without reading that row's meta. The evidence does not record 170981's `post_date` or any of its meta values.

### Incident-window timezone

The baseline (`11-shared-preservation-baseline.txt`) was captured `2026-11-14T22:25:02Z` and the incident evidence uses the same timeline: the trigger analysis (`09-trigger-analysis.md`) records the cork import at `2026-11-14 21:28:03–21:29:28 (UTC)` and a second `eventbrite_louth` continuation logged at `21:33:04` on the same day. The site timezone is `Europe/Dublin`; in November 2026 its UTC offset is 0, so site-local, GMT and UTC coincide on this date. Post 170981's `post_modified` (`2026-11-14 21:34:29`) falls inside the incident window (`21:28:00`–`21:35:00`) on that shared timeline.

### Inventory membership

- **Original 60-row baseline file** (`11-shared-preservation-baseline.txt`): 170981 is absent. The 60 enumerated IDs are the 13 incident-updated and 47 incident-created `eventbrite_cork` rows; no `eventbrite_louth` row is enumerated.
- **Stage 0.5 TSV `01-event-content-before.tsv`** (3,640 rows): 170981 absent (verified by search of the file).
- **Stage 0.5 TSV `09-event-content-after-unexpected-change.tsv`** (3,687 rows): 170981 absent (verified by search of the file).

The TSV delta between the two inventories is exactly the 47 created and 13 modified cork rows; 170981 appears in neither inventory.

### Classification: `EXISTENCE TIMING UNRESOLVED`

Available evidence provides `post_modified` (`2026-11-14 21:34:29`, inside the incident window) but does not reliably establish insertion time:
- `post_date`, `post_date_gmt`, `post_modified_gmt`, `_event_source_id` and `_event_imported` are not recorded anywhere in the evidence.
- No import log, import history row, snapshot comparison, audit or binlog evidence for 170981 exists in the repository.
- A `post_modified` inside the window is not sufficient for `CREATED-DURING INCIDENT CONFIRMED`, and no pre-window evidence establishes `PRE-EXISTING CONFIRMED`.

The timing is therefore recorded as `EXISTENCE TIMING UNRESOLVED`. The missing evidence is: the row's `post_date`/`post_modified_gmt` and import meta (`_event_source_id`, `_event_imported`, `_event_import_date`), or an import log/history entry identifying the create-or-update operation for ID 170981.

### Implications for the protected cohorts

- If 170981 was created during the incident window (its `post_modified` is shortly after the logged `eventbrite_louth` continuation at `21:33:04`, which is consistent with but not proof of creation by that run), the incident's created cohort may have omitted a record; the baseline's 60-row cohort is cork-scoped and does not claim to cover `eventbrite_louth`.
- If it pre-existed and was modified during the window, it is not one of the 13 enumerated updated IDs (all `eventbrite_cork`), so the 13-record updated cohort would be incomplete under a wider source scope.
- If it was created after the incident, its presence in the date-window recount would reflect a timestamp or query-scope effect rather than incident activity.
- **This finding is not evidence that Stage 1 created or modified the record:** Stage 1 ran on 2026-11-15, after the incident window of 2026-11-14, and performed no content writes.
- The overall reconciliation verdict (`UNRESOLVED`) and the Stage 1 verdict (`PASS WITH LIMITATION`) remain unchanged; no protected record was identified as changed by this follow-up.

_Last verified: 2026-11-15 by the Stage 1 preservation reconciliation_