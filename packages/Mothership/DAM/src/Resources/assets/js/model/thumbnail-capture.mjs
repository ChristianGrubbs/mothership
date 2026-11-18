/**
 * Client-side thumbnail capture for 3D models (ADR 0006).
 *
 * The worker image has no GL stack and no Node, so nothing on the server can
 * render a GLB or a 3MF. The browser that already renders the interactive
 * preview does it instead: this module mounts the very same ModelViewer into a
 * hidden 512px host, reads one frame straight out of the drawing buffer, and
 * posts the JPEG to the capture endpoint, which stores it under the ordinary
 * `meta_data['thumbnail_path']` convention every other generated thumbnail uses.
 *
 * Nothing here is imported eagerly: `viewer.mjs` (and with it three.js, the
 * Draco/KTX2 decoders and the viewer stylesheet) is pulled in only when a
 * capture actually runs, so the queue and its policy stay loadable in a plain
 * Node test process.
 */

import { MAX_MODEL_BYTES, TOO_LARGE_MESSAGE } from './limits.mjs';

export const MODEL_CAPTURE_EXTENSIONS = ['glb', '3mf', 'stl'];

/** Mirrors the viewer's own download ceiling (docs/ops/3d-preview-acceptance.md). */
export const MAX_CAPTURE_BYTES = MAX_MODEL_BYTES;

/**
 * Admission ceiling for an OFFSCREEN capture (the backfill queue), which has to load a model and stand up a second
 * renderer just to take a picture. A viewer that is already on screen has paid that cost, so the live capture is not
 * held to it: GLB decoding enforces no triangle ceiling, and the campus models (3.0M and 3.5M triangles) render fine.
 */
export const MAX_CAPTURE_TRIANGLES = 3000000;

export const CAPTURE_SIZE = 512;

/** The pauses before each retry of a capture the server answered 503 to (its lock was busy or unreachable). */
export const BUSY_RETRY_DELAYS_MS = [2000, 5000, 10000];

/** A longer Retry-After than this is not waited out; the capture is tried again on the next open. */
export const MAX_RETRY_AFTER_MS = 15000;

const captureGenerations = new Map();

/**
 * Starts a save for `key` (an asset or a file) and returns its generation. A newer save for the same key makes every older
 * one stale, so an older save still waiting to retry drops itself instead of overwriting the newer capture.
 */
export function nextCaptureGeneration(key) {
    const generation = (captureGenerations.get(key) ?? 0) + 1;
    captureGenerations.set(key, generation);

    return generation;
}

export function isCurrentCaptureGeneration(key, generation) {
    return captureGenerations.get(key) === generation;
}

const sleepFor = ms => new Promise(resolve => setTimeout(resolve, ms));

/** How long to wait before retry `attempt`: the scheduled pause, or the server's Retry-After when that is longer (capped). */
export function busyDelayMs(retryAfter, attempt, delays = BUSY_RETRY_DELAYS_MS) {
    const asked = Number(retryAfter);
    const scheduled = delays[Math.min(attempt, delays.length - 1)];

    return Number.isFinite(asked) && asked > 0 ? Math.min(Math.max(scheduled, asked * 1000), MAX_RETRY_AFTER_MS) : scheduled;
}

/**
 * One save of a model's capture, as the viewer makes it. The generation is taken here, synchronously, before the encode
 * starts, so it orders saves by when they began and not by when their encodes happen to finish: an older save whose
 * encode ends after a newer one has begun is dropped (null), before its first request and after every wait, and cannot
 * suppress the newer save's retry. `current` tells the caller whether the finished save is still the latest one, so it
 * does not announce a picture a newer save is about to replace.
 *
 * @returns {Promise<null|{data: *, current: boolean}>} null when the save was dropped (superseded or stopped)
 */
export async function saveLatestCapture({ key, encode, post, sleep = sleepFor, shouldStop = () => false, delays = BUSY_RETRY_DELAYS_MS }) {
    const generation = nextCaptureGeneration(key);
    const stale = () => shouldStop() || ! isCurrentCaptureGeneration(key, generation);
    const blob = await encode();

    if (stale()) return null;

    try {
        const response = await postWithBusyRetry({ post: () => post(blob), sleep, shouldStop: stale, delays });

        return { data: response?.data, current: isCurrentCaptureGeneration(key, generation) };
    } catch (error) {
        if (stale()) return null;

        throw error;
    }
}

/**
 * Runs `post` and, when the server answers 503 (the capture lock is busy or the cache behind it is down; nothing was
 * written), tries again after a pause: at most `delays.length` retries, each waiting the scheduled pause or Retry-After.
 * Every other outcome, success or error, is the caller's. `shouldStop` ends the waiting (the viewer was closed).
 */
export async function postWithBusyRetry({ post, sleep = sleepFor, shouldStop = () => false, delays = BUSY_RETRY_DELAYS_MS }) {
    for (let attempt = 0; ; attempt++) {
        try {
            return await post();
        } catch (error) {
            const response = error?.response;

            if (response?.status !== 503 || attempt >= delays.length || shouldStop()) throw error;

            const header = response.headers?.get ? response.headers.get('Retry-After') : (response.headers?.['retry-after'] ?? response.headers?.['Retry-After']);
            await sleep(busyDelayMs(header, attempt, delays));

            if (shouldStop()) throw error;
        }
    }
}

const CAPTURE_QUALITY = 0.92;

/** Capture outcomes that mean "this model cannot be captured", not "try again". */
const FAILURE_CODES = new Set(['timeout', 'not-ready', 'encode-failed']);

export class ModelCaptureError extends Error {
    constructor(message, code) {
        super(message);
        this.name = 'ModelCaptureError';
        this.code = code;
    }
}

function log(event, ctx = {}) {
    if (import.meta.env?.VITE_DAM_MODEL_DEBUG !== 'true') return;
    try {
        const key = 'mothership.model-preview.log';
        const line = JSON.stringify({
            ts: new Date().toISOString(), run_id: 'thumbnail-capture',
            level: event.endsWith('error') || event.endsWith('failed') ? 'error' : 'info',
            event, loc: 'dam/model/thumbnail-capture', ctx,
        });
        sessionStorage.setItem(key, [...(sessionStorage.getItem(key) || '').split('\n').filter(Boolean).slice(-99), line].join('\n'));
    } catch { /* Optional diagnostics never block a capture. */ }
}

function withTimeout(promise, timeoutMs, message) {
    if (! (timeoutMs > 0)) return promise;

    let timer = null;

    return Promise.race([
        Promise.resolve(promise).finally(() => clearTimeout(timer)),
        new Promise((_, reject) => {
            timer = setTimeout(() => reject(new ModelCaptureError(message, 'timeout')), timeoutMs);
        }),
    ]);
}

/**
 * Triangles as the GPU sees them: shared 3MF geometry instanced onto five
 * plates costs five draws, so every visible mesh counts separately.
 */
export function countTriangles(root) {
    let total = 0;

    root?.traverseVisible(object => {
        const geometry = object.isMesh ? object.geometry : null;
        const count = geometry?.index?.count ?? geometry?.attributes?.position?.count ?? 0;
        total += Math.floor(count / 3);
    });

    return total;
}

/**
 * The drawing buffer holds the tone-mapped, sRGB-encoded pixels the viewer
 * shows. Reading it with readPixels in the same turn as the render avoids
 * `preserveDrawingBuffer`, which would otherwise have to be switched on for
 * every interactive preview just so a capture could run later.
 */
function drawingBufferToCanvas({ renderer, width, height, documentRef }) {
    const gl = renderer.getContext();
    const pixels = new Uint8Array(width * height * 4);
    gl.readPixels(0, 0, width, height, gl.RGBA, gl.UNSIGNED_BYTE, pixels);

    const canvas = documentRef.createElement('canvas');
    canvas.width = width;
    canvas.height = height;
    const context = canvas.getContext('2d');
    const image = context.createImageData(width, height);
    const stride = width * 4;

    // WebGL's origin is bottom-left and a 2D canvas is top-left.
    for (let row = 0; row < height; row++) {
        image.data.set(pixels.subarray((height - 1 - row) * stride, (height - row) * stride), row * stride);
    }

    context.putImageData(image, 0, 0);

    return canvas;
}

function canvasToBlob(canvas, quality) {
    return new Promise((resolve, reject) => {
        canvas.toBlob(
            blob => blob ? resolve(blob) : reject(new ModelCaptureError('The browser could not encode the capture.', 'encode-failed')),
            'image/jpeg',
            quality,
        );
    });
}

/**
 * Everything captureFromViewer's square, single-plate framing temporarily
 * overwrites on a viewer that might already be on screen: the camera's
 * position and clip planes, the orbit target and its distance limits, the
 * selected plate, and the renderer's pixel ratio. `fit()`/`restorePrimaryCamera()`
 * mutate all of these in place, so a live preview's user-chosen orbit/zoom (or
 * a saved primary view's exact framing) would otherwise be replaced by
 * whatever the capture's own square fit computed, permanently.
 */
function snapshotViewerFraming(viewer) {
    const { camera, controls } = viewer;

    return {
        pixelRatio:  viewer.renderer.getPixelRatio(),
        plate:       viewer.selectedPlate,
        position:    camera.position.clone(),
        near:        camera.near,
        far:         camera.far,
        target:      controls.target.clone(),
        minDistance: controls.minDistance,
        maxDistance: controls.maxDistance,
    };
}

/** Restores exactly what snapshotViewerFraming recorded -- no re-fit, no approximation. */
function restoreViewerFraming(viewer, snapshot, { framePlate }) {
    const { renderer, camera, controls } = viewer;

    renderer.setPixelRatio(snapshot.pixelRatio);

    if (framePlate) {
        viewer.selectedPlate = snapshot.plate;
        viewer.applyVisibility();
    }

    camera.position.copy(snapshot.position);
    camera.near = snapshot.near;
    camera.far = snapshot.far;
    controls.target.copy(snapshot.target);
    controls.minDistance = snapshot.minDistance;
    controls.maxDistance = snapshot.maxDistance;
    controls.update();

    // Recomputes aspect from the pane's real, current size (not a snapshot of
    // it) and applies the restored near/far/position via updateProjectionMatrix
    // + a render -- the same call the interactive viewer already uses whenever
    // its stage changes shape.
    viewer.resize();
}

/**
 * Capture one frame from a viewer that has already loaded — the preview path,
 * where the model is on screen and must never be downloaded a second time.
 */
export async function captureFromViewer(viewer, { size = CAPTURE_SIZE, quality = CAPTURE_QUALITY, documentRef = globalThis.document, maxTriangles = null } = {}) {
    const renderer = viewer?.renderer;

    if (! renderer || viewer.closed || ! viewer.scene || ! viewer.camera) {
        throw new ModelCaptureError('The 3D viewer is not running.', 'not-ready');
    }

    const triangles = countTriangles(viewer.root);

    if (maxTriangles !== null && triangles > maxTriangles) {
        throw new ModelCaptureError(`Model draws ${triangles} triangles, past the ${maxTriangles} capture limit.`, 'too-many-triangles');
    }

    const snapshot = snapshotViewerFraming(viewer);

    // Bambu/Prusa multi-plate 3MFs lay every plate's build items out far apart
    // in one shared coordinate space -- print origins reset per plate, but the
    // file keeps every plate's objects at its own offset so they never overlap
    // if compiled together. Framing the viewer's "all plates" default for a
    // single still shrinks the actual model down to a stray handful of pixels
    // in a mostly-empty frame. A thumbnail is one picture, so it frames the
    // first plate instead -- the same one slicers already use as a project's
    // own icon -- then the interactive "all plates" framing is restored below
    // so a live-preview capture never changes what the viewer shows.
    const framePlate = viewer.plates?.length > 1 && snapshot.plate !== viewer.plates[0].id ? viewer.plates[0].id : null;

    let canvas;

    try {
        if (framePlate) { viewer.selectedPlate = framePlate; viewer.applyVisibility(); }

        // A pixel ratio of 1 makes the drawing buffer exactly size x size, so
        // readPixels needs no rescaling and the output is square regardless of
        // the shape of the pane the viewer happens to occupy.
        renderer.setPixelRatio(1);
        renderer.setSize(size, size, false);
        viewer.camera.aspect = 1;
        viewer.camera.updateProjectionMatrix();
        if (viewer.primaryView?.view) viewer.restorePrimaryCamera(); else viewer.fit();

        renderer.render(viewer.scene, viewer.camera);
        canvas = drawingBufferToCanvas({ renderer, width: size, height: size, documentRef });
    } finally {
        // Everything above is undone here, synchronously, right after the
        // pixels are already read back and before the async JPEG encode below
        // gets a chance to yield to the browser's compositor -- a live preview
        // must never paint even one frame in its temporary "framed square for
        // a capture" state.
        restoreViewerFraming(viewer, snapshot, { framePlate });
    }

    const blob = await canvasToBlob(canvas, quality);
    log('captured', { size, triangles, bytes: blob.size, plate: framePlate });

    return blob;
}

/**
 * Load a model into a hidden host, capture it, and dispose everything.
 *
 * OffscreenCanvas is deliberately not used: the viewer builds its toolbar and
 * status text as real DOM and measures its stage with getBoundingClientRect, so
 * a detached canvas would need a second, divergent copy of the loader.
 */
export async function captureModelThumbnail({
    url,
    extension,
    primaryView = null,
    size = CAPTURE_SIZE,
    timeoutMs = 30000,
    quality = CAPTURE_QUALITY,
    documentRef = globalThis.document,
    loadViewer = () => import('./viewer.mjs'),
} = {}) {
    const format = String(extension || '').toLowerCase();

    if (! MODEL_CAPTURE_EXTENSIONS.includes(format)) {
        throw new ModelCaptureError(`No renderer for .${format} thumbnails.`, 'unsupported');
    }

    const { ModelViewer } = await loadViewer();
    const host = documentRef.createElement('div');
    host.setAttribute('aria-hidden', 'true');
    host.style.cssText = `position:fixed;top:0;left:-20000px;width:${size}px;height:${size}px;opacity:0;pointer-events:none;`;
    documentRef.body.append(host);

    let viewer = null;

    try {
        log('load_start', { format });
        viewer = new ModelViewer({ container: host, url, extension: format, primaryView });
        await withTimeout(viewer.ready, timeoutMs, `Capture timed out after ${timeoutMs}ms.`);

        if (viewer.loadError) {
            throw captureErrorFor(viewer.loadError);
        }

        return await captureFromViewer(viewer, { size, quality, documentRef, maxTriangles: MAX_CAPTURE_TRIANGLES });
    } finally {
        viewer?.dispose();
        host.remove();
    }
}

/** Translate a viewer load failure into a capture outcome the queue can act on. */
function captureErrorFor(error) {
    if (error instanceof ModelCaptureError) return error;

    const message = String(error?.message ?? error);

    if (message.includes(TOO_LARGE_MESSAGE)) return new ModelCaptureError(message, 'too-large');
    if (/triangle|vertex limit/i.test(message)) return new ModelCaptureError(message, 'too-many-triangles');

    return new ModelCaptureError(message, 'load-failed');
}

function defaultCsrfToken() {
    try {
        return globalThis.document?.querySelector('meta[name="csrf-token"]')?.content ?? null;
    } catch {
        return null;
    }
}

/**
 * A bounded, cancellable run over a list of models that need a thumbnail.
 *
 * Concurrency is two because each capture holds a live WebGL context and a set
 * of decoder workers; browsers cap contexts well below the width a naive
 * "capture everything at once" pass would ask for.
 */
export class ModelThumbnailQueue {
    #pending = [];

    #results = [];

    #cancelled = false;

    #counts = { done: 0, skipped: 0, failed: 0 };

    constructor({
        endpoint,
        concurrency = 2,
        timeoutMs = 30000,
        size = CAPTURE_SIZE,
        replace = false,
        capture = captureModelThumbnail,
        fetchImpl = null,
        sleep = sleepFor,
        emitter = null,
        onProgress = null,
        csrfToken,
    } = {}) {
        this.endpoint = endpoint;
        this.concurrency = Math.max(1, concurrency);
        this.timeoutMs = timeoutMs;
        this.size = size;
        this.replace = replace;
        this.capture = capture;
        this.fetchImpl = fetchImpl ?? ((...args) => globalThis.fetch(...args));
        this.sleep = sleep;
        this.emitter = emitter;
        this.onProgress = onProgress;
        this.csrfToken = csrfToken === undefined ? defaultCsrfToken() : csrfToken;
        this.total = 0;
    }

    enqueue(items) {
        for (const item of items ?? []) {
            this.#pending.push({ index: this.total++, item });
            this.#results.push(null);
        }

        return this;
    }

    cancel() {
        this.#cancelled = true;
    }

    get cancelled() {
        return this.#cancelled;
    }

    get results() {
        return this.#results.filter(Boolean);
    }

    get stats() {
        return {
            total:     this.total,
            pending:   this.#pending.length,
            cancelled: this.#cancelled,
            ...this.#counts,
        };
    }

    async run() {
        const workers = Math.max(1, Math.min(this.concurrency, this.#pending.length));
        await Promise.all(Array.from({ length: workers }, () => this.#work()));

        return this.stats;
    }

    async #work() {
        for (;;) {
            if (this.#cancelled) return;
            const next = this.#pending.shift();
            if (! next) return;

            const result = await this.#process(next.item);
            this.#results[next.index] = result;
            this.#counts[result.status]++;

            if (result.status === 'done' && result.url) {
                this.emitter?.emit('dam:thumbnail-updated', { id: result.id, url: result.url });
            }

            this.onProgress?.({ ...this.stats, id: result.id, status: result.status, reason: result.reason ?? null });
        }
    }

    async #process(item) {
        const id = item.id;
        const skip = this.#preflight(item);

        if (skip) {
            log('skipped', { id, reason: skip });

            return { id, status: 'skipped', reason: skip };
        }

        let blob;

        try {
            blob = await withTimeout(
                this.capture({
                    url:         item.url,
                    extension:   item.extension,
                    primaryView: item.primaryView ?? null,
                    size:        this.size,
                    timeoutMs:   this.timeoutMs,
                }),
                this.timeoutMs,
                `Capture timed out after ${this.timeoutMs}ms.`,
            );
        } catch (error) {
            const code = error instanceof ModelCaptureError ? error.code : 'capture-failed';
            log('capture_failed', { id, code });

            return { id, status: FAILURE_CODES.has(code) ? 'failed' : 'skipped', reason: code, message: error?.message };
        }

        return this.#upload(item, blob);
    }

    #preflight(item) {
        if (Number(item.size) > MAX_CAPTURE_BYTES) return 'too-large';
        if (! MODEL_CAPTURE_EXTENSIONS.includes(String(item.extension || '').toLowerCase())) return 'unsupported';
        if (! /^[a-f0-9]{64}$/i.test(String(item.checksum ?? ''))) return 'unbound';

        return null;
    }

    async #upload(item, blob) {
        const id = item.id;
        const url = typeof this.endpoint === 'function' ? this.endpoint(item) : String(this.endpoint).replace('__ID__', id);

        // One retry, and only for a transport-level failure: a 409 (stale
        // checksum) or a 422 (unsupported asset) is the same answer every time,
        // and re-posting a 551-model backfill against them would triple the
        // request count for nothing.
        let busy = 0;

        for (let attempt = 0; attempt < 2; attempt++) {
            let response, body;

            try {
                ({ response, body } = await this.#attemptUpload(url, item, blob));
            } catch (error) {
                if (attempt === 0) continue;
                const reason = error instanceof ModelCaptureError && error.code === 'timeout' ? 'timeout' : 'network';
                log('upload_failed', { id, reason });

                return { id, status: 'failed', reason, message: error?.message };
            }

            // 503 is the server's "busy, nothing written": wait and ask again, as often as the schedule allows.
            if (response.status === 503) {
                if (busy >= BUSY_RETRY_DELAYS_MS.length || this.#cancelled) {
                    return { id, status: 'failed', reason: 'busy', message: 'HTTP 503' };
                }

                await this.sleep(busyDelayMs(response.headers?.get?.('Retry-After'), busy));

                // Cancelled while waiting: nothing more is sent, and the item is not reported as stored.
                if (this.#cancelled) return { id, status: 'skipped', reason: 'cancelled' };

                busy++;
                attempt = -1;

                continue;
            }

            if (response.status >= 500) {
                if (attempt === 0) continue;

                return { id, status: 'failed', reason: 'server', message: `HTTP ${response.status}` };
            }

            if (! response.ok) {
                log('upload_rejected', { id, status: response.status });

                return { id, status: 'failed', reason: 'rejected', message: body.message ?? `HTTP ${response.status}` };
            }

            if (body.skipped) {
                return { id, status: 'skipped', reason: body.skipped, url: body.thumbnail_url ?? null };
            }

            return { id, status: 'done', url: body.thumbnail_url ?? null };
        }

        return { id, status: 'failed', reason: 'network' };
    }

    /**
     * One upload attempt, bound end to end by a single AbortController --
     * one deadline that covers the connect, the response headers and the
     * body read together, not two independent timeouts stacked. A stalled
     * connect and a stalled response body used to have no deadline at all
     * (or, briefly, a second deadline whose own body-read `.catch(() => ({}))`
     * swallowed the abort it was raced against, turning a timed-out read
     * into a "successful" empty body): a request that never settled, or
     * settled but whose body never finished arriving, parked one of the
     * queue's two concurrent workers forever, which Cancel has no way to
     * free since it only stops picking up new items.
     *
     * Only a `SyntaxError` -- a body that arrived and parsed badly -- degrades
     * to `{}`. Every other rejection from the body read (an abort, a network
     * error) is a real failure and propagates, exactly like a rejection from
     * the fetch call itself.
     */
    async #attemptUpload(url, item, blob) {
        const controller = new AbortController();
        let timedOut = false;
        const timer = setTimeout(() => { timedOut = true; controller.abort(); }, this.timeoutMs);

        try {
            const response = await this.fetchImpl(url, {
                method:      'POST',
                credentials: 'same-origin',
                signal:      controller.signal,
                headers:     { 'Accept': 'application/json', ...(this.csrfToken ? { 'X-CSRF-TOKEN': this.csrfToken } : {}) },
                body:        this.#form(item, blob),
            });

            const body = await response.json().catch(error => {
                if (error instanceof SyntaxError) return {};

                throw error;
            });

            return { response, body };
        } catch (error) {
            throw timedOut ? new ModelCaptureError(`Upload timed out after ${this.timeoutMs}ms.`, 'timeout') : error;
        } finally {
            clearTimeout(timer);
        }
    }

    #form(item, blob) {
        const form = new FormData();
        form.append('image', blob, `model-${item.id}.jpg`);
        form.append('checksum', item.checksum);
        if (this.replace) form.append('replace', '1');

        return form;
    }
}
