// @ts-check
import { createDocument, defaultBackground, newId } from "./model.js";
import { apply, invert, matScale, sourceToOutput, layout } from "./geometry.js";

/**
 * Convert a schemaVersion-1 document (the editor The Mothership shipped before this one)
 * into schemaVersion 2. v1 annotations were drawn in OUTPUT pixels after the
 * background; v2 objects live in source pixels. Mapping runs through the inverse
 * of the v2 export transform, so the converted document renders in the same place.
 *
 * Known approximations, recorded rather than hidden:
 * - v1 `background.scale` < 100 has no v2 equivalent and is dropped.
 * - v1 step labels become auto-numbered counters.
 * - v1 text used Arial at the same size; v2 "standard" uses the system UI font.
 */
export function fromV1(v1) {
    if (!v1 || v1.schemaVersion !== 1) throw new Error("Not a schemaVersion 1 document");
    const d = createDocument({ base: v1.base, width: v1.sourceWidth, height: v1.sourceHeight });
    d.crop = { ...v1.crop };
    d.resize = { ...v1.resize };
    d.rotation = v1.rotation;
    d.flipX = !!v1.flipX;
    d.flipY = !!v1.flipY;
    d.adjustments = { ...d.adjustments, ...v1.adjustments };
    d.objectShadow = false; // v1 annotations had no shadow
    const b1 = v1.background || {};
    const bg = /** @type {any} */ (defaultBackground());
    bg.enabled = !!b1.enabled;
    bg.type = b1.type === "upload" ? "image" : b1.type === "gradient" ? "gradient" : "solid";
    bg.color = b1.color || "#ffffff";
    bg.gradient = [b1.color || "#ffffff", b1.color2 || "#ddd6fe"];
    bg.angle = Number.isFinite(b1.angle) ? b1.angle : 135;
    bg.image = b1.image ?? null;
    bg.padding = Math.round(b1.padding ?? 40);
    bg.ratio = b1.ratio ?? null;
    bg.shadow = b1.shadow ?? 0;
    const align = b1.align || [0.5, 0.5];
    bg.alignment = Math.round(align[1] * 2) * 3 + Math.round(align[0] * 2);
    d.background = /** @type {any} */ (bg);
    const l = layout(d);
    const minSide = Math.min(l.frame.width, l.frame.height) || 1;
    bg.corners = Math.max(0, Math.min(100, ((b1.radius ?? 0) / (0.12 * minSide)) * 100));

    const inv = invert(sourceToOutput(d));
    const k = matScale(inv);
    const P = (x, y) => apply(inv, x, y);
    const R = (x, y, w, h) => {
        const [ax, ay] = P(x, y), [bx, by] = P(x + w, y + h);
        return { x: Math.min(ax, bx), y: Math.min(ay, by), width: Math.abs(bx - ax), height: Math.abs(by - ay) };
    };
    const size = (s) => Math.max(1, Math.min(200, s * k));
    for (const a of v1.annotations || []) {
        const id = String(a.id || newId()).slice(0, 64);
        switch (a.type) {
            case "rectangle":
                d.objects.push({ id, type: "rect", ...R(a.x, a.y, a.width, a.height), color: a.color, thickness: size(a.size) });
                break;
            case "highlight":
                d.objects.push({ id, type: "filled-rect", ...R(a.x, a.y, a.width, a.height), color: `${a.color.slice(0, 7)}4d` });
                break;
            case "blur":
            case "pixelate":
                d.objects.push({ id, type: "pixelate", ...R(a.x, a.y, a.width, a.height), style: a.type === "blur" ? "blur" : "pixelate", intensity: Math.max(1, Math.min(100, Math.round(((a.size * k - 4) / 36) * 100))), seed: 1 });
                break;
            case "step": {
                const [cx, cy] = P(a.x + a.width / 2, a.y + a.height / 2);
                d.objects.push({ id, type: "counter", x: cx, y: cy, color: a.color, size: Math.max(8, Math.min(400, Math.max(a.width, a.height) * k)) });
                break;
            }
            case "text": {
                const [x, y] = P(a.x, a.y);
                d.objects.push({ id, type: "text", x, y, text: a.text || "", color: a.color, fontSize: Math.max(4, Math.min(1000, a.size * k)), style: "standard" });
                break;
            }
            case "arrow": {
                const sx = a.x + (a.reverseX ? a.width : 0), sy = a.y + (a.reverseY ? a.height : 0);
                const ex = a.x + (a.reverseX ? 0 : a.width), ey = a.y + (a.reverseY ? 0 : a.height);
                d.objects.push({ id, type: "arrow", points: [P(sx, sy), P(ex, ey)], color: a.color, thickness: size(a.size), style: "standard" });
                break;
            }
            case "drawing": {
                const pts = (a.points || []).slice(0, 5000).map(([x, y]) => P(a.x + x, a.y + y));
                if (pts.length) d.objects.push({ id, type: "pencil", points: pts, color: a.color, thickness: size(a.size) });
                break;
            }
        }
    }
    return d;
}

/** Accept either schema and return a v2 document. */
export function toV2(doc) {
    return doc && doc.schemaVersion === 1 ? fromV1(doc) : doc;
}
