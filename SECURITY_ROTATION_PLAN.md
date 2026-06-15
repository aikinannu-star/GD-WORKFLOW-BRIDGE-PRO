# Security Rotation & Remediation Plan

This document lists immediate, short-term, and follow-up actions to rotate credentials, remove secrets, and harden CI/CD for the `gd-workflow-bridge-pro` repository and related services.

## Immediate (within 1 hour)

- Rotate Postgres password used by local/dev stacks and CI:
  1. Create a new strong password (store in your vault).
  2. Update the `POSTGRES_PASSWORD` Secret in GitHub: `gh secret set POSTGRES_PASSWORD -b"<new>" -R owner/repo` or use the GitHub web UI.
  3. Update `.env` / `.env.example` and `docker-compose.yml` if needed.
  4. If container running, change DB user password:
     ```sh
     docker exec -it <postgres_container> psql -U postgres -c "ALTER USER gdwb_user WITH PASSWORD '<new_password>';"
     ```

- Rotate any payment provider keys (Stripe):
  1. In Stripe dashboard create new API keys (restricted where possible).
  2. Store new `STRIPE_SECRET_KEY` / `STRIPE_PUBLISHABLE_KEY` in GitHub Secrets.
  3. Revoke the old keys in Stripe.

- Rotate admin tokens and service tokens (license-server/admin tokens):
  1. Use `license-server/generate_admin_token.php` or admin endpoints to create a new admin token.
  2. Update `BILLING_ADMIN_TOKEN`, `AUTH_JWT_SECRET`, etc. as GitHub Secrets.
  3. Revoke or rotate any previously issued tokens where supported.

- Reissue license-signing keys if private keys were exposed:
  1. Generate a new RSA key (recommended 3072+ bits):
     ```sh
     openssl genrsa -out keys/private.pem 3072
     openssl rsa -in keys/private.pem -pubout -out keys/public.pem
     ```
  2. Add new key to JWKS and perform a canary rotation via `/api/v1/jwks/rotate` using an admin token.
  3. Verify new `kid` appears in JWKS, then prune old keys.

## Short-term (day 1-3)

- Replace all inline secrets in CI workflows with `secrets.` references (already applied to CI). Review all `.github/workflows/*` for other inline values.
- Run a full secret-scan (gitleaks) on the repository and in CI. Address findings.
- Remove any runtime logs or test-output files that contain real secrets (delete and add to `.gitignore`).

## Git history & purge

If any secrets were committed in prior history and that history exists on the remote, perform a history purge (requires coordination):

1. On a separate machine, mirror-clone the repo:
   ```sh
   git clone --mirror git@github.com:owner/repo.git repo.git
   cd repo.git
   ```
2. Use `git-filter-repo` to remove sensitive paths or patterns (example):
   ```sh
   git filter-repo --invert-paths --path license-server/keys/private.pem --path services/data/stripe_debug.log --path license-server/data/gdwb_db_credentials.txt
   ```
3. Expire reflogs and garbage collect:
   ```sh
   git reflog expire --expire=now --all
   git gc --prune=now --aggressive
   ```
4. Force-push the cleaned mirror:
   ```sh
   git push --force --mirror
   ```
5. Ask all collaborators to re-clone the repository.

See `SECURITY_REMOVE_AND_PURGE.md` for more details and example scripts.

## CI/CD hardening checklist

- Add `gitleaks` secret-scan as a required check on PRs (secret-scan workflow added).
- Ensure all secrets are stored in GitHub Secrets and not in code.
- Restrict token scopes (Stripe restricted keys, AWS least privilege) and rotate regularly.
- Use short-lived credentials when possible and OIDC where supported for cloud providers.

## Verification

- After each rotation, run the `secret-scan` workflow and verify no secrets are detected.
- Run integration smoke tests (license server, gateway) using new credentials.
- Monitor logs and alerts for authentication failures that indicate missed updates.

## Contacts / Emergency

- Stripe: rotate keys and contact support if keys were compromised.
- Postgres hosting: reset DB access and IP restrictions.
- AWS: rotate keys and rotate roles using IAM; revoke older access keys.

---
Add this file to the repo and treat it as the single-source checklist for rotations.
