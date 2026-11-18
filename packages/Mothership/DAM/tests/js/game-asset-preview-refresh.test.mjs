import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

/**
 * Loads `v-dam-game-asset-preview` from its Blade partial the way explorer-asset-card-open.test.mjs loads the
 * card: the `@json(...)` echoes are swapped for fixtures so the script parses, and fetch/setTimeout are
 * stand-ins the test drives. Covers the refresh of an unread Blender or Unity file's facts once its thumbnail
 * request (which reads the file) has settled.
 */
function loadComponent({ responses }) {
    const blade = readFileSync(new URL('../../src/Resources/views/asset/preview-modal/game-asset/viewer-script.blade.php', import.meta.url), 'utf8');
    const script = [...blade.matchAll(/<script type="module">([\s\S]*?)<\/script>/g)]
        .map(match => match[1])
        .find(source => source.includes("app.component('v-dam-game-asset-preview'"));

    assert.ok(script, 'component v-dam-game-asset-preview not found');

    let component;
    const fetched = [];
    const timers = [];
    const source = script
        .replace(/@json\(trans\([^)]*\)\)/g, '{}')
        .replace(/@json\(route\([^;]*?\)\)\)?/g, "'/admin/dam/assets/info/__ID__'");

    vm.runInNewContext(source, {
        app: { component: (_name, definition) => { component = definition; } },
        fetch: async (url) => {
            fetched.push(url);
            const body = responses.shift();

            return { ok: body !== undefined, json: async () => body };
        },
        setTimeout: (callback, delay) => { timers.push({ callback, delay }); },
    });

    return { component, fetched, timers };
}

function instance(component, props) {
    const vm = { ...props };
    Object.assign(vm, component.data.call(vm));
    for (const [name, method] of Object.entries(component.methods)) vm[name] = method.bind(vm);

    return vm;
}

const flush = () => new Promise(resolve => setImmediate(resolve));

const unreadBlend = { kind: 'blender', read: false, counts: {}, assets: [], thumbnailUrl: '/thumb', textUrl: null };

test('an unread file refreshes its facts from the info endpoint once the thumbnail loads', async () => {
    const { component, fetched } = loadComponent({ responses: [{ game_asset: { read: true, blenderVersion: '4.2', counts: { scenes: 1 } } }] });
    const preview = instance(component, { preview: unreadBlend, assetId: 7, fileName: 'scene.blend' });

    preview.onThumbnailSettled();
    await flush();

    assert.deepEqual(fetched, ['/admin/dam/assets/info/7']);
    assert.equal(preview.facts.read, true);
    assert.equal(preview.facts.blenderVersion, '4.2');
    assert.equal(preview.facts.thumbnailUrl, '/thumb');
});

test('it retries at most three times with backoff while the facts are still missing', async () => {
    const notYet = { game_asset: { read: false } };
    const { component, fetched, timers } = loadComponent({ responses: [notYet, notYet, notYet, notYet] });
    const preview = instance(component, { preview: unreadBlend, assetId: 7 });

    preview.onThumbnailSettled();
    await flush();
    while (timers.length) {
        timers.shift().callback();
        await flush();
    }

    assert.equal(fetched.length, 4);
    assert.equal(preview.facts.read, false);
});

test('it records the backoff delays', async () => {
    const notYet = { game_asset: { read: false } };
    const { component, timers } = loadComponent({ responses: [notYet, notYet, notYet, notYet] });
    const preview = instance(component, { preview: unreadBlend, assetId: 7 });
    const delays = [];

    preview.onThumbnailSettled();
    await flush();
    while (timers.length) {
        const timer = timers.shift();
        delays.push(timer.delay);
        timer.callback();
        await flush();
    }

    assert.deepEqual(delays, [1000, 2000, 4000]);
});

test('a file already read, or a Unity text asset, never fetches', async () => {
    const { component, fetched } = loadComponent({ responses: [] });

    instance(component, { preview: { ...unreadBlend, read: true }, assetId: 7 }).onThumbnailSettled();
    instance(component, { preview: { ...unreadBlend, kind: 'unity-yaml' }, assetId: 7 }).onThumbnailSettled();
    await flush();

    assert.deepEqual(fetched, []);
});

test('a response for a previous asset is dropped', async () => {
    const { component } = loadComponent({ responses: [{ game_asset: { read: true, blenderVersion: '9.9' } }] });
    const preview = instance(component, { preview: unreadBlend, assetId: 7 });

    preview.onThumbnailSettled();
    // The modal moved to another asset before the response arrived.
    preview.factsGeneration++;
    await flush();

    assert.equal(preview.facts.blenderVersion, undefined);
});
