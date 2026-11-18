'use strict';
const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '../../..');
const tabSource = fs.readFileSync(path.join(root, 'packages/Mothership/DAM/src/Resources/views/components/explorer/tab.blade.php'), 'utf8');
const indexSource = fs.readFileSync(path.join(root, 'packages/Mothership/DAM/src/Resources/views/components/explorer/index.blade.php'), 'utf8');
const navigation = require(path.join(root, 'packages/Mothership/DAM/src/Resources/assets/js/library-navigation.js'));
function method(source, name, globals = {}) {
    const signature = source.match(new RegExp(`^([ \\t]+)${name}\\(`, 'm'));
    assert.ok(signature, name);
    const start = signature.index, closing = `\n${signature[1]}},`;
    const end = source.indexOf(closing, start);
    const body = source.slice(start, end + closing.length - 1).replace(/@lang\('[^']+'\)/g, 'Translation').replace(/\{\{.*?\}\}/g, '/fixture');
    return new Function(...Object.keys(globals), `return ({${body}}).${name}`)(...Object.values(globals));
}
function fixture() {
    const timers = new Map(); let timerId = 0;
    const globals = {setTimeout: callback => {timers.set(++timerId, callback); return timerId;}, clearTimeout: id => timers.delete(id), localStorage: {setItem() {}}, window: {}};
    const requests = [], events = [];
    const state = {
        search: 'campus', searchInput: 'campus pending', page: 4, mediaType: 'images', scope: 'all', currentDirId: null,
        similarTo: null, similarName: '', similarityPriorView: null, sortBy: 'name', sortOrder: 'asc', sortBeforeSearch: null,
        includeSubfolders: true, groupBy: '', organization: null, applied: {filters: {columns: [{index: 'extension', value: ['png']}]}},
        smartSearch: true, loading: false, meta: {}, assets: [], dirs: [], breadcrumb: [], navHistory: [], navIdx: 0,
        $axios: {get(url, {params}) {return new Promise((resolve, reject) => requests.push({params, resolve, reject}));}},
        $emit: (name, value) => events.push({name, value}), $emitter: {emit() {}},
        trackSearchSettled() {}, restoreSelection() {}, computeSelectionMode() {}, userKey() {return null;}, clearSelection() {},
    };
    for (const name of ['onSearch', 'submitSearch', 'invalidateSearchRequests', 'enterSimilarity', 'exitSimilarity', 'resetSimilarity', 'captureListingView', 'restoreListingView', 'clearSimilar', 'syncSortToSearch', 'fetch', 'sync', 'takePendingView', 'applyPopState', 'emptyState', 'goBack', 'goForward', 'goTo', 'showAllAssets']) state[name] = method(tabSource, name, globals);
    const flushTimers = () => {const pending = [...timers.values()]; timers.clear(); pending.forEach(callback => callback());};
    return {state, requests, events, flushTimers};
}
const settle = () => new Promise(resolve => setImmediate(resolve));
const response = id => ({data: {assets: [{id}], directories: [], meta: {total_assets: 1}}});

test('similarity cancels pending debounce and rejects old in-flight success, errors and finalizers', async () => {
    const {state, requests, flushTimers} = fixture();
    state.fetch(); state.onSearch(); state.enterSimilarity({assetId: 9, name: 'Seed'});
    flushTimers();
    assert.equal(requests.length, 2); assert.equal(requests[1].params.similar_to, 9);
    requests[0].resolve(response(1)); await settle();
    assert.equal(state.assets.length, 0); assert.equal(state.loading, true); assert.equal(state.similarTo, 9);
    requests[1].resolve(response(2)); await settle();
    assert.deepEqual(state.assets, [{id: 2}]); assert.equal(state.similarTo, 9);
    state.fetch(); state.enterSimilarity({assetId: 10, name: 'Next'});
    requests[2].reject({response: {status: 404}}); await settle();
    assert.equal(state.loadError, null); assert.equal(state.loading, true); assert.equal(state.similarTo, 10);
    requests[3].resolve(response(3)); await settle();
});

test('clear similar restores original search, filters, sort and page after changing similarity seed', () => {
    const {state} = fixture();
    state.enterSimilarity({assetId: 9, name: 'Seed'});
    state.applied.filters.columns = []; state.mediaType = 'videos'; state.sortBy = 'created_at';
    state.enterSimilarity({assetId: 10, name: 'Next'}); state.clearSimilar();
    assert.equal(state.similarTo, null); assert.equal(state.search, 'campus'); assert.equal(state.searchInput, 'campus pending');
    assert.equal(state.page, 4); assert.equal(state.mediaType, 'images'); assert.equal(state.sortBy, 'name');
    assert.deepEqual(state.applied.filters.columns, [{index: 'extension', value: ['png']}]);
});

test('an unchanged restored search cannot clear similarity; an intentional new search can', () => {
    const {state, flushTimers} = fixture(); state.searchInput = state.search;
    state.enterSimilarity({assetId: 9, name: 'Seed'}); state.onSearch(); flushTimers(); state.submitSearch();
    assert.equal(state.similarTo, 9);
    state.searchInput = 'new term'; state.onSearch(); flushTimers();
    assert.equal(state.similarTo, null); assert.equal(state.search, 'new term');
});

test('Enter on an unchanged ordinary search resets the page and refetches', () => {
    const {state, requests} = fixture(); state.searchInput = state.search; state.page = 3;
    state.submitSearch();
    assert.equal(state.page, 1); assert.equal(requests.length, 1); assert.equal(requests[0].params.search, 'campus');
});

test('folder history exits and organization fetches discard the snapshot and cancel old debounce work', () => {
    for (const direction of ['goBack', 'goForward']) {
        const {state, requests, flushTimers} = fixture();
        state.navHistory = [{dirId: 10, scope: 'directory', breadcrumb: [{id: 10, name: 'First'}]}, {dirId: 20, scope: 'directory', breadcrumb: [{id: 20, name: 'Second'}]}];
        state.navIdx = direction === 'goBack' ? 1 : 0; state.canGoBack = state.canGoForward = true;
        state.enterSimilarity({assetId: 9, name: 'Seed'}); state.onSearch();
        state[direction](); flushTimers();
        assert.equal(state.similarTo, null); assert.equal(state.similarityPriorView, null);
        assert.equal(requests.length, 2, 'only the similarity and folder requests run');
        assert.equal(requests[1].params.directory_id, direction === 'goBack' ? 10 : 20);
    }
    const {state, requests, flushTimers} = fixture();
    state.enterSimilarity({assetId: 9, name: 'Seed'}); state.onSearch();
    state.similarTo = null; state.similarName = ''; state.fetch(); flushTimers();
    assert.equal(state.similarityPriorView, null); assert.equal(requests.length, 2);
});

test('only the visible addressed explorer tab enters similarity', () => {
    const start = tabSource.indexOf('this._onFindSimilar = (detail) => {');
    const end = tabSource.indexOf('\n        };', start);
    const handler = new Function(tabSource.slice(start, end + '\n        };'.length));
    const calls = [];
    const tab = {tabId: 'active', $el: {offsetParent: {}}, enterSimilarity: seed => calls.push(seed)};
    handler.call(tab);
    tab._onFindSimilar({tabId: 'other', id: 1}); tab._onFindSimilar({tabId: 'active', id: 2, name: 'Two'});
    tab.$el.offsetParent = null; tab._onFindSimilar({id: 3});
    assert.deepEqual(calls, [{assetId: 2, name: 'Two'}]);
    tab._onFindSimilar({tabId: 'active', id: 4});
    assert.equal(calls.length, 1, 'addressed but hidden tabs do not change mode');
});

test('mode persists through URL, tab storage, reload and Back/Forward, including the prior snapshot', () => {
    const {state, events} = fixture(); state.scope = 'directory'; state.currentDirId = 15;
    state.enterSimilarity({assetId: 9, name: 'Seed'});
    const snapshot = events.find(event => event.name === 'tab-state-change').value;
    let href = 'http://library.test/?scope=directory&directory_id=15&search=campus&media_type=images&page=4';
    const entries = [], previousEntries = [];
    const history = {state: null, pushState(value, unused, url) {previousEntries.push({state: this.state, href}); this.state = structuredClone(value); href = String(url); entries.push(href);}, replaceState(value, unused, url) {this.state = structuredClone(value); href = String(url);}};
    const retired = [];
    const window = new EventTarget();
    window.addEventListener('dam:preview-history-retired',event=>retired.push(event.type));
    const globals = {URL, window, CustomEvent: Event, location: {get href() {return href;}}, history, document: {querySelector() {return null;}}};
    const owner = {_locationSeeded: true};
    const reactive = value => value && typeof value === 'object' ? new Proxy(value, {get(target, key) {return reactive(target[key]);}}) : value;
    method(indexSource, 'syncLocation', globals).call(owner, reactive(snapshot));
    assert.equal(retired.length,1,'listing navigation retires retained preview ownership');
    assert.equal(entries.length, 1); assert.equal(navigation.locationState(new URL(href).search).similarity.assetId, 9);
    const reload = {makeTab: (directoryId, label) => ({directoryId, label})};
    const restored = method(indexSource, 'normalizeSavedTab').call(reload, JSON.parse(JSON.stringify(snapshot)));
    assert.equal(restored.similarity.priorView.page, 4);
    const reloadedOwner = {tabsKey: () => 'tabs', makeTab: reload.makeTab, normalizeSavedTab: method(indexSource, 'normalizeSavedTab')};
    method(indexSource, 'restore', {window: {MothershipLibraryNavigation: navigation}, location: {search: new URL(href).search}, history, localStorage: {getItem: () => null}}).call(reloadedOwner);
    assert.equal(reloadedOwner.tabs[0].similarity.priorView.page, 4, 'immediate reload restores synchronous history even before tab storage saves');
    const wanted = {...navigation.locationState(new URL(href).search), similarity: history.state.damSimilarity};
    const plan = navigation.popStatePlan(wanted, {scope: 'all', search: 'campus', mediaType: 'images', page: 1});
    state.similarTo = null; state.applyPopState(plan);
    assert.equal(state.similarTo, 9); assert.equal(state.similarityPriorView.page, 4);
    const similarityEntry = history.state;
    state.sortBy = 'created_at'; state.sortOrder = 'desc'; state.applied.filters.columns = [{index: 'extension', value: ['jpg']}];
    state.scope = 'all'; state.currentDirId = null;
    const backWanted = {...navigation.locationState(new URL(previousEntries[0].href).search), listingView: previousEntries[0].state.damLibraryView};
    const back = navigation.popStatePlan(backWanted, {...snapshot, scope: state.scope, directoryId: state.currentDirId});
    state.applyPopState(back); assert.equal(state.similarTo, null); assert.equal(state.page, 4);
    assert.equal(state.scope, 'directory'); assert.equal(state.currentDirId, 15); assert.equal(state.sortBy, 'name'); assert.equal(state.sortOrder, 'asc');
    assert.deepEqual(state.applied.filters.columns, [{index: 'extension', value: ['png']}]);
    state.sortBy = 'size'; state.applied.filters.columns = [];
    const forward = navigation.popStatePlan({...wanted, listingView: similarityEntry.damLibraryView}, {...snapshot, similarity: null});
    state.applyPopState(forward);
    assert.equal(state.similarTo, 9); assert.equal(state.sortBy, 'name');
    assert.deepEqual(state.applied.filters.columns, [{index: 'extension', value: ['png']}]);
});

test('URL similarity names are capped before popstate or tab storage consume them', () => {
    const wanted = navigation.locationState('?scope=all&similar_to=9&similar_name=' + 'x'.repeat(1000));
    assert.equal(wanted.similarity.name.length, 255);
    const {state} = fixture(); state.applyPopState(navigation.popStatePlan(wanted, {scope: 'all'}));
    assert.equal(state.similarName.length, 255);
});

test('similarity consumes the preview history entry before its deferred close watcher runs', () => {
    const {state, events} = fixture(); state.enterSimilarity({assetId: 9, name: 'Seed'});
    const snapshot = events.find(event => event.name === 'tab-state-change').value;
    let href = 'http://library.test/?scope=all&search=campus';
    let pushes = 0, backs = 0;
    const history = {state: {damPreview: true, damPreviewOwner: 7}, pushState(value, unused, url) {pushes++; this.state = structuredClone(value); href = String(url);}, replaceState(value, unused, url) {this.state = structuredClone(value); href = String(url);}, back() {backs++;}};
    const retired = [];
    const window = new EventTarget();
    window.addEventListener('dam:preview-history-retired',event=>retired.push(event.type));
    const globals = {URL, window, CustomEvent: Event, location: {get href() {return href;}}, history, document: {querySelector() {return null;}}};
    method(indexSource, 'syncLocation', globals).call({_locationSeeded: true}, snapshot);
    assert.equal(retired.length,1,'similarity replacement retires retained preview ownership');
    const gridSource = fs.readFileSync(path.join(root, 'packages/Mothership/DAM/src/Resources/views/asset/grid-preview-modal.blade.php'), 'utf8');
    // Execute the preview's real production history method after the explorer's synchronous event handler.
    const closeWatcher = method(gridSource, 'syncPreviewHistory', {history, location: globals.location});
    closeWatcher.call({_previewPushed: true, isLoading: false}, false);
    assert.equal(pushes, 0); assert.equal(backs, 0); assert.equal(history.state.damPreview, undefined); assert.equal(history.state.damPreviewOwner, undefined);
    assert.equal(new URL(href).searchParams.get('similar_to'), '9');
    history.state = {damPreview: true, damPreviewOwner: 7};
    closeWatcher.call({_previewPushed: true, _previewHistoryOwner: 7, _previewHistoryUrl: href, isLoading: false}, false);
    assert.equal(backs, 1, 'ordinary preview close still consumes its own entry');
});

test('rapid Back then Forward compares against restored tab state while the Back fetch is still pending', async () => {
    const {state, requests} = fixture();
    state.enterSimilarity({assetId: 9, name: 'Seed'});
    const similar = {similarity: {assetId: 9, name: 'Seed', priorView: state.similarityPriorView}, scope: 'all', search: 'campus', mediaType: 'images', page: 1};
    state.exitSimilarity();
    const normal = {scope: 'all', search: 'campus', mediaType: 'images', page: 4};
    const tab = {...normal};
    const parent = {tabs: [tab], activeTabId: 'active', persistTabs() {}, syncLocation() {}}; tab.id = 'active';
    parent.onStateChange = method(indexSource, 'onStateChange');
    state.$emit = (name, value) => {if (name === 'tab-state-change') parent.onStateChange('active', value);};
    state.applyPopState(navigation.popStatePlan({...similar, explicit: true}, tab));
    assert.equal(tab.similarity.assetId, 9, 'parent learns Back state before its request settles');
    const forward = navigation.popStatePlan({...normal, explicit: true}, tab);
    assert.ok(forward, 'Forward must not be dropped because the parent still holds the old normal view');
    state.applyPopState(forward);
    requests[2].resolve(response(99)); await settle();
    assert.equal(state.similarTo, null); assert.equal(tab.similarity, null); assert.equal(state.page, 4);
    assert.equal(state.assets.length, 0, 'late Back response cannot commit after Forward');
    requests[3].resolve(response(100)); await settle(); assert.deepEqual(state.assets, [{id: 100}]);
});

test('stale filter-conflict copy cannot shadow similarity results', () => {
    const {state} = fixture(); state.similarTo = 9; state.meta = {filter_conflict: {message: 'PNG and Videos conflict'}};
    assert.notEqual(state.emptyState().message, 'PNG and Videos conflict');
});
