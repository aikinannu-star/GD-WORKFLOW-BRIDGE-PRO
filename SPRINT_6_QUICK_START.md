# Sprint 6 Phase 1: Quick Start Guide

**TL;DR**: Copy the HTML component, add a route, run tests. Done in 3 days.

---

## 🚀 5-Minute Setup

### 1. Copy Component
```bash
# Copy the starter HTML to your workspace
cp ui-components/health-volatility-matrix.html /your/project/
```

### 2. Add Route
Edit `services/marketplace/server.php`, add after other GET routes:

```php
if ($method === 'GET' && ($path === '/health-volatility-matrix' || $path === '/health-volatility-matrix/')) {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/../../ui-components/health-volatility-matrix.html');
    exit;
}
```

### 3. Test It
```bash
# Start PHP server (if not already running)
php -S 127.0.0.1:8006 -t services/marketplace services/marketplace/server.php

# Visit: http://127.0.0.1:8006/health-volatility-matrix
# You should see the scatter plot with real tenant data!
```

---

## 📋 3-Day Execution Plan

### ✅ Day 1: Component Integration (4-5 hours)
```
09:00 - Review SPRINT_6_PHASE_1_GUIDE.md
10:00 - Copy starter HTML to ui-components/
10:30 - Add route to server.php
11:00 - Test locally (PHP server running)
12:00 - Verify SVG renders, data loads, interactions work
13:00 - LUNCH
14:00 - Customize colors/styling (optional)
15:00 - Responsive design check (mobile, tablet, desktop)
16:00 - Document any tweaks needed
17:00 - END DAY 1
```

**Acceptance**: Component loads at `/health-volatility-matrix`, renders scatter plot, shows real data

### ✅ Day 2: API Integration & Optimization (4-5 hours)
```
09:00 - Verify live data from /api/v1/marketplace/platform/overview
10:00 - Test all interactions (hover, click, refresh, export)
11:00 - Check console for errors/warnings
12:00 - Performance baseline (< 200ms load, < 500ms render)
13:00 - LUNCH
14:00 - Responsive layout polish
15:00 - Test on actual devices/browsers (if available)
16:00 - Statistics cards validation
17:00 - END DAY 2
```

**Acceptance**: Live data flowing, no errors, responsive on all screen sizes

### ✅ Day 3: Operations Center Integration & Testing (4-5 hours)
```
09:00 - Add navigation link to Operations Center
10:00 - Decide: standalone page or embedded iframe?
10:30 - Implement integration
11:00 - Run Playwright test suite
12:00 - LUNCH
13:00 - Debug any failing tests (should be 0 failures)
14:00 - Full smoke test of all features
15:00 - Documentation review
16:00 - Prepare for Phase 2 (drill-down)
17:00 - END DAY 3 - READY FOR PHASE 2
```

**Acceptance**: All 16 Playwright tests passing, component accessible from Operations Center

---

## 🧪 Testing Commands

### Run Phase 1 Tests Only
```bash
npx playwright test ui-tests/health-volatility-matrix.spec.js --reporter=list
```

### Run All Tests (Governance + Feature)
```bash
npx playwright test --reporter=list
```

### Run Contract Tests (Governance)
```bash
php -r 'require "vendor/autoload.php"; require "tests/unit/TrendContractTest.php"; $suite = new PHPUnit\Framework\TestSuite(); $suite->addTestSuite("TrendContractTest"); $runner = new PHPUnit\TextUI\TestRunner(); $runner->run($suite);'
```

**Expected Result**:
```
Health vs Volatility Matrix Tests: 16/16 ✅
Trend Contract Tests: 4/4 ✅
Edge-Case Tests: 7/7 ✅
Timeseries API Tests: 8/8 ✅
────────────────────────────────────
TOTAL: 35/35 ✅
```

---

## 📁 Files You Need

### Copy/Create
- [ ] `ui-components/health-volatility-matrix.html` (provided - 500+ lines of complete code)

### Edit
- [ ] `services/marketplace/server.php` (add 4-line route, see Day 1)

### Reference (Don't Edit)
- [ ] `ui-tests/health-volatility-matrix.spec.js` (16 tests - provided)
- [ ] `SPRINT_6_PHASE_1_GUIDE.md` (detailed guide)
- [ ] `services/marketplace/TimeSeriesHelper.php` (API data source)
- [ ] `services/data/marketplace_platform_cache.json` (live data)

---

## 🎯 Risk Zones at a Glance

```
                High Volatility
                      ↑
                      │
        Watch (🟠)  Degrading (🟥)
        High Health │   Low Health
        Volatile    │   Volatile
           │        │        │
Health 75% ├────────┼────────┤
           │        │        │
        Stagnant (🔵) - Mediocre - Any Volatility
           │        │        │
Health 50% ├────────┼────────┤
           │        │        │
     Critical (🔴) │ (same)
      Low Health    │   Low Health
      Stable        │   Volatile
                    │
            ←────────┼────────→
           Low Volatility (30%)  High Volatility

GREEN:  ✅ Healthy      (H > 75%, V < 30%)
ORANGE: ⚠ Watch        (H > 75%, V ≥ 30%)
BLUE:   ⚠ Stagnant     (50% ≤ H ≤ 75%, Any V)
RED:    🚨 Critical    (H < 50%, V < 30%)
DARK RED:🚨 Degrading  (H < 50%, V ≥ 30%)
```

---

## 🔧 Common Customizations

### Change Risk Zone Thresholds
Edit line ~120 in `health-volatility-matrix.html`:
```javascript
RISK_ZONES = {
    healthy: { condition: (h, v) => h > 75 && v < 30, color: '#10b981' },
    // Adjust numbers as needed for your SLA
}
```

### Change Colors
Edit line ~130 (RISK_ZONES object):
```javascript
healthy: { color: '#YOUR_COLOR_HERE', ... }
```

### Hide Period Selector
Comment out line ~380:
```javascript
// <select id="period">...</select>  // Hidden
```

### Change Export Format
Edit line ~280 (exportBtn click handler) - currently SVG, can add PNG/CSV

---

## ✅ Verification Checklist

Run through this before calling it "done":

### Load Test
- [ ] Component loads at `/health-volatility-matrix`
- [ ] No 404 errors
- [ ] No console errors

### Data Test
- [ ] Scatter plot shows 10+ points
- [ ] Points colored differently (multiple zones)
- [ ] Statistics cards show numbers (not dashes)
- [ ] Data matches API response

### Interaction Test
- [ ] Hover over point → tooltip shows
- [ ] Hover away → tooltip disappears
- [ ] Click refresh → data reloads
- [ ] Export button generates file

### Responsive Test
- [ ] Mobile (600px): Readable, scroll if needed
- [ ] Tablet (768px): Good layout
- [ ] Desktop (1024px+): Full side-by-side layout

### Browser Test (if possible)
- [ ] Chrome/Chromium: ✅
- [ ] Firefox: ✅
- [ ] Safari: ✅
- [ ] Edge: ✅

### Test Suite
- [ ] `npx playwright test ui-tests/health-volatility-matrix.spec.js` → 16/16 ✅
- [ ] No flaky tests
- [ ] All assertions passing

### Performance
- [ ] Component loads in < 200ms
- [ ] Scatter plot renders in < 500ms
- [ ] No lag on hover/interaction

---

## 🐛 Troubleshooting

### "No data showing"
```
Check:
  1. Is PHP server running? (php -S 127.0.0.1:8006 ...)
  2. Is API endpoint accessible? (curl http://127.0.0.1:8006/api/v1/marketplace/platform/overview)
  3. Does API return tenant data? (should see "items": [...])
  4. Console for JavaScript errors? (F12 → Console tab)
```

### "Scatter plot shows but no points"
```
Check:
  1. Is API returning tenants? (check Network tab in F12)
  2. Do tenants have health_score and fleet_volatility? 
  3. Are values valid numbers (not strings)?
```

### "Tooltips not showing"
```
Check:
  1. Does SVG have circles? (Inspect element in F12)
  2. Do circles have data-tenant-id attribute?
  3. Is JavaScript event listener bound?
  4. Check console for JavaScript errors
```

### "Tests failing"
```
Check:
  1. Is component accessible at the URL?
  2. Does API return data?
  3. Is there a network error? (F12 → Network tab)
  4. Is SVG rendering? (F12 → Elements tab, look for <svg> tags)
```

---

## 📞 Support

### Documentation
- **Implementation Guide**: `SPRINT_6_PHASE_1_GUIDE.md` (detailed)
- **Full Readiness**: `SPRINT_6_READINESS.md` (feature scope)
- **API Spec**: `services/marketplace/TimeSeriesHelper.php` (data source)

### Tests
- **Playwright**: `ui-tests/health-volatility-matrix.spec.js` (16 test cases)
- **Governance**: `tests/unit/TrendContractTest.php` (CI guardrails)

### Quick Links
- [Phase 1 Implementation Guide](SPRINT_6_PHASE_1_GUIDE.md) ← **START HERE**
- [HTML Component Code](ui-components/health-volatility-matrix.html)
- [Playwright Tests](ui-tests/health-volatility-matrix.spec.js)
- [Full Delivery Package](SPRINT_6_DELIVERY_PACKAGE.md)

---

## 🚀 You're Ready!

Everything is provided:
- ✅ Component code (complete, tested)
- ✅ Test cases (16 comprehensive)
- ✅ API ready (validated)
- ✅ Implementation guide (step-by-step)
- ✅ Governance locked (CI will catch regressions)

**Time to First Render**: ~2-3 hours  
**Time to Full Integration**: ~2-3 days  
**Next Phase**: Tenant Trend Timeline drill-down

**Let's build Phase 1! 🎉**
