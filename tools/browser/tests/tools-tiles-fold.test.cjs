const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {launchBrowser} = require('../launch-browser.cjs');

// The Tools page's tiles on a folded screen. In book pose the grid puts one column on each side of the fold, and in
// tabletop the first row above it and the rest below (public/pwa/device.css). The grid is laid out by the committed
// admin stylesheet and measured by the real fold layer (public/pwa/device.js). Everywhere else the marker on the grid
// changes nothing.
const root = path.resolve(__dirname, '../../..');
const playwrightPath = process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH;
const browserTest = playwrightPath ? test : test.skip;
const BUILD = 'public/themes/admin/default/build';
const view = fs.readFileSync(path.join(root, 'packages/Mothership/DAM/src/Resources/views/tools/index.blade.php'), 'utf8');

const builtStyles = () => {
    const manifest = JSON.parse(fs.readFileSync(path.join(root, BUILD, 'manifest.json'), 'utf8'));
    const entry = Object.values(manifest).find(item => typeof item.file === 'string' && item.file.startsWith('assets/app-') && item.file.endsWith('.css'));

    assert.ok(entry, 'the committed admin manifest lists a built assets/app-*.css');

    return fs.readFileSync(path.join(root, BUILD, entry.file), 'utf8');
};

// The shell around the page as it stands at these widths: the icon rail (72px, always collapsed at 1179px and below),
// then the page's own scroller with its 16px sides, the page head, and the grid as the view writes it.
const lines = ['Pull a palette from a picture.', 'Loop a clip without a seam, then export it at the size you need.', 'Seam-free spheres.'];
const tiles = Array.from({length: 9}, (_, i) => `<li><a href="#" class="bridge-tool-tile" data-tool-tile="tool-${i}">
    <span class="bridge-tool-well"></span>
    <span class="bridge-tool-text"><span class="bridge-tool-name">Tool ${i}</span><span class="bridge-tool-line">${lines[i % 3]}</span></span>
    <span class="bridge-tool-foot bridge-mono" data-tool-standing>Used today</span></a></li>`).join('');
const page = dir => `<!doctype html><html dir="${dir}"><head><style>${builtStyles()}</style>
<style>${fs.readFileSync(path.join(root, 'public/pwa/device.css'), 'utf8')}</style></head>
<body style="margin:0"><div style="display:flex;height:100vh">
  <nav style="flex:none;width:72px"></nav>
  <main id="main-content" style="flex:1;min-width:0;overflow-y:auto;overflow-x:hidden;padding:12px 16px 24px">
    <div style="height:64px">Tools</div>
    <div class="bridge-tools" data-tools-page><ul class="bridge-tools-grid" data-fold-split>${tiles}</ul></div>
  </main>
</div>
<script>${fs.readFileSync(path.join(root, 'public/pwa/device.js'), 'utf8')}</script></body></html>`;

// Segments as Safari reports them in each pose: no hinge, a 40px hinge band (observed on hardware), the Safari size.
const BOOK = [
    {name: 'book', viewport: {width: 951, height: 585}, segments: [[0, 0, 475, 585], [475, 0, 476, 585]]},
    {name: 'book with a 40px hinge', viewport: {width: 951, height: 585}, segments: [[0, 0, 455, 585], [495, 0, 456, 585]]},
    {name: 'book at the Safari size', viewport: {width: 951, height: 543}, segments: [[0, 0, 475, 543], [476, 0, 475, 543]]},
];
// The wider tabletops are sanity sizes, not a device's, so the grid's three- and four-column widths are laid out folded.
const TABLETOP = [
    {name: 'tabletop', viewport: {width: 669, height: 867}, segments: [[0, 0, 669, 433], [0, 433, 669, 434]], columns: 2},
    {name: 'tabletop with a 40px hinge', viewport: {width: 669, height: 867}, segments: [[0, 0, 669, 413], [0, 453, 669, 414]], columns: 2},
    {name: 'tabletop at the Safari size', viewport: {width: 669, height: 825}, segments: [[0, 0, 669, 349], [0, 350, 669, 475]], columns: 2},
    {name: 'a wider tabletop, three columns', viewport: {width: 900, height: 840}, segments: [[0, 0, 900, 400], [0, 440, 900, 400]], columns: 3},
    {name: 'a wider tabletop, four columns', viewport: {width: 1180, height: 840}, segments: [[0, 0, 1180, 400], [0, 440, 1180, 400]], columns: 4},
];

async function open(browser, {viewport, segments = null, dir = 'ltr'}) {
    const tab = await browser.newPage({viewport});
    if (segments) {
        await tab.addInitScript(rects => Object.defineProperty(window, 'viewport', {configurable: true,
            value: {segments: rects.map(rect => new DOMRect(...rect))}}), segments);
    }
    await tab.route('http://mothership.test/**', route => route.fulfill({contentType: 'text/html', body: page(dir)}));
    await tab.goto('http://mothership.test/');
    await settle(tab);

    return tab;
}

const settle = tab => tab.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
const boxes = tab => tab.evaluate(() => [...document.querySelectorAll('.bridge-tool-tile')].map(tile => {
    const box = tile.getBoundingClientRect();
    return {left: box.left, right: box.right, top: box.top, bottom: box.bottom};
}));

test('the Tools grid carries the fold hook', () => {
    assert.match(view, /<ul class="bridge-tools-grid" data-fold-split>/);
});

browserTest('in book pose no tile rests on the fold, one column a side, in reading order', async () => {
    const browser = await launchBrowser({playwrightPath});
    try {
        for (const dir of ['ltr', 'rtl']) {
            for (const pose of BOOK) {
                const label = `${pose.name}, ${dir}`;
                const tab = await open(browser, {...pose, dir});
                const [a, b] = pose.segments;
                const foldStart = a[0] + a[2];
                const foldEnd = b[0];
                assert.equal(await tab.evaluate(() => document.documentElement.dataset.fold), 'columns', label);

                // A tile is on the fold when it runs past the fold's leading edge and starts before its trailing edge.
                const tiles = await boxes(tab);
                assert.deepEqual(tiles.filter(box => box.right > foldStart && box.left < foldEnd), [], `${label}: tiles on the fold`);

                // Leading column first, then trailing, row by row: the order the grid has unfolded.
                const leading = box => (dir === 'rtl' ? box.left >= foldEnd : box.right <= foldStart);
                assert.deepEqual(tiles.map(leading), tiles.map((_, i) => i % 2 === 0), `${label}: reading order`);
                assert.equal(new Set(tiles.map(box => Math.round(box.left))).size, 2, `${label}: two columns`);
                assert.equal(await tab.evaluate(() => document.querySelector('main').scrollWidth <= document.querySelector('main').clientWidth), true, `${label}: no sideways scroll`);
                await tab.close();
            }
        }
    } finally {
        await browser.close();
    }
});

// In tabletop the first row fills the upper segment and stops 7px short of the fold; the next row starts 7px past it.
browserTest('in tabletop no tile rests on the fold, the first row above it and the next below', async () => {
    const browser = await launchBrowser({playwrightPath});
    try {
        for (const pose of TABLETOP) {
            const tab = await open(browser, pose);
            const [a, b] = pose.segments;
            const foldTop = a[1] + a[3];
            const foldEnd = b[1];
            assert.equal(await tab.evaluate(() => document.documentElement.dataset.fold), 'rows', pose.name);

            const tiles = await boxes(tab);
            const {columns} = pose;
            assert.equal(new Set(tiles.map(box => Math.round(box.left))).size, columns, `${pose.name}: columns`);
            assert.deepEqual(tiles.filter(box => box.bottom > foldTop && box.top < foldEnd), [], `${pose.name}: tiles on the fold`);
            for (const box of tiles.slice(0, columns)) assert.ok(Math.abs(box.bottom - (foldTop - 7)) <= 1, `${pose.name}: the first row ends at ${box.bottom}`);
            for (const box of tiles.slice(columns, 2 * columns)) assert.ok(Math.abs(box.top - (foldEnd + 7)) <= 1, `${pose.name}: the second row starts at ${box.top}`);
            await tab.close();
        }
    } finally {
        await browser.close();
    }
});

// The fold layer measures again on a DOM change and on resize. A reader part-way down the page, or at its end, must see
// neither the first row change height nor the page move.
browserTest('in tabletop the rows hold still when the fold layer measures again part-way down the page', async () => {
    const browser = await launchBrowser({playwrightPath});
    try {
        const tab = await open(browser, TABLETOP[1]);
        const firstRow = () => tab.evaluate(() => document.querySelector('.bridge-tool-tile').getBoundingClientRect().height);
        const scrolled = () => tab.evaluate(() => document.querySelector('main').scrollTop);
        const height = await firstRow();
        for (const place of ['part-way', 'at the end']) {
            await tab.evaluate(end => { const main = document.querySelector('main'); main.scrollTop = end ? main.scrollHeight : 200; }, place === 'at the end');
            await settle(tab);
            const before = await scrolled();
            assert.ok(before > 0, `${place}: the page scrolls`);
            await tab.evaluate(() => document.body.append(document.createElement('div')));
            await settle(tab);
            assert.equal(await firstRow(), height, `${place}, after a DOM change: first row height`);
            assert.equal(await scrolled(), before, `${place}, after a DOM change: scroll position`);
            await tab.evaluate(() => dispatchEvent(new Event('resize')));
            await settle(tab);
            assert.equal(await firstRow(), height, `${place}, after a resize: first row height`);
            assert.equal(await scrolled(), before, `${place}, after a resize: scroll position`);
        }
    } finally {
        await browser.close();
    }
});

// Ordinary phones, the outer display, the inner display unfolded, desktop, and a tabletop too narrow for cards side by
// side (a sanity size), where the tiles are one column of rows: the marker moves no tile.
browserTest('outside book and the card grid in tabletop the fold hook moves no tile', async () => {
    const browser = await launchBrowser({playwrightPath});
    try {
        const poses = [
            {name: 'phone 390x844', viewport: {width: 390, height: 844}},
            {name: 'outer display 382x580', viewport: {width: 382, height: 580}},
            {name: 'inner display 669x867', viewport: {width: 669, height: 867}},
            {name: 'inner display on its side, no fold', viewport: {width: 951, height: 585}},
            {name: 'desktop', viewport: {width: 1440, height: 900}},
            {name: 'tabletop with one column of tiles', viewport: {width: 500, height: 867}, segments: [[0, 0, 500, 413], [0, 453, 500, 414]]},
        ];
        for (const pose of poses) {
            const tab = await open(browser, pose);
            assert.equal(await tab.evaluate(() => document.documentElement.dataset.fold ?? null), pose.segments ? 'rows' : null, pose.name);
            const marked = await boxes(tab);
            await tab.evaluate(() => document.querySelector('.bridge-tools-grid').removeAttribute('data-fold-split'));
            await settle(tab);
            assert.deepEqual(marked, await boxes(tab), pose.name);
            await tab.close();
        }
    } finally {
        await browser.close();
    }
});
