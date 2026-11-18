export const MAX_FILE_BYTES = 20 * 1024 * 1024 * 1024;

const FINGERPRINT_SAMPLE_BYTES = 1024 * 1024;

export async function fingerprintFile(file) {
    const sampleLength = Math.min(FINGERPRINT_SAMPLE_BYTES, file.size);
    const offsets = [
        0,
        Math.max(0, Math.floor((file.size - sampleLength) / 2)),
        Math.max(0, file.size - sampleLength),
    ];
    const prefix = new TextEncoder().encode(`${file.size}\n`);
    const samples = await Promise.all(offsets.map(offset => file.slice(offset, offset + sampleLength).arrayBuffer()));
    const length = prefix.byteLength + samples.reduce((sum, sample) => sum + sample.byteLength + 1, 0);
    const canonical = new Uint8Array(length);
    let cursor = 0;

    canonical.set(prefix, cursor);
    cursor += prefix.byteLength;

    for (const sample of samples) {
        canonical[cursor] = 0;
        cursor++;
        canonical.set(new Uint8Array(sample), cursor);
        cursor += sample.byteLength;
    }

    return hexDigest(await crypto.subtle.digest('SHA-256', canonical));
}

export class ChunkUploadClient {
    constructor({
        http,
        routes,
        chunkSize,
        retryAttempts = 3,
        onProgress = () => {},
        waitForRetry = abortableWait,
        waitForPoll = abortableWait,
        maxRetryDelayMs = 4000,
        pollIntervalMs = 1000,
        maxPollDelayMs = 5000,
        pollRetryAttempts = 5,
        pollRetryWindowMs = 60000,
        maxPollRetryDelayMs = 15000,
        now = () => Date.now(),
        setTimer = (callback, delay) => setTimeout(callback, delay),
        clearTimer = timer => clearTimeout(timer),
    }) {
        this.http = http;
        this.routes = routes;
        this.chunkSize = chunkSize;
        this.retryAttempts = Math.max(1, retryAttempts);
        this.onProgress = onProgress;
        this.waitForRetry = waitForRetry;
        this.waitForPoll = waitForPoll;
        this.maxRetryDelayMs = Math.max(0, maxRetryDelayMs);
        this.pollIntervalMs = Math.max(0, pollIntervalMs);
        this.maxPollDelayMs = Math.max(0, maxPollDelayMs);
        this.pollRetryAttempts = Math.max(1, pollRetryAttempts);
        this.pollRetryWindowMs = Math.max(0, pollRetryWindowMs);
        this.maxPollRetryDelayMs = Math.max(0, maxPollRetryDelayMs);
        this.now = now;
        this.setTimer = setTimer;
        this.clearTimer = clearTimer;
    }

    async initialize({ file, sessionUuid, directoryId, relativePath = null, preserveRoot = false, signal }) {
        if (file.size > MAX_FILE_BYTES) {
            throw clientError('file_too_large', false, 'The file exceeds the upload limit.');
        }

        const fingerprint = await fingerprintFile(file);

        try {
            const response = await this.http.post(this.routes.initialize, {
                session_uuid: sessionUuid,
                directory_id: directoryId,
                original_name: file.name,
                mime_type: file.type || null,
                size: file.size,
                last_modified: file.lastModified,
                fingerprint,
                relative_path: relativePath,
                preserve_root: preserveRoot,
            }, { signal });

            return response.data.upload;
        } catch (error) {
            throw normalizeError(error);
        }
    }

    async uploadMissing({ file, upload, signal }) {
        throwIfAborted(signal);

        const uploadId = upload.uuid ?? upload.id;
        const chunkSize = Number(upload.chunk_size ?? this.chunkSize);

        if (!uploadId || !Number.isSafeInteger(chunkSize) || chunkSize <= 0) {
            throw clientError('invalid_upload_manifest', false, 'The upload manifest is invalid.');
        }

        const received = new Set((upload.received_indices ?? []).map(Number));
        const chunkCount = Math.ceil(file.size / chunkSize);
        let acceptedBytes = Number(upload.received_bytes ?? 0);
        let latestUpload = upload;

        for (let index = 0; index < chunkCount; index++) {
            if (received.has(index)) {
                continue;
            }

            throwIfAborted(signal);

            const start = index * chunkSize;
            const end = Math.min(start + chunkSize, file.size);
            const chunk = file.slice(start, end);
            const digest = hexDigest(await crypto.subtle.digest('SHA-256', await chunk.arrayBuffer()));
            const response = await this.requestWithRetry(
                () => this.http.put(this.routes.store(uploadId, index), chunk, {
                    headers: {
                        'Content-Range': `bytes ${start}-${end - 1}/${file.size}`,
                        'X-Chunk-SHA256': digest,
                    },
                    signal,
                }),
                signal,
            );

            latestUpload = response.data.upload;
            acceptedBytes = Number(latestUpload.received_bytes ?? acceptedBytes + chunk.size);
            this.onProgress({
                acceptedBytes,
                totalBytes: file.size,
                state: latestUpload.state ?? 'receiving',
                upload: latestUpload,
            });
        }

        return latestUpload;
    }

    async complete({ uploadId, signal }) {
        throwIfAborted(signal);

        let upload;

        try {
            const response = await this.http.post(this.routes.complete(uploadId), null, { signal });
            upload = response.data.upload;
        } catch (error) {
            throw normalizeError(error);
        }

        const deadline = upload.expires_at ? Date.parse(upload.expires_at) : Number.POSITIVE_INFINITY;

        while (true) {
            const terminal = this.terminalResult(upload);

            if (terminal) {
                return terminal;
            }

            if (Date.now() >= deadline) {
                throw clientError('upload_expired', false, 'The upload has expired.');
            }

            const delay = Math.min(this.pollIntervalMs, this.maxPollDelayMs, deadline - Date.now());
            await this.waitForPoll(delay, signal);
            throwIfAborted(signal);
            upload = await this.pollStatus({ uploadId, signal });
            this.onProgress({
                acceptedBytes: Number(upload.received_bytes ?? 0),
                finalizationBytes: Number(upload.finalization_bytes ?? 0),
                totalBytes: Number(upload.expected_bytes ?? 0),
                state: upload.state,
                upload,
            });
        }
    }

    /**
     * One status poll while finalization runs, riding out a brief outage (LIB-15). A 502/503 or a dropped
     * connection while the worker finishes used to fail a file the server then published. Transient failures
     * (network, 5xx) are retried with backoff, up to pollRetryAttempts tries; a 4xx (a refusal with its
     * reason, an expired session, a missing upload) is final at once.
     *
     * The whole sequence has one absolute deadline (pollRetryWindowMs from the first try): a wait never runs
     * past it, no request starts after it, and a request still pending when it passes is aborted and reported
     * as a failure (`status_timeout`). The caller's cancel signal always wins and keeps its own outcome.
     */
    async pollStatus({ uploadId, signal }) {
        const deadline = this.now() + this.pollRetryWindowMs;
        let lastError = null;

        for (let attempt = 1; ; attempt++) {
            throwIfAborted(signal);

            if (this.now() >= deadline) {
                throw lastError ?? statusTimeout();
            }

            const budget = new AbortController();
            const onCancel = () => budget.abort(signal.reason);
            signal?.addEventListener('abort', onCancel, { once: true });
            let timedOut = false;
            const timer = this.setTimer(() => { timedOut = true; budget.abort(); }, Math.max(0, deadline - this.now()));

            try {
                return await this.status({ uploadId, signal: budget.signal });
            } catch (error) {
                if (signal?.aborted) {
                    throw isAbort(error) ? error : new DOMException('The operation was aborted.', 'AbortError');
                }

                if (timedOut) {
                    throw statusTimeout();
                }

                if (isAbort(error)) {
                    throw error;
                }

                const status = Number(error?.status ?? 0);
                const transient = status === 0 || status >= 500;

                if (! transient || attempt >= this.pollRetryAttempts) {
                    throw error;
                }

                lastError = error;
            } finally {
                this.clearTimer(timer);
                signal?.removeEventListener('abort', onCancel);
            }

            const remaining = deadline - this.now();

            if (remaining <= 0) {
                throw lastError;
            }

            await this.waitForRetry(Math.min(1000 * (2 ** (attempt - 1)), this.maxPollRetryDelayMs, remaining), signal);
        }
    }

    async status({ uploadId, signal }) {
        try {
            const response = await this.http.get(this.routes.status(uploadId), { signal });

            return response.data.upload;
        } catch (error) {
            throw normalizeError(error);
        }
    }

    async cancel({ uploadId, signal }) {
        try {
            const response = await this.http.delete(this.routes.cancel(uploadId), { signal });

            return response.data.upload;
        } catch (error) {
            throw normalizeError(error);
        }
    }

    async requestWithRetry(request, signal) {
        for (let attempt = 1; attempt <= this.retryAttempts; attempt++) {
            throwIfAborted(signal);

            try {
                return await request();
            } catch (error) {
                throwIfAborted(signal);

                const normalized = normalizeError(error);

                if (attempt >= this.retryAttempts || !isRetryable(error, normalized)) {
                    throw normalized;
                }

                const delay = Math.min(250 * (2 ** (attempt - 1)), this.maxRetryDelayMs);
                await this.waitForRetry(delay, signal);
            }
        }

        throw clientError('upload_failed', true, 'The upload failed.');
    }

    terminalResult(upload) {
        if (upload.state === 'completed') {
            return upload;
        }

        if (upload.state === 'failed') {
            // Finalization refused the file with a reason the server worded for people (QRAI-06): keep it.
            const reason = refusalReason(upload.failure_reason);
            const error = clientError(upload.failure_code || 'upload_failed', false, reason?.message || 'The upload could not be completed.');

            if (reason) error.reason = reason;

            throw error;
        }

        if (upload.state === 'cancelled') {
            throw clientError('upload_cancelled', false, 'The upload was cancelled.');
        }

        if (upload.state === 'expired') {
            throw clientError('upload_expired', false, 'The upload has expired.');
        }

        return null;
    }
}

/**
 * A server refusal reason, `{ code, message }`, or null when the payload is missing or malformed.
 */
export function refusalReason(value) {
    if (! value || typeof value !== 'object') return null;

    const message = typeof value.message === 'string' ? value.message.trim() : '';

    if (! message) return null;

    return { code: typeof value.code === 'string' && value.code ? value.code : 'refused', message };
}

function clientError(code, retryable, message) {
    const error = new Error(message);
    error.code = code;
    error.retryable = retryable;

    return error;
}

/** The status poll ran out of its time budget: a failure the user can retry, never a cancel. */
function statusTimeout() {
    const error = clientError('status_timeout', true, 'The upload status could not be confirmed in time.');
    error.status = 0;

    return error;
}

function isAbort(error) {
    return error?.name === 'AbortError' || error?.name === 'CanceledError' || error?.code === 'ERR_CANCELED';
}

function normalizeError(error) {
    if (isAbort(error)) {
        return error;
    }

    if (error?.code && typeof error.retryable === 'boolean') {
        return error;
    }

    const data = error?.response?.data;
    const status = Number(error?.response?.status ?? 0);

    const normalized = clientError(
        data?.code || (status >= 500 || status === 0 ? 'network_error' : 'upload_failed'),
        data?.retryable ?? (status >= 500 || status === 0),
        data?.message || error?.message || 'The upload failed.',
    );

    const reason = refusalReason(data?.reason);

    if (reason) normalized.reason = reason;

    // The panel words the failure by status (an expired session, a refusal, a validation reason).
    normalized.status = status;
    normalized.response = error?.response;

    return normalized;
}

function isRetryable(error, normalized) {
    const status = Number(error?.response?.status ?? 0);

    return normalized.retryable === true && (status === 0 || status >= 500);
}

function throwIfAborted(signal) {
    if (signal?.aborted) {
        throw new DOMException('The operation was aborted.', 'AbortError');
    }
}

function abortableWait(delay, signal) {
    return new Promise((resolve, reject) => {
        throwIfAborted(signal);

        const timer = setTimeout(() => {
            signal?.removeEventListener('abort', onAbort);
            resolve();
        }, delay);
        const onAbort = () => {
            clearTimeout(timer);
            reject(new DOMException('The operation was aborted.', 'AbortError'));
        };

        signal?.addEventListener('abort', onAbort, { once: true });
    });
}

function hexDigest(buffer) {
    return [...new Uint8Array(buffer)].map(byte => byte.toString(16).padStart(2, '0')).join('');
}
