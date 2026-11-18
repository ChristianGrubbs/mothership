// Admin datagrid row actions are real controls (a link when they navigate, a button otherwise), and a
// confirmed action hands keyboard focus back after the grid reloads. These tests mount the REAL markup
// and scripts, extracted from the Blade sources the way confirm-modal-focus.test.cjs does:
//   - components/datagrid/index.blade.php (v-datagrid: performAction, refocusRowAction, the real reload)
//   - components/datagrid/table.blade.php (v-datagrid-table: the standard row and its action controls)
//   - components/history/table.blade.php  (v-history-table) with the real performAction of history/index.blade.php
//   - components/modal/confirm.blade.php  (the real confirm dialog, for the refocus flow)
//   - settings/users/index.blade.php      (a representative custom #body row with its own action buttons)
// Only the Blade-only pieces with no behaviour here (toolbar, shimmer, dropdown) are replaced by stubs.
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '../../..');
const views = 'packages/Mothership/Admin/src/Resources/views';
const source = (file) => fs.readFileSync(path.join(root, file), 'utf8');

const agreeLabels = { agree: 'Agree', disagree: 'Disagree' };

function blade(text) {
    return text
        .replace(/\{\{--[\s\S]*?--\}\}/g, '')
        .replace(/<x-admin::shimmer[^>]*\/>/g, '<div data-shimmer="true">Loading</div>')
        .replace(/@json\(__\('([^']*)'\)\)/g, (_, key) => JSON.stringify(key.endsWith('select-row') ? 'Select :name' : key))
        .replace(/@json\(trans\('[^']*'\)\)/g, '"Translation"')
        .replace(/\{\{ mothership_asset\('[^']*'\) \}\}/g, '/placeholder.svg')
        .replace(/@lang\('admin::app\.components\.modal\.(?:confirm|delete)\.(agree|disagree)-btn'\)/g, (_, which) => agreeLabels[which])
        .replace(/@lang\('([^']*)'\)/g, (_, key) => key.split('.').pop().replace(/^./, (c) => c.toUpperCase()))
        .replace(/@\{\{/g, '{{');
}

function xTemplate(file, id) {
    const match = source(file).match(new RegExp(`<script\\s+type="text/x-template"\\s+id="${id}"\\s*>([\\s\\S]*?)</script>`));
    assert.ok(match, `${id} must exist in ${file}`);
    return match[1];
}

function moduleScript(file) {
    const match = source(file).match(/<script type="module">([\s\S]*?)<\/script>/);
    assert.ok(match, `the module script must exist in ${file}`);
    return blade(match[1]);
}

// A method of the component's script, as a source string, by brace matching.
function extractMember(file, name) {
    const text = source(file);
    const start = text.search(new RegExp(`^\\s+${name}\\([^)]*\\)\\s*\\{`, 'm'));
    assert.ok(start >= 0, `${name} must exist in ${file}`);

    let depth = 0;
    for (let index = text.indexOf('{', start); index < text.length; index++) {
        if (text[index] === '{') depth++;
        if (text[index] === '}' && --depth === 0) return text.slice(start, index + 1).trim();
    }

    throw new Error(`${name} in ${file} is not closed`);
}

const files = {
    gridIndex: `${views}/components/datagrid/index.blade.php`,
    gridTable: `${views}/components/datagrid/table.blade.php`,
    historyIndex: `${views}/components/history/index.blade.php`,
    historyTable: `${views}/components/history/table.blade.php`,
    confirm: `${views}/components/modal/confirm.blade.php`,
    users: `${views}/settings/users/index.blade.php`,
};

function gridTemplate() {
    const template = blade(xTemplate(files.gridIndex, 'v-datagrid-template'))
        .replace('<x-admin::datagrid.toolbar />', '<v-filter-stub ref="filterDrawer"></v-filter-stub>')
        .replace(/<x-admin::datagrid\.table[^>]*>/, '<v-datagrid-table>')
        .replace('</x-admin::datagrid.table>', '</v-datagrid-table>');
    assert.ok(!/<x-admin::/.test(template), 'no Blade component may remain in the datagrid template');
    return template;
}

function tableTemplate(file, id) {
    // The select-all dropdown is a Blade component with no part in row actions.
    const template = blade(xTemplate(file, id).replace(/<x-admin::dropdown[\s\S]*?<\/x-admin::dropdown>/, ''));
    assert.ok(!/<x-admin::/.test(template), `no Blade component may remain in ${id}`);
    return template;
}

// The Users grid's own #body row: real buttons that call performAction / editModal from the slot scope.
function customRowTemplate() {
    const match = source(files.users).match(/<template #body="\{ columns, records, performAction, handleRowClick \}">([\s\S]*?)<\/template>\s*<\/x-admin::datagrid>/);
    assert.ok(match, 'the Users grid must have a #body row');
    return blade(match[1]);
}

const players = {
    edit: { index: 'edit', method: 'GET', title: 'Edit', icon: 'icon-edit' },
    share: { index: 'share', method: 'EDIT-SHARE', title: 'Share', icon: 'icon-share' },
    remove: { index: 'delete', method: 'DELETE', title: 'Delete', icon: 'icon-delete' },
    revoke: { index: 'revoke', method: 'POST', title: 'Revoke', icon: 'icon-revoke' },
};

function row(id, { skip = [] } = {}) {
    return {
        id,
        name: `Row ${id}`,
        actions: [
            { ...players.edit, url: `http://app.test/edit/${id}` },
            { ...players.share, url: `http://app.test/share/${id}` },
            { ...players.remove, url: `http://app.test/delete/${id}` },
            { ...players.revoke, url: `http://app.test/revoke/${id}` },
        ].filter((action) => !skip.includes(action.index)),
    };
}

function payload(records) {
    return {
        id: 1,
        columns: [{ index: 'name', label: 'Name', type: 'string', sortable: false, visible: true }],
        actions: [{ index: 'edit' }],
        mass_actions: [],
        records,
        meta: { primary_column: 'id', current_page: 1, last_page: 1, per_page: 10, total: records.length, per_page_options: [10, 25] },
    };
}

function userRow(id, { skip = [] } = {}) {
    return {
        id,
        user_id: id,
        user_name: `User ${id}`,
        user_img: null,
        status: '',
        email: `u${id}@example.test`,
        role_name: 'Admin',
        actions: [
            { index: 'edit', method: 'GET', url: `http://app.test/users/${id}/edit`, icon: 'icon-edit' },
            { index: 'delete', method: 'DELETE', url: `http://app.test/users/${id}`, icon: 'icon-delete' },
        ].filter((action) => !skip.includes(action.index)),
    };
}

const vueTest = process.env.MOTHERSHIP_VUE_RUNTIME_PATH && process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH ? test : test.skip;

async function withBrowser(fn) {
    const { chromium } = require(process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH);
    const browser = await chromium.launch({ headless: true, channel: process.env.MOTHERSHIP_BROWSER_CHANNEL || 'chrome' });

    try {
        const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
        await context.route('http://app.test/**', (route) => route.fulfill({ contentType: 'text/html', body: '<html><body>navigated</body></html>' }));
        const popups = [];
        context.on('page', (popup) => popups.push(popup));
        const page = await context.newPage();
        popups.length = 0;
        await page.goto('http://app.test/start');
        await fn({ page, popups });
    } finally {
        await browser.close();
    }
}

// Shared page scaffolding: Vue, the recording $emitter, the controllable $axios, and the log every
// observable dispatch goes to (emitter events, in-app visits, custom-row edit calls).
async function scaffold(page, { appTemplate, definitions, options = {} }) {
    // The icon font is not loaded here, so give the otherwise empty controls the icon's footprint.
    await page.setContent('<style>[data-row-action]{display:inline-block;width:24px;height:24px}</style><div id="app"></div>');
    await page.addScriptTag({ path: process.env.MOTHERSHIP_VUE_RUNTIME_PATH });

    await page.evaluate(({ definitions, appTemplate, options }) => {
        for (const [id, text] of Object.entries(definitions.templates)) {
            const script = document.createElement('script');
            script.type = 'text/x-template';
            script.id = id;
            script.textContent = text;
            document.body.append(script);
        }

        window.lockBodyScroll = () => {};
        window.unlockBodyScroll = () => {};
        window.log = [];
        window.gets = [];
        window.calls = [];
        if (options.spaVisit) window.mothership = { visit: (url) => window.log.push(['visit', url]) };

        const listeners = {};
        const emitter = {
            on: (event, handler) => (listeners[event] ??= []).push(handler),
            off: (event, handler) => (listeners[event] = (listeners[event] ?? []).filter((item) => item !== handler)),
            emit: (event, data) => {
                window.log.push([event, data?.url ?? data?.record?.id ?? data?.message ?? null]);
                (listeners[event] ?? []).forEach((handler) => handler(data));
            },
        };

        // Reloads stay pending until the test answers them; mutations answer at once.
        const axios = {
            get: (url) => new Promise((resolve) => window.gets.push(resolve)),
        };
        for (const method of ['post', 'put', 'patch', 'delete']) {
            axios[method] = (url) => {
                window.calls.push([method, url]);
                return Promise.resolve({ data: { message: 'Done' } });
            };
        }

        window.app = Vue.createApp({
            template: appTemplate,
            data: () => ({ id: 0 }),
            methods: { editModal: (url) => window.log.push(['editModal', url]) },
        });
        window.app.config.globalProperties.$emitter = emitter;
        window.app.config.globalProperties.$axios = axios;
    }, { definitions, appTemplate, options });

    await page.addScriptTag({ content: 'app.component("v-filter-stub", { template: "<i></i>", methods: { close() {} } });' });
    for (const script of definitions.scripts) await page.addScriptTag({ content: script });
}

async function mountGrid(page, { custom = false, withModal = false, spaVisit = true, records }) {
    const appTemplate = `<main>
        <button type="button" id="before">Before</button>
        <button type="button" id="other">Other</button>
        <v-datagrid src="/grid">${custom ? `<template #body="{ columns, records, performAction, handleRowClick }">${customRowTemplate()}</template>` : ''}</v-datagrid>
        ${withModal ? '<v-modal-confirm></v-modal-confirm>' : ''}
    </main>`;

    const templates = { 'v-datagrid-template': gridTemplate(), 'v-datagrid-table-template': tableTemplate(files.gridTable, 'v-datagrid-table-template') };
    const scripts = [moduleScript(files.gridIndex), moduleScript(files.gridTable)];
    if (withModal) {
        templates['v-modal-confirm-template'] = blade(xTemplate(files.confirm, 'v-modal-confirm-template'));
        scripts.push(moduleScript(files.confirm));
    }

    await scaffold(page, { appTemplate, definitions: { templates, scripts }, options: { spaVisit } });
    await page.evaluate(() => app.mount('#app'));
    await answerReload(page, records);
    await page.evaluate(() => { window.log.length = 0; });
}

// Answer the oldest pending grid reload (the first one is the initial load).
async function answerReload(page, records) {
    await page.waitForFunction(() => window.gets.length > 0);
    await page.evaluate(({ data }) => window.gets.shift()({ data }), { data: payload(records) });
    await page.waitForFunction(() => !document.querySelector('[data-shimmer]'));
}

async function mountHistory(page, { records, massActions = [] }) {
    const performAction = extractMember(files.historyIndex, 'performAction');
    const stub = `app.component('v-history-stub', {
        template: '<v-history-table></v-history-table>',
        data() {
            return {
                isLoading: false,
                available: {
                    columns: [{ index: 'name', label: 'Name' }],
                    actions: [{ index: 'view' }],
                    massActions: ${JSON.stringify(massActions)},
                    records: ${JSON.stringify(records)},
                    meta: { primary_column: 'id' },
                },
                applied: { sort: { column: null, order: 'asc' }, massActions: { meta: { mode: 'none' }, indices: [] } },
            };
        },
        methods: { sortPage() {}, selectAllRecords() {}, ${performAction} },
    });`;

    await scaffold(page, {
        appTemplate: '<main><button type="button" id="before">Before</button><v-history-stub></v-history-stub></main>',
        definitions: {
            templates: { 'v-history-table-template': tableTemplate(files.historyTable, 'v-history-table-template') },
            scripts: [moduleScript(files.historyTable), stub],
        },
    });
    await page.evaluate(() => app.mount('#app'));
}

const log = (page) => page.evaluate(() => window.log);
const active = (page) => page.evaluate(() => {
    const el = document.activeElement;
    if (el === document.body) return 'body';
    return el.closest('[data-row-key]') ? `${el.closest('[data-row-key]').dataset.rowKey}:${el.dataset.rowAction}` : el.id || el.tagName;
});
const settle = (page) => page.waitForTimeout(150);

// Record whether a handler cancelled the default action of the click on this element (registered after
// Vue's own listener on the same element, so it sees the final state).
async function probe(locator) {
    await locator.evaluate((el) => {
        window.prevented = [];
        if (el.probed) return;
        el.probed = true;
        for (const type of ['click', 'auxclick']) el.addEventListener(type, (event) => window.prevented.push([event.type, event.defaultPrevented]));
    });
}
const prevented = (page) => page.evaluate(() => window.prevented);

const cell = (page, rowKey, action) => page.locator(`[data-row-key="${rowKey}"] [data-row-action="${action}"]`);
const records = () => [row(1), row(2), row(3)];

// ---------------------------------------------------------------------------------------------
// Datagrid (standard rows)
// ---------------------------------------------------------------------------------------------

vueTest('datagrid row actions are named controls, in Tab order, with a link only for navigation', () => withBrowser(async ({ page }) => {
    await mountGrid(page, { records: records() });

    const first = page.locator('[data-row-key="1"]');
    assert.equal(await first.getByRole('link', { name: 'Edit' }).count(), 1);
    assert.equal(await first.getByRole('link', { name: 'Edit' }).getAttribute('href'), 'http://app.test/edit/1');
    for (const name of ['Share', 'Delete', 'Revoke']) {
        const button = first.getByRole('button', { name });
        assert.equal(await button.count(), 1, `${name} is a named button`);
        assert.equal(await button.getAttribute('type'), 'button');
        assert.equal(await button.getAttribute('href'), null);
    }

    await page.focus('#other');
    const order = [];
    for (let step = 0; step < 8; step++) {
        await page.keyboard.press('Tab');
        order.push(await active(page));
    }

    assert.deepEqual(order, ['1:edit', '1:share', '1:delete', '1:revoke', '2:edit', '2:share', '2:delete', '2:revoke']);
}));

vueTest('Enter on the link and Enter or Space on a button each dispatch exactly one action, never the row edit', () => withBrowser(async ({ page }) => {
    await mountGrid(page, { records: records() });

    await cell(page, 2, 'edit').focus();
    await page.keyboard.press('Enter');
    await settle(page);
    assert.deepEqual(await log(page), [['visit', 'http://app.test/edit/2']], 'Enter follows the link once, in-app');
    assert.equal(page.url(), 'http://app.test/start', 'the in-app visit replaces the native navigation');

    await page.evaluate(() => { window.log.length = 0; });
    await cell(page, 2, 'delete').focus();
    await page.keyboard.press('Space');
    await settle(page);
    assert.deepEqual(await log(page), [['open-delete-modal', null]]);

    await page.evaluate(() => { window.log.length = 0; });
    await cell(page, 3, 'revoke').focus();
    await page.keyboard.press('Enter');
    await settle(page);
    assert.deepEqual(await log(page), [['open-confirm-modal', null]]);

    await page.evaluate(() => { window.log.length = 0; });
    await cell(page, 1, 'share').focus();
    await page.keyboard.press('Enter');
    await settle(page);
    assert.deepEqual(await log(page), [['open-share-edit-modal', 'http://app.test/share/1']], 'the share action receives its own row');
}));

vueTest('a mouse click on an action dispatches exactly that action; a click elsewhere in the row is the edit handler', () => withBrowser(async ({ page }) => {
    await mountGrid(page, { records: records() });

    await cell(page, 1, 'delete').click();
    await settle(page);
    assert.deepEqual(await log(page), [['open-delete-modal', null]], 'one dispatch, and the row edit visit never fires');

    await page.evaluate(() => { window.log.length = 0; });
    await cell(page, 1, 'edit').click();
    await settle(page);
    assert.deepEqual(await log(page), [['visit', 'http://app.test/edit/1']], 'one visit, not two (link plus row)');

    // The control: the row's own handler does reach the edit action, so the spies above can see it.
    await page.evaluate(() => { window.log.length = 0; });
    await page.locator('[data-row-key="3"] p.truncate').click();
    await settle(page);
    assert.deepEqual(await log(page), [['visit', 'http://app.test/edit/3']]);
}));

vueTest('a plain click on a GET link falls back to a single location change when no in-app visit exists', () => withBrowser(async ({ page }) => {
    await mountGrid(page, { records: records(), spaVisit: false });

    const navigations = [];
    page.on('framenavigated', (frame) => navigations.push(frame.url()));
    await cell(page, 2, 'edit').click();
    await page.waitForURL('http://app.test/edit/2');
    await settle(page);

    assert.deepEqual(navigations.filter((url) => url !== 'http://app.test/start'), ['http://app.test/edit/2']);
}));

vueTest('a modified click or a middle click on a GET link is left to the browser: no in-app visit, no cancel, no row edit', () => withBrowser(async ({ page, popups }) => {
    await mountGrid(page, { records: records() });

    for (const modifier of ['Shift', 'Alt']) {
        await probe(cell(page, 2, 'edit'));
        await cell(page, 2, 'edit').click({ modifiers: [modifier] });
        await settle(page);
        assert.deepEqual(await prevented(page), [['click', false]], `${modifier}+click is not cancelled`);
    }
    assert.deepEqual(await log(page), [], 'no visit and no row edit for Shift or Alt');

    // Ctrl (Meta on macOS) and the middle button open a new tab, in the original page's context.
    for (const [label, options] of [['Ctrl/Cmd+click', { modifiers: ['ControlOrMeta'] }], ['middle click', { button: 'middle' }]]) {
        popups.length = 0;
        await probe(cell(page, 2, 'edit'));
        await cell(page, 2, 'edit').click(options);
        await settle(page);
        await page.waitForTimeout(300);

        assert.ok((await prevented(page)).every(([, cancelled]) => cancelled === false), `${label} is not cancelled`);
        assert.equal(popups.length, 1, `${label} opens the link in a new tab`);
        await popups[0].close();
    }

    assert.deepEqual(await log(page), [], 'a browser-handled click never reaches performAction or the row edit');
    assert.equal(page.url(), 'http://app.test/start');
}));

vueTest('a modified click on a button action still runs it once (only links defer to the browser)', () => withBrowser(async ({ page }) => {
    await mountGrid(page, { records: records() });

    await cell(page, 1, 'revoke').click({ modifiers: ['Shift'] });
    await settle(page);

    assert.deepEqual(await log(page), [['open-confirm-modal', null]]);
}));

// ---------------------------------------------------------------------------------------------
// A representative custom #body row (Users): buttons call performAction / editModal directly
// ---------------------------------------------------------------------------------------------

vueTest('custom row action buttons are named, tabbable, and dispatch exactly one action without the row edit', () => withBrowser(async ({ page }) => {
    await mountGrid(page, { custom: true, records: [userRow(1), userRow(2)] });

    assert.equal(await page.locator('[data-row-key="1"]').count(), 0, 'a custom row owns its markup; the standard row is not rendered');
    const buttons = page.locator('[data-row-action]');
    assert.equal(await buttons.count(), 4);
    assert.equal(await page.getByRole('button', { name: 'Edit' }).count(), 2);
    assert.equal(await page.getByRole('button', { name: 'Delete' }).count(), 2);

    await page.focus('#other');
    const order = [];
    for (let step = 0; step < 3; step++) {
        await page.keyboard.press('Tab');
        order.push(await page.evaluate(() => `${document.activeElement.dataset.rowAction ?? document.activeElement.textContent.trim()}`));
    }
    assert.deepEqual(order.slice(0, 1), ['U'], 'the avatar initial button comes first in the row');
    assert.deepEqual(order.slice(1), ['edit', 'delete']);

    await page.keyboard.press('Enter');
    await settle(page);
    assert.deepEqual(await log(page), [['open-delete-modal', null]], 'Enter on Delete dispatches one confirmation');

    await page.evaluate(() => { window.log.length = 0; });
    await page.locator('[data-row-action="edit"]').nth(1).click();
    await settle(page);
    assert.deepEqual(await log(page), [['editModal', 'http://app.test/users/2/edit']], 'Edit opens its modal once and never the row visit');
}));

// ---------------------------------------------------------------------------------------------
// History table: link actions, modal-GET actions, confirmed mutations
// ---------------------------------------------------------------------------------------------

const historyRecords = [{
    id: 1,
    name: 'Version 1',
    actions: [
        { index: 'view', method: 'GET', url: 'http://app.test/versions/1', title: 'View', icon: 'icon-view', frontend_view: 'view-modal' },
        { index: 'open', method: 'GET', url: 'http://app.test/versions/1/open', title: 'Open', icon: 'icon-open' },
        { index: 'restore', method: 'POST', url: 'http://app.test/versions/1/restore', title: 'Restore', icon: 'icon-restore' },
    ],
}];

vueTest('history modal-GET actions are buttons that open the modal once; plain GET actions are links', () => withBrowser(async ({ page }) => {
    await mountHistory(page, { records: historyRecords });

    const rowEl = page.locator('[data-row-action]').first();
    assert.equal(await rowEl.evaluate((el) => el.tagName), 'BUTTON', 'a view-modal action is a button, not a link');
    assert.equal(await page.getByRole('link', { name: 'Open' }).getAttribute('href'), 'http://app.test/versions/1/open');
    assert.equal(await page.getByRole('link', { name: 'View' }).count(), 0);

    await page.focus('#before');
    const order = [];
    for (let step = 0; step < 3; step++) {
        await page.keyboard.press('Tab');
        order.push(await page.evaluate(() => document.activeElement.dataset.rowAction));
    }
    assert.deepEqual(order, ['view', 'open', 'restore']);

    await page.focus('[data-row-action="view"]');
    await page.keyboard.press('Space');
    await settle(page);
    assert.deepEqual(await log(page), [['open-v-confirm-modal', 'http://app.test/versions/1']]);
    assert.equal(page.url(), 'http://app.test/start', 'a modal-GET never navigates');

    await page.evaluate(() => { window.log.length = 0; });
    await page.locator('[data-row-action="restore"]').click();
    await settle(page);
    assert.deepEqual(await log(page), [['open-confirm-modal', null]]);
}));

vueTest('history plain click on a link action navigates once; modified and middle clicks are left to the browser', () => withBrowser(async ({ page, popups }) => {
    await mountHistory(page, { records: historyRecords });
    const link = page.locator('[data-row-action="open"]');

    for (const modifier of ['Shift', 'Alt']) {
        await probe(link);
        await link.click({ modifiers: [modifier] });
        await settle(page);
        assert.deepEqual(await prevented(page), [['click', false]]);
    }

    for (const options of [{ modifiers: ['ControlOrMeta'] }, { button: 'middle' }]) {
        popups.length = 0;
        await probe(link);
        await link.click(options);
        await page.waitForTimeout(300);
        assert.ok((await prevented(page)).every(([, cancelled]) => cancelled === false));
        assert.equal(popups.length, 1, 'opens in a new tab');
        await popups[0].close();
    }
    assert.deepEqual(await log(page), []);
    assert.equal(page.url(), 'http://app.test/start');

    const navigations = [];
    page.on('framenavigated', (frame) => navigations.push(frame.url()));
    await link.click();
    await page.waitForURL('http://app.test/versions/1/open');
    await settle(page);
    assert.deepEqual(navigations.filter((url) => url !== 'http://app.test/start'), ['http://app.test/versions/1/open']);
}));

vueTest('history rows with mass actions render and name their selection checkbox', () => withBrowser(async ({ page }) => {
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    page.on('console', (message) => message.type() === 'error' || message.type() === 'warning' ? errors.push(message.text()) : null);

    await mountHistory(page, { records: historyRecords, massActions: [{ index: 'restore' }] });
    await settle(page);

    assert.deepEqual(errors.filter((text) => /selectRowLabel/.test(text)), [], 'selectRowLabel must be defined on the history table');
    assert.equal(await page.locator('[data-row-action]').count(), 3, 'the row still renders its actions');
    assert.equal(await page.getByRole('checkbox', { name: /Version 1/ }).count(), 1);
}));

// ---------------------------------------------------------------------------------------------
// Refocus after the confirmed action's real reload
// ---------------------------------------------------------------------------------------------

// Focus the action by keyboard, confirm it in the real dialog, and stop while the reload is pending
// (the grid is showing the loading shimmer in place of the rows).
async function confirmAndReachReload(page, rowKey, action) {
    await cell(page, rowKey, action).focus();
    await page.keyboard.press('Enter');
    await page.waitForSelector('[role="alertdialog"]');
    assert.equal(await page.evaluate(() => document.activeElement.textContent.trim()), 'Disagree');
    await page.keyboard.press('Tab');
    await page.keyboard.press('Enter');
    await page.waitForFunction(() => window.gets.length > 0);
    await page.waitForSelector('[data-shimmer]');

    assert.equal(await page.locator('[data-row-key]').count(), 0, 'the rows are replaced by the shimmer during the reload');
    assert.equal(await active(page), 'body', 'the reload drops focus from the action that ran');
}

const replaced = (page, nextRecords) => answerReload(page, nextRecords).then(() => page.waitForTimeout(100));

vueTest('after a confirmed action and its reload, focus returns to the same action of the surviving row', () => withBrowser(async ({ page }) => {
    await mountGrid(page, { withModal: true, records: records() });
    await confirmAndReachReload(page, 2, 'delete');
    assert.deepEqual(await page.evaluate(() => window.calls), [['delete', 'http://app.test/delete/2']]);

    // The server still lists row 2 (its delete is, say, soft) - the row is back with the same action.
    await replaced(page, [row(1), row(2), row(3)]);

    assert.equal(await active(page), '2:delete');
}));

vueTest('refocus follows the row, not its position, when the reload reorders or drops other rows', () => withBrowser(async ({ page }) => {
    await mountGrid(page, { withModal: true, records: records() });
    await confirmAndReachReload(page, 3, 'revoke');

    await replaced(page, [row(3), row(1)]);

    assert.equal(await active(page), '3:revoke');
}));

vueTest('focus is not restored when the row is gone after the reload', () => withBrowser(async ({ page }) => {
    await mountGrid(page, { withModal: true, records: records() });
    await confirmAndReachReload(page, 2, 'delete');

    await replaced(page, [row(1), row(3)]);

    assert.equal(await page.locator('[data-row-key="2"]').count(), 0);
    assert.equal(await active(page), 'body', 'no neighbouring row action receives the focus');
}));

vueTest('focus is not restored when the row survives but the action is gone', () => withBrowser(async ({ page }) => {
    await mountGrid(page, { withModal: true, records: records() });
    await confirmAndReachReload(page, 2, 'delete');

    await replaced(page, [row(1), row(2, { skip: ['delete'] }), row(3)]);

    assert.equal(await page.locator('[data-row-key="2"]').count(), 1);
    assert.equal(await active(page), 'body', 'a sibling action of the same row is not focused instead');
}));

vueTest('refocus never steals focus from a control the user moved to during the reload', () => withBrowser(async ({ page }) => {
    await mountGrid(page, { withModal: true, records: records() });
    await confirmAndReachReload(page, 2, 'delete');

    await page.focus('#other');
    await replaced(page, records());

    assert.equal(await active(page), 'other');
}));

vueTest('a failed confirmed mutation reloads nothing and leaves focus where the dialog returned it', () => withBrowser(async ({ page }) => {
    await mountGrid(page, { withModal: true, records: records() });
    await page.evaluate(() => {
        app.config.globalProperties.$axios.delete = () => Promise.reject({ response: { data: { message: 'Nope' } } });
    });

    await cell(page, 2, 'delete').focus();
    await page.keyboard.press('Enter');
    await page.waitForSelector('[role="alertdialog"]');
    await page.keyboard.press('Tab');
    await page.keyboard.press('Enter');
    await settle(page);

    assert.equal(await page.evaluate(() => window.gets.length), 0, 'no reload was started');
    assert.equal(await active(page), '2:delete');
}));
