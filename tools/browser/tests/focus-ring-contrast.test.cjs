// The global focus ring (packages/Mothership/Admin/src/Resources/assets/css/app.css) must stay a 3:1 focus
// indicator on both themes. primary-600 measures 2.9:1 on the dark surface, so dark mode takes primary-400
// (dark:outline-primary-400); light mode keeps primary-600. This keyboard-focuses an ordinary button and
// link outside any menu against the committed admin stylesheet and checks the computed outline colour.
const { test } = require('node:test');
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
    const [lr, lg, lb] = [r, g, b].map((v) => {
        const c = v / 255;
        return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
    });

    return 0.2126 * lr + 0.7152 * lg + 0.0722 * lb;
};
const contrast = (a, b) => {
    const [hi, lo] = [luminance(a), luminance(b)].sort((x, y) => y - x);

    return (hi + 0.05) / (lo + 0.05);
};
const rgb = (css) => css.match(/\d+(\.\d+)?/g).slice(0, 3).map(Number);

const browserTest = process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH ? test : test.skip;

browserTest('global focus ring: primary-400 with 3:1 on dark, primary-600 on light', async () => {
    const { chromium } = require(process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH);
    const browser = await chromium.launch({ headless: true, channel: process.env.MOTHERSHIP_BROWSER_CHANNEL || 'chrome' });

    try {
        const page = await browser.newPage();
        await page.setContent(`<!doctype html><html dir="ltr"><body class="bg-white dark:bg-cherry-900"><main style="padding:24px">
            <span tabindex="0" id="start">Start</span> <button type="button" id="b">Plain button</button> <a href="#x" id="a">Plain link</a></main></body></html>`);
        await page.addStyleTag({ path: builtStylesheet() });

        for (const { theme, token } of [{ theme: 'dark', token: '--c-primary-400' }, { theme: 'light', token: '--c-primary-600' }]) {
            await page.evaluate((t) => document.documentElement.classList.toggle('dark', t === 'dark'), theme);

            for (const [id, presses] of [['b', 1], ['a', 2]]) {
                await page.focus('#start');
                for (let i = 0; i < presses; i++) await page.keyboard.press('Tab');

                const seen = await page.evaluate(({ id, token }) => {
                    const el = document.getElementById(id);
                    const [r, g, b] = getComputedStyle(document.documentElement).getPropertyValue(token).trim().split(/\s+/);

                    return {
                        focused: document.activeElement === el && el.matches(':focus-visible'),
                        outline: getComputedStyle(el).outlineColor,
                        expected: `rgb(${r}, ${g}, ${b})`,
                        surface: getComputedStyle(document.body).backgroundColor,
                    };
                }, { id, token });

                assert.ok(seen.focused, `${theme} #${id} must be keyboard focus-visible`);
                assert.equal(seen.outline, seen.expected, `${theme} #${id} outline must be ${token}`);
                if (theme === 'dark') {
                    assert.ok(contrast(rgb(seen.outline), rgb(seen.surface)) >= 3, `${theme} #${id} outline needs 3:1 against ${seen.surface}`);
                }
            }
        }
    } finally {
        await browser.close();
    }
});
