const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const root = path.resolve(__dirname, '../../..');
const base = 'packages/Mothership/DAM/src/';
const editAssetPath = path.join(root, base + 'Resources/views/asset/edit.blade.php');

// Codex review (PR #177): v-custom-download, v-reupload-asset, v-delete-asset, and
// v-share-asset-button each seed their state from the page's ORIGINAL @json($asset)/asset-id and
// never listen for dam-asset-changed (navigateTo()'s ‹ › chevron event) — so after navigating to
// a different asset, every download/reupload/delete/share action kept targeting the FIRST asset
// shown on the page. This extracts each component's own <script type="module"> block, the same
// vm-sandbox way dam-preview-navigation.test.cjs and dam-details-navigation.test.cjs do, and
// drives it through a real dam-asset-changed emit.
// Replaces every `<prefix>...)` with `replacement`, matching parentheses so an argument with its own
// calls (`@json($x ? route('a', [...]) : null)`) is removed whole.
function replaceBalanced(source, prefix, replacement) {
    let out = '';
    let from = 0;
    for (let at = source.indexOf(prefix); at !== -1; at = source.indexOf(prefix, from)) {
        let depth = 0;
        let i = at + prefix.length - 1;
        for (; i < source.length; i++) {
            if (source[i] === '(') depth++;
            else if (source[i] === ')' && --depth === 0) break;
        }
        out += source.slice(from, at) + replacement;
        from = i + 1;
    }
    return out + source.slice(from);
}

function loadComponentScript(componentName) {
    const source = fs.readFileSync(editAssetPath, 'utf8');
    const marker = `app.component('${componentName}',`;
    const markerIndex = source.indexOf(marker);
    assert.notEqual(markerIndex, -1, `${componentName} not found in edit.blade.php`);
    const scriptOpen = source.lastIndexOf('<script type="module">', markerIndex);
    const scriptClose = source.indexOf('</script>', markerIndex);
    assert.notEqual(scriptOpen, -1);
    assert.notEqual(scriptClose, -1);

    let script = source.slice(scriptOpen, scriptClose).replace('<script type="module">', '');
    // Blade control lines (`@if (...)` / `@endif`) wrap optional object keys; dropping the lines keeps
    // the guarded key, so the script parses and the branch's code still runs.
    script = script.replace(/^[ \t]*@(?:if|elseif|else|endif)\b.*$/gm, '');
    script = replaceBalanced(script, '@json(', 'null')
        .replace(/@lang\('[^']*'\)/g, 'Label')
        // Simple single-arg trans() calls (no nested array/function calls).
        .replace(/@js\(trans\('[^']*'\)\)/g, "'Message'")
        // trans() calls with a params array (nested calls inside): line-bounded by the
        // statement's own trailing `;`, which is unambiguous since these are one per line.
        .replace(/@js\(trans\(.*\)\);/g, "'Message';")
        // Preserves the `:id` token so `.replace(':id', someId)` inside the real method body is
        // still exercised — a blanket placeholder without it would make every id substitution a
        // silent no-op and this whole test suite would test nothing. Bare (unquoted): the
        // original `{{ ... }}` is always already inside a backtick or double-quoted string in
        // the source, so adding another quote layer here would bake literal quote characters
        // into the runtime string instead of just delimiting JS source.
        .replace(/\{\{\s*route\('([\w.]+)'(?:,\s*'([^']*)')?\)\s*\}\}/g, (m, name, arg) => `/${name.replace(/\./g, '/')}${arg ? '/' + arg : ''}`);

    // Any other `@js(...)`, e.g. trans_choice() with a params array, matched by balanced parentheses.
    script = replaceBalanced(script, '@js(', "'Message'");

    let definition;
    const context = {app: {component: (name, value) => { definition = value; }}, window: {}, URLSearchParams, URL, FormData, AbortController};
    vm.createContext(context);
    vm.runInContext(script, context);

    return {definition, context};
}

function fakeEmitter() {
    const listeners = {};

    return {
        on(type, fn) { (listeners[type] ||= []).push(fn); },
        off(type, fn) { listeners[type] = (listeners[type] || []).filter((l) => l !== fn); },
        emit(type, payload) { (listeners[type] || []).forEach((fn) => fn(payload)); },
        _listeners: listeners,
    };
}

test('v-custom-download: dam-asset-changed replaces selectedItem, switches the svg/image/other branch, and downloadItem() targets the NEW id', () => {
    const {definition, context} = loadComponentScript('v-custom-download');
    const opened = [];
    context.window.open = (url) => opened.push(url);
    const emitter = fakeEmitter();

    // Seeded as the page's original asset: a video (neither svg nor image).
    const initialAsset = {id: 1, extension: 'mp4', file_type: 'video', width: 0, height: 0};
    const state = {
        ...definition.methods,
        // The cover is on screen (the details page's shown-file mixin says so).
        assertCoverShown: () => true,
        selectedItem: initialAsset,
        isLocked: false,
        onLockChange: null,
        onAssetChange: null,
        supportedExtensionTypes: [{label: 'Original', value: initialAsset.extension}],
        selectedItemExtension: initialAsset.extension,
        selectedItemWidth: 0,
        selectedItemHeight: 0,
        $emitter: emitter,
        $refs: {
            assetCustomDownloadModal: {toggled: 0, toggle() { this.toggled++; }, close() {}},
            svgCustomDownloadModal: {toggled: 0, toggle() { this.toggled++; }, close() {}},
        },
    };
    // Wire the real computed getters up as plain getters, the same way dam-preview-navigation's
    // previewModalState() does for a Vue computed outside real component reactivity.
    for (const [key, getter] of Object.entries(definition.computed)) {
        Object.defineProperty(state, key, {get: () => getter.call(state)});
    }

    definition.mounted.call(state);

    assert.equal(state.isOriginalDownloadPrimary, false, 'a video must not get the svg/image "download original" treatment');

    // navigateTo() (‹ › chevrons) loads a different asset — an image this time.
    emitter.emit('dam-asset-changed', {
        asset: {id: 2, extension: 'png', file_type: 'image'},
        width: 800,
        height: 600,
    });

    assert.equal(state.selectedItem.id, 2, 'selectedItem was not replaced by dam-asset-changed');
    assert.equal(state.isImageType, true);
    assert.equal(state.isOriginalDownloadPrimary, true);
    assert.equal(state.supportedExtensionTypes[0].value, 'png', '"Original" option in the format dropdown must track the new asset');
    assert.equal(state.selectedItemExtension, 'png');
    assert.equal(state.selectedItemWidth, 800);
    assert.equal(state.selectedItemHeight, 600);

    // downloadItem() calls window.open (spied on context.window above) to prove the download
    // click now targets id 2, not the original id 1.
    state.downloadItem();
    assert.equal(opened.length, 1);
    assert.match(opened[0], /\/2$/, 'downloadItem() opened the ORIGINAL asset\'s id, not the newly-navigated one');

    // "Download as…" for an image opens the CUSTOM (raster) modal, and an actual submit of that
    // modal's form builds the conversion link off the NEW asset's id, not the original one.
    state.customDownloadModel();
    assert.equal(state.$refs.assetCustomDownloadModal.toggled, 1, 'customDownloadModel() did not open the raster "Download as…" modal');
    state.customDownload({format: 'jpg', width: '400', height: '300'}, {resetForm() {}, setErrors() {}});
    assert.equal(opened.length, 2);
    assert.match(opened[1], /custom_download\/2\?/, 'the raster conversion link targeted the ORIGINAL asset id, not the navigated-to one');

    // Now navigate again to an SVG — "Download as…" must switch to the SVG (Imagick) modal.
    emitter.emit('dam-asset-changed', {asset: {id: 3, extension: 'svg', file_type: 'image'}});
    assert.equal(state.isSvgType, true);
    state.svgDownloadModel();
    assert.equal(state.$refs.svgCustomDownloadModal.toggled, 1, 'svgDownloadModel() did not open the svg "Download as…" modal');
    state.svgCustomDownload({format: 'png'}, {resetForm() {}, setErrors() {}});
    assert.equal(opened.length, 3);
    assert.match(opened[2], /custom_download\/3\?/, 'the svg conversion link targeted a stale asset id, not the current one');

    definition.unmounted.call(state);
    assert.deepEqual(emitter._listeners['dam-asset-changed'], []);
});

test('v-custom-download: svg takes the svg branch even though its file_type is also "image"', () => {
    const {definition} = loadComponentScript('v-custom-download');
    const state = {
        selectedItem: {id: 5, extension: 'svg', file_type: 'image'},
    };
    for (const [key, getter] of Object.entries(definition.computed)) {
        Object.defineProperty(state, key, {get: () => getter.call(state)});
    }

    assert.equal(state.isSvgType, true);
    assert.equal(state.isImageType, false);
    assert.equal(state.isOriginalDownloadPrimary, true);
});

test('v-reupload-asset: dam-asset-changed replaces selectedItem so a re-upload targets the NEW asset id', async () => {
    const {definition} = loadComponentScript('v-reupload-asset');
    const emitter = fakeEmitter();
    const state = {
        ...definition.methods,
        // The cover is on screen (the details page's shown-file mixin says so).
        assertCoverShown: () => true,
        selectedItem: {id: 1},
        isUploading: false,
        abortController: null,
        onAssetChange: null,
        $emitter: emitter,
    };
    definition.mounted.call(state);

    emitter.emit('dam-asset-changed', {asset: {id: 9, file_name: 'old.png', file_type: 'image'}});
    assert.equal(state.selectedItem.id, 9);

    // A re-upload under another name asks first (UX eval G1-18): answer the confirm the way the person would.
    const asked = [];
    let answer = 'agree';
    emitter.on('open-confirm-modal', (modal) => { asked.push(modal.message); modal[answer](); });
    let posted = null;
    state.$axios = {post: (url, formData) => { posted = formData; return new Promise(() => {}); }};
    // The confirm reads the file's first bytes (a Blob read), so wait for the outcome rather than for one tick.
    const settle = async (done) => { for (let i = 0; i < 200 && !done(); i++) await new Promise((resolve) => setTimeout(resolve, 5)); };
    const upload = () => state.onFileChange({target: {files: [new File(['new bytes'], 'new.png', {type: 'image/png'})], value: null}});

    answer = 'disagree';
    upload();
    await settle(() => asked.length === 1);
    await new Promise((resolve) => setTimeout(resolve, 20));
    assert.equal(asked.length, 1, 'a re-upload that renames the file asks first');
    assert.equal(posted, null, 'Cancel uploads nothing');

    answer = 'agree';
    upload();
    await settle(() => posted !== null);
    assert.equal(asked.length, 2);
    const assetId = posted && posted.get ? posted.get('asset_id') : null;
    assert.equal(String(assetId), '9', 'the re-upload FormData carried the ORIGINAL asset id, not the navigated-to one');

    definition.unmounted.call(state);
    assert.deepEqual(emitter._listeners['dam-asset-changed'], []);
});

test('v-delete-asset: dam-asset-changed replaces selectedItem so delete targets the NEW asset id', () => {
    const {definition} = loadComponentScript('v-delete-asset');
    const emitter = fakeEmitter();
    const state = {
        ...definition.methods,
        // The cover is on screen (the details page's shown-file mixin says so).
        assertCoverShown: () => true,
        selectedItem: {id: 1},
        isLocked: false,
        onLockChange: null,
        onAssetChange: null,
        isDeleting: false,
        $emitter: emitter,
        closeContextMenu() {},
    };
    definition.mounted.call(state);

    emitter.emit('dam-asset-changed', {asset: {id: 12}});
    assert.equal(state.selectedItem.id, 12);

    let deleteUrl = null;
    state.$axios = {delete: (url) => { deleteUrl = url; return new Promise(() => {}); }};
    const originalEmit = emitter.emit.bind(emitter);
    emitter.emit = (type, payload) => { if (type === 'open-delete-modal') payload.agree(); else originalEmit(type, payload); };
    state.deleteFile();

    assert.match(deleteUrl, /\/12$/, 'deleteFile() targeted the ORIGINAL asset id, not the navigated-to one');

    definition.unmounted.call(state);
    assert.deepEqual(emitter._listeners['dam-asset-changed'], []);
    assert.deepEqual(emitter._listeners['dam-asset-action-locked'], []);
});

test('v-share-asset-button: the asset-id PROP is frozen, but dam-asset-changed updates currentAssetId so Share targets the NEW asset', () => {
    const {definition} = loadComponentScript('v-share-asset-button');
    const emitter = fakeEmitter();
    const state = {
        ...definition.methods,
        // The cover is on screen (the details page's shown-file mixin says so).
        assertCoverShown: () => true,
        assetId: 1, // the frozen prop, as Blade baked it at initial render
        isLocked: false,
        onLockChange: null,
        onAssetChange: null,
        $emitter: emitter,
    };
    // data() reads `this.assetId` (the prop) into currentAssetId; simulate that seeding.
    state.currentAssetId = definition.data.call(state).currentAssetId;
    assert.equal(state.currentAssetId, 1);

    definition.mounted.call(state);
    emitter.emit('dam-asset-changed', {asset: {id: 21}});
    assert.equal(state.currentAssetId, 21, 'currentAssetId did not follow dam-asset-changed');
    // The frozen prop itself never changes — proving the fix reads currentAssetId, not assetId.
    assert.equal(state.assetId, 1);

    const emitted = [];
    const originalEmit = emitter.emit.bind(emitter);
    emitter.emit = (type, payload) => { emitted.push([type, payload]); originalEmit(type, payload); };
    state.openShare();

    assert.equal(emitted.length, 1);
    assert.equal(emitted[0][0], 'open-share-modal');
    assert.equal(emitted[0][1].targetId, 21, 'openShare() targeted the ORIGINAL asset id, not the navigated-to one');

    definition.unmounted.call(state);
    assert.deepEqual(emitter._listeners['dam-asset-changed'], []);
});
