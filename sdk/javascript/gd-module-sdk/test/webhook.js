const { GDClient } = require('../src/gdClient');

async function run() {
  const client = new GDClient();
  const plugin = await client.registerPlugin({ name: 'WebhookTest Plugin', version: '0.0.1', description: 'webhook test', author: 'sec' });
  console.log('registered plugin:', plugin.id);
  const pluginId = plugin.id;

  const url = 'http://127.0.0.1:59999/webhook-test';
  const updated = await client.updatePlugin(pluginId, { webhook_url: url });
  if (updated && updated.webhook_url === url) {
    console.log('PASS: webhook_url updated');
  } else {
    console.error('FAIL: webhook_url not set', updated);
    process.exitCode = 2;
  }
}

run().catch(e => { console.error('error', e); process.exitCode = 10; });
