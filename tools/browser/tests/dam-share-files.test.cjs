'use strict';

// The shared asset page's file panel (OD5): which file is on stage is decided by the DOM-less state module, so the
// rules (only an authorised id from ?file=, fallbacks, the URL it writes back) are tested here without a browser.
const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '../../..');
const statePath = path.join(root, 'packages/Mothership/DAM/src/Resources/assets/js/share-files-state.js');
const views = path.join(root, 'packages/Mothership/DAM/src/Resources/views/share/public');
const state = require(statePath);

const files = [
    {attachment_id: 11, name: 'kit.png', is_main: true, preview_kind: 'image', preview_url: '/share/t/file/11/preview', thumb_url: '/share/t/thumb/5', download_url: '/share/t/download'},
    {attachment_id: 12, name: 'clip.mp4', is_main: false, preview_kind: 'video', preview_url: '/share/t/file/12/preview', thumb_url: '/share/t/file/12/thumb', download_url: '/share/t/download/12'},
    {attachment_id: 13, name: 'chair.glb', is_main: false, preview_kind: 'none', preview_url: null, thumb_url: '/share/t/file/13/thumb', download_url: '/share/t/download/13'},
    {attachment_id: 14, name: 'brief.docx', is_main: false, preview_kind: 'none', preview_url: null, thumb_url: null, download_url: null},
];

test('opens the file the address names only when the link hands it out', () => {
    assert.equal(state.initialId(files, '?file=12'), 12);
    assert.equal(state.initialId(files, '?page=2&file=13'), 13);
    assert.equal(state.initialId(files, '?file=99'), 11, 'an id outside the list falls back to the main file');
    assert.equal(state.initialId(files, '?file=12abc'), 12, 'parsed as an integer, then checked against the list');
    assert.equal(state.initialId(files, '?file=-12'), 11);
    assert.equal(state.initialId(files, ''), 11);
    assert.equal(state.initialId([], '?file=12'), null);
});

test('stages the main viewer, a preview, or a fallback that says why there is none', () => {
    assert.equal(state.stage(files, 11, null).mode, 'main');
    assert.equal(state.stage(files, 99, null).mode, 'main', 'an unknown id keeps the main viewer');
    assert.deepEqual(state.stage([], 1, null), {mode: 'main', id: null, url: null, name: '', thumb: null, download: null, message: null});

    const clip = state.stage(files, 12, null);
    assert.equal(clip.mode, 'video');
    assert.equal(clip.url, '/share/t/file/12/preview');

    const failed = state.stage(files, 12, 12);
    assert.equal(failed.mode, 'fallback', 'a preview that failed to load falls back');
    assert.equal(failed.message, 'no-preview');
    assert.equal(failed.download, '/share/t/download/12');

    assert.equal(state.stage(files, 13, null).message, 'no-preview');
    assert.equal(state.stage(files, 14, null).message, 'downloads-off');
    assert.equal(state.stage(files, 14, null).download, null);
});

test('writes the picked file into the address and removes it for the main file', () => {
    assert.equal(state.urlFor('https://x.test/share/t?page=2#top', files, 12), '/share/t?page=2&file=12#top');
    assert.equal(state.urlFor('https://x.test/share/t?file=12', files, 11), '/share/t');
    assert.equal(state.urlFor('https://x.test/share/t?file=12', files, 99), '/share/t');
});

test('the page wires the panel through the state module with Vue-safe markup', () => {
    const asset = fs.readFileSync(path.join(views, 'asset.blade.php'), 'utf8');
    const list = fs.readFileSync(path.join(views, 'partials/files-list.blade.php'), 'utf8');

    assert.ok(asset.includes("file_get_contents(base_path('packages/Mothership/DAM/src/Resources/assets/js/share-files-state.js'))"));
    assert.ok(asset.includes("app.component('v-dam-share-files'"));
    assert.ok(asset.includes('v-slot="panel"'));
    assert.ok(list.includes('v-on:click="panel.select('));
    assert.ok(list.includes("v-pre>{{ $row['name'] }}</p>"), 'a user-set name is never compiled by Vue');
    for (const [name, source] of [['asset', asset], ['files-list', list]]) {
        assert.ok(!/\s@(click|error|keydown)=/.test(source), `${name} uses v-on:, never @`);
    }
});

test('the folder grid badges a card only when its item holds more than one file, server-rendered and on infinite scroll', () => {
    const directory = fs.readFileSync(path.join(views, 'directory.blade.php'), 'utf8');

    assert.ok(directory.includes("($fileCounts[$asset->id] ?? 1) > 1"), 'server-rendered cards badge only above one file');
    assert.ok(directory.includes('asset.file_count > 1'), 'infinite-scroll cards badge only above one file');
    assert.ok(directory.includes('${badgeHtml}${countHtml}'), 'the badge is part of the built card');
});

// A stack of web pages: every member carries its own pinned page url, slot, file id and FULL checksum.
const sums = ['a', 'b', 'c'].map(letter => letter.repeat(64));
const pages = [
    {attachment_id: 21, file_id: 101, checksum: sums[0], name: 'site.zip', is_main: true, preview_kind: 'none', preview_url: null, thumb_url: '/t/1', download_url: null, page_url: '/share/t/pf/7.21.101.' + sums[0] + '/'},
    {attachment_id: 22, file_id: 102, checksum: sums[1], name: 'second.zip', is_main: false, preview_kind: 'none', preview_url: null, thumb_url: null, download_url: null, page_url: '/share/t/pf/7.22.102.' + sums[1] + '/'},
    {attachment_id: 23, file_id: 103, checksum: sums[2], name: 'notes.txt', is_main: false, preview_kind: 'none', preview_url: null, thumb_url: null, download_url: null, page_url: null},
];

test('a pinned link selects only the file whose slot, file id and full checksum all still match, else says unavailable', () => {
    const pin = (file, id, checksum) => `?file=${file}&file_id=${id}&checksum=${checksum}`;

    assert.deepEqual(state.initialSelection(pages, pin(22, 102, sums[1])), {id: 22, pinned: true, unavailable: false});
    assert.deepEqual(state.initialSelection(pages, pin(21, 101, sums[0])), {id: 21, pinned: true, unavailable: false}, 'the representative pins too');
    // Revised (new file id), changed bytes, a prefix, uppercase, a half pin, a non-page row, an unknown slot: never another file.
    for (const search of [pin(22, 999, sums[1]), pin(22, 102, sums[2]), pin(22, 102, sums[1].slice(0, 16)), pin(22, 102, sums[1].toUpperCase()), `?file=22&file_id=102`, `?file=22&checksum=${sums[1]}`, pin(23, 103, sums[2]), pin(99, 102, sums[1]), `?file_id=102&checksum=${sums[1]}`]) {
        assert.deepEqual(state.initialSelection(pages, search), {id: null, pinned: true, unavailable: true}, search);
    }
    // An old unpinned link behaves exactly as before.
    assert.deepEqual(state.initialSelection(pages, '?file=22'), {id: 22, pinned: false, unavailable: false});
    assert.deepEqual(state.initialSelection(pages, ''), {id: 21, pinned: false, unavailable: false});
});

test('stages a member page, an explicit unavailable state, and keeps a pinned representative on its own page', () => {
    assert.deepEqual([state.stage(pages, 22, null).mode, state.stage(pages, 22, null).url], ['page', pages[1].page_url]);
    assert.equal(state.stage(pages, 21, null).mode, 'main', 'the unpinned representative stays the legacy stage');
    const pinnedMain = state.stage(pages, 21, null, {pinned: true});
    assert.deepEqual([pinnedMain.mode, pinnedMain.url, pinnedMain.id], ['page', pages[0].page_url, 21]);
    const gone = state.stage(pages, null, null, {pinned: true, unavailable: true});
    assert.deepEqual([gone.mode, gone.message, gone.id], ['unavailable', 'file-unavailable', null]);
    assert.equal(state.stage(pages, 23, null).mode, 'fallback', 'a file that is no page keeps its fallback');
});

test('writes the exact pin for a page member and the plain or legacy address otherwise', () => {
    assert.equal(state.urlFor('https://x.test/share/t', pages, 22), `/share/t?file=22&file_id=102&checksum=${sums[1]}`);
    assert.equal(state.urlFor('https://x.test/share/t?file=22&file_id=102&checksum=' + sums[1], pages, 21), '/share/t', 'the unpinned representative is the plain link');
    assert.equal(state.urlFor('https://x.test/share/t', pages, 21, true), `/share/t?file=21&file_id=101&checksum=${sums[0]}`);
    assert.equal(state.urlFor('https://x.test/share/t?file_id=1&checksum=x', pages, 23), '/share/t?file=23', 'a non-page file keeps the legacy slot address');
});

test('reads a page probe: ready, still preparing, could not be prepared, or unavailable', () => {
    assert.deepEqual([200, 204, 409, 422, 404, 401, 0, 500].map(state.pageVerdict), ['ready', 'ready', 'preparing', 'failed', 'unavailable', 'unavailable', 'unavailable', 'unavailable']);
});

test('the viewer mounts the sandboxed frame only once the pinned page answers, and never grants a download for it', () => {
    const asset = fs.readFileSync(path.join(views, 'asset.blade.php'), 'utf8');

    assert.match(asset, /panel\.stage\.mode === 'page'/);
    assert.match(asset, /v-if="panel\.stage\.pageState === 'ready'"/);
    assert.match(asset, /credentials: 'omit'/, 'the probe sends no cookie');
    assert.match(asset, /method: 'HEAD'/);
    assert.doesNotMatch(asset, /allow-same-origin/);
});

// The component's own page probe, run against the committed template script with a stubbed fetch and timer.
const vm = require('node:vm');

function shareComponent({search, answers, delays = {}}) {
    const source = fs.readFileSync(path.join(views, 'asset.blade.php'), 'utf8');
    const script = source.split("app.component('v-dam-share-files', ")[1].split('\n            });\n        </script>')[0];
    let definition;
    const calls = [];
    const window = {
        DamShareFilesState: state,
        location: {search, href: 'https://x.test/share/t' + search},
        history: {state: null, replaceState() {}},
        setTimeout: (fn) => { setImmediate(fn); return 1; },
        clearTimeout() {},
    };
    const queues = Object.fromEntries(Object.entries(answers).map(([url, list]) => [url, [...list]]));
    const fetch = async (url, options) => {
        calls.push({url, options});
        await (delays[url] ? delays[url].shift()?.promise : null);
        const list = queues[url];
        const status = list.length > 1 ? list.shift() : list[0];
        if (status === 'network') throw new Error('offline');
        return {status};
    };
    const sandbox = {app: {component: (name, value) => { definition = value; }}, window, document: {querySelectorAll: () => [], querySelector: () => null, addEventListener() {}, removeEventListener() {}}, fetch, Promise};
    vm.runInNewContext(`app.component('v-dam-share-files', ${script}\n            });`, sandbox);
    const inst = {files: pages, labels: {}, $nextTick: (fn) => fn(), ...definition.data.call({files: pages})};
    Object.entries(definition.methods).forEach(([key, value]) => { inst[key] = value.bind(inst); });
    Object.entries(definition.computed).forEach(([key, value]) => Object.defineProperty(inst, key, {get: () => value.call(inst)}));
    return {inst, calls, definition};
}

test('a pinned member page is shown only after its url answers 200, probed without credentials; 409 waits, 422 fails, 404 is unavailable', async () => {
    const url = pages[1].page_url;
    const search = `?file=22&file_id=102&checksum=${sums[1]}`;

    const ready = shareComponent({search, answers: {[url]: [409, 409, 200]}});
    await ready.inst.probe(url);
    assert.equal(ready.inst.pageState, 'ready');
    assert.deepEqual(ready.calls.map((c) => [c.options.method, c.options.credentials]), [['HEAD', 'omit'], ['HEAD', 'omit'], ['HEAD', 'omit']]);

    for (const [status, expected] of [[422, 'failed'], [404, 'unavailable'], [401, 'unavailable'], ['network', 'unavailable']]) {
        const c = shareComponent({search, answers: {[url]: [status]}});
        await c.inst.probe(url);
        assert.equal(c.inst.pageState, expected, String(status));
    }

    // A queue that is only slow is not a refusal: the bounded asks end in 'slow' (still preparing), never 'failed'.
    const stuck = shareComponent({search, answers: {[url]: [409]}});
    await stuck.inst.probe(url);
    assert.equal(stuck.calls.length, 30, 'bounded polling');
    assert.equal(stuck.inst.pageState, 'slow');
});

test('a slow page can be asked for again by the viewer only: one more bounded round, then ready, slow again, or unavailable', async () => {
    const url = pages[1].page_url;
    const search = `?file=22&file_id=102&checksum=${sums[1]}`;
    const c = shareComponent({search, answers: {[url]: [409]}});
    c.inst.labels = {'page-slow': 'Taking longer than usual', 'page-retry': 'Check again'};

    await c.inst.probe(url);
    assert.deepEqual([c.inst.pageState, c.calls.length, c.inst.panel.stage.pageMessage], ['slow', 30, 'Taking longer than usual']);
    assert.equal(typeof c.inst.panel.retry, 'function', 'the stage offers a retry');

    // Nothing asks again by itself, and a retry is only for a page that is slow.
    c.inst.pageState = 'failed';
    await c.inst.retryPage();
    assert.deepEqual([c.inst.pageState, c.calls.length], ['failed', 30], 'a refused page is not asked again');
    c.inst.pageState = 'slow';

    await c.inst.retryPage();
    assert.deepEqual([c.inst.pageState, c.calls.length], ['slow', 60], 'exactly one more round of the same bound');

    const later = shareComponent({search, answers: {[url]: [409, 409, 200]}});
    later.inst.pageState = 'slow';
    await later.inst.probe(url);
    assert.equal(later.inst.pageState, 'ready', 'a queue that caught up opens the page');
    assert.deepEqual(later.calls.map((call) => call.options.credentials), ['omit', 'omit', 'omit']);
});

test('the public stage shows the retry control only for a slow page, and mounts no frame for it', () => {
    const asset = fs.readFileSync(path.join(views, 'asset.blade.php'), 'utf8');

    assert.match(asset, /v-if="panel\.stage\.pageState === 'slow'"[^>]*data-dam-share-page-retry/);
    assert.match(asset, /'page-slow'\s*=> trans\(/);
    assert.match(asset, /'page-retry'\s*=> trans\(/);
    assert.match(asset, /v-if="panel\.stage\.pageState === 'ready'"/, 'the frame still mounts only on ready');
});

test('a strict pin that no longer matches shows unavailable and never probes or stages another file', () => {
    const c = shareComponent({search: `?file=22&file_id=999&checksum=${sums[1]}`, answers: {}});

    assert.deepEqual([c.inst.pinned, c.inst.unavailable, c.inst.selectedId], [true, true, null]);
    assert.equal(c.inst.pageUrl, null);
    assert.equal(c.inst.panel.stage.mode, 'unavailable');
    assert.equal(c.calls.length, 0);
});

test('a late probe answer for a page the viewer already left cannot repaint: A, then B, then A again, or an unmounted viewer', async () => {
    const [urlA, urlB] = [pages[0].page_url, pages[1].page_url];
    const gate = {resolve: null};
    const slow = new Promise((resolve) => { gate.resolve = resolve; });
    const c = shareComponent({search: '', answers: {[urlA]: [200], [urlB]: [200]}, delays: {[urlA]: [{promise: slow}]}});

    const first = c.inst.probe(urlA);
    await c.inst.probe(urlB);
    assert.equal(c.inst.pageState, 'ready');
    c.inst.pageState = 'checking';
    const again = c.inst.probe(urlB);
    gate.resolve();
    await Promise.all([first, again]);
    assert.equal(c.inst.pageState, 'ready', 'only the newest probe settles the state');

    const late = shareComponent({search: '', answers: {[urlA]: [200]}, delays: {[urlA]: [{promise: slow}]}});
    const pending = late.inst.probe(urlA);
    late.definition.beforeUnmount.call(late.inst);
    await pending;
    assert.equal(late.inst.pageState, 'checking', 'an unmounted viewer is never repainted');
});

test('choosing a file drops any pin and the address no longer names an exact file for the representative', () => {
    const c = shareComponent({search: `?file=22&file_id=102&checksum=${sums[1]}`, answers: {}});

    assert.deepEqual([c.inst.pinned, c.inst.selectedId, c.inst.pageUrl], [true, 22, pages[1].page_url]);
    c.inst.select(21);
    assert.deepEqual([c.inst.pinned, c.inst.unavailable, c.inst.panel.stage.mode], [false, false, 'main']);
});

// The strip under the stage (name, type, size) reads the file on stage. Rows carry the type (`extension`) and the size the page
// formats on the server (`size_label`); the state module only reads them.
const withInfo = [
    Object.assign({}, pages[0], {name: 'site.zip', extension: 'ZIP', size_label: '374.0 B'}),
    Object.assign({}, pages[1], {name: 'second.zip', extension: 'ZIP', size_label: '504.0 KB'}),
    Object.assign({}, pages[2], {name: 'notes.txt', extension: 'TXT', size_label: '22.0 B'}),
    {attachment_id: 24, file_id: 104, checksum: 'd'.repeat(64), name: 'blank', extension: '', size_label: null, is_main: false, preview_kind: 'none', preview_url: null, thumb_url: null, download_url: null, page_url: null},
];

test('the info strip reads the file on stage: its own name, type and size, including the representative and an unsupported file', () => {
    assert.deepEqual(state.info(withInfo, 22, null), {name: 'second.zip', type: 'ZIP', size: '504.0 KB'});
    assert.deepEqual(state.info(withInfo, 21, null), {name: 'site.zip', type: 'ZIP', size: '374.0 B'}, 'the representative reads its own row');
    assert.deepEqual(state.info(withInfo, 23, null), {name: 'notes.txt', type: 'TXT', size: '22.0 B'}, 'an unsupported file still names itself');
    assert.deepEqual(state.info(withInfo, 24, null), {name: 'blank', type: '', size: ''}, 'a missing type or size reads blank, never another file\'s');
    assert.deepEqual(state.info(withInfo, 99, null), state.info(withInfo, 21, null), 'an unknown id falls back to the main file, like the stage');
});

test('the info strip reads nothing when no file is on stage: a pin that no longer matches, a gone file, or an empty list', () => {
    const blank = {name: '', type: '', size: ''};

    assert.deepEqual(state.info(withInfo, 22, {unavailable: true}), blank, 'never the file the link used to name, nor the main file');
    assert.deepEqual(state.info(withInfo, null, {unavailable: true}), blank);
    assert.deepEqual(state.info([], 1, null), blank);
    assert.deepEqual(state.info(null, 1, null), blank);
});

test('the viewer\'s strip follows the selection and never keeps another file\'s values', async () => {
    const url = withInfo[1].page_url;
    const c = shareComponent({search: '', answers: {[url]: [200]}});
    c.inst.files = withInfo;
    const read = () => c.inst.panel.info;

    assert.deepEqual(read(), {name: 'site.zip', type: 'ZIP', size: '374.0 B'}, 'the representative on arrival');
    c.inst.select(22);
    assert.deepEqual(read(), {name: 'second.zip', type: 'ZIP', size: '504.0 KB'}, 'a member page');
    c.inst.select(23);
    assert.deepEqual(read(), {name: 'notes.txt', type: 'TXT', size: '22.0 B'}, 'an unsupported file');
    c.inst.select(21);
    assert.deepEqual(read(), {name: 'site.zip', type: 'ZIP', size: '374.0 B'}, 'back to the representative');

    // A member that turns out to be gone (revised, moved, removed after the page loaded) is a probe answer of 404: the strip goes blank.
    const gone = shareComponent({search: '', answers: {[url]: [404]}});
    gone.inst.files = withInfo;
    gone.inst.select(22);
    assert.deepEqual(gone.inst.panel.info, {name: 'second.zip', type: 'ZIP', size: '504.0 KB'}, 'still its own file while the answer is pending');
    await gone.inst.probe(url);
    assert.equal(gone.inst.pageState, 'unavailable');
    assert.deepEqual(gone.inst.panel.info, {name: '', type: '', size: ''}, 'nothing of the representative or the earlier file stands in for it');
    gone.inst.select(21);
    assert.equal(gone.inst.panel.info.name, 'site.zip', 'choosing another file reads that file again');

    // A pinned link that no longer matches reads blank from the start.
    const stale = shareComponent({search: `?file=22&file_id=999&checksum=${sums[1]}`, answers: {}});
    stale.inst.files = withInfo;
    assert.deepEqual(stale.inst.panel.info, {name: '', type: '', size: ''});
});

test('the public template wires the strip to the selection and keeps the phone stage clear of the Files strip', () => {
    const asset = fs.readFileSync(path.join(views, 'asset.blade.php'), 'utf8');

    for (const hook of ['data-dam-share-info-name', 'data-dam-share-info-type', 'data-dam-share-info-size']) assert.ok(asset.includes(hook), hook);
    assert.match(asset, /v-text="panel\.info\.name \|\| '—'"/);
    assert.match(asset, /v-text="panel\.info\.type \|\| '—'"/);
    assert.match(asset, /v-text="panel\.info\.size \|\| '—'"/);
    assert.doesNotMatch(asset, /panel\.info\.\w+[^"]*v-pre|v-pre[^>]*panel\.info/, 'user text is set as text, never compiled');
    assert.ok(asset.includes("v-pre>{{ $asset->file_name }}</dd>"), 'a single file keeps its server-rendered, v-pre name');
    assert.ok(asset.includes("'size_label' =>"), 'each row carries the size the Files list also formats');

    // On a phone the pages' inline 70vh floor must not spill past the 60dvh stage and cover the Files chip below it.
    const phone = asset.split('@media (max-width: 639px) {')[1].split('\n                }\n')[0];
    assert.match(phone, /\.dam-share-stage\s*\{\s*flex: none;\s*height: 60dvh;\s*\}/);
    assert.match(phone, /\.dam-share-stage iframe\s*\{\s*min-height: 0 !important;\s*\}/);
    assert.equal((asset.match(/style="border: 0; min-height: 70vh;"/g) || []).length, 2, 'both page frames keep their desktop floor');
    assert.doesNotMatch(asset, /allow-same-origin/);
    assert.equal((asset.match(/sandbox="\{\{ \\Mothership\\DAM\\Services\\WebPagePolicy::SANDBOX \}\}"/g) || []).length, 2, 'every page frame carries exactly the policy sandbox');
});
