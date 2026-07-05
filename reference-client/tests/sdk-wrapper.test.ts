import { describe, expect, it } from 'vitest';
import { config } from '../src/config.js';
import { createSdk, SdkError } from '../src/sdk.js';

describe('SDK wrapper and DX surface', () => {
  it('exports marketplace and intelligence namespaces', () => {
    const sdk = createSdk({
      basePath: config.apiBaseUrl,
      accessToken: config.apiToken,
      timeout: config.requestTimeoutMs,
    });

    expect(typeof sdk.marketplace.listProducts).toBe('function');
    expect(typeof sdk.marketplace.getProduct).toBe('function');
    expect(typeof sdk.intelligence.health).toBe('function');
    expect(typeof sdk.intelligence.consolidated).toBe('function');
  });

  it('wraps SDK errors in SdkError', async () => {
    const sdk = createSdk({
      basePath: config.apiBaseUrl,
      accessToken: config.apiToken,
      timeout: config.requestTimeoutMs,
    });

    await expect(sdk.marketplace.getProduct('invalid-id')).rejects.toThrow(SdkError);
  });
});
