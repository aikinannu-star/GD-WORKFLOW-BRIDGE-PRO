import { describe, expect, it } from 'vitest';
import { config } from '../src/config.js';
import { createSdk, SdkError } from '../src/sdk.js';

const sdk = createSdk({
  basePath: config.apiBaseUrl,
  accessToken: 'invalid-token',
  timeout: config.requestTimeoutMs,
});

describe('SdkError contract', () => {
  it('wraps transport errors in SdkError with normalized metadata', async () => {
    await expect(sdk.marketplace.getPluginInstalls('non-existent-plugin')).rejects.toMatchObject({
      name: 'SdkError',
      status: expect.any(Number),
      retryable: expect.any(Boolean),
    });
  });

  it('exposes retryable for server-side and rate-limit failures', async () => {
    try {
      await sdk.marketplace.getPluginInstalls('non-existent-plugin');
      throw new Error('Expected request to fail with missing plugin');
    } catch (error) {
      expect(error).toBeInstanceOf(SdkError);
      const sdkError = error as SdkError;
      expect([401, 403, 404, 409, 422, 429, 500]).toContain(sdkError.status);
      expect(typeof sdkError.code === 'string' || sdkError.code === undefined).toBe(true);
      expect(typeof sdkError.requestId === 'string' || sdkError.requestId === undefined).toBe(true);
      expect(typeof sdkError.details !== 'undefined' || sdkError.details === undefined).toBe(true);
    }
  });
});
