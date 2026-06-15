#!/usr/bin/env bash
set -euo pipefail

GATEWAY_URL=${GATEWAY_URL:-http://127.0.0.1:8000}
PROVIDER=${PROVIDER:-stripe}
ENDPOINT="${GATEWAY_URL}/api/v1/billing/webhooks/${PROVIDER}"

PAYLOAD='{"id":"evt_ci_test_'"$(date +%s)"'","type":"charge.succeeded","data":{"object":{"id":"ch_ci_test","amount":1000,"currency":"USD","metadata":{"site":"ci.example.test"}}}}'

if [ -n "${STRIPE_WEBHOOK_SECRET:-}" ]; then
  TIMESTAMP=$(date +%s)
  SIGPAYLOAD="${TIMESTAMP}.${PAYLOAD}"
  SIGNATURE="t=${TIMESTAMP},v1=$(printf '%s' "${SIGPAYLOAD}" | openssl dgst -sha256 -hmac "${STRIPE_WEBHOOK_SECRET}" -binary | xxd -p -c 256)"
  curl -sS -X POST "${ENDPOINT}" -H "Content-Type: application/json" -H "Stripe-Signature: ${SIGNATURE}" -d "${PAYLOAD}"
else
  curl -sS -X POST "${ENDPOINT}" -H "Content-Type: application/json" -d "${PAYLOAD}"
fi

echo
