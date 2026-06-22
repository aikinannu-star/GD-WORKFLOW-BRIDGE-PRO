#!/usr/bin/env bash
set -euo pipefail

# Create a DigitalOcean Droplet using doctl if available, otherwise use the DO API.
# Usage: ./scripts/create-droplet.sh [DROPLET_NAME] [REGION] [SIZE] [SSH_KEY_IDS]
# Example: ./scripts/create-droplet.sh gdwb-1 nyc1 s-1vcpu-2gb "12345,67890"

NAME=${1:-gdwb-1}
REGION=${2:-nyc1}
SIZE=${3:-s-1vcpu-2gb}
SSH_KEY_IDS=${4:-${SSH_KEY_IDS:-}}

if command -v doctl >/dev/null 2>&1; then
  echo "Using doctl to create droplet..."
  if [ -z "$SSH_KEY_IDS" ]; then
    echo "Provide SSH key IDs (doctl compute ssh-key list to find IDs)" >&2
    exit 1
  fi
  IP=$(doctl compute droplet create "$NAME" --region "$REGION" --size "$SIZE" --image ubuntu-22-04-x64 --ssh-keys "$SSH_KEY_IDS" --tag-names gdwb --wait --format PublicIPv4 --no-header)
  echo "Droplet created: $IP"
  exit 0
fi

if [ -z "${DO_API_TOKEN:-}" ]; then
  echo "Install doctl or set DO_API_TOKEN to use DigitalOcean API." >&2
  exit 1
fi

if [ -z "$SSH_KEY_IDS" ]; then
  echo "Provide SSH key IDs (DigitalOcean key IDs) via SSH_KEY_IDS env or arg." >&2
  exit 1
fi

echo "Creating droplet via DigitalOcean API..."
PAYLOAD=$(cat <<EOF
{
  "name": "$NAME",
  "region": "$REGION",
  "size": "$SIZE",
  "image": "ubuntu-22-04-x64",
  "ssh_keys": [$(echo $SSH_KEY_IDS | sed "s/,/','/g" | sed "s/^/'/" | sed "s/$/'/")],
  "tags": ["gdwb"]
}
EOF
)

RESP=$(curl -sS -X POST "https://api.digitalocean.com/v2/droplets" \
  -H "Authorization: Bearer ${DO_API_TOKEN}" \
  -H "Content-Type: application/json" \
  -d "$PAYLOAD")

IP=$(python - <<PY
import sys, json
try:
    j=json.load(sys.stdin)
    # prefer networks.v4[0].ip_address
    droplet=j.get('droplet',{})
    nets=droplet.get('networks',{}).get('v4',[])
    if nets:
        print(nets[0].get('ip_address'))
    else:
        print('')
except Exception:
    print('')
PY

<<<"$RESP")

if [ -z "$IP" ]; then
  echo "Failed to create droplet. Response:" >&2
  echo "$RESP" >&2
  exit 1
fi

echo "Droplet created: $IP"
