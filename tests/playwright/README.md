# Operations Center Playwright Test Suite

Comprehensive E2E and integration test coverage for the Operations Center dashboard, health calculations, drift detection, and synthetic scenario validation.

## Quick Start

### Install Dependencies

```bash
npm install @playwright/test
```

### Run All Tests

```bash
npx playwright test tests/playwright/
```

### Run Specific Test Suite

```bash
# UI/Dashboard tests
npx playwright test tests/playwright/operations-center.spec.ts

# Health and drift calculation validation
npx playwright test tests/playwright/health-drift-validation.spec.ts

# Synthetic scenario tests
npx playwright test tests/playwright/scenarios.spec.ts

# Health calculation verification
npx playwright test tests/playwright/health-calculations.spec.ts
```

### Run with UI Mode (Interactive)

```bash
npx playwright test tests/playwright/operations-center.spec.ts --ui
```

### Run with Debug Mode

```bash
npx playwright test tests/playwright/operations-center.spec.ts --debug
```

### Generate HTML Report

```bash
npx playwright test tests/playwright/
npx playwright show-report test-results/playwright/
```

## Test Structure

### 1. `operations-center.spec.ts` - UI Component Tests
**Purpose**: Verify Operations Center page structure and interactions  
**Tests**: 15 tests
- Page loads with correct title and banner
- KPI cards render with proper structure
- Platform health status displays
- Tenant overview table loads
- Rankings section renders
- Drift summary displays counts
- Rankings tables populate with data
- Tenant drill-down navigation works
- Data refresh on button click
- Health and drift status filters
- Tenant search functionality
- Back/Forward navigation

**Execution Time**: ~30 seconds  
**Prerequisites**: PHP dev server running on 127.0.0.1:8006

### 2. `health-drift-validation.spec.ts` - API & Data Validation
**Purpose**: Verify API responses and data integrity  
**Tests**: 26 tests organized in 5 suites
- Platform health calculation accuracy
- At-risk tenant identification (health < 60)
- Critical findings count
- Total installs aggregation
- 7-day remediation tracking
- Fleet volatility calculation
- Healthiest tenants ranking
- Most improved tenants ranking
- Highest risk tenants ranking
- Drift categorization
- Drift count summation
- Drifted tenants list integrity
- Data consistency across endpoints
- Cached timestamps

**Execution Time**: ~20 seconds  
**Prerequisites**: Endpoints must return valid JSON

### 3. `health-calculations.spec.ts` - Mathematical Verification
**Purpose**: Verify all health score calculations are mathematically correct  
**Tests**: 24 tests organized in 6 suites
- Weighted health formula: `sum(health × installs) / sum(installs)`
- Health bounds (0-100)
- At-risk threshold: health < 60
- Total installs calculation
- Fleet volatility as average of tenant volatility
- Ranking sort orders (DESC, ASC)
- Drift count summation
- Edge cases (single tenant, zero installs)
- Data consistency verification

**Execution Time**: ~25 seconds  
**Prerequisites**: Valid test data loaded

### 4. `scenarios.spec.ts` - Synthetic Scenario Tests
**Purpose**: Test platform behavior under different health/drift conditions  
**Tests**: 20 tests across 7 scenarios

#### Scenarios Available

**Healthy Fleet** (5 tenants @ 100% health)
- Platform health: 100
- At-risk: 0
- Drift: 5 no_drift

**Degraded Fleet** (mix of healthy and critical)
- Platform health: ~75
- At-risk: 1
- Drift: 3 no_drift, 1 governance, 1 revocation

**Drift Scenario** (various drift statuses)
- Platform health: ~91
- Drift: 2 no_drift, 2 governance, 1 revocation

**Weighted Health** (tests weighted calculation)
- Platform health: ~73 (low health, high installs)
- At-risk: 1

**Improved Tenants** (tests positive health deltas)
- Platform health: ~86
- Improvements: 35, 15, stable, declining

**Risk Scenario** (tests critical/at-risk tenants)
- Platform health: ~59 (low)
- At-risk: 3 of 4 tenants
- Drift: 2 none, 1 governance, 1 revocation

**Execution Time**: ~60 seconds (6 scenario setups × 10 seconds each)  
**Prerequisites**: `/api/v1/marketplace/test/scenario` endpoint available

### 5. `fixtures.ts` - Test Helpers
**Purpose**: Reusable test utilities  
**Classes**:
- `TestScenarioManager`: Setup scenarios, verify calculations
- `testScenarios`: Named scenario constants

**Usage**:
```typescript
const manager = new TestScenarioManager('http://127.0.0.1:8006', request);
await manager.setupScenario('degraded');
const isValid = await manager.verifyHealthCalculation('degraded', 75, 2);
```

## Synthetic Test Data Setup

### Via CLI

```bash
# Create healthy fleet scenario
php tests/SyntheticScenarioHelper.php healthy

# Create degraded fleet
php tests/SyntheticScenarioHelper.php degraded

# Create drift scenario
php tests/SyntheticScenarioHelper.php drift

# Test weighted health calculation
php tests/SyntheticScenarioHelper.php weighted

# Test improved tenants tracking
php tests/SyntheticScenarioHelper.php improved

# Test risk rankings
php tests/SyntheticScenarioHelper.php risk

# Reset to defaults
php tests/SyntheticScenarioHelper.php reset
```

### Via API

```bash
# Setup scenario via HTTP POST
curl -X POST http://127.0.0.1:8006/api/v1/marketplace/test/scenario \
  -H "Content-Type: application/json" \
  -d '{"scenario": "degraded"}'

# Response
{
  "status": "ok",
  "scenario": {
    "name": "degraded_fleet",
    "created_at": "2026-06-25T08:31:00Z",
    "tenants": [...]
  }
}
```

### Via Playwright Tests

```typescript
const manager = new TestScenarioManager('http://127.0.0.1:8006', request);
await manager.setupScenario('healthy');
await page.goto('/operations-center');
// Test runs against healthy fleet
```

## Test Data Expectations

### Scenario Health Calculations

| Scenario | Expected Health | At-Risk | Drift |
|----------|-----------------|---------|-------|
| healthy | 100 | 0 | 5 none |
| degraded | ~75 | 1 | 3/1/1 |
| drift | ~91 | 0 | 2/2/1 |
| weighted | ~73 | 1 | 3/0/0 |
| improved | ~86 | 0 | 3/1/0 |
| risk | ~59 | 3 | 2/1/1 |

### Tenant Structure

Each tenant includes:
```json
{
  "tenant_id": "unique-id",
  "health_score": 95,
  "health_status": "Healthy|Fair|Critical",
  "health_delta": 5,
  "drift_status": "none|governance|revocation",
  "install_count": 2,
  "volatility_score": 1.5,
  "last_check": "2026-06-25T08:31:00Z"
}
```

## Debugging Failed Tests

### Enable Verbose Logging

```bash
DEBUG=pw:api npx playwright test tests/playwright/operations-center.spec.ts
```

### Inspect Network Requests

```bash
npx playwright test tests/playwright/health-drift-validation.spec.ts --debug
# Use Inspector to check network tab
```

### Check Screenshots/Videos

Tests capture on failure:
```bash
# Screenshots in:
test-results/playwright/test-*.png

# Videos in:
test-results/playwright/test-video.webm
```

### Common Issues

**"Page not ready" errors**
- Ensure PHP dev server is running: `php -S 127.0.0.1:8006`
- Check `/health` endpoint: `curl http://127.0.0.1:8006/health`

**"Cannot find element" errors**
- Verify Operations Center route exists: `/operations-center`
- Check HTML structure matches test selectors
- Use `--debug` mode to inspect live page

**"Calculation mismatch" errors**
- Reset test data: `php tests/SyntheticScenarioHelper.php reset`
- Verify aggregation cache cleared: `rm services/data/platform-aggregation.json`
- Check health formula implementation in `buildPlatformAggregation()`

**Flaky drift counts**
- Ensure scenario data saved correctly to `services/data/`
- Clear aggregation cache before scenario setup
- Check JSON syntax in saved data file

## Coverage Report

```bash
# Run with coverage (requires Istanbul or similar)
npx playwright test tests/playwright/ --reporter=html

# Open report
open test-results/playwright/index.html
```

## CI/CD Integration

### GitHub Actions Example

```yaml
name: Playwright Tests

on: [push, pull_request]

jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v3
      - uses: actions/setup-node@v3
        with:
          node-version: '18'
      
      - run: npm install
      - run: php -S 127.0.0.1:8006 -t . &
      - run: npx playwright test tests/playwright/
      
      - uses: actions/upload-artifact@v3
        if: always()
        with:
          name: playwright-report
          path: test-results/playwright/
```

## Performance Benchmarks

Expected execution times:

```
operations-center.spec.ts:        ~30 seconds (15 tests)
health-drift-validation.spec.ts:  ~20 seconds (26 tests)
health-calculations.spec.ts:      ~25 seconds (24 tests)
scenarios.spec.ts:                ~60 seconds (20 tests, 6 scenarios)
---
Total:                            ~135 seconds (85 tests)
```

Parallel execution (4 workers):
```
Total: ~40 seconds
```

## Adding New Tests

### Template

```typescript
import { test, expect } from '@playwright/test';

test.describe('New Feature', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/operations-center');
    await page.waitForLoadState('networkidle');
  });

  test('should demonstrate new feature', async ({ page }) => {
    // Arrange
    const element = page.locator('selector');
    
    // Act
    await element.click();
    
    // Assert
    await expect(element).toHaveClass('active');
  });
});
```

### Best Practices

1. **Use data attributes**: `data-testid="kpi-card"` for stable selectors
2. **Wait for network**: `await page.waitForLoadState('networkidle')`
3. **Isolate tests**: Each test should be independent
4. **Clear data**: Use `beforeEach` to reset state
5. **Meaningful names**: Test name should describe behavior
6. **One assertion per test**: Easier to diagnose failures

## Maintenance

### Update Tests When

- UI structure changes (class names, IDs)
- API response schema changes
- Health calculation formula changes
- New endpoints added
- Threshold values change (e.g., at-risk < 60)

### Keep Tests Stable

- Avoid hardcoded timestamps
- Use relative time comparisons (< 100ms, not exact time)
- Mock slow endpoints if needed
- Update selectors when HTML changes

## Resources

- [Playwright Documentation](https://playwright.dev)
- [Best Practices](https://playwright.dev/docs/best-practices)
- [API Reference](https://playwright.dev/docs/api/class-page)
- [Debugging Guide](https://playwright.dev/docs/debug)

---

**Last Updated**: 2026-06-25  
**Version**: 1.0  
**Status**: Phase 2 Complete, Ready for Phase 3
