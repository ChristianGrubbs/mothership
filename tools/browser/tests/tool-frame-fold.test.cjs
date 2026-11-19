const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {bladeFixture} = require('../fixtures/blade.cjs');
const {launchBrowser} = require('../launch-browser.cjs');

// The tool frame on a folded screen: public/pwa/device.css keeps its messages and the question before work is lost off
// the fold. The page is the frame view as Laravel renders it, under the committed admin stylesheet and the fold layer.
// The fold is described the way public/pwa/device.js describes it, with a 40 px hinge.
const root = path.resolve(__dirname, '../../..');
const playwrightPath = process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH;
const browserTest = playwrightPath ? test : test.skip;
const BUILD = 'public/themes/admin/default/build';

const builtStyles = () => {
    const manifest = JSON.parse(fs.readFileSync(path.join(root, BUILD, 'manifest.json'), 'utf8'));
    const entry = Object.values(manifest).find(item => typeof item.file === 'string' && item.file.startsWith('assets/app-') && item.file.endsWith('.css'));

    assert.ok(entry, 'the committed admin manifest lists a built assets/app-*.css');

    return fs.readFileSync(path.join(root, BUILD, entry.file), 'utf8');
};

const BOOK = {
    viewport: {width: 951, height: 585},
    fold: 'columns',
    segments: {'a-width': 455, 'b-width': 456, 'trailing-left': 495, 'trailing-width': 456},
};
const LAPTOP = {
    viewport: {width: 669, height: 867},
    fold: 'rows',
    segments: {'b-top': 453, 'b-height': 414},
};
const NO_FOLD = {viewport: {width: 951, height: 585}};
const BANDS = [
    {band: '[data-tool-ask]', sentence: '[data-tool-question]', buttons: ['[data-tool-ask-cancel]', '[data-tool-ask-accept]']},
    {band: '[data-tool-notice]', sentence: '[data-tool-notice-text]', buttons: ['[data-tool-notice-reload]']},
];

// Every sentence and label is the one the frame carries in data-words, so the widths are the real ones.
async function framePage(browser, {viewport, fold, segments = {}, dir = 'ltr'}) {
    const page = await browser.newPage({viewport});

    await page.setContent(`<!doctype html><html dir="${dir}"><head><style>${builtStyles()}</style><style>${fs.readFileSync(path.join(root, 'public/pwa/device.css'), 'utf8')}</style></head><body><div id="app">${bladeFixture('tool-frame.html')}</div></body></html>`);
    await page.evaluate(({fold, segments}) => {
        if (fold) document.documentElement.dataset.fold = fold;
        for (const [name, value] of Object.entries(segments)) document.documentElement.style.setProperty(`--seg-${name}`, `${value}px`);

        const words = JSON.parse(document.querySelector('[data-tool-frame]').dataset.words);
        const tool = document.createElement('iframe');
        tool.dataset.toolWindow = '';
        document.querySelector('[data-tool-stage]').prepend(tool);
        document.querySelector('[data-tool-note-actions]').hidden = false;
        document.querySelector('[data-tool-note-text]').textContent = words.failed;
        document.querySelector('[data-tool-question]').textContent = words['ask-close-work'];
        document.querySelector('[data-tool-ask-accept]').textContent = words['discard-close'];
        document.querySelector('[data-tool-notice-text]').textContent = words.file;
    }, {fold, segments});

    return page;
}

const showBand = (page, band) => page.evaluate(band => {
    for (const item of document.querySelectorAll('[data-tool-ask], [data-tool-notice]')) item.hidden = !item.matches(band);
    document.querySelector('[data-tool-note]').hidden = true;
}, band);

const box = (page, selector) => page.locator(selector).evaluate(node => {
    const range = document.createRange();

    range.selectNodeContents(node);
    const {left, right, top, bottom} = (node.matches('p') ? range : node).getBoundingClientRect();

    return {left, right, top, bottom};
});
const toolVisibility = page => page.locator('[data-tool-window]').evaluate(node => getComputedStyle(node).visibility);

async function withBrowser(fn) {
    const browser = await launchBrowser({playwrightPath});

    try {
        await fn(browser);
    } finally {
        await browser.close();
    }
}

browserTest('book pose: the message and its buttons sit on the trailing segment, and the tool is out of reach', () => withBrowser(async browser => {
    const page = await framePage(browser, BOOK);

    for (const selector of ['[data-tool-note-text]', '[data-tool-note-reload]', '[data-tool-note-back]']) {
        const item = await box(page, selector);

        assert.ok(item.left >= 495 && item.right <= 951, `${selector} ${item.left}-${item.right} must be right of the hinge`);
    }

    assert.equal(await toolVisibility(page), 'hidden');
}));

browserTest('laptop pose: the message and its buttons sit on the lower segment, and the tool is out of reach', () => withBrowser(async browser => {
    const page = await framePage(browser, LAPTOP);

    for (const selector of ['[data-tool-note-text]', '[data-tool-note-reload]', '[data-tool-note-back]']) {
        const item = await box(page, selector);

        assert.ok(item.top >= 453 && item.bottom <= 867, `${selector} ${item.top}-${item.bottom} must be below the hinge`);
    }

    assert.equal(await toolVisibility(page), 'hidden');
}));

browserTest('book pose: the question and the file notice wrap before the hinge and their buttons stay past it', () => withBrowser(async browser => {
    const page = await framePage(browser, BOOK);

    for (const {band, sentence, buttons} of BANDS) {
        await showBand(page, band);

        const words = await box(page, sentence);

        assert.ok(words.right <= 455, `${sentence} ends at ${words.right}, past the start of the hinge`);

        for (const button of buttons) {
            const item = await box(page, button);

            assert.ok(item.left >= 495 && item.right <= 951, `${button} ${item.left}-${item.right} must be right of the hinge`);
        }
    }

    assert.equal(await toolVisibility(page), 'visible', 'the tool shows again once its message has gone');
}));

browserTest('book pose, right to left: the sentence stays right of the hinge and the buttons left of it', () => withBrowser(async browser => {
    const page = await framePage(browser, {...BOOK, dir: 'rtl'});

    for (const {band, sentence, buttons} of BANDS) {
        await showBand(page, band);

        const words = await box(page, sentence);

        assert.ok(words.left >= 495 && words.right <= 951, `${sentence} ${words.left}-${words.right} must be right of the hinge`);

        for (const button of buttons) {
            const item = await box(page, button);

            assert.ok(item.left >= 0 && item.right <= 455, `${button} ${item.left}-${item.right} must be left of the hinge`);
        }
    }
}));

// The bar's set menu as a tool with many saved sets has it: twelve, the open one with a 60-character name, and the item
// held at the menu's foot. The poses are WebKit's sizes, with no hinge, the 40 px hinge and the size Safari reported.
const LONG_SET = 'Harbour at dusk with the tide out and the lamps lit along it';
const showSets = page => page.evaluate(name => {
    const item = (role, label) => {
        const button = document.createElement('button');
        const span = document.createElement('span');

        button.type = 'button';
        button.setAttribute('role', role);
        if (role === 'menuitemradio') button.setAttribute('aria-checked', 'false');
        span.textContent = label;
        button.append(span);

        return button;
    };
    const menu = document.querySelector('[data-tool-sets-menu]');

    document.querySelector('[data-tool-note]').hidden = true;
    document.querySelector('[data-tool-sets]').hidden = false;
    document.querySelector('[data-tool-set-name]').textContent = name;
    menu.replaceChildren(...Array.from({length: 12}, (_, i) => item('menuitemradio', i ? `Palette ${i + 1}` : name)), item('menuitem', 'Manage saved palettes…'));
    menu.hidden = false;
}, LONG_SET);
const TABLETOPS = [
    {pose: 'no hinge', viewport: {width: 669, height: 867}, fold: 'rows', segments: {'a-height': 433, 'b-top': 433, 'b-height': 434}},
    {pose: '40 px hinge', viewport: {width: 669, height: 867}, fold: 'rows', segments: {'a-height': 413, 'b-top': 453, 'b-height': 414}},
    {pose: 'Safari size', viewport: {width: 669, height: 825}, fold: 'rows', segments: {'a-height': 348, 'b-top': 350, 'b-height': 475}},
];
const BOOKS = [
    {pose: 'no hinge', viewport: {width: 951, height: 585}, fold: 'columns', segments: {'a-width': 475, 'b-width': 476, 'trailing-left': 475, 'trailing-width': 476}},
    {pose: '40 px hinge', ...BOOK},
    {pose: 'Safari size', viewport: {width: 951, height: 543}, fold: 'columns', segments: {'a-width': 474, 'b-width': 476, 'trailing-left': 475, 'trailing-width': 476}},
];

browserTest('tabletop pose: the set menu ends 12 px above the fold, with the item at its foot wholly above it', () => withBrowser(async browser => {
    for (const view of TABLETOPS) {
        const page = await framePage(browser, view);

        await showSets(page);
        const fold = view.segments['a-height'];
        const menu = await box(page, '[data-tool-sets-menu]');
        const manage = await box(page, '[data-tool-sets-menu] [role="menuitem"]');

        assert.ok(menu.bottom <= fold - 12 + .5, `${view.pose}: the menu ends at ${menu.bottom}, under 12 px above the fold at ${fold}`);
        assert.ok(manage.top >= menu.top && manage.bottom <= menu.bottom, `${view.pose}: Manage ${manage.top}-${manage.bottom} is held inside the menu`);
        await page.close();
    }
}));

browserTest('book pose: a long set name keeps the tool\'s name and the set button 12 px before the fold', () => withBrowser(async browser => {
    for (const view of BOOKS) {
        const page = await framePage(browser, view);

        await showSets(page);
        const fold = view.segments['a-width'];

        for (const selector of ['.bridge-toolbar-name', '[data-tool-sets-btn]']) {
            const item = await box(page, selector);

            assert.ok(item.right <= fold - 12 + .5, `${view.pose}: ${selector} ends at ${item.right}, under 12 px before the fold at ${fold}`);
        }
        await page.close();
    }
}));

browserTest('without a fold the message fills the stage and the tool is left alone', () => withBrowser(async browser => {
    const page = await framePage(browser, NO_FOLD);
    const note = await box(page, '[data-tool-note]');
    const stage = await box(page, '[data-tool-stage]');

    assert.deepEqual(note, stage);
    assert.equal(await toolVisibility(page), 'visible');
}));
