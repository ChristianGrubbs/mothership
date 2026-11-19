import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

// The Tools page's first funnel step (tools-page.js): home.feature.exposed once per tool per visit, after half a tile
// has been on screen for a second, with the props the event registry declares, and never for a tile that is not a link.
const source = readFileSync(new URL('../../src/Resources/assets/js/tools-page.js', import.meta.url), 'utf8');
const registry = readFileSync(new URL('../../src/Config/dam_events.php', import.meta.url), 'utf8');

function page({ tiles }) {
    let now = 0;
    const timers = new Map();
    let nextTimer = 1;
    const observers = [];
    const listeners = {};
    const sent = [];
    const tile = ({ id, link = true }) => ({ tagName: link ? 'A' : 'DIV', dataset: { toolTile: id }, isConnected: true });
    const nodes = tiles.map(tile);
    const root = {
        querySelectorAll(selector) {
            assert.equal(selector, 'a[data-tool-tile]');
            return nodes.filter(node => node.tagName === 'A');
        },
    };
    const document = {
        readyState: 'complete',
        hidden: false,
        querySelector: selector => (selector === '[data-tools-page]' ? root : null),
        addEventListener: (type, handler) => { (listeners[type] ??= []).push(handler); },
    };
    class IntersectionObserver {
        constructor(callback) { this.callback = callback; this.observed = []; observers.push(this); }
        observe(node) { this.observed.push(node); }
        disconnect() { this.observed = []; }
    }
    const context = {
        document,
        IntersectionObserver,
        AbortController,
        setTimeout: (run, ms) => { const id = nextTimer++; timers.set(id, { run, at: now + ms }); return id; },
        clearTimeout: id => timers.delete(id),
    };
    context.window = context;
    context.window.damTrack = (...args) => sent.push(args);
    vm.runInNewContext(source, context);
    const tick = ms => {
        now += ms;
        for (const [id, timer] of [...timers].sort((a, b) => a[1].at - b[1].at)) {
            if (timer.at <= now && timers.has(id)) { timers.delete(id); timer.run(); }
        }
    };
    tick(0); // the start runs on the task after the app's mount
    const see = (id, ratio = 1) => {
        const node = nodes.find(entry => entry.dataset.toolTile === id);
        for (const observer of observers) {
            if (observer.observed.includes(node)) observer.callback([{ target: node, isIntersecting: ratio > 0, intersectionRatio: ratio }]);
        }
    };
    const setHidden = hidden => { document.hidden = hidden; (listeners.visibilitychange || []).forEach(handler => handler()); };
    return { sent, see, tick, setHidden, observers };
}

test('a tool counts once per visit after half its tile has been on screen for a second', () => {
    const tools = page({ tiles: [{ id: 'looper' }, { id: 'plaque' }] });
    tools.see('looper');
    tools.tick(999);
    assert.equal(tools.sent.length, 0);
    tools.tick(1);
    assert.equal(tools.sent.length, 1);
    const [name, subject, props, surface] = tools.sent[0];
    assert.equal(name, 'home.feature.exposed');
    assert.equal(subject, null);
    assert.equal(surface, 'tools');
    assert.deepEqual({ ...props }, { widget_id: 'looper', cols: 1, rows: 1, visible_ms: 1000 });

    // Scrolled away and back: still one event for that tool on this visit.
    tools.see('looper', 0);
    tools.see('looper');
    tools.tick(5000);
    assert.equal(tools.sent.length, 1);
});

test('a glimpse, a hidden page or a tile still to be set up never counts', () => {
    const tools = page({ tiles: [{ id: 'looper' }, { id: 'vectorization', link: false }] });
    assert.deepEqual(tools.observers[0].observed.map(node => node.dataset.toolTile), ['looper']);

    tools.see('looper', 0.4);
    tools.tick(2000);
    tools.see('looper');
    tools.tick(500);
    tools.see('looper', 0);
    tools.tick(2000);
    assert.equal(tools.sent.length, 0);

    tools.see('looper');
    tools.setHidden(true);
    tools.tick(2000);
    assert.equal(tools.sent.length, 0);
    tools.setHidden(false);
    tools.tick(1000);
    assert.equal(tools.sent.length, 1);
});

test('the props sent are exactly the ones the event registry declares', () => {
    const entry = registry.match(/'home\.feature\.exposed' => \[.*?'props' => \[([^\]]*)\]/s);
    assert.ok(entry, 'dam_events.php declares home.feature.exposed');
    const declared = [...entry[1].matchAll(/'([a-z_]+)' =>/g)].map(match => match[1]).sort();
    const tools = page({ tiles: [{ id: 'looper' }] });
    tools.see('looper');
    tools.tick(1000);
    assert.deepEqual(Object.keys(tools.sent[0][2]).sort(), declared);
});
