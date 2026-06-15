#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"

# Detect docker compose command
if command -v docker-compose >/dev/null 2>&1; then
  DOCKER_COMPOSE_CMD="docker-compose"
elif command -v docker >/dev/null 2>&1 && docker compose version >/dev/null 2>&1; then
  DOCKER_COMPOSE_CMD="docker compose"
else
  echo "ERROR: docker-compose or docker compose is required." >&2
  exit 1
fi

export POSTGRES_USER="${POSTGRES_USER:-gdwb_user}"
export POSTGRES_PASSWORD="${POSTGRES_PASSWORD:-/FdCDrG6wWczmjJvgXl28w==}"
export POSTGRES_DB="${POSTGRES_DB:-gdwb_app}"
export REDIS_HOST="${REDIS_HOST:-127.0.0.1}"
export REDIS_PORT="${REDIS_PORT:-6379}"

cleanup() {
  echo "Cleaning up..."
  if [[ -n "${SERVER_PID:-}" ]]; then
    kill "$SERVER_PID" >/dev/null 2>&1 || true
  fi
  $DOCKER_COMPOSE_CMD down
}
trap cleanup EXIT

echo "Starting Docker Compose services..."
$DOCKER_COMPOSE_CMD up -d redis postgres migrate

wait_for_port() {
  local host="$1" port="$2" timeout=${3:-60}
  local start
  start=$(date +%s)
  while true; do
    if command -v nc >/dev/null 2>&1; then
      if nc -z "$host" "$port" >/dev/null 2>&1; then
        return 0
      fi
    else
      (echo > /dev/tcp/$host/$port) >/dev/null 2>&1 && return 0 || true
    fi
    if [ $(( $(date +%s) - start )) -ge "$timeout" ]; then
      return 1
    fi
    sleep 1
  done
}

echo "Waiting for Postgres..."
if ! wait_for_port 127.0.0.1 5432 60; then
  echo "Postgres did not become available in time." >&2
  exit 1
fi

echo "Waiting for Redis..."
if ! wait_for_port "$REDIS_HOST" "$REDIS_PORT" 60; then
  echo "Redis did not become available in time." >&2
  exit 1
fi

export LICENSE_DB_DSN="pgsql:host=127.0.0.1;port=5432;dbname=${POSTGRES_DB}"
export LICENSE_DB_USER="$POSTGRES_USER"
export LICENSE_DB_PASS="$POSTGRES_PASSWORD"

# Generate admin token
php license-server/generate_admin_token.php >/dev/null
ADMIN_TOKEN=$(cat license-server/keys/admin_token.txt)

# Start license server
php -S 127.0.0.1:8001 license-server/server.php > /tmp/license-server.log 2>&1 &
SERVER_PID=$!

echo "Waiting for license server..."
for i in $(seq 1 60); do
  if curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8001/ >/dev/null 2>&1; then
    break
  fi
  sleep 1
done

sleep 1

echo "Validating license..."
RESPONSE=$(curl -s -X POST "http://127.0.0.1:8001/api/v1/validate" -d "license_key=TEST-GDW-INTEG-000000000001" -d "site=http://localhost")
TOKEN=$(python3 - <<'PY' <<< "$RESPONSE"
import json,sys
print(json.load(sys.stdin)["token"])
PY
)

if [[ -z "$TOKEN" ]]; then
  echo "Failed to obtain token from validate endpoint." >&2
  echo "$RESPONSE" >&2
  exit 1
fi

echo "Introspecting token..."
RESPONSE=$(curl -s -X POST "http://127.0.0.1:8001/api/v1/introspect" -d "token=$TOKEN")
INTROSPECT_SUCCESS=$(python3 - <<'PY' <<< "$RESPONSE"
import json,sys
print(json.load(sys.stdin).get('success'))
PY
)

if [[ "$INTROSPECT_SUCCESS" != "True" && "$INTROSPECT_SUCCESS" != "true" ]]; then
  echo "Introspection failed: $RESPONSE" >&2
  exit 1
fi

echo "Revoking license..."
RESPONSE=$(curl -s -X POST "http://127.0.0.1:8001/api/v1/revoke" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -d "license_key=TEST-GDW-INTEG-000000000001")
REVOKE_SUCCESS=$(python3 - <<'PY' <<< "$RESPONSE"
import json,sys
print(json.load(sys.stdin).get('success'))
PY
)

if [[ "$REVOKE_SUCCESS" != "True" && "$REVOKE_SUCCESS" != "true" ]]; then
  echo "Revoke failed: $RESPONSE" >&2
  exit 1
fi

echo "Introspecting token after revoke..."
RESPONSE=$(curl -s -X POST "http://127.0.0.1:8001/api/v1/introspect" -d "token=$TOKEN")
REVOKED_MSG=$(python3 - <<'PY' <<< "$RESPONSE"
import json,sys
print(json.load(sys.stdin).get('message'))
PY
)

if [[ "$REVOKED_MSG" != "revoked_jti" ]]; then
  echo "Expected revoked_jti, got: $RESPONSE" >&2
  exit 1
fi

echo "Integration test passed."
