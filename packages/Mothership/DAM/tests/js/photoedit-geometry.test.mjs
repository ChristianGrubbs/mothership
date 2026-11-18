import { test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

// Geometry has no imports or DOM dependencies; import its exact source without changing package module mode.
const source = readFileSync(new URL("../../src/Resources/assets/js/image/photoedit/core/geometry.js", import.meta.url), "utf8");
const { smoothStroke, limitStrokePoints, appendStrokePoint, objectBounds, quadPoint, arrowControl } = await import(`data:text/javascript;base64,${Buffer.from(source).toString("base64")}`);

test("bounded smoothed and raw strokes retain their complete extent and endpoints", () => {
    const raw = Array.from({ length: 6001 }, (_, i) => [i, i % 2 ? 10 : 0]);
    for (const points of [raw, smoothStroke(raw.slice(0, 2001))]) {
        assert.ok(points.length > 5000);
        const bounded = limitStrokePoints(points);
        assert.equal(bounded.length, 5000);
        assert.deepEqual(bounded[0], points[0]);
        assert.deepEqual(bounded.at(-1), points.at(-1));
        for (let i = 1; i < bounded.length; i++) assert.ok(bounded[i][0] >= bounded[i - 1][0]);
        bounded[0][0] = -1;
        assert.equal(points[0][0], 0, "the live stroke must not alias the sampled points");
    }
});

test("stroke reduction retains isolated extrema and caps live storage without resampling each move", () => {
    const raw = Array.from({ length: 6001 }, (_, i) => [i, 0]);
    raw[1] = [-300, 900];
    raw[5999] = [9000, -700];
    assert.deepEqual(objectBounds({ type: "pencil", points: limitStrokePoints(raw), thickness: 4 }), objectBounds({ type: "pencil", points: raw, thickness: 4 }));
    const live = [[0, 0], [-300, 900]];
    for (let i = 2; i < 25000; i++) {
        const first = live[1];
        const compacting = live.length === 9999;
        appendStrokePoint(live, [i, 0]);
        assert.ok(live.length < 10000);
        if (!compacting) assert.equal(live[1], first, "ordinary moves leave the existing points untouched");
    }
    const bounded = limitStrokePoints(live);
    assert.ok(bounded.length <= 5000);
    assert.deepEqual(bounded.at(-1), [24999, 0]);
    assert.ok(bounded.some(([x, y]) => x === -300 && y === 900));
});

test("curved arrow bounds contain both quadratic extrema, including reversed curves", () => {
    for (const points of [[[0, 0], [100, 100]], [[100, 100], [0, 0]]]) {
        const o = { type: "arrow", style: "curved", points, control: [-100, 200] };
        const b = objectBounds(o);
        assert.ok(Math.abs(b.x + 100 / 3) < 1e-10);
        assert.ok(Math.abs(b.y + b.height - 400 / 3) < 1e-10);
        for (let i = 0; i <= 1000; i++) {
            const [x, y] = quadPoint(points[0], o.control, points[1], i / 1000);
            assert.ok(x >= b.x - 1e-10 && x <= b.x + b.width + 1e-10);
            assert.ok(y >= b.y - 1e-10 && y <= b.y + b.height + 1e-10);
        }
    }
    assert.deepEqual(objectBounds({ type: "arrow", style: "curved", points: [[0, 0], [100, 100]], control: [50, 50] }), { x: 0, y: 0, width: 100, height: 100 });
    for (const control of [[50, 200], [-0.0001, 50], [100.0001, 50], undefined]) {
        const o = { type: "arrow", style: "curved", points: [[0, 0], [100, 100]], ...(control ? { control } : {}) };
        const b = objectBounds(o);
        for (let i = 0; i <= 1000; i++) {
            const [x, y] = quadPoint(o.points[0], arrowControl(o), o.points[1], i / 1000);
            assert.ok(x >= b.x - 1e-10 && x <= b.x + b.width + 1e-10);
            assert.ok(y >= b.y - 1e-10 && y <= b.y + b.height + 1e-10);
        }
    }
});
