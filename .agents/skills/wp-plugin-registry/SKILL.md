# Update the plugin registry

## Purpose

Change what this repository's plugins **are** — add, remove, re-classify,
re-order, re-scope (production/build/mount) — through the single authoritative
registry `plugins.json`, regenerating every derived region and never creating
a second plugin list anywhere.

## When to use

Adding, removing, re-classifying or re-ordering a plugin; changing whether it
is production, built into a release ZIP, or mounted into the local Compose
stack; changing a dependency or a documentation path.

`plugins.json` is the **single source of truth** for all of that. This skill
exists to keep it that way: the failure mode being prevented is a *second*
registry — a hand-maintained plugin list somewhere that silently disagrees.

## When not to use

- Building or verifying a release from the registry — `wp-release-deploy`
  (it consumes what this skill edits).
- A plugin's own code changes — the owning plugin's skill; this skill is only
  about registry metadata.
- The script catalogue — `scripts/README.md` is a *separate* single source of
  truth; do not merge the two.

## Required reading

- `AGENTS.md` and `docs/engineering-standard.md` §1.8 (the registry) and §4
  (plugin classes) / §4.3 (steady-state activation).
- **`plugins.json`** — the authoritative registry: `load_order` with `class`,
  `status`, `production`, `build`, `mount`, `dependencies`, `version_source`,
  `documentation`; plus the `conventions` block that defines each field.
- `docs/README.md` §The plugin registry — the list of every generated region
  derived from the registry.
- `scripts/README.md` — the script catalogue (a *separate* single source of
  truth; do not merge the two).

## The fields and what they drive

| Field | Meaning | Consumed by |
|---|---|---|
| `load_order` | Activation/load order; dependencies always precede dependents | `plugins.json` validation, the generated tables |
| `class` | `platform` (production runtime) / `tooling` (local-only) / `rollout` (one-shot, activate→apply→retire) | `docs/deployment.md`, lifecycle docs |
| `status` | `active` or `retired` | build/activation decisions, docs |
| `production` | Part of the production steady state | the generated activation order in `docs/deployment.md` |
| `build` | Included in release plugin ZIPs; a `retired` rollout must **never** be `true` | `scripts/lib/release.py` (artifact allowlist), `scripts/build-plugins-zip.sh` |
| `mount` | Bind-mounted into the local Compose stack (dev only) | `compose.yaml` |
| `dependencies` | Slugs that must be activated first | registry validation |
| `version_source` | The main plugin file whose header `Version` is authoritative | version rendering (versions are **not** duplicated here) |
| `documentation` | Path to the plugin doc carrying the generated lifecycle block | `docs/plugins/<slug>.md` |

## Authoritative sources

- **`plugins.json`** is the registry itself — `load_order` with `class`,
  `status`, `production`, `build`, `mount`, `dependencies`, `version_source`,
  `documentation`, plus the `conventions` block that defines each field.
- `scripts/generate-registry-docs.php` owns every generated region derived
  from it (the plugin docs' lifecycle blocks, `docs/deployment.md`'s
  activation order, `docs/plugins/README.md`'s load-order table,
  `AGENTS.md`'s plugin inventory).
- The component **header** owns each version (`version_source`); versions are
  deliberately not duplicated into the registry.

## Preconditions

- The plugin exists (or is being added) with its main file, header and
  `docs/plugins/<slug>.md` doc.
- The intended lifecycle semantics are decided (class, status, production,
  build, mount) before the registry is edited.

## Steps

1. **Edit `plugins.json` only.** Do not edit a generated region, and do not add a
   plugin list to any other file. The registry's `description` and `conventions`
   blocks state this; the drift gate enforces it.
2. **Keep the semantics honest.** `build: true` means the plugin ships in a
   release ZIP. A `retired` rollout must be `build: false` (its code is history).
   `production: true` means the plugin is part of the steady state on
   WordPress.com. `mount: true` is a *local* convenience only and never implies
   production activation. `class: tooling` is never a production dependency.
3. **Order and dependencies.** Dependencies must precede dependents in
   `load_order`; the validator enforces it. Never express an inter-plugin
   relationship by re-registering another plugin's content types — use
   `Requires Plugins:` in the plugin header.
4. **Add the documentation.** Every plugin has `docs/plugins/<slug>.md`, and it
   starts with the required metadata block (`Class`, `Status`, `Production`,
   `Requires Plugins`, `Text domain`, `Admin screen`, `Gate`). The lifecycle
   block in that file is **generated** — do not hand-write it.
5. **Regenerate the derived regions.**
   ```bash
   php scripts/generate-registry-docs.php --write   # after editing plugins.json
   php scripts/generate-registry-docs.php --check   # drift gate, zero writes
   ```
   Generated regions live in: the `AGENTS.md` plugin inventory,
   `README.md`, `docs/plugins/README.md`, each `docs/plugins/conexao-*.md`,
   `docs/deployment.md` (production activation order),
   `docs/project-inventory.md`, `docs/architecture.md` (load order),
   `scripts/build-plugins-zip.sh` (release build list) and `compose.yaml`
   (local mounts). Never hand-edit any of them; edit the source and regenerate.
6. **Bump the version in the component header** (the `version_source` file), not
   in the registry — versions are deliberately not duplicated there. Record the
   bump in the release log (`docs/releases.md`).
7. **Verify the release integration.** The artifact allowlist is *derived* from
   `build: true` in load order plus the theme. If you changed `build` or
   `production`, the derived lists must change with it — and nothing else may.
8. **Check drift in CI.** `--check` is a blocking gate in
   `.github/workflows/ci.yml`. It must pass before you finish.

## Guardrails

- **`plugins.json` is the only plugin registry.** Never introduce a second
  machine-readable plugin list, a second load-order table or a second production
  activation order. If a list is needed, generate it or check it.
- Never hand-edit a region between `<!-- BEGIN GENERATED … -->` and
  `<!-- END GENERATED … -->`. Edit the source, run `--write`.
- A `retired` rollout must never have `build: true`, and no `tooling` plugin is
  a production steady-state member.
- Dependencies precede dependents in `load_order`; do not reorder to "fix" a
  symptom of a genuine dependency error.
- `version_source` points at the header that owns the version; do not copy
  versions into the registry or into a doc table by hand.
- Do not add a plugin without a `docs/plugins/<slug>.md` (and its generated
  lifecycle block), and do not reference a `documentation` path that does not
  exist.
- `mount: true` is local-development only; it never implies production.
- Never touch the Flutter/mobile repository from this task.

## Verification

```bash
php scripts/generate-registry-docs.php --check        # blocking drift gate, 0 writes
python3 tests/scripts/verify-agent-governance.py      # no second registry in skills/docs
python3 tests/scripts/verify-release-integrity.py     # allowlist still derived, not declared
./scripts/verify-release.sh                           # end-to-end, local only
```

- `--check` reports the registry is valid and every generated region is current
  (record the real numbers).
- `git diff` after a registry change touches `plugins.json` **and** the generated
  regions — and the generated diffs are exactly the rows you expected.
- If you changed `build` or `production`, the derived build list / activation
  order changed accordingly and nothing else did.
- `tests/scripts/verify-release-integrity.py` passes: the allowlist is still
  derived from `plugins.json`, and a `retired` + `build: true` entry is refused.
- A nonexistent `documentation` or `version_source` path is a failure, not a
  warning.

## Failure handling

- **The drift gate reports a region is stale:** you edited the registry (or a
  header) and did not regenerate — run `--write`, then `--check` for zero
  writes. Never hand-edit the region to match.
- **A generated diff contains unrelated churn:** the registry edit touched
  more than intended — revert and re-edit precisely; unrelated churn hides
  real rows.
- **A dependency validation fails (dependent before dependency):** the order
  is wrong or the dependency is genuinely missing — never reorder to "fix" a
  symptom of a real dependency error.
- **A `documentation` or `version_source` path does not exist:** that is a
  failure, not a warning — create the doc or fix the path before proceeding.

## Evidence and reporting

- `--check` output (registry valid, every generated region current, zero
  writes) and the expected-rows diff go into the report, with evidence under
  `docs/evidence/<date>-<stage>/`.
- `tests/scripts/verify-release-integrity.py` (allowlist derived; a retired
  build-enabled entry refused) and `tests/scripts/verify-agent-governance.py`
  (no second registry anywhere) results are recorded alongside.

## Definition of done

- [ ] `plugins.json` is the only file edited for registry metadata.
- [ ] `class`, `status`, `production`, `build` and `mount` are correct and
      consistent (no `retired` + `build: true`; no `tooling` in production).
- [ ] Dependencies precede dependents in `load_order`.
- [ ] The plugin doc exists at the `documentation` path with the generated
      lifecycle block.
- [ ] The version was bumped in the component header, and recorded in the release log.
- [ ] `php scripts/generate-registry-docs.php --write` was run, then `--check`
      passes with zero writes.
- [ ] The generated diff contains only the expected rows — no unrelated churn.
- [ ] `tests/scripts/verify-release-integrity.py` confirms the allowlist is
      derived and refuses a retired build-enabled entry.
- [ ] No second registry was introduced anywhere (including agent skills and docs).
