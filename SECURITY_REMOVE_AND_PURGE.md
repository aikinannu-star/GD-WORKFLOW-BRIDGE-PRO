SECURITY REMOVAL & GIT-HISTORY PURGE PLAN
=========================================

What I removed from the working tree
-----------------------------------
- `license-server/keys/private.pem` (private signing key)
- All `license-server/keys/private_kid_*.pem` (per-KID private keys)
- `license-server/keys/admin_secret.txt`
- `license-server/keys/admin_token.txt`
- `license-server/keys/clients.json` (may contain client secrets)
- `license-server/data/gdwb_db_credentials.txt` (database password file)
- `services/data/stripe_debug.log` (log containing a test Stripe key)

Important next steps (do these immediately)
-----------------------------------------
1. Rotate any credentials that were exposed (DB password, Stripe keys, admin tokens, AWS keys, etc.).
2. Revoke or rotate any JWT signing keys that were exposed. Replace signing keys and reissue necessary tokens.
3. Update CI / GitHub Secrets to use the new credentials and remove any hard-coded secrets from workflow files.

Git-history purge plan (recommended)
-----------------------------------
Warning: rewriting git history is destructive. Make backups and coordinate with collaborators.

Option A — Use BFG (easy, pattern-based):

1. Install BFG (https://rtyley.github.io/bfg-repo-cleaner/).
2. Create a bare mirror clone:

```bash
git clone --mirror <REPO_URL_OR_PATH> repo.git
cd repo.git
```

3. Run BFG to delete files by name/pattern (examples):

```bash
# delete files matching patterns
java -jar /path/to/bfg.jar --delete-files 'private*.pem' --delete-files 'gdwb_db_credentials.txt' --delete-files 'stripe_debug.log' repo.git

# BFG will suggest next steps; now run:
git reflog expire --expire=now --all
git gc --prune=now --aggressive

# force-push cleaned repo
git push --force
```

Option B — Use git-filter-repo (recommended if available):

1. Install git-filter-repo (https://github.com/newren/git-filter-repo).
2. Mirror clone and run filter commands to remove paths and blobs.

```bash
git clone --mirror <REPO_URL_OR_PATH> repo.git
cd repo.git

# remove specific files and patterns
git filter-repo --invert-paths --path license-server/data/gdwb_db_credentials.txt --path services/data/stripe_debug.log --path license-server/keys/private.pem --path-glob 'license-server/keys/private_kid_*.pem'

# cleanup and push
git reflog expire --expire=now --all
git gc --prune=now --aggressive
git push --force
```

If you need to remove specific sensitive strings (e.g. a hard-coded password in workflow files), use `--replace-text` with `git-filter-repo` or run a two-stage filter: remove blobs by pattern or run an explicit path edit for specific files.

Post-purge steps
----------------
- Inform all contributors to re-clone the repository (old clones contain the secret history).
- Rotate credentials again after the purge to be safe.
- Remove any copies of the secrets in CI logs or third-party integrations.
- Add or enforce repository policy: move secrets to GitHub Actions Secrets, Docker secrets, or a secret manager (AWS Secrets Manager, Vault).

Automated helper (optional): the `scripts/purge-secrets.sh` below is a template you can run locally after editing the variables.

```bash
#!/usr/bin/env bash
set -euo pipefail
REPO_URL="<REPO_URL_OR_PATH>"
TMP_DIR="/tmp/repo-purge-$(date +%s)"

git clone --mirror "${REPO_URL}" "${TMP_DIR}"
cd "${TMP_DIR}"

# Example using git-filter-repo (preferred)
# Ensure git-filter-repo is installed and on PATH
git filter-repo --invert-paths --path license-server/data/gdwb_db_credentials.txt --path services/data/stripe_debug.log --path license-server/keys/private.pem --path-glob 'license-server/keys/private_kid_*.pem'

git reflog expire --expire=now --all
git gc --prune=now --aggressive
git push --force

echo "Purge complete. Notify collaborators to re-clone the repository."
```

Notes & caveats
---------------
- This change removed files from the working tree only — to fully purge them from all historical commits, follow the git-history purge plan above.
- Keep a secure backup of any keys you legitimately need before purging; store them in a secret manager and ensure runtime mounts are used instead of keeping keys in the repo.
- After forced-pushing a cleaned history, update downstream CI, deployment pipelines, mirrors and any other clones.

If you want, I can:
- prepare the `scripts/purge-secrets.sh` file in the repo (editable) and
- generate a `git filter-repo` command tailored to the exact paths and patterns found.
