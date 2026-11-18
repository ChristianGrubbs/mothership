// @ts-check
/**
 * Thumbnail editing for the asset page's non-photo assets (a 3D model, a PDF, a video). Not part of the vendored
 * PhotoEdit sources (photoedit/ differs from upstream only as PHOTOEDIT-UPSTREAM-DEVIATIONS.md lists): it drives the
 * same createEditor() with the asset's current raster thumbnail as the base and ONE action that posts the render to
 * the existing custom-thumbnail
 * endpoint (CustomThumbnailController::store). The original file, its versions and its editor document are never
 * touched, so there is no "Save new version" or "Save as new asset" here.
 *
 *   GET  {thumbnailUrl}   → the current thumbnail (must be a PNG, JPEG, WebP or GIF; the type placeholder is not editable)
 *   POST {endpoint}       multipart: thumbnail (PNG) → 201 { thumbnail_url }
 *
 * HTTP goes through the page's `window.axios` (it carries the CSRF header), like the original-image adapter.
 * Object URLs (the fetched thumbnail, and any image the person adds to the picture, which the editor keeps local) are
 * revoked on teardown. Diagnostics are env-gated and carry no content: set `localStorage["dam.thumbnailEditor.debug"]`.
 */
import { createEditor } from "./photoedit/ui/editor.js";
import { h } from "./photoedit/ui/dom.js";
import { injectStyles } from "./photoedit/ui/styles.js";

const EDITABLE_TYPES = ["image/png", "image/jpeg", "image/webp", "image/gif"];

const trace = (event, ctx = {}) => {
    try {
        if (globalThis.localStorage?.getItem("dam.thumbnailEditor.debug") !== "1") return;
        console.debug(JSON.stringify({ ts: new Date().toISOString(), level: "debug", event, loc: "dam-thumbnail-editor", ctx }));
    } catch {
        // Diagnostics never change what the editor does.
    }
};

/**
 * @param {HTMLElement} element
 * @param {{assetId:number, fileName?:string, thumbnailUrl:string, endpoint:string, labels?:Record<string,string>,
 *          onClose:()=>void, onSaved:(data:any)=>void, http?:any}} props
 * @returns {() => void} unmount
 */
export function mountThumbnailAdapter(element, props) {
    injectStyles();
    const tr = (t) => (props.labels && props.labels[t]) || t;
    const http = props.http || /** @type {any} */ (globalThis).axios;
    let disposed = false;
    /** @type {any} */
    let editor = null;
    /** @type {string|null} */
    let baseUrl = null;

    const host = h("div", { class: "pe-host", style: { position: "relative", width: "100%", height: "100%" } });
    const status = h("div", { class: "pe-busy", role: "status" }, tr("Loading…"));
    host.append(status);
    element.append(host);

    const message = (e) => e?.response?.data?.errors?.thumbnail?.[0] || e?.response?.data?.message || e?.message || tr("Something went wrong.");

    async function save(api) {
        const doc = api.getDocument(); // the submitted snapshot: edits made during the request stay unsaved
        const blob = await api.exportBlob("image/png");
        const form = new FormData();
        form.append("thumbnail", blob, "thumbnail.png");
        try {
            const { data } = await http.post(props.endpoint, form);
            trace("saved", { asset_id: props.assetId, disposed });
            // Closed while the request ran: the thumbnail is saved, but the destroyed editor has nothing to update, and
            // the page has moved on (its onSaved reloads, which would throw away whatever the person is doing now).
            if (disposed) return;
            api.markSaved(doc);
            api.toast(tr("Thumbnail saved"));
            props.onSaved?.(data);
        } catch (e) {
            trace("save_failed", { asset_id: props.assetId, status: /** @type {any} */ (e)?.response?.status ?? null });
            // The editor keeps the edits and reports this message; the same Save works again.
            throw new Error(message(e));
        }
    }

    (async () => {
        try {
            if (!http) throw new Error("window.axios is not available");
            const { data } = await http.get(props.thumbnailUrl, { responseType: "blob" });
            if (disposed) return;
            if (!(data instanceof Blob) || !EDITABLE_TYPES.includes(data.type)) throw new Error(tr("This thumbnail cannot be edited."));
            baseUrl = URL.createObjectURL(data);
            editor = await createEditor(host, {
                base: { id: "thumbnail", url: baseUrl },
                fileName: props.fileName || "",
                labels: props.labels,
                exportFormat: "png",
                onClose: () => props.onClose?.(),
                actions: [{ id: "thumbnail", label: tr("Save thumbnail"), primary: true, run: (api) => save(api) }],
            });
            if (disposed) editor.destroy();
            else status.remove();
        } catch (e) {
            if (disposed) return;
            host.querySelector(".pe-root")?.remove();
            if (!status.isConnected) host.append(status);
            status.replaceChildren(
                h("p", { role: "alert" }, message(e)),
                h("button", { type: "button", class: "pe-btn pe-text-btn", onclick: () => props.onClose?.() }, tr("Close")),
            );
            status.style.flexDirection = "column";
            status.style.gap = "10px";
        }
    })();

    return () => {
        disposed = true;
        editor?.destroy();
        host.remove();
        if (baseUrl) URL.revokeObjectURL(baseUrl);
        baseUrl = null;
    };
}
