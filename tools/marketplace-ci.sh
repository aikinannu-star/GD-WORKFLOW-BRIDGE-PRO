#!/usr/bin/env bash
set -euo pipefail
ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
TEST_GROUP=${1:-}
echo "Marketplace CI: starting dev servers"
php -S 127.0.0.1:8006 -t services/marketplace services/marketplace/server.php > /tmp/marketplace.log 2>&1 &
MPID=$!
php -S 127.0.0.1:8009 -t services/tenant services/tenant/server.php > /tmp/tenant.log 2>&1 &
TPID=$!
sleep 1

run_tests() {
	echo "Running tests: $@"
	for t in "$@"; do
		echo "-> $t"
		node "$t"
	done
}

case "$TEST_GROUP" in
	security)
		run_tests \
			sdk/javascript/gd-module-sdk/test/security.js \
			sdk/javascript/gd-module-sdk/test/key-rotation.js
		;;
	dependency)
		run_tests \
			sdk/javascript/gd-module-sdk/test/dependency-resolution.js
		;;
	publishing)
		run_tests \
			sdk/javascript/gd-module-sdk/test/ratings-publish.js
		;;
	ratings)
		run_tests \
			sdk/javascript/gd-module-sdk/test/ratings-publish.js
		;;
	artifact)
		run_tests \
			sdk/javascript/gd-module-sdk/test/artifact-upload.js \
			sdk/javascript/gd-module-sdk/test/artifact-download.js
		;;
	"" )
		echo "Running default JS tests"
		run_tests \
			sdk/javascript/gd-module-sdk/test/security.js \
			sdk/javascript/gd-module-sdk/test/key-rotation.js \
			sdk/javascript/gd-module-sdk/test/artifact-upload.js \
			sdk/javascript/gd-module-sdk/test/webhook.js 
		;;
	*)
		echo "Unknown test group: ${TEST_GROUP}" >&2
		exit 2
		;;
esac

echo "Stopping dev servers"
kill ${MPID} || true
kill ${TPID} || true
echo "CI script completed"
