// @ts-check
/**
 * Editor document model (schemaVersion 2).
 *
 * Objects live in SOURCE-IMAGE pixel space. Crop, rotation, flip, resize and
 * background form a non-destructive pipeline applied after the objects, so the
 * original pixels are never modified and annotations never drift when the view
 * or crop changes.
 */

export const SCHEMA_VERSION = 2;

import { layout } from "./geometry.js";

export const LIMITS = Object.freeze({
    maxSide: 8192,
    maxPixels: 32_000_000,
    maxObjects: 500,
    maxPoints: 5000,
    maxText: 10_000,
    maxPadding: 1000,
    maxCoord: 32768,
    /** Largest canvas any render step allocates (crop composition, final output). */
    maxCanvasPixels: 64_000_000,
    maxOutputSide: 16384,
    /** Background aspect ratio w/h bounds. */
    minRatio: 1 / 20,
    maxRatio: 20,
});

/** Tool ids, in toolbar order, with CleanShot-observed single-key shortcuts. */
export const TOOLS = Object.freeze([
    { id: "select", key: "v", label: "Select" },
    { id: "rect", key: "r", label: "Rectangle" },
    { id: "filled-rect", key: "f", label: "Filled rectangle" },
    { id: "ellipse", key: "e", label: "Ellipse" },
    { id: "line", key: "l", label: "Line" },
    { id: "arrow", key: "a", label: "Arrow" },
    { id: "text", key: "t", label: "Text" },
    { id: "pixelate", key: "p", label: "Redact" },
    { id: "spotlight", key: "h", label: "Spotlight" },
    { id: "counter", key: "c", label: "Counter" },
    { id: "pencil", key: "d", label: "Draw" },
    { id: "highlighter", key: "m", label: "Highlighter" },
]);

export const OBJECT_TYPES = Object.freeze([
    "rect", "filled-rect", "ellipse", "line", "arrow", "text",
    "pixelate", "spotlight", "counter", "pencil", "highlighter", "image",
]);
export const ARROW_STYLES = Object.freeze(["standard", "fancy", "curved", "double"]);
export const TEXT_STYLES = Object.freeze([
    "standard", "outline", "box", "rounded", "rounded-box", "mono", "mono-box",
]);
export const PIXELATE_STYLES = Object.freeze(["pixelate", "blur", "secure-blur", "blackout"]);
export const SPOTLIGHT_SHAPES = Object.freeze(["rect", "ellipse"]);
export const COUNTER_STYLES = Object.freeze(["numbers", "letters", "roman"]);
export const BACKGROUND_TYPES = Object.freeze(["solid", "gradient", "image", "blurred", "transparent"]);

/** Annotation palette: the reference's 10 named colours (values read from its binary, see docs/parity-inventory.md). */
export const PALETTE = Object.freeze([
    "#000000", "#f93442", "#ff8c00", "#ffe100", "#40d652", "#2ecfc2", "#007aff", "#8a52ff", "#ff2e6b", "#ffffff",
]);

/** Per-tool defaults; the editor remembers the last value per tool like CleanShot. */
export const TOOL_DEFAULTS = Object.freeze({
    rect: { color: "#f93442", thickness: 6 },
    "filled-rect": { color: "#f93442" },
    ellipse: { color: "#f93442", thickness: 6 },
    line: { color: "#f93442", thickness: 6 },
    arrow: { color: "#f93442", thickness: 6, style: "standard" },
    text: { color: "#f93442", fontSize: 30, style: "standard" },
    pixelate: { style: "pixelate", intensity: 30 },
    spotlight: { shape: "rect", opacity: 45 },
    counter: { color: "#f93442", size: 32 },
    pencil: { color: "#f93442", thickness: 6, smooth: true },
    highlighter: { color: "#ffe100", thickness: 24, snap: true },
});

/** @typedef {{x:number,y:number,width:number,height:number}} Rect */
/** @typedef {[number, number]} Pt */
/**
 * @typedef {object} Background
 * @property {boolean} enabled
 * @property {"solid"|"gradient"|"image"|"blurred"|"transparent"} type
 * @property {string} color
 * @property {string[]} gradient  2-3 #rrggbb stops
 * @property {number} angle       gradient angle in degrees
 * @property {string|null} image  resource id for type=image
 * @property {number} padding     px around the framed image (output space)
 * @property {number} inset       px of edge-colored margin inside the frame
 * @property {boolean} autoBalance
 * @property {number} shadow      0..100
 * @property {number} corners     0..100 (percent of max radius)
 * @property {number} alignment   0..8 row-major (4 = center)
 * @property {[number,number]|null} ratio  aspect ratio or null (auto)
 */
/**
 * @typedef {object} EditorDocument
 * @property {2} schemaVersion
 * @property {string} base
 * @property {number} sourceWidth
 * @property {number} sourceHeight
 * @property {Rect} crop
 * @property {{width:number,height:number}} resize
 * @property {0|90|180|270} rotation
 * @property {boolean} flipX
 * @property {boolean} flipY
 * @property {{brightness:number,contrast:number,sharpen:number,blur:number,grayscale:boolean,invert:boolean}} adjustments
 * @property {string|null} canvasColor  fill for crop area outside the source (null = transparent)
 * @property {boolean} objectShadow
 * @property {number} counterStart
 * @property {"numbers"|"letters"|"roman"} counterStyle
 * @property {any[]} objects
 * @property {Background} background
 */

export function defaultBackground() {
    return {
        enabled: false,
        type: /** @type {"gradient"} */ ("gradient"),
        color: "#ffffff",
        gradient: ["#ff7aa8", "#7b5cff"],
        angle: 135,
        image: null,
        padding: 64,
        inset: 0,
        autoBalance: false,
        shadow: 30,
        corners: 20,
        alignment: 4,
        ratio: null,
    };
}

/**
 * @param {{base:string,width:number,height:number}} p
 * @returns {EditorDocument}
 */
export function createDocument({ base, width, height }) {
    return {
        schemaVersion: SCHEMA_VERSION,
        base,
        sourceWidth: width,
        sourceHeight: height,
        crop: { x: 0, y: 0, width, height },
        resize: { width, height },
        rotation: 0,
        flipX: false,
        flipY: false,
        adjustments: { brightness: 0, contrast: 0, sharpen: 0, blur: 0, grayscale: false, invert: false },
        canvasColor: null,
        objectShadow: true,
        counterStart: 1,
        counterStyle: "numbers",
        objects: [],
        background: defaultBackground(),
    };
}

let idCounter = 0;
export function newId(prefix = "o") {
    idCounter = (idCounter + 1) % 1e6;
    const rand = Math.floor(Math.random() * 36 ** 6).toString(36).padStart(6, "0");
    return `${prefix}${Date.now().toString(36)}${idCounter.toString(36)}${rand}`;
}

/** Deep clone for plain JSON data. */
export const clone = (v) => (typeof structuredClone === "function" ? structuredClone(v) : JSON.parse(JSON.stringify(v)));

const HEX = /^#[0-9a-f]{6}([0-9a-f]{2})?$/i;
const finite = (v) => typeof v === "number" && Number.isFinite(v);

class ValidationError extends Error {}

function check(cond, msg) {
    if (!cond) throw new ValidationError(msg);
}
function num(v, lo, hi, name) {
    check(finite(v) && v >= lo && v <= hi, `${name} must be a number in [${lo}, ${hi}]`);
}
function keys(obj, allowed, name) {
    check(obj && typeof obj === "object" && !Array.isArray(obj), `${name} must be an object`);
    for (const k of Object.keys(obj)) check(allowed.includes(k), `${name}: unknown key "${k}"`);
}
function pt(p, name) {
    check(Array.isArray(p) && p.length === 2, `${name} must be [x, y]`);
    num(p[0], -LIMITS.maxCoord, LIMITS.maxCoord, name);
    num(p[1], -LIMITS.maxCoord, LIMITS.maxCoord, name);
}
function rectFields(o, name) {
    num(o.x, -LIMITS.maxCoord, LIMITS.maxCoord, `${name}.x`);
    num(o.y, -LIMITS.maxCoord, LIMITS.maxCoord, `${name}.y`);
    num(o.width, 0, LIMITS.maxCoord, `${name}.width`);
    num(o.height, 0, LIMITS.maxCoord, `${name}.height`);
}

/** Keys that may be absent; every other listed key is required. */
const OPTIONAL_KEYS = new Set(["control", "seed"]);

const OBJECT_KEYS = {
    rect: ["x", "y", "width", "height", "color", "thickness"],
    "filled-rect": ["x", "y", "width", "height", "color"],
    ellipse: ["x", "y", "width", "height", "color", "thickness"],
    line: ["points", "color", "thickness"],
    arrow: ["points", "control", "color", "thickness", "style"],
    text: ["x", "y", "text", "color", "fontSize", "style"],
    pixelate: ["x", "y", "width", "height", "style", "intensity", "seed"],
    spotlight: ["x", "y", "width", "height", "shape", "opacity"],
    counter: ["x", "y", "color", "size"],
    pencil: ["points", "color", "thickness"],
    highlighter: ["points", "color", "thickness"],
    image: ["x", "y", "width", "height", "resource"],
};

/** Validate one object. Throws on error. */
export function validateObject(o, i = 0) {
    const name = `objects[${i}]`;
    check(o && typeof o === "object", `${name} must be an object`);
    check(OBJECT_TYPES.includes(o.type), `${name}.type is not supported`);
    keys(o, ["id", "type", ...OBJECT_KEYS[o.type]], name);
    for (const k of OBJECT_KEYS[o.type]) if (!OPTIONAL_KEYS.has(k)) check(k in o, `${name}.${k} is required`);
    check(typeof o.id === "string" && o.id.length > 0 && o.id.length <= 64, `${name}.id must be a short string`);
    if ("color" in o) check(typeof o.color === "string" && HEX.test(o.color), `${name}.color must be #rrggbb or #rrggbbaa`);
    if ("thickness" in o) num(o.thickness, 1, 200, `${name}.thickness`);
    if ("x" in o && "width" in o) rectFields(o, name);
    switch (o.type) {
        case "line":
        case "arrow":
            check(Array.isArray(o.points) && o.points.length === 2, `${name}.points must have 2 points`);
            o.points.forEach((p, j) => pt(p, `${name}.points[${j}]`));
            if (o.type === "arrow") {
                check(ARROW_STYLES.includes(o.style), `${name}.style is not supported`);
                if (o.control !== undefined && o.control !== null) pt(o.control, `${name}.control`);
            }
            break;
        case "pencil":
        case "highlighter":
            check(Array.isArray(o.points) && o.points.length >= 1 && o.points.length <= LIMITS.maxPoints, `${name}.points must have 1..${LIMITS.maxPoints} points`);
            o.points.forEach((p, j) => pt(p, `${name}.points[${j}]`));
            break;
        case "text":
            num(o.x, -LIMITS.maxCoord, LIMITS.maxCoord, `${name}.x`);
            num(o.y, -LIMITS.maxCoord, LIMITS.maxCoord, `${name}.y`);
            check(typeof o.text === "string" && o.text.length <= LIMITS.maxText, `${name}.text too long`);
            num(o.fontSize, 4, 1000, `${name}.fontSize`);
            check(TEXT_STYLES.includes(o.style), `${name}.style is not supported`);
            break;
        case "pixelate":
            check(PIXELATE_STYLES.includes(o.style), `${name}.style is not supported`);
            num(o.intensity, 1, 100, `${name}.intensity`);
            if ("seed" in o) num(o.seed, 0, 2 ** 31, `${name}.seed`);
            break;
        case "spotlight":
            check(SPOTLIGHT_SHAPES.includes(o.shape), `${name}.shape is not supported`);
            num(o.opacity, 0, 100, `${name}.opacity`);
            break;
        case "counter":
            num(o.x, -LIMITS.maxCoord, LIMITS.maxCoord, `${name}.x`);
            num(o.y, -LIMITS.maxCoord, LIMITS.maxCoord, `${name}.y`);
            num(o.size, 8, 400, `${name}.size`);
            break;
        case "image":
            check(typeof o.resource === "string" && o.resource.length > 0 && o.resource.length <= 128, `${name}.resource is required`);
            break;
    }
}

/**
 * Validate a full document. Throws an Error describing the first problem.
 * Mirrors the server-side schema so the client never sends a document the
 * server would reject.
 * @param {any} d
 * @returns {EditorDocument}
 */
export function validateDocument(d) {
    keys(d, [
        "schemaVersion", "base", "sourceWidth", "sourceHeight", "crop", "resize", "rotation", "flipX", "flipY",
        "adjustments", "canvasColor", "objectShadow", "counterStart", "counterStyle", "objects", "background",
    ], "document");
    check(d.schemaVersion === SCHEMA_VERSION, "schemaVersion must be 2");
    check(typeof d.base === "string" && d.base.length > 0, "base is required");
    for (const k of ["sourceWidth", "sourceHeight"]) check(Number.isInteger(d[k]) && d[k] >= 1 && d[k] <= LIMITS.maxSide, `${k} must be an integer in [1, ${LIMITS.maxSide}]`);
    keys(d.crop, ["x", "y", "width", "height"], "crop");
    for (const k of ["x", "y"]) num(d.crop[k], -LIMITS.maxSide, LIMITS.maxSide, `crop.${k}`);
    for (const k of ["width", "height"]) num(d.crop[k], 1, LIMITS.maxSide * 2, `crop.${k}`);
    keys(d.resize, ["width", "height"], "resize");
    for (const k of ["width", "height"]) check(Number.isInteger(d.resize[k]) && d.resize[k] >= 1 && d.resize[k] <= LIMITS.maxSide, `resize.${k} must be an integer in [1, ${LIMITS.maxSide}]`);
    check([0, 90, 180, 270].includes(d.rotation), "rotation must be 0, 90, 180 or 270");
    check(typeof d.flipX === "boolean" && typeof d.flipY === "boolean", "flipX/flipY must be booleans");
    const a = d.adjustments;
    keys(a, ["brightness", "contrast", "sharpen", "blur", "grayscale", "invert"], "adjustments");
    num(a.brightness, -100, 100, "adjustments.brightness");
    num(a.contrast, -100, 100, "adjustments.contrast");
    num(a.sharpen, 0, 100, "adjustments.sharpen");
    num(a.blur, 0, 30, "adjustments.blur");
    check(typeof a.grayscale === "boolean" && typeof a.invert === "boolean", "adjustments flags must be booleans");
    check(d.canvasColor === null || (typeof d.canvasColor === "string" && HEX.test(d.canvasColor)), "canvasColor must be null or a hex color");
    check(typeof d.objectShadow === "boolean", "objectShadow must be a boolean");
    check(Number.isInteger(d.counterStart) && d.counterStart >= 0 && d.counterStart <= 9999, "counterStart must be an integer in [0, 9999]");
    check(COUNTER_STYLES.includes(d.counterStyle), "counterStyle is not supported");
    check(Array.isArray(d.objects) && d.objects.length <= LIMITS.maxObjects, `objects must be an array of at most ${LIMITS.maxObjects}`);
    const ids = new Set();
    d.objects.forEach((o, i) => {
        validateObject(o, i);
        check(!ids.has(o.id), `objects[${i}].id is duplicated`);
        ids.add(o.id);
    });
    const b = d.background;
    keys(b, ["enabled", "type", "color", "gradient", "angle", "image", "padding", "inset", "autoBalance", "shadow", "corners", "alignment", "ratio"], "background");
    check(typeof b.enabled === "boolean" && typeof b.autoBalance === "boolean", "background flags must be booleans");
    check(BACKGROUND_TYPES.includes(b.type), "background.type is not supported");
    check(typeof b.color === "string" && HEX.test(b.color), "background.color must be a hex color");
    check(Array.isArray(b.gradient) && b.gradient.length >= 2 && b.gradient.length <= 3 && b.gradient.every((c) => typeof c === "string" && HEX.test(c)), "background.gradient must have 2-3 hex colors");
    num(b.angle, 0, 360, "background.angle");
    check(b.image === null || (typeof b.image === "string" && b.image.length <= 128), "background.image must be null or a resource id");
    check(b.type !== "image" || !b.enabled || typeof b.image === "string", "background.image is required for image backgrounds");
    check(Number.isInteger(b.padding) && b.padding >= 0 && b.padding <= LIMITS.maxPadding, "background.padding must be an integer in [0, 1000]");
    check(Number.isInteger(b.inset) && b.inset >= 0 && b.inset <= 500, "background.inset must be an integer in [0, 500]");
    num(b.shadow, 0, 100, "background.shadow");
    num(b.corners, 0, 100, "background.corners");
    check(Number.isInteger(b.alignment) && b.alignment >= 0 && b.alignment <= 8, "background.alignment must be 0..8");
    check(b.ratio === null || (Array.isArray(b.ratio) && b.ratio.length === 2 && b.ratio.every((r) => finite(r) && r >= 0.01 && r <= 10000)
        && b.ratio[0] / b.ratio[1] >= LIMITS.minRatio && b.ratio[0] / b.ratio[1] <= LIMITS.maxRatio), "background.ratio must be null or [w, h] with w/h in [1/20, 20]");
    // Allocation budgets: every canvas a render creates must stay bounded.
    check(d.sourceWidth * d.sourceHeight <= LIMITS.maxPixels, `source image exceeds ${LIMITS.maxPixels} pixels`);
    check(d.crop.width * d.crop.height <= LIMITS.maxCanvasPixels, "crop area is too large");
    check(d.resize.width * d.resize.height <= LIMITS.maxPixels, `resized image exceeds ${LIMITS.maxPixels} pixels`);
    const out = layout(d);
    check(out.width <= LIMITS.maxOutputSide && out.height <= LIMITS.maxOutputSide && out.width * out.height <= LIMITS.maxCanvasPixels, "output size is too large");
    return d;
}

/** True when `validateDocument` would accept `d`. */
export function isValidDocument(d) {
    try {
        validateDocument(d);
        return true;
    } catch {
        return false;
    }
}

/** Collect resource ids a document references (base, background image, image objects). */
export function referencedResources(d) {
    const ids = new Set([d.base]);
    if (d.background.type === "image" && d.background.image) ids.add(d.background.image);
    for (const o of d.objects) if (o.type === "image") ids.add(o.resource);
    return [...ids];
}
