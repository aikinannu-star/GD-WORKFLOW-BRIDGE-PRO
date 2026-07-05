import { test, expect } from '@playwright/test';
import { TestScenarioManager, testScenarios } from './fixtures';

/**
 * Scenario-Based Integration Tests
 * Tests the platform with different synthetic health and drift conditions
 */

test.describe('Scenario: Healthy Fleet', () => {
  test.beforeEach(async ({ page, request }) => {
    const manager = new TestScenarioManager('http://127.0.0.1:8006', request);
    await manager.setupScenario('healthy');
    await page.goto('/operations-center');
    await page.waitForLoadState('networkidle');
  });

  test('should show 100% platform health', async ({ page }) => {
    const healthScore = await page.locator('main > div:first-child strong').first().textContent();
    expect(healthScore).toBe('100');
  });

  test('should show zero at-risk tenants', async ({ page }) => {
    const atRiskElement = page.locator('h3:has-text("At-Risk Tenants")').locator('.. strong');
    const atRiskCount = await atRiskElement.textContent();
    expect(atRiskCount).toBe('0');
  });

  test('should show all tenants in no drift category', async ({ page }) => {
    await page.locator('h2:has-text("Fleet Drift Summary")').scrollIntoViewIfNeeded();
    const noDrift = await page.locator('#driftNone').textContent();
    expect(noDrift).toBe('5');
  });

  test('should show zero governance and revocation drift', async ({ page }) => {
    await page.locator('h2:has-text("Fleet Drift Summary")').scrollIntoViewIfNeeded();
    const govDrift = await page.locator('#driftGovernance').textContent();
    const revDrift = await page.locator('#driftRevocation').textContent();
    expect(govDrift).toBe('0');
    expect(revDrift).toBe('0');
  });

  test('should populate healthiest tenants with all having 100 score', async ({ page }) => {
    await page.locator('h2:has-text("Rankings & Risk Analysis")').scrollIntoViewIfNeeded();
    const rows = page.locator('#healthiestTable tbody tr');
    const count = await rows.count();
    expect(count).toBeGreaterThan(0);
    
    // Check all visible scores are 100
    const cells = page.locator('#healthiestTable tbody td:nth-child(2)');
    for (let i = 0; i < Math.min(5, count); i++) {
      const score = await cells.nth(i).textContent();
      expect(score).toBe('100');
    }
  });
});

test.describe('Scenario: Degraded Fleet', () => {
  test.beforeEach(async ({ page, request }) => {
    const manager = new TestScenarioManager('http://127.0.0.1:8006', request);
    await manager.setupScenario('degraded');
    await page.goto('/operations-center');
    await page.waitForLoadState('networkidle');
  });

  test('should show degraded platform health', async ({ page }) => {
    const healthScore = await page.locator('main > div:first-child strong').first().textContent();
    const score = parseInt(healthScore || '0');
    expect(score).toBeLessThan(100);
    expect(score).toBeGreaterThan(50);
  });

  test('should identify at-risk tenants', async ({ page }) => {
    const atRiskElement = page.locator('h3:has-text("At-Risk Tenants")').locator('.. strong');
    const atRiskCount = await atRiskElement.textContent();
    const count = parseInt(atRiskCount || '0');
    expect(count).toBeGreaterThan(0);
  });

  test('should show mixed drift statuses', async ({ page }) => {
    await page.locator('h2:has-text("Fleet Drift Summary")').scrollIntoViewIfNeeded();
    const noDrift = parseInt(await page.locator('#driftNone').textContent() || '0');
    const govDrift = parseInt(await page.locator('#driftGovernance').textContent() || '0');
    const revDrift = parseInt(await page.locator('#driftRevocation').textContent() || '0');
    
    expect(noDrift + govDrift + revDrift).toBe(5); // Total tenants
    expect(noDrift).toBeGreaterThan(0);
    expect(govDrift + revDrift).toBeGreaterThan(0);
  });

  test('should show at-risk tenant in risk rankings', async ({ page }) => {
    await page.locator('h2:has-text("Rankings & Risk Analysis")').scrollIntoViewIfNeeded();
    await page.waitForSelector('#riskTable tbody tr', { timeout: 5000 });
    
    const rows = page.locator('#riskTable tbody tr');
    const count = await rows.count();
    expect(count).toBeGreaterThan(0);
    
    // Risk table should show lowest scores first
    const scores = page.locator('#riskTable tbody td:nth-child(2)');
    const firstScore = parseInt(await scores.first().textContent() || '0');
    expect(firstScore).toBeLessThan(60);
  });
});

test.describe('Scenario: Drift Detection', () => {
  test.beforeEach(async ({ page, request }) => {
    const manager = new TestScenarioManager('http://127.0.0.1:8006', request);
    await manager.setupScenario('drift');
    await page.goto('/operations-center');
    await page.waitForLoadState('networkidle');
  });

  test('should detect governance drift', async ({ page }) => {
    await page.locator('h2:has-text("Fleet Drift Summary")').scrollIntoViewIfNeeded();
    const govDrift = parseInt(await page.locator('#driftGovernance').textContent() || '0');
    expect(govDrift).toBeGreaterThan(0);
  });

  test('should detect revocation drift', async ({ page }) => {
    await page.locator('h2:has-text("Fleet Drift Summary")').scrollIntoViewIfNeeded();
    const revDrift = parseInt(await page.locator('#driftRevocation').textContent() || '0');
    expect(revDrift).toBeGreaterThan(0);
  });

  test('should categorize tenants with no drift', async ({ page }) => {
    await page.locator('h2:has-text("Fleet Drift Summary")').scrollIntoViewIfNeeded();
    const noDrift = parseInt(await page.locator('#driftNone').textContent() || '0');
    expect(noDrift).toBeGreaterThan(0);
  });

  test('should sum drift counts to total tenants', async ({ page }) => {
    await page.locator('h2:has-text("Fleet Drift Summary")').scrollIntoViewIfNeeded();
    const noDrift = parseInt(await page.locator('#driftNone').textContent() || '0');
    const govDrift = parseInt(await page.locator('#driftGovernance').textContent() || '0');
    const revDrift = parseInt(await page.locator('#driftRevocation').textContent() || '0');
    
    const total = noDrift + govDrift + revDrift;
    expect(total).toBe(5); // Total tenants in drift scenario
  });
});

test.describe('Scenario: Weighted Health Calculation', () => {
  test.beforeEach(async ({ page, request }) => {
    const manager = new TestScenarioManager('http://127.0.0.1:8006', request);
    await manager.setupScenario('weighted');
    await page.goto('/operations-center');
    await page.waitForLoadState('networkidle');
  });

  test('should calculate weighted health based on install count', async ({ page }) => {
    // Expected: (60*5 + 100*1 + 80*2) / 8 = 72.5 ≈ 73
    const healthScore = await page.locator('main > div:first-child strong').first().textContent();
    const score = parseInt(healthScore || '0');
    
    // Allow ±2 for rounding
    expect(score).toBeGreaterThanOrEqual(71);
    expect(score).toBeLessThanOrEqual(75);
  });

  test('should identify low-health tenant with high installs', async ({ page }) => {
    const atRiskElement = page.locator('h3:has-text("At-Risk Tenants")').locator('.. strong');
    const atRiskCount = await atRiskElement.textContent();
    
    // Should flag the tenant with health 60 as at-risk
    expect(parseInt(atRiskCount || '0')).toBeGreaterThan(0);
  });

  test('should show 3 tenants in overview', async ({ page }) => {
    const rows = page.locator('table tbody tr');
    const count = await rows.count();
    expect(count).toBe(3);
  });
});

test.describe('Scenario: Improved Tenants', () => {
  test.beforeEach(async ({ page, request }) => {
    const manager = new TestScenarioManager('http://127.0.0.1:8006', request);
    await manager.setupScenario('improved');
    await page.goto('/operations-center');
    await page.waitForLoadState('networkidle');
  });

  test('should rank most improved tenants by health delta', async ({ page }) => {
    await page.locator('h2:has-text("Rankings & Risk Analysis")').scrollIntoViewIfNeeded();
    await page.waitForSelector('#improvedTable tbody tr', { timeout: 5000 });
    
    const rows = page.locator('#improvedTable tbody tr');
    const count = await rows.count();
    expect(count).toBeGreaterThan(0);
  });

  test('should show tenant with largest improvement first', async ({ page }) => {
    await page.locator('h2:has-text("Rankings & Risk Analysis")').scrollIntoViewIfNeeded();
    await page.waitForSelector('#improvedTable tbody tr', { timeout: 5000 });
    
    // First tenant should have delta of 35 (highest improvement)
    const firstRowId = await page.locator('#improvedTable tbody tr').first().locator('td').first().textContent();
    expect(firstRowId).toContain('improved-high');
  });

  test('should include declining tenants at-risk', async ({ page }) => {
    const atRiskElement = page.locator('h3:has-text("At-Risk Tenants")').locator('.. strong');
    const atRiskCount = parseInt(await atRiskElement.textContent() || '0');
    
    // Declining tenant with health 70 should not be at-risk (< 60)
    // But should show in overview
    expect(atRiskCount).toBe(0);
  });
});

test.describe('Scenario: Risk Detection', () => {
  test.beforeEach(async ({ page, request }) => {
    const manager = new TestScenarioManager('http://127.0.0.1:8006', request);
    await manager.setupScenario('risk');
    await page.goto('/operations-center');
    await page.waitForLoadState('networkidle');
  });

  test('should identify multiple at-risk tenants', async ({ page }) => {
    const atRiskElement = page.locator('h3:has-text("At-Risk Tenants")').locator('.. strong');
    const atRiskCount = parseInt(await atRiskElement.textContent() || '0');
    
    // 25, 50, 65 < 60, so 3 at-risk
    expect(atRiskCount).toBe(3);
  });

  test('should rank highest risk tenants correctly', async ({ page }) => {
    await page.locator('h2:has-text("Rankings & Risk Analysis")').scrollIntoViewIfNeeded();
    await page.waitForSelector('#riskTable tbody tr', { timeout: 5000 });
    
    const rows = page.locator('#riskTable tbody tr');
    const count = await rows.count();
    expect(count).toBeGreaterThan(0);
    
    // First row should be lowest score (highest risk)
    const firstScore = parseInt(await rows.first().locator('td').nth(1).textContent() || '0');
    expect(firstScore).toBe(25); // Lowest in risk scenario
  });

  test('should show degraded platform health with multiple at-risk', async ({ page }) => {
    const healthScore = parseInt(await page.locator('main > div:first-child strong').first().textContent() || '0');
    
    // Expected: (25 + 50 + 65 + 95) / 4 = 58.75 ≈ 59
    expect(healthScore).toBeLessThan(65);
    expect(healthScore).toBeGreaterThan(55);
  });

  test('should track drift with at-risk tenants', async ({ page }) => {
    await page.locator('h2:has-text("Fleet Drift Summary")').scrollIntoViewIfNeeded();
    
    const noDrift = parseInt(await page.locator('#driftNone').textContent() || '0');
    const govDrift = parseInt(await page.locator('#driftGovernance').textContent() || '0');
    const revDrift = parseInt(await page.locator('#driftRevocation').textContent() || '0');
    
    // Risk scenario: 2 no_drift, 1 governance, 1 revocation
    expect(noDrift).toBe(2);
    expect(govDrift).toBe(1);
    expect(revDrift).toBe(1);
  });
});

test.describe('Scenario Reset', () => {
  test('should reset to default data', async ({ page, request }) => {
    const manager = new TestScenarioManager('http://127.0.0.1:8006', request);
    
    // Setup a test scenario
    await manager.setupScenario('degraded');
    
    // Reset
    await manager.resetDefaults();
    
    // Navigate to ops center
    await page.goto('/operations-center');
    await page.waitForLoadState('networkidle');
    
    // Should be back to default state
    const rows = page.locator('table tbody tr');
    const count = await rows.count();
    expect(count).toBeGreaterThan(0);
  });
});
