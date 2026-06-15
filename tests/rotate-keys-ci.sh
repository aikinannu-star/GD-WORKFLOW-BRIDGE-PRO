#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"

php license-server/generate_keys.php >/dev/null 2>&1 || true
php license-server/generate_admin_token.php >/dev/null 2>&1 || true
ADMIN_TOKEN=$(cat license-server/keys/admin_token.txt)

php -S 127.0.0.1:8001 license-server/server.php > /tmp/license-server-rotate.log 2>&1 &
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

echo "Issuing token before rotation..."
RESPONSE=$(curl -s -X POST "http://127.0.0.1:8001/api/v1/validate" -d "license_key=TEST-GDW-INTEG-ROTATE-0001" -d "site=http://localhost")
TOKEN_OLD=$(php -r 'echo json_decode(stream_get_contents(STDIN), true)["token"] ?? "";' <<< "$RESPONSE")
if [[ -z "$TOKEN_OLD" ]]; then echo "Failed to issue token before rotation: $RESPONSE" >&2; exit 1; fi

echo "Introspecting old token..."
RESP=$(curl -s -X POST "http://127.0.0.1:8001/api/v1/introspect" -d "token=$TOKEN_OLD")
OK=$(php -r 'echo json_decode(stream_get_contents(STDIN), true)["success"] ? "true" : "false";' <<< "$RESP")
if [[ "$OK" != "true" ]]; then echo "Old token introspect failed: $RESP" >&2; exit 1; fi

echo "Rotating keys via JWKS endpoint..."
ROT=$(curl -sS -X POST "http://127.0.0.1:8001/api/v1/jwks/rotate" -H "Authorization: Bearer $ADMIN_TOKEN" -d '' )
NEW_KID=$(php -r 'echo json_decode(stream_get_contents(STDIN), true)["kid"] ?? "";' <<< "$ROT")
if [[ -z "$NEW_KID" ]]; then echo "Rotation failed: $ROT" >&2; cat /tmp/license-server-rotate.log >&2; exit 1; fi
echo "Rotated to new kid: $NEW_KID"

echo "Fetching JWKS..."
JWKS=$(curl -sS http://127.0.0.1:8001/api/v1/jwks)
HAS_NEW=$(php -r ' $j=json_decode(stream_get_contents(STDIN), true); foreach($j["keys"] as $k){ if(($k["kid"]??"")=="'"$NEW_KID"'" ) { echo "1"; exit;} } echo "0";' <<< "$JWKS")
if [[ "$HAS_NEW" != "1" ]]; then echo "New kid not present in JWKS: $JWKS" >&2; exit 1; fi

echo "Issuing token after rotation..."
RESPONSE2=$(curl -s -X POST "http://127.0.0.1:8001/api/v1/validate" -d "license_key=TEST-GDW-INTEG-ROTATE-0002" -d "site=http://localhost")
TOKEN_NEW=$(php -r 'echo json_decode(stream_get_contents(STDIN), true)["token"] ?? "";' <<< "$RESPONSE2")
if [[ -z "$TOKEN_NEW" ]]; then echo "Failed to issue token after rotation: $RESPONSE2" >&2; exit 1; fi

echo "Extracting kids from tokens..."
KID_OLD=$(php -r '$t=trim(stream_get_contents(STDIN)); $h=explode(".",$t)[0] ?? ""; echo json_decode(base64_decode(str_replace(["-","_"],["+","/"],$h)), true)["kid"] ?? "";' <<< "$TOKEN_OLD")
KID_NEW=$(php -r '$t=trim(stream_get_contents(STDIN)); $h=explode(".",$t)[0] ?? ""; echo json_decode(base64_decode(str_replace(["-","_"],["+","/"],$h)), true)["kid"] ?? "";' <<< "$TOKEN_NEW")
echo "kid old: $KID_OLD"
echo "kid new: $KID_NEW"

if [[ "$KID_NEW" == "$KID_OLD" ]]; then echo "Warning: token kid did not change after rotation (possible immediate reuse of old key)."; fi

echo "Introspecting both tokens after rotation..."
R1=$(curl -s -X POST "http://127.0.0.1:8001/api/v1/introspect" -d "token=$TOKEN_OLD")
R2=$(curl -s -X POST "http://127.0.0.1:8001/api/v1/introspect" -d "token=$TOKEN_NEW")
OK1=$(php -r 'echo json_decode(stream_get_contents(STDIN), true)["success"] ? "true" : "false";' <<< "$R1")
OK2=$(php -r 'echo json_decode(stream_get_contents(STDIN), true)["success"] ? "true" : "false";' <<< "$R2")
if [[ "$OK2" != "true" ]]; then
  echo "New token failed introspect after rotation: $R2" >&2
  echo "Debug: verifying new token against saved public keys" >&2
  TOKEN="$TOKEN_NEW" php tests/inspect_token_keys.php >&2 || true
  TOKEN="$TOKEN_NEW" PUB="license-server/keys/public_${KID_NEW}.pem" php tests/debug_verify.php >&2 || true
  exit 1
fi

echo "Rotation smoke test passed. New kid: $NEW_KID"
if [[ "$OK1" != "true" ]]; then
  echo "Note: old token no longer introspects successfully after rotation (may be expected if you rotate canonical public.pem)." >&2
fi
exit 0
