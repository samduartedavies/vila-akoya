#!/usr/bin/env bash
# Push the vila-akoya theme into running wp-env containers.
#
# Docker Desktop can leave ./wp-content/themes/vila-akoya bind-mounted but empty
# inside the container. Copying straight into that path often does not stick.
# This copies to /tmp first, then cp -a into wp-content/themes/vila-akoya (works).
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# Required for wp-env’s Docker bind-mount of ./wp-content/plugins/vila-campsite-booking (see .wp-env.json).
mkdir -p "${ROOT}/wp-content/plugins/vila-campsite-booking"
THEME_SRC="${ROOT}/wp-content/themes/vila-akoya"
DEST="/var/www/html/wp-content/themes/vila-akoya"
TMP="/tmp/vila-akoya-theme"

if [[ ! -f "${THEME_SRC}/style.css" ]]; then
	echo "error: theme not found at ${THEME_SRC}" >&2
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
	echo "Syncing theme → ${cname} (${DEST})"
	docker cp "${THEME_SRC}/." "${cname}:${TMP}/"
	docker exec "${cname}" sh -c "cp -a ${TMP}/. ${DEST}/"
}

sync_container "${CLI}"
if [[ -n "${WP}" ]]; then
	sync_container "${WP}"
fi

echo "Theme sync done."
