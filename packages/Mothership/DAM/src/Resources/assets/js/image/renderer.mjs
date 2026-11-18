import { geometry, validateDocument } from "./document.mjs";
const canvasOf = (width, height) =>
    Object.assign(document.createElement("canvas"), {
        width: Math.round(width),
        height: Math.round(height),
    });
// Decoded sources are keyed by URL. Editor resources are immutable per id, and
// the resource route is no-store, so without this every repaint refetched and
// re-decoded the full image.
const images = new Map();
export function loadImage(url) {
    let pending = images.get(url);
    if (!pending) {
        pending = (async () => {
            const image = new Image();
            image.src = url;
            await image.decode();
            return image;
        })();
        pending.catch(() => images.delete(url));
        images.set(url, pending);
    }
    return pending;
}
let adjusted = null;
export function releaseImages() {
    images.clear();
    adjusted = null;
}
export function adjustPixels(pixels, a) {
    const p = pixels.data,
        f =
            (259 * (a.contrast * 2.55 + 255)) /
            (255 * (259 - a.contrast * 2.55));
    for (let i = 0; i < p.length; i += 4) {
        for (let c = 0; c < 3; c++)
            p[i + c] = f * (p[i + c] - 128) + 128 + a.brightness * 2.55;
        if (a.grayscale) {
            const gray = 0.2126 * p[i] + 0.7152 * p[i + 1] + 0.0722 * p[i + 2];
            p[i] = p[i + 1] = p[i + 2] = gray;
        }
        if (a.invert) for (let c = 0; c < 3; c++) p[i + c] = 255 - p[i + c];
    }
    if (a.sharpen) {
        const input = new Uint8ClampedArray(p),
            w = pixels.width,
            h = pixels.height,
            amount = a.sharpen / 100;
        for (let y = 1; y < h - 1; y++)
            for (let x = 1; x < w - 1; x++)
                for (let c = 0; c < 3; c++) {
                    const i = (y * w + x) * 4 + c;
                    p[i] =
                        input[i] * (1 + 4 * amount) -
                        amount *
                            (input[i - 4] +
                                input[i + 4] +
                                input[i - 4 * w] +
                                input[i + 4 * w]);
                }
    }
    return pixels;
}
export const isIdentityAdjustment = (a) =>
    !a.brightness && !a.contrast && !a.sharpen && !a.grayscale && !a.invert;
export function makeMask({
    width,
    height,
    selection,
    canvas = canvasOf(width, height),
}) {
    canvas.width = width;
    canvas.height = height;
    const ctx = canvas.getContext("2d");
    ctx.fillStyle = "#000";
    ctx.fillRect(0, 0, width, height);
    ctx.clearRect(selection.x, selection.y, selection.width, selection.height);
    return canvas;
}
// Crop, resize and the per-pixel adjustments only change when those fields
// change; annotation, background and rotation edits reuse the last result.
async function adjustedStage({ doc, sourceUrl }) {
    const { blur, ...pixelAdjustments } = doc.adjustments,
        key = JSON.stringify([
            sourceUrl,
            doc.crop,
            doc.resize,
            pixelAdjustments,
        ]);
    if (adjusted?.key === key) return adjusted.canvas;
    const source = await loadImage(sourceUrl);
    // The per-pixel pass reads the whole bitmap back to the CPU; skip it
    // (and the CPU-backed canvas it needs) when every adjustment is at zero.
    const adjust = !isIdentityAdjustment(doc.adjustments),
        resized = canvasOf(doc.resize.width, doc.resize.height),
        rc = resized.getContext("2d", { willReadFrequently: adjust }),
        c = doc.crop;
    rc.drawImage(
        source,
        c.x,
        c.y,
        c.width,
        c.height,
        0,
        0,
        resized.width,
        resized.height,
    );
    if (adjust)
        rc.putImageData(
            adjustPixels(
                rc.getImageData(0, 0, resized.width, resized.height),
                doc.adjustments,
            ),
            0,
            0,
        );
    adjusted = { key, canvas: resized };
    return resized;
}
export async function renderDocument({ doc, resolve, canvas }) {
    validateDocument(doc);
    const g = geometry(doc),
        sourceUrl = resolve(doc.base),
        resized = await adjustedStage({ doc, sourceUrl }),
        turned = canvasOf(g.image.width, g.image.height),
        tc = turned.getContext("2d");
    tc.translate(turned.width / 2, turned.height / 2);
    tc.rotate((doc.rotation * Math.PI) / 180);
    tc.scale(doc.flipX ? -1 : 1, doc.flipY ? -1 : 1);
    tc.filter = `blur(${doc.adjustments.blur}px)`;
    tc.drawImage(resized, -resized.width / 2, -resized.height / 2);
    const out = canvasOf(g.width, g.height),
        ctx = out.getContext("2d"),
        b = doc.background;
    if (b.enabled) {
        ctx.fillStyle = b.color;
        if (b.type === "gradient") {
            const angle = (b.angle * Math.PI) / 180,
                dx = (Math.cos(angle) * g.width) / 2,
                dy = (Math.sin(angle) * g.height) / 2,
                gradient = ctx.createLinearGradient(
                    g.width / 2 - dx,
                    g.height / 2 - dy,
                    g.width / 2 + dx,
                    g.height / 2 + dy,
                );
            gradient.addColorStop(0, b.color);
            gradient.addColorStop(1, b.color2);
            ctx.fillStyle = gradient;
        }
        ctx.fillRect(0, 0, g.width, g.height);
        if (b.type === "upload" && b.image) {
            const bg = await loadImage(resolve(b.image)),
                s = Math.max(g.width / bg.width, g.height / bg.height);
            ctx.drawImage(
                bg,
                (g.width - bg.width * s) / 2,
                (g.height - bg.height * s) / 2,
                bg.width * s,
                bg.height * s,
            );
        }
    }
    ctx.save();
    if (b.enabled && b.shadow) {
        ctx.shadowColor = "#00000066";
        ctx.shadowBlur = b.shadow;
        ctx.shadowOffsetY = b.shadow / 3;
        ctx.fillStyle = b.color;
        ctx.beginPath();
        ctx.roundRect(g.x, g.y, g.drawWidth, g.drawHeight, b.radius);
        ctx.fill();
        ctx.shadowColor = "transparent";
    }
    ctx.beginPath();
    ctx.roundRect(
        g.x,
        g.y,
        g.drawWidth,
        g.drawHeight,
        b.enabled ? b.radius : 0,
    );
    ctx.clip();
    ctx.drawImage(turned, g.x, g.y, g.drawWidth, g.drawHeight);
    ctx.restore();
    for (const a of doc.annotations) drawAnnotation(ctx, a, out);
    if (canvas) {
        canvas.width = out.width;
        canvas.height = out.height;
        canvas.getContext("2d").drawImage(out, 0, 0);
    }
    return out;
}
function drawAnnotation(ctx, a, canvas) {
    ctx.save();
    ctx.strokeStyle = a.color;
    ctx.fillStyle = a.color;
    ctx.lineWidth = a.size;
    ctx.lineCap = "round";
    ctx.lineJoin = "round";
    if (a.type === "text") {
        ctx.font = `${a.size}px Arial, sans-serif`;
        ctx.textBaseline = "top";
        a.text
            .split("\n")
            .forEach((line, i) =>
                ctx.fillText(line, a.x, a.y + i * a.size * 1.2),
            );
    } else if (a.type === "step") {
        ctx.beginPath();
        ctx.arc(
            a.x + a.width / 2,
            a.y + a.height / 2,
            Math.max(a.width, a.height) / 2,
            0,
            Math.PI * 2,
        );
        ctx.fill();
        ctx.fillStyle = "#fff";
        ctx.font = `bold ${a.size}px Arial`;
        ctx.textAlign = "center";
        ctx.textBaseline = "middle";
        ctx.fillText(a.text, a.x + a.width / 2, a.y + a.height / 2);
    } else if (a.type === "rectangle")
        ctx.strokeRect(a.x, a.y, a.width, a.height);
    else if (a.type === "highlight") {
        ctx.globalAlpha = 0.3;
        ctx.fillRect(a.x, a.y, a.width, a.height);
    } else if (a.type === "blur" || a.type === "pixelate") {
        const x = Math.max(0, Math.round(a.x)),
            y = Math.max(0, Math.round(a.y)),
            w = Math.min(canvas.width - x, Math.max(1, Math.round(a.width))),
            h = Math.min(canvas.height - y, Math.max(1, Math.round(a.height)));
        if (w > 0 && h > 0) {
            const copy = canvasOf(w, h);
            copy.getContext("2d").drawImage(canvas, x, y, w, h, 0, 0, w, h);
            ctx.beginPath();
            ctx.rect(x, y, w, h);
            ctx.clip();
            if (a.type === "blur") {
                ctx.filter = `blur(${Math.max(2, a.size)}px)`;
                ctx.drawImage(copy, x, y);
            } else {
                const tiny = canvasOf(
                    Math.max(1, w / a.size),
                    Math.max(1, h / a.size),
                );
                tiny.getContext("2d").drawImage(
                    copy,
                    0,
                    0,
                    tiny.width,
                    tiny.height,
                );
                ctx.imageSmoothingEnabled = false;
                ctx.drawImage(tiny, x, y, w, h);
            }
        }
    } else if (a.type === "drawing") {
        ctx.beginPath();
        (a.points || []).forEach(([x, y], i) =>
            i ? ctx.lineTo(a.x + x, a.y + y) : ctx.moveTo(a.x + x, a.y + y),
        );
        ctx.stroke();
    } else if (a.type === "arrow") {
        const sx = a.x + (a.reverseX ? a.width : 0),
            sy = a.y + (a.reverseY ? a.height : 0),
            ex = a.x + (a.reverseX ? 0 : a.width),
            ey = a.y + (a.reverseY ? 0 : a.height),
            angle = Math.atan2(ey - sy, ex - sx),
            head = Math.max(12, a.size * 3);
        ctx.beginPath();
        ctx.moveTo(sx, sy);
        ctx.lineTo(ex, ey);
        ctx.stroke();
        ctx.beginPath();
        ctx.moveTo(ex, ey);
        ctx.lineTo(
            ex - head * Math.cos(angle - 0.5),
            ey - head * Math.sin(angle - 0.5),
        );
        ctx.lineTo(
            ex - head * Math.cos(angle + 0.5),
            ey - head * Math.sin(angle + 0.5),
        );
        ctx.closePath();
        ctx.fill();
    }
    ctx.restore();
}
// Pointer moves arrive faster than a full-resolution render completes. Run
// one render at a time and fold every request made meanwhile into a single
// follow-up render, so the preview always ends on the latest state.
export function createPainter(render) {
    let running = null,
        again = false;
    return function paint() {
        if (running) {
            again = true;
            return running;
        }
        running = (async () => {
            do {
                again = false;
                await render();
            } while (again);
        })().finally(() => {
            running = null;
        });
        return running;
    };
}
export const pngBlob = (canvas) =>
    new Promise((resolve, reject) =>
        canvas.toBlob(
            (blob) =>
                blob ? resolve(blob) : reject(Error("PNG export failed")),
            "image/png",
        ),
    );

// JPEG has no alpha: flatten onto white first so transparent areas do not turn black.
export const imageBlob = (canvas, type, quality) =>
    new Promise((resolve, reject) => {
        let source = canvas;
        if (type === "image/jpeg") {
            source = document.createElement("canvas");
            source.width = canvas.width;
            source.height = canvas.height;
            const context = source.getContext("2d");
            context.fillStyle = "#ffffff";
            context.fillRect(0, 0, source.width, source.height);
            context.drawImage(canvas, 0, 0);
        }
        source.toBlob(
            (blob) =>
                blob ? resolve(blob) : reject(Error("Image export failed")),
            type,
            quality,
        );
    });
