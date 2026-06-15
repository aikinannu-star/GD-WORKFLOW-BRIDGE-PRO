# VPS Deployment Guide

This guide covers how to deploy `gd-workflow-bridge-pro` to a VPS (Virtual Private Server) using Docker, without installing Docker locally.

## Prerequisites

### VPS Setup (Ubuntu 20.04 LTS or later recommended)

1. **Provision a VPS** from a provider (DigitalOcean, Linode, AWS EC2, Vultr, etc.)
   - Minimum: 2GB RAM, 1 vCPU, 20GB SSD for prototype
   - For production: 4GB+ RAM, 2+ vCPU, 50GB+ SSD

2. **Connect and install Docker**:
   ```bash
   # SSH into your VPS
   ssh root@your-vps-ip
   
   # Update system packages
   apt-get update && apt-get upgrade -y
   
   # Install Docker (Ubuntu quick-start)
   curl -fsSL https://get.docker.com -o get-docker.sh
   sudo sh get-docker.sh
   
   # Add your user to docker group (to avoid `sudo` for docker commands)
   sudo usermod -aG docker $USER
   # Log out and back in for group changes to take effect
   exit
   ```

3. **Verify Docker**:
   ```bash
   docker --version
   docker compose version
   ```

### Local Machine Prerequisites

- **SSH client** (built-in on Linux/macOS; OpenSSH on Windows 10+)
- **scp** or Git Bash (for file transfer)
- **SSH key** (optional but recommended for automated deploys)

## Quick Deploy

1. **Set SSH key** (recommended, skip if you prefer password auth):
   ```bash
   # Generate SSH key locally (if not present)
   ssh-keygen -t ed25519 -f ~/.ssh/id_vps -N ""
   
   # Copy public key to VPS
   ssh-copy-id -i ~/.ssh/id_vps root@your-vps-ip
   ```

2. **Deploy from your local machine**:
   ```bash
   # Bash (macOS/Linux/Git Bash)
   ./scripts/remote-deploy.sh -h your-vps-ip -u root -d /root/gd-workflow-bridge-pro
   
   # PowerShell (Windows)
   .\scripts\remote-deploy.ps1 -HostName your-vps-ip -UserName root -RemoteDir /root/gd-workflow-bridge-pro
   ```

3. **Verify deployment**:
   ```bash
   # Check services
   ssh root@your-vps-ip "cd /root/gd-workflow-bridge-pro && docker compose ps"
   
   # Check logs
   ssh root@your-vps-ip "cd /root/gd-workflow-bridge-pro && docker compose logs -f gateway-service"
   ```

## Post-Deploy Configuration

### 1. Open Firewall Ports

```bash
# SSH into VPS
ssh root@your-vps-ip

# UFW (if enabled)
sudo ufw allow 22/tcp      # SSH
sudo ufw allow 8000/tcp    # Gateway (API)
sudo ufw allow 8001/tcp    # License server (optional)
sudo ufw allow 5432/tcp    # Postgres (optional, internal only recommended)
sudo ufw allow 6379/tcp    # Redis (optional, internal only recommended)

# Check firewall status
sudo ufw status
```

### 2. Set Environment Variables

Edit `.env` or set in `docker-compose.yml`:

```bash
cat > /root/gd-workflow-bridge-pro/.env <<EOF
# Auth
AUTH_JWT_SECRET=your-secret-key-here-change-me

# Database
POSTGRES_USER=gdwb_user
POSTGRES_PASSWORD=your-db-password-here-change-me
POSTGRES_DB=gdwb_app

# License
LICENSE_SERVER_PORT=8001
LICENSE_JWT_SECRET=your-license-secret-here

# Billing
BILLING_CURRENCY=USD

# CMS
CMS_DEFAULT_LOCALE=en_US

# Gateway
GATEWAY_CORS_ORIGIN=https://yourdomain.com
GATEWAY_RATE_LIMIT_PER_MIN=120
GATEWAY_DEFAULT_TENANT_LIMIT=100
GATEWAY_REDIS_HOST=redis
GATEWAY_REDIS_PORT=6379
EOF
```

### 3. Restart Services with New Env

```bash
cd /root/gd-workflow-bridge-pro
docker compose down
docker compose up -d --build
```

## Production Considerations

### Reverse Proxy (Nginx/HAProxy)

For external access, use a reverse proxy on port 80/443:

```bash
# Install nginx
sudo apt-get install -y nginx

# Create config
sudo tee /etc/nginx/sites-available/api <<EOF
upstream gateway {
    server 127.0.0.1:8000;
}

server {
    listen 80;
    server_name api.yourdomain.com;

    location / {
        proxy_pass http://gateway;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
    }
}
EOF

# Enable site
sudo ln -s /etc/nginx/sites-available/api /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

### SSL/TLS (Let's Encrypt)

```bash
# Install certbot
sudo apt-get install -y certbot python3-certbot-nginx

# Generate certificate
sudo certbot certonly --nginx -d api.yourdomain.com

# Update nginx to use SSL (Certbot can do this automatically)
sudo certbot --nginx -d api.yourdomain.com
```

### Monitoring & Logging

1. **View logs**:
   ```bash
   docker compose logs -f gateway-service
   docker compose logs -f auth-service
   ```

2. **Health checks**:
   ```bash
   curl http://your-vps-ip:8000/health
   curl http://your-vps-ip:8001/health
   ```

3. **Uptime monitoring**: Use external services like Uptime Robot or Pingdom.

### Data Persistence

Volumes are stored in `/var/lib/docker/volumes/`:

```bash
# List volumes
docker volume ls

# Backup volume
docker run --rm -v gdwb_pgdata:/data -v /backup:/backup alpine tar czf /backup/pgdata-backup.tar.gz /data

# Restore volume
docker run --rm -v gdwb_pgdata:/data -v /backup:/backup alpine tar xzf /backup/pgdata-backup.tar.gz -C /
```

## Remote CI/CD Integration

For GitHub Actions or other CI platforms, use the deploy scripts:

```yaml
# .github/workflows/deploy-vps.yml
name: Deploy to VPS
on:
  push:
    branches:
      - main

jobs:
  deploy:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - name: Deploy to VPS
        run: |
          mkdir -p ~/.ssh
          echo "${{ secrets.VPS_SSH_KEY }}" > ~/.ssh/vps_key
          chmod 600 ~/.ssh/vps_key
          ssh-keyscan -p 22 ${{ secrets.VPS_HOST }} >> ~/.ssh/known_hosts
          ./scripts/remote-deploy.sh -h ${{ secrets.VPS_HOST }} -u ${{ secrets.VPS_USER }} -d ~/gd-workflow-bridge-pro
```

Store secrets in GitHub:
- `VPS_SSH_KEY`: Private SSH key (base64 encoded or PEM)
- `VPS_HOST`: VPS IP or hostname
- `VPS_USER`: SSH user (typically `root` or Ubuntu username)

## Troubleshooting

### Docker Compose not found

```bash
# Verify installation
docker compose version

# If missing, install compose v2
curl -L "https://github.com/docker/compose/releases/latest/download/docker-compose-$(uname -s)-$(uname -m)" -o /usr/local/bin/docker-compose
chmod +x /usr/local/bin/docker-compose
```

### Gateway not starting

```bash
# Check logs
docker compose logs gateway-service

# Verify network
docker compose exec gateway-service ping redis
docker compose exec gateway-service ping auth-service
```

### Out of disk space

```bash
# Check usage
df -h

# Clean up Docker images/containers
docker system prune -a --volumes
```

### Permission denied (docker socket)

```bash
# Add user to docker group
sudo usermod -aG docker $USER
# Log out and back in, or:
newgrp docker
```

## Next Steps

- Add domain DNS records pointing to your VPS IP
- Set up SSL certificates (see Production Considerations > SSL/TLS)
- Configure backup automation for PostgreSQL volumes
- Set up application monitoring and alerting
