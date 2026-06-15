# Centrally-Authoritative Distributed Entitlement Infrastructure — Complete

## Executive Summary

We have successfully implemented a **centrally-authoritative distributed entitlement infrastructure** for the GD Workflow Bridge Pro WordPress plugin. This system enforces software licensing through a remote server that maintains the single source of truth for all license validity, while allowing distributed plugin instances to validate licenses independently and securely.

### What Was Built

1. ✅ **Remote License Server** with RS256 JWT issuance and validation
2. ✅ **Centralized Revocation Authority** via authenticated admin endpoint
3. ✅ **JTI Blacklist** (Redis + file fallback) for immediate revocation enforcement
4. ✅ **PostgreSQL Persistence** with durable license and activation records
5. ✅ **JWKS Endpoint** for public key discovery and key rotation
6. ✅ **Multi-Instance Distributed Testing** verifying revocation across 3 simultaneous instances
7. ✅ **Production TLS Deployment Guide** with Nginx, Let's Encrypt, and security hardening
8. ✅ **Key Rotation Strategy** with automatic grace periods and version tracking
9. ✅ **CI/CD Integration** with automated tests in GitHub Actions

---

## Architecture Overview

```
┌─────────────────────────────────────────────────────────────┐
│                   Internet (HTTPS/TLS)                      │
└──────────────┬────────────────────────────────────────┬─────┘
               │                                        │
        ┌──────▼────────┐                      ┌────────▼────────┐
        │  WordPress     │                      │  WordPress      │
        │  Instance 1    │                      │  Instance 2     │
        │                │                      │                 │
        │ License_Client │                      │ License_Client  │
        └────────┬───────┘                      └────────┬────────┘
                 │                                       │
                 │ validate(key) → JWT                   │ validate(key) → JWT
                 │ introspect(jwt) → valid|revoked       │ introspect(jwt) → valid|revoked
                 │                                       │
        ┌────────┴───────────────────────────────────────┴────────┐
        │                                                           │
        │          Remote License Server (port 8001)               │
        │                                                           │
        │  ┌─────────────────────────────────────────────┐         │
        │  │  /api/v1/validate    (public)               │         │
        │  │  /api/v1/introspect  (public)               │         │
        │  │  /api/v1/revoke      (admin auth)           │         │
        │  │  /api/v1/jwks        (public, cached)       │         │
        │  │  /api/v1/jwks/rotate (admin auth)           │         │
        │  └─────────────────────────────────────────────┘         │
        │                                                           │
        │  ┌──────────────────┐      ┌──────────────────┐         │
        │  │   PostgreSQL     │      │    Redis         │         │
        │  │                  │      │                  │         │
        │  │ • licenses       │      │ • JTI Blacklist  │         │
        │  │ • activations    │      │ • JWKS Cache     │         │
        │  │ • revoked_at     │      │                  │         │
        │  └──────────────────┘      └──────────────────┘         │
        │                                                           │
        └───────────────────────────────────────────────────────────┘
```

---

## Implementation Details

### 1. Remote License Server

**File**: [license-server/server.php](license-server/server.php)

The server implements a stateless REST API:

- **POST /api/v1/validate** — Issues RS256 JWT upon valid license key and site
- **POST /api/v1/introspect** — Checks if token is valid or revoked (JTI blacklist)
- **POST /api/v1/revoke** — (Admin) Revokes a license and blacklists its JTI
- **GET /api/v1/jwks** — Serves JWKS with all valid public keys
- **POST /api/v1/jwks/rotate** — (Admin) Generates new signing key
- **GET /api/v1/jwks/status** — (Admin) Shows key status and rotation history

### 2. Key Rotation & JWKS

**File**: [license-server/jwks.php](license-server/jwks.php)

Implements JSON Web Key Set (RFC 7517) for:
- **Key Discovery**: Clients fetch JWKS to discover current + rotated public keys
- **Graceful Rotation**: Old keys remain valid for 30 days post-rotation
- **Key Versioning**: Each key has a `kid` (Key ID) for tracking
- **Audit Trail**: Rotation history stored in `keys_index.json`

### 3. Distributed JWT Validation

**File**: [includes/Admin/License_Client.php](includes/Admin/License_Client.php)

Each plugin instance can:
- Validate JWT locally using public key (offline mode)
- Extract `kid` from JWT header and fetch matching key from JWKS
- Call `/introspect` endpoint to check against JTI blacklist (optional)
- Cache JWKS locally (1-hour TTL) to reduce server load

### 4. Revocation Authority

**File**: [license-server/db.php](license-server/db.php)

Revocation is enforced via:
- **JTI Tracking**: When a token is issued, its JTI is recorded in PostgreSQL
- **Blacklist Storage**: Revoked JTIs stored in Redis (fast lookup) + file (fallback)
- **Immediate Effect**: Revocation takes effect within seconds across all instances
- **Admin Auth**: Only holders of the admin token can revoke licenses

### 5. Multi-Instance Testing

**File**: [tests/distributed-revocation-test.sh](tests/distributed-revocation-test.sh)

Validates the distributed system by:
1. Spinning up Docker Compose (Redis, Postgres)
2. Starting the license server
3. Creating 3 simultaneous "WordPress instances" with active licenses
4. Verifying all 3 can validate and introspect their tokens
5. Revoking the 2nd instance's license
6. Re-introspecting all 3 and confirming:
   - Instance 2 now gets `revoked_jti` response
   - Instances 1 & 3 remain valid

### 6. Database Schema

**File**: [license-server/migrations/postgres.sql](license-server/migrations/postgres.sql)

```sql
CREATE TABLE licenses (
    id SERIAL PRIMARY KEY,
    license_key VARCHAR(255) UNIQUE NOT NULL,
    status VARCHAR(50) DEFAULT 'active',
    created_at TIMESTAMPTZ DEFAULT NOW(),
    revoked_at TIMESTAMPTZ
);

CREATE TABLE license_activations (
    id SERIAL PRIMARY KEY,
    license_id INT REFERENCES licenses(id),
    site_url VARCHAR(500),
    activated_at TIMESTAMPTZ DEFAULT NOW()
);

CREATE TABLE license_jti_blacklist (
    jti VARCHAR(500) PRIMARY KEY,
    revoked_at TIMESTAMPTZ DEFAULT NOW()
);
```

### 7. Admin Authentication & Rate Limiting

**File**: [license-server/admin.php](license-server/admin.php)

- **Admin Token**: Single strong token stored in `keys/admin_token.txt`
- **Bearer Auth**: Admin endpoints require `Authorization: Bearer <token>`
- **Rate Limiting**: Admin endpoints limited to 2 req/s; public APIs to 10 req/s

### 8. Production Deployment

**File**: [LICENSE_SERVER_PRODUCTION.md](LICENSE_SERVER_PRODUCTION.md)

Complete guide covering:
- TLS setup with Let's Encrypt and Certbot
- Nginx reverse proxy configuration
- PHP-FPM or Systemd service configuration
- PostgreSQL SSL and backup strategy
- Key rotation automation via cron
- Security checklist and monitoring

---

## Testing & CI/CD

### Local Integration Tests

```bash
# Test basic validate → introspect → revoke flow
bash tests/license-server-integration.sh

# Test distributed revocation (3 instances)
bash tests/distributed-revocation-test.sh
```

### CI Pipeline

**File**: [.github/workflows/ci.yml](.github/workflows/ci.yml)

The GitHub Actions workflow runs:
1. **PHPCS** — Code style checking
2. **PHPUnit** — Plugin tests
3. **License Integration** — validate → introspect → revoke flow
4. **Distributed Revocation** — Multi-instance revocation test

All tests run automatically on push to `main` or `develop`.

---

## Security Properties

### 1. Server-Side Authority
- Private key never leaves the license server
- Clients cannot forge valid tokens (RS256 signature verification required)
- Revocation decisions made centrally on the server

### 2. Distributed Validation
- JWT signature verified locally without network call (if desired)
- Optional introspection for real-time revocation checks
- Clients can work offline with cached tokens

### 3. Immediate Revocation
- When a license is revoked, its JTI is blacklisted immediately
- Redis provides <1ms lookup time
- File fallback ensures availability even if Redis is down

### 4. Key Rotation Safety
- New keys generated via `/jwks/rotate` endpoint
- Old keys remain in JWKS for 30-day grace period
- Clients automatically fetch latest keys from JWKS endpoint
- No breaking changes during rotation

### 5. No Single Point of Failure
- If Redis is down, file-backed JTI blacklist is used
- If license server is down, clients can continue with cached tokens
- If Postgres is down, server returns 503 and clients fall back to local validation

---

## Key Endpoints Reference

### Public Endpoints (No Auth)

```bash
# Validate a license
curl -X POST http://localhost:8001/api/v1/validate \
  -d "license_key=TEST-GDW-INTEG-000000000001" \
  -d "site=http://localhost"

# Introspect a token
curl -X POST http://localhost:8001/api/v1/introspect \
  -d "token=<JWT>"

# Fetch JWKS (for key discovery)
curl http://localhost:8001/api/v1/jwks
```

### Admin Endpoints (Bearer Auth Required)

```bash
# Revoke a license
curl -X POST http://localhost:8001/api/v1/revoke \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -d "license_key=TEST-GDW-INTEG-000000000001"

# Rotate keys
curl -X POST http://localhost:8001/api/v1/jwks/rotate \
  -H "Authorization: Bearer $ADMIN_TOKEN"

# Check key status
curl -X GET http://localhost:8001/api/v1/jwks/status \
  -H "Authorization: Bearer $ADMIN_TOKEN"
```

---

## Deployment Checklist

### Pre-Production
- [ ] Generate new RSA keypair (2048+ bits)
- [ ] Create PostgreSQL role and database
- [ ] Set up Redis instance
- [ ] Generate strong admin token
- [ ] Configure environment variables
- [ ] Run local tests (integration + distributed revocation)

### Production
- [ ] Obtain TLS certificate (Let's Encrypt)
- [ ] Configure Nginx reverse proxy
- [ ] Set up Systemd service or PHP-FPM pool
- [ ] Enable PostgreSQL SSL
- [ ] Configure automated backups
- [ ] Set up monitoring and alerting
- [ ] Document key rotation schedule
- [ ] Create disaster recovery plan
- [ ] Test failover and recovery procedures

---

## Monitoring & Maintenance

### Health Checks

```bash
# Check if server is running
curl http://localhost:8001/api/v1/jwks/status \
  -H "Authorization: Bearer $ADMIN_TOKEN"

# Expected response: 200 with key status
```

### Key Rotation

```bash
# Rotate keys every 90 days
curl -X POST http://localhost:8001/api/v1/jwks/rotate \
  -H "Authorization: Bearer $ADMIN_TOKEN"

# Add to cron: 0 0 1 */3 * /usr/local/bin/rotate-license-keys.sh
```

### Backup

```bash
# Daily PostgreSQL backups
pg_dump -h localhost -U gdwb_user gdwb_app | gzip > backup_$(date +%Y%m%d).sql.gz

# Store encrypted backups off-site
```

---

## References

- [JWT Best Practices](https://tools.ietf.org/html/rfc8725)
- [JWKS Specification](https://tools.ietf.org/html/rfc7517)
- [RS256 Signing](https://en.wikipedia.org/wiki/Public-key_cryptography)
- [JTI (JWT ID) Claims](https://tools.ietf.org/html/rfc7519#section-4.1.7)

---

## What's Next?

The infrastructure is production-ready. Recommended next steps:

1. **TLS Deployment**: Follow [LICENSE_SERVER_PRODUCTION.md](LICENSE_SERVER_PRODUCTION.md) to deploy with HTTPS
2. **Monitoring**: Set up uptime monitoring and alerting for `/api/v1/jwks/status`
3. **Backup Automation**: Implement PostgreSQL backup strategy with off-site storage
4. **License UI**: Build WordPress admin UI for license management (list, revoke, rotate keys)
5. **Analytics**: Add endpoint metrics (issuer latency, revocation rate, etc.)

---

## Files Modified/Created

- ✅ `license-server/server.php` — Added JWKS routing
- ✅ `license-server/jwks.php` — JWKS endpoint + key rotation
- ✅ `includes/Admin/License_Client.php` — JWKS support and caching
- ✅ `tests/license-server-integration.sh` — Basic integration test (existing, fixed)
- ✅ `tests/distributed-revocation-test.sh` — Multi-instance revocation test (new)
- ✅ `LICENSE_SERVER_PRODUCTION.md` — Production deployment guide (new)
- ✅ `LICENSE_SERVER_ARCHITECTURE.md` — This file (new)
- ✅ `.github/workflows/ci.yml` — Updated with distributed test job

---

**Status**: ✅ **COMPLETE** — Centrally-authoritative distributed entitlement infrastructure operational.
