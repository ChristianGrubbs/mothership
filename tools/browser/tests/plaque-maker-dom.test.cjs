const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const playwrightPath = process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH;
const browserTest = playwrightPath ? test : test.skip;
const widgets = path.resolve(__dirname, '../../../packages/Mothership/DAM/src/Resources/widgets');
const origin = 'http://plaque.test';
const toolPath = '/admin/home/tools/plaque/index.html';
const types = { '.html': 'text/html', '.css': 'text/css', '.js': 'application/javascript' };

// Stands in for Home around the tool, as docs/manual/developer/iframe-tools.md describes it: Home asks before closing
// the tool when the tool reported work and has not said since that it holds none.
const home = `<!doctype html><meta charset="utf-8"><style>body{margin:0}</style><script>
    window.__told = [];
    let noWork = false, reported = false;
    window.__asksBeforeClosing = () => !noWork && reported;
    addEventListener('message', event => {
        const data = event.data;
        if (event.source !== frames[0] || event.origin !== location.origin || data?.protocol !== 'mothership-widget/v1') return;
        if (data.type === 'ready' && typeof data.payload?.holdsWork === 'boolean') {
            window.__told.push(data.payload.holdsWork);
            noWork = !data.payload.holdsWork;
            if (noWork) reported = false;
        }
        if (data.type === 'dirty' && !noWork) reported = true;
    });
</script><iframe src="${toolPath}" style="display:block;width:100%;height:880px;border:0"></iframe>`;

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
        async folders() { return [{ id: '7', name: 'Plaques', depth: 0 }, { id: '9', name: 'Shows', depth: 1 }]; },
        async save({ files, folder }) { window.__told.saves.push({ names: files.map(file => file.name), folder }); return { count: files.length, url: '/admin/dam/assets/1' }; },
    };
};

// The tool keeps the set in IndexedDB. Each write of the set waits here until the test lets it succeed or refuses it,
// so the test decides when a save lands. Reads, and writes of other stores, go to the real database.
const holdWrites = () => {
    if (!location.pathname.startsWith('/admin/home/tools/plaque/')) return;
    window.__writes = [];
    const transaction = IDBDatabase.prototype.transaction;
    IDBDatabase.prototype.transaction = function (store, mode) {
        if (store !== 'sets' || mode !== 'readwrite') return transaction.apply(this, arguments);
        const write = { objectStore: () => ({ put: () => ({}) }), oncomplete: null, onerror: null, error: null };
        window.__writes.push(write);
        return write;
    };
};

const launch = () => require(playwrightPath).chromium.launch({ headless: true, channel: process.env.MOTHERSHIP_BROWSER_CHANNEL || 'chrome' });
const settled = page => page.evaluate(() => new Promise(done => requestAnimationFrame(() => requestAnimationFrame(done))));
const sheetOpen = (tool, name) => tool.locator(`[data-sheet="${name}"]`).evaluate(sheet => sheet.open);

// Serves the tool from disk at the path Home serves it from. Home's context answer (Library and AI) is not found, as
// on a page that offers neither; the bridge, where there is one, offers the Library.
async function open(browser, { bridge = false, framed = false, width = 1440, height = 900, touch = false } = {}) {
    const page = await browser.newPage({ viewport: { width, height }, hasTouch: touch, reducedMotion: 'reduce' });
    page.setDefaultTimeout(10000);
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.route(origin + '/**', route => {
        const url = new URL(route.request().url());
        if (url.pathname === '/home.html') return route.fulfill({ contentType: 'text/html; charset=utf-8', body: home });
        const file = path.join(widgets, path.normalize(url.pathname.replace(/^\/admin\/home\/tools\//, '')));
        const type = types[path.extname(file)];
        return url.pathname.startsWith('/admin/home/tools/') && file.startsWith(widgets + path.sep) && type && fs.existsSync(file)
            ? route.fulfill({ contentType: `${type}; charset=utf-8`, body: fs.readFileSync(file) }) : route.fulfill({ status: 404, body: '' });
    });
    if (bridge) await page.addInitScript(withBridge);
    if (framed) await page.addInitScript(holdWrites);
    // The tool asks Home what it offers as the last step of starting.
    const started = page.waitForRequest(request => request.url() === `${origin}/admin/home/plaque/context`);
    await page.goto(origin + (framed ? '/home.html' : toolPath));
    await started;
    const tool = framed ? page.frameLocator('iframe') : page;
    const frame = framed ? page.frames().find(candidate => candidate.url().endsWith(toolPath)) : page.mainFrame();
    return { page, errors, tool, frame };
}
// An empty set offers its ways to start on the stage; once it has plaques, they sit under the list.
const addBlank = tool => tool.locator('[data-action="add-blank"]:visible').first().click();
async function openDraft(tool) {
    await tool.locator('[data-action="open-presets"]:visible').first().click();
    await tool.locator('[data-preset-grid] [data-preset]').first().click();
    await tool.locator('[data-draft-banner]').waitFor();
}

browserTest('in the frame, the bar names the set and offers Export and Save to Library only while there is a set to take out', async () => {
    const browser = await launch();
    try {
        const { page, errors } = await open(browser, { bridge: true });
        const said = () => page.evaluate(() => window.__told.chrome.at(-1));
        const offers = async () => { const { export: exporting, library } = await said(); return { export: exporting, library }; };
        assert.equal(await page.locator('[data-own-bar]').isVisible(), false, 'the bar draws Open, Export and Save to Library');
        assert.deepEqual(await said(), { set: 'Untitled set', sets: [], setsLabel: 'Plaque sets', manage: 'Open a set…', export: false, library: false });

        await addBlank(page);
        assert.deepEqual(await offers(), { export: true, library: true });
        await page.locator('[data-set-name]').fill('Launch week');
        assert.equal((await said()).set, 'Launch week');

        // During a preset draft "Add to set" is the one filled button: the bar offers neither, and Save to Library pressed
        // anyway does not save the set around the draft.
        await openDraft(page);
        assert.deepEqual(await offers(), { export: false, library: false });
        await page.evaluate(() => window.__press('library'));
        assert.equal(await sheetOpen(page, 'library'), false);
        await page.locator('[data-action="discard-draft"]').click();
        assert.deepEqual(await offers(), { export: true, library: true });

        // The bar's buttons open the tool's own sheets (Export first checks which formats this browser can record).
        await page.evaluate(() => window.__press('export'));
        await page.locator('[data-sheet="export"][open]').waitFor();
        await page.keyboard.press('Escape');
        assert.equal(await sheetOpen(page, 'export'), false);
        await page.evaluate(() => window.__press('manage-sets'));
        await page.locator('[data-sheet="open"][open]').waitFor();
        await page.keyboard.press('Escape');
        assert.equal(await sheetOpen(page, 'open'), false);
        assert.deepEqual(errors, []);
        await page.close();
    } finally { await browser.close(); }
});

browserTest('Save to Library sends a render, the sound and the set file, remembers the folder, and keeps the focus on Save when refused', async () => {
    const browser = await launch();
    try {
        const { page, errors } = await open(browser, { bridge: true });
        await addBlank(page);
        await page.locator('[data-set-name]').fill('Launch week');
        await page.evaluate(() => window.__press('library'));
        await page.locator('[data-library-folder] option', { hasText: 'Shows' }).waitFor({ state: 'attached' });
        assert.equal(await page.locator('[data-library-folder]').inputValue(), '7', 'the first folder until one was used');
        assert.equal(await page.locator('[data-library-wav-name]').textContent(), 'launch-week.wav');
        await page.locator('[data-library-folder]').selectOption('9');

        // The Library takes its time: the save waits until the test lets it go, and the bridge keeps each file.
        await page.evaluate(() => {
            const bridge = window.MothershipFrame, save = bridge.save.bind(bridge);
            window.__asked = 0;
            bridge.save = args => {
                window.__asked++;
                return new Promise(done => { window.__finish = async () => {
                    window.__sent = await Promise.all(args.files.map(async file => ({
                        name: file.name, type: file.blob.type,
                        head: Array.from(new Uint8Array(await file.blob.slice(0, 8).arrayBuffer())),
                        text: file.name.endsWith('.json') ? await file.blob.text() : null,
                    })));
                    done(save(args));
                }; });
            };
        });
        await page.locator('[data-action="save-library"]').click();
        await page.waitForFunction(() => window.__asked === 1);
        assert.equal(await page.locator('[data-action="save-library"]').textContent(), 'Saving…');
        assert.equal(await page.locator('[data-library-folder]').isDisabled(), true, 'the choices stay as sent');
        await page.evaluate(() => window.__finish());
        await page.waitForFunction(() => window.__told.notices.length === 1);

        const sent = await page.evaluate(() => window.__sent);
        assert.deepEqual(sent.map(file => [file.name, file.type]), [['launch-week.png', 'image/png'], ['launch-week.wav', 'audio/wav'], ['launch-week.plaque.json', 'application/json']]);
        assert.deepEqual(sent[0].head, [0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a], 'a PNG');
        assert.equal(Buffer.from(sent[1].head.slice(0, 4)).toString('ascii'), 'RIFF', 'a WAV');
        const set = JSON.parse(sent[2].text);
        assert.equal(set.name, 'Launch week');
        assert.equal(set.plaques.length, 1, 'the set file opens again with "Open a set"');
        assert.deepEqual(await page.evaluate(() => window.__told.saves.map(save => save.folder)), ['9']);
        assert.deepEqual(await page.evaluate(() => window.__told.notices[0]), { text: 'Saved 3 files to Shows.', link: { label: 'Open in Library', url: '/admin/dam/assets/1' } });
        assert.equal(await sheetOpen(page, 'library'), false);

        // The next save starts from a free sheet, in the folder used last.
        await page.evaluate(() => window.__press('library'));
        assert.equal(await page.locator('[data-library-folder]').inputValue(), '9');
        assert.equal(await page.locator('[data-action="save-library"]').textContent(), 'Save');

        // Refused: the sheet stays open and says why, and Save has the focus back so Enter tries again.
        await page.evaluate(() => { window.MothershipFrame.save = async () => { throw new Error('The Library is full.'); }; });
        await page.locator('[data-action="save-library"]').click();
        await page.waitForFunction(() => document.querySelector('[data-library-status]').textContent === 'The Library is full.');
        assert.equal(await sheetOpen(page, 'library'), true);
        assert.equal(await page.locator('[data-library-status]').evaluate(line => line.classList.contains('is-error')), true);
        assert.equal(await page.evaluate(() => document.activeElement.matches('[data-action="save-library"]:not(:disabled)')), true, 'Save keeps the focus');
        assert.equal(await page.evaluate(() => window.__told.notices.length), 1, 'the open sheet says it; the bar does not');
        assert.deepEqual(errors, []);
        await page.close();
    } finally { await browser.close(); }
});

browserTest('on a touch screen no field is under 16 px, where Safari would zoom in; with a mouse the editor and the paste boxes keep their sizes', async () => {
    const browser = await launch();
    try {
        const seen = {};
        for (const touch of [true, false]) {
            const { page, errors } = await open(browser, { touch, width: 1024, height: 768 });
            assert.equal(await page.evaluate(() => matchMedia('(pointer: coarse)').matches), touch);
            await addBlank(page);
            await page.locator('.plq-edit .field input[type="text"]').first().waitFor({ state: 'attached' });
            seen[touch ? 'touch' : 'mouse'] = await page.evaluate(() => {
                const skip = ['checkbox', 'radio', 'range', 'color', 'file', 'hidden'];
                const fields = [...document.querySelectorAll('input, select, textarea')].filter(node => !skip.includes(node.type));
                const size = node => getComputedStyle(node).fontSize;
                return {
                    small: fields.filter(node => parseFloat(size(node)) < 16).map(node => `${node.outerHTML.slice(0, 70)} ${size(node)}`),
                    editor: [...new Set(fields.filter(node => node.closest('.plq-edit .field')).map(size))],
                    paste: [...new Set([...document.querySelectorAll('.plq-paste')].map(size))],
                };
            });
            assert.deepEqual(errors, []);
            await page.close();
        }
        assert.deepEqual(seen.touch.small, [], 'every field on a touch screen');
        assert.deepEqual([seen.mouse.editor, seen.mouse.paste], [['14.5px'], ['12.5px']], 'with a mouse');
    } finally { await browser.close(); }
});

browserTest('the panes follow the tool\'s width, and nothing scrolls sideways', async () => {
    const browser = await launch();
    try {
        const { page, errors } = await open(browser);
        await addBlank(page);
        const shown = () => page.evaluate(() => ['plaques', 'output', 'edit'].filter(name => document.querySelector(`[data-pane="${name}"]`).checkVisibility()));
        const options = () => page.evaluate(() => [...document.querySelectorAll('[data-view-opt]')].filter(button => button.checkVisibility()).map(button => button.dataset.viewOpt));
        const box = name => page.locator(`[data-pane="${name}"]`).evaluate(node => { const b = node.getBoundingClientRect(); return { left: Math.round(b.left), right: Math.round(b.right) }; });
        for (const [width, height] of [[1440, 900], [1100, 800], [1099, 800], [1024, 768], [768, 1024], [720, 900], [719, 900], [594, 368], [390, 844]]) {
            await page.setViewportSize({ width, height });
            await settled(page);
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth), 0, `sideways at ${width}`);
        }

        // From 1100: the plaques, the output under the preview, and Edit, side by side, with no segment.
        await page.setViewportSize({ width: 1440, height: 900 });
        await settled(page);
        assert.deepEqual(await options(), []);
        assert.deepEqual(await shown(), ['plaques', 'output', 'edit']);
        const [plaques, output, edit] = [await box('plaques'), await box('output'), await box('edit')];
        assert.ok(plaques.right <= output.left && output.right <= edit.left, 'Plaques, then Output, then Edit');

        // 720 to 1099: Edit stays in view; the segment picks the plaques or the output.
        await page.setViewportSize({ width: 1024, height: 768 });
        await settled(page);
        assert.deepEqual(await options(), ['plaques', 'output']);
        assert.deepEqual(await shown(), ['plaques', 'edit']);
        await page.locator('[data-view-opt="output"]').click();
        assert.deepEqual(await shown(), ['output', 'edit']);
        assert.equal(await page.locator('[data-view-opt="output"]').getAttribute('aria-pressed'), 'true');
        await page.locator('[data-view-opt="plaques"]').click();

        // Under 720: one pane under the preview, picked by a three-part segment, and a new plaque goes straight to Edit.
        await page.setViewportSize({ width: 390, height: 844 });
        await settled(page);
        assert.deepEqual(await options(), ['plaques', 'edit', 'output']);
        assert.deepEqual(await shown(), ['plaques']);
        await page.locator('[data-view-opt="output"]').click();
        assert.deepEqual(await shown(), ['output']);
        await page.locator('[data-view-opt="plaques"]').click();
        await addBlank(page);
        assert.deepEqual(await shown(), ['edit']);
        assert.equal(await page.locator('[data-view-opt="edit"]').getAttribute('aria-pressed'), 'true');
        assert.equal(await page.evaluate(() => document.activeElement.matches('[data-inspector] [data-k="title"]')), true, 'the title takes the focus');
        assert.deepEqual(errors, []);
        await page.close();
    } finally { await browser.close(); }
});

browserTest('Home is told the tool holds work while a change waits to be kept, after a refused write, and while an edited draft is open', async () => {
    const browser = await launch();
    try {
        const { page, errors, tool, frame } = await open(browser, { framed: true });
        const writesStarted = count => frame.waitForFunction(n => window.__writes.length === n, count);
        const land = (at, ok = true) => frame.evaluate(([at, ok]) => {
            const write = window.__writes[at];
            if (ok) { write.oncomplete(); return; }
            write.error = new DOMException('The quota has been exceeded.', 'QuotaExceededError');
            write.onerror();
        }, [at, ok]);
        const line = () => tool.locator('[data-save-state]').textContent();
        const rename = name => tool.locator('[data-set-name]').fill(name);
        // Messages from the tool reach Home as tasks of their own; a short wait lets any still on their way arrive,
        // so a check that nothing was said means it.
        const delivered = () => page.evaluate(() => new Promise(done => setTimeout(done, 100)));
        const sees = async (told, asks, message) => {
            await page.waitForFunction(([told, asks]) => JSON.stringify(window.__told) === JSON.stringify(told) && window.__asksBeforeClosing() === asks, [told, asks], { timeout: 3000 }).catch(() => {});
            assert.deepEqual(await page.evaluate(() => ({ told: window.__told, asks: window.__asksBeforeClosing() })), { told, asks }, message);
        };

        await delivered();
        await sees([], false, 'a tool with nothing to lose says nothing');

        // A change is work from the edit, through the wait before the write and the write itself, until it lands.
        await addBlank(tool);
        await sees([true], true, 'held while the change waits');
        await writesStarted(1);
        await sees([true], true, 'held while it is written');
        await land(0);
        await sees([true, false], false, 'cleared once it is kept');
        assert.equal(await line(), 'Kept on this device');

        // A refused write keeps the work.
        await rename('Launch week');
        await sees([true, false, true], true);
        await writesStarted(2);
        await land(1, false);
        assert.match(await line(), /^Not kept/);
        await delivered();
        await sees([true, false, true], true, 'a refused write keeps the work');

        // Only the latest write clears it: not an earlier one landing after a later write started...
        await rename('Launch week, two');
        await writesStarted(3);
        await rename('Launch week, three');
        await writesStarted(4);
        await land(2);
        await delivered();
        await sees([true, false, true], true, 'an earlier write landing late changes nothing');
        await land(3);
        await sees([true, false, true, false], false, 'the latest write clears it');
        // ...nor one landing while a later change still waits to be written.
        await rename('Launch week, four');
        await writesStarted(5);
        await rename('Launch week, five');
        await land(4);
        await delivered();
        await sees([true, false, true, false, true], true, 'a change still waiting keeps the work');
        await writesStarted(6);
        await land(5);
        await sees([true, false, true, false, true, false], false);

        // A preset draft lives in memory until it is added: opening one is not work, editing it is, discarding it is not.
        const before = [true, false, true, false, true, false];
        await openDraft(tool);
        await delivered();
        await sees(before, false, 'an untouched draft is not work');
        await tool.locator('[data-inspector] [data-k="title"]').fill('Edited in the draft');
        await sees([...before, true], true, 'an edited draft is work');
        await tool.locator('[data-action="discard-draft"]').click();
        await sees([...before, true, false], false, 'a discarded draft leaves nothing to lose');
        assert.equal(await frame.evaluate(() => window.__writes.length), 6, 'a draft is not written to the device');

        // An edited draft stays work while an earlier write of the set lands; adding it hands the work to the set's write.
        const then = [...before, true, false];
        await rename('Launch week, six');
        await writesStarted(7);
        await openDraft(tool);
        await tool.locator('[data-inspector] [data-k="title"]').fill('Edited while the set was written');
        await land(6);
        await delivered();
        await sees([...then, true], true, 'the set was kept, but the draft is still work');
        await tool.locator('[data-action="commit-draft"]').click();
        await delivered();
        await sees([...then, true], true, 'the added draft waits for the set to be kept');
        await writesStarted(8);
        await land(7);
        await sees([...then, true, false], false);
        assert.equal(await tool.locator('[data-list] [data-id]').count(), 2);
        assert.deepEqual(errors, []);
        await page.close();
    } finally { await browser.close(); }
});
