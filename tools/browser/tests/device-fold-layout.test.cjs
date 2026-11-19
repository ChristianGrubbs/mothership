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
