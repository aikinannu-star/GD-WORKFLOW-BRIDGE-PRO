#!/bin/sh
set -eu

BASE_URL="${1:-http://localhost:8017}"

fail() {
  echo "[smoke] $*" >&2
  exit 1
}

# Require jq
command -v jq >/dev/null 2>&1 || fail "jq is required for this script"

if ! curl -fsS "$BASE_URL/health/ready" -o /tmp/assistant-ready.json; then
  fail "health endpoint not reachable"
fi

if [ "$(jq -r '.ready' /tmp/assistant-ready.json 2>/dev/null)" != "true" ]; then
  fail "health endpoint did not report ready"
fi
echo "[smoke] health ready confirmed"

SESSION_RESPONSE=$(curl -fsS -X POST "$BASE_URL/api/v1/assistant/sessions" -H 'Content-Type: application/json' -d '{"user_id":"smoke-test"}') || fail "session creation failed"
SESSION_ID=$(printf '%s' "$SESSION_RESPONSE" | jq -r '.session.id' 2>/dev/null) || fail "could not parse session id"
[ -n "$SESSION_ID" ] || fail "empty session id"

MESSAGE_RESPONSE=$(curl -fsS -X POST "$BASE_URL/api/v1/assistant/sessions/$SESSION_ID/message" -H 'Content-Type: application/json' -d '{"text":"smoke test"}') || fail "message request failed"

REPLY_TEXT=$(printf '%s' "$MESSAGE_RESPONSE" | jq -r '.reply.text' 2>/dev/null) || fail "could not parse reply"
if [ -z "$REPLY_TEXT" ] || [ "$REPLY_TEXT" = "null" ]; then
  fail "assistant reply was empty"
fi

echo "[smoke] assistant replied successfully"
echo "[smoke] completed successfully"
