'use strict';

// Saving a rename dialog with the name the file already has is answered 200 `unchanged` (it used to be a 404 with
// "This name is already exist"). The three rename dialogs must treat that as a no-op: close, no flash, no reload.
const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const views = path.resolve(__dirname, '../../..', 'packages/Mothership/DAM/src/Resources/views');

function method(file, name, scope = {}) {
    const source = fs.readFileSync(path.join(views, file), 'utf8');
    const start = new RegExp(`\\n {8,24}(?:async )?${name}\\(`).exec(source);
    assert.ok(start, `${name}() not found in ${file}`);
    const balanced = (from, opener, closer) => {
        let depth = 0;
        for (let i = from; i < source.length; i++) {
            if (source[i] === opener) depth++;
            if (source[i] === closer && --depth === 0) return i;
        }
        throw new Error('unbalanced');
    };
    const argsEnd = balanced(start.index + start[0].length - 1, '(', ')');
    const end = balanced(source.indexOf('{', argsEnd), '{', '}');
    const text = source.slice(start.index + 1, end + 1).replace(/\{\{[\s\S]*?\}\}/g, 'x').replace(/@lang\('[^']+'\)/g, "''");

    return new Function(...Object.keys(scope), `return ({ ${text} })['${name}'];`)(...Object.values(scope));
}

const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

function harness({code}) {
    const calls = {emitted: [], navigated: [], closed: 0, reset: 0, dialogClosed: 0, fetched: 0};
    const host = {
        isRenaming: false, dialog: {loading: false, extra: {asset: {id: 4}}}, $refs: {assetRenameForm: {}, assetRenameModal: {close: () => calls.closed++}},
        $emitter: {emit: (...args) => calls.emitted.push(args)}, $navigate: (url) => calls.navigated.push(url),
        $axios: {post: async () => ({data: {code, message: code === 'unchanged' ? 'Name unchanged' : 'Renamed'}})},
        assertCoverShown: () => true, closeDialog() { calls.dialogClosed++; }, fetch() { calls.fetched++; }, loadDirectories() {},
    };
    const scope = {FormData: function () {}, window: {location: {href: '/details'}}, globalThis: {}};

    return {calls, host, scope, actions: {resetForm: () => calls.reset++, setErrors() {}}};
}

for (const [file, name, call] of [
    ['asset/edit.blade.php', 'renameAsset', (h, fn) => fn.call(h.host, {}, h.actions)],
    ['components/tree/damdirectories.blade.php', 'renameAsset', (h, fn) => fn.call(h.host, {}, h.actions)],
    ['components/explorer/tab.blade.php', 'onDialogSubmit', (h, fn) => fn.call(h.host, 'same-name.jpg')],
]) {
    test(`${file} ${name}: an unchanged name closes quietly; a real rename still announces and refreshes`, async () => {
        const unchanged = harness({code: 'unchanged'});
        call(unchanged, method(file, name, unchanged.scope));
        await settle();
        assert.deepEqual(unchanged.calls.emitted, [], 'something was announced for a rename that changed nothing');
        assert.deepEqual(unchanged.calls.navigated, [], 'the page reloaded');
        assert.equal(unchanged.calls.fetched, 0, 'the list was refetched');
        assert.equal(unchanged.calls.closed + unchanged.calls.dialogClosed, 1, 'the dialog did not close');

        const renamed = harness({code: undefined});
        call(renamed, method(file, name, renamed.scope));
        await settle();
        assert.equal(renamed.calls.emitted.some(([type, flash]) => type === 'add-flash' && flash.type === 'success'), true, 'a real rename lost its confirmation');
    });
}

test('batch member apply treats status unchanged as a completed no-op on retry', async () => {
    let calls = 0;
    const row = {id: 4, attachmentId: 7, fileId: 9, name: 'same-name', ext: 'jpg', checked: true, state: 'ready'};
    const host = {
        run: 1, rows: [row], setTitle: true, labels: {summary: ':renamed :unchanged :titled :failed :skipped'}, blockedCount: 0,
        urls: {applyMember: '/members/apply'}, $axios: {post: async () => {calls++; return {data: {status: 'unchanged', file_name: 'same-name.jpg'}};}},
        $emitter: {emit() {}}, close() {},
    };
    Object.defineProperty(host, 'applicable', {get: () => host.rows.filter(row => row.checked && row.state === 'ready')});
    const apply = method('components/explorer/ai-rename-modal.blade.php', 'apply');
    await apply.call(host);
    await apply.call(host);
    assert.equal(calls, 1);
    assert.equal(row.unchanged, true);
    assert.equal(row.outcome, 'unchanged');
});
