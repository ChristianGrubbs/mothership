import assert from 'node:assert/strict';
import test from 'node:test';

import {
    ChunkUploadClient,
    MAX_FILE_BYTES,
    fingerprintFile,
} from '../../../packages/Mothership/DAM/src/Resources/assets/js/upload/chunked-upload.mjs';

const routeFixture = () => ({
    initialize: '/upload/chunks',
    status: id => `/upload/chunks/${id}`,
    store: (id, index) => `/upload/chunks/${id}/${index}`,
    complete: id => `/upload/chunks/${id}/complete`,
    cancel: id => `/upload/chunks/${id}`,
});

const makeFile = (contents = 'abcdefghijkl') => new File([Buffer.from(contents)], 'master.mp4', {
    type: 'video/mp4',
    lastModified: 1_788_864_000_000,
});

test('initializes from metadata, accepts exactly 20 GiB, and rejects one byte above before HTTP', async () => {
    const calls = [];
    const http = {
        async post(url, body) {
            calls.push({ url, body });

            return { data: { upload: { uuid: 'upload-1' } } };
        },
    };
    const client = new ChunkUploadClient({ http, routes: routeFixture(), chunkSize: 4 });
    const accepted = {
        name: 'large.bin',
        type: 'application/octet-stream',
        size: MAX_FILE_BYTES,
        lastModified: 123,
        slice: (...args) => new Blob([]).slice(...args),
    };
    accepted.slice = (start, end) => new Blob([new Uint8Array(Math.max(0, Math.min(end, accepted.size) - start))]);

    await client.initialize({
        file: accepted,
        sessionUuid: 'session-1',
        directoryId: 7,
        relativePath: 'folder/large.bin',
        preserveRoot: true,
    });

    assert.equal(calls.length, 1);
    assert.equal(calls[0].body.size, MAX_FILE_BYTES);
    assert.equal(calls[0].body.original_name, 'large.bin');
    assert.equal(calls[0].body.fingerprint.length, 64);
    assert.equal(Object.values(calls[0].body).some(value => value instanceof Blob), false);

    await assert.rejects(
        client.initialize({
            file: { ...accepted, size: MAX_FILE_BYTES + 1 },
            sessionUuid: 'session-1',
            directoryId: 7,
            preserveRoot: false,
        }),
        error => error.code === 'file_too_large' && error.retryable === false,
    );
    assert.equal(calls.length, 1);
});

test('sends only missing chunks with exact hashes and ranges, then reports accepted bytes', async () => {
    const file = makeFile();
    const calls = [];
    const progress = [];
    const http = {
        async put(url, body, options) {
            const index = Number(url.split('/').at(-1));
            calls.push({ method: 'put', index, body, options });

            return {
                data: {
                    upload: {
                        received_indices: [index],
                        received_bytes: index === 0 ? 8 : 12,
                    },
                },
            };
        },
    };
    const client = new ChunkUploadClient({
        http,
        routes: routeFixture(),
        chunkSize: 99,
        retryAttempts: 3,
        onProgress: value => progress.push(value),
    });

    await client.uploadMissing({
        file,
        upload: {
            id: '00000000-0000-4000-8000-000000000001',
            chunk_size: 4,
            received_indices: [1],
            received_bytes: 4,
        },
        signal: new AbortController().signal,
    });

    assert.deepEqual(calls.map(call => call.index), [0, 2]);
    assert.equal(calls[0].options.headers['Content-Range'], 'bytes 0-3/12');
    assert.equal(calls[1].options.headers['Content-Range'], 'bytes 8-11/12');
    assert.equal(calls[0].options.headers['X-Chunk-SHA256'], await sha256('abcd'));
    assert.equal(calls[1].options.headers['X-Chunk-SHA256'], await sha256('ijkl'));
    assert.equal(progress.at(-1).acceptedBytes, 12);
    assert.equal(progress.some(value => Object.values(value).some(item => item instanceof Blob)), false);
});

test('retries only retryable failures with bounded waits', async () => {
    const waits = [];
    let attempts = 0;
    const client = new ChunkUploadClient({
        http: {
            async put() {
                attempts++;

                if (attempts < 3) {
                    const error = new Error('temporary');
                    error.response = { status: 503, data: { code: 'storage_busy', retryable: true } };
                    throw error;
                }

                return { data: { upload: { received_bytes: 4 } } };
            },
        },
        routes: routeFixture(),
        chunkSize: 4,
        retryAttempts: 3,
        maxRetryDelayMs: 25,
        waitForRetry: async delay => waits.push(delay),
    });

    await client.uploadMissing({ file: makeFile('abcd'), upload: { id: 'upload-1' } });

    assert.equal(attempts, 3);
    assert.deepEqual(waits, [25, 25]);
});

test('treats source and integrity responses as terminal and abort stops retries', async () => {
    for (const [status, code] of [[409, 'source_mismatch'], [422, 'chunk_checksum_mismatch']]) {
        let attempts = 0;
        const client = new ChunkUploadClient({
            http: {
                async put() {
                    attempts++;
                    const error = new Error(code);
                    error.response = { status, data: { code, retryable: false } };
                    throw error;
                },
            },
            routes: routeFixture(),
            chunkSize: 4,
            retryAttempts: 3,
            waitForRetry: async () => {},
        });

        await assert.rejects(
            client.uploadMissing({ file: makeFile('abcd'), upload: { id: 'upload-1' } }),
            error => error.code === code,
        );
        assert.equal(attempts, 1);
    }

    const controller = new AbortController();
    let attempts = 0;
    const abortingClient = new ChunkUploadClient({
        http: {
            async put() {
                attempts++;
                throw new TypeError('network down');
            },
        },
        routes: routeFixture(),
        chunkSize: 4,
        retryAttempts: 3,
        waitForRetry: async () => controller.abort(),
    });

    await assert.rejects(
        abortingClient.uploadMissing({ file: makeFile('abcd'), upload: { id: 'upload-1' }, signal: controller.signal }),
        error => error.name === 'AbortError',
    );
    assert.equal(attempts, 1);
});

test('forwards cancellation to initialization and preserves the abort identity', async () => {
    const controller = new AbortController();
    const cancelled = new Error('paused');
    cancelled.name = 'CanceledError';
    cancelled.code = 'ERR_CANCELED';
    const client = new ChunkUploadClient({
        http: {
            async post(_url, _body, options) {
                assert.equal(options.signal, controller.signal);
                throw cancelled;
            },
        },
        routes: routeFixture(),
        chunkSize: 4,
    });

    await assert.rejects(
        client.initialize({
            file: makeFile('abcd'),
            sessionUuid: 'session-1',
            directoryId: 7,
            signal: controller.signal,
        }),
        error => error === cancelled,
    );
});

test('fingerprints are stable and sample the beginning, middle, and end', async () => {
    const sampleSize = 1024 * 1024;
    const base = new Uint8Array(sampleSize * 4);
    const original = new File([base], 'large.bin');
    const stable = new File([base], 'renamed.bin');

    assert.equal(await fingerprintFile(original), await fingerprintFile(stable));

    for (const index of [0, sampleSize * 2, base.length - 1]) {
        const changed = base.slice();
        changed[index] = 1;
        assert.notEqual(await fingerprintFile(original), await fingerprintFile(new File([changed], 'large.bin')));
    }
});

test('posts completion once and polls queued through assembling to completed', async () => {
    const calls = [];
    const states = ['assembling', 'completed'];
    const waits = [];
    const http = {
        async post(url) {
            calls.push({ method: 'post', url });
            return { status: 202, data: { upload: { uuid: 'upload-1', state: 'queued', expires_at: new Date(Date.now() + 60_000).toISOString() } } };
        },
        async get(url) {
            calls.push({ method: 'get', url });
            const state = states.shift();
            return { data: { upload: { uuid: 'upload-1', state, asset_id: state === 'completed' ? 44 : null } } };
        },
    };
    const client = new ChunkUploadClient({
        http,
        routes: routeFixture(),
        chunkSize: 4,
        waitForPoll: async delay => waits.push(delay),
    });

    const upload = await client.complete({ uploadId: 'upload-1' });

    assert.equal(upload.state, 'completed');
    assert.equal(upload.asset_id, 44);
    assert.equal(calls.filter(call => call.method === 'post').length, 1);
    assert.equal(calls.filter(call => call.method === 'get').length, 2);
    assert.equal(waits.length, 2);
});

test('completion polling surfaces a stable failure code and supports status and cancel', async () => {
    const calls = [];
    const http = {
        async post() {
            return { data: { upload: { uuid: 'upload-1', state: 'queued' } } };
        },
        async get(url) {
            calls.push({ method: 'get', url });
            return { data: { upload: { uuid: 'upload-1', state: 'failed', failure_code: 'chunk_corrupt' } } };
        },
        async delete(url) {
            calls.push({ method: 'delete', url });
            return { data: { upload: { uuid: 'upload-1', state: 'cancelled' } } };
        },
    };
    const client = new ChunkUploadClient({
        http,
        routes: routeFixture(),
        chunkSize: 4,
        waitForPoll: async () => {},
    });

    await assert.rejects(client.complete({ uploadId: 'upload-1' }), error => error.code === 'chunk_corrupt');
    assert.equal((await client.status({ uploadId: 'upload-1' })).state, 'failed');
    assert.equal((await client.cancel({ uploadId: 'upload-1' })).state, 'cancelled');
    assert.deepEqual(calls.map(call => call.method), ['get', 'get', 'delete']);
});

async function sha256(value) {
    const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(value));

    return [...new Uint8Array(digest)].map(byte => byte.toString(16).padStart(2, '0')).join('');
}
