import { test } from "node:test";
import assert from "node:assert/strict";
import {
    adjustPixels,
    makeMask,
} from "../../src/Resources/assets/js/image/renderer.mjs";
test("invert and grayscale preserve alpha", () => {
    const pixels = {
        width: 1,
        height: 1,
        data: new Uint8ClampedArray([100, 150, 200, 128]),
    };
    adjustPixels(pixels, {
        brightness: 0,
        contrast: 0,
        sharpen: 0,
        grayscale: true,
        invert: true,
    });
    assert.equal(pixels.data[0], pixels.data[1]);
    assert.equal(pixels.data[2], pixels.data[1]);
    assert.equal(pixels.data[3], 128);
});
test("mask clears only the selected area at full resolution", () => {
    const calls = [];
    const ctx = {
        fillRect(...x) {
            calls.push(["fill", ...x]);
        },
        clearRect(...x) {
            calls.push(["clear", ...x]);
        },
    };
    const canvas = { getContext: () => ctx };
    makeMask({
        width: 800,
        height: 600,
        selection: { x: 10, y: 20, width: 40, height: 50 },
        canvas,
    });
    assert.deepEqual(calls, [
        ["fill", 0, 0, 800, 600],
        ["clear", 10, 20, 40, 50],
    ]);
    assert.equal(canvas.width, 800);
});
