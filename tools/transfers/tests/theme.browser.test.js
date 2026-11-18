import test from 'node:test';
import assert from 'node:assert/strict';
import http from 'node:http';
import { homedir } from 'node:os';
import { pathToFileURL } from 'node:url';
import { createRequire } from 'node:module';
import { chromium } from 'playwright-core';
import { createTransferService } from '../server/service.js';
import { freshKey, listen } from './helpers.js';
const require = createRequire(import.meta.url);

test('pinned parent theme overrides OS preference; invalid enum and sibling source are ignored', { timeout: 30000 }, async t => {
    let origin;
    const parent = http.createServer((req, res) => {
        res.setHeader('Content-Type', 'text/html');
        res.end(req.url === '/sibling' ? '<!doctype html><title>Sibling</title>'
            : `<!doctype html><iframe title="Transfers" src="${origin}"></iframe><iframe title="Sibling" src="/sibling"></iframe>`);
    });
    const parentOrigin = await listen(parent);
    const reservation = http.createServer(); origin = await listen(reservation);
    await new Promise(resolve => reservation.close(resolve));
    const service = createTransferService({ key: freshKey(), origin, parentOrigin, replayFile: null });
    await new Promise(resolve => service.server.listen(Number(new URL(origin).port), '127.0.0.1', resolve));
    t.after(async () => { await service.close(); await new Promise(resolve => parent.close(resolve)); });
    const { launchChrome } = await import(pathToFileURL(process.env.TRANSFERS_CHROME_LAUNCHER || `${homedir()}/ai-stack/tools/chrome-launch.mjs`));
    const browser = await launchChrome({ chromium, playwrightVersion: require('playwright-core/package.json').version });
    t.after(() => browser.close());
    const page = await browser.newPage({ colorScheme: 'dark' });
    await page.goto(parentOrigin);
    const child = page.frames().find(frame => frame.url().startsWith(origin));
    await child.waitForFunction(() => document.getElementById('disconnect').onclick !== null);
    const appearance = () => child.evaluate(() => ({ theme: document.documentElement.dataset.theme,
        background: getComputedStyle(document.documentElement).backgroundColor,
        scheme: getComputedStyle(document.documentElement).colorScheme }));
    assert.equal((await appearance()).background, 'rgb(23, 25, 29)');
    async function send(theme) {
        await page.evaluate(({ origin, theme }) => frames[0].postMessage({ channel: 'mothership-transfers', version: 1, type: 'theme', theme }, origin), { origin, theme });
    }
    await send('light');
    await child.waitForFunction(() => document.documentElement.dataset.theme === 'light');
    assert.deepEqual(await appearance(), { theme: 'light', background: 'rgb(246, 247, 248)', scheme: 'light' });
    await page.emulateMedia({ colorScheme: 'light' });
    await send('dark');
    await child.waitForFunction(() => document.documentElement.dataset.theme === 'dark');
    assert.deepEqual(await appearance(), { theme: 'dark', background: 'rgb(23, 25, 29)', scheme: 'dark' });
    await send('invalid');
    const sibling = page.frames().find(frame => frame.url().endsWith('/sibling'));
    await sibling.evaluate(origin => parent.frames[0].postMessage({ channel: 'mothership-transfers', version: 1, type: 'theme', theme: 'light' }, origin), origin);
    // Allow posted message tasks and layout to run without another valid theme masking a rejection bug.
    await child.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
    assert.equal((await appearance()).theme, 'dark');
});
