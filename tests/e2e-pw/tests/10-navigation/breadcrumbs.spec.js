const { test, expect } = require('../../utils/fixtures');

const crumb = (page) => page.locator('nav[aria-label="Breadcrumb"]');

test.describe('admin breadcrumbs', () => {
    test.beforeEach(async ({ adminPage: page }) => {
        await page.goto('/admin/home');
        await expect(page).toHaveURL(/\/admin\/home$/);
    });

    test('renders the correct breadcrumb for each page type', async ({ adminPage: page }) => {
        // In-menu list page: parent group + current page.
        await page.goto('/admin/catalog/categories');
        await expect(crumb(page).first()).toHaveText(/Catalog\s*\/\s*Categories/);

        // Hub fields page: leaf kept even though its URL sits under the hub URL.
        await page.goto('/admin/configuration/system/system.email');
        await expect(crumb(page).first()).toHaveText(/Configuration\s*\/\s*System Settings\s*\/\s*Email/);

        // Off-menu hub page resolved to its sidebar parent.
        await page.goto('/admin/configuration/system/system.appearance');
        await expect(crumb(page).first()).toHaveText(/Configuration\s*\/\s*System Settings\s*\/\s*Appearance/);

        // In-menu top-level page (Integrations) still shows the trail.
        await page.goto('/admin/configuration/integrations');
        await expect(crumb(page).first()).toContainText('Integrations');

        // Tabbed edit page: exactly one breadcrumb (tab panels don't add their own).
        await page.goto('/admin/catalog/attribute-families/edit/1?variants');
        await expect(crumb(page)).toHaveCount(1);
        await expect(crumb(page)).toContainText('Attribute Families');

        // Top-level page with no menu ancestor: no breadcrumb.
        await page.goto('/admin/dashboard');
        await expect(crumb(page)).toHaveCount(0);
    });

    test('keeps the breadcrumb after an ajax navigation between pages', async ({ adminPage: page }) => {
        // Configuration moved from the sidebar into the avatar dropdown (2026-09-24 navigation
        // redesign); its "Integrations" entry lives there now, not under #mothership-sidebar.
        await page.goto('/admin/configuration/webhook');
        await page.getByRole('button', { name: 'Account' }).click();
        await page.locator('[data-account-menu] a[href$="/admin/configuration/integrations"]').first().click();
        await page.waitForURL(/\/configuration\/integrations$/, { timeout: 15000 });
        await expect(crumb(page).first()).toContainText('Integrations');
    });
});
