# Remote Docker Deployment (no local Docker required)

This project includes a helper script to deploy the current repository to a remote host and run Docker Compose there, so you can avoid installing Docker locally.

Prerequisites on remote host

- SSH access (user@host)
- Docker installed (Engine v20+)
- Docker Compose (v2 plugin included with Docker) or `docker-compose` v1
- Sufficient permissions to run Docker (user in `docker` group or use sudo)

Local prerequisites

- `ssh`, `scp`, and `tar` (standard on macOS/Linux). On Windows, use `WSL` or Git Bash / PowerShell with OpenSSH.

Usage

1. Deploy and run (copies a tarball of the repo to the remote host and runs Compose):

```bash
./scripts/remote-deploy.sh -h myhost.example.com -u ubuntu -d /home/ubuntu/gd-workflow-bridge-pro
```

2. Options

- `-h host` (required) — remote SSH host
- `-u user` — SSH user (default: current user)
- `-P port` — SSH port (default: 22)
- `-d remote_dir` — remote directory to extract to (default: `~/gd-workflow-bridge-pro`)
- `-s` — skip `scp` (if the archive is already present on the remote)
- `-k` — keep remote tarball after extraction
- `-c` — do not clean local archive after deploy

Notes and recommendations

- For automated CI/CD, prefer building container images in CI and pushing to a registry, then running `docker compose pull` on the remote host.
- Secure the remote host: prefer SSH key auth and restrict which accounts can run Docker.
- If you need to run the deployment behind a bastion host, use `-o ProxyJump=...` via `~/.ssh/config` and `ssh` options.

Example with `docker context` (alternative, requires Docker installed locally):

```bash
# create an SSH-backed docker context
docker context create remote --docker "host=ssh://ubuntu@myhost.example.com"
# use the context
docker --context remote compose up -d --build
```
