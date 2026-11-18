const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../../../public/mothership-sw.js'), 'utf8');

function worker(fetch) {
    const handlers = {}, stored = new Map(), deleted = [];
    const caches = {
        open: async () => ({ put: async (url, response) => stored.set(url, response) }),
        match: async url => stored.get(url),
        keys: async () => ['mothership-offline-v0', 'mothership-offline-v1', 'unrelated-app'],
        delete: async key => deleted.push(key),
    };
    const self = { registration: { scope: 'https://mothership.test/' }, location: { origin: 'https://mothership.test' },
        clients: { claim: async () => {} }, addEventListener: (name, callback) => handlers[name] = callback };
    vm.runInNewContext(source, { self, caches, fetch, URL, Response });
    async function dispatch(name, request) {
        let result;
        handlers[name]({ request, waitUntil: promise => result = promise, respondWith: promise => result = promise });
        return result;
    }
    return { dispatch, stored, deleted };
}

test('only caches generic offline help; online authenticated pages and authorization errors stay on network', async () => {
    const calls = [];
    const w = worker(async (request, options) => {
        calls.push({ request, options });
        return typeof request === 'string' ? new Response('generic help', { headers: { 'content-type': 'text/html' } })
            : new Response('private user response', { status: request.url.endsWith('signed-out') ? 401 : 200 });
    });
    await w.dispatch('install');
    assert.equal(calls[0].options.credentials, 'omit');
    for (const suffix of ['user-a', 'user-b', 'signed-out']) {
        const response = await w.dispatch('fetch', { method: 'GET', mode: 'navigate', url: `https://mothership.test/${suffix}` });
        assert.equal(await response.text(), 'private user response');
        assert.equal(calls.at(-1).options.cache, 'no-store');
    }
    assert.deepEqual([...w.stored.keys()], ['https://mothership.test/pwa/offline']);
});

test('offline navigation never recovers cached private content and never intercepts API, posts or another origin', async () => {
    let online = true;
    const w = worker(async () => {
        if (!online) throw new TypeError('offline');
        return new Response('generic help', { headers: { 'content-type': 'text/html' } });
    });
    await w.dispatch('install');
    online = false;
    const response = await w.dispatch('fetch', { method: 'GET', mode: 'navigate', url: 'https://mothership.test/admin/private' });
    assert.equal(await response.text(), 'generic help');
    for (const request of [
        { method: 'POST', mode: 'navigate', url: 'https://mothership.test/logout' },
        { method: 'GET', mode: 'cors', url: 'https://mothership.test/api/assets' },
        { method: 'GET', mode: 'navigate', url: 'https://creative.test/app' },
    ]) assert.equal(await w.dispatch('fetch', request), undefined);
    await w.dispatch('activate');
    assert.deepEqual(w.deleted, ['mothership-offline-v0']);
});

test('installation rejects a redirected or failed fallback instead of caching a login page', async () => {
    for (const response of [{ ok: false }, { ok: true, redirected: true }]) {
        const w = worker(async () => response);
        await assert.rejects(w.dispatch('install'), /Offline help is unavailable/);
        assert.equal(w.stored.size, 0);
    }
});
