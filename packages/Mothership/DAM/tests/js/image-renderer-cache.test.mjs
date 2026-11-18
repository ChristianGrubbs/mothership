import { test, beforeEach } from "node:test";
import assert from "node:assert/strict";
import {
    createPainter,
    isIdentityAdjustment,
    loadImage,
    releaseImages,
    renderDocument,
} from "../../src/Resources/assets/js/image/renderer.mjs";
import { createDocument } from "../../src/Resources/assets/js/image/document.mjs";

// Minimal canvas stand-in that records the calls the render pipeline makes.
const calls = [];
function fakeContext(canvas) {
    return new Proxy(
        {},
        {
            get: (_, name) =>
                name === "canvas"
                    ? canvas
                    : (...args) => {
                          calls.push([name, ...args]);
                          if (name === "getImageData")
                              return {
                                  width: args[2],
                                  height: args[3],
                                  data: new Uint8ClampedArray(
                                      args[2] * args[3] * 4,
                                  ),
                              };
                      },
            set: () => true,
        },
    );
}
let loads = 0;
beforeEach(() => {
    calls.length = 0;
    loads = 0;
    releaseImages();
    globalThis.document = {
        createElement: () => {
            const canvas = { width: 0, height: 0 };
            canvas.getContext = () => fakeContext(canvas);
            return canvas;
        },
    };
    globalThis.Image = class {
        constructor() {
            this.width = 20;
            this.height = 10;
        }
        async decode() {
            loads++;
        }
    };
});
const resolve = (id) => "/resource/" + id;
const drawsFromSource = () =>
    calls.filter(([name, source]) => name === "drawImage" && source.decode)
        .length;

test("identity adjustments skip the per-pixel readback", () => {
    assert.equal(
        isIdentityAdjustment(createDocument({ base: "b", width: 2, height: 2 }).adjustments),
        true,
    );
    assert.equal(isIdentityAdjustment({ brightness: 1 }), false);
    assert.equal(isIdentityAdjustment({ grayscale: true }), false);
});

test("render reads pixels back only when an adjustment is active", async () => {
    const doc = createDocument({ base: "b", width: 20, height: 10 });
    await renderDocument({ doc, resolve });
    assert.equal(calls.some(([name]) => name === "getImageData"), false);
    doc.adjustments.brightness = 10;
    await renderDocument({ doc, resolve });
    assert.equal(calls.some(([name]) => name === "getImageData"), true);
});

test("source image is fetched once per URL until released", async () => {
    const doc = createDocument({ base: "b", width: 20, height: 10 });
    await renderDocument({ doc, resolve });
    doc.annotations.push({ id: "1", type: "rectangle", x: 1, y: 1, width: 2, height: 2, color: "#000", size: 1 });
    await renderDocument({ doc, resolve });
    assert.equal(loads, 1);
    assert.equal(await loadImage("/resource/b"), await loadImage("/resource/b"));
    releaseImages();
    await renderDocument({ doc, resolve });
    assert.equal(loads, 2);
});

test("failed loads are not cached", async () => {
    globalThis.Image = class {
        async decode() {
            loads++;
            throw Error("network");
        }
    };
    await assert.rejects(loadImage("/x"));
    await assert.rejects(loadImage("/x"));
    assert.equal(loads, 2);
});

test("crop, resize and adjustment stage is reused across annotation and background edits", async () => {
    const doc = createDocument({ base: "b", width: 20, height: 10 });
    doc.adjustments.contrast = 5;
    await renderDocument({ doc, resolve });
    assert.equal(drawsFromSource(), 1);
    doc.annotations.push({ id: "1", type: "arrow", x: 1, y: 1, width: 2, height: 2, color: "#000", size: 1 });
    doc.background.enabled = true;
    doc.rotation = 90;
    doc.adjustments.blur = 3;
    await renderDocument({ doc, resolve });
    assert.equal(drawsFromSource(), 1);
    assert.equal(calls.filter(([name]) => name === "getImageData").length, 1);
    doc.crop = { x: 1, y: 1, width: 10, height: 5 };
    doc.resize = { width: 10, height: 5 };
    await renderDocument({ doc, resolve });
    assert.equal(drawsFromSource(), 2);
    doc.base = "other";
    await renderDocument({ doc, resolve });
    assert.equal(drawsFromSource(), 3);
});

test("painter folds a burst of requests into at most one follow-up render", async () => {
    let renders = 0,
        block = true,
        release;
    const paint = createPainter(async () => {
        renders++;
        if (block) await new Promise((r) => (release = r));
    });
    const first = paint();
    for (let i = 0; i < 50; i++) paint();
    assert.equal(renders, 1);
    release();
    await new Promise((r) => setTimeout(r));
    assert.equal(renders, 2);
    release();
    await first;
    assert.equal(renders, 2);
    block = false;
    await paint();
    assert.equal(renders, 3);
});

test("painter recovers after a failed render", async () => {
    let fail = true;
    const paint = createPainter(async () => {
        if (fail) throw Error("boom");
    });
    await assert.rejects(paint());
    fail = false;
    await paint();
});
