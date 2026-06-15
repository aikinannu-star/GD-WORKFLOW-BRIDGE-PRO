#!/usr/bin/env bash
set -euo pipefail
BASE=${BASE:-http://127.0.0.1:8000}

echo "Waiting for gateway health..."
for i in $(seq 1 30); do
  if curl -sSf "$BASE/health" >/dev/null 2>&1; then
    break
  fi
  sleep 1
done

echo "Checking license health..."
if ! curl -sSf "$BASE/api/v1/license/health" >/dev/null 2>&1; then
  echo "license health failed"
  exit 1
fi

echo "Checking openapi..."
if ! curl -sSf "$BASE/api/v1/license/openapi.yaml" | head -n1 | grep -q '^openapi:'; then
  echo "openapi not found"
  exit 1
fi

echo "Checking tenant protected returns 401 without token..."
code=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/api/v1/tenant/health" || true)
if [[ "$code" != "401" ]]; then
  echo "expected 401, got $code"
  exit 1
fi

echo "Registering test user..."
json=$(curl -sSf -X POST "$BASE/api/v1/auth/register" -H 'Content-Type: application/json' -d '{"tenant_id":"ci-tenant","email":"ci@example.com","password":"password123"}')
# try to extract token with jq if available, else with grep
if command -v jq >/dev/null 2>&1; then
  token=$(echo "$json" | jq -r .token)
else
  token=$(echo "$json" | sed -n 's/.*"token"\s*:\s*"\([^"]*\)".*/\1/p')
fi
if [[ -z "$token" || "$token" == "null" ]]; then
  echo "failed to get token"
  echo "$json"
  exit 1
fi

echo "Checking tenant health with token..."
code=$(curl -s -o /dev/null -w '%{http_code}' -H "Authorization: Bearer $token" "$BASE/api/v1/tenant/health")
if [[ "$code" != "200" ]]; then
  echo "tenant health with auth failed: $code"
  exit 1
fi

echo "Checking aggregate health contains license..."
if command -v jq >/dev/null 2>&1; then
  if ! curl -sSf "$BASE/health/services" | jq -e '.services.license' >/dev/null 2>&1; then
    echo "aggregate missing license"
    exit 1
  fi
else
  if ! curl -sSf "$BASE/health/services" | grep -q '"license"'; then
    echo "aggregate missing license"
    exit 1
  fi
fi

echo "All gateway smoke tests passed."
