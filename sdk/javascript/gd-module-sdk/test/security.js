const { GDClient } = require('../src/gdClient');
const { generateKeyPairSync } = require('crypto');

async function run() {
  const client = new GDClient();

  // Register plugin
  const plugin = await client.registerPlugin({ name: 'SecTest Plugin', version: '0.0.1', description: 'security test', author: 'sec' });
  console.log('registered plugin:', plugin.id);
  const pluginId = plugin.id;

  // 1) Invalid manifest (missing required 'entrypoint')
  const badManifest = { name: 'BadManifest', version: '1.2.3' };
  try {
    await client.addPluginVersion(pluginId, { version: '1.0.0', manifest: badManifest });
    console.error('FAILED: invalid manifest accepted');
    process.exitCode = 2;
    return;
  } catch (err) {
    const body = err && err.body ? err.body : err;
    if (body && body.error === 'invalid_manifest') {
      console.log('PASS: invalid manifest rejected:', body);
    } else {
      console.error('FAIL: unexpected response for invalid manifest', body);
      process.exitCode = 3;
      return;
    }
  }

  // 2) Invalid signature (signed with unregistered key)
  const { publicKey: pubA, privateKey: privA } = generateKeyPairSync('rsa', { modulusLength: 2048, publicKeyEncoding: { type: 'spki', format: 'pem' }, privateKeyEncoding: { type: 'pkcs8', format: 'pem' } });
  const keyEntry = await client.registerPluginKey(pluginId, pubA, 'keyA');
  console.log('registered key:', keyEntry.id);

  // Sign with a different (unregistered) key
  const { publicKey: pubB, privateKey: privB } = generateKeyPairSync('rsa', { modulusLength: 2048, publicKeyEncoding: { type: 'spki', format: 'pem' }, privateKeyEncoding: { type: 'pkcs8', format: 'pem' } });
  const manifest = { name: 'SecTest Plugin', version: '2.0.0', entrypoint: 'index.js' };
  const sig = client.signManifest(manifest, privB);

  try {
    await client.addPluginVersion(pluginId, { version: '2.0.0', manifest: manifest, signature: sig });
    console.error('FAILED: invalid signature accepted');
    process.exitCode = 4;
    return;
  } catch (err) {
    const body = err && err.body ? err.body : err;
    if (body && body.error === 'invalid_signature') {
      console.log('PASS: invalid signature rejected:', body);
    } else {
      console.error('FAIL: unexpected response for invalid signature', body);
      process.exitCode = 5;
      return;
    }
  }

  console.log('All security tests passed');
}

run().catch(e => { console.error('error', e); process.exitCode = 10; });
