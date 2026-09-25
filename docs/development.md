# Development

## Local Setup

1. Ensure Docker and Docker Compose are installed.
2. Clone the repository.
3. Run `docker compose up -d` from the project root.
4. Access WordPress at http://localhost:8080.
5. Complete the WordPress installation wizard (if first run).

## Quality tooling (Stage C — static analysis)

Composer is **development tooling only**: it exists for local/CI static
analysis and is **never deployed** to production (production is
WordPress.com; nothing from `vendor/` ships). Install it once after cloning:

```bash
composer install          # creates vendor/ (git-ignored)
```

The commands (definitions in `composer.json`, ruleset in `phpcs.xml.dist`,
static analysis in `phpstan.neon.dist`):

| Command | What it runs |
|---|---|
| `composer lint` | PHPCS (WordPress-Extra + WordPress-Docs + PHPCompatibilityWP). **Raw debt view** — exits non-zero while any legacy violation exists. |
| `composer lint:fix` | PHPCBF (auto-fixes what PHPCS can fix). Run it on files you touched, then re-check the baseline. |
| `composer analyse` | PHPStan level 5 with WordPress support (`phpstan-wordpress`) — exits 0 today because legacy errors are in the baseline. |
| `composer check` | `composer lint` + `composer analyse` (green only when the PHPCS debt is fully paid down). |
| `./scripts/lint.sh` | **The Stage C quality gate**: PHP syntax sweep (`php -l`) + PHPCS baseline check + `composer analyse`. Fails on any *new* violation. |

- **PHPCS is the PHP coding standard gate**; **PHPStan is the static type
  analyser**. `scripts/lint.sh` is the single entry point that fails a change
  when it makes things worse.
- **Legacy baselines.** The repository's pre-existing debt is recorded, not
  fixed, by Stage C:
  - `phpcs-baseline.json` — per-sniff legacy ERROR/WARNING counts. It **must
    not grow**: any increased or new sniff count fails `./scripts/lint.sh`
    (`NEW VIOLATIONS` output). After *deliberately* paying down debt,
    regenerate it with
    `php scripts/phpcs-baseline.php update <report.json> phpcs-baseline.json`
    (generate the report with
    `vendor/bin/phpcs -q --report=json > <report.json>`).
  - `phpstan-baseline.neon` — the 298 legacy level-5 errors (generated
    2026-09-25). It **must not grow**: a new error fails `composer analyse`.
    After *deliberately* fixing legacy errors, regenerate it with
    `vendor/bin/phpstan analyse --generate-baseline`.
  - New violations introduced after Stage C are always visible (the gates
    fail); shrinking the baselines is the expected direction.
- **PHP floor**: PHPCS `testVersion` is `8.0-` (engineering standard §2.1).
  The toolchain runs on PHP ≥ 8.0 (verified on 8.5). Runtime headers still
  declare 7.4/8.0 in places — aligning them is later-stage work; Stage C
  does not change runtime compatibility claims.
- **CI (Stage D)** will run these same checks on every push/PR
  (engineering standard §1.7). Until then, run `./scripts/lint.sh` locally
  before committing PHP changes.
- No Node/JS tooling is used or required (plain JS/CSS assets; adding a JS
  build stack is a separate, explicit decision — engineering standard §2.2).

The PHPStan bootstrap `docs/dev/phpstan-bootstrap.php` is development/static
analysis only — it is **never loaded by WordPress** and never deployed; it
only declares constants/types the analyser cannot discover (Polylang API
stubs, theme/plugin constants).

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

## Multilingual (EN) development — Stage 9 (Guides)

The Guide CPT is a **real English translation** since Stage 9: one linked EN
`guide` per public PT guide + the linked EN `conexao_category` terms, so
`/en/guias/` is a genuine English archive.

```bash
# 1. Local runner (LOCAL ONLY; loads the plugin itself)
docker compose exec -T wordpress php \
  /var/www/html/wp-content/themes/conexao-br-irlanda/tests/run-guide-translation.php dry-run
docker compose exec -T wordpress php \
  /var/www/html/wp-content/themes/conexao-br-irlanda/tests/run-guide-translation.php

# 2. In-process suite (completeness gate, pairs, identity, filter, PT regression)
docker compose exec -T wordpress php \
  /var/www/html/wp-content/themes/conexao-br-irlanda/tests/test-guide-en-translation.php

# 3. HTTP contract (archive, singles, canonical, hreflang, filter, pagination, sitemap)
./scripts/stage9-guide-http-verify.sh
```

Production (WordPress.com, no WP-CLI) uses the plugin admin screen: **Tools → EN
Guide Translations** (Preview, then Apply). See
`docs/plugins/conexao-guide-translation.md` and
`CONEXAO_BR_ENGLISH_GUIDES_TRANSLATION_REPORT.md`.

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

## Repository Hygiene

Generated work trees and local tooling are **not** committed (engineering
standard §1.3, enforced by the root `.gitignore`):

- `stage*-work/` / `*-work/`, `*.body`, `*.log` — disposable probe output and
  run logs. Regenerate on demand; distil findings into `docs/reports/` and
  keep only curated machine-readable proof under `docs/evidence/<date>-<stage>/`
  (see `docs/evidence/README.md`).
- `.local/` — local toolchains. The repository previously shipped a 24 MB
  PHP CLI under `.local/php/`; it was removed in Stage B. To run PHP outside
  the container, use `docker compose exec wordpress php ...` (or `wp ...`
  for WP-CLI scripts), install a system PHP CLI, or point the scripts that
  accept it at your binary via `PHP_BIN=/path/to/php`.

Historical stage reports live in `docs/reports/` (index in
`docs/reports/README.md`); never add reports or evidence files at the
repository root.
