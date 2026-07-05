const { test, expect } = require('@playwright/test');
const { spawn } = require('child_process');
const net = require('net');
const path = require('path');

const ROOT = path.resolve(__dirname, '..');
const BASE_URL = process.env.BASE_URL || 'http://127.0.0.1:8006';
const SKIP_SERVER_START = process.env.SKIP_SERVER_START === '1';
let phpServer = null;

function checkPort(host, port) {
  return new Promise((resolve) => {
    const socket = new net.Socket();
    socket.setTimeout(1000);
    socket.once('connect', () => {
      socket.destroy();
      resolve(true);
    });
    socket.once('error', () => {
      socket.destroy();
      resolve(false);
    });
    socket.once('timeout', () => {
      socket.destroy();
      resolve(false);
    });
    socket.connect(port, host);
  });
}

async function waitForPort(host, port, timeoutMs = 10000) {
  const start = Date.now();
  while (Date.now() - start < timeoutMs) {
    if (await checkPort(host, port)) {
      return true;
    }
    await new Promise((resolve) => setTimeout(resolve, 200));
  }
  return false;
}

async function loadFirstPlugin(page) {
  await page.goto(`${BASE_URL}/marketplace-ui`);
  await expect(page.locator('h2')).toHaveText('Marketplace Admin UI');

  const row = page.locator('table#plugins tbody tr').first();
  await expect(row.locator('a')).toHaveCount(1);
  const href = await row.locator('a').first().getAttribute('href');
  if (!href) throw new Error('Unable to read plugin detail link');

  const match = href.match(/\/marketplace-ui\/plugins\/(.+)$/);
  if (!match) throw new Error('Plugin link did not match expected pattern');

  return {
    row,
    pluginId: decodeURIComponent(match[1]),
    pluginLink: row.locator('a').first(),
  };
}

test.beforeAll(async () => {
  if (SKIP_SERVER_START) {
    return;
  }

  phpServer = spawn('php', ['-S', '127.0.0.1:8006', 'services/marketplace/server.php'], {
    cwd: ROOT,
    stdio: 'ignore',
  });

  const started = await waitForPort('127.0.0.1', 8006, 10000);
  if (!started) {
    if (phpServer) {
      phpServer.kill();
    }
    throw new Error('Marketplace service did not start on port 8006');
  }
  await new Promise((resolve) => setTimeout(resolve, 250));
});

test.afterAll(async () => {
  if (phpServer) {
    phpServer.kill();
    phpServer = null;
  }
});

test('Playwright navigation test: list and detail', async ({ page }) => {
  const { pluginLink } = await loadFirstPlugin(page);
  await Promise.all([
    page.waitForURL(/\/marketplace-ui\/plugins\//),
    pluginLink.click(),
  ]);

  await expect(page.locator('h2')).toHaveText('Plugin Detail');
  await expect(page.locator('a', { hasText: 'Back to list' })).toBeVisible();
  await page.click('a:has-text("Back to list")');
  await expect(page.locator('h2')).toHaveText('Marketplace Admin UI');
});

test('Metadata edit test: update plugin title and version', async ({ page }) => {
  const { pluginId, pluginLink } = await loadFirstPlugin(page);
  await Promise.all([
    page.waitForURL(new RegExp(`/marketplace-ui/plugins/${pluginId}`)),
    pluginLink.click(),
  ]);

  await expect(page.locator('#editMetaBtn')).toBeVisible();
  await page.click('#editMetaBtn');
  await page.fill('#metaName', 'Playwright Metadata Test');
  await page.fill('#metaVersion', '9.9.9');
  await page.fill('#metaDesc', 'Updated by Playwright metadata edit test.');

  const [alert] = await Promise.all([
    page.waitForEvent('dialog'),
    page.click('#saveMetaBtn'),
  ]);
  expect(alert.type()).toBe('alert');
  await alert.accept();

  await expect(page.locator('h2')).toHaveText('Plugin Detail');
  await expect(page.locator('#meta')).toContainText('Playwright Metadata Test');
  await expect(page.locator('#meta')).toContainText('9.9.9');

  // Validate backend persisted the metadata change
  const plugin = await page.evaluate(async (id) => {
    const r = await fetch(`/api/v1/marketplace/plugins/${encodeURIComponent(id)}`, { headers: { Accept: 'application/json' } });
    return r.ok ? await r.json() : null;
  }, pluginId);
  expect(plugin).not.toBeNull();
  expect(plugin.name).toBe('Playwright Metadata Test');
  expect(plugin.version).toBe('9.9.9');
});

test('Publish/Unpublish test: toggle plugin visibility', async ({ page }) => {
  const { row, pluginLink, pluginId } = await loadFirstPlugin(page);
  const initialActionButton = row.locator('button', { hasText: /Publish|Unpublish/ }).first();
  const initialLabel = (await initialActionButton.textContent()).trim();

  const [dialog] = await Promise.all([
    page.waitForEvent('dialog'),
    initialActionButton.click(),
  ]);
  expect(dialog.type()).toBe('alert');
  await dialog.accept();
  // Verify backend published state changed
  const after = await page.evaluate(async (id) => {
    const r = await fetch(`/api/v1/marketplace/plugins/${encodeURIComponent(id)}`, { headers: { Accept: 'application/json' } });
    return r.ok ? await r.json() : null;
  }, pluginId);
  expect(after).not.toBeNull();
  const expectedPublished = initialLabel === 'Publish';
  expect(after.published).toBe(expectedPublished);

  // toggle back
  const toggledButton = page.locator('table#plugins tbody tr').first().locator('button', { hasText: initialLabel }).first();
  const [dialog2] = await Promise.all([
    page.waitForEvent('dialog'),
    toggledButton.click(),
  ]);
  expect(dialog2.type()).toBe('alert');
  await dialog2.accept();
  const restored = await page.evaluate(async (id) => {
    const r = await fetch(`/api/v1/marketplace/plugins/${encodeURIComponent(id)}`, { headers: { Accept: 'application/json' } });
    return r.ok ? await r.json() : null;
  }, pluginId);
  expect(restored.published).toBe(!expectedPublished);
});

test('Install/Uninstall test: tenant-scoped install and verify backend', async ({ page }) => {
  const { row, pluginLink, pluginId } = await loadFirstPlugin(page);
  // set tenant input
  await page.fill('#tenantId', 'e2e-tenant-1');
  const installBtn = row.locator('button', { hasText: 'Install' }).first();
  // accept confirm
  page.once('dialog', d => d.accept(true));
  await installBtn.click();
  // wait a short while for install to be saved
  await page.waitForTimeout(200);

  const installs = await page.evaluate(async (id) => {
    const r = await fetch(`/api/v1/marketplace/plugins/${encodeURIComponent(id)}/installs?tenant_id=e2e-tenant-1`, { headers: { Accept: 'application/json' } });
    return r.ok ? await r.json() : null;
  }, pluginId);
  expect(installs).not.toBeNull();
  expect(installs.items && installs.items.some(i => i.tenant_id === 'e2e-tenant-1' && i.status === 'installed')).toBe(true);

  // uninstall
  const uninstallBtn = row.locator('button', { hasText: 'Uninstall' }).first();
  page.once('dialog', d => d.accept(true));
  await uninstallBtn.click();
  await page.waitForTimeout(200);
  const afterUn = await page.evaluate(async (id) => {
    const r = await fetch(`/api/v1/marketplace/plugins/${encodeURIComponent(id)}/installs?tenant_id=e2e-tenant-1`, { headers: { Accept: 'application/json' } });
    return r.ok ? await r.json() : null;
  }, pluginId);
  expect(afterUn).not.toBeNull();
  expect(afterUn.items && afterUn.items.some(i => i.tenant_id === 'e2e-tenant-1' && i.status === 'uninstalled')).toBe(true);
});

test('Snapshot API test: create and list snapshots', async ({ page }) => {
  // create snapshot
  const created = await page.evaluate(async () => {
    const r = await fetch('/api/v1/marketplace/snapshots', { method: 'POST' });
    return r.ok ? await r.json() : null;
  });
  expect(created).not.toBeNull();
  expect(created.id).toBeTruthy();

  const list = await page.evaluate(async () => {
    const r = await fetch('/api/v1/marketplace/snapshots');
    return r.ok ? await r.json() : null;
  });
  expect(list).not.toBeNull();
  expect(list.items.some(it => it.id === created.id)).toBe(true);
});

test('Key management test: add and delete plugin key', async ({ page }) => {
  const { pluginId, pluginLink } = await loadFirstPlugin(page);
  await Promise.all([
    page.waitForURL(new RegExp(`/marketplace-ui/plugins/${pluginId}`)),
    pluginLink.click(),
  ]);

  await expect(page.locator('#addKeyBtn')).toBeVisible();
  await page.click('#addKeyBtn');
  await page.fill('#newKeyLabel', 'Playwright UI Key');
  await page.fill('#newKeyPem', '-----BEGIN PUBLIC KEY-----\nMFwwDQYJKoZIhvcNAQEBBQADSwAwSAJBALdFljM2GpW1YMS8a7TI6QBr8pXnWdU4\nHwIDAQAB\n-----END PUBLIC KEY-----');

  const [saveAlert] = await Promise.all([
    page.waitForEvent('dialog'),
    page.click('#saveKeyBtn'),
  ]);
  expect(saveAlert.type()).toBe('alert');
  await saveAlert.accept();

  const addedKey = page.locator('#keysList li', { hasText: 'Playwright UI Key' }).first();
  await expect(addedKey).toBeVisible();

  const deleteButton = addedKey.locator('button', { hasText: 'Delete' }).first();
  await deleteButton.click();
  await expect(page.locator('#keysList li', { hasText: 'Playwright UI Key' })).toHaveCount(0);
});

test('Ratings test: submit rating and verify backend stored it', async ({ page }) => {
  const { row, pluginLink, pluginId } = await loadFirstPlugin(page);
  const ratingButton = row.locator('button', { hasText: 'Rate' }).first();

  const firstPrompt = page.waitForEvent('dialog');
  await ratingButton.click();
  const dialog1 = await firstPrompt;
  expect(dialog1.type()).toBe('prompt');
  await dialog1.accept('5');

  const secondPrompt = page.waitForEvent('dialog');
  const dialog2 = await secondPrompt;
  expect(dialog2.type()).toBe('prompt');
  await dialog2.accept('Automated rating from Playwright');

  const alert = await page.waitForEvent('dialog');
  expect(alert.type()).toBe('alert');
  await alert.accept();

  const ratings = await page.evaluate(async (id) => {
    const response = await fetch(`/api/v1/marketplace/plugins/${encodeURIComponent(id)}/ratings`, {
      headers: { Accept: 'application/json' },
    });
    return response.json();
  }, pluginId);

  expect(ratings.items).toBeTruthy();
  expect(ratings.items.some((rating) => rating.rating === 5 && rating.comment === 'Automated rating from Playwright')).toBe(true);
});

test('Dependency graph visualization UI test: render graph page', async ({ page }) => {
  await page.goto(`${BASE_URL}/dep-graph`);
  await expect(page.locator('h2')).toHaveText('Marketplace Dependency Graph');
  await expect(page.locator('a', { hasText: 'Download mermaid source' })).toBeVisible();
});
