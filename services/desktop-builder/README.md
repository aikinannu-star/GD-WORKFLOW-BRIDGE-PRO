# Desktop App Builder Service (MVP)

This stub service provides lightweight desktop app project scaffolding and packaging metadata. It is intended as a scaffold for a full desktop builder module on the GD Platform.

Start locally:

```powershell
php -S 0.0.0.0:8015 -t services/desktop-builder services/desktop-builder/server.php
```

Endpoints:

- `GET /health` — health check
- `GET /api/v1/desktop-builder/apps` — list desktop apps
- `POST /api/v1/desktop-builder/apps` — create a desktop app
- `GET /api/v1/desktop-builder/apps/:id` — get a desktop app
- `POST /api/v1/desktop-builder/apps/:id/package` — package a desktop app

Notes:
- Uses file-backed JSON storage under `services/data/desktop-builder/` via `ServiceHelpers`.
- This service can be exposed via the API gateway using route prefix `/api/v1/desktop-builder`.
