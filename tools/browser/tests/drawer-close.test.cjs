// The shared drawer (packages/Mothership/Admin/src/Resources/views/components/drawer/index.blade.php, used as
// <x-admin::drawer> for the navigation drawer in the admin header and for the filter drawers) could only be
// closed from a 30px <span> in its header: a tap on the dimmed page beside it did nothing, a keyboard could
// not reach the span, and on a phone every drawer covered the whole page, so there was no page left to tap.
// Now the header's close control is a labelled button, a tap on the overlay closes the drawer, and a drawer
// can keep its own width on a phone (the navigation drawer does), which leaves the page beside it in view.
//
// This renders the component's real Blade markup (the header slot with its close control) and mounts the
// real, unmodified Vue component with the committed admin stylesheet, the way modal-dialog-focus.test.cjs does.
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '../../..');
const source = (file) => fs.readFileSync(path.join(root, file), 'utf8');

const drawerFile = 'packages/Mothership/Admin/src/Resources/views/components/drawer/index.blade.php';
const headerFile = 'packages/Mothership/Admin/src/Resources/views/components/layouts/header/index.blade.php';
const deviceStylesheetFile = 'public/pwa/device.css';
const adminManifestFile = 'public/themes/admin/default/build/manifest.json';

// Blade drops `{{-- --}}` comments, resolves @lang() and turns `@{{` back into `{{`.
function blade(text) {
    return text
        .replace(/\{\{--[\s\S]*?--\}\}/g, '')
        .replace(/@lang\('admin::app\.components\.modal\.close-btn'\)/g, 'Close')
        .replace(/@\{\{/g, '{{');
}

const php = source(drawerFile);

function extractComponent() {
    const template = php.match(/<script\s+type="text\/x-template"\s+id="v-drawer-template"\s*>([\s\S]*?)<\/script>/);
    assert.ok(template, 'v-drawer-template must exist in the drawer component');

    const registration = php.match(/<script type="module">([\s\S]*?)<\/script>/);
    assert.ok(registration, 'the v-drawer registration must exist in the drawer component');

    return { template: blade(template[1]), registration: blade(registration[1]) };
}

// What <x-admin::drawer> renders for one call site: the <v-drawer> tag with the Blade props, and the header
// and content slot wrappers around the markup a page passes in.
function renderDrawer({ attributes, position = 'right', width = '500px', fullWidthOnPhone = true, header, content }) {
    const slot = (name, inner) => {
        const block = php.match(new RegExp(`@isset\\(\\$${name}\\)([\\s\\S]*?)@endisset`));
        assert.ok(block, `the ${name} slot must exist in drawer/index.blade.php`);

        return block[1]
            .replace(new RegExp(`\\{\\{ \\$${name}->attributes->merge\\(\\['class' => '([^']*)'\\]\\) \\}\\}`), 'class="$1"')
            .replace(new RegExp(`\\{\\{ \\$${name} \\}\\}`), inner);
    };

    const tag = php.match(/<v-drawer\b([\s\S]*?)>/);
    assert.ok(tag, 'the <v-drawer> tag must exist in drawer/index.blade.php');

    const props = tag[1]
        .replace('{{ $attributes }}', attributes)
        .replace('{{ $isActive }}', '')
        .replace('{{ $position }}', position)
        .replace('{{ $width }}', width)
        .replace("{{ $fullWidthOnPhone ? 'true' : 'false' }}", fullWidthOnPhone ? 'true' : 'false');
    assert.ok(! props.includes('{{'), `every Blade expression on <v-drawer> must be rendered by this test: ${props}`);

    return blade(`<v-drawer ${props}>${slot('header', header)}${slot('content', content)}</v-drawer>`);
}

function resolveBuiltAdminStylesheetPath() {
    const manifest = JSON.parse(source(adminManifestFile));
    const entry = Object.values(manifest).find((item) => typeof item.file === 'string' && item.file.endsWith('.css') && item.file.startsWith('assets/app-'));
    assert.ok(entry, 'the committed admin manifest must list a built assets/app-*.css entry');

    return path.join(root, 'public/themes/admin/default/build', entry.file);
}

// The navigation drawer as the admin header places it (left, 270px, keeps its width on a phone) and a filter
// drawer with the defaults (right, 500px, full width on a phone).
const fixture = `
    <header style="position: relative; z-index: 10001; height: 60px">
        <button type="button" id="menu" @click="$refs.navDrawer.open()">Menu</button>
        <button type="button" id="filters" @click="$refs.filterDrawer.open()">Filters</button>
    </header>

    <button type="button" id="page-action" style="position: fixed; right: 16px; top: 300px" @click="events.push('page-action')">Page action</button>

    ${renderDrawer({
        attributes: 'ref="navDrawer" @open="events.push(\'nav-open\')" @close="events.push(\'nav-close\')"',
        position: 'left',
        width: '270px',
        fullWidthOnPhone: false,
        header: '<p id="nav-title">Space</p>',
        content: '<a id="nav-link" href="#library">Library</a>',
    })}

    ${renderDrawer({
        attributes: 'ref="filterDrawer" @close="events.push(\'filter-close\')"',
        header: '<p id="filter-title">Filters</p>',
        content: '<input type="text" id="filter-name">',
    })}
`;

// The navigation drawer with the header's own list (its sizing class and the hook public/pwa/device.css styles) holding as
// many rows as the header's navigation, and the shell's tab bar, which is var(--tabbar-space) tall where it shows.
function navList() {
    const list = source(headerFile).match(/<div class="([^"]*)"\s+data-nav-drawer-list>/);
    assert.ok(list, 'the navigation drawer list in the admin header must carry data-nav-drawer-list');
    const rows = Array.from({ length: 14 }, (_, i) => `<a class="block" href="#row-${i}" style="height: 38px">Row ${i}</a>`).join('');

    return `<div class="${list[1]}" data-nav-drawer-list>${rows}</div>`;
}

const navFixture = `
    <button type="button" id="menu" @click="$refs.navDrawer.open()">Menu</button>

    ${renderDrawer({
        attributes: 'ref="navDrawer" data-nav-drawer',
        position: 'left',
        width: '270px',
        fullWidthOnPhone: false,
        header: '<p id="nav-title">Space</p>',
        content: navList(),
    })}

    <nav id="tabbar" style="position: fixed; left: 0; right: 0; bottom: 0; height: var(--tabbar-space); z-index: 10002; background: #fff"></nav>
`;

async function mount(page, markup = fixture) {
    const { template, registration } = extractComponent();

    // The admin layout's document: left to right (the drawer's side classes are ltr:/rtl: variants) and sized to the device.
    await page.setContent(`<!doctype html><html dir="ltr"><head><meta name="viewport" content="width=device-width, initial-scale=1"></head><body><main id="app">${markup}</main></body></html>`);
    await page.addStyleTag({ path: resolveBuiltAdminStylesheetPath() });
    await page.addStyleTag({ path: path.join(root, deviceStylesheetFile) });
    await page.addScriptTag({ path: process.env.MOTHERSHIP_VUE_RUNTIME_PATH });

    await page.evaluate((template) => {
        const script = document.createElement('script');
        script.type = 'text/x-template';
        script.id = 'v-drawer-template';
        script.textContent = template;
        document.body.append(script);

        window.scrollLocks = 0;
        window.lockBodyScroll = () => { window.scrollLocks += 1; };
        window.unlockBodyScroll = () => { window.scrollLocks -= 1; };

        window.app = Vue.createApp({ data: () => ({ events: [] }) });
    }, template);

    await page.addScriptTag({ content: registration });
    await page.evaluate(() => { window.vm = app.mount('#app'); });
}

const events = (page) => page.evaluate(() => [...window.vm.events]);
const panels = (page) => page.locator('[data-drawer-panel]');

// A closing drawer stays in the DOM for its leave transition, so wait for the count instead of sampling it.
const expectPanels = (page, count) => page.waitForFunction((count) => document.querySelectorAll('[data-drawer-panel]').length === count, count, { timeout: 2000 });

// The box of the first visible match, and whether a finger at its centre lands on it.
const reach = (page, selector) => page.evaluate((selector) => {
    const element = [...document.querySelectorAll(selector)].find((node) => node.getClientRects().length);
    const box = element.getBoundingClientRect();
    const top = document.elementFromPoint(box.left + box.width / 2, box.top + box.height / 2);

    return { left: box.left, right: box.right, top: box.top, width: box.width, height: box.height, reaches: top === element || element.contains(top) };
}, selector);

const tap = async (page, selector) => {
    const box = await reach(page, selector);
    await page.touchscreen.tap(box.left + box.width / 2, box.top + box.height / 2);
};

const vueTest = process.env.MOTHERSHIP_VUE_RUNTIME_PATH && process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH ? test : test.skip;

// An iPhone Duo's outer screen in the installed app: about 385 CSS px wide, a finger for a pointer.
const phone = { viewport: { width: 385, height: 640 }, hasTouch: true, isMobile: true };

async function withPage(fn, context = phone, markup = fixture) {
    const { chromium } = require(process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH);
    const browser = await chromium.launch({ headless: true, channel: process.env.MOTHERSHIP_BROWSER_CHANNEL || 'chrome' });

    try {
        const page = await (await browser.newContext(context)).newPage();
        await mount(page, markup);
        await fn(page);
    } finally {
        await browser.close();
    }
}

test('the navigation drawer in the admin header keeps its width on a phone', () => {
    const drawer = source(headerFile).match(/<x-admin::drawer\b[\s\S]*?ref="sidebarMenuDrawer"[\s\S]*?>/);
    assert.ok(drawer, 'the admin header must hold the navigation drawer');
    assert.match(drawer[0], /:full-width-on-phone="false"/);
    assert.match(drawer[0], /\sdata-nav-drawer\b/, 'the device stylesheet finds the navigation drawer by this hook');
});

// The bottom of the list after it has been scrolled to its end, and the top of the tab bar.
const scrollNavListToEnd = (page) => page.evaluate(() => {
    const list = document.querySelector('[data-nav-drawer-list]');
    list.scrollTop = list.scrollHeight;

    return { tabbarTop: document.querySelector('#tabbar').getBoundingClientRect().top, padding: getComputedStyle(list).paddingBottom };
});

// The iPhone Duo's outer display (382 x 580) and any short phone: the tab bar sits over the foot of the drawer.
vueTest('on a short phone the last row of the navigation drawer scrolls clear of the tab bar', () => withPage(async (page) => {
    await tap(page, '#menu');
    await expectPanels(page, 1);
    await page.waitForTimeout(300);

    const { tabbarTop } = await scrollNavListToEnd(page);
    assert.equal(tabbarTop, 580 - 78, 'the phone form of the shell shows its 78px tab bar');

    const last = await reach(page, '[href="#row-13"]');
    assert.ok(last.top + last.height <= tabbarTop, `the last row ends above the tab bar: ${JSON.stringify(last)}`);
    assert.ok(last.reaches, 'a finger on the last row lands on it, not on the tab bar');
}, { ...phone, viewport: { width: 382, height: 580 } }, navFixture));

// Folded like a laptop (public/pwa/device.js sets these on the root; this test sets them as it would, with a 40px hinge).
vueTest('folded like a laptop the navigation drawer opens on the lower segment and every row scrolls into reach there', () => withPage(async (page) => {
    await page.evaluate(() => {
        const html = document.documentElement;
        html.dataset.fold = 'rows';
        html.style.setProperty('--seg-b-top', '453px');
        html.style.setProperty('--seg-b-height', '414px');
    });
    await tap(page, '#menu');
    await expectPanels(page, 1);
    await page.waitForTimeout(300);

    const panel = await reach(page, '[data-drawer-panel]');
    assert.equal(panel.top, 453, 'the drawer starts below the hinge');

    // From the end of the list upward, as a finger scrolls back: each row comes into view at the top of the list.
    const { tabbarTop } = await scrollNavListToEnd(page);
    for (let i = 13; i >= 0; i--) {
        await page.evaluate((i) => document.querySelector(`[href="#row-${i}"]`).scrollIntoView({ block: 'nearest' }), i);
        const row = await reach(page, `[href="#row-${i}"]`);
        assert.ok(row.top >= 453 && row.top + row.height <= tabbarTop && row.reaches, `row ${i} in reach below the hinge: ${JSON.stringify(row)}`);
    }
}, { ...phone, viewport: { width: 669, height: 867 } }, navFixture));

vueTest('where the shell shows no tab bar the navigation list keeps no room for one', () => withPage(async (page) => {
    await page.click('#menu');
    await expectPanels(page, 1);

    assert.equal((await scrollNavListToEnd(page)).padding, '0px');
}, { viewport: { width: 951, height: 585 } }, navFixture));

vueTest('on a phone the navigation drawer leaves the page in view, and a tap there closes it', () => withPage(async (page) => {
    await tap(page, '#menu');
    await expectPanels(page, 1);
    await page.waitForTimeout(300);

    const panel = await reach(page, '[data-drawer-panel]');
    assert.equal(panel.left, 0);
    assert.equal(panel.width, 270, 'the drawer keeps its 270px instead of covering the 385px page');

    // A finger on the dimmed page beside the drawer lands on the overlay, not on the page's own control under it.
    const action = await reach(page, '#page-action');
    assert.ok(action.left > 270 && ! action.reaches, 'the page control sits beside the drawer, under the overlay');
    await page.touchscreen.tap(action.left + action.width / 2, action.top + action.height / 2);
    await expectPanels(page, 0);
    assert.deepEqual(await events(page), ['nav-open', 'nav-close'], 'the overlay closes the drawer and the tap does not reach the page under it');
    assert.equal(await page.evaluate(() => window.scrollLocks), 0, 'the page scrolls again');
}));

vueTest('a narrower phone still shows a strip of the page beside the navigation drawer', () => withPage(async (page) => {
    await tap(page, '#menu');
    await expectPanels(page, 1);
    await page.waitForTimeout(300);

    const panel = await reach(page, '[data-drawer-panel]');
    assert.equal(panel.width, 300 - 56, 'the drawer stops 56px short of a 300px page');

    await page.touchscreen.tap(290, 320);
    await expectPanels(page, 0);
}, { ...phone, viewport: { width: 300, height: 640 } }));

vueTest('the header close control is a labelled button a finger and a keyboard can reach', () => withPage(async (page) => {
    await tap(page, '#menu');
    await expectPanels(page, 1);
    await page.waitForTimeout(300);

    const close = panels(page).locator('[data-drawer-close]');
    assert.equal(await close.evaluate((element) => element.tagName), 'BUTTON');
    assert.equal(await close.getAttribute('type'), 'button');
    assert.equal(await close.getAttribute('aria-label'), 'Close');

    const box = await reach(page, '[data-drawer-close]');
    assert.ok(box.reaches, 'nothing covers the close button');
    assert.ok(box.left >= 0 && box.right <= 270, 'the close button sits inside the drawer');

    await tap(page, '[data-drawer-close]');
    await expectPanels(page, 0);

    // The keyboard: Tab reaches the button and Enter presses it.
    await page.evaluate(() => window.vm.$refs.navDrawer.open());
    await expectPanels(page, 1);
    await close.focus();
    assert.equal(await page.evaluate(() => document.activeElement.getAttribute('aria-label')), 'Close');
    await page.keyboard.press('Enter');
    await expectPanels(page, 0);

    assert.deepEqual(await events(page), ['nav-open', 'nav-close', 'nav-open', 'nav-close']);
}));

vueTest('a drawer with the defaults still covers a phone and closes from its button', () => withPage(async (page) => {
    await tap(page, '#filters');
    await expectPanels(page, 1);
    await page.waitForTimeout(300);

    const panel = await reach(page, '[data-drawer-panel]');
    assert.equal(panel.width, 385, 'a filter drawer is full width on a phone, as before');

    const close = await reach(page, '[data-drawer-close]');
    assert.ok(close.reaches && close.right <= 385, 'its close button is on the page and on top');

    await tap(page, '[data-drawer-close]');
    await expectPanels(page, 0);
    assert.deepEqual(await events(page), ['filter-close']);
}));

vueTest('on a wide screen a click on the overlay closes a drawer and a click inside it does not', () => withPage(async (page) => {
    await page.click('#filters');
    await expectPanels(page, 1);
    await page.waitForTimeout(300);

    const panel = await reach(page, '[data-drawer-panel]');
    assert.equal(panel.width, 500);
    assert.ok(panel.left > 700, 'the drawer sits on the right and leaves the page beside it in view');

    await page.click('#filter-name');
    await page.mouse.click(panel.left + 250, 500);
    assert.equal(await panels(page).count(), 1, 'a click inside the drawer leaves it open');

    await page.mouse.click(300, 500);
    await expectPanels(page, 0);
    assert.deepEqual(await events(page), ['filter-close']);
}, { viewport: { width: 1280, height: 800 } }));
