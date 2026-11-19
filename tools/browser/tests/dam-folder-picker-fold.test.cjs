// The folder picker (packages/Mothership/DAM/src/Resources/views/components/explorer/folder-picker.blade.php) is the
// dialog that Move, Copy and the folder filters (Upload requests, Tag review) open. On a folding phone held open, a
// dialog centred on the whole screen sits across the fold, with its controls on it. This mounts the real component, as
// Laravel renders it (tools/browser/fixtures/blade.cjs), with the real public/pwa/device.js, and checks where it lands:
// book pose keeps it on the trailing segment (the one a dialog keeps when the device closes), tabletop on the lower
// segment, and with no fold it stays centred on the screen inside a 1rem margin, as before.
const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {bladeComponent} = require('../fixtures/blade.cjs');
const {launchBrowser} = require('../launch-browser.cjs');

const root = path.resolve(__dirname, '../../..');
const browserTest = process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH && process.env.MOTHERSHIP_VUE_RUNTIME_PATH ? test : test.skip;
const deviceJs = fs.readFileSync(path.join(root, 'public/pwa/device.js'), 'utf8');

const FOCUSABLE = 'button:not([disabled]), input:not([disabled]), select:not([disabled]), [href], [tabindex]:not([tabindex="-1"])';

// Segments as Safari on the iPhone Duo simulator reports them with its fold flags on ([left, top, width, height]):
// open in book pose 951 x 543 with the fold at x 475.5, and in tabletop 669 x 825 with the fold at y 349.5.
const BOOK = {width: 951, height: 543, segments: [[0, 0, 475.5, 543], [475.5, 0, 475.5, 543]]};
const TABLETOP = {width: 669, height: 825, segments: [[0, 0, 669, 349.5], [0, 349.5, 669, 475.5]]};
// With no fold: open flat, closed on its side, closed upright, and an ordinary phone.
const FLAT = [
    {name: 'open flat', width: 951, height: 543},
    {name: 'closed on its side', width: 678, height: 340},
    {name: 'closed upright', width: 382, height: 580},
    {name: 'an ordinary phone', width: 390, height: 844},
];

async function mount(page, {width, height, segments = null, dir = 'ltr'}) {
    await page.setViewportSize({width, height});
    const picker = bladeComponent('folder-picker');
    await page.route('http://mothership.test/**', async route => {
        const file = path.resolve(root, 'public', '.' + new URL(route.request().url()).pathname);
        if (file.startsWith(path.join(root, 'public') + path.sep) && fs.existsSync(file) && fs.statSync(file).isFile()) {
            await route.fulfill({path: file});
        } else await route.fulfill({status: 404, body: ''});
    });
    await page.setContent(`<base href="http://mothership.test/"><html dir="${dir}"><body style="margin:0"><div id="app"></div></body></html>`);
    await page.evaluate(({dir, segments}) => {
        document.documentElement.dir = dir;
        if (segments) {
            Object.defineProperty(window, 'viewport', {configurable: true, value: {segments: segments.map(rect => new DOMRect(...rect))}});
        }
    }, {dir, segments});
    await page.addScriptTag({path: process.env.MOTHERSHIP_VUE_RUNTIME_PATH});
    for (const name of ['admin/default', 'dam']) {
        const build = path.join(root, 'public/themes', name, 'build');
        const manifest = JSON.parse(fs.readFileSync(path.join(build, 'manifest.json')));
        for (const value of Object.values(manifest).filter(entry => entry.file?.endsWith('.css'))) {
            await page.addStyleTag({url: `http://mothership.test/themes/${name}/build/${value.file}`});
        }
    }
    await page.addScriptTag({content: deviceJs});
    await page.evaluate(() => { window.definitions = {}; window.app = {component(name, value) { definitions[name] = value; }}; });
    await page.addScriptTag({content: picker.script});
    await page.evaluate(({template}) => {
        const later = data => new Promise(resolve => setTimeout(() => resolve({data}), 20));
        const app = Vue.createApp({
            template: `<div>
                <button type="button" id="opener" @click="open = true">Move</button>
                <v-dam-folder-picker :open="open" tab-id="fixture" @close="open = false"></v-dam-folder-picker>
            </div>`,
            data: () => ({open: false}),
        });
        app.config.globalProperties.$axios = {
            get(url, options = {}) {
                const params = options.params ?? {};
                if (params.directory_id != null) {
                    return later({directories: Array.from({length: 12}, (_, i) => ({id: 10 + i, name: `Folder ${i + 1}`}))});
                }

                return later({data: [{id: 1, name: 'Library'}]});
            },
            post: () => later({data: {id: 9, name: 'New'}}),
        };
        app.component('v-dam-folder-picker', {...definitions['v-dam-folder-picker'], template});
        app.component('v-dam-explorer-view-toggle', {props: ['modelValue'], template: '<button type="button">View</button>'});
        app.mount('#app');
    }, {template: picker.template});

    await page.locator('#opener').click();
    await page.getByRole('button', {name: 'Folder 12'}).waitFor();
    await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
}

// The dialog's box, the scrim's box, every control the dialog shows, with its box, and the screen a fixed box fills. The
// admin theme keeps a stable scrollbar gutter (html { scrollbar-gutter: stable }), so where the browser draws classic
// scrollbars (a desktop, and CI) that screen is narrower than the window by the gutter. Phones overlay their scrollbars.
const layout = page => page.evaluate(selector => {
    const box = el => { const r = el.getBoundingClientRect(); return {left: r.left, top: r.top, right: r.right, bottom: r.bottom}; };
    const dialog = document.querySelector('[data-folder-picker] [role="dialog"]');
    const scrim = document.querySelector('[data-folder-picker] > .bg-black\\/50');

    return {
        screen: {width: document.documentElement.clientWidth, height: document.documentElement.clientHeight},
        fold: document.documentElement.dataset.fold ?? null,
        dialog: box(dialog),
        scrim: box(scrim),
        controls: [...dialog.querySelectorAll(selector)].filter(el => el.getClientRects().length > 0)
            .map(el => ({name: el.textContent.trim() || el.getAttribute('aria-label') || el.tagName, ...box(el)})),
    };
}, FOCUSABLE);

async function withPage(run) {
    const browser = await launchBrowser({playwrightPath: process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH});
    const page = await browser.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    page.on('console', message => { if (message.type() === 'warning' || message.type() === 'error') errors.push(message.text()); });
    try {
        await run(page);
        assert.deepEqual(errors, []);
    } finally { await browser.close(); }
}

// The dialog keeps a 1rem margin inside the area it belongs to; half a pixel allows for Safari's fractional fold.
const assertInside = (dialog, area, label) => {
    assert.ok(dialog.left >= area.left + 15.5 && dialog.right <= area.right - 15.5, `${label}: the dialog's sides (${dialog.left}–${dialog.right}) are 1rem inside ${area.left}–${area.right}`);
    assert.ok(dialog.top >= area.top + 15.5 && dialog.bottom <= area.bottom - 15.5, `${label}: the dialog's top and foot (${dialog.top}–${dialog.bottom}) are 1rem inside ${area.top}–${area.bottom}`);
};
const segmentBox = ([left, top, width, height]) => ({left, top, right: left + width, bottom: top + height});
const assertScrimCovers = (scrim, {width, height}, label) => assert.deepEqual(scrim, {left: 0, top: 0, right: width, bottom: height}, `${label}: the scrim still dims the whole screen`);

browserTest('book pose puts the folder picker on the trailing segment, with no control on the fold', async () => {
    for (const dir of ['ltr', 'rtl']) {
        await withPage(async page => {
            await mount(page, {...BOOK, dir});
            const {screen, fold, dialog, scrim, controls} = await layout(page);
            assert.equal(fold, 'columns');
            // Right in left-to-right, left in right-to-left (device.js: --seg-trailing-*).
            const trailing = segmentBox(BOOK.segments[dir === 'rtl' ? 0 : 1]);
            assertInside(dialog, trailing, `book ${dir}`);
            assertScrimCovers(scrim, screen, `book ${dir}`);
            const foldX = BOOK.segments[0][2];
            assert.deepEqual(controls.filter(c => c.left < foldX && c.right > foldX).map(c => c.name), [], `book ${dir}: no control crosses the fold`);
            assert.ok(controls.length >= 7, 'the controls were measured');
        });
    }
});

browserTest('tabletop puts the folder picker on the lower segment, with no control on the fold', () => withPage(async page => {
    await mount(page, TABLETOP);
    const {screen, fold, dialog, scrim, controls} = await layout(page);
    assert.equal(fold, 'rows');
    assertInside(dialog, segmentBox(TABLETOP.segments[1]), 'tabletop');
    assertScrimCovers(scrim, screen, 'tabletop');
    const foldY = TABLETOP.segments[1][1];
    assert.deepEqual(controls.filter(c => c.top < foldY && c.bottom > foldY).map(c => c.name), [], 'tabletop: no control crosses the fold');
}));

browserTest('with no fold the folder picker stays centred on the screen', async () => {
    for (const size of FLAT) {
        await withPage(async page => {
            await mount(page, size);
            const {screen, fold, dialog, scrim} = await layout(page);
            assert.equal(fold, null, `${size.name}: no fold`);
            // The 1rem margin holds on the screen itself, with or without a classic scrollbar's gutter.
            assertInside(dialog, {left: 0, top: 0, right: screen.width, bottom: screen.height}, size.name);
            assert.ok(Math.abs((dialog.left + dialog.right) / 2 - screen.width / 2) <= 0.5, `${size.name}: centred across`);
            assert.ok(Math.abs((dialog.top + dialog.bottom) / 2 - screen.height / 2) <= 0.5, `${size.name}: centred down`);
            // The picker's own size where the screen allows it: 360 wide, 520 tall. WebKit lays out in 1/64 px steps.
            const near = (actual, expected, what) => assert.ok(Math.abs(actual - expected) <= 0.5, `${size.name}: ${what} ${actual}, expected ${expected}`);
            near(dialog.right - dialog.left, Math.min(360, screen.width - 32), 'width');
            near(dialog.bottom - dialog.top, Math.min(520, screen.height - 32), 'height');
            assertScrimCovers(scrim, screen, size.name);
        });
    }
});
