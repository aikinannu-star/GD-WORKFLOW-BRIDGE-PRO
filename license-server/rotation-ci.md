# CI-driven signing-key rotation

This repository includes a GitHub Actions job that performs a signing-key rotation smoke test in CI.

What it does
- Starts the license server in CI (with Postgres + Redis services when required).
- Calls the JWKS rotate endpoint (`POST /api/v1/jwks/rotate`) using the admin token generated in CI.
- Verifies the new `kid` appears in the JWKS endpoint and that the server can issue and introspect a token signed with the new key.

Where to find it
- Workflow: `.github/workflows/license-server-ci.yml` — job `rotate-keys`.
- Script: `tests/rotate-keys-ci.sh` — performs the rotate + smoke validation.

Notes & production guidance
- The CI job rotates keys inside the CI environment for test/automation purposes. For production key rotation you should:
  - Rotate keys on the live license server (call `/api/v1/jwks/rotate` on the production endpoint using a secure admin token).
  - Ensure clients fetch the JWKS and accept both old and new keys during a grace period.
  - Do not keep private keys in the repository — use a secrets manager and inject `LICENSE_PRIVATE_KEY_PATH` in your deployment.
  - Consider orchestrating rotation during low-traffic windows and monitoring introspection failures.

If you want a scheduled rotation in CI, add a `schedule` trigger in `.github/workflows/license-server-ci.yml` and ensure secrets are configured for the target environment.

Scheduled orchestration
- This repository includes a scheduled orchestration workflow: `.github/workflows/jwks-rotation.yml`.
- Configure per-target secrets in the repository settings for each target you intend to rotate. The workflow's matrix expects the following secret names by default:
  - `STAGING_LICENSE_SERVER_URL`, `STAGING_ADMIN_TOKEN`, `STAGING_LICENSE_TEST_KEY`
  - `PROD_LICENSE_SERVER_URL`, `PROD_ADMIN_TOKEN`, `PROD_LICENSE_TEST_KEY`
- The workflow runs weekly by default and can also be triggered manually via `workflow_dispatch`.
- To add additional targets, update `.github/workflows/jwks-rotation.yml` matrix `include` section with the target name and map secrets accordingly.

Canary & staged rollout notes
- The scheduled workflow supports a canary phase and a staged rollout phase:
  - Canary targets are defined in the `rotate-prune-canary` job and expect secrets like `STAGING_CANARY_LICENSE_SERVER_URL`, `STAGING_CANARY_ADMIN_TOKEN`, etc.
  - Rollout targets are defined in the `rotate-prune-rollout` job and run after canary completes.
- The workflow supports an optional `delay_minutes` `workflow_dispatch` input to pause between canary success and rollout start.
- The `rotate-prune-rollout` job uses the `staged-rollout` environment — configure environment protection (required reviewers) in GitHub to force manual approval before rollout if desired.


Grace period and pruning
- Environment: `LICENSE_KEY_GRACE_PERIOD_SECONDS` — integer seconds that define how long a rotated-away key remains valid for introspection (default: 604800 / 7 days).
- Auto-prune: `LICENSE_ENABLE_AUTO_PRUNE` — set to `1`/`true` to automatically prune expired keys immediately after rotation.
- Admin prune endpoint: `POST /api/v1/jwks/prune` — admin-only endpoint to trigger pruning on demand. The CI test `tests/prune-keys-test.sh` demonstrates using a short grace period and calling this endpoint to remove expired keys.

Production recommendation: keep a conservative grace period (several days) and only prune keys after confirming clients have refreshed their JWKS caches.
