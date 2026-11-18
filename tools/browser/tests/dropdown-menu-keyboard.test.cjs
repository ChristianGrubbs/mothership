// The shared dropdown (packages/Mothership/Admin/src/Resources/views/components/dropdown/index.blade.php)
// follows the WAI-ARIA menu button pattern for its menu slot: the toggle carries aria-haspopup and
// aria-expanded, opening from the keyboard focuses the first item, the arrow keys move between items,
// Enter and Space activate, and Escape or choosing an item hands focus back to the toggle. Before this,
// a keyboard user could open the Library's "Generate missing 3D thumbnails" menu but never reach an item.
// A menu slot that holds a search box or a form (saved filters) is not a menu and keeps its keys.
// An item that holds its own <ul> is a submenu parent (the datagrid mass actions with options): the
// side arrow or Enter opens it onto its first option, Escape or the opposite arrow closes it again.
//
// This mounts the real, unmodified component and the committed admin stylesheet, the same way
// avatar-account-menu.test.cjs does, so it also pins the focus ring: a pill button keeps its pill shape
// while focused, and a focused item draws an inset ring.
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '../../..');
const source = (file) => fs.readFileSync(path.join(root, file), 'utf8');

const dropdownFile = 'packages/Mothership/Admin/src/Resources/views/components/dropdown/index.blade.php';
const controlFile = 'packages/Mothership/Admin/src/Resources/views/components/form/control-group/control.blade.php';
const adminManifestFile ='public/themes/admin/default/build/manifest.json';

// Blade drops `{{-- --}}` comments at compile time; the raw source still has them.
function stripBladeComments(text) {
    return text.replace(/\{\{--[\s\S]*?--\}\}/g, '');
}

function extractDropdownComponent() {
    const blade = source(dropdownFile);

    const template = blade.match(/<script\s+type="text\/x-template"\s+id="v-dropdown-template"\s*>([\s\S]*?)<\/script>/);
    assert.ok(template, 'v-dropdown-template must exist in dropdown/index.blade.php');

    const registration = blade.match(/<script type="module">([\s\S]*?)<\/script>/);
    assert.ok(registration, "app.component('v-dropdown', ...) must exist in dropdown/index.blade.php");

    // The fixtures below hand-write the list the Blade menu slot renders, so pin that it is marked.
    assert.match(blade, /<template v-slot:menu>\s*<ul\s+data-dropdown-menu/, 'the menu slot must render its list with data-dropdown-menu');

    return { template: stripBladeComments(template[1]), registration: stripBladeComments(registration[1]) };
}

function resolveBuiltAdminStylesheetPath() {
    const manifest = JSON.parse(source(adminManifestFile));
    const entry = Object.values(manifest).find((item) => typeof item.file === 'string' && item.file.endsWith('.css') && item.file.startsWith('assets/app-'));
    assert.ok(entry, 'the committed admin manifest must list a built assets/app-*.css entry');

    return path.join(root, 'public/themes/admin/default/build', entry.file);
}

// Three call-site shapes: <li @click> items (the Library menus), <li><button> items (gallery cards)
// with a pointer-disabled item, and a teleported menu.
const fixture = `
    <v-dropdown position="bottom-right" class="relative" data-case="plain">
        <template v-slot:toggle>
            <button type="button" class="secondary-button">Generate</button>
        </template>
        <template v-slot:menu>
            <ul data-dropdown-menu class="py-4">
                <li class="px-4 py-2" @click="pick('this-folder')">In this folder</li>
                <li class="px-4 py-2" @click="pick('every-folder')">In every folder</li>
                <li class="px-4 py-2" @click="pick('third')">Third</li>
            </ul>
        </template>
    </v-dropdown>

    <v-dropdown class="relative" data-case="buttons">
        <template v-slot:toggle>
            <button type="button">Card actions</button>
        </template>
        <template v-slot:menu>
            <ul data-dropdown-menu class="py-4">
                <li><button type="button" @click="pick('open')">Open</button></li>
                <li class="pointer-events-none" @click="pick('busy')">Busy</li>
                <li><button type="button" disabled @click="pick('archived')">Archived</button></li>
                <li><button type="button" @click="pick('delete')">Delete</button></li>
            </ul>
        </template>
    </v-dropdown>

    <v-dropdown class="relative" data-case="teleport" :teleport="true">
        <template v-slot:toggle>
            <button type="button">Teleported</button>
        </template>
        <template v-slot:menu>
            <ul data-dropdown-menu class="py-4">
                <li class="px-4 py-2" @click="pick('t1')">T one</li>
                <li class="px-4 py-2" @click="pick('t2')">T two</li>
            </ul>
        </template>
    </v-dropdown>

    <v-dropdown class="relative" data-case="composite" :close-on-click="false">
        <template v-slot:toggle>
            <button type="button">Saved filters</button>
        </template>
        <template v-slot:menu>
            <ul data-dropdown-menu class="py-4">
                <div><input type="text" data-search></div>
                <div @click="pick('clear')">Clear</div>
            </ul>
        </template>
    </v-dropdown>

    <v-dropdown class="relative" data-case="nested" :close-on-click="false">
        <template v-slot:toggle>
            <button type="button">Outer</button>
        </template>
        <template v-slot:content>
            <v-dropdown class="relative" data-case="inner">
                <template v-slot:toggle>
                    <button type="button">Inner</button>
                </template>
                <template v-slot:menu>
                    <ul data-dropdown-menu class="py-4">
                        <li class="px-4 py-2" @click="pick('i1')">I one</li>
                        <li class="px-4 py-2" @click="pick('i2')">I two</li>
                        <li class="px-4 py-2" @click="pick('i3')">I three</li>
                    </ul>
                </template>
            </v-dropdown>
        </template>
    </v-dropdown>

    <v-dropdown class="relative" data-case="mass">
        <template v-slot:toggle>
            <button type="button">Select action</button>
        </template>
        <template v-slot:menu>
            <ul data-dropdown-menu class="!p-0">
                <li>
                    <a class="flex px-4 py-2" href="javascript:void(0);" @click="pick('mass:delete')">Delete</a>
                </li>
                <li class="group/item relative overflow-visible">
                    <a class="flex justify-between px-4 py-2 cursor-not-allowed" href="javascript:void(0);">Update</a>
                    <ul class="absolute ltr:left-full rtl:right-full top-0 z-10 hidden w-max min-w-[150px] border rounded bg-white shadow group-hover/item:block">
                        <li><a class="block px-4 py-2" href="javascript:void(0);" @click="pick('mass:active')">Active</a></li>
                        <li><a class="block px-4 py-2" href="javascript:void(0);" @click="pick('mass:inactive')">Inactive</a></li>
                    </ul>
                </li>
                <li>
                    <a class="flex px-4 py-2" href="javascript:void(0);" @click="pick('mass:export')">Export</a>
                </li>
            </ul>
        </template>
    </v-dropdown>

    <button type="button" id="after">After</button>

    <div data-case="phone-bar" class="fixed bottom-0 inset-x-0 z-50 flex flex-wrap items-center gap-2 bg-white px-3 pt-3 pb-3 shadow">
        <span class="whitespace-nowrap">2 files selected</span>
        <button type="button" class="whitespace-nowrap px-4" style="min-width:130px">Select all 50</button>
        <button type="button" class="whitespace-nowrap px-4" style="min-width:130px">Clear selection</button>
        <v-dropdown position="bottom-left" :teleport="true" data-case="bar-menu">
            <template v-slot:toggle>
                <button type="button">Bulk actions</button>
            </template>
            <template v-slot:menu>
                <ul data-dropdown-menu class="py-4">
                    <li><button type="button" class="block px-4 py-2" @click="pick('b1')">Move</button></li>
                    <li><button type="button" class="block px-4 py-2" @click="pick('b2')">Copy</button></li>
                    <li><button type="button" class="block px-4 py-2" @click="pick('b3')">Tag</button></li>
                    <li><button type="button" class="block px-4 py-2" @click="pick('b4')">Share</button></li>
                    <li><button type="button" class="block px-4 py-2" @click="pick('b5')">Download</button></li>
                    <li><button type="button" class="block px-4 py-2" @click="pick('bulk-delete')">Delete</button></li>
                </ul>
            </template>
        </v-dropdown>
    </div>
`;

async function mount(page) {
    const { template, registration } = extractDropdownComponent();

    // The admin layout sets dir on <html>; the ltr:/rtl: utilities key off it.
    await page.setContent(`<!doctype html><html dir="ltr"><body><main id="app" style="display:flex;gap:24px;padding:24px">${fixture}</main></body></html>`);
    await page.addStyleTag({ path: resolveBuiltAdminStylesheetPath() });
    await page.addScriptTag({ path: process.env.MOTHERSHIP_VUE_RUNTIME_PATH });

    await page.evaluate((template) => {
        const script = document.createElement('script');
        script.type = 'text/x-template';
        script.id = 'v-dropdown-template';
        script.textContent = template;
        document.body.append(script);

        window.picked = [];
        window.app = Vue.createApp({ methods: { pick: (value) => window.picked.push(value) } });
    }, template);

    await page.addScriptTag({ content: registration });
    await page.evaluate(() => app.mount('#app'));
}

const toggleOf = (page, name) => page.locator(`[data-case="${name}"] button`).first();
const active = (page) => page.evaluate(() => {
    const el = document.activeElement;

    return `${el.getAttribute('role') ?? el.tagName.toLowerCase()}:${el.textContent.trim()}`;
});
const picked = (page) => page.evaluate(() => window.picked);
const settle = (page) => page.waitForTimeout(150);

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

vueTest('a keyboard user can open, move through, activate and leave a dropdown menu', () => withPage(async (page) => {
    const toggle = toggleOf(page, 'plain');

    assert.equal(await toggle.getAttribute('aria-haspopup'), 'menu');
    assert.equal(await toggle.getAttribute('aria-expanded'), 'false');

    await toggle.focus();
    await page.keyboard.press('Enter');
    await settle(page);

    assert.equal(await toggle.getAttribute('aria-expanded'), 'true');
    assert.equal(await active(page), 'menuitem:In this folder');
    assert.equal(await page.locator('[data-case="plain"] ul').getAttribute('role'), 'menu');

    await page.keyboard.press('ArrowDown');
    assert.equal(await active(page), 'menuitem:In every folder');
    await page.keyboard.press('End');
    assert.equal(await active(page), 'menuitem:Third');
    await page.keyboard.press('ArrowDown');
    assert.equal(await active(page), 'menuitem:In this folder', 'ArrowDown wraps from the last item to the first');
    await page.keyboard.press('ArrowUp');
    assert.equal(await active(page), 'menuitem:Third', 'ArrowUp wraps from the first item to the last');
    await page.keyboard.press('Home');
    assert.equal(await active(page), 'menuitem:In this folder');

    await page.keyboard.press('ArrowDown');
    await page.keyboard.press('Enter');
    await settle(page);

    assert.deepEqual(await picked(page), ['every-folder']);
    assert.equal(await toggle.getAttribute('aria-expanded'), 'false');
    assert.equal(await active(page), 'button:Generate', 'choosing an item hands focus back to the toggle');

    // ArrowUp on the closed toggle opens on the last item; Space activates; Escape closes.
    await page.keyboard.press('ArrowUp');
    await settle(page);
    assert.equal(await active(page), 'menuitem:Third');
    await page.keyboard.press(' ');
    await settle(page);
    assert.deepEqual(await picked(page), ['every-folder', 'third']);

    await page.keyboard.press('ArrowDown');
    await settle(page);
    assert.equal(await active(page), 'menuitem:In this folder');
    await page.keyboard.press('Escape');
    await settle(page);
    assert.equal(await toggle.getAttribute('aria-expanded'), 'false');
    assert.equal(await active(page), 'button:Generate');

    // Tab closes the menu and carries on from the toggle to the next control on the page.
    await page.keyboard.press('Enter');
    await settle(page);
    await page.keyboard.press('Tab');
    await settle(page);
    assert.equal(await toggle.getAttribute('aria-expanded'), 'false');
    assert.equal(await active(page), 'button:Card actions');
}));

// The real bug shape: the explorer's phone selection bar is fixed to the bottom edge, so a panel opening
// downward from its Bulk actions toggle ran off-screen. The teleported dropdown has to flip above the toggle
// and stay inside the viewport, with every item reachable by the pointer. (That the page reserves room for
// the bar, and re-measures on clear, on crossing 640 px and when a hidden tab is revealed, is pinned against
// the real v-dam-tab in explorer-pager-keyboard.test.cjs; the node harness cannot mount the whole explorer.)
vueTest('a teleported menu in a bottom-fixed, wrapped phone bar opens upward inside the viewport', () => withPage(async (page) => {
    await page.setViewportSize({ width: 375, height: 667 });

    const toggle = page.locator('[data-case="bar-menu"] button').first();

    // The bar really wraps: its controls sit on more than one row, so it is taller than a single row.
    const rows = await page.evaluate(() => {
        const bar = document.querySelector('[data-case="phone-bar"]');
        const tops = [...bar.querySelectorAll('span, button')].map((el) => Math.round(el.getBoundingClientRect().top));

        return { rows: new Set(tops).size, barHeight: bar.getBoundingClientRect().height, buttonHeight: bar.querySelector('button').getBoundingClientRect().height };
    });
    assert.ok(rows.rows >= 2, `the controls occupy ${rows.rows} row(s); the bar must wrap onto a second row`);
    assert.ok(rows.barHeight > rows.buttonHeight * 1.8, 'the wrapped bar is taller than one row of controls');
    await toggle.focus();
    await page.keyboard.press('Enter');
    await settle(page);

    assert.equal(await active(page), 'menuitem:Move');

    const [toggleBox, panel] = await Promise.all([
        toggle.boundingBox(),
        page.evaluate(() => {
            const menu = [...document.querySelectorAll('body > .fixed')].find((el) => el.textContent.includes('Download'));
            const last = [...menu.querySelectorAll('button')].find((el) => el.textContent.trim() === 'Delete');
            const centre = last.getBoundingClientRect();
            const hit = document.elementFromPoint(centre.x + centre.width / 2, centre.y + centre.height / 2);

            return { lastHit: hit === last, width: innerWidth, height: innerHeight, panel: menu.getBoundingClientRect().toJSON() };
        }),
    ]);

    assert.ok(panel.panel.bottom <= toggleBox.y + 1, 'the panel sits above the toggle, not below it');
    assert.ok(panel.panel.top >= 0 && panel.panel.left >= 0 && panel.panel.right <= panel.width && panel.panel.bottom <= panel.height, 'the panel stays inside the viewport');
    assert.equal(panel.lastHit, true, 'the last item is the element under the pointer, not covered by the bar');

    await page.keyboard.press('End');
    await page.keyboard.press('Enter');
    await settle(page);
    assert.deepEqual(await picked(page), ['bulk-delete']);

    // Crossing 640 px does not leave the bar's menu stranded: reopen on a wide viewport and it still fits.
    await page.setViewportSize({ width: 800, height: 667 });
    await toggle.focus();
    await page.keyboard.press('Enter');
    await settle(page);
    const wide = await page.evaluate(() => [...document.querySelectorAll('body > .fixed')].find((el) => el.textContent.includes('Download')).getBoundingClientRect().toJSON());
    assert.ok(wide.top >= 0 && wide.bottom <= 667 && wide.right <= 800, 'the reopened panel fits at a wider viewport too');
}));

vueTest('focus ring keeps the toggle its shape and draws an inset ring on the focused item', () => withPage(async (page) => {
    const toggle = toggleOf(page, 'plain');

    await toggle.focus();
    await page.keyboard.press('Enter');
    await settle(page);

    const styles = await page.evaluate(() => {
        const button = document.querySelector('[data-case="plain"] button');
        const item = getComputedStyle(document.activeElement);
        const itemStyles = { itemOutline: item.outlineStyle, itemOffset: item.outlineOffset };

        button.blur();
        const restRadius = getComputedStyle(button).borderTopLeftRadius;

        button.focus();

        return { ...itemStyles, restRadius, toggleRadius: getComputedStyle(button).borderTopLeftRadius };
    });

    assert.equal(styles.restRadius, '12px', 'secondary-button uses the control radius');
    assert.equal(styles.toggleRadius, styles.restRadius, 'secondary-button keeps its shape while focused');
    assert.equal(styles.itemOutline, 'solid');
    assert.equal(styles.itemOffset, '-2px');
}));

// WCAG 2.2 non-text contrast wants the focus indicator at 3:1 against the surface. primary-600 clears
// that on white but not on every raised dark surface, so dark mode lifts the ring to primary-400. The geometry test above never reads the colour, so dropping a dark variant passed
// silently. The checkbox and radio icons are siblings of the focused input, so the global rule does not
// reach them and they carry their own peer-focus-visible classes, read here from the Blade source.
vueTest('the focus ring is primary-600 in light and primary-400 in dark, including checkbox and radio icons', () => withPage(async (page) => {
    const controlBlade = source(controlFile);
    const checkboxClass = controlBlade.match(/'class' => '(icon-checkbox-normal[^']*)'/)?.[1];
    const radioClass = controlBlade.match(/class="(icon-radio-normal[^"]*)"/)?.[1];
    assert.ok(checkboxClass && radioClass, 'the checkbox and radio icon classes must exist in control-group/control.blade.php');

    await page.evaluate(({ checkboxClass, radioClass }) => {
        document.body.insertAdjacentHTML('beforeend', `
            <div id="ring-fixture" style="padding:24px;display:flex;gap:24px">
                <button type="button" id="ring-global">Plain control</button>
                <span><input type="checkbox" id="ring-checkbox" class="sr-only peer"><label for="ring-checkbox" class="${checkboxClass}" style="display:inline-block;width:24px;height:24px"></label></span>
                <span><input type="radio" id="ring-radio" class="sr-only peer"><label for="ring-radio" class="${radioClass}" style="display:inline-block;width:24px;height:24px"></label></span>
            </div>`);
    }, { checkboxClass, radioClass });

    // The ring only draws for keyboard focus, so establish keyboard modality before focusing directly.
    await page.keyboard.press('Tab');

    const ringOf = (selector, dark) => page.evaluate(({ selector, dark }) => {
        document.documentElement.classList.toggle('dark', dark);
        const input = document.querySelector(selector);
        input.focus();
        const el = input.tagName === 'INPUT' ? input.nextElementSibling : input;
        const styles = getComputedStyle(el);

        return { style: styles.outlineStyle, color: styles.outlineColor };
    }, { selector, dark });

    const primary600 = 'rgb(45, 91, 227)';
    const primary400Dark = 'rgb(138, 168, 255)';

    for (const [name, selector] of [['plain control', '#ring-global'], ['checkbox icon', '#ring-checkbox'], ['radio icon', '#ring-radio']]) {
        const light = await ringOf(selector, false);
        assert.equal(light.style, 'solid', `${name} draws a ring in light mode`);
        assert.equal(light.color, primary600, `${name} ring is primary-600 in light mode`);

        const dark = await ringOf(selector, true);
        assert.equal(dark.style, 'solid', `${name} draws a ring in dark mode`);
        assert.equal(dark.color, primary400Dark, `${name} ring is primary-400 (3:1 on dark surfaces) in dark mode`);
    }
}));

vueTest('items that wrap a button use the button, and a pointer-disabled item does nothing', () => withPage(async (page) => {
    const toggle = toggleOf(page, 'buttons');

    await toggle.focus();
    await page.keyboard.press('Enter');
    await settle(page);

    assert.equal(await active(page), 'menuitem:Open');
    assert.equal(await page.locator('[data-case="buttons"] li').first().getAttribute('role'), 'none');

    await page.keyboard.press('ArrowDown');
    assert.equal(await active(page), 'menuitem:Busy');
    await page.keyboard.press('Enter');
    await settle(page);
    assert.deepEqual(await picked(page), [], 'a pointer-events-none item stays inert for the keyboard');
    assert.equal(await toggle.getAttribute('aria-expanded'), 'true');

    await page.keyboard.press('ArrowDown');
    assert.equal(await active(page), 'menuitem:Delete', 'a natively disabled button cannot take focus, so the arrows skip it');
    await page.keyboard.press('Enter');
    await settle(page);
    assert.deepEqual(await picked(page), ['delete']);
    assert.equal(await active(page), 'button:Card actions');
}));

vueTest('a teleported menu opens on its first item below the toggle', () => withPage(async (page) => {
    const toggle = toggleOf(page, 'teleport');

    await toggle.focus();
    await page.keyboard.press('Enter');
    await settle(page);

    assert.equal(await active(page), 'menuitem:T one');

    const [toggleBox, itemBox] = await Promise.all([toggle.boundingBox(), page.getByText('T one').boundingBox()]);
    assert.ok(itemBox.y >= toggleBox.y + toggleBox.height, 'the teleported panel is positioned below its toggle');

    await page.keyboard.press('ArrowDown');
    await page.keyboard.press('Enter');
    await settle(page);
    assert.deepEqual(await picked(page), ['t2']);
    assert.equal(await active(page), 'button:Teleported');
}));

vueTest('the pointer still opens without moving focus, and a menu slot holding inputs is left alone', () => withPage(async (page) => {
    const plain = toggleOf(page, 'plain');

    await plain.click();
    await settle(page);
    assert.equal(await plain.getAttribute('aria-expanded'), 'true');
    assert.equal(await page.locator('[data-case="plain"] ul').getAttribute('role'), 'menu', 'menu roles do not depend on how the menu was opened');
    assert.equal(await page.getByText('In this folder').getAttribute('role'), 'menuitem');
    assert.equal(await active(page), 'button:Generate', 'a pointer open does not pull focus into the menu');

    await page.getByText('In every folder').click();
    await settle(page);
    assert.deepEqual(await picked(page), ['every-folder']);
    assert.equal(await plain.getAttribute('aria-expanded'), 'false');

    const composite = toggleOf(page, 'composite');

    assert.equal(await composite.getAttribute('aria-haspopup'), null);
    await composite.focus();
    await page.keyboard.press('Enter');
    await settle(page);
    assert.equal(await composite.getAttribute('aria-expanded'), 'true');
    assert.equal(await active(page), 'button:Saved filters', 'a composite panel is not a menu, so focus stays put');
    assert.equal(await page.locator('[data-case="composite"] ul').getAttribute('role'), null);

    await page.locator('[data-search]').fill('ab');
    await page.keyboard.press('ArrowLeft');
    await page.keyboard.press(' ');
    assert.equal(await page.locator('[data-search]').inputValue(), 'a b', 'Space and the arrow keys still edit the search box');
}));

vueTest('a dropdown nested in another panel owns its menu and its keys', () => withPage(async (page) => {
    const outer = page.locator('[data-case="nested"] > div > button').first();
    const inner = toggleOf(page, 'inner');

    assert.equal(await outer.getAttribute('aria-haspopup'), null, "the outer dropdown does not claim the inner one's menu");

    await outer.click();
    await settle(page);
    await inner.focus();
    await page.keyboard.press('Enter');
    await settle(page);
    assert.equal(await active(page), 'menuitem:I one');

    await page.keyboard.press('ArrowDown');
    assert.equal(await active(page), 'menuitem:I two', 'one key press moves one item, not one per dropdown');

    await page.keyboard.press('Escape');
    await settle(page);
    assert.equal(await active(page), 'button:Inner');
    assert.equal(await inner.getAttribute('aria-expanded'), 'false');
    assert.equal(await outer.getAttribute('aria-expanded'), 'true', 'Escape closes only the inner menu');
}));

const massSubmenu = (page) => page.locator('[data-case="mass"] li ul');
const massParent = (page) => page.locator('[data-case="mass"] a', { hasText: 'Update' });

async function openMassMenuOnUpdate(page) {
    await toggleOf(page, 'mass').focus();
    await page.keyboard.press('Enter');
    await settle(page);
    await page.keyboard.press('ArrowDown');
    assert.equal(await active(page), 'menuitem:Update');
}

vueTest('a submenu parent opens onto its first option from the keyboard and closes back to its parent', () => withPage(async (page) => {
    const toggle = toggleOf(page, 'mass');

    await openMassMenuOnUpdate(page);

    assert.equal(await massParent(page).getAttribute('aria-haspopup'), 'menu');
    assert.equal(await massParent(page).getAttribute('aria-expanded'), 'false');
    assert.equal(await massSubmenu(page).isVisible(), false, 'the options stay hidden until the parent is opened');

    await page.keyboard.press('ArrowRight');
    await settle(page);
    assert.equal(await massParent(page).getAttribute('aria-expanded'), 'true');
    assert.equal(await massSubmenu(page).isVisible(), true);
    assert.equal(await massSubmenu(page).getAttribute('role'), 'menu');
    assert.equal(await massSubmenu(page).getAttribute('aria-label'), 'Update');
    assert.equal(await active(page), 'menuitem:Active');

    await page.keyboard.press('ArrowDown');
    assert.equal(await active(page), 'menuitem:Inactive');
    await page.keyboard.press('ArrowDown');
    assert.equal(await active(page), 'menuitem:Active', 'the arrows wrap inside the submenu');
    await page.keyboard.press('End');
    assert.equal(await active(page), 'menuitem:Inactive');

    await page.keyboard.press('ArrowLeft');
    await settle(page);
    assert.equal(await active(page), 'menuitem:Update', 'the closing arrow returns to the parent');
    assert.equal(await massParent(page).getAttribute('aria-expanded'), 'false');
    assert.equal(await massSubmenu(page).isVisible(), false);
    assert.equal(await toggle.getAttribute('aria-expanded'), 'true', 'closing a submenu leaves the menu open');

    // Enter opens it too; Escape closes only the submenu, a second Escape closes the menu.
    await page.keyboard.press('Enter');
    await settle(page);
    assert.equal(await active(page), 'menuitem:Active');
    assert.deepEqual(await picked(page), [], 'Enter on a parent opens it instead of activating it');

    await page.keyboard.press('Escape');
    await settle(page);
    assert.equal(await active(page), 'menuitem:Update');
    assert.equal(await massSubmenu(page).isVisible(), false);
    assert.equal(await toggle.getAttribute('aria-expanded'), 'true', 'Escape in a submenu does not close the whole menu');

    await page.keyboard.press('Escape');
    await settle(page);
    assert.equal(await toggle.getAttribute('aria-expanded'), 'false');
    assert.equal(await active(page), 'button:Select action');

    // Choose an option: it runs, and the menu closes onto the toggle.
    await page.keyboard.press('ArrowDown');
    await settle(page);
    await page.keyboard.press('ArrowDown');
    await page.keyboard.press('ArrowRight');
    await settle(page);
    await page.keyboard.press('ArrowDown');
    await page.keyboard.press('Enter');
    await settle(page);
    assert.deepEqual(await picked(page), ['mass:inactive']);
    assert.equal(await toggle.getAttribute('aria-expanded'), 'false');
    assert.equal(await massSubmenu(page).isVisible(), false);
    assert.equal(await active(page), 'button:Select action');
}));

vueTest('an open submenu closes when focus moves on, and a plain item ignores the side arrows', () => withPage(async (page) => {
    await openMassMenuOnUpdate(page);
    await page.keyboard.press('ArrowRight');
    await settle(page);
    await page.keyboard.press('ArrowLeft');
    await page.keyboard.press('ArrowRight');
    await settle(page);
    assert.equal(await massSubmenu(page).isVisible(), true);

    await page.keyboard.press('ArrowLeft');
    await page.keyboard.press('ArrowDown');
    await settle(page);
    assert.equal(await active(page), 'menuitem:Export');
    await page.keyboard.press('ArrowRight');
    await page.keyboard.press('ArrowLeft');
    assert.equal(await active(page), 'menuitem:Export', 'a plain item has no submenu to open or close');

    // Tab from inside a submenu leaves the whole menu, like Tab from any item.
    await page.keyboard.press('ArrowUp');
    await page.keyboard.press('ArrowRight');
    await settle(page);
    assert.equal(await active(page), 'menuitem:Active');
    await page.keyboard.press('Tab');
    await settle(page);
    assert.equal(await toggleOf(page, 'mass').getAttribute('aria-expanded'), 'false');
    assert.equal(await massSubmenu(page).isVisible(), false);
    assert.equal(await massParent(page).getAttribute('aria-expanded'), 'false');
    assert.equal(await active(page), 'button:After', 'focus lands on the toggle and carries on to the next control');
}));

vueTest('hovering a submenu parent still shows its options and the pointer still picks one', () => withPage(async (page) => {
    await toggleOf(page, 'mass').click();
    await settle(page);
    assert.equal(await massSubmenu(page).isVisible(), false);

    await massParent(page).hover();
    await settle(page);
    assert.equal(await massSubmenu(page).isVisible(), true, 'hover opens the submenu as before');
    assert.equal(await massParent(page).getAttribute('aria-expanded'), 'false', 'a hover does not claim the keyboard open state');

    await page.locator('[data-case="mass"] ul ul').getByText('Active', { exact: true }).click();
    await settle(page);
    assert.deepEqual(await picked(page), ['mass:active']);
    assert.equal(await toggleOf(page, 'mass').getAttribute('aria-expanded'), 'false');

    await toggleOf(page, 'mass').click();
    await settle(page);
    await page.mouse.move(5, 5);
    await settle(page);
    assert.equal(await massSubmenu(page).isVisible(), false, 'moving off the parent hides the submenu again');
}));

vueTest('in a right-to-left page the submenu opens leftward and the arrows swap sides', () => withPage(async (page) => {
    await page.evaluate(() => { document.documentElement.dir = 'rtl'; });
    await openMassMenuOnUpdate(page);

    await page.keyboard.press('ArrowRight');
    await settle(page);
    assert.equal(await active(page), 'menuitem:Update', 'ArrowRight points away from where an RTL submenu opens');
    assert.equal(await massSubmenu(page).isVisible(), false);

    await page.keyboard.press('ArrowLeft');
    await settle(page);
    assert.equal(await active(page), 'menuitem:Active');

    const [parentBox, subBox] = await Promise.all([massParent(page).boundingBox(), massSubmenu(page).boundingBox()]);
    assert.ok(subBox.x + subBox.width <= parentBox.x + 1, 'the RTL submenu is drawn to the left of its parent');

    await page.keyboard.press('ArrowRight');
    await settle(page);
    assert.equal(await active(page), 'menuitem:Update');
    assert.equal(await massSubmenu(page).isVisible(), false);
}));

vueTest('dismissing a submenu from the keyboard hides it even while the pointer rests on its parent', () => withPage(async (page) => {
    await toggleOf(page, 'mass').click();
    await settle(page);
    await massParent(page).hover();
    await settle(page);
    assert.equal(await massSubmenu(page).isVisible(), true, 'the hover shows the submenu');

    // Keyboard from here: the toggle still has focus after the click.
    await toggleOf(page, 'mass').focus();
    await page.keyboard.press('ArrowDown');
    await page.keyboard.press('ArrowDown');
    assert.equal(await active(page), 'menuitem:Update');

    for (const dismiss of ['Escape', 'ArrowLeft']) {
        await page.keyboard.press('ArrowRight');
        await settle(page);
        assert.equal(await active(page), 'menuitem:Active');
        await page.keyboard.press(dismiss);
        await settle(page);
        assert.equal(await active(page), 'menuitem:Update');
        assert.equal(await massSubmenu(page).isVisible(), false, `${dismiss} hides the submenu although the pointer is still over the parent`);
    }

    // Once the pointer leaves and comes back, hover works again.
    await page.mouse.move(700, 600);
    await settle(page);
    await massParent(page).hover();
    await settle(page);
    assert.equal(await massSubmenu(page).isVisible(), true);
}));
