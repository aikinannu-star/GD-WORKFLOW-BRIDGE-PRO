Gateway Smoke Tests
===================

This folder contains a tiny smoke test script (`gateway_smoke.sh`) to validate the API Gateway locally.

Prerequisites
- PHP 8.2+ CLI available on PATH
- `curl` installed
- `bash` (Git Bash, WSL, or macOS/Linux shell)
- Optional: `jq` for nicer JSON parsing

Start services (from repo root)

PowerShell (Windows):
```
powershell -NoProfile -ExecutionPolicy Bypass -File .\run-php-services.ps1
```

Bash (Git Bash / WSL / Linux):
```
./run-php-services.sh
```

Run the smoke tests
```
bash ./tests/gateway_smoke.sh
```

If your gateway is listening on a different host/port, set `BASE`:
```
BASE=http://127.0.0.1:8000 bash ./tests/gateway_smoke.sh
```

Quota integration test
```
php ./tests/quota_integration.php
```
This script registers a test user for `ci-tenant` and performs requests until the per-tenant quota (configured in `services/data/tenant_quotas.json`) is enforced.

Tear down

Kill PHP built-in servers started by the runners:

Bash:
```
pkill -f "php -S" || true
```

PowerShell (example):
```
Get-Process php | Stop-Process -Force
```

Notes
- The CI workflow `.github/workflows/gateway-ci.yml` demonstrates how the smoke tests run on `ubuntu-latest`.
- The tests check `/health`, license OpenAPI discovery, auth-protected endpoints, and aggregate health.
