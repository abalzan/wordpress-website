# Development

## Local Setup

1. Ensure Docker and Docker Compose are installed.
2. Clone the repository.
3. Run `docker compose up -d` from the project root.
4. Access WordPress at http://localhost:8080.
5. Complete the WordPress installation wizard (if first run).

## Restoring a Production UpdraftPlus Backup (Local Only)

Production UpdraftPlus database dumps declare `SET NAMES latin1` and
`DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci`, but the text payload inside
them is valid UTF-8 (e.g. "Capacitação" as raw UTF-8 bytes). Importing such a
dump as-is produces mojibake ("CapacitaÃ§Ã£o") because MySQL misinterprets the
UTF-8 bytes as latin1.

`scripts/restore-updraft-db.sh` handles this safely:

```bash
# Fresh restore into a recreated local database (drops existing local data!)
./scripts/restore-updraft-db.sh /path/to/backup_YYYY-MM-DD-...-db_

# Same, but keep the production siteurl/home instead of http://localhost:8080
./scripts/restore-updraft-db.sh /path/to/backup_...-db_ --keep-urls
```

What it does (never modifies the original backup, local database only):

1. Verifies the dump payload is valid UTF-8 before touching anything.
2. Verifies every `latin1` occurrence is structural (SET NAMES / table DDL) and
   that none sits inside an `INSERT INTO` data row; aborts otherwise.
3. On a temp copy only, rewrites `SET NAMES latin1` → `SET NAMES utf8mb4` and
   the table `DEFAULT CHARSET`/`COLLATE` to `utf8mb4`/`utf8mb4_unicode_ci`.
4. Drops and recreates the local database (`utf8mb4_unicode_ci`) and imports
   with `mysql --default-character-set=utf8mb4`, so UTF-8 payload bytes are
   stored unchanged.
5. Points `siteurl`/`home` at `http://localhost:8080` (unless `--keep-urls`).
6. Runs raw-byte verification (HEX checks + binary-safe mojibake scan) against
   posts, terms, termmeta, postmeta, options and usermeta.

`compose.yaml` pins `WORDPRESS_DB_CHARSET=utf8mb4` and
`WORDPRESS_DB_COLLATION=utf8mb4_unicode_ci` so the generated `wp-config.php`
matches the restored database. Do not change these to `latin1`.

Notes:

- This is a DB-only restore: uploads/images referenced from production URLs are
  not included (import the UpdraftPlus uploads archive separately if needed).
- The dump contains production URLs in post content; only `siteurl`/`home` are
  rewritten. Run a WP-CLI search-replace locally if you need the rest rewritten.
- Never run this script against production.

## PHP Upload Limits (Local)

- The official `wordpress` image defaults to `upload_max_filesize = 2M` and `post_max_size = 8M`, which is too small for large event/Lazer export files.
- `docker/php/uploads.ini` is mounted into the container at `/usr/local/etc/php/conf.d/zz-conexao-uploads.ini` and raises the limits to 300M (plus memory/time limits for long imports).
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

## Multilingual (EN) development — Stage 2

English support (`/en/`) is provided by **Polylang 3.8.9 (Free)** and the theme's
single integration layer `wp-content/themes/conexao-br-irlanda/inc/polylang.php`.
See `CONEXAO_BR_ENGLISH_STAGE_2_REPORT.md` for the full gate results.

```bash
# 1. Install + activate Polylang (local only, never production)
docker compose exec -T wordpress wp plugin install polylang --version=3.8.9 --allow-root
docker compose exec -T wordpress wp plugin activate polylang --allow-root

# 2. Configure languages + assign existing content to pt_BR (idempotent)
docker compose exec -T wordpress wp eval-file - --allow-root < scripts/stage2-polylang-setup.php
#    dry run:  ... < scripts/stage2-polylang-setup.php dry-run

# 3. Verify the URL/SEO/cache/REST matrix (HTTP level)
./scripts/stage2-http-verify.sh http://localhost:8080

# 4. Stage 2 gates (language, identity, status, UUID, taxonomy)
docker compose exec wordpress php /var/www/html/wp-content/themes/conexao-br-irlanda/tests/test-polylang-foundation.php
docker compose exec wordpress php /var/www/html/wp-content/plugins/conexao-event-runtime/tests/test-event-language-gate.php
docker compose exec wordpress php /var/www/html/wp-content/plugins/conexao-event-importer/tests/test-language-identity.php
docker compose exec wordpress php /var/www/html/wp-content/plugins/conexao-leisure-migration/tests/test-language-uuid.php
```

Notes:

- Polylang is installed in the container volume (`/var/www/html/wp-content/plugins/polylang`),
  **not** committed to this repository. Deactivating it restores single-language
  behaviour (every integration helper is capability-guarded).
- Translated post types/taxonomies are declared in code (`pll_get_post_types` /
  `pll_get_taxonomies` in `inc/polylang.php`), so local/staging/production cannot drift.
- Local PHP uses opcache with `validate_timestamps=On` and
  `revalidate_freq=2`; when editing theme PHP during a request-heavy loop, allow
  ~2 s or the previous bytecode may still be served.

## Running Scripts

Utility scripts are in `scripts/`. Most are WP-CLI eval files:

```bash
docker compose exec wordpress wp eval-file scripts/seed-course-providers.php
docker compose exec wordpress wp eval-file scripts/run-event-import.php
docker compose exec wordpress wp eval-file scripts/run-leisure-migration.php
```
