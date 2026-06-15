#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"

php license-server/generate_keys.php >/dev/null 2>&1 || true
php license-server/generate_admin_token.php >/dev/null 2>&1 || true
ADMIN_TOKEN=$(cat license-server/keys/admin_token.txt)

# Start server with a short grace period (2 seconds)
LICENSE_KEY_GRACE_PERIOD_SECONDS=2 php -S 127.0.0.1:8001 license-server/server.php > /tmp/license-server-prune.log 2>&1 &
SERVER_PID=$!

cleanup() { echo "Cleaning up..."; kill "$SERVER_PID" >/dev/null 2>&1 || true; }
trap cleanup EXIT

for i in $(seq 1 30); do
  if curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8001/ >/dev/null 2>&1; then
    break
  fi
  sleep 1
done

echo "Fetching JWKS status..."
STATUS=$(curl -sS http://127.0.0.1:8001/api/v1/jwks/status || true)
CURRENT_KID=$(php -r 'echo json_decode(stream_get_contents(STDIN), true)["current_kid"] ?? "";' <<< "$STATUS")
echo "Current kid: $CURRENT_KID"

echo "Rotating keys via JWKS endpoint..."
ROT=$(curl -sS -X POST "http://127.0.0.1:8001/api/v1/jwks/rotate" -H "Authorization: Bearer $ADMIN_TOKEN" -d '' )
NEW_KID=$(php -r 'echo json_decode(stream_get_contents(STDIN), true)["kid"] ?? "";' <<< "$ROT")
if [[ -z "$NEW_KID" ]]; then echo "Rotation failed: $ROT" >&2; cat /tmp/license-server-prune.log >&2; exit 1; fi
echo "Rotated to new kid: $NEW_KID"

echo "Fetching JWKS..."
JWKS=$(curl -sS http://127.0.0.1:8001/api/v1/jwks)
HAS_NEW=$(php -r ' $j=json_decode(stream_get_contents(STDIN), true); foreach($j["keys"] as $k){ if(($k["kid"]??"")=="'"$NEW_KID"'" ) { echo "1"; exit;} } echo "0";' <<< "$JWKS")
if [[ "$HAS_NEW" != "1" ]]; then echo "New kid not present in JWKS: $JWKS" >&2; exit 1; fi

echo "Waiting for grace period to elapse..."
sleep 4

echo "Triggering prune..."
PRUNE=$(curl -sS -X POST "http://127.0.0.1:8001/api/v1/jwks/prune" -H "Authorization: Bearer $ADMIN_TOKEN" -d '' )
PRUNED=$(php -r 'echo json_encode(json_decode(stream_get_contents(STDIN), true)["pruned"] ?? []);' <<< "$PRUNE")
echo "Pruned: $PRUNED"

echo "Verifying old kid removed from JWKS..."
JWKS2=$(curl -sS http://127.0.0.1:8001/api/v1/jwks)
HAS_OLD=$(php -r ' $j=json_decode(stream_get_contents(STDIN), true); foreach($j["keys"] as $k){ if(($k["kid"]??"")=="'"$CURRENT_KID"'" ) { echo "1"; exit;} } echo "0";' <<< "$JWKS2")
if [[ "$HAS_OLD" == "1" ]]; then echo "Old key still present after prune: $JWKS2" >&2; exit 1; fi

echo "Prune test passed. Removed: $PRUNED"
exit 0
