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
    console.log('ts unit: GDClient surface exists');
}
smoke();
