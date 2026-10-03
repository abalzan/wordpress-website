# Stage G — Plugin Registry + Lifecycle

**Scope:** WordPress website repository only. Configuration/lifecycle
standardisation. No production, no content/database changes, no Polylang or REST
changes, no theme or plugin runtime changes, no Flutter/mobile work.

---

## 1. Baseline

| Item | Value |
|---|---|
| Branch | `i18n` |
| Starting HEAD | `451db134c2c35e66e79f8a5cacc89ca48eccc37d` |
| Working tree at start | clean (`git status --porcelain` empty) |
| Custom plugin directories (actual) | **12** |
| Build inclusion before | **11** slugs in `scripts/build-plugins-zip.sh` (omitted `conexao-guide-translation`) |
| Compose mounts before | **11** (omitted `conexao-leisure-translation`) |
| Plugin documentation files before | **13** (`docs/plugins/README.md` + 12 plugin docs) |
| Documented activation order before | 12 numbered entries in `docs/plugins/README.md`; 11 `echo` lines in the build script; a separate 6-item prose list in `docs/architecture.md` |
| PHP | 8.5.4 |
| Tracked plugin PHP files changed | **0** (Stage G touched no plugin or theme code) |

### Reconciled reality vs. the standard's intended model

The standard's §1.8 example registry and the actual repository agree on all 12
plugin slugs, on the 4/3/5 platform/tooling/rollout split, and on the retired
status of the five rollout plugins. Two documented discrepancies were real and
were resolved from evidence, not copied blindly:

| Discrepancy | Evidence found | Resolution |
|---|---|---|
| `conexao-guide-translation` absent from the build list | `scripts/build-plugins-zip.sh:19-31` listed 11 slugs | It is `status: retired`, so it is **excluded** from release ZIPs — the standard forbids building retired rollouts. The omission is now correct by construction rather than by accident. |
| `conexao-leisure-translation` absent from Compose mounts | `compose.yaml:41-51` mounted 11 plugins (audit finding J-06) | It is `mount: true` like its four sibling retired rollouts, so the mount is now **generated and present**. Local-only; implies no production activation. |
| `docs/plugins/README.md` said "10 custom plugins" then listed 12 | lines 3 vs 9-20 | Generated count is now **12**, read from the registry. |

### Plugin-header reconciliation (Phase 2) — deliberately BLOCKED, not guessed

Header convention audit (`Plugin Name`, `Description`, `Version`, `Requires at
least`, `Requires PHP`, `Requires Plugins`, `Text Domain`):

- **All 12** declare `Plugin Name`, `Description`, `Version`, `Text Domain`.
- **Only 2** declare `Requires Plugins` (below). **Zero** plugin headers were
  edited in this stage.
- `Requires PHP` is **inconsistent and ambiguous**: five rollout/migration
  plugins declare `7.4`, `conexao-admin-ux` declares `8.0`, and six declare
  nothing. `Requires at least` is declared by three plugins only.
- Choosing values for the six silent headers would mean **inventing** a
  compatibility floor per plugin, which Phase 2 forbids. This normalization is
  therefore **BLOCKED and recorded** rather than guessed. It is unrelated to
  the registry and does not block Stage G.

---

## 2. Registry

**Path:** `plugins.json` (repository root).

**Schema:** one `load_order` array of objects. Per entry: `slug`, `name`,
`class`, `status`, `production`, `build`, `mount`, `dependencies`,
`version_source`, `documentation`. Top level: `$schema_version`,
`description`, `conventions`, `generator`.

**Versions are deliberately not duplicated.** The WordPress plugin header is
authoritative; `version_source` records only *where* to read the version, and
the generator reads the header to print the current value in generated docs.
This is what makes the previously-stale version tables (audit F-01) self-
correcting.

### Inventory (12 plugins, registry load order)

| # | Slug | Class | Status | Prod | Build | Mount | Dependencies | Version (header) |
|---|------|-------|--------|------|-------|-------|--------------|------------------|
| 1 | conexao-data-model | platform | active | yes | yes | yes | — | 1.6.0 |
| 2 | conexao-content | platform | active | yes | yes | yes | — | 1.0.0 |
| 3 | conexao-admin-ux | platform | active | yes | yes | yes | — | 1.0.6 |
| 4 | conexao-event-runtime | platform | active | yes | yes | yes | conexao-data-model | 1.2.1 |
| 5 | conexao-event-importer | tooling | active | no | yes | yes | conexao-data-model, conexao-event-runtime | 1.7.1 |
| 6 | conexao-leisure-migration | tooling | active | no | yes | yes | — | 2.1.0 |
| 7 | conexao-sponsor-migration | tooling | active | no | yes | yes | — | 1.1.0 |
| 8 | conexao-page-translation | rollout | retired | no | no | yes | — | 1.0.0 |
| 9 | conexao-blog-translation | rollout | retired | no | no | yes | — | 1.0.0 |
| 10 | conexao-job-translation | rollout | retired | no | no | yes | — | 1.0.0 |
| 11 | conexao-leisure-translation | rollout | retired | no | no | yes | — | 1.0.0 |
| 12 | conexao-guide-translation | rollout | retired | no | no | yes | — | 1.0.0 |

**Distribution:** platform 4 · tooling 3 · rollout 5. Active 7 · retired 5.
**Production 4** · **build 7** · **mount 12**.

### Dependency validation result

Dependencies are **not inferred**. They come from the plugins' own headers, and
the generator fails whenever the registry and the headers disagree. Exactly two
headers declare a dependency, and those are the only two registry edges:

- `conexao-event-runtime` → `Requires Plugins: conexao-data-model`
- `conexao-event-importer` → `Requires Plugins: conexao-data-model, conexao-event-runtime`

Load order is valid (both dependencies precede their dependents), and the graph

---

## 3. Generated outputs

Generator: **`scripts/generate-registry-docs.php`** (`--check` / `--write`,
plus a read-only `--build-count` query). It writes only inside explicit marker
pairs — `<!-- BEGIN/END GENERATED PLUGIN REGISTRY: … -->` in Markdown and
`# BEGIN/END GENERATED PLUGIN REGISTRY: …` in shell/YAML.

| Generated region | File |
|---|---|
| Plugin inventory (slug/class/status/prod/build/mount/doc) | `AGENTS.md` |
| Plugin registry summary + workflow | `README.md` |
| Load order, lifecycle table, dependency evidence, vocabulary | `docs/plugins/README.md` |
| Per-plugin lifecycle metadata (12 files) | `docs/plugins/conexao-*.md` |
| Production activation order, build output, tooling, retired rollouts | `docs/deployment.md` |
| Plugin inventory with header versions | `docs/project-inventory.md` |
| Plugin load order prose | `docs/architecture.md` |
| Release build list + activation notes (2 regions) | `scripts/build-plugins-zip.sh` |
| Local plugin bind mounts | `compose.yaml` |

**21 generated regions across 19 files.** Hand-written content outside every
marker is preserved.

### Competing sources removed (not duplicated)

- `AGENTS.md` — "6 custom plugins" count and the 12-row manual table → generated.
- `README.md` — 6-entry plugin tree, the hard-coded load-order sentence, and the
  "packages all plugins" build claim → generated registry section.
- `docs/plugins/README.md` — "10 plugins", 12-row inventory, 12-step load order,
  the manual dependency paragraph → generated.
- `docs/deployment.md` — the 6-ZIP output list, the 4-plugin "production needs"
  paragraph, and a stray duplicated checklist → generated.
- `docs/project-inventory.md` — the stale 9-row version table → generated from
  headers (F-01 resolved).
- `docs/architecture.md` — the 6-item prose load order → generated.
- `docs/development.md` — the hard-coded load-order arrow → pointer to the registry.
- `docs/README.md` — added the "plugin registry is the single source of truth"
  section and the generated-region map.

**Historical reports under `docs/reports/` and `docs/audit/` were deliberately
not rewritten.** They are the point-in-time record and remain accurate as
history; the audit is the source of the findings this stage closes.

---

## 4. Build verification

Command: `./scripts/build-plugins-zip.sh` (output redirected to a temp dir so
no artefact is committed; `dist/` is git-ignored and was not written to).

| Measure | Value |
|---|---|
| Registry `build: true` | 7 |
| ZIPs produced | **7** |
| `build: false` excluded | 5 |
| Retired rollouts in output | **0** (all 5 confirmed absent) |
| `tests/`+`fixtures/`+`node_modules`+`.DS_Store` entries per ZIP | **0** |
| Build exit code | 0 |

Produced: `conexao-data-model`, `conexao-content`, `conexao-admin-ux`,
`conexao-event-runtime`, `conexao-event-importer`, `conexao-leisure-migration`,
`conexao-sponsor-migration`.

The script now fails — before packaging anything — on a malformed registry, a
stale generated list, a missing `build: true` directory (previously a silent
`WARNING: skipping`), or a build count that disagrees with the registry.

---

## 5. Compose verification

| Measure | Value |
|---|---|
| Registry `mount: true` | 12 |
| Plugin mounts in `docker compose config` | **12** |
| Orphan/manual plugin mounts outside the generated region | **0** |
| `docker compose config` | **exit 0**, no warnings |

The generated region uses the repository's existing convention verbatim
(`./wp-content/plugins/<slug>:/var/www/html/wp-content/plugins/<slug>`) and
nothing else in `compose.yaml` was touched: no service, volume, image, port,
environment variable or WordPress configuration changed. The previously missing
`conexao-leisure-translation` mount is now present and generated (J-06).

**Noted, not changed:** `docker/entrypoint-fix-permissions.sh` still chmods only
the theme and `conexao-content` (audit D-06). That is a runtime permission
concern outside this stage's configuration scope, so it is recorded rather than
silently altered.

---

## 6. CI verification

`php scripts/generate-registry-docs.php --check` was added to the existing
`.github/workflows/ci.yml` `static-quality` job as a **blocking** step (no
`continue-on-error`), ahead of the untouched Stage D `lint.sh` gate. The Stage D
gate, the ShellCheck step, the non-blocking PHPCS telemetry step and the
Stage E integration job are all unchanged, and no second workflow was added.

- The step was **validated locally** by executing the exact command it runs:
  it exits 0 on a clean tree and non-zero on drift/invalid data.
- The **GitHub-hosted workflow did not run.** Nothing was pushed and no remote
  run ID exists. No hosted run is claimed.

---

## 7. Test results

Numbers are from real runs on the local stack; Stage G changed no runtime code
or content, so the comparison to the Stage F/Stage E baseline is the point.

| Run | Stage F/Stage E baseline | After Stage G | Delta |
|---|---|---|---|
| `./scripts/run-tests.sh` (in-process) | 46 total, 31 passed, 15 failed | **46 total, 31 passed, 15 failed** | none |

---

## 8. Failure proofs

Every proof was run, captured, and then reverted. The working tree contains none
of them (verified by `git status` and byte-comparison against pre-proof copies).

**Drift proof** — deleted the `conexao-leisure-migration` mount from the
generated Compose region by hand:

```
$ php scripts/generate-registry-docs.php --check
ERROR: generated output is stale. …
  - compose.yaml :: plugin mounts
exit 1        # restored -> exit 0
```

`build-plugins-zip.sh` invoked during the same drift also exited 1 and produced
**0** ZIPs, proving the build gate is real.

**Invalid-registry proofs** (each reverted; all exit 1):

| Injected fault | Detected as |
|---|---|
| duplicate slug | `duplicate plugin slug "conexao-data-model" at load_order[0] and load_order[2]` + orphan-directory error |
| nonexistent dependency | `depends on "conexao-nope", which is not in the registry` + header mismatch |
| dependency cycle | `dependency cycle: conexao-data-model -> conexao-content -> conexao-data-model` + load-order violation |
| impossible `version_source` | `version_source does not exist: …` |
| invalid `class` | `invalid class 'whatever'; expected one of platform \| tooling \| rollout` |
| invalid `status` | `invalid status 'maybe'; expected one of active \| retired` |
| non-boolean `build` | `field "build" must be a JSON boolean, got string` |
| retired rollout forced `production: true` + `build: true` | three lifecycle-invariant errors |

**CLI proofs:** `--help` → 0; unknown argument → **2**; no mode → **2**;
`--check --write` → **2**; `--build-count --check` → **2**; `--write` is
idempotent (second run reports `0 file(s) changed`).

---

## 9. Runtime safety

| Item | Value |
|---|---|
| Production HTTP requests | **0** |
| Production DB / content changes | **NONE** |
| Polylang configuration changes | **NONE** |
| REST contract changes | **NONE** |
| Theme runtime logic changes | **NONE** (`git status wp-content/themes/` empty) |
| Plugin runtime logic changes | **NONE** — `git status wp-content/` is empty. No plugin file, including any plugin header, was modified. |
| Plugin header (metadata) changes | **NONE** (see the Phase 2 block in §1) |
| CPT / taxonomy / content-model changes | **NONE** |
| Migrations or rollouts run | **NONE** |
| Production activation state changed | **NONE** |
| Flutter/mobile files changed | **NONE** (no Flutter repo is present or touched) |
| Committed build artefacts | **NONE** (`dist/` untouched; ZIPs built to a temp dir) |

Verified with `git status --porcelain`: the change set is 2 new files
(`plugins.json`, `scripts/generate-registry-docs.php`) and modified
documentation/CI/config files only. `wp-content/` does not appear.

---

## 10. Limitations

1. **Hosted CI did not run.** Nothing was pushed, so the workflow edit is
   locally validated only (the exact gated command was executed) but has no
   remote run ID. Stated plainly rather than implied.
2. **`./scripts/lint.sh` cannot pass in this environment, before or after
   Stage G.** `composer` is not installed (the PHPStan step cannot run) and the
   local PHPCS binary reports `requires the tokenizer, xmlwriter and SimpleXML
   extensions`. This is environmental and pre-existing: the baseline run at the
   starting HEAD fails identically. The Stage D gate is therefore **not**
   reported as green here.
3. **PHPStan and ShellCheck were run directly** on the changed/touched files to
   compensate: `vendor/bin/phpstan analyse scripts/generate-registry-docs.php`
   is **clean**, and ShellCheck 0.11.0 (via the `koalaman/shellcheck:stable`
   image, since the binary is absent) is **clean** on
   `scripts/build-plugins-zip.sh`. Both started failing on the first draft and
   were fixed rather than baselined; no baseline file was altered.

---

## 11. Final state

| Item | Value |
|---|---|
| Branch | `i18n` |
| Starting HEAD | `451db134c2c35e66e79f8a5cacc89ca48eccc37d` |
| Final HEAD | the Stage G commit(s) on this branch (recorded in the delivery summary) |
| Registry plugin count | **12** |
| Production plugin count | **4** |
| Build plugin count | **7** |
| Mount plugin count | **12** |
| Retired rollout count | **5** |
| Generated regions | **21** (across 19 files) |
| `--check` result | **PASS** (12 plugins, 21 regions current, zero writes) |
| Build result | **PASS** — 7 ZIPs, 0 retired rollouts, 0 debris |
| Compose result | **PASS** — `docker compose config` exit 0, 12 mounts |
| Test result | **unchanged vs baseline** — full 46/31/15 (3244/59), theme 25/14/11 (1805/54), acceptance 2/2 |
| Lint / static result | `./scripts/lint.sh` **fails for pre-existing environmental reasons** (see §10.2); PHPStan **clean**, ShellCheck **clean** on touched files |
| Working tree | clean of temporary proof modifications |

### The two answers this stage exists to make unambiguous

- **Where is the authoritative plugin list?** → **`plugins.json`**
- **What generates the current derived lists?** →
  **`scripts/generate-registry-docs.php`**

_Last verified: 2026-09-26 by Stage G — Plugin Registry + Lifecycle_

4. **Plugin-header normalization is blocked, not done.** `Requires PHP` /
   `Requires at least` remain inconsistent across the 12 plugins (see §1). Any
   value chosen for the six silent headers would be invented, so it was left
   alone and recorded. This is a known, deliberate deferral.
5. **Historical duplication remains by design.** `docs/audit/` and
   `docs/reports/` still state plugin counts, load orders and build lists as
   they were when written. They are the historical record and were deliberately
   not rewritten; the objective was one *current* source of truth, not erasure.
6. **`docs/templates/report.md` does not exist** in the repository (Stage K
   scope), so this report follows the established `docs/reports/site/` layout
   instead.
7. **The build-selection change is intentional but visible.** Release plugin
   ZIPs drop from 11 to 7 because retired rollouts are no longer packaged. This
   is what the standard requires and what Stage G's acceptance criteria demand;
   it is called out because it changes the artefact set for future releases.

| Assertions (full) | 3 244 passed, 59 failed | **3 244 passed, 59 failed** | none |
| `./scripts/run-tests.sh --only theme` | 25 total, 14 passed, 11 failed | **25 total, 14 passed, 11 failed** | none |
| Assertions (theme) | 1 805 passed, 54 failed | **1 805 passed, 54 failed** | none |
| `./scripts/run-tests.sh --acceptance` | 2 total, 2 passed, 0 failed | **2 total, 2 passed, 0 failed** | none |

The failing-suite **set is byte-identical** before and after (diffed; only the
temp-directory name differs). The 15 pre-existing failures are the known
content/data findings (missing EN guide/blog coverage, shared county/town
language assignments, missing Stage 4.1/4.5 fixtures, Eircode-contaminated town
terms, PT menu-current assertion, seed/registry mismatches). **None were fixed,
touched or masked**, and no baseline was altered.

is acyclic. No dependency was invented; no header was added to create one.
