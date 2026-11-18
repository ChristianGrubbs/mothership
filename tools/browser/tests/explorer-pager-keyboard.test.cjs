// Two keyboard defects in the same family as the datagrid toolbar focus fix (#149):
//
// 1. The Library explorer breadcrumb swapped its back/forward buttons and crumbs for a shimmer on every
//    fetch (back, forward, a crumb, per page, page), so the back button a keyboard user had just pressed
//    was destroyed and focus fell to <body>. The tab now passes `loading && ! firstLoadSettled`, so only
//    the very first fetch shows the shimmer, even when that fetch fails and leaves `meta` null.
// 2. The History and DAM asset-picker toolbars' previous/next page controls were a bare <div> (History)
//    or an unnamed <button> (picker). Both are now named buttons, like the explorer pagination.
//
// The first test evaluates the tab's real breadcrumb binding against the real v-dam-tab state; the
// keyboard test mounts the real, unmodified breadcrumb component behind that same binding.
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = path.resolve(__dirname, '../../..');
const source = (file) => fs.readFileSync(path.join(root, file), 'utf8');

const EXPLORER = 'packages/Mothership/DAM/src/Resources/views/components/explorer';
const adminManifestFile = 'public/themes/admin/default/build/manifest.json';

const stripBladeComments = (text) => text.replace(/\{\{--[\s\S]*?--\}\}/g, '');

function moduleScript(file, componentName) {
    const script = [...source(file).matchAll(/<script type="module">([\s\S]*?)<\/script>/g)].map((match) => match[1])
        .find((body) => body.includes(`app.component('${componentName}'`));
    assert.ok(script, `${componentName} not found in ${file}`);

    return script;
}

// Replaces each @json(/@js(<php expression>) with null, matching parentheses so nested calls stay whole.
function stripBladeJson(script) {
    let out = '', from = 0, at;
    for (const rx = /@(?:json|js)\(/g; (at = script.slice(from).search(rx)) !== -1;) {
        at += from;
        let depth = 0, i = at + script.slice(at).indexOf('(');
        for (; i < script.length; i++) { if (script[i] === '(') depth++; else if (script[i] === ')' && --depth === 0) break; }
        out += script.slice(from, at) + 'null'; from = i + 1;
    }

    return out + script.slice(from);
}

// The real v-dam-tab script with its Blade directives removed, so node and the browser can both evaluate it.
function tabScript() {
    return stripBladeJson(moduleScript(`${EXPLORER}/tab.blade.php`, 'v-dam-tab'))
        .replace(/@lang\('[^']+'\)/g, 'Translation')
        .split('\n').filter((line) => ! /^\s*@(if|elseif|else|endif|once|endonce|push|endpush)\b/.test(line)).join('\n');
}

function explorer() {
    const script = tabScript();
    let definition; const calls = [];
    const storage = { getItem: () => null, setItem() {}, removeItem() {} };
    vm.runInNewContext(script, { app: { component(name, value) { definition = value; } }, window: { MothershipLibraryNavigation: { resolveSort: () => 'name:asc' } }, localStorage: storage, sessionStorage: storage, document: {}, getComputedStyle: (el) => el.style, setTimeout, clearTimeout, AbortController });
    const props = { tabId: 't1', initialDirectoryId: null, initialSearch: '', initialScope: 'all', initialMediaType: 'all', clearSavedFilters: false, initialViewMode: 'grid', initialPage: 1, initialPerPage: 50, aclBypass: true, accessibleIds: [] };
    const state = { ...props, ...definition.methods, $emit() {}, $emitter: { emit() {} }, $nextTick: (callback) => Promise.resolve().then(callback),
        $axios: { get(url, options) { calls.push({ url, options }); return new Promise((resolve) => calls.at(-1).resolve = resolve); } } };
    Object.assign(state, definition.data.call(state));
    for (const [name, getter] of Object.entries(definition.computed ?? {})) {
        Object.defineProperty(state, name, { get: typeof getter === 'function' ? getter : getter.get, configurable: true });
    }
    // Selection and tab-sync bookkeeping is not under test here.
    Object.assign(state, { restoreSelection() {}, computeSelectionMode() {}, clearSelection() {}, sync() {}, userKey: () => null, syncSortToSearch() {} });

    return { state, calls };
}

// The expression v-dam-tab binds to the breadcrumb's `loading` prop, which decides the shimmer.
function breadcrumbLoadingBinding() {
    const tag = stripBladeComments(source(`${EXPLORER}/tab.blade.php`)).match(/<v-dam-explorer-breadcrumb\b[^>]*>/);
    assert.ok(tag, 'tab.blade.php must render v-dam-explorer-breadcrumb');
    const binding = tag[0].match(/\s:loading="([^"]+)"/);
    assert.ok(binding, 'the tab must bind the breadcrumb loading prop');

    return binding[1];
}

function breadcrumbComponent() {
    const blade = stripBladeComments(source(`${EXPLORER}/breadcrumb.blade.php`));
    const template = blade.match(/<script type="text\/x-template" id="v-dam-explorer-breadcrumb-template">([\s\S]*?)<\/script>/);
    assert.ok(template, 'v-dam-explorer-breadcrumb-template must exist');
    assert.match(template[1], /^\s*<div[^>]*>\s*<template v-if="loading">/, 'the breadcrumb shimmer must be decided by the loading prop');

    return {
        template: expandAdminDropdown(template[1]).replace(/@lang\('[^']+'\)/g, 'Open in new tab').replace(/@\{\{/g, '{{'),
        registration: moduleScript(`${EXPLORER}/breadcrumb.blade.php`, 'v-dam-explorer-breadcrumb'),
    };
}

// What Blade renders for <x-admin::dropdown> (components/dropdown/index.blade.php): the v-dropdown element with
// a toggle slot and a menu slot wrapped in <ul data-dropdown-menu>. The test has no Blade, so expand it here.
function expandAdminDropdown(text) {
    return text
        .replace(/<x-admin::dropdown\b/g, '<v-dropdown').replace(/<\/x-admin::dropdown>/g, '</v-dropdown>')
        .replace(/<x-slot:toggle>/g, '<template v-slot:toggle>').replace(/<\/x-slot:toggle>/g, '</template>')
        .replace(/<x-slot:menu class="([^"]*)">/g, '<template v-slot:menu><ul data-dropdown-menu class="$1">').replace(/<\/x-slot:menu>/g, '</ul></template>');
}

// The real shared dropdown component, unmodified.
function dropdownComponent() {
    const blade = stripBladeComments(source('packages/Mothership/Admin/src/Resources/views/components/dropdown/index.blade.php'));
    const template = blade.match(/<script\s+type="text\/x-template"\s+id="v-dropdown-template"\s*>([\s\S]*?)<\/script>/);
    const registration = blade.match(/<script type="module">([\s\S]*?)<\/script>/);
    assert.ok(template && registration, 'the shared v-dropdown component must exist');

    return { template: template[1], registration: registration[1] };
}

const explorerResponse = (breadcrumb) => ({ data: { directories: [], assets: [], breadcrumb, meta: { current_page: 1, last_page: 1, per_page: 50 } } });
const settle = () => new Promise((resolve) => setImmediate(resolve));

// The phone selection bar is fixed (out of flow), so the tab reserves its measured height as a spacer.
test('the explorer reserves room for the fixed selection bar and re-measures when it changes', () => {
    const { state } = explorer();
    const observed = [];
    const observer = { disconnect: () => observed.splice(0), observe: (el) => observed.push(el) };
    const bar = (position, height) => ({ style: { position }, getBoundingClientRect: () => ({ height }) });

    state._selectionBarObserver = observer;

    state.$refs = { selectionBar: bar('fixed', 90.4) };
    state.measureSelectionBar();
    assert.equal(state.selectionBarHeight, 91, 'a fixed bar reserves its height, rounded up');
    assert.equal(observed.length, 1, 'the bar is watched for size changes');

    // A v-show tab that is hidden measures 0; the observer fires when it is revealed (or the bar wraps) and the spacer follows.
    const shown = state.$refs.selectionBar;
    shown.getBoundingClientRect = () => ({ height: 0 });
    state.measureSelectionBar();
    assert.equal(state.selectionBarHeight, 0, 'a hidden tab reserves nothing');
    shown.getBoundingClientRect = () => ({ height: 131 });
    state.measureSelectionBar();
    assert.equal(state.selectionBarHeight, 131, 'revealing the tab, or a wrapped second line, re-measures');
    assert.equal(observed.length, 1, 'the same bar is not observed twice');

    // Crossing 640 px: the bar goes back into the flow.
    shown.style.position = 'static';
    state.measureSelectionBar();
    assert.equal(state.selectionBarHeight, 0, 'a static bar needs no spacer');

    // Clearing the selection removes the bar.
    state.$refs = {};
    state.measureSelectionBar();
    assert.equal(state.selectionBarHeight, 0);
    assert.equal(observed.length, 0, 'nothing is watched once the bar is gone');
});


test('the explorer breadcrumb shows the shimmer on the first load only and stays mounted through navigation', async () => {
    const binding = breadcrumbLoadingBinding();
    const shimmer = (state) => new Function('state', `with (state) { return (${binding}); }`)(state);
    const { state, calls } = explorer();
    const trail = [{ id: 1, name: 'Root' }, { id: 2, name: 'Alpha' }, { id: 3, name: 'Beta' }];

    state.goTo({ id: 2, name: 'Alpha' });
    assert.equal(state.loading, true);
    assert.equal(shimmer(state), true, 'the first load shows the shimmer');
    calls[0].resolve(explorerResponse(trail.slice(0, 2))); await settle();
    assert.equal(shimmer(state), false);

    state.goTo({ id: 3, name: 'Beta' });
    assert.equal(state.loading, true);
    assert.equal(shimmer(state), false, 'opening a folder must keep the trail (and its focused crumb) mounted');
    calls[1].resolve(explorerResponse(trail)); await settle();

    state.goBack();
    assert.equal(state.loading, true);
    assert.equal(shimmer(state), false, 'going back must keep the focused back button mounted');
    calls[2].resolve(explorerResponse(trail.slice(0, 2))); await settle();

    state.goForward();
    assert.equal(shimmer(state), false, 'going forward must keep the focused forward button mounted');
});

test('the explorer breadcrumb stays mounted after a failed first load, while meta is still null', async () => {
    const binding = breadcrumbLoadingBinding();
    const shimmer = (state) => new Function('state', `with (state) { return (${binding}); }`)(state);
    const { state, calls } = explorer();

    state.goTo({ id: 3, name: 'Beta' }, true);
    assert.equal(shimmer(state), true, 'the first load shows the shimmer');
    calls[0].resolve(Promise.reject({ response: { status: 500 } })); await settle();
    assert.equal(state.loading, false);
    assert.equal(state.meta, null);

    state.goTo({ id: 3, name: 'Beta' });
    assert.equal(state.loading, true);
    assert.equal(shimmer(state), false, 'retrying from a crumb must keep the focused crumb mounted');
});

function resolveBuiltAdminStylesheetPath() {
    const manifest = JSON.parse(source(adminManifestFile));
    const entry = Object.values(manifest).find((item) => typeof item.file === 'string' && item.file.endsWith('.css') && item.file.startsWith('assets/app-'));
    assert.ok(entry, 'the committed admin manifest must list a built assets/app-*.css entry');

    return path.join(root, 'public/themes/admin/default/build', entry.file);
}

// A stand-in for v-dam-tab: the same navigation history shape, the real breadcrumb binding, and a fetch
// that stays in flight until the test lets it land.
const host = (binding) => `
    <button type="button" id="before">Before</button>
    <v-dam-explorer-breadcrumb
        :breadcrumbs="breadcrumb"
        :can-go-back="navIdx > 0"
        :can-go-forward="navIdx < history.length - 1"
        :loading="${binding}"
        @back="move(-1)"
        @forward="move(1)"
        @navigate="open($event)"
        @drop="log('drop', $event.targetDir.name + ':' + $event.targetDir.id + ':' + $event.payload.type + ':' + $event.payload.id)"
        @open-new-tab="log('tab', $event.name)"
    ></v-dam-explorer-breadcrumb>
`;

async function mount(page) {
    const { template, registration } = breadcrumbComponent();

    await page.setContent(`<main id="app" style="display:flex;gap:8px;padding:16px">${host(breadcrumbLoadingBinding())}</main>`);
    await page.addStyleTag({ path: resolveBuiltAdminStylesheetPath() });
    await page.addScriptTag({ path: process.env.MOTHERSHIP_VUE_RUNTIME_PATH });

    const dropdown = dropdownComponent();

    await page.evaluate(({ template, dropdownTemplate }) => {
        for (const [id, text] of [['v-dam-explorer-breadcrumb-template', template], ['v-dropdown-template', dropdownTemplate]]) {
            const script = document.createElement('script');
            script.type = 'text/x-template';
            script.id = id;
            script.textContent = text;
            document.body.append(script);
        }

        const trail = [{ id: 1, name: 'Root' }, { id: 2, name: 'Alpha' }, { id: 3, name: 'Beta' }, { id: 4, name: 'Gamma' }, { id: 5, name: 'Delta' }];

        window.app = Vue.createApp({
            data: () => ({ loading: true, firstLoadSettled: false, breadcrumb: [], history: [2, 3, 4, 5].map((id) => trail.slice(0, id)), navIdx: 3 }),
            methods: {
                log(kind, name) { (window.events ||= []).push([kind, name]); },
                fetch() { this.loading = true; window.land = () => { this.loading = false; this.firstLoadSettled = true; }; },
                move(step) { this.navIdx += step; this.breadcrumb = this.history[this.navIdx]; this.fetch(); },
                open(crumb) { this.breadcrumb = this.breadcrumb.slice(0, this.breadcrumb.findIndex((c) => c.id === crumb.id) + 1); this.fetch(); },
            },
            mounted() { this.breadcrumb = this.history[this.navIdx]; this.fetch(); },
        });
    }, { template, dropdownTemplate: dropdown.template });

    await page.addScriptTag({ content: dropdown.registration });
    await page.addScriptTag({ content: registration });
    await page.evaluate(() => app.mount('#app'));
}

const land = (page) => page.evaluate(async () => { window.land(); await Vue.nextTick(); });

const vueTest = process.env.MOTHERSHIP_VUE_RUNTIME_PATH && process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH ? test : test.skip;

for (const viewport of [{ width: 1440, height: 900 }, { width: 375, height: 812 }]) {
    vueTest(`at ${viewport.width}px keyboard focus stays on the back button, forward button and crumb across navigation`, async () => {
        const { chromium } = require(process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH);
        const browser = await chromium.launch({ headless: true, channel: process.env.MOTHERSHIP_BROWSER_CHANNEL || 'chrome' });

        try {
            const page = await browser.newPage({ viewport });
            await mount(page);
            assert.equal(await page.locator('.shimmer').count() > 0, true, 'the first load shows the shimmer');
            await land(page);
            assert.equal(await page.locator('.shimmer').count(), 0);

            const [back, forward] = await page.locator('button:has(svg)').elementHandles();
            const focused = (handle) => page.evaluate((el) => document.activeElement === el, handle);
            const activeText = () => page.evaluate(() => `${document.activeElement.tagName}:${document.activeElement.textContent.trim()}`);

            await page.focus('#before');
            await page.keyboard.press('Tab');
            assert.equal(await focused(back), true, 'Tab reaches the back button');

            await page.keyboard.press('Enter');
            assert.equal(await page.evaluate(() => app._instance.proxy.loading), true, 'the fetch is in flight');
            assert.equal(await focused(back), true, 'the back button keeps focus while the fetch is in flight');
            await land(page);
            assert.equal(await focused(back), true, 'the back button keeps focus after the fetch lands');

            await page.keyboard.press('Tab');
            assert.equal(await focused(forward), true);
            await page.keyboard.press('Shift+Tab');
            await page.keyboard.press('Enter');
            await land(page);
            await page.keyboard.press('Tab');
            assert.equal(await focused(forward), true, 'Tab reaches the forward button');
            await page.keyboard.press('Enter');
            assert.equal(await focused(forward), true, 'the forward button keeps focus while the fetch is in flight');
            await land(page);
            assert.equal(await focused(forward), true, 'the forward button keeps focus after the fetch lands');

            // Four levels (Root, Alpha, Beta, Gamma): back, forward, Root, the ellipsis (Alpha and Beta sit behind it), then the Beta crumb.
            await page.focus('#before');
            for (let i = 0; i < 4; i++) await page.keyboard.press('Tab');
            assert.equal(await activeText(), 'BUTTON:…', 'the ellipsis toggle follows Root in the Tab order');
            await page.keyboard.press('Tab');
            assert.equal(await activeText(), 'BUTTON:Beta');
            await page.keyboard.press('Enter');
            assert.equal(await activeText(), 'BUTTON:Beta', 'the crumb keeps focus while its folder loads');
            await land(page);
            assert.equal(await activeText(), 'BUTTON:Beta', 'the crumb keeps focus after its folder loads');
        } finally {
            await browser.close();
        }
    });
}


// The real v-dam-tab (data, watchers, mounted and beforeUnmount unmodified) behind a stand-in template that holds
// only the selection bar and its spacer. mounted() builds the ResizeObserver; nothing here calls measureSelectionBar
// by hand except the "repeated measurement" check, so the observer callback is what moves the spacer.
const tabHost = `
    <div id="host" v-show="shown"><v-dam-tab ref="tab" tab-id="t1" initial-scope="directory"></v-dam-tab></div>
`;
const tabStandIn = `
    <div>
        <div v-if="selection.ids.length" :key="selection.ids.length" ref="selectionBar" id="bar"
             style="position:fixed;left:0;right:0;bottom:0;height:90px;background:#ccc"></div>
        <div v-if="selectionBarHeight" id="spacer" :style="{ height: selectionBarHeight + 'px' }"></div>
    </div>
`;

vueTest('the real tab keeps its selection-bar spacer in step through the ResizeObserver it creates on mount', async () => {
    const { chromium } = require(process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH);
    const browser = await chromium.launch({ headless: true, channel: process.env.MOTHERSHIP_BROWSER_CHANNEL || 'chrome' });

    try {
        const page = await browser.newPage({ viewport: { width: 375, height: 812 } });
        // The tab reads localStorage, which an about:blank document denies, so serve the page from a real origin.
        await page.route('http://tab.test/', (route) => route.fulfill({ contentType: 'text/html', body: `<!doctype html><main id="app">${tabHost}</main>` }));
        await page.goto('http://tab.test/');
        await page.addScriptTag({ path: process.env.MOTHERSHIP_VUE_RUNTIME_PATH });

        // Records every observe/disconnect while still delegating to the browser's real ResizeObserver.
        await page.evaluate(() => {
            const Real = window.ResizeObserver;
            window.roLog = { created: 0, observe: 0, disconnect: 0, watching: new Set() };
            window.ResizeObserver = class {
                constructor(callback) { window.roLog.created++; this.inner = new Real(callback); }
                observe(el) { window.roLog.observe++; window.roLog.watching.add(el); this.inner.observe(el); }
                disconnect() { window.roLog.disconnect++; window.roLog.watching.clear(); this.inner.disconnect(); }
            };
        });

        await page.evaluate(({ standIn }) => {
            window.tabDefinition = null;
            window.MothershipLibraryNavigation = { resolveSort: () => 'name:asc' };
            window.app = { component: (name, definition) => { window.tabDefinition = definition; } };
            window.standIn = standIn;
        }, { standIn: tabStandIn });
        await page.addScriptTag({ content: tabScript() });
        const stubs = await page.evaluate(() => {
            const root = Vue.createApp({ data: () => ({ shown: true }) });
            root.config.globalProperties.$axios = { get: () => new Promise(() => {}) };
            root.config.globalProperties.$emitter = { on() {}, off() {}, emit() {} };
            root.component('v-dam-tab', { ...window.tabDefinition, template: window.standIn });
            window.root = root;

            return true;
        });
        assert.equal(stubs, true);
        await page.evaluate(() => { window.vm = window.root.mount('#app'); });

        const settle = () => page.waitForTimeout(150);
        const spacer = () => page.evaluate(() => document.querySelector('#spacer')?.getBoundingClientRect().height ?? 0);
        const barHeight = () => page.evaluate(() => document.querySelector('#bar')?.getBoundingClientRect().height ?? 0);
        const setIds = (ids) => page.evaluate(async (ids) => { vm.$refs.tab.selection.ids = ids; await Vue.nextTick(); }, ids);
        const log = () => page.evaluate(() => ({ created: roLog.created, observe: roLog.observe, disconnect: roLog.disconnect, watching: roLog.watching.size }));

        assert.equal((await log()).created, 1, 'mounted() creates the observer');

        await setIds(['a']);
        await settle();
        assert.equal(await barHeight(), 90);
        assert.equal(await spacer(), 90, 'selecting reserves the bar height');
        assert.deepEqual(await log(), { created: 1, observe: 1, disconnect: 0, watching: 1 });

        // The tab goes to display:none: the bar has no box, so the observer reports 0 and the spacer follows.
        await page.evaluate(async () => { vm.shown = false; await Vue.nextTick(); });
        await settle();
        assert.equal(await spacer(), 0, 'a hidden tab reserves nothing');

        // Change the bar's height while hidden, then reveal: only the observer can move the spacer.
        await page.evaluate(() => { document.querySelector('#bar').style.height = '130px'; });
        await page.evaluate(async () => { vm.shown = true; await Vue.nextTick(); });
        await settle();
        assert.equal(await spacer(), 130, 'revealing the tab re-measures through the observer');

        await page.evaluate(() => { document.querySelector('#bar').style.height = '60px'; });
        await settle();
        assert.equal(await spacer(), 60, 'a wrapped line or rotation re-measures through the observer');

        // Repeated measurement of the same bar does not pile up observe() calls.
        const before = await log();
        await page.evaluate(() => { for (let i = 0; i < 3; i++) vm.$refs.tab.measureSelectionBar(); });
        assert.deepEqual(await log(), before, 'the same bar is observed once');

        // Replacing the bar element (new key) detaches the old one and watches only the new one.
        const oldBar = await page.evaluate(() => { window.oldBar = document.querySelector('#bar'); return true; });
        assert.equal(oldBar, true);
        await setIds(['a', 'b']);
        await settle();
        const swapped = await page.evaluate(() => ({ replaced: document.querySelector('#bar') !== window.oldBar, watchesOld: roLog.watching.has(window.oldBar), watchesNew: roLog.watching.has(document.querySelector('#bar')) }));
        assert.deepEqual(swapped, { replaced: true, watchesOld: false, watchesNew: true });
        assert.equal((await log()).watching, 1);

        // Clearing the selection removes the bar and stops watching it.
        await setIds([]);
        await settle();
        assert.equal(await spacer(), 0);
        assert.equal((await log()).watching, 0, 'nothing is watched once the bar is gone');

        // Unmounting disconnects the observer and drops the resize listener.
        await setIds(['a']);
        await settle();
        assert.equal((await log()).watching, 1);
        const disconnectsBefore = (await log()).disconnect;
        await page.evaluate(() => window.root.unmount());
        const after = await log();
        assert.ok(after.disconnect > disconnectsBefore, 'beforeUnmount disconnects the observer');
        assert.equal(after.watching, 0);
    } finally {
        await browser.close();
    }
});

// Five levels: Root, the ellipsis (Alpha and Beta hidden), Gamma, Delta.
for (const viewport of [{ width: 1440, height: 900 }, { width: 375, height: 812 }]) {
    vueTest(`at ${viewport.width}px the breadcrumb ellipsis menu works from the keyboard and hands focus back`, async () => {
        const { chromium } = require(process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH);
        const browser = await chromium.launch({ headless: true, channel: process.env.MOTHERSHIP_BROWSER_CHANNEL || 'chrome' });

        try {
            const page = await browser.newPage({ viewport });
            await mount(page);
            await land(page);

            const activeText = () => page.evaluate(() => `${document.activeElement.tagName}:${document.activeElement.textContent.trim()}`);
            const toggle = page.locator('[data-breadcrumb-overflow]');
            const menuOpen = async () => (await toggle.getAttribute('aria-expanded')) === 'true';
            const reachToggle = async () => { await page.focus('#before'); for (let i = 0; i < 3; i++) await page.keyboard.press('Tab'); };
            const crumbs = await page.locator('nav button').allTextContents();
            assert.deepEqual(crumbs.map((text) => text.trim()), ['Root', '…', 'Gamma', 'Delta']);

            // Enter opens, the first hidden ancestor takes focus, arrows move, Escape closes and refocuses the toggle.
            await reachToggle();
            assert.equal(await activeText(), 'BUTTON:…');
            await page.keyboard.press('Enter');
            assert.equal(await menuOpen(), true);
            assert.equal(await activeText(), 'BUTTON:Alpha');
            await page.keyboard.press('ArrowDown');
            assert.equal(await activeText(), 'BUTTON:Beta');
            await page.keyboard.press('ArrowUp');
            assert.equal(await activeText(), 'BUTTON:Alpha');
            await page.keyboard.press('Escape');
            assert.equal(await menuOpen(), false);
            assert.equal(await activeText(), 'BUTTON:…', 'Escape returns focus to the ellipsis');

            // Space opens too; choosing a hidden ancestor navigates there and focus lands on the trail, not <body>.
            await page.keyboard.press('Space');
            assert.equal(await menuOpen(), true);
            assert.equal(await activeText(), 'BUTTON:Alpha');
            await page.keyboard.press('ArrowDown');
            await page.keyboard.press('Enter');
            assert.equal(await page.locator('[data-dropdown-open]').count(), 0, 'choosing closes the menu');
            assert.deepEqual((await page.locator('nav button').allTextContents()).map((text) => text.trim()), ['Root', 'Alpha', 'Beta']);
            assert.equal(await activeText(), 'BUTTON:Beta', 'focus lands on the last crumb of the new trail');
            await land(page);
            assert.equal(await activeText(), 'BUTTON:Beta');
        } finally {
            await browser.close();
        }
    });
}

// A level hidden behind the ellipsis still takes a drop and opens in a new tab (VIS-10 review).
vueTest('hidden breadcrumb levels stay drop targets and open in a new tab', async () => {
    const { chromium } = require(process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH);
    const browser = await chromium.launch({ headless: true, channel: process.env.MOTHERSHIP_BROWSER_CHANNEL || 'chrome' });

    try {
        const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
        await mount(page);
        await land(page);

        const toggle = page.locator('[data-breadcrumb-overflow]');
        const payload = JSON.stringify({ type: 'dam-asset', id: 7, name: 'photo.jpg', tabId: 't1' });
        const dragEvent = (type, selector) => page.evaluate(({ type, selector, payload }) => {
            const data = new DataTransfer();
            data.setData('application/json', payload);
            document.querySelector(selector).dispatchEvent(new DragEvent(type, { bubbles: true, cancelable: true, dataTransfer: data }));
        }, { type, selector, payload });

        // Dragging over the ellipsis opens the menu, then Alpha takes the drop.
        await dragEvent('dragover', '[data-breadcrumb-overflow]');
        assert.equal(await toggle.getAttribute('aria-expanded'), 'true', 'a drag over the ellipsis opens the menu');
        const alpha = page.locator('[data-breadcrumb-hidden]', { hasText: 'Alpha' });
        await alpha.waitFor();
        await dragEvent('dragover', '[data-breadcrumb-hidden]');
        await dragEvent('drop', '[data-breadcrumb-hidden]');
        assert.deepEqual(await page.evaluate(() => window.events), [['drop', 'Alpha:2:dam-asset:7']]);
        assert.equal(await toggle.getAttribute('aria-expanded'), 'false', 'the menu closes after the drop');

        // The context menu on a hidden level offers Open in new tab.
        await toggle.click();
        await page.locator('[data-breadcrumb-hidden]', { hasText: 'Alpha' }).click({ button: 'right' });
        await page.getByText('Open in new tab', { exact: false }).first().click();
        assert.deepEqual(await page.evaluate(() => window.events.at(-1)), ['tab', 'Alpha']);
        assert.equal(await page.evaluate(() => window.events.length), 2, 'nothing navigated or dropped');
        assert.deepEqual((await page.locator('nav button').allTextContents()).map((text) => text.trim()), ['Root', '…', 'Gamma', 'Delta']);
    } finally {
        await browser.close();
    }
});

test('the History and asset-picker previous/next page controls are named buttons', () => {
    const lang = source('packages/Mothership/Admin/src/Resources/lang/en_US/app.php');

    for (const file of [
        'packages/Mothership/Admin/src/Resources/views/components/history/toolbar.blade.php',
        'packages/Mothership/DAM/src/Resources/views/components/asset/picker/toolbar.blade.php',
    ]) {
        const blade = stripBladeComments(source(file));

        for (const [direction, key] of [['previous', 'previous-page'], ['next', 'next-page']]) {
            const at = blade.indexOf(`@click="changePage('${direction}')"`);
            assert.notEqual(at, -1, `${file} must have a ${direction} page control`);
            const tag = blade.slice(blade.lastIndexOf('<', at), blade.indexOf('>', at) + 1);

            assert.match(tag, /^<button\b/, `${file} ${direction} page control must be a button`);
            assert.match(tag, /\stype="button"/, `${file} ${direction} page control must not submit a form`);
            assert.ok(tag.includes(`aria-label="@lang('admin::app.components.datagrid.toolbar.pagination.${key}')"`), `${file} ${direction} page control must be named`);
            assert.match(lang, new RegExp(`'${key}'\\s*=>`), `the ${key} string must exist`);
        }
    }
});
