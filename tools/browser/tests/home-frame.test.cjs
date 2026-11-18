const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const source = fs.readFileSync(path.resolve(__dirname, '../../../packages/Mothership/DAM/src/Resources/assets/js/home-frame.js'), 'utf8');

function frame(widget) {
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
    const location = { origin: 'https://assets.example', search: '?theme=dark' };
    const window = {
        __MOTHERSHIP_FRAME__: { widget, state: {} }, parent,
        fetch(url, options) { requests.push({url, options}); return Promise.resolve({ok:true}); },
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
    return { root, requests, timers, messages, ready() { fire('DOMContentLoaded'); },
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
