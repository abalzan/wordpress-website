# Stage 2 — Event Importer fetch contract, reconciliation safeguards, partial-enrichment protection and reconciliation pagination

| | |
|---|---|
| **Stage / task name** | Stage 2 — Event Importer P3, P4, P5 and P10 implementation |
| **Date** | 2026-11-15 |
| **Branch** | `event-import-improvements` |
| **Actual HEAD at start** | `b5a3ee92be0f3e547be1f3be7e291afd3d924e60` |
| **HEAD at finish** | `b5a3ee92be0f3e547be1f3be7e291afd3d924e60` (no commit) |
| **Working tree at start** | clean |
| **Working tree at finish** | dirty by design: Stage 2 implementation, three new suites, regenerated importer POT, documentation and evidence |
| **Skills applied** | `wp-repository`, `wp-content-change`, `wp-write-in-process-test`, `wp-testing`, `wp-security-review`, `wp-update-docs` |
| **Canonical docs consulted** | `docs/engineering-standard.md`, `docs/testing.md`, `docs/content-model.md`, `docs/plugins/conexao-event-importer.md`, Stage 0/0.5/1 reports and evidence |

> **Note on the referenced audit/roadmap report.** The task refers to a
> 2026-11-14 report titled approximately “Event Importer — Final Audit and
> Implementation Roadmap”. No such file exists at this HEAD (searched `docs/` and
> the full git history; the P-numbered findings are referenced only as an
> external roadmap in `docs/plans/2026-11-14-stage-0-5-test-safety-plan.md`).
> Rather than fabricate or rewrite a historical audit, Stage 2 records its
> section here, in the established stage-report series, and leaves the Stage 0,
> Stage 0.5, Stage 1 and Stage 1 reconciliation reports untouched.

## 1. Scope completed

- **P4 (verification-first).** Inventoried every registered handler from
  `get_source_handler()`, established per handler whether `is_fetch_complete()` /
  `is_reconciliation_safe()` are implemented, whether the fail-open default
  applies and whether it is correct, and recorded the existing test coverage. No
  gate was re-implemented; no optional code was added.
- **P3.** Enforced the explicit fetch contract. Fixed the five handlers that
  returned `array()` on a failed or unparsable retrieval (Mondello Park, Laois
  Tourism, IVVCC, Motorsport Ireland, iCalendar). Eventbrite and Heritage Week
  already conformed and were left unchanged.
- **P5.** Added an additive per-field enrichment state
  (`Conexao_Event_Enrichment_State`), declared it in Heritage Week, and made
  `upsert_event()` preserve stored values for fields whose retrieval failed —
  field by field, with the description's change detection scoped to payloads that
  declare enrichment.
- **P10.** Replaced the 300-post single query in `mark_missing_events()` with
  gate-first, read-only keyset pagination and bounded, revalidated mutation after
  the complete candidate set is known. Mutation also re-checks the importer's
  language guard, so a Polylang translation is never flipped to
  `source_not_found`.

## 2. Scope NOT completed (deliberately)

- No shared-database query, fingerprint or status inventory: no verified
  read-only method exists (see §11).
- No P7, P8, P9, P11, P12 or P13 work.
- No production action, provider request, activation, release, commit or push.
- No change to Event Runtime visibility, `_event_status` semantics, source
  identity, retention or the source registry.

## 3. Files added / modified / deleted

**Added**

- `wp-content/plugins/conexao-event-importer/includes/class-conexao-event-enrichment-state.php`
- `wp-content/plugins/conexao-event-importer/tests/test-source-fetch-contract.php`
- `wp-content/plugins/conexao-event-importer/tests/test-partial-enrichment-preservation.php`
- `wp-content/plugins/conexao-event-importer/tests/test-reconciliation-pagination.php`
- `docs/evidence/2026-11-15-stage-2/` (this evidence set)
- this report

**Modified**

- `wp-content/plugins/conexao-event-importer/conexao-event-importer.php` (loads the new class)
- `wp-content/plugins/conexao-event-importer/includes/class-event-importer.php` (P5 write path, P10 reconciliation)
- `wp-content/plugins/conexao-event-importer/includes/class-event-normalizer.php` (forwards the state)
- `wp-content/plugins/conexao-event-importer/includes/sources/class-heritage-week-source.php` (P5 declarations)
- `wp-content/plugins/conexao-event-importer/includes/sources/class-mondello-park-source.php` (P3)
- `wp-content/plugins/conexao-event-importer/includes/sources/class-laois-tourism-source.php` (P3/P4)
- `wp-content/plugins/conexao-event-importer/includes/sources/class-ivvcc-source.php` (P3/P4)
- `wp-content/plugins/conexao-event-importer/includes/sources/class-motorsport-ireland-source.php` (P3/P4)
- `wp-content/plugins/conexao-event-importer/includes/sources/class-icalendar-source.php` (P3/P4)
- `wp-content/plugins/conexao-event-importer/languages/conexao-event-importer.pot` (regenerated)
- `docs/plugins/conexao-event-importer.md` (Stage 2 section)
- `docs/reports/README.md` (index entry)
- `docs/README.md` (documentation index entry)

**Deleted:** none. **Stage 1 tests:** none modified. **Database:** no schema, user
or privilege change; no shared-database write.

## 4. Runtime impact

- A failing source now always produces a truthful failed/incomplete run instead
  of a silent "success, 0 events".
- A failed Heritage Week detail fetch no longer erases a stored description,
  venue, address, organiser, location label or banner.
- Absence reconciliation covers every source-owned event rather than the first
  300, and it can no longer run on an incomplete or resumed walk.

## 5. Content / data impact

No content was written outside the disposable test database. Reconciliation still
uses the existing `source_not_found` transition; Stage 2 changed only how many
candidates are considered and when the transition is allowed to run.

## 6. Polylang impact

None. No taxonomy policy, language scoping or translation identity was touched.

## 7. Route / HTTP impact

None. No route, redirect, REST endpoint or `/en/` layer file was modified.

## 8. Production actions

None. No production request, credential, activation, upload, database, release or
deployment action was performed.


## 9. Verification commands and results

See `docs/evidence/2026-11-15-stage-2/05-tests.txt` and
`06-quality-gates.txt` for the full transcripts. Summary:

| Check | Command | Result |
|---|---|---|
| Targeted P3/P4 suite | `test-source-fetch-contract.php` | 55 passed, 0 failed |
| Targeted P5 suite | `test-partial-enrichment-preservation.php` | 42 passed, 0 failed |
| Targeted P10 suite | `test-reconciliation-pagination.php` | 57 passed, 0 failed |
| Full importer component | `./scripts/run-tests.sh --php --only conexao-event-importer` | **42 suites: 39 passed, 3 failed; 4434 assertions passed, 40 failed** — all 3 failures are pre-existing (see §11) |
| Static script gate | `./scripts/run-tests.sh --scripts` | 17/18 (the three known stale catalogues) |
| Registry drift | `php scripts/generate-registry-docs.php --check` | OK, 11 plugins / 20 regions current, zero writes |
| Lint | `./scripts/lint.sh` | **OK** — syntax clean, no new PHPCS violations, PHPStan clean |
| Disposable DB identity | `tests/lib/isolated-db.php` | `ISOLATED_DB_PREFLIGHT=PASS` |

## 10. Failure proofs / negative tests

- Incomplete enumeration → **zero** candidate queries and zero mutations
  (asserted with a `query`-filter spy plus an unchanged status set).
- Checkpoint-resumed subset → the same.
- Failed later candidate page, malformed page, and an interruption during the
  scan → zero absence-based mutations in each case.
- Failed/incomplete engine runs → the pre-existing live event stays published.
- A failed detail fetch → stored description/venue/address/organiser/location
  unchanged; an absent element is not a clear; a present-but-blank description
  container *is* a clear.

## 11. Limitations

1. **No shared-database read-only access.** The Stage 1 reconciliation account
   (`conexao_ro`) had already been dropped, and Stage 2 forbids creating or
   altering accounts and forbids using the application account. Therefore no
   before/after `source_not_found` inventory and no before/after cohort
   fingerprint exists for this stage. This is why the verdict is
   `PASS WITH LIMITATION`, not `PASS`.
2. A pre-change suite run was started but overlapped the first edits and was
   discarded; the post-change full run is the authoritative test evidence.
3. Per-event drops in IVVCC/Motorsport (P11-adjacent) and Laois selector drift are
   recorded residual risks, not Stage 2 changes.
4. **Three pre-existing importer suites fail** and are not fixed here:
   `test-import-continuation.php` (deterministic 22 page-3 assertions from a
   clean state — 29 in the final run because a leftover `state: running`
   continuation record from a SIGKILLED run adds 7 lock/stall failures),
   `test-source-fetch-failure.php` (122/10 — Stage 1 records a first-page 429 as
   `rate_limited` while the G1 test asserts `failed`) and
   `test-town-sanitization.php` (82/1 — four legacy `conexao_town` terms in the
   disposable clone). Proof of non-involvement, isolation runs, the clean-state
   control and disposition:
   `docs/evidence/2026-11-15-stage-2/08-preexisting-failures.md`.
   Fixing them would require changing Stage 1 behaviour or weakening an existing
   test, both outside this stage's authority.

## 12. Evidence paths

- `docs/evidence/2026-11-15-stage-2/00-baseline-and-scope.md`
- `docs/evidence/2026-11-15-stage-2/01-p4-handler-inventory.md`
- `docs/evidence/2026-11-15-stage-2/02-p3-p4-fixes.md`
- `docs/evidence/2026-11-15-stage-2/03-p5-enrichment-state.md`
- `docs/evidence/2026-11-15-stage-2/04-p10-reconciliation-pagination.md`
- `docs/evidence/2026-11-15-stage-2/05-tests.txt`
- `docs/evidence/2026-11-15-stage-2/06-quality-gates.txt`
- `docs/evidence/2026-11-15-stage-2/07-preservation-limitations-and-status-inventory.md`

## 13. Rollback / recovery

Revert the Stage 2 diff: delete the four added files (the state class and three
suites), restore the ten modified importer files and the regenerated catalogue,
and drop the Stage 2 documentation. No database migration, option or schema change
was introduced, so no data rollback is required.

## 14. Final status

**PASS WITH LIMITATION.** Every code-level objective (P3, P4, P5, P10) is
implemented and verified in the disposable database; the shared-database
preservation/status comparison required for a full `PASS` could not be performed
safely and is not claimed.

