#!/usr/bin/env bash
set -euo pipefail
COMPOSE_FILE=build/wp-test/docker-compose.yml

echo "Bringing up WordPress test stack..."
docker compose -f "$COMPOSE_FILE" up -d

echo "Waiting for WordPress to be ready at http://localhost:8080 ..."
for i in {1..60}; do
  if curl -sSf http://localhost:8080 >/dev/null 2>&1; then
    echo "WordPress is up"
    break
  fi
  sleep 2
done

# Install and activate the plugin using WP-CLI inside the wordpress container
echo "Installing plugin from release/gd-workflow-bridge-pro.zip"
docker compose -f "$COMPOSE_FILE" exec -T wordpress bash -lc "wp plugin install /tmp/release/gd-workflow-bridge-pro.zip --allow-root --activate --path=/var/www/html --url=http://localhost:8080"

echo "Plugin installation attempted. Visit http://localhost:8080 to verify." 
