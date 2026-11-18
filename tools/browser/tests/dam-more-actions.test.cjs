const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '../../..');
const EDIT = path.join(root, 'packages/Mothership/DAM/src/Resources/views/asset/edit.blade.php');
const vueRuntime = process.env.MOTHERSHIP_VUE_RUNTIME_PATH || path.join(root, 'packages/Mothership/DAM/node_modules/vue/dist/vue.global.js');

// The asset header's "More" group (operator, 2026-10-30): below 525px, or 480px tall or less, the less used actions fold
// behind a toggle. The toggle shows only when the group holds an action the user may use, and Re-Upload is a label, not
// a button (Codex review: a re-upload-only role saw no toggle and lost the action).
function moreActionsSource() {
    const source = fs.readFileSync(EDIT, 'utf8');
    const template = source.match(/<script type="text\/x-template" id="v-dam-more-actions-template">([\s\S]*?)<\/script>/);
    assert.ok(template, 'the v-dam-more-actions template must still be in edit.blade.php');
    const marker = "app.component('v-dam-more-actions', ";
    const at = source.indexOf(marker);
    assert.notEqual(at, -1, 'the v-dam-more-actions component must still be in edit.blade.php');
    let depth = 0;
    let end = at + marker.length;
    for (; end < source.length; end++) {
        if (source[end] === '{') depth++;
        else if (source[end] === '}' && --depth === 0) break;
    }

    return {
        template: template[1].replace(/\{\{--[\s\S]*?--\}\}/g, '').replace(/@\{\{/g, '{{'),
        options: source.slice(at + marker.length, end + 1),
    };
}

function adminStylesheet() {
    const dir = 'public/themes/admin/default/build';
    const manifest = JSON.parse(fs.readFileSync(path.join(root, dir, 'manifest.json'), 'utf8'));
    const entry = Object.values(manifest).find((item) => typeof item.file === 'string' && item.file.startsWith('assets/app-') && item.file.endsWith('.css'));
    assert.ok(entry, `${dir}/manifest.json must list a built assets/app-*.css entry`);

    return path.join(root, dir, entry.file);
}

async function withPage(run) {
    const {chromium} = require(process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH);
    const browser = await chromium.launch({headless: true, channel: process.env.MOTHERSHIP_BROWSER_CHANNEL || 'chrome'});
    try { await run(await browser.newPage()); } finally { await browser.close(); }
}

async function mount(page, {width, height, slot}) {
    const {template, options} = moreActionsSource();
    await page.setViewportSize({width, height});
    await page.setContent(`<!doctype html><html><head></head><body><div id="app"><div class="flex flex-wrap gap-2" data-row><button type="button" data-primary>Download original</button><v-dam-more-actions label="More" less-label="Fewer">${slot}</v-dam-more-actions></div></div><script type="text/x-template" id="v-dam-more-actions-template">${template}</script></body></html>`);
    await page.addStyleTag({path: adminStylesheet()});
    await page.addScriptTag({path: vueRuntime});
    await page.evaluate((options) => {
        window._damShownStackFile = {computed: {stackFileShown: () => false}};
        const app = Vue.createApp({});
        const handlers = {};
        app.config.globalProperties.$emitter = {
            on: (name, fn) => { (handlers[name] ||= []).push(fn); },
            off: (name, fn) => { handlers[name] = (handlers[name] || []).filter((h) => h !== fn); },
            emit: (name, ...args) => (handlers[name] || []).forEach((fn) => fn(...args)),
        };
        window.__emitter = app.config.globalProperties.$emitter;
        app.component('v-dam-more-actions', new Function(`return (${options});`)());
        app.mount('#app');
    }, options);
}

const visible = (page, selector) => page.evaluate((s) => {
    const el = document.querySelector(s);
    return !! el && el.getClientRects().length > 0 && getComputedStyle(el).visibility !== 'hidden';
}, selector);

const REUPLOAD_ONLY = '<div class="flex items-center gap-1.5" data-dam-asset-actions data-dam-asset-danger-actions><label class="secondary-button" data-dam-asset-actions data-reupload>Re-Upload<input type="file" class="hidden"></label></div>';
const NO_ACTIONS = '<div class="flex items-center gap-1.5" data-dam-asset-actions data-dam-asset-danger-actions></div>';

test('a role with only Re-Upload gets the More toggle on a phone and on a short screen, and it reveals the action', async () => {
    await withPage(async (page) => {
        for (const [width, height] of [[375, 812], [1024, 400]]) {
            await mount(page, {width, height, slot: REUPLOAD_ONLY});
            assert.equal(await visible(page, '[data-dam-more-actions-toggle]'), true, `${width}x${height}: the toggle shows for a Re-Upload label`);
            assert.equal(await visible(page, '[data-reupload]'), false, `${width}x${height}: Re-Upload starts folded`);
            await page.click('[data-dam-more-actions-toggle]');
            assert.equal(await page.getAttribute('[data-dam-more-actions-toggle]', 'aria-expanded'), 'true');
            assert.equal(await visible(page, '[data-reupload]'), true, `${width}x${height}: More reveals Re-Upload`);
        }
    });
});

test('with no action the user may use there is no toggle; on a tall wide screen nothing folds', async () => {
    await withPage(async (page) => {
        await mount(page, {width: 375, height: 812, slot: NO_ACTIONS});
        assert.equal(await visible(page, '[data-dam-more-actions-toggle]'), false, 'an empty group shows no toggle');

        await mount(page, {width: 1024, height: 800, slot: REUPLOAD_ONLY});
        assert.equal(await visible(page, '[data-dam-more-actions-toggle]'), false, 'from 525px wide and over 480px tall there is no toggle');
        assert.equal(await visible(page, '[data-reupload]'), true, 'and the action sits in the row');
    });
});

test('the Details pencil (dam-open-asset-rename) unfolds the group, since the Rename modal lives inside it', async () => {
    await withPage(async (page) => {
        await mount(page, {width: 375, height: 812, slot: REUPLOAD_ONLY});
        await page.evaluate(() => window.__emitter.emit('dam-open-asset-rename', 'title'));
        assert.equal(await page.getAttribute('[data-dam-more-actions-toggle]', 'aria-expanded'), 'true');
        assert.equal(await visible(page, '[data-reupload]'), true);
    });
});
