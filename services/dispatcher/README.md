# Task Dispatcher Service (MVP)

This service accepts high-level engineering tasks and forwards them to specialized tools (assistant, workflow, review, etc.).

Start locally:

```powershell
php -S 0.0.0.0:8020 -t services/dispatcher services/dispatcher/server.php
```

Endpoints:

- `GET /health` — health check
- `POST /api/v1/dispatcher/task` — dispatch a task; JSON body: `{ "type": "generate_workflow|scaffold_service|review_code", "payload": { ... } }`

Notes:

- This is an orchestration stub — real routing, queuing, retries, and RBAC should be added when integrating.
- Use environment variables to override upstream targets: `DISPATCHER_WORKFLOW_URL`, `DISPATCHER_SERVICE_URL`, `DISPATCHER_REVIEW_URL`.

Platform contract:

- See [PLATFORM_ARCHITECTURE.md](PLATFORM_ARCHITECTURE.md) for the dispatcher's extension model, plugin lifecycle, permission model, capability model, and versioning policy.
