import test from 'node:test';
import assert from 'node:assert/strict';
import { pinchFov } from '../../src/Resources/assets/js/pano/pinch.mjs';

test('spreading the fingers zooms in, pinching together zooms out', () => {
    assert.equal(pinchFov({ startFov: 70, startDistance: 100, distance: 200 }), 35);
    assert.equal(pinchFov({ startFov: 60, startDistance: 100, distance: 80 }), 75);
});

test('clamps to the wheel handler range of 30-90', () => {
    assert.equal(pinchFov({ startFov: 70, startDistance: 100, distance: 1000 }), 30);
    assert.equal(pinchFov({ startFov: 70, startDistance: 100, distance: 10 }), 90);
});

test('a degenerate distance leaves the field of view unchanged', () => {
    assert.equal(pinchFov({ startFov: 70, startDistance: 0, distance: 50 }), 70);
    assert.equal(pinchFov({ startFov: 70, startDistance: 50, distance: 0 }), 70);
});
