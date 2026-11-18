// The folder picker (packages/Mothership/DAM/src/Resources/views/components/explorer/folder-picker.blade.php) is a
// modal dialog that Move, Copy and the folder filters open over a page that has its own Escape and Tab behaviour.
// This mounts the real component, as Laravel renders it (tools/browser/fixtures/blade.cjs), and drives it with the
// keyboard: where focus lands on opening, that Tab and Shift+Tab stay inside, that Escape clears a typed search before
// it closes the picker and never reaches the page behind, that focus stays in the picker when the control that had it
// goes away, and that closing hands focus back to the control that opened it.
const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {bladeComponent} = require('../fixtures/blade.cjs');
const {launchBrowser} = require('../launch-browser.cjs');

const root = path.resolve(__dirname, '../../..');
const browserTest = process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH && process.env.MOTHERSHIP_VUE_RUNTIME_PATH ? test : test.skip;

// The selector the picker's own focus trap uses.
const FOCUSABLE = 'button:not([disabled]), input:not([disabled]), select:not([disabled]), [href], [tabindex]:not([tabindex="-1"])';

async function mount(page) {
    await page.setViewportSize({width: 1280, height: 800});
    const picker = bladeComponent('folder-picker');
    await page.route('http://mothership.test/**', async route => {
        const file = path.resolve(root, 'public', '.' + new URL(route.request().url()).pathname);
        if (file.startsWith(path.join(root, 'public') + path.sep) && fs.existsSync(file) && fs.statSync(file).isFile()) {
            await route.fulfill({path: file});
        } else await route.fulfill({status: 404, body: ''});
    });
    await page.setContent('<base href="http://mothership.test/"><body style="margin:0;padding:16px"><div id="app"></div>');
    await page.addScriptTag({path: process.env.MOTHERSHIP_VUE_RUNTIME_PATH});
    for (const name of ['admin/default', 'dam']) {
        const build = path.join(root, 'public/themes', name, 'build');
        const manifest = JSON.parse(fs.readFileSync(path.join(build, 'manifest.json')));
        for (const value of Object.values(manifest).filter(entry => entry.file?.endsWith('.css'))) {
            await page.addStyleTag({url: `http://mothership.test/themes/${name}/build/${value.file}`});
        }
    }
    await page.evaluate(() => { window.definitions = {}; window.app = {component(name, value) { definitions[name] = value; }}; });
    await page.addScriptTag({content: picker.script});
    await page.evaluate(({template}) => {
        // The page behind: its Escape handler (the Library drops its selection) ignores an event another control
        // already handled, exactly as components/explorer/index.blade.php does.
        window.pageEscapes = 0;
        document.addEventListener('keydown', event => { if (event.key === 'Escape' && ! event.defaultPrevented) window.pageEscapes++; });

        const folders = {1: [{id: 2, name: 'Campaigns'}, {id: 3, name: 'Press'}], 2: [{id: 4, name: 'Autumn'}]};
        const later = data => new Promise(resolve => setTimeout(() => resolve({data}), 20));
        const app = Vue.createApp({
            template: `<div>
                <button type="button" id="opener" @click="open = true">Move</button>
                <v-dam-folder-picker :open="open" tab-id="fixture" @close="closed++; open = false" @picked="picked = $event; open = false"></v-dam-folder-picker>
            </div>`,
            data: () => ({open: false, closed: 0, picked: null}),
        });
        app.config.globalProperties.$axios = {
            // Root first (no parameters), then a folder's children, or a search.
            get(url, options = {}) {
                const params = options.params ?? {};
                if (params.q != null) return later({data: [{id: 2, name: 'Campaigns', parent_id: 1, path_names: ['Library', 'Campaigns'], path_ids: [1, 2]}]});
                if (params.directory_id != null) return later({directories: folders[params.directory_id] ?? []});

                return later({data: [{id: 1, name: 'Library'}]});
            },
            post: () => later({data: {id: 9, name: 'New'}}),
        };
        app.component('v-dam-folder-picker', {...definitions['v-dam-folder-picker'], template});
        app.component('v-dam-explorer-view-toggle', {props: ['modelValue'], template: '<span></span>'});
        window.vm = app.mount('#app');
    }, {template: picker.template});
}

async function openPicker(page) {
    await page.locator('#opener').click();
    // Focus lands in the search once the picker is up, and the first folders arrive.
    await page.waitForFunction(() => document.activeElement?.matches('[data-folder-picker] input[type="text"]'));
    await page.getByRole('button', {name: 'Campaigns'}).waitFor();
}

// Where focus is among the picker's focusable controls: its index, how many there are, and what holds it.
const focusState = page => page.evaluate(selector => {
    const dialog = document.querySelector('[data-folder-picker] [role="dialog"]');
    const focusable = dialog ? [...dialog.querySelectorAll(selector)].filter(el => el.getClientRects().length > 0) : [];

    return {
        open: !! dialog,
        index: focusable.indexOf(document.activeElement),
        count: focusable.length,
        onDialog: !! dialog && document.activeElement === dialog,
        active: document.activeElement?.id || document.activeElement?.tagName,
        pageEscapes: window.pageEscapes,
        closed: vm.closed,
    };
}, FOCUSABLE);

const focusAt = (page, index) => page.evaluate(({selector, index}) => {
    const dialog = document.querySelector('[data-folder-picker] [role="dialog"]');
    const focusable = [...dialog.querySelectorAll(selector)].filter(el => el.getClientRects().length > 0);
    focusable.at(index).focus();
}, {selector: FOCUSABLE, index});

async function withPicker(run) {
    const browser = await launchBrowser({playwrightPath: process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH});
    const page = await browser.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    page.on('console', message => { if (message.type() === 'warning' || message.type() === 'error') errors.push(message.text()); });
    try {
        await mount(page);
        await run(page);
        assert.deepEqual(errors, []);
    } finally { await browser.close(); }
}

browserTest('the folder picker opens as a named dialog with focus in its search, keeps Tab inside, and returns focus on close', () => withPicker(async page => {
    await openPicker(page);

    const dialog = page.locator('[data-folder-picker] [role="dialog"]');
    assert.equal(await dialog.getAttribute('aria-modal'), 'true');
    const title = await page.evaluate(() => document.getElementById(document.querySelector('[data-folder-picker] [role="dialog"]').getAttribute('aria-labelledby'))?.textContent.trim());
    assert.ok(title, 'the dialog is named by its visible title');

    // Shift+Tab on the first control goes to the last, and Tab on the last goes back to the first.
    await focusAt(page, 0);
    await page.keyboard.press('Shift+Tab');
    let state = await focusState(page);
    assert.equal(state.index, state.count - 1, 'Shift+Tab on the first control wraps to the last');
    await page.keyboard.press('Tab');
    state = await focusState(page);
    assert.equal(state.index, 0, 'Tab on the last control wraps to the first');

    // In between, Tab moves on by one and stays inside.
    await page.keyboard.press('Tab');
    state = await focusState(page);
    assert.equal(state.index, 1, 'Tab moves to the next control inside the picker');

    await page.keyboard.press('Escape');
    await page.locator('[data-folder-picker]').waitFor({state: 'detached'});
    state = await focusState(page);
    assert.deepEqual({closed: state.closed, active: state.active, pageEscapes: state.pageEscapes}, {closed: 1, active: 'opener', pageEscapes: 0},
        'Escape closes the picker, hands focus back to the control that opened it, and does not reach the page');
}));

browserTest('Escape in the folder picker clears a typed search first and closes on the next press, without reaching the page', () => withPicker(async page => {
    await openPicker(page);

    const search = page.locator('[data-folder-picker] input[type="text"]').first();
    await page.keyboard.type('cam');
    await page.getByText('Library › Campaigns').waitFor();

    await page.keyboard.press('Escape');
    await page.getByRole('button', {name: 'Press'}).waitFor();
    let state = await focusState(page);
    assert.equal(await search.inputValue(), '', 'the first Escape clears the search');
    assert.deepEqual({open: state.open, closed: state.closed, pageEscapes: state.pageEscapes}, {open: true, closed: 0, pageEscapes: 0},
        'the picker stays open and the page behind is not told');
    assert.equal(await search.evaluate(el => el === document.activeElement), true, 'focus stays in the search');

    await page.keyboard.press('Escape');
    await page.locator('[data-folder-picker]').waitFor({state: 'detached'});
    state = await focusState(page);
    assert.deepEqual({closed: state.closed, active: state.active, pageEscapes: state.pageEscapes}, {closed: 1, active: 'opener', pageEscapes: 0});
}));

browserTest('focus stays in the folder picker when the control that had it goes away', () => withPicker(async page => {
    await openPicker(page);

    // Opening a folder replaces the list, so the button that was pressed is gone.
    await page.getByRole('button', {name: 'Campaigns'}).focus();
    await page.keyboard.press('Enter');
    await page.getByRole('button', {name: 'Autumn'}).waitFor();
    let state = await focusState(page);
    assert.equal(state.onDialog, true, 'focus moves to the picker itself after opening a folder');
    await page.keyboard.press('Shift+Tab');
    state = await focusState(page);
    assert.equal(state.index, state.count - 1, 'Shift+Tab from the picker itself goes to its last control');
    await page.keyboard.press('Tab');
    assert.equal((await focusState(page)).index, 0, 'and Tab wraps to the first');

    // A new folder that is cancelled takes its name field away.
    await page.locator('[data-folder-picker] button:has(.icon-dam-add-folder)').click();
    await page.waitForFunction(() => document.activeElement?.matches('[data-folder-picker] input[type="text"]') && document.activeElement !== document.querySelector('[data-folder-picker] input[type="text"]'));
    await page.keyboard.press('Escape');
    await page.locator('[data-folder-picker] button:has(.icon-dam-add-folder)').waitFor();
    state = await focusState(page);
    assert.deepEqual({open: state.open, onDialog: state.onDialog, closed: state.closed, pageEscapes: state.pageEscapes}, {open: true, onDialog: true, closed: 0, pageEscapes: 0},
        'cancelling the new folder keeps the picker open with focus on it');

    await page.keyboard.press('Escape');
    await page.locator('[data-folder-picker]').waitFor({state: 'detached'});
    state = await focusState(page);
    assert.deepEqual({closed: state.closed, active: state.active, pageEscapes: state.pageEscapes}, {closed: 1, active: 'opener', pageEscapes: 0});
}));
