// @ts-check
/**
 * Background sidebar: presets, gradient / wallpaper / blurred / plain backgrounds,
 * padding, inset, shadow, corners, auto-balance, alignment and aspect ratio.
 * Built once; `refresh()` only syncs values so a slider drag is never interrupted.
 */
import { clone } from "../core/model.js";
import { h, iconButton, popover, closePopover, menuList, slider } from "./dom.js";
import { ICONS } from "./icons.js";
import { GRADIENTS, PLAIN_COLORS, BLURRED, RATIOS, gradientCss } from "./backgrounds.js";

const PRESETS_KEY = "photoedit.bg-presets.v1";
const WALLPAPERS_KEY = "photoedit.bg-wallpapers.v1";

/** @param {any} ctx */
export function createBackgroundPanel(ctx) {
    const tr = ctx.tr;
    const el = h("aside", { class: "pe-panel", "aria-label": tr("Background") });
    /** @type {(() => void)[]} */
    const syncers = [];
    const bg = () => ctx.doc.background;

    /** Mutate the background and record an undo step. */
    function change(patch, coalesce) {
        Object.assign(ctx.doc.background, patch);
        ctx.commit(coalesce ? { coalesce } : {});
    }

    const sameGradient = (b, g) => b.type === "gradient" && b.angle === g.angle && b.gradient.join() === g.gradient.join();

    // ------------------------------------------------------------ presets
    const presetsRow = h("div", { class: "pe-grid" });
    function presetTile(p, i) {
        const b = p.background;
        const style = b.type === "gradient" ? { background: gradientCss(b) } : b.type === "solid" ? { background: b.color } : b.type === "transparent" ? {} : { background: "#888" };
        const t = h("button", { type: "button", class: `pe-tile${b.type === "transparent" ? " pe-transparent" : ""}`, title: p.name, "aria-label": p.name, style });
        t.addEventListener("click", () => {
            if (b.type === "image" && b.image && !ctx.images.has(b.image)) {
                ctx.toast(tr("This preset's wallpaper is not available here"));
                return;
            }
            change({ ...clone(b), enabled: true });
        });
        t.addEventListener("contextmenu", (e) => {
            e.preventDefault();
            e.stopPropagation();
            popover(ctx.root, t, menuList([{ id: "delete", label: tr("Delete Preset"), danger: true }], () => {
                const list = ctx.store.get(PRESETS_KEY, []);
                list.splice(i, 1);
                ctx.store.set(PRESETS_KEY, list);
                renderPresets();
            }));
        });
        return t;
    }
    function renderPresets() {
        const list = ctx.store.get(PRESETS_KEY, []);
        presetsRow.replaceChildren(...list.map(presetTile));
        presetsRow.style.display = list.length ? "" : "none";
    }
    const savePreset = h("button", { type: "button", title: tr("Save current background as a preset") }, `+ ${tr("Save")}`);
    savePreset.addEventListener("click", () => {
        const b = bg();
        if (!b.enabled) {
            ctx.toast(tr("Choose a background first"));
            return;
        }
        const list = ctx.store.get(PRESETS_KEY, []);
        const { enabled, ...rest } = clone(b);
        void enabled;
        list.unshift({ name: `${tr("Preset")} ${list.length + 1}`, background: rest });
        ctx.store.set(PRESETS_KEY, list.slice(0, 20));
        renderPresets();
        ctx.toast(tr("Preset saved"));
    });
    el.append(h("section", {}, [h("h4", {}, [tr("Presets"), savePreset]), presetsRow]));
    renderPresets();

    // ------------------------------------------------------------ backgrounds
    const noneTile = h("button", { type: "button", class: "pe-tile pe-none", title: tr("None"), "aria-label": tr("No background"), style: { background: "var(--pe-raised)", display: "flex", alignItems: "center", justifyContent: "center", color: "var(--pe-muted)", fontSize: "11px" } }, tr("None"));
    noneTile.addEventListener("click", () => change({ enabled: false }));
    syncers.push(() => noneTile.setAttribute("aria-pressed", String(!bg().enabled)));

    const gradTiles = GRADIENTS.map((g, i) => {
        const t = h("button", { type: "button", class: "pe-tile", title: `${tr("Gradient")} ${i + 1}`, "aria-label": `${tr("Gradient")} ${i + 1}`, style: { background: gradientCss(g) } });
        t.addEventListener("click", () => change({ enabled: true, type: "gradient", gradient: [...g.gradient], angle: g.angle }));
        syncers.push(() => t.setAttribute("aria-pressed", String(bg().enabled && sameGradient(bg(), g))));
        return t;
    });
    el.append(h("section", {}, [h("h4", {}, tr("Gradients")), h("div", { class: "pe-grid" }, [noneTile, ...gradTiles])]));

    // Wallpapers: images the user added (kept per browser as resource ids + urls).
    const wallGrid = h("div", { class: "pe-grid" });
    const wallInput = h("input", { type: "file", accept: "image/png,image/jpeg,image/webp", hidden: true });
    function renderWallpapers() {
        const known = ctx.store.get(WALLPAPERS_KEY, []).filter((w) => ctx.images.has(w.id));
        const cur = bg();
        if (cur.type === "image" && cur.image && ctx.images.has(cur.image) && !known.some((w) => w.id === cur.image)) known.unshift({ id: cur.image });
        const tiles = known.map((w) => {
            const img = ctx.images.get(w.id);
            const t = h("button", { type: "button", class: "pe-tile", title: tr("Wallpaper"), "aria-label": tr("Wallpaper"), style: { backgroundImage: `url("${img.src}")` } });
            t.setAttribute("aria-pressed", String(cur.enabled && cur.type === "image" && cur.image === w.id));
            t.addEventListener("click", () => change({ enabled: true, type: "image", image: w.id }));
            return t;
        });
        const add = h("button", { type: "button", class: "pe-tile pe-add", title: tr("Add wallpaper"), "aria-label": tr("Add wallpaper"), html: ICONS.plus });
        add.addEventListener("click", () => wallInput.click());
        wallGrid.replaceChildren(...tiles, add);
    }
    wallInput.addEventListener("change", async () => {
        const f = wallInput.files?.[0];
        wallInput.value = "";
        if (!f) return;
        try {
            const res = await ctx.addResource(f);
            const list = ctx.store.get(WALLPAPERS_KEY, []).filter((w) => w.id !== res.id);
            list.unshift({ id: res.id });
            ctx.store.set(WALLPAPERS_KEY, list.slice(0, 15));
            change({ enabled: true, type: "image", image: res.id });
        } catch (e) {
            ctx.toast(tr("The wallpaper could not be added"));
            console.error(e);
        }
    });
    syncers.push(renderWallpapers);
    el.append(h("section", {}, [h("h4", {}, tr("Wallpapers")), wallGrid, wallInput]));

    // Blurred: the image itself, blurred and tinted.
    const blurTiles = BLURRED.map((b) => {
        const t = h("button", { type: "button", class: "pe-tile", title: tr(b.label), "aria-label": tr(b.label), style: { background: `radial-gradient(circle at 35% 40%, ${b.color === "#101114" ? "#3b3d48" : "#c9d4ff"}, ${b.color})`, filter: "saturate(1.2)" } });
        t.addEventListener("click", () => change({ enabled: true, type: "blurred", color: b.color }));
        syncers.push(() => t.setAttribute("aria-pressed", String(bg().enabled && bg().type === "blurred" && bg().color === b.color)));
        return t;
    });
    el.append(h("section", {}, [h("h4", {}, tr("Blurred")), h("div", { class: "pe-grid" }, blurTiles)]));

    // Plain colours (+ transparent + custom).
    const transparentDot = h("button", { type: "button", class: "pe-dot pe-transparent", title: tr("Transparent"), "aria-label": tr("Transparent") });
    transparentDot.addEventListener("click", () => change({ enabled: true, type: "transparent" }));
    syncers.push(() => transparentDot.setAttribute("aria-pressed", String(bg().enabled && bg().type === "transparent")));
    const colorDots = PLAIN_COLORS.map((c) => {
        const d = h("button", { type: "button", class: "pe-dot", title: c, "aria-label": c, style: { background: c } });
        d.addEventListener("click", () => change({ enabled: true, type: "solid", color: c }));
        syncers.push(() => d.setAttribute("aria-pressed", String(bg().enabled && bg().type === "solid" && bg().color.toLowerCase() === c)));
        return d;
    });
    const custom = h("input", { type: "color", value: "#2f7bf6", "aria-label": tr("Custom color"), style: { position: "absolute", opacity: "0", width: "1px", height: "1px", pointerEvents: "none" } });
    const wheel = h("button", { type: "button", class: "pe-dot pe-wheel", title: tr("Custom color"), "aria-label": tr("Custom color") });
    wheel.addEventListener("click", () => {
        custom.value = bg().type === "solid" ? bg().color.slice(0, 7) : "#2f7bf6";
        custom.click();
    });
    custom.addEventListener("input", () => change({ enabled: true, type: "solid", color: custom.value.toLowerCase() }, "bg-custom"));
    custom.addEventListener("change", () => ctx.seal());
    syncers.push(() => wheel.setAttribute("aria-pressed", String(bg().enabled && bg().type === "solid" && !PLAIN_COLORS.includes(bg().color.toLowerCase()))));
    el.append(h("section", {}, [h("h4", {}, tr("Plain color")), h("div", { class: "pe-dots" }, [transparentDot, ...colorDots, wheel, custom])]));

    // ------------------------------------------------------------ geometry
    const sliders = [
        { key: "padding", label: tr("Padding"), min: 0, max: 400 },
        { key: "inset", label: tr("Inset"), min: 0, max: 200 },
        { key: "shadow", label: tr("Shadow"), min: 0, max: 100 },
        { key: "corners", label: tr("Corners"), min: 0, max: 100 },
    ].map((s) => {
        const sl = slider({
            label: s.label, min: s.min, max: s.max, value: bg()[s.key],
            onInput: (v) => change({ enabled: true, [s.key]: Math.round(v) }, `bg-${s.key}`),
            onChange: () => ctx.seal(),
        });
        const out = h("span", { class: "pe-crop-hint" }, "");
        sl.el.firstChild.after(out);
        sl.el.firstChild.style.display = "flex";
        syncers.push(() => {
            if (document.activeElement !== sl.input) sl.set(bg()[s.key]);
            out.textContent = String(Math.round(bg()[s.key]));
        });
        const head = h("div", { style: { display: "flex", justifyContent: "space-between" } }, [sl.el.firstChild, out]);
        sl.el.prepend(head);
        return sl.el;
    });
    el.append(h("section", { class: "pe-row2" }, sliders));

    const auto = h("input", { type: "checkbox", "aria-label": tr("Auto-balance") });
    auto.addEventListener("change", () => change({ enabled: true, autoBalance: auto.checked }));
    syncers.push(() => (auto.checked = bg().autoBalance));
    el.append(h("label", { class: "pe-check-row", title: tr("Equalize uneven solid margins around the screenshot") }, [auto, h("span", {}, tr("Auto-balance"))]));

    const alignBtns = Array.from({ length: 9 }, (_, i) => {
        const col = i % 3, row = Math.floor(i / 3);
        const names = [["Top left", "Top", "Top right"], ["Left", "Center", "Right"], ["Bottom left", "Bottom", "Bottom right"]];
        const b = h("button", { type: "button", title: tr(names[row][col]), "aria-label": tr(names[row][col]) });
        b.style.setProperty("--ax", `${col * 50}%`);
        b.addEventListener("click", () => change({ enabled: true, alignment: i }));
        syncers.push(() => b.setAttribute("aria-pressed", String(bg().alignment === i)));
        return b;
    });
    // Position each mini rectangle inside its button to show the alignment.
    alignBtns.forEach((b, i) => {
        const col = i % 3, row = Math.floor(i / 3);
        const style = h("style", {}, "");
        void style;
        b.dataset.col = String(col);
        b.dataset.row = String(row);
    });
    const ratioSel = h("select", { class: "pe-select", "aria-label": tr("Aspect ratio") }, RATIOS.map((r) => h("option", { value: r.id }, tr(r.label))));
    ratioSel.addEventListener("change", () => {
        const r = RATIOS.find((x) => x.id === ratioSel.value);
        change({ enabled: true, ratio: r?.ratio ? [...r.ratio] : null });
    });
    syncers.push(() => {
        const cur = bg().ratio;
        const m = RATIOS.find((r) => (r.ratio === null && cur === null) || (r.ratio && cur && r.ratio[0] === cur[0] && r.ratio[1] === cur[1]));
        ratioSel.value = m ? m.id : "auto";
    });
    el.append(h("section", { class: "pe-row2", style: { alignItems: "start" } }, [
        h("div", {}, [h("div", { class: "pe-field-label", style: { marginBottom: "6px" } }, tr("Alignment")), h("div", { class: "pe-align" }, alignBtns)]),
        h("div", {}, [h("div", { class: "pe-field-label", style: { marginBottom: "6px" } }, tr("Aspect Ratio")), ratioSel]),
    ]));

    function refresh() {
        for (const s of syncers) s();
    }
    refresh();
    void iconButton;
    void closePopover;
    return { el, refresh };
}
