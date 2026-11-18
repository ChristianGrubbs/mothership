const { test, expect } = require('../../utils/fixtures');

test.describe('admin ajax navigation', () => {
    test.beforeEach(async ({ adminPage: page }) => {
        // Global setup now authenticates this shard's own origin. Reuse it:
        // repeated admin logins invalidate sessions used by later specs.
        await page.goto('/admin/home');
        await expect(page).toHaveURL(/\/admin\/home$/);

        const enabled = await page.evaluate(() => !! window.__ajaxNavInitialised);
        expect(enabled, 'this build must initialize AJAX navigation').toBe(true);
    });

    test('navigates between pages without a full reload and supports back', async ({ adminPage: page }) => {
        await page.waitForLoadState('networkidle');

        // Guard against script re-execution regressions (redeclarations,
        // templates executed as JS, libraries not ready) during navigation.
        // Network resource failures (missing/broken product images, etc.) also
        // surface as console "error" entries but are unrelated to script
        // re-execution, so they are excluded here.
        const consoleErrors = [];
        page.on('console', (message) => {
            if (message.type() === 'error' && ! message.text().startsWith('Failed to load resource')) {
                consoleErrors.push(message.text());
            }
        });
        page.on('pageerror', (error) => consoleErrors.push(error.message));

        await page.evaluate(() => {
            window.__navSentinel = 'KEEP';
            window.__navEvents = [];
            document.addEventListener('mothership:navigate:before', (e) => window.__navEvents.push('before:' + e.detail.url));
            document.addEventListener('mothership:navigate:success', (e) => window.__navEvents.push('success:' + e.detail.url));
        });

        await page.evaluate(() => {
            const link = [...document.querySelectorAll('a[href]')]
                .find((a) => a.href.endsWith('/admin/catalog/products'));
            link.click();
        });

        await page.waitForURL(/\/admin\/catalog\/products/);
        await page.waitForFunction(() => window.__navEvents.includes('success:' + window.location.href));
        await expect(page.locator('#app')).toContainText(/product/i);

        // Sentinel intact => no full reload happened.
        expect(await page.evaluate(() => window.__navSentinel)).toBe('KEEP');

        await page.evaluate(() => {
            const link = [...document.querySelectorAll('a[href]')]
                .find((a) => a.href.endsWith('/admin/catalog/categories'));
            link.click();
        });
        await page.waitForURL(/\/admin\/catalog\/categories/);
        await page.waitForFunction(() => window.__navEvents.includes('success:' + window.location.href));
        expect(await page.evaluate(() => window.__navSentinel)).toBe('KEEP');

        await page.goBack();
        await page.waitForURL(/\/admin\/catalog\/products/);
        expect(await page.evaluate(() => window.__navSentinel)).toBe('KEEP');

        // Lifecycle events fired for each visit (listeners survive re-mounts
        // because they are on `document`, not the Vue app).
        const events = await page.evaluate(() => window.__navEvents);
        expect(events.filter((e) => e.startsWith('before:')).length).toBeGreaterThanOrEqual(2);
        expect(events.filter((e) => e.startsWith('success:')).length).toBeGreaterThanOrEqual(2);
        expect(events.some((e) => e.includes('/catalog/categories'))).toBeTruthy();

        // No script re-execution errors leaked to the console during navigation.
        expect(consoleErrors).toEqual([]);
    });

    test('datagrid edit action navigates without a full reload', async ({ adminPage: page }) => {
        await page.goto('/admin/catalog/attribute-families');
        await page.waitForLoadState('networkidle');

        // Wait for the datagrid rows and their edit action to render.
        const editAction = page.locator('#app [class*="icon-edit"]').first();
        await expect(editAction).toBeVisible({ timeout: 15000 });

        await page.evaluate(() => { window.__navSentinel = 'KEEP'; });

        await editAction.click();

        await page.waitForURL(/\/admin\/catalog\/attribute-families\/edit\//);

        // Sentinel intact => the datagrid GET action went through ajax nav,
        // not window.location (which would have reloaded and wiped it).
        expect(await page.evaluate(() => window.__navSentinel)).toBe('KEEP');
    });

    // Regression: a GET link that server-side 302-redirects (ai-agent/settings
    // → magic-ai/settings) must update the address bar to the FINAL
    // url. If it stays on the source url, a form with `action=""` on the landed
    // page posts back to the redirect-only route → 405 Method Not Allowed.
    test('ajax nav syncs the address bar to the final redirected url', async ({ adminPage: page }) => {
        await page.waitForLoadState('networkidle');

        // The legacy redirect lives in the optional PIM widget. Supply a real
        // anchor fixture so the redirect contract executes with that widget hidden.
        await page.evaluate(() => {
            const link = document.createElement('a');
            link.href = '/admin/ai-agent/settings';
            link.textContent = 'Legacy AI settings';
            link.dataset.testRedirect = 'settings';
            document.querySelector('main').append(link);
        });
        await page.locator('[data-test-redirect="settings"]').click();

        // Address bar must land on the redirect TARGET, not the source route.
        await page.waitForURL(/\/admin\/magic-ai\/settings/, { timeout: 20000 });
        await expect(page.locator('form#configuration-edit-form, #configuration-edit-form form')).toBeVisible();
        expect(new URL(page.url()).pathname).not.toContain('/ai-agent/settings');

        // Saving the landed configuration form must not 405 (posts to the
        // synced url = configuration store, not the GET-only settings route).
        const saveStatus = await page.evaluate(async () => {
            const form = document.querySelector('form#configuration-edit-form, #configuration-edit-form form');
            if (! form) { return null; }
            const action = form.getAttribute('action');
            const target = ! action ? window.location.href : action;
            const res = await fetch(target, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                body: new FormData(form),
            });
            return res.status;
        });
        expect(saveStatus).toBe(200);
    });
});
