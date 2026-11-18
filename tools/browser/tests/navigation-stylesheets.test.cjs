// Regression test for the AJAX-navigation head-stylesheet reconcile (SIGNAL ticker fix).
// Extracts the real reconcileHeadStylesheets() from navigation.js and runs it against a
// fake DOM, proving a destination-only <link> (the ticker stylesheet) is carried across a
// visit and that an already-live stylesheet is never duplicated.
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = path.resolve(__dirname, '../../..');
const source = fs.readFileSync(
    path.join(root, 'packages/Mothership/Admin/src/Resources/assets/js/plugins/navigation.js'),
    'utf8'
);

// Pull the real function body out of the module so the test tracks the shipped source.
const match = source.match(/function reconcileHeadStylesheets\(doc\) \{[\s\S]*?\n {4}\}/);
assert.ok(match, 'reconcileHeadStylesheets must exist in navigation.js');

// Minimal DOM doubles: a <link> is an object with getAttribute + a settable rel/href/media.
function makeLink({ rel = 'stylesheet', href = null, media = null } = {}) {
    const attrs = { rel, href, media };
    return {
        get rel() { return attrs.rel; },
        set rel(value) { attrs.rel = value; },
        get href() { return attrs.href; },
        set href(value) { attrs.href = value; },
        get media() { return attrs.media; },
        set media(value) { attrs.media = value; },
        getAttribute: (name) => attrs[name] ?? null,
        setAttribute: (name, value) => { attrs[name] = value; },
    };
}

function makeDoc(links) {
    const head = { appended: [], appendChild(node) { this.appended.push(node); } };
    return {
        head,
        querySelectorAll(selector) {
            // Only the two selectors the function uses are honoured.
            if (selector.startsWith('head ')) {
                return links.filter((l) => l.getAttribute('rel')?.includes('stylesheet') && l.getAttribute('href'));
            }
            return links.filter((l) => l.getAttribute('rel')?.includes('stylesheet') && l.getAttribute('href'));
        },
        createElement: () => makeLink({ rel: null, href: null }),
    };
}

function run(liveLinks, destLinks) {
    const liveDoc = makeDoc(liveLinks);
    const destDoc = makeDoc(destLinks);
    const context = {
        document: liveDoc,
        window: { location: { href: 'https://assets.example.org/admin/dam' } },
        URL,
    };
    vm.runInNewContext(`${match[0]}\nreconcileHeadStylesheets(__dest);`, { ...context, __dest: destDoc });
    return liveDoc.head.appended;
}

const APP = 'https://assets.example.org/themes/dam/build/assets/app-abc.css';
const TICKER = 'https://assets.example.org/admin/home/tools/rss/ticker.css?v=5';

test('carries a destination-only stylesheet (ticker) onto the live head', () => {
    const appended = run(
        [makeLink({ href: APP })],
        [makeLink({ href: APP }), makeLink({ href: TICKER })]
    );
    assert.equal(appended.length, 1);
    assert.equal(appended[0].href, TICKER);
    assert.equal(appended[0].rel, 'stylesheet');
    assert.equal(appended[0].getAttribute('data-ajax-nav-stylesheet'), '');
});

test('does not duplicate a stylesheet already live', () => {
    const appended = run(
        [makeLink({ href: APP }), makeLink({ href: TICKER })],
        [makeLink({ href: APP }), makeLink({ href: TICKER })]
    );
    assert.equal(appended.length, 0);
});

test('resolves relative destination hrefs against the live document before comparing', () => {
    const appended = run(
        [makeLink({ href: APP })],
        [makeLink({ href: '/themes/dam/build/assets/app-abc.css' })]
    );
    assert.equal(appended.length, 0, 'a relative href that resolves to a live absolute URL is not re-added');
});

// collectPageStyles(): a visit must also carry the <style> tags views push into the layout's <head>
// (@stack('styles')); the preview viewer's phone layout lives there, and without it the viewer had no layout.
const collect = source.match(/function collectPageStyles\(doc\) \{[\s\S]*?\n {4}\}/);
assert.ok(collect, 'collectPageStyles must exist in navigation.js');

const style = (text, injected = false) => ({ textContent: text, injected });
const scope = (styles) => ({
    querySelectorAll: (selector) => selector.includes(':not([data-ajax-nav-style])')
        ? styles.filter((s) => ! s.injected)
        : styles,
});

function collectStyles({ liveHead, destHead, destBody }) {
    const context = { document: { head: scope(liveHead) }, __dest: { head: scope(destHead), body: scope(destBody) } };
    return vm.runInNewContext(`${collect[0]}\ncollectPageStyles(__dest);`, context);
}

test('carries head-pushed styles across a visit, skipping text the live head already holds', () => {
    const custom = style('/* custom css */');
    const viewer = style('[data-dam-preview-tools] { display: flex }');
    const body = style('.page { color: red }');
    const got = collectStyles({
        liveHead: [style('/* custom css */'), style('[data-dam-preview-tools] { display: flex }', true)],
        destHead: [viewer, custom],
        destBody: [body],
    });
    assert.deepEqual(Array.from(got, (s) => s.textContent), [viewer.textContent, body.textContent],
        'a previously injected copy does not count as live, since injected styles are replaced on every visit');
});
