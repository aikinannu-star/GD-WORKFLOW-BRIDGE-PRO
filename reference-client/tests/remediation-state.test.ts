import { describe, expect, it } from 'vitest';
import { config } from '../src/config.js';
import { createSdk } from '../src/sdk.js';

function sleep(ms: number) { return new Promise((r) => setTimeout(r, ms)); }

function resolveHealth(value: any) {
  return (value?.health_score ?? value?.health ?? value?.status ?? 0) as number;
}

async function pollUntil<T>(fn: () => Promise<T>, check: (t: T) => boolean, timeout = 60000, interval = 3000): Promise<T> {
  const start = Date.now();
  // eslint-disable-next-line no-constant-condition
  while (true) {
    const res = await fn();
    if (check(res)) return res;
    if (Date.now() - start > timeout) throw new Error('Timeout waiting for condition');
    await sleep(interval);
  }
}

describe('Remediation state assertions', () => {
  it('executes remediation and verifies tenant health improves or does not regress', async () => {
    const sdk = createSdk({ basePath: config.apiBaseUrl, accessToken: config.apiToken, timeout: 30000 });

    if (!config.tenantId) throw new Error('TENANT_ID required for remediation-state test');

    // Baseline health
    const baseline = await sdk.marketplace.getTenant(config.tenantId);
    const baselineHealth = resolveHealth(baseline);

    // Detect candidate remediation via preview
    const preview = await sdk.marketplace.previewTenantRemediation(config.tenantId, 'install-missing-deps');
    expect(preview).toBeDefined();
    expect(preview).toHaveProperty('projected_health');
    expect(preview).toHaveProperty('current_health');
    const projected = preview.projected_health as number;
    const currentHealth = preview.current_health as number;

    // If not safe to execute, mark this run as intentionally skipped.
    if (preview.safe_to_execute === false) {
      console.info('[SKIP] remediation preview returned safe_to_execute=false; execution was intentionally skipped.');
      expect(preview.safe_to_execute).toBe(false);
      return;
    }

    // Execute remediation
    const exec = await sdk.marketplace.executeTenantRemediation(config.tenantId, 'install-missing-deps');
    expect(exec).toBeDefined();

    // Poll until tenant health meets or exceeds projected_health or timeout
    const final = await pollUntil(
      () => sdk.marketplace.getTenant(config.tenantId),
      (t) => {
        const h = resolveHealth(t);
        return h >= projected || h > baselineHealth || h > currentHealth;
      },
      120000,
      5000
    );

    const finalHealth = resolveHealth(final);

    // Assert improvement or non-regression
    expect(finalHealth).toBeGreaterThanOrEqual(baselineHealth);

    // Verify intelligence metrics updated (best-effort)
    const learning = await sdk.intelligence.consolidated();
    expect(learning).toBeDefined();

  }, 180000);
});
