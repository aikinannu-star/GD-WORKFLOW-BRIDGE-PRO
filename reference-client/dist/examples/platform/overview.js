import { config } from '../../config.js';
import { createSdk, SdkError } from '../../sdk.js';
async function main() {
    const sdk = createSdk({
        basePath: config.apiBaseUrl,
        accessToken: config.apiToken,
        timeout: config.requestTimeoutMs,
    });
    console.log('Running platform overview example');
    const products = await sdk.marketplace.listProducts({ skip: 0, limit: 3 });
    console.log(`Marketplace products: ${products.items?.length ?? 0}`);
    const plugins = await sdk.marketplace.listPlugins({ skip: 0, limit: 3 });
    console.log(`Marketplace plugins: ${plugins.items?.length ?? 0}`);
    const snapshots = await sdk.marketplace.listSnapshots({ skip: 0, limit: 3 });
    console.log(`Marketplace snapshots: ${snapshots.items?.length ?? 0}`);
    const intelligence = await sdk.intelligence.health();
    console.log('Intelligence health summary:', intelligence);
    console.log('Platform overview example complete');
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
