const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const source = fs.readFileSync(path.resolve(__dirname, '../../../packages/Mothership/DAM/src/Resources/assets/js/home-frame.js'), 'utf8');

// respond(url) answers a request with a JSON body, or with nothing for a 404.
function frame(widget, { library = null, respond = null } = {}) {
    const listeners = {};
    const documentListeners = {};
    const requests = [];
    const timers = new Map();
    const root = { dataset: {}, style: {} };
    const messages = [];
    const parent = { postMessage(message) { messages.push(message); } };
    // Every listener is kept and called in registration order, as a document does.
    const document = { documentElement: root, visibilityState: 'visible', addEventListener(type, fn) { (documentListeners[type]??=[]).push(fn); } };
    const fire = (type, event = {}) => { for (const fn of documentListeners[type] ?? []) fn(event); };
    const location = { origin: 'https://assets.example', href: 'https://assets.example/admin/home/tools/x/index.html?theme=dark', search: '?theme=dark' };
    const window = {
        __MOTHERSHIP_FRAME__: { widget, state: {}, ...(library ? { library } : {}) }, parent,
        fetch(url, options) {
            requests.push({url, options});
            if (!respond) return Promise.resolve({ok:true});
            const body = respond(url);
            return Promise.resolve(body === undefined ? {ok:false, status:404} : {ok:true, json: async () => body});
        },
        postMessage() {}, addEventListener(type, listener) { listeners[type] = listener; },
    };
    function XMLHttpRequest() {}
    XMLHttpRequest.prototype.open = function () {};
    XMLHttpRequest.prototype.send = function () {};
    vm.runInNewContext(source, {
        window, location, XMLHttpRequest, URLSearchParams, URL, Request, Headers,
        setTimeout(fn) { const id=timers.size+1;timers.set(id,fn);return id; },
        clearTimeout(id) { timers.delete(id); },
        document,
    });
    return { window, root, requests, timers, messages, ready() { fire('DOMContentLoaded'); },
        input(target) { fire('input', { target, isTrusted: true }); },
        // Something a person (isTrusted) or a script does in the page.
        act(type, event = {}) { fire(type, { isTrusted: true, ...event }); },
        theme(theme) {
        listeners.message({ source: parent, origin: location.origin,
            data: { protocol: 'mothership-widget/v1', type: 'theme', payload: { theme } } });
    } };
}

test('theme-aware widgets still follow the Home theme', () => {
    const signal = frame('rss');
    assert.equal(signal.root.style.colorScheme, 'dark');
    signal.theme('light');
    assert.equal(signal.root.style.colorScheme, 'light');
    signal.theme('dark');
    assert.equal(signal.root.style.colorScheme, 'dark');
});

test('typing in an ephemeral search field never marks the tool dirty, other input still does', () => {
    const tool = frame('sphereseam');
    const ephemeral = { closest: selector => (selector === '[data-agh-ephemeral]' ? {} : null) };
    const ordinary = { closest: () => null };
    tool.input(ephemeral);
    assert.deepEqual(tool.messages.filter(m => m.type === 'dirty'), [], 'a search box is not unexported work');
    tool.input(ordinary);
    assert.equal(tool.messages.filter(m => m.type === 'dirty').length, 1);
});

test('a tool reports input and drop as work, never a bare press', () => {
    const dirty = tool => tool.messages.filter(m => m.type === 'dirty').length;
    const other = frame('sphereseam');
    other.ready();
    assert.equal(dirty(other), 0, 'loading is not work');
    for (const type of ['pointerdown', 'keydown', 'wheel', 'click']) other.act(type, { key: 'r' });
    assert.equal(dirty(other), 0, 'no tool reports a bare press');
    other.act('drop');
    assert.equal(dirty(other), 1);
});

test('a tool is offered every folder it may save into, however deep and however many', async () => {
    // The Library's tree answers with its top folders and the first page of each one's children only. A child's own
    // children, and a long folder's later pages, come from the children endpoint.
    const leaves = (from, count) => Array.from({ length: count }, (_, i) => ({ id: `b${from + i}`, name: `b${from + i}`, has_children: false, children: [] }));
    const answers = {
        '/folders': { data: [
            { id: 1, name: 'Brand', has_children: true, children: [{ id: 2, name: 'Logos', has_children: true, children: [] }, ...leaves(0, 1)], children_has_more: true },
            { id: 3, name: 'Empty', has_children: false, children: [] },
        ] },
        '/children/2?offset=0': { data: [{ id: 4, name: 'Marks', has_children: false, children: [] }], has_more: false },
        '/children/1?offset=2': { data: leaves(1, 2), has_more: true },
        '/children/1?offset=4': { data: leaves(3, 1), has_more: false },
    };
    const tool = frame('palette-studio', { library: { folders: '/folders', children: '/children/__ID__', save: '/save', open: '/open/__ID__' }, respond: url => answers[url] });
    const rows = await tool.window.MothershipFrame.folders();
    // Array.from: the list is made in the tool's own realm.
    assert.deepEqual(Array.from(rows, row => `${row.depth}:${row.name}`), ['0:Brand', '1:Logos', '2:Marks', '1:b0', '1:b1', '1:b2', '1:b3', '0:Empty']);
    assert.equal(rows[0].id, '1');
    assert.equal(tool.requests.length, Object.keys(answers).length, 'each page is read once');

    // A page the Library refuses is said in words, never a shorter list.
    const refused = frame('palette-studio', { library: { folders: '/folders', children: '/gone/__ID__', save: '/save', open: '/open/__ID__' }, respond: url => answers[url] });
    await assert.rejects(refused.window.MothershipFrame.folders(), /The Library answered 404/);
});
