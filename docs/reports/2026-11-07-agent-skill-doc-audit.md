# Report — Comprehensive repository agent, skills and documentation audit

> Written from [`docs/templates/report.md`](../templates/report.md). Machine
> evidence: [`docs/evidence/2026-11-07-agent-skill-doc-audit/`](../evidence/2026-11-07-agent-skill-doc-audit/).

| | |
|---|---|
| **Stage / task name** | Comprehensive repository agent, skills and documentation audit |
| **Date** | 2026-11-07 |
| **Author / agent** | Cline (coding agent), acting under the audit task brief |
| **Branch** | `commemorative-events` (unchanged) |
| **Start SHA** | `438c2ebb206b0f9dd15094a8b5dea04a9693ebb2` |
| **Final SHA** | `438c2ebb206b0f9dd15094a8b5dea04a9693ebb2` (no commit created — this task forbids committing) |
| **Working tree at finish** | dirty — the audit's own uncommitted changes only (15 modified files + the plan, report and evidence directories), listed in §3 |
| **Skills followed** | `wp-repository` (entry, scope, reuse), `wp-update-docs` (documentation ownership, drift), `wp-testing` (test contract, regression comparison) |
| **Authoritative docs consulted** | `AGENTS.md`, `docs/engineering-standard.md` (§0, §9, §12, §13, §14), `docs/README.md`, `docs/testing.md`, `docs/releases.md`, `docs/deployment.md`, `docs/development.md`, `docs/architecture.md`, `docs/content-model.md`, `docs/routing.md`, `.agents/skills/README.md`, `tests/scripts/verify-agent-governance.py`, `tests/scripts/verify-documentation-drift.py`, `scripts/generate-registry-docs.php`, `docs/templates/plan.md`, `docs/templates/report.md` |

## 1. Scope completed

- **Discovery and inventory.** Full preflight (root, branch, HEAD, history,
  status, staged/unstaged diffs, untracked files, 9 stashes preserved), then an
  inventory of every agent-instruction source: root `AGENTS.md` (the only
  `AGENTS.md` in the repository), `.github/copilot-instructions.md`,
  `.github/PULL_REQUEST_TEMPLATE.md`, the 14 active skills and their index, the
  retired `.agents/legacy-flutter-skills/` provenance set, both templates, and
  the governance gates that enforce them. Confirmed there is no `.claude/`,
  `.codex/`, `skills/`, `agents/` or IDE agent configuration in the repository
  (`.idea/` is stock JetBrains metadata, git-ignored).
- **Documentation review.** Read the governing documents in full or in the
  sections that govern this work (`AGENTS.md`, the engineering standard, the
  documentation index, testing, releases, deployment, development, architecture
  heads, the evidence and reports index rules), and cross-reference-audited
  every relative Markdown link across `docs/`, `.agents/`, `.github/`,
  `AGENTS.md`, `README.md` and the Copilot instructions — baseline: **28 broken**
  in the `docs/`+`.agents/`+`.github/` scan (536 links, including 2 in the
  Copilot instructions) **plus 1** in `AGENTS.md`; final: **625 checked,
  19 broken** (all historical).
- **Skill assessment.** All 14 skills assessed (3 read in full —
  `wp-repository`, `wp-update-docs`, `wp-testing`; the other 11 sampled for
  Purpose/When-to-use/When-not-to-use, authoritative sources, structure, and
  scanned mechanically for broken links, missing paths and non-existent
  commands). One defect found and fixed (`wp-testing` orphan fragment).
- **14 findings** raised in the plan (13 during discovery, 1 during
  implementation), all actionable ones fixed at their source
  (see §3 and the plan §4). Category decisions recorded: no new skill was
  created, with the reasoning and rejected options in the plan §5 and §G below.
- **Verification** through the repository's own gates, with a full-suite
  before/after regression comparison (§9, §11).

## 2. Scope NOT completed

- **19 historical broken links were not repaired**: 17 evidence-path links
  inside three historical reports (`2026-09-30-en-pt-source-reconciliation.md`,
  `2026-09-30-en-rollout-blocker-resolution.md`,
  `2026-10-05-eventbrite-full-activation.md`), one in
  `docs/evidence/2026-10-02-ci-fixture-translation-regression/README.md`, and
  the self-qualifying `ivvcc-stage-d-report.md` ("when available") link in
  `docs/importers/ivvcc-importer.md`. Rationale: historical reports and
  evidence are immutable records (standard §9.2: history is not rewritten), and
  the ivvcc target is semantically ambiguous (only a *recovery* report exists).
  Full inventory: `11-final-link-audit.txt` in the evidence folder.
- **`docs/reports/README.md` was not made exhaustive.** It is a curated,
  topic-grouped index (190 reports, 66 not individually listed) with no
  completeness claim; the directory listing is the complete inventory. Adding
  66 rows would be churn, not navigation. Recorded as an observation only.
- No production access, no deployment, no commit, no push — all forbidden by
  the task and by `AGENTS.md`.

## 3. Files added / modified / deleted

**N added: 3** (plan, report, evidence folder with its files) · **N modified: 17** · **N deleted: 0** · **0 files under `wp-content/`**.

| Path | Change | Note |
|---|---|---|
| `scripts/generate-registry-docs.php` | fixed two hard-coded wrong link depths (`AGENTS.md` target `../` → root; `docs/deployment.md` target `../../` → `../`); guarded all three lifecycle lists against empty (the `… ZIP): .` sentence) | root cause of findings 4, 5, 9 |
| `AGENTS.md` | removed the duplicated `## Plugins` heading; removed the CI paragraph that duplicated the `## CI` section; added the 4 missing skill rows (10 → 14); new `_Last verified_` line; regenerated region | findings 1–3, 4, 5 |
| `README.md`, `docs/project-inventory.md`, `docs/plugins/README.md`, `docs/deployment.md` | regenerated regions only (`--write`), plus `_Last verified_` where the file carries one | consequence of the generator fix |
| `.agents/skills/wp-testing/SKILL.md` | removed the orphaned trailing fragment | finding 7 |
| `.github/copilot-instructions.md` | fixed 2 broken links; complete skill coverage via the index pointer; restated safety rules, content contract, architecture and workflow replaced with pointers to their owners; kept quick commands, env vars and MCP config (166 → 124 lines) | finding 6 |
| `docs/routing.md` | corrected 3 broken plugin-doc links | finding 9 |
| `docs/commemorative-events.md` | corrected 1 broken theme-doc link | finding 9 |
| `docs/translation-manual-operator-runbook.md` | corrected 2 broken report/evidence links | finding 9 |
| `docs/development.md` | removed the retired `run-guide-translation.php` commands (the file no longer exists; the same section retires the runner) | finding 10 |
| `docs/deployment.md` | replaced the production "via WP-CLI" thumbnail advice with the no-WP-CLI admin/plugin path | finding 13 |
| `docs/README.md` | removed the duplicated witch-animation index row; indexed this plan and report; new `_Last verified_` line | finding 12 |
| `docs/templates/plan.md` | copy destination corrected to `docs/plans/<date>-<topic>.md` | finding 8 |
| `docs/engineering-standard.md` | §9.1 ownership table records the existing `docs/plans/` location | finding 8 |
| `docs/plans/2026-11-07-agent-skill-doc-audit.md` | this task's plan (§13.2) | new |
| `docs/reports/2026-11-07-agent-skill-doc-audit.md` | this report | new |
| `docs/evidence/2026-11-07-agent-skill-doc-audit/` | baseline + final gate outputs, summaries, link audit, git snapshots | new |
| `docs/evidence/README.md`, `docs/reports/README.md` | index rows for this evidence folder and report; repaired the evidence index's three missing folders (finding 14) | evidence/reports index rules |

## 4. Runtime impact

**Behaviour-neutral.** `git diff --name-only` touches no file under
`wp-content/` (0 runtime files) and no route, template, query, cache key,
REST contract or asset. The only executable file changed is
`scripts/generate-registry-docs.php`, a static documentation generator whose
output is Markdown regions; `php -l` passes, `./scripts/lint.sh` passes
(exit 0: syntax clean, no new PHPCS violations, PHPStan clean), and
`php scripts/generate-registry-docs.php --check` reports all 20 regions
current. The full suite was run before the first edit (baseline) and after the
last edit (§9, §11); the failing-suite diff is the comparison that proves no
runtime change.

## 4a. Scope integrity

| Check | Result |
|---|---|
| No production write, deploy, upload or activation | **confirmed** — no production host contacted; no deployment tooling run |
| No production content, Polylang or database change | **confirmed** — no WordPress data touched at all |
| No application behaviour change (`wp-content/` diff is empty) | **confirmed** — `git diff --name-only` lists 0 files under `wp-content/` (see `12-final-git-status.txt`) |
| No Flutter/mobile repository accessed | **confirmed** — only this repository's files were read/written |
| No `.env` or secret change | **confirmed** — `.env` untouched and untracked; no credential appears in any changed file |

## 5. Content / data impact

**None.** No WordPress record, term, attachment or option was created,
updated or deleted; no content writer, importer or migration was run.
Confirmed by `git status` (only documentation, skills, governance and one
static generator changed) and by the empty `wp-content/` diff.

## 6. Polylang impact

**None.** PT content untouched (no content file is in the diff), no EN record
created, no taxonomy or locale logic changed, no completeness gate affected.
PT immutability is trivially satisfied: zero content writes occurred.

## 7. Route / HTTP impact

**None.** No route, redirect, canonical, hreflang or sitemap behaviour changed;
no HTTP matrix row added or updated. The acceptance layer was still executed in
both full-suite runs to prove the failing-suite list is unchanged (§11).

## 8. Production actions

| Action | Performed? | Detail |
|---|---|---|
| Production write | **no** | none authorised, none performed |
| Deploy / upload | **no** | |
| Plugin activation | **no** | |
| Content or DB mutation | **no** | |

No production host was contacted by any command in this task; every gate ran
against the local repository (the acceptance layer targets the local Docker
stack only, and the runner refuses production hosts).

## 9. Verification commands and results

| Command | Exit | Result |
|---|---|---|
| `python3 tests/scripts/verify-agent-governance.py` | 0 | **357 passed, 0 failed** — identical to baseline (357/0) |
| `php scripts/generate-registry-docs.php --check` | 0 | **11 plugins validated, 20 generated regions current** (zero writes) — identical to baseline |
| `python3 tests/scripts/verify-documentation-drift.py` | 0 | **14 passed, 0 failed**, `GATE-RESULT … "status": "pass"` — identical to baseline |
| `./scripts/lint.sh` | 0 | `lint: OK (syntax clean, no new PHPCS violations, PHPStan clean)` |
| `php -l scripts/generate-registry-docs.php` | 0 | `No syntax errors detected` |
| `./scripts/run-tests.sh` (baseline, before edits) | 1 | see §9a — 145 passed, 17 failed (all pre-existing) |
| `./scripts/run-tests.sh` (final, after edits) | see §9a | see §9a |
| relative-link audit (custom, read-only) | n/a | baseline: 29 broken total (536-link scan of `docs/`+`.agents/`+`.github/` = 28, plus 1 in `AGENTS.md`); final: **625 checked, 19 broken**, all historical (§2); 10 fixed |

### Numeric test results

Filled from the two full-suite runs in §9a/§11 with exact per-layer numbers
(137 in-process PHP suites, 21 script-contract suites, 5 acceptance suites).

### HTTP acceptance

Executed as part of both full-suite runs: **5 suites** (release smoke matrix,
routing, guides-EN, commemorative, event-occurrence); assertion counts recorded
in §11 from the runner output. No matrix row was added — nothing request-visible
changed.

### Static analysis

PHPCS + PHPStan (level 5) + PHP syntax via `./scripts/lint.sh`: **pass, exit 0**,
no new baseline entries (`lint-final.txt` in the evidence folder).

### Script-contract results

`tests/scripts/verify-*.py` executed inside both full-suite runs (21 suites):
per-suite results and totals in §9a/§11; the Stage K governance, Stage L
documentation-drift and Stage G registry gates inside that layer all pass with
the numbers above.

### Release / build results

**Not in scope** — no release was built; `plugins.json` and the artifact
allowlist are untouched (the generator edit changes documentation rendering
only).

## 10. Failure proofs / negative tests

| Proof | Result |
|---|---|
| Registry drift gate fails on a broken registry fact: `plugins.json` documentation path changed to `docs/plugins/does-not-exist.md` on purpose, `php scripts/generate-registry-docs.php --check` | **exit 1**, `ERROR: generated-target validation failed: generated target does not exist` — gate failed as required; `plugins.json` then restored byte-exact (`git diff plugins.json` empty) |
| Agent-governance gate fails on a skill defect: empty `.agents/skills/wp-bogus-proof/` created, `python3 tests/scripts/verify-agent-governance.py` | **exit 1**, `FAIL: … has no SKILL.md; a skill IS its SKILL.md` and `FAIL: … index has drifted` — gate failed as required; directory removed, gate back to **357/0** |
| Link-audit detector discriminates | baseline scan reported **28** broken links before the fixes; after the fixes **19** remain (all historical, deliberately untouched) plus the report link that this report itself resolves |
| Doc-drift gate orchestrates and fails when an underlying gate fails | by construction (`run_gate` fails on non-zero subprocess) — the two proofs above are that underlying failure |

Exact output: `13-negative-proofs.txt` in the evidence folder. No gate was
weakened, no assertion removed, no baseline entry added — every proof restores
its fixture immediately and re-runs green.

## 11. Regression comparison

| | Before | After |
|---|---|---|
| Failing in-process suites (named) | 11 | 11 — **same names** |
| In-process assertion failures | 38 (sum of the named suites' failed counts: 1+3+4+5+7+4+6+2+2+2+2) | 38 — **same counts** |
| Failing script-contract suites | 2 (`verify-i18n-freshness`, `verify-stage8-release-consistency`) | 2 — **same names** |
| Failing acceptance suites | 4 (commemorative, event-occurrence, release, routing) | 4 — **same names** |
| `[PASS]` lines | 145 | 145 |
| Governance gate | 357 / 0 | 357 / 0 |
| Registry drift | 20 regions current | 20 regions current |
| Documentation drift | 14 / 0, pass | 14 / 0, pass |

**The diff of the failing-suite lists is empty.** Every failure present after
the change was already present before it, with identical assertion counts, and
is classified in §12 as pre-existing local-data/artifact debt. No new failure,
no changed number, no weakened gate.

## 12. Known pre-existing failures

The baseline full-suite run (before any edit) recorded **17 failing suites**,
all of them local-data or local-artifact conditions that predate this task and
are unaffected by documentation changes. They are reproduced verbatim in
`05-full-suite-baseline-summary.txt`:

- **12 in-process PHP suites** — EN-translation/data-dependent
  (`test-blog-en-translation`, `test-en-jobs-shared-slug`,
  `test-guide-en-translation`, `test-job-en-translation`,
  `test-jobs-en-language`, `test-stage33-bilingual`,
  `test-stage41-rest-language`, `test-stage45-pages`,
  `test-translation-completeness`), `test-town-sanitization` (contaminated
  local `conexao_town` terms), `test-event-filter-cache`.
- **2 script-contract suites** — `verify-i18n-freshness.py` (three `.pot`
  catalogues older than the PHP that defines their strings: the Christmas hero
  and two admin/commemorative classes) and `verify-stage8-release-consistency.py`
  (stale git-ignored `dist/` artifacts: ZIP/release.json at 1.6.3 vs plugin
  header 1.7.0 / 1.0.8 vs 1.0.9).
- **4 acceptance suites** — `/empregos/` answering 302 instead of 200, two
  missing Laois events, and the `/natal/` motif fragment (local content state).

None of these failures is caused by this change: every one was failing at
baseline with the identical assertion, and the diff of the failing-suite lists
is empty (§11). They were **not** "fixed" here — they are pre-existing local
debt, out of scope for a documentation/skills audit.

## 13. Limitations

- **The local stack's data state is not the CI fixture state.** The full-suite
  comparison therefore runs against a locally drifted database (EN coverage,
  town terms, `/empregos/` redirect). This weakens nothing in the comparison —
  baseline and final run on the same state — but it means the run is not
  byte-identical to CI's synthetic-site run; CI remains authoritative there.
- **No production verification was attempted or possible** (forbidden by the
  task; production has no CLI anyway). Claims here are repository-local only.
- **19 historical broken links remain** (deliberately, §2); no gate checks
  links repository-wide, so nothing prevents new broken links in historical
  documents. Recommended as follow-up work if ever desired.
- **Browser (Playwright) and Node opt-in layers were not run** — they are
  outside `./scripts/run-tests.sh` by design and no changed file is exercised
  by them; `verify-commemorative-runtime.py` (the Node-backed gate) runs inside
  the script layer and passed at baseline.
- The custom link audit is a read-only ad-hoc script used for discovery; it is
  **not** a repository gate (no such gate exists). Its output is preserved as
  evidence, and its blind spots (anchor-only links, external URLs) are outside
  this audit's scope.

## 14. Evidence paths

[`docs/evidence/2026-11-07-agent-skill-doc-audit/`](../evidence/2026-11-07-agent-skill-doc-audit/)
— numbered per the evidence rule; each file states what it proves (see the
folder's `README.md`): baseline preflight and gates, baseline full-suite
summary, final gates, final lint, final link audit, final git status, the
negative-proof capture, and the final full-suite summary.

## 15. Documentation updated

| What moved / changed | From (document) | To (skill / owner) |
|---|---|---|
| Nothing moved between documents | — | every procedure kept its single owner; duplication was removed in place |
| Restated safety rules, §5.2 content contract, architecture summary, workflow | `.github/copilot-instructions.md` | pointers to `AGENTS.md`, `docs/engineering-standard.md`, `docs/architecture.md`, `wp-repository` (owners unchanged) |
| Plan copy destination | `docs/templates/plan.md` (`docs/reports/`) | `docs/plans/` — now also recorded in standard §9.1 |
| CI contract (second copy) | `AGENTS.md` *Running things* paragraph | the `## CI` section already in `AGENTS.md` |

Also updated (same change): `AGENTS.md` (deduplicated, complete skill table),
`docs/README.md` (index rows, duplicate row removed), `.agents/skills/README.md`
(unchanged — already correct), `docs/routing.md`, `docs/commemorative-events.md`,
`docs/translation-manual-operator-runbook.md`, `docs/development.md`,
`docs/deployment.md`, `README.md`, `docs/plugins/README.md`,
`docs/project-inventory.md`, `docs/evidence/README.md`,
`docs/reports/README.md` — each ending with its `_Last verified_` line.

## 16. Rollback / recovery

Nothing was committed, so rollback is a working-tree decision:

1. `git diff` lists every modified file; revert any of them with a reviewable
   reverse patch (no `git reset`/`clean`/`checkout` was used in this task).
2. The generator fix reverts with `scripts/generate-registry-docs.php`; after
   reverting it, run `php scripts/generate-registry-docs.php --write` to
   restore the previous region text, then `--check`.
3. The new files (plan, report, evidence folder) can simply be removed — they
   carry no state anything else depends on.
4. What rollback does not cover: nothing outside the §3 file list is in the
   diff; no content, no runtime code, no configuration is affected either way.

### §9a Full-suite runs (both layers of the comparison)

| | Baseline (before edits) | Final (after edits) |
|---|---|---|
| Command | `./scripts/run-tests.sh` | `./scripts/run-tests.sh` |
| Exit | 1 | 1 |
| In-process PHP | 137 total, 125 passed, 12 failed | 137 total, 125 passed, 12 failed |
| Script contract | 21 total, 19 passed, 2 failed | 21 total, 19 passed, 2 failed |
| HTTP acceptance | 5 total, 1 passed, 4 failed | 5 total, 1 passed, 4 failed |
| `[PASS]` lines | 145 | 145 |
| `[FAIL]` lines | 17 | 17 |
| Named failing suites | 11 PHP + 2 script + 4 acceptance | **identical lists** (diff empty) |

The runner counts 12 PHP failures while naming 11: in the final log the 12th is
`[TIMEOUT] plugin/conexao-event-importer/test-source-not-found-reconciliation.php — exceeded 300s`
(the baseline run showed the same 12-vs-11 gap with the same named list; its
raw log was distilled to `05-full-suite-baseline-summary.txt` per the evidence
rule, so the identity of its 12th entry is inferred from the identical totals —
recorded in §13).

## 17. Final status

| Status | Use when |
|---|---|
| `PASS` | Every applicable gate in the standard passed, with real numbers, and no required verifier was unavailable. |
| `PASS WITH LIMITATION` | The work is complete, but a **required** verification capability was unavailable; the limitation is stated in §13. |
| `BLOCKED` | A required implementation could not be completed safely. |
| `NOT TESTABLE` | The intended change could not be meaningfully verified at all. |

**Final status: `PASS WITH LIMITATION`**

Justification: every repository gate that applies to this change passed with
real numbers (governance 357/0, registry 20/20, documentation drift 14/0,
lint OK, full-suite failing-suite diff empty, negative proofs proven), and no
governance rule was bypassed — but two verifiers could not run to completion
here: production verification (forbidden by the task and impossible on
WordPress.com without an authorised operator action) and the opt-in
browser/Node layers (outside the canonical runner). The known pre-existing
local-data failures (§12) and the deliberately unrepaired historical links
(§2) are recorded rather than hidden. `PASS WITH LIMITATION` is the honest
status; `PASS` would overstate what was verified.

_Last verified: 2026-11-07 by the agent, skills and documentation audit_