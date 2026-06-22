VPS Deployment (DigitalOcean) — Quick Guide

This document collects the minimal steps and helper scripts to provision a Droplet, configure DNS, deploy the license server, and verify the system.

Prerequisites
- `doctl` (optional) or DigitalOcean API token (`DO_API_TOKEN`)
- `ssh` and `scp` access
- Docker + docker-compose on the Droplet (the `scripts/bootstrap-droplet.sh` prepares this)
- GitHub repository accessible from the droplet (public or via deploy key)

Quick sequence

1. Bootstrap the droplet (run as root):

```bash
# on the droplet as root
bash /path/to/gd-workflow-bridge-pro/scripts/bootstrap-droplet.sh
```

2. Create the Droplet (on your workstation):

```bash
# using doctl (recommended)
doctl compute droplet create gdwb-1 --region nyc1 --size s-1vcpu-2gb --image ubuntu-22-04-x64 --ssh-keys <KEY_IDS> --tag-names gdwb --wait

# or using provided script (requires DO_API_TOKEN and SSH_KEY_IDS)
./scripts/create-droplet.sh gdwb-1 nyc1 s-1vcpu-2gb "12345,67890"
```

3. Configure DNS (point hosts to the droplet IP):

```bash
DO_API_TOKEN=... ./scripts/configure-dns.sh example.com 203.0.113.10 www license
```

4. Deploy the plugin artifact and license-server

- Use the GitHub Actions deploy workflow by pushing a tag (`v1.0.0`) or publishing a release. The workflow will upload the ZIP to the droplet and run `docker compose`.
- Alternatively, manually copy the ZIP to the droplet and run the deploy script:

```bash
scp release/gd-workflow-bridge-pro.zip deploy@DROPLET_IP:/tmp/
ssh deploy@DROPLET_IP 'bash -s' < ./scripts/deploy-license-remote.sh deploy@DROPLET_IP
```

5. Verify license-server

```bash
./scripts/verify-license-endpoints.sh https://license.example.com
```

6. Update WordPress plugin to point at the license server

```bash
./scripts/update-wp-endpoint.sh deploy@DROPLET_IP https://license.example.com 22
```

7. Test license activation

```bash
./scripts/test-license-activation.sh https://license.example.com TEST-12345678901234567890
```

Notes & Security
- Keep `LICENSE_PRIVATE_KEY_PATH` and the private key outside the repository. Use `docker secrets` or mounted volumes with restricted permissions.
- Add the droplet SSH fingerprint to GitHub Secrets as `DROPLET_SSH_FINGERPRINT` in `SHA256:...` form (the workflow compares SHA256 fingerprints).

Add the droplet SSH fingerprint to GitHub Secrets as `DROPLET_SSH_FINGERPRINT` in `SHA256:...` form (the workflow compares SHA256 fingerprints). You can get the fingerprint locally with:

```bash
ssh-keyscan -t rsa -p 22 your.droplet.ip | ssh-keygen -lf - -E sha256
```

- Consider using a managed database (DigitalOcean Managed DB) for Postgres in production.
