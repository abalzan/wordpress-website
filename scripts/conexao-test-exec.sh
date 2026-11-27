#!/usr/bin/env bash
#
# conexao-test-exec.sh — container-side launcher for the isolated test process.
#
# This wrapper is the ONLY sanctioned path by which a WordPress/DB-dependent
# test process receives generated, schema-restricted test database credentials.
# It exists so the password never appears in a host command line, a Docker
# `-e NAME=value` argument, `ps` argv, shell history or a log.
#
# Contract (see docs/testing.md, "Isolated database for WordPress-dependent tests"):
#
#   1. The host runner copies the protected `.conexao-test.env` (mode 0600,
#      containing only CONEXAO_TEST_DB_USER and CONEXAO_TEST_DB_PASSWORD)
#      into this container at the path named by CONEXAO_TEST_DB_ENV_FILE.
#   2. This wrapper sources ONLY that dedicated file (never the full `.env`),
#      validates the two values and the requested test database, then exports
#      the WordPress DB environment variables for the child PHP process.
#   3. It `exec`s the requested command (PHP + suite path) so the restricted
#      environment is in place BEFORE `wp-config.php` runs. WordPress must
#      connect as the requested restricted test account from its first database
#      connection;
#      reconnecting `$wpdb` after `wp-load` is too late.
#
# The host-side command line contains only this wrapper's path, the secret-file
# path and the suite path — never the password value. Shell tracing is kept
# disabled from before the secret file is opened until the child exits.
#
# Environment inputs (set by the host runner, never by a secret value):
#   CONEXAO_TEST_DB_ENV_FILE   Path to the protected .conexao-test.env copy.
#   CONEXAO_TEST_DB_NAME       Expected disposable test schema name.
#   CONEXAO_TEST_DB_USER       Expected generated restricted test account.
#   CONEXAO_TEST_SHARED_DB_NAME  Shared schema the test DB must differ from.
#   WORDPRESS_DB_HOST          Database host (preserved from the environment).
#
# The wrapper fails closed (non-zero exit, no PHP launched) if any check fails.

set -euo pipefail

# Tracing must never be on while a secret file is open. Disable it
# unconditionally at entry; the caller must not re-enable it.
set +x

fail() {
	printf 'conexao-test-exec: %s\n' "$*" >&2
	exit 1
}

# --- Validate the non-secret inputs ----------------------------------------

: "${CONEXAO_TEST_DB_ENV_FILE:?CONEXAO_TEST_DB_ENV_FILE is required}"
: "${CONEXAO_TEST_DB_NAME:?CONEXAO_TEST_DB_NAME is required}"
: "${CONEXAO_TEST_DB_USER:?CONEXAO_TEST_DB_USER is required}"

if [ ! -f "$CONEXAO_TEST_DB_ENV_FILE" ]; then
	fail "secret file not found: $CONEXAO_TEST_DB_ENV_FILE"
fi

if [ ! -r "$CONEXAO_TEST_DB_ENV_FILE" ]; then
	fail "secret file is not readable: $CONEXAO_TEST_DB_ENV_FILE"
fi

# The expected account is either the legacy local test account or a generated
# per-run account. Refuse arbitrary database identities.
if ! printf '%s' "$CONEXAO_TEST_DB_USER" | grep -qE '^conexao_test(_[a-f0-9]{12})?$'; then
	fail "CONEXAO_TEST_DB_USER is not an approved restricted test account"
fi

# The requested test database must be a bare identifier.
if ! printf '%s' "$CONEXAO_TEST_DB_NAME" | grep -qE '^[A-Za-z0-9_]+$'; then
	fail "CONEXAO_TEST_DB_NAME is not a valid database identifier"
fi
REQUESTED_TEST_DB_USER="$CONEXAO_TEST_DB_USER"

# The test database must differ from the shared schema (outside CI, where the
# shared identity may be unknown but the test DB is still explicit).
if [ -n "${CONEXAO_TEST_SHARED_DB_NAME:-}" ] && [ "$CONEXAO_TEST_DB_NAME" = "$CONEXAO_TEST_SHARED_DB_NAME" ]; then
	fail "the requested test database is the shared WordPress database"
fi

# --- Source ONLY the dedicated secret file ---------------------------------
#
# `set -a` auto-exports every name assigned while sourcing, so the two test
# variables become environment variables for the child without appearing in
# argv. Only this file is sourced; the repository's full `.env` is never read
# inside the container.

set -a
# shellcheck disable=SC1090
. "$CONEXAO_TEST_DB_ENV_FILE"
set +a

# --- Validate the loaded secret values -------------------------------------

if [ -z "${CONEXAO_TEST_DB_USER:-}" ]; then
	fail "CONEXAO_TEST_DB_USER is empty after loading the secret file"
fi

if [ -z "${CONEXAO_TEST_DB_PASSWORD:-}" ]; then
	fail "CONEXAO_TEST_DB_PASSWORD is empty after loading the secret file"
fi

if [ "$CONEXAO_TEST_DB_USER" != "$REQUESTED_TEST_DB_USER" ]; then
	fail "the secret file's test account does not match the requested account"
fi

# --- Export the WordPress DB environment for the child PHP process ---------
#
# These are the variables `wp-config.php` reads via getenv_docker(). They must
# be in place before PHP starts so WordPress connects as the restricted test
# account to the disposable schema from its first connection.

export WORDPRESS_DB_USER="$CONEXAO_TEST_DB_USER"
export WORDPRESS_DB_PASSWORD="$CONEXAO_TEST_DB_PASSWORD"
export WORDPRESS_DB_NAME="$CONEXAO_TEST_DB_NAME"
export CONEXAO_TEST_DB_NAME="$CONEXAO_TEST_DB_NAME"

# Preserve the host only if the caller supplied one; otherwise let wp-config's
# default resolve. The host is not a secret.
if [ -n "${WORDPRESS_DB_HOST:-}" ]; then
	export WORDPRESS_DB_HOST
fi

# --- Exec the requested command --------------------------------------------
#
# "$@" is the PHP invocation (e.g. `php /var/www/html/tests/lib/isolated-db.php`
# or `php /var/www/html/wp-content/.../test-foo.php`). `exec` replaces this
# shell so the child inherits the restricted environment directly and no
# wrapper process lingers.

if [ "$#" -lt 1 ]; then
	fail "no command supplied to exec"
fi

exec "$@"