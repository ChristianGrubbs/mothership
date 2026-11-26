const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {bladeComponent, bladeFixture} = require('../fixtures/blade.cjs');
const {launchBrowser} = require('../launch-browser.cjs');

const root = path.resolve(__dirname, '../../..');
const browserTest = process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH && process.env.MOTHERSHIP_VUE_RUNTIME_PATH ? test : test.skip;

async function mount(page, {mode, width}) {
    await page.setViewportSize({width, height: 720});
    const tab = bladeComponent('explorer-tab');
    const grid = bladeComponent('explorer-grid');
    const list = bladeComponent('explorer-list');
    const binding = name => {
        const match = tab.template.match(new RegExp(`<v-dam-explorer-${name}\\b[\\s\\S]*?<\\/v-dam-explorer-${name}>`));
        assert.ok(match, `rendered tab binds the real ${name}`);
        return match[0];
    };
    const template = `<main class="p-4 bg-white dark:bg-cherry-900 rounded-lg box-shadow">${binding('grid')}${binding('list')}</main>`;
    await page.route('http://mothership.test/**', async route => {
        const file = path.resolve(root, 'public', '.' + new URL(route.request().url()).pathname);
        if (file.startsWith(path.join(root, 'public') + path.sep) && fs.existsSync(file) && fs.statSync(file).isFile()) {
            await route.fulfill({path: file});
        } else await route.fulfill({status: 404, body: ''});
    });
    await page.setContent('<base href="http://mothership.test/"><body style="margin:0;padding:16px;background:#f4f4f5"><div id="app"></div>');
    await page.addScriptTag({path: process.env.MOTHERSHIP_VUE_RUNTIME_PATH});
    for (const name of ['admin/default', 'dam']) {
        const build = path.join(root, 'public/themes', name, 'build');
        const manifest = JSON.parse(fs.readFileSync(path.join(build, 'manifest.json')));
        for (const value of Object.values(manifest).filter(entry => entry.file?.endsWith('.css'))) {
            await page.addStyleTag({url: `http://mothership.test/themes/${name}/build/${value.file}`});
        }
    }
    await page.evaluate(() => { window.definitions = {}; window.app = {component(name, value) { definitions[name] = value; }}; });
    await page.addScriptTag({content: bladeFixture('explorer-marquee.js')});
    for (const component of [tab, grid, list]) await page.addScriptTag({content: component.script});
    await page.evaluate(({template, mode, allTemplates}) => {
        const definition = definitions['v-dam-tab'];
        const app = Vue.createApp({
            template,
            data: () => ({
                viewMode: mode, tileSize: 100, block: {dirs: [], assets: [], grouped: false}, blockIndex: 0,
                loading: false, meta: {can_access_current: true, total_assets: 0},
                search: 'heic', searchInput: 'heic', scope: 'directory', currentDirId: 144, aclBypass: false,
                similarTo: null, applied: {filters: {columns: []}}, dirs: [], smartSearch: false,
                tabId: 'fixture', perPage: 50, clipboard: null, canAccessCurrentDir: true,
                selection: {ids: [], mode: 'none'}, bookmarkedDirIds: [], sortBy: 'name', sortOrder: 'asc',
                breadcrumb: [], page: 1, navHistory: [], navIdx: -1, requests: [],
            }),
            computed: {emptyState: definition.computed.emptyState},
            methods: {
                ...definition.methods,
                resetSimilarity() { this.similarTo = null; }, clearSelection() {}, takePendingView() {},
                fetch() { this.requests.push({scope: this.scope, query: this.search}); },
            },
        });
        app.config.globalProperties.$emitter = {on() {}, off() {}, emit() {}};
        app.directive('dam-custom-thumbnail-drop', {});
        app.directive('dam-thumbnail', {});
        // Resolve unrelated descendants without rebuilding their markup. The actual
        // grid/list templates, button, tab binding and recovery methods run unchanged.
        for (const name of new Set([...allTemplates.matchAll(/<(v-[a-z0-9-]+)/g)].map(match => match[1]))) {
            app.component(name, definitions[name] || {template: '<div></div>'});
        }
        app.component('v-dam-explorer-grid', {...definitions['v-dam-explorer-grid'], template: allTemplates.split('<!--LIST-->')[0]});
        app.component('v-dam-explorer-list', {...definitions['v-dam-explorer-list'], template: allTemplates.split('<!--LIST-->')[1]});
        window.vm = app.mount('#app');
    }, {template, mode, allTemplates: grid.template + '<!--LIST-->' + list.template});
    await page.locator('[data-explorer-empty] button').waitFor();
    await page.evaluate(async () => { await document.fonts.ready; });
}

for (const mode of ['grid', 'list']) for (const width of [1280, 375]) {
    browserTest(`${mode} ${width}: folder recovery preserves words; all-scope clears; inaccessible states offer no action`, async () => {
        const browser = await launchBrowser({playwrightPath: process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH});
        const page = await browser.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        page.on('console', message => { if (message.type() === 'warning' || message.type() === 'error') errors.push(message.text()); });
        try {
            await mount(page, {mode, width});
            const empty = page.locator('[data-explorer-empty]');
            assert.match(await empty.textContent(), /No files match.*heic.*in this folder/);
            const button = empty.getByRole('button', {name: 'Search all assets', exact: true});
            if (process.env.MOTHERSHIP_SEARCH_REVIEW_DIR) {
                fs.mkdirSync(process.env.MOTHERSHIP_SEARCH_REVIEW_DIR, {recursive: true});
                await page.screenshot({path: path.join(process.env.MOTHERSHIP_SEARCH_REVIEW_DIR, `${mode}-${width}.png`)});
            }
            await button.focus();
            if (process.env.MOTHERSHIP_SEARCH_REVIEW_DIR) {
                await page.screenshot({path: path.join(process.env.MOTHERSHIP_SEARCH_REVIEW_DIR, `${mode}-${width}-focus.png`)});
                await page.evaluate(() => { document.documentElement.classList.add('dark'); document.body.style.background = '#09090b'; });
                await page.screenshot({path: path.join(process.env.MOTHERSHIP_SEARCH_REVIEW_DIR, `${mode}-${width}-dark.png`)});
                await page.evaluate(() => { document.documentElement.classList.remove('dark'); document.body.style.background = '#f4f4f5'; });
            }
            await page.keyboard.press('Enter');
            assert.deepEqual(await page.evaluate(() => ({scope: vm.scope, dir: vm.currentDirId, query: vm.search, input: vm.searchInput, requests: vm.requests})),
                {scope: 'all', dir: null, query: 'heic', input: 'heic', requests: [{scope: 'all', query: 'heic'}]});
            await empty.getByRole('button', {name: 'Clear search', exact: true}).click();
            assert.equal(await page.evaluate(() => vm.search), '');
            await page.evaluate(() => { vm.scope = 'directory'; vm.currentDirId = 144; vm.search = vm.searchInput = 'heic'; vm.meta = {can_access_current: false}; });
            assert.match(await empty.textContent(), /don't have access/);
            assert.equal(await empty.locator('button').count(), 0);
            await page.evaluate(() => { vm.similarTo = 9; vm.meta.filter_conflict = true; });
            assert.match(await empty.textContent(), /No similar files found/);
            await page.evaluate(() => { vm.similarTo = null; vm.meta = {can_access_current: true, filter_conflict: true}; });
            assert.match(await empty.textContent(), /filters in use/);
            assert.equal(await empty.locator('button').count(), 0);
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false);
            assert.deepEqual(errors, []);
        } finally { await browser.close(); }
    });
}
