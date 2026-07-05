import { describe, expect, it } from 'vitest';
import { config } from '../src/config.js';
import { createSdk } from '../src/sdk.js';

const sdk = createSdk({
  basePath: config.apiBaseUrl,
  accessToken: config.apiToken,
  timeout: config.requestTimeoutMs,
});

describe('Operations workflow', () => {
  it('validates platform overview, drift, intelligence, and effectiveness metrics', async () => {
    const drift = await sdk.platform.getDriftSummary({ skip: 0, limit: 5 });
    expect(drift).toBeDefined();

    const overview = await sdk.platform.getTenantOverview({ skip: 0, limit: 5 });
    expect(overview).toBeDefined();

    const health = await sdk.intelligence.health();
    expect(health).toBeDefined();

    const learning = await sdk.intelligence.consolidated();
    expect(learning).toBeDefined();

    const effectiveness = await sdk.intelligence.effectivenessSummary();
    expect(effectiveness).toBeDefined();
  });
});
