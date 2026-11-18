const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const { test } = require('@playwright/test');

const projectRoot = path.resolve(__dirname, '../../..');
const adminRoot = path.join(projectRoot, 'packages/Mothership/Admin');
const readerPath = path.join(adminRoot, 'src/Resources/views/help/reader-script.blade.php');
const reader = fs.readFileSync(readerPath, 'utf8');
const style = reader.match(/<style[^>]*>[\s\S]*?<\/style>/)?.[0];
const script = reader.match(/<script[^>]*>([\s\S]*?)<\/script>/)?.[1];
// Resolved from tests/e2e-pw's own devDependencies; CI never installs the Admin package's node_modules.
const vuePath = require.resolve('vue/dist/vue.global.js');

assert.ok(style, 'reader partial must provide its scoped typography');
assert.ok(script, 'reader partial must provide its copy behavior');

function article(label) {
    return `<article data-documentation-article><pre><code>${label}</code></pre></article>`;
}

async function loadInitial(page, label) {
    await page.setContent(`<div id="app">${article(label)}${style}<script>${script}<\/script></div>`);
    await page.addScriptTag({ path: vuePath });
    await page.evaluate(() => {
        window.app = Vue.createApp({});
        window.app.mount('#app');
    });
}

async function spaVisit(page, label) {
    await page.evaluate(({ label, style, script }) => {
        const container = document.createElement('div');
        container.innerHTML = `<div id="app"><article data-documentation-article><pre><code>${label}</code></pre></article>${style}</div>`;
        const nextApp = container.firstElementChild;
        const styles = [...nextApp.querySelectorAll('style')];

        window.app.unmount();
        document.querySelector('#app').replaceWith(nextApp);

        document.querySelectorAll('style[data-ajax-nav-style]').forEach(node => node.remove());
        styles.forEach(original => {
            const clone = original.cloneNode(true);
            clone.setAttribute('data-ajax-nav-style', '');
            document.head.appendChild(clone);
        });

        window.app = Vue.createApp({});
        new Function(script)();
        window.app.mount('#app');
        document.dispatchEvent(new CustomEvent('mothership:navigate:success', { bubbles: true }));
    }, { label, style, script });
}

test('copy controls write the current block after initial Vue mount and three SPA visits', async ({ page }) => {
    await loadInitial(page, 'initial-code');
    await page.evaluate(() => {
        window.__clipboardWrites = [];
        Object.defineProperty(navigator, 'clipboard', {
            configurable: true,
            value: {
                writeText: async value => window.__clipboardWrites.push(value),
            },
        });
    });

    for (const label of ['initial-code', 'visit-one', 'visit-two', 'visit-three']) {
        if (label !== 'initial-code') {
            await spaVisit(page, label);
        }

        const copyButton = page.getByRole('button', { name: 'Copy code' });

        assert.equal(await copyButton.count(), 1);
        await copyButton.click();
    }

    assert.deepEqual(
        await page.evaluate(() => window.__clipboardWrites),
        ['initial-code', 'visit-one', 'visit-two', 'visit-three'],
    );
});

test('reader typography stays outside the Vue root after initial mount and three SPA visits', async ({ page }) => {
    await loadInitial(page, 'initial-code');

    for (const label of ['initial-code', 'visit-one', 'visit-two', 'visit-three']) {
        if (label !== 'initial-code') {
            await spaVisit(page, label);
        }

        assert.equal(await page.locator('#app style').count(), 0);
        assert.equal(await page.locator('head style[data-documentation-reader-style]').count(), 1);
        assert.ok(
            await page.locator('[data-documentation-article]').evaluate(element => parseFloat(getComputedStyle(element).lineHeight) > 20),
        );
    }
});
