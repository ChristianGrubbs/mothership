const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const root = path.resolve(__dirname, '../../..');
const base = 'packages/Mothership/DAM/src/';
const navigation = require(path.join(root, base + 'Resources/assets/js/preview-navigation.js'));
// The real event bus (mitt): emit(type, event?) carries exactly one payload, so this is what
// actually proves grid/list/gallery's `{ id, siblings }` and picker/field/context-menu's bare id
// both reach the mounted listener correctly — a hand-rolled fake emitter could hide an arity bug.
const mitt = require(path.join(root, 'packages/Mothership/DAM/node_modules/mitt'));

test('neighbours() walks the passed-in visible order and reports a no-wrap position', () => {
    const middle = navigation.neighbours({siblings: [11, 22, 33], currentId: 22});
    assert.deepEqual(middle, {previousId: 11, nextId: 33, position: 2, total: 3});

    const first = navigation.neighbours({siblings: [11, 22, 33], currentId: 11});
    assert.equal(first.previousId, null);
    assert.equal(first.nextId, 22);

    const last = navigation.neighbours({siblings: [11, 22, 33], currentId: 33});
    assert.equal(last.nextId, null);
    assert.equal(last.previousId, 22);

    const single = navigation.neighbours({siblings: [11], currentId: 11});
    assert.equal(single.previousId, null);
    assert.equal(single.nextId, null);
    assert.equal(single.total, 1);
});

test('neighbours() falls back to the server-computed ids when no siblings were passed or the current id is not among them', () => {
    const noSiblings = navigation.neighbours({siblings: [], currentId: 5, previousAssetId: 4, nextAssetId: 6});
    assert.deepEqual(noSiblings, {previousId: 4, nextId: 6, position: null, total: null});

    const notFound = navigation.neighbours({siblings: [1, 2, 3], currentId: 99, previousAssetId: 98, nextAssetId: 100});
    assert.deepEqual(notFound, {previousId: 98, nextId: 100, position: null, total: null});

    const nothingKnown = navigation.neighbours({});
    assert.deepEqual(nothingKnown, {previousId: null, nextId: null, position: null, total: null});
    assert.deepEqual(navigation.neighbours({currentId: 5, previousAssetId: null, nextAssetId: null}), nothingKnown);
});

test('neighbours() tolerates string ids from the DOM/JSON boundary and unsorted sibling order', () => {
    const stringIds = navigation.neighbours({siblings: ['7', '8', '9'], currentId: '8'});
    assert.deepEqual(stringIds, {previousId: 7, nextId: 9, position: 2, total: 3});

    // The order is whatever the caller rendered, not a numeric sort.
    const reversed = navigation.neighbours({siblings: [30, 20, 10], currentId: 20});
    assert.deepEqual(reversed, {previousId: 30, nextId: 10, position: 2, total: 3});
});

test('member neighbours retain attachment order and selected revision, filter foreign assets, and stop at boundaries', () => {
    const files = [{id: 90, asset_id: 7}, {id: 20, asset_id: 7, is_representative: true}, {id: 3, asset_id: 8}, {id: 40, asset_id: 7}];
    const middle = navigation.memberNeighbours({files, assetId: 7, fileId: 20});
    assert.equal(middle.previous.id, 90);
    assert.equal(middle.next.id, 40);
    assert.deepEqual([middle.position, middle.total], [2, 3]);
    assert.equal(navigation.memberNeighbours({files, assetId: 7, fileId: 90}).previous, null);
    assert.equal(navigation.memberNeighbours({files, assetId: 7, fileId: 40}).next, null);
    assert.equal(navigation.memberNeighbours({files, assetId: 7, fileId: 999}).next, null);
});

test('chooser Files link names the focused revision and preserves Files tab and directory scope', () => {
    const source = fs.readFileSync(path.join(root, base + 'Resources/views/components/explorer/stack-fan.blade.php'), 'utf8');
    const method = source.match(/                filesHref\(\) \{[\s\S]*?\n                \},/)[0].trim().replace(/,$/, '').replace("@js(route('admin.dam.assets.edit', '__ASSET__'))", "'/admin/dam/assets/edit/__ASSET__'");
    const filesHref = vm.runInNewContext(`({${method}}).filesHref`, {window: {MothershipDetailsNavigation: require(path.join(root, base + 'Resources/assets/js/details-navigation.js'))}});
    const href = filesHref.call({asset: {id: 7}, directoryId: 'all', focusIndex: 1, visibleItems: [{file: {id: 10}}, {file: {id: 11}}]});
    const url = new URL(href, 'https://files.test');
    assert.equal(url.pathname, '/admin/dam/assets/edit/7');
    assert.equal(url.searchParams.has('files'), true);
    assert.equal(url.searchParams.get('file'), '11');
    assert.equal(url.searchParams.get('directory_id'), 'all');
});

test('member navigation keeps the asset and clears media; a Library hop clears the chooser origin', () => {
    const definition = previewModalDefinition();
    const files = [{id: 1, asset_id: 7, is_representative: true}, {id: 2, asset_id: 7, mime_type: 'application/pdf', url: '/two', label: 'Two.pdf'}];
    let cleaned = 0;
    const events = [];
    const state = previewModalState(definition, {
        isOpen: true, previewData: {id: 7}, _coverPreviewData: {id: 7}, previewVariants: files,
        videoStopOnClose() { cleaned++; }, audioStopOnClose() { cleaned++; }, panoDestroy() { cleaned++; },
        previewOrigin: {kind: 'chooser', assetId: 7, fileId: 1},
        $emitter: {emit: (event) => events.push(event)},
    });
    state.navigateMember({direction: 1});
    assert.equal(state.previewData.id, 7);
    assert.equal(state.previewData.variantFileId, 2);
    assert.equal(cleaned, 3);
    state.navigateMember({direction: 1});
    assert.equal(cleaned, 3, 'last file must not wrap');
    state.previewSiblings = [7, 8];
    let destination;
    state.openById = (id) => { destination = id; };
    state.navigateNext();
    assert.equal(destination, 8);
    assert.equal(state.previewOrigin.kind, 'library');
    assert.ok(events.includes('dam-preview-leave-chooser'));
});

// --- Mounts the real grid-preview-modal component definition (stubbing only the
// unrelated media/info-panel mixins it spreads in) to exercise openById(), the
// in-flight re-entrancy guard, and the sibling-order carryover across real
// navigation calls end to end. ---
function previewModalDefinition() {
    const source = fs.readFileSync(path.join(root, base + 'Resources/views/asset/grid-preview-modal.blade.php'), 'utf8');
    let script = source.match(/<script type="module">([\s\S]*?)<\/script>/)[1];
    script = script
        .replace("@js(route('admin.dam.assets.show', '__id__'))", "'/admin/dam/assets/__id__'")
        .replace(/@js\(trans\([^)]*\)\)/, "'Assets'");
    // Any other @js(...)/@json(...) Blade directive (e.g. #179's custom-thumbnail route/permission
    // fields) isn't asserted on by this file, but still has to parse as JS: walk balanced parens
    // (values can themselves contain literal ')' inside quoted strings) and drop each call in for
    // a stub value, same technique image-viewer-modals.test.cjs already uses for this reason.
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
    // The preview is a history entry (Back closes it); mounted() registers its owner before the AJAX router boots.
    const listeners = {};
    const history = {state: null, pushes: 0, pushState(state) { this.state = state; this.pushes++; }, replaceState(state) { this.state = state; }, back() {}};
    const context = {
        app: {component: (name, value) => { definition = value; }},
        window: {
            _damInfoPanel:   stubMixin(),
            _damPreviewFocus: {data: {}, computed: {}, methods: Object.fromEntries(['rememberPreviewOpener', 'focusPreview', 'trapPreviewTab', 'startPreviewFocusGuard', 'stopPreviewFocusGuard', 'restorePreviewFocus'].map((name) => [name, () => {}]))},
            _damImageViewer: stubMixin(),
            _damVideoPlayer: stubMixin(),
            _damAudioPlayer: stubMixin(),
            _damPanoViewer:  stubMixin(),
            _damPdfViewer:   stubMixin(),
            MothershipFavoriteState: {selection: () => ({select() {}, toggle() {}, snapshot: () => ({id: null, favorite: false}), load: async () => {}})},
            MothershipPreviewNavigation: navigation,
            MothershipNameTail: (name) => ({head: name, tail: ''}),
            addEventListener(type, handler) { listeners[type] = handler; },
            removeEventListener() {},
        },
        document: {body: {style: {}}, addEventListener(type, handler) { listeners[type] = handler; }, removeEventListener() {}},
        history,
        location: {href: 'http://mothership.test/admin/dam/assets'},
        setTimeout, clearTimeout,
    };
    vm.createContext(context);
    // The real shared stack-switch mixin (strip-script.blade.php), which both previews spread in.
    const stripSource = fs.readFileSync(path.join(root, base + 'Resources/views/asset/preview-modal/attachments/strip-script.blade.php'), 'utf8');
    vm.runInContext(stripSource.match(/<script>([\s\S]*?)<\/script>/)[1].replace("@json(trans('dam::app.admin.dam.asset.files.roles'))", '{}'), context);
    context.window._damStackSwitch = vm.runInContext('window._damStackSwitch', context);
    vm.runInContext(script, context);
    definition.__env = {history, listeners, window: context.window, document: context.document};
    return definition;
}

function previewModalState(definition, overrides = {}) {
    const state = {
        ...definition.methods,
        previewData: {id: null, previousAssetId: null, nextAssetId: null},
        previewSiblings: [],
        previewVariant: null,
        previewVariants: [],
        isLoading: false,
        isOpen: false,
        favorite: false,
        favoriteSelection: {select() {}, toggle() {}, snapshot: () => ({id: null, favorite: false}), load: async () => {}},
        _previewRequestGeneration: 0,
        displayDirectoryBreadcrumb: [],
        showUrlTemplate: '/admin/dam/assets/__id__',
        $refs: {},
        $nextTick: (callback) => Promise.resolve().then(callback),
        $emitter: {emit() {}},
        pdfDestroy() {}, imgResetState() {}, videoResetState() {}, audioResetState() {},
        resetInfoPanel() {}, panoResetState() {}, panoramaInvalidate() {}, loadInfoPanel() {},
        videoInitEl() {}, audioInitEl() {}, panoInit() {}, autoplayMedia() {},
        imgMounted() {}, videoMounted() {}, panoMounted() {},
        ...overrides,
    };
    // `previewNeighbours` is a Vue computed getter in the real component; without Vue reactivity
    // behind this plain test object, wire it up as a real getter so navigatePrev/navigateNext
    // (which read `this.previewNeighbours`) see the same live value production code would.
    Object.defineProperty(state, 'previewNeighbours', {
        get() { return definition.computed.previewNeighbours.call(state); },
    });
    // previewNeighbours reads previewStack first (a stack's arrows step through its files), so wire that computed too.
    Object.defineProperty(state, 'previewStack', {
        get() { return definition.computed.previewStack.call(state); },
    });
    return state;
}

// Extracts a real caller component's methods object (grid/list/gallery), the same way
// dam-grid-navigation.test.cjs and library-navigation.test.cjs extract component definitions:
// evaluate the file's own <script type="module"> in a sandbox and capture what it registers.
// Blade directives that would not parse as JS ({{ route(...) }}, @lang(...)) are placeholder-swapped
// first; none of them affect the methods under test (preview/previewImage).
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
        // Every @lang(...) in these three files' module scripts is already inside a "..." string
        // literal, so the replacement must not add its own quotes.
        .replace(/@lang\('[^']*'\)/g, 'Label')
        // A permission handed to the script (@json(bouncer()->hasPermission('...'))) reads as granted.
        .replace(/@json\(bouncer\(\)->hasPermission\('[^']*'\)\)/g, 'true')
        .replace(/\{\{[\s\S]*?\}\}/g, '/admin/dam/x');
    let definition;
    const context = {app: {component: (name, value) => { definition = value; }}, window: {}, URLSearchParams, URL};
    vm.createContext(context);
    vm.runInContext(script, context);
    return withSiblingMethods(definition.methods);
}

function fakeAxios(calls, resolvers) {
    return {
        get(url) {
            calls.push(url);
            return new Promise((resolve) => resolvers.push(() => resolve({
                data: {
                    asset: {id: Number(url.split('/').pop()), file_name: 'a.jpg', extension: 'jpg', mime_type: 'image/jpeg'},
                    previousAssetId: null,
                    nextAssetId: null,
                },
            })));
        },
    };
}

test('Library origin captures the clicked control when the browser leaves focus on body', async () => {
    const definition = previewModalDefinition();
    const emitter = mitt();
    const state = previewModalState(definition, {$axios: fakeAxios([], []), $emitter: emitter});
    definition.__env.document.activeElement = definition.__env.document.body;
    definition.mounted.call(state);
    const control = {tagName: 'BUTTON'};
    definition.__env.listeners.click({target: {closest: () => control}});
    emitter.emit('dam-open-preview', {id: 7, siblings: [7]});
    assert.equal(state.previewOrigin.trigger, control);
    await Promise.resolve();
    assert.equal(state._previewClickTrigger, control, 'capture must survive the native event microtask checkpoint');
    await new Promise(resolve => setTimeout(resolve, 0));
    assert.equal(state._previewClickTrigger, null, 'capture must not leak into a later programmatic launch');
});

test('openById() stores caller-supplied siblings; navigatePrev/navigateNext then walk that order and stop at either end (no wrap)', async () => {
    const definition = previewModalDefinition();
    const calls = [];
    const resolvers = [];
    const state = previewModalState(definition, {$axios: fakeAxios(calls, resolvers)});

    state.openById(20, [10, 20, 30]);
    assert.deepEqual(state.previewSiblings, [10, 20, 30]);
    resolvers.shift()();
    await new Promise((resolve) => setImmediate(resolve));
    assert.equal(state.previewData.id, 20);

    state.navigateNext();
    resolvers.shift()();
    await new Promise((resolve) => setImmediate(resolve));
    assert.equal(state.previewData.id, 30);
    // Internal navigation did not touch the stored order.
    assert.deepEqual(state.previewSiblings, [10, 20, 30]);

    // At the last item, next is a no-op: no further request is made.
    const callsBefore = calls.length;
    state.navigateNext();
    assert.equal(calls.length, callsBefore);

    state.navigatePrev();
    resolvers.shift()();
    await new Promise((resolve) => setImmediate(resolve));
    state.navigatePrev();
    resolvers.shift()();
    await new Promise((resolve) => setImmediate(resolve));
    assert.equal(state.previewData.id, 10);

    // At the first item, prev is a no-op too.
    const callsAtStart = calls.length;
    state.navigatePrev();
    assert.equal(calls.length, callsAtStart);
});

test('navigation is ignored while a load is in flight (re-entrancy guard), then proceeds once it settles', async () => {
    const definition = previewModalDefinition();
    const calls = [];
    const resolvers = [];
    const state = previewModalState(definition, {$axios: fakeAxios(calls, resolvers)});

    state.openById(5, [1, 3, 5, 7]);
    assert.equal(state.isLoading, true);

    // A second navigation press while the first load is still pending is a no-op.
    state.navigateNext();
    assert.equal(calls.length, 1);

    resolvers.shift()();
    await new Promise((resolve) => setImmediate(resolve));
    assert.equal(state.isLoading, false);
    assert.equal(state.previewData.id, 5);

    state.navigateNext();
    assert.equal(calls.length, 2);
    assert.equal(state.isLoading, true);
});

// Goes through the real mitt() bus and the component's actual mounted() listener registration —
// not a direct openById() call and not a hand-simulated listener — because mitt's emit(type,
// event?) carries exactly one payload: an emitter.emit('dam-open-preview', id, {siblings}) call
// silently drops the second argument and only a real emitter round-trip catches that.
test('the real mitt emitter delivers both the ordered {id, siblings} payload (grid/list/gallery) and the bare-id payload (picker/field/context menu) to the mounted listener', async () => {
    const definition = previewModalDefinition();
    const calls = [];
    const resolvers = [];
    const emitter = mitt();
    const state = previewModalState(definition, {$axios: fakeAxios(calls, resolvers), $emitter: emitter});
    definition.mounted.call(state);

    // Ordered caller: a single { id, siblings } object, exactly as grid.blade.php / list.blade.php
    // / gallery.blade.php now emit it.
    emitter.emit('dam-open-preview', {id: 20, siblings: [10, 20, 30]});
    assert.equal(state.isLoading, true, 'the listener never reached openById()');
    assert.deepEqual(state.previewSiblings, [10, 20, 30]);
    resolvers.shift()();
    await new Promise((resolve) => setImmediate(resolve));
    assert.equal(state.previewData.id, 20);
    assert.equal(calls.length, 1);

    // Bare-id caller: picker-modal.blade.php / field.blade.php / context-menu.blade.php still emit
    // just the id, with no ordering context, clearing the stale siblings from the previous open.
    // (Array.from normalizes the vm sandbox's own `[]` literal, from inside the listener, to an
    // outer-realm array — deepStrictEqual treats a same-shape cross-realm array as unequal.)
    emitter.emit('dam-open-preview', 99);
    assert.deepEqual(Array.from(state.previewSiblings), []);
    resolvers.shift()();
    await new Promise((resolve) => setImmediate(resolve));
    assert.equal(state.previewData.id, 99);
    assert.equal(calls.length, 2);
});

// Unlike the test above (which hand-emits the already-correct payload shape), this one calls the
// real production preview()/previewImage() methods from grid.blade.php, list.blade.php and
// datagrid/gallery.blade.php through a real mitt() bus into the modal's real mounted() listener.
// Reintroducing the original 3-argument `emit('dam-open-preview', id, { siblings })` in any of
// those callers — which mitt silently drops the third argument from — would fail this test, where
// hand-writing the payload in the test itself would not have caught it.
test('the real preview()/previewImage() methods on grid, list and gallery deliver the complete sibling order to the mounted listener', () => {
    const assets = [{id: 10}, {id: 20}, {id: 30}];
    const callers = {
        'grid (explorer/grid.blade.php)':   {methods: loadCallerMethods('components/explorer/grid.blade.php'),         call: (methods, emitter) => methods.preview.call({assets, $emitter: emitter}, 20)},
        'list (explorer/list.blade.php)':   {methods: loadCallerMethods('components/explorer/list.blade.php'),         call: (methods, emitter) => methods.preview.call({assets, $emitter: emitter}, 20)},
        'gallery (datagrid/gallery.blade.php)': {methods: loadCallerMethods('components/datagrid/gallery.blade.php'), call: (methods, emitter) => methods.previewImage.call({$parent: {available: {records: assets}}, $emitter: emitter}, 20)},
    };

    for (const [label, {methods, call}] of Object.entries(callers)) {
        const definition = previewModalDefinition();
        const calls = [];
        const resolvers = [];
        const emitter = mitt();
        const state = previewModalState(definition, {$axios: fakeAxios(calls, resolvers), $emitter: emitter});
        definition.mounted.call(state);

        call(methods, emitter);
        assert.equal(state.isLoading, true, `${label}: the listener never reached openById()`);
        assert.deepEqual(Array.from(state.previewSiblings), [10, 20, 30], `${label}: sibling order was not delivered in full`);
    }
});

test('keyboard: plain Left/Right navigate for non-media previews; video/audio navigate too and seek on Shift+Left/Right; input focus is ignored', () => {
    const definition = previewModalDefinition();
    let prevCalls = 0, nextCalls = 0;
    const skips = [];
    const state = previewModalState(definition, {
        isOpen: true,
        navigatePrev() { prevCalls++; },
        navigateNext() { nextCalls++; },
        videoSkip: (amount) => skips.push(['video', amount]),
        audioSkip: (amount) => skips.push(['audio', amount]),
        videoShowControls() {},
    });
    const evt = (key, extra = {}) => ({key, target: {tagName: 'DIV'}, preventDefault() {}, ...extra});

    // Image/PDF/3D/pano: no video or audio ref, plain arrows navigate.
    state.$refs = {};
    state.handleEscape(evt('ArrowRight'));
    assert.equal(nextCalls, 1);
    state.handleEscape(evt('ArrowLeft'));
    assert.equal(prevCalls, 1);

    // Video: plain arrows navigate (holding one never sticks on a player), Shift+arrow seeks.
    state.$refs = {videoEl: {}};
    state.handleEscape(evt('ArrowRight'));
    assert.equal(nextCalls, 2);
    assert.deepEqual(skips, []);
    state.handleEscape(evt('ArrowRight', {shiftKey: true}));
    assert.deepEqual(skips.at(-1), ['video', 5]);
    state.handleEscape(evt('ArrowLeft'));
    assert.equal(prevCalls, 2);
    state.handleEscape(evt('ArrowLeft', {shiftKey: true}));
    assert.deepEqual(skips.at(-1), ['video', -5]);

    // Audio: same split.
    state.$refs = {audioEl: {}};
    state.handleEscape(evt('ArrowLeft'));
    assert.equal(prevCalls, 3);
    state.handleEscape(evt('ArrowLeft', {shiftKey: true}));
    assert.deepEqual(skips.at(-1), ['audio', -5]);
    assert.equal(prevCalls, 3);

    // A key a control already handled (the attachment strip's Alt+Arrow reorder) and any modified arrow belong to that control.
    state.$refs = {videoEl: {}};
    state.handleEscape(evt('ArrowRight', {defaultPrevented: true, altKey: true}));
    state.handleEscape(evt('ArrowRight', {altKey: true}));
    state.handleEscape(evt('ArrowLeft', {ctrlKey: true}));
    assert.equal(nextCalls, 2);
    assert.equal(prevCalls, 3);
    assert.deepEqual(skips.at(-1), ['audio', -5]);

    // Focus in a text input is ignored entirely.
    state.$refs = {};
    state.handleEscape({key: 'ArrowRight', target: {tagName: 'INPUT'}, preventDefault() {}});
    state.handleEscape({key: 'ArrowRight', target: {tagName: 'DIV', isContentEditable: true}, preventDefault() {}});
    assert.equal(nextCalls, 2);
});

test('Escape consumes the active viewer key and closes once; file arrows stay inside the active asset', () => {
    const definition = previewModalDefinition();
    let closed = 0, prevented = 0, stopped = 0;
    const moves = [];
    const state = previewModalState(definition, {isOpen: true, previewVariants: [{id: 1}, {id: 2}], closePreview() { closed++; }, navigateMember: ({direction}) => moves.push(direction)});
    const event = key => ({key, target: {tagName: 'DIV'}, preventDefault() { prevented++; }, stopPropagation() { stopped++; }});
    state.handleEscape(event('ArrowRight'));
    state.handleEscape(event('ArrowLeft'));
    assert.deepEqual(moves, [1, -1]);
    state.handleEscape(event('Escape'));
    assert.equal(closed, 1);
    assert.equal(stopped, 1);
    assert.equal(prevented, 3);
    state.handleEscape({...event('Escape'), defaultPrevented: true});
    assert.equal(closed, 1);
});

test('Library-origin multi-member arrows stay file-scoped at both boundaries; Library navigation remains explicit', () => {
    const definition = previewModalDefinition();
    const files = [{id: 1, asset_id: 7, is_representative: true}, {id: 2, asset_id: 7, mime_type: 'image/png', label: 'Two.png', url: '/two.png'}];
    let resultHops = 0;
    const state = previewModalState(definition, {
        isOpen: true, previewData: {id: 7}, _coverPreviewData: {id: 7}, previewVariants: files,
        previewOrigin: {kind: 'library', assetId: 7}, previewSiblings: [6, 7, 8],
        videoStopOnClose() {}, audioStopOnClose() {}, panoDestroy() {},
        openById() { resultHops++; },
    });
    const key = key => ({key, target: {tagName: 'DIV'}, preventDefault() {}});
    state.handleEscape(key('ArrowLeft'));
    assert.equal(state.previewData.variantFileId, undefined);
    state.handleEscape(key('ArrowRight'));
    assert.equal(state.previewData.variantFileId, 2);
    state.handleEscape(key('ArrowRight'));
    assert.equal(state.previewData.id, 7);
    assert.equal(resultHops, 0, 'member boundary must never silently change Library result');
    state.navigateNext();
    assert.equal(resultHops, 1);
});

// A stack's fan opens one stacked file (D5). That choice must not survive into the next whole-asset
// open, and a fan dismissed while the file is still loading must not open the preview afterwards
// (Codex review of the stack fan-out, 2026-10-09).
test('a stacked file shows by its stored type, does not leak into prev/next, and a cancel drops a pending load', async () => {
    const definition = previewModalDefinition();
    const calls = [];
    const resolvers = [];
    const emitter = mitt();
    const state = previewModalState(definition, {$axios: fakeAxios(calls, resolvers), $emitter: emitter});
    definition.mounted.call(state);

    const pdf = {id: 7, asset_id: 20, label: 'Budget.v2', role: 'alternate', mime_type: 'application/pdf', size: 2048, url: '/f/7', download_url: '/f/7?download=1'};
    emitter.emit('dam-open-preview', {id: 20, siblings: [20], file: pdf, variants: [pdf]});
    resolvers.shift()();
    await new Promise((resolve) => setImmediate(resolve));
    assert.equal(state.previewData.variantFileId, 7);
    assert.equal(state.previewData.extension, 'pdf', 'an editable label suffix picked the viewer');
    assert.equal(state.previewData.downloadCompressedUrl, '', 'the ZIP action would pack the cover, not this file');

    state.openById(30);
    resolvers.shift()();
    await new Promise((resolve) => setImmediate(resolve));
    assert.equal(state.previewData.id, 30);
    assert.equal(state.previewData.variantFileId, undefined, 'the stacked file leaked into a whole-asset open');

    emitter.emit('dam-open-preview', {id: 20, siblings: [20], file: pdf, variants: [pdf]});
    assert.equal(state.isLoading, true);
    emitter.emit('dam-preview-cancel');
    assert.equal(state.isLoading, false);
    resolvers.shift()();
    await new Promise((resolve) => setImmediate(resolve));
    assert.equal(state.isOpen, false, 'a cancelled preview opened when its response arrived');
});

test('an ordered open remembers where its page sits in the whole result; a bare-id open forgets it', () => {
    const definition = previewModalDefinition();
    const emitter = mitt();
    const state = previewModalState(definition, {$axios: fakeAxios([], []), $emitter: emitter});
    definition.mounted.call(state);

    emitter.emit('dam-open-preview', {id: 20, siblings: [10, 20], directoryId: 'all', total: 59, offset: 10});
    assert.deepEqual([state.previewTotal, state.previewOffset], [59, 10]);
    emitter.emit('dam-open-preview', {id: 20, siblings: [20], directoryId: 'all'});
    assert.deepEqual([state.previewTotal, state.previewOffset], [null, null]);
    emitter.emit('dam-open-preview', {id: 20, siblings: [10, 20], total: 59, offset: 10});
    emitter.emit('dam-open-preview', 30);
    assert.deepEqual([state.previewTotal, state.previewOffset], [null, null]);
});

test('mount strips both stale preview markers while preserving the Library snapshot', () => {
    const definition = previewModalDefinition();
    const {history} = definition.__env;
    history.state = {damPreview: true, damPreviewOwner: 17, damLibraryView: {page: 4}};
    definition.mounted.call(previewModalState(definition, {$emitter: mitt()}));
    assert.equal(Object.hasOwn(history.state, 'damPreview'), false);
    assert.equal(Object.hasOwn(history.state, 'damPreviewOwner'), false);
    assert.equal(history.state.damLibraryView.page, 4);
});

test('Back pressed while a next load is in flight drops the response: no reopen, no new history entry, loading and scroll released', async () => {
    const definition = previewModalDefinition();
    const {history, window, document} = definition.__env;
    const calls = [];
    const resolvers = [];
    const state = previewModalState(definition, {$axios: fakeAxios(calls, resolvers), $emitter: mitt()});
    definition.mounted.call(state);

    // An open preview owns one history entry.
    state.openById(5, [1, 3, 5, 7]);
    resolvers.shift()();
    await new Promise((resolve) => setImmediate(resolve));
    state.isOpen = true;
    state.syncPreviewHistory(true);
    assert.equal(history.pushes, 1);

    // Next starts a load (the modal is closed while loading), then Back pops the entry before it resolves.
    state.navigateNext();
    assert.equal(state.isLoading, true);
    assert.equal(document.body.style.overflow, 'hidden', 'the page should not scroll while the next file loads');
    history.state = null;
    const consumed = [...window.__mothershipLocalHistoryHandlers].some(handler => handler({}) === true);
    assert.equal(consumed, true, 'the viewer owns this Back transition, so the router must not remount the Library');
    assert.equal(state.isLoading, false);
    assert.equal(state.isOpen, false);
    assert.equal(document.body.style.overflow, '', 'Back left the page scroll locked');

    resolvers.shift()();
    await new Promise((resolve) => setImmediate(resolve));
    assert.equal(state.isOpen, false, 'the stale response reopened the modal');
    assert.equal(history.pushes, 1, 'the stale response pushed a new history entry');
    assert.notEqual(state.previewData.id, 7);
    assert.equal(document.body.style.overflow, '', 'the stale response re-locked the page scroll');
});
