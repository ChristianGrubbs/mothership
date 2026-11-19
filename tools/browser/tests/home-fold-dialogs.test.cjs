const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {launchBrowser} = require('../launch-browser.cjs');
const {homeApp, homeAnswersSource, homeScripts, homeStyles} = require('./support/home-fixture.cjs');

// Home's dialogs and phone sheet on a folded screen: public/pwa/device.css keeps every control in
// them off the fold. The page is Home's own markup, stylesheet and scripts, so a renamed class fails here.
const root = path.resolve(__dirname, '../../..');
const deviceCss = fs.readFileSync(path.join(root, 'public/pwa/device.css'), 'utf8');
const deviceJs = fs.readFileSync(path.join(root, 'public/pwa/device.js'), 'utf8');
const calendarJs = fs.readFileSync(path.join(root, 'packages/Mothership/DAM/src/Resources/assets/js/home-calendar.js'), 'utf8');
const playwrightPath = process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH;
const browserTest = playwrightPath ? test : test.skip;
const ORIGIN = 'https://home.test';

const tool = (id, title) => ({id, title, description: `${title} description`, icon: id, kind: 'tool', settings: {}, url: `${ORIGIN}/tools/${id}/index.html`});
const catalog = [tool('calendar', 'Calendar')];

// Emulated poses (sanity values; no Apple source gives the hinge a width). Book: side by side. Laptop: stacked.
// `first` is the first segment's width; left out, the fold is centred, as it is on the device.
const BOOK = {width: 951, height: 585, segments: (gap, first = Math.floor((951 - gap) / 2)) => [[0, 0, first, 585], [first + gap, 0, 951 - first - gap, 585]]};
const LAPTOP = {width: 669, height: 867, segments: gap => { const top = Math.floor((867 - gap) / 2); return [[0, 0, 669, top], [0, top + gap, 669, 867 - top - gap]]; }};

const settled = page => page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));

async function mount(browser, {width, height, segments = null, dir = 'ltr'}) {
    const page = await browser.newPage({viewport: {width, height}, reducedMotion: 'reduce'});
    const errors = []; page.on('pageerror', error => errors.push(error.message));
    if (segments) {
        await page.addInitScript(rects => Object.defineProperty(window, 'viewport', {configurable: true,
            value: {segments: rects.map(rect => new DOMRect(...rect))}}), segments);
    }
    await page.route(`${ORIGIN}/**`, route => route.fulfill({contentType: 'text/html', body: route.request().url().includes('/tools/') ? '<!doctype html><p>tool</p>'
        : `<!doctype html><a href="/admin/dam" class="bridge-top-upload" style="display:none" data-bridge-upload><span>Upload</span></a>${homeApp()}`}));
    await page.goto(`${ORIGIN}/admin/home`);
    await page.evaluate(dir => { document.documentElement.dir = dir; }, dir);
    // The order the page uses: the device stylesheet in the head, Home's own stylesheet after it in the body.
    await page.addStyleTag({content: `*{box-sizing:border-box}body{margin:0}${deviceCss}`});
    await page.addStyleTag({content: homeStyles});
    await page.addScriptTag({content: deviceJs});
    await page.addScriptTag({content: homeAnswersSource});
    await page.evaluate(catalog => {
        const response = data => new Response(JSON.stringify(data), {status: 200, headers: {'Content-Type': 'application/json'}});
        window.__requests = [];
        window.fetch = async (url, options = {}) => {
            const address = String(url); window.__requests.push(`${options.method || 'GET'} ${address}`);
            if (address.endsWith('/layout')) return response({catalog, widgets: catalog.map(({id, settings}) => ({id, settings})), links: []});
            return response(window.__homeAnswer(address) ?? {});
        };
    }, catalog);
    // Home's scripts in page order; the calendar loads just before home.js, which starts the page and so comes last.
    for (const content of [...homeScripts.slice(0, -1), calendarJs, homeScripts.at(-1)]) await page.addScriptTag({content});
    await page.waitForFunction(() => window.__requests.some(entry => entry.endsWith('/layout')) && !document.querySelector('[data-board]').hasAttribute('aria-busy'));
    await settled(page);

    return {page, errors};
}

// [name, what to click, the overlay, an overlay to open first]
const OVERLAYS = [
    ['upload', '[data-bridge-upload]', '[data-upload-dialog]'],
    ['calendar', '[data-calendar-open]', '[data-calendar-dialog]'],
    ['event editor', '[data-calendar-dialog] [data-cal-add]', '[data-cal-dialog]', '[data-calendar-open]'],
    ['iPhone app', '[data-section="apps"] [data-phone-open]', '[data-phone-dialog]'],
    ['phone sheet', '[data-glance-open]', '[data-side-sheet]'],
];

// Opens each named overlay in turn and returns its box, the boxes of the controls shown inside it, and the calendar's
// two halves where it has them.
async function boxes(page, names) {
    const found = {};
    for (const [name, opener, selector, first] of OVERLAYS) {
        if (!names.includes(name)) continue;
        if (first) await page.locator(first).first().evaluate(node => node.click());
        await page.locator(opener).first().evaluate(node => node.click());
        await page.locator(selector).waitFor();
        await settled(page);
        found[name] = await page.evaluate(selector => {
            const node = document.querySelector(selector), rect = element => { const box = element.getBoundingClientRect(); return [box.left, box.top, box.right, box.bottom]; };
            const controls = [...node.querySelectorAll('a[href], button, input:not([type=hidden]), select, textarea')]
                .filter(element => { const box = element.getBoundingClientRect(); return box.width && box.height; }).map(rect);
            const part = name => { const element = node.querySelector(name); return element && element.getBoundingClientRect().width ? rect(element) : null; };
            return {box: rect(node), controls, clips: getComputedStyle(node).overflowY !== 'visible', month: part('.agh-cal-grid'), day: part('.agh-cal-side')};
        }, selector);
        await page.evaluate(() => {
            [...document.querySelectorAll('dialog[open]')].reverse().forEach(dialog => dialog.close());
        });
        await page.waitForFunction(() => !document.querySelector('dialog[open]'));
    }

    return found;
}

browserTest('book pose keeps Home\'s dialogs off the fold', async () => {
    const browser = await launchBrowser({playwrightPath});
    try {
        const single = ['upload', 'event editor', 'iPhone app'];
        // Without segments the layer is inert: the dialogs sit centred, across the middle of the screen.
        const flat = await mount(browser, {width: BOOK.width, height: BOOK.height});
        const centred = await boxes(flat.page, [...single, 'calendar']);
        for (const [name, {box}] of Object.entries(centred)) assert.ok(box[0] < 475 && box[2] > 476, `${name} is centred when nothing is folded: ${box}`);
        assert.deepEqual(flat.errors, []);

        // Centred folds, as on the device, then an off-centre one in both directions: the rules follow the reported
        // segments, not the middle of the screen.
        for (const {gap, first, dir = 'ltr'} of [{gap: 0}, {gap: 40}, {gap: 24, first: 380}, {gap: 24, first: 380, dir: 'rtl'}]) {
            const segments = BOOK.segments(gap, first), [a, b] = segments, [leading, trailing] = dir === 'rtl' ? [b, a] : [a, b];
            const inside = (box, [left, , width]) => box[0] >= left - 0.5 && box[2] <= left + width + 0.5;
            const pose = `with a ${gap}px fold at ${a[2]} (${dir})`;
            const {page, errors} = await mount(browser, {width: BOOK.width, height: BOOK.height, segments, dir});
            assert.equal(await page.evaluate(() => document.documentElement.dataset.fold), 'columns');
            const found = await boxes(page, [...single, 'calendar']);
            // One segment each: inside the trailing one, controls and all.
            for (const name of single) {
                const {box, controls} = found[name];
                assert.ok(inside(box, trailing) && box[1] >= 0 && box[3] <= BOOK.height, `${name} ${pose}: ${box}`);
                assert.ok(controls.length > 0, `${name} shows its controls`);
                for (const control of controls) assert.ok(inside(control, trailing), `${name} control ${pose}: ${control}`);
            }
            // The calendar takes both: the month on the leading segment, the chosen day on the trailing one, no control between.
            const {box, controls, month, day} = found.calendar;
            assert.ok(box[0] >= 0 && box[2] <= BOOK.width && box[0] < a[2] && box[2] > b[0], `calendar ${pose}: ${box}`);
            assert.ok(month && inside(month, leading), `calendar month ${pose}: ${month}`);
            assert.ok(day && inside(day, trailing), `calendar day ${pose}: ${day}`);
            assert.ok(controls.length > 40, 'the calendar shows its month of day buttons');
            for (const control of controls) assert.ok(inside(control, a) || inside(control, b), `calendar control ${pose}: ${control}`);
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth), BOOK.width);
            assert.deepEqual(errors, []);
        }
    } finally {
        await browser.close();
    }
});

browserTest('laptop pose keeps Home\'s dialogs inside the lower segment', async () => {
    const browser = await launchBrowser({playwrightPath});
    try {
        // At this width Home has no phone sheet: its rows scroll to the cards on the page.
        const names = ['upload', 'calendar', 'event editor', 'iPhone app'];
        const flat = await mount(browser, {width: LAPTOP.width, height: LAPTOP.height});
        const resting = await boxes(flat.page, names);
        for (const [name, {box}] of Object.entries(resting)) assert.ok(box[1] < 433 && box[3] > 434, `${name} crosses the middle when nothing is folded: ${box}`);
        assert.deepEqual(flat.errors, []);

        for (const gap of [0, 40]) {
            const [, [, top]] = LAPTOP.segments(gap);
            const {page, errors} = await mount(browser, {width: LAPTOP.width, height: LAPTOP.height, segments: LAPTOP.segments(gap)});
            assert.equal(await page.evaluate(() => document.documentElement.dataset.fold), 'rows');
            const found = await boxes(page, names);
            for (const [name, {box, controls, clips}] of Object.entries(found)) {
                assert.ok(box[1] >= top && box[3] <= LAPTOP.height && box[0] >= 0 && box[2] <= LAPTOP.width, `${name} with a ${gap}px fold: ${box}`);
                assert.ok(controls.length > 0, `${name} shows its controls`);
                // A box that scrolls its own content hides whatever is scrolled past its edge, so only the controls it shows
                // count there; any other box keeps every control inside. None reaches past the box's sides.
                for (const control of controls) {
                    if (clips && (control[3] <= box[1] || control[1] >= box[3])) continue;
                    assert.ok(control[0] >= box[0] - 0.5 && control[2] <= box[2] + 0.5, `${name} control sideways with a ${gap}px fold: ${control}`);
                    assert.ok(clips || (control[1] >= box[1] - 0.5 && control[3] <= box[3] + 0.5), `${name} control with a ${gap}px fold: ${control}`);
                }
            }
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth), LAPTOP.width);
            assert.deepEqual(errors, []);
        }
    } finally {
        await browser.close();
    }
});

// The phone sheet exists only up to 640px wide, narrower than a folding phone opened. A narrower stacked screen still
// gets it in the lower segment.
browserTest('a phone-width screen folded like a laptop opens the phone sheet in the lower segment', async () => {
    const browser = await launchBrowser({playwrightPath});
    try {
        const narrow = {width: 600, height: 800};
        for (const gap of [0, 40]) {
            const top = Math.floor((narrow.height - gap) / 2) + gap;
            const {page, errors} = await mount(browser, {...narrow, segments: [[0, 0, narrow.width, top - gap], [0, top, narrow.width, narrow.height - top]]});
            assert.equal(await page.evaluate(() => document.documentElement.dataset.fold), 'rows');
            const {box, controls} = (await boxes(page, ['phone sheet']))['phone sheet'];
            assert.ok(box[1] >= top && box[3] <= narrow.height && box[0] >= 0 && box[2] <= narrow.width, `phone sheet with a ${gap}px fold: ${box}`);
            const close = controls[0];
            assert.ok(close[1] >= top && close[3] <= narrow.height, `Close is in the lower segment with a ${gap}px fold: ${close}`);
            assert.deepEqual(errors, []);
            await page.close();
        }
    } finally {
        await browser.close();
    }
});
