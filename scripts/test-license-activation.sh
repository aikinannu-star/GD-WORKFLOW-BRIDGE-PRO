#!/usr/bin/env bash
set -euo pipefail

# Test license activation by calling the license-server validate endpoint
# Usage: ./scripts/test-license-activation.sh https://license.example.com TEST-KEY

BASE=${1:-http://127.0.0.1:8001}
KEY=${2:-TEST-12345678901234567890}

echo "Calling ${BASE}/api/v1/validate with license_key=${KEY}"
curl -fsS -X POST -H "Content-Type: application/json" -d "{\"license_key\":\"${KEY}\"}" "${BASE}/api/v1/validate" | jq '.'
