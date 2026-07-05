# Production JWT Secret Management

## Security Policy

### Development Environment ✅
- Secret: `dev_jwt_secret` (configured via `AUTH_JWT_SECRET` in local `.env`)
- Use: Local development and CI certification only
- Risk: Low - isolated to development environment

### Production Environment ⚠️ CRITICAL
- **NEVER** use hardcoded secrets in production
- Secret must come from environment configuration
- Methods (in order of preference):
  1. **Kubernetes Secrets** (if using K8s)
  2. **Cloud Secret Manager** (AWS Secrets Manager, Google Secret Manager, Azure Key Vault)
  3. **Docker Secrets** (if using Docker Swarm)
  4. **Environment Variables** (least preferred, but acceptable with proper access controls)

## Implementation Checklist

### Code Level
- [ ] Verify `services/auth/server.php` reads `AUTH_JWT_SECRET` from `$_ENV`
  ```php
  define('JWT_SECRET', $_ENV['AUTH_JWT_SECRET'] ?? null);
  if (!JWT_SECRET) {
    throw new RuntimeException('AUTH_JWT_SECRET not set');
  }
  ```

### Docker Deployment
- [ ] Ensure `docker-compose.prod.yml` does NOT contain secret values
- [ ] Use Docker secrets or environment variable injection
- [ ] Example:
  ```yaml
  # ❌ WRONG
  environment:
    AUTH_JWT_SECRET: "my-secret"
  
  # ✅ CORRECT
  environment:
    AUTH_JWT_SECRET: /run/secrets/auth_jwt_secret
  # or use Docker secrets:
  secrets:
    - auth_jwt_secret
  ```

### CI/CD Integration
- [ ] Store `AUTH_JWT_SECRET` in GitHub Secrets (for GitHub Actions)
- [ ] Reference in workflow:
  ```yaml
  - name: Deploy
    env:
      AUTH_JWT_SECRET: ${{ secrets.AUTH_JWT_SECRET }}
  ```

### Secret Rotation
- [ ] Document rotation procedure
- [ ] Plan for key rolling (old key still valid for grace period)
- [ ] Test rotation in staging before production
- [ ] Notify consumers when secrets change

### Audit & Compliance
- [ ] Log all authentication failures (without logging secrets)
- [ ] Audit secret access in cloud provider
- [ ] Regular security reviews of secret management
- [ ] Document secret lifecycle

## Verification Steps

Before deploying to production, verify:

```bash
# 1. Check that code reads from environment
grep "AUTH_JWT_SECRET" services/auth/server.php

# 2. Verify no hardcoded secrets in production config
grep -r "AUTH_JWT_SECRET:" docker-compose.prod.yml || echo "✓ No hardcoded secret values in compose file"

# 3. Test environment variable injection
export AUTH_JWT_SECRET="test-secret-value"
php -r "require 'services/auth/server.php'; echo 'Secret loaded: ' . (defined('JWT_SECRET') ? 'YES' : 'NO');"

# 4. Check Docker image doesn't contain secrets
docker history <image> | grep -i secret || echo "✓ No secrets in image history"
```

## Documentation for Consumers

When publishing SDKs, include this authentication note:

> **Note**: The JWT secret used locally is supplied through `AUTH_JWT_SECRET` for development only. Production deployments use a secure secret injected at runtime. Consumers should never hardcode the JWT secret in their applications—always authenticate via the login endpoint (`POST /api/v1/auth/login`) to obtain tokens.

---

**Status**: ✅ Development secret isolation confirmed  
**Action**: Ensure production configs updated before Go-Live  
**Responsibility**: DevOps / SRE team during deployment phase
