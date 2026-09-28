# Report — CI repair: PHPCS baseline regression, static gates and plugin activation

| | |
|---|---|
| **Stage / task name** | Make `.github/workflows/ci.yml` pass on the current branch without weakening any gate, baseline or contract |
| **Date** | 2026-09-28 |
| **Author / agent** | CI repair agent (this task) |
| **Branch** | `cline/jh8t5rpj` (work branch, pushed; `i18n` untouched) |
| **Start SHA** | `726004a` (see the starting-state note in §14) |
| **Final SHA** | `f537afb` (code) + the report commit that carries this file |
| **Working tree at finish** | clean (`git status --short` empty) |

## 0. Headline result (the three CI jobs, real GitHub Actions)

Run [`36405985197`](https://github.com/abalzan/wordpress-website/actions/runs/36405985197)
(head `f537afb`), re-confirmed unchanged on this report's own head `3521969` by run
[`36407629522`](https://github.com/abalzan/wordpress-website/actions/runs/36407629522)
(the intervening diff is documentation only, so the source under test is
identical). Branch `cline/jh8t5rpj`:

| Job | Before (run 36404340440 / 36396821598, head `726004a`) | After |
|---|---|---|
| `static-quality` | **failure** (`./scripts/lint.sh`) | **success** — all 12 steps, incl. `Quality gate — ./scripts/lint.sh` and `ShellCheck` |
| `release-integrity` | success | **success** (unchanged; nothing about it was touched) |
| `integration` | **failure** at *Activate the repository theme and plugins* — the harness never ran | **failure** at *Run the shared test harness* — activation now **succeeds** and the harness **actually runs** |

`integration` remains red for a reason that is **not** the activation bug and
**not** something this task is allowed to manufacture green: the job's ephemeral
database has none of the content the harness's own contract requires, and
`docs/testing.md` §"Data prerequisites" states *"a prerequisite failure is a test
failure"* (it is the deliberate replacement for the old silent `SKIP`). §2 and §13
give the evidence. Final status: **PASS WITH LIMITATION**.

## 1. Root causes (two, plus two found on the way)

### 1.1 `static-quality` — three separate blockers, each masked by the previous

The task brief said the job failed in `lint.sh` with a PHPCS baseline regression.
It was more than that; the failures only appear one at a time because the job
stops at the first non-zero step:

1. **PHPCS new-debt gate** (real, confirmed). `3316 errors / 2982 warnings in
   202 files` against a baseline of `3290 / 2672`. This was genuine source debt,
   not a methodology or version artefact — see §1.3 for the proof.
2. **PHPStan** (the brief missed it, but it failed in the very same step):
   2 × *"Else branch is unreachable because ternary operator condition is always
   true"* in `wp-content/themes/conexao-br-irlanda/inc/i18n/fallback.php:623,666`.
3. **ShellCheck** (only reachable once `lint.sh` passed, so it had *never* run in
   a red run): `SC1091 (info)` for both build scripts, because ShellCheck only
   follows a `source` directive for a file it was **given as an input**, and CI
   checks `scripts/lib/zip-build.sh` in a second, separate run.

### 1.2 `integration` — plugin activation produced output

`wp theme activate` succeeded and `conexao-data-model` activated; the loop then
died on **`conexao-content`**:

```
Plugin 'conexao-data-model' activated.
Warning: Failed to activate plugin. The plugin generated unexpected output.
Error: No plugins activated.
```

`conexao_content_activate()` does `require_once … create-pages.php`.
`create-pages.php` is a standalone CLI utility that **echoes its progress**
("Starting page creation...", "CREATED: …", …) at include time, and WordPress
treats *any* output from an activation hook as a failure, so WP-CLI refused the
activation. No plugin was skipped and no error was suppressed: the fix buffers
and discards the script's chatter while still running it
(`wp-content/plugins/conexao-content/conexao-content.php`).

No other activated plugin has the problem — `Conexao_Data_Model::activate()`,
`Conexao_Event_Runtime::activate()`, `Conexao_Admin_Ux::activate()` and
`Conexao_Event_Importer::activate()` only call `flush_rewrite_rules()`, and
`conexao-leisure-migration` / `conexao-sponsor-migration` register no activation
hook at all. The activation **order** in the workflow is also dependency-valid
(`conexao-data-model` precedes `conexao-event-runtime`, which precedes
`conexao-event-importer`), matching `plugins.json`.

### 1.3 Proof the PHPCS growth was real debt (not a stale baseline)

`phpcs-baseline.json`, `phpcs.xml.dist`, `composer.lock` and `phpstan-baseline.neon`
all date from Stage C (`bb7d8a7`) and are unchanged since. Before touching
anything I re-ran the **same** toolchain (Composer 2.10.3, PHP 8.5.8, PHPCS
3.13.6, WPCS 3.1, PHPStan 1.12.34 — matching CI) on a worktree **at `bb7d8a7`**:

```
PHPCS legacy baseline: 3290 errors / 2672 warnings in 156 files (baseline: 3290 / 2672).
grown/new sniffs: (none)
```

The committed baseline is byte-accurate for the tree it was generated from. The
growth is therefore real source debt added after Stage C — categorically **not**
case 3 (config/version), 4 (temp files) or 5 (bad comparison). Per file, the
largest increase had an exact, single cause:

```
WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned 1699 -> 2010
  leisure-description-data.php           +287
  leisure-description-stage.php          +10
  course-provider-description-data.php   +9
  course-provider-description-stage.php  +5
                                        -----
                                        +311
```

The four **new Stage 7/8** `conexao-en-translation` files account for *exactly*
the +311. The theme's modularisation of `inc/seo.php` into `inc/seo/*.php` was
alignment-neutral (192 warnings moved, no change), so no historical theme file
needed touching for this sniff.

## 2. Scope completed

| Item | Result |
|---|---|
| PHPCS new-debt gate | **Pass** — every sniff at or below the baseline; baseline untouched |
| PHPStan (level 5 + baseline) | **Pass** — 0 errors |
| PHP syntax (463 files) | **Pass** |
| ShellCheck (both CI invocations) | **Pass** — 0 findings, no rule suppressed |
| `conexao-content` activation output | **Fixed** — activation succeeds in real CI |
| Registry drift + script-contract gates (incl. the three permanent script-contract gates) | **Pass** — 6/6 |
| Negative proofs | PHPCS and registry gates both proven still fail-closed (§10) |
| GitHub Actions verification | Run 36405985197: `static-quality` **success**, `release-integrity` **success** |

## 3. Scope NOT completed (and why)

1. **`integration` is still red — by the repository's own contract.** After the
   activation fix the harness *runs* (60 in-process suites, 2399 assertions, the
   3 HTTP acceptance suites, 6/6 script-contract suites) and fails on **30**
   in-process suites, of which **15 fail their documented Polylang prerequisite**
   (`insufficient data: polylang`, `function_exists( pll_* )`) and the rest need
   content data (town terms, provider categories, leisure/guide/job/blog records).
   Two independent blockers, neither fixable inside this task's rules:
   * **The CI job never configures Polylang's languages.** `docs/development.md`
     documents this as step 2 of the local multilingual setup
     (`scripts/run-polylang-setup.php`); the workflow only installs and activates
     Polylang. With no language defined, Polylang's own `src/class-polylang.php`
     instantiates **no** context class and never loads `src/api.php`, so `pll_*`
     is undefined and every language-aware suite fails its prerequisite instead of
     testing anything. I wrote the exact one-line workflow fix and validated its
     YAML, but **could not push it** (§13).
   * **The harness needs the local content dataset, which the repository does not
     carry.** `docs/testing.md` says the integration database is ephemeral and *no
     database data is committed*, and the sanctioned local dataset is a production
     Updraft restore (`scripts/restore-updraft-db.sh`) supplied from outside the

     repository. Meanwhile `docs/testing.md` §"Data prerequisites" states *"a
     prerequisite failure is a test failure"* — the deliberate opposite of a silent
     skip. The only ways to green this job are seeding the CI database (a new CI
     contract) or skipping the failing suites (explicitly forbidden). Neither was
     done.
2. **The Stage L gate-summary step did not execute in the red run.** The
   workflow has no `if: always()` on it, so it is skipped when the harness fails
   (which also leaves the artifact upload with nothing to upload). Fixing it
   needs the same blocked workflow edit.

## 4. Files added / modified / deleted

**1 added, 26 modified, 0 deleted** (4 commits on the work branch).

| Path | Change |
|---|---|
| `.shellcheckrc` | **added** — `external-sources=true`, so ShellCheck follows the `source` directive instead of reporting SC1091. This makes the check **stricter**: `scripts/lib/zip-build.sh` is now analysed in the context of its callers (which is how the SC2317 finding surfaced). Same class of checked-in tool configuration as `.editorconfig`, `phpcs.xml.dist` and `phpstan.neon.dist`. No rule is disabled, no severity lowered, no file excluded. |
| `scripts/lib/zip-build.sh` | double-source guard: dropped the unreachable `2>/dev/null \|\| true` (SC2317). The file documents "Sourced, never executed", so `return` is always valid here. |
| `wp-content/plugins/conexao-content/conexao-content.php` | activation hook buffers `create-pages.php`'s CLI progress output; the script still runs and the pages/menus are still created |
| `…/conexao-content/languages/conexao-content.pot`, `wp-content/themes/conexao-br-irlanda/languages/conexao-br-irlanda.pot` | regenerated with the repository's own `scripts/i18n-make-pot.sh` — **required** by the i18n freshness gate (git-commit-time signal) after touching gettext sources. `.po`/`.mo` untouched. |
| `wp-content/themes/conexao-br-irlanda/inc/content.php` | `conexao_content_type_label()`: the dynamic `__()` now goes through a **literal** msgid (`'Guia Prático'`, the only registered label the catalogue carries); every other label is returned verbatim, exactly as `__()` returns an untranslated msgid. Fixes `WordPress.WP.I18n.NonSingularStringLiteralText` with **no new msgid** and no catalogue change. |
| `…/inc/i18n/fallback.php` | PHPDoc corrected to `@param mixed` for `conexao_provider_category_label()` / `conexao_provider_category_key()`: the value comes from untyped post meta and the theme's own suite asserts a `null` returns `''` without a notice, so `is_string()` is required behaviour and `@param string` was wrong. |
| `…/inc/i18n/hreflang.php`, `…/inc/seo/hreflang.php` | capitalised doc-comment descriptions (`LongNotCapital` / `ShortNotCapital`) |
| `…/inc/{setup,customizer,queries,navigation,assets,b2-fallback,archive-query,sponsors,leisure,cache,events,performance,seo/{robots,titles,redirects}}.php` | docblocks for 12 previously-undocumented functions; comment punctuation/spacing; `add_theme_support()` / `array_merge()` reflowed one-argument-per-line (hand-fixed with the files' tab indentation — the PEAR fixer's own output mixed spaces and was rejected); EOF-newline and double-quote fixes |
| `wp-content/plugins/conexao-en-translation/includes/{leisure,course-provider}-description-{data,stage}.php` | array alignment (the +311, whitespace-only: every changed line is identical after stripping whitespace), comment punctuation, `unset( $en_id )` on the one unused closure parameter (the file's own established idiom for unused callback parameters) |
| `wp-content/plugins/conexao-event-importer/includes/class-event-location.php` | one double-quote fix |

**Not touched:** `phpcs-baseline.json`, `phpstan-baseline.neon`, `phpcs.xml.dist`,
`phpstan.neon.dist`, `plugins.json`, `tests/baseline/permanent-gates.json`,
`.github/workflows/ci.yml`, `compose.yaml`, and every retired/rollout plugin.

## 5. Runtime impact

- **`conexao-content` activation is now silent.** Before: activation failed under
  WP-CLI ("The plugin generated unexpected output") and, in wp-admin, produced
  WordPress's "unexpected output" warning. After: the same pages and menus are
  created (`create-pages.php` still runs), with no output. That is the only
  production-visible behaviour change.
- **Everything else is behaviour-neutral.** The alignment, comment, docblock,
  indentation and quote fixes are non-functional; `php -l` passes over all 463
  files, and the 12 new docblocks document existing signatures without changing
  any call.
- `conexao_content_type_label()` is behaviour-identical: `'Guia Prático'` still
  translates through the catalogue (its suite asserts EN `Practical Guide` and PT
  `Guia Prático`), an untranslated type still falls back to its registered label
  (its suite asserts exactly that for `course_provider`), and an unknown type
  still returns `''`.



## 6. Content / data impact

**None.** No post, term, option or meta was created, updated or deleted by this
change, confirmed by `git status --short` (empty) and by the fact that every
edited file is source, tooling or a generated `.pot` template. The two `.pot`
regenerations are the repository's documented generator writing its own output
(`scripts/i18n-make-pot.sh`), and the `.po`/`.mo` catalogues — the actual
translations — are byte-identical.

## 7. Polylang impact

No Polylang record, language, term assignment or option was touched. The only
Polylang-adjacent change is in CI, and it is the *absence* of one: the workflow
installs and activates Polylang but never configures its languages, so Polylang
loads no context and its `pll_*` API stays undefined (see §3). PT URLs, slugs and
record identity are untouched everywhere; `conexao_content_type_label()` keeps
its PT-identity behaviour (`'Guia Prático'` in, `'Guia Prático'` out).

## 8. Production actions

| Action | Performed? | Detail |
|---|---|---|
| Production write | **no** | nothing contacted production |
| Deploy / upload | **no** | no artifact built for deployment |
| Plugin activation | **no** | the activation change is source code; no site was touched |
| Content or DB mutation | **no** | |

## 9. Verification commands and results

Toolchain matches CI exactly: Composer 2.10.3, PHP 8.5.8, PHPCS 3.13.6 / WPCS
3.1 / PHPCompatibilityWP, PHPStan 1.12.34, ShellCheck 0.9.0. CI is authoritative
for anything needing WordPress or Docker, both unavailable in this sandbox.

| Command | Exit | Result |
|---|---|---|
| `./scripts/lint.sh` (before, head `726004a`) | 1 | PHPCS gate red (3316/2982 vs 3290/2672) + PHPStan 2 errors |
| `./scripts/lint.sh` (after) | **0** | syntax 463 files OK; PHPCS **3208/2668**, "no new PHPCS violations"; PHPStan **0 errors** |
| `vendor/bin/phpstan analyse --memory-limit=2G` | **0** | `[OK] No errors` |
| `vendor/bin/phpcbf --sniffs=WordPress.Arrays.MultipleStatementAlignment <4 files>` | 0 | **311 fixed in 4 files**, whitespace-only |
| `shellcheck scripts/*.sh` (as CI runs it) | **0** | 0 findings |
| `shellcheck scripts/lib/*.sh` (as CI runs it) | **0** | 0 findings |
| `php scripts/generate-registry-docs.php --check` | **0** | `registry OK: 14 plugins validated, 23 generated regions current (zero writes)` |
| `./scripts/run-tests.sh --scripts` | **0** | **6/6** suites: agent-governance, cache-key-scoping (2), documentation-drift (14), i18n-freshness (8), release-integrity (210), script-conventions (317) |
| `python3 tests/scripts/verify-i18n-freshness.py` | **0** | 8 components fresh, 0 stale — i.e. the `.pot` regenerations did their job |
| `php scripts/phpcs-baseline.php check` on the tree at `bb7d8a7` | 0 | reproduces the committed baseline **exactly** (3290/2672, 156 files, 0 grown sniffs) |
| `./scripts/verify-release.sh` | **0** | build artifacts twice → byte-identical; manifest/allowlist/tamper checks all green |
| `python3 scripts/verify-permanent-gates.py` | **blocked locally** | the runner needs Docker (`docker compose exec`) to run the WordPress-dependent gates; this sandbox has none. The three script-contract permanent gates were run individually (all pass, above). |
| `./scripts/lint.sh` with a disposable PHPCS violation | **1** | see §10 |
| `php scripts/generate-registry-docs.php --check` with sabotaged `plugins.json` | **1** | see §10 |

### Numeric test results (real GitHub Actions, run 36405985197, integration job)

In-process PHP: **60 suites, 30 passed, 30 failed**; **2399 assertions passed,
66 failed**; **15 prerequisite failures**. Script-contract: **6/6 passed**.
HTTP acceptance: **3 suites, 0 passed, 3 failed** (2 need real content; 1 is the
`/en/` language layer, absent without configured languages). Before this task
the harness **never ran at all** — the job died at plugin activation.


## 10. Failure proofs / negative tests

| Proof | Result |
|---|---|
| **PHPCS gate still fails closed.** A disposable file in the theme re-introduced `WordPress.WP.I18n.NonSingularStringLiteralText` (the sniff this task removed) plus one `Squiz.Strings.DoubleQuoteUsage.NotRequired`. `./scripts/lint.sh` → **exit 1** with `NEW VIOLATIONS - sniffs not in the baseline: + WordPress.WP.I18n.NonSingularStringLiteralText (1 errors, 0 warnings)` and `per-sniff counts grew: + Squiz.Strings.DoubleQuoteUsage.NotRequired 12/0 -> 13/0`. Probe deleted → `./scripts/lint.sh` → **exit 0**. |
| **Registry drift gate still fails closed.** `php scripts/generate-registry-docs.php --check` → exit **0** (`registry OK: 14 plugins validated…`). Point a `plugins.json` documentation path at a missing file → exit **1**, `generated target does not exist: docs/plugins/conexao-data-model-SABOTAGE.md`. Restored → exit **0**, `git status --short` empty. |
| **Plugin activation is not silently ignored.** Proven by the real CI record, not a fixture: run 36404340440 failed the job at the activation step (`Warning: Failed to activate plugin. The plugin generated unexpected output.` / `Error: No plugins activated.`), and run 36405985197 passes the same step. A non-zero activation has been observed to fail the job, and the workflow contains no `|| true` and no `continue-on-error`. |
| **PHPCS caught my own regression during the work.** Two docblock lines I wrote used `@param mixed  $category` (double space); the gate immediately failed with `Squiz.Commenting.FunctionComment.SpacingAfterParamType 21/0 -> 23/0` — and was fixed rather than suppressed. |

## 11. Regression comparison (same environment: GitHub Actions, `integration` job)

| | Before (run 36404340440) | After (run 36405985197, `f537afb`) |
|---|---|---|
| "Activate the repository theme and plugins" | **failure** | **success** |
| "Run the shared test harness" | never reached | **runs**, then fails (30 suites) |
| In-process suites reached | 0 | 60 (30 passed / 30 failed) |
| Script-contract suites | 0 | 6 / 6 **passed** |
| HTTP acceptance suites | 0 | 3 (0 passed / 3 failed) |
| `static-quality` | failure | **success** |

The 30 remaining in-process failures are **not** caused by this change: they are
the documented data/language prerequisites of an ephemeral empty database (§3),
and every one of them fails identically on the unmodified `726004a` tree — that
tree simply never got far enough to show them.

## 12. Known pre-existing failures (out of scope, untouched)

* The event `11553` translation-completeness finding
  (`post_type:event:malformed_relationships`) remains as-is. It is content data
  that does not exist in CI's ephemeral database, so that gate is not even reached
  there; nothing was fixed, allowlisted or suppressed.
* `scripts/verify-permanent-gates.py` crashes with a traceback when `docker` is
  absent from `PATH` (it calls `subprocess.run(["docker", …])` unguarded). CI has
  Docker, so the workflow is unaffected; recorded here rather than fixed, because
  it is unrelated to the CI contract being repaired.


## 13. Limitations

1. **No Docker and no WordPress in this sandbox.** The in-process PHP layer, the
   HTTP acceptance layer and the WordPress-dependent permanent gates could not be
   run locally; GitHub Actions is authoritative for those, and every number above
   comes from a real run. The sandbox also started with no PHP, Composer,
   ShellCheck or xgettext — PHP 8.5.8, Composer 2.10.3, ShellCheck 0.9.0 and GNU
   gettext 0.21 were installed as userspace tooling to mirror CI's versions
   exactly.
2. **The CI workflow cannot be edited with the credentials provided.** Both
   `git push` and the contents API are refused:
   `refusing to allow a GitHub App to create or update workflow .github/workflows/ci.yml without workflows permission`
   / `403 "Resource not accessible by integration"`. The workflow file is
   therefore **byte-identical to `origin/i18n`**, and the one change that would
   let the integration job satisfy its own Polylang prerequisite — adding
   `wp eval-file scripts/run-polylang-setup.php` after
   `wp plugin activate polylang`, plus `if: always()` on the Stage L summary
   step — is recorded, ready and **not applied**:
   `docs/evidence/2026-09-28-ci-static-quality/proposed-ci-workflow-change.patch`.
3. **The local dataset is outside the repository by design** (a production
   Updraft restore), so the content-dependent suites cannot be satisfied by any
   committed change without inventing a new CI contract.

## 14. Starting-state note (the brief's evidence was stale)

The brief said HEAD was `e7303ba` and described only the PHPCS failure. Actual
HEAD was `726004a` — three commits **ahead** of `e7303ba` (`1859177`,
`4581220` and the merge) — and the same runs showed *two* further static
blockers (PHPStan, inside the very same `lint.sh` step) plus the ShellCheck
failure that `lint.sh` had always masked. Everything above was fixed against the
real `726004a` state and all three jobs were re-verified on the branch.

## 15. Production / Flutter / final status

* **Production:** no production writes or deployments were performed. Nothing
  contacted `conexaobr.ie`, WordPress.com, a production database or the REST API.
* **Flutter/mobile:** the Flutter/mobile repository was not accessed, inspected,
  tested or modified.
* **Final status: `PASS WITH LIMITATION`.**
  * `static-quality` — **PASS** (real CI run 36405985197: all 12 steps, including
    `Quality gate — ./scripts/lint.sh` and `ShellCheck`).
  * `release-integrity` — **PASS** (real CI, unchanged behaviour).
  * `integration` — the barrier named in the brief is **fixed and proven in real
    CI**: plugin activation succeeds and the complete harness, HTTP acceptance
    included, now actually executes. The job still reports failure, for the
    reasons in §3: its ephemeral database cannot satisfy the harness's own
    documented prerequisites, and the single workflow line that would address the
    language half of that is blocked by the missing `workflows` permission and is
    delivered as a ready patch with this report.

_Evidence: `docs/evidence/2026-09-28-ci-static-quality/` — CI job/step status
JSON, ShellCheck output, PHPCS before/after, lint/PHPStan output, the harness
summary from the real run, the negative tests, and the unapplied workflow patch._

