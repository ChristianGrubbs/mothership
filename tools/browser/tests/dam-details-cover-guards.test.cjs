'use strict';

// While another of a stack's files is on show on the details page, nothing changes the cover. The REAL component
// scripts of the details page (rename, re-upload, delete, move, the AI card) run with a recording $axios, and each
// entry point is called as a person (or a late confirm answer) would reach it: zero requests go out. The header
// offers only the note and "Back to cover" then, and Back to cover restores the whole header.
const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '../../..');
const dam = path.join(root, 'packages/Mothership/DAM');
const views = path.join(dam, 'src/Resources/views');
const playwrightPath = process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH;
const browserTest = playwrightPath ? test.bind(null) : test.skip;
const read = (file) => fs.readFileSync(path.join(views, file), 'utf8');

test('both preview hosts subscribe and clean up default-file mapping refresh', () => {
    for (const file of ['asset/grid-preview-modal.blade.php', 'asset/preview-modal.blade.php']) {
        const source = read(file);
        assert.ok(source.includes("this.$emitter.on('dam:stack-default-changed', this.refreshStackDefault)"));
        assert.ok(source.includes("this.$emitter.off('dam:stack-default-changed', this.refreshStackDefault)"));
    }
});

const WORDS = {
    'dam::app.admin.dam.asset.stack-fan.viewing-stack-file': 'Viewing a stack file. Select the cover to edit, rename or replace.',
    'dam::app.admin.dam.asset.stack-fan.back-to-cover': 'Back to cover',
    'dam::app.admin.dam.asset.edit.ai.member.subject': 'This description is for :name',
    'dam::app.admin.dam.asset.edit.ai.creating': 'AI description is being created…',
};
const word = (key) => WORDS[key] || key.split('.').pop();
const asset = {id: 7, file_name: 'Cover.png', display_name: 'Cover', file_type: 'image', extension: 'png'};

/** Replaces every @js(...) / @json(...) call, found by balanced parentheses, with what `pick(expression)` returns. */
function replaceCalls(source, pick) {
    let output = '', offset = 0;
    for (;;) {
        const match = /@(js|json)\(/.exec(source.slice(offset));
        if (!match) return output + source.slice(offset);
        const start = offset + match.index;
        output += source.slice(offset, start);
        let depth = 1, quote = null, escape = false, end = start + match[0].length;
        for (; end < source.length && depth; end++) {
            const ch = source[end];
            if (escape) { escape = false; continue; }
            if (ch === '\\') { escape = true; continue; }
            if (quote) { if (ch === quote) quote = null; continue; }
            if (ch === "'" || ch === '"') { quote = ch; continue; }
            if (ch === '(') depth++;
            if (ch === ')') depth--;
        }
        output += pick(source.slice(start, end));
        offset = end;
    }
}

/** The module script that registers `name`, with its Blade calls resolved for a role that holds every permission. */
function component(source, name) {
    const at = source.indexOf(`app.component('${name}'`);
    const start = source.lastIndexOf('<script type="module">', at) + '<script type="module">'.length;
    return replaceCalls(source.slice(start, source.indexOf('</script>', at)), (expression) => {
        const route = /route\('([^']+)'/.exec(expression);
        const key = /trans(?:_choice)?\('([^']+)'/.exec(expression);
        if (expression.includes('config(') || expression.includes('dam_access_lists') || expression.includes('$aiRenameBase')) return 'true';
        if (expression.includes('liveShareLinks')) return 'null';
        if (expression.includes('display_name')) return '"Cover"';
        if (expression.includes('previewPath')) return '"/t.jpg"';
        if (expression.includes('$directory')) return '1';
        if (expression.includes('$asset')) return JSON.stringify(asset);
        if (route) return JSON.stringify(`/r/${route[1]}/__id__`);
        if (key) return JSON.stringify(word(key[1]));
        return '"x"';
    }).replace(/\{\{\s*route\('([^']+)'[^}]*\}\}/g, (_, name) => `/r/${name}/:id`).replace(/\{\{[^}]*\}\}/g, '2');
}

async function withBrowser(run) {
    const browser = await require('../launch-browser.cjs').launchBrowser({playwrightPath});
    try { await run(browser); } finally { await browser.close(); }
}

async function newPage(browser) {
    const page = await browser.newPage({viewport: {width: 1280, height: 900}});
    page.problems = [];
    page.on('pageerror', (error) => page.problems.push(error.message));
    page.on('console', (message) => { if (/was accessed during render but is not defined|Unhandled error/.test(message.text())) page.problems.push(message.text()); });
    await page.route('http://guards.test/**', (route) => route.fulfill({contentType: 'text/html', body: '<div id="app"></div>'}));
    await page.goto('http://guards.test/');
    await page.addScriptTag({path: process.env.MOTHERSHIP_VUE_RUNTIME_PATH || path.join(dam, 'node_modules/vue/dist/vue.global.js')});
    await page.addScriptTag({content: read('asset/stack-shown-script.blade.php').replace(/\{\{--[\s\S]*?--\}\}/g, '').replace(/<\/?script>/g, '')});
    // The page's emitter, $axios and navigation, all recording. Confirm dialogs queue: a test answers them when it likes.
    await page.evaluate(() => {
        const listeners = {};
        window.log = {requests: [], emitted: [], navigated: [], confirms: []};
        window.emitter = {
            on: (type, fn) => (listeners[type] ||= []).push(fn),
            off(type, fn) { listeners[type] = (listeners[type] || []).filter((other) => other !== fn); },
            emit(type, payload) {
                window.log.emitted.push(type);
                if (type === 'open-confirm-modal' || type === 'open-delete-modal') window.log.confirms.push({type, ...payload});
                (listeners[type] || []).slice().forEach((fn) => fn(payload));
            },
        };
        // What the preview component does: it says so (the shown-file mixin hears it) and its own previewData follows.
        window.isAlternate = false;
        const follow = (variantFileId) => { window.isAlternate = !! variantFileId; if (window.root?.$refs?.info) window.root.$refs.info.previewData = {id: 7, variantFileId}; };
        window.showAlternate = () => { follow(2); window.emitter.emit('dam:stack-file-shown', {assetId: 7, file: {name: 'Second.png'}}); };
        window.showMember = (id, name) => { follow(id); window.emitter.emit('dam:stack-file-shown', {assetId: 7, file: {id, name}}); };
        window.showCover = () => { follow(undefined); window.emitter.emit('dam:stack-file-shown', {assetId: 7, file: null}); };
        const record = (method) => async (url, ...rest) => {
            window.log.requests.push(`${method} ${url}`);
            // Every answer is the AI card's own payload (a describable file with one open suggestion, for a person who may tag).
            return {data: {ai: {caption: '', tags: [{term: 'cat', label: 'Cat'}]}, available: true, eligibility: {can_generate: true}, can_review: true, message: 'ok', file: {}}};
        };
        window.axiosStub = {get: record('GET'), post: record('POST'), put: record('PUT'), patch: record('PATCH'), delete: record('DELETE'), isCancel: () => false};
        window.definitions = {};
        window.app = {component: (name, definition) => { window.definitions[name] = definition; }};
        window.MothershipFileOps = {guardedMovePreview: async ({confirmMove}) => (window.movePreview ? window.movePreview(confirmMove) : true)};
    });
    return page;
}

/** The five details-page components that change the cover, mounted with their real scripts and no markup of their own. */
async function mountPage(browser) {
    const page = await newPage(browser);
    const edit = read('asset/edit.blade.php');
    for (const name of ['v-rename-asset', 'v-reupload-asset', 'v-delete-asset', 'v-move-asset']) {
        await page.addScriptTag({content: component(edit, name)});
    }
    await page.addScriptTag({content: component(read('asset/ai-card.blade.php'), 'v-dam-ai-card')});
    await page.addScriptTag({content: component(read('asset/embed/dialog.blade.php'), 'v-embed-asset')});
    // v-edit-asset's tag handlers are called on a plain object (its page-sized setup is not mounted here).
    await page.addScriptTag({content: component(edit, 'v-edit-asset')});
    // The Info pane's shared mixin (its first script), whose AI tag review it hosts.
    const info = read('asset/preview-modal/info-panel-script.blade.php');
    await page.addScriptTag({content: replaceCalls(info.slice(info.indexOf('<script>') + 8, info.indexOf('</script>')), (expression) => {
        const route = /route\('([^']+)'/.exec(expression);
        return route ? JSON.stringify(`/r/${route[1]}/__id__`) : '"x"';
    })});
    await page.evaluate(async () => {
        const app = Vue.createApp({template: '<div><v-rename-asset ref="rename"></v-rename-asset><v-reupload-asset ref="reupload"></v-reupload-asset><v-delete-asset ref="remove"></v-delete-asset><v-move-asset ref="move"></v-move-asset><v-dam-ai-card ref="card" :asset-id="7"></v-dam-ai-card><v-embed-asset ref="embed" :asset-id="7" initial-type="video"></v-embed-asset><v-info-host ref="info"></v-info-host></div>'});
        app.component('v-embed-asset', {...window.definitions['v-embed-asset'], template: '<div></div>'});
        const panel = window._damInfoPanel;
        app.component('v-info-host', {
            mixins: [window._damShownStackFile], template: '<div></div>', computed: panel.computed, methods: panel.methods,
            data() { return {...panel.data, previewData: {id: 7}, infoData: {ai: {can_review: true, tags: [{term: 'cat', label: 'Cat'}]}}, infoAssetId: 7}; },
        });
        window.tagCtx = () => ({
            ...window.definitions['v-edit-asset'].methods, asset: {id: 7}, displayTags: [{id: 1, name: 'a'}], tagValues: '[1]', tagComponentKey: 0,
            $axios: window.axiosStub, $emitter: window.emitter, assertCoverShown: window._damShownStackFile.methods.assertCoverShown,
            get stackFileShown() { return window.isAlternate; },
        });
        const fake = {template: '<div></div>', methods: {toggle() {}, open() {}, close() {}}};
        app.component('v-rename-asset', {...window.definitions['v-rename-asset'], template: '<div><form ref="assetRenameForm"></form><fake-modal ref="assetRenameModal"></fake-modal></div>'});
        app.component('fake-modal', fake);
        for (const name of ['v-reupload-asset', 'v-delete-asset', 'v-move-asset']) app.component(name, {...window.definitions[name], template: '<div></div>'});
        app.component('v-dam-ai-card', {...window.definitions['v-dam-ai-card'], template: '<div></div>'});
        app.config.globalProperties.$emitter = window.emitter;
        app.config.globalProperties.$axios = window.axiosStub;
        app.config.globalProperties.$navigate = (url) => window.log.navigated.push(url);
        window.root = app.mount('#app');
        // The AI card loads its payload (above) before any request is counted.
        await new Promise((resolve) => setTimeout(resolve, 30));
        window.log.requests.length = 0;
    });
    return page;
}

const entryPoints = {
    'renameAsset': (r) => r.rename.renameAsset({}, {resetForm() {}, setErrors() {}}),
    'renameItem': (r) => r.rename.renameItem(),
    'renameWithAi': (r) => r.rename.renameWithAi(),
    'onFileChange': (r) => r.reupload.onFileChange({target: {files: [new File(['x'], 'other.png', {type: 'image/png'})], value: 'x'}}),
    'handleFileUpload': (r) => r.reupload.handleFileUpload(new FormData()),
    'deleteFile': (r) => r.remove.deleteFile(),
    'moveAsset': (r) => r.move.moveAsset({id: 9}),
    'describe': (r) => r.card.describe(),
    'review': (r) => r.card.review('cat', 'accept'),
    'acceptAll': (r) => r.card.acceptAll(),
    'embed open': (r) => r.embed.open(),
    'embed create': (r) => r.embed.create(),
    'embed revoke': (r) => r.embed.revoke({id: 3}),
    'info: accept a suggestion': (r) => r.info.reviewAiTag('cat', 'accept'),
    'info: dismiss a suggestion': (r) => r.info.reviewAiTag('cat', 'dismiss'),
    'info: accept all': (r) => r.info.reviewAiTag(null, 'accept-all'),
    'add a tag': () => window.tagCtx().onTaggingChange({target: {value: 'sunset'}}),
    'remove a tag': () => window.tagCtx().onTaggingRemove({target: {value: 'sunset'}}),
};

browserTest('every entry point that changes the cover sends nothing and opens nothing while another stacked file is shown', () => withBrowser(async (browser) => {
    const page = await mountPage(browser);
    await page.evaluate(() => window.showAlternate());
    for (const name of Object.keys(entryPoints)) {
        await page.evaluate(`(async () => { await (${entryPoints[name].toString()})(window.root.$refs); await new Promise((resolve) => setTimeout(resolve, 20)); })()`);
        const sent = await page.evaluate(() => ({requests: window.log.requests.slice(), emitted: window.log.emitted.filter((type) => /^(open-|dam:open-|add-flash)/.test(type)), confirms: window.log.confirms.length}));
        assert.deepEqual(sent, {requests: [], emitted: [], confirms: 0}, `${name} reached the server or a dialog over another stacked file`);
    }
    assert.equal(await page.evaluate(() => window.root.$refs.embed.showModal), false, 'the Embed dialog opened over another stacked file');
    assert.equal(await page.evaluate(() => window.root.$refs.info.infoCanReviewAi), false, 'the Info pane offers AI review over another stacked file');
    assert.deepEqual(page.problems, []);
}));

browserTest('the same entry points still act on the cover when the cover is shown', () => withBrowser(async (browser) => {
    const page = await mountPage(browser);
    const requests = () => page.evaluate(() => window.log.requests.splice(0));
    const run = async (name) => { await page.evaluate(`(async () => { await (${entryPoints[name].toString()})(window.root.$refs); await new Promise((resolve) => setTimeout(resolve, 20)); })()`); };

    await run('renameAsset');
    assert.deepEqual(await requests(), ['POST /r/admin.dam.assets.rename/:id']);
    await run('handleFileUpload');
    assert.deepEqual(await requests(), ['POST /r/admin.dam.assets.re_upload/:id']);
    await run('moveAsset');
    assert.deepEqual(await requests(), ['POST /r/admin.dam.assets.moved/:id']);
    await run('renameWithAi');
    assert.equal(await page.evaluate(() => window.log.emitted.includes('dam:open-ai-rename')), true);
    await run('deleteFile');
    assert.equal(await page.evaluate(() => window.log.confirms.some((confirm) => confirm.type === 'open-delete-modal')), true);
    await run('review');
    assert.deepEqual((await requests())[0], 'POST /r/admin.dam.assets.ai-tags.accept/7');
    await run('acceptAll');
    assert.deepEqual((await requests())[0], 'POST /r/admin.dam.assets.ai-tags.accept-all/7');
    await run('describe');
    assert.deepEqual((await requests())[0], 'POST /r/admin.dam.assets.ai-tags.describe/7');
    await run('add a tag');
    assert.deepEqual(await requests(), ['POST /r/admin.dam.assets.tag/:id']);
    await run('remove a tag');
    assert.deepEqual(await requests(), ['POST /r/admin.dam.assets.remove-tag/:id']);
    await run('embed open');
    assert.deepEqual(await requests(), ['GET /r/admin.dam.assets.embeds.index/__id__']);
    assert.equal(await page.evaluate(() => window.root.$refs.embed.showModal), true);
    await run('embed create');
    assert.deepEqual(await requests(), ['POST /r/admin.dam.assets.embeds.index/__id__']);
    await run('embed revoke');
    assert.deepEqual(await requests(), ['POST /r/admin.dam.assets.embeds.index/__id__/3/revoke']);
    assert.equal(await page.evaluate(() => window.root.$refs.info.infoCanReviewAi), true, 'the Info pane offers AI review on the cover');
    await run('info: accept a suggestion');
    assert.equal((await requests())[0], 'POST /r/admin.dam.assets.ai-tags.accept/7');
    await run('info: accept all');
    assert.equal((await requests())[0], 'POST /r/admin.dam.assets.ai-tags.accept-all/7');
    assert.deepEqual(page.problems, []);
}));

browserTest('a confirm answered after the page moved to another stacked file does nothing (delete, replace, move)', () => withBrowser(async (browser) => {
    const page = await mountPage(browser);
    const settle = () => page.evaluate(() => new Promise((resolve) => setTimeout(resolve, 30)));
    const answer = (type, index = 0) => page.evaluate(([type, index]) => window.log.confirms.filter((confirm) => confirm.type === type)[index].agree(), [type, index]);
    const requests = () => page.evaluate(() => window.log.requests.splice(0));

    // Delete: asked on the cover, answered over an alternate.
    await page.evaluate(() => window.root.$refs.remove.deleteFile());
    await page.evaluate(() => window.showAlternate());
    await answer('open-delete-modal');
    await settle();
    assert.deepEqual(await requests(), [], 'a late "Delete" answer deleted the cover');
    assert.equal(await page.evaluate(() => window.root.$refs.remove.isDeleting), false);

    // Replace: a file with another name asks first; the answer arrives over an alternate.
    await page.evaluate(() => window.showCover());
    await page.evaluate(() => window.root.$refs.reupload.onFileChange({target: {files: [new File(['x'], 'other.png', {type: 'image/png'})], value: ''}}));
    await settle();
    await page.evaluate(() => window.showAlternate());
    await answer('open-confirm-modal');
    await settle();
    assert.deepEqual(await requests(), [], 'a late "Replace" answer replaced the cover');

    // Move: the preview is still out when the page moves; its answer must not move the cover.
    await page.evaluate(() => window.showCover());
    await page.evaluate(() => { window.movePreview = () => new Promise((resolve) => { window.releaseMove = () => resolve(true); }); window.root.$refs.move.moveAsset({id: 9}); });
    await page.evaluate(() => window.showAlternate());
    await page.evaluate(() => window.releaseMove());
    await settle();
    assert.deepEqual(await requests(), [], 'a late move preview moved the cover');
    assert.equal(await page.evaluate(() => window.root.$refs.move.isMoving), false);

    // Controls: answered while the cover is still shown, the same three go through.
    await page.evaluate(() => { window.showCover(); window.movePreview = null; });
    await page.evaluate(() => window.root.$refs.remove.deleteFile());
    await answer('open-delete-modal', 1);
    await settle();
    assert.deepEqual(await requests(), ['DELETE /r/admin.dam.assets.destroy/7']);
    await page.evaluate(() => window.root.$refs.reupload.onFileChange({target: {files: [new File(['x'], 'other.png', {type: 'image/png'})], value: ''}}));
    await settle();
    await answer('open-confirm-modal', 1);
    await settle();
    assert.deepEqual(await requests(), ['POST /r/admin.dam.assets.re_upload/:id']);
    assert.deepEqual(page.problems, []);
}));

/** The AI card, its real template and script, over a stack whose cover is `asset 7`; `window.axiosStub` answers (replace it before mounting to control replies). */
async function mountAiCard(page) {
    const source = read('asset/ai-card.blade.php');
    const markup = /id="v-dam-ai-card-template">([\s\S]*?)<\/script>/.exec(source)[1]
        .replace(/\{\{--[\s\S]*?--\}\}/g, '').replace(/<x-admin::accordion>/, '<div>').replace('</x-admin::accordion>', '</div>')
        .replace(/<x-slot:(header|content)[^>]*>/g, '<div>').replace(/<\/x-slot>/g, '</div>')
        .replace(/@lang\('([^']+)'\)/g, (_, key) => word(key)).replace(/@\{\{/g, '{{');
    await page.addScriptTag({content: component(source, 'v-dam-ai-card')});
    await page.evaluate((markup) => {
        const app = Vue.createApp({template: '<v-dam-ai-card ref="card" :asset-id="7"></v-dam-ai-card>'});
        app.component('v-dam-ai-card', {...window.definitions['v-dam-ai-card'], template: markup});
        app.config.globalProperties.$emitter = window.emitter;
        app.config.globalProperties.$axios = window.axiosStub;
        window.aiApp = app;
        window.root = app.mount('#app');
    }, markup);
}

/** An $axios whose every request waits for the test to answer it: `window.pending` lists them, `answer(i, data)` / `refuse(i, data)` settle one. */
async function controlledAxios(page) {
    await page.evaluate(() => {
        window.pending = [];
        const request = (method) => (url) => new Promise((resolve, reject) => window.pending.push({method, url, resolve, reject}));
        window.axiosStub = {get: request('GET'), post: request('POST'), put: request('PUT'), patch: request('PATCH'), delete: request('DELETE'), isCancel: () => false};
        window.answer = (index, data) => window.pending[index].resolve({data});
        window.refuse = (index, data, status = 503) => window.pending[index].reject({response: {status, data}});
    });
}

const coverCard = (extra = {}) => ({ai: null, available: true, can_review: true, eligibility: {state: 'unsupported', message: 'cover unsupported', can_generate: false, scope: 'stack_cover'}, ...extra});
const memberCard = (name, id, extra = {}) => ({ai: null, available: true, can_review: true, file: {id, name}, eligibility: {state: 'eligible', message: null, can_generate: true, scope: 'member'}, ...extra});

browserTest('over a stacked file the AI card is that file\'s own (named in full, its own request), tag review stays with the cover, and the cover\'s card returns with the cover', () => withBrowser(async (browser) => {
    const page = await newPage(browser);
    await mountAiCard(page);
    await page.waitForFunction(() => document.querySelector('[data-dam-ai-describe]'));
    assert.equal(await page.locator('[data-dam-ai-accept], [data-dam-ai-dismiss], [data-dam-ai-accept-all]').count(), 3, 'the cover offers tag review');

    await page.evaluate(() => window.showMember(2, 'Design Lab_Logo_Horizontal.png'));
    await page.waitForFunction(() => document.querySelector('[data-dam-ai-subject]'));
    assert.match(await page.locator('[data-dam-ai-subject]').textContent(), /This description is for Design Lab_Logo_Horizontal\.png/);
    assert.equal(await page.locator('[data-dam-ai-describe]').count(), 1, 'the selected file can be described in its own right');
    assert.equal(await page.locator('[data-dam-ai-accept], [data-dam-ai-dismiss], [data-dam-ai-accept-all]').count(), 0, 'tags and review stay with the cover');
    assert.ok((await page.evaluate(() => window.log.requests)).some((r) => r.startsWith('GET /r/admin.dam.assets.ai-tags.member/')), 'it asked the member route');

    await page.evaluate(() => window.showCover());
    await page.waitForFunction(() => ! document.querySelector('[data-dam-ai-subject]') && document.querySelector('[data-dam-ai-accept]'));
    assert.deepEqual(page.problems, []);
}));

browserTest('describing a stacked file shows "being created" while it runs, saves its own caption, and a failure leaves a message and Try again', () => withBrowser(async (browser) => {
    const page = await newPage(browser);
    await controlledAxios(page);
    await mountAiCard(page);
    await page.evaluate(() => window.answer(0, {ai: null, available: true, can_review: true, eligibility: {state: 'unsupported', message: 'cover unsupported', can_generate: false}}));
    await page.waitForFunction(() => document.querySelector('[data-dam-ai-message]'));

    await page.evaluate(() => window.showMember(2, 'Blue.png'));
    await page.waitForFunction(() => window.pending.length === 2);
    await page.evaluate(({card}) => window.answer(1, card), {card: memberCard('Blue.png', 2)});
    await page.locator('[data-dam-ai-describe]').click();
    await page.waitForFunction(() => window.pending.length === 3);
    assert.equal(await page.locator('[data-dam-ai-working]').count(), 1, 'a visible creating line while the request runs');
    assert.equal(await page.locator('[data-dam-ai-describe]').count(), 0, 'not an unchanged Create button');
    assert.match(await page.evaluate(() => window.pending[2].url), /ai-tags\.member\/.*\/describe$/);

    await page.evaluate(() => window.refuse(2, {message: 'The AI could not describe this file. Nothing was saved; try again.', retryable: true, ai: null, available: true, can_review: true, eligibility: {state: 'eligible', can_generate: true, scope: 'member'}}));
    await page.waitForFunction(() => document.querySelector('[data-dam-ai-notice]'));
    assert.match(await page.locator('[data-dam-ai-notice]').textContent(), /Nothing was saved/);
    assert.equal(await page.locator('[data-dam-ai-working]').count(), 0);
    assert.match(await page.locator('[data-dam-ai-describe]').textContent(), /retry/i);

    await page.locator('[data-dam-ai-describe]').click();
    await page.waitForFunction(() => window.pending.length === 4);
    await page.evaluate(({card}) => window.answer(3, card), {card: memberCard('Blue.png', 2, {ai: {caption: 'A blue triangle.', keywords: [], tags: []}, eligibility: {state: 'eligible', can_generate: false, scope: 'member'}})});
    await page.waitForFunction(() => document.querySelector('[data-dam-ai-caption]'));
    assert.equal((await page.locator('[data-dam-ai-caption]').textContent()).trim(), 'A blue triangle.');
    assert.deepEqual(page.problems, []);
}));

browserTest('a late reply for another stacked file, or for the cover, never paints onto what is on show now', () => withBrowser(async (browser) => {
    const page = await newPage(browser);
    await controlledAxios(page);
    await mountAiCard(page);
    await page.evaluate(({card}) => window.answer(0, card), {card: coverCard()});

    // Member A is asked about, then member B before A answers: A's late reply must not show on B.
    await page.evaluate(() => window.showMember(2, 'A.png'));
    await page.waitForFunction(() => window.pending.length === 2);
    await page.evaluate(() => window.showMember(3, 'B.png'));
    await page.waitForFunction(() => window.pending.length === 3);
    await page.evaluate(({b, a}) => { window.answer(2, b); window.answer(1, a); }, {
        b: memberCard('B.png', 3, {ai: {caption: 'Orange circle.', keywords: [], tags: []}}),
        a: memberCard('A.png', 2, {ai: {caption: 'Blue triangle.', keywords: [], tags: []}}),
    });
    await page.waitForFunction(() => document.querySelector('[data-dam-ai-caption]'));
    assert.equal((await page.locator('[data-dam-ai-caption]').textContent()).trim(), 'Orange circle.');
    assert.match(await page.locator('[data-dam-ai-subject]').textContent(), /B\.png/);

    // A is selected again and the cover right after, before A answers: A's late reply is dropped, the cover's card shows.
    await page.evaluate(() => window.showMember(2, 'A.png'));
    await page.waitForFunction(() => window.pending.length === 4);
    await page.evaluate(() => window.showCover());
    await page.waitForFunction(() => window.pending.length === 5);
    await page.evaluate(({late, cover}) => { window.answer(4, cover); window.answer(3, late); }, {
        late: memberCard('A.png', 2, {ai: {caption: 'Blue triangle, late.', keywords: [], tags: []}}),
        cover: coverCard(),
    });
    await page.waitForFunction(() => document.querySelector('[data-dam-ai-message]'));
    assert.equal(await page.locator('[data-dam-ai-caption]').count(), 0);
    assert.equal(await page.locator('[data-dam-ai-subject]').count(), 0);
    assert.deepEqual(page.problems, []);
}));

/** Member A's describe is out (its card is up and "being created" shows); returns with that POST's index. */
async function describePending(page) {
    await controlledAxios(page);
    await mountAiCard(page);
    await page.evaluate(({card}) => window.answer(0, card), {card: coverCard()});
    await page.evaluate(() => window.showMember(2, 'A.png'));
    await page.waitForFunction(() => window.pending.length === 2);
    await page.evaluate(({card}) => window.answer(1, card), {card: memberCard('A.png', 2)});
    await page.locator('[data-dam-ai-describe]').click();
    await page.waitForFunction(() => window.pending.length === 3 && document.querySelector('[data-dam-ai-working]'));
    return 2;
}

const flashes = (page) => page.evaluate(() => window.log.emitted.filter((type) => type === 'add-flash').length);

for (const [leave, label] of [['member', 'another stacked file'], ['cover', 'the cover']]) {
    for (const outcome of ['success', 'rejection']) {
        browserTest(`a describe still out for a stacked file, answered (${outcome}) after ${label} was selected, changes nothing on what is on show`, () => withBrowser(async (browser) => {
            const page = await newPage(browser);
            const post = await describePending(page);

            if (leave === 'member') {
                await page.evaluate(() => window.showMember(3, 'B.png'));
                await page.waitForFunction(() => window.pending.length === 4);
                await page.evaluate(({card}) => window.answer(3, card), {card: memberCard('B.png', 3)});
                await page.waitForFunction(() => /B\.png/.test(document.querySelector('[data-dam-ai-subject]')?.textContent || ''));
            } else {
                await page.evaluate(() => window.showCover());
                await page.waitForFunction(() => window.pending.length === 4);
                await page.evaluate(({card}) => window.answer(3, card), {card: coverCard()});
                await page.waitForFunction(() => document.querySelector('[data-dam-ai-message]'));
            }
            const before = await page.evaluate(() => ({html: document.querySelector('#app').innerHTML, busy: window.root.$refs.card.busy, working: window.root.$refs.card.working, notice: window.root.$refs.card.notice}));
            const flashed = await flashes(page);
            assert.equal(before.working, false, 'the old describe\'s creating line is gone with its file');
            assert.equal(before.busy, false);

            if (outcome === 'success') await page.evaluate(({post, card}) => window.answer(post, card), {post, card: memberCard('A.png', 2, {ai: {caption: 'Blue triangle, late.', keywords: [], tags: []}})});
            else await page.evaluate(({post}) => window.refuse(post, {message: 'The AI could not describe this file. Nothing was saved; try again.', retryable: true}), {post});
            await page.waitForTimeout(100);

            const after = await page.evaluate(() => ({html: document.querySelector('#app').innerHTML, busy: window.root.$refs.card.busy, working: window.root.$refs.card.working, notice: window.root.$refs.card.notice}));
            assert.deepEqual(after, before, 'a late describe answer must not change the card, notice, busy or working');
            assert.equal(await flashes(page), flashed, 'and must not flash a stale error');
            assert.equal(await page.locator('[data-dam-ai-caption]').count(), 0);
            assert.deepEqual(page.problems, []);
        }));
    }
}

for (const outcome of ['success', 'rejection']) {
    browserTest(`a describe for stacked file A answered (${outcome}) while stacked file B's own describe is still out leaves B creating until B answers`, () => withBrowser(async (browser) => {
        const page = await newPage(browser);
        const post = await describePending(page);

        await page.evaluate(() => window.showMember(3, 'B.png'));
        await page.waitForFunction(() => window.pending.length === 4);
        await page.evaluate(({card}) => window.answer(3, card), {card: memberCard('B.png', 3)});
        await page.locator('[data-dam-ai-describe]').click();
        await page.waitForFunction(() => window.pending.length === 5 && document.querySelector('[data-dam-ai-working]'));
        const flashed = await flashes(page);
        const state = () => page.evaluate(() => ({busy: window.root.$refs.card.busy, working: window.root.$refs.card.working, notice: window.root.$refs.card.notice, creating: document.querySelectorAll('[data-dam-ai-working]').length, create: document.querySelectorAll('[data-dam-ai-describe]').length, caption: document.querySelectorAll('[data-dam-ai-caption]').length}));
        const creating = {busy: true, working: true, notice: '', creating: 1, create: 0, caption: 0};
        assert.deepEqual(await state(), creating);

        // A's answer arrives while B is still being described: it must not end B's busy / creating state, flash, or write a notice.
        if (outcome === 'success') await page.evaluate(({post, card}) => window.answer(post, card), {post, card: memberCard('A.png', 2, {ai: {caption: 'Blue triangle, late.', keywords: [], tags: []}})});
        else await page.evaluate(({post}) => window.refuse(post, {message: 'The AI could not describe this file. Nothing was saved; try again.', retryable: true}), {post});
        await page.waitForTimeout(100);
        assert.deepEqual(await state(), creating, 'B stays busy and creating until its own answer');
        assert.equal(await flashes(page), flashed, 'no stale error flash');

        await page.evaluate(({card}) => window.answer(4, card), {card: memberCard('B.png', 3, {ai: {caption: 'Orange circle.', keywords: [], tags: []}, eligibility: {state: 'eligible', can_generate: false, scope: 'member'}})});
        await page.waitForFunction(() => document.querySelector('[data-dam-ai-caption]'));
        assert.equal((await page.locator('[data-dam-ai-caption]').textContent()).trim(), 'Orange circle.');
        assert.deepEqual(await state(), {busy: false, working: false, notice: '', creating: 0, create: 0, caption: 1});
        assert.deepEqual(page.problems, []);
    }));
}

for (const outcome of ['success', 'rejection']) {
    browserTest(`a describe still out when the card is unmounted, answered (${outcome}), paints nothing and flashes nothing`, () => withBrowser(async (browser) => {
        const page = await newPage(browser);
        const post = await describePending(page);
        const flashed = await flashes(page);
        const card = await page.evaluate(() => window.root.$refs.card);
        assert.ok(card !== undefined);
        const refs = await page.evaluate(() => { const vm = window.root.$refs.card; window.unmounted = vm; window.aiApp.unmount(); return {busy: vm.busy, working: vm.working}; });
        assert.deepEqual(refs, {busy: false, working: false}, 'unmounting ends the wait');

        if (outcome === 'success') await page.evaluate(({post, card}) => window.answer(post, card), {post, card: memberCard('A.png', 2, {ai: {caption: 'Blue triangle, late.', keywords: [], tags: []}})});
        else await page.evaluate(({post}) => window.refuse(post, {message: 'The AI could not describe this file. Nothing was saved; try again.', retryable: true}), {post});
        await page.waitForTimeout(100);

        const state = await page.evaluate(() => ({caption: window.unmounted.card.ai?.caption ?? null, notice: window.unmounted.notice, busy: window.unmounted.busy, working: window.unmounted.working}));
        assert.deepEqual(state, {caption: null, notice: '', busy: false, working: false});
        assert.equal(await flashes(page), flashed);
        assert.deepEqual(page.problems, []);
    }));
}

// --- The header ----------------------------------------------------------------------------------------------------

/** The header's slots exactly as edit.blade.php has them; every control is a visible stand-in button (dialogs, which draw nothing until opened, are not), the real wrappers are real. */
async function mountHeader(browser) {
    const page = await newPage(browser);
    const edit = read('asset/edit.blade.php');
    const slots = ['button-one', 'button-two', 'button-three', 'button-four', 'navButtons']
        .map((name) => new RegExp(`<x-slot:${name}>([\\s\\S]*?)</x-slot>`).exec(edit)[1]).join('\n')
        .replace(/\{\{--[\s\S]*?--\}\}/g, '').replace("@include('dam::asset.embed.dialog')", () => read('asset/embed/dialog.blade.php').split('@pushOnce')[0]).replace(/@include\([^\n]*\)/g, '').replace(/<x-dam::[^>]*\/>/g, '')
        .replace(/@if \([^\n]*\)/g, '').replace(/@endif/g, '').replace(/\{\{[^}]*\}\}/g, '#')
        .replace(/<(v-(?!dam-cover-only|dam-stack-back)[a-z-]+)\b[^>]*>\s*<\/\1>/g, (_, tag) => (tag.endsWith('-modal') ? `<i data-test-dialog="${tag}"></i>` : `<button type="button" data-test-control="${tag}">${tag}</button>`))
        .replace(/<v-dam-stack-back><\/v-dam-stack-back>/, '<v-dam-stack-back></v-dam-stack-back>');
    const templates = Object.fromEntries(['v-dam-stack-back-template'].map((id) => [id, new RegExp(`id="${id}">([\\s\\S]*?)</script>`).exec(edit)[1].replace(/@lang\('([^']+)'\)/g, (_, key) => word(key))]));
    // The two header components' real registrations (the module block they sit in also holds the page's big component).
    const registration = (name) => new RegExp(`app\\.component\\('${name}',[\\s\\S]*?\\n            \\}\\);`).exec(edit)[0];
    await page.evaluate(() => { window.definitions = {}; window.app = {component: (name, definition) => { window.definitions[name] = definition; }}; });
    await page.addScriptTag({content: registration('v-dam-cover-only') + registration('v-dam-stack-back')});
    await page.evaluate(({slots, templates}) => {
        const app = Vue.createApp({template: `<div>${slots}</div>`});
        app.component('v-dam-cover-only', window.definitions['v-dam-cover-only']);
        app.component('v-dam-stack-back', {...window.definitions['v-dam-stack-back'], template: templates['v-dam-stack-back-template']});
        app.config.globalProperties.$emitter = window.emitter;
        // The preview component answers "Back to cover" by showing the cover again (its real handler is exercised elsewhere).
        window.emitter.on('dam:stack-back-to-cover', () => window.showCover());
        window.root = app.mount('#app');
    }, {slots, templates});
    return page;
}

browserTest('over another stacked file the header offers only the note and one "Back to cover" button; Back to cover restores the whole header', () => withBrowser(async (browser) => {
    const page = await mountHeader(browser);
    const visibleControls = () => page.evaluate(() => [...document.querySelectorAll('#app button, #app a')].filter((el) => el.getClientRects().length > 0).map((el) => el.dataset.testControl || el.textContent.trim()));
    const onCover = await visibleControls();
    for (const control of ['v-custom-download', 'v-rename-asset', 'v-move-asset', 'v-reupload-asset', 'v-delete-asset', 'v-share-asset-button', 'v-embed-asset', 'v-dam-add-to-collection-button']) {
        assert.ok(onCover.includes(control), `${control} is missing from the header on the cover`);
    }
    assert.ok(onCover.includes('Generation details'), 'the generation link is missing on the cover');
    assert.equal(await page.locator('[data-dam-stack-file-bar]').count(), 0, 'the note shows on the cover');

    await page.evaluate(() => window.showAlternate());
    await page.locator('[data-dam-stack-file-bar]').waitFor();
    // Back to cover is the one action over another file; every cover action stays hidden with the rest.
    assert.deepEqual(await visibleControls(), ['Back to cover'], 'something besides Back to cover is on offer over another file');
    assert.match(await page.locator('[data-dam-stack-file-note]').textContent(), /Viewing a stack file\. Select the cover to edit, rename or replace\./);

    await page.locator('[data-dam-back-to-cover]').click();
    await page.waitForFunction(() => ! document.querySelector('[data-dam-stack-file-bar]'));
    assert.deepEqual(await visibleControls(), onCover, 'Back to cover did not restore the whole header');
    assert.deepEqual(page.problems, []);
}));
