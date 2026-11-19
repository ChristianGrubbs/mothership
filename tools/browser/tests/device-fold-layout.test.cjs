const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {launchBrowser} = require('../launch-browser.cjs');

const root = path.resolve(__dirname, '../../..');
const css = fs.readFileSync(path.join(root, 'public/pwa/device.css'), 'utf8');
const js = fs.readFileSync(path.join(root, 'public/pwa/device.js'), 'utf8');
const browserTest = process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH ? test : test.skip;

// The hooks the admin shell, the Library and Web Pages carry (data-fold-split / data-fold-wrap), reduced to their layout.
// Containers style through classes, as the Tailwind markup does, so the fold layer can override them.
const body = `<style>.row{display:flex}.bar{justify-content:space-between;padding:0 16px;height:60px}.pad{padding:16px}.drawer{position:fixed;left:0;top:0;width:300px;transform:translateX(-100%)}${css}</style>
<header class="mothership-header row bar" data-fold-split>
  <a href="#">Brand</a>
  <div class="dam-header-search" style="flex:0 1 280px;margin:auto"><input aria-label="Search" style="width:100%"></div>
  <div><button>Theme</button><button>Account</button></div>
</header>
<div class="row pad" data-explorer-split data-fold-split>
  <aside class="drawer" data-explorer-sidebar><button>Folder</button></aside>
  <section data-explorer-panel style="flex:1;min-width:0"><button style="width:100%">Full-width panel action</button></section>
</div>
<div data-fold-wrap style="display:flex;gap:8px;padding:0 16px">
  ${Array.from({length: 8}, (_, i) => `<button style="width:110px">Tool ${i}</button>`).join('')}
</div>
<footer data-fold-wrap style="padding:4px 16px">A notice long enough to run past the middle of the page before its <a href="#">privacy link</a>.</footer>
<script>window.foldEvents = []; addEventListener('mothership:fold', e => foldEvents.push(e.detail.mode));</script>
<script>${js}</script>`;

// Emulated book pose: two side-by-side segments around a 40px gap (a sanity value; no Apple source gives one).
const book = width => { const lead = Math.floor((width - 40) / 2); return [[0, 0, lead, 585], [lead + 40, 0, width - lead - 40, 585]]; };

async function open(browser, segments, {width = 951, html = body} = {}) {
    const page = await browser.newPage({viewport: {width, height: 585}});
    if (segments) {
        await page.addInitScript(rects => Object.defineProperty(window, 'viewport', {configurable: true,
            value: {segments: rects.map(rect => new DOMRect(...rect))}}), book(width));
    }
    await page.route('http://mothership.test/**', route => route.fulfill({contentType: 'text/html', body: `<!doctype html><html><body>${html}</body></html>`}));
    await page.goto('http://mothership.test/');
    await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));

    return page;
}

const onFold = page => page.evaluate(() => [...document.querySelectorAll('a, button, input')]
    .filter(el => { const box = el.getBoundingClientRect(); return box.width && box.left < 495 && box.right > 455; })
    .map(el => el.textContent.trim() || el.getAttribute('aria-label')));

browserTest('book pose keeps every control off the fold; without segments the layer is inert', async () => {
    const browser = await launchBrowser({playwrightPath: process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH});
    try {
        const flat = await open(browser, false);
        assert.equal(await flat.evaluate(() => document.documentElement.dataset.fold), undefined);
        assert.deepEqual(await flat.evaluate(() => foldEvents), []);
        assert.equal(await flat.evaluate(() => getComputedStyle(document.querySelector('[data-explorer-split]')).display), 'flex');
        assert.equal(await flat.evaluate(() => document.querySelector('[data-fold-split]').style.getPropertyValue('--fold-start')), '');
        assert.ok((await onFold(flat)).length > 0, 'the fixture must put controls across the middle when nothing is folded');

        const folded = await open(browser, true);
        assert.equal(await folded.evaluate(() => document.documentElement.dataset.fold), 'columns');
        assert.deepEqual(await folded.evaluate(() => foldEvents), ['columns']);
        assert.deepEqual(await onFold(folded), []);
        // The Directory column is in place on the leading segment, the panel on the trailing one.
        const [sidebar, panel] = await folded.evaluate(() => ['[data-explorer-sidebar]', '[data-explorer-panel]']
            .map(selector => { const box = document.querySelector(selector).getBoundingClientRect(); return [box.left, box.right]; }));
        assert.ok(sidebar[0] >= 0 && sidebar[1] <= 455, `sidebar ${sidebar}`);
        assert.ok(panel[0] >= 495, `panel ${panel}`);
        assert.equal(await folded.evaluate(() => document.documentElement.scrollWidth), 951);
    } finally {
        await browser.close();
    }
});

// The production Directory column keeps a 220px desktop minimum (sm:min-w-[220px]). With the admin nav expanded
// (270px) and the row's 16px padding, a 1024px book pose leaves the leading track only 206px.
const navBody = `<style>.row{display:flex}.pad{padding:16px}.min{min-width:220px;width:280px}${css}</style>
<div style="display:flex"><nav style="flex:none;width:270px">Nav</nav>
  <div class="row pad" data-explorer-split data-fold-split style="flex:1;min-width:0">
    <aside class="min" data-explorer-sidebar><button style="width:100%">A folder with a long name</button></aside>
    <section data-explorer-panel style="flex:1;min-width:0"><button style="width:100%">Panel action</button></section>
  </div>
</div>
<script>${js}</script>`;

// A list page (Shared links' grid form) in the shell: a top bar above the page's one scroller, the page head and the grid's
// toolbar with its filter drawer, then the rows. Rows sit in a positioned layer, as the list's sticky parts do.
const listBody = `<style>body{margin:0}.top{position:relative;z-index:20;height:60px}main{height:525px;overflow-y:auto;padding:12px 16px;box-sizing:border-box}
.panel{position:fixed;top:0;bottom:0;right:0;width:350px;z-index:10001;background:#fff}.rows>div{position:relative;height:90px}.rows button{width:100%}${css}</style>
<header class="top" style="display:flex;justify-content:flex-end"><button>Account</button></header>
<main id="main-content">
  <div data-fold-list data-fold-split>
    <div class="bridge-page-head" style="display:flex"><h1 style="margin:0">Shared links</h1><button>New</button></div>
    <div class="bridge-list-grid"><div>
      <div class="datagrid-toolbar" style="display:flex;flex-wrap:wrap;gap:8px"><div style="display:flex;gap:8px"><input aria-label="Search" style="width:260px"><button>Filter</button></div>
        <div><div style="display:flex;gap:8px"><button>10</button><p style="margin:0;max-width:44px">Per Page</p><input aria-label="Page" style="width:46px"><span>of 3</span><button>First</button><button>Previous</button><button>Next</button><button>Last</button></div></div></div>
      <div style="display:flex;margin-top:16px"><div class="table-responsive rows" style="width:100%">${Array.from({length: 12}, (_, i) => `<div class="bridge-list-row"><button>Link ${i}</button></div>`).join('')}</div></div>
    </div></div>
  </div>
</main>
<script>${js}</script>`;

browserTest('a list page puts its head and toolbar on the leading segment and its rows on the trailing one', async () => {
    const browser = await launchBrowser({playwrightPath: process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH});
    const box = (page, selector) => page.evaluate(selector => { const {left, right, top, bottom} = document.querySelector(selector).getBoundingClientRect(); return {left, right, top, bottom}; }, selector);
    try {
        const flat = await open(browser, false, {html: listBody});
        assert.equal(await flat.evaluate(() => getComputedStyle(document.querySelector('[data-fold-list]')).display), 'block');
        assert.ok((await onFold(flat)).length > 0, 'the fixture must put controls across the middle when nothing is folded');

        const page = await open(browser, true, {html: listBody});
        assert.deepEqual(await onFold(page), []);
        for (const selector of ['.bridge-page-head', '.datagrid-toolbar']) assert.ok((await box(page, selector)).right <= 455, selector);
        assert.ok((await box(page, '.table-responsive')).left >= 495);
        assert.equal(await page.evaluate(() => document.querySelector('main').scrollWidth - document.querySelector('main').clientWidth), 0);

        // The rows scroll past; the toolbar stays in reach.
        await page.evaluate(() => { document.querySelector('main').scrollTop = 400; });
        const [toolbar, main] = [await box(page, '.datagrid-toolbar'), await box(page, 'main')];
        assert.ok(toolbar.top >= main.top && toolbar.bottom <= main.bottom, `toolbar ${JSON.stringify(toolbar)}`);

        // The open filter drawer covers the top bar and the rows, though its toolbar is a sticky layer of its own.
        await page.evaluate(() => { const panel = document.createElement('div'); panel.className = 'panel'; panel.dataset.drawerPanel = ''; panel.innerHTML = '<button>Apply</button>'; document.querySelector('.datagrid-toolbar').append(panel); });
        assert.deepEqual(await page.evaluate(() => [[760, 30], [760, 300]].map(([x, y]) => Boolean(document.elementFromPoint(x, y).closest('[data-drawer-panel]')))), [true, true]);
    } finally {
        await browser.close();
    }
});

// Closing the device from book pose puts the page on the outer display (382 x 580, one segment), and opening it puts it
// back. The row being read keeps its place on screen both ways, though the page head moves from beside the rows to above
// them, and the head's sticky layer stops the browser's own scroll anchoring from doing it.
browserTest('a list page keeps the row being read on screen from book to the outer display and back', async () => {
    const browser = await launchBrowser({playwrightPath: process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH});
    try {
        const page = await browser.newPage({viewport: {width: 951, height: 585}});
        await page.addInitScript(rects => { window.foldRects = rects; Object.defineProperty(window, 'viewport', {configurable: true,
            get: () => ({segments: window.foldRects ? window.foldRects.map(rect => new DOMRect(...rect)) : [new DOMRect(0, 0, innerWidth, innerHeight)]})}); }, book(951));
        // The head stays beside the rows as they scroll, as Upload requests' head does ([data-fold-lead]).
        const html = listBody.replace('<div class="bridge-page-head"', '<div class="bridge-page-head" data-fold-lead');
        await page.route('http://mothership.test/**', route => route.fulfill({contentType: 'text/html', body: `<!doctype html><html><body>${html}</body></html>`}));
        await page.goto('http://mothership.test/');
        const settle = () => page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(() => setTimeout(resolve, 50)))));
        const read = () => page.evaluate(() => {
            const main = document.querySelector('main').getBoundingClientRect();
            const row = document.querySelector('[data-read]').getBoundingClientRect();
            return {fold: document.documentElement.dataset.fold ?? null, onScreen: row.top >= main.top && row.bottom <= main.bottom, offset: Math.round(row.top - main.top)};
        });
        await settle();

        // Partway down the rows in book pose, the first row wholly below the top of the scroller is the one being read.
        await page.evaluate(() => {
            const main = document.querySelector('main');
            main.scrollTop = 400;
            const top = main.getBoundingClientRect().top;
            [...main.querySelectorAll('.bridge-list-row')].find(row => row.getBoundingClientRect().top >= top + 40).dataset.read = '';
        });
        const start = await read();
        assert.equal(start.fold, 'columns');

        await page.evaluate(() => { window.foldRects = null; });
        await page.setViewportSize({width: 382, height: 580});
        await settle();
        const closed = await read();
        assert.equal(closed.fold, null);
        assert.ok(closed.onScreen && Math.abs(closed.offset - start.offset) <= 2, `closed: ${JSON.stringify({start, closed})}`);

        await page.evaluate(rects => { window.foldRects = rects; }, book(951));
        await page.setViewportSize({width: 951, height: 585});
        await settle();
        const back = await read();
        assert.equal(back.fold, 'columns');
        assert.ok(back.onScreen && Math.abs(back.offset - start.offset) <= 2, `back in book: ${JSON.stringify({start, closed, back})}`);
    } finally {
        await browser.close();
    }
});

browserTest('the Directory column shrinks below its desktop minimum rather than cross the fold', async () => {
    const browser = await launchBrowser({playwrightPath: process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH});
    try {
        const page = await open(browser, true, {width: 1024, html: navBody});
        const [sidebar, panel] = await page.evaluate(() => ['[data-explorer-sidebar]', '[data-explorer-panel]']
            .map(selector => { const box = document.querySelector(selector).getBoundingClientRect(); return [box.left, box.right]; }));
        assert.ok(sidebar[1] <= 492, `sidebar ${sidebar}`);
        assert.ok(panel[0] >= 532, `panel ${panel}`);
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth), 1024);
    } finally {
        await browser.close();
    }
});

// Safari on iPhone Duo fires resize and the posture change before it updates the viewport segments, and changes the
// segment media queries after. Chromium changes the size and the segments together, so it cannot show that order. Here
// the segments change with no resize, and the test raises the change event on the page's own segment media query lists.
browserTest('a fold that arrives after the resize still reaches an open page, through the segment media queries', async () => {
    const browser = await launchBrowser({playwrightPath: process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH});
    const state = page => page.evaluate(() => ({
        fold: document.documentElement.dataset.fold ?? null,
        gap: document.documentElement.style.getPropertyValue('--fold-gap'),
        start: [...document.querySelectorAll('[data-fold-split]')].map(el => el.style.getPropertyValue('--fold-start')),
    }));
    const settle = page => page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
    async function openLive(viewport, rects) {
        const page = await browser.newPage({viewport});
        await page.addInitScript(rects => {
            window.foldRects = rects;
            Object.defineProperty(window, 'viewport', {configurable: true, get: () => ({segments: window.foldRects
                ? window.foldRects.map(rect => new DOMRect(...rect)) : [new DOMRect(0, 0, innerWidth, innerHeight)]})});
            // Every segment media query list the page asks for, so the test can raise its change event.
            const matchMedia = window.matchMedia.bind(window);
            window.segmentLists = [];
            window.matchMedia = query => { const list = matchMedia(query); if (/viewport-segments/.test(query)) window.segmentLists.push(list); return list; };
        }, rects);
        await page.route('http://mothership.test/**', route => route.fulfill({contentType: 'text/html', body: `<!doctype html><html><body>${body}</body></html>`}));
        await page.goto('http://mothership.test/');
        await settle(page);

        return page;
    }
    // The segments change and nothing else does, then the segment media queries change.
    const segmentsChange = async (page, rects) => {
        const queries = await page.evaluate(rects => {
            window.foldRects = rects;
            for (const list of window.segmentLists) list.dispatchEvent(new Event('change'));
            return window.segmentLists.map(list => list.media);
        }, rects);
        await settle(page);

        return queries;
    };
    try {
        const freshFlat = await state(await openLive({width: 951, height: 585}, null));
        const freshBook = await state(await openLive({width: 951, height: 585}, book(951)));
        assert.equal(freshBook.fold, 'columns');

        // Open flat to book at the same size: the segments arrive with no resize.
        const page = await openLive({width: 951, height: 585}, null);
        assert.deepEqual(await state(page), freshFlat);
        assert.deepEqual((await segmentsChange(page, book(951))).sort(), ['(horizontal-viewport-segments: 2)', '(vertical-viewport-segments: 2)']);
        assert.deepEqual(await state(page), freshBook);

        // Book to open flat.
        await segmentsChange(page, null);
        assert.deepEqual(await state(page), freshFlat);

        // Tabletop to closed on its side: the resize comes while the two segments are still reported, then they go.
        const tabletop = await openLive({width: 669, height: 867}, [[0, 0, 669, 433], [0, 473, 669, 394]]);
        assert.equal((await state(tabletop)).fold, 'rows');
        await tabletop.setViewportSize({width: 678, height: 340});
        await settle(tabletop);
        await segmentsChange(tabletop, null);
        assert.deepEqual(await state(tabletop), await state(await openLive({width: 678, height: 340}, null)));
    } finally {
        await browser.close();
    }
});
