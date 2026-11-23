#!/usr/bin/env bash
set -Eeuo pipefail

: "${MIKSPRIT_API_DIR:?Set MIKSPRIT_API_DIR to the deployed api directory}"
: "${MIKSPRIT_LOCK_DIR:?Set MIKSPRIT_LOCK_DIR to a writable lock directory}"
: "${MIKSPRIT_LOG_DIR:?Set MIKSPRIT_LOG_DIR to a writable log directory}"

PHP_BIN="${PHP_BIN:-/usr/bin/php}"
TASK="${1:-}"

case "$TASK" in
  payments) COMMAND=(miksprit:payments:process-paid-orders --limit=25) ;;
  vouchers) COMMAND=(miksprit:vouchers:expire --limit=100) ;;
  subscriptions) COMMAND=(miksprit:subscriptions:refresh --limit=500) ;;
  router-sessions) COMMAND=(miksprit:routers:sync-sessions --limit=25) ;;
  refresh-sessions) COMMAND=(miksprit:auth:prune-refresh-sessions) ;;
  *) echo "Unknown Miksprit scheduled task: $TASK" >&2; exit 2 ;;
esac

if [[ ! -f "$MIKSPRIT_API_DIR/bin/console" ]]; then
  echo "Symfony console not found under MIKSPRIT_API_DIR" >&2
  exit 2
fi
if [[ ! -x "$PHP_BIN" ]]; then
  echo "PHP executable is missing or not executable: $PHP_BIN" >&2
  exit 2
fi

mkdir -p "$MIKSPRIT_LOCK_DIR" "$MIKSPRIT_LOG_DIR"
exec 9>"$MIKSPRIT_LOCK_DIR/$TASK.lock"
if ! flock -n 9; then
  exit 0
fi

cd "$MIKSPRIT_API_DIR"
"$PHP_BIN" bin/console "${COMMAND[@]}" --env=prod >> "$MIKSPRIT_LOG_DIR/$TASK.log" 2>&1
