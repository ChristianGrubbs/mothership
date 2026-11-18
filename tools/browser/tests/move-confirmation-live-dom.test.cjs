// The move confirmation flow (sharing slice 2b), driven in a real browser against the shipped
// v-modal-confirm component (packages/Mothership/Admin/src/Resources/views/components/modal/confirm.blade.php)
// and two of the four move UIs' real methods — never a re-implementation of either. Codex review of
// PR #75, finding 10: the earlier version of this coverage ran addedItems() in a Node vm with a
// stubbed emitter; it never rendered the confirm modal or clicked its buttons. Round 2, finding 7:
// that version also stubbed restoreFolderTreeSnapshot() as a counter rather than rendering a real
// tree, so it could not detect a broken restoration, and covered only the tree's own optimistic UI.
// This file: mounts the real modal, wires it to the same $emitter each UI uses, clicks the rendered
// Agree/Disagree buttons a person actually sees, renders formattedItems as a real (if minimal)
// nested tree so a decline's rendered placement is assertable, and covers a second, non-optimistic
// UI — the explorer tab's performMassMove() — alongside the tree's addedItems()/onNativeAssetDrop().
//
// The datagrid's moveSelectedTo() and the asset editor's moveAsset() are not mounted here: neither
// layers component logic over the shared helper beyond calling it, so move-preview-confirmation.test.cjs
// covers both by driving window.MothershipFileOps.guardedMovePreview()/confirmMovePreview() directly,
// including every failure kind. See that file's own tests for why.
//
// The tree's own drag machinery (vue-draggable, Sortable) is not needed to prove the confirmation
// sequence itself, so only each UI's own methods — extracted from the real source the way
// library-navigation.test.cjs extracts v-dam-tree-view's other methods — are attached to a minimal
// host component alongside the real modal. moveAssets()/confirmMovePreview()/guardedMovePreview() are
// the real library-file-ops.js module, required rather than re-typed.
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = path.resolve(__dirname, '../../..');
const base = 'packages/Mothership/DAM/src/';
const source = (file) => fs.readFileSync(path.join(root, file), 'utf8');
const fileOpsPath = path.join(root, base + 'Resources/assets/js/library-file-ops.js');
const treeFile = base + 'Resources/views/components/tree/damdirectories.blade.php';
const tabFile = base + 'Resources/views/components/explorer/tab.blade.php';
const confirmModalFile = 'packages/Mothership/Admin/src/Resources/views/components/modal/confirm.blade.php';

/**
 * Same Blade-to-vm transform library-navigation.test.cjs and share-dialog-access-dom.test.cjs use.
 *
 * @json(...)/@js(...) can nest parens (`@js((int) ini_get('max_file_uploads'))`), so it is stripped by
 * counting depth rather than matching up to the first `)` — a naive `[^)]*` regex stops at that first
 * inner `)` and leaves the rest of the call as trailing, unparseable text.
 */
function blade(text) {
    let result = text
        .replace(/\{\{--[\s\S]*?--\}\}/g, '')
        .replace(/@js\(/g, '@json(');

    let pos;
    while ((pos = result.indexOf('@json(')) !== -1) {
        let depth = 1;
        let index = pos + 6;
        let quote = null;

        for (; index < result.length && depth; index++) {
            const c = result[index];

            if (quote) {
                if (c === '\\') index++;
                else if (c === quote) quote = null;
            } else if (c === '"' || c === "'") quote = c;
            else if (c === '(') depth++;
            else if (c === ')') depth--;
        }

        result = result.slice(0, pos) + 'null' + result.slice(index);
    }

    return result
        .replace(/@\{\{/g, '\u0000OPEN\u0000')
        .replace(/\{\{\s*(?:route|config)[\s\S]*?\}\}/g, (match) => '/'.concat(match.match(/(?:route|config)\(['"]([^'"]+)/)?.[1] || 'fixture', '/:id'))
        // The default Agree/Disagree button labels the test clicks by role+name: resolved to their real
        // en_US strings, not a generic placeholder, since v-modal-confirm's own default param values (not
        // addedItems()'s emit call) are what actually supply them here.
        .replace(/@lang\('admin::app\.components\.modal\.confirm\.agree-btn'\)/g, 'Agree')
        .replace(/@lang\('admin::app\.components\.modal\.confirm\.disagree-btn'\)/g, 'Disagree')
        .replace(/@lang\('[^']*'\)/g, 'Translation')
        .replace(/^\s*@(if|elseif|else|endif|php|endphp|pushOnce|endPushOnce).*$/gm, '')
        .replace(/\u0000OPEN\u0000/g, '{{');
}

/**
 * Object-method-shorthand source (`async addedItems(evt) { ... }`, as Function.prototype.toString()
 * returns it) is not a valid function expression on its own — `addedItems` there reads as an unexpected
 * identifier after `async`. Rebuilt as an anonymous function expression of the same kind (async or not),
 * which Vue binds to the component instance exactly as it would the original method.
 */
function toFunctionExpression(methodSource) {
    const head = methodSource.match(/^(async\s+)?[A-Za-z_$][\w$]*\s*(?=\()/);
    assert.ok(head, `unexpected method shorthand: ${methodSource.slice(0, 60)}`);

    const rest = methodSource.slice(head[0].length);

    // eslint-disable-next-line no-new-func -- the real method body, evaluated verbatim, not retyped.
    return new Function(`return ${head[1] || ''}function ${rest}`)();
}

/** Real methods off v-dam-tree-view's own registration, evaluated the way library-navigation.test.cjs does. */
function treeMethods() {
    const modules = [...source(treeFile).matchAll(/<script type="module">([\s\S]*?)<\/script>/g)].map((m) => blade(m[1]));
    const components = {};
    const context = {
        app: { component: (name, value) => { components[name] = value; } },
        window: { MothershipLibraryNavigation: {} },
        URL, URLSearchParams, console, setTimeout, clearTimeout,
        localStorage: { getItem: () => null, setItem() {}, removeItem() {} },
    };
    vm.createContext(context);
    for (const code of modules) vm.runInContext(code, context);
    assert.ok(components['v-dam-tree-view'], "v-dam-tree-view must still be registered in damdirectories.blade.php");

    return components['v-dam-tree-view'].methods;
}

/**
 * Real methods off v-dam-tab's own registration — the explorer tab, the one non-optimistic move UI
 * (ruling for Codex review of PR #75 round 2, finding 7): performMassMove() awaits
 * previewAndConfirmMove() before touching anything, unlike the tree's addedItems(), which the drag
 * gesture has already updated optimistically by the time it runs.
 */
function tabMethods() {
    const modules = [...source(tabFile).matchAll(/<script type="module">([\s\S]*?)<\/script>/g)].map((m) => blade(m[1]));
    const components = {};
    const context = {
        app: { component: (name, value) => { components[name] = value; } },
        window: {}, URL, URLSearchParams, console, setTimeout, clearTimeout,
        localStorage: { getItem: () => null, setItem() {}, removeItem() {} },
    };
    vm.createContext(context);
    for (const code of modules) vm.runInContext(code, context);
    assert.ok(components['v-dam-tab'], 'v-dam-tab must still be registered in tab.blade.php');

    return components['v-dam-tab'].methods;
}

/** The real modal's template and registration, with @lang() resolved and Blade's @{{ }} escape undone. */
function confirmModal() {
    const php = source(confirmModalFile);
    const template = php.match(/<script\s+type="text\/x-template"\s+id="v-modal-confirm-template"\s*>([\s\S]*?)<\/script>/);
    assert.ok(template, 'v-modal-confirm-template must exist');
    const registration = php.match(/<script type="module">([\s\S]*?)<\/script>/);
    assert.ok(registration, "app.component('v-modal-confirm', ...) must exist");

    return { template: blade(template[1]), script: blade(registration[1]) };
}

const browserTest = process.env.MOTHERSHIP_VUE_RUNTIME_PATH && process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH ? test : test.skip;
const launch = () => require(process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH).chromium.launch({ headless: true, channel: process.env.MOTHERSHIP_BROWSER_CHANNEL || 'chrome' });

/**
 * Mounts the real v-modal-confirm alongside a minimal host whose addedItems() is the real tree method —
 * extracted, not retyped — sharing one $emitter so the confirm dialog the method opens is the one the
 * page renders, and clicking its buttons resolves the same promise addedItems() is awaiting.
 */
async function mount(browser, { previewResponse, moveResponse = { data: { message: 'Moved.' } }, statusResponse = { data: { status: 'completed' } } }) {
    const page = await browser.newPage();
    const requests = [];

    await page.exposeFunction('reportRequest', (method, url) => requests.push({ method, url }));
    await page.route('http://move-confirm.test/**', (route) => route.fulfill({
        contentType: 'text/html',
        body: '<div id="app"><v-modal-confirm></v-modal-confirm></div>',
    }));
    await page.goto('http://move-confirm.test/library');
    await page.addScriptTag({ path: process.env.MOTHERSHIP_VUE_RUNTIME_PATH });
    // The real library-file-ops.js module: addedItems()/onNativeAssetDrop() call
    // window.MothershipFileOps.guardedMovePreview() internally, exactly as the shipped page does.
    await page.addScriptTag({ path: fileOpsPath });

    const methods = treeMethods();
    // Rebuilt into anonymous function-expression source in Node, once, rather than inside page.evaluate:
    // toFunctionExpression() calls assert.ok(), which page.evaluate's serialised callback cannot reach.
    const methodsSource = Object.fromEntries(
        ['addedItems', 'restoreFolderTreeSnapshot', 'clearFolderDragState', 'onNativeAssetDrop'].map((name) => [name, toFunctionExpression(methods[name].toString()).toString()])
    );

    await page.evaluate(({ modal, methodsSource, previewResponse, moveResponse, statusResponse }) => {
        const node = document.createElement('script');
        node.type = 'text/x-template';
        node.id = 'v-modal-confirm-template';
        node.textContent = modal.template;
        document.body.append(node);

        window.lockBodyScroll = () => {};
        window.unlockBodyScroll = () => {};

        const listeners = {};
        const emitter = {
            on(event, handler) { (listeners[event] ??= []).push(handler); },
            off(event, handler) { listeners[event] = (listeners[event] ?? []).filter((h) => h !== handler); },
            emit(event, payload) {
                window.__emitted.push({ event, payload });
                (listeners[event] ?? []).forEach((handler) => handler(payload));
            },
        };
        window.__emitted = [];

        const axios = {
            get: (url) => {
                window.reportRequest('get', url);

                if (url.includes('move-preview') || url.includes('move_preview')) {
                    if (previewResponse.reject) return Promise.reject(previewResponse.reject);

                    return Promise.resolve({ data: previewResponse.data });
                }

                return Promise.resolve(statusResponse);
            },
            post: (url) => {
                window.reportRequest('post', url);

                if (moveResponse.reject) return Promise.reject(moveResponse.reject);

                return Promise.resolve(moveResponse);
            },
        };

        // eslint-disable-next-line no-new-func -- the real methods, evaluated verbatim, not retyped.
        const addedItems = new Function(`return ${methodsSource.addedItems}`)();
        const onNativeAssetDrop = new Function(`return ${methodsSource.onNativeAssetDrop}`)();
        const restoreFolderTreeSnapshot = new Function(`return ${methodsSource.restoreFolderTreeSnapshot}`)();
        const clearFolderDragState = new Function(`return ${methodsSource.clearFolderDragState}`)();

        // A minimal, genuinely recursive renderer for formattedItems — not v-dam-tree-view's own
        // template (which needs Sortable/vuedraggable for the drag gesture itself, a separate,
        // already-tested library this file has no need to re-drive), but the real data
        // restoreFolderTreeSnapshot() reads and writes, rendered so its effect is visible in the DOM,
        // not just inspectable from JS.
        const treeItem = {
            name: 'test-tree-item',
            props: ['item'],
            template: '<li :data-id="item.id">{{ item.name }}<ul v-if="item.children && item.children.length"><test-tree-item v-for="child in item.children" :key="child.id" :item="child"></test-tree-item></ul></li>',
        };

        const app = Vue.createApp({
            data: () => ({
                damAccessLists: true, isLoading: false, actionStatus: null, moveStatusLabel: '',
                movingDirectoryId: null, nativeMoveBusy: false,
                formattedItems: null, dragSnapshot: null, dragStart: false,
                draggedDirectory: null, draggedDirectoryId: null, draggedDescendantIds: [],
                // onNativeAssetDrop()'s own ACL gate, ahead of the confirmation flow this file proves;
                // bypassed here so a drop reaches guardedMovePreview() the same way an admin's does.
                treeBusy: false, aclBypass: true, localAccessibleIds: [],
            }),
            components: { 'test-tree-item': treeItem },
            methods: {
                addedItems, onNativeAssetDrop, restoreFolderTreeSnapshot, clearFolderDragState,
                invalidateAllAssetCaches() {},
                loadDirectories: async () => {},
                checkActionStatus() {},
            },
            // The root's own template, not the mount target's original markup: app.mount() replaces
            // #app's contents with this, so the modal (and the rendered tree) have to be named here to
            // ever be instantiated.
            template: '<div><ul id="tree"><test-tree-item v-for="root in (formattedItems || [])" :key="root.id" :item="root"></test-tree-item></ul><v-modal-confirm></v-modal-confirm></div>',
        });
        app.config.globalProperties.$emitter = emitter;
        app.config.globalProperties.$axios = axios;

        new Function('app', modal.script)(app);
        window.__vm = app.mount('#app');
    }, { modal: confirmModal(), methodsSource, previewResponse, moveResponse, statusResponse });

    return { page, requests };
}

/**
 * Mounts the real v-modal-confirm alongside a minimal host whose previewAndConfirmMove()/
 * performMassMove()/currentFolderName() are the real v-dam-tab methods — extracted, not retyped — the
 * one non-optimistic move UI the round 2, finding 7 ruling asks for: nothing here is mutated before
 * previewAndConfirmMove() resolves, unlike the tree's own drag gesture.
 */
async function mountTab(browser, { previewResponse, moveResponse = { data: { message: 'Moved.' } }, statusResponse = { data: { status: 'completed' } } }) {
    const page = await browser.newPage();
    const requests = [];

    await page.exposeFunction('reportRequest', (method, url) => requests.push({ method, url }));
    await page.route('http://move-confirm-tab.test/**', (route) => route.fulfill({
        contentType: 'text/html',
        body: '<div id="app"><v-modal-confirm></v-modal-confirm></div>',
    }));
    await page.goto('http://move-confirm-tab.test/library');
    await page.addScriptTag({ path: process.env.MOTHERSHIP_VUE_RUNTIME_PATH });
    await page.addScriptTag({ path: fileOpsPath });

    const methods = tabMethods();
    const methodsSource = Object.fromEntries(
        ['previewAndConfirmMove', 'performMassMove', 'currentFolderName'].map((name) => [name, toFunctionExpression(methods[name].toString()).toString()])
    );

    await page.evaluate(({ modal, methodsSource, previewResponse, moveResponse, statusResponse }) => {
        const node = document.createElement('script');
        node.type = 'text/x-template';
        node.id = 'v-modal-confirm-template';
        node.textContent = modal.template;
        document.body.append(node);

        window.lockBodyScroll = () => {};
        window.unlockBodyScroll = () => {};

        const listeners = {};
        const emitter = {
            on(event, handler) { (listeners[event] ??= []).push(handler); },
            off(event, handler) { listeners[event] = (listeners[event] ?? []).filter((h) => h !== handler); },
            emit(event, payload) {
                window.__emitted.push({ event, payload });
                (listeners[event] ?? []).forEach((handler) => handler(payload));
            },
        };
        window.__emitted = [];

        const axios = {
            get: (url) => {
                window.reportRequest('get', url);

                if (url.includes('move-preview') || url.includes('move_preview')) {
                    if (previewResponse.reject) return Promise.reject(previewResponse.reject);

                    return Promise.resolve({ data: previewResponse.data });
                }

                if (url.includes('action_request') || url.includes('status')) {
                    return Promise.resolve(statusResponse);
                }

                return Promise.resolve({ data: {} });
            },
            post: (url, body) => {
                window.reportRequest('post', url);

                if (url.includes('count_items')) return Promise.resolve({ data: { file_count: (body?.asset_ids?.length || 0) + (body?.directory_ids?.length || 0) } });
                if (moveResponse.reject) return Promise.reject(moveResponse.reject);

                return Promise.resolve(moveResponse);
            },
        };

        // eslint-disable-next-line no-new-func -- the real methods, evaluated verbatim, not retyped.
        const previewAndConfirmMove = new Function(`return ${methodsSource.previewAndConfirmMove}`)();
        const performMassMove = new Function(`return ${methodsSource.performMassMove}`)();
        const currentFolderName = new Function(`return ${methodsSource.currentFolderName}`)();

        const app = Vue.createApp({
            data: () => ({
                damAccessLists: true, tabId: 'tab-1', breadcrumb: [{ name: 'Library folder' }],
                selection: { ids: [{ id: 5, type: 'directory' }], mode: 'select' },
                operationOverlay: { show: false, label: '', progress: null, fileCount: null },
            }),
            methods: { previewAndConfirmMove, performMassMove, currentFolderName },
            template: '<div><v-modal-confirm></v-modal-confirm></div>',
        });
        app.config.globalProperties.$emitter = emitter;
        app.config.globalProperties.$axios = axios;

        new Function('app', modal.script)(app);
        window.__vm = app.mount('#app');
    }, { modal: confirmModal(), methodsSource, previewResponse, moveResponse, statusResponse });

    return { page, requests };
}

/**
 * The optimistic mutation vuedraggable's own v-model binding already applied by the time the tree's
 * real "moved" handler calls addedItems() — moving $movedId out of the root's children and into
 * $targetId's, with dragSnapshot holding the pre-move JSON restoreFolderTreeSnapshot() reads back.
 * Not re-driving Sortable's own drag gesture (a separate, already-tested library); simulating its
 * outcome, the same as calling addedItems() directly does for the confirmation flow itself.
 */
async function simulateOptimisticMove(page, { movedId, movedName, targetId }) {
    await page.evaluate(({ movedId, movedName, targetId }) => {
        const before = [{ id: 1, name: 'Root', children: [
            { id: movedId, name: movedName, children: [] },
            { id: targetId, name: 'Target folder', children: [] },
        ] }];
        window.__vm.dragSnapshot = JSON.stringify(before);

        const after = JSON.parse(JSON.stringify(before));
        const [moved] = after[0].children.splice(0, 1);
        after[0].children[0].children.push(moved);
        window.__vm.formattedItems = after;
    }, { movedId, movedName, targetId });
}

/** The rendered tree's own placement, read back from the DOM rather than from formattedItems in JS. */
async function renderedParentOf(page, id) {
    return page.evaluate((id) => {
        const node = document.querySelector(`#tree [data-id="${id}"]`);

        return node?.parentElement?.closest('[data-id]')?.getAttribute('data-id') ?? null;
    }, id);
}

browserTest('a folder move whose preview reports a change renders the real dialog, and Disagree restores the rendered tree', async () => {
    const browser = await launch();

    try {
        const { page, requests } = await mount(browser, { previewResponse: { data: { folders: 1, files: 2 } } });
        await simulateOptimisticMove(page, { movedId: 5, movedName: 'Moved folder', targetId: 9 });

        // The optimistic move already rendered folder 5 nested under folder 9, before the dialog and
        // before addedItems() is even called — real vuedraggable behaviour, not something this test
        // asserts on, only sets up.
        assert.equal(await renderedParentOf(page, 5), '9', 'the optimistic move did not render');

        const call = page.evaluate(() => window.__vm.addedItems({ id: 5, name: 'Moved folder' }, 9, 'directory'));

        // Both buttons render from the modal's own default labels, since addedItems() emits no title/options.
        await page.getByRole('button', { name: 'Disagree' }).click();
        await call;

        assert.equal(requests.some((request) => request.method === 'post'), false, 'a decline must post nothing');
        // The real restoreFolderTreeSnapshot(), reflected in the DOM: folder 5 is back as a sibling of
        // folder 9 under the root (id 1), not nested under it.
        assert.equal(await renderedParentOf(page, 5), '1', 'a decline on a folder must restore the rendered tree to its pre-move placement');

        await page.close();
    } finally {
        await browser.close();
    }
});

browserTest('the same move posts once Agree is clicked on the real dialog, and keeps the rendered placement', async () => {
    const browser = await launch();

    try {
        const { page, requests } = await mount(browser, { previewResponse: { data: { folders: 0, files: 1 } } });
        await simulateOptimisticMove(page, { movedId: 5, movedName: 'Moved folder', targetId: 9 });

        const call = page.evaluate(() => window.__vm.addedItems({ id: 5, name: 'Moved folder' }, 9, 'directory'));

        // exact:true — a substring match on "Agree" also matches the rendered "Disagree" button.
        await page.getByRole('button', { name: 'Agree', exact: true }).click();
        await call;

        const posted = requests.find((request) => request.method === 'post');
        assert.ok(posted, 'agreeing must post the move');
        assert.equal(await renderedParentOf(page, 5), '9', 'an agreed move must keep the optimistic placement, not restore the snapshot');

        await page.close();
    } finally {
        await browser.close();
    }
});

browserTest('a preview reporting no change skips the dialog and posts directly', async () => {
    const browser = await launch();

    try {
        const { page, requests } = await mount(browser, { previewResponse: { data: { folders: 0, files: 0 } } });

        await page.evaluate(() => window.__vm.addedItems({ id: 5, name: 'Moved folder' }, 9, 'directory'));

        assert.equal(await page.getByRole('button', { name: 'Agree', exact: true }).count(), 0, 'no change means no confirmation is asked');
        assert.ok(requests.some((request) => request.method === 'post'), 'the move still posts');

        await page.close();
    } finally {
        await browser.close();
    }
});

browserTest('a refused move (422 from the guard) never renders the dialog, never posts, and restores the rendered tree (finding 6)', async () => {
    const browser = await launch();

    try {
        const { page, requests } = await mount(browser, {
            previewResponse: { reject: { response: { status: 422, data: { message: "This folder contains items you can't see." } } } },
        });
        await simulateOptimisticMove(page, { movedId: 5, movedName: 'Moved folder', targetId: 9 });

        await page.evaluate(() => window.__vm.addedItems({ id: 5, name: 'Moved folder' }, 9, 'directory'));

        assert.equal(await page.getByRole('button', { name: 'Agree', exact: true }).count(), 0, 'a failed preview must not offer a confirmation to agree to');
        assert.equal(requests.some((request) => request.method === 'post'), false, 'a refused preview must never let the move through unconfirmed');
        assert.equal(await renderedParentOf(page, 5), '1', 'a refused preview must restore the rendered tree to its pre-move placement, exactly like a decline');

        await page.close();
    } finally {
        await browser.close();
    }
});

browserTest('the asset drop path renders the same real dialog and honours Disagree', async () => {
    const browser = await launch();

    try {
        const { page, requests } = await mount(browser, { previewResponse: { data: { folders: 0, files: 1 } } });

        const call = page.evaluate(() => window.__vm.onNativeAssetDrop({ targetId: 9, targetName: 'Target', ids: [41] }));

        await page.getByRole('button', { name: 'Disagree' }).click();
        await call;

        assert.equal(requests.some((request) => request.method === 'post'), false, 'declining an asset drop must post nothing');

        await page.close();
    } finally {
        await browser.close();
    }
});

// --- The explorer tab's performMassMove(): the one non-optimistic move UI (finding 7) --------------
//
// The datagrid's moveSelectedTo() and the asset editor's moveAsset() are not mounted here: both call
// straight into window.MothershipFileOps.guardedMovePreview()/confirmMovePreview() with no component
// logic of their own layered on top (move-preview-confirmation.test.cjs already drives those two
// functions directly, including every failure kind: a network error, a refused move, and the
// over-cap local refusal). What performMassMove() adds beyond that shared helper — awaiting the
// preview before touching anything, then the operation overlay and the mass-move POST/poll sequence
// — is what these two cases exercise, mounted for real.

browserTest("performMassMove renders the real dialog and posts the mass-move once Agree is clicked", async () => {
    const browser = await launch();

    try {
        const { page, requests } = await mountTab(browser, {
            previewResponse: { data: { folders: 1, files: 0 } },
            statusResponse: { data: { status: 'completed' } },
        });

        const call = page.evaluate(() => window.__vm.performMassMove(9, 'Target folder'));

        await page.getByRole('button', { name: 'Agree', exact: true }).click();
        await call;

        assert.ok(requests.some((request) => request.method === 'post' && request.url.includes('mass_move')), 'agreeing must post the mass-move');

        await page.close();
    } finally {
        await browser.close();
    }
});

browserTest('performMassMove posts nothing when Disagree is clicked on the real dialog', async () => {
    const browser = await launch();

    try {
        const { page, requests } = await mountTab(browser, { previewResponse: { data: { folders: 1, files: 0 } } });

        const call = page.evaluate(() => window.__vm.performMassMove(9, 'Target folder'));

        await page.getByRole('button', { name: 'Disagree' }).click();
        await call;

        assert.equal(requests.some((request) => request.method === 'post'), false, 'declining must post nothing at all, including count_items');

        await page.close();
    } finally {
        await browser.close();
    }
});
