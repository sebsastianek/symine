import { defineConfig, devices } from '@playwright/test';
import { BASE_URL, IS_EXTERNAL, PATHS, PHP, PORT, ROOT, SMTP_PORT } from './support/env';

// One id per run (inherited by the workers): keys the "DB reset once per spec file" marker, see support/fixtures.ts
process.env.E2E_RUN_ID ||= String(Date.now());

/**
 * Symine e2e suite. See e2e/README.md for conventions.
 *
 * One PHP built-in server (single worker) + one SQLite file => workers: 1. The DB is restored from the pristine
 * snapshot before every spec file (support/fixtures.ts), so files are independent; tests within a file share state
 * and run in order.
 */
export default defineConfig({
  testDir: './tests',
  outputDir: PATHS.results,
  fullyParallel: false,
  workers: 1,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  timeout: 60_000,
  expect: { timeout: 10_000 },
  reporter: [
    ['list'],
    ['html', { outputFolder: PATHS.report, open: 'never' }],
  ],
  globalSetup: require.resolve('./support/global-setup'),
  use: {
    ...devices['Desktop Chrome'],
    channel: 'chrome',
    headless: true,
    baseURL: BASE_URL,
    locale: 'en-US',
    timezoneId: 'UTC',
    viewport: { width: 1366, height: 900 },
    actionTimeout: 15_000,
    navigationTimeout: 30_000,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  webServer: IS_EXTERNAL
    ? undefined
    : {
        command: `node ${JSON.stringify(require.resolve('./support/serve.mjs'))}`,
        url: `${BASE_URL}/up`,
        timeout: 180_000,
        reuseExistingServer: false,
        stdout: 'ignore',
        stderr: 'pipe',
        env: {
          E2E_ROOT: ROOT,
          E2E_PHP: PHP,
          E2E_PORT: String(PORT),
          E2E_DB_PATH: PATHS.db,
          E2E_FIXTURE_DB: PATHS.fixtureDb,
          E2E_SEED_FILES: PATHS.seedFiles,
          E2E_SERVER_LOG: PATHS.serverLog,
          E2E_SESSIONS_PATH: PATHS.sessions,
          APP_ENV: 'dev',
          APP_DEBUG: '1',
          APP_CACHE_DIR: PATHS.cache,
          DATABASE_URL: `sqlite:///${PATHS.db}`,
          SYMINE_FILES_PATH: PATHS.files,
          MAILER_DSN: `smtp://127.0.0.1:${SMTP_PORT}`,
        },
      },
});
