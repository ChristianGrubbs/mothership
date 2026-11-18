import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import fileOps from '../../src/Resources/assets/js/library-file-ops.js';

// Dropping an asset (or a selection) on a stack card opens the merge dialog with that stack first,
// so it is the default cover; the dialog is the only confirmation.

function loadComponent(relativePath, componentName) {
    const blade = readFileSync(new URL(relativePath, import.meta.url), 'utf8');
    let script = [...blade.matchAll(/<script type="module">([\s\S]*?)<\/script>/g)]
        .map(match => match[1])
        .find(source => source.includes(`app.component('${componentName}'`));
    assert.ok(script, `component ${componentName} not found`);
    // @js() and @json() both print a JS literal; neither runs outside Blade (same stripping as explorer-drop-grants).
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
    }).filter(line => !/^\s*@(if|elseif|else|endif|once|endonce|push|endpush)\b/.test(line)).join('\n')
        .replace(/\{\{[\s\S]*?\}\}/g, '/fixture.svg');
    let component;
    vm.runInNewContext(script, {
        app: { component: (_name, definition) => { component = definition; } },
        window: { MothershipFileOps: fileOps }, document: {},
        localStorage: { getItem: () => null, setItem() {}, removeItem() {} },
        sessionStorage: { getItem: () => null, setItem() {}, removeItem() {} },
    });
    return component;
}

const plain = value => JSON.parse(JSON.stringify(value));
const card = loadComponent('../../src/Resources/views/components/explorer/asset-card.blade.php', 'v-dam-explorer-asset-card');
const tab = loadComponent('../../src/Resources/views/components/explorer/tab.blade.php', 'v-dam-tab');

function dataTransfer(payload, { files = false } = {}) {
    return {
        types: files ? ['Files'] : ['application/json'],
        getData: () => JSON.stringify(payload),
    };
}

function cardInstance(asset) {
    const emitted = [];
    const instance = {
        ...card.methods, asset, isDropTarget: false, _dragging: false, $emit: (...args) => emitted.push(args), $emitter: { emit() {} },
        $el: { contains: () => false },
        isStack: Number(asset.attachment_count || 0) > 1,
    };
    return { instance, emitted };
}

function dropEvent(payload, options) {
    const calls = { prevented: 0, stopped: 0 };
    return { calls, event: { dataTransfer: dataTransfer(payload, options), preventDefault: () => calls.prevented++, stopPropagation: () => calls.stopped++ } };
}

test('a stack card accepts a dam-asset drop and emits the target with the payload', () => {
    const { instance, emitted } = cardInstance({ id: 10, attachment_count: 3 });
    const payload = { type: 'dam-asset', id: 5, name: 'a.png', tabId: 't1' };
    const over = dropEvent(payload);
    instance.onStackDragOver(over.event);
    assert.equal(over.calls.prevented, 1);
    assert.equal(instance.isDropTarget, true);

    const drop = dropEvent(payload);
    instance.onStackDrop(drop.event);
    assert.equal(instance.isDropTarget, false);
    assert.equal(drop.calls.prevented, 1);
    assert.deepEqual(plain(emitted), [['stack-drop', { payload, asset: { id: 10, attachment_count: 3 } }]]);
});

test('a non-stack card, its own drag, folder payloads and OS files are not drop targets', () => {
    const payload = { type: 'dam-asset', id: 5, tabId: 't1' };
    const plainCard = cardInstance({ id: 10, attachment_count: 1 });
    const over = dropEvent(payload);
    plainCard.instance.onStackDragOver(over.event);
    plainCard.instance.onStackDrop(dropEvent(payload).event);
    assert.equal(over.calls.prevented, 0);
    assert.equal(plainCard.instance.isDropTarget, false);
    assert.deepEqual(plainCard.emitted, []);

    const stack = cardInstance({ id: 10, attachment_count: 2 });
    stack.instance._dragging = true;
    stack.instance.onStackDrop(dropEvent({ ...payload, id: 10 }).event);
    stack.instance._dragging = false;
    stack.instance.onStackDrop(dropEvent({ type: 'dam-folder', id: 3 }).event);
    const files = dropEvent(payload, { files: true });
    stack.instance.onStackDragOver(files.event);
    stack.instance.onStackDrop(files.event);
    assert.equal(files.calls.prevented, 0);
    assert.deepEqual(stack.emitted, []);
});

function tabInstance({ assets, tabId = 't1' }) {
    const emitted = [];
    const instance = {
        ...tab.methods, tabId, assets,
        $emitter: { emit: (event, payload) => emitted.push({ event, payload }) },
    };
    return { instance, emitted };
}

const records = [
    { id: 10, display_name: 'Stack', attachment_count: 3, stack_basis: 'sku', stack_key: 'A1', path: '/s.png', extension: 'png' },
    { id: 5, file_name: 'five.png', path: '/5.png', extension: 'png' },
    { id: 6, file_name: 'six.png' },
];

test('a single drop opens the merge dialog with the stack first', () => {
    const { instance, emitted } = tabInstance({ assets: records });
    instance.onStackDrop({ payload: { type: 'dam-asset', id: 5, name: 'five.png', tabId: 't1' }, asset: records[0] });
    assert.equal(emitted.length, 1);
    assert.equal(emitted[0].event, 'dam:open-merge-dialog');
    assert.equal(emitted[0].payload.context, 'explorer:t1');
    assert.deepEqual(plain(emitted[0].payload.assets.map(a => a.id)), [10, 5]);
    assert.equal(emitted[0].payload.assets[0].attachment_count, 3);
    assert.equal(emitted[0].payload.assets[0].stack_key, 'A1');
});

test('a selection drop dedupes and leaves the target out of the sources', () => {
    const { instance, emitted } = tabInstance({ assets: records });
    instance.onStackDrop({ payload: { type: 'dam-asset', id: 5, tabId: 't1', ids: [5, 10, 6, 5] }, asset: records[0] });
    assert.deepEqual(plain(emitted[0].payload.assets.map(a => a.id)), [10, 5, 6]);
});

test('dropping the stack onto itself opens nothing', () => {
    const { instance, emitted } = tabInstance({ assets: records });
    instance.onStackDrop({ payload: { type: 'dam-asset', id: 10, tabId: 't1' }, asset: records[0] });
    assert.deepEqual(emitted, []);
});

test('a selection from another tab is handed to its origin tab', () => {
    const { instance, emitted } = tabInstance({ assets: records, tabId: 't2' });
    const payload = { type: 'dam-asset', id: 5, tabId: 't1', ids: [5, 6] };
    instance.onStackDrop({ payload, asset: records[0] });
    assert.equal(emitted.length, 1);
    assert.equal(emitted[0].event, 'dam:explorer-stack-drop:t1');
    assert.equal(emitted[0].payload.refreshTabId, 't2');
});

// --- Cross-tab drops, driven through the real dialog show() and the real merge-done handler. ---

const dialog = loadComponent('../../src/Resources/views/components/merge/dialog.blade.php', 'v-dam-merge-dialog');

/** What the open dialog would hold after the request, running the dialog's own show(). */
function openDialog(request) {
    const emitted = [];
    const instance = {
        ...dialog.methods, assets: [], t: { needTwo: 'need two' }, queue: [], open: false, alsoRefresh: [],
        $emitter: { emit: (event, payload) => emitted.push({ event, payload }) },
        $nextTick: () => {}, $refs: {}, loadPreview() {}, showNext() {},
    };
    instance.show(request.assets, request.context, request.reviewId, request);
    return instance;
}

// Tab t2 shows the stack; tab t1 (disjoint listing) shows the dragged files.
const targetStack = { id: 10, display_name: 'Target stack', attachment_count: 3, stack_basis: 'sku', stack_key: 'A1', path: '/s.png', extension: 'png' };
const t1Assets = [
    { id: 5, file_name: 'five.png', path: '/5.png', extension: 'png' },
    { id: 6, file_name: 'six.png', attachment_count: 2, stack_basis: 'sku', stack_key: 'Z9' },
    { id: 7, file_name: 'seven.png' },
];

function handoff({ ids, sourceAssets = t1Assets }) {
    const t1 = tabInstance({ assets: sourceAssets, tabId: 't1' });
    const t2 = tabInstance({ assets: [targetStack], tabId: 't2' });
    const payload = { type: 'dam-asset', id: ids[0], name: 'x', tabId: 't1', ...(ids.length > 1 ? { ids } : {}) };
    // t2 receives the drop on its card.
    t2.instance.onStackDrop({ payload, asset: targetStack });
    let request = t2.emitted.find(e => e.event === 'dam:open-merge-dialog')?.payload;
    if (! request) {
        // Selection from another tab: handed to the origin tab, which resolves the records.
        const handed = t2.emitted.find(e => e.event === 'dam:explorer-stack-drop:t1').payload;
        t1.instance.onStackDrop(handed);
        request = t1.emitted.find(e => e.event === 'dam:open-merge-dialog').payload;
    }
    return request;
}

test('cross-tab selection: the dropped-on stack keeps its name, count and label and stays the cover, even over a stack source', () => {
    for (const ids of [[5, 7], [5, 6, 7]]) {
        const request = handoff({ ids });
        const dlg = openDialog(request);
        assert.equal(dlg.destinationId, 10, `ids ${ids}`);
        const cover = dlg.assets.find(a => a.id === 10);
        assert.equal(cover.name, 'Target stack');
        assert.equal(cover.files, 3);
        assert.equal(dlg.basis, 'sku');
        assert.equal(dlg.key, 'A1');
        assert.deepEqual(plain(dlg.assets.map(a => a.id).filter(id => id !== dlg.destinationId)), ids);
    }
});

test('cross-tab single drop resolves the same way and also refreshes the origin tab', () => {
    const request = handoff({ ids: [6] });
    assert.equal(openDialog(request).destinationId, 10);
    assert.equal(request.context, 'explorer:t2');
    assert.deepEqual(plain(request.alsoRefresh), ['explorer:t1']);
});

test('cross-tab single drop of a stack resolves it in its origin tab, so making it the cover keeps its own label', () => {
    const t2 = tabInstance({ assets: [targetStack], tabId: 't2' });
    t2.instance.onStackDrop({ payload: { type: 'dam-asset', id: 6, name: 'six.png', tabId: 't1' }, asset: targetStack });
    assert.deepEqual(plain(t2.emitted.map(e => e.event)), ['dam:explorer-stack-drop:t1']);

    const dlg = openDialog(handoff({ ids: [6] }));
    const source = dlg.assets.find(a => a.id === 6);
    assert.deepEqual(plain([source.files, source.stack_basis, source.stack_key]), [2, 'sku', 'Z9']);

    // Switch the cover to the dragged stack, through the dialog's own computed and watcher.
    for (const name of ['destination', 'labelChanged']) {
        Object.defineProperty(dlg, name, { get: dialog.computed[name].bind(dlg), configurable: true });
    }
    Object.assign(dlg, { open: true, seeding: false, labelTouched: false });
    dlg.destinationId = 6;
    dialog.watch.destinationId.call(dlg, 6, 10);
    assert.deepEqual(plain([dlg.basis, dlg.key, dlg.labelChanged]), ['sku', 'Z9', false]);
});

test('a same-tab drop needs no extra refresh', () => {
    const t = tabInstance({ assets: [targetStack, ...t1Assets], tabId: 't1' });
    t.instance.onStackDrop({ payload: { type: 'dam-asset', id: 5, tabId: 't1' }, asset: targetStack });
    assert.deepEqual(plain(t.emitted[0].payload.alsoRefresh), []);
});

function mergeDoneTab(tabId) {
    const calls = { cleared: 0, fetched: 0 };
    const instance = { ...tab.methods, tabId, pendingStackHighlight: null, clearSelection: () => calls.cleared++, fetch: () => calls.fetched++ };
    return { instance, calls };
}

test('a completed cross-tab merge refreshes both tabs and clears the origin selection', () => {
    const request = handoff({ ids: [5, 7] });
    const done = { context: request.context, alsoRefresh: request.alsoRefresh, destinationId: 10 };
    const origin = mergeDoneTab('t1');
    const receiver = mergeDoneTab('t2');
    const bystander = mergeDoneTab('t3');
    for (const t of [origin, receiver, bystander]) t.instance.onMergeDone(done);
    assert.deepEqual(origin.calls, { cleared: 1, fetched: 1 });
    assert.deepEqual(receiver.calls, { cleared: 1, fetched: 1 });
    assert.equal(receiver.instance.pendingStackHighlight, 10);
    assert.equal(origin.instance.pendingStackHighlight, null);
    assert.deepEqual(bystander.calls, { cleared: 0, fetched: 0 });
});

test('cancelling the dialog leaves every tab and its selection alone', () => {
    const request = handoff({ ids: [5, 7] });
    const dlg = openDialog(request);
    const origin = mergeDoneTab('t1');
    const emitted = [];
    dlg.$emitter = { emit: (event, payload) => emitted.push({ event, payload }) };
    dlg.$nextTick = () => {};
    dlg.close();
    assert.deepEqual(emitted.map(e => e.event), ['dam:merge-dialog-closed']);
    assert.equal(emitted[0].payload.merged, false);
    assert.deepEqual(origin.calls, { cleared: 0, fetched: 0 });
});
