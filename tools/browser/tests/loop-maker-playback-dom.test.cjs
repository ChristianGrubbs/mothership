const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');

const playwrightPath = process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH;
const browserTest = playwrightPath ? test : test.skip;
const widgetsRoot = path.resolve(__dirname, '../../../packages/Mothership/DAM/src/Resources/widgets');

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
    return { base: `http://127.0.0.1:${server.address().port}`, close: () => new Promise(resolve => server.close(resolve)) };
}

/**
 * Records how every AudioBufferSourceNode was actually started. The bug this guards is invisible
 * to the DOM: the widget used to restart a fresh source from an `onended` callback, which puts an
 * event loop between passes and drops a hole in the audio on every repeat.
 */
const recordSources = () => {
    window.__sources = [];
    const startOriginal = AudioBufferSourceNode.prototype.start;
    AudioBufferSourceNode.prototype.start = function start(when, offset) {
        const record = {
            loop: this.loop,
            loopStart: this.loopStart,
            loopEnd: this.loopEnd,
            offset: offset || 0,
            duration: this.buffer ? this.buffer.duration : 0,
            channels: this.buffer ? this.buffer.numberOfChannels : 0,
            startedAt: typeof when === 'number' ? when : 0,
            stopAt: null,
            stopped: false,
        };
        window.__sources.push(record);
        this.__record = record;
        return startOriginal.apply(this, arguments);
    };
    const stopOriginal = AudioBufferSourceNode.prototype.stop;
    // Captures the scheduled `when` a caller stops a source at, not just that it was stopped: the
    // seam audition schedules its own stop a fixed number of seconds after start, and asserting
    // that scheduled gap is the only way to check it without a multi-second real-time wait.
    AudioBufferSourceNode.prototype.stop = function stop(when) {
        if (this.__record) { this.__record.stopped = true; this.__record.stopAt = typeof when === 'number' ? when : this.__record.startedAt; }
        return stopOriginal.apply(this, arguments);
    };
};

const setRange = (page, selector, value) => page.locator(selector).evaluate((element, next) => {
    element.value = next;
    element.dispatchEvent(new Event('input', { bubbles: true }));
}, value);

const lastSource = page => page.evaluate(() => window.__sources[window.__sources.length - 1]);
const sourceCount = page => page.evaluate(() => window.__sources.length);
const waitForNewSource = (page, from) => page.waitForFunction(count => window.__sources.length > count, from, { timeout: 10_000 });

browserTest('Play loops natively, follows the controls, and auditions the real seam', async () => {
    const { chromium } = require(playwrightPath);
    const server = await fixtureServer();
    const browser = await chromium.launch({ headless: true, channel: process.env.MOTHERSHIP_BROWSER_CHANNEL || 'chrome' });
    try {
        const page = await browser.newPage();
        await page.addInitScript(recordSources);
        await page.goto(`${server.base}/loop-maker/index.html`);
        // The demo track is eight seconds of synthesized tone, so no fixture upload is needed and
        // the click supplies the user gesture an AudioContext wants before it will run.
        await page.getByRole('button', { name: 'Load a demo track' }).click();
        await page.locator('[data-editor]').waitFor();
        await setRange(page, '[data-length]', '2');
        await setRange(page, '[data-crossfade]', '0.5');

        await page.getByRole('button', { name: 'Play loop' }).click();
        await waitForNewSource(page, 0);
        const playing = await lastSource(page);
        assert.equal(playing.loop, true, 'Play must hand the wrap to the audio thread, not to an onended restart');
        assert.equal(playing.loopStart, 0);
        assert.ok(Math.abs(playing.loopEnd - playing.duration) < 1e-6, 'the loop region must span the whole built buffer');
        // Preview == export: the buffer under the preview is the built loop, whose period is the
        // region minus the crossfade overlap. Before the fix the preview played the raw file and
        // the Crossfade slider changed nothing audible.
        assert.ok(Math.abs(playing.duration - 1.5) < 0.02, `preview buffer ${playing.duration}s must be 2 s of loop minus a 0.5 s crossfade`);

        const clock = await page.locator('[data-clock]').innerText();
        await page.waitForFunction(text => document.querySelector('[data-clock]').textContent !== text, clock, { timeout: 10_000 });

        // Dragging a control mid-play used to hit the `duration === state.length` float compare in
        // onended and stop the loop dead.
        const beforeSwap = await sourceCount(page);
        await setRange(page, '[data-length]', '1.5');
        await waitForNewSource(page, beforeSwap);
        const swapped = await lastSource(page);
        assert.equal(swapped.loop, true);
        assert.equal(swapped.stopped, false, 'the replacement source must still be running');
        assert.ok(Math.abs(swapped.duration - 1) < 0.02, 'the rebuilt preview follows the new length and its clamped crossfade');
        assert.equal(await page.getByRole('button', { name: 'Stop' }).count(), 1, 'a control change must not stop playback');
        const afterSwap = await page.locator('[data-clock]').innerText();
        await page.waitForFunction(text => document.querySelector('[data-clock]').textContent !== text, afterSwap, { timeout: 10_000 });
        assert.equal(await page.getByRole('button', { name: 'Stop' }).count(), 1, 'playback keeps running past the swap');

        // Long enough that the audition window sits inside the loop rather than covering it.
        const beforeLength = await sourceCount(page);
        await setRange(page, '[data-length]', '5');
        await waitForNewSource(page, beforeLength);

        const beforeSeam = await sourceCount(page);
        await page.getByRole('button', { name: 'Hear the seam' }).click();
        await waitForNewSource(page, beforeSeam);
        const seam = await lastSource(page);
        assert.equal(seam.loop, true, 'the audition loops, which is how it reaches the head from the tail');
        assert.ok(Math.abs(seam.offset - (seam.duration - 1.5)) < 0.05, `the audition starts 1.5 s before the wrap, not at ${seam.offset}`);
        // It ends on its own and leaves the transport idle: proof the interval and the retired
        // source were both cleaned up rather than left racing the next press of Play.
        await page.getByRole('button', { name: 'Play loop' }).waitFor({ timeout: 15_000 });
        assert.equal(await page.getByRole('button', { name: 'Stop' }).count(), 0);
    } finally {
        await browser.close();
        await server.close();
    }
});

browserTest('the crossfade ceiling tracks the loop length, not a quarter of the whole file', async () => {
    const { chromium } = require(playwrightPath);
    const server = await fixtureServer();
    const browser = await chromium.launch({ headless: true, channel: process.env.MOTHERSHIP_BROWSER_CHANNEL || 'chrome' });
    try {
        const page = await browser.newPage();
        await page.goto(`${server.base}/loop-maker/index.html`);
        // The demo track is 8 s. A quarter of the whole file caps the slider at 2 s; the intended
        // ceiling is half the loop itself, which for a 6 s loop is 3 s.
        await page.getByRole('button', { name: 'Load a demo track' }).click();
        await page.locator('[data-editor]').waitFor();
        await setRange(page, '[data-length]', '6');
        const max = await page.locator('[data-crossfade]').evaluate(element => Number(element.max));
        assert.ok(Math.abs(max - 3) < 0.05, `a 6 s loop in an 8 s file must allow a 3 s crossfade, not ${max}`);
    } finally {
        await browser.close();
        await server.close();
    }
});

browserTest('Hear the seam always auditions three seconds, even when the loop period is far shorter', async () => {
    const { chromium } = require(playwrightPath);
    const server = await fixtureServer();
    const browser = await chromium.launch({ headless: true, channel: process.env.MOTHERSHIP_BROWSER_CHANNEL || 'chrome' });
    try {
        const page = await browser.newPage();
        await page.addInitScript(recordSources);
        await page.goto(`${server.base}/loop-maker/index.html`);
        await page.getByRole('button', { name: 'Load a demo track' }).click();
        await page.locator('[data-editor]').waitFor();
        // The shortest loop the sliders allow: length at its 0.25 s floor, no crossfade, for a
        // 0.25 s period. The old formula capped the audition at four periods (1 s here); the fix
        // is a flat three seconds regardless.
        await setRange(page, '[data-length]', '0.25');
        await setRange(page, '[data-crossfade]', '0');

        const beforeSeam = await sourceCount(page);
        await page.getByRole('button', { name: 'Hear the seam' }).click();
        await waitForNewSource(page, beforeSeam);
        const seam = await lastSource(page);
        assert.ok(Math.abs(seam.duration - 0.25) < 0.02, `the built loop's own period should be ~0.25 s, was ${seam.duration}`);
        assert.ok(seam.stopAt !== null, 'the seam source must have a scheduled stop');
        const scheduled = seam.stopAt - seam.startedAt;
        assert.ok(Math.abs(scheduled - 3) < 0.05, `the audition must be scheduled for three seconds regardless of the 0.25 s period, was ${scheduled}`);
    } finally {
        await browser.close();
        await server.close();
    }
});

browserTest('dragging the region\'s edge mid-play swaps the source rather than stopping playback', async () => {
    const { chromium } = require(playwrightPath);
    const server = await fixtureServer();
    const browser = await chromium.launch({ headless: true, channel: process.env.MOTHERSHIP_BROWSER_CHANNEL || 'chrome' });
    try {
        const page = await browser.newPage();
        await page.addInitScript(recordSources);
        await page.goto(`${server.base}/loop-maker/index.html`);
        await page.getByRole('button', { name: 'Load a demo track' }).click();
        await page.locator('[data-editor]').waitFor();
        await setRange(page, '[data-length]', '2');
        await setRange(page, '[data-crossfade]', '0.2');

        await page.getByRole('button', { name: 'Play loop' }).click();
        await waitForNewSource(page, 0);
        const beforeDrag = await lastSource(page);

        // Demo track duration is 8 s; the default start after loading it is 0.5 s, so the region's
        // end sits at (0.5 + 2) / 8 of the overlay's width.
        const box = await page.locator('[data-overlay]').boundingBox();
        const endX = box.x + ((0.5 + 2) / 8) * box.width, y = box.y + box.height / 2;
        const beforeDragCount = await sourceCount(page);
        await page.mouse.move(endX, y);
        await page.mouse.down();
        await page.mouse.move(endX - Math.min(120, box.width * 0.1), y, { steps: 6 });
        await page.mouse.up();
        await waitForNewSource(page, beforeDragCount);

        const afterDrag = await lastSource(page);
        assert.equal(afterDrag.loop, true, 'the swapped-in source must still be a native looping source');
        assert.equal(afterDrag.stopped, false, 'the replacement source must still be running');
        assert.ok(afterDrag.duration < beforeDrag.duration, `dragging the end edge inward must shorten the built loop (${beforeDrag.duration} -> ${afterDrag.duration})`);
        assert.equal(await page.getByRole('button', { name: 'Stop' }).count(), 1, 'an edge drag must not stop playback');
    } finally {
        await browser.close();
        await server.close();
    }
});

/**
 * Parses the exact 44-byte canonical PCM16 header wavBlob() writes (RIFF/WAVE/fmt /data, no extra
 * chunks) so the export's actual bytes can be checked, not just the AudioBuffer that fed it.
 */
function parseWavPcm16(bytes) {
    if (bytes.toString('ascii', 0, 4) !== 'RIFF' || bytes.toString('ascii', 8, 12) !== 'WAVE') throw new Error('not a RIFF/WAVE file');
    const channels = bytes.readUInt16LE(22);
    const dataSize = bytes.readUInt32LE(40);
    const frames = Math.floor(dataSize / (channels * 2));
    const perChannel = Array.from({ length: channels }, () => new Int16Array(frames));
    let offset = 44;
    for (let frame = 0; frame < frames; frame++) {
        for (let channel = 0; channel < channels; channel++) { perChannel[channel][frame] = bytes.readInt16LE(offset); offset += 2; }
    }
    return { channels, frames, perChannel };
}

browserTest('the exported WAV wraps from its last sample back into its first as smoothly as the audio itself', async () => {
    const { chromium } = require(playwrightPath);
    const server = await fixtureServer();
    const browser = await chromium.launch({ headless: true, channel: process.env.MOTHERSHIP_BROWSER_CHANNEL || 'chrome' });
    try {
        const page = await browser.newPage();
        await page.goto(`${server.base}/loop-maker/index.html`);
        await page.getByRole('button', { name: 'Load a demo track' }).click();
        await page.locator('[data-editor]').waitFor();
        await setRange(page, '[data-length]', '2');
        await setRange(page, '[data-crossfade]', '0.3');
        await page.getByRole('button', { name: 'Export…' }).click();

        const [download] = await Promise.all([
            page.waitForEvent('download'),
            page.getByRole('button', { name: 'Download WAV' }).click(),
        ]);
        const downloadPath = await download.path();
        assert.ok(downloadPath, 'the export must produce a downloadable file');
        const bytes = fs.readFileSync(downloadPath);
        const { perChannel } = parseWavPcm16(bytes);
        const values = Array.from(perChannel[0], value => value / 32768);
        let inside = 0;
        for (let index = 1; index < values.length; index++) inside = Math.max(inside, Math.abs(values[index] - values[index - 1]));
        const seam = Math.abs(values[0] - values[values.length - 1]);
        assert.ok(seam <= inside * 1.5 + 1e-3, `decoded WAV wrap step ${seam} must not exceed the file's own step of ${inside}`);
    } finally {
        await browser.close();
        await server.close();
    }
});
