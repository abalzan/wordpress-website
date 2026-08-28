# Deployment

## Local Environment

- **Docker Compose**: WordPress 7.0.2 + MySQL in containers.
- **URL**: http://localhost:8080
- **Data**: Persistent via Docker volumes (`db_data`, `wordpress_data`).
- **Theme/Plugins**: Bind-mounted from the host filesystem; changes are live-reloaded.
- **Permissions**: Entrypoint script (`docker/entrypoint-fix-permissions.sh`) ensures Apache can write to bind-mounted theme/plugin directories.

## Production Environment

- **Platform**: WordPress.com
- **Domain**: https://conexaobr.ie
- **Deployment method**: Manual ZIP upload via WordPress admin

### Production Differences from Local

| Aspect | Local | Production |
|--------|-------|------------|
| Web server | Apache (Docker) | WordPress.com managed |
| Database | MySQL in Docker | WordPress.com managed |
| File system | Bind-mounted | WordPress.com managed |
| HTTPS | HTTP only | HTTPS (managed) |
| Rewrite rules | `.htaccess` active | WordPress.com supports .htaccess |
| Cron | Manual / WP-CLI | WordPress.com wp-cron |

## Build Process

### Plugins
## Environment Differences

- **Local**: Full admin access, WP_DEBUG enabled, `WORDPRESS_DEBUG=1`
- **Production**: WordPress.com managed, WP_DEBUG disabled, caching enabled
- **Domain-specific configuration**: None required — all paths are relative

## Media thumbnails for new image sizes

The theme registers two additive sizes used by the homepage responsive
images: `conexao-sponsor-tile` (512px, Hero Apoiador carousel) and
`conexao-event-preview` (240×135 crop, homepage event previews). Attachments
uploaded *before* these sizes existed must be regenerated once on production
so the srcset ladders include the small derivatives:

```bash
# Local (Docker): regenerate the sponsor + event banner attachments
docker compose exec wordpress php wp-cli.phar media regenerate <IDs...> --skip-delete --yes --allow-root
```

On WordPress.com production, regenerate thumbnails for the same attachments
via WP-CLI (`wp media regenerate`) or a plugin equivalent after deploying the
theme update. The `scripts/generate-logo-derivatives.php` script creates the
240px header-logo derivatives from the theme assets (already committed; run
it only if the logo masters are ever replaced).

## Deployment Checklist

```bash
./scripts/build-plugins-zip.sh
```

Output: `dist/conexao-data-model.zip`, `dist/conexao-content.zip`, `dist/conexao-admin-ux.zip`, `dist/conexao-event-importer.zip`, `dist/conexao-leisure-migration.zip`

Import via WordPress Admin → Plugins → Add New → Upload Plugin. Activate in load order.

### Theme

```bash
./scripts/build-theme-zip.sh
```

Output: `dist/conexao-br-irlanda.zip`

Import via WordPress Admin → Appearance → Themes → Add New → Upload Theme.

## Media Handling

- **Local development**: Media uploaded via WordPress admin goes into the Docker volume.
- **Production**: Media uploaded via WordPress.com goes into the WordPress.com Media Library.
- **Migration**: Lazer export/import ZIPs carry actual image files; production always uses local Media Library attachments.
- **No hotlinking**: Production leisure images are never served from Wikimedia Commons or external CDNs. Wikimedia metadata is attribution-only.

## Data Migration Workflows

### Lazer (Leisure/Tourism)

1. Export from source: WordPress Admin → Lazer → Exportar Lazer → download ZIP
2. Import to target: WordPress Admin → Lazer → Importar Lazer → upload ZIP → preview → confirm
3. ZIP contains: `data.json` + actual image files from Media Library
4. Matching: stable UUID → slug → title (never WordPress post IDs)

### Events

1. Export: WordPress Admin → Event Import → Export Events → JSON download
2. Import: WordPress Admin → Event Import → Import Events → upload JSON
3. Matching: source + source_id → UUID → URL → content

## Environment Differences

- **Local**: Full admin access, WP_DEBUG enabled, `WORDPRESS_DEBUG=1`
- **Production**: WordPress.com managed, WP_DEBUG disabled, caching enabled
- **Domain-specific configuration**: None required — all paths are relative

## Deployment Checklist

1. Build plugin ZIPs (`./scripts/build-plugins-zip.sh`)
2. Build theme ZIP (`./scripts/build-theme-zip.sh`)
3. Upload and activate plugins in load order on production
4. Upload and activate theme on production
5. Verify all CPT archives load
6. Verify redirects work (English → Portuguese)
7. Run any required seed scripts
8. Test event import
9. Test leisure import (if applicable)
10. Verify sitemap at `/sitemap.xml`
