#!/bin/bash
#
# entrypoint-fix-permissions.sh
#
# Custom entrypoint for the WordPress container that fixes permissions on
# bind-mounted theme/plugin directories before starting Apache.
#
# The web server runs as www-data (uid 33), but bind-mounted files from the
# host are owned by the host user. Without write access, WordPress cannot
# update/remove themes or plugins via the admin UI (e.g. "Could not remove
# the old theme").
#
# We use chmod (not chown) to avoid changing ownership on the host filesystem,
# which would make it harder for the host user to edit files directly.
#
# The volume-backed directories below are a different case: `uploads/` and
# `upgrade/` live on the `wordpress_data` Docker volume, NOT on a bind mount, so
# there is no host ownership to protect. WordPress creates the year/month
# subdirectories (e.g. uploads/2026/09) as whichever user ran the request first,
# and a root-owned tree makes the web server (www-data, uid 33) unable to write —
# which surfaces in wp-admin as:
#
#     "The uploaded file could not be moved to wp-content/uploads/2026/09."
#
# `chown -R www-data:www-data` is therefore safe and correct here: it only
# touches the volume, it is idempotent, and it re-applies on every container
# start so a volume recreated by `docker compose down -v` self-heals instead of
# leaving the admin unable to install plugins or upload media.
#

set -e

echo "[entrypoint] Fixing permissions on bind-mounted directories..."

chmod -R a+rwX /var/www/html/wp-content/themes/conexao-br-irlanda
chmod -R a+rwX /var/www/html/wp-content/plugins/conexao-content

echo "[entrypoint] Fixing ownership on volume-backed content directories..."

# Volume-only (not bind-mounted): correct ownership so the web server can write.
for dir in uploads upgrade; do
    target="/var/www/html/wp-content/${dir}"
    if [ -d "$target" ]; then
        chown -R www-data:www-data "$target"
    fi
done

# Belt and braces: the top-level wp-content must stay traversable/writable by
# www-data even if a future image ships a different default owner.
chown www-data:www-data /var/www/html/wp-content 2>/dev/null || true

echo "[entrypoint] Permissions fixed."

# Execute the original WordPress entrypoint.
# If no command was provided (e.g. CMD was overridden), default to Apache.
if [ $# -eq 0 ]; then
    exec docker-entrypoint.sh apache2-foreground
else
    exec docker-entrypoint.sh "$@"
fi
