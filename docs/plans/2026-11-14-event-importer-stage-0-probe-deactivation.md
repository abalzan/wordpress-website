# Plan — Event Importer Stage 0 probe-source deactivation

| | |
|---|---|
| **Task title** | Event Importer Stage 0 probe-source deactivation |
| **Date** | 2026-11-14 |
| **Author / agent** | Codex |
| **Branch** | `event-import-improvements` |
| **Repository baseline (starting SHA)** | `464a353c8dc425d34cbf226668ffd889f8d4fec0` |
| **Applicable skill(s)** | `wp-repository`, `wp-content-change` |
| **Plan status** | blocked — the authorised 18 + 2 preflight failed before any write |

## 1. Scope

Freeze a manifest from the current local `conexao_event_sources` registry containing exactly the 18 existing IDs matching `^g1_probe_`, plus `g2_cavan_probe` and `g4c_budget_probe`. If and only if that inventory is exactly 20 distinct existing sources, change only their `status` from its current value to `inactive`, using the established local-only status tool. Capture a pre-write snapshot, dry-run, apply, idempotence proof and numeric verification gate.

## 2. Explicit non-goals

This does not implement Stage 0.5 or Stages 1–5; run importer or test suites that can mutate logs; contact an upstream provider; modify events, post meta, taxonomies, attachments, Polylang data or source settings; delete sources or probe events; alter production; or touch the Flutter/mobile repository.

## 3. Relevant authoritative documents

`AGENTS.md` owns repository scope and safety; `docs/engineering-standard.md` §§0, 5.2, 12 and 14 own the content-change and review rules; `docs/content-model.md` owns event/source identity context; `scripts/README.md` owns the supported status tool contract; `docs/plugins/conexao-event-importer.md` owns importer behaviour. The supplied audit brief authorises precisely this Stage 0 operation and defines the 18 + 2 fail-closed manifest rule.

## 3a. Skill workflow

| | |
|---|---|
| **Skill** | `wp-repository`; `wp-content-change` |
| **Its preconditions** | Current clean worktree recorded; local Docker stack inspected; source registry can be inventoried read-only; exact scope and rollback defined before write. |
| **Its guardrails that bind this task** | Use portable source IDs, dry-run first, snapshot before write, `--apply` explicitly, prove idempotence, make no production write and never use a live wildcard as the write selector. |
| **Its verification commands** | Read-only inventory; `set-event-source-status.php --dry-run`; `--apply`; repeat dry-run; read-only gate query. |
| **Its evidence location** | `docs/evidence/2026-11-14-stage-0-event-importer-probe-deactivation/` |

## 4. Current-state findings

The repository baseline is `464a353`. The existing `scripts/set-event-source-status.php` tool is local-only, defaults to dry-run, changes only source-record `status`, uses explicit `--ids` as an allowlist and is idempotent. The local Docker WordPress and database containers are already running, so no environment lifecycle action is needed. The read-only raw registry inventory found 154 sources and exactly 18 anchored `g1_probe_*` IDs, but neither `g2_cavan_probe` nor `g4c_budget_probe` exists. Therefore the manifest contains only 18 distinct existing records, not the required 20, and Stage 0 must stop with zero writes.

The absence is structural, not drift. Both named IDs are runtime test fixtures — `test-source-pagination-completeness.php:470-485` saves `g2_cavan_probe`, `test-request-budget.php:276-289` saves `g4c_budget_probe` — and Run All scope is `get_active()` over the registry (`class-event-importer.php:195`, `class-event-sources.php:256`), so neither ID can be an import target and neither has a `status` to change. The 18 G1 records, by contrast, are genuine live targets: `test-source-fetch-failure.php:156` generates them, all 18 carry source-health records and all 18 were imported with HTTP 429 during the audited Run All (17:06–17:25). Ten of them plus `eventbrite_laois` and the bare `eventbrite` source walk one identical Laois discovery feed — 12 duplicate enumerations per Run All. Evidence: `preflight-recheck.json`.

## 5. Proposed approach

Use a raw read-only registry inventory to enumerate the anchored G1 IDs and named sources, then freeze their literal IDs in a versioned evidence manifest. Abort before any `--apply` if the count is not 18 + 2 or if an ID is absent/duplicated. The inventory failed because both named IDs are absent, so no manifest is frozen for writing and no status tool invocation occurs. The rejected alternative is deactivating the 18 G1 sources alone or adding bespoke importer code: the former expands the authorised condition and the latter duplicates existing behaviour.

## 6. Files expected to change

| Path | Change | Why |
|---|---|---|
| `docs/plans/2026-11-14-event-importer-stage-0-probe-deactivation.md` | Add | Required pre-write plan. |
| `docs/evidence/2026-11-14-stage-0-event-importer-probe-deactivation/*` | Add | Inventory, frozen manifest, snapshot, dry-run, apply, idempotence and gate evidence. |
| `docs/reports/2026-11-14-stage-0-event-importer-probe-deactivation.md` | Add | Required factual outcome, rollback and limitations report. |

## 7. Files explicitly expected NOT to change

`wp-content/`, `scripts/`, `plugins.json`, `compose.yaml`, routes, tests and all application code remain unchanged: this is an existing-tool local data operation, not an implementation change.

## 8. Content / data impact

- Records created / updated / deleted: 0 / exactly 20 source configuration records' `status` fields / 0.
- Portable identifier strategy: authored source ID stored in the registry option; no post or attachment IDs are used.
- The six-step content-change contract applies: inventory, frozen manifest, dry-run, snapshot, idempotent apply, verification gate.
- PT content changed: no; no post is read or written as part of the change.

## 9. Route / HTTP impact

No routes, redirects, sitemap entries or HTTP matrix rows change. No external HTTP request is made.

## 10. Polylang / English impact

No translated type, taxonomy, language relationship, PT record, EN record, cache key or completeness gate is affected.

## 11. Security impact

No capability, nonce, SQL, REST surface or secret change. The existing script boots the local WordPress install and its status method; input is the frozen explicit allowlist. No credentials are printed or stored.

## 12. Performance impact

No request, query, cache or asset code changes. The next source-selection operation must exclude all 20 inactive sources; this is verified against the effective registry rather than by running an import.

## 13. Production impact

| Question | Answer |
|---|---|
| Will anything be written to production? | **no** |
| Will a deploy, upload or activation happen? | **no** |
| Who performs it, and how (production has no SSH/WP-CLI)? | Nobody; operation is local-only. |
| What is the verification of the production result? | Not applicable. |

## 14. Test plan

No test suite is run: the supplied audit requires Stage 0.5 test-log protection before tests that might write or clear the operator log. The data operation is verified by inventory, tool output and a numeric status gate: target sources not inactive = 0; sources outside the frozen manifest changed = 0.

## 15. Acceptance matrix plan

No request-visible behaviour changes, so no HTTP acceptance rows apply.

## 16. Rollback plan

The pre-write snapshot records every target's prior status. Restore only records whose prior status was not `inactive`, using the same explicit-ID tool and the status from that snapshot. Rollback changes no events or other source fields; it cannot restore any unrelated manual registry change made after this operation.

## 17. Documentation plan

| Document | Change | Procedure moved to (skill) |
|---|---|---|
| Plan, evidence and report named above | Record this one local operation and its evidence. | `wp-repository`, `wp-content-change` |

No permanent product documentation changes: the tooling and its contract already exist.

## 18. Release implications

No component version or registry change. No release is in scope.

## 19. Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| Probe inventory differs from the authorised 18 + 2 scope | Medium | Fail closed before writes and report the exact discrepancy. |
| Scope expands through prefix selection | Low | Freeze literal IDs and use `--ids` only for dry-run and apply. |
| An unrelated source field is modified | Low | Snapshot full target records and use the status-only tool, then compare. |
| Scope creeps into roadmap implementation | Medium | Keep code, tests and source/event imports out of this change. |

## 20. Verification gates

1. Read-only manifest validation reports 20 distinct existing sources: exactly 18 anchored `g1_probe_*`, plus both named IDs.
2. Post-apply gate reports target sources whose status is not `inactive` = 0 and sources outside the frozen manifest changed = 0.
3. Repeating the identical apply reports changed = 0.

## 21. Completion criteria

The manifest is frozen and satisfies the fail-closed inventory rule; only 20 statuses are changed; the snapshot and reversal recipe exist; the repeat apply is a zero-change no-op; the numeric gate is zero; evidence and report state actual results and limitations; no production, importer, event, test-suite or mobile action occurs.

_Last verified: 2026-11-14 by Stage 0 probe-source deactivation_
