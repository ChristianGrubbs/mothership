'use strict';

// The preview's Info pane (the real info-panel markup and the real infoBuiltinFields) while a stacked file is on
// show: the file's own name, type, size and pixel size replace the cover's, and what belongs to the whole stack
// (title, dates, tags, properties) says so. On the cover nothing changes.
const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '../../..');
const views = path.join(root, 'packages/Mothership/DAM/src/Resources/views');
const playwrightPath = process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH;
const browserTest = playwrightPath ? test : test.skip;
const read = (file) => fs.readFileSync(path.join(views, file), 'utf8');

const WORDS = {
    'dam::app.admin.dam.asset.stack-fan.info-stack-label': ':label (stack)',
    'dam::app.admin.dam.asset.stack-fan.info-describes-cover': 'File details are for the file shown (cover: :name).',
    'dam::app.admin.dam.asset.edit.file-name': 'File name',
    'dam::app.admin.dam.asset.edit.preview-modal.asset-title': 'Title',
    'dam::app.admin.dam.asset.edit.preview-modal.mime': 'MIME',
    'dam::app.admin.dam.asset.edit.size': 'Size',
    'dam::app.admin.dam.asset.edit.dimensions': 'Dimensions',
    'dam::app.admin.dam.asset.edit.created-at': 'Created',
    'dam::app.admin.dam.asset.edit.preview-modal.duration': 'Duration',
    'dam::app.admin.dam.asset.edit.preview-modal.codec': 'Codec',
    'dam::app.admin.dam.asset.edit.aspect-ratio': 'Aspect ratio',
    'dam::app.admin.dam.asset.edit.tags': 'Tags',
    'dam::app.admin.dam.asset.edit.preview-modal.properties': 'Properties',
};
const word = (key) => WORDS[key] || key.split('.').pop();

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
const asString = (expression) => {
    const key = /trans\('([^']+)'\)/.exec(expression);
    return key ? JSON.stringify(word(key[1])) : '"x"';
};

const cover = {id: 7, variantFileId: undefined, file_name: 'Cover.jpg', display_name: 'Cover.jpg', filesUrl: ''};
const info = {
    file_name: 'Cover.jpg', title: 'Spring banner', file_type: 'image', asset_kind: 'photo', mime_type: 'image/jpeg', extension: 'jpg', file_size_human: '9.00 MB',
    aspect_ratio: {display: '3:2'}, dimensions: {display: '6000 × 4000 px'}, duration: null, codec: null, created_at: '01 Sep 2026', updated_at: null,
    uploaded_by: 'Dana', created_by: null, legacy_creation_date: null, original: {sha256: 'c0ffee'}, tags: [{id: 1, name: 'spring'}],
    properties: [{name: 'Camera', value: 'EOS R5'}], directory_breadcrumb: [], fields: [], ai: null, detail_fields: null,
};
const stackedFile = {id: 3, asset_id: 7, attachment_id: 13, label: 'Product-Shot.png', role: 'alternate', is_representative: false, mime_type: 'image/png', extension: 'png', url: '/f/3.png', size: 2048, width: 800, height: 600, sha256: 'beef'};

async function mountInfo(run) {
    const playwright = require(playwrightPath);
    const launcher = process.env.MOTHERSHIP_CHROME_LAUNCHER_PATH || path.join(require('node:os').homedir(), 'ai-stack/tools/chrome-launch.mjs');
    const browser = fs.existsSync(launcher)
        ? await (await import(require('node:url').pathToFileURL(launcher).href)).launchChrome(playwright)
        : await playwright.chromium.launch({headless: true, channel: process.env.MOTHERSHIP_BROWSER_CHANNEL || 'chrome'});
    try {
        const page = await browser.newPage();
        page.problems = [];
        page.on('pageerror', (error) => page.problems.push(error.message));
        page.on('console', (message) => { if (/was accessed during render but is not defined|Unhandled error/.test(message.text())) page.problems.push(message.text()); });
        await page.route('http://info.test/**', (route) => route.fulfill({contentType: 'text/html', body: '<div id="app"></div>'}));
        await page.goto('http://info.test/');
        await page.addScriptTag({path: process.env.MOTHERSHIP_VUE_RUNTIME_PATH});

        const stripSource = read('asset/preview-modal/attachments/strip-script.blade.php');
        const infoScript = replaceCalls(read('asset/preview-modal/info-panel-script.blade.php').replace(/\{\{--[\s\S]*?--\}\}/g, '').replace(/@include\([^\n]*\)/g, '').replace(/<\/?script>/g, ''), asString);
        const panel = replaceCalls(read('asset/preview-modal/info-panel.blade.php'), asString)
            .replace(/\{\{--[\s\S]*?--\}\}/g, '')
            .replace(/(?<!@)\{\{\s*trans\('([^']+)'\)\s*\}\}/g, (_, key) => word(key))
            .replace(/(?<!@)\{\{[\s\S]*?\}\}/g, 'x').replace(/@\{\{/g, '{{');
        await page.evaluate(() => { window.MothershipNameTail = (name) => ({head: name, tail: ''}); });
        await page.addScriptTag({content: stripSource.match(/<script>([\s\S]*?)<\/script>/)[1].replace("@json(trans('dam::app.admin.dam.asset.files.roles'))", '{}')});
        await page.addScriptTag({content: infoScript});
        assert.deepEqual(page.problems, []);
        await page.evaluate(({panel, cover, info, stackedFile}) => {
            const definition = window._damInfoPanel;
            const app = Vue.createApp({
                template: `<div>${panel}</div>`,
                data() { return {...definition.data, isInfoPanelOpen: true, infoData: info, infoAssetId: 7, previewData: {...cover}, previewVariants: [stackedFile]}; },
                computed: definition.computed,
                methods: {...definition.methods, toggleInfoPanel() {}, onInfoFieldsSaved() {}, reviewAiTag() {}},
            });
            app.config.globalProperties.$emitter = {on() {}, off() {}, emit() {}};
            window.vm = app.mount('#app');
            window.showFile = (file) => { window.vm.previewData = file ? {...cover, ...window._damStackSwitch.methods.variantPreviewData.call(window.vm, file)} : {...cover}; };
        }, {panel, cover, info, stackedFile});
        await run(page);
        assert.deepEqual(page.problems, []);
    } finally {
        await browser.close();
    }
}

const rows = (page) => page.locator('[data-dam-info-panel] dl.divide-y > div').evaluateAll((nodes) => Object.fromEntries(nodes.map((node) => [node.querySelector('dt').textContent.trim(), node.querySelector('dd').textContent.trim()])));

browserTest('a stacked file on show lists its own name, type, size and pixel size, with the stack\'s fields marked as the stack\'s', async () => {
    await mountInfo(async (page) => {
        const coverRows = await rows(page);
        assert.equal(coverRows['File name'], 'Cover.jpg');
        assert.equal(coverRows['Dimensions'], '6000 × 4000 px');
        assert.equal(coverRows['Title'], 'Spring banner');
        assert.equal(await page.locator('[data-dam-info-variant-note]').count(), 0);

        await page.evaluate((file) => window.showFile(file), stackedFile);
        const shown = await rows(page);
        assert.equal(shown['File name'], 'Product-Shot.png');
        assert.equal(shown['MIME'], 'image/png');
        assert.equal(shown['Size'], '2.0 KB');
        assert.equal(shown['Dimensions'], '800 × 600 px');
        assert.equal(shown['Aspect ratio'], undefined, 'the cover\'s aspect ratio does not describe this file');
        assert.equal(shown['Title'], undefined, 'an unmarked Title would read as the file\'s');
        assert.equal(shown['Title (stack)'], 'Spring banner');
        assert.equal(shown['Created (stack)'], '01 Sep 2026');
        assert.equal(Object.values(shown).includes('Cover.jpg'), false, 'the cover\'s name still shows');
        assert.equal(Object.values(shown).includes('6000 × 4000 px'), false, 'the cover\'s size still shows');
        assert.match(await page.locator('[data-dam-info-panel]').textContent(), /Tags \(stack\)/);
        assert.match(await page.locator('[data-dam-info-panel]').textContent(), /Properties \(stack\)/);
        assert.equal(await page.locator('[data-dam-info-variant-note]').count(), 1);

        await page.evaluate(() => window.showFile(null));
        assert.equal((await rows(page))['File name'], 'Cover.jpg');
        assert.doesNotMatch(await page.locator('[data-dam-info-panel]').textContent(), /\(stack\)/);
    });
});

test('embedded metadata keeps the last selected member when earlier responses arrive late', async () => {
    const vm = require('node:vm');
    let definition;
    const location = {href: 'http://info.test/assets/7?meta-data&file=3'};
    const source = read('asset/meta-data/index.blade.php').match(/<script type="module">([\s\S]*?)<\/script>/)[1]
        .replace(/\{\{[\s\S]*?\}\}/g, '/metadata/__ID__');
    vm.runInNewContext(source, {app: {component(name, value) { definition = value; }}, window: {location}, URL, encodeURIComponent});
    const pending = [];
    const instance = {...definition.data(), ...definition.methods, assetIds: 7,
        $axios: {get(url) { return new Promise((resolve, reject) => pending.push({url, resolve, reject})); }},
        $emitter: {on() {}, off() {}},
    };
    definition.mounted.call(instance);
    location.href = 'http://info.test/assets/7?meta-data&file=4';
    instance.onMemberChanged({assetId: 7});
    location.href = 'http://info.test/assets/7?meta-data&file=3';
    instance.onMemberChanged({assetId: 7});
    assert.deepEqual(pending.map(item => item.url), ['/metadata/7?file=3', '/metadata/7?file=4', '/metadata/7?file=3']);
    const settle = () => new Promise(resolve => setImmediate(resolve));
    pending[2].resolve({data: {success: true, data: {FileName: 'last.jpg', FileType: 'JPG'}, file: {name: 'last.jpg'}}});
    await settle();
    pending[1].resolve({data: {success: true, data: {FileName: 'cover.dng'}, file: {name: 'cover.dng'}}});
    pending[0].reject(new Error('old request failed'));
    await settle();
    assert.equal(instance.metadata.FileName, 'last.jpg');
    assert.equal(instance.selectedName, 'last.jpg');
    assert.equal(instance.loading, false);
    assert.equal(instance.error, null);

    location.href = 'http://info.test/assets/7?meta-data&file=9';
    instance.fetchMetadataById();
    assert.equal(Object.keys(instance.metadata).length, 0);
    pending[3].resolve({data: {success: false, message: 'Selected file unavailable'}});
    await settle();
    assert.equal(instance.error, 'Selected file unavailable');
    assert.equal(Object.keys(instance.metadata).length, 0);
    instance.fetchMetadataById();
    definition.beforeUnmount.call(instance);
    pending[4].resolve({data: {success: true, data: {FileName: 'late.jpg'}}});
    await settle();
    assert.equal(Object.keys(instance.metadata).length, 0);
});
