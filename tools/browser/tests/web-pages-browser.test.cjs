const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

const root = path.resolve(__dirname, '../../..');
const labels = {
    title: 'Web Pages',
    search: 'Search titles',
    'owner-me': 'Mine',
    'owner-everyone': 'Everyone',
    shared: 'Shared',
    preparing: 'Preparing…',
    'bundle-failed': 'Bundle failed — re-upload',
    'disk-unsupported': 'Web pages need the local asset disk',
    'open-full-screen': 'Open full screen',
    'copy-share-link': 'Copy share link',
    'download-original': 'Download original',
    empty: 'No web pages yet.',
    versions: ':count versions',
    browser: {
        'new-folder': 'New folder', open: 'Open', rename: 'Rename', delete: 'Delete',
        'delete-confirm': ':children subfolders and :pages pages move to :target',
        unfiled: 'Unfiled', cancel: 'Cancel', save: 'Save', select: 'Select',
        'move-to': 'Move to…', selected: ':count selected', clear: 'Clear',
        moved: 'Moved :count', 'move-partial': 'Moved :placed, :rejected could not be moved',
        'empty-root': 'No web pages yet.', 'empty-folder': 'This folder is empty.',
        'no-matches': 'No pages match ":q"', 'upload-here': 'Upload here',
        'upload-target': 'Upload to', 'not-web': 'Not a web page — filed in the Library only',
        placed: 'Placed', uploading: 'Uploading…',
        'sort-newest': 'Newest first', 'sort-oldest': 'Oldest first', 'sort-name-asc': 'Name A–Z', 'sort-name-desc': 'Name Z–A',
        showing: 'Showing :from–:to of :total', 'load-failed': 'The list could not be loaded.', retry: 'Retry',
        'rename-failed': 'Could not rename the folder.', renamed: 'Renamed to ":name"', 'just-now': 'just now',
        'page-count': ':count pages', 'skipped-files': ':count files skipped', uploaded: 'Uploaded :count pages', 'upload-failed': 'Upload failed',
        'member-position': 'File :position of :count', 'member-prev': 'Previous file', 'member-next': 'Next file', 'member-select': 'Choose a file',
        'members-loading': 'Loading files…', 'members-failed': 'The file list could not be loaded, so only the main page is shown.',
        'share-folder': 'Share pages in folder', 'share-folder-confirm': 'Share :count page|Share :count pages',
        'share-folder-kept': 'Existing link kept (:expiry)', 'share-folder-expires': 'expires :date', 'share-folder-no-expiry': 'no expiry', 'share-folder-password': 'password protected',
        'share-folder-summary': ':created new, :kept kept, :failed not shared', 'share-folder-unknown': ':created new, :kept kept, :failed not shared, :unknown may exist',
        'share-folder-created': 'Link created', 'share-folder-unknown': ':created new, :kept kept, :failed not shared, :unknown may exist', 'share-folder-working': 'Sharing…', 'share-folder-waiting': 'Waiting', 'share-folder-preview': 'downloads off', 'share-folder-creator': 'closes if its creator loses access', 'share-folder-more': 'More pages below: scroll the list', 'share-folder-changed': 'changed', 'share-folder-denied': 'not allowed', 'share-folder-row-failed': 'failed',
        'share-folder-uncertain': 'may exist', 'share-folder-skipped': 'Not attempted', 'share-folder-copied': 'Links copied',
    },
    'member-stale': 'This file changed since it was opened.', 'member-show-current': 'Show current file',
};

// The dialogs read performance.now() when they open; tests move this clock by hand.
const clock = { now: 0 };

function component(platform) {
    const source = fs.readFileSync(path.join(root, 'packages/Mothership/DAM/src/Resources/views/pages/browser-script.blade.php'), 'utf8');
    const script = source.split('<script type="module">')[1].split('</script>')[0].replace(/@json\(csrf_token\(\)\)/g, '"csrf-token-stub"');
    let result;
    vm.runInNewContext(script, { app: { component: (name, value) => { result = value; } }, setTimeout, clearTimeout, performance: { now: () => clock.now }, URLSearchParams, AbortController, FormData, document: { addEventListener() {}, querySelector: () => null, activeElement: null, createElement: () => ({ style: {}, focus() {}, select() {} }), body: { appendChild() {}, removeChild() {} }, execCommand: () => false }, navigator: { platform }, window: { matchMedia: () => ({ matches: false }), location: { origin: 'https://dam.example.test' } } });
    return result;
}

function instance(overrides = {}, platform = undefined) {
    const definition = component(platform);
    const props = {
        initial: { collection: null, breadcrumb: [{ id: null, name: 'Web Pages' }, { id: 3, name: 'Projects' }, { id: 9, name: 'Notes' }], children: [], pages: { data: [], current_page: 1, last_page: 1, total: 0 }, search_mode: false, filters: { owner: 'everyone', q: '' } },
        routes: { listing: '/dam/pages/listing', store: '/dam/pages/collections', update: '/dam/pages/collections', destroy: '/dam/pages/collections', move: '/dam/pages/move', show: '/dam/pages', download: '/dam/assets/download', upload: '/dam/assets/upload', sharedLinksStore: '/dam/shared-links', shareCandidates: '/dam/shared-links/web-collections' },
        can: { organize: true, share: true, upload: true, download: true },
        labels,
        notice: null,
        uploadDirectories: [],
    };
    const inst = { ...props, ...definition.data.call({ initial: props.initial, uploadDirectories: props.uploadDirectories }), ...overrides };
    Object.entries(definition.methods).forEach(([key, value]) => { inst[key] = value.bind(inst); });
    Object.entries(definition.computed || {}).forEach(([key, value]) => Object.defineProperty(inst, key, { get: () => value.call(inst) }));
    return inst;
}

test('breadcrumb data renders the full trail from the initial payload', () => {
    const c = instance();
    assert.deepEqual(c.breadcrumb.map((crumb) => crumb.name), ['Web Pages', 'Projects', 'Notes']);
});

test('page trash requires confirmation, sends one DELETE and preserves the dialog on refusal', async () => {
    const c = instance({ routes: { trashPage: '/dam/pages' } });
    const page = { id: 42, display_name: 'Synthetic page', can_delete: true };
    let calls = 0, loads = 0, closed = 0;
    const errors = [];
    c.closeDialog = () => { closed++; c.dialog = null; };
    c.notify = (kind, text) => errors.push({ kind, text });
    c.load = async () => { loads++; };
    c.confirmTrashPage({ ...page, can_delete: false });
    assert.notEqual(c.dialog, 'trash-page');
    c.confirmTrashPage(page);
    assert.equal(c.dialog, 'trash-page');
    assert.equal(calls, 0, 'opening the confirmation never deletes');
    c.cancelTrashPage();
    assert.equal(calls, 0, 'cancel never deletes');
    c.confirmTrashPage(page);
    let release;
    c.request = async (url, options) => {
        calls++;
        assert.equal(url, '/dam/pages/42');
        assert.equal(options.method, 'DELETE');
        await new Promise(resolve => { release = resolve; });
        return { ok: false, json: async () => ({ message: 'Dependency prevents trash' }) };
    };
    const pending = c.submitTrashPage();
    await c.submitTrashPage();
    c.cancelTrashPage();
    assert.equal(c.dialog, 'trash-page', 'pending deletion cannot be dismissed');
    c.onGlobalKeydown({ key: 'Escape' });
    assert.equal(c.dialog, 'trash-page', 'Escape also preserves a pending request');
    release();
    await pending;
    assert.equal(calls, 1);
    assert.equal(loads, 0);
    assert.equal(c.dialog, 'trash-page');
    assert.equal(c.busyAction, false);
    assert.equal(errors.at(-1).text, 'Dependency prevents trash');
    c.selected.add(42);
    c.request = async () => ({ ok: true });
    await c.submitTrashPage();
    assert.equal(loads, 1);
    assert.equal(c.selected.has(42), false);
    assert.equal(closed, 2);
});

test('trash cancellation returns to its preview and stale permissions refresh the listing', async () => {
    const page = { id: 42, can_delete: true };
    const c = instance({ dialog: 'preview', previewPage: page, routes: { trashPage: '/dam/pages' } });
    c.confirmTrashPage(page);
    c.cancelTrashPage();
    assert.equal(c.dialog, 'preview');
    assert.equal(c.previewPage, page);
    c.confirmTrashPage(page);
    let loads = 0;
    c.request = async () => ({ ok: false, status: 403, json: async () => ({ message: 'Permission changed' }) });
    c.notify = () => {};
    c.load = async () => { loads++; };
    await c.submitTrashPage();
    assert.equal(c.dialog, null);
    assert.equal(c.previewPage, null);
    assert.equal(loads, 1);
});

test('kebab menu toggles open and closed for a given card key', () => {
    const c = instance();
    assert.equal(c.openMenu, null);
    c.toggleMenu('page-5');
    assert.equal(c.openMenu, 'page-5');
    c.toggleMenu('page-5');
    assert.equal(c.openMenu, null);
    c.toggleMenu('page-5');
    c.toggleMenu('folder-2');
    assert.equal(c.openMenu, 'folder-2', 'opening another menu replaces the previous one, never stacks');
});

test('drag payload for a page card carries type, ids and the mothership mime type', () => {
    const c = instance();
    let stored = null;
    const event = { dataTransfer: { setData: (type, value) => { stored = { type, value }; } } };
    c.onPageDragStart(event, { id: 42 });
    assert.equal(stored.type, 'application/x-mothership-web-move');
    assert.deepEqual(JSON.parse(stored.value), { type: 'page', ids: [42] });
});

test('drag payload for a multi-selected page carries every selected id, not just the dragged one', () => {
    const c = instance({ selectMode: true, selected: new Set([1, 2, 42]) });
    let stored = null;
    const event = { dataTransfer: { setData: (type, value) => { stored = JSON.parse(value); } } };
    c.onPageDragStart(event, { id: 42 });
    assert.deepEqual(stored.ids.sort(), [1, 2, 42]);
});

test('drag payload for a folder card carries type folder and its own id', () => {
    const c = instance();
    let stored = null;
    c.onFolderDragStart({ dataTransfer: { setData: (type, value) => { stored = JSON.parse(value); } } }, { id: 7 });
    assert.deepEqual(stored, { type: 'folder', ids: [7] });
});

test('onDrop reads the passed event, not window.event, so a drop resolves without a global event', async () => {
    const c = instance();
    let moveCall = null;
    c.movePages = async (ids, collectionId) => { moveCall = { ids, collectionId }; return { placed: ids, rejected: [] }; };
    c.load = async () => {};
    const event = { dataTransfer: { getData: () => JSON.stringify({ type: 'page', ids: [11] }) } };
    await c.onDrop(event, 9);
    // moveCall.ids is an Array constructed inside the vm-sandboxed component script (a
    // different realm), so assert.deepEqual's prototype check on the array itself would fail
    // even though the values match; compare the JSON-serialised shape instead.
    assert.equal(JSON.stringify(moveCall), JSON.stringify({ ids: [11], collectionId: 9 }), 'onDrop must read event.dataTransfer, not a global window.event');
});

test('move dialog disables an entire moved-folder subtree, not just the direct target', () => {
    const c = instance();
    c.moveTree = c.flattenTree([{ id: 1, name: 'A', children: [{ id: 2, name: 'B', children: [] }] }]);
    c.dialogTarget = { id: 1 };
    const nodeA = c.moveTree.find((node) => node.id === 1);
    const nodeB = c.moveTree.find((node) => node.id === 2);
    assert.equal(c.moveDisabled(nodeA), true, 'the moved folder itself is disabled');
    assert.equal(c.moveDisabled(nodeB), true, 'a descendant of the moved folder is also disabled');
});

test('without dam.pages.organize, move controls and card dragging are gated off', () => {
    const c = instance({ can: { organize: false, share: true, upload: true, download: true } });
    assert.equal(c.showMoveControls, false);
    assert.equal(c.cardsDraggable, false);
});

test('select-mode toolbar text reflects the selected count and clears on toggle off', () => {
    const c = instance();
    assert.equal(c.selectMode, false);
    c.toggleSelectMode();
    assert.equal(c.selectMode, true);
    c.toggleSelect(1);
    c.toggleSelect(2);
    assert.equal(c.selected.size, 2);
    assert.equal(c.trans(c.labels.browser.selected, { count: c.selected.size }), '2 selected');
    c.toggleSelectMode();
    assert.equal(c.selectMode, false);
    assert.equal(c.selected.size, 0, 'turning select mode off clears the selection');
});

const okJson = (body) => ({ ok: true, status: 200, json: async () => body });
const emptyListing = (collection, extra = {}) => ({ collection, breadcrumb: [], children: [], pages: { data: [], current_page: 1, last_page: 1, total: 0 }, search_mode: false, ...extra });

test('load() applies only the most recent response: a slow earlier request cannot overwrite a newer one', async () => {
    const c = instance();
    const pending = [];
    c.request = (url) => new Promise((resolve) => { pending.push({ url, resolve }); });
    const first = c.load({ collection: 3 });
    const second = c.load({ collection: 9 });
    assert.equal(pending.length, 2);
    // The newer request answers first with folder 9; the stale one answers later with folder 3.
    pending[1].resolve(okJson(emptyListing(9)));
    await second;
    pending[0].resolve(okJson(emptyListing(3, { pages: { data: [{ id: 1 }], current_page: 1, last_page: 1, total: 1 } })));
    await first;
    assert.equal(c.collection, 9, 'the stale response must be ignored');
    assert.equal(c.pages.data.length, 0);
    assert.equal(c.busy, false);
});

test('load() surfaces a failed request instead of leaving stale data silently', async () => {
    const c = instance();
    c.request = async () => ({ ok: false, status: 500, json: async () => ({}) });
    await c.load();
    assert.equal(c.loadError, true);
    assert.equal(c.busy, false);
});

test('opening a folder pushes a history entry and popstate reloads that level without pushing again', async () => {
    const c = instance();
    const pushes = [];
    const replaces = [];
    c.historyApi = () => ({ pushState: (state, title, url) => pushes.push({ state, url }), replaceState: (state, title, url) => replaces.push({ state, url }) });
    c.request = async (url) => okJson(emptyListing(/collection=7/.test(url) ? 7 : null));
    await c.openCollection(7);
    assert.equal(pushes.length, 1);
    assert.equal(pushes[0].url, '?collection=7');
    assert.equal(pushes[0].state.webPages.collection, 7);
    await c.onPopState({ state: { webPages: { collection: null, page: 1 } } });
    assert.equal(c.collection, null);
    assert.equal(pushes.length, 1, 'popstate must not push a second entry');
});

test('history entries carry owner, q and sort, and popstate restores them before reloading', async () => {
    const c = instance();
    const pushes = [];
    const requests = [];
    c.historyApi = () => ({ pushState: (state, title, url) => pushes.push({ state, url }), replaceState: () => {} });
    c.request = async (url) => { requests.push(url); return okJson(emptyListing(/collection=7/.test(url) ? 7 : null)); };
    c.filters.owner = 'me';
    c.filters.sort = 'name_asc';
    await c.openCollection(7);
    assert.equal(pushes[0].url, '?collection=7&owner=me&sort=name_asc');
    assert.deepEqual({ ...pushes[0].state.webPages }, { collection: 7, page: 1, owner: 'me', q: '', sort: 'name_asc' });
    // Back to an entry recorded with the default filters: the component must not keep owner/sort.
    await c.onPopState({ state: { webPages: { collection: null, page: 1, owner: 'everyone', q: 'draft', sort: 'newest' } } });
    assert.equal(c.filters.owner, 'everyone');
    assert.equal(c.filters.sort, 'newest');
    assert.equal(c.filters.q, 'draft');
    assert.equal(requests[requests.length - 1], '/dam/pages/listing?q=draft');
});

test('a failed folder creation is toasted as well as shown inline', async () => {
    const c = instance();
    const notices = [];
    c.notify = (type, message) => notices.push({ type, message });
    c.postJson = async () => ({ ok: false, json: async () => ({ message: 'Exists' }) });
    c.dialogName = 'Notes';
    await c.submitNewFolder();
    assert.equal(c.dialogError, 'Exists');
    assert.equal(JSON.stringify(notices), JSON.stringify([{ type: 'error', message: 'Exists' }]));
});

test('upload toasts describe only the current batch: a retry after a failed upload does not re-raise the old error', async () => {
    const c = instance();
    const notices = [];
    c.notify = (type, message) => notices.push(`${type}:${message}`);
    c.load = async () => {};
    c.schedulePollIfPreparing = () => {};
    c.csrf = () => 'stub';
    c.request = async () => ({ ok: false, json: async () => ({ message: 'Too large' }) });
    await c.onUploadFiles([{ name: 'big.zip' }]);
    assert.deepEqual(notices, ['error:Too large']);
    c.request = async () => ({ ok: true, json: async () => ({ files: [{ id: 5 }] }) });
    await c.onUploadFiles([{ name: 'ok.html' }]);
    assert.deepEqual(notices.slice(1), ['success:Uploaded 1 pages']);
    assert.equal(c.uploadRows.length, 2, 'earlier rows stay visible in the dialog');
});

test('copyShare with no working clipboard never claims success: it opens the link dialog with the URL', async () => {
    const c = instance();
    const notices = [];
    c.notify = (type, message) => notices.push(type);
    c.$nextTick = () => {};
    await c.copyShare({ id: 1, active_share_token: 'tok' });
    assert.deepEqual(notices, []);
    assert.equal(c.shareUrl, 'https://dam.example.test/share/tok');
});

test('copyShare without a visible link asks first, and only the confirmed create posts the opt-in with 30 days', async () => {
    const c = instance();
    const posts = [];
    const notices = [];
    c.notify = (type) => notices.push(type);
    c.$nextTick = () => {};
    c.postJson = async (url, body) => { posts.push(body); return { ok: true, json: async () => ({ share: { token: 'new-token' } }) }; };
    const page = { id: 7, active_share_token: null };

    await c.copyShare(page);
    assert.deepEqual(posts, [], 'nothing is created before the viewer confirms');
    assert.equal(c.shareConfirmPage, page);

    c.cancelShareConfirm();
    assert.equal(c.shareConfirmPage, null);
    assert.deepEqual(posts, []);

    await c.copyShare(page);
    await c.createShare();
    assert.equal(JSON.stringify(posts), JSON.stringify([{ share_type: 'asset', target_id: 7, public_link_confirmed: true, expiry_days: 30 }]));
    assert.equal(c.shareUrl, 'https://dam.example.test/share/new-token');
});

test('the sort filter travels in the listing query and is omitted at its default', () => {
    const c = instance();
    assert.equal(c.listingQuery({}).toString(), '');
    c.filters.sort = 'name_asc';
    assert.equal(c.listingQuery({}).toString(), 'sort=name_asc');
    // Spread into a host-realm array: the component's arrays come from the vm sandbox.
    assert.deepEqual([...c.sortOptions.map((option) => option.value)], ['newest', 'oldest', 'name_asc', 'name_desc']);
});

test('startRename focuses the rename input through its function ref, not the dialog ref', () => {
    const c = instance();
    let focused = false;
    let selected = false;
    c.$nextTick = (fn) => fn();
    c.setRenameRef({ focus: () => { focused = true; }, select: () => { selected = true; } });
    c.startRename({ id: 4, name: 'Notes', can_modify: true });
    assert.equal(c.renaming, 4);
    assert.equal(focused && selected, true);
});

const folder = (extra = {}) => ({ id: 7, name: 'Notes', can_modify: true, can_delete: true, ...extra });

test('folder rename, move and delete follow the per-folder flags, and global organize still gates them', () => {
    const c = instance();
    c.$nextTick = (fn) => fn();
    assert.equal(c.folderMayModify(folder()), true);
    assert.equal(c.folderMayDelete(folder()), true);
    assert.equal(c.folderMayModify(folder({ can_modify: false })), false, 'another creator\'s group is not offered rename/move');
    assert.equal(c.folderMayDelete(folder({ can_delete: false })), false, 'a group holding others\' items is not offered delete');
    assert.equal(c.folderMayModify({ id: 7, name: 'Notes' }), false, 'a missing flag fails closed');
    c.startRename(folder({ can_modify: false }));
    assert.equal(c.renaming, null, 'F2 / double-click cannot start a rename the service would refuse');
    const readOnly = instance({ can: { organize: false, share: true, upload: true, download: true } });
    assert.equal(readOnly.folderMayModify(folder()), false);
    assert.equal(readOnly.folderMayDelete(folder()), false);
});

function menuEvent(extra = {}) {
    const calls = { prevented: 0, stopped: 0 };
    const card = { querySelector: () => ({ focus() { calls.focused = true; } }) };
    return { calls, event: { target: card, currentTarget: card, preventDefault: () => { calls.prevented++; }, stopPropagation: () => { calls.stopped++; }, ...extra }, card };
}

test('right-click on a folder opens its menu without opening or renaming it', () => {
    const c = instance();
    c.$nextTick = (fn) => fn();
    let opened = null;
    c.openCollection = (id) => { opened = id; };
    const { calls, event, card } = menuEvent();
    c.onFolderContextMenu(event, folder());
    assert.equal(c.openMenu, 'folder-7');
    assert.equal(c.menuOpener, card);
    assert.equal(calls.prevented, 1, 'the browser menu is replaced');
    assert.equal(calls.stopped, 1, 'the document dismiss listener does not close it again');
    assert.equal(calls.focused, true, 'focus moves into the menu');
    assert.equal(c.renaming, null);
    assert.equal(opened, null);
});

test('the rename field keeps the browser context menu', () => {
    const c = instance();
    const { calls, event } = menuEvent();
    event.target = { tagName: 'INPUT' };
    c.onFolderContextMenu(event, folder());
    assert.equal(calls.prevented, 0);
    assert.equal(c.openMenu, null);
});

test('ctrl+click on a Mac is the OS right-click: it does not open the folder and cannot reach the document dismiss listener', () => {
    const opened = [];
    for (const [platform, ctrlOpens] of [['MacIntel', false], ['Win32', true]]) {
        const c = instance({}, platform);
        c.openCollection = (id) => opened.push([platform, id]);
        let stopped = 0;
        c.onFolderClick({ button: 0, ctrlKey: true, stopPropagation: () => { stopped++; } }, folder());
        assert.equal(stopped, ctrlOpens ? 0 : 1);
    }
    assert.equal(JSON.stringify(opened), JSON.stringify([['Win32', 7]]));
});

test('a folder menu dismissed by an outside click leaves no stale card: a later page menu\'s Escape does not refocus it', () => {
    const c = instance();
    c.$nextTick = (fn) => fn();
    let refocused = 0;
    c.menuOpener = { isConnected: true, focus: () => { refocused++; } };
    c.openMenu = 'folder-7';
    c.closeMenu();
    assert.equal(c.menuOpener, null);
    c.menuOpener = { isConnected: true, focus: () => { refocused++; } };
    c.toggleMenu('page-5');
    assert.equal(c.menuOpener, null, 'opening a page menu drops any folder opener');
    c.onGlobalKeydown({ key: 'Escape', target: {} });
    assert.equal(c.openMenu, null);
    assert.equal(refocused, 0);
});

test('a secondary-button click does not open a folder, a primary click does', () => {
    const c = instance();
    const opened = [];
    c.openCollection = (id) => opened.push(id);
    c.onFolderClick({ button: 2, stopPropagation() {} }, folder());
    assert.equal(opened.length, 0);
    c.onFolderClick({ button: 0 }, folder());
    assert.equal(opened.length, 1);
});

test('ContextMenu and Shift+F10 on a focused folder card open its menu; Enter still opens the folder', () => {
    const c = instance();
    c.$nextTick = (fn) => fn();
    let opened = null;
    c.openCollection = (id) => { opened = id; };
    for (const key of [{ key: 'ContextMenu' }, { key: 'F10', shiftKey: true }]) {
        c.openMenu = null;
        const { calls, event } = menuEvent(key);
        event.target = event.currentTarget;
        c.onCardKeydown(event, { type: 'folder', item: folder() });
        assert.equal(c.openMenu, 'folder-7');
        assert.equal(calls.prevented, 1);
    }
    c.openMenu = null;
    const { event } = menuEvent({ key: 'F10', shiftKey: false });
    event.target = event.currentTarget;
    c.onCardKeydown(event, { type: 'folder', item: folder() });
    assert.equal(c.openMenu, null, 'plain F10 is not the menu key');
    const enter = menuEvent({ key: 'Enter' });
    enter.event.target = enter.event.currentTarget;
    c.onCardKeydown(enter.event, { type: 'folder', item: folder() });
    assert.equal(opened, 7);
});

test('Escape closes a folder menu and returns focus to the card; Tab does the same', () => {
    const c = instance();
    c.$nextTick = (fn) => fn();
    let refocused = 0;
    const card = { isConnected: true, focus: () => { refocused++; } };
    c.menuOpener = card;
    c.openMenu = 'folder-7';
    c.onGlobalKeydown({ key: 'Escape', target: {} });
    assert.equal(c.openMenu, null);
    assert.equal(refocused, 1);
    c.menuOpener = card;
    c.openMenu = 'folder-7';
    let prevented = false;
    c.onFolderMenuKeydown({ key: 'Tab', target: {}, currentTarget: { querySelectorAll: () => [] }, preventDefault: () => { prevented = true; } });
    assert.equal(c.openMenu, null);
    assert.equal(prevented && refocused === 2, true);
});

test('the folder menu arrow keys cycle its items', () => {
    const c = instance();
    const focus = [];
    const items = [0, 1, 2].map((n) => ({ focus: () => focus.push(n) }));
    const currentTarget = { querySelectorAll: () => items };
    c.onFolderMenuKeydown({ key: 'ArrowDown', target: items[2], currentTarget, preventDefault() {} });
    c.onFolderMenuKeydown({ key: 'ArrowUp', target: items[0], currentTarget, preventDefault() {} });
    c.onFolderMenuKeydown({ key: 'Home', target: items[1], currentTarget, preventDefault() {} });
    assert.deepEqual(focus, [0, 2, 0]);
});

test('cancelling a rename hands focus back to the folder card', () => {
    const c = instance();
    c.$nextTick = (fn) => fn();
    let focused = false;
    c.$el = { querySelector: (selector) => (selector === '[data-web-collection="7"]' ? { focus: () => { focused = true; } } : null) };
    c.renaming = 7;
    c.cancelRename();
    assert.equal(c.renaming, null);
    assert.equal(focused, true);
});

test('saveRename reports a failed rename through notify instead of swallowing it', async () => {
    const c = instance();
    const notices = [];
    c.notify = (type, message) => notices.push({ type, message });
    c.patchJson = async () => ({ ok: false, json: async () => ({ message: 'Taken' }) });
    c.load = async () => {};
    c.renaming = 4;
    await c.saveRename({ target: { value: 'Other' } }, { id: 4, name: 'Notes' });
    assert.equal(JSON.stringify(notices), JSON.stringify([{ type: 'error', message: 'Taken' }]));
});

test('pageRange reads "from–to of total" from the paginator', () => {
    const c = instance();
    c.pages = { data: new Array(24).fill({}), current_page: 2, last_page: 3, total: 57 };
    assert.equal(c.pageRange, 'Showing 25–48 of 57');
    c.pages = { data: new Array(9).fill({}), current_page: 3, last_page: 3, total: 57 };
    assert.equal(c.pageRange, 'Showing 49–57 of 57');
});

test('a desktop file drag is recognised and an internal card drag is not', () => {
    const c = instance();
    assert.equal(c.isFileDrag({ dataTransfer: { types: ['Files'] } }), true);
    assert.equal(c.isFileDrag({ dataTransfer: { types: ['application/x-mothership-web-move'] } }), false);
    c.onFileDragEnter({ dataTransfer: { types: ['Files'] } });
    assert.equal(c.fileDragDepth, 1);
    c.onFileDragLeave({ dataTransfer: { types: ['Files'] } });
    assert.equal(c.fileDragDepth, 0);
});

test('queueUploads keeps only .html/.htm/.zip and reports the rest', () => {
    const c = instance();
    const notices = [];
    c.notify = (type, message) => notices.push(message);
    c.queueUploads([{ name: 'a.html' }, { name: 'b.ZIP' }, { name: 'c.png' }]);
    assert.deepEqual([...c.uploadQueue.map((file) => file.name)], ['a.html', 'b.ZIP']);
    assert.equal(notices.length, 1);
});

// Selected current-file previews: the read-only member strip over a stack's files.
const sumOf = (letter) => letter.repeat(64);
const memberRows = [
    { attachment_id: 5, file_id: 50, checksum: sumOf('a'), name: 'site.zip', candidate: true, is_main: true },
    { attachment_id: 6, file_id: 60, checksum: sumOf('b'), name: 'second.zip', candidate: true, is_main: false },
    { attachment_id: 7, file_id: 70, checksum: sumOf('c'), name: 'notes.txt', candidate: false, is_main: false },
];

function deferred() {
    let resolve;
    let reject;
    const promise = new Promise((res, rej) => { resolve = res; reject = rej; });
    return { promise, resolve, reject };
}

const answer = (members) => ({ ok: true, json: async () => ({ members }) });

test('a stack of several files pins the representative as an exact member; one file keeps the legacy page', async () => {
    const c = instance({ previewPage: { id: 3 } });
    c.request = async () => answer(memberRows);
    await c.loadPreviewMembers({ id: 3 });
    assert.deepEqual([c.previewMembersState, c.previewMembers.length, c.previewPin.file, c.previewPin.checksum], ['ready', 3, 50, sumOf('a')]);
    assert.equal(c.previewMemberPosition, 'File 1 of 3');

    c.request = async () => answer(memberRows.slice(0, 1));
    await c.loadPreviewMembers({ id: 3 });
    assert.equal(c.previewPin, null, 'a one-file stack is the legacy preview');
    assert.equal(c.previewOpenUrl, '/dam/pages/3/open', 'no pin on the full screen url');
});

test('previous and next walk the stack in order and the frame, full screen and share link follow the displayed file', async () => {
    const c = instance({ previewPage: { id: 3, file_name: 'site.zip' }, dialog: 'preview' });
    c.request = async () => answer(memberRows);
    await c.loadPreviewMembers(c.previewPage);

    c.stepMember(1);
    assert.deepEqual([c.previewPin.attachment, c.previewMemberPosition], [6, 'File 2 of 3']);
    assert.equal(c.previewOpenUrl, `/dam/pages/3/open?file=60&checksum=${sumOf('b')}`);
    assert.equal(c.previewFrameKey, `web-3-60-${sumOf('b')}-0`);
    assert.equal(c.shareLinkFor(c.previewPage, 'tok'), `https://dam.example.test/share/tok?file=6&file_id=60&checksum=${sumOf('b')}`);

    c.stepMember(1);
    assert.equal(c.shareLinkFor(c.previewPage, 'tok'), 'https://dam.example.test/share/tok?file=7', 'a file that is no page keeps the slot-only link');
    c.stepMember(1);
    assert.equal(c.previewPin.attachment, 7, 'next stops at the last file');
    c.stepMember(-2);
    assert.equal(c.previewPin.attachment, 5);
    assert.match(c.shareLinkFor(c.previewPage, 'tok'), /file_id=50&checksum=a{64}$/, 'the explicitly pinned representative stays pinned');

    c.dialog = null;
    assert.equal(c.shareLinkFor(c.previewPage, 'tok'), 'https://dam.example.test/share/tok', 'a card-menu copy is the plain link');
});

test('a late members answer never repaints another page: A, then B, then A again, or a closed preview', async () => {
    const first = deferred();
    const second = deferred();
    const third = deferred();
    const queue = [first, second, third];
    const c = instance();
    c.request = () => queue.shift().promise;

    const a1 = c.loadPreviewMembers({ id: 1 });
    const b = c.loadPreviewMembers({ id: 2 });
    const a2 = c.loadPreviewMembers({ id: 1 });
    third.resolve(answer([{ ...memberRows[0], name: 'A-new' }, memberRows[1]]));
    await a2;
    second.resolve(answer([{ ...memberRows[0], name: 'B' }, memberRows[1]]));
    first.resolve(answer([{ ...memberRows[0], name: 'A-old' }, memberRows[1]]));
    await Promise.all([a1, b]);
    assert.deepEqual(c.previewMembers.map((m) => m.name), ['A-new', 'second.zip'], 'only the newest request paints');

    const late = deferred();
    c.request = () => late.promise;
    const pending = c.loadPreviewMembers({ id: 1 });
    c.resetPreviewMembers();
    late.resolve(answer(memberRows));
    await pending;
    assert.deepEqual([c.previewMembers.length, c.previewPin, c.previewMembersState], [0, null, 'idle'], 'closing invalidates the answer');
});

test('a changed file is reported once, only after the list answers, and never moves the display on its own', async () => {
    const c = instance({ previewPage: { id: 3 }, dialog: 'preview' });
    c.request = async () => answer(memberRows);
    await c.loadPreviewMembers(c.previewPage);
    c.selectMember(c.previewMembers[1]);
    const pin = c.previewPin;

    const revised = [memberRows[0], { ...memberRows[1], file_id: 61, checksum: sumOf('d') }, memberRows[2]];
    const late = deferred();
    let calls = 0;
    c.request = () => { calls++; return late.promise; };
    // What the server answers for the old file id of a revised slot is a 404: the frame can only say "not current".
    c.onMemberChanged({ detail: { kind: 'unavailable' } });
    c.onMemberChanged({ detail: { kind: 'unavailable' } });
    assert.equal(c.previewChange, 'checking', 'no claim about changed or removed while the one refresh is out');
    assert.equal(calls, 1, 'one refresh per displayed file');
    late.resolve(answer(revised));
    await new Promise((r) => setImmediate(r));
    assert.deepEqual([c.previewChange, c.previewPin === pin, c.previewPin.file, c.previewPin.checksum], ['changed', true, 60, sumOf('b')], 'the list says the slot now holds another file; the original pin is untouched');

    c.showCurrentMember();
    assert.deepEqual([c.previewPin.file, c.previewChange], [61, null], 'only the viewer chooses the current file');

    c.request = async () => answer([memberRows[0], memberRows[2]]);
    c.previewRefreshed = false;
    c.onMemberChanged({ detail: { kind: 'unavailable' } });
    assert.equal(c.previewChange, 'checking');
    await new Promise((r) => setImmediate(r));
    assert.equal(c.previewChange, 'unavailable', 'a removed file is unavailable, never another file');
    assert.equal(c.previewPin.attachment, 6);
    assert.equal(c.previewMemberIndex, -1);
    assert.equal(c.previewMemberPosition, '', 'never "File 0 of N"');
    assert.equal(c.previewPin.name, memberRows[1].name, 'the pinned file keeps its own name');
    c.showCurrentMember();
    assert.equal(c.previewPin.attachment, 6, 'a removed slot has no current file to show');
});

test('a refresh that fails or never settles claims nothing: the pin stays, the list stays, and the viewer may check again', async () => {
    const c = instance({ previewPage: { id: 3 }, dialog: 'preview' });
    c.request = async () => answer(memberRows);
    await c.loadPreviewMembers(c.previewPage);
    c.selectMember(c.previewMembers[1]);
    const pin = c.previewPin;
    const list = c.previewMembers;

    c.request = async () => ({ ok: false, status: 500 });
    c.onMemberChanged({ detail: { kind: 'unavailable' } });
    assert.equal(c.previewChange, 'checking');
    await new Promise((r) => setImmediate(r));
    assert.deepEqual([c.previewChange, c.previewPin === pin, c.previewMembers === list, c.previewMembersState], ['unknown', true, true, 'ready'], 'an unreadable answer is not evidence, and is not the "only the main page" state');

    // A thrown request is the same.
    c.request = async () => { throw new TypeError('network'); };
    c.checkMemberAgain();
    await new Promise((r) => setImmediate(r));
    assert.equal(c.previewChange, 'unknown');

    // Checking again is the viewer's click and reads once; it settles on evidence.
    let calls = 0;
    c.request = async () => { calls++; return answer([memberRows[0], memberRows[2]]); };
    c.checkMemberAgain();
    c.checkMemberAgain();
    await new Promise((r) => setImmediate(r));
    assert.deepEqual([calls, c.previewChange, c.previewPin === pin], [1, 'unavailable', true]);
    c.checkMemberAgain();
    assert.equal(calls, 1, 'nothing re-reads once the state is settled');
});

test('a late refresh for a file the viewer already left says nothing about the file they are on now', async () => {
    const c = instance({ previewPage: { id: 3 }, dialog: 'preview' });
    c.request = async () => answer(memberRows);
    await c.loadPreviewMembers(c.previewPage);
    c.selectMember(c.previewMembers[1]);

    const late = deferred();
    c.request = () => late.promise;
    c.onMemberChanged({ detail: { kind: 'unavailable' } });
    c.selectMember(c.previewMembers[2]);
    assert.equal(c.previewChange, null);
    late.resolve(answer([memberRows[0], memberRows[2]]));
    await new Promise((r) => setImmediate(r));
    assert.deepEqual([c.previewChange, c.previewPin.attachment], [null, 7]);

    const failing = deferred();
    c.request = () => failing.promise;
    c.previewRefreshed = false;
    c.onMemberChanged({ detail: { kind: 'unavailable' } });
    c.selectMember(c.previewMembers[0]);
    failing.reject(new TypeError('network'));
    await new Promise((r) => setImmediate(r));
    assert.deepEqual([c.previewChange, c.previewPin.attachment], [null, 5]);
});

test('a slot that is current again after the frame reported it gone is asked for afresh only when the viewer chooses', async () => {
    const c = instance({ previewPage: { id: 3 }, dialog: 'preview' });
    c.request = async () => answer(memberRows);
    await c.loadPreviewMembers(c.previewPage);
    c.selectMember(c.previewMembers[1]);
    const key = c.previewFrameKey;
    c.onMemberChanged({ detail: { kind: 'unavailable' } });
    await new Promise((r) => setImmediate(r));
    assert.equal(c.previewChange, 'changed');
    c.showCurrentMember();
    assert.deepEqual([c.previewChange, c.previewPin.file, c.previewFrameKey === key], [null, 60, false], 'the same file is re-asked through a remounted frame');
});

test('full screen is offered only for a displayed file that opens as a page: not one that changed, went away, is being checked or is no page', async () => {
    const c = instance({ previewPage: { id: 3, state: 'ready' }, dialog: 'preview' });
    c.request = async () => answer(memberRows.slice(0, 1));
    await c.loadPreviewMembers(c.previewPage);
    assert.equal(c.previewOpens, true, 'a one-file stack is the legacy page');

    c.request = async () => answer(memberRows);
    await c.loadPreviewMembers(c.previewPage);
    assert.equal(c.previewOpens, true, 'the pinned representative is a current page candidate');
    c.stepMember(1);
    assert.equal(c.previewOpens, true);
    c.stepMember(1);
    assert.deepEqual([c.previewPin.attachment, c.previewPin.candidate, c.previewOpens], [7, false, false], 'a selected file the server lists as no page cannot be opened full screen');
    c.stepMember(-1);
    assert.equal(c.previewOpens, true, 'choosing a page again restores it');
    for (const kind of ['checking', 'changed', 'unavailable', 'unknown']) {
        c.previewChange = kind;
        assert.equal(c.previewOpens, false, kind);
    }
});

test('no link is pinned to a file that changed: Copy share link creates and copies nothing while the displayed file does not open', async () => {
    const c = instance({ previewPage: { id: 3, state: 'ready', active_share_token: null }, dialog: 'preview' });
    const posts = [];
    c.notify = () => {};
    c.$nextTick = () => {};
    c.postJson = async (url, body) => { posts.push(body); return { ok: true, json: async () => ({ share: { token: 'new-token' } }) }; };
    c.request = async () => answer(memberRows);
    await c.loadPreviewMembers(c.previewPage);
    c.stepMember(1);

    // Control: a current file asks first, exactly as before.
    await c.copyShare(c.previewPage);
    assert.equal(c.shareConfirmPage, c.previewPage);
    c.cancelShareConfirm();

    // Changed: no confirmation, no request, no link, whether or not the page already has a public link.
    c.previewChange = 'changed';
    await c.copyShare(c.previewPage);
    c.previewPage.active_share_token = 'tok';
    await c.copyShare(c.previewPage);
    assert.deepEqual([c.shareConfirmPage, posts.length, c.shareUrl], [null, 0, null]);

    // The file changes while the confirmation is open: confirming makes nothing.
    c.previewChange = null;
    c.previewPage.active_share_token = null;
    await c.copyShare(c.previewPage);
    assert.equal(c.shareConfirmPage, c.previewPage);
    c.previewChange = 'unavailable';
    await c.createShare();
    assert.deepEqual([c.shareConfirmPage, posts.length, c.shareUrl], [null, 0, null]);

    // It changes while the request is out: the link exists, but no stale pin is handed over.
    c.previewChange = null;
    await c.copyShare(c.previewPage);
    c.postJson = async (url, body) => { posts.push(body); c.previewChange = 'changed'; return { ok: true, json: async () => ({ share: { token: 'new-token' } }) }; };
    await c.createShare();
    assert.deepEqual([posts.length, c.previewPage.active_share_token, c.shareUrl], [1, 'new-token', null]);

    // Once the viewer chooses a current file the pinned link is theirs again; a card-menu copy never depended on the preview.
    c.previewChange = null;
    await c.copyShare(c.previewPage);
    assert.equal(c.shareUrl, `https://dam.example.test/share/new-token?file=6&file_id=60&checksum=${sumOf('b')}`);
    c.shareUrl = null;
    c.previewChange = 'changed';
    c.dialog = null;
    await c.copyShare({ id: 9, active_share_token: 'card' });
    assert.equal(c.shareUrl, 'https://dam.example.test/share/card');
});

test('a failed members read says so and keeps the legacy page', async () => {
    const c = instance();
    c.request = async () => ({ ok: false, status: 500 });
    await c.loadPreviewMembers({ id: 3 });
    assert.deepEqual([c.previewMembersState, c.previewPin], ['failed', null]);
});


// ── Share pages in folder ──────────────────────────────────────────────────────────────────────────────

const jsonAnswer = (status, body) => ({ ok: status >= 200 && status < 300, status, json: async () => body });
const candidatesBody = (pages, extra = {}) => ({ success: true, collection: 7, cap: 100, total: pages.length, omitted: 0, pages, ...extra });
const candidatePage = (id, extra = {}) => ({ id, display_name: `Page ${id}`, revision: `rev${id}`.padEnd(64, '0'), reuse: null, ...extra });

function shareFolderInstance({ pages, answers }) {
    const c = instance({ dialog: null });
    const log = { gets: [], posts: [], notices: [] };
    c.$nextTick = (fn) => fn?.();
    c.notify = (type, message) => log.notices.push({ type, message });
    c.load = async () => { log.loads = (log.loads || 0) + 1; };
    c.request = async (url) => { log.gets.push(url); return pages instanceof Error ? Promise.reject(pages) : jsonAnswer(200, candidatesBody(pages)); };
    let index = 0;
    c.postJson = async (url, body) => {
        log.posts.push({ url, body });
        const answer = answers[index++];
        if (answer instanceof Error) throw answer;
        return answer;
    };
    return { c, log };
}

test('Share pages in folder is offered only where links can be made and the folder lists pages', () => {
    const c = instance();
    assert.equal(c.folderMayShare({ id: 7, page_count: 2 }), true);
    assert.equal(c.folderMayShare({ id: 7, page_count: 0 }), false, 'nothing visible to share');
    assert.equal(c.folderMayShare({ id: 7 }), false, 'a missing count fails closed');
    assert.equal(instance({ can: { organize: true, share: false, upload: true, download: true } }).folderMayShare({ id: 7, page_count: 2 }), false);
    assert.equal(instance({ can: { organize: false, share: true, upload: true, download: true } }).folderMayShare({ id: 7, page_count: 2 }), true, 'sharing is not an organizer right');
});

test('opening Share pages in folder only asks which pages; cancelling creates nothing', async () => {
    const { c, log } = shareFolderInstance({ pages: [candidatePage(1), candidatePage(2, { reuse: { expires_at: null, has_password: false, allow_download: true } })], answers: [] });

    await c.openShareFolder({ id: 7, name: 'Launch' });

    assert.deepEqual(log.gets, ['/dam/shared-links/web-collections/7/candidates']);
    assert.equal(c.dialog, 'share-folder');
    assert.equal(c.folderShare.state, 'confirm');
    assert.equal(c.shareFolderCountLabel, 'Share 2 pages');
    assert.equal(c.folderShare.keepCount, 1);
    c.cancelShareFolder();
    assert.equal(c.dialog, null);
    assert.equal(c.folderShare, null);
    assert.deepEqual(log.posts, [], 'no link request before the confirmation, and none after a cancel');
});

test('an empty, failed or capped candidate answer says so and never starts a run', async () => {
    for (const [pages, state] of [[[], 'empty'], [new Error('offline'), 'failed']]) {
        const { c, log } = shareFolderInstance({ pages, answers: [] });
        await c.openShareFolder({ id: 7, name: 'Launch' });
        assert.equal(c.folderShare.state, state);
        await c.startShareFolder();
        assert.deepEqual(log.posts, []);
    }
    const capped = shareFolderInstance({ pages: [candidatePage(1)], answers: [] });
    capped.c.request = async () => jsonAnswer(200, candidatesBody([candidatePage(1)], { total: 4, omitted: 3 }));
    await capped.c.openShareFolder({ id: 7, name: 'Launch' });
    assert.equal(capped.c.folderShare.omitted, 3, 'the pages the cap left out are carried to the confirmation');
    const refused = shareFolderInstance({ pages: [], answers: [] });
    refused.c.request = async () => jsonAnswer(403, { message: 'no' });
    await refused.c.openShareFolder({ id: 7, name: 'Launch' });
    assert.equal(refused.c.folderShare.state, 'failed');
});

test('the confirmed run posts one ordinary 30-day link per page in order and reports each page exactly', async () => {
    const answers = [
        jsonAnswer(200, { success: true, share: { token: 'tok-one' }, reused: false }),
        jsonAnswer(200, { success: true, share: { token: 'tok-two', expires_at: '2027-01-02T00:00:00+00:00', has_password: true }, reused: true }),
        jsonAnswer(409, { reason: 'page-changed' }),
        jsonAnswer(404, { message: 'gone' }),
        jsonAnswer(422, { message: 'bad' }),
    ];
    const { c, log } = shareFolderInstance({ pages: [1, 2, 3, 4, 5].map((id) => candidatePage(id)), answers });
    await c.openShareFolder({ id: 7, name: 'Launch' });
    await c.startShareFolder();

    assert.equal(JSON.stringify(log.posts.map((post) => post.body)), JSON.stringify([1, 2, 3, 4, 5].map((id) => ({ share_type: 'asset', target_id: id, web_collection_id: 7, page_revision: `rev${id}`.padEnd(64, '0'), public_link_confirmed: true, expiry_days: 30 }))));
    assert.deepEqual(log.posts.map((post) => post.url), Array(5).fill('/dam/shared-links'));
    assert.deepEqual(c.folderShare.rows.map((row) => row.status), ['created', 'kept', 'changed', 'not-allowed', 'failed']);
    assert.equal(c.folderShare.state, 'done');
    assert.equal(c.shareFolderSummary, '1 new, 1 kept, 3 not shared');
    assert.match(c.shareFolderRowLabel(c.folderShare.rows[1]), /Existing link kept \(expires .*, password protected\)/);
    assert.equal(c.folderShare.linksText, 'Page 1\nhttps://dam.example.test/share/tok-one\n\nPage 2\nhttps://dam.example.test/share/tok-two');
    assert.equal(log.notices.at(-1).type, 'warning', 'a partial result is never toasted as success');
    assert.equal(log.loads, 1, 'the listing refreshes once so the Shared badges follow');
});

test('an answer that never came back stops the run, is not retried, and leaves the rest untouched', async () => {
    const { c, log } = shareFolderInstance({ pages: [1, 2, 3].map((id) => candidatePage(id)), answers: [jsonAnswer(200, { share: { token: 'a' }, reused: false }), new Error('network down')] });
    await c.openShareFolder({ id: 7, name: 'Launch' });
    await c.startShareFolder();

    assert.equal(log.posts.length, 2, 'the uncertain write is not repeated and the third page is not attempted');
    assert.deepEqual(c.folderShare.rows.map((row) => row.status), ['created', 'uncertain', 'not-attempted']);
    assert.equal(c.folderShare.stopped, true);
    assert.equal(c.shareFolderSummary, '1 new, 0 kept, 1 not shared, 1 may exist', 'an uncertain write is counted apart, never as not shared');
    assert.equal(log.notices.at(-1).type, 'warning');

    const serverError = shareFolderInstance({ pages: [1, 2].map((id) => candidatePage(id)), answers: [jsonAnswer(500, {})] });
    await serverError.c.openShareFolder({ id: 7, name: 'Launch' });
    await serverError.c.startShareFolder();
    assert.deepEqual(serverError.c.folderShare.rows.map((row) => row.status), ['uncertain', 'not-attempted']);
    assert.equal(serverError.log.posts.length, 1);
});

// A request that genuinely never settles: it ignores the signal and is only ever resolved by the test, after the run has moved on.
const hangingPost = (log, { honourSignal = false } = {}) => {
    const late = [];
    const post = (url, body, options = {}) => new Promise((resolve, reject) => {
        log.posts.push(body);
        log.signals = [...(log.signals || []), options.signal];
        late.push(() => resolve(jsonAnswer(200, { share: { token: `late${body.target_id}` }, reused: false })));
        if (honourSignal) options.signal?.addEventListener('abort', () => reject(Object.assign(new Error('Aborted'), { name: 'AbortError' })), { once: true });
    });

    return { post, answerLate: () => late.forEach((answer) => answer()) };
};
const flush = () => new Promise((resolve) => setImmediate(resolve));

test('Stop and Escape recover a request that never answers: its page may exist, nothing is retried, the rest are not started', async () => {
    for (const honourSignal of [false, true]) {
        const { c, log } = shareFolderInstance({ pages: [1, 2, 3].map((id) => candidatePage(id)), answers: [] });
        const hanging = hangingPost(log, { honourSignal });
        c.postJson = hanging.post;
        await c.openShareFolder({ id: 7, name: 'Launch' });
        const running = c.startShareFolder();
        await flush();

        assert.equal(c.folderShare.state, 'sharing');
        assert.equal(log.posts.length, 1, 'a genuinely pending request, not an error');
        assert.equal(log.signals[0].aborted, false);
        c.onGlobalKeydown({ key: 'Escape', target: {} });
        assert.equal(c.dialog, 'share-folder', 'Escape cannot dismiss a run in progress');
        assert.equal(log.signals[0].aborted, true, 'the request in flight is abandoned, not waited for');
        await running;
        // The same Escape reaches the dialog's own cancel event after the run has already ended: it must not close the result.
        c.cancelShareFolder();
        c.closeDialog();
        assert.equal(c.dialog, 'share-folder', 'the Escape that stopped the run does not also dismiss its result');
        assert.equal(c.folderShare.state, 'done');
        await new Promise((resolve) => setTimeout(resolve, 5));

        assert.deepEqual(c.folderShare.rows.map((row) => row.status), ['uncertain', 'not-attempted', 'not-attempted']);
        assert.equal(c.folderShare.state, 'done');
        assert.equal(c.folderShare.stopped, true);
        assert.equal(log.posts.length, 1, 'no retry and no next POST');
        assert.equal(c.shareFolderSummary, '0 new, 0 kept, 2 not shared, 1 may exist');

        // The late answer of the abandoned request is never read: no link, no row change, no extra write.
        hanging.answerLate();
        await flush();
        assert.deepEqual(c.folderShare.rows.map((row) => row.status), ['uncertain', 'not-attempted', 'not-attempted']);
        assert.equal(c.folderShare.linksText, '');
        assert.equal(log.posts.length, 1);
        assert.equal(log.loads ?? 0, 0, 'nothing was made, so the listing is not refreshed');

        // Closing and opening again starts a clean run; the old one cannot touch it.
        c.cancelShareFolder();
        assert.equal(c.dialog, null);
        await c.openShareFolder({ id: 7, name: 'Launch' });
        assert.equal(c.folderShare.state, 'confirm');
        assert.deepEqual(c.folderShare.rows.map((row) => row.status), ['pending', 'pending', 'pending']);
        hanging.answerLate();
        await flush();
        assert.deepEqual(c.folderShare.rows.map((row) => row.status), ['pending', 'pending', 'pending']);
        assert.equal(log.posts.length, 1);
    }
});

test('Stop between pages, with no request in flight, only prevents the next page: nothing is marked as possibly existing', async () => {
    const { c, log } = shareFolderInstance({ pages: [1, 2, 3].map((id) => candidatePage(id)), answers: [] });
    c.postJson = async (url, body) => {
        log.posts.push(body);

        return jsonAnswer(200, { share: { token: `t${body.target_id}` }, reused: false });
    };
    // The first page is fully answered and read; the viewer presses Stop in the gap before the second is started.
    const link = c.shareLinkFor;
    c.shareLinkFor = (row, token) => { c.cancelShareFolder(); return link(row, token); };
    await c.openShareFolder({ id: 7, name: 'Launch' });
    await c.startShareFolder();

    assert.deepEqual(c.folderShare.rows.map((row) => row.status), ['created', 'not-attempted', 'not-attempted']);
    assert.equal(log.posts.length, 1);
    assert.equal(c.folderShare.stopped, true);
    assert.equal(c.shareFolderSummary, '1 new, 0 kept, 2 not shared', 'no page in flight, so none is reported as possibly existing');
    assert.equal(c.folderShare.linksText, 'Page 1\nhttps://dam.example.test/share/t1');
});

// Headers arrive, the body never does: the answer is read as far as its status and no further.
const hangingBody = (log) => {
    const late = [];
    const post = (url, body, options = {}) => {
        log.posts.push(body);
        log.signals = [...(log.signals || []), options.signal];

        return Promise.resolve({ ok: true, status: 200, json: () => new Promise((resolve) => late.push(() => resolve({ share: { token: `late${body.target_id}` }, reused: false }))) });
    };

    return { post, answerLate: () => late.forEach((answer) => answer()) };
};

test('a response whose body never finishes is recovered by Stop and by Escape like one that never answers; the late body is ignored', async () => {
    for (const how of ['stop', 'escape']) {
        const { c, log } = shareFolderInstance({ pages: [1, 2, 3].map((id) => candidatePage(id)), answers: [] });
        const hanging = hangingBody(log);
        c.postJson = hanging.post;
        await c.openShareFolder({ id: 7, name: 'Launch' });
        const running = c.startShareFolder();
        await flush();

        assert.equal(c.folderShare.state, 'sharing');
        assert.equal(log.posts.length, 1, 'the headers are in and the body is pending');
        assert.equal(log.signals[0].aborted, false);
        if (how === 'stop') c.cancelShareFolder(); else c.onGlobalKeydown({ key: 'Escape', target: {} });
        assert.equal(log.signals[0].aborted, true, 'the pending body is abandoned, not waited for');
        await running;
        await new Promise((resolve) => setTimeout(resolve, 5));

        assert.deepEqual(c.folderShare.rows.map((row) => row.status), ['uncertain', 'not-attempted', 'not-attempted'], 'a write whose answer was never read is possibly made, never retried');
        assert.equal(c.folderShare.state, 'done');
        assert.equal(log.posts.length, 1, 'no retry and no next POST');
        hanging.answerLate();
        await flush();
        assert.deepEqual(c.folderShare.rows.map((row) => row.status), ['uncertain', 'not-attempted', 'not-attempted'], 'the late body changes nothing');
        assert.equal(c.folderShare.linksText, '');
        assert.equal(log.posts.length, 1);

        c.cancelShareFolder();
        assert.equal(c.dialog, null);
        await c.openShareFolder({ id: 7, name: 'Launch' });
        assert.deepEqual(c.folderShare.rows.map((row) => row.status), ['pending', 'pending', 'pending']);
    }
});

test('an incidental close during a run, such as a press on the overlay, neither hides it nor abandons the page in flight; Stop and Escape still do', async () => {
    const { c, log } = shareFolderInstance({ pages: [1, 2].map((id) => candidatePage(id)), answers: [] });
    const hanging = hangingPost(log);
    c.postJson = hanging.post;
    await c.openShareFolder({ id: 7, name: 'Launch' });
    const running = c.startShareFolder();
    await flush();

    const overlay = {};
    c.onOverlayMousedown({ target: overlay, currentTarget: overlay });
    c.onOverlayClick({ target: overlay, currentTarget: overlay });
    c.closeDialog();
    assert.equal(c.dialog, 'share-folder');
    assert.equal(c.folderShare.state, 'sharing');
    assert.equal(log.signals[0].aborted, false, 'the page in flight is not abandoned');
    assert.equal(c.folderShare.stop, false);

    c.cancelShareFolder();
    assert.equal(log.signals[0].aborted, true, 'an explicit Stop keeps its immediate abort');
    await running;
    await new Promise((resolve) => setTimeout(resolve, 5));
    assert.deepEqual(c.folderShare.rows.map((row) => row.status), ['uncertain', 'not-attempted']);
    c.closeDialog();
    assert.equal(c.dialog, null, 'a finished run closes normally');
});

test('while it runs, the page being shared says Sharing and the pages behind it say Waiting', async () => {
    let release;
    const gate = new Promise((resolve) => { release = resolve; });
    const { c, log } = shareFolderInstance({ pages: [1, 2].map((id) => candidatePage(id)), answers: [] });
    c.postJson = async (url, body) => { log.posts.push(body); await gate; return jsonAnswer(200, { share: { token: `t${body.target_id}` }, reused: false }); };
    await c.openShareFolder({ id: 7, name: 'Launch' });
    const running = c.startShareFolder();
    await flush();

    assert.deepEqual(c.folderShare.rows.map((row) => c.shareFolderRowLabel(row)), ['Sharing…', 'Waiting']);
    release();
    await running;
    assert.deepEqual(c.folderShare.rows.map((row) => c.shareFolderRowLabel(row)), ['Link created', 'Link created']);
    assert.equal(c.folderShare.rows.some((row) => row.working), false);
});

test('a kept link is shown with every option it has, in the candidate list and in the result, and nothing about it is changed', async () => {
    const reuse = { expires_at: null, has_password: true, allow_download: false, requires_creator_access: true };
    const answers = [jsonAnswer(200, { share: { token: 'kept', expires_at: null, has_password: true, allow_download: false, requires_creator_access: true }, reused: true })];
    const { c, log } = shareFolderInstance({ pages: [candidatePage(1, { reuse })], answers });
    await c.openShareFolder({ id: 7, name: 'Launch' });
    const options = 'Existing link kept (no expiry, password protected, downloads off, closes if its creator loses access)';

    assert.equal(c.shareFolderKeptLabel(c.folderShare.rows[0].reuse), options);
    assert.equal(c.shareFolderKeptLabel({ expires_at: null, has_password: false, allow_download: true, requires_creator_access: false }), 'Existing link kept (no expiry)');
    await c.startShareFolder();
    assert.equal(c.shareFolderRowLabel(c.folderShare.rows[0]), options);
    assert.deepEqual(Object.keys(log.posts[0].body).sort(), ['expiry_days', 'page_revision', 'public_link_confirmed', 'share_type', 'target_id', 'web_collection_id'], 'the request carries no option of its own');
});

test('a list says there is more only while its last row is actually cut off, never because of the box padding', async () => {
    const { c } = shareFolderInstance({ pages: [1, 2, 3].map((id) => candidatePage(id)), answers: [] });
    await c.openShareFolder({ id: 7, name: 'Launch' });
    // The padding box ends at top 100 + border 1 + client height 192 = 293; only the text of the last row matters.
    const box = (textBottom) => ({ clientTop: 1, clientHeight: 192, getBoundingClientRect: () => ({ top: 100 }), lastElementChild: { lastElementChild: { getBoundingClientRect: () => ({ bottom: textBottom }) } } });
    const says = (textBottom) => { c.$refs = { shareFolderList: box(textBottom) }; c.checkShareFolderMore(); return c.folderShare.moreBelow; };

    assert.equal(says(400), true, 'a last row below the box is cut off');
    assert.equal(says(295), true, 'a row cut by a couple of pixels is still cut off');
    assert.equal(says(294), false, 'within a pixel of the edge it shows');
    assert.equal(says(285), false, 'a fully visible last row says nothing even though padding remains to scroll through');
    c.$refs = { shareFolderList: { clientTop: 1, clientHeight: 192, getBoundingClientRect: () => ({ top: 100 }), lastElementChild: null } };
    c.checkShareFolderMore();
    assert.equal(c.folderShare.moreBelow, false, 'an empty list says nothing');
});

test('copying the folder links never claims success without a clipboard', async () => {
    const { c, log } = shareFolderInstance({ pages: [candidatePage(1)], answers: [jsonAnswer(200, { share: { token: 'a' }, reused: false })] });
    await c.openShareFolder({ id: 7, name: 'Launch' });
    await c.startShareFolder();
    log.notices.length = 0;
    c.$refs = {};
    c.writeClipboard = async () => false;

    await c.copyFolderShareLinks();

    assert.equal(c.folderShare.copyFailed, true, 'the links are shown selected instead');
    assert.deepEqual(log.notices, []);
    c.writeClipboard = async () => true;
    await c.copyFolderShareLinks();
    assert.equal(c.folderShare.copyFailed, false);
    assert.deepEqual(log.notices, [{ type: 'success', message: 'Links copied' }]);
});

test('the second press of a double-click that opened a dialog does not cancel it; a press once it has settled does', () => {
    const c = instance({ $nextTick: () => {} });
    const overlay = {};
    const press = (timeStamp) => {
        c.onOverlayMousedown({ target: overlay, currentTarget: overlay, timeStamp });
        c.onOverlayClick({ target: overlay, currentTarget: overlay, timeStamp });
    };
    c.openDialog('new');
    c.overlayOpenedAt = 1000;
    press(1110);
    assert.equal(c.dialog, 'new', 'a press 110 ms after opening is the double-click, not Cancel');
    press(1500);
    assert.equal(c.dialog, null, 'a press 500 ms after opening cancels');

    clock.now = 5000;
    c.openShareDialog('https://dam.example.test/s/abc');
    assert.equal(c.overlayOpenedAt, 5000, 'the share link dialog starts the same settling time');
    c.onOverlayMousedown({ target: overlay, currentTarget: overlay, timeStamp: 5100 });
    c.onShareOverlayClick({ target: overlay, currentTarget: overlay, timeStamp: 5100 });
    assert.equal(c.shareUrl, 'https://dam.example.test/s/abc');
    c.onOverlayMousedown({ target: overlay, currentTarget: overlay, timeStamp: 5600 });
    c.onShareOverlayClick({ target: overlay, currentTarget: overlay, timeStamp: 5600 });
    assert.equal(c.shareUrl, null);
});
