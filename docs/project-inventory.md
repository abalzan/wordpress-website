# Project Inventory

## Plugins

<!-- BEGIN GENERATED PLUGIN REGISTRY: docs/project-inventory.md plugins -->
Generated from [`plugins.json`](../plugins.json) by `scripts/generate-registry-docs.php`.
Versions are read from the plugin headers at generation time, which is the authoritative
source. Do not hand-edit this table.

| Slug | Version | Class | Status | Production | Build | Mount | Path |
|------|---------|-------|--------|------------|-------|-------|------|
| conexao-data-model | 1.6.1 | platform | active | yes | yes | yes | `wp-content/plugins/conexao-data-model/` |
| conexao-content | 1.0.1 | platform | active | yes | yes | yes | `wp-content/plugins/conexao-content/` |
| conexao-admin-ux | 1.0.7 | platform | active | yes | yes | yes | `wp-content/plugins/conexao-admin-ux/` |
| conexao-event-runtime | 1.2.2 | platform | active | yes | yes | yes | `wp-content/plugins/conexao-event-runtime/` |
| conexao-event-importer | 1.7.1 | tooling | active | no | yes | yes | `wp-content/plugins/conexao-event-importer/` |
| conexao-leisure-migration | 2.1.0 | tooling | active | no | yes | yes | `wp-content/plugins/conexao-leisure-migration/` |
| conexao-sponsor-migration | 1.1.0 | tooling | active | no | yes | yes | `wp-content/plugins/conexao-sponsor-migration/` |
| conexao-translation-rollout | 1.1.0 | platform | active | yes | yes | yes | `wp-content/plugins/conexao-translation-rollout/` |
| conexao-en-translation | 1.5.0 | tooling | active | no | no | yes | `wp-content/plugins/conexao-en-translation/` |
| conexao-translation-automation | 0.1.0 | platform | active | yes | yes | yes | `wp-content/plugins/conexao-translation-automation/` |
| conexao-page-translation | 1.0.0 | rollout | retired | no | no | yes | `wp-content/plugins/conexao-page-translation/` |
| conexao-blog-translation | 1.0.0 | rollout | retired | no | no | yes | `wp-content/plugins/conexao-blog-translation/` |
| conexao-job-translation | 1.0.0 | rollout | retired | no | no | yes | `wp-content/plugins/conexao-job-translation/` |
| conexao-leisure-translation | 1.0.0 | rollout | retired | no | no | yes | `wp-content/plugins/conexao-leisure-translation/` |
| conexao-guide-translation | 1.0.0 | rollout | retired | no | no | yes | `wp-content/plugins/conexao-guide-translation/` |

**Production steady state** (platform, `production: true`) - activate in this order: conexao-data-model -> conexao-content -> conexao-admin-ux -> conexao-event-runtime -> conexao-translation-rollout -> conexao-translation-automation.

**Local-only tooling** (never production): conexao-event-importer, conexao-leisure-migration, conexao-sponsor-migration, conexao-en-translation.

**Retired rollout plugins** (historical tooling, *activate → apply → remove*; not a production dependency and not in any release ZIP): conexao-page-translation, conexao-blog-translation, conexao-job-translation, conexao-leisure-translation, conexao-guide-translation.
<!-- END GENERATED PLUGIN REGISTRY: docs/project-inventory.md plugins -->

## Themes

| Name | Path | Active |
|------|------|--------|
| Conexão BR Irlanda | `wp-content/themes/conexao-br-irlanda/` | Yes |

## Custom Post Types

| Post Type | Slug | Archive | Labels |
|-----------|------|---------|--------|
| Guides | `guide` | `/guias/` | Guias Práticos |
| Events | `event` | `/eventos/` | Eventos |
| Jobs | `job` | `/empregos/` | Empregos |
| Sponsors | `sponsor` | `/apoiadores/` | Apoiadores |
| Course Providers | `course_provider` | `/cursos/` | Cursos |
| Leisure | `leisure` | `/lazer/` | Lazer e Turismo |

## Taxonomies

| Taxonomy | Slug | Hierarchical | Applies To |
|----------|------|--------------|------------|
| Category | `conexao_category` | Yes | All 6 CPTs |
| County | `conexao_county` | Yes | All 6 CPTs |
| Tag | `conexao_tag` | No | All 6 CPTs |
| Town | `conexao_town` | Yes | Events only |

## Important Routes

| Route | Type | Template |
|-------|------|----------|
| `/` | Front page | `front-page.php` |
| `/blog/` | Posts archive | `home.php` |
| `/guias/` | Guide archive | `archive.php` |
| `/eventos/` | Event archive | `archive.php` |
| `/cursos/` | Course provider archive | `archive.php` |
| `/empregos/` | Job archive | `archive.php` |
| `/apoiadores/` | Sponsor archive | `archive.php` |
| `/lazer/` | Leisure archive | `archive.php` |
| `/lazer/{slug}/` | Single leisure | `single-leisure.php` |
| `/{slug}/` | Static pages | `page.php` |
| `/search/` | Search results | `search.php` |

## Cron Jobs

None. Event importing and cleanup are manual, local-only operations (see docs/plugins/conexao-event-importer.md). The production site never fetches from external event sources.

## Shortcodes

| Shortcode | Plugin/Theme | Description |
|-----------|-------------|-------------|
| `[conexao_grid]` | conexao-content | Category-filtered card grid |
| `[conexao_blog_categories]` | conexao-content | Blog category pills |
| `[conexao_course_providers]` | Theme functions.php | Course provider directory |

## Key Scripts

| Script | Purpose |
|--------|---------|
| `scripts/build-plugins-zip.sh` | Package plugins for deployment |
| `scripts/build-theme-zip.sh` | Package theme for deployment |
| `scripts/seed-course-providers.php` | Seed course provider data |
| `scripts/run-event-import.php` | Trigger event import |
| `scripts/run-leisure-migration.php` | Run leisure migration |

## Documentation Index

| Document | Path |
|----------|------|
| README (human) | `README.md` |
| Agent orientation | `AGENTS.md` |
| Architecture | `docs/architecture.md` |
| Content model | `docs/content-model.md` |
| Routing | `docs/routing.md` |
| Frontend/UI | `docs/frontend.md` |
| Development | `docs/development.md` |
| Deployment | `docs/deployment.md` |
| Plugin inventory | `docs/plugins/README.md` |
| Data Model plugin | `docs/plugins/conexao-data-model.md` |
| Content plugin | `docs/plugins/conexao-content.md` |
| Admin UX plugin | `docs/plugins/conexao-admin-ux.md` |
| Event Runtime plugin | `docs/plugins/conexao-event-runtime.md` |
| Event Importer plugin | `docs/plugins/conexao-event-importer.md` |
| Leisure Migration plugin | `docs/plugins/conexao-leisure-migration.md` |
| Sponsor Migration plugin | `docs/plugins/conexao-sponsor-migration.md` |
| Theme inventory | `docs/themes/README.md` |
| Conexão BR Irlanda theme | `docs/themes/conexao-br-irlanda.md` |