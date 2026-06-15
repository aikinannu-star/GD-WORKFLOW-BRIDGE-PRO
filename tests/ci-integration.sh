#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"

POSTGRES_HOST="${POSTGRES_HOST:-127.0.0.1}"
POSTGRES_PORT="${POSTGRES_PORT:-5432}"
POSTGRES_USER="${POSTGRES_USER:-gdwb_user}"
POSTGRES_PASSWORD="${POSTGRES_PASSWORD:-/FdCDrG6wWczmjJvgXl28w==}"
POSTGRES_DB="${POSTGRES_DB:-gdwb_app}"
REDIS_HOST="${REDIS_HOST:-127.0.0.1}"
REDIS_PORT="${REDIS_PORT:-6379}"

echo "Ensure keys and admin token exist..."
php license-server/generate_keys.php >/dev/null 2>&1 || true
php license-server/generate_admin_token.php >/dev/null 2>&1 || true
ADMIN_TOKEN=$(cat license-server/keys/admin_token.txt)

# Wait for Postgres readiness
echo "Waiting for Postgres at $POSTGRES_HOST:$POSTGRES_PORT..."
for i in $(seq 1 60); do
  if pg_isready -h "$POSTGRES_HOST" -p "$POSTGRES_PORT" -U "$POSTGRES_USER" >/dev/null 2>&1; then
    echo "Postgres ready"
    break
  fi
  sleep 1
done

# Run migrations
echo "Running migrations..."
export PGPASSWORD="$POSTGRES_PASSWORD"
psql -h "$POSTGRES_HOST" -p "$POSTGRES_PORT" -U "$POSTGRES_USER" -d "$POSTGRES_DB" -f license-server/migrations/postgres.sql

# Start license server with DB + Redis envs
export LICENSE_DB_DSN="pgsql:host=${POSTGRES_HOST};port=${POSTGRES_PORT};dbname=${POSTGRES_DB}"
export LICENSE_DB_USER="$POSTGRES_USER"
export LICENSE_DB_PASS="$POSTGRES_PASSWORD"
export REDIS_HOST="$REDIS_HOST"
export REDIS_PORT="$REDIS_PORT"

php -S 127.0.0.1:8001 license-server/server.php > /tmp/license-server-integration.log 2>&1 &
SERVER_PID=$!

cleanup() { echo "Cleaning up..."; kill "$SERVER_PID" >/dev/null 2>&1 || true; }
trap cleanup EXIT

# Wait for server to be ready
for i in $(seq 1 30); do
  if curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8001/ >/dev/null 2>&1; then
    break
  fi
  sleep 1
done

# Validate license
echo "Validating license..."
RESPONSE=$(curl -s -X POST "http://127.0.0.1:8001/api/v1/validate" -d "license_key=TEST-GDW-INTEG-000000000001" -d "site=http://localhost")
ACCESS=$(php -r ' $s=stream_get_contents(STDIN); $j=json_decode($s,true); echo isset($j["token"]) ? $j["token"] : ""; ' <<< "$RESPONSE")
if [[ -z "$ACCESS" ]]; then echo "validate failed: $RESPONSE" >&2; exit 1; fi

# Introspect
echo "Introspecting..."
RESPONSE=$(curl -s -X POST "http://127.0.0.1:8001/api/v1/introspect" -d "token=$ACCESS")
OK=$(php -r ' $s=stream_get_contents(STDIN); $j=json_decode($s,true); echo isset($j["success"]) ? ($j["success"] ? "true" : "false") : "false"; ' <<< "$RESPONSE")
if [[ "$OK" != "true" ]]; then echo "introspect failed: $RESPONSE" >&2; exit 1; fi

# Revoke
echo "Revoking..."
RESPONSE=$(curl -s -X POST "http://127.0.0.1:8001/api/v1/revoke" -H "Authorization: Bearer $ADMIN_TOKEN" -d "license_key=TEST-GDW-INTEG-000000000001")
REVOKE_SUCCESS=$(php -r ' $s=stream_get_contents(STDIN); $j=json_decode($s,true); echo isset($j["success"]) ? ($j["success"] ? "true" : "false") : "false"; ' <<< "$RESPONSE")
if [[ "$REVOKE_SUCCESS" != "true" ]]; then echo "revoke failed: $RESPONSE" >&2; exit 1; fi

# Introspect after revoke
echo "Introspecting after revoke..."
RESPONSE=$(curl -s -X POST "http://127.0.0.1:8001/api/v1/introspect" -d "token=$ACCESS")
MSG=$(php -r ' $s=stream_get_contents(STDIN); $j=json_decode($s,true); echo isset($j["message"]) ? $j["message"] : ""; ' <<< "$RESPONSE")
if [[ "$MSG" != "revoked_jti" ]]; then echo "expected revoked_jti, got: $RESPONSE" >&2; exit 1; fi

echo "Integration tests passed."
exit 0
