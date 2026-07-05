/**
 * Tenant SDK example (TypeScript)
 *
 * Prereqs:
 *   php -S 127.0.0.1:8009 -t services/tenant services/tenant/server.php
 *
 * Run:
 *   npx ts-node examples/tenant-usage.ts
 */

import { GDClient } from '../src/gdClient';

async function main() {
  const client = new GDClient();
  try {
    const createRes = await client.createTenant(
      'Example Corp',
      'example.local',
      { logo_url: 'https://placehold.co/128x128?text=Example' },
      { plan: 'pro' },
      { cms: true }
    );
    console.log('createTenant response:', createRes);

    const tenantId = (createRes && (createRes.tenant || (createRes as any).id)) || null;
    if (!tenantId) {
      console.warn('Could not determine tenant id from response; stopping.');
      return;
    }

    const getRes = await client.getTenant(tenantId as string);
    console.log('getTenant:', getRes);

    const updateRes = await client.updateTenant(tenantId as string, { name: 'Example Corp (Updated)' });
    console.log('updateTenant:', updateRes);

    const settings = await client.getTenantSettings(tenantId as string);
    console.log('getTenantSettings:', settings);

    const list = await client.listTenants();
    console.log('listTenants:', list);
  } catch (err) {
    console.error('Tenant flow error:', err);
  }
}

main();
