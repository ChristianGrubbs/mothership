// The admin selection checkboxes (datagrid and history select-all and row checkboxes, the association
// product picker, the media card and the webhook log grid) were `<input class="peer hidden">`
// behind an icon-only label: display:none takes an input out of the Tab order, so a keyboard user could
// not select rows or select all. They are now visually hidden (sr-only) with a focus ring on the icon,
// and because an sr-only input is exposed to assistive technology while its label holds only an icon, each
// carries a localized accessible name (the item name, or "Select all") and the icon is aria-hidden.
//
// This renders the real label/checkbox markup from each Blade source (plus the real label-building
// methods), with the committed admin stylesheet, and drives it with the keyboard.
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '../../..');
const source = (file) => fs.readFileSync(path.join(root, file), 'utf8');

const views = 'packages/Mothership/Admin/src/Resources/views/components';
const files = {
    datagrid: `${views}/datagrid/table.blade.php`,
    history: `${views}/history/table.blade.php`,
    picker: `${views}/associations/product-picker.blade.php`,
    card: `${views}/media/card.blade.php`,
    webhook: 'packages/Mothership/Webhook/src/Resources/views/logs/_grid.blade.php',
};

// The English strings behind the keys, from the datagrid `table` block of the admin lang file.
const tableLang = source('packages/Mothership/Admin/src/Resources/lang/en_US/app.php').match(/'no-records-filtered'[\s\S]*?\],/)[0];
const translations = {
    'admin::app.components.datagrid.table.select-all': tableLang.match(/'select-all'\s*=>\s*'([^']*)'/)?.[1],
    'admin::app.components.datagrid.table.select-row': tableLang.match(/'select-row'\s*=>\s*'([^']*)'/)?.[1],
};

function blade(text) {
    return text
        .replace(/\{\{--[\s\S]*?--\}\}/g, '')
        .replace(/@json\(__\('([^']*)'\)\)/g, (_, key) => JSON.stringify(translations[key] ?? key))
        .replace(/@lang\('([^']*)'\)/g, (_, key) => translations[key] ?? key)
        .replace(/@\{\{/g, '{{');
}

// Every <label> that wraps a checkbox, as the browser would get it.
function checkboxLabels(file) {
    return (source(file).match(/<label[^>]*>(?:(?!<\/label>)[\s\S])*?type="checkbox"[\s\S]*?<\/label>/g) || []).map(blade);
}

// A method or computed property of the component's script, as a source string, by brace matching.
function extractMember(file, name) {
    const text = source(file);
    const start = text.search(new RegExp(`^\\s+${name}\\([^)]*\\)\\s*\\{`, 'm'));
    assert.ok(start >= 0, `${name} must exist in ${file}`);

    let depth = 0;
    for (let index = text.indexOf('{', start); index < text.length; index++) {
        if (text[index] === '{') depth++;
        if (text[index] === '}' && --depth === 0) {
            return blade(text.slice(start, index + 1)).trim();
        }
    }

    throw new Error(`${name} in ${file} is not closed`);
}

function adminStylesheet() {
    const manifest = JSON.parse(source('public/themes/admin/default/build/manifest.json'));
    const entry = Object.values(manifest).find((item) => typeof item.file === 'string' && item.file.endsWith('.css') && item.file.startsWith('assets/app-'));
    assert.ok(entry, 'the committed admin manifest must list a built assets/app-*.css entry');

    return path.join(root, 'public/themes/admin/default/build', entry.file);
}

// In Tab order. `members` are the component's own functions the markup calls.
const cases = () => {
    const grid = (name) => checkboxLabels(files[name]);
    const datagrid = grid('datagrid');
    const history = grid('history');

    assert.equal(datagrid.length, 2, 'the datagrid table has a select-all and a row checkbox');
    assert.equal(history.length, 2, 'the history table has a select-all and a row checkbox');
    assert.equal(grid('picker').length, 1);
    assert.equal(grid('card').length, 1);
    assert.equal(grid('webhook').length, 1);

    return [
        { key: 'datagrid', template: `<div>${datagrid.join('')}</div>`, members: [extractMember(files.datagrid, 'visibleColumns'), extractMember(files.datagrid, 'selectRowLabel')], names: ['Select all', 'Select Alpha'] },
        { key: 'history', template: `<div>${history.join('')}</div>`, members: [extractMember(files.history, 'selectRowLabel')], names: ['Select all', 'Select Alpha'] },
        {
            key: 'picker',
            template: `<div>${grid('picker')[0]}</div>`,
            members: ['pickerColumns', 'stripHtml', 'recordId', 'selectRowLabel'].map((name) => extractMember(files.picker, name)),
            names: ['Select Alpha'],
        },
        { key: 'card', template: `<div>${grid('card')[0]}</div>`, members: [], names: ['Select photo.png'] },
        { key: 'webhook', template: `<div>${grid('webhook')[0].replace(/\$refs\.datagrid/g, 'dg')}</div>`, members: [], names: ['Select 7'] },
    ];
};

async function mount(page) {
    await page.setContent('<main id="app"></main>');
    await page.addStyleTag({ path: adminStylesheet() });
    await page.addScriptTag({ path: process.env.MOTHERSHIP_VUE_RUNTIME_PATH });

    await page.evaluate((cases) => {
        const shared = {
            applied: { massActions: { indices: [], meta: { mode: 'none' } } },
            available: { meta: { primary_column: 'id' }, columns: [{ index: 'name', visible: true }], massActions: [{}], actions: [] },
        };
        const record = { id: 7, name: '<span>Alpha</span>' };

        window.app = Vue.createApp({
            template: `<div>${cases.map((item) => `<section><wrap-${item.key}></wrap-${item.key}></section>`).join('')}</div>`,
            data: () => shared,
            methods: { selectAllRecords() {} },
        });

        for (const item of cases) {
            const members = item.members.map((text) => new Function(`return ({ ${text} })`)());
            const merged = Object.assign({}, ...members);
            const computed = { visibleColumns: merged.visibleColumns };
            delete merged.visibleColumns;

            window.app.component(`wrap-${item.key}`, {
                template: item.template,
                data: () => ({
                    record,
                    meta: shared.available.meta,
                    columns: shared.available.columns,
                    selectable: true,
                    selected: false,
                    displayName: 'photo.png',
                    dg: shared,
                }),
                computed: computed.visibleColumns ? computed : {},
                methods: { ...merged, isSelected: () => false, toggleRecord() {} },
            });
        }

        window.vm = window.app.mount('#app');
    }, cases());
}

const vueTest = process.env.MOTHERSHIP_VUE_RUNTIME_PATH && process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH ? test : test.skip;

async function withPage(fn) {
    const { chromium } = require(process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH);
    const browser = await chromium.launch({ headless: true, channel: process.env.MOTHERSHIP_BROWSER_CHANNEL || 'chrome' });

    try {
        const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
        await mount(page);
        await fn(page);
    } finally {
        await browser.close();
    }
}

const expected = () => cases().flatMap((item) => item.names);

vueTest('Tab reaches every selection checkbox and each has an accessible name', () => withPage(async (page) => {
    const names = expected();
    assert.equal(names.length, 7);

    for (const name of names) {
        await page.keyboard.press('Tab');

        const focused = await page.evaluate(() => ({ tag: document.activeElement.tagName, type: document.activeElement.type }));
        assert.deepEqual(focused, { tag: 'INPUT', type: 'checkbox' }, `Tab must land on the "${name}" checkbox`);

        // getByRole computes the accessible name the way assistive technology does.
        const box = page.getByRole('checkbox', { name, exact: true });
        assert.equal(await box.count(), name === 'Select Alpha' ? 3 : name === 'Select all' ? 2 : 1, `"${name}" is exposed as a named checkbox`);
        assert.equal(await box.evaluateAll((all) => all.includes(document.activeElement)), true, `focus is on a checkbox named "${name}"`);
    }

    assert.equal(await page.getByRole('checkbox').count(), 7);
    assert.equal(await page.getByRole('checkbox', { name: /\S/ }).count(), 7, 'no selection checkbox is unnamed');
    assert.equal(await page.locator('.icon-checkbox-normal:not([aria-hidden="true"])').count(), 0, 'the decorative icons are hidden from assistive technology');
}));

vueTest('Space toggles a row checkbox from the keyboard', () => withPage(async (page) => {
    const row = page.locator('#app section').first().getByRole('checkbox', { name: 'Select Alpha' });

    await row.focus();
    await page.keyboard.press('Space');
    assert.equal(await row.isChecked(), true);
    assert.deepEqual(await page.evaluate(() => [...window.vm.applied.massActions.indices]), [7]);

    await page.keyboard.press('Space');
    assert.equal(await row.isChecked(), false);
    assert.deepEqual(await page.evaluate(() => [...window.vm.applied.massActions.indices]), []);
}));
