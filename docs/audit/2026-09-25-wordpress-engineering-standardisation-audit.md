# WordPress Engineering Standardisation Audit — 2026-09-25

| | |
|---|---|
| **Repository** | `abalzan/wordpress-website` |
| **Branch / HEAD at audit time** | `i18n` / `8994ca8` |
| **Production** | https://conexaobr.ie (WordPress.com, no SSH/CLI) |
| **Local** | http://localhost:8080 (Docker: WordPress + MySQL) |
| **Scope** | WordPress website only: theme, plugins, scripts, docs, Docker, build/deploy, content/data tooling, AI-agent configuration |
| **Out of scope** | Flutter / mobile app / mobile tests / mobile REST clients (explicitly untouched) |
| **Stage** | **AUDIT + PROPOSAL ONLY.** No PHP, template, CSS, JS, plugin, file-name, directory, database, URL, Polylang setting, REST contract or deployment setting was modified. |
| **Artifacts added by this audit** | `docs/audit/2026-09-25-wordpress-engineering-standardisation-audit.md` (this file), `docs/engineering-standard.md` (the proposed standard), plus two index links in `AGENTS.md` |
| **Companion document** | [`docs/engineering-standard.md`](../engineering-standard.md) — the normative standard this audit recommends adopting |

---

## 1. Executive summary

The repository is **healthy and unusually well-documented at the content level, but under-instrumented at the engineering level**. The English (`/en/`) rollout across nine stages produced genuinely strong practices — dry-run-then-apply importers, human-authored translation manifests, PT-regression snapshots, in-process assertion suites and HTTP acceptance matrices — yet almost none of those practices are **extracted, named, enforced or reusable**. They exist as per-stage artefacts.

The result is a repository where *adding the tenth translated content type or the twentieth migration* means re-deriving the pattern from the Stage 4.5 / 5 / 6 / 7 / 9 reports, copying a bootstrap block out of another script, and hoping the build script is updated in all five places that currently list the activation order.

**Scale at audit time**

| Metric | Value |
|---|---|
| Tracked files | 1,087 (pack 43.4 MiB) |
| PHP files / PHP LOC in `wp-content` | 392 files, ~63 000 LOC |
| Theme | 19 589 PHP LOC + 16 323 CSS/JS LOC |
| Custom plugins | 12 plugins, 49 000 PHP LOC, 6.0 MB (5.2 MB of that is importer tests/fixtures) |
| `scripts/` | 115 files (74 PHP, 34 Python, 7 shell), 24 413 LOC |
| Tests | 100 PHP test files, 21 329 LOC, 0 runners, 0 PHPUnit |
| Docs | 58 files / 16 543 lines in `docs/` + 22 root reports / 11 370 lines |
| Committed evidence/work dirs | 550 tracked files in `stage43-work` … `stage7-work` (~21.9 MB) |
| Committed PHP CLI binaries | 24 MB (`.local/php/php` + `.local/php-cli.tar.gz`) = **55 % of the pack** |
| CI workflows | **0** |
| Coding-standard / static-analysis configs | **0** |
| PHP lint steps in scripts | **0** |
| WordPress-specific AI skills | **0** (23 Dart/Flutter skills instead) |

**Top 12 standardisation recommendations (detail in §6–§8)**

1. **Create a `docs/engineering-standard.md` as the single normative contract** (this audit's companion) and make it the review checklist — *done, proposal ready to adopt*.
2. **Add a minimal, dev-only quality gate**: `.editorconfig`, `phpcs.xml.dist`, `phpstan.neon.dist`, dev-only `composer.json` (WPCS + PHPCompatibilityWP + phpstan-wordpress). No production dependency; no build step.
3. **Add CI** (`.github/workflows/ci.yml`): `php -l`, PHPCS, PHPStan, shellcheck, JSON/YAML lint on every push/PR; optional Docker integration job that boots `compose.yaml` and runs the test suite.
4. **Build one shared test harness**: `tests/bootstrap.php` + `tests/lib/assert.php` + `scripts/run-tests.sh`. Removes 24 bootstrap copies and 20+ assert-helper variants.
5. **Standardise a "content rollout" engine** instead of six near-identical translation plugins (`apply.php` / `audit.php` / `translation-map.php` × 5–6): one `conexao-translation-rollout` framework, per-stage data files only.
6. **Split the theme monoliths**: `functions.php` (4 765 lines) → `inc/` modules; `inc/polylang.php` (2 072 lines) → `inc/i18n/*.php`; `inc/seo.php` (1 747 lines) → `inc/seo/*.php`. Behaviour-preserving, pure file moves + `require_once`.
7. **Define a plugin file contract** (bootstrap singleton, `includes/class-*.php`, activation/uninstall lifecycle, `Requires Plugins`, steady-state active set) and state clearly which plugins must be *inactive or absent* in steady state.
8. **Single source of truth for plugin registry + load order + build**: generate the ZIP list, the README table, the AGENTS.md table and the activation order from one `plugins.json`/manifest; today the order appears in ≥5 places and the build script already omits `conexao-guide-translation`.
9. **Stop committing work products**: gitignore `*-work/`, `*.body`, `*.log`, `dist/*.json`, `.local/`, `scripts/__pycache__/`; move curated evidence under `docs/evidence/<stage>/` and keep only what a future reader needs.
10. **Make the safety contract explicit and reusable**: every content-affecting operation must support `--dry-run`, print a machine-readable plan, refuse ID-based matches, snapshot PT before EN writes, and end with a numeric gate (`eligible X missing EN = 0`). Encode it as a skill + template, not as prose in a stage report.
11. **Fix the documentation drift mechanically**: `docs/project-inventory.md` is stale in 6 of 12 version rows; AGENTS.md/README/deployment say "6 plugins"; `docs/deployment.md` has duplicated sections. Add a generator + a drift check.
12. **Create WordPress AI-agent skills** (`.agents/skills/wp-*`) that encode this repository's conventions, and treat the existing Flutter/Dart skill set + `skills-lock.json` as out-of-scope clutter for a WordPress-only repository (relocate, do not delete, per the audit constraint).

---

## 2. Phase 1 — Repository inventory

### 2.1 Area inventory

| Area | Exists | Current approach (evidence) | Recommendation |
|---|---|---|---|
| **Theme** | Yes, 1 only | `wp-content/themes/conexao-br-irlanda/` — 30 root templates, `inc/` (12 modules), `template-parts/` (22 parts), `assets/css` (8 files, 13 671 lines), `assets/js` (2 files, 2 122 lines), `languages/` (pot + po/mo ×2), `tests/` (27 files) | Keep as the only theme. Split `functions.php` / `polylang.php` / `seo.php`; exclude `tests/` from the production ZIP; record a `theme.json` decision (none today, while `style.css` claims "full Gutenberg support") |
| **Plugins** | Yes, 12 custom | `wp-content/plugins/conexao-*` — 4 "platform" (data-model, content, admin-ux, event-runtime), 3 "migration/tooling" (event-importer, leisure-migration, sponsor-migration), 5 "one-shot translation" (page, blog, job, leisure-descriptions, guide) | Keep the split, but formalise plugin classes (platform / tooling / rollout), a bootstrap contract, a lifecycle contract and a registry manifest; introduce a shared rollout engine |
| **Scripts** | Yes, 115 files | `scripts/` — WP-CLI `eval-file` PHP (74), stdlib Python REST/HTTP (34), bash (7), plus `scripts/data/` fixture PHP | Catalogue + naming scheme + shared bootstrap lib + shared REST client lib; move `tmp-*` / diagnostics out of the main namespace; standardise `--dry-run` |
| **Tests** | Yes, 100 files / 21 329 LOC | Per-component `tests/` dirs (theme + 6 plugins) + `scripts/test-*`; bespoke assert helpers; WordPress bootstrapped from `wp-load.php`; no runner | Introduce `tests/bootstrap.php`, `tests/lib/assertions.php`, `scripts/run-tests.sh`; keep the in-process style (fits the WordPress.com constraint) as *the* standard |
| **Docs** | Yes, extensive | `docs/` 58 files / 16 543 lines + 22 root `CONEXAO_*` reports / 11 370 lines + `AGENTS.md` (136 lines) | Add `docs/engineering-standard.md`, `docs/reports/`, `docs/evidence/`, `docs/templates/`; single doc index; doc-drift check in CI |
| **Configuration** | Partially | `compose.yaml`, `.env` / `.env.example`, `.htaccess`, `docker/*`, `skills-lock.json`, `.gitignore` | Add `.editorconfig`, `.gitattributes`, `phpcs.xml.dist`, `phpstan.neon.dist` (dev-only), `plugins.json` registry |
| **Deployment artifacts** | Yes | `scripts/build-plugins-zip.sh`, `scripts/build-theme-zip.sh`, `dist/*.zip` (gitignored), `docs/english-stage43-production-deployment.md` runbook | Add a release manifest + version stamping + artifact allowlist + executable post-deploy verification |
| **Development environment** | Yes | Docker Compose (WordPress + MySQL), bind mounts for the theme + **11** of 12 plugins, `docker/mu-plugins` volume | Generate mounts from the plugin registry; pin image versions; note that `conexao-leisure-translation` has **no** mount (finding F-51) |
| **CI configuration** | **No** | No `.github/`, no workflow, no CI runner config anywhere | Add static + integration CI (finding F-02) |
| **Static analysis** | **No** | No PHPCS / PHPStan / PHP-CS-Fixer / ESLint / Stylelint config; convention only | Add PHPCS (WordPress-Extra + WordPress-Docs + PHPCompatibilityWP) + PHPStan (phpstan-wordpress) |
| **Translation files** | Yes, partially | 6 plugin `.pot`; theme `.pot` + `en_US`/`pt_BR` `.po`/`.mo`; **no** generation tooling in `scripts/` or `docs/` despite "regenerate" instructions in the `.po` header | Add `scripts/i18n-make-pot.sh` (`wp i18n make-pot`) + `scripts/i18n-check.sh` (catalogue freshness) |
| **Generated files** | Yes | `dist/*.zip` (ignored), `scripts/__pycache__/` (ignored), `stage*-work/**` (**550 tracked files**), root `stage41-rest-matrix.json`, plugin `wikimedia-image-report.json` | gitignore generated output; keep only curated evidence under `docs/evidence/` |

| **Migration tools** | Yes, strong | Event importer (106 PHP files, 28 581 LOC), leisure/sponsor migration (ZIP/JSON + embedded images + UUID dedupe), 5 rollout plugins, 40+ stage scripts | Extract the reusable core (plan → dry-run → apply → verify → rollback) into a documented rollout framework; keep per-stage data |
| **Fixture data** | Yes | `conexao-event-importer/tests/fixtures/` (5.2 MB incl. 1.2 MB / 1.1 MB / 960 KB JSON baselines, 564 KB `mi-listing.html`), `scripts/data/*.php` (143 KB), `dist/*.json` (**gitignored**) | Adopt a documented fixture convention with a size budget; move curated JSON evidence out of the ignored `dist/` |
| **Reports** | Yes, many | 22 root `CONEXAO_*` reports + `docs/*-report.md` + `stage*-work` captures | Move to `docs/reports/` and `docs/evidence/<stage>/`; keep a report index and template |
| **Shell scripts** | Yes, 7 | `build-*.sh`, `restore-updraft-db.sh`, `stage2-http-verify.sh`, `stage7-validation-clone-setup.sh`, `stage9-guide-http-verify.sh`, `test-admin-ux-e2e-http.sh` | Add shellcheck to the gate and a shared `scripts/lib/` include |
| **PHP scripts** | Yes, 74 | WP-CLI `eval-file` / standalone with templated `wp-load.php` discovery | **33/74 lack an `ABSPATH` guard**; 20+ bootstrap variants → standardise via `scripts/lib/bootstrap.php` |
| **SQL files** | No | None in repo; local DB import driven by `scripts/restore-updraft-db.sh` (UpdraftPlus re-encoding + UTF-8 verification) | Keep; document as the only supported local DB import path |
| **Docker files** | Yes | `compose.yaml` + `docker/apache-allowoverride.conf`, `docker/entrypoint-fix-permissions.sh`, `docker/php/uploads.ini`, `docker/mu-plugins/disable-jetpack-account-protection.php` | Pin versions; derive mounts from the registry; broaden the entrypoint `chmod` (today: theme + `conexao-content` only) |
| **Composer configuration** | **No** | No `composer.json` / `composer.lock`, no `vendor/` | Add **dev-only** Composer tooling (never shipped to WordPress.com) |
| **npm/package configuration** | **No** | No `package.json`; assets are hand-written plain files (by design) | Keep; prefer PHPCS/PHPStan over introducing Node |
| **Git configuration** | Minimal | `.gitignore` (6 lines); no hooks; no `.gitattributes`; origin default branch `master` while work happens on `i18n` (plus 15+ `cline/*` branches) | Add `.gitattributes`, optional `pre-commit`, PR template; reconcile branch strategy |
| **Agent configuration** | Partially, wrong domain | `AGENTS.md` (good) + `.agents/skills/` (23 Dart/Flutter skills, gitignored) + `skills-lock.json` (Flutter only) | Add `.agents/skills/wp-*` WordPress skills; relocate Flutter skills/lock out of this repo |
| **WordPress-specific configuration** | Partially | Permalinks `/%postname%/`, `.htaccess` rewrites/caching/headers, `WORDPRESS_DB_CHARSET=utf8mb4` pins, local-only mu-plugin guard | Document activation order, mu-plugin policy, charset pins (never latin1), REST exposure allowlist |

### 2.2 Explicit file-existence matrix

| Requested artifact | Exists | Notes |
|---|---|---|
| `AGENTS.md` | **YES** | 136 lines; accurate orientation, 6 stale facts (finding F-31) |
| `README.md` | **YES** | 106 lines; plugin table lists 6 of 12 plugins |
| `.gitignore` | **YES** | 6 patterns; does not cover `stage*-work/`, `*.body`, `*.log`, `.local/`, `dist/*.json` |
| `.editorconfig` | **NO** | Tabs-in-PHP convention exists implicitly (verified: theme + plugins use hard tabs) |
| `.php-cs-fixer.php` / `.php-cs-fixer.dist.php` | **NO** | — |
| `phpcs.xml` / `phpcs.xml.dist` | **NO** | — |
| `phpstan.neon` / `phpstan.neon.dist` | **NO** | — |
| `composer.json` / `composer.lock` | **NO** | — |
| `package.json` / lockfile | **NO** | Intentional (no JS build tooling) |
| `docker-compose.yml` | **NO** (uses `compose.yaml`) | Modern Compose filename; fine |
| `wp-env.json` / `.wp-env.json` | **NO** | Local env is Compose-based instead |
| CI workflows (`.github/workflows/*`) | **NO** | — |
| Git hooks | **NO** | Only the `.git/hooks/*.sample` defaults |
| Deployment scripts | **YES** | `scripts/build-plugins-zip.sh`, `scripts/build-theme-zip.sh` (ZIP build only; upload is manual) |
| Backup scripts | **Partial** | `scripts/restore-updraft-db.sh` (restore + verification). No dump/backup script, no pre-change snapshot helper |
| Test runners | **NO** | 100 test files, each self-contained; no aggregator |
| Coding-standard definitions | **NO** | — |
| `Makefile` / task runner | **NO** | Commands documented in `docs/development.md` |
| `phpunit.xml(.dist)` | **NO** | Tests are plain PHP scripts |
| `skills-lock.json` | **YES** | Flutter/Dart skills only (domain mismatch for this repo) |

> **Compose mount check:** `compose.yaml` bind-mounts the theme and 11 plugin directories (content, data-model, event-importer, event-runtime, admin-ux, leisure-migration, sponsor-migration, page-translation, blog-translation, job-translation, guide-translation) plus `docker/mu-plugins`. `conexao-leisure-translation` has **no** mount, and `docker/entrypoint-fix-permissions.sh` chmods only the theme and `conexao-content` — both are drift risks as the plugin count grows (findings F-51, F-22).

---


## 3. Phase 2 — Current architecture map

### 3.1 Actual structure (as found)

```text
wordpress-website/                          (branch i18n, 1 087 tracked files, 43.4 MiB pack)
├── AGENTS.md                     # agent orientation (136 lines) — accurate but stale in places
├── README.md                     # human overview — plugin table stale
├── compose.yaml                  # WordPress (latest) + MySQL (latest); theme + 11 plugins bind-mounted
├── .htaccess                     # EN→PT 301s, Wix legacy redirects, WP rewrite, caching, DEFLATE, 3 security headers
├── .env / .env.example           # local DB + WP_APPLICATION_PASSWORD for REST scripts
├── .gitignore                    # .env, dist/, __pycache__, .agents/skills/, .idea/
├── skills-lock.json              # pin file for 23 Dart/Flutter skills (repo is WordPress-only)
├── .agents/skills/               # 23 Dart/Flutter skills; ZERO WordPress skills
├── docker/                       # apache-allowoverride.conf, entrypoint-fix-permissions.sh, php/uploads.ini, mu-plugins/
├── content-inventory/            # Wix migration CSVs (5) + README
├── dist/                         # built ZIPs (ignored) + curated *.json migration payloads (ignored!)
├── docs/                         # 58 files / 16 543 lines (see 3.6) + audit/ (this audit)
├── scripts/                      # 115 files / 24 413 LOC: 74 PHP, 34 Python, 7 bash + data/
├── stage43-work/ stage45-work/ stage5-work/ stage6-work/ stage7-work/ stage9-work/
│                                 # 550 tracked evidence trees (~21.9 MB): raw .body/.log/JSON/HTML captures
├── stage41-rest-matrix.json      # one-off stage-level JSON at repo root
└── wp-content/
    ├── plugins/                  # 12 custom plugins (49 000 PHP LOC)
    └── themes/conexao-br-irlanda # the only theme (19 589 PHP LOC + 16 323 CSS/JS LOC)

CONEXAO_BR_ENGLISH_* / CONEXAO_BR_*  # 22 root-level reports, 11 370 lines (Stage 1 → Stage 9 history)
```

### 3.2 Theme map (`wp-content/themes/conexao-br-irlanda/`)

```text
conexao-br-irlanda/
├── style.css                     # header only; all CSS lives in assets/css
├── functions.php       4 765 L   # MONOLITH: setup, enqueue, perf, homepage transients,
│                                 #   cache invalidation, REST allowlist, excerpt, body classes,
│                                 #   reading time, related posts, "latest blog posts", quick access
├── front-page.php        605 L   # homepage composition (8 WP_Query calls, cached)
├── header.php / footer.php / 404.php / home.php / archive.php / search.php / index.php
├── page.php / page-landing.php / page-empregos.php
├── single.php / single-sponsor.php / single-leisure.php (407 L)
├── inc/
│   ├── i18n.php                  299 L  # locale foundation; conexao_current_locale()
│   ├── polylang.php            2 072 L  # 50 functions: guard, locale hooks, URL resolvers,
│   │                                    #   archive/lang URLs, term lookup, B2 fallback policy,
│   │                                    #   leisure card copy, hreflang, switcher, canonical
│   ├── rest-language.php         729 L  # bilingual REST contract (register_rest_field + query filters)
│   ├── seo.php                 1 747 L  # titles, meta, canonical, OG/Twitter, schema, sitemap,
│   │                                    #   robots, redirects — only 1 apply_filters (no extension points)
│   ├── employment-opportunities.php 580 L  # Empregos data/model layer
│   ├── recruitment-agencies.php  550 L
│   ├── job-resources.php         373 L
│   ├── empregos-landing.php      372 L
│   ├── permit-employers.php      279 L
│   ├── search.php                220 L
│   └── post-views.php            163 L
├── template-parts/               22 parts (event-card, leisure-card, employment-opportunities 48 KB,
│                                 #   leisure-filters 30 KB, event-filters 26 KB, …)
├── assets/css/                   8 files / 13 671 lines (main.css 8 037 L, dark-mode.css 2 569 L)
├── assets/js/                    main.js 2 026 L (single deferred bundle), job-resources-admin.js
### 3.3 Plugin map

| Plugin | Class | Files / LOC | Bootstrap style | i18n | Steady-state role |
|---|---|---|---|---|---|
| `conexao-data-model` | platform | 5 / 1 090 | procedural + `includes/class-*.php` | `.pot` + `load_plugin_textdomain` | **Active** (production) |
| `conexao-content` | platform | 3 / 1 031 | procedural + top-level scripts (`create-pages.php`, `seed-leisure.php`) | `.pot` | **Active** (production) |
| `conexao-admin-ux` | platform | 12 / 6 753 | procedural + `includes/class-*.php` | `.pot` | **Active** (production) |
| `conexao-event-runtime` | platform | 9 / 2 826 | procedural + `includes/class-*.php` + `tests/` | `.pot` | **Active** (production, required) |
| `conexao-event-importer` | tooling | 106 / 28 581 | singleton + `sources/` + `tests/` + fixtures (6.0 MB) | `.pot` | Local-only, never production |
| `conexao-leisure-migration` | tooling | 8 / 2 975 | singleton + fallback CPT registration when data-model is absent | `.pot` | Local-only |
| `conexao-sponsor-migration` | tooling | 5 / 1 643 | singleton + `includes/class-*.php` | `.pot` | Local-only |
| `conexao-page-translation` | rollout | 3 / 959 | procedural `apply.php` + `translation-map.php` | — | One-shot (Stage 4.5) |
| `conexao-blog-translation` | rollout | 4 / 3 078 | procedural `apply.php` + `audit.php` + `translation-map.php` (2 056 L data) | — | One-shot (Stage 5) |
| `conexao-job-translation` | rollout | 4 / 862 | procedural `apply.php` + `audit.php` + `translation-map.php` | — | One-shot (Stage 6) |
| `conexao-leisure-translation` | rollout | 3 / 555 | procedural `apply.php` + `audit.php` + JSON data | — | One-shot (Stage 7) |
| `conexao-guide-translation` | rollout | 72 / 3 131 | procedural `apply*.php` + `audit.php` + **70 `body-*.php` data files** | — | One-shot (Stage 9) |

**Observed plugin conventions:** `conexao-*` slug prefix; `Conexão BR Irlanda — <Purpose>` plugin header; `Requires Plugins:` for dependency ordering (used by event-importer and event-runtime); singleton `get_instance()` in tooling plugins; admin screens under Tools; dry-run preview before apply; `ABSPATH` guard at the top of every loaded file; **no PHP namespaces and no autoloader** (global `Conexao_*` class names); one `conexao-*` text domain per plugin.

### 3.4 Scripts map (115 files / 24 413 LOC)

| Group | Count | Examples | Notes |
|---|---|---|---|
| WP-CLI `eval-file` (PHP) | 74 | `seed-*.php`, `run-*.php`, `stage*.php`, `verify-*.php`, `cleanup-*.php` | 67 bootstrap `wp-load.php`; 33 lack an `ABSPATH` guard |
| REST / HTTP (Python, stdlib only) | 34 | `*-rest.py`, `*-http-verify.py`, `*-inventory.py` | 42 hardcoded `conexaobr.ie` references; credentials via `WP_USERNAME` / `WP_APPLICATION_PASSWORD` |
| Bash | 7 | `build-*.sh` (2), `restore-updraft-db.sh`, `stage*-verify.sh`, `test-admin-ux-e2e-http.sh` | `set -euo pipefail` used in the build scripts |
| Data / fixtures | 7 (`scripts/data/`) | `leisure-expansion-data-1..3.php`, `fix-urls.php`, `url-verification-report.json` | 143 KB |
| Diagnostic / temporary | ≥10 | `tmp-mp-*.php`, `c2-audit.php`, `c2-gate-diagnosis.php`, `repair-mi-css-leak.php`, `debug-heritage-images.php` | Not part of any documented workflow |

### 3.5 Test map

| Location | Files | Style |
|---|---|---|
| `wp-content/themes/conexao-br-irlanda/tests/` | 27 | Plain PHP, `wp-load.php` bootstrap copy, local assert helper (`t2_`, `s5_`, `s6_`, `s7_`, `s9_`, `s32_`, `s33_`, `s41_`, `s45_`, `ts_` …) |
| `wp-content/plugins/*/tests/` | ~40 | Same style, per-plugin helper (`test_assert`, `t_assert`, `uuid_assert`, `gate_assert`, `mp_test_assert`, `xl_assert` …) |
| `scripts/test-*.php`, `scripts/test-*.sh` | 8 | Same style / HTTP-level |
| `scripts/stage*-verify.*`, `*-http-verify.*` | ~12 | HTTP acceptance matrices (bash + Python), each with its own JSON shape |

### 3.6 Documentation map

`docs/architecture.md`, `content-model.md`, `routing.md` (30 KB), `frontend.md`, `deployment.md`, `development.md`, `english-stage43-production-deployment.md`, `project-inventory.md` (stale), `plugins/` (README + 12 plugin docs), `themes/conexao-br-irlanda.md` (73 KB), `ui/`, `events/`, `importers/`, `research/`, `audit/` (new), plus 22 root-level stage reports.

### 3.7 Configuration / deployment / tooling summary

| Concern | Mechanism today |
|---|---|
| Local env | `docker compose up -d` → `localhost:8080`; bind mounts (live edit) |
| Permissions | `docker/entrypoint-fix-permissions.sh` chmod on the theme + `conexao-content` only |
| PHP limits | `docker/php/uploads.ini` → 300 M upload/post/memory |
| Local-only shim | `docker/mu-plugins/disable-jetpack-account-protection.php` |
| Build | `scripts/build-*-zip.sh` → `dist/*.zip` (11 plugin slugs listed; `conexao-guide-translation` missing) |
| Deploy | Manual upload in wp-admin, activation in documented order, prose checklist |
| Verify | Per-stage HTTP matrices + in-process suites, run manually |
| CI/CD | **None** |
| Static analysis | **None** |
| Secrets | `.env` (ignored) + WordPress application passwords for REST scripts |

---

├── languages/                    conexao-br-irlanda.pot + en_US.{po,mo} + pt_BR.{po,mo} (no generator)
└── tests/                        27 in-process PHP test scripts (no runner)
```

**Observed theme conventions (worth keeping — to be made mandatory):** `conexao_` function prefix (99/99 functions in `functions.php`); `CONEXAO_THEME_*` constants; documented module load order in `functions.php` (i18n → polylang → rest-language → seo → …); template parts over inline markup; `conexao_asset_version()` filemtime cache-busting; language-scoped transient keys; imperative `require_once` of `inc/` modules.

---


## 4. Phase 3 — Findings register

Severity: **H** = blocks or endangers standardisation · **M** = causes rework/drift · **L** = hygiene.
Each finding has a concrete recommendation; the normative form lives in [`docs/engineering-standard.md`](../engineering-standard.md).

### Group A — Quality gates and tooling (absent)

| ID | Sev | Finding | Evidence | Recommendation |
|---|---|---|---|---|
| A-01 | H | No coding-standard or static-analysis gate exists | No `composer.json`, `phpcs.xml(.dist)`, `phpstan.neon(.dist)`, `.php-cs-fixer.php`, `.editorconfig`, `package.json`, `phpunit.xml` (verified one by one) | Add dev-only Composer + PHPCS (WordPress-Extra, WordPress-Docs, PHPCompatibilityWP) + PHPStan (phpstan-wordpress) + `.editorconfig`; never shipped to production |
| A-02 | H | No CI of any kind | No `.github/`; no workflow, no bot, no required check | `.github/workflows/ci.yml`: `php -l` on all changed PHP, PHPCS, PHPStan, shellcheck on `scripts/*.sh`, `python -m json.tool`/YAML lint; optional `compose.yaml` integration job that boots WordPress and runs the test runner |
| A-03 | M | No PHP syntax check anywhere, despite 392 PHP files and generated `body-*.php` data files | No `php -l` in any script | Add to the CI gate and to a `scripts/lint.sh` |
| A-04 | M | No Git hooks | `.git/hooks/` contains only `.sample` files | Offer an opt-in `scripts/install-hooks.sh` (pre-commit: whitespace/`php -l`; pre-push: phpcs on changed files) |
| A-05 | M | Floating container images | `compose.yaml`: `mysql:latest`, `wordpress:latest` while docs claim WordPress 7.0.2 / PHP 8.5 | Pin `wordpress:7.0.2-php8.5-apache` and a fixed `mysql:8.4`; bump deliberately in one commit; document the local↔production version matrix |
| A-06 | L | Declared PHP floors are untested and inconsistent | Plugins declare `Requires PHP: 7.4`/`8.0`; theme `Requires PHP: 7.4`; runtime is PHP 8.5; theme uses typed returns/properties | Set one supported floor (recommend `8.1`), declare it in all headers + PHPCS `testVersion`, verify with PHPCompatibilityWP |

### Group B — Repository hygiene

| ID | Sev | Finding | Evidence | Recommendation |
|---|---|---|---|---|
| B-01 | H | 550 tracked files of raw work product | `git ls-files \| grep stage.*-work` = 550; `stage7-work` 11 MB (1.8 MB JSON inventory, 892 KB + 808 KB HTTP captures); 78 tracked `.body`/`.log`/`.zip`/`.pyc` files | gitignore `*-work/`; distil each stage into `docs/evidence/<stage>/` (expected vs actual, gate result) and drop raw dumps |
| B-02 | H | 24 MB of committed PHP CLI binaries = 55 % of the pack | `.local/php/php` (12 MB) + `.local/php-cli.tar.gz` (12 MB); pack = 43.4 MiB | Remove from Git; document how to obtain a local PHP CLI (or use `docker compose exec`) |
| B-03 | M | Curated migration payloads live in an ignored directory | `dist/*.json` (`ivvcc-only-export.json`, `mondello-only-export.json`, `stage-d-rollout-export.json`) match `.gitignore` rule `dist/` | Move curated inputs to `docs/evidence/` or `content-inventory/` (tracked); keep `dist/` for build output only |
| B-04 | M | Reports scattered at the repository root | 22 `CONEXAO_*.md` files, 11 370 lines, next to `compose.yaml` and `AGENTS.md` | Move to `docs/reports/`; add `docs/reports/README.md` index; use the report template |
| B-05 | L | Temporary/diagnostic scripts sit in the main `scripts/` namespace | `tmp-mp-export-inspect.php`, `tmp-mp-state-check.php`, `tmp-mp-verify.php`, `c2-audit.php`, `c2-gate-diagnosis.php`, `c2-run2-background.php`, `repair-mi-css-leak.php` | Delete, or move to `scripts/diagnostics/` (excluded from the standard workflow docs) |
| B-06 | L | `.env.example` contains a duplicated 7-line block and an unused placeholder pairing | Lines 1–7 repeated verbatim at 8–14; `WP_USERNAME`/`WP_APPLICATION_PASSWORD` only needed by REST scripts | De-duplicate and group by purpose (Compose vars / REST credentials) with comments |
| B-07 | L | No `.gitattributes` | Binary/large assets and `.po`/`.mo` conflate diff/merge behaviour | Add `* text=auto`, `*.php text eol=lf`, `*.png -text`, `*.mo -text` |

---

