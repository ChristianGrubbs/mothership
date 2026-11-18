import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

import { ChunkUploadClient } from '../../src/Resources/assets/js/upload/chunked-upload.mjs';

/**
 * Failed uploads must say what happened and offer Retry only when it can help (FAULT-03, LIB-08).
 * Loads v-dam-drop-upload from its Blade file, as upload-panel-dismiss.test.mjs does.
 */
function loadDropUpload() {
    const blade = readFileSync(
        new URL('../../src/Resources/views/components/asset/drop-upload.blade.php', import.meta.url),
        'utf8',
    );
    let script = [...blade.matchAll(/<script type="module">([\s\S]*?)<\/script>/g)]
        .map(match => match[1])
        .find(source => source.includes("app.component('v-dam-drop-upload'"));

    assert.ok(script, 'v-dam-drop-upload not found');

    // @js() and @json() print a JS literal; neither runs outside Blade.
    script = script.split('\n').map(line => {
        let out = line;
        for (let start = out.search(/@js(?:on)?\(/); start !== -1; start = out.search(/@js(?:on)?\(/)) {
            let depth = 0;
            let i = out.indexOf('(', start);
            for (; i < out.length; i++) {
                if (out[i] === '(') depth++;
                else if (out[i] === ')') { depth--; if (depth === 0) break; }
            }
            out = out.slice(0, start) + '0' + out.slice(i + 1);
        }
        return out;
    }).filter(line => !/^\s*@(if|elseif|else|endif|once|endonce|push|endpush)\b/.test(line)).join('\n');

    const timers = new Map();
    let nextTimer = 1;
    const clock = {
        setTimeout: (fn, ms) => { const id = nextTimer++; timers.set(id, { fn, ms }); return id; },
        clearTimeout: id => { timers.delete(id); },
        // Runs every pending timer of the given delay once, like that much time passing.
        advance(ms) {
            for (const [id, timer] of [...timers]) {
                if (timer.ms === ms) { timers.delete(id); timer.fn(); }
            }
        },
        pending: ms => [...timers.values()].filter(timer => timer.ms === ms).length,
    };

    // Records the listeners the tray adds, as a page would hold them.
    const win = {
        listeners: [],
        addEventListener(type, handler) { this.listeners.push({ type, handler }); },
        removeEventListener(type, handler) { this.listeners = this.listeners.filter(l => l.type !== type || l.handler !== handler); },
    };

    let component;
    vm.runInNewContext(script, {
        app: { component: (_name, definition) => { component = definition; } },
        window: win,
        document: {},
        localStorage: { getItem: () => null, setItem() {}, removeItem() {} },
        setTimeout: clock.setTimeout,
        clearTimeout: clock.clearTimeout,
    });

    const vmInstance = Object.assign({}, component.data.call({}), component.methods, { persistNow() {}, persistState() {} });

    return { vm: vmInstance, clock, win };
}


const session = (jobs = []) => ({ id: 1, uuid: 'u-1', targetDirId: 7, jobs, doneCount: 0, errorCount: 0, paused: false });

test('a failed tracker registration is retried before the next file instead of dooming the batch', async () => {
    const { vm } = loadDropUpload();
    let calls = 0;
    vm.$axios = { post: async () => { calls++; if (calls === 1) throw Object.assign(new Error('boom'), { response: { status: 500 } }); return {}; } };
    const s = session([{ id: 1, isFolder: false }]);

    await vm.startTracker(s);
    assert.equal(s._trackerReady, undefined, 'the failure is remembered, not swallowed as success');

    await vm.ensureTracker(s);
    assert.equal(calls, 2);
    assert.equal(s._trackerReady, true);

    await vm.ensureTracker(s);
    assert.equal(calls, 2, 'a registered tracker is not posted again');
});

test('a refusal is not offered Retry; a server or network failure is', () => {
    const { vm } = loadDropUpload();
    const httpError = (status, data = {}) => ({ isAxiosError: true, status, response: { status, data } });

    assert.equal(vm.uploadFailure(httpError(422, { message: 'The file type is not allowed.' })).retryable, false);
    assert.equal(vm.uploadFailure(httpError(403)).retryable, false);
    assert.equal(vm.uploadFailure({ code: 'invalid_upload_name', retryable: false, status: 422 }).retryable, false);
    assert.equal(vm.uploadFailure(httpError(500)).retryable, true);
    assert.equal(vm.uploadFailure({ isAxiosError: true, code: 'ERR_NETWORK' }).retryable, true);
    assert.equal(vm.uploadFailure(httpError(419)).retryable, true, 'signing in again fixes an expired session');
});

test('Retry requeues only the files that can succeed', async () => {
    const { vm } = loadDropUpload();
    const refused = { id: 1, isFolder: false, status: 'error', retryable: false };
    const transient = { id: 2, isFolder: false, status: 'error', retryable: true };
    const s = session([refused, transient]);
    s.errorCount = 2;
    vm.recount = () => {};
    vm.trackerAction = async () => {};
    vm.runWorkers = async () => {};
    vm.finishSession = async () => {};

    assert.equal(vm.sessionRetryableCount(s), 1);
    await vm.retrySession(s);
    assert.equal(refused.status, 'error');
});

test('a failed file never fills the progress bar', () => {
    const { vm } = loadDropUpload();
    const s = session([{ id: 1, isFolder: false, status: 'error', progress: 100 }]);
    s.errorCount = 1;

    assert.equal(vm.aggregate(s), 0);
});

// QRAI-06: the reason finalization stored reaches the tray through the status poll, word for word.
test('a refusal reason from the finalization poll is what the tray shows, with no Retry', async () => {
    const { vm } = loadDropUpload();
    const reasons = [
        { code: 'unsafe_path', message: 'This web page bundle contains a file path that points outside the bundle.' },
        { code: 'forbidden_entry', message: 'This web page bundle contains a file that is not allowed: shell.php' },
        { code: 'too_many_entries', message: 'This web page bundle has more than 2 files.' },
    ];

    for (const reason of reasons) {
        const polls = [
            { uuid: 'u1', state: 'queued', expires_at: null },
            { uuid: 'u1', state: 'failed', failure_code: 'forbidden_file', failure_reason: reason, expires_at: null },
        ];
        const client = new ChunkUploadClient({
            http: {
                post: async () => ({ data: { upload: polls[0] } }),
                get: async () => ({ data: { upload: polls[1] } }),
            },
            routes: { complete: () => '/complete', status: () => '/status' },
            chunkSize: 4,
            waitForPoll: async () => {},
        });

        const error = await client.complete({ uploadId: 'u1' }).then(() => null, failure => failure);

        assert.ok(error, 'the failed poll must reject');
        assert.deepEqual({ ...error.reason }, reason);
        assert.deepEqual({ ...vm.uploadFailure(error) }, { message: reason.message, retryable: false });
    }
});

test('a failed poll without a reason keeps the generic text, and a direct-upload 422 reason is shown too', async () => {
    const { vm } = loadDropUpload();
    const client = new ChunkUploadClient({
        http: { post: async () => ({ data: { upload: { uuid: 'u2', state: 'failed', failure_code: 'chunk_corrupt', failure_reason: null } } }) },
        routes: { complete: () => '/complete' },
        chunkSize: 4,
    });
    const error = await client.complete({ uploadId: 'u2' }).then(() => null, failure => failure);

    assert.equal(error.reason, undefined);
    assert.equal(error.message, 'The upload could not be completed.');

    // A direct upload's 422 carries the same {code, message}; the client puts it on the error as `reason`.
    const reason = { code: 'too_large', message: 'Too large.' };
    const direct = { status: 422, reason, response: { status: 422, data: { code: 'forbidden_type', message: 'raw', reason } } };
    assert.deepEqual({ ...vm.uploadFailure(direct) }, { message: 'Too large.', retryable: false });
});

/**
 * The tray's job rows (every v-for over jobWindow), cut from the Blade x-template, for Vue's server renderer.
 */
function trayRowTemplates() {
    const blade = readFileSync(new URL('../../src/Resources/views/components/asset/drop-upload.blade.php', import.meta.url), 'utf8');
    const rows = [];
    for (let anchor = blade.indexOf('v-for="job in jobWindow(session).items"'); anchor !== -1; anchor = blade.indexOf('v-for="job in jobWindow(session).items"', anchor + 1)) {
        const start = blade.lastIndexOf('<div', anchor);
        const tag = /<\/?div\b/g;
        tag.lastIndex = start;
        let depth = 0;
        for (let match = tag.exec(blade); match; match = tag.exec(blade)) {
            depth += match[0] === '<div' ? 1 : -1;
            if (depth === 0) {
                rows.push(blade.slice(start, blade.indexOf('>', match.index) + 1).replace(/@\{\{/g, '{{').replace(/@lang\([^)]*\)/g, 'label'));
                break;
            }
        }
    }
    assert.ok(rows.length >= 2, 'tray job rows not found');

    return rows;
}

const decode = html => html.replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&quot;/g, '"').replace(/&#39;/g, "'").replace(/&amp;/g, '&');

test('the tray shows a hostile refusal reason as literal text: no element is created from it', async () => {
    const { createSSRApp } = await import('vue');
    const { renderToString } = await import('vue/server-renderer');
    const { vm } = loadDropUpload();

    const hostile = '<img src=x onerror=alert(1)><script>alert(2)</script>"\'` ' + 'A'.repeat(180) + '…';
    const message = `The bundle contains a file type that cannot be served: ${hostile}`;
    const client = new ChunkUploadClient({
        http: { post: async () => ({ data: { upload: { uuid: 'u3', state: 'failed', failure_code: 'forbidden_file', failure_reason: { code: 'forbidden_entry', message } } } }) },
        routes: { complete: () => '/complete' },
        chunkSize: 4,
    });
    const failure = vm.uploadFailure(await client.complete({ uploadId: 'u3' }).then(() => null, error => error));
    const job = { id: 1, name: '<img src=x onerror=alert(3)>.zip', isFolder: false, status: 'error', error: failure.message, retryable: failure.retryable };

    const render = template => renderToString(createSSRApp({
        template,
        // The component's own data and methods; only the virtual-scroll window is fixed to this one row.
        data: () => ({
            ...Object.fromEntries(Object.entries(vm).filter(([, value]) => typeof value !== 'function')),
            session: { jobs: [job] },
            rowHeight: vm.rowHeight ?? 48,
            i18n: vm.i18n ?? {},
        }),
        methods: {
            ...Object.fromEntries(Object.entries(vm).filter(([, value]) => typeof value === 'function')),
            jobWindow: () => ({ items: [job], padTop: 0, padBottom: 0 }),
        },
    }));

    assert.equal(failure.retryable, false);
    let shown = 0;

    for (const html of await Promise.all(trayRowTemplates().map(render))) {
        assert.doesNotMatch(html, /<img\b/i, 'no img element');
        assert.doesNotMatch(html, /<script\b/i, 'no script element');
        assert.match(html, /&lt;img src=x onerror=alert\(3\)&gt;\.zip/, 'the file name is text too');

        const errorText = html.match(/<p[^>]*data-dam-job-error[^>]*>([\s\S]*?)<\/p>/)?.[1];
        if (errorText !== undefined) {
            shown++;
            assert.equal(decode(errorText), message, 'textContent of the error line is the reason, verbatim');
            assert.match(html, /&lt;img src=x onerror=alert\(1\)&gt;/);
        }
    }

    assert.equal(shown, 1, 'one row variant shows the reason');
});

/* LIB-15: a brief outage while finalization runs, and leaving the page mid-upload. */

const pollingClient = (responses, waits = [], options = {}) => {
    const calls = { post: 0, get: 0 };
    const client = new ChunkUploadClient({
        http: {
            post: async () => { calls.post++; return { data: { upload: { uuid: 'p1', state: 'queued', expires_at: null } } }; },
            get: async (_url, config) => {
                calls.get++;
                const next = responses.shift();
                if (typeof next === 'function') return next(config);
                if (next instanceof Error) throw next;
                return { data: { upload: next } };
            },
        },
        routes: { complete: () => '/complete', status: () => '/status' },
        chunkSize: 4,
        waitForPoll: async () => {},
        waitForRetry: async delay => { waits.push(delay); },
        ...options,
    });
    client.calls = calls;

    return client;
};

/** A clock the test moves, and timers that fire only when it passes them. */
function fakeClock() {
    let now = 0;
    const timers = new Set();
    const clock = {
        now: () => now,
        setTimer: (callback, delay) => { const timer = { at: now + delay, callback }; timers.add(timer); return timer; },
        clearTimer: timer => { timers.delete(timer); },
        advance(ms) {
            now += ms;
            for (const timer of [...timers]) {
                if (timer.at <= now) { timers.delete(timer); timer.callback(); }
            }
        },
        pending: () => timers.size,
    };

    return clock;
}

const httpFailure = (status, data = {}) => Object.assign(new Error(`HTTP ${status}`), { response: { status, data } });

test('one 503 while polling final processing is retried, and the upload completes', async () => {
    const waits = [];
    const client = pollingClient([httpFailure(503), { uuid: 'p1', state: 'completed', asset_id: 9 }], waits);

    const result = await client.complete({ uploadId: 'p1' });

    assert.equal(result.state, 'completed');
    assert.deepEqual(waits, [1000]);
    assert.equal(client.calls.post, 1, 'recovering never asks the server to finalize again');
    assert.equal(client.calls.get, 2);
});

test('a dropped connection while polling is retried too', async () => {
    const client = pollingClient([Object.assign(new Error('Network Error'), { isAxiosError: true }), { uuid: 'p1', state: 'completed' }]);

    assert.equal((await client.complete({ uploadId: 'p1' })).state, 'completed');
});

test('a server that keeps failing ends the upload after the bound, not on the first error', async () => {
    const waits = [];
    const client = pollingClient(Array.from({ length: 10 }, () => httpFailure(502)), waits);

    const error = await client.complete({ uploadId: 'p1' }).then(() => null, failure => failure);

    assert.ok(error, 'it must fail eventually');
    assert.equal(error.status, 502);
    assert.equal(waits.length, 4, 'five tries, four waits');
    assert.deepEqual(waits, [1000, 2000, 4000, 8000]);
});

test('a backoff that would cross the 60 s budget is cut short, and no request starts after it', async () => {
    const clock = fakeClock();
    const waits = [];
    const client = pollingClient(Array.from({ length: 20 }, () => httpFailure(503)), [], {
        ...clock,
        pollRetryAttempts: 20,
        maxPollRetryDelayMs: 60000,
        waitForRetry: async delay => { waits.push(delay); clock.advance(delay); },
    });

    const error = await client.complete({ uploadId: 'p1' }).then(() => null, failure => failure);

    // Tries at 0, 1, 3, 7, 15 and 31 s; the next 32 s backoff is cut to the 29 s left, and then nothing.
    assert.deepEqual(waits, [1000, 2000, 4000, 8000, 16000, 29000]);
    assert.equal(client.calls.get, 6);
    assert.equal(clock.now(), 60000);
    assert.equal(error.status, 503);
    assert.equal(clock.pending(), 0, 'no timer left behind');
});

test('a status request still pending at the deadline is aborted and reported as a failure, not a cancel', async () => {
    const clock = fakeClock();
    let pendingSignal = null;
    const hanging = config => new Promise((_resolve, reject) => {
        pendingSignal = config.signal;
        config.signal.addEventListener('abort', () => reject(Object.assign(new Error('canceled'), { name: 'CanceledError', code: 'ERR_CANCELED' })));
    });
    const client = pollingClient([hanging], [], { ...clock });

    const outcome = client.complete({ uploadId: 'p1' }).then(() => null, failure => failure);
    await new Promise(resolve => setImmediate(resolve));
    assert.ok(pendingSignal && ! pendingSignal.aborted, 'the request is in flight');

    clock.advance(60000);
    const error = await outcome;

    assert.equal(pendingSignal.aborted, true);
    assert.equal(error.code, 'status_timeout');
    assert.notEqual(error.name, 'AbortError');
    assert.notEqual(error.name, 'CanceledError');
    assert.equal(client.calls.get, 1);
    assert.deepEqual({ ...loadDropUpload().vm.uploadFailure(error) }.retryable, true, 'shown as a failure the user can retry');
});

test('cancelling while a status request is pending is still a cancel', async () => {
    const clock = fakeClock();
    const controller = new AbortController();
    const hanging = config => new Promise((_resolve, reject) => {
        config.signal.addEventListener('abort', () => reject(Object.assign(new Error('canceled'), { name: 'CanceledError', code: 'ERR_CANCELED' })));
    });
    const client = pollingClient([hanging], [], { ...clock });

    const outcome = client.complete({ uploadId: 'p1', signal: controller.signal }).then(() => null, failure => failure);
    await new Promise(resolve => setImmediate(resolve));
    controller.abort();
    const error = await outcome;

    assert.ok(error.name === 'AbortError' || error.name === 'CanceledError', `a cancel, not ${error.code}`);
    assert.notEqual(error.code, 'status_timeout');
    assert.equal(clock.pending(), 0);
});

test('a 422 refusal while polling is final at once, with its reason', async () => {
    const waits = [];
    const reason = { code: 'unsafe_path', message: 'The bundle has a file path that points outside the bundle.' };
    const client = pollingClient([httpFailure(422, { code: 'forbidden_file', message: reason.message, reason }), { uuid: 'p1', state: 'completed' }], waits);

    const error = await client.complete({ uploadId: 'p1' }).then(() => null, failure => failure);

    assert.equal(waits.length, 0, 'no retry');
    assert.deepEqual({ ...error.reason }, reason);
    assert.deepEqual({ ...loadDropUpload().vm.uploadFailure(error) }, { message: reason.message, retryable: false });
});

test('leaving the page asks first only while files are in flight, with one listener', () => {
    const { vm, win } = loadDropUpload();
    vm.isPrimary = true;
    const guards = () => win.listeners.filter(l => l.type === 'beforeunload');
    const job = { id: 1, isFolder: false, status: 'uploading', phase: 'receiving' };
    vm.activeSessions = [session([job])];

    vm.syncUnloadGuard(vm.inFlightCount());
    vm.syncUnloadGuard(vm.inFlightCount());
    assert.equal(guards().length, 1, 'registered once while uploading');

    const event = { defaultPrevented: false, returnValue: undefined, preventDefault() { this.defaultPrevented = true; } };
    guards()[0].handler(event);
    assert.equal(event.defaultPrevented, true);
    assert.equal(event.returnValue, '');

    job.phase = 'queued';
    vm.syncUnloadGuard(vm.inFlightCount());
    assert.equal(guards().length, 1, 'still asks while final processing is pending');

    job.status = 'done';
    vm.syncUnloadGuard(vm.inFlightCount());
    assert.equal(guards().length, 0, 'removed when idle');

    vm.activeSessions = [Object.assign(session([{ id: 2, isFolder: false, status: 'queued', phase: null }]), { paused: true })];
    vm.syncUnloadGuard(vm.inFlightCount());
    assert.equal(guards().length, 0, 'a paused batch does not ask');
});

test('a secondary tray never adds its own unload prompt', () => {
    const { vm, win } = loadDropUpload();
    vm.isPrimary = false;
    vm.activeSessions = [session([{ id: 1, isFolder: false, status: 'uploading' }])];

    vm.syncUnloadGuard(vm.inFlightCount());

    assert.equal(win.listeners.filter(l => l.type === 'beforeunload').length, 0);
});
