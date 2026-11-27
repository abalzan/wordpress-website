#!/usr/bin/env bash
#
# Purpose: Create, activate and clean up the isolated WordPress CI test database.
# Scope: The ephemeral local Docker Compose stack and this checkout only.
# Safety: Uses a generated schema/account, clones only the local CI WordPress
#         database, and stores credentials in mode-0600 files. The admin and
#         synthetic fixtures are built after the service switches to the clone.

set -Eeuo pipefail
umask 077

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
CREDENTIALS_FILE="$REPO_ROOT/.conexao-test.env"
COMPOSE_ENV_FILE="$REPO_ROOT/.conexao-test-compose.env"
CONTAINER_SECRET_FILE="/var/www/html/scripts/.conexao-test.env"
STATE_ROOT="${RUNNER_TEMP:-${TMPDIR:-/tmp}}"
CREDENTIALS_TMP=""
COMPOSE_ENV_TMP=""
CURRENT_OPERATION="initialize isolated test database"
CURRENT_COMMAND="shell setup"

fail() {
	printf 'isolated-test-database: %s\n' "$*" >&2
	exit 1
}

report_command_failure() {
	local exit_status="$1"
	printf 'isolated-test-database: command failed: %s during %s (exit status %s)\n' \
		"$CURRENT_COMMAND" "$CURRENT_OPERATION" "$exit_status" >&2
}

trap 'report_command_failure "$?"' ERR

root_mysql() {
	docker compose exec -T db sh -c \
		'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot --batch --skip-column-names'
}

root_sql() {
	CURRENT_COMMAND="docker compose exec db mysql (SQL supplied on stdin)"
	printf '%s\n' "$1" | root_mysql
}

remove_generated_files() {
	rm -f "$CREDENTIALS_FILE" "$COMPOSE_ENV_FILE"
	if [ -n "$CREDENTIALS_TMP" ]; then
		rm -f "$CREDENTIALS_TMP"
	fi
	if [ -n "$COMPOSE_ENV_TMP" ]; then
		rm -f "$COMPOSE_ENV_TMP"
	fi
}

cleanup_resources() {
	local status=0
	local state_dir="${TEST_DB_STATE_DIR:-}"

	if [ -n "$state_dir" ] && [ -f "$state_dir/service-switch-attempted" ]; then
		if ! docker compose up -d --no-deps --force-recreate wordpress >/dev/null; then
			printf '::error::could not restore the WordPress service to its shared local database\n' >&2
			status=1
		fi
		rm -f "$state_dir/service-switch-attempted"
	fi

	if [ -n "${TEST_DB_USER:-}" ] && [ -n "$state_dir" ] \
		&& [ -f "$state_dir/user-create-attempted" ]; then
		if ! printf "DROP USER IF EXISTS '%s'@'%%';\n" "$TEST_DB_USER" | root_mysql >/dev/null; then
			printf '::error::could not remove the generated isolated test user\n' >&2
			status=1
		else
			rm -f "$state_dir/user-create-attempted"
		fi
	fi

	if [ -n "${TEST_DB_NAME:-}" ] && [ -n "$state_dir" ] \
		&& [ -f "$state_dir/database-create-attempted" ]; then
		if ! printf "DROP DATABASE IF EXISTS \`%s\`;\n" "$TEST_DB_NAME" | root_mysql >/dev/null; then
			printf '::error::could not remove the generated isolated test database\n' >&2
			status=1
		else
			rm -f "$state_dir/database-create-attempted"
		fi
	fi

	if docker compose ps -q wordpress >/dev/null 2>&1; then
		if ! docker compose exec -T wordpress rm -f "$CONTAINER_SECRET_FILE" >/dev/null 2>&1; then
			printf '::warning::could not remove the copied test credential file from WordPress\n' >&2
			status=1
		fi
	fi

	remove_generated_files
	if [ -n "$state_dir" ] && [ -d "$state_dir" ]; then
		if ! rmdir "$state_dir" 2>/dev/null; then
			printf '::warning::temporary test database state remains at %s\n' "$state_dir" >&2
			status=1
		fi
	fi

	return "$status"
}

on_create_exit() {
	local exit_status=$?
	trap - EXIT
	if [ "${SETUP_COMPLETE:-0}" != "1" ]; then
		if ! cleanup_resources; then
			exit_status=1
		fi
	fi
	exit "$exit_status"
}

require_running_stack() {
	local wordpress_container db_container variable
	wordpress_container="$(docker compose ps -q wordpress)"
	db_container="$(docker compose ps -q db)"
	[ -n "$wordpress_container" ] || fail "WordPress container is unavailable"
	[ -n "$db_container" ] || fail "database container is unavailable"
	[ "$(docker inspect -f '{{.State.Running}}' "$wordpress_container")" = "true" ] \
		|| fail "WordPress container is not running"
	[ "$(docker inspect -f '{{.State.Health.Status}}' "$db_container")" = "healthy" ] \
		|| fail "database container is not healthy"

	for variable in WORDPRESS_DB_HOST WORDPRESS_DB_NAME WORDPRESS_DB_USER WORDPRESS_DB_PASSWORD; do
		if ! docker compose exec -T wordpress printenv "$variable" >/dev/null 2>&1; then
			fail "required WordPress database setting is unavailable: $variable"
		fi
	done

	if ! docker compose exec -T db sh -c 'test -n "${MYSQL_ROOT_PASSWORD:-}"' >/dev/null 2>&1; then
		fail "database root password is unavailable in the database container"
	fi
	if ! docker compose exec -T db sh -c \
		'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysqladmin ping -uroot --silent' >/dev/null 2>&1; then
		fail "database is healthy but does not accept root connections"
	fi
}

restricted_mysql() {
	local database="$1"
	CURRENT_COMMAND="docker compose exec db mysql (restricted credentials supplied on stdin)"
	{
		printf '[client]\nuser=%s\npassword=%s\n' "$TEST_DB_USER" "$TEST_DB_PASSWORD"
	} | docker compose exec -T db sh -c '
		set -eu
		client_file=$(mktemp)
		trap '\''rm -f "$client_file"'\'' EXIT
		chmod 600 "$client_file"
		cat > "$client_file"
		mysql --defaults-extra-file="$client_file" --batch --skip-column-names \
			--database="$1" -e "SELECT DATABASE()"
	' sh "$database"
}

create_database() {
	local token shared_db database_exists user_exists grant_counts table_count active_database

	require_running_stack
	[ -n "${GITHUB_ENV:-}" ] || fail "GITHUB_ENV is required to pass the generated identity to later CI steps"
	[ -w "$GITHUB_ENV" ] || fail "GITHUB_ENV is not writable"
	[ ! -e "$CREDENTIALS_FILE" ] || fail ".conexao-test.env already exists; refusing to overwrite it"
	[ ! -e "$COMPOSE_ENV_FILE" ] || fail ".conexao-test-compose.env already exists; refusing to overwrite it"

	shared_db="$(docker compose exec -T wordpress printenv WORDPRESS_DB_NAME)"
	if ! [[ "$shared_db" =~ ^[A-Za-z0-9_]+$ ]]; then
		fail "shared WordPress database name is not a valid identifier"
	fi

	token="$(openssl rand -hex 16)"
	TEST_DB_NAME="conexao_stage05_test_${token}"
	TEST_DB_USER="conexao_test_${token:0:12}"
	TEST_DB_PASSWORD="$(openssl rand -hex 32)"
	if ! [[ "$TEST_DB_NAME" =~ ^[A-Za-z0-9_]+$ && "$TEST_DB_USER" =~ ^conexao_test_[a-f0-9]{12}$ ]]; then
		fail "generated test database identity is invalid"
	fi
	[ "$TEST_DB_NAME" != "$shared_db" ] || fail "generated test database matches the shared WordPress database"

	TEST_DB_STATE_DIR="$STATE_ROOT/conexao-test-db-$TEST_DB_NAME"
	mkdir -m 700 "$TEST_DB_STATE_DIR"
	export TEST_DB_NAME TEST_DB_USER TEST_DB_STATE_DIR
	trap on_create_exit EXIT

	CURRENT_OPERATION="export isolated database identity"
	CURRENT_COMMAND="write non-secret database identity to GITHUB_ENV"
	printf 'CONEXAO_TEST_DB_NAME=%s\nCONEXAO_TEST_DB_USER=%s\nCONEXAO_TEST_SHARED_DB_NAME=%s\n' \
		"$TEST_DB_NAME" "$TEST_DB_USER" "$shared_db" >> "$GITHUB_ENV"

	CURRENT_OPERATION="check generated database name is unused"
	database_exists="$(root_sql "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '$TEST_DB_NAME';")"
	[ "$database_exists" = "0" ] || fail "generated test database unexpectedly exists"
	: > "$TEST_DB_STATE_DIR/database-create-attempted"
	CURRENT_OPERATION="create isolated test database"
	root_sql "CREATE DATABASE \`$TEST_DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

	CURRENT_OPERATION="check generated test account is unused"
	user_exists="$(root_sql "SELECT COUNT(*) FROM mysql.user WHERE User = '$TEST_DB_USER' AND Host = '%';")"
	[ "$user_exists" = "0" ] || fail "generated test account unexpectedly exists"
	: > "$TEST_DB_STATE_DIR/user-create-attempted"
	CURRENT_OPERATION="create restricted test account"
	root_sql "CREATE USER '$TEST_DB_USER'@'%' IDENTIFIED BY '$TEST_DB_PASSWORD';"
	CURRENT_OPERATION="grant access only to isolated test database"
	root_sql "GRANT ALL PRIVILEGES ON \`$TEST_DB_NAME\`.* TO '$TEST_DB_USER'@'%';"

	# Clone the initialized CI WordPress site into the disposable schema. The
	# test administrator and synthetic fixtures are added after the service
	# switches to this clone; the original schema remains untouched.
	CURRENT_OPERATION="clone initialized WordPress database with GTID state disabled"
	CURRENT_COMMAND="docker compose exec db mysqldump | mysql"
	docker compose exec -T db bash -o pipefail -c '
		MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysqldump --single-transaction \
			--set-gtid-purged=OFF \
			--skip-lock-tables --no-tablespaces -uroot "$1" |
		MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot "$2"
	' bash "$shared_db" "$TEST_DB_NAME"

	CURRENT_OPERATION="verify cloned database contains WordPress tables"
	table_count="$(root_sql "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = '$TEST_DB_NAME' AND TABLE_TYPE = 'BASE TABLE';")"
	[ "$table_count" -gt 0 ] || fail "the cloned test database contains no WordPress tables"

	CURRENT_OPERATION="verify restricted test account can access generated database"
	if [ "$(restricted_mysql "$TEST_DB_NAME")" != "$TEST_DB_NAME" ]; then
		fail "restricted test user could not select the generated database"
	fi
	CURRENT_OPERATION="verify restricted test account cannot access shared database"
	if restricted_mysql "$shared_db" >/dev/null 2>&1; then
		fail "restricted test user can access the shared WordPress database"
	fi

	CURRENT_OPERATION="verify restricted test account has no privileges outside generated database"
	grant_counts="$(root_sql "SELECT (SELECT COUNT(*) FROM information_schema.USER_PRIVILEGES WHERE GRANTEE = CONCAT(CHAR(39), '$TEST_DB_USER', CHAR(39), '@', CHAR(39), '%', CHAR(39)) AND PRIVILEGE_TYPE <> 'USAGE'), (SELECT COUNT(*) FROM information_schema.SCHEMA_PRIVILEGES WHERE GRANTEE = CONCAT(CHAR(39), '$TEST_DB_USER', CHAR(39), '@', CHAR(39), '%', CHAR(39)) AND TABLE_SCHEMA <> '$TEST_DB_NAME');")"
	[ "$grant_counts" = $'0\t0' ] || fail "restricted test user has privileges outside the generated database"

	CURRENT_OPERATION="write protected credential and Compose environment files"
	CURRENT_COMMAND="mktemp, printf, chmod 0600, and atomic rename (secret values redacted)"
	local database_host
	database_host="$(docker compose exec -T wordpress printenv WORDPRESS_DB_HOST)"
	CREDENTIALS_TMP="$(mktemp "$REPO_ROOT/.conexao-test.env.XXXXXX")"
	COMPOSE_ENV_TMP="$(mktemp "$REPO_ROOT/.conexao-test-compose.env.XXXXXX")"
	printf 'CONEXAO_TEST_DB_USER=%s\nCONEXAO_TEST_DB_PASSWORD=%s\n' \
		"$TEST_DB_USER" "$TEST_DB_PASSWORD" > "$CREDENTIALS_TMP"
	printf 'WORDPRESS_DB_HOST=%s\nWORDPRESS_DB_NAME=%s\nWORDPRESS_DB_USER=%s\nWORDPRESS_DB_PASSWORD=%s\nCONEXAO_TEST_DB_NAME=%s\nCONEXAO_TEST_DB_USER=%s\nCONEXAO_TEST_SHARED_DB_NAME=%s\n' \
		"$database_host" "$TEST_DB_NAME" "$TEST_DB_USER" "$TEST_DB_PASSWORD" \
		"$TEST_DB_NAME" "$TEST_DB_USER" "$shared_db" > "$COMPOSE_ENV_TMP"
	chmod 600 "$CREDENTIALS_TMP" "$COMPOSE_ENV_TMP"
	mv "$CREDENTIALS_TMP" "$CREDENTIALS_FILE"
	CREDENTIALS_TMP=""
	mv "$COMPOSE_ENV_TMP" "$COMPOSE_ENV_FILE"
	COMPOSE_ENV_TMP=""

	CURRENT_OPERATION="switch WordPress service to isolated test database"
	CURRENT_COMMAND="docker compose up --force-recreate wordpress (credential values redacted)"
	: > "$TEST_DB_STATE_DIR/service-switch-attempted"
	docker compose --env-file "$COMPOSE_ENV_FILE" up -d --no-deps --force-recreate wordpress

	CURRENT_OPERATION="verify WordPress service connected to isolated test database"
	CURRENT_COMMAND="docker compose exec wordpress printenv WORDPRESS_DB_NAME"
	active_database=""
	for _ in $(seq 1 60); do
		if active_database="$(docker compose exec -T wordpress printenv WORDPRESS_DB_NAME 2>/dev/null)"; then
			:
		else
			active_database=""
		fi
		if [ "$active_database" = "$TEST_DB_NAME" ]; then
			break
		fi
		sleep 2
	done
	[ "$active_database" = "$TEST_DB_NAME" ] || fail "WordPress did not switch to the isolated test database"

	CURRENT_OPERATION="verify WordPress readiness on isolated test database"
	CURRENT_COMMAND="curl local WordPress readiness endpoint"
	for _ in $(seq 1 60); do
		if curl -fsS -o /dev/null --max-time 5 "${CONEXAO_TEST_BASE_URL:-http://localhost:8080}/" \
			>/dev/null 2>&1; then
			break
		fi
		sleep 2
	done
	curl -fsS -o /dev/null --max-time 10 "${CONEXAO_TEST_BASE_URL:-http://localhost:8080}/" \
		|| fail "WordPress did not become ready against the isolated test database"

	SETUP_COMPLETE=1
	printf 'Isolated test database ready: %s\n' "$TEST_DB_NAME"
	printf 'Restricted test account verified for the isolated schema; credentials are not displayed.\n'
}

cleanup_database() {
	local test_db_name="${CONEXAO_TEST_DB_NAME:-}"
	local test_db_user="${CONEXAO_TEST_DB_USER:-}"

	if [ -z "$test_db_name" ] && [ -z "$test_db_user" ]; then
		return 0
	fi
	[[ "$test_db_name" =~ ^conexao_stage05_test_[a-f0-9]{32}$ ]] \
		|| fail "refusing cleanup for an unrecognized test database name"
	[[ "$test_db_user" =~ ^conexao_test_[a-f0-9]{12}$ ]] \
		|| fail "refusing cleanup for an unrecognized test account"

	TEST_DB_NAME="$test_db_name"
	TEST_DB_USER="$test_db_user"
	TEST_DB_STATE_DIR="$STATE_ROOT/conexao-test-db-$TEST_DB_NAME"
	export TEST_DB_NAME TEST_DB_USER TEST_DB_STATE_DIR
	cleanup_resources
	printf 'Isolated test database resources cleaned up.\n'
}

case "${1:-}" in
	create)
		[ "$#" -eq 1 ] || fail "usage: $0 create"
		create_database
		;;
	cleanup)
		[ "$#" -eq 1 ] || fail "usage: $0 cleanup"
		cleanup_database
		;;
	*)
		fail "usage: $0 {create|cleanup}"
		;;
esac
