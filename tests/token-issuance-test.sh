#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"

# Ensure keys and admin token exist
php license-server/generate_keys.php >/dev/null 2>&1 || true
php license-server/generate_admin_token.php >/dev/null 2>&1 || true

# Start license server
php -S 127.0.0.1:8001 license-server/server.php > /tmp/license-server-token-test.log 2>&1 &
SERVER_PID=$!

cleanup() {
  echo "Cleaning up..."
  if [[ -n "${SERVER_PID:-}" ]]; then
    kill "$SERVER_PID" >/dev/null 2>&1 || true
  fi
}
trap cleanup EXIT

# Wait for JWKS to be available
for i in $(seq 1 30); do
  if curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8001/api/v1/jwks >/dev/null 2>&1; then
    break
  fi
  sleep 1
done

echo "Testing client_credentials (POST body)..."
RESPONSE=$(curl -s -X POST http://127.0.0.1:8001/oauth/token -d "grant_type=client_credentials" -d "client_id=dev-client" -d "client_secret=dev-secret")
ACCESS=$(php -r ' $s = stream_get_contents(STDIN); $j = json_decode($s, true); echo isset($j["access_token"]) ? $j["access_token"] : ""; ' <<< "$RESPONSE")
if [[ -z "$ACCESS" ]]; then
  echo "client_credentials POST failed: $RESPONSE" >&2
  exit 1
fi

echo "Testing client_credentials (Basic auth)..."
RESPONSE=$(curl -s -X POST http://127.0.0.1:8001/api/v1/token -H "Authorization: Basic $(echo -n dev-client:dev-secret | base64)" -d "grant_type=client_credentials")
ACCESS2=$(php -r ' $s = stream_get_contents(STDIN); $j = json_decode($s, true); echo isset($j["access_token"]) ? $j["access_token"] : ""; ' <<< "$RESPONSE")
if [[ -z "$ACCESS2" ]]; then
  echo "client_credentials Basic failed: $RESPONSE" >&2
  exit 1
fi

echo "Testing license grant..."
RESPONSE=$(curl -s -X POST http://127.0.0.1:8001/api/v1/token -d "grant_type=license" -d "license_key=TEST-GDW-INTEG-000000000001" -d "site=http://localhost")
ACCESS3=$(php -r ' $s = stream_get_contents(STDIN); $j = json_decode($s, true); echo isset($j["access_token"]) ? $j["access_token"] : ""; ' <<< "$RESPONSE")
if [[ -z "$ACCESS3" ]]; then
  echo "license grant failed: $RESPONSE" >&2
  exit 1
fi

echo "Token issuance tests passed."
exit 0
