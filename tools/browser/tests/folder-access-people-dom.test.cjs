// The folder-access panel's "People with access" list (packages/Mothership/DAM/src/Resources/views/components/
// folder-access.blade.php, used under the `grants` access model), driven in a real browser against the
// shipped template and app.component('v-folder-access', {...}) registration behind a stubbed $axios.
//
// Blade cannot run here, so the few directives the file uses are resolved the way
// share-dialog-access-dom.test.cjs resolves them: every dam::app.admin.dam.access key becomes its real
// en_US string, and the two server URLs become fixture paths.
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '../../..');
const source = (file) => fs.readFileSync(path.join(root, file), 'utf8');

function accessStrings() {
    const php = source('packages/Mothership/DAM/src/Resources/lang/en_US/app.php');
    const start = php.indexOf("'access' => [");
    const end = php.indexOf('],', start);
    assert.ok(start !== -1 && end !== -1, "the 'access' lang block must exist in en_US/app.php");

    const strings = {};
    for (const [, key, value] of php.slice(start, end).matchAll(/'([a-z0-9-]+)'\s*=>\s*'((?:[^'\\]|\\.)*)'/g)) {
        strings[key] = value.replace(/\\'/g, "'").replace(/\\\\/g, '\\');
    }

    for (const key of ['people-heading', 'people-hint', 'has-access']) {
        assert.ok(strings[key], `dam::app.admin.dam.access.${key} must exist`);
    }

    return strings;
}

const strings = accessStrings();

function blade(text) {
    return text
        // Blade drops its own comments before the page is sent; left in, Vue would read `{{-- … --}}` as interpolation.
        .replace(/\{\{--[\s\S]*?--\}\}/g, '')
        .replace(/@json\(trans\('dam::app\.admin\.dam\.access\.([a-z0-9-]+)'\)\)/g, (_, key) => JSON.stringify(strings[key] ?? key))
        .replace(/@lang\('dam::app\.admin\.dam\.access\.([a-z0-9-]+)'\)/g, (_, key) => strings[key] ?? key)
        .replace(/@json\(url\([^\n]*?\)\)/g, '"/fixture/dam/directory"')
        .replace(/\{\{\s*route\('([^']+)'\)\s*\}\}/g, (_, name) => `/fixture/${name}`)
        .replace(/@\{\{/g, '{{');
}

function component() {
    const php = source('packages/Mothership/DAM/src/Resources/views/components/folder-access.blade.php');
    const template = php.match(/<script type="text\/x-template" id="v-folder-access-template">([\s\S]*?)<\/script>/);
    assert.ok(template, 'v-folder-access-template must exist');
    const registration = php.match(/<script type="module">([\s\S]*?)<\/script>/);
    assert.ok(registration, "app.component('v-folder-access', ...) must exist");

    return { template: blade(template[1]), script: blade(registration[1]) };
}

const snapshot = (people) => ({
    directory: { id: 7, name: 'Campus photos' },
    users: [{ type: 'user', id: 9, name: 'Sam Friend', inherit_children: false }],
    groups: [],
    sources: [],
    people,
    available_users: [{ id: 9, name: 'Sam Friend', email: 'sam@example.test' }, { id: 12, name: 'Rae Other', email: 'rae@example.test' }],
    available_groups: [],
});

/** Mounts the shipped component; every GET is answered from `gets` in order, `null` meaning a failure. */
async function mount(browser, gets) {
    const page = await browser.newPage();
    await page.route('http://access.test/**', (route) => route.fulfill({ contentType: 'text/html', body: '<div id="app"><v-folder-access></v-folder-access></div>' }));
    await page.goto('http://access.test/library');
    await page.addScriptTag({ path: process.env.MOTHERSHIP_VUE_RUNTIME_PATH });

    await page.evaluate(({ template, script, gets }) => {
        const node = document.createElement('script');
        node.type = 'text/x-template';
        node.id = 'v-folder-access-template';
        node.textContent = template;
        document.body.append(node);

        const listeners = {};
        const emitter = {
            on(event, handler) { (listeners[event] ??= []).push(handler); },
            off(event, handler) { listeners[event] = (listeners[event] ?? []).filter((h) => h !== handler); },
            emit(event, payload) { (listeners[event] ?? []).forEach((handler) => handler(payload)); },
        };

        window.__puts = [];
        const queue = [...gets];
        const app = Vue.createApp({});
        app.config.globalProperties.$emitter = emitter;
        app.config.globalProperties.$axios = {
            get: () => {
                const next = queue.shift();

                return next === null ? Promise.reject(new Error('refresh failed')) : Promise.resolve({ data: next });
            },
            put: (url, body) => { window.__puts.push({ url, body }); return Promise.resolve({ data: {} }); },
        };

        new Function('app', script)(app);
        app.mount('#app');
        emitter.emit('dam:manage-access', { id: 7, name: 'Campus photos' });
    }, { ...component(), gets });

    return page;
}

const browserTest = process.env.MOTHERSHIP_VUE_RUNTIME_PATH && process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH ? test : test.skip;
const launch = () => require(process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH).chromium.launch({ headless: true, channel: process.env.MOTHERSHIP_BROWSER_CHANNEL || 'chrome' });

browserTest('the panel lists who has access and marks them in the picker', async () => {
    const browser = await launch();

    try {
        const page = await mount(browser, [snapshot([{ id: 12, name: 'Rae Other', email: 'rae@example.test', reasons: ['via group Studio crew'] }])]);

        await page.locator('[data-dam-access-person]').first().waitFor();
        assert.ok((await page.locator('[data-dam-access-people]').textContent()).includes(strings['people-heading']));
        assert.equal(await page.locator('[data-dam-access-person]').count(), 1);

        await page.locator('#access-search').fill('rae');
        const option = (await page.locator('button:has-text("Rae Other")').textContent()).replace(/\s+/g, ' ');
        assert.ok(option.includes(strings['has-access'].replace(':reason', 'via group Studio crew')), option);

        await page.close();
    } finally {
        await browser.close();
    }
});

browserTest('a save whose refresh fails clears the list and says so, rather than showing it as current', async () => {
    const browser = await launch();

    try {
        const person = { id: 9, name: 'Sam Friend', email: 'sam@example.test', reasons: ['Direct'] };
        const page = await mount(browser, [snapshot([person]), null]);

        await page.locator('[data-dam-access-person]').first().waitFor();
        await page.locator('button[aria-label="Remove access for Sam Friend"]').click();
        await page.locator('button:has-text("Save access")').click();

        await page.locator('[role="alert"]').waitFor();
        assert.match(await page.locator('[role="alert"]').textContent(), /could not be refreshed/);
        assert.equal(await page.locator('[data-dam-access-person]').count(), 0);
        assert.deepEqual(await page.evaluate(() => window.__puts.map((put) => put.body)), [{ users: [], groups: [] }]);

        await page.close();
    } finally {
        await browser.close();
    }
});

browserTest('a save whose refresh succeeds shows the new list', async () => {
    const browser = await launch();

    try {
        const person = { id: 9, name: 'Sam Friend', email: 'sam@example.test', reasons: ['Direct'] };
        const page = await mount(browser, [snapshot([person]), snapshot([])]);

        await page.locator('[data-dam-access-person]').first().waitFor();
        await page.locator('button[aria-label="Remove access for Sam Friend"]').click();
        await page.locator('button:has-text("Save access")').click();

        await page.locator('text=Access saved.').waitFor();
        assert.equal(await page.locator('[data-dam-access-person]').count(), 0);
        assert.equal(await page.locator('[role="alert"]').count(), 0);

        await page.close();
    } finally {
        await browser.close();
    }
});
