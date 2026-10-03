# WordPress Engineering Standard (WP-ES)

| | |
|---|---|
| **Status** | **Proposal — not yet enforced.** Produced by the [2026-09-25 standardisation audit](audit/2026-09-25-wordpress-engineering-standardisation-audit.md). Adoption is sequenced in §15. |
| **Version** | 1.0 (proposed) |
| **Scope** | The WordPress website repository (`wp-content/`, `scripts/`, `docker/`, `docs/`, build/deploy). **Flutter/mobile is explicitly out of scope.** |
| **Audience** | Maintainers, contributors and AI agents working on this repository |
| **Enforcement levels** | **MUST** — breaking this is a defect · **SHOULD** — deviation requires a written reason in the PR/report · **MAY** — optional improvement |
| **How to use** | Read §0 for principles, then the section for your change type. §14 is the checklist every change must pass. §15 states what to adopt when. |

This document is the single normative contract for engineering work in this repository. Where an existing document (`AGENTS.md`, `docs/routing.md`, `docs/content-model.md`, plugin docs, stage reports) disagrees with this standard, **this standard wins** and the other document MUST be corrected in the same change. It encodes and formalises practices already proven in the Stage 1–9 English rollout; it does not invent a new architecture.

---

## 0. Change-safety principles (apply to every change)

1. **Portuguese is canonical.** PT slugs, PT URLs and PT record identity are never renamed as a side effect of an EN change.
2. **English is a layer, never a fork.** An EN record is a *linked translation* of the same identity. Two identities for the same thing is a defect.
3. **Plan before write.** Anything that writes content, meta, terms or media MUST support a dry run that prints a machine-readable plan (created / updated / skipped, and why).
4. **Portable identifiers only.** Never use local WordPress post/attachment IDs as cross-environment identity. Use `_leisure_uuid` / `_leisure_export_uuid`, event `source + source_id + export UUID`, or an authored stable key. Slug/title matches are allowed only as a *reported fallback* (count and print them).
5. **PT originals are immutable in an EN change.** After an EN rollout, verify PT records are unchanged (title, slug, status, date, meta). A change that must alter PT content is a different change with its own plan.
6. **Every rollout ends with a numeric gate.** E.g. `eligible public PT guides missing EN = 0`. A gate that cannot be expressed as a number MUST be re-framed until it can.
7. **Snapshots before writes; rollback documented.** Capture the affected dataset (or at least counts + identifiers) before applying and state the rollback action in the report.
8. **No localhost URLs, no hotlinked media, no secrets in the repo.** Production data never contains a local URL; images live in the Media Library; credentials come from `.env`/environment variables.
9. **Language-scoped caches.** Every transient/object-cache key derived from content MUST be language-scoped (`conexao_lang_cache_key()`), and invalidation MUST clear every language.
10. **Two places is a bug.** Any list that must stay in sync (plugin load order, activation order, build list, compose mounts, version numbers) MUST be generated from one source (`plugins.json`) or checked by CI.
11. **Behaviour-preserving refactors carry no feature change.** File moves, module splits and renames MUST NOT be mixed with behaviour changes in the same commit.
12. **Production has no CLI.** Any capability operated on production MUST expose an admin screen (and MAY additionally provide WP-CLI and/or REST paths).
13. **Every change is verifiable.** No change is complete without an executable verification (test, HTTP matrix row, or gate) that fails when the change is reverted.

---

## 1. Repository configuration files

These files MUST exist at the repository root. Proposed contents below; adoption order is in §15.

### 1.1 `.editorconfig` (MUST)

```ini
root = true

[*]
charset = utf-8
end_of_line = lf
insert_final_newline = true
trim_trailing_whitespace = true
indent_style = tab
indent_size = 4

[*.md]
trim_trailing_whitespace = false

[*.{yml,yaml,json}]
indent_style = space
indent_size = 2

[*.{sh,bash}]
indent_style = space
indent_size = 4
switch_case_indent = true

[*.py]
indent_style = space
indent_size = 4

[Makefile]
indent_style = tab
```

Rationale: theme/plugin PHP already uses hard tabs (WordPress-Extra style); this makes it explicit and stops editors re-indenting.

### 1.2 `.gitattributes` (MUST)

```gitattributes
* text=auto eol=lf

*.php   text
*.json  text
*.md    text
*.yml   text
*.yaml  text
*.sh    text

*.png  binary
*.jpg  binary
*.jpeg binary
*.webp binary
*.svg  text
*.mo   binary
*.zip  binary
*.tar.gz binary
```

### 1.3 `.gitignore` (MUST — extend the current file)

```gitignore
# Local environment / secrets
.env
.env.local

# Build output
dist/

# Tooling caches
vendor/
node_modules/
__pycache__/
*.pyc
phpcs.cache
.phpunit.result.cache

# Evidence / work trees (curated copies belong in docs/evidence/)
*-work/
*.body
*.log
.local/

# Editors / OS
.idea/
.DS_Store
Thumbs.db

# Agent skill material that is not this repository's domain
.agents/skills/
```

> **Rule:** curated migration payloads and evidence MUST live in `content-inventory/` or `docs/evidence/<date>-<stage>/` (tracked), never in the ignored `dist/`.

### 1.4 `composer.json` — dev tooling only (MUST)

Composer is **never** deployed (production is WordPress.com). It exists only for local/CI static analysis.

```json
{
  "name": "abalzan/conexao-br-irlanda-dev",
  "description": "Development-only tooling for the Conexão BR Irlanda WordPress site. Not deployed to production.",
  "type": "project",
  "license": "GPL-2.0-or-later",
  "require-dev": {
    "dealerdirect/phpcodesniffer-composer-installer": "^1.0",
    "wp-coding-standards/wpcs": "^3.1",
    "phpcompatibility/phpcompatibility-wp": "^2.1",
    "szepeviktor/phpstan-wordpress": "^2.0",
    "phpstan/phpstan": "^2.2"
  },
  "config": {
    "allow-plugins": {
      "dealerdirect/phpcodesniffer-composer-installer": true
    },
    "sort-packages": true
  },
  "scripts": {
    "lint": "phpcs",
    "lint:fix": "phpcbf",
    "analyse": "phpstan analyse --memory-limit=1G",
    "check": ["@lint", "@analyse"]
  }
}
```

### 1.5 `phpcs.xml.dist` (MUST)

```xml
<?xml version="1.0"?>
<ruleset name="Conexao BR Irlanda">
  <description>Coding standard for the Conexão BR Irlanda theme and custom plugins.</description>

  <file>wp-content/themes/conexao-br-irlanda</file>
  <file>wp-content/plugins</file>

  <exclude-pattern>*/tests/*</exclude-pattern>
  <exclude-pattern>*/vendor/*</exclude-pattern>
  <exclude-pattern>*/node_modules/*</exclude-pattern>

  <arg name="extensions" value="php"/>
  <arg name="basepath" value="."/>
  <arg name="parallel" value="8"/>
  <arg value="ps"/>

  <config name="minimum_wp_version" value="6.4"/>
  <config name="testVersion" value="8.0-"/>

  <rule ref="WordPress-Extra"/>
  <rule ref="WordPress-Docs"/>
  <rule ref="PHPCompatibilityWP"/>

  <rule ref="WordPress.WP.I18n">
    <properties>
      <property name="text_domain" type="array">
        <element value="conexao-br-irlanda"/>
        <element value="conexao-data-model"/>
        <element value="conexao-content"/>
        <element value="conexao-admin-ux"/>
        <element value="conexao-event-runtime"/>
        <element value="conexao-event-importer"/>
        <element value="conexao-leisure-migration"/>
        <element value="conexao-sponsor-migration"/>
      </property>
    </properties>
  </rule>
</ruleset>
```


### 1.6 `phpstan.neon.dist` (SHOULD)

```neon
includes:
    - vendor/szepeviktor/phpstan-wordpress/extension.neon

parameters:
    level: 5
    paths:
        - wp-content/themes/conexao-br-irlanda
        - wp-content/plugins
    excludePaths:
        - wp-content/themes/conexao-br-irlanda/tests/*
        - wp-content/plugins/*/tests/*
    bootstrapFiles:
        - %currentWorkingDirectory%/docs/dev/phpstan-bootstrap.php
```

`docs/dev/phpstan-bootstrap.php` (new) defines the constants the theme expects outside WordPress (`ABSPATH`, `CONEXAO_THEME_DIR`, `CONEXAO_THEME_URI`) and stubs the WordPress functions PHPStan cannot resolve. Legacy violations are recorded in `phpstan-baseline.neon`, which MUST NOT grow.

`phpstan.neon.dist` also carries a single documented `ignoreErrors` entry for
`requireOnce.fileNotFound`. WordPress core is not committed to this
repository, so `require_once ABSPATH . 'wp-admin/...'` cannot be resolved on
disk; the requires are correct WordPress and MUST NOT be "fixed" by pointing
them elsewhere.

### 1.7 `.github/workflows/ci.yml` (MUST)

```yaml
name: CI

on:
  push:
    branches: [master, i18n, 'stage*']
  pull_request:

jobs:
  static:
    name: Static analysis
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v5
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'
          tools: composer
      - run: composer install --no-interaction --no-progress
      - name: PHP syntax check
        run: find wp-content -name '*.php' -print0 | xargs -0 -n1 -P8 php -l > /dev/null
      - run: composer lint
      - run: composer analyse
      - name: Shell lint
        run: sudo apt-get update && sudo apt-get install -y shellcheck && shellcheck scripts/*.sh
```

GitHub Actions MUST be referenced at a release whose `runs.using` is `node24`.
Node 20 was removed from GitHub-hosted runners (final removal 2026-09-23), so
an action that still declares `node20` can no longer run natively and MUST NOT
be added. The approved set is `actions/checkout@v5`, `actions/cache@v5` and
`actions/upload-artifact@v6`; each is the lowest major of that action that
targets `node24` while keeping the previous major's input names and defaults,
so no workflow input semantics change. Upgrading to a *higher* major than this
list is not a routine maintenance step: it requires its own change, because a
newer major may add or alter inputs.

The `integration` job (boot `compose.yaml`, seed, run `scripts/run-tests.sh`) is a **required blocking check** on `push`, `pull_request` and `workflow_dispatch`, alongside `static` and `release-integrity`.

The requirement for deterministic fixtures is **not** waived — it was satisfied, not removed. The job was `workflow_dispatch`-only until the content-dependent suites could run against a committed deterministic synthetic site rather than an empty database, where they would otherwise 404 on pagination or pass *vacuously*. Promotion was earned on two consecutive fresh hosted `workflow_dispatch` runs (GitHub Actions runs 36575383129 and 36579787054, branch `i18n`) that completed with no manual intervention, and the job now blocks every push and pull request. Determinism MUST continue to come from committed fixtures: never from a committed database dump and never from weakening an assertion. The job retains ordinary CI failure semantics, so infrastructure failures fail it like any other CI failure.

### 1.8 `plugins.json` — the plugin registry (MUST)

One machine-readable source for load order, build, mounts and documentation.

```json
{
  "load_order": [
    { "slug": "conexao-data-model",        "class": "platform", "status": "active",   "production": true,  "build": true,  "mount": true },
    { "slug": "conexao-content",           "class": "platform", "status": "active",   "production": true,  "build": true,  "mount": true },
    { "slug": "conexao-admin-ux",          "class": "platform", "status": "active",   "production": true,  "build": true,  "mount": true },
    { "slug": "conexao-event-runtime",     "class": "platform", "status": "active",   "production": true,  "build": true,  "mount": true },
    { "slug": "conexao-event-importer",    "class": "tooling",  "status": "active",   "production": false, "build": true,  "mount": true },
    { "slug": "conexao-leisure-migration", "class": "tooling",  "status": "active",   "production": false, "build": true,  "mount": true },
    { "slug": "conexao-sponsor-migration", "class": "tooling",  "status": "active",   "production": false, "build": true,  "mount": true },
    { "slug": "conexao-translation-rollout", "class": "platform", "status": "active",  "production": true,  "build": true,  "mount": true },
    { "slug": "conexao-en-translation",      "class": "tooling", "status": "active",   "production": false, "build": false, "mount": true },
    { "slug": "conexao-translation-automation", "class": "platform", "status": "active", "production": true, "build": true, "mount": true }
  ]
}
```

Rules: `status` ∈ `active | retired`; `retired` rollouts MUST NOT be built into release ZIPs and MUST be documented as "activate → apply → remove"; `production: false` components MUST NOT appear in the production activation order.

The `rollout` class and the `retired` status both still exist for a future
one-shot rollout, but **no entry uses them today**: Stage 19 removed the five
retired rollout plugins (`conexao-page-translation`, `conexao-blog-translation`,
`conexao-job-translation`, `conexao-leisure-translation`,
`conexao-guide-translation`) from the repository after proving their authored
data had been consolidated into the shared stages of `conexao-en-translation`.
There is exactly one translation architecture.

`scripts/generate-registry-docs.php` reads this file and updates: the AGENTS.md plugin table, `README.md`, `docs/plugins/README.md` load order, `build-plugins-zip.sh`'s slug list, and the `compose.yaml` mount list. CI fails when generated output is stale.

### 1.9 Other required-by-convention files

| File | Rule |
|---|---|
| `docs/README.md` | MUST exist as the single documentation index |
| `docs/templates/plan.md`, `docs/templates/report.md` | MUST exist and be used for planned work and stage reports |
| `.github/PULL_REQUEST_TEMPLATE.md` | SHOULD mirror §14 |
| `scripts/README.md` | MUST catalogue every script (purpose, safety level, arguments, last verified) |
| `tests/bootstrap.php`, `tests/lib/assertions.php` | MUST exist before new test suites are added (§8) |
| `CHANGELOG.md` or `docs/releases.md` | MUST record every production release with versions + git SHA |

---


## 2. Coding standards

### 2.1 PHP

| Rule | Level |
|---|---|
| WordPress Coding Standards (WordPress-Extra + WordPress-Docs) as enforced by `phpcs.xml.dist` | MUST |
| Hard tabs for indentation; no trailing whitespace; LF endings; final newline | MUST |
| One supported PHP floor, declared identically in every plugin/theme header and in PHPCS `testVersion` — **recommended `8.0`** | MUST |
| Every loaded PHP file begins with the `ABSPATH` guard (`defined( 'ABSPATH' )` then `exit;`) | MUST |
| Prefixes: `conexao_` (functions), `Conexao_` (classes), `CONEXAO_` (constants) | MUST |
| New public functions carry a WordPress-style docblock (`@param`, `@return`) | MUST |
| No `var_dump`/`print_r`/`error_log` in shipped code (allowed in `tests/`) | MUST |
| Direct `$wpdb` usage only with `$wpdb->prepare()`; prefer WP APIs | MUST |
| Sanitize on input, escape on output (`sanitize_*`, `esc_html*`, `esc_url`, `esc_attr`, `wp_kses_post`) | MUST |
| Never suppress errors with `@` | SHOULD |
| No `extract()`, no dynamic includes from user input, no `eval` | MUST |
| Use `wp_remote_*` for HTTP (never raw `curl`/`file_get_contents` on URLs) | MUST |
| PHPStan level 5, no new baseline entries | SHOULD |

### 2.2 JavaScript

| Rule | Level |
|---|---|
| Vanilla ES2017+; no framework, no bundler, no transpiler | MUST |
| One deferred bundle per concern, enqueued via `wp_enqueue_script()` with `conexao_asset_version()` | MUST |
| No inline `<script>` except the documented theme-detection snippet in `header.php` | SHOULD |
| Progressive enhancement: pages work without JS | MUST |
| Accessibility: `aria-*` on custom widgets, keyboard-operable toggles, visible focus | MUST |
| No jQuery for new code | SHOULD |

### 2.3 CSS

| Rule | Level |
|---|---|
| Design-system variables (`assets/css/design-system.css`) are the only source of colour/spacing/typography tokens | MUST |
| Dark mode reuses/extends `assets/css/dark-mode.css` variables — never page-specific hacks | MUST |
| One component per file for **new** components; enqueue in documented order in `inc/assets.php` | SHOULD |
| No `!important` except documented compatibility shims | SHOULD |
| Class prefix `conexao-` | SHOULD |
| Every new rule is checked in light mode, dark mode and at the mobile breakpoint | MUST |

### 2.4 Shell / Python (tooling)

| Rule | Level |
|---|---|
| Bash: `#!/usr/bin/env bash` + `set -euo pipefail`; all expansions quoted | MUST |
| Bash: shellcheck-clean | SHOULD |
| Python: standard library only (no pip dependencies, no `requests`) | MUST |
| Python: credentials strictly from environment (`WP_USERNAME`, `WP_APPLICATION_PASSWORD`) | MUST |
| Python: target URL from `CONEXAO_SITE_URL` (one documented default) and printed in the run header | MUST |
| Write-capable scripts default to plan-only and require `--apply` | MUST |

---


## 3. Theme standard

### 3.1 Structure

```text
wp-content/themes/conexao-br-irlanda/
├── style.css                 # header only; no rules
├── functions.php             # LOADER ONLY: constants + require_once of inc/*.php in documented order
├── inc/
│   ├── i18n.php              # locale foundation
│   ├── i18n/                 # language policy: guard, locale, urls, terms, fallback, hreflang, switcher
│   ├── rest-language.php     # bilingual REST contract (§7)
│   ├── seo/                  # titles, meta, canonical, og, schema, sitemap, robots, redirects
│   ├── setup.php             # add_theme_support, image sizes, menus, widgets
│   ├── assets.php            # enqueue + conexao_asset_version()
│   ├── performance.php       # dequeue/optimisation, preload/preconnect
│   ├── cache.php             # homepage/404 transients + invalidation
│   ├── content.php           # excerpt, body classes, reading time, share buttons
│   ├── queries.php           # shared query/helper functions (quick access, related, latest)
│   └── <domain>.php          # one concern per file (target ≤ ~400 lines)
├── template-parts/           # all reusable markup
├── assets/css/, assets/js/   # versioned, enqueued in documented order
├── languages/                # catalogues, regenerated by script (§9.3)
└── tests/                    # in-process suites (§8)
```

| Rule | Level |
|---|---|
| `functions.php` holds no logic beyond constants + `require_once` | MUST (target; §15 Stage F) |
| One concern per `inc/` file; a file over ~400 lines MUST be split | SHOULD |
| Template parts for anything rendered in more than one place | MUST |
| Language/slug/URL decisions come from `inc/i18n/` (`conexao_current_locale()`, `conexao_lang_url()`, `conexao_language_archive_url()`, `conexao_lang_term()`) — no ad-hoc language branching elsewhere | MUST |
| SEO output (title/meta/canonical/OG/schema/robots/sitemap) is owned exclusively by `inc/seo/` | MUST |
| Templates contain presentation logic only | SHOULD |
| New theme functionality exposes at least one filter or is marked `@internal` | SHOULD |
| `tests/` MUST NOT ship in the production ZIP | MUST |
| If `style.css` claims Gutenberg support, `theme.json` MUST exist and match that claim (or the claim is removed) | SHOULD |

### 3.2 Performance contract

- A page running ≥3 `WP_Query` calls MUST cache results in language-scoped transients and register invalidation for every CPT it renders.
- The invalidation matrix (post type → transient keys → languages cleared) MUST be recorded in `docs/frontend.md`.
- New images use registered sizes; above-the-fold images set `fetchpriority`/`loading` deliberately.
- Assets MUST be cache-busted via `conexao_asset_version()` (filemtime) — no manual `?v=` strings.

### 3.3 Responsive & accessibility

- New components MUST be verified at the existing mobile/tablet/desktop breakpoints.
- Interactive elements MUST be keyboard-reachable and operable, with a visible focus style.
- Decorative images use empty `alt`; content images describe the subject.

---


## 4. Plugin standard

### 4.1 Plugin classes

| Class | Definition | Active in production? | Examples |
|---|---|---|---|
| **platform** | Registers content types, meta, taxonomies, admin UX and runtime behaviour | Yes — required | data-model, content, admin-ux, event-runtime |
| **tooling** | Import/export/migration machinery operated by maintainers | Only when genuinely needed; typically local-only | event-importer, leisure-migration, sponsor-migration |
| **rollout** | One-shot, auditable content migration with a numeric gate | No — activate, apply, verify, then deactivate/remove | page/blog/job/leisure/guide-translation |

Every plugin doc (`docs/plugins/<slug>.md`) MUST start with: `Class`, `Status` (`active | retired`), `Production`, `Requires Plugins`, `Text domain`, `Admin screen`, `Gate`.

### 4.2 File contract

```text
wp-content/plugins/conexao-<name>/
├── conexao-<name>.php        # plugin header + constants + ABSPATH guard + hooks + includes loader
├── includes/
│   ├── class-conexao-<name>-<concern>.php   # one class per file (preferred)
│   └── <concern>.php                        # procedural module (allowed for rollout data logic)
├── assets/                   # admin/front assets (only if used)
├── languages/                # <textdomain>.pot (+ po/mo when translated)
└── tests/                    # in-process suites (§8), never shipped
```

| Rule | Level |
|---|---|
| Header fields: `Plugin Name`, `Description`, `Version`, `Requires at least`, `Requires PHP`, `Requires Plugins` (when applicable), `Text Domain` | MUST |
| Single bootstrap file; no top-level side effects other than `add_action`/`add_filter` registration | MUST |
| Dependencies declared via `Requires Plugins:` — never by re-registering another plugin's content types | MUST |
| The `leisure` CPT/taxonomies are registered **only** in `conexao-data-model` (no duplicated schema) | MUST |
| Every admin write path verifies a nonce AND a capability before writing | MUST |
| Admin screens live under Tools (rollout/diagnostics) or the CPT menu (editing UX) and provide a dry-run preview before any write | MUST |
| Activation validates dependencies and never writes content | MUST |
| Deactivation removes hooks only and never deletes data | MUST |
| `uninstall.php` only where data deletion is intended; otherwise document "no uninstall by design" | MUST |
| Classes prefixed `Conexao_<Plugin>_<Concern>`, no namespaces; plugin-scoped autoloading preferred over `require_once` ladders | SHOULD |
| New user-facing strings use the plugin's own text domain; the catalogue is regenerated by script | MUST |
| All plugin PHP passes PHPCS with zero new violations | MUST |

### 4.3 Steady-state activation

The authoritative list is `plugins.json` (§1.8). Default: **platform** active; **tooling** local-only or inactive; **rollout** inactive/absent. Deviations MUST be recorded in the plugin doc and in `docs/deployment.md`.

---


## 5. Content-model and data-change standard

### 5.1 Declaring or changing content

| Change | Where it MUST happen | Also MUST update |
|---|---|---|
| New/renamed CPT, taxonomy, rewrite slug | `conexao-data-model` only | `docs/content-model.md`, `docs/routing.md`, `docs/plugins/conexao-data-model.md`, `plugins.json`, a test |
| New/changed meta field | `conexao-data-model` (`register_*_meta`) with `auth_callback` when REST-writable | `docs/content-model.md`, the affected plugin/theme doc |
| New archive/single route | theme template + `inc/i18n/` URL resolvers | `docs/routing.md`, sitemap coverage, an HTTP matrix row |
| New admin editing UI | `conexao-admin-ux` | `docs/plugins/conexao-admin-ux.md` |
| New front-end filter | `inc/queries.php` + the archive template | `docs/routing.md` §Filters, a filter URL builder helper, a test |

Rules: content-model changes MUST be additive/backwards compatible; a rename requires a redirect + migration note; every new CPT/taxonomy MUST be added to the sitemap policy and to the Polylang translated/shared decision (§6).

### 5.2 The content-change contract (MUST for anything that writes content)

Every migration, seed, rollout or bulk edit follows exactly these six steps, whatever the language or implementation:

1. **Inventory** — read-only; produce a machine-readable list of target records with a stable identifier and current relevant state. Kept as evidence.
2. **Manifest / data file** — authored data (translations, mappings, values) lives in a versioned file (`translation-map.php`, JSON…), never inline in the engine.
3. **Dry-run plan** — prints JSON: `create[]`, `update[]`, `skip[]` (with reason), `conflicts[]`. No writes.
4. **Snapshot** — before applying, capture the affected records (identifiers + fields of interest) to prove PT/EN regressions later.
5. **Apply** — idempotent: re-running produces zero changes. Every write reports the match strategy used (stable ID → slug → title) and the count per strategy.
6. **Verify + gate** — completeness audit ending in one numeric gate; PT-unchanged assertion for EN work; result stored in `docs/evidence/<date>-<stage>/gate.json`.

| Rule | Level |
|---|---|
| Steps 1–6 mandatory for content-writing changes | MUST |
| Re-running apply produces no changes (idempotent) | MUST |
| Slug/title matches are printed with counts; matching by local post ID is forbidden | MUST |
| EN work asserts PT records unchanged (fields listed in the report) | MUST |
| The gate result is machine-readable and re-checked by CI for permanent content types | SHOULD |
| Every rollout is reversible (`--remove` or documented rollback) | MUST |
| Production-capable rollouts provide an admin screen with Preview → Apply (+ Remove when reversible) | MUST |

### 5.3 Import/export payloads

- Payloads are self-describing (schema-version field) and validated on import; unknown fields never pass silently.
- Identifiers are portable (UUID / `source + source_id + export UUID` / authored key).
- Media travels with the payload (embedded bytes or a local attachment reference) — never an external hotlink.
- Attribution/license metadata is preserved on import (`_leisure_image_author`, `_license`, `_attribution`, …).
- Import is dry-run-first with a preview table and MUST NOT modify other post types.

---


## 6. Bilingual (Polylang) standard

### 6.1 Non-negotiables

| Rule | Level |
|---|---|
| PT is canonical: PT URLs, slugs and record identity never change as part of an EN change | MUST |
| An EN record is a linked translation of the same identity (never a fork); Event/Lazer identity meta stays language-neutral | MUST |
| Translated post types/taxonomies are declared in code (`pll_get_post_types` / `pll_get_taxonomies` in `inc/i18n/`) | MUST |
| Taxonomy policy: `conexao_category` and `conexao_tag` are Polylang-translated (linked EN terms); `conexao_county` and `conexao_town` are **shared**, one term used by both languages | MUST |
| Filter slugs are language-specific for translated taxonomies (`?categoria=festivais` / `?categoria=festivals`); county/town filters use the one shared slug in both languages | MUST |
| Locale is read only through `conexao_current_locale()` | MUST |
| Cache keys are language-scoped (`conexao_lang_cache_key()`) and invalidation clears all languages | MUST |
| Any fallback rendering (PT record under an EN URL) is explicit, canonical-pointing, excluded from the sitemap and carries the notice | MUST |
| `hreflang` links are emitted only where a real translation relationship or a content-backed archive exists | MUST |
| New EN coverage of a content type ends with the gate `eligible public PT <type> missing EN = 0` (or an explicit, documented allowlist) | MUST |

### 6.2 Procedure — adding EN coverage for a content type

1. **Decide the strategy**: linked EN records (preferred for CPTs/posts/pages) vs. an authored EN field on the same record (as used for Leisure card descriptions — `_leisure_excerpt_en`). Record the decision and rationale in the report.
2. **Author the data** in a versioned manifest (one entry per PT record, keyed by stable identifier + slug).
3. **Register the translation policy** (translated post type + its translated taxonomies; shared taxonomies stay shared).
4. **Build the rollout** on the shared engine (§4.1 `rollout`) with dry-run, PT-drift guard, idempotent apply, `--remove` and an admin screen.
5. **Wire the theme** so EN requests resolve to the EN record/field while PT requests are untouched (URL resolvers, archive filters, breadcrumbs, sitemap, hreflang).
6. **Gate and evidence**: completeness gate = 0, PT-unchanged assertion, HTTP matrix rows (archive / single / filter / pagination / canonical / hreflang / sitemap) stored under `docs/evidence/`.
7. **Document**: `docs/routing.md` §English rollout state, the plugin doc, `docs/content-model.md` if meta changed, `AGENTS.md` if counts changed.

### 6.3 Permanent invariant tests (MUST)

- Taxonomy policy: no suffixed duplicate county/town terms; every translated term is linked both ways.
- Completeness gate per public content type (0 missing, or allowlisted).
- Language-scoped caches: no unscoped `conexao_*` transient/object-cache key.
- Legacy EN→PT redirects still win over newly created EN pages with the same slug.

---

## 7. REST standard

| Rule | Level |
|---|---|
| Bilingual behaviour lives in `inc/rest-language.php` only; no other module filters REST queries | MUST |
| Collection requests accept `?lang=<slug>`; an unknown language returns `400 rest_invalid_param` | MUST |
| Records expose `conexao_language` (`lang`, `is_fallback`, `translations[]`) — stable for the Flutter contract | MUST |
| Fallback records are never presented as translated: `is_fallback = true` and canonical points to PT | MUST |
| Detail endpoints enforce the language gate (a PT slug under an EN request never masquerades as EN) | MUST |
| Public REST exposure is an allowlist (`inc/rest-policy.php`); new endpoints require an explicit decision | MUST |
| Event collections respect `_event_status` exactly as the front end does | MUST |
| Meta writable over REST declares an `auth_callback` | MUST |
| REST contract changes are verified by an HTTP matrix and documented in `docs/routing.md` §English (Stage 4.1) | MUST |
| Contract changes the mobile client depends on are announced in `docs/routing.md` before release | SHOULD |

---


## 8. Testing standard

### 8.1 Two layers, one runner

| Layer | Purpose | Location |
|---|---|---|
| **In-process (PHP)** | Behaviour of functions/queries/policies against a real WordPress | `<component>/tests/test-<area>-<behaviour>.php` |
| **HTTP acceptance (Python/bash)** | What a real request returns (status, canonical, hreflang, redirects, sitemap, filters) | `tests/acceptance/verify-<area>-http.py` |

Both run through `scripts/run-tests.sh` (to be created), which MUST print a per-suite summary and exit non-zero on any failure.

### 8.2 Rules

| Rule | Level |
|---|---|
| Every test file requires `tests/bootstrap.php` — no inline `wp-load.php` discovery | MUST |
| Assertions come from `tests/lib/assertions.php` (`assert_true`, `assert_equals`, `assert_set`, `assert_contains`, `assert_fail`); no bespoke helpers per suite | MUST |
| Tests are read-only unless the name says otherwise; write-capable tests create and remove their own fixtures | MUST |
| A test declares its data prerequisites and fails loudly (`insufficient data: <hint>`) instead of passing vacuously | MUST |
| Every suite prints `N passed, M failed` and exits non-zero on failure | MUST |
| Permanent content invariants (taxonomy policy, completeness gates, cache scoping) run in the default suite | MUST |
| New feature work ships one in-process test + one HTTP matrix addition | MUST |
| Every bug fix ships the test that would have caught it | MUST |
| HTTP matrices are data files validated against one schema (`{id, url, expect_status, expect_contains, expect_absent, note}`) | SHOULD |
| Evidence is stored under `docs/evidence/<date>-<stage>/` (matrices, `gate.json`, PT snapshot) | MUST |
| Heavy fixtures live in `tests/fixtures/` with a documented size budget and are excluded from ZIPs | MUST |

### 8.3 Reference commands

```bash
# After the harness exists
./scripts/run-tests.sh                      # in-process + acceptance
./scripts/run-tests.sh --only theme         # one component
./scripts/run-tests.sh --acceptance         # HTTP layer only

# Today's equivalent (per suite, inside the container)
docker compose exec wordpress php /var/www/html/wp-content/themes/conexao-br-irlanda/tests/test-polylang-foundation.php
./scripts/verify-guides-http.sh
```

---

## 9. Documentation standard

### 9.1 Layout and ownership

**The ownership rule: a procedure has one operational home.** Skills own
*how to do a kind of work*; this table owns *what is true about the project*.
A canonical document that needs to mention a procedure links to the skill — it
does not restate the steps. History is not instruction: a number in a report is
a historical measurement, not a current fact.

| Concern | Owns | Does **not** own |
|---|---|---|
| `.agents/skills/<name>/SKILL.md` | reusable agent workflows, procedural checklists, operational guardrails, verification sequences | versions, route lists, plugin inventories, taxonomy policies — those are read from their owner below |
| `AGENTS.md` | scope, safety rules, the authoritative-source map, skill selection | procedures (they live in skills) |
| `docs/` (canonical) | specifications, policy, architecture, content model, route definitions, release policy, testing semantics | procedures, historical measurements |
| `docs/reports/`, `docs/evidence/`, `docs/audit/` | completed work, audits, measurements, machine evidence | current instructions — never a procedure's home |

| Path | Content | Owner |
|---|---|---|
| `AGENTS.md` | Orientation: project, stack, scope, safety rules, authoritative map, task→skill index | Project maintainer |
| `README.md` | Human entry point: stack, local dev, build, deploy, structure | Project maintainer |
| `.agents/skills/README.md` | The skill index: which skill, when to use it, which canonical docs it depends on | Project maintainer |
| `docs/architecture.md`, `docs/content-model.md`, `docs/routing.md`, `docs/frontend.md`, `docs/deployment.md`, `docs/development.md` | Evergreen reference | Project maintainer |
| `docs/plugins/<slug>.md` | Per-plugin doc (metadata block per §4.1) | Plugin maintainer |
| `docs/themes/conexao-br-irlanda.md` | Theme reference | Theme maintainer |
| `docs/reports/<date>-<stage>-<topic>.md` | Stage/feature history (the root `CONEXAO_*` reports move here) | Change author |
| `docs/evidence/<date>-<stage>/` | Machine-readable proof (matrices, `gate.json`, snapshots) | Change author |
| `docs/audit/` | Audits | Project maintainer |
| `docs/templates/` | `plan.md`, `report.md` | Project maintainer |
| `docs/engineering-standard.md` | This document | Project maintainer |

### 9.2 Rules

| Rule | Level |
|---|---|
| A list that must stay in sync (plugin counts, versions, load order, routes) is generated or CI-checked — never hand-maintained in two places | MUST |
| Every doc ends with `_Last verified: YYYY-MM-DD by <area>_` | MUST |
| A change touching content types, routes, filters, REST, EN coverage or deployment updates the matching docs **in the same commit** | MUST |
| Stage/feature work produces a report from `docs/templates/report.md` containing: scope, files touched, verification (with numbers), PT-integrity result, gate result, rollback, limitations | MUST |
| No root-level report or evidence file is added; use `docs/reports/` and `docs/evidence/` | MUST |
| `AGENTS.md` stays short (< ~250 lines); depth lives in `docs/` and the skills | SHOULD |
| A **procedure** is maintained in exactly one skill; a canonical document links to it instead of restating it | MUST |
| A skill never restates a value that has an authoritative source (versions, load order, routes, taxonomies, script inventory) — it names the source and reads it | MUST |
| A historical report is never rewritten to match a current procedure, and a procedure is never reconstructed from historical reports | MUST |
| A new skill is added to `.agents/skills/README.md` in the same change, so the index never drifts from the directory | MUST |

### 9.3 i18n catalogues (MUST)

```bash
# Regenerate (theme + each plugin with user-facing strings)
wp i18n make-pot wp-content/themes/conexao-br-irlanda \
  wp-content/themes/conexao-br-irlanda/languages/conexao-br-irlanda.pot
wp i18n make-pot wp-content/plugins/conexao-data-model \
  wp-content/plugins/conexao-data-model/languages/conexao-data-model.pot

# Freshness check (CI): fail when a .pot is older than the PHP defining its strings
./scripts/i18n-check.sh
```

The theme's `pt_BR` catalogue is an identity catalogue by design (the source language is `pt_BR`); it MUST be regenerated, never hand-edited.

---


## 10. Scripts and tooling standard

### 10.1 Naming

| Pattern | Meaning |
|---|---|
| `build-<artifact>.sh` | Produces a release artifact |
| `seed-<dataset>.php` | Creates local fixture/demo data (idempotent) |
| `run-<rollout>.php` | Executes a documented rollout/importer |
| `<area>-inventory.php` / `.py` | Read-only inventory → machine-readable output |
| `<area>-verify.*`, `verify-<area>-http.py` | Verification (assertions + exit code) |
| `restore-<source>.sh` | Destructive local restore (must warn loudly) |
| `historical/*` | One-shot stage scripts kept for provenance (not documented as runnable) |
| `diagnostics/*` | Temporary debugging helpers (may be deleted without notice) |

### 10.2 Rules

| Rule | Level |
|---|---|
| Every script is listed in `scripts/README.md` with purpose, safety level, arguments and last-verified date | MUST |
| Every PHP script uses `scripts/lib/bootstrap.php` (single `wp-load.php` resolution + `ABSPATH` + `WP_USE_THEMES=false`) | MUST |
| Every Python script uses `scripts/lib/rest.py` for auth, retries, pagination and the base URL | SHOULD |
| Write-capable scripts support `--dry-run` (default: plan only) and `--apply` | MUST |
| Every run prints: script name, target URL/environment, mode (`dry-run`/`apply`) and a summary line | MUST |
| Evidence output goes to `docs/evidence/<date>-<stage>/`, not the repo root | MUST |
| No secrets in code; read `WP_USERNAME` / `WP_APPLICATION_PASSWORD` from the environment | MUST |
| Scripts never modify content outside their declared scope, and say so in `--help` | MUST |
| A write-capable script is idempotent or refuses to re-run without `--force` | MUST |
| Production-affecting scripts print a warning banner and require `--confirm-production` | SHOULD |

---

## 11. Deployment and release standard

| Rule | Level |
|---|---|
| Build list, mounts and activation order derive from `plugins.json` | MUST |
| `build-plugins-zip.sh` packages every component with `build: true`; `retired` rollouts are excluded | MUST |
| `build-theme-zip.sh` excludes `tests/` and any dev-only file | MUST |
| Each plugin ZIP excludes `tests/`, fixtures, `*.json` reports and OS junk | MUST |
| Every build emits `dist/release.json`: component, version, git SHA, built-at, file count, sha256 | MUST |
| Versions are bumped in the component header and recorded in `CHANGELOG.md` / `docs/releases.md` with the git SHA | MUST |
| The production activation order in `docs/deployment.md` matches `plugins.json` | MUST |
| Every release runs `scripts/verify-deploy.py` (HTTP smoke matrix: homepage, every archive, one single per CPT, `/en/` pairs, canonical, hreflang, sitemap, 404) | MUST |
| Every release has a rollback note (previous ZIPs, previous plugin state, content rollback if a rollout ran) | MUST |
| Production constraints are explicit: no WP-CLI, no SSH, no filesystem access | MUST |
| Local restore is only ever performed with `scripts/restore-updraft-db.sh` (never a raw `mysql < dump`) | MUST |

Normative release sequence:

```bash
./scripts/lint.sh && ./scripts/run-tests.sh     # 1. gates
./scripts/build-plugins-zip.sh                  # 2. build (+ dist/release.json)
./scripts/build-theme-zip.sh
# 3. upload the theme, then the plugins in plugins.json order (platform first)
# 4. activate/deactivate to reach the documented steady state
python3 scripts/verify-deploy.py --site https://conexaobr.ie    # 5. verify over HTTP
# 6. record the release in docs/releases.md (versions + SHA + verification result)
```

---

## 12. Git, review and commit standard

| Rule | Level |
|---|---|
| Branch roles: `master` = production-ready; `i18n` = English-layer integration; feature branches `feat/<slug>`, `fix/<slug>`, `stage<n>-<slug>` | MUST |
| Commit subjects: `<area>: <imperative summary>`; narrative detail belongs in the report | SHOULD |
| One logical change per commit; behaviour-preserving refactors separate from behaviour changes | MUST |
| A commit that writes/touches content includes its dry-run output and verification numbers in the PR body or report | MUST |
| No direct commits to `master`; merge via PR with the §14 checklist | SHOULD |
| Stale merged `cline/*` / backup branches are pruned after merge | SHOULD |
| Force-pushing a shared branch is forbidden; history rewrites require a recorded decision | MUST |

---


## 13. AI-agent standard

### 13.1 Skills

This repository MUST maintain WordPress-domain skills under `.agents/skills/`.
A skill is an **executable workflow**, not a summary, and MUST use the standard
format: **Purpose → When to use → When not to use → Required reading →
Authoritative sources → Preconditions → Steps → Guardrails → Verification →
Failure handling → Evidence and reporting → Definition of done**.

`When to use`, `Required reading`, `Steps`, `Guardrails`, `Verification` and
`Definition of done` are the governed core and MUST appear in that order. Each
skill MUST name its **authoritative sources** (what it reads, and what it must
never restate) and its **failure handling** (what to do when a step does not
produce the expected result). `.agents/skills/README.md` is the index and MUST
list every active skill.

| Skill | Minimum content |
|---|---|
| `wp-repository` | orientation, planning, scope control, reuse of shared engines, definition of done |
| `wp-add-content-type` | data-model registration, taxonomy/sitemap decision, docs, test |
| `wp-add-admin-screen` | capability, nonce, list table, dry-run preview, notices, escaping |
| `wp-content-change` | the §5.2 six-step contract, snapshots, idempotence, numeric gate, rollback |
| `wp-translation-rollout` | §6.2 procedure, shared engine, invariant tests, routing doc update |
| `wp-testing` | what to run, in what order, failure classification, regression comparison, permanent gates |
| `wp-write-in-process-test` | bootstrap + assertions + naming + prerequisites |
| `wp-http-acceptance-matrix` | harness, matrix schema, evidence storage |
| `wp-release-deploy` | §11 sequence, artifact allowlist, manifest, determinism, rollback |
| `wp-production-operations` | WordPress.com constraints, update-in-place, steady state, admin-only capabilities, rollback/roll-forward |
| `wp-update-docs` | change → document map (appendix) + drift checks + the one-operational-home rule |
| `wp-security-review` | nonce/capability/escaping/prepared-statement checklist |
| `wp-frontend-perf` | transient caching + invalidation matrix, enqueue discipline |
| `wp-plugin-registry` | registry fields, generated regions, derived build list |

### 13.2 Rules for agents

| Rule | Level |
|---|---|
| Read `AGENTS.md` + this standard before changing code | MUST |
| Select the skill for the task from `.agents/skills/README.md` and follow it; when a skill and this standard disagree, this standard wins and the skill is corrected in the same change | MUST |
| Use `docs/templates/plan.md` before multi-file work and `docs/templates/report.md` after | MUST |
| Prefer shared engines/helpers over new copies; copying a plugin or script requires a written justification | MUST |
| Never modify PT content, URLs, Polylang configuration, `.htaccess` redirects or REST contracts without explicit instruction | MUST |
| Never introduce a second source of truth for a list (`plugins.json` is authoritative) | MUST |
| Run the verification and paste real numbers into the report — no "should work" | MUST |
| Keep documentation updated in the same change | MUST |
| Never touch the Flutter/mobile repository, app, tests, docs or REST clients from this repository's tasks | MUST |

---

## 14. Definition of done

Every change (human or agent) MUST satisfy all applicable items:

- [ ] Scope matches the request; nothing unrelated refactored.
- [ ] PHPCS + PHPStan pass with no new violations; PHP syntax checked.
- [ ] In-process test added/updated (plus an HTTP matrix row for anything request-visible).
- [ ] **Content writes:** dry-run output captured, apply idempotent, PT-unchanged assertion, numeric gate = 0 (or documented allowlist), rollback documented.
- [ ] **EN work:** §6.1 rules hold (linked translation, canonical, hreflang, sitemap, cache scoping, taxonomy policy).
- [ ] **Routes/filters:** `docs/routing.md` updated + sitemap + redirect precedence verified.
- [ ] **Content model:** `docs/content-model.md` + plugin doc + `plugins.json` updated.
- [ ] **Plugins:** header/version/status updated; build + mounts derived from the registry; steady state stated.
- [ ] **Releases:** `dist/release.json` emitted, versions recorded, deployment verification run, rollback noted.
- [ ] Docs updated in the same commit; no root-level report added; doc ends with `_Last verified_`.
- [ ] No secrets, no localhost URLs, no hotlinked media, no committed build output or work trees.
- [ ] Report written with real verification numbers and stated limitations.
- [ ] Flutter/mobile untouched.

---

## 15. Adoption roadmap and compliance

| Stage | Adopt | Depends on |
|---|---|---|
| A | This standard + the audit; `AGENTS.md` links; doc index | — |
| B | §1.2, §1.3 hygiene (gitignore/gitattributes/cleanup) | A |
| C | §1.1, §1.4, §1.5, §1.6 + `scripts/lint.sh` | B |
| D | §1.7 CI (static) | C |
| E | §8 harness (`tests/bootstrap.php`, `tests/lib/assertions.php`, `scripts/run-tests.sh`); migrate existing suites | B |
| F | §3.1 theme module split | E (regression safety) |
| G | §1.8 registry + generated docs/load order/build/mounts | A |
| H | §4.1 shared rollout engine; §5.2 contract enforcement | E, G |
| I | §10 scripts (`scripts/lib/`, catalogue, flags) | C |
| J | §11 release manifest + `verify-deploy.py` + `docs/releases.md` | G |
| K | §13 skills + `docs/templates/` | A |
| L | §6.3 + §8.2 permanent invariant gates in CI | E, H |

**Compliance statement:** until Stages B–D are complete this standard is *advisory* for existing code and **mandatory for all new work**. New plugins, scripts, tests, migrations and documentation added after adoption MUST follow it, even where the surrounding code does not yet comply.

### Appendix — change → document map

| If you change… | Update… |
|---|---|
| CPT / taxonomy / meta | `docs/content-model.md`, `docs/plugins/conexao-data-model.md`, `plugins.json`, `docs/routing.md` (if routed) |
| Archive / single route / filter | `docs/routing.md`, sitemap behaviour, an HTTP matrix row |
| EN coverage of a content type | `docs/routing.md` §English, the rollout plugin doc, `docs/evidence/`, `AGENTS.md` (if counts change) |
| REST behaviour | `docs/routing.md` §English (Stage 4.1), HTTP matrix |
| Theme structure / template parts / assets | `docs/themes/conexao-br-irlanda.md`, `docs/frontend.md` |
| Transient cache / invalidation | `docs/frontend.md` (invalidation matrix) |
| Plugin added/removed/retired | `plugins.json`, `docs/plugins/README.md`, that plugin's doc, `docs/deployment.md` |
| Build / deploy / activation | `docs/deployment.md`, `docs/releases.md` |
| Script added/renamed | `scripts/README.md` |
| Workflow definition | `AGENTS.md` (short pointer) + this standard |

---

_Last verified: 2026-09-25 by the WordPress engineering standardisation audit (proposal stage — no code changed)._


_Last verified: 2026-09-26 by Stage I — Scripts Standardisation_
_Last verified: 2026-09-30 by the agent skills / documentation migration_
