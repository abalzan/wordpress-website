#!/usr/bin/env bash
#
# restore-updraft-db.sh — LOCAL ONLY
#
# Restores a production UpdraftPlus SQL database backup into the local Docker
# MySQL/MariaDB container with correct UTF-8 handling.
#
# Why this exists:
#   UpdraftPlus exports the production dump with `/*!40101 SET NAMES latin1 */;`
#   and `DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci` table definitions,
#   while the actual text payload inside the dump is valid UTF-8
#   (e.g. "Capacitação" as raw UTF-8 bytes).
#
#   Importing that dump as-is makes MySQL interpret UTF-8 bytes as latin1 and
#   then double-encode them when WordPress reads them back with its utf8mb4
#   connection, producing mojibake ("CapacitaÃ§Ã£o").
#
# Fix (verified, not blind replacement):
#   - The script FIRST verifies the dump payload is valid UTF-8.
#   - It verifies every `latin1` occurrence is structural (SET NAMES / table DDL)
#     and that NONE of them sits inside an `INSERT INTO` data row.
#   - Only then it rewrites, on a TEMP COPY (original backup is never modified):
#       SET NAMES latin1                                 -> SET NAMES utf8mb4
#       DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci -> utf8mb4/utf8mb4_unicode_ci
#   - It imports with `mysql --default-character-set=utf8mb4` into a freshly
#     recreated database, so UTF-8 payload bytes are stored unchanged in
#     utf8mb4 tables.
#
# Usage:
#   ./scripts/restore-updraft-db.sh /path/to/backup_...-db_ [--keep-urls]
#
#   By default, after import the script points `siteurl` and `home` at
#   http://localhost:8080 so the local site is browsable. Pass --keep-urls
#   to leave the production URLs untouched.
#
# DO NOT point this script at any production database or run it on production.

set -euo pipefail

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$PROJECT_DIR"

BACKUP_FILE=""
KEEP_URLS=0
LOCAL_URL="http://localhost:8080"

for arg in "$@"; do
  case "$arg" in
    --keep-urls) KEEP_URLS=1 ;;
    -h|--help) grep '^#' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) if [ -z "$BACKUP_FILE" ]; then BACKUP_FILE="$arg"; else echo "ERROR: unexpected argument '$arg'" >&2; exit 1; fi ;;
  esac
done

if [ -z "$BACKUP_FILE" ] || [ ! -f "$BACKUP_FILE" ]; then
  echo "Usage: $0 /path/to/updraftplus-db-backup [--keep-urls]" >&2
  exit 1
fi

# Local DB credentials (defaults match compose.yaml; override via environment).
DB_NAME="${WORDPRESS_DB_NAME:-wordpress}"
DB_USER="${WORDPRESS_DB_USER:-wordpress}"
DB_PASSWORD="${WORDPRESS_DB_PASSWORD:-wordpress}"
DB_ROOT_PASSWORD="${MYSQL_ROOT_PASSWORD:-local-root-password}"

MYSQL_CLIENT=(docker compose exec -T db mysql --default-character-set=utf8mb4)

# ---------------------------------------------------------------------------
# 1. Materialise the dump (support plain + .gz backups) into a temp copy.
#    The original backup file is NEVER modified.
# ---------------------------------------------------------------------------
WORKDIR="$(mktemp -d)"
trap 'rm -rf "$WORKDIR"' EXIT

case "$BACKUP_FILE" in
  *.gz|*.gzip) gunzip -c "$BACKUP_FILE" > "$WORKDIR/dump.sql" ;;
 *)            cp "$BACKUP_FILE" "$WORKDIR/dump.sql" ;;
esac
DUMP="$WORKDIR/dump.sql"

echo "==> Backup: $BACKUP_FILE"

# ---------------------------------------------------------------------------
# 2. SAFETY CHECK A: the text payload must already be valid UTF-8.
#    If it is not, rewriting the charset declarations would corrupt it.
# ---------------------------------------------------------------------------
if ! iconv -f UTF-8 -t UTF-8 "$DUMP" > /dev/null 2>&1; then
  echo "ABORT: dump payload is NOT valid UTF-8. This script only handles the" >&2
  echo "       'UTF-8 data declared as latin1' case. Inspect the file manually." >&2
  exit 1
fi
echo "==> Payload is valid UTF-8 (OK)"

# ---------------------------------------------------------------------------
# 3. SAFETY CHECK B: every latin1 occurrence must be structural.
#    If any latin1 appears inside an INSERT data row, abort — we must not
#    rewrite strings that are part of the content.
# ---------------------------------------------------------------------------
if grep 'latin1' "$DUMP" | grep -q '^INSERT INTO'; then
  echo "ABORT: found 'latin1' inside an INSERT data row. Refusing to rewrite" >&2
  echo "       (would risk corrupting content). Inspect the dump manually." >&2
  exit 1
fi

NAMES_COUNT="$(grep -c 'SET NAMES latin1' "$DUMP" || true)"
CHARSET_COUNT="$(grep -c 'DEFAULT CHARSET=latin1' "$DUMP" || true)"
echo "==> Found: SET NAMES latin1 x${NAMES_COUNT}, DEFAULT CHARSET=latin1 x${CHARSET_COUNT}"

if [ "$NAMES_COUNT" -eq 0 ] && [ "$CHARSET_COUNT" -eq 0 ]; then
  echo "==> Nothing to rewrite (dump already declares a non-latin1 charset)."
  cp "$DUMP" "$WORKDIR/dump-fixed.sql"
else
  # -----------------------------------------------------------------------
  # 4. Rewrite ONLY the structural declarations, on the temp copy.
  #    Content strings (INSERT rows) were proven untouched by check B.
  # -----------------------------------------------------------------------
  sed -e 's/SET NAMES latin1/SET NAMES utf8mb4/' \
      -e 's/DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci/DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci/g' \
      -e 's/DEFAULT CHARSET=latin1/DEFAULT CHARSET=utf8mb4/g' \
      "$DUMP" > "$WORKDIR/dump-fixed.sql"

  # Post-check: any remaining latin1 must be inside INSERT data rows only.
  if grep 'latin1' "$WORKDIR/dump-fixed.sql" | grep -v '^INSERT INTO' | grep -q 'latin1'; then
    echo "ABORT: unexpected latin1 remains outside INSERT rows after rewrite." >&2
    exit 1
  fi
  echo "==> Rewrote declarations to utf8mb4/utf8mb4_unicode_ci (original backup untouched)"
fi

# ---------------------------------------------------------------------------
# 5. Recreate the local database from scratch (disposable local environment).
# ---------------------------------------------------------------------------
echo "==> Dropping and recreating local database '${DB_NAME}'..."
docker compose exec -T db mysql -uroot -p"${DB_ROOT_PASSWORD}" -e \
  "DROP DATABASE IF EXISTS \`${DB_NAME}\`; CREATE DATABASE \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

echo "==> Importing (this can take a minute)..."
"${MYSQL_CLIENT[@]}" -u"${DB_USER}" -p"${DB_PASSWORD}" "${DB_NAME}" < "$WORKDIR/dump-fixed.sql"

echo "==> Import complete."

# ---------------------------------------------------------------------------
# 6. Point siteurl/home at the local URL (skip with --keep-urls).
# ---------------------------------------------------------------------------
if [ "$KEEP_URLS" -eq 0 ]; then
  echo "==> Setting siteurl/home to ${LOCAL_URL} (use --keep-urls to skip)..."
  "${MYSQL_CLIENT[@]}" -u"${DB_USER}" -p"${DB_PASSWORD}" "${DB_NAME}" -e \
    "UPDATE \`${DB_NAME}\`.wp_options SET option_value='${LOCAL_URL}' WHERE option_name IN ('siteurl','home');"
fi

# ---------------------------------------------------------------------------
# 7. Verification: raw byte checks straight from the database.
# ---------------------------------------------------------------------------
echo "==> Verifying stored bytes (UTF-8 hex for 'Capacitação' must be: 4361706163697461C3A7C3A36F)"
"${MYSQL_CLIENT[@]}" -u"${DB_USER}" -p"${DB_PASSWORD}" "${DB_NAME}" <<'SQL'
SELECT '--- table charsets ---' AS info;
SELECT table_name, table_collation FROM information_schema.tables
 WHERE table_schema = DATABASE() ORDER BY table_name;
SELECT '--- post titles (raw hex of first Capacitação title) ---' AS info;
SELECT ID, post_title, HEX(post_title) AS title_hex
  FROM wp_posts WHERE post_title LIKE 'Capacitação%' LIMIT 5;
SELECT '--- mojibake scan (binary-safe; double-encoded sequences C383C2xx; all counts must be 0) ---' AS info;
SELECT
  (SELECT COUNT(*) FROM wp_posts    WHERE HEX(post_title)   LIKE '%C383C2%' OR HEX(post_content) LIKE '%C383C2%') AS posts_mojibake,
  (SELECT COUNT(*) FROM wp_terms    WHERE HEX(name)         LIKE '%C383C2%')                                     AS terms_mojibake,
  (SELECT COUNT(*) FROM wp_termmeta WHERE HEX(meta_value)   LIKE '%C383C2%')                                     AS termmeta_mojibake,
  (SELECT COUNT(*) FROM wp_postmeta WHERE HEX(meta_value)   LIKE '%C383C2%')                                     AS postmeta_mojibake,
  (SELECT COUNT(*) FROM wp_options  WHERE HEX(option_value) LIKE '%C383C2%')                                     AS options_mojibake,
  (SELECT COUNT(*) FROM wp_usermeta WHERE HEX(meta_value)   LIKE '%C383C2%')                                     AS usermeta_mojibake;
SELECT '--- samples: posts content ---' AS info;
SELECT post_title FROM wp_posts WHERE post_content LIKE '%Informação%' OR post_content LIKE '%Educação%' LIMIT 5;
SELECT '--- samples: terms ---' AS info;
SELECT name FROM wp_terms WHERE name LIKE '%ç%' OR name LIKE '%ã%' OR name LIKE '%á%' OR name LIKE '%é%' OR name LIKE '%í%' OR name LIKE '%ó%' OR name LIKE '%ú%' LIMIT 10;
SELECT '--- samples: post meta ---' AS info;
SELECT meta_key, LEFT(meta_value, 80) AS sample FROM wp_postmeta WHERE meta_value LIKE '%Capacitação%' LIMIT 5;
SELECT '--- samples: options ---' AS info;
SELECT option_name, LEFT(option_value, 80) AS sample FROM wp_options WHERE option_value LIKE '%Capacitação%' OR option_value LIKE '%Informação%' OR option_value LIKE '%Apoiadores%' OR option_value LIKE '%Lazer%' LIMIT 10;
SQL

echo "==> Done. Local site: http://localhost:8080"
echo "    (uploads/images referenced from production are not part of a DB-only backup)"

