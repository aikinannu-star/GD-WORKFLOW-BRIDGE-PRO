#!/bin/sh
set -eu

BASE_URL="${1:-http://localhost:8017}"

fail() {
  echo "[smoke] $*" >&2
  exit 1
}

curl -fsS "$BASE_URL/health/ready" >/tmp/assistant-ready.json 2>/dev/null || fail "health endpoint not reachable"
python - <<'PY' /tmp/assistant-ready.json
import json, sys
from pathlib import Path
path = Path('/tmp/assistant-ready.json')
data = json.loads(path.read_text())
if data.get('ready') is not True:
    raise SystemExit('health endpoint did not report ready')
print('[smoke] health ready confirmed')
PY

SESSION_RESPONSE=$(curl -fsS -X POST "$BASE_URL/api/v1/assistant/sessions" -H 'Content-Type: application/json' -d '{"user_id":"smoke-test"}') || fail "session creation failed"
SESSION_ID=$(printf '%s' "$SESSION_RESPONSE" | python -c 'import json,sys; data=json.load(sys.stdin); print(data["session"]["id"])') || fail "could not parse session id"

MESSAGE_RESPONSE=$(curl -fsS -X POST "$BASE_URL/api/v1/assistant/sessions/$SESSION_ID/message" -H 'Content-Type: application/json' -d '{"text":"smoke test"}') || fail "message request failed"
printf '%s
' "$MESSAGE_RESPONSE" | python - <<'PY'
import json, sys
payload = json.load(sys.stdin)
reply = payload.get('reply', {})
text = reply.get('text', '')
if not isinstance(text, str) or not text.strip():
    raise SystemExit('assistant reply was empty')
print('[smoke] assistant replied successfully')
PY

echo "[smoke] completed successfully"
