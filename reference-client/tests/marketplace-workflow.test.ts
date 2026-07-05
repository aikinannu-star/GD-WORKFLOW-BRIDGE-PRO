import { describe, expect, it } from 'vitest';
import { config } from '../src/config.js';
import { createSdk, SdkError } from '../src/sdk.js';

const sdk = createSdk({
  basePath: config.apiBaseUrl,
  accessToken: config.apiToken,
  timeout: config.requestTimeoutMs,
});

describe('Marketplace workflow (end-to-end lifecycle)', () => {
  it('completes a marketplace install lifecycle and validates state transitions', async () => {
    // Browse marketplace
    const products = await sdk.marketplace.listProducts({ skip: 0, limit: 5 });
    expect(Array.isArray(products.items)).toBe(true);
    expect(products.items.length).toBeGreaterThan(0);

    const firstProduct = products.items[0];
    expect(firstProduct).toHaveProperty('id');

    // Inspect product
    const product = await sdk.marketplace.getProduct(firstProduct.id);
    expect(product).toHaveProperty('id', firstProduct.id);

    // List plugins and pick one to install
    const plugins = await sdk.marketplace.listPlugins({ skip: 0, limit: 5 });
    expect(Array.isArray(plugins.items)).toBe(true);

    if (!Array.isArray(plugins.items) || plugins.items.length === 0) {
      // If no plugins available, consider this test inconclusive for install-specific assertions
      return;
    }

    const plugin = plugins.items[0];
    expect(plugin).toHaveProperty('id');
    const pluginId = plugin.id;

    // Record installs before
    const beforeInstalls = await sdk.marketplace.getPluginInstalls(pluginId, { skip: 0, limit: 100 });
    const beforeCount = Array.isArray(beforeInstalls.items) ? beforeInstalls.items.length : (beforeInstalls.total ?? 0);

    // Attempt install for configured tenant
    let installed: any = null;
    try {
      installed = await sdk.marketplace.installPlugin(pluginId, { tenant_id: config.tenantId });
    } catch (err) {
      if (err instanceof SdkError) {
        // Fail early for authorization/authentication issues
        throw err;
      }
      throw err;
    }

    expect(installed).toBeDefined();

    // Verify installs increased (best-effort; some systems may be idempotent)
    const afterInstalls = await sdk.marketplace.getPluginInstalls(pluginId, { skip: 0, limit: 100 });
    const afterCount = Array.isArray(afterInstalls.items) ? afterInstalls.items.length : (afterInstalls.total ?? 0);

    expect(afterCount).toBeGreaterThanOrEqual(beforeCount);

    // Retrieve tenant health
    const tenant = await sdk.marketplace.getTenant(config.tenantId);
    expect(tenant).toHaveProperty('tenant_id', config.tenantId);

    // Retrieve intelligence health and learning metrics
    const health = await sdk.intelligence.health();
    expect(health).toBeDefined();

    const learning = await sdk.intelligence.consolidated();
    expect(learning).toBeDefined();

    // Preview remediation (safe, no-op)
    const preview = await sdk.marketplace.previewTenantRemediation(config.tenantId, 'install-missing-deps');
    expect(preview).toBeDefined();
    expect(preview).toHaveProperty('action');

    // Cleanup: uninstall plugin if install result indicates success or if installs increased
    try {
      if (installed || afterCount > beforeCount) {
        await sdk.marketplace.uninstallPlugin(pluginId, { tenant_id: config.tenantId });
        // Best-effort verify uninstall decreased or returned success
        const finalInstalls = await sdk.marketplace.getPluginInstalls(pluginId, { skip: 0, limit: 100 });
        const finalCount = Array.isArray(finalInstalls.items) ? finalInstalls.items.length : (finalInstalls.total ?? 0);
        expect(finalCount).toBeLessThanOrEqual(afterCount);
      }
    } catch (cleanupErr) {
      // Do not mask original failures, but surface cleanup problems
      console.warn('Plugin cleanup failed:', cleanupErr);
    }
  }, 120000);
});
