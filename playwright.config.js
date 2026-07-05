const { defineConfig, devices } = require('@playwright/test');

module.exports = defineConfig({
  testDir: './ui-tests',
  timeout: 60000,
  expect: {
    timeout: 5000,
  },
  fullyParallel: false,
  retries: 1,
  use: {
    baseURL: process.env.BASE_URL || 'http://127.0.0.1:8006',
    headless: true,
    viewport: { width: 1280, height: 900 },
    actionTimeout: 10000,
    ignoreHTTPSErrors: true,
  },
  projects: [
    {
      name: 'api',
      use: {
        baseURL: process.env.BASE_URL || 'http://127.0.0.1:8006',
      },
    },
    {
      name: 'chromium',
      use: {
        ...devices['Desktop Chrome'],
      },
    },
  ],
});
