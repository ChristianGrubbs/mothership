const { test, expect } = require('@playwright/test');
const { uploadAsset, solidJpeg, createDirectory, attachFile } = require('./helpers');

/**
 * Stack preview Next, as a person uses it: open a stack from its Library card, pick one of
 * the stacked files in the preview's strip, press Next. The preview should stay in the
 * stack and show the stack's next file, not jump to the next Library result.
 *
 * Known defect F4 ("2026-10-14 Live stack viewing UX review", S2, not yet fixed): the
 * preview's arrows walk the Library results around the stack, so Next lands on the
 * neighbouring Library asset. The spec is marked as
 * an expected failure until F4 ships; once it does, Playwright reports the unexpected pass
 * and the marker has to come off, so the acceptance suite cannot silently keep the old
 * behaviour.
 */
test('Next in a stacked file\'s preview moves to the next file of the same stack', async ({ page }, testInfo) => {
    await page.goto('/admin/dam/assets');
    const directoryId = await createDirectory(page, 'acceptance-stack');
    const stamp = Date.now();
    // A Library neighbour on either side of the stack, so leaving the stack is always visible.
    const before = await uploadAsset(page, { name: `acceptance-neighbour-a-${stamp}.jpg`, directoryId, buffer: await solidJpeg(page, { width: 800, height: 600, rgb: [30, 160, 60] }) });
    const stack = await uploadAsset(page, { name: `acceptance-stack-cover-${stamp}.jpg`, directoryId, buffer: await solidJpeg(page, { width: 800, height: 600, rgb: [200, 40, 40] }) });
    const after = await uploadAsset(page, { name: `acceptance-neighbour-b-${stamp}.jpg`, directoryId, buffer: await solidJpeg(page, { width: 800, height: 600, rgb: [60, 60, 60] }) });
    for (const [index, rgb] of [[1, [40, 40, 200]], [2, [220, 200, 40]]]) {
        await attachFile(page, stack.id, { name: `acceptance-stack-member-${index}-${stamp}.jpg`, buffer: await solidJpeg(page, { width: 800, height: 600, rgb }) });
    }
    const neighbourNames = [before.fileName, after.fileName];

    // Fixture failures must fail the test, not masquerade as the known navigation defect.
    // ACCEPTANCE_PROBE=1 drops the marker to watch the real failure (or confirm a fix) locally.
    if (! process.env.ACCEPTANCE_PROBE) test.fail(true, 'F4: preview arrows walk Library results, not stack siblings (deferred S2)');
    testInfo.annotations.push({ type: 'known-defect', description: 'F4 2026-10-14 Live stack viewing UX review' });

    // The classic gallery Library (production runs it; the explorer's stack fan is behind
    // DAM_EXPLORER_ENABLED): open the stack from its card, then pick a stacked file in the strip.
    await page.goto(`/admin/dam/assets?filters[directory_id][]=${directoryId}`);
    await page.getByRole('img', { name: stack.fileName }).or(page.locator(`img[alt="${stack.fileName}"]`)).first()
        .or(page.getByText(stack.fileName.slice(0, 20)).first()).first().click();

    const strip = page.locator('[data-dam-attachment-strip]').first();
    await expect(strip).toBeVisible({ timeout: 30_000 });
    const items = strip.locator('[data-dam-strip-item]');
    await expect(items).toHaveCount(3);
    await items.nth(1).click();

    // The stack opens on its details page; the strip now shows member 1 as "Viewing".
    await expect(page).toHaveURL(new RegExp(`/admin/dam/assets/edit/${stack.id}`));
    const member = (index) => new RegExp(`acceptance-stack-member-${index}-${stamp}`);
    await expect(page.getByText(member(1)).first()).toBeVisible();

    // The main preview's Next arrow (it shows on hover) — the control F4 is about.
    await page.locator('[data-preview-frame]').first().hover();
    await page.getByRole('button', { name: 'Next', exact: true }).first().click();

    // Next must stay in the stack and show member 2, not hop to a neighbouring Library asset.
    await page.waitForTimeout(1_500);
    const url = page.url();
    expect(url, `Next left the stack for ${url}`).toMatch(new RegExp(`/admin/dam/assets/edit/${stack.id}(\\b|\\?|$)`));
    for (const neighbour of neighbourNames) {
        await expect(page.getByText(neighbour.replace(/\.jpg$/, '')).first()).toBeHidden();
    }
    await expect(page.getByText(member(2)).first()).toBeVisible();
});
