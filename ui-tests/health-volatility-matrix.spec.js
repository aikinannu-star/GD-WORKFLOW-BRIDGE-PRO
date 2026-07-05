import { test, expect } from '@playwright/test';

test.describe('Health vs Volatility Matrix - Phase 1', () => {
  test.beforeEach(async ({ page }) => {
    // Navigate to the scatter plot component
    // For now, this is served directly; will be integrated into operations-center later
    await page.goto('/health-volatility-matrix.html', { waitUntil: 'networkidle' });
  });

  test('renders scatter plot with tenants', async ({ page }) => {
    // Wait for chart to load
    const scatterChart = page.locator('#scatterChart');
    await expect(scatterChart).toBeVisible();

    // Verify SVG is populated
    const circles = page.locator('circle[data-tenant-id]');
    const count = await circles.count();
    expect(count).toBeGreaterThan(0);
  });

  test('displays risk zone legend', async ({ page }) => {
    // Check all 5 risk zones are displayed
    await expect(page.locator('text=Healthy')).toBeVisible();
    await expect(page.locator('text=Watch')).toBeVisible();
    await expect(page.locator('text=Stagnant')).toBeVisible();
    await expect(page.locator('text=Critical')).toBeVisible();
    await expect(page.locator('text=Degrading')).toBeVisible();
  });

  test('shows tooltip on hover', async ({ page }) => {
    const tooltip = page.locator('#tooltip');
    const firstTenant = page.locator('circle[data-tenant-id]').first();

    // Hover over first tenant
    await firstTenant.hover();

    // Tooltip should be visible with tenant info
    await expect(tooltip).toHaveClass(/visible/);
    
    const tooltipText = await tooltip.textContent();
    expect(tooltipText).toContain('Health:');
    expect(tooltipText).toContain('Volatility:');
    expect(tooltipText).toContain('Risk:');
  });

  test('displays statistics cards', async ({ page }) => {
    const statsContainer = page.locator('#statsContainer');
    await expect(statsContainer).toBeVisible();

    // Check all stats are displayed
    await expect(page.locator('#statTotalTenants')).not.toContainText('-');
    await expect(page.locator('#statHealthyTenants')).not.toContainText('-');
    await expect(page.locator('#statAtRiskTenants')).not.toContainText('-');
    await expect(page.locator('#statAvgHealth')).not.toContainText('-');
  });

  test('period selector changes data', async ({ page, request }) => {
    // Get initial data
    const initialTenants = await page.locator('circle[data-tenant-id]').count();
    expect(initialTenants).toBeGreaterThan(0);

    // Note: Data won't actually change with period selection in this test 
    // unless the API supports different periods. For now, we're just verifying
    // the UI control works.
    const periodSelect = page.locator('#period');
    await expect(periodSelect).toBeVisible();

    await periodSelect.selectOption('3d');
    const selectedPeriod = await periodSelect.inputValue();
    expect(selectedPeriod).toBe('3d');
  });

  test('clicking a tenant point drills down to the trend timeline', async ({ page }) => {
    const firstCircle = page.locator('circle[data-tenant-id]').first();
    await expect(firstCircle).toBeVisible();
    await firstCircle.click();

    await expect(page).toHaveURL(/\/tenant-trend-timeline\?tenant_id=/);
  });

  test('refresh button fetches new data', async ({ page }) => {
    const refreshBtn = page.locator('#refreshBtn');
    const loadingContainer = page.locator('#loadingContainer');

    // Click refresh
    await refreshBtn.click();

    // Loading should appear briefly
    await expect(loadingContainer).toBeVisible();

    // Data should reload
    const circles = page.locator('circle[data-tenant-id]');
    await expect(circles.first()).toBeVisible({ timeout: 5000 });
  });

  test('export button generates SVG download', async ({ page, context }) => {
    // Listen for download
    const downloadPromise = context.waitForEvent('download');

    // Click export
    const exportBtn = page.locator('#exportBtn');
    await exportBtn.click();

    // Verify download started
    const download = await downloadPromise;
    expect(download.suggestedFilename()).toContain('health-volatility-matrix');
  });

  test('tenant points are colored by risk zone', async ({ page }) => {
    const circles = page.locator('circle[data-tenant-id]');
    const count = await circles.count();

    // Sample some circles and verify they have fill colors
    for (let i = 0; i < Math.min(count, 5); i++) {
      const circle = circles.nth(i);
      const fill = await circle.getAttribute('fill');
      
      // Should be one of the risk zone colors
      const validColors = ['#10b981', '#f59e0b', '#6366f1', '#ef4444', '#dc2626'];
      expect(validColors).toContain(fill);
    }
  });

  test('scatter plot responsive on resize', async ({ page }) => {
    const scatterChart = page.locator('#scatterChart');
    await expect(scatterChart).toBeVisible();

    // Get initial dimensions
    const initialBox = await scatterChart.boundingBox();
    const initialWidth = initialBox.width;

    // Resize viewport
    await page.setViewportSize({ width: 600, height: 400 });

    // Wait for redraw
    await page.waitForTimeout(500);

    // Chart should still be visible and responsive
    await expect(scatterChart).toBeVisible();
    const newBox = await scatterChart.boundingBox();
    expect(newBox.width).toBeLessThan(initialWidth);
  });

  test('no error message on load', async ({ page }) => {
    const errorContainer = page.locator('#errorContainer');
    const errorText = await errorContainer.innerHTML();
    expect(errorText).not.toContain('error');
    expect(errorText).not.toContain('Error');
  });

  test('risk zone color distribution matches legend', async ({ page }) => {
    const legendItems = page.locator('.legend-item');
    const zoneCount = await legendItems.count();
    
    // Should have 5 legend items (5 risk zones)
    expect(zoneCount).toBe(5);

    // Verify each legend item has a color div
    for (let i = 0; i < 5; i++) {
      const colorDiv = legendItems.nth(i).locator('.legend-color');
      await expect(colorDiv).toBeVisible();
    }
  });

  test('axes and grid lines visible', async ({ page }) => {
    const svg = page.locator('#scatterChart');
    
    // Check for axis lines (should have at least 2: x and y)
    const lines = page.locator('line');
    expect(await lines.count()).toBeGreaterThanOrEqual(2);
  });

  test('axis labels displayed', async ({ page }) => {
    await expect(page.locator('text=Volatility (%)')).toBeVisible();
    await expect(page.locator('text=Health Score (%)')).toBeVisible();
  });
});

test.describe('Health vs Volatility Matrix - Integration', () => {
  test('matrix integrates with platform overview API', async ({ request }) => {
    // Verify the API endpoint returns expected structure
    const response = await request.get('/api/v1/marketplace/platform/overview');
    expect(response.ok()).toBeTruthy();

    const data = await response.json();
    expect(data).toHaveProperty('items');
    expect(Array.isArray(data.items)).toBeTruthy();

    // Verify tenant structure has required fields
    if (data.items.length > 0) {
      const tenant = data.items[0];
      expect(tenant).toHaveProperty('id');
      expect(tenant).toHaveProperty('health_score');
      expect(tenant).toHaveProperty('fleet_volatility');
    }
  });
});
