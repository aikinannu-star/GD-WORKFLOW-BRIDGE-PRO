#!/usr/bin/env bash
set -euo pipefail

# Fetch AWS Secrets Manager secret (if configured)
if [ -n "${AWS_SECRET_ID:-}" ]; then
  echo "Fetching AWS Secrets Manager secret: $AWS_SECRET_ID"
  SECRET_JSON=$(aws secretsmanager get-secret-value --secret-id "$AWS_SECRET_ID" --region "${AWS_REGION:-us-east-1}" --query SecretString --output text 2>/dev/null || true)
  if [ -n "$SECRET_JSON" ]; then
    if echo "$SECRET_JSON" | jq -e . >/dev/null 2>&1; then
      echo "Parsed JSON secret — exporting keys"
      echo "$SECRET_JSON" | jq -r 'to_entries[] | "\(.key)::::\(.value|@base64)"' | while IFS='::::' read -r KEY B64; do
        VAL=$(echo "$B64" | base64 --decode)
        export "$KEY"="$VAL"
        echo "Exported $KEY"
      done
    else
      export AWS_SECRET_VALUE="$SECRET_JSON"
      echo "Exported AWS_SECRET_VALUE"
    fi
  else
    echo "No secret found for $AWS_SECRET_ID or aws cli failed"
  fi
fi

# Fetch Vault secrets (optional, simple passthrough)
if [ -n "${VAULT_ADDR:-}" ] && [ -n "${VAULT_TOKEN:-}" ]; then
  echo "Fetching Vault secrets from $VAULT_ADDR"
  VAULT_PATH=${VAULT_SECRET_PATH:-secret/data/gdwb}
  RES=$(curl -sS -H "X-Vault-Token: $VAULT_TOKEN" "$VAULT_ADDR/v1/$VAULT_PATH" || true)
  if echo "$RES" | jq -e '.data.data' >/dev/null 2>&1; then
    echo "$RES" | jq -r '.data.data | to_entries[] | "\(.key)::::\(.value|@base64)"' | while IFS='::::' read -r KEY B64; do
      VAL=$(echo "$B64" | base64 --decode)
      export "$KEY"="$VAL"
      echo "Exported $KEY from Vault"
    done
  fi
fi

### Runtime auto-prune lifecycle
# Configuration (environment variables)
# LICENSE_ENABLE_AUTO_PRUNE: 1/true to enable auto-prune loop
# LICENSE_PRUNE_METHOD: 'cli' (default) or 'http' to call the admin endpoint
# LICENSE_PRUNE_INTERVAL_SECONDS: interval in seconds between prune runs (default 3600)
# LICENSE_PRUNE_ON_STARTUP: 1/true to run a prune immediately after server becomes ready (default 1)
# LICENSE_SERVER_PORT / LICENSE_SERVER_HOST: server bind settings

# Default behavior when no command provided: start built-in PHP server
if [ $# -eq 0 ]; then
  PORT=${LICENSE_SERVER_PORT:-8001}
  HOST=${LICENSE_SERVER_HOST:-0.0.0.0}

  # Derive admin token from env or file if present
  ADMIN_TOKEN=${LICENSE_ADMIN_TOKEN:-${ADMIN_TOKEN:-$(cat license-server/keys/admin_token.txt 2>/dev/null || true)}}

  ENABLE_PRUNE=${LICENSE_ENABLE_AUTO_PRUNE:-0}
  PRUNE_METHOD=${LICENSE_PRUNE_METHOD:-cli}
  PRUNE_INTERVAL=${LICENSE_PRUNE_INTERVAL_SECONDS:-3600}
  PRUNE_ON_START=${LICENSE_PRUNE_ON_STARTUP:-1}

  # Pushgateway configuration (optional) - used by `metrics_lib.php` when present
  # Default to the docker-compose service name so containers can reach it via the network
  PUSHGATEWAY_URL=${PUSHGATEWAY_URL:-http://pushgateway:9091}
  PUSHGATEWAY_JOB=${PUSHGATEWAY_JOB:-license_server}
  PUSHGATEWAY_INSTANCE=${PUSHGATEWAY_INSTANCE:-$(hostname 2>/dev/null || echo license_server)}
  export PUSHGATEWAY_URL PUSHGATEWAY_JOB PUSHGATEWAY_INSTANCE

  if [[ "${ENABLE_PRUNE,,}" == "1" || "${ENABLE_PRUNE,,}" == "true" ]]; then
    echo "Starting license server (background) on ${HOST}:${PORT} with auto-prune (method=${PRUNE_METHOD})"
    php -S ${HOST}:${PORT} -t license-server > /proc/1/fd/1 2>/proc/1/fd/2 &
    SERVER_PID=$!

    shutdown() {
      echo "Shutting down..."
      if [[ ! -z "${PRUNE_PID:-}" ]]; then
        kill -TERM "${PRUNE_PID}" 2>/dev/null || true
      fi
      kill -TERM "${SERVER_PID}" 2>/dev/null || true
      wait || true
      exit 0
    }
    trap shutdown SIGINT SIGTERM EXIT

    # wait for server ready
    for i in $(seq 1 30); do
      if curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:${PORT}/" >/dev/null 2>&1; then
        break
      fi
      sleep 1
    done

    # choose prune runner: CLI directly manipulates key files, HTTP hits admin endpoint
    if [[ "${PRUNE_METHOD,,}" == "cli" ]]; then
      echo "Using CLI prune runner (php license-server/prune_cli.php) interval=${PRUNE_INTERVAL}s"
      ( 
        if [[ "${PRUNE_ON_START,,}" == "1" || "${PRUNE_ON_START,,}" == "true" ]]; then
          php license-server/prune_cli.php || true
        fi
        while true; do
          sleep ${PRUNE_INTERVAL}
          php license-server/prune_cli.php || true
        done
      ) &
      PRUNE_PID=$!
    else
      echo "Using HTTP prune runner (curl to /api/v1/jwks/prune) interval=${PRUNE_INTERVAL}s"
      ( 
        if [[ "${PRUNE_ON_START,,}" == "1" || "${PRUNE_ON_START,,}" == "true" ]]; then
          if [[ -n "${ADMIN_TOKEN}" ]]; then
            curl -sS -X POST "http://127.0.0.1:${PORT}/api/v1/jwks/prune" -H "Authorization: Bearer ${ADMIN_TOKEN}" || true
          else
            echo "ADMIN_TOKEN not available; skipping startup prune"
          fi
        fi
        while true; do
          sleep ${PRUNE_INTERVAL}
          if [[ -n "${ADMIN_TOKEN}" ]]; then
            curl -sS -X POST "http://127.0.0.1:${PORT}/api/v1/jwks/prune" -H "Authorization: Bearer ${ADMIN_TOKEN}" || true
          else
            echo "ADMIN_TOKEN not available; skipping prune run"
          fi
        done
      ) &
      PRUNE_PID=$!
    fi

    # wait for server process
    wait ${SERVER_PID}
    exit $?
  else
    echo "Starting license server on ${HOST}:${PORT} (auto-prune disabled)"
    exec php -S ${HOST}:${PORT} -t license-server
  fi
else
  exec "$@"
fi
