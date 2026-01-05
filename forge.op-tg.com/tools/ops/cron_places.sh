#!/usr/bin/env bash
# cPanel cron wrapper for Places queue runner (conservative)
# Usage in cPanel Cron Jobs: */15 * * * * /home/USER/site/current/tools/ops/cron_places.sh
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="${SCRIPT_DIR}/../.."
PHP_BIN=${PHP_BIN:-php}
LOG_DIR="${ROOT_DIR}/storage/logs/ops"
mkdir -p "$LOG_DIR"
LOG_FILE="$LOG_DIR/cron_places.log"
{
  echo "[$(date -Iseconds)] cron_places: starting"
  "$PHP_BIN" "$ROOT_DIR/tools/ops/run_places_queue.php" --max 5
  echo "[$(date -Iseconds)] cron_places: finished"
} >>"$LOG_FILE" 2>&1
