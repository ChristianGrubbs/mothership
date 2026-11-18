import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

/**
 * Extracts a component definition from a blade file the same way
 * explorer-list-selection.test.mjs does, but also hands back the sandboxed
 * `window` object so a test can stub `window.loadDamModelThumbnails` the same
 * way app.js exposes it to the real bundle.
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
    const window = { addEventListener() {}, removeEventListener() {} };
    vm.runInNewContext(script, {
        app: { component: (_name, definition) => { component = definition; } },
        window,
        document: { getElementById: () => null },
        localStorage: { getItem: () => null, setItem() {}, removeItem() {} },
        sessionStorage: { getItem: () => null, setItem() {}, removeItem() {} },
    });

    return { component, window };
}

// The capture runner is page-level and Library-mode-independent (ADR 0006):
// asset/index.blade.php mounts it whether or not the explorer is enabled.
const { component: runnerComponent, window: tabWindow } = loadComponent(
    '../../src/Resources/views/components/model-thumbnails/runner.blade.php',
    'v-dam-model-thumbnail-runner',
);

const { component: tabComponent } = loadComponent(
    '../../src/Resources/views/components/explorer/tab.blade.php',
    'v-dam-tab',
);

function emitterStub() {
    const handlers = {};

    return {
        handlers,
        on(name, fn) { (handlers[name] ||= []).push(fn); },
        off(name, fn) { handlers[name] = (handlers[name] || []).filter(entry => entry !== fn); },
        emit(name, payload) { (handlers[name] || []).forEach(fn => fn(payload)); },
    };
}

/** Builds a real runner instance and runs its actual `mounted()` hook. */
function mountedTabInstance({ getMissing } = {}) {
    const calls = [];
    const emitter = emitterStub();

    const instance = {
        ...runnerComponent.methods,
        modelThumbnailSummary: '',
        modelThumbnails: { visible: false, running: false, label: '', total: 0, done: 0, skipped: 0, failed: 0 },
        $emitter: emitter,
        $nextTick: fn => fn?.(),
        $axios: {
            get: async (url, options) => {
                if (! url.includes('model_thumbnails.missing')) return { data: {} };

                calls.push({ url, params: options?.params });

                return { data: await getMissing(options?.params) };
            },
        },
    };

    runnerComponent.mounted.call(instance);

    return { instance, calls, emitter };
}

/** A handful of macrotask ticks -- enough for any chain of awaited requests
 * this module's fire-and-forget event handlers kick off to settle. */
async function flush(times = 6) {
    for (let i = 0; i < times; i++) await new Promise(resolve => setImmediate(resolve));
}

const plain = value => JSON.parse(JSON.stringify(value));

function missingModel(id) {
    return { id, extension: 'glb', checksum: id.toString(16).padStart(64, '0'), size: 10, url: `/download/${id}` };
}

test('an upload refresh that arrives while a capture run is active is not dropped', async () => {
    let resolveFirst;
    const firstDeferred = new Promise(resolve => { resolveFirst = resolve; });
    const seen = [];

    const { instance, calls, emitter } = mountedTabInstance({
        getMissing: async params => {
            seen.push(params.ids);
            if (seen.length === 1) { await firstDeferred; return { items: [missingModel(10)], next_cursor: null }; }

            return { items: [missingModel(20)], next_cursor: null };
        },
    });

    tabWindow.loadDamModelThumbnails = async () => ({
        ModelThumbnailQueue: class {
            enqueue() { return this; }
            async run() { return { total: 1, done: 1, skipped: 0, failed: 0, pending: 0, cancelled: false }; }
            cancel() {}
        },
    });

    emitter.emit('dam:uploads-refresh', { assetIds: [10] });
    assert.equal(instance.modelThumbnails.running, true, 'the first batch is already mid-run');

    // The final refresh of the same upload batch arrives here, while the
    // first request is still in flight.
    emitter.emit('dam:uploads-refresh', { assetIds: [20] });

    resolveFirst();
    await flush();

    assert.equal(calls.length, 2, 'the second batch was not silently dropped');
    assert.deepEqual(plain(calls[1].params.ids), [20]);
    assert.equal(instance.modelThumbnails.running, false);
});

test('two refreshes that both arrive during an active run do not double-queue a shared id', async () => {
    let resolveFirst;
    const firstDeferred = new Promise(resolve => { resolveFirst = resolve; });
    const seen = [];

    const { instance, emitter } = mountedTabInstance({
        getMissing: async params => {
            seen.push([...params.ids]);
            if (seen.length === 1) { await firstDeferred; return { items: [], next_cursor: null }; }

            return { items: [], next_cursor: null };
        },
    });

    emitter.emit('dam:uploads-refresh', { assetIds: [10] });
    // Both of these arrive while the request for id 10 is still in flight, and
    // id 12 appears in both -- the drain that follows must ask about it once.
    emitter.emit('dam:uploads-refresh', { assetIds: [11, 12] });
    emitter.emit('dam:uploads-refresh', { assetIds: [12, 13] });

    resolveFirst();
    await flush();

    assert.equal(seen.length, 2);
    assert.deepEqual(plain(seen[0]), [10]);
    assert.deepEqual(plain(seen[1]).slice().sort((a, b) => a - b), [11, 12, 13]);
    assert.equal(instance.modelThumbnails.running, false);
});

test('a 200-id upload batch reaches the missing endpoint in a single request', async () => {
    const ids = Array.from({ length: 200 }, (_, i) => i + 1);
    const { calls, emitter } = mountedTabInstance({ getMissing: async () => ({ items: [], next_cursor: null }) });

    emitter.emit('dam:uploads-refresh', { assetIds: ids });
    await flush();

    assert.equal(calls.length, 1);
    assert.equal(calls[0].params.ids.length, 200);
});

test('a 201-id upload batch is partitioned into requests the endpoint accepts', async () => {
    const ids = Array.from({ length: 201 }, (_, i) => i + 1);
    const { calls, emitter } = mountedTabInstance({ getMissing: async () => ({ items: [], next_cursor: null }) });

    emitter.emit('dam:uploads-refresh', { assetIds: ids });
    await flush();

    assert.equal(calls.length, 2, 'ModelThumbnailController rejects ids arrays over 200 with a 422');
    assert.equal(calls[0].params.ids.length, 200);
    assert.equal(calls[1].params.ids.length, 1);
    assert.deepEqual(plain(calls.flatMap(call => call.params.ids)), ids);
});

test('cancelling a run drops ids queued while it was active instead of auto-starting a new one', async () => {
    let resolveFirst;
    const firstDeferred = new Promise(resolve => { resolveFirst = resolve; });
    let requests = 0;

    const { instance, emitter } = mountedTabInstance({
        getMissing: async () => { requests++; await firstDeferred; return { items: [], next_cursor: null }; },
    });

    emitter.emit('dam:uploads-refresh', { assetIds: [10] });
    emitter.emit('dam:uploads-refresh', { assetIds: [20] });
    instance.cancelModelThumbnails();

    resolveFirst();
    await flush();

    assert.equal(requests, 1, 'the cancelled run should not be followed by an auto-drained one');
    assert.equal(instance.modelThumbnails.running, false);
});

test('the backfill event runs the missing endpoint for the given directory', async () => {
    const { calls, emitter } = mountedTabInstance({ getMissing: async () => ({ items: [], next_cursor: null }) });

    emitter.emit('dam:generate-model-thumbnails', { directoryId: 7 });
    await flush();

    assert.equal(calls.length, 1);
    assert.equal(calls[0].params.directory_id, 7);
    assert.equal(calls[0].params.ids, undefined);
});

test('the explorer tab delegates to the runner instead of running its own queue', async () => {
    assert.equal(tabComponent.methods.runModelThumbnails, undefined, 'a second runner would double every capture');
    assert.equal(tabComponent.methods.queueModelThumbnailIds, undefined);

    const emitter = emitterStub();
    const forwarded = [];
    emitter.on('dam:generate-model-thumbnails', payload => forwarded.push(payload));

    tabComponent.mounted.call({
        ...tabComponent.methods,
        fetch: async () => {},
        loadRoot: async () => {},
        available: { columns: [] },
        navHistory: [],
        navIdx: -1,
        currentDirId: 7,
        scope: 'directory',
        tabId: 'tab-1',
        foreignSelections: {},
        _heldSelectionDir: null,
        localAccessibleIds: [],
        $emitter: emitter,
        $nextTick: fn => fn?.(),
        $axios: { get: async () => ({ data: {} }) },
    });

    emitter.emit('dam:explorer-generate-model-thumbnails:tab-1', { scope: 'current' });
    emitter.emit('dam:explorer-generate-model-thumbnails:tab-1', { scope: 'directory', directoryId: 9 });

    assert.deepEqual(plain(forwarded), [{ directoryId: 7 }, { directoryId: 9 }]);
});
