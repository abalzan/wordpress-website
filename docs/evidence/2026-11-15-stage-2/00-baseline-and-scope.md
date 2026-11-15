# Stage 2 — baseline, scope and environment

## 1. Repository state at Stage 2 start

| Item | Value |
|---|---|
| Branch | `event-import-improvements` |
| HEAD | `b5a3ee92be0f3e547be1f3be7e291afd3d924e60` |
| `git status --short` | empty — clean worktree |
| Last commits | `b5a3ee9 fix: correct Reconciliation A classification and update follow-up findings for post 170981`; `44c885b Implement Stage 1 …`; `c76d836 …` |

The Stage 0 probe-source work, the Stage 0.5 test-safety work, the Stage 1
implementation and the Stage 1 preservation reconciliation are all present in
this HEAD and in the worktree. Stage 2 began from that state and did not reset,
clean, stash or discard anything.

## 2. Historical work identified before editing

| Stage | Implementation | Evidence | Report |
|---|---|---|---|
| Stage 0 | G1 probe source deactivation | `docs/evidence/2026-11-14-stage-0-event-importer-probe-deactivation/` | `docs/reports/2026-11-14-stage-0-event-importer-probe-deactivation.md` |
| Stage 0.5 | operator-store isolation, disposable clone, fail-closed bootstrap | `docs/evidence/2026-11-14-stage-0-5/` | `docs/reports/2026-11-14-stage-0-5-test-safety.md` |
| Stage 1 | P1 breaker, P6 Run All identity, P2 Heritage pre-detail filtering | `docs/evidence/2026-11-15-stage-1/` | `docs/reports/2026-11-15-stage-1-event-importer-run-coordination.md` |
| Stage 1 reconciliation | 275-vs-285 membership, unreproducible before-edit digest, post 170981 timing | `docs/evidence/2026-11-15-stage-1-preservation-reconciliation/` | section in the Stage 1 report |

None of those files was modified by Stage 2 (see
`07-preservation-limitations-and-status-inventory.md` §4).

## 3. Test environment identity (disposable database)

Every WordPress-dependent check in Stage 2 ran against the disposable clone, not
the shared development database. Identity was verified with the repository's own
raw pre-WordPress preflight before the first suite:

```bash
docker compose exec -T \
  -e WORDPRESS_DB_NAME=conexao_stage05_test_5bd9ece5c032 \
  -e CONEXAO_TEST_DB_NAME=conexao_stage05_test_5bd9ece5c032 \
  -e CONEXAO_TEST_SHARED_DB_NAME=wordpress \
  wordpress php /var/www/html/tests/lib/isolated-db.php
```

Output:

```
ISOLATED_DB_PREFLIGHT=PASS database=conexao_stage05_test_5bd9ece5c032 host=db
```

`tests/bootstrap.php` additionally refuses to continue when the post-bootstrap
`DB_NAME` is not the requested `CONEXAO_TEST_DB_NAME`, so a suite cannot silently
fall back to the shared database.

All suites were run through `./scripts/run-tests.sh` (which performs the same
preflight) or directly with the identical environment variables.

## 4. Shared-database read-only access — NOT AVAILABLE

Stage 2 requires a **verified read-only** method before any shared-database query
(§3.7 of the task): either an existing independently verified read-only account
or an empirically verified engine-enforced read-only transaction.

| Candidate method | Status |
|---|---|
| Existing read-only account | **None exists.** The `conexao_ro` account used by the Stage 1 reconciliation was **dropped** afterwards; that is recorded in `docs/evidence/2026-11-15-stage-1-preservation-reconciliation/03-protected-cohort-status.md` §6 ("the `conexao_ro` account was dropped and no read-only credentials were available"). |
| Engine-enforced `START TRANSACTION READ ONLY` | Only reachable through an existing account. The only accounts are the WordPress application account (all privileges) and `root`. Stage 2 rules explicitly forbid using the application account for shared-database queries, and creating/altering accounts or privileges is forbidden. |

Consequence, stated plainly:

- **No shared-database query was performed in Stage 2.** No `SHOW`, no `SELECT`,
  no fingerprint, no status inventory was taken from `wordpress`.
- No database user was created, dropped, granted, revoked or altered.
- Because the shared database was never connected to, Stage 2 also made **no
  shared-database writes** of any kind (not merely "no content writes").

This is a limitation, not a pass: the before/after `source_not_found` delta and
the before/after preservation-cohort fingerprints **could not be captured**, so
the final verdict is `PASS WITH LIMITATION` (see
`07-preservation-limitations-and-status-inventory.md`).

## 5. Pre-change test-run attempt (discarded)

A full importer suite run was started before the first edit, but the harness
executes suites sequentially from the live worktree. Implementation edits landed
while it was running, so part of that run executed pre-change code and part
post-change code. It is **not** a valid baseline and is not used as evidence.

The authoritative test evidence for Stage 2 is the post-implementation run
recorded in `05-tests.txt`. The historical pre-Stage-2 suite state is recorded in
`docs/evidence/2026-11-15-stage-1/02-quality-gates.txt`.

## 6. Scope actually implemented

| Finding | Work |
|---|---|
| P4 | Handler inventory + coverage verification; no handler needed a gate re-implementation. All gaps found were P3 contract gaps, fixed under P3/P4. |
| P3 | Mondello Park (transport/non-200 returned `[]`), Laois Tourism, IVVCC, Motorsport Ireland, iCalendar (empty body / unparsable / no feed returned `[]` as a *complete* result). |
| P5 | New per-field enrichment state, Heritage Week declaration, write-path preservation, description participation in change detection (declared-enrichment payloads only). |
| P10 | Keyset-paginated, gate-first, two-phase absence reconciliation. |

Nothing from P7, P8, P9, P11, P12 or P13 was implemented. No production action,
provider request, commit, push or deploy was performed.
