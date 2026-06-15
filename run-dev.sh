#!/usr/bin/env bash
set -euo pipefail

# Usage: ./run-dev.sh [host] [port]
HOST="${1:-127.0.0.1}"
PORT="${2:-8001}"

POSTGRES_USER="${POSTGRES_USER:-gdwb_user}"
POSTGRES_PASSWORD="${POSTGRES_PASSWORD:-/FdCDrG6wWczmjJvgXl28w==}"
POSTGRES_DB="${POSTGRES_DB:-gdwb_app}"
REDIS_HOST="${REDIS_HOST:-127.0.0.1}"
REDIS_PORT="${REDIS_PORT:-6379}"

COMPOSE_CMD=""
if command -v docker >/dev/null 2>&1; then
  if docker compose version >/dev/null 2>&1 2>&1; then
    COMPOSE_CMD="docker compose"
  elif command -v docker-compose >/dev/null 2>&1; then
    COMPOSE_CMD="docker-compose"
  fi
fi

if [ -z "$COMPOSE_CMD" ]; then
  echo "ERROR: Docker Compose not found (install Docker + Compose)." >&2
  exit 1
fi

echo "Starting docker services (redis + postgres + migrate)..."
${COMPOSE_CMD} up -d redis postgres migrate || ${COMPOSE_CMD} up -d redis postgres

wait_for_tcp() {
  local host="$1"; local port="$2"; local timeout_secs="${3:-60}"; local start now
  start=$(date +%s)
  echo -n "Waiting for $host:$port"
  while true; do
    # Prefer protocol-aware checks when available
    if command -v redis-cli >/dev/null 2>&1 && [ "$port" = "6379" ]; then
      if redis-cli -h "$host" -p "$port" PING >/dev/null 2>&1; then
        echo " ok"
        return 0
      fi
    elif command -v nc >/dev/null 2>&1; then
      if nc -z "$host" "$port" >/dev/null 2>&1; then
        echo " ok"
        return 0
      fi
    else
      # Fallback to bash /dev/tcp check (requires bash)
      if bash -c "</dev/tcp/$host/$port" >/dev/null 2>&1; then
        echo " ok"
        return 0
      fi
    fi

    now=$(date +%s)
    if [ $((now - start)) -ge "$timeout_secs" ]; then
      echo " timeout"
      return 1
    fi
    echo -n "."
    sleep 1
  done
}

echo "Checking Redis readiness..."
if ! wait_for_tcp "$REDIS_HOST" "$REDIS_PORT" 60; then
  echo "WARNING: Redis did not become available within timeout." >&2
fi

echo "Checking Postgres readiness..."
if command -v pg_isready >/dev/null 2>&1; then
  until pg_isready -h 127.0.0.1 -p 5432 -U "$POSTGRES_USER" >/dev/null 2>&1; do sleep 1; done
elif command -v psql >/dev/null 2>&1; then
  export PGPASSWORD="$POSTGRES_PASSWORD"
  until psql -h 127.0.0.1 -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c '\q' >/dev/null 2>&1; do sleep 1; done
else
  if ! wait_for_tcp 127.0.0.1 5432 60; then
    echo "WARNING: Postgres did not become available within timeout." >&2
  fi
fi

export LICENSE_DB_DSN="pgsql:host=127.0.0.1;port=5432;dbname=${POSTGRES_DB}"
export LICENSE_DB_USER="$POSTGRES_USER"
export LICENSE_DB_PASSWORD="$POSTGRES_PASSWORD"
export REDIS_HOST="$REDIS_HOST"
export REDIS_PORT="$REDIS_PORT"

echo "Starting PHP dev server on http://$HOST:$PORT"
exec php -S "$HOST:$PORT" -t license-server
