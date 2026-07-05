"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
const gdClient_1 = require("../src/gdClient");
async function run() {
    const client = new gdClient_1.GDClient();
    // Create dependency plugin A and version
    const pluginA = await client.registerPlugin({ name: 'DepA', version: '0.0.1', description: 'dependency A', author: 'dev' });
    await client.addPluginVersion(pluginA.id, { version: '1.0.0', manifest: { name: 'DepA', version: '1.0.0', entrypoint: 'index.js' } });
    // Create plugin B that depends on A@1.0.0
    const pluginB = await client.registerPlugin({ name: 'DepB', version: '0.0.1', description: 'dependency B', author: 'dev' });
    const manifestB = { name: 'DepB', version: '1.0.0', entrypoint: 'index.js', dependencies: [{ plugin_id: pluginA.id, version: '1.0.0' }] };
    await client.addPluginVersion(pluginB.id, { version: '1.0.0', manifest: manifestB });
    // Create tenant
    const tenantRes = await client.createTenant('Dep Tenant', `dep-${Date.now()}.local`);
    const tenantId = (tenantRes && (tenantRes.tenant || tenantRes).id);
    // Try to install B without auto-install -> expect missing_dependencies
    try {
        await client.installPlugin(pluginB.id, tenantId, '1.0.0');
        console.error('FAILED: installed plugin with missing dependencies');
        process.exitCode = 2;
        return;
    }
    catch (err) {
        const body = err && err.body ? err.body : err;
        if (body && body.error === 'missing_dependencies') {
            console.log('PASS: missing dependencies detected');
        }
        else {
            console.error('FAIL: unexpected response when dependencies missing', body);
            process.exitCode = 3;
            return;
        }
    }
    // Now install with auto_install_dependencies
    const res = await client.installPlugin(pluginB.id, tenantId, '1.0.0', { auto_install_dependencies: true });
    console.log('installed with auto-deps:', res.status);
    // Verify both installs exist
    const installsB = await client.listPluginInstalls(pluginB.id, tenantId);
    const installsA = await client.listPluginInstalls(pluginA.id, tenantId);
    if (installsB && installsB.items && installsA && installsA.items && installsA.items.length > 0) {
        console.log('PASS: dependency auto-installed and parent installed');
    }
    else {
        console.error('FAIL: expected installs for both B and A', { installsA, installsB });
        process.exitCode = 4;
    }
}
run().catch(e => { console.error('error', e); process.exitCode = 10; });
