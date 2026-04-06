#!/usr/bin/env bash
# Push vila-campsite-booking into running wp-env containers (especially wordpress-1).
#
# Docker Desktop can leave ./wp-content/plugins/vila-campsite-booking bind-mounted but empty
# inside the Apache container while the CLI container sees files. The Plugins admin screen
# reads the filesystem from wordpress-1, so the plugin must be copied there (same pattern as
# scripts/sync-theme-to-wp-env.sh).
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_SRC="${ROOT}/wp-content/plugins/vila-campsite-booking"
DEST="/var/www/html/wp-content/plugins/vila-campsite-booking"
TMP="/tmp/vila-campsite-booking-sync"

if [[ ! -f "${PLUGIN_SRC}/vila-campsite-booking.php" ]]; then
	echo "error: plugin not found at ${PLUGIN_SRC}" >&2
	exit 1
fi

CLI="$(docker ps --format '{{.Names}}' | grep 'cli-1$' | grep -v 'tests-cli' | head -1 || true)"
WP="$(docker ps --format '{{.Names}}' | grep 'wordpress-1$' | grep -v 'tests-wordpress' | head -1 || true)"

if [[ -z "${CLI}" ]]; then
	echo "error: no wp-env CLI container found (name *-cli-1, not tests). Run: npm run wp:start" >&2
	exit 1
fi

sync_container() {
	local cname="$1"
	echo "Syncing vila-campsite-booking → ${cname} (${DEST})"
	docker cp "${PLUGIN_SRC}/." "${cname}:${TMP}/"
	docker exec "${cname}" sh -c "mkdir -p ${DEST} && cp -a ${TMP}/. ${DEST}/"
}

sync_container "${CLI}"
if [[ -n "${WP}" ]]; then
	sync_container "${WP}"
fi

echo "Plugin sync done."
