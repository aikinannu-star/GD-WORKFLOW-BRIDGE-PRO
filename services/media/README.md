# Media Service (MVP)

Prototype media service for storing uploaded files as base64 in JSON for rapid prototyping.

Start (standalone):

```powershell
php -S 0.0.0.0:8010 -t services/media services/media/server.php
```

Endpoints:

- `GET /health` — health check
- `POST /api/v1/media/upload` — upload file (JSON: `filename`, `content` (base64)); requires `X-User-Id` or `user_id` and `X-Tenant-Id` or `tenant_id`.
- `GET /api/v1/media/:id` — retrieve media metadata and base64 content

Notes:

- This is a prototype. For production replace storage with object store (S3/MinIO) and store only metadata in DB.
- The service stores data under `services/data/media_files.json`.
- When integrating with the gateway, use the `media` route added to `services/gateway/server.php`.
