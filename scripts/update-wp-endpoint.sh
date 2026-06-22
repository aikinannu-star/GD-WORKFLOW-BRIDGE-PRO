#!/usr/bin/env bash
set -euo pipefail

# Update WordPress plugin options on the remote droplet via WP-CLI inside the wordpress container
# Usage: ./scripts/update-wp-endpoint.sh user@host license_endpoint [ssh-port]

TARGET=${1:?user@host}
ENDPOINT=${2:?license endpoint URL}
PORT=${3:-22}

echo "Updating WP option gdwb_license_server_endpoint -> $ENDPOINT on $TARGET"

ssh -p "$PORT" "$TARGET" <<EOF
set -e
sudo docker compose -f /srv/gdwb/releases/current/docker-compose.prod.yml exec -T wordpress bash -lc \
  "wp option update gdwb_license_server_endpoint '$ENDPOINT' --allow-root --path=/var/www/html"
sudo docker compose -f /srv/gdwb/releases/current/docker-compose.prod.yml exec -T wordpress bash -lc \
  "wp option update gdwb_license_server_enabled 1 --allow-root --path=/var/www/html"
EOF

echo "WP options updated."
