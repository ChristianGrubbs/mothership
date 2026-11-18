// The attribute, attribute-group and category-field create forms lay out a main column (flex-1
// overflow-auto) beside a 360px sidebar. flex-wrap alone does not stack them: the sidebar must also
// take the full row below the sm breakpoint (max-sm:w-full), or both stay on one row at 430px and the
// form is squeezed to about 28px. This takes the real row/column classes from each Blade source and
// renders them against the committed admin stylesheet: stacked with no horizontal overflow at 375,
// 430 and 524px, side by side at 525px.
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '../../..');
const source = (file) => fs.readFileSync(path.join(root, file), 'utf8');
const viewsDir = 'packages/Mothership/Admin/src/Resources/views/catalog';
const templates = ['attributes', 'attributegroups', 'categories/field'].map((dir) => `${viewsDir}/${dir}/create.blade.php`);

function stylesheetPath() {
    const manifest = JSON.parse(source('public/themes/admin/default/build/manifest.json'));
    const entry = Object.values(manifest).find((item) => typeof item.file === 'string' && item.file.endsWith('.css') && item.file.startsWith('assets/app-'));
    assert.ok(entry, 'the committed admin manifest must list a built assets/app-*.css entry');

    return path.join(root, 'public/themes/admin/default/build', entry.file);
}

function classesOf(blade, pattern, label) {
    const match = blade.match(new RegExp(`<div class="([^"]*${pattern}[^"]*)"`));
    assert.ok(match, `${label} not found`);

    return match[1];
}

function columnClasses(file) {
    const blade = source(file);

    return {
        row: classesOf(blade, 'max-sm:flex-wrap', 'two-column row'),
        main: classesOf(blade, 'flex-1 overflow-auto', 'main column'),
        side: classesOf(blade, 'w-\\[360px\\]', 'sidebar'),
    };
}

const playwrightTest = process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH ? test : test.skip;

async function measure(page, { width, cls }) {
    await page.setViewportSize({ width, height: 900 });
    await page.setContent(`<!doctype html><html dir="ltr"><body><div id="row" class="${cls.row}"><div id="main" class="${cls.main}"><input style="width:100%"></div><div id="side" class="${cls.side}">side</div></div></body></html>`);
    await page.addStyleTag({ path: stylesheetPath() });

    return page.evaluate(() => {
        const rect = (id) => document.getElementById(id).getBoundingClientRect();
        const main = rect('main');
        const side = rect('side');

        return {
            stacked: side.top >= main.bottom - 1,
            mainWidth: main.width,
            sideWidth: side.width,
            overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
        };
    });
}

for (const file of templates) {
    playwrightTest(`${file} stacks its columns at phone width and splits them from 525px`, async () => {
        const { chromium } = require(process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH);
        const browser = await chromium.launch({ headless: true, channel: process.env.MOTHERSHIP_BROWSER_CHANNEL || 'chrome' });
        const cls = columnClasses(file);

        try {
            const page = await browser.newPage();

            for (const width of [375, 430, 524]) {
                const m = await measure(page, { width, cls });
                assert.ok(m.stacked, `${width}px: sidebar must sit below the main column`);
                assert.ok(m.mainWidth > width * 0.8, `${width}px: main column must get the row, got ${m.mainWidth}`);
                assert.ok(m.sideWidth > width * 0.8, `${width}px: sidebar must get the row, got ${m.sideWidth}`);
                assert.ok(m.overflow <= 0, `${width}px: no horizontal overflow, got ${m.overflow}`);
            }

            const wide = await measure(page, { width: 525, cls });
            assert.ok(!wide.stacked, '525px: columns must sit side by side');
            assert.ok(wide.mainWidth > 100, `525px: main column must keep real width, got ${wide.mainWidth}`);
        } finally {
            await browser.close();
        }
    });
}
