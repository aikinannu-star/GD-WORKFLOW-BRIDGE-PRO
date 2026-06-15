#!/usr/bin/env bash
set -euo pipefail

if [ "$#" -lt 1 ]; then
  echo "Usage: $0 <client_id> [aws_secret_id]"
  exit 2
fi

CLIENT_ID="$1"
AWS_SECRET_ID="${2:-${AWS_SECRET_ID:-gdwb/${CLIENT_ID}}}"
AWS_REGION="${AWS_REGION:-us-east-1}"

OUT=$(php license-server/generate_client.php "$CLIENT_ID" 2>&1)
if [ $? -ne 0 ]; then
  echo "generate_client failed" >&2
  echo "$OUT" >&2
  exit 3
fi

SECRET=$(printf "%s" "$OUT" | sed -n "s/Secret (store securely, shown once): //p" | tail -n1)
if [ -z "$SECRET" ]; then
  echo "Could not parse secret from generate_client output" >&2
  echo "$OUT" >&2
  exit 4
fi

NORM=$(echo "$CLIENT_ID" | tr '[:lower:]' '[:upper:]' | sed 's/[^A-Z0-9]/_/g')
KEY_NAME="CLIENT_${NORM}_SECRET"

PAYLOAD=$(printf '{"%s":"%s"}' "$KEY_NAME" "$SECRET")

echo "Updating AWS Secrets Manager secret: $AWS_SECRET_ID (region: $AWS_REGION)"
if aws secretsmanager create-secret --name "$AWS_SECRET_ID" --secret-string "$PAYLOAD" --region "$AWS_REGION" >/dev/null 2>&1; then
  echo "Created secret $AWS_SECRET_ID"
else
  echo "Secret exists or create failed, attempting to put-secret-value"
  aws secretsmanager put-secret-value --secret-id "$AWS_SECRET_ID" --secret-string "$PAYLOAD" --region "$AWS_REGION"
fi

echo "Secret stored. Secret value (shown once): $SECRET"
