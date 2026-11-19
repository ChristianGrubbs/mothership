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

async function open(browser, { connected = true, width = 1440, height = 900, fold = null, generate = null } = {}) {
    const tab = await browser.newPage({ viewport: { width, height }, reducedMotion: 'reduce' });
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
        const home = () => tab.evaluate(() => {
            const pane = document.getElementById('sp-styles');
            return pane.parentElement.id === 'stylepress' && pane.nextElementSibling?.id === 'sp-make';
        });
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
