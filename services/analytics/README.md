# Analytics Service (MVP)

This stub service provides lightweight event ingestion and summary reporting. It is designed to be a scaffold for a standalone analytics module in the GD Platform.

Start locally:

```powershell
php -S 0.0.0.0:8018 -t services/analytics services/analytics/server.php
```

Endpoints:

- `GET /health` — health check
- `POST /api/v1/analytics/events` — ingest an analytics event
- `GET /api/v1/analytics/summary` — get a simple event summary

Notes:
- Uses file-backed JSON storage under `services/data/analytics/` via `ServiceHelpers`.
- This service can be exposed via the API gateway using route prefix `/api/v1/analytics`.
