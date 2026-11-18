import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

/** Loads the Library tab component (same extraction as explorer-list-selection.test.mjs). */
function loadTab() {
    const blade = readFileSync(new URL('../../src/Resources/views/components/explorer/tab.blade.php', import.meta.url), 'utf8');
    let script = [...blade.matchAll(/<script type="module">([\s\S]*?)<\/script>/g)]
        .map(match => match[1])
        .find(source => source.includes("app.component('v-dam-tab'"));
    assert.ok(script, 'v-dam-tab not found');
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
        window: {}, document: {},
        localStorage: { getItem: () => null, setItem() {}, removeItem() {} },
        sessionStorage: { getItem: () => null, setItem() {}, removeItem() {} },
        setTimeout, clearTimeout, AbortController,
    });
    return component;
}

const tab = loadTab();
const settle = () => new Promise(resolve => setTimeout(resolve, 0));

/** A tab whose axios GET stays pending until the test settles it; an aborted signal rejects like axios does. */
function instance({ ignoreAbort = false } = {}) {
    const requests = [];
    const flashes = [];
    const self = {
        ...tab.methods,
        scope: 'all', similarTo: null, similarityPriorView: false, smartSearch: false, search: 'falcon',
        applied: { filters: { columns: [] } },
        loading: false, loadError: null, firstLoadSettled: false, assets: [], dirs: [],
        $emitter: { emit: (...args) => flashes.push(args) },
        $axios: {
            // Real axios: isCancel is `!!(value && value.__CANCEL__)`.
            isCancel: error => !! (error && error.__CANCEL__),
            get: (_url, config) => new Promise((resolve, reject) => {
                const request = { config, resolve, reject };
                if (! ignoreAbort) config.signal.addEventListener('abort', () => reject(Object.assign(new Error('canceled'), { __CANCEL__: true })));
                requests.push(request);
            }),
        },
        syncSortToSearch() {}, resetSimilarity() {}, trackSearchSettled() {}, restoreSelection() {}, computeSelectionMode() {}, sync() {},
    };
    return { self, requests, flashes };
}

test('a new Library list request aborts the superseded one without error or loading flicker', async () => {
    const { self, requests, flashes } = instance();
    self.fetch();
    self.fetch();
    await settle();

    assert.equal(requests.length, 2);
    assert.equal(requests[0].config.signal.aborted, true);
    assert.equal(requests[1].config.signal.aborted, false);
    // The aborted request settled, yet the newer one still owns the loading state and nothing errored.
    assert.equal(self.loading, true);
    assert.equal(self.loadError, null);
    assert.deepEqual(flashes, []);

    requests[1].resolve({ data: { directories: [], assets: [{ id: 1 }], meta: {}, breadcrumb: null } });
    await settle();
    assert.equal(self.loading, false);
    assert.equal(self.loadError, null);
    assert.equal(self.assets.length, 1);
});

const page = { data: { directories: [], assets: [{ id: 7 }], meta: {}, breadcrumb: null } };
const failure = () => Object.assign(new Error('boom'), { isAxiosError: true, response: { status: 500, data: {} } });

test('a cancel of the still-current request is neither an error nor a loading change', async () => {
    const { self, requests, flashes } = instance();
    self.fetch();
    self._fetchAbort.abort();
    await settle();

    assert.equal(requests.length, 1);
    assert.equal(self.loadError, null);
    assert.deepEqual(flashes, []);
    assert.equal(self.loading, true);
    assert.equal(self.firstLoadSettled, false);
});

const olderPage = { data: { directories: [], assets: [{ id: 1 }, { id: 2 }], meta: {}, breadcrumb: null } };

/** Two overlapping fetches on a transport that ignores abort; returns the instance with the newer request's page applied or pending. */
async function supersededPair({ newerDone }) {
    const ctx = instance({ ignoreAbort: true });
    ctx.self.fetch();
    ctx.self.fetch();
    if (newerDone) {
        ctx.requests[1].resolve(page);
        await settle();
    }
    return ctx;
}

for (const newerDone of [false, true]) {
    const when = newerDone ? 'after the newer one completed' : 'while the newer one is still pending';

    test(`a superseded request the transport failed to abort cannot overwrite the newer one when it resolves late, ${when}`, async () => {
        const { self, requests, flashes } = await supersededPair({ newerDone });
        requests[0].resolve(olderPage);
        await settle();

        assert.deepEqual(self.assets, newerDone ? [{ id: 7 }] : []);
        assert.equal(self.loadError, null);
        assert.equal(self.loading, ! newerDone);
        assert.deepEqual(flashes, []);

        if (! newerDone) {
            requests[1].resolve(page);
            await settle();
            assert.deepEqual(self.assets, [{ id: 7 }]);
            assert.equal(self.loading, false);
        }
    });

    test(`a superseded request that fails late cannot clear the newer results or show an error, ${when}`, async () => {
        const { self, requests, flashes } = await supersededPair({ newerDone });
        requests[0].reject(failure());
        await settle();

        assert.deepEqual(self.assets, newerDone ? [{ id: 7 }] : []);
        assert.equal(self.loadError, null);
        assert.equal(self.loading, ! newerDone);
        assert.deepEqual(flashes, []);

        if (! newerDone) {
            requests[1].resolve(page);
            await settle();
            assert.deepEqual(self.assets, [{ id: 7 }]);
            assert.equal(self.loadError, null);
            assert.equal(self.loading, false);
        }
    });
}
