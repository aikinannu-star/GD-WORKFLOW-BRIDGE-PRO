#!/usr/bin/env bash
set -euo pipefail

# remote-deploy.sh
# Deploy the current repository to a remote host and run `docker compose up -d --build` there.
# Requirements:
#  - Local: ssh, scp, tar (or git)
#  - Remote: docker (and docker compose plugin or docker-compose)
# Usage:
#  ./scripts/remote-deploy.sh -h host [-u user] [-P port] [-d remote_dir] [-s]
# Example:
#  ./scripts/remote-deploy.sh -h myhost.example.com -u ubuntu -d /home/ubuntu/gd-workflow-bridge-pro

usage() {
  cat <<USAGE
Usage: $0 -h host [-u user] [-P port] [-d remote_dir] [-s]

  -h host       Remote SSH host (required)
  -u user       SSH user (default: current user)
  -P port       SSH port (default: 22)
  -d remote_dir Remote directory to extract the repo into (default: ~/gd-workflow-bridge-pro)
  -s            Skip scp (assume repo already present on remote)
  -k            Keep remote tarball after extraction
  -c            Clean local archive after deploy
USAGE
}

SSH_HOST=""
SSH_USER="$(whoami)"
SSH_PORT=22
REMOTE_DIR="~/gd-workflow-bridge-pro"
SKIP_SCP=false
KEEP_REMOTE_TAR=false
CLEAN_LOCAL=true

while getopts ":h:u:P:d:skc" opt; do
  case $opt in
    h) SSH_HOST="$OPTARG" ;;
    u) SSH_USER="$OPTARG" ;;
    P) SSH_PORT="$OPTARG" ;;
    d) REMOTE_DIR="$OPTARG" ;;
    s) SKIP_SCP=true ;;
    k) KEEP_REMOTE_TAR=true ;;
    c) CLEAN_LOCAL=false ;;
    \?) echo "Invalid option: -$OPTARG" >&2; usage; exit 2 ;;
    :) echo "Option -$OPTARG requires an argument." >&2; usage; exit 2 ;;
  esac
done

if [[ -z "$SSH_HOST" ]]; then
  echo "Remote host is required (-h)." >&2
  usage
  exit 2
fi

# ensure ssh connectivity
if ! ssh -p "$SSH_PORT" -o BatchMode=yes -o ConnectTimeout=5 "$SSH_USER@$SSH_HOST" 'echo 2>&1' && true >/dev/null 2>&1; then
  echo "Warning: SSH to $SSH_USER@$SSH_HOST may require interactive auth. Continuing..." >&2
fi

TIMESTAMP=$(date +%s)
ARCHIVE="/tmp/gdwb-deploy-${TIMESTAMP}.tar.gz"

# Create a tarball of the repository (exclude .git to keep archive small when possible)
if command -v git >/dev/null 2>&1 && [ -d .git ]; then
  echo "Creating archive from git HEAD..."
  git archive --format=tar HEAD | gzip > "$ARCHIVE"
else
  echo "Creating archive from working tree (excluding .git)..."
  tar --exclude='./.git' -czf "$ARCHIVE" .
fi

REMOTE_TMP="$ARCHIVE"

if [ "$SKIP_SCP" = false ]; then
  echo "Copying archive to $SSH_USER@$SSH_HOST:/tmp/"
  scp -P "$SSH_PORT" "$ARCHIVE" "$SSH_USER@$SSH_HOST:/tmp/" || { echo "scp failed"; exit 3; }
  REMOTE_TMP="/tmp/$(basename "$ARCHIVE")"
else
  echo "Skipping scp; assuming archive already present on remote as $REMOTE_TMP"
fi

# Run remote extraction and docker compose
ssh -p "$SSH_PORT" "$SSH_USER@$SSH_HOST" bash -lc "\
  set -euo pipefail; \
  mkdir -p $REMOTE_DIR; \
  tar -xzf $REMOTE_TMP -C $REMOTE_DIR; \
  cd $REMOTE_DIR; \
  # prefer 'docker compose' (v2) then fallback to 'docker-compose' (v1)
  if command -v docker >/dev/null 2>&1; then \
    if docker compose version >/dev/null 2>&1; then \
      docker compose pull || true; \
      docker compose up -d --build --remove-orphans; \
    elif command -v docker-compose >/dev/null 2>&1; then \
      docker-compose pull || true; \
      docker-compose up -d --build --remove-orphans; \
    else \
      echo 'docker-compose not found'; exit 4; \
    fi; \
  else \
    echo 'docker not found on remote host'; exit 5; \
  fi"

if [ "$KEEP_REMOTE_TAR" = false ] && [ "$SKIP_SCP" = false ]; then
  echo "Removing remote archive"
  ssh -p "$SSH_PORT" "$SSH_USER@$SSH_HOST" "rm -f $REMOTE_TMP" || true
fi

if [ "$CLEAN_LOCAL" = true ]; then
  echo "Removing local archive $ARCHIVE"
  rm -f "$ARCHIVE" || true
fi

echo "Deploy complete. Remote path: $REMOTE_DIR"

# Host github.com
    HostName github.com
    User git
    IdentityFile ~/.ssh/id_ed25519_gdwb
    IdentitiesOnly yes
