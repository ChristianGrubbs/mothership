'use strict';
const {test} = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');

// Explicit authenticated local-app lane; intentionally outside the required browser DOM suite.
test('native preview close watcher preserves similarity through reload, Clear and browser history', async () => {
    const required = ['MOTHERSHIP_SIMILARITY_QA_URL', 'MOTHERSHIP_SIMILARITY_QA_AUTH', 'MOTHERSHIP_PLAYWRIGHT_MODULE_PATH'];
    const missing = required.filter(name => !process.env[name]);
    if (missing.length) throw new Error('Native similarity QA missing required environment variables: ' + missing.join(', '));
    const {pathToFileURL} = require('node:url');
    const {homedir} = require('node:os');
    const {chromium} = require(process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH);
    const {launchChrome} = await import(pathToFileURL(path.join(homedir(), 'ai-stack/tools/chrome-launch.mjs')).href);
    const browser = await launchChrome({chromium, playwrightVersion: require(path.join(process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH, 'package.json')).version});
    let releaseBack = () => {};
    try {
        const context = await browser.newContext({baseURL: process.env.MOTHERSHIP_SIMILARITY_QA_URL, storageState: process.env.MOTHERSHIP_SIMILARITY_QA_AUTH});
        const page = await context.newPage(), errors = [];
        let documents = 0;
        let holdBack = false, backStarted;
        const backPending = new Promise(resolve => {backStarted = resolve;});
        await page.route('**/explorer/data?**', async route => {
            if (holdBack && new URL(route.request().url()).searchParams.has('similar_to')) {
                holdBack = false;
                backStarted();
                await new Promise(resolve => {releaseBack = resolve;});
            }
            await route.continue();
        });
        page.on('pageerror', error => errors.push(error.message));
        page.on('request', request => {if (request.resourceType() === 'document') documents++;});
        const directory = process.env.MOTHERSHIP_SIMILARITY_QA_DIRECTORY || '2';
        await page.goto('/admin/dam/assets?directory_id=' + encodeURIComponent(directory));
        const openMain = page.getByRole('button', {name: 'Open the main file straight away, without choosing from the stack'}).first();
        const findSimilar = page.locator('[data-dam-preview-find-similar]');
        const banner = page.locator('[data-similar-banner]');
        // Ordinary close still consumes its preview entry and leaves the original folder in place.
        await openMain.click(); await findSimilar.waitFor({state: 'visible'});
        await page.locator('[data-dam-preview-back]').click(); await findSimilar.waitFor({state: 'hidden'});
        await page.waitForTimeout(300);
        assert.equal(new URL(page.url()).searchParams.get('directory_id'), directory);
        assert.equal(documents, 1);
        // The native close watcher and explorer transition must agree which history entry they own.
        await openMain.click(); await findSimilar.click(); await banner.waitFor({state: 'visible', timeout: 5000});
        await page.waitForTimeout(1200); assert.equal(await banner.isVisible(), true);
        assert.ok(new URL(page.url()).searchParams.get('similar_to')); assert.equal(documents, 1);
        await page.reload(); await banner.waitFor({state: 'visible'});
        await page.waitForTimeout(300); assert.equal(await banner.isVisible(), true);
        await banner.locator('button').click(); await banner.waitFor({state: 'hidden'}); await openMain.waitFor({state: 'visible'});
        await page.waitForURL(url => !url.searchParams.has('similar_to'));
        assert.equal(new URL(page.url()).searchParams.get('directory_id'), directory);
        holdBack = true;
        await page.goBack(); await backPending; await banner.waitFor({state: 'visible'}); await page.waitForURL(url => url.searchParams.has('similar_to'));
        await page.goForward(); await banner.waitFor({state: 'hidden'}); await openMain.waitFor({state: 'visible'});
        await page.waitForURL(url => !url.searchParams.has('similar_to'));
        releaseBack(); await page.waitForTimeout(300); assert.equal(await banner.count(), 0);
        assert.equal(new URL(page.url()).searchParams.get('directory_id'), directory);
        assert.deepEqual(errors, []);
    } finally {releaseBack(); await browser.close();}
});
