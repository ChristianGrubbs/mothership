const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { randomUUID } = require('crypto');

/**
 * Seeds an asset through the session-authenticated Library upload endpoint
 * (admin.dam.assets.upload) instead of scripting the Library's drag-drop
 * upload widget: the widget's file input is not a plain <input type=file>
 * (it wraps a drop zone with client-side chunking, see dam-chunked-upload.spec.js),
 * so a direct multipart POST reusing the already-authenticated browser session
 * cookies is the reliable seed path for this smoke test. The XSRF-TOKEN cookie
 * is Laravel's own SPA-CSRF cookie (unencrypted, readable from document.cookie);
 * VerifyCsrfToken accepts it back as the X-XSRF-TOKEN header, so no separate
 * OAuth/API credential bootstrap is needed. Returns the uploaded asset's id
 * and storage path (from the endpoint's own JSON response).
 */
async function uploadFixture(page, { fileName = 'two-file-bundle.zip', directoryId = 1 } = {}) {
  const xsrfToken = await page.evaluate(() => {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : null;
  });
  expect(xsrfToken, 'XSRF-TOKEN cookie must be present after the authenticated global-setup login').not.toBeNull();

  const fixturePath = path.join(__dirname, '../../fixtures/web-pages', fileName);

  const response = await page.request.post('/admin/dam/assets/upload', {
    headers: { 'X-XSRF-TOKEN': xsrfToken },
    multipart: {
      directory_id: String(directoryId),
      'files[]': {
        // Uploads to an existing Library path replace that asset and retain its
        // custom thumbnail. Capture cases need a fresh asset on every retry.
        name: `${path.basename(fileName, '.zip')}-${randomUUID()}.zip`,
        mimeType: 'application/zip',
        buffer: fs.readFileSync(fixturePath),
      },
    },
  });

  expect(response.ok(), `upload endpoint responded ${response.status()}: ${await response.text()}`).toBeTruthy();
  const body = await response.json();
  const asset = body.files[0];

  // Upload acceptance queues finalization and bundle building. A preparing card
  // deliberately does not open an iframe, and its grant correctly answers 409.
  await expect.poll(async () => {
    const grant = await page.request.get(`/admin/dam/pages/${asset.id}/grant`);
    const result = await grant.json();

    if (grant.status() === 409) {
      expect(result, 'bundle preparation must remain pending, not failed').toEqual({ state: 'preparing', error: null });
    } else {
      expect(grant.status(), `bundle grant responded ${grant.status()}`).toBe(200);
      expect(result.url, 'ready bundle must expose its entry grant').toEqual(expect.any(String));
    }

    return grant.status();
  }, { timeout: 30000, intervals: [250, 500, 1000], message: 'uploaded web bundle must finish preparing' }).toBe(200);

  return { id: asset.id, path: asset.path };
}

/**
 * The fixture page's #bg element covers the full page with a CSS url(pixel.png) background
 * (solid red, no other colour anywhere on the page), so the centre pixel of the captured
 * thumbnail reading red-dominant proves the CSS url() reference was actually resolved and
 * inlined into the snapshot -- not just the plain <img> sibling, and not a blank canvas.
 */
async function expectRedThumbnail(page, asset) {
  const thumbnail = await page.request.get(`/admin/dam/file/thumbnail?path=${encodeURIComponent(asset.path)}`);
  expect(thumbnail.ok()).toBeTruthy();
  expect(thumbnail.headers()['content-type']).toContain('image/jpeg');
  const bytes = await thumbnail.body();
  expect(bytes.length).toBeGreaterThan(1000);

  const centrePixel = await page.evaluate(async (base64) => {
    const image = new Image();
    const url = 'data:image/jpeg;base64,' + base64;
    await new Promise((resolve, reject) => { image.onload = resolve; image.onerror = reject; image.src = url; });
    const canvas = document.createElement('canvas');
    canvas.width = image.naturalWidth; canvas.height = image.naturalHeight;
    const context = canvas.getContext('2d');
    context.drawImage(image, 0, 0);
    const [r, g, b] = context.getImageData(Math.floor(canvas.width / 2), Math.floor(canvas.height / 2), 1, 1).data;

    return { r, g, b };
  }, bytes.toString('base64'));

  expect(centrePixel.r).toBeGreaterThan(150);
  expect(centrePixel.g).toBeLessThan(100);
  expect(centrePixel.b).toBeLessThan(100);
}

test('a two-file bundle previews inside the sandbox, its script runs, and the capture lands as the thumbnail', async ({ page }) => {
  await page.goto('/admin/dam/assets');
  const asset = await uploadFixture(page);

  await page.goto('/admin/dam/pages');
  const card = page.locator(`[data-web-page="${asset.id}"]`);
  await expect(card).toBeVisible();

  const captureResponse = page.waitForResponse(
    (response) => response.url().includes(`/assets/${asset.id}/custom-thumbnail`) && response.status() === 201,
    { timeout: 30000 },
  );

  await card.click();

  const frame = page.frameLocator('iframe[sandbox]');
  await expect(frame.locator('body')).toContainText('bundle ok');
  await expect(frame.locator('body')).toHaveAttribute('data-script-ran', 'yes');

  const opaque = await page.locator('iframe[sandbox]').evaluate((el) => el.contentDocument === null);
  expect(opaque).toBe(true); // opaque origin: the parent cannot read the sandboxed document

  // The in-page script posts the capture ~800ms after load; wait for the parent's store round trip.
  await captureResponse;

  await expectRedThumbnail(page, asset);
});

test('an exporter launcher zip captures the page it refreshes to, not the launcher', async ({ page }) => {
  await page.goto('/admin/dam/assets');
  const asset = await uploadFixture(page, { fileName: 'launcher-bundle.zip' });

  await page.goto('/admin/dam/pages');
  const captureResponse = page.waitForResponse(
    (response) => response.url().includes(`/assets/${asset.id}/custom-thumbnail`) && response.status() === 201,
    { timeout: 30000 },
  );
  await page.locator(`[data-web-page="${asset.id}"]`).click();

  // The launcher (served as index.html) meta-refreshes into site/page/ inside the same frame.
  const frame = page.frameLocator('iframe[sandbox]');
  await expect(frame.locator('body')).toContainText('bundle ok');
  await captureResponse;

  // Red, not the launcher's "Opening Launcher" line on white.
  await expectRedThumbnail(page, asset);
});

test('an asset with an existing custom thumbnail is served without the capture marker', async ({ page }) => {
  await page.goto('/admin/dam/assets');
  const asset = await uploadFixture(page, { fileName: 'two-file-bundle.zip' });

  // Arrange the existing-thumbnail state independently. The two capture tests
  // above cover automatic capture; this case checks the server's suppression
  // contract, including a manually supplied thumbnail.
  const xsrfToken = await page.evaluate(() => decodeURIComponent(document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/)[1]));
  const saved = await page.request.post(`/admin/dam/assets/${asset.id}/custom-thumbnail`, {
    headers: { 'X-XSRF-TOKEN': xsrfToken },
    multipart: {
      thumbnail: { name: 'thumbnail.png', mimeType: 'image/png', buffer: fs.readFileSync(path.join(__dirname, '../../assets/dotted.png')) },
    },
  });
  expect(saved.status(), await saved.text()).toBe(201);

  const grant = await page.request.get(`/admin/dam/pages/${asset.id}/grant`);
  expect(grant.ok()).toBeTruthy();
  const entryUrl = (await grant.json()).url;

  const entry = await page.request.get(entryUrl);
  expect(entry.ok()).toBeTruthy();
  expect(await entry.text()).not.toContain('<!--mothership-capture-->');
});
