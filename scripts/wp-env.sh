#!/usr/bin/env bash
# Run wp-env with repo root as cwd so .wp-env.json paths like ./wp-content/plugins/... resolve correctly.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
# Docker bind-mounts local plugin paths from .wp-env.json; the host directory must exist or
# Docker reports: mkdir .../vila-campsite-booking: no such file or directory (missing parent path).
mkdir -p "${ROOT}/wp-content/plugins/vila-campsite-booking"

# Fast path for `start`: wp-env always runs `compose up --build`, which hangs when Docker Hub
# base images are missing/unreachable even though local env images already exist.
# If the env is already built, bring containers up without rebuilding.
if [[ "${1:-}" == "start" ]]; then
  ENV_HASH="$(
    node -e "
      const crypto = require('crypto');
      const path = require('path');
      const configPath = path.resolve(process.cwd(), '.wp-env.json');
      process.stdout.write(
        crypto.createHash('md5').update(configPath).digest('hex')
      );
    "
  )"
  ENV_DIR="${HOME}/.wp-env/${ENV_HASH}"
  COMPOSE="${ENV_DIR}/docker-compose.yml"
  WP_IMAGE="${ENV_HASH}-wordpress:latest"
  if [[ -f "${COMPOSE}" ]] && docker image inspect "${WP_IMAGE}" >/dev/null 2>&1; then
    echo "Using cached wp-env images (skip rebuild). Site: http://localhost:8888"
    docker compose -f "${COMPOSE}" up -d --no-build mysql wordpress cli
    exit 0
  fi
fi

exec npx wp-env "$@"
