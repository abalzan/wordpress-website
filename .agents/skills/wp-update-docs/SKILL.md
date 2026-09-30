# Update documentation

## Purpose

Decide **where** a change's documentation goes, update it in the same commit,
keep every generated region generated, and stop drift before it starts — the
change → document map plus the drift gates are the mechanism.

## When to use

Any change that alters what a maintainer or an agent must know. Documentation is
updated **in the same commit** as the change it describes — never as a follow-up
task, and never as a separate "docs pass".

Use this skill together with the skill for the change itself; this one decides
*where* the documentation goes and how drift is prevented.

## When not to use

- The operational procedure of the change itself — the domain skill owns it;
  this skill owns where its documentation lands.
- Historical reports are **never rewritten** to match current procedures —
  `docs/reports/` and `docs/evidence/` are archives (see Guardrails).

## Required reading

- `AGENTS.md` — the orientation document (short by design; depth lives in `docs/`).
- `docs/engineering-standard.md` §9 (documentation standard) and §13.2 (agent
  rules). §9.1 defines the layout and ownership of every document path.
- `docs/README.md` — the documentation index. New docs are added here in the
  same change.
- `docs/engineering-standard.md` §9.2 — the "two places is a bug" rule and the
  `_Last verified_` line requirement.
- `scripts/README.md` — the authoritative script catalogue.

## The change → document map

| The change touched | Update, in the same commit |
|---|---|
| A post type, taxonomy or meta field | `docs/content-model.md`, the owning plugin doc, the header version |
| A route, archive, single, filter or redirect | `docs/routing.md` (+ sitemap coverage, + HTTP matrix row) |
| A plugin (new, re-classified, retired) | `plugins.json`, then `php scripts/generate-registry-docs.php --write`; the plugin's own `docs/plugins/<slug>.md` |
| English/Polylang coverage | `docs/routing.md` §English rollout state, `docs/content-model.md` if meta changed, the rollout stage report |
| Theme templates, components, CSS | `docs/themes/conexao-br-irlanda.md`, `docs/frontend.md` (incl. the transient invalidation matrix) |
| A new or changed script | `scripts/README.md` (purpose, safety level, arguments, default mode, target, last verified) |
| Build, manifest, deploy or rollback | `docs/releases.md` (and the release log row) |
| REST contract | `docs/routing.md` §English, and the HTTP matrix |
| Agent skills or governance | `docs/README.md`, `AGENTS.md`, and the CI gate in `tests/scripts/` |

## Authoritative sources

- `docs/engineering-standard.md` §9 owns the documentation rules (layout,
  ownership, the "two places is a bug" rule, `_Last verified_` markers) and
  §13.2 owns the agent rules — never restate them; link them.
- `plugins.json` + `scripts/generate-registry-docs.php` own every generated
  region; `scripts/README.md` owns the script catalogue; `docs/README.md` owns
  the documentation index — the three lists that must stay in sync are all
  generated or gate-checked, never hand-copied.
- `.agents/skills/README.md` owns the agent-skill index; the governance gate
  `tests/scripts/verify-agent-governance.py` checks it against the actual
  skill set.

## Preconditions

- The change's own work is complete or in progress in the same commit —
  documentation is never a separate follow-up.
- For a report: the verification numbers exist first (a report without real
  numbers is written too early).

## Steps

1. **Classify the change** with the map above and open every affected document —
   not only the obvious one.
2. **Never hand-edit a generated region.** Anything between
   `<!-- BEGIN GENERATED … -->` and `<!-- END GENERATED … -->` is owned by
   `scripts/generate-registry-docs.php`. Edit the source (`plugins.json`, or a
   component header) and run:
   ```bash
   php scripts/generate-registry-docs.php --write   # regenerate
   php scripts/generate-registry-docs.php --check   # drift gate, 0 writes
   ```
3. **Never introduce a second source of truth.** A list that must stay in sync
   (plugin load order, versions, routes, script inventory) is generated or
   CI-checked. If you find yourself writing a list twice, stop and generate or
   check it instead.
4. **Keep `AGENTS.md` short and current.** It is orientation: what the project
   is, what must never be touched, what is authoritative, what to read first,
   where the templates and the release instructions are. Point to authoritative
   documents instead of duplicating their contents. Do not retain a stale
   operational fact just because it was once true — correct or remove it.
5. **Write stage/feature reports from the template.** Use
   `docs/templates/report.md`; put the report in `docs/reports/` (or
   `docs/reports/site/` for repository-engineering stages) and the machine
   evidence in `docs/evidence/<date>-<stage>/`. **No root-level report or
   evidence file is ever added.**
6. **Plan multi-file work first** with `docs/templates/plan.md`.
7. **End every document with its `_Last verified: YYYY-MM-DD by <area>_` line**,
   updated to today and the area that verified it.
8. **Check for drift before finishing.** `docs/README.md` links every document;
   the registry drift gate passes; no document references a path that was
   renamed or removed.

## Guardrails

- Documentation changes live **with** the change, in the same commit.
- Never edit a `GENERATED` region by hand; never add a second copy of a
  generated list.
- No secrets and no hard-coded local or external URLs in documentation.
  Reference the local development site through `CONEXAO_TEST_BASE_URL` and
  `docs/development.md`, never as a literal local URL, and never in production data.
- No root-level `*-report.md`; reports go in `docs/reports/`, evidence in
  `docs/evidence/`.
- Do not record telemetry that changes every run (plugin totals, artifact byte
  sizes, SHA values, assertion counts) as a standing fact in a governance
  document. If a number is illustrative, mark it as an example.
- A report states what was **not** done and what could not be verified. Never
  write "fully verified" when a verifier was unavailable.
- Never touch the Flutter/mobile repository from this task.

## Verification

```bash
php scripts/generate-registry-docs.php --check   # no drift, zero writes
./scripts/run-tests.sh --scripts                 # the script-contract gate
python3 tests/scripts/verify-agent-governance.py # agent governance gate
git status --short                               # only intended doc changes
```

- Every path referenced by a changed document exists (`git ls-files` / `ls`).
- `docs/README.md` links each new or moved document.
- Each touched document ends with an updated `_Last verified_` line.
- The report from `docs/templates/report.md` contains **real** verification
  numbers and an explicit limitations section.

## Failure handling

- **A drift gate is red:** fix the source or regenerate — never edit a
  generated region by hand to match a stale expectation.
- **A document references a path that no longer exists:** fix the reference
  (the governance gate checks every skill path) — never delete the check.
- **The same list is being maintained in two places:** stop; make it generated
  from its source or CI-checked, and remove the second copy in the same
  change.
- **A living document drifted from a change made earlier:** correct it now and
  record the correction; do not preserve a stale fact for stability — history
  belongs in `docs/reports/`, not in living reference docs.

## Evidence and reporting

- Reports are written from `docs/templates/report.md`, placed under
  `docs/reports/` (or `docs/reports/site/`), with machine evidence under
  `docs/evidence/<date>-<stage>/` — never at the repository root.
- Each touched living document ends with an updated
  `_Last verified: YYYY-MM-DD by <area>_` line; the drift gate owns the living
  set and reports the historical corpus separately.

## Definition of done

- [ ] Every document in the change → document map was updated.
- [ ] Generated regions were regenerated by the generator, not hand-edited.
- [ ] No list is now maintained in two places; drift is generated or CI-checked.
- [ ] `AGENTS.md` remains concise, current and free of stale facts.
- [ ] The report follows `docs/templates/report.md`; evidence is under
      `docs/evidence/<date>-<stage>/`; nothing was added at the repository root.
- [ ] Every touched document ends with its `_Last verified_` line.
- [ ] A new script is catalogued in `scripts/README.md`; a new skill appears in
      the CI governance gate.
- [ ] The registry drift gate and the script-contract gate pass.
- [ ] The report states limitations honestly, with no "fully verified" claim.
