'use strict';

const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {gunzipSync} = require('node:zlib');
const {createHash} = require('node:crypto');
const {launchBrowser} = require('../launch-browser.cjs');

const root = path.resolve(__dirname, '../../..');
const corePath = path.join(root, 'public/themes/admin/default/build/tinymce/tinymce.min.js');
const playwrightPath = process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH;
const pixel = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

// Expose the actual bundled factory instance, without changing sanitizer logic.
// This is an instrumented library regression, not an editor exploit claim against this app.
function exposeEmbeddedPurify(source, version) {
    const assignment = /([\w$]+)\.version="(3\.\d+\.\d+)",\1\.removed=\[\]/g;
    const matches = [...source.matchAll(assignment)];
    assert.equal(matches.length, 1, 'exactly one embedded DOMPurify assignment');
    assert.equal(matches[0][2], version);
    return source.replace(assignment, (match, name) => `${match},globalThis.__sccEmbeddedPurify=${name}`);
}

async function withCore({source, prepare = async () => {}, run}) {
    assert.ok(playwrightPath, 'MOTHERSHIP_PLAYWRIGHT_MODULE_PATH is required; never silently skip');
    const browser = await launchBrowser({playwrightPath});
    try {
        const page = await browser.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await prepare(page);
        await page.addScriptTag({content: source});
        await run(page);
        assert.deepEqual(errors, [], 'no browser runtime errors');
    } finally {
        await browser.close();
    }
}

async function removalProbe(page) {
    return page.evaluate(async pixel => {
        window.__sccMarker = 0;
        const purify = window.__sccEmbeddedPurify;
        purify.removeAllHooks();
        const root = document.createElement('div');
        root.innerHTML = `<footer><img src="${pixel}" onload="window.__sccMarker++"></footer><div>safe sibling</div>`;
        const image = root.querySelector('img');
        let hookCalls = 0;
        purify.addHook('uponSanitizeElement', node => {
            if (node.tagName === 'FOOTER') {
                hookCalls++;
                node.remove();
            }
        });
        const sanitized = purify.sanitize(root, {IN_PLACE: true, ALLOWED_TAGS: ['div', '#text', 'footer']});
        document.body.append(root);
        // A positive control proves resource events execute in this document.
        await new Promise((resolve, reject) => {
            const control = new Image();
            const timeout = setTimeout(() => reject(new Error('positive image control timed out')), 5000);
            control.onload = () => { clearTimeout(timeout); resolve(); };
            control.onerror = () => { clearTimeout(timeout); reject(new Error('positive image control failed')); };
            control.src = pixel;
            document.body.append(control);
        });
        await new Promise(resolve => setTimeout(resolve, 100));
        return {version: purify.version, marker: window.__sccMarker, hookCalls,
            sameRoot: sanitized === root, html: root.innerHTML,
            handler: image.getAttribute('onload'), disconnected: !image.isConnected};
    }, pixel);
}

test('embedded patched DOMPurify neutralizes a removed wrapper and retains its safe sibling', async () => {
    const source = exposeEmbeddedPurify(fs.readFileSync(corePath, 'utf8'), '3.4.17');
    await withCore({source, run: async page => {
        const result = await removalProbe(page);
        assert.deepEqual(result, {version: '3.4.17', marker: 0, hookCalls: 1,
            sameRoot: true, html: '<div>safe sibling</div>', handler: null, disconnected: true});
    }});
});

test('unmodified shipped TinyMCE parser drops a bogus wrapper without executing its marker', async () => {
    await withCore({source: fs.readFileSync(corePath, 'utf8'), run: async page => {
        const result = await page.evaluate(async pixel => {
            window.__sccMarker = 0;
            const schema = tinymce.html.Schema();
            const parser = tinymce.html.DomParser({sanitize: true}, schema);
            const parsed = parser.parse(`<div data-mce-bogus="all"><img src="${pixel}" onload="window.__sccMarker++"></div><p>safe sibling</p>`);
            const html = tinymce.html.Serializer({}, schema).serialize(parsed);
            document.body.innerHTML = html;
            await new Promise(resolve => setTimeout(resolve, 100));
            return {html, marker: window.__sccMarker};
        }, pixel);
        assert.deepEqual(result, {html: '<p>safe sibling</p>', marker: 0});
    }});
});

// Pinned stock code is a test-only gzip fixture, never installed or published.
test('stock embedded DOMPurify 3.2.6 reproduces the detached-subtree library failure', async () => {
        const stock = gunzipSync(fs.readFileSync(path.join(root, 'packages/Mothership/Admin/tests/Fixtures/tinymce-7.9.3-stock.min.js.gz')));
        assert.equal(createHash('sha256').update(stock).digest('hex'), 'ca7a4ae9354b117b3133d5b449b4f807ac1484e66b5019c1381e1990ebb1ecd3');
        const source = exposeEmbeddedPurify(stock.toString('utf8'), '3.2.6');
        await withCore({source, run: async page => {
            const result = await removalProbe(page);
            assert.equal(result.marker, 1, 'the harmless stock marker must execute');
            assert.equal(result.html, '<div>safe sibling</div>');
            assert.equal(result.disconnected, true);
            assert.equal(result.hookCalls, 1);
            assert.equal(result.handler, 'window.__sccMarker++');
        }});
});

test('shipped parser sanitizes MathML, SVG and mixed content without exceptions', async () => {
    await withCore({source: fs.readFileSync(corePath, 'utf8'), run: async page => {
        const results = await page.evaluate(async pixel => {
            window.__sccMarker = 0;
            const math = `<math><mi>x</mi><annotation encoding="text/html"><img src="${pixel}" onload="window.__sccMarker++"></annotation></math>`;
            const svg = '<svg onload="window.__sccMarker++"><circle cx="5" cy="5" r="2"></circle></svg>';
            const results = [math, svg, math + svg].map(input => {
                const schema = tinymce.html.Schema(input.includes('<math>') ? {custom_elements: 'math'} : {});
                const parser = tinymce.html.DomParser({sanitize: true}, schema);
                const parsed = parser.parse(input + '<p>safe sibling</p>');
                const html = tinymce.html.Serializer({}, schema).serialize(parsed);
                const target = document.createElement('div');
                target.innerHTML = html;
                document.body.append(target);
                return html;
            });
            await new Promise(resolve => setTimeout(resolve, 100));
            return {html: results, marker: window.__sccMarker};
        }, pixel);
        assert.equal(results.marker, 0);
        for (const html of results.html) {
            assert.ok(html.includes('<p>safe sibling</p>'));
            assert.doesNotMatch(html, /onload|annotation|__sccMarker/);
        }
        assert.match(results.html[0], /<math><mi>x<\/mi><\/math>/);
        // Admin's default schema drops inline SVG altogether.
        assert.equal(results.html[1], '<p>safe sibling</p>');
        assert.match(results.html[2], /<math>/);
        assert.doesNotMatch(results.html[2], /<svg/);
    }});
});

test('Admin plugins initialize from public assets and round-trip sanitized editor content', async () => {
    const blade = fs.readFileSync(path.join(root, 'packages/Mothership/Admin/src/Resources/views/components/tinymce/index.blade.php'), 'utf8');
    const pluginMatch = blade.match(/plugins: '([^']+)'/);
    assert.ok(pluginMatch, 'derive the actual Admin plugin list');
    const requested = new Set();
    await withCore({source: fs.readFileSync(corePath, 'utf8'), prepare: async page => {
        await page.route('**/*', route => {
            const url = new URL(route.request().url());
            if (url.pathname === '/') {
                return route.fulfill({contentType: 'text/html', body: '<!doctype html><textarea id="editor"></textarea>'});
            }
            const relative = url.pathname.replace(/^\/tinymce\//, '');
            const file = path.resolve(path.dirname(corePath), relative);
            assert.ok(file.startsWith(path.dirname(corePath) + path.sep), 'only serve TinyMCE assets');
            assert.ok(fs.existsSync(file), `requested asset exists: ${relative}`);
            requested.add(relative);
            return route.fulfill({body: fs.readFileSync(file), contentType: file.endsWith('.css') ? 'text/css' : 'text/javascript'});
        });
        await page.goto('http://tinymce.test/');
    }, run: async page => {
        const result = await page.evaluate(async plugins => {
            const editor = await Promise.race([
                tinymce.init({selector: '#editor', license_key: 'gpl', base_url: '/tinymce', suffix: '.min', plugins}).then(editors => editors[0]),
                new Promise((_, reject) => setTimeout(() => reject(new Error('editor initialization timed out')), 10000)),
            ]);
            window.__sccMarker = 0;
            editor.setContent('<p>safe <strong>sibling</strong></p><svg onload="window.__sccMarker++"><circle r="2"></circle></svg>');
            const html = editor.getContent();
            editor.setContent(html);
            await new Promise(resolve => setTimeout(resolve, 100));
            const result = {html: editor.getContent(), marker: window.__sccMarker, plugins: Object.keys(editor.plugins)};
            editor.remove();
            return result;
        }, pluginMatch[1]);
        assert.match(result.html, /<p>safe <strong>sibling<\/strong><\/p>/);
        assert.doesNotMatch(result.html, /<svg/);
        assert.doesNotMatch(result.html, /onload|__sccMarker/);
        assert.equal(result.marker, 0);
        for (const plugin of pluginMatch[1].split(/\s+/)) {
            assert.ok(result.plugins.includes(plugin), `initialized ${plugin}`);
            assert.ok(requested.has(`plugins/${plugin}/plugin.min.js`));
        }
        for (const asset of ['themes/silver/theme.min.js', 'models/dom/model.min.js', 'icons/default/icons.min.js', 'skins/ui/oxide/skin.min.css']) {
            assert.ok(requested.has(asset), `loaded ${asset}`);
        }
    }});
});
