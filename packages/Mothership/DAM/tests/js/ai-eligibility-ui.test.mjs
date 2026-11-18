import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

/**
 * The details-page AI card and the Rename-with-AI dialog follow the server's eligibility: poll while a run is queued
 * or going (including on page load), offer Create only when the server says it can work, and set unsupported files
 * aside in a mixed selection. Loads each component from its Blade file, as upload-failure-states.test.mjs does.
 */
function load(file, name) {
    const blade = readFileSync(new URL(`../../src/Resources/views/${file}`, import.meta.url), 'utf8');
    let script = [...blade.matchAll(/<script(?: type="module")?>([\s\S]*?)<\/script>/g)]
        .map(match => match[1])
        .find(source => source.includes(`app.component('${name}'`));

    assert.ok(script, `${name} not found`);

    // @js() prints a JS literal; it does not run outside Blade. A string keeps `.replace()` on a route usable.
    script = script.split('\n').map(line => {
        let out = line;
        for (let start = out.search(/@js(?:on)?\(/); start !== -1; start = out.search(/@js(?:on)?\(/)) {
            let depth = 0;
            let i = out.indexOf('(', start);
            for (; i < out.length; i++) {
                if (out[i] === '(') depth++;
                else if (out[i] === ')') { depth--; if (depth === 0) break; }
            }
            out = out.slice(0, start) + "'x'" + out.slice(i + 1);
        }
        return out;
    }).filter(line => !/^\s*@(if|elseif|else|endif|once|endonce|push|endpush)\b/.test(line)).join('\n');

    // The details page's shared "which stacked file is on show" mixin, run from its own Blade file, as the page loads it.
    const shownSource = readFileSync(new URL('../../src/Resources/views/asset/stack-shown-script.blade.php', import.meta.url), 'utf8')
        .replace(/\{\{--[\s\S]*?--\}\}/g, '').replace(/<\/?script>/g, '');
    const shownContext = { window: {} };
    vm.runInNewContext(shownSource, shownContext);

    const timers = new Map();
    // The page's visibility: tests flip `hidden` and call the registered listeners.
    const listeners = [];
    const doc = { hidden: false, addEventListener: (_name, fn) => listeners.push(fn), removeEventListener() {} };
    let next = 1;
    let component;
    vm.runInNewContext(script, {
        app: { component: (_name, definition) => { component = definition; } },
        damCameraName: /^(IMG|DSC|GOPR)/i,
        window: { _damShownStackFile: shownContext.window._damShownStackFile, addEventListener() {}, removeEventListener() {}, location: { href: '', search: '' }, MothershipLibraryNavigation: new Proxy({}, { get: () => () => 'name:asc' }) },
        document: doc,
        localStorage: { getItem: () => null, setItem() {}, removeItem() {} },
        setTimeout: (fn, ms) => { const id = next++; timers.set(id, { fn, ms }); return id; },
        clearTimeout: id => { timers.delete(id); },
        Date,
        Promise,
        Number,
        Set,
    });

    // Vue merges a component's mixins under its own options; do the same for the three that matter here.
    const mixins = (component.mixins ?? []).filter(Boolean);
    const instance = { ...Object.assign({}, ...mixins.map(mixin => mixin.methods)), ...component.methods, mounted: component.mounted, beforeUnmount: component.beforeUnmount, flash() {}, $emit() {}, $emitter: { emit() {} } };
    Object.assign(instance, ...mixins.map(mixin => mixin.data.call(instance)), component.data.call(instance));
    for (const [key, getter] of Object.entries(Object.assign({}, ...mixins.map(mixin => mixin.computed ?? {}), component.computed ?? {}))) {
        Object.defineProperty(instance, key, { get: () => getter.call(instance), configurable: true });
    }

    // These long-standing asset-level cases exercise rename-only compatibility.
    // Member expansion and attachment identity are covered by dam-ai-rename-members.
    if (name === 'v-dam-ai-rename-modal') {
        let rows = instance.rows;
        Object.defineProperty(instance, 'rows', {
            get: () => rows,
            set: value => { rows = value.map(row => Object.assign(row, { legacy: true, representative: true })); },
        });
    }

    return { vm: instance, timers, doc, show: () => { doc.hidden = false; listeners.forEach(fn => fn()); } };
}

// Arrays built inside the component's vm context have another Array prototype.
const plain = value => JSON.parse(JSON.stringify(value));
const summary = ':renamed renamed; :unchanged unchanged; :titled titled; :failed failed; :skipped skipped.';

const card = (overrides = {}) => ({ ai: null, available: true, can_review: true, eligibility: { state: 'eligible', can_generate: true, can_rename: true, message: null }, ...overrides });
const state = (name, extra = {}) => ({ state: name, can_generate: false, can_rename: false, message: 'm', ...extra });

test('a card opened while the run is queued or running polls, with no Create button', async () => {
    for (const name of ['queued', 'running']) {
        const { vm, timers } = load('asset/ai-card.blade.php', 'v-dam-ai-card');
        vm.assetId = 5;
        vm.$axios = { get: async () => ({ data: card({ ai: { status: 'pending', caption: null }, eligibility: state(name) }) }) };

        await vm.load();

        assert.equal(vm.working, true, `${name} shows the working state on load`);
        assert.equal(timers.size, 1, `${name} schedules a poll`);
        assert.equal(vm.canCreate, false);
    }
});

test('unsupported and service-down files neither poll nor offer Create', async () => {
    for (const name of ['unsupported', 'service_unavailable']) {
        const { vm, timers } = load('asset/ai-card.blade.php', 'v-dam-ai-card');
        vm.assetId = 5;
        vm.$axios = { get: async () => ({ data: card({ ai: null, eligibility: state(name) }) }) };

        await vm.load();

        assert.equal(vm.working, false);
        assert.equal(timers.size, 0, `${name} does not poll`);
        assert.equal(vm.canCreate, false, `${name} offers no Create`);
    }
});

test('Create shows only when the server allows it and the person may tag', async () => {
    const { vm } = load('asset/ai-card.blade.php', 'v-dam-ai-card');
    vm.assetId = 5;
    vm.$axios = { get: async () => ({ data: card() }) };
    await vm.load();
    assert.equal(vm.canCreate, true);

    vm.card = card({ can_review: false });
    assert.equal(vm.canCreate, false);

    vm.card = card({ eligibility: state('failed', { can_generate: true }) });
    assert.equal(vm.canCreate, true, 'a failed run can be tried again');
});

test('a file the AI found no picture in offers one explicit Try again, which asks the server to retry', async () => {
    const { vm } = load('asset/ai-card.blade.php', 'v-dam-ai-card');
    vm.assetId = 5;
    const posts = [];
    vm.$axios = { post: async (url, body) => { posts.push(body); return { data: card({ eligibility: state('queued') }) }; }, get: async () => ({ data: card() }) };

    vm.card = card({ eligibility: state('failed', { reason: 'no_image', can_retry: false }) });
    assert.equal(vm.canCreate, false, 'a second try for the same bytes is not offered');

    vm.card = card({ eligibility: state('failed', { reason: 'no_image', can_retry: true }) });
    assert.equal(vm.canCreate, true);
    vm.describe();
    await new Promise(resolve => setTimeout(resolve, 0));
    assert.deepEqual(plain(posts), [{ retry: true }]);
    vm.stopPolling();

    // An ordinary Create sends nothing extra.
    vm.card = card();
    vm.describe();
    await new Promise(resolve => setTimeout(resolve, 0));
    assert.deepEqual(plain(posts[1]), {});
    vm.stopPolling();
});

test('a card whose service health is not known yet still shows the file\'s own state and offers Create', async () => {
    const { vm } = load('asset/ai-card.blade.php', 'v-dam-ai-card');
    vm.assetId = 5;
    vm.$axios = { get: async () => ({ data: card({ available: null, ai: null }) }) };
    await vm.load();

    // `available: null` is not "down" (no failure text, actions stay) and not "up" (nothing is claimed).
    assert.equal(vm.loaded, true);
    assert.equal(vm.canCreate, true);
    assert.equal(vm.eligibility.message, null);
});

test('a run waiting out a service that is not answering keeps being followed, and says so plainly', async () => {
    const { vm, timers } = load('asset/ai-card.blade.php', 'v-dam-ai-card');
    vm.assetId = 5;
    const waiting = state('service_unavailable', { reason: 'retrying', will_retry: true, message: "The AI service isn't responding. We'll keep trying automatically." });
    vm.$axios = { get: async () => ({ data: card({ ai: { status: 'pending', caption: null }, eligibility: waiting }) }) };
    await vm.load();

    assert.equal(vm.working, true, 'polling started: it ends in a result or Try again');
    assert.equal(vm.waitingOnService, true);
    assert.equal(vm.isActive(card({ eligibility: waiting })), true);
    assert.equal(vm.isActive(card({ eligibility: state('service_unavailable', { reason: 'unreachable', will_retry: false }) })), false, 'a service that is simply down is not a run');
    vm.stopPolling();
});

test('polling ends when the run is no longer queued or running, or a caption arrives', async () => {
    const { vm, timers } = load('asset/ai-card.blade.php', 'v-dam-ai-card');
    vm.assetId = 5;
    const replies = [card({ eligibility: state('running') }), card({ ai: { status: 'done', caption: 'A falcon.' }, eligibility: state('eligible', { can_generate: false }) })];
    vm.$axios = { get: async () => ({ data: replies.shift() }) };
    vm.startPolling();

    for (const reply of [1, 2]) {
        const [id, timer] = [...timers][0];
        timers.delete(id);
        timer.fn();
        await new Promise(resolve => setImmediate(resolve));
    }

    assert.equal(vm.working, false);
    assert.equal(timers.size, 0);
    assert.equal(vm.card.ai.caption, 'A falcon.');
});

test('the dialog sets unsupported files aside, keeps their reason, and never submits them', async () => {
    const { vm } = load('components/explorer/ai-rename-modal.blade.php', 'v-dam-ai-rename-modal');
    vm.rows = [
        { id: 1, current: 'IMG_1.jpg', state: 'queued', checked: true },
        { id: 2, current: 'logo.svg', state: 'queued', checked: true },
        { id: 3, current: 'IMG_2.dng', state: 'queued', checked: true },
    ];
    vm.urls = { eligibility: '/e' };
    vm.labels = { notEligible: 'Not renamed with AI' };
    vm.$axios = {
        post: async () => ({ data: { eligibility: {
            1: { can_rename: true },
            2: { can_rename: false, message: "AI descriptions aren't available for this file type." },
            3: { can_rename: false, message: 'Waiting for the preview to finish; the AI description will start automatically.' },
        } } }),
    };

    await vm.filterEligible(vm.run);

    assert.deepEqual(plain(vm.rows.map(row => [row.id, row.state])), [[1, 'queued'], [2, 'ineligible'], [3, 'ineligible']]);
    assert.equal(vm.rows[1].message, "AI descriptions aren't available for this file type.");
    assert.equal(vm.eligibleCount, 1);
    assert.equal(vm.blockedCount, 2);
    assert.equal(vm.checking, false);

    // Nothing ineligible is ever applicable, even ticked and named.
    vm.rows.forEach(row => Object.assign(row, { checked: true, name: 'x' }));
    vm.rows[0].state = 'ready';
    assert.deepEqual(plain(vm.applicable.map(row => row.id)), [1]);
});

test('a selection with no eligible file leaves nothing to apply', async () => {
    const { vm } = load('components/explorer/ai-rename-modal.blade.php', 'v-dam-ai-rename-modal');
    vm.rows = [{ id: 2, current: 'logo.svg', state: 'queued', checked: true, name: 'x' }];
    vm.urls = { eligibility: '/e' };
    vm.labels = { notEligible: 'Not renamed with AI' };
    vm.$axios = { post: async () => ({ data: { eligibility: {} } }) };

    await vm.filterEligible(vm.run);

    assert.equal(vm.eligibleCount, 0);
    assert.equal(vm.applicable.length, 0);
    assert.equal(vm.rows[0].message, 'Not renamed with AI', 'a file the server did not list is left out too');
});

test('the dialog also honours the server refusing a file it was asked to describe', async () => {
    const { vm } = load('components/explorer/ai-rename-modal.blade.php', 'v-dam-ai-rename-modal');
    const row = { id: 2, current: 'logo.svg', state: 'queued', checked: true };
    vm.rows = [row];
    vm.urls = { suggest: '/s' };
    vm.labels = { notEligible: 'Not renamed with AI' };
    vm.$axios = { post: async () => { throw { response: { status: 422, data: { message: 'No.', eligibility: { state: 'unsupported' } } } }; } };

    await vm.suggest(row, vm.run);

    assert.equal(row.state, 'ineligible');
    assert.equal(row.message, 'No.');
});

const massActions = [{ title: 'Process', options: { actionType: 'process' } }, { title: 'Rename with AI', options: { actionType: 'ai-rename' } }];
const grid = (records, picked) => {
    const { vm } = load('components/datagrid/dam.blade.php', 'v-dam-datagrid');
    vm.available = { massActions, records, meta: { primary_column: 'id' } };
    vm.applied = { massActions: { indices: picked } };

    return vm;
};

test('the legacy grid drops Rename with AI when nothing selected can ever be described', () => {
    const records = [{ id: 1, ai_possible: false }, { id: 2, ai_possible: false }, { id: 3, ai_possible: true }, { id: 4, ai_possible: true }];

    assert.deepEqual(plain(grid(records, [1, 2]).visibleMassActions.map(a => a.title)), ['Process'], 'unsupported only: no AI action');
    assert.deepEqual(plain(grid(records, [1, 3]).visibleMassActions.map(a => a.title)), ['Process', 'Rename with AI'], 'a mixed selection keeps it; the dialog sorts the files');
    assert.deepEqual(plain(grid(records, [3]).visibleMassActions.map(a => a.title)), ['Process', 'Rename with AI']);
    assert.deepEqual(plain(grid(records, [1, 99]).visibleMassActions.map(a => a.title)), ['Process', 'Rename with AI'], 'a file on another page is unknown, so possible');
    assert.deepEqual(plain(grid(records, []).visibleMassActions.map(a => a.title)), ['Process', 'Rename with AI'], 'nothing selected hides nothing');
});

test('a HEIC-style file (hint true) is offered Rename with AI; the Explorer menu follows the same hint', () => {
    const { vm } = load('components/explorer/tab.blade.php', 'v-dam-tab');
    vm.assets = [{ id: 1, ai_possible: false }, { id: 2, ai_possible: true }];

    vm.selection = { ids: [{ type: 'asset', id: 1 }] };
    assert.equal(vm.selectionMayUseAi(), false);
    vm.selection = { ids: [{ type: 'asset', id: 1 }, { type: 'asset', id: 2 }] };
    assert.equal(vm.selectionMayUseAi(), true);
    vm.selection = { ids: [{ type: 'directory', id: 9 }] };
    assert.equal(vm.selectionMayUseAi(), false);
    vm.selection = { ids: [{ type: 'asset', id: 77 }] };
    assert.equal(vm.selectionMayUseAi(), true, 'a file the page does not hold is left to the server');
});

test('with the AI service down, every nameable file fails at once with the plain reason and can be retried', async () => {
    const { vm } = load('components/explorer/ai-rename-modal.blade.php', 'v-dam-ai-rename-modal');
    vm.rows = [
        { id: 1, current: 'IMG_1.jpg', state: 'queued', checked: true },
        { id: 2, current: 'IMG_2.jpg', state: 'queued', checked: true },
        { id: 3, current: 'logo.svg', state: 'queued', checked: true },
    ];
    vm.urls = { eligibility: '/e' };
    vm.labels = { notEligible: "Can't rename with AI", unavailable: 'The AI service is not available right now.' };
    vm.$axios = {
        post: async () => ({ data: { available: false, eligibility: {
            1: { state: 'service_unavailable', can_rename: false, message: "The AI service isn't reachable right now." },
            2: { state: 'eligible', reason: 'described', can_rename: false, message: null },
            3: { state: 'unsupported', can_rename: false, message: "AI descriptions aren't available for this file type." },
        } } }),
    };

    await vm.filterEligible(vm.run);

    // No wait, no "No suggestion": the service files fail now with the reason; the unsupported one is still set aside.
    assert.deepEqual(plain(vm.rows.map(row => [row.id, row.state, row.error || row.message])), [
        [1, 'failed', 'The AI service is not available right now.'],
        [2, 'failed', 'The AI service is not available right now.'],
        [3, 'ineligible', "AI descriptions aren't available for this file type."],
    ]);
    assert.equal(vm.rows.filter(row => row.state === 'queued').length, 0, 'nothing is left to ask the AI');
});

test('a describe that the server reports as waiting on a down service ends at once, with that reason', async () => {
    const { vm, timers } = load('components/explorer/ai-rename-modal.blade.php', 'v-dam-ai-rename-modal');
    const row = { id: 4, state: 'queued' };
    vm.urls = { describe: '/d/__id__', show: '/s/__id__' };
    vm.labels = { unavailable: 'The AI service is not available right now.', describeTimeout: 'Too long.', noDescription: 'No description.' };
    vm.$axios = {
        post: async () => ({ data: {} }),
        get: async () => ({ data: { ai: { status: 'pending', caption: null }, eligibility: { state: 'service_unavailable', will_retry: true, message: 'm' } } }),
    };

    const result = vm.describe(row, vm.run);
    await new Promise(resolve => setImmediate(resolve));
    for (const [id, timer] of [...timers]) { timers.delete(id); timer.fn(); }

    assert.equal(await result, false);
    assert.equal(row.state, 'failed');
    assert.equal(row.error, 'The AI service is not available right now.');
});

test('the summary counts the whole selection and names the files that cannot use AI naming', async () => {
    const { vm } = load('components/explorer/ai-rename-modal.blade.php', 'v-dam-ai-rename-modal');
    const flashes = [];
    vm.$emitter = { emit: (name, payload) => { if (name === 'add-flash') flashes.push(payload); } };
    vm.$axios = { post: async () => ({ data: {} }) };
    vm.urls = { rename: '/r' };
    vm.labels = {
        summary, done: 'Renamed :count files.', doneOne: 'Renamed 1 file.', partial: 'Renamed :done of :total files; the others are marked.',
        blockedOne: "Renamed :done of :total. 1 file can't use AI naming.", blocked: "Renamed :done of :total. :blocked files can't use AI naming.",
    };
    vm.rows = [
        { id: 1, state: 'ready', checked: true, name: 'a', ext: 'jpg' },
        { id: 2, state: 'ineligible', checked: false }, { id: 3, state: 'ineligible', checked: false }, { id: 4, state: 'ineligible', checked: false },
    ];
    vm.setTitle = false;
    vm.close = () => { throw new Error('the reasons stay on screen'); };

    await vm.apply();

    assert.deepEqual(plain(flashes), [{ type: 'warning', message: '1 renamed; 0 unchanged; 0 titled; 0 failed; 3 skipped.' }]);

    // A clean single-file result still reports every aggregate counter.
    flashes.length = 0;
    vm.close = () => {};
    vm.rows = [{ id: 1, state: 'ready', checked: true, name: 'a', ext: 'jpg' }];
    await vm.apply();
    assert.deepEqual(plain(flashes), [{ type: 'success', message: '1 renamed; 0 unchanged; 0 titled; 0 failed; 0 skipped.' }]);
});

test('a rename where one file fails is not reported as clean and does not close the dialog', async () => {
    const { vm } = load('components/explorer/ai-rename-modal.blade.php', 'v-dam-ai-rename-modal');
    const flashes = [];
    let closed = 0;
    vm.$emitter = { emit: (name, payload) => { if (name === 'add-flash') flashes.push(payload); } };
    vm.close = () => { closed += 1; };
    vm.urls = { rename: '/r' };
    vm.setTitle = false;
    vm.labels = { summary, done: 'Renamed :count files.', doneOne: 'Renamed 1 file.', partial: 'Renamed :done of :total files; the others are marked.', renameFailed: "Couldn't rename this file." };
    vm.$axios = { post: async (_url, body) => { if (body.id === 2) throw { response: { data: { message: 'Not allowed.', code: 'forbidden' } } }; return { data: {} }; } };
    vm.rows = [
        { id: 1, state: 'ready', checked: true, name: 'a', ext: 'jpg' },
        { id: 2, state: 'ready', checked: true, name: 'b', ext: 'jpg' },
    ];

    await vm.apply();

    assert.deepEqual(plain(flashes), [{ type: 'warning', message: '1 renamed; 0 unchanged; 0 titled; 1 failed; 0 skipped.' }]);
    assert.equal(closed, 0, 'the failed row stays visible');
    assert.equal(vm.rows[1].state, 'error');
    assert.equal(vm.rows[1].error, 'Not allowed.');
});

/** A rename dialog wired to record what it emits and whether it closed. */
function renameDialog(post) {
    const { vm } = load('components/explorer/ai-rename-modal.blade.php', 'v-dam-ai-rename-modal');
    const emitted = [];
    const calls = { closed: 0 };
    vm.$emitter = { emit: (name, payload) => emitted.push([name, payload]) };
    vm.close = () => { calls.closed += 1; };
    vm.urls = { rename: '/r' };
    vm.setTitle = false;
    vm.context = { from: 'details' };
    vm.labels = {
        summary, done: 'Renamed :count files.', doneOne: 'Renamed 1 file.', partial: 'Renamed :done of :total files; the others are marked.',
        blockedOne: "Renamed :done of :total. 1 file can't use AI naming.", blocked: "Renamed :done of :total. :blocked files can't use AI naming.",
        renameFailed: "Couldn't rename this file.", unchanged: 'Name unchanged',
        titledOne: 'Title updated.', titled: 'Titles updated for :count files.',
    };
    vm.$axios = { post: async (_url, body) => post(body) };

    return { vm, emitted, calls, flashes: () => plain(emitted.filter(([name]) => name === 'add-flash').map(([, payload]) => payload)) };
}

const unchanged200 = { data: { code: 'unchanged', message: 'Name unchanged' } };
const unchanged404 = () => { throw { response: { status: 404, data: { code: 'unchanged' } } }; };

test('a mixed selection counts only the real renames, shows unchanged and set-aside rows, and tells the opener how many', async () => {
    const { vm, emitted, calls, flashes } = renameDialog(body => (body.id === 2 ? unchanged200 : body.id === 3 ? unchanged404() : { data: {} }));
    vm.rows = [
        { id: 1, state: 'ready', checked: true, name: 'a', ext: 'jpg' },
        { id: 2, state: 'ready', checked: true, name: 'b', ext: 'jpg' },
        { id: 3, state: 'ready', checked: true, name: 'c', ext: 'jpg' },
        { id: 4, state: 'ineligible', checked: false, message: 'Not for AI.' },
    ];

    await vm.apply();

    // Both shapes of "unchanged" are neither renamed nor failed, and carry no Retry (they are not in the failed state).
    assert.deepEqual(plain(vm.rows.map(row => [row.id, row.state, !! row.unchanged, row.error ?? ''])), [
        [1, 'renamed', false, ''], [2, 'renamed', true, ''], [3, 'renamed', true, ''], [4, 'ineligible', false, ''],
    ]);
    assert.deepEqual(flashes(), [{ type: 'warning', message: '1 renamed; 2 unchanged; 0 titled; 0 failed; 1 skipped.' }]);
    assert.deepEqual(plain(emitted.find(([name]) => name === 'dam:ai-rename-applied')[1]), { context: { from: 'details' }, done: 1, titled: 0 });
    assert.equal(calls.closed, 0, 'the set-aside row keeps its reason on screen');
});

test('a run where every file already had its name closes quietly and reports no rename', async () => {
    const { vm, emitted, calls, flashes } = renameDialog(body => (body.id === 1 ? unchanged200 : unchanged404()));
    vm.rows = [
        { id: 1, state: 'ready', checked: true, name: 'a', ext: 'jpg' },
        { id: 2, state: 'ready', checked: true, name: 'b', ext: 'jpg' },
    ];

    await vm.apply();

    assert.deepEqual(flashes(), [], 'no toast');
    assert.equal(calls.closed, 1);
    // The opener hears done = 0, so the details page does not reload for nothing.
    assert.deepEqual(plain(emitted.find(([name]) => name === 'dam:ai-rename-applied')[1]), { context: { from: 'details' }, done: 0, titled: 0 });
});

test('one real rename and one unchanged file have distinct summary counts', async () => {
    const { vm, calls, flashes } = renameDialog(body => (body.id === 2 ? unchanged200 : { data: {} }));
    vm.rows = [
        { id: 1, state: 'ready', checked: true, name: 'a', ext: 'jpg' },
        { id: 2, state: 'ready', checked: true, name: 'b', ext: 'jpg' },
    ];

    await vm.apply();

    assert.deepEqual(flashes(), [{ type: 'success', message: '1 renamed; 1 unchanged; 0 titled; 0 failed; 0 skipped.' }]);
    assert.equal(calls.closed, 1);
});

test('applying the ready row while another file failed to be described keeps the dialog open on the failed row and its Retry', async () => {
    const { vm, emitted, calls, flashes } = renameDialog(() => ({ data: {} }));
    vm.rows = [
        { id: 1, state: 'ready', checked: true, name: 'a', ext: 'jpg', current: 'IMG_1.jpg' },
        { id: 2, state: 'failed', checked: true, name: '', ext: 'jpg', current: 'IMG_2.jpg', error: 'No description.' },
    ];

    await vm.apply();

    assert.equal(calls.closed, 0, 'the failed row is still on screen');
    assert.equal(vm.rows[1].state, 'failed');
    assert.deepEqual(flashes(), [{ type: 'warning', message: '1 renamed; 0 unchanged; 0 titled; 1 failed; 0 skipped.' }]);
    assert.deepEqual(plain(emitted.find(([name]) => name === 'dam:ai-rename-applied')[1]), { context: { from: 'details' }, done: 1, titled: 0 });
});

test('a file still being described when the ready ones are applied also keeps the dialog open', async () => {
    const { vm, calls } = renameDialog(() => ({ data: {} }));
    vm.rows = [
        { id: 1, state: 'ready', checked: true, name: 'a', ext: 'jpg', current: 'IMG_1.jpg' },
        { id: 2, state: 'describing', checked: true, name: '', ext: 'jpg', current: 'IMG_2.jpg' },
    ];

    await vm.apply();

    assert.equal(calls.closed, 0);
});

test('a title-only change (same file name) is not counted as a rename but is reported so the opener refreshes', async () => {
    const { vm, emitted, calls, flashes } = renameDialog(() => ({ data: {} }));
    vm.setTitle = true;
    vm.rows = [{ id: 1, state: 'ready', checked: true, name: 'IMG_1', ext: 'jpg', current: 'IMG_1.jpg', title: 'Sunset' }];

    await vm.apply();

    assert.deepEqual(plain(emitted.find(([name]) => name === 'dam:ai-rename-applied')[1]), { context: { from: 'details' }, done: 0, titled: 1 });
    assert.deepEqual(flashes(), [{ type: 'success', message: '0 renamed; 0 unchanged; 1 titled; 0 failed; 0 skipped.' }]);
    assert.equal(calls.closed, 1);
});

test('a real rename beside a title-only change counts one rename and one title', async () => {
    const { vm, emitted, flashes } = renameDialog(() => ({ data: {} }));
    vm.setTitle = true;
    vm.rows = [
        { id: 1, state: 'ready', checked: true, name: 'beach_01', ext: 'jpg', current: 'IMG_1.jpg', title: 'Beach' },
        { id: 2, state: 'ready', checked: true, name: 'IMG_2', ext: 'jpg', current: 'IMG_2.jpg', title: 'Dune' },
    ];

    await vm.apply();

    assert.deepEqual(plain(emitted.find(([name]) => name === 'dam:ai-rename-applied')[1]), { context: { from: 'details' }, done: 1, titled: 1 });
    assert.deepEqual(flashes(), [{ type: 'success', message: '1 renamed; 0 unchanged; 1 titled; 0 failed; 0 skipped.' }]);
});

test('the rename dialog labels it reads exist, with their real text, in every English locale', () => {
    const modal = readFileSync(new URL('../../src/Resources/views/components/explorer/ai-rename-modal.blade.php', import.meta.url), 'utf8');
    const used = [...modal.matchAll(/trans\('dam::app\.admin\.explorer\.ai-rename\.([a-z-]+)'\)/g)].map(match => match[1]);

    assert.ok(used.includes('unchanged') && used.includes('summary'));

    for (const locale of ['en_US', 'en_GB', 'en_AU', 'en_NZ']) {
        const lang = readFileSync(new URL(`../../src/Resources/lang/${locale}/app.php`, import.meta.url), 'utf8');
        const block = lang.slice(lang.indexOf("'ai-rename' => ["));

        for (const key of used) {
            assert.match(block.slice(0, block.indexOf('],')), new RegExp(`'${key}'\\s*=>`), `${locale} lacks ai-rename.${key}`);
        }

        assert.match(block, /'unchanged'\s*=> 'Name unchanged'/, locale);
    }
});

/** One row applied with title on; the server answers with the name it applied (`applied`), as AssetController returns the refreshed asset. */
async function appliedAs(row, applied) {
    const { vm, emitted } = renameDialog(body => ({ data: { data: { file_name: applied(body) } } }));
    vm.setTitle = true;
    vm.rows = [{ id: 1, state: 'ready', checked: true, ext: 'jpg', title: 'T', ...row }];
    await vm.apply();

    const { done, titled } = plain(emitted.find(([name]) => name === 'dam:ai-rename-applied')[1]);

    return { done, titled };
}

/**
 * What AssetRename::renameLocked() makes of a request: trim the submitted name, take its stem (PHP pathinfo FILENAME), and
 * keep the CURRENT file's extension, whatever extension was asked for. Returns the answer function for appliedAs().
 */
const assetRename = current => body => {
    const dot = current.lastIndexOf('.');
    const extension = dot > 0 ? current.slice(dot + 1) : '';
    const submitted = body.file_name.trim();
    const cut = submitted.lastIndexOf('.');
    const stem = cut > 0 ? submitted.slice(0, cut) : submitted;

    return extension === '' ? stem : `${stem}.${extension}`;
};

test('whether a file was renamed is judged on the name the server applied against the name it had at suggestion', async () => {
    const jpg = { serverName: 'IMG_1.jpg', current: 'IMG_1.jpg' };
    const server = assetRename('IMG_1.jpg');

    // The server trims the whole submitted name: leading space is gone, so the name is the one it had.
    assert.deepEqual(await appliedAs({ name: ' IMG_1', ...jpg }, server), { done: 0, titled: 1 });
    // A space before the extension is inside the submitted name ("IMG_1 .jpg"), so it is kept: a different name.
    assert.deepEqual(await appliedAs({ name: 'IMG_1 ', ...jpg }, server), { done: 1, titled: 0 });
    // The extension asked for is ignored; the current .jpg stays, so only the title changed.
    assert.deepEqual(await appliedAs({ name: 'IMG_1', ext: 'JPG', ...jpg }, server), { done: 0, titled: 1 });
    // Extensionless names, both ways.
    assert.deepEqual(await appliedAs({ name: 'README', ext: '', serverName: 'README', current: 'README' }, assetRename('README')), { done: 0, titled: 1 });
    assert.deepEqual(await appliedAs({ name: 'notes', ext: '', serverName: 'README', current: 'README' }, assetRename('README')), { done: 1, titled: 0 });
    // Several dots: only the whole applied name counts.
    const tar = { ext: 'tar.gz', serverName: 'a.b.c.tar.gz', current: 'a.b.c.tar.gz' };
    assert.deepEqual(await appliedAs({ name: 'a.b.c', ...tar }, assetRename('a.b.c.tar.gz')), { done: 0, titled: 1 });
    assert.deepEqual(await appliedAs({ name: 'a.b', ...tar }, assetRename('a.b.c.tar.gz')), { done: 1, titled: 0 });
});

test('a name the server found taken is retried as -2, -3, and the numbered file counts as a rename', async () => {
    const asked = [];
    const server = assetRename('IMG_1.jpg');
    const { vm, emitted } = renameDialog(body => {
        asked.push(body.file_name);

        if (asked.length < 3) throw { response: { status: 404, data: { code: 'name_taken', message: 'Taken.' } } };

        return { data: { data: { file_name: server(body) } } };
    });
    vm.setTitle = true;
    vm.rows = [{ id: 1, state: 'ready', checked: true, name: 'beach', ext: 'jpg', title: 'Beach', current: 'IMG_1.jpg', serverName: 'IMG_1.jpg' }];

    await vm.apply();

    assert.deepEqual(asked, ['beach.jpg', 'beach-2.jpg', 'beach-3.jpg']);
    assert.equal(vm.rows[0].name, 'beach-3');
    assert.deepEqual(plain(emitted.find(([name]) => name === 'dam:ai-rename-applied')[1]), { context: { from: 'details' }, done: 1, titled: 0 });
});

test('a listing that was out of date does not decide it: the suggestion-time name does', async () => {
    // The list still said OLD.jpg; the server had IMG_1.jpg when it suggested, and applies IMG_1.jpg: title only.
    assert.deepEqual(await appliedAs({ name: 'IMG_1', current: 'OLD.jpg', serverName: 'IMG_1.jpg' }, body => body.file_name), { done: 0, titled: 1 });
    // The file was renamed elsewhere to IMG_9.jpg after the list was read; asking for the listed name is a real rename.
    assert.deepEqual(await appliedAs({ name: 'IMG_1', current: 'IMG_1.jpg', serverName: 'IMG_9.jpg' }, body => body.file_name), { done: 1, titled: 0 });
});

test('the suggestion records the name the server had, replacing an older listing name for the comparison', async () => {
    const { vm } = renameDialog(() => ({ data: {} }));
    vm.urls = { suggest: '/s' };
    vm.$axios = { post: async () => ({ data: { file_name: 'IMG_1.jpg', stem: 'beach', extension: 'jpg' } }) };
    vm.number = () => {};
    const row = { id: 1, state: 'queued', known: true, current: 'OLD.jpg', ext: 'jpg' };

    await vm.suggest(row, vm.run);

    assert.equal(row.serverName, 'IMG_1.jpg');
    assert.equal(row.current, 'OLD.jpg', 'the displayed name is left as listed');
});

test('suggesting again replaces the remembered server name, and the next apply is judged on the newer one', async () => {
    const { vm, emitted } = renameDialog(() => ({ data: {} }));
    const names = ['IMG_1.jpg', 'IMG_2.jpg'];
    vm.urls = { suggest: '/s' };
    vm.$axios = {
        post: async url => (url === '/s' ? { data: { file_name: names.shift(), stem: 'beach', extension: 'jpg' } } : { data: { data: { file_name: 'IMG_2.jpg' } } }),
    };
    vm.number = () => {};
    vm.setTitle = true;
    vm.urls = { suggest: '/s', rename: '/r' };
    const row = { id: 1, state: 'queued', known: true, current: 'IMG_1.jpg', ext: 'jpg', checked: true, name: 'IMG_2', title: 'T' };
    vm.rows = [row];

    await vm.suggest(row, vm.run);
    assert.equal(row.serverName, 'IMG_1.jpg');

    // Retry: the file was renamed elsewhere in the meantime, and the new suggestion says so.
    await vm.suggest(row, vm.run);
    assert.equal(row.serverName, 'IMG_2.jpg');

    row.name = 'IMG_2';
    await vm.apply();

    // Applied IMG_2.jpg against the IMG_2.jpg it had at the latest suggestion: title only.
    assert.deepEqual(plain(emitted.find(([name]) => name === 'dam:ai-rename-applied')[1]), { context: { from: 'details' }, done: 0, titled: 1 });
});

test('over another stacked file the AI card offers no Create, Try again or review, and sends nothing', async () => {
    const { vm } = load('asset/ai-card.blade.php', 'v-dam-ai-card');
    vm.assetId = 5;
    const posts = [];
    vm.$axios = { post: async url => { posts.push(url); return { data: card() }; }, get: async () => ({ data: card() }) };
    vm.card = card({ ai: { caption: '', tags: [{ term: 'cat', label: 'Cat' }] } });

    assert.equal(vm.canCreate, true, 'the cover offers Create');
    assert.equal(vm.canReview, true, 'the cover offers review');

    vm.shownFile = { name: 'Second.png' };
    assert.equal(vm.canCreate, false);
    assert.equal(vm.canReview, false);
    vm.describe();
    vm.review('cat', 'accept');
    vm.acceptAll();
    await new Promise(resolve => setTimeout(resolve, 0));
    assert.deepEqual(posts, [], 'a request went out over another stacked file');

    vm.shownFile = null;
    assert.equal(vm.canCreate, true, 'the cover gets Create back');
    vm.describe();
    await new Promise(resolve => setTimeout(resolve, 0));
    assert.equal(posts.length, 1);
});

/** Fires the one pending timer, then lets the request it makes settle. */
async function tick(timers) {
    const [id, timer] = [...timers][0];
    timers.delete(id);
    timer.fn();
    await new Promise(resolve => setImmediate(resolve));

    return timer.ms;
}

test('a file waiting for its preview is followed on its own, without a Create button or a working line, and flips to eligible without a reload', async () => {
    const { vm, timers } = load('asset/ai-card.blade.php', 'v-dam-ai-card');
    vm.assetId = 5;
    const replies = [
        card({ eligibility: state('awaiting_preview') }),
        card({ eligibility: state('awaiting_preview') }),
        card({ eligibility: state('eligible', { can_generate: true }) }),
    ];
    vm.$axios = { get: async () => ({ data: replies.shift() }) };

    await vm.load();

    assert.equal(vm.working, false, 'nothing was asked for, so no "Creating…" line');
    assert.equal(vm.canCreate, false);
    assert.equal(timers.size, 1, 'a quiet follow is scheduled');
    assert.equal(await tick(timers), 10000, 'every 10 s to begin with');
    assert.equal(timers.size, 1, 'still waiting, still followed');
    await tick(timers);

    assert.equal(vm.eligibility.state, 'eligible');
    assert.equal(vm.canCreate, true, 'Create appears with no reload');
    assert.equal(timers.size, 0, 'and the follow ends');
});

test('a preview wait backs off to 30 s after two minutes', async () => {
    const { vm, timers } = load('asset/ai-card.blade.php', 'v-dam-ai-card');
    vm.assetId = 5;
    vm.$axios = { get: async () => ({ data: card({ eligibility: state('awaiting_preview') }) }) };
    await vm.load();
    vm.followStarted = Date.now() - 121000;

    assert.equal(await tick(timers), 10000, 'the timer already set keeps its delay');
    assert.equal(await tick(timers), 30000, 'the next one is 30 s');
    vm.stopPolling();
});

test('a preview wait that turns into a run is followed as a run, then to its caption', async () => {
    const { vm, timers } = load('asset/ai-card.blade.php', 'v-dam-ai-card');
    vm.assetId = 5;
    const replies = [
        card({ eligibility: state('awaiting_preview') }),
        card({ ai: { status: 'pending', caption: null }, eligibility: state('running') }),
        card({ ai: { status: 'done', caption: 'A falcon.' }, eligibility: state('eligible') }),
    ];
    vm.$axios = { get: async () => ({ data: replies.shift() }) };
    await vm.load();

    await tick(timers);
    assert.equal(vm.working, true, 'the auto-describe started: the run is shown as one');
    assert.equal(timers.size, 1);
    assert.equal(await tick(timers), 4000, 'at the run cadence');

    assert.equal(vm.card.ai.caption, 'A falcon.');
    assert.equal(vm.working, false);
    assert.equal(timers.size, 0);
});

test('a preview wait ends when the file turns out unsupported, or a caption arrives, and on reset', async () => {
    for (const end of [card({ eligibility: state('unsupported') }), card({ ai: { status: 'done', caption: 'x' }, eligibility: state('awaiting_preview') }), card({ eligibility: state('failed') })]) {
        const { vm, timers } = load('asset/ai-card.blade.php', 'v-dam-ai-card');
        vm.assetId = 5;
        const replies = [card({ eligibility: state('awaiting_preview') }), end];
        vm.$axios = { get: async () => ({ data: replies.shift() }) };
        await vm.load();
        await tick(timers);

        assert.equal(timers.size, 0, `ends on ${end.eligibility.state}`);
        assert.equal(vm.working, false);
    }

    // The card leaving (or the page swapping assets) cancels the follow, and a reply already on its way is dropped.
    const { vm, timers } = load('asset/ai-card.blade.php', 'v-dam-ai-card');
    vm.assetId = 5;
    let release;
    const replies = [card({ eligibility: state('awaiting_preview') })];
    vm.$axios = { get: () => (replies.length ? Promise.resolve({ data: replies.shift() }) : new Promise(resolve => { release = () => resolve({ data: card({ eligibility: state('eligible') }) }); })) };
    await vm.load();
    const [id, timer] = [...timers][0];
    timers.delete(id);
    timer.fn();
    vm.reset();
    release();
    await new Promise(resolve => setImmediate(resolve));

    assert.equal(timers.size, 0);
    assert.equal(vm.eligibility.state, 'awaiting_preview', 'the late reply changed nothing');
});

const awaiting = () => card({ eligibility: state('awaiting_preview') });

test('a preview wait ends for good on 404, 401 and 403, with a plain notice and no further timer', async () => {
    for (const [status, text] of [[404, 'This file is no longer available.'], [403, 'You no longer have access to this file.'], [401, 'You no longer have access to this file.']]) {
        const { vm, timers } = load('asset/ai-card.blade.php', 'v-dam-ai-card');
        vm.assetId = 5;
        vm.labels = { gone: 'This file is no longer available.', noAccess: 'You no longer have access to this file.' };
        const replies = [() => ({ data: awaiting() }), () => { throw { response: { status } }; }];
        vm.$axios = { get: async () => replies.shift()() };
        await vm.load();
        await tick(timers);

        assert.equal(timers.size, 0, `${status} leaves no timer`);
        assert.equal(vm.following, false);
        assert.equal(vm.notice, text, `${status} says why`);
    }
});

test('a run poll also ends on 404, rather than polling a deleted file for ever', async () => {
    const { vm, timers } = load('asset/ai-card.blade.php', 'v-dam-ai-card');
    vm.assetId = 5;
    vm.labels = { gone: 'This file is no longer available.', noAccess: 'x' };
    const replies = [() => ({ data: card({ ai: { status: 'pending', caption: null }, eligibility: state('running') }) }), () => { throw { response: { status: 404 } }; }];
    vm.$axios = { get: async () => replies.shift()() };
    await vm.load();
    await tick(timers);

    assert.equal(timers.size, 0);
    assert.equal(vm.working, false);
    assert.equal(vm.notice, 'This file is no longer available.');
});

test('a transient 503 or a dropped connection keeps exactly one timer and recovers', async () => {
    for (const failure of [{ response: { status: 503 } }, new Error('Network Error')]) {
        const { vm, timers } = load('asset/ai-card.blade.php', 'v-dam-ai-card');
        vm.assetId = 5;
        const replies = [() => ({ data: awaiting() }), () => { throw failure; }, () => ({ data: card({ eligibility: state('eligible', { can_generate: true }) }) })];
        vm.$axios = { get: async () => replies.shift()() };
        await vm.load();
        await tick(timers);

        assert.equal(timers.size, 1, 'one timer after the failure');
        assert.equal(vm.notice, '');
        await tick(timers);

        assert.equal(vm.canCreate, true, 'recovered');
        assert.equal(timers.size, 0);
    }
});

test('a hidden tab sends no requests, and the wait resumes the moment the tab is shown', async () => {
    const { vm, timers, doc, show } = load('asset/ai-card.blade.php', 'v-dam-ai-card');
    vm.assetId = 5;
    let requests = 0;
    const replies = [awaiting(), awaiting(), card({ eligibility: state('eligible', { can_generate: true }) })];
    vm.$axios = { get: async () => { requests += 1; return { data: replies.shift() }; } };
    // The component registers its own visibility handler when mounted (which also loads the card).
    vm.mounted();
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(requests, 1);

    // Hidden while the timer is pending: when it fires, it asks nothing and sets no new timer.
    doc.hidden = true;
    await tick(timers);
    assert.equal(requests, 1, 'no request while hidden');
    assert.equal(timers.size, 0, 'and nothing scheduled');
    assert.equal(vm.following, true, 'still waiting, paused');

    // Shown: asks at once, and keeps going.
    show();
    assert.equal(timers.size, 1);
    assert.equal(await tick(timers), 0);
    assert.equal(requests, 2);
    assert.equal(timers.size, 1);

    // Hidden after a reply: the next round is not scheduled at all.
    doc.hidden = true;
    await tick(timers);
    assert.equal(requests, 2, 'a timer that fires hidden asks nothing');
    show();
    await tick(timers);

    assert.equal(vm.canCreate, true);
    assert.equal(timers.size, 0);
});
