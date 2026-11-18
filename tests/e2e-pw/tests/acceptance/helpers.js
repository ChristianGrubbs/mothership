const { expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const ASSETS = path.resolve(__dirname, '../../assets');

const MIME = { '.jpg': 'image/jpeg', '.jpeg': 'image/jpeg', '.png': 'image/png', '.mp4': 'video/mp4', '.pdf': 'application/pdf' };

/**
 * Laravel's SPA-CSRF cookie, read back from the authenticated session so the seed
 * requests below ride the same login the specs use (no API credential needed).
 */
async function xsrfToken(page) {
    if (page.url() === 'about:blank') {
        await page.goto('/admin/dam/assets');
    }

    const token = await page.evaluate(() => {
        const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);
        return match ? decodeURIComponent(match[1]) : null;
    });
    expect(token, 'XSRF-TOKEN cookie must exist after the global-setup login').not.toBeNull();

    return token;
}

/**
 * Seeds one asset through the Library upload endpoint (admin.dam.assets.upload) and
 * returns { id, path }. Each call uploads under a unique file name so reruns against the
 * same isolated database never collide with an earlier run's asset.
 */
async function uploadAsset(page, { source, name, buffer, directoryId }) {
    directoryId ??= await createDirectory(page, 'acceptance-upload');
    const ext = path.extname(name || source).toLowerCase();
    const fileName = name || `acceptance-${Date.now()}-${Math.random().toString(36).slice(2, 8)}${ext}`;
    const response = await page.request.post('/admin/dam/assets/upload', {
        headers: { 'X-XSRF-TOKEN': await xsrfToken(page), Accept: 'application/json' },
        multipart: {
            directory_id: String(directoryId),
            'files[]': {
                name: fileName,
                mimeType: MIME[ext] || 'application/octet-stream',
                buffer: buffer || fs.readFileSync(path.join(ASSETS, source)),
            },
        },
    });

    expect(response.status(), `upload responded ${response.status()}: ${(await response.text()).slice(0, 400)}`).toBe(201);
    const body = await response.json();
    expect(body.success).toBe(true);
    expect(body.files, 'upload must return exactly one published asset').toHaveLength(1);
    const asset = body.files[0];
    expect(asset.id).toBeGreaterThan(0);
    expect(asset.path).toEqual(expect.any(String));

    return { id: asset.id, path: asset.path, fileName };
}

/** A fresh folder per spec, so Library order and details-page neighbours are exactly the spec's own assets. */
async function createDirectory(page, name) {
    const headers = { 'X-XSRF-TOKEN': await xsrfToken(page), Accept: 'application/json' };
    const tree = await page.request.get('/admin/dam/directory', { headers });
    expect(tree.status(), 'directory tree must load before creating fixtures').toBe(200);
    const roots = (await tree.json()).data;
    const root = roots.find((directory) => directory.parent_id === null && directory.name === 'Root');
    expect(root, 'the isolated app must have a seeded Root directory').toBeTruthy();
    const response = await page.request.post('/admin/dam/directory/store', {
        headers,
        data: { name: `${name}-${Date.now()}-${Math.random().toString(36).slice(2, 6)}`, parent_id: root.id },
    });
    expect(response.ok(), `directory store responded ${response.status()}: ${(await response.text()).slice(0, 400)}`).toBeTruthy();

    return (await response.json()).data.id;
}

/**
 * A solid-colour JPEG drawn in the browser, so specs need no binary fixtures and can tell
 * their assets apart by pixel colour. A 2:1 size is classified as an equirectangular panorama.
 */
async function solidJpeg(page, { width, height, rgb }) {
    const base64 = await page.evaluate(({ width, height, rgb }) => {
        const canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = height;
        const context = canvas.getContext('2d');
        context.fillStyle = `rgb(${rgb.join(',')})`;
        context.fillRect(0, 0, width, height);

        return canvas.toDataURL('image/jpeg', 0.9).split(',')[1];
    }, { width, height, rgb });

    return Buffer.from(base64, 'base64');
}

/** Attaches another file to an asset (admin.dam.assets.files.store), which makes it a stack. */
async function attachFile(page, assetId, { name, buffer, role = 'alternate' }) {
    const response = await page.request.post(`/admin/dam/assets/${assetId}/files`, {
        headers: { 'X-XSRF-TOKEN': await xsrfToken(page), Accept: 'application/json' },
        multipart: { role, file: { name, mimeType: MIME[path.extname(name).toLowerCase()] || 'application/octet-stream', buffer } },
    });
    expect(response.status(), `attach responded ${response.status()}: ${(await response.text()).slice(0, 400)}`).toBe(201);

    return response.json();
}

/** Polls until the queued upload processing has classified the asset as a panorama. */
async function waitForPanorama(page, assetId) {
    await expect.poll(async () => {
        const response = await page.request.get(`/admin/dam/assets/${assetId}/panorama-preview`, { headers: { Accept: 'application/json' } });
        if (! response.ok()) return `http ${response.status()}`;
        const body = await response.json();
        const state = body.state ?? body.data?.state ?? body;

        return state?.classification?.is_panorama === true;
    }, { message: `asset ${assetId} never classified as a panorama (is the queue worker running?)`, timeout: 60_000, intervals: [1_000] }).toBe(true);
}

/** A 256x256 PNG of four coloured quadrants, drawn in the page: a real image the apps can decode and a canvas can tell from blank. */
async function quadrantsPng(page) {
    const bytes = await page.evaluate(async () => {
        const canvas = Object.assign(document.createElement('canvas'), { width: 256, height: 256 });
        const ctx = canvas.getContext('2d');
        [['#f00', 0, 0], ['#0c0', 128, 0], ['#00f', 0, 128], ['#fd0', 128, 128]].forEach(([c, x, y]) => { ctx.fillStyle = c; ctx.fillRect(x, y, 128, 128); });
        const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/png'));
        return Array.from(new Uint8Array(await blob.arrayBuffer()));
    });
    return Buffer.from(bytes);
}

// A valid two-page PDF (real cross-reference table) whose pages carry different text, so a viewer that opens it can be
// asked for page 1's words and for a count of 2. Built here so no binary fixture is committed.
function twoPagePdf() {
    const stream = (text) => `BT /F1 36 Tf 72 700 Td (${text}) Tj ET`;
    const objects = [
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R 5 0 R] /Count 2 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 7 0 R >> >> >>',
        `<< /Length ${stream('Handoff page one').length} >>\nstream\n${stream('Handoff page one')}\nendstream`,
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 6 0 R /Resources << /Font << /F1 7 0 R >> >> >>',
        `<< /Length ${stream('Handoff page two').length} >>\nstream\n${stream('Handoff page two')}\nendstream`,
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
    ];
    let body = '%PDF-1.4\n';
    const offsets = objects.map((object, index) => {
        const offset = body.length;
        body += `${index + 1} 0 obj\n${object}\nendobj\n`;

        return offset;
    });
    const xref = body.length;
    body += `xref\n0 ${objects.length + 1}\n0000000000 65535 f \n${offsets.map((o) => `${String(o).padStart(10, '0')} 00000 n \n`).join('')}`;
    body += `trailer\n<< /Size ${objects.length + 1} /Root 1 0 R >>\nstartxref\n${xref}\n%%EOF\n`;

    return Buffer.from(body, 'latin1');
}

const editUrl = (id) => `/admin/dam/assets/edit/${id}`;

/** Holds every response matching `pattern` for `ms` before letting it through. */
async function delayResponses(page, pattern, ms) {
    await page.route(pattern, async (route) => {
        const response = await route.fetch();
        await new Promise((resolve) => setTimeout(resolve, ms));
        await route.fulfill({ response });
    });
}

/** No horizontal page scroll: the layout must fit the viewport at every width. */
async function expectNoHorizontalScroll(page) {
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect(overflow, 'page scrolls horizontally').toBeLessThanOrEqual(1);
}

module.exports = {
    uploadAsset, editUrl, delayResponses, expectNoHorizontalScroll, xsrfToken, createDirectory, solidJpeg, attachFile, waitForPanorama, quadrantsPng, twoPagePdf,
};
