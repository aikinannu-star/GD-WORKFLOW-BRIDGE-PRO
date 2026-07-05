# Deployment Service (MVP)

This stub service provides lightweight deployment orchestration metadata and scheduling. It is designed as a scaffold for a deployment services module in the GD Platform.

Start locally:

```powershell
php -S 0.0.0.0:8019 -t services/deployment services/deployment/server.php
```

Endpoints:

- `GET /health` — health check
- `GET /api/v1/deployment/services` — list deployment targets and status
- `POST /api/v1/deployment/deploy` — schedule a deployment

Notes:
- Uses file-backed JSON storage under `services/data/deployment/` via `ServiceHelpers`.
- This service can be exposed via the API gateway using route prefix `/api/v1/deployment`.
