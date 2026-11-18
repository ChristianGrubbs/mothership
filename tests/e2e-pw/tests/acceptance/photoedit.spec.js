const { test, expect } = require('@playwright/test');
const { uploadAsset, editUrl, solidJpeg, expectNoHorizontalScroll } = require('./helpers');

/**
 * PhotoEdit opened the way a person opens it: from the asset's own details page, through
 * the "Edit image" button, at 1440, 1024 and 375 px (one project each). The editor must load
 * the real working document from the server, fit inside the viewport with its tool bar and
 * stage visible, and close again without leaving the page in a scrolled or covered state.
 */
test('PhotoEdit opens from the asset page and fits the viewport', async ({ page }) => {
    await page.goto('/admin/dam/assets');
    // 2400x1600 is larger than every viewport here, so the stage has to fit it rather than overflow.
    const asset = await uploadAsset(page, {
        name: `acceptance-photoedit-${Date.now()}.jpg`,
        buffer: await solidJpeg(page, { width: 2400, height: 1600, rgb: [40, 120, 200] }),
    });

    await page.goto(editUrl(asset.id));
    await expect(page).toHaveURL(new RegExp(`/admin/dam/assets/edit/${asset.id}`));
    await expectNoHorizontalScroll(page);

    const editButton = page.getByRole('button', { name: 'Edit image', exact: true });
    await expect(editButton).toBeVisible();

    const documentLoaded = page.waitForResponse((response) => response.url().includes(`/admin/dam/assets/editor/${asset.id}`)
        && response.request().method() === 'GET' && ! response.url().includes('/resources/'));
    await editButton.click();
    expect((await documentLoaded).status(), 'the editor working document must load').toBe(200);

    const overlay = page.locator('[data-dam-photoedit-overlay]');
    await expect(overlay).toBeVisible();
    await expect(overlay).toHaveAttribute('role', 'dialog');
    await expect(overlay.locator('.pe-top')).toBeVisible();
    await expect(overlay.locator('.pe-stage')).toBeVisible();

    const viewport = page.viewportSize();
    const box = await overlay.locator('.pe-root').boundingBox();
    expect(box, 'editor root has no layout box').not.toBeNull();
    expect(box.x).toBeGreaterThanOrEqual(-1);
    expect(box.y).toBeGreaterThanOrEqual(-1);
    expect(box.x + box.width).toBeLessThanOrEqual(viewport.width + 1);
    expect(box.y + box.height).toBeLessThanOrEqual(viewport.height + 1);

    // Every tool-bar control the person needs must be reachable on screen, not clipped off the side.
    const offscreen = await overlay.locator('.pe-top button:visible').evaluateAll((buttons, width) => buttons
        .map((button) => button.getBoundingClientRect())
        .filter((rect) => rect.right > width + 1 || rect.left < -1).length, viewport.width);
    expect(offscreen, 'tool-bar buttons outside the viewport').toBe(0);

    const stage = await overlay.locator('.pe-stage').boundingBox();
    expect(stage.width).toBeGreaterThan(viewport.width * 0.5);
    expect(stage.height).toBeGreaterThan(120);
    await expectNoHorizontalScroll(page);

    await overlay.getByRole('button', { name: 'Close', exact: true }).first().click();
    await expect(overlay).toBeHidden();
    await expect(editButton).toBeVisible();
    await expectNoHorizontalScroll(page);
});
