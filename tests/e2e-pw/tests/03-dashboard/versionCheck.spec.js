const { test, expect } = require('../../utils/fixtures');
const { navigateTo } = require('../../utils/helpers');

/**
 * Helper: Open the profile dropdown reliably.
 * Navigates to dashboard first, then waits for network idle.
 */
async function openProfileDropdown(adminPage) {
  await navigateTo(adminPage, 'dashboard');
  const profileBtn = adminPage.getByRole('button', { name: 'Account', exact: true });
  await profileBtn.click();
  // Wait for dropdown content to render
  await expect(adminPage.getByRole('link', { name: 'Logout' })).toBeVisible({ timeout: 5000 });
}

test.describe('Mothership Version Check', () => {

test.beforeEach(async ({ adminPage }) => {
  await navigateTo(adminPage, 'dashboard');
});

// ═════════════════════════════════════════════════
// SECTION 1: Profile Dropdown & Version Display
// ═════════════════════════════════════════════════

test('1.1 - Admin profile button is visible in header', async ({ adminPage }) => {
  const profileBtn = adminPage.getByRole('button', { name: 'Account', exact: true });
  await expect(profileBtn).toBeVisible();
});

test('1.2 - Clicking profile button opens dropdown', async ({ adminPage }) => {
  await openProfileDropdown(adminPage);

  // The account menu shows identity and navigation; upstream version branding was retired.
  await expect(adminPage.locator('[data-account-menu]')).toBeVisible();
  await expect(adminPage.getByRole('link', { name: 'My Account' })).toBeVisible();
  await expect(adminPage.getByRole('link', { name: 'Logout' })).toBeVisible();
});

test('1.3 - Account menu shows the signed-in user identity', async ({ adminPage }) => {
  await openProfileDropdown(adminPage);

  const identity = adminPage.locator('[data-account-menu] p').first();
  await expect(identity).toBeVisible();
  await expect(identity).not.toHaveText(/^\s*$/);
});

test('1.4 - Account menu shows the signed-in role', async ({ adminPage }) => {
  await openProfileDropdown(adminPage);

  const role = adminPage.locator('[data-account-menu] p').nth(1);
  await expect(role).toBeVisible();
  await expect(role).toHaveText('Administrator');
});

test('1.5 - Header keeps the Mothership brand visible while the account menu is open', async ({ adminPage }) => {
  await openProfileDropdown(adminPage);

  const logo = adminPage.locator('header [data-mothership-logo]:visible');
  await expect(logo).toBeVisible();
  await expect.poll(() => logo.evaluate(img => img.complete && img.naturalWidth > 0)).toBe(true);
});

// ═════════════════════════════════════════════════
// SECTION 2: Profile Dropdown Links
// ═════════════════════════════════════════════════

test('2.1 - Profile dropdown shows My Account link with correct URL', async ({ adminPage }) => {
  await openProfileDropdown(adminPage);

  const myAccountLink = adminPage.getByRole('link', { name: 'My Account' });
  await expect(myAccountLink).toBeVisible();
  await expect(myAccountLink).toHaveAttribute('href', /\/admin\/account/);
});

test('2.2 - Profile dropdown shows Logout link', async ({ adminPage }) => {
  await openProfileDropdown(adminPage);

  const logoutLink = adminPage.getByRole('link', { name: 'Logout' });
  await expect(logoutLink).toBeVisible();
});

test('2.3 - My Account link navigates to account edit page', async ({ adminPage }) => {
  await openProfileDropdown(adminPage);

  const myAccountLink = adminPage.getByRole('link', { name: 'My Account' });
  await expect(myAccountLink).toBeVisible();
  await myAccountLink.click();
  await expect(adminPage).toHaveURL(/\/admin\/account/);
});

// ═════════════════════════════════════════════════
// SECTION 3: Header Dark Mode Toggle
// ═════════════════════════════════════════════════

test('3.1 - Dark mode toggle icon is visible in header', async ({ adminPage }) => {
  const darkToggle = adminPage.locator('.icon-dark, .icon-light');
  await expect(darkToggle.first()).toBeVisible();
});

test('3.2 - Clicking dark mode toggle switches the icon', async ({ adminPage }) => {
  await adminPage.waitForLoadState('networkidle');
  const darkIcon = adminPage.locator('.icon-dark');
  const lightIcon = adminPage.locator('.icon-light');

  const wasDark = await darkIcon.isVisible().catch(() => false);

  // Click the toggle
  const toggle = adminPage.locator('.icon-dark, .icon-light').first();
  await toggle.click();

  if (wasDark) {
    // Should now show light icon
    await expect(lightIcon).toBeVisible();
  } else {
    // Should now show dark icon
    await expect(darkIcon).toBeVisible();
  }

  // Toggle back to restore original state
  const toggleBack = adminPage.locator('.icon-dark, .icon-light').first();
  await toggleBack.click();
});

});
