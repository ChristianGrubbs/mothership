const {test: base, expect, chromium} = require('@playwright/test');
const test = base.extend({browser: [async ({}, use) => {
    if (!process.env.MOTHERSHIP_CHROME_LAUNCHER) {
        const browser = await chromium.launch();
        try { await use(browser); } finally { await browser.close(); }
        return;
    }
    const {launchChrome} = await import(process.env.MOTHERSHIP_CHROME_LAUNCHER);
    const browser = await launchChrome({chromium, playwrightVersion: require('playwright/package.json').version});
    try { await use(browser); } finally { await browser.close(); }
}, {scope: 'worker'}]});

// Dedicated seeded stack: callers choose the asset; no production data is modified.
const assetId = process.env.DAM_STACK_INSPECTION_ASSET;
const memberCount = Number(process.env.DAM_STACK_INSPECTION_COUNT || 15);
test.skip(!assetId, 'Set DAM_STACK_INSPECTION_ASSET to a seeded 15-member stack.');

for (const [width, height] of [[375,812],[768,1024],[1024,768],[1440,900]]) {
    test(`stack inspection fits ${width}×${height}`, async ({page}) => {
        await page.setViewportSize({width,height});
        await page.goto(`/admin/dam/assets/edit/${assetId}?file=2`);
        const strip = page.locator('[data-dam-inline-stack]');
        await expect(strip.locator('[data-dam-strip-item]')).toHaveCount(memberCount);
        for (const target of [strip, page.locator('[data-dam-preview-card]')]) {
            const box = await target.boundingBox();
            expect(box.x).toBeGreaterThanOrEqual(0);
            expect(box.x + box.width).toBeLessThanOrEqual(width + 1);
        }
        if (width === 375) expect((await strip.boundingBox()).y).toBeLessThan(height);
        const first = strip.locator('[data-dam-strip-item]').first();
        await first.focus();
        await page.keyboard.press('End');
        await expect(strip.locator('[data-dam-strip-item]').last()).toBeFocused();
        await page.keyboard.press('Enter');
        await expect(page).toHaveURL(new RegExp(`file=${memberCount}(?:&|$)`));
        await page.goto(`/admin/dam/assets/edit/${assetId}?files&file=15`);
        await expect(page.locator('[data-dam-file-row]').first()).toBeVisible();
        const rowGeometry = await page.locator('[data-dam-file-row]').evaluateAll(rows => rows.slice(0,2).map(row => {
            const role = row.querySelector('[data-dam-file-role]');
            const style = getComputedStyle(role);
            return {
                roleHeight: role.getBoundingClientRect().height,
                singleRoleHeight: parseFloat(style.lineHeight) + parseFloat(style.paddingTop) + parseFloat(style.paddingBottom),
                actions: [...row.querySelectorAll('[data-dam-file-actions] button,[data-dam-file-actions] a')].map(action => ({
                    left:action.getBoundingClientRect().left,right:action.getBoundingClientRect().right,
                    top:action.getBoundingClientRect().top,
                    border:parseFloat(getComputedStyle(action).borderLeftWidth),
                    destructive:action.textContent.trim()==='Remove',
                })),
            };
        }));
        for (const row of rowGeometry) {
            expect(row.roleHeight).toBeLessThanOrEqual(row.singleRoleHeight + 1);
            for (const action of row.actions) {
                expect(action.left).toBeGreaterThanOrEqual(0);
                expect(action.right).toBeLessThanOrEqual(width + 1);
                if (width < 1240 && action.destructive) expect(action.border).toBe(0);
            }
            for (let index=1;index<row.actions.length;index++) {
                const previous=row.actions[index-1],current=row.actions[index];
                if (Math.abs(previous.top-current.top)<2) expect(current.left-previous.right).toBeGreaterThanOrEqual(8);
            }
        }
        const order = await page.evaluate(() => {
            const row = document.querySelector('[data-dam-file-row]');
            return [...document.querySelectorAll('[data-capture-files],[data-dam-stack],[data-dam-attach-section]')]
                .every(section => !!(row.compareDocumentPosition(section) & Node.DOCUMENT_POSITION_FOLLOWING) && !section.open);
        });
        expect(order).toBe(true);
    });
}

test('a 100-member chooser exposes the last file without original downloads', async ({page}) => {
    await page.route(new RegExp(`/${assetId}/files(?:\\?|$)`), async route => {
        const response = await route.fetch();
        const data = await response.json();
        const original = data.files;
        data.files = Array.from({length:100}, (_,index) => ({...original[index % original.length],
            id:index+1, attachment_id:index+1, asset_id:Number(assetId), is_representative:index===0,
            file_name:`Member_${index+1}.jpg`, label:`Member_${index+1}.jpg`}));
        await route.fulfill({response,json:data});
    });
    const originals = [];
    page.on('request', request => {
        const url = new URL(request.url());
        if (/\/files\/\d+$/.test(url.pathname) && !url.searchParams.has('thumb')) originals.push(url.href);
    });
    await page.goto('/admin/dam/assets?directory_id=all');
    await page.locator('[data-dam-stack-open]').first().click();
    const chooser = page.locator('[data-dam-stack-fan]');
    await expect(chooser.locator('[data-dam-stack-fan-item]')).toHaveCount(100);
    await chooser.locator('[data-dam-stack-fan-item]').first().focus();
    await page.keyboard.press('End');
    await expect(chooser.locator('[data-dam-stack-fan-item]').last()).toBeFocused();
    expect(originals).toEqual([]);
    await expect(chooser.getByText(/show all/i)).toHaveCount(0);
});

test('a text member opens its media-appropriate viewer', async ({page}) => {
    test.skip(!process.env.DAM_STACK_INSPECTION_TEXT_FILE, 'Optional seeded text member.');
    await page.goto(`/admin/dam/assets/edit/${assetId}?file=${process.env.DAM_STACK_INSPECTION_TEXT_FILE}`);
    await page.locator('[data-dam-text-expand]').click();
    await expect(page.locator('[data-dam-file-text] pre')).toContainText('Local text member for inspection.');
});
