import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

// Regression: the sidebar tree emitted `dam:directory-granted` for folders made in its own create
// modal but never listened for it, so a folder granted by a drag-upload's createStructure or made
// in the explorer pane stayed outside localAccessibleIds for the whole session. "Move to" hid
// behind canAccessSelected(), the context menu fell back to "View only. Open a subfolder you have
// access to.", and dragging a folder onto the new one was refused as "Cannot move directory" —
// while the server would have accepted the move. PR #154 fixed the asset-drop half of this in
// explorer/tab.blade.php; this is the folder-move half.

const treeBlade = new URL('../../src/Resources/views/components/tree/damdirectories.blade.php', import.meta.url);
const navigationScript = new URL('../../src/Resources/assets/js/library-navigation.js', import.meta.url);

/** Strip a `@js(...)` / `@json(...)` call to a literal null, honouring nested parentheses. */
function stripBladeExpressions(line) {
    for (const directive of ['@js(', '@json(']) {
        let start = line.indexOf(directive);
        while (start !== -1) {
            let depth = 0;
            let end = start + directive.length - 1;
            for (; end < line.length; end++) {
                if (line[end] === '(') depth++;
                else if (line[end] === ')') {
                    depth--;
                    if (depth === 0) break;
                }
            }
            line = `${line.slice(0, start)}null/*${line.slice(start, end + 1)}*/${line.slice(end + 1)}`;
            start = line.indexOf(directive, start + 'null/*'.length + directive.length);
        }
    }
    return line;
}

function loadComponent(componentName) {
    const blade = readFileSync(treeBlade, 'utf8');
    const script = [...blade.matchAll(/<script type="module">([\s\S]*?)<\/script>/g)]
        .map(match => match[1])
        .find(source => source.includes(`app.component('${componentName}'`));
    assert.ok(script, `component ${componentName} not found in the tree blade`);

    const source = script
        .split('\n')
        .map(stripBladeExpressions)
        .filter(line => !/^\s*@(if|elseif|else|endif|once|endonce|push|endpush|pushOnce|endPushOnce)\b/.test(line))
        .join('\n');

    let component;
    vm.runInNewContext(source, {
        app: { component: (_name, definition) => { component = definition; } },
        window: {}, document: {},
        localStorage: { getItem: () => null, setItem() {}, removeItem() {} },
        sessionStorage: { getItem: () => null, setItem() {}, removeItem() {} },
    });
    assert.ok(component, `component ${componentName} did not register`);
    return component;
}

function loadNavigation() {
    const sandbox = { window: {}, module: { exports: {} } };
    sandbox.globalThis = sandbox;
    vm.runInNewContext(readFileSync(navigationScript, 'utf8'), sandbox);
    return sandbox.window.MothershipLibraryNavigation;
}

const tree = loadComponent('v-dam-tree-view');
const navigation = loadNavigation();

/** A mounted tree whose only page-load grant is the Library root. */
function mountTree() {
    const handlers = new Map();
    const instance = {
        // `visible: false` keeps mounted() from firing loadDirectories()'s HTTP call.
        visible: false,
        aclBypass: false,
        accessibleIds: [1],
        localAccessibleIds: [1],
        selectedItem: null,
        formattedItems: null,
        draggedDirectoryId: null,
        draggedDescendantIds: [],
        $emitter: {
            on: (event, handler) => {
                if (!handlers.has(event)) handlers.set(event, []);
                handlers.get(event).push(handler);
            },
            emit() {},
        },
        $axios: { interceptors: { response: { use: () => 1, eject() {} } } },
        loadDirectories() { throw new Error('loadDirectories must not run in this test'); },
    };

    tree.mounted.call(instance);

    return {
        instance,
        grant(id) {
            const listeners = handlers.get('dam:directory-granted') ?? [];
            assert.ok(listeners.length > 0, 'the tree must listen for dam:directory-granted');
            listeners.forEach(listener => listener(id));
        },
    };
}

test('the tree listens for dam:directory-granted and adds the folder to its live grant list', () => {
    const { instance, grant } = mountTree();

    assert.deepEqual(instance.localAccessibleIds.map(Number), [1]);
    grant(99);

    assert.deepEqual(instance.localAccessibleIds.map(Number), [1, 99]);
});

test('a folder granted after page load offers its folder actions instead of "View only"', () => {
    const { instance, grant } = mountTree();
    instance.selectedItem = { id: 99, name: 'Made this session' };

    assert.equal(tree.methods.canAccessSelected.call(instance), false, 'unknown before the grant arrives');

    grant(99);

    assert.equal(tree.methods.canAccessSelected.call(instance), true);
    assert.equal(tree.computed.newFolderParent.call(instance), instance.selectedItem);
});

test('a folder granted after page load is a valid drag target for a folder move', () => {
    const { instance, grant } = mountTree();
    const move = target => navigation.isValidFolderMoveTarget({
        sourceId: 1,
        targetId: target,
        descendantIds: [],
        accessibleIds: instance.localAccessibleIds,
        aclBypass: instance.aclBypass,
    });

    assert.equal(move(99), false, 'the stale page-load snapshot refuses the move');

    grant(99);

    assert.equal(move(99), true, 'the live grant list accepts it, as the server already would');
    assert.equal(move(42), false, 'a folder that was never granted is still refused');
});

test('the grant listener ignores repeats and non-numeric ids', () => {
    const { instance, grant } = mountTree();

    grant(99);
    grant('99');
    grant(null);
    grant('not-an-id');
    grant(0);

    assert.deepEqual(instance.localAccessibleIds.map(Number), [1, 99]);
});
