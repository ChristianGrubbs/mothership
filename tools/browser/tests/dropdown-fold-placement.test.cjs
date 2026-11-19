// The shared dropdown (packages/Mothership/Admin/src/Resources/views/components/dropdown/index.blade.php)
// on a folding phone held in its laptop pose. An open menu stays on one half of the screen, never covers the
// toggle that opened it, scrolls when it is too tall for the room, and keeps its place when scrolling asks it
// to position itself again. Without a fold the menu opens where it always has.
//
// This mounts the real, unmodified component and the committed admin stylesheet, the same way
// dropdown-menu-keyboard.test.cjs does. The fold is described the way public/pwa/device.js describes it.
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '../../..');
const source = (file) => fs.readFileSync(path.join(root, file), 'utf8');

const dropdownFile = 'packages/Mothership/Admin/src/Resources/views/components/dropdown/index.blade.php';
const adminManifestFile = 'public/themes/admin/default/build/manifest.json';

const FOLD_START = 413;
const FOLD_END = 453;
const SCREEN = { width: 669, height: 867 };
const TOGGLE_HEIGHT = 42;
const ROW_HEIGHT = 40;

function extractDropdownComponent() {
    const blade = source(dropdownFile);
    const template = blade.match(/<script\s+type="text\/x-template"\s+id="v-dropdown-template"\s*>([\s\S]*?)<\/script>/);
    const registration = blade.match(/<script type="module">([\s\S]*?)<\/script>/);

    assert.ok(template && registration, 'the dropdown template and registration must exist');

    const withoutBladeComments = (text) => text.replace(/\{\{--[\s\S]*?--\}\}/g, '');

    return { template: withoutBladeComments(template[1]), registration: withoutBladeComments(registration[1]) };
}

function builtAdminStylesheet() {
    const manifest = JSON.parse(source(adminManifestFile));
    const entry = Object.values(manifest).find((item) => typeof item.file === 'string' && item.file.endsWith('.css') && item.file.startsWith('assets/app-'));

    assert.ok(entry, 'the committed admin manifest must list a built assets/app-*.css entry');

    return path.join(root, 'public/themes/admin/default/build', entry.file);
}

async function openMenu(page, { folded, toggleTop, rows, foldStart = FOLD_START, foldEnd = FOLD_END }) {
    const { template, registration } = extractDropdownComponent();
    const items = Array.from({ length: rows }, (_, index) => `<li style="height:${ROW_HEIGHT}px">Row ${index + 1}</li>`).join('');

    await page.setContent(`<!doctype html><html dir="ltr"><body><main id="app">
        <div style="position:fixed;left:40px;top:${toggleTop}px">
            <v-dropdown position="bottom-left" :teleport="true">
                <template v-slot:toggle>
                    <button type="button" id="toggle" style="display:block;width:120px;height:${TOGGLE_HEIGHT}px">More</button>
                </template>
                <template v-slot:menu>
                    <ul data-dropdown-menu>${items}</ul>
                </template>
            </v-dropdown>
        </div>
    </main></body></html>`);
    await page.addStyleTag({ path: builtAdminStylesheet() });
    await page.addScriptTag({ path: process.env.MOTHERSHIP_VUE_RUNTIME_PATH });

    await page.evaluate(({ template, folded, foldStart, foldEnd }) => {
        if (folded) {
            document.documentElement.dataset.fold = 'rows';
            document.documentElement.style.setProperty('--seg-a-bottom', `${foldStart}px`);
            document.documentElement.style.setProperty('--seg-b-top', `${foldEnd}px`);
        }

        const script = document.createElement('script');
        script.type = 'text/x-template';
        script.id = 'v-dropdown-template';
        script.textContent = template;
        document.body.append(script);

        window.app = Vue.createApp({});
    }, { template, folded, foldStart, foldEnd });

    await page.addScriptTag({ content: registration });
    await page.evaluate(() => app.mount('#app'));
    await page.click('#toggle');
    await page.waitForTimeout(150);
}

const boxes = (page) => page.evaluate(() => {
    const menu = [...document.body.children].find((node) => node.style?.zIndex === '10010');
    const panel = menu.getBoundingClientRect();
    const toggle = document.getElementById('toggle').getBoundingClientRect();

    return { top: panel.top, bottom: panel.bottom, toggleTop: toggle.top, toggleBottom: toggle.bottom, scrolls: menu.scrollHeight > menu.clientHeight + 1 };
});

const assertClearOfFoldAndToggle = (box, { foldStart = FOLD_START, foldEnd = FOLD_END } = {}) => {
    assert.ok(box.bottom <= foldStart + 0.5 || box.top >= foldEnd - 0.5, `the menu ${box.top}-${box.bottom} must not touch the fold`);
    assert.ok(box.bottom <= box.toggleTop + 0.5 || box.top >= box.toggleBottom - 0.5, `the menu ${box.top}-${box.bottom} must not cover its toggle`);
    assert.ok(box.top >= 0 && box.bottom <= SCREEN.height, `the menu ${box.top}-${box.bottom} must stay on the screen`);
};

const vueTest = process.env.MOTHERSHIP_VUE_RUNTIME_PATH && process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH ? test : test.skip;

async function withPage(fn) {
    const { chromium } = require(process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH);
    const browser = await chromium.launch({ headless: true, channel: process.env.MOTHERSHIP_BROWSER_CHANNEL || 'chrome' });

    try {
        await fn(await browser.newPage({ viewport: SCREEN }));
    } finally {
        await browser.close();
    }
}

vueTest('without a fold the menu opens directly below its toggle', () => withPage(async (page) => {
    await openMenu(page, { folded: false, toggleTop: 340, rows: 4 });

    const box = await boxes(page);

    assert.equal(box.top, box.toggleBottom + 4);
}));

vueTest('a menu that would reach the fold opens above a toggle on the upper half', () => withPage(async (page) => {
    await openMenu(page, { folded: true, toggleTop: 340, rows: 4 });

    const box = await boxes(page);

    assertClearOfFoldAndToggle(box);
    assert.equal(box.bottom, box.toggleTop - 4);
}));

vueTest('a toggle across the fold opens its menu wholly on one half', () => withPage(async (page) => {
    await openMenu(page, { folded: true, toggleTop: 400, rows: 3 });

    assertClearOfFoldAndToggle(await boxes(page));
}));

vueTest('a tall menu from a toggle across a thin fold starts below that toggle', () => withPage(async (page) => {
    const fold = { foldStart: 433, foldEnd: 433 };

    await openMenu(page, { folded: true, toggleTop: 410, rows: 16, ...fold });

    assertClearOfFoldAndToggle(await boxes(page), fold);
}));

vueTest('a menu too tall for the lower half scrolls there and leaves its toggle uncovered', () => withPage(async (page) => {
    await openMenu(page, { folded: true, toggleTop: 650, rows: 12 });

    const box = await boxes(page);

    assertClearOfFoldAndToggle(box);
    assert.ok(box.scrolls, 'the rows that do not fit must be reachable by scrolling');
}));

vueTest('positioning a capped menu again keeps its place and its height', () => withPage(async (page) => {
    await openMenu(page, { folded: true, toggleTop: 650, rows: 12 });

    const first = await boxes(page);

    for (let pass = 0; pass < 2; pass += 1) {
        await page.evaluate(() => window.dispatchEvent(new Event('resize')));
        await page.waitForTimeout(100);
    }

    assert.deepEqual(await boxes(page), first);
}));
