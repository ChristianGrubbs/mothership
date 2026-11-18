const { test, expect } = require('../../utils/fixtures');
const { navigateTo, generateUid, clickSaveAndExpect } = require('../../utils/helpers');

async function togglePermission(adminPage, value) {
  const box = adminPage.locator(`input[type="checkbox"][name="permissions[]"][value="${value}"]`);

  await box.waitFor({ state: 'attached', timeout: 15000 });

  // The tree sits inside the collapsed "Advanced permissions" details.
  const summary = adminPage.locator('summary', { hasText: 'Advanced permissions' });

  if (await summary.count()) {
    const opened = await summary.evaluate((node) => node.parentElement.open);

    if (!opened) {
      await summary.click();
    }
  }

  const id = await box.getAttribute('id');

  await adminPage.locator(`label[for="${id}"] span`).first().click();
}

async function instrumentFlashes(adminPage) {
  await adminPage.evaluate(() => {
    sessionStorage.setItem('pwFlashes', '[]');

    window.__pwNoReload = true;

    const record = (node) => {
      if (node.nodeType !== 1 || !node.matches('[role="alert"]')) {
        return;
      }

      const seen = JSON.parse(sessionStorage.getItem('pwFlashes') || '[]');

      seen.push({
        type: /border-l-red|border-r-red/.test(node.className) ? 'error' : 'other',
        message: (node.textContent || '').replace(/\s+/g, ' ').trim(),
      });

      sessionStorage.setItem('pwFlashes', JSON.stringify(seen));
    };

    new MutationObserver((mutations) => {
      mutations.forEach((mutation) => mutation.addedNodes.forEach(record));
    }).observe(document.body, { childList: true, subtree: true });
  });
}

async function readFlashes(adminPage) {
  return adminPage.evaluate(() => JSON.parse(sessionStorage.getItem('pwFlashes') || '[]'));
}

async function delaySaveResponse(adminPage, ms = 1500) {
  await adminPage.route('**/admin/settings/roles/edit/**', async (route) => {
    if (route.request().method() === 'GET') {
      return route.continue();
    }

    await new Promise((resolve) => setTimeout(resolve, ms));

    return route.continue();
  });
}

test.describe('Role edit header save', () => {
  test('the header Save Role button survives unsaved-changes tracking and saves a clean form', async ({ adminPage }) => {
    await navigateTo(adminPage, 'roles');

    await adminPage.locator('#app').locator('[data-row-action="edit"]').first().click();
    await adminPage.waitForURL(/\/settings\/roles\/edit\/\d+/);
    await adminPage.waitForLoadState('networkidle');

    // Unsaved-changes tracking strips save buttons on mount and again 400 ms later.
    await adminPage.waitForTimeout(800);

    const headerSave = adminPage.locator('button[form="role-edit-form"]');

    await expect(headerSave).toBeVisible();

    await headerSave.click();

    await expect(
      adminPage.locator('#app').getByText(/updated successfully/i).first()
    ).toBeVisible({ timeout: 20000 });
  });
});

test.describe('Role save posts over AJAX', () => {
  let createdRoleName = null;

  // Runs whether the test passed or failed, and fails the test if the role cannot be removed, so a leftover role is
  // never silent.
  test.afterEach(async ({ adminPage }) => {
    if (!createdRoleName) return;

    const name = createdRoleName;

    createdRoleName = null;

    await adminPage.goto('/admin/settings/roles', { waitUntil: 'domcontentloaded' });

    const result = await adminPage.evaluate(async (name) => {
      const list = await fetch('/admin/settings/roles?pagination[per_page]=100&sort[column]=id&sort[order]=desc', {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      });
      const id = (await list.json()).records.find((record) => record.name === name)?.id;

      if (!id) return { found: false };

      const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
      const response = await fetch(`/admin/settings/roles/edit/${id}`, {
        method: 'DELETE',
        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': token },
      });

      return { found: true, status: response.status };
    }, name);

    // A test that failed before saving never created the role; one that created it must delete it.
    if (result.found) {
      expect(result.status, `deleting leftover role "${name}"`).toBe(200);
    }
  });

  test('updating a role raises no error toast and does not reload the page', async ({ adminPage }) => {
    const name = `AjaxRole ${generateUid()}`;

    createdRoleName = name;

    await navigateTo(adminPage, 'roles');
    await adminPage.getByRole('link', { name: 'Create Role' }).click();
    await adminPage.waitForLoadState('networkidle');

    await togglePermission(adminPage, 'dashboard');
    await togglePermission(adminPage, 'settings');

    await adminPage.getByRole('textbox', { name: 'Name' }).fill(name);
    await adminPage.getByRole('textbox', { name: 'Description' }).fill('ajax submit regression');

    await clickSaveAndExpect(adminPage, 'Save changes', /Roles Created Successfully/i);

    // The new role is on the first grid page, so the row is found by its name rather than through the search box.
    await navigateTo(adminPage, 'roles');

    const row = () => adminPage.locator('div.row.grid.cursor-pointer').filter({ hasText: name }).first();

    await row().locator('[data-row-action="edit"]').click();
    await adminPage.waitForLoadState('networkidle');

    await instrumentFlashes(adminPage);
    await delaySaveResponse(adminPage);

    await togglePermission(adminPage, 'settings');

    await adminPage.getByRole('button', { name: 'Save changes' }).click();

    await expect(
      adminPage.locator('#app').getByText(/updated successfully/i).first()
    ).toBeVisible({ timeout: 20000 });

    const errors = (await readFlashes(adminPage)).filter((flash) => flash.type === 'error');

    expect(errors, `unexpected error toast(s): ${JSON.stringify(errors)}`).toEqual([]);

    await expect
      .poll(() => adminPage.evaluate(() => window.__pwNoReload === true))
      .toBe(true);
  });
});
