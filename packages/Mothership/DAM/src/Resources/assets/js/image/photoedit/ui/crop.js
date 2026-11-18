// @ts-check
/**
 * Crop & resize mode. The crop rectangle lives in SOURCE pixels (it may extend
 * beyond the image to grow the canvas). Editing happens in "display space" — the
 * rotated/flipped, unscaled view of the source — so handles stay screen-aligned
 * for any 90° rotation; results are mapped back to source space. Objects are
 * never touched, so annotations cannot drift when cropping.
 */
import { clone, LIMITS } from "../core/model.js";
import { apply, invert, multiply, sourceToOutput, normRect } from "../core/geometry.js";
import { h, iconButton, popover, closePopover, menuList, modKey, isMac } from "./dom.js";
import { ICONS } from "./icons.js";

// Same entries and order as the reference's ratio menu; portrait variants come from the swap button.
const ASPECTS = [
    { id: "free", label: "Freeform", ratio: null },
    { id: "original", label: "Original Ratio", ratio: "original" },
    { id: "-", label: "" },
    { id: "1:1", label: "1 : 1 (Square)", ratio: [1, 1] },
    { id: "5:4", label: "5 : 4 (10 : 8)", ratio: [5, 4] },
    { id: "7:5", label: "7 : 5", ratio: [7, 5] },
    { id: "4:3", label: "4 : 3", ratio: [4, 3] },
    { id: "3:2", label: "3 : 2 (6 : 4)", ratio: [3, 2] },
    { id: "16:9", label: "16 : 9", ratio: [16, 9] },
    { id: "-", label: "" },
    { id: "custom", label: "Custom Ratio…", ratio: "custom" },
];
const SNAP_PX = 8;
const HANDLE_PX = 10;
const MIN_PX = 4;

/** @param {any} ctx editor bridge (see editor.js) */
export function createCropMode(ctx) {
    /** draft state while the mode is open */
    let d = /** @type {any} */ (null);
    let frozen = /** @type {any} */ (null); // display doc frozen during a drag
    let drag = /** @type {any} */ (null);
    let snapEdges = true;
    const inputs = { w: /** @type {any} */ (null), h: /** @type {any} */ (null) };

    const tr = ctx.tr;

    function enter() {
        const doc = ctx.doc;
        d = {
            crop: { ...doc.crop },
            rotation: doc.rotation,
            flipX: doc.flipX,
            flipY: doc.flipY,
            // Output px per source px, per axis: a non-uniform resize must survive an unchanged crop.
            kx: doc.resize.width / doc.crop.width,
            ky: doc.resize.height / doc.crop.height,
            aspect: "free", portrait: false, custom: null,
        };
        try {
            snapEdges = localStorage.getItem("photoedit.crop.snap") !== "0";
        } catch {
            snapEdges = true;
        }
    }

    /** Visible region in source px: union of the image and the crop rectangle. */
    function region() {
        const c = d.crop, sw = ctx.doc.sourceWidth, sh = ctx.doc.sourceHeight;
        const x0 = Math.floor(Math.min(0, c.x)), y0 = Math.floor(Math.min(0, c.y));
        const x1 = Math.ceil(Math.max(sw, c.x + c.width)), y1 = Math.ceil(Math.max(sh, c.y + c.height));
        return { x: x0, y: y0, width: x1 - x0, height: y1 - y0 };
    }

    function displayDoc() {
        if (frozen) return frozen;
        const doc = ctx.doc;
        const r = region();
        const bg = { ...doc.background, enabled: false };
        return { ...doc, crop: r, resize: { width: r.width, height: r.height }, rotation: d.rotation, flipX: d.flipX, flipY: d.flipY, background: bg };
    }

    /** source → display (unscaled output) */
    const S2D = () => sourceToOutput(displayDoc());
    const D2Screen = () => /** @type {any} */ ([ctx.view.zoom, 0, 0, ctx.view.zoom, ctx.view.panX, ctx.view.panY]);

    function rectToDisplay(r) {
        const M = S2D();
        const a = apply(M, r.x, r.y), b = apply(M, r.x + r.width, r.y + r.height);
        return { x: Math.min(a[0], b[0]), y: Math.min(a[1], b[1]), width: Math.abs(b[0] - a[0]), height: Math.abs(b[1] - a[1]) };
    }
    function rectFromDisplay(r) {
        const M = invert(S2D());
        const a = apply(M, r.x, r.y), b = apply(M, r.x + r.width, r.y + r.height);
        return normRect({ x: a[0], y: a[1], width: b[0] - a[0], height: b[1] - a[1] });
    }
    const screenToDisplay = (sx, sy) => apply(invert(D2Screen()), sx, sy);
    const displayToScreen = (x, y) => apply(D2Screen(), x, y);

    /** Aspect ratio (w/h) in display orientation, or null. */
    function aspectRatio() {
        const a = ASPECTS.find((x) => x.id === d.aspect);
        if (!a || !a.ratio) return null;
        if (a.ratio === "custom") return d.custom ? (d.portrait ? d.custom[1] / d.custom[0] : d.custom[0] / d.custom[1]) : null;
        if (a.ratio === "original") {
            const turned = d.rotation % 180 !== 0;
            const sw = ctx.doc.sourceWidth, sh = ctx.doc.sourceHeight;
            return turned ? sh / sw : sw / sh;
        }
        const r = /** @type {number[]} */ (a.ratio);
        return d.portrait ? r[1] / r[0] : r[0] / r[1];
    }

    function imageRectDisplay() {
        return rectToDisplay({ x: 0, y: 0, width: ctx.doc.sourceWidth, height: ctx.doc.sourceHeight });
    }

    function snapValue(v, targets, tol) {
        let best = v, bd = tol;
        for (const t of targets) {
            const dd = Math.abs(v - t);
            if (dd <= bd) {
                bd = dd;
                best = t;
            }
        }
        return best;
    }

    function setCropDisplay(r) {
        d.crop = rectFromDisplay(r);
        d.crop = {
            x: Math.round(d.crop.x), y: Math.round(d.crop.y),
            width: Math.max(1, Math.round(d.crop.width)), height: Math.max(1, Math.round(d.crop.height)),
        };
        const max = LIMITS.maxSide * 2;
        d.crop.width = Math.min(max, d.crop.width);
        d.crop.height = Math.min(max, d.crop.height);
        d.crop.x = Math.max(-LIMITS.maxSide, Math.min(LIMITS.maxSide, d.crop.x));
        d.crop.y = Math.max(-LIMITS.maxSide, Math.min(LIMITS.maxSide, d.crop.y));
        clampScale();
    }

    /** Keep the output within server limits. */
    function clampScale() {
        const w = d.crop.width, hh = d.crop.height;
        // One common factor keeps the x/y ratio while fitting the limits.
        let f = Math.min(1, LIMITS.maxSide / (w * d.kx), LIMITS.maxSide / (hh * d.ky), Math.sqrt(LIMITS.maxPixels / (w * d.kx * hh * d.ky)));
        f = Math.max(f, 1 / (w * d.kx), 1 / (hh * d.ky));
        d.kx *= f;
        d.ky *= f;
    }

    const outW = () => Math.max(1, Math.round(d.crop.width * d.kx));
    const outH = () => Math.max(1, Math.round(d.crop.height * d.ky));

    // ------------------------------------------------------------ pointer
    function hitHandle(sx, sy) {
        const R = rectToDisplay(d.crop);
        const a = displayToScreen(R.x, R.y), b = displayToScreen(R.x + R.width, R.y + R.height);
        const L = a[0], T = a[1], Rr = b[0], B = b[1];
        const nearL = Math.abs(sx - L) <= HANDLE_PX, nearR = Math.abs(sx - Rr) <= HANDLE_PX;
        const nearT = Math.abs(sy - T) <= HANDLE_PX, nearB = Math.abs(sy - B) <= HANDLE_PX;
        const inX = sx > L - HANDLE_PX && sx < Rr + HANDLE_PX, inY = sy > T - HANDLE_PX && sy < B + HANDLE_PX;
        let hd = "";
        if (inY && nearT) hd += "n";
        else if (inY && nearB) hd += "s";
        if (inX && nearL) hd += "w";
        else if (inX && nearR) hd += "e";
        if (hd && inX && inY) return hd;
        if (sx > L && sx < Rr && sy > T && sy < B) return "move";
        return null;
    }

    function pointerDown(e, sx, sy) {
        frozen = null;
        frozen = clone(displayDoc());
        const hd = hitHandle(sx, sy);
        const [px, py] = screenToDisplay(sx, sy);
        drag = { handle: hd || "new", start: rectToDisplay(d.crop), px, py, moved: false };
        ctx.state.drag = { kind: "crop" };
    }

    function pointerMove(e, sx, sy) {
        if (!drag) return;
        const [px, py] = screenToDisplay(sx, sy);
        const dx = px - drag.px, dy = py - drag.py;
        if (!drag.moved && Math.hypot(dx, dy) * ctx.view.zoom < 2) return;
        drag.moved = true;
        const s = drag.start;
        const snapOn = snapEdges !== (isMac ? e.metaKey : e.ctrlKey);
        const img = imageRectDisplay();
        const tol = SNAP_PX / ctx.view.zoom;
        const xs = [img.x, img.x + img.width], ys = [img.y, img.y + img.height];
        const ratio = aspectRatio();
        const min = MIN_PX / ctx.view.zoom;
        let r;
        if (drag.handle === "move") {
            let nx = s.x + dx, ny = s.y + dy;
            if (snapOn) {
                const sxL = snapValue(nx, xs, tol), sxR = snapValue(nx + s.width, xs, tol);
                nx = sxL !== nx ? sxL : sxR !== nx + s.width ? sxR - s.width : nx;
                const syT = snapValue(ny, ys, tol), syB = snapValue(ny + s.height, ys, tol);
                ny = syT !== ny ? syT : syB !== ny + s.height ? syB - s.height : ny;
            }
            r = { x: nx, y: ny, width: s.width, height: s.height };
        } else if (drag.handle === "new") {
            let x1 = px, y1 = py;
            if (snapOn) {
                x1 = snapValue(x1, xs, tol);
                y1 = snapValue(y1, ys, tol);
            }
            let w = x1 - drag.px, hh = y1 - drag.py;
            const rr = ratio ?? (e.shiftKey ? 1 : null);
            if (rr) {
                const aw = Math.abs(w), ah = Math.abs(hh);
                if (aw / Math.max(ah, 1e-9) > rr) hh = Math.sign(hh || 1) * aw / rr;
                else w = Math.sign(w || 1) * ah * rr;
            }
            r = normRect({ x: drag.px, y: drag.py, width: w, height: hh });
        } else {
            let L = s.x, T = s.y, Rr = s.x + s.width, B = s.y + s.height;
            const hd = drag.handle;
            if (hd.includes("w")) L = Math.min(Rr - min, snapOn ? snapValue(s.x + dx, xs, tol) : s.x + dx);
            if (hd.includes("e")) Rr = Math.max(L + min, snapOn ? snapValue(Rr + dx, xs, tol) : Rr + dx);
            if (hd.includes("n")) T = Math.min(B - min, snapOn ? snapValue(s.y + dy, ys, tol) : s.y + dy);
            if (hd.includes("s")) B = Math.max(T + min, snapOn ? snapValue(B + dy, ys, tol) : B + dy);
            const rr = ratio ?? (e.shiftKey && hd.length === 2 ? s.width / s.height : null);
            if (rr) {
                let w = Rr - L, hh = B - T;
                if (hd === "n" || hd === "s") w = hh * rr;
                else if (hd === "e" || hd === "w") hh = w / rr;
                else if (w / hh > rr) hh = w / rr;
                else w = hh * rr;
                if (hd.includes("w")) L = Rr - w;
                else if (hd.includes("e")) Rr = L + w;
                else {
                    const cx = (s.x + s.x + s.width) / 2;
                    L = cx - w / 2;
                    Rr = cx + w / 2;
                }
                if (hd.includes("n")) T = B - hh;
                else if (hd.includes("s")) B = T + hh;
                else {
                    const cy = (s.y + s.y + s.height) / 2;
                    T = cy - hh / 2;
                    B = cy + hh / 2;
                }
            }
            r = { x: L, y: T, width: Rr - L, height: B - T };
        }
        if (r.width >= 1 && r.height >= 1) setCropDisplay(r);
        syncBar();
        ctx.invalidate();
    }

    function pointerUp() {
        const was = drag;
        drag = null;
        frozen = null;
        if (was && was.handle === "new" && !was.moved) {
            /* a click outside the rectangle keeps the current crop */
        }
        ctx.fitView();
        ctx.refreshZoomLabel();
        syncBar();
        ctx.invalidate();
    }

    function hover(sx, sy) {
        const hd = hitHandle(sx, sy);
        const cursors = { n: "ns-resize", s: "ns-resize", e: "ew-resize", w: "ew-resize", nw: "nwse-resize", se: "nwse-resize", ne: "nesw-resize", sw: "nesw-resize", move: "move" };
        ctx.stage.style.cursor = hd ? cursors[hd] : "crosshair";
    }

    // ------------------------------------------------------------ painting
    function paintOverlay(c) {
        const { w, h: hh } = ctx.stageSize();
        const R = rectToDisplay(d.crop);
        const a = displayToScreen(R.x, R.y), b = displayToScreen(R.x + R.width, R.y + R.height);
        const x = a[0], y = a[1], cw = b[0] - a[0], ch = b[1] - a[1];
        c.save();
        c.fillStyle = "rgba(0,0,0,0.55)";
        c.beginPath();
        c.rect(0, 0, w, hh);
        c.rect(x, y, cw, ch);
        c.fill("evenodd");
        // Rule of thirds while dragging
        if (drag?.moved) {
            c.strokeStyle = "rgba(255,255,255,0.45)";
            c.lineWidth = 1;
            c.beginPath();
            for (let i = 1; i < 3; i++) {
                c.moveTo(x + (cw * i) / 3, y);
                c.lineTo(x + (cw * i) / 3, y + ch);
                c.moveTo(x, y + (ch * i) / 3);
                c.lineTo(x + cw, y + (ch * i) / 3);
            }
            c.stroke();
        }
        c.strokeStyle = "#ffffff";
        c.lineWidth = 1;
        c.strokeRect(Math.round(x) + 0.5, Math.round(y) + 0.5, Math.round(cw) - 1, Math.round(ch) - 1);
        // Corner brackets + edge bars
        c.lineWidth = 4;
        c.lineCap = "round";
        c.shadowColor = "rgba(0,0,0,.35)";
        c.shadowBlur = 3;
        const L = Math.min(18, cw / 3, ch / 3);
        c.beginPath();
        for (const [px, py, dx, dy] of [[x, y, 1, 1], [x + cw, y, -1, 1], [x + cw, y + ch, -1, -1], [x, y + ch, 1, -1]]) {
            c.moveTo(px, py + dy * L);
            c.lineTo(px, py);
            c.lineTo(px + dx * L, py);
        }
        const mx = x + cw / 2, my = y + ch / 2, E = Math.min(12, cw / 6, ch / 6);
        if (cw > 60) {
            c.moveTo(mx - E, y);
            c.lineTo(mx + E, y);
            c.moveTo(mx - E, y + ch);
            c.lineTo(mx + E, y + ch);
        }
        if (ch > 60) {
            c.moveTo(x, my - E);
            c.lineTo(x, my + E);
            c.moveTo(x + cw, my - E);
            c.lineTo(x + cw, my + E);
        }
        c.stroke();
        c.shadowColor = "transparent";
        // Size badge
        const label = `${outW()} × ${outH()}`;
        c.font = "600 11px -apple-system,BlinkMacSystemFont,sans-serif";
        const tw = c.measureText(label).width + 14;
        const bx = Math.max(4, Math.min(w - tw - 4, mx - tw / 2));
        const by = y + ch + 8 + 20 < hh ? y + ch + 8 : Math.max(4, y - 28);
        c.fillStyle = "rgba(20,21,24,.85)";
        c.beginPath();
        c.roundRect?.(bx, by, tw, 20, 6);
        c.fill();
        c.fillStyle = "#fff";
        c.textBaseline = "middle";
        c.fillText(label, bx + 7, by + 10);
        c.restore();
    }

    // ------------------------------------------------------------ bar
    function syncBar() {
        const R = rectToDisplay(d.crop);
        if (inputs.w && document.activeElement !== inputs.w) inputs.w.value = String(Math.round(R.width));
        if (inputs.h && document.activeElement !== inputs.h) inputs.h.value = String(Math.round(R.height));
    }

    function setSize(w, hh) {
        const R = rectToDisplay(d.crop);
        const nw = Math.max(1, Math.min(LIMITS.maxSide * 2, Math.round(w)));
        const nh = Math.max(1, Math.min(LIMITS.maxSide * 2, Math.round(hh)));
        const cx = R.x + R.width / 2, cy = R.y + R.height / 2;
        setCropDisplay({ x: cx - nw / 2, y: cy - nh / 2, width: nw, height: nh });
        ctx.fitView();
        ctx.refreshChrome();
        ctx.invalidate();
    }

    let aspectAnchor = /** @type {any} */ (null);

    /** Ask for a custom W : H ratio, then apply it. */
    function openCustomRatio() {
        const cur = d.custom || [16, 9];
        const wIn = h("input", { class: "pe-input", type: "number", min: 1, max: 10000, value: cur[0], "aria-label": tr("Ratio width") });
        const hIn = h("input", { class: "pe-input", type: "number", min: 1, max: 10000, value: cur[1], "aria-label": tr("Ratio height") });
        const ok = h("button", { type: "button", class: "pe-btn pe-text-btn pe-primary" }, tr("Apply"));
        const go = () => {
            const w = Number(wIn.value), hh = Number(hIn.value);
            if (!(w > 0 && hh > 0)) return;
            d.custom = [w, hh];
            d.portrait = false;
            closePopover();
            applyAspect("custom");
        };
        ok.addEventListener("click", go);
        for (const i of [wIn, hIn]) i.addEventListener("keydown", (e) => { if (e.key === "Enter") { e.preventDefault(); e.stopPropagation(); go(); } });
        popover(ctx.root, aspectAnchor, h("div", { style: { display: "flex", flexDirection: "column", gap: "8px", padding: "4px" } }, [
            h("div", { class: "pe-field-label" }, tr("Custom Ratio")),
            h("div", { class: "pe-palette-row" }, [wIn, h("span", {}, ":"), hIn]),
            ok,
        ]));
    }

    function applyAspect(id) {
        if (id === "custom" && !d.custom) return openCustomRatio();
        if (id !== d.aspect) d.portrait = false;
        d.aspect = id;
        const r = aspectRatio();
        if (r) {
            // Largest rectangle of ratio r inside the current crop, centred.
            const R = rectToDisplay(d.crop);
            let w = R.width, hh = R.height;
            if (w / hh > r) w = hh * r;
            else hh = w / r;
            setCropDisplay({ x: R.x + (R.width - w) / 2, y: R.y + (R.height - hh) / 2, width: w, height: hh });
        }
        ctx.fitView();
        ctx.refreshChrome();
        ctx.invalidate();
    }

    function rotateBy(delta) {
        d.rotation = (d.rotation + delta + 360) % 360;
        // The ratio is defined in display orientation, so a quarter turn flips it to portrait/landscape.
        if (aspectRatio()) d.portrait = !d.portrait;
        ctx.fitView();
        ctx.refreshChrome();
        ctx.invalidate();
    }

    function flip(axis) {
        // Flip in display orientation: when turned 90°, a horizontal flip is a source-vertical flip.
        const turned = d.rotation % 180 !== 0;
        if ((axis === "h") !== turned) d.flipX = !d.flipX;
        else d.flipY = !d.flipY;
        ctx.refreshChrome();
        ctx.invalidate();
    }

    function revert() {
        const doc = ctx.doc;
        d = { crop: { x: 0, y: 0, width: doc.sourceWidth, height: doc.sourceHeight }, rotation: 0, flipX: false, flipY: false, kx: 1, ky: 1, aspect: "free", portrait: false, custom: null };
        clampScale();
        ctx.fitView();
        ctx.refreshChrome();
        ctx.invalidate();
    }

    function openImageSize(anchor) {
        const ratio = d.crop.width / d.crop.height;
        const turned = d.rotation % 180 !== 0;
        const dispW = () => (turned ? outH() : outW());
        const dispH = () => (turned ? outW() : outH());
        const wIn = h("input", { class: "pe-input", type: "number", min: 1, max: LIMITS.maxSide, value: dispW(), "aria-label": tr("Width") });
        const hIn = h("input", { class: "pe-input", type: "number", min: 1, max: LIMITS.maxSide, value: dispH(), "aria-label": tr("Height") });
        const pct = h("span", { class: "pe-crop-hint" }, `${Math.round(d.kx * 100)}%`);
        const refresh = () => {
            wIn.value = String(dispW());
            hIn.value = String(dispH());
            pct.textContent = `${Math.round(d.kx * 100)}%`;
            ctx.invalidate();
        };
        // The size fields keep proportions locked, so editing either one sets a uniform scale.
        const setK = (k) => {
            d.kx = d.ky = k;
            clampScale();
            refresh();
        };
        wIn.addEventListener("change", () => {
            const v = Number(wIn.value);
            if (v > 0) setK(v / (turned ? d.crop.height : d.crop.width));
            else refresh();
        });
        hIn.addEventListener("change", () => {
            const v = Number(hIn.value);
            if (v > 0) setK(v / (turned ? d.crop.width : d.crop.height));
            else refresh();
        });
        const presets = h("div", { style: { display: "flex", gap: "4px", flexWrap: "wrap" } }, [25, 50, 75, 100, 150, 200].map((p) =>
            h("button", { type: "button", class: "pe-btn pe-text-btn", style: { padding: "0 8px" }, onclick: () => setK(p / 100) }, `${p}%`),
        ));
        void ratio;
        popover(ctx.root, anchor, h("div", { style: { display: "flex", flexDirection: "column", gap: "8px", padding: "4px", width: "230px" } }, [
            h("div", { class: "pe-field-label" }, tr("Image size")),
            h("div", { class: "pe-palette-row" }, [wIn, h("span", { html: ICONS.lock, title: tr("Proportions are locked") }), hIn, h("span", { class: "pe-crop-hint" }, "px")]),
            presets,
            h("div", { class: "pe-palette-row", style: { justifyContent: "space-between" } }, [h("span", { class: "pe-crop-hint" }, tr("Scale")), pct]),
        ]));
    }

    function buildBar(bar) {
        const aspect = ASPECTS.find((a) => a.id === d.aspect) || ASPECTS[0];
        const aspectBtn = h("button", { type: "button", class: "pe-btn pe-chip", "aria-haspopup": "menu", title: tr("Aspect ratio") }, [h("span", {}, tr(aspect.label)), h("span", { html: ICONS.chevron })]);
        aspectAnchor = aspectBtn;
        aspectBtn.addEventListener("click", () => popover(ctx.root, aspectBtn, menuList(ASPECTS.map((a) => (a.id === "-" ? a : { id: a.id, label: tr(a.label), checked: a.id === d.aspect })), (id) => (id === "custom" ? openCustomRatio() : applyAspect(id)))));
        const R = rectToDisplay(d.crop);
        inputs.w = h("input", { class: "pe-input", type: "number", min: 1, value: Math.round(R.width), "aria-label": tr("Crop width") });
        inputs.h = h("input", { class: "pe-input", type: "number", min: 1, value: Math.round(R.height), "aria-label": tr("Crop height") });
        const onSize = (which) => () => {
            const R2 = rectToDisplay(d.crop);
            let w = Number(inputs.w.value) || R2.width, hh = Number(inputs.h.value) || R2.height;
            const r = aspectRatio();
            if (r) {
                if (which === "w") hh = w / r;
                else w = hh * r;
            }
            setSize(w, hh);
        };
        inputs.w.addEventListener("change", onSize("w"));
        inputs.h.addEventListener("change", onSize("h"));
        for (const inp of [inputs.w, inputs.h]) {
            inp.addEventListener("keydown", (e) => {
                if (e.key === "Enter") {
                    e.preventDefault();
                    inp.blur();
                    ctx.root.focus();
                }
            });
        }
        const swap = iconButton({ icon: ICONS.swap, label: tr("Swap width and height"), onClick: () => {
            const R2 = rectToDisplay(d.crop);
            if (aspectRatio()) d.portrait = !d.portrait;
            setSize(R2.height, R2.width);
        } });
        const sizeBtn = h("button", { type: "button", class: "pe-btn pe-chip", "aria-haspopup": "dialog", title: tr("Image size") }, [h("span", {}, `${tr("Image size")} ${d.rotation % 180 ? outH() : outW()}×${d.rotation % 180 ? outW() : outH()}`), h("span", { html: ICONS.chevron })]);
        sizeBtn.addEventListener("click", () => openImageSize(sizeBtn));
        bar.append(
            aspectBtn,
            h("div", { class: "pe-ctx" }, [inputs.w, swap, inputs.h]),
            h("div", { class: "pe-sep" }),
            h("div", { class: "pe-group" }, [
                iconButton({ icon: ICONS.rotateLeft, label: tr("Rotate left"), onClick: () => rotateBy(-90) }),
                iconButton({ icon: ICONS.rotateRight, label: tr("Rotate right"), onClick: () => rotateBy(90) }),
                iconButton({ icon: ICONS.flipH, label: tr("Flip horizontally"), onClick: () => flip("h") }),
                iconButton({ icon: ICONS.flipV, label: tr("Flip vertically"), onClick: () => flip("v") }),
            ]),
            sizeBtn,
            h("div", { class: "pe-spacer" }),
            h("button", { type: "button", class: "pe-btn pe-text-btn", onclick: cancel }, tr("Cancel")),
            h("button", { type: "button", class: "pe-btn pe-text-btn pe-primary", title: `${tr("Crop")} (↩)`, onclick: applyCrop }, tr("Crop")),
        );
    }

    /** Bottom bar while cropping: snapping toggle + hint, and Revert to Original (as in the reference). */
    function buildBottom(el) {
        const snap = h("input", { type: "checkbox", checked: snapEdges, "aria-label": tr("Snap to edges") });
        snap.addEventListener("change", () => {
            snapEdges = snap.checked;
            try {
                localStorage.setItem("photoedit.crop.snap", snapEdges ? "1" : "0");
            } catch {
                /* preference is best-effort */
            }
        });
        el.replaceChildren(
            h("label", { class: "pe-check-row" }, [snap, h("span", {}, tr("Snap to edges"))]),
            h("span", { class: "pe-crop-hint" }, `${tr("Hold")} ${isMac ? "Command ⌘" : "Ctrl"} ${tr("to disable snapping")}`),
            h("div", { class: "pe-spacer" }),
            h("button", { type: "button", class: "pe-btn pe-text-btn", onclick: revert }, tr("Revert to Original")),
        );
    }

    // ------------------------------------------------------------ keyboard
    function keyDown(e) {
        if (e.key === "Enter") {
            e.preventDefault();
            applyCrop();
        } else if (e.key === "Escape") {
            e.preventDefault();
            cancel();
        } else if (e.key.startsWith("Arrow")) {
            e.preventDefault();
            const step = e.shiftKey ? 10 : 1;
            const R = rectToDisplay(d.crop);
            const dx = e.key === "ArrowLeft" ? -step : e.key === "ArrowRight" ? step : 0;
            const dy = e.key === "ArrowUp" ? -step : e.key === "ArrowDown" ? step : 0;
            setCropDisplay({ ...R, x: R.x + dx, y: R.y + dy });
            syncBar();
            ctx.invalidate();
        }
    }

    function applyCrop() {
        closePopover();
        const doc = ctx.doc;
        clampScale();
        const next = clone(doc);
        next.crop = { ...d.crop };
        next.resize = { width: Math.min(LIMITS.maxSide, outW()), height: Math.min(LIMITS.maxSide, outH()) };
        next.rotation = d.rotation;
        next.flipX = d.flipX;
        next.flipY = d.flipY;
        const same = JSON.stringify([next.crop, next.resize, next.rotation, next.flipX, next.flipY]) ===
            JSON.stringify([doc.crop, doc.resize, doc.rotation, doc.flipX, doc.flipY]);
        d = null;
        if (same) ctx.exit();
        else ctx.apply(next);
    }

    function cancel() {
        closePopover();
        d = null;
        ctx.exit();
    }

    void multiply;
    void modKey;
    return { enter, buildBottom, displayDoc, paintOverlay, buildBar, pointerDown, pointerMove, pointerUp, hover, keyDown, apply: applyCrop, cancel };
}
