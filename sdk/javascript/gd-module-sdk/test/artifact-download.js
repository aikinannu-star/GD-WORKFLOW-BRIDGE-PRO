const { GDClient } = require('../src/gdClient');
const { generateKeyPairSync, createSign } = require('crypto');

async function run() {
  const client = new GDClient();

  const plugin = await client.registerPlugin({ name: 'ArtifactDL Plugin', version: '0.0.1', description: 'artifact download test', author: 'sec' });
  console.log('registered plugin:', plugin.id);
  const pluginId = plugin.id;

  const { publicKey, privateKey } = generateKeyPairSync('rsa', { modulusLength: 2048, publicKeyEncoding: { type: 'spki', format: 'pem' }, privateKeyEncoding: { type: 'pkcs8', format: 'pem' } });
  const keyEntry = await client.registerPluginKey(pluginId, publicKey, 'artifact-dl-key');

  const raw = Buffer.from('artifact-download-' + Date.now());
  const b64 = raw.toString('base64');
  const signer = createSign('RSA-SHA256');
  signer.update(raw);
  signer.end();
  const signature = signer.sign(privateKey).toString('base64');

  const version = '0.1.0';
  const art = await client.uploadPluginArtifact(pluginId, version, { file_name: 'dl.txt', artifact_base64: b64, signature, public_key: publicKey });
  console.log('uploaded artifact:', art.id);

  const dl = await client.downloadPluginArtifact(pluginId, version, art.id);
  const ok = await client.verifyArtifact(pluginId, dl);
  if (ok) console.log('PASS: downloaded artifact signature verified'); else { console.error('FAIL: verification failed'); process.exitCode = 2; }
}

run().catch(e => { console.error('error', e); process.exitCode = 10; });
