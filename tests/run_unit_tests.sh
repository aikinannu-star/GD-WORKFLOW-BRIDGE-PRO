#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"

echo "Running unit tests (CLI)..."
php tests/unit/test_prune_cli.php
php tests/unit/test_introspect_cli.php

echo "All CLI unit tests passed."
