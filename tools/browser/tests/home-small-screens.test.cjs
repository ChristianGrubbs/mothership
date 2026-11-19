const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {launchBrowser} = require('../launch-browser.cjs');
const {homeApp, homeAnswersSource, homeScripts, homeStyles} = require('./support/home-fixture.cjs');

// Home on short and narrow screens, such as a folding phone's outer display, and on the same phone opened: where the
// phone layout ends, how the phone sheet opens, and what stays on the first screen. The page is Home's own markup,
// stylesheet and scripts inside a stand-in for the admin shell.
const root = path.resolve(__dirname, '../../..');
const deviceCss = fs.readFileSync(path.join(root, 'public/pwa/device.css'), 'utf8');
const deviceJs = fs.readFileSync(path.join(root, 'public/pwa/device.js'), 'utf8');
// The admin's own text face, so lines break here as they do in the product on any machine.
const uiFont = fs.readFileSync(path.join(root, 'packages/Mothership/Admin/src/Resources/assets/fonts/instrument-sans-latin-standard-normal.woff2')).toString('base64');
const playwrightPath = process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH;
const browserTest = playwrightPath ? test : test.skip;
const ORIGIN = 'https://home.test';

// The shell's parts that Home lays out against, at the sizes the shell gives them on a phone: a 60px top bar, the page
// scrolling inside #main-content, the analytics notice under it, a 78px tab bar fixed to the bottom, and the
// assistant's round button floating bottom right. Headings and paragraphs carry no margins, as under the shell's reset.
const SHELL_STYLES = `@font-face{font-family:"Instrument Sans";font-weight:400 700;src:url(data:font/woff2;base64,${uiFont}) format("woff2")}
:root{--font-ui:"Instrument Sans",sans-serif;--font-mono:ui-monospace,monospace}*{box-sizing:border-box}h1,h2,h3,p{margin:0}body{margin:0;font:15px/1.4 var(--font-ui)}
.shell{display:flex;flex-direction:column;height:100vh}.shell>header{flex:none;height:60px}
#main-content{flex:1;min-height:0;overflow-y:auto;padding:12px 16px 0}
footer[data-fold-wrap]{flex:none;padding:4px 16px;font-size:12px;line-height:1.35}
[data-bridge-tabbar]{position:fixed;left:0;right:0;bottom:0;height:78px}
.ap-fab{position:fixed;right:24px;bottom:24px;width:52px;height:52px;border-radius:50%}`;
const shell = `<div class="shell"><header><a href="/admin/dam" class="bridge-top-upload" style="display:none" data-bridge-upload><span>Upload</span></a></header>
<main id="main-content">${homeApp()}</main>
<footer data-fold-wrap>The Mothership measures how its features are used, to improve them. <a href="/privacy">What is collected and how to opt out</a></footer></div>
<nav data-bridge-tabbar aria-label="Sections"></nav><button type="button" class="ap-fab" aria-label="Open the assistant"><svg width="24" height="24"></svg></button>`;

// Five arrivals and something new: the tallest board and deck Home shows without a "more" line.
const arrivals = () => window.__homeSummary({
    lead: {available: true, state: 'new', files: 14, bytes: 155e6, since: null},
    board: {
        rows: ['artcraft', 'claude-code', 'blender', 'codex', 'artcraft'].map((producer, index) => ({status: 'landed', source: 'craft', producer, title: `Landing ${index + 1}`, files: index + 1, bytes: 2048, at: `2026-11-18T1${index}:31:00Z`, previews: []})),
        more: 0, truncated: false, cargo: [],
        docked: [{producer: 'artcraft', last_save_at: '2026-11-18T14:31:00Z', files_24h: 6, hours: Array.from({length: 24}, () => 0)}],
    },
});

// The tallest board a phone shows: five rows, the wrapped line for the rest, and a three-figure count on the deck.
const manyArrivals = summary => { summary.lead.files = 186; summary.lead.since = '2026-11-17T18:05:00Z'; summary.board.more = 4; return summary; };

const settled = page => page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
const stacked = (width, height, gap) => { const top = Math.floor((height - gap) / 2); return [[0, 0, width, top], [0, top + gap, width, height - top - gap]]; };

async function mount(browser, {width, height, segments = null, summary = arrivals, adjust = summary => summary, layoutFailures = 0}) {
    const page = await browser.newPage({viewport: {width, height}, reducedMotion: 'reduce'});
    const errors = []; page.on('pageerror', error => errors.push(error.message));
    if (segments) {
        await page.addInitScript(rects => Object.defineProperty(window, 'viewport', {configurable: true,
            value: {segments: rects.map(rect => new DOMRect(...rect))}}), segments);
    }
    await page.route(`${ORIGIN}/**`, route => route.fulfill({contentType: 'text/html', body: `<!doctype html>${shell}`}));
    await page.goto(`${ORIGIN}/admin/home`);
    await page.addStyleTag({content: `${SHELL_STYLES}${deviceCss}`});
    await page.addStyleTag({content: homeStyles});
    await page.addScriptTag({content: deviceJs});
    await page.addScriptTag({content: homeAnswersSource});
    await page.evaluate(([summary, adjust, layoutFailures]) => {
        const response = (data, status = 200) => new Response(JSON.stringify(data), {status, headers: {'Content-Type': 'application/json'}});
        const answer = (0, eval)(`(${summary})`), adjusted = (0, eval)(`(${adjust})`);
        window.__requests = []; window.__layoutFailures = layoutFailures;
        window.fetch = async (url, options = {}) => {
            const address = String(url); window.__requests.push(`${options.method || 'GET'} ${address}`);
            if (address.endsWith('/summary')) return response(adjusted(answer()));
            if (address.endsWith('/layout') && window.__layoutFailures-- > 0) return response({}, 500);
            return response(window.__homeAnswer(address) ?? {});
        };
    }, [summary.toString(), adjust.toString(), layoutFailures]);
    await page.evaluate(() => Promise.all(['400 15px', '600 17px'].map(face => document.fonts.load(`${face} "Instrument Sans"`))));
    for (const content of homeScripts) await page.addScriptTag({content});
    await page.waitForFunction(() => window.__requests.some(entry => entry.endsWith('/layout')) && !document.querySelector('[data-board]').hasAttribute('aria-busy'));
    await settled(page);

    return {page, errors};
}

// Boxes as [left, top, right, bottom], or null for a part that is not shown.
const boxes = page => page.evaluate(() => {
    const box = selector => { const element = document.querySelector(selector); if (!element) return null; const r = element.getBoundingClientRect(); return r.width && r.height ? [r.left, r.top, r.right, r.bottom] : null; };
    const notice = document.querySelector('footer[data-fold-wrap]'), words = document.createRange();
    words.selectNodeContents(notice);

    return {
        board: box('[data-board]'), deck: box('[data-deck]'), action: box('[data-deck] .hm-btn'), time: box('[data-board-rows] .hm-k.k1'), side: box('[data-grid] > [data-side]'),
        notice: box('footer[data-fold-wrap]'), tabbar: box('[data-bridge-tabbar]'), fab: box('.ap-fab'),
        words: [...words.getClientRects()].map(r => [r.left, r.top, r.right, r.bottom]),
        sheetOpen: document.querySelector('[data-side-sheet]').open, sideways: document.documentElement.scrollWidth - innerWidth,
    };
});
const meets = (a, b) => a[0] < b[2] - 0.5 && a[2] > b[0] + 0.5 && a[1] < b[3] - 0.5 && a[3] > b[1] + 0.5;

browserTest('the phone layout ends at 640px: a wider screen gets the whole board and its side cards on the page', async () => {
    const browser = await launchBrowser({playwrightPath});
    try {
        // 669 wide is a folding phone opened and held upright.
        const open = await mount(browser, {width: 669, height: 867});
        let found = await boxes(open.page);
        assert.ok(found.time, 'the TIME column shows');
        assert.equal(await open.page.locator('[data-board-rows] .hm-frow').first().locator('.hm-k i').evaluateAll(cells => cells.filter(cell => cell.getBoundingClientRect().width).length), 51);
        assert.ok(found.side, 'the side cards are on the page');
        // The tab bar still shows at this width, so the assistant's button docks on the notice strip, off the cards.
        assert.ok(found.fab[1] >= found.notice[1] - 0.5 && found.fab[3] <= found.notice[3] + 0.5, `the assistant's button sits on the notice strip: ${found.fab} in ${found.notice}`);
        assert.ok(!found.words.some(word => meets(word, found.fab)), 'the notice keeps its words clear of the button');
        await open.page.locator('[data-glance-open="away"]').click();
        await settled(open.page);
        assert.equal((await boxes(open.page)).sheetOpen, false, 'a row scrolls to its card; no sheet');
        assert.equal(found.sideways, 0);
        assert.deepEqual(open.errors, []);

        const phone = await mount(browser, {width: 640, height: 867});
        found = await boxes(phone.page);
        assert.equal(found.time, null, 'a phone leaves TIME out');
        assert.equal(await phone.page.locator('[data-board-rows] .hm-frow').first().locator('.hm-k i').evaluateAll(cells => cells.filter(cell => cell.getBoundingClientRect().width).length), 24);
        assert.equal(found.side, null, 'the side cards wait in the sheet');
        await phone.page.locator('[data-glance-open="away"]').click();
        assert.equal((await boxes(phone.page)).sheetOpen, true);
        assert.deepEqual(phone.errors, []);
    } finally {
        await browser.close();
    }
});

browserTest('the phone sheet keeps Close in reach, opens at the tapped card, and closes when the screen outgrows it', async () => {
    const browser = await launchBrowser({playwrightPath});
    try {
        for (const [width, height] of [[382, 580], [594, 368]]) {
            const {page, errors} = await mount(browser, {width, height});
            await page.locator('[data-glance-open="away"]').click();
            await settled(page);
            const sheet = await page.evaluate(() => {
                const rect = element => { const r = element.getBoundingClientRect(); return [r.left, r.top, r.right, r.bottom]; };
                const body = document.querySelector('[data-sheet-body]'), cards = [...body.querySelectorAll('.hm-card')].filter(card => card.getBoundingClientRect().height);
                return {box: rect(document.querySelector('[data-side-sheet]')), close: rect(document.querySelector('[data-sheet-close]')), body: rect(body), away: rect(document.querySelector('[data-away]')),
                    scrolled: body.scrollTop, room: body.scrollHeight - body.clientHeight - body.scrollTop, cards: cards.map(rect), side: rect(body.querySelector('[data-side]'))};
            });
            assert.ok(sheet.close[1] >= sheet.box[1] && sheet.close[3] <= sheet.body[1] + 0.5 && sheet.close[1] >= 0, `Close sits in the sheet's head at ${width}: ${sheet.close}`);
            assert.ok(sheet.close[3] - sheet.close[1] >= 44, 'Close is a full-size touch target');
            // The last card cannot always reach the top; then the body is scrolled as far as it goes.
            assert.ok(Math.abs(sheet.away[1] - sheet.body[1]) <= 4 || sheet.room <= 1, `the sheet opens at the tapped card at ${width}: ${JSON.stringify(sheet)}`);
            if (height < 400) assert.ok(sheet.scrolled > 0, 'on the short screen the body scrolled to reach it');
            assert.ok(sheet.cards.length >= 2, 'the sheet holds the side cards');
            for (const card of sheet.cards) assert.ok(Math.abs(card[0] - sheet.side[0]) <= 0.5 && Math.abs(card[2] - sheet.side[2]) <= 0.5, `each card fills the sheet's width at ${width}: ${card}`);

            // Opening a folding phone ends the phone layout: no sheet is left across the larger screen.
            await page.setViewportSize({width: 951, height: 585});
            await page.waitForFunction(() => !document.querySelector('[data-side-sheet]').open && document.querySelector('[data-grid] > [data-side]'));
            assert.deepEqual(errors, []);
            await page.close();
        }
    } finally {
        await browser.close();
    }
});

browserTest('a short outer display keeps the next step above the notice and the assistant\'s button on the notice strip', async () => {
    const browser = await launchBrowser({playwrightPath});
    try {
        // A folding phone's outer display upright, and the same phone sharing its inner display.
        for (const [width, height] of [[382, 580], [385, 571]]) {
            const {page, errors} = await mount(browser, {width, height});
            const found = await boxes(page);
            assert.ok(found.action[3] <= found.notice[1] + 0.5, `the blue action ends above the notice at ${width}x${height}: ${found.action} against ${found.notice}`);
            assert.deepEqual([found.fab[2] - found.fab[0], found.fab[3] - found.fab[1]], [44, 44]);
            assert.ok(found.fab[1] >= found.notice[1] - 0.5 && found.fab[3] <= found.notice[3] + 0.5, `the assistant's button sits on the notice strip: ${found.fab} in ${found.notice}`);
            assert.ok(!found.words.some(word => meets(word, found.fab)), 'the notice keeps its words clear of the button');
            assert.ok(!meets(found.fab, found.deck) && !meets(found.fab, found.tabbar), 'the button covers neither the deck nor the tab bar');
            assert.equal(found.sideways, 0);
            assert.deepEqual(errors, []);
            await page.close();
        }
        // The tallest board still leaves the whole action above the notice and clear of the button.
        const many = await mount(browser, {width: 382, height: 580, adjust: manyArrivals});
        const full = await boxes(many.page);
        assert.ok(await many.page.locator('[data-board-more]').isVisible(), 'the line for the rest shows');
        assert.ok(full.action[3] <= full.notice[1] + 0.5 && !meets(full.fab, full.action), `with many arrivals the action ends above the notice: ${full.action} against ${full.notice}`);
        assert.deepEqual(many.errors, []);
        // A tall phone keeps the button where it was.
        const tall = await mount(browser, {width: 390, height: 844});
        const fab = (await boxes(tall.page)).fab;
        assert.deepEqual([fab[2] - fab[0], 844 - fab[3]], [52, 128]);
    } finally {
        await browser.close();
    }
});

browserTest('on its side the outer display stands the deck beside the board, with the next step on the first screen', async () => {
    const browser = await launchBrowser({playwrightPath});
    try {
        const {page, errors} = await mount(browser, {width: 594, height: 368});
        const found = await boxes(page);
        assert.ok(found.deck[0] >= found.board[2] - 0.5 && Math.abs(found.deck[1] - found.board[1]) <= 0.5, `the deck stands beside the board: ${found.deck} and ${found.board}`);
        assert.equal(found.time, null, 'the board keeps its phone columns');
        assert.ok(found.action[3] <= found.notice[1] + 0.5, `the blue action ends above the notice: ${found.action} against ${found.notice}`);
        assert.ok(found.action[2] - found.action[0] >= 150, 'the action keeps a readable width');
        assert.ok(!meets(found.fab, found.deck) && !meets(found.fab, found.board), 'the assistant\'s button covers neither');
        assert.equal(found.sideways, 0);
        assert.deepEqual(errors, []);

        // The tallest board makes the object taller; the deck reads from the top, so the action keeps its place.
        const many = await mount(browser, {width: 594, height: 368, adjust: manyArrivals});
        const full = await boxes(many.page);
        assert.ok(await many.page.locator('[data-board-more]').isVisible(), 'the line for the rest shows');
        assert.ok(full.action[3] <= full.notice[1] + 0.5 && !meets(full.fab, full.action), `with many arrivals the action ends above the notice: ${full.action} against ${full.notice}`);
        assert.deepEqual(many.errors, []);
    } finally {
        await browser.close();
    }
});

browserTest('folded like a laptop the board ends at the fold and the deck starts below it, wherever the page has scrolled', async () => {
    const browser = await launchBrowser({playwrightPath});
    try {
        const flat = await mount(browser, {width: 669, height: 867});
        const resting = await boxes(flat.page);
        assert.ok(Math.abs(resting.deck[1] - resting.board[3]) <= 0.5, 'unfolded, the deck follows the board');

        for (const gap of [0, 40]) {
            const [[, , , above], [, below]] = stacked(669, 867, gap);
            const {page, errors} = await mount(browser, {width: 669, height: 867, segments: stacked(669, 867, gap)});
            assert.equal(await page.evaluate(() => document.documentElement.dataset.fold), 'rows');
            const check = async when => {
                const found = await boxes(page);
                assert.ok(Math.abs(found.board[3] - above) <= 1, `the board ends at the fold ${when} (${gap}px fold): ${found.board}`);
                assert.ok(Math.abs(found.deck[1] - below) <= 1 && found.action[1] >= below, `the deck and its action start below the fold ${when} (${gap}px fold): ${found.deck}`);
            };
            await check('at rest');
            // The fold layer measures again on every resize; a measurement taken mid-page must not move the board.
            await page.evaluate(() => { document.querySelector('#main-content').scrollTop = 240; dispatchEvent(new Event('resize')); });
            await settled(page);
            await page.evaluate(() => { document.querySelector('#main-content').scrollTop = 0; });
            await settled(page);
            await check('after a resize while scrolled');
            assert.deepEqual(errors, []);
            await page.close();
        }

        // A failed load puts a status line and Retry above the board. The board still ends at the fold while the line
        // shows, and again once a retry clears it.
        const [[, , , above], [, below]] = stacked(669, 867, 0);
        const failed = await mount(browser, {width: 669, height: 867, segments: stacked(669, 867, 0), layoutFailures: 1});
        const retry = failed.page.locator('[data-home-retry]');
        await retry.waitFor();
        await settled(failed.page);
        const pushed = await boxes(failed.page);
        assert.ok(Math.abs(pushed.board[3] - above) <= 1 && Math.abs(pushed.deck[1] - below) <= 1, `with the status line showing the board ends at the fold: ${pushed.board}`);
        await retry.click();
        await failed.page.waitForFunction(() => !document.querySelector('[data-status]').textContent.trim());
        await settled(failed.page);
        const cleared = await boxes(failed.page);
        assert.ok(cleared.board[1] < pushed.board[1], 'the board moved up when the line went');
        assert.ok(Math.abs(cleared.board[3] - above) <= 1 && Math.abs(cleared.deck[1] - below) <= 1, `after the retry the board ends at the fold: ${cleared.board}`);
        assert.deepEqual(failed.errors, []);
    } finally {
        await browser.close();
    }
});
