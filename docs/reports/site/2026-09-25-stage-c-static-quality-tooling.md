# Stage C — Static Quality Tooling and Development Configuration

**Stage:** C of the standardisation roadmap (`docs/engineering-standard.md`
§15; audit `docs/audit/2026-09-25-wordpress-engineering-standardisation-audit.md`
Phase 6 row C). **Depends on:** Stage B (`2026-09-25-stage-b-repository-hygiene.md`).
**Scope:** repository tooling/configuration + documentation only. **No WordPress
runtime/behaviour change** — no plugin, theme, template, CPT, taxonomy, route,
Polylang, REST, CSS, JS, database, content or production change. No
Flutter/mobile change. No CI introduced (Stage D), no test harness (Stage E),
no theme split (Stage F).
**Status:** PASS WITH LIMITATION (§12 — the limitations are the documented
legacy debt itself and its follow-ups; every gate runs and is proven to fail
on new violations).

---

## 1. Baseline (fresh counts from the checkout, not the audit)

| Measure | Value |
|---|---|
| Branch / HEAD before Stage C | `i18n` @ `284601a` ("repo hygiene: standardise .gitignore/.gitattributes/.env.example, relocate reports, drop generated work trees") — clean tree; Stage C work on branch `cline/md7p0px6` |
| Tracked files before Stage C | **602** (`git ls-files \| wc -l`) |
| Tracked PHP files before Stage C | **388** |
| `git status --short` before Stage C | clean (verified before any edit) |
| Host PHP (Stage C sandbox) | **PHP 8.5.8 (cli)** — no system PHP/Docker in the Stage C sandbox; the documented local stack is WordPress 7.0.2 / PHP 8.5 (Docker image `wordpress:latest`, audit A-05) |
| Composer | **2.10.3** (with PHP 8.5.8) |
| Pre-existing Stage C files | none — no `.editorconfig`, `composer.json`, `composer.lock`, `phpcs.xml.dist`, `phpstan.neon.dist`, `phpstan-baseline.neon`, `docs/README.md`, `scripts/lint.sh` (verified one by one; audit A-01) |

## 2. Files added / changed

**Added (12):**

| File | Purpose |
|---|---|
| `.editorconfig` | Engineering standard §1.1 verbatim (UTF-8, LF, final newline, tabs for PHP, 2-space JSON/YAML, 4-space shell/Python) |
| `composer.json` | Dev-only toolchain (standard §1.4) + `lint` / `lint:fix` / `analyse` / `check` scripts |
| `composer.lock` | Deterministic toolchain (12 packages, resolved and verified on PHP 8.5.8) |
| `phpcs.xml.dist` | WordPress-Extra + WordPress-Docs + PHPCompatibilityWP; text domains from actual source (§4) |
| `phpcs-baseline.json` | PHPCS legacy baseline: per-sniff counts (129 sniffs, 3290 errors / 2672 warnings) |
| `phpstan.neon.dist` | Level 5, `phpstan-wordpress`, theme+plugins paths, bootstrap wiring |
| `phpstan-baseline.neon` | PHPStan legacy baseline (298 level-5 errors, generated 2026-09-25) |
| `docs/dev/phpstan-bootstrap.php` | Static-analysis bootstrap: guarded constants + signature-only Polylang stubs (§5); never loaded by WordPress |
| `scripts/lint.sh` | The Stage C gate: PHP syntax sweep + PHPCS baseline check + `composer analyse` |
| `scripts/phpcs-baseline.php` | Baseline aggregator/checker (`check` / `update` modes) |
| `docs/README.md` | The Stage A documentation index deliverable (navigation only) |
| `docs/reports/site/2026-09-25-stage-c-static-quality-tooling.md` | This report |

**Changed (3, documentation only):**

| File | Change |
|---|---|
| `docs/development.md` | + "Quality tooling (Stage C)" section: composer install/lint/lint:fix/analyse/check, `./scripts/lint.sh`, baseline rules, dev-only policy, PHP floor, Stage D outlook |
| `AGENTS.md` | + 1 line: the full docs index link (`docs/README.md`) in the Documentation index section |
| `docs/reports/README.md` | + the `site/` stage-reports row (this report's location) |

`git diff --check`: clean. No tracked runtime file changed; `git diff --
wp-content/` is empty (§9).

## 3. Composer

**Dev-only policy:** every package is in `require-dev`; `vendor/` is
git-ignored (Stage B); Composer is **never deployed** (production is
WordPress.com; no runtime WordPress dependency, no autoloader in shipped
code, no `composer.json` inside plugins/theme). `composer validate`: valid;
lock in sync (re-runs are a deterministic no-op).

| Package | Constraint | Locked | Why |
|---|---|---|---|
| `dealerdirect/phpcodesniffer-composer-installer` | `^1.0` | v1.2.1 | Registers WPCS/PHPCompatibility standards paths |
| `wp-coding-standards/wpcs` | `^3.1` | 3.4.1 | WordPress-Extra / WordPress-Docs |
| `phpcompatibility/phpcompatibility-wp` | `^2.1` | 2.1.8 (→ php-compatibility 9.3.5 + paragonie 1.3.4) | PHPCompatibilityWP |
| `phpstan/phpstan` | `^1.11` | 1.12.34 | Static analysis |
| `szepeviktor/phpstan-wordpress` | `^1.3` | v1.3.5 (→ php-stubs/wordpress-stubs v6.9.4) | WordPress core stubs/constants + WP rules |
| (transitive) | — | squizlabs/php_codesniffer 3.13.6, phpcsstandards/phpcsutils 1.2.3, phpcsstandards/phpcsextra 1.5.1, symfony/polyfill-php73 v1.37.0 | PHPCS runtime + WPCS dependencies |

No PHPUnit (no runner exists yet — Stage E owns the harness), no Node/JS
tooling (§ below), no unrelated packages. The constraint set is the standard's
§1.4 verbatim; verified installable and **runnable on the actual local PHP
(8.5.8)** before committing (PHPCS 3.13.6 and PHPStan 1.12.34 both execute on
8.5.8).

**One measured deviation from the standard's template:** `analyse` uses
`--memory-limit=2G` instead of `1G`. At 1G, PHPStan's process (WP stubs are
134 k lines + 156 project files) crashes: "PHPStan process crashed because it
reached configured PHP memory limit: 1G". 2G verified sufficient (exit 0).
Documented here; a standard amendment should update §1.4.

## 4. PHPCS

**Standards enabled** (`phpcs.xml.dist`, standard §1.5):
`WordPress-Extra` + `WordPress-Docs` + `PHPCompatibilityWP`, with
`minimum_wp_version: 6.4` and `testVersion: 8.0-` (§ "PHP version policy"
below). PHPCompatibility findings: **0** — the codebase contains no
constructs incompatible with the declared 8.0 floor.

**Scanned:** `wp-content/themes/conexao-br-irlanda` + `wp-content/plugins`
— **156 PHP files** scanned (151 with violations, 5 clean).

**Excluded (targeted, architectural reasons — no broad disabling):**

| Exclusion | Reason |
|---|---|
| `*/tests/*`, `*/vendor/*`, `*/node_modules/*` | Standard's template; tests are outside the production coding-standard gate until the Stage E harness defines their gate; `vendor/`/`node_modules/` are generated |
| `wp-content/plugins/conexao-guide-translation/includes/body-*.php` | Machine-generated Stage 9 EN translation bodies inside the **retired rollout plugin** (standard §1.5/§1.6 template); generated content data, not a maintained style target |

**Text domains** — built from the repository's actual source, not copied from
the old report: the 8 domains with verified gettext usage are
`conexao-br-irlanda` (theme, 583 calls; also used by data-model),
`conexao-data-model`, `conexao-content`, `conexao-admin-ux`,
`conexao-event-runtime`, `conexao-event-importer` (467 calls),
`conexao-leisure-migration`, `conexao-sponsor-migration`.
**Excluded:** the five translation-rollout plugins
(`conexao-page/blog/job/leisure/guide-translation`) — they contain **zero**
gettext calls (verified by grep over every plugin) and are retired rollout
tooling (standard §1.8 registry class `rollout`), not maintained runtime text
domains.

**Baseline mechanism** (`phpcs-baseline.json` + `scripts/phpcs-baseline.php` +
gate in `scripts/lint.sh`):
- PHPCS 3.x has no native baseline (PHPCS 4 does, but WPCS 3.x pins PHPCS
  3.13), so Stage C records the legacy debt as **per-sniff ERROR/WARNING
  counts** (129 sniff classes).
- `check` fails on any sniff whose count **grew** and on any sniff **not in**
  the baseline — that is how a new violation is visible even while legacy
  debt exists.
- `update` regenerates the file **only** after deliberately paying down debt
  (shrinking is the expected direction; the check prints a reminder when it
  detects shrinkage).
- Semantics: the aggregate is per sniff, not per file — moving a violation
  between files without changing counts is net-zero debt and passes (robust
  to the Stage F theme split's file moves).
- Why each class of baseline violation exists: it is the measured pre-Stage-C
  style debt of hand-written code (docblocks, escaping, alignment, naming,
  `Yoda`, `Generic`/`Squiz` checks) concentrated in the biggest/oldest files
  — nothing was reformatted in Stage C (no mass PHPCBF run).

**Baseline captured (2026-09-25):** 3290 errors + 2672 warnings in 151 files;
4901 auto-fixable; 129 sniff classes. **New violations introduced by Stage C:
0** (the gate is green on the committed tree).

**PHP version policy (Phase 9):** the standard's floor is `8.0`
(`testVersion: 8.0-`). Repository PHP-version claims conflict (documented,
not changed): the theme `style.css` declares `Requires PHP: 7.4`; plugins
declare 7.4 except `conexao-admin-ux` (8.0); the runtime is PHP 8.5 (local +
production). Stage C did **not** touch any runtime header (that is
later-stage work; changing `Requires PHP` claims alters production
compatibility and needs its own change). The toolchain runs on PHP ≥ 8.0
(verified on 8.5.8) and `composer.lock` installs across that range.

## 5. PHPStan

| Measure | Value |
|---|---|
| Version | 1.12.34 (`phpstan/phpstan ^1.11`, the standard's §1.4 constraint) |
| Integration | `szepeviktor/phpstan-wordpress` v1.3.5 (extension.neon: WP core stubs, ABSPATH/core constants, WP rules) + `php-stubs/wordpress-stubs` v6.9.4 |
| Level | **5** (standard §1.6 proposal — verified workable: not lowered to hide problems, not raised into a noisy migration) |
| Files analysed | 156 (theme + plugins; tests and generated body files excluded per the standard's excludePaths) |
| Legacy baseline | `phpstan-baseline.neon`, **298 errors**, generated 2026-09-25 |
| Non-baseline errors | **0** (`composer analyse` exit 0, "No errors") |
| New errors introduced by Stage C | **0** (the reverse is proven in §7: a type defect fails the gate) |

**Baseline policy (must not grow):** the baseline records debt that existed
before Stage C; a *new* error (new file/line/message) is not in it and fails
`composer analyse` / `./scripts/lint.sh`. Regeneration
(`vendor/bin/phpstan analyse --generate-baseline`) is a deliberate action
taken **only** after fixing legacy errors. Known composition of the 298:
~247 real type-level findings (always-false checks, docblock/return
mismatches, unread properties) + 51 `Function conexao_guide_en_* not found`
(calls into the **excluded generated body files** — a closed set: the guide
rollout is retired, so it cannot grow; it shrinks to zero the day the retired
plugin is removed).

**Bootstrap (`docs/dev/phpstan-bootstrap.php`)** — inspected first, stubbed
only what the analyser genuinely cannot discover:

- WordPress core constants/functions are already provided by
  `phpstan-wordpress`'s own bootstrap (ABSPATH etc.) — not duplicated; a
  guarded fallback `define( 'ABSPATH' )` remains for robustness.
- Repository constants whose runtime values are dynamic
  (`get_template_directory_uri()`, `plugin_dir_url()`, …), which PHPStan
  cannot infer cross-file: `CONEXAO_THEME_DIR/URI`,
  `CONEXAO_ADMIN_UX_DIR/URL`, `CONEXAO_LEISURE_TRANSLATION_DIR` — each with
  `defined()` guards (inert when the real definitions load).
- The **Polylang API** (`pll_*` functions + `PLL()`/`PLL_Model`/…): Polylang
  is a third-party plugin deliberately not committed to this repo, so the
  *signatures used by this codebase* are declared as capability-guarded
  stubs with `@param`/`@return` metadata and no logic. Without them every
  `pll_*` call is an unfixable "not found" error and the gate is unusable.
- The file is clearly marked development/static-analysis only, is never
  loaded by WordPress, and real symbols always win over its guards.

## 6. Commands — real results (Phases 15, 18–20)

| Command | Real result on the committed tree |
|---|---|
| `composer install` | 12 packages installed from lock (dealerdirect v1.2.1, wpcs 3.4.1, phpcompatibility-wp 2.1.8, php-compatibility 9.3.5, paragonie 1.3.4, phpstan 1.12.34, phpstan-wordpress v1.3.5, wordpress-stubs v6.9.4, phpcsutils 1.2.3, phpcsextra 1.5.1, php_codesniffer 3.13.6, polyfill-php73 v1.37.0); re-runs are a deterministic no-op; `composer validate`: valid |
| `composer lint` | **exit 2** — PHPCS reports the legacy debt (the raw debt view); 3290 errors + 2672 warnings in 151 files; **by design** until the debt is paid down (§10) |
| `composer analyse` | **exit 0** — " [OK] No errors" (legacy errors isolated in `phpstan-baseline.neon`) |
| `composer check` | **exit 2** — runs `@lint` then `@analyse`; fails at the lint step while legacy debt exists (documented; green when the debt reaches 0) |
| `./scripts/lint.sh` | **exit 0** — `[1/3]` syntax sweep 390 files OK; `[2/3]` "OK: no new PHPCS violations against the baseline" (3290/2672 vs baseline 3290/2672); `[3/3]` composer analyse " [OK] No errors"; final `lint: OK` |
| `php -l` sweep (Phase 18) | **390 files** (388 tracked + the 2 new Stage C PHP files), **0 parse errors**; pre-existing parse errors: 0; new parse errors: 0 |
| `git diff --check` | clean (no whitespace errors) |

`./scripts/lint.sh` details: discovers PHP files deterministically
(`git ls-files --cached --others --exclude-standard -- '*.php'` — tracked +
untracked-not-ignored, so `vendor/` is never scanned), runs `php -l` in
parallel (8), then PHPCS with `--report=json` compared against
`phpcs-baseline.json` by `scripts/phpcs-baseline.php`, then `composer
analyse`. Non-zero exit on any mandatory check; it does **not** run the
behavioural suites (Stage E owns the harness).

## 7. Failure proof (Phase 16)

A temporary fixture `wp-content/plugins/tmp-stagec-gate-proof.php` (never
committed; removed immediately after each proof; the gate re-verified green
afterwards) proved the gate is not decorative:

| Test | Defect (temporary) | Result |
|---|---|---|
| **A — syntax** | `this_is_invalid(` (unterminated call) | [1/3] FAILED: "Errors parsing wp-content/plugins/tmp-stagec-gate-proof.php"; [2/3] FAILED: new sniff `Generic.PHP.Syntax.PHPSyntax` + `Squiz.Commenting.FileComment.WrongStyle 17→18`; [3/3] PHPStan crashed (parse error); overall `lint: FAILED` (exit 1) |
| **B — style** | unescaped `echo $x;` (valid PHP) | [1/3] OK; [2/3] FAILED precisely: `NEW VIOLATIONS - per-sniff counts grew: WordPress.Security.EscapeOutput.OutputNotEscaped 31/0 -> 32/0` (+ `MissingPackageTag 6→7`, missing function docblock `140→141`); [3/3] OK (type-clean); overall FAILED (exit 1) |
| **C — type** | `is_wp_error( array( 1, 2 ) )` (style-clean) | [1/3] OK; [2/3] OK (no new style violations); [3/3] **PHPStan " [ERROR] Found 2 errors"** — "Call to function is_wp_error() with array{1, 2} will always evaluate to false" + "is_wp_error(array<int, int>) will always evaluate to false" at line 20; overall FAILED (exit 1) |

Precision bonus: during Test C an extra trailing blank line alone was caught
(`PSR2.Files.EndFileNewline.TooMany 9/0 -> 10/0`). After removing the fixture,
`./scripts/lint.sh` returned to **exit 0** — the proofs are fully reversible
and the working tree is clean of the fixture.

## 8. Documentation added / updated

| Document | Change |
|---|---|
| `docs/README.md` | **New** — the single documentation index (the Stage A leftover; navigation only, no duplicated standards). All link targets verified to exist |
| `docs/development.md` | **New "Quality tooling (Stage C)" section** — composer install/lint/lint:fix/analyse/check, `./scripts/lint.sh`, both baselines and their must-not-grow rules, dev-only policy, PHP floor, Stage D outlook, bootstrap warning |
| `AGENTS.md` | +1 line — full docs index link (standard §1.9) |
| `docs/reports/README.md` | + the `site/` stage-reports row (this report's location) |
| `docs/reports/site/2026-09-25-stage-c-static-quality-tooling.md` | This report |

The authoritative rules remain in `docs/engineering-standard.md` — no
competing coding-standard document was created. Link validation (Phase 22):
all `docs/README.md` targets exist (the only pre-creation gap,
`reports/site/`, is this report's directory); no stale `.local/php`
references (its mention in `docs/development.md` is the accurate Stage B
historical note); no outdated Composer assumptions elsewhere.

## 9. Runtime safety (Phases 17, 23, 24)

Explicitly confirmed — `git status --short` / `git diff --name-only` /
`git diff -- wp-content/`:

- **No theme/plugin runtime modifications** — `git diff -- wp-content/` is
  empty; zero runtime PHP, template, CSS or JS files changed.
- **No content changes** — no content, meta, terms or media touched.
- **No database changes** — nothing connected to a database; no migrations.
- **No Polylang changes** — no CPT/taxonomy declarations, no `pll_*` runtime
  behaviour, no language settings touched (Polylang stubs live only in the
  dev-only analyser bootstrap).
- **No REST changes** — no REST contract or route file touched.
- **No production changes** — nothing deployed; production is WordPress.com
  and no deploy/build ZIP ran.
- **No Flutter/mobile changes** — no files outside this repository's
  WordPress scope were touched.
- Phase 23 (regression safety): with zero runtime-source changes, the
  existing WordPress in-process suites are unaffected by construction; they
  require a running WordPress (Docker) and are Stage E's harness problem.
  Stage C's evidence is the static gate output above (all real runs).
- Phase 24 (git diff review): tracked changes are
  `AGENTS.md` (+1), `docs/development.md` (+54), `docs/reports/README.md`
  (+6) — documentation only; all added files reviewed one by one; no
  broad reformatting happened (no `composer lint:fix` run was ever executed
  against runtime code).

## 10. Known limitations

1. **`composer lint` / `composer check` are red by design** while the legacy
   PHPCS debt (3290 errors + 2672 warnings in 151 files) exists. They are the
   *raw debt view*; `./scripts/lint.sh` is the Stage C gate (green today,
   fails on new violations). Paying down the debt (per-file `composer
   lint:fix` + manual fixes, then a deliberate baseline `update`) is
   later-stage maintenance, deliberately not done here.
2. **PHPCS baseline granularity**: counts are per sniff, so net-zero moves
   within a sniff (one violation fixed + one added elsewhere) pass. This is
   the documented "debt must not increase" semantic; per-file baselines
   would break on the Stage F file moves.
3. **PHPStan 1.12.x prints an "old version" nag** on every run (2.x is
   current). The standard pins `^1.11` and Stage C avoids the unnecessary
   major upgrade (phpstan-wordpress ^2 / stubs refresh is a deliberate
   later-stage decision, bundled with the standard amendment).
4. **PHP floor discrepancy is documented, not fixed**: runtime headers
   declare 7.4/8.0 while the standard's floor is 8.0 and the runtime is
   8.5. Aligning headers is later-stage runtime work.
5. **wordpress-stubs v6.9.4** (WP 6.9) vs production WP 7.0.2 — the
   `phpstan-wordpress ^1.3` line pins it; noted for the future upgrade.
6. **`.editorconfig` tab default vs theme CSS**: verified reality is mixed —
   theme `assets/css/*.css` is 2-space indented (the bulk of the CSS) while
   the JS (5 files), plugin `admin.css` (4 files) and 2 theme CSS files use
   tabs. The standard §1.1 is silent on CSS; its template was kept verbatim
   (tabs via `[*]`) and **no reformatting was performed**. A future standard
   amendment should decide the CSS rule (Stage C records the facts).
7. **Toolchain requires a host PHP ≥ 8.0** (verified on 8.5.8). The Stage C
   sandbox had no system PHP/Docker, so a static PHP 8.5.8 CLI (outside the
   repository) ran the toolchain; on a normal dev box, any PHP ≥ 8.0 with
   Composer 2 works the same.
8. **Composer network flakiness through the egress proxy** (metadata/dist
   downloads occasionally time out) was worked around by retries + the local
   metadata cache; the committed `composer.lock` makes installs deterministic
   once cached.

## 11. Next stage

**Stage D — CI** (engineering standard §1.7, roadmap §15: D depends on C):
`.github/workflows/ci.yml` runs `composer install`, the `php -l` sweep,
`composer lint` + `composer analyse` (or `./scripts/lint.sh` — see §10.1),
and `shellcheck scripts/*.sh`, on every push/PR; the optional Docker
integration job waits for Stage E's harness. **Not implemented in Stage C**
by scope.

## 12. Final status

**PASS WITH LIMITATION**

Every gate exists, runs, and is proven to fail on new syntax/style/type
defects (§7); `./scripts/lint.sh`, `composer analyse`, `composer install`
are green on the committed tree; `composer lint` / `composer check` are red
only because they expose the measured pre-existing debt (§10.1), which is
exactly what Stage C was required to record rather than fix.

_Last verified: 2026-09-25 by Stage C (static quality tooling)_
