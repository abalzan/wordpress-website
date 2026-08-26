# Development

## Local Setup

1. Ensure Docker and Docker Compose are installed.
2. Clone the repository.
3. Run `docker compose up -d` from the project root.
4. Access WordPress at http://localhost:8080.
5. Complete the WordPress installation wizard (if first run).

## PHP Upload Limits (Local)

- The official `wordpress` image defaults to `upload_max_filesize = 2M` and `post_max_size = 8M`, which is too small for large event/Lazer export files.
- `docker/php/uploads.ini` is mounted into the container at `/usr/local/etc/php/conf.d/zz-conexao-uploads.ini` and raises the limits to 64M (plus memory/time limits for long imports).
- Changes to that file require a container restart: `docker compose restart wordpress`.
- Production equivalents are managed by the hosting platform (WordPress.com) and cannot be changed from this repo.
- Both import screens (Events → Import Events, Lazer → Importar Lazer) display the effective maximum upload size, validate file size client-side before submitting, and show a clear error notice if a POST is rejected by `post_max_size`.

## Common Commands

```bash
# Start environment
docker compose up -d

# Stop environment
docker compose down

# View logs
docker compose logs -f wordpress

# WP-CLI
docker compose exec wordpress wp --info
docker compose exec wordpress wp plugin list
docker compose exec wordpress wp user list

# Build plugins (creates ZIPs in dist/)
./scripts/build-plugins-zip.sh

# Build theme (creates ZIP in dist/)
./scripts/build-theme-zip.sh
```

## Debugging

- `WORDPRESS_DEBUG=1` is set by default in `.env.example` / `compose.yaml`.
- WP_DEBUG is enabled.
- PHP error logs: `docker compose logs wordpress`

## Cache Clearing

- WordPress object cache (transients) can be cleared via WP-CLI:
  ```bash
  docker compose exec wordpress wp transient delete-all
  ```
- Homepage transients auto-invalidate on save (`conexao_homepage_cache_invalidate()`).
- Filter term caches auto-invalidate on save.
- `.htaccess` sets long-lived browser cache (1 year) for fingerprinted CSS/JS assets; clear browser cache or append a new query string to force updates.

## Modifying Templates Safely

1. All templates are in `wp-content/themes/conexao-br-irlanda/`.
2. Template parts are in `template-parts/`.
3. Reuse shared components instead of duplicating markup.
4. Use the design-system CSS variables (`--color-*`) instead of hard-coded colors.
5. When adding a new CSS file, add it to `conexao_enqueue_scripts()` in `functions.php`.
6. Always add `loading="lazy"` to below-fold images.

## Modifying Plugins Safely

1. All custom plugins are in `wp-content/plugins/conexao-*`.
2. When adding meta fields, register them via `register_post_meta()`.
3. When adding CPTs/taxonomies, add them to `conexao-data-model`.
4. Follow the plugin load order: data-model → content → admin-ux → event-importer → leisure-migration.

## Testing Responsive Behavior

- Resize the browser window to test breakpoints (768px mobile/tablet boundary).
- Use browser DevTools device emulation.
- Test dark mode toggle in both themes.

## Running Scripts

Utility scripts are in `scripts/`. Most are WP-CLI eval files:

```bash
docker compose exec wordpress wp eval-file scripts/seed-course-providers.php
docker compose exec wordpress wp eval-file scripts/run-event-import.php
docker compose exec wordpress wp eval-file scripts/run-leisure-migration.php
```
