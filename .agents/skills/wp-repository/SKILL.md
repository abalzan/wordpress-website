# Work in this repository

## Purpose

The entry workflow every other skill is used from. It establishes where you are,
what is authoritative, what the task actually is, what it must not touch, and
what "done" means — before any file is edited. It also owns **scope control** and
**reuse**, the two failures that produce the most expensive bad changes here.

## When to use

- Any task in this repository, at the start, before planning or editing.
- A multi-file change, or any change touching content, routes or
  English/Polylang — this is where `docs/templates/plan.md` is filled in.
- A review before merge: is the scope right, is anything duplicated, is a shared
  engine available instead of a new copy?
- You are unsure which skill or which authoritative source applies.

## When not to use

It is not the way to *perform* a specific change. Once oriented, switch to the
domain skill: `wp-add-content-type`, `wp-translation-rollout`, `wp-testing`,
`wp-release-deploy`, and so on. This skill does not duplicate their procedures.

## Required reading

- `AGENTS.md` — the orientation document: scope, safety rules, authoritative map.
- `docs/engineering-standard.md` — §0 (the change-safety principles that apply to
  every change), §12 (git and review), §14 (definition of done).
- `docs/README.md` — the documentation index, to find a canonical document.
- `docs/templates/plan.md` — required before multi-file work.

## Authoritative sources

This skill owns **no** project data. Every fact it needs is read, never restated:

| Fact | Read it from |
|---|---|
| Scope, safety rules, what must never be touched | `AGENTS.md` |
| Engineering rules, MUST/SHOULD levels | `docs/engineering-standard.md` |
| Content types, taxonomies, meta, language policy | `docs/content-model.md` |
| URLs, redirects, archives, filters, the `/en/` layer | `docs/routing.md` |
| Test model, layers, permanent gates | `docs/testing.md` |
| Release/deploy/rollback contract | `docs/releases.md` |
| Plugin load order, class, status, production, build, mount | `plugins.json` |
| Every script, its safety level and arguments | `scripts/README.md` |
| Version of a component | the component's own header (never a table) |

## Preconditions

- The repository root is the working directory and `git status --short` has been
  read. Uncommitted work already present is **someone else's** — record it in the
  plan, do not sweep it into your change.
- No production action is in progress without an explicit authorisation.

## Steps

1. **Orient.** Read `AGENTS.md` in full, then the skill table in it. Confirm the
   branch and `git status --short` before assuming a clean tree.
2. **Classify the task** and select the domain skill. Most tasks need one domain
   skill plus `wp-update-docs`; content or English work adds a content or
   translation skill. Record which skills you are using and why.
3. **Name the authoritative sources.** For every fact the change depends on,
   write down the one document that owns it. If a needed fact has no owner, that
   is a finding: the change should add the owner, not invent a private list.
4. **Plan before writing** when the change is multi-file, or touches content,
   routes, REST or English/Polylang. Fill in `docs/templates/plan.md` — every
   impact section, even when the answer is "none". A short task may answer the
   headings inline; it may not skip them.
5. **State non-goals explicitly.** Name the tempting adjacent work you are
   refusing, so a reviewer does not read it as an oversight.
6. **Prove a new artifact is needed.** Most "add a component/script/CPT" requests
   are an extension of an existing one. Before creating anything, search for the
   shared engine it would duplicate.
7. **Reuse, do not reinvent.** The shared pieces are:
   - scripts: `scripts/lib/bootstrap.php`, `scripts/lib/rest.py`,
     `scripts/lib/zip-build.sh`, `scripts/lib/release.py`, `scripts/lib/plan.py`
   - tests: `tests/bootstrap.php`, `tests/lib/assertions.php`
   - translation: the `conexao-translation-rollout` engine — a stage is
     *configuration*, never a second implementation
   - generated regions: `scripts/generate-registry-docs.php`
   Copying a plugin, script or engine requires a **written justification** in the
   report. Silent duplication is a defect.
8. **Keep one logical change per commit.** A behaviour-preserving refactor is
   never mixed with a behaviour change. Force-pushing a shared branch is
   forbidden; a history rewrite needs a recorded decision.
9. **Define done before you start**, from `docs/engineering-standard.md` §14, with
   the command that will produce each number.
10. **Review against the standard** when the change is complete: scope, one
    source of truth, tests that discriminate, docs updated in the same change,
    no unintended production or mobile scope.


## Guardrails

- **Read `AGENTS.md` and the standard before changing code.** Orientation is not
  optional and is not skippable because the change "looks small".
- **Never introduce a second source of truth.** Any list that must stay in sync
  (plugin load order, activation order, build list, compose mounts, versions,
  routes, script inventory) is generated from one source or checked by CI. If you
  are writing a list twice, stop and generate or check it instead.
- **Never hand-edit a `BEGIN GENERATED` / `END GENERATED` region.** Edit the
  source and run the generator.
- **Never modify PT content, PT URLs, Polylang configuration, `.htaccess`
  redirects or a REST contract without explicit instruction.**
- **No production writes** unless the task explicitly authorises them, and then
  only through the documented, admin-mediated path.
- **No secrets, no localhost URLs in production data, no hotlinked media.**
  Credentials come from the environment (`WP_USERNAME` /
  `WP_APPLICATION_PASSWORD`).
- **No application behaviour change to support a process or documentation
  convenience.** A doc or governance refactor carries no behaviour change.
- **Never touch the Flutter/mobile repository** — not its code, tests, docs, REST
  clients or workflows — from this repository. A task requiring that is out of
  scope here; say so instead of reaching outside.

## Verification

```bash
git status --short                             # only intended files
php scripts/generate-registry-docs.php --check # 0 writes, no drift
./scripts/run-tests.sh                         # the whole contract
./scripts/lint.sh                              # syntax + PHPCS + PHPStan
python3 tests/scripts/verify-agent-governance.py
```

- The working tree contains only the files the plan named.
- Every canonical document the change depends on was read, not guessed.
- No list is now maintained in two places.
- No shared engine was copied without a written justification.

## Failure handling

- **You cannot find an authoritative source for a fact you need.** Do not invent
  one. Record the gap; either the change adds the owner (and the fact) or the
  change is blocked. State it in the report's limitations.
- **A gate is red before you start.** That is pre-existing debt, not your
  regression. Record the exact failing suites as the **baseline**, keep it, and
  compare against it at the end. Never "fix" an unrelated failure to make a run
  green, and never weaken an assertion to get a pass.
- **The working tree already has uncommitted changes.** They are not yours. Do not
  revert them and do not include them in your diff or report.
- **A generated region will not regenerate.** The source and the generator
  disagree; fix the source. Do not paste the expected output into the region.
- **The plan turns out to be wrong.** Update the plan and record the change of
  direction. A wrong plan corrected is normal; an unrecorded scope drift is a
  defect.

## Evidence and reporting

Fill in `docs/templates/plan.md` before the work and
`docs/templates/report.md` after it. The report carries **real** numbers from the
commands above, the baseline-versus-final comparison, and an explicit limitations
section. Machine output goes to `docs/evidence/<date>-<stage>/`; no report or
evidence file is ever added at the repository root.

## Definition of done

- [ ] `AGENTS.md` and the engineering standard were read before editing.
- [ ] The task was classified and the domain skill(s) recorded.
- [ ] A plan exists for multi-file or content/route/EN work, with every impact
      section answered.
- [ ] Explicit non-goals are stated.
- [ ] Every fact the change depends on has exactly one named authoritative source.
- [ ] No shared engine was copied; any copy has a written justification.
- [ ] One logical change per commit; no refactor mixed with a behaviour change.
- [ ] `docs/engineering-standard.md` §14 is satisfied, with real numbers.
- [ ] The Flutter/mobile repository was not touched; no production write occurred.
