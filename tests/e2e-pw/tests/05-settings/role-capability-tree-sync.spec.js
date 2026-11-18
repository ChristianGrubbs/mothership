const { test, expect } = require('../../utils/fixtures');
const { navigateTo, generateUid } = require('../../utils/helpers');

const checkedPermissions = (page) =>
  page.evaluate(() =>
    [...document.querySelectorAll('input[name="permissions[]"]')]
      // Always-on permissions (Admin::HUMAN_ALWAYS_ON_PERMISSIONS) show ticked but disabled: they are not a choice.
      .filter((box) => box.checked && ! box.disabled)
      .map((box) => box.value)
      .sort()
  );

const tickedCapabilities = (page) =>
  page.evaluate(() => [...document.querySelectorAll('#dam-capability-options input:checked')].map((box) => box.value));

const openAdvanced = (page) => page.locator('summary', { hasText: 'Advanced permissions' }).click();

const permissionBox = (page, key) => page.locator(`input[name="permissions[]"][value="${key}"]`);

/**
 * Creates a custom role the way the Create form posts it (hidden fields included), from the Create page so the
 * CSRF token and session are the real ones. Returns the new role's id, read back from the roles grid data.
 */
async function createRole(page, { name, permissions, capabilities = [] }) {
  await page.goto('/admin/settings/roles/create', { waitUntil: 'networkidle' });

  const status = await page.evaluate(
    async ({ name, permissions, capabilities }) => {
      const form = new FormData();

      form.append('_token', document.querySelector('input[name="_token"]').value);
      form.append('name', name);
      form.append('permission_type', 'custom');
      form.append('dam_role_builder_managed', '1');
      permissions.forEach((key) => form.append('permissions[]', key));
      capabilities.forEach((key) => form.append('dam_capabilities[]', key));

      const response = await fetch('/admin/settings/roles/create', {
        method: 'POST',
        body: form,
        headers: { Accept: 'application/json' },
      });

      return response.status;
    },
    { name, permissions, capabilities }
  );

  expect(status).toBe(200);

  return page.evaluate(async (name) => {
    const response = await fetch('/admin/settings/roles?pagination[per_page]=100&sort[column]=id&sort[order]=desc', {
      headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    });

    return (await response.json()).records.find((record) => record.name === name)?.id;
  }, name);
}

async function openRole(page, id) {
  await page.goto(`/admin/settings/roles/edit/${id}`, { waitUntil: 'networkidle' });
  await page.waitForTimeout(800);

  return id;
}

async function deleteRole(page, id) {
  if (!id) return;

  await page.evaluate(async (id) => {
    const token = document.querySelector('meta[name="csrf-token"]')?.content || '';

    await fetch(`/admin/settings/roles/edit/${id}`, {
      method: 'DELETE',
      headers: { Accept: 'application/json', 'X-CSRF-TOKEN': token, 'X-XSRF-TOKEN': decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '') },
    });
  }, id);
}

async function saveEdit(page) {
  await page.locator('.unsaved-bar').getByRole('button', { name: 'Save changes' }).click();
  await expect(page.locator('#app').getByText(/updated successfully/i).first()).toBeVisible({ timeout: 20000 });
}

test.describe('Role capabilities and permission tree stay in step', () => {
  test('a new role opens with no capability ticked and a visible Save', async ({ adminPage }) => {
    await navigateTo(adminPage, 'roles');
    await adminPage.getByRole('link', { name: 'Create Role' }).click();
    await adminPage.waitForLoadState('networkidle');

    await expect(adminPage.getByRole('button', { name: 'Save Role' })).toBeVisible();
    await expect(adminPage.locator('#dam-capability-options input:checked')).toHaveCount(0);
    expect(await checkedPermissions(adminPage)).toEqual([]);
  });

  test('ticking a capability ticks its permissions, and unticking one share key unticks the capability', async ({ adminPage }) => {
    await navigateTo(adminPage, 'roles');
    await adminPage.getByRole('link', { name: 'Create Role' }).click();
    await adminPage.waitForLoadState('networkidle');

    await adminPage.locator('#dam-capability-options input[value="public_shares"]').check();
    expect(await checkedPermissions(adminPage)).toEqual(expect.arrayContaining(['dam.asset.share', 'dam.directory.share']));

    await openAdvanced(adminPage);
    await permissionBox(adminPage, 'dam.asset.share').evaluate((box) => box.click());

    await expect(adminPage.locator('#dam-capability-options input[value="public_shares"]')).not.toBeChecked();
  });

  test('a saved role with public links, switched off and one share key reselected, shows what it persists', async ({ adminPage }) => {
    const name = `Sync ${generateUid()}`;

    const id = await createRole(adminPage, {
      name,
      permissions: ['dam', 'dam.asset', 'dam.directory', 'dam.asset.share', 'dam.directory.share'],
      capabilities: ['public_shares'],
    });

    await openRole(adminPage, id);

    try {
      expect(await tickedCapabilities(adminPage)).toEqual(['public_shares']);

      await openAdvanced(adminPage);
      await adminPage.locator('#dam-capability-options input[value="public_shares"]').uncheck();
      await permissionBox(adminPage, 'dam.asset.share').evaluate((box) => box.click());

      // The save would remove the reselected key, so the tree already shows it removed.
      await expect(permissionBox(adminPage, 'dam.asset.share')).not.toBeChecked();
      await expect(adminPage.locator('#dam-capability-sync-note')).toContainText('dam.asset.share');

      const displayed = await checkedPermissions(adminPage);

      await saveEdit(adminPage);
      await adminPage.reload({ waitUntil: 'networkidle' });
      await openAdvanced(adminPage);

      expect(await checkedPermissions(adminPage)).toEqual(displayed);
      expect(displayed).not.toContain('dam.asset.share');
      expect(displayed).not.toContain('dam.directory.share');
    } finally {
      await deleteRole(adminPage, id);
    }
  });

  test('a saved role holding only dam.duplicates.view shows a partial parent and survives an unrelated save', async ({ adminPage }) => {
    const name = `Child ${generateUid()}`;

    const id = await createRole(adminPage, { name, permissions: ['dam.duplicates.view'] });

    await openRole(adminPage, id);

    try {
      await openAdvanced(adminPage);

      const parent = permissionBox(adminPage, 'dam.duplicates');

      // The branch above it is collapsed; its label opens it without ticking anything.
      if (!(await parent.locator('xpath=following-sibling::div[1]').isVisible())) {
        await permissionBox(adminPage, 'dam').locator('xpath=following-sibling::div[1]').click();
      }

      await expect(parent).not.toBeChecked();
      await expect(permissionBox(adminPage, 'dam.duplicates.view')).toBeChecked();
      await expect(parent.locator('xpath=following-sibling::span[1]')).toHaveClass(/icon-checkbox-partial/);

      // The label expands or collapses the branch; it must not tick it.
      await parent.locator('xpath=following-sibling::div[1]').click();
      await expect(parent).not.toBeChecked();

      await adminPage.locator('#description').fill('edited by the child-only case');
      await saveEdit(adminPage);
      await adminPage.reload({ waitUntil: 'networkidle' });
      await openAdvanced(adminPage);

      expect(await checkedPermissions(adminPage)).toEqual(['dam.duplicates.view']);
    } finally {
      await deleteRole(adminPage, id);
    }
  });

  test('Discard after a save restores the saved widgets, not the page-load ones', async ({ adminPage }) => {
    const name = `Widgets ${generateUid()}`;

    const id = await createRole(adminPage, {
      name,
      permissions: ['dam', 'dam.assets', 'dam.asset', 'dam.asset.view', 'dam.asset.upload', 'dam.asset.download', 'dam.directory', 'dam.directory.index', 'dam.directory.store'],
      capabilities: ['library'],
    });

    await openRole(adminPage, id);
    const widget = adminPage.locator('#dam-home-widget-options input[value="recent"]');

    try {
      const initiallyChecked = await widget.isChecked();

      await widget.setChecked(!initiallyChecked);
      await saveEdit(adminPage);

      await adminPage.locator('#description').fill('unrelated edit');
      await adminPage.locator('.unsaved-bar').getByRole('button', { name: 'Discard' }).click();
      await adminPage.locator('button.danger-button').click();
      await expect(adminPage.locator('.unsaved-bar')).toBeHidden();

      expect(await widget.isChecked()).toBe(!initiallyChecked);
    } finally {
      await deleteRole(adminPage, id);
    }
  });

  test('under lists access a new role keeps the six share keys through an unrelated edit, Discard, save and reload', async ({ adminPage }) => {
    const shareKeys = ['dam.asset.share', 'dam.directory.share', 'dam.shares', 'dam.shares.index', 'dam.shares.revoke', 'dam.shares.delete'];
    const name = `Lists ${generateUid()}`;

    await adminPage.goto('/admin/settings/roles/create', { waitUntil: 'networkidle' });

    // Needs a runtime whose DAM_ACCESS_MODEL is lists: the folder-grant panel is not rendered there.
    test.skip((await adminPage.locator('#dam-all-directories-toggle').count()) > 0, 'runtime is in grants mode');

    await openAdvanced(adminPage);

    const shareKeysTicked = async () => (await checkedPermissions(adminPage)).filter((key) => shareKeys.includes(key)).sort();

    await expect.poll(shareKeysTicked).toEqual([...shareKeys].sort());

    await adminPage.locator('#description').fill('unrelated edit');
    await adminPage.locator('.unsaved-bar').getByRole('button', { name: 'Discard' }).click();
    await adminPage.locator('button.danger-button').click();
    await expect(adminPage.locator('.unsaved-bar')).toBeHidden();

    await expect.poll(shareKeysTicked).toEqual([...shareKeys].sort());

    await adminPage.getByRole('textbox', { name: 'Name' }).fill(name);
    await adminPage.getByRole('button', { name: 'Save Role' }).click();
    await adminPage.waitForURL(/\/settings\/roles$/);

    const id = await adminPage.evaluate(async (name) => {
      const response = await fetch('/admin/settings/roles?pagination[per_page]=100&sort[column]=id&sort[order]=desc', {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      });

      return (await response.json()).records.find((record) => record.name === name)?.id;
    }, name);

    try {
      await openRole(adminPage, id);
      await openAdvanced(adminPage);

      await expect.poll(shareKeysTicked).toEqual([...shareKeys].sort());
    } finally {
      await deleteRole(adminPage, id);
    }
  });
});
