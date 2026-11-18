const { test: base, expect } = require("@playwright/test");
const test = base.extend({ browser: [async ({}, use) => {
    const { launchBrowser } = require("../../../tools/browser/launch-browser.cjs");
    const browser = await launchBrowser({ playwrightPath: require("node:path").dirname(require.resolve("playwright/package.json")) });
    try { await use(browser); } finally { await browser.close(); }
}, { scope: "worker" }] });
const fs = require("node:fs"),
    path = require("node:path"),
    http = require("node:http");
const root = path.resolve(__dirname, "../../.."),
    build = path.join(root, "public/themes/dam/build");
let server, origin, initial;

async function observeDragExports(page) {
    await page.addInitScript(() => {
        window.dragExports = [];
        window.pendingDragEncodes = [];
        const create = URL.createObjectURL.bind(URL);
        URL.createObjectURL = (blob) => {
            const url = create(blob);
            if (blob.type === "image/png") window.dragExports.push(url);
            return url;
        };
        const encode = HTMLCanvasElement.prototype.toBlob;
        HTMLCanvasElement.prototype.toBlob = function (callback, ...args) {
            return encode.call(this, (blob) => {
                if (window.holdDragEncoding) window.pendingDragEncodes.push(() => callback(blob));
                else callback(blob);
            }, ...args);
        };
    });
}

test("UPSTREAM-DEVIATION: Drag me never supplies the previous document while a replacement is pending", async ({ page }) => {
    await observeDragExports(page);
    await mount(page);
    await page.waitForFunction(() => window.dragExports.length === 1);
    const result = await page.evaluate(() => {
        const api = document.querySelector(".pe-root").__photoedit;
        const next = api.getDocument();
        next.counterStart++;
        api.setDocument(next);
        const transfer = new DataTransfer();
        const event = new DragEvent("dragstart", { bubbles: true, cancelable: true, dataTransfer: transfer });
        document.querySelector(".pe-drag").dispatchEvent(event);
        return { prevented: event.defaultPrevented, url: transfer.getData("DownloadURL") };
    });
    expect(result).toEqual({ prevented: true, url: "" });
    await page.waitForFunction(() => window.dragExports.length === 2);
    const latest = await page.evaluate(() => {
        const transfer = new DataTransfer();
        const event = new DragEvent("dragstart", { bubbles: true, cancelable: true, dataTransfer: transfer });
        document.querySelector(".pe-drag").dispatchEvent(event);
        return { prevented: event.defaultPrevented, url: transfer.getData("text/uri-list"), latest: window.dragExports.at(-1) };
    });
    expect(latest.prevented).toBe(false);
    expect(latest.url).toBe(latest.latest);
});

for (const action of ["edit", "destroy"]) {
    test(`UPSTREAM-DEVIATION: a delayed drag encode cannot publish after ${action}`, async ({ page }) => {
        await observeDragExports(page);
        await mount(page);
        await page.evaluate(() => { window.holdDragEncoding = true; });
        await page.waitForFunction(() => window.pendingDragEncodes.length === 1);
        await page.evaluate((action) => {
            const api = document.querySelector(".pe-root").__photoedit;
            window.holdDragEncoding = false;
            if (action === "destroy") api.destroy();
            else {
                const next = api.getDocument();
                next.counterStart++;
                api.setDocument(next);
            }
            window.pendingDragEncodes.shift()();
        }, action);
        // Drain the released encoding's promise continuations before inspecting publication.
        await page.evaluate(() => new Promise((resolve) => setTimeout(resolve, 0)));
        expect(await page.evaluate(() => window.dragExports.length)).toBe(0);
        if (action === "edit") {
            await page.waitForFunction(() => window.dragExports.length === 1);
        }
    });
}

test("UPSTREAM-DEVIATION: a dropped image URL stays fetchable after a subsequent edit", async ({ page }) => {
    await observeDragExports(page);
    await mount(page);
    await page.waitForFunction(() => window.dragExports.length === 1);
    const size = await page.evaluate(async () => {
        const transfer = new DataTransfer();
        document.querySelector(".pe-drag").dispatchEvent(new DragEvent("dragstart", { bubbles: true, cancelable: true, dataTransfer: transfer }));
        const url = transfer.getData("text/uri-list");
        const api = document.querySelector(".pe-root").__photoedit;
        const next = api.getDocument();
        next.counterStart++;
        api.setDocument(next);
        return (await (await fetch(url)).blob()).size;
    });
    expect(size).toBeGreaterThan(0);
});

test("UPSTREAM-DEVIATION: repeated refused drags preserve an encoding already in flight", async ({ page }) => {
    await observeDragExports(page);
    await mount(page);
    await page.evaluate(() => { window.holdDragEncoding = true; });
    await page.waitForFunction(() => window.pendingDragEncodes.length === 1);
    await page.evaluate(() => {
        for (let i = 0; i < 5; i++) {
            const event = new DragEvent("dragstart", { bubbles: true, cancelable: true, dataTransfer: new DataTransfer() });
            document.querySelector(".pe-drag").dispatchEvent(event);
            if (!event.defaultPrevented) throw new Error("encoding is still pending");
        }
        window.holdDragEncoding = false;
        window.pendingDragEncodes.shift()();
    });
    await page.evaluate(() => new Promise((resolve) => setTimeout(resolve, 0)));
    expect(await page.evaluate(() => window.dragExports.length)).toBe(1);
});
test.beforeAll(async () => {
    // A schema v1 document, as the server stores it for a never-edited asset: PhotoEdit converts it on open.
    const { createDocument } = await import(
        path.join(
            root,
            "packages/Mothership/DAM/src/Resources/assets/js/image/document.mjs",
        )
    );
    initial = createDocument({ base: "source", width: 600, height: 400 });
    const manifest = JSON.parse(
            fs.readFileSync(path.join(build, "manifest.json")),
        ),
        entry = manifest["src/Resources/assets/js/app.js"].file;
    server = http.createServer((req, res) => {
        const p = new URL(req.url, "http://localhost").pathname;
        if (p === "/") {
            res.setHeader("Content-Type", "text/html");
            res.end(
                `<style>body{margin:0;font:14px sans-serif}.col{max-width:480px;padding:12px}</style><form onsubmit="event.preventDefault();window.parentSubmits=(window.parentSubmits||0)+1"><div class="col"><div style="height:1400px">Preview column content</div><button type="button" id="opener">Edit</button><div id="host"></div></div></form><script type="module" src="/bundle/${entry}"></script>`,
            );
            return;
        }
        if (p === "/source.svg") {
            res.setHeader("Content-Type", "image/svg+xml");
            res.end(
                '<svg xmlns="http://www.w3.org/2000/svg" width="600" height="400"><rect width="600" height="400" fill="#ddd6fe"/><circle cx="300" cy="200" r="100" fill="#7c3aed"/></svg>',
            );
            return;
        }
        const relative = p.startsWith("/bundle/")
            ? p.slice(8)
            : p.startsWith("/themes/dam/build/")
              ? p.slice(18)
              : null;
        const file = relative && path.join(build, relative);
        if (!file || !fs.existsSync(file)) {
            res.statusCode = 404;
            res.end();
            return;
        }
        res.setHeader(
            "Content-Type",
            file.endsWith(".css") ? "text/css" : "text/javascript",
        );
        res.end(fs.readFileSync(file));
    });
    await new Promise((r) => server.listen(0, "127.0.0.1", r));
    origin = `http://127.0.0.1:${server.address().port}`;
});
test.afterAll(async () => {
    await new Promise((r) => server.close(r));
});

/**
 * Mounts the editor the way the asset page does, over a stand-in for window.axios that records every request:
 * window.requests holds { url, mode, revision, document, render: { type, size, name, width, height } }.
 * Set window.nextSaveError (an axios-shaped error) to make the next save fail, or window.saveGate (a promise) to hold
 * the save response until it resolves; window.resourceGate likewise holds an image upload.
 * The host is what the asset page renders: an unsized div inside the preview column, below 1400px of page content.
 * `load` "hold" keeps the document request pending until window.releaseLoad(), "fail" rejects it; either way mount
 * returns without waiting for the editor.
 */
async function mount(page, props = {}, load = null) {
    await page.goto(origin);
    await page.waitForFunction(() => window.loadDamImageEditor);
    await page.evaluate(
        async ({ initial, props, load }) => {
            window.loadMode = load;
            window.releaseLoad = () => {};
            const held = new Promise((resolve) => (window.releaseLoad = resolve));
            window.editorDoc = initial;
            window.saved = [];
            window.editorCloseCount = 0;
            window.requests = [];
            window.revision = "revision";
            window.version = 1;
            window.axios = {
                get: async () => {
                    if (window.loadMode === "fail") throw { response: { data: { message: "Not found." } } };
                    if (window.loadMode === "hold") await held;
                    return {
                    data: {
                        document: window.editorDoc,
                        revision: window.revision,
                        original_base: "source",
                        resources: { source: "/source.svg" },
                        source: props.source || null,
                    },
                    };
                },
                post: async (url, form) => {
                    if (!url.endsWith("/save")) {
                        // An image upload: window.resourceGate (a promise) holds it; it answers with a drawable resource.
                        window.requests.push({ url });
                        const id = `upload-${window.requests.length}`;
                        if (window.resourceGate) await window.resourceGate;
                        return { data: { id, url: "/source.svg", source: props.uploadSource || null } };
                    }
                    const render = form.get("render");
                    const bitmap = await createImageBitmap(render);
                    window.requests.push({
                        url,
                        mode: form.get("mode"),
                        revision: form.get("revision"),
                        document: JSON.parse(form.get("document")),
                        render: {
                            type: render.type,
                            size: render.size,
                            name: render.name,
                            width: bitmap.width,
                            height: bitmap.height,
                        },
                    });
                    if (window.saveGate) await window.saveGate;
                    if (window.nextSaveError) {
                        const error = window.nextSaveError;
                        window.nextSaveError = null;
                        throw error;
                    }
                    window.editorDoc = JSON.parse(form.get("document"));
                    if (form.get("mode") === "version") {
                        window.revision = `revision-${++window.version}`;
                    }
                    return {
                        data: {
                            asset_id: 1,
                            revision: window.revision,
                            version: window.version,
                        },
                    };
                },
            };
            const { mountEditor } = await window.loadDamImageEditor();
            window.mountAgain = () => {
                window.destroyEditor?.();
                window.destroyEditor = mountEditor(
                    document.querySelector("#host"),
                    {
                        assetId: 1,
                        labels: window.fixtureLabels || {},
                        fileName: "Sample.png",
                        endpoint: "/editor/__ASSET__",
                        // The asset page's onClose only flips isEditOpen; its watcher then tears the editor down.
                        onClose: () => {
                            window.editorCloseCount++;
                            setTimeout(() => window.destroyEditor?.(), 0);
                        },
                        // window.closeOnSaved: like the asset page, a reported save also tears the editor down.
                        onSaved: (data) => {
                            window.saved.push(data);
                            if (window.closeOnSaved) setTimeout(() => window.destroyEditor?.(), 0);
                        },
                        ...props,
                    },
                );
            };
            document.querySelector("#opener").focus({ preventScroll: true });
            window.mountAgain();
        },
        { initial, props, load },
    );
    if (!props.templateMode && !load) {
        await expect(page.locator(".pe-root")).toBeVisible();
        await expect(page.locator(".pe-size-label")).toHaveText("600 × 400");
    }
}
const button = (page, name) => page.getByRole("button", { name, exact: true });
const requests = (page) => page.evaluate(() => window.requests);
const liveObjects = (page) => page.evaluate(() => /** @type {any} */ (document.querySelector(".pe-root")).__photoedit.getDocument().objects);
const objectTypes = async (page) => (await liveObjects(page)).map((o) => o.type);
const lastColor = async (page) => (await liveObjects(page)).at(-1).color;
// A 1×1 PNG for Add image.
const PNG_FILE = {
    name: "dot.png",
    mimeType: "image/png",
    buffer: Buffer.from("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFBQIAX8jx0gAAAABJRU5ErkJggg==", "base64"),
};
// Names of the toolbar and bottom-bar buttons that sit outside the viewport.
const offScreenButtons = (page) =>
    page.evaluate(() =>
        [...document.querySelectorAll(".pe-bar button")]
            .filter((b) => {
                const r = b.getBoundingClientRect();
                return r.width > 0 && (r.left < 0 || r.right > innerWidth || r.bottom > innerHeight);
            })
            .map((b) => b.getAttribute("aria-label") || b.textContent.trim()),
    );

async function drawRectangle(page) {
    const box = await page.locator(".pe-stage canvas").boundingBox();
    await page.mouse.move(box.x + box.width * 0.3, box.y + box.height * 0.3);
    await page.mouse.down();
    await page.mouse.move(box.x + box.width * 0.55, box.y + box.height * 0.5, { steps: 6 });
    await page.mouse.up();
}

test("real pointer drag, undo/redo, save a new version and reopen the editable document", async ({
    page,
}) => {
    await mount(page);
    await page.keyboard.press("r");
    await drawRectangle(page);
    await button(page, "Undo").click();
    await expect(button(page, "Undo")).toBeDisabled();
    await button(page, "Redo").click();
    await expect(button(page, "Undo")).toBeEnabled();

    await button(page, "Save new version").click();
    await expect.poll(() => page.evaluate(() => window.saved.length)).toBe(1);
    const [save] = await requests(page);
    expect(save).toMatchObject({
        url: "/editor/1/save",
        mode: "version",
        revision: "revision",
        render: { type: "image/png", name: "edited.png", width: 600, height: 400 },
    });
    expect(save.render.size).toBeGreaterThan(100);
    // Saving always writes schema v2, whatever the stored document's version.
    expect(save.document).toMatchObject({ schemaVersion: 2, base: "source", sourceWidth: 600, sourceHeight: 400 });
    expect(save.document.objects).toHaveLength(1);
    expect(save.document.objects[0].type).toBe("rect");
    expect(await page.evaluate(() => window.saved[0])).toMatchObject({ asset_id: 1, version: 2 });

    // Reopened from the saved v2 document and saved again: the drawn rectangle is still an editable object.
    await page.evaluate(() => window.mountAgain());
    await expect(page.locator(".pe-size-label")).toHaveText("600 × 400");
    await button(page, "Save new version").click();
    await expect.poll(async () => (await requests(page)).length).toBe(2);
    const [, again] = await requests(page);
    expect(again.revision).toBe("revision-2");
    expect(again.document.objects).toEqual(save.document.objects);
});

test("Save as new asset posts mode new and does not move the revision of the asset being edited", async ({
    page,
}) => {
    await mount(page);
    await page.keyboard.press("r");
    await drawRectangle(page);
    await button(page, "Save as new asset").click();
    await expect.poll(() => page.evaluate(() => window.saved.length)).toBe(1);
    await button(page, "Save new version").click();
    await expect.poll(() => page.evaluate(() => window.saved.length)).toBe(2);
    await button(page, "Save new version").click();
    await expect.poll(() => page.evaluate(() => window.saved.length)).toBe(3);
    const sent = await requests(page);
    expect(sent.map((r) => [r.mode, r.revision])).toEqual([
        ["new", "revision"],
        ["version", "revision"],
        ["version", "revision-2"],
    ]);
});

test("a 409 keeps the edits, reports the newer version and does not close or report a save", async ({
    page,
}) => {
    await mount(page);
    await page.keyboard.press("r");
    await drawRectangle(page);
    await page.evaluate(() => {
        window.nextSaveError = { response: { status: 409, data: { message: "A newer edit exists." } } };
    });
    await button(page, "Save new version").click();
    await expect(page.getByRole("status").filter({ hasText: "A newer version was saved elsewhere" })).toBeVisible();
    expect(await page.evaluate(() => window.saved.length)).toBe(0);
    // The edit is still there, still unsaved, and can go out as a new asset.
    await expect(button(page, "Undo")).toBeEnabled();
    await button(page, "Save as new asset").click();
    await expect.poll(() => page.evaluate(() => window.saved.length)).toBe(1);
    const sent = await requests(page);
    expect(sent.map((r) => r.mode)).toEqual(["version", "new"]);
    expect(sent[1].document.objects).toHaveLength(1);
});

test("a JPEG asset is saved as a JPEG; a type the editor cannot keep asks before becoming a PNG", async ({
    page,
}) => {
    await mount(page, { fileName: "Photo.jpg" });
    await button(page, "Save new version").click();
    await expect.poll(async () => (await requests(page)).length).toBe(1);
    expect((await requests(page))[0].render).toMatchObject({ type: "image/jpeg", name: "edited.jpg", width: 600, height: 400 });

    await mount(page, { fileName: "Banner.webp" });
    await button(page, "Save new version").click();
    const dialog = page.getByRole("dialog", { name: "Save as PNG?" });
    await expect(dialog).toBeVisible();
    await dialog.getByRole("button", { name: "Cancel" }).click();
    expect(await requests(page)).toHaveLength(0);
    await button(page, "Save new version").click();
    await page.getByRole("dialog", { name: "Save as PNG?" }).getByRole("button", { name: "Save" }).click();
    await expect.poll(async () => (await requests(page)).length).toBe(1);
    expect((await requests(page))[0].render).toMatchObject({ type: "image/png", name: "edited.png" });
});

test("every tool has its single-key shortcut and B toggles the background panel", async ({
    page,
}) => {
    await mount(page);
    const tools = [
        ["v", "Select"], ["r", "Rectangle"], ["f", "Filled rectangle"], ["e", "Ellipse"], ["l", "Line"], ["a", "Arrow"],
        ["t", "Text"], ["p", "Redact"], ["h", "Spotlight"], ["c", "Counter"], ["d", "Draw"], ["m", "Highlighter"],
    ];
    await expect(button(page, "Rectangle")).toHaveAttribute("aria-pressed", "true");
    for (const [key, name] of tools) {
        await page.keyboard.press(key);
        await expect(button(page, name)).toHaveAttribute("aria-pressed", "true");
        for (const [, other] of tools) {
            if (other !== name) await expect(button(page, other)).toHaveAttribute("aria-pressed", "false");
        }
    }
    await expect(button(page, "Background")).toHaveAttribute("aria-pressed", "false");
    await page.keyboard.press("b");
    await expect(button(page, "Background")).toHaveAttribute("aria-pressed", "true");
    await expect(button(page, "Done")).toBeVisible();
    await button(page, "Done").click();
    await expect(button(page, "Background")).toHaveAttribute("aria-pressed", "false");
});

test("the base image is drawn at its size", async ({ page }) => {
    await mount(page);
    // The image's middle is the purple circle of the fixture.
    const centre = await page.locator(".pe-stage canvas").evaluate((canvas) => {
        const ctx = canvas.getContext("2d");
        const [r, g, b, a] = ctx.getImageData(canvas.width / 2, canvas.height / 2, 1, 1).data;
        return { r, g, b, a };
    });
    expect(centre).toEqual({ r: 0x7c, g: 0x3a, b: 0xed, a: 255 });
});

test("responsive dark UI keeps the editor inside a phone-width viewport with every control on screen", async ({
    page,
}) => {
    await mount(page);
    await page.evaluate(() => document.documentElement.classList.add("dark"));
    await expect(page.locator(".pe-root")).toHaveClass(/pe-dark/);
    await page.setViewportSize({ width: 375, height: 812 });
    const bounds = await page.locator(".pe-root").boundingBox();
    expect(bounds.width).toBeLessThanOrEqual(375);
    // The overlay wraps PhotoEdit's bars on a phone instead of letting them scroll sideways, so no control is off-screen.
    for (const bar of [page.locator(".pe-top"), page.locator(".pe-bottom")]) {
        expect(await bar.evaluate((el) => el.scrollWidth <= el.clientWidth)).toBe(true);
    }
    expect(await offScreenButtons(page)).toEqual([]);
    await expect(button(page, "Save new version")).toBeInViewport();
    await expect(button(page, "Save as new asset")).toBeInViewport();
    await expect(button(page, "Close")).toBeInViewport();
});
/** On a phone the open background panel sits above the picture, never over it, and every bar keeps its controls on screen. */
async function expectPhoneBackgroundAndCrop(page, saveNames) {
    const barsFit = () =>
        page.evaluate(() => [...document.querySelectorAll(".pe-top, .pe-bottom")].every((el) => el.scrollWidth <= el.clientWidth));
    // On screen and not covered: the button itself is what a tap at its centre reaches.
    const expectTappable = async (names) => {
        for (const name of names) {
            await expect(button(page, name)).toBeInViewport();
            expect(await button(page, name).evaluate((el) => {
                const r = el.getBoundingClientRect();
                return el.contains(document.elementFromPoint(r.x + r.width / 2, r.y + r.height / 2));
            }), name).toBe(true);
        }
    };
    await page.locator(".pe-stage").click({ position: { x: 4, y: 4 } });
    await page.keyboard.press("b");
    await expect(button(page, "Done")).toBeVisible();
    expect(await barsFit()).toBe(true);
    expect(await offScreenButtons(page)).toEqual([]);
    await expectTappable([...saveNames, "Done", "Close"]);
    const top = await page.locator(".pe-top").boundingBox();
    const panel = await page.locator(".pe-panel").boundingBox();
    const stage = await page.locator(".pe-stage").boundingBox();
    // The picture keeps a usable area of its own while the background is being changed.
    expect(panel.y).toBeGreaterThanOrEqual(top.y + top.height - 1);
    expect(stage.y).toBeGreaterThanOrEqual(panel.y + panel.height - 1);
    expect(stage.width).toBeGreaterThan(300);
    expect(stage.height).toBeGreaterThan(150);
    await button(page, "Done").click();
    await button(page, "Crop & resize").click();
    await expect(button(page, "Revert to Original")).toBeVisible();
    expect(await barsFit()).toBe(true);
    expect(await offScreenButtons(page)).toEqual([]);
    await expectTappable(["Cancel", "Crop", "Swap width and height"]);
}

test("on a phone the image editor's background panel and crop bar keep the picture and every control on screen", async ({ page }) => {
    await mount(page);
    await page.setViewportSize({ width: 375, height: 812 });
    await expectPhoneBackgroundAndCrop(page, ["Save new version", "Save as new asset"]);
});

for (const width of [768, 1024, 1440]) {
    for (const dark of [false, true]) {
        test(`responsive actions fit at ${width}px in ${dark ? "dark" : "light"} theme`, async ({ page }) => {
            await page.setViewportSize({ width, height: 900 });
            await mount(page);
            if (dark) await page.evaluate(() => document.documentElement.classList.add("dark"));
            const check = async (names) => {
                expect(await offScreenButtons(page)).toEqual([]);
                for (const name of names) {
                    const control = button(page, name);
                    await expect(control).toBeInViewport();
                    expect(await control.evaluate(el => {
                        const r = el.getBoundingClientRect();
                        return el.contains(document.elementFromPoint(r.x + r.width / 2, r.y + r.height / 2));
                    }), name).toBe(true);
                }
                for (const bar of [page.locator(".pe-top"), page.locator(".pe-bottom")]) {
                    expect(await bar.evaluate(el => el.scrollWidth <= el.clientWidth)).toBe(true);
                }
            };
            await check(["Save new version", "Save as new asset", "Close"]);
            await page.locator(".pe-stage").click({ position: { x: 4, y: 4 } });
            await page.keyboard.press("b");
            await check(["Save new version", "Save as new asset", "Done", "Close"]);
            const stage = await page.locator(".pe-stage").boundingBox();
            expect(stage.width).toBeGreaterThan(300);
            expect(stage.height).toBeGreaterThan(150);
            await button(page, "Done").click();
            await button(page, "Crop & resize").click();
            await check(["Cancel", "Crop"]);
        });
    }
}

test("large-source dimensions stay visible and version save requires resize confirmation", async ({ page }) => {
    await mount(page, { source: { original_width: 12000, original_height: 8000, working_width: 6928, working_height: 4618, reduced: true } });
    const notice = page.locator(".pe-source-notice");
    await expect(notice).toContainText("12000 × 8000 → 6928 × 4618");
    await button(page, "Save new version").click();
    await expect(page.getByText("Save reduced-size version?", { exact: true })).toBeVisible();
    await button(page, "Cancel").click();
    expect(await page.evaluate(() => window.requests.length)).toBe(0);
    await expect(notice).toBeVisible();
    await button(page, "Save as new asset").click();
    await expect.poll(() => page.evaluate(() => window.requests.length)).toBe(1);
});

test("an added large image keeps its reduced-working-copy notice visible after editing", async ({ page }) => {
    await mount(page, { uploadSource: { original_width: 12000, original_height: 8000, working_width: 6928, working_height: 4618, reduced: true } });
    await page.locator('input[type="file"]').first().setInputFiles(PNG_FILE);
    await expect(page.locator('.pe-source-notice')).toContainText('Added image uses a reduced working copy: 12000 × 8000 → 6928 × 4618');
    await drawRectangle(page);
    await expect(page.locator('.pe-source-notice')).toBeVisible();
    await button(page, 'Crop & resize').click();
    await expect(page.locator('.pe-source-notice')).toBeVisible();
});

test("on a phone the thumbnail editor's background panel and crop bar keep the picture and every control on screen", async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 812 });
    await mountThumbnail(page);
    await expect(page.locator(".pe-size-label")).toHaveText("320 × 240");
    await expectPhoneBackgroundAndCrop(page, ["Save thumbnail"]);
});

test("the thumbnail editor keeps Save thumbnail and Close on screen on a phone", async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 812 });
    await mountThumbnail(page);
    await expect(page.locator(".pe-size-label")).toHaveText("320 × 240");
    expect(await offScreenButtons(page)).toEqual([]);
    await expect(button(page, "Save thumbnail")).toBeInViewport();
    await expect(button(page, "Close")).toBeInViewport();
});
test("editor buttons never submit the ancestor asset form", async ({ page }) => {
    await mount(page);
    await button(page, "Background").click();
    await button(page, "Done").click();
    await button(page, "Crop & resize").click();
    await button(page, "Cancel").click();
    await button(page, "Save new version").click();
    await expect.poll(async () => (await requests(page)).length).toBe(1);
    expect(await page.evaluate(() => window.parentSubmits || 0)).toBe(0);
});

test("closing with unsaved edits asks first, then reports the close", async ({
    page,
}) => {
    await mount(page);
    await button(page, "Close").click();
    expect(await page.evaluate(() => window.editorCloseCount)).toBe(1);
    await expect(page.getByRole("dialog")).toHaveCount(0);
    await expect(page.locator("#opener")).toBeFocused();
    await page.evaluate(() => window.mountAgain());
    await expect(page.locator(".pe-size-label")).toHaveText("600 × 400");
    await page.keyboard.press("r");
    await drawRectangle(page);
    await button(page, "Close").click();
    const dialog = page.getByRole("dialog", { name: "Discard changes?" });
    await dialog.getByRole("button", { name: "Keep editing" }).click();
    expect(await page.evaluate(() => window.editorCloseCount)).toBe(1);
    await button(page, "Close").click();
    await page.getByRole("dialog", { name: "Discard changes?" }).getByRole("button", { name: "Discard" }).click();
    await expect.poll(() => page.evaluate(() => window.editorCloseCount)).toBe(2);
    await expect(page.getByRole("dialog")).toHaveCount(0);
});

test("editor accepts translated UI labels", async ({ page }) => {
    await page.addInitScript(
        () => (window.fixtureLabels = { Background: "Fondo", "Save new version": "Guardar versión" }),
    );
    await mount(page);
    await expect(button(page, "Fondo")).toBeVisible();
    await expect(button(page, "Guardar versión")).toBeVisible();
});

const dialog = (page) => page.getByRole("dialog", { name: "Image editor", exact: true });
const inViewport = (page, box) => {
    const { width, height } = page.viewportSize();
    return box.x >= -0.5 && box.y >= -0.5 && box.x + box.width <= width + 0.5 && box.y + box.height <= height + 0.5;
};

test("opens as a viewport overlay over the page, not inline in the preview column", async ({ page }) => {
    await mount(page);
    expect(await page.evaluate(() => window.scrollY)).toBe(0); // opening does not move the page
    // The page behind is scrolled (the preview column is taller than the screen); the overlay does not move with it.
    await page.evaluate(() => window.scrollTo(0, 500));
    expect(await page.evaluate(() => window.scrollY)).toBe(500);
    const panel = await dialog(page).boundingBox();
    const view = page.viewportSize();
    expect(inViewport(page, panel)).toBe(true);
    expect(panel.height).toBeCloseTo(view.height * 0.94, 0);
    expect(panel.width).toBeCloseTo(Math.min(1340, view.width * 0.96), 0);
    // Centred, and above the page content, the way the legacy editor's backdrop was.
    expect(panel.x + panel.width / 2).toBeCloseTo(view.width / 2, 0);
    expect(panel.y + panel.height / 2).toBeCloseTo(view.height / 2, 0);
    expect(await page.evaluate(() => {
        const mid = document.elementFromPoint(innerWidth / 2, innerHeight / 2);
        return !!mid?.closest('[role="dialog"]') && getComputedStyle(mid.closest('[role="dialog"]').parentElement).zIndex;
    })).toBe("10030");
    // PhotoEdit lives in the overlay; the page's own host stays empty and does not grow.
    expect(await page.locator("#host .pe-root").count()).toBe(0);
    expect(await page.locator("#host").evaluate((host) => host.offsetHeight)).toBe(0);
    const stage = await page.locator(".pe-stage").boundingBox();
    const canvas = await page.locator(".pe-stage canvas").boundingBox();
    expect(stage.width).toBeGreaterThan(600);
    expect(stage.height).toBeGreaterThan(300);
    expect(canvas.width).toBeGreaterThan(300);
    expect(canvas.height).toBeGreaterThan(200);
    expect(inViewport(page, stage)).toBe(true);
    // Closing leaves the page where it was and gives focus back to what opened the editor.
    await button(page, "Close").click();
    await expect(page.getByRole("dialog")).toHaveCount(0);
    expect(await page.evaluate(() => window.scrollY)).toBe(500);
    await expect(page.locator("#opener")).toBeFocused();
    // One clean remount after the close.
    await page.evaluate(() => window.mountAgain());
    await expect(page.locator(".pe-size-label")).toHaveText("600 × 400");
    await expect(page.getByRole("dialog")).toHaveCount(1);
    await expect(page.locator(".pe-root")).toHaveCount(1);
});

// WCAG contrast of an element's text against the first opaque background behind it.
const contrastOf = (locator) =>
    locator.evaluate((el) => {
        const parse = (c) => c.match(/[\d.]+/g).map(Number);
        let bg = [0, 0, 0, 0];
        for (let n = el; n && bg[3] < 1; n = n.parentElement) {
            const [r, g, b, a = 1] = parse(getComputedStyle(n).backgroundColor);
            if (a > 0) bg = a >= 1 ? [r, g, b, 1] : [r, g, b, a];
        }
        const lum = ([r, g, b]) => {
            const f = (v) => ((v /= 255) <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4);
            return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b);
        };
        const [fr, fg, fb] = parse(getComputedStyle(el).color);
        const [l1, l2] = [lum([fr, fg, fb]), lum(bg)].sort((a, b) => b - a);
        return { ratio: (l1 + 0.05) / (l2 + 0.05), bg: bg.slice(0, 3).join(",") };
    });

for (const scheme of ["light", "dark"]) {
    test(`loading and error states are legible in the ${scheme} theme`, async ({ page }) => {
        await page.emulateMedia({ colorScheme: scheme });
        await mount(page, {}, "hold");
        const loading = page.getByRole("status").filter({ hasText: "Loading…" });
        await expect(loading).toBeVisible();
        const panelBg = await dialog(page).evaluate((el) => getComputedStyle(el).backgroundColor);
        expect(panelBg).toBe(scheme === "dark" ? "rgb(35, 36, 40)" : "rgb(246, 246, 248)");
        expect((await contrastOf(loading)).ratio).toBeGreaterThanOrEqual(4.5);

        await mount(page, {}, "fail");
        const alert = page.getByRole("alert");
        await expect(alert).toHaveText("Not found.");
        expect((await contrastOf(alert)).ratio).toBeGreaterThanOrEqual(4.5);
        const close = button(page, "Close");
        await expect(close).toBeVisible();
        expect((await contrastOf(close)).ratio).toBeGreaterThanOrEqual(4.5);
    });
}

test("the html.dark class switches the overlay panel to the dark palette", async ({ page }) => {
    await page.emulateMedia({ colorScheme: "light" });
    await mount(page, {}, "hold");
    await expect(dialog(page)).toHaveCSS("background-color", "rgb(246, 246, 248)");
    await page.evaluate(() => document.documentElement.classList.add("dark"));
    await expect(dialog(page)).toHaveCSS("background-color", "rgb(35, 36, 40)");
});

test("focus moves into the overlay, stays inside it while loading, and returns to the opener on teardown", async ({ page }) => {
    await mount(page, {}, "hold");
    await expect(dialog(page)).toBeFocused();
    expect(await page.evaluate(() => window.scrollY)).toBe(0);
    for (const key of ["Tab", "Shift+Tab", "Tab", "Tab"]) {
        await page.keyboard.press(key);
        expect(await page.evaluate(() => !!document.activeElement.closest('[role="dialog"]'))).toBe(true);
    }
    await page.evaluate(() => window.destroyEditor());
    await expect(page.locator("#opener")).toBeFocused();
    // The Tab trap is gone with the overlay: Tab from the opener is the page's again.
    await page.keyboard.press("Tab");
    expect(await page.evaluate(() => document.activeElement.id)).not.toBe("opener");
});

test("Tab wraps within the error state and within the loaded editor", async ({ page }) => {
    await mount(page, {}, "fail");
    await expect(page.getByRole("alert")).toBeVisible();
    await expect(dialog(page)).toBeFocused();
    await page.keyboard.press("Tab");
    await expect(button(page, "Close")).toBeFocused();
    await page.keyboard.press("Tab");
    await expect(button(page, "Close")).toBeFocused();
    await page.keyboard.press("Shift+Tab");
    await expect(button(page, "Close")).toBeFocused();

    await mount(page);
    for (let i = 0; i < 80; i++) {
        await page.keyboard.press("Tab");
        expect(await page.evaluate(() => !!document.activeElement.closest('[role="dialog"]'))).toBe(true);
    }
    for (let i = 0; i < 80; i++) {
        await page.keyboard.press("Shift+Tab");
        expect(await page.evaluate(() => !!document.activeElement.closest('[role="dialog"]'))).toBe(true);
    }
});

test("a slow load shows a visible loading state in the overlay, then the editor replaces it", async ({ page }) => {
    await mount(page, {}, "hold");
    const loading = page.getByRole("status").filter({ hasText: "Loading…" });
    await expect(loading).toBeVisible();
    const box = await loading.boundingBox();
    expect(box.height).toBeGreaterThan(20);
    expect(inViewport(page, await dialog(page).boundingBox())).toBe(true);
    expect(inViewport(page, box)).toBe(true);
    await page.evaluate(() => window.releaseLoad());
    await expect(page.locator(".pe-size-label")).toHaveText("600 × 400");
    await expect(loading).toHaveCount(0);
});

test("a rejected load shows the error and a working Close inside the overlay", async ({ page }) => {
    await mount(page, {}, "fail");
    const alert = page.getByRole("alert");
    await expect(alert).toHaveText("Not found.");
    expect(inViewport(page, await alert.boundingBox())).toBe(true);
    const close = page.getByRole("button", { name: "Close", exact: true });
    await expect(close).toBeInViewport();
    await close.click();
    expect(await page.evaluate(() => window.editorCloseCount)).toBe(1);
    await expect(page.getByRole("dialog")).toHaveCount(0);
    await expect(page.locator("#opener")).toBeFocused();
    await page.evaluate(() => {
        window.loadMode = null;
        window.mountAgain();
    });
    await expect(page.locator(".pe-size-label")).toHaveText("600 × 400");
    await expect(page.getByRole("dialog")).toHaveCount(1);
    await expect(page.locator(".pe-root")).toHaveCount(1);
});

test("teardown removes the overlay, a late load does not revive it, and remounting gives exactly one", async ({ page }) => {
    await mount(page, {}, "hold");
    await expect(dialog(page)).toBeVisible();
    await page.evaluate(() => window.destroyEditor());
    await expect(page.getByRole("dialog")).toHaveCount(0);
    await page.evaluate(() => window.releaseLoad());
    await page.waitForTimeout(300);
    await expect(page.getByRole("dialog")).toHaveCount(0);
    await expect(page.locator(".pe-root")).toHaveCount(0);

    await page.evaluate(() => {
        window.loadMode = null;
        window.mountAgain();
    });
    await expect(page.locator(".pe-size-label")).toHaveText("600 × 400");
    await page.evaluate(() => window.mountAgain());
    await expect(page.locator(".pe-size-label")).toHaveText("600 × 400");
    await expect(page.getByRole("dialog")).toHaveCount(1);
    await expect(page.locator(".pe-root")).toHaveCount(1);
});

test("a save that answers after the editor was torn down reports nothing to the page", async ({ page }) => {
    await mount(page);
    await page.keyboard.press("r");
    await drawRectangle(page);
    await page.evaluate(() => {
        window.saveGate = new Promise((resolve) => (window.releaseSave = resolve));
    });
    await button(page, "Save new version").click();
    await expect.poll(async () => (await requests(page)).length).toBe(1);
    // The page tears the editor down (it navigated, or another flow closed it) before the server answers.
    await page.evaluate(() => window.destroyEditor());
    await expect(page.getByRole("dialog")).toHaveCount(0);
    await page.evaluate(() => window.releaseSave());
    await page.waitForTimeout(300);
    // The asset page's onSaved reloads the page, which would discard whatever the person is doing now.
    expect(await page.evaluate(() => [window.saved.length, window.editorCloseCount])).toEqual([0, 0]);
});

/**
 * Edits made while a save is in flight (keyboard Undo and a toolbar rotation: PhotoEdit only blocks pointer drawing then)
 * are not in what was saved. The page's onSaved closes the editor and reloads, so it must not run while they are unsaved:
 * the editor stays open with them, and the save reaches the page once the person closes the editor.
 */
async function editWhileSaving(page, { saveName, hold, release, posted, closeCount, rotatedSize }) {
    await page.keyboard.press("r");
    await drawRectangle(page);
    await page.evaluate(hold);
    await button(page, saveName).click();
    await expect.poll(() => page.evaluate(posted)).toBe(1);
    await page.keyboard.press("ControlOrMeta+z");
    await button(page, "Rotate left").click();
    await page.evaluate(release);
    await expect(page.locator(".pe-toast")).toBeVisible();
    await page.waitForTimeout(300);
    expect(await page.evaluate(() => window.saved.length)).toBe(0);
    await expect(page.locator(".pe-root")).toBeVisible();
    await expect(page.locator(".pe-size-label")).toHaveText(rotatedSize);
    await button(page, "Close").click();
    await page.getByRole("dialog", { name: "Discard changes?" }).getByRole("button", { name: "Discard", exact: true }).click();
    await expect.poll(() => page.evaluate(() => window.saved.length)).toBe(1);
    expect(await page.evaluate(closeCount)).toBe(0);
}

test("edits made while a save is in flight keep the editor open; the save reaches the page when it closes", async ({ page }) => {
    await mount(page);
    await editWhileSaving(page, {
        saveName: "Save new version",
        hold: () => {
            window.saveGate = new Promise((resolve) => (window.releaseSave = resolve));
        },
        release: () => window.releaseSave(),
        posted: () => window.requests.length,
        closeCount: () => window.editorCloseCount,
        rotatedSize: "400 × 600",
    });
});

test("template editing keeps the legacy editor", async ({ page }) => {
    await mount(page, { templateMode: true });
    await expect(page.locator(".pe-root")).toHaveCount(0);
    await expect(page.getByRole("heading", { name: "Image editor", exact: true })).toBeVisible();
});

/**
 * Mounts the thumbnail editor the way the asset page does for a non-photo asset: its current thumbnail is GET as a
 * blob, and the one action posts it to the custom-thumbnail endpoint. window.thumbPosts records each post;
 * window.thumbFail makes the next post reject; window.revoked lists every revoked object URL.
 */
async function mountThumbnail(page, { type = "image/png" } = {}) {
    await page.goto(origin);
    await page.waitForFunction(() => window.loadDamImageEditor);
    await page.evaluate(async ({ type }) => {
        window.thumbPosts = [];
        window.thumbFail = null;
        window.saved = [];
        window.closeCount = 0;
        window.revoked = [];
        const revoke = URL.revokeObjectURL.bind(URL);
        URL.revokeObjectURL = (url) => {
            window.revoked.push(url);
            revoke(url);
        };
        const canvas = document.createElement("canvas");
        canvas.width = 320;
        canvas.height = 240;
        const context = canvas.getContext("2d");
        context.fillStyle = "#334155";
        context.fillRect(0, 0, 320, 240);
        const picture = await new Promise((resolve) => canvas.toBlob(resolve, "image/png"));
        window.axios = {
            get: async () => ({ data: new Blob([picture], { type }) }),
            post: async (url, form) => {
                const file = form.get("thumbnail");
                window.thumbPosts.push({ url, field: [...form.keys()], type: file.type, size: file.size });
                if (window.thumbGate) await window.thumbGate;
                if (window.thumbFail) {
                    const error = window.thumbFail;
                    window.thumbFail = null;
                    throw error;
                }
                // Each post answers with its own URL, so a test can tell which save reached the page.
                return { data: { thumbnail_url: `/thumb?v=${window.thumbPosts.length + 1}` } };
            },
        };
        const { mountThumbnailEditor } = await window.loadDamImageEditor();
        document.querySelector("#opener").focus({ preventScroll: true });
        window.destroyEditor = mountThumbnailEditor(document.querySelector("#host"), {
            assetId: 6,
            fileName: "model.glb",
            thumbnailUrl: "/thumb?v=1",
            endpoint: "/assets/6/custom-thumbnail",
            labels: {},
            onClose: () => {
                window.closeCount++;
                setTimeout(() => window.destroyEditor?.(), 0);
            },
            onSaved: (data) => {
                window.saved.push(data);
                if (window.closeOnSaved) setTimeout(() => window.destroyEditor?.(), 0);
            },
        });
    }, { type });
}

test("thumbnail editor offers one Save thumbnail action and posts only the thumbnail field", async ({ page }) => {
    await mountThumbnail(page);
    await expect(page.getByRole("dialog", { name: "Edit thumbnail" })).toBeVisible();
    await expect(page.locator(".pe-size-label")).toHaveText("320 × 240");
    await expect(button(page, "Save thumbnail")).toBeVisible();
    await expect(button(page, "Save new version")).toHaveCount(0);
    await expect(button(page, "Save as new asset")).toHaveCount(0);
    await page.keyboard.press("r");
    await drawRectangle(page);
    await button(page, "Save thumbnail").click();
    await expect.poll(() => page.evaluate(() => window.saved.length)).toBe(1);
    const posts = await page.evaluate(() => window.thumbPosts);
    expect(posts).toHaveLength(1);
    expect(posts[0]).toMatchObject({ url: "/assets/6/custom-thumbnail", field: ["thumbnail"], type: "image/png" });
    // Saved, so closing needs no discard prompt.
    await button(page, "Close").click();
    await expect.poll(() => page.evaluate(() => window.closeCount)).toBe(1);
});

test("a failed thumbnail save keeps the edits and the same Save works again", async ({ page }) => {
    await mountThumbnail(page);
    await expect(page.locator(".pe-size-label")).toHaveText("320 × 240");
    await page.keyboard.press("r");
    await drawRectangle(page);
    await page.evaluate(() => {
        window.thumbFail = { response: { status: 500, data: { message: "Could not save the thumbnail." } } };
    });
    await button(page, "Save thumbnail").click();
    await expect(page.getByRole("status").filter({ hasText: "Could not save the thumbnail." })).toBeVisible();
    expect(await page.evaluate(() => window.saved.length)).toBe(0);
    await expect(button(page, "Undo")).toBeEnabled();
    await button(page, "Save thumbnail").click();
    await expect.poll(() => page.evaluate(() => window.saved.length)).toBe(1);
    expect(await page.evaluate(() => window.thumbPosts.length)).toBe(2);
});

test("a thumbnail that is not a raster picture is not editable and Close works", async ({ page }) => {
    await mountThumbnail(page, { type: "image/svg+xml" });
    await expect(page.getByRole("alert").filter({ hasText: "This thumbnail cannot be edited." })).toBeVisible();
    await expect(page.locator(".pe-root")).toHaveCount(0);
    await button(page, "Close").click();
    await expect.poll(() => page.evaluate(() => window.closeCount)).toBe(1);
});

test("tearing the thumbnail editor down revokes its object URL and removes the overlay", async ({ page }) => {
    await mountThumbnail(page);
    await expect(page.locator(".pe-size-label")).toHaveText("320 × 240");
    await page.evaluate(() => window.destroyEditor());
    await expect(page.locator(".pe-root")).toHaveCount(0);
    expect(await page.evaluate(() => window.revoked.filter((url) => url.startsWith("blob:")).length)).toBeGreaterThan(0);
    await expect(page.locator("#opener")).toBeFocused();
});

test("the editor takes no edit while the thumbnail save is in flight, so nothing unsaved is marked saved", async ({ page }) => {
    await mountThumbnail(page);
    await expect(page.locator(".pe-size-label")).toHaveText("320 × 240");
    await page.keyboard.press("r");
    await drawRectangle(page);
    await page.evaluate(() => {
        window.thumbGate = new Promise((resolve) => (window.releaseThumb = resolve));
    });
    await button(page, "Save thumbnail").click();
    await expect.poll(() => page.evaluate(() => window.thumbPosts.length)).toBe(1);
    await page.keyboard.press("o");
    await drawRectangle(page);
    await page.evaluate(() => window.releaseThumb());
    await expect.poll(() => page.evaluate(() => window.saved.length)).toBe(1);
    // The in-flight edit was refused (the editor is busy), and the save marks the submitted snapshot, so closing is clean.
    await button(page, "Close").click();
    await expect.poll(() => page.evaluate(() => window.closeCount)).toBe(1);
    await expect(page.getByRole("dialog", { name: "Discard changes?" })).toHaveCount(0);
});

test("a thumbnail save that answers after the editor was torn down reports nothing to the page", async ({ page }) => {
    await mountThumbnail(page);
    await expect(page.locator(".pe-size-label")).toHaveText("320 × 240");
    await page.keyboard.press("r");
    await drawRectangle(page);
    await page.evaluate(() => {
        window.thumbGate = new Promise((resolve) => (window.releaseThumb = resolve));
    });
    await button(page, "Save thumbnail").click();
    await expect.poll(() => page.evaluate(() => window.thumbPosts.length)).toBe(1);
    await page.evaluate(() => window.destroyEditor());
    await expect(page.getByRole("dialog")).toHaveCount(0);
    await page.evaluate(() => window.releaseThumb());
    await page.waitForTimeout(300);
    expect(await page.evaluate(() => [window.saved.length, window.closeCount])).toEqual([0, 0]);
});

test("edits made while a thumbnail save is in flight keep the editor open; the save reaches the page when it closes", async ({ page }) => {
    await mountThumbnail(page);
    await expect(page.locator(".pe-size-label")).toHaveText("320 × 240");
    await editWhileSaving(page, {
        saveName: "Save thumbnail",
        hold: () => {
            window.thumbGate = new Promise((resolve) => (window.releaseThumb = resolve));
        },
        release: () => window.releaseThumb(),
        posted: () => window.thumbPosts.length,
        closeCount: () => window.closeCount,
        rotatedSize: "240 × 320",
    });
});

/** The two editors' save fixtures, for the held-save lifecycle checks below. */
const SAVE_MODES = {
    image: {
        open: (page) => mount(page),
        saveName: "Save new version",
        hold: () => {
            window.saveGate = new Promise((resolve) => (window.releaseSave = resolve));
        },
        release: () => window.releaseSave(),
        posted: () => window.requests.length,
        closeCount: () => window.editorCloseCount,
        failNext: () => {
            window.nextSaveError = { response: { status: 500, data: { message: "Could not save." } } };
        },
        // Version 2 was the first save; the failed save moved nothing.
        reportedLatest: () => window.saved[0]?.version === 3,
    },
    thumbnail: {
        open: async (page) => {
            await mountThumbnail(page);
            await expect(page.locator(".pe-size-label")).toHaveText("320 × 240");
        },
        saveName: "Save thumbnail",
        hold: () => {
            window.thumbGate = new Promise((resolve) => (window.releaseThumb = resolve));
        },
        release: () => window.releaseThumb(),
        posted: () => window.thumbPosts.length,
        closeCount: () => window.closeCount,
        failNext: () => {
            window.thumbFail = { response: { status: 500, data: { message: "Could not save." } } };
        },
        reportedLatest: () => window.saved[0]?.thumbnail_url === "/thumb?v=4",
    },
};

for (const [name, m] of Object.entries(SAVE_MODES)) {
    // Crop & resize keeps its draft outside the document until Crop is pressed, so the editor is clean but not idle.
    test(`${name} editor: a crop opened while a save is in flight stays open with its draft; the save reaches the page on close`, async ({ page }) => {
        await m.open(page);
        await page.keyboard.press("r");
        await drawRectangle(page);
        await page.evaluate(m.hold);
        await button(page, m.saveName).click();
        await expect.poll(() => page.evaluate(m.posted)).toBe(1);
        await button(page, "Crop & resize").click();
        const width = page.getByLabel("Crop width");
        await width.fill("150");
        await width.dispatchEvent("change");
        await page.evaluate(m.release);
        await expect(page.locator(".pe-toast")).toBeVisible();
        await page.waitForTimeout(300);
        expect(await page.evaluate(() => window.saved.length)).toBe(0);
        await expect(width).toHaveValue("150");
        await button(page, "Cancel").click();
        await button(page, "Close").click();
        await expect.poll(() => page.evaluate(() => window.saved.length)).toBe(1);
        expect(await page.evaluate(m.closeCount)).toBe(0);
    });

    test(`${name} editor: a save kept back for unsaved edits survives Keep editing and a failed save, and a later save reports its own result`, async ({ page }) => {
        await m.open(page);
        await page.evaluate(() => (window.closeOnSaved = true));
        await page.keyboard.press("r");
        await drawRectangle(page);
        await page.evaluate(m.hold);
        await button(page, m.saveName).click();
        await expect.poll(() => page.evaluate(m.posted)).toBe(1);
        await page.keyboard.press("ControlOrMeta+z");
        await page.evaluate(m.release);
        await expect(page.locator(".pe-toast")).toBeVisible();
        // Keep editing reports nothing.
        await button(page, "Close").click();
        await page.getByRole("dialog", { name: "Discard changes?" }).getByRole("button", { name: "Keep editing", exact: true }).click();
        await page.waitForTimeout(300);
        expect(await page.evaluate(() => window.saved.length)).toBe(0);
        // A failed save keeps the edits and reports nothing.
        await page.evaluate(m.failNext);
        await button(page, m.saveName).click();
        await expect(page.getByRole("status").filter({ hasText: "Could not save." })).toBeVisible();
        expect(await page.evaluate(() => window.saved.length)).toBe(0);
        // Saving everything reports that save at once; the page then closes the editor, and nothing else follows.
        await button(page, m.saveName).click();
        await expect.poll(() => page.evaluate(() => window.saved.length)).toBe(1);
        expect(await page.evaluate(m.reportedLatest)).toBe(true);
        await expect(page.locator(".pe-root")).toHaveCount(0);
        await page.waitForTimeout(300);
        expect(await page.evaluate(() => window.saved.length)).toBe(1);
        expect(await page.evaluate(m.closeCount)).toBe(0);
    });

    test(`${name} editor: tearing down with a kept-back save and another in flight reports nothing to the page`, async ({ page }) => {
        await m.open(page);
        await page.keyboard.press("r");
        await drawRectangle(page);
        await page.evaluate(m.hold);
        await button(page, m.saveName).click();
        await expect.poll(() => page.evaluate(m.posted)).toBe(1);
        await page.keyboard.press("ControlOrMeta+z");
        await page.evaluate(m.release);
        await expect(page.locator(".pe-toast")).toBeVisible();
        await page.evaluate(m.hold);
        await button(page, m.saveName).click();
        await expect.poll(() => page.evaluate(m.posted)).toBe(2);
        await page.evaluate(() => window.destroyEditor());
        await expect(page.getByRole("dialog")).toHaveCount(0);
        await page.evaluate(m.release);
        await page.waitForTimeout(300);
        expect(await page.evaluate(() => window.saved.length)).toBe(0);
        expect(await page.evaluate(m.closeCount)).toBe(0);
    });

    // Fields take their value on change; the save's answer redraws the bars while the person is still typing in one.
    test(`${name} editor: a Crop width still being typed when the save answers is kept and can be applied`, async ({ page }) => {
        await m.open(page);
        await page.evaluate(() => (window.closeOnSaved = true));
        await page.keyboard.press("r");
        await drawRectangle(page);
        await page.evaluate(m.hold);
        await button(page, m.saveName).click();
        await expect.poll(() => page.evaluate(m.posted)).toBe(1);
        await button(page, "Crop & resize").click();
        const width = page.getByLabel("Crop width");
        await width.fill("150");
        await expect(width).toBeFocused();
        await page.evaluate(m.release);
        await expect(page.locator(".pe-toast")).toBeVisible();
        await page.waitForTimeout(300);
        expect(await page.evaluate(() => window.saved.length)).toBe(0);
        await expect(width).toHaveValue("150");
        await button(page, "Crop").click();
        await expect(page.locator(".pe-size-label")).toHaveText(/^150 × \d+$/);
        await button(page, "Close").click();
        await page.getByRole("dialog", { name: "Discard changes?" }).getByRole("button", { name: "Discard", exact: true }).click();
        await expect.poll(() => page.evaluate(() => window.saved.length)).toBe(1);
        await expect(page.locator(".pe-root")).toHaveCount(0);
        expect(await page.evaluate(m.closeCount)).toBe(0);
    });

    test(`${name} editor: a Hex color still being typed when the save answers is kept as an unsaved edit`, async ({ page }) => {
        await m.open(page);
        await page.evaluate(() => (window.closeOnSaved = true));
        await page.keyboard.press("r");
        await drawRectangle(page);
        await page.evaluate(m.hold);
        await button(page, m.saveName).click();
        await expect.poll(() => page.evaluate(m.posted)).toBe(1);
        await button(page, "Color").click();
        const hex = page.getByLabel("Hex color");
        await hex.fill("#00aaff");
        await expect(hex).toBeFocused();
        await page.evaluate(m.release);
        await expect(page.locator(".pe-toast")).toBeVisible();
        await page.waitForTimeout(300);
        expect(await page.evaluate(() => window.saved.length)).toBe(0);
        expect(await lastColor(page)).toBe("#00aaff");
        await button(page, "Close").click();
        await page.getByRole("dialog", { name: "Discard changes?" }).getByRole("button", { name: "Discard", exact: true }).click();
        await expect.poll(() => page.evaluate(() => window.saved.length)).toBe(1);
        await expect(page.locator(".pe-root")).toHaveCount(0);
        expect(await page.evaluate(m.closeCount)).toBe(0);
    });

    for (const field of ["Crop width", "Hex color"]) {
        test(`${name} editor: a ${field} still being typed when the save fails is kept, the error shows and the page hears nothing`, async ({ page }) => {
            const crop = field === "Crop width";
            await m.open(page);
            await page.evaluate(() => (window.closeOnSaved = true));
            await page.keyboard.press("r");
            await drawRectangle(page);
            await page.evaluate(m.hold);
            await page.evaluate(m.failNext);
            await button(page, m.saveName).click();
            await expect.poll(() => page.evaluate(m.posted)).toBe(1);
            await button(page, crop ? "Crop & resize" : "Color").click();
            const input = page.getByLabel(field);
            await input.fill(crop ? "150" : "#00aaff");
            await expect(input).toBeFocused();
            await page.evaluate(m.release);
            await expect(page.getByRole("status").filter({ hasText: "Could not save." })).toBeVisible();
            await page.waitForTimeout(300);
            expect(await page.evaluate(() => window.saved.length)).toBe(0);
            expect(await page.evaluate(m.closeCount)).toBe(0);
            if (crop) {
                await expect(input).toHaveValue("150");
                await button(page, "Crop").click();
                await expect(page.locator(".pe-size-label")).toHaveText(/^150 × \d+$/);
            } else {
                expect(await lastColor(page)).toBe("#00aaff");
            }
        });
    }

    test(`UPSTREAM-DEVIATION: ${name} editor: after Add image the save action works again and saves the image`, async ({ page }) => {
        await m.open(page);
        await page.locator('.pe-root input[type="file"]').setInputFiles(PNG_FILE);
        await expect.poll(() => objectTypes(page)).toContain("image");
        await expect(button(page, m.saveName)).toBeEnabled();
        await button(page, m.saveName).click();
        await expect.poll(() => page.evaluate(() => window.saved.length)).toBe(1);
        if (name === "image") {
            const saved = await page.evaluate(() => window.requests.filter((r) => r.url.endsWith("/save")).at(-1).document);
            expect(saved.objects.filter((o) => o.type === "image").map((o) => o.resource)).toEqual(["upload-1"]);
        }
    });

    test(`UPSTREAM-DEVIATION: ${name} editor: Shift+Cmd+[ / ] send to back and bring to front, Cmd+[ / ] still move one step`, async ({ page }) => {
        await m.open(page);
        await page.keyboard.press("r");
        const box = await page.locator(".pe-stage canvas").boundingBox();
        for (const [x, y] of [[0.1, 0.1], [0.4, 0.4], [0.7, 0.1]]) {
            await page.mouse.move(box.x + box.width * x, box.y + box.height * y);
            await page.mouse.down();
            await page.mouse.move(box.x + box.width * (x + 0.2), box.y + box.height * (y + 0.2), { steps: 4 });
            await page.mouse.up();
        }
        const [a, b, c] = (await liveObjects(page)).map((o) => o.id);
        const order = async () => (await liveObjects(page)).map((o) => o.id);
        // A shifted bracket key reports "{" / "}" (US layout); some layouts and browsers report "[" / "]" with Shift.
        const shiftedBracket = (key) =>
            page.evaluate((key) => {
                const init = { key, shiftKey: true, metaKey: true, ctrlKey: true, bubbles: true, cancelable: true };
                document.querySelector(".pe-root").dispatchEvent(new KeyboardEvent("keydown", init));
            }, key);

        await page.keyboard.press("ControlOrMeta+Shift+BracketLeft");
        expect(await order()).toEqual([c, a, b]);
        await page.keyboard.press("ControlOrMeta+BracketRight");
        expect(await order()).toEqual([a, c, b]);
        await page.keyboard.press("ControlOrMeta+Shift+BracketRight");
        expect(await order()).toEqual([a, b, c]);
        await page.keyboard.press("ControlOrMeta+BracketLeft");
        expect(await order()).toEqual([a, c, b]);
        await shiftedBracket("[");
        expect(await order()).toEqual([c, a, b]);
        await shiftedBracket("]");
        expect(await order()).toEqual([a, b, c]);
        await shiftedBracket("{");
        expect(await order()).toEqual([c, a, b]);
        await shiftedBracket("}");
        expect(await order()).toEqual([a, b, c]);
        expect(await objectTypes(page)).toEqual(["rect", "rect", "rect"]);
    });
}

test("UPSTREAM-DEVIATION: a menu closed before its outside-click listener is armed does not close the next menu", async ({ page }) => {
    await mount(page);
    await page.keyboard.press("r");
    // Open and close the Thickness menu within one task, before its listener registration timer runs.
    await page.evaluate(() => {
        /** @type {HTMLElement} */ (document.querySelector('.pe-root [aria-label="Thickness"]')).click();
        document.querySelector(".pe-popover").dispatchEvent(new KeyboardEvent("keydown", { key: "Escape", bubbles: true }));
    });
    await page.waitForTimeout(100);
    await button(page, "Thickness").click();
    await page.getByRole("menuitemradio", { name: "13 px" }).click({ timeout: 3000 });
    await expect(page.locator(".pe-popover")).toHaveCount(0);
    await drawRectangle(page);
    expect(await page.evaluate(() => /** @type {any} */ (document.querySelector(".pe-root")).__photoedit.getDocument().objects.at(-1).thickness)).toBe(13);
});

test("UPSTREAM-DEVIATION: Add to My Colors after typing a hex reopens the palette under the Color button", async ({ page }) => {
    await mount(page);
    await page.keyboard.press("r");
    await drawRectangle(page);
    await button(page, "Color").click();
    await page.getByLabel("Hex color").fill("#00aaff");
    await button(page, "Add to My Colors").click();
    const palette = page.locator(".pe-popover");
    await expect(palette.getByRole("button", { name: "#00aaff", exact: true })).toBeVisible();
    const p = await palette.boundingBox();
    const b = await button(page, "Color").boundingBox();
    expect(Math.abs(p.y - (b.y + b.height + 6))).toBeLessThan(2);
    expect(p.x < b.x + b.width && p.x + p.width > b.x).toBe(true);
    expect(await lastColor(page)).toBe("#00aaff");
});

test("an image upload that answers while a Hex color is being typed keeps the color and adds the image", async ({ page }) => {
    await mount(page);
    await page.keyboard.press("r");
    await drawRectangle(page);
    await page.evaluate(() => {
        window.resourceGate = new Promise((resolve) => (window.releaseResource = resolve));
    });
    await page.locator('.pe-root input[type="file"]').setInputFiles(PNG_FILE);
    await expect.poll(() => page.evaluate(() => window.requests.length)).toBe(1);
    await button(page, "Color").click();
    const hex = page.getByLabel("Hex color");
    await hex.fill("#00aaff");
    await expect(hex).toBeFocused();
    await page.evaluate(() => window.releaseResource());
    await expect.poll(() => objectTypes(page)).toContain("image");
    const rect = await page.evaluate(() => /** @type {any} */ (document.querySelector(".pe-root")).__photoedit.getDocument().objects.filter((o) => o.type === "rect").at(-1));
    expect(rect.color).toBe("#00aaff");
    await expect(button(page, "Save new version")).toBeEnabled();
});

test("an image upload that answers after teardown leaves a field the person is typing in on the page alone", async ({ page }) => {
    await mount(page);
    await page.evaluate(() => {
        window.resourceGate = new Promise((resolve) => (window.releaseResource = resolve));
    });
    await page.locator('.pe-root input[type="file"]').setInputFiles(PNG_FILE);
    await expect.poll(() => page.evaluate(() => window.requests.length)).toBe(1);
    await page.evaluate(() => window.destroyEditor());
    await expect(page.locator(".pe-root")).toHaveCount(0);
    await page.evaluate(() => document.body.append(Object.assign(document.createElement("input"), { id: "page-field" })));
    const field = page.locator("#page-field");
    await field.fill("typed on the page");
    await page.evaluate(() => window.releaseResource());
    await page.waitForTimeout(300);
    await expect(field).toBeFocused();
    await expect(field).toHaveValue("typed on the page");
});

test("the focused menu item keeps PhotoEdit's colors under the page's menu-item focus tint", async ({ page }) => {
    await mount(page);
    // The asset page's rule (Admin app.css): a pale primary-50 behind PhotoEdit's white focused-item text.
    await page.addStyleTag({ content: '[role="menu"] [role="menuitem"]:focus-visible:not(.no-focus-ring){background-color:rgb(252 241 241);outline-offset:-2px}' });
    await page.keyboard.press("f");
    await drawRectangle(page);
    const box = await page.locator(".pe-stage canvas").boundingBox();
    await page.keyboard.press("v");
    await page.mouse.click(box.x + box.width * 0.42, box.y + box.height * 0.4, { button: "right" });
    const first = page.locator(".pe-popover .pe-menu-item").first();
    await expect(first).toBeFocused();
    const look = await first.evaluate((el) => ({ bg: getComputedStyle(el).backgroundColor, color: getComputedStyle(el).color }));
    expect(look).toEqual({ bg: "rgb(47, 123, 246)", color: "rgb(255, 255, 255)" });
});
