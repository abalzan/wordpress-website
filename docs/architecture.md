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

Plugins must load in this dependency order:

1. **conexao-data-model** — Registers CPTs, taxonomies, meta fields. Other plugins depend on these types existing.
2. **conexao-content** — Creates static pages and shortcodes. Depends on data-model types for grid shortcodes.
3. **conexao-admin-ux** — Enhances admin UI for all supported types. Depends on data-model types.
4. **conexao-event-runtime** — Production event runtime: event meta registration, the `conexao_town` taxonomy, the `_event_status` gate on public event queries, and the event status admin UI. Depends on the event CPT from data-model. **Required on production.**
5. **conexao-event-importer** — Local-only event aggregation/import/export tooling. Depends on data-model and the event runtime (`Requires Plugins` header). **Never required on production.**
6. **conexao-leisure-migration** — Leisure export/import. Can self-register leisure CPT if data-model is absent (fallback).

## Theme Architecture

The active theme `conexao-br-irlanda` is a custom block-theme-compatible theme:

- **Template hierarchy**: Standard WordPress with `single-leisure.php` for the leisure CPT
- **SEO**: Built into `inc/seo.php` (titles, meta, canonical, OG, schema, sitemap, redirects, robots.txt)
- **CSS**: Design system CSS variables → header-nav → main → leisure → dark-mode (cascading enqueue)
- **JS**: Single `assets/js/main.js` (deferred) — mobile menu, theme toggle, search, leisure filters, copy buttons

## Data Flow

1. **Events**: External sources → importer → normalize → deduplicate → WordPress event CPT → archive/templates
2. **Lazer**: Local data entry + ZIP export/import → WordPress leisure CPT + Media Library images → archive/single templates
3. **Guides/JOBS/Sponsors**: Manual entry via WordPress admin → CPT → archive/templates
4. **Blog**: Standard WordPress posts → `home.php` template

## SEO Architecture

- Built into theme (`inc/seo.php`), no plugin dependency.
- Handles: titles, meta descriptions, canonical URLs, Open Graph, Twitter Cards, schema.org, breadcrumbs, XML sitemap, robots.txt, redirects.
- WordPress core sitemap disabled in favor of custom lightweight sitemap.
- English-to-Portuguese redirects at two levels: `.htaccess` (Apache) and `inc/seo.php` (PHP).