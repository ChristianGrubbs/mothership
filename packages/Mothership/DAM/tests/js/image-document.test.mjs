import { test } from "node:test";
import assert from "node:assert/strict";
import {
    createDocument,
    geometry,
    History,
    validateDocument,
    hitTest,
} from "../../src/Resources/assets/js/image/document.mjs";
test("rotated cropped output has precise dimensions and background layout", () => {
    const d = createDocument({ base: "base", width: 400, height: 200 });
    d.crop = { x: 10, y: 20, width: 100, height: 50 };
    d.resize = { width: 200, height: 100 };
    d.rotation = 90;
    assert.deepEqual(geometry(d).image, { width: 100, height: 200 });
    d.background.enabled = true;
    d.background.padding = 20;
    d.background.ratio = [1, 1];
    d.background.align = [1, 1];
    const g = geometry(d);
    assert.equal(g.width, g.height);
    assert.equal(g.x + g.drawWidth, g.width - 20);
});
test("history owns isolated snapshots including accepted AI and editable layers", () => {
    const d = createDocument({ base: "base", width: 400, height: 200 });
    const h = new History(d);
    d.annotations.push({
        id: "a",
        type: "text",
        x: 1,
        y: 2,
        width: 30,
        height: 20,
        text: "Hello",
        color: "#ff0000",
        size: 12,
    });
    h.push(d);
    const a = createDocument({ base: "ai", width: 100, height: 100 });
    h.push(a);
    assert.equal(h.undo().annotations[0].text, "Hello");
    assert.equal(h.undo().base, "base");
    assert.equal(h.redo().annotations.length, 1);
    assert.equal(h.redo().base, "ai");
    assert.equal(h.current.annotations.length, 0);
});
test("invalid geometry and unsupported layers fail rather than allocate giant canvases", () => {
    const d = createDocument({ base: "b", width: 400, height: 200 });
    d.crop.width = 401;
    assert.throws(() => validateDocument(d));
    d.crop.width = 400;
    d.background.ratio = [0, 1];
    assert.throws(() => validateDocument(d));
});
test("hit test selects topmost annotation", () => {
    assert.equal(
        hitTest(
            [
                { id: "a", x: 0, y: 0, width: 40, height: 40 },
                { id: "b", x: 5, y: 5, width: 20, height: 20 },
            ],
            10,
            10,
        ).id,
        "b",
    );
});

test("locked drag crop retains its ratio and stays inside source bounds", async () => {
    const { cropFromDrag } = await import(
        "../../src/Resources/assets/js/image/document.mjs"
    );
    assert.equal(typeof cropFromDrag, "function");
    const crop = cropFromDrag({
        start: { x: 50, y: 50 },
        end: { x: 390, y: 190 },
        source: { width: 400, height: 200 },
        ratio: 2,
    });
    assert.equal(crop.width / crop.height, 2);
    assert.ok(crop.x + crop.width <= 400 && crop.y + crop.height <= 200);
});
