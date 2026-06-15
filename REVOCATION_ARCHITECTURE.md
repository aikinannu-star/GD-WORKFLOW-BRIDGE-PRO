# JWT Token Revocation Architecture

## Overview

This document describes the distributed JWT token revocation system for the GD Workflow Bridge Pro license server. The system provides immediate token revocation enforcement across multiple components with Redis pub/sub for fast propagation and file-backed fallback for high availability.

## System Components

### 1. License Server (PHP)
- **Purpose**: Issues and validates JWT tokens, manages JTI blacklist
- **Role in Revocation**: Maintains revoked JTI list, publishes revocation events
- **Key Files**: 
  - `license-server/server.php` — token endpoints and revocation handlers
  - `license-server/redis.php` — Redis helper and file fallback
  - `license-server/admin.php` — admin token revocation logic
  - `license-server/data/jti_blacklist.json` — file-backed persistent blacklist

### 2. API Gateway (Node.js/Express)
- **Purpose**: Unified request routing and auth enforcement
- **Role in Revocation**: Fast-path revocation check via Redis cache, rejects revoked Bearer tokens
- **Key Files**:
  - `GodemarsEmpire2/server/api-gateway.js` — revocation middleware, Redis subscription
  - `POST /api/v1/admin/revoke` — admin revoke endpoint

### 3. Redis (Optional)
- **Purpose**: High-performance distributed cache and pub/sub
- **Role in Revocation**: Fast JTI lookup, pub/sub for real-time revocation propagation
- **Fallback**: File-backed JSON when Redis unavailable
- **Channels**:
  - `jti_revocations` — publish revocation events (JSON payload)
- **Keys**:
  - `jti:<jti>` — user token revocation (TTL)
  - `admin_jti:<jti>` — admin token revocation (TTL)

### 4. Database (PostgreSQL)
- **Purpose**: Persistent storage of licenses and users
- **Role in Revocation**: Introspection queries for audit trail (optional)

---

## Revocation Flow

### 1. User Token Revocation (via License Server)

```
Frontend/Admin UI
    ↓
POST /api/v1/revoke
(Bearer: admin_token, body: {jti, ttl_seconds})
    ↓
License Server (server.php)
    ├─ Verify admin token in request
    ├─ Call redis_blacklist_add(jti, ttl)
    │  ├─ Add to Redis: SET jti:<jti> 1 EX <ttl>
    │  ├─ Publish to Redis channel jti_revocations
    │  │  Payload: {"jti":"...", "prefix":"jti", "expires_at":<epoch>}
    │  └─ Add to file fallback: data/jti_blacklist.json
    ├─ Return 200 OK
    └─ (Optional) Log audit event
```

### 2. Admin Token Revocation (via License Server)

```
Frontend/Admin UI
    ↓
POST /api/v1/admin/token/revoke
(Bearer: admin_token, body: {token_id})
    ↓
License Server (admin.php)
    ├─ Verify admin token in request
    ├─ Extract JTI from target token
    ├─ Call redis_publish_revocation(jti, ttl, 'admin_jti')
    │  └─ Publish to Redis channel jti_revocations
    │     Payload: {"jti":"...", "prefix":"admin_jti", "expires_at":<epoch>}
    ├─ Add to Redis: SET admin_jti:<jti> 1 EX <ttl>
    ├─ Add to file fallback
    └─ Return 200 OK
```

### 3. Admin Revoke via Gateway

```
Frontend/Admin UI
    ↓
POST /api/v1/admin/revoke
(Bearer: admin_token, body: {jti})
    ↓
API Gateway (api-gateway.js)
    ├─ Extract admin token from Bearer
    ├─ Forward to License Server: POST /api/v1/admin/token/revoke
    ├─ Write to local Redis (if available):
    │  ├─ SET jti:<jti> 1 EX <ttl>
    │  └─ SET admin_jti:<jti> 1 EX <ttl>
    └─ Return response from License Server
```

### 4. Token Introspection (Fast Path)

```
Frontend / Internal Service
    ↓
GET /health or any endpoint with Bearer token
(Authorization: Bearer <token>)
    ↓
API Gateway (Revocation Middleware)
    ├─ Extract Bearer token
    ├─ Parse JWT payload to get JTI
    ├─ Call isJtiRevoked(jti)
    │  ├─ Fast check: Look up in revokedCache (Map)
    │  ├─ If not in cache, check Redis (200ms timeout)
    │  │  ├─ EXISTS jti:<jti>?
    │  │  └─ EXISTS admin_jti:<jti>?
    │  └─ Return revocation status
    ├─ If revoked: Return 403 Forbidden
    └─ Otherwise: Continue to handler
```

### 5. Token Introspection (Fallback Path)

```
If Redis unavailable:
    ↓
License Server (server.php)
    ├─ On POST /api/v1/introspect request
    ├─ Extract token JTI
    ├─ Call redis_blacklist_check(jti)
    │  └─ Consult file: data/jti_blacklist.json
    ├─ If revoked in file: Return 403 Forbidden
    └─ Otherwise: Return 200 with token info
```

---

## Data Structures

### Redis Keys (TTL-based Expiration)

```php
// User token revocation
SET jti:<jti> 1 EX 3600
// Lookup: EXISTS jti:<jti>

// Admin token revocation
SET admin_jti:<jti> 1 EX 3600
// Lookup: EXISTS admin_jti:<jti>
```

### Redis Pub/Sub Channel

**Channel**: `jti_revocations`

**Message Payload** (JSON):
```json
{
  "jti": "c23820bb24c579c9914fc2c4a61c99cd",
  "prefix": "jti",
  "expires_at": 1782458094
}
```

### File Fallback: jti_blacklist.json

```json
{
  "c23820bb24c579c9914fc2c4a61c99cd": 1782458094,
  "other_jti_here": 1782400000
}
```

**Format**: `{ "jti": <epoch_expiry_seconds>, ... }`

**Location**: `license-server/data/jti_blacklist.json`

---

## Revocation Enforcement

### Where Revocation is Enforced

| Component | Method | Speed | Fallback |
|-----------|--------|-------|----------|
| **API Gateway** | In-memory cache + Redis query | <200ms | File via license-server |
| **License Server** | File-backed JSON | ~10ms (file I/O) | N/A (always available) |
| **Introspection Endpoint** | Redis or file | Varies | File |

### Revocation Enforcement Logic

1. **Gateway Middleware** (first line of defense):
   - Checks `revokedCache` (in-memory Map)
   - If not in cache, queries Redis with 200ms timeout
   - If Redis unavailable, fails **open** (allows request)
   - On revocation detected: Returns **403 Forbidden**

2. **License Server Introspection** (backup):
   - Consults Redis first (fast path)
   - Falls back to file if Redis unavailable
   - Returns **403 Forbidden** if revoked
   - Includes expiry check; expires old entries from file

---

## Configuration

### Environment Variables

#### License Server
```bash
# Redis connection (optional)
REDIS_HOST=127.0.0.1              # Redis hostname
REDIS_PORT=6379                    # Redis port
REDIS_PASS=""                      # Redis password (optional)
REDIS_DB=0                         # Redis database number (optional)

# Or use alternate names
LICENSE_REDIS_HOST=127.0.0.1
LICENSE_REDIS_PORT=6379
LICENSE_REDIS_PASS=""
```

#### API Gateway
```bash
# Redis connection (optional)
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASS=""

# Or use alternate names
LICENSE_REDIS_HOST=127.0.0.1
LICENSE_REDIS_PORT=6379
LICENSE_REDIS_PASS=""
```

### Redis Availability Handling

- **If Redis is available**: Fast revocation via pub/sub and key lookups
- **If Redis is unavailable**: Automatic fallback to file-backed JSON
  - Gateway's `isJtiRevoked()` returns false (fails open)
  - License-server introspection uses file fallback
  - No data loss; revocations persist in `jti_blacklist.json`

---

## API Endpoints

### Revoke User Token

**Endpoint**: `POST /api/v1/revoke`

**Authentication**: Bearer `<admin_token>`

**Request**:
```json
{
  "jti": "c23820bb24c579c9914fc2c4a61c99cd",
  "ttl_seconds": 3600
}
```

**Response (Success)**:
```json
{
  "success": true,
  "message": "Token revoked",
  "jti": "c23820bb24c579c9914fc2c4a61c99cd"
}
```

**Response (Revoked)**:
```
HTTP 403 Forbidden
```

---

### Revoke Admin Token

**Endpoint**: `POST /api/v1/admin/token/revoke`

**Authentication**: Bearer `<admin_token>`

**Request**:
```json
{
  "token_id": "some-token-id"
}
```

**Response (Success)**:
```json
{
  "success": true,
  "message": "Admin token revoked"
}
```

---

### Introspect Token

**Endpoint**: `POST /api/v1/introspect`

**Request**:
```json
{
  "token": "eyJhbGciOiJSUzI1NiI..."
}
```

**Response (Valid)**:
```json
{
  "success": true,
  "active": true,
  "jti": "c23820bb24c579c9914fc2c4a61c99cd",
  "iss": "gdwb-license-server",
  "aud": "gd-workflow-bridge-pro",
  "iat": 1779866094,
  "exp": 1782458094
}
```

**Response (Revoked)**:
```
HTTP 403 Forbidden
{"error": "revoked"}
```

---

## Admin Revoke via Gateway

**Endpoint**: `POST /api/v1/admin/revoke`

**Authentication**: Bearer `<admin_token>`

**Request**:
```json
{
  "jti": "c23820bb24c579c9914fc2c4a61c99cd"
}
```

**Behavior**:
- Gateway writes keys to local Redis (if available)
- Forwards to license-server `/api/v1/admin/token/revoke`
- License-server publishes to `jti_revocations` channel
- All subscribers receive and cache the revocation

**Response (Success)**:
```json
{
  "success": true,
  "message": "Token revoked",
  "jti": "c23820bb24c579c9914fc2c4a61c99cd"
}
```

---

## Deployment & Operations

### Development Setup

```bash
# Start license server with Redis
cd gd-workflow-bridge-pro/license-server
export REDIS_HOST=127.0.0.1
export REDIS_PORT=6379
php -S 127.0.0.1:8001

# Start API gateway
cd GodemarsEmpire2/server
export REDIS_HOST=127.0.0.1
export REDIS_PORT=6379
node api-gateway.js

# Start Redis (Docker)
docker run -d --name gdwb-redis -p 6379:6379 redis:7-alpine
```

### Production Setup

1. **Deploy Redis** (managed service recommended):
   - Amazon ElastiCache / Azure Cache / self-hosted
   - Use strong AUTH password
   - Enable persistence (RDB or AOF)
   - Configure replication/clustering as needed

2. **Set Environment Variables** (via `.env` or deployment config):
   ```bash
   REDIS_HOST=redis-prod.example.com
   REDIS_PORT=6379
   REDIS_PASS=<strong-password>
   ```

3. **Verify Revocation**:
   ```bash
   # Test endpoint
   curl -X POST http://localhost:8001/api/v1/introspect \
     -H "Content-Type: application/json" \
     -d '{"token": "...jwt..."}'
   ```

4. **Monitor**:
   - Redis memory usage and connection pool
   - `jti_revocations` channel publish/subscribe lag
   - File `data/jti_blacklist.json` size (prune expired entries periodically)

### High Availability Considerations

- **Redis Replication**: Use primary/replica for failover
- **File Fallback**: Shared network filesystem for license-server instances
- **TTL Management**: Set reasonable TTLs (default 3600s) to prevent unbounded growth
- **Prune Expired Entries**: Periodically clean `jti_blacklist.json`
  ```bash
  php license-server/scripts/prune-jti-blacklist.php
  ```

---

## Testing

### Local Smoke Test

```bash
cd gd-workflow-bridge-pro
php license-server/tests/smoke_revoke.php
```

**Expected Output**:
```
Issuing license token for TEST-...
Introspecting token (pre-revoke)
Pre-revoke introspect OK
Revoking license via license-server
Revoke accepted
Introspecting token (post-revoke)
Post-revoke introspect returned 403 — treating as revoked.
```

### Integration Test

```bash
cd GodemarsEmpire2/server
node test_integration.js
```

### CI/CD Workflows

Both repositories include GitHub Actions workflows for automated testing:

- **gd-workflow-bridge-pro**: `.github/workflows/revocation-smoke.yml`
- **GodemarsEmpire2**: `.github/workflows/revocation-smoke.yml`

**Workflow Steps**:
1. Spin up PostgreSQL + Redis services
2. Start license-server
3. Start API gateway
4. Run revocation smoke tests
5. Verify 403 responses for revoked tokens

---

## Troubleshooting

### Revocation Not Enforced

**Symptom**: Token still accepted after revocation

**Causes**:
1. **Redis not running**: Check Redis connection
   ```bash
   redis-cli ping
   ```
2. **JTI not in blacklist**: Verify revocation was called
   ```bash
   cat license-server/data/jti_blacklist.json | grep <jti>
   ```
3. **Gateway middleware bypass**: Ensure Bearer token is in `Authorization` header
4. **Old token in cache**: Restart gateway to clear in-memory cache

**Resolution**:
- Verify Redis connectivity and pub/sub channel
- Manually add JTI to `jti_blacklist.json` for immediate effect
- Restart gateway to reset cache

### Redis Connection Failures

**Symptom**: `redis_connect failed` in logs

**Causes**:
1. Redis service not running
2. Wrong host/port/password
3. Network connectivity issue

**Resolution**:
```bash
# Test Redis connection
redis-cli -h <HOST> -p <PORT> -a <PASSWORD> ping

# Expected: PONG
```

### File Blacklist Growing Too Large

**Symptom**: `jti_blacklist.json` file size increasing

**Causes**:
1. TTLs set too high
2. No prune operation running

**Resolution**:
```bash
# Manual prune
php -r "require 'license-server/redis.php'; redis_prune_blacklist();"

# Or add to cron job
0 2 * * * php /opt/gdwb/license-server/scripts/prune-jti-blacklist.php
```

---

## Performance Characteristics

| Operation | Latency | Method |
|-----------|---------|--------|
| **Revocation Check (gateway)** | <200ms | In-memory cache + Redis query |
| **Revocation Check (license-server)** | ~10ms | File I/O |
| **Publish to Redis** | <1ms | Redis pub/sub |
| **File-backed add** | ~5-10ms | JSON write |

**Scaling Notes**:
- Redis can handle 100k+ revocation checks/sec
- File-backed fallback suitable for <1k revocations
- Use Redis in production for high-volume systems

---

## Code References

### Key Functions

#### License Server (redis.php)
- `redis_connect()` — establish Redis connection
- `redis_blacklist_add($jti, $ttl)` — add JTI to blacklist and publish
- `redis_blacklist_check($jti)` — check if JTI is revoked
- `redis_publish_revocation($jti, $ttl, $prefix)` — publish to channel

#### API Gateway (api-gateway.js)
- `isJtiRevoked(jti)` — check local cache and Redis
- `parseJwtPayload(token)` — extract JTI from JWT
- Middleware at line ~170 — enforce revocation on incoming requests

---

## Future Enhancements

- [ ] Cluster mode for Redis failover
- [ ] WebSocket real-time revocation UI updates
- [ ] Webhook notifications on revocation
- [ ] Revocation reason/audit trail
- [ ] Time-based token expiry without explicit revocation
- [ ] Bulk revocation operations
- [ ] Revocation event streaming (Kafka/etc)

