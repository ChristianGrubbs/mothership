import assert from 'node:assert/strict';
import test from 'node:test';
import { MAX_CAPTURE_TRIANGLES, ModelCaptureError, captureFromViewer, captureModelThumbnail } from '../../src/Resources/assets/js/model/thumbnail-capture.mjs';

/**
 * A viewer that is already rendering a model of `triangles` triangles, and a document that can encode a canvas. Enough
 * of the renderer surface for captureFromViewer to frame, render, read back and restore.
 */
function fakeViewer(triangles) {
    const vector = () => ({ clone: () => vector(), copy() {} });
    const renders = [];
    const mesh = { isMesh: true, geometry: { index: null, attributes: { position: { count: triangles * 3 } } } };
    const viewer = {
        closed: false,
        scene: {},
        camera: { aspect: 1.5, near: 0.1, far: 100, position: vector(), updateProjectionMatrix() {} },
        controls: { target: vector(), minDistance: 0, maxDistance: 10, update() {} },
        root: { traverseVisible: visit => visit(mesh) },
        renderer: {
            getPixelRatio: () => 2, setPixelRatio() {}, setSize() {},
            render: () => renders.push('render'),
            getContext: () => ({ RGBA: 1, UNSIGNED_BYTE: 2, readPixels() {} }),
        },
        fit() {}, resize() {}, applyVisibility() {},
    };

    return { viewer, renders };
}

const documentRef = {
    createElement: () => ({
        getContext: () => ({ createImageData: (w, h) => ({ data: new Uint8ClampedArray(w * h * 4) }), putImageData() {} }),
        toBlob: callback => callback(new Blob(['jpeg'])),
    }),
};

const OVER = MAX_CAPTURE_TRIANGLES + 34802;

test('a viewer that is already rendering a model past the offscreen ceiling still supplies its capture', async () => {
    const { viewer, renders } = fakeViewer(OVER);

    const blob = await captureFromViewer(viewer, { size: 64, documentRef });

    assert.equal(blob.size, 4);
    assert.deepEqual(renders, ['render']);
});

test('the offscreen backfill keeps refusing a model past the admission ceiling, before it renders anything', async () => {
    const { viewer, renders } = fakeViewer(OVER);
    const host = { setAttribute() {}, style: {}, remove() {} };
    const offscreen = {
        createElement: () => host,
        body: { append() {} },
    };
    const loadViewer = async () => ({ ModelViewer: class { constructor() { Object.assign(this, viewer); this.ready = Promise.resolve(); } dispose() {} } });

    await assert.rejects(
        captureModelThumbnail({ url: '/m.glb', extension: 'glb', documentRef: offscreen, loadViewer }),
        error => error instanceof ModelCaptureError && error.code === 'too-many-triangles',
    );
    assert.deepEqual(renders, []);
});

test('a live caller can still opt into a ceiling', async () => {
    const { viewer } = fakeViewer(OVER);

    await assert.rejects(captureFromViewer(viewer, { documentRef, maxTriangles: MAX_CAPTURE_TRIANGLES }), error => error.code === 'too-many-triangles');
});
