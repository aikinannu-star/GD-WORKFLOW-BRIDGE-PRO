/**
 * Plugin Marketplace Flow Example (JavaScript)
 *
 * Prereqs:
 *   php -S 127.0.0.1:8006 -t services/marketplace services/marketplace/server.php
 *   php -S 127.0.0.1:8009 -t services/tenant services/tenant/server.php
 *
 * Run:
 *   node examples/plugin-flow.js
 */

const { GDClient } = require('../src/gdClient');
const { generateKeyPairSync } = require('crypto');

async function main() {
  const client = new GDClient();

  try {
    // Register a new plugin
    const plugin = await client.registerPlugin({ name: 'Example Plugin', version: '0.0.1', description: 'Example plugin', author: 'dev', manifest_url: 'https://example.com/manifest.json' });
    console.log('registered plugin:', plugin);

    const pluginId = plugin.id || plugin['id'];

    // Generate an RSA keypair for signing (dev/demo)
    const { publicKey, privateKey } = generateKeyPairSync('rsa', { modulusLength: 2048, publicKeyEncoding: { type: 'spki', format: 'pem' }, privateKeyEncoding: { type: 'pkcs8', format: 'pem' } });

    // Register the public key with the marketplace for this plugin
    const keyEntry = await client.registerPluginKey(pluginId, publicKey, 'example-key');
    console.log('registered plugin key:', keyEntry);

    // Add a new version with inline manifest + signature
    const manifest = { name: plugin.name || 'example-plugin', version: '0.1.0', entrypoint: 'index.js', permissions: [], assets: [] };
    const signature = client.signManifest(manifest, privateKey);
    const ver = await client.addPluginVersion(pluginId, { version: '0.1.0', manifest: manifest, signature: signature, changelog: 'Initial release' });
    console.log('added version:', ver);

    // Create a tenant to install into
    const tenantRes = await client.createTenant('Plugin Tenant', `plugin-${Date.now()}.local`);
    const tenantId = tenantRes && (tenantRes.tenant || tenantRes).id;
    console.log('created tenant:', tenantId);

    // Install plugin
    const inst = await client.installPlugin(pluginId, tenantId, '0.1.0');
    console.log('installed:', inst);

    // List installs
    const installs = await client.listPluginInstalls(pluginId);
    console.log('installs:', installs);

    // Uninstall
    const un = await client.uninstallPlugin(pluginId, tenantId);
    console.log('uninstalled:', un);
  } catch (err) {
    console.error('plugin flow error:', err && err.body ? err.body : err);
  }
}

main();
