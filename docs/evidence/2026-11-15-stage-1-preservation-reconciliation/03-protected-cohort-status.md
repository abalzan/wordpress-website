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

_Last verified: 2026-11-15 by the Stage 1 preservation reconciliation_