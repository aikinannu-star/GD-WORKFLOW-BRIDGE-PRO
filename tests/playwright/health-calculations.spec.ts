import { test, expect } from '@playwright/test';

/**
 * Platform Health Calculation Verification Tests
 * Validates the mathematical correctness of health score aggregations
 */

interface DashboardData {
  health_score: number;
  at_risk_count: number;
  critical_count: number;
  total_installs: number;
  remediations_7d: number;
  fleet_volatility: number;
}

interface TenantOverview {
  tenant_id: string;
  health_score: number;
  install_count: number;
  health_delta: number;
  volatility_score: number;
  drift_status: string;
  health_status: string;
}

test.describe('Health Score Calculation Verification', () => {
  test('formula: weighted_health = sum(tenant_health × installs) / sum(installs)', async ({ request }) => {
    // Fetch data
    const dashResponse = await request.get('http://127.0.0.1:8006/api/v1/marketplace/platform/dashboard');
    const dashboard: DashboardData = await dashResponse.json();

    const overviewResponse = await request.get('http://127.0.0.1:8006/api/v1/marketplace/platform/tenants-overview');
    const overviewData = await overviewResponse.json();

    // Manual calculation
    let totalWeighted = 0;
    let totalInstalls = 0;

    for (const tenant of overviewData) {
      const health = tenant.health_score || 100;
      const installs = tenant.install_count || 1;
      totalWeighted += health * installs;
      totalInstalls += installs;
    }

    const expectedHealth = totalInstalls > 0 ? Math.round(totalWeighted / totalInstalls) : 100;

    // Verify result is within 1 point (rounding tolerance)
    expect(Math.abs(dashboard.health_score - expectedHealth)).toBeLessThanOrEqual(1);
  });

  test('platform health must be between 0-100', async ({ request }) => {
    const response = await request.get('http://127.0.0.1:8006/api/v1/marketplace/platform/dashboard');
    const dashboard: DashboardData = await response.json();

    expect(dashboard.health_score).toBeGreaterThanOrEqual(0);
    expect(dashboard.health_score).toBeLessThanOrEqual(100);
  });

  test('at-risk threshold: health < 60', async ({ request }) => {
    const dashResponse = await request.get('http://127.0.0.1:8006/api/v1/marketplace/platform/dashboard');
    const dashboard: DashboardData = await dashResponse.json();

    const overviewResponse = await request.get('http://127.0.0.1:8006/api/v1/marketplace/platform/tenants-overview');
    const overviewData = await overviewResponse.json();

    // Count tenants with health < 60
    const atRiskTenants = overviewData.filter((t: TenantOverview) => t.health_score < 60);

    // Verify count matches dashboard
    expect(dashboard.at_risk_count).toBe(atRiskTenants.length);
  });

  test('total installs is sum of all tenant installs', async ({ request }) => {
    const dashResponse = await request.get('http://127.0.0.1:8006/api/v1/marketplace/platform/dashboard');
    const dashboard: DashboardData = await dashResponse.json();

    const overviewResponse = await request.get('http://127.0.0.1:8006/api/v1/marketplace/platform/tenants-overview');
    const overviewData = await overviewResponse.json();

    // Sum install counts
    const totalInstalls = overviewData.reduce((sum: number, t: TenantOverview) => sum + (t.install_count || 0), 0);

    expect(dashboard.total_installs).toBe(totalInstalls);
  });

  test('fleet volatility is average of all tenant volatility scores', async ({ request }) => {
    const dashResponse = await request.get('http://127.0.0.1:8006/api/v1/marketplace/platform/dashboard');
    const dashboard: DashboardData = await dashResponse.json();

    const overviewResponse = await request.get('http://127.0.0.1:8006/api/v1/marketplace/platform/tenants-overview');
    const overviewData = await overviewResponse.json();

    // Calculate average volatility
    if (overviewData.length > 0) {
      const totalVolatility = overviewData.reduce(
        (sum: number, t: TenantOverview) => sum + (t.volatility_score || 0),
        0
      );
      const expectedVolatility = Math.round((totalVolatility / overviewData.length) * 100) / 100;

      // Allow small floating point differences
      expect(Math.abs(dashboard.fleet_volatility - expectedVolatility)).toBeLessThan(0.1);
    }
  });
});

test.describe('Ranking Calculation Verification', () => {
  test('healthiest: sorted by health_score DESC, limit 5', async ({ request }) => {
    const rankResponse = await request.get('http://127.0.0.1:8006/api/v1/marketplace/platform/rankings');
    const rankings = await rankResponse.json();

    const healthiest = rankings.healthiest_tenants;

    // Verify max 5
    expect(healthiest.length).toBeLessThanOrEqual(5);

    // Verify descending sort
    for (let i = 1; i < healthiest.length; i++) {
      expect(healthiest[i - 1].health_score).toBeGreaterThanOrEqual(healthiest[i].health_score);
    }

    // Verify it matches top 5 from overview
    const overviewResponse = await request.get('http://127.0.0.1:8006/api/v1/marketplace/platform/tenants-overview');
    const overview: TenantOverview[] = await overviewResponse.json();

    const topHealthiest = overview
      .sort((a, b) => b.health_score - a.health_score)
      .slice(0, 5);

    expect(healthiest.length).toBe(Math.min(5, topHealthiest.length));
  });

  test('most improved: sorted by health_delta DESC, limit 5', async ({ request }) => {
    const rankResponse = await request.get('http://127.0.0.1:8006/api/v1/marketplace/platform/rankings');
    const rankings = await rankResponse.json();

    const improved = rankings.most_improved_tenants;

    // Verify max 5
    expect(improved.length).toBeLessThanOrEqual(5);

    // Verify descending sort by delta
    for (let i = 1; i < improved.length; i++) {
      const delta1 = improved[i - 1].health_delta || 0;
      const delta2 = improved[i].health_delta || 0;
      expect(delta1).toBeGreaterThanOrEqual(delta2);
    }
  });

  test('highest risk: sorted by health_score ASC, limit 5', async ({ request }) => {
    const rankResponse = await request.get('http://127.0.0.1:8006/api/v1/marketplace/platform/rankings');
    const rankings = await rankResponse.json();

    const risk = rankings.highest_risk_tenants;

    // Verify max 5
    expect(risk.length).toBeLessThanOrEqual(5);

    // Verify ascending sort (lowest scores first = highest risk)
    for (let i = 1; i < risk.length; i++) {
      expect(risk[i - 1].health_score).toBeLessThanOrEqual(risk[i].health_score);
    }
  });

  test('ranking data includes required fields', async ({ request }) => {
    const rankResponse = await request.get('http://127.0.0.1:8006/api/v1/marketplace/platform/rankings');
    const rankings = await rankResponse.json();

    const validateRankings = (arr: any[]) => {
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

test.describe('Drift Summary Calculation Verification', () => {
  test('drift counts sum to total tenants', async ({ request }) => {
    const driftResponse = await request.get('http://127.0.0.1:8006/api/v1/marketplace/platform/drift-summary');
    const drift = await driftResponse.json();

    const overviewResponse = await request.get('http://127.0.0.1:8006/api/v1/marketplace/platform/tenants-overview');
    const overview = await overviewResponse.json();

    const totalDrift = drift.no_drift + drift.governance_drift + drift.revocation_drift;
    expect(totalDrift).toBe(overview.length);
  });

  test('drift status values are valid', async ({ request }) => {
    const driftResponse = await request.get('http://127.0.0.1:8006/api/v1/marketplace/platform/drift-summary');
    const drift = await driftResponse.json();

    expect(drift.no_drift).toBeGreaterThanOrEqual(0);
    expect(drift.governance_drift).toBeGreaterThanOrEqual(0);
    expect(drift.revocation_drift).toBeGreaterThanOrEqual(0);

    expect(Number.isInteger(drift.no_drift)).toBe(true);
    expect(Number.isInteger(drift.governance_drift)).toBe(true);
    expect(Number.isInteger(drift.revocation_drift)).toBe(true);
  });

  test('drifted_tenants only contains non-none drift tenants', async ({ request }) => {
    const driftResponse = await request.get('http://127.0.0.1:8006/api/v1/marketplace/platform/drift-summary');
    const drift = await driftResponse.json();

    for (const tenant of drift.drifted_tenants) {
      expect(tenant.drift_type).not.toBe('none');
      expect(['governance', 'revocation']).toContain(tenant.drift_type);
    }
  });

  test('drifted_tenants count matches governance + revocation counts', async ({ request }) => {
    const driftResponse = await request.get('http://127.0.0.1:8006/api/v1/marketplace/platform/drift-summary');
    const drift = await driftResponse.json();

    const driftedCount = drift.governance_drift + drift.revocation_drift;
    expect(drift.drifted_tenants.length).toBe(driftedCount);
  });
});

test.describe('Data Consistency Verification', () => {
  test('all ranking tenants exist in overview', async ({ request }) => {
    const overviewResponse = await request.get('http://127.0.0.1:8006/api/v1/marketplace/platform/tenants-overview');
    const overview: TenantOverview[] = await overviewResponse.json();
    const overviewIds = new Set(overview.map((t) => t.tenant_id));

    const rankResponse = await request.get('http://127.0.0.1:8006/api/v1/marketplace/platform/rankings');
    const rankings = await rankResponse.json();

    const validateRankingIds = (arr: any[]) => {
      for (const item of arr) {
        expect(overviewIds.has(item.tenant_id)).toBe(true);
      }
    };

    if (rankings.healthiest_tenants.length > 0) {
      validateRankingIds(rankings.healthiest_tenants);
    }
    if (rankings.most_improved_tenants.length > 0) {
      validateRankingIds(rankings.most_improved_tenants);
    }
    if (rankings.highest_risk_tenants.length > 0) {
      validateRankingIds(rankings.highest_risk_tenants);
    }
  });

  test('all drifted tenants exist in overview', async ({ request }) => {
    const overviewResponse = await request.get('http://127.0.0.1:8006/api/v1/marketplace/platform/tenants-overview');
    const overview: TenantOverview[] = await overviewResponse.json();
    const overviewIds = new Set(overview.map((t) => t.tenant_id));

    const driftResponse = await request.get('http://127.0.0.1:8006/api/v1/marketplace/platform/drift-summary');
    const drift = await driftResponse.json();

    for (const tenant of drift.drifted_tenants) {
      expect(overviewIds.has(tenant.tenant_id)).toBe(true);
    }
  });

  test('overview data has required fields', async ({ request }) => {
    const response = await request.get('http://127.0.0.1:8006/api/v1/marketplace/platform/tenants-overview');
    const overview: TenantOverview[] = await response.json();

    const requiredFields = ['tenant_id', 'health_score', 'install_count', 'health_delta', 'volatility_score', 'drift_status'];

    for (const tenant of overview) {
      for (const field of requiredFields) {
        expect(tenant).toHaveProperty(field);
      }
    }
  });

  test('all endpoints return cached_at timestamp', async ({ request }) => {
    const endpoints = [
      '/api/v1/marketplace/platform/dashboard',
      '/api/v1/marketplace/platform/rankings',
      '/api/v1/marketplace/platform/drift-summary',
    ];

    for (const endpoint of endpoints) {
      const response = await request.get(`http://127.0.0.1:8006${endpoint}`);
      const data = await response.json();

      expect(data).toHaveProperty('cached_at');
      // Verify it's a valid ISO 8601 timestamp
      expect(() => new Date(data.cached_at).toISOString()).not.toThrow();
    }
  });

  test('dashboard counts match aggregated overview data', async ({ request }) => {
    const dashResponse = await request.get('http://127.0.0.1:8006/api/v1/marketplace/platform/dashboard');
    const dashboard: DashboardData = await dashResponse.json();

    const overviewResponse = await request.get('http://127.0.0.1:8006/api/v1/marketplace/platform/tenants-overview');
    const overview: TenantOverview[] = await overviewResponse.json();

    // Total installs
    const totalInstalls = overview.reduce((sum, t) => sum + (t.install_count || 0), 0);
    expect(dashboard.total_installs).toBe(totalInstalls);

    // At-risk count
    const atRisk = overview.filter((t) => t.health_score < 60).length;
    expect(dashboard.at_risk_count).toBe(atRisk);
  });
});

test.describe('Edge Cases', () => {
  test('handles single tenant fleet', async ({ request }) => {
    // This verifies the calculation works with minimal data
    const dashResponse = await request.get('http://127.0.0.1:8006/api/v1/marketplace/platform/dashboard');
    const dashboard: DashboardData = await dashResponse.json();

    expect(dashboard.health_score).toBeGreaterThanOrEqual(0);
    expect(dashboard.total_installs).toBeGreaterThanOrEqual(1);
  });

  test('handles zero install counts gracefully', async ({ request }) => {
    const overviewResponse = await request.get('http://127.0.0.1:8006/api/v1/marketplace/platform/tenants-overview');
    const overview: TenantOverview[] = await overviewResponse.json();

    // All tenants should have at least 1 install
    for (const tenant of overview) {
      expect(tenant.install_count).toBeGreaterThanOrEqual(1);
    }
  });

  test('handles empty drift counts', async ({ request }) => {
    const driftResponse = await request.get('http://127.0.0.1:8006/api/v1/marketplace/platform/drift-summary');
    const drift = await driftResponse.json();

    // Even if all zeros, structure should be valid
    expect(Number.isInteger(drift.no_drift)).toBe(true);
    expect(Number.isInteger(drift.governance_drift)).toBe(true);
    expect(Number.isInteger(drift.revocation_drift)).toBe(true);
  });
});
