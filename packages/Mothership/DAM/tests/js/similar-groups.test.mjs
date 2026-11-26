import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

// Similar images groups: Stack opens the Create stack review with exactly the members the group shows (the review names the
// group, so a changed group is refused with 409 there), and the page reloads when that review finishes. The Suggested stacks panel names a near_group's members when it dismisses it.

function loadComponent(relativePath, componentName) {
    const blade = readFileSync(new URL(relativePath, import.meta.url), 'utf8');
    const script = [...blade.matchAll(/<script type="module">([\s\S]*?)<\/script>/g)]
        .map(match => match[1])
        .find(source => source.includes(`app.component('${componentName}'`));
    assert.ok(script, `component ${componentName} not found`);
    let component;
    vm.runInNewContext(
        script
            .replace(/@js\(route\('([^']+)'(?:, \[[^\]]*\])?\)\)/g, (_all, name) => JSON.stringify(`/route/${name}`))
            .replace(/@js\(trans\('([^']+)'\)\)/g, (_all, key) => JSON.stringify(key)),
        { app: { component: (_name, definition) => { component = definition; } }, window: { history: { replaceState() {} } }, document: { activeElement: null }, URLSearchParams, AbortController },
    );
    return component;
}

// Values built inside the vm context carry its own prototypes; compare their JSON.
const plain = value => JSON.parse(JSON.stringify(value));
const views = '../../src/Resources/views/';
const page = loadComponent(`${views}similar/index.blade.php`, 'v-dam-similar-images');
const panel = loadComponent(`${views}components/explorer/stack-suggestions.blade.php`, 'v-dam-stack-suggestions');

const asset = (id, name, stack = null) => ({ id, name, stack: stack ? { basis: stack[0], key: stack[1] } : { basis: null, key: null } });
const group = (assets, extra = {}) => ({ group_key: `grp:${assets.map(item => item.id).join('-')}`, members: assets.map(item => item.id), count: assets.length, distance: 2, assets, ...extra });

function mount(groups, { post, get } = {}) {
    const posts = [];
    const emitted = [];
    const instance = {
        ...page.methods,
        ...page.data.call({ initial: { data: groups, meta: { total: groups.length, current_page: 1, per_page: 12, last_page: 1 }, filters: {}, can_stack: true, can_merge: true } }),
        t: { selectPair: 'Select :a and :b', selectGroup: 'Group of :count starting :name', failed: 'failed' },
        $axios: {
            post: async (url, body) => { posts.push({ url, body }); if (post) return post(url, body); return { data: { message: 'ok' } }; },
            get: async (url, options) => { posts.push({ url, get: options }); return get ? get(url, options) : { data: { data: [], meta: { total: 0, current_page: 1, per_page: 12, last_page: 1 } } }; },
        },
        $emitter: { emit: (name, payload) => emitted.push({ name, payload }) },
    };
    return { instance, posts, emitted };
}

test('Stack opens the Create stack review with every member shown, for a group of three and for a pair, and applies nothing itself', () => {
    const cases = [
        group([asset(11, 'a.jpg', ['campaign', 'Fall']), asset(12, 'b.jpg', ['campaign', 'Fall']), asset(13, 'c.jpg')]),
        group([asset(14, 'd.jpg'), asset(15, 'e.jpg', ['product', 'Other'])]),
    ];

    for (const shown of cases) {
        shown.assets.forEach(item => { item.thumbnail_url = `/thumb/${item.id}`; });
        const { instance, posts, emitted } = mount([shown]);
        instance.stack(shown);

        assert.equal(emitted.length, 1);
        assert.equal(emitted[0].name, 'dam:open-merge-dialog');
        const payload = plain(emitted[0].payload);
        assert.equal(payload.context, 'similar-images');
        assert.equal(payload.reviewId, `similar:${shown.group_key}`);
        assert.deepEqual(payload.review, { group_key: shown.group_key, members: shown.members });
        assert.deepEqual(payload.assets, shown.assets.map(item => ({ id: item.id, name: item.name, thumb: `/thumb/${item.id}`, stack_basis: item.stack.basis, stack_key: item.stack.key })));
        assert.equal(posts.length, 0, 'nothing is requested until the review is confirmed');
    }
});

test('the page reloads when a similar-images review finishes, and ignores other contexts', () => {
    const handlers = {};
    let loads = 0;
    const instance = { ...page.methods, meta: { current_page: 2 }, load(pageNumber) { loads += pageNumber; }, $emitter: { on: (name, handler) => { handlers[name] = handler; } } };
    page.mounted.call(instance);

    handlers['dam:merge-done']({ context: 'suggestions' });
    assert.equal(loads, 0);
    handlers['dam:merge-done']({ context: 'similar-images' });
    assert.equal(loads, 2);
});

test('the page reloads when a similar-images review closes without stacking (cancelled or refused 409), and not otherwise', () => {
    const handlers = {};
    let loads = 0;
    const instance = { ...page.methods, meta: { current_page: 3 }, load(pageNumber) { loads += pageNumber; }, $emitter: { on: (name, handler) => { handlers[name] = handler; } } };
    page.mounted.call(instance);

    handlers['dam:merge-dialog-closed']({ reviewId: 'similar:grp:11-12', merged: false });
    assert.equal(loads, 3, 'the refused or dismissed group is reloaded on the current page');
    handlers['dam:merge-dialog-closed']({ reviewId: 'similar:grp:11-12', merged: true });
    handlers['dam:merge-dialog-closed']({ reviewId: 'near_group:grp:abc', merged: false });
    handlers['dam:merge-dialog-closed']({ reviewId: 'key:fall', merged: false });
    assert.equal(loads, 3);
});

test('Not duplicates sends each selected group with its members, and nothing else', async () => {
    const first = group([asset(31, 'a.jpg'), asset(32, 'b.jpg'), asset(33, 'c.jpg')]);
    const second = group([asset(33, 'c.jpg'), asset(34, 'd.jpg')]);
    const { instance, posts } = mount([first, second]);
    await instance.dismiss([first.group_key]);

    const call = posts.find(item => item.url === '/route/admin.dam.similar.dismiss');
    assert.deepEqual(plain(call.body), { groups: [{ key: first.group_key, members: [31, 32, 33] }] });
});

test('labels a pair by both names and a larger group by its size', () => {
    const pair = group([asset(41, 'a.jpg'), asset(42, 'b.jpg')]);
    const three = group([asset(43, 'x.jpg'), asset(44, 'y.jpg'), asset(45, 'z.jpg')]);
    const { instance } = mount([pair, three]);
    assert.equal(instance.selectLabel(pair), 'Select a.jpg and b.jpg');
    assert.equal(instance.selectLabel(three), 'Group of 3 starting x.jpg');
});

test('the Suggested stacks panel names a near_group\'s members when it dismisses it, and reloads on a changed group', async () => {
    const calls = [];
    let loads = 0;
    const entries = [{ id: 51, name: 'a.jpg' }, { id: 52, name: 'b.jpg' }, { id: 53, name: 'c.jpg' }];
    const nearGroup = { kind: 'near_group', key: 'grp:abc', members: entries.slice(0, 2), entries };
    const keyed = { kind: 'key', key: 'fall 26', members: [], entries: [] };
    const instance = {
        ...panel.methods, groups: [nearGroup, keyed], busy: false, load() { loads++; },
        $axios: { post: async (url, body) => { calls.push({ url, body }); } },
        $emitter: { emit() {} },
    };

    await instance.dismiss(nearGroup);
    await instance.dismiss(keyed);
    assert.deepEqual(plain(calls.map(call => call.body)), [
        { kind: 'near_group', group_key: 'grp:abc', members: [51, 52, 53] },
        { kind: 'key', group_key: 'fall 26' },
    ]);

    instance.$axios.post = async () => { const error = new Error('conflict'); error.response = { status: 409, data: { message: 'changed' } }; throw error; };
    instance.groups = [nearGroup];
    await instance.dismiss(nearGroup);
    assert.equal(loads, 1);
    assert.equal(instance.groups.length, 1);
});

// The panel's Create stack review: a near_group confirms only while it is still the reviewed group.

const dialog = loadComponent(`${views}components/merge/dialog.blade.php`, 'v-dam-merge-dialog');

function mountDialog() {
    const calls = [];
    const instance = {
        ...dialog.methods,
        ...dialog.data.call({}),
        $axios: {
            get: async (url, options) => { calls.push({ method: 'get', url, params: options.params }); return { data: { sources: [], blocked: null, metadata: { tags: [], properties: [], fields: [] } } }; },
            post: async (url, body) => { calls.push({ method: 'post', url, body }); return { data: { message: 'ok' } }; },
            put: async () => ({ data: {} }),
        },
        $emitter: { emit() {} },
        $nextTick() {},
        $refs: {},
    };
    for (const [name, getter] of Object.entries(dialog.computed ?? {})) {
        Object.defineProperty(instance, name, { get: () => getter.call(instance) });
    }
    return { instance, calls };
}

test('the panel hands a near_group\'s key and members to the Create stack review, and a pair or other kind none', () => {
    const emitted = [];
    const entries = [{ id: 7, name: 'a.jpg' }, { id: 8, name: 'b.jpg' }, { id: 9, name: 'c.jpg' }];
    const instance = { ...panel.methods, $emitter: { emit: (name, payload) => emitted.push({ name, payload }) } };

    instance.edit({ kind: 'near_group', key: 'grp:abc', members: entries.slice(0, 2), entries });
    instance.edit({ kind: 'near_duplicate', key: '7:8', members: entries.slice(0, 2), entries: entries.slice(0, 2) });

    assert.deepEqual(plain(emitted[0].payload.review), { group_key: 'grp:abc', members: [7, 8, 9] });
    assert.equal(emitted[1].payload.review, undefined);
});

test('the Create stack review sends the reviewed group with the preview and the confirmation, and none for any other review', async () => {
    const { instance, calls } = mountDialog();
    const files = [{ id: 7, name: 'a.jpg' }, { id: 8, name: 'b.jpg' }, { id: 9, name: 'c.jpg' }];

    instance.show(files, 'suggestions', 'near_group:grp:abc', { review: { group_key: 'grp:abc', members: [7, 8, 9] } });
    await new Promise(resolve => setImmediate(resolve));
    assert.deepEqual(plain(calls.find(call => call.method === 'get').params.review_members), [7, 8, 9]);
    assert.equal(calls.find(call => call.method === 'get').params.review_key, 'grp:abc');

    instance.preview = { blocked: null, sources: [] };
    instance.loading = false;
    await instance.confirm();
    assert.deepEqual(plain(calls.find(call => call.method === 'post').body.review), { group_key: 'grp:abc', members: [7, 8, 9] });

    const other = mountDialog();
    other.instance.show(files, 'library', null, {});
    await new Promise(resolve => setImmediate(resolve));
    assert.equal('review_key' in other.calls.find(call => call.method === 'get').params, false);
    other.instance.preview = { blocked: null, sources: [] };
    other.instance.loading = false;
    await other.instance.confirm();
    assert.equal('review' in other.calls.find(call => call.method === 'post').body, false);
});

// A group's files are told apart by the part their names do not share; the shared head and tail may shorten.

test('names split into shared head, what tells the files apart, and shared tail, on the page and in the panel', () => {
    const split = (names, instance = mount([]).instance) => {
        const group = { assets: names.map((name, index) => ({ id: index + 1, name })) };

        return group.assets.map(item => plain(instance.nameParts(group, item)));
    };
    const parts = (head, mid, tail) => ({ head, mid, tail, fixed: tail.length <= 8 });

    assert.deepEqual(split(['Sign-Letters-Morning-Sun-IMG_1406.jpeg', 'Sign-Letters-Morning-Sun-IMG_1408.jpeg', 'Sign-Letters-Morning-Sun-IMG_1410.jpeg']), [
        parts('Sign-Letters-Morning-Sun-IMG_', '1406', '.jpeg'), parts('Sign-Letters-Morning-Sun-IMG_', '1408', '.jpeg'), parts('Sign-Letters-Morning-Sun-IMG_', '1410', '.jpeg'),
    ]);
    // Underscore and dot separated names keep the distinguishing number visible (review followup-final-r1 finding 1).
    assert.deepEqual(split(['Sign_Letters_Morning_Sun_IMG_1406.jpeg', 'Sign_Letters_Morning_Sun_IMG_1408.jpeg', 'Sign_Letters_Morning_Sun_IMG_1410.jpeg']).map(p => [p.head, p.mid, p.tail]), [
        ['Sign_Letters_Morning_Sun_IMG_', '1406', '.jpeg'], ['Sign_Letters_Morning_Sun_IMG_', '1408', '.jpeg'], ['Sign_Letters_Morning_Sun_IMG_', '1410', '.jpeg'],
    ]);
    assert.deepEqual(split(['Lobby.Camera.01.jpg', 'Lobby.Camera.02.jpg']).map(p => [p.head, p.mid, p.tail]), [
        ['Lobby.Camera.', '01', '.jpg'], ['Lobby.Camera.', '02', '.jpg'],
    ]);
    // The number sits mid-name: the shared tail is long, so it may shorten; the number stays whole.
    assert.deepEqual(split(['Lobby-Camera-01-Final-Retouched.jpeg', 'Lobby-Camera-02-Final-Retouched.jpeg']).map(p => [p.head, p.mid, p.tail]), [
        ['Lobby-Camera-', '01', '-Final-Retouched.jpeg'], ['Lobby-Camera-', '02', '-Final-Retouched.jpeg'],
    ]);
    // A pair where one name extends the other: the split backs off to a separator, never inside a word.
    assert.deepEqual(split(['Reception-Desk-Wide.jpg', 'Reception-Desk-Wide-copy.jpg']).map(p => p.mid), ['Wide', 'Wide-copy']);
    // Nothing in common, or a name that is only shared parts: the whole name.
    assert.deepEqual(split(['alpha.jpg', 'beta.png']).map(p => [p.head, p.mid, p.tail]), [['', 'alpha.jpg', ''], ['', 'beta.png', '']]);
    assert.deepEqual(split(['x-.jpg', 'x-1.jpg']).map(p => [p.head, p.mid, p.tail]), [['', 'x-.jpg', ''], ['', 'x-1.jpg', '']]);

    // The panel splits the same way, over the group's entries.
    const entries = [{ id: 1, name: 'Set-01.jpg' }, { id: 2, name: 'Set-02.jpg' }, { id: 3, name: 'Set-03.jpg' }];
    const panelGroup = { kind: 'near_group', key: 'grp:x', members: entries.slice(0, 2), entries };
    assert.deepEqual(plain(panel.methods.nameParts.call({}, panelGroup, entries[1])), { head: 'Set-', mid: '02', tail: '.jpg', fixed: true });
});

test('stack suggestions wait for a request, ignore duplicate clicks and allow retry after failure', async () => {
    let requests = 0;
    let finish;
    const instance = {
        ...panel.data(), ...panel.methods,
        $emitter: { on() {}, off() {} },
        $axios: { get() {
            requests++;
            return new Promise((resolve, reject) => { finish = { resolve, reject }; });
        } },
    };
    panel.mounted.call(instance);
    assert.equal(requests, 0);
    assert.equal(instance.loadState, 'idle');

    const first = instance.load();
    await instance.load();
    assert.equal(requests, 1);
    finish.reject(new Error('server is busy'));
    await first;
    assert.equal(instance.loadState, 'failed');

    const retry = instance.load();
    finish.resolve({ data: { suggestions: [{ key: 'group' }] } });
    await retry;
    assert.equal(instance.loadState, 'ready');
    assert.deepEqual(plain(instance.groups), [{ key: 'group' }]);
});

test('the Suggested stacks panel reloads when a near_group review closes without stacking, and not otherwise', () => {
    const handlers = {};
    let loads = 0;
    const nearGroup = { kind: 'near_group', key: 'grp:abc', members: [], entries: [] };
    const instance = {
        ...panel.methods, groups: [nearGroup, { kind: 'key', key: 'fall' }], load() { loads++; },
        $emitter: { on: (name, handler) => { handlers[name] = handler; }, emit() {} },
    };
    panel.mounted.call(instance);

    handlers['dam:merge-dialog-closed']({ reviewId: 'near_group:grp:abc', merged: false });
    assert.equal(loads, 1, 'only the cancelled or refused review reloads');
    handlers['dam:merge-dialog-closed']({ reviewId: 'near_group:grp:abc', merged: true });
    handlers['dam:merge-dialog-closed']({ reviewId: 'key:fall', merged: false });
    handlers['dam:merge-dialog-closed']({ reviewId: 'near_group:other', merged: false });
    assert.equal(loads, 1);
});
