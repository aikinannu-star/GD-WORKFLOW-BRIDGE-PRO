#!/usr/bin/env bash
set -euo pipefail
BASE=${BASE:-http://127.0.0.1:8000}
TENANT=${TENANT:-ci-tenant}
EMAIL="quota-ci-$(date +%s)@example.com"
PWD="password123"

echo "Resetting file-based rate limits and flushing Redis if available..."
# remove file-based rate limits so tests start clean
rm -f services/data/gateway_rate_limits.json || true
# attempt to flush Redis if redis-cli is available
REDIS_HOST=${GATEWAY_REDIS_HOST:-redis}
REDIS_PORT=${GATEWAY_REDIS_PORT:-6379}
if command -v redis-cli >/dev/null 2>&1; then
  if redis-cli -h "$REDIS_HOST" -p "$REDIS_PORT" ping 2>/dev/null | grep -q PONG; then
    redis-cli -h "$REDIS_HOST" -p "$REDIS_PORT" FLUSHALL || true
  fi
fi

echo "Registering test user $EMAIL..."
REG=$(curl -sS -X POST "$BASE/api/v1/auth/register" -H 'Content-Type: application/json' -d "{\"tenant_id\":\"$TENANT\",\"email\":\"$EMAIL\",\"password\":\"$PWD\"}") || true
if [[ -z "$REG" ]]; then
  echo "register returned empty"; exit 1
fi
TOKEN=$(echo "$REG" | php -r 'echo json_decode(stream_get_contents(STDIN), true)["token"] ?? "";')
if [[ -z "$TOKEN" || "$TOKEN" == "null" ]]; then
  echo "register failed: $REG"
  exit 1
fi

# read tenant limit from services/data/tenant_quotas.json
if [[ -f services/data/tenant_quotas.json ]]; then
  # Prefer jq if present, else use PHP to parse JSON
  if command -v jq >/dev/null 2>&1; then
    LIMIT=$(jq -r --arg t "$TENANT" 'if has($t) then .[$t].limit elif has("default") and .default.limit then .default.limit else 120 end' services/data/tenant_quotas.json)
  else
    LIMIT=$(php -r 'echo (json_decode(file_get_contents("services/data/tenant_quotas.json"), true)[$argv[1]]["limit"] ?? json_decode(file_get_contents("services/data/tenant_quotas.json"), true)["default"]["limit"] ?? 120);' "$TENANT")
  fi
else
  LIMIT=120
fi

echo "Tenant limit: $LIMIT"

# perform requests until quota is enforced
for i in $(seq 1 $((LIMIT + 5))); do
  status=$(curl -s -o /dev/null -w '%{http_code}' -H "Authorization: Bearer $TOKEN" "$BASE/api/v1/tenant/health" || true)
  echo "iter $i -> $status"
  if [[ "$status" == "429" ]]; then
    echo "quota enforced at iteration $i"
    exit 0
  fi
  sleep 0.05
done

echo "Quota not enforced; last status: $status"
exit 1
