# In-Container Auto-Prune Lifecycle

This document describes the in-container automatic JWKS key pruning lifecycle and runtime configuration.

Overview
- The license server supports automated pruning of expired (retired) signing keys to keep the `keys/` directory tidy.
- Two prune methods are supported inside the container:
  - `cli`: runs the PHP CLI runner `license-server/prune_cli.php` that directly updates `keys/keys_index.json` and removes expired PEM files.
  - `http`: calls the admin-only endpoint `POST /api/v1/jwks/prune` using the admin token.

Configuration (environment variables)
- `LICENSE_ENABLE_AUTO_PRUNE` (default: `0`) — set to `1`/`true` to enable the auto-prune lifecycle inside the container.
- `LICENSE_PRUNE_METHOD` (default: `cli`) — one of `cli` or `http`.
- `LICENSE_PRUNE_INTERVAL_SECONDS` (default: `3600`) — how often to run the prune loop (seconds).
- `LICENSE_PRUNE_ON_STARTUP` (default: `1`) — whether to run a prune immediately after the server becomes ready.
- `LICENSE_PRUNE_LOCK_TTL_SECONDS` (default: `43200`) — TTL for the prune lock file (seconds). If a lock is older than this, it will be considered stale and removed.
- `LICENSE_ADMIN_TOKEN` — admin token to use with the `http` method (falls back to `license-server/keys/admin_token.txt`).

Behavior
- When `LICENSE_ENABLE_AUTO_PRUNE` is enabled the `entrypoint.sh` will start the license server in the background and start a prune loop.
- The `cli` method uses `prune_cli.php` which acquires an exclusive lock file at `keys/.prune.lock` to prevent overlapping runs. If the lock is stale it will be cleaned up.
- The `http` method calls `POST /api/v1/jwks/prune` using the admin token. Use this if you prefer HTTP-based admin controls or have a separate admin token store.
- Logs are written to stdout/stderr (container logs). The prune runner prints `Pruned keys: [...]` when keys are pruned.

Best practices
- Production: prefer a separate scheduler (Cron, Kubernetes CronJob, or external runbook) to perform rotations & pruning.
- Keep a conservative grace period (`LICENSE_KEY_GRACE_PERIOD_SECONDS`) so distributed clients have time to fetch updated JWKS.
- Use `http` method when your admin tokens are centrally managed and injected at runtime; prefer `cli` method for contained environments.

Troubleshooting
- If you see "Another prune is running (lock held)" messages, another process holds the lock; check `keys/.prune.lock` for PID and timestamp.
- If stale locks persist, increase `LICENSE_PRUNE_LOCK_TTL_SECONDS` or investigate long-running prune operations.
