import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import ops from '../../src/Resources/assets/js/library-file-ops.js';

// Regression: the classic Library grid (DAM_EXPLORER_ENABLED unset) listed a folder's assets
// recursively but never showed its subfolders, so a folder full of only sub-folders looked empty.
// The v-dam-gallery-folders strip fills that gap with folder cards above the asset grid.

function loadComponent(relativePath, componentName) {
    const blade = readFileSync(new URL(relativePath, import.meta.url), 'utf8');
    let script = [...blade.matchAll(/<script type="module">([\s\S]*?)<\/script>/g)]
        .map(match => match[1])
        .find(source => source.includes(`app.component('${componentName}'`));
    assert.ok(script, `component ${componentName} not found in ${relativePath}`);
    script = script.replace(/@json\(/g, () => 'null/*@json(');
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
    }).filter(line => !/^\s*@(if|elseif|else|endif|once|endonce|push|endpush)\b/.test(line)).join('\n');
    let component;
    vm.runInNewContext(script, {
        app: { component: (_name, definition) => { component = definition; } },
        window: { MothershipFileOps: ops }, document: {},
        localStorage: { getItem: () => null, setItem() {}, removeItem() {} },
        sessionStorage: { getItem: () => null, setItem() {}, removeItem() {} },
        setTimeout, clearTimeout,
    });
    return component;
}

const folders = loadComponent('../../src/Resources/views/components/datagrid/folders.blade.php', 'v-dam-gallery-folders');

// The children URL is built from an un-rendered Blade route() call (`':id'` substituted in verbatim
// by .replace), so the substituted id shows up as `'<id>')` in the resulting string — match on that.
function idFromUrl(url) {
    const match = url.match(/'(\d+)'\)/);
    return match ? match[1] : null;
}

function makeInstance({ responses = {}, failFor = null } = {}) {
    const emitted = [];
    const posts = [];
    const gets = [];
    const instance = {
        currentDirId: null,
        rootId: null,
        children: [],
        offset: 0,
        limit: 100,
        hasMore: false,
        dragOverId: null,
        _reloadTimer: null,
        _requestId: 0,
        _lastRequestedId: null,
        $emitter: { emit: (event, payload) => emitted.push({ event, payload }) },
        $axios: {
            get: (url, opts) => {
                gets.push({ url, opts });
                const id = idFromUrl(url);
                if (failFor != null && String(failFor) === id) return Promise.reject(new Error('boom'));
                if (id != null && responses[id]) return Promise.resolve(responses[id]);
                return Promise.resolve({ data: { data: [], has_more: false } });
            },
            post: (url, body) => { posts.push({ url, body }); return Promise.resolve({ data: { data: {} } }); },
        },
    };
    // Methods call sibling methods via `this.load()` etc, so the instance needs the real
    // implementations attached, not just the single method under test.
    for (const [name, fn] of Object.entries(folders.methods)) instance[name] = fn;
    return { instance, emitted, posts, gets };
}

function dataTransfer(payload, types = []) {
    return { types, getData: () => (payload === null ? '' : JSON.stringify(payload)) };
}

// scheduleReload()'s debounce is a real 200ms setTimeout (the sandbox is wired to the real
// timers), so tests that go through it just wait it out rather than faking time.
function flush(ms = 260) {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

test('a folder click emits dam:reveal-directory with the id', () => {
    const { instance, emitted } = makeInstance();

    folders.methods.onClick.call(instance, { id: 42, name: 'Alien' });

    // Cross-realm objects (created inside the vm sandbox) fail deepStrictEqual on their prototype,
    // so compare via JSON rather than assert.deepEqual.
    assert.equal(emitted.length, 1);
    assert.equal(emitted[0].event, 'dam:reveal-directory');
    assert.equal(JSON.stringify(emitted[0].payload), JSON.stringify({ id: 42 }));
});

test('a drop with a dam-asset payload emits dam:native-asset-drop and stops propagation', () => {
    const { instance, emitted } = makeInstance();
    let prevented = false;
    let stopped = false;
    const event = {
        dataTransfer: dataTransfer({ type: 'dam-asset', id: 5, ids: [5, 6] }),
        preventDefault: () => { prevented = true; },
        stopPropagation: () => { stopped = true; },
    };

    folders.methods.onDrop.call(instance, event, { id: 42, name: 'Alien' });

    assert.equal(prevented, true);
    assert.equal(stopped, true);
    assert.equal(emitted.length, 1);
    assert.equal(emitted[0].event, 'dam:native-asset-drop');
    assert.equal(JSON.stringify(emitted[0].payload), JSON.stringify({ targetId: 42, targetName: 'Alien', ids: [5, 6] }));
});

test('a drop with no JSON payload emits nothing and does not stop propagation', () => {
    const { instance, emitted } = makeInstance();
    let prevented = false;
    let stopped = false;
    const event = {
        dataTransfer: dataTransfer(null),
        preventDefault: () => { prevented = true; },
        stopPropagation: () => { stopped = true; },
    };

    folders.methods.onDrop.call(instance, event, { id: 42, name: 'Alien' });

    assert.equal(prevented, false);
    assert.equal(stopped, false);
    assert.deepEqual(emitted, []);
});

test('a null current directory falls back to the root id once dam:tree-root arrives', async () => {
    const { instance, gets } = makeInstance();

    instance.onCurrentDirectory(null);
    assert.equal(instance.currentDirId, null);
    // onCurrentDirectory now routes through the debounced scheduleReload, so no request fires yet.
    assert.equal(gets.length, 0, 'no request fires synchronously from current-directory');

    instance.onTreeRoot({ id: 7, name: 'Root' });
    // onTreeRoot also routes through scheduleReload now, so the request is still pending here.
    assert.equal(gets.length, 0, 'no request fires synchronously from dam:tree-root either');

    await flush();

    assert.equal(instance.rootId, 7);
    assert.equal(gets.length, 1, 'the root children load once the debounce settles');
    assert.ok(gets[0].url.includes("'7')"), `expected the root id substituted into the URL, got: ${gets[0].url}`);
});

test('onCurrentDirectory debounces then loads exactly once, with offset matching the returned page', async () => {
    const { instance, gets } = makeInstance({ responses: { 8: { data: { data: [{ id: 101 }, { id: 102 }, { id: 103 }], has_more: false } } } });

    instance.onCurrentDirectory({ id: 8 });
    assert.equal(gets.length, 0, 'debounced — no request fires synchronously');

    await flush();

    assert.equal(gets.length, 1, 'exactly one children request for directory 8');
    assert.ok(gets[0].url.includes("'8')"), `expected directory 8 in the URL, got: ${gets[0].url}`);
    assert.equal(instance.offset, 3, 'offset tracks the length of the returned page');
});

test('a second dam:tree-root for the same id after the first request was issued does not reload', async () => {
    const { instance, gets } = makeInstance();

    instance.onTreeRoot({ id: 1 });
    await flush();
    assert.equal(gets.length, 1, 'the first root arrival loads once');

    instance.onTreeRoot({ id: 1 });
    await flush();
    assert.equal(gets.length, 1, 'a repeat dam:tree-root for the same, already-requested id triggers no second request');
});

test('a failed request for the still-current target resets children, hasMore, and offset', async () => {
    const { instance, gets } = makeInstance({ failFor: 5 });
    instance.children = [{ id: 1 }, { id: 2 }];
    instance.hasMore = true;
    instance.offset = 2;

    instance.onCurrentDirectory({ id: 5 });
    await flush();

    assert.equal(gets.length, 1);
    // Cross-realm arrays (assigned inside the vm sandbox) fail deepStrictEqual on their prototype,
    // so assert on length rather than assert.deepEqual([]).
    assert.equal(instance.children.length, 0, 'a failed load must not leave the old directory\'s cards attributed to the new one');
    assert.equal(instance.hasMore, false);
    assert.equal(instance.offset, 0);
});

test('load() with no target id clears any previously shown children', () => {
    const { instance } = makeInstance();
    instance.children = [{ id: 9 }];
    instance.hasMore = true;
    instance.offset = 1;
    instance.currentDirId = null;
    instance.rootId = null;

    instance.load();

    assert.equal(instance.children.length, 0);
    assert.equal(instance.hasMore, false);
    assert.equal(instance.offset, 0);
});

test('dragover with an application/json payload highlights the card, a Files drag does not', () => {
    const { instance } = makeInstance();
    const dir = { id: 42, name: 'Alien' };

    folders.methods.onDragOver.call(instance, { dataTransfer: { types: ['application/json'] } }, dir);
    assert.equal(instance.dragOverId, 42, 'an asset drag (application/json, no Files) highlights the target card');

    folders.methods.onDragOver.call(instance, { dataTransfer: { types: ['Files'] } }, dir);
    assert.equal(instance.dragOverId, null, 'an OS file drag must not highlight the card');

    folders.methods.onDragOver.call(instance, { dataTransfer: { types: ['application/json', 'Files'] } }, dir);
    assert.equal(instance.dragOverId, null, 'Files present alongside application/json still must not highlight');
});

test('the classic Library datagrid mounts v-dam-gallery-folders inside the directory-index permission gate', () => {
    const blade = readFileSync(new URL('../../src/Resources/views/components/datagrid/dam.blade.php', import.meta.url), 'utf8');
    const gateMatch = blade.match(/@if\s*\(\s*bouncer\(\)->hasPermission\('dam\.directory\.index'\)\s*\)([\s\S]*?)@endif/);
    assert.ok(gateMatch, 'expected a dam.directory.index permission gate in dam.blade.php');
    assert.match(gateMatch[1], /<v-dam-gallery-folders\b/, 'the folders strip must actually be mounted as a Vue element, not just registered');
});

// Layout shift (PostHog UX review, Finding 5): the strip used to appear only after a debounced XHR and
// pushed the asset grid down ~142 px. The page now renders the root's first page into a `seed` prop.
test('data() starts from the server seed so the strip has its height at mount', () => {
    const seed = { root_id: 1, children: [{ id: 7, name: 'A', assets_total_count: 3 }], has_more: true };
    const state = folders.data.call({ seed, restoresDirectory: () => false });

    assert.equal(state.rootId, 1);
    assert.equal(state.children.length, 1);
    assert.equal(state.offset, 1);
    assert.equal(state.hasMore, true);
    assert.equal(state._lastRequestedId, 1, 'the tree root emit for the seeded root must not refetch it');
});

test('data() drops the seed when the page is about to reopen a saved folder', () => {
    const seed = { root_id: 1, children: [{ id: 7, name: 'A' }], has_more: false };
    const state = folders.data.call({ seed, restoresDirectory: () => true });

    assert.equal(state.rootId, null);
    assert.equal(state.children.length, 0);
    assert.equal(state._lastRequestedId, null);
});

test('a reload keeps the counts already shown until fresh counts arrive', async () => {
    const { instance } = makeInstance({ responses: { 1: { data: { data: [{ id: 7, name: 'A' }, { id: 8, name: 'B' }], has_more: false } } } });
    instance.rootId = 1;
    instance.children = [{ id: 7, name: 'A', assets_total_count: 12 }];

    instance.load();
    await flush(10);

    assert.equal(instance.children[0].assets_total_count, 12, 'a badge that drops out rewraps the name and nudges the grid');
    assert.equal(instance.children[1].assets_total_count, undefined);
});
