#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
php "$SCRIPT_DIR/run_assistant_tests.php"
