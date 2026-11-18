import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import fileOps from '../../src/Resources/assets/js/library-file-ops.js';

// Regression: a folder created in the current session is granted to its creator server-side and
// pushed into the tab's localAccessibleIds through `dam:directory-granted`, but the drop handler
// tested the immutable page-load prop, so dragging a file onto a folder you just made was refused
// with "You do not have access to this directory" until the page was reloaded.

function loadComponent(relativePath, componentName) {
    const blade = readFileSync(new URL(relativePath, import.meta.url), 'utf8');
    let script = [...blade.matchAll(/<script type="module">([\s\S]*?)<\/script>/g)]
        .map(match => match[1])
        .find(source => source.includes(`app.component('${componentName}'`));
    assert.ok(script, `component ${componentName} not found in ${relativePath}`);
    // @js() and @json() both print a JS literal; neither runs outside Blade.
    script = script.replace(/@js(?:on)?\(/g, () => 'null/*@json(');
    script = script.split('\n').map(line => {
        if (line.includes('null/*@json(')) {
            const start = line.indexOf('null/*@json(');
            let depth = 0;
            let i = start + 'null/*@json('.length - 1;
            for (; i < line.length; i++) {
                if (line[i] === '(') depth++;
                else if (line[i] === ')') { depth--; if (depth === 0) break; }
            }
            return line.slice(0, i + 1) + '*/' + line.slice(i + 1);
        }
        return line;
    }).filter(line => !/^\s*@(if|elseif|else|endif|once|endonce|push|endpush)\b/.test(line)).join('\n');
    let component;
    vm.runInNewContext(script, {
        app: { component: (_name, definition) => { component = definition; } },
        window: { MothershipFileOps: fileOps }, document: {},
        localStorage: { getItem: () => null, setItem() {}, removeItem() {} },
        sessionStorage: { getItem: () => null, setItem() {}, removeItem() {} },
    });
    return component;
}

// The component runs in a vm realm; compare its objects by value, not prototype.
const plain = value => JSON.parse(JSON.stringify(value));

const tab = loadComponent('../../src/Resources/views/components/explorer/tab.blade.php', 'v-dam-tab');

function tabInstance({ accessibleIds }) {
    const flashes = [];
    const posts = [];
    const emitted = [];
    const massMoves = [];
    const instance = {
        tabId: 'tab-1',
        aclBypass: false,
        accessibleIds,
        localAccessibleIds: [...accessibleIds],
        currentDirId: 1,
        $emitter: { emit: (event, payload) => { if (event === 'add-flash') flashes.push(payload); else emitted.push({ event, payload }); } },
        performMassMove: (...args) => { massMoves.push(args); },
        $axios: { post: (url, body) => { posts.push({ url, body }); return Promise.resolve({ data: {} }); } },
        fetch() {},
        // onInternalDrop() confirms through this before posting (sharing slice 2b); these two cases are
        // about the ACL gate above it, not the confirmation flow, so it always proceeds.
        previewAndConfirmMove: async () => true,
    };
    return { instance, flashes, posts, emitted, massMoves };
}

test('a drop onto a folder granted after page load is accepted', async () => {
    const accessibleIds = [1];
    const { instance, flashes, posts } = tabInstance({ accessibleIds });
    // What the `dam:directory-granted` listener does when the tree reports the new folder.
    instance.localAccessibleIds.push(99);

    tab.methods.onInternalDrop.call(instance, { payload: { type: 'dam-asset', id: 5, tabId: 'tab-1' }, targetDir: { id: 99 } });
    await new Promise(resolve => setImmediate(resolve));

    assert.deepEqual(flashes.filter(flash => flash.type === 'warning'), [], 'no access warning for a folder the user was granted this session');
    assert.equal(posts.length, 1, 'the move request is sent');
    assert.equal(posts[0].body.new_parent_id, 99);
});

test('a drop onto a folder the user was never granted is still refused', async () => {
    const { instance, flashes, posts } = tabInstance({ accessibleIds: [1] });

    tab.methods.onInternalDrop.call(instance, { payload: { type: 'dam-asset', id: 5, tabId: 'tab-1' }, targetDir: { id: 42 } });
    await new Promise(resolve => setImmediate(resolve));

    assert.equal(flashes.filter(flash => flash.type === 'warning').length, 1);
    assert.equal(posts.length, 0);
});

test('a dragged selection moves together through the mass move, never the single-item route', async () => {
    const { instance, posts, massMoves } = tabInstance({ accessibleIds: [1, 99] });

    await tab.methods.onInternalDrop.call(instance, { payload: { type: 'dam-asset', id: 5, ids: [5, 6], dirIds: [7], tabId: 'tab-1' }, targetDir: { id: 99, name: 'Dest' } });

    assert.equal(posts.length, 0);
    assert.deepEqual(plain(massMoves), [[99, 'Dest', { assetIds: [5, 6], dirIds: [7], refreshTabId: null }]]);
});

test('a selection dropped into another tab is moved by the tab it came from', async () => {
    const { instance, massMoves, emitted } = tabInstance({ accessibleIds: [1, 99] });
    const payload = { type: 'dam-asset', id: 5, ids: [5, 6], dirIds: [], tabId: 'tab-origin' };

    await tab.methods.onInternalDrop.call(instance, { payload, targetDir: { id: 99 } });

    assert.equal(massMoves.length, 0);
    assert.deepEqual(plain(emitted), [{ event: 'dam:explorer-internal-drop:tab-origin', payload: { payload, targetDir: { id: 99 }, refreshTabId: 'tab-1' } }]);
});

test('a selection is never moved into one of its own folders', async () => {
    const { instance, massMoves } = tabInstance({ accessibleIds: [1, 7] });

    await tab.methods.onInternalDrop.call(instance, { payload: { type: 'dam-folder', id: 7, ids: [5], dirIds: [7], tabId: 'tab-1' }, targetDir: { id: 7 } });

    assert.equal(massMoves.length, 0);
});
