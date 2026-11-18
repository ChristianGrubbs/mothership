const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const root = path.resolve(__dirname, '../../..');
const panelPath = path.join(root, 'packages/Mothership/DAM/src/Resources/views/components/asset/drop-upload.blade.php');
const indexPath = path.join(root, 'packages/Mothership/DAM/src/Resources/views/asset/index.blade.php');
const panel = fs.readFileSync(panelPath, 'utf8');
const index = fs.readFileSync(indexPath, 'utf8');

test('all picker, drag/drop, and folder files converge on the chunk upload queue', () => {
    assert.match(index, /onFileChange[\s\S]*?enqueueUpload\(/);
    assert.doesNotMatch(index, /handleFileUpload\s*\(/);
    assert.match(panel, /window\.loadDamChunkUploader\(\)/);
    assert.match(panel, /client\.initialize\(/);
    assert.match(panel, /client\.uploadMissing\(/);
    assert.match(panel, /client\.complete\(/);
    assert.doesNotMatch(panel, /multipart\/form-data/);
});

test('the resumable snapshot stores source identity and server progress but never blobs', () => {
    assert.doesNotMatch(panel, /indexedDB|DAM_RESUME_MAX_BYTES|damUploadStore|canStashBatch/);
    assert.match(panel, /uploadId:\s*j\.uploadId/);
    assert.match(panel, /fingerprint:\s*j\.fingerprint/);
    assert.match(panel, /lastModified:\s*j\.lastModified/);
    assert.match(panel, /receivedBytes:\s*j\.receivedBytes/);
    assert.match(panel, /expiresAt:\s*j\.expiresAt/);
    assert.match(panel, /j\.status\s*=\s*'interrupted'/);
});

test('pause, resume, retry, cancel, and stable error-code paths are explicit', () => {
    assert.match(panel, /job\._abort\.abort\(\)/);
    assert.match(panel, /client\.status\(\{\s*uploadId:/);
    assert.match(panel, /client\.cancel\(\{\s*uploadId:/);

    for (const code of [
        'file_too_large',
        'upload_expired',
        'source_mismatch',
        'chunk_checksum_mismatch',
        'chunk_storage_failed',
        'directory_forbidden',
    ]) {
        assert.match(panel, new RegExp(`${code}:`));
    }
});

test('the actual panel drives initialize, missing chunks, and completion with accepted-byte progress', async () => {
    const calls = [];
    const storage = {};
    const { definition, window } = loadPanel({ calls, storage });
    const state = mountState(definition, storage);
    const file = new File([Buffer.from('abcdefghijkl')], 'master.mp4', {
        type: 'video/mp4',
        lastModified: 1_788_864_000_000,
    });

    await state.startSession({
        items: [{ file, relativePath: 'folder/master.mp4', preserveRoot: true }],
        folderPaths: [],
        targetDirId: 7,
    });
    state.persistNow();

    assert.deepEqual(calls.filter(call => call.client).map(call => call.method), [
        'fingerprint',
        'initialize',
        'uploadMissing',
        'complete',
    ]);
    assert.equal(state.sessions[0].jobs[0].progress, 100);
    assert.deepEqual(JSON.parse(JSON.stringify(state.sessions[0].uploadedAssetIds)), [44]);
    assert.equal(window.progress.includes(50), true);
    assert.equal(window.progress.includes(100), false, 'accepted bytes must not mark completion before finalization');
    assert.equal(JSON.stringify(JSON.parse(storage['dam_upload_state:7'])).includes('blob'), false);
    assert.equal(storage.dam_upload_state, undefined);
});

// Upload as a stack by drag (2026-10-09): only the bottom quarter of the drop area stacks, only for two or
// more loose files, and a refused stack drop uploads nothing rather than falling back to a plain upload.
test('a drop in the bottom quarter stacks 2+ loose files; the rest uploads normally and refusals upload nothing', async () => {
    const { definition } = loadPanel({ calls: [], storage: {} });
    const state = mountState(definition, {});
    Object.assign(state, {
        canUpload: true,
        currentDirectory: { id: 7 },
        stackContext: 'explorer:tab-1',
        // Visible drop area 100..500, so the stack band starts at y = 400.
        $el: { getBoundingClientRect: () => ({ top: 100, bottom: 500, left: 0, width: 400 }) },
    });
    for (const [name, getter] of Object.entries(definition.computed)) {
        Object.defineProperty(state, name, { get: getter.bind(state), configurable: true });
    }
    const enqueued = [];
    const flashes = [];
    state.enqueueUpload = payload => enqueued.push(payload);
    state.$emitter = { emit: (_event, payload) => flashes.push(payload.message), on() {}, off() {} };
    const item = (name, isDirectory = false) => ({
        kind: 'file',
        getAsFile: () => (isDirectory ? null : new File(['x'], name)),
        webkitGetAsEntry: () => ({ isDirectory }),
    });
    const dropAt = async (items, clientY) => {
        const event = { dataTransfer: { types: ['Files'], items }, clientY };
        state.onDragEnter(event);
        state.onDragOver(event);
        const hot = state.stackZoneHot;
        await state.onDrop(event);
        return hot;
    };

    assert.equal(await dropAt([item('a.png'), item('b.png')], 450), true, 'the bottom quarter is the stack band');
    assert.equal(enqueued.at(-1).stackContext, 'explorer:tab-1');
    assert.equal(enqueued.at(-1).items.map(i => i.relativePath).join(','), 'a.png,b.png');

    assert.equal(await dropAt([item('a.png'), item('b.png')], 250), false);
    assert.equal(enqueued.at(-1).stackContext, undefined, 'the top three quarters upload normally');

    assert.equal(await dropAt([item('solo.png')], 450), false, 'one file never offers a stack');
    assert.equal(enqueued.at(-1).stackContext, undefined);

    const before = enqueued.length;
    assert.equal(await dropAt([item('a.png'), item('Folder', true)], 450), true);
    assert.equal(enqueued.length, before, 'a folder in a stack drop uploads nothing');
    assert.equal(flashes.length, 1);
    assert.equal(state.isDragOver, false);

    // A short visible area (100..200) gets no band, so nothing below it can be caught as a stack.
    state.$el = { getBoundingClientRect: () => ({ top: 100, bottom: 200, left: 0, width: 400 }) };
    assert.equal(await dropAt([item('a.png'), item('b.png')], 190), false, 'a short drop area never stacks');
    assert.equal(enqueued.at(-1).stackContext, undefined);
});

// Upload as a stack (A1, M1): the card is the way back to an open review, and the review is the completion signal.
function stackHarness({ stackContext = 'explorer:tab-1', dialog = true } = {}) {
    const timers = fakeTimers();
    const { definition, window } = loadPanel({ calls: [], storage: {}, timers });
    // The merge dialog announces itself when it mounts; pages without it never set this.
    if (dialog) window.damMergeDialogReady = true;
    const state = mountState(definition, {});
    const events = [];
    state.canStack = true;
    state.$emitter = { emit: (event, payload) => events.push({ event, ...payload }), on() {}, off() {} };
    const session = {
        id: 1,
        uuid: 'session-1',
        targetDirId: 7,
        minimized: true,
        paused: false,
        cancelled: false,
        resumable: false,
        folderPaths: [],
        jobs: [],
        doneCount: 2,
        errorCount: 0,
        queuedCount: 0,
        uploadingCount: 0,
        bytesTotal: 2,
        bytesDone: 2,
        overall: 100,
        uploadedFiles: [
            { id: 1, name: 'a.png', order: 0, thumb: null },
            { id: 2, name: 'b.png', order: 1, thumb: null },
        ],
    };
    if (stackContext) session.stackContext = stackContext;
    state.activeSessions = [session];

    return { state, session, events, timers };
}

const uploadCompleteFlashes = events => events.filter(e => e.event === 'add-flash' && e.type === 'success' && e.message === 'Upload complete');

test('a stack review that is still open keeps its card past the dismiss window and then reports a cancelled review', async () => {
    const { state, events, timers } = stackHarness();

    await state.finishSession(state.activeSessions[0]);
    assert.equal(events.filter(e => e.event === 'dam:open-merge-dialog').length, 1);
    assert.equal(state.sessions[0].stackState, 'pending');

    timers.advance(6000 * 5);
    assert.equal(state.sessions.length, 1, 'an open review must not lose its card to the dismiss timer');

    state.onMergeDialogClosed({ reviewId: 'upload:session-1', merged: false });
    const warning = events.find(e => e.event === 'add-flash' && e.type === 'warning');
    assert.equal(warning.message, 'Not stacked 2');
    assert.equal(state.sessions[0].stackState, 'declined');
    assert.equal(state.sessions.length, 1);

    timers.advance(6000);
    assert.equal(state.sessions.length, 0, 'once the review is closed the normal dismiss timer applies again');
});

test('closing a panel-opened review as merged after the dismiss window refreshes the uploads', async () => {
    const { state, events, timers } = stackHarness({ stackContext: null });
    state.activeSessions[0].stackContext = undefined;

    // The panel's own "stack these" button opens the review for a finished card.
    await state.finishSession(state.activeSessions[0]);
    assert.equal(state.sessions[0].stackState, undefined, 'a plain upload does not open a review by itself');
    state.offerStack(state.sessions[0]);
    assert.equal(state.sessions[0].stackState, 'pending');
    const opened = events.find(e => e.event === 'dam:open-merge-dialog');
    assert.equal(opened.reviewId, 'upload:session-1');

    timers.advance(6000 * 5);
    assert.equal(state.sessions.length, 1, 'the open review keeps the card it was opened from');
    const refreshes = () => events.filter(e => e.event === 'dam:uploads-refresh').length;
    const before = refreshes();
    state.onMergeDialogClosed({ reviewId: opened.reviewId, merged: true });

    assert.equal(state.sessions[0].stackState, 'done');
    assert.equal(refreshes(), before + 1, 'no tab started this review, so the panel refreshes the grid itself');
});

test('the stack review is offered one entry per asset id', async () => {
    const { state, events } = stackHarness({ stackContext: null });
    state.activeSessions[0].stackContext = undefined;
    state.activeSessions[0].uploadedFiles.push({ id: 2, name: 'b.png', order: 2, thumb: null });

    await state.finishSession(state.activeSessions[0]);
    state.offerStack(state.sessions[0]);

    const opened = events.find(e => e.event === 'dam:open-merge-dialog');
    assert.deepEqual(Array.from(opened.assets, asset => asset.id), [1, 2]);
});

test('only a finish that opens the stack review skips the upload-complete flash', async () => {
    const stacked = stackHarness();
    await stacked.state.finishSession(stacked.state.activeSessions[0]);
    assert.equal(stacked.events.filter(e => e.event === 'dam:open-merge-dialog').length, 1);
    assert.equal(uploadCompleteFlashes(stacked.events).length, 0, 'the review is the completion signal');

    const plain = stackHarness({ stackContext: null });
    await plain.state.finishSession(plain.state.activeSessions[0]);
    assert.equal(uploadCompleteFlashes(plain.events).length, 1, 'a plain upload still says it is complete');

    // A stack set that cannot open a review (one file) still gets the receipt.
    const single = stackHarness();
    single.state.activeSessions[0].uploadedFiles.pop();
    await single.state.finishSession(single.state.activeSessions[0]);
    assert.equal(single.events.filter(e => e.event === 'dam:open-merge-dialog').length, 0);
    assert.equal(uploadCompleteFlashes(single.events).length, 1);
});

test('without a merge dialog on the page an Upload-as-a-stack card is a plain upload that still dismisses', async () => {
    const { state, session, events, timers } = stackHarness({ dialog: false });

    await state.finishSession(session);
    assert.equal(events.filter(e => e.event === 'dam:open-merge-dialog').length, 0, 'nothing is listening, so no review is requested');
    assert.notEqual(state.sessions[0].stackState, 'pending');
    assert.equal(uploadCompleteFlashes(events).length, 1, 'the receipt is still shown');

    timers.advance(6000);
    assert.equal(state.sessions.length, 0, 'the card dismisses on the normal timer');
});

test('restored jobs stay interrupted with resumable metadata and no source bytes', () => {
    const storage = {};
    const { definition } = loadPanel({ calls: [], storage });
    const state = mountState(definition, storage);
    state.activeSessions = [{
        id: 1,
        uuid: 'session-1',
        targetDirId: 7,
        minimized: false,
        resumable: true,
        paused: false,
        folderPaths: [],
        doneCount: 0,
        errorCount: 0,
        bytesTotal: 12,
        bytesDone: 0,
        overall: 50,
        jobs: [{
            id: 1,
            name: 'master.mp4',
            parentPath: '',
            relativePath: 'master.mp4',
            fileSize: 12,
            isFolder: false,
            preserveRoot: false,
            mimeType: 'video/mp4',
            lastModified: 1_788_864_000_000,
            fingerprint: 'a'.repeat(64),
            uploadId: 'upload-1',
            receivedBytes: 6,
            expiresAt: '2026-09-09T00:00:00Z',
            status: 'uploading',
            phase: 'receiving',
            progress: 50,
            error: null,
        }],
    }];
    state.persistNow();

    const snapshot = JSON.parse(storage['dam_upload_state:7']);
    assert.equal(JSON.stringify(snapshot).includes('blob'), false);
    assert.equal(snapshot.activeSessions[0].jobs[0].uploadId, 'upload-1');
    assert.equal(snapshot.activeSessions[0].jobs[0].fingerprint.length, 64);

    storage.dam_upload_state = 'stale legacy state';
    const restored = mountState(definition, storage);
    restored.restoreState();
    assert.equal(storage.dam_upload_state, undefined);
    assert.equal(restored.activeSessions[0].jobs[0].status, 'interrupted');
    assert.equal(restored.activeSessions[0].jobs[0].receivedBytes, 6);
});

test('a session stays active while restored files still await reselection', async () => {
    const storage = {};
    const { definition } = loadPanel({ calls: [], storage });
    const state = mountState(definition, storage);
    const session = {
        id: 1,
        uuid: 'session-1',
        targetDirId: 7,
        minimized: false,
        paused: false,
        cancelled: false,
        jobs: [state.makeJob({ id: 1, name: 'master.mp4', status: 'interrupted' })],
        doneCount: 0,
        errorCount: 0,
        queuedCount: 0,
        uploadingCount: 0,
        bytesTotal: 12,
        bytesDone: 0,
        overall: 0,
    };
    state.activeSessions = [session];

    await state.finishSession(session);

    assert.equal(state.activeSessions.length, 1);
    assert.equal(state.sessions.length, 0);
});

test('an interrupted row displays a source mismatch instead of hiding it', () => {
    assert.match(
        panel,
        /job\.status === 'interrupted'[\s\S]*?job\.error \|\| i18n\.interruptedJob/,
    );
});

function loadPanel({ calls, storage, timers }) {
    const script = panel.slice(panel.lastIndexOf('<script type="module">') + 22).replace(/<\/script>[\s\S]*$/, '');
    let definition;
    const window = { progress: [], innerHeight: 800, addEventListener() {}, removeEventListener() {} };
    window.loadDamChunkUploader = async () => ({
        fingerprintFile: async () => {
            calls.push({ client: true, method: 'fingerprint' });
            return 'a'.repeat(64);
        },
        ChunkUploadClient: class {
            constructor(options) {
                this.onProgress = options.onProgress;
            }

            async initialize() {
                calls.push({ client: true, method: 'initialize' });
                return upload('receiving', { received_bytes: 0 });
            }

            async uploadMissing() {
                calls.push({ client: true, method: 'uploadMissing' });
                this.onProgress({ acceptedBytes: 6, totalBytes: 12, state: 'receiving', upload: upload('receiving', { received_bytes: 6 }) });
                window.progress.push(50);
                return upload('receiving', { received_bytes: 12 });
            }

            async complete() {
                calls.push({ client: true, method: 'complete' });
                this.onProgress({ acceptedBytes: 12, finalizationBytes: 6, totalBytes: 12, state: 'assembling', upload: upload('assembling', { received_bytes: 12 }) });
                return upload('completed', { received_bytes: 12, asset_id: 44 });
            }

            async status() {
                calls.push({ client: true, method: 'status' });
                return upload('receiving', { received_bytes: 6 });
            }

            async cancel() {
                calls.push({ client: true, method: 'cancel' });
                return upload('cancelled');
            }
        },
    });
    const context = {
        AbortController,
        Blob,
        crypto,
        DOMException,
        File,
        localStorage: {
            getItem: key => storage[key] ?? null,
            removeItem: key => delete storage[key],
            setItem: (key, value) => { storage[key] = value; },
        },
        setTimeout: timers ? timers.setTimeout : setTimeout,
        clearTimeout: timers ? timers.clearTimeout : clearTimeout,
        window,
        document: { body: { setAttribute() {}, removeAttribute() {} } },
        app: { component: (_name, value) => { definition = value; } },
    };

    vm.runInNewContext(renderBladeScript(script), context);

    return { definition, window };
}

// A manual clock so the 6 s card dismissal can be crossed without waiting.
function fakeTimers() {
    let now = 0;
    let nextId = 1;
    const pending = new Map();

    return {
        setTimeout: (callback, delay = 0) => {
            const id = nextId++;
            pending.set(id, { at: now + delay, callback });
            return id;
        },
        clearTimeout: id => { pending.delete(id); },
        advance(ms) {
            const target = now + ms;
            for (;;) {
                const due = [...pending.entries()].filter(([, t]) => t.at <= target).sort((a, b) => a[1].at - b[1].at)[0];
                if (!due) break;
                pending.delete(due[0]);
                now = due[1].at;
                due[1].callback();
            }
            now = target;
        },
    };
}

function mountState(definition, storage) {
    const state = {
        ...definition.data(),
        ...definition.methods,
        isPrimary: true,
        $axios: {
            isCancel: () => false,
            post: async () => ({ data: {} }),
        },
        $emit() {},
        $emitter: { emit() {}, on() {}, off() {} },
        $nextTick: callback => Promise.resolve().then(callback),
    };

    return state;
}

function upload(state, overrides = {}) {
    return {
        uuid: 'upload-1',
        state,
        chunk_size: 4,
        expected_bytes: 12,
        received_indices: [],
        received_bytes: 0,
        expires_at: new Date(Date.now() + 60_000).toISOString(),
        ...overrides,
    };
}

function renderBladeScript(source) {
    return replaceBladeCalls(source)
        .replace(/\{\{[\s\S]*?\}\}/g, match => {
            if (match.includes('chunk-upload.initialize')) return '/chunks';
            if (match.includes('chunk-upload.status')) return '/chunks/__UUID__';
            if (match.includes('chunk-upload.store')) return '/chunks/__UUID__/__INDEX__';
            if (match.includes('chunk-upload.complete')) return '/chunks/__UUID__/complete';
            if (match.includes('chunk-upload.cancel')) return '/chunks/__UUID__';
            return '/tracker/__UUID__';
        });
}

function replaceBladeCalls(source) {
    let output = '';
    let offset = 0;

    for (;;) {
        const match = /@js\(/.exec(source.slice(offset));
        if (!match) return output + source.slice(offset);
        const start = offset + match.index;
        output += source.slice(offset, start);
        let depth = 1;
        let quote = null;
        let escape = false;
        let end = start + match[0].length;

        for (; end < source.length && depth; end++) {
            const character = source[end];
            if (escape) { escape = false; continue; }
            if (character === '\\') { escape = true; continue; }
            if (quote) { if (character === quote) quote = null; continue; }
            if (character === '\'' || character === '"') { quote = character; continue; }
            if (character === '(') depth++;
            if (character === ')') depth--;
        }

        const expression = source.slice(start, end);
        if (expression.includes("auth('admin')->id()")) {
            output += '7';
        } else if (expression.includes('upload-errors')) {
            output += JSON.stringify({ generic: 'Failed', reselect_required: 'Reselect', source_mismatch: 'Mismatch' });
        } else if (expression.includes('index.upload-complete')) {
            output += '"Upload complete"';
        } else if (expression.includes('upload-stack.not-stacked')) {
            output += '"Not stacked :count"';
        } else if (expression.includes('concurrency')) {
            output += '2';
        } else if (expression.includes('resume_enabled')) {
            output += 'true';
        } else if (expression.includes('chunk_bytes')) {
            output += '4';
        } else if (expression.includes('retry_attempts')) {
            output += '3';
        } else if (expression.includes('resume_stale_hours')) {
            output += '24';
        } else {
            output += '"Translation"';
        }
        offset = end;
    }
}
