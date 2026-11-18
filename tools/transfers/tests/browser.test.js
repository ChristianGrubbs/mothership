import test from 'node:test';
import assert from 'node:assert/strict';
import http from 'node:http';
import { createHash, randomBytes } from 'node:crypto';
import { readFile, mkdir } from 'node:fs/promises';
import { homedir } from 'node:os';
import { pathToFileURL } from 'node:url';
import { createRequire } from 'node:module';
import { chromium } from 'playwright-core';
import { createTransferService } from '../server/service.js';
import { freshKey, grant, listen } from './helpers.js';
const require = createRequire(import.meta.url);
const sha = bytes => createHash('sha256').update(bytes).digest('hex');

test('two independent contexts accept, save and hash real PairDrop WebRTC files; decline/cancel/rooms/signout', { timeout: 150000 }, async t => {
    const key = freshKey(); let origin;
    const parent = http.createServer((req, res) => {
        const who = new URL(req.url, 'http://fixture').searchParams.get('user') || 'alice';
        if (req.url.startsWith('/grant')) {
            res.setHeader('Content-Type', 'application/json'); res.end(JSON.stringify({ grant: grant({ key, sub: who,
                name: who === 'alice' ? 'Alex Example' : 'Blair Example', sid: who }) })); return;
        }
        if (req.url === '/fixture.js') {
            res.setHeader('Content-Type', 'text/javascript');
            res.end(`const child = document.querySelector('iframe'); window.states=[]; window.offers=0; window.renewals=0; window.analytics=[];
                async function authenticate(type) {const data=await fetch('/grant'+location.search).then(r=>r.json());child.contentWindow.postMessage({channel:'mothership-transfers',version:1,type,grant:data.grant,selectedUserId:null},${JSON.stringify(origin)});}
                window.addEventListener('message',e=>{if(e.source!==child.contentWindow||e.origin!==${JSON.stringify(origin)})return;if(e.data.type==='analytics')window.analytics.push(e.data);if(e.data.type==='ready'||e.data.type==='reconnect')authenticate('initialize');if(e.data.type==='state')window.states.push(e.data);if(e.data.type==='incoming-offer'){window.offers++;child.style.display='block';}});
                window.disconnectChild=()=>child.contentWindow.postMessage({channel:'mothership-transfers',version:1,type:'disconnect'},${JSON.stringify(origin)});
                window.refreshRoster=()=>authenticate('authenticate');
                setInterval(()=>{window.renewals++;authenticate('authenticate');},30000);`); return;
        }
        res.setHeader('Content-Type', 'text/html'); res.end(`<!doctype html><html><head><style>body{margin:0}iframe{width:100%;height:100vh;border:0}</style></head><body><iframe title="Transfers" allow="clipboard-read; clipboard-write" src="${origin}"></iframe><script src="/fixture.js"></script></body></html>`);
    });
    const parentOrigin = await listen(parent);
    const reservation = http.createServer(); origin = await listen(reservation);
    await new Promise(resolve => reservation.close(resolve));
    const service = createTransferService({ key, origin, parentOrigin, replayFile: null });
    await new Promise(resolve => service.server.listen(Number(new URL(origin).port), '127.0.0.1', resolve));
    t.after(async () => { await service.close(); await new Promise(resolve => parent.close(resolve)); });
    const launcher = process.env.TRANSFERS_CHROME_LAUNCHER || `${homedir()}/ai-stack/tools/chrome-launch.mjs`;
    const { launchChrome } = await import(pathToFileURL(launcher));
    const browser = await launchChrome({ chromium, playwrightVersion: require('playwright-core/package.json').version });
    let originalClipboard; let clipboardPage;
    t.after(async () => {
        try {
            if (originalClipboard !== undefined && clipboardPage && !clipboardPage.isClosed()) {
                await clipboardPage.bringToFront();
                await clipboardPage.frames()[1].evaluate(text => window.__nativeClipboard.writeText(text), originalClipboard);
            }
        } finally { await browser.close(); }
    });
    const aContext = await browser.newContext({ acceptDownloads: true, viewport: { width: 1440, height: 900 } });
    const bContext = await browser.newContext({ acceptDownloads: true, viewport: { width: 375, height: 812 } });
    for (const context of [aContext, bContext]) await context.addInitScript(() => {
        window.__rtcConnections = []; window.__connectionDiagnostics = []; window.__rtcChannels = []; window.__webSockets = []; window.__nativeCandidateRejections = 0;
        window.__blockConnection = true;
        const trackChannel = channel => {
            window.__rtcChannels.push(channel);
            channel.addEventListener('message', event => {
                if (window.__closeOnBinary && typeof event.data !== 'string') { window.__closeOnBinary = false; channel.close(); }
            });
            return channel;
        };
        const NativeSocket = window.WebSocket;
        window.WebSocket = class extends NativeSocket { constructor(...args) { super(...args); window.__webSockets.push(this); } };
        const NativeConnection = window.RTCPeerConnection;
        window.RTCPeerConnection = class extends NativeConnection {
            constructor(configuration) {
                // Native RTC with no permitted route: relay-only and no relay server.
                super(window.__blockConnection ? { iceServers: [], iceTransportPolicy: 'relay' } : configuration);
                window.__rtcConnections.push(this); this.addEventListener('datachannel', event => trackChannel(event.channel));
            }
            createDataChannel(...args) { return trackChannel(super.createDataChannel(...args)); }
            addIceCandidate(candidate) { return super.addIceCandidate(candidate).catch(error => { window.__nativeCandidateRejections++; throw error; }); }
        };
        window.addEventListener('connection-diagnostic', event => window.__connectionDiagnostics.push(event.detail));
    });
    const a = await aContext.newPage(); const b = await bContext.newPage();
    const errors = []; for (const page of [a, b]) page.on('pageerror', error => errors.push(error.message));
    await Promise.all([a.goto(`${parentOrigin}/?user=alice`), b.goto(`${parentOrigin}/?user=bob`)]);
    const af = a.frameLocator('iframe'); const bf = b.frameLocator('iframe');
    for (const frame of [af, bf]) await frame.getByText('Could not connect · Retry', { exact: false }).waitFor({ timeout: 70000 });
    const attempts = await Promise.all([a, b].map(page => page.frames()[1].evaluate(() => window.__rtcConnections.length)));
    assert.equal(await af.locator('.transfer').count(), 0);
    assert.equal(await bf.locator('.transfer').count(), 0);
    for (let index = 0; index < 3; index++) {
        const stateCounts = await Promise.all([a, b].map(page => page.evaluate(() => window.states.length)));
        await Promise.all([a, b].map(page => page.evaluate(() => window.refreshRoster())));
        await Promise.all([a, b].map((page, i) => page.waitForFunction(count => window.states.length > count, stateCounts[i])));
    }
    for (const frame of [af, bf]) assert.match(await frame.locator('.peer small').textContent(), /Could not connect · Retry/);
    assert.deepEqual(await Promise.all([a, b].map(page => page.frames()[1].evaluate(() => window.__rtcConnections.length))), attempts);
    for (const page of [a, b]) await page.frames()[1].evaluate(() => { window.__blockConnection = false; });
    await af.locator('.peer').click();
    await af.locator('.peer[data-connection-state="ready"]').waitFor(); await bf.locator('.peer[data-connection-state="ready"]').waitFor();
    console.log(JSON.stringify({ event: 'blocked-rtc-manual-retry', stableFailure: true, attempts, recovered: true }));
    assert.equal(await af.locator('.peer strong').textContent(), 'Blair Example');
    await a.waitForFunction(() => window.states.at(-1)?.readyPeers.length === 1);
    await b.waitForFunction(() => window.states.at(-1)?.readyPeers.length === 1);
    const aDeviceLabel = await b.evaluate(() => window.states.at(-1).readyPeers[0].deviceName);
    assert.equal(await af.locator('#this-device').textContent(), `Signed in as Alex Example · ${aDeviceLabel}`);
    assert.equal(await af.locator('#connection').isVisible(), false);
    assert.equal(await af.locator('h1').evaluate(element => getComputedStyle(element).fontSize), '20px');
    await a.frames()[1].evaluate(() => {
        for (const connection of window.__rtcConnections) connection.onicecandidateerror?.({ errorCode: 701 });
    });
    assert.equal(await af.locator('.transfer').count(), 0);
    assert.equal(await af.locator('.peer[data-connection-state="ready"]').count(), 1);
    const remotePeerId = await a.evaluate(() => window.states.at(-1).readyPeers[0].peerId);
    await a.frames()[1].evaluate(to => window.__webSockets.find(socket => socket.readyState === WebSocket.OPEN).send(JSON.stringify({
        type: 'signal', to, ice: { candidate: 'candidate:1 1 udp 2122260223 192.0.2.1 54321 typ host', sdpMid: 'missing-media-section', sdpMLineIndex: 99 }
    })), remotePeerId);
    await b.frames()[1].waitForFunction(() => window.__connectionDiagnostics.some(item => item.source === 'ice-add'));
    assert.equal(await bf.locator('.transfer').count(), 0);
    assert.equal(await bf.locator('.peer[data-connection-state="ready"]').count(), 1);
    const nativeRejections = await b.frames()[1].evaluate(() => window.__nativeCandidateRejections);
    assert.ok(nativeRejections >= 1);
    console.log(JSON.stringify({ event: 'native-candidate-rejection', nativeRejections, diagnostic: 'ice-add', idlePhantomRows: 0, readyPeers: 1 }));
    await a.evaluate(origin => frames[0].postMessage({ channel: 'mothership-transfers', version: 1,
        type: 'select-peer', selectedPeerId: window.states.at(-1).readyPeers[0].peerId }, origin), origin);
    await af.locator('.peer[aria-pressed="true"]').waitFor();
    // A forged postMessage from the child itself must never disconnect it.
    await a.frames()[1].evaluate(() => window.postMessage({ channel: 'mothership-transfers', version: 1, type: 'disconnect' }, '*'));
    assert.match(await af.locator('#connection').textContent(), /Connected/);
    const bytes = randomBytes(2000137); const name = 'résumé 空間.bin';
    async function offer({ payload, name }) {
        await af.locator('.peer').click();
        await af.locator('#files').setInputFiles({ name, mimeType: 'application/octet-stream', buffer: payload });
        await af.locator('#send-files').click();
        await bf.getByRole('button', { name: 'Accept', exact: true }).waitFor();
    }
    // Reproduce Home's hidden mobile panel: receiving an offer reveals a short iframe.
    await b.locator('iframe').evaluate(frame => { frame.style.height = '520px'; frame.style.display = 'none'; });
    await offer({ payload: bytes, name });
    assert.equal(await b.evaluate(() => window.offers), 1);
    await b.frames()[1].waitForFunction(() => {
        const buttons = [...document.querySelectorAll('.transfer button')];
        return ['Accept', 'Decline'].every(label => {
            const rect = buttons.find(button => button.textContent === label)?.getBoundingClientRect();
            return rect && rect.top >= 0 && rect.bottom <= innerHeight;
        });
    });
    await a.waitForFunction(() => window.states.at(-1)?.activeCount === 1);
    await a.frames()[1].evaluate(() => {
        for (const connection of window.__rtcConnections) connection.onicecandidateerror?.({ errorCode: 701 });
    });
    assert.equal(await a.evaluate(() => window.states.at(-1).activeCount), 1);
    console.log(JSON.stringify({ event: 'browser-ice-regression', idlePhantomRows: 0, pendingActiveCount: 1,
        diagnostics: await a.frames()[1].evaluate(() => window.__connectionDiagnostics) }));
    await bf.getByRole('button', { name: 'Accept', exact: true }).click();
    await bf.getByRole('link', { name: `Save ${name}`, exact: true }).waitFor();
    const downloaded = b.waitForEvent('download'); await bf.getByRole('link', { name: `Save ${name}`, exact: true }).click();
    const download = await downloaded;
    assert.equal(download.suggestedFilename(), name);
    assert.equal(sha(await readFile(await download.path())), sha(bytes));
    await a.waitForFunction(() => window.states.at(-1)?.activeCount === 0);
    await b.waitForFunction(() => window.states.at(-1)?.activeCount === 0);
    const senderAnalytics = await a.evaluate(() => window.analytics);
    const receiverAnalytics = await b.evaluate(() => window.analytics);
    assert.deepEqual(senderAnalytics.map(row => row.event), ['attempted', 'acknowledged']);
    assert.deepEqual(receiverAnalytics.map(row => row.event), ['attempted', 'received']);
    assert.equal(senderAnalytics[0].props.transfer_id, senderAnalytics[1].props.transfer_id);
    assert.equal(receiverAnalytics[0].props.transfer_id, receiverAnalytics[1].props.transfer_id);
    assert.deepEqual(Object.keys(senderAnalytics[0].props).sort(), ['direction', 'files', 'transfer_id']);
    assert.equal(JSON.stringify([...senderAnalytics, ...receiverAnalytics]).includes(name), false);
    console.log(JSON.stringify({ event: 'browser-transfer-proof', bytes: bytes.length, sha256: sha(bytes), contexts: 2 }));
    await offer({ payload: Buffer.alloc(0), name: 'empty.txt' });
    await bf.getByRole('button', { name: 'Accept', exact: true }).click();
    const emptyDownload = b.waitForEvent('download'); await bf.getByRole('link', { name: 'Save empty.txt', exact: true }).click();
    assert.equal((await readFile(await (await emptyDownload).path())).length, 0);
    await af.locator('.peer[data-connection-state="ready"]').waitFor();
    await offer({ payload: Buffer.from('decline'), name: 'decline.txt' });
    await bf.getByRole('button', { name: 'Decline', exact: true }).click();
    await af.getByRole('heading', { name: 'Transfer declined' }).waitFor();
    assert.match(await af.locator('.transfer-person').textContent(), /Blair Example/);
    await offer({ payload: Buffer.from('cancel'), name: 'cancel.txt' });
    await af.getByRole('button', { name: 'Cancel', exact: true }).click();
    await bf.getByRole('heading', { name: 'Transfer cancelled' }).waitFor();
    await a.waitForFunction(() => window.analytics.at(-1)?.event === 'cancelled');
    await b.waitForFunction(() => window.analytics.at(-1)?.event === 'cancelled');
    assert.deepEqual(await a.evaluate(() => window.analytics.map(row => row.event)),
        ['attempted', 'acknowledged', 'attempted', 'acknowledged', 'attempted', 'declined', 'attempted', 'cancelled']);
    assert.deepEqual(await b.evaluate(() => window.analytics.map(row => row.event)),
        ['attempted', 'received', 'attempted', 'received', 'attempted', 'declined', 'attempted', 'cancelled']);
    await af.locator('.peer[data-connection-state="ready"]').waitFor();
    await af.locator('.peer').click(); await af.locator('#text-mode').click(); await af.locator('#message').fill('A link and a note: https://example.org/');
    await af.getByRole('button', { name: 'Send text', exact: true }).click();
    await bf.getByText('A link and a note: https://example.org/', { exact: true }).waitFor();
    // Native clipboard calls stay behind explicit clicks in the cross-origin iframe.
    await aContext.grantPermissions(['clipboard-read', 'clipboard-write']);
    await bContext.grantPermissions(['clipboard-read', 'clipboard-write']);
    const clipboardText = 'Résumé 空間 🧭\n<em>plain text</em>\n  Keep these spaces.  ';
    await a.bringToFront();
    clipboardPage = a;
    originalClipboard = await a.frames()[1].evaluate(() => {
        window.__nativeClipboard = navigator.clipboard;
        return navigator.clipboard.readText();
    });
    await a.frames()[1].evaluate(text => navigator.clipboard.writeText(text), clipboardText);
    await af.locator('.peer').click(); await af.locator('#text-mode').click();
    await af.getByRole('button', { name: 'Paste clipboard', exact: true }).click();
    await af.getByText('Pasted. Review your text before sending.', { exact: true }).waitFor();
    assert.equal(await af.locator('#message').inputValue(), clipboardText);
    const captureDir = process.env.TRANSFERS_SCREENSHOTS;
    if (captureDir) {
        await mkdir(captureDir, { recursive: true });
        await a.screenshot({ path: `${captureDir}/desktop-text-dialog.png` });
        await a.setViewportSize({ width: 375, height: 812 });
        await a.frames()[1].evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
        await a.screenshot({ path: `${captureDir}/mobile-text-dialog.png` });
        assert.equal(await af.locator('#send-dialog').evaluate(el => el.scrollWidth > el.clientWidth), false);
        await a.emulateMedia({ colorScheme: 'dark' });
        await a.screenshot({ path: `${captureDir}/mobile-text-dialog-dark.png` });
        await a.emulateMedia({ colorScheme: 'light' });
        await af.locator('#files-mode').click();
        await a.screenshot({ path: `${captureDir}/mobile-files-dialog.png` });
        await af.locator('#text-mode').click();
        await a.setViewportSize({ width: 1440, height: 900 });
    }
    await af.getByRole('button', { name: 'Send text', exact: true }).click();
    await bf.locator('.received-text').waitFor();
    await b.frames()[1].waitForFunction(text => document.querySelector('.received-text')?.textContent === text, clipboardText);
    assert.equal(await bf.locator('.received-text em').count(), 0);
    assert.match(await bf.locator('.transfer-person').textContent(), /Alex Example/);
    assert.match(await af.locator('.transfer-person').textContent(), /Blair Example/);
    await b.bringToFront();
    await bf.getByRole('button', { name: 'Copy text', exact: true }).click();
    await bf.getByText('Copied to clipboard.', { exact: true }).waitFor();
    assert.equal(await b.frames()[1].evaluate(() => navigator.clipboard.readText()), clipboardText);
    if (captureDir) {
        await b.locator('iframe').evaluate(frame => { frame.style.height = '100vh'; });
        await b.screenshot({ path: `${captureDir}/mobile-received-text.png` });
        await b.emulateMedia({ colorScheme: 'dark' });
        await b.screenshot({ path: `${captureDir}/mobile-received-text-dark.png` });
        await b.emulateMedia({ colorScheme: 'light' });
        await b.setViewportSize({ width: 1440, height: 900 });
        await b.frames()[1].evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
        await b.screenshot({ path: `${captureDir}/desktop-received-text.png` });
        await b.setViewportSize({ width: 375, height: 812 });
    }
    // Denied APIs are deterministic failure injection; native success above uses Chromium clipboard.
    await a.frames()[1].evaluate(() => Object.defineProperty(navigator, 'clipboard', { configurable: true,
        value: { readText: () => Promise.reject(new DOMException('Denied', 'NotAllowedError')) } }));
    await a.bringToFront(); await af.locator('.peer').click(); await af.locator('#text-mode').click();
    await af.getByRole('button', { name: 'Paste clipboard', exact: true }).click();
    await af.getByText('Clipboard unavailable. Paste into the text field manually.', { exact: true }).waitFor();
    await af.locator('#message').fill(clipboardText);
    await a.frames()[1].evaluate(() => Object.defineProperty(navigator, 'clipboard', { configurable: true,
        value: { readText: () => Promise.resolve('x'.repeat(10001)) } }));
    await af.getByRole('button', { name: 'Paste clipboard', exact: true }).click();
    await af.getByText('Clipboard text exceeds 10,000 characters. Paste a shorter selection manually.', { exact: true }).waitFor();
    assert.equal(await af.locator('#message').inputValue(), clipboardText);
    await af.getByRole('button', { name: 'Send text', exact: true }).click();
    await b.frames()[1].evaluate(() => Object.defineProperty(navigator, 'clipboard', { configurable: true,
        value: { writeText: () => Promise.reject(new DOMException('Denied', 'NotAllowedError')) } }));
    await b.bringToFront(); await bf.getByRole('button', { name: 'Copy text', exact: true }).click();
    await bf.getByText('Clipboard unavailable. Select the text above and copy it manually.', { exact: true }).waitFor();
    console.log(JSON.stringify({ event: 'clipboard-proof', nativePaste: true, nativeCopy: true, unicodeNewlinesExact: true,
        deniedFallback: true, verifiedIdentityLabels: true }));
    const revealsBefore = await b.evaluate(() => window.offers);
    for (let index = 0; index < 3; index++) {
        await af.locator('.peer').click(); await af.locator('#text-mode').click(); await af.locator('#message').fill(`Follow-up ${index}`);
        await af.getByRole('button', { name: 'Send text', exact: true }).click();
        await bf.getByText(`Follow-up ${index}`, { exact: true }).waitFor();
    }
    assert.ok((await b.evaluate(() => window.offers)) - revealsBefore <= 1);
    // A text reveal must not throttle a file offer after the recipient closes Home's panel.
    await b.locator('iframe').evaluate(frame => { frame.style.display = 'none'; });
    const beforeFileReveal = await b.evaluate(() => window.offers);
    await offer({ payload: Buffer.from('after message'), name: 'after-message.txt' });
    assert.equal(await b.evaluate(() => window.offers), beforeFileReveal + 1);
    assert.equal(await b.locator('iframe').isVisible(), true);
    await bf.getByRole('button', { name: 'Decline', exact: true }).click();
    await af.getByRole('heading', { name: 'Transfer declined' }).waitFor();
    assert.match(await af.locator('.transfer-person').textContent(), /Blair Example/);
    // Both authenticated contexts use the room code; no guest connection is created.
    await af.locator('summary').click(); await af.getByRole('button', { name: 'Create room', exact: true }).click();
    await a.waitForFunction(() => window.states.at(-1)?.readyPeers.length === 0);
    assert.match(await af.locator('.transfer-person').textContent(), /Blair Example/);
    const code = await af.locator('#room-code').textContent(); assert.match(code, /^\d{6}$/);
    await bf.locator('summary').click(); await bf.locator('#code').fill(code); await bf.getByRole('button', { name: 'Join', exact: true }).click();
    await af.locator('.peer[data-connection-state="ready"]').waitFor();
    await bf.locator('.peer[data-connection-state="ready"]').waitFor();
    const screenshotDir = process.env.TRANSFERS_SCREENSHOTS;
    if (screenshotDir) {
        await mkdir(screenshotDir, { recursive: true });
        await a.screenshot({ path: `${screenshotDir}/desktop.png`, fullPage: true });
        await b.screenshot({ path: `${screenshotDir}/mobile.png`, fullPage: true });
        await b.emulateMedia({ colorScheme: 'dark' });
        await b.screenshot({ path: `${screenshotDir}/mobile-dark.png`, fullPage: true });
    }
    assert.equal(await b.frames()[1].evaluate(() => document.documentElement.scrollWidth > innerWidth), false);
    await af.getByRole('button', { name: 'Disconnect', exact: true }).click();
    await b.waitForFunction(() => window.states.at(-1)?.readyPeers.length === 0);
    await a.waitForFunction(() => window.states.at(-1)?.status === 'disconnected');
    assert.equal(await af.locator('.transfer').count(), 0);
    await af.getByRole('button', { name: 'Reconnect', exact: true }).click();
    await a.waitForFunction(() => window.states.at(-1)?.status === 'ready');
    await af.getByRole('button', { name: 'Back to all staff' }).click(); await bf.getByRole('button', { name: 'Back to all staff' }).click();
    await af.locator('.peer[data-connection-state="ready"]').waitFor();
    assert.deepEqual(errors, []);
    // Close an actual data channel on its first binary chunk, after acceptance.
    await offer({ payload: randomBytes(4000137), name: 'interrupt.bin' });
    await b.frames()[1].evaluate(() => { window.__closeOnBinary = true; });
    await bf.getByRole('button', { name: 'Accept', exact: true }).click();
    await af.getByRole('heading', { name: 'Transfer interrupted', exact: true }).waitFor();
    await a.waitForFunction(() => window.states.at(-1)?.activeCount === 0 && window.states.at(-1)?.readyPeers.length === 0);
    await af.getByText('Disconnected · checking again…', { exact: false }).waitFor();
    assert.match(await af.locator('.transfer-person').textContent(), /Blair Example/);
    // Wait for the fixture host's actual 30-second authenticate interval. The service
    // verifies the new grant and broadcasts the roster; no manual message wakes recovery.
    const renewalsBefore = await a.evaluate(() => window.renewals);
    await af.locator('.peer[data-connection-state="ready"]').waitFor({ timeout: 70000 });
    await bf.locator('.peer[data-connection-state="ready"]').waitFor({ timeout: 70000 });
    const renewalsAfter = await a.evaluate(() => window.renewals);
    assert.ok(renewalsAfter > renewalsBefore);
    console.log(JSON.stringify({ event: 'native-channel-interruption', activeCount: 0,
        recoveredOnRoster: true, recovery: 'scheduled-authenticate', renewalsAfter }));
    for (const page of [a, b]) {
        const analytics = await page.evaluate(() => window.analytics);
        assert.equal(analytics.at(-1).event, 'failed');
        const ids = new Set(analytics.map(row => row.props.transfer_id));
        for (const id of ids) {
            const operation = analytics.filter(row => row.props.transfer_id === id);
            assert.equal(operation[0].event, 'attempted');
            assert.equal(operation.length, 2);
        }
    }
    // A second tab is the same browser/device, while a separate context remains a device.
    const a2 = await aContext.newPage();
    a2.on('pageerror', error => errors.push(error.message));
    await a2.route('**/config', async route => {
        await route.request().frame().evaluate(() => { window.__blockConnection = false; });
        await route.continue();
    });
    await a2.goto(`${parentOrigin}/?user=alice`);
    const a2f = a2.frameLocator('iframe');
    await a2f.locator('.peer[data-connection-state="ready"]').waitFor();
    assert.equal(await a2f.locator('.peer').count(), 1, 'same-browser tabs are not self devices');
    assert.equal(await bf.locator('.peer').count(), 1, 'remote duplicate tabs share one row');
    assert.equal(await b.evaluate(() => window.states.at(-1).readyPeers.length), 1);
    const labelBefore = await bf.locator('.peer small').textContent();
    const peerBefore = await bf.locator('.peer').getAttribute('data-peer-id');
    await b.evaluate(origin => frames[0].postMessage({ channel: 'mothership-transfers', version: 1,
        type: 'select-peer', selectedPeerId: window.states.at(-1).readyPeers[0].peerId }, origin), origin);
    await bf.locator('.peer').click();
    // Restore the native clipboard before closing the page that owns its saved handle.
    await a.bringToFront();
    await a.frames()[1].evaluate(text => window.__nativeClipboard.writeText(text), originalClipboard);
    originalClipboard = undefined;
    await a.close();
    await b.waitForFunction(old => window.states.at(-1)?.readyPeers.length === 1
        && window.states.at(-1).readyPeers[0].peerId !== old, peerBefore);
    await bf.locator(`.peer[data-connection-state="ready"]:not([data-peer-id="${peerBefore}"])`).waitFor();
    assert.equal(await bf.locator('.peer').getAttribute('aria-pressed'), 'true');
    assert.equal(await bf.locator('.peer small').textContent(), labelBefore);
    const tabBytes = randomBytes(65573);
    await bf.locator('#files').setInputFiles({ name: 'tab-failover.bin', mimeType: 'application/octet-stream', buffer: tabBytes });
    await bf.locator('#send-files').click();
    await a2f.getByRole('button', { name: 'Accept', exact: true }).click();
    await a2f.getByRole('link', { name: 'Save tab-failover.bin', exact: true }).waitFor();
    const tabDownload = a2.waitForEvent('download');
    await a2f.getByRole('link', { name: 'Save tab-failover.bin', exact: true }).click();
    assert.equal(sha(await readFile(await (await tabDownload).path())), sha(tabBytes));
    const thirdContext = await browser.newContext();
    const third = await thirdContext.newPage();
    await third.goto(`${parentOrigin}/?user=alice`);
    await b.waitForFunction(() => window.states.at(-1)?.readyPeers.length === 2);
    assert.equal(await bf.locator('.peer').count(), 2, 'same account on another browser stays separate');
    await thirdContext.close();
    console.log(JSON.stringify({ event: 'browser-tab-deduplication', rowsPerBrowser: 1, otherBrowserDistinct: true, failoverFileHash: sha(tabBytes) }));
    assert.deepEqual(errors, []);
});
