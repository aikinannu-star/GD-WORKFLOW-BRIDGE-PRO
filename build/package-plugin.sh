#!/usr/bin/env bash
set -euo pipefail

PLUGIN_SLUG="gd-workflow-bridge-pro"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
BUILD_DIR="$REPO_ROOT/build/$PLUGIN_SLUG"
RELEASE_DIR="$REPO_ROOT/release"
ZIP_NAME="$RELEASE_DIR/${PLUGIN_SLUG}.zip"

echo "Repo root: $REPO_ROOT"
echo "Building plugin: $PLUGIN_SLUG"

# Composer vendor install if available
if command -v composer >/dev/null 2>&1; then
  echo "Running composer install --no-dev ..."
  (cd "$REPO_ROOT" && composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction)
else
  echo "composer not found: skipping vendor install. Ensure vendor/ exists if you need dependencies vendored."
fi

echo "Preparing build directories..."
rm -rf "$BUILD_DIR" "$RELEASE_DIR"
mkdir -p "$BUILD_DIR" "$RELEASE_DIR"

copy_if_exists() {
  local src="$REPO_ROOT/$1"
  if [ -e "$src" ]; then
    cp -a "$src" "$BUILD_DIR/"
  fi
}

# Files and folders to include in packaged plugin
copy_if_exists "gd-workflow-bridge-pro.php"
copy_if_exists "readme.txt"
copy_if_exists "includes"
copy_if_exists "assets"
copy_if_exists "languages"
copy_if_exists "templates"
copy_if_exists "vendor"
copy_if_exists "composer.json"
copy_if_exists "composer.lock"
copy_if_exists "phinx.php"
copy_if_exists "phpcs.xml"

# Remove anything that should never be bundled
rm -rf "$BUILD_DIR/keys" \
       "$BUILD_DIR/.env" \
       "$BUILD_DIR/.github" \
       "$BUILD_DIR/tests" \
       "$BUILD_DIR/license-server" \
       "$BUILD_DIR/services" \
       "$BUILD_DIR/infra" || true

echo "Creating ZIP in $RELEASE_DIR ..."
if command -v zip >/dev/null 2>&1; then
  (cd "$REPO_ROOT/build" && zip -r -q "../release/${PLUGIN_SLUG}.zip" "${PLUGIN_SLUG}")
else
  echo "zip not found, falling back to tar.gz (ZIP preferred for WordPress installs)"
  (cd "$REPO_ROOT/build" && tar -czf "../release/${PLUGIN_SLUG}.tar.gz" "${PLUGIN_SLUG}")
fi

echo "Package created:"
ls -lh "$REPO_ROOT/release" || true

echo "Done."
