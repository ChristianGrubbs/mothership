import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import fileOps from '../../src/Resources/assets/js/library-file-ops.js';

// Adding to an existing stack: the plain "Add to stack" wording once exactly one stack is selected, the stack's
// context-menu item, the list-view drop, the merge dialog that keeps a merged stack as the representative, and a
// stacked file that is not the cover never offering to replace the stack card's picture.

const words = {
    'dam::app.admin.dam.asset.merge.action-count': 'Create stack (:count)',
    'dam::app.admin.dam.asset.merge.add-action-count': 'Add to stack (:count)',
    'dam::app.admin.dam.asset.merge.action': 'Create stack',
    'dam::app.admin.dam.asset.merge.add-action': 'Add to stack',
    'dam::app.admin.explorer.context.add-to-stack-empty': 'Select the files to add first.',
    'dam::app.admin.dam.asset.merge.add-confirm': 'Add :count file to stack|Add :count files to stack',
    'dam::app.admin.dam.asset.merge.summary-joins': ':count file joins “:name”.|:count files join “:name”.',
    'dam::app.admin.dam.asset.merge.add-intro': 'Adding :count file to “:name”.|Adding :count files to “:name”.',
    'dam::app.admin.dam.asset.files.roles': {},
};

function loadComponent(relativePath, componentName) {
    const blade = readFileSync(new URL(relativePath, import.meta.url), 'utf8');
    let script = [...blade.matchAll(/<script type="module">([\s\S]*?)<\/script>/g)]
        .map(match => match[1])
        .find(source => source.includes(`app.component('${componentName}'`));
    assert.ok(script, `component ${componentName} not found`);
    // A translated string the test cares about becomes its words; every other @js()/@json() prints null (not run outside Blade).
    script = script.replace(/@js(?:on)?\(trans\('([^']+)'\)\)/g, (_all, key) => JSON.stringify(words[key] ?? ''));
    script = script.replace(/@js(?:on)?\(/g, () => 'null/*@json(');
    script = script.split('\n').map(line => {
        if (line.includes('null/*@json(')) {
            const start = line.indexOf('null/*@json(');
            let depth = 0;
            let i = start + 'null/*@json('.length - 1;
            for (; i < line.length; i++) {
                if (line[i] === '(') depth++;
                else if (line[i] === ')') { depth--; if (depth === 0) break; }
            }
            return line.slice(0, i + 1) + '*/' + line.slice(i + 1);
        }
        return line;
    }).filter(line => !/^\s*@(if|elseif|else|endif|once|endonce|push|endpush|include)\b/.test(line)).join('\n')
        .replace(/\{\{[\s\S]*?\}\}/g, '/fixture.svg');
    let component;
    vm.runInNewContext(script, {
        app: { component: (_name, definition) => { component = definition; } },
        window: new Proxy({ MothershipFileOps: fileOps, MothershipNameTail: (name) => ({ head: name, tail: '' }) }, {
            // The preview modal spreads in sibling mixins (_damInfoPanel, _damImageViewer, ...); none matter here.
            get: (target, key) => key in target || typeof key !== 'string' || ! key.startsWith('_dam') ? target[key] : { data: {}, computed: {}, methods: {}, watch: {} },
        }), document: {}, clearTimeout, setTimeout, AbortController,
        localStorage: { getItem: () => null, setItem() {}, removeItem() {} },
        sessionStorage: { getItem: () => null, setItem() {}, removeItem() {} },
    });
    return component;
}

const plain = value => JSON.parse(JSON.stringify(value));
const views = '../../src/Resources/views/';
const tab = loadComponent(`${views}components/explorer/tab.blade.php`, 'v-dam-tab');
const list = loadComponent(`${views}components/explorer/list.blade.php`, 'v-dam-explorer-list');
const ctx = loadComponent(`${views}components/explorer/context-menu.blade.php`, 'v-dam-explorer-ctx');
const dialog = loadComponent(`${views}components/merge/dialog.blade.php`, 'v-dam-merge-dialog');
const suggestions = loadComponent(`${views}components/explorer/stack-suggestions.blade.php`, 'v-dam-stack-suggestions');
const tree = loadComponent(`${views}components/tree/damdirectories.blade.php`, 'v-dam-tree-view');
const treeItem = loadComponent(`${views}components/tree/damdirectories.blade.php`, 'v-dam-tree-item');
// variantPreviewData lives in the stack-switch mixin both previews spread (the Library lightbox and the details page).
const stackSwitch = (() => {
    const context = { window: { MothershipNameTail: (name) => ({ head: name, tail: '' }) } };
    const source = readFileSync(new URL(`${views}asset/preview-modal/attachments/strip-script.blade.php`, import.meta.url), 'utf8');
    vm.runInNewContext(source.match(/<script>([\s\S]*?)<\/script>/)[1].replace("@json(trans('dam::app.admin.dam.asset.files.roles'))", '{}'), context);
    return context.window._damStackSwitch;
})();

const assets = [
    { id: 1, display_name: 'Stack', attachment_count: 3, path: '/1.png', extension: 'png' },
    { id: 2, file_name: 'two.png', path: '/2.png', extension: 'png' },
    { id: 3, file_name: 'three.png', path: '/3.png', extension: 'png' },
    { id: 4, display_name: 'Other stack', attachment_count: 2 },
];

function tabInstance(selected) {
    const emitted = [];
    const instance = {
        ...tab.methods, tabId: 't1', assets, selection: { ids: selected.map(id => ({ type: 'asset', id })), mode: 'partial' },
        $emitter: { emit: (event, payload) => emitted.push({ event, payload }) },
    };
    for (const name of ['selectedStack', 'createStackLabel', 'addToStackLabel']) {
        Object.defineProperty(instance, name, { get: tab.computed[name].bind(instance), configurable: true });
    }
    return { instance, emitted };
}

test('one stack among the selected files makes the button, the menu entry and the dialog request say "Add to stack"', () => {
    const withStack = tabInstance([1, 2, 3]);
    assert.equal(withStack.instance.createStackLabel, 'Add to stack (2)');
    assert.equal(withStack.instance.addToStackLabel, 'Add to stack');
    withStack.instance.openMergeDialog();
    assert.equal(withStack.emitted[0].payload.coverId, 1);

    const plainFiles = tabInstance([2, 3]);
    assert.equal(plainFiles.instance.createStackLabel, 'Create stack (2)');
    assert.equal(plainFiles.instance.addToStackLabel, 'Create stack');
    plainFiles.instance.openMergeDialog();
    assert.equal('coverId' in plainFiles.emitted[0].payload, false);

    // Two stacks are not "adding": one of them would have to join the other.
    assert.equal(tabInstance([1, 4, 2]).instance.createStackLabel, 'Create stack (3)');
});

test('the stack context item sends the other selected files to the merge dialog with the stack as cover', () => {
    const { instance, emitted } = tabInstance([1, 2, 3]);
    instance.addSelectedToStack({ asset: assets[0] });
    assert.equal(emitted[0].event, 'dam:open-merge-dialog');
    assert.equal(emitted[0].payload.coverId, 1);
    assert.deepEqual(plain(emitted[0].payload.assets.map(a => a.id)), [1, 2, 3]);

    const nothing = tabInstance([]);
    nothing.instance.addSelectedToStack({ asset: assets[0] });
    assert.deepEqual(plain(nothing.emitted.map(e => [e.event, e.payload.type])), [['add-flash', 'warning']]);
});

test('the context menu item asks the tab to add the selection to the right-clicked stack', () => {
    const emitted = [];
    let closed = 0;
    ctx.methods.addToStack.call({ tabId: 't1', item: assets[0], close: () => closed++, $emitter: { emit: (event, payload) => emitted.push({ event, payload }) } });
    assert.deepEqual(plain(emitted), [{ event: 'dam:explorer-add-to-stack:t1', payload: { asset: assets[0] } }]);
    assert.equal(closed, 1);
});

test('"Create stack" on the context menu shows inside a selection of two or more files and opens the toolbar\'s merge dialog', () => {
    const menu = (item, ids) => {
        const instance = { item, selection: { ids: ids.map(id => ({ type: 'asset', id })) } };
        return ctx.computed.canCreateStack.call(instance);
    };
    assert.equal(menu(assets[1], [2, 3]), true);
    // Outside the selection, a single file, or a stack (whose own item adds to it): not offered.
    assert.equal(menu(assets[2], [2, 4]), false);
    assert.equal(menu(assets[1], [2]), false);
    assert.equal(menu(assets[0], [1, 2]), false);
    assert.equal(ctx.computed.canCreateStack.call({ item: assets[1], selection: { ids: [{ type: 'asset', id: 2 }, { type: 'directory', id: 3 }] } }), false);

    const emitted = [];
    let closed = 0;
    ctx.methods.createStack.call({ tabId: 't1', close: () => closed++, $emitter: { emit: (event, payload) => emitted.push({ event, payload }) } });
    assert.deepEqual(plain(emitted), [{ event: 'dam:explorer-create-stack:t1' }]);
    assert.equal(closed, 1);

    const { instance, emitted: fromTab } = tabInstance([2, 3]);
    instance.openMergeDialog();
    assert.equal(fromTab[0].event, 'dam:open-merge-dialog');
    assert.deepEqual(plain(fromTab[0].payload.assets.map(a => a.id)), [2, 3]);
});

function listInstance(draggingPayload = null) {
    const emitted = [];
    const instance = { ...list.methods, stackDropId: null, _draggingPayload: draggingPayload, $emit: (...args) => emitted.push(args), $emitter: { emit() {} } };
    return { instance, emitted };
}
const drag = (types, payload) => {
    const calls = { prevented: 0 };
    return { calls, event: { dataTransfer: { types, getData: () => JSON.stringify(payload) }, preventDefault: () => calls.prevented++, stopPropagation() {}, currentTarget: { contains: () => false }, relatedTarget: null } };
};
const dropped = { type: 'dam-asset', id: 2, tabId: 't1' };

test('a list row of a stack takes a dropped file the way a grid card does', () => {
    const { instance, emitted } = listInstance();
    const over = drag(['application/json'], dropped);
    instance.onStackDragOver(over.event, assets[0]);
    assert.equal(over.calls.prevented, 1);
    assert.equal(instance.stackDropId, 1);

    instance.onStackDrop(drag(['application/json'], dropped).event, assets[0]);
    assert.equal(instance.stackDropId, null);
    assert.deepEqual(plain(emitted), [['stack-drop', { payload: dropped, asset: assets[0] }]]);
});

test('a list row that is not a stack, its own drag, and OS files are not stack drops', () => {
    const plainRow = listInstance();
    const over = drag(['application/json'], dropped);
    plainRow.instance.onStackDragOver(over.event, assets[1]);
    plainRow.instance.onStackDrop(drag(['application/json'], dropped).event, assets[1]);
    assert.equal(over.calls.prevented, 0);
    assert.deepEqual(plainRow.emitted, []);

    const own = listInstance({ type: 'dam-asset', id: 1 });
    own.instance.onStackDrop(drag(['application/json'], { ...dropped, id: 1 }).event, assets[0]);
    const files = listInstance();
    const osFiles = drag(['Files'], dropped);
    files.instance.onStackDragOver(osFiles.event, assets[0]);
    files.instance.onStackDrop(osFiles.event, assets[0]);
    assert.equal(osFiles.calls.prevented, 0);
    assert.deepEqual([...own.emitted, ...files.emitted], []);
});

function dialogInstance(list) {
    const instance = {
        ...dialog.methods, assets: list.map(a => ({ files: 0, name: String(a.id), ...a })), destinationId: list[0].id, stackCoverId: null, t: dialog.data().t,
    };
    for (const name of ['adding', 'intro', 'confirmLabel', 'destination', 'stackCoverName']) {
        Object.defineProperty(instance, name, { get: dialog.computed[name].bind(instance), configurable: true });
    }
    return instance;
}

test('the dialog adds to the stack when exactly one file is a stack and it leads', () => {
    const dlg = dialogInstance([{ id: 1, name: 'Stack', files: 3 }, { id: 2 }, { id: 3 }]);
    assert.equal(dlg.adding, true);
    assert.equal(dlg.confirmLabel.includes('2'), true);
    dlg.destinationId = 2;
    assert.equal(dlg.adding, false);
    assert.equal(dialogInstance([{ id: 1, files: 3 }, { id: 4, files: 2 }]).adding, false);
});

test('a stack that other files are merged into stays the representative, and the other radios are disabled', () => {
    const dlg = dialogInstance([{ id: 2 }, { id: 1, name: 'Stack', files: 3 }]);
    assert.equal(dlg.keepMergedStackAsCover({ sources: [{ id: 1, nested: true }] }), true);
    assert.equal(dlg.destinationId, 1);
    assert.equal(dlg.stackCoverId, 1);
    assert.equal(dlg.coverLocked({ id: 2 }), true);
    assert.equal(dlg.coverLocked({ id: 1 }), false);
    // Held once: a second nested answer (two stacks) does not move the cover again.
    assert.equal(dlg.keepMergedStackAsCover({ sources: [{ id: 2, nested: true }] }), false);
    assert.equal(dlg.destinationId, 1);
    assert.equal(dialogInstance([{ id: 1 }, { id: 2 }]).keepMergedStackAsCover({ sources: [{ id: 2, nested: false }] }), false);
});

test('a refused review (two stacks) says nothing about files joining', () => {
    const dlg = dialogInstance([{ id: 1, files: 3 }, { id: 4, files: 2 }]);
    Object.defineProperty(dlg, 'joinSummary', { get: dialog.computed.joinSummary.bind(dlg), configurable: true });
    dlg.destination; // the review's cover
    dlg.preview = { sources: [{ files: [{}, {}] }], blocked: { message: 'Two stacks can’t be combined in one step.' } };
    assert.equal(dlg.joinSummary, '');

    dlg.preview = { sources: [{ files: [{}, {}] }], blocked: null };
    assert.notEqual(dlg.joinSummary, '');
});

test('removing the held stack from the review releases the representative lock', () => {
    const dlg = dialogInstance([{ id: 2 }, { id: 1, name: 'Stack', files: 3 }, { id: 3 }]);
    dlg.merging = false;
    dlg.loadPreview = () => {};
    dlg.$nextTick = () => {};
    dlg.$refs = {};
    dlg.keepMergedStackAsCover({ sources: [{ id: 1, nested: true }] });
    assert.equal(dlg.stackCoverId, 1);
    dlg.remove(1);
    assert.equal(dlg.stackCoverId, null);
    assert.equal(dlg.coverLocked({ id: 2 }), false);
    assert.equal(dlg.stackCoverName, '');
});

test('a stacked file that is not the cover cannot set the stack card\'s thumbnail', () => {
    const cover = { customThumbnailUrl: '/custom/9', canSetThumbnail: true };
    const base = { _coverPreviewData: cover, previewVariants: [{ is_representative: true, asset_id: 9, label: 'cover.png' }] };
    const variant = (file) => plain(stackSwitch.methods.variantPreviewData.call(base, { asset_id: 9, id: 5, label: 'clip.mp4', mime_type: 'video/mp4', url: '/u', extension: 'mp4', ...file }));

    const stacked = variant({ is_representative: false });
    assert.deepEqual([stacked.canSetThumbnail, stacked.customThumbnailUrl, stacked.thumbnailLocked], [false, null, true]);

    const own = variant({ is_representative: true });
    assert.deepEqual([own.canSetThumbnail, own.customThumbnailUrl, own.thumbnailLocked], [true, '/custom/9', false]);

    // A viewer who cannot edit gets no locked control either.
    const viewer = plain(stackSwitch.methods.variantPreviewData.call({ ...base, _coverPreviewData: { canSetThumbnail: false } }, { asset_id: 9, id: 5, label: 'a', mime_type: 'video/mp4', url: '/u' }));
    assert.equal(viewer.thumbnailLocked, false);
});

test('"Stack them" opens the Create stack review on every file of the suggestion and drops the group once it is stacked', () => {
    const emitted = [];
    const handlers = {};
    const group = { kind: 'stem', key: 'hero', members: [{ id: 1, name: 'a.jpg' }], entries: [{ id: 1, name: 'a.jpg', extension: 'jpg' }, { id: 2, name: 'b.png', extension: 'png' }] };
    const instance = {
        ...suggestions.methods, groups: [group, { kind: 'key', key: 'other', members: [], entries: [] }], load() {},
        $emitter: { emit: (event, payload) => emitted.push({ event, payload }), on: (event, handler) => { handlers[event] = handler; } },
    };

    instance.edit(group);
    assert.equal(emitted[0].event, 'dam:open-merge-dialog');
    assert.deepEqual(plain(emitted[0].payload.assets.map(a => [a.id, a.name, a.extension])), [[1, 'a.jpg', 'jpg'], [2, 'b.png', 'png']]);
    assert.equal(emitted[0].payload.context, 'suggestions');
    // Opening the review stacks nothing: no request, and the group stays until the review reports it done.
    assert.equal(instance.groups.length, 2);

    suggestions.mounted.call(instance);
    handlers['dam:merge-done']({ context: 'explorer:t1', reviewId: emitted[0].payload.reviewId });
    assert.equal(instance.groups.length, 2);
    handlers['dam:merge-done']({ context: 'suggestions', reviewId: emitted[0].payload.reviewId });
    assert.deepEqual(plain(instance.groups.map(g => g.key)), ['other']);
    assert.equal(emitted.at(-1).event, 'dam:uploads-refresh');
});

test('the folder tree refreshes its counts in place when a stack is made or split, and never re-selects a folder', () => {
    const handlers = {};
    const posted = [];
    const calls = { loaded: 0, defaulted: 0, revealed: 0 };
    const instance = {
        ...tree.methods,
        // A search or filtered view: no folder is selected.
        selectedItem: null,
        formattedItems: [{ id: 1, children: [{ id: 2, children: [{ id: 4, children: [] }] }, { id: 3 }] }],
        $emitter: { on: (event, handler) => { handlers[event] = handler; }, emit() {} },
        $axios: { interceptors: { response: { use: () => 1 } }, post: (_url, body) => { posted.push(body.ids); return new Promise(() => {}); } },
        onNativeAssetDrop() {},
        loadDirectories: () => { calls.loaded++; },
        setDefaultSeletedItem: () => { calls.defaulted++; },
        revealDirectory: () => { calls.revealed++; },
    };
    tree.mounted.call(instance);

    handlers['dam:merge-done']({ context: 'explorer:t1' });
    handlers['dam-merges-changed']({ assetId: 5 });

    // Every folder's count is asked for again; the tree is not reloaded, so no folder is selected for the user.
    assert.deepEqual(plain(posted), [[1, 2, 4, 3], [1, 2, 4, 3]]);
    assert.deepEqual(calls, { loaded: 0, defaulted: 0, revealed: 0 });
    assert.equal(instance.selectedItem, null);
});

// The tab and the tree on one emitter, as the page wires them: the merge dialog answers `dam:merge-done` to both.
function pageWithTabAndTree(view) {
    const listeners = {};
    const requests = [];
    const stack = { id: 90, display_name: 'New stack', attachment_count: 3 };
    const response = { data: { directories: [], assets: [stack, assets[1]], meta: { total: 2, current_page: 1 } } };
    const emitter = {
        on: (event, handler) => { (listeners[event] ||= []).push(handler); },
        off() {},
        emit: (event, payload) => { (listeners[event] || []).forEach(handler => handler(payload)); },
    };
    const calls = { loadDirectories: 0, defaulted: 0, posts: [] };
    const treeInstance = {
        ...tree.methods,
        selectedItem: null,
        formattedItems: [{ id: 1, children: [{ id: 7, children: [] }] }],
        $emitter: emitter,
        $axios: { interceptors: { response: { use: () => 1 } }, post: (_url, body) => { calls.posts.push(body.ids); return new Promise(() => {}); } },
        onNativeAssetDrop() {},
        loadDirectories: () => { calls.loadDirectories++; },
        setDefaultSeletedItem: () => { calls.defaulted++; },
    };
    tree.mounted.call(treeInstance);

    // Production view state: the folder is `currentDirId`, the column filters live under `applied.filters.columns`.
    const tabInstance = {
        ...tab.methods,
        tabId: 't1', scope: view.scope, currentDirId: view.currentDirId, search: view.search,
        applied: { filters: { columns: view.columns } },
        similarTo: null, smartSearch: false, mediaType: 'all', includeSubfolders: false, page: 1, perPage: 50,
        sortBy: 'created_at', sortOrder: 'desc', groupBy: '', loading: false, loadError: null,
        dirs: [], assets: [assets[1]], meta: null, selection: { ids: [], mode: 'none' }, navHistory: [], breadcrumb: [],
        pendingStackHighlight: null,
        clearSelection() {}, restoreSelection() {}, computeSelectionMode() {}, trackSearchSettled() {}, sync() {}, userKey: () => null,
        $emit() {},
        $emitter: emitter,
        $axios: { get: (_url, config) => { requests.push(plain(config.params)); return Promise.resolve(response); } },
    };
    emitter.on('dam:merge-done', event => tabInstance.onMergeDone(event));

    return { emitter, tabInstance, calls, requests, stack };
}

const settle = () => new Promise(resolve => setTimeout(resolve, 0));

test('a stack made from a search, a folder or a filtered folder re-lists the view it was made in, sends the same query and changes none of it', async () => {
    const views = {
        'scope=all with a search': { scope: 'all', currentDirId: null, search: 'V1-', columns: [], expect: { scope: 'all', search: 'V1-' } },
        'a folder': { scope: 'directory', currentDirId: 7, search: '', columns: [], expect: { scope: 'directory', directory_id: 7 } },
        'a filtered folder': {
            scope: 'directory', currentDirId: 7, search: '', columns: [{ index: 'extension', value: ['jpg'] }],
            expect: { scope: 'directory', directory_id: 7, filter_extension: 'jpg' },
        },
    };

    for (const [name, view] of Object.entries(views)) {
        const page = pageWithTabAndTree(view);
        const before = plain({ scope: page.tabInstance.scope, currentDirId: page.tabInstance.currentDirId, search: page.tabInstance.search, columns: page.tabInstance.applied.filters.columns });

        page.emitter.emit('dam:merge-done', { context: 'explorer:t1', destinationId: 90 });
        await settle();

        // One re-list, with the query the view already had.
        assert.equal(page.requests.length, 1, name);
        assert.deepEqual(page.requests[0], { page: 1, per_page: 50, media_type: 'all', include_subfolders: 0, sort_by: 'created_at', sort_order: 'desc', ...view.expect }, name);
        // The new stack is in the listing, and the view state is the one it was.
        assert.deepEqual(plain(page.tabInstance.assets.map(a => a.id)), [90, 2], name);
        assert.deepEqual(plain(page.tabInstance.meta), { total: 2, current_page: 1 }, name);
        assert.equal(page.tabInstance.pendingStackHighlight, 90, name);
        assert.deepEqual(plain({ scope: page.tabInstance.scope, currentDirId: page.tabInstance.currentDirId, search: page.tabInstance.search, columns: page.tabInstance.applied.filters.columns }), before, name);
        // The tree refreshed its counts and selected nothing, reloaded nothing.
        assert.deepEqual(plain(page.calls.posts), [[1, 7]], name);
        assert.deepEqual([page.calls.loadDirectories, page.calls.defaulted], [0, 0], name);
    }
});


function treeEmitter() {
    const listeners = {};

    return {
        on: (event, handler) => { (listeners[event] ||= []).push(handler); },
        off: (event, handler) => { listeners[event] = (listeners[event] || []).filter(known => known !== handler); },
        emit: (event, payload) => { (listeners[event] || []).forEach(handler => handler(payload)); },
    };
}

/** A tree folder showing files, whose file list the server answers from `lists` (one answer per request). */
function treeFolder(emitter, { id, open, files, lists }) {
    const folder = {
        ...treeItem.methods,
        item: { id, assets: [], children: [] }, isOpen: open, localAssets: files.map(file => ({ id: file })),
        assetsLoaded: true, assetsLoading: false, assetsStale: false, damTreeShowAssets: true,
        $emitter: emitter,
        $axios: { get: () => Promise.resolve({ data: { data: lists.shift().map(file => ({ id: file })) } }) },
        fetchChildCounts() {},
    };
    Object.defineProperty(folder, 'isAssets', { get: treeItem.computed.isAssets.bind(folder), configurable: true });
    treeItem.mounted.call(folder);

    return folder;
}

test('with files shown in the tree, a merge that empties a folder and a split that restores files update the root and an expanded child in place', async () => {
    const emitter = treeEmitter();
    // Each folder is asked for its files once per stack change: the merge leaves none, the split brings three back.
    const root = treeFolder(emitter, { id: 1, open: false, files: [10, 11], lists: [[], [10, 11, 12]] });
    const child = treeFolder(emitter, { id: 2, open: true, files: [20, 21], lists: [[], [20, 21, 22]] });
    const treeView = {
        ...tree.methods, showAssets: true, formattedItems: [{ id: 1, children: [{ id: 2, children: [] }] }], $emitter: emitter,
        $axios: { post: () => new Promise(() => {}) }, loadRootAssets() {},
    };
    let rootListsLoaded = 0;
    treeView.loadRootAssets = () => { rootListsLoaded++; };

    assert.deepEqual([root.isAssets, child.isAssets], [true, true]);
    treeView.refreshCounts();
    await settle();
    // Merged to zero: the hidden members are gone from both lists and neither shows a chevron any more.
    assert.deepEqual(plain([root.localAssets, child.localAssets]), [[], []]);
    assert.deepEqual([!! root.isAssets, !! child.isAssets], [false, false]);

    treeView.refreshCounts();
    await settle();
    // Split: the restored members are back, and nothing about what was open or selected changed.
    assert.deepEqual(plain([root.localAssets.map(f => f.id), child.localAssets.map(f => f.id)]), [[10, 11, 12], [20, 21, 22]]);
    assert.deepEqual([root.isAssets, child.isAssets, root.isOpen, child.isOpen], [true, true, false, true]);
    assert.equal(rootListsLoaded, 2);

    // With files not shown in the tree there is nothing to re-read.
    treeView.showAssets = false;
    treeView.refreshCounts();
    assert.equal(rootListsLoaded, 2);
});

test('a count answer that arrives after a newer request for the same folders is dropped', async () => {
    const waiting = [];
    const node = { id: 5, assets_total_count: 9 };
    const treeView = { ...tree.methods, showAssets: false, formattedItems: [node], $emitter: treeEmitter(), $axios: { post: () => new Promise(resolve => waiting.push(resolve)) } };

    treeView.fetchCountsForNodes([node]);
    treeView.fetchCountsForNodes([node]);
    // The newer request answers first (3 files), then the older one (7): the older must not win.
    waiting[1]({ data: { data: { 5: 3 } } });
    await settle();
    waiting[0]({ data: { data: { 5: 7 } } });
    await settle();
    assert.equal(node.assets_total_count, 3);

    // The same for the lazily loaded children of an open folder.
    const folder = treeFolder(treeEmitter(), { id: 1, open: true, files: [], lists: [] });
    folder.item.children = [{ id: 6, assets_total_count: 9 }];
    const later = [];
    folder.$axios.post = () => new Promise(resolve => later.push(resolve));
    treeItem.methods.fetchChildCounts.call(folder, [6]);
    treeItem.methods.fetchChildCounts.call(folder, [6]);
    later[1]({ data: { data: { 6: 1 } } });
    await settle();
    later[0]({ data: { data: { 6: 4 } } });
    await settle();
    assert.equal(folder.item.children[0].assets_total_count, 1);
});

/** A folder whose file reads are deferred: `reads` collects each request's resolve/reject. */
function deferredFolder(emitter, { id = 1, files = [1] } = {}) {
    const reads = [];
    const folder = treeFolder(emitter, { id, open: true, files, lists: [] });
    folder.$axios.get = () => new Promise((resolve, reject) => reads.push({ resolve: list => resolve({ data: { data: list.map(file => ({ id: file })) } }), reject }));

    return { folder, reads };
}

test('a merge that arrives during a folder read is followed up even when that read fails, and the rows shown stay meanwhile', async () => {
    const emitter = treeEmitter();
    const { folder, reads } = deferredFolder(emitter, { files: [1, 2] });

    emitter.emit('refresh-dir-assets');
    assert.equal(reads.length, 1);
    emitter.emit('refresh-dir-assets');
    // Still the first read: the second change is queued behind it.
    assert.equal(reads.length, 1);
    assert.equal(folder.assetsStale, true);

    reads[0].reject(new Error('network'));
    await settle();
    // The failed read changed nothing on screen, and the queued refresh went out.
    assert.deepEqual(plain(folder.localAssets.map(f => f.id)), [1, 2]);
    assert.equal(reads.length, 2);

    reads[1].resolve([2]);
    await settle();
    assert.deepEqual(plain(folder.localAssets.map(f => f.id)), [2]);
    assert.deepEqual([folder.assetsStale, folder.assetsLoading], [false, false]);
});

test('a folder that was unmounted answers and requests nothing; the remounted one is the only one refreshed', async () => {
    const emitter = treeEmitter();
    const first = deferredFolder(emitter, { files: [1] });
    const inFlight = first.folder.loadDirectoryAssets();
    assert.equal(first.reads.length, 1);

    treeItem.beforeUnmount.call(first.folder);
    // Its answer arrives after it is gone: not applied.
    first.reads[0].resolve([9]);
    await settle();
    assert.deepEqual(plain(first.folder.localAssets.map(f => f.id)), [1]);

    const second = deferredFolder(emitter, { files: [1] });
    emitter.emit('refresh-dir-assets');
    emitter.emit('invalidate-dir-assets', null);

    assert.equal(first.reads.length, 1);
    assert.equal(second.reads.length, 1);
    void inFlight;
});
