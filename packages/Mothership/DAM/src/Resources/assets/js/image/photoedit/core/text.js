// @ts-check
/**
 * Text layout shared by the on-screen preview, the export renderer and the
 * text-editing overlay. Text only wraps on explicit newlines, so a given string
 * lays out identically everywhere the same font is available.
 */

export const FONT_FAMILIES = Object.freeze({
    sans: '-apple-system, BlinkMacSystemFont, "Segoe UI", "Helvetica Neue", Helvetica, Arial, sans-serif',
    rounded: 'ui-rounded, "SF Pro Rounded", "Arial Rounded MT Bold", "Nunito", -apple-system, system-ui, sans-serif',
    mono: 'ui-monospace, "SF Mono", Menlo, Monaco, Consolas, "Liberation Mono", monospace',
});

export const LINE_HEIGHT = 1.25;

/** Font descriptor for a text style. */
export function textFont(style) {
    const family = style.startsWith("rounded") ? FONT_FAMILIES.rounded : style.startsWith("mono") ? FONT_FAMILIES.mono : FONT_FAMILIES.sans;
    const weight = style.startsWith("mono") ? 600 : 700;
    return { family, weight };
}

export function fontString(o) {
    const f = textFont(o.style);
    return `${f.weight} ${o.fontSize}px ${f.family}`;
}

export const isBoxStyle = (style) => style === "box" || style === "rounded-box" || style === "mono-box";

/** Padding around the glyphs for a style (source px). */
export function textPadding(o) {
    if (isBoxStyle(o.style)) return { x: Math.round(o.fontSize * 0.45), y: Math.round(o.fontSize * 0.22) };
    if (o.style === "outline") {
        const w = outlineWidth(o);
        return { x: w, y: w };
    }
    return { x: 0, y: 0 };
}

export const outlineWidth = (o) => Math.max(2, o.fontSize * 0.16);

/** Relative luminance of a #rrggbb color (0..1). */
export function luminance(hex) {
    const n = parseInt(hex.slice(1, 7), 16);
    const ch = [(n >> 16) & 255, (n >> 8) & 255, n & 255].map((v) => {
        const c = v / 255;
        return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
    });
    return 0.2126 * ch[0] + 0.7152 * ch[1] + 0.0722 * ch[2];
}

/** Text/ink colors for a style: box styles invert onto a filled box. */
export function textColors(o) {
    const light = luminance(o.color) > 0.6;
    if (isBoxStyle(o.style)) return { fill: light ? "#16181d" : "#ffffff", box: o.color, stroke: null };
    if (o.style === "outline") return { fill: o.color, box: null, stroke: light ? "#16181d" : "#ffffff" };
    return { fill: o.color, box: null, stroke: null };
}

/**
 * Create a measurer bound to a 2D context. Results are cached per (font, text).
 * @param {CanvasRenderingContext2D|OffscreenCanvasRenderingContext2D} ctx
 */
export function createMeasurer(ctx) {
    const cache = new Map();
    /** @param {any} o */
    function measure(o) {
        const font = fontString(o);
        const key = `${font}\u0000${o.style}\u0000${o.text}`;
        let m = cache.get(key);
        if (!m) {
            ctx.save();
            ctx.font = font;
            const lines = o.text.split("\n");
            const widths = lines.map((l) => ctx.measureText(l || " ").width);
            ctx.restore();
            const pad = textPadding(o);
            const lh = o.fontSize * LINE_HEIGHT;
            const contentW = Math.max(o.fontSize * 0.5, ...widths);
            m = {
                lines,
                widths,
                lineHeight: lh,
                padX: pad.x,
                padY: pad.y,
                width: contentW + 2 * pad.x,
                height: lines.length * lh + 2 * pad.y,
            };
            if (cache.size > 500) cache.clear();
            cache.set(key, m);
        }
        return m;
    }
    return measure;
}

/**
 * Draw a text object in source space.
 * @param {CanvasRenderingContext2D|OffscreenCanvasRenderingContext2D} ctx
 */
export function drawText(ctx, o, measure, shadow) {
    if (!o.text) return;
    const m = measure(o);
    const c = textColors(o);
    ctx.save();
    if (c.box) {
        const r = o.style === "rounded-box" ? Math.min(m.height / 2, o.fontSize * 0.7) : o.fontSize * 0.18;
        shadow(ctx);
        ctx.fillStyle = c.box;
        ctx.beginPath();
        ctx.roundRect(o.x, o.y, m.width, m.height, r);
        ctx.fill();
        ctx.shadowColor = "transparent";
    }
    ctx.font = fontString(o);
    ctx.textBaseline = "middle";
    ctx.textAlign = "left";
    const x = o.x + m.padX;
    m.lines.forEach((line, i) => {
        const y = o.y + m.padY + m.lineHeight * (i + 0.5);
        if (c.stroke) {
            ctx.save();
            shadow(ctx);
            ctx.strokeStyle = c.stroke;
            ctx.lineWidth = outlineWidth(o) * 2;
            ctx.lineJoin = "round";
            ctx.miterLimit = 2;
            ctx.strokeText(line, x, y);
            ctx.restore();
        } else if (!c.box) shadow(ctx);
        ctx.fillStyle = c.fill;
        ctx.fillText(line, x, y);
        ctx.shadowColor = "transparent";
    });
    ctx.restore();
}
