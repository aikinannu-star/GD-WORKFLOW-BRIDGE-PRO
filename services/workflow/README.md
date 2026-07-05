# Workflow Automation Service (MVP)

This stub service provides lightweight workflow definitions and execution simulation. It is designed to be a scaffold for a future workflow automation engine in the GD Platform.

Start locally:

```powershell
php -S 0.0.0.0:8016 -t services/workflow services/workflow/server.php
```

Endpoints:

- `GET /health` — health check
- `GET /api/v1/workflow/flows` — list workflows
- `POST /api/v1/workflow/flows` — create a workflow
- `POST /api/v1/workflow/flows/:id/execute` — execute a workflow (simulated)

Notes:
- Uses file-backed JSON storage under `services/data/workflow/` via `ServiceHelpers`.
- This service can be exposed via the API gateway using route prefix `/api/v1/workflow`.
