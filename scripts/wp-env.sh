#!/usr/bin/env bash
# Run wp-env with repo root as cwd so .wp-env.json paths like ./wp-content/plugins/... resolve correctly.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
# Docker bind-mounts local plugin paths from .wp-env.json; the host directory must exist or
# Docker reports: mkdir .../vila-campsite-booking: no such file or directory (missing parent path).
mkdir -p "${ROOT}/wp-content/plugins/vila-campsite-booking"
exec npx wp-env "$@"
