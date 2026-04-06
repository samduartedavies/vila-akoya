#!/usr/bin/env bash
# Confirms vila-campsite-booking exists in CLI and wordpress containers after sync.
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"
bash scripts/wp-env.sh start
bash scripts/sync-vila-plugin-to-wp-env.sh
bash scripts/wp-env.sh run cli sh -c 'test -f /var/www/html/wp-content/plugins/vila-campsite-booking/vila-campsite-booking.php && echo "OK: plugin file in CLI container"'
WP="$(docker ps --format '{{.Names}}' | grep 'wordpress-1$' | grep -v 'tests-wordpress' | head -1 || true)"
if [[ -z "${WP}" ]]; then
	echo "error: wordpress-1 container not found" >&2
	exit 1
fi
docker exec "${WP}" sh -c 'test -f /var/www/html/wp-content/plugins/vila-campsite-booking/vila-campsite-booking.php && echo "OK: plugin file in wordpress (Apache) container — Plugins screen will list it"'
