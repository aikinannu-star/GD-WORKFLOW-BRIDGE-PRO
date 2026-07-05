import { describe, expect, it } from 'vitest';
import { config } from '../src/config.js';
import { createSdk } from '../src/sdk.js';

const sdk = createSdk({
  basePath: config.apiBaseUrl,
  accessToken: config.apiToken,
  timeout: config.requestTimeoutMs,
});

describe('Tenant workflow', () => {
  it('verifies tenant health, trends, drift, and risk classification', async () => {
    const tenants = await sdk.marketplace.listTenants({ skip: 0, limit: 5 });
    expect(Array.isArray(tenants.items)).toBe(true);

    const tenantId = tenants.items[0]?.id ?? config.tenantId;
    expect(tenantId).toBeTruthy();

    const tenant = await sdk.marketplace.getTenant(tenantId);
    expect(tenant).toHaveProperty('tenant_id', tenantId);

    const trends = await sdk.marketplace.getTenantTrends(tenantId);
    expect(trends).toBeDefined();

    const drift = await sdk.platform.getDriftSummary({ skip: 0, limit: 5 });
    expect(drift).toBeDefined();

    const riskZones = await sdk.risk.listZones({ skip: 0, limit: 5 });
    expect(riskZones).toBeDefined();
  });
});
