# Tenant Trends & Drift Analysis - Usage Guide

## Quick Start

### Viewing Tenant Trends
1. Open http://127.0.0.1:8006/marketplace-ui
2. Select a tenant from the "Select Tenant" dropdown
3. Click the **Trends** tab
4. View real-time trend analysis

## Dashboard Sections

### 📈 Volatility Indicator
- **Green badge**: Stable (volatility < 2)
- **Yellow badge**: Medium volatility (2-5)
- **Red badge**: High volatility (> 5)
- Shows trend direction: "Stable", "Fluctuating", or "Trending"

### Trend Metrics (3 Cards)
| Metric | Shows | Arrow Meanings |
|--------|-------|----------------|
| **Health Score Change** | Delta in health points | ↗=improving, ↙=declining, →=stable |
| **Installs** | Current install count | ↗=adoption growing, ↙=usage declining |
| **Ratings** | Current rating count | ↗=engagement up, ↙=engagement down |

### 🚨 Drift Detectors

#### ✅ No Drift Detected (Green)
- Tenant is operating normally
- No governance violations
- Metrics are stable

#### ⚠️ Revocation Drift (Red Alert)
- Multiple license keys have been revoked
- Example: "⚠️ Revocation Drift Detected - 5 keys revoked (35%) - up 2"
- **Action**: Review key revocation reasons
- **Severity**: 
  - 30-50% revoked = HIGH RISK
  - >50% revoked = CRITICAL RISK

#### 🔧 Governance Drift (Red Alert)
- Unmet plugin dependencies have increased
- Example: "🔧 Governance Drift Detected - 3 unmet dependencies - up 1"
- **Action**: Install missing dependencies
- **Severity**: Indicates policy violations

## Remediation Workflow

### Before Remediation
1. Navigate to **Health** tab to see issues
2. Click a remediation button (e.g., "Install Missing Dependencies")

### Preview Step
- Dialog appears showing "Expected impact: +10 points"
- Lists specific changes that will occur
- Example: "Install plugins: plugin-a, plugin-b"

### After Confirmation
- System executes remediation
- Trends tab automatically updates
- Health score increases by expected amount
- New data point added to 30-day history

## Interpreting Trends

### Health Score Trends
- **Consistently improving** (↗↗↗): Remediation actions working
- **Flat** (→→→): No changes in tenant configuration
- **Declining** (↙↙↙): New issues appearing (investigate)

### Volatility Patterns
- **Stable**: Normal operations, low risk
- **Medium**: Some metric fluctuations (monitor)
- **High**: Rapid changes (immediate investigation recommended)

### Adoption vs. Engagement
- **High adoption + engagement**: Healthy tenant, actively used
- **High adoption + low engagement**: Used but not rated (follow up)
- **Low adoption + engagement**: Specialized plugins, smaller user base
- **Low adoption + low engagement**: Potentially at-risk

## Data Storage & History

### Where Data is Stored
- Location: `services/data/marketplace_tenant_history_{TENANT_ID}.json`
- Format: JSON with 30-day rolling window
- Size: ~2KB per tenant (efficient storage)

### Data Points Captured
- `timestamp`: When measurement was taken
- `health_score`: 0-100 scale
- `install_count`: Number of active installs
- `rating_count`: Number of ratings received
- `revoked_key_count`: Number of revoked licenses
- `missing_deps_count`: Unmet dependencies

### Historical Data Example
```json
{
  "tenant_id": "test-tenant-1",
  "snapshots": [
    {"timestamp": 1234567890, "health_score": 95, "install_count": 5, ...},
    {"timestamp": 1234567891, "health_score": 95, "install_count": 5, ...}
  ],
  "trends": {
    "health_score": {"current": 95, "delta": 0, "direction": "stable"},
    "adoption": {"current": 5, "delta": 0, "direction": "flat"},
    "engagement": {"current": 3, "delta": 0, "direction": "flat"},
    "volatility": {"score": 0.0, "trend": "stable", "risk": "low"},
    "revocation_drift": {...},
    "governance_drift": {...}
  }
}
```

## API Reference

### Get Trends for a Tenant
```bash
GET /api/v1/marketplace/tenants/{TENANT_ID}/trends
```

**Response:**
```json
{
  "tenant_id": "test-tenant-1",
  "current_snapshot": {...},
  "trends": {
    "health_score": {"current": 95, "delta": 0, "direction": "stable"},
    "adoption": {"current": 5, "delta": 0, "direction": "flat"},
    "engagement": {"current": 3, "delta": 0, "direction": "flat"},
    "volatility": {"score": 0.0, "trend": "stable", "risk": "low"},
    "revocation_drift": {
      "current_count": 0,
      "current_percent": 0.0,
      "delta": 0,
      "is_drifting": false,
      "risk_level": "normal"
    },
    "governance_drift": {
      "current_missing": 0,
      "delta": 0,
      "is_drifting": false,
      "risk_level": "normal"
    }
  }
}
```

### Preview Remediation Impact
```bash
POST /api/v1/marketplace/tenants/{TENANT_ID}/remediate/{ACTION}/preview
```

**Actions:**
- `install-missing-deps`
- `activate-keys`

**Response:**
```json
{
  "current_health": 85,
  "projected_health": 95,
  "health_impact": 10,
  "confidence": "high",
  "safe_to_execute": true,
  "changes": [
    "Install plugin-a",
    "Install plugin-b"
  ]
}
```

## Troubleshooting

### No Data in Trends Tab
- **Cause**: Tenant has no history yet (new tenant)
- **Solution**: Wait 24 hours, or perform an action to generate baseline data
- **Note**: Volatility shows 0.0 initially (expected)

### Drift Alert Not Appearing
- **Cause**: Drift detection thresholds not met
- **Note**: Revocation drift requires >30% revoked keys
- **Note**: Governance drift requires increase in missing deps

### Health Score Not Improving After Remediation
- **Cause**: Check if remediation actually fixed the issue
- **Solution**: Verify in Health tab that findings are resolved
- **Note**: Some issues require manual intervention

## Best Practices

1. **Review Trends Weekly** - Catch issues early
2. **Act on High Volatility** - Investigate spike causes
3. **Use Previews** - Always preview before major changes
4. **Monitor Drift** - Address governance violations promptly
5. **Track Patterns** - Look for seasonal trends in adoption/engagement

## Support

For issues or questions about the Trends & Drift Analysis feature, refer to:
- [PLATFORM_README.md](PLATFORM_README.md) - Platform overview
- [PLATFORM_SERVICE_SPEC.md](PLATFORM_SERVICE_SPEC.md) - API specification
- services/marketplace/server.php - Source code with detailed comments
