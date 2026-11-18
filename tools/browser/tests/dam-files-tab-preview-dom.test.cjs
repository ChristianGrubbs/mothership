'use strict';
const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const root = path.resolve(__dirname, '../../..');
const dam = path.join(root, 'packages/Mothership/DAM');
const read = file => fs.readFileSync(path.join(dam, 'src/Resources/views', file), 'utf8');
const source = read('asset/files/index.blade.php');
// Extract the production method without interpreting unrelated Blade fields.
const openPreview = source.match(/                openPreview\(file, event = null\) \{[\s\S]*?\n                \},/)[0].trim().replace(/,$/, '');
const mixin = read('asset/preview-modal/attachments/strip-script.blade.php').match(/<script>([\s\S]*?)<\/script>/)[1].replace("@json(trans('dam::app.admin.dam.asset.files.roles'))", '{}');

test('Files forwards exact revision/order and origin to the shared viewer; redundant dialog is removed', () => {
    assert.ok(source.includes("@include('dam::asset.grid-preview-modal')"));
    assert.ok(!source.includes('data-dam-file-preview'));
    const trigger = {}, events = [], files = [{id: 10, asset_id: 7}, {id: 11, asset_id: 7}];
    const method = vm.runInNewContext(`({${openPreview}}).openPreview`, {document: {activeElement: trigger}, window: {scrollY: 410}});
    const state = {assetId: 7, ownFiles: files, $emitter: {emit: (...args) => events.push(args)}};
    method.call(state, files[1]);
    const [event, payload] = events[0];
    assert.equal(event, 'dam-open-preview');
    assert.equal(payload.file, files[1]);
    assert.equal(payload.variants, files);
    assert.equal(payload.origin.trigger, trigger);
    assert.deepEqual([payload.origin.kind, payload.origin.assetId, payload.origin.fileId, payload.origin.scrollTop], ['files', 7, 11, 410]);
    assert.equal(state.pickedFileId, 11);
    const clicked = {};
    method.call(state, files[0], {currentTarget: clicked});
    assert.equal(events[1][1].origin.trigger, clicked, 'the click control wins over an unfocused body');
});

test('shared renderer retains RAW screen copy, stored media type and text detection', () => {
    const context = {window: {MothershipNameTail: name => ({head: name, tail: ''})}};
    vm.runInNewContext(mixin, context);
    const state = {...context.window._damStackSwitch.methods, previewVariants: []};
    const file = {id: 9, asset_id: 7, label: 'RAW.DNG', mime_type: 'application/octet-stream', preview_url: '/copy.jpg', url: '/original.dng'};
    assert.equal(state.variantPreviewData(file).playbackKind, 'image');
    assert.equal(state.variantPreviewData(file).previewPath, '/copy.jpg');
    assert.equal(state.variantPreviewData({...file, preview_url: null}).playbackKind, null);
    assert.equal(state.variantPreviewData({...file, mime_type: 'video/mp4', preview_url: null}).playbackKind, 'video');
    assert.equal(state.variantPreviewData({...file, mime_type: 'application/pdf', preview_url: null}).extension, 'pdf');
    assert.equal(state.isTextFile('text/plain', ['Notes.txt']), true);
    assert.equal(state.isTextFile('application/octet-stream', ['Prompt', 'prompt.md']), true);
});

test('text streams into the shared viewer and an aborted response for the same revision cannot replace a newer open', async () => {
    let answerOld;
    const context = {window: {}, AbortController, TextDecoder, Uint8Array, fetch: () => new Promise(resolve => { answerOld = resolve; })};
    vm.runInNewContext(mixin, context);
    const state = {...context.window._damStackSwitch.data, ...context.window._damStackSwitch.methods, previewData: {variantFileId: 9}};
    const file = {id: 9, url: '/notes.txt'};
    const pending = state.loadText(file);
    state.resetTextPreview();
    context.fetch = async () => new Response('new content');
    await state.loadText(file);
    assert.equal(state.textState, 'ready');
    assert.equal(state.textContent, 'new content');
    answerOld(new Response('old content'));
    await pending;
    assert.equal(state.textContent, 'new content');
});

const playwrightPath = process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH;
const browserTest = playwrightPath ? test : test.skip;

browserTest('native Library mouse click preserves its origin through capture/bubble timing and returns focus', async () => {
    const browser = await require('../launch-browser.cjs').launchBrowser({playwrightPath});
    const grid = read('asset/grid-preview-modal.blade.php');
    const capture = grid.slice(grid.indexOf('                this._onPreviewOriginClick ='), grid.indexOf("                // mitt's emit"));
    const listener = grid.slice(grid.indexOf('                this._onPreviewRequested ='), grid.indexOf("                // A stack's fan dismissed"));
    const preview = read('components/explorer/grid.blade.php').match(/preview\(id\)\s*\{[^\n]+\},/)[0].replace(/,$/, '');
    try {
        const page = await browser.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.setContent('<div id="app"></div>');
        await page.addScriptTag({path: process.env.MOTHERSHIP_VUE_RUNTIME_PATH || path.join(dam, 'node_modules/vue/dist/vue.global.js')});
        await page.addScriptTag({path: path.join(dam, 'src/Resources/assets/js/preview-navigation.js')});
        await page.evaluate(({capture, listener, preview}) => {
            const handlers = {};
            const emitter = {on: (type, callback) => { handlers[type] = callback; }, emit: (type, payload) => handlers[type]?.(payload)};
            const app = Vue.createApp({
                // Preventing mousedown focus reproduces browsers that keep body active after a mouse click.
                template: `<button data-library-open @mousedown.prevent @click="preview(7)">Open Library asset</button><section v-if="isOpen" role="dialog" tabindex="-1" ref="dialog"><button @click="close">Close preview</button></section>`,
                data: () => ({assets: [{id: 7}], currentDirId: 'all', resultTotal: 1, resultOffset: 0, isOpen: false, previewOrigin: null, previewVariant: null, previewVariants: []}),
                methods: {...new Function(`return ({${preview}})`)(), trackResultOpened() {}, rememberPreviewOpener() {},
                    openById() { this.isOpen = true; this.$nextTick(() => this.$refs.dialog.focus()); },
                    close() { this.isOpen = false; window.MothershipPreviewNavigation.restoreOrigin({origin: this.previewOrigin}); },
                },
            });
            app.config.globalProperties.$emitter = emitter;
            window.state = app.mount('#app');
            new Function(capture + listener).call(window.state);
            document.body.tabIndex = -1;
            document.body.focus();
        }, {capture, listener, preview});
        const button = page.locator('[data-library-open]');
        const bounds = await button.boundingBox();
        await page.mouse.click(bounds.x + bounds.width / 2, bounds.y + bounds.height / 2);
        await page.getByRole('dialog').waitFor();
        assert.equal(await page.evaluate(() => window.state.previewOrigin.trigger === document.querySelector('[data-library-open]')), true);
        await page.getByRole('button', {name: 'Close preview', exact: true}).click();
        assert.equal(await button.evaluate(el => el === document.activeElement), true);
        await page.waitForFunction(() => window.state._previewClickTrigger === null);
        assert.deepEqual(errors, []);
    } finally { await browser.close(); }
});

browserTest('mounted shared viewer handles RAW copy 404 and generic bytes without a broken image; Library buttons remain keyboard reachable', async () => {
    const browser = await require('../launch-browser.cjs').launchBrowser({playwrightPath});
    // Mount the production shared template and actual image renderer/error handler, not a stand-in image shell.
    const expand = body => body.replace(/@include\('dam::asset.preview-modal.image.image-viewer'\)/g, () => read('asset/preview-modal/image/image-viewer.blade.php'))
        .replace(/@include\('dam::asset.preview-modal.fallback'\)/g, () => read('asset/preview-modal/fallback.blade.php'));
    const template = expand(read('asset/preview-modal/viewer-modal.blade.php'))
        .replace(/\{\{--[\s\S]*?--\}\}/g, '').replace(/@include\([^\n]*\)/g, '')
        .replace(/@if[^\n]*?@endif/g, '').replace(/@(if|unless)[^\n]*\n/g, '').replace(/@(endif|endunless)/g, '')
        .replace(/@else\s*:href="previewData.editUrl"/g, '')
        .replace(/@js\(trans\('([^']+)'\)\)/g, (_, key) => JSON.stringify(key.split('.').pop()))
        .replace(/@lang\('([^']+)'\)/g, (_, key) => key.split('.').pop())
        .replace(/(?<!@)\{\{[\s\S]*?\}\}/g, 'Label').replace(/@\{\{/g, '{{').replace(/@@load/g, '@load');
    try {
        const page = await browser.newPage();
        const errors = [], requested = [];
        page.on('pageerror', error => errors.push(error.message));
        page.on('console', message => { if (/Unhandled error|was accessed during render/.test(message.text())) errors.push(message.text()); });
        await page.route('http://viewer.test/**', route => {
            const pathname = new URL(route.request().url()).pathname;
            requested.push(pathname);
            if (pathname === '/missing-copy.jpg') return route.fulfill({status: 404, body: 'copy unavailable'});
            if (pathname === '/') return route.fulfill({contentType: 'text/html', body: '<div id="app"></div>'});
            return route.fulfill({contentType: 'image/svg+xml', body: '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20"><rect width="20" height="20" fill="purple"/></svg>'});
        });
        await page.goto('http://viewer.test/');
        await page.addScriptTag({path: process.env.MOTHERSHIP_VUE_RUNTIME_PATH || path.join(dam, 'node_modules/vue/dist/vue.global.js')});
        await page.addScriptTag({path: path.join(dam, 'src/Resources/assets/js/preview-navigation.js')});
        await page.addScriptTag({content: mixin});
        await page.addScriptTag({content: read('asset/preview-modal/image/image-viewer-script.blade.php').replace(/<\/?script[^>]*>/g, '').replace(/\{\{[\s\S]*?\}\}/g, 'Label')});
        await page.evaluate(({template}) => {
            window.MothershipNameTail = name => ({head: name, tail: ''});
            const cover = {id: 7, file_name: 'Stack', display_name: 'Stack', extension: 'png', file_type: 'image', placeholderSvg: '/placeholder.svg', filesUrl: '', editUrl: '', downloadUrl: '/cover', attachment_count: 2, typeColor: ''};
            const file = {id: 9, asset_id: 7, label: 'RAW.DNG', mime_type: 'application/octet-stream', preview_url: '/missing-copy.jpg', url: '/original.dng', download_url: '/original.dng?download=1'};
            const noop = () => {};
            const app = Vue.createApp({template,
                data: () => ({...window._damStackSwitch.data, ...window._damImageViewer.data, isOpen: true, favorite: false, isAssetLocked: false, isInfoPanelOpen: false, previewOrigin: {kind: 'library'}, previewData: cover, libraryHops: 0}),
                computed: {...window._damImageViewer.computed, previewBackLabel: () => 'Back to Library', previewNeighbours: () => ({previousId: 6, nextId: 8, total: 3, position: 2})},
                methods: {...window._damStackSwitch.methods, ...window._damImageViewer.methods,
                    show(file) { this.previewVariant = file; this.previewVariants = [file]; this.imgResetState(); this.previewData = {...cover, ...this.variantPreviewData(file)}; },
                    navigatePrev() { this.libraryHops++; }, navigateNext() { this.libraryHops++; },
                    openDetailsHref: () => '', onOpenDetailsClick: noop, toggleFavorite: noop, toggleInfoPanel: noop, closePreviewWithInfoReset: noop, findSimilarFromPreview: noop,
                },
            });
            app.component('v-dam-attachment-strip', {template: '<div></div>'});
            window.state = app.mount('#app');
            window.state.show(file);
        }, {template});
        const dialog = page.getByRole('dialog');
        await dialog.getByText('not-available', {exact: true}).waitFor();
        assert.equal(await dialog.getByRole('link', {name: 'download-original', exact: true}).last().getAttribute('href'), '/original.dng?download=1');
        assert.equal(await dialog.locator('img[src="/missing-copy.jpg"]').count(), 0);
        assert.equal(requested.includes('/original.dng'), false, 'RAW original must never be loaded to preview');
        await page.evaluate(() => window.state.show({id: 10, asset_id: 7, label: 'opaque.bin', mime_type: 'application/octet-stream', url: '/opaque.bin', download_url: '/opaque.bin?download=1'}));
        await dialog.getByText('not-available', {exact: true}).waitFor();
        assert.equal(await dialog.getByRole('link', {name: 'download-original', exact: true}).last().getAttribute('href'), '/opaque.bin?download=1');
        assert.equal(await dialog.locator('img[src="/opaque.bin"]').count(), 0);
        assert.equal(requested.includes('/opaque.bin'), false);
        const next = dialog.getByRole('button', {name: 'Next Library result', exact: true});
        await next.focus();
        assert.equal(await next.evaluate(el => el === document.activeElement), true);
        await page.keyboard.press('Enter');
        assert.equal(await page.evaluate(() => window.state.libraryHops), 1);
        assert.equal(await dialog.getByRole('button', {name: 'Previous Library result', exact: true}).isEnabled(), true);
        assert.deepEqual(errors, []);
    } finally { await browser.close(); }
});

browserTest('Files mouse/keyboard launch records the real row; shared origin restores focus and fallback after removal', async () => {
    const browser = await require('../launch-browser.cjs').launchBrowser({playwrightPath});
    try {
        const page = await browser.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.setContent('<div id="app"></div>');
        await page.addScriptTag({path: process.env.MOTHERSHIP_VUE_RUNTIME_PATH || path.join(dam, 'node_modules/vue/dist/vue.global.js')});
        await page.addScriptTag({path: path.join(dam, 'src/Resources/assets/js/preview-navigation.js')});
        await page.evaluate(({openPreview}) => {
            const files = [{id: 1, asset_id: 7, label: 'Image'}, {id: 2, asset_id: 7, label: 'Video'}, {id: 3, asset_id: 7, label: 'Document'}];
            const app = Vue.createApp({
                template: `<div data-dam-files-panel tabindex="-1"><button v-for="file in ownFiles" :data-open="file.id" @click="openPreview(file, $event)">Open {{file.label}}</button></div><section v-if="opened" role="dialog" aria-modal="true"><span>{{opened.file.label}}</span><button @click="close">Close</button></section>`,
                data: () => ({assetId: 7, ownFiles: files, opened: null}),
                methods: {...new Function(`return ({${openPreview}})`)(), close() { const origin = this.opened.origin; this.opened = null; window.MothershipPreviewNavigation.restoreOrigin({origin}); }},
            });
            app.config.globalProperties.$emitter = {emit: (_event, payload) => { window.state.opened = payload; }};
            window.state = app.mount('#app');
        }, {openPreview});
        for (const id of [1, 2, 3]) {
            const button = page.locator(`[data-open="${id}"]`);
            if (id === 2) { await button.focus(); await page.keyboard.press('Enter'); } else await button.click();
            assert.equal(await page.evaluate(() => window.state.opened.file.id), id);
            await page.getByText('Close', {exact: true}).click();
            assert.equal(await button.evaluate(el => el === document.activeElement), true);
        }
        await page.locator('[data-open="3"]').click();
        await page.locator('[data-open="3"]').evaluate(el => el.remove());
        await page.getByText('Close', {exact: true}).click();
        assert.equal(await page.locator('[data-dam-files-panel]').evaluate(el => el === document.activeElement), true);
        await page.evaluate(() => {
            document.body.tabIndex = -1;
            document.body.focus();
            window.state.openPreview(window.state.ownFiles[0]);
        });
        assert.equal(await page.evaluate(() => window.state.opened.origin.trigger === document.body), true);
        await page.getByText('Close', {exact: true}).click();
        assert.equal(await page.locator('[data-dam-files-panel]').evaluate(el => el === document.activeElement), true, 'body origin must restore the Files fallback');
        assert.deepEqual(errors, []);
    } finally { await browser.close(); }
});

test('a stacked Illustrator file keeps its Adobe identity and raw PDF type when the viewer switches to it, a genuine PDF beside it does not', () => {
    const context = {window: {MothershipNameTail: name => ({head: name, tail: ''})}};
    vm.runInNewContext(mixin, context);
    const state = {...context.window._damStackSwitch.methods, previewVariants: []};
    const illustrator = {id: 12, asset_id: 7, label: 'Banner Logo.ai', mime_type: 'application/pdf', extension: 'ai', url: '/original.ai', adobe: {key: 'illustrator', app: 'Adobe Illustrator', title: 'Adobe Illustrator file (.AI)', pdf: true}};
    const pdf = {id: 13, asset_id: 7, label: 'Brochure.pdf', mime_type: 'application/pdf', extension: 'pdf', url: '/original.pdf', adobe: null};
    const shown = state.variantPreviewData(illustrator);

    // Named by the server: an .ai, Illustrator, with the stored PDF type still available to technical info.
    assert.deepEqual([shown.extension, shown.extension_upper, shown.mime_type, shown.adobe.key, shown.adobe.pdf], ['ai', 'AI', 'application/pdf', 'illustrator', true]);
    assert.deepEqual([state.variantPreviewData(pdf).extension, state.variantPreviewData(pdf).adobe], ['pdf', null]);
    // Every viewer branch that mounts the PDF renderer also accepts PDF-compatible Illustrator bytes.
    for (const file of ['asset/preview-modal/card.blade.php', 'asset/preview-modal/viewer-modal.blade.php', 'asset/preview-modal/files/pdf-viewer-script.blade.php']) {
        assert.match(read(file), /adobe\?\.pdf/, file);
    }
});

test('a selected Adobe file with no picture shows its application mark in the viewer, and the strip, Files table and cover carry the mark', () => {
    const context = {window: {MothershipNameTail: name => ({head: name, tail: ''}), MothershipAdobe: {tile: adobe => `tile:${adobe.key}`}}};
    vm.runInNewContext(mixin, context);
    const state = {...context.window._damStackSwitch.methods, previewVariants: [], _coverPreviewData: {placeholderSvg: '/storage/dam/preview/file.svg'}};
    const indd = {id: 20, asset_id: 7, label: 'Layout.indd', mime_type: 'application/octet-stream', extension: 'indd', url: '/o', adobe: {key: 'indesign', app: 'Adobe InDesign', pdf: false}};
    const note = {id: 21, asset_id: 7, label: 'notes.txt', mime_type: 'text/plain', extension: 'txt', url: '/n', adobe: null};

    // The cover's published-only placeholder (a 404 without vendor:publish) never stands in for an Adobe member.
    assert.equal(state.variantPreviewData(indd).placeholderSvg, 'tile:indesign');
    assert.equal('placeholderSvg' in state.variantPreviewData(note), false);
    assert.match(read('asset/preview-modal/attachments/strip-script.blade.php'), /data-dam-strip-adobe-mark/);
    assert.match(read('asset/files/index.blade.php'), /data-dam-file-adobe-tile[\s\S]*data-dam-file-adobe-mark/);
    assert.match(read('asset/preview-modal.blade.php'), /AdobeFormats::tiles\(\)/);
});

test('no DAM view writes a bare Vue @error attribute: Blade reads it as its @error directive and the page fails to compile', () => {
    const views = path.join(root, 'packages/Mothership/DAM/src/Resources/views');
    const offenders = [];
    (function walk(dir) {
        for (const entry of fs.readdirSync(dir, {withFileTypes: true})) {
            const full = path.join(dir, entry.name);
            if (entry.isDirectory()) { walk(full); continue; }
            if (!entry.name.endsWith('.blade.php')) continue;
            // `@@error=` is the escaped spelling (renders `@error=`); `v-on:error=` needs none.
            if (/(?<!@)@error\b(?!\s*\()/.test(fs.readFileSync(full, 'utf8').replace(/\{\{--[\s\S]*?--\}\}/g, ''))) offenders.push(path.relative(root, full));
        }
    })(views);
    assert.deepEqual(offenders, []);
});
