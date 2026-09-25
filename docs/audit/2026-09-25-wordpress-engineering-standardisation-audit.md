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
| Docs | 58 files / 16 543 lines in `docs/` (before this audit) + 20 root `CONEXAO_*` reports / 11 128 lines |
| Committed evidence/work dirs | 550 tracked files in `stage43-work` … `stage7-work` (≈22 MB) |
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
| **Theme** | Yes, 1 only | `wp-content/themes/conexao-br-irlanda/` — 17 root templates + `functions.php`, `inc/` (11 modules), `template-parts/` (22 parts), `assets/css` (8 files, 14 201 lines), `assets/js` (2 files, 2 122 lines), `languages/` (pot + po/mo ×2), `tests/` (25 files) | Keep as the only theme. Split `functions.php` / `polylang.php` / `seo.php`; exclude `tests/` from the production ZIP; record a `theme.json` decision (none today, while `style.css` claims "full Gutenberg support") |
| **Plugins** | Yes, 12 custom | `wp-content/plugins/conexao-*` — 4 "platform" (data-model, content, admin-ux, event-runtime), 3 "migration/tooling" (event-importer, leisure-migration, sponsor-migration), 5 "one-shot translation" (page, blog, job, leisure-descriptions, guide) | Keep the split, but formalise plugin classes (platform / tooling / rollout), a bootstrap contract, a lifecycle contract and a registry manifest; introduce a shared rollout engine |
| **Scripts** | Yes, 115 files | `scripts/` — WP-CLI `eval-file` PHP (74), stdlib Python REST/HTTP (34), bash (7), plus `scripts/data/` fixture PHP | Catalogue + naming scheme + shared bootstrap lib + shared REST client lib; move `tmp-*` / diagnostics out of the main namespace; standardise `--dry-run` |
| **Tests** | Yes, 100 files / 21 329 LOC | Per-component `tests/` dirs (theme 25 + 4 plugins 75) + `scripts/test-*` (7); bespoke assert helpers; WordPress bootstrapped from `wp-load.php`; no runner | Introduce `tests/bootstrap.php`, `tests/lib/assertions.php`, `scripts/run-tests.sh`; keep the in-process style (fits the WordPress.com constraint) as *the* standard |
| **Docs** | Yes, extensive | `docs/` 58 files / 16 543 lines + 20 root `CONEXAO_*` reports / 11 128 lines + `AGENTS.md` (136 lines) | Add `docs/engineering-standard.md`, `docs/reports/`, `docs/evidence/`, `docs/templates/`; single doc index; doc-drift check in CI |
| **Configuration** | Partially | `compose.yaml`, `.env` / `.env.example`, `.htaccess`, `docker/*`, `skills-lock.json`, `.gitignore` | Add `.editorconfig`, `.gitattributes`, `phpcs.xml.dist`, `phpstan.neon.dist` (dev-only), `plugins.json` registry |
| **Deployment artifacts** | Yes | `scripts/build-plugins-zip.sh`, `scripts/build-theme-zip.sh`, `dist/*.zip` (gitignored), `docs/english-stage43-production-deployment.md` runbook | Add a release manifest + version stamping + artifact allowlist + executable post-deploy verification |
| **Development environment** | Yes | Docker Compose (WordPress + MySQL), bind mounts for the theme + **11** of 12 plugins, `docker/mu-plugins` volume | Generate mounts from the plugin registry; pin image versions; note that `conexao-leisure-translation` has **no** mount (finding J-06) |
| **CI configuration** | **No** | No `.github/`, no workflow, no CI runner config anywhere | Add static + integration CI (finding A-02) |
| **Static analysis** | **No** | No PHPCS / PHPStan / PHP-CS-Fixer / ESLint / Stylelint config; convention only | Add PHPCS (WordPress-Extra + WordPress-Docs + PHPCompatibilityWP) + PHPStan (phpstan-wordpress) |
| **Translation files** | Yes, partially | 6 plugin `.pot`; theme `.pot` + `en_US`/`pt_BR` `.po`/`.mo`; **no** generation tooling in `scripts/` or `docs/` despite "regenerate" instructions in the `.po` header | Add `scripts/i18n-make-pot.sh` (`wp i18n make-pot`) + `scripts/i18n-check.sh` (catalogue freshness) |
| **Generated files** | Yes | `dist/*.zip` (ignored), `scripts/__pycache__/` (ignored), `stage*-work/**` (**550 tracked files**), root `stage41-rest-matrix.json`, plugin `wikimedia-image-report.json` | gitignore generated output; keep only curated evidence under `docs/evidence/` |

| **Migration tools** | Yes, strong | Event importer (106 PHP files, 28 581 LOC), leisure/sponsor migration (ZIP/JSON + embedded images + UUID dedupe), 5 rollout plugins, 40+ stage scripts | Extract the reusable core (plan → dry-run → apply → verify → rollback) into a documented rollout framework; keep per-stage data |
| **Fixture data** | Yes | `conexao-event-importer/tests/fixtures/` (5.2 MB incl. 1.2 MB / 1.1 MB / 960 KB JSON baselines, 564 KB `mi-listing.html`), `scripts/data/*.php` (143 KB), `dist/*.json` (**gitignored**) | Adopt a documented fixture convention with a size budget; move curated JSON evidence out of the ignored `dist/` |
| **Reports** | Yes, many | 20 root `CONEXAO_*` reports + `docs/*-report.md` + `stage*-work` captures | Move to `docs/reports/` and `docs/evidence/<stage>/`; keep a report index and template |
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
| `AGENTS.md` | **YES** | 136 lines; accurate orientation, but 6 stale facts (findings F-02, K-02) |
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

> **Compose mount check:** `compose.yaml` bind-mounts the theme and 11 plugin directories (content, data-model, event-importer, event-runtime, admin-ux, leisure-migration, sponsor-migration, page-translation, blog-translation, job-translation, guide-translation) plus `docker/mu-plugins`. `conexao-leisure-translation` has **no** mount, and `docker/entrypoint-fix-permissions.sh` chmods only the theme and `conexao-content` — both are drift risks as the plugin count grows (findings J-06, D-06).

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
│                                 # 550 tracked evidence trees (≈22 MB): raw .body/.log/JSON/HTML captures
├── stage41-rest-matrix.json      # one-off stage-level JSON at repo root
└── wp-content/
    ├── plugins/                  # 12 custom plugins (49 000 PHP LOC)
    └── themes/conexao-br-irlanda # the only theme (19 589 PHP LOC + 16 323 CSS/JS LOC)

CONEXAO_BR_ENGLISH_* / CONEXAO_BR_*  # 20 root-level reports, 11 128 lines (Stage 1 → Stage 9 history)
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
├── assets/css/                   8 files / 14 201 lines (main.css 8 037 L, dark-mode.css 2 569 L)
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
| `conexao-guide-translation` | rollout | 72 / 3 131 | procedural `apply*.php` + `audit.php` + **54 `body-*.php` data files** | — | One-shot (Stage 9) |

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
| `wp-content/themes/conexao-br-irlanda/tests/` | 25 | Plain PHP, `wp-load.php` bootstrap copy, local assert helper (`t2_`, `s5_`, `s6_`, `s7_`, `s9_`, `s32_`, `s33_`, `s41_`, `s45_`, `ts_` …) |
| `wp-content/plugins/*/tests/` | 75 | Same style, per-plugin helper (`test_assert`, `t_assert`, `uuid_assert`, `gate_assert`, `mp_test_assert`, `xl_assert` …) |
| `scripts/test-*.php`, `scripts/test-*.sh` | 7 | Same style / HTTP-level |
| `scripts/stage*-verify.*`, `*-http-verify.*` | ~12 | HTTP acceptance matrices (bash + Python), each with its own JSON shape |

### 3.6 Documentation map

`docs/architecture.md`, `content-model.md`, `routing.md` (30 KB), `frontend.md`, `deployment.md`, `development.md`, `english-stage43-production-deployment.md`, `project-inventory.md` (stale), `plugins/` (README + 12 plugin docs), `themes/conexao-br-irlanda.md` (73 KB), `ui/`, `events/`, `importers/`, `research/`, `audit/` (new), plus 20 root-level stage reports.

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
└── tests/                        25 in-process PHP test scripts (no runner)
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
| B-04 | M | Reports scattered at the repository root | 20 `CONEXAO_*.md` files, 11 128 lines, next to `compose.yaml` and `AGENTS.md` | Move to `docs/reports/`; add `docs/reports/README.md` index; use the report template |
| B-05 | L | Temporary/diagnostic scripts sit in the main `scripts/` namespace | `tmp-mp-export-inspect.php`, `tmp-mp-state-check.php`, `tmp-mp-verify.php`, `c2-audit.php`, `c2-gate-diagnosis.php`, `c2-run2-background.php`, `repair-mi-css-leak.php` | Delete, or move to `scripts/diagnostics/` (excluded from the standard workflow docs) |
| B-06 | L | `.env.example` contains a duplicated 7-line block and an unused placeholder pairing | Lines 1–7 repeated verbatim at 8–14; `WP_USERNAME`/`WP_APPLICATION_PASSWORD` only needed by REST scripts | De-duplicate and group by purpose (Compose vars / REST credentials) with comments |
| B-07 | L | No `.gitattributes` | Binary/large assets and `.po`/`.mo` conflate diff/merge behaviour | Add `* text=auto`, `*.php text eol=lf`, `*.png -text`, `*.mo -text` |

---

### Group C — Theme architecture

| ID | Sev | Finding | Evidence | Recommendation |
|---|---|---|---|---|
| C-01 | H | `functions.php` is a 4 765-line, 99-function monolith mixing at least 8 concerns | Setup/enqueue, performance dequeueing, homepage transients + invalidation, REST allowlist, excerpt/body classes/reading time, related posts, latest posts, quick-access resolution | Split into `inc/setup.php`, `inc/assets.php`, `inc/performance.php`, `inc/cache.php`, `inc/rest-policy.php`, `inc/content.php`, `inc/queries.php`; `functions.php` becomes a loader of `require_once`s in explicit order |
| C-02 | H | `inc/polylang.php` (2 072 lines, 50 functions) mixes ~6 responsibilities | Guard, locale filters, URL/archive resolvers, term lookups, B2 fallback policy, leisure card copy, hreflang, switcher, canonical | Split into `inc/i18n/` (`guard.php`, `locale.php`, `urls.php`, `terms.php`, `fallback.php`, `hreflang.php`, `switcher.php`); keep public function names unchanged |
| C-03 | M | `inc/seo.php` (1 747 lines) is a single module with no extension points | Titles/meta/canonical/OG/Twitter/schema/sitemap/robots/redirects in one file; exactly 1 `apply_filters` | Split into `inc/seo/*.php` and introduce named filters (`conexao_seo_title`, `conexao_seo_canonical`, `conexao_sitemap_urls` …) as the documented plugin integration surface |
| C-04 | M | Architecture rule 11 is violated in practice | Language/locale branching also appears in `inc/i18n.php`, `inc/search.php`, `inc/employment-opportunities.php`, `inc/empregos-landing.php`, `inc/recruitment-agencies.php`, `functions.php` | Either centralise language decisions in `inc/i18n/` or amend the rule to "language *policy* lives in `inc/i18n/`; other modules may only read `conexao_current_locale()` / `conexao_lang_url()`" — the second is more realistic and should be written down |
| C-05 | M | Extension-point surface is effectively absent | Theme-wide: 0 `do_action`, 6 `apply_filters` (`i18n.php` 2; `seo.php`, `search.php`, `polylang.php`, `job-resources.php`, `employment-opportunities.php` 1 each) | Define a small documented hook contract (filters for SEO, employment data, job resources, leisure card data, language switcher data) so plugins extend without editing the theme |
| C-06 | M | CSS is three unbounded files | `main.css` 8 037 lines / 194 KB; `dark-mode.css` 2 569 lines; `header-nav.css` 1 273 lines; total 14 201 lines across 8 files | Split by component (`assets/css/components/*.css`) with explicit enqueue order, or at minimum add Stylelint + a "one component per file" rule for new work; keep the design-system variables as the only token source |
| C-07 | L | No `theme.json` although the theme advertises Gutenberg support | `style.css` description/tags mention Gutenberg; `theme.json` absent | Either add a minimal `theme.json` (locking the editor palette to the design-system variables) or remove the Gutenberg claim and document that blocks are unstyled |
| C-08 | L | Theme ships `tests/` in the production ZIP | `dist/conexao-br-irlanda.zip` contains 24 `tests/` entries | Add `-x "*/tests/*"` to `build-theme-zip.sh` (mirrors the plugin builder) |

---
### Group D — Plugin architecture and lifecycle

| ID | Sev | Finding | Evidence | Recommendation |
|---|---|---|---|---|
| D-01 | H | Five near-identical "translation rollout" plugins duplicate the same control flow | `page`/`blog`/`job`/`guide` each ship `apply.php` + `audit.php` + `translation-map.php`; `leisure` ships `apply.php` + `audit.php`; 4 811 LOC of near-duplicate orchestration | Introduce `conexao-translation-rollout` (shared engine: plan → dry-run → apply → verify → gate → rollback); each stage keeps only a data file + a small config array |
| D-02 | M | Plugin internal structure is inconsistent | `conexao-leisure-migration` uses a singleton + `class-*.php`; rollout plugins are procedural `apply.php`; `conexao-content` ships unloaded top-level scripts (`create-pages.php`, `seed-leisure.php`) | One file contract: `<slug>.php` bootstrap (header, constants, `ABSPATH` guard, hooks, `includes/` loading) + `includes/class-<slug>-<concern>.php`; runtime seeders move to `scripts/` or an explicitly documented admin action |
| D-03 | M | Duplicate source of truth for the `leisure` schema | `conexao-leisure-migration::maybe_register_helpers()` re-registers the `leisure` CPT + `conexao_category`/`conexao_county`/`conexao_tag` when data-model is absent | Remove the fallback (declare `Requires Plugins: conexao-data-model`) or extract the registration into one shared data-model function that both call |
| D-04 | M | No autoloading; global class names | 12 plugins, ~40 `Conexao_*` classes in the global namespace, manual `require_once` ladders in every bootstrap | Add a plugin-scoped `spl_autoload_register` (or a shared `conexao_load_class()` helper); keep class prefixes unique per plugin |
| D-05 | M | Lifecycle is documented but not enforceable | Rollout plugins are "activate → apply → deactivate/remove" yet remain in the repo, in the documented load order, and (mostly) in the build script; no uninstall/rollback contract | Add a `Status:` convention (`platform` / `tooling` / `rollout:retired`) to each plugin doc + registry; state the steady-state active set in exactly one place; provide `uninstall.php` or an explicit "no uninstall by design" note |
| D-06 | L | Dev-only artefacts ship inside plugin ZIPs | `conexao-admin-ux.zip` contains `wikimedia-image-report.json` (12 KB) | Add a per-plugin include/exclude list to the registry; build from an allowlist |
| D-07 | L | Versions drift without a changelog | Headers: 1.0.6 / 1.0.0 / 1.0.0 / 1.6.0 / 1.7.1 / 1.2.1 / 1.0.0 / 1.0.0 / 2.1.0 / 1.0.0 / 1.0.0 / 1.1.0; theme fixed at 1.0.0; no CHANGELOG anywhere | Add `CHANGELOG.md` per shipped component (or one `docs/releases.md`) and bump versions in the release commit |

### Group E — Testing

| ID | Sev | Finding | Evidence | Recommendation |
|---|---|---|---|---|
| E-01 | H | No test runner and no framework | 100 test files / 21 329 LOC; zero aggregators; no PHPUnit/`phpunit.xml`; tests are standalone PHP scripts | Ship `tests/bootstrap.php` (single WordPress bootstrap), `tests/lib/assertions.php` (one API), `scripts/run-tests.sh` (discover + run + aggregate + non-zero exit); keep the plain-PHP style |
| E-02 | H | Bootstrap duplication | 20+ variants of `wp-load.php` discovery across scripts and tests (`dirname( __DIR__ )`, `/var/www/html/wp-load.php`, walk-up loops, `$dir . '/wp-load.php'`); ~24 theme tests each carry their own copy | One `tests/bootstrap.php` / `scripts/lib/bootstrap.php`; forbid inline bootstrap in new files (CI grep check) |
| E-03 | H | Assertion-helper sprawl | 13× `test_assert`, 9× `t_assert`, 2× `ts_assert`, plus `s5_/s6_/s7_/s9_/s32_/s33_/s41_/s45_/uuid_/gate_/attr_/mp_/xl_assert` | One assertion library with `assert_true/equals/set/contains/fail` + summary exit code |
| E-04 | M | Tests are not isolated: they assert against live local data | Suites assert published counts, specific slugs (`saude`/`health`), term relationships, and production-shaped content; no seeding/teardown step documented | Standardise "read-only assertions over a seeded fixture DB" or make each suite declare its prerequisites (`@requires: seed-leisure`) and fail loudly with a setup hint |
| E-05 | M | Placement and naming are inconsistent | `wp-content/themes/.../tests/`, `wp-content/plugins/*/tests/`, `scripts/test-*.php`, `scripts/stage*-verify.*`, `scripts/*-http-verify.py` | Rule: unit/in-process → `<component>/tests/`; HTTP/acceptance → `tests/acceptance/`; naming `test-<area>-<behaviour>.php`, `verify-<area>-http.py` |
| E-06 | M | HTTP acceptance matrices are ad-hoc per stage | `stage2-http-verify.sh`, `stage33/stage41/stage43/stage45/stage6/stage7-*-verify.py`, `c3-production-http-verify.py`, `nav-regression-http-verify.py`; one-off root `stage41-rest-matrix.json` | Shared harness (`tests/acceptance/lib/`) with a fixed JSON schema (row = id, url, expectation, before/after) and one CLI; matrices become plain data files |
| E-07 | M | Heavy fixtures committed inside a shipped plugin | `conexao-event-importer` = 6.0 MB of which `tests/` = 5.2 MB (1.2 MB + 1.1 MB + 960 KB JSON baselines, 564 KB HTML) | Move baselines to `tests/fixtures/` with a documented size budget; keep only fixtures required to prove parsing/normalisation; exclude from ZIPs (already done) |

---


### Group F — Documentation

| ID | Sev | Finding | Evidence | Recommendation |
|---|---|---|---|---|
| F-01 | H | `docs/project-inventory.md` claims to be machine-readable and is stale | Documented vs actual versions: data-model 1.3.0 vs **1.6.0**; admin-ux 1.0.0 vs **1.0.6**; event-runtime 1.0.0 vs **1.2.1**; event-importer 1.5.0 vs **1.7.1**; leisure-migration 2.0.0 vs **2.1.0**; sponsor-migration 1.0.0 vs **1.1.0** | Generate the inventory from the plugin headers (`scripts/generate-inventory.php`) and add a CI drift check; or drop the file in favour of `plugins.json` |
| F-02 | M | Plugin counts differ across four documents | `AGENTS.md` layout says "6 custom plugins"; `README.md` lists 6; `docs/deployment.md` lists 6 ZIPs; 12 exist | One generator, one source of truth (the registry), one table pasted by tooling |
| F-03 | M | `docs/deployment.md` has structural damage | "### Plugins" heading with an empty body followed immediately by "## Environment Differences" (duplicated again at the file end) and two "## Deployment Checklist" sections, the first containing a stray `./scripts/build-plugins-zip.sh` block | Rewrite the file once against the new standard; add a docs lint (heading uniqueness/order) later |
| F-04 | M | Translation catalogues have no generation or freshness check | 6 plugin `.pot` + theme `.pot`/`en_US`/`pt_BR` `.po`/`.mo`; the `.po` header says "Generated … do not hand-edit; regenerate" but no `wp i18n make-pot` invocation exists in `scripts/` or `docs/` | Add `scripts/i18n-make-pot.sh` + `scripts/i18n-check.sh` (fails when a catalogue is older than the source defining its strings) |
| F-05 | M | 60 doc files with a single, partial index | `docs/` = 58 files / 16 543 lines (before this audit); indexes exist only in `AGENTS.md` §Documentation index and `docs/plugins/README.md`; `docs/themes/README.md` is 368 bytes | `docs/README.md` as the canonical index; per-directory `README.md`; every doc ends with "Last verified" + owning area |
| F-06 | L | Empty and mixed-purpose directories | `docs/lazer/` is empty; `docs/` mixes evergreen docs, stage reports, audits, importer research | Define directory roles (`docs/` evergreen, `docs/reports/` stage history, `docs/evidence/` proof, `docs/templates/` templates) and remove empty dirs |

### Group G — Scripts and tooling

| ID | Sev | Finding | Evidence | Recommendation |
|---|---|---|---|---|
| G-01 | H | 115 scripts with no catalogue, no ownership, no status | 74 PHP + 34 Python + 7 bash = 24 413 LOC; the only documentation is a prose bullet in `docs/development.md` §Running Scripts | Add `scripts/README.md` (purpose, when to run, arguments, safety level, last verified) + a naming standard; mark one-shot stage scripts as historical |
| G-02 | H | Bootstrap duplication and missing guards | 67 scripts bootstrap `wp-load.php` with 20+ shapes; **33/74 PHP scripts have no `ABSPATH` guard** | `scripts/lib/bootstrap.php` (locate `wp-load.php`, define `ABSPATH`, `WP_USE_THEMES=false`, parse `--dry-run`) required by every new script |
| G-03 | M | Production domain hardcoded in scripts | 42 `conexaobr.ie` references across `scripts/` | Resolve the base URL from one place (`CONEXAO_SITE_URL` env with a documented default) and log the target in every run header |
| G-04 | M | No shared REST client | 34 Python scripts each re-implement auth, retries, pagination and error handling with `urllib` | `scripts/lib/rest.py` (auth from env, retry/backoff, pagination, `--dry-run`, uniform JSON output) |
| G-05 | M | Dry-run is a convention with three spellings | 27 scripts mention dry-run; seen as `dry-run`, `--dry-run`, and positional / `DRY_RUN` env values | Mandate `--dry-run` (default for destructive scripts is *plan only*), plus `--apply` to write, and a machine-readable plan on stdout |
| G-06 | M | Stage-scoped names encode history, not capability | `stage2-*`, `stage32-*`, `stage33-*`, `stage41-*`, `stage43-*`, `stage45-*`, `stage5-*`, `stage6-*`, `stage7-*`, `stage9-*`, `c3-*`, `mp-*`, `ivvcc-*` | Keep stage scripts as historical artefacts (`scripts/historical/`) and give reusable capabilities generic names (`translation-inventory.php`, `content-snapshot.php`, `http-verify.py`) |

### Group H — Bilingual / Polylang engineering

| ID | Sev | Finding | Evidence | Recommendation |
|---|---|---|---|---|
| H-01 | H | "How to add the next EN translation" exists only as nine stage reports | Rollout logic re-implemented 5×; the B2 fallback, taxonomy policy, filter-slug rules, hreflang rules and cache scoping are documented across `routing.md` §English (6 sub-sections) and the stage reports | Publish one normative "bilingual change procedure" (in the standard + a `wp-add-translation-rollout` skill) with a checklist and a copy-paste data-manifest format |
| H-02 | M | Taxonomy policy is documented but not asserted globally | Policy: `conexao_category`/`conexao_tag` translated; `conexao_county`/`conexao_town` shared. Only stage tests assert it | Add a permanent invariant test (`test-polylang-taxonomy-policy.php`): no suffixed duplicate county/town terms; every translated term is linked both ways |
| H-03 | M | Language-scoped caching depends on discipline | Keys are built per language via `conexao_lang_cache_key()` / `conexao_flush_language_cache()`; nothing detects an unscoped key in new code | Document the rule; add a static check for `set_transient( 'conexao_…' )` / `wp_cache_set( 'conexao_…' )` without a language suffix, plus a debug-mode runtime assertion |
| H-04 | M | B2 fallback allowlists are code-level policy with only stage-time gates | `conexao_b2_page_allowlist()`, `conexao_b2_post_types()`, `conexao_b2_translation_replaced_pt_ids()` | Add a standing completeness gate per content type ("eligible public PT records missing EN = 0, or explicitly allowlisted") so a new PT record can never ship untranslated silently |
| H-05 | L | EN coverage is proven per stage, not continuously | Gate results live in report prose (`88/88 HTTP`, `134/134 in-process`, `289/289`) | Persist gate results as `docs/evidence/<stage>/gate.json` and re-run the aggregate gate in CI |

### Group I — Data and content-change safety

| ID | Sev | Finding | Evidence | Recommendation |
|---|---|---|---|---|
| I-01 | H | No single "content change" contract | Safe patterns exist but only inside stage tooling: PT snapshots (`stage45-pt-snapshot.py`, `stage6-job-pt-snapshot.py`), completeness scans, PT-drift guards (leisure EN descriptions), numeric gates | Formalise the 6-step procedure as the standard (inventory → manifest → dry-run plan → apply → verify → gate + rollback) and make it the only accepted shape for data/code that writes content |
| I-02 | M | Portable-identifier discipline is unevenly enforced | Leisure uses UUIDs; events use `source + source_id + export UUID`; several scripts fall back to slug/title matching (`deployment.md` for Lazer: "UUID → slug → title"; REST image refresh matches by slug) | Keep slug/title as a *reported* fallback (never silent): the apply step must print how many records matched by each strategy and refuse ID-based matches |
| I-03 | M | Snapshot/rollback is implicit | `scripts/restore-updraft-db.sh` is excellent but restore-oriented; no "snapshot before change" helper, and reports contain no rollback field | Add `scripts/content-snapshot.php` (JSON export of the affected post type + meta) and require a "Rollback" section in the report template |
| I-04 | L | Import/export identifiers are documented but not schema-validated | `docs/content-model.md` §Import/Export Identifiers; no JSON schema for the leisure/sponsor/event payloads | Provide JSON Schema files under `docs/schemas/` and validate payloads in the importer + CI |

### Group J — Build, deployment and release

| ID | Sev | Finding | Evidence | Recommendation |
|---|---|---|---|---|
| J-01 | H | The build script omits a shipped plugin and duplicates the load order | `build-plugins-zip.sh` lists 11 slugs (no `conexao-guide-translation`); the same ordering appears in README.md, AGENTS.md, `docs/deployment.md`, `docs/plugins/README.md`, and the script's own closing text | Generate build + docs from one `plugins.json`; build exactly what the registry declares |
| J-02 | M | The production theme ZIP ships tests | 24 `tests/` entries in `dist/conexao-br-irlanda.zip` | Exclude `tests/` (and any dev-only asset) in `build-theme-zip.sh` |
| J-03 | M | No release manifest or version stamp | No CHANGELOG/tags recorded; theme and several plugins stay at 1.0.0; no record of what is deployed on production | Emit `dist/release.json` (component, version, git SHA, build time, file list, sha256) in the build scripts; tag releases; keep a `docs/releases.md` log |
| J-04 | M | Deployment verification is prose only | `docs/deployment.md` 10-step checklist; WordPress.com has no CLI | Add `scripts/verify-deploy.py` (HTTP smoke matrix: archives, singles, `/en/` pairs, canonical, sitemap, 404) reused by every release |
| J-05 | L | Production/local version parity is asserted but not checked | Docs say WordPress 7.0.2 / PHP 8.5; `compose.yaml` uses `:latest` | Pin images and record the matrix in `docs/deployment.md`; print versions in the verification report |
| J-06 | M | Local environment drift: a plugin has no bind mount, and the entrypoint chmods only two paths | `compose.yaml` mounts 11 of 12 plugins (`conexao-leisure-translation` missing); `docker/entrypoint-fix-permissions.sh` chmods the theme + `conexao-content` only | Generate the mount list from `plugins.json`; chmod every bind-mounted component (or use a group-writable bind-mount policy documented once) |

---

---

### Group K — AI-agent configuration

| ID | Sev | Finding | Evidence | Recommendation |
|---|---|---|---|---|
| K-01 | H | The agent-skill library is entirely the wrong domain | `.agents/skills/` holds 23 Dart/Flutter skills and `skills-lock.json` pins them; there is **no** WordPress/Polylang/PHP skill, so every WordPress task restarts from zero knowledge | Add `.agents/skills/wp-*` covering this repo's real workflows (list in §8 / the standard §13); relocate the Flutter skills + lock out of this repository (documented, not executed here — Flutter is out of scope) |
| K-02 | M | `AGENTS.md` is good but not verifiable and has stale facts | 136 lines; says "6 custom plugins"; content-model and route tables duplicate `docs/routing.md`/`docs/content-model.md` and can drift | Keep AGENTS.md as orientation only; add a "facts are generated or linked" rule and a CI drift check for counts/versions |
| K-03 | M | No task/plan template for agent or human work | Stage reports differ in structure; plans were conversational | Add `docs/templates/plan.md` (scope, files, risks, verification, rollback) and `docs/templates/report.md`; require them for any change touching content, routes or EN |
| K-04 | L | No machine-readable project map | `docs/project-inventory.md` is prose tables; no `plugins.json` / `routes.json` for agents to read | Ship `plugins.json` (registry) and optionally `routes.json` derived from `docs/routing.md` |

### Group L — Security, performance and general observations

| ID | Sev | Finding | Evidence | Recommendation |
|---|---|---|---|---|
| L-01 | — (positive) | Admin write paths are gated | 35 nonce/referer checks vs 27 `admin_post_`/`wp_ajax_` handlers; 65 `current_user_can` checks | Keep; encode as a hard rule + PHPCS `WordPress.Security.*` ruleset |
| L-02 | — (positive) | Output escaping is consistent | 522 `esc_html_e`, 260 `esc_url`, 72 `esc_attr_e`, 57 `esc_html`, 56 + many `esc_attr` | Keep; PHPCS enforces it for new code |
| L-03 | M | Seven interpolated DB call sites deserve review | 133 `$wpdb->` usages, 12 with `prepare`, 7 direct-interpolation call sites (`$wpdb->get_var("…")` style) | Enable `WordPress.DB.PreparedSQL` in PHPCS; review the 7 sites once |
| L-04 | M | Money-path performance depends on transients with undocumented invalidation coverage | `conexao_home_*` / `conexao_404_*` transients + `conexao_flush_language_cache()`; homepage performs 8 queries | Document the invalidation matrix (which CPT save clears which key, per language) and add a test that asserts invalidation per post type |
| L-05 | L | `X-Frame-Options: SAMEORIGIN` is set but no CSP | `.htaccess` sets `nosniff`, `X-Frame-Options`, `Referrer-Policy`; no `Content-Security-Policy` | Record the CSP decision explicitly (WordPress.com constraints) so it is not re-litigated per audit |
| L-06 | L | WordPress.com constraints vs local tooling | No SSH/CLI on production ⇒ no server-side scripts; everything must be admin-UI or REST | Make this an explicit standard rule: production-capable tooling must provide (a) admin screen, (b) WP-CLI path for local, (c) REST path for production where relevant |

### Group M — Git, branching and process

| ID | Sev | Finding | Evidence | Recommendation |
|---|---|---|---|---|
| M-01 | M | Branch strategy is implicit | origin default `master`; long-lived `i18n`; 15+ `cline/*` agent branches; `i18n-backup-vdhn162j` | Declare `master` = production-ready, feature branches = `stage<N>-<slug>` or `feat/<slug>`; delete merged `cline/*` branches; document in the standard |
| M-02 | M | Commit history mixes stage narrative with code | Subjects like "Stage 7 Phase 3-8: language-aware leisure card description + rollout plugin + clone validation" are informative but not machine-parseable | Adopt a light convention (`<area>: <imperative summary>` + optional stage reference), documented; keep the narrative in the report |
| M-03 | L | No PR template or review checklist | No `.github/` at all | Add `PULL_REQUEST_TEMPLATE.md` mirroring the standard's Definition of Done (§14) |
| M-04 | L | 15+ stale `cline/*` branches | `git branch -a` shows remote `cline/drac45r7`, `cline/rpztgj8v`, `cline/rx6v6bw5`, `cline/vdhn162j` with no local counterpart | Prune after confirming they are merged |

---


## 5. Phase 4 — Gap analysis: practice → today → target

| Practice | Today | Target standard | Priority |
|---|---|---|---|
| Coding style | Implicit (tabs, `conexao_` prefix) | `.editorconfig` + PHPCS WordPress-Extra/Docs, PHPCompatibilityWP; `phpcbf` on changed files | P1 |
| Static analysis | None | PHPStan level 5 with `phpstan-wordpress`, baseline for legacy files, no new violations | P1 |
| CI | None | `.github/workflows/ci.yml`: syntax lint, PHPCS, PHPStan, shellcheck, JSON/YAML lint; optional Docker integration job | P1 |
| Tests | 100 scripts, no runner, duplicate bootstraps/asserts | `tests/bootstrap.php` + `tests/lib/assertions.php` + `scripts/run-tests.sh`; suites keep their names; aggregated exit code | P1 |
| Acceptance evidence | Per-stage ad-hoc matrices | Shared harness + fixed JSON schema; matrices as data; stored under `docs/evidence/<stage>/` | P2 |
| Theme structure | 4 765-line `functions.php`; 2 072-line `polylang.php`; 1 747-line `seo.php` | `inc/` modules ≤ ~400 lines each, one concern per file, `functions.php` = loader only | P1 |
| Plugin structure | 3 competing styles; no lifecycle contract | One bootstrap contract + `includes/class-*.php`; plugin classes (platform/tooling/rollout) with `Status:` in docs + registry | P2 |
| Translation rollouts | 5 near-duplicate plugins (4 811 LOC) | One rollout engine + per-stage data; new EN coverage = data + config + gate test | P1 |
| Content-change safety | Excellent patterns, not documented as a contract | 6-step contract (inventory → manifest → dry-run plan → apply → verify → gate + rollback) enforced by skill + template + suite | P1 |
| Bilingual rules | Spread across `routing.md` + 9 reports | One normative §Bilingual standard + invariant tests (taxonomy policy, completeness gate, cache scoping) | P1 |
| Scripts | 115 files, 20+ bootstraps, 3 dry-run spellings | `scripts/README.md` + `scripts/lib/` (bootstrap, rest, plan) + naming/flag standard | P2 |
| Docs | 58 files, partial index, stale inventory | `docs/README.md` index, `docs/reports/`, `docs/evidence/`, `docs/templates/`, generated inventory, drift check | P2 |
| Build/release | 2 scripts, 1 missing plugin, no manifest | Registry-driven build, artifact allowlist, `release.json` + sha256 + tag, `docs/releases.md` | P2 |
| Repository hygiene | 550 work files, 24 MB binaries | Clean tree; evidence distilled into `docs/evidence/`; size budget enforced in review | P2 |
| Deployment verification | Prose checklist | `scripts/verify-deploy.py` HTTP smoke matrix, run for every release | P3 |
| Agent skills | Flutter-only | `.agents/skills/wp-*` (WordPress workflows) + task/report templates | P2 |
| Hooks/extension points | 6 filters, 0 actions | Documented filter contract for SEO, employment data, leisure card data, nav/switcher | P3 |
| Frontend assets | 14 201 CSS lines, 2 122 JS lines unbundled | Component-split CSS or a documented "one component per file" rule + Stylelint | P3 |
| i18n catalogues | Hand-maintained, no generator | `i18n-make-pot.sh` + freshness check | P3 |

---

## 6. Phase 5 — Risk register

| # | Risk | Likelihood | Impact | Mitigation (from the standard) |
|---|---|---|---|---|

## 7. Phase 6 — Recommended implementation roadmap

Sequenced so each stage is independently valuable and never blocks content work. **Nothing here is executed by this audit.**

| Stage | Scope | Key deliverables | Exit gate |
|---|---|---|---|
| **A. Baseline & docs** (0.5–1 day) | Adopt this audit + `docs/engineering-standard.md`; update `AGENTS.md`; fix stale counts/versions by hand once | 2 new docs, `AGENTS.md` links, `docs/README.md` index | Docs reviewed and accepted; no code touched |
| **B. Repository hygiene** (0.5 day) | `.gitignore` (work dirs, `.local/`, `*.body`/`*.log`, `dist/*.json`), `.gitattributes`, remove binaries + work trees from Git (or archive history to a bundle), `.env.example` cleanup, move root reports to `docs/reports/` | Clean `git status`, pack < 15 MB | `git ls-files` shows no `*-work/`, `.local/`, `.body`/`.log`; docs still complete |
| **C. Static quality gate** (1 day) | `.editorconfig`, dev-only `composer.json`, `phpcs.xml.dist`, `phpstan.neon.dist`, `scripts/lint.sh`, PHPCS baseline | `composer lint` + `composer analyse` run clean (baseline allowed) | A deliberately malformed edit fails locally; CI-ready |
| **D. CI** (0.5 day) | `.github/workflows/ci.yml` (static) + optional `integration` job (compose up, run the runner) | Green CI on the branch | PRs blocked on failure |
| **E. Test harness** (1–2 days) | `tests/bootstrap.php`, `tests/lib/assertions.php`, `scripts/run-tests.sh`; migrate the 27 theme suites + plugin suites mechanically | One command runs every suite; summary + exit code | All suites pass; no bootstrap copies remain |
| **F. Theme modularisation** (2–3 days) | `functions.php` → `inc/*.php`; `inc/polylang.php` → `inc/i18n/*.php`; `inc/seo.php` → `inc/seo/*.php`; documented load order; regression suite per module | Pure moves + loaders; no behaviour change | Existing tests pass unchanged; homepage/archives/singles/sitemap/hreflang verified over HTTP |
| **G. Plugin registry + lifecycle** (1–2 days) | `plugins.json` (slug, class, status, deps, build includes, mounts) + generator for docs/load order/build/compose; `Status:` in every plugin doc | One source of truth; build includes all shipped plugins | `build-plugins-zip.sh` output matches the registry; docs generated |
| **H. Rollout engine** (2–4 days) | `conexao-translation-rollout` skeleton + migrate one stage (the best candidate is the next new content type) | One engine, one data file per stage; `--dry-run` plan; gate output | A new EN content type needs ≤ 1 data file + config, no new orchestration code |
| **I. Scripts standardisation** (1–2 days) | `scripts/lib/` (bootstrap/rest/plan), `scripts/README.md`, rename reusable capabilities, move stage/historical scripts, standard `--dry-run` | Consistent entry/exit, machine-readable plans | New-script template exists; no new script lacking a guard/dry-run |
| **J. Build/release & deploy verification** (1 day) | Registry-driven build, artifact allowlist, `dist/release.json` (versions + sha256), tags, `docs/releases.md`, `scripts/verify-deploy.py` | Reproducible release + post-deploy smoke matrix | A release can be built, stamped and verified from one command sequence |
| **K. Agent skills + templates** (1 day) | `.agents/skills/wp-*` (§9), `docs/templates/plan.md`, `report.md`, `PULL_REQUEST_TEMPLATE.md`; relocate Flutter skills | Agents execute this repo's conventions without rediscovery | A new feature is planned/shipped using only the skills + templates |
| **L. Continuous gates** (0.5 day) | Standing invariants: completeness gate per content type, taxonomy policy, cache scoping, doc drift, i18n freshness | Permanent test set + CI wiring | Gates fail deliberately when violated (proven once) |

**If only one week is available:** do **B** (hygiene) → **C + D** (gate + CI) → **E** (test harness). These make every later change safer without touching behaviour.

---

| R-01 | A regression ships because there is no automated gate; review depends on a human reading a 200-line apply diff | High | High | CI gate + shared test runner + PR checklist (§2, §8, §12) |
| R-02 | The next EN content type is built by copying a stage plugin, re-introducing ~4 800 LOC of divergent logic | High | High | Rollout engine + `wp-add-translation-rollout` skill (§4, §13) |
| R-03 | Silent PT/EN drift after a Portuguese edit invalidates an EN record | Medium | High | Standing completeness/gate suite + drift guards as a standard in (§6, I-02, H-04) |
| R-04 | A destructive script runs against the wrong target (hardcoded production base URL, no dry-run) | Medium | High | `--dry-run` default, target printed in the run header, shared `scripts/lib/rest.py` (§10) |
| R-05 | `functions.php`/`polylang.php` edits collide or break precedence (SEO vs Polylang ordering) | High | Medium | Module split with documented load order + one regression suite per module (§3) |
| R-06 | Repo growth makes clones/reviews slow and hides the real diffs | High | Medium | Hygiene rules + `docs/evidence/` distillation (§1) |
| R-07 | Docs mislead (stale versions, wrong plugin counts, duplicated deployment sections) | Medium | Medium | Generated inventory + drift check + doc ownership/`Last verified` (§9) |
| R-08 | A plugin exists in the repo but not in the build/mounts (already true: `conexao-guide-translation` build, `conexao-leisure-translation` mount) | High | Medium | Single `plugins.json` driving build, mounts, docs and verification (§4, §11) |
| R-09 | Personal/agent workflows diverge (branch sprawl, ad-hoc conventions) | Medium | Low | Branch + commit + template standard (§12) |
| R-10 | PHP 8.5 runtime with 7.4-declared floors hides incompatibilities | Low | Medium | PHPCompatibilityWP with one declared floor; `php -l` per supported floor (§2) |

## 8. Phase 7 — Configuration files the repository should have

Full proposed contents are in [`docs/engineering-standard.md`](../engineering-standard.md) §1. Summary:

| File | Purpose | Ships to production? |
|---|---|---|
| `.editorconfig` | Tabs for PHP, 2-space YAML/JSON, LF, final newline | n/a (dev only) |
| `.gitattributes` | `* text=auto`, LF for PHP/JSON/YAML, binary marks for images/fonts/zips | n/a |
| `composer.json` (dev-only) | PHPCS + WPCS 3.x + PHPCompatibilityWP + phpstan-wordpress; `lint`, `lint:fix`, `analyse` scripts | **No** |
| `phpcs.xml.dist` | WordPress-Extra + WordPress-Docs + PHPCompatibilityWP; text-domain list; exclude `tests/` | **No** |
| `phpstan.neon.dist` | level 5, `phpstan-wordpress`, theme + plugin scanning, baseline file | **No** |
| `.github/workflows/ci.yml` | Static gate always; integration job on demand | **No** |
| `.github/PULL_REQUEST_TEMPLATE.md` | Definition-of-Done checklist | **No** |
| `plugins.json` (registry) | slug, name, class (platform/tooling/rollout), version source, dependencies, load order, build includes, compose mount | **No** |
| `tests/bootstrap.php`, `tests/lib/assertions.php` | Single test entry point + assertion API | **No** |
| `scripts/run-tests.sh`, `scripts/lint.sh`, `scripts/verify-deploy.py` | Runner, lint, deploy verification | **No** |
| `.github/workflows/` + `docs/templates/` | Process templates (plan, report, PR) | **No** |

Explicitly **not** recommended: `package.json`/Node tooling (the project deliberately has no JS build), `wp-env.json` (Compose already works), PHPUnit as the primary framework (the in-process style matches WordPress.com constraints — keep it as the standard and add PHPUnit only if a suite genuinely needs isolation).

---

## 9. Phase 8 — AI-agent skills this repository should have

Replace the Flutter-only skill library (out of scope to delete here) with WordPress-domain skills under `.agents/skills/`:

| Skill | Encodes |
|---|---|
| `wp-add-content-type` | Adding/changing a CPT, taxonomy or meta field in `conexao-data-model`, plus docs + tests + admin-ux wiring |
| `wp-add-admin-screen` | Tools/CPT admin screens: capability, nonce, list table, dry-run, notices, escaping |
| `wp-add-theme-component` | Template part + design-system variables + dark-mode variables + asset versioning + a11y rules |
| `wp-add-string-i18n` | Sanctioned `__()`/`esc_html_e` usage, text domain per component, catalogue regeneration |
| `wp-content-rollout` | The 6-step content-change contract (inventory → manifest → dry-run → apply → verify → gate + rollback) |
| `wp-add-translation-rollout` | Adding EN coverage for a content type: rollout engine, gate test, routing doc update, B2 policy check |
| `wp-rest-contract-change` | `inc/rest-language.php` rules: `?lang=`, `conexao_language` field, detail gate, collection params, cache scoping |
| `wp-write-in-process-test` | Bootstrap + assertions library + naming + prerequisites + exit codes |

## 10. Appendices

### A. Evidence commands used

```bash
# inventory
find . -path ./.git -prune -o -type f -print | wc -l
git ls-files | awk -F/ '{print $1}' | sort | uniq -c | sort -rn
git ls-files | grep -c 'stage.*-work'            # 550
git count-objects -vH | grep size-pack           # 43.39 MiB
for f in AGENTS.md README.md .gitignore .editorconfig phpcs.xml phpstan.neon composer.json \
         package.json docker-compose.yml wp-env.json phpunit.xml; do
  [ -e "$f" ] && echo "EXISTS: $f" || echo "MISSING: $f"; done

# duplication
grep -rho 'function [a-z0-9_]*_assert' wp-content/*/*/tests/*.php | sort | uniq -c | sort -rn
grep -h "wp-load" scripts/*.php | sort | uniq -c | sort -rn
grep -L "defined( 'ABSPATH' )" scripts/*.php | wc -l           # 33

# drift
for f in wp-content/plugins/*/*.php; do grep -m1 'Version:' "$f"; done
grep -E '^\| conexao-[a-z-]+ \|' docs/project-inventory.md     # documented versions
unzip -l dist/conexao-br-irlanda.zip | grep -c 'tests/'        # 24
```

### B. Metrics snapshot (2026-09-25)

| Metric | Value |
|---|---|
| Tracked files / pack size | 1 087 / 43.39 MiB |
| `wp-content` PHP LOC | ~63 000 (theme 19 589 + plugins ~49 000, incl. data files) |
| Theme CSS+JS LOC | 16 323 |
| `scripts/` LOC | 24 413 (115 files) |
| Test files / LOC | 100 / 21 329 |
| Docs (evergreen) / LOC | 58 / 16 543 |
| Root reports / LOC | 20 / 11 128 |
| Committed evidence trees | 550 files / ≈22 MB |
| Committed binaries | 24 MB (`php-cli.tar.gz` + `php`) |
| CI workflows / static-analysis configs / test runners | 0 / 0 / 0 |
| WordPress AI skills | 0 (23 Dart/Flutter skills present) |

### C. Documentation drift table (verified)

| Document | Claims | Reality |
|---|---|---|
| `docs/project-inventory.md` | data-model 1.3.0 | 1.6.0 |
| `docs/project-inventory.md` | admin-ux 1.0.0 | 1.0.6 |
| `docs/project-inventory.md` | event-runtime 1.0.0 | 1.2.1 |
| `docs/project-inventory.md` | event-importer 1.5.0 | 1.7.1 |
| `docs/project-inventory.md` | leisure-migration 2.0.0 | 2.1.0 |
| `docs/project-inventory.md` | sponsor-migration 1.0.0 | 1.1.0 |
| `AGENTS.md` §Repository layout | "6 custom plugins" | 12 |
| `README.md` | 6 plugins, no translation plugins listed | 12 |
| `docs/deployment.md` | 6 plugin ZIPs named | 11 built (12 exist) |
| `scripts/build-plugins-zip.sh` | 11 slugs | 12 plugin directories |
| `compose.yaml` | — | 11 of 12 plugins mounted |
| `docs/deployment.md` | structure | duplicated "Environment Differences" + two "Deployment Checklist" sections |

### D. What this audit deliberately did not do

- Did not modify PHP, templates, CSS, JS, plugins, `compose.yaml`, `.htaccess`, `.gitignore` or any data.
- Did not rename, move or delete files; did not touch the database, Polylang configuration, URLs, REST contracts or deployment configuration.
- Did not delete or rewrite tests.
- Did not touch anything Flutter/mobile (only noted the skill-library domain mismatch).
- Did not implement any recommendation — everything is a proposal, sequenced in §7.

---

**End of audit.** The normative, implementation-ready form of these recommendations is [`docs/engineering-standard.md`](../engineering-standard.md).

| `wp-http-acceptance-matrix` | Shared harness usage, matrix schema, evidence storage, canonical/hreflang/redirect rows |
| `wp-release-deploy` | Build from registry, artifact allowlist, release manifest, activation order, post-deploy verification, rollback |
| `wp-update-docs` | Which doc must change for which change (routing/content-model/frontend/plugin/theme/AGENTS) + drift checks |
| `wp-security-review` | Nonce/capability/escaping/`$wpdb->prepare` checklist + PHPCS rule mapping |
| `wp-frontend-perf` | Transient caching + invalidation matrix, asset enqueue discipline, LCP/preload conventions |

Each skill follows one shape: **When to use → Required reading (doc paths) → Steps → Guardrails (AGENTS.md architecture rules) → Verification commands → Definition of done.** A feature request should be impossible to complete correctly without invoking at least `wp-content-rollout` + `wp-update-docs` + `wp-write-in-process-test`.

---


---

