#!/usr/bin/env bash
set -euo pipefail

# Verify license-server endpoints (health and jwks)
# Usage: ./scripts/verify-license-endpoints.sh https://license.example.com

BASE=${1:-http://127.0.0.1:8001}

echo "Checking ${BASE}/health"
curl -fsS --retry 3 --retry-delay 2 "${BASE}/health" | jq '.' || { echo "health check failed" >&2; exit 1; }

echo "Checking ${BASE}/api/v1/jwks"
curl -fsS --retry 3 --retry-delay 2 "${BASE}/api/v1/jwks" | jq '.' || { echo "jwks check failed" >&2; exit 1; }

echo "License server endpoints OK"
