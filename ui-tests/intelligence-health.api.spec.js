const { test, expect } = require('@playwright/test');

test.use({ baseURL: process.env.BASE_URL || 'http://127.0.0.1:8006' });

test('intelligence-health API contract', async ({ request }) => {
  const res = await request.get('/api/v1/intelligence-health');
  expect(res.ok()).toBeTruthy();
  const data = await res.json();
  expect(data).toHaveProperty('trend_confidence');
  expect(data).toHaveProperty('stable_tenants_pct');
  expect(data).toHaveProperty('anomaly_density');
  expect(data).toHaveProperty('remediation_success_rate');
  expect(typeof data.trend_confidence).toBe('number');
  expect(typeof data.stable_tenants_pct).toBe('number');
});
