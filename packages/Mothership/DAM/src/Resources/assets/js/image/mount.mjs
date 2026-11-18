import { createApp } from "vue";
import Editor from "./Editor.vue";
import { mountEditor as mountPhotoEdit } from "./photoedit/adapters/mothership.js";
import { mountThumbnailAdapter } from "./thumbnail-adapter.mjs";

// The vendor palette (photoedit/ui/styles.js `.pe-root` and `.pe-root.pe-dark`). Until PhotoEdit creates its own root the
// loading and error states are drawn on this panel, so the panel carries the variables those states read.
const LIGHT = {
    "--pe-surface": "#f6f6f8", "--pe-raised": "#fff", "--pe-line": "rgba(0,0,0,.09)", "--pe-ink": "#1d1f24", "--pe-muted": "#6b707b",
    "--pe-hover": "rgba(0,0,0,.06)", "--pe-pressed": "rgba(0,0,0,.1)", "--pe-accent": "#2f7bf6", "--pe-accent-ink": "#fff",
};
const DARK = {
    "--pe-surface": "#232428", "--pe-raised": "#34353b", "--pe-line": "rgba(255,255,255,.1)", "--pe-ink": "#eeeff2", "--pe-muted": "#a3a7b1",
    "--pe-hover": "rgba(255,255,255,.08)", "--pe-pressed": "rgba(255,255,255,.14)", "--pe-accent": "#2f7bf6", "--pe-accent-ink": "#fff",
};
// Vendor bars scroll sideways (`overflow-x:auto`, no wrap), which on a phone leaves the save and close buttons off-screen at
// the end of the tool row. In the overlay they wrap instead, so every control stays on screen; photoedit/ itself is untouched.
// The vendor's narrow layout floats the background panel over the left of the picture, which on a phone leaves a sliver of
// it; here the panel stacks above the picture (scrolling within a bounded height) and the editor refits the picture below.
// The wrapped bars keep their full height (the vendor lets them shrink to one row, hiding the rest under the panel).
const PHONE_CSS = `
[data-dam-photoedit-overlay] .pe-bar{flex-wrap:wrap;overflow-x:visible;row-gap:4px;flex-shrink:0}
[data-dam-photoedit-overlay] .pe-bar .pe-group,[data-dam-photoedit-overlay] .pe-bar .pe-ctx{flex-wrap:wrap;max-width:100%;row-gap:4px}
[data-dam-photoedit-overlay] .pe-host{display:flex;flex-direction:column;min-height:0}
[data-dam-photoedit-overlay] .pe-host>.pe-root{height:auto;flex:1;min-height:0}
[data-dam-photoedit-overlay] .pe-source-notice{flex-shrink:0;margin:0;padding:6px 10px;background:var(--pe-raised);color:var(--pe-ink);border-bottom:1px solid var(--pe-line);font-size:12px}
@media (max-width:640px){
[data-dam-photoedit-overlay] .pe-body{flex-direction:column}
[data-dam-photoedit-overlay] .pe-stage{min-height:0}
[data-dam-photoedit-overlay] .pe-panel{position:static;width:auto;max-height:45%;border-right:0;border-bottom:1px solid var(--pe-line);box-shadow:none}
[data-dam-photoedit-overlay] .pe-bottom{justify-content:center}
[data-dam-photoedit-overlay] .pe-size-label{white-space:nowrap}
}`;
// The page tints a focused [role=menu] [role=menuitem] with its pale primary-50 behind PhotoEdit's white focused-item text,
// which leaves that item unreadable; in the overlay PhotoEdit's own focused-item colors win.
const MENU_CSS = `[data-dam-photoedit-overlay] .pe-root .pe-popover .pe-menu-item:focus-visible{background:var(--pe-accent);color:var(--pe-accent-ink);outline:none}`;
const FOCUSABLE = 'a[href],button,input,select,textarea,[tabindex]:not([tabindex="-1"])';

/**
 * PhotoEdit sizes itself to its host (width and height 100%), and the asset page's host is an unsized div inside the
 * preview column. This gives it what the legacy editor's Teleport supplied: a viewport overlay on <body> above the
 * page (same stacking as .ag-editor-backdrop) holding a 94vh dialog panel. Like the legacy editor it does not lock
 * the page's scroll. As an aria-modal dialog it takes focus when it opens, keeps Tab inside it (including while
 * the editor is loading or has failed to load) and gives focus back to the opener when it is torn down.
 * The page's onSaved and onClose reach the page only while the overlay is mounted: a save that answers after teardown
 * must not reload a page the person has since moved on in (the vendored adapter reports a late save unconditionally).
 * The page's onSaved closes the editor and reloads. While a save is in flight PhotoEdit still takes keyboard and toolbar
 * edits and Crop & resize, which are not in what was saved (the editor keeps them unsaved), so a save that answers while
 * the editor has unsaved edits or an open crop leaves it open, and reaches the page in place of onClose when the person
 * closes the editor. A value still being typed in a field when a request answers counts as such an edit.
 */
function mountPhotoEditOverlay(element, props, mountInner = mountPhotoEdit, label = "Image editor") {
    const opener = document.activeElement;
    const backdrop = document.createElement("div");
    Object.assign(backdrop.style, {
        position: "fixed",
        inset: "0",
        zIndex: "10030",
        background: "#000a",
        display: "grid",
        placeItems: "center",
    });
    const panel = document.createElement("div");
    panel.setAttribute("role", "dialog");
    panel.setAttribute("aria-modal", "true");
    panel.setAttribute("aria-label", props.labels?.[label] || label);
    panel.tabIndex = -1;
    panel.setAttribute("data-dam-photoedit-overlay", "");
    const overlayStyle = document.createElement("style");
    overlayStyle.textContent = PHONE_CSS + MENU_CSS;
    panel.append(overlayStyle);
    Object.assign(panel.style, {
        position: "relative",
        width: "min(1340px, 96vw)",
        height: "94vh",
        maxHeight: "1000px",
        overflow: "hidden",
        borderRadius: "16px",
        boxShadow: "0 24px 90px #0006",
        background: "var(--pe-surface)",
        color: "var(--pe-ink)",
        outline: "none",
        font: "13px/1.35 -apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif",
    });
    // The same rule the editor applies to its own root with theme "auto".
    const media = globalThis.matchMedia ? matchMedia("(prefers-color-scheme: dark)") : null;
    const applyTheme = () => {
        const dark = document.documentElement.classList.contains("dark") || !!media?.matches;
        for (const [name, value] of Object.entries(dark ? DARK : LIGHT)) panel.style.setProperty(name, value);
        panel.style.colorScheme = dark ? "dark" : "light";
    };
    applyTheme();
    const themeObserver = new MutationObserver(applyTheme);
    themeObserver.observe(document.documentElement, { attributes: true, attributeFilter: ["class"] });
    media?.addEventListener?.("change", applyTheme);

    const keepTabInside = (event) => {
        if (event.key !== "Tab" || event.defaultPrevented) return;
        const inside = [...panel.querySelectorAll(FOCUSABLE)].filter((el) => !el.disabled && el.getClientRects().length > 0);
        const first = inside[0];
        const last = inside[inside.length - 1];
        const active = document.activeElement;
        if (!first) {
            event.preventDefault();
            panel.focus({ preventScroll: true });
        } else if (!panel.contains(active) || active === panel) {
            event.preventDefault();
            (event.shiftKey ? last : first).focus({ preventScroll: true });
        } else if (event.shiftKey && active === first) {
            event.preventDefault();
            last.focus({ preventScroll: true });
        } else if (!event.shiftKey && active === last) {
            event.preventDefault();
            first.focus({ preventScroll: true });
        }
    };
    document.addEventListener("keydown", keepTabInside, true);

    backdrop.append(panel);
    document.body.append(backdrop);
    panel.focus({ preventScroll: true });
    let active = true;
    /** @type {{data:any}|null} */
    let savedUnderEdits = null;
    // An open Crop & resize keeps its draft outside the document until it is applied, so it counts as unsaved too.
    const hasUnsavedEdits = () => {
        const editor = /** @type {any} */ (panel.querySelector(".pe-root"))?.__photoedit;
        return !!editor && (editor.isDirty() || editor.state.mode === "crop");
    };
    const onSaved = (data) => {
        if (!active) return;
        if (hasUnsavedEdits()) savedUnderEdits = { data };
        else props.onSaved?.(data);
    };
    const onClose = () => {
        if (!active) return;
        if (savedUnderEdits && props.onSaved) props.onSaved(savedUnderEdits.data);
        else props.onClose?.();
    };
    // PhotoEdit takes a typed Crop size or Hex color only on change, and redraws its bars (replacing the field) when a
    // request it made settles. Leaving the focused field first lets PhotoEdit take the typed value its own way, so a save
    // that answers mid-typing finds it as unsaved work. The text editor (a textarea) takes each keystroke and keeps focus.
    const takeTypedValue = () => {
        const field = document.activeElement;
        if (active && field instanceof HTMLInputElement && panel.contains(field) && !/^(checkbox|radio|range|color|file)$/.test(field.type)) field.blur();
    };
    const http = props.http || /** @type {any} */ (globalThis).axios;
    const settling = http && {
        get: (...args) => http.get(...args),
        post: (...args) => http.post(...args).finally(takeTypedValue),
    };
    const unmount = mountInner(panel, { ...props, ...(settling && { http: settling }), onSaved, onClose });
    return () => {
        if (!active) return;
        active = false;
        unmount();
        document.removeEventListener("keydown", keepTabInside, true);
        themeObserver.disconnect();
        media?.removeEventListener?.("change", applyTheme);
        backdrop.remove();
        if (opener instanceof HTMLElement && opener.isConnected) opener.focus({ preventScroll: true });
    };
}

// Template editing keeps the legacy Vue editor; asset images use PhotoEdit.
export function mountEditor(element, props) {
    if (!props.templateMode) {
        return mountPhotoEditOverlay(element, props);
    }
    const app = createApp(Editor, props);
    app.mount(element);
    return () => app.unmount();
}

// A 3D model, PDF or video has no editable original: its real thumbnail is edited in the same overlay and saved as the
// asset's custom thumbnail (thumbnail-adapter.mjs).
export function mountThumbnailEditor(element, props) {
    return mountPhotoEditOverlay(element, props, mountThumbnailAdapter, "Edit thumbnail");
}
