# Codespaces Devcontainer Validation

Follow these steps to validate the Codespaces devcontainer and ensure parity with a local Docker environment.

1. Open the repository in GitHub Codespaces (or `gh codespace create` + `gh codespace code`)

2. Ensure repository secrets are configured in the repository settings:
   - `POSTGRES_PASSWORD`, `AUTH_JWT_SECRET`, `BILLING_ADMIN_TOKEN`, `STRIPE_SECRET_KEY`, etc.

3. Start the compose services (Codespaces runs Docker for you):
   ```sh
   # from the codespace terminal (workspace root)
   docker compose -f docker-compose.yml up -d --build
   ```

4. Verify the health endpoints are reachable (forwarded ports in `.devcontainer/devcontainer.json`):
   ```sh
   curl -sS http://127.0.0.1:8000/health
   curl -sS http://127.0.0.1:8001/health
   ```

5. Run a lightweight smoke test:
   ```sh
   curl -sS http://127.0.0.1:8000/api/v1/marketplace/products
   ```

6. If services fail to start, check `docker compose ps` and service logs:
   ```sh
   docker compose logs license-server | tail -n 200
   docker compose logs gateway-service | tail -n 200
   ```

7. Post-creation: update the `.env` file in the codespace (the `postCreateCommand` will copy `.env.example` to `.env`). Fill any secrets from repository secrets or Codespaces secret storage.

8. After validation, bring down services to free ports:
   ```sh
   docker compose down
   ```

Notes:
- The devcontainer uses `gateway-service` as the primary container and forwards ports: `8000,8001,8010,5432,6379,9090,9091`.
- Do not store secrets in the repo; use GitHub Secrets or Codespaces secrets.
