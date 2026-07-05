import { test, expect } from '@playwright/test';

test.describe('Risk Zones API Contract', () => {
  test('GET /api/v1/risk-zones returns canonical zone definitions', async ({ request }) => {
    const response = await request.get('/api/v1/risk-zones');
    expect(response.ok()).toBeTruthy();

    const data = await response.json();

    expect(data).toHaveProperty('healthy');
    expect(data).toHaveProperty('watch');
    expect(data).toHaveProperty('stagnant');
    expect(data).toHaveProperty('critical');
    expect(data).toHaveProperty('degrading');

    expect(data.healthy.name).toBe('Healthy');
    expect(data.watch.name).toBe('Watch');
    expect(data.stagnant.name).toBe('Stagnant');
    expect(data.critical.name).toBe('Critical');
    expect(data.degrading.name).toBe('Degrading');
  });

  test('classify(health=100, volatility=0) returns healthy', async ({ request }) => {
    const response = await request.get('/api/v1/risk-zones/classify?health=100&volatility=0');
    expect(response.ok()).toBeTruthy();

    const result = await response.json();
    expect(result.zone_id).toBe('healthy');
    expect(result.zone_name).toBe('Healthy');
  });

  test('classify(health=20, volatility=0.9) returns degrading', async ({ request }) => {
    const response = await request.get('/api/v1/risk-zones/classify?health=20&volatility=0.9');
    expect(response.ok()).toBeTruthy();

    const result = await response.json();
    expect(result.zone_id).toBe('degrading');
    expect(result.zone_name).toBe('Degrading');
  });
});
