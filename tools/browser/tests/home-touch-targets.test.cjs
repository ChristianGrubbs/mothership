const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {launchBrowser} = require('../launch-browser.cjs');
const {homeApp, homeAnswersSource, homeScripts, homeStyles} = require('./support/home-fixture.cjs');

// On a touch screen every control on Home and in its dialogs is a 44 px press area (Apple's HIG); a mouse keeps the
// compact sizes. The page is Home's own markup, stylesheets and scripts, with the calendar module the page loads.
const root = path.resolve(__dirname, '../../..');
const deviceCss = fs.readFileSync(path.join(root, 'public/pwa/device.css'), 'utf8');
const deviceJs = fs.readFileSync(path.join(root, 'public/pwa/device.js'), 'utf8');
const calendarJs = fs.readFileSync(path.join(root, 'packages/Mothership/DAM/src/Resources/assets/js/home-calendar.js'), 'utf8');
const playwrightPath = process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH;
const browserTest = playwrightPath ? test : test.skip;
const ORIGIN = 'https://home.test';

// The iPhone Duo's outer display held upright, the inner one folded flat on a table (stacked) and held like a book.
const POSES = {
    outer: {width: 382, height: 524},
    tabletop: {width: 669, height: 867, segments: [[0, 0, 669, 433], [0, 433, 669, 434]]},
    book: {width: 951, height: 585, segments: [[0, 0, 475, 585], [476, 0, 475, 585]]},
};
const GIF = 'data:image/gif;base64,R0lGODlhAQABAAAAACw=';

async function mount(browser, {width, height, segments = null, touch = true, trending = 'fail'}) {
    const context = await browser.newContext({viewport: {width, height}, hasTouch: touch, isMobile: touch, reducedMotion: 'reduce'});
    const page = await context.newPage();
    const errors = []; page.on('pageerror', error => errors.push(error.message));
    if (segments) {
        await page.addInitScript(rects => Object.defineProperty(window, 'viewport', {configurable: true,
            value: {segments: rects.map(rect => new DOMRect(...rect))}}), segments);
    }
    await page.route(`${ORIGIN}/**`, route => route.fulfill({contentType: 'text/html',
        body: `<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1">${homeApp({superuser: true})}`}));
    await page.goto(`${ORIGIN}/admin/home`);
    // The order the page uses: the device stylesheet in the head, Home's own stylesheet after it in the body.
    await page.addStyleTag({content: `*{box-sizing:border-box}body{margin:0}${deviceCss}`});
    await page.addStyleTag({content: homeStyles});
    await page.addScriptTag({content: deviceJs});
    await page.addScriptTag({content: homeAnswersSource});
    const now = new Date();
    const day = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
    await page.evaluate(({day, trending, GIF}) => {
        const response = (data, status = 200) => new Response(JSON.stringify(data), {status, headers: {'Content-Type': 'application/json'}});
        const event = {id: 7, title: 'Gallery opening', kind: 'event', source: 'manual', category: 'Arts', status: 'confirmed', starts_at: `${day}T18:00:00`,
            ends_at: `${day}T20:00:00`, location: 'Main hall', tasks: 2, tasks_done: 0, unassigned: 1, assets: 1, mine: 1};
        const repo = index => ({repo: `o/r${index}`, url: `https://github.com/o/r${index}`, description: 'About it', language: 'Python', stars: 1200, starsToday: 50,
            rank: index + 1, bestRank: index + 1, firstSeenAt: Date.now() - 7200000, lastSeenAt: Date.now(), onList: true});
        window.__requests = [];
        window.fetch = async (url, options = {}) => {
            const address = String(url), at = address.split('?')[0];
            window.__requests.push(`${options.method || 'GET'} ${at}`);
            if (at.endsWith('/layout')) return response({catalog: [{id: 'calendar', title: 'Calendar', kind: 'widget', url: null, settings: {}}], widgets: [{id: 'calendar', settings: {}}], links: []});
            if (at.endsWith('/calendar/events')) return response({events: [event, {...event, id: 8, title: 'Board meeting', tasks: 0, unassigned: 0, assets: 0}]});
            if (at.endsWith('/calendar/people')) return response({people: [{id: 1, name: 'Test'}, {id: 2, name: 'Sam'}]});
            if (at.endsWith('/calendar/events/7')) return response({event: {...event, can_edit: true, can_delete: true, url: 'https://example.com/e'}, hidden_assets: 0,
                tasks: [{id: 1, title: 'Photos', role: 'Photographer', status: 'open', assignee: null, parent_id: null, due_on: day},
                    {id: 2, title: 'Edit', role: null, status: 'open', assignee: {id: 1, name: 'Test'}, parent_id: 1, due_on: null}],
                assets: [{id: 3, link_id: 30, task_id: null, name: 'cover.jpg', url: '/admin/dam/3', thumbnail: GIF}]});
            if (at.endsWith('/calendar/tasks/1')) return response({ok: true});
            if (at.endsWith('/calendar/assets')) return response({assets: []});
            if (at.endsWith('/admin/dam/directory')) return response({data: [{id: 1, name: 'Events', path_names: ['Events']}, {id: 2, name: 'Sport', path_names: ['Sport']}]});
            if (at.endsWith('/service/trending/v1/trending')) return trending === 'fail' ? response({message: 'offline'}, 503)
                : response({date: day, status: 'ok', updatedAt: Date.now() - 3600000, repos: Array.from({length: 6}, (_, index) => repo(index)),
                    days: [{date: day, count: 6}, {date: '2026-09-10', count: 1}]});
            if (at.includes('/service/rss/')) return response({message: 'offline'}, 503);
            return response(window.__homeAnswer(address) ?? {});
        };
    }, {day, trending, GIF});
    // Home's scripts in page order; the calendar loads just before home.js, which starts the page and so comes last.
    for (const content of [...homeScripts.slice(0, -1), calendarJs, homeScripts.at(-1)]) await page.addScriptTag({content});
    await page.waitForFunction(() => !document.querySelector('[data-board]').hasAttribute('aria-busy'));
    await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));

    return {page, context, errors};
}

// Every control shown inside `scope`, named, with its width and height. A box inside its own label is pressed through
// the label, so the label is measured instead.
const controls = (page, scope) => page.evaluate(scope => [...document.querySelector(scope).querySelectorAll(
    'a[href], button, input:not([type=hidden]), select, textarea, label.agh-button, label.agh-cal-check, label.agh-cal-done')]
    .filter(element => {
        if (element.matches('input[type=checkbox]') && element.closest('label.agh-cal-check, label.agh-cal-done')) return false;
        const box = element.getBoundingClientRect();
        return box.width && box.height && getComputedStyle(element).visibility !== 'hidden' && !element.closest('[hidden]');
    })
    .map(element => {
        const box = element.getBoundingClientRect(), style = getComputedStyle(element);
        const name = (element.getAttribute('aria-label') || element.textContent || element.getAttribute('placeholder') || element.name || '').trim().replace(/\s+/g, ' ').slice(0, 32);
        // A select or date field: its appearance, and whether a caret is drawn on it.
        const look = element.matches('select, input[type=date], input[type=datetime-local]') ? `${style.appearance} ${style.backgroundImage === 'none' ? 'bare' : 'caret'}` : null;
        return {name: `${element.tagName.toLowerCase()} "${name}"`, width: box.width, height: box.height, look};
    }), scope);

const under44 = list => list.filter(control => control.width < 44 || control.height < 44).map(control => `${control.name} ${control.width.toFixed(1)}x${control.height.toFixed(1)}`);
const click = (page, selector) => page.locator(selector).first().evaluate(node => node.click());

// Opens the calendar, an event, a task's picker and the event's editor in turn, and returns the controls of each.
async function walk(page) {
    const found = {page: await controls(page, '[data-mothership-home]')};
    // The phone sheet exists up to 640 px wide; wider, the glance scrolls to the cards on the page.
    const sheet = page.locator('[data-glance-open]').first();
    if (page.viewportSize().width <= 640 && await sheet.isVisible()) {
        await sheet.click();
        await page.waitForFunction(() => document.querySelector('[data-side-sheet]').open);
        await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
        found.sheet = await controls(page, '[data-side-sheet]');
        await page.evaluate(() => document.querySelector('[data-side-sheet]').close());
    }
    await click(page, '#hm-cargo [data-calendar-open]');
    await page.locator('[data-calendar-dialog] [data-cal-open="7"]').first().waitFor();
    found.calendar = await controls(page, '[data-calendar-dialog]');
    await click(page, '[data-calendar-dialog] [data-cal-open="7"]');
    await page.locator('[data-cal-dialog] [data-cal-new]').waitFor();
    found.event = await controls(page, '[data-cal-dialog]');
    await click(page, '[data-cal-dialog] [data-cal-attach="1"]');
    await page.locator('[data-cal-dialog] [data-cal-pick-folder]').first().waitFor();
    found.picker = await controls(page, '[data-cal-picker]');
    await click(page, '[data-cal-dialog] [data-cal-edit]');
    await page.locator('[data-cal-dialog] [name="all_day"]').waitFor();
    found.editor = await controls(page, '[data-cal-dialog]');

    return found;
}

browserTest('on a touch screen every control on Home, in its phone sheet and in the calendar is a 44 px press area', async () => {
    const browser = await launchBrowser({playwrightPath});
    try {
        for (const [pose, size] of Object.entries(POSES)) {
            const {page, context, errors} = await mount(browser, size);
            const found = await walk(page);
            assert.ok(found.calendar.length >= 9 && found.event.length >= 20 && found.picker.length >= 5 && found.editor.length >= 9, `${pose}: each part shows its controls`);
            assert.ok(found.page.some(control => /Try again/.test(control.name)) || found.sheet?.some(control => /Try again/.test(control.name)), `${pose}: Signal's Try again is measured`);
            for (const [part, list] of Object.entries(found)) assert.deepEqual(under44(list), [], `${pose} ${part}`);
            // Safari draws a native select or date field at its own height whatever min-height says (Chromium honours
            // it), so the calendar's drop the native look under touch, with a caret in its place.
            const looks = ['calendar', 'event', 'picker', 'editor'].flatMap(part => found[part].filter(control => control.look).map(control => `${part} ${control.name}: ${control.look}`));
            assert.ok(looks.length >= 7, `${pose}: the pickers, Assign to, Due date, Kind, Starts and Ends are measured: ${looks.join(' | ')}`);
            assert.deepEqual(looks.filter(look => !look.endsWith(': none caret')), [], `${pose}: selects and date fields`);
            assert.deepEqual(errors, []);
            await context.close();
        }
    } finally {
        await browser.close();
    }
});

browserTest('on a tabletop fold by touch, Trending\'s Retry, Refresh, day and Show all are 44 px', async () => {
    const browser = await launchBrowser({playwrightPath});
    try {
        for (const trending of ['fail', 'success']) {
            const {page, context, errors} = await mount(browser, {...POSES.tabletop, trending});
            await page.locator(trending === 'fail' ? '[data-signal] [data-trending-refresh]' : '[data-signal] [data-trend-all]').waitFor();
            const list = await controls(page, '[data-signal]');
            const names = list.map(control => control.name).join(' | ');
            for (const part of trending === 'fail' ? ['Retry'] : ['Refresh', 'select', 'Show all 6']) assert.ok(names.includes(part), `Trending ${trending} shows ${part}: ${names}`);
            assert.deepEqual(under44(list), [], `Trending ${trending}`);
            assert.deepEqual(errors, []);
            await context.close();
        }
    } finally {
        await browser.close();
    }
});

browserTest('a task\'s done label is its press area: a touch beside the box, even over the title, ticks it, and the row keeps its mouse layout', async () => {
    const browser = await launchBrowser({playwrightPath});
    try {
        const {page, context, errors} = await mount(browser, POSES.outer);
        await click(page, '#hm-cargo [data-calendar-open]');
        await click(page, '[data-calendar-dialog] [data-cal-open="7"]');
        const label = page.locator('[data-task="1"] .agh-cal-done').first();
        await label.waitFor();
        // The bigger press areas leave the picker beside the title and delete beside the task's buttons.
        const tops = await page.locator('[data-task="1"] .agh-cal-task-line').evaluate(line => Object.fromEntries(Object.entries({done: '.agh-cal-done', picker: '[data-cal-assign]', attach: '[data-cal-attach]', remove: '[data-cal-remove]'}).map(([name, selector]) => [name, Math.round(line.querySelector(selector).getBoundingClientRect().top)])));
        assert.equal(tops.picker, tops.done, `the picker shares the title's line: ${JSON.stringify(tops)}`);
        assert.equal(tops.remove, tops.attach, `delete shares the buttons' line: ${JSON.stringify(tops)}`);
        const box = await label.boundingBox(), mark = await label.locator('input').boundingBox();
        // A point inside the label but clear of the box itself.
        const x = box.x + 2, y = box.y + 2;
        assert.ok(x < mark.x || y < mark.y, `the point ${x},${y} is beside the box at ${mark.x},${mark.y}`);
        // The label's right edge lies over the title, which follows it in the row: a touch there still takes the label.
        const title = await page.locator('[data-task="1"] .agh-cal-task-title').boundingBox();
        const edge = {x: box.x + box.width - 2, y: box.y + box.height / 2};
        assert.ok(edge.x > title.x && edge.y > title.y && edge.y < title.y + title.height, `the title lies under the label's right edge: ${JSON.stringify({edge, title})}`);
        assert.ok(await page.evaluate(([px, py]) => Boolean(document.elementFromPoint(px, py)?.closest('.agh-cal-done')), [edge.x, edge.y]), 'a touch at the label\'s right edge takes the label');
        await page.touchscreen.tap(x, y);
        await page.waitForFunction(() => window.__requests.some(entry => entry.startsWith('PATCH ') && entry.endsWith('/calendar/tasks/1')), null, {timeout: 5000});
        assert.deepEqual(errors, []);
        await context.close();
    } finally {
        await browser.close();
    }
});

browserTest('with a mouse, Home and its calendar keep their compact sizes', async () => {
    const browser = await launchBrowser({playwrightPath});
    try {
        const {page, context, errors} = await mount(browser, {width: 1280, height: 900, touch: false});
        const height = selector => page.locator(selector).first().evaluate(node => node.getBoundingClientRect().height);
        assert.equal(await height('[data-signal] [data-signal-retry]'), 40);
        await click(page, '#hm-cargo [data-calendar-open]');
        await page.locator('[data-calendar-dialog] [data-cal-today]').waitFor();
        assert.ok(await height('[data-calendar-dialog] [data-cal-today]') < 30, 'Today keeps its small button');
        await click(page, '[data-calendar-dialog] [data-cal-open="7"]');
        await page.locator('[data-task="1"] .agh-cal-done').waitFor();
        const [label, mark] = await page.locator('[data-task="1"] .agh-cal-done').evaluate(node => [node.getBoundingClientRect(), node.firstElementChild.getBoundingClientRect()].map(box => [box.width, box.height]));
        assert.deepEqual(label, mark, 'the done label is only as big as its box');
        const looks = await page.locator('[data-cal-dialog]').evaluate(dialog => [...dialog.querySelectorAll('select, input[type=date]')].map(node => getComputedStyle(node).appearance));
        assert.ok(looks.length >= 4 && looks.every(look => look !== 'none'), `with a mouse the selects and Due date keep their native look: ${looks}`);
        assert.equal(await height('[data-cal-dialog] [data-cal-unlink]'), 18);
        assert.deepEqual(errors, []);
        await context.close();
    } finally {
        await browser.close();
    }
});
