import { config } from '../../config.js';
import { createSdk, SdkError } from '../../sdk.js';
async function main() {
    if (!config.tenantId) {
        throw new Error('TENANT_ID must be configured to run tenant health example.');
    }
    const sdk = createSdk({
        basePath: config.apiBaseUrl,
        accessToken: config.apiToken,
        timeout: config.requestTimeoutMs,
    });
    console.log(`Running tenant health example for ${config.tenantId}`);
    const tenant = await sdk.marketplace.getTenant(config.tenantId);
    console.log('Tenant health:', tenant);
    const trends = await sdk.marketplace.getTenantTrends(config.tenantId);
    console.log('Tenant trends:', trends);
    console.log('Tenant health example complete');
}
main().catch((error) => {
    if (error instanceof SdkError) {
        console.error('SDK error:', error.message, { status: error.status, code: error.code, requestId: error.requestId });
    }
    else {
        console.error('Unexpected error:', error);
    }
    process.exit(1);
});
