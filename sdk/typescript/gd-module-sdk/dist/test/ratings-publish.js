"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
const gdClient_1 = require("../src/gdClient");
async function run() {
    const client = new gdClient_1.GDClient();
    const plugin = await client.registerPlugin({ name: 'RatePub Plugin', version: '0.0.1', description: 'rating test', author: 'dev' });
    console.log('registered plugin:', plugin.id);
    const pluginId = plugin.id;
    // Unpublish and check
    const un = await client.unpublishPlugin(pluginId);
    if (un && un.published === false)
        console.log('PASS: unpublish');
    else {
        console.error('FAIL: unpublish', un);
        process.exitCode = 2;
        return;
    }
    const pub = await client.publishPlugin(pluginId);
    if (pub && pub.published === true)
        console.log('PASS: publish');
    else {
        console.error('FAIL: publish', pub);
        process.exitCode = 3;
        return;
    }
    // Rating
    const r = await client.ratePlugin(pluginId, 5, 'Excellent', null);
    console.log('rating created:', r.id);
    const list = await client.listPluginRatings(pluginId);
    if (list && list.items && list.items.length > 0)
        console.log('PASS: rating listed');
    else {
        console.error('FAIL: rating not listed', list);
        process.exitCode = 4;
    }
}
run().catch(e => { console.error('error', e); process.exitCode = 10; });
