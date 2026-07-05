/**
 * Plugin Marketplace Flow Example (TypeScript)
 *
 * Prereqs:
 *   php -S 127.0.0.1:8006 -t services/marketplace services/marketplace/server.php
 *   php -S 127.0.0.1:8009 -t services/tenant services/tenant/server.php
 *
 * Run:
 *   npx ts-node examples/plugin-flow.ts
 */

import { GDClient } from '../src/gdClient';
import * as crypto from 'crypto';

async function main() {
  const client = new GDClient();

  try {
    const plugin = await client.registerPlugin({ name: 'Example Plugin', version: '0.0.1', description: 'Example plugin', author: 'dev', manifest_url: 'https://example.com/manifest.json' });
    console.log('registered plugin:', plugin);

    const pluginId = (plugin as any).id;

    const { publicKey, privateKey } = crypto.generateKeyPairSync('rsa', { modulusLength: 2048, publicKeyEncoding: { type: 'spki', format: 'pem' }, privateKeyEncoding: { type: 'pkcs8', format: 'pem' } });
    const keyEntry = await client.registerPluginKey(pluginId, publicKey, 'example-key');
    console.log('registered plugin key:', keyEntry);

    const manifest = { name: (plugin as any).name || 'example-plugin', version: '0.1.0', entrypoint: 'index.js', permissions: [], assets: [] };
    const signature = client.signManifest(manifest, privateKey);
    const ver = await client.addPluginVersion(pluginId, { version: '0.1.0', manifest, signature, changelog: 'Initial release' });
    console.log('added version:', ver);

    const tenantRes = await client.createTenant('Plugin Tenant', `plugin-${Date.now()}.local`);
    const tenantId = (tenantRes && ((tenantRes as any).tenant || (tenantRes as any).id)) as string;
    console.log('created tenant:', tenantId);

    const inst = await client.installPlugin(pluginId, tenantId, '0.1.0');
    console.log('installed:', inst);

    const installs = await client.listPluginInstalls(pluginId);
    console.log('installs:', installs);

    const un = await client.uninstallPlugin(pluginId, tenantId);
    console.log('uninstalled:', un);
  } catch (err) {
    console.error('plugin flow error:', err);
  }
}

main();
