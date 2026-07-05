# Phase 3: Cross-Tenant Intelligence & Fleet Trend Analytics

## Overview
Phase 3 extends Operations Center from reactive dashboard (Phase 2) to proactive intelligence platform. This phase enables platform operators to:

- **Detect anomalies** across the fleet (patterns, outliers, sudden changes)
- **Predict risks** (trending health, volatility spikes, drift propagation)
- **Compare tenants** against fleet baselines and peer groups
- **Analyze trends** over time (7d, 30d, 90d moving windows)
- **Recommend actions** based on health patterns and peer benchmarks

## Architecture

### Data Model Extensions

#### TimeSeriesMetric
```typescript
interface TimeSeriesMetric {
  timestamp: ISO8601;           // Hourly aggregate point
  tenant_id: string;
  health_score: number;
  install_count: number;
  drift_status: 'none' | 'governance' | 'revocation';
  volatility_score: number;
  critical_findings_count: number;
  remediation_actions_count: number;
}
```

#### FleetBenchmark
```typescript
interface FleetBenchmark {
  metric: string;               // 'health_score' | 'volatility' | 'drift_rate' etc
  period: 'hourly' | 'daily' | 'weekly';
  p10: number;                  // 10th percentile
  p25: number;                  // 25th percentile
  p50: number;                  // Median
  p75: number;                  // 75th percentile
  p90: number;                  // 90th percentile
  mean: number;
  stddev: number;
  min: number;
  max: number;
  timestamp: ISO8601;
}
```

#### TenantAnomalies
```typescript
interface TenantAnomaly {
  tenant_id: string;
  anomaly_type: 'sudden_drop' | 'volatility_spike' | 'drift_change' | 'peer_outlier';
  severity: 'low' | 'medium' | 'high' | 'critical';
  detected_at: ISO8601;
  metric: string;
  previous_value: number;
  current_value: number;
  baseline_expected: number;
  deviation_percent: number;
  confidence: number;           // 0-1, statistical confidence
}
```

#### TrendSegment
```typescript
interface TrendSegment {
  tenant_id: string;
  period_start: ISO8601;
  period_end: ISO8601;
  duration_hours: number;
  metric: string;
  start_value: number;
  end_value: number;
  change_percent: number;
  direction: 'improving' | 'stable' | 'degrading';
  velocity: number;             // Change per hour
  volatility: number;           // Std dev of changes
  is_statistically_significant: boolean;
}
```

### Core Intelligence Queries

#### Query 1: Fleet Trend Analysis
**Purpose**: Detect fleet-wide health patterns  
**SQL-like pseudocode**:
```sql
SELECT 
  period,
  AVG(health_score) as avg_health,
  MIN(health_score) as min_health,
  MAX(health_score) as max_health,
  STDDEV(health_score) as volatility,
  COUNT(CASE WHEN health_score < 60 THEN 1 END) as at_risk_count
FROM timeseries_metrics
GROUP BY DATE_TRUNC('hour', timestamp)
ORDER BY timestamp DESC
LIMIT 24  -- Last 24 hours
```

#### Query 2: Peer Comparison Groups
**Purpose**: Identify similar tenants for benchmarking  
**Logic**:
```
- Tenants with same scale (install_count within 50%)
- Similar drift patterns (both governance, both revocation, etc)
- Within health band (±20 points)
- Calculate median health of peer group
- Identify outliers: tenant health > 1.5σ from peer median
```

#### Query 3: Anomaly Detection
**Purpose**: Flag unusual behavior  
**Detectors**:
1. **Sudden drop**: Health decreased >15 points in last 6 hours
2. **Volatility spike**: Last 24h stddev > 2x historical baseline
3. **Drift acceleration**: New drifted tenants > 20% increase in 24h
4. **Peer outlier**: Health 2+ standard deviations from peer group median

#### Query 4: Trend Direction
**Purpose**: Calculate velocity and trajectory  
**Logic**:
```
- Fit linear regression to last 7 days of health scores
- Extract slope (velocity) and R² (confidence)
- Classify: improving (slope > 1), stable (|slope| < 1), degrading (slope < -1)
- Project forward 7 days: final_health = current + (slope × 7)
- Confidence = R² value
```

### Phase 3 Endpoints

#### GET `/api/v1/marketplace/platform/timeseries`
**Query params**:
- `tenant_id` (optional): specific tenant or fleet-wide if omitted
- `metric` (optional): 'health_score' | 'volatility' | 'drift_rate' (default: all)
- `period` (optional): 'hourly' | 'daily' | 'weekly' (default: hourly)
- `days_back` (optional): 1-90 (default: 7)

**Response**:
```json
{
  "tenant_id": "optional-tenant-id",
  "metric": "health_score",
  "period": "hourly",
  "data_points": [
    {
      "timestamp": "2026-06-25T08:00:00Z",
      "health_score": 95,
      "install_count": 5,
      "volatility_score": 1.2,
      "at_risk": false,
      "drift_status": "none"
    }
  ],
  "statistics": {
    "current_value": 95,
    "7d_avg": 92,
    "7d_min": 85,
    "7d_max": 98,
    "7d_stddev": 3.5,
    "trend_direction": "improving",
    "trend_velocity": 0.5,
    "trend_confidence": 0.92
  },
  "cached_at": "2026-06-25T08:31:00Z"
}
```

#### GET `/api/v1/marketplace/platform/benchmarks`
**Query params**:
- `metric` (optional): 'health_score' | 'volatility' | 'install_count' (default: health_score)
- `period` (optional): 'hourly' | 'daily' | 'weekly' (default: daily)

**Response**:
```json
{
  "metric": "health_score",
  "period": "daily",
  "current_benchmark": {
    "p10": 45,
    "p25": 60,
    "p50": 85,
    "p75": 92,
    "p90": 97,
    "mean": 82,
    "stddev": 12.5,
    "min": 25,
    "max": 100
  },
  "percentile_counts": {
    "critical": 1,      // < p10
    "at_risk": 2,       // p10-p25
    "fair": 2,          // p25-p50
    "good": 1,          // p50-p75
    "healthy": 1        // p75+
  },
  "cached_at": "2026-06-25T08:00:00Z"
}
```

#### GET `/api/v1/marketplace/platform/anomalies`
**Query params**:
- `severity` (optional): 'low' | 'medium' | 'high' | 'critical' (default: medium+)
- `hours_back` (optional): 1-168 (default: 24)
- `tenant_id` (optional): filter to specific tenant

**Response**:
```json
{
  "anomalies": [
    {
      "tenant_id": "tenant-abc",
      "anomaly_type": "sudden_drop",
      "severity": "high",
      "detected_at": "2026-06-25T06:15:00Z",
      "metric": "health_score",
      "previous_value": 92,
      "current_value": 75,
      "baseline_expected": 88,
      "deviation_percent": -14.8,
      "confidence": 0.95,
      "recommended_action": "Review recent deployments on tenant-abc"
    }
  ],
  "count": 1,
  "critical_count": 0,
  "cached_at": "2026-06-25T08:31:00Z"
}
```

#### GET `/api/v1/marketplace/platform/peers`
**Query params**:
- `tenant_id`: the tenant to find peers for
- `group_by` (optional): 'install_count' | 'health_band' | 'drift_pattern' (default: all)

**Response**:
```json
{
  "tenant_id": "tenant-abc",
  "peer_count": 3,
  "peer_groups": {
    "by_install_count": {
      "peer_ids": ["tenant-def", "tenant-ghi"],
      "peer_median_health": 89,
      "this_tenant_health": 92,
      "percentile_in_group": 75,
      "status": "above_peers"
    },
    "by_health_band": {
      "band": "healthy_85-95",
      "peer_ids": ["tenant-def", "tenant-ghi", "tenant-jkl"],
      "median_health": 90,
      "this_tenant_health": 92
    }
  },
  "cached_at": "2026-06-25T08:31:00Z"
}
```

#### GET `/api/v1/marketplace/platform/predictions`
**Query params**:
- `horizon_days` (optional): 1-30 (default: 7)
- `confidence_threshold` (optional): 0-1 (default: 0.7)

**Response**:
```json
{
  "predictions": [
    {
      "tenant_id": "tenant-abc",
      "current_health": 92,
      "predicted_health_7d": 88,
      "trend": "degrading",
      "confidence": 0.82,
      "primary_factor": "increasing_volatility",
      "risk_level": "medium",
      "recommended_action": "Monitor for drift"
    }
  ],
  "cached_at": "2026-06-25T08:31:00Z"
}
```

### Phase 3 UI Components

#### 1. Fleet Health Timeline Card
- **Location**: Operations Center main section
- **Shows**: Line chart of fleet average health over last 7 days
- **Interaction**: Click to drill down to daily view
- **Data source**: `/api/v1/marketplace/platform/timeseries`

#### 2. Health Distribution Widget
- **Location**: KPI Summary section (new card)
- **Shows**: Histogram/distribution of tenant health scores
- **Color-coded**: Red (critical), Orange (at-risk), Yellow (fair), Green (healthy)
- **Interaction**: Click to filter overview table by band

#### 3. Anomalies Alert Panel
- **Location**: Above Rankings section
- **Shows**: High/critical anomalies with severity badges
- **Interaction**: Click anomaly to navigate to tenant details
- **Auto-refresh**: Every 30 seconds

#### 4. Peer Comparison Card
- **Location**: Rankings section, new "Your Fleet Position" card
- **Shows**: This tenant vs peer median (bar chart)
- **Interaction**: Hover to see peer details

#### 5. Trend Indicator Panel
- **Location**: Tenant rows (new column)
- **Shows**: ↑ Improving / → Stable / ↓ Degrading arrow
- **Tooltip**: Velocity and confidence

#### 6. Prediction Dashboard
- **Location**: New "Risk Forecast" section
- **Shows**: Predicted health in 7 days for at-risk tenants
- **Interaction**: Click tenant to see detailed forecast

### Phase 3 Implementation Roadmap

#### Week 1: Time Series Foundation
- [ ] Create `timeseries_metrics` table/collection
- [ ] Implement hourly aggregation job
- [ ] Build `/platform/timeseries` endpoint
- [ ] Add basic line chart to Operations Center

#### Week 2: Benchmarks & Peer Groups
- [ ] Implement percentile calculations
- [ ] Build `/platform/benchmarks` endpoint
- [ ] Build `/platform/peers` endpoint
- [ ] Add distribution widget to KPI cards

#### Week 3: Anomaly Detection
- [ ] Implement anomaly detectors (4 types above)
- [ ] Build `/platform/anomalies` endpoint
- [ ] Add anomalies alert panel
- [ ] Set up notification rules

#### Week 4: Trends & Predictions
- [ ] Implement linear regression for trends
- [ ] Build `/platform/predictions` endpoint
- [ ] Add trend indicators to overview table
- [ ] Add prediction dashboard

#### Week 5: Advanced Analytics
- [ ] Correlation analysis (e.g., "tenants with governance drift trend degraded health")
- [ ] Root cause analysis (what factors most influence health changes)
- [ ] Optimization recommendations (e.g., "upgrade most critical tenants")
- [ ] Export analytics reports

### Data Storage Strategy

#### Option 1: File-Based (MVP, current)
```
services/data/
  timeseries/
    tenant-abc.jsonl      # Line-delimited JSON, append-only
    fleet-aggregate.jsonl
  benchmarks/
    daily-latest.json
    hourly-latest.json
```

**Pros**: Simple, no DB setup  
**Cons**: Slow queries, disk intensive, no indexing  
**Use**: MVP validation, < 1000 tenants

#### Option 2: SQLite (Recommended for Phase 3)
```sql
CREATE TABLE timeseries_metrics (
  id INTEGER PRIMARY KEY,
  tenant_id TEXT NOT NULL,
  metric TEXT NOT NULL,
  value REAL NOT NULL,
  recorded_at DATETIME NOT NULL,
  FOREIGN KEY(tenant_id) REFERENCES tenants(id),
  INDEX idx_tenant_metric_time (tenant_id, metric, recorded_at)
);

CREATE TABLE fleet_benchmarks (
  id INTEGER PRIMARY KEY,
  metric TEXT NOT NULL,
  period TEXT NOT NULL,
  p10 REAL, p25 REAL, p50 REAL, p75 REAL, p90 REAL,
  mean REAL, stddev REAL,
  calculated_at DATETIME NOT NULL,
  UNIQUE(metric, period, calculated_at)
);

CREATE TABLE tenant_anomalies (
  id INTEGER PRIMARY KEY,
  tenant_id TEXT NOT NULL,
  anomaly_type TEXT NOT NULL,
  severity TEXT NOT NULL,
  detected_at DATETIME NOT NULL,
  details JSON NOT NULL,
  FOREIGN KEY(tenant_id) REFERENCES tenants(id),
  INDEX idx_tenant_severity (tenant_id, severity, detected_at)
);
```

**Pros**: Fast queries, aggregations, proper indexing  
**Cons**: Requires setup, slight performance overhead  
**Use**: Production, 1000+ tenants

### Caching & Performance

#### Cache Layers
1. **Dashboard cache** (5 min TTL)
   - Current health, rankings, drift summary
   - Invalidate on: new anomaly, threshold breach

2. **Benchmark cache** (1 hour TTL)
   - Fleet percentiles, distribution
   - Invalidate on: hourly aggregate job complete

3. **Timeseries cache** (15 min TTL, 7-day window)
   - Health history for UI charts
   - Lazy-load older data on demand

4. **Anomaly cache** (2 min TTL, 24-hour window)
   - Recent anomalies list
   - Invalidate on: new anomaly detected

#### Aggregation Jobs
```bash
# Hourly: Calculate aggregate metrics
php /services/marketplace/jobs/hourly-aggregation.php

# Daily: Calculate benchmarks
php /services/marketplace/jobs/daily-benchmarks.php

# Continuous: Anomaly detection
php /services/marketplace/jobs/anomaly-detector.php --continuous
```

### Testing Strategy

#### Unit Tests (Calculation Verification)
- [ ] Percentile calculation accuracy
- [ ] Trend regression R² validation
- [ ] Anomaly detector true/false positive rates
- [ ] Peer group formation logic

#### Integration Tests (API Contract)
- [ ] Timeseries endpoint pagination
- [ ] Benchmark endpoint statistical validity
- [ ] Anomaly endpoint response time < 500ms
- [ ] Peers endpoint consistency with benchmarks

#### E2E Tests (UI + Data)
- [ ] Chart renders with correct data
- [ ] Anomaly alert appears within 2 minutes
- [ ] Peer comparison updates on data change
- [ ] Drill-down from prediction to tenant details

### Success Metrics

By end of Phase 3, Operations Center should:

1. ✅ **Visibility**: Show fleet trends over 7/30/90 day windows
2. ✅ **Proactivity**: Alert on anomalies within 2 minutes of occurrence
3. ✅ **Benchmarking**: Enable tenants to see how they rank vs peers
4. ✅ **Prediction**: Forecast at-risk tenants 7+ days in advance
5. ✅ **Performance**: Dashboard loads < 1 second, anomalies < 2 second
6. ✅ **Governance**: All intelligence backed by peer/peer test suite

### Phase 4 Considerations (Future)

- Machine learning: Automated pattern detection, root cause analysis
- Automation: Self-healing policies (e.g., auto-remediate common drift)
- Integration: Slack/Teams alerts, PagerDuty escalation
- Multi-tenancy: Each tenant sees only their fleet view
- Compliance: Audit trail for all operations center actions

---

## Transition from Phase 2 to Phase 3

### Definition of Done for Phase 2
- ✅ Operations Center loads with KPI cards
- ✅ Tenant overview table displays all tenants
- ✅ Rankings show healthiest, improved, risk tenants
- ✅ Drift summary categorizes fleet
- ✅ Drill-down navigates to tenant details
- ✅ Playwright test coverage validates UI/data
- ✅ Synthetic scenarios validate calculations
- ✅ Health calculation verified mathematically

### Ready for Phase 3 When
- ✅ All Phase 2 tests passing
- ✅ Operations Center stable for 48 hours in dev
- ✅ No regressions in existing Marketplace UI
- ✅ Team agreement on time series storage approach
- ✅ Performance baseline established (dashboard < 1s)

### First Task in Phase 3
Implement time series aggregation job + `/platform/timeseries` endpoint to capture historical health data. This enables trend analysis, the foundation for all Phase 3 features.

---

**Document Version**: 1.0  
**Last Updated**: 2026-06-25  
**Status**: Ready for Phase 3 Implementation
