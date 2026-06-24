#!/usr/bin/env bash
set -euo pipefail
ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
echo "Marketplace Security CI: starting dev servers"
php -S 127.0.0.1:8006 -t services/marketplace services/marketplace/server.php > "$ROOT_DIR/tmp/marketplace.log" 2>&1 &
MPID=$!
php -S 127.0.0.1:8009 -t services/tenant services/tenant/server.php > "$ROOT_DIR/tmp/tenant.log" 2>&1 &
TPID=$!
sleep 1
echo "Running security tests"
node sdk/javascript/gd-module-sdk/test/security.js
node sdk/javascript/gd-module-sdk/test/key-rotation.js
node sdk/javascript/gd-module-sdk/test/artifact-upload.js
node sdk/javascript/gd-module-sdk/test/webhook.js

echo "Stopping dev servers"
kill ${MPID} || true
kill ${TPID} || true
echo "Security CI script completed"
