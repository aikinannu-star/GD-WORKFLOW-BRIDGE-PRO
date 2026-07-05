# Mobile App Builder Service (MVP)

This stub service provides lightweight mobile app project scaffolding and release metadata. It is intended as a scaffold for a full mobile builder module on the GD Platform.

Start locally:

```powershell
php -S 0.0.0.0:8014 -t services/mobile-builder services/mobile-builder/server.php
```

Endpoints:

- `GET /health` — health check
- `GET /api/v1/mobile-builder/apps` — list mobile apps
- `POST /api/v1/mobile-builder/apps` — create a mobile app
- `GET /api/v1/mobile-builder/apps/:id` — get a mobile app
- `POST /api/v1/mobile-builder/apps/:id/release` — mark a mobile app as released

Notes:
- Uses file-backed JSON storage under `services/data/mobile-builder/` via `ServiceHelpers`.
- This service can be exposed via the API gateway using route prefix `/api/v1/mobile-builder`.
