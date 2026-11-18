/**
 * Real-workflow acceptance suite (2026-10-14 nightly repair).
 *
 * Drives the actual asset page the way a person does — PhotoEdit opened from the asset,
 * panorama switching under a slow network, stack preview Next — at the three widths the
 * live UX reviews use. It runs against whatever app BASE_URL names: the CI-hosted app on
 * the self-hosted runner, a local isolated stack, or (with the operator's go-ahead) an
 * isolated second Studio stack. It never needs production data: every spec seeds its own
 * assets through the authenticated upload endpoint.
 *
 * ACCEPTANCE_EXPECT_COMMIT (optional) pins the build the app must report in its
 * `mothership-build-commit` meta tag, so a run proves which commit it exercised.
 */
const { defineConfig, devices } = require('@playwright/test');
const path = require('path');

const { loadEnv } = require('./utils/load-env');

loadEnv();

const isCI = !!process.env.CI;
const STORAGE_STATE = path.resolve(__dirname, process.env.PW_STATE_DIR || '.state', 'admin-auth.json');

const chrome = {
    ...devices['Desktop Chrome'],
    headless: true,
    launchOptions: { args: ['--disable-gpu', '--disable-dev-shm-usage', '--no-sandbox'] },
};

module.exports = defineConfig({
    testDir: './tests/acceptance',
    fullyParallel: false,
    forbidOnly: isCI,
    retries: isCI ? 1 : 0,
    workers: 1,
    reporter: isCI
        ? [['list'], ['html', { outputFolder: 'playwright-report-acceptance', open: 'never' }]]
        : [['list']],
    globalTimeout: 40 * 60_000,
    timeout: 90_000,
    expect: { timeout: 15_000 },
    globalSetup: require.resolve('./global-setup.js'),
    use: {
        baseURL: process.env.BASE_URL || 'http://127.0.0.1:8000',
        storageState: STORAGE_STATE,
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        video: 'off',
        actionTimeout: 15_000,
        navigationTimeout: 30_000,
        locale: 'en-US',
    },
    projects: [
        { name: 'desktop-1440', use: { ...chrome, viewport: { width: 1440, height: 900 } } },
        { name: 'tablet-1024', use: { ...chrome, viewport: { width: 1024, height: 768 } } },
        {
            name: 'phone-375',
            use: { ...chrome, viewport: { width: 375, height: 812 }, isMobile: true, hasTouch: true, deviceScaleFactor: 2 },
        },
    ],
});
