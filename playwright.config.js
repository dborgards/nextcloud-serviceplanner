import { defineConfig, devices } from '@playwright/test'

export default defineConfig({
  testDir: './tests/E2E',
  fullyParallel: false,
  workers: 1,
  timeout: 60_000,
  outputDir: process.env.PLAYWRIGHT_OUTPUT_DIR || 'test-results',
  reporter: [['list'], ['html', { open: 'never' }]],
  use: {
    baseURL: process.env.NEXTCLOUD_URL,
    headless: process.env.HEADED !== '1',
    testIdAttribute: 'data-test',
    trace: 'retain-on-failure',
  },
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
})
