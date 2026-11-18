import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

// The Add to collection dialog preselects the collection used last by THIS person, else one they own, else
// "new" -- never someone else's collection that a full admin merely happens to be allowed to manage.

function loadComponent(window, localStorage) {
    const blade = readFileSync(new URL('../../src/Resources/views/components/collection/add-modal.blade.php', import.meta.url), 'utf8');
    let script = [...blade.matchAll(/<script type="module">([\s\S]*?)<\/script>/g)]
        .map(match => match[1])
        .find(source => source.includes("app.component('v-dam-collection-add-modal'"));
    assert.ok(script, 'add-modal component not found');
    script = script
        .replace(/@json\(route\('admin\.dam\.collections\.list'\)\)/g, "'/list'")
        .replace(/@json\(route\('admin\.dam\.collections\.store'\)\)/g, "'/store'")
        .replace(/@json\(route\('admin\.dam\.collections\.members\.store', \['id' => '__ID__'\]\)\)/g, "'/members/__ID__'")
        .replace(/@js\(trans\('[^']*'\)\)/g, "'translated'")
        .replace(/@js\(trans_choice\([^\n]*?\]\)\)/g, "'translated'");
    // Only the lines that need Blade are touched above; anything left must be plain JavaScript.
    assert.ok(!/@js|@json/.test(script.split('app.component(\'v-dam-add-to-collection-button\'')[0]), 'unreplaced Blade in the modal component');
    let component;
    vm.runInNewContext(script.split("app.component('v-dam-add-to-collection-button'")[0], {
        app: { component: (_name, definition) => { component = definition; } }, window, localStorage,
    });
    return component;
}

function instance({ userId, storage, managed, failPosts = false, throwingStorage = false }) {
    const calls = { posts: [], flashes: [], done: [], focused: 0, toggled: 0 };
    const localStorage = throwingStorage
        ? { getItem() { throw new Error('denied'); }, setItem() { throw new Error('denied'); } }
        : { getItem: key => (storage.has(key) ? storage.get(key) : null), setItem: (key, value) => { storage.set(key, String(value)); } };
    const component = loadComponent({ MothershipLibraryNavigation: { userId } }, localStorage);
    const vmInstance = {
        ...component.data(),
        $refs: { collectionAddModal: { toggle: () => { calls.toggled++; } }, newName: { focus: () => { calls.focused++; } } },
        $nextTick: callback => callback(),
        $emitter: { emit: (name, payload) => { (name === 'add-flash' ? calls.flashes : calls.done).push(payload); } },
        $axios: {
            get: async () => ({ data: { managed } }),
            post: async (url, body) => {
                calls.posts.push({ url, body });
                if (failPosts) throw { response: { data: { message: 'nope' } } };
                return url === '/store' ? { data: { collection: { id: 900 } } } : { data: { message: 'added' } };
            },
        },
    };
    for (const [name, method] of Object.entries(component.methods)) vmInstance[name] = method.bind(vmInstance);

    return { vmInstance, calls };
}

const mine = (id, owner) => ({ id, owner: { id: owner }, can_manage: true });

test('remembers the last-used collection per person, even when two people share one browser storage', async () => {
    const storage = new Map();
    const managed = [mine(1, 10), mine(2, 10), mine(3, 20)];

    const first = instance({ userId: 10, storage, managed });
    first.vmInstance.assetIds = [5];
    first.vmInstance.target = 2;
    await first.vmInstance.submit();
    assert.equal(storage.get('dam.collection-add.last.10'), '2');
    assert.deepEqual(first.calls.posts.map(post => post.url), ['/members/2']);

    const second = instance({ userId: 20, storage, managed });
    await second.vmInstance.load();
    assert.equal(second.vmInstance.target, 3, "person 20 gets their own collection, not person 10's remembered one");

    second.vmInstance.assetIds = [5];
    await second.vmInstance.submit();
    assert.equal(storage.get('dam.collection-add.last.20'), '3');
    assert.equal(storage.get('dam.collection-add.last.10'), '2');

    const again = instance({ userId: 10, storage, managed });
    await again.vmInstance.load();
    assert.equal(again.vmInstance.target, 2);
});

test('ignores a remembered collection that was deleted or whose management was revoked', async () => {
    const storage = new Map([['dam.collection-add.last.10', '7']]);

    const deleted = instance({ userId: 10, storage, managed: [mine(1, 10), mine(3, 20)] });
    await deleted.vmInstance.load();
    assert.equal(deleted.vmInstance.target, 1, 'falls back to one they own');

    const revoked = instance({ userId: 10, storage, managed: [{ id: 7, owner: { id: 10 }, can_manage: false }, mine(3, 20)] });
    await revoked.vmInstance.load();
    assert.deepEqual(revoked.vmInstance.collections.map(c => c.id), [3], 'can_manage filters it out of the choices');
    assert.equal(revoked.vmInstance.target, 'new', 'someone else\'s collection is never preselected');
});

test('falls back to a collection the person owns, else a new one, and focuses the name field for new', async () => {
    const owned = instance({ userId: 10, storage: new Map(), managed: [mine(3, 20), mine(4, 10)] });
    await owned.vmInstance.load();
    assert.equal(owned.vmInstance.target, 4);
    assert.equal(owned.calls.focused, 0);

    const none = instance({ userId: 10, storage: new Map(), managed: [mine(3, 20)] });
    await none.vmInstance.load();
    assert.equal(none.vmInstance.target, 'new');
    assert.equal(none.calls.focused, 1);

    const anonymous = instance({ userId: null, storage: new Map(), managed: [mine(3, 20)] });
    await anonymous.vmInstance.load();
    assert.equal(anonymous.vmInstance.target, 'new');
});

test('still loads and adds when localStorage throws', async () => {
    const { vmInstance, calls } = instance({ userId: 10, storage: new Map(), managed: [mine(4, 10)], throwingStorage: true });
    await vmInstance.load();
    assert.equal(vmInstance.target, 4);

    vmInstance.assetIds = [5];
    await vmInstance.submit();
    assert.equal(vmInstance.error, '');
    assert.equal(calls.flashes.at(-1).message, 'added');
    assert.equal(calls.done.at(-1).collectionId, 4);
    assert.equal(vmInstance.busy, false);
});

test('creates a new collection first, then adds to it, and remembers nothing on failure', async () => {
    const storage = new Map();
    const { vmInstance, calls } = instance({ userId: 10, storage, managed: [] });
    vmInstance.assetIds = [5];
    vmInstance.target = 'new';
    vmInstance.newName = '  Spring  ';
    await vmInstance.submit();
    assert.deepEqual(calls.posts.map(post => post.url), ['/store', '/members/900']);
    assert.equal(calls.posts[0].body.name, 'Spring');
    assert.equal(storage.get('dam.collection-add.last.10'), '900');

    const failing = instance({ userId: 11, storage, managed: [mine(8, 11)], failPosts: true });
    failing.vmInstance.assetIds = [5];
    failing.vmInstance.target = 8;
    await failing.vmInstance.submit();
    assert.equal(failing.vmInstance.error, 'nope');
    assert.equal(storage.has('dam.collection-add.last.11'), false);
});
