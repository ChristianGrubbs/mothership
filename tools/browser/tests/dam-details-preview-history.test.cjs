'use strict';

// Details-page lightbox history (preview-modal.blade.php), driven in Chrome by the REAL admin AJAX router
// (plugins/navigation.js), the production history methods of the details component and the production
// replaceMemberContext / details-navigation helpers. Browser Back (Command+[ on a Mac) must close the preview
// without a page visit; foreign entries must still reach the router.
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..', '..', '..');
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');
const views = 'packages/Mothership/DAM/src/Resources/views/asset/';
const details = read(views + 'preview-modal.blade.php');
const strip = read(views + 'preview-modal/attachments/strip-script.blade.php');

// One method body, found by its 12-space-indented signature and closed by brace matching.
function method(source, name) {
    const start = source.indexOf(`\n            ${name}(`);
    assert.notEqual(start, -1, `method ${name} exists`);
    let depth = 0, end = source.indexOf('{', start);
    for (let i = end; i < source.length; i++) {
        if (source[i] === '{') depth++;
        if (source[i] === '}' && --depth === 0) { end = i + 1; break; }
    }

    return source.slice(start, end).trim();
}

const historyMethods = ['previewEntryBase', 'previewOwnsHistoryEntry', 'retireStalePreviewMarker', 'syncPreviewHistory', 'onPreviewPopState', 'startPreviewHistory', 'openPreview', 'closePreview']
    .map((name) => method(details, name)).concat(method(strip, 'replaceMemberContext')).join(',\n');

const playwrightPath = process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH;
const browserTest = playwrightPath ? test : test.skip;

async function setup(browser, {boot}) {
    const page = await browser.newPage();
    const errors = [], visits = [];
    page.on('pageerror', (error) => errors.push(error.message));
    await page.route('http://history.test/**', (route) => {
        const request = route.request(), pathname = new URL(request.url()).pathname;
        if (pathname === '/navigation.js') return route.fulfill({contentType: 'text/javascript', body: read('packages/Mothership/Admin/src/Resources/assets/js/plugins/navigation.js')});
        if (pathname === '/constants') return route.fulfill({contentType: 'text/javascript', body: read('packages/Mothership/Admin/src/Resources/assets/js/constants.js')});
        if (request.headers()['x-ajax-nav']) visits.push(pathname + new URL(request.url()).search);
        return route.fulfill({contentType: 'text/html', body: '<div id="app"><p>Destination page</p></div>'});
    });
    await page.goto('http://history.test/admin/dam/assets/edit/2');
    await page.addScriptTag({path: path.join(root, 'packages/Mothership/DAM/node_modules/vue/dist/vue.global.js')});
    await page.addScriptTag({path: path.join(root, 'packages/Mothership/DAM/src/Resources/assets/js/details-navigation.js')});
    await page.evaluate(async ({methods, boot}) => {
        history.replaceState({ajaxNav: true}, '', '/admin/previous');
        // unrelated metadata on the details entry must survive every preview open/close
        history.pushState({ajaxNav: true, keep: 'metadata'}, '', '/admin/dam/assets/edit/2');
        const noop = () => {};
        window.mountDetails = () => {
            if (location.pathname !== '/admin/dam/assets/edit/2') return;
            // The production Files tab is the same page with ?files: a different tab component mounts, not details.
            if (new URLSearchParams(location.search).has('files')) {
                window.detailsApp = Vue.createApp({template: '<p id="files-tab">Files tab</p>'});
                window.detailsApp.mount('#app');
                return;
            }
            const app = Vue.createApp({
                template: '<div style="min-height:4000px"><div style="position:absolute;top:600px"><button id="open" @click="openPreview">Preview</button><a id="files" href="/admin/dam/assets/edit/2?files&nav=1&file=5">Open Files tab</a></div>'
                    + '<div v-if="isOpen" data-backdrop><button id="close" @click="closePreview">Close</button></div></div>',
                data: () => ({isOpen: false, memberPending: false, memberUnavailable: false, previewData: {id: 2, variantFileId: null}}),
                methods: {
                    ...new Function(`return ({${methods}})`)(),
                    rememberPreviewOpener() { this._opener = document.activeElement; },
                    restorePreviewFocus() { this._opener?.focus?.({preventScroll: true}); },
                    rememberStackCover: noop, imgResetState: noop, videoResetState: noop, audioResetState: noop, panoResetState: noop,
                    loadInfoPanel: noop, focusPreview: noop, videoInitEl: noop, audioInitEl: noop, panoInit: noop, autoplayMedia: noop, loadTextPreview: noop,
                    resetTextPreview: noop, videoStopOnClose: noop, audioStopOnClose: noop, pdfDestroy: noop, panoDestroy: noop, restoreStackCover: noop,
                },
                mounted() { this.startPreviewHistory(); },
                beforeUnmount() { this._unregisterPreviewHistory?.(); },
            });
            window.detailsApp = app;
            window.state = app.mount('#app');
            window.mounts = (window.mounts || 0) + 1;
        };
        window.app = {unmount() { window.detailsApp?.unmount(); }};
        window.createAdminApp = () => { window.app = {mount: window.mountDetails, unmount() { window.detailsApp?.unmount(); }}; };
        // Hard loads mount before the router initialises; AJAX remounts after it.
        window.app.mount = window.mountDetails;
        if (boot === 'hard') window.mountDetails();
        (await import('/navigation.js')).default();
        if (boot === 'ajax') window.mountDetails();
        window.backCalls = 0;
        const nativeBack = history.back.bind(history);
        history.back = () => { window.backCalls++; nativeBack(); };
        window.addEventListener('keydown', (event) => { if (event.key === 'Escape' && window.state.isOpen) window.state.closePreview(); });
    }, {methods: historyMethods, boot});

    return {page, errors, visits};
}

const closeBy = async (page, how) => {
    if (how === 'button') await page.locator('#close').click();
    else if (how === 'Escape') await page.keyboard.press('Escape');
    else await page.goBack();
};

browserTest('details preview keeps history ownership across member URL replacement, remount and rapid reopen (real router)', async () => {
    const {launchBrowser} = require('../launch-browser.cjs');
    const browser = await launchBrowser({playwrightPath});
    try {
        for (const boot of ['hard', 'ajax']) {
            // 1. Picking another stacked file rewrites ?file= on the owned entry; close/Escape/Back stay local.
            for (const how of ['button', 'Escape', 'Back']) {
                const {page, errors, visits} = await setup(browser, {boot});
                const label = `${boot}/member/${how}`;
                await page.evaluate(() => window.scrollTo(0, 300));
                const length = await page.evaluate(() => history.length);
                await page.locator('#open').click();
                await page.waitForFunction(() => history.state?.damPreview === true);
                assert.equal(await page.evaluate(() => history.state.keep), 'metadata', `${label}: open keeps unrelated metadata`);
                await page.evaluate(() => { window.state.previewData.variantFileId = 5; window.state.replaceMemberContext(); });
                assert.match(await page.evaluate(() => location.search), /file=5/);
                await closeBy(page, how);
                await page.waitForFunction(() => !window.state.isOpen && !history.state?.damPreview);
                await page.waitForTimeout(500);
                assert.deepEqual(visits, [], `${label}: close never reaches the router`);
                assert.equal(await page.evaluate(() => window.backCalls), how === 'Back' ? 0 : 1, `${label}: exactly one owned Back`);
                assert.equal(await page.evaluate(() => history.length), length + 1, `${label}: the entry is consumed, not duplicated`);
                assert.equal(await page.evaluate(() => history.state.keep), 'metadata', `${label}: metadata retained after close`);
                assert.match(await page.evaluate(() => location.search), /file=5/, `${label}: selected file stays in the url`);
                assert.equal(await page.evaluate(() => window.scrollY), 300, `${label}: scroll preserved`);
                assert.equal(await page.evaluate(() => document.activeElement.id), 'open', `${label}: focus returns to the opener`);
                // Forward onto the closed entry, then Back again: both local.
                await page.goForward();
                await page.waitForTimeout(300);
                assert.equal(await page.evaluate(() => window.state.isOpen), false, `${label}: Forward keeps the preview closed`);
                await page.goBack();
                await page.waitForTimeout(300);
                assert.deepEqual(visits, [], `${label}: Forward/Back over the closed entry never reach the router`);
                assert.equal(await page.evaluate(() => window.mounts), 1, `${label}: page never remounted`);
                assert.deepEqual(errors, []);
                await page.close();
            }

            // 2. Open, follow a page link, Back remounts on the abandoned marked entry: it is normalised, and a
            //    reopened preview closes locally.
            {
                const {page, errors, visits} = await setup(browser, {boot});
                const label = `${boot}/remount`;
                await page.locator('#open').click();
                await page.waitForFunction(() => history.state?.damPreview === true);
                await page.locator('#files').evaluate((link) => link.click());
                await page.waitForFunction(() => new URLSearchParams(location.search).has('files') && document.querySelector('#files-tab'));
                assert.deepEqual(visits, ['/admin/dam/assets/edit/2?files&nav=1&file=5'], `${label}: the query-only Files tab visit reaches the router`);
                assert.equal(await page.evaluate(() => location.pathname), '/admin/dam/assets/edit/2', `${label}: same pathname as the details page`);
                await page.goBack();
                await page.waitForFunction(() => !new URLSearchParams(location.search).has('files') && window.mounts === 2);
                const state = await page.evaluate(() => ({marker: history.state?.damPreview, owner: history.state?.damPreviewOwner, keep: history.state?.keep}));
                assert.deepEqual(state, {marker: undefined, owner: undefined, keep: 'metadata'}, `${label}: abandoned marker dropped, metadata kept`);
                const visitsBefore = visits.length;
                assert.equal(visits.length, 2, `${label}: tab visit then Back remount`);
                await page.evaluate(() => window.scrollTo(0, 250));
                await page.locator('#open').click();
                await page.waitForFunction(() => history.state?.damPreview === true);
                await page.goBack();
                await page.waitForFunction(() => !window.state.isOpen);
                await page.waitForTimeout(500);
                assert.equal(visits.length, visitsBefore, `${label}: Back after reopen closes locally, no router visit`);
                assert.equal(await page.evaluate(() => window.mounts), 2, `${label}: no extra remount`);
                assert.equal(await page.evaluate(() => window.scrollY), 250, `${label}: no scroll jump`);
                assert.deepEqual(errors, []);
                await page.close();
            }

            // 3. Reopen before the close's asynchronous Back lands: the reopened viewer survives and owns a fresh entry.
            {
                const {page, errors, visits} = await setup(browser, {boot});
                const label = `${boot}/rapid`;
                await page.locator('#open').click();
                await page.waitForFunction(() => history.state?.damPreview === true);
                const length = await page.evaluate(() => history.length);
                await page.evaluate(() => { window.state.closePreview(); window.state.openPreview(); });
                await page.waitForFunction(() => history.state?.damPreview === true && window.backCalls === 1);
                await page.waitForTimeout(500);
                assert.equal(await page.evaluate(() => window.state.isOpen), true, `${label}: queued Back does not close the reopened preview`);
                assert.equal(await page.evaluate(() => history.length), length, `${label}: exactly one live entry backs the reopened viewer`);
                await page.goBack();
                await page.waitForFunction(() => !window.state.isOpen);
                await page.waitForTimeout(300);
                assert.deepEqual(visits, [], `${label}: later Back closes locally`);
                // close, reopen, close again before the Back lands: ends closed with no stray entry
                await page.evaluate(() => { window.state.openPreview(); });
                await page.waitForFunction(() => history.state?.damPreview === true);
                await page.evaluate(() => { window.state.closePreview(); window.state.openPreview(); window.state.closePreview(); });
                await page.waitForFunction(() => !history.state?.damPreview);
                await page.waitForTimeout(300);
                assert.equal(await page.evaluate(() => window.state.isOpen), false);
                assert.deepEqual(visits, [], `${label}: close/reopen/close stays local`);
                assert.deepEqual(errors, []);
                await page.close();
            }

            // 4. A foreign preview entry (another owner's marker) is never claimed: Back onto it reaches the router.
            {
                const {page, visits} = await setup(browser, {boot});
                await page.evaluate(() => {
                    history.replaceState({ajaxNav: true, damPreview: true, damPreviewOwner: 999}, '', '/admin/dam/assets/edit/2');
                    history.pushState({ajaxNav: true}, '', '/admin/dam/assets/edit/2?other=1');
                });
                await page.goBack();
                await page.waitForFunction(() => window.mounts >= 1);
                await page.waitForTimeout(500);
                assert.deepEqual(visits, ['/admin/dam/assets/edit/2'], `${boot}/foreign: the router visits a foreign entry`);
                await page.close();
            }
        }
    } finally {
        await browser.close();
    }
});
