const { GDClient } = require('../src/gdClient');
const { generateKeyPairSync } = require('crypto');

async function run() {
  const client = new GDClient();

  const plugin = await client.registerPlugin({ name: 'RotateTest Plugin', version: '0.0.1', description: 'rotation test', author: 'sec' });
  console.log('registered plugin:', plugin.id);
  const pluginId = plugin.id;

  // generate keyA and register
  const { publicKey: pubA, privateKey: privA } = generateKeyPairSync('rsa', { modulusLength: 2048, publicKeyEncoding: { type: 'spki', format: 'pem' }, privateKeyEncoding: { type: 'pkcs8', format: 'pem' } });
  const keyEntry = await client.registerPluginKey(pluginId, pubA, 'keyA');
  console.log('registered keyA:', keyEntry.id);

  // sign and upload version - should succeed
  const manifest = { name: 'RotateTest Plugin', version: '1.0.0', entrypoint: 'index.js' };
  const sig = client.signManifest(manifest, privA);
  const v = await client.addPluginVersion(pluginId, { version: '1.0.0', manifest, signature: sig });
  console.log('added version succeeded');

  // revoke key
  const revoked = await client.revokePluginKey(pluginId, keyEntry.id);
  console.log('revoked key:', revoked.id);

  // attempt to add a new version signed with the revoked key - should fail
  try {
    const sig2 = client.signManifest({ name: 'RotateTest Plugin', version: '1.1.0', entrypoint: 'index.js' }, privA);
    await client.addPluginVersion(pluginId, { version: '1.1.0', manifest: { name: 'RotateTest Plugin', version: '1.1.0', entrypoint: 'index.js' }, signature: sig2 });
    console.error('FAILED: accepted version signed with revoked key');
    process.exitCode = 2;
    return;
  } catch (err) {
    const body = err && err.body ? err.body : err;
    if (body && body.error === 'invalid_signature') {
      console.log('PASS: revoked key signature rejected');
    } else {
      console.error('FAIL: unexpected response after revocation', body);
      process.exitCode = 3;
      return;
    }
  }

  // reactivate key
  const activated = await client.activatePluginKey(pluginId, keyEntry.id);
  console.log('activated key:', activated.id);

  // now signing should succeed again
  const sig3 = client.signManifest({ name: 'RotateTest Plugin', version: '1.2.0', entrypoint: 'index.js' }, privA);
  await client.addPluginVersion(pluginId, { version: '1.2.0', manifest: { name: 'RotateTest Plugin', version: '1.2.0', entrypoint: 'index.js' }, signature: sig3 });
  console.log('PASS: re-activated key accepted signatures');

  console.log('Key rotation tests completed');
}

run().catch(e => { console.error('error', e); process.exitCode = 10; });
