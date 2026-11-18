import test from 'node:test';
import assert from 'node:assert/strict';
import http from 'node:http';
import { createHash, randomBytes } from 'node:crypto';
import { readFile } from 'node:fs/promises';
import { homedir } from 'node:os';
import { pathToFileURL } from 'node:url';
import { createRequire } from 'node:module';
import { chromium } from 'playwright-core';
import { createTransferService } from '../server/service.js';
import { freshKey, grant, listen } from './helpers.js';
const require = createRequire(import.meta.url);
const sha = bytes => createHash('sha256').update(bytes).digest('hex');

// Two real browsers that can never open a WebRTC route (the symmetric-NAT case: no path between two devices) must still
// exchange files and text, through the service, with the bytes intact.
test('two browsers with no direct route transfer files and text through the relay', { timeout: 120000 }, async t => {
    const key = freshKey(); let origin;
    const parent = http.createServer((req, res) => {
        const who = new URL(req.url, 'http://fixture').searchParams.get('user') || 'alice';
        if (req.url.startsWith('/grant')) {
            res.setHeader('Content-Type', 'application/json'); res.end(JSON.stringify({ grant: grant({ key, sub: who,
                name: who === 'alice' ? 'Alex Example' : 'Blair Example', sid: who }) })); return;
        }
        if (req.url === '/fixture.js') {
            res.setHeader('Content-Type', 'text/javascript');
            res.end(`const child = document.querySelector('iframe'); window.states=[];
                async function authenticate(type) {const data=await fetch('/grant'+location.search).then(r=>r.json());child.contentWindow.postMessage({channel:'mothership-transfers',version:1,type,grant:data.grant,selectedUserId:null},${JSON.stringify(origin)});}
                window.addEventListener('message',e=>{if(e.source!==child.contentWindow||e.origin!==${JSON.stringify(origin)})return;if(e.data.type==='ready'||e.data.type==='reconnect')authenticate('initialize');if(e.data.type==='state')window.states.push(e.data);});
                setInterval(()=>authenticate('authenticate'),30000);`); return;
        }
        res.setHeader('Content-Type', 'text/html'); res.end(`<!doctype html><html><head><style>body{margin:0}iframe{width:100%;height:100vh;border:0}</style></head><body><iframe title="Transfers" src="${origin}"></iframe><script src="/fixture.js"></script></body></html>`);
    });
    const parentOrigin = await listen(parent);
    const reservation = http.createServer(); origin = await listen(reservation);
    await new Promise(resolve => reservation.close(resolve));
    const service = createTransferService({ key, origin, parentOrigin, replayFile: null, relay: true, relayAfterMs: 1000 });
    await new Promise(resolve => service.server.listen(Number(new URL(origin).port), '127.0.0.1', resolve));
    t.after(async () => { await service.close(); await new Promise(resolve => parent.close(resolve)); });
    const launcher = process.env.TRANSFERS_CHROME_LAUNCHER || `${homedir()}/ai-stack/tools/chrome-launch.mjs`;
    const { launchChrome } = await import(pathToFileURL(launcher));
    const browser = await launchChrome({ chromium, playwrightVersion: require('playwright-core/package.json').version });
    t.after(() => browser.close());
    const aContext = await browser.newContext({ acceptDownloads: true, viewport: { width: 1440, height: 900 } });
    const bContext = await browser.newContext({ acceptDownloads: true, viewport: { width: 375, height: 812 } });
    for (const context of [aContext, bContext]) await context.addInitScript(() => {
        window.__openChannels = 0; window.__relayFrames = { sent: 0, received: 0 };
        const NativeSocket = window.WebSocket;
        window.WebSocket = class extends NativeSocket {
            constructor(...args) { super(...args); this.addEventListener('message', event => { if (typeof event.data !== 'string') window.__relayFrames.received++; }); }
            send(data) { if (typeof data !== 'string') window.__relayFrames.sent++; return super.send(data); }
        };
        const NativeConnection = window.RTCPeerConnection;
        window.RTCPeerConnection = class extends NativeConnection {
            // Native RTC with no permitted route: relay-only candidates and no TURN server to supply one.
            constructor() { super({ iceServers: [], iceTransportPolicy: 'relay' }); this.addEventListener('datachannel', event => event.channel.addEventListener('open', () => window.__openChannels++)); }
            createDataChannel(...args) { const channel = super.createDataChannel(...args); channel.addEventListener('open', () => window.__openChannels++); return channel; }
        };
    });
    const a = await aContext.newPage(); const b = await bContext.newPage();
    const errors = []; for (const page of [a, b]) page.on('pageerror', error => errors.push(error.message));
    await Promise.all([a.goto(`${parentOrigin}/?user=alice`), b.goto(`${parentOrigin}/?user=bob`)]);
    const af = a.frameLocator('iframe'); const bf = b.frameLocator('iframe');
    for (const frame of [af, bf]) {
        await frame.locator('.peer[data-connection-state="ready"][data-transport="relay"]').waitFor({ timeout: 20000 });
        assert.match(await frame.locator('.peer small').textContent(), /Ready · through The Mothership/);
    }
    for (const page of [a, b]) await page.waitForFunction(() => window.states.at(-1)?.readyPeers.length === 1);

    async function transfer({ from, to, toPage, fromPage, payload, name }) {
        await from.locator('.peer').click();
        await from.locator('#files').setInputFiles({ name, mimeType: 'application/octet-stream', buffer: payload });
        await from.locator('#send-files').click();
        await to.getByRole('button', { name: 'Accept', exact: true }).click();
        await to.getByRole('link', { name: `Save ${name}`, exact: true }).waitFor({ timeout: 60000 });
        const downloaded = toPage.waitForEvent('download'); await to.getByRole('link', { name: `Save ${name}`, exact: true }).click();
        const download = await downloaded;
        assert.equal(download.suggestedFilename(), name);
        assert.equal(sha(await readFile(await download.path())), sha(payload));
        await from.locator('.transfer h3', { hasText: 'Files sent' }).waitFor();
        for (const page of [fromPage, toPage]) await page.waitForFunction(() => window.states.at(-1)?.activeCount === 0);
    }
    // Five megabytes crosses several 1 MB partitions, so the acknowledgment round trip also runs through the relay.
    const large = randomBytes(5000137);
    await transfer({ from: af, to: bf, fromPage: a, toPage: b, payload: large, name: 'résumé 空間.bin' });
    const small = randomBytes(70001);
    await transfer({ from: bf, to: af, fromPage: b, toPage: a, payload: small, name: 'reply.bin' });
    await transfer({ from: af, to: bf, fromPage: a, toPage: b, payload: Buffer.alloc(0), name: 'empty.txt' });

    await af.locator('.peer').click();
    await af.locator('#text-mode').click();
    await af.locator('#message').fill('Größe ✓ through the relay');
    await af.locator('#send-text').click();
    await bf.locator('.received-text', { hasText: 'Größe ✓ through the relay' }).waitFor();

    // A declined offer and a cancelled transfer behave as they do on a direct route.
    await af.locator('.peer').click();
    await af.locator('#files').setInputFiles({ name: 'declined.bin', mimeType: 'application/octet-stream', buffer: small });
    await af.locator('#send-files').click();
    await bf.getByRole('button', { name: 'Decline', exact: true }).click();
    await af.locator('.transfer h3', { hasText: 'Transfer declined' }).waitFor();
    await af.locator('.peer:not([disabled])').waitFor();

    const frames = await Promise.all([a, b].map(page => page.frames()[1].evaluate(() => ({ channels: window.__openChannels, ...window.__relayFrames }))));
    assert.deepEqual(frames.map(row => row.channels), [0, 0], 'no WebRTC data channel ever opened');
    assert.ok(frames[0].sent > 80 && frames[1].received > 80, 'the file travelled as relay frames');
    assert.deepEqual(errors, []);
    console.log(JSON.stringify({ event: 'browser-relay-proof', bytes: large.length, sha256: sha(large), contexts: 2, frames }));
});
