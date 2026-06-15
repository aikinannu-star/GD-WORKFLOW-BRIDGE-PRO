#!/usr/bin/env bash
# Starts local PHP built-in servers for core services for quick dev (no Docker required)
set -euo pipefail
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT_DIR"

LOGS_DIR="$ROOT_DIR/logs"
mkdir -p "$LOGS_DIR"

services=(
  "license:8001:license-server:license-server/server.php"
  "gateway:8000:services/gateway:services/gateway/server.php"
  "auth:8002:services/auth:services/auth/server.php"
  "tenant:8003:services/tenant:services/tenant/server.php"
  "cms:8004:services/cms:services/cms/server.php"
  "billing:8005:services/billing:services/billing/server.php"
  "usage:8007:services/usage:services/usage/server.php"
)

echo "Starting PHP services in background (logs -> $LOGS_DIR)"
for s in "${services[@]}"; do
  IFS=":" read -r name port dir file <<< "$s"
  out="$LOGS_DIR/${name}.log"
  err="$LOGS_DIR/${name}.err"
  cmd=(php -S 0.0.0.0:${port} -t ${dir} ${file})
  echo "Starting ${name} on port ${port}..."
  nohup "${cmd[@]}" >"$out" 2>"$err" &
done

echo "All services started. Use 'ps aux | grep php' to inspect processes."