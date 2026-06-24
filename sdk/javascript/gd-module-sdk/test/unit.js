const assert = require('assert');
const { GDClient } = require('../src/gdClient');

function smoke() {
  const c = new GDClient();
  assert.strictEqual(typeof c.getHealth, 'function');
  assert.strictEqual(typeof c.listMarketplaceProducts, 'function');
  assert.strictEqual(typeof c.evaluatePolicy, 'function');
  assert.strictEqual(typeof c.authLogin, 'function');
  assert.strictEqual(typeof c.authIntrospect, 'function');
  assert.strictEqual(typeof c.createProject, 'function');
  assert.strictEqual(typeof c.listPlugins, 'function');
  assert.strictEqual(typeof c.registerPlugin, 'function');
  assert.strictEqual(typeof c.listPluginVersions, 'function');
  assert.strictEqual(typeof c.addPluginVersion, 'function');
  assert.strictEqual(typeof c.installPlugin, 'function');
  assert.strictEqual(typeof c.uninstallPlugin, 'function');
  assert.strictEqual(typeof c.listPluginInstalls, 'function');
  assert.strictEqual(typeof c.createTenant, 'function');
  assert.strictEqual(typeof c.getTenant, 'function');
  assert.strictEqual(typeof c.updateTenant, 'function');
  assert.strictEqual(typeof c.getTenantSettings, 'function');
  assert.strictEqual(typeof c.listTenants, 'function');
  console.log('unit: GDClient surface exists and methods are callable');
}

try {
  smoke();
  process.exit(0);
} catch (e) {
  console.error('unit test failed:', e && e.stack ? e.stack : e);
  process.exit(2);
}
