import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import { thumbnailTarget, isCoarsePointer, needsPhoneConfirm } from '../../src/Resources/assets/js/model/load-policy.mjs';

/**
 * Loads `v-dam-model-viewer` from its Blade partial (the `@js(...)` echoes swapped for fixtures, as
 * game-asset-preview-refresh.test.mjs does) and drives the capture handshake with stand-ins for the network and the
 * renderer. Covers which bytes a capture may be stored against: the ones the viewer loaded, never a newer revision.
 */
const A = 'a'.repeat(64);
const B = 'b'.repeat(64);

function loadComponent() {
    const blade = readFileSync(new URL('../../src/Resources/views/asset/preview-modal/model/viewer-script.blade.php', import.meta.url), 'utf8');
    const script = [...blade.matchAll(/<script type="module">([\s\S]*?)<\/script>/g)].map(match => match[1])
        .find(source => source.includes("app.component('v-dam-model-viewer'"));

    assert.ok(script, 'component v-dam-model-viewer not found');

    let component;
    const source = script
        .replace(/@js\(trans\('dam::app\.model-viewer'\)\)/, '{}')
        .replace(/@js\(route\('admin\.dam\.assets\.download'[^\n]*?\]\)\)/, "'/download/__ASSET__'")
        .replace(/@js\(route\('admin\.dam\.assets\.primary_view\.show'[^\n]*?\]\)\)/, "'/primary/__ASSET__'")
        .replace(/@js\(route\('admin\.dam\.assets\.file_model_thumbnail\.show'[^\n]*?\]\)\)/, "'/file-capture/__TARGET__'")
        .replace(/@js\(route\('admin\.dam\.assets\.model_thumbnail\.show'[^\n]*?\]\)\)/, "'/asset-capture/__TARGET__'");

    assert.ok(!source.includes('@js('), 'every @js() echo was replaced');

    return { source, define: sandbox => { vm.runInNewContext(source, { ...sandbox, app: { component: (_name, definition) => { component = definition; } } }); return component; } };
}

/** @param {{ checksums: string[], primary?: object }} options `checksums` are what the capture endpoint answers, in order. */
async function drive({ checksums, savePrimary = null, downloads = null, url = '/download/7' }) {
    const posts = [];
    const reads = [...checksums];
    // What the entry held at the moment each model download was served (default: the first handshake's answer).
    const served = [...(downloads ?? [checksums[0]])];
    const rendered = [];
    const requests = [];
    let viewerOptions = null;

    const host = { textContent: '', dataset: {}, replaceChildren() {} };
    const window = {
        axios: {
            get: async url => {
                if (url === '/primary/7') return { data: { revision: 'rev-1', view: null, can_edit: true } };
                if (url === '/asset-capture/7') return { data: { checksum: reads.length > 1 ? reads.shift() : reads[0], has_thumbnail: false, can_edit: true, can_capture: true, can_replace: true } };
                throw new Error(`unexpected GET ${url}`);
            },
            post: async (url, body) => {
                if (url === '/primary/7') return { data: savePrimary };
                posts.push({ url, checksum: body.get?.('checksum') ?? null, replace: body.get?.('replace') ?? null });

                return { data: { stored: true, skipped: null, thumbnail_url: '/t.jpg' } };
            },
        },
        loadDamModelPolicy: async () => ({ thumbnailTarget, isCoarsePointer: () => false, needsPhoneConfirm }),
        loadDamModelViewer: async () => ({
            ModelViewer: class {
                // The download: a request naming sha256 is answered 409 unless the entry still holds those bytes.
                constructor(options) {
                    viewerOptions = options;
                    requests.push(options.url);
                    const held = served.length > 1 ? served.shift() : served[0];
                    const pinned = new URL(options.url, 'http://x').searchParams.get('sha256');
                    if (pinned !== null && pinned !== held) { host.dataset.damModelViewer = 'error'; options.onFailure(); } else rendered.push(held);
                    this.ready = Promise.resolve();
                }

                adoptPrimaryView() {}

                dispose() {}
            },
        }),
        loadDamModelThumbnails: async () => ({
            captureFromViewer: async () => new Blob(['jpeg']),
            saveLatestCapture: async ({ encode, post }) => ({ data: (await post(await encode())).data, current: true }),
        }),
    };

    const { define } = loadComponent();
    const component = define({ window, FormData, Blob, setTimeout, console, sessionStorage: undefined });
    const vm = { url, extension: 'glb', assetId: 7, fileId: null, sizeBytes: 1, $refs: { host }, $emitter: { emit() {} }, $nextTick: async () => {} };
    Object.assign(vm, component.data.call(vm));
    for (const [name, method] of Object.entries(component.methods)) vm[name] = method.bind(vm);

    await vm.start();
    await new Promise(resolve => setImmediate(resolve));

    return { vm, posts, window, rendered, requests, save: async payload => { await viewerOptions.onSavePrimary(payload); await new Promise(resolve => setImmediate(resolve)); } };
}

test('captures against the revision the viewer loaded when the server still holds it', async () => {
    const { posts } = await drive({ checksums: [A] });

    assert.deepEqual(posts, [{ url: '/asset-capture/7', checksum: A, replace: null }]);
});

test('never stores revision A\'s picture as the capture of revision B published before the handshake', async () => {
    // The identity is read before the download (A); the model is replaced while it loads; the handshake then reads B.
    const { posts } = await drive({ checksums: [A, B] });

    assert.deepEqual(posts, []);
});

test('after a primary-view save the capture is bound to the saved bytes, and replaces', async () => {
    const { posts, save } = await drive({ checksums: [A, B], savePrimary: { revision: 'rev-2', can_edit: true, view: { distance: 4, aspect: 1.5 }, checksum: B } });
    // Opening found the model replaced (A then B): nothing captured. The save then adopts B in the loaded scene.
    assert.deepEqual(posts, []);

    await save({ rotation: [0, 1, 0, 0], translation: [0, 0, 0], view: { distance: 4, aspect: 1.5 } });

    assert.deepEqual(posts, [{ url: '/asset-capture/7', checksum: B, replace: '1' }]);
});

test('a save whose answer does not name the saved bytes captures nothing', async () => {
    const { posts, save } = await drive({ checksums: [A], savePrimary: { revision: 'rev-2', can_edit: true, view: null } });
    posts.length = 0;

    await save({ rotation: [0, 1, 0, 0], translation: [0, 0, 0], view: { distance: 4, aspect: 1.5 } });

    assert.deepEqual(posts, []);
});

test('another editor replacing the model after the viewer loaded blocks the save-time capture too', async () => {
    // A at load, A at the first handshake (captured), then B once the person saves a primary view that answers A.
    const { posts, save } = await drive({ checksums: [A, A, B], savePrimary: { revision: 'rev-2', can_edit: true, view: null, checksum: A } });
    posts.length = 0;

    await save({ rotation: [0, 1, 0, 0], translation: [0, 0, 0], view: { distance: 4, aspect: 1.5 } });

    assert.deepEqual(posts, []);
});

test('the model download is pinned to the checksum read before it, and what loads is what is captured', async () => {
    const { posts, requests, rendered } = await drive({ checksums: [A], downloads: [A] });

    assert.deepEqual(requests, [`/download/7?sha256=${A}`]);
    assert.deepEqual(rendered, [A]);
    assert.deepEqual(posts, [{ url: '/asset-capture/7', checksum: A, replace: null }]);
});

test('A at the checksum read, B at the download, A again at the handshake: B\'s picture is never stored as A\'s', async () => {
    // The restore puts A's bytes back before the handshake, so two reads of the entry both say A; only the download knows.
    const { posts, rendered } = await drive({ checksums: [A, A], downloads: [B] });

    assert.deepEqual(rendered, [], 'the pinned download was refused instead of serving B');
    assert.deepEqual(posts, []);
});

test('a model URL that is not the asset download route cannot be pinned, so it captures nothing', async () => {
    const { posts, requests } = await drive({ checksums: [A], url: '/m.glb' });

    assert.deepEqual(requests, ['/m.glb']);
    assert.deepEqual(posts, []);
});
