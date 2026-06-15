#!/usr/bin/env bash
# Multi-instance distributed revocation test.
# Simulates 3 WordPress instances with active licenses, revokes one, and verifies revocation is immediate across all instances.

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"

# Detect docker compose
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
  echo ""
  echo "Cleaning up..."
  if [[ -n "${SERVER_PID:-}" ]]; then
    kill "$SERVER_PID" >/dev/null 2>&1 || true
  fi
  $DOCKER_COMPOSE_CMD down
}
trap cleanup EXIT

echo "=== Distributed Revocation Test ==="
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

# Define 3 WordPress instances (simulated via license keys + sites)
INSTANCES=(
  "TEST-GDW-DIST-000000000001:http://site1.example.com"
  "TEST-GDW-DIST-000000000002:http://site2.example.com"
  "TEST-GDW-DIST-000000000003:http://site3.example.com"
)

declare -A TOKENS
declare -A VALID_STATUS

echo ""
echo "=== Step 1: Validate licenses for 3 instances ==="
for i in "${!INSTANCES[@]}"; do
  IFS=':' read -r key site <<< "${INSTANCES[$i]}"
  echo "  Instance $((i+1)): $key @ $site"
  
  RESPONSE=$(curl -s -X POST "http://127.0.0.1:8001/api/v1/validate" \
    -d "license_key=$key" -d "site=$site")
  
  TOKEN=$(python3 - <<'PY' <<< "$RESPONSE"
import json,sys
print(json.load(sys.stdin).get("token", ""))
PY
)
  
  if [[ -z "$TOKEN" ]]; then
    echo "    FAILED: Could not obtain token" >&2
    echo "    Response: $RESPONSE" >&2
    exit 1
  fi
  
  TOKENS[$key]="$TOKEN"
  echo "    ✓ Token obtained"
done

echo ""
echo "=== Step 2: Verify all tokens are valid (introspect) ==="
for i in "${!INSTANCES[@]}"; do
  IFS=':' read -r key site <<< "${INSTANCES[$i]}"
  TOKEN="${TOKENS[$key]}"
  
  RESPONSE=$(curl -s -X POST "http://127.0.0.1:8001/api/v1/introspect" \
    -d "token=$TOKEN")
  
  SUCCESS=$(python3 - <<'PY' <<< "$RESPONSE"
import json,sys
print(json.load(sys.stdin).get("success", False))
PY
)
  
  if [[ "$SUCCESS" != "True" && "$SUCCESS" != "true" ]]; then
    echo "  Instance $((i+1)) ($key): INVALID ✗" >&2
    echo "    Response: $RESPONSE" >&2
    exit 1
  fi
  
  VALID_STATUS[$key]="valid"
  echo "  Instance $((i+1)) ($key): VALID ✓"
done

echo ""
echo "=== Step 3: Revoke the second instance's license ==="
REVOKE_KEY="${INSTANCES[1]%:*}"
echo "  Revoking: $REVOKE_KEY"

RESPONSE=$(curl -s -X POST "http://127.0.0.1:8001/api/v1/revoke" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -d "license_key=$REVOKE_KEY")

REV_SUCCESS=$(python3 - <<'PY' <<< "$RESPONSE"
import json,sys
print(json.load(sys.stdin).get("success", False))
PY
)

if [[ "$REV_SUCCESS" != "True" && "$REV_SUCCESS" != "true" ]]; then
  echo "  FAILED: Revoke endpoint returned error" >&2
  echo "  Response: $RESPONSE" >&2
  exit 1
fi

echo "  ✓ License revoked"
sleep 1

echo ""
echo "=== Step 4: Re-introspect all tokens (distributed validation) ==="
FAILED_COUNT=0

for i in "${!INSTANCES[@]}"; do
  IFS=':' read -r key site <<< "${INSTANCES[$i]}"
  TOKEN="${TOKENS[$key]}"
  
  RESPONSE=$(curl -s -X POST "http://127.0.0.1:8001/api/v1/introspect" \
    -d "token=$TOKEN")
  
  SUCCESS=$(python3 - <<'PY' <<< "$RESPONSE"
import json,sys
print(json.load(sys.stdin).get("success", False))
PY
)
  
  MESSAGE=$(python3 - <<'PY' <<< "$RESPONSE"
import json,sys
print(json.load(sys.stdin).get("message", ""))
PY
)
  
  if [[ "$i" == "1" ]]; then
    # This one should be revoked
    if [[ "$MESSAGE" == "revoked_jti" ]]; then
      echo "  Instance $((i+1)) ($key): REVOKED ✓"
    else
      echo "  Instance $((i+1)) ($key): EXPECTED REVOKED, got: $MESSAGE ✗" >&2
      FAILED_COUNT=$((FAILED_COUNT+1))
    fi
  else
    # Others should still be valid
    if [[ "$SUCCESS" == "True" || "$SUCCESS" == "true" ]]; then
      echo "  Instance $((i+1)) ($key): STILL VALID ✓"
    else
      echo "  Instance $((i+1)) ($key): SHOULD BE VALID, got error ✗" >&2
      echo "    Response: $RESPONSE" >&2
      FAILED_COUNT=$((FAILED_COUNT+1))
    fi
  fi
done

echo ""
if [[ $FAILED_COUNT -gt 0 ]]; then
  echo "❌ Distributed revocation test FAILED ($FAILED_COUNT issues)"
  exit 1
else
  echo "✅ Distributed revocation test PASSED"
  echo ""
  echo "Summary:"
  echo "  • 3 instances validated licenses independently"
  echo "  • Centralized revocation of instance 2"
  echo "  • Instance 2 immediately saw revocation"
  echo "  • Instances 1 & 3 remained valid (distributed enforcement)"
fi
