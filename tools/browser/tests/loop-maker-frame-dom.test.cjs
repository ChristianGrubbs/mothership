const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const playwrightPath = process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH;
const browserTest = playwrightPath ? test : test.skip;
const widgets = path.resolve(__dirname, '../../../packages/Mothership/DAM/src/Resources/widgets');
const origin = 'http://loop-maker.test';
const toolPath = '/admin/home/tools/loop-maker/index.html';
const types = { '.html': 'text/html', '.css': 'text/css', '.js': 'application/javascript', '.svg': 'image/svg+xml' };

// Stands in for home-frame.js: keeps what the tool told the page, and presses the bar's buttons. The test may hold a
// save (window.__hold) and decide later whether the Library takes it.
const withBridge = ({ bar }) => {
    const handlers = {};
    window.__told = { chrome: [], notices: [], saves: [] };
    window.__press = name => handlers[name]?.();
    window.MothershipFrame = {
        bar,
        library: true,
        chrome(shown) { window.__told.chrome.push(shown); },
        on(name, handler) { handlers[name] = handler; },
        notice(shown) { window.__told.notices.push(shown); },
        async folders() { return [{ id: '7', name: 'Music', depth: 0 }, { id: '9', name: 'Beds', depth: 1 }]; },
        async save({ files, folder }) {
            window.__told.saves.push({ names: files.map(file => file.name), types: files.map(file => file.blob.type), folder });
            if (window.__hold) await new Promise((resolve, reject) => { window.__release = ok => (ok ? resolve() : reject(new Error('The Library is full.'))); });
            if (window.__refuse) throw new Error('The Library is full.');
            return { count: files.length, url: '/admin/dam/assets/1' };
        },
    };
};

const launch = () => require(playwrightPath).chromium.launch({ headless: true, channel: process.env.MOTHERSHIP_BROWSER_CHANNEL || 'chrome' });

// Serves the tool, and the kit beside it, from disk at the path Home serves it from.
async function open(browser, { bridge = null, width = 1440, height = 900, touch = false } = {}) {
    const page = await browser.newPage({ viewport: { width, height }, reducedMotion: 'reduce', hasTouch: touch });
    page.setDefaultTimeout(10000);
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.route(origin + '/**', route => {
        const url = new URL(route.request().url());
        const file = path.join(widgets, path.normalize(url.pathname.replace(/^\/admin\/home\/tools\//, '')));
        const type = types[path.extname(file)];
        return url.pathname.startsWith('/admin/home/tools/') && file.startsWith(widgets + path.sep) && type && fs.existsSync(file)
            ? route.fulfill({ contentType: `${type}; charset=utf-8`, body: fs.readFileSync(file) }) : route.fulfill({ status: 404, body: '' });
    });
    if (bridge) await page.addInitScript(withBridge, bridge);
    await page.goto(origin + toolPath);
    await page.getByRole('button', { name: 'Load a demo track' }).waitFor();
    return { page, errors };
}
const loadDemo = async page => {
    await page.getByRole('button', { name: 'Load a demo track' }).click();
    await page.locator('[data-editor]').waitFor();
};
const sheetOpen = (page, name) => page.locator(`[data-sheet="${name}"]`).evaluate(sheet => sheet.open);
// Filled is the accent's ground, or its hover ground under the pointer; every other control is a card or plain words.
const filled = page => page.evaluate(() => {
    const grounds = ['--act', '--act-hover'].map(name => {
        const probe = Object.assign(document.createElement('i'), { style: `color:${getComputedStyle(document.documentElement).getPropertyValue(name).trim()}` });
        document.body.append(probe);
        const ground = getComputedStyle(probe).color;
        probe.remove();
        return ground;
    });
    return [...document.querySelectorAll('button, a, .tk-btn')].filter(node => node.checkVisibility() && grounds.includes(getComputedStyle(node).backgroundColor)).map(node => node.textContent.trim());
});

browserTest('Loop Maker tells the bar what it offers, and its Export and Save to Library sheets take out the same loop', async () => {
    const browser = await launch();
    try {
        const { page, errors } = await open(browser, { bridge: { bar: true } });
        assert.deepEqual(await page.evaluate(() => window.__told.chrome), [{}], 'no track, nothing to offer');
        assert.equal(await page.locator('[data-own-bar]').isHidden(), true, 'the bar draws Export and Save to Library');

        await loadDemo(page);
        assert.deepEqual(await page.evaluate(() => window.__told.chrome.at(-1)), { export: true, library: true });
        assert.deepEqual(await filled(page), [], 'under a bar, the bar holds the filled control');

        await page.evaluate(() => window.__press('export'));
        assert.equal(await sheetOpen(page, 'export'), true);
        assert.deepEqual(await filled(page), ['Download WAV']);
        const written = await page.locator('[data-export-summary]').textContent();
        assert.match(written, /^WAV · \d:\d\d\.\d\d$/);
        assert.equal((await page.locator('[data-clock]').textContent()).split(' / ')[1], written.split(' · ')[1], 'the clock at rest shows the pass Export writes');

        await page.evaluate(() => window.__press('library'));
        assert.equal(await sheetOpen(page, 'export'), false, 'one sheet at a time');
        assert.equal(await sheetOpen(page, 'library'), true);
        await page.locator('[data-folder] option').nth(1).waitFor({ state: 'attached' });
        assert.equal(await page.locator('[data-library-file]').textContent(), 'demo-loop.wav');
        assert.equal(await page.locator('[data-library-summary]').textContent(), written, 'both sheets say the same loop');
        await page.locator('[data-folder]').selectOption('9');
        await page.locator('[data-library-go]').click();
        await page.waitForFunction(() => window.__told.notices.length === 1);
        assert.equal(await sheetOpen(page, 'library'), false);
        assert.deepEqual(await page.evaluate(() => window.__told.saves), [{ names: ['demo-loop.wav'], types: ['audio/wav'], folder: '9' }]);
        assert.deepEqual(await page.evaluate(() => window.__told.notices[0]), { text: 'Saved demo-loop.wav to Beds.', link: { label: 'Open in Library', url: '/admin/dam/assets/1' } });

        // Refused while the sheet is open: the sheet says it, and Save keeps the focus so Enter tries again.
        await page.evaluate(() => { window.__refuse = true; window.__press('library'); });
        assert.equal(await page.locator('[data-folder]').inputValue(), '9', 'the folder used last');
        await page.locator('[data-library-go]').click();
        await page.locator('[data-sheet="library"] .tk-status.is-error').filter({ hasText: 'The Library is full.' }).waitFor();
        assert.equal(await page.evaluate(() => document.activeElement.matches('[data-library-go]:not(:disabled)')), true);
        assert.equal(await page.evaluate(() => window.__told.notices.length), 1, 'the open sheet says it; the bar does not');

        // Refused after the sheet was closed: the bar says it at once, and the sheet once more when it opens.
        await page.evaluate(() => { window.__refuse = false; window.__hold = true; });
        await page.locator('[data-library-go]').click();
        assert.equal(await page.locator('[data-library-go]').textContent(), 'Saving…');
        await page.keyboard.press('Escape');
        assert.equal(await sheetOpen(page, 'library'), false);
        await page.evaluate(() => window.__release(false));
        await page.waitForFunction(() => window.__told.notices.length === 2);
        assert.deepEqual(await page.evaluate(() => window.__told.notices[1]), { text: 'Not saved to the Library. The Library is full.', tone: 'error' });
        await page.evaluate(() => { window.__hold = false; window.__press('library'); });
        assert.equal(await page.locator('[data-sheet="library"] .tk-status').textContent(), 'The Library is full.');
        await page.keyboard.press('Escape');
        await page.evaluate(() => window.__press('library'));
        assert.equal(await page.locator('[data-sheet="library"] .tk-status').textContent(), '', 'said once');
        assert.deepEqual(errors, []);
    } finally { await browser.close(); }
});

browserTest('Loop Maker sends one file however fast Save is pressed, and frees Save when the loop cannot be made', async () => {
    const browser = await launch();
    try {
        const { page, errors } = await open(browser, { bridge: { bar: true } });
        await loadDemo(page);
        await page.evaluate(() => window.__press('library'));
        await page.locator('[data-folder] option').nth(1).waitFor({ state: 'attached' });

        // Two presses in one turn, before the file is made: one file goes to the Library.
        await page.evaluate(() => { const go = document.querySelector('[data-library-go]'); go.click(); go.click(); });
        await page.waitForFunction(() => window.__told.notices.length > 0);
        assert.equal(await page.evaluate(() => window.__told.saves.length), 1);

        // MP3 with no encoder: nothing is sent, the sheet says why, and Save takes the focus to try again.
        await page.evaluate(() => window.__press('export'));
        await page.locator('[data-format]').selectOption('mp3-128');
        await page.evaluate(() => { window.__lame = window.lamejs; window.lamejs = undefined; window.__press('library'); });
        await page.locator('[data-library-go]').click();
        await page.locator('[data-sheet="library"] .tk-status.is-error').filter({ hasText: 'MP3 encoder unavailable' }).waitFor();
        assert.equal(await page.evaluate(() => document.activeElement.matches('[data-library-go]:not(:disabled)')), true);
        assert.equal(await page.evaluate(() => window.__told.saves.length), 1);

        await page.evaluate(() => { window.lamejs = window.__lame; });
        await page.locator('[data-library-go]').click();
        await page.waitForFunction(() => window.__told.notices.length === 2);
        assert.deepEqual(await page.evaluate(() => window.__told.saves[1]), { names: ['demo-loop.mp3'], types: ['audio/mpeg'], folder: '7' });
        assert.deepEqual(errors, []);
    } finally { await browser.close(); }
});

browserTest('Loop Maker without a bar draws its own Export, fills only the next step, and says a bad duration in both sheets', async () => {
    const browser = await launch();
    try {
        // From disk: no page around the tool, so no Library.
        let { page, errors } = await open(browser, { width: 1024, height: 768 });
        assert.deepEqual(await filled(page), ['Choose audio file']);
        await loadDemo(page);
        assert.equal(await page.locator('[data-own-bar]').isVisible(), true);
        assert.equal(await page.getByRole('button', { name: 'Export…' }).isVisible(), true);
        assert.equal(await page.locator('[data-open-library]').isHidden(), true, 'no Library to save to');
        assert.deepEqual(await filled(page), []);
        await page.getByRole('button', { name: 'Export…' }).click();
        await page.getByLabel('File length').selectOption('fill');
        await page.getByLabel('Target duration').fill('soon');
        assert.equal(await page.locator('[data-fill]').getAttribute('aria-invalid'), 'true');
        assert.equal(await page.locator('[data-status]').evaluate(line => line.classList.contains('is-error')), true);
        assert.equal(await page.locator('[data-export]').isDisabled(), true, 'nothing to download while the duration is bad');
        assert.deepEqual(errors, []);
        await page.close();

        // Home's panel: the page offers the Library but draws no bar, so the tool's own row holds the filled control.
        ({ page, errors } = await open(browser, { bridge: { bar: false }, width: 1024, height: 768 }));
        await loadDemo(page);
        assert.deepEqual(await filled(page), ['Save to Library…']);
        await page.getByRole('button', { name: 'Export…' }).click();
        await page.getByLabel('File length').selectOption('fill');
        await page.getByLabel('Target duration').fill('soon');
        await page.getByRole('button', { name: 'Close' }).click();
        await page.getByRole('button', { name: 'Save to Library…' }).click();
        assert.equal(await page.locator('[data-library-summary]').textContent(), 'Enter a valid duration in Export first.');
        assert.equal(await page.locator('[data-library-go]').isDisabled(), true);
        await page.getByRole('button', { name: 'Cancel' }).click();
        await page.getByRole('button', { name: 'Export…' }).click();
        await page.getByLabel('Target duration').fill('0:20');
        await page.getByRole('button', { name: 'Close' }).click();
        await page.getByRole('button', { name: 'Save to Library…' }).click();
        await page.locator('[data-library-go]').click();
        await page.locator('[data-tool-status]').filter({ hasText: 'Saved demo-loop.wav to Music.' }).waitFor();
        assert.deepEqual(errors, []);
    } finally { await browser.close(); }
});

browserTest('Loop Maker on a folded screen keeps every pane off the fold: the stage above a tabletop fold, the controls and the drop zone below', async () => {
    const browser = await launch();
    try {
        const { page, errors } = await open(browser, { width: 951, height: 585 });
        // What the page that frames the tool says about the fold (home-frame.js), with a hinge strip of 19 px and 31 px.
        const fold = (mode, start, end) => page.evaluate(([mode, start, end]) => {
            const root = document.documentElement;
            root.dataset.fold = mode;
            root.style.setProperty('--fold-start', start + 'px');
            root.style.setProperty('--fold-end', end + 'px');
        }, [mode, start, end]);
        const box = selector => page.locator(selector).evaluate(node => { const { left, right, top, bottom } = node.getBoundingClientRect(); return { left, right, top, bottom }; });
        const pageScrolls = () => page.evaluate(() => document.documentElement.scrollHeight - document.documentElement.clientHeight);

        await fold('columns', 466, 485);
        assert.ok((await box('.lm-drop')).right <= 466, 'with no track, the drop zone stays left of the fold');
        await page.setViewportSize({ width: 669, height: 867 });
        await fold('rows', 418, 449);
        assert.ok((await box('.lm-drop')).top >= 449, 'and below a tabletop fold');

        await loadDemo(page);
        let stage = await box('.lm-stage'), controls = await box('.lm-controls');
        assert.ok(stage.bottom <= 418, 'the stage ends above the fold');
        assert.ok(controls.top >= 449, 'the controls start below it');
        assert.ok((await box('.lm-wave')).bottom <= 418 && (await box('[data-play]')).bottom <= 418, 'the wave and Play both show above the fold');
        assert.equal(await pageScrolls(), 0, 'the page itself does not scroll');

        await page.setViewportSize({ width: 951, height: 585 });
        await fold('columns', 466, 485);
        stage = await box('.lm-stage');
        controls = await box('.lm-controls');
        assert.ok(stage.right <= 466, 'the stage ends before the fold');
        assert.ok(controls.left >= 485, 'the controls start after it');
        assert.equal(await page.locator('.lm-controls').evaluate(pane => pane.scrollHeight > pane.clientHeight && getComputedStyle(pane).overflowY === 'auto'), true, 'the controls scroll inside their own pane');
        assert.equal(await pageScrolls(), 0);
        assert.deepEqual(errors, []);
    } finally { await browser.close(); }
});

browserTest('Loop Maker on the iPhone Duo: Play and both figures above a tabletop fold, Play in view held sideways, a swipe up the wave scrolls and edits nothing, a keypad with a colon, and 44 px targets', async () => {
    const browser = await launch();
    try {
        const bottom = (page, selector) => page.locator(selector).evaluate(node => node.getBoundingClientRect().bottom);
        const region = page => page.evaluate(() => [document.querySelector('[data-start]').value, document.querySelector('[data-length]').value]);

        // Held like a laptop, in Home's frame: the tool is 669 × 761 and the fold runs from 286 to 326.
        let { page, errors } = await open(browser, { bridge: { bar: true }, width: 669, height: 761, touch: true });
        await loadDemo(page);
        await page.evaluate(() => {
            const root = document.documentElement;
            root.dataset.fold = 'rows';
            root.style.setProperty('--fold-start', '286px');
            root.style.setProperty('--fold-end', '326px');
        });
        for (const selector of ['.lm-wave', '[data-play]', '[data-find-best]', '.lm-readout']) assert.ok(await bottom(page, selector) <= 286, `${selector} shows above the fold`);
        assert.equal(await page.locator('.lm-stage').evaluate(pane => pane.scrollHeight - pane.clientHeight), 0, 'nothing in the stage waits under the fold');
        // Under a finger every button, tick and slider is 44 px tall, and Duration asks for a keypad that has the colon m:ss needs.
        const short = await page.evaluate(() => [...document.querySelectorAll('.tk-btn, .tk-check, input[type="range"]')]
            .filter(node => node.checkVisibility() && node.getBoundingClientRect().height < 44).map(node => node.textContent.trim() || node.id));
        assert.deepEqual(short, []);
        assert.equal(await page.locator('[data-fill]').getAttribute('inputmode'), null);
        assert.deepEqual(errors, []);
        await page.close();

        // Held sideways: 594 × 312, where the stage and the controls scroll as one.
        ({ page, errors } = await open(browser, { bridge: { bar: true }, width: 594, height: 312, touch: true }));
        await loadDemo(page);
        assert.ok(await bottom(page, '[data-play]') <= 312, 'Play is in view at rest');
        const finger = await page.context().newCDPSession(page);
        const swipe = async distance => {
            const wave = await page.locator('[data-overlay]').boundingBox();
            await finger.send('Input.synthesizeScrollGesture', { x: Math.round(wave.x + wave.width * .6), y: Math.round(wave.y + wave.height * .7), ...distance, gestureSourceType: 'touch' });
        };
        // The swipe starts from the top, with room below it, wherever loading the demo left the scroll on this machine's
        // fonts; the scroll lands a frame or more after the gesture on a busy machine, so the check waits for it.
        const scroller = page.locator('.lm'), before = await region(page);
        await scroller.evaluate(node => { node.scrollTop = 0; });
        const room = await scroller.evaluate(node => node.scrollHeight - node.clientHeight);
        assert.ok(room >= 100, `the tool has room to scroll (${room} px)`);
        await swipe({ yDistance: -100 });
        const scrolled = await page.waitForFunction(() => document.querySelector('.lm').scrollTop >= 50, null, { timeout: 3000 }).then(() => true, () => false);
        assert.ok(scrolled, `a swipe up that starts on the wave scrolls the tool (to ${await scroller.evaluate(node => node.scrollTop)} of ${room} px)`);
        assert.deepEqual(await region(page), before, 'and leaves the loop where it was');
        await scroller.evaluate(node => { node.scrollTop = 0; });
        await swipe({ xDistance: -80 });
        assert.notDeepEqual(await region(page), before, 'a sideways drag on the wave still moves the loop');
        assert.deepEqual(errors, []);
    } finally { await browser.close(); }
});

browserTest('Loop Maker puts the whole loop back, crossfade and fades included, when the browser cancels a press on the wave', async () => {
    const browser = await launch();
    try {
        const { page, errors } = await open(browser);
        await loadDemo(page);
        const settings = () => page.evaluate(() => Object.fromEntries(['start', 'length', 'crossfade', 'fade-in', 'fade-out'].map(name => [name, document.querySelector(`[data-${name}]`).value])));
        await page.evaluate(() => {
            for (const [name, value] of [['fade-in', '2'], ['fade-out', '1.5']]) {
                const input = document.querySelector(`[data-${name}]`);
                input.value = value;
                input.dispatchEvent(new Event('input', { bubbles: true }));
            }
        });
        const before = await settings();
        assert.ok(Number(before.crossfade) > .75 && Number(before['fade-in']) > .75, `a crossfade and fades a short loop would cap (${JSON.stringify(before)})`);

        // The demo is 8 s and its loop ends at its end: pull the end edge in to 2 s, a 1.5 s loop.
        const wave = await page.locator('[data-overlay]').boundingBox(), y = wave.y + wave.height / 2;
        await page.mouse.move(wave.x + wave.width - 2, y);
        await page.mouse.down();
        await page.mouse.move(wave.x + wave.width * 2 / 8, y, { steps: 6 });
        const squeezed = await settings();
        assert.ok(Number(squeezed.length) < 2 && Number(squeezed.crossfade) <= .75 && Number(squeezed['fade-in']) <= .75, `the short loop capped them (${JSON.stringify(squeezed)})`);
        // What the browser sends when it takes the press for a scroll.
        await page.locator('[data-overlay]').dispatchEvent('pointercancel', { pointerId: 1, pointerType: 'mouse' });
        assert.deepEqual(await settings(), before);
        await page.mouse.up();
        assert.deepEqual(await settings(), before, 'and the release after it changes nothing');
        assert.deepEqual(errors, []);
    } finally { await browser.close(); }
});
