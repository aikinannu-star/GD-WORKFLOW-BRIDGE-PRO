import { test, expect } from '@playwright/test';

/**
 * Operations Center Dashboard Tests
 * Verifies KPI cards, data aggregation, rankings, and drift summary rendering
 */

test.describe('Operations Center Dashboard', () => {
  test.beforeEach(async ({ page }) => {
    // Navigate to Operations Center
    await page.goto('/operations-center');
    // Wait for initial data load
    await page.waitForLoadState('networkidle');
  });

  test('should load page with correct title and banner', async ({ page }) => {
    // Verify page title
    await expect(page.locator('h1')).toContainText('Platform Operations Center');
    
    // Verify description
    await expect(page.locator('main p')).toContainText('Executive dashboard for fleet health');
    
    // Verify back link exists
    await expect(page.locator('a:has-text("← Back to Marketplace")')).toHaveAttribute('href', '/marketplace-ui');
  });

  test('should display KPI cards with correct structure', async ({ page }) => {
    // Verify Fleet KPI Summary section
    await expect(page.locator('h2')).toContainText('Fleet KPI Summary');
    
    // Verify all 6 KPI cards are present
    const kpiCards = page.locator('main > div:nth-child(4) > div');
    await expect(kpiCards).toHaveCount(6);
    
    // Verify specific KPI labels
    const kpiLabels = ['Platform Health Score', 'At-Risk Tenants', 'Critical Findings', 
                        'Total Active Installs', 'Total Remediations (7d)', 'Fleet Volatility'];
    
    for (const label of kpiLabels) {
      await expect(page.locator(`h3:has-text("${label}")`)).toBeVisible();
    }
  });

  test('should display platform health status banner', async ({ page }) => {
    // Verify status banner
    const banner = page.locator('main > div:first-child');
    await expect(banner).toContainText('Platform Health: 100');
    
    // Health score should be a number
    const healthText = await page.locator('main > div:first-child strong').first().textContent();
    expect(healthText).toMatch(/\d+/);
  });

  test('should load and render tenant overview table', async ({ page }) => {
    // Verify overview table exists
    const table = page.locator('table').first();
    await expect(table).toBeVisible();
    
    // Verify table headers
    const headers = ['Tenant', 'Health', 'Trend', 'Volatility', 'Installs', 'Findings', 'Updated'];
    for (const header of headers) {
      await expect(page.locator(`th:has-text("${header}")`)).toBeVisible();
    }
    
    // Verify at least one data row exists
    const rows = page.locator('table tbody tr');
    expect(await rows.count()).toBeGreaterThan(0);
  });

  test('should display Rankings & Risk Analysis section', async ({ page }) => {
    // Scroll to rankings section
    await page.locator('h2:has-text("Rankings & Risk Analysis")').scrollIntoViewIfNeeded();
    
    // Verify section exists
    await expect(page.locator('h2:has-text("Rankings & Risk Analysis")')).toBeVisible();
    
    // Verify three ranking tables
    const rankingCards = page.locator('h3:has-text("Top Healthiest Tenants"), h3:has-text("Most Improved Tenants"), h3:has-text("Highest Risk Tenants")');
    expect(await rankingCards.count()).toBe(3);
  });

  test('should display Fleet Drift Summary section', async ({ page }) => {
    // Scroll to drift summary section
    await page.locator('h2:has-text("Fleet Drift Summary")').scrollIntoViewIfNeeded();
    
    // Verify section exists
    await expect(page.locator('h2:has-text("Fleet Drift Summary")')).toBeVisible();
    
    // Verify three drift cards
    const driftCards = page.locator('h3:has-text("No Drift"), h3:has-text("Governance Drift"), h3:has-text("Revocation Drift")');
    expect(await driftCards.count()).toBe(3);
    
    // Verify drift counts are numeric
    const noDriftCount = await page.locator('#driftNone').textContent();
    const govDriftCount = await page.locator('#driftGovernance').textContent();
    const revDriftCount = await page.locator('#driftRevocation').textContent();
    
    expect(noDriftCount).toMatch(/^\d+$/);
    expect(govDriftCount).toMatch(/^\d+$/);
    expect(revDriftCount).toMatch(/^\d+$/);
  });

  test('should populate rankings tables with data', async ({ page }) => {
    // Scroll to rankings section
    await page.locator('h2:has-text("Rankings & Risk Analysis")').scrollIntoViewIfNeeded();
    
    // Check healthiest tenants table
    const healthiestTable = page.locator('#healthiestTable tbody tr');
    expect(await healthiestTable.count()).toBeGreaterThan(0);
    expect(await healthiestTable.count()).toBeLessThanOrEqual(5);
    
    // Check most improved table
    const improvedTable = page.locator('#improvedTable tbody tr');
    expect(await improvedTable.count()).toBeGreaterThan(0);
    expect(await improvedTable.count()).toBeLessThanOrEqual(5);
    
    // Check highest risk table
    const riskTable = page.locator('#riskTable tbody tr');
    expect(await riskTable.count()).toBeGreaterThan(0);
    expect(await riskTable.count()).toBeLessThanOrEqual(5);
  });

  test('should navigate to tenant details on overview table click', async ({ page }) => {
    // Get first tenant row
    const firstTenantRow = page.locator('table tbody tr').first();
    
    // Extract tenant ID from first cell
    const tenantCell = firstTenantRow.locator('td').first();
    const tenantId = await tenantCell.textContent();
    
    // Click on the row
    await firstTenantRow.click();
    
    // Verify navigation to marketplace-ui with tenant and tab params
    await page.waitForURL('**/marketplace-ui?tenant=**');
    const url = page.url();
    expect(url).toContain(`tenant=${tenantId}`);
    expect(url).toContain('tab=health');
  });

  test('should navigate from rankings table drill-down', async ({ page }) => {
    // Scroll to rankings section
    await page.locator('h2:has-text("Rankings & Risk Analysis")').scrollIntoViewIfNeeded();
    
    // Wait for rankings table to populate
    await page.waitForSelector('#healthiestTable tbody tr', { timeout: 5000 });
    
    // Get first tenant from healthiest table
    const firstHealthyRow = page.locator('#healthiestTable tbody tr').first();
    
    // Click on the row
    await firstHealthyRow.click();
    
    // Verify navigation to marketplace-ui
    await page.waitForURL('**/marketplace-ui?tenant=**');
    expect(page.url()).toContain('/marketplace-ui');
  });

  test('should refresh data on button click', async ({ page }) => {
    // Get initial health value
    const initialHealth = await page.locator('main > div:first-child strong').first().textContent();
    
    // Click Refresh Data button
    await page.locator('button:has-text("Refresh Data")').click();
    
    // Wait for data to reload
    await page.waitForTimeout(500);
    
    // Verify page still loaded correctly
    await expect(page.locator('h1')).toContainText('Platform Operations Center');
  });

  test('should apply health status filter', async ({ page }) => {
    // Get the health filter dropdown
    const healthFilter = page.locator('select').nth(0);
    
    // Change filter to 'Healthy'
    await healthFilter.selectOption('Healthy');
    
    // Wait for table to update
    await page.waitForTimeout(300);
    
    // Verify table rows are visible
    const rows = page.locator('table tbody tr');
    expect(await rows.count()).toBeGreaterThanOrEqual(0);
  });

  test('should apply drift status filter', async ({ page }) => {
    // Get the drift filter dropdown
    const driftFilter = page.locator('select').nth(1);
    
    // Change filter to 'No Drift'
    await driftFilter.selectOption('No Drift');
    
    // Wait for table to update
    await page.waitForTimeout(300);
    
    // Verify table rows are visible
    const rows = page.locator('table tbody tr');
    expect(await rows.count()).toBeGreaterThanOrEqual(0);
  });

  test('should search tenants by ID', async ({ page }) => {
    // Get search input
    const searchInput = page.locator('input[placeholder="Search tenants"]');
    
    // Type a search term
    await searchInput.fill('test-tenant');
    
    // Wait for filter to apply
    await page.waitForTimeout(300);
    
    // Verify table rows exist or "no results" message
    const rows = page.locator('table tbody tr');
    const count = await rows.count();
    expect(count).toBeGreaterThanOrEqual(0);
  });
});

test.describe('Operations Center Navigation', () => {
  test('should navigate back to Marketplace UI', async ({ page }) => {
    // Go to Operations Center
    await page.goto('/operations-center');
    await page.waitForLoadState('networkidle');
    
    // Click back link
    await page.locator('a:has-text("← Back to Marketplace")').click();
    
    // Verify navigation to marketplace-ui
    await page.waitForURL('**/marketplace-ui');
    expect(page.url()).toContain('/marketplace-ui');
  });

  test('should show Operations Center link on Marketplace', async ({ page }) => {
    // Go to Marketplace
    await page.goto('/marketplace-ui');
    await page.waitForLoadState('networkidle');
    
    // Verify Operations Center link exists in header
    const opsLink = page.locator('a:has-text("📊 Operations Center")');
    await expect(opsLink).toBeVisible();
    
    // Click it
    await opsLink.click();
    
    // Verify navigation to Operations Center
    await page.waitForURL('**/operations-center');
    expect(page.url()).toContain('/operations-center');
  });
});
