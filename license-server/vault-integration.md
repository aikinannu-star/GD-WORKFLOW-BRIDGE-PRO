# Vault integration & rotation

This document describes how to use HashiCorp Vault to inject secrets into the license server and how to rotate client secrets.

Loading secrets at startup
--------------------------

- The server includes a helper `license-server/vault.php` which will be auto-loaded when present. It looks for the environment variables:
  - `VAULT_ADDR` (e.g. `https://vault.example.com`)
  - `VAULT_TOKEN` (Vault token with permissions to read/write the configured path)
  - `VAULT_SECRET_PATH` (use a KV v2 path such as `secret/data/gdwb`)

- KV v2 returns data under `data.data`; the helper maps keys returned by Vault directly to environment variables in the PHP process (it will not overwrite existing env vars unless empty).

Rotation tools
--------------

1) PowerShell helper

- `scripts/rotate-client.ps1` runs `php license-server/generate_client.php <client_id>` to create a new secret and writes it to Vault KV v2 as `CLIENT_<ID>_SECRET` (normalized to uppercase with non-alphanum replaced by `_`).
- Example (PowerShell):

```powershell
$env:VAULT_ADDR = 'https://vault.example.com'
$env:VAULT_TOKEN = '<token>'
powershell -File scripts/rotate-client.ps1 -ClientId dev-client
```

2) Ansible playbook

- `ansible/rotate-secrets.yml` runs the same generation step and writes the secret using Ansible's `uri` module. Example:

```bash
ansible-playbook ansible/rotate-secrets.yml -e client_id=dev-client
```

Operational notes
-----------------

- Both tools assume KV v2 semantics and POST to the API path you provide. If your Vault mount differs, update `VAULT_SECRET_PATH` accordingly.
- After rotation, update consuming services to use the new secret and optionally call the server's revocation endpoint to invalidate tokens issued with the old secret.
