const { GDClient } = require('../src/gdClient');

(async () => {
  const client = new GDClient({
    baseUrls: {
      auth: process.env.GD_AUTH || 'http://127.0.0.1:8002',
      billing: process.env.GD_BILLING || 'http://127.0.0.1:8003',
      cms: process.env.GD_CMS || 'http://127.0.0.1:8004',
      marketplace: process.env.GD_MARKETPLACE || 'http://127.0.0.1:8006',
      usage: process.env.GD_USAGE || 'http://127.0.0.1:8007',
      'control-plane': process.env.GD_CONTROL_PLANE || 'http://127.0.0.1:8080',
    }
  });

  const services = ['auth','billing','cms','marketplace','usage','control-plane'];
  for (const s of services) {
    try {
      const res = await client.getHealth(s);
      console.log(`[${s}] health:`, res);
    } catch (e) {
      console.error(`[${s}] health failed:`, e.message, e.body || '');
    }
  }

  try {
    const products = await client.listMarketplaceProducts();
    console.log('marketplace products sample:', Array.isArray(products.items) ? products.items.slice(0,3) : products);
  } catch (e) {
    console.error('marketplace list failed:', e.message);
  }

  try {
    const evalRes = await client.evaluatePolicy({ filePath: 'dummy.txt', content: 'any content' });
    console.log('control-plane evaluate:', evalRes);
  } catch (e) {
    console.error('control-plane evaluate failed:', e.message, e.body || '');
  }

  try {
    const track = await client.trackUsage('tenant_test', 'sdk.sample', 1);
    console.log('usage tracked:', track);
  } catch (e) {
    console.error('usage track failed:', e.message, e.body || '');
  }
})();
