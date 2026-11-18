// @ts-check
import {
    TOOLS, TOOL_DEFAULTS, PALETTE, ARROW_STYLES, TEXT_STYLES, PIXELATE_STYLES, COUNTER_STYLES,
    createDocument, validateDocument, newId, clone, referencedResources,
} from "../core/model.js";
import {
    textOrientation, apply, invert, multiply, sourceToOutput, outputSize, objectBounds, objectHandles, hitObject, moveObject,
    reshapeObject, dragRect, snapAngle, smoothStroke, limitStrokePoints, appendStrokePoint, snapHighlighter, normRect, arrowControl, quadPoint, curvePoints,
} from "../core/geometry.js";
import { renderDocument, exportCanvas, canvasToBlob, loadImage, sharedMeasure, paintOrder } from "../core/render.js";
import { fontString, textPadding, LINE_HEIGHT, textColors } from "../core/text.js";
import { History } from "../core/history.js";
import { toV2 } from "../core/convert.js";
import { h, iconButton, popover, closePopover, menuList, slider, downloadBlob, modKey, isMac } from "./dom.js";
import { ICONS, PREVIEWS, thicknessPreview } from "./icons.js";
import { injectStyles } from "./styles.js";
import { createCropMode } from "./crop.js";
import { createBackgroundPanel } from "./background-panel.js";

const THICKNESS = [2, 4, 6, 9, 13, 18, 26];
const TEXT_SIZES = [10, 13, 16, 20, 24, 30, 36, 48, 72, 96, 144, 216, 288];
const COUNTER_SIZES = [{ id: "24", label: "Small" }, { id: "32", label: "Medium" }, { id: "44", label: "Large" }, { id: "60", label: "Extra large" }];
const ZOOMS = [0.25, 0.5, 0.75, 1, 1.5, 2, 3, 4];
const STYLE_LABELS = {
    arrow: { standard: "Standard", fancy: "Tapered", curved: "Curved", double: "Double" },
    text: { standard: "Standard", outline: "Outline", box: "Box", rounded: "Rounded", "rounded-box": "Rounded box", mono: "Monospaced", "mono-box": "Monospaced box" },
    pixelate: { pixelate: "Pixelate", blur: "Blur", "secure-blur": "Secure blur", blackout: "Black out" },
    counter: { numbers: "1, 2, 3", letters: "A, B, C", roman: "I, II, III" },
    shape: { rect: "Rectangle", ellipse: "Ellipse" },
};

function safeStorage(storage) {
    return {
        get(key, fallback) {
            try {
                const v = storage?.getItem(key);
                return v ? JSON.parse(v) : fallback;
            } catch {
                return fallback;
            }
        },
        set(key, value) {
            try {
                storage?.setItem(key, JSON.stringify(value));
            } catch {
                /* storage full or unavailable: preferences are best-effort */
            }
        },
    };
}

/**
 * @typedef {object} EditorAction
 * @property {string} id
 * @property {string} label
 * @property {boolean} [primary]
 * @property {(api: any) => (void|Promise<void>)} run
 */
/**
 * @typedef {object} EditorOptions
 * @property {any} [document]            v1 or v2 document; created from `base` when absent
 * @property {{id:string,url:string,width?:number,height?:number}} [base]
 * @property {Record<string,string>} [resources]  resource id → URL (must include document.base)
 * @property {string} [fileName]
 * @property {Record<string,string>} [labels]     English text → translation
 * @property {EditorAction[]} [actions]           top-right buttons
 * @property {(file: File) => Promise<{id:string,url:string}>} [uploadImage]
 * @property {() => void} [onClose]               shows a close button when provided
 * @property {(dirty:boolean) => void} [onDirtyChange]
 * @property {"auto"|"light"|"dark"} [theme]
 * @property {string} [accent]
 * @property {Storage|null} [storage]
 * @property {"png"|"jpeg"} [exportFormat]
 */

/**
 * Mount the editor into `host`.
 * @param {HTMLElement} host
 * @param {EditorOptions} options
 */
export async function createEditor(host, options) {
    injectStyles();
    const tr = (t) => (options.labels && options.labels[t]) || t;
    const store = safeStorage(options.storage === undefined ? globalThis.localStorage : options.storage);
    const toolPrefs = { ...clone(TOOL_DEFAULTS), ...store.get("photoedit.tools.v1", {}) };
    for (const k of Object.keys(TOOL_DEFAULTS)) toolPrefs[k] = { ...TOOL_DEFAULTS[k], ...(toolPrefs[k] || {}) };

    // ------------------------------------------------------------------ state
    const resources = { ...(options.resources || {}) };
    /** Object URLs this editor created (revoked on destroy). */
    const ownedUrls = new Set();
    const localResource = (file) => {
        const url = URL.createObjectURL(file);
        ownedUrls.add(url);
        return { id: newId("r"), url };
    };
    let doc;
    if (options.document) doc = toV2(clone(options.document));
    else {
        if (!options.base) throw new Error("createEditor needs `document` or `base`");
        resources[options.base.id] = options.base.url;
        const probe = await loadImage(options.base.url);
        doc = createDocument({ base: options.base.id, width: probe.naturalWidth, height: probe.naturalHeight });
    }
    validateDocument(doc);
    /** @type {Map<string, any>} */
    const images = new Map();
    await Promise.all(Object.entries(resources).map(async ([id, url]) => {
        try {
            images.set(id, await loadImage(url));
        } catch (e) {
            if (id === doc.base) throw e;
        }
    }));
    if (!images.get(doc.base)) throw new Error("The base image could not be loaded");
    // Every image the document references must be present, or export/save would silently drop content.
    const missing = [...referencedResources(doc)].filter((id) => !images.has(id));
    if (missing.length) throw new Error(`${missing.length} image(s) in this document could not be loaded. Reopen the editor to try again.`);

    const history = new History(doc);
    let savedJson = JSON.stringify(doc);
    const state = {
        tool: "rect", // the reference opens with Rectangle active
        selection: /** @type {Set<string>} */ (new Set()),
        mode: /** @type {"edit"|"crop"} */ ("edit"),
        panelOpen: false,
        drag: /** @type {any} */ (null),
        hover: /** @type {any} */ (null),
        space: false,
        editingText: /** @type {string|null} */ (null),
        clipboard: /** @type {any[]|null} */ (null),
        busy: false,
    };
    const view = { zoom: 1, panX: 0, panY: 0, fit: true, dpr: 1 };
    let renderCache = { key: "", canvas: /** @type {any} */ (null), balance: { dx: 0, dy: 0 } };
    let balance = { dx: 0, dy: 0 };
    let zoomReady = false;

    // ------------------------------------------------------------------ DOM
    const root = h("div", { class: "pe-root", tabindex: "0", role: "application", "aria-label": tr("Image editor") });
    if (options.accent) root.style.setProperty("--pe-accent", options.accent);
    const topbar = h("div", { class: "pe-bar pe-top", role: "toolbar", "aria-label": tr("Tools") });
    const body = h("div", { class: "pe-body" });
    const stage = h("div", { class: "pe-stage", tabindex: "-1" });
    const canvas = h("canvas", { "aria-hidden": "true" });
    stage.append(canvas);
    const bottombar = h("div", { class: "pe-bar pe-bottom" });
    body.append(stage);
    root.append(topbar, body, bottombar);
    host.append(root);

    const media = globalThis.matchMedia ? matchMedia("(prefers-color-scheme: dark)") : null;
    function applyTheme() {
        const t = options.theme || "auto";
        const dark = t === "dark" || (t === "auto" && (document.documentElement.classList.contains("dark") || !!media?.matches));
        root.classList.toggle("pe-dark", dark);
    }
    applyTheme();
    media?.addEventListener?.("change", applyTheme);
    const themeObserver = new MutationObserver(applyTheme);
    themeObserver.observe(document.documentElement, { attributes: true, attributeFilter: ["class"] });

    // ------------------------------------------------------------------ helpers
    const baseMeasure = sharedMeasure();
    /** Text metrics plus the current text orientation (geometry reads `measure.orient`). */
    const measure = /** @type {any} */ ((o) => baseMeasure(o));
    Object.defineProperty(measure, "orient", { get: () => textOrientation(doc) });
    const byId = (id) => doc.objects.find((o) => o.id === id);
    const selected = () => doc.objects.filter((o) => state.selection.has(o.id));
    // Compare the live document (including text being typed) with the last saved snapshot.
    const isDirty = () => JSON.stringify(doc) !== savedJson;
    let lastDirty = false;

    function toast(msg) {
        const t = h("div", { class: "pe-toast", role: "status" }, msg);
        root.append(t);
        setTimeout(() => (t.style.opacity = "0"), 1400);
        setTimeout(() => t.remove(), 1700);
    }

    /** Record the current document as an undo step. */
    function commit(opts = {}, chrome = {}) {
        validateDocument(doc);
        history.commit(doc, opts);
        afterChange(chrome);
    }

    function afterChange(chrome = {}) {
        const dirty = isDirty();
        if (dirty !== lastDirty) {
            lastDirty = dirty;
            options.onDirtyChange?.(dirty);
        }
        if (view.fit) fitView();
        refreshChrome(chrome);
        scheduleExportUrl();
        invalidate();
    }

    function setDocument(next, { record = true } = {}) {
        doc = next;
        for (const id of [...state.selection]) if (!byId(id)) state.selection.delete(id);
        if (record) commit();
        else afterChange();
    }

    function saveToolPrefs() {
        store.set("photoedit.tools.v1", toolPrefs);
    }

    // ------------------------------------------------------------------ view
    const displayDoc = () => (state.mode === "crop" ? crop.displayDoc() : doc);
    const docMatrix = () => sourceToOutput(displayDoc(), state.mode === "crop" ? { dx: 0, dy: 0 } : balance);
    const viewMatrix = () => /** @type {any} */ ([view.zoom, 0, 0, view.zoom, view.panX, view.panY]);
    const screenMatrix = () => multiply(viewMatrix(), docMatrix());
    const toSource = (sx, sy) => apply(invert(screenMatrix()), sx, sy);
    const toScreen = (x, y) => apply(screenMatrix(), x, y);
    const srcPerScreen = () => 1 / Math.sqrt(Math.abs(screenMatrix()[0] * screenMatrix()[3] - screenMatrix()[1] * screenMatrix()[2]));

    function stageSize() {
        const r = stage.getBoundingClientRect();
        return { w: Math.max(1, r.width), h: Math.max(1, r.height) };
    }

    function fitView() {
        const { w, h: hh } = stageSize();
        const out = outputSize(displayDoc());
        const pad = state.mode === "crop" ? 56 : 28;
        view.zoom = Math.max(0.02, Math.min(1, (w - pad * 2) / out.width, (hh - pad * 2) / out.height));
        view.fit = true;
        centerView();
        if (zoomReady) refreshZoomLabel();
    }

    function centerView() {
        const { w, h: hh } = stageSize();
        const out = outputSize(displayDoc());
        view.panX = Math.round((w - out.width * view.zoom) / 2);
        view.panY = Math.round((hh - out.height * view.zoom) / 2);
    }

    function clampPan() {
        const { w, h: hh } = stageSize();
        const out = outputSize(displayDoc());
        const cw = out.width * view.zoom, ch = out.height * view.zoom;
        const m = 40;
        view.panX = cw <= w ? Math.round((w - cw) / 2) : Math.min(m, Math.max(w - cw - m, view.panX));
        view.panY = ch <= hh ? Math.round((hh - ch) / 2) : Math.min(m, Math.max(hh - ch - m, view.panY));
    }

    function setZoom(z, cx, cy) {
        const { w, h: hh } = stageSize();
        cx ??= w / 2;
        cy ??= hh / 2;
        const nz = Math.max(0.05, Math.min(8, z));
        view.panX = cx - ((cx - view.panX) * nz) / view.zoom;
        view.panY = cy - ((cy - view.panY) * nz) / view.zoom;
        view.zoom = nz;
        view.fit = false;
        clampPan();
        refreshZoomLabel();
        invalidate();
    }

    // ------------------------------------------------------------------ rendering
    let raf = 0;
    function invalidate() {
        if (!raf) raf = requestAnimationFrame(paint);
    }

    function paint() {
        raf = 0;
        const { w, h: hh } = stageSize();
        const dpr = globalThis.devicePixelRatio || 1;
        view.dpr = dpr;
        if (canvas.width !== Math.round(w * dpr) || canvas.height !== Math.round(hh * dpr)) {
            canvas.width = Math.round(w * dpr);
            canvas.height = Math.round(hh * dpr);
            canvas.style.width = `${w}px`;
            canvas.style.height = `${hh}px`;
        }
        const d = displayDoc();
        const out = outputSize(d);
        const pxScale = Math.min(1, Math.max(0.05, view.zoom * dpr));
        const skip = null;
        const key = `${JSON.stringify(d)}|${pxScale.toFixed(4)}|${images.size}`;
        if (renderCache.key !== key) {
            const r = renderDocument(d, images, { pxScale, skip });
            renderCache = { key, canvas: r.canvas, balance: r.balance };
            if (state.mode === "edit") balance = r.balance;
        }
        const ctx = /** @type {CanvasRenderingContext2D} */ (canvas.getContext("2d"));
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        ctx.clearRect(0, 0, w, hh);
        ctx.imageSmoothingEnabled = true;
        ctx.imageSmoothingQuality = view.zoom * dpr < 1 ? "high" : "low";
        if (state.mode === "edit") {
            ctx.save();
            ctx.shadowColor = "rgba(0,0,0,0.18)";
            ctx.shadowBlur = 12;
            ctx.shadowOffsetY = 2;
            ctx.fillStyle = "rgba(0,0,0,0.001)";
            ctx.fillRect(view.panX, view.panY, out.width * view.zoom, out.height * view.zoom);
            ctx.restore();
        }
        ctx.drawImage(renderCache.canvas, view.panX, view.panY, out.width * view.zoom, out.height * view.zoom);
        if (state.mode === "crop") crop.paintOverlay(ctx);
        else paintOverlay(ctx);
    }

    function objectScreenPath(ctx, o) {
        const M = screenMatrix();
        const P = (x, y) => apply(M, x, y);
        ctx.beginPath();
        if (o.type === "line" || o.type === "arrow" || o.type === "pencil" || o.type === "highlighter") {
            const pts = o.type === "arrow" && o.style === "curved" ? curvePoints(o) : o.points;
            pts.forEach(([x, y], i) => {
                const [sx, sy] = P(x, y);
                if (i) ctx.lineTo(sx, sy);
                else ctx.moveTo(sx, sy);
            });
            if (pts.length === 1) {
                const [sx, sy] = P(pts[0][0], pts[0][1]);
                ctx.lineTo(sx + 0.1, sy);
            }
            return (o.thickness || 2) / srcPerScreen();
        }
        const r = o.type === "ellipse" ? normRect(o) : objectBounds(o, measure);
        const a = P(r.x, r.y), b = P(r.x + r.width, r.y + r.height);
        const x = Math.min(a[0], b[0]), y = Math.min(a[1], b[1]), w = Math.abs(b[0] - a[0]), hh = Math.abs(b[1] - a[1]);
        if (o.type === "ellipse") ctx.ellipse(x + w / 2, y + hh / 2, w / 2, hh / 2, 0, 0, Math.PI * 2);
        else if (o.type === "counter") ctx.arc(x + w / 2, y + hh / 2, w / 2, 0, Math.PI * 2);
        else ctx.rect(x, y, w, hh);
        return o.type === "rect" || o.type === "ellipse" ? o.thickness / srcPerScreen() : 0;
    }

    function paintOverlay(ctx) {
        const sel = selected();
        const accent = getComputedStyle(root).getPropertyValue("--pe-select").trim() || "#2f7bf6";
        // Hover hint
        if (state.hover && !state.drag && !state.selection.has(state.hover)) {
            const o = byId(state.hover);
            if (o) {
                ctx.save();
                const w = objectScreenPath(ctx, o);
                ctx.strokeStyle = accent;
                ctx.globalAlpha = 0.35;
                ctx.lineWidth = Math.max(2, w + 3);
                ctx.lineCap = ctx.lineJoin = "round";
                ctx.stroke();
                ctx.restore();
            }
        }
        for (const o of sel) {
            if (o.id === state.editingText) {
                const r = objectBounds(o, measure);
                const a = toScreen(r.x, r.y), b = toScreen(r.x + r.width, r.y + r.height);
                ctx.save();
                ctx.setLineDash([4, 3]);
                ctx.strokeStyle = accent;
                ctx.lineWidth = 1;
                ctx.strokeRect(Math.min(a[0], b[0]) - 3.5, Math.min(a[1], b[1]) - 3.5, Math.abs(b[0] - a[0]) + 7, Math.abs(b[1] - a[1]) + 7);
                ctx.restore();
                continue;
            }
            ctx.save();
            const w = objectScreenPath(ctx, o);
            ctx.lineCap = ctx.lineJoin = "round";
            ctx.strokeStyle = accent;
            ctx.globalAlpha = 0.45;
            ctx.lineWidth = Math.max(3, w + 5);
            ctx.stroke();
            ctx.globalAlpha = 1;
            ctx.lineWidth = 1;
            if (!w) ctx.stroke();
            ctx.restore();
        }
        if (sel.length === 1 && !state.editingText) {
            for (const hd of objectHandles(sel[0], measure)) {
                const [x, y] = toScreen(hd.x, hd.y);
                ctx.save();
                ctx.beginPath();
                ctx.arc(x, y, hd.id === "ctrl" ? 5 : 5.5, 0, Math.PI * 2);
                ctx.fillStyle = hd.id === "ctrl" ? "#ffffff" : accent;
                ctx.shadowColor = "rgba(0,0,0,.3)";
                ctx.shadowBlur = 3;
                ctx.fill();
                ctx.shadowColor = "transparent";
                ctx.lineWidth = 2;
                ctx.strokeStyle = hd.id === "ctrl" ? accent : "#ffffff";
                ctx.stroke();
                ctx.restore();
            }
        }
        if (state.drag?.kind === "marquee") {
            const m = state.drag;
            ctx.save();
            ctx.fillStyle = "rgba(47,123,246,0.12)";
            ctx.strokeStyle = accent;
            ctx.lineWidth = 1;
            const x = Math.min(m.sx, m.cx), y = Math.min(m.sy, m.cy);
            ctx.fillRect(x, y, Math.abs(m.cx - m.sx), Math.abs(m.cy - m.sy));
            ctx.strokeRect(x + 0.5, y + 0.5, Math.abs(m.cx - m.sx), Math.abs(m.cy - m.sy));
            ctx.restore();
        }
    }

    // ------------------------------------------------------------------ hit testing
    function topHit(px, py) {
        const tol = 6 * srcPerScreen();
        const ordered = paintOrder(doc.objects);
        for (let i = ordered.length - 1; i >= 0; i--) if (hitObject(ordered[i], px, py, tol, measure)) return ordered[i];
        return null;
    }

    function handleAt(sx, sy) {
        const sel = selected();
        if (sel.length !== 1) return null;
        for (const hd of objectHandles(sel[0], measure)) {
            const [x, y] = toScreen(hd.x, hd.y);
            if (Math.hypot(sx - x, sy - y) <= 9) return { object: sel[0], handle: hd.id };
        }
        return null;
    }

    // ------------------------------------------------------------------ pointer
    function localPoint(e) {
        const r = stage.getBoundingClientRect();
        return [e.clientX - r.left, e.clientY - r.top];
    }

    const shapeTools = new Set(["rect", "filled-rect", "ellipse", "pixelate", "spotlight"]);

    function newObject(tool, p) {
        const pref = toolPrefs[tool] || {};
        const id = newId();
        const [x, y] = p;
        switch (tool) {
            case "rect":
            case "ellipse":
                return { id, type: tool, x, y, width: 0, height: 0, color: pref.color, thickness: pref.thickness };
            case "filled-rect":
                return { id, type: tool, x, y, width: 0, height: 0, color: pref.color };
            case "pixelate":
                return { id, type: tool, x, y, width: 0, height: 0, style: pref.style, intensity: pref.intensity, seed: Math.floor(Math.random() * 2 ** 31) };
            case "spotlight":
                return { id, type: tool, x, y, width: 0, height: 0, shape: pref.shape, opacity: pref.opacity };
            case "line":
                return { id, type: "line", points: [[x, y], [x, y]], color: pref.color, thickness: pref.thickness };
            case "arrow":
                return { id, type: "arrow", points: [[x, y], [x, y]], color: pref.color, thickness: pref.thickness, style: pref.style };
            case "pencil":
            case "highlighter":
                return { id, type: tool, points: [[x, y]], color: pref.color, thickness: pref.thickness };
        }
        return null;
    }

    function onPointerDown(e) {
        if (state.busy) return;
        root.focus({ preventScroll: true });
        closePopover();
        const [sx, sy] = localPoint(e);
        if (e.button === 1 || (e.button === 0 && state.space)) {
            state.drag = { kind: "pan", sx, sy, px: view.panX, py: view.panY };
            stage.setPointerCapture(e.pointerId);
            stage.style.cursor = "grabbing";
            return;
        }
        if (e.button !== 0) return;
        if (state.mode === "crop") {
            crop.pointerDown(e, sx, sy);
            stage.setPointerCapture(e.pointerId);
            return;
        }
        if (state.editingText) finishTextEdit();
        const [px, py] = toSource(sx, sy);
        const hd = handleAt(sx, sy);
        stage.setPointerCapture(e.pointerId);
        if (hd) {
            const o = hd.object;
            state.drag = { kind: "handle", id: o.id, handle: hd.handle, start: clone(o), bounds: objectBounds(o, measure), px, py };
            return;
        }
        const tool = state.tool;
        const hit = tool === "pencil" || tool === "highlighter" ? null : topHit(px, py);
        if (hit && (tool === "select" || !shapeTools.has(tool) || state.selection.has(hit.id) || hit.type === tool || true)) {
            if (e.shiftKey && tool === "select") {
                if (state.selection.has(hit.id)) state.selection.delete(hit.id);
                else state.selection.add(hit.id);
            } else if (!state.selection.has(hit.id)) {
                state.selection = new Set([hit.id]);
            }
            state.drag = { kind: "move", px, py, lx: px, ly: py, moved: false, axis: null, origin: new Map(selected().map((o) => [o.id, clone(o)])) };
            if (e.detail === 2 && hit.type === "text") startTextEdit(hit.id);
            refreshChrome();
            invalidate();
            return;
        }
        if (tool === "select") {
            if (!e.shiftKey) state.selection.clear();
            state.drag = { kind: "marquee", sx, sy, cx: sx, cy: sy, base: new Set(state.selection) };
            refreshChrome();
            invalidate();
            return;
        }
        if (tool === "text") {
            const pref = toolPrefs.text;
            const probe = { type: "text", fontSize: pref.fontSize, style: pref.style, text: "" };
            const pad = textPadding(probe);
            // Anchor so the caret's line is centred on the click, in the text's upright frame.
            const T = textOrientation(doc), lx = -pad.x, ly = -(pref.fontSize * LINE_HEIGHT) / 2 - pad.y;
            const o = { id: newId(), type: "text", x: px + T[0] * lx + T[2] * ly, y: py + T[1] * lx + T[3] * ly, text: "", color: pref.color, fontSize: pref.fontSize, style: pref.style };
            doc.objects.push(o);
            state.selection = new Set([o.id]);
            startTextEdit(o.id, true);
            refreshChrome();
            return;
        }
        if (tool === "counter") {
            const pref = toolPrefs.counter;
            const o = { id: newId(), type: "counter", x: px, y: py, color: pref.color, size: pref.size };
            doc.objects.push(o);
            state.selection = new Set([o.id]);
            commit();
            return;
        }
        const o = newObject(tool, [px, py]);
        if (!o) return;
        doc.objects.push(o);
        state.selection = new Set([o.id]);
        state.drag = { kind: "create", id: o.id, px, py, sx, sy, raw: [[px, py]], alt: e.altKey };
        invalidate();
    }

    function onPointerMove(e) {
        const [sx, sy] = localPoint(e);
        const d = state.drag;
        if (!d) {
            if (state.mode === "crop") {
                crop.hover(sx, sy);
                return;
            }
            updateHover(sx, sy);
            return;
        }
        if (d.kind === "pan") {
            view.panX = d.px + (sx - d.sx);
            view.panY = d.py + (sy - d.sy);
            view.fit = false;
            clampPan();
            invalidate();
            return;
        }
        if (state.mode === "crop") {
            crop.pointerMove(e, sx, sy);
            return;
        }
        const [px, py] = toSource(sx, sy);
        if (d.kind === "marquee") {
            d.cx = sx;
            d.cy = sy;
            const a = toSource(Math.min(d.sx, sx), Math.min(d.sy, sy)), b = toSource(Math.max(d.sx, sx), Math.max(d.sy, sy));
            const r = normRect({ x: a[0], y: a[1], width: b[0] - a[0], height: b[1] - a[1] });
            const next = new Set(d.base);
            for (const o of doc.objects) {
                const ob = objectBounds(o, measure);
                if (ob.x < r.x + r.width && ob.x + ob.width > r.x && ob.y < r.y + r.height && ob.y + ob.height > r.y) next.add(o.id);
            }
            state.selection = next;
            invalidate();
            return;
        }
        if (d.kind === "move") {
            let dx = px - d.px, dy = py - d.py;
            if (!d.moved && Math.hypot(dx, dy) / srcPerScreen() < 2) return;
            d.moved = true;
            if (e.shiftKey) {
                d.axis ??= Math.abs(dx) >= Math.abs(dy) ? "x" : "y";
                if (d.axis === "x") dy = 0;
                else dx = 0;
            } else d.axis = null;
            for (const o of selected()) {
                const orig = d.origin.get(o.id);
                if (!orig) continue;
                Object.assign(o, clone(orig));
                moveObject(o, dx, dy);
            }
            invalidate();
            return;
        }
        if (d.kind === "handle") {
            const o = byId(d.id);
            if (!o) return;
            Object.assign(o, clone(d.start));
            if (d.handle === "p0" || d.handle === "p1") {
                const i = d.handle === "p0" ? 0 : 1;
                const other = o.points[1 - i];
                const p = e.shiftKey ? snapAngle(other[0], other[1], px, py) : [px, py];
                o.points[i] = p;
            } else if (d.handle === "ctrl") {
                // Drag the curve's midpoint; solve for the quadratic control point.
                const [p0, p1] = o.points;
                o.control = [2 * px - (p0[0] + p1[0]) / 2, 2 * py - (p0[1] + p1[1]) / 2];
            } else {
                const b = d.bounds;
                const anchor = {
                    nw: [b.x + b.width, b.y + b.height], ne: [b.x, b.y + b.height],
                    se: [b.x, b.y], sw: [b.x + b.width, b.y],
                }[d.handle];
                const keepAspect = e.shiftKey || o.type === "text" || o.type === "image";
                let nx = px, ny = py;
                if (keepAspect && b.width && b.height) {
                    const sxr = Math.abs(px - anchor[0]) / b.width, syr = Math.abs(py - anchor[1]) / b.height;
                    const k = Math.max(sxr, syr);
                    nx = anchor[0] + Math.sign(px - anchor[0] || 1) * b.width * k;
                    ny = anchor[1] + Math.sign(py - anchor[1] || 1) * b.height * k;
                }
                const to = normRect({ x: anchor[0], y: anchor[1], width: nx - anchor[0], height: ny - anchor[1] });
                if (to.width < 1) to.width = 1;
                if (to.height < 1) to.height = 1;
                reshapeObject(o, b, to);
                if (o.type === "pencil" || o.type === "highlighter") {
                    // bounds include half the stroke; keep the stroke inside the new box
                }
            }
            invalidate();
            return;
        }
        if (d.kind === "create") {
            const o = byId(d.id);
            if (!o) return;
            if (o.type === "pencil" || o.type === "highlighter") {
                const last = d.raw[d.raw.length - 1];
                if (Math.hypot(px - last[0], py - last[1]) >= 0.6 * srcPerScreen()) appendStrokePoint(d.raw, [px, py]);
                o.points = d.raw;
            } else if (o.type === "line" || o.type === "arrow") {
                o.points[1] = e.shiftKey ? snapAngle(d.px, d.py, px, py) : [px, py];
                d.alt = e.altKey;
            } else {
                const r = dragRect(d.px, d.py, px, py, { square: e.shiftKey, fromCenter: e.altKey });
                Object.assign(o, r);
            }
            invalidate();
        }
    }

    function onPointerUp(e) {
        const d = state.drag;
        state.drag = null;
        stage.style.cursor = "";
        try {
            stage.releasePointerCapture(e.pointerId);
        } catch {
            /* pointer already released */
        }
        if (!d) return;
        if (d.kind === "pan") return refreshCursor();
        if (state.mode === "crop") return crop.pointerUp();
        if (d.kind === "marquee") {
            invalidate();
            refreshChrome();
            return;
        }
        if (d.kind === "move") {
            if (d.moved) commit();
            return;
        }
        if (d.kind === "handle") {
            commit();
            return;
        }
        if (d.kind === "create") {
            const o = byId(d.id);
            if (!o) return;
            const [sx, sy] = localPoint(e);
            const tiny = Math.hypot(sx - d.sx, sy - d.sy) < 3;
            if (o.type === "pencil" || o.type === "highlighter") {
                o.points = limitStrokePoints(d.raw);
                if (o.type === "pencil" && toolPrefs.pencil.smooth) o.points = limitStrokePoints(smoothStroke(d.raw));
                if (o.type === "highlighter" && toolPrefs.highlighter.snap !== false && !(isMac ? e.metaKey : e.ctrlKey)) o.points = snapHighlighter(limitStrokePoints(d.raw));
            } else if (tiny) {
                doc.objects = doc.objects.filter((x) => x.id !== o.id);
                state.selection.clear();
                refreshChrome();
                invalidate();
                return;
            }
            if (o.type === "arrow" && d.alt) o.points = [o.points[1], o.points[0]];
            commit();
        }
    }

    function updateHover(sx, sy) {
        const hd = handleAt(sx, sy);
        const [px, py] = toSource(sx, sy);
        const hit = state.tool === "pencil" || state.tool === "highlighter" ? null : topHit(px, py);
        const id = hit ? hit.id : null;
        if (id !== state.hover) {
            state.hover = id;
            invalidate();
        }
        if (state.space) stage.style.cursor = "grab";
        else if (hd) stage.style.cursor = hd.handle === "nw" || hd.handle === "se" ? "nwse-resize" : hd.handle === "ne" || hd.handle === "sw" ? "nesw-resize" : "move";
        else if (hit) stage.style.cursor = "move";
        else stage.style.cursor = state.tool === "select" ? "default" : state.tool === "text" ? "text" : "crosshair";
    }

    function refreshCursor() {
        stage.style.cursor = state.space ? "grab" : state.tool === "select" ? "default" : "crosshair";
    }

    function onWheel(e) {
        e.preventDefault();
        const [sx, sy] = localPoint(e);
        if (e.ctrlKey || e.metaKey) {
            setZoom(view.zoom * Math.exp(-e.deltaY * 0.01), sx, sy);
        } else {
            view.panX -= e.deltaX;
            view.panY -= e.deltaY;
            view.fit = false;
            clampPan();
            invalidate();
        }
    }

    // ------------------------------------------------------------------ text editing
    let textArea = /** @type {HTMLTextAreaElement|null} */ (null);
    function startTextEdit(id, isNew = false) {
        const o = byId(id);
        if (!o) return;
        state.editingText = id;
        state.selection = new Set([id]);
        const ta = h("textarea", { class: "pe-textedit", spellcheck: false, "aria-label": tr("Text"), autocapitalize: "off" });
        ta.value = o.text;
        textArea = ta;
        stage.append(ta);
        layoutTextArea();
        ta.addEventListener("input", () => {
            const t = byId(id);
            if (!t) return;
            t.text = ta.value.slice(0, 10000);
            layoutTextArea();
            invalidate();
        });
        ta.addEventListener("keydown", (e) => {
            e.stopPropagation();
            if (e.key === "Escape" || (e.key === "Enter" && (e.metaKey || e.ctrlKey))) {
                e.preventDefault();
                finishTextEdit();
                root.focus();
            }
        });
        ta.addEventListener("pointerdown", (e) => e.stopPropagation());
        ta.dataset.isNew = isNew ? "1" : "";
        // Focus synchronously so keystrokes typed right after the click land in the text, not on tool shortcuts.
        ta.focus({ preventScroll: true });
        if (!isNew) ta.select();
        refreshChrome();
        invalidate();
    }

    function layoutTextArea() {
        if (!textArea || !state.editingText) return;
        const o = byId(state.editingText);
        if (!o) return;
        const m = measure(o.text ? o : { ...o, text: " " });
        const pad = textPadding(o);
        const M = screenMatrix();
        const ta = textArea;
        ta.style.font = fontString(o);
        ta.style.lineHeight = String(LINE_HEIGHT);
        ta.style.padding = `${pad.y}px ${pad.x}px`;
        ta.style.width = `${m.width + o.fontSize}px`;
        ta.style.height = `${m.height + 2}px`;
        ta.style.caretColor = textColors(o).fill;
        const T = textOrientation(doc);
        const F = multiply(M, [T[0], T[1], T[2], T[3], o.x, o.y]);
        ta.style.transform = `matrix(${F.map((v) => v.toFixed(5)).join(",")})`;
    }

    function finishTextEdit() {
        const id = state.editingText;
        if (!id) return;
        state.editingText = null;
        textArea?.remove();
        textArea = null;
        const o = byId(id);
        if (o && !o.text.trim()) {
            doc.objects = doc.objects.filter((x) => x.id !== id);
            state.selection.delete(id);
        }
        commit();
    }

    // ------------------------------------------------------------------ object operations
    function deleteSelection() {
        if (!state.selection.size) return;
        doc.objects = doc.objects.filter((o) => !state.selection.has(o.id));
        state.selection.clear();
        commit();
    }

    function duplicateSelection() {
        const sel = selected();
        if (!sel.length) return;
        const off = 16 * srcPerScreen();
        const copies = sel.map((o) => {
            const c = clone(o);
            c.id = newId();
            moveObject(c, off, off);
            return c;
        });
        doc.objects.push(...copies);
        state.selection = new Set(copies.map((c) => c.id));
        commit();
    }

    function pasteObjects() {
        if (!state.clipboard?.length) return false;
        const off = 16 * srcPerScreen();
        const copies = state.clipboard.map((o) => {
            const c = clone(o);
            c.id = newId();
            moveObject(c, off, off);
            return c;
        });
        state.clipboard = copies.map((c) => clone(c));
        doc.objects.push(...copies);
        state.selection = new Set(copies.map((c) => c.id));
        commit();
        return true;
    }

    function reorder(where) {
        const sel = state.selection;
        if (!sel.size) return;
        const objs = doc.objects;
        if (where === "front") doc.objects = [...objs.filter((o) => !sel.has(o.id)), ...objs.filter((o) => sel.has(o.id))];
        else if (where === "back") doc.objects = [...objs.filter((o) => sel.has(o.id)), ...objs.filter((o) => !sel.has(o.id))];
        else {
            const arr = [...objs];
            const step = where === "forward" ? 1 : -1;
            const idxs = arr.map((o, i) => (sel.has(o.id) ? i : -1)).filter((i) => i >= 0);
            if (step > 0) idxs.reverse();
            for (const i of idxs) {
                const j = i + step;
                if (j < 0 || j >= arr.length || sel.has(arr[j].id)) continue;
                [arr[i], arr[j]] = [arr[j], arr[i]];
            }
            doc.objects = arr;
        }
        commit();
    }

    function nudge(dx, dy) {
        const sel = selected();
        if (!sel.length) return;
        // Screen-direction nudge mapped through rotation/flip/resize into source space.
        const M = invert(screenMatrix());
        const sx = M[0] * dx + M[2] * dy, sy = M[1] * dx + M[3] * dy;
        for (const o of sel) moveObject(o, sx, sy);
        commit({ coalesce: "nudge" });
    }

    async function addImageFile(file, at) {
        if (!file || !/^image\/(png|jpe?g|webp|gif)$/.test(file.type)) {
            toast(tr("Only PNG, JPEG, WebP or GIF images can be added"));
            return;
        }
        try {
            state.busy = true;
            const res = options.uploadImage ? await options.uploadImage(file) : localResource(file);
            const img = await loadImage(res.url);
            resources[res.id] = res.url;
            images.set(res.id, img);
            const c = doc.crop;
            const maxW = c.width * 0.5, maxH = c.height * 0.5;
            const s = Math.min(1, maxW / img.naturalWidth, maxH / img.naturalHeight);
            const w = img.naturalWidth * s, hh = img.naturalHeight * s;
            const [cx, cy] = at || [c.x + c.width / 2, c.y + c.height / 2];
            const o = { id: newId(), type: "image", x: cx - w / 2, y: cy - hh / 2, width: w, height: hh, resource: res.id };
            doc.objects.push(o);
            state.selection = new Set([o.id]);
            setTool("select", false);
            commit();
        } catch (e) {
            toast(tr("The image could not be added"));
            console.error(e);
        } finally {
            state.busy = false;
            // UPSTREAM-DEVIATION (The Mothership): the bars were last drawn while busy (save actions disabled); redraw them.
            refreshChrome();
        }
    }

    /** Add an image resource without creating an object (background wallpapers). */
    async function addResource(file) {
        const res = options.uploadImage ? await options.uploadImage(file) : localResource(file);
        const img = await loadImage(res.url);
        resources[res.id] = res.url;
        images.set(res.id, img);
        return res;
    }

    /** Bottom-bar rotate/flip: whole-image transforms that keep objects attached to the image. */
    function quickTransform(kind) {
        if (state.mode !== "edit") return;
        if (state.editingText) finishTextEdit();
        if (kind === "rotate") doc.rotation = (doc.rotation + 270) % 360;
        else if (doc.rotation % 180) doc.flipY = !doc.flipY;
        else doc.flipX = !doc.flipX;
        commit();
    }

    // ------------------------------------------------------------------ export
    async function exportBlob(type) {
        const fmt = type || (options.exportFormat === "jpeg" ? "image/jpeg" : "image/png");
        validateDocument(doc);
        const c = exportCanvas(doc, images);
        const size = outputSize(doc);
        if (c.width !== size.width || c.height !== size.height) throw new Error("Export size mismatch");
        return canvasToBlob(c, fmt, 0.92);
    }

    let exportUrl = "";
    let exportJson = "";
    let exportTimer = 0;
    let exportIdle = 0;
    let exportRevision = 0;
    let exportPending = false;
    let exportPreparingJson = "";
    const retiredExportUrls = new Map();
    let destroyed = false;
    function scheduleExportUrl() {
        clearTimeout(exportTimer);
        if (exportIdle) globalThis.cancelIdleCallback?.(exportIdle);
        exportIdle = 0;
        const revision = ++exportRevision;
        // UPSTREAM-DEVIATION (The Mothership): never drag an older document while its replacement is preparing.
        if (exportUrl) {
            const retired = exportUrl;
            // A DownloadURL drop may still be fetching after the next edit. Retire publication immediately,
            // but leave the transferred URL fetchable for thirty seconds.
            retiredExportUrls.set(retired, setTimeout(() => {
                URL.revokeObjectURL(retired);
                retiredExportUrls.delete(retired);
            }, 30000));
        }
        exportUrl = "";
        exportJson = "";
        if (destroyed) return;
        exportPending = true;
        exportPreparingJson = JSON.stringify(doc);
        // Pre-render for "Drag me" (dragstart must be synchronous). Large images take a few hundred ms,
        // so wait until edits settle and the main thread is idle.
        const prepare = async () => {
            exportIdle = 0;
            if (destroyed || revision !== exportRevision) return;
            if (state.drag) return scheduleExportUrl();
            try {
                const snapshot = JSON.stringify(doc);
                const blob = await exportBlob("image/png");
                if (destroyed || revision !== exportRevision) return;
                if (snapshot !== JSON.stringify(doc)) return scheduleExportUrl();
                exportUrl = URL.createObjectURL(blob);
                exportJson = snapshot;
            } catch {
                /* the next change retries */
            } finally {
                if (revision === exportRevision) exportPending = false;
            }
        };
        exportTimer = /** @type {any} */ (setTimeout(() => {
            if (globalThis.requestIdleCallback) exportIdle = globalThis.requestIdleCallback(prepare);
            else void prepare();
        }, 1200));
    }

    const baseName = () => (options.fileName || "image").replace(/\.[a-z0-9]+$/i, "");

    async function copyImage() {
        try {
            if (!navigator.clipboard || typeof ClipboardItem === "undefined") throw new Error("Clipboard unavailable");
            await navigator.clipboard.write([new ClipboardItem({ "image/png": exportBlob("image/png") })]);
            toast(tr("Copied to clipboard"));
        } catch {
            toast(tr("Copy is not available in this browser"));
        }
    }

    async function downloadImage(type = "image/png") {
        const blob = await exportBlob(type);
        downloadBlob(blob, `${baseName()}-edited.${type === "image/jpeg" ? "jpg" : type === "image/webp" ? "webp" : "png"}`);
    }

    async function shareImage() {
        const blob = await exportBlob("image/png");
        const file = new File([blob], `${baseName()}-edited.png`, { type: "image/png" });
        try {
            await navigator.share({ files: [file], title: baseName() });
        } catch {
            /* user cancelled */
        }
    }
    const canShare = () => {
        try {
            return !!navigator.canShare?.({ files: [new File([new Blob(["x"])], "x.png", { type: "image/png" })] });
        } catch {
            return false;
        }
    };

    // ------------------------------------------------------------------ chrome
    function setTool(id, focus = true) {
        if (state.editingText) finishTextEdit();
        state.tool = id;
        if (id !== "select" && state.selection.size) {
            // Keep the selection only when it matches the new tool (so its controls edit it).
            const sel = selected();
            if (!sel.every((o) => o.type === id)) state.selection.clear();
        }
        refreshChrome();
        refreshCursor();
        invalidate();
        if (focus) root.focus({ preventScroll: true });
    }

    /** The object type whose properties the contextual controls edit. */
    function contextType() {
        const sel = selected();
        if (sel.length) {
            const t = sel[0].type;
            return sel.every((o) => o.type === t) ? t : null;
        }
        return state.tool === "select" ? null : state.tool;
    }

    /** Apply a property to the selection (if any) and remember it for the tool. */
    function setProp(type, key, value, coalesce = null, { refreshTopbar = true } = {}) {
        if (toolPrefs[type]) {
            toolPrefs[type][key] = value;
            saveToolPrefs();
        }
        const sel = selected().filter((o) => o.type === type);
        if (sel.length) {
            for (const o of sel) o[key] = value;
            commit(coalesce ? { coalesce } : {}, { refreshTopbar });
        } else refreshChrome({ refreshTopbar });
    }

    function finishPropertySlider(input) {
        const focused = document.activeElement === input;
        history.seal();
        refreshChrome();
        // A keyboard step also fires change. Keep subsequent arrows on the
        // replacement range instead of the canvas annotation-nudge handler.
        if (focused) {
            const replacement = [...topbar.querySelectorAll('input[type="range"]')].find((node) => node.title === input.title);
            // buildTopbar queues the usual editor-root focus first.
            queueMicrotask(() => { if (replacement?.isConnected) replacement.focus({ preventScroll: true }); });
        }
    }

    function currentProp(type, key) {
        const sel = selected().filter((o) => o.type === type);
        return sel.length ? sel[0][key] : toolPrefs[type]?.[key];
    }

    const toolButtons = new Map();
    function buildTopbar() {
        // Rebuilding removes the focused button; keep keyboard shortcuts alive by refocusing the editor.
        const hadFocus = topbar.contains(document.activeElement);
        topbar.replaceChildren();
        if (hadFocus) queueMicrotask(() => root.focus({ preventScroll: true }));
        if (state.mode === "crop") {
            crop.buildBar(topbar);
            return;
        }
        const lead = h("div", { class: "pe-group" }, [
            iconButton({ icon: ICONS.crop, label: tr("Crop & resize"), onClick: () => enterCrop() }),
            iconButton({ icon: ICONS.addImage, label: tr("Add image"), onClick: () => fileInput.click() }),
            Object.assign(iconButton({ icon: ICONS.background, label: tr("Background"), shortcut: "B", onClick: () => togglePanel(), pressed: state.panelOpen }), {}),
        ]);
        const tools = h("div", { class: "pe-group", role: "group", "aria-label": tr("Annotation tools") });
        toolButtons.clear();
        for (const t of TOOLS) {
            const b = iconButton({ icon: ICONS[t.id], label: tr(t.label), shortcut: t.key.toUpperCase(), onClick: () => setTool(t.id), pressed: state.tool === t.id });
            b.dataset.tool = t.id;
            toolButtons.set(t.id, b);
            tools.append(b);
        }
        const ctx = h("div", { class: "pe-ctx", "aria-label": tr("Tool options") });
        buildContext(ctx);
        const actions = h("div", { class: "pe-ctx" });
        // As in the reference: the primary action turns blue only when there are unsaved edits,
        // and while the background panel is open "Done" takes the blue slot.
        for (const a of options.actions || []) {
            const blue = a.primary && !state.panelOpen && isDirty();
            actions.append(h("button", { type: "button", class: `pe-btn pe-text-btn${blue ? " pe-primary" : ""}`, "data-action": a.id, disabled: state.busy, onclick: () => runAction(a) }, tr(a.label)));
        }
        if (state.panelOpen) actions.append(h("button", { type: "button", class: "pe-btn pe-text-btn pe-primary", onclick: () => togglePanel(false) }, tr("Done")));
        if (options.onClose) actions.append(iconButton({ icon: ICONS.close, label: tr("Close"), onClick: () => requestClose() }));
        topbar.append(lead, h("div", { class: "pe-sep" }), tools, h("div", { class: "pe-sep" }), ctx, h("div", { class: "pe-spacer" }), actions);
    }

    function colorButton(type) {
        const color = currentProp(type, "color") || "#000000";
        const b = h("button", { type: "button", class: "pe-btn pe-chip", title: tr("Color"), "aria-label": tr("Color"), "aria-haspopup": "dialog" }, [
            h("span", { class: "pe-swatch", style: { background: color } }),
            h("span", { html: ICONS.chevron }),
        ]);
        b.addEventListener("click", () => openPalette(b, color, (c, coalesce) => setProp(type, "color", c, coalesce)));
        return b;
    }

    function openPalette(anchor, current, onPick) {
        const custom = store.get("photoedit.colors.v1", []);
        const dot = (c) => h("button", { type: "button", class: "pe-dot", style: { background: c }, title: c, "aria-label": c, "aria-pressed": String(c.toLowerCase() === current.toLowerCase()), onclick: () => { onPick(c); closePopover(); } });
        const hex = h("input", { class: "pe-input", value: current, "aria-label": tr("Hex color"), maxlength: 9, style: { width: "90px" } });
        const native = h("input", { type: "color", value: current.slice(0, 7), "aria-label": tr("Custom color"), style: { width: "34px", height: "26px", border: "0", padding: "0", background: "none" } });
        native.addEventListener("input", () => { hex.value = native.value; onPick(native.value, "color"); });
        hex.addEventListener("change", () => {
            const v = hex.value.trim().toLowerCase();
            if (/^#[0-9a-f]{6}([0-9a-f]{2})?$/.test(v)) onPick(v);
            else hex.value = current;
        });
        const row = h("div", { class: "pe-palette-row" }, [native, hex]);
        if ("EyeDropper" in globalThis) {
            row.append(iconButton({ icon: ICONS.eyedropper, label: tr("Pick color from screen"), onClick: async () => {
                try {
                    const r = await new /** @type {any} */ (globalThis).EyeDropper().open();
                    onPick(r.sRGBHex.toLowerCase());
                    closePopover();
                } catch { /* cancelled */ }
            } }));
        }
        const add = h("button", { type: "button", class: "pe-btn pe-text-btn", onclick: () => {
            const v = hex.value.trim().toLowerCase();
            if (!/^#[0-9a-f]{6}([0-9a-f]{2})?$/.test(v)) return;
            const next = [v, ...custom.filter((c) => c !== v)].slice(0, 16);
            store.set("photoedit.colors.v1", next);
            closePopover();
            // UPSTREAM-DEVIATION (The Mothership): a hex change redraws the top bar, replacing this palette's Color button;
            // reopen under the button now on screen, not the detached one (which would place the palette top-left).
            const live = anchor.isConnected ? anchor : topbar.querySelector(`[aria-haspopup="dialog"][aria-label="${tr("Color")}"]`);
            openPalette(live || anchor, v, onPick);
        } }, tr("Add to My Colors"));
        const content = h("div", { class: "pe-palette" }, [
            h("div", { class: "pe-dots" }, PALETTE.map(dot)),
            custom.length ? h("div", { class: "pe-field-label" }, tr("My Colors")) : null,
            custom.length ? h("div", { class: "pe-dots" }, custom.map(dot)) : null,
            row,
            add,
        ]);
        popover(root, anchor, content);
    }

    function menuButton(/** @type {any} */ { label, content, items, onSelect, title }) {
        const b = h("button", { type: "button", class: "pe-btn pe-chip", title: title || label, "aria-label": title || label, "aria-haspopup": "menu" }, [
            typeof content === "string" ? h("span", { html: content }) : content,
            h("span", { html: ICONS.chevron }),
        ]);
        b.addEventListener("click", () => popover(root, b, menuList(items(), onSelect)));
        return b;
    }

    function thicknessButton(type, key = "thickness") {
        const cur = currentProp(type, key);
        return menuButton({
            label: tr("Thickness"),
            content: thicknessPreview(cur).replace('width="60"', 'width="30"'),
            items: () => THICKNESS.map((t) => ({ id: String(t), label: `${t} px`, preview: thicknessPreview(t), checked: t === cur })),
            onSelect: (id) => setProp(type, key, Number(id)),
        });
    }

    function buildContext(ctx) {
        const type = contextType();
        if (!type) return;
        switch (type) {
            case "rect":
            case "ellipse":
            case "line":
                ctx.append(colorButton(type), thicknessButton(type));
                break;
            case "filled-rect":
                ctx.append(colorButton(type));
                break;
            case "arrow": {
                const cur = currentProp("arrow", "style");
                ctx.append(colorButton(type), thicknessButton(type), menuButton({
                    label: tr("Arrow style"),
                    content: PREVIEWS.arrow[cur],
                    items: () => ARROW_STYLES.map((s) => ({ id: s, label: tr(STYLE_LABELS.arrow[s]), preview: PREVIEWS.arrow[s], checked: s === cur })),
                    onSelect: (s) => setProp("arrow", "style", s),
                }));
                break;
            }
            case "text": {
                const size = Math.round(currentProp("text", "fontSize"));
                const style = currentProp("text", "style");
                ctx.append(colorButton(type), menuButton({
                    label: tr("Text size"),
                    content: h("span", {}, `${size} pt`),
                    items: () => TEXT_SIZES.map((s) => ({ id: String(s), label: `${s} pt`, checked: s === size })),
                    onSelect: (s) => setProp("text", "fontSize", Number(s)),
                }), menuButton({
                    label: tr("Text style"),
                    content: h("span", { style: { fontWeight: "700", fontStyle: "italic" } }, "A"),
                    items: () => TEXT_STYLES.map((s) => ({ id: s, label: tr(STYLE_LABELS.text[s]), checked: s === style })),
                    onSelect: (s) => setProp("text", "style", s),
                }));
                break;
            }
            case "pixelate": {
                const style = currentProp("pixelate", "style");
                const s = slider({ label: tr("Intensity"), min: 1, max: 100, value: currentProp("pixelate", "intensity"), onInput: (v) => setProp("pixelate", "intensity", v, "pixelate-intensity", { refreshTopbar: false }), onChange: () => finishPropertySlider(s.input) });
                s.el.querySelector(".pe-field-label").style.display = "none";
                s.input.title = tr("Intensity");
                ctx.append(menuButton({
                    label: tr("Redaction style"),
                    content: PREVIEWS.pixelate[style],
                    items: () => PIXELATE_STYLES.map((p) => ({ id: p, label: tr(STYLE_LABELS.pixelate[p]), preview: PREVIEWS.pixelate[p], checked: p === style })),
                    onSelect: (p) => setProp("pixelate", "style", p),
                }), s.el);
                break;
            }
            case "spotlight": {
                const shape = currentProp("spotlight", "shape");
                const s = slider({ label: tr("Dim"), min: 0, max: 100, value: currentProp("spotlight", "opacity"), onInput: (v) => setProp("spotlight", "opacity", v, "spot-opacity", { refreshTopbar: false }), onChange: () => finishPropertySlider(s.input) });
                s.el.querySelector(".pe-field-label").style.display = "none";
                s.input.title = tr("Dim amount");
                ctx.append(menuButton({
                    label: tr("Spotlight shape"),
                    content: PREVIEWS.shape[shape],
                    items: () => ["rect", "ellipse"].map((p) => ({ id: p, label: tr(STYLE_LABELS.shape[p]), preview: PREVIEWS.shape[p], checked: p === shape })),
                    onSelect: (p) => setProp("spotlight", "shape", p),
                }), s.el);
                break;
            }
            case "counter": {
                const size = currentProp("counter", "size");
                ctx.append(colorButton(type), menuButton({
                    label: tr("Counter size"),
                    content: h("span", {}, tr(COUNTER_SIZES.find((c) => Number(c.id) === size)?.label || `${size}`)),
                    items: () => COUNTER_SIZES.map((c) => ({ id: c.id, label: tr(c.label), checked: Number(c.id) === size })),
                    onSelect: (s) => setProp("counter", "size", Number(s)),
                }));
                const nb = h("button", { type: "button", class: "pe-btn pe-chip", title: tr("Counter settings"), "aria-label": tr("Counter settings"), "aria-haspopup": "dialog" }, [h("span", {}, "123"), h("span", { html: ICONS.chevron })]);
                nb.addEventListener("click", () => {
                    const start = h("input", { class: "pe-input", type: "number", min: 0, max: 9999, value: doc.counterStart, "aria-label": tr("Starting number") });
                    start.addEventListener("change", () => {
                        const v = Math.max(0, Math.min(9999, Math.round(Number(start.value) || 0)));
                        doc.counterStart = v;
                        commit();
                    });
                    const content = h("div", { style: { display: "flex", flexDirection: "column", gap: "8px" } }, [
                        menuList(COUNTER_STYLES.map((s) => ({ id: s, label: tr(STYLE_LABELS.counter[s]), checked: doc.counterStyle === s })), (s) => {
                            doc.counterStyle = /** @type {any} */ (s);
                            commit();
                        }),
                        h("label", { class: "pe-check-row", style: { padding: "0 8px 6px" } }, [h("span", {}, tr("Starting number:")), start]),
                    ]);
                    popover(root, nb, content);
                });
                ctx.append(nb);
                break;
            }
            case "pencil":
            case "highlighter": {
                const key = type === "pencil" ? "smooth" : "snap";
                const on = toolPrefs[type][key] !== false;
                const lockLabel = type === "pencil" ? tr("Auto-smoothing") : tr("Straight-line snapping");
                ctx.append(colorButton(type), thicknessButton(type), iconButton({
                    icon: on ? ICONS.lock : ICONS.unlock,
                    label: lockLabel,
                    pressed: on,
                    onClick: () => {
                        toolPrefs[type][key] = !on;
                        saveToolPrefs();
                        refreshChrome();
                    },
                }));
                break;
            }
            case "image":
                break;
        }
        if (state.selection.size) {
            ctx.append(iconButton({ icon: ICONS.trash, label: tr("Delete"), shortcut: "⌫", onClick: deleteSelection }));
        }
    }

    // Bottom bar
    const zoomBtn = h("button", { type: "button", class: "pe-btn pe-chip pe-zoom", "aria-haspopup": "menu", title: tr("Zoom") }, [h("span", {}, "100%"), h("span", { html: ICONS.chevron })]);
    zoomBtn.addEventListener("click", () => popover(root, zoomBtn, menuList([
        { id: "fit", label: tr("Zoom to Fit"), checked: view.fit },
        { id: "-", label: "" },
        ...ZOOMS.map((z) => ({ id: String(z), label: `${Math.round(z * 100)}%`, checked: !view.fit && Math.abs(view.zoom - z) < 0.001 })),
    ], (id) => {
        if (id === "fit") {
            fitView();
            refreshZoomLabel();
            invalidate();
        } else setZoom(Number(id));
    }), { placement: "above" }));
    const undoBtn = iconButton({ icon: ICONS.undo, label: tr("Undo"), shortcut: `${modKey}Z`, onClick: () => undo() });
    const redoBtn = iconButton({ icon: ICONS.redo, label: tr("Redo"), shortcut: `${modKey}⇧Z`, onClick: () => redo() });
    const dragBtn = h("button", { type: "button", class: "pe-btn pe-chip pe-drag", draggable: "true", title: tr("Drag the edited image into another app or folder"), "aria-label": tr("Drag me") }, [h("span", { html: ICONS.grip }), h("span", {}, tr("Drag me")), h("span", { html: ICONS.grip })]);
    dragBtn.addEventListener("dragstart", (e) => {
        const name = `${baseName()}-edited.png`;
        // Keep the snapshot check: text and pointer edits mutate doc before an afterChange revision exists.
        const currentJson = JSON.stringify(doc);
        if (!exportUrl || exportJson !== currentJson) {
            e.preventDefault();
            toast(tr("Preparing image… try again"));
            if (!exportPending || exportPreparingJson !== currentJson) scheduleExportUrl();
            return;
        }
        const dt = /** @type {DataTransfer} */ (e.dataTransfer);
        dt.effectAllowed = "copy";
        dt.setData("DownloadURL", `image/png:${name}:${new URL(exportUrl, location.href).href}`);
        dt.setData("text/uri-list", exportUrl);
        if (renderCache.canvas instanceof HTMLCanvasElement) {
            const thumb = document.createElement("canvas");
            const k = 120 / Math.max(renderCache.canvas.width, renderCache.canvas.height);
            thumb.width = Math.max(1, renderCache.canvas.width * k);
            thumb.height = Math.max(1, renderCache.canvas.height * k);
            thumb.getContext("2d")?.drawImage(renderCache.canvas, 0, 0, thumb.width, thumb.height);
            thumb.style.position = "absolute";
            thumb.style.left = "-9999px";
            document.body.append(thumb);
            dt.setDragImage(thumb, thumb.width / 2, thumb.height / 2);
            setTimeout(() => thumb.remove());
        }
    });
    dragBtn.addEventListener("click", () => toast(tr("Drag this handle into another app or folder")));
    const sizeLabel = h("span", { class: "pe-size-label", "aria-live": "polite" });
    const right = h("div", { class: "pe-ctx" }, [
        sizeLabel,
        iconButton({ icon: ICONS.rotateLeft, label: tr("Rotate left"), onClick: () => quickTransform("rotate") }),
        iconButton({ icon: ICONS.flipH, label: tr("Flip horizontally"), onClick: () => quickTransform("flip") }),
        iconButton({ icon: ICONS.copy, label: tr("Copy image"), shortcut: `${modKey}⇧C`, onClick: () => copyImage() }),
        iconButton({ icon: ICONS.download, label: tr("Download image"), onClick: () => openExportDialog() }),
        canShare() ? iconButton({ icon: ICONS.share, label: tr("Share"), onClick: () => shareImage() }) : null,
    ]);
    const cropBottom = h("div", { class: "pe-ctx", style: { flex: "1 1 auto", marginLeft: "12px" } });
    bottombar.append(h("div", { class: "pe-ctx" }, [zoomBtn, undoBtn, redoBtn]), dragBtn, right);

    zoomReady = true;
    function refreshZoomLabel() {
        zoomBtn.firstChild.textContent = `${Math.round(view.zoom * 100)}%`;
    }

    function refreshChrome({ refreshTopbar = true } = {}) {
        // Keep native range input attached throughout its gesture; commits still refresh the other chrome.
        if (refreshTopbar) buildTopbar();
        undoBtn.disabled = !history.canUndo;
        redoBtn.disabled = !history.canRedo;
        const s = outputSize(doc);
        sizeLabel.textContent = `${s.width} × ${s.height}`;
        refreshZoomLabel();
        // Crop mode swaps the export controls for snapping + revert.
        const cropping = state.mode === "crop";
        if (cropping && !cropBottom.isConnected) {
            crop.buildBottom(cropBottom);
            dragBtn.replaceWith(cropBottom);
            right.style.display = "none";
        } else if (!cropping && cropBottom.isConnected) {
            cropBottom.replaceWith(dragBtn);
            right.style.display = "";
        }
        panel.refresh();
        if (state.panelOpen && !panel.el.isConnected) body.prepend(panel.el);
        if (!state.panelOpen && panel.el.isConnected) panel.el.remove();
    }

    // ------------------------------------------------------------------ dialogs
    function modal(title, bodyNodes, buttons) {
        return new Promise((resolve) => {
            const back = h("div", { class: "pe-modal-back" });
            const close = (v) => {
                back.remove();
                root.focus();
                resolve(v);
            };
            const m = h("div", { class: "pe-modal", role: "dialog", "aria-modal": "true", "aria-label": title }, [
                h("h3", {}, title),
                ...bodyNodes,
                h("div", { class: "pe-actions" }, buttons.map((b) => h("button", { type: "button", class: `pe-btn pe-text-btn${b.primary ? " pe-primary" : ""}`, onclick: () => close(b.value) }, b.label))),
            ]);
            back.addEventListener("keydown", (e) => {
                e.stopPropagation();
                if (e.key === "Escape") close(null);
            });
            back.append(m);
            root.append(back);
            /** @type {HTMLElement|null} */ (m.querySelector("input,select,.pe-primary"))?.focus();
        });
    }

    async function openExportDialog() {
        const fmt = h("select", { class: "pe-select", "aria-label": tr("Format") }, [
            h("option", { value: "image/png" }, "PNG"),
            h("option", { value: "image/jpeg", selected: options.exportFormat === "jpeg" }, "JPEG"),
            h("option", { value: "image/webp" }, "WebP"),
        ]);
        const s = outputSize(doc);
        const ok = await modal(tr("Download image"), [
            h("p", {}, `${s.width} × ${s.height} px`),
            h("label", {}, [h("span", { class: "pe-field-label" }, tr("Format")), fmt]),
        ], [{ label: tr("Cancel"), value: null }, { label: tr("Download"), value: "ok", primary: true }]);
        if (ok) await downloadImage(fmt.value);
    }

    async function requestClose() {
        if (state.editingText) finishTextEdit();
        if (isDirty()) {
            const v = await modal(tr("Discard changes?"), [h("p", {}, tr("Your edits have not been saved."))], [
                { label: tr("Keep editing"), value: null },
                { label: tr("Discard"), value: "discard", primary: true },
            ]);
            if (!v) return;
        }
        options.onClose?.();
    }

    async function runAction(a) {
        if (state.busy) return;
        if (state.editingText) finishTextEdit();
        if (state.mode === "crop") crop.apply();
        state.busy = true;
        refreshChrome();
        try {
            await a.run(api);
        } catch (e) {
            toast(e?.message || tr("Something went wrong"));
            console.error(e);
        } finally {
            state.busy = false;
            refreshChrome();
        }
    }

    // ------------------------------------------------------------------ undo / redo
    function undo() {
        if (state.editingText) finishTextEdit();
        if (state.mode === "crop") return crop.cancel();
        const d = history.undo();
        if (d) setDocument(d, { record: false });
    }
    function redo() {
        if (state.mode === "crop") return;
        if (state.editingText) finishTextEdit(); // commits typed text (which also clears the redo stack)
        const d = history.redo();
        if (d) setDocument(d, { record: false });
    }

    // ------------------------------------------------------------------ modes
    const crop = createCropMode({
        get doc() { return doc; },
        state, view, tr, images, stage, root,
        invalidate, refreshChrome, fitView, refreshZoomLabel, toScreenMatrix: () => screenMatrix(), stageSize,
        apply(next) {
            doc = next;
            state.mode = "edit";
            stage.classList.remove("pe-checker");
            fitView();
            commit();
        },
        exit() {
            state.mode = "edit";
            stage.classList.remove("pe-checker");
            fitView();
            refreshChrome();
            invalidate();
        },
    });

    function enterCrop() {
        if (state.editingText) finishTextEdit();
        state.selection.clear();
        state.panelOpen = false;
        crop.enter();
        state.mode = "crop";
        stage.classList.add("pe-checker");
        fitView();
        refreshChrome();
        invalidate();
    }

    const panel = createBackgroundPanel({
        get doc() { return doc; },
        tr, store, images, root,
        commit: (opts) => commit(opts),
        seal: () => history.seal(),
        addResource,
        edgeColor: () => null,
        toast,
    });

    function togglePanel(open = !state.panelOpen) {
        state.panelOpen = open;
        refreshChrome();
        requestAnimationFrame(() => {
            if (view.fit) fitView();
            invalidate();
        });
    }

    // ------------------------------------------------------------------ keyboard
    function onKeyDown(e) {
        const t = /** @type {HTMLElement} */ (e.target);
        if (t && (t.tagName === "INPUT" || t.tagName === "TEXTAREA" || t.tagName === "SELECT" || t.isContentEditable)) return;
        const mod = isMac ? e.metaKey : e.ctrlKey;
        const k = e.key.toLowerCase();
        if (e.key === " " && !state.space) {
            state.space = true;
            refreshCursor();
            e.preventDefault();
            return;
        }
        if (mod && k === "z") {
            e.preventDefault();
            if (e.shiftKey) redo();
            else undo();
            return;
        }
        if (mod && k === "y") { e.preventDefault(); redo(); return; }
        if (mod && (k === "=" || k === "+")) { e.preventDefault(); setZoom(view.zoom * 1.25); return; }
        if (mod && k === "-") { e.preventDefault(); setZoom(view.zoom / 1.25); return; }
        if (mod && k === "0") { e.preventDefault(); fitView(); refreshZoomLabel(); invalidate(); return; }
        if (mod && k === "1") { e.preventDefault(); setZoom(1); return; }
        if (state.mode === "crop") {
            crop.keyDown(e);
            return;
        }
        if (mod && k === "d") { e.preventDefault(); duplicateSelection(); return; }
        if (mod && k === "a") { e.preventDefault(); state.selection = new Set(doc.objects.map((o) => o.id)); refreshChrome(); invalidate(); return; }
        if (mod && k === "c") {
            e.preventDefault();
            if (e.shiftKey || !state.selection.size) copyImage();
            else {
                state.clipboard = selected().map((o) => clone(o));
                toast(tr("Copied"));
            }
            return;
        }
        if (mod && k === "s") {
            e.preventDefault();
            const a = (options.actions || []).find((x) => x.primary) || (options.actions || [])[0];
            if (a) runAction(a);
            return;
        }
        // UPSTREAM-DEVIATION (The Mothership): with Shift a bracket key reports "{" / "}", so those count as "[" / "]" here;
        // otherwise Shift+Cmd+[ / ] (send to back, bring to front) would do nothing.
        const bracket = e.key === "{" ? "[" : e.key === "}" ? "]" : e.key;
        if (mod && (bracket === "]" || bracket === "[")) {
            e.preventDefault();
            reorder(bracket === "]" ? (e.shiftKey ? "front" : "forward") : e.shiftKey ? "back" : "backward");
            return;
        }
        if (e.key === "Backspace" || e.key === "Delete") { e.preventDefault(); deleteSelection(); return; }
        if (e.key === "Escape") {
            if (state.drag) return;
            if (state.panelOpen) togglePanel(false);
            else if (state.selection.size) { state.selection.clear(); refreshChrome(); invalidate(); }
            else if (state.tool !== "select") setTool("select");
            else if (options.onClose) requestClose();
            return;
        }
        if (e.key === "Enter") {
            const sel = selected();
            if (sel.length === 1 && sel[0].type === "text") { e.preventDefault(); startTextEdit(sel[0].id); }
            return;
        }
        if (e.key.startsWith("Arrow")) {
            e.preventDefault();
            const step = e.shiftKey ? 10 : 1;
            nudge(e.key === "ArrowLeft" ? -step : e.key === "ArrowRight" ? step : 0, e.key === "ArrowUp" ? -step : e.key === "ArrowDown" ? step : 0);
            return;
        }
        if (mod || e.altKey) return;
        if (e.key === "[" || e.key === "]") {
            const type = contextType();
            if (!type || !toolPrefs[type]) return;
            const key = type === "text" ? "fontSize" : type === "counter" ? "size" : "thickness";
            if (!(key in toolPrefs[type])) return;
            const cur = currentProp(type, key);
            const list = type === "text" ? TEXT_SIZES : type === "counter" ? [24, 32, 44, 60] : THICKNESS;
            const idx = list.findIndex((v) => v >= cur);
            const next = e.key === "]" ? list[Math.min(list.length - 1, idx < 0 ? list.length - 1 : list[idx] > cur ? idx : idx + 1)] : list[Math.max(0, (idx < 0 ? list.length : idx) - 1)];
            setProp(type, key, next);
            return;
        }
        if (k === "b") { togglePanel(); return; }
        const tool = TOOLS.find((x) => x.key === k);
        if (tool) setTool(tool.id);
    }
    function onKeyUp(e) {
        if (e.key === " ") {
            state.space = false;
            refreshCursor();
        }
    }

    function onContextMenu(e) {
        if (state.mode !== "edit") return;
        e.preventDefault();
        const [sx, sy] = localPoint(e);
        const [px, py] = toSource(sx, sy);
        const hit = topHit(px, py);
        if (hit && !state.selection.has(hit.id)) state.selection = new Set([hit.id]);
        refreshChrome();
        invalidate();
        if (!state.selection.size) return;
        const anchor = h("div", { style: { position: "absolute", left: `${sx}px`, top: `${sy + 44}px`, width: "1px", height: "1px" } });
        root.append(anchor);
        popover(root, anchor, menuList([
            { id: "duplicate", label: `${tr("Duplicate")}  ${modKey}D` },
            { id: "copy", label: `${tr("Copy")}  ${modKey}C` },
            { id: "-", label: "" },
            { id: "front", label: tr("Bring to Front") },
            { id: "forward", label: tr("Bring Forward") },
            { id: "backward", label: tr("Send Backward") },
            { id: "back", label: tr("Send to Back") },
            { id: "-", label: "" },
            { id: "delete", label: tr("Delete"), danger: true },
        ], (id) => {
            if (id === "duplicate") duplicateSelection();
            else if (id === "copy") state.clipboard = selected().map((o) => clone(o));
            else if (id === "delete") deleteSelection();
            else reorder(id);
        }), { onClose: () => anchor.remove() });
    }

    async function onPaste(e) {
        if (state.mode !== "edit" || state.editingText) return;
        const t = /** @type {HTMLElement} */ (e.target);
        if (t && (t.tagName === "INPUT" || t.tagName === "TEXTAREA")) return;
        const file = [...(e.clipboardData?.files || [])].find((f) => f.type.startsWith("image/"));
        if (file) {
            e.preventDefault();
            await addImageFile(file);
        } else if (pasteObjects()) e.preventDefault();
    }

    // ------------------------------------------------------------------ wiring
    const fileInput = h("input", { type: "file", accept: "image/png,image/jpeg,image/webp,image/gif", hidden: true });
    fileInput.addEventListener("change", () => {
        const f = fileInput.files?.[0];
        fileInput.value = "";
        if (f) addImageFile(f);
    });
    root.append(fileInput);
    stage.addEventListener("pointerdown", onPointerDown);
    // The editor manages focus itself; the default mousedown focus change would steal it from the text box.
    stage.addEventListener("mousedown", (e) => {
        if (e.target !== textArea) e.preventDefault();
    });
    stage.addEventListener("pointermove", onPointerMove);
    stage.addEventListener("pointerup", onPointerUp);
    stage.addEventListener("pointercancel", onPointerUp);
    stage.addEventListener("pointerleave", () => {
        if (!state.drag && state.hover) {
            state.hover = null;
            invalidate();
        }
    });
    stage.addEventListener("wheel", onWheel, { passive: false });
    stage.addEventListener("contextmenu", onContextMenu);
    stage.addEventListener("dragover", (e) => {
        if ([...(e.dataTransfer?.items || [])].some((i) => i.kind === "file")) e.preventDefault();
    });
    stage.addEventListener("drop", (e) => {
        const f = [...(e.dataTransfer?.files || [])].find((x) => x.type.startsWith("image/"));
        if (!f || state.mode !== "edit") return;
        e.preventDefault();
        const [sx, sy] = localPoint(e);
        addImageFile(f, toSource(sx, sy));
    });
    root.addEventListener("keydown", onKeyDown);
    // Focus can fall back to <body> (e.g. a clicked control was removed); still honour shortcuts then.
    const onDocKeyDown = (e) => {
        if (document.activeElement === document.body && root.isConnected && !root.querySelector(".pe-modal-back")) onKeyDown(e);
    };
    const onDocKeyUp = (e) => {
        if (document.activeElement === document.body) onKeyUp(e);
    };
    document.addEventListener("keydown", onDocKeyDown);
    document.addEventListener("keyup", onDocKeyUp);
    root.addEventListener("keyup", onKeyUp);
    root.addEventListener("paste", onPaste);
    const ro = new ResizeObserver(() => {
        if (view.fit) fitView();
        else clampPan();
        layoutTextArea();
        invalidate();
    });
    ro.observe(stage);

    const api = {
        root,
        getDocument: () => clone(doc),
        getResources: () => ({ ...resources }),
        exportBlob,
        outputSize: () => outputSize(doc),
        isDirty,
        /**
         * Mark a document as saved. Pass the exact snapshot that was submitted: edits made
         * while the save was in flight stay dirty. Without an argument the current document is used.
         */
        markSaved(snapshot) {
            savedJson = JSON.stringify(snapshot ?? doc);
            afterChange();
        },
        setTool,
        undo,
        redo,
        toast,
        modal,
        get state() { return { tool: state.tool, selection: [...state.selection], mode: state.mode, panelOpen: state.panelOpen, zoom: view.zoom }; },
        /** Source px → viewport (client) px; used by hosts and tests to place pointer input. */
        sourceToClient(x, y) {
            const r = stage.getBoundingClientRect();
            const [sx, sy] = toScreen(x, y);
            return { x: r.left + sx, y: r.top + sy };
        },
        select(ids) { state.selection = new Set(ids); refreshChrome(); invalidate(); },
        setDocument(next) { setDocument(toV2(clone(next))); },
        enterCrop,
        togglePanel,
        zoomTo: (z) => (z === "fit" ? (fitView(), refreshZoomLabel(), invalidate()) : setZoom(z)),
        requestClose,
        destroy() {
            destroyed = true;
            exportRevision++;
            cancelAnimationFrame(raf);
            ro.disconnect();
            document.removeEventListener("keydown", onDocKeyDown);
            document.removeEventListener("keyup", onDocKeyUp);
            themeObserver.disconnect();
            media?.removeEventListener?.("change", applyTheme);
            clearTimeout(exportTimer);
            if (exportIdle) globalThis.cancelIdleCallback?.(exportIdle);
            if (exportUrl) URL.revokeObjectURL(exportUrl);
            for (const [url, timer] of retiredExportUrls) {
                clearTimeout(timer);
                URL.revokeObjectURL(url);
            }
            retiredExportUrls.clear();
            for (const u of ownedUrls) URL.revokeObjectURL(u);
            closePopover();
            root.remove();
        },
    };

    refreshChrome();
    fitView();
    refreshZoomLabel();
    scheduleExportUrl();
    invalidate();
    root.focus({ preventScroll: true });
    /** @type {any} */ (root).__photoedit = api;
    return api;
}

export { arrowControl, quadPoint };
