# Feed Service (MVP)

Lightweight feed composer for prototyping timelines. Reads `social` posts and `follows` data from `services/data/` and composes a chronological feed for a user.

Start (standalone):

```powershell
php -S 0.0.0.0:8011 -t services/feed services/feed/server.php
```

Endpoints:

- `GET /health` — health check
- `GET /api/v1/feed?user_id=...&tenant_id=...&page=1&limit=20` — returns items (reads `social/posts.json` and `social/follows.json`)

Caching:
- Tries Redis (host via `GATEWAY_REDIS_HOST`/`GATEWAY_REDIS_PORT`) then falls back to file-cache `services/data/feed_cache.json`.
- TTL controlled via `FEED_CACHE_TTL_SEC` env var (default 10s).

Notes:
- For production move feed logic to a dedicated store and stream updates to Redis or a materialized timeline store.
