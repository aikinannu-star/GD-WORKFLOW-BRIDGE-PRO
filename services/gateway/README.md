Gateway Preflight Authorization Cache
===================================

Purpose
-------
This document describes the short-lived preflight authorization cache implemented in the API Gateway. The gateway consults the CMS `POST /api/v1/cms/authorize` endpoint for write-sensitive requests and will optionally cache decisions in Redis to reduce latency and backend load.

Behavior
--------
- The gateway performs a preflight `POST /api/v1/cms/authorize` for mapped CMS write endpoints (chat send, vault uploads, project access changes, timeline comments, form submissions).
- When a user ID is available, the gateway builds a cache key of the form:

  gateway:cms:auth:sha1(u:<user_id>:p:<project_id>:a:<action>)

- The gateway attempts to connect to Redis (host/port from `GATEWAY_REDIS_HOST` / `GATEWAY_REDIS_PORT`). If Redis is available it will read the key before calling the CMS and write a short-lived result after a miss. The cached value is `1` for allowed, `0` for denied.
- The cache TTL is configurable via `GATEWAY_AUTH_CACHE_TTL` (seconds). Default: 10.
- The gateway sets the response header `X-Gateway-Auth-Cache: hit` or `miss` to help with diagnostics.

Security & Correctness Notes
----------------------------
- Decisions are only cached when a `X-User-Id` (or internal user id from token introspection) is available. Anonymous or API-key flows always perform a live preflight.
- Cache entries are short-lived and safe to invalidate by graph mutation hooks in the CMS. For production deployments we recommend using a clustered Redis (or Redis Sentinel/Managed Redis) so multiple gateway instances share the same cache.
- To disable caching, either remove Redis connectivity or set `GATEWAY_AUTH_CACHE_TTL=0`.

Environment
-----------
- `GATEWAY_REDIS_HOST` (default: `redis` in compose)
- `GATEWAY_REDIS_PORT` (default: `6379`)
- `GATEWAY_AUTH_CACHE_TTL` (default: `10` seconds)

Diagnostic Headers
------------------
- `X-Gateway-Auth-Cache: hit|miss` — whether the gateway returned an auth decision from cache or after a live preflight.

Example `.env` snippet
----------------------
GATEWAY_REDIS_HOST=redis
GATEWAY_REDIS_PORT=6379
GATEWAY_AUTH_CACHE_TTL=10

Invalidation subscriber
-----------------------
There's a tiny Redis subscriber script included for logging and metrics when the CMS publishes invalidation messages:

services/gateway/redis_invalidate_subscriber.php

Run it on the gateway host (or in a sidecar) with PHP available and the Redis extension installed:

```bash
php services/gateway/redis_invalidate_subscriber.php
```

It will append structured JSON lines to `services/data/gateway_invalidation.log` and print concise lines to stdout for observability.

Metrics emitted
---------------
The subscriber increments two metrics via the existing `Metrics` helper (and Pushgateway when enabled):

- `gateway_invalidation_total` — total invalidation events received
- `gateway_invalidation_action_<action>` — per-action counts (e.g. `gateway_invalidation_action_upload`)

These are pushed to the configured Pushgateway if `PUSHGATEWAY_ENABLED=true` and `PUSHGATEWAY_URL` is set.

Supervisor and health endpoint
-----------------------------
You can run a supervisor that ensures the subscriber remains running and exposes a JSON health file plus a simple HTTP health endpoint:

- Supervisor script: `services/gateway/redis_invalidate_supervisor.php` — monitors and restarts the subscriber and writes the health file.
- HTTP health endpoint: `services/gateway/invalidation_supervisor_health.php` — reads the supervisor health file and returns JSON.

Run the supervisor (foreground):

```bash
php services/gateway/redis_invalidate_supervisor.php
```

Serve the health endpoint from any PHP-capable webserver that can reach `services/data` (or use the gateway to proxy it if preferred). Example: run a quick built-in server for local testing:

```bash
php -S 0.0.0.0:9001 -t . services/gateway/invalidation_supervisor_health.php
```

Optional env vars:

- `GATEWAY_SUPERVISOR_RESTART_DELAY` — seconds to wait between restart attempts (default 3)
- `GATEWAY_SUPERVISOR_CHECK_INTERVAL` — seconds between heartbeat writes when running (default 5)


Where to look in the code
-------------------------
- The gateway preflight implementation is in `services/gateway/server.php`.

Questions / Next Steps
---------------------
- I can add an integration test that exercises the gateway preflight + cache (requires a test Redis instance).
- I can also add explicit cache invalidation notifications from the CMS when graph mutations occur (webhook or pub/sub).
