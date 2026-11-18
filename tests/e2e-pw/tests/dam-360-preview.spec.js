const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

/**
 * The on-demand 360 preview is a state machine the server owns and the browser
 * renders: Build -> Queued -> Processing N% -> the interactive viewer, with
 * Cancel/Retry off to the side. These specs drive that machine end to end
 * against a deployed branch and seeded fixtures, because the parts that can
 * actually break — the poll loop, the automatic hand-off into Three.js, the
 * browser's own codec verdict, and byte-range playback — have no server-side
 * equivalent to assert.
 *
 * Fixture ids arrive as environment variables so the same spec runs against
 * any seeded environment:
 *   PANORAMA_ASSET_ID        an OSV needing a generated proxy
 *   PANORAMA_DIRECT_VIDEO_ID an H.264 equirect the browser plays directly
 *   PANORAMA_FORCE_FLAT_ID   an asset whose panorama_mode is force_flat
 *   PANORAMA_PROXY_ID        an asset with a completed 360 proxy attached
 *
 * The environment must also run with MOTHERSHIP_360_PROCESSING_ENABLED=true
 * and an account holding dam.asset.edit: the build controls are gated on
 * processing_enabled and can_edit, both of which arrive in the server-rendered
 * page state and cannot be mocked from here.
 */

/* The status strip renders on two surfaces — the inline card and, with the
 * modal open on a non-playable asset, the fallback panel. Both can be on
 * screen at once, so every locator names its surface or strict mode throws. */
const CARD_STATUS = '[data-dam-panorama-status="card"]';

const MODAL = '[data-dam-preview-backdrop]';

const VIEWER = '[data-dam-360-viewer]';

/* A real one-second H.264 MP4 (ffmpeg testsrc, 320x160). The mocked "done"
 * state points playback at a URL the app itself does not serve, and an
 * unrouted src makes the <video> element raise MEDIA_ERR_SRC_NOT_SUPPORTED —
 * which the viewer treats as a load failure and, once reachable, a rejection.
 * Serving real bytes keeps the success path a success path. */
const PANO_MP4 = fs.readFileSync(path.resolve(__dirname, '../assets/pano-1s.mp4'));

const servePanoFixture = (page, pattern = '**/fixture/pano.mp4') => page.route(
    pattern,
    (route) => route.fulfill({ status: 200, contentType: 'video/mp4', body: PANO_MP4 }),
);

const preserveWebGlDrawingBuffer = (page) => page.addInitScript(() => {
    const getContext = window.HTMLCanvasElement.prototype.getContext;

    window.HTMLCanvasElement.prototype.getContext = function (type, attributes) {
        const contextAttributes = ['webgl', 'webgl2'].includes(type)
            ? { ...(attributes || {}), preserveDrawingBuffer: true }
            : attributes;

        return getContext.call(this, type, contextAttributes);
    };
});

const renderedPixelRatio = (canvas) => canvas.evaluate((element) => {
    const gl = element.getContext('webgl2') || element.getContext('webgl');

    if (! gl || element.width < 2 || element.height < 2) return 0;

    const sample = new Uint8Array(4);
    let rendered = 0;
    let total = 0;

    for (const xFraction of [0.25, 0.5, 0.75]) {
        for (const yFraction of [0.25, 0.5, 0.75]) {
            const x = Math.floor((element.width - 1) * xFraction);
            const y = Math.floor((element.height - 1) * yFraction);
            gl.readPixels(x, y, 1, 1, gl.RGBA, gl.UNSIGNED_BYTE, sample);
            rendered += sample[0] + sample[1] + sample[2] > 12 ? 1 : 0;
            total += 1;
        }
    }

    return rendered / total;
});

const requireAsset = (name) => {
    const id = process.env[name];

    if (! id) {
        throw new Error(`${name} must name the seeded 360 fixture`);
    }

    return id;
};

const editUrl = (id) => `/admin/dam/assets/edit/${id}`;

/**
 * The full shape PanoramaPreviewService::state() returns. The component gates
 * the whole strip on classification.is_panorama and each button on
 * processing_enabled / can_edit / actions.*, so a payload carrying only
 * rendition + playback would unmount everything under test.
 */
const panoramaState = ({ rendition, playback = null, actions = {} }) => ({
    classification: {
        mode: 'auto',
        is_panorama: true,
        reason: 'osv',
        media_type: 'video',
        direct_candidate: false,
        requires_proxy: true,
    },
    playback,
    processing_enabled: true,
    can_edit: true,
    rendition,
    actions: {
        can_build: ! playback,
        can_rebuild: !! playback,
        can_cancel: ['queued', 'running'].includes(rendition?.status),
        can_retry: ['failed', 'cancelled'].includes(rendition?.status),
        ...actions,
    },
});

test('moves from build through progress into the 360 viewer without reload', async ({ page }) => {
    const assetId = requireAsset('PANORAMA_ASSET_ID');

    // The build POST and the state GET share a URL, so this handler answers
    // both; the first reply is the POST's. 48% is held for several ticks so
    // the progress and Cancel assertions are not racing the next transition.
    let poll = 0;
    const states = [
        panoramaState({ rendition: { id: 1, status: 'queued', progress: 0 } }),
        panoramaState({ rendition: { id: 1, status: 'running', progress: 48 } }),
        panoramaState({ rendition: { id: 1, status: 'running', progress: 48 } }),
        panoramaState({ rendition: { id: 1, status: 'running', progress: 48 } }),
        panoramaState({
            rendition: { id: 1, status: 'done', progress: 100 },
            playback: {
                url: '/fixture/pano.mp4',
                mime: 'video/mp4',
                kind: 'pano360-video',
                label: 'Generated 360 Preview (4K)',
            },
        }),
    ];

    await page.route(`**/admin/dam/assets/${assetId}/panorama-preview`, async (route) => {
        await route.fulfill({ json: states[Math.min(poll++, states.length - 1)] });
    });
    await servePanoFixture(page);

    await page.goto(editUrl(assetId));

    const status = page.locator(CARD_STATUS);
    await status.getByRole('button', { name: 'Build 360 preview' }).click();

    await expect(status.getByText('Processing 48%')).toBeVisible();
    await expect(status.getByRole('button', { name: 'Cancel' })).toBeVisible();

    // The poll swaps previewData over to the finished proxy on its own; the
    // viewer itself lives inside the modal, which the Open button reveals.
    await expect(status.getByRole('button', { name: 'Open 360 preview' })).toBeVisible();
    await status.getByRole('button', { name: 'Open 360 preview' }).click();

    await expect(page.locator(MODAL).locator(VIEWER)).toBeVisible();
});

test('plays a directly playable H.264 equirect without building a proxy', async ({ page }) => {
    const assetId = requireAsset('PANORAMA_DIRECT_VIDEO_ID');

    await page.goto(editUrl(assetId));

    const status = page.locator(CARD_STATUS);
    await expect(status.getByRole('button', { name: 'Build 360 preview' })).toHaveCount(0);

    await status.getByRole('button', { name: 'Open 360 preview' }).click();

    await expect(page.locator(MODAL).locator(VIEWER)).toBeVisible();
});

test('renders non-black video pixels inside the sphere viewer', async ({ page }) => {
    const assetId = requireAsset('PANORAMA_DIRECT_VIDEO_ID');
    let mediaRequests = 0;

    await preserveWebGlDrawingBuffer(page);
    await page.route('**/admin/dam/file/**', (route) => {
        mediaRequests += 1;

        return route.fulfill({ status: 200, contentType: 'video/mp4', body: PANO_MP4 });
    });
    await page.goto(editUrl(assetId));
    await page.locator(CARD_STATUS).getByRole('button', { name: 'Open 360 preview' }).click();

    const canvas = page.locator(MODAL).locator(VIEWER).locator('canvas');
    await expect(canvas).toBeVisible();
    await expect.poll(() => canvas.evaluate((element) => element.width > 300 && element.height > 150)).toBe(true);
    await expect.poll(() => renderedPixelRatio(canvas)).toBeGreaterThan(0);
    expect(mediaRequests).toBeGreaterThan(0);
});

test('exposes Build when the browser itself refuses the direct source', async ({ page }) => {
    const assetId = requireAsset('PANORAMA_DIRECT_VIDEO_ID');

    // The server can only read container metadata, so a stripped-metadata
    // HEVC reaches the page as a "playable" direct source. The browser's own
    // verdict is the last honest gate — forge it here. Both forms are covered:
    // the declared type, and the ISO-BMFF codec string the client substitutes
    // for video/quicktime (Chrome answers '' for that container regardless).
    await page.addInitScript(() => {
        const original = window.HTMLMediaElement.prototype.canPlayType;
        window.HTMLMediaElement.prototype.canPlayType = function (type) {
            const value = String(type).toLowerCase();

            return value.startsWith('video/mp4') || value.startsWith('video/quicktime')
                ? ''
                : original.call(this, type);
        };
    });

    await page.goto(editUrl(assetId));

    // The gate lives in panoInit(), which only runs when the viewer is
    // entered — so this mirrors the user flow rather than expecting a page
    // load to have consulted canPlayType on its own.
    await page.locator(CARD_STATUS).getByRole('button', { name: 'Open 360 preview' }).click();

    // Rejection clears previewData.playback*, so the modal falls through to
    // the fallback panel, which carries its own copy of the status strip.
    const status = page.locator(MODAL).locator('[data-dam-panorama-status="fallback"]');
    await expect(status.locator('[data-dam-panorama-error]')).toBeVisible();

    // Build has to be reachable even though the server classified this asset
    // as a direct candidate (so actions.can_build is false) — the request
    // carries browser_rejected, which the API accepts for exactly this case.
    const build = status.getByRole('button', { name: 'Build 360 preview' });
    await expect(build).toBeVisible();

    const [request] = await Promise.all([
        page.waitForRequest((req) => req.url().includes('/panorama-preview') && req.method() === 'POST'),
        build.click(),
    ]);

    expect(request.postDataJSON()).toMatchObject({ browser_rejected: true });
});

/**
 * Chrome raises MEDIA_ERR_SRC_NOT_SUPPORTED (4) both for an unsupported codec
 * and for bytes that never arrived at all. Blaming the codec discards a good
 * proxy client-side and — since the API honours browser_rejected — queues a
 * real ffmpeg run for an asset that never needed one, so the viewer probes
 * reachability first. Each answer below is a way that probe could be fooled
 * into reporting "reachable" and must not be.
 */
const UNREACHABLE_PROBE_ANSWERS = [
    {
        name: 'a 404',
        fulfil: { status: 404, contentType: 'text/plain', body: 'gone' },
    },
    {
        // The probe sends X-Requested-With so Laravel answers 401 rather than
        // redirecting, but a proxy or an older handler can still redirect —
        // and redirect: 'manual' has to stop fetch from following it into a
        // 200 login page that would read as ok.
        name: 'a session-expired redirect to login',
        fulfil: { status: 302, headers: { location: '/admin/login' }, body: '' },
    },
    {
        // Whatever the status, HTML is not a playable source.
        name: 'a 200 login page',
        fulfil: { status: 200, contentType: 'text/html', body: '<html><body>Sign in</body></html>' },
    },
];

for (const answer of UNREACHABLE_PROBE_ANSWERS) {
    test(`offers retry, not a build, when the 360 source answers with ${answer.name}`, async ({ page }) => {
        const assetId = requireAsset('PANORAMA_PROXY_ID');

        // A proxy plays from admin.dam.file.asset-file, i.e. /admin/dam/file/...,
        // so one pattern covers both the <video> element's own fetch and the
        // credentialed reachability probe that follows its error.
        await page.route('**/admin/dam/file/**', (route) => route.fulfill(answer.fulfil));

        const buildRequests = [];
        page.on('request', (request) => {
            if (request.method() === 'POST' && request.url().includes('/panorama-preview')) {
                buildRequests.push(request.url());
            }
        });

        await page.goto(editUrl(assetId));
        await page.locator(CARD_STATUS).getByRole('button', { name: 'Open 360 preview' }).click();

        const viewer = page.locator(MODAL).locator(VIEWER);

        // Load failure, not rejection: the playback is kept, retry is offered,
        // and no build is exposed on either surface.
        await expect(viewer.locator('[data-dam-panorama-retry-playback]')).toBeVisible();
        await expect(page.locator(CARD_STATUS).getByRole('button', { name: 'Build 360 preview' })).toHaveCount(0);
        await expect(page.locator(MODAL).locator('[data-dam-panorama-status="fallback"]')).toHaveCount(0);

        expect(buildRequests).toEqual([]);

        // Recovery must reuse the preserved source, without scheduling a conversion.
        await page.unroute('**/admin/dam/file/**');
        await viewer.locator('[data-dam-panorama-retry-playback]').click();
        await expect.poll(() => viewer.locator('video').evaluate(video => video.readyState)).toBeGreaterThanOrEqual(2);
        await expect(viewer.locator('[data-dam-panorama-retry-playback]')).toHaveCount(0);
        expect(buildRequests).toEqual([]);
    });
}

test('keeps the sphere viewer out of the way for a force-flat asset', async ({ page }) => {
    const assetId = requireAsset('PANORAMA_FORCE_FLAT_ID');

    await page.goto(editUrl(assetId));

    // force_flat makes is_panorama false, so the whole strip stays unmounted.
    await expect(page.locator(CARD_STATUS)).toHaveCount(0);

    // The override remains reversible independently of the panorama status strip.
    const mode = page.locator('[data-dam-panorama-mode]');
    await expect(mode).toHaveValue('force_flat');
    await mode.selectOption('auto');
    await expect(page.locator(CARD_STATUS)).toBeVisible();
    await mode.selectOption('force_flat');
    await expect(page.locator(CARD_STATUS)).toHaveCount(0);

    await page.locator('[data-dam-preview-open]').first().click();

    const modal = page.locator(MODAL);
    await expect(modal.locator('[data-dam-flat-video-player]')).toBeVisible();
    await expect(modal.locator(VIEWER)).toHaveCount(0);
});

test('drag, wheel, seek and fullscreen drive the viewer readouts', async ({ page }) => {
    const assetId = requireAsset('PANORAMA_PROXY_ID');

    await preserveWebGlDrawingBuffer(page);
    await page.goto(editUrl(assetId));
    await page.locator(CARD_STATUS).getByRole('button', { name: 'Open 360 preview' }).click();

    const viewer = page.locator(MODAL).locator(VIEWER);
    await expect(viewer).toBeVisible();

    // The visible canvas precedes lazy Three/pinch initialization. Its first
    // rendered frame comes after the real pointer handlers have been attached.
    const canvas = viewer.locator('canvas');
    await expect.poll(() => renderedPixelRatio(canvas)).toBeGreaterThan(0);

    const longitude = viewer.locator('[data-dam-360-longitude]');
    const fov = viewer.locator('[data-dam-360-fov]');
    const before = { longitude: await longitude.innerText(), fov: await fov.innerText() };

    const box = await canvas.boundingBox();
    await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
    await page.mouse.down();
    await page.mouse.move(box.x + box.width / 2 - 160, box.y + box.height / 2, { steps: 8 });
    await page.mouse.up();

    await expect(longitude).not.toHaveText(before.longitude);

    await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
    await page.mouse.wheel(0, 240);

    await expect(fov).not.toHaveText(before.fov);

    const video = viewer.locator('video');
    await viewer.locator('[data-dam-360-seek]').fill('3');
    await expect.poll(() => video.evaluate((el) => el.currentTime)).toBeGreaterThan(0);

    const fullscreenRequested = await viewer.evaluate((el) => {
        let called = false;
        el.requestFullscreen = () => { called = true; return Promise.resolve(); };
        el.querySelector('[data-dam-360-fullscreen]').click();

        return called;
    });
    expect(fullscreenRequested).toBe(true);
});

test('serves the 360 proxy as a byte-range response', async ({ page }) => {
    const assetId = requireAsset('PANORAMA_PROXY_ID');

    await page.goto(editUrl(assetId));
    await page.locator(CARD_STATUS).getByRole('button', { name: 'Open 360 preview' }).click();

    const viewer = page.locator(MODAL).locator(VIEWER);
    await expect(viewer).toBeVisible();

    const source = await viewer.locator('video').getAttribute('src');
    expect(source).toBeTruthy();

    // page.request shares the page's cookie jar; the isolated `request`
    // fixture does not, and an authenticated media URL answers 302-to-login.
    const response = await page.request.get(source, { headers: { Range: 'bytes=0-1023' } });

    expect(response.status()).toBe(206);
    expect(response.headers()['content-range']).toContain('bytes 0-1023/');
});
