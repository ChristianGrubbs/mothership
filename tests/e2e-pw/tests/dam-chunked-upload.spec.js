const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const clientSource = fs.readFileSync(path.resolve(
  __dirname,
  '../../../packages/Mothership/DAM/src/Resources/assets/js/upload/chunked-upload.mjs',
), 'utf8');

test('three-chunk browser fixture pauses, resumes missing chunks, and reveals the completed asset', async ({ page }) => {
  await page.route('http://localhost/chunked-upload-fixture', route => route.fulfill({
    contentType: 'text/html',
    body: `
    <input id="source" type="file">
    <button id="pause">Pause</button>
    <button id="resume">Resume</button>
    <progress id="progress" max="100" value="0"></progress>
    <output id="state">idle</output>
    <output id="asset"></output>
    `,
  }));
  await page.goto('http://localhost/chunked-upload-fixture');

  await page.evaluate(async (source) => {
    const moduleUrl = URL.createObjectURL(new Blob([source], { type: 'text/javascript' }));
    const { ChunkUploadClient } = await import(moduleUrl);
    URL.revokeObjectURL(moduleUrl);

    const received = new Set();
    const calls = [];
    let blockedOnce = false;
    let completionPosted = false;
    let completionPoll = 0;
    let controller = null;
    let currentFile = null;
    const upload = (state = 'receiving') => ({
      uuid: '00000000-0000-4000-8000-000000000001',
      state,
      chunk_size: 4,
      expected_bytes: 12,
      received_indices: [...received],
      received_bytes: received.size * 4,
      expires_at: new Date(Date.now() + 60_000).toISOString(),
    });
    const http = {
      async post(url) {
        calls.push({ method: 'post', url });
        if (url.endsWith('/complete')) {
          completionPosted = true;
          return { status: 202, data: { upload: upload('queued') } };
        }
        return { status: 201, data: { upload: upload() } };
      },
      async put(url, body, options) {
        const index = Number(url.split('/').at(-1));
        calls.push({ method: 'put', index, range: options.headers['Content-Range'] });
        if (index === 1 && !blockedOnce) {
          blockedOnce = true;
          await new Promise((resolve, reject) => {
            options.signal.addEventListener('abort', () => reject(new DOMException('Paused', 'AbortError')), { once: true });
          });
        }
        received.add(index);
        return { data: { upload: upload() } };
      },
      async get(url) {
        calls.push({ method: 'get', url });
        if (!completionPosted) return { data: { upload: upload() } };
        completionPoll++;
        return { data: { upload: completionPoll === 1 ? upload('assembling') : { ...upload('completed'), asset_id: 44 } } };
      },
      async delete() {
        return { data: { upload: upload('cancelled') } };
      },
    };
    const routes = {
      initialize: '/chunks',
      status: id => `/chunks/${id}`,
      store: (id, index) => `/chunks/${id}/${index}`,
      complete: id => `/chunks/${id}/complete`,
      cancel: id => `/chunks/${id}`,
    };
    const makeClient = () => new ChunkUploadClient({
      http,
      routes,
      chunkSize: 4,
      retryAttempts: 1,
      waitForPoll: async () => {},
      onProgress: ({ acceptedBytes = 0, state }) => {
        document.querySelector('#state').textContent = state;
        document.querySelector('#progress').value = Math.min(99, Math.round((acceptedBytes / 12) * 100));
      },
    });
    const transfer = async (manifest) => {
      controller = new AbortController();
      const client = makeClient();
      try {
        const latest = await client.uploadMissing({ file: currentFile, upload: manifest, signal: controller.signal });
        const completed = await client.complete({ uploadId: latest.uuid, signal: controller.signal });
        document.querySelector('#progress').value = 100;
        document.querySelector('#state').textContent = completed.state;
        document.querySelector('#asset').textContent = String(completed.asset_id);
      } catch (error) {
        if (error.name !== 'AbortError') throw error;
        document.querySelector('#state').textContent = 'paused';
      }
    };

    document.querySelector('#source').addEventListener('change', async (event) => {
      currentFile = event.target.files[0];
      const client = makeClient();
      const manifest = await client.initialize({ file: currentFile, sessionUuid: 'session-1', directoryId: 7 });
      await transfer(manifest);
    });
    document.querySelector('#pause').addEventListener('click', () => controller.abort());
    document.querySelector('#resume').addEventListener('click', async () => {
      const client = makeClient();
      await transfer(await client.status({ uploadId: upload().uuid }));
    });
    window.fixture = { calls, received };
  }, clientSource);

  await page.locator('#source').setInputFiles({
    name: 'master.mp4',
    mimeType: 'video/mp4',
    buffer: Buffer.from('abcdefghijkl'),
  });
  await expect.poll(() => page.locator('#progress').getAttribute('value')).toBe('33');
  await page.locator('#pause').click();
  await expect(page.locator('#state')).toHaveText('paused');
  await page.locator('#resume').click();
  await expect(page.locator('#state')).toHaveText('completed');
  await expect(page.locator('#asset')).toHaveText('44');

  const evidence = await page.evaluate(() => ({
    accepted: [...window.fixture.received],
    completionPosts: window.fixture.calls.filter(call => call.method === 'post' && call.url.endsWith('/complete')).length,
    ranges: window.fixture.calls.filter(call => call.method === 'put').map(call => call.range),
  }));
  expect(evidence.accepted).toEqual([0, 1, 2]);
  expect(evidence.completionPosts).toBe(1);
  expect(evidence.ranges).toContain('bytes 8-11/12');
});
