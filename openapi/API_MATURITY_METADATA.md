# API Maturity & Stability Metadata

## Overview
This document defines the maturity levels for API operations, ensuring clear communication with SDK consumers about breaking change risk and support expectations.

## Maturity Levels

### Level 1: Stable ✅
**Status**: Ready for production use  
**Guarantee**: Covered by semantic versioning, backward compatibility maintained  
**Breaking Changes**: Only in major version releases with migration path  
**Support**: Full support, regular maintenance  

**When to use**: 
- Operations that have been deployed for >3 months
- Operations with production traffic > 1000 req/day
- Operations tested across multiple SDK languages
- Operations with stable schema definitions

**Example**:
```yaml
/api/v1/marketplace/products:
  get:
    summary: List marketplace products
    operationId: marketplaceListMarketplaceProducts
    x-maturity: stable
    x-stabilitySince: "2024-01-15"
    x-deprecationPath: null
```

### Level 2: Beta ⚠️
**Status**: Publicly available but may evolve  
**Guarantee**: API shape may change based on feedback  
**Breaking Changes**: Possible in minor versions with notice  
**Support**: Full support, but changes may occur  

**When to use**:
- New operations (deployed < 3 months)
- Operations with active development
- Operations under evaluation for feature completeness
- Operations with evolving schema requirements

**Example**:
```yaml
/api/v1/intelligence-learning/effectiveness-score:
  get:
    summary: Consolidated effectiveness score
    operationId: intelligenceListIntelligencelearningEffectivenessscore
    x-maturity: beta
    x-stabilityExpected: "2024-03-30"
    x-feedback: "https://github.com/gd-workflow-bridge-pro/api/issues/label:effectiveness-score"
```

### Level 3: Experimental 🔬
**Status**: Not intended for production use  
**Guarantee**: May be removed or heavily modified  
**Breaking Changes**: Frequent, without notice  
**Support**: Best-effort, may be deprecated  

**When to use**:
- Proof-of-concept operations
- Operations under active design
- Operations subject to security review
- Operations pending compliance approval

**Example**:
```yaml
/api/v1/marketplace/test/scenario:
  post:
    summary: Test scenario simulation (experimental)
    operationId: testingCreateMarketplacetest
    x-maturity: experimental
    x-warning: "This endpoint may change or be removed without notice"
    x-requiredApprovals: ["security-review", "compliance-review"]
```

### Level 4: Internal ⛔
**Status**: Not intended for external consumption  
**Guarantee**: No guarantees  
**Breaking Changes**: Frequent, unpredictable  
**Support**: None, use at own risk  

**When to use**:
- Endpoints only for internal tooling
- Endpoints under heavy development
- Endpoints pending deprecation
- Administrative/operational endpoints

**Example**:
```yaml
/admin/reset:
  post:
    summary: Reset platform state (internal only)
    operationId: adminReset
    x-maturity: internal
    x-warning: "NOT FOR EXTERNAL USE"
    x-audience: "internal-team-only"
```

## OpenAPI Extension Schema

Add these custom extensions to every operation:

```yaml
x-maturity:
  type: string
  enum: ["stable", "beta", "experimental", "internal"]
  description: Maturity level of the operation

x-stabilitySince:
  type: string
  format: date
  description: Date when operation reached current maturity level

x-stabilityExpected:
  type: string
  format: date
  description: Expected date to advance maturity level

x-deprecationPath:
  type: string
  description: Recommended alternative operation ID, if deprecated

x-requiredApprovals:
  type: array
  items:
    type: string
  description: Required approvals before reaching stability

x-feedback:
  type: string
  format: uri
  description: URL for submitting feedback or issues
```

## Implementation in OpenAPI

### Adding to All Operations

This Python script adds maturity metadata to all operations:

```python
import yaml
from pathlib import Path
from datetime import datetime

# Maturity mapping by domain
MATURITY_MAP = {
    'Marketplace': 'stable',        # Deployed 6+ months
    'Intelligence': 'beta',          # Active development
    'Platform': 'stable',            # Established operations
    'Risk Zones': 'beta',            # Evaluating effectiveness
    'Remediation': 'stable',         # Proven reliability
    'Drift Analysis': 'beta',        # Recent deployment
    'Health': 'stable',              # Core operation
    'Testing': 'experimental',       # Test-only operation
}

def add_maturity_metadata(spec_path, mapping):
    """Add x-maturity to all operations."""
    with spec_path.open('r') as f:
        spec = yaml.safe_load(f)
    
    today = datetime.now().strftime('%Y-%m-%d')
    
    for path, methods in spec.get('paths', {}).items():
        for method, operation in methods.items():
            if isinstance(operation, dict):
                domain = operation.get('tags', ['Untagged'])[0]
                maturity = mapping.get(domain, 'experimental')
                
                operation['x-maturity'] = maturity
                operation['x-stabilitySince'] = today
                operation['x-feedback'] = 'https://github.com/gd-workflow-bridge-pro/api/issues'
                
                if maturity == 'beta':
                    operation['x-stabilityExpected'] = '2024-03-30'
                elif maturity == 'experimental':
                    operation['x-requiredApprovals'] = ['design-review']
    
    with spec_path.open('w') as f:
        yaml.safe_dump(spec, f, sort_keys=False)
```

## Current State: Proposed Maturity Map

Based on deployment history and stability:

| Domain | Level | Rationale | Stability Date |
|--------|-------|-----------|-----------------|
| Marketplace | Stable | 6+ months production | 2023-06-15 |
| Remediation | Stable | Proven, high reliability | 2023-09-01 |
| Platform | Stable | Core operations platform | 2023-08-01 |
| Health | Stable | Foundational, tested | 2023-07-15 |
| Intelligence | Beta | Active feature development | 2024-01-15 |
| Risk Zones | Beta | Effectiveness under evaluation | 2024-02-01 |
| Drift Analysis | Beta | Recent enhancement | 2024-01-01 |
| Testing | Experimental | Test/validation only | Ongoing |

## SDK Generation Impact

### Stable Operations
```typescript
// Full type safety, all features available
client.marketplace.listMarketplaceProducts()  // ✓ Recommended
```

## Observability Architecture Assessment

### Current Foundation ✅
The first observability increment has established the core primitives needed for production operation:
- Request correlation via `X-Request-Id`
- Shared structured logging helpers
- Gateway and authentication instrumentation
- Request and error counters
- Health/readiness endpoints
- Prometheus-compatible metrics endpoints

These are the foundational building blocks that later capabilities such as tracing, dashboards, alerting, and SLOs depend on.

### Recommended Next Priorities
1. ✅ Distributed trace propagation across services (Complete)
2. ✅ Metric labels for route, method, status, and tenant context (Complete)
3. ✅ Latency histograms for P50/P95/P99 measurements (Complete)
4. ✅ Structured log enrichment with trace IDs, tenant IDs, and route metadata (Complete)
5. 🔄 Metadata enrichment (In Progress) — Ensuring consistent fields across all logs and metrics
6. 📋 Grafana dashboards and alert rules tied to the new metrics (Planned)

### Metadata Enrichment Pattern ✅
Every log entry and metric automatically includes:

**Standard Metadata**:
- `service` — Service name
- `version` — Service version (e.g., "7.3")
- `environment` — Deployment environment (e.g., "local", "staging", "production")
- `instance` — Hostname or instance identifier
- `request_id` — Unique request identifier

**Trace Context**:
- `trace_id` — Distributed trace identifier
- `span_id` — Current service span within trace
- `parent_span_id` — Parent span in call chain

**Request Context** (when available):
- `tenant_id` — Tenant identifier
- `user_id` — User identifier (in auth logs)
- `method` — HTTP method
- `path` — Request path
- `status` — HTTP status code

**Metric Labels**:
- `method` — HTTP method (GET, POST, etc.)
- `route` — API route pattern
- `status` — Status class (2xx, 4xx, 5xx)
- `service` — Service name

Example log entry:
```json
{
  "service": "gateway",
  "version": "7.3",
  "environment": "local",
  "instance": "api-server-01",
  "request_id": "708e39871a4e7fdd",
  "trace_id": "8fcbb1e48816da933a48638db5b146d6",
  "span_id": "296311274a56a534",
  "tenant_id": "demo-tenant",
  "timestamp": "2026-06-26T08:36:40+00:00",
  "level": "info",
  "message": "request_completed",
  "method": "GET",
  "path": "/api/v1/health",
  "status": 200,
  "latency_ms": 12
}
```

Example metric with labels:
```
gateway_requests_total{method="GET",route="/health",status="2xx",service="gateway"} 42
gateway_request_duration_seconds_bucket{method="GET",route="/health",status="2xx",service="gateway",le="0.05"} 35
```

### Suggested Maturity View for Observability
```yaml
x-observability:
  status: partial-production
  layers:
    telemetry-foundation:
      status: complete
      includes: [request-ids, trace-propagation, structured-logs, labeled-metrics, histograms]
    platform-operations:
      status: planned
      includes: [readiness-probes, latency-percentiles, error-rates, throughput]
      roadmap: [7.3.4-monitoring-stack, 7.3.5-dashboards]
    business-intelligence:
      status: planned
      includes: [effectiveness-metrics, drift-detection, anomaly-analysis]
      roadmap: [7.3.5-dashboards, 7.4-audit-layer]
```

### Next Implementation Phases

**7.3.4 — Monitoring Stack**
- Docker Compose for Prometheus, Grafana, Alertmanager
- Prometheus scrape configuration
- Service discovery and target management

**7.3.5 — Dashboards**
Create domain-specific Grafana dashboards:
- **Gateway**: Requests/sec, error rate, P95 latency
- **Authentication**: Login success/failure, token issuance, JWT validation
- **Marketplace**: Plugin installs, install failures, marketplace throughput
- **Intelligence**: Effectiveness score, drift anomalies, recommendation acceptance, fleet stability
- **Operations**: Readiness score, recovery metrics, SLA compliance

**7.3.6 — Alerting**
Add actionable alert rules:
- Gateway 5xx error rate exceeds threshold
- P95 latency exceeds target
- Authentication failure rate spikes
- Operational Readiness Score falls below threshold
- Fleet Stability drops below target
- Intelligence Accuracy declines below expected level

### Sprint 7.4 Connection
The audit layer will build directly on this observability infrastructure by:
- Referencing `Trace-Id` and `Request-Id` in all decision records
- Enabling operators to trace decisions from originating request through intelligence engine to remediation outcome
- Leveraging existing structured logs for compliance reporting and incident investigation


### Beta Operations
```typescript
// Available but may change
client.intelligence.getIntelligenceHealth()  // ⚠️ May change
// SDK should include deprecation warnings in JSDoc
```

### Experimental Operations
```typescript
// Available for testing only
client.testing.createMarketplaceTest()  // 🔬 Experimental, may change
// SDK should include warnings in JSDoc and console
```

### Internal Operations
```typescript
// Not exposed in public SDKs
// Available only in internal admin SDK
```

## Documentation Impact

### API Reference
Show maturity badge for each operation:

```
🟢 STABLE (since 2023-06-15)
## List Marketplace Products
GET /api/v1/marketplace/products
operationId: marketplaceListMarketplaceProducts

Production-ready endpoint. Covered by semantic versioning.
```

```
🟡 BETA (expected stable: 2024-03-30)
## Get Intelligence Health
GET /api/v1/intelligence-health
operationId: intelligenceGetIntelligenceHealth

Public preview. May change based on feedback.
Provide feedback: https://github.com/...
```

```
🔬 EXPERIMENTAL
## Test Scenario
POST /api/v1/marketplace/test/scenario
operationId: testingCreateMarketplacetest

For testing purposes only. Subject to change or removal.
```

## Transition Path

### Stable → Stable
No action required.

### Beta → Stable
1. Confirm >3 months deployment
2. Verify >1000 req/day production traffic
3. Confirm >80% SDK usage adoption
4. Remove `x-stabilityExpected`
5. Publish release notes highlighting stabilization

### Beta → Deprecated
1. Set `x-deprecationPath` to recommended alternative
2. Publish deprecation notice with migration window (6+ months)
3. Add deprecation warning to SDK generated docs
4. After migration window, move to "Internal"

### Experimental → Stable/Beta/Deprecated
1. Review feedback from `x-feedback` channel
2. Complete required approvals (`x-requiredApprovals`)
3. Update maturity level
4. Publish transition release notes

### Any → Internal
1. Publish deprecation notice (12-month window)
2. Move to internal SDK only
3. Remove from public OpenAPI spec after deprecation window

## Governance Rules

### Operation Creation
Every new operation **must be tagged** with maturity level upon creation.
- Default: `experimental`
- Override with domain approval if stability is expected

### Operation Updates
1. **Schema changes** to `experimental`/`internal` operations: OK
2. **Schema changes** to `beta` operations: Requires minor version bump + deprecation notice
3. **Schema breaking changes** to `stable` operations: Requires major version bump + 6-month deprecation window
4. **Operaion removal** from `stable`: Requires 12-month deprecation window

### Review Cadence
- **Monthly**: Review `experimental` operations for promotion/removal
- **Quarterly**: Review `beta` operations for stability readiness
- **Semi-annually**: Review maturity map for deprecations

## CI/CD Checks

### Prevent Inadvertent Stability Changes
```python
# Fail if:
# - Stable operation loses x-maturity: stable
# - Beta operation promotes to stable without approval
# - Any operation lacks x-maturity field
```

### Enforce Maturity Rules
```python
# For internal operations:
# - Must not be in public SDK export
# - Must have x-audience: internal-only

# For experimental operations:
# - Must not have >2 months deployment
# - Must have clear x-requiredApprovals
```

## Communication Strategy

### For SDK Consumers
1. **Documentation**: Clearly mark operation maturity in API docs
2. **Changelog**: Highlight maturity transitions in release notes
3. **Deprecations**: Announce with 6-12 month lead time
4. **Migration**: Provide examples and guides for transitions

### For Internal Teams
1. **Metrics**: Track operations by maturity level (% stable)
2. **Targets**: Increase stable operations 5-10% per quarter
3. **Reviews**: Monthly operation health reviews
4. **Training**: Educate teams on maturity standards

## Success Metrics

| Metric | Target |
|--------|--------|
| % Stable operations | 60% (current target: reach by Q2) |
| % Beta operations | 30% |
| % Experimental/Internal | 10% |
| Average time to stability | 3-6 months |
| Unplanned deprecations | < 5% |
| SDK user satisfaction | > 4.5/5 |
| Documentation completeness | 100% |

## Next Steps

1. **Implement maturity metadata** in all operations (Week 1)
2. **Add CI checks** for maturity validation (Week 1)
3. **Update SDK docs** with maturity badges (Week 2)
4. **Communicate transitions** to stakeholders (Week 2)
5. **Establish review cadence** (Ongoing)
