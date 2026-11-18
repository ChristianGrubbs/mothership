const { test, expect } = require('@playwright/test');

/**
 * The app publishes the commit it was built from (MOTHERSHIP_BUILD_COMMIT, baked into the
 * Studio image and set by CI). Every acceptance run records it, and when the runner knows
 * which commit it deployed (ACCEPTANCE_EXPECT_COMMIT) the page must report exactly that one,
 * so acceptance evidence always names the build it exercised.
 */
test('the app reports the build commit it serves', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'desktop-1440', 'one check per run is enough');

    await page.goto('/admin/dam/assets');
    const commit = await page.locator('meta[name="mothership-build-commit"]').getAttribute('content');

    testInfo.annotations.push({ type: 'app-commit', description: commit || '' });
    expect(commit, 'meta[name=mothership-build-commit] is missing').toBeTruthy();

    const expected = process.env.ACCEPTANCE_EXPECT_COMMIT;
    if (expected) {
        expect(commit).toBe(expected);
    } else {
        expect(commit).toMatch(/^([0-9a-f]{40}|unknown)$/);
    }
});
