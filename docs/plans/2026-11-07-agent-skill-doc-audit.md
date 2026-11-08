# Plan — Comprehensive repository agent, skills and documentation audit

> Filled from [`docs/templates/plan.md`](../templates/plan.md). Required by
> [`docs/engineering-standard.md`](../engineering-standard.md) §13.2 before
> multi-file work. This task is documentation-, skills- and governance-only:
> every impact section below is answered, and the runtime impact is **none**.

| | |
|---|---|
| **Task title** | Comprehensive repository agent, skills and documentation audit |
| **Date** | 2026-11-07 |
| **Author / agent** | Cline (coding agent), acting under the audit task brief |
| **Branch** | `commemorative-events` |
| **Repository baseline (starting SHA)** | `438c2ebb206b0f9dd15094a8b5dea04a9693ebb2` |
| **Applicable skill(s)** | `wp-repository` (entry/orientation), `wp-update-docs` (documentation ownership), `wp-testing` (verification) |
| **Plan status** | approved (the audit brief authorises repository-level agent/skill/documentation improvements and forbids pausing for approval on in-scope changes) |

## 1. Scope

Evidence-driven audit of the repository's agent-instruction surface (root
`AGENTS.md`, `.github/copilot-instructions.md`, the PR template), the 14 active
skills in `.agents/skills/`, the documentation index and canonical docs under
`docs/`, and the governance gates that enforce them — followed by justified,
incremental fixes: broken links, duplicated/contradictory instructions, generator
defects in generated regions, and skill/index inconsistencies. Verification runs
through the repository's own gates; a report with real numbers closes the task.

## 2. Explicit non-goals

- No commits, push, merge, rebase, stash changes, `git reset` or `git clean`.
- No production access, deployment, upload, activation or any production write.
- No application behaviour change: no file under `wp-content/` is touched; the
  generator change alters documentation output only.
- No creation of speculative skills; no skill proliferation (the audit may
  conclude that no new skill is justified, and that conclusion is recorded).
- No rewriting of historical reports/evidence/audit documents; no deletion of
  historical material.
- No touching the Flutter/mobile repository or the retired
  `.agents/legacy-flutter-skills/` material beyond what gates already require.
- No unrelated code refactoring; no test weakened, removed or bypassed.
- No new tool, dependency or custom framework introduced.

## 3. Relevant authoritative documents

| Document | Why it constrains this change |
|---|---|
| `AGENTS.md` | orientation, scope, safety rules (read in full before editing) |
| `docs/engineering-standard.md` §0, §9, §12, §13, §14 | change safety, documentation standard, agent/skill standard, definition of done |
| `docs/README.md` | documentation index; new docs must be indexed in the same change |
| `docs/testing.md` | test model, permanent gates, command reference |
| `.agents/skills/README.md` | skill index of record (must match the directory) |
| `tests/scripts/verify-agent-governance.py` | the enforced skill/instruction contract |
| `tests/scripts/verify-documentation-drift.py` | §9.2 markers, living-doc set, root-file rule |
| `scripts/generate-registry-docs.php` | owns every generated region it emits |
| `docs/templates/plan.md`, `docs/templates/report.md` | required workflow artifacts |

## 3a. Skill workflow

| | |
|---|---|
| **Skill** | `wp-repository` → `wp-update-docs` (with `wp-testing` for verification) |
| **Its preconditions** | repository root as working directory; `git status --short` read (baseline: clean); branch confirmed `commemorative-events`; stash list recorded (9 stashes, `stash@{0}`–`stash@{8}`); no production action in progress |
| **Its guardrails that bind this task** | one owner per fact; no hand-edited generated regions; no second source of truth; behaviour-neutral change; `_Last verified_` on every touched living document; honest limitations |
| **Its verification commands** | `python3 tests/scripts/verify-agent-governance.py`, `php scripts/generate-registry-docs.php --check`, `python3 tests/scripts/verify-documentation-drift.py`, `./scripts/run-tests.sh --scripts`, `./scripts/lint.sh`, full `./scripts/run-tests.sh` |
| **Its evidence location** | `docs/evidence/2026-11-07-agent-skill-doc-audit/` |

## 4. Current-state findings

Baseline captured before any edit (see evidence `baseline/`):

- Worktree **clean**, HEAD `438c2eb`, branch `commemorative-events`, 9 stashes.
- `python3 tests/scripts/verify-agent-governance.py` → **357 passed, 0 failed** (exit 0).
- `php scripts/generate-registry-docs.php --check` → **11 plugins, 20 regions current** (exit 0).
- Only one root `AGENTS.md`; 14 active skills + index; 23 retired Flutter skill
  directories on disk (git-ignored, provenance-only, documented).
- **Defects found** (each with evidence in the report):
  1. `AGENTS.md` repeats the `## Plugins (custom, this repo)` heading (editing defect).
  2. `AGENTS.md` states the CI contract twice (a `**CI.**` paragraph inside
     *Running things* duplicates the `## CI` section verbatim).
  3. `AGENTS.md`'s skill table lists only 10 of the 14 active skills.
  4. Generated region in `AGENTS.md` links `../plugins.json` — broken from the
     repository root; root cause: `render_agents_table()` hard-codes the prefix.
  5. Generated sentence `…release ZIP): .` (empty retired-rollout list) appears
     in 4 files; root cause: `lifecycle_summary_sentences()` does not guard an
     empty list.
  6. `.github/copilot-instructions.md` has 2 broken relative links
     (`docs/engineering-standard.md`, `plugins.json` — resolved against `.github/`),
     lists only 12 of 14 skills, and restates AGENTS.md safety rules, the
     §5.2 content contract, the architecture summary and the plan/build/verify/
     report workflow — a second source of truth for policy.
  7. `.agents/skills/wp-testing/SKILL.md` ends with an orphaned indented
     fragment (`recorded as blocked or not tested — never as verified.`) left
     over from a botched edit; the preceding checklist bullet already says it.
  8. `docs/templates/plan.md` says to copy plans into `docs/reports/`, but every
     recent plan lives in `docs/plans/` (5 plans, all indexed there) and
     standard §9.1's ownership table has no `docs/plans/` row.
  9. Seven further broken relative links in living/canonical docs: 3 in
     `docs/routing.md` (plugin doc links one directory too high), 1 in
     `docs/commemorative-events.md` (theme doc), 2 in
     `docs/translation-manual-operator-runbook.md` (report + evidence), and the
     generated `../../plugins.json` in `docs/deployment.md` (generator hard-codes
     two directory levels for a `docs/` target).
  10. `docs/development.md` §Stage 9 (Guides) still documents
      `run-guide-translation.php` commands — the file no longer exists anywhere
      in the repository, and the same section's prose says the runner was
      retired — a self-contradicting instruction.
  11. A full scan of `docs/`, `.agents/` and `.github/` (536 relative links)
      found 28 broken: 9 are fixed by this change; 20 sit inside historical
      reports/evidence (left untouched per the no-history-rewrite rule) and 1 is
      a self-qualifying "when available" link in `docs/importers/ivvcc-importer.md`
      (ambiguous target — recorded, not changed).
  12. `docs/README.md` lists `reports/2026-10-27-halloween-witch-animation-report.md`
      twice in a row (identical index rows) — a duplicated entry in the index.
  13. `docs/deployment.md` §Media thumbnails tells the operator to regenerate
      thumbnails "on WordPress.com production … via WP-CLI (`wp media regenerate`)",
      contradicting the canonical production constraint (releases.md §Production
      constraints, standard §0.12, AGENTS.md: no SSH, no WP-CLI on production).
  14. (found during implementation) `docs/evidence/README.md` claims a complete
      folder index but was missing three folders that exist on disk
      (`2026-11-06-christmas-homepage-popup-fix`,
      `2026-11-06-christmas-landing-page-active-dispatch-fix`,
      `2026-11-07-christmas-welcome-top-popup`) — index drift introduced by the
      tasks that created them.
- Skill quality: all 14 skills carry the 12 standard sections in order, no
  dangling skill references, no broken markdown links, no non-existent command
  or path references (gate + manual scans). Index matches the directory.
- Documentation: `docs/README.md` links resolve (manual audit, 0 broken);
  347 markdown files under `docs/`; reports/evidence indexes are curated by
  topic (not exhaustive by design — recorded as an observation, no action).

## 5. Proposed approach

Fix defects at their **source** (generator for generated regions), keep manual
edits minimal and outside generated markers, de-duplicate the Copilot entry
point into a thin audience-specific file that points at AGENTS.md and canonical
docs, complete the AGENTS.md skill routing table, repair the wp-testing
fragment, and align the plan-template destination with actual practice plus the
§9.1 ownership table. Create **no new skill**: every documented repeatable
workflow already has exactly one owning skill; the remaining candidate gaps
(bug-investigation playbooks, CI troubleshooting) have no canonical procedure to
convert and would be speculative. Routing gaps are closed with table rows in
`AGENTS.md` instead.

**Options rejected:** (a) merging/removing the AGENTS.md skill table — rejected
because it is the primary routing surface; completing it is the smaller diff;
(b) replacing `copilot-instructions.md` with a pointer-only file — rejected
because GitHub Copilot discovers content at that exact path, so the file must
retain the audience-specific quick commands and MCP configuration; (c) adding a
skill for documentation audits or regression triage — rejected as speculative
duplication of `wp-update-docs` / `wp-testing`.

## 6. Files expected to change

| Path | Change | Why |
|---|---|---|
| `scripts/generate-registry-docs.php` | fix root-relative `plugins.json` links (`AGENTS.md` target: `../` → none; `docs/deployment.md` target: `../../` → `../`); guard empty lifecycle lists | root cause of findings 4, 5 and 9 (deployment link) |
| `docs/routing.md`, `docs/commemorative-events.md`, `docs/translation-manual-operator-runbook.md` | correct broken relative links (findings 9) | §10 cross-reference audit |
| `docs/development.md` | remove the retired `run-guide-translation.php` commands (finding 10) | §10; the section's own prose already retires the runner |
| `docs/deployment.md` | correct the production WP-CLI recommendation (finding 13) | contradicts the canonical production constraint |
| `docs/README.md` | remove the duplicated witch-animation index row (finding 12) | index correctness |
| `AGENTS.md` | remove duplicate heading; remove duplicated CI paragraph; add 4 skill rows; new `_Last verified_` line; regenerated region | findings 1–3 |
| `README.md`, `docs/project-inventory.md`, `docs/plugins/README.md` | regenerated regions only (`--write`) + `_Last verified_` where the file carries one | consequence of the generator fix |
| `.agents/skills/wp-testing/SKILL.md` | remove orphaned trailing fragment | finding 7 |
| `.github/copilot-instructions.md` | fix links; complete skill list; replace restated policy with pointers | finding 6 |
| `docs/templates/plan.md` | copy destination → `docs/plans/`; `_Last verified_` | finding 8 |
| `docs/engineering-standard.md` §9.1 | add the `docs/plans/` ownership row; `_Last verified_` | finding 8 |
| `docs/README.md` | index rows for this plan and report; `_Last verified_` | §9 same-change index rule |
| `docs/plans/2026-11-07-agent-skill-doc-audit.md` | this plan | §13.2 |
| `docs/reports/2026-11-07-agent-skill-doc-audit.md` | final report from the report template | §9.2/§13.2 |
| `docs/evidence/2026-11-07-agent-skill-doc-audit/` | baseline + final gate outputs, link-audit output | evidence rule |

## 7. Files explicitly expected NOT to change

- `wp-content/**` — no runtime file; the change must be behaviour-neutral.
- `plugins.json` — no plugin fact changes; only the generator's *rendering*.
- `compose.yaml`, `scripts/build-plugins-zip.sh` — generated regions untouched
  by these fixes (mounts/build list are unaffected).
- `.agents/skills/*/SKILL.md` other than `wp-testing` — no workflow changes.
- `.agents/skills/README.md` — the index is already correct; the governance
  gate would catch any drift.
- `tests/**` — no gate, assertion or baseline is modified (the audit reads
  gates, never edits them).
- `.github/PULL_REQUEST_TEMPLATE.md`, `docs/templates/report.md` — already
  compliant with their gates.
- `.agents/legacy-flutter-skills/**` — retired provenance, untouched.
- All historical `docs/reports/`, `docs/evidence/`, `docs/audit/` documents.

## 8. Content / data impact

- Records created / updated / deleted: **none** — no WordPress data is touched.
- Portable identifier strategy: not applicable (no content writes).
- Does this write content? **No** — the six-step contract (§5.2) does not apply.
- PT content changed? **No.**

## 9. Route / HTTP impact

- Routes added, removed or changed: **none**.
- Redirects needed: **none**.
- Sitemap coverage: **none**.
- HTTP matrix rows to add or update: **none** — no request-visible behaviour
  changes; no acceptance row is added.

## 10. Polylang / English impact

- Translated post types / taxonomies affected: **none**.
- Is PT immutable in this change? **Yes** — no content file is touched.
- B1/B2 decisions: not applicable.
- Canonical, hreflang, language-switcher behaviour: **unchanged**.
- Language-scoped cache keys affected: **none**.
- Completeness gate: not applicable (no EN coverage work).

## 11. Security impact

- Capabilities checked: none added or changed (documentation/skills only).
- Nonces added/changed: none.
- Sanitisation / escaping: not applicable (no PHP runtime file).
- SQL: none.
- REST surface change: **none**.
- Secrets involved: **none** — credentials remain environment-only; the
  governance secret scan (`verify-stage14-secret-scan.py`) runs unchanged.

## 12. Performance impact

- Queries added or removed: **none**.
- Caching: **none**.
- Assets or images affected: **none**.
- Expected measurable improvement: faster/correct agent orientation and no
  broken navigation links — measured by the link audit and the governance gate,
  not by page metrics (none apply).

## 13. Production impact

| Question | Answer |
|---|---|
| Will anything be written to production? | **no** |
| Will a deploy, upload or activation happen? | **no** |
| Who performs it, and how? | not applicable — no production action is authorised or performed |
| What is the verification of the production result? | not applicable; scope integrity is proven by the empty `wp-content/` diff and green gates |

## 14. Test plan

- In-process suite(s) to add or update: **none** — no behaviour changes; the
  existing suites that already cover this surface (Stage K governance, Stage L
  documentation drift, Stage I script contract, Stage G registry drift) are the
  verification and must stay green.
- Acceptance area / matrix rows: none (no request-visible change).
- Script contract impact: `scripts/generate-registry-docs.php` changes must pass
  `tests/scripts/verify-script-conventions.py` and `./scripts/lint.sh`
  (PHPCS/PHPStan, no new baseline entries).
- Registry drift check: `php scripts/generate-registry-docs.php --check` after
  `--write`.
- What constitutes a pass, numerically: governance gate ≥ 357 passed / 0 failed
  (the count may grow only if new checks are naturally satisfied — it must never
  drop below baseline and must stay 0 failures); documentation-drift gate exit 0;
  `--scripts` layer exit 0; lint exit 0; full `./scripts/run-tests.sh`
  failing-suite list identical to baseline.

## 15. Acceptance matrix plan

No matrix rows added: nothing request-visible changes. The acceptance layer is
still run once in the full suite to prove the failing-suite list is unchanged
from baseline.

## 16. Rollback plan

Nothing is committed, so rollback = revert the edited files:
1. `git diff` lists every touched file; no stash is created or modified.
2. The generator fix is undone by reverting `scripts/generate-registry-docs.php`
   and re-running `php scripts/generate-registry-docs.php --write` to restore
   the previous region text.
3. What rollback does not cover: nothing outside `docs/`, `.agents/`,
   `.github/copilot-instructions.md`, `AGENTS.md`, `README.md` and
   `scripts/generate-registry-docs.php` is in the diff.

## 17. Documentation plan

| Document | Change | Procedure moved to (skill) |
|---|---|---|
| `AGENTS.md` | de-duplicate, complete skill table, new marker | none moved — duplication removed in place |
| `.github/copilot-instructions.md` | restated policy replaced with pointers | safety rules remain owned by `AGENTS.md`; contract by §5.2; workflow by `wp-repository` |
| `docs/templates/plan.md` | destination corrected to `docs/plans/` | none |
| `docs/engineering-standard.md` §9.1 | `docs/plans/` ownership row added | none |
| `docs/README.md` | index rows for plan + report | none |
| `.agents/skills/wp-testing/SKILL.md` | orphan fragment removed | none (text already stated by the checklist) |

## 18. Release implications

- Component version bump(s): **none** — no plugin or theme file changes.
- Effect on the artifact allowlist: **none** (`plugins.json` untouched).
- Is a release in scope? **No.**

## 19. Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| Scope creep into unrelated documentation rewriting | medium | findings list is fixed; everything else is recorded as observation-only in the report |
| Generator edit breaks the 20 generated regions | low | edit + `--write` + `--check` + diff review; regions are marker-delimited |
| Over-trimming `copilot-instructions.md` removes needed context | medium | every removed block is replaced by a pointer to its authoritative owner; the file keeps commands, env vars and MCP config |
| AGENTS.md grows past the <250-line gate | low | removals (duplicate heading + CI paragraph) outweigh additions (4 rows + 1 marker); gate re-run proves it |
| Accidental edit inside a generated marker | medium | generated regions are only changed via `--write`; manual edits are verified against marker boundaries in the final diff |

## 20. Verification gates

1. `python3 tests/scripts/verify-agent-governance.py` — 0 failures (baseline 357 passed).
2. `php scripts/generate-registry-docs.php --check` — 0 drift.
3. `python3 tests/scripts/verify-documentation-drift.py` — exit 0.
4. `./scripts/run-tests.sh --scripts` — all script-contract suites pass.
5. `./scripts/lint.sh` — PHP syntax + PHPCS + PHPStan, no new violations.
6. `./scripts/run-tests.sh` — full contract; failing-suite list identical to baseline.
7. Manual relative-link audit over the governance surface — 0 broken links.
8. `git status --short` — only the files this plan names.

## 21. Completion criteria

Per `docs/engineering-standard.md` §14: scope matches this plan; no unrelated
refactor; gates green with real numbers recorded in the report; documentation
updated in the same change with `_Last verified_` lines; no root-level report or
evidence file; no secrets; Flutter/mobile untouched; report written from
`docs/templates/report.md` with an explicit limitations section; git safety
confirmed (no commit, no push, no production write, stash untouched).

## Explicit non-goal note (git safety)

This task forbids committing. The final state is therefore an uncommitted
working-tree diff on `commemorative-events`, reported as such — not committed,
not pushed, not stashed.

_Last verified: 2026-11-07 by the agent, skills and documentation audit_