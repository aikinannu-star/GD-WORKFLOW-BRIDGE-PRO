Using GitHub Codespaces to run the GDWB stack
===========================================

This devcontainer configuration brings up the core gd-workflow-bridge-pro services inside a Codespace using the repository's `docker-compose.yml`.

Quick start
-----------

1. Open the `gd-workflow-bridge-pro` repository in GitHub and click **Code → Codespaces → New codespace** (or create a Codespace from this branch).
2. Codespaces will read `.devcontainer/devcontainer.json` and start the specified compose services automatically.
3. After the Codespace finishes provisioning, edit `.env` (a copy of `.env.example` is created automatically). IMPORTANT: do not commit `.env`.

Note about the Node backend (`myApp-backend`)

The Node backend is optional and not started by default via this compose setup. Run it separately if you need the service mounted into the Codespace.

Notes and tips
--------------
- Ports forwarded by the devcontainer: 8000 (gateway), 8001 (license-server), 5432 (postgres), 6379 (redis), 9090 (prometheus), 9091 (pushgateway).
- Secrets: populate `.env` from `.env.example` or set repository secrets in GitHub and keep `.env` out of git.
- If you prefer not to run all services automatically, remove entries from `runServices` and start them manually with `docker compose up SERVICE`.
