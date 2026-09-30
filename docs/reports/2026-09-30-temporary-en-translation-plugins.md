# EN TRANSLATION ROLLOUT — TEMPORARY PLUGIN PACKAGING (repository/artifact preparation)

**Date:** 2026-09-30
**Branch:** `i18n`
**Starting HEAD:** `6bc4a6be8654cc083a162445491227a278ce0a47`
**Status:** **PASS — TEMPORARY EN ROLLOUT PLUGINS READY**

**Production writes: 0. Production installations: 0. Production activations: 0.**
Nothing was installed, activated, applied or written in production. No EN record,
translation link, B2 field or taxonomy term was created anywhere. The only WordPress
touched is the local disposable Compose stack.

---

## 1. What this task did, and what it deliberately did not

The two shared EN translation components already existed at HEAD and were complete.
**This task packaged them; it did not reimplement, rewrite or "fix" either one.** The
engine is byte-identical to the Phase 2 baseline.

| | Phase 2 baseline | Current HEAD | Result |
|---|---|---|---|
| `class-conexao-translation-rollout-engine.php` SHA-256 | `baf85283df95e80c6e1e2fccb0e1290c73f6269e290e33eb138ed2cfa36a6ce4` | `baf85283df95e80c6e1e2fccb0e1290c73f6269e290e33eb138ed2cfa36a6ce4` | **IDENTICAL** |

One new file was added (`scripts/build-temporary-plugins-zip.sh`) plus its catalogue
row, because the existing release builder cannot package `build: false` plugins. See
§5. **No plugin source file, no engine file, no data file and no `plugins.json` entry
was modified by this task.**

---

## 2. Plugin 1 — `conexao-translation-rollout`

| Fact | Value |
|---|---|
| Source path | `wp-content/plugins/conexao-translation-rollout/` |
| Engine hash | `baf85283df95e80c6e1e2fccb0e1290c73f6269e290e33eb138ed2cfa36a6ce4` (unchanged) |
| Package path | `dist/temporary/conexao-translation-rollout.zip` |
| Package SHA-256 | `5b87fbc533c352197e6bf6be14b49d27537323a8370862ec7bb8031bb3d15d5b` |
| File count | **4** |
| Byte count | 11,742 |
| Version | 1.1.0 |
| Requires PHP | 8.0 |
| Requires at least (WP) | *undeclared* |
| Activation test | **PASS** — extracted artifact loads with no fatal error |
| Dry-run compatibility | **PASS** — all 7 stages plan, 0 created, 0 PT-drift |
| `production` | **false** |
| `build` (permanent release) | **false** |

Engine lifecycle surface present and verified loaded: `register_stage`,
`registered_stages`, `get_stage`, `validate_config`, `validate_manifest`,
`build_plan`, `diff_snapshots`, `calculate_gate`, `collect_verify`, `run`.
The engine remains the single owner of inventory → manifest → dry-run → snapshot →
apply → verify → gate → rollback. Nothing was reimplemented.

## 3. Plugin 2 — `conexao-en-translation`

| Fact | Value |
|---|---|
| Source path | `wp-content/plugins/conexao-en-translation/` |
| Stage count | **7** |
| Stage IDs | `en-guide`, `en-page`, `en-post`, `en-blog-page`, `en-jobs-page`, `en-leisure-description`, `en-course-provider-description` |
| Package path | `dist/temporary/conexao-en-translation.zip` |
| Package SHA-256 | `f6f9c87986d34a321c3b1925b052c59cd93e7040f01e11719142912accc9669a` |
| File count | **15** |
| Byte count | 191,905 |
| Version | 1.5.0 |
| Requires PHP | 7.4 |
| Requires at least (WP) | *undeclared* |
| Requires Plugins | `conexao-translation-rollout` |
| Activation test | **PASS** — extracted artifact loads, detects the engine, registers all 7 stages |
| Dry-run compatibility | **PASS** — GATE PASS on all 7 stages |
| `production` | **false** |
| `build` (permanent release) | **false** |

Authored manifest rows shipped, read from the plugin's own data files:
`en-guide` 51, `en-page` 31, `en-post` 43, `en-blog-page` 1, `en-jobs-page` 1,
`en-leisure-description` 289, `en-course-provider-description` 10.
Taxonomy data (`guide-terms-data.php`) ships and resolves — 13 EN terms.


---

## 4. Compatibility

**Engine/stage compatibility.** The EN plugin's header declares
`Requires Plugins: conexao-translation-rollout`. With the bind-mounted copies
deactivated, the **extracted artifacts** were loaded engine-first and the EN plugin
registered all 7 stages against them. The assertion
`strpos( CONEXAO_EN_TRANSLATION_DIR, $scratch ) === 0` proves the code under test was
the artifact, not the working tree.

**No second lifecycle.** Exactly one declared class exposes
`run` + `build_plan` + `calculate_gate`: `Conexao_Translation_Rollout_Engine`. The
engine plugin's second class, `Conexao_Translation_Rollout_Admin`, is its wp-admin
screen and owns no lifecycle step. No second engine entry point exists.

**B1/B2 classification** (read from the source, not from a hard-coded list):

| Class | Stages | Field |
|---|---|---|
| B1 record | `en-guide`, `en-page`, `en-post`, `en-blog-page`, `en-jobs-page` | — (creates linked EN records) |
| B2 field | `en-leisure-description` | `CONEXAO_EN_LEISURE_DESCRIPTION_META = _leisure_excerpt_en` |
| B2 field | `en-course-provider-description` | `CONEXAO_EN_COURSE_PROVIDER_DESCRIPTION_META = _provider_excerpt_en` |

`event`, `sponsor` and `job` are confirmed **B2 and stage-less**: `en-event`,
`en-sponsor` and `en-job` are not registered. `job` remains governed by the documented
combination of B2 behaviour + `en-jobs-page`. No retired stage
(`en-page-translation`, `en-blog-translation`, `en-job-translation`,
`en-leisure-translation`, `en-guide-translation`) is registered. Nothing was
reclassified during packaging.

**Taxonomy capability.** Only `en-guide` declares `taxonomy_callback` /
`taxonomy_gate_callback`; both resolve. `en-page`, `en-post`, `en-blog-page` and
`en-jobs-page` declare none, so only `en-guide` can create EN terms.

**Shared-slug compatibility and `newsletter`.**

| Stage | Declared shared slug |
|---|---|
| `en-page` | `newsletter` |
| `en-blog-page` | `blog` |
| `en-jobs-page` | `empregos` |
| `en-guide`, `en-post`, both B2 stages | *(none — no uniqueness exception armed)* |

`conexao_en_translation_shared_page_slug_for('en-page')` returns `newsletter`, read
out of the stage's **own manifest**, and the authored row's `en_slug` is `newsletter`.
The canonical dry-run plans it as a slug repair and **never** as `newsletter-2`.
`newsletter` is **not** in any B2 allowlist — it stays a B1 record stage. Outside a
stage's own writes the permit is disarmed: `has_filter('wp_unique_post_slug', …)` is
`false` and the permit transient is unset after verification.

---

## 5. Release boundaries

**Production steady state is unchanged** — `conexao-data-model`, `conexao-content`,
`conexao-admin-ux`, `conexao-event-runtime`. Verified: `dist/release.json` is
byte-identical before and after packaging (SHA-256
`03c39492d67b478a3551117626c070317693f5ce7d89d0f10a2944a30905f063`), and its
`allowlist.artifacts` and `production_order` still contain neither plugin.

This was **not** achieved by editing `plugins.json`. It is structural:

* the permanent builder derives its slug list from `plugins.json` `build: true`;
  neither plugin is in it;
* `release.build_manifest()` iterates the registry allowlist and never scans a
  directory, so it cannot see `dist/temporary/`;
* the release-integrity gate compares `os.listdir(dist)` for top-level `*.zip`, and
  `temporary` is a directory, not a zip.

**The one new file: `scripts/build-temporary-plugins-zip.sh`.** The smallest dedicated
mechanism, and it *refuses* to drift:

* it reuses `scripts/lib/zip-build.sh` — the single existing packaging implementation
  — so the exclusion rules and determinism guarantee are **not** duplicated;
* it never writes `plugins.json` and never emits `dist/release.json`;
* it **hard-fails** for any slug that is not `production: false` + `build: false`, so
  a future registry flip cannot silently create a second release path;
* it is catalogued in `scripts/README.md`, as the script-contract gate requires.

Output is `dist/temporary/`, which is git-ignored via the existing `dist/` rule.

---

## 6. Verification results

| Gate | Result |
|---|---|
| `scripts/lint.sh` (syntax 478 files, PHPCS, PHPStan L5) | **OK** — no new violations |
| `php scripts/generate-registry-docs.php --check` | **OK** — 14 plugins, 23 regions, zero writes |
| `tests/scripts/verify-script-conventions.py` | **PASS** 324 / 0 (was 321; +3 from the new script's catalogue row) |
| `tests/scripts/verify-release-integrity.py` | **PASS** 210 / 0 |
| `tests/scripts/verify-documentation-drift.py` | **PASS** 14 / 0 |
| Runtime verification (engine + 7 stages + policy) | **PASS** 87 / 0 |
| ZIP integrity + leakage inspection | **PASS** 24 / 0 |
| Installation smoke test (extract → load → exercise → remove) | **PASS** 26 / 0 |
| `test-shared-slug-newsletter.php` | **PASS** 37 / 0 |
| Canonical EN dry-run (all 7 stages) | **PASS** — GATE PASS × 7 |
| `tests/scripts/verify-i18n-freshness.py` | **FAIL** 8 / 3 — **pre-existing, see §7** |

### Full suite — `./scripts/run-tests.sh`

| Layer | Result |
|---|---|
| In-process PHP suites | **63 total, 63 passed, 0 failed** |
| Assertions | **4216 passed, 0 failed** |
| Script-contract suites | 6 total, 5 passed, **1 failed** (the pre-existing i18n gate) |
| HTTP acceptance suites | **3 total, 3 passed, 0 failed** |

Aggregate exit code 1, caused solely by the pre-existing i18n gate. Every in-process
suite, including the 37-assertion `test-shared-slug-newsletter.php`, passes.

A mid-task baseline run also showed `verify-documentation-drift.py` and
`verify-script-conventions.py` failing. That was **an artefact of running the baseline
while this task's files were still being written**, not a real state: once the work
settled, both pass (14/0 and 324/0). Neither represents a persistent failure.


### Determinism (Phase 15)

Each artifact was built **three** times from the same source state — two scratch runs
plus the repository run:

| Plugin | Build 1 | Build 2 | Repository | Equality |
|---|---|---|---|---|
| `conexao-translation-rollout` | `5b87fbc5…d15d5b` | `5b87fbc5…d15d5b` | `5b87fbc5…d15d5b` | **EQUAL** |
| `conexao-en-translation` | `f6f9c879…cc9669a` | `f6f9c879…cc9669a` | `f6f9c879…cc9669a` | **EQUAL** |

Byte-identical archives — no normalisation was needed, because
`scripts/lib/zip-build.sh` already normalises mtime and permissions on a staging copy
and feeds `zip -X` a pre-sorted entry list. This reuses the repository's existing
determinism convention rather than inventing one.

### ZIP integrity (Phase 10)

| | `conexao-translation-rollout.zip` | `conexao-en-translation.zip` |
|---|---|---|
| File count | 4 | 15 |
| Bytes | 11,742 | 191,905 |
| SHA-256 | `5b87fbc5…d15d5b` | `f6f9c879…cc9669a` |
| Version | 1.1.0 | 1.5.0 |
| Requires PHP | 8.0 | 7.4 |
| Requires at least (WP) | undeclared | undeclared |

Leakage checks — **all zero, all programmatically verified**:
`.env` 0 · secrets/credentials 0 · tests 0 · fixtures 0 · docs 0 · reports 0 ·
screenshots 0 · logs 0 · caches 0 · `node_modules` 0 · temporary worktrees 0 ·

---

## 7. Pre-existing failures (not caused by this task)

**`verify-i18n-freshness.py`: 8 passed, 3 failed** — stale `.pot` catalogues for
`conexao-br-irlanda`, `conexao-content`, `conexao-event-runtime`.

The gate reports "2 pre-existing, 1 new". The "new" one is `conexao-content`, which
is simply **absent from the recorded baseline**, not newly introduced. Proven by
stashing all of this task's work and re-running the gate at pristine HEAD:

```
8 passed, 3 failed
  stale catalogues: 2 pre-existing, 1 new
```

— identical output before and after. This task modified no PHP, no theme, no `.pot`
and no `plugins.json`; `git diff --stat HEAD` over those paths is empty. The correct
remedy is `wp i18n make-pot` regeneration, which is outside this task's scope and
outside its two plugins.

**`docs/evidence/2026-09-26-stage-l/gate.json`** is a tracked artifact that the
permanent-gate runner rewrites on every run. Running the gate updated it; it was
reverted with `git checkout` so this task leaves no unrelated diff.

**No environmental failures were encountered.** The Docker stack, the local
WordPress and PHP 8.3.35 in-container were all available throughout.

---

## 8. Limitations and assumptions, stated honestly

* **The installation smoke test ran against the local Compose WordPress, not a
  throwaway container.** It is a disposable local database, and the activation state
  was snapshotted and restored exactly (verified: `exact match: YES`, 10 → 10
  entries). It is a genuine local installation test; it is not a
  spin-up-a-brand-new-WordPress test.
* The smoke test extracts to a scratch tree and loads it, rather than writing into
  `wp-content/plugins/`, because the plugin directories are bind mounts and cannot be
  overwritten by a container copy. The extracted layout asserted is exactly
  `wp-content/plugins/<slug>/<slug>.php`, and the load path is the same
  `require_once` WordPress performs for an active plugin. The plugin was **not**
  written into `active_plugins` by the test, and that is asserted.
* `Requires at least` (the WordPress minimum) is **undeclared in both plugins' own
  headers**. Reported as undeclared rather than guessed.
* `conexao-translation-rollout/languages/README.md` ships in the ZIP. It is a
  documented runtime-tree README explaining why the directory is empty, and the
  shared packager keeps `languages/` content by design. It contains no secret and no
  development artifact.
* The Phase 12 read-only production check was **not** performed. It requires
  production credentials and network access, neither of which this task needs.
  Production was not contacted at all, which is the stronger guarantee.

---

## 9. Safety

| Fact | Value |
|---|---|
| Production writes | **0** |
| Production installations | **0** |
| Production activations | **0** |
| EN records created | **0** |
| Translation links created | **0** |
| B2 fields written | **0** |
| EN taxonomy terms created | **0** |
| PT drift | **0** (every dry-run gate reported `PT drift=0`) |
| `.env` changed | **NO** |
| Secrets exposed | **NO** |
| `plugins.json` modified | **NO** |
| Permanent release manifest modified | **NO** |
| Engine semantics altered | **NO** |
| New test failures introduced | **0** |

---

## 10. What this task did not do, by design

It did **not** install or activate either plugin in production, execute EN apply,
create EN content, create translation links, update B2 fields, or create EN taxonomy
terms.

The next separate task, after the seven `NO_REAL_PT_SOURCE` editorial decisions are
resolved and the resulting manifest/dry-run is clean, is:

**EN Translation Rollout — Phase 3: Temporary Plugin Install → Apply → Immediate
Verification → Idempotence → Gate → Plugin Removal**

### How to install the artifacts (Phase 3, by an authorised maintainer)

```bash
./scripts/build-temporary-plugins-zip.sh     # writes dist/temporary/*.zip
```

Then, in the target WordPress: **Plugins → Add New → Upload Plugin**, upload
`conexao-translation-rollout.zip`, then `conexao-en-translation.zip`, activating each
on install. Run the dry-run through the existing runner
(`scripts/run-en-translation.php --dry-run`) before any apply, and remove both plugins
when the rollout is finished.

---

## Evidence

All machine evidence: `docs/evidence/2026-09-30-temporary-en-translation-plugins/`
(see its `README.md` for the file-by-file index).

_Last verified: 2026-09-30 by the temporary EN rollout plugin packaging —
`dist/temporary/*.zip` rebuilt three times byte-identical, runtime 87/87, ZIP integrity
24/24, install smoke 26/26, newsletter 37/37, dry-run GATE PASS × 7, full suite
63/63 in-process with the only failure (3 stale `.pot`) proven pre-existing at HEAD_


unrelated plugins 0 · Flutter/mobile files 0.

Additionally proven for every shipped file: the archive root is exactly
`<plugin-slug>/`, the plugin main file is present, WordPress's own `get_plugin_data()`
parses the name and version, every shipped `.php` parses under `php -l`, and every
shipped byte is **identical to `wp-content/plugins/`** — the artifact cannot have
drifted from its source.


The maintained contract suite `test-shared-slug-newsletter.php` passes **37/37**,
including every negative guard: an unpaired duplicate on the shared slug is reported
as a duplicate, duplicate PT and duplicate EN pages are rejected, the guard is scoped
to the ONE declared slug, and the fixtures are removed afterwards leaving only the
real PT page.
