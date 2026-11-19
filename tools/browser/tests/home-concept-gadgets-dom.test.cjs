const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');

const playwrightPath = process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH;
const browserTest = playwrightPath ? test : test.skip;
const widgetsRoot = path.resolve(__dirname, '../../../packages/Mothership/DAM/src/Resources/widgets');

function waveFixture({ seconds = 2, sampleRate = 44_100, amplitude = 12_000 } = {}) {
    const samples = seconds * sampleRate;
    const buffer = Buffer.alloc(44 + samples * 2);
    buffer.write('RIFF', 0);
    buffer.writeUInt32LE(buffer.length - 8, 4);
    buffer.write('WAVEfmt ', 8);
    buffer.writeUInt32LE(16, 16);
    buffer.writeUInt16LE(1, 20);
    buffer.writeUInt16LE(1, 22);
    buffer.writeUInt32LE(sampleRate, 24);
    buffer.writeUInt32LE(sampleRate * 2, 28);
    buffer.writeUInt16LE(2, 32);
    buffer.writeUInt16LE(16, 34);
    buffer.write('data', 36);
    buffer.writeUInt32LE(samples * 2, 40);
    for (let index = 0; index < samples; index++) {
        buffer.writeInt16LE(Math.round(Math.sin(index / sampleRate * 2 * Math.PI * 220) * amplitude), 44 + index * 2);
    }
    return buffer;
}

async function fixtureServer() {
    const server = http.createServer((request, response) => {
        const requested = decodeURIComponent(new URL(request.url, 'http://fixture').pathname).replace(/^\/+/, '');
        const file = path.resolve(widgetsRoot, requested);
        if (!file.startsWith(widgetsRoot + path.sep) || !fs.existsSync(file) || !fs.statSync(file).isFile()) {
            response.writeHead(404).end('Not found');
            return;
        }
        const mime = { '.css': 'text/css', '.js': 'application/javascript', '.svg': 'image/svg+xml', '.png': 'image/png' }[path.extname(file)] || 'text/html';
        response.writeHead(200, { 'Content-Type': mime });
        fs.createReadStream(file).pipe(response);
    });
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    return {
        base: `http://127.0.0.1:${server.address().port}`,
        close: () => new Promise(resolve => server.close(resolve)),
    };
}

browserTest('Loop Maker loads audio, exposes seam controls, and exports a real WAV', async () => {
    const { chromium } = require(playwrightPath);
    const server = await fixtureServer();
    const browser = await chromium.launch({ headless: true, channel: process.env.MOTHERSHIP_BROWSER_CHANNEL || 'chrome' });
    try {
        const page = await browser.newPage({ acceptDownloads: true });
        await page.goto(`${server.base}/loop-maker/index.html`);
        await page.getByRole('button', { name: 'Load a demo track' }).waitFor();
        await page.locator('input[type="file"]').first().setInputFiles({ name: 'broken.mp3', mimeType: 'audio/mpeg', buffer: Buffer.from('not audio') });
        await page.getByRole('status').filter({ hasText: "Couldn't read that file" }).waitFor();
        await page.locator('input[type="file"]').first().setInputFiles({ name: 'fixture.wav', mimeType: 'audio/wav', buffer: waveFixture() });
        await page.locator('[data-editor]').waitFor();
        assert.equal(await page.locator('[data-drop-zone]').isHidden(), true);
        assert.match(await page.locator('[data-seam-score]').innerText(), /^\d+% · (clean|fair|rough)$/);
        assert.equal(await page.getByLabel('Smart seam').isChecked(), true);
        assert.equal(await page.getByRole('link', { name: 'LAME encoder' }).getAttribute('href'), 'https://lame.sourceforge.net/');
        // Opened from disk there is no bar, so the tool's own Export opens the sheet that holds the file's choices.
        await page.getByRole('button', { name: 'Export…' }).click();
        await page.getByLabel('File length').selectOption('fill');
        await page.getByLabel('Target duration').fill('not a duration');
        assert.match(await page.locator('[data-status]').innerText(), /seconds or m:ss/);
        // WAV is the default format: LAME's encoder delay and padding put an audible gap at the
        // wrap of a looped MP3, so the lossless option is the one a loop is exported with.
        assert.equal(await page.getByLabel('Format').inputValue(), 'wav');
        assert.equal(await page.getByRole('button', { name: 'Download WAV' }).isDisabled(), true);
        await page.getByLabel('Target duration').fill('0:02');
        assert.equal(await page.getByRole('button', { name: 'Download WAV' }).isEnabled(), true);
        await page.getByLabel('File length').selectOption('once');
        await page.locator('[data-start]').evaluate(element => { element.value = '1'; element.dispatchEvent(new Event('input', { bubbles: true })); });
        assert.equal(await page.locator('[data-length]').getAttribute('max'), '1');
        assert.equal(await page.getByLabel('Format').locator('option').allTextContents().then(values => values.includes('WAV · lossless')), true);
        await page.getByLabel('Format').selectOption('wav');
        const download = page.waitForEvent('download', { timeout: 3_000 });
        await page.getByRole('button', { name: 'Download WAV' }).click();
        const wavDownload = await download;
        assert.match(wavDownload.suggestedFilename(), /^fixture-loop\.wav$/);
        const wavBytes = fs.readFileSync(await wavDownload.path());
        assert.equal(wavBytes.subarray(0, 4).toString(), 'RIFF');
        assert.ok(wavBytes.length > 44_000);
        assert.ok(Math.abs(wavBytes.readInt16LE(44)) < 2_000, 'a zero crossing must not be clamped to full negative amplitude');
        await page.getByLabel('Format').selectOption('mp3-128');
        const mp3Download = page.waitForEvent('download', { timeout: 3_000 });
        await page.getByRole('button', { name: 'Download MP3' }).click();
        const encodedDownload = await mp3Download;
        assert.match(encodedDownload.suggestedFilename(), /^fixture-loop\.mp3$/);
        assert.ok(fs.statSync(await encodedDownload.path()).size > 1_000);
    } finally {
        await browser.close();
        await server.close();
    }
});

browserTest('Loop Maker preserves digital silence instead of introducing clipped samples', async () => {
    const { chromium } = require(playwrightPath);
    const server = await fixtureServer();
    const browser = await chromium.launch({ headless: true, channel: process.env.MOTHERSHIP_BROWSER_CHANNEL || 'chrome' });
    try {
        const page = await browser.newPage({ acceptDownloads: true });
        await page.goto(`${server.base}/loop-maker/index.html`);
        await page.locator('input[type="file"]').first().setInputFiles({ name: 'silence.wav', mimeType: 'audio/wav', buffer: waveFixture({ amplitude: 0 }) });
        await page.locator('[data-editor]').waitFor();
        await page.getByRole('button', { name: 'Export…' }).click();
        await page.getByLabel('Format').selectOption('wav');
        const pending = page.waitForEvent('download', { timeout: 3_000 });
        await page.getByRole('button', { name: 'Download WAV' }).click();
        const bytes = fs.readFileSync(await (await pending).path());
        assert.equal(bytes.readInt16LE(44), 0);
        assert.equal(bytes.readInt16LE(2_044), 0);
    } finally {
        await browser.close();
        await server.close();
    }
});

browserTest('Loop Maker stays usable at phone, tablet, and desktop widths', async () => {
    const { chromium } = require(playwrightPath);
    const server = await fixtureServer();
    const browser = await chromium.launch({ headless: true, channel: process.env.MOTHERSHIP_BROWSER_CHANNEL || 'chrome' });
    const viewports = [
        { name: 'phone', width: 390, height: 844 },
        { name: 'tablet', width: 820, height: 1_080 },
        { name: 'desktop', width: 1_280, height: 900 },
    ];
    try {
        for (const viewport of viewports) {
            const page = await browser.newPage({ viewport: { width: viewport.width, height: viewport.height } });
            await page.goto(`${server.base}/loop-maker/index.html`);
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, `Loop Maker must not overflow at ${viewport.name} width`);
            assert.ok((await page.locator('.choose-file').boundingBox()).height >= 44, `Loop Maker file target must be touch-sized at ${viewport.name} width`);
            await page.getByRole('button', { name: 'Load a demo track' }).click();
            await page.locator('[data-editor]').waitFor();
            for (const button of await page.locator('.transport-actions button').all()) {
                assert.ok((await button.boundingBox()).height >= 44, `Loop Maker transport target must be touch-sized at ${viewport.name} width`);
            }

            await page.close();
        }
    } finally {
        await browser.close();
        await server.close();
    }
});
