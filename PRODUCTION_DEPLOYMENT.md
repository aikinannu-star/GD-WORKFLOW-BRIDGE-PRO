# Production Deployment Guide

## Overview

This guide walks through deploying the GD Workflow Bridge Pro platform to production. The system consists of multiple microservices coordinated through Docker Compose, with emphasis on graceful shutdown, health-check based orchestration, and structured logging.

## Architecture Overview

```
Client
  │
  ├─► API Gateway (Port 80/443)
  │     │
  │     ├─► Dispatcher Service (Port 8020)
  │     │     │
  │     │     └─► Assistant Service (Port 8017)
  │     │           │
  │     │           └─► Ollama LLM (Port 11434)
  │     │
  │     ├─► Marketplace Service (Port 8001)
  │     ├─► Billing Service (Port 8003)
  │     └─► Usage Service (Port 8007)
```

## Prerequisites

- Docker Engine 20.10+ 
- Docker Compose 2.0+
- Minimum 4 GB RAM (8 GB recommended)
- 20 GB disk space (for Ollama models)

## Environment Configuration

Create a `.env` file in the project root:

```bash
# Ollama / LLM
ASSISTANT_LLM_API_URL=http://ollama:11434/v1/completions
ASSISTANT_LLM_MODEL=gemma:2b
ASSISTANT_LLM_MAX_TOKENS=512
ASSISTANT_LLM_TEMPERATURE=0.2
ASSISTANT_LLM_TIMEOUT_SECONDS=30
ASSISTANT_PROVIDER=ollama

# Gateway
GATEWAY_PORT=80
GATEWAY_ENABLE_COMPRESSION=1

# Database (if applicable)
DB_HOST=postgres
DB_PORT=5432
DB_NAME=gdwb
DB_USER=gdwb_user
DB_PASSWORD=<generate-random>

# Redis (if applicable)
REDIS_HOST=redis
REDIS_PORT=6379
REDIS_PASSWORD=<generate-random>

# Service Ports
SERVICE_PORT=8000

# Logging
ASSISTANT_DEBUG_LOG_REQUESTS=false
ASSISTANT_SHUTDOWN_TIMEOUT=30
```

## Deployment Steps

### 1. Pull Latest Code

```bash
git clone https://github.com/aikinannu-star/GD-WORKFLOW-BRIDGE-PRO.git
cd GD-WORKFLOW-BRIDGE-PRO
git checkout main
```

### 2. Build Images

```bash
docker compose build
```

This will:
- Build PHP service image (includes ollama-init.sh)
- Tag images with project name

### 3. Start Services

```bash
docker compose up -d
```

The assistant service will automatically:
1. Wait for Ollama to be healthy
2. Check if `gemma:2b` model is available
3. Pull the model if missing (first deployment only)
4. Start the PHP server

**First deployment may take 1-2 minutes** due to model download (~1.7 GB).

### 4. Verify Health

```bash
# Check service readiness
curl http://localhost:8017/health/ready

# Expected response (200 OK):
# {"ready":true,"service":"assistant","provider":"ollama","time":"2026-07-08T...","errors":[]}

# Check liveness
curl http://localhost:8017/health/live

# Check full gateway
curl http://localhost/api/v1/gateway/health
```

## Health Check Endpoints

Each service exposes standardized health endpoints:

| Endpoint | Purpose | Status Codes |
|----------|---------|--------------|
| `/health` | Full health check | 200 (healthy), 503 (not ready) |
| `/health/ready` | Readiness probe (K8s/ECS) | 200 (ready), 503 (not ready) |
| `/health/live` | Liveness probe (K8s/ECS) | 200 (alive), 503 (dead) |
| `/metrics` | Prometheus metrics | 200 (metrics) |

### Using with Kubernetes

```yaml
livenessProbe:
  httpGet:
    path: /health/live
    port: 8017
  initialDelaySeconds: 5
  periodSeconds: 10

readinessProbe:
  httpGet:
    path: /health/ready
    port: 8017
  initialDelaySeconds: 30
  periodSeconds: 5
  failureThreshold: 3
```

## Model Management

### Current Models

- **gemma:2b** (default) - ~1.7 GB, lightweight, fast
- **mistral:latest** - ~4 GB, higher quality
- **llama2:latest** - ~3.8 GB, larger context window

### Change Default Model

```bash
# Update .env
ASSISTANT_LLM_MODEL=mistral:latest

# Pull the new model inside Ollama container
docker compose exec ollama ollama pull mistral:latest

# Restart assistant service
docker compose up -d --force-recreate assistant-service
```

### Pre-download Models

For air-gapped or slow-network deployments:

```bash
# Download model first
docker compose exec ollama ollama pull gemma:2b
docker compose exec ollama ollama pull mistral:latest

# Models are cached, subsequent deployments will use the cache
```

## Logging

### Service Logs

```bash
# Assistant service logs
docker compose logs -f assistant-service

# All service logs
docker compose logs -f

# Structured logs (JSON)
cat services/data/assistant_app.log | jq .
```

### Log Locations

- **Assistant structured log**: `services/data/assistant_app.log`
- **Docker compose stdout**: `docker compose logs`

### Log Fields

Each structured log entry includes:
- `service`: service name
- `timestamp`: ISO 8601 UTC
- `level`: info, warning, error
- `message`: event name
- `trace_id`: distributed trace ID
- `span_id`: request span ID
- `request_id`: unique request ID

Example:
```json
{
  "service": "assistant",
  "timestamp": "2026-07-08T10:18:13+00:00",
  "level": "info",
  "message": "assistant_provider_result",
  "success": true,
  "error": null,
  "provider": "OllamaProvider",
  "api_url": "http://ollama:11434/v1/completions",
  "response_preview": "Hello! 👋 It's a pleasure to meet you..."
}
```

## Resource Limits

Recommended resource allocation:

```yaml
# docker-compose.yml
services:
  assistant-service:
    deploy:
      resources:
        limits:
          cpus: '2'
          memory: 2G
        reservations:
          cpus: '1'
          memory: 1G
  
  ollama:
    deploy:
      resources:
        limits:
          cpus: '4'
          memory: 6G
        reservations:
          cpus: '2'
          memory: 4G
```

Adjust based on:
- Model size (gemma:2b = 2GB, mistral = 4GB)
- Concurrent users (each request needs ~100MB)
- Available system memory

## Graceful Shutdown

Services support SIGTERM graceful shutdown:

```bash
# Services will:
# 1. Stop accepting new requests
# 2. Drain in-flight requests (up to SHUTDOWN_TIMEOUT)
# 3. Close connections
# 4. Exit cleanly

docker compose down
# Sends SIGTERM to all services, waits 10s, then SIGKILL
```

To customize shutdown timeout:

```bash
ASSISTANT_SHUTDOWN_TIMEOUT=30 docker compose up -d
```

## Monitoring

### Prometheus Metrics

Metrics available at `/metrics` (Prometheus format):

```bash
curl http://localhost:8017/metrics
```

Key metrics:
- `assistant_health_ready_checks_total` - readiness probe counts
- `assistant_health_live_checks_total` - liveness probe counts
- `assistant_requests_rejected_during_shutdown_total` - shutdown rejections

### Alerting Rules

Example Prometheus alert:

```yaml
groups:
  - name: assistant
    rules:
      - alert: AssistantNotReady
        expr: up{job="assistant"} == 0
        for: 1m
        annotations:
          summary: "Assistant service is not ready"
      
      - alert: AssistantHighErrorRate
        expr: rate(assistant_provider_failures_total[5m]) > 0.1
        annotations:
          summary: "Assistant error rate > 10%"
```

## Troubleshooting

### Assistant returns 502 llm_unavailable

**Symptoms**: Requests to `/api/v1/assistant/sessions/{id}/message` return HTTP 502

**Causes**:
1. Ollama not running
2. Model not loaded
3. Ollama out of memory

**Debug steps**:

```bash
# Check Ollama health
curl http://localhost:11434/api/tags

# Check loaded models
docker compose exec ollama ollama list

# Check assistant logs for provider errors
docker compose logs assistant-service | grep provider

# Restart Ollama
docker compose restart ollama
```

### Assistant slow or timing out

**Causes**:
1. Model too large for available memory
2. Concurrent requests exceeding Ollama capacity
3. Network latency

**Solutions**:
1. Use smaller model (gemma:2b)
2. Limit concurrent requests
3. Increase ASSISTANT_LLM_TIMEOUT_SECONDS

### Disk space exhausted

**Symptoms**: Ollama fails to pull models

**Solution**:
```bash
# Check Ollama model cache
du -sh services/assistant/ollama/

# Remove unused models (inside Ollama)
docker compose exec ollama ollama rm mistral:latest

# Clear Docker build cache if needed
docker system prune
```

## Upgrade Procedure

### Upgrade Code

```bash
git pull origin main
docker compose build
docker compose up -d --force-recreate
```

### Verify After Upgrade

```bash
# Wait for services to be ready (may take 1-2 min)
sleep 30

# Check all health endpoints
curl http://localhost:8017/health/ready
curl http://localhost:8020/health/ready
curl http://localhost/api/v1/gateway/health

# Test a request
curl -X POST http://localhost:8017/api/v1/assistant/sessions \
  -H 'Content-Type: application/json' \
  -d '{"user_id":"test"}'
```

## Rollback Procedure

If issues occur after upgrade:

```bash
# Revert to previous commit
git checkout <previous-commit>

# Rebuild and restart
docker compose build
docker compose up -d --force-recreate

# Verify
curl http://localhost:8017/health/ready
```

## Security Considerations

1. **API Gateway** - Use TLS termination (nginx/Traefik)
2. **Database** - Use strong passwords, enable SSL
3. **Redis** - Enable authentication, disable public access
4. **Environment Variables** - Use secrets management (not .env in production)
5. **Ollama** - Only expose on internal network (not 0.0.0.0 in production)

Example production docker-compose excerpt:

```yaml
services:
  ollama:
    # Only listen on internal network
    ports:
      - "127.0.0.1:11434:11434"
    # Or use Docker networks
    networks:
      - internal
  
  assistant-service:
    networks:
      - internal
      - external
    # No external port exposure

networks:
  internal:
    driver: bridge
  external:
    driver: bridge
```

## Production Checklist

- [ ] `.env` configured with production values
- [ ] Database backups enabled
- [ ] Redis persistence configured
- [ ] TLS certificates installed
- [ ] Monitoring/alerting configured
- [ ] Log aggregation setup (ELK, Splunk, etc.)
- [ ] Rate limiting configured at API Gateway
- [ ] Ollama model(s) pre-downloaded
- [ ] Resource limits configured
- [ ] Health check endpoints verified
- [ ] Graceful shutdown tested
- [ ] Rollback procedure documented and tested
- [ ] Incident response plan ready

## Support

For issues or questions:
1. Check logs: `docker compose logs -f`
2. Verify health: `curl http://localhost:PORT/health`
3. Check metrics: `curl http://localhost:PORT/metrics`
4. Review troubleshooting section above
5. Open issue on GitHub with logs and metrics output
