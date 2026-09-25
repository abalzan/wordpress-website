# Conexão BR Irlanda — WordPress Engineering Standardisation Audit

| | |
|---|---|
| **Scope** | WordPress website repository only (theme, custom plugins, scripts, docs, Docker, deployment). Flutter / mobile is explicitly out of scope and was not inspected or modified. |
| **Type** | Audit + proposal. **No implementation was performed.** The only repository change is this document. |
| **Production** | https://conexaobr.ie/ (WordPress.com) |
| **Local** | http://localhost:8080/ (Docker Compose) |
| **Method** | Full read-only inventory of 1,085 tracked files; documentation review (`docs/`, root reports); code-pattern sampling across theme, plugins, tests and scripts; `php -l` syntax sweep of all 317 PHP files (all pass); drift checks between docs, plugin headers and container config. |
| **Date basis** | Repository state at commit `1b4d59c` on branch `i18n` (the checkout this audit ran on). |

---

## 0. Executive summary

This repository has **unusually strong documentation and migration discipline** for a
single-maintainer WordPress project, and **unusually weak mechanical enforcement**: there
is no CI, no linter, no static analyser, no test runner, and no dependency manifest.
Every convention that exists today is held up by documentation and personal discipline
alone. The standard proposed in Phase 4 is therefore mostly about **making the existing,
already-documented conventions executable** — not about inventing new ones.

### 0.1 Strengths to keep (do not "fix")

1. **Documentation culture.** `AGENTS.md`, `docs/architecture.md`, `docs/routing.md`,
   `docs/content-model.md`, per-plugin docs, a per-theme doc, an architecture decision
   record (`CONEXAO_BR_ENGLISH_ARCHITECTURE_DECISION.md`) and gate-based stage reports.
   This is the strongest convention in the repo and becomes the backbone of the standard.
2. **The staged migration workflow** (audit → inventory → pilot/dry-run → apply with
   drift guards → HTTP verify → reconciliation report), visible across Stages 1–7 and
   the event/Lazer expansions. 21 scripts support `--dry-run`. This is a de-facto
   standard that only needs formalising.
3. **Identity discipline.** Stable UUIDs for Lazer, `source + source_id` for events,
   "never use WordPress IDs as portable identifiers" — consistently applied and
   documented.
4. **Language architecture.** Single integration layer (`inc/polylang.php`), one locale
   accessor (`conexao_current_locale()`), Portuguese-canonical URLs, capability-guarded
   Polylang coupling, language-scoped caches, third-party Polylang pinned (3.8.9) and
   never vendored.
5. **Sane PHP baseline.** All 317 PHP files pass `php -l` (PHP 8.4); `ABSPATH` guards,
   nonces, capability checks and late escaping were present in every file sampled.
6. **Deployment pragmatism.** ZIP builds exclude tests and VCS junk; `filemtime()` asset
   versioning; `.htaccess` caching/security headers; a UTF-8-safe, verification-heavy
   production backup restore script; local-only mu-plugin pattern.

### 0.2 Gaps that most threaten future work (fix first)

1. **No CI and no quality gates.** Nothing runs tests or checks syntax on a change.
   317 PHP files, 22 theme test files and ~40 test/probe scripts are executed manually,
   or not at all.
2. **No tooling manifest.** No `composer.json`, no `phpcs.xml`, no `phpstan.neon`, no
   `phpunit.xml`, no `.editorconfig`, no `.gitattributes`. Coding standards are
   unenforceable; one test file already contains unreachable code after `exit()`
   (`test-leisure-card-map-action.php`, line 292) that any analyser would flag.
3. **Environment is not reproducible.** `compose.yaml` runs `wordpress:latest` +
   `mysql:latest`; `docs/architecture.md` claims `wordpress:7.0.2-php8.5-apache`; the
   Stage 2 report observed WP 7.1 / PHP 8.3.33 / MySQL 9.7.1. Three sources, three
   answers — for a repo whose production platform pins versions for it.
4. **Documentation drift.** `docs/project-inventory.md` and `docs/plugins/README.md`
   list plugin versions that are 1–3 releases behind the actual plugin headers
   (e.g. data-model documented 1.3.0 vs actual 1.6.0), and claim "10 custom plugins"
   where 11 exist. Docs are the project's main safety mechanism — drift directly
   translates into wrong-agent and wrong-human behaviour.
5. **Repository hygiene.** A 12 MB PHP toolchain (`.local/`) is committed to git; 551
   tracked files live in ephemeral `stage*-work/` caches; 19 stage reports sit at the
   repository root; `scripts/` holds 114 entries including `tmp-*.php` probes and
   committed `.log` files inside a plugin's `tests/` directory.
6. **Test harness duplication.** The `t_assert()`/`t_section()` micro-harness is
   copy-pasted into 9 theme test files; `scripts/test-*.php` uses a second, different
   `check()` harness; bootstrap logic is duplicated in every file; there is no runner
   and no aggregate exit code.

### 0.3 The proposed standard at a glance

Phase 4 defines fifteen standards: **S1** repository configuration files, **S2** PHP
coding standard, **S3** JS/CSS standard, **S4** static analysis, **S5** testing,
**S6** theme architecture, **S7** plugin architecture, **S8** bilingual/Polylang,
**S9** data & migration workflow, **S10** scripts & tooling, **S11** documentation,
**S12** deployment & release, **S13** CI, **S14** AI-agent skills, **S15** security &
performance baseline. Phase 5 sequences them into P0 (hygiene + gates, ~2–3 days),
P1 (tooling + drift control, ~3–5 days) and P2 (structural improvements, ongoing).

Nothing in this audit requires refactoring working code. Every P0/P1 item is additive
(config files, runners, CI, doc moves) and can land without touching production
behaviour.

---

## PHASE 1 — Repository inventory

### 1.1 What exists (annotated, as-built)

```text
repository root
├── AGENTS.md                        # AI-agent orientation (strong, current)
├── README.md                        # Human quickstart (current)
├── CONEXAO_BR_*.md                  # 19 root-level reports: 1 ADR, 1 support audit,
│                                    #   9 English-stage reports, 8 fix/feature reports
├── compose.yaml                     # Docker: db (mysql:latest) + wordpress (wordpress:latest)
├── .env.example                     # Local env overrides (contains a duplicated block)
├── .gitignore                       # .env, dist/, __pycache__, .agents/skills/, .idea/
├── .htaccess                        # Wix redirects, EN→PT redirects, caching, compression,
│                                    #   security headers (mounted into local Apache)
├── skills-lock.json                 # Agent skills lockfile — currently Flutter-only entries
├── stage41-rest-matrix.json         # Stage 4.1 REST verification artifact (root)
├── stage43-work/ … stage7-work/     # 5 ephemeral stage working dirs (HTTP caches,
│                                    #   inventories, manifests): 551 tracked files
├── .local/                          # ⚠ Committed PHP toolchain: php-cli.tar.gz (12 MB)
│                                    #   + extracted `php/php` binary (PHP 8.4)
├── content-inventory/               # Wix migration CSVs + README (historical, complete)
├── docker/                          # apache conf, entrypoint, php/uploads.ini, mu-plugins/
├── docs/                            # 65 files: architecture, routing, content-model,
│   ├── plugins/                     #   frontend, development, deployment, inventory;
│   ├── themes/                      #   11 plugin docs; theme doc; importers/, events/,
│   └── importers/ events/ ui/ …     #   ui/, research/ report subfolders
├── scripts/                         # 114 entries: 70 PHP / 33 Python / 6 bash / data/
│   └── data/                        #   build-*.sh, seed-*, run-*, stage2…stage7-*,
│                                    #   test-*, verify-*, tmp-*, ivvcc-*, mp-*, c2/c3-*
└── wp-content/
    ├── plugins/                     # 11 custom plugins (no third-party vendored):
    │                                #   data-model 1.6.0, content 1.0.0, admin-ux 1.0.6,
    │                                #   event-runtime 1.2.1 (PRODUCTION), event-importer 1.7.1
    │                                #   (local-only), leisure-migration 2.1.0,
    │                                #   sponsor-migration 1.1.0, page/blog/job/leisure-
    │                                #   translation 1.0.0 (stage rollout tooling)
    └── themes/conexao-br-irlanda/   # Only theme: templates, inc/ domain modules
        ├── inc/                     #   (i18n, polylang, rest-language, seo, jobs data…),
        ├── template-parts/          #   24 shared parts
        ├── assets/                  #   css/js/images (design-system → header-nav → main
        ├── languages/               #   → leisure → dark-mode); .pot + pt_BR/en_US po/mo
        └── tests/                   #   22 bespoke integration test scripts
```
### 1.2 Configuration & tooling matrix

Prompt-required files were checked individually; nothing is assumed.

| Area | Exists | Current approach | Recommendation |
|---|---|---|---|
| `AGENTS.md` | ✅ root | Hand-maintained agent orientation; accurate and detailed | Keep; add freshness checks (S11) |
| `README.md` | ✅ root | Human quickstart; structure diagram lists only 6 of 11 plugins | Keep; refresh plugin list (S11) |
| `.gitignore` | ✅ root | Covers `.env`, `dist/`, `__pycache__`, `.agents/skills/`, `.idea/` | Add `.local/`, `stage*-work/`, `*.log`, `tmp-*` (S1) |
| `.editorconfig` | ❌ | Tabs-by-convention (WP style), unenforced | Add: tabs for PHP/JS/CSS per WPCS, LF endings (S1) |
| `.gitattributes` | ❌ | Line endings + zip export content uncontrolled | Add `* text=auto eol=lf` + `export-ignore` for tests/docs (S1, S12) |
| `.php-cs-fixer.php` | ❌ | None | Do **not** add — standardise on PHPCS+WPCS as the single fixer ecosystem (S2) |
| `phpcs.xml` | ❌ | WPCS conventions documented informally only | Add ruleset: WordPress-Core + Docs + selected Extra, scoped exclusions (S2) |
| `phpstan.neon` | ❌ | None — unreachable code after `exit()` already present in one test file | Add level-1 baseline with `wordpress-stubs`; ratchet later (S4) |
| `composer.json` | ❌ | No PHP dependency/tooling manifest | Add **dev-tools-only** composer.json (phpcs+wpcs, phpstan, phpunit); no runtime deps (S1) |
| `package.json` | ❌ | Deliberately no JS build (vanilla JS/CSS policy) | Keep absent, or tooling-only lint — decide explicitly and document (S3) |
| `docker-compose.yml` | ⚠️ as `compose.yaml` | Works; images unpinned (`:latest`); entrypoint chmod list drifted from mounts | Pin images; sync entrypoint with mount list (S12) |
| `.wp-env.json` | ❌ | Docker Compose chosen instead | Keep Compose as the single dev environment; do not add a second one (S12) |
| CI workflows | ❌ | No `.github/`, no other CI config | Add GitHub Actions: php -l, phpcs, phpstan, harness tests, zip build, drift checks (S13) |
| Git hooks | ❌ | Samples only | Optional documented pre-commit; CI remains the real gate (S13) |
| Deployment scripts | ✅ | `scripts/build-plugins-zip.sh`, `build-theme-zip.sh` (tests excluded ✔) | Keep; add version stamping + generalized post-deploy HTTP verify (S12) |
| Backup scripts | ⚠️ restore-only | `scripts/restore-updraft-db.sh` (verification-heavy, UTF-8-safe); backups themselves = UpdraftPlus on production | Document the split of responsibility in one place (S12) |
| Test runners | ❌ | Tests run one file at a time via `docker compose exec … php file.php` | Add `scripts/run-tests.sh` discovery runner + shared bootstrap (S5) |
| Coding-standard definitions | ❌ | Conventions live in README/docs/AGENTS only | Codify into phpcs.xml + a Conventions doc section (S2) |
| `phpunit.xml` | ❌ | No PHPUnit anywhere | Short-term: formalise existing harness; P2: evaluate WP core PHPUnit suite (S5) |
| Translation files | ✅ | Theme: `.pot` + `pt_BR`/`en_US` `.po/.mo`; 8 plugin `.pot` files but no plugin translations shipped | Decide policy: ship plugin translations or treat pots as developer catalogs; document (S8) |
| Fixture data | ✅ | Importer `tests/fixtures/` (HTML/JSON); `scripts/data/`; stage JSON payloads | Keep fixtures in each plugin's `tests/fixtures/`; move stage payloads out of root/scripts (S5/S10) |
| Reports | ✅ scattered | 19 at root; others under `docs/importers|events|ui/` — two competing locations | One location + naming convention + index (S11) |
| SQL files | ❌ none | DB changes via WP-CLI/PHP only — correct for this project | Keep; document "no hand-written SQL migrations" (S9) |
| Docker files | ✅ | `docker/` config + entrypoint + mu-plugins | Add entrypoint/mount consistency check (S12) |
| Agent configuration | ⚠️ | `skills-lock.json` (Flutter skills only); `.agents/skills/` gitignored; zero WP skills exist | Author repo-local WordPress skills; leave Flutter entries untouched (S14) |
| WordPress config | ⚠️ | No repo `wp-config.php` (container-generated) — correct; `.htaccess` is the repo-owned config surface | Add `.htaccess` redirect regression test tier (S5) |
| Makefile / task runner | ❌ | Commands documented in prose across several docs | `scripts/run-tests.sh` now; optional Makefile later (S10) |

### 1.3 Statistics snapshot

| Metric | Value |
|---|---|
| Tracked files | 1,085 |
| PHP files | 317 (all pass `php -l`, PHP 8.4 binary) |
| PHP LOC under `wp-content/` | ~75,100 |
| Largest theme files | `functions.php` 4,671 lines; `inc/polylang.php` 2,072; `inc/seo.php` 1,747; `main.css` 8,034; `main.js` 2,026 |
| Largest plugin files | `class-event-importer.php` 1,512; `class-event-sources.php` 1,251; `class-leisure-importer.php` 1,250; blog `translation-map.php` 2,056 |
| Tests | 22 theme scripts; plugin dirs (runtime 4, admin-ux 2, leisure-migration 2, importer 89 files mixing tests/probes/fixtures/13 `.log` files); 7 `scripts/test-*.php` |
| Scripts | 70 PHP + 33 Python + 6 bash under `scripts/`; 21 support `--dry-run` |
| Ephemeral artifacts tracked | 551 files in `stage*-work/` + `stage41-rest-matrix.json`; 14 `.log` files; 12 MB `.local/` toolchain |
| Docs | 104 Markdown files: 19 reports at root, 65 under `docs/` |

---

## PHASE 2 — Current architecture map (as-built)

### 2.1 Architecture tree

```text
WordPress (Docker local :8080 / WordPress.com production)
│
├── Theme: conexao-br-irlanda (active, only theme)
│   ├── Templates            front-page, archive (shared by all 6 CPTs), single,
│   │                        single-leisure, single-sponsor, page, page-empregos,
│   │                        page-landing, home (blog), search, 404, header, footer
│   ├── template-parts/      24 shared parts (cards, filters, hero, pagination, switcher)
│   ├── inc/i18n.php         Stage 1 locale foundation; owns conexao_current_locale()
│   ├── inc/polylang.php     SINGLE Polylang integration layer (2,072 lines): translated
│   │                        types/taxonomies, menu assignment, hreflang helpers, B2
│   │                        fallback, leisure card excerpt language, caches per language
│   ├── inc/rest-language.php Bilingual REST contract (729 lines): ?lang= filtering,
│   │                        conexao_language record metadata, detail-endpoint rules
│   ├── inc/seo.php          SEO owner (1,747 lines): titles, meta, canonical, OG,
│   │                        schema, sitemap, robots, PHP-level redirects
│   ├── inc/search.php       Search customisation
│   ├── inc/post-views.php   View counting → homepage "Mais Lidos"
│   ├── inc/empregos-landing.php    Jobs landing meta + current-jobs listing
│   ├── inc/job-resources.php       ┐ Data-as-code modules (filterable card datasets
│   ├── inc/recruitment-agencies.php │  for the Empregos hub; seeded to production via
│   ├── inc/permit-employers.php     │  scripts/seed-*.php and rendered from meta)
│   ├── inc/employment-opportunities.php ┘ Unified opportunities model over the 3 sources
│   ├── functions.php        4,671 lines: setup, enqueue (filemtime versioning),
│   │                        customizer, image sizes, homepage queries + transients,
│   │                        template helpers, shortcode, performance dequeues
│   └── assets/              design-system.css → header-nav.css → main.css →
│                            leisure.css → sponsor.css → dark-mode.css; main.js
│
├── Plugins (11, load order matters)
│   ├── conexao-data-model        CPTs (guide, event, job, sponsor, course_provider,
│   │                             leisure), shared taxonomies (category, county, tag),
│   │                             meta registration, contacts, agency helpers
│   ├── conexao-content           Static page creation + grid/category shortcodes, seeds
│   ├── conexao-admin-ux          Admin list/edit UI, statuses, bulk actions, fields,
│   │                             Wikimedia client (local image tooling)
│   ├── conexao-event-runtime     PRODUCTION: event meta, conexao_town taxonomy,
│   │                             _event_status public-query gate, status UI, recurrence
│   ├── conexao-event-importer    LOCAL-ONLY: sources, normalizers, dedupe, image
│   │                             handler, import log, export to production (manual)
│   ├── conexao-leisure-migration Lazer ZIP export/import + maintenance, UUID identity
│   ├── conexao-sponsor-migration Apoiadores JSON export/import with embedded images
│   └── conexao-{page,blog,job,leisure}-translation  One-shot EN rollout tooling
│                                 (audit.php + apply.php + translation-map/data;
│                                  dry-run + rollback; activate → roll out → remove)
│
├── scripts/                   Build (2 zips), seeds, migrations, stage workflows,
│                              HTTP verifiers, tests, restore, one-off probes
├── docker/                    Apache conf, entrypoint, php uploads.ini, local mu-plugin
├── docs/                      Canonical documentation + per-area report folders
└── deployment                 Manual: build ZIPs → upload via wp-admin → activate in
                               load order → verify (docs/deployment.md checklist)
```

### 2.2 Plugin dependency & load-order graph

```text
conexao-data-model (CPTs/taxonomies/meta)
   ├── conexao-content            (shortcodes over the CPTs)
   ├── conexao-admin-ux           (admin UI over the CPTs)
   ├── conexao-event-runtime      (event meta + town + status gate)
   │      └── conexao-event-importer   ("Requires Plugins" header enforced)
   ├── conexao-leisure-migration  (self-registers leisure CPT if data-model absent)
   ├── conexao-sponsor-migration
   └── conexao-{page,blog,job,leisure}-translation (rollout tooling; no runtime deps)

Theme ──requires at runtime──▶ data-model types (soft), Polylang (capability-guarded),
                               event-runtime statuses (soft)
Third-party: Polylang 3.8.9 Free — pinned, installed into the Docker volume,
             never committed; deactivation restores single-language behaviour.
```

### 2.3 Runtime flows

| Flow | Path |
|---|---|
| Public page | Apache `.htaccess` (redirects/cache) → WP rewrite → theme template → `template-parts/*` → helpers in `functions.php`/`inc/*` → language-aware queries (`_event_status` gate via `pre_get_posts`) |
| Homepage | `front-page.php` + transient caches `conexao_home_*` (language-scoped, invalidated on save) |
| Filters | Query-param driven (`?categoria=`, `?county=`, `?cidade=`…) — shared URL-building helpers; filter change resets pagination |
| REST (bilingual) | `inc/rest-language.php`: `?lang=` collection filtering, `conexao_language` metadata, detail-endpoint language rules, event-status gate |
| Event import (local only) | Sources → fetch → normalize → dedupe (source+source_id/UUID/URL/content) → event CPT → export JSON → production import via wp-admin |
| Lazer transfer | Admin export ZIP (`data.json` + images) → target import (preview → confirm), matched by UUID → slug → title |
| EN translation rollout | Inventory → authored manifest → dry-run → apply (PT-drift guard) → audit/verify → report; tooling plugin removed afterwards |
| Deployment | `build-plugins-zip.sh` / `build-theme-zip.sh` → `dist/*.zip` → manual wp-admin upload → activate in load order → checklist verification |

### 2.4 Language layer flow

```text
inc/i18n.php            conexao_current_locale()  ← single locale accessor (filter:
       │                                            conexao_current_locale)
       ▼
inc/polylang.php        wires the filter to Polylang's per-request language; declares
                        translated post types/taxonomies in code; county/town stay
                        shared proper nouns; B2 fallback = approved PT substitution
                        set, never a duplicate identity
       ▼
inc/rest-language.php   ?lang= REST contract + record language metadata
Theme/plugins           read locale ONLY via conexao_current_locale(); caches are
                        language-scoped; PT slugs canonical; /en/ is additive
```

### 2.5 Where things live (ownership)

| Concern | Owner | Not allowed to own it |
|---|---|---|
| CPTs / taxonomies / meta registration | `conexao-data-model` (+ event-runtime for event meta/town) | theme, other plugins |
| Public event visibility | `conexao-event-runtime` (`_event_status` gate) | templates, importer |
| Locale / language context | theme `inc/i18n.php` + `inc/polylang.php` | plugins (read-only via accessor) |
| SEO / redirects / sitemap | theme `inc/seo.php` + `.htaccess` | plugins |
| REST language contract | theme `inc/rest-language.php` (see F-21) | plugins |
| Admin UI / statuses | `conexao-admin-ux` (+ event-runtime event status) | theme |
| Import/export tooling | migration/importer plugins (local-only) | production runtime plugins |
| Jobs hub data | theme `inc/*` data-as-code modules (see F-19) | — (recommend plugin) |
| Deployment artifacts | `scripts/build-*.sh` → `dist/` (gitignored) | — |

---

## PHASE 3 — Findings (gap analysis)

Every finding cites observable evidence. "Std" maps to the Phase 4 standard that
resolves it. Severity: 🔴 blocks safe growth · 🟡 causes drift/rework · 🟢 polish.

### A. Tooling & quality gates

| # | Sev | Finding & evidence | Impact | Std |
|---|---|---|---|---|
| F-01 | 🔴 | **No CI anywhere** — no `.github/`, no other CI config, no git hooks | 317 PHP files + ~36 test scripts are verified manually or never; regressions ship silently | S13 |
| F-02 | 🔴 | **No PHPCS/WPCS ruleset** (`phpcs.xml` absent); style held by convention | Escaping/i18n/nonce mistakes are only caught by human review; style drifts per author/agent session | S2 |
| F-03 | 🟡 | **No static analysis** (`phpstan.neon` absent). Proof of value already exists: `theme/tests/test-leisure-card-map-action.php` line 292 is unreachable code after `exit()` | Latent dead code, wrong-type bugs, undefined-function typos survive | S4 |
| F-04 | 🟡 | **No `composer.json`** — no way to pin/install phpcs/phpstan/phpunit per repo | Tooling versions float per machine; "works on my PHP" (see F-12) | S1 |
| F-05 | 🟡 | **No `.editorconfig` / `.gitattributes`** | Line-ending and indentation drift on Windows/mixed editors; zip exports can't exclude dev files declaratively | S1 |
| F-06 | 🟢 | **No `package.json`** — currently a deliberate no-build policy for JS/CSS | None today; risk appears only if assets grow — decide explicitly rather than by accretion | S3 |

### B. Repository hygiene

| # | Sev | Finding & evidence | Impact | Std |
|---|---|---|---|---|
| F-07 | 🔴 | **12 MB PHP toolchain committed**: `.local/php-cli.tar.gz` + `.local/php/php` (PHP 8.4 vs project's PHP 8.5), not in `.gitignore` | Repo bloat; every clone pays for it; wrong-version linting | S1 |
| F-08 | 🟡 | **551 tracked files of ephemeral stage work**: `stage43-work/`, `stage45-work/`, `stage5-work/`, `stage6-work/`, `stage7-work/`, `stage41-rest-matrix.json` (HTTP caches, inventories, `.body` files); 14 `.log` files tracked, incl. 13 inside `conexao-event-importer/tests/` | Root clutter; agents/humans can't tell artifact from source; diffs drown in caches | S10/S11 |
| F-09 | 🟡 | **19 reports at repository root** while other reports live under `docs/importers|events|ui/` | Two competing conventions; root becomes a dumping ground | S11 |
| F-10 | 🟡 | **`scripts/` is an unstructured 114-entry flat pile**: `tmp-*.php` probes, stage prefixes (`stage32-`, `stage45-`…), source prefixes (`ivvcc-`, `mp-`, `c2-`, `c3-`), mixed PHP/Python/bash, no README, no usage/safety headers | Nobody can tell which scripts are current, safe, or production-authorized; high misfire risk | S10 |
| F-11 | 🟢 | **`.env.example` has a duplicated block (lines 1–6 == 7–12), no trailing newline, inline placeholder app password** | Sloppy first impression; copy-paste errors | S1 |
| F-12 | 🟢 | Local lint PHP is 8.4 (`.local/`), docs say 8.5, container floats | Version ambiguity in the toolchain itself | S12 |

### C. Version & configuration drift

| # | Sev | Finding & evidence | Impact | Std |
|---|---|---|---|---|
| F-13 | 🔴 | **Unreproducible local environment**: `compose.yaml` = `wordpress:latest` + `mysql:latest`; `docs/architecture.md` claims `wordpress:7.0.2-php8.5-apache`; Stage 2 report observed WP 7.1 / PHP 8.3.33 / MySQL 9.7.1 | "Local vs production" debugging is guesswork; docs and reality disagree; any rebuild silently upgrades WordPress | S12 |
| F-14 | 🔴 | **Plugin version drift in docs**: headers say data-model 1.6.0, event-importer 1.7.1, leisure-migration 2.1.0, sponsor-migration 1.1.0, event-runtime 1.2.1, admin-ux 1.0.6 — `docs/project-inventory.md` + `docs/plugins/README.md` still say 1.3.0 / 1.5.0 / 2.0.0 / 1.0.0 / 1.0.0 / 1.0.0; plugins README says "10 custom plugins" (11 exist); root README structure diagram shows 6 | Docs are the project's primary safety rail — stale versions → wrong deploys, wrong agent behaviour | S11 |
| F-15 | 🟡 | **No changelog anywhere** (no CHANGELOG files, no `readme.txt`, version bumps unrecorded) | Can't reconstruct what shipped when; manual ZIP deploys have no audit trail | S12 |
| F-16 | 🟡 | **`docker/entrypoint-fix-permissions.sh` chmods only the theme + `conexao-content`, but `compose.yaml` bind-mounts 11 plugin dirs** | Admin-side updates to the other plugins fail with permission errors — the exact problem the script exists to prevent | S12 |
| F-17 | 🟢 | **`CONEXAO_THEME_VERSION` frozen at 1.0.0** (assets use `filemtime()`, so cosmetic) | Version number carries no meaning | S12 |

### D. Architecture & code organisation

| # | Sev | Finding & evidence | Impact | Std |
|---|---|---|---|---|
| F-18 | 🟡 | **`functions.php` = 4,671 lines** mixing setup, enqueue, customizer, image sizes, homepage queries/caching, template helpers, shortcode, performance dequeues; `inc/polylang.php` 2,072; `inc/seo.php` 1,747 | Change risk concentrates in one file; no ownership boundaries; hard for agents to navigate safely | S6 |
| F-19 | 🟡 | **Business data lives in the theme as code**: `inc/job-resources.php`, `inc/recruitment-agencies.php`, `inc/permit-employers.php` are curated datasets; blog `translation-map.php` = 2,056 lines of content-in-code | Data changes require code deploys; the theme owns content it shouldn't | S6/S9 |
| F-20 | 🟡 | **Translation-rollout pattern is copy-pasted per stage** (page/blog/job/leisure plugins share `apply.php`/`audit.php`/`translation-map.php` lineage) | 4 near-identical plugins to maintain; the next rollout forks again | S9 |
| F-21 | 🟢 | **REST language contract lives in the theme** (`inc/rest-language.php`, 729 lines) | A theme change can silently alter a public API contract | S7 |
| F-22 | 🟢 | Cache keys (`conexao_home_*`, `conexao_404_*`) are per-feature hand-rolled (language-scoped correctly ✔) | Each new cached query re-implements scoping/invalidation | S6 |

### E. Testing

| # | Sev | Finding & evidence | Impact | Std |
|---|---|---|---|---|
| F-23 | 🔴 | **Harness duplication**: `t_assert()` copy-pasted into 9 theme test files (`t_section()` in 7); `scripts/test-*.php` uses a different `check()` helper; wp-load bootstrap duplicated in every file | Fixing the harness means editing N files; new tests copy the nearest file and diverge further | S5 |
| F-24 | 🔴 | **No test runner**: each test is executed by hand (`docker compose exec … php <file>`); no discovery, no aggregate exit code | Suites are skipped under time pressure; CI has nothing to call (see F-01) | S5 |
| F-25 | 🟡 | **Test locations inconsistent**: theme `tests/`, 4 plugin `tests/` dirs, `scripts/test-*.php`; importer `tests/` mixes real tests, probes, fixtures, JSON baselines and 13 committed `.log` run outputs | Nobody knows the full suite; logs masquerade as tests; ZIP builds guess what to exclude | S5 |
| F-26 | 🟢 | **No redirect/`.htaccess` regression tier** — HTTP verifiers exist as per-stage one-offs (`stage2-http-verify.sh`, `stage33-http-verify.py`, `nav-regression-http-verify.py`…) | The SEO-critical redirect surface is re-verified by rewriting the verifier each time | S5 |

### F. Documentation

| # | Sev | Finding & evidence | Impact | Std |
|---|---|---|---|---|
| F-27 | 🟡 | Docs are excellent but **freshness is voluntary** — F-14 shows version drift; no doc states the full current test suite | The doc system decays exactly where it's most relied on | S11 |
| F-28 | 🟢 | **ADR convention is implicit**: one decision record exists (`CONEXAO_BR_ENGLISH_ARCHITECTURE_DECISION.md`) with no naming/location rule | Future decisions land wherever the author feels like | S11 |
| F-29 | 🟢 | **Plugin translation policy undefined**: 8 plugin `.pot` files, zero plugin `.po/.mo` shipped; only the theme ships translations | Unclear whether plugin gettext is a deliverable or scaffolding | S8 |

### G. Process, security & operations

| # | Sev | Finding & evidence | Impact | Std |
|---|---|---|---|---|
| F-30 | 🟡 | **Branch/release workflow undocumented** — work lands on feature branches (`i18n`); no documented PR/review/merge policy, no release tagging | Doesn't scale to collaborators/agents; can't map production ZIPs to commits | S12/S13 |
| F-31 | 🟢 | **Security posture is good by hand** (ABSPATH guards, nonces, capability checks, late escaping observed; `.htaccess` headers; `Requires Plugins`) but **nothing automated** watches for regressions; third-party checksum verification was a one-off (Stage 2) | One distracted edit away from an XSS/nonce hole; plugin integrity unchecked on updates | S15 |
| F-32 | 🟢 | **No dependency update policy** — Polylang pinned 3.8.9 in docs only; Docker images unpinned (F-13); WP/PHP versions tracked in prose | Upgrades are ad-hoc and unverified | S12/S15 |
| F-33 | 🟢 | **`skills-lock.json` tracks only Flutter skills**; zero WordPress agent skills exist despite AGENTS.md being agent-first | Every agent session re-derives conventions from scratch | S14 |

---

## PHASE 4 — Proposed WordPress engineering standard

Normative rules. Keywords: **MUST** (mandatory), **SHOULD** (default, deviation needs a
written reason), **MAY** (optional). Nothing here requires refactoring working code;
items that touch existing code are flagged "on next change" — apply when the file is
opened for other reasons.

### S1 — Repository configuration files

The following files **MUST** exist at the repository root and be kept current:

| File | Rule | Resolves |
|---|---|---|
| `.editorconfig` | Tabs for `*.php|*.css|*.js` (WPCS), LF, final newline, trim trailing whitespace | F-05 |
| `.gitattributes` | `* text=auto eol=lf`; `export-ignore` for `tests/`, `docs/`, `*.log`, stage workdirs | F-05 |
| `.gitignore` | Add: `.local/`, `stage*-work/`, `*.log`, `tmp-*`, `/vendor/`, `/node_modules/`, coverage output | F-07/F-08 |
| `composer.json` | **Dev-tools only**: phpcs + `wp-coding-standards/wpcs`, `phpstan/phpstan` + `szepeviktor/phpstan-wordpress`, `phpunit/phpunit` (P2). `composer scripts`: `lint`, `analyse`, `test`. No runtime dependencies — deployable code stays composer-free | F-04 |
| `phpcs.xml.dist` | WordPress-Core + WordPress-Docs + selected WordPress-Extra; text-domain rules per plugin/theme; excludes `*/tests/fixtures/*`, `vendor/` | F-02 |
| `phpstan.neon.dist` | Level 1 → ratchet to 2–3; `wordpress-stubs`; scan `wp-content/` only; baseline file for legacy noise | F-03 |
| `.github/workflows/ci.yml` | See S13 | F-01 |
| `scripts/README.md` | Index + safety classification of every script (see S10) | F-10 |
| `tests/bootstrap.php` (theme) | Shared test harness (see S5) | F-23 |

Rules:
1. `.local/` **MUST NOT** be tracked. Tool binaries are installed by a documented
   command (`composer install` or `scripts/setup-tools.sh`), never committed.
2. `.env.example` **MUST** contain each variable once, end with a newline, and never
   contain real or placeholder secrets — reference where credentials come from instead.
3. No `.php-cs-fixer.php` (PHPCS is the single fixer), no `.wp-env.json` (Compose is
   the single environment), no `package.json` unless S3 is amended.

### S2 — PHP coding standard

1. **WordPress Coding Standards** (tabs, Yoda conditions, brace placement, naming)
   enforced by `phpcs.xml.dist`; CI fails on new violations (legacy baseline allowed,
   ratcheted).
2. **Escape late, escape always**: `esc_html__`/`esc_attr`/`esc_url` at output;
   `sanitize_*` at input; `$wpdb->prepare()` for queries.
3. **Internationalisation**: every user-facing string gettext-wrapped with the owning
   plugin/theme text domain (`conexao-br-irlanda`, `conexao-<plugin>`); one text
   domain per plugin, equal to its slug.
4. Every PHP file **MUST** start with the `ABSPATH` guard and a docblock stating
   purpose + package.
5. Plugin headers **MUST** include `Plugin Name`, `Description`, `Version`,
   `Text Domain`, `Requires PHP`, and `Requires Plugins` when dependent on other
   conexao plugins (pattern already proven by event-importer).
6. Prefix all global functions/classes: `conexao_` / `Conexao_`. No unprefixed globals.
7. Superglobals only through sanitising accessors; nonces + `current_user_can()` on
   every state-changing admin action (existing pattern — now enforced by sniffs).

### S3 — JavaScript & CSS standard

1. **No build step by default.** Vanilla JS/CSS stays the policy. A `package.json` MAY
   be introduced *tooling-only* (stylelint/eslint via `npx`) — but the day it appears,
   it MUST be wired into CI; otherwise keep the zero-dependency stance and document it.
2. CSS **MUST** use the design-system variables (`--color-*`, spacing, type scale) from
   `assets/css/design-system.css`; dark-mode variants go in `dark-mode.css` using the
   existing `[data-theme="dark"]` pattern. No hard-coded colors outside the token file.
3. New CSS files are added to the documented cascade in `conexao_enqueue_scripts()`
   (design-system → header-nav → main → feature → dark-mode); feature CSS in its own
   file (pattern: `leisure.css`, `sponsor.css`), never page-specific hacks.
4. JS stays in deferred files with `filemtime()` versioning; progressive enhancement —
   every interactive feature works as plain links/forms without JS (existing filter
   pattern is the reference).
5. Asset versioning **MUST** stay `filemtime()`-based (pairs with the immutable
   1-year `.htaccess` cache).

### S4 — Static analysis & quality gates

1. `php -l` over all PHP — CI gate (free, already green: 317/317 files pass).
2. PHPCS with the S1 ruleset — CI gate; legacy violations held in a baseline/exclusion
   list that **MUST** shrink, never grow ("ratchet").
3. PHPStan level 1 with WordPress stubs — CI gate; level raised one step per quarter
   until 3. Would already have caught the unreachable code in
   `test-leisure-card-map-action.php` (F-03).
4. Gates run on every push/PR; the optional local pre-commit hook runs `php -l` +
   PHPCS on changed files only, but CI is the authority.

### S5 — Testing standard

Three tiers, all runnable with one command:

| Tier | What | Where | Runner |
|---|---|---|---|
| **T1 Unit/integration** | PHP scripts bootstrapping WordPress, using a SHARED harness | `<theme-or-plugin>/tests/test-*.php` | `scripts/run-tests.sh` |
| **T2 HTTP verification** | Black-box URL/status/redirect matrix against a running site (local or production) | `scripts/verify/http-*.py|sh` (generalized from today's stage one-offs) | `scripts/verify/run-http-verify.sh <base-url>` |
| **T3 Smoke** | `php -l`, ZIP build, plugin activate/deactivate round-trip, archive-200 checks | CI | workflow steps |

Rules:
1. **One harness.** `tests/bootstrap.php` provides wp-load discovery, `t_assert`,
   `t_section`, fixture factories, a cleanup registry and the final `RESULT:` line +
   exit code. Existing files migrate "on next change"; new tests MUST use it (kills
   the 9-copy `t_assert` duplication and the second `check()` harness).
2. **One runner.** `scripts/run-tests.sh` discovers `wp-content/themes/*/tests/test-*.php`
   and `wp-content/plugins/*/tests/test-*.php`, runs each inside the container,
   aggregates pass/fail, exits non-zero on any failure. CI calls exactly this.
3. **Locations.** Tests live in the owning component's `tests/` directory. `scripts/`
   keeps only cross-cutting T2/T3 tooling. Fixtures in `tests/fixtures/`. `.log` run
   outputs and probe scripts MUST NOT be committed (gitignored / deleted after use).
4. **Fixture discipline** (already de-facto — formalise): unique prefixed names
   (`[MAPTEST]` pattern), create-then-delete, never touch existing records, verify
   "no leftovers" at the end.
5. **Stage gates stay.** Every migration/rollout keeps its bespoke verification script
   and report — but shared assertions (language identity, UUID integrity, `_event_status`
   gate, redirect matrix) move into reusable T2 checks instead of being rewritten per stage.
6. P2 evaluation: WordPress core PHPUnit suite for pure unit tests. The harness above
   remains the integration tier either way.

### S6 — Theme architecture standard

1. `functions.php` is a **loader, not a landfill**: setup + `require_once` of `inc/`
   modules. New domains get a new `inc/<domain>.php` (the i18n/polylang/seo pattern).
   On-next-change: split functions.php into `inc/setup.php`, `inc/enqueue.php`,
   `inc/customizer.php`, `inc/homepage.php`, `inc/template-helpers.php`,
   `inc/performance.php` (F-18). Size guidance: one concern per file; >1,500 lines is a
   smell that needs a comment justifying it.
2. **Templates compose from `template-parts/`**; new card/filter UI is a new shared
   part, not inline markup (existing rule — keep).
3. **Data does not live in the theme.** Curated datasets (job-resources, recruitment
   agencies, permit employers — F-19) move to a data plugin (or CPT + meta) on their
   next functional change; the theme reads through accessor functions only.
4. **Caching**: all front-page/expensive queries behind transients, keys scoped
   `conexao_<feature>_<locale>` via a single helper (`conexao_cache_key($name)` reading
   `conexao_current_locale()`), invalidated on save (existing rule 10 — now centralised).
5. Language reads **ONLY** via `conexao_current_locale()` (existing rule 11 — keep).
6. SEO/redirects stay owned by `inc/seo.php` + `.htaccess` (single owner — keep).

### S7 — Plugin architecture standard

1. **One responsibility per plugin**; standard layout:
   `conexao-<name>.php` (bootstrap) · `includes/` · `assets/` · `tests/` · `languages/`
   · `docs/plugins/conexao-<name>.md` (docs are part of the deliverable, per the
   existing documentation-maintenance rule).
2. **Dependencies are declared in code**: `Requires Plugins` header for every
   conexao-plugin dependency (docs table alone is not enough); load-order documentation
   in `docs/plugins/README.md` generated/verified from headers, not by hand.
3. **Environment classification is mandatory in the header docblock**:
   `production-required` (data-model, content, admin-ux, event-runtime) vs
   `local-tooling` (event-importer, migrations) vs `rollout-one-shot` (translation
   plugins: activate → roll out → remove). The build script's activation-order output
   is generated from this classification.
4. Activation hooks are idempotent and never mutate content; deactivation never
   deletes data (existing pattern — keep).
5. **REST/API contracts SHOULD NOT live in the theme.** On its next functional change,
   `inc/rest-language.php` moves to a small `conexao-rest-contract` plugin so the
   public API survives theme changes (F-21). Until then: no new REST surface in the theme.
6. Version bumps follow SemVer-ish intent (patch = fixes, minor = features,
   major = contract change) and **MUST** update the header + docs table + changelog in
   the same commit (S11/S12 enforce).

### S8 — Bilingual / Polylang standard

1. **Single integration layer**: all Polylang coupling stays in `inc/polylang.php`,
   capability-guarded (`function_exists`/`conexao_polylang_active()`), so a
   single-language site behaves identically (existing rule 11 — keep).
2. `conexao_current_locale()` is the only locale read; Polylang is never called
   directly from templates/plugins.
3. Translated post types/taxonomies are declared **in code** (`pll_get_post_types` /
   `pll_get_taxonomies` filters) so environments cannot drift (existing — keep).
4. Taxonomy policy: `conexao_category`/`conexao_tag` are Polylang-translated;
   `conexao_county`/`conexao_town` stay shared proper-noun terms (existing — keep).
5. **Identity rule**: an EN record is a linked translation of the same identity,
   never a second record; `_leisure_uuid`, event `source+source_id` are never
   duplicated or rewritten (existing — keep; enforced by stage gates).
6. **B2 fallback**: untranslated records render as the approved PT substitution set —
   never both languages of one identity on one page (existing — keep).
7. All caches/transients language-scoped (S6.4); all language-dependent URLs built via
   shared helpers.
8. **New translatable surface ⇒ a rollout**, never a manual edit: inventory → authored
   manifest → dry-run → drift-guarded apply → audit → report (S9). Translation content
   lives in data files (JSON), not in PHP maps (F-19).
9. Decide and document the plugin-translation policy (F-29): either ship `.po/.mo`
   for plugin admin strings or declare plugin `.pot` files developer-only catalogs.
10. Polylang stays pinned (currently 3.8.9), installed via WP-CLI, verified with
    `wp plugin verify-checksums`, never vendored into git (existing — keep).

### S9 — Data & migration workflow standard

The project's proven stage workflow becomes mandatory for **any batch data/content
change** (imports, expansions, translation rollouts, backfills):

1. **Stages**: A = audit/inventory (machine-readable JSON) → B = manifest/setup →
   C = pilot on a small subset → D = full rollout. Each stage produces a dated report.
2. **Identity rules (mandatory)**: Lazer matched by stable UUID → slug → title;
   events by `source + source_id` → export UUID → URL → content hash; **never**
   WordPress post/attachment IDs as portable identifiers (existing rule 2).
3. Every apply tool **MUST** support `--dry-run`, be idempotent (safe re-run), and
   write an audit log (applied/skipped/refused + reasons).
4. **Drift guards**: refuse to overwrite a record whose current source values differ
   from the manifest's expectations (the Stage 7 PT-drift guard is the reference).
5. **Reversibility**: every rollout ships a rollback path (e.g. the translation
   plugins' Remove action) or documents why it is append-only.
6. Production changes go through the admin UI importer or REST scripts with
   application passwords; **no hand-written SQL migrations** (none exist — keep).
7. No localhost URLs in production data (existing rule 3); attribution/license meta
   (`_leisure_image_*`) is preserved by every image touch (existing rule 8).
8. **Converge the tooling**: the four translation plugins (page/blog/job/leisure)
   share one structure — on the next rollout, extract a shared `includes/` rollout
   toolkit (audit/apply/manifest/drift-guard) instead of forking a 5th copy (F-20).
9. Working artifacts (inventories, HTTP caches, before/after matrices) live in a
   gitignored workdir (`stage-work/` or `.work/`); only the final report + canonical
   manifest are committed, under `docs/reports/<area>/` (S11).

### S10 — Scripts & tooling standard

1. `scripts/` is organised by intent:
   `scripts/build/` (zips) · `scripts/seed/` · `scripts/migrate/` (stage workflows) ·
   `scripts/verify/` (HTTP/T2) · `scripts/test/` (runner + cross-cutting tests) ·
   `scripts/restore/` · `scripts/data/`.
2. Every script **MUST** have a header block: purpose, usage, environment
   (local-only / production-safe), destructive? (yes/no), and rollback notes.
   `scripts/README.md` indexes them with the same fields.
3. Naming: `<domain>-<action>.<ext>` (e.g. `leisure-export-zip.php`); stage tooling
   `stage<N>-<action>.<ext>`; **no `tmp-*`, no personal prefixes** — throwaway probes
   go in the gitignored workdir and are deleted with it.
4. PHP scripts run via `wp eval-file` inside the container; Python scripts are
   standard-library only (production has no pip) and take `--dry-run` first;
   bash scripts use `set -euo pipefail` (existing build scripts are the reference).
5. One command to find everything: `scripts/run-tests.sh` (S5), `scripts/verify/`
   (T2), `./scripts/build/*.sh` (release) — mirrored in `docs/development.md`.

### S11 — Documentation standard

1. **Locations**: canonical docs in `docs/` (architecture, routing, content-model,
   frontend, development, deployment, testing [new], conventions [new]);
   per-plugin docs in `docs/plugins/`; theme doc in `docs/themes/`;
   **all reports in `docs/reports/<area>/`** — the repository root holds only
   `README.md`, `AGENTS.md` and the *current* programme-level ADR. The 19 existing
   root reports move under `docs/reports/english/` and `docs/reports/fixes/` with
   an index (F-09); old links are cheap to fix (docs-only).
2. **ADRs**: `docs/adr/NNNN-<slug>.md` (the English architecture decision becomes
   `0001`). Any decision that changes an architecture rule needs an ADR.
3. **Freshness gates** (CI): (a) plugin header versions == `docs/project-inventory.md`
   == `docs/plugins/README.md`; (b) plugin count/slugs == `wp-content/plugins/`;
   (c) AGENTS.md plugin table matches reality; (d) compose image tags ==
   `docs/architecture.md` claims. Drift = build failure (F-14).
4. Every feature/migration change updates its docs in the same change (existing
   documentation-maintenance rule — now with the CI teeth above).
5. Report format stays the stage-report convention (status, gates, evidence,
   scope-limited items called out) — it is one of this repo's best assets.

### S12 — Deployment & release standard

1. **Pin the local platform**: `compose.yaml` pins `wordpress:<major.minor-patch>-php<8.x>-apache`
   and `mysql:<major.minor>` (digest optional); `docs/architecture.md` is updated in
   the same commit; upgrades are deliberate PRs with the T2 HTTP matrix green (F-13).
2. Keep ONE local environment (Compose). The mu-plugin pattern for local-only fixes
   stays (good). Fix the entrypoint to chmod every bind-mounted dir (F-16) — or
   generate the chmod list from the compose mount list.
3. **Release = tag + ZIPs + notes**: every production deploy is a git tag
   (`release/YYYY-MM-DD-<area>`), ZIPs built from the tag, and a short release note
   (what/where/rollback) appended to `docs/deployment.md` or a `CHANGELOG.md` (F-15).
4. ZIP builds keep excluding tests/fixtures/VCS (current behaviour) and stamp each
   ZIP with plugin-header version; build output prints the activation order from the
   S7.3 classification.
5. Post-deploy verification: one generalised T2 script
   (`scripts/verify/run-http-verify.sh https://conexaobr.ie`) covering archives,
   redirects, `/en/` behaviour, sitemap — replacing per-stage rewrites; the existing
   10-step checklist in `docs/deployment.md` calls it.
6. Backups: production backups remain UpdraftPlus (platform-side);
   `scripts/restore-updraft-db.sh` remains the only sanctioned local restore —
   document that split in `docs/deployment.md` (it currently lives in development.md).
7. Third-party plugins on production: installed pinned, checksum-verified, recorded
   in `docs/deployment.md` (Polylang 3.8.9 today) (F-32).

### S13 — CI standard (GitHub Actions)

`.github/workflows/ci.yml` on every push + PR:

| Job | Steps | Gate |
|---|---|---|
| `lint-php` | `php -l` all PHP files | fail on error |
| `phpcs` | composer install → PHPCS ruleset | fail on NEW violations (ratchet) |
| `phpstan` | PHPStan level from S4 | fail on NEW errors (baseline) |
| `tests` | docker compose up → `scripts/run-tests.sh` → compose down | fail on any test failure |
| `build` | `build-plugins-zip.sh` + `build-theme-zip.sh`; upload `dist/` as artifacts; verify zips contain no `tests/` | fail on build error |
| `docs-drift` | S11.3 checks (versions, plugin list, image pins) + `.env.example` duplicate check | fail on drift |

Optional: `pre-commit` config for local runs; Dependabot/Renovate for Docker image
+ composer dev-tool bumps (P2). Branch policy documented: feature branch → PR with
green CI → merge to main (F-30); the repo's default branch protection requires CI.

### S14 — AI-agent skills standard

1. Keep `AGENTS.md` as the orientation entry point (it is excellent); keep it short —
   depth in `docs/` (existing rule).
2. Author **repo-local WordPress skills** (under the agents skills directory the
   lockfile already references; `.agents/skills/` stays the install location):
   - `wp-add-cpt-or-taxonomy` (data-model rules, load order, docs update)
   - `wp-add-template-part` (design-system reuse, dark-mode, i18n wrapping)
   - `wp-write-integration-test` (S5 harness + fixture discipline)
   - `wp-run-quality-gates` (php -l, phpcs, phpstan, run-tests.sh, drift checks)
   - `wp-polylang-rollout` (S8/S9 stage workflow, identity rules, drift guards)
   - `wp-build-release` (S12 tag + ZIP + verify)
   - `wp-docs-sync` (S11 freshness rules)
   Each skill cites the docs it must read first (mirroring AGENTS.md's task table).
3. `skills-lock.json` gains the WP skills alongside the existing Flutter entries —
   **Flutter entries are not modified** (out of scope).
4. Agent-facing rules that are already in AGENTS.md (architecture rules 1–11) stay
   there; CI enforces the enforceable subset so agents get mechanical feedback.

### S15 — Security & performance baseline

1. Keep and enforce: nonces + capability checks on writes; escape-late; prepared SQL;
   `ABSPATH` guards; `Requires Plugins`; no secrets in git (`.env` gitignored;
   application passwords via environment only).
2. PHPCS security-relevant sniffs (WordPress-Extra subset) are part of the S2 ruleset.
3. `.htaccess` security headers (`X-Content-Type-Options`, `X-Frame-Options`,
   `Referrer-Policy`) stay; changes verified by the T2 HTTP matrix.
4. Upload limits/entrypoint/mu-plugin local deviations are documented in one place
   (`docs/development.md` — mostly true today).
5. Performance invariants (already true — now testable): homepage transients
   language-scoped + invalidated on save; `filemtime()` asset versioning; hero LCP
   preload pattern; `loading="lazy"` below the fold; no new render-blocking CSS in
   `<head>` without a documented reason.

---

## PHASE 5 — Implementation roadmap

Sequenced so each step is small, additive and independently revertible. Effort assumes
one maintainer/agent session as the unit.

### P0 — Hygiene + first gates (≈2–3 sessions; zero code changes)

| # | Action | Resolves |
|---|---|---|
| P0-1 | Untrack `.local/`; extend `.gitignore` (`.local/`, `stage*-work/`, `*.log`, `tmp-*`); delete committed `.log` files and `scripts/tmp-*.php` (they are superseded probes) | F-07, F-08, F-10 |
| P0-2 | Move `stage*-work/` artifacts: final reports/manifests → `docs/reports/<area>/`; caches deleted; add workdir policy to docs | F-08, F-09 |
| P0-3 | Fix `.env.example` (dedupe, newline, no placeholder secrets) | F-11 |
| P0-4 | Pin `compose.yaml` images; sync `docs/architecture.md`; fix entrypoint chmod list (or derive from mounts) | F-13, F-16 |
| P0-5 | Add `.editorconfig`, `.gitattributes` | F-05 |
| P0-6 | Extract shared test harness `tests/bootstrap.php`; write `scripts/run-tests.sh`; adopt in ALL existing tests (mechanical edit) | F-23, F-24 |
| P0-7 | GitHub Actions: `lint-php` + `tests` + `build` jobs | F-01 |
| P0-8 | Sync versions: plugin headers ↔ `docs/project-inventory.md` ↔ `docs/plugins/README.md` ↔ README plugin list; add CI `docs-drift` job | F-14 |

### P1 — Tooling + standards codification (≈3–5 sessions)

| # | Action | Resolves |
|---|---|---|
| P1-1 | `composer.json` (dev tools) + `phpcs.xml.dist` + `phpstan.neon.dist` with legacy baselines; CI jobs wired with ratchet | F-02, F-03, F-04 |
| P1-2 | Reorganise `scripts/` per S10 (dirs + headers + `scripts/README.md`); retire one-offs already covered by reports | F-10 |
| P1-3 | Move 19 root reports → `docs/reports/`; create `docs/adr/` (0001 = English decision); add `docs/testing.md` + `docs/conventions.md` (S2–S9 distilled) | F-09, F-28 |
| P1-4 | Generalise T2 HTTP verify (`scripts/verify/`) from the stage one-offs; wire into deployment checklist | F-26 |
| P1-5 | Release process: tag + changelog + ZIP version stamping; document branch policy | F-15, F-30 |
| P1-6 | Decide plugin-translation policy (F-29) and JS/CSS tooling policy (S3.1); record both as ADRs | F-29, F-06 |

### P2 — Structural improvements (ongoing, "on next change")

| # | Action | Resolves |
|---|---|---|
| P2-1 | Split `functions.php` into `inc/` modules (S6.1) when next touched | F-18 |
| P2-2 | Move jobs-hub datasets out of the theme (data plugin or CPT) when next changed | F-19 |
| P2-3 | Extract shared translation-rollout toolkit; 5th rollout uses it | F-20 |
| P2-4 | Move REST language contract to `conexao-rest-contract` plugin when next changed | F-21 |
| P2-5 | Author the S14 WordPress agent skills; wire `skills-lock.json` | F-33 |
| P2-6 | Evaluate WP core PHPUnit suite for pure units; PHPStan level 2→3 ratchet | S4, S5.6 |
| P2-7 | Dependabot/Renovate for Docker + dev-tool pins; optional pre-commit hooks | F-32 |

### Explicitly NOT recommended

- ❌ Rewriting the theme, migrating to a block/FSE theme, or adopting a JS framework.
- ❌ Composer-managed runtime plugins / Packagist-style deployment — production is
  WordPress.com manual ZIP upload; the current build matches the platform.
- ❌ `.php-cs-fixer` alongside PHPCS (two fixers fighting), `wp-env` alongside Compose
  (two environments drifting).
- ❌ Refactoring working importer/migration plugins that already have gate evidence —
  standardise their *interfaces* (S9), not their internals.

---

## Appendix A — Evidence index

Key verifiable facts behind the findings (re-checkable with one command each):

| Fact | How verified |
|---|---|
| No CI, no hooks | `ls .github` → absent; `.git/hooks` = samples only |
| No composer/phpcs/phpstan/phpunit/editorconfig/gitattributes | file-existence sweep at repo root |
| 317 PHP files, all lint-clean | `find -name '*.php' \| xargs php -l` (PHP 8.4) |
| Unreachable code example | `theme/tests/test-leisure-card-map-action.php:292` (after `exit()` at :290) |
| 9× `t_assert`, 7× `t_section` redefinitions | `grep -rl 'function t_assert' wp-content scripts` |
| Second harness family | `scripts/test-event-crud.php` `check()` helper |
| compose `:latest` images vs docs | `compose.yaml:19` (`wordpress:latest`), `docs/architecture.md:11` (7.0.2-php8.5-apache), `CONEXAO_BR_ENGLISH_STAGE_2_REPORT.md` §2 (WP 7.1 / PHP 8.3.33 observed) |
| Plugin version drift | header `Version:` values vs `docs/project-inventory.md` / `docs/plugins/README.md` tables |
| "10 plugins" vs 11 dirs | `docs/plugins/README.md:3` vs `ls wp-content/plugins/` |
| `.local/` toolchain tracked | `git ls-files .local` (2 entries, 12 MB tarball) |
| 551 ephemeral files tracked | `git ls-files stage*-work stage41-rest-matrix.json \| wc -l` |
| 14 `.log` files tracked; 13 in importer tests | `git ls-files \| grep '\.log$'`; `ls conexao-event-importer/tests/` |
| 19 root reports | `ls CONEXAO_BR*.md \| wc -l` |
| Entrypoint chmods 2 dirs vs 11 mounts | `docker/entrypoint-fix-permissions.sh` vs `compose.yaml` volumes |
| 21 scripts with dry-run support | `grep -l 'dry-run\|dry_run' scripts/*` |
| Largest files | `wc -l` sweep (`functions.php` 4,671; `inc/polylang.php` 2,072; `inc/seo.php` 1,747; blog `translation-map.php` 2,056) |
| ZIP builds exclude tests | `scripts/build-plugins-zip.sh` `-x "*/tests/*"` |
| `Requires Plugins` in use | `conexao-event-importer` and `conexao-event-runtime` headers |

## Appendix B — Scope & constraints honoured

- **WordPress website only.** Flutter, the Flutter repository, the mobile app, mobile
  tests/docs/architecture and mobile REST clients were not inspected or modified.
  `skills-lock.json`'s Flutter entries are noted for context only and remain untouched.
- **Audit only — no implementation.** No PHP/templates/CSS/JS/plugins were modified;
  no files renamed or moved; no directories restructured; no database/content,
  Polylang, URL, REST-contract or deployment changes; no tests deleted; no working
  architecture rewritten. The sole repository change is this document.
- Production (https://conexaobr.ie/) was not contacted; all observations are from the
  repository and its committed artifacts.

## Appendix C — Glossary of referenced conventions

- **Stage workflow** — the audit→manifest→pilot→rollout pattern with gate reports,
  visible across `CONEXAO_BR_ENGLISH_STAGE_*_REPORT.md` and `docs/importers/`.
- **B2 fallback** — approved Portuguese-substitution rendering for untranslated
  records (never a duplicate identity); see the Stage 2 report.
- **Identity fields** — `_leisure_uuid`/`_leisure_export_uuid` (Lazer),
  `_event_source` + `_event_source_id` + `_event_export_uuid` (events).
- **Ratchet** — a CI baseline that may only shrink: legacy violations are recorded
  once; any *new* violation fails the build.

---

*End of audit. Proposed next step: approve/adjust Phase 4, then execute P0 as a
single hygiene PR (no production impact).*
