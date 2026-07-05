# Sprint 6 Readiness: Cross-Tenant Intelligence & Fleet Analytics

**Status**: ✅ READY FOR EXECUTION  
**Date**: 2026-06-25  
**Foundation**: Phase 3 Architecture, Operations Center, Time Series Analytics  

---

## Governance & Quality Gates ✓

### Test Coverage
- ✅ **Edge-Case Regression Tests** (7 PHP unit tests, 8 Playwright API tests)
  - Constant series, single values, empty series, increasing/decreasing trends
  - Low variance, two-value series, parameter variations
  - **Total**: 18 test cases, all passing

- ✅ **Trend Contract Tests** (4 PHP unit tests)
  - Constant series contract: `direction='stable'`, `velocity=0`, `confidence=0`
  - Statistics contract: `stddev=0` for constant series
  - Endpoint response contract validation
  - Fatal error regression guard
  - **Total**: 4 test cases, all passing

- ✅ **Original Regression Tests** (3 Playwright API tests)
  - Fleet health timeseries data
  - Tenant-specific time series queries
  - Weekly aggregation

- ✅ **Core Functionality Tests** (Platform APIs)
  - `/api/v1/marketplace/platform/dashboard` - fleet overview
  - `/api/v1/marketplace/platform/overview` - tenant rankings
  - `/api/v1/marketplace/platform/rankings` - drift rankings
  - `/api/v1/marketplace/platform/drift` - drift summary
  - `/api/v1/marketplace/platform/timeseries` - fleet/tenant analytics

### CI Integration Ready
- All tests runnable via PHPUnit and Playwright
- Contract tests will catch semantic regressions
- Edge-case suite prevents future crashes

---

## Technical Foundation Ready ✓

### Core Microservices
1. **Marketplace Service** (`services/marketplace/server.php`)
   - Multi-tenant aware
   - Platform aggregation endpoints
   - Time series analytics
   - Operations Center UI route

2. **Time Series Helper** (`services/marketplace/TimeSeriesHelper.php`)
   - Hourly snapshot recording
   - Tenant history loading
   - Period resampling (hourly → weekly → monthly)
   - Trend calculation with zero-variance guard
   - Statistics aggregation

3. **Platform Data Layer** (`services/data/`)
   - Fleet aggregation cache (`marketplace_platform_cache.json`)
   - Tenant history files (`marketplace_tenant_history_*.json`)
   - Time series data (`timeseries/fleet-aggregate.jsonl`)

### Data Structures Validated
- Platform cache with health_score, drift counts, volatility, tenant rankings
- Tenant history with hourly snapshots
- Time series format: `{hour, metric_value, ...statistics}`
- Statistics format: `{7d_avg, 7d_min, 7d_max, 7d_stddev, trend_direction, trend_velocity, trend_confidence}`

---

## Sprint 6 Scope: Cross-Tenant Intelligence

### Feature 1: Health vs Volatility Matrix (Scatter Plot)

**Objective**: Provide operators immediate visibility into tenant health classification

**Data Points**:
- X-axis: Fleet Volatility (0-100)
- Y-axis: Tenant Health Score (0-100)
- Points: Individual tenants colored by risk level

**Risk Classification**:
```
Healthy (zone 1):     health > 75, volatility < 30
Unstable (zone 2):    health > 75, volatility >= 30
At-Risk (zone 3):     50 <= health <= 75, volatility any
Degrading (zone 4):   health < 50, volatility >= 30
Critical (zone 5):    health < 50, volatility < 30
```

**Implementation Approach**:
1. Extend Operations Center UI with SVG scatter plot container
2. Fetch `/api/v1/marketplace/platform/overview` for tenant list and metrics
3. Render points dynamically with hover tooltips
4. Color-code by risk zone

**UI Component Location**: `services/marketplace/server.php` (operations-center route)

---

### Feature 2: Tenant Trend Timeline (Line Chart)

**Objective**: Show trend trajectory for each tenant over selected period

**Data Points**:
- Time series for individual tenant via `/api/v1/marketplace/platform/timeseries?tenant_id=X`
- Multiple metrics: health_score, at_risk_count, critical_count, remediations_7d
- Period selection: 1d, 3d, 7d, 14d, 30d

**Implementation Approach**:
1. Add tab selector for metrics in Operations Center
2. Use Playwright Chart.js or similar for line rendering
3. Display trend direction and confidence alongside chart
4. Highlight anomalies (sudden drops/spikes)

---

### Feature 3: Drift Analysis Grid (Tenant Status Dashboard)

**Objective**: Rank tenants by drift risk, remediation urgency, and health

**Data Points**:
- Tenant ID, Name, Health, Drift Status, Remediations (7d), Risk Level
- Sortable columns: Health (desc), Drift Count (desc), Remediation Count (desc)

**Implementation Approach**:
1. Extend Operations Center with data grid
2. Fetch `/api/v1/marketplace/platform/rankings` for initial sort
3. Allow drill-down to tenant-specific trend view
4. Show remediation preview (`GET /api/v1/marketplace/tenants/{id}/remediation-preview`)

---

### Feature 4: Intelligence Engine Health Check

**Objective**: Validate the health of the analytics pipeline itself

**Metrics**:
- Last aggregation timestamp
- Cache staleness (compare to current time)
- Time series data points in fleet-aggregate.jsonl
- Tenant history file count and age

**Implementation Approach**:
1. Add diagnostics endpoint: `GET /api/v1/marketplace/platform/health`
2. Return cache status, data freshness, error counts
3. Used by Operations Center to show data pipeline health

---

## Immediate Next Steps

### Phase 1: Visualization Components (1-2 days)
```javascript
// Operations Center enhancements
const operations_center_enhancements = {
  1_scatter_plot: 'Health vs Volatility Matrix',
  2_line_chart: 'Tenant Trend Timeline',
  3_data_grid: 'Drift Analysis Grid',
  4_diagnostics: 'Intelligence Health Check'
};
```

### Phase 2: Interactive Features (1-2 days)
- Drill-down from scatter plot to tenant trend
- Filter by risk zone
- Export tenant report
- Real-time refresh toggle

### Phase 3: Testing & Documentation (1 day)
- Playwright UI tests for each chart interaction
- API contract tests for new endpoints
- User guide for Operations Center
- Trend semantics documentation

---

## Risk Mitigation

### Zero-Variance Protection ✓
- Contract test ensures constant series never cause fatal errors
- Test will fail on any regression
- Currently: all edge cases passing

### Data Freshness
- Platform cache has `cached_at` timestamp
- Hourly aggregation job (`jobs/hourly-aggregation.php`) updates cache
- Monitor for stale data (> 2h without update)

### Multi-Tenant Isolation
- All queries filter by `tenant_id` parameter
- Tenant history loaded from separate files
- No cross-tenant data leakage in tests

---

## Definition of Done for Sprint 6

- [ ] Scatter plot renders with 100+ tenants
- [ ] Hover tooltips show tenant details (name, health, volatility)
- [ ] Risk zones colored correctly
- [ ] Line chart shows historical trend with 7 metrics
- [ ] Trend direction badge displayed (stable/improving/degrading)
- [ ] Data grid sortable by health, drift, remediation count
- [ ] Drill-down from any chart to detailed tenant view
- [ ] All new features covered by Playwright UI tests
- [ ] Contract tests remain passing
- [ ] No performance degradation (< 500ms response time for all APIs)
- [ ] Documentation updated

---

## Architecture Diagram: Sprint 6 Stack

```
Operations Center UI
├── Health vs Volatility Scatter Plot
│   └── GET /api/v1/marketplace/platform/overview → tenant data
├── Tenant Trend Timeline (Line Chart)
│   └── GET /api/v1/marketplace/platform/timeseries → historical data
├── Drift Analysis Grid
│   └── GET /api/v1/marketplace/platform/rankings → sorted tenant list
└── Intelligence Health Check
    └── GET /api/v1/marketplace/platform/health → diagnostics

Platform Aggregation Layer
├── Fleet Overview (health_score, at_risk_count, volatility)
├── Tenant Rankings (sorted by health, drift, risk)
├── Time Series Analytics (hourly snapshots with trend)
└── Drift Summary (governance, revocation drift counts)

Data Layer
├── Platform Cache (marketplace_platform_cache.json)
├── Tenant History (marketplace_tenant_history_*.json)
└── Time Series Logs (timeseries/fleet-aggregate.jsonl)

Governance
├── Trend Contract Tests (semantics locked in)
├── Edge-Case Regression Tests (robustness)
└── API Contract Tests (schema validation)
```

---

## Key Decisions

1. **Scatter Plot over Trend Timeline First**: Provides immediate operational insight
2. **Risk Zones**: Stakeholders need quick visual classification
3. **Contract Tests in CI**: Prevent drift semantics regression
4. **Tenant Drill-Down**: Enable quick investigation of anomalies
5. **10-Minute Cache TTL**: Balance freshness vs database load

---

## Success Metrics for Sprint 6

| Metric | Target |
|--------|--------|
| Scatter plot render time | < 200ms |
| API response time | < 500ms |
| Test coverage | > 90% |
| Contract test pass rate | 100% |
| Operations Center page load | < 1s |
| Tenant drill-down latency | < 300ms |

---

## Continuation: Sprint 7 & Beyond

- **Sprint 7**: Anomaly detection (sudden health drops, volatility spikes)
- **Sprint 8**: Predictive health (trend extrapolation, SLA risk)
- **Sprint 9**: Automated remediation suggestions
- **Sprint 10**: Cross-tenant benchmarking & performance comparisons

---

**Recommended Action**: 
✅ Begin Sprint 6 immediately with scatter plot + trend timeline
✅ All governance, testing, and operations foundations are solid
✅ Contract tests will ensure long-term semantic stability
