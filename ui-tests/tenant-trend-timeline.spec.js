import { test, expect } from '@playwright/test';

test.describe('Tenant Trend Timeline', () => {
  test('renders selection guidance when no tenant is specified', async ({ page }) => {
    await page.goto('/tenant-trend-timeline', { waitUntil: 'networkidle' });

    await expect(page.locator('text=No tenant selected')).toBeVisible();
    await expect(page.locator('text=Health vs Volatility Matrix')).toBeVisible();
  });

  test('loads tenant trend page structure when tenant_id is provided', async ({ page }) => {
    await page.goto('/tenant-trend-timeline?tenant_id=test-tenant&tenant_name=Test+Tenant', { waitUntil: 'networkidle' });

    await expect(page.locator('text=Tenant Trend Timeline')).toBeVisible();
    await expect(page.locator('#statTenantName')).toHaveText('Test Tenant');
    await expect(page.locator('text=Health Timeline')).toBeVisible();
  });
});
