'use strict';

// "Open details" from the stack fan and from the Library preview, against the REAL ajax navigation plugin
// (navigation.js, loaded with its one import inlined). Ajax navigation takes every admin <a href> click in the
// capture phase, before a component's own handler runs, so a placeholder link (href="#") turned into a Library
// reload and swallowed the details visit, and a link whose handler also assigned location.href loaded twice.
const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const {pathToFileURL} = require('node:url');

const playwrightPath = process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH;
const browserTest = playwrightPath ? test : test.skip;
const root = path.resolve(__dirname, '../../..');
const adminJs = path.join(root, 'packages/Mothership/Admin/src/Resources/assets/js');
const constants = fs.readFileSync(path.join(adminJs, 'constants.js'), 'utf8').replace(/export const/g, 'const');
const navigation = fs.readFileSync(path.join(adminJs, 'plugins/navigation.js'), 'utf8')
    .replace(/^import .*$/m, constants)
    .replace('export default function initAjaxNavigation', 'window.initAjaxNavigation = function initAjaxNavigation')
    .concat('\n;window.initAjaxNavigation();');

const ORIGIN = 'http://nav.test';
const LIBRARY = `${ORIGIN}/admin/dam/assets`;
const DETAILS = '/admin/dam/assets/edit/20';

const pageHtml = (body, title) => `<!doctype html><html><head><title>${title}</title><meta name="admin-url" content="admin"></head><body><div id="app">${body}</div></body></html>`;

async function open(browser, libraryBody) {
    const page = await browser.newPage();
    const requests = [];
    await page.route(`${ORIGIN}/**`, (route) => {
        const url = new URL(route.request().url());
        requests.push(url.pathname + url.search);
        const isDetails = url.pathname === DETAILS;
        route.fulfill({status: 200, contentType: 'text/html', body: pageHtml(isDetails ? '<h1 id="details">Details</h1>' : libraryBody, isDetails ? 'Details' : 'Library')});
    });
    await page.goto(LIBRARY);
    requests.length = 0;
    await page.evaluate(() => { window.createAdminApp = () => { window.app = {unmount() {}, mount() {}}; }; });
    await page.addScriptTag({content: navigation});
    return {page, requests};
}

async function withBrowser(run) {
    const {chromium} = require(playwrightPath);
    const helper = process.env.MOTHERSHIP_CHROME_LAUNCHER_PATH || path.join(os.homedir(), 'ai-stack/tools/chrome-launch.mjs');
    const browser = process.env.MOTHERSHIP_CHROME_LAUNCHER_PATH || process.platform === 'darwin' || fs.existsSync(helper)
        ? await (await import(pathToFileURL(helper).href)).launchChrome({chromium})
        : await chromium.launch({headless: true, channel: process.env.MOTHERSHIP_BROWSER_CHANNEL || 'chrome'});
    try { await run(browser); } finally { await browser.close(); }
}

// The fan's header control as the Library renders it, with edit() reduced to its navigation: $navigate -> mothership.visit.
const fan = (control) => `<div class="fan">${control}</div><script>window.openDetails = () => window.mothership.visit('${DETAILS}?nav=1&directory_id=all');</script>`;

browserTest('a placeholder link (href="#") is not an ajax navigation, so its own handler can open the details page', () => withBrowser(async (browser) => {
    const {page, requests} = await open(browser, '<a href="#" id="fan-details">Open details</a>');
    await page.evaluate(() => document.querySelector('#fan-details').addEventListener('click', (event) => { event.preventDefault(); window.mothership.visit('/admin/dam/assets/edit/20?nav=1&directory_id=all'); }));
    await page.click('#fan-details');
    await page.waitForSelector('#details');
    assert.deepEqual(requests, [`${DETAILS}?nav=1&directory_id=all`], 'the Library was reloaded in place of the details visit');
    assert.equal(new URL(page.url()).pathname, DETAILS);
}));

browserTest('the fan\'s Open details button reaches the details page with one visit', () => withBrowser(async (browser) => {
    const {page, requests} = await open(browser, fan('<button type="button" id="fan-details" onclick="openDetails()">Open details</button>'));
    await page.click('#fan-details');
    await page.waitForSelector('#details');
    assert.deepEqual(requests, [`${DETAILS}?nav=1&directory_id=all`]);
    assert.equal(new URL(page.url()).search, '?nav=1&directory_id=all');
}));

browserTest('the lightbox\'s Open details link loads the details page once, with one history entry, after storing the order', () => withBrowser(async (browser) => {
    const href = `${DETAILS}?nav=1&directory_id=all&file=1691`;
    const {page, requests} = await open(browser, `<a href="${href}" id="open-details" data-dam-open-details>Open details</a>`);
    // The component's click handler: store the visible order, nothing else (no preventDefault, no location change).
    await page.evaluate(() => document.querySelector('#open-details').addEventListener('click', () => sessionStorage.setItem('order', '[10,20,30]')));
    const before = await page.evaluate(() => history.length);
    await page.click('#open-details');
    await page.waitForSelector('#details');
    assert.deepEqual(requests, [href]);
    assert.equal(await page.evaluate(() => history.length), before + 1);
    assert.equal(await page.evaluate(() => sessionStorage.getItem('order')), '[10,20,30]', 'the order was stored before the visit');
    assert.equal(new URL(page.url()).searchParams.get('file'), '1691');
    await page.goBack();
    await page.waitForFunction(() => location.pathname === '/admin/dam/assets');
}));

browserTest('an ordinary admin link still navigates by ajax', () => withBrowser(async (browser) => {
    const {page, requests} = await open(browser, `<a href="${DETAILS}" id="plain">Details</a>`);
    await page.click('#plain');
    await page.waitForSelector('#details');
    assert.deepEqual(requests, [DETAILS]);
}));
