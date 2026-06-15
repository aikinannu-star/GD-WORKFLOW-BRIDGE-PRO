Progressive Rollout & Orchestration

This document describes the progressive rollout support in `.github/workflows/jwks-rotation.yml`.

Targets file
- Create `deploy/targets.json` (or set `ROLLOUT_TARGETS_JSON` secret) with an array of objects:
  - `name`: friendly name
  - `url`: base URL for the license server (https://...)
  - `admin_token`: admin bearer token used to call rotate/activate/rollback
  - `license_test_key`: optional license key to use for issuing test tokens

Example (see `deploy/targets.example.json`):
[
  {
    "name": "us-east-1-production",
    "url": "https://license-us-east.example.com",
    "admin_token": "REPLACE_WITH_ADMIN_TOKEN",
    "license_test_key": "REPLACE_WITH_TEST_LICENSE_KEY"
  }
]

Workflow usage
- Manual dispatch with percentage:
  - Go to Actions → JWKS Rotation and Prune Orchestration → Run workflow
  - Set `percentage` to the percent of targets to rotate (1-100)
  - The `progressive-rollout` job will pick the first N targets from `deploy/targets.json` (N = ceil(total * percentage/100)) and rotate them sequentially.

Notifications
- Slack: set the repository secret `SLACK_WEBHOOK_URL` to post simple text notifications on failure.
- Email: configure SMTP secrets (`SMTP_HOST`, `SMTP_PORT`, `SMTP_USERNAME`, `SMTP_PASSWORD`) and `NOTIFY_EMAIL_TO`, `NOTIFY_EMAIL_FROM` to send email on failures.

Rollback behavior
- If rotation fails for a target the orchestrator will attempt:
  1. If the rotate response returned `old_kid`, call `POST /api/v1/jwks/activate` with that kid.
  2. Otherwise call `POST /api/v1/jwks/rollback`.

Security
- Do NOT store admin tokens in the repo. Use GitHub Secrets to store `ROLLOUT_TARGETS_JSON` (if you prefer a secret-based targets list) or store only non-sensitive names and load tokens from secrets mapped by name in your pipeline.

Notes
- The orchestrator assumes your license-server exposes the admin endpoints added in `license-server/jwks.php` (`/api/v1/jwks/activate` and `/api/v1/jwks/rollback`).
- The progressive orchestrator is intentionally simple: it selects a deterministic subset (first N targets). For production you may want to randomize selection or control ordering by risk/traffic.

Canary health thresholds
- The workflow supports configurable canary health thresholds via the dispatch inputs:
  - `canary_samples` (default 5): how many synthetic license issue+introspect requests to run.
  - `canary_success_rate_threshold` (default 95): minimum percent success required.
  - `canary_max_latency_ms` (default 1000): maximum average combined latency (token issue + introspect) in milliseconds.
- If the canary check fails the workflow will abort and (if configured) send Slack/email notifications.

Staged-region sequencing
- You can group targets by `region` in `deploy/targets.json`. The workflow provides a `region-sequencer` job which:
  - Determines a region order from a top-level `region_sequence` field in the targets file, or infers the unique region order from the list.
  - Performs rotation + health checks for all targets in a region sequentially, then waits `region_delay_minutes` before moving to the next region.
  - On failure for any target in a region it will attempt the same rollback behavior and abort the sequence.

Example target object with `region`:
{
  "name": "license-us-east-1",
  "region": "us-east-1",
  "url": "https://license-us-east.example.com",
  "admin_token": "...",
  "license_test_key": "..."
}

Security reminder
- Keep admin tokens and secrets in GitHub Secrets or an external secrets manager. The orchestrator also supports storing a JSON targets list in the `ROLLOUT_TARGETS_JSON` secret to avoid checked-in files.
