// Cases for ImageEditorSchema's schema v2 (PHP), computed by PhotoEdit's own validateDocument() and outputSize().
// The PHP test reads tests/Fixtures/photoedit/schema-v2-cases.json and expects the same verdict and the same
// pixel size for every case; photoedit-schema-fixture.test.mjs fails when that file no longer matches what
// this module computes. Regenerate it after changing the vendored photoedit/core or this list:
//   node packages/Mothership/DAM/tests/js/photoedit-schema-cases.mjs --write
import { copyFileSync, mkdtempSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import path from "node:path";
import { fileURLToPath, pathToFileURL } from "node:url";

const here = path.dirname(fileURLToPath(import.meta.url));
export const FIXTURE = path.join(here, "../Fixtures/photoedit/schema-v2-cases.json");
const CORE = path.join(here, "../../src/Resources/assets/js/image/photoedit/core");

/** The vendored files themselves, copied byte for byte beside a package.json that makes them ES modules. */
async function loadCore() {
    const dir = mkdtempSync(path.join(tmpdir(), "photoedit-core-"));
    writeFileSync(path.join(dir, "package.json"), '{"type":"module"}');
    for (const file of ["model.js", "geometry.js"]) copyFileSync(path.join(CORE, file), path.join(dir, file));
    const model = await import(pathToFileURL(path.join(dir, "model.js")).href);
    const geometry = await import(pathToFileURL(path.join(dir, "geometry.js")).href);
    return { validateDocument: model.validateDocument, outputSize: geometry.outputSize };
}

const BASE = "11111111-1111-4111-8111-111111111111";
const BACKGROUND = "22222222-2222-4222-8222-222222222222";
const PICTURE = "33333333-3333-4333-8333-333333333333";

const doc = () => ({
    schemaVersion: 2,
    base: BASE,
    sourceWidth: 400,
    sourceHeight: 300,
    crop: { x: 0, y: 0, width: 400, height: 300 },
    resize: { width: 400, height: 300 },
    rotation: 0,
    flipX: false,
    flipY: false,
    adjustments: { brightness: 0, contrast: 0, sharpen: 0, blur: 0, grayscale: false, invert: false },
    canvasColor: null,
    objectShadow: true,
    counterStart: 1,
    counterStyle: "numbers",
    objects: [],
    background: {
        enabled: false, type: "gradient", color: "#ffffff", gradient: ["#ff7aa8", "#7b5cff"], angle: 135, image: null,
        padding: 64, inset: 0, autoBalance: false, shadow: 30, corners: 20, alignment: 4, ratio: null,
    },
});

const OBJECTS = {
    rect: { id: "o1", type: "rect", x: 1, y: 2, width: 30, height: 40, color: "#f93442", thickness: 6 },
    "filled-rect": { id: "o1", type: "filled-rect", x: 1, y: 2, width: 30, height: 40, color: "#f93442" },
    ellipse: { id: "o1", type: "ellipse", x: 1, y: 2, width: 30, height: 40, color: "#f93442", thickness: 6 },
    line: { id: "o1", type: "line", points: [[0, 0], [10, 10]], color: "#f93442", thickness: 6 },
    arrow: { id: "o1", type: "arrow", points: [[0, 0], [10, 10]], control: [5, 0], color: "#f93442", thickness: 6, style: "curved" },
    text: { id: "o1", type: "text", x: 5, y: 6, text: "Hello", color: "#f93442", fontSize: 30, style: "outline" },
    pixelate: { id: "o1", type: "pixelate", x: 1, y: 2, width: 30, height: 40, style: "secure-blur", intensity: 30, seed: 7 },
    spotlight: { id: "o1", type: "spotlight", x: 1, y: 2, width: 30, height: 40, shape: "ellipse", opacity: 45 },
    counter: { id: "o1", type: "counter", x: 5, y: 6, color: "#f93442", size: 32 },
    pencil: { id: "o1", type: "pencil", points: [[0, 0], [3, 4], [6, 8]], color: "#f93442", thickness: 6 },
    highlighter: { id: "o1", type: "highlighter", points: [[0, 0], [3, 4]], color: "#ffe100", thickness: 24 },
    image: { id: "o1", type: "image", x: 1, y: 2, width: 30, height: 40, resource: PICTURE },
};

const withObject = (o) => {
    const d = doc();
    d.objects = [o];
    return d;
};
const clone = (v) => JSON.parse(JSON.stringify(v));
/** Set `path` (dot-separated; "-" deletes) on a copy of `d`. */
function edit(d, p, value) {
    const copy = clone(d);
    const parts = p.split(".");
    const last = parts.pop();
    let node = copy;
    for (const part of parts) node = node[part];
    if (value === undefined) delete node[last];
    else node[last] = value;
    return copy;
}

const WRONG = { string: "1", bool: true, null: null, object: {} };

function* scalarPaths(value, prefix = "") {
    for (const [k, v] of Object.entries(value)) {
        const p = prefix ? `${prefix}.${k}` : k;
        if (v !== null && typeof v === "object" && !Array.isArray(v)) yield* scalarPaths(v, p);
        else yield p;
    }
}

/** A seeded generator, so the dimension cases are the same on every run. */
function mulberry32(seed) {
    return () => {
        seed = (seed + 0x6d2b79f5) | 0;
        let t = Math.imul(seed ^ (seed >>> 15), 1 | seed);
        t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
}

export function rawCases() {
    /** @type {{name:string,json:string}[]} */
    const cases = [];
    const add = (name, value) => cases.push({ name, json: typeof value === "string" ? value : JSON.stringify(value) });

    // Valid documents
    add("valid: empty document", doc());
    for (const [type, o] of Object.entries(OBJECTS)) add(`valid: ${type} object`, withObject(o));
    add("valid: every object type at once", { ...doc(), objects: Object.values(OBJECTS).map((o, i) => ({ ...o, id: `o${i}` })) });
    add("valid: arrow without control", withObject(edit(OBJECTS.arrow, "control", undefined)));
    add("valid: arrow with null control", withObject({ ...OBJECTS.arrow, control: null }));
    add("valid: pixelate without seed", withObject(edit(OBJECTS.pixelate, "seed", undefined)));
    add("valid: 8-digit and uppercase hex", withObject({ ...OBJECTS.rect, color: "#F9344280" }));
    add("valid: canvasColor hex", { ...doc(), canvasColor: "#00000000" });
    add("valid: image background", { ...doc(), background: { ...doc().background, enabled: true, type: "image", image: BACKGROUND } });
    add("valid: three gradient stops", edit(doc(), "background.gradient", ["#000000", "#808080", "#ffffff"]));
    add("valid: background image kept while another type is chosen", edit(doc(), "background.image", BACKGROUND));
    add("valid: cropped beyond the source", { ...doc(), crop: { x: -100.5, y: -20, width: 900.25, height: 700 }, resize: { width: 900, height: 700 } });

    // Required keys: every one, at every level
    const full = { ...doc(), objects: [] };
    for (const p of scalarPaths(full)) add(`missing: ${p}`, edit(full, p, undefined));
    for (const type of Object.keys(OBJECTS)) {
        for (const key of Object.keys(OBJECTS[type])) {
            add(`missing: ${type}.${key}`, withObject(edit(OBJECTS[type], key, undefined)));
        }
    }

    // Unknown keys
    for (const p of ["", "crop.", "resize.", "adjustments.", "background."]) add(`unknown key: ${p || "document."}extra`, edit(doc(), `${p}extra`, 1));
    for (const type of Object.keys(OBJECTS)) add(`unknown key: ${type}.extra`, withObject({ ...OBJECTS[type], extra: 1 }));
    add("unknown key: control on a rect", withObject({ ...OBJECTS.rect, control: [0, 0] }));
    add("unknown key: seed on a text", withObject({ ...OBJECTS.text, seed: 1 }));

    // Wrong JSON types for every scalar
    for (const p of scalarPaths(full)) {
        for (const [kind, value] of Object.entries(WRONG)) {
            if (p === "canvasColor" && kind === "null") continue; // null is the valid value
            add(`type: ${p} as ${kind}`, edit(full, p, value));
        }
    }
    for (const type of Object.keys(OBJECTS)) {
        for (const key of Object.keys(OBJECTS[type])) {
            if (key === "type") continue;
            for (const [kind, value] of Object.entries(WRONG)) {
                if (kind === "null" && (key === "control")) continue; // null control is accepted
                add(`type: ${type}.${key} as ${kind}`, withObject({ ...OBJECTS[type], [key]: value }));
            }
        }
    }
    add("type: objects as {}", '{"schemaVersion":2,"base":"' + BASE + '","sourceWidth":400,"sourceHeight":300,"crop":{"x":0,"y":0,"width":400,"height":300},"resize":{"width":400,"height":300},"rotation":0,"flipX":false,"flipY":false,"adjustments":{"brightness":0,"contrast":0,"sharpen":0,"blur":0,"grayscale":false,"invert":false},"canvasColor":null,"objectShadow":true,"counterStart":1,"counterStyle":"numbers","objects":{},"background":{"enabled":false,"type":"gradient","color":"#ffffff","gradient":["#ff7aa8","#7b5cff"],"angle":135,"image":null,"padding":64,"inset":0,"autoBalance":false,"shadow":30,"corners":20,"alignment":4,"ratio":null}}');
    add("type: gradient as {}", edit(doc(), "background.gradient", {}));
    add("type: points as {}", withObject({ ...OBJECTS.line, points: {} }));
    add("type: schemaVersion 3", edit(doc(), "schemaVersion", 3));
    add("type: numbers as numeric strings", edit(doc(), "background.padding", "64"));

    // Enums
    add("enum: counterStyle", edit(doc(), "counterStyle", "hex"));
    add("enum: background.type", edit(doc(), "background.type", "upload"));
    add("enum: rotation 45", edit(doc(), "rotation", 45));
    add("enum: rotation -90", edit(doc(), "rotation", -90));
    add("enum: object type", withObject({ ...OBJECTS.rect, type: "highlight" }));
    add("enum: arrow style", withObject({ ...OBJECTS.arrow, style: "wavy" }));
    add("enum: text style", withObject({ ...OBJECTS.text, style: "script" }));
    add("enum: pixelate style", withObject({ ...OBJECTS.pixelate, style: "mosaic" }));
    add("enum: spotlight shape", withObject({ ...OBJECTS.spotlight, shape: "star" }));
    for (const style of ["standard", "fancy", "curved", "double"]) add(`enum ok: arrow ${style}`, withObject({ ...OBJECTS.arrow, style }));
    for (const style of ["standard", "outline", "box", "rounded", "rounded-box", "mono", "mono-box"]) add(`enum ok: text ${style}`, withObject({ ...OBJECTS.text, style }));
    for (const style of ["pixelate", "blur", "secure-blur", "blackout"]) add(`enum ok: pixelate ${style}`, withObject({ ...OBJECTS.pixelate, style }));
    for (const type of ["solid", "gradient", "image", "blurred", "transparent"]) add(`enum ok: background ${type}`, edit(doc(), "background.type", type));
    for (const style of ["numbers", "letters", "roman"]) add(`enum ok: counter ${style}`, edit(doc(), "counterStyle", style));
    add("enum: image background enabled without an image", { ...doc(), background: { ...doc().background, enabled: true, type: "image", image: null } });

    // Hex colours
    const colourSites = [
        ["background.color", (v) => edit(doc(), "background.color", v)],
        ["canvasColor", (v) => edit(doc(), "canvasColor", v)],
        ["gradient stop", (v) => edit(doc(), "background.gradient", ["#000000", v])],
        ["rect.color", (v) => withObject({ ...OBJECTS.rect, color: v })],
    ];
    for (const [site, build] of colourSites) {
        for (const bad of ["#fff", "#ggg000", "red", "#1234567", "#12345678a", "#ffffff\n", " #ffffff", "ffffff", ""]) add(`hex: ${site} ${JSON.stringify(bad)}`, build(bad));
        for (const ok of ["#ffffff", "#FFFFFF", "#ffffff00", "#aBcDeF12"]) add(`hex ok: ${site} ${ok}`, build(ok));
    }

    // Numeric bounds: [path, low, high, integer?]
    const bounds = [
        ["sourceWidth", 1, 8192, true], ["sourceHeight", 1, 8192, true],
        ["crop.x", -8192, 8192, false], ["crop.y", -8192, 8192, false], ["crop.width", 1, 16384, false], ["crop.height", 1, 16384, false],
        ["resize.width", 1, 8192, true], ["resize.height", 1, 8192, true],
        ["adjustments.brightness", -100, 100, false], ["adjustments.contrast", -100, 100, false], ["adjustments.sharpen", 0, 100, false], ["adjustments.blur", 0, 30, false],
        ["counterStart", 0, 9999, true],
        ["background.angle", 0, 360, false], ["background.padding", 0, 1000, true], ["background.inset", 0, 500, true],
        ["background.shadow", 0, 100, false], ["background.corners", 0, 100, false], ["background.alignment", 0, 8, true],
    ];
    for (const [p, lo, hi, integer] of bounds) {
        for (const v of [lo, hi]) add(`bound ok: ${p}=${v}`, edit(doc(), p, v));
        for (const v of [lo - 1, hi + 1, lo - 0.001, hi + 0.001]) add(`bound: ${p}=${v}`, edit(doc(), p, v));
        if (integer) add(`integer: ${p}=1.5`, edit(doc(), p, 1.5));
    }
    const objectBounds = [
        ["rect", "x", -32768, 32768], ["rect", "y", -32768, 32768], ["rect", "width", 0, 32768], ["rect", "height", 0, 32768], ["rect", "thickness", 1, 200],
        ["text", "x", -32768, 32768], ["text", "y", -32768, 32768], ["text", "fontSize", 4, 1000],
        ["counter", "size", 8, 400], ["counter", "x", -32768, 32768],
        ["pixelate", "intensity", 1, 100], ["pixelate", "seed", 0, 2 ** 31], ["spotlight", "opacity", 0, 100],
    ];
    for (const [type, key, lo, hi] of objectBounds) {
        for (const v of [lo, hi]) add(`bound ok: ${type}.${key}=${v}`, withObject({ ...OBJECTS[type], [key]: v }));
        for (const v of [lo - 1, hi + 1]) add(`bound: ${type}.${key}=${v}`, withObject({ ...OBJECTS[type], [key]: v }));
    }
    for (const v of [-32768, 32768]) add(`bound ok: line point ${v}`, withObject({ ...OBJECTS.line, points: [[v, v], [0, 0]] }));
    for (const v of [-32769, 32769]) add(`bound: line point ${v}`, withObject({ ...OBJECTS.line, points: [[v, 0], [0, 0]] }));
    add("bound: arrow control out of range", withObject({ ...OBJECTS.arrow, control: [40000, 0] }));

    // Counts and lengths
    const counters = (n) => Array.from({ length: n }, (_, i) => ({ id: `c${i}`, type: "counter", x: i, y: 0, color: "#000000", size: 8 }));
    add("limit ok: 500 objects", { ...doc(), objects: counters(500) });
    add("limit: 501 objects", { ...doc(), objects: counters(501) });
    const stroke = (n) => ({ ...OBJECTS.pencil, points: Array.from({ length: n }, (_, i) => [i % 100, 0]) });
    add("limit ok: 5000 points", withObject(stroke(5000)));
    add("limit: 5001 points", withObject(stroke(5001)));
    add("limit: 0 points", withObject({ ...OBJECTS.pencil, points: [] }));
    add("limit: 3 line points", withObject({ ...OBJECTS.line, points: [[0, 0], [1, 1], [2, 2]] }));
    add("limit ok: 10000 characters", withObject({ ...OBJECTS.text, text: "a".repeat(10000) }));
    add("limit: 10001 characters", withObject({ ...OBJECTS.text, text: "a".repeat(10001) }));
    add("limit ok: 5000 emoji (10000 UTF-16 units)", withObject({ ...OBJECTS.text, text: "😀".repeat(5000) }));
    add("limit: 5001 emoji (10002 UTF-16 units)", withObject({ ...OBJECTS.text, text: "😀".repeat(5001) }));
    add("limit ok: empty text", withObject({ ...OBJECTS.text, text: "" }));
    add("limit ok: 64-character id", withObject({ ...OBJECTS.rect, id: "i".repeat(64) }));
    add("limit: 65-character id", withObject({ ...OBJECTS.rect, id: "i".repeat(65) }));
    add("limit ok: 32 emoji id (64 UTF-16 units)", withObject({ ...OBJECTS.rect, id: "😀".repeat(32) }));
    add("limit: 33 emoji id (66 UTF-16 units)", withObject({ ...OBJECTS.rect, id: "😀".repeat(33) }));
    add("limit: empty id", withObject({ ...OBJECTS.rect, id: "" }));
    add("limit: duplicate ids", { ...doc(), objects: [OBJECTS.rect, { ...OBJECTS.ellipse, id: "o1" }] });
    add("limit: empty base", edit(doc(), "base", ""));
    add("limit: background image over 128 characters", edit(doc(), "background.image", "x".repeat(129)));
    add("limit: image resource over 128 characters", withObject({ ...OBJECTS.image, resource: "x".repeat(129) }));
    add("limit: empty image resource", withObject({ ...OBJECTS.image, resource: "" }));

    // Aspect ratio: w/h within [1/20, 20]
    for (const [r, label] of [[[1, 20], "1:20"], [[20, 1], "20:1"], [[16, 9], "16:9"], [[0.05, 1], "0.05:1"], [[1, 1], "1:1"]]) add(`ratio ok: ${label}`, edit(doc(), "background.ratio", r));
    for (const [r, label] of [[[1, 21], "1:21"], [[21, 1], "21:1"], [[0.005, 1], "0.005:1"], [[1, 0], "1:0"], [[1, 20000], "1:20000"], [[1, 1, 1], "three values"], [[1], "one value"], ["1:1", "a string"], [["1", "1"], "numeric strings"]]) add(`ratio: ${label}`, edit(doc(), "background.ratio", r));

    // Allocation budgets
    const sized = (patch) => ({ ...doc(), ...patch });
    add("budget: source 8192x8192", sized({ sourceWidth: 8192, sourceHeight: 8192, crop: { x: 0, y: 0, width: 8192, height: 8192 }, resize: { width: 100, height: 100 } }));
    add("budget ok: source 8192x3906 (31.99 MP)", sized({ sourceWidth: 8192, sourceHeight: 3906, crop: { x: 0, y: 0, width: 8192, height: 3906 }, resize: { width: 100, height: 100 } }));
    add("budget: crop 16384x4000 (65.5 MP)", sized({ crop: { x: 0, y: 0, width: 16384, height: 4000 } }));
    add("budget ok: crop 16384x3900 (63.9 MP)", sized({ crop: { x: 0, y: 0, width: 16384, height: 3900 } }));
    add("budget: resize 8192x4000 (32.8 MP)", sized({ resize: { width: 8192, height: 4000 } }));
    add("budget ok: resize 8000x4000 (32 MP)", sized({ resize: { width: 8000, height: 4000 } }));
    const framed = (patch) => ({ ...doc(), background: { ...doc().background, enabled: true, ...patch } });
    add("budget: output over 64 MP by padding and inset", { ...framed({ padding: 1000, inset: 500 }), resize: { width: 5600, height: 5600 } });
    add("budget ok: output at 61 MP by padding and inset", { ...framed({ padding: 1000, inset: 500 }), resize: { width: 5000, height: 5000 } });
    add("budget: output side over 16384 by a 20:1 ratio", { ...framed({ padding: 1000, ratio: [20, 1] }), resize: { width: 8192, height: 1 } });
    add("budget: output side over 16384 by a 1:20 ratio", { ...framed({ padding: 1000, ratio: [1, 20] }), resize: { width: 1, height: 8192 } });
    add("budget ok: 16380 wide by a 20:1 ratio", { ...framed({ padding: 0, ratio: [20, 1] }), resize: { width: 8192, height: 819 } });
    add("budget: 16400 wide by a 20:1 ratio", { ...framed({ padding: 0, ratio: [20, 1] }), resize: { width: 8192, height: 820 } });
    add("budget: 16400 tall by a 1:20 ratio", { ...framed({ padding: 0, ratio: [1, 20] }), resize: { width: 820, height: 8192 } });
    add("budget: rotated output over the side limit", { ...framed({ padding: 1000, ratio: [20, 1] }), rotation: 90, resize: { width: 1, height: 8192 } });
    add("budget ok: background disabled ignores padding", { ...doc(), background: { ...doc().background, padding: 1000, inset: 500, ratio: [20, 1] }, resize: { width: 8192, height: 3900 } });

    // JSON objects where a list belongs: a browser keeps them objects, PHP's decoder turns {"0":…} into a list
    const keyed = (list) => `{${list.map((v, i) => `"${i}":${JSON.stringify(v)}`).join(",")}}`;
    const swap = (value, find, replace) => JSON.stringify(value).replace(find, replace);
    add("object as list: line points", swap(withObject(OBJECTS.line), '"points":[[0,0],[10,10]]', `"points":${keyed([[0, 0], [10, 10]])}`));
    add("object as list: pencil points", swap(withObject(OBJECTS.pencil), '"points":[[0,0],[3,4],[6,8]]', `"points":${keyed([[0, 0], [3, 4], [6, 8]])}`));
    add("object as list: a point", swap(withObject(OBJECTS.line), '[[0,0],[10,10]]', `[${keyed([0, 0])},[10,10]]`));
    add("object as list: arrow control", swap(withObject(OBJECTS.arrow), '"control":[5,0]', `"control":${keyed([5, 0])}`));
    add("object as list: gradient", swap(doc(), '"gradient":["#ff7aa8","#7b5cff"]', `"gradient":${keyed(["#ff7aa8", "#7b5cff"])}`));
    add("object as list: ratio", swap(doc(), '"ratio":null', `"ratio":${keyed([1, 1])}`));
    add("object as list: objects", swap(withObject(OBJECTS.rect), /"objects":\[(.*)\],"background"/, '"objects":{"0":$1},"background"'));
    add("object as list: empty objects", swap(doc(), '"objects":[]', '"objects":{}'));
    add("object as list: empty gradient", swap(doc(), '"gradient":["#ff7aa8","#7b5cff"]', '"gradient":{}'));

    // Resource ids the editor's schema allows but this server's uuid keys cannot match (the controller refuses them)
    add("resource id: empty background image", edit(doc(), "background.image", ""));
    add("resource id: zero as an image resource", withObject({ ...OBJECTS.image, resource: "0" }));
    add("resource id: not a uuid as the base", edit(doc(), "base", "../../other.png"));

    // Spellings a browser reads as plain numbers
    const compact = JSON.stringify(doc());
    add("spelling: padding 64.0", compact.replace('"padding":64', '"padding":64.0'));
    add("spelling: schemaVersion 2.0", compact.replace('"schemaVersion":2', '"schemaVersion":2.0'));
    add("spelling: rotation 90.0", compact.replace('"rotation":0', '"rotation":90.0'));
    add("spelling: sourceWidth 4e2", compact.replace('"sourceWidth":400', '"sourceWidth":4e2'));
    add("spelling: brightness 1e999", compact.replace('"brightness":0', '"brightness":1e999'));
    add("spelling: width 400.5 as an integer", compact.replace('"resize":{"width":400', '"resize":{"width":400.5'));
    add("spelling: not an object", "[]");
    add("spelling: scalar", "2");

    // Output size: hand-picked rounding cases, then a seeded sweep
    const dims = (resize, rotation, background) => ({ ...doc(), resize, rotation, background: { ...doc().background, ...background } });
    add("size: rotation swaps the resized image", dims({ width: 300, height: 200 }, 90, { enabled: false }));
    add("size: 270 swaps too", dims({ width: 301, height: 200 }, 270, { enabled: false }));
    add("size: 180 does not swap", dims({ width: 301, height: 200 }, 180, { enabled: false }));
    add("size: padding only", dims({ width: 300, height: 200 }, 0, { enabled: true, padding: 25, inset: 0 }));
    add("size: inset is inside the padding", dims({ width: 300, height: 200 }, 0, { enabled: true, padding: 25, inset: 10 }));
    add("size: alignment does not change the size", dims({ width: 300, height: 200 }, 0, { enabled: true, padding: 25, inset: 10, ratio: [1, 1], alignment: 8 }));
    add("size: half rounds up wide", dims({ width: 20, height: 101 }, 0, { enabled: true, padding: 0, inset: 0, ratio: [1, 2] }));
    add("size: half rounds up tall", dims({ width: 101, height: 20 }, 0, { enabled: true, padding: 0, inset: 0, ratio: [2, 1] }));
    add("size: rotated with ratio", dims({ width: 20, height: 101 }, 90, { enabled: true, padding: 7, inset: 3, ratio: [4, 3] }));
    add("size: ratio already satisfied", dims({ width: 400, height: 300 }, 0, { enabled: true, padding: 0, inset: 0, ratio: [4, 3] }));
    add("size: ratio with decimals", dims({ width: 333, height: 777 }, 0, { enabled: true, padding: 13, inset: 5, ratio: [0.3, 0.7] }));
    const rand = mulberry32(20261013);
    const pick = (list) => list[Math.floor(rand() * list.length)];
    const int = (lo, hi) => lo + Math.floor(rand() * (hi - lo + 1));
    const ratios = [null, [1, 1], [16, 9], [9, 16], [4, 3], [3, 2], [5, 4], [7, 5], [3, 7], [1, 20], [20, 1], [0.3, 0.7], [123, 457], [2.5, 1]];
    for (let i = 0; i < 160; i++) {
        const width = int(1, 3000);
        const height = int(1, 3000);
        const d = {
            ...doc(),
            sourceWidth: width, sourceHeight: height,
            crop: { x: 0, y: 0, width, height },
            resize: { width: int(1, 2500), height: int(1, 2500) },
            rotation: pick([0, 90, 180, 270]),
            flipX: rand() < 0.5, flipY: rand() < 0.5,
            background: { ...doc().background, enabled: rand() < 0.75, padding: int(0, 400), inset: int(0, 120), ratio: pick(ratios), alignment: int(0, 8) },
        };
        add(`size: sweep ${i}`, d);
    }
    return cases;
}

export async function buildCases() {
    const { validateDocument, outputSize } = await loadCore();
    return rawCases().map(({ name, json }) => {
        try {
            const d = validateDocument(JSON.parse(json));
            const size = outputSize(d);
            return { name, json, valid: true, width: size.width, height: size.height };
        } catch (error) {
            return { name, json, valid: false, width: null, height: null };
        }
    });
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url) && process.argv.includes("--write")) {
    const { mkdirSync, writeFileSync: write } = await import("node:fs");
    mkdirSync(path.dirname(FIXTURE), { recursive: true });
    const cases = await buildCases();
    write(FIXTURE, `${JSON.stringify(cases)}\n`);
    console.log(`${cases.length} cases (${cases.filter((c) => c.valid).length} valid) -> ${FIXTURE}`);
}
