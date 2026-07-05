import { config } from '../../config.js';
import { createSdk, SdkError } from '../../sdk.js';

async function main() {
  const sdk = createSdk({
    basePath: config.apiBaseUrl,
    accessToken: config.apiToken,
    timeout: config.requestTimeoutMs,
  });

  console.log('Marketplace DX journey started');

  const products = await sdk.marketplace.listProducts({ skip: 0, limit: 5 });
  console.log(`Found ${products.items?.length ?? 0} products`);

  if (Array.isArray(products.items) && products.items.length > 0) {
    const firstProduct = products.items[0];
    console.log('Inspecting first product:', firstProduct.id ?? firstProduct.name ?? '<unknown>');
    const product = await sdk.marketplace.getProduct(firstProduct.id);
    const productData = product && typeof product === 'object' && 'data' in product ? (product as any).data : product;
    console.log('Product detail:', { id: productData?.id, name: productData?.name, summary: productData?.summary });
  }

  const plugins = await sdk.marketplace.listPlugins({ skip: 0, limit: 5 });
  console.log(`Found ${plugins.items?.length ?? 0} marketplace plugins`);

  const snapshots = await sdk.marketplace.listSnapshots({ skip: 0, limit: 5 });
  console.log(`Found ${snapshots.items?.length ?? 0} snapshots`);

  if (config.tenantId) {
    const tenant = await sdk.marketplace.getTenant(config.tenantId);
    const tenantData = tenant && typeof tenant === 'object' && 'data' in tenant ? (tenant as any).data : tenant;
    console.log('Tenant health summary:', { id: tenantData?.id, name: tenantData?.name, status: tenantData?.status });

    const trends = await sdk.marketplace.getTenantTrends(config.tenantId);
    console.log('Tenant trends:', trends);
  }

  const intelligence = await sdk.intelligence.health();
  console.log('Intelligence health:', intelligence);

  const learning = await sdk.intelligence.consolidated();
  console.log('Intelligence learning summary:', learning);

  console.log('Marketplace DX journey complete');
}

main().catch((error) => {
  if (error instanceof SdkError) {
    console.error('SDK error:', error.message, { status: error.status, code: error.code, requestId: error.requestId });
  } else {
    console.error('Unexpected error:', error);
  }
  process.exit(1);
});
