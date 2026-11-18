'use strict';

// A DAM download is a plain browser navigation, so a large zip looked frozen until the server sent headers. The
// "Preparing download…" card (components/download/preparing.blade.php) shows the item at once, starts the download in a
// hidden iframe and waits for the dam_dl_<token> cookie the route sets with its headers. This runs the REAL partial
// (Blade stripped, the strings read from the English lang file) in Chromium against a tiny HTTP server that delays its
// headers, then sets the cookie and sends an attachment, or answers 422 JSON.
const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');

const root = path.resolve(__dirname, '../../..');
const views = path.join(root, 'packages/Mothership/DAM/src/Resources/views');
const partialPath = path.join(views, 'components/download/preparing.blade.php');
const playwrightPath = process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH;
const browserTest = playwrightPath ? test.bind(null) : test.skip;

const lang = fs.readFileSync(path.join(root, 'packages/Mothership/DAM/src/Resources/lang/en_US/app.php'), 'utf8');
const WORDS = Object.fromEntries(['preparing', 'started', 'failed', 'close'].map((key) => {
    const match = lang.match(new RegExp(`'download' => \\[[^\\]]*?'${key}'\\s*=>\\s*'([^']*)'`));
    assert.ok(match, `dam::app.admin.dam.download.${key} must exist in the English lang file`);

    return [`dam::app.admin.dam.download.${key}`, match[1]];
}));

/** The partial's <style> and <script>, as the browser gets them once Blade has run. */
function partialHtml() {
    return fs.readFileSync(partialPath, 'utf8')
        .replace(/\{\{--[\s\S]*?--\}\}/g, '')
        .replace(/@pushOnce\('scripts'\)|@endPushOnce/g, '')
        // The save helper for the installed iPhone app is inlined from its own file, as Blade does it.
        .replace(/\{!!\s*file_get_contents\(base_path\('([^']+)'\)\)\s*!!\}/g, (_, file) => fs.readFileSync(path.join(root, file), 'utf8'))
        .replace(/@js\(trans\('([^']+)'\)\)/g, (_, key) => {
            assert.ok(key in WORDS, `the partial reads an unknown string ${key}`);

            return JSON.stringify(WORDS[key]);
        });
}

/** The admin theme's built stylesheets: what the share pages (anonymous layout) load, so the card is styled for real. */
function shareCss() {
    const build = path.join(root, 'public/themes/admin/default/build');
    const manifest = JSON.parse(fs.readFileSync(path.join(build, 'manifest.json'), 'utf8'));
    const files = [...new Set(Object.values(manifest).flatMap((entry) => [entry.file, ...(entry.css || [])]).filter((file) => file.endsWith('.css')))];

    return files.map((file) => fs.readFileSync(path.join(build, file), 'utf8')).join('\n');
}

const THUMB = '<svg xmlns="http://www.w3.org/2000/svg" width="40" height="30"><rect width="40" height="30" fill="#36c"/></svg>';

/** A server that answers like the download routes: slow headers then the cookie, or a 422 JSON, or never (held). */
async function startServer() {
    const state = {requests: [], held: []};
    const server = http.createServer((req, res) => {
        const url = new URL(req.url, 'http://x');
        const token = url.searchParams.get('dl');
        if (url.pathname === '/') {
            res.setHeader('Content-Type', 'text/html');
            res.end(`<!doctype html><html><head><meta charset="utf-8"><title>Library</title></head><body>
                <a id="file" href="/file?files[]=1" data-dam-download data-dam-download-name="menu — 3 files.png" data-dam-download-thumb="/thumb.svg">file</a>
                <a id="bare" href="/file" data-dam-download data-dam-download-name="no-thumb.zip">bare</a>
                <a id="broken" href="/file" data-dam-download data-dam-download-name="broken.png" data-dam-download-thumb="/missing.png">broken</a>
                <a id="fail" href="/fail" data-dam-download data-dam-download-name="doomed.zip">fail</a>
                <a id="held" href="/held" data-dam-download data-dam-download-name="held.zip">held</a>
                <a id="plain" href="/file">plain</a>
                <a id="blank" href="/file" target="_blank" data-dam-download data-dam-download-name="blank.zip">blank</a>
                <a id="self" href="/file" target="_self" data-dam-download data-dam-download-name="self.zip">self</a>
                ${partialHtml()}</body></html>`);

            return;
        }
        if (url.pathname === '/app.css') {
            res.setHeader('Content-Type', 'text/css');
            res.end(shareCss());

            return;
        }
        if (url.pathname === '/share') {
            // The public share page's shape: the anonymous layout (dark class on <html>, the admin stylesheet) and a header
            // download link that also carries the plain `download` attribute, as the real one does.
            res.setHeader('Content-Type', 'text/html');
            res.end(`<!doctype html><html class="${url.searchParams.get('dark') ? 'dark' : ''}"><head><meta charset="utf-8"><title>Share</title>
                <link rel="stylesheet" href="/app.css"></head><body class="bg-gray-50 text-gray-800 dark:bg-cherry-900 dark:text-white antialiased"><div id="app">
                <a id="share-download" class="primary-button" href="/file" download data-dam-download data-dam-download-name="menu.png" data-dam-download-thumb="/thumb.svg">Download</a>
                <a id="share-zip" class="primary-button" href="/file" data-dam-download data-dam-download-name="Press kit" data-dam-download-folder>Download all (.zip)</a>
                </div>${partialHtml()}</body></html>`);

            return;
        }
        if (url.pathname === '/thumb.svg') {
            res.setHeader('Content-Type', 'image/svg+xml');
            res.end(THUMB);

            return;
        }
        if (url.pathname === '/favicon.ico') {
            res.writeHead(404);
            res.end();

            return;
        }
        state.requests.push(req.url);
        if (url.pathname === '/file') {
            const send = () => {
                res.writeHead(200, {
                    'Content-Type': 'application/zip',
                    'Content-Disposition': 'attachment; filename="menu.zip"',
                    'Set-Cookie': `dam_dl_${token}=1; Max-Age=120; Path=/; SameSite=Lax`,
                });
                res.end('zip bytes');
            };
            // A test that must see "Preparing" sets holdFile and releases the headers itself; a fixed delay races a loaded runner.
            if (state.holdFile) state.held.push(send); else setTimeout(send, 800);
        } else if (url.pathname === '/held') {
            state.held.push(() => {
                res.writeHead(200, {'Content-Type': 'application/zip', 'Content-Disposition': 'attachment; filename="held.zip"', 'Set-Cookie': `dam_dl_${token}=1; Max-Age=120; Path=/`});
                res.end('held bytes');
            });
        } else if (url.pathname === '/cookie-page') {
            // A download that reaches the frame as a document: the cookie says it started, so its load event is not an error.
            res.writeHead(200, {'Content-Type': 'text/html', 'Set-Cookie': `dam_dl_${token}=1; Max-Age=120; Path=/`});
            res.end('<!doctype html><title>Started</title>started');
        } else if (url.pathname === '/fail') {
            res.writeHead(422, {'Content-Type': 'application/json'});
            res.end(JSON.stringify({message: 'This folder is empty.'}));
        } else {
            res.writeHead(404);
            res.end('Not found');
        }
    });
    await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
    state.origin = `http://127.0.0.1:${server.address().port}`;
    state.close = () => new Promise((resolve) => { server.closeAllConnections?.(); server.close(resolve); });

    return state;
}

async function withPage(viewport, run, entry = '/', prepare = null) {
    const server = await startServer();
    const browser = await require('../launch-browser.cjs').launchBrowser({playwrightPath});
    try {
        const context = await browser.newContext({viewport, acceptDownloads: true});
        if (prepare) await prepare(context);
        const page = await context.newPage();
        await page.goto(server.origin + entry);
        await run({page, server});
    } finally {
        await browser.close();
        await server.close();
    }
}

const cards = (page) => page.locator('[data-dam-download-card]');

browserTest('the card shows the item at once, then "Download started" when the cookie arrives, then hides', async () => {
    await withPage({width: 1280, height: 800}, async ({page, server}) => {
        const download = page.waitForEvent('download');
        download.catch(() => {}); // a failed assertion below closes the browser; report that, not this
        // Time from the click to the card in the DOM, measured in the page.
        await page.evaluate(() => {
            window.__timing = {};
            document.addEventListener('click', () => { window.__timing.click = performance.now(); }, true);
            new MutationObserver(() => {
                const now = performance.now();
                const status = document.querySelector('[data-dam-dl-status]')?.textContent;
                if (window.__timing.card === undefined && document.querySelector('[data-dam-download-card]')) window.__timing.card = now;
                if (window.__timing.started === undefined && status === 'Download started') window.__timing.started = now;
                if (window.__timing.started !== undefined && window.__timing.removed === undefined && !document.querySelector('[data-dam-download-card]')) window.__timing.removed = now;
            }).observe(document.body, {childList: true, subtree: true, characterData: true});
        });
        server.holdFile = true;
        await page.click('#file');

        const timing = await page.evaluate(() => window.__timing);
        assert.ok(timing.card - timing.click < 100, `card appeared ${timing.card - timing.click}ms after the click`);
        assert.equal(await cards(page).count(), 1);
        assert.equal(await page.locator('[data-dam-dl-name]').textContent(), 'menu — 3 files.png');
        assert.equal(await page.locator('[data-dam-dl-name]').getAttribute('title'), 'menu — 3 files.png');
        assert.equal(await page.locator('[data-dam-dl-thumb] img').getAttribute('src'), '/thumb.svg');
        assert.match(await page.locator('[data-dam-dl-thumb] img').getAttribute('class'), /object-contain/);
        assert.equal((await page.locator('[data-dam-dl-thumb]').boundingBox()).width, 48);
        await page.waitForFunction(() => document.querySelector('[data-dam-dl-status]')?.textContent);
        assert.equal(await page.locator('[data-dam-dl-status]').textContent(), 'Preparing download…');
        const bar = page.locator('[role="progressbar"]');
        assert.equal(await bar.count(), 1);
        assert.equal(await bar.getAttribute('aria-valuenow'), null);

        // The server holds its headers until released: still preparing after 400ms, started once the cookie lands.
        await page.waitForTimeout(400);
        await page.waitForFunction(() => document.querySelector('[data-dam-dl-status]')?.textContent);
        assert.equal(await page.locator('[data-dam-dl-status]').textContent(), 'Preparing download…');
        for (const deadline = Date.now() + 5000; server.held.length !== 1 && Date.now() < deadline;) await new Promise((resolve) => setTimeout(resolve, 20));
        assert.equal(server.held.length, 1);
        server.held.shift()();
        await page.waitForFunction(() => document.querySelector('[data-dam-dl-status]')?.textContent === 'Download started');
        const saved = await download;
        assert.equal(saved.suggestedFilename(), 'menu.zip');

        // The download went through the hidden frame with one token, the link's own query kept, and the page never moved.
        assert.equal(server.requests.length, 1);
        const url = new URL(server.requests[0], 'http://x');
        assert.deepEqual(url.searchParams.getAll('files[]'), ['1']);
        assert.equal(url.searchParams.getAll('dl').length, 1);
        assert.match(url.searchParams.get('dl'), /^[A-Za-z0-9]{12,40}$/);
        assert.equal(page.url(), server.origin + '/');
        assert.equal(await page.locator('iframe[data-dam-dl-frame]').count(), 1);

        // The cookie is expired at once; the card goes about two seconds after "Download started".
        assert.doesNotMatch(await page.evaluate(() => document.cookie), /dam_dl_/);
        await page.waitForFunction(() => window.__timing.removed !== undefined, null, {timeout: 6000});
        const shown = await page.evaluate(() => window.__timing.removed - window.__timing.started);
        assert.ok(shown >= 1800 && shown <= 2600, `the card hid ${shown}ms after "Download started"`);
    });
});

browserTest('an error page shows the server message, stays until closed, and the page URL never changes', async () => {
    await withPage({width: 1280, height: 800}, async ({page, server}) => {
        let downloaded = false;
        page.on('download', () => { downloaded = true; });
        await page.click('#fail');

        const alert = page.locator('[data-dam-dl-alert]');
        await page.waitForFunction(() => document.querySelector('[data-dam-dl-alert]')?.textContent === 'This folder is empty.');
        assert.equal(await alert.getAttribute('role'), 'alert');
        assert.equal(await page.locator('[data-dam-dl-status]').textContent(), '', 'the status region is emptied, not turned into the alert');
        assert.equal(await page.locator('[data-dam-dl-status]').getAttribute('role'), 'status');
        assert.equal(await page.locator('[data-dam-dl-bar]').isVisible(), false);
        await page.waitForTimeout(2500);
        assert.equal(await cards(page).count(), 1, 'an error card stays until it is closed');
        assert.equal(page.url(), server.origin + '/');
        assert.equal(downloaded, false);

        await page.click('[data-dam-dl-close]');
        assert.equal(await cards(page).count(), 0);
        assert.equal(await page.locator('iframe[data-dam-dl-frame]').count(), 0, 'closing an error drops its frame');
    });
});

browserTest('no thumbnail, or one that fails to load, shows the neutral placeholder', async () => {
    await withPage({width: 1280, height: 800}, async ({page}) => {
        await page.click('#bare');
        assert.equal(await page.locator('[data-dam-dl-thumb] img').count(), 0);
        assert.equal(await page.locator('[data-dam-dl-thumb] svg').count(), 1);
        await page.click('#broken');
        await page.waitForFunction(() => document.querySelectorAll('[data-dam-dl-thumb] svg').length === 2);
        assert.equal(await page.locator('[data-dam-dl-thumb] img').count(), 0);
    });
});

browserTest('the indeterminate bar moves, and stays static under reduced motion', async () => {
    await withPage({width: 1280, height: 800}, async ({page}) => {
        await page.click('#held');
        const animation = () => page.evaluate(() => getComputedStyle(document.querySelector('.dam-dl-bar')).animationName);
        assert.equal(await animation(), 'dam-dl-slide');
        await page.emulateMedia({reducedMotion: 'reduce'});
        assert.equal(await animation(), 'none');
    });
});

browserTest('several downloads stack as separate cards, three visible at most', async () => {
    await withPage({width: 1280, height: 800}, async ({page, server}) => {
        for (let index = 0; index < 4; index++) await page.click('#held');
        assert.equal(await cards(page).count(), 4);
        const visible = () => cards(page).evaluateAll((nodes) => nodes.filter((node) => node.style.display !== 'none').length);
        assert.equal(await visible(), 3);

        // Each card has its own token, and releasing the held responses starts every download.
        await new Promise((resolve) => setTimeout(resolve, 200));
        const tokens = server.requests.map((request) => new URL(request, 'http://x').searchParams.get('dl'));
        assert.equal(new Set(tokens).size, 4);
        server.held.forEach((release) => release());
        await page.waitForFunction(() => !document.querySelector('[data-dam-download-card]'), null, {timeout: 6000});
    });
});

browserTest('a card started before its URL is known takes the download, or the error, in the same card', async () => {
    await withPage({width: 1280, height: 800}, async ({page, server}) => {
        await page.evaluate(() => {
            window.__created = 0;
            new MutationObserver((records) => {
                for (const record of records) for (const node of record.addedNodes) if (node.matches?.('[data-dam-download-card]')) window.__created++;
            }).observe(document.body, {childList: true, subtree: true});
            window.__dl = window.damDownload.start({name: 'Campaign', folder: true});
        });
        assert.equal(await cards(page).count(), 1);
        await page.waitForFunction(() => document.querySelector('[data-dam-dl-status]')?.textContent);
        assert.equal(await page.locator('[data-dam-dl-status]').textContent(), 'Preparing download…');
        assert.equal(server.requests.length, 0, 'nothing is requested until the URL is handed over');
        assert.equal(await page.locator('[data-dam-dl-thumb] svg').count(), 1);

        await page.evaluate(() => window.__dl.fail('Nothing to download here.'));
        assert.equal(await page.locator('[data-dam-dl-alert]').textContent(), 'Nothing to download here.');
        await page.click('[data-dam-dl-close]');

        const download = page.waitForEvent('download');
        await page.evaluate(() => { window.__dl = window.damDownload.start({name: 'Campaign', folder: true}); });
        await page.evaluate(() => window.__dl.go('/file'));
        await download;
        // The card of the first start was closed; the second start's card took the URL: two cards were ever made, no third.
        assert.equal(await page.evaluate(() => window.__created), 2);
    });
});

browserTest('a link without data-dam-download is left alone, and the card fits a 375px phone', async () => {
    await withPage({width: 375, height: 812}, async ({page, server}) => {
        const navigated = page.waitForEvent('download');
        await page.click('#plain');
        await navigated;
        assert.equal(await cards(page).count(), 0);

        await page.click('#held');
        const box = await cards(page).first().boundingBox();
        assert.ok(box.x >= 16 - 0.5 && box.x + box.width <= 375 - 16 + 0.5, `card spans ${box.x}..${box.x + box.width}`);
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), true);
        assert.equal(await page.evaluate(() => getComputedStyle(document.querySelector('[data-dam-download-host]')).zIndex), '10030');
        server.held.forEach((release) => release());
    });
});

browserTest('the admin AJAX navigation (a document capture-phase link handler) cannot take a download link before the card does', async () => {
    await withPage({width: 1280, height: 800}, async ({page, server}) => {
        // Mimics plugins/navigation.js: document + capture phase, preventDefault on every plain link click.
        await page.evaluate(() => {
            window.__nav = [];
            document.addEventListener('click', (event) => {
                const link = event.target instanceof Element ? event.target.closest('a[href]') : null;
                if (!link || event.defaultPrevented) return;
                event.preventDefault();
                window.__nav.push(link.getAttribute('href'));
            }, true);
        });
        const download = page.waitForEvent('download');
        download.catch(() => {});
        await page.click('#file');
        assert.equal(await cards(page).count(), 1, 'the card shows although a document-capture handler is registered');
        await download;
        assert.deepEqual(await page.evaluate(() => window.__nav), [], 'the navigation handler never saw the click');
        assert.equal(server.requests.length, 1);
        assert.equal(await page.locator('iframe[data-dam-dl-frame]').count(), 1);

        // A link without data-dam-download is still the navigation's to take.
        await page.click('#plain');
        assert.deepEqual(await page.evaluate(() => window.__nav), ['/file']);
    });
});

browserTest('the card sits above the viewers (10010) and their dialogs (10020), below the skip link and the flash group', async () => {
    await withPage({width: 1280, height: 800}, async ({page, server}) => {
        await page.click('#held');
        const z = Number(await page.evaluate(() => getComputedStyle(document.querySelector('[data-dam-download-host]')).zIndex));
        assert.ok(z > 10020 && z < 10050, `z-index ${z}`);
        server.held.forEach((release) => release());
    });
});

browserTest('ten minutes without a start fails the card visibly instead of hiding it', async () => {
    const server = await startServer();
    const browser = await require('../launch-browser.cjs').launchBrowser({playwrightPath});
    try {
        const context = await browser.newContext({viewport: {width: 1280, height: 800}});
        const page = await context.newPage();
        await page.clock.install();
        await page.goto(server.origin + '/');
        await page.click('#held');
        await page.waitForFunction(() => document.querySelector('[data-dam-dl-status]')?.textContent);
        assert.equal(await page.locator('[data-dam-dl-status]').textContent(), 'Preparing download…');
        await page.clock.runFor(10 * 60 * 1000 + 1000);
        assert.equal(await page.locator('[data-dam-dl-alert]').textContent(), 'Download failed');
        assert.equal(await cards(page).count(), 1, 'the failed card stays until it is closed');
        assert.equal(await page.locator('[data-dam-dl-alert]').getAttribute('role'), 'alert');
        await page.click('[data-dam-dl-close]');
        assert.equal(await cards(page).count(), 0);
    } finally {
        await browser.close();
        await server.close();
    }
});

browserTest('a frame load that already carries the start cookie is a started download, not a failure', async () => {
    await withPage({width: 1280, height: 800}, async ({page}) => {
        await page.evaluate(() => { window.__dl = window.damDownload.start({name: 'Report', folder: true}); });
        await page.evaluate(() => window.__dl.go('/cookie-page'));
        await page.waitForFunction(() => document.querySelector('[data-dam-dl-status]')?.textContent === 'Download started', null, {timeout: 3000});
        assert.equal(await page.locator('[data-dam-dl-alert]').textContent(), '');
    });
});

browserTest('a URL that cannot be built fails the card at once instead of leaving it preparing', async () => {
    await withPage({width: 1280, height: 800}, async ({page, server}) => {
        await page.evaluate(() => { window.__dl = window.damDownload.start({name: 'Report', folder: true}); });
        await page.evaluate(() => window.__dl.go('http://[bad'));
        assert.equal(await page.locator('[data-dam-dl-alert]').textContent(), 'Download failed');
        assert.equal(await page.locator('iframe[data-dam-dl-frame]').count(), 0, 'no frame was added');
        assert.equal(server.requests.length, 0);
    });
});

browserTest('an error card follows the upload panel when it appears or grows', async () => {
    await withPage({width: 1280, height: 800}, async ({page}) => {
        await page.evaluate(() => { window.__dl = window.damDownload.start({name: 'Campaign', folder: true}); });
        const bottom = () => page.evaluate(() => document.querySelector('[data-dam-download-host]').style.bottom);
        assert.equal(await bottom(), '1rem');
        await page.evaluate(() => {
            const panel = document.createElement('div');
            panel.setAttribute('data-dam-upload-panel', '');
            panel.style.cssText = 'position:fixed;bottom:1rem;right:1rem;width:300px;height:120px;';
            document.body.appendChild(panel);
            window.__dl.fail('Nothing to download here.');
        });
        assert.match(await bottom(), /120px/, 'fail() re-places the card at once, without waiting for a poll');
    });
});

browserTest('a modified click and a link that opens elsewhere are left to the browser: no card, no iframe', async () => {
    await withPage({width: 1280, height: 800}, async ({page}) => {
        await page.evaluate(() => {
            window.__prevented = [];
            window.addEventListener('click', (event) => { window.__prevented.push(event.target.id + ':' + event.defaultPrevented); });
        });
        // Ctrl/meta open a new tab and target=_blank opens one too; each click must pass straight through.
        // (A real Ctrl-click is a right-click on macOS, so the Ctrl click is dispatched; the Meta click is a real one.)
        await page.dispatchEvent('#file', 'click', {button: 0, ctrlKey: true, bubbles: true, cancelable: true});
        await page.click('#file', {modifiers: ['Meta']});
        await page.click('#blank');
        await page.waitForTimeout(300);
        assert.equal(await cards(page).count(), 0);
        assert.equal(await page.locator('iframe[data-dam-dl-frame]').count(), 0);
        assert.deepEqual(await page.evaluate(() => window.__prevented), ['file:false', 'file:false', 'blank:false']);

        // target=_self is an ordinary same-tab link: the card still takes it over.
        await page.click('#self');
        assert.equal(await cards(page).count(), 1);
        assert.equal(await page.locator('iframe[data-dam-dl-frame]').count(), 1);
    });
});

browserTest('a share page link shows the card styled for light and for dark, and the zip link has a folder name and no thumbnail', async () => {
    // One page per colour scheme; each link is clicked in turn and its newest card read.
    const cardsFor = async (entry, selectors) => {
        const found = [];
        await withPage({width: 1280, height: 800}, async ({page, server}) => {
            for (const [index, selector] of selectors.entries()) {
                const download = page.waitForEvent('download');
                download.catch(() => {});
                await page.click(selector);
                await page.locator('[data-dam-download-card]').nth(index).waitFor();
                await page.waitForFunction((position) => document.querySelectorAll('[data-dam-dl-status]')[position]?.textContent, index);
                found.push(await page.evaluate((position) => {
                    const node = document.querySelectorAll('[data-dam-download-card]')[position];
                    const style = getComputedStyle(node);

                    return {
                        name: node.querySelector('[data-dam-dl-name]').textContent,
                        status: node.querySelector('[data-dam-dl-status]').textContent,
                        image: Boolean(node.querySelector('img')),
                        folderIcon: Boolean(node.querySelector('[data-dam-dl-thumb] svg path[d^="M3 7a2"]')),
                        background: style.backgroundColor,
                        shadow: style.boxShadow,
                        radius: style.borderRadius,
                        nameColor: getComputedStyle(node.querySelector('[data-dam-dl-name]')).color,
                        barColor: getComputedStyle(node.querySelector('.dam-dl-bar')).backgroundColor,
                        url: location.href,
                    };
                }, index));
                const saved = await download;
                assert.equal(new URL(saved.url()).searchParams.get('dl')?.length, 16, 'the download carries the card token');
                await saved.path(); // let the download finish, so closing the browser is not held up by it
            }
            assert.equal(server.requests.filter((request) => request.startsWith('/file')).length, selectors.length, 'one download request per click');
        }, entry);

        return found;
    };

    const [light, zip] = await cardsFor('/share', ['#share-download', '#share-zip']);
    const [dark] = await cardsFor('/share?dark=1', ['#share-download']);
    assert.equal(light.name, 'menu.png');
    assert.equal(light.status, 'Preparing download…');
    assert.ok(light.image, 'the asset thumbnail shows');
    assert.equal(light.background, 'rgb(255, 255, 255)', 'light card is white');
    assert.notEqual(dark.background, light.background, 'dark card uses the dark surface');
    assert.notEqual(dark.nameColor, light.nameColor);
    assert.notEqual(light.shadow, 'none', 'the shadow utility exists in the share stylesheet');
    assert.notEqual(light.radius, '0px');
    assert.notEqual(light.barColor, 'rgba(0, 0, 0, 0)', 'the bar colour utility exists in the share stylesheet');
    assert.ok(light.url.endsWith('/share'), 'the page did not navigate');
    assert.equal(zip.name, 'Press kit');
    assert.equal(zip.image, false, 'a folder zip has no thumbnail, only the neutral icon');
    assert.equal(zip.folderIcon, true, 'a link marked data-dam-download-folder shows the folder icon');
    assert.equal(light.folderIcon, false, 'an asset link keeps the file icon');
});

browserTest('in the app installed to an iPhone Home Screen a download goes to the share sheet: no hidden frame, no navigation', async () => {
    // That app has no download manager, and a file navigation replaced the whole app with a preview that had no way
    // back (operator report 2026-11-11). device-save.js fetches the file and opens the share sheet from its own card.
    const {installedApp} = require('./support/installed-app.cjs');
    const asApp = (context) => context.addInitScript(installedApp);
    const shares = (page) => page.evaluate(() => window.__shares.map(({name, type}) => ({name, type})));
    await withPage({width: 385, height: 678}, async ({page, server}) => {
        await page.evaluate(() => { window.__stayed = true; });
        await page.click('#file');
        await page.waitForFunction(() => window.__shares.length === 1);
        assert.deepEqual(await shares(page), [{name: 'menu.zip', type: 'application/zip'}], 'the file carries the server\'s name');
        assert.equal(await page.locator('[data-dam-dl-frame]').count(), 0, 'nothing navigates, not even a hidden frame');
        assert.equal(await cards(page).count(), 0, 'the save card stands in for the preparing card');
        assert.equal(await page.locator('[data-device-save]').count(), 1);
        assert.equal(server.requests.length, 1);
        const url = new URL(server.requests[0], 'http://x');
        assert.equal(url.searchParams.get('files[]'), '1', 'the link\'s own query is kept');
        assert.equal(url.searchParams.get('dl'), null, 'no start cookie is asked for: the fetch itself reports progress');

        // A card started before its URL is known (a folder zip being checked) hands over the same way.
        await page.evaluate(() => { window.__handle = window.damDownload.start({name: 'Press kit', folder: true}); });
        assert.equal(await cards(page).count(), 1);
        await page.evaluate(() => window.__handle.go('/file'));
        await page.waitForFunction(() => window.__shares.length === 2);
        assert.equal(await cards(page).count(), 0);
        assert.equal(page.url(), server.origin + '/');
        assert.equal(await page.evaluate(() => window.__stayed), true, 'the app never left the page');
    }, '/', asApp);
    // A share page link carries both `download` and data-dam-download: it is saved once, not twice.
    await withPage({width: 385, height: 678}, async ({page, server}) => {
        await page.click('#share-download');
        await page.waitForFunction(() => window.__shares.length === 1);
        await page.locator('[data-device-save] .status').filter({hasText: 'Done.'}).waitFor();
        assert.deepEqual(await shares(page), [{name: 'menu.zip', type: 'application/zip'}]);
        // The share page's stylesheet asks this server for its fonts too; only the file matters here.
        assert.deepEqual(server.requests.filter((request) => request.startsWith('/file')), ['/file']);
        assert.equal(await cards(page).count(), 0);
    }, '/share', asApp);
});

test('the public share pages mark their download links and include the card', () => {
    const read = (file) => fs.readFileSync(path.join(views, file), 'utf8');
    const asset = read('share/public/asset.blade.php');
    const directory = read('share/public/directory.blade.php');

    // asset page: the header link carries the asset's name and share thumbnail; each file row (files-list partial) its own
    const files = read('share/public/partials/files-list.blade.php');
    assert.equal((asset.match(/data-dam-download-thumb="\{\{ \$thumbnailUrl \}\}"/g) || []).length, 1, 'the header link');
    assert.ok(asset.includes('data-dam-download-name="{{ $asset->file_name }}"'));
    assert.ok(files.includes('data-dam-download-name="{{ $row[\'name\'] }}"'));
    assert.ok(files.includes('data-dam-download-thumb="{{ $row[\'thumb_url\'] }}"'));
    // the video and audio players carry the thumbnail to their download menu
    assert.equal((asset.match(/download-thumb="\{\{ \$thumbnailUrl \}\}"/g) || []).length, 3, 'the header link and two players');
    assert.ok(read('share/components/media-player.blade.php').includes('downloadThumb:         this.downloadThumb'));
    // folder / collection page: the zip link has the folder name and no thumbnail
    assert.ok(directory.includes('data-dam-download-name="{{ $directory->name }}"'));
    assert.ok(directory.includes('data-dam-download-folder'), 'the folder zip link asks for the folder icon');
    assert.ok(fs.readFileSync(path.join(views, 'collections/show.blade.php'), 'utf8').includes('data-dam-download-folder'), 'the collection zip link asks for the folder icon');
    assert.ok(!/data-dam-download-thumb/.test(directory));
    for (const [name, source] of [['asset', asset], ['directory', directory]]) {
        assert.equal((source.match(/@include\('dam::components\.download\.preparing'\)/g) || []).length, 1, `${name} share page includes the card once`);
    }
});

test('every admin download trigger goes through the card', () => {
    const wired = {
        'asset/edit.blade.php': ['damDownload', 'data-dam-download'],
        'asset/preview-modal/card.blade.php': ['data-dam-download'],
        'asset/preview-modal/fallback.blade.php': ['data-dam-download'],
        'asset/preview-modal/viewer-modal.blade.php': ['data-dam-download'],
        'asset/preview-modal/image/image-viewer.blade.php': ['data-dam-download'],
        'asset/preview-modal/video/video-player.blade.php': ['data-dam-download'],
        'asset/preview-modal/audio/audio-player.blade.php': ['data-dam-download'],
        'asset/preview-modal/attachments/strip-script.blade.php': ['data-dam-download'],
        'components/explorer/tab.blade.php': ['damDownload'],
        'components/explorer/context-menu.blade.php': ['damDownload'],
        'components/tree/damdirectories.blade.php': ['damDownload'],
        'collections/show.blade.php': ['data-dam-download'],
        'asset/files/index.blade.php': ['data-dam-download'],
        'asset/generation.blade.php': ['data-dam-download'],
    };
    for (const [file, needles] of Object.entries(wired)) {
        const source = fs.readFileSync(path.join(views, file), 'utf8');
        for (const needle of needles) assert.ok(source.includes(needle), `${file} must go through ${needle}`);
    }
    for (const layout of [
        'packages/Mothership/Admin/src/Resources/views/components/layouts/index.blade.php',
        'packages/Mothership/DAM/src/Resources/views/components/layouts/with-history/asset.blade.php',
    ]) {
        assert.ok(fs.readFileSync(path.join(root, layout), 'utf8').includes("@include('dam::components.download.preparing')"), `${layout} must include the card once`);
    }
});
