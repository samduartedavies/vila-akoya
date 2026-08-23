#!/usr/bin/env bash
# Copy local bot data files onto the Fly.io volume (run once before first deploy,
# or after rebuilding the volume).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
APP="${FLY_APP:-vila-akoya-telegram-bot}"

upload() {
  local src="$1"
  local dest="$2"
  if [ ! -f "$src" ]; then
    echo "Skip (missing): $src"
    return 0
  fi
  echo "Uploading $(basename "$src") -> $dest"
  fly ssh sftp shell -a "$APP" <<EOF
put "$src" "$dest"
EOF
}

echo "Uploading bot data to Fly app: $APP"
upload "$ROOT/scripts/.vila-akoya-notion.json" /data/.vila-akoya-notion.json
upload "$ROOT/scripts/.vila-akoya-docs-index.json" /data/.vila-akoya-docs-index.json
upload "$ROOT/scripts/.vila-akoya-telegram-state.json" /data/.vila-akoya-telegram-state.json
echo "Done."
