#!/usr/bin/env bash
# Simple smoke tests for the license server
# Usage: ./license_server_smoke.sh [license_server_base]

BASE=${1:-http://127.0.0.1:8001}

echo "Validate test license (TEST-...):"
curl -sS -X POST "$BASE/api/v1/validate" -d "license_key=TEST-GDWB-SMOKE-0001" | jq || true

echo
echo "Token grant (client credentials) - attempt to obtain admin token (requires ADMIN_TOKEN env or keys/admin_token.txt):"
curl -sS -X POST "$BASE/api/v1/token" -d "grant_type=client_credentials" | jq || true

echo
echo "Introspect: (replace with real token from previous step)"
echo "curl -sS -X POST $BASE/api/v1/introspect -d 'token=<TOKEN>'"
