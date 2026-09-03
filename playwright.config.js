const { defineConfig, devices } = require('@playwright/test');

module.exports = defineConfig({
  testDir: './tests/playwright',
  outputDir: './tests/ci/artifacts/playwright-results',
  timeout: 45_000,
  expect: {
    timeout: 10_000,
  },
  fullyParallel: false,
  retries: process.env.CI ? 1 : 0,
  reporter: [
    ['list'],
    ['html', { outputFolder: './tests/ci/artifacts/playwright-report', open: 'never' }],
  ],
  use: {
    baseURL: process.env.CIVICFG_BASE_URL || 'http://127.0.0.1:8760',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
    ignoreHTTPSErrors: process.env.CIVICFG_IGNORE_HTTPS_ERRORS === '1',
  },
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
});
