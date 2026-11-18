// The visually hidden (sr-only) select-all checkboxes used to bind `checked` for BOTH partial and full
// selection, so assistive technology announced "Select all, checked" with only some items selected.
// They now bind `checked` for mode 'all' only and the native `indeterminate` property for 'partial'.
//
// This mounts the REAL select-all checkbox (and, where there is one, the real row checkbox) from each of
// the four Blade templates in Chromium, wired to the REAL handlers of the owning component, and checks
// none / partial / all for the checked and indeterminate properties, the accessible name and mixed state,
// and the keyboard (focus + Space) behavior.
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '../../..');
const source = (file) => fs.readFileSync(path.join(root, file), 'utf8');
const views = 'packages/Mothership/DAM/src/Resources/views';
const ROW_NAME = 'budget$&.png';

// Blade stand-ins: select-row carries its :name placeholder like the real English string.
const translate = (key) => (key.endsWith('.select-row') ? 'Select :name' : key.endsWith('.select-all') ? 'Select all' : 'Translation');
function blade(text) {
    return text
        .replace(/\{\{--[\s\S]*?--\}\}/g, '')
        .replace(/@lang\('([^']*)'\)/g, (_, key) => translate(key))
        .replace(/\{\{\s*__\('([^']*)'\)\s*\}\}/g, (_, key) => translate(key))
        .replace(/@\{\{/g, '{{');
}

// End of a tag that starts at `start`, skipping any `>` inside quotes (arrow functions live in attributes).
function tagEnd(text, start) {
    let quote = null;
    for (let index = start; index < text.length; index++) {
        const char = text[index];
        if (quote) { if (char === quote) quote = null; continue; }
        if (char === '"' || char === "'") quote = char;
        else if (char === '>') return index + 1;
    }
    throw new Error('unterminated tag');
}

// Every <input type="checkbox"> element of a file, in source order.
function inputs(file) {
    const text = source(file);
    const found = [];
    for (let at = text.indexOf('<input'); at >= 0; at = text.indexOf('<input', at + 1)) {
        const end = tagEnd(text, at);
        const tag = text.slice(at, end);
        if (/type="checkbox"/.test(tag)) found.push({ tag, at });
    }
    return { text, found };
}

// The select-all checkbox wrapped in its nearest enclosing <label>, or the row checkbox on its own.
function selectAll(file) {
    const { text, found } = inputs(file);
    const hits = found.filter((item) => /select-all'\)/.test(item.tag) || /select-all/.test(item.tag) && /aria-label/.test(item.tag));
    assert.equal(hits.length, 1, `${file} has exactly one select-all checkbox`);
    const start = text.lastIndexOf('<label', hits[0].at);
    const end = text.indexOf('</label>', hits[0].at) + '</label>'.length;
    return blade(text.slice(start, end));
}

function rowCheckbox(file) {
    const { found } = inputs(file);
    const hits = found.filter((item) => /select-row/.test(item.tag));
    assert.equal(hits.length, 1, `${file} has exactly one row checkbox`);
    return blade(hits[0].tag);
}

// A method of a component's script, as a source string, by brace matching.
function member(file, name) {
    const text = source(file);
    const start = text.search(new RegExp(`^\\s+${name}\\([^)]*\\)\\s*\\{`, 'm'));
    assert.ok(start >= 0, `${name} must exist in ${file}`);
    let depth = 0;
    for (let index = text.indexOf('{', start); index < text.length; index++) {
        if (text[index] === '{') depth++;
        if (text[index] === '}' && --depth === 0) return blade(text.slice(start, index + 1)).trim();
    }
    throw new Error(`${name} in ${file} is not closed`);
}

const gridFiles = {
    picker: `${views}/components/asset/picker/index.blade.php`,
    gallery: `${views}/components/datagrid/dam.blade.php`,
    tag: 'packages/Mothership/Admin/src/Resources/views/components/datagrid/index.blade.php',
};

// `scope` names what the template reads, resolved from the root (the owning component) in the page.
const cases = [
    {
        key: 'toolbar',
        kind: 'explorer',
        selectAll: selectAll(`${views}/components/explorer/toolbar.blade.php`),
        row: null,
        members: ['toggleSelectAll', 'clearSelection', 'computeSelectionMode'].map((name) => member(`${views}/components/explorer/tab.blade.php`, name)),
        scope: { dirs: 'r.dirs', assets: 'r.assets', selection: 'r.selection' },
        handlers: ['toggleSelectAll'],
    },
    {
        key: 'picker-modal',
        kind: 'grid',
        selectAll: selectAll(`${views}/asset/picker-modal.blade.php`),
        row: rowCheckbox(`${views}/asset/picker-modal.blade.php`),
        members: ['selectAllRecords', 'setCurrentSelectionMode'].map((name) => member(gridFiles.picker, name)),
        scope: { meta: 'r.applied.massActions.meta', applied: 'r.applied', record: 'r.record' },
        handlers: ['selectAllRecords', 'setCurrentSelectionMode'],
        rowMeta: 'r.available.meta',
    },
    {
        key: 'gallery',
        kind: 'grid',
        selectAll: selectAll(`${views}/components/datagrid/gallery.blade.php`),
        row: rowCheckbox(`${views}/components/datagrid/gallery.blade.php`),
        members: ['selectAllRecords', 'setCurrentSelectionMode'].map((name) => member(gridFiles.gallery, name)),
        scope: { record: 'r.record' },
        handlers: [],
        usesParent: true,
    },
    {
        key: 'tag',
        kind: 'grid',
        selectAll: selectAll(`${views}/tag/index.blade.php`),
        row: rowCheckbox(`${views}/tag/index.blade.php`),
        members: ['selectAllRecords', 'setCurrentSelectionMode'].map((name) => member(gridFiles.tag, name)),
        scope: { applied: 'r.applied', available: 'r.available', record: 'r.record' },
        handlers: ['selectAllRecords'],
    },
];

const browserTest = process.env.MOTHERSHIP_VUE_RUNTIME_PATH && process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH ? test : test.skip;

async function withPage(fn) {
    const { chromium } = require(process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH);
    const browser = await chromium.launch({ headless: true, channel: process.env.MOTHERSHIP_BROWSER_CHANNEL || 'chrome' });
    try {
        await fn(browser);
    } finally {
        await browser.close();
    }
}

// Mounts one case. The root component owns the real handlers and state; the select-all and row
// checkboxes are child components, so `$parent` (used by the gallery template) is the root as in the app.
async function mount(browser, item) {
    const page = await browser.newPage();
    await page.setContent('<main id="app"></main>');
    await page.addScriptTag({ path: process.env.MOTHERSHIP_VUE_RUNTIME_PATH });

    await page.evaluate(({ item, rowName }) => {
        const members = Object.assign({}, ...item.members.map((text) => new Function(`return ({ ${text} })`)()));
        const part = (template, scope, handlers, metaOverride) => ({
            template,
            computed: Object.fromEntries(Object.entries(scope).map(([name, expr]) => [name, function () { return new Function('r', `return ${expr}`)(this.$root); }])),
            data() { return {}; },
            methods: Object.fromEntries(handlers.map((name) => [name, function (...args) { return this.$root[name](...args); }])),
        });

        const records = [1, 2, 3].map((id) => ({ id, name: rowName, file_name: rowName }));
        const app = Vue.createApp({
            template: `<div><sel-part></sel-part>${item.row ? '<row-part></row-part>' : ''}</div>`,
            data: () => ({
                // explorer
                dirs: [{ id: 1 }],
                assets: [{ id: 1 }, { id: 2 }],
                selection: { ids: [], mode: 'none' },
                // datagrid
                available: { records, meta: { primary_column: 'id' }, massActions: [{}] },
                applied: { massActions: { indices: [], meta: { mode: 'none' } } },
                record: records[0],
            }),
            methods: {
                ...members,
                persistSelection() {},
                broadcastSelection() {},
            },
        });
        app.component('sel-part', part(item.selectAll, item.scope, item.handlers));
        if (item.row) {
            const rowScope = { record: 'r.record', applied: 'r.applied', available: 'r.available', meta: item.rowMeta || 'r.available.meta' };
            app.component('row-part', part(item.row, rowScope, ['setCurrentSelectionMode']));
        }
        window.vm = app.mount('#app');
        window.setSelected = (count) => {
            if (item.kind === 'explorer') {
                const all = [{ id: 1, type: 'directory' }, { id: 1, type: 'asset' }, { id: 2, type: 'asset' }];
                window.vm.selection.ids = all.slice(0, count);
                window.vm.computeSelectionMode();
            } else {
                window.vm.applied.massActions.indices = [1, 2, 3].slice(0, count);
                window.vm.setCurrentSelectionMode();
            }
        };
    }, { item, rowName: ROW_NAME });

    return page;
}

const STATES = [
    { label: 'none', count: 0, checked: false, indeterminate: false, aria: /^- checkbox "Select all"$/ },
    { label: 'partial', count: 1, checked: false, indeterminate: true, aria: /^- checkbox "Select all" \[checked=mixed\]$/ },
    { label: 'all', count: 3, checked: true, indeterminate: false, aria: /^- checkbox "Select all" \[checked\]$/ },
];

const box = (page) => page.getByRole('checkbox', { name: 'Select all', exact: true });
const props = (page) => page.evaluate(() => {
    const el = document.querySelector('input[type="checkbox"][aria-label="Select all"]');
    return { checked: el.checked, indeterminate: el.indeterminate };
});

for (const item of cases) {
    browserTest(`${item.key}: select-all exposes checked only for all and indeterminate only for partial`, () => withPage(async (browser) => {
        for (const state of STATES) {
            const page = await mount(browser, item);
            await page.evaluate((count) => window.setSelected(count), state.count);
            await page.evaluate(() => null);

            assert.deepEqual(await props(page), { checked: state.checked, indeterminate: state.indeterminate }, `${item.key} ${state.label}: DOM properties`);
            assert.equal(await box(page).count(), 1, `${item.key} ${state.label}: one checkbox named "Select all"`);
            assert.match((await box(page).ariaSnapshot()).trim(), state.aria, `${item.key} ${state.label}: accessible name and state`);
            await page.close();
        }
    }));

    browserTest(`${item.key}: Space on the focused select-all runs the real handler from every state`, () => withPage(async (browser) => {
        // none -> all, all -> none, partial -> none (the handler clears a partial selection, as before).
        for (const [from, expectMode, expectChecked] of [[0, 'all', true], [3, 'none', false], [1, 'none', false]]) {
            const page = await mount(browser, item);
            await page.evaluate((count) => window.setSelected(count), from);
            await page.evaluate(() => null);

            await box(page).focus();
            await page.keyboard.press('Space');
            await page.evaluate(() => null);

            const state = { mode: await page.evaluate((kind) => (kind === 'explorer' ? window.vm.selection.mode : window.vm.applied.massActions.meta.mode), item.kind) };
            assert.equal(state.mode, expectMode, `${item.key}: selection mode after Space from ${from} selected`);
            assert.deepEqual(await props(page), { checked: expectChecked, indeterminate: false }, `${item.key}: DOM properties after Space from ${from} selected`);
            await page.close();
        }
    }));

    if (item.row) {
        browserTest(`${item.key}: the row checkbox name is exactly "Select ${ROW_NAME}"`, () => withPage(async (browser) => {
            const page = await mount(browser, item);
            const rowBox = page.getByRole('checkbox', { name: `Select ${ROW_NAME}`, exact: true });
            assert.equal(await rowBox.count(), 1);
            assert.equal(await page.locator('input[type="checkbox"]').evaluateAll((all) => all.map((el) => el.getAttribute('aria-label')).filter((label) => label !== 'Select all')).then((labels) => labels.join('|')), `Select ${ROW_NAME}`);
            await page.close();
        }));
    }
}

// Native `indeterminate` leaves `checked` false, so the sighted partial icon must come from the
// `peer-indeterminate` variant. A `peer-checked` partial icon would match nothing and the control would
// look unselected while assistive technology announces "mixed".
const partialIconTemplates = [
    `${views}/components/explorer/toolbar.blade.php`,
    `${views}/asset/picker-modal.blade.php`,
    `${views}/components/datagrid/gallery.blade.php`,
    `${views}/tag/index.blade.php`,
    `${views}/new-uploads/index.blade.php`,
];

test('the partial-selection icon is driven by the peer-indeterminate variant in every select-all template', () => {
    for (const file of partialIconTemplates) {
        const text = source(file);
        assert.match(text, /peer-indeterminate:icon-checkbox-partial/, `${file} styles the partial state`);
        assert.doesNotMatch(text, /peer-checked:icon-checkbox-partial/, `${file} must not style partial through :checked`);
    }
});

test('the committed admin and DAM stylesheets ship the peer-indeterminate partial icon rule', () => {
    for (const build of ['public/themes/admin/default/build', 'public/themes/dam/build']) {
        const manifest = JSON.parse(source(`${build}/manifest.json`));
        const css = Object.values(manifest).flatMap((entry) => [entry.file, ...(entry.css || [])]).filter((file) => file.endsWith('.css')).map((file) => source(`${build}/${file}`)).join('\n');
        // The icon font classes are defined by the admin stylesheet, which every DAM page also loads.
        if (build.includes('admin')) assert.match(css, /:indeterminate~\.peer-indeterminate\\:icon-checkbox-partial:before/, `${build} has the partial icon rule`);
        assert.match(css, /:indeterminate~\.peer-indeterminate\\:text-primary-700\{/, `${build} has the partial colour rule`);
    }
});

browserTest('new-uploads: the real select-all handler reports none / partial / all natively and toggles by keyboard', () => withPage(async (browser) => {
    const file = `${views}/new-uploads/index.blade.php`;
    const { found } = inputs(file);
    const hit = found.filter((item) => /selectAllTitle/.test(item.tag) && /toggleAll/.test(item.tag));
    assert.equal(hit.length, 1, 'one select-all checkbox bound to toggleAll');
    const template = blade(hit[0].tag);
    const toggleAll = member(file, 'toggleAll');

    const page = await browser.newPage();
    await page.setContent('<main id="app"></main>');
    await page.addScriptTag({ path: process.env.MOTHERSHIP_VUE_RUNTIME_PATH });
    await page.evaluate(({ template, toggleAll }) => {
        const methods = new Function(`return ({ ${toggleAll} })`)();
        window.vm = Vue.createApp({
            template: `<div>${template}</div>`,
            data: () => ({ assets: [{ id: 1 }, { id: 2 }, { id: 3 }], selected: [], selectAllTitle: 'Select all uploads' }),
            methods,
        }).mount('#app');
    }, { template, toggleAll });

    const box = page.getByRole('checkbox', { name: 'Select all uploads', exact: true });
    const read = () => page.evaluate(() => { const el = document.querySelector('input[type="checkbox"]'); return { checked: el.checked, indeterminate: el.indeterminate }; });
    const set = (ids) => page.evaluate((ids) => { window.vm.selected = ids; }, ids);

    assert.deepEqual(await read(), { checked: false, indeterminate: false }, 'none');
    await set([1]);
    await page.evaluate(() => null);
    assert.deepEqual(await read(), { checked: false, indeterminate: true }, 'partial');
    assert.match((await box.ariaSnapshot()).trim(), /\[checked=mixed\]$/, 'partial is announced as mixed');
    await set([1, 2, 3]);
    await page.evaluate(() => null);
    assert.deepEqual(await read(), { checked: true, indeterminate: false }, 'all');

    await set([1]);
    await page.evaluate(() => null);
    await box.focus();
    await page.keyboard.press('Space');
    await page.evaluate(() => null);
    assert.equal(await page.evaluate(() => window.vm.selected.length), 3, 'Space from partial selects every upload (the handler\'s behaviour)');
    assert.deepEqual(await read(), { checked: true, indeterminate: false }, 'all after Space');
    await page.close();
}));
