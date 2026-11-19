const { test } = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');
const { pathToFileURL } = require('node:url');

const playwrightPath = process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH;
const browserTest = playwrightPath ? test : test.skip;
// Opened straight from disk, as the tool has to work without the platform.
const pageUrl = pathToFileURL(path.resolve(__dirname, '../../../packages/Mothership/DAM/src/Resources/widgets/plaque/index.html')).href;

const launch = () => require(playwrightPath).chromium.launch({ headless: true, channel: process.env.MOTHERSHIP_BROWSER_CHANNEL || 'chrome' });
const settled = page => page.evaluate(() => new Promise(done => requestAnimationFrame(() => requestAnimationFrame(done))));

// Stands in for home-frame.js: the page draws the bar (set, Export, Save to Library), so the tool shows no bar of its own.
const withBridge = () => {
    const handlers = {};
    window.__press = (name, detail) => handlers[name]?.(detail);
    window.MothershipFrame = {
        bar: true,
        library: true,
        chrome() {},
        on(name, handler) { handlers[name] = handler; },
        notice() {},
        async folders() { return [{ id: '7', name: 'Plaques', depth: 0 }]; },
        async save({ files }) { return { count: files.length }; },
    };
};

async function open(browser, { width, height, touch = true }) {
    const page = await browser.newPage({ viewport: { width, height }, hasTouch: touch, reducedMotion: 'reduce' });
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.addInitScript(withBridge);
    await page.goto(pageUrl);
    await page.locator('[data-empty] [data-action="add-blank"]').waitFor();
    return { page, errors };
}

// The page that frames the tool tells it where a fold crosses it (home-frame.js).
const fold = (page, mode, start, end) => page.evaluate(([mode, start, end]) => {
    const html = document.documentElement;
    html.dataset.fold = mode;
    html.style.setProperty('--fold-start', `${start}px`);
    html.style.setProperty('--fold-end', `${end}px`);
}, [mode, start, end]);

async function addPlaques(page, count) {
    await page.locator('[data-empty] [data-action="add-blank"]').click();
    for (let i = 1; i < count; i++) await page.locator('[data-action="add-blank"]').first().evaluate(button => button.click());
    await page.locator('[data-list] .row').nth(count - 1).waitFor({ state: 'attached' });
    // A new plaque opens Edit on a phone; the list is where the set is worked.
    await page.locator('[data-view-opt="plaques"]').evaluate(button => button.click());
    await settled(page);
}

const box = (page, selector) => page.locator(selector).first().evaluate(node => {
    const r = node.getBoundingClientRect();
    return { left: r.left, top: r.top, right: r.right, bottom: r.bottom, width: r.width, height: r.height };
});

// Every control a finger can land on, with the ones that cross the band between `from` and `to` on the given axis.
const across = (page, axis, from, to) => page.evaluate(([axis, from, to]) => [...document.querySelectorAll('main button, main input, main select, main textarea, main [role="tab"]')]
    .filter(node => node.checkVisibility())
    .filter(node => {
        const r = node.getBoundingClientRect();
        if (!r.width || !r.height) return false;
        return axis === 'y' ? r.top < to && r.bottom > from : r.left < to && r.right > from;
    })
    .map(node => node.getAttribute('aria-label') || node.textContent.trim().slice(0, 30) || node.tagName), [axis, from, to]);

browserTest('folded like a laptop, the stage has the upright half and Play and the scrubber sit on the flat half', async () => {
    const browser = await launch();
    try {
        // An iPhone Duo in tabletop: the tool under the page's 64 px bar, with a 40 px hinge band.
        const { page, errors } = await open(browser, { width: 669, height: 761 });
        await fold(page, 'rows', 286, 326);
        await addPlaques(page, 2);
        assert.deepEqual(await across(page, 'y', 286, 326), [], 'nothing to tap on the fold');
        assert.ok((await box(page, '[data-stage]')).bottom <= 286, 'the stage ends above the fold');
        const transport = await box(page, '[data-transport]');
        assert.ok(transport.top >= 326, `the transport starts below the fold: ${transport.top}`);
        assert.ok((await box(page, '.plq-seg')).top >= transport.bottom, 'the segment follows the transport');
        assert.ok((await box(page, '[data-action="play"]')).top >= 326, 'Play is on the flat half');
        assert.ok((await box(page, '[data-scrub]')).top >= 326, 'the scrubber is on the flat half');
        const stage = await box(page, '[data-stage]');
        assert.ok(stage.height >= 200, `the stage fills the upright half: ${stage.height}`);
        assert.deepEqual(errors, []);
    } finally { await browser.close(); }
});

browserTest('on the outer display upright, a set with plaques keeps the preview in place and scrolls only the pane', async () => {
    const browser = await launch();
    try {
        // 382 x 463: the outer display in Safari once the page's foot (Export, Save to Library) shows; Split View is close.
        for (const [width, height] of [[382, 463], [385, 510]]) {
            const { page, errors } = await open(browser, { width, height });
            await addPlaques(page, 6);
            const tool = await page.locator('.plq').evaluate(node => ({ scroll: node.scrollHeight - node.clientHeight, overflow: getComputedStyle(node).overflowY }));
            assert.ok(tool.overflow !== 'auto' || tool.scroll <= 1, `${width} x ${height}: the tool itself does not scroll (${tool.overflow}, +${tool.scroll})`);
            const pane = await page.locator('[data-pane="plaques"]').evaluate(node => node.scrollHeight - node.clientHeight);
            assert.ok(pane > 0, `${width} x ${height}: the list scrolls by itself`);
            const stage = await box(page, '[data-stage]');
            assert.ok(stage.top >= 0 && stage.bottom <= height, `${width} x ${height}: the stage is in view`);
            assert.ok(await page.evaluate(() => document.scrollingElement.scrollHeight <= innerHeight), 'the page does not scroll');
            assert.deepEqual(errors, []);
            await page.close();
        }
    } finally { await browser.close(); }
});

browserTest('held sideways on a short screen, the tool scrolls as one and its page never moves', async () => {
    const browser = await launch();
    try {
        // The outer display on its side in Safari and as a window, and a phone on its side.
        for (const [width, height] of [[594, 312], [594, 368], [678, 340], [700, 390]]) {
            const { page } = await open(browser, { width, height });
            await addPlaques(page, 6);
            const tool = await page.locator('.plq').evaluate(node => node.scrollHeight - node.clientHeight);
            assert.ok(tool > 0, `${width} x ${height}: the tool scrolls as one`);
            const spill = await page.evaluate(() => document.scrollingElement.scrollHeight - innerHeight);
            assert.ok(spill <= 0, `${width} x ${height}: the page scrolls ${spill} px`);
            // Safari places a visually hidden label by the box that holds it: held outside the tool's scroller, the
            // label's spot past the bottom of the screen made the page itself scroll (23 px on the outer display).
            const loose = await page.evaluate(() => [...document.querySelectorAll('.plq .tk-hidden')]
                .filter(node => node.offsetParent && !document.querySelector('.plq').contains(node.offsetParent))
                .map(node => node.textContent || node.outerHTML.slice(0, 60)));
            assert.deepEqual(loose, [], `${width} x ${height}: hidden labels are held inside the tool`);
            await page.close();
        }
    } finally { await browser.close(); }
});

browserTest('on a touch screen every control of the tool is a 44 px target, and the Move buttons keep clear of the title', async () => {
    const browser = await launch();
    try {
        const { page } = await open(browser, { width: 390, height: 844 });
        assert.ok(await page.evaluate(() => matchMedia('(pointer: coarse)').matches), 'the page sees a touch screen');
        const small = selector => page.locator(selector).evaluateAll(nodes => nodes.filter(node => node.checkVisibility()).map(node => {
            const r = node.getBoundingClientRect();
            return { name: node.getAttribute('aria-label') || node.textContent.trim().slice(0, 24) || node.className, width: r.width, height: r.height };
        }).filter(target => target.height < 44 || target.width < 44));
        const short = async (selector, label) => {
            const found = await page.locator(selector).evaluateAll(nodes => nodes.filter(node => node.checkVisibility()).length);
            assert.ok(found > 0, `${label} are shown`);
            assert.deepEqual(await small(selector), [], label);
        };

        await short('[data-empty] .tk-btn', 'the empty set\'s buttons');
        await addPlaques(page, 3);
        await short('.plq-seg .tk-seg-opt', 'the segment');
        await short('[data-action="play"]', 'Play');
        await short('[data-scrub]', 'the scrubber');
        await short('.plq-mute', 'Mute');
        await short('[data-set-name]', 'the set name');
        await short('[data-list-tools] .tk-link', 'Select all and Clear selection');
        await short('[data-list] .row.is-current .row-move button', 'Move up and Move down');
        await short('[data-list] .row-check', 'the row checkboxes');
        const checked = await page.locator('[data-list] .row').nth(1).locator('.row-check').evaluate(label => {
            const r = label.getBoundingClientRect();
            const hit = document.elementFromPoint(r.left + 3, r.top + 3);
            return Boolean(hit && label.contains(hit));
        });
        assert.ok(checked, 'the corner of the checkbox\'s target belongs to it');
        await page.locator('[data-list] .row').nth(1).locator('.row-check').click({ position: { x: 4, y: 4 } });
        assert.equal(await page.locator('[data-list] .row').nth(1).locator('[data-select]').isChecked(), true, 'a tap at the edge of the target ticks the box');
        assert.equal(await page.locator('[data-list] .row').nth(1).getAttribute('aria-current'), 'false', 'and does not open that plaque');

        // The current row's Move buttons sit beside its title, never over it.
        await page.locator('[data-list] .row').first().locator('.row-text').click();
        const overlap = await page.locator('[data-list] .row.is-current').evaluate(row => {
            const title = row.querySelector('.row-text strong').getBoundingClientRect(), move = row.querySelector('.row-move').getBoundingClientRect();
            return Math.max(0, Math.min(title.right, move.right) - Math.max(title.left, move.left)) * Math.max(0, Math.min(title.bottom, move.bottom) - Math.max(title.top, move.top));
        });
        assert.equal(overlap, 0, 'Move buttons cover none of the title');

        await page.locator('[data-view-opt="edit"]').click();
        const tall = await page.locator('[data-pane="edit"] :is(button, select, textarea, input:not([type="checkbox"], [type="file"]), label.tk-check)').evaluateAll(nodes => nodes
            .filter(node => node.checkVisibility())
            .map(node => ({ name: node.getAttribute('aria-label') || node.textContent.trim().slice(0, 24) || node.type, height: node.getBoundingClientRect().height })));
        assert.ok(tall.length > 20, 'Edit\'s controls are shown');
        assert.deepEqual(tall.filter(target => target.height < 44), [], 'Edit\'s segments, icons, colours, fields and sliders');
        await short('[data-pane="edit"] .icon-grid button', 'the icon grid');
        await page.locator('[data-view-opt="output"]').click();
        await short('[data-anchors] button', 'the placement grid');
        await page.locator('[data-view-opt="plaques"]').click();

        await page.evaluate(() => document.querySelector('[data-action="open-presets"]').click());
        await page.locator('[data-sheet="presets"]').waitFor();
        await short('[data-preset-tabs] .tk-seg-opt', 'the preset groups');
        await page.keyboard.press('Escape');
        await page.evaluate(() => window.__press('manage-sets'));
        await page.locator('[data-sheet="open"]').waitFor();
        await short('[data-sheet="open"] [data-action="import-text"]', 'Open the pasted text');
    } finally { await browser.close(); }
});

browserTest('a narrow Export sheet gives Copy for an agent its own row, wherever the window is wide', async () => {
    const browser = await launch();
    try {
        // Open like a book: the sheet keeps to the trailing half, 423 px wide.
        const { page } = await open(browser, { width: 951, height: 543, touch: false });
        await fold(page, 'columns', 456, 496);
        await addPlaques(page, 1);
        await page.evaluate(() => window.__press('export'));
        await page.locator('[data-sheet="export"]').waitFor();
        await page.locator('[data-export-format]').selectOption('json');
        await settled(page);
        const sheet = await box(page, '[data-sheet="export"]');
        assert.ok(sheet.width < 560, `the sheet is narrow: ${sheet.width}`);
        const copy = await box(page, '[data-sheet="export"] [data-action="copy-agent"]');
        const close = await box(page, '[data-sheet="export"] [data-export-close]');
        const download = await box(page, '[data-sheet="export"] [data-action="run-export"]');
        assert.ok(copy.bottom <= close.top, 'Copy for an agent sits above Close');
        assert.ok(Math.abs(close.top - download.top) < 1, 'Close and Download share a row');
        assert.ok(close.right <= download.left, 'Close comes before Download');
    } finally { await browser.close(); }
});

// Reads the preview's pixels for the bounds of what is drawn, in CSS px of the canvas on screen.
const drawn = page => page.locator('[data-preview]').evaluate(canvas => {
    const { width, height } = canvas, pixels = canvas.getContext('2d').getImageData(0, 0, width, height).data;
    let left = width, right = -1, top = height, bottom = -1;
    for (let y = 0; y < height; y += 2) for (let x = 0; x < width; x += 2) {
        if (pixels[(y * width + x) * 4 + 3] > 200) { left = Math.min(left, x); right = Math.max(right, x); top = Math.min(top, y); bottom = Math.max(bottom, y); }
    }
    const r = canvas.getBoundingClientRect(), k = r.width / width;
    return { canvas: r.width, plaque: (right - left) * k, plaqueHeight: (bottom - top) * k };
});

browserTest('on a narrow stage the preview frames the plaque close up; a wide stage shows the whole frame', async () => {
    const browser = await launch();
    try {
        const { page } = await open(browser, { width: 382, height: 463 });
        await addPlaques(page, 1);
        await page.waitForTimeout(300);
        const narrow = await drawn(page);
        assert.ok(narrow.canvas < 480, `the stage is narrow: ${narrow.canvas}`);
        assert.ok(narrow.plaque >= narrow.canvas * 0.7, `the plaque fills the stage: ${narrow.plaque} of ${narrow.canvas}`);
        assert.equal(await page.locator('[data-action="close-up"]').getAttribute('aria-pressed'), 'true', 'the close-up says it is on');
        const toggle = await box(page, '[data-action="close-up"]');
        assert.ok(toggle.width >= 44 && toggle.height >= 44, `the switch is a 44 px target on touch: ${toggle.width} x ${toggle.height}`);
        // From the keyboard: Space on the focused switch turns it off and does not start playback.
        await page.locator('[data-action="close-up"]').focus();
        // The switch is painted on the next animation frame, so wait for its state rather than read it at once.
        const pressedBecomes = value => page.waitForFunction(v => document.querySelector('[data-action="close-up"]').getAttribute('aria-pressed') === v, value, { timeout: 3000 }).catch(() => {});
        await page.keyboard.press('Space');
        await pressedBecomes('false');
        assert.equal(await page.locator('[data-action="close-up"]').getAttribute('aria-pressed'), 'false', 'Space turns close-up off');
        assert.equal(await page.locator('[data-play-label]').textContent(), 'Play', 'and does not play');
        const whole = await drawn(page);
        assert.ok(whole.plaque < narrow.plaque * 0.7, `the whole frame shows when asked: ${whole.plaque}`);
        await page.keyboard.press('Enter');
        await pressedBecomes('true');
        assert.equal(await page.locator('[data-action="close-up"]').getAttribute('aria-pressed'), 'true', 'Enter turns it back on');

        // The Output pane sets the frame, so it shows the whole frame unless close-up is asked for there.
        await page.close();
        const fresh = await open(browser, { width: 382, height: 463 });
        await addPlaques(fresh.page, 1);
        await fresh.page.locator('[data-view-opt="output"]').click();
        await fresh.page.waitForTimeout(150);
        assert.equal(await fresh.page.locator('[data-action="close-up"]').getAttribute('aria-pressed'), 'false', 'Output shows the whole frame');
        assert.ok((await drawn(fresh.page)).plaque < narrow.plaque * 0.7, 'the whole frame is drawn in Output');
        await fresh.page.close();

        const wide = await open(browser, { width: 1440, height: 900, touch: false });
        await addPlaques(wide.page, 1);
        await wide.page.waitForTimeout(300);
        const desk = await drawn(wide.page);
        assert.ok(desk.canvas >= 480, `the stage is wide: ${desk.canvas}`);
        assert.ok(desk.plaque < desk.canvas * 0.6, `the whole frame shows: ${desk.plaque} of ${desk.canvas}`);
        assert.equal(await wide.page.locator('[data-action="close-up"]').isVisible(), false, 'no close-up switch on a wide stage');
    } finally { await browser.close(); }
});

browserTest('the close-up follows the stage as the window changes, holds steady while it is shown, and stands down for tight framing', async () => {
    const browser = await launch();
    try {
        const { page, errors } = await open(browser, { width: 382, height: 463 });
        await addPlaques(page, 2);
        const toggle = page.locator('[data-action="close-up"]');
        const pressed = () => toggle.getAttribute('aria-pressed');
        assert.equal(await pressed(), 'true');
        // A desktop window: the stage is wide and shows the whole frame.
        await page.setViewportSize({ width: 1440, height: 900 });
        await page.waitForTimeout(200);
        assert.equal(await toggle.isVisible(), false, 'no switch on a wide stage');
        assert.ok((await drawn(page)).plaque < 0.6 * (await drawn(page)).canvas, 'the whole frame on a wide stage');
        await page.setViewportSize({ width: 382, height: 463 });
        await page.waitForTimeout(200);
        assert.equal(await pressed(), 'true', 'closed again: close-up again');
        // Steady: the stage and the canvas keep their size frame after frame.
        const sizes = await page.evaluate(() => new Promise(done => {
            const seen = [];
            const tick = () => {
                const stage = document.querySelector('[data-stage]').getBoundingClientRect(), canvas = document.querySelector('[data-preview]');
                seen.push(`${Math.round(stage.width)}x${Math.round(stage.height)} ${canvas.width}x${canvas.height}`);
                if (seen.length < 12) requestAnimationFrame(tick); else done([...new Set(seen)]);
            };
            requestAnimationFrame(tick);
        }));
        assert.equal(sizes.length, 1, `one size throughout: ${sizes.join(', ')}`);
        // Tight framing already fills the canvas with the plaque: no close-up, no switch.
        await page.locator('[data-view-opt="output"]').click();
        await page.locator('[data-out="mode"]').selectOption('tight');
        await page.locator('[data-view-opt="plaques"]').click();
        await page.waitForTimeout(150);
        assert.equal(await toggle.isVisible(), false, 'no switch with tight framing');
        assert.deepEqual(errors, []);
    } finally { await browser.close(); }
});

browserTest('with a mouse the list rows, the transport and the segment keep their desktop sizes at 1440 and on a 390 phone', async () => {
    const browser = await launch();
    try {
        for (const [width, height] of [[1440, 900], [390, 844]]) {
            const { page } = await open(browser, { width, height, touch: false });
            await addPlaques(page, 3);
            assert.equal(await page.evaluate(() => matchMedia('(pointer: coarse)').matches), false, 'the page sees a mouse');
            const sizes = await page.evaluate(() => {
                const h = selector => Math.round(document.querySelector(selector).getBoundingClientRect().height);
                const row = document.querySelector('[data-list] .row.is-current');
                return {
                    columns: getComputedStyle(row).gridTemplateColumns.split(' ').length,
                    move: getComputedStyle(row.querySelector('.row-move')).position,
                    checkbox: h('[data-list] .row [data-select]'),
                    play: h('[data-action="play"]'),
                    segment: document.querySelector('.plq-seg').checkVisibility() ? h('.plq-seg .tk-seg-opt') : 36,
                    scrub: h('[data-scrub]'),
                };
            });
            assert.deepEqual({ ...sizes, scrub: sizes.scrub < 30 }, { columns: 3, move: 'absolute', checkbox: 18, play: 40, segment: 36, scrub: true }, `${width} x ${height}`);
            await page.close();
        }
    } finally { await browser.close(); }
});

// The transport's controls and where they sit, and how much of the open pane is left in view under the tool.
const transport = page => page.evaluate(() => {
    const at = selector => {
        const node = document.querySelector(selector);
        if (!node || !node.checkVisibility()) return null;
        const r = node.getBoundingClientRect();
        return { left: Math.round(r.left), right: Math.round(r.right), top: Math.round(r.top), bottom: Math.round(r.bottom), middle: Math.round(r.top + r.height / 2), width: Math.round(r.width), height: Math.round(r.height) };
    };
    const tool = document.querySelector('.plq').getBoundingClientRect();
    // The pane the segment picked (Edit beside it is always in view on a wide tool).
    const view = document.querySelector('.plq').dataset.view;
    const pane = [...document.querySelectorAll('.plq [data-pane]')].filter(node => node.checkVisibility()).find(node => node.dataset.pane === view) || document.querySelector('.plq [data-pane="plaques"]');
    const p = pane.getBoundingClientRect();
    const t = document.querySelector('.plq-transport');
    const tb = t.getBoundingClientRect();
    return {
        box: { left: Math.round(tb.left), right: Math.round(tb.right) },
        height: Math.round(t.getBoundingClientRect().height),
        overflow: t.scrollWidth > t.clientWidth + 1,
        label: document.querySelector('[data-play-label]').getBoundingClientRect().width > 2,
        pane: pane.dataset.pane,
        visible: Math.round(Math.min(p.bottom, tool.bottom) - Math.max(p.top, tool.top)),
        play: at('[data-action="play"]'), scrub: at('[data-scrub]'), clock: at('[data-clock]'),
        back: at('[data-action="step-back"]'), forward: at('[data-action="step-forward"]'), scope: at('[data-scope]'),
        mute: at('.plq-mute'), closeUp: at('[data-action="close-up"]'),
    };
});

// Two rows: Play, the scrubber and the clock, then the steps, what to play, Mute and Close-up (when it applies), each a
// 44 px target, none overlapping another or the transport's edges.
function assertTwoRows(t, label) {
    assert.ok(t.height <= 100, `${label}: the transport takes two rows: ${t.height}`);
    assert.equal(t.overflow, false, `${label}: nothing overflows the row`);
    for (const name of ['play', 'scrub', 'clock', 'back', 'forward', 'scope', 'mute']) assert.ok(t[name], `${label}: ${name} stays in view`);
    const first = ['play', 'scrub', 'clock'];
    const second = ['back', 'forward', 'scope', 'mute', ...(t.closeUp ? ['closeUp'] : [])];
    for (const name of first) assert.ok(Math.abs(t[name].middle - t.play.middle) <= 4, `${label}: ${name} shares the first row`);
    for (const name of second) {
        assert.ok(Math.abs(t[name].middle - t.back.middle) <= 4 && t[name].top >= t.play.bottom, `${label}: ${name} sits on the second row`);
        assert.ok(t[name].height >= 44, `${label}: ${name} is a 44 px target: ${t[name].height}`);
    }
    for (const row of [first, second]) {
        const boxes = row.map(name => ({ name, ...t[name] })).sort((a, b) => a.left - b.left);
        boxes.forEach((b, i) => {
            assert.ok(b.left >= t.box.left - 1 && b.right <= t.box.right + 1, `${label}: ${b.name} stays inside the transport`);
            if (i) assert.ok(b.left >= boxes[i - 1].right, `${label}: ${b.name} does not overlap ${boxes[i - 1].name}`);
        });
    }
    assert.ok(t.play.width >= 44 && t.play.height >= 44, `${label}: Play is a 44 px target`);
    assert.equal(t.label, false, `${label}: Play shows its glyph only`);
}

browserTest('on a short tool under touch the transport takes two rows, hides nothing, and the pane gains the room', async () => {
    const browser = await launch();
    try {
        // The outer display upright, the inner display flat and as a book, and a phone on its side.
        for (const size of [
            { width: 382, height: 463, view: 'edit', pane: 150 },
            { width: 951, height: 479, view: 'plaques', pane: 95 },
            { width: 951, height: 479, fold: ['columns', 476, 476], view: 'plaques', pane: 150 },
            { width: 594, height: 312 },
        ]) {
            const label = `${size.width} x ${size.height}${size.fold ? ' book' : ''}`;
            const { page, errors } = await open(browser, size);
            if (size.fold) await fold(page, ...size.fold);
            await addPlaques(page, 3);
            if (size.view) await page.locator(`[data-view-opt="${size.view}"]`).evaluate(button => button.click());
            await settled(page);
            const t = await transport(page);
            // Each of these stages shows the whole frame under 480 px, so Close-up applies and must be in view.
            assert.ok(t.closeUp, `${label}: Close-up stays in view`);
            assertTwoRows(t, label);
            assert.equal(await page.getByRole('button', { name: 'Play', exact: true }).count(), 1, `${label}: Play keeps its name for assistive tech`);
            if (size.pane) assert.ok(t.visible >= size.pane, `${label}: the ${t.pane} pane has room: ${t.visible}`);
            assert.deepEqual(errors, []);
            await page.close();
        }

        // Tight framing stands Close-up down; the two rows hold and Mute closes the second row.
        {
            const { page } = await open(browser, { width: 382, height: 463 });
            await addPlaques(page, 1);
            await page.locator('[data-view-opt="output"]').evaluate(button => button.click());
            await page.locator('[data-out="mode"]').selectOption('tight');
            await settled(page);
            const t = await transport(page);
            assert.equal(t.closeUp, null, 'tight framing: no Close-up');
            assertTwoRows(t, 'tight framing');
            assert.ok(Math.abs(t.mute.right - t.clock.right) <= 2, `tight framing: Mute ends under the clock: ${t.mute.right} vs ${t.clock.right}`);
            await page.close();
        }

        // Taller tools under touch and a short window under a mouse keep the transport as it was.
        for (const size of [
            { width: 390, height: 664 },
            { width: 669, height: 761, fold: ['rows', 286, 286] },
            { width: 951, height: 479, touch: false },
        ]) {
            const label = `${size.width} x ${size.height}${size.touch === false ? ' mouse' : ''}`;
            const { page } = await open(browser, size);
            if (size.fold) await fold(page, ...size.fold);
            await addPlaques(page, 3);
            const t = await transport(page);
            assert.equal(t.label, true, `${label}: Play keeps its word`);
            assert.ok(t.scrub.top >= t.play.bottom, `${label}: the scrubber keeps its own row under Play`);
            await page.close();
        }
    } finally { await browser.close(); }
});
