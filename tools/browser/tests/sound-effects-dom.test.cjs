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

const launch = () => require(playwrightPath).chromium.launch({ headless: true, channel: process.env.MOTHERSHIP_BROWSER_CHANNEL || 'chrome' });
const settled = page => page.evaluate(() => new Promise(done => requestAnimationFrame(() => requestAnimationFrame(done))));
const sheetOpen = (page, name) => page.locator(`[data-sheet="${name}"]`).evaluate(sheet => sheet.open);
const closeSheet = (page, name) => page.locator(`[data-sheet="${name}"] [data-sheet-close]`).click();
// Opens a saved sound from the Saved sounds sheet, then closes the sheet.
async function pick(page, name) {
    await page.locator('[data-open-saved]').click();
    await page.getByRole('option', { name }).click();
    await closeSheet(page, 'saved');
}

// Stands in for home-frame.js: keeps what the tool told the page, and presses the bar's buttons.
const withBridge = () => {
    const handlers = {};
    window.__told = { chrome: [], notices: [], saves: [] };
    window.__press = (name, detail) => handlers[name]?.(detail);
    window.MothershipFrame = {
        bar: true,
        library: true,
        chrome(shown) { window.__told.chrome.push(shown); },
        on(name, handler) { handlers[name] = handler; },
        notice(shown) { window.__told.notices.push(shown); },
        async folders() { return [{ id: '7', name: 'Sounds', depth: 0 }]; },
        async save({ files, folder }) { window.__told.saves.push({ names: files.map(file => file.name), folder }); return { count: files.length, url: '/admin/dam/assets/1' }; },
    };
};
async function open(browser, { bridge = false, width = 1440, height = 900 } = {}) {
    const page = await browser.newPage({ viewport: { width, height }, reducedMotion: 'reduce' });
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    if (bridge) await page.addInitScript(withBridge);
    await page.goto(pageUrl);
    await page.locator('[data-name]').filter({ hasNotText: /^Sound$/ }).waitFor();
    return { page, errors };
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
        // The saved sounds live in a sheet; it opens on the list, so the arrow keys go on stepping through it.
        await page.locator('[data-open-saved]').click();
        assert.ok(await page.evaluate(() => document.activeElement.matches('[data-list]')), 'the list takes the focus');
        await page.getByRole('button', { name: 'Rename' }).click();
        const field = page.getByRole('textbox', { name: 'Sound name' });
        await field.fill('Gold coin');
        const before = await page.evaluate(() => window.__starts);
        await field.press('Space');
        await field.press('Backspace');
        assert.equal(await page.evaluate(() => window.__starts), before, 'Space in a text field types, it does not play');
        await field.press('Enter');
        assert.deepEqual(await names(), ['Gold coin', 'Explosion']);
        await closeSheet(page, 'saved');

        // A page's localStorage writes reach the browser's shared store asynchronously. Before reloading, wait until a
        // second page of the same origin sees the saved library.
        const probeFile = path.join(downloads, 'probe.html');
        fs.writeFileSync(probeFile, '<!doctype html><title>probe</title>');
        const probe = await context.newPage();
        await probe.goto(pathToFileURL(probeFile).href);
        await probe.waitForFunction(expected => {
            const stored = JSON.parse(localStorage.getItem('mothership.sound-effects.library') || 'null');
            return JSON.stringify((stored?.sounds || []).map(sound => sound.name)) === JSON.stringify(expected);
        }, ['Gold coin', 'Explosion'], { timeout: 15000 }).catch(async error => {
            const read = target => target.evaluate(() => localStorage.getItem('mothership.sound-effects.library')).catch(reason => `unreadable: ${reason.message}`);
            console.log('# sound-effects probe diagnostics', JSON.stringify({ probeSaw: await read(probe), pageHas: await read(page), errors }));
            throw error;
        });
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

        await pick(page, /Gold coin/);
        // Export downloads this sound, or every saved sound as one ZIP; its button says which.
        await page.locator('[data-open-export]').click();
        const [wav] = await Promise.all([page.waitForEvent('download'), page.getByRole('button', { name: 'Download WAV' }).click()]);
        assert.equal(wav.suggestedFilename(), 'Gold coin.wav');
        const wavPath = path.join(downloads, 'one.wav');
        await wav.saveAs(wavPath);
        checkWav(fs.readFileSync(wavPath), 44100);
        await page.getByRole('radio', { name: /Every saved sound/ }).check();
        const [zip] = await Promise.all([page.waitForEvent('download'), page.getByRole('button', { name: 'Download all as ZIP' }).click()]);
        const zipPath = path.join(downloads, 'all.zip');
        await zip.saveAs(zipPath);
        assert.deepEqual(readZip(fs.readFileSync(zipPath)).map(e => e.name), ['01 Gold coin.wav', '02 Explosion.wav']);
        await closeSheet(page, 'export');

        // A store that refuses the write leaves the library and the Saved state as they were.
        await page.getByRole('button', { name: 'Whoosh' }).click();
        await page.evaluate(() => { window.__setItem = Storage.prototype.setItem; Storage.prototype.setItem = () => { throw new Error('full'); }; });
        await page.locator('[data-sfx]').focus();
        await page.keyboard.press('s');
        assert.deepEqual(await names(), ['Gold coin', 'Explosion']);
        assert.equal(await page.locator('[data-sound-state]').textContent(), 'Not saved');
        await pick(page, /Gold coin/);
        await page.getByRole('button', { name: 'Nudge' }).click();
        await page.locator('[data-sfx]').focus();
        await page.keyboard.press('s');
        assert.equal(await page.locator('[data-sound-state]').textContent(), 'Changed since you saved it', 'a refused update stays unsaved');
        await page.locator('[data-open-saved]').click();
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
        await page.locator('[data-sheet="saved"] .tk-status').getByRole('button', { name: 'Undo' }).click();
        assert.deepEqual(await names(), ['Gold coin', 'Explosion']);
        assert.equal(await page.locator('[aria-selected="true"] .sfx-item-name').textContent(), 'Gold coin');
        await closeSheet(page, 'saved');

        for (const width of [360, 1440]) {
            await page.setViewportSize({ width, height: 900 });
            // On a phone Shape shares the space under the sound with Effects; the segment picks it.
            const shapeOpt = page.locator('[data-view-opt="shape"]');
            if (await shapeOpt.isVisible()) await shapeOpt.click();
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

browserTest('alone, the tool shows its own way to its sheets and offers no Save to Library', async () => {
    const browser = await launch();
    try {
        const { page, errors } = await open(browser);
        assert.equal(await page.locator('[data-own-bar]').isVisible(), true);
        assert.equal(await page.locator('[data-open-library]').isVisible(), false);

        // With nothing saved, the saved-sounds sheet says so, and Export offers only this sound.
        await page.locator('[data-open-saved]').click();
        assert.equal(await page.locator('[data-empty]').isVisible(), true);
        assert.equal(await page.getByRole('button', { name: 'Export all' }).isDisabled(), true);
        await page.keyboard.press('Escape');
        await page.locator('[data-open-export]').click();
        assert.equal(await sheetOpen(page, 'export'), true);
        assert.ok(await page.evaluate(() => document.activeElement.matches('input[type="radio"]')), 'the first choice takes the focus, never the scrolling body');
        assert.equal(await page.getByRole('radio', { name: /Every saved sound/ }).isDisabled(), true);
        // A shortcut under an open sheet does nothing; Escape closes the sheet.
        await page.keyboard.press('s');
        await page.keyboard.press('Escape');
        assert.equal(await sheetOpen(page, 'export'), false);
        assert.equal(await page.locator('[data-sound-state]').textContent(), 'Not saved', 'S under a sheet saves nothing');

        // Saved from the tool, the toast says so; it gives way to a sheet, which has its own status line.
        await page.locator('[data-sfx]').focus();
        await page.keyboard.press('s');
        assert.equal(await page.locator('[data-status]').textContent(), 'Saved Coin pickup');
        await page.locator('[data-open-saved]').click();
        assert.equal(await page.locator('[data-status]').isHidden(), true);
        assert.deepEqual(errors, []);
        await page.close();
    } finally { await browser.close(); }
});

browserTest('in the frame, the bar speaks for the tool and a save sends the sound as it was when Save was pressed', async () => {
    const browser = await launch();
    try {
        const { page, errors } = await open(browser, { bridge: true });
        assert.equal(await page.locator('[data-own-bar]').isVisible(), false);
        const said = () => page.evaluate(() => window.__told.chrome.at(-1));
        assert.deepEqual(await said(), { set: 'Coin pickup', sets: [], setsLabel: 'Saved sounds', manage: 'Manage saved sounds…', export: true, library: true });

        // Each saved sound is offered in the bar's menu, the open one marked; picking one opens it.
        await page.locator('[data-sfx]').focus();
        await page.keyboard.press('s');
        await page.getByRole('button', { name: 'Explosion' }).click();
        await page.locator('[data-sfx]').focus();
        await page.keyboard.press('s');
        const sets = (await said()).sets;
        assert.deepEqual(sets.map(set => [set.name, set.current]), [['Coin pickup', false], ['Explosion', true]]);
        await page.evaluate(id => window.__press('open-set', { id }), sets[0].id);
        assert.equal(await page.locator('[data-name]').textContent(), 'Coin pickup');
        assert.deepEqual((await said()).sets.map(set => set.current), [true, false]);

        await page.evaluate(() => window.__press('export'));
        assert.equal(await sheetOpen(page, 'export'), true);
        await page.keyboard.press('Escape');
        await page.evaluate(() => window.__press('manage-sets'));
        assert.equal(await sheetOpen(page, 'saved'), true);
        await page.keyboard.press('Escape');

        await page.evaluate(() => window.__press('library'));
        await page.locator('[data-folder] option', { hasText: 'Sounds' }).waitFor({ state: 'attached' });
        // Nothing ticked is nothing to save.
        await page.locator('[data-save-wav]').uncheck();
        await page.locator('[data-save-settings]').uncheck();
        assert.equal(await page.locator('[data-library-go]').isDisabled(), true);
        await page.locator('[data-save-wav]').check();
        await page.locator('[data-save-settings]').check();

        // The Library takes its time: the save waits until the test lets it go, and the bridge keeps each file.
        await page.evaluate(() => {
            const bridge = window.MothershipFrame, save = bridge.save.bind(bridge);
            window.__asked = 0;
            bridge.save = args => {
                window.__asked++;
                return new Promise(done => { window.__finish = async () => {
                    window.__sent = await Promise.all(args.files.map(async file => ({ name: file.name, type: file.blob.type, bytes: Array.from(new Uint8Array(await file.blob.arrayBuffer())) })));
                    done(save(args));
                }; });
            };
        });
        await page.locator('[data-library-go]').click();
        await page.waitForFunction(() => window.__asked === 1);
        assert.equal(await page.locator('[data-library-go]').textContent(), 'Saving…');
        assert.equal(await page.locator('[data-folder]').isDisabled(), true, 'the choices stay as sent');
        // While it saves, the sheet is closed and the sound changed.
        await page.keyboard.press('Escape');
        await page.getByRole('button', { name: 'Whoosh' }).click();
        await page.evaluate(() => window.__finish());
        await page.waitForFunction(() => window.__told.notices.length === 1);

        const sent = await page.evaluate(() => window.__sent);
        assert.deepEqual(sent.map(file => [file.name, file.type]), [['Coin pickup.wav', 'audio/wav'], ['Coin pickup.json', 'application/json']]);
        checkWav(sent[0].bytes, 44100);
        const settings = JSON.parse(Buffer.from(sent[1].bytes).toString('utf8'));
        assert.deepEqual(settings.sounds.map(sound => sound.name), ['Coin pickup'], 'the settings file is a library of the one sound Import opens again');
        assert.deepEqual(await page.evaluate(() => window.__told.saves.map(save => save.folder)), ['7']);
        assert.deepEqual(await page.evaluate(() => window.__told.notices[0]), { text: 'Saved 2 files to Sounds.', link: { label: 'Open in Library', url: '/admin/dam/assets/1' } });
        assert.equal(await page.locator('[data-status]').isHidden(), true, 'the bar says it once; the tool keeps quiet');
        // The next save starts from a free sheet, in the folder used last.
        await page.evaluate(() => window.__press('library'));
        assert.equal(await page.locator('[data-library-go]').textContent(), 'Save');
        assert.equal(await page.locator('[data-folder]').inputValue(), '7');
        assert.deepEqual(errors, []);
        await page.close();
    } finally { await browser.close(); }
});

browserTest('a refused save is never lost: the open sheet keeps the focus on Save, and a closed one is said in the bar and again when it opens', async () => {
    const browser = await launch();
    try {
        const { page, errors } = await open(browser, { bridge: true });
        const line = () => page.locator('[data-sheet="library"] .tk-status').evaluate(node => ({ text: node.textContent, error: node.classList.contains('is-error') }));
        const openLibrary = async () => {
            await page.evaluate(() => window.__press('library'));
            await page.locator('[data-folder] option', { hasText: 'Sounds' }).waitFor({ state: 'attached' });
        };

        // Refused while the sheet is open: it says why, and Save has the focus back so Enter tries again.
        await openLibrary();
        await page.evaluate(() => { window.MothershipFrame.save = async () => { throw new Error('The Library is full.'); }; });
        await page.locator('[data-library-go]').click();
        await page.waitForFunction(() => document.querySelector('[data-sheet="library"] .tk-status').textContent === 'The Library is full.');
        assert.deepEqual(await line(), { text: 'The Library is full.', error: true });
        assert.equal(await page.evaluate(() => document.activeElement.matches('[data-library-go]:not(:disabled)')), true, 'Save keeps the focus');
        assert.deepEqual(await page.evaluate(() => window.__told.notices), [], 'the open sheet says it; the bar does not');

        // Refused after the sheet was closed: the bar says it at once...
        await page.evaluate(() => { window.MothershipFrame.save = () => new Promise((_, refuse) => { window.__refuse = () => refuse(new Error('The Library is full.')); }); });
        await page.locator('[data-library-go]').click();
        await page.waitForFunction(() => typeof window.__refuse === 'function');
        await page.keyboard.press('Escape');
        assert.equal(await sheetOpen(page, 'library'), false);
        await page.evaluate(() => window.__refuse());
        await page.waitForFunction(() => window.__told.notices.length === 1);
        assert.deepEqual(await page.evaluate(() => window.__told.notices[0]), { text: 'Not saved to the Library. The Library is full.', tone: 'error' });
        assert.equal(await page.locator('[data-status]').isHidden(), true, 'the bar says it once; the tool keeps quiet');
        // ...and the sheet says it again when it opens, free to try again; once seen, it is not said a second time.
        await openLibrary();
        assert.deepEqual(await line(), { text: 'The Library is full.', error: true });
        assert.equal(await page.locator('[data-library-go]').isDisabled(), false);
        assert.equal(await page.locator('[data-library-go]').textContent(), 'Save');
        await page.keyboard.press('Escape');
        await openLibrary();
        assert.deepEqual(await line(), { text: '', error: false });
        assert.deepEqual(errors, []);
        await page.close();
    } finally { await browser.close(); }
});

browserTest('with no folder to save into, the Library sheet says so and offers no ticks', async () => {
    const browser = await launch();
    try {
        const { page, errors } = await open(browser, { bridge: true });
        await page.evaluate(() => { window.MothershipFrame.folders = async () => []; });
        await page.evaluate(() => window.__press('library'));
        await page.locator('[data-folder-empty]').waitFor();
        assert.equal(await page.locator('[data-save-wav]').isVisible(), false);
        assert.equal(await page.locator('[data-save-settings]').isVisible(), false);
        assert.equal(await page.locator('[data-folder]').isVisible(), false);
        assert.equal(await page.locator('[data-library-go]').isDisabled(), true);
        assert.deepEqual(errors, []);
        await page.close();
    } finally { await browser.close(); }
});

browserTest('a saved sound being renamed draws one edge, its field\'s, not the row\'s as well', async () => {
    const browser = await launch();
    try {
        const { page, errors } = await open(browser);
        await saveTakes(page, 1);
        const row = page.locator('[data-sheet="saved"] [role="option"]').first();
        await row.click();
        // The edge eases in and out (briefly, with reduced motion): it is read once the row has finished changing.
        const edge = () => row.evaluate(async node => { await Promise.all(node.getAnimations().map(a => a.finished)); return getComputedStyle(node).boxShadow; });
        assert.match(await edge(), /inset/, 'the selected row has its own edge');
        await page.locator('[data-rename]').click();
        await page.locator('[data-rename-input]').waitFor();
        assert.equal(await page.evaluate(() => document.activeElement.matches('[data-rename-input]')), true);
        assert.doesNotMatch(await edge(), /inset/, 'while it is renamed, the field is the row\'s one edge');
        await page.keyboard.press('Enter');
        assert.match(await edge(), /inset/, 'renamed, the row has its edge back');
        assert.deepEqual(errors, []);
        await page.close();
    } finally { await browser.close(); }
});

browserTest('on a touch screen the field that renames a saved sound is 16 px, where Safari would zoom into a smaller one', async () => {
    const browser = await launch();
    try {
        const sizes = [];
        for (const hasTouch of [true, false]) {
            const page = await browser.newPage({ viewport: { width: 390, height: 844 }, hasTouch, reducedMotion: 'reduce' });
            await page.goto(pageUrl);
            await page.locator('[data-name]').filter({ hasNotText: /^Sound$/ }).waitFor();
            await saveTakes(page, 1);
            await page.locator('[data-sheet="saved"] [role="option"]').first().click();
            await page.locator('[data-rename]').click();
            sizes.push(await page.locator('[data-rename-input]').evaluate(node => getComputedStyle(node).fontSize));
            await page.close();
        }
        assert.deepEqual(sizes, ['16px', '14.5px'], 'touch, then a mouse');
    } finally { await browser.close(); }
});

browserTest('the panes follow the tool\'s width, and nothing scrolls sideways', async () => {
    const browser = await launch();
    try {
        const { page } = await open(browser);
        const box = selector => page.locator(selector).evaluate(node => { const b = node.getBoundingClientRect(); return { left: Math.round(b.left), top: Math.round(b.top) }; });
        for (const [width, height] of [[1440, 900], [1024, 768], [768, 1024], [720, 900], [719, 900], [669, 867], [594, 368], [390, 844]]) {
            await page.setViewportSize({ width, height });
            await settled(page);
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth), 0, `sideways at ${width}`);
        }

        for (const [width, height] of [[1440, 900], [768, 1024]]) {
            await page.setViewportSize({ width, height });
            await settled(page);
            assert.equal(await page.locator('[data-view-opt="shape"]').isVisible(), false, `no segment at ${width}`);
            const sound = await box('[data-pane="sound"]'), effects = await box('[data-pane="effects"]'), shape = await box('[data-pane="shape"]');
            assert.ok(effects.left === sound.left && effects.top > sound.top, `Effects under the sound at ${width}`);
            assert.ok(shape.left > sound.left && shape.top === sound.top, `Shape beside them at ${width}`);
        }

        await page.setViewportSize({ width: 390, height: 844 });
        await settled(page);
        const shown = () => page.evaluate(() => ['sound', 'effects', 'shape'].filter(name => document.querySelector(`[data-pane="${name}"]`).checkVisibility()));
        assert.deepEqual(await shown(), ['sound', 'effects']);
        await page.locator('[data-view-opt="shape"]').click();
        assert.deepEqual(await shown(), ['sound', 'shape'], 'the sound stays; the segment swaps what is under it');
        assert.equal(await page.locator('[data-view-opt="shape"]').getAttribute('aria-pressed'), 'true');
        await page.close();
    } finally { await browser.close(); }
});

browserTest('on a fold the panes and a sheet keep clear of the strip', async () => {
    const browser = await launch();
    try {
        const { page } = await open(browser, { width: 951, height: 585 });
        await page.evaluate(() => {
            const html = document.documentElement;
            html.dataset.fold = 'columns';
            html.style.setProperty('--fold-start', '455px');
            html.style.setProperty('--fold-end', '495px');
        });
        await settled(page);
        const onStrip = await page.evaluate(() => [...document.querySelectorAll('main button, main input, main select, main canvas')].filter(node => {
            const box = node.getBoundingClientRect();
            return box.width > 0 && box.height > 0 && box.left < 495 && box.right > 455;
        }).map(node => node.getAttribute('aria-label') || node.textContent.trim().slice(0, 30) || node.tagName));
        assert.deepEqual(onStrip, []);
        assert.equal(await page.locator('[data-view-opt="shape"]').isVisible(), false, 'each half shows its own part');

        await page.locator('[data-open-export]').click();
        await settled(page);
        const sheet = await page.locator('[data-sheet="export"]').evaluate(node => { const box = node.getBoundingClientRect(); return { left: box.left, right: box.right }; });
        assert.ok(sheet.left >= 495, `the sheet starts past the fold: ${sheet.left}`);
        assert.ok(sheet.right <= 951, `the sheet stays on the screen: ${sheet.right}`);
        await page.keyboard.press('Escape');

        // Folded like a laptop: the sound alone above the fold; the segment and the pane it picks below.
        await page.setViewportSize({ width: 669, height: 803 });
        await page.evaluate(() => {
            const html = document.documentElement;
            html.dataset.fold = 'rows';
            html.style.setProperty('--fold-start', '349px');
            html.style.setProperty('--fold-end', '389px');
        });
        await settled(page);
        const across = await page.evaluate(() => [...document.querySelectorAll('main button, main input, main select, main canvas')].filter(node => {
            const box = node.getBoundingClientRect();
            return box.width > 0 && box.height > 0 && box.top < 389 && box.bottom > 349;
        }).map(node => node.getAttribute('aria-label') || node.textContent.trim().slice(0, 30) || node.tagName));
        assert.deepEqual(across, []);
        const edge = selector => page.locator(selector).evaluate(node => { const box = node.getBoundingClientRect(); return { top: box.top, bottom: box.bottom }; });
        assert.ok((await edge('[data-pane="sound"]')).bottom <= 349, 'the sound ends above the fold');
        assert.ok((await edge('[data-play]')).bottom <= 349, 'Play is above the fold');
        assert.ok((await edge('[data-view-opt="shape"]')).top >= 389, 'the segment starts below the fold');
        assert.ok((await edge('[data-pane="effects"]')).top >= 389, 'Effects is below the fold');
        await page.locator('[data-view-opt="shape"]').click();
        assert.equal(await page.locator('[data-pane="effects"]').isVisible(), false);
        assert.ok((await edge('[data-pane="shape"]')).top >= 389, 'Shape takes its place');
        await page.close();
    } finally { await browser.close(); }
});

// Saves `count` fresh takes, one generator after another, so the Saved sounds sheet has rows to show.
async function saveTakes(page, count) {
    const generators = page.locator('[data-gen]');
    const kinds = await generators.count();
    for (let i = 0; i < count; i++) {
        await generators.nth(i % kinds).click();
        await page.locator('[data-sfx]').focus();
        await page.keyboard.press('s');
    }
    await page.locator('[data-open-saved]').click();
    await page.getByRole('option').nth(count - 1).waitFor({ state: 'attached' });
}

browserTest('on a touch screen the segment, Simple and Advanced, the sliders and the selected sound\'s buttons are 44 px targets', async () => {
    const browser = await launch();
    try {
        const page = await browser.newPage({ viewport: { width: 390, height: 844 }, hasTouch: true, reducedMotion: 'reduce' });
        await page.goto(pageUrl);
        await page.locator('[data-name]').filter({ hasNotText: /^Sound$/ }).waitFor();
        assert.ok(await page.evaluate(() => matchMedia('(pointer: coarse)').matches), 'the page sees a touch screen');
        const heights = selector => page.locator(selector).evaluateAll(nodes => nodes.filter(node => node.checkVisibility())
            .map(node => ({ name: node.textContent.trim(), height: node.getBoundingClientRect().height })));
        const short = list => list.filter(target => target.height < 44);

        const segment = await heights('.sfx-seg .tk-seg-opt');
        assert.equal(segment.length, 2, 'Effects and Shape are shown');
        assert.deepEqual(short(segment), []);
        await page.locator('[data-view-opt="shape"]').click();
        const mode = await heights('.sfx-mode .tk-seg-opt');
        assert.equal(mode.length, 2, 'Simple and Advanced are shown');
        assert.deepEqual(short(mode), []);
        assert.deepEqual(short(await heights('.sfx-own .tk-btn')), [], 'the way to the sheets');
        const sliders = await heights('.sfx-slider input[type="range"]');
        assert.ok(sliders.length >= 3, `Shape's sliders are shown: ${sliders.length}`);
        assert.deepEqual(short(sliders), [], 'the sliders');

        await page.locator('[data-view-opt="effects"]').click();
        await saveTakes(page, 1);
        const tools = await heights('.sfx-tools .tk-btn');
        assert.ok(tools.length >= 5, 'the selected sound\'s buttons are shown');
        assert.deepEqual(short(tools), []);
        await page.close();

        // A mouse keeps the 28 px sliders.
        const mouse = await browser.newPage({ viewport: { width: 390, height: 844 }, reducedMotion: 'reduce' });
        await mouse.goto(pageUrl);
        await mouse.locator('[data-name]').filter({ hasNotText: /^Sound$/ }).waitFor();
        await mouse.locator('[data-view-opt="shape"]').click();
        const thin = await mouse.locator('.sfx-slider input[type="range"]').evaluateAll(nodes => nodes.filter(node => node.checkVisibility()).map(node => node.getBoundingClientRect().height));
        assert.ok(thin.length >= 3 && thin.every(height => height === 28), `with a mouse the sliders keep 28 px: ${thin}`);
        await mouse.close();
    } finally { await browser.close(); }
});

browserTest('held sideways on a short screen, the Saved sounds sheet shows its rows and still reaches its toolbar', async () => {
    const browser = await launch();
    try {
        // An iPhone Duo's outer display on its side, as a browser window and as Safari, and a phone on its side.
        for (const [width, height] of [[594, 312], [678, 340], [844, 390]]) {
            const page = await browser.newPage({ viewport: { width, height }, hasTouch: true, reducedMotion: 'reduce' });
            await page.goto(pageUrl);
            await page.locator('[data-name]').filter({ hasNotText: /^Sound$/ }).waitFor();
            await saveTakes(page, 8);
            await settled(page);
            const body = page.locator('[data-sheet="saved"] .tk-sheet-body');
            const shown = await body.evaluate(node => {
                const view = node.getBoundingClientRect();
                return [...node.querySelectorAll('[role="option"]')].filter(row => {
                    const box = row.getBoundingClientRect();
                    return box.top >= view.top && box.bottom <= view.bottom;
                }).length;
            });
            assert.ok(shown >= 2, `${width} x ${height}: ${shown} whole rows in view`);
            await page.getByRole('button', { name: 'Delete' }).scrollIntoViewIfNeeded();
            assert.ok(await page.getByRole('button', { name: 'Delete' }).isVisible(), `${width} x ${height}: the toolbar is reached by scrolling`);
            await page.close();
        }
    } finally { await browser.close(); }
});
