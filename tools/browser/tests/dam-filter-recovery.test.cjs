'use strict';
const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '../../..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');
const base = 'packages/Mothership/DAM/src/Resources/views/components/';
const source = read(base + 'explorer/tab.blade.php');

// Execute the production methods without mounting unrelated Library components.
function method(name) {
    const start = source.indexOf(`        ${name}(`);
    assert.ok(start >= 0, `${name} is present`);
    const next = source.indexOf('\n        },', start);
    const body = source.slice(start, next + '\n        }'.length)
        .replace(/@lang\('([^']+)'\)/g, (_, key) => key)
        .replace(/@json\(trans\('[^']+'\)\)/g, "'Selected :count'")
        .replace(/\{\{.*?\}\}/g, '/fixture');
    return new Function(`return ({${body}}).${name}`)();
}

function explorer() {
    const vm = {
        available: {columns: [
            {index: 'extension', label: 'Extension', type: 'string'},
            {index: 'tag', label: 'Tags', type: 'string'},
        ]}, applied: {filters: {columns: []}}, filterDrafts: {},
        mediaType: 'images', search: 'campus', searchInput: 'campus', page: 3,
        mediaTypeLabels: {all: 'All', images: 'Images', videos: 'Videos', svgs: 'SVGs', audio: 'Audio', models: '3D'},
        scope: 'all', currentDirId: null, similarTo: null, loading: false, meta: {}, dirs: [],
        phoneFiltersOpen: false, closed: 0, cleared: 0, params: null,
        trackFilter() {}, clearSelection() { this.cleared++; },
        $refs: {explorerFilterDrawer: {close() { vm.closed++; }}},
        $axios: {get(url, {params}) { vm.params = params; return {then() { return {catch() { return {finally() {}}; }}; }}; }},
    };
    for (const name of ['findAppliedColumn', 'applyFilter', 'commitFilterDrafts', 'runFilters', 'removeAppliedColumnValue', 'removeActiveFilter', 'activeFilterChips', 'visibleFilterConflict', 'filterLabel', 'appliedValuesSummary', 'fetch', 'emptyState']) vm[name] = method(name);
    return vm;
}

test('typing png then Apply sends the extension without Enter', () => {
    const vm = explorer();
    vm.filterDrafts.extension = ' png ';
    vm.runFilters();
    assert.equal(vm.params.filter_extension, 'png');
    assert.equal(vm.params.search, 'campus');
    assert.equal(vm.params.media_type, 'images');
    assert.equal(vm.closed, 1);
});

test('Enter followed by Apply does not duplicate; blank drafts are a no-op', () => {
    const vm = explorer();
    vm.filterDrafts.extension = 'png';
    vm.commitFilterDrafts();
    vm.runFilters();
    assert.deepEqual(vm.applied.filters.columns[0].value, ['png']);
    vm.filterDrafts.tag = '  ';
    vm.runFilters();
    assert.equal(vm.applied.filters.columns.length, 1);
});

test('one action removes a named constraint and preserves search, media and other filters', () => {
    const vm = explorer();
    vm.filterDrafts = {extension: 'png', tag: 'Fall'};
    vm.commitFilterDrafts();
    const chips = vm.activeFilterChips();
    assert.deepEqual(chips.map(c => c.label), ['Images', 'Extension: png', 'Tags: Fall']);
    vm.removeActiveFilter(chips.find(c => c.index === 'extension'));
    assert.equal(vm.params.filter_extension, undefined);
    assert.equal(vm.params.filter_tag, 'Fall');
    assert.equal(vm.params.search, 'campus');
    assert.equal(vm.params.media_type, 'images');
    assert.equal(vm.phoneFiltersOpen, false);
    assert.equal(vm.page, 1);
    assert.equal(vm.closed, 1);
});

test('removing media keeps extension and search; conflicts take priority over search advice', () => {
    const vm = explorer();
    vm.mediaType = 'videos';
    vm.filterDrafts.extension = 'png';
    vm.commitFilterDrafts();
    vm.meta.filter_conflict = {filters: ['media_type', 'extension'], message: 'PNG files cannot match Videos.'};
    assert.match(vm.emptyState().message, /no-files.filters/);
    assert.equal(vm.visibleFilterConflict().message, vm.meta.filter_conflict.message);
    assert.equal(vm.emptyState().action, null);
    vm.removeActiveFilter(vm.activeFilterChips()[0]);
    assert.equal(vm.params.media_type, 'all');
    assert.equal(vm.params.filter_extension, 'png');
    assert.equal(vm.params.search, 'campus');
    vm.loading = false;
    vm.meta = {filter_conflict: null};
    assert.match(vm.emptyState().message, /no-files.search/);
});

test('removing an applied chip retains uncommitted drafts, including its own column', () => {
    const vm = explorer();
    vm.filterDrafts.extension = 'png';
    vm.commitFilterDrafts();
    vm.filterDrafts = {extension: 'jpg', tag: 'Fall'};
    vm.removeActiveFilter(vm.activeFilterChips().find(c => c.index === 'extension'));
    assert.deepEqual(vm.filterDrafts, {extension: 'jpg', tag: 'Fall'});
    assert.equal(vm.params.filter_extension, undefined);
    assert.equal(vm.params.filter_tag, undefined);
    vm.runFilters();
    assert.equal(vm.params.filter_extension, 'jpg');
    assert.equal(vm.params.filter_tag, 'Fall');
});

test('removing a date-range chip removes both bounds and retains the other constraints', () => {
    const vm = explorer();
    const column = {index: 'created_at', label: 'Created at', type: 'date_range'};
    vm.available.columns.push(column);
    vm.applied.filters.columns.push({...column, value: [['2026-10-01', '2026-10-15']]});
    vm.filterDrafts.tag = 'Fall';
    vm.commitFilterDrafts();
    const chip = vm.activeFilterChips().find(c => c.index === 'created_at');
    assert.equal(chip.label, 'Created at: 2026-10-01 – 2026-10-15');
    vm.removeActiveFilter(chip);
    assert.equal(vm.params.filter_created_from, undefined);
    assert.equal(vm.params.filter_created_to, undefined);
    assert.equal(vm.params.filter_tag, 'Fall');
    assert.equal(vm.params.media_type, 'images');
    assert.equal(vm.params.search, 'campus');
});

test('draft and persistent removal controls are connected outside the drawer', () => {
    const filters = read(base + 'datagrid/filters.blade.php');
    const toolbar = read(base + 'explorer/toolbar.blade.php');
    assert.match(filters, /v-model="filterDrafts\[column.index\]"/);
    assert.match(filters, /@keyup.enter="commitFilterDrafts\(\)"/);
    assert.ok(toolbar.indexOf('data-active-filters') > toolbar.indexOf('</x-admin::drawer>'));
    assert.match(toolbar, /@click="removeActiveFilter\(chip\)"/);
    assert.match(toolbar, /filter-recovery.remove.*replace\(':filter', \(\) => chip.label\)/);
    const controller = read('packages/Mothership/DAM/src/Http/Controllers/Explorer/ExplorerDataController.php');
    assert.match(controller, /'filter_conflict'\s+=> LibraryMediaFilter::conflict\(mediaType: \$mediaType, extension: \$filterExtension\)/);
});

test('conflict guidance remains visible with folder tiles and never leaks into similarity or inaccessible views', () => {
    const vm = explorer();
    vm.dirs = [{id: 1, name: 'Subfolder'}];
    vm.meta = {filter_conflict: {message: 'PNG files cannot match Videos.'}};
    assert.equal(vm.visibleFilterConflict().message, vm.meta.filter_conflict.message);
    const toolbar = read(base + 'explorer/toolbar.blade.php');
    assert.match(toolbar, /<p v-if="visibleFilterConflict\(\)"[^>]*data-filter-conflict[^>]*v-text="visibleFilterConflict\(\).message"/);
    assert.ok(toolbar.indexOf('data-filter-conflict') > toolbar.indexOf('</x-admin::drawer>'));
    vm.similarTo = 10;
    assert.equal(vm.visibleFilterConflict(), null);
    assert.notEqual(vm.emptyState().message, vm.meta.filter_conflict.message);
    vm.similarTo = null;
    vm.loading = true;
    assert.equal(vm.visibleFilterConflict(), null);
    vm.loading = false;
    vm.scope = 'directory';
    vm.currentDirId = 1;
    vm.meta.can_access_current = false;
    assert.equal(vm.visibleFilterConflict(), null);
});

test('toolbar is the single conflict announcement and guards errors, missing metadata and access bypass', () => {
    const vm = explorer();
    vm.assets = [];
    vm.dirs = [];
    const conflict = {message: 'PNG files cannot match Videos.'};
    vm.meta = {filter_conflict: conflict};
    assert.equal(vm.visibleFilterConflict(), conflict);
    assert.notEqual(vm.emptyState().message, conflict.message);
    assert.match(vm.emptyState().message, /no-files.filters/);
    assert.equal(vm.emptyState().action, null);
    vm.loadError = {message: 'Request failed'};
    assert.equal(vm.visibleFilterConflict(), null);
    vm.loadError = null;
    vm.meta = null;
    assert.equal(vm.visibleFilterConflict(), null);
    vm.meta = {filter_conflict: conflict};
    vm.scope = 'directory';
    vm.currentDirId = 1;
    assert.equal(vm.visibleFilterConflict(), conflict, 'unspecified access does not hide the conflict');
    vm.meta.can_access_current = true;
    assert.equal(vm.visibleFilterConflict(), conflict);
    vm.meta.can_access_current = false;
    vm.aclBypass = true;
    assert.equal(vm.visibleFilterConflict(), conflict, 'access bypass keeps guidance visible');
});
