# Stage 1 — Event Importer run coordination, rate limiting and Heritage eligibility

| | |
|---|---|
| **Stage / task name** | Stage 1 — Event Importer P1, P2 and P6 implementation |
| **Date** | 2026-11-15 |
| **Author / agent** | Codex |
| **Branch** | `event-import-improvements` |
| **Start SHA** | `c76d836a6ada1948924562b8dcea53ee2f9dd974` |
| **Final SHA** | `c76d836a6ada1948924562b8dcea53ee2f9dd974` (no commit) |
| **Working tree at finish** | dirty by design: Stage 1 implementation, tests, docs and evidence |
| **Skills followed** | `wp-repository`, `wp-testing`, `wp-security-review`, `wp-content-change`, `wp-write-in-process-test`, `wp-update-docs` |
| **Authoritative docs consulted** | `docs/engineering-standard.md`, `docs/testing.md`, `docs/content-model.md`, `docs/routing.md`, importer plugin docs, Stage 0/0.5 reports |

## 1. Scope completed

Implemented the requested Stage 1 findings P1, P2 and P6 without creating a new
plan:

- one logical-run Eventbrite breaker shared across Eventbrite source boundaries;
- deterministic Retry-After parsing, unsupported-date fail-closed handling,
  bounded fallback, and wait deferral independent of request budget;
- stable parent Run All IDs, unique child source-run IDs, explicit parent
  statuses, aggregate counters, complete failed-source disclosure and
  idempotent parent history;
- continuation propagation and parent refresh;
- Heritage Week pre-detail date eligibility in the site timezone, with
  distinguishable empty, invalid and failed detail outcomes;
- deterministic isolated tests and operator documentation.

The implementation preserved the existing pacing, request-budget, checkpoint,
incomplete-enumeration, source cooldown and no-live-provider safeguards.

## 2. Scope NOT completed

- No production action was performed or authorised.
- No live provider request was made.
- The full WordPress-dependent runner was not run because the existing local
  HTTP server is attached to the shared database and the runner correctly
  blocks that unsafe identity.
- `test-import-continuation.php` was stopped after an isolated preflight when it
  produced no output for more than one minute; it is not claimed as passing.
- The after-edit all-event-post digest did not reconcile with the before-edit
  note, so full all-event post-row equality is explicitly not claimed. The
  matching meta, protected-cohort, option digests and counts are recorded in
  evidence, and the discrepancy needs a separate read-only reconciliation.

## 3. Files added / modified / deleted

Added: 1 test, 6 evidence files, 1 report. Modified: 10 importer/plugin files,
4 documentation/index files and the generated importer POT. Deleted: none.
No file outside the WordPress repository was touched.

## 4. Runtime impact

The local importer now returns truthful source/parent outcomes for shared
Eventbrite rate limiting, resumable wait deferral, Run All aggregation and
Heritage listing eligibility. Dry-run Run All no longer marks events expired.
Non-Eventbrite sources are not blocked by the Eventbrite breaker.

## 5. Content / data impact

No shared or production content was written. All WordPress-dependent tests used
the disposable clone.
Shared-database read-only counts and digests are in
[`03-shared-preservation-after.txt`](../evidence/2026-11-15-stage-1/03-shared-preservation-after.txt).
The existing Stage 0/0.5 reports and records were not edited.

## 6. Polylang impact

None. No Polylang data, PT identity, EN record, taxonomy or translation link was
created or changed.

## 7. Route / HTTP impact

No public route, redirect, canonical, hreflang or sitemap behavior changed.
Provider HTTP is exercised only through deterministic mocks in the new suite.

## 8. Production actions

| Action | Performed? | Detail |
|---|---|---|
| Production write | **no** | No WordPress.com access or mutation |
| Deploy / upload | **no** | Not authorised |
| Plugin activation | **no** | Not authorised |
| Content or DB mutation | **no** | Shared DB was queried read-only; tests used the clone |

## 9. Verification commands and results

| Command | Exit | Result |
|---|---:|---|
| Isolated Stage 1 suite | 0 | 47 passed, 0 failed |
| Isolated `test-eventbrite-rate-limit.php` | 0 | 87 passed, 0 failed |
| `./scripts/lint.sh` | 0 | Syntax clean, PHPCS no-new-violations, PHPStan clean |
| `php scripts/generate-registry-docs.php --check` | 0 | 11 plugins, 20 generated regions current |
| `./scripts/run-tests.sh --scripts` | 1 | 18 suites: 17 passed, 1 failed on 3 stale catalogues |
| `./scripts/i18n-make-pot.sh conexao-event-importer` | 0 | Importer POT regenerated |
| `test-import-continuation.php` | stopped | Isolated preflight passed; no output for >1 minute |

### Numeric test results

The deterministic Stage 1 in-process suite was **1 suite, 47 passed, 0
failed**. The focused existing Eventbrite suite was **1 suite, 87 passed, 0
failed**. The full in-process layer was not run against the shared HTTP site.

### HTTP acceptance

Not run. The available local server is bound to the shared database; no unsafe
HTTP acceptance run was attempted.

### Static analysis

`./scripts/lint.sh` passed: PHP syntax clean across 598 files, PHPCS baseline
did not grow, and PHPStan level 5 reported no errors.

### Script-contract results

`./scripts/run-tests.sh --scripts`: **18 suites, 17 passed, 1 failed**. The one
failure is the existing freshness check for three unrelated stale catalogues:
`conexao-br-irlanda`, `conexao-admin-ux` and `conexao-commemorative`.

### Release / build results

Not in scope. No build, release, deploy or activation was performed.

## 10. Failure proofs / negative tests

The Stage 1 suite proves, with mocked deterministic responses, that an active
breaker prevents repeated Eventbrite requests, unsupported date-like
Retry-After does not trigger an earlier retry, deferred waits do not consume
request budget, failed-source disclosure is not capped, parent finalisation is
idempotent, and definitely-past Heritage listings do not fetch detail pages.

## 11. Regression comparison

No full before/after suite comparison is claimed because the safe full runner
was unavailable against an isolated HTTP identity. The focused Eventbrite
regression passed 87/87, and the new suite passed 47/47.

## 12. Known pre-existing failures

The script gate's three stale catalogues predate this implementation. The
permanent-gate probe also fails closed in the shared-server environment; it is
not a release result. The continuation diagnostic was non-returning, not
silently classified as a pass.

## 13. Limitations

The local HTTP server and shared database cannot be used for WordPress-dependent
acceptance or permanent-gate verification. The all-event-post digest mismatch
between the before-edit note and the after read-only query remains unresolved;
only the matching postmeta, protected-cohort, option digests and counts are
claimed as preserved. No production or provider verification was possible or
attempted.

## 14. Evidence paths

- [`00-before-edit-preservation-baseline.md`](../evidence/2026-11-15-stage-1/00-before-edit-preservation-baseline.md) — branch, stack, protected cohort and before fingerprints.
- [`01-targeted-tests.txt`](../evidence/2026-11-15-stage-1/01-targeted-tests.txt) — isolated Stage 1 and focused regression numbers.
- [`02-quality-gates.txt`](../evidence/2026-11-15-stage-1/02-quality-gates.txt) — lint, registry, i18n and script-gate results.
- [`03-shared-preservation-after.txt`](../evidence/2026-11-15-stage-1/03-shared-preservation-after.txt) — read-only after counts and fingerprints.
- [`04-isolation-and-scope.md`](../evidence/2026-11-15-stage-1/04-isolation-and-scope.md) — environment and authorization boundary.
- [`05-status-model.md`](../evidence/2026-11-15-stage-1/05-status-model.md) — parent/child status and legacy mapping.

## 15. Documentation updated

Updated the importer plugin contract, testing index, documentation index,
reports index, generated importer POT, and this implementation report. No
canonical procedure was moved into a skill.

## 16. Rollback / recovery

Review the uncommitted diff and revert the Stage 1 files before any commit if
the change is rejected. No database rollback is needed because no shared or
production content was written. The preserved Stage 0/0.5 reports and records
are outside this change.

## 17. Final status

**Final status: PASS WITH LIMITATION**

The requested implementation and deterministic isolated tests passed, but the
safe full WordPress/HTTP verifier was unavailable and the all-event-post digest
needs separate read-only reconciliation.

## 17. Post-implementation preservation reconciliation

> **This section was added after the Stage 1 implementation was completed.** It
> records a separate, subsequent read-only reconciliation. It does not describe
> verification that happened during the original implementation, and it does not
> change the original findings, test results or limitations recorded in §1–§16.

### Why this section exists

The original Stage 1 verification ended with one unresolved evidence question:
the after-edit all-event-post digest did not reconcile with the before-edit
note (§2, §13). A second question was also outstanding: whether the 275 protected
`stage32_export_test` posts and the 285 fixture posts recorded as cleaned from
the disposable clone are the same, overlapping or separate populations.

Both were investigated read-only. No database was written, no WordPress
bootstrap, importer, continuation, cron or scheduler operation was triggered, no
protected record was changed, and no application code or test was modified.

### Reconciliation A — all-event-post digest: `RECONCILED — CAPTURE MISMATCH`

The two captures are from the **same** `wordpress` database, table, scope, six
columns and 3,705-row count. Neither documented its serialization.

The serialization was recovered from independent evidence: the baseline's
275-row cohort fingerprint (`a77b0943…`) is reproducible only with
**tab-separated** output ordered by `ID`. Applying that method to the all-event
query reproduces the after-edit digest **exactly** (`4ec41dc2…`), confirming both
the after-edit capture and the recovered method.

The before-edit digest (`86a39a84…`) is not reproducible from current state.
Every plausible variant was tested and none matched: column subsets, status
scopes, orderings, separators, line endings, headers, and a pre-import
reconstruction. Its snapshot inputs no longer exist.

The database did change between the captures, and the change is fully documented
and independently attributable: six event posts were written by an external
admin-triggered import at 2026-11-15 07:42–07:45 UTC, already recorded by Stage
0.5 as external and not caused by any session. The row count is identical in
both captures because the baseline was taken after that import.

Every other fingerprint in the same before/after pair matches exactly: event
postmeta (72,527 rows), the 275-row cohort, and all four option digests.

**The mismatch is a capture-timing and serialization artifact, not evidence of
data loss.**

### Reconciliation B — 275 versus 285 cohorts: `UNRESOLVED — INSUFFICIENT IDENTITY EVIDENCE`

The 275 is a shared-database cohort defined by a documented selector, still
present and fingerprint-identical. The 285 is a clone-only set of leftover
fixtures, deleted from the disposable clone.

Neither cohort has an exact ID list in evidence. The 285's list cannot be
recovered at all: those rows were deleted, and the clone's current state cannot
establish which posts were present at an earlier time. Without both lists, no
intersection, exclusive set or overlap can be computed.

Current-state verification confirms the shape of the evidence: the shared
database holds exactly 275 `stage32_export_test` posts; the clone holds zero.

A related discrepancy was resolved: the Stage 0.5 before-inventory contains 276
rows matching `stage32`, not 275. The difference is exactly one post, **ID
25441**, which matches the fixture family by slug and title but carries no
`_event_source` meta and is therefore correctly excluded from the cohort. This
confirms the 275 cohort definition is correct.

### Other protected cohorts

| Cohort | Exact IDs | Before/after fingerprints | Status |
|---|---|---|---|
| 47 incident-created events | yes | yes | supported |
| 13 incident-updated events | yes | yes | supported |
| 6 externally imported events | yes | yes | supported |
| 14 probe-associated events | partial | no | partially supported |
| 275 `stage32_export_test` posts | no (selector + hash) | yes | membership unresolved; fingerprint unchanged |

No cohort shows evidence of a preservation violation.

### Effect on the Stage 1 verdict

**The Stage 1 verdict of `PASS WITH LIMITATION` is unchanged.**

The original caution — that full all-event post-row equality is not claimed —
remains correct, because a before-edit row inventory was never captured and a
direct row-level diff is therefore impossible. What this reconciliation adds is
an *explanation* for the mismatch, not a removal of the limitation.

The final reconciliation verdict is **`UNRESOLVED`**, derived per the task's
rule that an unresolved reconciliation forces an unresolved final verdict. That
verdict reflects one specific gap — the 275-versus-285 relationship — and not a
general failure of the preservation evidence. Every fingerprint that can be
compared matches exactly, and no protected record was identified as changed.

### Newly established facts

1. The digest serialization used by the Stage 1 baseline is tab-separated,
   ordered by `ID`.
2. The after-edit all-event digest is exactly reproducible and correct.
3. The all-event digest mismatch is explained by snapshot timing and six
   externally documented writes.
4. The 276-versus-275 TSV discrepancy is explained by post 25441 lacking
   `_event_source` meta.
5. The clone's `stage32_export_test` cohort is empty, consistent with the
   recorded deletion of the 285 leftovers.

### Remaining limitations

1. The 285 cohort's membership cannot be determined; no ID list exists or can be
   reconstructed.
2. The before-edit all-event digest cannot be recomputed, so no direct
   row-level diff of the two captures is possible.
3. The 14 probe-associated events have no enumerated ID list or fingerprint pair.

### Evidence

Full detail is in
[`docs/evidence/2026-11-15-stage-1-preservation-reconciliation/`](../evidence/2026-11-15-stage-1-preservation-reconciliation/):

- [`00-reconciliation-scope.md`](../evidence/2026-11-15-stage-1-preservation-reconciliation/00-reconciliation-scope.md) — branch, HEAD, worktree state, files inspected, read-only access method and enforcement verification.
- [`01-all-event-post-digest.md`](../evidence/2026-11-15-stage-1-preservation-reconciliation/01-all-event-post-digest.md) — digest recovery, reproduction, hypotheses tested and classification.
- [`02-stage32-fixture-cohorts.md`](../evidence/2026-11-15-stage-1-preservation-reconciliation/02-stage32-fixture-cohorts.md) — cohort identities, current-state verification and classification.
- [`03-protected-cohort-status.md`](../evidence/2026-11-15-stage-1-preservation-reconciliation/03-protected-cohort-status.md) — status of every protected cohort.
- [`04-final-reconciliation.md`](../evidence/2026-11-15-stage-1-preservation-reconciliation/04-final-reconciliation.md) — executive summary and final verdict.

### Read-only access

The Stage 1 captures used the `wordpress` application account, which holds
`ALL PRIVILEGES` and is not read-only. A dedicated `conexao_ro` account with
`SELECT` grants on both databases was created for this reconciliation, and its
enforcement was verified empirically before any data query: `SELECT` succeeded,
while `CREATE` and `UPDATE` were both rejected with `ERROR 1142`. All queries
were plain `SELECT`/`SHOW` against base tables.

_Last verified: 2026-11-15 by the Stage 1 implementation and isolated verification; preservation reconciliation added 2026-11-15_
