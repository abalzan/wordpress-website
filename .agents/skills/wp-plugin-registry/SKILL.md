# Update the plugin registry

## Purpose

Keep `plugins.json` the single authoritative plugin registry by editing only that
file and regenerating every derived region, so no second registry can appear and
none can silently disagree.

## When to use

Adding, removing, re-classifying or re-ordering a plugin; changing whether it
is production, built into a release ZIP, or mounted into the local Compose
stack; changing a dependency or a documentation path.

`plugins.json` is the **single source of truth** for all of that. This skill
exists to keep it that way: the failure mode being prevented is a *second*
registry — a hand-maintained plugin list somewhere that silently disagrees.

## When not to use

- Building artifacts, recording a release, or verifying a deployment —
  `wp-release-deploy`.
- Operating the live site (upload, activate, verify) — `wp-production-operations`.
- Changing plugin *code*; the registry records facts, it does not implement.

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

## Authoritative sources

| Fact | Read it from |
|---|---|
| The registry itself (the only place it may be edited) | `plugins.json` |
| Plugin class semantics and steady-state activation | `docs/engineering-standard.md` §4.1/§4.3 |
| The generated activation order | `docs/deployment.md` (generated region) |
| The derived artifact allowlist | `scripts/lib/release.py` |
| The script catalogue (a separate single source of truth) | `scripts/README.md` |

**This skill declares no plugin list of its own.** Load order, counts, build
flags and activation order are read from `plugins.json` and the generated regions
at use time. Writing them here would create the second registry this skill
exists to prevent.

## Preconditions

- The plugin's own code, doc and header already exist (or are being added in this
  same change) — the registry references them, it does not create them.
- `php scripts/generate-registry-docs.php --check` was green before the edit, so
  a later failure is attributable to this change.
- No production action is implied by a registry change; a registry edit changes
  what *may* ship, not what *is* deployed.

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

- *`--check` reports a generated region is stale.* Run `--write`, then `--check`
  again. If it stays stale, the generator and the registry disagree — fix the
  generator or the registry, never the generated text.
- *The validator refuses an entry.* A `retired` entry with `build: true`, a
  `tooling` plugin in production, or a dependency that follows its dependent.
  Correct the registry; do not bypass the validation.
- *A `documentation` or `version_source` path does not exist.* Create the doc or
  correct the path. A dangling path is a failure, not a warning.
- *The derived build list did not change after flipping `build`.* Something else
  is also reading a hard-coded list. Find it — that is a second registry, which
  is the defect this skill prevents.
- *The generated diff contains unrelated churn.* Something outside the registry
  feeds a generated region. Revert the churn and correct the source.

## Evidence and reporting

Record: the registry diff, the `--check` result with its real counts, the
regenerated diff and an explanation of every changed row, the release-integrity
result, and the version bump in the component header. If a plugin was added,
retired or re-classified, say so explicitly in the report because it changes the
production steady state. Evidence goes to `docs/evidence/<date>-<stage>/`.

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
