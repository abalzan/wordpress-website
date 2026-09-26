# Update documentation

## When to use

Any change that alters what a maintainer or an agent must know. Documentation is
updated **in the same commit** as the change it describes — never as a follow-up
task, and never as a separate "docs pass".

Use this skill together with the skill for the change itself; this one decides
*where* the documentation goes and how drift is prevented.

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
