import { defineConfig } from '@playwright/test';

export default defineConfig({
  testDir: './tests/e2e',
  testIgnore: ['**/helpers.js', '**/global-setup.js'],
  globalSetup: './tests/e2e/global-setup.js',
  fullyParallel: false,
  workers: 1,
  timeout: 60000,
  expect: { timeout: 10000 },
  reporter: [['list'], ['html', { open: 'never', outputFolder: 'storage/playwright-report' }]],
  outputDir: 'storage/playwright-artifacts',
  use: {
    baseURL: process.env.PLAYWRIGHT_BASE_URL || 'http://mall-management.test',
    headless: true,
    screenshot: 'only-on-failure',
    trace: 'retain-on-failure',
    actionTimeout: 15000,
    navigationTimeout: 30000,
  },
  projects: [
    { name: 'chromium', use: { browserName: 'chromium' } },
  ],
});
