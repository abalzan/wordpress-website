# Stage K — Agent Skills + Templates

**Scope:** the WordPress website repository only. The Flutter/mobile repository
was not touched. This stage implements
[`docs/engineering-standard.md`](../../engineering-standard.md) §13 (AI-agent
standard) and §9 (templates).

**Status:** complete and verified locally. No production write, deploy, upload,
activation, content mutation, Polylang change or database migration was
performed — see §17.

| | |
|---|---|
| **Start SHA** | `0f5c586a66831e5ccccd53378524698c8d3313cc` |
| **Final SHA** | the commit that carries this report |
| **Branch** | `i18n` |
| **Working tree at finish** | clean (only intentional Stage K changes) |
| **WordPress runtime files changed** | **0** |
| **Production writes** | **0** |

---

## 1. Scope and boundary

| | |
|---|---|
| WordPress skills created | 10 |
| WordPress skills modified | 0 |
| Templates created | 2 (`plan.md`, `report.md`) + 1 PR template |
| Governance gate created | 1 (`tests/scripts/verify-agent-governance.py`) |
| `wp-content/` files touched | **0** |
| `scripts/` files touched | **0** |
| CI workflow files touched | **0** |
| Production writes | **0** |

The stage is **behaviour-neutral**: it adds documentation and one static
verification suite. §15 proves the runtime is untouched by comparing the full
test run before and after.

## 2. Verification findings that shaped the work

The audit findings were re-checked against the tree first, and two differed from
the brief:

1. **K-01 confirmed, with a correction.** `.agents/skills/` did hold 23
   Dart/Flutter skills — but it was **git-ignored** (`.gitignore`:
   `.agents/skills/`), so those files were untracked and were never part of the
   repository. WordPress-domain skills were indeed absent: **0**.
2. **`skills-lock.json` was tracked** at the repository root, so a rename (not a
   deletion) was possible and history-preserving.
3. **K-02 confirmed.** `AGENTS.md` was 208 lines, carried changing operational
   facts (pinned Polylang/WordPress versions, a route inventory, a duplicate
   plugin list) and pointed at no templates.
4. **K-03 confirmed.** `docs/templates/` did not exist; neither did
   `.github/PULL_REQUEST_TEMPLATE.md` (the `.github/` tree held only
   `workflows/ci.yml`).
5. **K-04 confirmed.** `plugins.json` is authoritative, but nothing told an
   agent to use it instead of writing a second list.

Two further facts were discovered while writing the skills:

- **`inc/rest-policy.php` does not exist.** The standard's §7 describes a public
  REST allowlist in that file; the real bilingual REST surface is entirely in
  `wp-content/themes/conexao-br-irlanda/inc/rest-language.php`. The
  `wp-security-review` skill was written against the **real** path and states
  the allowlist as a documented decision, rather than citing a file that has
  never existed — the exact failure mode the path check prevents.
- **No existing document referenced `docs/templates/`**, so creating it is
  purely additive.

## 3. Active skill inventory

Ten WordPress-domain skills under `.agents/skills/`, each
`.agents/skills/<name>/SKILL.md` with the six required sections in the order
§13.1 specifies (`When to use → Required reading → Steps → Guardrails →
Verification → Definition of done`):

| Skill | Domain |
|---|---|
| `wp-add-content-type` | CPT/taxonomy/meta/registry/Polylang decisions, admin-UX boundary |
| `wp-add-admin-screen` | capability, nonce, read-only page load, dry-run preview |
| `wp-write-in-process-test` | `tests/bootstrap.php`, shared assertions, discovery, anti-vacuous testing |
| `wp-http-acceptance-matrix` | the fixed `{id,url,expect_status,expect_contains,expect_absent,note}` schema |
| `wp-release-deploy` | derived allowlist, manifest, determinism, WordPress.com limits, rollback |
| `wp-update-docs` | change → document map, generated regions, no duplicated facts |
| `wp-security-review` | capabilities, nonces, sanitising, escaping, SQL, secrets, hotlinks |
| `wp-frontend-perf` | query counts, language-scoped caches, invalidation matrix, assets |
| `wp-translation-rollout` | PT immutability, B1/B2, the shared engine, numeric gates, portable IDs |
| `wp-plugin-registry` | `plugins.json` fields, generated regions, drift gate, no second registry |

Each skill points at **real** repository files and commands, and every path a
skill references is machine-verified to exist (§12, check 8). Two skills
(`wp-plugin-registry`, `wp-update-docs`) carry an extra section — a field table
and a change→document map — which is additive and does not disturb the required
order.

## 4. Treatment of the legacy Flutter/Dart skill material

**What was found.** 23 Dart/Flutter skill directories under `.agents/skills/`
(13 `dart-*`, 10 `flutter-*`) plus `skills-lock.json` pinning exactly those 23
with `source: flutter/agent-plugins` — material belonging to the external
Flutter/mobile toolchain, not to this WordPress repository.

**Who used it.** A repository-wide search for `.agents/`, `skills-lock` and the
skill names found **no** consumer: no CI step, no script, no build system, no
test, and no current documentation other than the Stage B report and the
standardisation audit, which both *record* the material rather than use it.

**What was done — relocated, not deleted.**

| Item | Action | Why |
|---|---|---|
| 23 `dart-*` / `flutter-*` skill dirs | moved to `.agents/legacy-flutter-skills/`, left on disk and git-ignored | the audit's constraint is "relocate, do not delete"; nothing is lost |
| `skills-lock.json` | `git mv` to `.agents/legacy-flutter-skills/skills-lock.json` | history preserved as a rename; removed from the root, where it read as an active lock |
| `.agents/legacy-flutter-skills/README.md` | **added** | states the material is provenance, NOT an active skill set, and records why |
| `.gitignore` | the blanket `.agents/skills/` ignore was replaced | the **active** WordPress skills must be tracked; only the retired subtrees stay ignored |

**On the lock file.** The engineering standard requires **no** lock model, and
nothing in the repository consumed it. It pinned only the Dart/Flutter set, so
once that set left the active namespace the lock described nothing real. It was
therefore **retired by relocation** rather than replaced: no new lock model was
invented. The drift protection the standard actually asks for is the
`.agents/skills/*/SKILL.md` structure plus the CI gate in §12, which enforces it.

**The external Flutter repository was not touched.** Only repository-local
material inside this WordPress repository was handled.

## 5. `AGENTS.md` changes

Refactored from 208 lines to 177 — shorter, and organised as an entry point
rather than a duplicate of the documentation.

| Change | Detail |
|---|---|
| Stale facts removed | the pinned Polylang/WordPress versions, the route inventory, the filter-parameter list and the hand-written plugin list were removed or replaced with links |
| Generated block preserved | the `GENERATED PLUGIN REGISTRY` region is **byte-identical**; `generate-registry-docs.php --check` still reports 22 regions current |
| Ten explicit directives added | read the standard; use `plan.md`; use `report.md`; consult `plugins.json`; reuse shared engines; keep PT immutable; preserve Polylang rules; real verification numbers; never touch the Flutter/mobile repo; no production writes without authorisation |
| Skill table added | all ten skills linked, so an agent lands on the right workflow |
| Authority table added | one row per concern naming the single source of truth |
| Length gate added | the verifier fails if `AGENTS.md` exceeds 250 lines |

## 6. `docs/templates/plan.md`

21 sections covering every planning requirement: scope, explicit non-goals,
authoritative documents, current-state findings, approach, **files expected to
change**, **files expected NOT to change**, content/data impact, route/HTTP
impact, Polylang/English impact, security, performance, production, test plan,
acceptance-matrix plan, rollback, documentation plan, release implications,
risks, verification gates and completion criteria. It opens with an instruction
that it is a template to copy, not to edit in place, and states that content,
route and English work requires one.

## 7. `docs/templates/report.md`

The completion-report standard: start/final SHA, working-tree status, scope
completed and **not** completed, files changed, runtime/content/Polylang/route
impact, production actions, verification commands, numeric test results, HTTP
acceptance, static analysis, script-contract, release/build, **failure proofs**,
regression comparison, known pre-existing failures, limitations, evidence paths,
documentation updated, rollback, and a final status of `PASS` /
`PASS WITH LIMITATION` / `BLOCKED` / `NOT TESTABLE`.

It explicitly forbids an unsupported "fully verified" claim, and requires
`passed`, `failed`, `blocked/unavailable`, `pre-existing` and `not tested` to be
distinguished from one another.

## 8. `.github/PULL_REQUEST_TEMPLATE.md`

Sections for summary, scope, non-goals, related plan, runtime, content/data,
English/Polylang, security, performance, tests, HTTP verification, release
impact, documentation, production changes, rollback, limitations, and a 15-item
checklist requiring the engineering-standard confirmations — including PT
content safety, `plugins.json` remaining authoritative, no duplicate source of
truth, real test numbers, no secrets/localhost URLs, and the Flutter/mobile
repository being untouched.

## 9–11. Documentation indexes updated

`docs/README.md` gained a "Working with this repository as an agent" section
exposing the standard, the skills, both templates, the PR template, the
governance gate, the evidence rule, the script catalogue and the release
contract. `docs/testing.md` documents the new suite (section + classification
row). `docs/evidence/README.md` lists the Stage K evidence set.

`scripts/README.md` was **not** changed, correctly: it catalogues runnable
top-level scripts in `scripts/`, and Stage K adds no script there. The new gate
is a test suite in `tests/scripts/`, documented where tests are documented. The
Stage I contract gate confirms the catalogue stays accurate — and negative proof
H proves it would reject an undocumented script.

## 12. The governance gate

`tests/scripts/verify-agent-governance.py` — static, no WordPress, no network,
zero writes. Placed in `tests/scripts/` so the canonical runner **discovers it by
convention** (Stage E), making it blocking in CI with **no workflow change**.
Fourteen checks: required files exist; `.agents/skills/` exists; every skill has
a `SKILL.md`; every skill has the six sections **in order**; the ten required
skills exist; no Dart/Flutter/mobile skill in the active namespace; no second
plugin registry and every plugin slug a skill names is a real `plugins.json`
entry; **every path a skill references exists**; `AGENTS.md` points at the
standard, the templates, `plugins.json` and the skills and stays under 250 lines;
the PR template carries the standard's checks; both templates carry the required
sections; `docs/README.md` exposes the workflow; no governance file hard-codes a
localhost URL, a credential or an external filesystem path; no root
`skills-lock.json`; and the governance surface is documentation-only.

**Result: `Stage K governance verification: 247 passed, 0 failed` (exit 0).**

The gate found four real defects in my own work while it was being written — two
hard-coded `localhost` URLs in governance documents, a PR-template checklist match
that failed on backticks, and a `rest-policy.php` path that does not exist. All
were fixed rather than waived, and one genuine bug in the gate itself (an
`IndexError` in a failure message) was found and fixed the same way.

## 13. Negative / failure proofs

A verifier never shown to fail is not a verifier. All eight proofs ran against a
**throwaway copy** of the repository at `/tmp/k-neg`; the real repository was
never modified (`git status --short` confirms). The copy reproduced the passing
baseline (exit 0, 247/0) first.

| Proof | Injected defect | Result | Exit |
|---|---|---|---|
| A | `docs/templates/plan.md` removed | 223 passed, **2 failed** | **1** |
| B | `wp-release-deploy` skill removed | 235 passed, **1 failed** | **1** |
| C | `Guardrails` section deleted from `wp-security-review` | 247 passed, **1 failed** | **1** |
| D | a skill's path changed to a nonexistent file | 247 passed, **1 failed** | **1** |
| E | a Flutter skill added to the active namespace | 255 passed, **4 failed** | **1** |
| F | `AGENTS.md` standard reference replaced | 246 passed, **1 failed** | **1** |
| G | `.github/PULL_REQUEST_TEMPLATE.md` removed | 218 passed, **1 failed** | **1** |
| H | an undocumented script added to `scripts/` | Stage I gate: 286 passed, **1 failed** | **1** |

Proof A is a two-failure result because removing the plan template also breaks
the path reference in `wp-update-docs` — the gate caught both causes. Proof H
deliberately uses the **Stage I** script-contract gate, because "every script is
catalogued in `scripts/README.md`" is that gate's contract (§10.2), not the
governance gate's. No broken fixture was left in the repository.

## 14. Test and acceptance results

`./scripts/run-tests.sh`, before and after, same stack, same database:

| Metric | Before | After |
|---|---|---|
| In-process PHP suites | 50 total, 35 passed, **15 failed** | 50 total, 35 passed, **15 failed** |
| Assertions | 3306 passed, 59 failed | 3306 passed, 59 failed |
| Script-contract suites | 2 total, 2 passed, 0 failed | **3 total, 3 passed, 0 failed** |
| HTTP acceptance | 3 total, 3 passed, 0 failed | 3 total, 3 passed, 0 failed |
| Runner exit code | 1 | 1 |

Individual gates, all exit 0: governance **247/0**, script contract **284/0**,
release integrity **209/0**, registry **13 plugins / 22 regions**, acceptance
**18/0** (guides-en), **42/0** (release), **59/0** (routing).

## 15. Regression comparison

The `diff` of the before and after failing-suite lists is **empty**: the same 15
suites, the same 3306/59 assertion split. Stage K introduced **zero** regressions.

The only delta in the whole run is script-contract **2 → 3**: the new Stage K
gate, discovered by the runner and passing. No in-process suite, assertion or
acceptance row changed.

## 16. Known pre-existing failures

The **15** failing in-process suites are pre-existing and unrelated to Stage K.
They were captured **before** any change was made, and the list is identical
afterwards. They are local **content-data** conditions recorded in the Stage J
report: untranslated blog posts, Eircode-contaminated `conexao_town` terms,
Portuguese meta descriptions on EN pages, and importer fixtures left by earlier
seed runs. Fixing them requires a content/seed pass, which is out of Stage K
scope; they were deliberately **not** touched.

## 17. Production safety

No production write, deploy, upload, activation, content mutation, Polylang
change or database migration occurred. Every command in this stage was
repository-local and read-only. The only network-facing tools used were the
existing local-only HTTP acceptance suites, which the runner refuses to point at
production. `git diff -- wp-content compose.yaml .htaccess .github/workflows
scripts` is **empty**.

## 18. Static / repository hygiene

| Check | Result |
|---|---|
| `python3 -m py_compile` on the new gate | clean |
| `php -l` over 349 `wp-content` PHP files | 349 clean, 0 errors |
| `php scripts/generate-registry-docs.php --check` | 13 plugins, 22 regions, zero writes |
| Registry drift, script contract, release integrity | all pass (§14) |
| Secrets / localhost URLs / external paths in governance files | none (gate-enforced) |
| Generated artifacts (`dist/`) | none committed; `dist/` stays git-ignored |
| `shellcheck` | **not available** in this environment — no new shell script was added, so nothing is unchecked |
| `composer` / `./scripts/lint.sh` (PHPCS + PHPStan) | **not available** in this environment; CI is authoritative. Stage K adds **0** PHP files and changes **0** runtime files, so the lint surface is unchanged |

## 19. Limitations

1. **`shellcheck` and `composer` are unavailable here**, so `./scripts/lint.sh`
   could not run. Stage K adds no shell script and no PHP file, so no new lint
   surface exists; CI runs the full gate.
2. **No production verification was possible or attempted** — production is
   WordPress.com with no SSH/WP-CLI, and Stage K changes nothing that is
   deployed. Nothing was uploaded, so `scripts/verify-deploy.py` had nothing new
   to observe.
3. **The CI workflow was not executed**; it is unchanged, and the new gate is
   blocking there by the existing discovery convention. That is a structural
   inference, not an observed CI run.
4. **The 15 pre-existing in-process failures remain.** They are content-data
   conditions, out of scope, and unchanged by this stage.
5. **The path check resolves paths that exist now.** A skill that legitimately
   documents a *planned* path would fail until the path exists — the intended
   behaviour, but it means skills must be written after (or alongside) the files
   they cite.

## 20. Rollback / recovery

Every change is in Git on branch `i18n`. Reverting the Stage K commit removes the
skills, templates, PR template and gate, and restores the previous `AGENTS.md`,
`.gitignore` and documentation, returning `skills-lock.json` to the repository
root. Because **no `wp-content` file was modified**, no runtime rollback, content
restoration or re-deployment is needed.

## 21. Definition of done (§14)

| Item | Result |
|---|---|
| Scope matches the request; nothing unrelated refactored | Yes — governance only |
| PHPCS + PHPStan | N/A in this environment; 0 PHP files added, 0 runtime files changed (§18) |
| PHP syntax checked | Yes — 349 files clean |
| In-process test added/updated | `tests/scripts/verify-agent-governance.py` (247 assertions) — discovered by convention |
| HTTP matrix addition | N/A — no request-visible change; routing/release acceptance re-run unchanged |
| Content writes | N/A — none |
| EN work | N/A — none; Polylang untouched |
| Routes/filters | N/A — unchanged |
| Content model | N/A — unchanged |
| Plugins | No new plugin; registry unchanged, drift gate passes |
| Releases | N/A — no release in scope; `dist/` untouched |
| Docs updated in the same commit; `_Last verified_` lines | Yes |
| No secrets, no localhost URLs, no committed build output | Yes — gate-enforced |
| Report with real numbers and stated limitations | This document |
| Flutter/mobile untouched | Yes |

## 22. Final status

All applicable acceptance gates hold: the required governance files and ten
skills exist; every skill has the six required sections in order; skills
reference only real paths; the active namespace contains no Dart/Flutter/mobile
skill; `AGENTS.md` is concise and points at the authoritative sources; both
templates and the PR template exist and cover the standard; `docs/README.md`
exposes the workflow; `plugins.json` remains the only plugin registry; the
script catalogue is unchanged and still accurate; the governance gate passes
(247/0) and has been proven to fail closed eight times; the test and acceptance
results show **zero** new failures; no production write occurred; no WordPress
runtime behaviour changed; no content or Polylang data changed; the external
Flutter/mobile repository was not touched; and the diff contains only
intentional Stage K changes.

The two unavailable tools in §18/§19 (`shellcheck`, `composer`) apply to nothing
Stage K introduces: there is no new shell script and no new or changed PHP file.
Every verification that Stage K's scope actually admits was executed and is
reported above with real numbers.

**Final status: PASS**

## 23. Evidence

`docs/evidence/2026-09-26-stage-k/`

| File | Proof |
|---|---|
| `skill-inventory-before.txt` | the 23 Dart/Flutter skills, 0 WordPress skills, root `skills-lock.json` |
| `skill-inventory-after.txt` | the 10 active WordPress skills and the retired legacy set |
| `agent-governance-gate.txt` | 247 passed, 0 failed, exit 0 |
| `negative-proofs.txt` | the eight failure proofs with real exit codes |
| `test-summary.txt` | before/after suite, assertion and acceptance numbers |
| `git-scope-audit.txt` | the diff, the rename, and the empty runtime areas |

_Last verified: 2026-09-26 by Stage K — Agent Skills + Templates_
