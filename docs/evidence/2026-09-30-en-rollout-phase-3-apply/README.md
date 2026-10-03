# Phase 3 — EN translation rollout apply (BLOCKED at the apply boundary)

Date: 2026-09-30 · Branch `i18n` · HEAD `6bc4a6b` · Target `https://conexaobr.ie`
**Production writes: 0. HTTP verbs used: GET only.**

Round 2 re-ran after the authoriser resolved both open questions (the 13
`conexao_category` creates are **in scope**; the invariant is **0 PT
canonical-content mutations + 0 PT authored-content drift**). Both decisions
are satisfied by the live plan. The apply itself still requires a human to press
the button — reasoning in `14-apply-reachability-reverified-phase3r2.txt`.

**State change since round 1: the two temporary rollout plugins are now installed
and ACTIVE in production** (`conexao-translation-rollout`, `conexao-en-translation`).
Prerequisite (1) is satisfied; only step (4) — the Apply click — remains human-only.

| file | what it proves |
|---|---|
| `09-dry-run-plan-revalidated-phase3r2.json` | Round-2 engine re-plan from live production: 425 manifest objects, 126 B1, 299 B2, 13 taxonomy creates, every safety counter 0. |
| `10-mutation-snapshot-phase3r2.json` | Round-2 pre-apply snapshot, 438 objects, **byte-identical** to the round-1 snapshot. |
| `11-dry-run-summary-phase3r2.txt` | Round-2 console summary with all acceptance gates and the three digests. |
| `12-run-tests-phase3r2.log` | Round-2 `./scripts/run-tests.sh`: 63/63 suites, 4,216 assertions, 1 pre-existing i18n failure. |
| `13-permanent-gates-phase3r2.log` | Round-2 `verify-permanent-gates.py`: 7 gates, 6 passed, 1 pre-existing failure. |
| `14-apply-reachability-reverified-phase3r2.txt` | Re-verified apply boundary: tooling now installed, but 0 REST routes, admin cookie session required (302 to wp-login). |
| `08-safety-ledger.txt` | Round-2 zero-write ledger at the top; round-1 record retained below as history. |

## Round-1 files (retained)

| file | what it proves |
|---|---|
| `01-dry-run-plan-revalidated.json` | The repository's own engine re-planned against live production. 425 objects, 126 B1, 299 B2, every safety counter 0. |
| `02-mutation-snapshot.json` | Read-only pre-apply snapshot, 438 objects. |
| `03-dry-run-summary.txt` | Console summary incl. all acceptance gates and the three digests. |
| `04-run-tests-output.log` | `./scripts/run-tests.sh` — 63/63 suites, 4,216 assertions, 1 pre-existing i18n failure. |
| `05-permanent-gates.log` | `verify-permanent-gates.py` — 7 gates, 6 passed, 1 pre-existing failure. |
| `06-apply-reachability-audit.txt` | Why the apply cannot be run from here: no WP-CLI in production, 0 REST routes, admin_post + nonce only. |
| `07-pre-apply-http-state.txt` | Pre-apply production EN route status and canonical behaviour (GET only). |

## Key numbers (round 2, unchanged)

* Mutation objects **438** = 425 manifest objects + 13 `conexao_category` creates.
* B1 **126** · B2 **299** · taxonomy **13** · conflicts **0** · duplicates **0** ·
  PT overwrites **0** · unclassified **0** · `missing_source` **0** — all MATCH.
* Plan digest `7dc4f56e…` and snapshot digest `d679f1c1…` are **identical** to the
  committed baseline. `plan.json` differs in exactly one field,
  `report/generated_at_utc`; `snapshot.json` is byte-identical.
* PT identity digest `76620b66…` identical to baseline; **8/8 post types identical**; zero PT drift.
* B2 targets confirmed: `_leisure_excerpt_en` ×289, `_provider_excerpt_en` ×10.
* Applied: **0**. Created: **0**. Linked: **0**. Field updates: **0**. Rollbacks: **0**.

