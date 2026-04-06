#!/usr/bin/env bash
# Run wp-env with repo root as cwd so .wp-env.json paths like ./wp-content/plugins/... resolve correctly.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
exec npx wp-env "$@"
