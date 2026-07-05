import { defineConfig, devices } from '@playwright/test';

/**
 * Playwright configuration for Operations Center and Marketplace UI tests
 * Covers dashboard KPI verification, data aggregation, drift detection, and risk calculations
 */
export default defineConfig({
  testDir: './tests/playwright',
  fullyParallel: false,
  forbidOnly: process.env.CI ? true : false,
  retries: process.env.CI ? 2 : 0,
  workers: process.env.CI ? 1 : 1,
  reporter: [
    ['html', { outputFolder: 'test-results/playwright' }],
    ['json', { outputFile: 'test-results/playwright/results.json' }],
    ['junit', { outputFile: 'test-results/playwright/junit.xml' }],
  ],
  use: {
    baseURL: 'http://127.0.0.1:8006',
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
  },

  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],

  webServer: {
    command: 'npm run dev', // adjust based on your dev server
    url: 'http://127.0.0.1:8006',
    reuseExistingServer: true,
    timeout: 120 * 1000,
  },
});
