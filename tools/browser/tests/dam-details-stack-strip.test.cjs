'use strict';

// The details page's viewer (v-asset-preview-modal's real template and component script, with the real image
// viewer, stack-switch mixin and attachment strip): a click on a strip tile views that file, the separate download
// checkbox only ticks, closing returns to the cover, and below 768px the Info pane steps aside. Also the details
// page's Rename with AI button and the AI rename dialog opening one file pre-ticked.
const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '../../..');
const dam = path.join(root, 'packages/Mothership/DAM');
const views = path.join(dam, 'src/Resources/views');
const playwrightPath = process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH;
const browserTest = playwrightPath ? test.bind(null) : test.skip;
const read = (file) => fs.readFileSync(path.join(views, file), 'utf8');

// Blade stand-ins: a translation key becomes its last segment, except the few the assertions read.
const WORDS = {
    'dam::app.admin.dam.asset.stack-fan.viewing-stack-file': 'Viewing a stack file. Select the cover to edit, rename or replace.',
    'dam::app.admin.explorer.ai-rename.action': 'Rename with AI',
    'dam::app.admin.dam.asset.files.view-file': 'View :name',
    'dam::app.admin.dam.asset.files.select-file': 'Select :name for download',
    'dam::app.admin.dam.asset.files.no-access': "You don't have access to this stack's files. Ask an admin.",
    'dam::app.admin.dam.asset.stack.files-count': ':count files',
    'dam::app.admin.explorer.ai-rename.needs-description': 'No AI description, ask an admin',
    'dam::app.admin.explorer.ai-rename.apply': 'Rename :count',
    'dam::app.admin.explorer.ai-rename.title': 'Rename :count files',
    'dam::app.admin.explorer.ai-rename.title-one': 'Rename this file',
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

const trans = (expression) => {
    const key = /trans\('([^']+)'\)/.exec(expression);
    return key ? JSON.stringify(word(key[1])) : null;
};

/** The markup of an x-template, with Blade conditionals resolved for a role that holds every permission asked. */
function markup(source, id, {keep = [], escaped = false} = {}) {
    let body = new RegExp(`id="${id}"\\s*>([\\s\\S]*?)</script>`).exec(source)[1];
    body = body.replace(/\{\{--[\s\S]*?--\}\}/g, '');
    body = body.replace(/@unless\([^\n]*\)[\s\S]*?@endunless/g, '');
    body = body.replace(/@if \(bouncer\(\)->hasPermission\('([^']+)'\)\)([\s\S]*?)@endif/g, (_, permission, inner) => (keep.includes(permission) ? inner : ''));
    body = body.replace(/@include\('dam::asset\.preview-modal\.info-panel'\)/, () => read('asset/preview-modal/info-panel.blade.php'));
    return body.replace(/@include\([^\n]*\)/g, '').replace(/@if\b[\s\S]*?@endif/g, '')
        .replace(/@js\(trans\('([^']+)'\)\)/g, (_, key) => `'${word(key)}'`).replace(/@lang\('([^']+)'\)/g, (_, key) => word(key))
        .replace(/(?<!@)\{\{\s*trans\('([^']+)'\)\s*\}\}/g, (_, key) => word(key)).replace(/(?<!@)\{\{[\s\S]*?\}\}/g, 'x').replace(/@\{\{/g, escaped ? '@{{' : '{{');
}

const fixture = {
    id: 7, file_name: 'Cover.png', display_name: 'Cover.png', extension: 'png', extension_upper: 'PNG', file_type: 'image', asset_kind: 'image',
    mime_type: 'image/png', path: 'assets/cover.png', width: 800, height: 600, mediaUrl: '/f/1.svg', playbackUrl: '/f/1.svg', playbackMime: 'image/svg+xml',
    playbackKind: 'image', previewPath: '/f/1.svg', typeColor: '', fileSize: '1 KB', downloadUrl: '/dl', editUrl: '', filesUrl: '',
    // ImageEditing::previewFields() for a PNG and a user with the editor and edit permissions: the original is editable,
    // so no thumbnail edit is offered (the page spreads these into its initial previewData; show() nests them below).
    canEditOriginal: true, canEditThumbnail: false, thumbnailEditUrl: null,
};
// What AssetController::show() sends for the asset (the chevron/Library payload), the production source of the stack's file count.
const showPayload = {
    asset: {id: 7, file_name: 'Cover.png', display_name: 'Cover.png', extension: 'png', file_type: 'image', asset_kind: 'image', mime_type: 'image/png', path: 'assets/cover.png', file_size: 1024},
    width: 800, height: 600, mediaUrl: '/f/1.svg', playbackSource: {url: '/f/1.svg', mime: 'image/svg+xml', kind: 'image'}, previewPath: '/f/1.svg',
    fileSize: '1 KB', downloadUrl: '/dl', editUrl: '/admin/dam/assets/edit/7', filesUrl: '', attachmentCount: 3,
    imageEditing: {canEditOriginal: true, canEditThumbnail: false, thumbnailEditUrl: null},
};
const files = [
    {id: 1, asset_id: 7, attachment_id: 11, label: 'Cover.png', role: 'primary', is_representative: true, mime_type: 'image/png', extension: 'png', url: '/f/1.svg', size: 1024},
    {id: 2, asset_id: 7, attachment_id: 12, label: 'Second.png', role: 'alternate', is_representative: false, mime_type: 'image/png', extension: 'png', url: '/f/2.svg', size: 2048},
    {id: 3, asset_id: 7, attachment_id: 13, label: 'Third.png', role: 'alternate', is_representative: false, mime_type: 'image/png', extension: 'png', url: '/f/3.svg', size: 4096},
];

async function withBrowser(run) {
    const {pathToFileURL} = require('node:url');
    const browser = await require('../launch-browser.cjs').launchBrowser({playwrightPath});
    try { await run(browser); } finally { await browser.close(); }
}

async function newPage(browser, viewport) {
    const page = await browser.newPage({viewport});
    page.problems = [];
    page.on('pageerror', (error) => page.problems.push(error.message));
    page.on('console', (message) => { if (/was accessed during render but is not defined|Unhandled error/.test(message.text())) page.problems.push(message.text()); });
    await page.route('http://strip.test/**', (route) => {
        const pathname = new URL(route.request().url()).pathname;
        if (pathname === '/') return route.fulfill({contentType: 'text/html', body: '<div id="app"></div>'});
        if (pathname.startsWith('/missing')) return route.fulfill({status: 404, body: 'gone'});
        return route.fulfill({contentType: 'image/svg+xml', body: '<svg xmlns="http://www.w3.org/2000/svg" width="800" height="600"><rect width="800" height="600" fill="#ddd6fe"/></svg>'});
    });
    await page.goto('http://strip.test/');
    await page.addScriptTag({path: process.env.MOTHERSHIP_VUE_RUNTIME_PATH || path.join(dam, 'node_modules/vue/dist/vue.global.js')});
    await page.addScriptTag({path: path.join(dam, 'src/Resources/assets/js/details-navigation.js')});
    await page.addScriptTag({path: path.join(dam, 'src/Resources/assets/js/preview-navigation.js')});
    // The details page's record of a stacked file on show, as every component that spreads it sees it.
    await page.addScriptTag({content: read('asset/stack-shown-script.blade.php').replace(/\{\{--[\s\S]*?--\}\}/g, '').replace(/<\/?script>/g, '')});
    return page;
}

/** The details-page viewer plus the real strip, with the stack's files served by a stubbed list request. */
async function mountViewer(browser, {viewport = {width: 1280, height: 900}, canList = true, infoOpen = false, count = 3, inline = false, editor = false, stackFiles = files} = {}) {
    const page = await newPage(browser, viewport);
    const entry = read('asset/preview-modal.blade.php');
    const stripSource = read('asset/preview-modal/attachments/strip-script.blade.php');
    const component = (source, from) => replaceCalls(source.slice(from).replace(/<\/script>[\s\S]*$/, ''), (expression) => {
        if (expression.includes('hasPermission')) return String(canList);
        if (expression.includes('files.roles')) return '{}';
        if (expression.includes('strip-hint') || expression.includes('reorder-hint') || expression.includes('select-hint')) return '""';
        if (expression.includes('$initialPreviewData')) return JSON.stringify(fixture);
        if (expression.includes('__ASSET__')) return '"/editor/__ASSET__"';
        if (expression.includes('directoryAncestors')) return '[]';
        if (expression.includes('panoramaPreview')) return 'null';
        if (expression.includes('Labels')) return '{}';
        return trans(expression) || '"x"';
    }).replace(/\{\{[^}]*\}\}/g, '/files/__ASSET__');
    const stripScript = component(stripSource, stripSource.lastIndexOf('<script type="module">') + '<script type="module">'.length)
        .replace(/@include\([^\n]*\)/g, '');
    const modalScript = component(entry, entry.lastIndexOf('<script type="module">') + '<script type="module">'.length);
    const viewerBody = markup(read('asset/preview-modal/viewer-modal.blade.php').replace(/^/, '<script id="v">').concat('</script>'), 'v', {keep: [], escaped: true});
    const stripMarkup = markup(stripSource, 'v-dam-attachment-strip-template', {keep: ['dam.asset.download_compressed']});
    // The details page's whole preview component: the inline card, the strip under it, and the viewer over them.
    const cardBody = markup(read('asset/preview-modal/card.blade.php').replace(/@php[\s\S]*?@endphp/, '').replace(/^/, '<script id="v">').concat('</script>'), 'v', {keep: editor ? ['dam.asset.editor'] : [], escaped: true});
    const infoBody = markup(read('asset/preview-modal/info-modal.blade.php').replace(/^/, '<script id="v">').concat('</script>'), 'v', {keep: [], escaped: true});
    const inlineBody = markup(entry.replace("@include('dam::asset.preview-modal.card')", () => cardBody).replace("@include('dam::asset.preview-modal.info-modal')", () => infoBody)
        .replace("@include('dam::asset.preview-modal.editor-modal')", () => read('asset/preview-modal/editor-modal.blade.php'))
        .replace("@include('dam::asset.preview-modal.viewer-modal', ['fromDetailsPage' => true])", () => '<div v-if="isOpen" data-test-viewer>' + viewerBody + '</div>'), 'v-asset-preview-modal-template', {keep: []});

    await page.evaluate(({infoOpen}) => {
        const noop = () => {}, stub = (names) => ({data: {}, computed: {}, methods: Object.fromEntries(names.map((name) => [name, noop]))});
        window._damVideoPlayer = stub(['videoMounted', 'videoBeforeUnmount', 'videoResetState', 'videoInitEl', 'videoStopOnClose', 'videoShowControls']);
        window._damAudioPlayer = stub(['audioResetState', 'audioInitEl', 'audioStopOnClose']);
        window._damPdfViewer = stub(['pdfInit', 'pdfDestroy']);
        window._damPanoViewer = stub(['panoMounted', 'panoBeforeUnmount', 'panoResetState', 'panoramaInvalidate', 'panoInit', 'panoDestroy', 'schedulePanoramaPoll']);
        window._damPreviewFocus = {data: {}, computed: {}, methods: {rememberPreviewOpener: noop, focusPreview: noop, trapPreviewTab: noop, startPreviewFocusGuard: noop, stopPreviewFocusGuard: noop, restorePreviewFocus: noop}};
        window._damInfoPanel = {
            data: {isInfoPanelOpen: infoOpen, isInfoLoading: false, infoData: null, infoError: '', infoAssetId: null},
            computed: {infoBuiltinFields: () => []},
            methods: {loadInfoPanel: noop, infoStackLabel(label) { return this.previewData?.variantFileId ? ':label (stack)'.replace(':label', label) : label; }, resetInfoPanel() { this.infoData = null; }, toggleInfoPanel() { this.isInfoPanelOpen = !this.isInfoPanelOpen; localStorage.setItem('dam_preview_info_open', String(this.isInfoPanelOpen)); }, closePreviewWithInfoReset() { this.closePreview(); }},
        };
        window._damSavedInfoOpen = () => localStorage.getItem('dam_preview_info_open') === 'true';
        window.MothershipNameTail = (name) => ({head: name, tail: ''});
        window.MothershipFavorites = {};
        window.MothershipFavoriteState = {selection: () => ({select: noop})};
        window.axios = {defaults: {headers: {common: {}}}};
        window.definitions = {};
        window.app = {component: (name, definition) => { window.definitions[name] = definition; }};
    }, {infoOpen});
    await page.addScriptTag({content: read('asset/preview-modal/frame-fit-script.blade.php').match(/<script>([\s\S]*?)<\/script>/)[1]});
    await page.addScriptTag({content: read('asset/preview-modal/image/image-viewer-script.blade.php').replace(/<\/?script[^>]*>/g, '').replace(/\{\{[\s\S]*?\}\}/g, 'Label')});
    await page.addScriptTag({content: stripSource.match(/<script>([\s\S]*?)<\/script>/)[1].replace("@json(trans('dam::app.admin.dam.asset.files.roles'))", '{}')});
    await page.addScriptTag({content: stripScript});
    await page.addScriptTag({content: modalScript});
    await page.evaluate(({viewerBody, stripMarkup, fixture, files, inlineBody, inline}) => {
        window.stackFiles = files;
        const definition = window.definitions['v-asset-preview-modal'], data = definition.data;
        const app = Vue.createApp({...definition, template: inline ? inlineBody : `<div>${'<div v-if="isOpen" data-test-viewer>' + viewerBody + '</div>'}</div>`, data() { return {...data.call(this), previewData: {...fixture}, favorite: false}; }, methods: {toggleFavorite() {}, ...definition.methods}});
        app.component('v-dam-attachment-strip', {...window.definitions['v-dam-attachment-strip'], template: stripMarkup});
        window.emitted = [];
        // A working emitter, so the page's shown-file record and the header's "Back to cover" hear what the viewer says.
        const listeners = {};
        window.emitter = {
            on: (type, fn) => (listeners[type] ||= []).push(fn),
            off(type, fn) { listeners[type] = (listeners[type] || []).filter((other) => other !== fn); },
            emit(type, payload) { window.emitted.push([type, payload]); (listeners[type] || []).slice().forEach((fn) => fn(payload)); },
        };
        app.config.globalProperties.$emitter = window.emitter;
        // `window.listFails` makes the stack's file list (and a reorder) fail, as a dropped connection would.
        app.config.globalProperties.$axios = {
            get: async () => { if (window.listFails) throw new Error('offline'); return {data: {files: window.stackFiles, can_edit: false}}; },
            put: async () => { if (window.listFails) throw new Error('offline'); return {data: {files: window.stackFiles}}; },
        };
        window.modal = app.mount('#app');
    }, {viewerBody, stripMarkup, fixture, files: stackFiles, inlineBody, inline});
    // The page's initial previewData has no count in this fixture; the count arrives the way it does in production,
    // through the viewer's own mapping of the show() payload (the details page's prev/next hop).
    await page.evaluate((payload) => window.modal.onDamAssetChanged(payload), {...showPayload, attachmentCount: count});
    return page;
}

const tile = (page, name) => page.locator(`[data-dam-strip-item][aria-label="View ${name}"]`);

browserTest('strip uses one tab stop, arrows and Home/End move focus without selection, Enter selects', () => withBrowser(async (browser) => {
    const page = await mountViewer(browser);
    await page.evaluate(() => window.modal.openPreview());
    const cover = tile(page, 'Cover.png');
    await cover.waitFor();
    await cover.focus();
    assert.equal(await page.locator('[data-dam-strip-item][tabindex="0"]').count(), 1);
    await page.keyboard.press('ArrowRight');
    assert.equal(await tile(page, 'Second.png').evaluate(el => el === document.activeElement), true);
    assert.equal(await page.evaluate(() => window.modal.previewData.variantFileId), undefined);
    await page.keyboard.press('End');
    assert.equal(await tile(page, 'Third.png').evaluate(el => el === document.activeElement), true);
    await page.keyboard.press('ArrowRight');
    assert.equal(await tile(page, 'Third.png').evaluate(el => el === document.activeElement), true);
    await page.keyboard.press('Enter');
    assert.equal(await page.evaluate(() => window.modal.previewData.variantFileId), 3);
    assert.equal(await page.getByRole('button', {name: 'Next file', exact: true}).isDisabled(), true);
    await page.getByRole('button', {name: 'Previous file', exact: true}).click();
    assert.equal(await page.evaluate(() => window.modal.previewData.variantFileId), 2);
    assert.equal(await page.evaluate(() => window.modal.previewData.id), 7);
    // A shared Files viewer above this details viewer owns both keys and its focus guard.
    const blocked = await page.evaluate(() => {
        window._damGridPreviewActive = true;
        let closes = 0;
        const close = window.modal.closePreview;
        window.modal.closePreview = () => { closes++; };
        window.modal.handleEscape({key: 'Escape'});
        const dialog = window.modal.previewDialog();
        window.modal.closePreview = close;
        window._damGridPreviewActive = false;
        return {closes, dialog};
    });
    assert.deepEqual(blocked, {closes: 0, dialog: null});
    await tile(page, 'Third.png').focus();
    await page.keyboard.press('Home');
    assert.equal(await cover.evaluate(el => el === document.activeElement), true);
    assert.deepEqual(page.problems, []);
}));

browserTest('clicking a strip tile on the details page views that file; the checkbox only ticks it for download', () => withBrowser(async (browser) => {
    const page = await mountViewer(browser);
    await page.evaluate(() => window.modal.openPreview());
    await tile(page, 'Second.png').waitFor();
    assert.equal(await page.locator('[data-dam-strip-item]').count(), 3);

    await tile(page, 'Second.png').click();
    assert.equal(await page.evaluate(() => window.modal.previewData.variantFileId), 2);
    assert.equal(await page.evaluate(() => window.modal.previewData.mediaUrl), '/f/2.svg');
    assert.equal(await tile(page, 'Second.png').locator('[data-dam-strip-viewing]').count(), 1);
    assert.equal(await tile(page, 'Cover.png').locator('[data-dam-strip-viewing]').count(), 0);

    // The checkbox of another tile ticks without switching the viewed file.
    await tile(page, 'Third.png').hover();
    await tile(page, 'Third.png').locator('[data-dam-strip-check]').check();
    assert.equal(await page.evaluate(() => window.modal.previewData.variantFileId), 2, 'ticking a box switched the viewed file');
    assert.equal(await tile(page, 'Third.png').getAttribute('aria-selected'), 'true');
    assert.equal(await tile(page, 'Second.png').getAttribute('aria-selected'), 'false');

    // Back to the cover from its tile; then to another file.
    await tile(page, 'Cover.png').click();
    assert.equal(await page.evaluate(() => window.modal.previewData.variantFileId), undefined);
    await tile(page, 'Third.png').click();
    assert.equal(await page.evaluate(() => window.modal.previewData.variantFileId), 3);
    // The details page shows the stack inline too, so closing the viewer leaves the file shown there on screen.
    await page.evaluate(() => window.modal.closePreview());
    assert.equal(await page.evaluate(() => [window.modal.previewData.variantFileId, window.modal.previewData.mediaUrl]).then((v) => v.join()), '3,/f/3.svg');
    await page.evaluate(() => window.modal.openPreview());
    await tile(page, 'Third.png').waitFor();
    assert.equal(await tile(page, 'Third.png').locator('[data-dam-strip-viewing]').count(), 1);
    assert.deepEqual(page.problems, []);
}));

browserTest('"Open Files tab" names the stacked file on show, so the Files tab can pick it out; on the cover it is the plain tab link', () => withBrowser(async (browser) => {
    const page = await mountViewer(browser);
    await page.evaluate(() => { window.modal.previewData.filesUrl = '/admin/dam/assets/edit/7?files'; window.modal.openPreview(); });
    await tile(page, 'Second.png').waitFor();
    assert.equal(await page.evaluate(() => window.modal.filesTabHref()), '/admin/dam/assets/edit/7?files');
    await tile(page, 'Third.png').click();
    assert.equal(await page.evaluate(() => window.modal.filesTabHref()), '/admin/dam/assets/edit/7?files&file=3');
    await tile(page, 'Cover.png').click();
    assert.equal(await page.evaluate(() => window.modal.filesTabHref()), '/admin/dam/assets/edit/7?files');
    assert.deepEqual(page.problems, []);
}));

const inlineTile = (page, name) => page.locator(`[data-dam-inline-stack] [data-dam-strip-item][aria-label="View ${name}"]`);

browserTest('the inline Preview tab has the stack strip: a click shows that file inline, and the page is told which file is on show', () => withBrowser(async (browser) => {
    const page = await mountViewer(browser, {inline: true});
    await inlineTile(page, 'Second.png').waitFor();
    assert.equal(await page.locator('[data-dam-inline-stack] [data-dam-strip-item]').count(), 3);
    assert.equal(await page.evaluate(() => window.modal.isOpen), false, 'the viewer is not what shows the file');

    await page.evaluate(() => { window.invalidated = 0; window.modal.panoramaInvalidate = () => { window.invalidated += 1; }; });
    await inlineTile(page, 'Second.png').click();
    assert.equal(await page.evaluate(() => window.invalidated), 1, 'switching to another file left the panorama requests of the last view running');
    assert.equal(await page.evaluate(() => window.modal.previewData.variantFileId), 2);
    assert.equal(await page.locator('[data-preview-frame="image"] v-zoomable-image').getAttribute('src'), '/f/2.svg', 'the inline card still shows the cover');
    assert.equal(await inlineTile(page, 'Second.png').locator('[data-dam-strip-viewing]').count(), 1);
    const shown = await page.evaluate(() => window.emitted.filter(([type]) => type === 'dam:stack-file-shown').map(([, payload]) => payload));
    assert.deepEqual(shown.map((payload) => [payload.assetId, payload.file?.name, payload.file?.mime]), [[7, 'Second.png', 'image/png']]);

    // The card's header, its information popover and the information dialog all name the file on show, not the cover.
    const coverName = await page.evaluate(() => window.modal.previewData.file_name);
    assert.notEqual(coverName, 'Second.png');
    assert.match(await page.locator('[data-dam-preview-card] nav[aria-label]').first().textContent(), /Second\.png/);
    await page.locator('[data-dam-info-trigger]').hover();
    const popover = await page.locator('[data-dam-info-popover]').textContent();
    assert.match(popover, /Second\.png/);
    assert.ok(!popover.includes(coverName), 'the popover named the cover');
    await page.evaluate(() => Object.assign(window.modal.previewData, {created_at: '01 Sep 2026', updated_at: '02 Sep 2026'}));
    await page.locator('[data-dam-info-trigger]').click();
    const dialog = page.locator('[class~="z-[10010]"]').first();
    await dialog.waitFor();
    const dialogText = await dialog.textContent();
    assert.match(dialogText, /Second\.png/);
    assert.ok(!dialogText.includes(coverName), 'the information dialog named the cover');
    assert.match(dialogText, /\(stack\)/, 'the dates are not marked as the stack\'s');
    await page.evaluate(() => { window.modal.isInfoOpen = false; });

    // The viewer opened over it carries its own strip and the same file; closing it leaves the file on show inline.
    await page.evaluate(() => window.modal.openPreview());
    assert.equal(await page.locator('[data-dam-inline-stack]').count(), 0);
    await tile(page, 'Second.png').locator('[data-dam-strip-viewing]').waitFor();
    await page.evaluate(() => window.modal.closePreview());
    await inlineTile(page, 'Second.png').locator('[data-dam-strip-viewing]').waitFor();

    await inlineTile(page, 'Cover.png').click();
    assert.equal(await page.evaluate(() => window.modal.previewData.variantFileId), undefined);
    assert.equal(await page.evaluate(() => window.emitted.filter(([type]) => type === 'dam:stack-file-shown').pop()[1].file), null);
    assert.deepEqual(page.problems, []);
}));

browserTest('a role that may not list the stack\'s files gets the strip\'s note on the inline Preview tab, and a phone behaves as the viewer does', () => withBrowser(async (browser) => {
    const denied = await mountViewer(browser, {inline: true, canList: false});
    await denied.locator('[data-dam-inline-stack] [data-dam-strip-no-access]').waitFor();
    assert.equal(await denied.locator('[data-dam-strip-item]').count(), 0);
    assert.deepEqual(denied.problems, []);

    // Below 768px an open Info pane would cover the file just chosen, so choosing one closes it for this view only.
    const phone = await mountViewer(browser, {inline: true, viewport: {width: 375, height: 800}, infoOpen: true});
    await phone.evaluate(() => localStorage.setItem('dam_preview_info_open', 'true'));
    await inlineTile(phone, 'Third.png').click();
    assert.equal(await phone.evaluate(() => window.modal.previewData.variantFileId), 3);
    assert.equal(await phone.evaluate(() => window.modal.isInfoPanelOpen), false);
    assert.equal(await phone.evaluate(() => localStorage.getItem('dam_preview_info_open')), 'true', 'the saved choice must not change');
    assert.deepEqual(phone.problems, []);
}));

browserTest('the "Playing:" label shows for video and audio, never for a still image', () => withBrowser(async (browser) => {
    const page = await mountViewer(browser);
    await page.evaluate(() => { window.modal.previewData.playbackLabel = 'Original'; window.modal.openPreview(); });
    await tile(page, 'Second.png').waitFor();
    assert.equal(await page.locator('[data-dam-playback-label]').count(), 0, 'a still image says it is playing');
    for (const kind of ['video', 'audio', 'pano360-video']) {
        await page.evaluate((playbackKind) => { window.modal.previewData = {...window.modal.previewData, playbackKind}; }, kind);
        assert.equal(await page.locator('[data-dam-playback-label]').count(), 1, `${kind} lost its label`);
    }
    assert.deepEqual(page.problems, []);
}));

browserTest('below 768px a strip choice closes the Info pane for that view without changing the saved choice; on desktop it stays open', () => withBrowser(async (browser) => {
    for (const [width, expectOpen] of [[375, false], [1280, true]]) {
        const page = await mountViewer(browser, {viewport: {width, height: 800}, infoOpen: true});
        await page.evaluate(() => { localStorage.setItem('dam_preview_info_open', 'true'); window.modal.openPreview(); });
        await tile(page, 'Second.png').waitFor();
        await tile(page, 'Second.png').click();
        assert.equal(await page.evaluate(() => window.modal.previewData.variantFileId), 2);
        assert.equal(await page.evaluate(() => window.modal.isInfoPanelOpen), expectOpen, `at ${width}px`);
        assert.equal(await page.locator('[data-dam-info-panel]').isVisible(), expectOpen, `at ${width}px`);
        assert.equal(await page.evaluate(() => localStorage.getItem('dam_preview_info_open')), 'true', 'the saved choice must not change');
        // Closing the viewer gives the pane back as the person left it, so the next open is not silently different.
        await page.evaluate(() => window.modal.closePreview());
        assert.equal(await page.evaluate(() => window.modal.isInfoPanelOpen), true, `at ${width}px, after closing`);
        assert.deepEqual(page.problems, []);
        await page.close();
    }
}));

browserTest('a role that may not list a stack\'s files is told so, in place of the strip', () => withBrowser(async (browser) => {
    const page = await mountViewer(browser, {canList: false});
    await page.evaluate(() => window.modal.openPreview());
    const note = page.locator('[data-dam-strip-no-access]');
    await note.waitFor();
    assert.equal((await note.textContent()).trim(), "You don't have access to this stack's files. Ask an admin.");
    assert.equal(await page.locator('[data-dam-strip-item]').count(), 0);
    assert.deepEqual(page.problems, []);
}));

// --- Rename with AI on the details page ---------------------------------------------------------------------------

const aiFile = {id: 5, file_name: 'Welcome-Banner.jpg'};

/** The details page's rename component (real script, only its Rename with AI button as markup) beside the real AI rename dialog. */
async function mountRename(browser, {available = true, eligibility, rename = 'changed'} = {}) {
    const page = await newPage(browser, {width: 1280, height: 900});
    // Keep the real checkbox's screen-reader-only geometry, without loading unrelated shell styles.
    // The manifest key is the CSS entry declared by packages/Mothership/Admin/vite.config.js.
    const adminBuild = path.join(root, 'public/themes/admin/default/build');
    const manifest = JSON.parse(fs.readFileSync(path.join(adminBuild, 'manifest.json'), 'utf8'));
    const css = fs.readFileSync(path.join(adminBuild, manifest['src/Resources/assets/css/app.css'].file), 'utf8');
    const srOnly = css.match(/\.sr-only\{[^}]+\}/);
    assert.ok(srOnly, 'the shipped screen-reader checkbox style is missing');
    await page.addStyleTag({content: srOnly[0]});
    const edit = read('asset/edit.blade.php');
    const start = edit.indexOf("app.component('v-rename-asset'");
    const renameScript = replaceCalls(edit.slice(start, edit.indexOf('</script>', start)), (expression) => {
        if (expression.includes('$aiRenameBase')) return String(available);
        if (expression.includes('display_name')) return '"Welcome"';
        if (expression.includes('previewPath')) return '"/thumb.jpg"';
        if (expression.includes('$asset')) return JSON.stringify(aiFile);
        return '"x"';
    }).replace(/\{\{[^}]*\}\}/g, '/rename');
    const button = markup('<script id="b">' + /<button[^>]*?v-if="canRenameWithAi"[\s\S]*?<\/button>/.exec(edit)[0] + '</script>', 'b');

    const modalSource = read('components/explorer/ai-rename-modal.blade.php');
    const modalMarkup = markup(modalSource, 'v-dam-ai-rename-modal-template')
        .replace(/<x-admin::modal([^>]*)>/, '<fake-modal ref="aiRenameModal" @close="run += 1" @toggle="onToggle">').replace('</x-admin::modal>', '</fake-modal>')
        .replace(/<x-slot:(header|content)>/g, '<template #$1>').replace(/<\/x-slot>/g, '</template>');
    const modalStart = modalSource.lastIndexOf('<script type="module">') + '<script type="module">'.length;
    const modalScript = replaceCalls(modalSource.slice(modalStart).replace(/<\/script>[\s\S]*$/, ''), (expression) => {
        const route = /route\('([^']+)'/.exec(expression);
        if (route) return JSON.stringify({'admin.dam.assets.ai-tags.eligibility': '/ai/eligibility', 'admin.dam.assets.ai-rename.members': '/ai/members', 'admin.dam.assets.ai-rename.apply-member': '/rename', 'admin.dam.assets.ai-rename.suggest': '/ai/suggest', 'admin.dam.assets.rename': '/rename', 'admin.dam.assets.ai-tags.describe': '/ai/describe/__id__', 'admin.dam.assets.ai-tags.show': '/ai/show/__id__'}[route[1]]);
        return trans(expression) || '"x"';
    });

    await page.evaluate(() => { window.definitions = {}; window.app = {component: (name, definition) => { window.definitions[name] = definition; }}; });
    await page.addScriptTag({content: renameScript});
    await page.addScriptTag({content: modalScript});
    await page.evaluate(({button, modalMarkup, eligibility, rename}) => {
        const listeners = {};
        const emitter = {on: (type, fn) => (listeners[type] ||= []).push(fn), off(type, fn) { listeners[type] = (listeners[type] || []).filter((other) => other !== fn); }, emit: (type, payload) => (listeners[type] || []).slice().forEach((fn) => fn(payload))};
        window.calls = {posts: [], gets: [], navigated: [], emitted: []};
        window.emitter = emitter;
        const original = emitter.emit;
        emitter.emit = (type, payload) => { window.calls.emitted.push([type, payload]); original(type, payload); };
        const app = Vue.createApp({template: '<div><v-rename-asset></v-rename-asset><v-dam-ai-rename-modal></v-dam-ai-rename-modal></div>'});
        app.component('v-rename-asset', {...window.definitions['v-rename-asset'], template: button});
        app.component('v-dam-ai-rename-modal', {...window.definitions['v-dam-ai-rename-modal'], template: modalMarkup});
        app.component('fake-modal', {template: '<div><slot name="header"></slot><slot name="content"></slot></div>', methods: {open() {}, close() {}}});
        app.config.globalProperties.$emitter = emitter;
        app.config.globalProperties.$navigate = (url) => window.calls.navigated.push(url);
        app.config.globalProperties.$axios = {
            post: async (url, body) => {
                window.calls.posts.push([url, body]);
                if (url === '/ai/members') return {data: {rows: [{asset_id: 5, attachment_id: 15, file_id: 25, file_name: 'Welcome-Banner.jpg', extension: 'jpg', is_representative: true, eligibility: eligibility?.[5] || {state: 'eligible'}}]}};
                if (url === '/ai/eligibility') return {data: {available: true, eligibility: eligibility || {5: {can_rename: true}}}};
                if (url === '/ai/suggest') return {data: {file_name: 'Welcome-Banner.jpg', stem: '2026-09-14_welcome-week', title: 'Welcome Week', event: '', captured_at: '', date_source: 'exif', extension: 'jpg'}};
                if (url === '/rename') {
                    if (rename === 'failed') { const error = new Error('500'); error.response = {status: 500, data: {message: 'Rename refused'}}; throw error; }
                    if (rename === 'unchanged') return {data: {status: 'unchanged', file_name: 'Welcome-Banner.jpg'}};
                    // The server's answer carries the name it applied: the new one, or the old one for a title-only change.
                    return {data: {status: 'renamed', file_name: rename === 'titled' ? 'Welcome-Banner.jpg' : body.file_name}};
                }
                return {data: {}};
            },
            get: async (url) => { window.calls.gets.push(url); return {data: {ai: {caption: 'x'}}}; },
        };
        window.root = app.mount('#app');
    }, {button, modalMarkup, eligibility, rename});
    return page;
}

browserTest('the details page offers Rename with AI through the Library\'s dialog, which asks the server about this file', () => withBrowser(async (browser) => {
    const page = await mountRename(browser);
    const button = page.locator('[data-dam-rename-with-ai]');
    await button.waitFor();
    assert.match(await button.textContent(), /Rename with AI/);

    await button.click();
    const opened = await page.evaluate(() => window.calls.emitted.find(([type]) => type === 'dam:open-ai-rename')[1]);
    assert.deepEqual(opened.assets, [{id: 5, name: 'Welcome-Banner.jpg', thumb: '/thumb.jpg', known: true}]);
    assert.equal(opened.context, 'details:5');
    await page.waitForFunction(() => window.calls.posts.some(([url]) => url === '/ai/suggest'));
    assert.deepEqual(await page.evaluate(() => window.calls.posts.find(([url]) => url === '/ai/members')), ['/ai/members', {asset_ids: [5]}]);
    assert.deepEqual(page.problems, []);
}));

browserTest('Rename with AI is not offered when the AI is not configured, and a file the AI cannot take is explained in the dialog', () => withBrowser(async (browser) => {
    const none = await mountRename(browser, {available: false});
    assert.equal(await none.locator('[data-dam-rename-with-ai]').count(), 0);

    const page = await mountRename(browser, {eligibility: {5: {can_rename: false, state: 'unsupported', message: 'AI cannot read this type'}}});
    await page.locator('[data-dam-rename-with-ai]').click();
    await page.locator('[data-dam-ai-rename-skipped]').waitFor();
    assert.match(await page.locator('[data-dam-ai-rename-skipped]').textContent(), /AI cannot read this type/);
    assert.equal(await page.evaluate(() => window.calls.posts.some(([url]) => url === '/ai/suggest')), false, 'a refused file was still sent for a suggestion');
    assert.equal(await page.locator('[data-dam-ai-rename-apply]').isDisabled(), true);
}));

// The real dialog's apply(), with the page's reload rule: it reloads for a rename or a title-only change, never for nothing.
for (const [rename, expected] of [
    ['changed', {reloads: 1, done: 1, titled: 0, flashes: ['success']}],
    ['unchanged', {reloads: 0, done: 0, titled: 0, flashes: []}],
    ['titled', {reloads: 1, done: 0, titled: 1, flashes: ['success']}],
    ['failed', {reloads: 0, done: 0, titled: 0, flashes: ['warning']}],
]) {
    browserTest(`applying an AI name on the details page, server answer "${rename}": ${expected.reloads ? 'reloads the page' : 'leaves the page as it is'}`, () => withBrowser(async (browser) => {
        const page = await mountRename(browser, {rename});
        await page.locator('[data-dam-rename-with-ai]').click();
        // Wait for the suggestion to render, then select the screen-reader checkbox by keyboard.
        // A forced coordinate click skips stability while the row's contents are still changing.
        await page.locator('[data-dam-ai-rename-row] input[type=text]').waitFor({state: 'visible'});
        const checkbox = page.locator('[data-dam-ai-rename-row] input[type=checkbox]');
        assert.equal(await checkbox.isChecked(), false, 'a descriptive name should start unticked');
        await checkbox.press('Space');
        assert.equal(await checkbox.isChecked(), true, 'keyboard selection did not tick the proposal');
        await page.waitForFunction(() => !document.querySelector('[data-dam-ai-rename-apply]').disabled);
        await page.locator('[data-dam-ai-rename-apply]').click();
        await page.waitForFunction(() => window.calls.emitted.some(([type]) => type === 'dam:ai-rename-applied'));

        const applied = await page.evaluate(() => ({...window.calls.emitted.find(([type]) => type === 'dam:ai-rename-applied')[1]}));
        assert.deepEqual([applied.done, applied.titled], [expected.done, expected.titled]);
        assert.equal(await page.evaluate(() => window.calls.navigated.length), expected.reloads);
        const flashes = await page.evaluate(() => window.calls.emitted.filter(([type]) => type === 'add-flash').map(([, flash]) => [flash.type, flash.message]));
        assert.deepEqual(flashes.map(([type]) => type), expected.flashes);
        if (rename === 'failed') await page.locator('[data-dam-ai-rename-row]').getByText('Rename refused').waitFor();
        assert.deepEqual(page.problems, []);
    }));
}

// --- A cover's panorama poll landing while an alternate file is on screen -------------------------------------------

function panoramaMethods() {
    const vm = require('node:vm');
    const source = read('asset/preview-modal/pano360/viewer-script.blade.php');
    const script = replaceCalls(source.slice(source.indexOf('>') + 1, source.lastIndexOf('</script>')), (expression) => trans(expression) || '"x"').replace(/\{\{[^}]*\}\}/g, 'x');
    const context = {window: {}, document: {}, console, clearTimeout, setTimeout};
    vm.createContext(context);
    vm.runInContext(script, context);
    return Object.assign({}, context.window._damPanoViewer.methods, {__window: context.window, __computed: context.window._damPanoViewer.computed});
}

test('a panorama poll answering while an alternate file is shown updates the cover only, never the alternate\'s fields', () => {
    const {applyPanoramaState} = panoramaMethods();
    const cover = {id: 7, variantFileId: undefined, playbackUrl: '/cover-flat.mp4', playbackKind: 'video', playbackMime: 'video/mp4', playbackLabel: 'Original'};
    const alternate = {...cover, variantFileId: 2, variantName: 'Second.png', playbackUrl: '/f/2.svg', playbackKind: 'image', playbackMime: null, playbackLabel: null};
    const answer = {rendition: {status: 'done'}, playback: {url: '/cover-pano.mp4', kind: 'pano360-video', mime: 'video/mp4', label: 'Panorama'}};
    const host = (previewData) => {
        const calls = [];
        return {calls, state: {previewData, _coverPreviewData: {...cover}, panoramaState: null, panoramaRejectedUrl: null, panoDestroy: () => calls.push('panoDestroy'), panoInit: () => calls.push('panoInit'), schedulePanoramaPoll: () => calls.push('schedule'), $nextTick: (fn) => fn()}};
    };

    const viewing = host({...alternate});
    applyPanoramaState.call(viewing.state, answer);
    assert.equal(viewing.state.previewData.playbackUrl, '/f/2.svg', 'the cover\'s source was written under the alternate\'s name');
    assert.equal(viewing.state.previewData.playbackKind, 'image');
    assert.equal(viewing.state._coverPreviewData.playbackUrl, '/cover-pano.mp4', 'the cover did not take the new source for when it is shown again');
    assert.equal(viewing.state._coverPreviewData.playbackKind, 'pano360-video');
    assert.deepEqual(viewing.calls, ['schedule'], 'the player of the file on screen was rebuilt');

    // Control: with the cover itself on screen the poll still updates it and rebuilds the player.
    const onCover = host({...cover});
    applyPanoramaState.call(onCover.state, answer);
    assert.equal(onCover.state.previewData.playbackUrl, '/cover-pano.mp4');
    assert.deepEqual(onCover.calls, ['panoDestroy', 'panoInit', 'schedule']);
    assert.equal(onCover.state._coverPreviewData.playbackUrl, '/cover-pano.mp4', 'switching to a stacked file and back would restore the stale source');
    assert.equal(onCover.state._coverPreviewData.playbackKind, 'pano360-video');
});

test('a panorama poll or build request that answers after the viewer moved to another asset is dropped, not applied to it', async () => {
    const methods = panoramaMethods();
    const deferred = () => { const d = {}; d.promise = new Promise((resolve, reject) => Object.assign(d, {resolve, reject})); return d; };
    const asset = (id) => ({id, playbackUrl: null, playbackKind: null, playbackMime: null, playbackLabel: null});
    const answer = {rendition: {status: 'done'}, playback: {url: '/a-pano.mp4', kind: 'pano360-video', mime: 'video/mp4', label: 'Panorama'}};

    const mount = () => {
        const requests = [];
        methods.__window.axios = {
            get: (url) => { const d = deferred(); requests.push({url, d}); return d.promise; },
            post: (url) => { const d = deferred(); requests.push({url, d}); return d.promise; },
        };
        const calls = [];
        const state = {
            ...methods, previewData: asset('A'), panoramaUrls: {state: '/a/state', build: '/a/build'}, panoramaState: null, panoramaRejectedUrl: null,
            panoramaPollAttempt: 0, panoramaPollTimer: null, panoramaBusy: false, panoramaError: null, panoramaLoadFailed: false,
            panoDestroy: () => calls.push('panoDestroy'), panoInit: () => calls.push('panoInit'), $nextTick: (fn) => fn(),
        };
        state._coverPreviewData = {...state.previewData};
        // What onDamAssetChanged does when previous/next lands on B.
        state.moveToB = () => {
            state.panoramaInvalidate();
            state.previewData = asset('B');
            state._coverPreviewData = {...state.previewData};
            state.panoramaUrls = {state: '/b/state', build: '/b/build'};
            state.panoramaState = {rendition: {status: 'none'}};
            state.panoramaError = null;
            state.panoramaPollAttempt = 0;
        };
        return {state, requests, calls};
    };

    // A's poll answers after B is on screen: nothing of it reaches B or B's saved cover.
    let { state, requests, calls } = mount();
    const poll = state.loadPanoramaState();
    state.moveToB();
    requests[0].d.resolve({data: answer});
    await poll;
    assert.equal(state.previewData.playbackUrl, null, 'A\'s playback source was applied to B');
    assert.equal(state._coverPreviewData.playbackUrl, null, 'A\'s playback source was written into B\'s saved cover');
    assert.deepEqual(state.panoramaState, {rendition: {status: 'none'}});
    assert.deepEqual(calls, []);
    assert.equal(state.panoramaPollTimer, null, 'A\'s answer re-armed the poll');

    // A's poll fails after B is on screen: no retry timer, no error shown for B.
    ({ state, requests } = mount());
    const failing = state.loadPanoramaState();
    state.moveToB();
    requests[0].d.reject(Object.assign(new Error('500'), {response: {status: 401, data: {message: 'Session expired'}}}));
    await failing;
    assert.equal(state.panoramaError, null, 'A\'s failure was shown on B');
    assert.equal(state.panoramaPollAttempt, 0);
    assert.equal(state.panoramaPollTimer, null);

    // A build request for A answers after B is on screen: B's rendition is untouched and B's buttons are not unlocked early.
    ({ state, requests } = mount());
    const build = state.panoramaRequest('build');
    assert.equal(state.panoramaBusy, true);
    state.moveToB();
    state.panoramaBusy = true; // B's own request is in flight
    requests[0].d.resolve({data: {rendition: {status: 'queued'}}});
    await build;
    assert.deepEqual(state.panoramaState, {rendition: {status: 'none'}}, 'A\'s rendition was merged into B');
    assert.equal(requests.length, 1, 'A\'s follow-up read ran against B');
    assert.equal(state.panoramaBusy, true);

    // Control: with no navigation in between, the same answer is applied to the asset it was for.
    ({ state, requests, calls } = mount());
    const same = state.loadPanoramaState();
    requests[0].d.resolve({data: answer});
    await same;
    assert.equal(state.previewData.playbackUrl, '/a-pano.mp4');
    assert.equal(state._coverPreviewData.playbackUrl, '/a-pano.mp4');
    assert.deepEqual(calls, ['panoDestroy', 'panoInit']);
});


test('a panorama start queued before the viewer changed file or entry does not run, and panoInit re-checks after its own wait', async () => {
    const methods = panoramaMethods();
    const answer = {rendition: {status: 'done'}, playback: {url: '/a-pano.mp4', kind: 'pano360-video', mime: 'video/mp4', label: 'Panorama'}};
    const ticks = [];
    const calls = [];
    const state = {
        ...methods, previewData: {id: 7, playbackUrl: null, playbackKind: null}, panoramaState: null, panoramaRejectedUrl: null,
        panoDestroy: (...args) => calls.push(['panoDestroy', ...args]), panoInit: () => calls.push(['panoInit']), schedulePanoramaPoll: () => {},
        $nextTick: (fn) => { ticks.push(fn); },
    };
    state._coverPreviewData = {...state.previewData};

    // applyPanoramaState queues panoInit for the next tick; a switch (which bumps the generation) before it flushes cancels it.
    state.applyPanoramaState(answer);
    state.panoramaInvalidate();
    ticks.splice(0).forEach((fn) => fn());
    assert.deepEqual(calls.filter(([name]) => name === 'panoInit'), [], 'a start queued for the previous view ran');

    // Control: without a switch the queued start runs.
    state.previewData = {id: 7, playbackUrl: null, playbackKind: null};
    state.applyPanoramaState(answer);
    ticks.splice(0).forEach((fn) => fn());
    assert.deepEqual(calls.filter(([name]) => name === 'panoInit'), [['panoInit']]);

    // panoInit waits for the renderer and then a tick; if the file changed meanwhile it must stop before it touches the canvas.
    const initCalls = [];
    methods.__window.loadDamThree = async () => ({});
    const init = {
        ...methods, previewData: {id: 7, playbackKind: 'pano360-video', playbackUrl: '/p.mp4'}, panoFlatView: false, _panoToken: 0, panoLoading: false,
        panoramaVerifyDirectPlayback: () => true, panoDestroy: () => initCalls.push('panoDestroy'),
        $refs: {panoCanvas: {}, panoContainer: {}, videoEl: {}}, $nextTick: () => new Promise((resolve) => { init.release = resolve; }),
    };
    const running = init.panoInit();
    await new Promise((resolve) => setImmediate(resolve));
    init.previewData = {id: 7, playbackKind: 'image', playbackUrl: '/f/2.svg'};
    init.release();
    await running;
    assert.deepEqual(initCalls, [], 'panoInit built a player for a view that was no longer a panorama');
});

browserTest('a single-file entry gets neither a strip nor a no-access note, whatever the role', () => withBrowser(async (browser) => {
    const page = await mountViewer(browser, {canList: false, count: 1});
    await page.evaluate(() => window.modal.openPreview());
    assert.equal(await page.locator('[data-dam-strip-no-access], [data-dam-strip-item]').count(), 0);
}));


browserTest('the details page treats an older payload with no `titled` as 0, and ignores another page\'s dialog', () => withBrowser(async (browser) => {
    const page = await mountRename(browser);
    const reloads = async (payload) => {
        const before = await page.evaluate(() => window.calls.navigated.length);
        await page.evaluate((payload) => window.emitter.emit('dam:ai-rename-applied', payload), payload);
        return (await page.evaluate(() => window.calls.navigated.length)) - before;
    };
    assert.equal(await reloads({context: 'details:5', done: 1}), 1);
    assert.equal(await reloads({context: 'details:5', done: 0}), 0, 'a missing `titled` must read as 0');
    assert.equal(await reloads({context: 'details:6', done: 1, titled: 1}), 0, 'another page\'s context reloaded this one');
}));

// --- Actions on the cover while another of the stack's files is shown inline ---------------------------------------

const editImageButton = (page) => page.locator('button:has(.icon-edit):not([data-dam-edit-thumbnail])');
const editThumbnailButton = (page) => page.locator('button[data-dam-edit-thumbnail]');

browserTest('while a stacked file the server did not mark editable is shown inline, Edit image, the editor and the cover\'s panorama controls step aside, and choosing the cover (mouse or Enter) restores them', () => withBrowser(async (browser) => {
    const page = await mountViewer(browser, {inline: true, editor: true});
    await page.evaluate(() => { window.editorLoads = 0; window.loadDamImageEditor = async () => { window.editorLoads += 1; return {mountEditor: () => () => {}}; }; });
    await inlineTile(page, 'Second.png').waitFor();
    assert.equal(await editImageButton(page).count(), 1, 'the cover has Edit image');
    await inlineTile(page, 'Second.png').click();
    assert.equal(await editImageButton(page).count(), 0, 'Edit image was offered for a file not marked editable');

    // Even if something sets the flag, the editor never mounts over another file.
    await page.evaluate(() => { window.modal.isEditOpen = true; });
    await page.waitForFunction(() => window.modal.isEditOpen === false);
    assert.equal(await page.evaluate(() => window.editorLoads), 0);
    assert.equal(await page.locator('[ref="imageEditorHost"]').count(), 0);

    // The cover tile from the keyboard.
    await inlineTile(page, 'Cover.png').focus();
    await page.keyboard.press('Enter');
    assert.equal(await page.evaluate(() => window.modal.previewData.variantFileId), undefined);
    assert.equal(await editImageButton(page).count(), 1);
    assert.equal(await editThumbnailButton(page).count(), 0, 'Edit thumbnail was offered beside an editable original');

    // A cover whose original is not edited (a 3D model, PDF or video) offers Edit thumbnail instead, the same way:
    // only on the cover, never over another stacked file.
    await page.evaluate((payload) => window.modal.onDamAssetChanged(payload), {...showPayload, imageEditing: {canEditOriginal: false, canEditThumbnail: true, thumbnailEditUrl: '/thumb?v=1'}});
    await inlineTile(page, 'Second.png').waitFor();
    assert.equal(await editThumbnailButton(page).count(), 1, 'the cover has Edit thumbnail');
    assert.equal(await editImageButton(page).count(), 0, 'Edit image was offered for an original that is not edited');
    await inlineTile(page, 'Second.png').click();
    assert.equal(await editThumbnailButton(page).count(), 0, 'Edit thumbnail was offered for another file');
    await inlineTile(page, 'Cover.png').click();
    assert.equal(await editThumbnailButton(page).count(), 1);
    assert.deepEqual(page.problems, []);
}));

browserTest('a stacked PNG the user may edit offers Edit image, and the editor opens on that file with its slot on every request', () => withBrowser(async (browser) => {
    const page = await mountViewer(browser, {inline: true, editor: true});
    await page.evaluate(() => { window.mounts = []; window.loadDamImageEditor = async () => ({mountEditor: (host, options) => { window.mounts.push({assetId: options.assetId, fileName: options.fileName, slotted: !! options.http}); return () => {}; }}); });
    await page.evaluate((payload) => window.modal.onDamAssetChanged(payload), {...showPayload, imageEditing: {...showPayload.imageEditing, mayUseEditor: true}});
    await inlineTile(page, 'Second.png').waitFor();
    await inlineTile(page, 'Second.png').click();
    assert.equal(await page.evaluate(() => window.modal.previewData.variantFileId), 2);
    assert.equal(await editImageButton(page).count(), 1, 'Edit image was not offered for an editable stacked file');
    await editImageButton(page).click();
    await page.waitForFunction(() => window.mounts.length === 1);
    assert.deepEqual(await page.evaluate(() => window.mounts), [{assetId: 7, fileName: 'Second.png', slotted: true}]);
    // Edit thumbnail stays the cover's alone.
    assert.equal(await editThumbnailButton(page).count(), 0);
    assert.deepEqual(page.problems, []);
}));

test('the cover\'s panorama controls are not shown over another stacked file, and come back on the cover', () => {
    const computed = panoramaMethods().__computed;
    const state = (previewData) => ({previewData, panoramaState: {classification: {is_panorama: true}}, panoramaMode: 'auto'});

    assert.equal(computed.panoramaVisible.call(state({id: 7})), true);
    assert.equal(computed.panoramaVisible.call(state({id: 7, variantFileId: 2})), false);
    assert.equal(computed.panoramaVisible.call({...state({id: 7, variantFileId: 2}), panoramaMode: 'never'}), false, 'an override control stayed on show');
});

// --- A cover's own pictures and path never describe another stacked file of the same type -------------------------------

for (const [kind, cover, alternate, cardSelector, coverPicture] of [
    ['PDF', {file_type: 'document', extension: 'pdf', extension_upper: 'PDF', mime_type: 'application/pdf', pdfThumbnailUrl: '/cover-page.png', path: 'assets/cover.pdf'},
        {id: 9, asset_id: 7, label: 'Other.pdf', role: 'alternate', mime_type: 'application/pdf', extension: 'pdf', url: '/f/9.pdf', size: 2048}, 'img[src="/cover-page.png"]', 'pdfThumbnailUrl'],
    ['model', {file_type: 'document', extension: 'glb', extension_upper: 'GLB', mime_type: 'model/gltf-binary', modelThumbnailUrl: '/cover-model.png', path: 'assets/cover.glb'},
        {id: 9, asset_id: 7, label: 'Other.glb', role: 'alternate', mime_type: 'model/gltf-binary', extension: 'glb', url: '/f/9.glb', size: 2048}, '[data-dam-model-thumbnail]', 'modelThumbnailUrl'],
]) {
    browserTest(`a ${kind} shown beside a ${kind} cover keeps the cover's picture and path off the file on show`, () => withBrowser(async (browser) => {
        const page = await mountViewer(browser, {inline: true});
        await page.evaluate((cover) => { Object.assign(window.modal.previewData, cover); window.modal.rememberStackCover(); }, cover);
        await page.waitForSelector('[data-dam-inline-stack]');
        if (kind === 'model') assert.equal(await page.locator(cardSelector).count(), 1, 'the cover\'s own picture should show on the cover');

        await page.evaluate((file) => window.modal.switchStackedFile(file), alternate);
        const shown = await page.evaluate((key) => ({picture: window.modal.previewData[key], path: window.modal.previewData.path, name: window.modal.previewData.variantName, canSetThumbnail: window.modal.previewData.canSetThumbnail}), coverPicture);
        assert.equal(shown.picture, null, 'the cover\'s picture stayed on the previewData of the other file');
        assert.equal(shown.path, '');
        assert.equal(shown.canSetThumbnail, false);
        assert.equal(await page.locator(cardSelector).count(), 0, 'the cover\'s picture is on screen under the other file');

        // The information dialog names the file on show and has no folder path of the cover's.
        await page.locator('[data-dam-info-trigger]').click();
        const dialogText = await page.locator('[class~="z-[10010]"]').first().textContent();
        assert.match(dialogText, new RegExp(shown.name.replace('.', '\\.')));
        assert.ok(!dialogText.includes(cover.path), 'the information dialog showed the cover\'s path');
        await page.evaluate(() => { window.modal.isInfoOpen = false; });

        // Back on the cover, its picture and path return.
        await inlineTile(page, 'Cover.png').click().catch(() => {});
        await page.evaluate(() => window.modal.switchStackedFile({id: 1, asset_id: 7, label: 'Cover.png', is_representative: true, role: 'primary', mime_type: 'image/png', extension: 'png', url: '/f/1.svg', size: 1}));
        assert.equal(await page.evaluate((key) => window.modal.previewData[key], coverPicture), cover[coverPicture]);
        assert.equal(await page.evaluate(() => window.modal.previewData.path), cover.path);
        assert.deepEqual(page.problems, []);
    }));
}

// --- Back to cover -----------------------------------------------------------------------------------------------

browserTest('"Back to cover" restores the cover and tells the page with file:null, even when the strip never loaded', () => withBrowser(async (browser) => {
    const page = await mountViewer(browser, {inline: true});
    await inlineTile(page, 'Second.png').waitFor();
    // The strip's list is out of reach; a stacked file is still on show (the host remembered the cover at open).
    await page.evaluate((file) => { window.listFails = true; window.modal.rememberStackCover(); window.modal.switchStackedFile(file); }, files[1]);
    assert.equal(await page.evaluate(() => window.modal.previewData.variantFileId), 2);

    await page.evaluate(() => window.emitter.emit('dam:stack-back-to-cover'));
    assert.equal(await page.evaluate(() => window.modal.previewData.variantFileId), undefined, 'the cover did not come back');
    assert.equal(await page.evaluate(() => window.modal.previewData.mediaUrl), '/f/1.svg');
    const last = await page.evaluate(() => window.emitted.filter(([type]) => type === 'dam:stack-file-shown').at(-1)[1]);
    assert.deepEqual(last, {assetId: 7, file: null});
    assert.deepEqual(page.problems, []);
}));

browserTest('a reorder that fails, and a refetch that fails after it, leave the strip\'s list as it was; Back to cover still restores everything', () => withBrowser(async (browser) => {
    const page = await mountViewer(browser, {inline: true});
    await inlineTile(page, 'Second.png').waitFor();
    await inlineTile(page, 'Second.png').click();
    assert.equal(await page.evaluate(() => window.modal.previewData.variantFileId), 2);

    await page.evaluate(async () => {
        const strip = document.querySelector('[data-dam-inline-stack] [data-dam-strip-item]').__vueParentComponent.proxy;
        strip.canEdit = true;
        window.listFails = true;
        await strip.move(0, 1);
    });
    assert.equal(await page.locator('[data-dam-inline-stack] [data-dam-strip-item]').count(), 3, 'a failed refetch emptied the strip');

    await page.evaluate(() => window.emitter.emit('dam:stack-back-to-cover'));
    assert.equal(await page.evaluate(() => window.modal.previewData.variantFileId), undefined);
    assert.equal(await page.evaluate(() => window.modal.previewData.mediaUrl), '/f/1.svg');
    assert.deepEqual(await page.evaluate(() => window.emitted.filter(([type]) => type === 'dam:stack-file-shown').at(-1)[1]), {assetId: 7, file: null});
    assert.equal(await page.locator('[data-dam-inline-stack] [data-dam-strip-item]').count(), 3);
    assert.deepEqual(page.problems, []);
}));

// --- The image editor loading while the viewer moves on ------------------------------------------------------------

browserTest('an image editor still loading when the viewer changes file or entry mounts nothing, and Edit image works again afterwards', () => withBrowser(async (browser) => {
    const page = await mountViewer(browser, {inline: true, editor: true});
    await inlineTile(page, 'Second.png').waitFor();
    await page.evaluate(() => {
        window.mounts = [];
        window.loads = [];
        window.loadDamImageEditor = () => new Promise((resolve) => window.loads.push(() => resolve({mountEditor: (host, options) => { window.mounts.push(options.assetId); return () => {}; }})));
    });
    const edit = editImageButton(page);
    await page.evaluate((payload) => { window.payloadFor7 = payload; }, {...showPayload, attachmentCount: 3});

    // 1) A switch to another stacked file while the bundle loads.
    await edit.click();
    await page.waitForFunction(() => window.loads.length === 1);
    await inlineTile(page, 'Second.png').click();
    assert.equal(await page.evaluate(() => window.modal.isEditOpen), false, 'the switch left the editor open');
    await page.evaluate(() => window.loads[0]());
    await page.evaluate(() => new Promise((resolve) => setTimeout(resolve, 50)));
    assert.deepEqual(await page.evaluate(() => window.mounts), [], 'the editor mounted over another file');
    assert.equal(await page.evaluate(() => window.modal.isEditOpen), false);

    // 2) Edit image works again on the cover, and that load mounts.
    await inlineTile(page, 'Cover.png').click();
    await edit.click();
    await page.waitForFunction(() => window.loads.length === 2);
    await page.evaluate(() => window.loads[1]());
    await page.waitForFunction(() => window.mounts.length === 1);
    assert.deepEqual(await page.evaluate(() => window.mounts), [7]);
    await page.evaluate(() => { window.modal.isEditOpen = false; });

    // 3) The page moved to another entry while the bundle loaded: nothing mounts, and the flag is reset so Edit image can reopen.
    await edit.click();
    await page.waitForFunction(() => window.loads.length === 3);
    await page.evaluate((payload) => window.modal.onDamAssetChanged(payload), {...showPayload, asset: {...showPayload.asset, id: 8}, attachmentCount: 3});
    await page.evaluate(() => window.loads[2]());
    await page.evaluate(() => new Promise((resolve) => setTimeout(resolve, 50)));
    assert.deepEqual(await page.evaluate(() => window.mounts), [7], 'the editor mounted for the entry that was left');
    assert.equal(await page.evaluate(() => window.modal.isEditOpen), false);
    await edit.click();
    await page.waitForFunction(() => window.loads.length === 4);
    await page.evaluate(() => window.loads[3]());
    await page.waitForFunction(() => window.mounts.length === 2);
    await page.evaluate(() => { window.modal.isEditOpen = false; });

    // 4) Whatever moved the viewer (not only the strip or the chevrons), the load re-checks the file and asset it was for.
    await page.evaluate(() => window.modal.onDamAssetChanged(window.payloadFor7));
    await page.waitForFunction(() => window.modal.previewData.id === 7);
    await edit.click();
    await page.waitForFunction(() => window.loads.length === 5);
    await page.evaluate(() => { window.modal.previewData = {...window.modal.previewData, id: 9}; });
    await page.evaluate(() => window.loads[4]());
    await page.evaluate(() => new Promise((resolve) => setTimeout(resolve, 50)));
    assert.equal(await page.evaluate(() => window.mounts.length), 2, 'the editor mounted for an asset that was no longer on screen');
    assert.equal(await page.evaluate(() => window.modal.isEditOpen), false, 'Edit image stayed stuck open');
    assert.deepEqual(page.problems, []);
}));

// --- Returning to the cover finds a panorama build that started before the switch -----------------------------------

/** The panorama viewer and the stack-switch mixin on one object (as the details page's preview component is), with deferred requests. */
function viewerWithStack() {
    const vm = require('node:vm');
    const pano = panoramaMethods();
    const shown = (() => {
        const context = {window: {}};
        vm.createContext(context);
        vm.runInContext(read('asset/stack-shown-script.blade.php').replace(/\{\{--[\s\S]*?--\}\}/g, '').replace(/<\/?script>/g, ''), context);
        return context.window._damShownStackFile;
    })();
    const stack = (() => {
        const context = {window: {MothershipNameTail: (name) => ({head: name, tail: ''})}};
        vm.createContext(context);
        const source = read('asset/preview-modal/attachments/strip-script.blade.php');
        vm.runInContext(source.match(/<script>([\s\S]*?)<\/script>/)[1].replace("@json(trans('dam::app.admin.dam.asset.files.roles'))", '{}'), context);
        return context.window._damStackSwitch;
    })();

    const deferred = () => { const d = {}; d.promise = new Promise((resolve, reject) => Object.assign(d, {resolve, reject})); return d; };
    const requests = [];
    pano.__window.axios = {
        get: (url) => { const d = deferred(); requests.push({method: 'get', url, d}); return d.promise; },
        post: (url) => { const d = deferred(); requests.push({method: 'post', url, d}); return d.promise; },
    };
    const emitted = [];
    const noop = () => {};
    const state = {
        ...pano, ...stack.methods, assertCoverShown: shown.methods.assertCoverShown,
        previewVariant: null, previewVariants: [], stackInline: true, isOpen: false, isInfoPanelOpen: false, isEditOpen: false,
        previewData: {id: 7, file_type: 'image', playbackUrl: '/f/1.svg', playbackKind: 'image'},
        panoramaUrls: {state: '/p/state', build: '/p/build'}, panoramaState: {rendition: {status: 'none'}}, panoramaRejectedUrl: null,
        panoramaPollAttempt: 0, panoramaPollTimer: null, panoramaBusy: false, panoramaError: null,
        videoStopOnClose: noop, audioStopOnClose: noop, pdfDestroy: noop, panoDestroy: noop, imgResetState: noop, videoResetState: noop, audioResetState: noop,
        videoInitEl: noop, audioInitEl: noop, panoInit: noop, autoplayMedia: noop,
        $emitter: {emit: (...args) => emitted.push(args)}, $nextTick: (fn) => fn(),
    };
    Object.defineProperty(state, 'stackFileShown', {get: () => !! state.previewData.variantFileId});
    const file = (id) => ({id, asset_id: 7, label: `F${id}.png`, role: 'alternate', is_representative: false, mime_type: 'image/png', url: `/f/${id}.svg`, size: 10});
    const reads = () => requests.filter((request) => request.method === 'get');
    const settle = () => new Promise((resolve) => setImmediate(resolve));
    return {state, requests, emitted, file, reads, settle};
}

test('a build started on the cover is found again when the cover returns after two stacked files (cover, A, B, cover)', async () => {
    const {state, requests, emitted, file, reads, settle} = viewerWithStack();

    state.rememberStackCover();
    // Build is pressed on the cover; its answer is still out when the viewer goes cover -> A -> B -> cover.
    const build = state.buildPanoramaPreview();
    assert.equal(requests.length, 1);
    state.switchStackedFile(file(2));
    state.switchStackedFile(file(3));
    assert.equal(state.previewData.variantFileId, 3);
    state.showStackCover();
    assert.equal(state.previewData.variantFileId, undefined);
    assert.equal(JSON.stringify(emitted.at(-1)), JSON.stringify(['dam:stack-file-shown', {assetId: 7, file: null}]));

    // Returning asked the server where the panorama stands; the late build answer itself is dropped.
    assert.equal(reads().length, 1, 'the cover came back without asking for its panorama state');
    requests[0].d.resolve({data: {rendition: {status: 'queued'}}});
    await build;
    reads()[0].d.resolve({data: {rendition: {status: 'running'}}});
    await settle();
    assert.equal(state.panoramaState.rendition.status, 'running', 'the running build was not found');
    assert.notEqual(state.panoramaPollTimer, null, 'the running build is not being polled');
    clearTimeout(state.panoramaPollTimer);

    // Showing another stacked file does not ask; only the cover does.
    const before = reads().length;
    state.switchStackedFile(file(2));
    assert.equal(reads().length, before);
});

test('the return read can answer "none" before the earlier Build commits: the viewer reads again once Build settles, and polls', async () => {
    const {state, requests, file, reads, settle} = viewerWithStack();

    state.rememberStackCover();
    const build = state.buildPanoramaPreview();
    state.switchStackedFile(file(2));
    state.switchStackedFile(file(3));
    state.showStackCover();
    assert.equal(reads().length, 1);

    // The return read answers first, from before the server committed the Build: still "none".
    reads()[0].d.resolve({data: {rendition: {status: 'none'}}});
    await settle();
    assert.equal(state.panoramaState.rendition.status, 'none');
    assert.equal(reads().length, 1, 'it read again before the Build had settled');

    // Build answers "queued": that reply is dropped (it belongs to a view that was left), but it triggers one more read.
    requests[0].d.resolve({data: {rendition: {status: 'queued'}}});
    await build;
    await settle();
    assert.equal(reads().length, 2, 'the viewer never read again after Build settled');
    reads()[1].d.resolve({data: {rendition: {status: 'queued'}}});
    await settle();
    assert.equal(state.panoramaState.rendition.status, 'queued', 'the queued build was not found');
    assert.notEqual(state.panoramaPollTimer, null, 'the queued build is not being polled');
    clearTimeout(state.panoramaPollTimer);
    assert.equal(reads().length, 2, 'the re-read looped');
});

test('a Build that settled before a stale return read answers still triggers one more read; a Build for another asset never does', async () => {
    let view = viewerWithStack();
    view.state.rememberStackCover();
    let build = view.state.buildPanoramaPreview();
    view.state.switchStackedFile(view.file(2));
    view.state.showStackCover();
    // Build commits and answers first; the read that was already out answers "none" afterwards.
    view.requests[0].d.resolve({data: {rendition: {status: 'queued'}}});
    await build;
    view.reads()[0].d.resolve({data: {rendition: {status: 'none'}}});
    await view.settle();
    assert.equal(view.reads().length, 2, 'a read answered from before the Build settled was trusted');

    // The viewer moved on to another asset before Build settled: its late answer never reads for the new asset.
    view = viewerWithStack();
    view.state.rememberStackCover();
    build = view.state.buildPanoramaPreview();
    view.state.switchStackedFile(view.file(2));
    view.state.showStackCover();
    view.state.panoramaInvalidate();
    view.state.previewData = {id: 8, file_type: 'image'};
    view.state._coverPreviewData = {...view.state.previewData};
    view.state.panoramaUrls = {state: '/b/state', build: '/b/build'};
    view.reads()[0].d.resolve({data: {rendition: {status: 'none'}}});
    // B's own read (answered before A's Build settles), then A's Build settles.
    const bRead = view.state.loadPanoramaState();
    view.reads()[1].d.resolve({data: {rendition: {status: 'none'}}});
    await bRead;
    view.requests[0].d.resolve({data: {rendition: {status: 'queued'}}});
    await build;
    await view.settle();
    assert.equal(view.reads().length, 2, 'a Build for the previous asset caused another read for the new one');
});

for (const order of ['A first', 'B first']) {
    test(`two Builds pending across A, B, A (${order}): A recovers to queued and polls, B's answer never reaches A, and nothing loops`, async () => {
        const {state, requests, reads, settle} = viewerWithStack();
        const moveTo = (id, urls) => {
            state.panoramaInvalidate();
            state.previewData = {id, file_type: 'image', playbackUrl: null, playbackKind: null};
            state._coverPreviewData = {...state.previewData};
            state.panoramaUrls = urls;
            state.panoramaState = {rendition: {status: 'none'}};
            state.panoResetState();
        };
        const urlsA = {state: '/a/state', build: '/a/build'};
        const urlsB = {state: '/b/state', build: '/b/build'};
        state.panoramaUrls = urlsA;

        const buildA = state.buildPanoramaPreview();
        moveTo(8, urlsB);
        const buildB = state.buildPanoramaPreview();
        assert.equal(requests.filter((request) => request.method === 'post').length, 2, 'B could not start its own Build');
        moveTo(7, urlsA);
        const read = state.loadPanoramaState();
        // The return read for A answers before A's Build commits.
        reads().at(-1).d.resolve({data: {rendition: {status: 'none'}}});
        await read;
        assert.equal(state.panoramaState.rendition.status, 'none');
        const readsBefore = reads().length;

        const posts = requests.filter((request) => request.method === 'post');
        const answer = {A: () => posts[0].d.resolve({data: {rendition: {status: 'queued'}}}), B: () => posts[1].d.resolve({data: {rendition: {status: 'failed'}}})};
        for (const which of order === 'A first' ? ['A', 'B'] : ['B', 'A']) {
            answer[which]();
            await (which === 'A' ? buildA : buildB);
            await settle();
        }

        assert.equal(reads().length, readsBefore + 1, 'A was not read again exactly once after its Build settled');
        assert.equal(reads().at(-1).url, '/a/state', 'the re-read was not for A');
        reads().at(-1).d.resolve({data: {rendition: {status: 'queued'}}});
        await settle();
        assert.equal(state.panoramaState.rendition.status, 'queued', 'A did not recover to queued (or took B\'s answer)');
        assert.notEqual(state.panoramaPollTimer, null, 'A\'s queued build is not being polled');
        clearTimeout(state.panoramaPollTimer);
        assert.equal(reads().length, readsBefore + 1, 'the re-read looped');
    });
}

test('several reads answering around one settled Build cause one re-read, not one per read', async () => {
    for (const settledFirst of [false, true]) {
        const {state, requests, reads, settle} = viewerWithStack();
        state.rememberStackCover();
        const build = state.buildPanoramaPreview();
        state.switchStackedFile({id: 2, asset_id: 7, label: 'F2.png', mime_type: 'image/png', url: '/f/2.svg', size: 10});
        state.showStackCover();
        const second = state.loadPanoramaState(); // e.g. a poll tick landing beside the return read
        assert.equal(reads().length, 2);

        const settleBuild = async () => { requests[0].d.resolve({data: {rendition: {status: 'queued'}}}); await build; await settle(); };
        if (settledFirst) await settleBuild();
        reads()[0].d.resolve({data: {rendition: {status: 'none'}}});
        reads()[1].d.resolve({data: {rendition: {status: 'none'}}});
        await second;
        await settle();
        if (! settledFirst) await settleBuild();
        assert.equal(reads().length, 3, `${settledFirst ? 'settled before' : 'settled after'} the reads answered: expected exactly one re-read`);
        clearTimeout(state.panoramaPollTimer);
    }
});

test('panorama build, rebuild, retry, cancel and mode send nothing while another stacked file is shown', async () => {
    const vm = require('node:vm');
    const pano = panoramaMethods();
    const context = {window: {}};
    vm.createContext(context);
    vm.runInContext(read('asset/stack-shown-script.blade.php').replace(/\{\{--[\s\S]*?--\}\}/g, '').replace(/<\/?script>/g, ''), context);
    const calls = [];
    pano.__window.axios = {
        get: async () => { calls.push('get'); return {data: {rendition: {status: 'none'}}}; },
        post: async (url) => { calls.push(`post ${url}`); return {data: {}}; },
        patch: async (url) => { calls.push(`patch ${url}`); return {data: {}}; },
    };
    const state = {
        ...pano, assertCoverShown: context.window._damShownStackFile.methods.assertCoverShown, stackFileShown: true, previewData: {id: 7},
        panoramaUrls: {state: '/p/state', build: '/p/build', rebuild: '/p/rebuild', retry: '/p/retry', cancel: '/p/cancel', mode: '/p/mode'},
        panoramaState: null, panoramaBusy: false, panoramaError: null, panoramaPollTimer: null, panoramaPollAttempt: 0, panoramaRejectedUrl: null,
        panoDestroy() {}, panoInit() {}, $nextTick: (fn) => fn(),
    };
    state._coverPreviewData = {...state.previewData};
    await Promise.all([state.buildPanoramaPreview(), state.rebuildPanoramaPreview(), state.retryPanoramaPreview(), state.cancelPanoramaPreview(), state.setPanoramaMode('flat')]);
    assert.deepEqual(calls, [], 'a cover-changing request was sent over another file');
    assert.equal(state.panoramaBusy, false);

    // Control: with the cover shown the same calls go out.
    state.stackFileShown = false;
    await state.buildPanoramaPreview();
    await state.setPanoramaMode('flat');
    assert.deepEqual(calls.filter((call) => call !== 'get'), ['post /p/build', 'patch /p/mode']);
});

// A name that fits two lines is shown whole; split, a head wrapping to a short second line left a gap before the
// tail ("Intelli gent…  Averta-Bold.otf", 2026-10-29). Only a name the clamp cuts splits, and keeps its ending.
browserTest('strip: a name that fits two lines is shown whole, a longer one is cut and keeps its ending', () => withBrowser(async (browser) => {
    const member = (id, label) => ({id, asset_id: 7, attachment_id: 10 + id, label, role: 'alternate', is_representative: id === 1, mime_type: 'font/otf', extension: 'otf', url: `/f/${id}.svg`, size: 1024});
    const long = 'Intelligent Systems Brand Typeface Specimen Sheet Averta-Bold.otf';
    const stack = [member(1, 'Intelligent Averta-Bold.otf'), member(2, long)];
    const page = await mountViewer(browser, {stackFiles: stack, count: stack.length});
    const stripSource = read('asset/preview-modal/attachments/strip-script.blade.php');
    await page.addStyleTag({content: stripSource.match(/<style>([\s\S]*?)<\/style>/)[1]});
    await page.addScriptTag({content: read('components/shared/name-tail.blade.php').match(/<script>([\s\S]*?)<\/script>/)[1]});
    await page.evaluate(() => window.modal.openPreview());
    await tile(page, long).locator('.dam-strip__tail').waitFor();
    const name = (label) => tile(page, label).locator('.dam-strip__name').evaluate((el) => ({
        whole: el.querySelector('.dam-strip__whole')?.textContent ?? null,
        tail: el.querySelector('.dam-strip__tail')?.textContent ?? null,
    }));
    assert.deepEqual(await name('Intelligent Averta-Bold.otf'), {whole: 'Intelligent Averta-Bold.otf', tail: null});
    assert.deepEqual(await name(long), {whole: null, tail: ' Averta-Bold.otf'});
    assert.deepEqual(page.problems, []);
}));

// Thumbnails that load show only the artwork (plus the application mark for an Adobe file); only a missing or failed one
// falls back to the extension/application tile, whatever the file is.
browserTest('strip: a healthy thumbnail has no fallback tile, a failed or absent one does, and an Adobe mark rides only a picture that loaded', () => withBrowser(async (browser) => {
    const member = (id, label, extension, mime, thumbnail, adobe = null) => ({id, asset_id: 7, attachment_id: 10 + id, label, role: 'alternate', is_representative: id === 1, mime_type: mime, extension, url: `/f/${id}.svg`, size: 1024, thumbnail_url: thumbnail, adobe});
    const ai = {key: 'illustrator', app: 'Adobe Illustrator', label: 'Illustrator', badge: 'Ai', color: '#FF9A00', extension: 'ai', title: 'Adobe Illustrator file (.AI)', pdf: true};
    const stack = [
        member(1, 'Cover.png', 'png', 'image/png', '/ok/1.svg'),
        member(2, 'Plain.pdf', 'pdf', 'application/pdf', '/ok/2.svg'),
        member(3, 'Lost.pdf', 'pdf', 'application/pdf', '/missing/3.svg'),
        member(4, 'Art.ai', 'ai', 'application/pdf', '/ok/4.svg', ai),
        member(5, 'Gone.ai', 'ai', 'application/pdf', '/missing/5.svg', ai),
        member(6, 'None.png', 'png', 'image/png', null),
    ];
    const page = await mountViewer(browser, {stackFiles: stack, count: stack.length});
    await page.evaluate(() => { window.MothershipAdobe = {icon: () => 'data:image/svg+xml;base64,PHN2Zy8+', tile: () => ''}; window.modal.openPreview(); });
    await tile(page, 'None.png').waitFor();
    await page.waitForFunction(() => [...document.querySelectorAll('[data-dam-strip-item] img:not([data-dam-strip-adobe-mark])')].every((img) => img.complete));
    // The failed pictures' error events land, and Vue re-renders those tiles, before anything is read.
    await tile(page, 'Gone.ai').locator('.dam-strip__type').waitFor();
    await tile(page, 'Lost.pdf').locator('.dam-strip__type').waitFor();
    const state = async (name) => tile(page, name).evaluate((el) => ({
        fallback: el.querySelector('.dam-strip__type')?.textContent.trim() || null,
        mark: el.querySelector('[data-dam-strip-adobe-mark]') !== null,
    }));
    assert.deepEqual(await state('Cover.png'), {fallback: null, mark: false});
    assert.deepEqual(await state('Plain.pdf'), {fallback: null, mark: false});
    assert.equal((await state('Lost.pdf')).fallback?.toUpperCase().includes('PDF'), true);
    assert.deepEqual(await state('Art.ai'), {fallback: null, mark: true});
    assert.equal((await state('Gone.ai')).mark, false);
    assert.equal((await state('Gone.ai')).fallback !== null, true);
    assert.equal((await state('None.png')).fallback?.toUpperCase().includes('PNG'), true);
    assert.deepEqual(page.problems, []);
}));

test('switching between two videos of a stack reloads the player on each member\'s own source, never the cover\'s proxy (feedback #6)', () => {
    const {state, file} = viewerWithStack();
    const loads = [];
    state.$refs = {videoEl: {load: () => loads.push(state.previewData.playbackUrl)}};
    state.previewData = {id: 7, file_type: 'video', playbackKind: 'video', playbackUrl: '/f/cover-proxy.mp4', playbackIsProxy: true};
    state.rememberStackCover();
    const video = (id, playback) => ({...file(id), role: 'other', mime_type: 'video/mp4', playback});

    state.switchStackedFile(video(2, {state: 'ready', url: '/f/2-proxy.mp4', mime: 'video/mp4', is_proxy: true}));
    assert.equal(state.previewData.playbackUrl, '/f/2-proxy.mp4');
    assert.equal(state.previewData.playbackIsProxy, true);

    state.switchStackedFile(video(3, {state: 'ready', url: '/f/3.mp4', mime: 'video/mp4', is_proxy: false}));
    assert.equal(state.previewData.playbackUrl, '/f/3.mp4');
    assert.equal(state.previewData.playbackIsProxy, false);

    // A member with no source yet is not given a player over an empty source.
    state.switchStackedFile(video(4, {state: 'processing', url: null, mime: null, is_proxy: false}));
    assert.equal(state.previewData.playbackKind, null);
    assert.equal(state.previewData.playbackProcessing, true);

    state.showStackCover();
    assert.equal(state.previewData.playbackUrl, '/f/cover-proxy.mp4');
    assert.equal(!! state.previewData.playbackProcessing, false);
    // Every switch reloaded the <video>: changing <source src> alone never does.
    assert.deepEqual(loads, ['/f/2-proxy.mp4', '/f/3.mp4', null, '/f/cover-proxy.mp4']);
});

test('a stacked file\'s own bytes name no <source type>, so Chrome\'s rejection of video/quicktime never keeps a .mov from loading; only a proxy names its type', () => {
    const script = read('asset/preview-modal/video/video-player-script.blade.php');
    const body = /videoActiveType\(\) \{([\s\S]*?)\n        \},/.exec(script);
    assert.ok(body, 'videoActiveType computed not found');
    const activeType = new Function(`return function () {${body[1]}}`)();
    const type = (previewData, extra = {}) => activeType.call({previewData, videoQualityAvailable: false, videoQuality: 'proxy', ...extra});

    assert.equal(type({playbackIsProxy: false, playbackMime: 'video/quicktime', mime_type: 'video/quicktime'}), undefined, 'a .mov kept its rejected type hint');
    assert.equal(type({playbackIsProxy: false, playbackMime: 'video/mp4', mime_type: 'video/mp4'}), undefined);
    assert.equal(type({playbackIsProxy: true, playbackMime: 'video/mp4'}), 'video/mp4', 'a proxy lost its type');
    assert.equal(type({playbackIsProxy: true, playbackMime: 'video/mp4'}, {videoQualityAvailable: true, videoQuality: 'original'}), undefined);
});

browserTest('showing a far file scrolls only the strip\'s own lane, never the page above it (the details page jumped ~330px on a phone)', () => withBrowser(async (browser) => {
    const stack = Array.from({length: 8}, (_, i) => ({...files[Math.min(i, 2)], id: i + 1, attachment_id: 11 + i, label: `File${i + 1}.png`, role: i ? 'alternate' : 'primary', is_representative: ! i}));
    const page = await mountViewer(browser, {inline: true, viewport: {width: 375, height: 320}, stackFiles: stack, count: stack.length});
    // The two Tailwind rules the strip's lane (<ol class="flex gap-2 overflow-x-auto">) depends on; no bundle loads here.
    // The strip starts below the fold, as it does under the picture on a phone.
    await page.addStyleTag({content: '.flex{display:flex}.gap-2{gap:.5rem}.overflow-x-auto{overflow-x:auto}[data-dam-strip-item]{flex:none;width:8rem}body{padding:900px 0}'});
    const far = inlineTile(page, 'File7.png');
    await far.waitFor({state: 'attached'});
    await page.evaluate(() => window.scrollTo(0, 0));
    // A DOM click: Playwright's own click would scroll the tile into view first and hide the bug.
    await far.evaluate((el) => el.click());
    await page.waitForFunction(() => window.modal.previewData.variantFileId === 7);
    await page.evaluate(() => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve))));
    assert.equal(await page.evaluate(() => window.scrollY), 0, 'the page scrolled');
    const {tile: box, lane} = await far.evaluate((el) => {
        let lane = el.parentElement;
        while (lane && ! ['auto', 'scroll'].includes(getComputedStyle(lane).overflowX)) lane = lane.parentElement;
        return {tile: el.getBoundingClientRect().toJSON(), lane: lane.getBoundingClientRect().toJSON()};
    });
    assert.ok(box.left >= lane.left - 1 && box.right <= lane.right + 1, `the viewing tile is outside its lane: ${JSON.stringify({box, lane})}`);
    assert.deepEqual(page.problems, []);
}));
