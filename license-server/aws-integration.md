# AWS Secrets Manager integration

This guide explains how to use AWS Secrets Manager as an alternative to HashiCorp Vault for injecting secrets into the license server.

Loading secrets at startup
--------------------------

- Place `license-server/aws_secrets.php` in the `license-server/` directory (provided). The helper looks for:
  - `AWS_SECRET_ID` (name/ARN of the secret in Secrets Manager)
  - `AWS_REGION` (optional, defaults to `us-east-1`)

- The helper will try the AWS SDK for PHP (`Aws\\SecretsManager\\SecretsManagerClient`) first. If the SDK is not installed, it will attempt to use the `aws` CLI.

- If the secret value is JSON, keys will be mapped to environment variables inside the PHP process. Example secret JSON:

```json
{
  "LICENSE_CLIENTS_JSON": "{...}",
  "LICENSE_ADMIN_TOKEN": "<admin-token>",
  "CLIENT_DEV_CLIENT_SECRET_HASH": "$2y$..."
}
```

Rotation
--------

- You can rotate client secrets using the included `php license-server/generate_client.php <client_id>` to produce a new secret. Use the AWS CLI or SDK to update the secret in Secrets Manager with the new value.

Example (Bash using AWS CLI):

```bash
new_secret=$(php license-server/generate_client.php dev-client | sed -n "s/Secret (store securely, shown once): //p")
aws secretsmanager put-secret-value --secret-id "my/gdwb" --secret-string "{\"CLIENT_DEV_CLIENT_SECRET\": \"$new_secret\"}" --region us-east-1
```

Notes
-----
- The helper will not overwrite an existing environment variable unless it is empty.
- For production, prefer storing only `client_secret_hash` values and not plaintext secrets. Place the hash in Secrets Manager and map it to `CLIENT_<ID>_SECRET_HASH`.

Runtime integration (Docker / systemd)
------------------------------------

1) Docker (container) entrypoint

- `license-server/entrypoint.sh` is provided. It will fetch secrets from AWS Secrets Manager (if `AWS_SECRET_ID` is set) and from Vault (if `VAULT_ADDR` and `VAULT_TOKEN` are set), export them into the process environment, and then start the PHP built-in server. A simple image is included at `license-server/Dockerfile` which installs `awscli` and `jq`.

Example Docker run (development):

```bash
docker build -t gdwb-license-server -f license-server/Dockerfile .
docker run --rm -p 8001:8001 \
  -e AWS_SECRET_ID="my/gdwb" \
  -e AWS_REGION="us-east-1" \
  -e AWS_ACCESS_KEY_ID="..." \
  -e AWS_SECRET_ACCESS_KEY="..." \
  gdwb-license-server
```

2) systemd

- A helper `deploy/gdwb-fetch-secrets.sh` and a unit template `deploy/license-server.service` are provided. The helper writes secrets to `/etc/gdwb/license-server.env` (owned by root, 600) and the unit file references that env file via `EnvironmentFile=-/etc/gdwb/license-server.env` and calls the helper in `ExecStartPre`.

Install example (Linux):

```bash
sudo cp deploy/gdwb-fetch-secrets.sh /usr/local/bin/gdwb-fetch-secrets.sh
sudo chmod 700 /usr/local/bin/gdwb-fetch-secrets.sh
sudo cp deploy/license-server.service /etc/systemd/system/gdwb-license-server.service
sudo systemctl daemon-reload
sudo systemctl enable --now gdbw-license-server.service
```

Adjust paths (`/opt/gdwb`) and the `ExecStart` line to match your deployment layout.

