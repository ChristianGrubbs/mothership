'use strict';

// The Mothership installed to an iPhone Home Screen is one window with no download manager: following a file link replaced
// the whole app with a file preview that had no way back (operator report 2026-11-11). device-save.js takes a save over
// in that app only, fetches the file and hands it to the share sheet. The first half checks its rules without a DOM;
// the second runs the real file in Chromium made to look like the installed app (navigator.standalone, a touch screen,
// a recorded share sheet) against a tiny HTTP server, and once as an ordinary browser, which must be left alone.
const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');
const vm = require('node:vm');

const root = path.resolve(__dirname, '../../..');
const source = fs.readFileSync(path.join(root, 'packages/Mothership/DAM/src/Resources/assets/js/device-save.js'), 'utf8');
const playwrightPath = process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH;
const browserTest = playwrightPath ? test.bind(null) : test.skip;
const {installedApp} = require('./support/installed-app.cjs');

/** Runs the file against a stand-in window and reports what it attached. */
function load({standalone, touchPoints}) {
    const listeners = [];
    function HTMLAnchorElement() {}
    const nativeClick = HTMLAnchorElement.prototype.click = function click() {};
    const window = {
        navigator: {standalone, maxTouchPoints: touchPoints},
        location: {href: 'https://assets.example/admin/home', origin: 'https://assets.example'},
        addEventListener(type, listener, capture) { listeners.push({type, capture}); },
    };
    window.top = window;
    vm.runInNewContext(source, {window, HTMLAnchorElement, URL, setTimeout});

    return {api: window.mothershipDeviceSave, listeners, patched: HTMLAnchorElement.prototype.click !== nativeClick};
}
const link = (attributes) => ({
    hasAttribute: (name) => name in attributes,
    getAttribute: (name) => attributes[name] ?? null,
});

test('a browser with a download manager is left alone; only the installed iPhone or iPad app is taken over', () => {
    for (const [name, device] of Object.entries({
        'a desktop browser': {standalone: undefined, touchPoints: 0},
        'Safari on an iPhone': {standalone: false, touchPoints: 5},
        'a Mac Dock app': {standalone: true, touchPoints: 0},
    })) {
        const loaded = load(device);
        assert.equal(loaded.api.active, false, name);
        assert.deepEqual(loaded.listeners, [], `${name}: no click is listened for`);
        assert.equal(loaded.patched, false, `${name}: anchor clicks are untouched`);
    }
    const app = load({standalone: true, touchPoints: 5});
    assert.equal(app.api.active, true);
    assert.deepEqual(app.listeners, [{type: 'click', capture: true}]);
    assert.equal(app.patched, true);
});

test('only a download link this page may fetch is taken', () => {
    const {takes} = load({standalone: true, touchPoints: 5}).api.parts;
    assert.equal(takes(link({download: '', href: '/admin/home/tools/loop-maker/assets/upload.svg'})), true);
    assert.equal(takes(link({download: 'loop.wav', href: 'blob:https://assets.example/7f0c'})), true);
    assert.equal(takes(link({download: '', href: 'data:text/plain,notes'})), true);
    assert.equal(takes(link({download: '', href: '/file', target: '_self'})), true);
    assert.equal(takes(link({href: '/admin/dam/assets/edit/7'})), false, 'a plain link is a page, not a save');
    assert.equal(takes(link({download: '', href: 'https://elsewhere.example/logo.svg'})), false, 'another origin cannot be fetched with this session');
    assert.equal(takes(link({download: '', href: '/file', target: '_blank'})), false, 'a link that opens elsewhere is the browser\'s');
    assert.equal(takes(link({download: ''})), false);
    assert.equal(takes(null), false);
});

test('the file keeps the name the server gave it, then the name the page asked for, then the address', () => {
    const {chooseName, nameFromDisposition, cleanName} = load({standalone: true, touchPoints: 5}).api.parts;
    assert.equal(nameFromDisposition('attachment; filename="menu.zip"'), 'menu.zip');
    assert.equal(nameFromDisposition('attachment; filename=plain.png'), 'plain.png');
    assert.equal(nameFromDisposition('attachment; filename="fall.zip"; filename*=UTF-8\'\'Men%C3%BA%20oto%C3%B1o.zip'), 'Menú otoño.zip');
    assert.equal(nameFromDisposition('inline'), '');
    assert.equal(chooseName({disposition: 'attachment; filename="server.zip"', asked: 'Press kit', url: '/admin/dam/download/9'}), 'server.zip');
    assert.equal(chooseName({disposition: null, asked: 'brand-logo.svg', url: '/x/y.svg'}), 'brand-logo.svg');
    assert.equal(chooseName({disposition: null, asked: '', url: 'https://assets.example/files/Campus%20map.pdf?dl=1'}), 'Campus map.pdf');
    assert.equal(chooseName({disposition: null, asked: '', url: 'blob:https://assets.example/7f0c'}), 'download');
    assert.equal(cleanName('../../etc/passwd'), 'etc-passwd');
});

test('a failed answer gets the sentence that says what to do', () => {
    const {failure, percent} = load({standalone: true, touchPoints: 5}).api.parts;
    assert.equal(failure({status: 200, redirected: false, type: 'image/png'}), '');
    assert.equal(failure({status: 200, redirected: true, type: 'text/html; charset=UTF-8'}), 'signedOut', 'a redirect that ends on a page is the sign-in page');
    assert.equal(failure({status: 200, redirected: true, type: 'image/png'}), '', 'a redirect to the file itself is fine');
    assert.equal(failure({status: 401}), 'signedOut');
    assert.equal(failure({status: 419}), 'signedOut');
    assert.equal(failure({status: 403}), 'denied');
    assert.equal(failure({status: 404}), 'gone');
    assert.equal(failure({status: 500}), 'failed');
    assert.equal(percent(50, 200), 25);
    assert.equal(percent(300, 200), 99, 'a compressed answer never reads as finished early');
    assert.equal(percent(50, 0), null);
});

// ---- In a browser -------------------------------------------------------------------------------------------------

const PAGE = `<!doctype html><html><head><meta charset="utf-8"><title>Tool</title><script>${source}</script></head><body>
    <a id="logo" href="/files/logo.svg" download>SVG</a>
    <a id="named" href="/files/plain" download="asked-name.png"><span id="named-inner">PNG</span></a>
    <a id="signed-out" href="/expired" download>expired</a>
    <a id="page" href="/other-page">a page</a>
    <button id="blob" type="button">Export</button>
    <script>
        document.getElementById('blob').addEventListener('click', () => {
            const anchor = document.createElement('a');
            anchor.href = URL.createObjectURL(new Blob(['RIFF'], {type: 'audio/wav'}));
            anchor.download = 'made-up-loop.wav';
            anchor.click();
        });
    </script></body></html>`;

async function startServer() {
    const requests = [];
    const server = http.createServer((req, res) => {
        const url = new URL(req.url, 'http://x');
        if (url.pathname !== '/' && url.pathname !== '/favicon.ico') requests.push(url.pathname);
        if (url.pathname === '/') {
            res.writeHead(200, {'Content-Type': 'text/html'});
            res.end(PAGE);
        } else if (url.pathname === '/files/logo.svg') {
            res.writeHead(200, {'Content-Type': 'image/svg+xml', 'Content-Disposition': 'attachment; filename="brand-logo.svg"'});
            res.end('<svg xmlns="http://www.w3.org/2000/svg"/>');
        } else if (url.pathname === '/files/plain') {
            res.writeHead(200, {'Content-Type': 'image/png'});
            res.end('png bytes');
        } else if (url.pathname === '/expired') {
            res.writeHead(302, {Location: '/login'});
            res.end();
        } else if (url.pathname === '/login' || url.pathname === '/other-page') {
            res.writeHead(200, {'Content-Type': 'text/html; charset=UTF-8'});
            res.end('<!doctype html><title>Page</title><p id="arrived">page</p>');
        } else {
            res.writeHead(404);
            res.end();
        }
    });
    await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));

    return {
        requests,
        origin: `http://127.0.0.1:${server.address().port}`,
        close: () => new Promise((resolve) => { server.closeAllConnections?.(); server.close(resolve); }),
    };
}

async function withPage({installed}, run) {
    const server = await startServer();
    const browser = await require('../launch-browser.cjs').launchBrowser({playwrightPath});
    try {
        // The Duo's installed app lays the page out about 385 points wide.
        const context = await browser.newContext({viewport: {width: 385, height: 678}, acceptDownloads: true});
        if (installed) await context.addInitScript(installedApp);
        const page = await context.newPage();
        await page.goto(server.origin + '/');
        await page.evaluate(() => { window.__stayed = true; });
        await run({page, server});
    } finally {
        await browser.close();
        await server.close();
    }
}
const card = (page) => page.locator('[data-device-save]');
const shares = (page) => page.evaluate(() => window.__shares);
const stayed = async (page, server) => {
    assert.equal(page.url(), server.origin + '/', 'the app is still on the page it was on');
    assert.equal(await page.evaluate(() => window.__stayed), true, 'and it is the same document, not a reload');
};

browserTest('in the installed app a download link goes to the share sheet and the app stays where it is', async () => {
    await withPage({installed: true}, async ({page, server}) => {
        await page.click('#logo');
        await page.waitForFunction(() => window.__shares.length === 1);
        assert.deepEqual(await shares(page), [{name: 'brand-logo.svg', type: 'image/svg+xml', body: '<svg xmlns="http://www.w3.org/2000/svg"/>', refused: null}]);
        assert.deepEqual(server.requests, ['/files/logo.svg'], 'fetched once, never navigated to');
        await stayed(page, server);
        await card(page).locator('.status').filter({hasText: 'Done.'}).waitFor();
        assert.equal(await card(page).locator('.name').textContent(), 'brand-logo.svg');
        assert.equal(await card(page).locator('.action').isVisible(), false);

        // A click on something inside the link, and the name the page asked for when the server gives none.
        await page.click('#named-inner');
        await page.waitForFunction(() => window.__shares.length === 2);
        assert.deepEqual((await shares(page))[1], {name: 'asked-name.png', type: 'image/png', body: 'png bytes', refused: null});
        assert.equal(await card(page).count(), 1, 'one card: a new save replaces the last');
        await stayed(page, server);

        // A plain link is still a page.
        await page.click('#page');
        await page.locator('#arrived').waitFor();
    });
});

browserTest('when the share sheet needs a tap, the card offers Save, and closing the sheet keeps it on offer', async () => {
    await withPage({installed: true}, async ({page, server}) => {
        // A script that builds an anchor it never adds to the page (Loop Maker) after work that outlived the tap.
        await page.evaluate(() => { window.__refusals = ['NotAllowedError', 'AbortError']; });
        await page.click('#blob');
        const action = card(page).locator('.action');
        await action.waitFor();
        assert.equal(await action.textContent(), 'Save to this device');
        assert.equal(await card(page).locator('.status').textContent(), 'Ready. Tap Save to choose where it goes.');
        assert.equal(await card(page).locator('.name').textContent(), 'made-up-loop.wav');
        const box = await action.boundingBox();
        assert.ok(box.height >= 44, `the Save button is a ${box.height}px touch target`);
        assert.ok(box.x >= 0 && box.x + box.width <= 385, 'the card fits the phone');

        // The sheet opens, the person closes it without choosing: nothing was saved and the card says so.
        await action.click();
        await card(page).locator('.status').filter({hasText: 'Not saved yet. Tap Save to try again.'}).waitFor();

        await action.click();
        await card(page).locator('.status').filter({hasText: 'Done.'}).waitFor();
        const calls = await shares(page);
        assert.deepEqual(calls.map((call) => call.refused), ['NotAllowedError', 'AbortError', null]);
        assert.deepEqual(calls[2], {name: 'made-up-loop.wav', type: 'audio/wav', body: 'RIFF', refused: null});
        await stayed(page, server);
    });
});

browserTest('a signed-out session and a device that cannot share say so in words; Close removes the card', async () => {
    await withPage({installed: true}, async ({page, server}) => {
        await page.click('#signed-out');
        const alert = card(page).locator('[role="alert"]');
        await alert.filter({hasText: 'You have been signed out. Reload The Mothership and sign in again.'}).waitFor();
        assert.deepEqual(await shares(page), [], 'the sign-in page is never offered as the file');
        assert.equal(await card(page).locator('.action').isVisible(), false);
        await stayed(page, server);

        await page.evaluate(() => { navigator.canShare = () => false; });
        await page.click('#logo');
        await alert.filter({hasText: 'This iPhone or iPad cannot save a file from the Home Screen app. Open The Mothership in Safari to save it.'}).waitFor();

        await card(page).locator('.close').click();
        assert.equal(await card(page).count(), 0);
        await stayed(page, server);
    });
});

browserTest('a script-clicked anchor keeps its own click handlers, and a file the page built is held to the size ceiling', async () => {
    await withPage({installed: true}, async ({page, server}) => {
        let downloads = 0;
        page.on('download', () => { downloads += 1; });
        // One anchor that is never added to the page, with one handler of its own, clicked by script.
        const clickDetached = (mode) => page.evaluate((how) => {
            const anchor = Object.assign(document.createElement('a'), {
                href: URL.createObjectURL(new Blob(['RIFF'], {type: 'audio/wav'})), download: `made-up-${how}.wav`,
            });
            const calls = {ran: 0, threw: null, parent: null};
            anchor.addEventListener('click', (event) => {
                calls.ran += 1;
                if (how === 'cancel') event.preventDefault();
                if (how === 'stop') event.stopImmediatePropagation();
                if (how === 'remove') anchor.remove();
                if (how === 'move') document.body.appendChild(anchor);
            }, how === 'stop');
            try { anchor.click(); } catch (error) { calls.threw = error.name; }
            calls.parent = anchor.parentNode ? anchor.parentNode.nodeName : null;
            anchor.remove();

            return calls;
        }, mode);

        // A handler that cancels the click stops the save: no card, nothing shared.
        assert.deepEqual(await clickDetached('cancel'), {ran: 1, threw: null, parent: null});
        assert.equal(await card(page).count(), 0);
        assert.deepEqual(await shares(page), []);

        // A handler that tidies up, stops the event, removes the anchor or puts it in the page runs once, does not
        // make click() throw, and the file is saved each time; where the handler left the anchor is respected.
        for (const [mode, parent] of [['tidy', null], ['stop', null], ['remove', null], ['move', 'BODY']]) {
            assert.deepEqual(await clickDetached(mode), {ran: 1, threw: null, parent}, mode);
            await page.waitForFunction((name) => window.__shares.some((call) => call.name === name), `made-up-${mode}.wav`);
        }
        assert.deepEqual((await shares(page)).map((call) => call.name),
            ['made-up-tidy.wav', 'made-up-stop.wav', 'made-up-remove.wav', 'made-up-move.wav']);
        assert.equal(downloads, 0, 'no click got through as a download');

        // A Blob over the ceiling is refused before it is copied into a File, as a fetched file of that size is.
        await page.evaluate(() => {
            class Huge extends Blob { get size() { return 501 * 1024 * 1024; } }
            window.mothershipDeviceSave.save({blob: new Huge(['x'], {type: 'video/webm'}), name: 'made-up-export.webm'});
        });
        const alert = card(page).locator('[role="alert"]');
        await alert.filter({hasText: 'This file is too large to save from the Home Screen app. Open The Mothership in Safari to save it.'}).waitFor();
        assert.equal((await shares(page)).length, 4, 'the oversized file never reached the share sheet');
        assert.equal(await card(page).locator('.action').isVisible(), false);
        await stayed(page, server);
    });
});

browserTest('an ordinary browser downloads as it always has: no card, no fetch by the page', async () => {
    await withPage({installed: false}, async ({page, server}) => {
        assert.equal(await page.evaluate(() => window.mothershipDeviceSave.active), false);
        const download = page.waitForEvent('download');
        download.catch(() => {});
        await page.click('#logo');
        assert.equal((await download).suggestedFilename(), 'brand-logo.svg');
        assert.equal(await card(page).count(), 0);
        const blob = page.waitForEvent('download');
        blob.catch(() => {});
        await page.click('#blob');
        assert.equal((await blob).suggestedFilename(), 'made-up-loop.wav');
        assert.equal(await card(page).count(), 0);
    });
});
