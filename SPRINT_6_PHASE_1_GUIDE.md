# Sprint 6 Phase 1: Health vs Volatility Matrix - Implementation Guide

**Status**: 🚀 Ready to Execute  
**Duration**: 2-3 days  
**Team**: 1 Frontend + 1 Backend  

---

## 📋 Overview

The Health vs Volatility Matrix is a scatter plot visualization that immediately surfaces operational risk across the fleet. This is Sprint 6's primary delivery that provides operators with an intuitive, at-a-glance view of tenant health.

### Visual Interpretation

```
QUADRANT ANALYSIS:

High Health + Low Volatility   = HEALTHY (green zone)
  ✓ Stable performance
  ✓ Low risk
  ✓ No intervention needed

High Health + High Volatility  = WATCH (orange zone)
  ⚠ Performing but unpredictable
  ⚠ Monitor closely
  ⚠ May need stability intervention

Med Health + Any Volatility    = STAGNANT (blue zone)
  ⚠ Mediocre performance
  ⚠ Needs attention
  ⚠ Consider optimization

Low Health + Low Volatility    = CRITICAL (red zone)
  🚨 Poor performance but predictable
  🚨 Urgent attention required
  🚨 Need investigation

Low Health + High Volatility   = DEGRADING (dark red zone)
  🚨 Poor + unpredictable
  🚨 CRITICAL + cascading failure risk
  🚨 Immediate remediation required
```

---

## 🛠️ Technical Architecture

### Component Location
```
ui-components/
  └── health-volatility-matrix.html (starter code provided)

ui-tests/
  └── health-volatility-matrix.spec.js (16 test cases)

services/marketplace/
  └── server.php (add route to serve component)
```

### API Contract
The component consumes:
```
GET /api/v1/marketplace/platform/overview

Response:
{
  "items": [
    {
      "id": "tenant-id",
      "name": "Tenant Name",
      "health_score": 85,           // 0-100
      "fleet_volatility": 25,       // 0-100
      "at_risk_count": 3,
      "critical_count": 0,
      ...
    }
  ]
}
```

**Status**: ✅ API ready (already implemented)

---

## 📦 Deliverables

### Phase 1A: Component Integration (1 day)

**File**: `ui-components/health-volatility-matrix.html`

Starter code includes:
- ✅ SVG scatter plot rendering
- ✅ 5 risk zones (colored backgrounds)
- ✅ Axis labels and grid
- ✅ Interactive tooltips on hover
- ✅ Data point color coding
- ✅ Statistics cards (total, healthy, at-risk, avg health)
- ✅ Period selector (1d, 3d, 7d, 14d, 30d)
- ✅ Refresh button
- ✅ SVG export button

**Tasks**:
1. Copy starter HTML to workspace
2. Verify it loads locally with mock data
3. Test SVG rendering and interactions
4. Adjust colors/styling to match brand

**Exit Criteria**:
- [ ] Component loads without errors
- [ ] SVG scatter plot renders with 10+ test tenants
- [ ] Tooltips appear on hover
- [ ] All 5 risk zones visible in legend
- [ ] Responsive on mobile (600px+)

### Phase 1B: API Integration (1 day)

**File**: `services/marketplace/server.php`

Add route:
```php
if ($method === 'GET' && ($path === '/health-volatility-matrix' || $path === '/health-volatility-matrix/')) {
    // Serve the HTML component
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/../../ui-components/health-volatility-matrix.html');
    exit;
}
```

**Tasks**:
1. Add route to marketplace server
2. Test component loads at `/health-volatility-matrix`
3. Verify API calls succeed from component
4. Check CORS headers if needed

**Exit Criteria**:
- [ ] Component accessible at `/health-volatility-matrix`
- [ ] Fetches live tenant data from `/api/v1/marketplace/platform/overview`
- [ ] Scatter plot renders with actual fleet data
- [ ] No console errors

### Phase 1C: Operations Center Integration (1 day)

**File**: `services/marketplace/server.php`

Update the Operations Center route to include a link/iframe:
```html
<nav class="operations-nav">
    <a href="/health-volatility-matrix">📊 Fleet Matrix</a>
    <a href="/operations-center">📋 Dashboard</a>
    <a href="/operations-center/trends">📈 Trends</a>
    <a href="/operations-center/drift">🔄 Drift</a>
</nav>
```

Or embed as iframe in Operations Center:
```html
<div class="matrix-container">
    <iframe src="/health-volatility-matrix" style="width: 100%; height: 800px; border: none;"></iframe>
</div>
```

**Tasks**:
1. Decide: standalone page vs embedded
2. Add navigation link in Operations Center
3. Ensure responsive layout
4. Test drill-down (click tenant → detail view)

**Exit Criteria**:
- [ ] Matrix accessible from Operations Center
- [ ] Navigation clear
- [ ] Responsive layout on tablet/mobile
- [ ] Click handlers ready for Phase 2 drill-down

---

## 🧪 Testing Strategy

### Playwright Tests (16 test cases)
File: `ui-tests/health-volatility-matrix.spec.js`

**Coverage**:
- ✅ Scatter plot renders with tenants
- ✅ Risk zone legend displays all 5 zones
- ✅ Hover shows tooltip with health/volatility/risk
- ✅ Statistics cards display correctly
- ✅ Period selector functional
- ✅ Refresh button fetches new data
- ✅ Export button generates SVG
- ✅ Points colored by risk zone
- ✅ Responsive on resize
- ✅ No error messages on load
- ✅ Risk zone colors match legend
- ✅ Axes and grid visible
- ✅ Axis labels displayed
- ✅ API contract verified
- ✅ Tenant data structure validated

**Execution**:
```bash
npx playwright test ui-tests/health-volatility-matrix.spec.js --reporter=list
```

**Success Criteria**: ✅ 16/16 tests passing

---

## 🔄 Drill-Down (Phase 2 Prep)

The scatter plot includes click handlers ready for Phase 2:

```javascript
circle.addEventListener('click', () => {
    // Phase 2: Navigate to tenant trend timeline
    window.location.href = `/tenant/${tenant.id}/trends`;
});
```

**Placeholder**: Currently alerts tenant ID. Will be updated in Phase 2 to load trend timeline.

---

## 📊 Risk Zone Thresholds (Configurable)

Current settings in `health-volatility-matrix.html`:

```javascript
RISK_ZONES = {
    healthy: { 
        condition: (h, v) => h > 75 && v < 30,
        color: '#10b981'  // Green
    },
    watch: { 
        condition: (h, v) => h > 75 && v >= 30,
        color: '#f59e0b'  // Orange
    },
    stagnant: { 
        condition: (h, v) => h >= 50 && h <= 75,
        color: '#6366f1'  // Blue
    },
    critical: { 
        condition: (h, v) => h < 50 && v < 30,
        color: '#ef4444'  // Red
    },
    degrading: { 
        condition: (h, v) => h < 50 && v >= 30,
        color: '#dc2626'  // Dark Red
    }
}
```

**Adjustment**: These thresholds can be tuned based on operational needs. Currently:
- Health > 75% = "Good"
- Health 50-75% = "Concerning"
- Health < 50% = "Poor"
- Volatility < 30% = "Stable"
- Volatility >= 30% = "Volatile"

---

## 🎨 Styling & Branding

### Colors (Tailwind)
```css
Healthy:   #10b981 (green-500)
Watch:     #f59e0b (amber-500)
Stagnant:  #6366f1 (indigo-500)
Critical:  #ef4444 (red-500)
Degrading: #dc2626 (red-600)
```

### Responsive Design
- Desktop (1024px+): Side-by-side chart + legend
- Tablet (768px+): Stacked layout
- Mobile (< 768px): Full-width with scrollable legend

---

## 📝 Checklist for Phase 1 Completion

### Day 1: Component Setup
- [ ] Copy starter HTML to `ui-components/`
- [ ] Verify HTML syntax (no browser errors)
- [ ] Test SVG rendering locally
- [ ] Adjust styling to match brand
- [ ] Responsive design validated

### Day 2: API Integration
- [ ] Add route to `server.php`
- [ ] Component loads at `/health-volatility-matrix`
- [ ] Fetches live data from overview API
- [ ] Scatter plot populates correctly
- [ ] Tooltips and interactions work
- [ ] Statistics cards populate

### Day 3: Operations Center Integration & Testing
- [ ] Add navigation link to Operations Center
- [ ] Embed or link component
- [ ] Responsive layout verified
- [ ] Run Playwright test suite: `npx playwright test ui-tests/health-volatility-matrix.spec.js`
- [ ] All 16 tests passing
- [ ] No console errors
- [ ] Click handlers ready for Phase 2

---

## 🚀 Success Metrics

| Metric | Target | Status |
|--------|--------|--------|
| Component load time | < 200ms | 📊 |
| SVG render time | < 500ms | 📊 |
| API response time | < 200ms | ✅ (measured) |
| Test pass rate | 100% (16/16) | 📊 |
| Responsive breakpoints | 3+ (mobile/tablet/desktop) | 📊 |
| Accessibility score | > 85 | 📊 |
| Browser support | Chrome, Firefox, Safari, Edge | 📊 |

---

## 🔗 Integration Points

### Depends On
- ✅ `/api/v1/marketplace/platform/overview` (ready)
- ✅ Marketplace service running (ready)
- ✅ Tenant data in platform cache (ready)

### Enables
- 🔄 Phase 2: Tenant Trend Timeline drill-down
- 🔄 Phase 3: Drift Analysis Grid filtering
- 🔄 Phase 4: Intelligence Health Check metrics

---

## 📚 Related Files

### Provided Starter Code
- `ui-components/health-volatility-matrix.html` - Complete component (✅ provided)
- `ui-tests/health-volatility-matrix.spec.js` - 16 test cases (✅ provided)

### Existing APIs (Ready)
- `services/marketplace/server.php` - Add route
- `services/lib/ServiceHelpers.php` - Utilities
- `services/data/marketplace_platform_cache.json` - Data source

### Documentation
- `SPRINT_6_READINESS.md` - Full readiness assessment
- `SPRINT_6_LAUNCH_SUMMARY.md` - Executive summary

---

## ⚠️ Known Limitations & Mitigations

| Issue | Impact | Mitigation |
|-------|--------|-----------|
| SVG scaling on very large datasets (1000+ tenants) | Performance | Implement clustering or pagination |
| Mobile tooltip positioning | UX | Use positioned popup instead of tooltip |
| Period selector not changing data | UX | Requires API support for `period` parameter |
| Drill-down not implemented | Integration | Phase 2 deliverable |

---

## 🎯 Phase 1 Exit Criteria (Must Have All ✅)

- [ ] Component renders scatter plot correctly
- [ ] All 5 risk zones visible and correctly colored
- [ ] Hover tooltips show tenant details
- [ ] Statistics cards populate from API data
- [ ] Responsive on mobile (tested)
- [ ] API integration verified
- [ ] Playwright test suite: 16/16 passing
- [ ] No console errors or warnings
- [ ] Component accessible from Operations Center
- [ ] Documentation updated in this file

---

## 🔄 Continuation: Phase 2 Readiness

After Phase 1 completes:
1. Click handler on scatter plot points → drill-down to tenant
2. Navigate to `/tenant/{id}/trends` (Phase 2 feature)
3. Display tenant trend timeline with health over time
4. Show trend direction badge (improving/stable/degrading)

**Preview**: Tenant drill-down will load `ui-components/tenant-trend-timeline.html`

---

## 📞 Support & Questions

**Component Structure**: See `health-volatility-matrix.html` comments  
**Data Format**: See API contract section above  
**Test Coverage**: Run `npx playwright test ui-tests/health-volatility-matrix.spec.js -v`  
**Integration**: Add route in `services/marketplace/server.php`

---

**Status**: 🟢 Ready to build  
**Start Date**: Immediately after sprint kick-off  
**Expected Completion**: +3 days  
**Next Phase**: Phase 2 (Tenant Trend Timeline)
