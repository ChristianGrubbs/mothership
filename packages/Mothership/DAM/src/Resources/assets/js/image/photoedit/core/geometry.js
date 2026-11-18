// @ts-check
/**
 * Geometry for the non-destructive pipeline:
 *   source space ──crop──▶ crop space ──resize──▶ resized ──rotate/flip──▶ image space ──background──▶ output
 * One affine matrix (`sourceToOutput`) maps object coordinates to export pixels;
 * the editor view adds a zoom/pan matrix on top. Every consumer (renderer, hit-testing,
 * handles, crop overlay) uses these functions, so preview and export cannot drift.
 */

/** @typedef {[number, number, number, number, number, number]} Mat  [a, b, c, d, e, f] */

/** @returns {Mat} */
export const identity = () => [1, 0, 0, 1, 0, 0];
/** @param {Mat} m @param {Mat} n @returns {Mat}  m ∘ n (apply n first, then m) */
export function multiply(m, n) {
    return [
        m[0] * n[0] + m[2] * n[1],
        m[1] * n[0] + m[3] * n[1],
        m[0] * n[2] + m[2] * n[3],
        m[1] * n[2] + m[3] * n[3],
        m[0] * n[4] + m[2] * n[5] + m[4],
        m[1] * n[4] + m[3] * n[5] + m[5],
    ];
}
/** @returns {Mat} */
export const translate = (x, y) => [1, 0, 0, 1, x, y];
/** @returns {Mat} */
export const scale = (sx, sy = sx) => [sx, 0, 0, sy, 0, 0];
/** Exact rotation for multiples of 90°. @returns {Mat} */
export function rotate(deg) {
    const r = ((deg % 360) + 360) % 360;
    const [c, s] = r === 0 ? [1, 0] : r === 90 ? [0, 1] : r === 180 ? [-1, 0] : r === 270 ? [0, -1] : [Math.cos((r * Math.PI) / 180), Math.sin((r * Math.PI) / 180)];
    return [c, s, -s, c, 0, 0];
}
/** @param {Mat} m @returns {Mat} */
export function invert(m) {
    const det = m[0] * m[3] - m[1] * m[2];
    if (!det) throw new Error("Matrix is not invertible");
    return [m[3] / det, -m[1] / det, -m[2] / det, m[0] / det, (m[2] * m[5] - m[3] * m[4]) / det, (m[1] * m[4] - m[0] * m[5]) / det];
}
/** @param {Mat} m @param {number} x @param {number} y @returns {[number, number]} */
export const apply = (m, x, y) => [m[0] * x + m[2] * y + m[4], m[1] * x + m[3] * y + m[5]];
/** Uniform-ish scale factor of a matrix (geometric mean of axis scales). */
export const matScale = (m) => Math.sqrt(Math.abs(m[0] * m[3] - m[1] * m[2]));

/** Size of the image after crop+resize+rotation (before background). */
export function imageSize(doc) {
    const turned = doc.rotation % 180 !== 0;
    return { width: turned ? doc.resize.height : doc.resize.width, height: turned ? doc.resize.width : doc.resize.height };
}

/**
 * Background layout in output pixels. Must stay identical to the server's
 * `dimensions()` so the rendered export matches what the server validates.
 */
export function layout(doc) {
    const img = imageSize(doc);
    const b = doc.background;
    if (!b.enabled) {
        return { width: img.width, height: img.height, frame: { x: 0, y: 0, width: img.width, height: img.height }, image: { x: 0, y: 0, ...img }, inset: 0, radius: 0 };
    }
    const fw = img.width + 2 * b.inset;
    const fh = img.height + 2 * b.inset;
    let W = fw + 2 * b.padding;
    let H = fh + 2 * b.padding;
    if (b.ratio) {
        const r = b.ratio[0] / b.ratio[1];
        if (W / H < r) W = Math.round(H * r);
        else if (W / H > r) H = Math.round(W / r);
    }
    const col = b.alignment % 3;
    const row = Math.floor(b.alignment / 3);
    const fx = b.padding + ((W - fw - 2 * b.padding) * col) / 2;
    const fy = b.padding + ((H - fh - 2 * b.padding) * row) / 2;
    const radius = (b.corners / 100) * Math.min(fw, fh) * 0.12;
    return {
        width: W,
        height: H,
        frame: { x: fx, y: fy, width: fw, height: fh },
        image: { x: fx + b.inset, y: fy + b.inset, ...img },
        inset: b.inset,
        radius,
    };
}

/** Exact output dimensions of an export (integers). */
export function outputSize(doc) {
    const l = layout(doc);
    return { width: Math.round(l.width), height: Math.round(l.height) };
}

/** Source → resized image (crop + resize), before rotation. */
export function sourceToResized(doc) {
    return multiply(scale(doc.resize.width / doc.crop.width, doc.resize.height / doc.crop.height), translate(-doc.crop.x, -doc.crop.y));
}

/** Resized → image space (rotation + flip about the centre, like CSS/canvas would). */
export function resizedToImage(doc) {
    const img = imageSize(doc);
    return multiply(
        multiply(multiply(translate(img.width / 2, img.height / 2), rotate(doc.rotation)), scale(doc.flipX ? -1 : 1, doc.flipY ? -1 : 1)),
        translate(-doc.resize.width / 2, -doc.resize.height / 2),
    );
}

/**
 * Text stays upright and undistorted in the output whatever the rotation, flip or
 * non-uniform resize: a text object's local frame is T = s·L⁻¹ (L = linear part of
 * source→output, s = √|det L|), anchored at (o.x, o.y). Returns T as [a, b, c, d].
 */
export function textOrientation(doc) {
    const M = sourceToOutput(doc);
    const det = M[0] * M[3] - M[1] * M[2];
    const s = Math.sqrt(Math.abs(det)) / det;
    return [M[3] * s, -M[1] * s, -M[2] * s, M[0] * s];
}

/** Source → output pixels (the export transform). `balance` shifts content for auto-balance. */
export function sourceToOutput(doc, balance = { dx: 0, dy: 0 }) {
    const l = layout(doc);
    return multiply(translate(l.image.x + balance.dx, l.image.y + balance.dy), multiply(resizedToImage(doc), sourceToResized(doc)));
}

// ---------------------------------------------------------------------------
// Object geometry (source space)

/** @param {Rect} r */
export const normRect = (r) => ({
    x: Math.min(r.x, r.x + r.width),
    y: Math.min(r.y, r.y + r.height),
    width: Math.abs(r.width),
    height: Math.abs(r.height),
});
/** @typedef {{x:number,y:number,width:number,height:number}} Rect */

export function pointsBounds(points, pad = 0) {
    let x0 = Infinity, y0 = Infinity, x1 = -Infinity, y1 = -Infinity;
    for (const [x, y] of points) {
        if (x < x0) x0 = x;
        if (y < y0) y0 = y;
        if (x > x1) x1 = x;
        if (y > y1) y1 = y;
    }
    return { x: x0 - pad, y: y0 - pad, width: x1 - x0 + 2 * pad, height: y1 - y0 + 2 * pad };
}

export function unionRect(a, b) {
    if (!a) return b;
    if (!b) return a;
    const x = Math.min(a.x, b.x), y = Math.min(a.y, b.y);
    return { x, y, width: Math.max(a.x + a.width, b.x + b.width) - x, height: Math.max(a.y + a.height, b.y + b.height) - y };
}

/** Default curve control point for a curved arrow: perpendicular bulge at the midpoint. */
export function arrowControl(o) {
    if (o.control) return o.control;
    const [[x1, y1], [x2, y2]] = o.points;
    const mx = (x1 + x2) / 2, my = (y1 + y2) / 2;
    const dx = x2 - x1, dy = y2 - y1;
    const len = Math.hypot(dx, dy) || 1;
    const bulge = len * 0.2;
    return [mx + (-dy / len) * bulge, my + (dx / len) * bulge];
}

export function quadPoint(p0, c, p1, t) {
    const u = 1 - t;
    return [u * u * p0[0] + 2 * u * t * c[0] + t * t * p1[0], u * u * p0[1] + 2 * u * t * c[1] + t * t * p1[1]];
}

/**
 * Selection bounds of an object in source space. `measure` returns text box
 * metrics (see text.js) — injected so geometry stays DOM-free.
 */
export function objectBounds(o, measure) {
    switch (o.type) {
        case "rect":
        case "ellipse":
        case "filled-rect":
        case "pixelate":
        case "spotlight":
        case "image":
            return normRect(o);
        case "line":
            return pointsBounds(o.points);
        case "arrow": {
            if (o.style !== "curved") return pointsBounds(o.points);
            // UPSTREAM-DEVIATION (The Mothership): a quadratic's extrema need not be at its midpoint.
            const [p0, p1] = o.points, c = arrowControl(o), points = [...o.points];
            for (let axis = 0; axis < 2; axis++) {
                const denominator = p0[axis] - 2 * c[axis] + p1[axis];
                const t = denominator ? (p0[axis] - c[axis]) / denominator : -1;
                if (t > 0 && t < 1) points.push(quadPoint(p0, c, p1, t));
            }
            return pointsBounds(points);
        }
        case "pencil":
        case "highlighter":
            return pointsBounds(o.points, o.thickness / 2);
        case "counter":
            return { x: o.x - o.size / 2, y: o.y - o.size / 2, width: o.size, height: o.size };
        case "text": {
            const m = measure ? measure(o) : { width: o.fontSize * Math.max(1, o.text.length) * 0.6, height: o.fontSize * 1.25, padX: 0, padY: 0 };
            // `measure.orient` (see textOrientation) maps the text's local box into source space.
            const T = measure && measure.orient;
            if (!T) return { x: o.x, y: o.y, width: m.width, height: m.height };
            const ax = T[0] * m.width, ay = T[1] * m.width, bx = T[2] * m.height, by = T[3] * m.height;
            const xs = [0, ax, bx, ax + bx], ys = [0, ay, by, ay + by];
            const x0 = Math.min(...xs), y0 = Math.min(...ys);
            return { x: o.x + x0, y: o.y + y0, width: Math.max(...xs) - x0, height: Math.max(...ys) - y0 };
        }
    }
    return { x: 0, y: 0, width: 0, height: 0 };
}

function distToSegment(px, py, [x1, y1], [x2, y2]) {
    const dx = x2 - x1, dy = y2 - y1;
    const len2 = dx * dx + dy * dy;
    const t = len2 ? Math.max(0, Math.min(1, ((px - x1) * dx + (py - y1) * dy) / len2)) : 0;
    return Math.hypot(px - (x1 + t * dx), py - (y1 + t * dy));
}

function distToPolyline(px, py, pts) {
    if (pts.length === 1) return Math.hypot(px - pts[0][0], py - pts[0][1]);
    let d = Infinity;
    for (let i = 1; i < pts.length; i++) d = Math.min(d, distToSegment(px, py, pts[i - 1], pts[i]));
    return d;
}

export function curvePoints(o, steps = 32) {
    const c = arrowControl(o);
    const out = [];
    for (let i = 0; i <= steps; i++) out.push(quadPoint(o.points[0], c, o.points[1], i / steps));
    return out;
}

const inRect = (px, py, r, pad = 0) => px >= r.x - pad && px <= r.x + r.width + pad && py >= r.y - pad && py <= r.y + r.height + pad;

/**
 * Does source-space point (px, py) hit object `o`? `tol` is in source pixels.
 */
export function hitObject(o, px, py, tol, measure) {
    switch (o.type) {
        case "filled-rect":
        case "pixelate":
        case "spotlight":
        case "image":
        case "text":
        case "counter":
            return inRect(px, py, objectBounds(o, measure), tol);
        case "rect": {
            const r = normRect(o);
            const half = o.thickness / 2 + tol;
            if (!inRect(px, py, r, half)) return false;
            // On the outline band, or inside a thin shape where the band covers everything.
            return !inRect(px, py, r, -half) || r.width < half * 2 || r.height < half * 2;
        }
        case "ellipse": {
            const r = normRect(o);
            const rx = r.width / 2, ry = r.height / 2;
            if (rx < 1 || ry < 1) return inRect(px, py, r, tol + o.thickness / 2);
            const cx = r.x + rx, cy = r.y + ry;
            const nx = (px - cx) / rx, ny = (py - cy) / ry;
            const d = Math.hypot(nx, ny);
            const band = (o.thickness / 2 + tol) / Math.min(rx, ry);
            return Math.abs(d - 1) <= band;
        }
        case "line":
            return distToSegment(px, py, o.points[0], o.points[1]) <= o.thickness / 2 + tol;
        case "arrow": {
            if (distToPolyline(px, py, o.style === "curved" ? curvePoints(o) : o.points) <= o.thickness / 2 + tol + 4) return true;
            // The filled head is wider than the shaft (same size formula as arrows.js headSize).
            const head = (o.thickness * 3.2 + 10) * 0.75 + tol;
            const tips = o.style === "double" ? o.points : [o.points[1]];
            return tips.some(([x, y]) => Math.hypot(px - x, py - y) <= head);
        }
        case "pencil":
        case "highlighter":
            return distToPolyline(px, py, o.points) <= o.thickness / 2 + tol;
    }
    return false;
}

/**
 * Handles for a selected object in source space.
 * @returns {{id:string, x:number, y:number}[]}
 */
export function objectHandles(o, measure) {
    if (o.type === "line" || o.type === "arrow") {
        const h = [
            { id: "p0", x: o.points[0][0], y: o.points[0][1] },
            { id: "p1", x: o.points[1][0], y: o.points[1][1] },
        ];
        if (o.type === "arrow" && o.style === "curved") {
            const m = quadPoint(o.points[0], arrowControl(o), o.points[1], 0.5);
            h.push({ id: "ctrl", x: m[0], y: m[1] });
        }
        return h;
    }
    if (o.type === "counter") return [];
    const r = objectBounds(o, measure);
    return [
        { id: "nw", x: r.x, y: r.y },
        { id: "ne", x: r.x + r.width, y: r.y },
        { id: "se", x: r.x + r.width, y: r.y + r.height },
        { id: "sw", x: r.x, y: r.y + r.height },
    ];
}

/** Constrain a drag vector to 0/45/90° (Shift on lines and arrows). */
export function snapAngle(x0, y0, x1, y1) {
    const dx = x1 - x0, dy = y1 - y0;
    const len = Math.hypot(dx, dy);
    const a = Math.round(Math.atan2(dy, dx) / (Math.PI / 4)) * (Math.PI / 4);
    return [x0 + Math.cos(a) * len, y0 + Math.sin(a) * len];
}

/** Rectangle from a drag, with optional square constraint and draw-from-centre. */
export function dragRect(x0, y0, x1, y1, { square = false, fromCenter = false } = {}) {
    let w = x1 - x0, h = y1 - y0;
    if (square) {
        const s = Math.max(Math.abs(w), Math.abs(h));
        w = Math.sign(w || 1) * s;
        h = Math.sign(h || 1) * s;
    }
    if (fromCenter) return normRect({ x: x0 - w, y: y0 - h, width: 2 * w, height: 2 * h });
    return normRect({ x: x0, y: y0, width: w, height: h });
}

/** Keep the entire stroke within the schema budget, including both endpoints. */
export function limitStrokePoints(points) {
    const limit = 5000;
    // UPSTREAM-DEVIATION (The Mothership): truncating a smoothed stroke discards its tail.
    if (points.length <= limit) return points.map(([x, y]) => [x, y]);
    const extrema = [0, 0, 0, 0];
    for (let i = 1; i < points.length; i++) {
        if (points[i][0] < points[extrema[0]][0]) extrema[0] = i;
        if (points[i][0] > points[extrema[1]][0]) extrema[1] = i;
        if (points[i][1] < points[extrema[2]][1]) extrema[2] = i;
        if (points[i][1] > points[extrema[3]][1]) extrema[3] = i;
    }
    const kept = new Set([0, points.length - 1, ...extrema]);
    const remaining = limit - kept.size;
    for (let i = 1; i <= remaining; i++) kept.add(Math.round(i * (points.length - 1) / (remaining + 1)));
    for (let i = 0; kept.size < limit; i++) kept.add(i);
    return [...kept].sort((a, b) => a - b).map((i) => [...points[i]]);
}

/** Append without resampling the live prefix on every move; compact only at the bounded high-water mark. */
export function appendStrokePoint(points, point) {
    points.push(point);
    if (points.length >= 10000) points.splice(0, points.length, ...limitStrokePoints(points));
    return points;
}

/** Ramer–Douglas–Peucker simplification + Chaikin smoothing for pencil strokes. */
export function smoothStroke(points, epsilon = 0.75) {
    if (points.length < 3) return points.map((p) => [p[0], p[1]]);
    const keep = new Uint8Array(points.length);
    keep[0] = keep[points.length - 1] = 1;
    const stack = [[0, points.length - 1]];
    while (stack.length) {
        const [s, e] = /** @type {[number,number]} */ (stack.pop());
        let max = 0, idx = -1;
        for (let i = s + 1; i < e; i++) {
            const d = distToSegment(points[i][0], points[i][1], points[s], points[e]);
            if (d > max) { max = d; idx = i; }
        }
        if (max > epsilon && idx > 0) {
            keep[idx] = 1;
            stack.push([s, idx], [idx, e]);
        }
    }
    let pts = points.filter((_, i) => keep[i]);
    for (let pass = 0; pass < 2 && pts.length > 2; pass++) {
        const out = [pts[0]];
        for (let i = 0; i < pts.length - 1; i++) {
            const [x0, y0] = pts[i], [x1, y1] = pts[i + 1];
            out.push([0.75 * x0 + 0.25 * x1, 0.75 * y0 + 0.25 * y1], [0.25 * x0 + 0.75 * x1, 0.25 * y0 + 0.75 * y1]);
        }
        out.push(pts[pts.length - 1]);
        pts = out;
    }
    return pts.map(([x, y]) => [Math.round(x * 100) / 100, Math.round(y * 100) / 100]);
}

/** Snap a nearly-horizontal or vertical highlighter stroke to a straight line. */
export function snapHighlighter(points) {
    if (points.length < 2) return points;
    const a = points[0], b = points[points.length - 1];
    const dx = b[0] - a[0], dy = b[1] - a[1];
    const len = Math.hypot(dx, dy);
    if (len < 12) return points;
    const maxDev = Math.max(...points.map(([x, y]) => distToSegment(x, y, a, b)));
    if (maxDev > Math.max(6, len * 0.08)) return points;
    if (Math.abs(dy) <= Math.abs(dx) * 0.2) {
        const y = points.reduce((s, p) => s + p[1], 0) / points.length;
        return [[a[0], y], [b[0], y]];
    }
    if (Math.abs(dx) <= Math.abs(dy) * 0.2) {
        const x = points.reduce((s, p) => s + p[0], 0) / points.length;
        return [[x, a[1]], [x, b[1]]];
    }
    return [a, b];
}

/** Translate an object by (dx, dy) in place. */
export function moveObject(o, dx, dy) {
    if ("points" in o) o.points = o.points.map(([x, y]) => [x + dx, y + dy]);
    if (o.control) o.control = [o.control[0] + dx, o.control[1] + dy];
    if ("x" in o) {
        o.x += dx;
        o.y += dy;
    }
}

/**
 * Map an object's geometry from old bounds to new bounds (corner resize).
 * Points scale proportionally; text scales its font size.
 */
export function reshapeObject(o, from, to) {
    const sx = from.width ? to.width / from.width : 1;
    const sy = from.height ? to.height / from.height : 1;
    const mapX = (x) => to.x + (x - from.x) * sx;
    const mapY = (y) => to.y + (y - from.y) * sy;
    if ("points" in o) o.points = o.points.map(([x, y]) => [mapX(x), mapY(y)]);
    if (o.control) o.control = [mapX(o.control[0]), mapY(o.control[1])];
    if (o.type === "text") {
        o.fontSize = Math.max(4, Math.min(1000, o.fontSize * Math.sqrt(Math.abs(sx * sy))));
        o.x = mapX(o.x);
        o.y = mapY(o.y);
    } else if ("width" in o) {
        o.x = to.x;
        o.y = to.y;
        o.width = to.width;
        o.height = to.height;
    }
}

/** Clamp a crop rect to the source when canvas expansion is not allowed. */
export function clampRect(r, w, h) {
    const x = Math.max(0, Math.min(r.x, w - 1));
    const y = Math.max(0, Math.min(r.y, h - 1));
    return { x, y, width: Math.max(1, Math.min(r.width, w - x)), height: Math.max(1, Math.min(r.height, h - y)) };
}
