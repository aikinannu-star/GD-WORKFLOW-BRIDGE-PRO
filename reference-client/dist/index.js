import { authenticateClient } from './auth/authenticate.js';
import { runMarketplaceDemo } from './workflows/marketplace.js';
async function main() {
    const client = await authenticateClient();
    await runMarketplaceDemo(client);
}
main().catch((error) => {
    console.error('Reference client failed:', error);
    process.exit(1);
});
