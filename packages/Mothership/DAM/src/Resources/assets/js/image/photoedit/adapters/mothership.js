// @ts-check
/**
 * The Mothership adapter. Mirrors the contract the legacy Vue editor
 * uses (packages/Mothership/DAM/src/Resources/assets/js/image/Editor.vue):
 *
 *   GET  {endpoint}            → { document, revision, original_base, resources: {id: url} }
 *   POST {endpoint}/save       multipart: document (JSON), revision (uuid), mode ("version"|"new"),
 *                              render (PNG, or JPEG when the asset is a JPEG) → { asset_id, revision, version }
 *                              409 = a newer revision exists
 *   POST {endpoint}/resources  multipart: image → { id, url }
 *
 * `endpoint` contains the literal `__ASSET__`, replaced by `assetId`. HTTP goes through
 * the page's `window.axios` (which carries the app's CSRF header), as the legacy editor does.
 * The editing core knows nothing about any of this.
 */
import { createEditor } from "../ui/editor.js";
import { h } from "../ui/dom.js";
import { injectStyles } from "../ui/styles.js";

/**
 * @param {HTMLElement} element
 * @param {{assetId:number, fileName?:string, endpoint:string, labels?:Record<string,string>,
 *          onClose:()=>void, onSaved:(data:any)=>void, http?:any}} props
 * @returns {() => void} unmount
 */
export function mountEditor(element, props) {
    injectStyles();
    const tr = (t) => (props.labels && props.labels[t]) || t;
    const http = props.http || /** @type {any} */ (globalThis).axios;
    const api = props.endpoint.replace("__ASSET__", String(props.assetId));
    const fileName = props.fileName || "";
    const keepsJpeg = /\.jpe?g$/i.test(fileName);
    const convertsToPng = !keepsJpeg && !/\.png$/i.test(fileName);
    let disposed = false;
    /** @type {any} */
    let editor = null;
    let revision = "";
    // UPSTREAM-DEVIATION (The Mothership): disclose bounded server working copies throughout editing.
    let reducedSource = null;
    let resizeConfirmed = false;
    const notice = h("p", { class: "pe-source-notice", role: "status" });
    const disclosures = new Map();
    const disclose = (source, added = false) => {
        if (!source?.reduced) return;
        const dimensions = `${source.original_width} × ${source.original_height} → ${source.working_width} × ${source.working_height}`;
        disclosures.set(added ? "added" : "base", added
            ? `${tr("Added image uses a reduced working copy:")} ${dimensions}. ${tr("Export uses working dimensions.")}`
            : `${tr("Editing a reduced working copy:")} ${dimensions}. ${tr("The original file is preserved. Export uses working dimensions.")}`);
        notice.textContent = [...disclosures.values()].join(" ");
        host.prepend(notice);
    };

    const host = h("div", { class: "pe-host", style: { position: "relative", width: "100%", height: "100%" } });
    const status = h("div", { class: "pe-busy", role: "status" }, tr("Loading…"));
    host.append(status);
    element.append(host);

    const message = (e) => e?.response?.data?.message || e?.message || tr("Something went wrong.");

    async function save(api_, mode) {
        if (reducedSource && mode === "version" && !resizeConfirmed) {
            const ok = await api_.modal(tr("Save reduced-size version?"), [h("p", {}, `${tr("This version exports from the working copy:")} ${reducedSource.working_width} × ${reducedSource.working_height}. ${tr("The full-size original remains in version history.")}`)], [
                { label: tr("Cancel"), value: null },
                { label: tr("Save new version"), value: "ok", primary: true },
            ]);
            if (!ok) return;
            resizeConfirmed = true;
        }
        if (convertsToPng && mode === "version" && !save.warned) {
            const ok = await api_.modal(tr("Save as PNG?"), [h("p", {}, tr("This file type is saved as a PNG image."))], [
                { label: tr("Cancel"), value: null },
                { label: tr("Save"), value: "ok", primary: true },
            ]);
            if (!ok) return;
            save.warned = true;
        }
        const doc = api_.getDocument();
        const blob = await api_.exportBlob(keepsJpeg ? "image/jpeg" : "image/png");
        const form = new FormData();
        form.append("document", JSON.stringify(doc));
        form.append("revision", revision);
        form.append("mode", mode);
        form.append("render", blob, keepsJpeg ? "edited.jpg" : "edited.png");
        try {
            const { data } = await http.post(`${api}/save`, form);
            if (data?.revision && mode === "version") revision = data.revision;
            api_.markSaved(doc); // the submitted snapshot: edits made during the request stay unsaved
            api_.toast(mode === "new" ? tr("Saved as a new asset") : tr("Saved"));
            props.onSaved?.(data);
        } catch (e) {
            const err = /** @type {any} */ (e);
            throw new Error(err?.response?.status === 409
                ? tr("A newer version was saved elsewhere. Your edits remain here; save as a new asset or reopen to load the latest version.")
                : message(err));
        }
    }
    save.warned = false;

    (async () => {
        try {
            if (!http) throw new Error("window.axios is not available");
            const { data } = await http.get(api);
            if (disposed) return;
            revision = data.revision;
            reducedSource = data.source?.reduced ? data.source : null;
            editor = await createEditor(host, {
                document: data.document,
                resources: data.resources,
                fileName,
                labels: props.labels,
                exportFormat: keepsJpeg ? "jpeg" : "png",
                onClose: () => props.onClose?.(),
                uploadImage: async (file) => {
                    const form = new FormData();
                    form.append("image", file);
                    const { data: res } = await http.post(`${api}/resources`, form);
                    disclose(res.source, true);
                    return { id: res.id, url: res.url };
                },
                actions: [
                    { id: "new", label: "Save as new asset", run: (a) => save(a, "new") },
                    { id: "version", label: "Save new version", primary: true, run: (a) => save(a, "version") },
                ],
            });
            disclose(reducedSource);
            if (disposed) editor.destroy();
            else status.remove(); // only once the editor is up; on failure the status shows the error
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
    };
}
