# Risk Zone Configuration - Integration Guide

**Date**: 2026-06-25  
**Status**: Ready for Phase 1 Integration

---

## Overview

Risk zone thresholds have been centralized to prevent "threshold drift" - a situation where different components use different thresholds for the same risk classifications.

**Problem Solved**: Before this integration, the Health vs Volatility Matrix might use different health/volatility boundaries than the Drift Engine or Operations Center, causing confusing and inconsistent risk classifications.

**Solution**: Single source of truth for all risk thresholds, consumed by all components.

---

## Risk Zone Definitions

| Zone | Health | Volatility | Color | Priority | Action |
|------|--------|------------|-------|----------|--------|
| **Healthy** | 75-100 | 0-30 | 🟢 #10b981 | Monitor (4) | Continue monitoring |
| **Watch** | 75-100 | 30-100 | 🟠 #f59e0b | Observe (3) | Stabilize volatility |
| **Stagnant** | 50-75 | 0-100 | 🔵 #6366f1 | Improve (2) | Optimize performance |
| **Critical** | 0-50 | 0-30 | 🔴 #ef4444 | Urgent (1) | Investigate root cause |
| **Degrading** | 0-50 | 30-100 | 🟥 #dc2626 | Emergency (1) | Escalate immediately |

---

## File Locations

```
Config/
├── RiskZones.php                 # PHP central configuration (backend source of truth)
│
services/marketplace/api/
├── RiskZonesEndpoint.php        # API endpoint for serving zones to frontend
│
ui-components/
├── risk-zones.js                # Frontend risk zone utilities
├── health-volatility-matrix.html # Phase 1 component (uses risk-zones.js)
│
tests/fixtures/
├── fleet-healthy.json           # Test scenario: all healthy tenants
├── fleet-mixed.json             # Test scenario: mixed risk distribution
├── fleet-degrading.json         # Test scenario: critical cascade
```

---

## Integration by Component

### 1. Backend Analytics (PHP)

**File**: `services/marketplace/TimeSeriesHelper.php`

```php
<?php
namespace GD\Workflow\Services;

use GD\Workflow\Config;

class TimeSeriesHelper {
    public function calculateHealthZone($health_score, $volatility) {
        // Import the function
        require_once __DIR__ . '/../../Config/RiskZones.php';
        
        // Use centralized function
        $zone = Config\getRiskZone($health_score, $volatility);
        
        return $zone;
    }
}
```

**Usage in calculations**:
```php
$zone = Config\getRiskZone($health, $volatility);
$color = $zone['color'];
$remediation_priority = $zone['remediation_priority'];
```

---

### 2. Drift Engine (PHP)

**File**: `services/marketplace/DriftAnalyzer.php`

```php
<?php
namespace GD\Workflow\Services;

use GD\Workflow\Config;

class DriftAnalyzer {
    public function categorizeDrift($tenant_health) {
        // Get zone configuration
        require_once __DIR__ . '/../../Config/RiskZones.php';
        
        $zone = Config\getRiskZone($tenant_health, $tenant_volatility);
        
        // Use zone status for drift routing
        if ($zone['remediation_priority'] === 1) {
            // Critical or degrading - needs immediate attention
            $this->escalateDrift($tenant_id);
        }
    }
}
```

---

### 3. Operations Center Dashboard (PHP + JavaScript)

**Server Side**: `services/marketplace/server.php`

```php
<?php
// Add route to serve risk zones
if ($method === 'GET' && $path === '/api/v1/risk-zones') {
    require_once __DIR__ . '/../../Config/RiskZones.php';
    require_once __DIR__ . '/api/RiskZonesEndpoint.php';
    \GD\Workflow\API\RiskZonesEndpoint::handle();
}

// Add classification endpoint
if ($method === 'GET' && preg_match('/^\/api\/v1\/risk-zones\/classify/', $path)) {
    require_once __DIR__ . '/../../Config/RiskZones.php';
    require_once __DIR__ . '/api/RiskZonesEndpoint.php';
    
    $health = (float)($_GET['health'] ?? 50);
    $volatility = (float)($_GET['volatility'] ?? 50);
    
    $result = \GD\Workflow\API\RiskZonesEndpoint::classifyTenant($health, $volatility);
    
    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}
```

**Client Side**: `ui-components/health-volatility-matrix.html`

```javascript
// Import risk zones
<script src="risk-zones.js"></script>

<script>
  // Use centralized risk zones for coloring
  const zone = getRiskZone(tenant.health_score, tenant.fleet_volatility);
  
  // Color the data point
  point.style.fill = zone.color;
  
  // Set tooltip
  tooltip.innerHTML = `
    ${zone.icon} ${zone.name}
    Health: ${tenant.health_score}%
    Volatility: ${tenant.fleet_volatility}%
  `;
</script>
```

---

### 4. Health vs Volatility Matrix (Phase 1)

**File**: `ui-components/health-volatility-matrix.html`

**Before** (hardcoded thresholds):
```javascript
const HEALTHY_THRESHOLD = 75;
const VOLATILITY_THRESHOLD = 30;

if (health >= HEALTHY_THRESHOLD && volatility <= VOLATILITY_THRESHOLD) {
    color = '#10b981';  // Green
}
```

**After** (centralized thresholds):
```javascript
// Import from centralized config
const zone = getRiskZone(health, volatility);
const color = zone.color;  // #10b981
const icon = zone.icon;    // 🟢
const name = zone.name;    // "Healthy"
```

---

### 5. Phase 2: Tenant Trend Timeline (Upcoming)

**File**: `ui-components/tenant-trend-timeline.html` (Phase 2)

```javascript
// Import risk zones
import { getRiskZone, getZoneColor } from './risk-zones.js';

// Use when rendering trend points
for (const snapshot of timeseries) {
    const zone = getRiskZone(snapshot.health, snapshot.volatility);
    const point = createDataPoint(snapshot.timestamp, zone.color);
}
```

---

### 6. Phase 3: Drift Analysis Grid (Upcoming)

**File**: `ui-components/drift-analysis-grid.html` (Phase 3)

```javascript
// Use risk zone for row coloring
const zone = getRiskZone(tenant.current_health, tenant.current_volatility);

// Color grid rows by risk
gridRow.style.backgroundColor = zone.light_color;
gridRow.style.borderLeft = `4px solid ${zone.color}`;
```

---

## API Endpoints

### Get All Risk Zones

```bash
GET /api/v1/risk-zones

Response:
{
  "healthy": {
    "id": "healthy",
    "name": "Healthy",
    "health_min": 75,
    "health_max": 100,
    "volatility_min": 0,
    "volatility_max": 30,
    "color": "#10b981",
    ...
  },
  ...
}
```

### Classify Tenant Risk

```bash
GET /api/v1/risk-zones/classify?health=85&volatility=25

Response:
{
  "zone_id": "healthy",
  "zone_name": "Healthy",
  "zone_icon": "🟢",
  "zone_color": "#10b981",
  "health_score": 85.0,
  "volatility_score": 25.0,
  "recommended_action": "Continue monitoring",
  "remediation_priority": 4,
  ...
}
```

---

## Testing Risk Zone Consistency

### 1. Unit Test: Threshold Validation

```php
<?php
// tests/unit/RiskZoneThresholdsTest.php

use PHPUnit\Framework\TestCase;
use GD\Workflow\Config;

class RiskZoneThresholdsTest extends TestCase
{
    public function testHealthyZoneThresholds()
    {
        $zone = Config\getRiskZone(85, 25);
        $this->assertEquals('healthy', $zone['id']);
        $this->assertEquals('#10b981', $zone['color']);
    }
    
    public function testDegradingZoneThresholds()
    {
        $zone = Config\getRiskZone(35, 45);
        $this->assertEquals('degrading', $zone['id']);
        $this->assertEquals('#dc2626', $zone['color']);
    }
    
    public function testThresholdConsistency()
    {
        // Verify no overlapping zones
        $this->assertTrue(Config\validateRiskThresholds());
    }
}
```

### 2. Integration Test: API Endpoint

```javascript
// ui-tests/risk-zones.spec.js

test('should serve consistent risk zones from API', async ({ page }) => {
    const response = await page.request.get('/api/v1/risk-zones');
    const zones = await response.json();
    
    expect(zones.healthy.color).toBe('#10b981');
    expect(zones.critical.color).toBe('#ef4444');
    expect(zones.degrading.color).toBe('#dc2626');
});

test('should classify tenant consistently', async ({ page }) => {
    const response = await page.request.get('/api/v1/risk-zones/classify?health=85&volatility=25');
    const result = await response.json();
    
    expect(result.zone_name).toBe('Healthy');
    expect(result.zone_color).toBe('#10b981');
});
```

### 3. Fixture-Based Testing

Use test fixtures to verify consistent zone classification:

```bash
# Healthy Fleet: All tenants should be "healthy" zone
tests/fixtures/fleet-healthy.json

# Mixed Fleet: Verify 2 critical, 3 warning, 2 healthy
tests/fixtures/fleet-mixed.json

# Degrading Fleet: All critical/degrading, verify emergency status
tests/fixtures/fleet-degrading.json
```

---

## Customization Strategy

To adjust thresholds (e.g., for different SLAs):

### Option 1: Global Adjustment
Edit `Config/RiskZones.php` and update thresholds:

```php
const RISK_ZONES = [
    'healthy' => [
        'health_min' => 80,  // Changed from 75
        'health_max' => 100,
        ...
    ],
    ...
];
```

All components automatically use new thresholds.

### Option 2: Per-Tenant Customization (Future)
```php
$custom_zones = get_tenant_risk_zones($tenant_id);
$zone = getRiskZone($health, $volatility, $custom_zones);
```

### Option 3: A/B Testing
```javascript
// Load different zone set for experiment
const zones = experimentEnabled ? alternativeZones : RISK_ZONES;
```

---

## Checklist: Integration Before Sprint 1 Day 1

- [ ] `Config/RiskZones.php` created ✅
- [ ] `services/marketplace/api/RiskZonesEndpoint.php` created ✅
- [ ] `ui-components/risk-zones.js` created ✅
- [ ] API route `/api/v1/risk-zones` added to server.php
- [ ] Health vs Volatility Matrix imports `risk-zones.js`
- [ ] Operations Center uses API endpoint `/api/v1/risk-zones`
- [ ] Test fixtures created ✅ (fleet-healthy.json, fleet-mixed.json, fleet-degrading.json)
- [ ] Unit test for RiskZoneThresholds passes
- [ ] Integration test for API endpoint passes
- [ ] Documentation updated (this file)

---

## Troubleshooting

### Q: Components showing different colors
**A**: Verify they're all importing from centralized config:
```bash
grep -r "getRiskZone\|RISK_ZONES" ui-components/ services/
```

### Q: Thresholds changed but UI not updating
**A**: Clear browser cache and reload:
```bash
# Hard refresh
Ctrl+Shift+R (Windows/Linux)
Cmd+Shift+R (Mac)

# Or restart server
```

### Q: API endpoint returning 404
**A**: Verify route added to `services/marketplace/server.php`:
```bash
grep -n "risk-zones" services/marketplace/server.php
```

### Q: Zone classification unexpected
**A**: Test with classify endpoint:
```bash
curl "http://127.0.0.1:8006/api/v1/risk-zones/classify?health=85&volatility=25"
```

---

## Next Steps After Phase 1

1. **Phase 2**: Update Tenant Trend Timeline to use risk zones
2. **Phase 3**: Color drift grid rows by risk zone
3. **Phase 4**: Use zone priority for remediation ordering
4. **Monitoring**: Track if threshold adjustments improve risk detection accuracy

---

## Reference

- **Backend Source**: `Config/RiskZones.php`
- **API Endpoint**: `services/marketplace/api/RiskZonesEndpoint.php`
- **Frontend Utilities**: `ui-components/risk-zones.js`
- **Test Fixtures**: `tests/fixtures/fleet-*.json`
- **Component**: `ui-components/health-volatility-matrix.html`
