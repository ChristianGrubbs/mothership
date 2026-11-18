import test from 'node:test';
import assert from 'node:assert/strict';

const MODULE = '../../src/Resources/assets/js/model/thumbnail-capture.mjs';

function blob(text = 'jpeg-bytes') {
    return new Blob([text], { type: 'image/jpeg' });
}

function recorder() {
    const events = [];

    return { events, emit(name, payload) { events.push({ name, payload }); } };
}

function okResponse(body = { stored: true, skipped: null, thumbnail_url: '/thumb.jpg' }) {
    return { ok: true, status: 200, json: async () => body };
}

/**
 * A `fetch` call that never settles until its own request is aborted --
 * exactly what a real, uncooperative connection does when an AbortController
 * tied to it fires: the browser guarantees the fetch promise itself rejects
 * with AbortError, never that it just keeps waiting regardless of the signal.
 */
function stalledFetch(init) {
    return new Promise((_resolve, reject) => {
        init.signal.addEventListener('abort', () => reject(new DOMException('The operation was aborted.', 'AbortError')));
    });
}

/**
 * A response whose headers arrive fine (200, `ok`) but whose body never
 * finishes -- the shape a stalled upload actually takes in production.
 * Abort-aware, the same real-browser guarantee `stalledFetch` relies on:
 * aborting the controller after headers arrive still rejects an in-flight
 * `.json()` read of that response, it does not leave it pending forever.
 */
function stalledBodyResponse(init) {
    return {
        ok: true,
        status: 200,
        json: () => new Promise((_resolve, reject) => {
            init.signal.addEventListener('abort', () => reject(new DOMException('The operation was aborted.', 'AbortError')));
        }),
    };
}

function item(id, overrides = {}) {
    return { id, url: `/download/${id}`, extension: 'glb', checksum: 'a'.repeat(64), size: 1024, ...overrides };
}

async function build(options) {
    const { ModelThumbnailQueue } = await import(MODULE);

    return new ModelThumbnailQueue({
        endpoint: entry => `/model-thumbnail/${entry.id}`,
        timeoutMs: 200,
        ...options,
    });
}

test('the queue captures at most two models at a time', async () => {
    let live = 0;
    let peak = 0;
    const queue = await build({
        async capture() {
            peak = Math.max(peak, ++live);
            await new Promise(resolve => setTimeout(resolve, 10));
            live--;

            return blob();
        },
        fetchImpl: async () => okResponse(),
    });

    queue.enqueue([1, 2, 3, 4, 5, 6].map(id => item(id)));
    const stats = await queue.run();

    assert.equal(peak, 2);
    assert.equal(stats.done, 6);
    assert.equal(stats.failed, 0);
});

test('items that exceed the viewer limits are skipped without a capture or a request', async () => {
    const { MAX_CAPTURE_BYTES } = await import(MODULE);
    let captures = 0;
    let requests = 0;
    const queue = await build({
        async capture() { captures++; return blob(); },
        fetchImpl: async () => { requests++; return okResponse(); },
    });

    queue.enqueue([
        item(1, { size: MAX_CAPTURE_BYTES + 1 }),
        item(2, { extension: 'obj' }),
        item(3, { checksum: null }),
    ]);
    const stats = await queue.run();

    assert.equal(captures, 0);
    assert.equal(requests, 0);
    assert.equal(stats.skipped, 3);
    assert.deepEqual(queue.results.map(result => result.reason), ['too-large', 'unsupported', 'unbound']);
});

test('every viewer format, STL included, passes preflight and reaches capture', async () => {
    const seen = [];
    const queue = await build({
        async capture({ extension }) { seen.push(extension); return blob(); },
        fetchImpl: async () => okResponse(),
    });

    queue.enqueue([item(1, { extension: 'glb' }), item(2, { extension: '3mf' }), item(3, { extension: 'STL' })]);
    const stats = await queue.run();

    assert.deepEqual(seen.sort(), ['3mf', 'STL', 'glb'].sort());
    assert.equal(stats.done, 3);
    assert.equal(stats.skipped, 0);
});

test('a deterministic capture failure is reported once and never retried', async () => {
    const { ModelCaptureError } = await import(MODULE);
    let captures = 0;
    const queue = await build({
        async capture() {
            captures++;
            throw new ModelCaptureError('too many triangles', 'too-many-triangles');
        },
        fetchImpl: async () => okResponse(),
    });

    queue.enqueue([item(1)]);
    const stats = await queue.run();

    assert.equal(captures, 1);
    assert.equal(stats.skipped, 1);
    assert.equal(queue.results[0].reason, 'too-many-triangles');
});

test('a capture that never settles fails on the per-item timeout', async () => {
    const queue = await build({
        timeoutMs: 25,
        capture: () => new Promise(() => {}),
        fetchImpl: async () => okResponse(),
    });

    queue.enqueue([item(1)]);
    const stats = await queue.run();

    assert.equal(stats.failed, 1);
    assert.equal(queue.results[0].reason, 'timeout');
});

test('a network failure is retried exactly once and a rejected upload is not', async () => {
    const attempts = [];
    const queue = await build({
        capture: async () => blob(),
        fetchImpl: async url => {
            attempts.push(url);
            if (url.endsWith('/1') && attempts.filter(entry => entry.endsWith('/1')).length === 1) {
                throw new TypeError('Failed to fetch');
            }
            if (url.endsWith('/2')) {
                return { ok: false, status: 409, json: async () => ({ message: 'stale' }) };
            }

            return okResponse();
        },
    });

    queue.enqueue([item(1), item(2)]);
    const stats = await queue.run();

    assert.equal(attempts.filter(entry => entry.endsWith('/1')).length, 2);
    assert.equal(attempts.filter(entry => entry.endsWith('/2')).length, 1);
    assert.equal(stats.done, 1);
    assert.equal(stats.failed, 1);
    assert.equal(queue.results.find(result => result.id === 2).reason, 'rejected');
});

test('a fetch that never settles fails on the upload timeout, not the queue itself', { timeout: 2000 }, async () => {
    let attempts = 0;
    const queue = await build({
        timeoutMs: 25,
        capture: async () => blob(),
        fetchImpl: (url, init) => { attempts++; return stalledFetch(init); },
    });

    queue.enqueue([item(1)]);
    const stats = await queue.run();

    assert.equal(stats.failed, 1);
    assert.equal(queue.results[0].reason, 'timeout');
    assert.equal(attempts, 2, 'a stalled connect is retried once, same as any other transport failure');
});

/**
 * The bug this guards: a `.json()` rejection was being caught unconditionally
 * and coerced to `{}`, so an aborted (timed-out) body read looked exactly
 * like a genuinely empty, successful response -- the queue reported the item
 * `done` with `url: null` instead of retrying and eventually failing it.
 */
test('a response body that never settles is retried once, then reported as failed -- never as a silent empty success', { timeout: 2000 }, async () => {
    let attempts = 0;
    const queue = await build({
        timeoutMs: 25,
        capture: async () => blob(),
        fetchImpl: (url, init) => { attempts++; return Promise.resolve(stalledBodyResponse(init)); },
    });

    queue.enqueue([item(1)]);
    const stats = await queue.run();

    assert.equal(attempts, 2, 'a stalled body read is retried once, same as any other transport failure');
    assert.equal(stats.done, 0);
    assert.equal(stats.failed, 1);
    assert.equal(queue.results[0].status, 'failed');
    assert.equal(queue.results[0].reason, 'timeout');
    assert.equal(queue.results[0].url, undefined, 'never reports done with a null url for a body that never arrived');
});

test('cancelling during a stalled upload still lets the run settle instead of hanging on it', { timeout: 2000 }, async () => {
    const queue = await build({
        timeoutMs: 25,
        concurrency: 2,
        capture: async () => blob(),
        fetchImpl: (url, init) => Promise.resolve(stalledBodyResponse(init)),
        onProgress: () => queue.cancel(),
    });

    queue.enqueue([item(1), item(2)]);
    const stats = await queue.run();

    assert.equal(stats.cancelled, true);
    assert.equal(stats.pending, 0);
    assert.equal(stats.failed, 2);
    assert.ok(queue.results.every(result => result.status === 'failed' && result.reason === 'timeout'));
});

test('progress is reported per item and a cancel stops the remaining work', async () => {
    const progress = [];
    let captures = 0;
    const queue = await build({
        concurrency: 1,
        async capture() {
            captures++;
            await new Promise(resolve => setTimeout(resolve, 5));

            return blob();
        },
        fetchImpl: async () => okResponse(),
        onProgress: snapshot => {
            progress.push({ ...snapshot });
            if (progress.length === 2) queue.cancel();
        },
    });

    queue.enqueue([1, 2, 3, 4, 5].map(id => item(id)));
    const stats = await queue.run();

    assert.equal(progress.length, 2);
    assert.deepEqual(progress.map(entry => entry.total), [5, 5]);
    assert.deepEqual(progress.map(entry => entry.done), [1, 2]);
    assert.equal(captures, 2);
    assert.equal(stats.cancelled, true);
    assert.equal(stats.done, 2);
});

test('a stored thumbnail announces itself on the shared emitter and a skipped one does not', async () => {
    const emitter = recorder();
    const queue = await build({
        emitter,
        capture: async () => blob(),
        fetchImpl: async url => okResponse(url.endsWith('/2')
            ? { stored: false, skipped: 'custom', thumbnail_url: '/custom.png' }
            : { stored: true, skipped: null, thumbnail_url: `/generated-${url.slice(-1)}.jpg` }),
    });

    queue.enqueue([item(1), item(2)]);
    const stats = await queue.run();

    assert.deepEqual(emitter.events, [{ name: 'dam:thumbnail-updated', payload: { id: 1, url: '/generated-1.jpg' } }]);
    assert.equal(stats.done, 1);
    assert.equal(stats.skipped, 1);
    assert.equal(queue.results.find(result => result.id === 2).reason, 'custom');
});

test('the upload carries the capture, its binding checksum and the replace flag', async () => {
    let sent = null;
    const queue = await build({
        replace: true,
        csrfToken: 'token-value',
        capture: async () => blob('pixels'),
        fetchImpl: async (url, init) => { sent = { url, init }; return okResponse(); },
    });

    queue.enqueue([item(7)]);
    await queue.run();

    assert.equal(sent.url, '/model-thumbnail/7');
    assert.equal(sent.init.method, 'POST');
    assert.equal(sent.init.credentials, 'same-origin');
    assert.equal(sent.init.headers['X-CSRF-TOKEN'], 'token-value');
    assert.equal(sent.init.body.get('checksum'), 'a'.repeat(64));
    assert.equal(sent.init.body.get('replace'), '1');
    assert.equal(await sent.init.body.get('image').text(), 'pixels');
});

function busyResponse(retryAfter = null) {
    return { ok: false, status: 503, headers: { get: name => (name === 'Retry-After' ? retryAfter : null) }, json: async () => ({ message: 'busy' }) };
}

test('a 503 (the capture lock was busy) is retried after a pause and then stored', async () => {
    const sleeps = [];
    let requests = 0;
    const queue = await build({
        async capture() { return blob(); },
        sleep: async ms => { sleeps.push(ms); },
        fetchImpl: async () => (++requests < 3 ? busyResponse() : okResponse()),
    });

    queue.enqueue([item(1)]);
    const stats = await queue.run();

    assert.equal(stats.done, 1);
    assert.equal(requests, 3);
    assert.deepEqual(sleeps, [2000, 5000]);
});

test('a 503 that never clears is given up on after three pauses, as busy, and Retry-After can lengthen a pause but not past the cap', async () => {
    const sleeps = [];
    let requests = 0;
    const queue = await build({
        async capture() { return blob(); },
        sleep: async ms => { sleeps.push(ms); },
        fetchImpl: async () => { requests++; return busyResponse(requests === 1 ? '8' : '600'); },
    });

    queue.enqueue([item(1)]);
    await queue.run();

    assert.equal(requests, 4);
    assert.deepEqual(sleeps, [8000, 15000, 15000]);
    assert.deepEqual([queue.results[0].status, queue.results[0].reason], ['failed', 'busy']);
});

test('postWithBusyRetry retries only a 503, stops when asked, and rethrows anything else at once', async () => {
    const { postWithBusyRetry } = await import(MODULE);
    const busy = () => Object.assign(new Error('busy'), { response: { status: 503, headers: { 'retry-after': '3' } } });
    const sleeps = [];
    let calls = 0;

    const saved = await postWithBusyRetry({
        post: async () => { if (++calls < 3) throw busy(); return { data: { stored: true } }; },
        sleep: async ms => { sleeps.push(ms); },
    });
    assert.deepEqual([saved.data.stored, calls, sleeps], [true, 3, [3000, 5000]]);

    calls = 0;
    await assert.rejects(postWithBusyRetry({ post: async () => { calls++; throw Object.assign(new Error('stale'), { response: { status: 409 } }); }, sleep: async () => {} }));
    assert.equal(calls, 1);

    calls = 0;
    await assert.rejects(postWithBusyRetry({ post: async () => { calls++; throw busy(); }, sleep: async () => {} }), /busy/);
    assert.equal(calls, 4);

    calls = 0;
    await assert.rejects(postWithBusyRetry({ post: async () => { calls++; throw busy(); }, sleep: async () => {}, shouldStop: () => true }));
    assert.equal(calls, 1);
});

test('a save superseded by a newer one for the same model sends nothing when its retry wakes', async () => {
    const { postWithBusyRetry, nextCaptureGeneration, isCurrentCaptureGeneration } = await import(MODULE);
    const busy = () => Object.assign(new Error('busy'), { response: { status: 503, headers: {} } });
    const older = nextCaptureGeneration('file:7');
    let sent = 0;

    await assert.rejects(postWithBusyRetry({
        post: async () => { sent++; throw busy(); },
        // While the older save waits, the viewer saves again for the same file.
        sleep: async () => { nextCaptureGeneration('file:7'); },
        shouldStop: () => ! isCurrentCaptureGeneration('file:7', older),
    }));

    assert.equal(sent, 1);
    // Another model's generation is its own.
    assert.equal(isCurrentCaptureGeneration('file:8', nextCaptureGeneration('file:8')), true);
});

test('cancelling while the queue waits out a 503 sends no further request and does not report the item done', async () => {
    let requests = 0;
    let queue;
    queue = await build({
        async capture() { return blob(); },
        sleep: async () => { queue.cancel(); },
        fetchImpl: async () => { requests++; return busyResponse(); },
    });

    queue.enqueue([item(1)]);
    const stats = await queue.run();

    assert.equal(requests, 1);
    assert.equal(stats.done, 0);
    assert.deepEqual([queue.results[0].status, queue.results[0].reason], ['skipped', 'cancelled']);
});

test('an older save whose encode finishes after a newer save began is dropped, and the newer save still retries with its own image', async () => {
    const { saveLatestCapture } = await import(MODULE);
    const busy = () => Object.assign(new Error('busy'), { response: { status: 503, headers: {} } });
    const posted = [];
    let releaseA;
    let wakeB;
    const encodeA = new Promise(resolve => { releaseA = () => resolve('image-A'); });
    const post = async image => {
        posted.push(image);
        if (image === 'image-B' && posted.filter(sent => sent === 'image-B').length === 1) throw busy();

        return { data: { stored: true, thumbnail_url: `/${image}.jpg` } };
    };

    // A starts first (its encode is slow), then B starts (its encode is immediate).
    const a = saveLatestCapture({ key: 'file:7', encode: () => encodeA, post });
    const b = saveLatestCapture({
        key: 'file:7',
        encode: async () => 'image-B',
        post,
        sleep: () => new Promise(resolve => { wakeB = resolve; }),
    });

    // B's first request is refused with a 503 and B waits; only now does A's encode finish.
    await new Promise(resolve => setTimeout(resolve, 5));
    releaseA();
    assert.equal(await a, null);

    wakeB();
    const saved = await b;

    assert.deepEqual(posted, ['image-B', 'image-B']);
    assert.deepEqual([saved.data.thumbnail_url, saved.current], ['/image-B.jpg', true]);
});
