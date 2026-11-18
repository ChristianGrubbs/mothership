// Regression test for the validation language (2026-10-10). The Admin app now mounts on DOMContentLoaded, so a stalled
// font or image keeps `window.load` pending for good; the validation locale used to be set only on `load`, leaving
// every message in English. Bundles the real plugin and drives it in a browser with `load` held pending: the localized
// message must appear at once, and follow a changed-language and a same-language AJAX visit (the real
// syncDocumentLocale() from navigation.js).
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '../../..');
const dam = path.join(root, 'packages/Mothership/DAM');
const playwrightPath = process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH;
const vuePath = process.env.MOTHERSHIP_VUE_GLOBAL_PATH || path.join(dam, 'node_modules/vue/dist/vue.global.js');
const browserTest = playwrightPath ? test : test.skip;
const navigationSource = fs.readFileSync(path.join(root, 'packages/Mothership/Admin/src/Resources/assets/js/plugins/navigation.js'), 'utf8');

async function pluginBundle() {
    const esbuild = require(path.join(dam, 'node_modules/esbuild'));
    const result = await esbuild.build({
        entryPoints: [path.join(root, 'packages/Mothership/Admin/src/Resources/assets/js/plugins/vee-validate.js')],
        bundle: true,
        format: 'iife',
        globalName: 'VeePlugin',
        write: false,
        nodePaths: [path.join(dam, 'node_modules')],
        loader: { '.json': 'json' },
        // vee-validate must share the page's Vue (one reactivity system), not bundle a second copy.
        plugins: [{
            name: 'global-vue',
            setup(build) {
                build.onResolve({ filter: /^vue$/ }, () => ({ path: 'vue', namespace: 'global-vue' }));
                build.onLoad({ filter: /.*/, namespace: 'global-vue' }, () => ({ contents: 'module.exports = window.Vue;', loader: 'js' }));
            },
        }],
    });

    return result.outputFiles[0].text;
}

function syncDocumentLocaleSource() {
    const match = navigationSource.match(/function syncDocumentLocale\([^)]*\) \{[\s\S]*?\n {4}\}/);
    assert.ok(match, 'syncDocumentLocale must exist in navigation.js');

    return match[0];
}

const page_html = `<!doctype html><html lang="de_DE" dir="ltr"><head><meta charset="utf-8"></head><body>
<div id="app"><v-form><v-field name="name" label="Name" rules="required" v-slot="{ field, errorMessage }"><input v-bind="field" data-field><span data-error>{{ errorMessage }}</span></v-field><button type="button" data-submit>Save</button></v-form></div>
<img src="/never-answers.png" alt="">
<script src="/vue.js"></script><script src="/plugin.js"></script>
<script>const app = Vue.createApp({}); app.use(VeePlugin.default); app.mount('#app');</script>
</body></html>`;

browserTest('validation messages follow the document language with window.load pending, and AJAX language visits', async () => {
    const bundle = await pluginBundle();
    const browser = await require(playwrightPath).chromium.launch({ headless: true, channel: process.env.MOTHERSHIP_BROWSER_CHANNEL || 'chrome' });

    try {
        const page = await browser.newPage();
        page.on('pageerror', (error) => process.stderr.write(`pageerror ${error.message.slice(0, 200)}\n`));
        await page.route('**/*', (route) => {
            const url = new URL(route.request().url());

            if (url.pathname === '/') return route.fulfill({ contentType: 'text/html', body: page_html });
            if (url.pathname === '/vue.js') return route.fulfill({ contentType: 'text/javascript', body: fs.readFileSync(vuePath, 'utf8') });
            if (url.pathname === '/plugin.js') return route.fulfill({ contentType: 'text/javascript', body: bundle });
            // Never answered: window.load stays pending for the whole test.
        });
        await page.goto('http://mothership.test/', { waitUntil: 'commit' });
        await page.waitForSelector('[data-submit]');

        const submit = async () => {
            // Typing then clearing the field validates it (validateOnInput), the way a user would trigger the message.
            await page.locator('[data-field]').fill('x');
            await page.locator('[data-field]').fill('');
            await page.waitForFunction(() => document.querySelector('[data-error]')?.textContent.trim() !== '');

            return (await page.locator('[data-error]').textContent()).trim();
        };

        assert.notEqual(await page.evaluate(() => document.readyState), 'complete', 'window.load must still be pending');
        assert.match(await submit(), /Pflichtfeld/, 'German message expected immediately, before window.load');

        await page.evaluate((source) => {
            window.syncDocumentLocale = new Function(`${source}; return syncDocumentLocale;`)();
        }, syncDocumentLocaleSource());
        const visit = (lang) => page.evaluate((language) => {
            window.syncDocumentLocale(new DOMParser().parseFromString(`<html lang="${language}" dir="ltr"><body></body></html>`, 'text/html'));
        }, lang);

        // Changed language: the hook re-syncs the validation locale.
        await visit('fr_FR');
        assert.match(await submit(), /obligatoire/, 'French message expected after a changed-language visit');

        // Same language: nothing changes, and the message stays French.
        await visit('fr_FR');
        assert.match(await submit(), /obligatoire/, 'French message expected after a same-language visit');

        await visit('en_US');
        assert.match(await submit(), /required/i, 'English message expected after switching back');
        assert.notEqual(await page.evaluate(() => document.readyState), 'complete', 'window.load was pending throughout');
    } finally {
        await browser.close();
    }
});
