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

<!-- BEGIN GENERATED PLUGIN REGISTRY: docs/deployment.md activation order -->
Generated from [`plugins.json`](../../plugins.json) by
`scripts/generate-registry-docs.php`. Do not hand-edit this section.

### Production activation order

The filtered subset of the registry load order where `production: true`. It contains no
`production: false` entry:

1. `conexao-data-model`
2. `conexao-content`
3. `conexao-admin-ux`
4. `conexao-event-runtime`

Production needs exactly these 4 platform plugins. They are the only
plugins that must be installed **and active** on production.

### Release build output

```bash
./scripts/build-plugins-zip.sh
```

Produces one ZIP per `build: true` entry (7 files), in registry order:

- `dist/conexao-data-model.zip`
- `dist/conexao-content.zip`
- `dist/conexao-admin-ux.zip`
- `dist/conexao-event-runtime.zip`
- `dist/conexao-event-importer.zip`
- `dist/conexao-leisure-migration.zip`
- `dist/conexao-sponsor-migration.zip`

Import via WordPress Admin -> Plugins -> Add New -> Upload Plugin, then activate in
the production order above.

### Local-only tooling (not production)

Built and mounted so a developer can run them locally, but **not** production
dependencies and never left active on production:

- `conexao-event-importer`
- `conexao-leisure-migration`
- `conexao-sponsor-migration`
- `conexao-translation-rollout`

### Retired rollout plugins (historical tooling)

These one-shot English-rollout plugins have already been applied. They are **not** part
of a normal production release: each is `build: false` (no ZIP is produced) and none is
activated in the production steady state. They remain in the repository, and are locally
mounted only so the historical importer stays reproducible.

**Lifecycle: activate → apply → remove.**

- `conexao-page-translation` - activate → apply → remove
- `conexao-blog-translation` - activate → apply → remove
- `conexao-job-translation` - activate → apply → remove
- `conexao-leisure-translation` - activate → apply → remove
- `conexao-guide-translation` - activate → apply → remove
<!-- END GENERATED PLUGIN REGISTRY: docs/deployment.md activation order -->

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

Output: `dist/conexao-data-model.zip`, `dist/conexao-content.zip`, `dist/conexao-admin-ux.zip`, `dist/conexao-event-runtime.zip`, `dist/conexao-event-importer.zip`, `dist/conexao-leisure-migration.zip`

Import via WordPress Admin → Plugins → Add New → Upload Plugin. Activate in load order.

**Production** needs: data-model, content, admin-ux, **event-runtime**. The
event importer (local tooling) should **not** be installed or active on
production — see docs/plugins/conexao-event-runtime.md for the activation and
migration plan.

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

### Lazer image refresh (REST, no PHP/CLI)

Production is WordPress.com (no SSH/SFTP/CLI), so image corrections cannot be
run from a PHP script. Use the standard-library REST script instead — it
uploads the replacement image to the Media Library, sets the featured image
(the theme's hero/card image) and rewrites the `_leisure_image_*` attribution
meta using the exact same format as the PHP importer:

```bash
export WP_USERNAME='…'
export WP_APPLICATION_PASSWORD='…'   # wp-admin → Perfil → Senhas de aplicativo
python3 scripts/update-lazer-images-rest.py --dry-run   # preview
python3 scripts/update-lazer-images-rest.py             # apply
```

Notes:
- Matches listings by **slug**; idempotent (skips when `_leisure_image_source_url`
  already matches); never deletes the old attachment unless `--delete-old`.
- Requires the `conexao-data-model` update that adds an `auth_callback` to
  `register_leisure_meta()` — without it the attribution meta is read-only over
  REST (`403 rest_cannot_update`). Deploy that plugin update first.


### Events

1. Export: WordPress Admin → Event Import → Export Events → JSON download
2. Import: WordPress Admin → Event Import → Import Events → upload JSON
3. Matching: source + source_id → UUID → URL → content

## Environment Differences

- **Local**: Full admin access, WP_DEBUG enabled, `WORDPRESS_DEBUG=1`
- **Production**: WordPress.com managed, WP_DEBUG disabled, caching enabled
- **Domain-specific configuration**: None required — all paths are relative

## Translation Rollouts (Stage H)

Translation rollouts are **not** part of the normal production release. The
shared engine `conexao-translation-rollout` is `tooling` / `production: false` /
`build: false`: it is locally mounted for development and tests, and it is
**never** in a release plugin ZIP, exactly like the retired one-shot rollouts.

To run an actual rollout on production, install the engine plus the stage
plugin manually (ZIP upload or a repo checkout), then use the **admin
workflow** — production is WordPress.com and has **no WP-CLI**:

1. Tools → **Translation Rollouts** (or the stage's own legacy screen, for the
   four rollouts not yet migrated).
2. Select the registered stage.
3. **Preview (dry run)** first — it performs zero writes and prints the
   `create` / `update` / `skip` / `conflicts` plan plus the numeric gate.
4. **Apply** — writes, then re-verifies that the PT originals are unchanged
   (`pt_drift` must be 0) and recalculates the gate
   (`eligible public PT <type> missing EN = 0`).
5. **Remove** only for a stage that declares `allow_remove`; otherwise follow
   that stage's documented recovery.

The engine registers its admin action with `manage_options` and a nonce, never
writes on page load or on GET, and performs no content write on activation.
See [`docs/plugins/conexao-translation-rollout.md`](plugins/conexao-translation-rollout.md).

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
