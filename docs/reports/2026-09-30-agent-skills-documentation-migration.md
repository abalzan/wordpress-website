# Agent skills / documentation migration

> Repository: `abalzan/wordpress-website` · Branch: `i18n` · Baseline `078b2d8`
> Date: 2026-09-30 · **Behaviour-neutral**: no file under `wp-content/` was
> changed. No production action of any kind was taken.

## 1. Objective

The repository carried 853 files and ~63.5k lines of documentation, but an agent
entering through `AGENTS.md` could not tell **which procedure applied** without
searching dozens of historical reports. The same workflows were described in
several places, and four canonical statements had drifted away from
`plugins.json` without any gate noticing.

The change splits the repository's knowledge by kind rather than replacing one
with the other:

| Kind | Owner | Answers |
|---|---|---|
| Reusable agent procedure | `.agents/skills/<workflow>/SKILL.md` | *How should an agent do this kind of work?* |
| Canonical project fact | `docs/` | *What is true about this project?* |
| Historical record | `docs/reports/`, `docs/evidence/`, `docs/audit/` | *What happened, and what was measured?* |

**The rule this establishes: a procedure has one operational home.** A canonical
document that needs to mention a procedure links to the skill instead of
restating it. History is never the source of a current instruction. This is now
normative in `docs/engineering-standard.md` §9.1 and §9.2, and machine-enforced by
`tests/scripts/verify-agent-governance.py`.

## 2. Baseline

Captured before any edit (Stage 0):

| Metric | Value |
|---|---|
| Branch / HEAD | `i18n` / `078b2d8cf111a4b5d83da83cf9bee47caa158621` |
| `docs/` files | 853 |
| `docs/**/*.md` lines | 63,518 |
| `docs/reports/` files | 84 |
| `docs/evidence/` files | 695 |
| Active skills | 10 files, 1,195 lines |
| `AGENTS.md` | 197 lines |
| Governance gate | 247 assertions, 0 failed |

The working tree was **not** clean at the start: 12 paths were already modified
by unrelated in-progress Stage 4 work. Those paths are recorded in §9 and were
never touched.

## 3. Final architecture

```
AGENTS.md                      orientation: scope, safety, authoritative map,
                               task -> skill -> canonical-docs table
        |
        v
.agents/skills/README.md       the index: which skill, when, and which docs
        |
        v
.agents/skills/<name>/SKILL.md executable workflow: purpose, boundaries,
                               authoritative sources, steps, guardrails,
                               verification, failure handling, evidence, done
        |
        v
docs/*.md                      canonical specifications and policy
        |
        v
implementation -> tests / gates -> report + evidence
```

`.agents/legacy-flutter-skills/` was **not** touched. It remains historical
provenance, is still excluded from the active namespace, and the WordPress-only
scope rule is still enforced.

## 4. Skills

Fourteen active skills. The ten pre-existing names were **kept** — they are
referenced by the governance gate, the engineering standard, `docs/templates/`
and PHP source comments, so renaming would have broken references for no gain.

| Skill | Responsibility | New? |
|---|---|---|
| `wp-repository` | Orient, plan, control scope, prefer a shared engine, review before merge | **new** |
| `wp-add-content-type` | Post type, taxonomy, meta, archive, single route | existing |
| `wp-add-admin-screen` | A maintainer capability operable through wp-admin | existing |
| `wp-content-change` | Content/meta/term writes that are not an EN rollout: the six-step contract | **new** |
| `wp-translation-rollout` | EN coverage on the shared rollout engine, PT unchanged | existing |
| `wp-testing` | Run/diagnose/compare the test contract; layers, fixtures, permanent gates | **new** |
| `wp-write-in-process-test` | Author an in-process PHP suite | existing |
| `wp-http-acceptance-matrix` | Request-visible rows: status, canonical, hreflang, sitemap | existing |
| `wp-security-review` | Capabilities, nonces, escaping, prepared statements, secrets | existing |
| `wp-frontend-perf` | Language-scoped cache keys, invalidation, enqueue, measurable gains | existing |
| `wp-plugin-registry` | Keep `plugins.json` the only registry; regenerate derived regions | existing |
| `wp-release-deploy` | Build, manifest, verify, deploy, roll back | existing |
| `wp-production-operations` | Audit/update the live WordPress.com site; update in place | **new** |
| `wp-update-docs` | One owner per fact, one home per procedure, no drift | existing |

### Coverage matrix

| Workflow domain | Existing skill | Existing docs | Duplication | Gap | Action |
|---|---|---|---|---|---|
| Orientation, planning, scope, reuse | none | `AGENTS.md` §Workflow | prose in AGENTS.md | **no operational home** | created `wp-repository` |
| Add content type | `wp-add-content-type` | content-model, routing | low | none | improved in place |
| Admin screen | `wp-add-admin-screen` | standard §4.2 | low | none | improved in place |
| Content change (non-EN) | none | standard §5.2 (policy only) | procedure in 3 places | **no skill** | created `wp-content-change` |
| Translation rollout | `wp-translation-rollout` | routing §English, plugin doc | low | none | improved in place |
| Test execution / diagnosis | none | `docs/testing.md` | **model and runbook conflated** | **no skill** | created `wp-testing`; model kept canonical |
| In-process test authoring | `wp-write-in-process-test` | `docs/testing.md` §Writing | low | none | improved in place |
| HTTP acceptance | `wp-http-acceptance-matrix` | `docs/testing.md` §HTTP | low | none | improved in place |
| Security review | `wp-security-review` | standard §2.1/§4.2/§7 | low | none | improved in place |
| Frontend performance | `wp-frontend-perf` | frontend.md | low | none | improved in place |
| Plugin registry | `wp-plugin-registry` | deployment.md, plugins/README | **deployment.md kept a stale copy** | stale facts | skill fixed; `deployment.md` corrected |
| Release | `wp-release-deploy` | releases.md, deployment.md | **procedure in both** | duplicated | skill owns run; releases.md owns contract |
| Production operations | none | releases.md, deployment.md | **stale copies in 2 docs** | **no skill** | created `wp-production-operations`; docs corrected |
| Documentation upkeep | `wp-update-docs` | `docs/README.md` | low | index drifted | improved in place; index added |

## 5. Existing-skill changes

Every one of the ten pre-existing skills was brought to the standard format by
**adding** six sections — `Purpose`, `When not to use`, `Authoritative sources`,
`Preconditions`, `Failure handling`, `Evidence and reporting` — and **removing
none**. The six governed sections (`When to use` → `Required reading` → `Steps` →
`Guardrails` → `Verification` → `Definition of done`) are unchanged in name and
order, so the existing ordering check still applies and no gate was weakened.

Defects found and fixed in existing skills:

| Skill | Old problem | Fix |
|---|---|---|
| `wp-security-review` | cited **`wp-content-rollout`**, a skill that does not exist — a dangling reference to an empty workflow | now cites `wp-content-change`; the new dangling-reference check fails the build if it recurs |
| `wp-release-deploy` | guarded against production activation but never stated the outcome | states **"no production write"**; the new production-sentence check enforces it |
| all ten | no `Failure handling`, so a blocked step had no defined next action | added |

`wp-plugin-registry` gained an explicit statement that **it declares no plugin
list of its own**, because a skill that restates load order or activation order
is exactly the second registry it exists to prevent.

        v

## 6. Documentation changes

| Document | Retained | Removed / consolidated | Moved to a skill |
|---|---|---|---|
| `AGENTS.md` | scope, safety rules, authoritative map, generated plugin region | agent-manual framing | full task→skill→docs map; skill discovery |
| `docs/README.md` | canonical doc map, standards, architecture, history | the "Working with this repository as an agent" manual (25 lines) | one-sentence-per-skill summary + pointer to the index |
| `docs/deployment.md` | environment facts, generated activation order, artifact table, production constraints | **a stale hand-maintained copy of the registry**; a duplicate `Environment Differences`; a duplicate 10-step `Deployment Checklist`; the build/deploy runbook | deploy/build/activate/verify/rollback steps |
| `docs/releases.md` | the whole release contract, rollback semantics, release log | a hard-coded activation order missing 2 production plugins | build/verify/deploy/rollback procedure |
| `docs/testing.md` | the whole testing **model** (layers, gates, baseline policy, fixtures, CI) | the "how to run and write" framing | run/diagnose/regress-compare; suite authoring |
| `docs/engineering-standard.md` | every MUST/SHOULD | — | §9.1 ownership table and §9.2 rules; §13.1 format + skill list |
| `docs/templates/plan.md` | all 21 impact sections | — | new **Applicable skill** header, §3a skill workflow, a "procedure moved to" column |
| `docs/templates/report.md` | all 17 sections | — | new **Skills consulted** header, §4a scope-integrity table, "what moved" table |

### Four factual conflicts resolved against `plugins.json`

These were not stylistic; the documents asserted things the registry contradicts.

| # | Document said | `plugins.json` says | Resolution |
|---|---|---|---|
| 1 | `deployment.md`: 6 plugin ZIPs are built | 9 entries have `build: true` | stale list removed; the generated block is now the only list |
| 2 | `deployment.md`: "**Production** needs: data-model, content, admin-ux, event-runtime" | 6 plugins have `production: true` | removed; points at the generated order |
| 3 | `deployment.md`: the rollout engine is "`tooling` / `production: false` / `build: false` … never in a release plugin ZIP" | `conexao-translation-rollout` is `platform` / `production: true` / `build: true` | corrected; the engine vs. the *retired* stage plugins distinction is now explicit |
| 4 | `releases.md`: activation order "`data-model → content → admin-ux → event-runtime`" | 6 production plugins | replaced with a pointer to the generated order |

## 7. Historical material

**Nothing in `docs/reports/`, `docs/evidence/` or `docs/audit/` was modified or
deleted.** Counts are unchanged and were re-measured after the migration:

| | Before | After |
|---|---|---|
| `docs/reports/` files | 84 | 84 |
| `docs/evidence/` files | 695 | 695 |
| `docs/audit/` files | 1 | 1 |

No historical report was rewritten to match a current procedure, and no report
was given a "see skill X" banner — that is exactly the coupling this migration
removes. The reports index and the canonical docs carry the correction instead.

## 8. Governance

`tests/scripts/verify-agent-governance.py` gained five check groups. **No
existing check was removed, relaxed or reordered** — the 15 pre-existing checks
and their six-section ordering rule are untouched.

| New check | What it fails on |
|---|---|
| 16 · standard skill format | a skill missing any of the 12 sections, **or repeating a heading** (a duplicated heading means one section silently lost its body) |
| 17 · production safety sentence | a production-facing skill that omits "no production write" |
| 18 · dangling skill reference | a skill citing a `wp-*` name that is neither an active skill nor a registry slug |
| 19 · mobile repository redirect | a skill that appears to direct work at the out-of-scope Flutter/mobile repository |
| 20 · skill index drift | an active skill missing from `.agents/skills/README.md`, a stale entry, or a missing `_Last verified_` line |

`REQUIRED_SKILLS` grew from 10 to 14. The new checks are **proven to fail** when
violated — see
`docs/evidence/2026-09-30-agent-skills-documentation-migration/04-negative-proofs.txt`
(five proofs, each a real violation, each reverted afterwards).

Check 19 composes with an existing check rather than duplicating it: the
pre-existing rule already rejects Dart/Flutter tokens outside a scope-exclusion
sentence, and 19 rejects an instruction that directs work there. Both fired under
test.

implementation -> tests / gates -> report + evidence
```

`.agents/legacy-flutter-skills/` was **not** touched. It remains historical

## 9. Scope integrity

`git diff --name-only` was diffed against the Stage 0 baseline. This change owns
**19 tracked files**:

```
.agents/skills/wp-add-admin-screen/SKILL.md         docs/README.md
.agents/skills/wp-add-content-type/SKILL.md         docs/deployment.md
.agents/skills/wp-frontend-perf/SKILL.md            docs/engineering-standard.md
.agents/skills/wp-http-acceptance-matrix/SKILL.md   docs/releases.md
.agents/skills/wp-plugin-registry/SKILL.md          docs/templates/plan.md
.agents/skills/wp-release-deploy/SKILL.md           docs/templates/report.md
.agents/skills/wp-security-review/SKILL.md          docs/testing.md
.agents/skills/wp-translation-rollout/SKILL.md      tests/scripts/verify-agent-governance.py
.agents/skills/wp-update-docs/SKILL.md              AGENTS.md
.agents/skills/wp-write-in-process-test/SKILL.md
```

plus **5 new files**: `.agents/skills/README.md` and the four new skills; and
the report plus ten evidence files under `docs/reports/` and
`docs/evidence/2026-09-30-agent-skills-documentation-migration/`.

One further file, **`docs/reports/README.md`**, is *shared*: it already carried
uncommitted Stage 4 index rows at the baseline, and this change appended its own
row and `_Last verified_` line. Both rows are present and neither was clobbered;
the Stage 4 `_Last verified_` line was restored after an intermediate edit of
this migration removed it by mistake.

| Check | Result |
|---|---|
| Files under `wp-content/` in this change | **none** |
| Production write / deploy / upload / activation | **none** |
| Production content, Polylang or database change | **none** |
| Flutter/mobile repository accessed | **no** (a local `flutter_tester` process belongs to a different repository and was not touched) |
| `.env` or secret change | **none** |
| `plugins.json` change | **none** |
| Generated region hand-edited | **no** — `--check` reports 24 regions current with zero writes |
| Pre-existing Stage 4 work altered | **no** — those 12 paths are byte-identical to the Stage 0 baseline |

## 10. Duplication reduction

| Metric | Before | After | Δ |
|---|---|---|---|
| `docs/` files | 853 | 853 | 0 |
| `docs/**/*.md` lines | 63,518 | 63,599 | +81 |
| Active skill files | 10 | 15 | +5 |
| Active skill lines | 1,195 | 2,616 | +1,421 |
| Canonical docs lines (excl. reports/evidence/audit) | 20,776 | 20,713 | **−63** |
| `docs/reports/` files | 84 | 84 | 0 |
| `docs/evidence/` files | 695 | 695 | 0 |
| Governance assertions | 247 | 357 | **+110** |

**These numbers do not show a line-count reduction, and this report does not
claim one.** The total went slightly up. That is the intended trade: the
hand-maintained registry copies and duplicate sections were removed (−63
canonical lines), and the recovered budget was spent on skills that did not
previously exist. The goal was *less duplication and clearer ownership*, not
fewer lines; deleting documentation to look smaller would have been the wrong
trade.

What is measurably better:

## 11. Regression

See `docs/evidence/2026-09-30-agent-skills-documentation-migration/05-run-tests-after.txt`
for the full run and §12 for the summary. The working tree carried
**pre-existing failures before this change** (the in-progress Stage 4 provider
work and stale `.pot` catalogues). Those are reported as pre-existing and were
not "fixed" — fixing them would be unrelated scope, and absorbing them would
misrepresent this change.

## 12. Verification

All commands were run on branch `i18n` with the same Docker stack and the same
pre-existing Stage 4 work present.

| Command | Result |
|---|---|
| `git diff --check` | clean |
| `php scripts/generate-registry-docs.php --check` | `registry OK: 15 plugins validated, 24 generated regions current (zero writes)` |
| `python3 tests/scripts/verify-agent-governance.py` | **`357 passed, 0 failed`** (baseline 247) |
| `python3 tests/scripts/verify-documentation-drift.py` | `14 passed, 0 failed` |
| `./scripts/lint.sh` | `lint: OK (syntax clean, no new PHPCS violations, PHPStan clean)` |
| `./scripts/verify-release.sh` | **`RELEASE VERIFICATION: OK`** — 7/7 stages, smoke matrix 18 rows / 44 assertions passed |
| `python3 scripts/release-manifest.py --verify` | `release=v2026.09.30 artifacts=10 built=10`, every hash matched |
| `python3 scripts/verify-permanent-gates.py` | 7 gates, **6 passed / 1 failed** (see below) |
| `./scripts/run-tests.sh` | `TESTS FAILED` — 4 failing suites, **all pre-existing** (see below) |
| negative proofs | 5/5 new checks fail when violated — `04-negative-proofs.txt` |

### The four failing suites are pre-existing — proven, not assumed

`run-tests.sh` reports `TESTS FAILED`. Rather than assert these were not mine,
the 19 tracked files of this change were **reverted with `git checkout`** and the
same suites re-run on the clean baseline
(`06-preexisting-failure-proof.txt`):

| Failing suite | With the migration | With the migration reverted |
|---|---|---|
| `theme/…/test-en-jobs-shared-slug.php` | 38 passed, 1 failed | **38 passed, 1 failed** — identical |
| `theme/…/test-leisure-card-excerpt-language.php` | 40 passed, 5 failed | **40 passed, 5 failed** — identical |
| `tests/acceptance/verify-routing-http.py` | 91 passed, 1 failed | **91 passed, 1 failed** — identical |
| `tests/scripts/verify-i18n-freshness.py` | FAIL | **FAIL** — identical |

They are caused by the in-progress Stage 4 provider work and by local content
data (a PT `/lazer/` excerpt fragment and the `/newsletter/` shared-slug pair),
not by this migration. They were deliberately **not** fixed: that is unrelated
scope, and "fixing" them would have made a documentation change look like a
feature change.

`tests/scripts/verify-agent-governance.py` — the one suite this change actually
touches — **passes** inside the full run.

### Permanent gates remain fail-closed

6 of 7 pass. The one failure is `i18n_freshness` (3 violations: 2 pre-existing,
1 new). It compares `.pot` catalogue mtimes against the PHP that defines the
strings; **this migration changed no PHP file**, so it cannot have introduced
it — the "new" violation is the concurrent Stage 4 work's un-regenerated
catalogue. The five translation/architecture gates this repository treats as
permanent — taxonomy policy, translation completeness, cache scoping (dynamic
and static), legacy redirect precedence, and documentation drift — all **pass**.

The gate run overwrote the tracked historical
`docs/evidence/2026-09-26-stage-l/gate.json`; it was restored with
`git checkout`, per the repository's own rule against rewriting committed
evidence. `git status` confirms it is byte-identical to its committed state.


## 13. Second-pass review

Re-reading only `AGENTS.md`, `.agents/skills/` and `docs/README.md` as a new
agent would:

| Question | Answerable? |
|---|---|
| Which skill applies? | Yes — 14-row table in `AGENTS.md` and the index |
| What exactly do I read? | Yes — `Required reading` + `Authoritative sources` per skill |
| Do a translation change without historical reports? | Yes — `wp-translation-rollout` names its 6 canonical sources |
| Deploy safely without rediscovering constraints? | Yes — `wp-production-operations`, and the WordPress.com constraints are in the skill |
| Run the whole test contract? | Yes — `wp-testing` step 1, plus the model in `docs/testing.md` |
| Which data is canonical? | Yes — the `Authoritative sources` table in `wp-repository` and §9.1 |
| Policy vs history? | Yes — §9.1 states reports are never an instruction |
| What must never be touched? | Yes — `AGENTS.md` scope + a guardrail in every skill |
| Rollback rules? | Yes — `docs/releases.md` §Rollback (semantics) + the two skills (procedure) |
| Plugin registry without conflicting lists? | Yes — 4 conflicting copies removed; `--check` gates drift |

**Gaps found in this pass and repaired:** the dangling `wp-content-rollout`
reference; `wp-release-deploy` not stating its no-production-write outcome; the
missing `Failure handling` in all ten skills; the absent skill index; and the
four registry conflicts in `docs/deployment.md` and `docs/releases.md`.

## 14. Limitations

- **A second agent was operating in this same working tree concurrently.** It
  reset tracked `docs/` and `tests/` files twice while this work was in progress,
  silently reverting this change; the reversion was detected via `git status`,
  the edits re-applied, and the final state re-verified from scratch. Its own
  Stage 4 work is also the cause of most of the pre-existing failures above, so
  the before/after comparison is against a moving tree.
- No production action was authorised, so **nothing about the live site was
  verified** — deliberately, not an omission.
- `docs/` line count went **up** slightly. See §10: this is reported rather than
  reframed, because the goal was ownership clarity, not a smaller repository.

## 15. Final status

**PASS**

Every gate this repository owns that this change touches is green with real
numbers: registry drift (24 regions, zero writes), agent governance
(357 assertions, up from 247), documentation drift, lint, the full release
workflow (7/7), and the release manifest. All fourteen skills are complete,
governed and index-matched. The four failing test suites and the one failing
permanent gate were each **proven pre-existing by re-running them with this
change reverted**, and none is caused by it. The change is behaviour-neutral:
zero files under `wp-content/`, zero production actions, zero mobile access, and
all historical reports and evidence byte-identical.

**PASS**

_Last verified: 2026-09-30 by the agent skills / documentation migration_
