// Real Vue + IntersectionObserver acceptance against a running Mothership login page.
// BASE_URL selects the app; PLAYWRIGHT_CHROMIUM_CHANNEL optionally selects Chrome.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('playwright');

(async () => {
    assert(process.env.BASE_URL, 'BASE_URL is required');
    const browser = await chromium.launch({
        headless: true,
        ...(process.env.PLAYWRIGHT_CHROMIUM_CHANNEL ? { channel: process.env.PLAYWRIGHT_CHROMIUM_CHANNEL } : {}),
    });
    try {
        const page = await browser.newPage({ viewport: { width: 1000, height: 700 } });
        const requested = [];
        await page.route('**/__thumbnail_acceptance__/**', async route => {
            requested.push(new URL(route.request().url()).pathname);
            await route.fulfill({ contentType: 'image/svg+xml', body: '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect width="10" height="10" fill="blue"/></svg>' });
        });
        await page.goto(new URL('/admin/login', process.env.BASE_URL).href);
        await page.waitForFunction(() => typeof window.createAdminApp === 'function');
        const file = path.resolve(__dirname, '../../packages/Mothership/DAM/src/Resources/views/components/shared/lazy-thumbnail.blade.php');
        const source = fs.existsSync(file) ? fs.readFileSync(file, 'utf8').match(/<script type="module">([\s\S]*?)<\/script>/)[1] : '';
        await page.evaluate(source => {
            window.app.unmount();
            document.body.innerHTML = '<div id="thumbnail-fixture"><thumbnail-fixture /></div>';
            const app = window.createAdminApp();
            if (source) new Function(source)();
            window.thumbnailState = window.Vue.reactive({
                items: Array.from({ length: 50 }, (_, id) => ({ id, src: `/__thumbnail_acceptance__/${id}` })),
            });
            app.component('thumbnail-fixture', {
                setup: () => window.thumbnailState,
                template: `<div id="scroller" style="height:300px;overflow:auto"><div v-for="item in items" :key="item.id" style="height:150px"><img ${source ? 'v-dam-thumbnail' : ':src'}="item.src" :data-id="item.id" width="200" height="100"></div></div>`,
            });
            app.mount('#thumbnail-fixture');
        }, source);
        await page.waitForFunction(() => document.querySelector('img[data-id="0"]').naturalWidth > 0);
        // Give erroneous eager requests a chance to arrive before checking absence.
        await page.waitForTimeout(500);
        const initial = requested.length;
        assert(initial <= 5, `offscreen thumbnails were fetched eagerly: ${initial}/50`);
        assert(!requested.includes('/__thumbnail_acceptance__/49'));
        await page.evaluate(() => { window.thumbnailState.items[49].src = '/__thumbnail_acceptance__/replacement'; });
        await page.evaluate(() => { document.querySelector('#scroller').scrollTop = 100000; });
        await page.waitForFunction(() => document.querySelector('img[data-id="49"]').naturalWidth > 0);
        assert(requested.includes('/__thumbnail_acceptance__/replacement'));
        assert(!requested.includes('/__thumbnail_acceptance__/49'), 'recycled image requested its obsolete source');
        const beforeUnmount = requested.length;
        await page.evaluate(() => window.app.unmount());
        await page.waitForTimeout(200);
        assert.equal(requested.length, beforeUnmount, 'unmounted images still requested thumbnails');
        const fallback = await page.evaluate(source => {
            delete window.IntersectionObserver;
            document.body.innerHTML = '<div id="thumbnail-fallback"><thumbnail-fallback /></div>';
            const app = window.createAdminApp();
            new Function(source)();
            app.component('thumbnail-fallback', {
                data: () => ({ empty: '', present: '/__thumbnail_acceptance__/fallback' }),
                template: '<div><img id="empty" v-dam-thumbnail="empty"><img id="present" v-dam-thumbnail="present"></div>',
            });
            app.mount('#thumbnail-fallback');
            return {
                empty: document.querySelector('#empty').hasAttribute('src'),
                present: document.querySelector('#present').getAttribute('src'),
            };
        }, source);
        assert.equal(fallback.empty, false, 'empty sources must not request the current page');
        assert.equal(fallback.present, '/__thumbnail_acceptance__/fallback');
        console.log(JSON.stringify({ initialRequests: initial, totalItems: 50, scroll: 'passed', sourceUpdate: 'passed', unmount: 'passed', noObserver: 'passed', emptySource: 'passed' }));
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error.message); process.exitCode = 1; });
