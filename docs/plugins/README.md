# Plugins

This project contains 5 custom WordPress plugins. All are in `wp-content/plugins/`.

## Plugin Inventory

| Plugin | Path | Version | Purpose | Docs |
|--------|------|---------|---------|------|
| conexao-data-model | `wp-content/plugins/conexao-data-model/` | 1.3.0 | CPTs, taxonomies, editorial meta | conexao-data-model.md |
| conexao-content | `wp-content/plugins/conexao-content/` | 1.0.0 | Static pages, shortcodes | conexao-content.md |
| conexao-admin-ux | `wp-content/plugins/conexao-admin-ux/` | 1.0.0 | Custom admin UI, statuses, bulk actions | conexao-admin-ux.md |
| conexao-event-importer | `wp-content/plugins/conexao-event-importer/` | 1.3.0 | Local-only event import + export to production (manual, no cron) | conexao-event-importer.md |
| conexao-leisure-migration | `wp-content/plugins/conexao-leisure-migration/` | 2.0.0 | Lazer ZIP export/import with images | conexao-leisure-migration.md |

## Load Order

Plugins must be activated in this order (dependencies first):

1. `conexao-data-model`
2. `conexao-content`
3. `conexao-admin-ux`
4. `conexao-event-importer`
5. `conexao-leisure-migration`

## Third-Party Plugins

No third-party plugins are bundled in this repository. The project relies only on these 5 custom plugins and core WordPress functionality.

## Building

```bash
./scripts/build-plugins-zip.sh
```

Output: `dist/*.zip` — one ZIP per plugin, structured for WordPress plugin upload.
