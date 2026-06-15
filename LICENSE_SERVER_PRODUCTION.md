# Production TLS & Deployment Guide

## Overview

This guide documents how to deploy the license server in production with:
- **TLS/HTTPS** for secure communication
- **Key rotation** via JWKS endpoint
- **Distributed certificate management**
- **Production hardening** best practices

---

## 1. TLS Certificate Setup

### 1.1 Using Let's Encrypt (Recommended)

For automatic certificate management, use Certbot with a supported web server.

#### Prerequisites
- Domain name (e.g., `license.example.com`)
- DNS properly configured to point to your server IP
- Port 80 (HTTP) and 443 (HTTPS) accessible from the internet

#### Setup with Nginx

```bash
# Install Certbot and Nginx
sudo apt-get update
sudo apt-get install certbot python3-certbot-nginx nginx -y

# Obtain certificate
sudo certbot certonly --nginx -d license.example.com

# This creates:
# - /etc/letsencrypt/live/license.example.com/fullchain.pem
# - /etc/letsencrypt/live/license.example.com/privkey.pem
```

#### Setup with Apache

```bash
# Install Certbot and Apache
sudo apt-get update
sudo apt-get install certbot python3-certbot-apache apache2 -y

# Obtain certificate
sudo certbot certonly --apache -d license.example.com
```

#### Auto-renewal

```bash
# Enable auto-renewal timer
sudo systemctl enable certbot.timer
sudo systemctl start certbot.timer

# Test renewal
sudo certbot renew --dry-run
```

### 1.2 Using Self-Signed Certificates (Development Only)

```bash
# Generate private key + self-signed cert (valid 365 days)
openssl req -x509 -newkey rsa:2048 -keyout license-server.key -out license-server.crt \
  -days 365 -nodes \
  -subj "/C=US/ST=State/L=City/O=Org/CN=license.example.com"

# Move to secure location
sudo mv license-server.key /etc/ssl/private/
sudo mv license-server.crt /etc/ssl/certs/
sudo chmod 600 /etc/ssl/private/license-server.key
```

---

## 2. Nginx Configuration

### 2.1 Reverse Proxy Setup

Create `/etc/nginx/sites-available/license-server`:

```nginx
server {
    listen 80;
    server_name license.example.com;
    
    # Redirect HTTP to HTTPS
    return 301 https://$server_name$request_uri;
}

server {
    listen 443 ssl http2;
    server_name license.example.com;

    # TLS Configuration
    ssl_certificate /etc/letsencrypt/live/license.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/license.example.com/privkey.pem;
    
    # Modern TLS settings
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers HIGH:!aNULL:!MD5;
    ssl_prefer_server_ciphers on;
    ssl_session_cache shared:SSL:10m;
    ssl_session_timeout 10m;
    
    # Security headers
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-Frame-Options "DENY" always;
    add_header X-XSS-Protection "1; mode=block" always;

    # Rate limiting
    limit_req_zone $binary_remote_addr zone=api:10m rate=10r/s;
    limit_req_zone $binary_remote_addr zone=admin:10m rate=2r/s;

    # Proxy settings
    location / {
        proxy_pass http://127.0.0.1:8001;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_read_timeout 15s;
        proxy_connect_timeout 5s;
    }

    # API endpoints (higher rate limit)
    location /api/v1/validate {
        limit_req zone=api burst=20 nodelay;
        proxy_pass http://127.0.0.1:8001;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }

    location /api/v1/introspect {
        limit_req zone=api burst=20 nodelay;
        proxy_pass http://127.0.0.1:8001;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }

    # JWKS endpoint (cache for 1 hour)
    location /api/v1/jwks {
        proxy_pass http://127.0.0.1:8001;
        proxy_set_header Host $host;
        proxy_cache_valid 200 1h;
        proxy_cache_bypass $http_cache_control;
        add_header Cache-Control "public, max-age=3600";
    }

    # Admin endpoints (strict rate limit)
    location /api/v1/revoke {
        limit_req zone=admin burst=5 nodelay;
        proxy_pass http://127.0.0.1:8001;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }

    location /api/v1/jwks/rotate {
        limit_req zone=admin burst=5 nodelay;
        proxy_pass http://127.0.0.1:8001;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }

    # Deny direct access to keys directory
    location /keys {
        return 403;
    }

    # Deny access to sensitive files
    location ~ /\. {
        return 404;
    }
}
```

Enable the site:

```bash
sudo ln -s /etc/nginx/sites-available/license-server /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

### 2.2 Health Check Endpoint (Recommended)

Add a health check in your license server or use a simple status endpoint:

```nginx
location /health {
    access_log off;
    proxy_pass http://127.0.0.1:8001;
}
```

---

## 3. Application Server (PHP-FPM)

### 3.1 Running License Server in Production

Instead of `php -S`, use a proper application server:

#### Option A: Systemd Service

Create `/etc/systemd/system/license-server.service`:

```ini
[Unit]
Description=GD Workflow Bridge Pro - License Server
After=network.target postgresql.service redis.service

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/opt/license-server

# Environment
Environment="LICENSE_DB_DSN=pgsql:host=localhost;port=5432;dbname=gdwb_app"
Environment="LICENSE_DB_USER=gdwb_user"
Environment="LICENSE_DB_PASS=YOUR_SECURE_PASSWORD"
Environment="REDIS_HOST=localhost"
Environment="REDIS_PORT=6379"
Environment="PHP_ENV=production"

# Run license server on localhost only (reverse proxy via Nginx)
ExecStart=/usr/bin/php -S 127.0.0.1:8001 /opt/license-server/server.php

# Auto-restart on failure
Restart=on-failure
RestartSec=10s

# Security
PrivateTmp=true
NoNewPrivileges=true
ProtectSystem=strict
ProtectHome=true

[Install]
WantedBy=multi-user.target
```

Enable and start:

```bash
sudo systemctl daemon-reload
sudo systemctl enable license-server
sudo systemctl start license-server
sudo systemctl status license-server
```

#### Option B: PHP-FPM + Nginx (Advanced)

```bash
# Install PHP-FPM
sudo apt-get install php8.2-fpm php8.2-pgsql php8.2-redis -y

# Create PHP-FPM pool config
sudo tee /etc/php/8.2/fpm/pool.d/license-server.conf > /dev/null <<'EOF'
[license-server]
listen = 127.0.0.1:9000
user = www-data
group = www-data
pm = dynamic
pm.max_children = 50
pm.start_servers = 5
pm.min_spare_servers = 5
pm.max_spare_servers = 10

env[LICENSE_DB_DSN] = "pgsql:host=localhost;port=5432;dbname=gdwb_app"
env[LICENSE_DB_USER] = "gdwb_user"
env[LICENSE_DB_PASS] = "YOUR_SECURE_PASSWORD"
env[REDIS_HOST] = "localhost"
env[REDIS_PORT] = "6379"
EOF

sudo systemctl restart php8.2-fpm
```

---

## 4. Database Hardening

### 4.1 PostgreSQL Production Setup

```bash
# Create dedicated role (if not exists)
sudo sudo -u postgres psql <<'SQL'
CREATE ROLE gdwb_user WITH LOGIN PASSWORD 'YOUR_SECURE_PASSWORD';
CREATE DATABASE gdwb_app OWNER gdwb_user;

-- Restrict permissions
REVOKE ALL ON DATABASE gdwb_app FROM public;
GRANT CONNECT ON DATABASE gdwb_app TO gdwb_user;

-- Setup SSL for remote connections
-- (see PostgreSQL docs for ssl=on in postgresql.conf)
SQL
```

### 4.2 PostgreSQL SSL Connection

In `postgresql.conf`:

```ini
ssl = on
ssl_cert_file = '/etc/postgresql/server.crt'
ssl_key_file = '/etc/postgresql/server.key'
ssl_protocols = 'TLSv1.2,TLSv1.3'
```

In `pg_hba.conf`, require SSL for network connections:

```
hostssl   gdwb_app   gdwb_user   0.0.0.0/0   md5
```

Restart PostgreSQL:

```bash
sudo systemctl restart postgresql
```

---

## 5. Key Rotation Strategy

### 5.1 Rotation Workflow

The JWKS endpoint (`/api/v1/jwks`) serves all currently valid public keys.

#### Create a new signing key:

```bash
curl -X POST https://license.example.com/api/v1/jwks/rotate \
  -H "Authorization: Bearer $ADMIN_TOKEN"
```

Response:

```json
{
  "success": true,
  "message": "Key rotated",
  "kid": "kid_20250522120000_abc12345",
  "private_key_path": "/path/to/private_kid_20250522120000_abc12345.pem"
}
```

#### Automatic rotation (recommended):

Create a cron job to rotate keys every 90 days:

```bash
# /etc/cron.d/license-server-key-rotation
0 0 1 */3 * root /usr/local/bin/rotate-license-keys.sh
```

Script: `/usr/local/bin/rotate-license-keys.sh`

```bash
#!/bin/bash
ADMIN_TOKEN=$(cat /opt/license-server/keys/admin_token.txt)
curl -X POST https://license.example.com/api/v1/jwks/rotate \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Content-Type: application/json"
```

### 5.2 Key Deprecation

Old keys remain in JWKS for 30 days to allow distributed clients to validate issued tokens. After 30 days, keys are removed automatically (configure in `jwks.php`).

---

## 6. Monitoring & Logging

### 6.1 Application Logging

Configure in your license server to log all events:

```php
// license-server/server.php
define('LOG_FILE', '/var/log/license-server/app.log');
define('LOG_LEVEL', 'INFO'); // DEBUG, INFO, WARN, ERROR
```

### 6.2 Access Logging (Nginx)

Logs are in `/var/log/nginx/access.log` and `/var/log/nginx/error.log`.

Monitor for suspicious patterns:

```bash
# Monitor failed validations
tail -f /var/log/nginx/access.log | grep "POST /api/v1/validate" | grep "400\|401\|403\|500"
```

### 6.3 Health Check Monitoring

Use Uptime Kuma, Datadog, or similar to monitor:

```bash
curl -X GET https://license.example.com/api/v1/jwks/status \
  -H "Authorization: Bearer $ADMIN_TOKEN"
```

---

## 7. Backup & Disaster Recovery

### 7.1 Database Backups

```bash
# Daily automated backup (cron)
0 2 * * * /usr/local/bin/backup-license-db.sh

# Script: /usr/local/bin/backup-license-db.sh
#!/bin/bash
BACKUP_DIR="/mnt/backups/license-server"
mkdir -p "$BACKUP_DIR"
pg_dump -h localhost -U gdwb_user gdwb_app | \
  gzip > "$BACKUP_DIR/gdwb_app_$(date +%Y%m%d_%H%M%S).sql.gz"

# Cleanup old backups (keep last 30 days)
find "$BACKUP_DIR" -mtime +30 -delete
```

### 7.2 Key Backup

Store private keys in a secure vault (e.g., HashiCorp Vault, AWS Secrets Manager):

```bash
# Example: backup to AWS Secrets Manager
aws secretsmanager create-secret \
  --name license-server/private-keys \
  --secret-string file:///opt/license-server/keys/private.pem
```

### 7.3 Disaster Recovery Plan

1. Keep off-site encrypted backups of private keys
2. Document key IDs and rotation dates
3. Maintain a recovery procedure document
4. Test recovery monthly

---

## 8. Security Checklist

- [ ] TLS/HTTPS enabled and HTTP redirects to HTTPS
- [ ] Certificate auto-renewal configured and tested
- [ ] All keys stored outside web root
- [ ] Database credentials in environment variables (never committed)
- [ ] Rate limiting enabled on all endpoints
- [ ] Admin token rotated regularly
- [ ] Nginx security headers configured
- [ ] Firewall rules restrict access (port 5432/6379 to localhost only)
- [ ] Access logs monitored for suspicious activity
- [ ] Database backups encrypted and stored off-site
- [ ] Key rotation schedule established
- [ ] Disaster recovery plan documented and tested
- [ ] Web application firewall (WAF) enabled (e.g., ModSecurity)
- [ ] DDoS protection enabled (e.g., Cloudflare)

---

## 9. Troubleshooting

### Certificate Renewal Failing

```bash
# Check renewal status
sudo certbot renew --dry-run -v

# Manual renewal
sudo certbot renew -v --force-renewal
```

### TLS Handshake Errors

```bash
# Test TLS configuration
openssl s_client -connect license.example.com:443 -tls1_2

# Check certificate validity
openssl x509 -in /etc/letsencrypt/live/license.example.com/cert.pem -text -noout
```

### High Latency

- Check Postgres connection pool
- Monitor Redis memory usage
- Enable nginx caching for JWKS endpoint

---

## References

- [Let's Encrypt Documentation](https://letsencrypt.org/docs/)
- [Nginx SSL Setup](https://nginx.org/en/docs/http/ngx_http_ssl_module.html)
- [PostgreSQL SSL Documentation](https://www.postgresql.org/docs/current/ssl-tcp.html)
- [JWKS Specification](https://tools.ietf.org/html/rfc7517)
- [JWT Best Practices](https://tools.ietf.org/html/rfc8725)
