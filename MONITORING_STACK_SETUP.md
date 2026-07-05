# Monitoring Stack Setup Guide

## Overview

Sprint 7.3.4 establishes the observability consumption layer through a Docker Compose monitoring stack. This brings together:

- **Prometheus** — Time-series database for metrics collection from all services
- **Grafana** — Visualization and dashboard platform for operational insight
- **Alertmanager** — Alert routing and notification system
- **Node Exporter** — Host system metrics collection

## Quick Start

### Prerequisites

- Docker and Docker Compose installed
- API services running on local ports (gateway 8000, auth 8002, marketplace 8006, billing 8005, license-server 8001)
- `.env` file configured with service variables

### 1. Start the Monitoring Stack

```bash
docker-compose -f docker-compose.monitoring.yml up -d
```

This starts:
- **Prometheus**: http://localhost:9090
- **Grafana**: http://localhost:3000
- **Alertmanager**: http://localhost:9093

### 2. Verify Prometheus is Collecting Metrics

Visit http://localhost:9090 and check:

- **Targets** page (`http://localhost:9090/targets`) — Should show all services in "UP" state
- **Graph** page — Query `gateway_requests_total` to verify data collection

Example queries:
```promql
# Total requests by route
gateway_requests_total

# Requests per second over last minute
sum(rate(gateway_requests_total[1m])) by (route, status)

# Error rate by route
sum(rate(gateway_errors_total[5m])) / sum(rate(gateway_requests_total[5m]))

# P95 latency
histogram_quantile(0.95, sum(rate(gateway_request_duration_seconds_bucket[5m])) by (le, route))
```

### 3. Log into Grafana

Visit http://localhost:3000 (admin / admin)

Default dashboard **"Gateway Monitoring"** should already be available, showing:
- Request rate by route and status
- Error rate over time
- Latency percentiles (P50/P95)
- Error rate percentage by route

### 4. View Alert Rules

Prometheus will evaluate alert rules from `monitoring/alert-rules.yml`:

Visit http://localhost:9090/alerts to see active alerts

Current alert rules include:
- `GatewayHighErrorRate` — Error rate > 5% for 2 minutes
- `Gateway5xxErrorsSpike` — 5xx errors > 0.01/sec
- `GatewayHighLatency` — P95 latency > 1 second
- `AuthHighFailureRate` — Auth failure rate > 2%
- `ServiceDown` — Service metrics not received for 1 minute
- `HighCPUUsage` — System CPU > 80%
- `HighMemoryUsage` — System memory > 85%

### 5. Configure Alert Notifications

Alertmanager is configured in `monitoring/alertmanager.yml` with webhook receivers.

**Current configuration** (for local development):
- Critical alerts → `http://localhost:5000/alert`
- Warnings → `http://localhost:5000/alert`
- Auth alerts → `http://localhost:5000/alert/auth`
- Platform alerts → `http://localhost:5000/alert/platform`
- Ops alerts → `http://localhost:5000/alert/ops`

To receive actual notifications, integrate with:
- **Slack** — Add `slack_configs` to `monitoring/alertmanager.yml`
- **PagerDuty** — Add `pagerduty_configs`
- **Email** — Add `email_config`
- **Custom webhook** — Implement webhook receiver service

### 6. Create Custom Dashboards

#### Add metrics to new dashboard

1. In Grafana, create a new dashboard
2. Add panels with PromQL queries:

**Gateway Requests (Stat Panel)**:
```promql
sum(rate(gateway_requests_total[5m]))
```

**Error Rate % (Gauge Panel)**:
```promql
sum(rate(gateway_errors_total[5m])) / sum(rate(gateway_requests_total[5m])) * 100
```

**Latency Distribution (Heatmap)**:
```promql
sum(rate(gateway_request_duration_seconds_bucket[5m])) by (le)
```

**Service Availability (Table)**:
```promql
up{job=~"api-gateway|auth-service|marketplace-service|billing-service|license-server"}
```

## Key Metrics Reference

All services expose metrics at `/metrics` endpoint in Prometheus text format.

### Universal Metrics (all services)
- `{service}_requests_total{method, route, status}` — Total requests
- `{service}_errors_total{method, route, status}` — Total errors
- `{service}_request_duration_seconds{method, route, status}` — Latency histogram

### Service-Specific Metrics
- **Gateway**: `gateway_requests_total`, `gateway_errors_total`, `gateway_request_duration_seconds`
- **Auth**: `auth_requests_total`, `auth_errors_total`, `auth_request_duration_seconds`
- **Marketplace**: `marketplace_requests_total`, `marketplace_installs_total`, `marketplace_install_errors_total`
- **License Server**: `license_requests_total`, `license_errors_total`

### System Metrics (Node Exporter)
- `node_cpu_seconds_total`
- `node_memory_MemTotal_bytes`
- `node_memory_MemAvailable_bytes`
- `node_disk_io_time_seconds_total`

## PromQL Query Examples

### Request Throughput
```promql
# Requests per second
sum(rate(gateway_requests_total[1m])) by (route)

# Top 5 busiest routes
topk(5, sum(rate(gateway_requests_total[5m])) by (route))
```

### Error Analysis
```promql
# 5xx error rate
sum(rate(gateway_errors_total{status=~"5xx"}[5m])) by (route)

# Errors by status code
sum(rate(gateway_errors_total[5m])) by (status)

# Error percentage
sum(rate(gateway_errors_total[5m])) / sum(rate(gateway_requests_total[5m])) * 100
```

### Latency Analysis
```promql
# Average latency
sum(rate(gateway_request_duration_seconds_sum[5m])) by (route)
/
sum(rate(gateway_request_duration_seconds_count[5m])) by (route)

# P50, P95, P99 latency
histogram_quantile(0.50, sum(rate(gateway_request_duration_seconds_bucket[5m])) by (le, route))
histogram_quantile(0.95, sum(rate(gateway_request_duration_seconds_bucket[5m])) by (le, route))
histogram_quantile(0.99, sum(rate(gateway_request_duration_seconds_bucket[5m])) by (le, route))
```

### Service Health
```promql
# Services that are down
up == 0

# Average response time by service
sum(rate(gateway_request_duration_seconds_sum[5m])) by (instance)
/
sum(rate(gateway_request_duration_seconds_count[5m])) by (instance)
```

## Troubleshooting

### Services not appearing in Prometheus targets

**Problem**: Targets show "DOWN" in Prometheus UI

**Solution**:
1. Verify services are running: `docker ps`
2. Check service is exposing `/metrics` endpoint: `curl http://localhost:8000/metrics`
3. Check Prometheus scrape config targets in `monitoring/prometheus.yml`
4. Verify `host.docker.internal` resolves (Docker for Mac/Windows feature)

### No metrics data in Grafana

**Problem**: Grafana panels show "No data"

**Solution**:
1. Verify Prometheus has data: Visit `http://localhost:9090` and query `up`
2. Check data source connection: Grafana → Configuration → Data Sources → Prometheus
3. Verify time range is correct (not in the future)
4. Check metric name is correct: Use Prometheus UI autocomplete

### Alerts not firing

**Problem**: Alert rules defined but not triggering

**Solution**:
1. Check Prometheus Alert Rules: `http://localhost:9090/alerts`
2. Verify alert threshold values match your traffic patterns
3. Check Alertmanager console: `http://localhost:9093`
4. Verify webhook receiver is accessible

## Next Steps (Sprint 7.3.5)

The monitoring stack provides the infrastructure for Sprint 7.3.5 dashboard work:

- **Auth Service Dashboard**: Login success/failure, token issuance metrics
- **Marketplace Dashboard**: Plugin installs, failures, marketplace throughput
- **Intelligence Dashboard**: Effectiveness score, drift, acceptance rates
- **Operations Dashboard**: Readiness score, recovery metrics, SLA compliance

## Production Deployment Notes

**For production deployments**:

1. **Persistent Storage**: Configure volume mounts for Prometheus and Grafana data
   ```yaml
   volumes:
     prometheus-data: /prometheus
     grafana-data: /var/lib/grafana
   ```

2. **Authentication**: Change Grafana admin password in `docker-compose.monitoring.yml`

3. **Alert Routing**: Replace webhook receivers with production integrations (Slack, PagerDuty, email)

4. **Retention**: Configure Prometheus retention in `prometheus.yml`
   ```yaml
   command:
     - '--storage.tsdb.retention.time=30d'
   ```

5. **High Availability**: Deploy Prometheus and Grafana on separate hosts with replication

6. **Security**: Restrict access to Prometheus and Grafana ports (9090, 3000, 9093)

## Related Documentation

- [Prometheus Documentation](https://prometheus.io/docs/)
- [Grafana Documentation](https://grafana.com/docs/)
- [Alertmanager Documentation](https://prometheus.io/docs/alerting/latest/overview/)
- [Service Metadata Enrichment](../API_MATURITY_METADATA.md#metadata-enrichment-pattern)
- [Alert Rules Reference](./alert-rules.yml)
