'use strict';

// The secondary-text contrast override (packages/Mothership/Admin/src/Resources/assets/css/app.css, UX eval VIS-08),
// measured as computed colours against the committed admin stylesheet, in light and dark: plain captions keep
// the corrected colour at rest, hover and focus; a control with its own hover/focus colour keeps that colour;
// icon glyphs keep their own rule.
const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '../../..');
const buildDir = 'public/themes/admin/default/build';

function builtStylesheet() {
    const manifest = JSON.parse(fs.readFileSync(path.join(root, buildDir, 'manifest.json'), 'utf8'));
    const entry = Object.values(manifest).find((item) => typeof item.file === 'string' && item.file.endsWith('.css') && item.file.startsWith('assets/app-'));
    assert.ok(entry, 'the committed admin manifest must list a built assets/app-*.css entry');

    return path.join(root, buildDir, entry.file);
}

const luminance = ([r, g, b]) => {
    const [lr, lg, lb] = [r, g, b].map((v) => { const c = v / 255; return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4; });
    return 0.2126 * lr + 0.7152 * lg + 0.0722 * lb;
};
const contrast = (a, b) => { const [hi, lo] = [luminance(a), luminance(b)].sort((x, y) => y - x); return (hi + 0.05) / (lo + 0.05); };
const rgb = (css) => css.match(/\d+(\.\d+)?/g).slice(0, 3).map(Number);

const browserTest = process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH ? test : test.skip;

browserTest('secondary text keeps 4.5:1 idle, hovered and focused; controls with their own state colour and icons are left alone', async () => {
    const {chromium} = require(process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH);
    const browser = await chromium.launch({headless: true, channel: process.env.MOTHERSHIP_BROWSER_CHANNEL || 'chrome'});

    try {
        const page = await browser.newPage();
        await page.setContent(`<!doctype html><html><body style="margin:0"><div id="surface" style="padding:24px">
            <span id="gray" tabindex="0" class="text-gray-500 dark:text-gray-500" style="display:inline-block">gray caption</span>
            <span id="slate" tabindex="0" class="text-slate-500 dark:text-slate-500" style="display:inline-block">slate caption</span>
            <span id="control" tabindex="0" class="text-gray-500 hover:text-red-600 dark:text-gray-500" style="display:inline-block">control</span>
            <span id="icon" tabindex="0" class="icon-delete text-gray-400 dark:text-gray-500" style="display:inline-block">icon</span>
            </div></body></html>`);
        await page.addStyleTag({path: builtStylesheet()});

        const colour = (id) => page.evaluate((i) => getComputedStyle(document.getElementById(i)).color, id);
        const states = async (id) => {
            const seen = {idle: await colour(id)};
            await page.hover(`#${id}`);
            seen.hover = await colour(id);
            await page.mouse.move(500, 500);
            await page.focus(`#${id}`);
            seen.focus = await colour(id);
            await page.evaluate((i) => document.getElementById(i).blur(), id);

            return seen;
        };

        for (const theme of ['light', 'dark']) {
            await page.evaluate((t) => document.documentElement.classList.toggle('dark', t === 'dark'), theme);
            const surface = theme === 'dark' ? [17, 24, 39] : [255, 255, 255];
            await page.evaluate((s) => { document.getElementById('surface').style.background = `rgb(${s.join(',')})`; }, surface);

            const gray = await states('gray');
            for (const [state, value] of Object.entries(gray)) {
                assert.ok(contrast(rgb(value), surface) >= 4.5, `${theme} gray caption ${state} (${value}) needs 4.5:1`);
            }
            assert.deepEqual(new Set(Object.values(gray)).size, 1, `${theme} gray caption keeps one colour across states`);

            if (theme === 'dark') {
                const slate = await states('slate');
                for (const [state, value] of Object.entries(slate)) {
                    assert.ok(contrast(rgb(value), surface) >= 4.5, `dark slate caption ${state} (${value}) needs 4.5:1`);
                }
            }

            const control = await states('control');
            assert.ok(contrast(rgb(control.idle), surface) >= 4.5, `${theme} control at rest needs 4.5:1`);
            if (theme === 'light') {
                assert.equal(control.hover, 'rgb(220, 38, 38)', 'light: the control\'s own hover:text-red-600 still wins');
            } else {
                assert.notEqual(control.hover, control.idle, 'dark: the corrected colour steps aside on a control with its own hover colour');
            }

            if (theme === 'dark') {
                const icon = await states('icon');
                for (const [state, value] of Object.entries(icon)) {
                    assert.equal(value, 'rgb(247, 247, 247)', `dark icon ${state} keeps the icon rule's slate-50`);
                }
            }
        }
    } finally {
        await browser.close();
    }
});
