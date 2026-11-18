const { test, expect } = require('@playwright/test');
const { uploadAsset, editUrl, solidJpeg, createDirectory, waitForPanorama } = require('./helpers');

/**
 * Panorama A -> B -> A on the asset details page, with B's media and panorama state held
 * back by a slow network. The person steps to B and straight back to A before B has
 * arrived; when B's late responses finally land they must not repaint A's page with B.
 *
 * A is red and B is blue (2:1 JPEGs, classified as equirectangular panoramas by the real
 * upload processing), so the check reads the actual preview and viewer pixels, not only
 * the URL. Both live in a fresh folder, so the details page's Previous/Next walk exactly
 * these two assets.
 */
const DELAY_MS = 4_000;

const isRed = ([r, g, b]) => r > 150 && g < 90 && b < 90;
const isBlue = ([r, g, b]) => b > 150 && r < 90 && g < 90;

/** Average colour of the centre of a rendered <img>, read back through a canvas. */
const centreColour = (locator) => locator.evaluate(async (img) => {
    if (! img.complete) await new Promise((resolve) => img.addEventListener('load', resolve, { once: true }));
    const canvas = document.createElement('canvas');
    canvas.width = 8;
    canvas.height = 8;
    const context = canvas.getContext('2d');
    context.drawImage(img, img.naturalWidth / 2 - 4, img.naturalHeight / 2 - 4, 8, 8, 0, 0, 8, 8);
    const data = context.getImageData(0, 0, 8, 8).data;
    const sum = [0, 0, 0];
    for (let i = 0; i < data.length; i += 4) { sum[0] += data[i]; sum[1] += data[i + 1]; sum[2] += data[i + 2]; }

    return sum.map((value) => Math.round(value / 64));
});

/* WebGL clears its drawing buffer after compositing; keep it so readPixels sees the frame. */
const preserveWebGlDrawingBuffer = (page) => page.addInitScript(() => {
    const getContext = window.HTMLCanvasElement.prototype.getContext;
    window.HTMLCanvasElement.prototype.getContext = function (type, attributes) {
        const contextAttributes = ['webgl', 'webgl2'].includes(type) ? { ...(attributes || {}), preserveDrawingBuffer: true } : attributes;

        return getContext.call(this, type, contextAttributes);
    };
});

/** Share of a 3x3 sample grid of the viewer's rendered WebGL frame that is red (A) and blue (B). */
const viewerColours = (canvas) => canvas.evaluate((element) => {
    const gl = element.getContext('webgl2') || element.getContext('webgl');
    if (! gl || element.width < 2 || element.height < 2) return { red: 0, blue: 0, sampled: 0 };
    const pixel = new Uint8Array(4);
    let red = 0;
    let blue = 0;
    let sampled = 0;
    for (const x of [0.25, 0.5, 0.75]) {
        for (const y of [0.25, 0.5, 0.75]) {
            gl.readPixels(Math.floor((element.width - 1) * x), Math.floor((element.height - 1) * y), 1, 1, gl.RGBA, gl.UNSIGNED_BYTE, pixel);
            sampled += 1;
            if (pixel[0] > 150 && pixel[1] < 90 && pixel[2] < 90) red += 1;
            if (pixel[2] > 150 && pixel[0] < 90 && pixel[1] < 90) blue += 1;
        }
    }

    return { red: red / sampled, blue: blue / sampled, sampled };
});

test('panorama A -> B -> A under a slow network shows A, not B\'s late responses', async ({ page }) => {
    await preserveWebGlDrawingBuffer(page);
    await page.goto('/admin/dam/assets');
    const directoryId = await createDirectory(page, 'acceptance-pano');
    const a = await uploadAsset(page, {
        name: `acceptance-pano-a-${Date.now()}.jpg`, directoryId,
        buffer: await solidJpeg(page, { width: 2048, height: 1024, rgb: [220, 30, 30] }),
    });
    const b = await uploadAsset(page, {
        name: `acceptance-pano-b-${Date.now()}.jpg`, directoryId,
        buffer: await solidJpeg(page, { width: 2048, height: 1024, rgb: [30, 30, 220] }),
    });
    await waitForPanorama(page, a.id);
    await waitForPanorama(page, b.id);

    // Everything that belongs to B except its details (show/{id}, which the page awaits before it
    // allows the next hop) arrives DELAY_MS late: its media, its panorama state and the per-asset
    // side panels. A is served normally. The preview URL double-encodes the path, so B's media is
    // matched by its unique file name.
    const bOnly = new RegExp(`/admin/dam/(assets/(ai-tags|vectorization|optimization)/${b.id}$|assets/${b.id}/panorama-preview$|similar-images\\?asset=${b.id}&)`);
    let lateResponses = 0;
    await page.route((url) => url.href.includes(b.fileName) || bOnly.test(url.pathname + url.search), async (route) => {
        const response = await route.fetch().catch(() => null);
        await new Promise((resolve) => setTimeout(resolve, DELAY_MS));
        lateResponses += 1;
        await (response ? route.fulfill({ response }) : route.abort()).catch(() => {});
    });

    await page.goto(`${editUrl(a.id)}?directory_id=${directoryId}`);
    const frame = page.locator('[data-preview-frame="pano360"]');
    const preview = frame.locator('img');
    await expect(frame).toBeVisible();
    await expect.poll(() => centreColour(preview).then(isRed), { message: 'A should open red' }).toBe(true);

    // With two assets in the folder exactly one direction leads to B.
    await frame.hover();
    const next = page.getByRole('button', { name: 'Next', exact: true });
    const previous = page.getByRole('button', { name: 'Previous', exact: true });
    const [toB, toA] = (await next.count()) > 0 && await next.first().isEnabled() ? [next, previous] : [previous, next];

    await toB.first().click({ force: true });
    await expect(page).toHaveURL(new RegExp(`/admin/dam/assets/edit/${b.id}`));
    await frame.hover();
    await toA.first().click({ force: true });
    await expect(page).toHaveURL(new RegExp(`/admin/dam/assets/edit/${a.id}`));

    // Let every held-back B response land, then check nothing of B reached A's page.
    await page.waitForTimeout(DELAY_MS + 1_500);
    expect(lateResponses, 'the slow-network route never fired; the race was not exercised').toBeGreaterThan(0);
    await expect(page).toHaveURL(new RegExp(`/admin/dam/assets/edit/${a.id}`));
    await expect(page.getByText(a.fileName).first()).toBeVisible();
    expect(await preview.getAttribute('src'), 'card still points at B').not.toContain(b.fileName);
    const cardColour = await centreColour(preview);
    expect(isBlue(cardColour), `card shows B's colour ${cardColour}`).toBe(false);
    expect(isRed(cardColour), `card colour ${cardColour}`).toBe(true);

    // The interactive viewer opened now must load A's equirect, not B's.
    await frame.click();
    const viewer = page.locator('[data-dam-360-viewer]').first();
    await expect(viewer).toBeVisible();
    const sources = await page.locator('[data-dam-360-viewer] img, [data-dam-preview-backdrop] img').evaluateAll(
        (images) => images.map((img) => img.currentSrc || img.src).filter(Boolean),
    );
    expect(sources.some((src) => src.includes(b.fileName)), `viewer sources ${sources.join(', ')}`).toBe(false);

    // And what it actually draws: A's red equirect in the WebGL frame, never B's blue, with no
    // loading or error state left over (a black, stale or failed canvas must not pass).
    const canvas = viewer.locator('canvas').first();
    await expect(canvas).toBeVisible();
    await expect.poll(async () => (await viewerColours(canvas)).red, { message: 'the 360 viewer never rendered A', timeout: 20_000 })
        .toBeGreaterThanOrEqual(0.5);
    expect((await viewerColours(canvas)).blue, 'the 360 viewer rendered B').toBe(0);
    // Inside the viewer, role=status is the loading overlay (data-dam-360-loading, opaque, so it
    // would hide a correct framebuffer from the person) or the error banner; captions aside, none may remain.
    await expect(page.locator('[data-dam-360-viewer] [role="status"]:not([data-dam-360-caption-overlay])')).toHaveCount(0);
});
