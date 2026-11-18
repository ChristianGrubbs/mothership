'use strict';

// The details page's Files tab (asset/files/index.blade.php's real template and component script, mounted whole):
// "Open Files tab" from a preview names the stacked file on show in `?files&file=ID`, and the tab picks that row out
// and scrolls it into view
const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '../../..');
const view = path.join(root, 'packages/Mothership/DAM/src/Resources/views/asset/files/index.blade.php');
const playwrightPath = process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH;
const browserTest = playwrightPath ? test : test.skip;

/** Replaces every @js(...) / @json(...) call, found by balanced parentheses, with what `pick(expression)` returns. */
function replaceCalls(source, pick) {
    let output = '', offset = 0;
    for (;;) {
        const match = /@(js|json)\(/.exec(source.slice(offset));
        if (!match) return output + source.slice(offset);
        const start = offset + match.index;
        output += source.slice(offset, start);
        let depth = 1, quote = null, escape = false, end = start + match[0].length;
        for (; end < source.length && depth; end++) {
            const ch = source[end];
            if (escape) { escape = false; continue; }
            if (ch === '\\') { escape = true; continue; }
            if (quote) { if (ch === quote) quote = null; continue; }
            if (ch === "'" || ch === '"') { quote = ch; continue; }
            if (ch === '(') depth++;
            if (ch === ')') depth--;
        }
        output += pick(source.slice(start, end));
        offset = end;
    }
}

const files = [
    {id: 1, asset_id: 7, attachment_id: 11, label: 'Cover.png', file_name: 'Cover.png', role: 'primary', is_representative: true, size: 1024, sha256: 'a'.repeat(64), download_url: '/dl/1'},
    {id: 2, asset_id: 7, attachment_id: 12, label: 'Second.png', file_name: 'Second.png', role: 'alternate', is_representative: false, size: 2048, sha256: 'b'.repeat(64), download_url: '/dl/2'},
    {id: 3, asset_id: 7, attachment_id: 13, label: 'Third.png', file_name: 'Third.png', role: 'alternate', is_representative: false, size: 4096, sha256: 'c'.repeat(64), download_url: '/dl/3'},
];

/** The committed stylesheets the page ships, so a utility class means what it means in production. */
function builtStylesheets() {
    return ['public/themes/admin/default/build', 'public/themes/dam/build'].map((dir) => {
        const manifest = JSON.parse(fs.readFileSync(path.join(root, dir, 'manifest.json'), 'utf8'));
        const entry = Object.values(manifest).find((item) => typeof item.file === 'string' && item.file.startsWith('assets/app-') && item.file.endsWith('.css'));
        assert.ok(entry, `${dir}/manifest.json lists no assets/app-*.css`);

        return path.join(root, dir, entry.file);
    });
}

async function mountFiles({viewport = {width: 1280, height: 900}, search = '', rows = files} = {}, run) {
    const browser = await require(playwrightPath).chromium.launch({headless: true, channel: process.env.MOTHERSHIP_BROWSER_CHANNEL || 'chrome'});
    try {
        const source = fs.readFileSync(view, 'utf8');
        const body = /id="v-dam-asset-files-template">([\s\S]*?)<\/script>/.exec(source)[1]
            .replace(/\{\{--[\s\S]*?--\}\}/g, '')
            .replace(/@if \(bouncer\(\)->hasPermission\('([^']+)'\)\)([\s\S]*?)@endif/g, (_, permission, inner) => (permission === 'dam.asset.files.destroy' ? inner : ''))
            .replace(/@foreach[\s\S]*?@endforeach/g, '<option value="alternate">alternate</option>')
            .replace(/@lang\('([^']+)'\)/g, (_, key) => key.split('.').pop())
            .replace(/@\{\{/g, '{{').replace(/(?<!@)\{\{[\s\S]*?\}\}/g, 'x');
        const script = replaceCalls(source.split('<script type="module">')[1].split('</script>')[0].replace(/@lang\('([^']+)'\)/g, (_, key) => key.split('.').pop()), (expression) => {
            if (expression.includes('route(')) return '"/x/__ASSET__"';
            if (expression.includes('trans(')) return '{}';
            if (expression.includes('UNSTACKED_ROLES')) return '[]';
            return 'true';
        }).replace(/\{\{[\s\S]*?\}\}/g, '/x/:asset');

        const page = await browser.newPage({viewport});
        page.problems = [];
        page.on('pageerror', (error) => page.problems.push(error.message));
        page.on('console', (message) => { if (/was accessed during render but is not defined|Unhandled error/.test(message.text())) page.problems.push(message.text()); });
        await page.route('http://files.test/**', (route) => {
            const pathname = new URL(route.request().url()).pathname;
            if (pathname.startsWith('/missing')) return route.fulfill({status: 404, body: 'gone'});
            if (pathname.startsWith('/ok')) return route.fulfill({contentType: 'image/svg+xml', body: '<svg xmlns="http://www.w3.org/2000/svg" width="80" height="64"><rect width="80" height="64" fill="#ddd6fe"/></svg>'});
            return route.fulfill({contentType: 'text/html', body: '<div id="app"></div>'});
        });
        await page.goto(`http://files.test/${search}`);
        await page.addScriptTag({path: process.env.MOTHERSHIP_VUE_RUNTIME_PATH});
        for (const sheet of builtStylesheets()) await page.addStyleTag({path: sheet});
        await page.evaluate(({body, files}) => {
            window.definitions = {};
            window.app = {component: (name, definition) => { window.definitions[name] = definition; }};
            window.pendingFiles = files;
            window.bodyMarkup = body;
        }, {body, files: rows});
        await page.addScriptTag({content: script});
        await page.evaluate(() => { window.MothershipAdobe = {icon: () => 'data:image/svg+xml;base64,PHN2Zy8+', tile: () => 'data:image/svg+xml;base64,PHN2Zy8+'}; });
        await page.evaluate(() => {
            const definition = window.definitions['v-dam-asset-files'];
            const app = Vue.createApp({...definition, template: window.bodyMarkup, props: ['initialAssetId']}, {initialAssetId: 7});
            app.config.globalProperties.$emitter = {on() {}, off() {}, emit() {}};
            app.config.globalProperties.$axios = {get: async (url) => ({data: url.includes('capture') ? {group: null, members: [], profiles: [], can_manage: false} : {files: window.pendingFiles, stack: {basis: null, key: null}, can_edit: true}})};
            window.panel = app.mount('#app');
        });
        await page.locator('[data-dam-file-row]').first().waitFor({timeout: 5000}).catch((e) => { throw new Error(page.problems.join(' | ') + ' :: ' + e.message.slice(0, 80)); });
        await run(page);
        assert.deepEqual(page.problems, []);
    } finally {
        await browser.close();
    }
}

browserTest('?files&file=ID picks that stacked file out in the Files tab; without it nothing is picked', async () => {
    await mountFiles({search: '?files&file=2'}, async (page) => {
        assert.equal(await page.locator('[data-dam-file-picked]').count(), 1);
        assert.equal(await page.locator('[data-dam-file-row="2"]').getAttribute('data-dam-file-picked'), '');
        assert.equal(await page.locator('[data-dam-file-row="2"]').getAttribute('aria-current'), 'true');
        assert.equal((await page.locator('[data-dam-file-row="2"] [data-dam-file-picked-label]').textContent()).trim(), 'viewing');
    });
    await mountFiles({search: '?files'}, async (page) => {
        assert.equal(await page.locator('[data-dam-file-picked]').count(), 0);
    });
});

browserTest('at 375px every Files tab row action is on screen without scrolling the table sideways', async () => {
    await mountFiles({viewport: {width: 375, height: 800}}, async (page) => {
        const controls = '[data-dam-file-open], [data-dam-file-edit], [data-dam-file-promote], [data-dam-file-up], [data-dam-file-down], [data-dam-file-history-toggle]';
        for (const id of [1, 2, 3]) {
            const row = page.locator(`[data-dam-file-row="${id}"]`);
            for (const control of await row.locator(controls).all()) {
                const box = await control.boundingBox();
                assert.ok(box && box.x >= 0 && box.x + box.width <= 375, `row ${id}: ${await control.getAttribute('class')} sits at ${JSON.stringify(box)}`);
            }
        }
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= 375), true, 'the page scrolls sideways');
        assert.equal(await page.locator('[data-dam-files-table]').evaluate((table) => table.scrollWidth <= table.clientWidth), true, 'the table is wider than the screen');
    });
});

browserTest('Files: an Adobe thumbnail that 404s switches to the application tile and drops the overlay; a loaded one keeps both', async () => {
    const ai = {key: 'illustrator', app: 'Adobe Illustrator', label: 'Illustrator', badge: 'Ai', color: '#FF9A00', extension: 'ai', title: 'Adobe Illustrator file (.AI)', pdf: true};
    const rows = [
        {...files[0], thumbnail_url: '/ok/1.svg', adobe: ai},
        {...files[1], thumbnail_url: '/missing/2.svg', adobe: ai},
        {...files[2], thumbnail_url: null, adobe: ai},
    ];
    await mountFiles({rows}, async (page) => {
        const row = (id) => page.locator(`[data-dam-file-row="${id}"]`);
        await row(2).locator('[data-dam-file-adobe-tile]').waitFor({timeout: 5000});
        const parts = async (id) => row(id).evaluate((el) => ({
            artwork: el.querySelector('[data-dam-file-thumbnail]') !== null,
            tile: el.querySelector('[data-dam-file-adobe-tile]') !== null,
            mark: el.querySelector('[data-dam-file-adobe-mark]') !== null,
        }));
        assert.deepEqual(await parts(1), {artwork: true, tile: false, mark: true});
        assert.deepEqual(await parts(2), {artwork: false, tile: true, mark: false});
        assert.deepEqual(await parts(3), {artwork: false, tile: true, mark: false});
    });
});
