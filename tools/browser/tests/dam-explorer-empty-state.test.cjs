'use strict';

// The Library's empty-folder message (emptyState in explorer/tab.blade.php, the real function): a folder the viewer
// can only see on the way to a granted one is told "You don't have access", not "This folder is empty".
const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const source = fs.readFileSync(path.resolve(__dirname, '../../..', 'packages/Mothership/DAM/src/Resources/views/components/explorer/tab.blade.php'), 'utf8');
const WORDS = {
    'dam::app.admin.explorer.no-files.similar': 'No similar files found.',
    'dam::app.admin.explorer.no-files.no-access': "You don't have access to this folder.",
    'dam::app.admin.explorer.no-files.folder': 'This folder is empty.',
    'dam::app.admin.explorer.no-files.folders-only': 'Only subfolders.',
    'dam::app.admin.explorer.no-files.filters': 'Filters.',
    'dam::app.admin.explorer.no-files.search': 'No match for :query.',
    'dam::app.admin.explorer.no-files.clear-search': 'Clear',
};

function emptyState() {
    const start = source.indexOf('        emptyState() {');
    assert.notEqual(start, -1, 'emptyState() not found');
    let depth = 0, end = source.indexOf('{', start);
    for (let i = end; i < source.length; i++) {
        if (source[i] === '{') depth++;
        if (source[i] === '}' && --depth === 0) { end = i + 1; break; }
    }
    const body = source.slice(start, end).replace(/@lang\('([^']+)'\)/g, (_, key) => WORDS[key] ?? key);

    return new Function(`return ({ ${body} });`)().emptyState;
}

const viewer = (overrides = {}) => ({
    loading: false, meta: {can_access_current: true}, search: '', similarTo: null, scope: 'folder', currentDirId: 5, aclBypass: false,
    applied: {filters: {columns: []}}, dirs: [], ...overrides,
});
const message = (overrides) => emptyState().call(viewer(overrides))?.message;

test('a folder the viewer cannot open says so, whatever else is on screen', () => {
    assert.equal(message({meta: {can_access_current: false}}), "You don't have access to this folder.");
    assert.equal(message({meta: {can_access_current: false}, dirs: [{id: 6}]}), "You don't have access to this folder.");
    assert.equal(message({meta: {can_access_current: false}, search: 'logo'}), "You don't have access to this folder.");
});

test('an empty folder the viewer can open, and every view that is not one folder, keep their own messages', () => {
    assert.equal(message({}), 'This folder is empty.');
    assert.equal(message({dirs: [{id: 6}]}), 'Only subfolders.');
    assert.equal(message({meta: {}}), 'This folder is empty.', 'an answer without the flag must not read as no access');
    assert.equal(message({meta: {can_access_current: false}, aclBypass: true}), 'This folder is empty.');
    assert.equal(message({meta: {can_access_current: false}, scope: 'all', currentDirId: null}), 'This folder is empty.');
    assert.notEqual(message({meta: {can_access_current: false}, similarTo: 9, search: 'x'}), "You don't have access to this folder.", 'a Similar-to list is not a folder');
});

test('an empty similarity result describes the results and suppresses stale folder, search and conflict advice', () => {
    assert.equal(message({similarTo: 9}), 'No similar files found.');
    assert.equal(message({similarTo: 9, search: 'old words', meta: {can_access_current: false, filter_conflict: {message: 'Stale conflict'}}, applied: {filters: {columns: [{index: 'extension', value: ['png']}]}}}), 'No similar files found.');
});
