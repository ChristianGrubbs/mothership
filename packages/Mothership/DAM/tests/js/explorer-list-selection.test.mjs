import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

/**
 * Extracts a Vue component definition from a Blade file's inline module script.
 * Blade directives that would not parse as JavaScript (`@json(...)`, `@if`/`@endif` lines)
 * are stripped so the component script can be evaluated in isolation.
 */
function loadComponent(relativePath, componentName) {
    const blade = readFileSync(new URL(relativePath, import.meta.url), 'utf8');
    let script = [...blade.matchAll(/<script type="module">([\s\S]*?)<\/script>/g)]
        .map(match => match[1])
        .find(source => source.includes(`app.component('${componentName}'`));

    assert.ok(script, `component ${componentName} not found in ${relativePath}`);

    // @js() and @json() both print a JS literal; neither runs outside Blade.
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
    }).filter(line => !/^\s*@(if|elseif|else|endif|once|endonce|push|endpush)\b/.test(line)).join('\n');

    let component;
    vm.runInNewContext(script, {
        app: { component: (_name, definition) => { component = definition; } },
        window: {},
        document: {},
        localStorage: { getItem: () => null, setItem() {}, removeItem() {} },
        sessionStorage: { getItem: () => null, setItem() {}, removeItem() {} },
        setTimeout, clearTimeout,
    });
    return component;
}

function memoryStorage() {
    const store = new Map();
    return {
        getItem: key => (store.has(key) ? store.get(key) : null),
        setItem: (key, value) => store.set(key, String(value)),
        removeItem: key => store.delete(key),
        keys: () => [...store.keys()],
    };
}

const listComponent = loadComponent('../../src/Resources/views/components/explorer/list.blade.php', 'v-dam-explorer-list');
const tabComponent  = loadComponent('../../src/Resources/views/components/explorer/tab.blade.php', 'v-dam-tab');

function listInstance({ selectedIds = [] } = {}) {
    const emitted = [];
    const instance = {
        ...listComponent.methods,
        ...listComponent.data(),
        directories: [{ id: 1, name: 'A' }, { id: 2, name: 'B' }],
        assets: [{ id: 10, file_name: 'x.png' }, { id: 11, file_name: 'y.png' }, { id: 12, file_name: 'z.png' }],
        selection: { ids: selectedIds, mode: selectedIds.length ? 'partial' : 'none' },
        $emit: (name, ...args) => emitted.push({ name, args }),
    };
    return { instance, emitted };
}

const plainClick = () => ({ metaKey: false, ctrlKey: false, shiftKey: false, detail: 1 });
// Objects created inside the vm realm have a foreign prototype; compare by value only.
const plain = value => JSON.parse(JSON.stringify(value));

test('list row click opens the item when nothing is selected', () => {
    const { instance, emitted } = listInstance();
    let opened = 0;
    instance.onRowClick(plainClick(), instance.assets[0], 'asset', () => opened++);
    assert.equal(opened, 1);
    assert.deepEqual(emitted, []);
});

test('list row click toggles selection instead of opening once a selection exists', () => {
    const { instance, emitted } = listInstance({ selectedIds: [{ id: 10, type: 'asset' }] });
    let opened = 0;
    instance.onRowClick(plainClick(), instance.assets[1], 'asset', () => opened++);
    assert.equal(opened, 0);
    assert.deepEqual(emitted, [{ name: 'toggle-select', args: [11, 'asset'] }]);
});

test('cmd/ctrl click toggles selection even with an empty selection', () => {
    const { instance, emitted } = listInstance();
    let opened = 0;
    instance.onRowClick({ ...plainClick(), metaKey: true }, instance.directories[0], 'directory', () => opened++);
    assert.equal(opened, 0);
    assert.deepEqual(emitted, [{ name: 'toggle-select', args: [1, 'directory'] }]);
});

test('shift click selects the visible range from the last toggled row', () => {
    const { instance, emitted } = listInstance({ selectedIds: [{ id: 2, type: 'directory' }] });
    instance.markAnchor(2, 'directory');
    instance.onRowClick({ ...plainClick(), shiftKey: true }, instance.assets[1], 'asset', () => { throw new Error('must not open'); });
    assert.deepEqual(plain(emitted), [{
        name: 'select-range',
        args: [[{ id: 2, type: 'directory' }, { id: 10, type: 'asset' }, { id: 11, type: 'asset' }]],
    }]);
});

test('shift click without an anchor falls back to a plain toggle', () => {
    const { instance, emitted } = listInstance();
    instance.onRowClick({ ...plainClick(), shiftKey: true }, instance.assets[2], 'asset', () => { throw new Error('must not open'); });
    assert.deepEqual(emitted, [{ name: 'toggle-select', args: [12, 'asset'] }]);
});

test('checkbox cell click toggles and records the range anchor', () => {
    const { instance, emitted } = listInstance();
    instance.onCheckboxClick(plainClick(), instance.assets[2], 'asset');
    assert.deepEqual(emitted, [{ name: 'toggle-select', args: [12, 'asset'] }]);
    assert.deepEqual(plain(instance.rangeAnchor), { id: 12, type: 'asset' });
});

function tabInstance({ storage = memoryStorage(), currentDirId = 7, selectedIds = [] } = {}) {
    const emitted = [];
    const instance = {
        ...tabComponent.methods,
        selection: { ids: selectedIds, mode: selectedIds.length ? 'partial' : 'none' },
        dirs: [{ id: 1 }, { id: 2 }],
        assets: [{ id: 10 }, { id: 11 }, { id: 12 }],
        currentDirId,
        scope: currentDirId ? 'directory' : 'all',
        tabId: 'tab-1',
        foreignSelections: {},
        _heldSelectionDir: null,
        $emitter: { emit: (name, payload) => emitted.push({ name, payload }), on() {}, off() {} },
        _selectionStorage: () => storage,
    };
    return { instance, emitted, storage };
}

test('selectRange adds every item once and recomputes the mode', () => {
    const { instance } = tabInstance({ selectedIds: [{ id: 10, type: 'asset' }] });
    instance.selectRange([{ id: 10, type: 'asset' }, { id: 11, type: 'asset' }, { id: 2, type: 'directory' }]);
    assert.deepEqual(plain(instance.selection.ids), [{ id: 10, type: 'asset' }, { id: 11, type: 'asset' }, { id: 2, type: 'directory' }]);
    assert.equal(instance.selection.mode, 'partial');
});

test('selection is persisted per folder and removed when cleared', () => {
    const { instance, storage } = tabInstance();
    instance.toggleSelect(10, 'asset');
    instance.toggleSelect(1, 'directory');
    assert.deepEqual(storage.keys(), ['dam_explorer_selection:7']);
    assert.deepEqual(JSON.parse(storage.getItem('dam_explorer_selection:7')), [{ id: 10, type: 'asset' }, { id: 1, type: 'directory' }]);

    instance.clearSelection();
    assert.deepEqual(storage.keys(), []);
});

test('restoreSelection re-applies the whole stored selection, including items not on the current page', () => {
    const storage = memoryStorage();
    storage.setItem('dam_explorer_selection:7', JSON.stringify([{ id: 10, type: 'asset' }, { id: 99, type: 'asset' }, { id: 2, type: 'directory' }, null, { id: 5, type: 'bogus' }]));
    const { instance } = tabInstance({ storage });
    instance.restoreSelection();
    instance.computeSelectionMode();
    assert.deepEqual(plain(instance.selection.ids), [{ id: 10, type: 'asset' }, { id: 99, type: 'asset' }, { id: 2, type: 'directory' }]);
    assert.equal(instance.selection.mode, 'partial');
    assert.deepEqual(JSON.parse(storage.getItem('dam_explorer_selection:7')), [{ id: 10, type: 'asset' }, { id: 99, type: 'asset' }, { id: 2, type: 'directory' }]);
});

test('restoreSelection ignores malformed storage', () => {
    const storage = memoryStorage();
    storage.setItem('dam_explorer_selection:7', '{not json');
    const { instance } = tabInstance({ storage });
    instance.restoreSelection();
    assert.deepEqual(instance.selection.ids, []);
});

test('restoreSelection never overrides a live selection or a different folder', () => {
    const storage = memoryStorage();
    storage.setItem('dam_explorer_selection:8', JSON.stringify([{ id: 10, type: 'asset' }]));
    const { instance } = tabInstance({ storage });
    instance.restoreSelection();
    assert.deepEqual(instance.selection.ids, []);

    storage.setItem('dam_explorer_selection:7', JSON.stringify([{ id: 11, type: 'asset' }]));
    const live = tabInstance({ storage, selectedIds: [{ id: 10, type: 'asset' }] });
    live.instance.restoreSelection();
    assert.deepEqual(plain(live.instance.selection.ids), [{ id: 10, type: 'asset' }]);
});

test('restoreSelection defers to a live selection held by another explorer tab', () => {
    const storage = memoryStorage();
    storage.setItem('dam_explorer_selection:7', JSON.stringify([{ id: 10, type: 'asset' }]));
    const { instance } = tabInstance({ storage });
    instance.foreignSelections[7] = { tabId: 'tab-2', ids: [{ id: 10, type: 'asset' }] };
    instance.restoreSelection();
    assert.deepEqual(instance.selection.ids, []);
});

test('a tab that never held the selection cannot delete another tab\'s stored recovery state', () => {
    const storage = memoryStorage();
    storage.setItem('dam_explorer_selection:7', JSON.stringify([{ id: 10, type: 'asset' }]));
    const { instance } = tabInstance({ storage });
    instance.foreignSelections[7] = { tabId: 'tab-2', ids: [{ id: 10, type: 'asset' }] };
    instance.restoreSelection();
    instance.clearSelection();
    instance.computeSelectionMode();
    assert.deepEqual(JSON.parse(storage.getItem('dam_explorer_selection:7')), [{ id: 10, type: 'asset' }]);
});

test('All Assets scope persists under its own key and clears it on deselect', () => {
    const { instance, storage } = tabInstance({ currentDirId: null });
    instance.toggleSelect(10, 'asset');
    assert.deepEqual(storage.keys(), ['dam_explorer_selection:all']);
    instance.clearSelection();
    assert.deepEqual(storage.keys(), []);
});

function historyInstance(storage) {
    const { instance } = tabInstance({ storage, currentDirId: 7 });
    Object.assign(instance, {
        navHistory: [{ dirId: 3, breadcrumb: [] }, { dirId: 7, breadcrumb: [] }],
        navIdx: 1,
        canGoBack: true,
        canGoForward: false,
        breadcrumb: [],
        search: '', searchInput: '', page: 1,
        $emit() {},
        fetch() { this.fetched = true; },
    });
    return instance;
}

test('history navigation drops the source folder selection instead of carrying it into the destination', () => {
    const storage = memoryStorage();
    storage.setItem('dam_explorer_selection:3', JSON.stringify([{ id: 42, type: 'asset' }]));
    const instance = historyInstance(storage);
    instance.toggleSelect(10, 'asset');
    assert.deepEqual(storage.keys().sort(), ['dam_explorer_selection:3', 'dam_explorer_selection:7']);

    instance.goBack();
    assert.equal(instance.currentDirId, 3);
    assert.deepEqual(plain(instance.selection.ids), []);
    assert.deepEqual(plain(storage.keys()), ['dam_explorer_selection:3']);
    assert.equal(instance.fetched, true);

    // The destination's own stored selection is what fetch() would restore.
    instance.restoreSelection();
    assert.deepEqual(plain(instance.selection.ids), [{ id: 42, type: 'asset' }]);
});

test('the second click of a native double-click is ignored on rows and checkbox cells', () => {
    const { instance, emitted } = listInstance({ selectedIds: [{ id: 10, type: 'asset' }] });
    let opened = 0;
    instance.onRowClick(plainClick(), instance.assets[0], 'asset', () => opened++);   // deselects
    instance.selection.ids = [];                                                       // parent applied the toggle
    instance.onRowClick({ ...plainClick(), detail: 2 }, instance.assets[0], 'asset', () => opened++);
    instance.onCheckboxClick({ ...plainClick(), detail: 2 }, instance.assets[1], 'asset');
    assert.equal(opened, 0);
    assert.deepEqual(emitted, [{ name: 'toggle-select', args: [10, 'asset'] }]);
});

test('toggleSelectAll selects every visible row and clears on the second toggle', () => {
    const { instance, storage } = tabInstance();
    instance.toggleSelectAll();
    assert.deepEqual(plain(instance.selection.ids), [
        { id: 1, type: 'directory' }, { id: 2, type: 'directory' },
        { id: 10, type: 'asset' }, { id: 11, type: 'asset' }, { id: 12, type: 'asset' },
    ]);
    assert.equal(instance.selection.mode, 'all');
    assert.deepEqual(storage.keys(), ['dam_explorer_selection:7']);
    instance.toggleSelectAll();
    assert.deepEqual(plain(instance.selection.ids), []);
    assert.deepEqual(storage.keys(), []);
});

test('adoptForeignSelection copies another tab\'s mixed selection for the current scope', () => {
    const { instance } = tabInstance();
    instance.foreignSelections[7] = { tabId: 'tab-2', ids: [{ id: 2, type: 'directory' }, { id: 11, type: 'asset' }] };
    instance.adoptForeignSelection();
    assert.deepEqual(plain(instance.selection.ids), [{ id: 2, type: 'directory' }, { id: 11, type: 'asset' }]);
    assert.equal(instance.selection.mode, 'partial');
});

test('two All Assets tabs coordinate through the all scope key; the non-owner cannot delete the owner\'s state', () => {
    const storage = memoryStorage();
    const owner = tabInstance({ storage, currentDirId: null });
    owner.instance.toggleSelect(10, 'asset');
    const active = owner.emitted.find(e => e.name === 'dam:selection-active');
    assert.equal(active.payload.directoryId, 'all');
    assert.deepEqual(storage.keys(), ['dam_explorer_selection:all']);

    const other = tabInstance({ storage, currentDirId: null });
    other.instance.foreignSelections[active.payload.directoryId] = { tabId: 'tab-1', ids: active.payload.ids };
    other.instance.restoreSelection();
    assert.deepEqual(plain(other.instance.selection.ids), []);
    assert.equal(tabComponent.computed.foreignSelectionCount.call(other.instance), 1);
    other.instance.clearSelection();
    assert.deepEqual(storage.keys(), ['dam_explorer_selection:all']);
});

test('entering All Assets from a folder queries other tabs for an existing all-scope selection', () => {
    const { instance, emitted } = tabInstance({ currentDirId: 7 });
    instance.scope = 'all';
    instance.currentDirId = null;
    tabComponent.watch.currentDirId.call(instance, null);
    assert.deepEqual(plain(emitted), [{ name: 'dam:selection-query', payload: { directoryId: 'all', requesterId: 'tab-1' } }]);

    const back = tabInstance({ currentDirId: 7 });
    tabComponent.watch.currentDirId.call(back.instance, 7);
    assert.deepEqual(plain(back.emitted), [{ name: 'dam:selection-query', payload: { directoryId: 7, requesterId: 'tab-1' } }]);
});
