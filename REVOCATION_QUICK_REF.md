# JWT Revocation Quick Reference

## For Frontend Developers

### Handling 403 Revoked Token

```javascript
// When API returns 403, token is revoked
fetch('/api/v1/endpoint', {
  headers: { 'Authorization': `Bearer ${token}` }
})
.then(res => {
  if (res.status === 403) {
    // Token revoked - clear storage and redirect to login
    localStorage.removeItem('gdwb_user_token');
    window.location.href = '/login';
  }
  return res.json();
})
.catch(err => console.error(err));
```

### Detecting Token Expiry vs Revocation

```javascript
// 401 = expired or invalid signature
// 403 = revoked
// 200 = valid

const isTokenRevoked = (token) => {
  // Decode without verification (for local check)
  const payload = JSON.parse(atob(token.split('.')[1]));
  return payload.exp < Date.now() / 1000 ? 'expired' : 'unknown';
};
```

---

## For Backend Developers (Node.js/Express)

### Using the Revocation Middleware

```javascript
// Automatically enforced on all routes
const express = require('express');
const app = express();

// The API Gateway revocation middleware handles token checking
// No additional code needed - 403 is returned automatically for revoked tokens

app.get('/api/v1/data', (req, res) => {
  // If we get here, token is valid and not revoked
  const user = req.user; // populated by auth middleware
  res.json({ data: 'protected' });
});
```

### Checking Revocation Manually

```javascript
// If needed, manually check revocation status
const redis = require('redis');
const client = redis.createClient({ socket: { host: '127.0.0.1', port: 6379 } });

async function checkRevoked(jti) {
  await client.connect();
  const userRevoked = await client.exists(`jti:${jti}`);
  const adminRevoked = await client.exists(`admin_jti:${jti}`);
  await client.quit();
  return userRevoked === 1 || adminRevoked === 1;
}

// Usage
const isRevoked = await checkRevoked(payload.jti);
if (isRevoked) {
  return res.status(403).json({ error: 'Token revoked' });
}
```

---

## For Backend Developers (PHP)

### Checking Revocation

```php
<?php
require_once 'license-server/redis.php';

// Check if JTI is revoked
$jti = 'c23820bb24c579c9914fc2c4a61c99cd';
$is_revoked = redis_blacklist_check($jti);

if ($is_revoked) {
    http_response_code(403);
    echo json_encode(['error' => 'Token revoked']);
    exit;
}

// Safe to proceed
echo json_encode(['data' => 'protected']);
?>
```

### Revoking a Token

```php
<?php
require_once 'license-server/redis.php';

// Revoke a token (admin operation)
$jti = $_POST['jti'] ?? '';
$ttl = (int)($_POST['ttl_seconds'] ?? 3600);

if (redis_blacklist_add($jti, $ttl)) {
    echo json_encode(['success' => true, 'message' => 'Token revoked']);
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to revoke token']);
}
?>
```

---

## API Cheat Sheet

### Revoke a User Token (Admin)

```bash
curl -X POST http://localhost:8001/api/v1/revoke \
  -H "Authorization: Bearer <admin_token>" \
  -H "Content-Type: application/json" \
  -d '{"jti": "c23820bb24c579c9914fc2c4a61c99cd", "ttl_seconds": 3600}'

# Response: 200 OK
# {"success": true, "message": "Token revoked", "jti": "c23820bb24c579c9914fc2c4a61c99cd"}
```

### Introspect a Token

```bash
curl -X POST http://localhost:8001/api/v1/introspect \
  -H "Content-Type: application/json" \
  -d '{"token": "eyJhbGciOiJSUzI1NiI..."}'

# Response (if valid): 200 OK
# {"success": true, "active": true, "jti": "...", "exp": 1782458094}

# Response (if revoked): 403 Forbidden
# {"error": "revoked"}
```

### Revoke via Gateway (Admin)

```bash
curl -X POST http://localhost:3000/api/v1/admin/revoke \
  -H "Authorization: Bearer <admin_token>" \
  -H "Content-Type: application/json" \
  -d '{"jti": "c23820bb24c579c9914fc2c4a61c99cd"}'

# Response: 200 OK
# {"success": true, "message": "Token revoked", "jti": "c23820bb24c579c9914fc2c4a61c99cd"}
```

### Check Health

```bash
curl http://localhost:3000/health
# {"status": "ok", "service": "api-gateway", "port": 3000}

curl http://localhost:8001/health
# (no endpoint on license-server, check introspection instead)
```

---

## Environment Variables

### License Server

```bash
# Redis (optional)
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASS=""
REDIS_DB=0

# Or use LICENSE_REDIS_* variants
LICENSE_REDIS_HOST=127.0.0.1
LICENSE_REDIS_PORT=6379
LICENSE_REDIS_PASS=""

# Database
LICENSE_DB_HOST=127.0.0.1
LICENSE_DB_PORT=5432
LICENSE_DB_USER=gdwb_user
LICENSE_DB_PASS=<password>
LICENSE_DB_NAME=gdwb_app
```

### API Gateway

```bash
# Redis (same as license server)
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASS=""

# License Server URL
LICENSE_SERVER_URL=http://localhost:8001

# Security
JWT_SECRET=<strong-secret>
JWT_EXPIRY=5m
REFRESH_TOKEN_EXPIRY_DAYS=7
COOKIE_SECURE=false  # true in production
COOKIE_SAMESITE=none

# CORS
CORS_ORIGIN=http://localhost:3001

# Port
PORT=3000

# Optional
NODE_ENV=development
```

---

## Testing

### Unit Test Example (Node.js)

```javascript
const assert = require('assert');
const { createClient } = require('redis');

describe('Revocation', () => {
  let redisClient;

  before(async () => {
    redisClient = createClient({ socket: { host: '127.0.0.1', port: 6379 } });
    await redisClient.connect();
  });

  after(async () => {
    await redisClient.quit();
  });

  it('should revoke a token', async () => {
    const jti = 'test-jti-123';
    
    // Revoke
    await redisClient.set(`jti:${jti}`, '1', { EX: 3600 });
    
    // Check
    const exists = await redisClient.exists(`jti:${jti}`);
    assert.equal(exists, 1, 'JTI should be revoked');
  });

  it('should expire revoked tokens', async (done) => {
    const jti = 'test-jti-temp';
    
    // Revoke with 1 second TTL
    await redisClient.set(`jti:${jti}`, '1', { EX: 1 });
    
    // Check immediately
    let exists = await redisClient.exists(`jti:${jti}`);
    assert.equal(exists, 1, 'JTI should be revoked');
    
    // Wait 1.5 seconds
    setTimeout(async () => {
      exists = await redisClient.exists(`jti:${jti}`);
      assert.equal(exists, 0, 'JTI should be expired');
      done();
    }, 1500);
  });
});
```

### Integration Test Example (PHP)

```php
<?php
class RevocationTest extends PHPUnit_Framework_TestCase {
    
    public function testRevokeToken() {
        require_once 'license-server/redis.php';
        
        $jti = 'test-jti-' . time();
        $result = redis_blacklist_add($jti, 3600);
        $this->assertTrue($result);
        
        $is_revoked = redis_blacklist_check($jti);
        $this->assertTrue($is_revoked);
    }
    
    public function testExpiredRevocation() {
        require_once 'license-server/redis.php';
        
        $jti = 'test-jti-expire-' . time();
        redis_blacklist_add($jti, 1); // 1 second TTL
        
        $this->assertTrue(redis_blacklist_check($jti));
        sleep(2);
        $this->assertFalse(redis_blacklist_check($jti)); // Should be expired
    }
}
?>
```

---

## Common Patterns

### Pattern 1: Logout User (Revoke All Tokens)

```javascript
// Frontend
async function logout() {
  const token = localStorage.getItem('gdwb_user_token');
  const payload = JSON.parse(atob(token.split('.')[1]));
  
  // Call backend to revoke
  await fetch('/api/v1/admin/revoke', {
    method: 'POST',
    headers: {
      'Authorization': `Bearer ${adminToken}`,
      'Content-Type': 'application/json'
    },
    body: JSON.stringify({ jti: payload.jti })
  });
  
  // Clear local storage
  localStorage.removeItem('gdwb_user_token');
  window.location.href = '/login';
}
```

### Pattern 2: Session Management (Revoke Old Tokens)

```php
<?php
// When user logs in from a new device, revoke previous tokens
function revokeUserOldTokens($user_id, $keep_recent = 1) {
    global $db;
    
    // Get user's token JTIs
    $result = $db->query(
        "SELECT jti FROM tokens WHERE user_id = ? ORDER BY created_at DESC LIMIT 999 OFFSET ?",
        [$user_id, $keep_recent]
    );
    
    require_once 'redis.php';
    foreach ($result as $row) {
        redis_blacklist_add($row['jti'], 86400); // Revoke for 24 hours
    }
}
?>
```

### Pattern 3: Time-based Auto-Logout

```javascript
// Frontend
const TOKEN_LIFETIME = 5 * 60 * 1000; // 5 minutes
let logoutTimer;

function setAutoLogout() {
  clearTimeout(logoutTimer);
  logoutTimer = setTimeout(() => {
    logout();
  }, TOKEN_LIFETIME);
}

// On page load and after each API call
document.addEventListener('load', setAutoLogout);
app.use((req, res, next) => {
  res.on('finish', setAutoLogout);
  next();
});
```

---

## Debugging

### Check Redis Connection

```bash
# From Node.js
node -e "
const { createClient } = require('redis');
(async () => {
  const client = createClient({ socket: { host: '127.0.0.1', port: 6379 } });
  client.on('error', e => console.error('Error:', e.message));
  await client.connect();
  const pong = await client.ping();
  console.log('Redis:', pong);
  await client.quit();
})();
"

# From PHP
php -r "
require 'license-server/redis.php';
\$r = redis_connect();
echo \$r ? 'Connected' : 'Failed';
"
```

### Check Blacklist Content

```bash
# View Redis keys
redis-cli KEYS "jti:*" | head -20
redis-cli GET "jti:c23820bb24c579c9914fc2c4a61c99cd"

# View file blacklist
cat license-server/data/jti_blacklist.json | jq '.' | head -20
```

### Monitor Pub/Sub

```bash
# In one terminal, subscribe
redis-cli SUBSCRIBE jti_revocations

# In another, publish a test message
redis-cli PUBLISH jti_revocations '{"jti":"test-123","prefix":"jti"}'
```

---

## Performance Tips

1. **Cache JTI checks locally** (in-memory map) for fast repeated access
2. **Use Redis pub/sub** instead of polling for large-scale deployments
3. **Set reasonable TTLs** (default 3600s) to prevent unbounded growth
4. **Prune expired entries** weekly from file blacklist
5. **Monitor Redis memory** — may need to increase `maxmemory` or add replicas

---

## Troubleshooting Checklist

- [ ] Redis running and reachable?
- [ ] Correct REDIS_HOST/PORT/PASS set?
- [ ] JTI present in Redis or file blacklist?
- [ ] Token signature valid?
- [ ] Admin token valid and not expired?
- [ ] CORS properly configured?
- [ ] Gateway middleware enabled?
- [ ] Logs checked for errors?

