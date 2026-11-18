const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {launchBrowser} = require('../launch-browser.cjs');

const root = path.resolve(__dirname, '../../..');
const {buildSync} = require(path.join(root, 'packages/Mothership/DAM/node_modules/esbuild'));
const browserTest = process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH ? test : test.skip;

browserTest('browser-generated rejection frames identify only the test bundle or current document', async () => {
    const browser = await launchBrowser({playwrightPath: process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH});
    const page = await browser.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    page.on('console', message => { if (message.type() === 'warning' || message.type() === 'error') errors.push(message.text()); });
    try {
        await page.route('http://mothership.test/**', route => route.fulfill({contentType: 'text/html', body: '<!doctype html><title>Diagnostics fixture</title>'}));
        await page.goto('http://mothership.test/admin/dam');
        const bundle = buildSync({entryPoints: [path.join(root, 'packages/Mothership/DAM/src/Resources/assets/js/analytics/error-details.js')],
            bundle: true, write: false, platform: 'browser', format: 'iife', globalName: 'ErrorDiagnostics'}).outputFiles[0].text;
        await page.addScriptTag({content: bundle});
        await page.evaluate(() => {
            window.probeResults = [];
            window.probeFrames = [];
            addEventListener('unhandledrejection', event => {
                event.preventDefault();
                probeFrames.push(event.reason.stack.split('\n').some(line => /^\s+at\s/.test(line)));
                probeResults.push(ErrorDiagnostics.errorDetails({event, kind: 'rejection', origin: location.origin, pathname: location.pathname}));
            });
        });
        // Real Error objects and real browser unhandledrejection events. sourceURL
        // fixes only attribution; the browser generates the stack and coordinates.
        await page.addScriptTag({content: 'function diagnosticsBundleProbe() { Promise.reject(new TypeError("private-bundle-message")); } diagnosticsBundleProbe();\n//# sourceURL=http://mothership.test/themes/dam/build/assets/diagnostics-probe-Abc123_d.js?private=query'});
        await page.waitForFunction(() => probeResults.length === 1);
        await page.addScriptTag({content: 'function diagnosticsDocumentProbe() { Promise.reject(new TypeError("private-document-message\\n    at http://mothership.test/admin/dam:12:34")); } diagnosticsDocumentProbe();\n//# sourceURL=http://mothership.test/admin/dam?private=query'});
        await page.waitForFunction(() => probeResults.length === 2);
        const result = await page.evaluate(() => ({results: probeResults, browserGeneratedFrameSyntax: probeFrames}));
        assert.equal(result.results[0].source_kind, 'bundle');
        assert.equal(result.results[0].bundle, 'diagnostics-probe-Abc123_d.js');
        assert.equal(result.results[0].error_type, 'TypeError');
        assert.ok(result.results[0].line > 0 && result.results[0].column > 0);
        assert.equal(result.results[1].source_kind, 'document');
        assert.equal(result.results[1].error_type, 'TypeError');
        assert.equal(result.results[1].line, 1, 'the embedded message frame at line12 must not win');
        assert.equal(result.results[1].bundle, undefined);
        assert.deepEqual(result.browserGeneratedFrameSyntax, [true, true]);
        assert.ok(!JSON.stringify(result).includes('private'));
        assert.deepEqual(errors, []);
        if (process.env.MOTHERSHIP_ERROR_PROBE_EVIDENCE) {
            fs.writeFileSync(process.env.MOTHERSHIP_ERROR_PROBE_EVIDENCE, JSON.stringify({browser: browser.version(), ...result}, null, 2));
        }
    } finally { await browser.close(); }
});

browserTest('the committed collector exports bounded real-browser rejection properties through local ingest', async () => {
    const origin = 'http://127.0.0.1';
    const browser = await launchBrowser({playwrightPath: process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH});
    const page = await browser.newPage();
    const batches = [];
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    page.on('console', message => { if (message.type() === 'warning' || message.type() === 'error') errors.push(message.text()); });
    try {
        const build = path.join(root, 'public/themes/dam/build');
        const manifest = JSON.parse(fs.readFileSync(path.join(build, 'manifest.json')));
        const entry = manifest['src/Resources/assets/js/analytics/collector.js'];
        const config = {t: 'fixture', e: '/test-ingest', n: ['client.error.raised'], b: 1, q: 25, r: 100, f: 10, v: 0, o: 'admin.dam.index', u: 'library'};
        await page.route(origin + '/**', async route => {
            const pathname = new URL(route.request().url()).pathname;
            if (pathname === '/test-ingest') {
                batches.push(route.request().postDataJSON());
                await route.fulfill({status: 204});
            } else if (pathname.startsWith('/themes/dam/build/')) {
                const file = path.resolve(root, 'public', '.' + pathname);
                assert.ok(file.startsWith(build + path.sep));
                await route.fulfill({path: file, contentType: 'application/javascript'});
            } else {
                await route.fulfill({contentType: 'text/html', body: '<!doctype html><title>Collector fixture</title><script type="application/json" id="dam-t">' + JSON.stringify(config) + '</script>'});
            }
        });
        await page.addInitScript(() => addEventListener('unhandledrejection', event => event.preventDefault()));
        await page.goto(origin + '/admin/dam');
        await page.addScriptTag({type: 'module', url: origin + '/themes/dam/build/' + entry.file});
        await page.waitForFunction(() => typeof damTrack === 'function');
        const first = page.waitForResponse(response => response.url() === origin + '/test-ingest');
        await page.addScriptTag({content: 'function realCollectorProbe() { Promise.reject(new TypeError("private-message")); } realCollectorProbe();\n//# sourceURL=' + origin + '/themes/dam/build/assets/diagnostics-collector-Abc123_d.js?private=query'});
        await first;
        const second = page.waitForResponse(response => response.url() === origin + '/test-ingest');
        await page.addScriptTag({content: 'function staleNameProbe() { const e = new Error("private-message\\n    at ' + origin + '/admin/dam:12:34"); const original = e.stack; e.name = "AbortError"; e.stack = original; Promise.reject(e); } staleNameProbe();\n//# sourceURL=' + origin + '/themes/dam/build/assets/diagnostics-collector-Abc123_d.js'});
        await second;
        const third = page.waitForResponse(response => response.url() === origin + '/test-ingest');
        await page.addScriptTag({content: 'function namedHeaderProbe() { Promise.reject(new TypeError("x@' + origin + '/admin/dam:12:34")); } namedHeaderProbe();\n//# sourceURL=' + origin + '/themes/dam/build/assets/diagnostics-collector-Abc123_d.js'});
        await third;
        const fourth = page.waitForResponse(response => response.url() === origin + '/test-ingest');
        await page.addScriptTag({content: 'function emptyNameProbe() { const e = new Error("    at ' + origin + '/admin/dam:12:34"); e.name = ""; Promise.reject(e); } emptyNameProbe();\n//# sourceURL=' + origin + '/themes/dam/build/assets/diagnostics-collector-Abc123_d.js'});
        await fourth;
        const events = batches.flatMap(batch => batch.e);
        assert.equal(events.length, 4);
        assert.equal(events[0].n, 'client.error.raised');
        assert.equal(events[0].p.kind, 'rejection');
        assert.equal(events[0].p.route, 'admin.dam.index');
        assert.equal(events[0].p.source_kind, 'bundle');
        assert.equal(events[0].p.bundle, 'diagnostics-collector-Abc123_d.js');
        assert.equal(events[0].p.error_type, 'TypeError');
        assert.ok(events[0].p.line > 0 && events[0].p.column > 0);
        assert.deepEqual(events[1].p, {kind: 'rejection', route: 'admin.dam.index', error_type: 'AbortError'});
        assert.equal(events[2].p.source_kind, 'bundle');
        assert.equal(events[2].p.bundle, 'diagnostics-collector-Abc123_d.js');
        assert.equal(events[2].p.line, 1, 'the named message header must not supply document line12');
        assert.deepEqual(events[3].p, {kind: 'rejection', route: 'admin.dam.index'});
        assert.ok(!JSON.stringify(events).includes('private'));
        assert.deepEqual(errors, []);
        if (process.env.MOTHERSHIP_COLLECTOR_PROBE_EVIDENCE) {
            fs.writeFileSync(process.env.MOTHERSHIP_COLLECTOR_PROBE_EVIDENCE, JSON.stringify({browser: browser.version(), collector: entry.file, exported: events.map(event => ({name: event.n, props: event.p}))}, null, 2));
        }
    } finally { await browser.close(); }
});
