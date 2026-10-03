# Report — Agent-skills / documentation architecture migration

| | |
|---|---|
| **Stage / task name** | Agent-skills & documentation-architecture migration |
| **Date** | 2026-09-30 |
| **Author / agent** | Coding agent (Cline) |
| **Branch** | `cline/64pw31bv` (from `i18n`) |
| **Skill(s) followed** | `wp-update-docs` (+ the domain skills whose procedures were migrated) |
| **Start SHA** | `5a75417018c79ebbc6b9ac0ae976e1469c002db8` (`i18n`) |
| **Final SHA** | see "Final status" (head of `cline/64pw31bv`) |
| **Working tree at finish** | clean (report + evidence committed) |

## Objective

Make the repository substantially easier for agents to operate, and keep it a
trustworthy long-term record, by giving each kind of knowledge **one home**:

- **`.agents/skills/`** — reusable operational workflows ("how should an agent
  perform this kind of work?").
- **`docs/`** — durable project truth ("what is true about this project?").
- **`docs/reports/`, `docs/evidence/`** — the historical record ("what happened,
  and how was it verified?").

Before this migration the boundary was blurry: canonical documents carried
step-by-step procedures that a skill also needed, the engineering standard's
§13.1 held a **hand-maintained** skill table that had already drifted from the
real skill set, and `docs/deployment.md` described the release/rollback
procedure that `docs/releases.md` also described (three copies of one
deployment story). The result was duplicate procedure, drifting lists and a
risk that a new agent would reconstruct the current procedure from historical
reports. This migration removes the duplication, makes the skill set the single
operational layer, and makes the "one home per kind of knowledge" rule
explicit and **CI-enforced**.

## Baseline

Frozen at `5a75417` (branch `i18n`) before any edit; machine evidence:
`docs/evidence/2026-09-30-agent-skills-documentation-migration/00-baseline-repository-state.txt`
and `01-baseline-inventory.txt`.

| Measure | Baseline |
|---|---|
| `docs/` tracked files | 749 |
| `docs/` markdown | 191 |
| `docs/reports/` | 82 |
| `docs/evidence/` | 593 |
| Active skills (`.agents/skills/*/SKILL.md`) | 10 |
| Retired material (`.agents/legacy-flutter-skills/`, tracked) | 2 |
| `docs/` markdown, total lines | 62,031 |
| Active skill lines (SKILL.md) | 1,195 |
| `AGENTS.md` / `docs/README.md` | 197 / 169 |
| `docs/engineering-standard.md` / `testing.md` / `routing.md` | 836 / 529 / 719 |
| `docs/content-model.md` / `development.md` / `deployment.md` / `releases.md` | 367 / 482 / 299 / 350 |
| `scripts/README.md` | 353 |

Inventory classification (Stage 0–1) found the corpus divides cleanly: 191
markdown files, of which the bulk are historical (`docs/reports/` 82,
`docs/evidence/` 593 files, plus dated reports under `docs/events/`,
`docs/ui/`, `docs/importers/`, `docs/research/`), and a small set of living
canonical documents. The governance gate `tests/scripts/verify-documentation-drift.py`
already scoped the "living" set; that scope is the natural canonical boundary.

## Final architecture

| Layer | Owns | Never contains |
|---|---|---|
| `.agents/skills/` | Reusable operational workflows: purpose, when/not, required reading, authoritative sources, preconditions, steps, guardrails, verification, failure handling, evidence, definition of done | Registry tables, route lists, version numbers, taxonomy policy — any list with an authoritative source |
| `docs/` (canonical) | Durable project truth: architecture, content model, routing, engineering standards, production constraints, release policy, testing model, plugin specifications, templates | Step-by-step procedures (they belong to a skill) |
| `docs/reports/`, `docs/evidence/` | Historical outcome, audit trail, measurements, incident/recovery evidence | Current instructions |

The rule is written into the engineering standard (§13.1, new vocabulary):
**a procedure has one operational home — the skill; a canonical document links
it instead of duplicating it.** The architecture is now:

```text
AGENTS.md → skill selection → .agents/skills/<workflow>/SKILL.md
          → canonical docs → implementation → tests/gates → report + evidence
```

and never `AGENTS.md → search historical reports → guess the procedure`.

## Skills

Fourteen active skills, indexed by `.agents/skills/README.md` (the single
skill index, CI-checked against the directories). Four are **new**; ten are the
existing skills **upgraded in place** to the 12-section format. No existing
skill was renamed, so every governance/test reference kept resolving.

| Skill | Responsibility | New? |
|---|---|---|
| `wp-add-content-type` | New/changed post type, taxonomy, meta, archive or route | existing |
| `wp-add-admin-screen` | A maintainer capability operated through wp-admin | existing |
| `wp-write-in-process-test` | Writing an in-process PHP test suite | existing |
| `wp-http-acceptance-matrix` | An HTTP acceptance row/matrix | existing |
| `wp-run-tests` | Running the full test contract, classifying failures, comparing regressions | **new** |
| `wp-content-change` | The six-step content-change contract for any non-EN, non-schema content write | **new** |
| `wp-translation-rollout` | English coverage via the shared rollout engine, B1/B2, PT integrity | existing |
| `wp-add-strings` | User-facing strings, text domains, catalogue regeneration | **new** |
| `wp-release-deploy` | Build, manifest, artefact allowlist, deploy, verify, rollback | existing |
| `wp-production-operations` | Read-only production audits + explicitly authorised production actions | **new** |
| `wp-update-docs` | Where documentation goes; generated regions; drift prevention | existing |
| `wp-security-review` | Capabilities, nonces, escaping, SQL, secrets, REST boundary | existing |
| `wp-frontend-perf` | Templates, queries, caching, assets, images (measurable) | existing |
| `wp-plugin-registry` | `plugins.json` as the single plugin registry | existing |

Every skill carries the standard 12 sections in order (`Purpose → When to use →
When not to use → Required reading → Authoritative sources → Preconditions →
Steps → Guardrails → Verification → Failure handling → Evidence and reporting →
Definition of done`), the WordPress-only scope sentence, and an **Authoritative
sources** section that declares its canonical inputs (the dependency model).
All 14 pass the extended governance gate.

## Existing skill changes

For each upgraded skill: the old problem, the new role, canonical dependencies,
verification.

| Skill | Old problem | New role | Canonical dependencies | Verification |
|---|---|---|---|---|
| `wp-add-content-type` | No `Purpose`/`When not to use`; no sources section | Full workflow; declares content-model/routing/registry authority | `docs/content-model.md`, `docs/routing.md`, `plugins.json` | registry check, `--only conexao-data-model`, acceptance |
| `wp-add-admin-screen` | Referenced `wp-content-rollout`, a skill that never existed | Fixed reference; adds sources/preconditions/failure handling | `docs/engineering-standard.md` §4.2, `docs/plugins/conexao-admin-ux.md` | component suite, `lint.sh` |
| `wp-write-in-process-test` | Lacked the scope sentence | Scope sentence added (gate now enforces it) | `docs/testing.md`, `tests/bootstrap.php`, `tests/lib/assertions.php` | `run-tests.sh --php`, `--list` |
| `wp-http-acceptance-matrix` | No authoritative-sources section | Points at the shared matrix schema/client | `docs/testing.md`, `docs/routing.md`, `tests/acceptance/lib/matrix.py` | `run-tests.sh --acceptance` |
| `wp-translation-rollout` | Strategy/B1–B2 narrative duplicated from the standard | Points at content-model/routing/engine doc; failure handling for PT-drift, conflicts, orphans, gate | `docs/content-model.md`, `docs/routing.md`, `docs/plugins/conexao-translation-rollout.md`, `plugins.json` | engine suite, acceptance, registry check |
| `wp-release-deploy` | Deployment steps duplicated `docs/releases.md` | Owns the deploy sequence incl. **update-in-place ≠ deactivate/reactivate** and full rollback steps; the doc keeps semantics | `docs/releases.md`, `docs/deployment.md`, `plugins.json`, `scripts/README.md` | `verify-release.sh`, `release-manifest.py --verify`, `verify-release-integrity.py` |
| `wp-update-docs` | Fine; no sources/preconditions/failure handling | Explicitly forbids rewriting historical reports | `docs/README.md`, `scripts/README.md`, `docs/engineering-standard.md` §9 | `verify-agent-governance.py`, drift gate |
| `wp-security-review` | Referenced the retired `wp-content-rollout` name | Fixed; adds sources/preconditions/failure handling | `docs/engineering-standard.md` §2.1/§4.2/§7, `inc/rest-language.php` | `lint.sh`, script-contract gate |
| `wp-frontend-perf` | Fine; no sources/preconditions/failure handling | Points at the invalidation matrix and cache-scoping gate | `docs/frontend.md`, `docs/themes/conexao-br-irlanda.md` | `run-tests.sh`, `verify-release.sh` |
| `wp-plugin-registry` | Fine; no sources/preconditions/failure handling | Keeps `plugins.json` the single registry | `plugins.json`, `docs/plugins/README.md` | registry check, `verify-release-integrity.py`, governance gate |

Stale references fixed: `wp-add-admin-screen` and `wp-security-review` named
`wp-content-rollout` (never shipped); the engineering standard named
`wp-add-theme-component`, `wp-add-string-i18n`, `wp-content-rollout`,
`wp-add-translation-rollout` and `wp-rest-contract-change`. Those references
are gone from the active surface — they survive only in the immutable
2026-09-25 audit, which is history.

## Documentation changes

| Document | Retained | Removed / consolidated | Moved to skills |
|---|---|---|---|
| `AGENTS.md` | scope, safety rules, authoritative map, commands, CI, generated registry | verbose "reuse" rationale; stale `Where to go next` | task→skill→docs mapping now routes to skills |
| `docs/README.md` | canonical index, standards, architecture, testing, plugins, templates, history | duplicated agent narration | per-skill purpose table |
| `docs/engineering-standard.md` | every engineering rule (MUST/SHOULD) | **§13.1 hand-maintained 18-line skill table** (drifted; named 5 non-existent skills) | skill format + ownership vocabulary; §6.2/§11/§5.2/§9.1 now link the skills; status header corrected (was "Proposal — not yet enforced", now adopted/enforced — CI already enforces it) |
| `docs/deployment.md` | environment, generated activation order/build lists, production constraints, media-thumbnail facts | **~93 lines** of duplicated release procedure, deployment checklist, migration recipes, environment-differences block | deploy/rollback → `wp-release-deploy`; migration table now cites the owning plugin docs |
| `docs/releases.md` | two release invariants, artefact rules, manifest schema, tag convention, deployment-verification, **rollback semantics** | step-by-step rollback table (12 lines) | rollback steps → `wp-release-deploy` |
| `docs/testing.md` | testing model, layers, permanent gates, baseline policy, fixtures, acceptance | nothing removed (already canonical altitude) | runner workflow → `wp-run-tests` (pointer added) |
| `docs/templates/plan.md` | all impact sections | — | added an **Applicable skill(s)** field; §3 names the skill's authoritative docs |
| `docs/templates/report.md` | all reporting sections | — | added a **Skill(s) followed** field |

`docs/releases.md` remains authoritative for the release contract;
`docs/deployment.md` for environment facts and the generated activation order;
`docs/testing.md` for the testing model. No canonical specification was
weakened — the procedural passages that moved were duplicated, not unique.

## Historical material

`docs/reports/` and `docs/evidence/` are **preserved unchanged**. No historical
report was rewritten, deleted, or converted into a skill instruction; no report
was added, removed or truncated (`docs/reports/` 82 → 83 = the one new
migration report). This migration added a short archive framing sentence to
`docs/README.md`'s history section and a one-line explanatory header in the
new report only. The retired `.agents/legacy-flutter-skills/` material is
untouched and remains explicitly inactive.

## Governance

`tests/scripts/verify-agent-governance.py` was extended (never weakened) to
enforce the new architecture:

- **12-section format**, in order, for every active skill (was 6).
- **Skill-index drift:** `.agents/skills/README.md` must list exactly the real
  skill directories (no missing entry, no phantom/retired reference) and
  reference only real paths.
- **Entry-point discovery:** every active skill must be linked from `AGENTS.md`.
- **Index agreement:** every active skill must be named in `docs/README.md`.
- **Scope sentence:** every skill carries "Never touch the Flutter/mobile
  repository".
- **Dependency model:** every skill's *Authoritative sources* section must
  declare at least one canonical source, and no skill may carry a
  generated-region marker.
- The skill index was added to the localhost/credential/external-path surface.

`tests/scripts/verify-documentation-drift.py` now treats
`.agents/skills/README.md` as a **living** document (it must carry its
`_Last verified_` marker). The six permanent invariant gates are unchanged and
still fail closed.

## CI

CI (`.github/workflows/ci.yml`, three blocking jobs) is the authoritative
regression; the sandbox has python3 but no php/docker, so the PHP/docker
contract was run by CI. Evidence:
`docs/evidence/2026-09-30-agent-skills-documentation-migration/03-ci-regression.txt`.

| Job | Result |
|---|---|
| Static quality (`lint.sh` + ShellCheck) | **✓ success** |
| Integration tests (Docker WordPress + `run-tests.sh`) | **✓ success** |
| Release integrity (manifest + allowlist) | **✓ success** |

Integration detail (run 36774170147, commit `ebf3227`):

- In-process PHP suites: **67 total, 67 passed, 0 failed**; assertions
  **4509 passed, 0 failed**.
- Script-contract suites: **7 total, 7 passed, 0 failed** (includes the
  extended agent-governance gate).
- HTTP acceptance suites: **3 total, 3 passed, 0 failed**.
- Permanent gates: **all PASS**; `documentation_drift` **14 passed, 0 failed,
  0 violations (0 pre-existing, 0 new)**, 0 allowlisted; 90 assertions passed.

Locally (python-only), the agent-governance gate is **371 passed, 0 failed**
(up from 291 — the new checks), script-conventions **324/0**, i18n-freshness
**9/0**. `git diff --check` is clean. The `php`-dependent gates
(`generate-registry-docs.php --check`, `lint.sh`, `run-tests.sh`,
`verify-release.sh`, `release-manifest.py --verify`) could not run in the
sandbox — recorded as **not locally run, CI-authoritative**.

## Duplication reduction

Measured (baseline `5a75417` → final). Evidence:
`…/01-baseline-inventory.txt` and `…/04-measurements.txt`.

| Measure | Before | After | Note |
|---|---|---|---|
| `docs/` tracked files | 749 | 763 | +14: 1 report + 13 curated evidence files from this migration; no canonical doc deleted |
| `docs/` markdown | 191 | 192 | +1 (the migration report) |
| `docs/reports/` | 82 | 83 | +1 (the migration report) |
| Active skills (`SKILL.md`) | 10 | 14 | +4 new workflows |
| Active skill lines | 1,195 | 2,449 | operational layer deliberately expanded (+1,254) |
| `docs/` markdown total lines | 62,031 | 62,240 | net **+209**, which includes the new ~230-line report; the **canonical** documents shrank while historical material was untouched |
| `docs/deployment.md` | 299 | 206 | **−93** duplicated procedure lines |
| `docs/engineering-standard.md` §13.1 table | 18 rows | 0 | drifted hand table removed; index is CI-checked |

Duplicated procedural sections removed (each now has one home):

1. Engineering standard §13.1 hand-maintained skill table (drifted) → the
   CI-checked skill index.
2. `docs/deployment.md` release sequence / rollback / checklist (~93 lines) →
   `wp-release-deploy` (+ `docs/releases.md` semantics).
3. `docs/deployment.md` migration "how-to" recipes → owning plugin docs +
   `wp-content-change`.
4. `docs/releases.md` step-by-step rollback table → `wp-release-deploy`.
5. `AGENTS.md` "reuse" rationale duplication → skill *Authoritative sources*.
6. Stale skill-name references (`wp-content-rollout`, etc.) in two skills and
   the standard → the real skill set.

Canonical documents retained: all of them (`architecture`, `content-model`,
`routing`, `frontend`, `deployment`, `development`, `testing`, `releases`,
`engineering-standard`, `themes/`, `plugins/`). The target was **less
duplication and clearer ownership**, not fewer lines — canonical
specifications were preserved and only duplicated *procedure* was removed.

## Regression

| Suite | Before (i18n baseline) | After | Verdict |
|---|---|---|---|
| In-process PHP suites | 67 | **67 total, 0 failed** (CI) | no regression |
| Assertions (in-process) | 4509 | **4509 passed, 0 failed** (CI) | no regression |
| Script-contract suites | 7 | **7 total, 0 failed** (CI) | no regression |
| HTTP acceptance suites | 3 | **3 total, 0 failed** (CI) | no regression |
| Permanent gates | 7 gates, documentation_drift PASS | **all PASS**, `documentation_drift` 14/0/0 | no regression |
| Agent-governance gate | 291 assertions | **371 passed, 0 failed** | extended, not weakened |
| `lint.sh` / ShellCheck | pass (baseline PHPCS debt unchanged) | **✓ (CI static quality)** | no regression |
| Release integrity | pass | **✓ (CI)** | no regression |

A documentation-only migration does not change test counts; the counts are
identical, which is the expected AND correct result. No assertion was weakened,
no baseline widened, no gate suppressed.

## Scope integrity

- **No production writes.** No deploy, upload, activation, content or DB
  mutation was performed.
- **No production plugin/theme changes**, no `plugins.json` change, no content
  or translation change, no Polylang change.
- **No application behaviour change.** `git diff 5a75417` touches only
  `AGENTS.md`, `docs/*`, `docs/reports/`, `docs/evidence/`, `docs/templates/`,
  `.agents/skills/` and two test gates (`tests/scripts/verify-*.py`). No file
  under `wp-content/`, `scripts/`, `docker/`, `compose.yaml` or `.github/`
  changed.
- **No `.env` change** (never read or written).
- **No generated region was hand-edited** — zero diff across every generated
  region (`plugins.json` unchanged; CI registry-drift check passed).
- **No Flutter/mobile access** — never inspected, referenced as a path, or
  modified; the scope rule is preserved everywhere.
- **No historical report deleted or rewritten.**

## Final status

**PASS — Agent skills/documentation architecture migrated and fully verified.**

Every PASS condition holds: clear ownership (the "one home" rule is in the
standard and CI-enforced); no conflicting sources of truth (the drifted §13.1
table was removed; the stale engine lifecycle fact in `docs/deployment.md` was
corrected against `plugins.json`); complete skill workflows (14 skills, 12
sections, all governance checks green); canonical specifications intact;
historical evidence preserved; valid governance (extended, not weakened); full
CI regression success (three blocking jobs green: 67/67 in-process, 7/7
script-contract, 3/3 acceptance, all permanent gates PASS); and no unintended
code/production changes.

Limitations (stated honestly, per `docs/templates/report.md`):

1. The sandbox has no php/docker, so `./scripts/lint.sh`,
   `./scripts/run-tests.sh`, `./scripts/verify-release.sh`,
   `generate-registry-docs.php --check` and `release-manifest.py --verify` were
   **not run locally**; CI (same contract, same committed fixtures) is the
   authoritative runner and is green.
2. The independent second-pass review (Stage 22) was **performed directly with
   fresh-eyes probes** rather than by the delegated sub-agent, which the
   environment's model quota rejected before producing output; the probe
   results are recorded in `…/05-second-pass-review.txt`.

_Last verified: 2026-09-30 by the agent-skills documentation migration_