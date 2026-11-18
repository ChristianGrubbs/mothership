import { test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

const root = new URL("../../src/Resources/assets/js/image/photoedit/core/", import.meta.url);
const urls = new Map();
function moduleUrl(name) {
    if (!urls.has(name)) {
        const source = readFileSync(new URL(name, root), "utf8").replace(/from "\.\/([^\"]+)"/g, (_, dependency) => `from "${moduleUrl(dependency)}"`);
        urls.set(name, `data:text/javascript;base64,${Buffer.from(source).toString("base64")}`);
    }
    return urls.get(name);
}
const { edgeColor, balanceOffset, renderDocument } = await import(moduleUrl("render.js"));
const { createDocument } = await import(moduleUrl("model.js"));

function pixels(width, height, pixel) {
    const data = new Uint8ClampedArray(width * height * 4);
    for (let y = 0; y < height; y++) for (let x = 0; x < width; x++) data.set(pixel(x, y), (y * width + x) * 4);
    return { width, height, data };
}

const fixtures = [
    { name: "opaque uniform", image: pixels(7, 5, () => [255, 255, 255, 255]), edge: "#ffffff", balance: { dx: 0, dy: 0 } },
    { name: "transparent", image: pixels(7, 5, () => [20, 40, 60, 0]), edge: "#ffffff", balance: { dx: 0, dy: 0 } },
    { name: "asymmetric margins", image: pixels(7, 5, (x, y) => x >= 1 && x <= 2 && y >= 1 && y <= 2 ? [0, 0, 0, 255] : [255, 255, 255, 255]), edge: "#ffffff", balance: { dx: 1.5, dy: 0.5 } },
    // The existing perimeter sampler counts both sides, including their repeated corner pixels (10 white / 12 samples).
    { name: "one column", image: pixels(1, 5, (_, y) => y === 1 ? [0, 0, 0, 255] : [255, 255, 255, 255]), edge: "#d5d5d5", balance: { dx: 0, dy: 0 } },
    { name: "one row", image: pixels(5, 1, (x) => x === 1 ? [0, 0, 0, 255] : [255, 255, 255, 255]), edge: "#d5d5d5", balance: { dx: 0, dy: 0 } },
    { name: "edge alpha 127 ignored", image: pixels(7, 5, () => [20, 40, 60, 127]), edge: "#ffffff", balance: { dx: 0, dy: 0 } },
    { name: "edge alpha 128 included but not opaque", image: pixels(7, 5, () => [20, 40, 60, 128]), edge: "#14283c", balance: { dx: 0, dy: 0 } },
];

for (const fixture of fixtures) {
    test(`combined render analysis preserves ${fixture.name} and reads the image once`, () => {
        const { image } = fixture;
        let analyzed = image;
        let reads = 0;
        const context = new Proxy({ getImageData(x, y, w, h) {
            assert.deepEqual([x, y, w, h], [0, 0, analyzed.width, analyzed.height]);
            reads++;
            return analyzed;
        } }, { get: (target, key) => key in target ? target[key] : () => {} });
        const canvas = () => ({ width: image.width, height: image.height, getContext: () => context });
        assert.equal(edgeColor(canvas()), fixture.edge);
        assert.deepEqual(balanceOffset(canvas(), fixture.edge), fixture.balance);
        const previousDocument = globalThis.document;
        globalThis.document = { createElement: () => canvas() };
        try {
            for (const enabled of [false, true]) for (const autoBalance of [false, true]) for (const inset of [0, 1]) for (const pxScale of [1, 2]) {
                analyzed = pixels(image.width * pxScale, image.height * pxScale, (x, y) => {
                    const start = (Math.floor(y / pxScale) * image.width + Math.floor(x / pxScale)) * 4;
                    return image.data.slice(start, start + 4);
                });
                const standalone = { width: analyzed.width, height: analyzed.height, getContext: () => context };
                const expectedEdge = edgeColor(standalone);
                const expectedBalance = balanceOffset(standalone, expectedEdge);
                const doc = createDocument({ base: "source", width: image.width, height: image.height });
                Object.assign(doc.background, { enabled, type: "solid", autoBalance, inset, padding: 0, shadow: 0 });
                reads = 0;
                const result = renderDocument(doc, new Map(), { pxScale });
                assert.equal(result.edge, enabled && (autoBalance || inset > 0) ? expectedEdge : null);
                assert.deepEqual(result.balance, enabled && autoBalance ? { dx: expectedBalance.dx / pxScale, dy: expectedBalance.dy / pxScale } : { dx: 0, dy: 0 });
                assert.equal(reads, enabled && (autoBalance || inset > 0) ? 1 : 0);
            }
        } finally {
            if (previousDocument === undefined) delete globalThis.document;
            else globalThis.document = previousDocument;
        }
    });
}
