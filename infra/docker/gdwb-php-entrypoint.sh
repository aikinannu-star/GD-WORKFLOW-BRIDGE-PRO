#!/bin/sh
set -e
# Lightweight entrypoint wrapper for PHP development services.
# If SERVICE is set, start the corresponding service `services/$SERVICE/server.php`
# on SERVICE_PORT (or default port 8000). Otherwise, forward to docker-php-entrypoint
# with any provided arguments or fall back to starting the gateway.

SERVICE=${SERVICE:-}
PORT=${SERVICE_PORT:-}

if [ -n "$SERVICE" ]; then
  TARGET_DIR="services/$SERVICE"
  if [ -f "$TARGET_DIR/server.php" ]; then
    PORT=${PORT:-8000}
    echo "[gdwb-entrypoint] Starting service '$SERVICE' on port $PORT"
    exec docker-php-entrypoint php -S 0.0.0.0:${PORT} -t "$TARGET_DIR" "$TARGET_DIR/server.php"
  else
    echo "[gdwb-entrypoint] Service directory '$TARGET_DIR' missing or no server.php found, falling back to args"
  fi
fi

if [ "$#" -gt 0 ]; then
  exec docker-php-entrypoint "$@"
fi

echo "[gdwb-entrypoint] No service specified, starting default gateway on 8000"
exec docker-php-entrypoint php -S 0.0.0.0:8000 -t services/gateway services/gateway/server.php
