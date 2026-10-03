#!/usr/bin/env bash
#
# Stage 7 — validation clone setup (reproducible acceptance environment).
#
# Builds a throwaway WordPress install running the repo theme + plugins with the
# real production Leisure dataset, used to verify the Stage 7 English Leisure
# card descriptions end to end (/lazer/ unchanged, /en/lazer/ English).
#
# Requirements: a PHP 8.4+ CLI with pdo_sqlite (the repo bundles a static binary
# at .local/php/php), plus curl, unzip, tar, python3.
#
# Usage (from the project root):
#   ./scripts/stage7-validation-clone-setup.sh [target-dir] [port]
#   defaults: /tmp/wpval/wordpress and 8765
#
# The script fetches + wires everything, then prints the remaining command
# sequence (install, languages, seed, validate). The clone is disposable.
set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TARGET="${1:-/tmp/wpval/wordpress}"
PORT="${2:-8765}"
PHP_BIN="${PHP_BIN:-$PROJECT_ROOT/.local/php/php}"
BASE_URL="http://127.0.0.1:$PORT"
WP_VERSION="7.0.2"
POLYLANG_VERSION="3.8.9"
WORK_DIR="$(dirname "$TARGET")"

echo "== Stage 7 validation clone =="
echo "target: $TARGET | url: $BASE_URL | php: $PHP_BIN"

[ -x "$PHP_BIN" ] || { echo "ERROR: PHP binary not found at $PHP_BIN (set PHP_BIN)." >&2; exit 1; }

mkdir -p "$WORK_DIR"
cd "$WORK_DIR"

# --- 1. Sources (GitHub mirrors + the plugin's own distribution) ----------------

if [ ! -d "$TARGET" ]; then
	echo "-- fetching WordPress $WP_VERSION"
	curl -sL -o "wp-$WP_VERSION.tar.gz" "https://codeload.github.com/WordPress/WordPress/tar.gz/refs/tags/$WP_VERSION"
	tar xzf "wp-$WP_VERSION.tar.gz"
	mv "WordPress-$WP_VERSION" "$TARGET"
fi

[ -f "$WORK_DIR/wp-cli.phar" ] || curl -sL -o "$WORK_DIR/wp-cli.phar" "https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar"

if [ ! -d "$WORK_DIR/polylang-$POLYLANG_VERSION" ]; then
	echo "-- fetching Polylang $POLYLANG_VERSION (built distribution: it ships vendor/)"
	curl -sL -o "polylang-$POLYLANG_VERSION.zip" "https://downloads.wordpress.org/plugin/polylang.$POLYLANG_VERSION.zip"
	unzip -q -o "polylang-$POLYLANG_VERSION.zip"
fi

if [ ! -d "$WORK_DIR/sqlite-database-integration-main" ]; then
	echo "-- fetching the SQLite database-integration drop-in"
	curl -sL -o "sqlite-database-integration.zip" "https://codeload.github.com/WordPress/sqlite-database-integration/zip/refs/heads/main"
	unzip -q -o "sqlite-database-integration.zip"
fi

# --- 2. wp-config.php + SQLite drop-in -----------------------------------------

if [ ! -f "$TARGET/wp-config.php" ]; then
	echo "-- writing wp-config.php"
	cat > "$TARGET/wp-config.php" <<PHPEOF
<?php
define( 'DB_NAME', 'wordpress' );
define( 'DB_USER', 'wordpress' );
define( 'DB_PASSWORD', 'wordpress' );
define( 'DB_HOST', 'localhost' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', 'utf8mb4_unicode_ci' );
\$table_prefix = 'wp_';

define( 'WP_HOME', '$BASE_URL' );
define( 'WP_SITEURL', '$BASE_URL' );

define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
define( 'WP_CACHE', false );
define( 'DISABLE_WP_CRON', true );
define( 'WP_AUTO_UPDATE_CORE', false );

/* SQLite drop-in (wp-content/db.php) takes over the DB layer. */
define( 'DB_ENGINE', 'sqlite' );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require_once ABSPATH . 'wp-settings.php';
PHPEOF
fi

mkdir -p "$TARGET/wp-content/plugins"
if [ ! -d "$TARGET/wp-content/plugins/sqlite-database-integration" ]; then
	cp -r "$WORK_DIR/sqlite-database-integration-main" "$TARGET/wp-content/plugins/sqlite-database-integration"
fi
cp "$TARGET/wp-content/plugins/sqlite-database-integration/db.copy" "$TARGET/wp-content/db.php"

# --- 3. Repo theme + plugins (real copies: the suites resolve paths via __DIR__) --

echo "-- syncing repo theme + plugins"
sync_dir() { rm -rf "$2"; cp -r "$1" "$2"; }
sync_dir "$PROJECT_ROOT/wp-content/themes/conexao-br-irlanda" "$TARGET/wp-content/themes/conexao-br-irlanda"
for p in conexao-data-model conexao-content conexao-admin-ux conexao-event-runtime conexao-event-importer conexao-leisure-migration conexao-sponsor-migration conexao-page-translation conexao-blog-translation conexao-job-translation conexao-leisure-translation; do
	[ -d "$PROJECT_ROOT/wp-content/plugins/$p" ] && sync_dir "$PROJECT_ROOT/wp-content/plugins/$p" "$TARGET/wp-content/plugins/$p"
done
sync_dir "$WORK_DIR/polylang-$POLYLANG_VERSION" "$TARGET/wp-content/plugins/polylang"

# --- 4. Router for the PHP built-in server --------------------------------------

if [ ! -f "$WORK_DIR/router.php" ]; then
	cat > "$WORK_DIR/router.php" <<'PHPEOF'
<?php
$root = $_SERVER['DOCUMENT_ROOT'];
$path = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
if ( false !== $path && is_file( $root . $path ) ) {
	return false;
}
require_once $root . '/index.php';
PHPEOF
fi

# --- 5. Remaining steps (printed; run them in order) ----------------------------

WP="\"$PHP_BIN\" \"$WORK_DIR/wp-cli.phar\" --path=\"$TARGET\" --url=\"$BASE_URL\" --allow-root"
cat <<EOF

== Ready. Run the remaining steps in order: ==

# install (first time only)
$WP core install --title='Conexao BR validation clone' --admin_user=stage7admin \\
  --admin_password=stage7-local-pass --admin_email=stage7@example.invalid --skip-email

# activate the production plugin set + the rollout tooling, then the theme
$WP plugin activate conexao-data-model conexao-content conexao-admin-ux \\
  conexao-event-runtime conexao-leisure-migration conexao-leisure-translation polylang
$WP theme activate conexao-br-irlanda
$WP rewrite structure '/%postname%/' --hard

# languages: pt (default) + en, then assign existing content
$WP eval-file "$PROJECT_ROOT/scripts/run-polylang-setup.php"
$WP rewrite flush --hard

# serve (background)
setsid "$PHP_BIN" -S 127.0.0.1:$PORT -t "$TARGET" "$WORK_DIR/router.php" \\
  </dev/null >"$WORK_DIR/server.log" 2>&1 &

# seed the production leisure dataset, then capture BEFORE / apply / capture AFTER
$WP eval-file "$PROJECT_ROOT/scripts/stage7-clone-seed-leisure.php"
$WP eval-file "$PROJECT_ROOT/scripts/stage7-clone-inventory.php" before
python3 "$PROJECT_ROOT/scripts/stage7-leisure-http-verify.py" --base "$BASE_URL" --label before
$WP eval-file "$PROJECT_ROOT/scripts/run-leisure-translation.php" apply
$WP eval-file "$PROJECT_ROOT/scripts/stage7-clone-inventory.php" after
python3 "$PROJECT_ROOT/scripts/stage7-leisure-http-verify.py" --base "$BASE_URL" --label after

# in-process checks (from the clone root)
"$PHP_BIN" "$TARGET/wp-content/themes/conexao-br-irlanda/tests/test-leisure-card-excerpt-language.php"
"$PHP_BIN" "$TARGET/wp-content/plugins/conexao-leisure-migration/tests/test-excerpt-en-roundtrip.php"
EOF

