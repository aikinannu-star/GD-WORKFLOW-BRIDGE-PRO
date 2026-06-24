const http = require('http');
const { GDClient } = require('../src/gdClient');

async function run() {
  const client = new GDClient();
  const received = [];
  const port = 59999;

  const server = http.createServer((req, res) => {
    const chunks = [];
    req.on('data', c => chunks.push(c));
    req.on('end', () => {
      const buf = Buffer.concat(chunks).toString('utf8');
      let body = null;
      try { body = JSON.parse(buf); } catch (e) { body = buf; }
      received.push({ url: req.url, method: req.method, body });
      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ ok: true }));
    });
  });

  await new Promise((res, rej) => server.listen(port, '127.0.0.1', res));
  try {
    const plugin = await client.registerPlugin({ name: 'WebhookRecv Plugin', version: '0.0.1', description: 'webhook receive test', author: 'sec' });
    const pluginId = plugin.id;
    const hookPath = `/webhook-test-${Date.now()}`;
    const webhookUrl = `http://127.0.0.1:${port}${hookPath}`;
    await client.updatePlugin(pluginId, { webhook_url: webhookUrl });

    // create tenant and install plugin
    const tenantRes = await client.createTenant('Webhook Tenant', `hook-${Date.now()}.local`);
    const tenantId = tenantRes && (tenantRes.tenant || tenantRes).id;
    await client.installPlugin(pluginId, tenantId);

    // wait for webhook to be received
    await new Promise(r => setTimeout(r, 500));
    const installWebhooks = received.filter(r => r.body && r.body.event === 'installed');
    if (installWebhooks.length === 0) {
      console.error('FAIL: install webhook not received', received);
      process.exitCode = 2;
      return;
    }
    console.log('PASS: install webhook received');

    // uninstall
    await client.uninstallPlugin(pluginId, tenantId);
    await new Promise(r => setTimeout(r, 500));
    const uninstallWebhooks = received.filter(r => r.body && r.body.event === 'uninstalled');
    if (uninstallWebhooks.length === 0) {
      console.error('FAIL: uninstall webhook not received', received);
      process.exitCode = 3;
      return;
    }
    console.log('PASS: uninstall webhook received');

    console.log('Webhook receiver integration test passed');
  } finally {
    server.close();
  }
}

run().catch(e => { console.error('error', e); process.exitCode = 10; });
