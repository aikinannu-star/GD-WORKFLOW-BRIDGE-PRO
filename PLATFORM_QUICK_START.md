# Complete SaaS Platform - Quick Reference

## Project Structure
```
gd-workflow-bridge-pro/
├── license-server/           # Core license & entitlement engine
├── services/                 # Microservices
│   ├── auth/
│   ├── billing/
│   ├── cms/
│   ├── domains/
│   ├── marketplace/
│   ├── usage/
│   └── admin/
├── gateway/                  # API Gateway
├── web/                      # Frontend applications
│   ├── customer-portal/
│   ├── developer-marketplace/
│   ├── admin-dashboard/
│   ├── cms-builder/
│   └── marketing-site/
├── sdk/                      # Client SDKs
├── integrations/             # Cloud service integrations
│   ├── aws/
│   ├── stripe/
│   ├── sendgrid/
│   ├── cloudflare/
│   ├── auth0/
│   └── datadog/
├── infra/                    # Infrastructure & deployment
│   ├── docker/
│   ├── kubernetes/
│   └── terraform/
└── database/                 # Schemas & migrations
```

## Quick Start

### Local Development
```bash
# Start all services with Docker Compose
docker-compose -f infra/docker/docker-compose.yml up -d

# Run database migrations
docker-compose exec postgres psql -U user -d gdwb_app -f /docker-entrypoint-initdb.d/001_init_platform.sql

# Check service health
curl http://localhost:8001/health
curl http://localhost:8002/health
# ... etc for other services
```

### AWS Deployment
```bash
cd infra/terraform
terraform init
terraform plan -var-file="prod.tfvars"
terraform apply
```

## Services & Ports

| Service | Port | Purpose |
|---------|------|---------|
| License Server | 8001 | Core licensing & entitlements |
| Auth Service | 8002 | Authentication, SSO, MFA |
| Billing Service | 8003 | Subscriptions, payments, invoices |
| CMS Service | 8004 | Site builder, content management |
| Domains Service | 8005 | Domain registration, DNS |
| Marketplace | 8006 | Plugin registry, extensions |
| Usage Service | 8007 | Usage tracking, limits |
| Admin Service | 8008 | Admin operations, audit logs |
| API Gateway | 80/443 | Central routing |

## Cloud Integrations

- **AWS**: RDS, S3, Route53, SES, ECS, Lambda
- **Stripe**: Payments & subscriptions
- **Cloudflare**: DNS, CDN, SSL
- **Auth0**: Identity & SSO
- **SendGrid**: Email
- **Datadog**: Monitoring

## Environment Variables

```bash
DATABASE_URL=postgresql://user:password@postgres:5432/gdwb_app
REDIS_URL=redis://redis:6379
STRIPE_SECRET_KEY=sk_...
CLOUDFLARE_API_KEY=...
AUTH0_DOMAIN=...
AUTH0_CLIENT_ID=...
AWS_REGION=us-east-1
```

## API Examples

### Issue a License Token
```bash
curl -X POST http://localhost:8001/api/v1/token \
  -H "Content-Type: application/json" \
  -d '{"grant_type":"license","license_key":"KEY-123","plan":"pro","site":"https://example.com"}'
```

### Create a CMS Site
```bash
curl -X POST http://localhost:8004/api/v1/sites \
  -H "Content-Type: application/json" \
  -d '{"title":"My Site","slug":"my-site","template":"blank"}'
```

### Register a Domain
```bash
curl -X POST http://localhost:8005/api/v1/domains/register \
  -H "Content-Type: application/json" \
  -d '{"name":"example.com","registrar":"cloudflare"}'
```

## Monitoring & Logs

```bash
# View all service logs
docker-compose -f infra/docker/docker-compose.yml logs -f

# View specific service logs
docker-compose -f infra/docker/docker-compose.yml logs -f cms-service

# Check database
docker-compose -f infra/docker/docker-compose.yml exec postgres psql -U user -d gdwb_app

# Monitor Redis
docker-compose -f infra/docker/docker-compose.yml exec redis redis-cli monitor
```

## Next Steps

1. **Implement Service Endpoints** — Each service needs full API implementation
2. **Build Frontend Applications** — React/Vue apps for portals and CMS builder
3. **Set Up Cloud Accounts** — AWS, Stripe, Cloudflare, Auth0
4. **Configure CI/CD** — GitHub Actions for builds and deployments
5. **Add Monitoring** — Datadog dashboards and alerts
6. **Test & Deploy** — Staging environment first, then production
