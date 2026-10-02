#!/usr/bin/env bash
#
# CI-equivalent clean run — Stage 22 verification harness (evidence tooling).
#
# This is NOT a product script and NOT a second fixture lifecycle. It is a
# faithful LOCAL REPLICA of the steps in `.github/workflows/ci.yml` (job
# `integration`), extracted so a developer can reproduce the CI verdict
# locally: an ephemeral database, the same WordPress install, the same
# plugin/theme activation, the same `bootstrap-ci-fixtures.php --apply --verify`
# provisioning and the same `./scripts/run-tests.sh` harness invocation.
#
# The whole point is that the run must be CLEAN. A warm local volume hides
# exactly the class of defect this harness exists to catch, so the database is
# destroyed and rebuilt with `docker compose down -v` before anything runs.
#
# Usage:
#   docs/evidence/2026-10-02-stage-22/clean-ci-run.sh <label>
#
# `label` names the output files, so two runs can be compared side by side.
#
# Safety: local only. It never contacts production and needs no credential; the
# database credentials are read back from the running container, exactly as the
# CI job does, so none is hard-coded here.

set -euo pipefail

LABEL="${1:?usage: clean-ci-run.sh <label>}"
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
cd "$REPO_ROOT"

OUT_DIR="$REPO_ROOT/docs/evidence/2026-10-02-stage-22"
mkdir -p "$OUT_DIR"

# The CI job pins Polylang. Keep the two in step.
POLYLANG_VERSION="${POLYLANG_VERSION:-3.8.9}"

step() { printf '\n=== %s ===\n' "$*"; }

# The WP-CLI wrapper, identical in intent to the CI job's: address WordPress
# through the running container, inherit its volume and bind mounts, run as
# 33:33 so writes into the volume succeed, and read the database credentials
# back out of the container environment (never hard-coded, never logged).
wp_cli() {
	local wp_container="$1"; shift
	for v in WORDPRESS_DB_HOST WORDPRESS_DB_NAME WORDPRESS_DB_USER WORDPRESS_DB_PASSWORD; do
		local val
		val="$(docker inspect -f '{{range .Config.Env}}{{println .}}{{end}}' "$wp_container" \
			| grep "^$v=" | head -1 | cut -d= -f2-)"
		export "$v=$val"
	done
	docker run --rm --network "container:$wp_container" --volumes-from "$wp_container" \
		--user 33:33 -e HOME=/tmp \
		-e WORDPRESS_DB_HOST -e WORDPRESS_DB_NAME \
		-e WORDPRESS_DB_USER -e WORDPRESS_DB_PASSWORD \
		--entrypoint wp wordpress:cli --path=/var/www/html --allow-root "$@"
}


# `dist/` is git-ignored build output. The two hermetic gates build their own
# artifacts into a temporary directory, so a leftover `dist/` must not be what
# makes them pass. Remove it so the run proves a clean checkout.
step "clean the working tree of generated release artifacts"
rm -rf "$REPO_ROOT/dist"
echo "dist/ present: $([ -d "$REPO_ROOT/dist" ] && echo yes || echo no)"

step "destroy the database and every volume (this is what makes the run clean)"
docker compose down -v --remove-orphans

step "start the ephemeral stack"
docker compose up -d

step "wait for the database and for HTTP"
for i in $(seq 1 60); do
	if [ "$(docker inspect -f '{{.State.Health.Status}}' "$(docker compose ps -q db)" 2>/dev/null)" = "healthy" ]; then
		echo "db healthy"; break
	fi
	if [ "$i" -eq 60 ]; then echo "::error::database never became healthy"; docker compose logs db; exit 1; fi
	sleep 2
done
for i in $(seq 1 90); do
	code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 5 http://localhost:8080/ || true)"
	case "$code" in
		200|301|302) echo "WordPress is serving (status $code)"; break ;;
	esac
	if [ "$i" -eq 90 ]; then echo "::error::WordPress did not become ready"; docker compose logs wordpress; exit 1; fi
	sleep 2
done

WP_CONTAINER="$(docker compose ps -q wordpress)"
[ -n "$WP_CONTAINER" ] || { echo "::error::no wordpress container"; exit 1; }

step "install WordPress"
# The database healthcheck and the front-end 200 can both pass a second or two
# before MySQL actually accepts the application user, so the FIRST wp-cli call
# after a fresh volume can fail with "Error establishing a database connection".
# That is a readiness race, not a misconfiguration, and it is retried here
# rather than masked: a genuine credential or host fault still fails, because
# the retry gives up and the error is reported.
installed=0
for attempt in $(seq 1 30); do
	if wp_cli "$WP_CONTAINER" core is-installed --network=localhost >/dev/null 2>&1; then
		echo "WordPress is already installed (attempt $attempt)"
		installed=1
		break
	fi
	if wp_cli "$WP_CONTAINER" core install --url=http://localhost:8080 \
		--title="Conexao BR Ireland (CI)" --admin_user=ci --admin_password=ci \
		--admin_email=ci@example.invalid --skip-email; then
		echo "WordPress installed (attempt $attempt)"
		installed=1
		break
	fi
	sleep 2
done
[ "$installed" -eq 1 ] || { echo "::error::WordPress could not be installed"; exit 1; }

step "activate the theme and plugins"
wp_cli "$WP_CONTAINER" theme activate conexao-br-irlanda
for slug in conexao-data-model conexao-content conexao-event-runtime \
            conexao-admin-ux conexao-event-importer conexao-leisure-migration \
            conexao-sponsor-migration; do
	wp_cli "$WP_CONTAINER" plugin activate "$slug"
done
wp_cli "$WP_CONTAINER" plugin is-installed polylang \
	|| wp_cli "$WP_CONTAINER" plugin install polylang --version="$POLYLANG_VERSION" --force
wp_cli "$WP_CONTAINER" plugin activate polylang
wp_cli "$WP_CONTAINER" language core install pt_BR --activate
wp_cli "$WP_CONTAINER" option update timezone_string Europe/Dublin
wp_cli "$WP_CONTAINER" option update date_format 'j \d\e F \d\e Y'
wp_cli "$WP_CONTAINER" rewrite structure '/%postname%/' --hard
wp_cli "$WP_CONTAINER" eval-file /var/www/html/scripts/run-polylang-setup.php dry-run
wp_cli "$WP_CONTAINER" eval-file /var/www/html/scripts/run-polylang-setup.php
wp_cli "$WP_CONTAINER" plugin activate conexao-translation-rollout
wp_cli "$WP_CONTAINER" plugin activate conexao-en-translation


step "build the deterministic synthetic site"
docker compose exec -T wordpress env HTTP_HOST=localhost REQUEST_URI=/ \
	php /var/www/html/scripts/bootstrap-ci-fixtures.php --apply --verify

step "the three ICS events the Stage 20 HTTP suite needs must exist NOW"
# Recorded as machine evidence, and asserted: the acceptance suite must be able
# to pass because the documented fixture path created these records, never
# because another test happened to leave them behind.
#
# These are the DETERMINISTIC CI FIXTURE slugs, emitted by
# `scripts/data/ci-fixture-laois-ics.php` and imported by bootstrap step 4c
# through the real importer engine. The real captured Laois titles
# ("Chair Yoga At Portlaoise Library", …) are deliberately NOT here: they are
# owned by the plugin's own committed-feed suite
# `wp-content/plugins/conexao-event-importer/tests/test-ics-occurrence-dates.php`,
# which proves the exact-date contract without a database.
ics_found="$(docker compose exec -T db sh -c \
	'mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE" -N -B -e "
		SELECT post_name FROM wp_posts
		WHERE post_type = \"event\" AND post_status = \"publish\"
		  AND post_name IN (
		    \"evento-ci-serie-semanal-laois\",
		    \"evento-ci-multiday-laois\",
		    \"evento-ci-all-day-laois\")
		ORDER BY post_name;"' | tr -d '\r')"
printf '%s\n' "$ics_found" | tee "$OUT_DIR/$LABEL-ics-events.tsv"
ics_count="$(printf '%s' "$ics_found" | grep -c . || true)"
[ "$ics_count" -eq 3 ] \
	|| { echo "::error::expected 3 ICS fixture events, found $ics_count"; exit 1; }
echo "all 3 ICS fixture events exist, created by the documented fixture path"

step "run the shared test harness"
set +e
./scripts/run-tests.sh 2>&1 | tee "$OUT_DIR/$LABEL-run-tests.txt"
HARNESS="${PIPESTATUS[0]}"
set -e
echo "run-tests.sh exit status: $HARNESS"

step "Stage L permanent invariant gates"
set +e
python3 scripts/verify-permanent-gates.py 2>&1 | tee "$OUT_DIR/$LABEL-permanent-gates.txt"
GATES="${PIPESTATUS[0]}"
set -e

step "result"
echo "harness=$HARNESS gates=$GATES" | tee "$OUT_DIR/$LABEL-exit-code.txt"
# The database is left in place for inspection; the caller tears it down.
# `dist/` must NOT be left behind by a test.
echo "dist/ after the run: $([ -d "$REPO_ROOT/dist" ] && echo 'PRESENT (unexpected)' || echo absent)"
exit "$HARNESS"
