import { describe, expect, it } from 'vitest';
import { config } from '../src/config.js';
import { createSdk } from '../src/sdk.js';

const sdk = createSdk({
  basePath: config.apiBaseUrl,
  accessToken: config.apiToken,
  timeout: config.requestTimeoutMs,
});

describe('Remediation workflow', () => {
  it('records and resolves a remediation event through the SDK', async () => {
    const event = await sdk.remediation.recordEvent({
      tenant_id: config.tenantId,
      action: 'integration-test-action',
      details: { source: 'reference-client-test', timestamp: new Date().toISOString() },
    });

    expect(event).toHaveProperty('id');
    expect(event).toHaveProperty('tenant_id', config.tenantId);

    const resolved = await sdk.remediation.resolveEvent(event.id, {
      resolved_at: new Date().toISOString(),
      success: true,
      resolution_comment: 'Integration test cleanup',
    });

    expect(resolved).toBeDefined();
  });
});
