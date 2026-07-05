# Operational Readiness Suite — Complete Implementation

## Overview

The Operational Readiness Suite is a comprehensive framework for validating platform reliability before production deployment. It runs on every PR and produces:

1. **Load profiles** (ramp, burst, endurance, mixed workload)
2. **Security validation** (tenant isolation, auth matrix, permission checks)
3. **Recovery verification** (backup/restore with RTO/RPO measurement)
4. **Observability checks** (health endpoints, metrics)
5. **Readiness Score** (0-100 composite metric)
6. **Recovery SLA Gate** (pass/warn/fail)

---

## What Gets Tested

### Load Testing

| Profile | Duration | Concurrency | Purpose |
|---------|----------|-------------|---------|
| **Ramp** | ~40s | 5→10→20→40 | Assess behavior under increasing load |
| **Burst** | ~10s | 100 | Test spike capacity handling |
| **Endurance** | 60s | 10 | Check for memory leaks and degradation |
| **Mixed** | ~30s | 15 | Realistic multi-endpoint operations |

**Metrics captured**: P50, P95, P99 latency; throughput; error rate

### Security Validation

| Test | Purpose | Success Criteria |
|------|---------|------------------|
| **Tenant Isolation** | Data not leaked between tenants | Different tenant IDs return different data |
| **Auth Matrix** | Endpoints require proper auth | All auth checks present |
| **Remediation Permissions** | Only authorized roles can remediate | Proper 40x responses on unauthorized access |

### Recovery Testing (RTO/RPO)

| Metric | Target | Severity | Purpose |
|--------|--------|----------|---------|
| **Average RTO** | < 60s | FAIL if exceeded | Restore time under normal conditions |
| **P95 RTO** | < 90s | WARN if exceeded | Restore time at 95th percentile |
| **Average RPO** | < 30s | FAIL if exceeded | Data loss window (backup completion time) |
| **Success Rate** | > 99% | FAIL if below | Backup/restore reliability |

**Definitions:**
- **RTO (Recovery Time Objective):** Time to restore from backup
- **RPO (Recovery Point Objective):** Time taken to create a backup (data loss window)

### Observability Checks

| Check | Purpose |
|-------|---------|
| **/metrics** | Prometheus-style metrics endpoint |
| **/health** | Readiness/liveness probe |
| **/operations-center** | Core platform availability |

---

## Operational Readiness Score

Composite metric combining four equally weighted areas:

```
Operational Readiness Score = (30% Load) + (30% Security) + (20% Recovery) + (20% Observability)
```

### Scoring

| Area | Pass | Warn | Fail |
|------|------|------|------|
| **Load** | <2% error rate | 2-5% | >5% |
| **Security** | All checks pass | - | Any check fails |
| **Recovery** | All SLAs met | P95 exceeds threshold | Average RTO/RPO exceeds target |
| **Observability** | All endpoints available | - | Unavailable |

### Interpretation

| Score | Status | Meaning |
|-------|--------|---------|
| **85-100** | ✅ EXCELLENT | Deployment ready |
| **70-84** | 🔵 HEALTHY | Monitor, no blocking issues |
| **60-69** | 🟡 WARNING | Review needed before deployment |
| **<60** | 🔴 CRITICAL | Do not deploy, fix issues first |

---

## Recovery Gate

Specialized evaluation of backup/restore SLAs:

### Severity Levels

**PASS** ✅
- Average RTO < 60s
- P95 RTO < 90s
- Average RPO < 30s
- Recovery success rate ≥ 99%

**WARN** ⚠️
- P95 RTO exceeds threshold OR
- Isolated outlier in timing data
- Does NOT block CI (informational)

**FAIL** ❌
- Average RTO > 60s
- Average RPO > 30s
- Recovery success rate < 99%
- Blocks CI merging

---

## Running Locally

### Full Suite

```bash
npm run operational:readiness
```

Outputs:
- `operational-readiness/results/` — JSON results from each test
- `operational-readiness/report/operational-readiness.html` — visual report

### Individual Tests

```bash
# Load profiles
node operational-readiness/load/load-profile-ramp.js
node operational-readiness/load/load-profile-burst.js
node operational-readiness/load/load-profile-endurance.js
node operational-readiness/load/load-profile-mixed.js

# Security
node operational-readiness/security/tenant-isolation.test.js

# Recovery
node operational-readiness/recovery/recovery-gate.js results/

# Full report generation
node operational-readiness/operational-readiness.js
```

---

## CI Integration

Every PR runs the full suite in GitHub Actions and publishes:

1. **operational-readiness-artifacts** — Build artifact containing results + HTML report
2. **Recovery gate evaluation** — Determines pass/warn/fail
3. **Readiness score trend** — Can be graphed over time

### CI Workflow

```
Checkout → Setup PHP → Start Server → Setup Node
  ↓
  Run Readiness Suite
  ↓
  Upload Artifacts
  ↓
  Evaluate Recovery SLAs
  ↓
  Gate: Fail on Critical Issues
```

---

## Interpreting Results

### HTML Report

The `operational-readiness.html` report includes:

1. **Overall Readiness Score** (0-100)
2. **Component breakdown** (Load, Security, Recovery, Observability)
3. **Recovery metrics detail** (RTO/RPO with SLA comparison)
4. **Per-test results** with links to detailed JSON

### Recovery Detail Example

```
Average RTO        38s   ✅ (target < 60s)
P95 RTO            54s   ✅ (target < 90s)
Average RPO         6s   ✅ (target < 30s)
Success Rate     100%   ✅ (target > 99%)
Worst-case RTO    71s   ⚠️  (informational)
────────────────────────
Status            PASS
```

### Failure Diagnosis

If recovery fails:

```json
{
  "recovery": {
    "avg_rto_ms": 125000,    // ❌ 125s > 60s target
    "p95_rto_ms": 180000,    // ❌ 180s > 90s threshold
    "avg_rpo_ms": 45000,     // ❌ 45s > 30s target
    "recovery_success_rate": 0.98,  // ❌ 98% < 99% target
    "severity": "fail"
  }
}
```

**Remediation:**
- Optimize restore process (add indexing, batch operations)
- Reduce backup size or frequency
- Increase server resources
- Investigate slow disk I/O

---

## Customizing Thresholds

Edit `operational-readiness/recovery/recovery-metrics.js`:

```javascript
const DEFAULT_THRESHOLDS = {
  avg_rto_target_ms: 60000,        // Change to your target
  p95_rto_target_ms: 90000,
  avg_rpo_target_ms: 30000,
  recovery_success_rate_target: 0.99
};
```

Then rebuild the report:

```bash
npm run operational:readiness
```

---

## Trend Tracking

To graph readiness scores over time:

1. Collect `operational-readiness.html` reports from each PR
2. Parse `results/summary.json` from each run
3. Plot overall score, component scores, and recovery metrics

Example script (coming in Phase 7.2):

```javascript
// Track score trend across PRs
const scores = [];
for (const run of prRuns) {
  scores.push({
    pr: run.number,
    score: run.summary.overall,
    timestamp: run.created_at
  });
}
```

---

## Known Limitations

- **Network dependent:** Assumes `http://127.0.0.1:8006` is available
- **Timing variance:** P95/P99 depend on system load; runs in CI may vary
- **Single-point test:** Doesn't test distributed scenarios
- **Backup size:** Tests assume small data file; scale testing deferred

---

## Next Steps

### Phase 7.2: Trend History
- Store historical readiness scores in artifact storage
- Graph score trends over recent PRs
- Alert on score degradation

### Phase 7.3: Advanced Profiles
- Distributed load (multi-worker)
- Connection pooling tests
- Cache efficiency profiling

### Phase 7.4: Performance SLAs
- Add latency SLAs to readiness gates (P95 < 500ms)
- Track throughput trends
- Memory consumption profiling

---

## Summary

The Operational Readiness Suite ensures every deployment is:

✅ Fast (load profiles validate performance)  
✅ Secure (tenant isolation and auth checked)  
✅ Reliable (backup/restore validated with SLAs)  
✅ Observable (health endpoints available)  
✅ Measurable (composite score enables governance)  

**Readiness scores make deployment decisions consistent and data-driven.**
