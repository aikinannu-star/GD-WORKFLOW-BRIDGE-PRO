#!/usr/bin/env bash
set -euo pipefail

CI_WAIT_TIMEOUT=${CI_WAIT_TIMEOUT:-60}

echo "ci-runner: waiting up to ${CI_WAIT_TIMEOUT}s for gateway..."
for i in $(seq 1 "${CI_WAIT_TIMEOUT}"); do
  if curl -sSf "http://gateway-service:8000/health" >/dev/null 2>&1; then
    echo "gateway-service is healthy"
    break
  fi
  echo "waiting for gateway... ${i}s"
  sleep 1
done

if ! curl -sSf "http://gateway-service:8000/health" >/dev/null 2>&1; then
  echo "ERROR: gateway did not become healthy in ${CI_WAIT_TIMEOUT}s" >&2
  exit 1
fi

# Flush redis if available
if command -v redis-cli >/dev/null 2>&1; then
  if redis-cli -h redis ping >/dev/null 2>&1; then
    echo "Flushing redis"
    redis-cli -h redis FLUSHALL || true
  fi
fi

chmod +x ./tests/quota_integration.sh || true

# Ensure minimal tools available (attempt apt-get install if missing)
if ! command -v curl >/dev/null 2>&1 || ! command -v jq >/dev/null 2>&1; then
  if command -v apt-get >/dev/null 2>&1; then
    apt-get update -y && apt-get install -y --no-install-recommends curl jq redis-tools || true
  fi
fi

echo "Running quota integration script..."
./tests/quota_integration.sh BASE=http://gateway-service:8000
