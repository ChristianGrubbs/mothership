import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

/**
 * Extracts the `v-dam-explorer-asset-card` definition from asset-card.blade.php the same way
 * explorer-list-selection.test.mjs loads its components. The card's script holds no `@json`,
 * only `{{ mothership_asset(...) }}` echoes inside string literals, which are swapped for a path
 * so the script parses. The sandboxed `window` is handed back so a test can watch
 * `window.location.assign`.
 *
 * tools/browser/tests/dam-explorer-asset-card-open.test.cjs drives the same behavior through the
 * real template and a real click in Chromium.
 */
function loadCard() {
    const blade = readFileSync(new URL('../../src/Resources/views/components/explorer/asset-card.blade.php', import.meta.url), 'utf8');
    const script = [...blade.matchAll(/<script type="module">([\s\S]*?)<\/script>/g)]
        .map(match => match[1])
        .find(source => source.includes("app.component('v-dam-explorer-asset-card'"));

    assert.ok(script, 'component v-dam-explorer-asset-card not found');

    let component;
    const assigned = [];
    const window = { location: { assign: url => assigned.push(url) } };
    // Blade renders @js(trans('…')) as a quoted string; stand in a fixed one so the script parses.
    const source = script
        .replace(/@js\(trans\('[^']*'\)\)/g, "'fixture'")
        .replace(/\{\{[\s\S]*?\}\}/g, '/fixture.svg');
    vm.runInNewContext(source, {
        app: { component: (_name, definition) => { component = definition; } },
        window,
        document: {},
    });

    return { component, assigned };
}

function cardInstance(asset) {
    const { component, assigned } = loadCard();
    const emitted = [];
    const instance = { ...component.methods, asset, $emit: (...args) => emitted.push(args) };

    return { instance, emitted, assigned };
}

test('an ordinary asset card emits the requested mode with the asset id and never navigates', () => {
    for (const mode of ['edit', 'preview']) {
        const { instance, emitted, assigned } = cardInstance({ id: 41, file_name: 'poster.png' });

        instance.openAsset(mode);

        assert.deepEqual(emitted, [[mode, 41]], 'the emit is synchronous: nothing is awaited before it');
        assert.deepEqual(assigned, []);
    }
});
