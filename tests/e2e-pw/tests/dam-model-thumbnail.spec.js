/**
 * Offscreen 3D thumbnail capture against the compiled DAM bundle (ADR 0006).
 *
 * The capture path only exists because nothing on the server can render a
 * model, so the only place it can be proven is a real browser holding a real
 * WebGL context: that the frame reaches a JPEG at the requested size, that the
 * JPEG is a picture of a model rather than 512 squares of background, and that
 * every renderer it opened is released again — a leak here would take the
 * interactive preview down with it once Chromium starts evicting contexts.
 */
const { test, expect } = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');
const http = require('node:http');
const { pathToFileURL } = require('node:url');

const root = path.resolve(__dirname, '../../..');
const build = process.env.DAM_MODEL_BUILD_DIR || path.join(root, 'public/themes/dam/build');
let server, origin, threeMF, stl;

/** A single large red triangle: unmistakable against the viewer's #222833 stage. */
function triangleGLB() {
    const positions = new Float32Array([-1, -1, 0, 1, -1, 0, 0, 1, 0]);
    const normals = new Float32Array([0, 0, 1, 0, 0, 1, 0, 0, 1]);
    const chunks = [Buffer.from(positions.buffer), Buffer.from(normals.buffer)];

    let offset = 0;
    const views = chunks.map(buffer => {
        const view = { buffer: 0, byteOffset: offset, byteLength: buffer.length };
        offset += Math.ceil(buffer.length / 4) * 4;

        return view;
    });

    const bin = Buffer.alloc(offset);
    chunks.forEach((buffer, index) => buffer.copy(bin, views[index].byteOffset));

    const doc = {
        asset: { version: '2.0' }, scene: 0, scenes: [{ nodes: [0] }],
        nodes: [{ name: 'Capture target', mesh: 0 }],
        meshes: [{ primitives: [{ attributes: { POSITION: 0, NORMAL: 1 }, material: 0 }] }],
        materials: [{ pbrMetallicRoughness: { baseColorFactor: [0.9, 0.1, 0.1, 1], metallicFactor: 0, roughnessFactor: 0.7 }, doubleSided: true }],
        accessors: [
            { bufferView: 0, componentType: 5126, count: 3, type: 'VEC3', min: [-1, -1, 0], max: [1, 1, 0] },
            { bufferView: 1, componentType: 5126, count: 3, type: 'VEC3' },
        ],
        bufferViews: views, buffers: [{ byteLength: bin.length }],
    };

    const json = Buffer.from(JSON.stringify(doc));
    const padded = Buffer.alloc(Math.ceil(json.length / 4) * 4, 32);
    json.copy(padded);

    const header = Buffer.alloc(20);
    header.write('glTF');
    header.writeUInt32LE(2, 4);
    header.writeUInt32LE(28 + padded.length + bin.length, 8);
    header.writeUInt32LE(padded.length, 12);
    header.writeUInt32LE(0x4e4f534a, 16);

    const binHeader = Buffer.alloc(8);
    binHeader.writeUInt32LE(bin.length);
    binHeader.writeUInt32LE(0x004e4942, 4);

    return Buffer.concat([header, padded, binHeader, bin]);
}

function png() {
    const crc = bytes => {
        let c = 0xffffffff;
        for (const byte of bytes) { c ^= byte; for (let i = 0; i < 8; i++) c = (c >>> 1) ^ ((c & 1) ? 0xedb88320 : 0); }

        return (c ^ 0xffffffff) >>> 0;
    };
    const chunk = (name, data) => {
        const type = Buffer.from(name), size = Buffer.alloc(4), checksum = Buffer.alloc(4);
        size.writeUInt32BE(data.length);
        checksum.writeUInt32BE(crc(Buffer.concat([type, data])));

        return Buffer.concat([size, type, data, checksum]);
    };
    const header = Buffer.alloc(13);
    header.writeUInt32BE(1, 0);
    header.writeUInt32BE(1, 4);
    header[8] = 8;
    header[9] = 6;

    return Buffer.concat([
        Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]),
        chunk('IHDR', header),
        chunk('IDAT', require('node:zlib').deflateSync(Buffer.from([0, 255, 0, 0, 255]))),
        chunk('IEND', Buffer.alloc(0)),
    ]);
}

/**
 * A textured, animated GLB (mirrors dam-model-viewer.spec.js's animatedGLB
 * fixture): proves a capture works against everything the interactive viewer
 * itself renders, not only the bare-geometry triangle every other test here
 * uses. The animation channel is present but never played -- a capture must
 * still show the model at its bind pose, no different from any other model
 * that has not started animating yet.
 */
function texturedAnimatedGLB() {
    const positions = new Float32Array([-1, -1, 0, 1, -1, 0, 0, 1, 0]);
    const normals = new Float32Array([0, 0, 1, 0, 0, 1, 0, 0, 1]);
    const uv = new Float32Array([0, 0, 1, 0, .5, 1]);
    const times = new Float32Array([0, 1]);
    const translations = new Float32Array([0, 0, 0, 2, 0, 0]);
    const image = png();
    const arrays = [positions, normals, uv, times, translations];
    const chunks = arrays.map(a => Buffer.from(a.buffer));
    chunks.push(image);

    let offset = 0;
    const views = chunks.map(b => {
        const v = { buffer: 0, byteOffset: offset, byteLength: b.length };
        offset += Math.ceil(b.length / 4) * 4;

        return v;
    });

    const bin = Buffer.alloc(offset);
    chunks.forEach((b, i) => b.copy(bin, views[i].byteOffset));

    const doc = {
        asset: { version: '2.0' }, scene: 0, scenes: [{ nodes: [0] }],
        nodes: [{ name: 'Textured animated triangle', mesh: 0 }],
        meshes: [{ primitives: [{ attributes: { POSITION: 0, NORMAL: 1, TEXCOORD_0: 2 }, material: 0 }] }],
        materials: [{ pbrMetallicRoughness: { baseColorTexture: { index: 0 }, metallicFactor: 0, roughnessFactor: 1 }, doubleSided: true }],
        textures: [{ source: 0 }], images: [{ bufferView: 5, mimeType: 'image/png' }],
        animations: [{ name: 'Slide', channels: [{ sampler: 0, target: { node: 0, path: 'translation' } }], samplers: [{ input: 3, output: 4 }] }],
        accessors: [
            { bufferView: 0, componentType: 5126, count: 3, type: 'VEC3', min: [-1, -1, 0], max: [1, 1, 0] },
            { bufferView: 1, componentType: 5126, count: 3, type: 'VEC3' },
            { bufferView: 2, componentType: 5126, count: 3, type: 'VEC2' },
            { bufferView: 3, componentType: 5126, count: 2, type: 'SCALAR', min: [0], max: [1] },
            { bufferView: 4, componentType: 5126, count: 2, type: 'VEC3' },
        ],
        bufferViews: views, buffers: [{ byteLength: bin.length }],
    };

    const json = Buffer.from(JSON.stringify(doc));
    const padded = Buffer.alloc(Math.ceil(json.length / 4) * 4, 32);
    json.copy(padded);

    const header = Buffer.alloc(20);
    header.write('glTF');
    header.writeUInt32LE(2, 4);
    header.writeUInt32LE(28 + padded.length + bin.length, 8);
    header.writeUInt32LE(padded.length, 12);
    header.writeUInt32LE(0x4e4f534a, 16);

    const binHeader = Buffer.alloc(8);
    binHeader.writeUInt32LE(bin.length);
    binHeader.writeUInt32LE(0x004e4942, 4);

    return Buffer.concat([header, padded, binHeader, bin]);
}

let singlePlateThreeMF;

test.beforeAll(async () => {
    const fixtureModule = await import(pathToFileURL(path.join(root, 'packages/Mothership/DAM/tests/js/model-fixture.mjs')).href);
    threeMF = Buffer.from(fixtureModule.fixture());
    stl = Buffer.from(fixtureModule.stlFixture({ triangles: [[[-1, -1, 0], [1, -1, 0], [0, 1, 0]]] }));

    // No Metadata/model_settings.config at all -- the common case for a 3MF
    // exported by something other than a Bambu/Prusa multi-plate project, and
    // the path captureFromViewer's plate-framing fix must leave untouched
    // (viewer.plates is empty, so it frames the whole model exactly as the
    // interactive viewer's own default fit() already does).
    singlePlateThreeMF = Buffer.from(fixtureModule.encode({
        '_rels/.rels': '<Relationships><Relationship Target="/3D/3dmodel.model" Type="http://schemas.microsoft.com/3dmanufacturing/2013/01/3dmodel"/></Relationships>',
        '3D/3dmodel.model': '<model unit="millimeter"><resources><object id="1"><mesh><vertices><vertex x="0" y="0" z="0"/><vertex x="2" y="0" z="0"/><vertex x="0" y="2" z="0"/></vertices><triangles><triangle v1="0" v2="1" v3="2"/></triangles></mesh></object></resources><build><item objectid="1"/></build></model>',
    }));

    const manifest = JSON.parse(fs.readFileSync(path.join(build, 'manifest.json')));
    const entry = manifest['src/Resources/assets/js/app.js'].file;

    server = http.createServer((request, response) => {
        const pathname = new URL(request.url, 'http://localhost').pathname;

        if (pathname === '/') {
            response.setHeader('Content-Type', 'text/html');
            response.end(`<style>html,body{margin:0;height:100%}</style><script type="module" src="/bundle/${entry}"></script>`);

            return;
        }
        if (pathname === '/fixture.glb') { response.end(triangleGLB()); return; }
        if (pathname === '/fixture.3mf') { response.end(threeMF); return; }
        if (pathname === '/fixture.stl') { response.end(stl); return; }
        if (pathname === '/fixture-textured.glb') { response.end(texturedAnimatedGLB()); return; }
        if (pathname === '/fixture-single-plate.3mf') { response.end(singlePlateThreeMF); return; }
        if (pathname === '/huge.glb') {
            response.setHeader('Content-Length', String(1024 * 1024 * 1024 + 1));
            response.end(triangleGLB());

            return;
        }
        let file;
        if (pathname.startsWith('/bundle/')) file = path.join(build, pathname.slice(8));
        if (pathname.startsWith('/themes/dam/build/')) file = path.join(build, pathname.slice('/themes/dam/build/'.length));
        if (/^\/real\/\d+\.(3mf|glb)$/.test(pathname) && process.env.MODEL_SAMPLE_DIR) file = path.join(process.env.MODEL_SAMPLE_DIR, path.basename(pathname));
        if (! file || ! fs.existsSync(file)) { response.statusCode = 404; response.end(); return; }

        response.setHeader('Content-Type', ({ '.js': 'text/javascript', '.css': 'text/css', '.wasm': 'application/wasm' })[path.extname(file)] || 'application/octet-stream');
        fs.createReadStream(file).pipe(response);
    });

    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    origin = `http://127.0.0.1:${server.address().port}`;
});

test.afterAll(async () => { if (server?.listening) await new Promise(resolve => server.close(resolve)); });

/**
 * Count every WebGL context the page opens and every one three.js explicitly
 * loses, so "disposed" is measured rather than assumed.
 */
async function openPage(page) {
    await page.addInitScript(() => {
        window.__gl = { created: 0, lost: 0 };
        const realGetContext = HTMLCanvasElement.prototype.getContext;
        HTMLCanvasElement.prototype.getContext = function (type, ...rest) {
            const context = realGetContext.call(this, type, ...rest);
            if (context && /webgl/i.test(String(type))) {
                window.__gl.created++;
                const realGetExtension = context.getExtension.bind(context);
                context.getExtension = name => {
                    const extension = realGetExtension(name);
                    if (name === 'WEBGL_lose_context' && extension) {
                        return { loseContext: () => { window.__gl.lost++; extension.loseContext(); } };
                    }

                    return extension;
                };
            }

            return context;
        };
    });

    await page.goto(origin);
    await page.waitForFunction(() => window.loadDamModelThumbnails && window.loadDamModelViewer);
}

/** Capture in the page and report the blob plus what its pixels actually look like. */
async function capture(page, { url, extension, size = 512 }) {
    return page.evaluate(async ({ url, extension, size }) => {
        const { captureModelThumbnail } = await window.loadDamModelThumbnails();
        const blob = await captureModelThumbnail({ url, extension, size, timeoutMs: 45000 });

        const bitmap = await createImageBitmap(blob);
        const canvas = document.createElement('canvas');
        canvas.width = bitmap.width;
        canvas.height = bitmap.height;
        canvas.getContext('2d').drawImage(bitmap, 0, 0);
        const { data } = canvas.getContext('2d').getImageData(0, 0, bitmap.width, bitmap.height);

        // The corner is stage background by construction; measure how much of
        // the frame is something else.
        const corner = [data[0], data[1], data[2]];
        let different = 0;
        for (let i = 0; i < data.length; i += 4) {
            if (Math.abs(data[i] - corner[0]) + Math.abs(data[i + 1] - corner[1]) + Math.abs(data[i + 2] - corner[2]) > 30) different++;
        }

        return {
            type: blob.type,
            bytes: blob.size,
            width: bitmap.width,
            height: bitmap.height,
            differentFraction: different / (data.length / 4),
            strayCanvases: document.querySelectorAll('canvas').length,
            strayHosts: document.querySelectorAll('[data-dam-model-viewer]').length,
            gl: { ...window.__gl },
        };
    }, { url, extension, size });
}

test('a synthetic GLB captures to a square JPEG that is a picture of the model', async ({ page }) => {
    await openPage(page);
    const result = await capture(page, { url: '/fixture.glb', extension: 'glb' });

    expect(result.type).toBe('image/jpeg');
    expect(result.width).toBe(512);
    expect(result.height).toBe(512);
    expect(result.bytes).toBeGreaterThan(1000);
    expect(result.differentFraction).toBeGreaterThan(0.05);
    expect(result.strayCanvases).toBe(0);
    expect(result.strayHosts).toBe(0);
});

test('a synthetic STL captures to a square JPEG that is a picture of the model', async ({ page }) => {
    await openPage(page);
    const result = await capture(page, { url: '/fixture.stl', extension: 'stl' });

    expect(result.type).toBe('image/jpeg');
    expect(result.width).toBe(512);
    expect(result.height).toBe(512);
    expect(result.differentFraction).toBeGreaterThan(0.05);
    expect(result.strayCanvases).toBe(0);
    expect(result.strayHosts).toBe(0);
});

test('a Bambu 3MF captures at a requested non-default size', async ({ page }) => {
    await openPage(page);
    const result = await capture(page, { url: '/fixture.3mf', extension: '3mf', size: 256 });

    expect(result.type).toBe('image/jpeg');
    expect(result.width).toBe(256);
    expect(result.height).toBe(256);
    expect(result.differentFraction).toBeGreaterThan(0.02);
});

test('every renderer a run of captures opens is released again', async ({ page }) => {
    await openPage(page);

    const runs = [];
    for (const source of [
        { url: '/fixture.glb', extension: 'glb' },
        { url: '/fixture.3mf', extension: '3mf' },
        { url: '/fixture.glb', extension: 'glb' },
        { url: '/fixture.3mf', extension: '3mf' },
        { url: '/fixture.stl', extension: 'stl' },
    ]) {
        runs.push(await capture(page, source));
    }

    for (const run of runs) {
        expect(run.differentFraction).toBeGreaterThan(0.02);
    }

    const last = runs.at(-1);
    expect(last.gl.created).toBe(runs.length);
    expect(last.gl.lost).toBe(runs.length);
    expect(last.strayCanvases).toBe(0);
    expect(last.strayHosts).toBe(0);
});

test('a model past the viewer download limit is reported as skipped, not captured', async ({ page }) => {
    await openPage(page);

    const outcome = await page.evaluate(async () => {
        const { captureModelThumbnail } = await window.loadDamModelThumbnails();
        try {
            await captureModelThumbnail({ url: '/huge.glb', extension: 'glb', timeoutMs: 20000 });

            return { code: null };
        } catch (error) {
            return { code: error.code, name: error.name, strayHosts: document.querySelectorAll('[data-dam-model-viewer]').length };
        }
    });

    expect(outcome.name).toBe('ModelCaptureError');
    expect(outcome.code).toBe('too-large');
    expect(outcome.strayHosts).toBe(0);
});

test('an unsupported extension never opens a renderer at all', async ({ page }) => {
    await openPage(page);

    const outcome = await page.evaluate(async () => {
        const { captureModelThumbnail } = await window.loadDamModelThumbnails();
        try {
            await captureModelThumbnail({ url: '/fixture.glb', extension: 'obj' });

            return { code: null };
        } catch (error) {
            return { code: error.code, created: window.__gl.created };
        }
    });

    expect(outcome.code).toBe('unsupported');
    expect(outcome.created).toBe(0);
});

test('a textured, animated GLB at its bind pose still captures a picture of the model', async ({ page }) => {
    await openPage(page);
    const result = await capture(page, { url: '/fixture-textured.glb', extension: 'glb' });

    expect(result.type).toBe('image/jpeg');
    expect(result.differentFraction).toBeGreaterThan(0.05);
    expect(result.strayCanvases).toBe(0);
});

test('a single-plate 3MF with no plate metadata captures the whole model, not a stray plate framing', async ({ page }) => {
    await openPage(page);
    const result = await capture(page, { url: '/fixture-single-plate.3mf', extension: '3mf' });

    expect(result.type).toBe('image/jpeg');
    expect(result.differentFraction).toBeGreaterThan(0.05);
    expect(result.strayCanvases).toBe(0);
});

test('an official Draco-compressed GLB sample captures successfully', async ({ page }) => {
    test.skip(
        ! process.env.MODEL_SAMPLE_DIR || ! fs.existsSync(path.join(process.env.MODEL_SAMPLE_DIR, '90001.glb')),
        'Optional official Draco acceptance sample (docs/ops/3d-preview-acceptance.md)',
    );
    await openPage(page);
    const result = await capture(page, { url: '/real/90001.glb', extension: 'glb' });

    expect(result.type).toBe('image/jpeg');
    expect(result.differentFraction).toBeGreaterThan(0.02);
    expect(result.strayCanvases).toBe(0);
});

/**
 * Item 3's fix: a live-view capture must never permanently change the camera
 * the user is looking at, and it must never let anything paint a frame in its
 * temporary "framed square for a capture" state either -- restoration has to
 * happen before the async JPEG encode, not merely by the time it finishes.
 * A portrait viewport (whose automatic fit differs from the capture's own
 * square fit) plus a manually orbited/zoomed camera before the capture runs
 * is the case that used to lose the framing -- cleanup previously restored
 * only pixel ratio, size and plate, never camera position/target/near/far/
 * distance limits.
 *
 * `HTMLCanvasElement.prototype.toBlob` is gated so the async JPEG encode
 * cannot finish until the test releases it -- proving `duringEncode` (state
 * read the instant `captureFromViewer` hands back a still-pending promise,
 * without this test awaiting it yet) already matches `before`, not just that
 * `after` eventually does once the whole call has settled.
 *
 * Every scenario below deliberately keeps the *non-animated* GLB fixture: a
 * playing animation's time label constantly changes toolbar text width,
 * which can wrap the toolbar onto a different number of rows between
 * snapshots -- a real but capture-unrelated source of aspect jitter that
 * would make this assertion flaky for a reason that has nothing to do with
 * the fix under test.
 */
async function runLiveCaptureRestoreCase(page, { url, extension, primaryView = null, selectPlate = null }) {
    return page.evaluate(async ({ url, extension, primaryView, selectPlate }) => {
        let releaseEncode;
        const gate = new Promise(resolve => { releaseEncode = resolve; });
        const realToBlob = HTMLCanvasElement.prototype.toBlob;
        let gated = 0;
        HTMLCanvasElement.prototype.toBlob = function (callback, type, quality) {
            gated++;
            gate.then(() => realToBlob.call(this, callback, type, quality));
        };

        const { ModelViewer } = await window.loadDamModelViewer();
        const host = document.createElement('div');
        host.style.cssText = 'position:fixed;top:0;left:0;width:350px;height:900px';
        document.body.append(host);

        const viewer = new ModelViewer({ container: host, url, extension, primaryView });
        await viewer.ready;

        // Simulates the folder/context-menu backfill or a picker choosing a
        // plate other than the default before a capture ever runs.
        if (selectPlate != null) { viewer.selectedPlate = selectPlate; viewer.applyVisibility(); }

        // A user has orbited and zoomed away from the automatic (or saved
        // primary-view) framing before a capture ever runs -- the live-preview
        // trigger fires right after first render, but nothing guarantees it
        // wins a race against a user who started interacting immediately.
        viewer.camera.position.set(3, 5, 9);
        viewer.controls.target.set(0.5, -0.25, 0.1);
        viewer.controls.minDistance = 0.2;
        viewer.controls.maxDistance = 40;
        viewer.camera.near = 0.05;
        viewer.camera.far = 500;
        viewer.camera.updateProjectionMatrix();
        viewer.controls.update();

        const snapshot = () => ({
            position:      viewer.camera.position.toArray(),
            target:        viewer.controls.target.toArray(),
            aspect:        viewer.camera.aspect,
            near:          viewer.camera.near,
            far:           viewer.camera.far,
            minDistance:   viewer.controls.minDistance,
            maxDistance:   viewer.controls.maxDistance,
            selectedPlate: viewer.selectedPlate,
        });
        const before = snapshot();

        const { captureFromViewer } = await window.loadDamModelThumbnails();
        const capturePromise = captureFromViewer(viewer, { size: 256 });

        // Everything captureFromViewer mutates and restores runs synchronously
        // up to the gated JPEG encode; the instant the call above has merely
        // returned a pending promise -- before this test has awaited it, while
        // the encode is deliberately held open -- the camera must already
        // show the restored state, not the temporary square/single-plate one.
        const duringEncode = snapshot();

        HTMLCanvasElement.prototype.toBlob = realToBlob;
        releaseEncode();
        const blob = await capturePromise;

        const bitmap = await createImageBitmap(blob);
        const canvas = document.createElement('canvas');
        canvas.width = bitmap.width;
        canvas.height = bitmap.height;
        canvas.getContext('2d').drawImage(bitmap, 0, 0);
        const { data } = canvas.getContext('2d').getImageData(0, 0, bitmap.width, bitmap.height);
        const corner = [data[0], data[1], data[2]];
        let different = 0;
        for (let i = 0; i < data.length; i += 4) {
            if (Math.abs(data[i] - corner[0]) + Math.abs(data[i + 1] - corner[1]) + Math.abs(data[i + 2] - corner[2]) > 30) different++;
        }

        const after = snapshot();
        viewer.dispose();
        host.remove();

        return { before, duringEncode, after, gated, differentFraction: different / (data.length / 4), bytes: blob.size };
    }, { url, extension, primaryView, selectPlate });
}

function assertLiveCaptureRestored(result) {
    expect(result.gated).toBe(1);
    expect(result.differentFraction).toBeGreaterThan(0.01);

    for (const snapshotName of ['duringEncode', 'after']) {
        const snapshot = result[snapshotName];

        for (const key of ['position', 'target']) {
            for (let i = 0; i < 3; i++) expect(snapshot[key][i]).toBeCloseTo(result.before[key][i], 6);
        }
        for (const key of ['aspect', 'near', 'far', 'minDistance', 'maxDistance']) {
            expect(snapshot[key]).toBeCloseTo(result.before[key], 6);
        }
        expect(snapshot.selectedPlate).toBe(result.before.selectedPlate);
    }
}

for (const scenario of [
    { name: 'a GLB with a manually adjusted portrait camera', url: '/fixture.glb', extension: 'glb' },
    {
        name: 'a GLB with a saved primary view, further adjusted by the user',
        url: '/fixture.glb', extension: 'glb',
        primaryView: { can_edit: false, view: { distance: 8, aspect: 1.5 } },
    },
    { name: 'a single-plate 3MF with no plate metadata', url: '/fixture-single-plate.3mf', extension: '3mf' },
    { name: 'a multi-plate 3MF with All plates selected', url: '/fixture.3mf', extension: '3mf', selectPlate: 'all' },
    { name: 'a multi-plate 3MF with a non-first plate selected', url: '/fixture.3mf', extension: '3mf', selectPlate: '2' },
]) {
    test(`a live-view capture in a portrait host restores the exact prior camera afterward: ${scenario.name}`, async ({ page }) => {
        await openPage(page);
        const result = await runLiveCaptureRestoreCase(page, scenario);
        assertLiveCaptureRestored(result);
    });
}
