// The explorer's right-click menu (components/explorer/context-menu.blade.php) draws each item's icon with an
// icon-font <i>. The font's ::before glyph is a Private Use Area character, and without aria-hidden="true" it
// became the start of the button's accessible name ("U+E90B Preview"), so a role query for the exact name
// "Preview" found nothing. This mounts the real menu template and component against the committed built
// stylesheets (the glyphs come from them) and pins that every item's accessible name is exactly its visible label.
//
// The menu is a role=menu of role=menuitem buttons: opening it moves focus to the first item, Arrow keys, Home and
// End rove through the items, Escape and Tab close it and hand focus back to the control that opened it.
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '../../..');
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');
const menuFile = 'packages/Mothership/DAM/src/Resources/views/components/explorer/context-menu.blade.php';
const langFile = 'packages/Mothership/DAM/src/Resources/lang/en_US/app.php';

/** Flattens the en_US PHP translation file into dotted keys with a small nesting-aware scan. */
function translations() {
    const flat = {};
    const stack = [];
    for (const line of read(langFile).split('\n')) {
        const open = line.match(/^\s*'([^']+)'\s*=>\s*\[\s*$/);
        if (open) { stack.push(open[1]); continue; }
        if (/^\s*\],?\s*$/.test(line)) { stack.pop(); continue; }
        const leaf = line.match(/^\s*'([^']+)'\s*=>\s*'((?:[^'\\]|\\.)*)',?\s*$/);
        if (leaf) flat[[...stack, leaf[1]].join('.')] = leaf[2].replace(/\\'/g, "'");
    }
    return flat;
}

/**
 * Renders the Blade source as a user with every permission would receive it. Every @if in this file is a flat,
 * un-nested permission/config gate and there is no @else, so granting all gates means dropping those directives.
 */
function blade(text, lang, strict) {
    return text
        .replace(/\{\{--[\s\S]*?--\}\}/g, '')
        .replace(/^\s*@(?:if|endif)\b.*$/gm, '')
        .replace(/@lang\('dam::app\.([^']+)'\)/g, (_, key) => {
            if (strict) assert.ok(key in lang, `missing en_US translation for ${key}`);
            return lang[key] ?? key;
        })
        .replace(/@js\(trans\('dam::app\.([^']+)'\)\)/g, (_, key) => "'" + lang[key].replace(/'/g, "\\'") + "'")
        .replace(/\{\{\s*route\([^}]*\)\s*\}\}/g, '/route');
}

function menu() {
    const source = read(menuFile);
    const lang = translations();
    const template = blade(source.match(/<script type="text\/x-template" id="v-dam-explorer-ctx-template">([\s\S]*?)<\/script>/)[1], lang, true);
    const script = blade(source.match(/<script type="module">([\s\S]*?)<\/script>/)[1], lang, false);
    assert.match(script, /app\.component\('v-dam-explorer-ctx'/);
    return { template, script, lang };
}

function builtStylesheet(theme) {
    const manifest = JSON.parse(read(`public/themes/${theme}/build/manifest.json`));
    const entry = Object.values(manifest).find((item) => typeof item.file === 'string' && item.file.endsWith('.css') && item.file.startsWith('assets/app-'));
    assert.ok(entry, `the committed ${theme} manifest must list a built assets/app-*.css entry`);
    return path.join(root, `public/themes/${theme}/build`, entry.file);
}

const adminCss = builtStylesheet('admin/default');
const damCss = builtStylesheet('dam');

const THUMBNAILS = 'Generate missing 3D thumbnails'; // literal text in the template, not a translation
const A = 'admin.dam.index.directory.actions.';
const directoryLabels = (lang) => [
    lang['admin.explorer.context.open'], lang['admin.explorer.context.open-new-tab'],
    lang[A + 'upload-files'], lang['admin.explorer.context.folder-upload'], lang['admin.templates.use-page.title'],
    lang[A + 'add-directory'], lang[A + 'rename'], lang[A + 'copy-directory-structured'], THUMBNAILS,
    lang[A + 'download-zip'], lang[A + 'share'], lang['admin.upload-requests.request-uploads'],
    lang['admin.explorer.context.copy'], lang[A + 'delete'],
];
const spaceLabels = (lang) => [
    lang[A + 'upload-files'], lang['admin.explorer.context.folder-upload'], lang['admin.templates.use-page.title'],
    lang[A + 'add-directory'], THUMBNAILS, lang['admin.explorer.context.paste'],
];

/** Asserts the complete ordered list of button names, each matched by exactly one exact role query. */
async function assertExactNames(page, labels) {
    for (const label of labels) {
        assert.equal(await page.getByRole('menuitem', { name: label, exact: true }).count(), 1, `role=menuitem "${label}"`);
    }
    const names = (await buttonNames(page)).filter((name) => name !== 'Before');
    assert.deepEqual(names, labels);
    for (const name of names) assert.doesNotMatch(name, PRIVATE_USE, `"${name}" must not carry an icon-font glyph`);
}

const assetLabels = (lang) => [
    lang['admin.dam.asset.edit.preview-modal.card.preview'],
    lang['admin.dam.index.directory.actions.edit'],
    lang['admin.dam.index.directory.actions.rename'],
    lang['admin.explorer.context.copy'],
    lang['admin.dam.asset.edit.preview-modal.find-similar'],
    lang['admin.dam.index.directory.actions.download'],
    lang['admin.dam.index.directory.actions.share-file'],
    lang['admin.organize.actions.add-to-board-dots'],
    lang['admin.organize.actions.labels-dots'],
    lang['admin.organize.actions.pin-dots'],
    lang['admin.dam.index.directory.actions.delete'],
];

const browserTest = process.env.MOTHERSHIP_VUE_RUNTIME_PATH && process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH ? test : test.skip;
const launch = () => require(process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH).chromium.launch({ headless: true, channel: process.env.MOTHERSHIP_BROWSER_CHANNEL || 'chrome' });

/** Mounts the real menu for an item; every emitter call and every `close` event is reported to Node. */
async function mount(browser, { itemType, item, width, clipboard = null }) {
    const { template, script } = menu();
    const page = await browser.newPage({ viewport: { width, height: 1000 } });
    const events = [];
    await page.exposeFunction('reportEvent', (name, payload) => events.push([name, payload]));
    // The built stylesheets and the icon fonts they reference are served from the committed build, so the
    // ::before glyphs exist exactly as on the real page.
    await page.route('http://menu.test/themes/**', (route) => {
        const [, theme, file] = new URL(route.request().url()).pathname.match(/^\/themes\/(admin\/default|dam)\/(.+)$/);
        const asset = path.join(root, 'public/themes', theme, 'build/assets', file);
        return route.fulfill({ path: asset, contentType: asset.endsWith('.css') ? 'text/css; charset=utf-8' : undefined });
    });
    await page.route('http://menu.test/explorer', (route) => route.fulfill({
        contentType: 'text/html',
        body: '<!doctype html><html><head><meta charset="utf-8"><link rel="stylesheet" href="/themes/admin/default/' + path.basename(adminCss) + '"><link rel="stylesheet" href="/themes/dam/' + path.basename(damCss) + '"></head><body><div id="app"><button id="before" type="button">Before</button><v-dam-explorer-ctx :x="200" :y="100" :item="item" item-type="' + itemType + '" tab-id="tab-1" :clipboard="clipboard" :selection="null" @close="report(\'close\')"></v-dam-explorer-ctx></div></body></html>',
    }));
    await page.goto('http://menu.test/explorer');
    await page.evaluate(() => Promise.all([document.fonts.load('1em Mothership-DAM'), document.fonts.load('1em mothership-admin')]));
    assert.equal(await page.evaluate(() => document.fonts.check('1em Mothership-DAM') && document.fonts.check('1em mothership-admin')), true, 'icon fonts must be loaded');
    await page.addScriptTag({ path: process.env.MOTHERSHIP_VUE_RUNTIME_PATH });
    await page.evaluate(({ template, script, item, clipboard }) => {
        const el = document.createElement('script');
        el.type = 'text/x-template'; el.id = 'v-dam-explorer-ctx-template'; el.textContent = template; document.body.append(el);
        const app = Vue.createApp({ data: () => ({ item, clipboard }), methods: { report: (name, payload) => window.reportEvent(name, payload ?? null) } });
        app.config.globalProperties.$emitter = { on() {}, off() {}, emit: (name, payload) => window.reportEvent(name, payload ?? null) };
        app.config.globalProperties.$navigate = () => {};
        app.config.globalProperties.$axios = {};
        new Function('app', script)(app);
        app.mount('#app');
    }, { template, script, item, clipboard });
    await page.locator('#app button').nth(1).waitFor();
    await page.waitForFunction(() => getComputedStyle(document.querySelector('#app button:nth-of-type(2)').parentElement).visibility === 'visible');
    return { page, events };
}

const asset = { id: 41, file_name: 'poster.png', display_name: 'poster.png' };
const directory = { id: 5, name: 'Campaigns', can_access: true };

/** Every button's computed accessible name, read from Chromium's accessibility tree. */
async function buttonNames(page) {
    const cdp = await page.context().newCDPSession(page);
    const { nodes } = await cdp.send('Accessibility.getFullAXTree');
    return nodes.filter((node) => node.role?.value === 'menuitem' && !node.ignored).map((node) => node.name?.value);
}

const PRIVATE_USE = /[-]/;

for (const width of [375, 1440]) browserTest(`every asset context-menu item has exactly its visible label as its accessible name (${width}px wide)`, async () => {
    const browser = await launch();
    try {
        const { page } = await mount(browser, { itemType: 'asset', item: asset, width });
        const labels = assetLabels(menu().lang);
        for (const label of labels) {
            assert.equal(await page.getByRole('menuitem', { name: label, exact: true }).count(), 1, `role=menuitem "${label}"`);
        }
        const names = (await buttonNames(page)).filter((name) => name !== 'Before');
        assert.deepEqual(names, labels);
        for (const name of names) assert.doesNotMatch(name, PRIVATE_USE, `"${name}" must not carry an icon-font glyph`);
        await page.close();
    } finally { await browser.close(); }
});

for (const width of [375, 1440]) browserTest(`every directory context-menu item has exactly its visible label as its accessible name (${width}px wide)`, async () => {
    const browser = await launch();
    try {
        const { page } = await mount(browser, { itemType: 'directory', item: directory, width });
        await assertExactNames(page, directoryLabels(menu().lang));
        await page.close();
    } finally { await browser.close(); }
});

for (const width of [375, 1440]) browserTest(`every space context-menu item, including Paste with a populated clipboard, has exactly its visible label (${width}px wide)`, async () => {
    const browser = await launch();
    try {
        const { page } = await mount(browser, { itemType: 'space', item: directory, width, clipboard: { type: 'asset', id: 7 } });
        await assertExactNames(page, spaceLabels(menu().lang));
        await page.close();
    } finally { await browser.close(); }
});

// Source contract for the menus the browser fixtures cannot mount (the folder tree) and for every branch: each
// decorative icon inside a menu item is hidden from the accessibility tree and sits beside visible label text.
const treeFile = 'packages/Mothership/DAM/src/Resources/views/components/tree/damdirectories.blade.php';
function menuItems(text) {
    return [...text.matchAll(/<button\b[^>]*>([\s\S]*?)<\/button>/g)]
        .map((match) => match[1])
        .filter((inner) => /<i\s[^>]*class="icon-|<svg\b/.test(inner));
}
for (const [file, from, to, minimum] of [
    [menuFile, '<script type="text/x-template" id="v-dam-explorer-ctx-template">', '</script>', 30],
    [treeFile, 'class="dam-tree-context-menu', '</teleport>', 15],
]) {
    test(`${path.basename(file)}: every icon in a menu item is aria-hidden and has a visible label (${file.includes('tree') ? 'tree' : 'explorer'} menu)`, () => {
        const text = read(file);
        const start = text.indexOf(from);
        assert.notEqual(start, -1);
        const items = menuItems(text.slice(start, text.indexOf(to, start + from.length)));
        let icons = 0;
        for (const inner of items) {
            const glyphs = inner.match(/<i\s[^>]*class="icon-[^>]*>|<svg\b[^>]*>/g);
            for (const glyph of glyphs) {
                icons += 1;
                assert.match(glyph, /\saria-hidden="true"/, `icon is exposed to assistive tech: ${glyph.slice(0, 90)}`);
            }
            const label = inner.replace(/<svg[\s\S]*?<\/svg>/g, '').replace(/<i\s[^>]*><\/i>/g, '').replace(/<[^>]+>/g, '').replace(/\{\{--[\s\S]*?--\}\}/g, '').trim();
            assert.notEqual(label, '', 'a menu item with an icon must carry visible label text');
        }
        assert.ok(icons >= minimum, `${file}: expected at least ${minimum} icons, found ${icons}`);
        console.log(`# ${path.basename(file)}: ${items.length} icon items, ${icons} icons`);
    });
}

for (const width of [375, 1440]) browserTest(`the menu keyboard behaviour: focus enters on the first item, arrows rove, Escape returns focus to the opener (${width}px wide)`, async () => {
    const browser = await launch();
    try {
        const { page, events } = await mount(browser, { itemType: 'asset', item: asset, width });
        const lang = menu().lang;
        const focusedText = () => page.evaluate(() => document.activeElement.textContent.trim());
        const first = lang['admin.dam.asset.edit.preview-modal.card.preview'];
        const second = lang['admin.dam.index.directory.actions.edit'];

        // Opening the menu puts focus on its first item.
        await page.waitForFunction(() => document.activeElement?.getAttribute('role') === 'menuitem');
        assert.equal(await focusedText(), first);
        assert.equal(await page.locator('[role=menu]').count(), 1);

        await page.keyboard.press('ArrowDown');
        assert.equal(await focusedText(), second);
        await page.keyboard.press('ArrowUp');
        assert.equal(await focusedText(), first);
        await page.keyboard.press('ArrowUp');
        assert.equal(await focusedText(), lang['admin.dam.index.directory.actions.delete'], 'ArrowUp wraps to the last item');
        await page.keyboard.press('Home');
        assert.equal(await focusedText(), first);
        assert.deepEqual(events, []);

        // Enter activates the focused item: it opens the preview, then asks the host to close the menu.
        await page.keyboard.press('Enter');
        await page.evaluate(() => null); // one round trip lets the exposed-function calls land in Node
        assert.deepEqual(events, [['dam-open-preview', 41], ['close', null]]);

        // Space activates the focused item the same way (here Rename).
        events.length = 0;
        await page.getByRole('menuitem', { name: lang['admin.dam.index.directory.actions.rename'], exact: true }).focus();
        await page.keyboard.press('Space');
        await page.evaluate(() => null);
        assert.deepEqual(events, [['dam:explorer-rename-asset:tab-1', { asset }], ['close', null]]);

        // Escape hands focus back to the control that was focused when the menu opened, and asks the host to close it.
        events.length = 0;
        await page.keyboard.press('Escape');
        await page.evaluate(() => null);
        assert.deepEqual(events, [['close', null]]);
        await page.close();
    } finally { await browser.close(); }
});
