#!/usr/bin/env bash
# Local replica of the GitHub Actions integration job's environment bootstrap,
# so a fresh `docker compose down -v && docker compose up -d` can be verified
# locally with the SAME steps CI performs.
#
# Derived from .github/workflows/ci.yml ("Prepare WordPress",
# "Activate the repository theme and plugins").
set -euo pipefail

cd "$(dirname "$0")/../../.."

WP_CONTAINER="$(docker compose ps -q wordpress)"
if [ -z "$WP_CONTAINER" ]; then
  echo "no wordpress container; run: docker compose up -d" >&2
  exit 1
fi

for v in WORDPRESS_DB_HOST WORDPRESS_DB_NAME WORDPRESS_DB_USER WORDPRESS_DB_PASSWORD; do
  val="$(docker inspect -f '{{range .Config.Env}}{{println .}}{{end}}' "$WP_CONTAINER" \
         | grep "^$v=" | head -1 | cut -d= -f2-)"
  export "$v=$val"
done

wp() {
  docker run --rm --network "container:$WP_CONTAINER" --volumes-from "$WP_CONTAINER" \
    --user 33:33 -e HOME=/tmp \
    -e WORDPRESS_DB_HOST -e WORDPRESS_DB_NAME \
    -e WORDPRESS_DB_USER -e WORDPRESS_DB_PASSWORD \
    --entrypoint wp wordpress:cli --path=/var/www/html --allow-root "$@"
}

if ! docker exec "$WP_CONTAINER" test -f /var/www/html/wp-config.php; then
  echo "wp-config.php is missing; the stack did not initialise" >&2
  docker compose logs wordpress
  exit 1
fi

if ! wp core is-installed --network=localhost 2>/dev/null; then
  wp core install --url=http://localhost:8080 \
    --title="Conexao BR Ireland (CI)" --admin_user=ci --admin_password=ci \
    --admin_email=ci@example.invalid --skip-email
else
  echo "WordPress already installed; skipping core install"
fi

# Polylang Free, pinned version (the value CI uses, .github/workflows/ci.yml).
POLYLANG_VERSION="${POLYLANG_VERSION:-3.8.9}"

wp theme activate conexao-br-irlanda
for slug in conexao-data-model conexao-content conexao-event-runtime \
            conexao-admin-ux conexao-event-importer conexao-leisure-migration \
            conexao-sponsor-migration; do
  wp plugin activate "$slug"
done

wp plugin is-installed polylang || wp plugin install polylang --version="$POLYLANG_VERSION" --force
wp plugin activate polylang
wp language core install pt_BR --activate
wp option update timezone_string Europe/Dublin
wp option update date_format 'j \d\e F \d\e Y'
wp rewrite structure '/%postname%/' --hard
wp eval-file /var/www/html/scripts/run-polylang-setup.php dry-run
wp eval-file /var/www/html/scripts/run-polylang-setup.php
wp plugin activate conexao-translation-rollout
wp plugin activate conexao-en-translation
wp plugin list