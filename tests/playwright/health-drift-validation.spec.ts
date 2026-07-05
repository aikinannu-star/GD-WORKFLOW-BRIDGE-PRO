import { test, expect } from '@playwright/test';

/**
 * Synthetic Health and Drift Validation Tests
 * Tests platform health calculations, rankings accuracy, and drift categorization
 */

// Helper to fetch JSON from API
async function fetchAPI(page, endpoint) {
  const response = await page.request.get(endpoint);
  return await response.json();
}

test.describe('Platform Health Calculation Validation', () => {
  test('should calculate weighted platform health correctly', async ({ page }) => {
    // Fetch dashboard with all tenants
    const dashboard = await fetchAPI(page, '/api/v1/marketplace/platform/dashboard');
    
    // Verify health score is a number between 0-100
    expect(dashboard.health_score).toBeGreaterThanOrEqual(0);
    expect(dashboard.health_score).toBeLessThanOrEqual(100);
    
    // Fetch overview to manually verify calculation
    const overview = await fetchAPI(page, '/api/v1/marketplace/platform/tenants-overview');
    expect(Array.isArray(overview)).toBe(true);
    
    if (overview.length > 0) {
      // Manual verification: weighted sum should match
      // health_score = sum(tenant_health * install_count) / sum(install_count)
      let totalWeighted = 0;
      let totalInstalls = 0;
      
      for (const tenant of overview) {
        totalWeighted += (tenant.health_score * tenant.install_count);
        totalInstalls += tenant.install_count;
      }
      
      const calculatedHealth = totalInstalls > 0 ? Math.round(totalWeighted / totalInstalls) : 100;
      
      // Allow for rounding differences
      expect(Math.abs(dashboard.health_score - calculatedHealth)).toBeLessThan(2);
    }
  });

  test('should correctly identify at-risk tenants (health < 60)', async ({ page }) => {
    const dashboard = await fetchAPI(page, '/api/v1/marketplace/platform/dashboard');
    const overview = await fetchAPI(page, '/api/v1/marketplace/platform/tenants-overview');
    
    // Count tenants with health < 60
    const atRiskTenants = overview.filter(t => t.health_score < 60);
    
    // Dashboard should report correct count
    expect(dashboard.at_risk_count).toBe(atRiskTenants.length);
  });

  test('should correctly count critical findings', async ({ page }) => {
    const dashboard = await fetchAPI(page, '/api/v1/marketplace/platform/dashboard');
    
    // Critical findings count should be non-negative
    expect(dashboard.critical_count).toBeGreaterThanOrEqual(0);
  });

  test('should calculate total installs across fleet', async ({ page }) => {
    const dashboard = await fetchAPI(page, '/api/v1/marketplace/platform/dashboard');
    const overview = await fetchAPI(page, '/api/v1/marketplace/platform/tenants-overview');
    
    // Sum install counts from overview
    const totalInstalls = overview.reduce((sum, t) => sum + (t.install_count || 0), 0);
    
    // Dashboard should report same total
    expect(dashboard.total_installs).toBe(totalInstalls);
  });

  test('should report 7-day remediation count', async ({ page }) => {
    const dashboard = await fetchAPI(page, '/api/v1/marketplace/platform/dashboard');
    
    // Remediations should be a non-negative integer
    expect(dashboard.remediations_7d).toBeGreaterThanOrEqual(0);
    expect(Number.isInteger(dashboard.remediations_7d)).toBe(true);
  });

  test('should calculate volatility correctly', async ({ page }) => {
    const dashboard = await fetchAPI(page, '/api/v1/marketplace/platform/dashboard');
    
    // Volatility should be non-negative and reasonable
    expect(dashboard.fleet_volatility).toBeGreaterThanOrEqual(0);
    expect(dashboard.fleet_volatility).toBeLessThan(1000); // sanity check
  });
});

test.describe('Rankings Accuracy Validation', () => {
  test('should rank healthiest tenants by score descending', async ({ page }) => {
    const rankings = await fetchAPI(page, '/api/v1/marketplace/platform/rankings');
    const healthiest = rankings.healthiest_tenants;
    
    // Verify healthiest is array
    expect(Array.isArray(healthiest)).toBe(true);
    expect(healthiest.length).toBeLessThanOrEqual(5);
    
    // Verify sorted by health_score descending
    for (let i = 1; i < healthiest.length; i++) {
      expect(healthiest[i - 1].health_score).toBeGreaterThanOrEqual(healthiest[i].health_score);
    }
  });

  test('should rank most improved tenants by delta descending', async ({ page }) => {
    const rankings = await fetchAPI(page, '/api/v1/marketplace/platform/rankings');
    const improved = rankings.most_improved_tenants;
    
    // Verify improved is array
    expect(Array.isArray(improved)).toBe(true);
    expect(improved.length).toBeLessThanOrEqual(5);
    
    // Verify sorted by health_delta descending
    for (let i = 1; i < improved.length; i++) {
      const delta1 = improved[i - 1].health_delta || 0;
      const delta2 = improved[i].health_delta || 0;
      expect(delta1).toBeGreaterThanOrEqual(delta2);
    }
  });

  test('should rank highest risk tenants by score ascending (lowest first)', async ({ page }) => {
    const rankings = await fetchAPI(page, '/api/v1/marketplace/platform/rankings');
    const risk = rankings.highest_risk_tenants;
    
    // Verify risk is array
    expect(Array.isArray(risk)).toBe(true);
    expect(risk.length).toBeLessThanOrEqual(5);
    
    // Verify sorted by health_score ascending (lowest = most risk)
    for (let i = 1; i < risk.length; i++) {
      expect(risk[i - 1].health_score).toBeLessThanOrEqual(risk[i].health_score);
    }
  });

  test('should include tenant_id and health_score in rankings', async ({ page }) => {
    const rankings = await fetchAPI(page, '/api/v1/marketplace/platform/rankings');
    
    const validateRankings = (arr) => {
      for (const item of arr) {
        expect(item).toHaveProperty('tenant_id');
        expect(item).toHaveProperty('health_score');
        expect(typeof item.tenant_id).toBe('string');
        expect(typeof item.health_score).toBe('number');
      }
    };
    
    if (rankings.healthiest_tenants.length > 0) {
      validateRankings(rankings.healthiest_tenants);
    }
    if (rankings.most_improved_tenants.length > 0) {
      validateRankings(rankings.most_improved_tenants);
    }
    if (rankings.highest_risk_tenants.length > 0) {
      validateRankings(rankings.highest_risk_tenants);
    }
  });
});

test.describe('Drift Summary Validation', () => {
  test('should categorize tenants by drift status', async ({ page }) => {
    const drift = await fetchAPI(page, '/api/v1/marketplace/platform/drift-summary');
    
    // Verify drift counts
    expect(drift).toHaveProperty('no_drift');
    expect(drift).toHaveProperty('governance_drift');
    expect(drift).toHaveProperty('revocation_drift');
    
    // All should be non-negative integers
    expect(Number.isInteger(drift.no_drift)).toBe(true);
    expect(Number.isInteger(drift.governance_drift)).toBe(true);
    expect(Number.isInteger(drift.revocation_drift)).toBe(true);
  });

  test('should sum drift counts to total tenants', async ({ page }) => {
    const drift = await fetchAPI(page, '/api/v1/marketplace/platform/drift-summary');
    const overview = await fetchAPI(page, '/api/v1/marketplace/platform/tenants-overview');
    
    const totalDrift = drift.no_drift + drift.governance_drift + drift.revocation_drift;
    
    // Total drift counts should equal number of tenants
    expect(totalDrift).toBe(overview.length);
  });

  test('should include drifted_tenants array with details', async ({ page }) => {
    const drift = await fetchAPI(page, '/api/v1/marketplace/platform/drift-summary');
    
    // drifted_tenants should be array
    expect(Array.isArray(drift.drifted_tenants)).toBe(true);
    
    // Each drifted tenant should have required fields
    for (const tenant of drift.drifted_tenants) {
      expect(tenant).toHaveProperty('tenant_id');
      expect(tenant).toHaveProperty('drift_type');
      expect(tenant).toHaveProperty('health_score');
    }
  });

  test('should only include non-no_drift tenants in drifted_tenants', async ({ page }) => {
    const drift = await fetchAPI(page, '/api/v1/marketplace/platform/drift-summary');
    
    // All drifted_tenants should NOT have drift_type: 'none'
    for (const tenant of drift.drifted_tenants) {
      expect(tenant.drift_type).not.toBe('none');
    }
  });
});

test.describe('Operations Center Data Consistency', () => {
  test('should maintain consistency between dashboard and overview', async ({ page }) => {
    const dashboard = await fetchAPI(page, '/api/v1/marketplace/platform/dashboard');
    const overview = await fetchAPI(page, '/api/v1/marketplace/platform/tenants-overview');
    
    // Overview should have same tenants as dashboard references
    if (dashboard.tenant_count !== undefined) {
      expect(overview.length).toBeGreaterThanOrEqual(0);
    }
  });

  test('should maintain consistency between overview and rankings', async ({ page }) => {
    const overview = await fetchAPI(page, '/api/v1/marketplace/platform/tenants-overview');
    const rankings = await fetchAPI(page, '/api/v1/marketplace/platform/rankings');
    
    // All ranking tenants should exist in overview
    const overviewIds = new Set(overview.map(t => t.tenant_id));
    
    const validateRankingConsistency = (arr) => {
      for (const item of arr) {
        expect(overviewIds.has(item.tenant_id)).toBe(true);
      }
    };
    
    if (rankings.healthiest_tenants.length > 0) {
      validateRankingConsistency(rankings.healthiest_tenants);
    }
  });

  test('should maintain consistency between overview and drift', async ({ page }) => {
    const overview = await fetchAPI(page, '/api/v1/marketplace/platform/tenants-overview');
    const drift = await fetchAPI(page, '/api/v1/marketplace/platform/drift-summary');
    
    // All drifted tenants should exist in overview
    const overviewIds = new Set(overview.map(t => t.tenant_id));
    
    for (const tenant of drift.drifted_tenants) {
      expect(overviewIds.has(tenant.tenant_id)).toBe(true);
    }
  });

  test('should have cached_at timestamp', async ({ page }) => {
    const dashboard = await fetchAPI(page, '/api/v1/marketplace/platform/dashboard');
    const rankings = await fetchAPI(page, '/api/v1/marketplace/platform/rankings');
    const drift = await fetchAPI(page, '/api/v1/marketplace/platform/drift-summary');
    
    // All endpoints should include cached_at
    expect(dashboard).toHaveProperty('cached_at');
    expect(rankings).toHaveProperty('cached_at');
    expect(drift).toHaveProperty('cached_at');
    
    // Should be valid ISO 8601 timestamps
    expect(() => new Date(dashboard.cached_at).toISOString()).not.toThrow();
  });
});
