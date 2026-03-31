#!/usr/bin/env bash
# Copy the Vila Akoya theme from this repo into ~/.wp-env/…/WordPress/wp-content/themes/vila-akoya
#
# Docker Desktop often fails to bind-mount a single theme folder (the container sees an empty directory
# even though your Mac has files). Symlinks are also invisible inside the container. A real directory
# copy fixes that. Re-run this script after `wp-env clean all` or when you change env hash.
#
# Usage (from repo root):
#   npm run theme:sync
#   npx wp-env run cli wp theme activate vila-akoya
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SRC="$ROOT/wp-content/themes/vila-akoya/"
WP_ENV_ROOT="${WP_ENV_HOME:-$HOME/.wp-env}"

if [[ ! -f "${SRC}style.css" ]]; then
	echo "Missing ${SRC}style.css" >&2
	exit 1
fi

FOUND=0
while IFS= read -r -d '' THEMES_DIR; do
	FOUND=1
	DEST="$THEMES_DIR/vila-akoya"
	rm -rf "$DEST"
	mkdir -p "$DEST"
	rsync -a --delete "$SRC" "$DEST/"
	echo "Synced theme -> $DEST"
done < <(find "$WP_ENV_ROOT" -path '*/WordPress/wp-content/themes' -type d -print0 2>/dev/null)

if [[ "$FOUND" -eq 0 ]]; then
	echo "No ~/.wp-env/.../WordPress/wp-content/themes found. Run: npx wp-env start" >&2
	exit 1
fi
