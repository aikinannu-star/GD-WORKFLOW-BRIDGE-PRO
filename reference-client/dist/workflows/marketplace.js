import { normalizeSdkError } from '../errors.js';
export async function runMarketplaceDemo(client) {
    console.log('Starting marketplace demo...');
    const products = await client.marketplaceListMarketplaceProducts({
        skip: 0,
        limit: 10
    });
    console.log(`Found ${products.items?.length ?? 0} marketplace products`);
    if (Array.isArray(products.items) && products.items.length > 0) {
        console.log('Listing first three product entries:');
        products.items.slice(0, 3).forEach((product, index) => {
            console.log(`${index + 1}. ${product.name ?? product.id ?? '<unknown>'}`);
        });
    }
    try {
        const firstProductId = products.items?.[0]?.id;
        if (firstProductId) {
            const product = await client.marketplaceGetMarketplaceProducts({
                productId: firstProductId
            });
            console.log(`Selected product name: ${product.name ?? '<unknown>'}`);
        }
    }
    catch (error) {
        console.warn('Marketplace product detail retrieval failed. This may indicate a path-parameter SDK issue.');
        console.warn(normalizeSdkError(error));
    }
    try {
        const plugins = await client.marketplaceListMarketplacePlugins({
            skip: 0,
            limit: 5
        });
        console.log(`Marketplace has ${plugins.items?.length ?? 0} plugins available`);
    }
    catch (error) {
        console.warn('Marketplace plugin listing failed:', normalizeSdkError(error));
    }
    try {
        const snapshots = await client.marketplaceListMarketplaceSnapshots({
            skip: 0,
            limit: 5
        });
        console.log(`Marketplace snapshots returned ${snapshots.items?.length ?? 0} entries`);
    }
    catch (error) {
        console.warn('Marketplace snapshot listing failed:', normalizeSdkError(error));
    }
    console.log('Marketplace demo complete.');
}
