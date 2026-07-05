"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
const gdClient_1 = require("../src/gdClient");
function smoke() {
    const c = new gdClient_1.GDClient();
    if (typeof c.getHealth !== 'function')
        throw new Error('missing getHealth');
    if (typeof c.listMarketplaceProducts !== 'function')
        throw new Error('missing listMarketplaceProducts');
    if (typeof c.evaluatePolicy !== 'function')
        throw new Error('missing evaluatePolicy');
    if (typeof c.authLogin !== 'function')
        throw new Error('missing authLogin');
    if (typeof c.createProject !== 'function')
        throw new Error('missing createProject');
    if (typeof c.createTenant !== 'function')
        throw new Error('missing createTenant');
    if (typeof c.getTenant !== 'function')
        throw new Error('missing getTenant');
    if (typeof c.updateTenant !== 'function')
        throw new Error('missing updateTenant');
    if (typeof c.getTenantSettings !== 'function')
        throw new Error('missing getTenantSettings');
    if (typeof c.listTenants !== 'function')
        throw new Error('missing listTenants');
    if (typeof c.listPluginVersions !== 'function')
        throw new Error('missing listPluginVersions');
    if (typeof c.addPluginVersion !== 'function')
        throw new Error('missing addPluginVersion');
    if (typeof c.installPlugin !== 'function')
        throw new Error('missing installPlugin');
    if (typeof c.uninstallPlugin !== 'function')
        throw new Error('missing uninstallPlugin');
    if (typeof c.listPluginInstalls !== 'function')
        throw new Error('missing listPluginInstalls');
    console.log('ts unit: GDClient surface exists');
}
smoke();
