import { config } from '../../config.js';
import { createSdk, SdkError } from '../../sdk.js';
async function main() {
    const sdk = createSdk({
        basePath: config.apiBaseUrl,
        accessToken: config.apiToken,
        timeout: config.requestTimeoutMs,
    });
    console.log('Running intelligence health example');
    const health = await sdk.intelligence.health();
    console.log('Intelligence health result:', health);
    const summary = await sdk.intelligence.consolidated();
    console.log('Consolidated intelligence learning:', summary);
    const performance = await sdk.intelligence.performance();
    console.log('Learning performance metrics:', performance);
    console.log('Intelligence health example complete');
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
