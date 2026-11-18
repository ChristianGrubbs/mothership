const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const root = path.resolve(__dirname, '../../..');

function definition(crypto = {randomUUID: () => 'request'}, globals = {}) {
    const source = fs.readFileSync(path.join(root, 'packages/Mothership/DAM/src/Resources/views/asset/preview-modal/optimization.blade.php'), 'utf8');
    let result;
    const script = source.split('<script type="module">')[1].split('</script>')[0];
    vm.runInNewContext(script, {app: {component: (name, value) => {result=value;}}, setTimeout, clearTimeout, crypto, ...globals});
    return result;
}

function instantiate(result) {
    const instance = {...result.data(), assetId: 7, extension: 'png', stateUrl: '/state/7', storeUrl: '/store/7', filesUrl: '/asset/7?files', $emitter:{emit(){}}, $axios:{}};
    Object.entries(result.methods).forEach(([key,value]) => {instance[key]=value.bind(instance);});
    Object.entries(result.computed).forEach(([key,value]) => Object.defineProperty(instance,key,{get:()=>value.call(instance)}));
    ['mounted', 'beforeUnmount'].forEach((hook) => { if (result[hook]) instance[hook] = result[hook].bind(instance); });
    return instance;
}

function component(crypto, globals = {}) {
    return instantiate(definition(crypto, globals));
}

test('a queued or processing job found on reopen resumes polling immediately', async () => {
    const c = component();
    let polls = 0;
    c.schedulePoll = () => polls++;
    c.$axios.get = async () => ({data: {ready: true, result: {status: 'processing', outcome: null}}});

    await c.refresh();

    assert.equal(c.result.status, 'processing');
    assert.equal(c.busy, true, 'a resumed processing job must render the busy overlay');
    assert.equal(polls, 1, 'a still-busy result must keep polling, not stop silently');
});

test('a queued job found on reopen also resumes polling', async () => {
    const c = component();
    let polls = 0;
    c.schedulePoll = () => polls++;
    c.$axios.get = async () => ({data: {ready: true, result: {status: 'queued', outcome: null}}});

    await c.refresh();

    assert.equal(c.busy, true);
    assert.equal(polls, 1);
});

test('transient status failure keeps polling but a permission denial stops it', async () => {
    const c = component();
    let polls = 0;
    c.schedulePoll = () => polls++;
    c.$axios.get = async () => { throw {response: {status: 503}}; };

    await c.refresh();
    assert.equal(polls, 1, 'a transient (non-auth) failure must retry');
    // FAULT-19: a failed status check says so, rather than reading as a failed optimization.
    assert.equal(c.error, 'Couldn’t check the optimization status.');

    c.$axios.get = async () => { throw {response: {status: 403}}; };
    await c.refresh();

    assert.equal(polls, 1, 'a permission denial must not schedule another poll');
    assert.equal(c.result, null);
    assert.equal(c.ready, false);
});

test('a network failure with no response also keeps polling', async () => {
    const c = component();
    let polls = 0;
    c.schedulePoll = () => polls++;
    c.$axios.get = async () => { throw new Error('network down'); };

    await c.refresh();

    assert.equal(polls, 1);
    // FAULT-19: a failed status check says so, rather than reading as a failed optimization.
    assert.equal(c.error, 'Couldn’t check the optimization status.');
});

test('late completion after unmount cannot update state or emit the files-updated event', async () => {
    const c = component();
    let resolve;
    let emitted = 0;
    c.$emitter.emit = () => emitted++;
    c.$axios.get = () => new Promise(r => { resolve = r; });

    const pending = c.refresh();
    c.alive = false;
    resolve({data: {ready: true, result: {status: 'completed', outcome: 'optimized', old_bytes: 100, new_bytes: 50}}});
    await pending;

    assert.equal(c.result, null);
    assert.equal(emitted, 0);
});

test('a failed result surfaces its message and does not poll again', async () => {
    const c = component();
    let polls = 0;
    c.schedulePoll = () => polls++;
    c.$axios.get = async () => ({data: {ready: true, result: {status: 'failed', message: 'Optimization failed. Try again.'}}});

    await c.refresh();

    assert.equal(c.error, 'Optimization failed. Try again.');
    assert.equal(polls, 0, 'a terminal failed status must not still be considered busy');
});

test('an expired result surfaces its message and does not poll again', async () => {
    const c = component();
    let polls = 0;
    c.schedulePoll = () => polls++;
    c.$axios.get = async () => ({data: {ready: true, result: {status: 'expired', message: 'This optimization expired before it could finish.'}}});

    await c.refresh();

    assert.equal(c.error, 'This optimization expired before it could finish.');
    assert.equal(polls, 0);
});

test('the busy overlay shows the stalled-queue message only for a queued result', () => {
    const source = fs.readFileSync(path.join(root, 'packages/Mothership/DAM/src/Resources/views/asset/preview-modal/optimization.blade.php'), 'utf8');
    assert.match(source, /<p v-if="result\?\.status === 'queued' && result\.message"[^>]*data-dam-optimize-stalled>@\{\{ result\.message \}\}<\/p>/);
});

test('a stalled queued row that a worker then claims and finishes clears the notice and keeps polling until terminal', async () => {
    const stalled = 'Still waiting for the optimizer to start — this usually means it isn\'t running.';
    const c = component();
    let polls = 0;
    c.schedulePoll = () => polls++;
    const answer = (result) => { c.$axios.get = async () => ({data: {ready: true, result}}); };

    answer({status: 'queued', outcome: null, message: stalled});
    await c.refresh();
    assert.equal(c.result.message, stalled, 'the overlay reads this message');
    assert.equal(c.busy, true);
    assert.equal(c.error, '', 'a stalled queue is not an error');
    assert.equal(polls, 1);

    answer({status: 'processing', outcome: null, message: null});
    await c.refresh();
    assert.equal(c.result.message, null, 'the notice is gone once a worker has it');
    assert.equal(c.busy, true);
    assert.equal(polls, 2, 'polling continues while processing');

    answer({status: 'completed', outcome: 'optimized', old_bytes: 10, new_bytes: 5, output_extension: 'jpg', message: null});
    await c.refresh();
    assert.equal(c.busy, false);
    assert.equal(c.result.message, null);
    assert.equal(c.error, '');
    assert.equal(polls, 2, 'a terminal status stops polling');
});

function fakeStorage() {
    const store = new Map();
    return {getItem: (k) => store.get(k) ?? null, setItem: (k, v) => store.set(k, String(v))};
}

function viewGlobals({visibility = 'visible', storage = fakeStorage(), extra = {}} = {}) {
    const listeners = {};
    const document = {
        visibilityState: visibility, hidden: visibility !== 'visible',
        addEventListener: (n, f) => { listeners['document:' + n] = f; }, removeEventListener: (n) => { delete listeners['document:' + n]; },
    };
    return {document, localStorage: storage, listeners, addEventListener: (n, f) => { listeners['window:' + n] = f; }, removeEventListener: (n) => { delete listeners['window:' + n]; }, ...extra};
}

const settle = () => new Promise((resolve) => setImmediate(resolve));
const MESSAGE = 'Optimization failed. Your original is unchanged. Try again.';
const ago = (ms) => new Date(Date.now() - ms).toISOString();
const DAY = 86400000;
const failedAnswer = (id, {updated_at = ago(60000), status = 'failed', message = MESSAGE} = {}) => ({data: {ready: true, result: {id, status, message, updated_at}}});
const dismissedIds = (storage) => JSON.parse(storage.getItem('dam-optimize-dismissed') || '[]');
async function visit(storage, answer, extra) {
    const c = component(undefined, viewGlobals({storage, extra}));
    c.$axios.get = async () => answer;
    await c.refresh();
    return c;
}

test('F-08: a failure shows with a Dismiss action, and a dismissed id never shows again in this browser', async () => {
    const storage = fakeStorage();
    const first = await visit(storage, failedAnswer('a'));
    assert.equal(first.error, MESSAGE);
    assert.equal(first.failureId, 'a', 'the Dismiss button is offered for this failure');

    // Not dismissed: it is still there on another visit, because the user has not said they are done with it.
    assert.equal((await visit(storage, failedAnswer('a'))).error, MESSAGE);

    first.dismiss();
    assert.equal(first.error, '');
    assert.equal(first.failureId, null);
    assert.deepEqual(dismissedIds(storage), ['a']);
    first.$axios.get = async () => failedAnswer('a');
    await first.refresh();
    assert.equal(first.error, '', 'polling does not bring it back in the same view');
    assert.equal((await visit(storage, failedAnswer('a'))).error, '', 'nor on a later visit');
});

test('F-08: a new failure (different id) always shows, and an expired result dismisses the same way', async () => {
    const storage = fakeStorage();
    (await visit(storage, failedAnswer('a'))).dismiss();
    const next = await visit(storage, failedAnswer('b'));
    assert.equal(next.error, MESSAGE);
    assert.equal(next.failureId, 'b');

    const expired = await visit(storage, failedAnswer('e', {status: 'expired', message: 'This optimization expired before it could finish. Your original is unchanged.'}));
    assert.equal(expired.error, 'This optimization expired before it could finish. Your original is unchanged.');
    expired.dismiss();
    assert.deepEqual(dismissedIds(storage), ['a', 'e']);
    assert.equal((await visit(storage, failedAnswer('e', {status: 'expired', message: 'x'}))).error, '');
});

test('F-08: only the server decides a failure is stale; the browser clock, even 8 days off, changes nothing', async () => {
    const skews = {'on time': 0, '8 days ahead': 8 * DAY, '8 days behind': -8 * DAY};
    for (const [label, skew] of Object.entries(skews)) {
        const clock = {Date: {now: () => Date.now() + skew, parse: Date.parse}};
        const fresh = failedAnswer('fresh'); fresh.data.result.stale = false;
        assert.equal((await visit(fakeStorage(), fresh, clock)).error, MESSAGE, label + ': a fresh failure shows');

        const old = failedAnswer('old'); old.data.result.stale = true;
        assert.equal((await visit(fakeStorage(), old, clock)).error, '', label + ': a stale failure stays hidden');

        // Whatever the timestamp says, only `stale` counts: an ancient updated_at without the flag still shows.
        const ancient = failedAnswer('ancient', {updated_at: ago(30 * DAY)});
        assert.equal((await visit(fakeStorage(), ancient, clock)).error, MESSAGE, label + ': no flag, no hiding');
        const recentButStale = failedAnswer('rs', {updated_at: ago(1000)}); recentButStale.data.result.stale = true;
        assert.equal((await visit(fakeStorage(), recentButStale, clock)).error, '', label + ': the flag wins over the timestamp');
    }
    const storage = fakeStorage();
    const stale = failedAnswer('s'); stale.data.result.stale = true;
    await visit(storage, stale);
    assert.deepEqual(dismissedIds(storage), [], 'staleness stores nothing');
});

test('F-08: the client never reads the clock to age a failure', () => {
    const source = fs.readFileSync(path.join(root, 'packages/Mothership/DAM/src/Resources/views/asset/preview-modal/optimization.blade.php'), 'utf8');
    const failureText = source.split('failureText(result) {')[1].split('\n        dismiss()')[0].split('focusAfterDismiss')[0];
    assert.doesNotMatch(failureText, /Date\.(now|parse)|new Date/);
    assert.match(failureText, /result\.stale === true/);
});

test('F-08: a start error from a failed POST is transient: never stored, no Dismiss, list untouched', async () => {
    const storage = fakeStorage(); storage.setItem('dam-optimize-dismissed', JSON.stringify(['x']));
    const c = component(undefined, viewGlobals({storage}));
    c.$axios.post = async () => { throw {response: {status: 500}}; };
    c.schedulePoll = () => {};
    await c.optimize();
    assert.equal(c.error, 'Could not start optimization. Try again.');
    assert.equal(c.failureId, null);
    c.dismiss();
    assert.deepEqual(dismissedIds(storage), ['x'], 'dismissing nothing changes nothing');
    assert.equal(storage.getItem('dam-optimize-dismissed'), '["x"]');
});

test('F-08: a failed run started now clears an older failure and shows its own', async () => {
    const storage = fakeStorage();
    const c = await visit(storage, failedAnswer('old'));
    assert.equal(c.failureId, 'old');
    c.$axios.post = async () => ({data: {result: {id: 'run', status: 'queued', outcome: null}}});
    c.schedulePoll = () => {};
    await c.optimize();
    assert.equal(c.error, '');
    assert.equal(c.failureId, null);
    c.$axios.get = async () => failedAnswer('run');
    await c.refresh();
    assert.equal(c.failureId, 'run');
});

test('F-08: whatever is in storage, the real failure shows and dismissing leaves a clean list', async () => {
    const cases = {
        'an object': '{}', 'null': 'null', 'a number': '42', 'a boolean': 'true',
        'invalid JSON': '{{', 'a bare string containing the id': '"xx-a-yy"', 'an array of junk': '[1,null,{},["a"]]',
    };
    for (const [label, stored] of Object.entries(cases)) {
        const storage = fakeStorage(); storage.setItem('dam-optimize-dismissed', stored);
        const c = await visit(storage, failedAnswer('a'));
        assert.equal(c.error, MESSAGE, label);
        c.dismiss();
        assert.deepEqual(dismissedIds(storage), ['a'], label + ' is replaced by a clean array');
    }
    const mixed = fakeStorage(); mixed.setItem('dam-optimize-dismissed', '[1,"a",null]');
    assert.equal((await visit(mixed, failedAnswer('a'))).error, '', 'a valid string id in a mixed array still counts');
});

test('F-08: the list is deduplicated and capped at 50, newest kept', async () => {
    const storage = fakeStorage();
    for (let i = 0; i < 60; i++) (await visit(storage, failedAnswer('id' + i))).dismiss();
    const ids = dismissedIds(storage);
    assert.equal(ids.length, 50);
    assert.equal(ids.at(-1), 'id59');
    assert.equal(ids[0], 'id10');
    const again = await visit(storage, failedAnswer('id59')); assert.equal(again.error, '');
    assert.equal(new Set(dismissedIds(storage)).size, 50);
});

test('F-08: storage that throws never breaks showing or dismissing; the dismissal holds for this view', async () => {
    const broken = {getItem() { throw new Error('denied'); }, setItem() { throw new Error('denied'); }};
    const c = await visit(broken, failedAnswer('a'));
    assert.equal(c.error, MESSAGE);
    c.dismiss();
    assert.equal(c.error, '');
    c.$axios.get = async () => failedAnswer('a');
    await c.refresh();
    assert.equal(c.error, '', 'remembered in memory for the rest of this view');
});

test('F-08: an error formatter that throws cannot stop polling or the start message', async () => {
    const throwing = {requestErrorMessage() { throw new Error('formatter exploded'); }};
    const c = component(undefined, viewGlobals({extra: {mothership: throwing}}));
    const delays = [];
    c.schedulePoll = (delay = 1500) => delays.push(delay);
    c.result = {status: 'processing'};
    c.$axios.get = async () => { throw {response: {status: 503}}; };
    await c.refresh();
    assert.equal(c.error, 'Couldn’t check the optimization status.');
    assert.deepEqual(delays, [1500], 'the next poll is still scheduled');
    assert.equal(c.inflight, false);

    const starter = component(undefined, viewGlobals({extra: {mothership: throwing}}));
    starter.$axios.post = async () => { throw {response: {status: 500}}; };
    await starter.optimize();
    assert.equal(starter.error, 'Could not start optimization. Try again.');
    assert.equal(starter.submitting, false);

    const bare = component(undefined, viewGlobals({extra: {mothership: throwing}}));
    const bareDelays = []; bare.schedulePoll = (delay = 1500) => bareDelays.push(delay);
    bare.$axios.get = async () => { throw undefined; };
    await bare.refresh();
    assert.equal(bareDelays.length, 1, 'even a thrown undefined is handled and rescheduled');
});

test('A-13: a poll has a timeout, and a failed poll while a run is in flight retries at the base rate, not slower', async () => {
    const c = component();
    const delays = [];
    c.schedulePoll = (delay = 1500) => delays.push(delay);
    let seenConfig;
    c.result = {status: 'processing'};
    c.$axios.get = async (url, config) => { seenConfig = config; throw {response: {status: 503}}; };

    await c.refresh(); await c.refresh(); await c.refresh(); await c.refresh();

    assert.ok(seenConfig.timeout > 0 && seenConfig.timeout <= 5000, 'a stalled poll must time out so the chain keeps going');
    assert.deepEqual(delays, [1500, 1500, 1500, 1500], 'while busy: always the base rate');
});

test('A-13: an idle component that cannot reach the server backs off instead of polling at the base rate', async () => {
    const c = component();
    const delays = [];
    c.schedulePoll = (delay = 1500) => delays.push(delay);
    c.$axios.get = async () => { throw {response: {status: 503}}; };

    for (let i = 0; i < 12; i++) await c.refresh();

    assert.deepEqual(delays.slice(0, 3), [1500, 3000, 4500]);
    assert.equal(Math.max(...delays), 15000);
});

test('A-13: returning to a hidden tab while a run is in flight polls at once; idle it does nothing', () => {
    const listeners = {};
    const document = {hidden: true, addEventListener: (n, f) => { listeners['document:' + n] = f; }, removeEventListener: (n) => { delete listeners['document:' + n]; }};
    const win = {addEventListener: (n, f) => { listeners['window:' + n] = f; }, removeEventListener: (n) => { delete listeners['window:' + n]; }};
    const c = component(undefined, {document, addEventListener: win.addEventListener, removeEventListener: win.removeEventListener});
    const delays = [];
    c.schedulePoll = (delay = 1500) => delays.push(delay);
    c.refresh = async () => {};
    c.mounted();
    assert.ok(listeners['document:visibilitychange'] && listeners['window:focus'] && listeners['window:online']);

    c.result = {status: 'processing'};
    listeners['document:visibilitychange']();
    assert.deepEqual(delays, [], 'still hidden: nothing to do');

    document.hidden = false;
    listeners['document:visibilitychange']();
    assert.deepEqual(delays, [0]);

    c.result = {status: 'completed'};
    listeners['window:focus']();
    assert.deepEqual(delays, [0], 'idle (not busy): no extra poll');

    c.beforeUnmount();
    assert.deepEqual(Object.keys(listeners), [], 'listeners are removed on unmount');
});

test('F-20 and F-09: the busy status sits on a backing plate, and an unconfigured optimizer shows a plain reason', () => {
    const source = fs.readFileSync(path.join(root, 'packages/Mothership/DAM/src/Resources/views/asset/preview-modal/optimization.blade.php'), 'utf8');
    const overlay = source.split('data-dam-optimize-progress>')[1].split('</script>')[0];
    assert.match(overlay, /^\s*<div class="[^"]*\bbg-white\/90\b[^"]*" data-dam-optimize-plate>[\s\S]*Queued for optimization…[\s\S]*<\/div>/, 'the status text is inside the plate');
    assert.match(source, /v-if="ready === false && ! error"[^>]*data-dam-optimize-unavailable>Optimization unavailable\. It isn't set up on this server; ask an admin\./);
    const card = fs.readFileSync(path.join(root, 'packages/Mothership/DAM/src/Resources/views/asset/preview-modal/card.blade.php'), 'utf8');
    assert.doesNotMatch(card.split('<v-dam-asset-optimization')[0].split('@if').at(-1), /Optimizer::class\)->ready\(\)/, 'Optimize is no longer hidden when unconfigured');
});

function clockedComponent(start = 10000) {
    const clock = {now: start, timers: [], cleared: 0};
    const globals = {
        Date: {now: () => clock.now},
        setTimeout: (f, ms) => { clock.timers.push({f, ms}); return clock.timers.length; },
        clearTimeout: () => { clock.cleared++; },
        ...viewGlobals(),
    };
    const c = component(undefined, globals);
    return {c, clock, globals};
}

test('A-13: only one refresh is ever in flight, however often it is asked for', async () => {
    const {c} = clockedComponent();
    let gets = 0, answer;
    c.$axios.get = () => { gets++; return new Promise((resolve) => { answer = resolve; }); };
    const first = c.refresh(); c.refresh(); c.refresh();
    assert.equal(gets, 1, 'the extra asks start no request');
    answer({data: {ready: true, result: {id: 'r', status: 'completed', outcome: 'no_reduction'}}});
    await first;
    assert.equal(c.inflight, false);
    c.$axios.get = async () => { gets++; return {data: {ready: true, result: null}}; };
    await c.refresh();
    assert.equal(gets, 2, 'a new refresh is allowed once the first has settled');
});

test('A-13: an answer that was overtaken never overwrites newer state', async () => {
    const {c} = clockedComponent();
    let answerOlder;
    c.$axios.get = () => new Promise((resolve) => { answerOlder = resolve; });
    c.$axios.post = async () => ({data: {result: {id: 'new', status: 'queued', outcome: null}}});
    const older = c.refresh();               // asked before the run existed, still on the wire
    await c.optimize();                      // the run is created; its result is newer than anything in flight
    assert.equal(c.result.id, 'new');

    answerOlder({data: {ready: true, result: {id: 'previous', status: 'completed', outcome: 'optimized'}}});
    await older;

    assert.equal(c.result.id, 'new', 'the older answer is dropped');
    assert.equal(c.result.status, 'queued');
    assert.equal(c.inflight, false);
    assert.ok(c.timer, 'and polling carries on because the run is still in flight');
});

test('A-13: an older "processing" answer cannot overwrite a newer "completed" one', async () => {
    const {c} = clockedComponent();
    let answerOlder;
    c.$axios.get = () => new Promise((resolve) => { answerOlder = resolve; });
    const older = c.refresh();
    c.result = {id: 'run', status: 'completed', outcome: 'optimized'}; c.seq++;   // a newer state landed meanwhile
    answerOlder({data: {ready: true, result: {id: 'run', status: 'processing', outcome: null}}});
    await older;
    assert.equal(c.result.status, 'completed');
    assert.equal(c.busy, false);
});

test('A-13: a storm of wake events is one pending poll, never sooner than 1.5 s after the last request began', async () => {
    const {c, clock, globals} = clockedComponent(10000);
    c.$axios.get = async () => ({data: {ready: true, result: {id: 'r', status: 'processing', outcome: null}}});
    c.mounted();
    await settle();
    assert.equal(c.busy, true);
    const pending = clock.timers.length;
    assert.equal(pending, 1, 'the poll after the first answer');
    assert.equal(clock.timers[0].ms, 1500);

    clock.now = 10100;                       // 100 ms later the tab regains focus, over and over
    for (let i = 0; i < 20; i++) { globals.listeners['window:focus'](); globals.listeners['document:visibilitychange'](); globals.listeners['window:online'](); }
    assert.equal(clock.timers.length, pending, 'wake events add no timers: one poll is already pending, due at the floor');

    c.beforeUnmount();
});

test('A-13: a wake starts a poll at once only when the last request is old enough, else at the 1.5 s floor, and never while one is in flight', () => {
    const {c, clock, globals} = clockedComponent(50000);
    c.result = {id: 'r', status: 'processing', outcome: null};
    c.$axios.get = () => new Promise(() => {});
    c.mounted();                              // starts a request that never answers: inflight
    const startedAt = clock.now;
    assert.equal(c.inflight, true);
    globals.listeners['window:focus']();
    assert.equal(clock.timers.length, 0, 'no second request while one is in flight');
    c.beforeUnmount();

    const fresh = clockedComponent(80000);
    fresh.c.result = {id: 'r', status: 'processing', outcome: null};
    fresh.c.lastStart = 79900;                // a request began 100 ms ago
    fresh.c.schedulePoll(0, true);
    assert.equal(fresh.clock.timers.at(-1).ms, 1400, 'floor: 79900 + 1500 - 80000');
    fresh.c.lastStart = 70000;                // long ago (a throttled hidden tab)
    fresh.clock.timers.length = 0; fresh.c.timer = null;
    fresh.c.schedulePoll(0, true);
    assert.equal(fresh.clock.timers.at(-1).ms, 0, 'old enough: poll now');
    assert.ok(startedAt > 0);
});

test('F-08: the session fallback is shared across remounts, normalized and bounded, so blocked storage cannot bring a dismissed failure back', async () => {
    const broken = {getItem() { throw new Error('denied'); }, setItem() { throw new Error('denied'); }};
    const script = definition(undefined, viewGlobals({storage: broken}));     // the page's one script run
    const mount = () => { const c = instantiate(script); c.$axios.get = async () => failedAnswer('a'); return c; };

    const first = mount(); await first.refresh();
    assert.equal(first.error, MESSAGE);
    first.dismiss();
    const remounted = mount(); await remounted.refresh();
    assert.equal(remounted.error, '', 'a remount in the same page session keeps it dismissed');
    const other = mount(); other.$axios.get = async () => failedAnswer('b'); await other.refresh();
    assert.equal(other.error, MESSAGE, 'a different failure still shows');

    // Bounded to 50, oldest dropped; junk ids are ignored.
    for (let i = 0; i < 60; i++) { const c = mount(); c.$axios.get = async () => failedAnswer('n' + i); await c.refresh(); c.dismiss(); }
    const oldest = mount(); oldest.$axios.get = async () => failedAnswer('n0'); await oldest.refresh();
    assert.equal(oldest.error, MESSAGE, 'beyond the bound the oldest is forgotten');
    const newest = mount(); newest.$axios.get = async () => failedAnswer('n59'); await newest.refresh();
    assert.equal(newest.error, '');
    const junk = mount(); junk.failureId = 42; junk.dismiss();
    const junk2 = mount(); junk2.failureId = ''; junk2.dismiss();
    const again = mount(); again.$axios.get = async () => failedAnswer('n59'); await again.refresh();
    assert.equal(again.error, '', 'junk ids did not disturb the set');
});

test('F-08: dismissing moves focus to the Optimize trigger, else the nearest enabled toolbar control', async () => {
    const focused = [];
    const el = (name, extra = {}) => ({name, disabled: false, isConnected: true, tabIndex: 0, focus() { focused.push(name); }, ...extra});
    const dismissWith = async (trigger, siblings) => {
        focused.length = 0;
        const c = await visit(fakeStorage(), failedAnswer('f' + Math.random()));
        const own = el('inside-component');
        c.$refs = {trigger};
        c.$el = {contains: (node) => node === own, parentElement: {querySelectorAll: () => [own, ...siblings]}};
        c.dismiss();
        await settle();
        return focused.slice();
    };
    assert.deepEqual(await dismissWith(el('trigger'), [el('sibling')]), ['trigger']);
    assert.deepEqual(await dismissWith(el('trigger', {disabled: true}), [el('disabled-sibling', {disabled: true}), el('sibling')]), ['sibling'], 'disabled trigger: first enabled control that is not part of this component');
    assert.deepEqual(await dismissWith(undefined, [el('sibling')]), ['sibling'], 'trigger gone');
    assert.deepEqual(await dismissWith(el('trigger', {isConnected: false}), [el('sibling')]), ['sibling']);
    assert.deepEqual(await dismissWith(undefined, []), [], 'nothing to focus is not an error');
});
