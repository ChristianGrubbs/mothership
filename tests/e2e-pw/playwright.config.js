const { defineConfig, devices } = require('@playwright/test');
const path = require('path');
const os = require('os');
const fs = require('fs');

const { loadEnv } = require('./utils/load-env');

loadEnv();

const isCI = !!process.env.CI;
/* PW_STATE_DIR keeps auth state per shard, so shards driving their own server
 * and database don't overwrite each other's session. */
const STORAGE_STATE = path.resolve(__dirname, process.env.PW_STATE_DIR || '.state', 'admin-auth.json');

/**
 * Worker count strategy:
 * - CI: half the available CPUs (nginx + PHP-FPM handles concurrency)
 * - Local: all CPUs minus 1 (leave one for the dev server)
 * - Minimum 2 workers to parallelize across test files
 */
const workerCount = isCI
  ? 1  // artisan serve is single-threaded — 1 worker per shard (shards run in parallel; count set in CI workflow)
  : Math.max(2, os.cpus().length - 1);

module.exports = defineConfig({
  testDir: './tests',

  /* Scratch specs under 99-debug are for local diagnosis only, never CI. The acceptance
     specs run under playwright.acceptance.config.js at their own three widths. The 01-catalog
     specs drive the Mothership catalog pages, retired 2026-09-29; the operator kept them dispatch-only
     (2026-10-14), so the nightly sets PW_SKIP_RETIRED_PIM and a plain dispatch still runs them. */
  testIgnore: [
    '**/99-debug/**',
    '**/acceptance/**',
    ...(process.env.PW_SKIP_RETIRED_PIM === '1' ? ['**/01-catalog/**'] : []),
  ],

  /* Run tests within each file sequentially, but files in parallel across workers */
  fullyParallel: false,

  /* Fail CI if test.only() is accidentally committed */
  forbidOnly: isCI,

  /* Retry once in CI to handle transient network/rendering flakes */
  retries: isCI ? 1 : 0,

  /* Parallel workers across test files */
  workers: workerCount,

  /**
   * Reporters:
   * - CI: list (live output) + html (artifact on failure)
   * - Local: html with auto-open on failure
   */
  reporter: isCI
    ? [['list'], ['html', { outputFolder: 'playwright-report', open: 'never' }]]
    : [['html', { outputFolder: 'playwright-report', open: 'on-failure' }]],

  /* Whole-run ceiling per shard: below the workflow's 90-minute job timeout so Playwright
     reports what hung instead of the runner killing it blind (2026-09-15 pool wedge).
     80, not 85: the html reporter, artifact upload and teardown need real minutes after
     the ceiling fires, and a 5-minute margin let the job timeout win first (2026-09-16). */
  globalTimeout: 80 * 60_000,
  /* Per-test timeout: 60s is enough with nginx serving requests concurrently */
  timeout: 60_000,

  /* Assertion timeout: 15s for Vue components to render */
  expect: { timeout: 15_000 },

  /* Pre-authenticate admin session before all tests */
  globalSetup: require.resolve('./global-setup.js'),

  use: {
    /* Base URL — must point at THIS project's server and match its APP_URL so the
     * app-url guard doesn't redirect. Set BASE_URL (env or tests/e2e-pw/.env) per
     * environment; defaults to the local dev server. */
    baseURL: process.env.BASE_URL || 'http://127.0.0.1:8000',

    /* Reuse authenticated session across all tests */
    storageState: STORAGE_STATE,

    /* Traces only on retry — keeps artifacts small, available when needed */
    trace: 'on-first-retry',

    /* Screenshots only on failure — saves disk I/O during passing runs */
    screenshot: 'only-on-failure',

    /* No video recording — significant performance overhead */
    video: 'off',

    /* Action timeout: clicks, fills, selects — 15s is enough with fast server */
    actionTimeout: 15_000,

    /* Navigation timeout: page.goto — 30s handles slow pages */
    navigationTimeout: 30_000,

    /* Reduce motion to speed up animations */
    reducedMotion: 'reduce',

    /* Set locale for consistent date/number formatting */
    locale: 'en-US',

    /* Viewport for consistent rendering */
    viewport: { width: 1280, height: 720 },
  },

  projects: [
    {
      name: 'chromium',
      use: {
        ...devices['Desktop Chrome'],
        headless: true,
        /* Launch options for performance */
        launchOptions: {
          args: [
            '--disable-gpu',
            '--disable-dev-shm-usage',
            '--no-sandbox',
          ],
        },
      },
    },
  ],
});
