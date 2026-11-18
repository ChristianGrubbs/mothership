import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

// Perf pass LB-05: a fresh Library load asked for the root's folder counts twice. loadDirectories() counts the
// root and its children, then the root's tree-item component mounted and counted the same children again.

const treeBlade = new URL('../../src/Resources/views/components/tree/damdirectories.blade.php', import.meta.url);

function loadComponent(name) {
    const blade = readFileSync(treeBlade, 'utf8');
    const script = [...blade.matchAll(/<script type="module">([\s\S]*?)<\/script>/g)]
        .map(match => match[1])
        .find(source => source.includes(`app.component('${name}'`));
    assert.ok(script, `component ${name} not found`);
    const source = script.split('\n')
        .map(line => line.replace(/@(js|json)\(([^()]|\([^()]*\))*\)/g, 'null'))
        .filter(line => !/^\s*@(if|elseif|else|endif|once|endonce|push|endpush|pushOnce|endPushOnce)\b/.test(line))
        .join('\n');
    let component;
    vm.runInNewContext(source, {
        app: { component: (_n, definition) => { component = definition; } },
        window: {}, document: {},
        localStorage: { getItem: () => null, setItem() {}, removeItem() {} },
        sessionStorage: { getItem: () => null, setItem() {}, removeItem() {} },
    });
    assert.ok(component);
    return component;
}

const view = loadComponent('v-dam-tree-view');
const item = loadComponent('v-dam-tree-item');

function axiosRecorder() {
    const posts = [];
    return { posts, $axios: { post: (_url, body) => { posts.push(JSON.parse(JSON.stringify(body.ids))); return Promise.resolve({ data: { data: {} } }); } } };
}

test('a fresh load requests each folder count once: the root item skips children the tree already counted', () => {
    const { posts, $axios } = axiosRecorder();
    const root = { id: 1, children: [{ id: 2 }, { id: 3 }] };
    const treeVm = { $axios };
    view.methods.fetchCountsForNodes.call(treeVm, [root, ...root.children]);

    const itemVm = { $axios, item: root, fetchChildCounts: item.methods.fetchChildCounts };
    itemVm.fetchChildCounts = item.methods.fetchChildCounts.bind(itemVm);
    item.mounted.call(Object.assign(itemVm, { localAssets: { splice() {} }, $emitter: { on() {} }, $nextTick() {} }));

    assert.deepEqual(posts, [[1, 2, 3]]);
});

test('children nobody has counted yet are still requested, and refreshCounts always re-asks', () => {
    const { posts, $axios } = axiosRecorder();
    const root = { id: 1, children: [{ id: 2 }, { id: 3 }] };
    view.methods.fetchCountsForNodes.call({ $axios }, [root, root.children[0]]);

    const itemVm = { $axios, item: root, localAssets: { splice() {} }, $emitter: { on() {} }, $nextTick() {} };
    itemVm.fetchChildCounts = item.methods.fetchChildCounts.bind(itemVm);
    item.mounted.call(itemVm);
    assert.deepEqual(posts, [[1, 2], [3]]);

    posts.length = 0;
    const refreshVm = { $axios, formattedItems: [root], showAssets: false, fetchCountsForNodes: view.methods.fetchCountsForNodes };
    refreshVm.fetchCountsForNodes = view.methods.fetchCountsForNodes.bind(refreshVm);
    view.methods.refreshCounts.call(refreshVm);
    assert.deepEqual(posts, [[1, 2, 3]]);
});

// A tree whose server answers with controlled counts. `server.counts` is mutable, as it is after an upload or delete.
function countingServer(server) {
    const posts = [];
    const $axios = {
        interceptors: { response: { use() {} }, request: { use() {} } },
        get: () => Promise.resolve({ data: { data: JSON.parse(JSON.stringify(server.tree)) } }),
        post: (_url, body) => {
            const ids = JSON.parse(JSON.stringify(body.ids));
            posts.push(ids);
            const data = {};
            ids.forEach(id => { if (server.counts[id] !== undefined) data[id] = server.counts[id]; });
            return Promise.resolve({ data: { data } });
        },
    };
    return { posts, $axios };
}

const settle = () => new Promise(resolve => setImmediate(resolve));

function treeVm({ $axios, handlers }) {
    const vm = {
        $axios, formattedItems: [], showAssets: false, treeLoading: false, selectedItem: null, visible: false,
        $emitter: { on: (name, fn) => { handlers[name] = fn; }, emit() {} },
        $nextTick() {}, adjustAncestorCounts() {},
    };
    for (const name of ['loadDirectories', 'fetchCountsForNodes', 'refreshCounts', 'loadRootAssets', 'adjustAncestorCounts']) {
        if (view.methods[name]) vm[name] = view.methods[name].bind(vm);
    }
    return vm;
}

test('the displayed count follows a mutation: the reload after an upload or delete asks again and shows the new number', async () => {
    const server = { tree: [{ id: 1, children: [{ id: 2 }, { id: 3 }] }], counts: { 1: 10, 2: 6, 3: 4 } };
    const { posts, $axios } = countingServer(server);
    const handlers = {};
    const vm = treeVm({ $axios, handlers });
    view.mounted.call(vm);
    assert.equal(typeof handlers['dam:tree-reload'], 'function');

    vm.loadDirectories();
    await settle();
    assert.deepEqual(posts, [[1, 2, 3]]);
    assert.deepEqual(vm.formattedItems[0].children.map(child => child.assets_total_count), [6, 4]);
    assert.equal(vm.formattedItems[0].assets_total_count, 10);

    // A file in folder 2 is deleted; the reload the delete triggers shows the new counts.
    server.counts = { 1: 9, 2: 5, 3: 4 };
    posts.length = 0;
    handlers['dam:tree-reload']();
    await settle();
    assert.deepEqual(posts, [[1, 2, 3]]);
    assert.deepEqual(vm.formattedItems[0].children.map(child => child.assets_total_count), [5, 4]);
    assert.equal(vm.formattedItems[0].assets_total_count, 9);
});

test('a second loadDirectories() requests fresh counts again and carries nothing over from the first', async () => {
    const server = { tree: [{ id: 1, children: [{ id: 2 }] }], counts: { 1: 3, 2: 2 } };
    const { posts, $axios } = countingServer(server);
    const vm = treeVm({ $axios, handlers: {} });

    vm.loadDirectories();
    await settle();
    const firstRoot = vm.formattedItems[0];
    server.counts = { 1: 8, 2: 7 };
    vm.loadDirectories();
    await settle();

    assert.deepEqual(posts, [[1, 2], [1, 2]]);
    assert.notEqual(vm.formattedItems[0], firstRoot);
    assert.equal(vm.formattedItems[0]._countStamp, 1);
    assert.deepEqual([vm.formattedItems[0].assets_total_count, vm.formattedItems[0].children[0].assets_total_count], [8, 7]);
});

test('an older counts answer that arrives after a newer refresh is dropped (the _countStamp guard)', async () => {
    const node = { id: 5 };
    const pending = [];
    const $axios = { post: () => new Promise(resolve => pending.push(resolve)) };
    const vm = { $axios };

    view.methods.fetchCountsForNodes.call(vm, [node]);
    view.methods.fetchCountsForNodes.call(vm, [node]);
    pending[1]({ data: { data: { 5: 20 } } });
    await settle();
    pending[0]({ data: { data: { 5: 11 } } });
    await settle();

    assert.equal(node.assets_total_count, 20);
});
