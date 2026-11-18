// @ts-check
import { arrowControl } from "./geometry.js";

/**
 * Arrow drawing. All geometry is computed in source space from the two end
 * points (and the control point for curved arrows), so arrows scale with the
 * image and look identical in preview and export.
 */

const headSize = (t) => ({ length: t * 3.2 + 10, width: t * 2.8 + 9 });

function head(ctx, tip, angle, t, swept) {
    const { length, width } = headSize(t);
    const ux = Math.cos(angle), uy = Math.sin(angle);
    const px = -uy, py = ux;
    const bx = tip[0] - ux * length, by = tip[1] - uy * length;
    ctx.beginPath();
    ctx.moveTo(tip[0], tip[1]);
    ctx.lineTo(bx + px * (width / 2), by + py * (width / 2));
    if (swept) ctx.lineTo(tip[0] - ux * length * 0.68, tip[1] - uy * length * 0.68);
    ctx.lineTo(bx - px * (width / 2), by - py * (width / 2));
    ctx.closePath();
    ctx.fill();
    return swept ? length * 0.68 : length * 0.8;
}

/**
 * @param {CanvasRenderingContext2D|OffscreenCanvasRenderingContext2D} ctx
 * @param {any} o arrow object
 * @param {(ctx:any)=>void} shadow
 */
export function drawArrow(ctx, o, shadow) {
    const [p0, p1] = o.points;
    const t = o.thickness;
    const len = Math.hypot(p1[0] - p0[0], p1[1] - p0[1]);
    if (len < 0.5) return;
    ctx.save();
    shadow(ctx);
    ctx.fillStyle = o.color;
    ctx.strokeStyle = o.color;
    ctx.lineCap = "round";
    ctx.lineJoin = "round";

    if (o.style === "curved") {
        const c = arrowControl(o);
        const endAngle = Math.atan2(p1[1] - c[1], p1[0] - c[0]);
        const { length } = headSize(t);
        // Stop the shaft inside the head so the round cap never pokes out of the tip.
        const back = Math.min(length * 0.8, len * 0.45);
        const end = [p1[0] - Math.cos(endAngle) * back, p1[1] - Math.sin(endAngle) * back];
        ctx.lineWidth = t;
        ctx.beginPath();
        ctx.moveTo(p0[0], p0[1]);
        ctx.quadraticCurveTo(c[0], c[1], end[0], end[1]);
        ctx.stroke();
        head(ctx, p1, endAngle, t, false);
        ctx.restore();
        return;
    }

    const angle = Math.atan2(p1[1] - p0[1], p1[0] - p0[0]);
    const ux = Math.cos(angle), uy = Math.sin(angle);

    if (o.style === "fancy") {
        // Tapered shaft: hairline at the tail, full width at the swept head.
        const { length, width } = headSize(t * 1.15);
        const inset = Math.min(length * 0.68, len * 0.7);
        const neck = [p1[0] - ux * inset, p1[1] - uy * inset];
        const px = -uy, py = ux;
        const tail = Math.max(0.6, t * 0.12), wide = t * 0.75;
        ctx.beginPath();
        ctx.moveTo(p0[0] + px * tail, p0[1] + py * tail);
        ctx.lineTo(neck[0] + px * wide, neck[1] + py * wide);
        ctx.lineTo(neck[0] - px * wide, neck[1] - py * wide);
        ctx.lineTo(p0[0] - px * tail, p0[1] - py * tail);
        ctx.closePath();
        ctx.fill();
        const bx = p1[0] - ux * Math.min(length, len), by = p1[1] - uy * Math.min(length, len);
        ctx.beginPath();
        ctx.moveTo(p1[0], p1[1]);
        ctx.lineTo(bx + px * (width / 2), by + py * (width / 2));
        ctx.lineTo(neck[0], neck[1]);
        ctx.lineTo(bx - px * (width / 2), by - py * (width / 2));
        ctx.closePath();
        ctx.fill();
        ctx.restore();
        return;
    }

    const { length } = headSize(t);
    const double = o.style === "double";
    const cap = Math.min(length * 0.8, len * (double ? 0.4 : 0.8));
    const start = double ? [p0[0] + ux * cap, p0[1] + uy * cap] : p0;
    const end = [p1[0] - ux * cap, p1[1] - uy * cap];
    ctx.lineWidth = t;
    ctx.beginPath();
    ctx.moveTo(start[0], start[1]);
    ctx.lineTo(end[0], end[1]);
    ctx.stroke();
    head(ctx, p1, angle, t, false);
    if (double) head(ctx, p0, angle + Math.PI, t, false);
    ctx.restore();
}
