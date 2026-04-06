#!/usr/bin/env bash
# Restore plugins, theme, and permalinks after `wp-env destroy` or a fresh DB.
# Run from repo root: npm run wp:restore
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

echo "==> Starting wp-env (Docker)…"
bash scripts/wp-env.sh start

bash scripts/wp-env-install-plugins.sh

echo "==> Syncing theme into containers (fixes empty bind mounts)…"
bash scripts/sync-theme-to-wp-env.sh

echo "==> Syncing vila-campsite-booking into containers (Apache often has empty plugin mount)…"
bash scripts/sync-vila-plugin-to-wp-env.sh

echo "==> Activating theme and fixing permalinks / URLs…"
bash scripts/wp-env.sh run cli sh -c '
set -e
cd /var/www/html
wp theme activate vila-akoya
wp option update permalink_structure "/%postname%/" 2>/dev/null || true
wp rewrite flush --hard
'

echo ""
echo "==> Current site URL (open this in the browser):"
bash scripts/wp-env.sh run cli wp option get siteurl || true
echo ""
echo "Done. If pages are missing, run: npm run wp:seed"
