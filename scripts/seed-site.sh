#!/usr/bin/env bash
# Run from the repository root (folder that contains wp-content/ and .wp-env.json).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

SEED_REL="wp-content/themes/vila-akoya/scripts/seed-vila-akoya.php"

if ! [[ -f "$SEED_REL" ]]; then
	echo "Expected seed script at $SEED_REL" >&2
	exit 1
fi

if command -v wp-env >/dev/null 2>&1; then
	echo "Running: wp-env run cli wp eval-file $SEED_REL"
	wp-env run cli wp eval-file "$SEED_REL"
elif [[ -x "$ROOT/node_modules/.bin/wp-env" ]]; then
	echo "Running: node_modules/.bin/wp-env run cli wp eval-file $SEED_REL"
	"$ROOT/node_modules/.bin/wp-env" run cli wp eval-file "$SEED_REL"
elif command -v npx >/dev/null 2>&1; then
	echo "Running: npx wp-env run cli wp eval-file $SEED_REL"
	npx wp-env run cli wp eval-file "$SEED_REL"
elif command -v wp >/dev/null 2>&1; then
	echo "Running: wp eval-file $SEED_REL"
	wp eval-file "$SEED_REL"
else
	echo "Could not find wp-env. From the project root run:" >&2
	echo "  npm install" >&2
	echo "Then either:" >&2
	echo "  npx wp-env start" >&2
	echo "  npm run start" >&2
	echo "Or seed the site:" >&2
	echo "  npx wp-env run cli wp eval-file $SEED_REL" >&2
	exit 1
fi
