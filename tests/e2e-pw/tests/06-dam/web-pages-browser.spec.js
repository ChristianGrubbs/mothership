const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { randomUUID } = require('crypto');

/**
 * Same seed strategy as web-pages.spec.js: an authenticated multipart POST to
 * the Library upload endpoint, reusing the Laravel SPA-CSRF cookie already set
 * by global setup, since the browser's own "Upload here" dialog is exercised
 * by the click path below and does not need a second, redundant seed helper.
 */
async function uploadFixture(page, { fileName = 'two-file-bundle.zip', directoryId = 1 } = {}) {
  const xsrfToken = await page.evaluate(() => {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : null;
  });
  expect(xsrfToken, 'XSRF-TOKEN cookie must be present after the authenticated global-setup login').not.toBeNull();

  const fixturePath = path.join(__dirname, '../../fixtures/web-pages', fileName);

  const response = await page.request.post('/admin/dam/assets/upload', {
    headers: { 'X-XSRF-TOKEN': xsrfToken },
    multipart: {
      directory_id: String(directoryId),
      'files[]': {
        name: fileName,
        mimeType: 'application/zip',
        buffer: fs.readFileSync(fixturePath),
      },
    },
  });

  expect(response.ok(), `upload endpoint responded ${response.status()}: ${await response.text()}`).toBeTruthy();
  const body = await response.json();

  return body.files[0];
}

// Local dev server only: not run in CI/local worktrees without a booted admin app.
// Unverified locally in this worktree -- no dev server is available here (see task report).
test.describe.configure({ mode: 'serial' });

test.describe('Web Pages browser: folder create, upload here, drag move, delete reparent', () => {
  test('a folder can be created, a page uploaded into it, dragged to root and the folder deleted', async ({ page }) => {
    const folderName = `Smoke-${randomUUID()}`;
    await page.goto('/admin/dam/pages');

    // 1. New folder "Smoke".
    await page.getByRole('button', { name: /new page group/i }).click();
    await page.getByRole('dialog', { name: /new page group/i }).getByRole('textbox').fill(folderName);
    await page.getByRole('dialog', { name: /new page group/i }).getByRole('button', { name: /save/i }).click();
    const folderCard = page.locator('[data-web-collection]', { hasText: folderName });
    await expect(folderCard).toBeVisible();
    const folderId = await folderCard.getAttribute('data-web-collection');

    // 2. Open the folder, then Upload here (the two-file-bundle fixture).
    await folderCard.click();
    await page.getByRole('button', { name: /upload here/i }).click();
    await page.setInputFiles('input[type="file"]', {
      name: `${folderName}.zip`,
      mimeType: 'application/zip',
      buffer: fs.readFileSync(path.join(__dirname, '../../fixtures/web-pages/two-file-bundle.zip')),
    });
    await page.locator('[data-action="upload-start"]').click();
    await expect(page.locator('[data-upload-row]', { hasText: /placed/i })).toBeVisible({ timeout: 30000 });
    await page.locator('[data-dialog="upload"]').getByRole('button', { name: 'Close', exact: true }).click();

    // 3. The card appears inside "Smoke".
    const pageCard = page.locator('[data-web-page]').first();
    await expect(pageCard).toBeVisible({ timeout: 30000 });
    const assetId = await pageCard.getAttribute('data-web-page');

    // 4. Drag the card to the "Web Pages" breadcrumb segment (root).
    const rootCrumb = page.locator('[data-web-pages-breadcrumb] a', { hasText: 'Web Pages' });
    await pageCard.dragTo(rootCrumb);
    await page.goto('/admin/dam/pages');
    await expect(page.locator(`[data-web-page="${assetId}"]`)).toBeVisible();

    // 5. Delete "Smoke": the confirm text names 0 subfolders and 0 pages moving to Unfiled.
    await page.locator(`[data-web-collection-menu="${folderId}"]`).click();
    await page.getByRole('menuitem', { name: /delete/i }).click();
    await expect(page.getByText('0 nested page groups and 0 pages move to Unfiled')).toBeVisible();
    await page.getByRole('button', { name: /^delete$/i }).click();
    await expect(page.locator('[data-web-collection]', { hasText: folderName })).toHaveCount(0);
  });
});
