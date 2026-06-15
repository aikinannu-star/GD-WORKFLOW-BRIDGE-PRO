# Social Microservice (MVP)

This is a minimal, file-backed social microservice intended as a safe MVP addition to the GD Workflow backend. It is intentionally lightweight and tenant-aware and uses the existing `ServiceHelpers` storage under `services/data/`.

Start (standalone):

```powershell
php -S 0.0.0.0:8008 -t services/social services/social/server.php
```

Key endpoints (tenant-aware via `X-Tenant-Id` header forwarded by the gateway):

- `GET /health` — health check
- `POST /api/v1/social/posts` — create post (requires `X-User-Id` header)
- `GET /api/v1/social/posts` — list posts (query: `tenant_id`, `page`, `limit`)
- `GET /api/v1/social/posts/:id` — get post with comments
- `POST /api/v1/social/posts/:id/comments` — add comment
- `POST /api/v1/social/posts/:id/like` — like/unlike post (toggle)
- `POST /api/v1/social/users/:id/follow` — follow/unfollow (`action`: `follow`|`unfollow`)
- `GET /api/v1/social/users/:id/followers` — list followers
- `GET /api/v1/social/users/:id/following` — list followees

Data files are stored under `services/data/` with prefixes: `social_posts.json`, `social_comments.json`, `social_likes.json`, `social_follows.json`.

Notes and next steps:
- This MVP uses file-backed JSON storage for speed of prototyping. For production, replace with a DB (MySQL/Postgres) and object storage for media.
- The gateway must include an entry for `social` (added to `services/gateway/server.php`).
- The service trusts headers forwarded by the gateway (`X-User-Id`, `X-Tenant-Id`). Do not expose this service directly without the gateway in production.
