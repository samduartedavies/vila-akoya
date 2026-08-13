#!/usr/bin/env bash
# Start VAVA, stopping any previous local copy first so Telegram is not polled twice.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

stop_old() {
  local signal="$1"
  local pids
  pids="$(pgrep -f 'vila-akoya-telegram-bot\.js' || true)"
  if [ -z "$pids" ]; then
    return 0
  fi
  for pid in $pids; do
    echo "Stopping previous telegram bot (pid $pid)"
    kill -s "$signal" "$pid" 2>/dev/null || true
  done
}

stop_old TERM
sleep 1
stop_old KILL
rm -f "$ROOT/scripts/.vila-akoya-telegram-state.json.lock"

exec node scripts/vila-akoya-telegram-bot.js "$@"
