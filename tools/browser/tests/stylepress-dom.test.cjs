// StylePress inside the tool frame: the page (home/stylepress.blade.php) on the kit, with its own style.css and app.js.
// The page is built from the Blade file with test values in place of its echoes and served from disk, so these checks
// need no app. They cover what the frame port changed: the category select, Styles as a sheet on a narrow screen, the
// one filled action, the error status, the Restyle note, and the styles on the upright half of a tabletop fold.
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const playwrightPath = process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH;
const browserTest = playwrightPath ? test : test.skip;
const resources = path.resolve(__dirname, '../../../packages/Mothership/DAM/src/Resources');
const widgets = path.join(resources, 'widgets');
const origin = 'http://stylepress.test';
const catalogText = fs.readFileSync(path.join(widgets, 'stylepress/assets/stylepress-catalog.json'), 'utf8');
const catalog = JSON.parse(catalogText);
const pixel = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=', 'base64');
const values = {
    csrf: 'token', 'generate-url': '/generate', 'estimate-url': '/estimate', 'balance-url': '/balance', 'enhance-url': '/enhance',
    'reference-url': '/reference', 'folders-url': '/folders', 'assets-url': '/assets', 'thumbnail-base': '/thumb',
    'source-base': '/source', 'library-base': '/library', 'app-name': 'The Mothership',
};

// The Blade page with its echoes filled: the frame bootstrap goes (Home supplies it), routes point at the tool folder.
function page({ connected }) {
    const view = fs.readFileSync(path.join(resources, 'views/home/stylepress.blade.php'), 'utf8');
    const html = view
        .replace(/\{!! app\([^!]*?bootstrap\('stylepress'\) !!\}/, '')
        .replace(/\{\{ route\('admin\.home\.tool', \['widget' => '([^']+)', 'path' => '([^']+)'\]\) \}\}/g, '/tools/$1/$2')
        .replace(/\{!! json_encode\(\$catalog[^!]*!!\}/, () => catalogText.replace(/</g, '\\u003c'))
        .replace(/data-connected="\{\{[^}]*\}\}"/, `data-connected="${connected ? '1' : '0'}"`)
        .replace(/data-([a-z-]+)="\{\{[^}]*\}\}"/g, (match, name) => `data-${name}="${values[name]}"`)
        .replace(/\{\{ config\('documentation\.name'\) \}\}/g, 'The Mothership')
        .replace(/\{\{--[\s\S]*?--\}\}/g, '');
    assert.doesNotMatch(html, /\{\{|\{!!/, 'every Blade echo is filled');
    return html;
}

const launch = () => require(playwrightPath).chromium.launch({ headless: true, channel: process.env.MOTHERSHIP_BROWSER_CHANNEL || 'chrome' });

// Twelve Library images for the picker, one per style picture.
const assets = catalog.presets.slice(0, 12).map((s, i) => ({ id: 500 + i, name: `${s.slug}.jpg`, title: s.title, url: `/thumb/${s.slug}` }));

async function open(browser, { connected = true, width = 1440, height = 900, fold = null, generate = null, touch = false } = {}) {
    const tab = await browser.newPage({ viewport: { width, height }, reducedMotion: 'reduce', hasTouch: touch, isMobile: touch });
    tab.setDefaultTimeout(10000);
    const errors = [];
    tab.on('pageerror', (error) => errors.push(error.message));
    await tab.route(origin + '/**', (route) => {
        const url = new URL(route.request().url());
        const p = url.pathname;
        if (p === '/') return route.fulfill({ contentType: 'text/html; charset=utf-8', body: page({ connected }) });
        if (p.startsWith('/tools/')) {
            const file = path.join(widgets, path.normalize(p.slice(7)));
            const type = { '.css': 'text/css', '.js': 'application/javascript' }[path.extname(file)];
            return file.startsWith(widgets + path.sep) && type && fs.existsSync(file)
                ? route.fulfill({ contentType: `${type}; charset=utf-8`, body: fs.readFileSync(file) }) : route.fulfill({ status: 404, body: '' });
        }
        if (p.startsWith('/thumb/')) return route.fulfill({ contentType: 'image/png', body: pixel });
        if (p === '/balance') return route.fulfill({ json: { ok: true, balance: { credits: 1240 } } });
        if (p === '/estimate') return route.fulfill({ json: { ok: true, estimate: { credits: 12 } } });
        if (p === '/generate') return route.fulfill({ json: generate || { ok: false, message: 'Generation could not start.' } });
        if (p === '/folders') return route.fulfill({ json: { data: [{ id: 1, name: 'Campaigns', has_children: true }, { id: 2, name: 'Product shots', has_children: false }, { id: 3, name: 'Brand kit', has_children: false }] } });
        if (p === '/assets') return route.fulfill({ json: { assets, has_more: false } });
        return route.fulfill({ status: 404, body: '' });
    });
    await tab.goto(origin + '/');
    // Narrow, Styles is out of the page until Change style opens it, so wait for the cards to exist, not to show.
    await tab.locator('.sp-card').first().waitFor({ state: 'attached' });
    // As home-frame.js does: the fold arrives on the root once the page is running.
    if (fold) {
        await tab.evaluate((f) => {
            document.documentElement.style.setProperty('--fold-start', `${f.start}px`);
            document.documentElement.style.setProperty('--fold-end', `${f.end}px`);
            document.documentElement.dataset.fold = f.fold;
        }, fold);
    }
    return { tab, errors };
}

browserTest('the category select lists every category with its count and shows only that category', async () => {
    const browser = await launch();
    try {
        const { tab, errors } = await open(browser);
        const category = catalog.categories[0];
        const count = catalog.presets.filter((s) => s.categoryLabel === category).length;
        const options = await tab.locator('#sp-category option').allTextContents();
        assert.equal(options[0], 'All categories');
        assert.ok(options.includes(`${category} · ${count}`), `${category} is offered with its count`);
        await tab.selectOption('#sp-category', category);
        const metas = await tab.locator('.sp-card .sp-card-meta').allTextContents();
        assert.equal(metas.length, count, 'one card per style in the category');
        assert.ok(metas.every((meta) => meta.startsWith(`${category} · `)), 'no card from another category');
        assert.deepEqual(errors, []);
    } finally {
        await browser.close();
    }
});

browserTest('on a narrow screen Styles opens as a sheet, picking a style closes it, and the pane goes back', async () => {
    const browser = await launch();
    try {
        const { tab, errors } = await open(browser, { width: 390, height: 844 });
        assert.equal(await tab.locator('#sp-styles').isVisible(), false, 'Make is the page');
        await tab.click('#sp-change-style');
        assert.equal(await tab.locator('#sp-browse').evaluate((sheet) => sheet.open), true);
        assert.equal(await tab.locator('#sp-browse-body #sp-styles').isVisible(), true, 'Styles is inside the sheet');

        const card = tab.locator('.sp-card').nth(1);
        const title = await card.locator('.sp-card-title').textContent();
        await card.locator('.sp-pick').click();
        assert.equal(await tab.locator('#sp-browse').evaluate((sheet) => sheet.open), false, 'picking closes the sheet');
        assert.equal(await tab.locator('#sp-title').textContent(), title);
        // The dialog's close event, which puts the pane back, runs a task after `open` turns false: wait for it.
        const home = () => tab.waitForFunction(() => {
            const pane = document.getElementById('sp-styles');
            return pane.parentElement.id === 'stylepress' && pane.nextElementSibling?.id === 'sp-make';
        }, null, { timeout: 2000 }).then(() => true, () => false);
        assert.equal(await home(), true, 'the pane is back before Make');

        await tab.click('#sp-change-style');
        await tab.keyboard.press('Escape');
        assert.equal(await tab.locator('#sp-browse').evaluate((sheet) => sheet.open), false, 'Escape closes the sheet');
        assert.equal(await home(), true, 'the pane is back after Escape too');
        assert.deepEqual(errors, []);
    } finally {
        await browser.close();
    }
});

browserTest('on a phone the sheet shows two large cards, three compact ones or one list row across', async () => {
    const browser = await launch();
    try {
        const { tab, errors } = await open(browser, { width: 375, height: 812 });
        await tab.click('#sp-change-style');
        const columns = {};
        for (const density of ['comfortable', 'compact', 'list']) {
            await tab.click(`.sp-density [data-density="${density}"]`);
            columns[density] = await tab.locator('#sp-cards').evaluate((cards) => getComputedStyle(cards).gridTemplateColumns.split(' ').length);
        }
        assert.deepEqual(columns, { comfortable: 2, compact: 3, list: 1 });
        assert.deepEqual(errors, []);
    } finally {
        await browser.close();
    }
});

browserTest('in a short phone frame the Library picker shows its images above the foot, and every one can be picked', async () => {
    const browser = await launch();
    try {
        // The closed phone held upright, as Home's tool panel frames it.
        const { tab, errors } = await open(browser, { width: 382, height: 524 });
        await tab.click('#sp-browse-assets');
        await tab.locator('.sp-asset').first().waitFor();
        const shown = await tab.evaluate(() => {
            const sheet = document.getElementById('sp-asset-dialog');
            const foot = sheet.querySelector('.tk-sheet-foot').getBoundingClientRect();
            const lib = sheet.querySelector('.sp-lib').getBoundingClientRect();
            const results = document.getElementById('sp-asset-results').getBoundingClientRect();
            return Math.min(results.bottom, lib.bottom, foot.top) - Math.max(results.top, lib.top);
        });
        assert.ok(shown >= 96, `the images get room above the foot before any scrolling (${Math.round(shown)} px)`);
        const spare = await tab.locator('#sp-asset-search').evaluate((input) => {
            const cs = getComputedStyle(input);
            const ctx = document.createElement('canvas').getContext('2d');
            ctx.font = `${cs.fontWeight} ${cs.fontSize} ${cs.fontFamily}`;
            return input.clientWidth - parseFloat(cs.paddingLeft) - parseFloat(cs.paddingRight) - ctx.measureText(input.placeholder).width;
        });
        assert.ok(spare >= 6, `the search's words fit beside Sort (${Math.round(spare)} px spare)`);

        // The last image scrolls clear of the foot, and a press on it lands on the image, not on the foot.
        const reached = await tab.locator('.sp-asset').last().evaluate((asset) => {
            asset.scrollIntoView({ block: 'end' });
            const r = asset.getBoundingClientRect();
            return asset.contains(document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2));
        });
        assert.equal(reached, true, 'the last image is reachable');
        await tab.locator('.sp-asset').last().click();
        assert.match(await tab.locator('#sp-asset-selection').textContent(), /^1 selected/);
        assert.deepEqual(errors, []);
    } finally {
        await browser.close();
    }
});

browserTest('one action is filled: Generate when generation is connected, Copy prompt when it is not', async () => {
    const browser = await launch();
    try {
        const connected = await open(browser);
        assert.equal(await connected.tab.locator('#sp-generate.is-act').count(), 1);
        assert.equal(await connected.tab.locator('#sp-copy.is-act').count(), 0);

        const offline = await open(browser, { connected: false });
        assert.equal(await offline.tab.locator('#sp-generate.is-act').count(), 0);
        assert.equal(await offline.tab.locator('#sp-generate').isDisabled(), true);
        assert.equal(await offline.tab.locator('#sp-copy.is-act').count(), 1);
        assert.match(await offline.tab.locator('#sp-balance').textContent(), /not connected/);
        assert.deepEqual([...connected.errors, ...offline.errors], []);
    } finally {
        await browser.close();
    }
});

browserTest('a failure shows in the status line as an error, and the next plain status clears the mark', async () => {
    const browser = await launch();
    try {
        const { tab, errors } = await open(browser, { generate: { ok: false, message: 'The shared balance is too low.' } });
        await tab.click('#sp-generate');
        const result = tab.locator('#sp-result');
        await result.filter({ hasText: 'The shared balance is too low.' }).waitFor();
        assert.equal(await result.evaluate((box) => box.classList.contains('is-error')), true);
        await tab.click('#sp-estimate');
        await result.filter({ hasText: 'Estimate: 12 credits' }).waitFor();
        assert.equal(await result.evaluate((box) => box.classList.contains('is-error')), false);
        assert.deepEqual(errors, []);
    } finally {
        await browser.close();
    }
});

browserTest('Restyle without a source image says what it needs and holds Generate back', async () => {
    const browser = await launch();
    try {
        const { tab, errors } = await open(browser);
        assert.equal(await tab.locator('#sp-mode-note').isVisible(), false);
        await tab.click('.sp-mode [data-mode="restyle"]');
        assert.equal(await tab.locator('.sp-mode [data-mode="restyle"]').getAttribute('aria-pressed'), 'true');
        assert.equal(await tab.locator('#sp-mode-note').isVisible(), true);
        assert.equal(await tab.locator('#sp-generate').isDisabled(), true);
        await tab.click('.sp-mode [data-mode="create"]');
        assert.equal(await tab.locator('#sp-mode-note').isVisible(), false);
        assert.equal(await tab.locator('#sp-generate').isDisabled(), false);
        assert.deepEqual(errors, []);
    } finally {
        await browser.close();
    }
});

browserTest('folded like a laptop, the filters take one row and the styles start on the upright half', async () => {
    const browser = await launch();
    try {
        const { tab, errors } = await open(browser, { width: 669, height: 867, fold: { fold: 'rows', start: 349, end: 389 } });
        const top = (selector) => tab.locator(selector).first().evaluate((el) => Math.round(el.getBoundingClientRect().top));
        assert.equal(await top('#sp-category'), await top('#sp-search'), 'search and the selects share a row');
        assert.ok(await top('.sp-card') < 349 - 100, 'at least 100 px of styles show above the fold');
        assert.ok(await top('#sp-make') >= 389, 'Make starts below the fold');
        assert.deepEqual(errors, []);
    } finally {
        await browser.close();
    }
});

browserTest('on a tabletop fold by touch, each filter shows its whole value and every tab is a 44 px press', async () => {
    const browser = await launch();
    try {
        const { tab, errors } = await open(browser, { width: 669, height: 867, fold: { fold: 'rows', start: 349, end: 389 }, touch: true });
        // Each value must fit the field's text box with 6 px to spare, since a phone's fonts set a little wider.
        const cut = await tab.evaluate(() => {
            const ctx = document.createElement('canvas').getContext('2d');
            const room = (el) => {
                const cs = getComputedStyle(el);
                ctx.font = `${cs.fontWeight} ${cs.fontSize} ${cs.fontFamily}`;
                return { cs, width: el.clientWidth - parseFloat(cs.paddingLeft) - parseFloat(cs.paddingRight) - 6 };
            };
            const out = [];
            for (const select of document.querySelectorAll('.sp-find select')) {
                const { width } = room(select);
                const texts = select.id === 'sp-category' ? [select.options[0].text] : [...select.options].map((o) => o.text);
                texts.filter((t) => ctx.measureText(t).width > width).forEach((t) => out.push(`${select.id}: ${t}`));
            }
            const search = document.getElementById('sp-search');
            const { width } = room(search);
            if (ctx.measureText(search.placeholder).width > width) out.push(`sp-search: ${search.placeholder}`);
            return out;
        });
        assert.deepEqual(cut, [], 'no filter value is cut');
        const narrow = await tab.$$eval('.sp-tabs .tk-seg-opt', (opts) => opts.map((o) => [o.textContent.trim(), o.getBoundingClientRect().width]).filter(([, w]) => w < 44));
        assert.deepEqual(narrow, [], 'every tab is at least 44 px wide');
        assert.deepEqual(errors, []);
    } finally {
        await browser.close();
    }
});
