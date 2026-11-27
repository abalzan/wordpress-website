# Preservation, database safety, status inventory and limitations

## 1. Shared-database writes

**Stage 2 made no shared-database writes of any kind, because it never connected
to the shared database.** See `00-baseline-and-scope.md` §4 for why no verified
read-only method existed. Every database-backed test and fixture in Stage 2 ran
against the disposable clone `conexao_stage05_test_5bd9ece5c032`, whose identity
was proven by `ISOLATED_DB_PREFLIGHT=PASS` before the first suite.

No event status was changed in the shared database. No content, option, meta,
Polylang, user, grant or privilege was created, altered or dropped.

## 2. Preserved cohorts — status of the claim

The authoritative recorded cohorts are unchanged and were not touched:

| Cohort | Recorded count | Stage 2 action |
|---|---|---|
| Incident-created events | 47 | none |
| Incident-updated events | 13 | none |
| Externally imported events (133818, 171003, 171004, 171006, 171007, 171008) | 6 | none |
| Probe-associated imported events | 14 | none |
| Protected `stage32_export_test` posts | 275 | none |

What can and cannot be claimed:

- **Claimed:** Stage 2 performed no shared-database write, so it cannot have
  modified any cohort record.
- **Not claimed:** that the cohort fingerprints *match* a fresh capture. No
  read-only method was available, so no before/after fingerprint was taken in
  Stage 2. The previously recorded fingerprints (Stage 1 baseline,
  `docs/evidence/2026-11-15-stage-1/00-before-edit-preservation-baseline.md`) are
  the last authoritative captures and are **not** re-verified here.

## 3. Digests and fingerprints captured during Stage 2

**None.** No digest or fingerprint of shared-database data was captured, because
no verified read-only access existed. Recording an undocumented or
unverified-connection digest would repeat the Stage 1 limitation that made the
before-edit all-event digest unreproducible, so no such digest was produced.

Disposable-database test fixtures (`_event_source = s2r_*`, `s2c_*`) are not
preservation data and were deleted by their own suites; they are not evidence
about any cohort.

## 4. Stage 0 / 0.5 / 1 integrity

| Check | Result |
|---|---|
| Stage 0 probe-source deactivation and its evidence | unchanged (`git status` lists no file under `docs/evidence/2026-11-14-stage-0-*`) |
| Stage 0.5 operator-store isolation, fail-closed bootstrap, shutdown cleaner, static-only runner, disposable-DB identity checks, HTTP isolation | unchanged (no file under `tests/`, `scripts/` or `docs/evidence/2026-11-14-stage-0-5/` modified) |
| Stage 1 Eventbrite breaker, Run All parent/child model, Heritage pre-detail filtering | unchanged: the only Stage 1 files touched are `class-heritage-week-source.php` (P5 state declarations inside `enrich_event()`) and `class-event-importer.php` (P5/P10 additions). The date-eligibility gate, the breaker and the Run All identity model were not edited. |
| Stage 1 tests | not modified, not weakened; all pass |
| Stage 1 evidence and reconciliation evidence | not modified |
| Unresolved Stage 1 findings | preserved as unresolved: the 285-post clone population remains `UNRESOLVED — INSUFFICIENT IDENTITY EVIDENCE`, the original before-edit all-event digest method remains unknown/unreproducible, and post 170981 remains `EXISTENCE TIMING UNRESOLVED`. Stage 2 did not attempt to resolve them. |
| Safeguards listed in Section 7 of the task | unchanged; Stage 2 introduced **no new persistent option, transient, cache entry or other store** (enrichment state is in-memory only), so the Stage 0.5 isolation gate needed no extension |

## 5. Identified `source_not_found` events

No Stage 2 query was possible, so Stage 2 cannot report a current inventory. The
last authoritative read-only capture is the 2026-10-03 diagnosis
(`docs/reports/2026-10-03-event-import-diagnosis.md` §D6), which recorded **18
`source_not_found` events: 12 `laois_tourism`, 5 `eventbrite_cavan`, 1 other**,
including these five Cavan listings:

| Row (as recorded) | Title | Source URL fragment |
|---|---|---|
| 1852 | Cavan Plein Air Festival | `…/cavan-plein-air-festival-tickets-1999210552580` |
| 1862 | Cornstarch Messy Play (ages 3-6) | `…/cornstarch-messy-play-ages-3-6-tickets-2001940046570` |
| 1868 | Cornstarch Messy Play (age 7+) | `…/cornstarch-messy-play-age-7-tickets-2001939782781` |
| 1899 | EVAGARDEN Christmas Makeup Masterclass | `…/evagarden-christmas-makeup-masterclass-tickets-2002739094544` |
| 1901 | Cavan in Ireland Remembers: Stories of Home | `…/cavan-in-ireland-remembers-…-tickets-2002801904410` |

These identifiers come from **existing evidence, not a Stage 2 query**, and the
current status of each record is **unknown**. Their status was **not changed** by
Stage 2, and no restoration was attempted: verifying whether they are still live
requires live provider requests, which are outside this stage and require
separate authorisation.

## 6. Known limitations

1. **No shared-database read-only evidence (blocking for a full PASS).** The
   before/after `source_not_found` inventory and the before/after preservation
   fingerprints could not be captured. This is why the verdict is
   `PASS WITH LIMITATION`.
2. **Per-event drops in IVVCC and Motorsport Ireland.** A parse/enrichment
   exception skips one event while the walk reports complete, so that single
   event could be reconciled as absent. It is a per-event failure, outside the
   four P4 walk outcomes, and belongs to identity handling (P11). Recorded, not
   changed.
3. **Laois Tourism selector drift.** A markup change that stops matching the
   event containers makes the page a "successfully retrieved empty listing"
   (complete, zero events), which is the documented source contract. The
   alternative — treating "no containers" as a failure — would make a genuinely
   empty Laois listing unreconcilable. Recorded as a residual risk.
4. **Description change detection is scoped to declared enrichment.** Handlers
   that declare no enrichment state (Eventbrite, listing-only sources) still do
   not compare the description, exactly as before. Making it global would change
   Eventbrite update behaviour, which P5 explicitly forbids.
5. **Static script layer remains 17/18.** The single failure is
   `tests/scripts/verify-i18n-freshness.py` reporting the three pre-existing
   stale catalogues (theme, `conexao-admin-ux`, `conexao-commemorative`). It is
   the same known baseline recorded by Stage 1 and is unrelated to Stage 2; the
   importer catalogue itself was regenerated (see `06-quality-gates.txt`).
6. **Three pre-existing importer suites fail** in the full run — see
   `08-preexisting-failures.md` for the per-suite analysis, the isolation runs and
   the proof that Stage 2's diff contains none of the code they exercise.
7. **Pre-change suite baseline unavailable.** The attempted pre-change run
   overlapped the first edits and was discarded (`00-baseline-and-scope.md` §5).

