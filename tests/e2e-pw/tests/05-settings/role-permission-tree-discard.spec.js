const { test, expect } = require('../../utils/fixtures');
const { navigateTo } = require('../../utils/helpers');

const checkedPermissions = (page) =>
  page.evaluate(() =>
    [...document.querySelectorAll('.unsaved-root input[name="permissions[]"]')]
      // Always-on permissions (Admin::HUMAN_ALWAYS_ON_PERMISSIONS) show ticked but disabled: they are not a choice.
      .filter((box) => box.checked && ! box.disabled)
      .map((box) => box.value)
  );

// The tree sits inside the collapsed "Advanced permissions" details, so its boxes are not visible until it is opened.
async function openCreateRole(page) {
  await navigateTo(page, 'roles');
  await page.getByRole('link', { name: 'Create Role' }).click();
  await page.waitForLoadState('networkidle');
  await page.locator('summary', { hasText: 'Advanced permissions' }).click();
}

async function discard(page) {
  await page.locator('.unsaved-bar').getByRole('button', { name: 'Discard' }).click();
  await page.locator('button.danger-button').click();
  await expect(page.locator('.unsaved-bar')).toBeHidden();
}

test.describe('Role permission tree discard', () => {
  test('ticking a permission then discarding clears it and nothing else', async ({ adminPage }) => {
    await openCreateRole(adminPage);

    expect(await checkedPermissions(adminPage)).toEqual([]);

    const dashboard = adminPage.locator('label:has(input[value="dashboard"]) span.icon-checkbox-normal');

    await dashboard.click();

    expect(await checkedPermissions(adminPage)).toEqual(['dashboard']);
    await expect(adminPage.locator('.unsaved-bar')).toBeVisible();

    await discard(adminPage);

    expect(await checkedPermissions(adminPage)).toEqual([]);
  });

  test('checking a permission marks the form as unsaved', async ({ adminPage }) => {
    await openCreateRole(adminPage);

    await expect(adminPage.locator('.unsaved-bar')).toBeHidden();

    await adminPage.locator('label:has(input[value="dashboard"]) span.icon-checkbox-normal').click();

    await expect(adminPage.locator('.unsaved-bar')).toBeVisible();
  });
});
