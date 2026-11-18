const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { pathToFileURL } = require('node:url');

const playwrightPath = process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH;
const browserTest = playwrightPath ? test : test.skip;
// Opened straight from disk: the tool has to work without the platform, so the test loads it the same way.
const pageUrl = pathToFileURL(path.resolve(__dirname, '../../../packages/Mothership/DAM/src/Resources/widgets/sound-effects/index.html')).href;

const countSources = () => {
    window.__starts = 0;
    const start = AudioBufferSourceNode.prototype.start;
    AudioBufferSourceNode.prototype.start = function () { window.__starts++; window.__lastPlayed = this.buffer.getChannelData(0).slice(); return start.apply(this, arguments); };
};

// Reads a stored ZIP the way an unzip tool does: from the end record, through the central directory, to each entry.
function readZip(bytes) {
    const buf = Buffer.from(bytes);
    const end = buf.lastIndexOf(Buffer.from([0x50, 0x4b, 0x05, 0x06]));
    assert.ok(end >= 0, 'end of central directory record');
    const count = buf.readUInt16LE(end + 10), size = buf.readUInt32LE(end + 12);
    let at = buf.readUInt32LE(end + 16);
    assert.equal(at + size, end, 'central directory sits right before the end record');
    const entries = [];
    for (let i = 0; i < count; i++) {
        assert.equal(buf.readUInt32LE(at), 0x02014b50);
        const crc = buf.readUInt32LE(at + 16), length = buf.readUInt32LE(at + 24), nameLength = buf.readUInt16LE(at + 28), offset = buf.readUInt32LE(at + 42);
        const name = buf.toString('utf8', at + 46, at + 46 + nameLength);
        assert.equal(buf.readUInt32LE(offset), 0x04034b50);
        const dataStart = offset + 30 + buf.readUInt16LE(offset + 26) + buf.readUInt16LE(offset + 28);
        entries.push({ name, crc, data: buf.subarray(dataStart, dataStart + length) });
        at += 46 + nameLength;
    }
    return entries;
}

function checkWav(data, rate) {
    const buf = Buffer.from(data);
    assert.equal(buf.toString('ascii', 0, 4), 'RIFF');
    assert.equal(buf.readUInt32LE(4), buf.length - 8);
    assert.equal(buf.toString('ascii', 8, 16), 'WAVEfmt ');
    assert.equal(buf.readUInt16LE(20), 1, 'PCM');
    assert.equal(buf.readUInt16LE(22), 1, 'mono');
    assert.equal(buf.readUInt32LE(24), rate);
    assert.equal(buf.readUInt16LE(34), 16);
    assert.equal(buf.toString('ascii', 36, 40), 'data');
    assert.equal(buf.readUInt32LE(40), buf.length - 44);
}

browserTest('renders deterministically and writes valid WAV, ZIP and JSON', async () => {
    const { chromium } = require(playwrightPath);
    const browser = await chromium.launch({ headless: true, channel: process.env.MOTHERSHIP_BROWSER_CHANNEL || 'chrome' });
    try {
        const page = await browser.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        page.on('console', message => { if (message.type() === 'error') errors.push(message.text()); });
        await page.goto(pageUrl);
        const result = await page.evaluate(() => {
            const api = window.MothershipSoundEffects;
            const params = { wave: 'noise', pitch: 0.4, decay: 0.2, sustain: 0.3, release: 0.4, noise: 0.3, crush: 0.2, delay: 0.3, cutoff: 0.7, tremolo: 0.2, slide: -0.3, repeat: 0.3 };
            const a = api.renderSamples(params, 12345, 44100), b = api.renderSamples(params, 12345, 44100);
            const other = api.renderSamples(params, 12346, 44100), lower = api.renderSamples(params, 12345, 22050);
            const bytesA = new Uint8Array(a.buffer), bytesB = new Uint8Array(b.buffer);
            const same = bytesA.length === bytesB.length && bytesA.every((value, i) => value === bytesB[i]);
            const differs = a.some((value, i) => value !== other[i]);
            const buffer = api.renderBuffer(params, 12345, 44100).buffer;
            const fromBuffer = buffer.getChannelData(0);
            const library = [{ name: 'Coin', kind: 'coin', seed: 7, params: { wave: 'square', pitch: 0.6, jump: 0.3 } }, { name: 'Boom', kind: 'explosion', seed: 4294967295, params: params }];
            const exported = api.libraryJson(library, 44100);
            const roundTrip = api.libraryJson(api.parseLibrary(JSON.parse(JSON.stringify(exported))), 44100);
            let refusesNewer = false;
            try { api.parseLibrary({ ...exported, version: 99 }); } catch { refusesNewer = true; }
            const wav = api.encodeWav(a, 44100);
            const zip = api.makeZip([{ name: '01 Coin.wav', data: wav }, { name: '02 Bøøm.wav', data: api.encodeWav(lower, 22050) }]);
            return {
                same, differs, length: a.length, lowerLength: lower.length, bufferMatches: fromBuffer.every((value, i) => value === a[i]),
                exported: JSON.stringify(exported), roundTrip: JSON.stringify(roundTrip), refusesNewer, wav: Array.from(wav), zip: Array.from(zip),
                crcs: [api.crc32(wav), api.crc32(api.encodeWav(lower, 22050))],
            };
        });
        assert.ok(result.same, 'same parameters, seed and rate give byte-identical samples');
        assert.ok(result.differs, 'a different seed gives different noise');
        assert.ok(result.bufferMatches, 'the AudioBuffer holds exactly the rendered samples');
        assert.equal(result.lowerLength * 2, result.length + (result.length % 2));
        assert.equal(result.roundTrip, result.exported, 'JSON export survives an import unchanged');
        assert.ok(result.refusesNewer, 'a library from an unknown format version is refused');
        checkWav(result.wav, 44100);
        const entries = readZip(result.zip);
        assert.deepEqual(entries.map(e => e.name), ['01 Coin.wav', '02 Bøøm.wav']);
        entries.forEach((entry, i) => assert.equal(entry.crc, result.crcs[i]));
        checkWav(entries[0].data, 44100);
        checkWav(entries[1].data, 22050);
        assert.deepEqual(errors, []);
    } finally {
        await browser.close();
    }
});

browserTest('saves, keeps two sounds across a reload, downloads them, and keeps shortcuts out of fields', async () => {
    const { chromium } = require(playwrightPath);
    const browser = await chromium.launch({ headless: true, channel: process.env.MOTHERSHIP_BROWSER_CHANNEL || 'chrome' });
    const downloads = fs.mkdtempSync(path.join(os.tmpdir(), 'sound-effects-'));
    try {
        const context = await browser.newContext({ acceptDownloads: true, viewport: { width: 1440, height: 900 } });
        const page = await context.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        page.on('console', message => { if (message.type() === 'error') errors.push(message.text()); });
        await page.addInitScript(countSources);
        await page.goto(pageUrl);
        // A new context starts with empty storage. Do not clear it and reload here: the old document's clear reaches the
        // browser's store asynchronously and can land after the new document's saves, wiping them (CI, 2026-11-18).
        assert.equal(await page.evaluate(() => localStorage.getItem('mothership.sound-effects.library')), null, 'the test starts with no saved sounds');

        await page.getByRole('button', { name: 'Coin pickup' }).click();
        assert.equal(await page.evaluate(() => window.__starts), 1, 'a generator plays its new sound once');
        await page.locator('[data-sfx]').focus();
        await page.keyboard.press('s');
        await page.getByRole('button', { name: 'Explosion' }).click();
        await page.locator('[data-sfx]').focus();
        await page.keyboard.press('s');
        const names = () => page.locator('[role="option"] .sfx-item-name').allTextContents();
        assert.deepEqual(await names(), ['Coin pickup', 'Explosion']);

        // Space plays from the widget, but not from a text field (rename) or as a second press of a button.
        const starts = await page.evaluate(() => window.__starts);
        await page.locator('[data-sfx]').focus();
        await page.keyboard.press('Space');
        assert.equal(await page.evaluate(() => window.__starts), starts + 1);
        await page.keyboard.press('ArrowUp');
        assert.equal(await page.locator('[aria-selected="true"] .sfx-item-name').textContent(), 'Coin pickup');
        await page.getByRole('button', { name: 'Rename' }).click();
        const field = page.getByRole('textbox', { name: 'Sound name' });
        await field.fill('Gold coin');
        const before = await page.evaluate(() => window.__starts);
        await field.press('Space');
        await field.press('Backspace');
        assert.equal(await page.evaluate(() => window.__starts), before, 'Space in a text field types, it does not play');
        await field.press('Enter');
        assert.deepEqual(await names(), ['Gold coin', 'Explosion']);

        // A page's localStorage writes reach the browser's shared store asynchronously. Before reloading, wait until a
        // second page of the same origin sees the saved library.
        const probeFile = path.join(downloads, 'probe.html');
        fs.writeFileSync(probeFile, '<!doctype html><title>probe</title>');
        const probe = await context.newPage();
        await probe.goto(pathToFileURL(probeFile).href);
        await probe.waitForFunction(expected => {
            const stored = JSON.parse(localStorage.getItem('mothership.sound-effects.library') || 'null');
            return JSON.stringify((stored?.sounds || []).map(sound => sound.name)) === JSON.stringify(expected);
        }, ['Gold coin', 'Explosion'], { timeout: 15000 });
        await probe.close();
        // TODO 2026-11-18: remove this snapshot once CI shows why the reload sometimes finds no saved sounds.
        const snapshot = () => page.evaluate(() => {
            const raw = localStorage.getItem('mothership.sound-effects.library');
            let stored;
            try { stored = (JSON.parse(raw)?.sounds || []).map(sound => sound.name); } catch (error) { stored = `unreadable: ${error.message}`; }
            const navigation = performance.getEntriesByType('navigation')[0];
            return {
                url: location.href.slice(-40), readyState: document.readyState, navigation: navigation?.type, timeOrigin: Math.round(performance.timeOrigin),
                keys: Object.keys(localStorage), stored, rawLength: raw?.length ?? null,
                listed: [...document.querySelectorAll('[role="option"] .sfx-item-name')].map(node => node.textContent),
                empty: !!document.querySelector('[data-empty]:not([hidden])'), status: document.querySelector('[data-status]')?.textContent || '',
            };
        });
        const beforeReload = await snapshot();
        const errorsBeforeReload = errors.length;
        await page.reload();
        const afterReload = await snapshot();
        const listed = await names();
        if (JSON.stringify(listed) !== JSON.stringify(['Gold coin', 'Explosion'])) {
            await page.waitForTimeout(1000);
            const oneSecondLater = await snapshot();
            console.log('# sound-effects reload diagnostics', JSON.stringify({ beforeReload, afterReload, oneSecondLater, errorsAfterReload: errors.slice(errorsBeforeReload) }));
        }
        assert.deepEqual(listed, ['Gold coin', 'Explosion'], `both saved sounds survive a reload, in order; before ${JSON.stringify(beforeReload)}; after ${JSON.stringify(afterReload)}; errors ${JSON.stringify(errors)}`);

        // Switching modes keeps the sound.
        const peak = await page.locator('[data-peak]').textContent();
        await page.getByRole('radio', { name: 'Advanced' }).click();
        await page.getByRole('radio', { name: 'Simple' }).click();
        assert.equal(await page.locator('[data-peak]').textContent(), peak);

        // Picking a waveform plays the new waveform, not the render from before the click.
        await page.getByRole('radio', { name: 'Advanced' }).click();
        await page.getByRole('radio', { name: 'Sine' }).click();
        // The tool stores the current sound 250 ms after an edit; wait for that write, not for a fixed time.
        await page.waitForFunction(() => JSON.parse(localStorage.getItem('mothership.sound-effects.current') || 'null')?.params?.wave === 'sine');
        assert.ok(await page.evaluate(() => {
            const current = JSON.parse(localStorage.getItem('mothership.sound-effects.current'));
            const expected = window.MothershipSoundEffects.renderSamples(current.params, current.seed, 44100);
            return current.params.wave === 'sine' && expected.length === window.__lastPlayed.length && expected.every((v, i) => v === window.__lastPlayed[i]);
        }), 'the first playback after a waveform change holds the new samples');
        await page.getByRole('radio', { name: 'Simple' }).click();

        await page.getByRole('option', { name: /Gold coin/ }).click();
        const [wav] = await Promise.all([page.waitForEvent('download'), page.getByRole('button', { name: 'Download WAV' }).click()]);
        assert.equal(wav.suggestedFilename(), 'Gold coin.wav');
        const wavPath = path.join(downloads, 'one.wav');
        await wav.saveAs(wavPath);
        checkWav(fs.readFileSync(wavPath), 44100);
        const [zip] = await Promise.all([page.waitForEvent('download'), page.getByRole('button', { name: 'Download all as ZIP' }).click()]);
        const zipPath = path.join(downloads, 'all.zip');
        await zip.saveAs(zipPath);
        assert.deepEqual(readZip(fs.readFileSync(zipPath)).map(e => e.name), ['01 Gold coin.wav', '02 Explosion.wav']);

        // A store that refuses the write leaves the library and the Saved state as they were.
        await page.getByRole('button', { name: 'Whoosh' }).click();
        await page.evaluate(() => { window.__setItem = Storage.prototype.setItem; Storage.prototype.setItem = () => { throw new Error('full'); }; });
        await page.locator('[data-sfx]').focus();
        await page.keyboard.press('s');
        assert.deepEqual(await names(), ['Gold coin', 'Explosion']);
        assert.equal(await page.locator('[data-sound-state]').textContent(), 'Not saved');
        await page.getByRole('option', { name: /Gold coin/ }).click();
        await page.getByRole('button', { name: 'Nudge' }).click();
        await page.locator('[data-sfx]').focus();
        await page.keyboard.press('s');
        assert.equal(await page.locator('[data-sound-state]').textContent(), 'Changed since you saved it', 'a refused update stays unsaved');
        await page.getByRole('button', { name: 'Move down' }).click();
        await page.getByRole('button', { name: 'Delete' }).click();
        assert.deepEqual(await names(), ['Gold coin', 'Explosion'], 'a refused reorder or delete changes nothing');
        assert.equal(await page.locator('[aria-selected="true"] .sfx-item-name').textContent(), 'Gold coin');
        await page.evaluate(() => { Storage.prototype.setItem = window.__setItem; });
        assert.deepEqual(await page.evaluate(() => JSON.parse(localStorage.getItem('mothership.sound-effects.library')).sounds.map(s => s.name)), ['Gold coin', 'Explosion']);

        // Deleting keeps the sound in the editor as a draft and selects nothing; Undo puts it back in place.
        await page.getByRole('option', { name: /Gold coin/ }).click();
        await page.getByRole('button', { name: 'Delete' }).click();
        assert.deepEqual(await names(), ['Explosion']);
        assert.equal(await page.locator('[aria-selected="true"]').count(), 0);
        assert.equal(await page.locator('[data-name]').textContent(), 'Gold coin');
        assert.equal(await page.locator('[data-sound-state]').textContent(), 'Not saved');
        await page.getByRole('status').getByRole('button', { name: 'Undo' }).click();
        assert.deepEqual(await names(), ['Gold coin', 'Explosion']);
        assert.equal(await page.locator('[aria-selected="true"] .sfx-item-name').textContent(), 'Gold coin');

        for (const width of [360, 1440]) {
            await page.setViewportSize({ width, height: 900 });
            for (const mode of ['Advanced', 'Simple']) {
                await page.getByRole('radio', { name: mode }).click();
                const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
                assert.equal(overflow, 0, `no horizontal overflow at ${width}px in ${mode}`);
            }
        }
        assert.deepEqual(errors, []);
    } finally {
        await browser.close();
        fs.rmSync(downloads, { recursive: true, force: true });
    }
});
