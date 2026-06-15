#!/usr/bin/env bash
set -euo pipefail

OUTFILE=${OUTFILE:-/etc/gdwb/license-server.env}
mkdir -p "$(dirname "$OUTFILE")"
> "$OUTFILE"

if [ -n "${AWS_SECRET_ID:-}" ]; then
  SECRET_JSON=$(aws secretsmanager get-secret-value --secret-id "$AWS_SECRET_ID" --region "${AWS_REGION:-us-east-1}" --query SecretString --output text 2>/dev/null || true)
  if echo "$SECRET_JSON" | jq -e . >/dev/null 2>&1; then
    echo "$SECRET_JSON" | jq -r 'to_entries[] | "\(.key)=\(.value)"' >> "$OUTFILE"
  else
    echo "AWS_SECRET_VALUE=$SECRET_JSON" >> "$OUTFILE"
  fi
fi

if [ -n "${VAULT_ADDR:-}" ] && [ -n "${VAULT_TOKEN:-}" ]; then
  VAULT_PATH=${VAULT_SECRET_PATH:-secret/data/gdwb}
  RES=$(curl -sS -H "X-Vault-Token: $VAULT_TOKEN" "$VAULT_ADDR/v1/$VAULT_PATH" || true)
  if echo "$RES" | jq -e '.data.data' >/dev/null 2>&1; then
    echo "$RES" | jq -r '.data.data | to_entries[] | "\(.key)=\(.value)"' >> "$OUTFILE"
  fi
fi

chmod 600 "$OUTFILE"
echo "Wrote secrets to $OUTFILE"
