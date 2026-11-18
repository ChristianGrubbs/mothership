const {test} = require('node:test');
const assert = require('node:assert/strict');
const {bladeComponent, bladeFixture} = require('../fixtures/blade.cjs');
const {launchBrowser} = require('../launch-browser.cjs');

const browserTest = process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH && process.env.MOTHERSHIP_VUE_RUNTIME_PATH ? test : test.skip;

/** Mounts the real list template and component with one stack row; every event the row sends is kept on window.sent. */
async function mount(page) {
    const list = bladeComponent('explorer-list');
    await page.setContent('<body><div id="app"></div>');
    await page.addScriptTag({path: process.env.MOTHERSHIP_VUE_RUNTIME_PATH});
    await page.evaluate(() => { window.definitions = {}; window.app = {component(name, value) { definitions[name] = value; }}; });
    await page.addScriptTag({content: bladeFixture('explorer-marquee.js')});
    await page.addScriptTag({content: list.script});
    await page.evaluate(({template}) => {
        window.sent = [];
        const app = Vue.createApp({
            template: '<v-dam-explorer-list :assets="assets" :selection="selection" tab-id="fixture" :current-dir-id="144" :result-total="1" @toggle-select="id => sent.push([\'toggle-select\', id, null])" @select-range="() => sent.push([\'select-range\', null, null])"></v-dam-explorer-list>',
            // Three files under one entry.
            data: () => ({sent, selection: {ids: [], mode: 'none'}, assets: [{id: 52, file_name: 'campaign.png', file_type: 'image', extension: 'png', path: '/fixture.png', attachment_count: 3}]}),
        });
        window.select = ids => { vm.selection = {ids, mode: ids.length ? 'some' : 'none'}; };
        app.config.globalProperties.$emitter = {on() {}, off() {}, emit(name, payload) {
            sent.push([name, payload?.id ?? payload?.asset?.id, payload?.el?.matches?.('[data-dam-asset-open]') ?? null]);
        }};
        app.directive('dam-custom-thumbnail-drop', {});
        app.directive('dam-thumbnail', {});
        // Descendants this test does not exercise resolve to empty elements; the row and its handlers run unchanged.
        for (const name of new Set([...template.matchAll(/<(v-[a-z0-9-]+)/g)].map(match => match[1]))) {
            app.component(name, {template: '<div></div>'});
        }
        app.component('v-dam-explorer-list', {...definitions['v-dam-explorer-list'], template});
        window.vm = app.mount('#app');
    }, {template: list.template});
    await page.locator('[data-dam-asset-open]').waitFor();
}
// What the row did: opened the file, opened the chooser, or changed the selection.
const acted = page => page.evaluate(() => sent.filter(([name]) => ['dam-open-preview', 'dam:open-stack-fan', 'toggle-select', 'select-range'].includes(name)));

browserTest('a stack row opens its main file like any other row, and its count opens the chooser', async () => {
    const browser = await launchBrowser({playwrightPath: process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH});
    try {
        const page = await browser.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await mount(page);
        await page.locator('[data-dam-asset-open] bdi', {hasText: 'campaign.png'}).click();
        assert.deepEqual(await acted(page), [['dam-open-preview', 52, null]], 'the row opens the main file, not the chooser');
        // The count is the way into the stack's files: it names how many, hands the chooser its row, and does not open the file as well.
        const count = page.locator('[data-dam-stack-open]');
        assert.equal((await count.textContent()).trim(), '3');
        assert.match(await count.getAttribute('aria-label'), /3 files/);
        await count.click();
        assert.deepEqual(await acted(page), [['dam-open-preview', 52, null], ['dam:open-stack-fan', 52, true]]);
        // While files are selected a click on a row selects it; the count still only opens the chooser.
        await page.evaluate(() => { sent.length = 0; select([{id: 7, type: 'asset'}]); });
        await count.click();
        await count.click({modifiers: ['Shift']});
        assert.deepEqual(await acted(page), [['dam:open-stack-fan', 52, true], ['dam:open-stack-fan', 52, true]]);
        assert.deepEqual(errors, []);
    } finally { await browser.close(); }
});
