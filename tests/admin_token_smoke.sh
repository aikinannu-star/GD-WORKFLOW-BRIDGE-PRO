#!/usr/bin/env bash
set -euo pipefail
ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"

php license-server/generate_admin_token.php >/dev/null 2>&1 || true
ADMIN_TOKEN=$(cat license-server/keys/admin_token.txt)
php -S 127.0.0.1:8001 license-server/server.php > /tmp/license-server.log 2>&1 &
SERVER_PID=$!
trap 'kill $SERVER_PID >/dev/null 2>&1 || true' EXIT
sleep 1

echo "Server PID: $SERVER_PID"
ISSUE=$(curl -sS -X POST "http://127.0.0.1:8001/api/v1/admin/token" -H "Authorization: Bearer $ADMIN_TOKEN" -H "Content-Type: application/json" -d '{"scopes":"rotate prune status","exp_seconds":300}')
echo "Issue response: $ISSUE"
TOKEN=$(echo "$ISSUE" | php -r 'echo json_decode(stream_get_contents(STDIN), true)["token"] ?? "";' )
echo "Got token len: ${#TOKEN}"
# Revoke using token string
REVOKE_RESP=$(curl -sS -X POST "http://127.0.0.1:8001/api/v1/admin/token/revoke" -H "Authorization: Bearer $ADMIN_TOKEN" -H "Content-Type: application/json" -d "{\"token\":\"$TOKEN\"}")
echo "Revoke response: $REVOKE_RESP"

exit 0
