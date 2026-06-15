#!/usr/bin/env bash
set -euo pipefail

GATEWAY_URL=${GATEWAY_URL:-http://127.0.0.1:8000}
PROVIDER=${PROVIDER:-stripe}
ADMIN_TOKEN=${BILLING_ADMIN_TOKEN:-}
TIMEOUT=${TIMEOUT:-30}

EVENT_ID="evt_ci_$(date +%s)"
ENDPOINT="${GATEWAY_URL}/api/v1/billing/webhooks/${PROVIDER}"
PAYLOAD="{\"id\":\"${EVENT_ID}\",\"type\":\"invoice.payment_succeeded\",\"data\":{\"object\":{\"id\":\"in_${EVENT_ID}\",\"amount_paid\":1000,\"currency\":\"usd\",\"metadata\":{\"license_key\":\"TEST-LICENSE-CI-${EVENT_ID}\",\"site\":\"http://ci.local\"}}}}"

echo "Posting webhook to ${ENDPOINT}..."
if [ -n "${STRIPE_WEBHOOK_SECRET:-}" ]; then
  TIMESTAMP=$(date +%s)
  SIGPAYLOAD="${TIMESTAMP}.${PAYLOAD}"
  SIGNATURE="t=${TIMESTAMP},v1=$(printf '%s' "${SIGPAYLOAD}" | openssl dgst -sha256 -hmac "${STRIPE_WEBHOOK_SECRET}" -binary | xxd -p -c 256)"
  RESP=$(curl -sS -X POST "${ENDPOINT}" -H "Content-Type: application/json" -H "Stripe-Signature: ${SIGNATURE}" -d "${PAYLOAD}")
else
  RESP=$(curl -sS -X POST "${ENDPOINT}" -H "Content-Type: application/json" -d "${PAYLOAD}")
fi

echo "Webhook posted. Server response: ${RESP}"

if [ -z "${ADMIN_TOKEN}" ]; then
  echo "BILLING_ADMIN_TOKEN not set; cannot poll admin events. Exiting with success for quick smoke." 
  exit 0
fi

EVENT_KEY="${PROVIDER}:${EVENT_ID}"
END_TIME=$(( $(date +%s) + TIMEOUT ))
while [ $(date +%s) -lt ${END_TIME} ]; do
  echo "Polling admin events for ${EVENT_KEY}..."
  OUT=$(curl -sS -H "X-Admin-Token: ${ADMIN_TOKEN}" "${GATEWAY_URL}/api/v1/admin/events") || true
  echo "$OUT" | grep -q "${EVENT_KEY}" && echo "Found event in admin store" && exit 0
  sleep 2
done

echo "Timed out waiting for event in admin store." >&2
exit 2
