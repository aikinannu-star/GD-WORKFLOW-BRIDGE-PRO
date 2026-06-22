#!/usr/bin/env bash
set -euo pipefail

# Deploy only the license-server on a remote droplet by unzipping current release
# and running docker compose. Assumes release ZIP has been uploaded to /tmp/ on remote.
# Usage: ./scripts/deploy-license-remote.sh user@host [ssh-port]

TARGET=${1:?user@host}
PORT=${2:-22}

echo "Deploying license-server to $TARGET"

ssh -p "$PORT" "$TARGET" <<'EOF'
set -e
sudo mkdir -p /srv/gdwb/releases/current
sudo unzip -o /tmp/gd-workflow-bridge-pro.zip -d /srv/gdwb/releases/current
sudo chown -R ${DEPLOY_USER:-deploy}:${DEPLOY_USER:-deploy} /srv/gdwb/releases/current
cd /srv/gdwb/releases/current
sudo docker compose -f docker-compose.prod.yml pull || true
sudo docker compose -f docker-compose.prod.yml up -d --build license-server
sudo rm -f /tmp/gd-workflow-bridge-pro.zip
EOF

echo "Remote license-server deploy initiated."
