Using GitHub Codespaces to run the GDWB stack
===========================================

This devcontainer configuration brings up the core gd-workflow-bridge-pro services inside a Codespace using the repository's `docker-compose.yml`.

Quick start
-----------

1. Open the `gd-workflow-bridge-pro` repository in GitHub and click **Code → Codespaces → New codespace** (or create a Codespace from this branch).
2. Codespaces will read `.devcontainer/devcontainer.json` and start the specified compose services automatically.
3. After the Codespace finishes provisioning, edit `.env` (a copy of `.env.example` is created automatically). IMPORTANT: do not commit `.env`.

Including the Node backend (`myApp-backend`)
-------------------------------------------

If you want the Node backend in the same Codespace (so the compose service `myapp-backend` can mount it):

- Ensure `myApp-backend` is available inside the Codespace filesystem (either by adding it to this repository or cloning it inside the Codespace). Example:

  ```bash
  # inside the Codespace terminal
  git clone https://github.com/youruser/godemars-empire4.git /workspaces/godemars-empire4
  ```

- Then set `MYAPP_BACKEND_PATH` in `.env` to the absolute path inside the Codespace, e.g. `/workspaces/godemars-empire4/myApp-backend`.

- Rebuild or restart the compose services (you can run `docker compose up -d` from the repo root in the Codespace terminal).

Notes and tips
--------------
- Ports forwarded by the devcontainer: 8000 (gateway), 8001 (license-server), 8010 (myapp-backend host mapping), 5432 (postgres), 6379 (redis), 9090 (prometheus), 9091 (pushgateway).
- Secrets: populate `.env` from `.env.example` or set repository secrets in GitHub and keep `.env` out of git.
- If you prefer not to run all services automatically, remove entries from `runServices` and start them manually with `docker compose up SERVICE`.
