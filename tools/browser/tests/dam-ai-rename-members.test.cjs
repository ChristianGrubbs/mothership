'use strict';
const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const views = path.resolve(__dirname, '../../../packages/Mothership/DAM/src/Resources/views');

function blade(source) {
    return source.replace(/@(js|json)\(/g, '\u0001(').split('\u0001').map((part, index) => {
        if (! index) return part;
        let depth = 0, quote = '', escaped = false, end = 0;
        for (; end < part.length; end++) {
            const char = part[end];
            if (escaped) { escaped = false; continue; }
            if (char === '\\') { escaped = true; continue; }
            if (quote) { if (char === quote) quote = ''; continue; }
            if (char === "'" || char === '"') { quote = char; continue; }
            if (char === '(') depth++;
            if (char === ')' && --depth === 0) break;
        }
        const expression = part.slice(0, end + 1);
        const route = /route\('([^']+)'/.exec(expression);
        const value = route ? `/r/${route[1]}/__ASSET__/__SLOT__` : expression.includes('hasPermission') ? true : expression.includes('.summary') ? ':renamed renamed :unchanged unchanged :titled titled :failed failed :skipped skipped' : 'label';
        return JSON.stringify(value) + part.slice(end + 1);
    }).join('');
}
function component(file, name) {
    const source = fs.readFileSync(path.join(views, file), 'utf8');
    const at = source.indexOf(`app.component('${name}'`);
    const start = source.lastIndexOf('<script type="module">', at) + '<script type="module">'.length;
    let definition;
    new Function('app', 'window', blade(source.slice(start, source.indexOf('</script>', at))))({component: (key, value) => { if (key === name) definition = value; }}, {location: {search: ''}});
    return definition;
}
function host(definition, extra = {}) {
    const result = {...definition.data.call({}), ...extra};
    for (const [key, value] of Object.entries(definition.methods)) result[key] = value.bind(result);
    for (const [key, value] of Object.entries(definition.computed || {})) Object.defineProperty(result, key, {get: value.bind(result)});
    return result;
}
const modal = () => component('components/explorer/ai-rename-modal.blade.php', 'v-dam-ai-rename-modal');
const row = (id, options = {}) => ({id: 1, attachmentId: id, fileId: id + 100, key: `1:${id}`, order: id, state: 'ready', checked: true, current: 'IMG_0001.jpg', serverName: 'IMG_0001.jpg', name: 'event', ext: 'jpg', stem: 'event', ...options});

test('expands 15-member and multiple stack selections before suggestions, retaining unsupported identity/reason', async () => {
    const members = Array.from({length: 16}, (_, index) => ({asset_id: index < 15 ? 1 : 2, attachment_id: index + 1, file_id: index + 101, file_name: `IMG_${index}.jpg`, thumbnail_url: `/member/${index}.jpg`, extension: 'jpg', is_representative: index === 0, eligibility: {state: index === 14 ? 'unsupported' : 'eligible', message: 'No preview'}}));
    const requests = [];
    const h = host(modal(), {$refs: {aiRenameModal: {open() {}}}, $axios: {post: async (url, body) => {requests.push(body); return {data: {rows: members}}; }}});
    let suggestions;
    h.suggestAll = rows => {suggestions = rows;};
    await h.open({assets: [{id: 1, name: 'First stack'}, {id: 2, name: 'Second stack'}]});
    assert.deepEqual(requests, [{asset_ids: [1, 2]}]);
    assert.equal(h.rows.length, 16);
    assert.equal(new Set(h.rows.map(row => row.key)).size, 16);
    assert.equal(h.rows[14].message, 'No preview');
    assert.equal(h.rows[15].stackName, 'Second stack');
    assert.equal(suggestions.length, 15);
    assert.deepEqual(h.rows.map(row => row.thumb), members.map(member => member.thumbnail_url));
});
test('mixed unavailable assets retain generic separate rows while valid member suggestions continue', async () => {
    const rows = [{asset_id: 1, attachment_id: 10, file_id: 20, file_name: 'IMG_1.jpg', eligibility: {state: 'eligible'}}, {asset_id: 2, attachment_id: null, file_id: null, file_name: null, eligibility: {state: 'unavailable', message: 'Unavailable'}}, {asset_id: 3, attachment_id: null, file_id: null, file_name: null, eligibility: {state: 'unavailable', message: 'Unavailable'}}];
    const h = host(modal(), {$refs: {aiRenameModal: {open() {}}}, $axios: {post: async () => ({data: {rows}})}});
    let suggested;
    h.suggestAll = rows => {suggested = rows;};
    await h.open({assets: [{id: 1}, {id: 2, name: 'Stale hidden name'}, {id: 3}]});
    assert.deepEqual(h.rows.map(row => row.key), ['1:10', '2:unavailable', '3:unavailable']);
    assert.equal(h.rows[1].stackName, '2');
    assert.notEqual(h.rows[1].current, 'Stale hidden name');
    assert.equal(suggested.length, 1);
});
test('members service failure retains selected row count, reason and a retryable listing state', async () => {
    const h = host(modal(), {$refs: {aiRenameModal: {open() {}}}, $axios: {post: async () => {throw {response: {data: {message: 'Try listing again'}}};}}});
    h.suggestAll = () => {};
    await h.open({assets: [{id: 1}, {id: 2}]});
    assert.equal(h.rows.length, 2);
    assert.equal(h.checking, false);
    assert.equal(h.membersError, true);
    assert.ok(h.rows.every(row => row.state === 'ineligible' && row.message === 'Try listing again'));
});
test('rename-only roles use legacy representative eligibility/suggestion/apply without requesting member access', async () => {
    const calls = [];
    const h = host(modal(), {canListMembers: false, $refs: {aiRenameModal: {open() {}, close() {}}}, $emitter: {emit() {}}});
    h.$axios = {post: async (url, body) => {
        calls.push({url, body});
        if (url === h.urls.eligibility) return {data: {available: true, eligibility: {1: {can_rename: true}}}};
        if (url === h.urls.suggest) return {data: {file_name: 'IMG_1.jpg', stem: 'reviewed', title: 'Title', extension: 'jpg'}};
        if (url === h.urls.rename) return {data: {data: {file_name: body.file_name}}};
        throw Error('Member API must not be called');
    }};
    await h.open({assets: [{id: 1, name: 'IMG_1.jpg'}]});
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(h.rows[0].state, 'ready');
    await h.apply();
    assert.deepEqual(calls.map(call => call.body), [{ids: [1]}, {id: 1}, {id: 1, file_name: 'reviewed.jpg', title: 'Title'}]);
    assert.equal(h.rows[0].checked, false);
});
test('select-all, clear and indeterminate affect eligible unfinished rows only', () => {
    const h = host(modal(), {rows: [row(1), row(2, {checked: false}), row(3, {state: 'ineligible', checked: false}), row(4, {state: 'renamed'})]});
    assert.equal(h.selectionIndeterminate, true);
    h.setAllEligible({checked: true});
    assert.equal(h.allEligibleSelected, true);
    assert.equal(h.rows[2].checked, false);
    h.setAllEligible({checked: false});
    assert.equal(h.selectedCount, 0);
    assert.equal(h.rows[3].checked, true);
});
test('late member suggestion preserves an explicit uncheck and manual name', async () => {
    let release;
    const member = row(1, {known: false, edited: true, name: 'manual', checked: false, selectionExplicit: true});
    const h = host(modal(), {rows: [member], $axios: {post: () => new Promise(resolve => {release = resolve;})}});
    const pending = h.suggest(member, h.run);
    release({data: {file_name: 'IMG_9999.jpg', stem: 'proposed', extension: 'jpg'}});
    await pending;
    assert.equal(member.checked, false);
    assert.equal(member.name, 'manual');
});
test('duplicate numbering follows capture time with stable input-order ties and preserves manual names', () => {
    const h = host(modal(), {rows: [row(1, {capturedAt: '2026-10-15', edited: true, name: 'manual'}), row(2, {capturedAt: '2026-10-14'}), row(3, {capturedAt: '2026-10-15'})]});
    h.number();
    assert.deepEqual(h.rows.map(row => row.name), ['manual', 'event_01', 'event_03']);
});
test('partial apply retries only failures, sends exact member IDs and representative-only title, counts no-ops', async () => {
    const calls = [], emitted = [];
    let failure = true;
    const h = host(modal(), {rows: [row(1, {representative: true, title: 'Title', name: 'event.jpg'}), row(2), row(3), row(4, {state: 'ineligible', checked: false}), row(5, {checked: false})], $emitter: {emit: (...args) => emitted.push(args)}});
    h.close = () => {};
    h.$axios = {post: async (url, body) => {
        calls.push(body);
        if (body.attachment_id === 3 && failure) throw {response: {data: {message: 'Try again'}}};
        return {data: {status: body.attachment_id === 2 ? 'unchanged' : 'renamed', file_name: body.file_name}};
    }};
    await h.apply();
    assert.equal(calls[0].file_name, 'event.jpg');
    assert.deepEqual([calls[0].asset_id, calls[0].attachment_id, calls[0].file_id], [1, 1, 101]);
    assert.equal(calls[0].title, 'Title');
    assert.equal(calls[1].title, undefined);
    assert.match(h.summary, /1 renamed 1 unchanged 0 titled 1 failed 2 skipped/);
    assert.deepEqual(h.rows.slice(0, 3).map(row => row.checked), [false, false, true]);
    failure = false;
    await h.apply();
    assert.deepEqual(calls.map(body => body.attachment_id), [1, 2, 3, 3]);
    assert.match(h.summary, /2 renamed 1 unchanged 0 titled 0 failed 2 skipped/);
});
test('strip promotion uses attachment ID and leaves state unchanged on refusal', async () => {
    const def = component('asset/preview-modal/attachments/strip-script.blade.php', 'v-dam-attachment-strip');
    const file = {id: 999, attachment_id: 12, asset_id: 7, role: 'alternate'};
    const files = [file];
    const calls = [];
    const h = host(def, {assetId: 7, canEdit: true, files, $emitter: {emit: (...args) => calls.push(args)}, $axios: {post: async url => {assert.match(url, /7\/12$/); throw {response: {data: {message: 'Refused'}}};}}});
    await h.promoteNow(file);
    assert.equal(h.files, files);
    assert.equal(h.saving, false);
    assert.deepEqual(calls, [['add-flash', {type: 'error', message: 'Refused'}]]);
});
test('strip default confirmation follows the addressed stack and success refreshes the mapping', async () => {
    const def = component('asset/preview-modal/attachments/strip-script.blade.php', 'v-dam-attachment-strip');
    const file = {id: 999, attachment_id: 12, asset_id: 7, role: 'alternate'};
    const returned = [{...file, is_representative: true}];
    const events = [], requests = [];
    const h = host(def, {assetId: 7, canEdit: true, files: [file], $emitter: {emit: (...args) => events.push(args)}, $axios: {post: async url => {requests.push(url); return {data: {files: returned, asset: {id: 7}}};}}});
    h.promote(file);
    const confirmation = events[0][1];
    h.assetId = 8;
    await confirmation.agree();
    assert.equal(requests.length, 0);
    h.assetId = 7;
    await h.promoteNow(file);
    assert.equal(h.files, returned);
    assert.deepEqual(events.at(-2), ['dam:stack-default-changed', {assetId: 7, files: returned, asset: {id: 7}}]);
    assert.deepEqual(events.at(-1), ['dam-asset-files-updated', {assetId: 7}]);
});
test('Files default refusal preserves rows and late success never reloads another asset', async () => {
    const def = component('asset/files/index.blade.php', 'v-dam-asset-files');
    const file = {id: 999, attachment_id: 12, asset_id: 7, role: 'alternate'};
    const files = [file], events = [];
    const h = host(def, {assetId: 7, canEditStack: true, canEditDetails: true, files, $emitter: {emit: (...args) => events.push(args)}, $navigate() {throw new Error('Wrong reload');}, $axios: {post: async () => {throw {response: {data: {message: 'Refused'}}};}}});
    await h.promoteNow(file);
    assert.equal(h.files, files);
    assert.equal(h.arranging, false);
    assert.deepEqual(events, [['add-flash', {type: 'error', message: 'Refused'}]]);
    let release;
    h.$axios.post = () => new Promise(resolve => {release = resolve;});
    const pending = h.promoteNow(file);
    h.assetId = 8;
    release({data: {files: [], asset: {id: 7}}});
    await pending;
    assert.equal(h.files, files);
    assert.deepEqual(events.at(-2), ['dam:stack-default-changed', {assetId: 7, files: [], asset: {id: 7}}]);
});
test('promotion mapping keeps viewed content through old/new default changes', () => {
    const source = fs.readFileSync(path.join(views, 'asset/preview-modal/attachments/strip-script.blade.php'), 'utf8');
    const window = {};
    new Function('window', blade(source.slice(source.indexOf('<script>') + 8, source.indexOf('</script>'))))(window);
    const before = [{id: 10, attachment_id: 1, is_representative: true}, {id: 20, attachment_id: 2}];
    const files = [{id: 10, attachment_id: 1, asset_id: 7}, {id: 20, attachment_id: 2, asset_id: 7, is_representative: true}];
    for (const variantFileId of [null, 20]) {
        const h = {previewData: {id: 7, variantFileId}, previewVariants: before, _coverPreviewData: {}, variantPreviewData: file => ({mediaUrl: `/bytes/${file.id}`}), renderStackFile() {this.shown = this.previewVariant?.id || 20;}};
        window._damStackSwitch.methods.refreshStackDefault.call(h, {assetId: 7, files, asset: {file_name: 'new.jpg'}});
        assert.equal(h.shown, variantFileId || 10);
        assert.equal(h._coverPreviewData.mediaUrl, '/bytes/20');
    }
});
test('default filename labels distinguish real stacks from titled single files', () => {
    const source = fs.readFileSync(path.join(views, 'asset/preview-modal/attachments/strip-script.blade.php'), 'utf8');
    const window = {};
    new Function('window', blade(source.slice(source.indexOf('<script>') + 8, source.indexOf('</script>'))))(window);
    const label = window._damStackSwitch.methods.defaultFileLabel;
    const single = {previewData: {id: 1, display_name: 'Commencement 2026', file_name: 'IMG_1234.jpg', attachment_count: 1}, previewVariants: []};
    assert.equal(label.call(single), '', 'a title does not turn one photo into a stack');
    single.previewData.display_name = 'IMG_1234.jpg';
    assert.equal(label.call(single), '', 'untitled single files have no Default label');
    single.previewVariants = [{asset_id: 1, is_representative: true, file_name: 'IMG_1234.jpg'}];
    single.previewData.display_name = 'Commencement 2026';
    assert.equal(label.call(single), '', 'listing one representative still is not a stack');
    const stack = {previewData: {id: 1, display_name: 'Old JPG stack title', file_name: 'member2.png', attachment_count: 15}, previewVariants: []};
    assert.equal(label.call(stack), 'member2.png', 'grid stacks retain count before member listing arrives');
    stack.previewData.attachment_count = null;
    stack.previewVariants = [{asset_id: 1, is_representative: true, file_name: 'member2.png'}, {asset_id: 1, file_name: 'member3.jpg'}];
    assert.equal(label.call(stack), 'member2.png', 'loaded same-asset members prove the stack');
    stack.previewData.variantFileId = 3;
    assert.equal(label.call(stack), '', 'a variant uses its existing filename label');
    stack.previewData.variantFileId = null;
    stack.previewData.display_name = 'member2.png';
    assert.equal(label.call(stack), '', 'equal title and filename need no extra line');
    stack.previewData.display_name = 'Title';
    stack.previewVariants = [{asset_id: 1, is_representative: true, file_name: 'member2.png'}, {asset_id: 99, file_name: 'unrelated.jpg'}];
    assert.equal(label.call(stack), '', 'members of another asset do not prove a stack');
});
test('chooser default target stays on the selected member while arrow focus moves elsewhere', async () => {
    const file3 = {id: 3, attachment_id: 13, asset_id: 1, file_name: 'member3.png', role: 'alternate'};
    const file5 = {id: 5, attachment_id: 15, asset_id: 1, file_name: 'member5.png', role: 'alternate'};
    const events = [];
    const h = host(component('components/explorer/stack-fan.blade.php', 'v-dam-stack-fan'), {files: [file3, file5], asset: {id: 1}, selectedAttachmentId: 15, focusIndex: 0, lastVariantIndex: 1, canEditDefault: true, phase: 'open', $emitter: {emit: (...args) => events.push(args)}});
    assert.equal(h.defaultCandidate, file5);
    h.focusIndex = 0;
    h.promote(h.defaultCandidate);
    let requested;
    h.$axios = {post: async url => {requested = url; return {data: {files: [{...file5, is_representative: true}, file3], asset: {id: 1}}};}};
    await events[0][1].agree();
    assert.match(requested, /1\/15$/);
    assert.equal(h.defaultCandidate.id, 5);
});
test('promotion invalidates cached cover facts and reloads the open Info pane for the new default', async () => {
    const window = {matchMedia: () => ({matches: true})};
    for (const file of ['asset/preview-modal/attachments/strip-script.blade.php', 'asset/preview-modal/info-panel-script.blade.php']) {
        const source = fs.readFileSync(path.join(views, file), 'utf8');
        new Function('window', blade(source.slice(source.indexOf('<script>') + 8, source.indexOf('</script>'))))(window);
    }
    let answer;
    const h = {...window._damInfoPanel.data, ...window._damInfoPanel.methods,
        isInfoPanelOpen: true, infoData: {mime_type: 'image/jpeg'}, infoAssetId: 7,
        previewData: {id: 7, variantFileId: 20}, previewVariants: [{id: 10, attachment_id: 1, is_representative: true}, {id: 20, attachment_id: 2}], _coverPreviewData: {id: 7},
        variantPreviewData: () => ({mime_type: 'image/png'}), renderStackFile() {this.previewData = {...this._coverPreviewData};},
        $axios: {get: () => new Promise(resolve => {answer = resolve;})},
    };
    window._damStackSwitch.methods.refreshStackDefault.call(h, {assetId: 7, files: [{id: 20, asset_id: 7, attachment_id: 2, is_representative: true}]});
    assert.equal(h.infoData, null, 'old JPEG facts must disappear before the new response');
    assert.equal(h.isInfoLoading, true);
    answer({data: {mime_type: 'image/png', file_size_human: '42.4 KB'}});
    await new Promise(resolve => setImmediate(resolve));
    assert.deepEqual(h.infoData, {mime_type: 'image/png', file_size_human: '42.4 KB'});
    assert.equal(h.isInfoLoading, false);
});

const playwrightPath = process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH;
(playwrightPath ? test : test.skip)('default confirmation sits above the viewer, owns keyboard focus, and ordinary confirmations keep their layer', async () => {
    const browser = await require('../launch-browser.cjs').launchBrowser({playwrightPath});
    try {
        const page = await browser.newPage({viewport: {width: 1280, height: 900}});
        await page.setContent('<style>.fixed{position:fixed}.inset-0{inset:0}</style><div id="app"></div>');
        await page.addScriptTag({path: process.env.MOTHERSHIP_VUE_RUNTIME_PATH || path.join(views, '../../../node_modules/vue/dist/vue.global.js')});
        const source = fs.readFileSync(path.resolve(__dirname, '../../../packages/Mothership/Admin/src/Resources/views/components/modal/confirm.blade.php'), 'utf8').replace(/\{\{--[\s\S]*?--\}\}/g, '');
        const markup = source.match(/id="v-modal-confirm-template"\s*>\s*([\s\S]*?)<\/script>/)[1].replace(/@\{\{/g, '{{');
        const script = source.slice(source.indexOf('<script type="module">') + '<script type="module">'.length, source.lastIndexOf('</script>'));
        const focus = fs.readFileSync(path.join(views, 'asset/preview-modal/focus-script.blade.php'), 'utf8').replace(/\{\{--[\s\S]*?--\}\}/g, '').replace(/<\/?script>/g, '');
        await page.evaluate(({script, markup, focus}) => {
            window.lockBodyScroll = () => {};
            window.unlockBodyScroll = () => {};
            const definitions = {};
            new Function('app', script)({component: (name, definition) => definitions[name] = definition});
            new Function(focus)();
            const app = Vue.createApp({template: '<div><div data-dam-preview-backdrop style="position:fixed;inset:0;z-index:10010;background:white"><section role="dialog"><button id="opener">Make default file</button></section></div><v-modal-confirm ref="confirm" /></div>'});
            app.component('v-modal-confirm', {...definitions['v-modal-confirm'], template: markup});
            app.config.globalProperties.$emitter = {on() {}};
            window.root = app.mount('#app');
            window.guard = {isOpen: true, ...window._damPreviewFocus.methods};
            window.guard.startPreviewFocusGuard();
            document.querySelector('#opener').focus();
            window.answers = 0;
            window.root.$refs.confirm.open({zIndex: 10020, title: 'Make default file', message: 'Use this member?', options: {btnDisagree: 'Cancel', btnAgree: 'Make default file'}, agree: () => window.answers++});
        }, {script, markup, focus});
        const dialog = page.getByRole('alertdialog');
        await dialog.waitFor();
        await page.waitForFunction(() => document.activeElement?.textContent === 'Cancel');
        assert.equal(await dialog.evaluate(el => !! document.elementFromPoint(el.getBoundingClientRect().x + el.offsetWidth / 2, el.getBoundingClientRect().y + el.offsetHeight / 2)?.closest('[role="alertdialog"]')), true);
        await page.keyboard.press('Tab');
        assert.equal(await page.evaluate(() => document.activeElement.textContent), 'Make default file');
        await page.keyboard.press('Enter');
        assert.equal(await page.evaluate(() => window.answers), 1);
        assert.equal(await page.evaluate(() => document.activeElement.id), 'opener');
        await page.evaluate(() => window.root.$refs.confirm.open({title: 'Ordinary'}));
        assert.equal(await page.evaluate(() => window.root.$refs.confirm.zIndex), 10002);
    } finally {await browser.close();}
});
(playwrightPath ? test : test.skip)('rendered modal selects 15 members, keeps reasons, applies partial batch and retries failed file only', async () => {
    const browser = await require('../launch-browser.cjs').launchBrowser({playwrightPath});
    try {
        const page = await browser.newPage({viewport: {width: 1280, height: 900}});
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.setContent('<div id="app"></div>');
        await page.addScriptTag({path: process.env.MOTHERSHIP_VUE_RUNTIME_PATH || path.join(views, '../../../node_modules/vue/dist/vue.global.js')});
        const source = fs.readFileSync(path.join(views, 'components/explorer/ai-rename-modal.blade.php'), 'utf8');
        const markup = source.match(/id="v-dam-ai-rename-modal-template">([\s\S]*?)<\/script>/)[1]
            .replace(/\{\{--[\s\S]*?--\}\}/g, '').replace(/x-admin::modal/g, 'test-modal')
            .replace(/<x-slot:(header|content)>/g, '<template v-slot:$1>').replace(/<\/x-slot>/g, '</template>')
            .replace(/@lang\('([^']+)'\)/g, (_, key) => key.split('.').pop()).replace(/@\{\{/g, '{{');
        const at = source.indexOf("app.component('v-dam-ai-rename-modal'");
        await page.evaluate(({script, markup}) => {
            const definitions = {};
            new Function('app', script)({component: (name, def) => definitions[name] = def});
            const app = Vue.createApp({template: '<v-dam-ai-rename-modal ref="modal" />'});
            app.component('test-modal', {template: '<section><slot name="header" /><slot name="content" /></section>', methods: {open() {}, close() {}}});
            app.component('v-dam-ai-rename-modal', {...definitions['v-dam-ai-rename-modal'], template: markup});
            window.calls = [];
            window.failure = true;
            app.config.globalProperties.$emitter = {on() {}, off() {}, emit() {}};
            app.config.globalProperties.$axios = {post: async (url, body) => {
                if (url.includes('.members')) return {data: {rows: Array.from({length: 16}, (_, index) => ({asset_id: index < 15 ? 1 : 2, attachment_id: index + 1, file_id: index + 101, file_name: `IMG_${index}.jpg`, extension: 'jpg', eligibility: {state: index === 15 ? 'unsupported' : 'eligible', message: 'Unsupported original'}}))}};
                if (url.includes('.suggest')) return {data: {stem: 'event', extension: 'jpg', captured_at: '2026-10-15'}};
                window.calls.push(body.attachment_id);
                if (body.attachment_id === 3 && window.failure) throw {response: {data: {message: 'Retry member'}}};
                return {data: {status: 'renamed', file_name: body.file_name}};
            }};
            window.root = app.mount('#app');
            window.modal = window.root.$refs.modal;
        }, {script: blade(source.slice(source.lastIndexOf('<script type="module">', at) + '<script type="module">'.length, source.indexOf('</script>', at))), markup});
        await page.evaluate(() => window.modal.open({assets: [{id: 1, name: 'First stack'}, {id: 2, name: 'Second stack'}]}));
        await page.waitForFunction(() => window.modal.rows.filter(row => row.state === 'ready').length === 15);
        assert.equal(await page.locator('[data-dam-ai-rename-row]').count(), 16);
        assert.match(await page.locator('[data-dam-ai-rename-skipped]').textContent(), /Unsupported original/);
        await page.evaluate(() => window.modal.setAllEligible({checked: false}));
        await page.locator('input[type=checkbox]').first().check();
        assert.equal(await page.evaluate(() => window.modal.applicable.length), 15);
        await page.locator('[data-dam-ai-rename-apply]').click();
        await page.waitForFunction(() => window.modal.rows[2].state === 'error');
        await page.evaluate(() => window.failure = false);
        await page.locator('[data-dam-ai-rename-apply]').click();
        await page.waitForFunction(() => window.modal.rows[2].state === 'renamed');
        assert.deepEqual(await page.evaluate(() => window.calls), [...Array.from({length: 15}, (_, i) => i + 1), 3]);
        assert.deepEqual(errors, []);
    } finally { await browser.close(); }
});
