#!/usr/bin/env bash
set -euo pipefail

# Configure DNS A records on DigitalOcean for WP and license hostnames.
# Usage: ./scripts/configure-dns.sh DOMAIN DROPLET_IP WP_HOST SUBDOMAIN LICENSE_HOST SUBDOMAIN
# Example: ./scripts/configure-dns.sh example.com 203.0.113.10 www license license

DOMAIN=${1:?Domain (example.com)}
IP=${2:?Droplet IP}
WP_NAME=${3:-www}
LICENSE_NAME=${4:-license}

if [ -z "${DO_API_TOKEN:-}" ]; then
  echo "Set DO_API_TOKEN env var with your DigitalOcean API token." >&2
  exit 1
fi

create_or_update() {
  local name=$1
  echo "Creating/updating A record for ${name}.${DOMAIN} -> ${IP}"
  # Check existing records
  resp=$(curl -sS -H "Authorization: Bearer ${DO_API_TOKEN}" "https://api.digitalocean.com/v2/domains/${DOMAIN}/records")
  exists=$(python - <<PY
import sys, json
data=json.load(sys.stdin)
name="$name"
for r in data.get('domain_records',[]):
    if r.get('type')=='A' and r.get('name')==name:
        print(r.get('id'))
        sys.exit(0)
print('')
PY
<<<"$resp")

  if [ -n "$exists" ]; then
    echo "Updating existing record id=$exists"
    curl -sS -X PUT -H "Authorization: Bearer ${DO_API_TOKEN}" -H "Content-Type: application/json" \
      -d "{\"data\":\"${IP}\"}" \
      "https://api.digitalocean.com/v2/domains/${DOMAIN}/records/${exists}"
  else
    echo "Creating record"
    curl -sS -X POST -H "Authorization: Bearer ${DO_API_TOKEN}" -H "Content-Type: application/json" \
      -d "{\"type\":\"A\",\"name\":\"${name}\",\"data\":\"${IP}\",\"ttl\":1800}" \
      "https://api.digitalocean.com/v2/domains/${DOMAIN}/records"
  fi
}

create_or_update "$WP_NAME"
create_or_update "$LICENSE_NAME"

echo "DNS configuration complete. Allow some time for propagation."
