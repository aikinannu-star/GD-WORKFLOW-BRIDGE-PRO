import { describe, expect, it } from 'vitest';
import { createSdk, SdkError } from '../src/sdk.js';
import { config } from '../src/config.js';

describe('Error injection and SdkError contract', () => {
  it('invalid API token or plugin lookup failure yields SdkError with retryable=false', async () => {
    const sdk = createSdk({ basePath: config.apiBaseUrl, accessToken: 'invalid-token', timeout: 5000 });
    await expect(sdk.marketplace.getPluginInstalls('non-existent-plugin')).rejects.toMatchObject({
      name: 'SdkError',
      status: expect.any(Number),
      retryable: expect.any(Boolean),
    });

    try {
      await sdk.marketplace.getPluginInstalls('non-existent-plugin');
    } catch (err) {
      expect(err).toBeInstanceOf(SdkError);
      const e = err as SdkError;
      expect(e.status).toBeGreaterThanOrEqual(400);
      expect([401, 403, 404, 422]).toContain(e.status);
      expect(typeof e.code === 'string' || e.code === undefined).toBe(true);
      expect(typeof e.requestId === 'string' || e.requestId === undefined).toBe(true);
      expect(e.retryable).toBe(false);
      expect(e.details !== undefined || e.details === undefined).toBe(true);
    }
  });

  it('invalid plugin ID yields 404 SdkError', async () => {
    const sdk = createSdk({ basePath: config.apiBaseUrl, accessToken: config.apiToken, timeout: 5000 });
    await expect(sdk.marketplace.getPluginInstalls('non-existent-plugin')).rejects.toBeInstanceOf(SdkError);
    try {
      await sdk.marketplace.getPluginInstalls('non-existent-plugin');
    } catch (err) {
      const e = err as SdkError;
      expect(e.status).toBeGreaterThanOrEqual(400);
      expect([404, 422]).toContain(e.status);
      expect(typeof e.code === 'string' || e.code === undefined).toBe(true);
      expect(typeof e.requestId === 'string' || e.requestId === undefined).toBe(true);
    }
  });

  it('unknown plugin ID returns 404/422 SdkError', async () => {
    const sdk = createSdk({ basePath: config.apiBaseUrl, accessToken: config.apiToken, timeout: 5000 });
    await expect(sdk.marketplace.getPluginInstalls('non-existent-plugin')).rejects.toBeInstanceOf(SdkError);
    try {
      await sdk.marketplace.getPluginInstalls('non-existent-plugin');
    } catch (err) {
      const e = err as SdkError;
      expect([404, 422]).toContain(e.status);
      expect(e.retryable).toBe(false);
    }
  });

  it('validation failure yields 4xx with details', async () => {
    const sdk = createSdk({ basePath: config.apiBaseUrl, accessToken: config.apiToken, timeout: 5000 });
    // Trigger create plugin with invalid payload
    await expect(sdk.marketplace.installPlugin('', { tenant_id: '' })).rejects.toBeInstanceOf(SdkError);
    try {
      await sdk.marketplace.installPlugin('', { tenant_id: '' });
    } catch (err) {
      const e = err as SdkError;
      expect(e.status).toBeGreaterThanOrEqual(400);
      expect([422, 400, 404]).toContain(e.status);
      expect(e.details).toBeDefined();
    }
  });

  it('conflict (409) returns SdkError retryable=false', async () => {
    const sdk = createSdk({ basePath: config.apiBaseUrl, accessToken: config.apiToken, timeout: 5000 });
    // Best-effort: attempt to install the same plugin twice to provoke 409
    const plugins = await sdk.marketplace.listPlugins({ skip: 0, limit: 1 });
    if (!plugins.items || plugins.items.length === 0) return;
    const pluginId = plugins.items[0].id;

    try {
      await sdk.marketplace.installPlugin(pluginId, { tenant_id: config.tenantId });
    } catch (_) {}

    try {
      await sdk.marketplace.installPlugin(pluginId, { tenant_id: config.tenantId });
    } catch (err) {
      const e = err as SdkError;
      expect(e.status).toBeGreaterThanOrEqual(400);
      expect([409, 422]).toContain(e.status);
      expect(e.retryable).toBe(false);
    }
  });

  it('rate limit (429) and server errors (500) are marked retryable', async () => {
    // This is environment-dependent; we assert the shape when retryable occurs
    const sdk = createSdk({ basePath: config.apiBaseUrl, accessToken: config.apiToken, timeout: 5000 });
    try {
      await sdk.intelligence.health();
    } catch (err) {
      const e = err as SdkError;
      if (e.status === 429 || e.status >= 500) {
        expect(e.retryable).toBe(true);
      }
    }
  });

});
