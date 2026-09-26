# Architecture

## Overview

Conexão BR Irlanda is a WordPress-based community portal. The architecture follows standard WordPress conventions with custom post types, shared taxonomies, and a custom theme.

## WordPress Configuration

- **WordPress version**: 7.0.2
- **PHP version**: 8.5
- **Web server**: Apache (Docker: `wordpress:7.0.2-php8.5-apache`)
- **Database**: MySQL
- **Permalink structure**: `/%postname%/`

## Docker Local Architecture

```
compose.yaml
├── db (mysql:latest)
│   └── Mounts: db_data volume
└── wordpress (wordpress:7.0.2-php8.5-apache)
    ├── Ports: 8080 → 80
    ├── Entrypoint: docker/entrypoint-fix-permissions.sh
    ├── Mounts:
    │   ├── .htaccess (ro)
    │   ├── wp-content/themes/conexao-br-irlanda (bind)
    │   ├── wp-content/plugins/conexao-* (bind)
    │   ├── docker/apache-allowoverride.conf (ro)
    │   └── docker/entrypoint-fix-permissions.sh (ro)
    └── wordpress_data volume (core WP files)
```

## Plugin Architecture

<!-- BEGIN GENERATED PLUGIN REGISTRY: docs/architecture.md plugin load order -->
Generated from [`plugins.json`](../plugins.json) by `scripts/generate-registry-docs.php`.
Plugins load in registry order, and dependencies always precede their dependents.

1. **conexao-data-model** (`platform`, active) v1.6.0. **Required on production.**
2. **conexao-content** (`platform`, active) v1.0.0. **Required on production.**
3. **conexao-admin-ux** (`platform`, active) v1.0.6. **Required on production.**
4. **conexao-event-runtime** (`platform`, active) v1.2.1. **Required on production.** Declared dependencies (`Requires Plugins` header): `conexao-data-model`.
5. **conexao-event-importer** (`tooling`, active) v1.7.1. **Never required on production.** Declared dependencies (`Requires Plugins` header): `conexao-data-model`, `conexao-event-runtime`.
6. **conexao-leisure-migration** (`tooling`, active) v2.1.0. **Never required on production.**
7. **conexao-sponsor-migration** (`tooling`, active) v1.1.0. **Never required on production.**
8. **conexao-translation-rollout** (`tooling`, active) v1.0.0. **Never required on production.**
9. **conexao-page-translation** (`rollout`, retired) v1.0.0. Retired rollout tooling - *activate → apply → remove*. Not a production dependency.
10. **conexao-blog-translation** (`rollout`, retired) v1.0.0. Retired rollout tooling - *activate → apply → remove*. Not a production dependency.
11. **conexao-job-translation** (`rollout`, retired) v1.0.0. Retired rollout tooling - *activate → apply → remove*. Not a production dependency.
12. **conexao-leisure-translation** (`rollout`, retired) v1.0.0. Retired rollout tooling - *activate → apply → remove*. Not a production dependency.
13. **conexao-guide-translation** (`rollout`, retired) v1.0.0. Retired rollout tooling - *activate → apply → remove*. Not a production dependency.

The authoritative registry is [`plugins.json`](../plugins.json): the load order, the
production activation order, the release build list and the local Compose mount list are
all derived from it by `scripts/generate-registry-docs.php`.
<!-- END GENERATED PLUGIN REGISTRY: docs/architecture.md plugin load order -->

## Translation Rollout Architecture (Stage H)

```text
shared engine (conexao-translation-rollout)
    ↓  register_stage( config )
stage config (includes/stage-config.php)   ← identity, fields, gate, remove policy
    ↓  manifest_callback
versioned stage data (translation-map.php / data/*.json)   ← authored EN copy
    ↓
inventory → manifest validation → dry-run plan → snapshot → apply → verify + numeric gate
```

| Concern | Owner |
|---|---|
| inventory, dry-run plan, snapshot orchestration, apply traversal, PT-drift guard, verify counters, numeric gate, result formatting, admin capability/nonce flow, remove traversal | `conexao-translation-rollout` (shared engine) |
| authored translated copy, portable stable keys, stage identity and languages, field mapping, eligibility, landing-page verification, remove-safety declaration | the stage plugin |

A retired rollout that has been migrated becomes **data + configuration only**.
`conexao-job-translation` is the first: its `includes/apply.php`,
`includes/audit.php` and per-stage admin class were removed in Stage H, leaving
`includes/translation-map.php` (data), `includes/stage-fields.php` (job field
mapping) and `includes/stage-config.php` (configuration). The other four
retired rollouts (`page`, `blog`, `leisure`, `guide`) still own their historical
orchestration and are unchanged.

Adding a rollout therefore requires **one data manifest + one small stage
config + one gate/test**, and no copied orchestration. See
[`docs/plugins/conexao-translation-rollout.md`](plugins/conexao-translation-rollout.md).

## Theme Architecture

The active theme `conexao-br-irlanda` is a custom block-theme-compatible theme:

- **Template hierarchy**: Standard WordPress with `single-leisure.php` for the leisure CPT
- **Runtime layout**: `functions.php` is a **loader only** (theme constants + `require_once` of the `inc/` modules in a documented order). All theme logic lives in focused `inc/*.php` modules, one concern per file.
- **Language policy**: `inc/i18n.php` (locale foundation, `conexao_current_locale()`) plus `inc/i18n/` (Polylang guard, locale, URLs, terms, B2 fallback, hreflang, switcher)
- **SEO**: Built into `inc/seo/` (titles, meta, canonical, hreflang, OG, schema, sitemap, robots, redirects)
- **REST**: `inc/rest-language.php` is the single owner of the bilingual REST contract
- **CSS**: Design system CSS variables → header-nav → main → leisure → dark-mode (cascading enqueue)
- **JS**: Single `assets/js/main.js` (deferred) — mobile menu, theme toggle, search, leisure filters, copy buttons

See `docs/themes/conexao-br-irlanda.md` §Runtime Architecture for the full module map and load order.

## Data Flow

1. **Events**: External sources → importer → normalize → deduplicate → WordPress event CPT → archive/templates
2. **Lazer**: Local data entry + ZIP export/import → WordPress leisure CPT + Media Library images → archive/single templates
3. **Guides/JOBS/Sponsors**: Manual entry via WordPress admin → CPT → archive/templates
4. **Blog**: Standard WordPress posts → `home.php` template

## SEO Architecture

- Built into theme (`inc/seo/`), no plugin dependency.
- Handles: titles, meta descriptions, canonical URLs, hreflang, Open Graph, Twitter Cards, schema.org, breadcrumbs, XML sitemap, robots.txt, redirects.
- WordPress core sitemap disabled in favor of custom lightweight sitemap.
- English-to-Portuguese redirects at two levels: `.htaccess` (Apache) and `inc/seo/redirects.php` (PHP).
_Last verified: 2026-09-26 by Stage L — Permanent Invariant Gates_
