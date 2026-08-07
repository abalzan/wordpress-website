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

set -e

echo "[entrypoint] Fixing permissions on bind-mounted directories..."

chmod -R a+rwX /var/www/html/wp-content/themes/conexao-br-irlanda
chmod -R a+rwX /var/www/html/wp-content/plugins/conexao-content

echo "[entrypoint] Permissions fixed."

# Execute the original WordPress entrypoint.
# If no command was provided (e.g. CMD was overridden), default to Apache.
if [ $# -eq 0 ]; then
    exec docker-entrypoint.sh apache2-foreground
else
    exec docker-entrypoint.sh "$@"
fi
