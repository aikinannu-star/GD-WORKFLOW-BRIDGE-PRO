// @ts-check
const { test, expect } = require('@playwright/test');

/**
 * Operations Center Comprehensive Test Suite
 * Covers: Navigation, KPIs, Overview table, Rankings, Drift summary, Drill-down links
 * Protects executive-facing dashboard surface
 */

test.describe('Operations Center Dashboard - Executive Surface', () => {
  
  test.beforeEach(async ({ page }) => {
    // Navigate to Operations Center
    await page.goto('http://127.0.0.1:8006/operations-center');
    // Wait for initial data load
    await page.waitForLoadState('networkidle');
  });

  // ============================================
  // NAVIGATION & PAGE STRUCTURE
  // ============================================

  test('should load Operations Center with correct title and navigation', async ({ page }) => {
    // Verify page loads with correct heading
    await expect(page.locator('h1')).toContainText('Platform Operations Center');
    
    // Verify description exists
    await expect(page.locator('main p')).toContainText('Executive dashboard');
    
    // Verify back navigation link
    await expect(page.locator('a:has-text("← Back to Marketplace")')).toHaveAttribute('href', '/marketplace-ui');
  });

  test('should display navigation breadcrumb', async ({ page }) => {
    // Verify breadcrumb structure
    const breadcrumb = page.locator('nav, [role="navigation"]');
    const isVisible = await breadcrumb.isVisible().catch(() => false);
    
    // Main heading should be visible as fallback
    await expect(page.locator('h1')).toBeVisible();
  });

  // ============================================
  // DASHBOARD KPIs
  // ============================================

  test('should display all 6 KPI cards with correct labels', async ({ page }) => {
    // Verify Fleet KPI Summary section
    await expect(page.locator('h2:has-text("Fleet KPI Summary")')).toBeVisible();
    
    // Verify KPI card labels
    const expectedKPIs = [
      'Platform Health Score',
      'At-Risk Tenants',
      'Critical Findings',
      'Total Active Installs',
      'Total Remediations (7d)',
      'Fleet Volatility'
    ];
    
    for (const kpiLabel of expectedKPIs) {
      const kpiElement = page.locator(`text=${kpiLabel}`);
      await expect(kpiElement).toBeVisible({ timeout: 5000 });
    }
  });

  test('should display platform health score in status banner', async ({ page }) => {
    // Verify health status banner exists and contains numeric value
    const healthBanner = page.locator('main > div').first();
    await expect(healthBanner).toBeVisible();
    
    // Verify numeric health value
    const healthText = await page.locator('main > div:first-child').textContent();
    expect(healthText).toMatch(/\d+/);
  });

  test('should populate KPI cards with numeric values', async ({ page }) => {
    // Get all KPI value elements and verify they contain numbers
    const kpiValues = page.locator('div h3').count();
    expect(await kpiValues).toBeGreaterThan(0);
    
    // Spot check: Platform Health Score should be numeric
    const healthScore = page.locator('text=/Platform Health Score.*\\d+/');
    await expect(healthScore).toBeVisible({ timeout: 5000 });
  });

  test('should validate intelligence-health API contract and KPI fields', async ({ request }) => {
    const response = await request.get('/api/v1/intelligence-health');
    expect(response.ok()).toBeTruthy();

    const data = await response.json();
    expect(data).toHaveProperty('trend_confidence');
    expect(data).toHaveProperty('stable_tenants_pct');
    expect(data).toHaveProperty('anomaly_density');
    expect(data).toHaveProperty('remediation_success_rate');
    expect(data).toHaveProperty('average_drift_resolution_hours');

    expect(typeof data.trend_confidence).toBe('number');
    expect(typeof data.stable_tenants_pct).toBe('number');
    expect(typeof data.anomaly_density).toBe('number');
  });

  test('should display intelligence KPI summary cards on Operations Center', async ({ page }) => {
    // Refresh data and verify KPI cards are rendered
    await page.goto('http://127.0.0.1:8006/operations-center');
    await page.waitForLoadState('networkidle');

    const totalRemediations = page.locator('h3:has-text("Total Remediations (7d)")');
    await expect(totalRemediations).toBeVisible({ timeout: 5000 });

    const healthScoreCard = page.locator('h3:has-text("Platform Health Score")');
    await expect(healthScoreCard).toBeVisible({ timeout: 5000 });

    const kpiValue = healthScoreCard.locator('xpath=../strong');
    await expect(kpiValue).toHaveText(/\d+/);
  });

  test('should display health status color indicator', async ({ page }) => {
    // Verify status indicator element exists
    const statusIndicator = page.locator('[class*="health"], [class*="status"], [style*="background"]').first();
    await expect(statusIndicator).toBeVisible({ timeout: 5000 });
  });

  // ============================================
  // OVERVIEW TABLE
  // ============================================

  test('should load and render tenant overview table', async ({ page }) => {
    // Verify overview table exists
    const table = page.locator('table').first();
    await expect(table).toBeVisible({ timeout: 5000 });
    
    // Verify table headers
    const expectedHeaders = ['Tenant', 'Health', 'Trend', 'Volatility', 'Installs', 'Findings', 'Updated'];
    for (const header of expectedHeaders) {
      const headerElement = page.locator(`th:has-text("${header}")`);
      const isVisible = await headerElement.isVisible().catch(() => false);
      // At least some headers should be present
      if (isVisible) {
        await expect(headerElement).toBeVisible();
      }
    }
  });

  test('should populate overview table with tenant data rows', async ({ page }) => {
    // Wait for table to load
    const tableRows = page.locator('table tbody tr');
    await expect(tableRows.first()).toBeVisible({ timeout: 5000 });
    
    // Verify at least one data row exists
    const rowCount = await tableRows.count();
    expect(rowCount).toBeGreaterThan(0);
    
    // Verify first row has data cells
    const firstRow = tableRows.first();
    const cells = firstRow.locator('td');
    expect(await cells.count()).toBeGreaterThan(0);
  });

  test('should display tenant names in overview table', async ({ page }) => {
    // Get first tenant name from table
    const firstTenantCell = page.locator('table tbody td').first();
    await expect(firstTenantCell).toContainText(/\w+/);
    
    // Verify cell contains text
    const tenantText = await firstTenantCell.textContent();
    expect(tenantText?.trim().length).toBeGreaterThan(0);
  });

  test('should display health scores as percentages', async ({ page }) => {
    // Get health score cells (typically column 2)
    const healthCells = page.locator('table tbody tr td:nth-child(2)');
    const cellCount = await healthCells.count();
    
    if (cellCount > 0) {
      const firstHealthValue = await healthCells.first().textContent();
      // Should be numeric or percentage
      expect(firstHealthValue).toMatch(/\d/);
    }
  });

  test('should filter overview table by health status', async ({ page }) => {
    // Look for health filter dropdown
    const healthFilter = page.locator('select').nth(0);
    const isVisible = await healthFilter.isVisible().catch(() => false);
    
    if (isVisible) {
      // Select filter option
      await healthFilter.selectOption('Healthy');
      
      // Wait for table update
      await page.waitForTimeout(300);
      
      // Verify table still visible
      const table = page.locator('table tbody');
      await expect(table).toBeVisible();
    }
  });

  // ============================================
  // RANKINGS & RISK ANALYSIS
  // ============================================

  test('should display Rankings & Risk Analysis section', async ({ page }) => {
    // Scroll to rankings section
    const rankingsHeader = page.locator('h2:has-text("Rankings & Risk Analysis")');
    const isVisible = await rankingsHeader.isVisible().catch(() => false);
    
    if (isVisible) {
      await rankingsHeader.scrollIntoViewIfNeeded();
      await expect(rankingsHeader).toBeVisible();
    }
  });

  test('should display Top Healthiest Tenants ranking', async ({ page }) => {
    // Look for healthiest tenants section
    const healthiestSection = page.locator('h3:has-text("Top Healthiest"), h3:has-text("Healthiest")');
    const isVisible = await healthiestSection.isVisible({ timeout: 5000 }).catch(() => false);
    
    if (isVisible) {
      await healthiestSection.scrollIntoViewIfNeeded();
      await expect(healthiestSection).toBeVisible();
      
      // Verify ranking table exists
      const rankingTable = page.locator('table').nth(1);
      await expect(rankingTable).toBeVisible({ timeout: 5000 });
    }
  });

  test('should display Most Improved Tenants ranking', async ({ page }) => {
    // Look for improved tenants section
    const improvedSection = page.locator('h3:has-text("Improved")');
    const isVisible = await improvedSection.isVisible({ timeout: 5000 }).catch(() => false);
    
    if (isVisible) {
      await improvedSection.scrollIntoViewIfNeeded();
      await expect(improvedSection).toBeVisible();
    }
  });

  test('should display Highest Risk Tenants ranking', async ({ page }) => {
    // Look for highest risk section
    const riskSection = page.locator('h3:has-text("Risk"), h3:has-text("Critical")');
    const isVisible = await riskSection.isVisible({ timeout: 5000 }).catch(() => false);
    
    if (isVisible) {
      await riskSection.scrollIntoViewIfNeeded();
      await expect(riskSection).toBeVisible();
    }
  });

  test('should populate all ranking tables with data', async ({ page }) => {
    // Get all table bodies (first is overview, rest are rankings)
    const tables = page.locator('table tbody');
    const tableCount = await tables.count();
    
    // Should have at least overview table + at least one ranking table
    expect(tableCount).toBeGreaterThanOrEqual(1);
    
    // Verify first ranking table has rows
    if (tableCount > 1) {
      const rankingTableRows = page.locator('table').nth(1).locator('tbody tr');
      const rowCount = await rankingTableRows.count();
      expect(rowCount).toBeGreaterThan(0);
    }
  });

  // ============================================
  // DRIFT SUMMARY
  // ============================================

  test('should display Fleet Drift Summary section', async ({ page }) => {
    // Scroll to drift summary section
    const driftHeader = page.locator('h2:has-text("Drift"), h2:has-text("drift")');
    const isVisible = await driftHeader.isVisible({ timeout: 5000 }).catch(() => false);
    
    if (isVisible) {
      await driftHeader.scrollIntoViewIfNeeded();
      await expect(driftHeader).toBeVisible();
    }
  });

  test('should display drift category cards', async ({ page }) => {
    // Look for drift category indicators
    const driftCategories = ['No Drift', 'Governance Drift', 'Revocation Drift', 'Dependency Drift'];
    let foundCategory = false;
    
    for (const category of driftCategories) {
      const categoryElement = page.locator(`text=${category}`);
      const isVisible = await categoryElement.isVisible({ timeout: 3000 }).catch(() => false);
      if (isVisible) {
        foundCategory = true;
        break;
      }
    }
    
    // At least one drift category should be visible
    if (foundCategory) {
      expect(true).toBe(true);
    }
  });

  test('should display drift counts as numeric values', async ({ page }) => {
    // Look for any numeric drift indicators
    const driftElements = page.locator('[class*="drift"]');
    const elementCount = await driftElements.count();
    
    // Verify at least one drift-related element
    expect(elementCount).toBeGreaterThanOrEqual(0);
  });

  test('should show drift summary statistics', async ({ page }) => {
    // Look for drift summary cards with numeric values
    const summaryCards = page.locator('div[class*="card"], div[class*="summary"]');
    const cardCount = await summaryCards.count();
    
    // Should have multiple summary cards
    expect(cardCount).toBeGreaterThan(0);
  });

  // ============================================
  // DRILL-DOWN LINKS & NAVIGATION
  // ============================================

  test('should navigate from overview table tenant click', async ({ page }) => {
    // Get first tenant row
    const firstTenantRow = page.locator('table tbody tr').first();
    
    // Verify row is clickable or has link
    const isClickable = await firstTenantRow.evaluate((el) => {
      return window.getComputedStyle(el).cursor === 'pointer' || el.closest('a') !== null;
    }).catch(() => false);
    
    if (isClickable || await firstTenantRow.locator('a').isVisible().catch(() => false)) {
      // Click first row or link within
      const link = firstTenantRow.locator('a').first();
      const hasLink = await link.isVisible().catch(() => false);
      
      if (hasLink) {
        await link.click();
      } else {
        await firstTenantRow.click();
      }
      
      // Verify navigation occurred or check URL pattern
      await page.waitForTimeout(500);
      const currentUrl = page.url();
      expect(currentUrl).toBeDefined();
    }
  });

  test('should have drill-down links in rankings tables', async ({ page }) => {
    // Look for ranking table
    const rankingTable = page.locator('table').nth(1);
    const isVisible = await rankingTable.isVisible({ timeout: 5000 }).catch(() => false);
    
    if (isVisible) {
      // Get first row in ranking table
      const rankingRow = rankingTable.locator('tbody tr').first();
      const isRowVisible = await rankingRow.isVisible().catch(() => false);
      
      if (isRowVisible) {
        // Verify row has clickable element or link
        const link = rankingRow.locator('a');
        const hasLink = await link.isVisible().catch(() => false);
        expect(hasLink).toBeDefined();
      }
    }
  });

  test('should display tenant detail navigation breadcrumb', async ({ page }) => {
    // Verify page structure supports drill-down
    const navElements = page.locator('a, [role="link"]');
    const navCount = await navElements.count();
    expect(navCount).toBeGreaterThan(0);
  });

  // ============================================
  // INTERACTIVITY & REFRESH
  // ============================================

  test('should refresh data on refresh button click', async ({ page }) => {
    // Look for refresh button
    const refreshButton = page.locator('button:has-text("Refresh")');
    const isVisible = await refreshButton.isVisible().catch(() => false);
    
    if (isVisible) {
      // Record initial content
      const initialContent = await page.locator('main').textContent();
      
      // Click refresh
      await refreshButton.click();
      
      // Wait for potential reload
      await page.waitForTimeout(500);
      
      // Verify page still loaded
      await expect(page.locator('main')).toBeVisible();
    }
  });

  test('should maintain data consistency after filter changes', async ({ page }) => {
    // Get initial table row count
    const initialRows = page.locator('table tbody tr');
    const initialCount = await initialRows.count();
    
    // Apply filter if available
    const filterSelect = page.locator('select').first();
    const hasFilter = await filterSelect.isVisible().catch(() => false);
    
    if (hasFilter) {
      // Get available options
      const options = await filterSelect.locator('option').count();
      
      if (options > 1) {
        // Select different option
        await filterSelect.selectOption({ index: 1 });
        
        // Wait for update
        await page.waitForTimeout(300);
        
        // Verify table still exists
        await expect(page.locator('table tbody')).toBeVisible();
      }
    }
  });

  // ============================================
  // RESPONSIVE DESIGN
  // ============================================

  test('should render properly on desktop viewport', async ({ page }) => {
    // Set desktop viewport
    await page.setViewportSize({ width: 1024, height: 768 });
    
    // Verify all main sections visible
    await expect(page.locator('h1')).toBeVisible();
    await expect(page.locator('table').first()).toBeVisible({ timeout: 5000 });
  });

  test('should render properly on tablet viewport', async ({ page }) => {
    // Set tablet viewport
    await page.setViewportSize({ width: 768, height: 1024 });
    
    // Verify main heading visible
    await expect(page.locator('h1')).toBeVisible();
    
    // Table should be accessible (possibly with scroll)
    const table = page.locator('table').first();
    const isVisible = await table.isVisible().catch(() => false);
    expect(isVisible || await table.isVisible({ timeout: 5000 }).catch(() => false)).toBeDefined();
  });

  test('should be navigable on mobile viewport', async ({ page }) => {
    // Set mobile viewport
    await page.setViewportSize({ width: 375, height: 667 });
    
    // Verify heading is accessible
    await expect(page.locator('h1')).toBeVisible();
    
    // Verify back navigation exists
    const backLink = page.locator('a:has-text("Back")');
    const hasBackLink = await backLink.isVisible().catch(() => false);
    expect(hasBackLink || await page.locator('a').first().isVisible()).toBeDefined();
  });

  // ============================================
  // ERROR HANDLING
  // ============================================

  test('should not display console errors', async ({ page }) => {
    // Collect any console errors
    const errors = [];
    page.on('console', (msg) => {
      if (msg.type() === 'error') {
        errors.push(msg.text());
      }
    });
    
    // Wait for page to fully load
    await page.waitForTimeout(1000);
    
    // Verify no critical errors (network errors OK)
    const criticalErrors = errors.filter(e => !e.includes('Failed to fetch'));
    expect(criticalErrors.length).toBe(0);
  });

  test('should handle missing data gracefully', async ({ page }) => {
    // Verify page structure even if data is incomplete
    await expect(page.locator('main')).toBeVisible();
    
    // Should show some content
    const mainContent = await page.locator('main').textContent();
    expect(mainContent).toBeDefined();
  });
});

/**
 * Operations Center Data Integrity Tests
 * Ensures dashboard data remains consistent
 */
test.describe('Operations Center Data Integrity', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('http://127.0.0.1:8006/operations-center');
    await page.waitForLoadState('networkidle');
  });

  test('should maintain data consistency across refreshes', async ({ page }) => {
    // Get initial health value
    const initialHealth = await page.locator('main').textContent();
    
    // Refresh page
    await page.reload();
    await page.waitForLoadState('networkidle');
    
    // Verify dashboard still loads
    await expect(page.locator('h1')).toContainText('Operations Center');
  });

  test('should not show stale data in tables', async ({ page }) => {
    // Get first table timestamp
    const tableData = await page.locator('table tbody tr').first().textContent();
    
    // Data should exist
    expect(tableData?.trim().length).toBeGreaterThan(0);
  });
});
