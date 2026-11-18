const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const root = path.resolve(__dirname, '../../..');
const base = 'packages/Mothership/DAM/src/';
const detailsNav = require(path.join(root, base + 'Resources/assets/js/details-navigation.js'));
const previewNav = require(path.join(root, base + 'Resources/assets/js/preview-navigation.js'));
const mitt = require(path.join(root, 'packages/Mothership/DAM/node_modules/mitt'));

test('withFileContext preserves the tab, unrelated query and hash while replacing duplicate context keys', () => {
    const href = detailsNav.withFileContext({url: '/admin/dam/assets/edit/1370?meta-data&file=8&file=9&nav=0&nav=1&directory_id=7&directory_id=8&keep=yes#section', fileId: 1691, nav: true, directoryId: 'all'});
    const url = new URL(href, 'https://example.test');
    assert.equal(url.searchParams.get('file'), '1691');
    assert.equal(url.searchParams.getAll('file').length, 1);
    assert.deepEqual(url.searchParams.getAll('nav'), ['1']);
    assert.deepEqual(url.searchParams.getAll('directory_id'), ['all']);
    assert.equal(url.searchParams.has('meta-data'), true);
    assert.equal(url.searchParams.get('keep'), 'yes');
    assert.equal(url.hash, '#section');
    assert.equal(detailsNav.withFileContext({url: '/admin/dam/assets/edit/7', fileId: null}), '/admin/dam/assets/edit/7');
    for (const fileId of [null, -1, 0, 'invalid', 1.2]) {
        assert.equal(new URL(detailsNav.withFileContext({url: '/edit/8?file=1691', fileId}), 'https://example.test').searchParams.has('file'), false);
    }
});

test('member tab round trips preserve the Library total, offset and return URL even with unavailable storage', () => {
    global.sessionStorage = fakeSessionStorage();
    detailsNav.writeSiblings({siblings: [1370, 1371], directoryId: 'all', total: 59, offset: 10, returnUrl: '/admin/dam/assets?search=jpg&sort=name&page=2'});
    const snapshot = detailsNav.readSiblings();
    for (const tab of ['', '?meta-data', '?files']) {
        const href = detailsNav.withFileContext({url: '/admin/dam/assets/edit/1370' + tab, fileId: 1691, nav: true, directoryId: 'all'});
        assert.equal(new URL(href, 'https://example.test').searchParams.get('file'), '1691');
        assert.deepEqual(detailsNav.readSiblings(), snapshot);
    }
    global.sessionStorage = {getItem() { throw new Error('disabled'); }, setItem() { throw new Error('disabled'); }};
    assert.equal(detailsNav.readSiblings(), null);
    assert.match(detailsNav.withFileContext({url: '/edit/1370?files', fileId: 1691, directoryId: 'all', nav: true}), /file=1691/);
});

test('the chooser opens details for its current file while ordinary cover details retain their existing caller', () => {
    const source = fs.readFileSync(path.join(root, base + 'Resources/views/components/explorer/stack-fan.blade.php'), 'utf8');
    const from = source.indexOf('openDetails() {');
    const to = source.indexOf('focusCard(index)', from);
    const script = source.slice(from, to).replace(/@js\(route\('admin.dam.assets.edit', '__ASSET__'\)\)/g, "'/admin/dam/assets/edit/__ASSET__'");
    const methods = vm.runInNewContext('({' + script + '})', {window: {MothershipDetailsNavigation: detailsNav}});
    global.sessionStorage = fakeSessionStorage();
    const urls = [];
    let ordinary = 0;
    const state = {asset: {id: 1370}, visibleItems: [{file: {id: 1690}, cover: false}, {file: {id: 1691}, cover: false}], focusIndex: 1, lastVariantIndex: 0, directoryId: 'all', resultTotal: 59, resultOffset: 10, onDetails: () => ordinary++, librarySiblings: () => [1370, 1371], collapse() {}, $navigate: (url) => urls.push(url)};
    methods.openDetails.call(state);
    assert.equal(new URL(urls[0], 'https://example.test').searchParams.get('file'), '1691');
    assert.equal(detailsNav.readSiblings().total, 59);
    assert.equal(detailsNav.readSiblings().offset, 10);
    state.visibleItems[1].cover = true;
    methods.openDetails.call(state);
    assert.equal(ordinary, 1);
    assert.equal(urls.length, 1);
});

// --- details-navigation.js: the sessionStorage handoff the Library writes and the details
// page reads, standing in for the real browser sessionStorage with a tiny in-memory fake. ---
function fakeSessionStorage(initial = {}) {
    const store = {...initial};

    return {
        getItem: (key) => (Object.prototype.hasOwnProperty.call(store, key) ? store[key] : null),
        setItem: (key, value) => { store[key] = String(value); },
        removeItem: (key) => { delete store[key]; },
        _store: store,
    };
}

test('writeSiblings()/readSiblings() round-trip the visible order, scope label, and directory scope', () => {
    global.sessionStorage = fakeSessionStorage();

    detailsNav.writeSiblings({siblings: ['10', 20, '30'], scopeLabel: 'grid', directoryId: '5'});
    const read = detailsNav.readSiblings();

    assert.deepEqual(read.siblings, [10, 20, 30]);
    assert.equal(read.scopeLabel, 'grid');
    assert.equal(read.directoryId, '5');
});

test('normalizeDirectoryId() distinguishes a folder id, the explicit "all" scope, and "no signal at all" — never inventing one', () => {
    assert.equal(detailsNav.normalizeDirectoryId('5'), '5');
    assert.equal(detailsNav.normalizeDirectoryId(5), '5');
    assert.equal(detailsNav.normalizeDirectoryId('all'), 'all');
    assert.equal(detailsNav.normalizeDirectoryId(null), null);
    assert.equal(detailsNav.normalizeDirectoryId(undefined), null);
    assert.equal(detailsNav.normalizeDirectoryId('0'), null);
    assert.equal(detailsNav.normalizeDirectoryId('not-a-number'), null);
});

test('readSiblings() returns null when nothing was written, the list is empty, or storage is unavailable', () => {
    global.sessionStorage = fakeSessionStorage();
    assert.equal(detailsNav.readSiblings(), null);

    detailsNav.writeSiblings({siblings: [], scopeLabel: 'grid'});
    assert.equal(detailsNav.readSiblings(), null);

    global.sessionStorage = {
        getItem() { throw new Error('sessionStorage disabled'); },
        setItem() { throw new Error('sessionStorage disabled'); },
    };
    assert.equal(detailsNav.readSiblings(), null);
    // writeSiblings() swallows the same failure rather than throwing into the caller's click handler.
    assert.doesNotThrow(() => detailsNav.writeSiblings({siblings: [1, 2]}));
});

test('readSiblings() discards a list older than maxAgeMs (default 1h) as stale', () => {
    global.sessionStorage = fakeSessionStorage();
    detailsNav.writeSiblings({siblings: [1, 2, 3]});

    // A list from over an hour ago is worse than falling back to server ids.
    assert.equal(detailsNav.readSiblings({maxAgeMs: -1}), null);
    // A list from "just now" survives the default window.
    assert.deepEqual(detailsNav.readSiblings().siblings, [1, 2, 3]);
});

test('readSiblings() discards unparsable JSON instead of throwing', () => {
    global.sessionStorage = fakeSessionStorage({'dam.details.siblings': '{not json'});
    assert.equal(detailsNav.readSiblings(), null);
});

test('withNavMarker() appends nav=1 without disturbing an existing query string', () => {
    assert.equal(detailsNav.withNavMarker('/admin/dam/assets/edit/5'), '/admin/dam/assets/edit/5?nav=1');
    assert.equal(detailsNav.withNavMarker('/admin/dam/assets/edit/5?foo=bar'), '/admin/dam/assets/edit/5?foo=bar&nav=1');
    assert.equal(detailsNav.withNavMarker(null), null);
});

test('withNavMarker() also carries the exact directory scope (folder id or "all") when known', () => {
    assert.equal(detailsNav.withNavMarker('/admin/dam/assets/edit/5', '7'), '/admin/dam/assets/edit/5?nav=1&directory_id=7');
    assert.equal(detailsNav.withNavMarker('/admin/dam/assets/edit/5', 'all'), '/admin/dam/assets/edit/5?nav=1&directory_id=all');
    // No scope known: no directory_id is invented.
    assert.equal(detailsNav.withNavMarker('/admin/dam/assets/edit/5', null), '/admin/dam/assets/edit/5?nav=1');
});

// --- Real caller methods: grid/list/gallery's edit()/editImage(), evaluated the same way
// dam-preview-navigation.test.cjs extracts preview()/previewImage() — through a real vm sandbox
// of the file's own <script type="module">, not a hand-simulated call. ---
// Functions compiled by vm.runInContext() close over THAT sandbox's own global object for bare
// identifiers like `window` — not the outer Node realm's `global.window` — so the spy has to be
// installed on the returned context, not on the outer process global.
// Vue exposes a component's other methods on `this`; the tests call one method with a bare state
// object, so lend it the siblings (through the prototype, keeping writes on the state itself).
function withSiblingMethods(methods) {
    const wrapped = {};
    for (const [name, fn] of Object.entries(methods)) {
        wrapped[name] = function (...args) {
            if (this && Object.getPrototypeOf(this) === Object.prototype) Object.setPrototypeOf(this, wrapped);
            return fn.apply(this, args);
        };
    }
    return wrapped;
}

function loadCallerMethods(relativeViewPath) {
    const source = fs.readFileSync(path.join(root, base + 'Resources/views/' + relativeViewPath), 'utf8');
    const script = source.match(/<script type="module">([\s\S]*?)<\/script>/)[1]
        .replace(/@lang\('[^']*'\)/g, 'Label')
        // A permission handed to the script (@json(bouncer()->hasPermission('...'))) reads as granted.
        .replace(/@json\(bouncer\(\)->hasPermission\('[^']*'\)\)/g, 'true')
        .replace(/\{\{[\s\S]*?\}\}/g, '/admin/dam/x');
    let definition;
    const context = {app: {component: (name, value) => { definition = value; }}, window: {}, URLSearchParams, URL};
    vm.createContext(context);
    vm.runInContext(script, context);
    return {methods: withSiblingMethods(definition.methods), context};
}

test('grid/list "edit" and gallery "editImage" write the visible order AND the exact browsed folder (or "all") to sessionStorage before navigating', () => {
    const assets = [{id: 10}, {id: 20}, {id: 30}];
    const dirFilterColumns = (directoryId) => [{index: 'directory_id', value: [directoryId]}];
    const callers = {
        'grid (explorer/grid.blade.php), in a folder': {
            loaded: loadCallerMethods('components/explorer/grid.blade.php'),
            call: (methods, navigate) => methods.edit.call({assets, currentDirId: 7, $navigate: navigate}, 20),
            expectedDirectoryId: 7,
        },
        'grid (explorer/grid.blade.php), all scope': {
            loaded: loadCallerMethods('components/explorer/grid.blade.php'),
            call: (methods, navigate) => methods.edit.call({assets, currentDirId: null, $navigate: navigate}, 20),
            expectedDirectoryId: 'all',
        },
        'list (explorer/list.blade.php), in a folder': {
            loaded: loadCallerMethods('components/explorer/list.blade.php'),
            call: (methods, navigate) => methods.edit.call({assets, currentDirId: 7, $navigate: navigate}, 20),
            expectedDirectoryId: 7,
        },
        'list (explorer/list.blade.php), all scope': {
            loaded: loadCallerMethods('components/explorer/list.blade.php'),
            call: (methods, navigate) => methods.edit.call({assets, currentDirId: null, $navigate: navigate}, 20),
            expectedDirectoryId: 'all',
        },
        'gallery (datagrid/gallery.blade.php), in a folder': {
            loaded: loadCallerMethods('components/datagrid/gallery.blade.php'),
            call: (methods, navigate) => methods.editImage.call({$parent: {available: {records: assets}, applied: {filters: {columns: dirFilterColumns(7)}}}, $navigate: navigate}, 20),
            expectedDirectoryId: 7,
        },
        'gallery (datagrid/gallery.blade.php), all scope': {
            loaded: loadCallerMethods('components/datagrid/gallery.blade.php'),
            call: (methods, navigate) => methods.editImage.call({$parent: {available: {records: assets}, applied: {filters: {columns: []}}}, $navigate: navigate}, 20),
            expectedDirectoryId: 'all',
        },
    };

    for (const [label, {loaded, call, expectedDirectoryId}] of Object.entries(callers)) {
        const writes = [];
        const navigated = [];
        loaded.context.window.MothershipDetailsNavigation = {
            writeSiblings: (payload) => writes.push(payload),
            withNavMarker: (url, directoryId) => `${url}?nav=1&directory_id=${directoryId}`,
        };

        call(loaded.methods, (url) => navigated.push(url));

        assert.equal(writes.length, 1, `${label}: writeSiblings() was not called`);
        assert.deepEqual(writes[0].siblings, [10, 20, 30], `${label}: sibling order was not delivered in full`);
        assert.equal(writes[0].directoryId, expectedDirectoryId, `${label}: directoryId was not the browsed scope`);
        assert.equal(navigated.length, 1, `${label}: $navigate() was not called`);
        assert.match(navigated[0], /\?nav=1&directory_id=/, `${label}: navigation url did not carry the nav=1/directory_id markers`);
        assert.match(navigated[0], new RegExp(`directory_id=${expectedDirectoryId}$`), `${label}: navigation url carried the wrong directory_id`);
    }
});

// --- The lightbox's "Open details" button: v-dam-grid-preview-modal's onOpenDetailsClick(). ---
function loadGridPreviewModalDefinition() {
    const source = fs.readFileSync(path.join(root, base + 'Resources/views/asset/grid-preview-modal.blade.php'), 'utf8');
    let script = source.match(/<script type="module">([\s\S]*?)<\/script>/)[1]
        .replace("@js(route('admin.dam.assets.show', '__id__'))", "'/admin/dam/assets/__id__'")
        .replace(/@js\(trans\([^)]*\)\)/, "'Assets'");

    // Any other @js(...)/@json(...) Blade directive (e.g. #179's custom-thumbnail route/permission
    // fields) isn't asserted on by this file, but still has to parse as JS: walk balanced parens
    // (values can themselves contain literal ')' inside quoted strings) and drop each call in for
    // a stub value — same technique dam-preview-navigation.test.cjs's own loader uses for this.
    script = (() => {
        let output = '';
        let offset = 0;
        for (;;) {
            const match = /@(js|json)\(/.exec(script.slice(offset));
            if (!match) return output + script.slice(offset);
            const start = offset + match.index;
            output += script.slice(offset, start);
            let depth = 1, quote = null, escape = false, end = start + match[0].length;
            for (; end < script.length && depth; end++) {
                const ch = script[end];
                if (escape) { escape = false; continue; }
                if (ch === '\\') { escape = true; continue; }
                if (quote) { if (ch === quote) quote = null; continue; }
                if (ch === "'" || ch === '"') { quote = ch; continue; }
                if (ch === '(') depth++;
                if (ch === ')') depth--;
            }
            // A plain `null` would break call chains like #179's
            // `@json(route(...)).replace('__ASSET__', asset.id)` (customThumbnailUrl); an empty
            // string is a safe stand-in everywhere a stripped @js()/@json() value isn't asserted
            // on by this file, chained `.replace()` included.
            output += "''";
            offset = end;
        }
    })();

    const stubMixin = () => ({data: {}, computed: {}, methods: {}});
    let definition;
    const context = {
        app: {component: (name, value) => { definition = value; }},
        URL,
        window: {
            _damInfoPanel:   stubMixin(),
            _damStackSwitch: stubMixin(),
            _damPreviewFocus: {data: {}, computed: {}, methods: Object.fromEntries(['rememberPreviewOpener', 'focusPreview', 'trapPreviewTab', 'startPreviewFocusGuard', 'stopPreviewFocusGuard', 'restorePreviewFocus'].map((name) => [name, () => {}]))},
            _damImageViewer: stubMixin(),
            _damVideoPlayer: stubMixin(),
            _damAudioPlayer: stubMixin(),
            _damPanoViewer:  stubMixin(),
            _damPdfViewer:   stubMixin(),
            MothershipFavoriteState: {selection: () => ({select() {}, toggle() {}, snapshot: () => ({id: null, favorite: false}), load: async () => {}})},
            MothershipPreviewNavigation: previewNav,
            MothershipDetailsNavigation: detailsNav,
            MothershipNameTail: name => ({head: name, tail: ''}),
            addEventListener() {},
            removeEventListener() {},
        },
        document: {body: {style: {}}},
        history: {state: null, pushState() {}, replaceState() {}, back() {}},
        location: {href: 'http://mothership.test/admin/dam/assets'},
    };
    vm.createContext(context);
    const stackSource = fs.readFileSync(path.join(root, base + 'Resources/views/asset/preview-modal/attachments/strip-script.blade.php'), 'utf8');
    vm.runInContext(stackSource.match(/<script>([\s\S]*?)<\/script>/)[1].replace("@json(trans('dam::app.admin.dam.asset.files.roles'))", '{}'), context);
    vm.runInContext(script, context);
    return {definition, context};
}

test('the lightbox\'s "Open details" click only hands over the sibling order and the browsed scope; its :href carries the final URL and the click never navigates itself', () => {
    for (const [label, directoryId] of [['a folder', 7], ['all scope', 'all']]) {
        const {definition, context} = loadGridPreviewModalDefinition();
        // window.MothershipDetailsNavigation is the real, required module (not a mock), so the
        // sessionStorage write below is observed through the module's own readSiblings() — the
        // same round-trip a real click would produce.
        global.sessionStorage = fakeSessionStorage();
        context.window.location = {href: null};
        let prevented = false;
        const state = {
            previewData: {editUrl: '/admin/dam/assets/edit/20'},
            previewSiblings: [10, 20, 30],
            previewDirectoryId: directoryId,
            previewTotal: 59,
            previewOffset: 10,
        };
        state.onOpenDetailsClick = definition.methods.onOpenDetailsClick;

        state.onOpenDetailsClick({preventDefault: () => { prevented = true; }});

        // Ajax navigation follows the link once; a second navigation here is the double load this guards against.
        assert.equal(prevented, false, label);
        assert.equal(context.window.location.href, null, label);
        const stored = detailsNav.readSiblings();
        assert.deepEqual(stored.siblings, [10, 20, 30], label);
        assert.equal(stored.directoryId, String(directoryId), label);
        // The details counter reads "n of 59", not "n of page size" (QA F14).
        assert.equal(stored.total, 59, label);
        assert.equal(stored.offset, 10, label);
        assert.equal(definition.methods.openDetailsHref.call(state), `/admin/dam/assets/edit/20?nav=1&directory_id=${directoryId}`, label);
    }
});

test('the lightbox\'s "Open details" link follows the asset on screen, never the previous one', () => {
    const {definition} = loadGridPreviewModalDefinition();
    const state = {previewData: {editUrl: '/admin/dam/assets/edit/20'}, previewDirectoryId: 5};
    assert.equal(definition.methods.openDetailsHref.call(state), '/admin/dam/assets/edit/20?nav=1&directory_id=5');
    state.previewData = {editUrl: '/admin/dam/assets/edit/21'};
    assert.equal(definition.methods.openDetailsHref.call(state), '/admin/dam/assets/edit/21?nav=1&directory_id=5');
    state.previewData = {editUrl: ''};
    assert.equal(definition.methods.openDetailsHref.call(state), '');
});

test('the lightbox\'s "Open details" link looks the navigation helper up when it renders, not once', () => {
    const {definition, context} = loadGridPreviewModalDefinition();
    const state = {previewData: {editUrl: '/admin/dam/assets/edit/20'}, previewDirectoryId: 5};
    const helper = context.window.MothershipDetailsNavigation;
    delete context.window.MothershipDetailsNavigation;
    assert.equal(definition.methods.openDetailsHref.call(state), '/admin/dam/assets/edit/20');
    context.window.MothershipDetailsNavigation = helper;
    assert.equal(definition.methods.openDetailsHref.call(state), '/admin/dam/assets/edit/20?nav=1&directory_id=5');
});

test('Open details carries the viewed member and does not replace the original Library snapshot from details', () => {
    const {definition, context} = loadGridPreviewModalDefinition();
    global.sessionStorage = fakeSessionStorage();
    detailsNav.writeSiblings({siblings: [10, 20, 30], directoryId: 'all', total: 59, offset: 10, returnUrl: '/admin/dam/assets?search=jpg&sort=name&page=2'});
    const snapshot = detailsNav.readSiblings();
    context.window.location = {pathname: '/admin/dam/assets/edit/20', origin: 'https://example.test'};
    const state = {previewData: {id: 20, editUrl: '/admin/dam/assets/edit/20', variantFileId: 1691}, previewDirectoryId: 'all', previewSiblings: [20]};
    assert.equal(new URL(definition.methods.openDetailsHref.call(state), 'https://example.test').searchParams.get('file'), '1691');
    definition.methods.onOpenDetailsClick.call(state);
    assert.deepEqual(detailsNav.readSiblings(), snapshot);
    state.previewData = {id: 21, editUrl: '/admin/dam/assets/edit/21'};
    assert.equal(new URL(definition.methods.openDetailsHref.call(state), 'https://example.test').searchParams.has('file'), false);
});

test('the lightbox keeps the stack file count the show() payload carries, and none when it carries none', async () => {
    for (const [payload, expected] of [[{asset: {id: 20}, attachmentCount: 3}, 3], [{asset: {id: 20}}, null]]) {
        const {definition, context} = loadGridPreviewModalDefinition();
        context.window.location = {href: null};
        const state = {
            ...definition.methods,
            previewData: {id: null}, previewSiblings: [], previewVariant: null, previewVariants: [], previewDirectoryId: null,
            isLoading: false, isOpen: false, favorite: false,
            favoriteSelection: {select() {}, toggle() {}, snapshot: () => ({id: null, favorite: false}), load: async () => {}},
            _previewRequestGeneration: 0, displayDirectoryBreadcrumb: [], showUrlTemplate: '/admin/dam/assets/__id__', $refs: {},
            $nextTick: (cb) => Promise.resolve().then(cb), $emitter: {emit() {}},
            pdfDestroy() {}, imgResetState() {}, videoResetState() {}, audioResetState() {}, resetInfoPanel() {}, panoResetState() {}, panoramaInvalidate() {}, loadInfoPanel() {},
            videoInitEl() {}, audioInitEl() {}, panoInit() {}, autoplayMedia() {}, openPreview() {}, loadFavorite: async () => {},
            $axios: {get: async () => ({data: payload})},
        };
        state.openById(20);
        await new Promise((resolve) => setImmediate(resolve));
        assert.equal(state.previewData.attachment_count, expected);
    }
});

test('openById() records the browsed directoryId, carries it into the show() request, and preserves it across internal prev/next (no new directoryId argument)', async () => {
    const {definition, context} = loadGridPreviewModalDefinition();
    context.window.location = {href: null};
    const calls = [];
    const resolvers = [];
    const state = {
        ...definition.methods,
        previewData: {id: null, previousAssetId: null, nextAssetId: null},
        previewSiblings: [],
        previewVariant: null,
        previewVariants: [],
        previewDirectoryId: null,
        isLoading: false,
        isOpen: false,
        favorite: false,
        favoriteSelection: {select() {}, toggle() {}, snapshot: () => ({id: null, favorite: false}), load: async () => {}},
        _previewRequestGeneration: 0,
        displayDirectoryBreadcrumb: [],
        showUrlTemplate: '/admin/dam/assets/__id__',
        $refs: {},
        $nextTick: (cb) => Promise.resolve().then(cb),
        $emitter: {emit() {}},
        pdfDestroy() {}, imgResetState() {}, videoResetState() {}, audioResetState() {},
        resetInfoPanel() {}, panoResetState() {}, panoramaInvalidate() {}, loadInfoPanel() {},
        videoInitEl() {}, audioInitEl() {}, panoInit() {}, autoplayMedia() {},
        imgMounted() {}, videoMounted() {}, panoMounted() {},
        $axios: {get: (url) => { calls.push(url); return new Promise((resolve) => resolvers.push(() => resolve({data: {asset: {id: 20}, previousAssetId: null, nextAssetId: null}}))); }},
    };
    // `previewNeighbours` is a Vue computed getter in the real component; navigatePrev/
    // navigateNext read it, so wire it up as a real getter the same way dam-preview-navigation
    // .test.cjs's previewModalState() does.
    Object.defineProperty(state, 'previewNeighbours', {
        get() { return definition.computed.previewNeighbours.call(state); },
    });
    // previewNeighbours reads previewStack first, so wire that computed as a getter too.
    Object.defineProperty(state, 'previewStack', {
        get() { return definition.computed.previewStack.call(state); },
    });

    state.openById(20, [10, 20, 30], 7);
    assert.equal(state.previewDirectoryId, 7);
    assert.match(calls[0], /\/20\?directory_id=7$/);
    resolvers.shift()();
    await new Promise((resolve) => setImmediate(resolve));

    // navigateNext()/navigatePrev() call openById(id) with only one argument — directoryId is
    // undefined, not null, so the scope already recorded must survive, not reset.
    state.navigateNext();
    assert.equal(state.previewDirectoryId, 7, 'directoryId was lost on internal next navigation');
    assert.match(calls[1], /directory_id=7$/, 'the next-navigation show() request dropped the scope');
});

test('the real preview()/previewImage() methods on grid, list and gallery deliver directoryId through the mitt bus into the lightbox\'s show() request', () => {
    const assets = [{id: 10}, {id: 20}, {id: 30}];
    const dirFilterColumns = (directoryId) => [{index: 'directory_id', value: [directoryId]}];
    const callers = {
        'grid, in a folder': {methods: loadCallerMethods('components/explorer/grid.blade.php').methods, call: (methods, emitter) => methods.preview.call({assets, currentDirId: 7, $emitter: emitter}, 20), expected: 7},
        'grid, all scope':   {methods: loadCallerMethods('components/explorer/grid.blade.php').methods, call: (methods, emitter) => methods.preview.call({assets, currentDirId: null, $emitter: emitter}, 20), expected: 'all'},
        'list, in a folder': {methods: loadCallerMethods('components/explorer/list.blade.php').methods, call: (methods, emitter) => methods.preview.call({assets, currentDirId: 7, $emitter: emitter}, 20), expected: 7},
        'list, all scope':   {methods: loadCallerMethods('components/explorer/list.blade.php').methods, call: (methods, emitter) => methods.preview.call({assets, currentDirId: null, $emitter: emitter}, 20), expected: 'all'},
        'gallery, in a folder': {methods: loadCallerMethods('components/datagrid/gallery.blade.php').methods, call: (methods, emitter) => methods.previewImage.call({$parent: {available: {records: assets}, applied: {filters: {columns: dirFilterColumns(7)}}}, $emitter: emitter}, 20), expected: 7},
        'gallery, all scope': {methods: loadCallerMethods('components/datagrid/gallery.blade.php').methods, call: (methods, emitter) => methods.previewImage.call({$parent: {available: {records: assets}, applied: {filters: {columns: []}}}, $emitter: emitter}, 20), expected: 'all'},
    };

    for (const [label, {methods, call, expected}] of Object.entries(callers)) {
        const {definition, context} = loadGridPreviewModalDefinition();
        context.window.location = {href: null};
        const calls = [];
        const emitter = mitt();
        const state = {
            ...definition.methods,
            previewData: {id: null, previousAssetId: null, nextAssetId: null},
            previewSiblings: [],
            previewDirectoryId: null,
            isLoading: false, isOpen: false, favorite: false,
            favoriteSelection: {select() {}, toggle() {}, snapshot: () => ({id: null, favorite: false}), load: async () => {}},
            _previewRequestGeneration: 0,
            displayDirectoryBreadcrumb: [],
            showUrlTemplate: '/admin/dam/assets/__id__',
            $refs: {}, $nextTick: (cb) => Promise.resolve().then(cb), $emitter: emitter,
            pdfDestroy() {}, imgResetState() {}, videoResetState() {}, audioResetState() {},
            resetInfoPanel() {}, panoResetState() {}, panoramaInvalidate() {}, loadInfoPanel() {},
            videoInitEl() {}, audioInitEl() {}, panoInit() {}, autoplayMedia() {},
            imgMounted() {}, videoMounted() {}, panoMounted() {},
            $axios: {get: (url) => { calls.push(url); return new Promise(() => {}); }},
        };
        definition.mounted.call(state);

        call(methods, emitter);

        assert.equal(state.previewDirectoryId, expected, `${label}: previewDirectoryId`);
        assert.match(calls[0], new RegExp(`directory_id=${expected}$`), `${label}: show() request scope`);
    }
});

// --- The details page itself: v-edit-asset's created()/applyNeighbours()/navigateTo(). ---
function loadEditAssetDefinition() {
    const source = fs.readFileSync(path.join(root, base + 'Resources/views/asset/edit.blade.php'), 'utf8');
    // The module block also registers the header's cover-only wrapper and "Back to cover" first; the test wants v-edit-asset on.
    const from = source.indexOf("app.component('v-edit-asset',");
    const block = '<script type="module">\n' + source.slice(from, source.indexOf('</script>', from) + '</script>'.length);
    const script = block
        .replace(/^<script type="module">/, '')
        .replace(/<\/script>$/, '')
        .replace('@json($asset)', 'null')
        .replace('@js($detailFields)', 'null')
        .replace('@js($generationPrompt ?? null)', 'null')
        .replace('@json($asset->previousAssetId)', 'null')
        .replace('@json($asset->nextAssetId)', 'null')
        .replace('@js($detailsNote)', 'null')
        .replace('@js($asset->display_name)', "''")
        .replace('@js($asset->file_name)', "''")
        .replace('@js($fileSize ?? null)', 'null')
        .replace("@js($asset->width ?? '')", "''")
        .replace("@js($asset->height ?? '')", "''")
        .replace("@js($asset->extension ?? '')", "''")
        .replace("@js($asset->mime_type ?? '')", "''")
        .replace('@js(\\Mothership\\DAM\\Support\\AdobeFormats::present(extension: $asset->extension, mime: $asset->mime_type))', 'null')
        .replace("@js($asset->created_at?->format('d M Y, H:i T'))", "''")
        .replace("@js($asset->updated_at?->format('d M Y, H:i T'))", "''")
        .replace("@js($asset->getPathWithOutFileSystemRoot() ?? '')", "''")
        .replace('@json($asset->tags ?? [])', '[]')
        .replace("@js(json_encode($asset->tags->pluck('id')->values()->all()))", "'[]'")
        .replace("@json($tags->map(fn ($tag) => ['id' => $tag->id, 'name' => $tag->name])->values())", '[]')
        // Keeps the `:id` token (rather than a placeholder like `__id__`) so the real
        // `.replace(':id', id)` call in navigateTo() actually substitutes it — tests that
        // discriminate mock responses by url content need the real id present in the url.
        .replace(/`\{\{ route\('admin\.dam\.assets\.show', ':id'\) \}\}`/g, "'/admin/dam/assets/show/:id'")
        .replace(/@js\(trans\('dam::app\.admin\.dam\.asset\.datagrid\.not-found-to-show'\)\)/, "'Not found'")
        .replace(/@js\(trans\('dam::app\.admin\.dam\.asset\.stack-fan\.info-stack-label'\)\)/, "':label (stack)'")
        .replace(/"\{\{ route\('admin\.dam\.assets\.tag'\) \}\}"/, "'/admin/dam/assets/tag'")
        .replace(/"\{\{ route\('admin\.dam\.assets\.remove-tag'\) \}\}"/, "'/admin/dam/assets/remove-tag'");

    let definition;
    const context = {
        app: {component: (name, value) => { definition = value; }},
        window: {MothershipPreviewNavigation: previewNav, MothershipDetailsNavigation: detailsNav, location: {search: ''}},
        history: {pushState() {}},
        URLSearchParams,
        URL,
    };
    vm.createContext(context);
    vm.runInContext(script, context);
    return {definition, context};
}

function editAssetState(definition, overrides = {}) {
    return {
        ...definition.methods,
        asset: {id: 20, display_name: 'a.jpg', file_name: 'a.jpg', extension: 'jpg', mime_type: 'image/jpeg'},
        detailFields: null, detailsNote: null, generationPrompt: null,
        promptExpanded: false, promptCopied: false,
        navSiblings: null, navPosition: null, navTotal: null, navDirectoryParam: null,
        prevAssetId: null, nextAssetId: null, isNavigating: false,
        displayTitle: '', displayFileName: '', displayFileSize: null,
        displayWidth: '', displayHeight: '', displayExtension: '', displayMimeType: '',
        displayCreatedAt: '', displayUpdatedAt: '', displayAssetPath: '',
        displayTags: [], tagValues: '[]', tagComponentKey: 0,
        $nextTick: (cb) => Promise.resolve().then(cb),
        $emitter: {emit() {}},
        ...overrides,
    };
}

test('created() reads the sessionStorage list only when the url carries nav=1, and positions the current asset within it', () => {
    global.sessionStorage = fakeSessionStorage();
    detailsNav.writeSiblings({siblings: [10, 20, 30], scopeLabel: 'grid'});

    const {definition, context} = loadEditAssetDefinition();
    context.window.location.search = '?nav=1';
    const state = editAssetState(definition, {asset: {id: 20}});

    definition.created.call(state);

    assert.deepEqual(state.navSiblings, [10, 20, 30]);
    // ‹ (previous) is the earlier index (10, to the LEFT); › (next) is the later index (30).
    assert.equal(state.prevAssetId, 10);
    assert.equal(state.nextAssetId, 30);
    assert.equal(state.navPosition, 2);
    assert.equal(state.navTotal, 3);
});

test('created() falls back to the server ids when the url has no nav=1 marker, even with a fresh list in storage', () => {
    global.sessionStorage = fakeSessionStorage();
    detailsNav.writeSiblings({siblings: [10, 20, 30]});

    const {definition, context} = loadEditAssetDefinition();
    context.window.location.search = ''; // no ?nav=1
    const state = editAssetState(definition, {asset: {id: 20}, prevAssetId: 7, nextAssetId: 9});

    definition.created.call(state);

    assert.equal(state.navSiblings, null);
    assert.equal(state.prevAssetId, 7);
    assert.equal(state.nextAssetId, 9);
    assert.equal(state.navPosition, null);
    assert.equal(state.navTotal, null);
});

test('created() falls back to the server ids when the stored list is stale or does not contain the current asset', () => {
    global.sessionStorage = fakeSessionStorage();
    detailsNav.writeSiblings({siblings: [10, 20, 30]});

    const {definition, context} = loadEditAssetDefinition();
    context.window.location.search = '?nav=1';

    // Stale: older than the 1h window.
    const staleRaw = JSON.parse(global.sessionStorage.getItem('dam.details.siblings'));
    staleRaw.at = Date.now() - (2 * 60 * 60 * 1000);
    global.sessionStorage.setItem('dam.details.siblings', JSON.stringify(staleRaw));
    let state = editAssetState(definition, {asset: {id: 20}, prevAssetId: 7, nextAssetId: 9});
    definition.created.call(state);
    assert.equal(state.navSiblings, null);
    assert.equal(state.prevAssetId, 7);
    assert.equal(state.nextAssetId, 9);

    // Fresh list, but the current asset is not in it (direct link to an asset not on that page).
    detailsNav.writeSiblings({siblings: [10, 30, 40]});
    state = editAssetState(definition, {asset: {id: 99}, prevAssetId: 7, nextAssetId: 9});
    definition.created.call(state);
    assert.equal(state.prevAssetId, 7);
    assert.equal(state.nextAssetId, 9);
    assert.equal(state.navPosition, null);
});

test('created() discards a stored list that does not contain the page\'s OWN starting asset, so a later hop cannot silently adopt an unrelated list', () => {
    global.sessionStorage = fakeSessionStorage();
    // This list belongs to some other browsing session — id 20 (this page's asset) is not on it,
    // even though ids 30/40 could coincidentally be navigated to later.
    detailsNav.writeSiblings({siblings: [30, 40, 50]});

    const {definition, context} = loadEditAssetDefinition();
    context.window.location.search = '?nav=1';
    const state = editAssetState(definition, {asset: {id: 20}, prevAssetId: 7, nextAssetId: 9});

    definition.created.call(state);

    // navSiblings must be null outright — not merely "not walked this time" — so a subsequent
    // navigateTo() hop landing on id 30 (which IS in the stale list) cannot start walking it.
    assert.equal(state.navSiblings, null);
    assert.equal(state.prevAssetId, 7);
    assert.equal(state.nextAssetId, 9);
});

test('resolveNavDirectoryParam() reads the exact Library scope (folder id or "all") straight off the url, never inventing one', () => {
    const {definition, context} = loadEditAssetDefinition();
    const state = editAssetState(definition, {});

    context.window.location.search = '?nav=1&directory_id=42';
    assert.equal(definition.methods.resolveNavDirectoryParam.call(state), '42');

    context.window.location.search = '?nav=1&directory_id=all';
    assert.equal(definition.methods.resolveNavDirectoryParam.call(state), 'all');

    context.window.location.search = '?nav=1';
    assert.equal(definition.methods.resolveNavDirectoryParam.call(state), null);
});

test('mounted() corrects the "n of N" counter once, only when a siblings-list position is active', async () => {
    global.sessionStorage = fakeSessionStorage();
    detailsNav.writeSiblings({siblings: [10, 20, 30]});

    const {definition, context} = loadEditAssetDefinition();
    context.window.location.search = '?nav=1';
    const emitted = [];
    const state = editAssetState(definition, {asset: {id: 20}, $emitter: {on() {}, off() {}, emit: (type, payload) => emitted.push([type, payload])}});

    definition.created.call(state);
    definition.mounted.call(state);
    await new Promise((resolve) => setImmediate(resolve));

    assert.equal(emitted.length, 1);
    // The emitted payload was built inside the vm sandbox (a different realm); spread it into a
    // plain outer-realm object first — deepEqual treats a same-shape cross-realm object as
    // reference-unequal otherwise.
    assert.equal(emitted[0][0], 'dam-asset-changed');
    assert.deepEqual({...emitted[0][1]}, {assetPosition: 2, assetTotal: 3});
});

test('navigateTo() keeps walking the stored siblings list across hops and preserves the nav=1 marker in the pushed url', async () => {
    global.sessionStorage = fakeSessionStorage();
    detailsNav.writeSiblings({siblings: [10, 20, 30]});

    const {definition, context} = loadEditAssetDefinition();
    context.window.location.search = '?nav=1';
    context.window.location.href = 'https://example.test/admin/dam/assets/edit/20?nav=1&file=1691';
    const tab = {href: 'https://example.test/admin/dam/assets/edit/20?meta-data&nav=1&file=1691'};
    context.document = {querySelectorAll: () => [tab]};
    const pushed = [];
    context.history.pushState = (...args) => pushed.push(args);

    const state = editAssetState(definition, {
        asset: {id: 20},
        $axios: {get: (url) => Promise.resolve({data: {
            success: true,
            asset: {id: 30, display_name: 'c.jpg', file_name: 'c.jpg', extension: 'jpg', mime_type: 'image/jpeg'},
            editUrl: '/admin/dam/assets/edit/30',
            previousAssetId: null,
            nextAssetId: null,
            assetPosition: 1,
            assetTotal: 1,
            tags: [],
        }})},
    });
    definition.created.call(state);
    assert.equal(state.nextAssetId, 30);

    await definition.methods.navigateTo.call(state, 30);

    // The list still has the new current id (30) in it, so prev/next keep walking it: at the
    // last index, next is null and previous is 20 — not the server's own (misleading) values.
    assert.equal(state.asset.id, 30);
    assert.equal(state.prevAssetId, 20);
    assert.equal(state.nextAssetId, null);
    assert.equal(state.navPosition, 3);
    assert.equal(state.navTotal, 3);
    assert.equal(pushed.length, 1);
    assert.equal(pushed[0][2], '/admin/dam/assets/edit/30?nav=1');
    const tabUrl = new URL(tab.href);
    assert.equal(tabUrl.pathname, '/admin/dam/assets/edit/30');
    assert.equal(tabUrl.searchParams.has('meta-data'), true);
    assert.equal(tabUrl.searchParams.has('file'), false);
    assert.equal(tabUrl.searchParams.get('nav'), '1');
});

test('navigateTo() falls back to the server-computed ids once the current asset drops out of the stored list', async () => {
    global.sessionStorage = fakeSessionStorage();
    detailsNav.writeSiblings({siblings: [10, 20]}); // 99 is never in this list

    const {definition, context} = loadEditAssetDefinition();
    context.window.location.search = '?nav=1';
    context.history.pushState = () => {};

    const state = editAssetState(definition, {
        asset: {id: 20},
        $axios: {get: () => Promise.resolve({data: {
            success: true,
            asset: {id: 99, display_name: 'x.jpg', file_name: 'x.jpg', extension: 'jpg', mime_type: 'image/jpeg'},
            editUrl: '/admin/dam/assets/edit/99',
            previousAssetId: 50,
            nextAssetId: 60,
            assetPosition: 4,
            assetTotal: 9,
            tags: [],
        }})},
    });
    definition.created.call(state);

    await definition.methods.navigateTo.call(state, 99);

    assert.equal(state.prevAssetId, 50);
    assert.equal(state.nextAssetId, 60);
    assert.equal(state.navPosition, null);
    assert.equal(state.navTotal, null);
});

test('navigateTo() carries the SAME ?directory_id scope on every hop, never the newly-navigated-to asset\'s own directory', async () => {
    // No sessionStorage siblings list at all here — this reproduces the scenario where the
    // handoff is unavailable/stale and the server fallback is genuinely load-bearing across
    // multiple chevron clicks within one scoped folder (?directory_id=7).
    global.sessionStorage = fakeSessionStorage();

    const {definition, context} = loadEditAssetDefinition();
    context.window.location.search = '?directory_id=7'; // no nav=1: no siblings list in play
    const pushed = [];
    context.history.pushState = (...args) => pushed.push(args);

    const requestedUrls = [];
    const responseFor = (id, editUrl) => ({data: {
        success: true,
        asset: {id, display_name: 'x.jpg', file_name: 'x.jpg', extension: 'jpg', mime_type: 'image/jpeg'},
        editUrl,
        previousAssetId: null,
        nextAssetId: null,
        assetPosition: 1,
        assetTotal: 1,
        tags: [],
    }});
    const state = editAssetState(definition, {
        asset: {id: 20},
        $axios: {get: (url) => {
            requestedUrls.push(url);

            return Promise.resolve(url.includes('/30') ? responseFor(30, '/admin/dam/assets/edit/30') : responseFor(40, '/admin/dam/assets/edit/40'));
        }},
    });
    definition.created.call(state);
    assert.equal(state.navDirectoryParam, '7');

    await definition.methods.navigateTo.call(state, 30);
    await definition.methods.navigateTo.call(state, 40);

    assert.equal(requestedUrls.length, 2);
    // Both hops requested the SAME scope (7) — never derived from the asset just navigated to.
    assert.match(requestedUrls[0], /directory_id=7$/);
    assert.match(requestedUrls[1], /directory_id=7$/);

    // With no siblings list active, pushState() still must not drop the scope — a reload right
    // after either hop would otherwise silently change folders.
    assert.equal(pushed.length, 2);
    assert.equal(pushed[0][2], '/admin/dam/assets/edit/30?directory_id=7');
    assert.equal(pushed[1][2], '/admin/dam/assets/edit/40?directory_id=7');
});

test('navigateTo() carries the "all" scope into both the show() request and the pushed url when there is no siblings list active', async () => {
    global.sessionStorage = fakeSessionStorage();

    const {definition, context} = loadEditAssetDefinition();
    context.window.location.search = '?directory_id=all'; // no nav=1: no siblings list in play
    const pushed = [];
    context.history.pushState = (...args) => pushed.push(args);

    const requestedUrls = [];
    const state = editAssetState(definition, {
        asset: {id: 20},
        $axios: {get: (url) => {
            requestedUrls.push(url);

            return Promise.resolve({data: {
                success: true,
                asset: {id: 30, display_name: 'x.jpg', file_name: 'x.jpg', extension: 'jpg', mime_type: 'image/jpeg'},
                editUrl: '/admin/dam/assets/edit/30',
                previousAssetId: null,
                nextAssetId: null,
                assetPosition: 1,
                assetTotal: 1,
                tags: [],
            }});
        }},
    });
    definition.created.call(state);
    assert.equal(state.navDirectoryParam, 'all');

    await definition.methods.navigateTo.call(state, 30);

    assert.equal(requestedUrls.length, 1);
    assert.match(requestedUrls[0], /directory_id=all$/);
    assert.equal(pushed.length, 1);
    assert.equal(pushed[0][2], '/admin/dam/assets/edit/30?directory_id=all');
});

// --- End-to-end: a real Library click (grid.blade.php's edit()) writes sessionStorage + builds
// the details url; the details page's created() reads that SAME storage/url and positions
// correctly — proving the write side and the read side actually agree on the wire format,
// rather than each half only being tested against its own assumptions. ---
test('integration: a real grid "edit" click hands off to a real details-page created() load, and falls back cleanly once storage is cleared', () => {
    global.sessionStorage = fakeSessionStorage();

    const grid = loadCallerMethods('components/explorer/grid.blade.php');
    grid.context.window.MothershipDetailsNavigation = detailsNav; // the real module, not a mock
    const assets = [{id: 10}, {id: 20}, {id: 30}];
    let navigatedUrl = null;
    grid.methods.edit.call({assets, currentDirId: 7, $navigate: (url) => { navigatedUrl = url; }}, 20);

    // loadCallerMethods() blanket-replaces every {{ route(...) }} with a placeholder url, since
    // it is a generic Blade-directive stub, not just this one route — the query string appended
    // by the real writeSiblings()/withNavMarker() round-trip is what this test actually proves.
    assert.match(navigatedUrl, /\?nav=1&directory_id=7$/);

    const {definition, context} = loadEditAssetDefinition();
    const query = navigatedUrl.slice(navigatedUrl.indexOf('?'));
    context.window.location.search = query;
    const state = editAssetState(definition, {asset: {id: 20}});

    definition.created.call(state);

    assert.deepEqual(state.navSiblings, [10, 20, 30]);
    assert.equal(state.navDirectoryParam, '7');
    assert.equal(state.prevAssetId, 10);
    assert.equal(state.nextAssetId, 30);

    // Storage clears between the click and the load (private tab, expired session, etc.) — the
    // SAME url (still carrying nav=1 and directory_id=7) must fall back cleanly, not throw.
    global.sessionStorage = fakeSessionStorage();
    const fallbackState = editAssetState(definition, {asset: {id: 20}, prevAssetId: 3, nextAssetId: 4});
    definition.created.call(fallbackState);

    assert.equal(fallbackState.navSiblings, null);
    assert.equal(fallbackState.navDirectoryParam, '7');
    assert.equal(fallbackState.prevAssetId, 3);
    assert.equal(fallbackState.nextAssetId, 4);
});
