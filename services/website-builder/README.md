# Website Builder Service (MVP)

This stub service provides lightweight website project creation and publish workflows. It is intended to be a scaffold for a full website builder module integrated into the GD Platform.

Start locally:

```powershell
php -S 0.0.0.0:8013 -t services/website-builder services/website-builder/server.php
```

Endpoints:

- `GET /health` — health check
- `GET /api/v1/website-builder/projects` — list website projects
- `POST /api/v1/website-builder/projects` — create a website project
- `GET /api/v1/website-builder/projects/:id` — get a website project
- `POST /api/v1/website-builder/projects/:id/publish` — publish a website project

Notes:
- Uses file-backed JSON storage under `services/data/website-builder/` via `ServiceHelpers`.
- This service can be exposed via the API gateway using route prefix `/api/v1/website-builder`.
