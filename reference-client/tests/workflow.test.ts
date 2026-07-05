import { describe, expect, it } from 'vitest';
import { authenticateClient } from '../src/auth/authenticate.js';

describe('Reference client workflow', () => {
  it('authenticates and constructs client', async () => {
    const client = authenticateClient();
    expect(client).toBeDefined();
  });

  it('can perform marketplace list', async () => {
    const client = authenticateClient();
    const result = await client.marketplaceListMarketplaceProducts({ skip: 0, limit: 1 });
    expect(result).toBeDefined();
    expect(Array.isArray(result.data?.items)).toBe(true);
  });

  it('can query intelligence health', async () => {
    const client = authenticateClient();
    const health = await client.intelligenceListIntelligencehealth();
    expect(health).toBeDefined();
  });
});
