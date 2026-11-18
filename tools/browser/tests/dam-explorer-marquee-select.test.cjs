const {test} = require('node:test');
const assert = require('node:assert/strict');
const {bladeComponent, bladeFixture} = require('../fixtures/blade.cjs');
const {launchBrowser} = require('../launch-browser.cjs');

const browserTest = process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH && process.env.MOTHERSHIP_VUE_RUNTIME_PATH ? test : test.skip;

// The real grid template and methods, with the tab's own selection handlers; cards are plain 100px tiles.
async function mount(page, {grouped = false} = {}) {
    await page.setViewportSize({width: 900, height: 700});
    const tab = bladeComponent('explorer-tab');
    const grid = bladeComponent('explorer-grid');
    // Just enough layout for rows of tiles: the built theme CSS is not what is under test here.
    await page.setContent(`<style>.grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:24px}
        .relative{position:relative}.absolute{position:absolute}.pr-4{padding-right:16px}.min-h-72{min-height:18rem}</style>
        <body style="margin:0"><div id="app"></div>`);
    await page.addScriptTag({path: process.env.MOTHERSHIP_VUE_RUNTIME_PATH});
    await page.evaluate(() => { window.definitions = {}; window.app = {component(name, value) { definitions[name] = value; }}; });
    await page.addScriptTag({content: bladeFixture('explorer-marquee.js')});
    for (const component of [tab, grid]) await page.addScriptTag({content: component.script});
    await page.evaluate(({gridTemplate, grouped}) => {
        const tabMethods = definitions['v-dam-tab'].methods;
        const app = Vue.createApp({
            // Grouped by stack, the tab renders one grid per block (tab.blade.php displayBlocks).
            template: grouped
                ? `<div style="padding:40px;width:700px"><div v-for="block in [{dirs, assets: assets.slice(0, 3)}, {dirs: [], assets: assets.slice(3)}]" data-explorer-block>
                    <v-dam-explorer-grid tab-id="t" :directories="block.dirs" :assets="block.assets" :selection="selection"
                    @toggle-select="toggleSelect" @select-range="selectRange" @set-selection="setSelection"></v-dam-explorer-grid></div></div>`
                : `<div style="padding:40px;width:700px"><v-dam-explorer-grid tab-id="t" :directories="dirs" :assets="assets" :selection="selection"
                @toggle-select="toggleSelect" @select-range="selectRange" @set-selection="setSelection"></v-dam-explorer-grid></div>`,
            data: () => ({
                dirs: [{id: 7, name: 'Games'}],
                assets: [1, 2, 3, 4, 5, 6].map(id => ({id, file_name: `IMG_${id}.JPG`})),
                selection: {ids: [], mode: 'none'},
            }),
            methods: {
                toggleSelect: tabMethods.toggleSelect, selectRange: tabMethods.selectRange, setSelection: tabMethods.setSelection,
                computeSelectionMode() {}, persistSelection() {}, broadcastSelection() {},
            },
        });
        app.config.globalProperties.$emitter = {on() {}, off() {}, emit() {}};
        const tile = {template: '<div style="width:100px;height:100px;background:#ddd"></div>'};
        app.component('v-dam-explorer-asset-card', tile);
        app.component('v-dam-explorer-folder-card', tile);
        app.component('v-dam-explorer-ctx', {template: '<div></div>'});
        app.component('v-dam-explorer-grid', {...definitions['v-dam-explorer-grid'], template: gridTemplate});
        window.vm = app.mount('#app');
    }, {gridTemplate: grid.template, grouped});
}

// The real list template and methods, with the tab's own selection handlers; every row it opens is kept on window.opened.
async function mountList(page) {
    await page.setViewportSize({width: 1000, height: 700});
    const tab = bladeComponent('explorer-tab');
    const list = bladeComponent('explorer-list');
    await page.setContent(`<style>.dam-list-row{display:grid;grid-template-columns:32px 32px 1fr 100px 100px 120px 60px;height:44px;align-items:center}
        .relative{position:relative}.absolute{position:absolute}.min-h-72{min-height:18rem}</style>
        <body style="margin:0"><div id="app"></div>`);
    await page.addScriptTag({path: process.env.MOTHERSHIP_VUE_RUNTIME_PATH});
    await page.evaluate(() => { window.definitions = {}; window.app = {component(name, value) { definitions[name] = value; }}; });
    await page.addScriptTag({content: bladeFixture('explorer-marquee.js')});
    for (const component of [tab, list]) await page.addScriptTag({content: component.script});
    await page.evaluate(({template}) => {
        window.opened = [];
        window.nativeDrags = 0;
        document.addEventListener('dragstart', event => { if (! event.defaultPrevented) nativeDrags++; });
        const tabMethods = definitions['v-dam-tab'].methods;
        const app = Vue.createApp({
            template: `<div style="padding:40px;width:900px"><v-dam-explorer-list tab-id="t" :directories="dirs" :assets="assets" :selection="selection"
                @toggle-select="toggleSelect" @select-range="selectRange" @set-selection="setSelection" @navigate="dir => opened.push('directory:' + dir.id)"></v-dam-explorer-list></div>`,
            data: () => ({
                dirs: [{id: 7, name: 'Games', assets_count: 0, children_count: 0}],
                assets: [1, 2, 3, 4].map(id => ({id, file_name: `IMG_${id}.JPG`, file_type: 'image', extension: 'jpg', path: '/x.jpg'})),
                selection: {ids: [], mode: 'none'},
            }),
            methods: {
                toggleSelect: tabMethods.toggleSelect, selectRange: tabMethods.selectRange, setSelection: tabMethods.setSelection,
                computeSelectionMode() {}, persistSelection() {}, broadcastSelection() {},
            },
        });
        app.config.globalProperties.$emitter = {on() {}, off() {}, emit(name, payload) { if (name === 'dam-open-preview') opened.push(`asset:${payload.id}`); }};
        app.directive('dam-custom-thumbnail-drop', {});
        app.directive('dam-thumbnail', {});
        for (const name of new Set([...template.matchAll(/<(v-[a-z0-9-]+)/g)].map(match => match[1]))) app.component(name, {template: '<div></div>'});
        app.component('v-dam-explorer-list', {...definitions['v-dam-explorer-list'], template});
        window.vm = app.mount('#app');
    }, {template: list.template});
    await page.locator('[data-marquee-key="asset:4"]').waitFor();
}

const selected = page => page.evaluate(() => vm.selection.ids.map(i => `${i.type}:${i.id}`).sort());
const box = (page, key) => page.locator(`[data-marquee-key="${key}"]`).boundingBox();
// The middle of a list row's date cell, the part of a row that starts a box.
const dateCell = async (page, key) => {
    const cell = await page.locator(`[data-marquee-key="${key}"] .dam-list-col-date`).boundingBox();
    return {x: cell.x + cell.width / 2, y: cell.y + cell.height / 2};
};

browserTest('in the list, dragging from a row\'s date cell selects the folders and files the box touches', async () => {
    const browser = await launchBrowser({playwrightPath: process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH});
    const page = await browser.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));

    try {
        await mountList(page);
        const from = await dateCell(page, 'directory:7');
        const to = await dateCell(page, 'asset:2');

        await page.mouse.move(from.x, from.y);
        await page.mouse.down();
        await page.mouse.move(to.x, to.y, {steps: 10});
        assert.equal(await page.locator('[data-explorer-marquee]').count(), 1, 'the box is drawn while dragging');
        await page.mouse.up();

        assert.deepEqual(await selected(page), ['asset:1', 'asset:2', 'directory:7']);
        assert.equal(await page.locator('[data-explorer-marquee]').count(), 0, 'the box goes away on release');
        assert.equal(await page.evaluate(() => nativeDrags), 0, 'the row is not picked up and moved');
        assert.deepEqual(await page.evaluate(() => opened), [], 'releasing the box opens nothing');
        assert.deepEqual(errors, []);
    } finally {
        await browser.close();
    }
});

browserTest('in the list, a row\'s name still moves it and a click on its date cell still opens it', async () => {
    const browser = await launchBrowser({playwrightPath: process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH});
    const page = await browser.newPage();

    try {
        await mountList(page);
        const name = await page.locator('[data-marquee-key="asset:1"] bdi', {hasText: 'IMG_1.JPG'}).boundingBox();
        const target = await dateCell(page, 'asset:3');
        await page.mouse.move(name.x + 10, name.y + name.height / 2);
        await page.mouse.down();
        await page.mouse.move(target.x, target.y, {steps: 10});
        assert.equal(await page.locator('[data-explorer-marquee]').count(), 0, 'a press on the name never draws a box');
        await page.mouse.up();
        assert.equal(await page.evaluate(() => nativeDrags), 1, 'the row is picked up');
        assert.deepEqual(await selected(page), []);

        const cell = await dateCell(page, 'asset:2');
        await page.mouse.click(cell.x, cell.y);
        assert.deepEqual(await page.evaluate(() => opened), ['asset:2']);
    } finally {
        await browser.close();
    }
});

browserTest('dragging on empty space selects the folders and files the box touches', async () => {
    const browser = await launchBrowser({playwrightPath: process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH});
    const page = await browser.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));

    try {
        await mount(page);
        const space = await page.locator('[data-marquee-key="asset:1"]').evaluate(el => el.closest('.min-h-72').getBoundingClientRect().toJSON());
        const a1 = await box(page, 'asset:1');
        const a2 = await box(page, 'asset:2');

        // A press on empty space with no drag selects nothing.
        await page.mouse.click(space.right - 5, space.bottom - 5);
        assert.deepEqual(await selected(page), []);

        // From the empty margin right of the last column, across the first two files.
        await page.mouse.move(space.right - 5, a1.y - 4);
        await page.mouse.down();
        await page.mouse.move(a2.x + 10, a1.y + 10, {steps: 6});
        assert.equal(await page.locator('[data-explorer-marquee]').count(), 1, 'the box is drawn while dragging');
        await page.mouse.move(a1.x + 10, a1.y + 10, {steps: 6});
        await page.mouse.up();
        const firstRow = await selected(page);
        assert.ok(firstRow.includes('asset:1') && firstRow.includes('asset:2'), JSON.stringify(firstRow));
        assert.ok(! firstRow.includes('directory:7'), 'the folder row above is outside the box');
        assert.equal(await page.locator('[data-explorer-marquee]').count(), 0, 'the box goes away on release');

        // A new box replaces the selection; with Shift it adds to it.
        const dir = await box(page, 'directory:7');
        await page.mouse.move(space.right - 5, dir.y + 5);
        await page.mouse.down();
        await page.mouse.move(dir.x + 5, dir.y + 10, {steps: 6});
        await page.mouse.up();
        assert.deepEqual(await selected(page), ['directory:7']);

        await page.keyboard.down('Shift');
        await page.mouse.move(space.right - 5, a1.y - 4);
        await page.mouse.down();
        await page.mouse.move(a1.x + 10, a1.y + 10, {steps: 6});
        await page.mouse.up();
        await page.keyboard.up('Shift');
        assert.ok((await selected(page)).includes('directory:7') && (await selected(page)).includes('asset:1'));

        // A press that starts on a card is the card's own (open, drag), never a box.
        await page.mouse.move(a1.x + 50, a1.y + 50);
        await page.mouse.down();
        await page.mouse.move(space.right - 5, space.bottom - 5, {steps: 6});
        assert.equal(await page.locator('[data-explorer-marquee]').count(), 0);
        await page.mouse.up();

        assert.deepEqual(errors, []);
    } finally {
        await browser.close();
    }
});

browserTest('a release before the next frame still selects what the final box covers', async () => {
    const browser = await launchBrowser({playwrightPath: process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH});
    const page = await browser.newPage();

    try {
        await mount(page);
        const ids = await page.evaluate(() => {
            const space = document.querySelector('.min-h-72').getBoundingClientRect();
            const a1 = document.querySelector('[data-marquee-key="asset:1"]').getBoundingClientRect();
            const fire = (type, target, x, y) => target.dispatchEvent(new PointerEvent(type, {bubbles: true, button: 0, pointerId: 1, pointerType: 'mouse', clientX: x, clientY: y}));
            // Down, drag across the first file and release in one task: no animation frame runs in between.
            fire('pointerdown', document.querySelector('.min-h-72'), space.right - 5, a1.top - 4);
            fire('pointermove', window, a1.left + 30, a1.top + 10);
            fire('pointermove', window, a1.left + 10, a1.top + 10);
            fire('pointerup', window, a1.left + 10, a1.top + 10);
            return vm.selection.ids.map(i => `${i.type}:${i.id}`);
        });
        assert.ok(ids.includes('asset:1'), JSON.stringify(ids));
    } finally {
        await browser.close();
    }
});

browserTest('grouped by stack, the box selects across every group grid', async () => {
    const browser = await launchBrowser({playwrightPath: process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH});
    const page = await browser.newPage();

    try {
        await mount(page, {grouped: true});
        const space = await page.locator('[data-marquee-key="asset:1"]').evaluate(el => el.closest('.min-h-72').getBoundingClientRect().toJSON());
        const a1 = await box(page, 'asset:1');
        const a4 = await box(page, 'asset:4');

        // From the first group's empty margin down into the second group's first file.
        await page.mouse.move(space.right - 5, a1.y - 4);
        await page.mouse.down();
        await page.mouse.move(a4.x + 10, a4.y + 10, {steps: 8});
        await page.mouse.up();
        const ids = await selected(page);
        assert.ok(ids.includes('asset:1') && ids.includes('asset:4'), JSON.stringify(ids));
    } finally {
        await browser.close();
    }
});

browserTest('a shrink released before its frame keeps only the final box, and another pointer lifting does not end the drag', async () => {
    const browser = await launchBrowser({playwrightPath: process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH});
    const page = await browser.newPage();

    try {
        await mount(page);
        const result = await page.evaluate(async () => {
            const space = document.querySelector('.min-h-72').getBoundingClientRect();
            const a1 = document.querySelector('[data-marquee-key="asset:1"]').getBoundingClientRect();
            const a2 = document.querySelector('[data-marquee-key="asset:2"]').getBoundingClientRect();
            const fire = (type, target, x, y, pointerId = 1) => target.dispatchEvent(new PointerEvent(type, {bubbles: true, button: 0, pointerId, pointerType: 'mouse', clientX: x, clientY: y}));
            const frame = () => new Promise(resolve => requestAnimationFrame(() => resolve()));
            const ids = () => vm.selection.ids.map(i => `${i.type}:${i.id}`).sort();

            // From the right margin, a box over files 1 and 2 is drawn and applied.
            fire('pointerdown', document.querySelector('.min-h-72'), space.right - 5, a1.top - 4);
            fire('pointermove', window, a1.left + 10, a1.top + 10);
            await frame(); await frame();
            const wide = ids();
            // A second pointer lifting and cancelling leaves the drag alone.
            fire('pointerup', window, 0, 0, 2);
            fire('pointercancel', window, 0, 0, 2);
            const alive = document.querySelector('[data-explorer-marquee]') !== null;
            // Shrink to file 2 only and release in the same task.
            fire('pointermove', window, a2.left + 10, a1.top + 10);
            fire('pointerup', window, a2.left + 10, a1.top + 10);
            return {wide, alive, final: ids()};
        });
        assert.ok(result.wide.includes('asset:1') && result.wide.includes('asset:2'), JSON.stringify(result.wide));
        assert.equal(result.alive, true, 'a foreign pointer does not end the drag');
        assert.ok(result.final.includes('asset:2') && ! result.final.includes('asset:1'), JSON.stringify(result.final));
    } finally {
        await browser.close();
    }
});
