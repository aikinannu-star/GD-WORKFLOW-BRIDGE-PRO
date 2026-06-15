# JWT Revocation Operations Guide

## Quick Start

### Development Environment

**1. Start Redis**
```bash
docker run -d --name gdwb-redis -p 6379:6379 redis:7-alpine
```

**2. Start License Server**
```bash
cd gd-workflow-bridge-pro/license-server
export REDIS_HOST=127.0.0.1
export REDIS_PORT=6379
php -S 127.0.0.1:8001
```

**3. Start API Gateway**
```bash
cd GodemarsEmpire2/server
export REDIS_HOST=127.0.0.1
export REDIS_PORT=6379
node api-gateway.js
```

**4. Run Smoke Test**
```bash
cd gd-workflow-bridge-pro
php license-server/tests/smoke_revoke.php
```

---

## Production Deployment

### Prerequisites

- PostgreSQL 13+ (for license storage)
- Redis 6.0+ (for revocation cache)
- PHP 8.1+ (with redis extension)
- Node.js 18+ (for API gateway)

### Step 1: Deploy Redis

**Option A: AWS ElastiCache**
```bash
# Create ElastiCache cluster
aws elasticache create-replication-group \
  --replication-group-description "GDWB License Revocation" \
  --engine redis \
  --engine-version 7.0 \
  --cache-node-type cache.r6g.xlarge \
  --num-cache-clusters 2 \
  --automatic-failover-enabled \
  --auth-token <strong-token>
```

**Option B: Self-hosted (Docker Swarm/K8s)**
```yaml
# docker-compose.yml (production)
version: '3.8'
services:
  redis:
    image: redis:7-alpine
    command: redis-server --requirepass <REDIS_PASS>
    ports:
      - "6379:6379"
    volumes:
      - redis_data:/data
    restart: always
volumes:
  redis_data:
```

**Option C: Managed Services**
- Azure Cache for Redis
- Google Cloud Memorystore
- Digital Ocean Managed Redis
- Upstash Redis (serverless)

**Connection Verification**
```bash
redis-cli -h <HOST> -p <PORT> -a <PASSWORD> ping
# Expected: PONG
```

### Step 2: Configure License Server

**Create `/etc/gdwb/license-server.env`**
```bash
# Redis configuration
REDIS_HOST=redis-prod.example.com
REDIS_PORT=6379
REDIS_PASS=<strong-password>
REDIS_DB=0

# Database configuration
LICENSE_DB_HOST=postgres.example.com
LICENSE_DB_PORT=5432
LICENSE_DB_USER=gdwb_user
LICENSE_DB_PASS=<strong-password>
LICENSE_DB_NAME=gdwb_app

# Server configuration
LICENSE_SERVER_HOST=0.0.0.0
LICENSE_SERVER_PORT=8001
LICENSE_SERVER_URL=https://license.example.com
```

**Start via systemd**
```bash
sudo cp deploy/license-server.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable gdwb-license-server
sudo systemctl start gdwb-license-server
```

**Or Docker**
```bash
docker build -t gdwb-license-server -f license-server/Dockerfile .
docker run -d --name license-server \
  -p 8001:8001 \
  -e REDIS_HOST=redis-prod.example.com \
  -e REDIS_PORT=6379 \
  -e REDIS_PASS=<password> \
  gdwb-license-server
```

### Step 3: Configure API Gateway

**Create `.env` file** (or environment variables)
```bash
NODE_ENV=production
PORT=3000

# License server
LICENSE_SERVER_URL=https://license.example.com

# Redis (same credentials)
REDIS_HOST=redis-prod.example.com
REDIS_PORT=6379
REDIS_PASS=<strong-password>

# CORS
CORS_ORIGIN=https://app.example.com

# JWT configuration
JWT_SECRET=<strong-random-secret>
JWT_EXPIRY=5m
REFRESH_TOKEN_EXPIRY_DAYS=7

# Security
COOKIE_SECURE=true
COOKIE_SAMESITE=strict
NODE_ENV=production
```

**Start via PM2**
```bash
npm install -g pm2
pm2 start GodemarsEmpire2/server/api-gateway.js \
  --name "api-gateway" \
  --env production
pm2 save
pm2 startup
```

**Or Docker**
```bash
docker run -d --name api-gateway \
  -p 3000:3000 \
  -e REDIS_HOST=redis-prod.example.com \
  -e REDIS_PORT=6379 \
  -e REDIS_PASS=<password> \
  -e NODE_ENV=production \
  -e JWT_SECRET=<secret> \
  api-gateway:latest
```

### Step 4: Configure Reverse Proxy (Nginx)

```nginx
upstream license_server {
  server license-1.internal:8001;
  server license-2.internal:8001;
}

upstream api_gateway {
  server gateway-1.internal:3000;
  server gateway-2.internal:3000;
}

server {
  listen 443 ssl http2;
  server_name license.example.com;
  
  ssl_certificate /etc/letsencrypt/live/license.example.com/fullchain.pem;
  ssl_certificate_key /etc/letsencrypt/live/license.example.com/privkey.pem;
  ssl_protocols TLSv1.2 TLSv1.3;
  ssl_ciphers HIGH:!aNULL:!MD5;
  
  # License Server
  location / {
    proxy_pass http://license_server;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_connect_timeout 10s;
    proxy_read_timeout 30s;
  }
}

server {
  listen 443 ssl http2;
  server_name api.example.com;
  
  ssl_certificate /etc/letsencrypt/live/api.example.com/fullchain.pem;
  ssl_certificate_key /etc/letsencrypt/live/api.example.com/privkey.pem;
  ssl_protocols TLSv1.2 TLSv1.3;
  
  # API Gateway
  location / {
    proxy_pass http://api_gateway;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_read_timeout 60s;
  }
}
```

---

## Monitoring & Observability

### Metrics to Monitor

**Redis Health**
```bash
# Check connection and memory
redis-cli INFO stats
redis-cli INFO memory

# Monitor pub/sub subscribers
redis-cli PUBSUB CHANNELS
redis-cli PUBSUB NUMSUB jti_revocations
```

**License Server Health**
```bash
# Check JTI blacklist file size
du -h license-server/data/jti_blacklist.json

# Check logs for revocation errors
tail -f /var/log/php-fpm/license-server.log | grep revok
```

**API Gateway Health**
```bash
# Check revocation middleware errors
curl http://localhost:3000/health

# Monitor Redis connection errors
pm2 logs api-gateway | grep redis
```

### Prometheus Metrics (Optional)

**License Server** (add to server.php)
```php
// Metrics endpoint: GET /metrics
$revocations_count = count(json_decode(file_get_contents(__DIR__ . '/data/jti_blacklist.json'), true) ?: []);
echo "# HELP jti_blacklist_size Number of revoked JTIs in file\n";
echo "# TYPE jti_blacklist_size gauge\n";
echo "jti_blacklist_size $revocations_count\n";
```

**API Gateway** (add to api-gateway.js)
```javascript
// Middleware to track revocation checks
app.use((req, res, next) => {
  if (req.path.startsWith('/api/')) {
    metrics.revocation_checks++;
    res.on('finish', () => {
      if (res.statusCode === 403) metrics.revocation_denials++;
    });
  }
  next();
});

app.get('/metrics', (req, res) => {
  res.set('Content-Type', 'text/plain');
  res.send(`# HELP revocation_checks Total revocation checks
# TYPE revocation_checks counter
revocation_checks ${metrics.revocation_checks}

# HELP revocation_denials Total revocation denials
# TYPE revocation_denials counter
revocation_denials ${metrics.revocation_denials}
`);
});
```

### Logging Configuration

**PHP (license-server)**
```ini
; php.ini
error_log = /var/log/php-fpm/license-server.log
log_errors = On
error_reporting = E_ALL
```

**Node.js (api-gateway)**
```javascript
// Use winston or pino for structured logging
const pino = require('pino');
const logger = pino({
  transport: {
    target: 'pino/file',
    options: { destination: '/var/log/api-gateway.log' }
  }
});

logger.info({ jti, revoked: true }, 'Token revoked');
```

### Alert Rules

**Redis Down**
```yaml
alert: RedisDown
expr: redis_up == 0
for: 1m
annotations:
  summary: "Redis is down"
```

**High JTI Blacklist Growth**
```yaml
alert: BlacklistGrowth
expr: rate(jti_blacklist_size[1h]) > 100
for: 10m
annotations:
  summary: "JTI blacklist growing too fast"
```

**Revocation Denials Spike**
```yaml
alert: RevocationDenialSpike
expr: rate(revocation_denials[5m]) > 10
for: 5m
annotations:
  summary: "Unusual token revocation denials"
```

---

## Maintenance Tasks

### Daily Tasks

**1. Check Redis Memory Usage**
```bash
redis-cli INFO memory | grep used_memory_human
# Alert if > 80% of max memory
```

**2. Verify License Server Logs**
```bash
tail -100 /var/log/php-fpm/license-server.log | grep -i error
```

**3. Monitor File Blacklist Size**
```bash
du -h license-server/data/jti_blacklist.json
# Alert if > 10MB
```

### Weekly Tasks

**1. Prune Expired JTIs**
```bash
php license-server/scripts/prune-jti-blacklist.php
# Or via cron: 0 2 * * 0 (Sunday 2 AM)
```

**2. Backup Redis**
```bash
redis-cli BGSAVE
# Copy dump.rdb to backup storage
```

**3. Review Revocation Audit Log**
```bash
mysql -u root gdwb_app -e "
  SELECT created_at, reason, jti_count
  FROM revocation_audits
  ORDER BY created_at DESC
  LIMIT 20;
"
```

### Monthly Tasks

**1. Rotate Admin Token**
```bash
php license-server/generate_admin_token.php > keys/admin_token.txt
# Update in secrets manager
```

**2. Review and Rotate Redis Password**
```bash
redis-cli --eval script.lua
# Update in environment variables
```

**3. Check Certificate Expiry**
```bash
echo | openssl s_client -servername license.example.com -connect license.example.com:443 2>/dev/null | openssl x509 -noout -dates
```

---

## Troubleshooting

### Symptom: Revoked Tokens Still Accepted

**Diagnosis**
```bash
# 1. Check Redis connectivity
redis-cli -h <HOST> -p <PORT> PING

# 2. Verify JTI in Redis
redis-cli KEYS "jti:*"
redis-cli GET "jti:<specific-jti>"

# 3. Check file fallback
grep "<jti>" license-server/data/jti_blacklist.json

# 4. Check gateway logs
pm2 logs api-gateway | grep -i revok
```

**Resolution**
```bash
# Option 1: Manually revoke via file
php -r "require 'license-server/redis.php'; redis_blacklist_add('<jti>', 3600);"

# Option 2: Restart gateway to clear cache
systemctl restart api-gateway

# Option 3: Flush Redis and restart
redis-cli FLUSHDB
```

### Symptom: Redis Connection Failures

**Diagnosis**
```bash
# Test connection
redis-cli -h <HOST> -p <PORT> -a <PASSWORD> ping

# Check firewall
nc -zv <HOST> <PORT>

# Check DNS
nslookup <HOST>
```

**Resolution**
```bash
# Update environment variables
export REDIS_HOST=<new-host>
export REDIS_PORT=<new-port>
export REDIS_PASS=<new-pass>

# Restart services
systemctl restart gdwb-license-server
systemctl restart api-gateway
```

### Symptom: File Blacklist Growing Too Large

**Diagnosis**
```bash
# Check file size
ls -lh license-server/data/jti_blacklist.json

# Check number of entries
php -r "echo count(json_decode(file_get_contents('license-server/data/jti_blacklist.json'), true));"
```

**Resolution**
```bash
# Prune expired entries
php license-server/scripts/prune-jti-blacklist.php

# Schedule automatic pruning
echo "0 2 * * * php /opt/gdwb/license-server/scripts/prune-jti-blacklist.php" | crontab -
```

### Symptom: High Latency on Revocation Checks

**Diagnosis**
```bash
# Monitor Redis latency
redis-cli --latency

# Check gateway logs for Redis timeouts
pm2 logs api-gateway | grep timeout

# Check Redis memory pressure
redis-cli INFO memory
```

**Resolution**
```bash
# 1. Scale Redis (add replicas)
# 2. Increase gateway Redis timeout (if temporary)
export REDIS_TIMEOUT=500

# 3. Upgrade Redis instance type
aws elasticache modify-cache-cluster \
  --cache-cluster-id <id> \
  --cache-node-type cache.r6g.2xlarge \
  --apply-immediately
```

---

## Performance Tuning

### Redis Configuration

```redis.conf
# Memory management
maxmemory 2gb
maxmemory-policy allkeys-lru

# Persistence (for high-volume systems)
save 900 1
save 300 10
save 60 10000

# Pub/sub tuning
client-output-buffer-limit pubsub 32mb 8mb 60

# Connection pooling
timeout 300
tcp-keepalive 60
```

### License Server Tuning

```php
// Increase file I/O buffer
ini_set('memory_limit', '512M');

// Connection pooling to Redis
$redis_pool_size = 10;

// Cache TTL defaults
define('DEFAULT_JTI_TTL', 3600); // 1 hour
```

### API Gateway Tuning

```javascript
// Worker processes
const cluster = require('cluster');
const cpuCount = require('os').cpus().length;

// Connection pooling
redisClient.on('ready', () => {
  redisClient.client('SETNAME', 'api-gateway');
  redisClient.config('SET', 'maxclients', 10000);
});

// Rate limiting (optional)
const rateLimit = require('express-rate-limit');
app.use(rateLimit({
  windowMs: 15 * 60 * 1000,
  max: 100
}));
```

---

## Disaster Recovery

### Backup Strategy

**Daily Backups**
```bash
# Backup Redis
redis-cli BGSAVE
s3 cp /var/lib/redis/dump.rdb s3://backups/redis/$(date +%Y%m%d).rdb

# Backup JTI blacklist
aws s3 cp license-server/data/jti_blacklist.json \
  s3://backups/jti/$(date +%Y%m%d).json
```

### Recovery Procedure

**1. Restore Redis from Backup**
```bash
# Stop Redis
systemctl stop redis-server

# Restore dump.rdb
aws s3 cp s3://backups/redis/20250101.rdb /var/lib/redis/dump.rdb
chown redis:redis /var/lib/redis/dump.rdb

# Start Redis
systemctl start redis-server

# Verify
redis-cli DBSIZE
```

**2. Restore JTI Blacklist**
```bash
# Download backup
aws s3 cp s3://backups/jti/20250101.json license-server/data/jti_blacklist.json

# Verify
php -r "echo json_encode(json_decode(file_get_contents('license-server/data/jti_blacklist.json')), JSON_PRETTY_PRINT);" | head
```

**3. Restart Services**
```bash
systemctl restart gdwb-license-server
systemctl restart api-gateway
```

---

## Security Hardening

### Admin Token Protection

```bash
# Generate strong admin token (store securely)
php license-server/generate_admin_token.php

# Rotate regularly (monthly)
0 0 1 * * php license-server/generate_admin_token.php > /tmp/new_token.txt && \
  cat /tmp/new_token.txt && rm /tmp/new_token.txt

# Restrict access to token file
chmod 600 license-server/keys/admin_token.txt
chown www-data:www-data license-server/keys/admin_token.txt
```

### Redis Security

```bash
# Use strong authentication
requirepass <64-character-random-string>

# Disable dangerous commands
rename-command FLUSHDB ""
rename-command FLUSHALL ""
rename-command KEYS ""
rename-command CONFIG ""

# Use TLS (if supported)
tls-port 6380
tls-cert-file /etc/redis/cert.pem
tls-key-file /etc/redis/key.pem
```

### Network Security

```bash
# Restrict Redis to internal network only
bind 10.0.0.0/8

# Use VPN/SSH tunnel for remote connections
ssh -L 6379:127.0.0.1:6379 remote-redis-host

# Implement firewall rules
ufw allow from 10.0.0.0/8 to any port 6379
```

---

## FAQ

**Q: How often should I prune the JTI blacklist?**
A: Weekly for most deployments. Daily if processing >1000 revocations/day.

**Q: What's the maximum blacklist size?**
A: File system dependent, typically 1-100GB. Redis handles millions of keys natively.

**Q: Can I use Redis Cluster?**
A: Yes. Update Redis client config to support cluster topology.

**Q: How do I migrate from file-backed to Redis-backed?**
A: Set REDIS_HOST/PORT in environment and restart. File fallback remains active.

**Q: Is revocation immediate across all instances?**
A: Yes via Redis pub/sub (~1ms). File fallback may have slight delays (next introspection).

**Q: Can I revoke an admin token?**
A: Yes via `POST /api/v1/admin/token/revoke`. The token can't revoke itself.

