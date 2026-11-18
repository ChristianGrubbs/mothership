<script setup>
import { ref, computed, onMounted, onBeforeUnmount, nextTick } from "vue";
import {
    cropFromDrag,
    createDocument,
    geometry,
    History,
    validateDocument,
    hitTest,
} from "./document.mjs";
import {
    createPainter,
    loadImage,
    imageBlob,
    pngBlob,
    releaseImages,
    renderDocument,
} from "./renderer.mjs";
const previewSize = ref(null);
const props = defineProps({
    assetId: Number,
    fileName: String,
    endpoint: String,
    labels: { type: Object, default: () => ({}) },
    onClose: Function,
    onSaved: Function,
    // A template's locked layout (P8′): no device upload, and the one save writes the template's draft.
    templateMode: { type: Boolean, default: false },
});
const tr = (text) => props.labels[text] || text;
const api = props.endpoint.replace("__ASSET__", props.assetId),
    http = window.axios;
const doc = ref(null),
    revision = ref(""),
    error = ref(""),
    busy = ref(false),
    tab = ref("edit"),
    canvas = ref(null),
    viewport = ref(null),
    dialog = ref(null),
    dirty = ref(false),
    zoom = ref(1),
    fit = ref(true),
    compare = ref(false),
    selected = ref(null),
    tool = ref("select"),
    color = ref("#e11d48"),
    size = ref(8),
    text = ref("Text"),
    textField = ref(null),
    lock = ref(true),
    closing = ref(false),
    presetName = ref(""),
    presets = ref([]),
    tick = ref(0),
    loading = ref(true);
const tabs = [
    ["edit", "Edit image"],
    ["annotate", "Annotate"],
    ["background", "Background"],
];
let history,
    initial = "",
    originalBase = "",
    resources = {},
    paintSequence = 0,
    drag = null,
    observer,
    lastFocus,
    savedGeneration = 0,
    disposed = false;
const cannotSave = computed(() => busy.value);
const keepsJpeg = computed(
    () => !props.templateMode && /\.jpe?g$/i.test(props.fileName || ""),
);
// Anything that is neither a JPEG nor a PNG is saved as a PNG; say so before the save.
const convertsToPng = computed(
    () =>
        !props.templateMode &&
        !keepsJpeg.value &&
        !/\.png$/i.test(props.fileName || ""),
);
const g = computed(() => {
    try {
        return doc.value ? geometry(doc.value) : { width: 1, height: 1 };
    } catch {
        return { width: 1, height: 1 };
    }
});
const selectedLayer = computed(() =>
    doc.value?.annotations.find((a) => a.id === selected.value),
);
const canUndo = computed(() => {
    tick.value;
    return !!history?.canUndo;
});
const canRedo = computed(() => {
    tick.value;
    return !!history?.canRedo;
});
const scale = computed(() => zoom.value);
const overlayStyle = (area) => ({
    left: area.x * scale.value + "px",
    top: area.y * scale.value + "px",
    width: area.width * scale.value + "px",
    height: area.height * scale.value + "px",
});
function resolve(id) {
    if (!resources[id])
        throw Error("This image resource is unavailable. Reopen the editor.");
    return resources[id];
}
function message(e) {
    return (
        e?.response?.data?.message ||
        e.message ||
        "The operation failed. Try again."
    );
}
// Save failures: show a sentence a person can act on, never the transport library's text
// ("Request failed with status code 500", "Network Error"). The edits are always still here.
function saveMessage(e) {
    const status = e?.response?.status;
    if (!e?.response)
        return e?.message && !/Network Error|timeout|aborted/i.test(e.message)
            ? e.message
            : "Could not reach the server, so nothing was saved. Your edits are still here; check the connection and try again.";
    if (status >= 500)
        return "The server could not save this just now. Your edits are still here; try again in a moment.";
    return e.response.data?.message || "The save was refused. Your edits are still here.";
}
function checkpoint() {
    dirty.value = JSON.stringify(doc.value) !== initial;
    try {
        validateDocument(doc.value);
        history.push(doc.value);
        tick.value++;
        dirty.value = JSON.stringify(doc.value) !== initial;
        error.value = "";
        paint();
    } catch (e) {
        error.value = message(e);
    }
}
function undo() {
    doc.value = history.undo();
    tick.value++;
    dirty.value = JSON.stringify(doc.value) !== initial;
    selected.value = null;
    paint();
}
function redo() {
    doc.value = history.redo();
    tick.value++;
    dirty.value = JSON.stringify(doc.value) !== initial;
    paint();
}
// Every input event asks for a repaint; the painter runs one full-resolution
// render at a time and folds a burst of requests into a single follow-up.
const paint = createPainter(paintNow);
async function paintNow() {
    if (!doc.value) return;
    const sequence = ++paintSequence;
    try {
        const rendering =
            tab.value === "edit" && tool.value === "crop"
                ? createDocument({
                      base: doc.value.base,
                      width: doc.value.sourceWidth,
                      height: doc.value.sourceHeight,
                  })
                : compare.value
                  ? createDocument({
                        base: originalBase,
                        width: doc.value.sourceWidth,
                        height: doc.value.sourceHeight,
                    })
                  : doc.value;
        if (compare.value) {
            const image = await loadImage(resolve(originalBase));
            rendering.sourceWidth =
                rendering.crop.width =
                rendering.resize.width =
                    image.width;
            rendering.sourceHeight =
                rendering.crop.height =
                rendering.resize.height =
                    image.height;
        }
        const out = await renderDocument({ doc: rendering, resolve });
        if (sequence !== paintSequence || disposed || !canvas.value) return;
        previewSize.value = { width: out.width, height: out.height };
        canvas.value.width = out.width;
        canvas.value.height = out.height;
        canvas.value.getContext("2d").drawImage(out, 0, 0);
        if (fit.value) fitImage();
    } catch (e) {
        if (sequence === paintSequence) error.value = message(e);
    }
}
function fitImage() {
    if (!canvas.value || !viewport.value) return;
    zoom.value = Math.min(
        1,
        (viewport.value.clientWidth - 64) / canvas.value.width,
        (viewport.value.clientHeight - 64) / canvas.value.height,
    );
}
function changeSize(axis) {
    if (lock.value) {
        const c = doc.value.crop;
        doc.value.resize[axis === "width" ? "height" : "width"] = Math.max(
            1,
            Math.round(
                doc.value.resize[axis] *
                    (axis === "width"
                        ? c.height / c.width
                        : c.width / c.height),
            ),
        );
    }
    checkpoint();
}
function cropChanged() {
    doc.value.resize = {
        width: doc.value.crop.width,
        height: doc.value.crop.height,
    };
    checkpoint();
}
function chooseTab(id) {
    tab.value = id;
    tool.value = "select";
    compare.value = false;
    paint();
}
function tabKeys(e, index) {
    if (["ArrowLeft", "ArrowRight", "Home", "End"].includes(e.key)) {
        e.preventDefault();
        const i =
            e.key === "Home"
                ? 0
                : e.key === "End"
                  ? tabs.length - 1
                  : (index + (e.key === "ArrowLeft" ? -1 : 1) + tabs.length) %
                    tabs.length;
        chooseTab(tabs[i][0]);
        dialog.value.querySelectorAll("[role=tab]")[i].focus();
    }
}
function point(e) {
    const box = canvas.value.getBoundingClientRect();
    return {
        x: Math.max(
            0,
            Math.min(
                canvas.value.width,
                ((e.clientX - box.left) * canvas.value.width) / box.width,
            ),
        ),
        y: Math.max(
            0,
            Math.min(
                canvas.value.height,
                ((e.clientY - box.top) * canvas.value.height) / box.height,
            ),
        ),
    };
}
function pointerDown(e) {
    if (compare.value || e.button !== 0) return;
    const p = point(e);
    e.currentTarget.setPointerCapture(e.pointerId);
    if (tab.value === "edit" && tool.value === "crop") {
        drag = {
            kind: "crop",
            start: p,
            ratio: lock.value
                ? doc.value.crop.width / doc.value.crop.height
                : null,
        };
        doc.value.crop = { x: p.x, y: p.y, width: 1, height: 1 };
        return;
    }
    if (tab.value !== "annotate") return;
    if (tool.value === "select") {
        const a = hitTest(doc.value.annotations, p.x, p.y);
        selected.value = a?.id || null;
        if (a) drag = { kind: "move", start: p, x: a.x, y: a.y };
        return;
    }
    const a = {
        id: crypto.randomUUID(),
        type: tool.value,
        x: p.x,
        y: p.y,
        width: 0,
        height: 0,
        color: color.value,
        size: size.value,
        text: text.value,
    };
    if (a.type === "text") {
        a.width = Math.max(40, text.value.length * size.value * 0.65);
        a.height = size.value * 1.3;
    }
    if (a.type === "step") {
        a.width = a.height = 40;
        a.size = 22;
        a.text = String(
            doc.value.annotations.filter((l) => l.type === "step").length + 1,
        );
    }
    if (a.type === "drawing") a.points = [[0, 0]];
    doc.value.annotations.push(a);
    selected.value = a.id;
    drag = { kind: "draw", start: p };
    paint();
}
// Text is typed in the side panel's Text field, so a placed text layer hands the keyboard to it.
function focusTextField() {
    const a = selectedLayer.value;
    if (!a || !["text", "step"].includes(a.type)) return;
    nextTick(() => {
        textField.value?.focus();
        textField.value?.select();
    });
}
function resizeTextLayer(a) {
    if (a?.type !== "text") return;
    a.width = Math.max(40, (a.text || "").length * (a.size || 8) * 0.65);
    a.height = (a.size || 8) * 1.3;
}
function pointerMove(e) {
    if (!drag) return;
    const p = point(e),
        a = selectedLayer.value,
        s = drag.start;
    if (drag.kind === "crop") {
        doc.value.crop = cropFromDrag({
            start: s,
            end: p,
            source: {
                width: doc.value.sourceWidth,
                height: doc.value.sourceHeight,
            },
            ratio: drag.ratio,
        });
        return;
    }
    if (!a) return;
    if (drag.kind === "move") {
        a.x = Math.max(
            0,
            Math.min(g.value.width - a.width, drag.x + p.x - s.x),
        );
        a.y = Math.max(
            0,
            Math.min(g.value.height - a.height, drag.y + p.y - s.y),
        );
    } else if (a.type === "drawing") {
        a.points.push([p.x - a.x, p.y - a.y]);
        a.width = Math.max(a.width, p.x - a.x);
        a.height = Math.max(a.height, p.y - a.y);
    } else if (!["text", "step"].includes(a.type)) {
        a.x = Math.min(p.x, s.x);
        a.y = Math.min(p.y, s.y);
        a.width = Math.abs(p.x - s.x);
        a.height = Math.abs(p.y - s.y);
        a.reverseX = p.x < s.x;
        a.reverseY = p.y < s.y;
    }
    paint();
}
function pointerUp() {
    if (!drag) return;
    if (drag.kind === "crop") cropChanged();
    else {
        const a = selectedLayer.value;
        if (a?.type === "drawing") {
            const xs = a.points.map((p) => p[0]),
                ys = a.points.map((p) => p[1]),
                minX = Math.min(...xs),
                minY = Math.min(...ys);
            a.x += minX;
            a.y += minY;
            a.width = Math.max(...xs) - minX;
            a.height = Math.max(...ys) - minY;
            a.points = a.points.map(([x, y]) => [x - minX, y - minY]);
        }
        checkpoint();
        if (drag.kind === "draw") setTimeout(focusTextField, 0);
    }
    drag = null;
}
function removeLayer() {
    doc.value.annotations = doc.value.annotations.filter(
        (a) => a.id !== selected.value,
    );
    selected.value = null;
    checkpoint();
}
async function upload(e) {
    const file = e.target.files[0];
    if (!file) return;
    busy.value = true;
    try {
        const form = new FormData();
        form.append("image", file);
        const { data } = await http.post(api + "/resources", form);
        resources[data.id] = data.url;
        doc.value.background.image = data.id;
        doc.value.background.enabled = true;
        doc.value.background.type = "upload";
        checkpoint();
    } catch (e) {
        error.value = message(e);
    } finally {
        busy.value = false;
        e.target.value = "";
    }
}
function applyPreset(p) {
    doc.value.background = JSON.parse(JSON.stringify(p.background));
    checkpoint();
}
function storePreset() {
    if (!presetName.value.trim()) return;
    const b = { ...doc.value.background, image: null };
    presets.value.push({
        name: presetName.value.trim().slice(0, 80),
        background: b,
    });
    try {
        localStorage.setItem(
            "mothership.imageEditor.presets.v1",
            JSON.stringify(presets.value),
        );
        presetName.value = "";
    } catch {
        error.value = "Browser storage is full. Preset could not be saved.";
    }
}
async function save(mode) {
    if (cannotSave.value) return;
    busy.value = true;
    error.value = "";
    try {
        validateDocument(doc.value);
        const out = await renderDocument({ doc: doc.value, resolve }),
            form = new FormData();
        form.append("document", JSON.stringify(doc.value));
        form.append("revision", revision.value);
        form.append("mode", mode);
        // A JPEG stays a JPEG (smaller, same type, same file name); everything else is saved as PNG.
        const blob = keepsJpeg.value
            ? await imageBlob(out, "image/jpeg", 0.92)
            : await pngBlob(out);
        form.append("render", blob, keepsJpeg.value ? "edited.jpg" : "edited.png");
        const { data } = await http.post(api + "/save", form);
        initial = JSON.stringify(doc.value);
        dirty.value = false;
        savedGeneration++;
        props.onSaved(data);
    } catch (e) {
        error.value =
            e?.response?.status === 409
                ? props.templateMode
                    ? tr("A newer layout was saved elsewhere. Your edits remain here; reopen the editor to load it.")
                    : "A newer version was saved elsewhere. Your edits remain here; save as a new asset or reopen to load the latest version."
                : saveMessage(e);
    } finally {
        busy.value = false;
    }
}
function requestClose() {
    if (busy.value) return;
    if (dirty.value) {
        closing.value = true;
        nextTick(() => dialog.value.querySelector("[data-stay]")?.focus());
    } else props.onClose();
}
function confirmClose() {
    props.onClose();
}
function keys(e) {
    if (e.key === "Escape") {
        e.preventDefault();
        e.stopImmediatePropagation();
        // Escape first ends text entry; a second Escape asks about discarding.
        if (!closing.value && e.target === textField.value) {
            textField.value.blur();
            return;
        }
        if (closing.value) closing.value = false;
        else requestClose();
        return;
    }
    if (e.key === "Tab") {
        const focusRoot = closing.value
            ? dialog.value.querySelector(".ag-confirm")
            : dialog.value;
        const nodes = [
            ...focusRoot.querySelectorAll(
                'button:not(:disabled), input:not(:disabled),textarea,select,[tabindex="0"]',
            ),
        ].filter((x) => x.offsetParent !== null);
        if (!nodes.length) return;
        const first = nodes[0],
            last = nodes.at(-1);
        if (e.shiftKey && document.activeElement === first) {
            e.preventDefault();
            last.focus();
        } else if (!e.shiftKey && document.activeElement === last) {
            e.preventDefault();
            first.focus();
        }
    }
    if (/INPUT|TEXTAREA|SELECT/.test(e.target.tagName)) return;
    if ((e.metaKey || e.ctrlKey) && e.key === "z") {
        e.preventDefault();
        e.shiftKey ? redo() : undo();
    }
    if (e.key === "Delete" || e.key === "Backspace") {
        if (selected.value) {
            e.preventDefault();
            removeLayer();
        }
    }
    if (
        selectedLayer.value &&
        ["ArrowLeft", "ArrowRight", "ArrowUp", "ArrowDown"].includes(e.key)
    ) {
        e.preventDefault();
        const a = selectedLayer.value,
            delta = e.shiftKey ? 10 : 1;
        if (e.key === "ArrowLeft") a.x -= delta;
        if (e.key === "ArrowRight") a.x += delta;
        if (e.key === "ArrowUp") a.y -= delta;
        if (e.key === "ArrowDown") a.y += delta;
        checkpoint();
    }
}
function unload(e) {
    if (dirty.value) {
        e.preventDefault();
        e.returnValue = "";
    }
}
onMounted(async () => {
    lastFocus = document.activeElement;
    document.addEventListener("keydown", keys, true);
    window.addEventListener("beforeunload", unload);
    try {
        presets.value = JSON.parse(
            localStorage.getItem("mothership.imageEditor.presets.v1") || "[]",
        );
    } catch {}
    try {
        const { data } = await http.get(api);
        doc.value = data.document;
        revision.value = data.revision;
        resources = data.resources;
        originalBase = data.original_base;
        initial = JSON.stringify(doc.value);
        history = new History(doc.value);
        dirty.value = JSON.stringify(doc.value) !== initial;
        loading.value = false;
        await nextTick();
        observer = new ResizeObserver(() => {
            if (fit.value) fitImage();
        });
        observer.observe(viewport.value);
        await paint();
        dialog.value.querySelector("button")?.focus();
    } catch (e) {
        error.value = message(e);
        loading.value = false;
    }
});
onBeforeUnmount(() => {
    disposed = true;
    observer?.disconnect();
    document.removeEventListener("keydown", keys, true);
    window.removeEventListener("beforeunload", unload);
    releaseImages();
    lastFocus?.focus();
});
</script>
<template>
    <Teleport to="body">
        <div class="ag-editor-backdrop" @click.self="requestClose">
            <section
                ref="dialog"
                class="ag-editor"
                role="dialog"
                aria-modal="true"
                aria-labelledby="ag-editor-title"
            >
                <header>
                    <div>
                        <h1 id="ag-editor-title">{{ tr("Image editor") }}</h1>
                        <p>{{ fileName }}</p>
                    </div>
                    <span class="ag-status">{{
                        dirty ? "Unsaved changes" : "Original preserved"
                    }}</span
                    ><button
                        type="button"
                        :aria-label="tr('Close image editor')"
                        @click="requestClose"
                    >
                        ✕
                    </button>
                </header>
                <nav role="tablist" :aria-label="tr('Image editing tools')">
                    <button
                        type="button"
                        v-for="([id, label], i) in tabs"
                        :id="'ag-tab-' + id"
                        :key="id"
                        role="tab"
                        :aria-selected="tab === id"
                        :aria-controls="'ag-panel-' + id"
                        :tabindex="tab === id ? 0 : -1"
                        @keydown="tabKeys($event, i)"
                        @click="chooseTab(id)"
                    >
                        {{ tr(label) }}
                    </button>
                </nav>
                <div v-if="error" class="ag-error" role="alert">
                    {{ error }}
                </div>
                <div v-if="loading" class="ag-loading" role="status">
                    {{ tr("Loading editable image…") }}
                </div>
                <main v-if="doc">
                    <div class="ag-workspace">
                        <div ref="viewport" class="ag-viewport">
                            <div
                                class="ag-canvas-wrap"
                                :style="{
                                    width:
                                        (previewSize?.width || g.width) * zoom +
                                        'px',
                                    height:
                                        (previewSize?.height || g.height) *
                                            zoom +
                                        'px',
                                }"
                                @pointerdown="pointerDown"
                                @pointermove="pointerMove"
                                @pointerup="pointerUp"
                                @pointercancel="pointerUp"
                            >
                                <canvas
                                    ref="canvas"
                                    :style="{ width: '100%', height: '100%' }"
                                    :aria-label="
                                        tr(
                                            'Image preview. Draw annotations here.',
                                        )
                                    "
                                />
                                <div
                                    v-if="tab === 'edit' && tool === 'crop'"
                                    class="ag-selection"
                                    :style="overlayStyle(doc.crop)"
                                ></div>
                                <div
                                    v-if="
                                        selectedLayer &&
                                        !compare &&
                                        tool !== 'crop'
                                    "
                                    class="ag-selection"
                                    :style="overlayStyle(selectedLayer)"
                                ></div>
                            </div>
                        </div>
                        <div class="ag-previewbar">
                            <div>
                                <button
                                    type="button"
                                    :disabled="!canUndo"
                                    :aria-label="tr('Undo')"
                                    @click="undo"
                                >
                                    ↶</button
                                ><button
                                    type="button"
                                    :disabled="!canRedo"
                                    :aria-label="tr('Redo')"
                                    @click="redo"
                                >
                                    ↷
                                </button>
                            </div>
                            <div>
                                <button
                                    type="button"
                                    :aria-pressed="compare"
                                    @click="
                                        compare = !compare;
                                        paint();
                                    "
                                >
                                    {{
                                        compare
                                            ? "Original"
                                            : "Compare original"
                                    }}</button
                                >
                            </div>
                            <div>
                                <button
                                    type="button"
                                    @click="
                                        fit = true;
                                        fitImage();
                                    "
                                >
                                    {{ tr("Fit") }}</button
                                ><button
                                    type="button"
                                    :aria-label="tr('Zoom out')"
                                    @click="
                                        fit = false;
                                        zoom = Math.max(0.05, zoom / 1.25);
                                    "
                                >
                                    −</button
                                ><span>{{ Math.round(zoom * 100) }}%</span
                                ><button
                                    type="button"
                                    :aria-label="tr('Zoom in')"
                                    @click="
                                        fit = false;
                                        zoom = Math.min(4, zoom * 1.25);
                                    "
                                >
                                    +
                                </button>
                            </div>
                        </div>
                    </div>
                    <aside
                        :id="'ag-panel-' + tab"
                        role="tabpanel"
                        :aria-labelledby="'ag-tab-' + tab"
                        :aria-busy="busy"
                    >
                        <fieldset :disabled="busy">
                            <template v-if="tab === 'edit'"
                                ><h2>{{ tr("Edit image") }}</h2>
                                <p class="ag-help">
                                    {{
                                        tr(
                                            "Adjust the image, then save a new version.",
                                        )
                                    }}
                                </p>
                                <h3>{{ tr("Crop") }}</h3>
                                <label class="ag-check"
                                    ><input type="checkbox" v-model="lock" />{{
                                        tr("Lock crop aspect ratio")
                                    }}</label
                                >
                                <button
                                    type="button"
                                    :aria-pressed="tool === 'crop'"
                                    @click="
                                        tool =
                                            tool === 'crop' ? 'select' : 'crop';
                                        compare = false;
                                        paint();
                                    "
                                >
                                    {{
                                        tool === "crop"
                                            ? "Finish crop"
                                            : "Draw crop on image"
                                    }}
                                </button>
                                <div class="ag-grid">
                                    <label
                                        v-for="key in [
                                            'x',
                                            'y',
                                            'width',
                                            'height',
                                        ]"
                                        :key="key"
                                        >{{ key
                                        }}<input
                                            type="number"
                                            v-model.number="doc.crop[key]"
                                            :min="
                                                key === 'x' || key === 'y'
                                                    ? 0
                                                    : 1
                                            "
                                            :max="
                                                key === 'x' || key === 'width'
                                                    ? doc.sourceWidth
                                                    : doc.sourceHeight
                                            "
                                            @change="cropChanged"
                                    /></label>
                                </div>
                                <h3>{{ tr("Resize") }}</h3>
                                <div class="ag-grid">
                                    <label
                                        v-for="key in ['width', 'height']"
                                        :key="key"
                                        >{{ key
                                        }}<input
                                            type="number"
                                            min="1"
                                            max="8192"
                                            v-model.number="doc.resize[key]"
                                            @change="changeSize(key)"
                                    /></label>
                                </div>
                                <label class="ag-check"
                                    ><input type="checkbox" v-model="lock" />{{
                                        tr("Lock aspect ratio")
                                    }}</label
                                >
                                <div class="ag-grid">
                                    <button
                                        type="button"
                                        @click="
                                            doc.rotation =
                                                (doc.rotation + 270) % 360;
                                            checkpoint();
                                        "
                                    >
                                        {{ tr("Rotate left") }}</button
                                    ><button
                                        type="button"
                                        @click="
                                            doc.rotation =
                                                (doc.rotation + 90) % 360;
                                            checkpoint();
                                        "
                                    >
                                        {{ tr("Rotate right") }}</button
                                    ><button
                                        type="button"
                                        :aria-pressed="doc.flipX"
                                        @click="
                                            doc.flipX = !doc.flipX;
                                            checkpoint();
                                        "
                                    >
                                        {{ tr("Flip horizontal") }}</button
                                    ><button
                                        type="button"
                                        :aria-pressed="doc.flipY"
                                        @click="
                                            doc.flipY = !doc.flipY;
                                            checkpoint();
                                        "
                                    >
                                        {{ tr("Flip vertical") }}
                                    </button>
                                </div>
                                <h3>{{ tr("Adjustments") }}</h3>
                                <label
                                    v-for="key in [
                                        'brightness',
                                        'contrast',
                                        'sharpen',
                                        'blur',
                                    ]"
                                    :key="key"
                                    >{{ tr(key) }}
                                    <output>{{ doc.adjustments[key] }}</output
                                    ><input
                                        type="range"
                                        :min="
                                            ['brightness', 'contrast'].includes(
                                                key,
                                            )
                                                ? -100
                                                : 0
                                        "
                                        :max="key === 'blur' ? 30 : 100"
                                        v-model.number="doc.adjustments[key]"
                                        @input="paint"
                                        @change="checkpoint" /></label
                                ><label
                                    v-for="key in ['grayscale', 'invert']"
                                    :key="key"
                                    class="ag-check"
                                    ><input
                                        type="checkbox"
                                        v-model="doc.adjustments[key]"
                                        @change="checkpoint"
                                    />{{ tr(key) }}</label
                                ></template
                            >
                            <template v-if="tab === 'annotate'"
                                ><h2>{{ tr("Annotate") }}</h2>
                                <p class="ag-help">
                                    {{
                                        tr(
                                            "Choose a tool and drag on the image. Select a layer to move or edit it.",
                                        )
                                    }}
                                </p>
                                <div class="ag-grid">
                                    <button
                                        type="button"
                                        v-for="t in [
                                            'select',
                                            'arrow',
                                            'text',
                                            'rectangle',
                                            'highlight',
                                            'step',
                                            'blur',
                                            'pixelate',
                                            'drawing',
                                        ]"
                                        :key="t"
                                        :aria-pressed="tool === t"
                                        @click="tool = t"
                                    >
                                        {{ tr(t) }}
                                    </button>
                                </div>
                                <label
                                    >{{ tr("Color")
                                    }}<input
                                        type="color"
                                        v-model="color"
                                        @change="
                                            selectedLayer &&
                                                (selectedLayer.color = color);
                                            checkpoint();
                                        " /></label
                                ><label
                                    >{{ tr("Size") }}<output>{{ size }}</output
                                    ><input
                                        type="range"
                                        min="2"
                                        max="100"
                                        v-model.number="size"
                                        @change="
                                            selectedLayer &&
                                                (selectedLayer.size = size);
                                            checkpoint();
                                        " /></label
                                ><label
                                    >{{ tr("Text")
                                    }}<textarea
                                        ref="textField"
                                        v-model="text"
                                        @input="
                                            selectedLayer &&
                                                ['text', 'step'].includes(
                                                    selectedLayer.type,
                                                ) &&
                                                (selectedLayer.text = text);
                                            resizeTextLayer(selectedLayer);
                                            paint();
                                        "
                                        @change="checkpoint"
                                    /></label
                                ><template v-if="selectedLayer"
                                    ><h3>Selected {{ selectedLayer.type }}</h3>
                                    <div class="ag-grid">
                                        <label
                                            v-for="key in [
                                                'x',
                                                'y',
                                                'width',
                                                'height',
                                            ]"
                                            :key="key"
                                            >{{ key
                                            }}<input
                                                type="number"
                                                min="0"
                                                v-model.number="
                                                    selectedLayer[key]
                                                "
                                                @change="checkpoint"
                                        /></label>
                                    </div>
                                    <label
                                        v-if="
                                            ['text', 'step'].includes(
                                                selectedLayer.type,
                                            )
                                        "
                                        >{{ tr("Layer text")
                                        }}<textarea
                                            v-model="selectedLayer.text"
                                            @change="checkpoint"
                                        /></label
                                    ><button
                                        type="button"
                                        class="ag-danger"
                                        @click="removeLayer"
                                    >
                                        {{ tr("Delete selected layer") }}
                                    </button></template
                                >
                                <h3>{{ tr("Layers") }}</h3>
                                <button
                                    type="button"
                                    v-for="(a, i) in doc.annotations"
                                    :key="a.id"
                                    class="ag-layer"
                                    :aria-pressed="selected === a.id"
                                    @click="
                                        selected = a.id;
                                        tool = 'select';
                                    "
                                >
                                    {{ i + 1 }} · {{ a.type }}
                                    {{ a.text?.slice(0, 20) }}
                                </button></template
                            >
                            <template v-if="tab === 'background'"
                                ><h2>{{ tr("Background") }}</h2>
                                <p class="ag-help">
                                    {{
                                        tr(
                                            "Frame the image with a canvas, spacing and finish.",
                                        )
                                    }}
                                </p>
                                <label class="ag-check"
                                    ><input
                                        type="checkbox"
                                        v-model="doc.background.enabled"
                                        @change="checkpoint"
                                    />{{ tr("Add background canvas") }}</label
                                >
                                <div class="ag-grid ag-three">
                                    <button
                                        type="button"
                                        v-for="t in templateMode
                                            ? ['solid', 'gradient']
                                            : ['solid', 'gradient', 'upload']"
                                        :key="t"
                                        :aria-pressed="
                                            doc.background.type === t
                                        "
                                        @click="
                                            doc.background.type = t;
                                            doc.background.enabled = true;
                                            checkpoint();
                                        "
                                    >
                                        {{ tr(t) }}
                                    </button>
                                </div>
                                <label
                                    >{{ tr("Color")
                                    }}<input
                                        type="color"
                                        v-model="doc.background.color"
                                        @change="checkpoint" /></label
                                ><template
                                    v-if="doc.background.type === 'gradient'"
                                    ><label
                                        >{{ tr("Second color")
                                        }}<input
                                            type="color"
                                            v-model="doc.background.color2"
                                            @change="checkpoint" /></label
                                    ><label
                                        >Angle {{ doc.background.angle }}°<input
                                            type="range"
                                            min="0"
                                            max="360"
                                            v-model.number="
                                                doc.background.angle
                                            "
                                            @change="
                                                checkpoint
                                            " /></label></template
                                ><label v-if="doc.background.type === 'upload' && !templateMode"
                                    >{{ tr("Upload background")
                                    }}<input
                                        type="file"
                                        accept="image/png,image/jpeg,image/webp"
                                        @change="upload" /></label
                                ><label
                                    >{{ tr("Canvas ratio")
                                    }}<select
                                        :value="
                                            doc.background.ratio?.join(':') ||
                                            'auto'
                                        "
                                        @change="
                                            doc.background.ratio =
                                                $event.target.value === 'auto'
                                                    ? null
                                                    : $event.target.value ===
                                                        'custom'
                                                      ? [16, 10]
                                                      : $event.target.value
                                                            .split(':')
                                                            .map(Number);
                                            doc.background.enabled = true;
                                            checkpoint();
                                        "
                                    >
                                        <option value="auto">
                                            {{ tr("Auto — fit image") }}
                                        </option>
                                        <option value="1:1">
                                            {{ tr("Square 1:1") }}
                                        </option>
                                        <option value="4:5">
                                            {{ tr("Portrait 4:5") }}
                                        </option>
                                        <option value="16:9">
                                            {{ tr("Landscape 16:9") }}
                                        </option>
                                        <option value="9:16">
                                            {{ tr("Story 9:16") }}
                                        </option>
                                        <option value="custom">
                                            {{ tr("Custom…") }}
                                        </option>
                                    </select></label
                                >
                                <div
                                    v-if="doc.background.ratio"
                                    class="ag-grid"
                                >
                                    <label
                                        >{{ tr("Ratio width")
                                        }}<input
                                            type="number"
                                            min="0.01"
                                            max="10000"
                                            step="any"
                                            v-model.number="
                                                doc.background.ratio[0]
                                            "
                                            @change="checkpoint" /></label
                                    ><label
                                        >{{ tr("Ratio height")
                                        }}<input
                                            type="number"
                                            min="0.01"
                                            max="10000"
                                            step="any"
                                            v-model.number="
                                                doc.background.ratio[1]
                                            "
                                            @change="checkpoint"
                                    /></label>
                                </div>
                                <label
                                    v-for="[key, label, max, min] in [
                                        ['padding', 'Padding', 500, 0],
                                        ['scale', 'Image scale', 100, 1],
                                        ['radius', 'Rounded corners', 200, 0],
                                        ['shadow', 'Shadow', 100, 0],
                                    ]"
                                    :key="key"
                                    >{{ tr(label) }}
                                    <output>{{ doc.background[key] }}</output
                                    ><input
                                        type="range"
                                        :min="min"
                                        :max="max"
                                        v-model.number="doc.background[key]"
                                        @input="paint"
                                        @change="checkpoint"
                                /></label>
                                <h3>{{ tr("Alignment") }}</h3>
                                <div class="ag-alignment">
                                    <template
                                        v-for="(y, yi) in [0, 0.5, 1]"
                                        :key="y"
                                        ><button
                                            type="button"
                                            v-for="(x, xi) in [0, 0.5, 1]"
                                            :key="x"
                                            :aria-label="
                                                'Align ' +
                                                ['top', 'middle', 'bottom'][
                                                    yi
                                                ] +
                                                ' ' +
                                                ['left', 'center', 'right'][xi]
                                            "
                                            :aria-pressed="
                                                doc.background.align[0] === x &&
                                                doc.background.align[1] === y
                                            "
                                            @click="
                                                doc.background.align = [x, y];
                                                checkpoint();
                                            "
                                        >
                                            •
                                        </button></template
                                    >
                                </div>
                                <details>
                                    <summary>
                                        {{ tr("Reusable presets") }}
                                    </summary>
                                    <label
                                        >{{ tr("Preset name")
                                        }}<input
                                            v-model="presetName"
                                            maxlength="80" /></label
                                    ><button type="button" @click="storePreset">
                                        {{ tr("Save preset") }}</button
                                    ><button
                                        type="button"
                                        v-for="p in presets"
                                        :key="p.name"
                                        class="ag-layer"
                                        @click="applyPreset(p)"
                                    >
                                        {{ p.name }}
                                    </button>
                                    <p class="ag-help">
                                        {{
                                            tr(
                                                "Saved in this browser. Uploaded images stay with their asset.",
                                            )
                                        }}
                                    </p>
                                </details>
                                </template
                            >
                        </fieldset>
                    </aside>
                </main>
                <footer>
                    <span
                        >{{ previewSize?.width || g.width }} ×
                        {{ previewSize?.height || g.height }} px</span
                    >
                    <div>
                        <button
                            type="button"
                            :disabled="busy"
                            @click="requestClose"
                        >
                            {{ tr("Cancel") }}</button
                        ><button
                            v-if="!templateMode"
                            type="button"
                            :disabled="!doc || cannotSave"
                            @click="save('new')"
                        >
                            {{ tr("Save as new asset") }}</button
                        ><button
                            type="button"
                            class="ag-primary"
                            :disabled="!doc || cannotSave"
                            @click="save('version')"
                        >
                            {{
                                busy
                                    ? "Working…"
                                    : templateMode
                                      ? tr("Save layout")
                                      : "Save new version"
                            }}
                        </button>
                        <span
                            v-if="convertsToPng"
                            class="ag-format-note"
                            data-format-note
                            >{{ tr("Saved as PNG; the file type changes.") }}</span
                        >
                    </div>
                </footer>
                <div
                    v-if="closing"
                    class="ag-confirm"
                    role="alertdialog"
                    aria-modal="true"
                    :aria-label="tr('Discard unsaved changes?')"
                >
                    <div>
                        <h2>{{ tr("Discard unsaved changes?") }}</h2>
                        <p>
                            {{
                                tr(
                                    "Your working edits will be discarded.",
                                )
                            }}
                        </p>
                        <button
                            type="button"
                            data-stay
                            @click="closing = false"
                        >
                            {{ tr("Keep editing") }}</button
                        ><button
                            type="button"
                            class="ag-danger"
                            @click="confirmClose"
                        >
                            {{ tr("Discard and close") }}
                        </button>
                    </div>
                </div>
            </section>
        </div>
    </Teleport>
</template>
<style>
.ag-editor,
.ag-editor * {
    box-sizing: border-box;
}
.ag-editor-backdrop {
    position: fixed;
    inset: 0;
    z-index: 10030;
    background: #000a;
    display: grid;
    place-items: center;
    color: #20232d;
    font-size: 14px;
    line-height: 1.5;
}
.ag-editor {
    --surface: #fff;
    --subtle: #f8f9fc;
    --line: #e1e4eb;
    --ink: #252936;
    --muted: #707787;
    --accent: #7c3aed;
    position: relative;
    display: flex;
    flex-direction: column;
    width: min(1340px, 96vw);
    height: 94vh;
    max-height: 1000px;
    background: var(--surface);
    color: var(--ink);
    border: 1px solid var(--line);
    border-radius: 16px;
    box-shadow: 0 24px 90px #0006;
    overflow: hidden;
}
.dark .ag-editor {
    --surface: #171b25;
    --subtle: #10141e;
    --line: #343a4a;
    --ink: #f0f1f7;
    --muted: #a1a9bb;
    --accent: #a78bfa;
}
.ag-editor header,
.ag-editor footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    padding: 16px 22px;
    flex-shrink: 0;
}
.ag-editor header {
    border-bottom: 1px solid var(--line);
}
.ag-editor h1 {
    font-size: 17px;
    font-weight: 650;
    margin: 0;
}
.ag-editor h2 {
    font-size: 19px;
    font-weight: 650;
    margin: 0 0 8px;
}
.ag-editor h3 {
    font-size: 13px;
    font-weight: 600;
    margin: 22px 0 10px;
}
.ag-editor header p {
    font-size: 12px;
    color: var(--muted);
    margin: 0;
    max-width: 55vw;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.ag-status {
    color: var(--muted);
    font-size: 12px;
    margin-left: auto;
}
.ag-editor button {
    border: 1px solid var(--line);
    background: var(--surface);
    padding: 8px 11px;
    border-radius: 7px;
    color: inherit;
    cursor: pointer;
    font-size: 12px;
    min-height: 34px;
    text-transform: none;
}
.ag-editor button:hover {
    background: var(--subtle);
}
.ag-editor button:disabled {
    opacity: 0.45;
    cursor: not-allowed;
}
.ag-editor button:focus-visible,
.ag-editor input:focus-visible,
.ag-editor textarea:focus-visible,
.ag-editor select:focus-visible {
    outline: 3px solid var(--accent);
    outline-offset: 2px;
}
.ag-editor button[aria-pressed="true"] {
    border-color: var(--accent);
    color: var(--accent);
    background: color-mix(in srgb, var(--accent) 8%, var(--surface));
}
.ag-editor nav {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    border-bottom: 1px solid var(--line);
    flex-shrink: 0;
}
.ag-editor nav button {
    border: none;
    border-bottom: 3px solid transparent;
    border-radius: 0;
    padding: 14px 8px;
    font-size: 13px;
}
.ag-editor nav button[aria-selected="true"] {
    color: var(--accent);
    border-bottom-color: var(--accent);
    font-weight: 650;
}
.ag-editor main {
    display: flex;
    min-height: 0;
    flex: 1;
}
.ag-workspace {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    background: var(--subtle);
}
.ag-viewport {
    flex: 1;
    min-height: 0;
    display: flex;
    overflow: auto;
    align-items: safe center;
    justify-content: safe center;
    padding: 32px;
    background-image: radial-gradient(var(--line) 1px, transparent 1px);
    background-size: 12px 12px;
}
.ag-canvas-wrap {
    position: relative;
    flex-shrink: 0;
    touch-action: none;
    box-shadow: 0 8px 26px #0002;
    line-height: 0;
}
.ag-canvas-wrap canvas {
    display: block;
}
.ag-selection {
    position: absolute;
    border: 2px dashed #7c3aed;
    pointer-events: none;
}
.ag-previewbar {
    display: flex;
    justify-content: space-between;
    gap: 8px;
    align-items: center;
    padding: 12px;
    border-top: 1px solid var(--line);
    background: var(--surface);
    flex-wrap: wrap;
}
.ag-previewbar > div {
    display: flex;
    gap: 5px;
    align-items: center;
}
.ag-previewbar span {
    font-size: 12px;
    min-width: 36px;
    text-align: center;
}
.ag-editor aside {
    width: 330px;
    flex-shrink: 0;
    padding: 24px;
    overflow: auto;
    border-left: 1px solid var(--line);
}
.ag-editor fieldset {
    border: 0;
    padding: 0;
    margin: 0;
    min-width: 0;
}
.ag-help {
    font-size: 12px;
    color: var(--muted);
    margin: 6px 0 18px;
}
.ag-editor label {
    display: block;
    font-size: 12px;
    margin: 14px 0 8px;
    text-transform: none;
}
.ag-editor input:not([type="checkbox"]):not([type="range"]):not([type="color"]),
.ag-editor textarea,
.ag-editor select {
    display: block;
    width: 100%;
    border: 1px solid var(--line);
    border-radius: 6px;
    padding: 8px;
    background: var(--surface);
    color: var(--ink);
    font-size: 13px;
    margin-top: 5px;
}
.ag-editor textarea {
    min-height: 90px;
    resize: vertical;
}
.ag-editor input[type="range"] {
    display: block;
    width: 100%;
    accent-color: var(--accent);
    margin-top: 8px;
}
.ag-editor input[type="color"] {
    display: block;
    width: 100%;
    height: 36px;
    border: 1px solid var(--line);
    padding: 4px;
    border-radius: 6px;
    margin-top: 6px;
    background: var(--surface);
}
.ag-editor output {
    float: right;
    color: var(--muted);
}
.ag-editor .ag-check {
    display: flex;
    gap: 8px;
    align-items: center;
}
.ag-check input {
    accent-color: var(--accent);
}
.ag-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 8px;
    margin-bottom: 10px;
}
.ag-grid label {
    margin: 0;
}
.ag-three {
    grid-template-columns: repeat(3, 1fr);
}
.ag-alignment {
    display: grid;
    grid-template-columns: repeat(3, 36px);
    gap: 4px;
    margin-bottom: 18px;
}
.ag-editor .ag-layer,
.ag-full {
    width: 100%;
    margin: 5px 0;
}
.ag-editor .ag-primary {
    background: var(--accent);
    color: #fff;
    border-color: transparent;
}
.dark .ag-editor .ag-primary {
    color: #151020;
}
.ag-editor .ag-danger {
    color: #dc2626;
}
.ag-editor footer {
    border-top: 1px solid var(--line);
    font-size: 12px;
}
.ag-editor footer > div {
    display: flex;
    gap: 8px;
}
.ag-format-note {
    align-self: center;
    font-size: 12px;
    opacity: 0.8;
}
.ag-error {
    padding: 10px 22px;
    background: #fee2e2;
    color: #991b1b;
    font-size: 13px;
    flex-shrink: 0;
}
.ag-loading {
    padding: 48px;
    flex: 1;
}
.ag-confirm {
    position: absolute;
    inset: 0;
    background: #0009;
    display: grid;
    place-items: center;
    z-index: 2;
}
.ag-confirm > div {
    padding: 28px;
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: 12px;
    max-width: 430px;
}
.ag-confirm p {
    margin: 12px 0 22px;
}
.ag-confirm button {
    margin-right: 8px;
}
.ag-editor details {
    border-top: 1px solid var(--line);
    padding-top: 16px;
    margin-top: 16px;
}
.ag-editor summary {
    cursor: pointer;
    font-size: 13px;
}
@media (max-width: 760px) {
    .ag-editor {
        width: 100vw;
        height: 100dvh;
        max-height: none;
        border-radius: 0;
    }
    .ag-editor header,
    .ag-editor footer {
        padding: 10px 12px;
    }
    .ag-editor aside {
        width: 260px;
        padding: 16px;
    }
    .ag-status {
        display: none;
    }
    .ag-previewbar {
        padding: 6px;
    }
    .ag-editor button {
        padding: 7px;
    }
    .ag-editor footer > span {
        display: none;
    }
}
@media (max-width: 560px) {
    .ag-editor main {
        flex-direction: column;
    }
    .ag-workspace {
        flex: 1;
        min-height: 220px;
    }
    .ag-editor aside {
        width: 100%;
        height: 38%;
        min-height: 160px;
        border-left: 0;
        border-top: 1px solid var(--line);
    }
    .ag-editor nav button {
        font-size: 11px;
        padding: 10px 3px;
    }
    .ag-editor footer > div {
        width: 100%;
        justify-content: flex-end;
    }
    .ag-editor footer button {
        font-size: 11px;
    }
    .ag-viewport {
        padding: 16px;
    }
    .ag-editor header {
        padding: 8px 12px;
    }
}
</style>
