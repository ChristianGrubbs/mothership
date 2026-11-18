'use strict';

// What the Library hands the preview and the details page about where the opened entry sits in the whole result
// (`total`, `offset`): the producers, run for real. The grid and list cards, the stack fan they open, and the fan's own
// "preview" and "Open details" paths each pass the same two numbers on, so the arrows and the "n of N" counter agree
// with the Library from every way in.
const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const explorer = path.resolve(__dirname, '../../..', 'packages/Mothership/DAM/src/Resources/views/components/explorer');
const read = (file) => fs.readFileSync(path.join(explorer, file), 'utf8');

/** One method of a component's script, compiled on its own (arguments and body found by balanced brackets). */
function method(source, name, scope = {}) {
    const start = new RegExp(`\\n {8,16}(?:async )?${name}\\(`).exec(source);
    assert.ok(start, `${name}() not found`);
    const open = start.index + start[0].length - 1;
    const balanced = (from, opener, closer) => {
        let depth = 0;
        for (let i = from; i < source.length; i++) {
            if (source[i] === opener) depth++;
            if (source[i] === closer && --depth === 0) return i;
        }
        throw new Error(`${name}: unbalanced`);
    };
    const argsEnd = balanced(open, '(', ')');
    const bodyStart = source.indexOf('{', argsEnd);
    const bodyEnd = balanced(bodyStart, '{', '}');
    const text = source.slice(start.index + 1, bodyEnd + 1)
        .replace(/@json\(bouncer\(\)->hasPermission\('[^']+'\)\)/g, 'true')
        .replace(/@js\(route\('admin.dam.assets.edit', '__ASSET__'\)\)/g, "'/admin/dam/assets/edit/__ASSET__'")
        .replace(/@json\([^)]*\)/g, 'null').replace(/\{\{[\s\S]*?\}\}/g, ':id');

    return new Function(...Object.keys(scope), `return ({ ${text} })['${name}'];`)(...Object.values(scope));
}

const library = {assets: [{id: 11}, {id: 12}, {id: 13}], currentDirId: 5, resultTotal: 240, resultOffset: 40, searchSmart: null};
const recorder = () => { const events = []; return {events, $emitter: {emit: (name, payload) => events.push([name, payload])}}; };

for (const file of ['grid.blade.php', 'list.blade.php']) {
    test(`${file}: a card's preview and its stack fan carry the result's total and offset`, () => {
        const source = read(file);
        const host = {...library, ...recorder(), trackResultOpened() {}, edit() {}};

        method(source, 'preview').call(host, 12);
        assert.deepEqual(host.events.splice(0), [['dam-open-preview', {id: 12, siblings: [11, 12, 13], directoryId: 5, total: 240, offset: 40}]]);

        method(source, 'openStack').call(host, {asset: {id: 12}, el: null});
        const [name, payload] = host.events.splice(0)[0];
        assert.equal(name, 'dam:open-stack-fan');
        assert.equal(payload.total, 240);
        assert.equal(payload.offset, 40);
        assert.deepEqual(payload.siblings, [11, 12, 13]);
    });

    test(`${file}: opening details writes the same total and offset for the details page's arrows`, () => {
        const written = [];
        const host = {...library, ...recorder(), trackResultOpened() {}, $navigate() {}};
        const window = {MothershipDetailsNavigation: {writeSiblings: (value) => written.push(value), withNavMarker: (url) => url}};

        method(read(file), 'edit', {window}).call(host, 12);
        assert.equal(written.length, 1);
        assert.equal(written[0].total, 240);
        assert.equal(written[0].offset, 40);
    });
}

test('the stack fan keeps what its card gave it and passes it to the preview, from a file, the cover and the Open details route', () => {
    const source = read('stack-fan.blade.php');
    const fan = {
        asset: null, phase: 'closed', generation: 0, cache: {}, anchor: {width: 100}, ...recorder(),
        measureAnchor() {}, load() {}, $nextTick() {}, settleMs: () => 1, collapse() {}, focusCard() {}, cancelScheduled() {},
        librarySiblings() { return method(source, 'librarySiblings').call(this); },
    };
    const scope = {document: {activeElement: null}, window: {innerWidth: 1000, innerHeight: 800}, setTimeout() {}, clearTimeout() {}, requestAnimationFrame() {}};
    let detailsOpened = 0;

    method(source, 'open', scope).call(fan, {asset: {id: 12}, el: null, siblings: [11, 12, 13], directoryId: 5, total: 240, offset: 40, onDetails: () => detailsOpened++});
    assert.equal(fan.resultTotal, 240);
    assert.equal(fan.resultOffset, 40);

    // The fan is open with the cover and one other file; both ways to the preview carry the numbers.
    fan.phase = 'open';
    fan.files = [{id: 1}, {id: 2}];
    fan.visibleItems = [{file: {id: 1}, cover: true}, {file: {id: 2}, cover: false}];
    method(source, 'activate', scope).call(fan, 1);
    method(source, 'openMain').call(fan);
    const previews = fan.$emitter && fan.events.filter(([name]) => name === 'dam-open-preview').map(([, payload]) => payload);
    assert.equal(previews.length, 2);
    assert.equal(previews[0].origin.kind, 'chooser');
    assert.equal(previews[0].origin.fileId, 2);
    for (const payload of previews) {
        assert.equal(payload.total, 240);
        assert.equal(payload.offset, 40);
        assert.deepEqual(payload.siblings, [11, 12, 13]);
    }

    // Open details hands over to the card's own handler, which wrote the same numbers above.
    method(source, 'openDetails', scope).call({...fan, onDetails: fan.onDetails});
    assert.equal(detailsOpened, 1);
});

// "Open main file" pressed while the fan is still opening used to be dropped.
function openingFan() {
    const source = read('stack-fan.blade.php');
    const timers = [];
    const later = [];
    const fan = {
        asset: null, phase: 'closed', generation: 0, cache: {}, anchor: {width: 100}, files: [{id: 1}], expanded: false, focusIndex: 0, count: 2, title: 'Stack', t: {opened: ':name :count'},
        $refs: {root: {offsetWidth: 1}}, $el: {isConnected: true}, ...recorder(), reducedMotion: true, visibleItems: [],
        measureAnchor() {}, load() {}, $nextTick: (callback) => later.push(callback), settleMs: () => 1, collapse() { this.generation++; this.phase = 'closing'; }, focusCard() {},
        stagger: () => 0, librarySiblings() { return method(source, 'librarySiblings').call(this); },
        openMain() { return method(source, 'openMain').call(this); },
        cancelScheduled() { return method(source, 'cancelScheduled', scope).call(this); },
    };
    const cancelled = [];
    // Cancelling is recorded but does nothing, so these tests also prove the generation check on its own.
    const scope = {
        document: {activeElement: null}, window: {innerWidth: 1000, innerHeight: 800, removeEventListener() {}}, clearTimeout: () => cancelled.push('timeout'), cancelAnimationFrame: () => cancelled.push('frame'),
        setTimeout: (callback) => timers.push(callback), requestAnimationFrame: (callback) => callback(),
    };
    fan.$emitter.off = () => {};
    const open = (id) => method(source, 'open', scope).call(fan, {asset: {id}, el: null, siblings: [id], directoryId: 5, total: 1, offset: 0});
    open(12);

    return {
        fan, source, open, cancelled, unmount: () => method(source, 'beforeUnmount', scope).call(fan),
        clickMain: () => fan.openMain(),
        expand: () => later.splice(0).forEach((callback) => callback()),
        settle: () => { later.splice(0).forEach((callback) => callback()); timers.splice(0).forEach((callback) => callback()); },
        previews: () => fan.events.filter(([name]) => name === 'dam-open-preview'),
    };
}

test('an Open main file click while the fan is opening runs when it settles, once', () => {
    const {fan, clickMain, settle, previews} = openingFan();
    assert.equal(fan.phase, 'opening');

    clickMain();
    assert.equal(previews().length, 0, 'it opened before the fan settled');
    settle();
    assert.equal(previews().length, 1, 'the click was dropped');
    assert.equal(previews()[0][1].id, 12);
    settle();
    assert.equal(previews().length, 1, 'it ran twice');
});

test('a queued Open main file click is dropped if the fan is folded away before it settles', () => {
    const {fan, clickMain, settle, previews} = openingFan();
    clickMain();
    fan.collapse();
    settle();
    assert.equal(previews().length, 0, 'a click for a fan that was closed opened a preview');
});

test('a fan that is unmounted before it expands never expands, settles, or opens a queued click', () => {
    const {fan, clickMain, settle, unmount, cancelled, previews} = openingFan();
    clickMain();
    unmount();
    settle();
    assert.equal(previews().length, 0, 'a queued click ran after the fan was gone');
    assert.equal(fan.expanded, false);
    assert.equal(fan.phase, 'opening');
    assert.ok(cancelled.includes('timeout') && cancelled.includes('frame'), 'pending timers and frames were not cancelled');
});

test('a fan that is unmounted after it expanded but before it settles does not run its queued click', () => {
    const {fan, clickMain, expand, settle, unmount, previews} = openingFan();
    clickMain();
    expand();
    assert.equal(fan.expanded, true);
    unmount();
    settle();
    assert.equal(fan.phase, 'opening', 'the settle callback ran for a fan that was gone');
    assert.equal(previews().length, 0, 'a queued click ran after the fan was gone');
});

test('a click queued for one stack is not run when a different stack has been opened meanwhile', () => {
    const {fan, open, clickMain, settle, previews} = openingFan();
    clickMain();
    fan.phase = 'closed';
    open(13);
    settle();
    assert.equal(previews().filter(([, payload]) => payload.id === 12).length, 0, 'the first stack\'s click opened its preview');
    assert.equal(previews().length, 0, 'a click nobody made for the second stack opened a preview');
    assert.equal(fan.asset.id, 13);
});

test('a fan whose element left the page (ajax navigation) does not expand or open anything when its timers fire', () => {
    const {fan, clickMain, settle, previews} = openingFan();
    clickMain();
    fan.$el.isConnected = false;
    settle();
    assert.equal(fan.expanded, false);
    assert.equal(fan.phase, 'opening');
    assert.equal(previews().length, 0);
});
