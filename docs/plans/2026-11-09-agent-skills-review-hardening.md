# Plan — Agent skills review and hardening

> **This is a template.** Copy it to
> `docs/plans/<date>-<topic>.md` and fill it in. Do **not** edit this file to
> turn it into a repository-specific plan, and do not leave a filled-in plan at
> this path.
>
> Required by [`docs/engineering-standard.md`](../engineering-standard.md) §13.2
> before multi-file work, and before anything that changes **content, routes or
> English/Polylang**. A short task may answer the headings inline, but it may
> not skip the impact sections — an unstated impact is the thing this document
> exists to prevent.

| | |
|---|---|
| **Task title** | Agent skills review and hardening |
| **Date** | 2026-11-09 |
| **Author / agent** | AI assistant using Copilot SDK in VS Code |
| **Branch** | commemorative-events |
| **Repository baseline (starting SHA)** | 38c130d8188cba312f92ee96511df8540f7bdba0 |
| **Applicable skill(s)** | `wp-repository`, `wp-update-docs` |
| **Plan status** | approved |

## 1. Scope

This task is a narrow corrective reconciliation of the agent-skills review and
hardening already pushed at `a52bad7` on branch `commemorative-events`. It does
not repeat the 15-skill review or redesign the skill system. It fixes six
governance defects found when independently reviewing the pushed branch:

1. The documentation-drift gate counts AGENTS.md as 250 lines (off-by-one from
   `count("\n") + 1` on a 249-line file ending in a newline) and fails the
   `<250` policy although the file meets the target.
2. Both governance validators accept a `_Last verified:` marker anywhere in a
   document instead of requiring it as the final non-empty line.
3. The living-document inventory omits canonical commemorative sources
   (`docs/commemorative-events.md`, `docs/commemorative-content.md`,
   `docs/plugins/conexao-commemorative.md`) and hand-codes a plugin list
   instead of deriving plugin docs from `plugins.json`.
4. Failure-handling sections in `wp-testing`, `wp-write-in-process-test` and
   `wp-http-acceptance-matrix` still direct automatic stack starts, seeding and
   other side effects, contradicting their own preconditions and `AGENTS.md`.
5. The task-specific plan at `docs/plans/2026-11-09-agent-skills-review-hardening.md`
   is an unfilled template copy.
6. The task report at `docs/reports/2026-11-09-agent-skills-review-hardening.md`
   has stale fields (title, SHAs, file counts, working-tree state, scope
   completeness).

This is documentation/governance only. No runtime files under `wp-content/`,
no content, database, production, release or deployment changes, and no
Flutter/mobile repository work.

## 2. Explicit non-goals

- Do **not** repeat the full 15-skill review or redesign the skill system.
- Do **not** change `plugins.json` unless the audit proves it necessary (it does
  not; the registry is already correct).
- Do **not** hand-edit any generated region (the `<!-- BEGIN GENERATED PLUGIN
  REGISTRY -->` blocks in `AGENTS.md`, `README.md`, `docs/plugins/README.md` and
  `docs/plugins/conexao-*.md`).
- Do **not** touch `wp-content/`, production state, the database, releases,
  deployments, or the Flutter/mobile repository.
- Do **not** commit, push, deploy or perform production actions without separate
  explicit authorisation.
- Do **not** rewrite historical reports/audit corpus as a blanket required set;
  preserve the existing distinction between living references and immutable
  historical records.

## 3. Relevant authoritative documents

- `AGENTS.md` — orientation, scope, safety rules, authoritative-source map.
- `docs/engineering-standard.md` — §9.1 (layout and ownership), §9.2 (last-verified
  markers, no root reports, AGENTS.md <250 lines), §13 (skills), §13.2 (read
  AGENTS.md + standard, use templates, no second source of truth).
- `docs/README.md` — documentation index and plugin registry map.
- `plugins.json` — single authoritative plugin registry (source for derived
  plugin documentation paths).
- `tests/scripts/verify-documentation-drift.py` — Stage L documentation-drift gate.
- `tests/scripts/verify-agent-governance.py` — Stage K agent-governance gate.
- `docs/templates/plan.md` — plan template (kept unchanged).
- `docs/templates/report.md` — report template (kept unchanged).
- `docs/development.md` — local environment guidance (inspect first, reuse running
  stack, approval before side effects).

Procedures live in skills, not in this plan: `wp-repository` (orient/plan),
`wp-update-docs` (documentation truthfulness).

## 3a. Skill workflow

| | |
|---|---|
| **Skill** | `wp-repository`, `wp-update-docs` |
| **Its preconditions** | Read `AGENTS.md` and `docs/engineering-standard.md`; confirm branch/HEAD/worktree/stashes; inspect before editing; preserve existing worktree changes and stashes. |
| **Its guardrails that bind this task** | No `wp-content/` changes; no `plugins.json` change unless proven necessary; no hand-editing of generated regions; no production writes; no Flutter/mobile repository work; no commit/push without authorisation. |
| **Its verification commands** | `python3 tests/scripts/verify-documentation-drift.py`, `python3 tests/scripts/verify-agent-governance.py`, `php scripts/generate-registry-docs.php --check`, `./scripts/run-tests.sh --scripts`, `git diff --check`. |
| **Its evidence location** | `docs/evidence/<date>-<stage>/` (only if a real evidence folder is created; do not create an empty one). |

## 4. Current-state findings

Verified by reading the code and running the gates:

- Branch `commemorative-events`, HEAD `a52bad7650d4289f030f0c6c230552d01113fc52`
  (`chore: review and harden agent skills documentation`), previous review
  baseline `38c130d8188cba312f92ee96511df8540f7bdba0`. Worktree clean; stashes
  preserved (8 stashes on `feat/christmas-easter-egg` and `master`).
- `git diff 38c130d..HEAD` shows 22 changed paths: 20 modified + 2 added
  (603 insertions, 78 deletions), all documentation/skills/governance.
- `AGENTS.md` has 249 physical lines ending in a newline, but
  `count("\n") + 1` reports 250, failing the `<250` policy.
- `verify-documentation-drift.py` uses `LAST_VERIFIED.search(body)` (marker
  anywhere) instead of requiring the marker as the final non-empty line.
- `verify-agent-governance.py` uses `LAST_VERIFIED_RE.search(body)` for skills
  and the skills index (same defect).
- `LIVING_DOCS` omits `docs/commemorative-events.md`,
  `docs/commemorative-content.md` and `docs/plugins/conexao-commemorative.md`,
  and hand-codes plugin docs instead of deriving them from `plugins.json`.
- `docs/plugins/conexao-commemorative.md` and several other plugin docs lack a
  `_Last verified:` marker.
- `wp-testing`, `wp-write-in-process-test` and `wp-http-acceptance-matrix`
  failure-handling sections still say "Seed it" / "Start the stack", contradicting
  their preconditions and `AGENTS.md`.
- `docs/plans/2026-11-09-agent-skills-review-hardening.md` is an unfilled
  template copy (all substantive sections blank).
- `docs/reports/2026-11-09-agent-skills-review-hardening.md` has stale fields:
  title `# Report — <stage / task name>`, final SHA = old start SHA
  `38c130d...` ("no commit made"), file count `19 modified + 1 new` (actual is
  20 modified + 2 added), working-tree statement reflects pre-commit dirty state,
  scope marked complete although plan/index corrections were not done earlier.
- `docs/README.md` has the 2026-11-09 marker before two older 2026-11-08 markers
  (most recent marker must be final non-empty line).
- `docs/reports/README.md` lacks the 2026-11-09 report row.
- Baseline gate results: documentation drift FAIL (AGENTS.md line count),
  agent governance PASS (388/0), registry drift PASS (zero writes), script
  contract 19 passed / 2 failed (pre-existing `dist/release.json` version
  mismatches, unrelated to this change).

## 5. Proposed approach

Smallest coherent changes, documentation/governance only:

1. **Fix the AGENTS.md line count** in `verify-documentation-drift.py`:
   replace `agents_body.count("\n") + 1` with `len(agents_body.splitlines())`
   (splitlines() does not invent a trailing empty line for a final newline).
   Add a negative/edge-case proof: 250 physical lines counts as 250, 249 as 249.
   Do not relax the `<250` policy.

2. **Strengthen terminal-marker validation** in both gates: the last non-empty
   line (trailing blank lines allowed) must be a valid `_Last verified:`
   marker. Add a discriminating negative proof: a document with a valid marker
   followed by ordinary content must fail; the same document with the marker as
   its final non-empty line must pass. Keep both gates aligned on one terminal
   marker definition.

3. **Reconcile the living-document inventory** with §9.1: add the commemorative
   references (`docs/commemorative-events.md`, `docs/commemorative-content.md`)
   and derive active plugin documentation paths from `plugins.json` (the
   `documentation` field of each `load_order` entry). Add `_Last verified`
   markers to plugin docs lacking them, in safe manual areas below generated
   blocks — never inside a generated region. Support the marker through the
   `docs/plugins/README.md` generator's manual area (it already has one below
   its generated block).

4. **Align side-effect wording** in `wp-testing`, `wp-write-in-process-test` and
   `wp-http-acceptance-matrix` failure handling: inspect first, reuse running
   environment, request explicit approval before side effects, otherwise record
   `BLOCKED` / `not tested`. Review `wp-add-content-type` and
   `wp-frontend-perf` (no change needed — their failure handling does not
   direct side effects).

5. **Fill the task-specific plan** (this document) with facts from the actual
   diff and report; write `None — documentation/governance only` for
   non-applicable impact sections.

6. **Reconcile the task report**: fix title, SHAs, file counts, working-tree
   state, scope completeness; represent plan/index corrections as a follow-up
   reconciliation, not as already-completed work.

7. **Update indexes**: add the report to `docs/reports/README.md` and update its
   terminal marker; move the 2026-11-09 marker to the end of `docs/README.md`.

Options rejected:
- Relaxing the `<250` AGENTS.md policy: rejected — the policy is intentional;
  the fix is the off-by-one count, not the threshold.
- Hand-maintaining a second plugin list in `LIVING_DOCS`: rejected — §9.1 and
  §1.8 require deriving from `plugins.json`.
- Silently excluding generated plugin docs: rejected — they are canonical
  sources owned by plugin maintainers; add the marker in the manual area or
  document a justified exception.

## 6. Files expected to change

| Path | Change | Why |
|---|---|---|
| `tests/scripts/verify-documentation-drift.py` | Fix line count; terminal-marker validation; extend LIVING_DOCS with commemorative docs + plugin docs from plugins.json; add negative proofs | Fix findings 1, 2, 3 |
| `tests/scripts/verify-agent-governance.py` | Terminal-marker validation for skills and skills index | Finding 2 |
| `.agents/skills/wp-testing/SKILL.md` | Align failure-handling side-effect wording | Finding 4 |
| `.agents/skills/wp-write-in-process-test/SKILL.md` | Align failure-handling side-effect wording | Finding 4 |
| `.agents/skills/wp-http-acceptance-matrix/SKILL.md` | Align failure-handling side-effect wording | Finding 4 |
| `docs/plugins/conexao-data-model.md` | Add `_Last verified` marker (manual area) | Finding 3 |
| `docs/plugins/conexao-content.md` | Add `_Last verified` marker (manual area) | Finding 3 |
| `docs/plugins/conexao-admin-ux.md` | Add `_Last verified` marker (manual area) | Finding 3 |
| `docs/plugins/conexao-event-runtime.md` | Add `_Last verified` marker (manual area) | Finding 3 |
| `docs/plugins/conexao-commemorative.md` | Add `_Last verified` marker (manual area) | Finding 3 |
| `docs/plugins/conexao-event-importer.md` | Add `_Last verified` marker (manual area) | Finding 3 |
| `docs/plugins/conexao-leisure-migration.md` | Add `_Last verified` marker (manual area) | Finding 3 |
| `docs/plugins/conexao-sponsor-migration.md` | Add `_Last verified` marker (manual area) | Finding 3 |
| `docs/plugins/conexao-en-translation.md` | Fix marker to use "by" (terminal) | Finding 3 |
| `docs/plugins/README.md` | Add `_Last verified` marker (manual area below generated block) | Finding 3 |
| `docs/plans/2026-11-09-agent-skills-review-hardening.md` | Fill all substantive sections | Finding 5 |
| `docs/reports/2026-11-09-agent-skills-review-hardening.md` | Reconcile title, SHAs, counts, state, scope | Finding 6 |
| `docs/reports/README.md` | Add 2026-11-09 report row; update terminal marker | Finding 7 |
| `docs/README.md` | Move 2026-11-09 marker to end (final non-empty line) | Finding 7 |

## 7. Files explicitly expected NOT to change

| Path | Why |
|---|---|
| `wp-content/plugins/*` | No runtime/plugin code changes; documentation/governance only |
| `plugins.json` | Registry already correct; no change proven necessary |
| `docs/templates/plan.md` | Canonical template kept unchanged |
| `docs/templates/report.md` | Canonical template kept unchanged |
| `.agents/skills/README.md` | Skill index already correct; no skill added/removed |
| Generated `<!-- BEGIN GENERATED PLUGIN REGISTRY -->` regions | Never hand-edited; owned by `scripts/generate-registry-docs.php` |
| `docs/evidence/` | No empty evidence directory created; only if a real folder results |

## 8. Content / data impact

- Records created / updated / deleted: None — documentation/governance only.
- Portable identifier strategy: N/A (no content writes).
- Does this write content? No. The six-step contract (§5.2) does not apply.
- PT content changed? No. PT immutability holds; no PT URLs, slugs or record
  identity changed.

## 9. Route / HTTP impact

- Routes added, removed or changed: None.
- Redirects needed: None.
- Sitemap coverage: None.
- HTTP matrix rows to add or update: None.

## 10. Polylang / English impact

- Translated post types / taxonomies affected: None.
- Is PT immutable in this change? Yes.
- B1 / B2 for each destination: N/A (no translation rollout).
- Canonical, hreflang and language-switcher behaviour: None changed.
- Language-scoped cache keys affected: None.
- Completeness gate that must reach `0`: N/A (no EN coverage change).

## 11. Security impact

- Capabilities checked: None.
- Nonces added/changed: None.
- Sanitisation / escaping: None.
- SQL: prepared statements or WP APIs: N/A (no database access).
- REST surface change: None.
- Secrets involved: None; credentials come from the environment only.

## 12. Performance impact

- Queries added or removed: None.
- Caching: None.
- Assets or images affected: None.
- Expected measurable improvement: None — documentation/governance only.

## 13. Production impact

| Question | Answer |
|---|---|
| Will anything be written to production? | **no** |
| Will a deploy, upload or activation happen? | **no** |
| Who performs it, and how (production has no SSH/WP-CLI)? | N/A — no production action |
| What is the verification of the production result? | N/A — no production action |

## 14. Test plan

- In-process suite(s) to add or update: None (no PHP changed).
- Acceptance area / matrix rows: None.
- Script contract impact: `./scripts/run-tests.sh --scripts` — verify the 2
  pre-existing `dist/release.json` version mismatches remain the only failures.
- Registry drift check: `php scripts/generate-registry-docs.php --check` —
  zero writes, must pass.
- What constitutes a pass, numerically: documentation drift 0 failed, agent
  governance 0 failed, registry drift 0 writes, script contract 2 pre-existing
  failures only (no new failures).

## 15. Acceptance matrix plan

None — no routes or HTTP behaviour changed.

## 16. Rollback plan

How to undo this change, in order:

1. `git revert` the commit (or `git reset --hard` to the pre-change HEAD if
   uncommitted) restores all governance files.
2. Plugin-doc markers are additive text; reverting removes them.
3. No data, content, or production state was changed, so no data rollback is
   needed.
4. Pre-change state captured: HEAD `a52bad7`, clean worktree, stashes preserved.

Rollback does **not** cover content changes (none made).

## 17. Documentation plan

| Document | Change | Procedure moved to (skill) |
|---|---|---|
| `tests/scripts/verify-documentation-drift.py` | Fix line count, terminal markers, living docs inventory | N/A (gate logic, not a procedure) |
| `tests/scripts/verify-agent-governance.py` | Terminal-marker validation | N/A (gate logic) |
| `docs/plans/2026-11-09-agent-skills-review-hardening.md` | Filled from template | N/A |
| `docs/reports/2026-11-09-agent-skills-review-hardening.md` | Reconciled against actual commit | N/A |
| `docs/reports/README.md` | Added 2026-11-09 report row + terminal marker | N/A |
| `docs/README.md` | Moved 2026-11-09 marker to end | N/A |
| `docs/plugins/*.md` (8 files) | Added `_Last verified` markers in manual areas | N/A |

No procedure was removed from a document; procedures live in skills and were not
relocated.

## 18. Release implications

- Component version bump(s): None — no code changed.
- Effect on the artifact allowlist: None — `plugins.json` unchanged.
- Is a release in scope? **No.** Documentation/governance only; no release was
  built.

## 19. Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| Doing too much — scope creep into unrelated refactoring of skills or docs | Medium | Keep changes to the six findings only; do not touch unrelated skill content |
| Hand-editing a generated region | Low | Only add markers below `<!-- END GENERATED ... -->` blocks; verify with `--check` |
| Relaxing the `<250` AGENTS.md policy | Low | Fix the count, not the threshold; negative proof guards against regression |
| Stamping files as verified without review | Low | Markers added only after reading each document |
| Claiming the working tree is clean when it is not | Low | Verify with `git status --short`; report actual state |

## 20. Verification gates

1. `python3 tests/scripts/verify-documentation-drift.py` — must exit 0
   (AGENTS.md 249 lines, terminal markers on all 28 living docs, negative
   proofs pass).
2. `python3 tests/scripts/verify-agent-governance.py` — must exit 0
   (terminal markers on all 15 skills + index).
3. `php scripts/generate-registry-docs.php --check` — must exit 0 (zero writes,
   no generated region manually edited).
4. `./scripts/run-tests.sh --scripts` — report exact pass/fail; 2 pre-existing
   `dist/release.json` mismatches only, no new failures.
5. `git diff --check` — must exit 0.
6. `git status --short` / `git stash list` — confirm worktree and stash state;
   no unsupported claim of cleanliness.

## 21. Completion criteria

The task is complete when:

- All six findings from the reconciliation prompt are corrected.
- The documentation-drift gate passes (including the 249-line AGENTS.md case
  and terminal-marker negative/positive proofs).
- The governance gate passes for all 15 active skills with terminal markers.
- Registry drift remains zero-write and passes.
- The script-contract layer reports exact results; the 2 pre-existing
  `dist/release.json` mismatches are classified as pre-existing, no new
  failures introduced.
- The task-specific plan has no blank template sections.
- The task report is reconciled against the actual commit (`a52bad7`).
- `docs/reports/README.md` and `docs/README.md` have correct terminal markers.
- No `wp-content/` files changed; no runtime, content, DB, production, release
  or mobile changes occurred.
- Final worktree and stash state reported honestly (no claim of cleanliness
  unless `git status --short` is empty).

_Last verified: 2026-11-09 by the agent skills review and hardening_
