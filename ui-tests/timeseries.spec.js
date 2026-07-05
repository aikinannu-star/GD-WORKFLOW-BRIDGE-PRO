import { test, expect } from '@playwright/test';

test.describe('Marketplace Platform Time Series API', () => {
  test('returns fleet health timeseries data', async ({ request }) => {
    const response = await request.get('/api/v1/marketplace/platform/timeseries?metric=health_score&days_back=3');
    expect(response.ok()).toBeTruthy();

    const body = await response.json();
    expect(body.metric).toBe('health_score');
    expect(body.period).toBe('hourly');
    expect(Array.isArray(body.data_points)).toBe(true);
    expect(body.statistics).toBeTruthy();
    expect(body.statistics).toHaveProperty('current_value');
    expect(body.statistics).toHaveProperty('7d_avg');
  });

  test('supports tenant-specific time series queries', async ({ request }) => {
    const tenantResponse = await request.get('/api/v1/marketplace/tenants');
    expect(tenantResponse.ok()).toBeTruthy();
    const tenantBody = await tenantResponse.json();
    const tenantId = tenantBody.items?.[0]?.id;
    expect(tenantId).toBeTruthy();

    const response = await request.get(`/api/v1/marketplace/platform/timeseries?tenant_id=${encodeURIComponent(tenantId)}&metric=health_score&days_back=7`);
    expect(response.ok()).toBeTruthy();

    const body = await response.json();
    expect(body.tenant_id).toBe(tenantId);
    expect(Array.isArray(body.data_points)).toBe(true);
    expect(body.statistics).toBeTruthy();
  });

  test('supports weekly aggregation', async ({ request }) => {
    const response = await request.get('/api/v1/marketplace/platform/timeseries?metric=health_score&period=weekly&days_back=14');
    expect(response.ok()).toBeTruthy();

    const body = await response.json();
    expect(body.period).toBe('weekly');
    expect(Array.isArray(body.data_points)).toBe(true);
    expect(body.data_points.length).toBeLessThanOrEqual(14);
  });

  // Edge-case regression tests
  test('handles single-day timeseries requests', async ({ request }) => {
    const response = await request.get('/api/v1/marketplace/platform/timeseries?metric=health_score&days_back=1');
    expect(response.ok()).toBeTruthy();

    const body = await response.json();
    expect(body.statistics).toBeTruthy();
    expect(body.statistics).toHaveProperty('trend_direction');
    expect(['stable', 'improving', 'degrading']).toContain(body.statistics.trend_direction);
  });

  test('returns valid statistics for constant series', async ({ request }) => {
    const response = await request.get('/api/v1/marketplace/platform/timeseries?metric=health_score&days_back=3');
    expect(response.ok()).toBeTruthy();

    const body = await response.json();
    expect(body.statistics).toBeTruthy();
    expect(body.statistics).toHaveProperty('trend_direction');
    expect(body.statistics).toHaveProperty('trend_velocity');
    expect(body.statistics).toHaveProperty('trend_confidence');
    // Trend confidence can be 0 for constant series (low variance)
    expect(typeof body.statistics.trend_confidence).toBe('number');
    expect(body.statistics.trend_confidence).toBeGreaterThanOrEqual(0);
  });

  test('validates metrics parameter', async ({ request }) => {
    const response = await request.get('/api/v1/marketplace/platform/timeseries?metric=invalid_metric&days_back=3');
    // Should still return 200 with empty or default data
    expect([200, 400]).toContain(response.status());
  });

  test('handles multiple days_back values', async ({ request }) => {
    const testCases = [1, 3, 7, 14, 30];
    
    for (const days of testCases) {
      const response = await request.get(`/api/v1/marketplace/platform/timeseries?metric=health_score&days_back=${days}`);
      expect(response.ok()).toBeTruthy();
      
      const body = await response.json();
      expect(body.days_back).toBe(days);
      expect(Array.isArray(body.data_points)).toBe(true);
    }
  });

  test('trend statistics contain required fields', async ({ request }) => {
    const response = await request.get('/api/v1/marketplace/platform/timeseries?metric=health_score&days_back=7');
    expect(response.ok()).toBeTruthy();

    const body = await response.json();
    expect(body.statistics).toHaveProperty('trend_direction');
    expect(body.statistics).toHaveProperty('trend_velocity');
    expect(body.statistics).toHaveProperty('trend_confidence');
    expect(['stable', 'improving', 'degrading']).toContain(body.statistics.trend_direction);
    expect(typeof body.statistics.trend_velocity).toBe('number');
    expect(typeof body.statistics.trend_confidence).toBe('number');
  });
});
