# License Server (scaffold)

This folder contains a minimal development scaffold for a license server that issues RS256-signed JWT tokens for the plugin to consume.

Important: This is an example only. Do NOT use as-is in production.

Quick start (dev):

1. Generate an RSA keypair (2048+ bits). Save `private.pem` and `public.pem` under `license-server/keys/`.

2. Run a PHP built-in server for local testing:

```bash
php -S 127.0.0.1:8001 -t license-server
```

3. Configure the plugin `License_Client::SERVER_ENABLED = true` and set `SERVER_ENDPOINT` to `http://127.0.0.1:8001` and paste the contents of `keys/public.pem` into `License_Client::PUBLIC_KEY`.

4. Use the plugin license activation UI to activate a test key (keys starting with `TEST-` expire in 30 days in this scaffold).

Endpoints:
- `POST /api/v1/validate` — accepts `license_key` and optional `site`. Returns JSON `{ success: true, token: "<jwt>", exp: <timestamp> }`.
- `POST /api/v1/introspect` — accepts `token`. Returns `{ success: true }` or `{ success: false, message: "revoked_jti" }`.
- `POST /api/v1/revoke` — (admin-only) accepts `license_key` and Bearer token auth. Revokes a license immediately.
- `GET /api/v1/jwks` — returns JWKS with all currently valid public keys (JSON Web Key Set format per RFC 7517).
- `POST /api/v1/jwks/rotate` — (admin-only) generates and activates a new signing key. Returns new `kid` and private key path.
- `GET /api/v1/jwks/status` — (admin-only) returns current key status and rotation history.
- `POST /api/v1/token` and `POST /oauth/token` — OAuth-like token endpoint. Supports `grant_type=client_credentials` (client auth via Basic or `client_id`+`client_secret`), and `grant_type=license|password` (legacy license key exchange). Returns `{ access_token: "<jwt>", token_type: "bearer", expires_in: <secs> }`.

Token endpoint examples
-----------------------

Client credentials (POST body):

```bash
curl -X POST http://127.0.0.1:8001/oauth/token \
  -d "grant_type=client_credentials" \
  -d "client_id=dev-client" \
  -d "client_secret=dev-secret"
```

Client credentials (Basic auth):

```bash
curl -X POST http://127.0.0.1:8001/api/v1/token \
  -H "Authorization: Basic $(echo -n dev-client:dev-secret | base64)" \
  -d "grant_type=client_credentials"
```

License grant (legacy):

```bash
curl -X POST http://127.0.0.1:8001/api/v1/token \
  -d "grant_type=license" \
  -d "license_key=TEST-GDW-INTEG-000000000001" \
  -d "site=http://localhost"
```

OpenID Connect discovery
------------------------

For compatibility with OIDC/OAuth tooling, the server exposes discovery metadata at `/.well-known/openid-configuration` (also available as `/.well-known/oauth-authorization-server`). Example:

```bash
curl http://127.0.0.1:8001/.well-known/openid-configuration | jq
```

The discovery document includes `issuer`, `token_endpoint`, `jwks_uri`, `introspection_endpoint`, and `revocation_endpoint`.

Clients registry
----------------

You may register simple clients in `license-server/keys/clients.json`. For development the repository includes a sample client:

```json
{
  "dev-client": {
    "client_secret": "dev-secret",
    "name": "Development client",
    "scopes": ["admin"]
  }
}
```

For production use, store clients and secrets securely and prefer hashed secrets (`client_secret_hash`) over plaintext strings. The server accepts client credentials via Basic auth or `client_id`+`client_secret` POST parameters.

Running the token issuance test
------------------------------

A small test script exercises the token issuance flows (client_credentials and license). Run it locally:

```bash
./tests/token-issuance-test.sh
```


Key Rotation & JWKS
-------------------

The server supports automatic key rotation via the `/api/v1/jwks/rotate` endpoint. Clients can fetch the JWKS endpoint to discover and cache public keys:

```bash
curl https://license.example.com/api/v1/jwks
```

Example JWKS response:

```json
{
  "keys": [
    {
      "kty": "RSA",
      "alg": "RS256",
      "use": "sig",
      "kid": "kid_20250522120000_abc12345",
      "n": "...",
      "e": "AQAB"
    }
  ]
}
```

Clients extract the `kid` from the JWT header and use the corresponding public key to verify the signature. During key rotation, both old and new keys remain in the JWKS for a configurable grace period, allowing distributed clients to validate previously-issued tokens.

See [LICENSE_SERVER_PRODUCTION.md](../LICENSE_SERVER_PRODUCTION.md) for production deployment with TLS, key rotation strategy, and hardening.

Docker compose for local Redis
--------------------------------

To run a local Redis instance for testing JTI blacklisting, use the provided `docker-compose.yml` at the repo root:

```bash
docker-compose up -d redis
```

Then set environment variables for the PHP dev server before starting it:

```powershell
$env:REDIS_HOST='127.0.0.1';
$env:REDIS_PORT='6379';
# php -S 127.0.0.1:8001 -t license-server
```

The license server will attempt to use the PHP `Redis` extension when available and fall back to a file-backed blacklist otherwise.

Integration testing
-------------------

A local integration test harness is available at `tests/license-server-integration.sh`. It starts the Docker Compose stack, launches the local license server, and exercises the full validate → introspect → revoke → introspect flow.

Docker compose for Redis + Postgres (migrations)
-------------------------------------------------

This repository includes a `docker-compose.yml` that starts Redis and Postgres and runs the initial migration automatically.

Start services:

```bash
docker-compose up -d
```

The Postgres service initializes the database and runs `license-server/migrations/postgres.sql` on first boot. Use the included `run-dev.ps1` to start Redis/Postgres and the PHP dev server together on Windows:

```powershell
.\run-dev.ps1
```

Environment variables (optional): `POSTGRES_USER`, `POSTGRES_PASSWORD`, `POSTGRES_DB`, `REDIS_HOST`, `REDIS_PORT`.

Postgres / Production notes
---------------------------

This scaffold can optionally use a real database (recommended: PostgreSQL) instead of the file-based `data/licenses.json` store.

Configuration (environment variables):

- `LICENSE_DB_DSN` — full PDO DSN (e.g. `pgsql:host=127.0.0.1;port=5432;dbname=licenses`)
- `LICENSE_DB_USER` — DB username
- `LICENSE_DB_PASS` — DB password

Or set discrete parts and driver:

- `LICENSE_DB_DRIVER` — `pgsql` (default) or `mysql`
- `LICENSE_DB_HOST`, `LICENSE_DB_PORT`, `LICENSE_DB_NAME`

Migration
---------

Create the tables using the provided migration script:

```bash
psql -h <host> -U <user> -d <db> -f license-server/migrations/postgres.sql
```

After migration, set the environment variables above (or use your process manager) and restart the PHP server.

Security
--------

- Keep `private.pem` on the license server only. Do NOT commit it to source control.
- Store `public.pem` with the client/plugin (this repo places it in `keys/public.pem`).
- Use TLS (HTTPS) for production endpoints and secure DB credentials with your platform's secrets manager.

Secret Rotation & Production Guidance
-------------------------------------

1. Hashed client secrets
  - Store client credentials as `client_secret_hash` using `password_hash()` (bcrypt). See `license-server/generate_client.php` to create/rotate client secrets.

2. Environment-based secret management
  - Prefer environment variables or a secrets manager over checked-in files. The server accepts a full clients JSON via `LICENSE_CLIENTS_JSON` or per-client secrets via `CLIENT_<ID>_SECRET` / `CLIENT_<ID>_SECRET_HASH` environment variables.

3. Private key isolation
  - In production set `LICENSE_PRIVATE_KEY_PATH` to a secure location outside the repository. The server rejects keys inside the repository when `LICENSE_SERVER_ENV=production`.

4. Git hygiene
  - This repo includes a `.gitignore` that excludes `license-server/keys/` and runtime secret files. Never commit `private.pem`, `admin_token.txt`, or client secrets.

5. Startup validation
  - The server performs runtime checks and will fail-fast in production for insecure configs (plaintext client secrets, private key in repo) unless explicitly overridden with admin-only environment flags.

6. Secret rotation process
  - **Client secret rotation:** run `php license-server/generate_client.php <client_id>` to generate a new secret. Update any dependent systems to use the new secret, then optionally revoke old tokens by using the revocation API or by blacklisting JTIs for affected activations.
  - **Admin token rotation:** rotate by generating a new token (`php license-server/generate_admin_token.php`) and replacing the value in your secrets manager. Rotate the token in all automation before removing the old value.
  - **Signing key rotation:** use the JWKS rotate endpoint (`POST /api/v1/jwks/rotate`) or rotate keys on disk and ensure the new public key is served via `/api/v1/jwks`. Old keys remain for a grace period to allow distributed clients to refresh.

Operational notes
-----------------
- Store secrets in a secrets manager (AWS Secrets Manager, Azure Key Vault, HashiCorp Vault) and inject them at runtime via env vars or mounted files.
- Automate rotation with CI/CD pipelines that: update server environment, wait for clients to pick new secrets/keys, then revoke old values.
- Use monitoring and alerts to detect unauthorized access after rotation.


