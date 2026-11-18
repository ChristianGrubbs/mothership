const { test, expect } = require('../../utils/fixtures');
const { navigateTo } = require('../../utils/helpers');

test.describe('Profile Dropdown Logo Image', () => {

  test('Profile dropdown logo does not show broken image icon', async ({ adminPage }) => {
    // Navigate to dashboard first
    await navigateTo(adminPage, 'dashboard');

    // The profile toggle is a button inside the header — click it to open dropdown
    // The admin profile button is the last interactive element in the header's right section
    const profileToggle = adminPage.getByRole('button', { name: 'Account', exact: true });
    await profileToggle.click();

    // Wait for dropdown to appear with "My Account" link
    await expect(adminPage.getByText('My Account')).toBeVisible({ timeout: 5000 });

    // The upstream version/logo block was removed from the account menu.
    // Verify the actual header logo decodes instead of passing on zero legacy images.
    const brand = adminPage.locator('header [data-mothership-logo]:visible');
    await expect(brand).toBeVisible();
    await expect.poll(() => brand.evaluate(img => img.complete && img.naturalWidth > 0)).toBe(true);

    // Verify the logo img tag has onerror handler
    const logoImg = adminPage.locator('img[src*="cache/logo/mothership.png"]');
    const count = await logoImg.count();

    if (count > 0) {
      const onerror = await logoImg.getAttribute('onerror');
      expect(onerror).toBeTruthy();
      expect(onerror).toContain('none');
    }

    // Confirm no visible broken images in the dropdown
    const brokenImages = await adminPage.evaluate(() => {
      const imgs = document.querySelectorAll('img[src*="cache/logo"]');
      return Array.from(imgs).filter(img =>
        img.style.display !== 'none' && img.complete && img.naturalWidth === 0
      ).length;
    });
    expect(brokenImages).toBe(0);
  });
});
