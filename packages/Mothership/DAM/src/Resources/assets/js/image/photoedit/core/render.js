// @ts-check
import { drawArrow } from "./arrows.js";
import { layout, outputSize, resizedToImage, multiply, scale as scaleM, normRect, smoothStroke, textOrientation } from "./geometry.js";
import { createMeasurer, drawText } from "./text.js";

/**
 * Renderer shared by preview and export. `renderDocument` is synchronous:
 * callers preload every image resource into `images` (id → CanvasImageSource).
 *
 * Preview passes `scale < 1` for speed; geometry is identical, only the pixel
 * density changes. Export always uses `scale: 1` and returns a canvas whose size
 * equals `outputSize(doc)` exactly.
 */

/** @returns {HTMLCanvasElement|OffscreenCanvas} */
export function createCanvas(width, height) {
    const w = Math.max(1, Math.round(width)), h = Math.max(1, Math.round(height));
    if (typeof document !== "undefined") {
        const c = document.createElement("canvas");
        c.width = w;
        c.height = h;
        return c;
    }
    return new OffscreenCanvas(w, h);
}

/** @param {HTMLCanvasElement|OffscreenCanvas} c @returns {CanvasRenderingContext2D} */
const ctx2d = (c, opts) => /** @type {any} */ (c.getContext("2d", opts));

const measureCanvas = { c: /** @type {any} */ (null), m: /** @type {any} */ (null) };
/** A text measurer bound to a private canvas (geometry + hit-testing use this). */
export function sharedMeasure() {
    if (!measureCanvas.m) {
        measureCanvas.c = createCanvas(4, 4);
        measureCanvas.m = createMeasurer(ctx2d(measureCanvas.c));
    }
    return measureCanvas.m;
}

// ---------------------------------------------------------------------------
// Deterministic noise for secure pixelation (seeded per object, so preview and
// export produce the same pixels and re-exports are stable).
function rng(seed) {
    let s = seed >>> 0 || 0x9e3779b9;
    return () => {
        s ^= s << 13;
        s ^= s >>> 17;
        s ^= s << 5;
        return (s >>> 0) / 4294967296;
    };
}

const clamp255 = (v) => (v < 0 ? 0 : v > 255 ? 255 : v);

/** Apply brightness/contrast/grayscale/invert/sharpen to ImageData in place. */
export function adjustPixels(data, width, height, a) {
    const px = data;
    const b = a.brightness * 2.55;
    const c = a.contrast;
    const f = (259 * (c + 255)) / (255 * (259 - c));
    for (let i = 0; i < px.length; i += 4) {
        let r = px[i], g = px[i + 1], bl = px[i + 2];
        if (b) { r += b; g += b; bl += b; }
        if (c) { r = f * (r - 128) + 128; g = f * (g - 128) + 128; bl = f * (bl - 128) + 128; }
        if (a.grayscale) { const y = 0.2126 * r + 0.7152 * g + 0.0722 * bl; r = g = bl = y; }
        if (a.invert) { r = 255 - r; g = 255 - g; bl = 255 - bl; }
        px[i] = clamp255(r); px[i + 1] = clamp255(g); px[i + 2] = clamp255(bl);
    }
    if (a.sharpen > 0) {
        const amt = a.sharpen / 100;
        const src = new Uint8ClampedArray(px);
        for (let y = 1; y < height - 1; y++) {
            for (let x = 1; x < width - 1; x++) {
                const i = (y * width + x) * 4;
                for (let k = 0; k < 3; k++) {
                    const center = src[i + k];
                    const blur = (src[i - 4 + k] + src[i + 4 + k] + src[i - width * 4 + k] + src[i + width * 4 + k]) / 4;
                    px[i + k] = clamp255(center + (center - blur) * amt * 2);
                }
            }
        }
    }
}

const hasAdjust = (a) => a.brightness || a.contrast || a.sharpen || a.blur || a.grayscale || a.invert;

/** Cache of the adjusted base image (keyed by base + adjustments). */
const baseCache = new WeakMap();
function adjustedBase(img, a) {
    if (!hasAdjust(a)) return img;
    const key = JSON.stringify(a);
    const hit = baseCache.get(img);
    if (hit && hit.key === key) return hit.canvas;
    const w = /** @type {any} */ (img).naturalWidth || /** @type {any} */ (img).width;
    const h = /** @type {any} */ (img).naturalHeight || /** @type {any} */ (img).height;
    const c = createCanvas(w, h);
    const x = ctx2d(c, { willReadFrequently: true });
    if (a.blur) x.filter = `blur(${a.blur}px)`;
    x.drawImage(img, 0, 0);
    x.filter = "none";
    if (a.brightness || a.contrast || a.sharpen || a.grayscale || a.invert) {
        const d = x.getImageData(0, 0, w, h);
        adjustPixels(d.data, w, h, a);
        x.putImageData(d, 0, 0);
    }
    baseCache.set(img, { key, canvas: c });
    return c;
}

// ---------------------------------------------------------------------------
// Image analysis for background inset colour and auto-balance.

/** Average colour of the outermost pixel ring of an image canvas (#rrggbb). */
export function edgeColor(canvas) {
    const w = canvas.width, h = canvas.height;
    if (w < 1 || h < 1) return "#ffffff";
    return edgeColorData(ctx2d(canvas, { willReadFrequently: true }).getImageData(0, 0, w, h));
}

function edgeColorData({ width: w, height: h, data: d }) {
    let r = 0, g = 0, b = 0, n = 0;
    const add = (x, y) => {
        const i = (y * w + x) * 4;
        if (d[i + 3] < 128) return;
        r += d[i]; g += d[i + 1]; b += d[i + 2]; n++;
    };
    const step = Math.max(1, Math.floor(Math.max(w, h) / 400));
    for (let x = 0; x < w; x += step) { add(x, 0); add(x, h - 1); }
    for (let y = 0; y < h; y += step) { add(0, y); add(w - 1, y); }
    if (!n) return "#ffffff";
    const hex = (v) => Math.round(v / n).toString(16).padStart(2, "0");
    return `#${hex(r)}${hex(g)}${hex(b)}`;
}

/**
 * Auto-balance: find uniform *opaque* margins matching the edge colour and
 * return the shift (image px) that centres the content. Transparent margins
 * are left alone (observed CleanShot behaviour).
 */
export function balanceOffset(canvas, color) {
    const w = canvas.width, h = canvas.height;
    return balanceOffsetData(ctx2d(canvas, { willReadFrequently: true }).getImageData(0, 0, w, h), color);
}

function balanceOffsetData({ width: w, height: h, data: d }, color) {
    const n = parseInt(color.slice(1), 16);
    const cr = (n >> 16) & 255, cg = (n >> 8) & 255, cb = n & 255;
    const tol = 18;
    const same = (i) => d[i + 3] > 200 && Math.abs(d[i] - cr) <= tol && Math.abs(d[i + 1] - cg) <= tol && Math.abs(d[i + 2] - cb) <= tol;
    const rowUniform = (y) => { for (let x = 0; x < w; x++) if (!same((y * w + x) * 4)) return false; return true; };
    const colUniform = (x) => { for (let y = 0; y < h; y++) if (!same((y * w + x) * 4)) return false; return true; };
    let top = 0, bottom = 0, left = 0, right = 0;
    while (top < h && rowUniform(top)) top++;
    if (top >= h) return { dx: 0, dy: 0 };
    while (bottom < h - top && rowUniform(h - 1 - bottom)) bottom++;
    while (left < w && colUniform(left)) left++;
    while (right < w - left && colUniform(w - 1 - right)) right++;
    return { dx: (right - left) / 2, dy: (bottom - top) / 2 };
}

// ---------------------------------------------------------------------------
// Counters

export function counterLabel(n, style) {
    if (style === "letters") {
        let s = "", v = n;
        if (v < 1) return "0";
        while (v > 0) { v--; s = String.fromCharCode(65 + (v % 26)) + s; v = Math.floor(v / 26); }
        return s;
    }
    if (style === "roman") {
        if (n < 1) return "0";
        /** @type {[number, string][]} */
        const map = [[1000, "M"], [900, "CM"], [500, "D"], [400, "CD"], [100, "C"], [90, "XC"], [50, "L"], [40, "XL"], [10, "X"], [9, "IX"], [5, "V"], [4, "IV"], [1, "I"]];
        let s = "", v = n;
        for (const [k, r] of map) while (v >= k) { s += r; v -= k; }
        return s;
    }
    return String(n);
}

/** Map counter id → label, numbered in z-order starting at `doc.counterStart`. */
export function counterLabels(doc) {
    const out = new Map();
    let n = doc.counterStart;
    for (const o of doc.objects) if (o.type === "counter") out.set(o.id, counterLabel(n++, doc.counterStyle));
    return out;
}

/** Objects in paint order: counters always stay on top (CleanShot behaviour). */
export function paintOrder(objects) {
    return [...objects.filter((o) => o.type !== "counter"), ...objects.filter((o) => o.type === "counter")];
}

// ---------------------------------------------------------------------------
// Object painters (source space; ctx transform maps source → canvas pixels)

function shadowFn(enabled, pxScale) {
    if (!enabled) return () => {};
    return (ctx) => {
        // shadowBlur/offset ignore the transform, so scale them manually.
        ctx.shadowColor = "rgba(0,0,0,0.32)";
        ctx.shadowBlur = 6 * pxScale;
        ctx.shadowOffsetX = 0;
        ctx.shadowOffsetY = 1.5 * pxScale;
    };
}

function strokePath(ctx, points) {
    ctx.beginPath();
    if (points.length === 1) {
        ctx.moveTo(points[0][0], points[0][1]);
        ctx.lineTo(points[0][0] + 0.01, points[0][1]);
        return;
    }
    ctx.moveTo(points[0][0], points[0][1]);
    if (points.length === 2) {
        ctx.lineTo(points[1][0], points[1][1]);
        return;
    }
    for (let i = 1; i < points.length - 1; i++) {
        const mx = (points[i][0] + points[i + 1][0]) / 2, my = (points[i][1] + points[i + 1][1]) / 2;
        ctx.quadraticCurveTo(points[i][0], points[i][1], mx, my);
    }
    const last = points[points.length - 1];
    ctx.lineTo(last[0], last[1]);
}

/** Apply a pixelate/blur effect to a region of the composition canvas. */
function applyEffect(comp, o, toCanvas, pxScale) {
    const r = normRect(o);
    const x0 = Math.floor(r.x * toCanvas[0] + toCanvas[4]);
    const y0 = Math.floor(r.y * toCanvas[3] + toCanvas[5]);
    const x1 = Math.ceil((r.x + r.width) * toCanvas[0] + toCanvas[4]);
    const y1 = Math.ceil((r.y + r.height) * toCanvas[3] + toCanvas[5]);
    const cx0 = Math.max(0, x0), cy0 = Math.max(0, y0);
    const cx1 = Math.min(comp.width, x1), cy1 = Math.min(comp.height, y1);
    const w = cx1 - cx0, h = cy1 - cy0;
    if (w < 1 || h < 1) return;
    const ctx = ctx2d(comp, { willReadFrequently: true });
    if (o.style === "blackout") {
        ctx.save();
        ctx.setTransform(1, 0, 0, 1, 0, 0);
        ctx.fillStyle = "#000000";
        ctx.fillRect(cx0, cy0, w, h);
        ctx.restore();
        return;
    }
    // Block size in SOURCE px so the effect is resolution independent.
    const blockSrc = Math.max(2, Math.round(4 + (o.intensity / 100) * 36));
    if (o.style === "blur" || o.style === "secure-blur") {
        const radius = Math.max(1, (o.intensity / 100) * 40 * pxScale);
        const pad = Math.ceil(radius * 2);
        const sx = Math.max(0, cx0 - pad), sy = Math.max(0, cy0 - pad);
        const sw = Math.min(comp.width, cx1 + pad) - sx, sh = Math.min(comp.height, cy1 + pad) - sy;
        const tmp = createCanvas(sw, sh);
        const t = ctx2d(tmp);
        if (o.style === "secure-blur") {
            // Destroy information first (coarse pixelation + noise), then blur it smooth.
            const coarse = ctx2d(comp, { willReadFrequently: true }).getImageData(sx, sy, sw, sh);
            // Same source-space grid as the export (no rounding up at low zoom), aligned to the object.
            pixelateData(coarse, Math.max(1, blockSrc * pxScale * 0.75), o.seed ?? 1, true, x0 - sx, y0 - sy);
            t.putImageData(coarse, 0, 0);
            const t2 = createCanvas(sw, sh);
            const c2 = ctx2d(t2);
            c2.filter = `blur(${radius}px)`;
            c2.drawImage(tmp, 0, 0);
            ctx.save();
            ctx.setTransform(1, 0, 0, 1, 0, 0);
            ctx.beginPath();
            ctx.rect(cx0, cy0, w, h);
            ctx.clip();
            ctx.drawImage(t2, sx, sy);
            ctx.restore();
            return;
        }
        t.filter = `blur(${radius}px)`;
        t.drawImage(comp, sx, sy, sw, sh, 0, 0, sw, sh);
        ctx.save();
        ctx.setTransform(1, 0, 0, 1, 0, 0);
        ctx.beginPath();
        ctx.rect(cx0, cy0, w, h);
        ctx.clip();
        ctx.clearRect(cx0, cy0, w, h);
        ctx.drawImage(tmp, sx, sy);
        ctx.restore();
        return;
    }
    const data = ctx.getImageData(cx0, cy0, w, h);
    pixelateData(data, Math.max(1, blockSrc * pxScale), o.seed ?? 1, true, x0 - cx0, y0 - cy0);
    ctx.putImageData(data, cx0, cy0);
}

/** Average colour per block, with seeded per-block jitter for unrecoverability. */
function pixelateData(img, block, seed, noise, ox = 0, oy = 0) {
    const { width: w, height: h, data: d } = img;
    const rand = rng(seed);
    const bs = Math.max(1, block);
    // Align the block grid to the object's origin so preview and export match; start at or before 0.
    const startX = ox - Math.ceil(ox / bs) * bs, startY = oy - Math.ceil(oy / bs) * bs;
    for (let by = Math.floor(startY); by < h; by += bs) {
        for (let bx = Math.floor(startX); bx < w; bx += bs) {
            const xa = Math.max(0, Math.floor(bx)), ya = Math.max(0, Math.floor(by));
            const xb = Math.min(w, Math.floor(bx + bs)), yb = Math.min(h, Math.floor(by + bs));
            let r = 0, g = 0, b = 0, a = 0, n = 0;
            for (let y = ya; y < yb; y++) for (let x = xa; x < xb; x++) {
                const i = (y * w + x) * 4;
                r += d[i]; g += d[i + 1]; b += d[i + 2]; a += d[i + 3]; n++;
            }
            if (!n) continue;
            const j = noise ? (rand() - 0.5) * 24 : 0;
            r = clamp255(r / n + j); g = clamp255(g / n + j); b = clamp255(b / n + j); a /= n;
            for (let y = ya; y < yb; y++) for (let x = xa; x < xb; x++) {
                const i = (y * w + x) * 4;
                d[i] = r; d[i + 1] = g; d[i + 2] = b; d[i + 3] = a;
            }
        }
    }
}

function drawSpotlights(ctx, doc, spots, crop) {
    const opacity = Math.max(...spots.map((s) => s.opacity)) / 100;
    if (opacity <= 0) return;
    void doc;
    void crop;
    // Dim layer minus the UNION of all spotlights (overlaps stay clear, unlike an even-odd fill).
    const comp = ctx.canvas;
    const mask = createCanvas(comp.width, comp.height);
    const m = ctx2d(mask);
    m.fillStyle = `rgba(0,0,0,${opacity})`;
    m.fillRect(0, 0, mask.width, mask.height);
    m.setTransform(ctx.getTransform());
    m.globalCompositeOperation = "destination-out";
    m.fillStyle = "#000";
    for (const s of spots) {
        const r = normRect(s);
        m.beginPath();
        if (s.shape === "ellipse") m.ellipse(r.x + r.width / 2, r.y + r.height / 2, r.width / 2, r.height / 2, 0, 0, Math.PI * 2);
        else m.roundRect(r.x, r.y, r.width, r.height, Math.min(8, r.width / 4, r.height / 4));
        m.fill();
    }
    ctx.save();
    ctx.setTransform(1, 0, 0, 1, 0, 0);
    ctx.drawImage(mask, 0, 0);
    ctx.restore();
}

/**
 * Draw one object. `env` carries the composition canvas (for effects), the
 * source→canvas matrix, pixel scale, measurer, counter labels and images.
 */
export function drawObject(ctx, o, env) {
    const shadow = shadowFn(env.shadow, env.pxScale);
    switch (o.type) {
        case "rect":
        case "ellipse": {
            const r = normRect(o);
            ctx.save();
            shadow(ctx);
            ctx.strokeStyle = o.color;
            ctx.lineWidth = o.thickness;
            ctx.lineJoin = "round";
            ctx.beginPath();
            if (o.type === "ellipse") ctx.ellipse(r.x + r.width / 2, r.y + r.height / 2, Math.max(0.1, r.width / 2), Math.max(0.1, r.height / 2), 0, 0, Math.PI * 2);
            else ctx.roundRect(r.x, r.y, r.width, r.height, Math.min(o.thickness * 0.6, r.width / 2, r.height / 2));
            ctx.stroke();
            ctx.restore();
            break;
        }
        case "filled-rect": {
            const r = normRect(o);
            ctx.save();
            shadow(ctx);
            ctx.fillStyle = o.color;
            ctx.beginPath();
            ctx.roundRect(r.x, r.y, r.width, r.height, Math.min(4, r.width / 2, r.height / 2));
            ctx.fill();
            ctx.restore();
            break;
        }
        case "line": {
            ctx.save();
            shadow(ctx);
            ctx.strokeStyle = o.color;
            ctx.lineWidth = o.thickness;
            ctx.lineCap = "round";
            ctx.beginPath();
            ctx.moveTo(o.points[0][0], o.points[0][1]);
            ctx.lineTo(o.points[1][0], o.points[1][1]);
            ctx.stroke();
            ctx.restore();
            break;
        }
        case "arrow":
            drawArrow(ctx, o, shadow);
            break;
        case "text": {
            // Upright in the output: draw in the text's local frame (see textOrientation).
            const T = env.textOrient || [1, 0, 0, 1];
            ctx.save();
            ctx.transform(T[0], T[1], T[2], T[3], o.x, o.y);
            drawText(ctx, { ...o, x: 0, y: 0 }, env.measure, shadow);
            ctx.restore();
            break;
        }
        case "pencil": {
            ctx.save();
            shadow(ctx);
            ctx.strokeStyle = o.color;
            ctx.lineWidth = o.thickness;
            ctx.lineCap = "round";
            ctx.lineJoin = "round";
            strokePath(ctx, o.points);
            ctx.stroke();
            ctx.restore();
            break;
        }
        case "highlighter": {
            ctx.save();
            ctx.globalCompositeOperation = "multiply";
            ctx.globalAlpha = 0.5;
            ctx.strokeStyle = o.color;
            ctx.lineWidth = o.thickness;
            ctx.lineCap = "butt";
            ctx.lineJoin = "round";
            strokePath(ctx, o.points);
            ctx.stroke();
            ctx.restore();
            break;
        }
        case "counter": {
            const label = env.labels.get(o.id) ?? "1";
            const rad = o.size / 2;
            ctx.save();
            shadow(ctx);
            ctx.fillStyle = o.color;
            ctx.beginPath();
            if (label.length > 1) {
                const w = Math.max(o.size, o.size * 0.42 * label.length + o.size * 0.5);
                ctx.roundRect(o.x - w / 2, o.y - rad, w, o.size, rad);
            } else ctx.arc(o.x, o.y, rad, 0, Math.PI * 2);
            ctx.fill();
            ctx.shadowColor = "transparent";
            ctx.fillStyle = "#ffffff";
            ctx.font = `700 ${Math.round(o.size * 0.58)}px -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, Arial, sans-serif`;
            ctx.textAlign = "center";
            ctx.textBaseline = "middle";
            ctx.fillText(label, o.x, o.y + o.size * 0.03);
            ctx.restore();
            break;
        }
        case "image": {
            const img = env.images.get(o.resource);
            const r = normRect(o);
            if (img) {
                ctx.save();
                shadow(ctx);
                ctx.drawImage(img, r.x, r.y, r.width, r.height);
                ctx.restore();
            }
            break;
        }
        case "pixelate":
            applyEffect(env.comp, o, env.toCanvas, env.pxScale);
            break;
    }
}

/**
 * Render the composition (base + objects) in crop space at `pxScale`.
 * @returns {HTMLCanvasElement|OffscreenCanvas}
 */
export function renderComposition(doc, images, { pxScale = 1, skip = null } = {}) {
    const crop = doc.crop;
    const comp = createCanvas(Math.max(1, Math.round(crop.width * pxScale)), Math.max(1, Math.round(crop.height * pxScale)));
    const ctx = ctx2d(comp, { willReadFrequently: true });
    const sx = comp.width / crop.width, sy = comp.height / crop.height;
    /** @type {import("./geometry.js").Mat} */
    const toCanvas = [sx, 0, 0, sy, -crop.x * sx, -crop.y * sy];
    ctx.imageSmoothingQuality = "high";
    ctx.setTransform(...toCanvas);
    if (doc.canvasColor) {
        ctx.fillStyle = doc.canvasColor;
        ctx.fillRect(crop.x, crop.y, crop.width, crop.height);
    }
    const base = images.get(doc.base);
    if (base) ctx.drawImage(adjustedBase(base, doc.adjustments), 0, 0, doc.sourceWidth, doc.sourceHeight);
    const env = {
        comp,
        toCanvas,
        pxScale: sx,
        shadow: doc.objectShadow,
        measure: sharedMeasure(),
        textOrient: textOrientation(doc),
        labels: counterLabels(doc),
        images,
    };
    const ordered = paintOrder(doc.objects);
    const spots = ordered.filter((o) => o.type === "spotlight" && o.id !== skip);
    let spotsDrawn = false;
    for (const o of ordered) {
        if (o.id === skip) continue;
        if (o.type === "spotlight") {
            if (!spotsDrawn) {
                drawSpotlights(ctx, doc, spots, crop);
                spotsDrawn = true;
            }
            continue;
        }
        drawObject(ctx, o, env);
        ctx.setTransform(...toCanvas);
    }
    return comp;
}

/** Composition → rotated/flipped/resized image canvas (image space). */
export function renderImage(doc, images, opts = {}) {
    const pxScale = opts.pxScale ?? 1;
    const comp = renderComposition(doc, images, opts);
    const l = layout(doc);
    const img = createCanvas(Math.max(1, Math.round(l.image.width * pxScale)), Math.max(1, Math.round(l.image.height * pxScale)));
    const ctx = ctx2d(img, { willReadFrequently: true });
    ctx.imageSmoothingQuality = "high";
    const kx = img.width / l.image.width, ky = img.height / l.image.height;
    // comp pixel → crop space → resized → image → canvas pixel
    const m = multiply(
        multiply(scaleM(kx, ky), resizedToImage(doc)),
        scaleM(doc.resize.width / comp.width, doc.resize.height / comp.height),
    );
    ctx.setTransform(...m);
    ctx.drawImage(comp, 0, 0);
    return img;
}

/**
 * Full render: image + background. Returns { canvas, balance } where `balance`
 * is the auto-balance shift in OUTPUT pixels (needed for hit-testing).
 */
export function renderDocument(doc, images, opts = {}) {
    const pxScale = opts.pxScale ?? 1;
    const l = layout(doc);
    const size = outputSize(doc);
    const out = opts.canvas ?? createCanvas(size.width * pxScale, size.height * pxScale);
    const W = Math.max(1, Math.round(size.width * pxScale)), H = Math.max(1, Math.round(size.height * pxScale));
    if (out.width !== W) out.width = W;
    if (out.height !== H) out.height = H;
    const ctx = ctx2d(out);
    ctx.setTransform(1, 0, 0, 1, 0, 0);
    ctx.clearRect(0, 0, W, H);
    const img = renderImage(doc, images, opts);
    const b = doc.background;
    if (!b.enabled) {
        ctx.drawImage(img, 0, 0, W, H);
        return { canvas: out, balance: { dx: 0, dy: 0 }, edge: null };
    }
    const kx = W / l.width, ky = H / l.height;
    ctx.setTransform(kx, 0, 0, ky, 0, 0);
    ctx.imageSmoothingQuality = "high";
    paintBackground(ctx, doc, images, l, img);

    const needsEdge = b.inset > 0 || b.autoBalance;
    // UPSTREAM-DEVIATION (The Mothership): share this render's readback between both analyses.
    const analysis = needsEdge ? ctx2d(img, { willReadFrequently: true }).getImageData(0, 0, img.width, img.height) : null;
    const edge = analysis ? edgeColorData(analysis) : null;
    let balance = { dx: 0, dy: 0 };
    if (b.autoBalance && edge) {
        const off = balanceOffsetData(analysis, edge);
        balance = { dx: off.dx / (img.width / l.image.width), dy: off.dy / (img.height / l.image.height) };
    }
    const f = l.frame;
    if (b.shadow > 0) {
        ctx.save();
        const k = Math.min(kx, ky);
        ctx.shadowColor = `rgba(0,0,0,${0.18 + (b.shadow / 100) * 0.4})`;
        ctx.shadowBlur = (b.shadow / 100) * Math.max(24, Math.min(f.width, f.height) * 0.09) * k;
        ctx.shadowOffsetY = (b.shadow / 100) * Math.max(6, Math.min(f.width, f.height) * 0.02) * k;
        ctx.fillStyle = "#000";
        ctx.beginPath();
        ctx.roundRect(f.x, f.y, f.width, f.height, l.radius);
        // Paint the shadow only outside the frame so transparent images stay transparent.
        ctx.rect(l.width + 10000, -10000, -(l.width + 20000), l.height + 20000);
        ctx.clip("evenodd");
        ctx.beginPath();
        ctx.roundRect(f.x, f.y, f.width, f.height, l.radius);
        ctx.fill();
        ctx.restore();
    }
    ctx.save();
    ctx.beginPath();
    ctx.roundRect(f.x, f.y, f.width, f.height, l.radius);
    ctx.clip();
    if (edge && (b.inset > 0 || balance.dx || balance.dy)) {
        ctx.fillStyle = edge;
        ctx.fillRect(f.x, f.y, f.width, f.height);
    }
    if (balance.dx || balance.dy) {
        ctx.beginPath();
        ctx.rect(l.image.x, l.image.y, l.image.width, l.image.height);
        ctx.clip();
    }
    ctx.drawImage(img, l.image.x + balance.dx, l.image.y + balance.dy, l.image.width, l.image.height);
    ctx.restore();
    return { canvas: out, balance, edge };
}

/** Background fill for the whole output canvas (ctx in output units). */
function paintBackground(ctx, doc, images, l, img) {
    const b = doc.background;
    ctx.save();
    if (b.type === "solid") {
        ctx.fillStyle = b.color;
        ctx.fillRect(0, 0, l.width, l.height);
    } else if (b.type === "gradient") {
        const a = (b.angle * Math.PI) / 180;
        const r = (Math.abs(Math.cos(a)) * l.width + Math.abs(Math.sin(a)) * l.height) / 2;
        const cx = l.width / 2, cy = l.height / 2;
        const g = ctx.createLinearGradient(cx - Math.cos(a) * r, cy - Math.sin(a) * r, cx + Math.cos(a) * r, cy + Math.sin(a) * r);
        b.gradient.forEach((c, i) => g.addColorStop(i / (b.gradient.length - 1), c));
        ctx.fillStyle = g;
        ctx.fillRect(0, 0, l.width, l.height);
    } else if (b.type === "image" || b.type === "blurred") {
        const src = b.type === "image" ? images.get(b.image) : img;
        ctx.fillStyle = b.color;
        ctx.fillRect(0, 0, l.width, l.height);
        if (src) {
            const sw = /** @type {any} */ (src).naturalWidth || /** @type {any} */ (src).width;
            const sh = /** @type {any} */ (src).naturalHeight || /** @type {any} */ (src).height;
            const s = Math.max(l.width / sw, l.height / sh);
            if (b.type === "blurred") {
                const t = ctx.getTransform();
                ctx.filter = `blur(${Math.max(l.width, l.height) * 0.04 * Math.min(t.a, t.d)}px)`;
                const over = 1.15; // overscan hides the blur's soft edges
                ctx.drawImage(src, (l.width - sw * s * over) / 2, (l.height - sh * s * over) / 2, sw * s * over, sh * s * over);
                ctx.filter = "none";
                // b.color tints the blur (dark/light presets).
                ctx.globalAlpha = 0.35;
                ctx.fillStyle = b.color;
                ctx.fillRect(0, 0, l.width, l.height);
            } else ctx.drawImage(src, (l.width - sw * s) / 2, (l.height - sh * s) / 2, sw * s, sh * s);
        }
    }
    ctx.restore();
}

/** Export: full-resolution canvas whose size equals outputSize(doc). */
export function exportCanvas(doc, images) {
    return renderDocument(doc, images, { pxScale: 1 }).canvas;
}

/**
 * Encode a canvas. JPEG has no alpha: transparent pixels are flattened onto
 * white explicitly instead of letting the encoder turn them black.
 */
export async function canvasToBlob(canvas, type = "image/png", quality = 0.92) {
    let c = canvas;
    if (type === "image/jpeg") {
        c = createCanvas(canvas.width, canvas.height);
        const x = ctx2d(c);
        x.fillStyle = "#ffffff";
        x.fillRect(0, 0, c.width, c.height);
        x.drawImage(canvas, 0, 0);
    }
    if ("convertToBlob" in c) return /** @type {OffscreenCanvas} */ (c).convertToBlob({ type, quality });
    return new Promise((resolve, reject) => /** @type {HTMLCanvasElement} */ (c).toBlob((b) => (b ? resolve(b) : reject(new Error("Encoding failed"))), type, quality));
}

/** Preload resources: id → url map → id → decoded image. */
export async function loadImages(urls) {
    const out = new Map();
    await Promise.all(Object.entries(urls).map(async ([id, url]) => out.set(id, await loadImage(url))));
    return out;
}

/** @returns {Promise<HTMLImageElement>} */
export function loadImage(url) {
    return new Promise((resolve, reject) => {
        const img = new Image();
        img.decoding = "async";
        img.crossOrigin = "anonymous";
        img.onload = () => resolve(img);
        img.onerror = () => reject(new Error(`Could not load image ${String(url).slice(0, 80)}`));
        img.src = url;
    });
}

