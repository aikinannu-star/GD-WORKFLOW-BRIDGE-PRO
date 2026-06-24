/**
 * Tenant SDK example (JavaScript)
 *
 * Prereqs:
 *   php -S 127.0.0.1:8009 -t services/tenant services/tenant/server.php
 *
 * Run:
 *   node examples/tenant-usage.js
 */

const { GDClient } = require('../src/gdClient');

async function main() {
  const client = new GDClient();
  try {
    // Create a tenant
    const createRes = await client.createTenant(
      'Example Corp',
      'example.local',
      { logo_url: 'https://placehold.co/128x128?text=Example' },
      { plan: 'pro' },
      { cms: true }
    );
    console.log('createTenant response:', createRes);

    // Server returns { success: true, tenant: { ... } }
    const tenantId = (createRes && (createRes.tenant || createRes).id) || null;
    if (!tenantId) {
      console.warn('Could not determine tenant id from response; stopping.');
      return;
    }

    // Get tenant
    const getRes = await client.getTenant(tenantId);
    console.log('getTenant:', getRes);

    // Update tenant
    const updateRes = await client.updateTenant(tenantId, { name: 'Example Corp (Updated)', branding: { primary_color: '#0044ff' } });
    console.log('updateTenant:', updateRes);

    // Get settings
    const settings = await client.getTenantSettings(tenantId);
    console.log('getTenantSettings:', settings);

    // List tenants
    const list = await client.listTenants();
    console.log('listTenants:', list);
  } catch (err) {
    console.error('Tenant flow error:', err && err.body ? err.body : err);
  }
}

main();
