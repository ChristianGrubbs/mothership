'use strict';

// The details page's Details card (the real markup and the real v-edit-asset script) while the Preview tab shows a
// stacked file: its name, type, size and pixel size follow the file, the rename pencil for the cover's name steps
// aside, and what belongs to the whole stack (title, dates, tags heading) says so. On the cover nothing changes.
const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const os = require('node:os');
const {pathToFileURL} = require('node:url');

const root = path.resolve(__dirname, '../../..');
const edit = fs.readFileSync(path.join(root, 'packages/Mothership/DAM/src/Resources/views/asset/edit.blade.php'), 'utf8');
const playwrightPath = process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH;
const browserTest = playwrightPath ? test : test.skip;
const detailsNav = require(path.join(root, 'packages/Mothership/DAM/src/Resources/assets/js/details-navigation.js'));

async function launchTestBrowser() {
    const {chromium} = require(playwrightPath);
    const helper = process.env.MOTHERSHIP_CHROME_LAUNCHER_PATH || path.join(os.homedir(), 'ai-stack/tools/chrome-launch.mjs');
    if (process.env.MOTHERSHIP_CHROME_LAUNCHER_PATH || process.platform === 'darwin' || fs.existsSync(helper)) {
        const {launchChrome} = await import(pathToFileURL(helper).href);
        return launchChrome({chromium});
    }
    return chromium.launch({headless: true, channel: process.env.MOTHERSHIP_BROWSER_CHANNEL || 'chrome'});
}

function memberMethods(window, document = {}) {
    const source = fs.readFileSync(path.join(root, 'packages/Mothership/DAM/src/Resources/views/asset/preview-modal/attachments/strip-script.blade.php'), 'utf8');
    const script = replaceCalls(source.match(/<script>([\s\S]*?)<\/script>/)[1], () => '({})');
    vm.runInNewContext(script, {window, document, URLSearchParams});
    return window._damStackSwitch.methods;
}

test('changing the inline member replaces the URL and every tab without adding history entries', () => {
    const tab = {href: 'https://example.test/edit/1370?meta-data&file=8&nav=1&directory_id=all'};
    const writes = [];
    const window = {MothershipDetailsNavigation: detailsNav, location: {href: 'https://example.test/edit/1370?nav=1&directory_id=all#preview'}, history: {state: {assetId: 1370}, replaceState: (...args) => writes.push(args)}};
    const methods = memberMethods(window, {querySelectorAll: () => [tab]});
    methods.replaceMemberContext.call({previewData: {variantFileId: 1691}});
    assert.equal(writes.length, 1);
    const url = new URL(writes[0][2]);
    assert.equal(url.searchParams.get('file'), '1691');
    assert.equal(url.searchParams.get('nav'), '1');
    assert.equal(url.hash, '#preview');
    assert.equal(new URL(tab.href).searchParams.get('file'), '1691');
    assert.equal(new URL(tab.href).searchParams.has('meta-data'), true);
    methods.replaceMemberContext.call({previewData: {}});
    assert.equal(new URL(writes[1][2]).searchParams.has('file'), false);
});

test('Files links carry member and Library context in both inline and Library viewers', () => {
    const window = {MothershipDetailsNavigation: detailsNav, location: {search: '?file=1691&nav=1&directory_id=all'}};
    const methods = memberMethods(window);
    for (const stackInline of [true, false]) {
        const href = methods.filesTabHref.call({stackInline, previewDirectoryId: 'all', previewData: {filesUrl: '/edit/1370?files', variantFileId: 1691}});
        const url = new URL(href, 'https://example.test');
        assert.equal(url.searchParams.get('file'), '1691');
        assert.equal(url.searchParams.get('directory_id'), 'all');
        assert.equal(url.searchParams.get('nav'), '1');
        assert.equal(url.searchParams.has('files'), true);
    }
});

test('reload restores only the requested current member and reports stale, foreign and refused selections explicitly', async () => {
    const source = fs.readFileSync(path.join(root, 'packages/Mothership/DAM/src/Resources/views/asset/preview-modal.blade.php'), 'utf8');
    const from = source.indexOf('async restoreRequestedMember()');
    const to = source.indexOf('openPreview()', from);
    const method = vm.runInNewContext('({' + replaceCalls(source.slice(from, to), () => "'/files/__ASSET__'") + '})', {window: {location: {search: '?file=1691'}}, URLSearchParams}).restoreRequestedMember;
    for (const [files, refused, expected] of [
        [[{id: 1691, asset_id: 1370, attachment_id: 7}], false, 1691],
        [[{id: 1690, asset_id: 1370, attachment_id: 7}], false, null],
        [[{id: 1691, asset_id: 99, attachment_id: 7}], false, null],
        [[], true, null],
    ]) {
        let selected = null;
        const state = {previewData: {id: 1370}, $axios: {get: async () => { if (refused) throw new Error('403'); return {data: {files}}; }}, switchStackedFile: (file) => { selected = file.id; return true; }};
        await method.call(state);
        assert.equal(selected, expected);
        assert.equal(state.memberUnavailable, expected === null);
        assert.equal(state.memberPending, false);
    }
    assert.match(source, /v-else-if="memberUnavailable"[^>]+data-dam-member-unavailable/);
});

test('reload reports real switch guard failures, while an already shown representative is successful', async () => {
    const window = {MothershipDetailsNavigation: detailsNav, location: {search: '?file=1691'}};
    const methods = memberMethods(window);
    const file = {id: 1691, asset_id: 1370, attachment_id: 7, is_representative: true};
    const state = {stackInline: true, previewData: {}, _coverPreviewData: {}, replaceMemberContext() {}};
    assert.equal(methods.switchStackedFile.call({...state, isLoading: true}, file), false);
    assert.equal(methods.switchStackedFile.call({...state, _coverPreviewData: null}, file), false);
    assert.equal(methods.switchStackedFile.call(state, file), true);
    const source = fs.readFileSync(path.join(root, 'packages/Mothership/DAM/src/Resources/views/asset/preview-modal.blade.php'), 'utf8');
    const from = source.indexOf('async restoreRequestedMember()');
    const to = source.indexOf('openPreview()', from);
    const restore = vm.runInNewContext('({' + replaceCalls(source.slice(from, to), () => "'/files/__ASSET__'") + '})', {window, URLSearchParams}).restoreRequestedMember;
    for (const [overrides, unavailable] of [[{isLoading: true}, true], [{_coverPreviewData: null}, true], [{}, false]]) {
        const preview = {...methods, ...state, previewData: {id: 1370}, ...overrides, $axios: {get: async () => ({data: {files: [file]}})}};
        await restore.call(preview);
        assert.equal(preview.memberUnavailable, unavailable);
        assert.equal(preview.memberPending, false);
    }
});

browserTest('mounted member reload keeps image/video refs and fit alive until the real switch shows the selected file', async () => {
    const source = fs.readFileSync(path.join(root, 'packages/Mothership/DAM/src/Resources/views/asset/preview-modal.blade.php'), 'utf8');
    const stackSource = fs.readFileSync(path.join(root, 'packages/Mothership/DAM/src/Resources/views/asset/preview-modal/attachments/strip-script.blade.php'), 'utf8');
    const fitSource = fs.readFileSync(path.join(root, 'packages/Mothership/DAM/src/Resources/views/asset/preview-modal/frame-fit-script.blade.php'), 'utf8');
    const envelope = source.match(/id="v-asset-preview-modal-template"\s*>\s*([\s\S]*?)<\/script>/)[1];
    // The production component and envelope run unchanged; media bytes and the card are controlled fixtures.
    const template = envelope.replace(/\{\{--[\s\S]*?--\}\}/g, '').replace(/@include\('([^']+)'[^\n]*\)/g, (_, view) => view === 'dam::asset.preview-modal.card'
        ? '<div data-preview-frame><span data-member-name>{{ previewData.variantName || previewData.file_name }}</span><img v-if="previewData.file_type === \'image\'" ref="imgEl" :src="previewData.previewPath"><video v-else ref="videoEl" :src="previewData.playbackUrl"></video></div>' : '');
    const browser = await launchTestBrowser();
    try {
        for (const kind of ['image', 'video']) {
            const page = await browser.newPage({viewport: {width: 1024, height: 768}});
            const errors = [];
            page.on('pageerror', (error) => errors.push(error.message));
            await page.route('http://reload.test/**', (route) => route.fulfill({contentType: 'text/html', body: '<div id="app"></div>'}));
            await page.goto('http://reload.test/edit/1370?file=1691&nav=1&directory_id=all');
            await page.addScriptTag({path: process.env.MOTHERSHIP_VUE_RUNTIME_PATH});
            await page.addScriptTag({path: path.join(root, 'packages/Mothership/DAM/src/Resources/assets/js/details-navigation.js')});
            await page.evaluate(() => {
                const stub = () => ({data: {}, computed: {}, methods: {}});
                for (const name of ['_damInfoPanel', '_damImageViewer', '_damVideoPlayer', '_damAudioPlayer', '_damPanoViewer', '_damPdfViewer', '_damPreviewFocus']) window[name] = stub();
                window._damShownStackFile = {};
                window.MothershipNameTail = (name) => ({head: name, tail: ''});
                window.axios = {defaults: {headers: {common: {}}}};
                window.app = {component: (_, definition) => { window.previewDefinition = definition; }};
            });
            await page.addScriptTag({content: replaceCalls(stackSource.match(/<script>([\s\S]*?)<\/script>/)[1], () => '({})')});
            await page.addScriptTag({content: fitSource.match(/<script>([\s\S]*?)<\/script>/)[1]});
            const cover = {id: 1370, file_name: 'cover.' + (kind === 'image' ? 'jpg' : 'mp4'), file_type: kind, previewPath: '', playbackUrl: '', attachment_count: 2};
            const script = replaceCalls(source.match(/<script type="module">([\s\S]*?)<\/script>/)[1], (expression) => expression.includes('$initialPreviewData') ? JSON.stringify(cover) : expression.includes("request()->has('file')") ? 'true' : expression.includes("route('admin.dam.assets.files.index'") ? "'/files/__ASSET__'" : '({})').replace(/\{\{[\s\S]*?\}\}/g, 'fixture');
            await page.addScriptTag({content: script});
            await page.evaluate(({template, kind}) => {
                const definition = window.previewDefinition;
                definition.template = template;
                const noop = () => {};
                // Keep mounted/restore/switch/render real; unrelated media services do no I/O in this fixture.
                for (const name of ['startPreviewFocusGuard', 'stopPreviewFocusGuard', 'imgResetState', 'videoResetState', 'audioResetState', 'panoResetState', 'videoStopOnClose', 'audioStopOnClose', 'pdfDestroy', 'pdfInit', 'panoDestroy', 'panoInit', 'audioInitEl', 'videoInitEl', 'loadPanoramaState', 'schedulePanoramaPoll', 'panoramaInvalidate']) definition.methods[name] = noop;
                window.mountRefs = {};
                definition.methods.imgMounted = function () { window.mountRefs.image = !! this.$refs.imgEl; };
                definition.methods.videoMounted = function () { window.mountRefs.video = !! this.$refs.videoEl; };
                definition.methods.panoMounted = noop;
                const app = Vue.createApp(definition);
                app.component('v-dam-attachment-strip', {template: '<span></span>'});
                app.config.globalProperties.$emitter = {on() {}, off() {}, emit() {}};
                app.config.globalProperties.$axios = {get: () => new Promise((resolve) => { window.resolveMember = resolve; })};
                window.preview = app.mount('#app');
                window.fixtureKind = kind;
            }, {template, kind});
            assert.deepEqual(errors, [], 'mounted component must not throw');
            assert.equal(await page.locator('[data-preview-frame]').count(), 1, 'card exists during pending reload');
            assert.equal(await page.evaluate((kind) => window.mountRefs[kind], kind), true);
            assert.equal(await page.locator('[data-preview-frame]').isVisible(), false);
            await page.evaluate((kind) => window.resolveMember({data: {files: [{id: 1691, asset_id: 1370, attachment_id: 7, mime_type: kind === 'image' ? 'image/jpeg' : 'video/mp4', extension: kind === 'image' ? 'jpg' : 'mp4', file_name: 'selected.' + (kind === 'image' ? 'jpg' : 'mp4'), url: '/selected-bytes'}]}}), kind);
            await page.waitForFunction(() => ! window.preview.memberPending);
            assert.equal(await page.locator('[data-preview-frame]').isVisible(), true);
            assert.match(await page.locator('[data-member-name]').textContent(), /^selected\./);
            assert.equal(await page.evaluate(() => window.preview.previewData.variantFileId), 1691);
            assert.equal(await page.evaluate(() => window.preview.memberUnavailable), false);
            await page.waitForFunction(() => parseInt(document.querySelector('[data-preview-frame]').style.height) > 0);
            assert.deepEqual(errors, []);
            await page.close();
        }
    } finally { await browser.close(); }
});

const WORDS = {
    'dam::app.admin.dam.asset.stack-fan.info-stack-label': ':label (stack)',
    'dam::app.admin.dam.asset.stack-fan.info-describes-cover': 'File details follow the file shown (cover: :name).',
    'dam::app.admin.dam.asset.stack-fan.details-describe-stack': 'Whole stack (cover: :name).',
    'dam::app.admin.dam.asset.edit.preview-modal.asset-title': 'Title',
    'dam::app.admin.dam.asset.edit.file-name': 'File name',
    'dam::app.admin.dam.asset.edit.size': 'Size',
    'dam::app.admin.dam.asset.edit.dimensions': 'Dimensions',
    'dam::app.admin.dam.asset.edit.type': 'Type',
    'dam::app.admin.dam.asset.edit.preview-modal.mime': 'MIME',
    'dam::app.admin.dam.asset.edit.created-at': 'Created',
    'dam::app.admin.dam.asset.edit.updated-at': 'Updated',
    'dam::app.admin.dam.asset.edit.tags': 'Tags',
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
const asValue = (expression) => {
    const key = /trans\('([^']+)'\)/.exec(expression);
    return key ? JSON.stringify(word(key[1])) : 'null';
};

function parts() {
    // The Details card: from its stack note to the end of the accordion's rows.
    const from = edit.indexOf('{{-- A stacked file on show on the Preview tab');
    const to = edit.indexOf('</x-slot>', edit.indexOf('<template v-if="displayUpdatedAt">', from));
    assert.ok(from > 0 && to > from, 'the Details card markup was not found');
    let card = edit.slice(from, to);
    card = card.replace(/\{\{--[\s\S]*?--\}\}/g, '').replace(/@if \(\$stackSummary\)[\s\S]*?@endif/, '')
        .replace(/@if \(\$canRename\)([\s\S]*?)@endif/g, '$1');
    card = replaceCalls(card, asValue).replace(/@lang\('([^']+)'\)/g, (_, key) => word(key)).replace(/(?<!@)\{\{[\s\S]*?\}\}/g, 'x').replace(/@\{\{/g, '{{');

    const start = edit.indexOf("app.component('v-edit-asset'");
    let script = edit.slice(start, edit.indexOf('</script>', start));
    script = replaceCalls(script, asValue).replace(/\{\{[\s\S]*?\}\}/g, 'x').replace(/@(?:php|endphp)[^\n]*/g, '');

    const mixin = fs.readFileSync(path.join(root, 'packages/Mothership/DAM/src/Resources/views/asset/stack-shown-script.blade.php'), 'utf8').replace(/\{\{--[\s\S]*?--\}\}/g, '').replace(/<\/?script>/g, '');

    return {card, script, mixin};
}

browserTest('the Details card follows the stacked file on show, keeping the stack\'s own fields marked', async () => {
    const {card, script, mixin} = parts();
    const browser = await launchTestBrowser();
    try {
        const page = await browser.newPage();
        const problems = [];
        page.on('pageerror', (error) => problems.push(error.message));
        page.on('console', (message) => { if (/was accessed during render but is not defined|Unhandled error/.test(message.text())) problems.push(message.text()); });
        await page.route('http://card.test/**', (route) => route.fulfill({contentType: 'text/html', body: '<div id="app"></div>'}));
        await page.goto('http://card.test/');
        await page.addScriptTag({path: process.env.MOTHERSHIP_VUE_RUNTIME_PATH});
        await page.evaluate(() => { window.definitions = {}; window.app = {component: (name, definition) => { window.definitions[name] = definition; }}; });
        await page.addScriptTag({content: mixin});
        await page.addScriptTag({content: script});
        await page.evaluate(({card}) => {
            const definition = window.definitions['v-edit-asset'];
            const listeners = {};
            const app = Vue.createApp({
                template: `<div>${card}</div>`,
                mixins: definition.mixins,
                data: () => ({
                    detailFields: null, generationPrompt: null, asset: {id: 7, file_type: 'image'},
                    displayTitle: 'Spring banner', displayFileName: 'Cover.jpg', displayFileSize: '9.00 MB', displayWidth: 6000, displayHeight: 4000,
                    displayExtension: 'jpg', displayAdobe: null, displayMimeType: 'image/jpeg', displayCreatedAt: '01 Sep 2026', displayUpdatedAt: '02 Sep 2026',
                }),
                methods: {stackLabel: definition.methods.stackLabel},
                mounted() { definition.mounted.call(this); },
                beforeUnmount() { definition.beforeUnmount.call(this); },
            });
            app.config.globalProperties.$emitter = {on: (name, fn) => { listeners[name] = fn; }, off() {}, emit() {}};
            window.fire = (name, payload) => listeners[name]?.(payload);
            window.vm = app.mount('#app');
        }, {card});

        const rows = () => page.locator('[data-dam-naming-details] > div, #app .flex.justify-between').evaluateAll((nodes) => Object.fromEntries(nodes.map((node) => {
            const label = node.querySelector('dt, span');
            const value = node.querySelector('dd, span:last-child');
            return [label?.textContent.trim(), value?.textContent.trim()];
        })));
        assert.equal(await page.locator('[data-dam-details-variant-note]').count(), 0);
        let shown = await rows();
        assert.equal(shown['File name'], 'Cover.jpg');
        assert.equal(shown['Size'], '9.00 MB');
        assert.equal(shown['Dimensions'], '6000 × 4000 px');
        assert.equal(await page.locator('[data-dam-filename-pencil]').count(), 1);
        const actions = () => page.locator('[data-dam-title-pencil], [data-dam-filename-pencil]').count();
        // The title and file-name pencils open the cover's rename dialog.
        assert.equal(await actions(), 2, 'a control for the cover is missing on the cover');

        await page.evaluate(() => window.fire('dam:stack-file-shown', {assetId: 7, file: {name: 'Product-Shot.png', extension: 'png', mime: 'image/png', type: 'image', size: '2.0 KB', width: 800, height: 600}}));
        shown = await rows();
        assert.equal(shown['File name'], 'Product-Shot.png');
        assert.equal(shown['Size'], '2.0 KB');
        assert.equal(shown['Dimensions'], '800 × 600 px');
        assert.equal(shown['Type'], 'PNG');
        assert.equal(shown['MIME'], 'image/png');
        assert.equal(shown['Title'], undefined, 'an unmarked Title reads as the file\'s');
        assert.equal(shown['Title (stack)'], 'Spring banner');
        assert.equal(shown['Created (stack)'], '01 Sep 2026');
        assert.equal(shown['Updated (stack)'], '02 Sep 2026');
        assert.equal(await page.locator('[data-dam-filename-pencil]').count(), 0, 'the pencil would rename the cover under another file\'s name');
        assert.match(await page.locator('[data-dam-details-variant-note]').textContent(), /cover: Cover\.jpg/);
        assert.equal(await actions(), 0, 'a pencil for the cover stayed on screen over another file');

        // The cover coming back restores everything.
        await page.evaluate(() => window.fire('dam:stack-file-shown', {assetId: 7, file: null}));
        shown = await rows();
        assert.equal(shown['File name'], 'Cover.jpg');
        assert.equal(shown['Title'], 'Spring banner');
        assert.equal(await page.locator('[data-dam-filename-pencil]').count(), 1);
        assert.equal(await actions(), 2, 'the cover\'s controls did not come back');

        // Moving to another entry with the chevrons puts the cover back, even if no file event follows.
        await page.evaluate(() => window.fire('dam:stack-file-shown', {assetId: 7, file: {name: 'Product-Shot.png', extension: 'png', mime: 'image/png', type: 'image', size: '2.0 KB', width: 800, height: 600}}));
        assert.equal(await actions(), 0);
        await page.evaluate(() => window.fire('dam-asset-changed', {asset: {id: 8}}));
        assert.equal(await actions(), 2);
        await page.evaluate(() => window.fire('dam-asset-changed', {assetPosition: 2, assetTotal: 3}));
        assert.deepEqual(problems, []);
    } finally {
        await browser.close();
    }
});

test('the Tags heading is marked as the stack\'s while a stacked file is on show', () => {
    assert.match(edit, /stackLabel\(@js\(trans\('dam::app\.admin\.dam\.asset\.edit\.tags'\)\)\)/);
});
