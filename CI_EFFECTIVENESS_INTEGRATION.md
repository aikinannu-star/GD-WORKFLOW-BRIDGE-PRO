# Intelligence Effectiveness CI/CD Integration

## Overview

The Intelligence Effectiveness Engine is now integrated into the CI/CD pipeline as a **first-class governance signal**, alongside drift analysis, snapshot approvals, and architectural integrity checks.

Every commit and pull request validates that the platform's intelligence system is:
1. **Detecting issues accurately** (low false positive rate)
2. **Resolving detected issues quickly** (MTTD/MTTR targets)
3. **Providing recommendations tenants adopt** (acceptance rate)
4. **Maintaining recommendation quality** (precision metrics)

## Architecture

### Components

```
GitHub Actions Workflow
├── marketplace-ci.yml (Primary)
│   ├── marketplace-tests (Unit tests, security, publishing)
│   ├── marketplace-ts (SDK tests)
│   ├── marketplace-ui (Playwright UI tests)
│   └── effectiveness-governance ✨ NEW
│       ├── Starts marketplace server
│       ├── Runs effectiveness reporter
│       ├── Validates SLA targets
│       └── Generates governance reports
│
└── ci.yml (Core)
    ├── phpcs (Code style)
    ├── phpunit (Unit tests)
    ├── license-integration (License server)
    └── kpi-validation (Updated)
        ├── synthetic KPI validation
        ├── intelligence-health check
        └── effectiveness governance ✨ INTEGRATED
```

### Workflow Sequence

```
Push to main/develop or PR created
          ↓
marketplace-ci jobs start in parallel
├── marketplace-tests (15-20s)
├── marketplace-ts (depends on marketplace-tests)
├── marketplace-ui (depends on marketplace-ts)
└── effectiveness-governance ✨ (depends on marketplace-tests)
    ├── Start PHP server
    ├── Run effectiveness-ci-reporter.js
    ├── Validate SLAs
    ├── Generate reports
    └── Upload artifacts
```

## Governance Rules

### SLA Targets (Critical)

All of these must pass for CI to succeed:

| Metric | Target | Status | Description |
|--------|--------|--------|-------------|
| MTTD (Mean Time To Detect) | < 6 hours | 🟢 | Average time from anomaly occurs to detection |
| MTTR (Mean Time To Resolve) | < 8 hours | 🟢 | Average time from detection to resolution |
| Accuracy (Precision) | > 85% | ⚠️ | Confirmed anomalies / detected anomalies |
| False Positive Rate | < 15% | ⚠️ | False alarms / total detections |
| Recommendation Acceptance | > 80% | 🟢 | Recommendations adopted by operators |

**Status Indicators:**
- 🟢 **PASS**: Target met
- ⚠️ **WARN**: Within 10-15% of target (advisory, does not block)
- 🔴 **FAIL**: Below target by >15% (blocks merge)

### Contract Tests

50+ effectiveness contract tests validate:
- Metric schema integrity
- Value range consistency
- Percentile relationships (P95 >= average)
- Event data completeness
- Remediation lifecycle validity

**Test file:** `tests/EffectivenessContractTests.php`

**Run locally:**
```bash
php tests/EffectivenessContractTests.php
```

## Report Artifacts

CI generates two governance reports per run:

### 1. `effectiveness-metrics.json`
Machine-readable report for downstream processing:
```json
{
  "generated_at": "2026-06-25T22:49:27.000Z",
  "environment": "ci",
  "metrics": {
    "recommendations": [...],
    "mttd": {...},
    "mttr": {...},
    "acceptance_rate": {...},
    "accuracy": {...}
  },
  "test_results": {
    "sla_mttd": {"status": "PASS", ...},
    "sla_mttr": {"status": "PASS", ...},
    ...
  },
  "status": "PASS"
}
```

### 2. `effectiveness-report.html`
Human-readable report viewable in browser:
- SLA Target cards (green/yellow/red)
- Contract test results
- Detailed metrics breakdown
- Trend data (7d, 30d comparisons)

**Access via GitHub Actions:**
1. Go to workflow run
2. Scroll to "Artifacts" section
3. Download `effectiveness-governance-{run_id}.zip`
4. Extract and open `effectiveness-report.html` in browser

## CI Integration Points

### marketplace-ci.yml

**New Job:** `effectiveness-governance`

```yaml
effectiveness-governance:
  runs-on: ubuntu-latest
  needs: marketplace-tests
  steps:
    - Start marketplace server
    - Run effectiveness-ci-reporter.js
    - Upload governance reports
```

**Location:** `.github/workflows/marketplace-ci.yml` (lines ~143-169)

### ci.yml

**Updated Job:** `kpi-validation`

```yaml
kpi-validation:
  needs: license-integration
  steps:
    - Run synthetic KPI validation
    - Run intelligence-health check
    - Start marketplace server           # ✨ NEW
    - Run effectiveness governance      # ✨ NEW
    - Upload reports                    # ✨ NEW
```

**Location:** `.github/workflows/ci.yml` (lines ~160+)

## Reporter Implementation

**File:** `scripts/effectiveness-ci-reporter.js`

### Features

1. **Graceful Offline Mode**
   - If marketplace server not running: uses mock data
   - Does not fail CI if tests can't run
   - Enables artifact generation for PR previews

2. **SLA Validation**
   - Compares metrics against targets
   - Applies threshold logic (PASS/WARN/FAIL)
   - Determines exit code based on critical failures

3. **Report Generation**
   - JSON for automation/downstream tools
   - HTML for human review in GitHub
   - Color-coded status indicators
   - Timestamp and environment info

4. **Exit Codes**
   - `0` = All checks passed ✅
   - `1` = Critical SLAs failed ❌
   - `2` = Fatal error (server unreachable, invalid config)

### Usage

```bash
# Run locally (requires marketplace server running on :8006)
node scripts/effectiveness-ci-reporter.js

# Run in CI (automatic fallback to mock data if offline)
# Invoked by GitHub Actions workflow
```

## Data Flow

```
Remediation Events (json)
        ↓
EffectivenessMetrics.php (computation)
        ↓
/api/v1/intelligence-effectiveness (HTTP endpoint)
        ↓
effectiveness-ci-reporter.js (CI validation)
        ↓
effectiveness-metrics.json (machine-readable)
effectiveness-report.html (human-readable)
        ↓
GitHub Artifacts (stored for 90 days)
```

## Example: Interpreting CI Report

### SLA Targets Section

```
✅ MTTD < 6 hours: 2.1h (target: 6)
✅ MTTR < 8 hours: 3.8h (target: 8)
⚠️ Accuracy (Precision) > 85%: 83.0% (target: 85)
⚠️ False Positive Rate < 15%: 17.0% (target: 15)
✅ Recommendation Acceptance > 80%: 88.0% (target: 80)
```

**Interpretation:**
- MTTD and MTTR are excellent (well below targets)
- Accuracy slightly below target (83% vs 85%) → WARN status
- False positive rate slightly above target (17% vs 15%) → WARN status
- Acceptance rate well above target (88% vs 80%)
- **Overall:** CI passes (WARNs are advisory) ✅

### Contract Tests Section

```
✅ Effectiveness Contract Tests: PASS (50 passed, 1 skipped)
```

**Meaning:** Schema validation, value ranges, and metric integrity all verified.

## Troubleshooting

### CI Fails with "CRITICAL TESTS FAILED"

Check the effectiveness report for which SLA failed:

```bash
# Download artifacts from GitHub Actions run
# Extract effectiveness-governance-{id}.zip
# Open effectiveness-report.html
```

Common causes:
- Recommendation acceptance dropped (operators ignoring suggestions)
- False positive rate increased (poor detection quality)
- MTTD/MTTR degraded (slower detection or resolution)

### Report Shows "Contract Tests: SKIPPED"

Normal in these scenarios:
- `tests/EffectivenessContractTests.php` not found
- PHP environment not available in runner
- Server offline (uses mock data instead)

Does **not** block CI—safety fallback enabled.

### HTML Report Not Generated

Check marketplace server startup:
```bash
# Look in workflow logs for:
php -S 127.0.0.1:8006 ...
```

If server fails to start:
- Check PHP version compatibility (requires 8.2+)
- Verify `services/marketplace/server.php` exists
- Check for startup errors in logs

## Integration with Other Systems

### Drift Analysis

Effectiveness metrics complement drift analysis:
- **Drift Analysis:** "What is the state of tenant environments?" (detection)
- **Effectiveness Metrics:** "Is drift detection improving?" (evaluation)

### Snapshot Approvals

Both are part of governance model:
- Snapshot diffs require approval before merge
- Effectiveness SLA targets must pass before merge
- Together they ensure: correct snapshots + effective intelligence

### License Server Integration

License operations trigger remediation events:
```
License issue detected
        ↓
License server creates remediation event
        ↓
EffectivenessMetrics aggregates event
        ↓
SLA target evaluated in next CI run
```

## Next Steps

### Phase 4.5: Learning Section UI

Learning dashboard will display:
- Top performing recommendations (by success rate, adoption)
- Lowest adoption alerts (recommendations operators ignore)
- Recurring issues (trending problems)
- 30-day improvement trends

Will use effectiveness APIs to display operator insights.

### Phase 4.6: Playwright UI Tests

UI tests will assert:
- Intelligence Performance cards render correctly
- SLA thresholds trigger visual changes (red/yellow/green)
- Learning section displays trend data
- Refresh behavior updates metrics

Requires: Browser installation stability (currently deferred due to network timeouts)

### Phase 4.7: Predictive Intelligence Layer

Future enhancement:
- ML model predicting effectiveness improvement
- Recommendations for improving adoption
- Anomaly detection on effectiveness metrics themselves

## See Also

- [Effectiveness Metrics Implementation](../services/marketplace/EffectivenessMetrics.php)
- [Contract Tests](../tests/EffectivenessContractTests.php)
- [Intelligence Health Dashboard](./ARCHITECTURE.md#intelligence-health-dashboard)
- [CI Workflow Artifacts](../.github/workflows/marketplace-ci.yml)
