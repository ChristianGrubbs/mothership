import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// feedback.js is inlined into the admin layout's <head>; run it here against a plain window. The server re-redacts
// (FeedbackRedactor, covered by FeedbackReportTest); these cases keep the preview the person sees in step with it.
const source = readFileSync(new URL('../../src/Resources/assets/js/feedback/feedback.js', import.meta.url), 'utf8');

const fakeWindow = () => {
    const listeners = {};
    const logged = [];

    return {
        listeners,
        logged,
        innerWidth: 1440,
        innerHeight: 900,
        devicePixelRatio: 2,
        navigator: { userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', language: 'en-US' },
        console: { error: (...args) => logged.push(args) },
        addEventListener: (type, fn) => { listeners[type] = fn; },
    };
};

const load = () => {
    const win = fakeWindow();
    new Function('window', source)(win);

    return win;
};

test('masks credentials, sensitive pairs, quoted secrets whole, long tokens and JWTs', () => {
    const F = load().MothershipFeedback;
    assert.equal(F.redactText('Authorization: Bearer abc.def'), 'Authorization: [redacted] [redacted]');
    assert.equal(F.redactText('{"password":"two words","token":"abc"}'), '{"password":"[redacted]","token":"[redacted]"}');
    assert.equal(F.redactText("secret='s p a c e' tail"), "secret='[redacted]' tail");
    assert.equal(F.redactText('share abcdefghijklmnopqrstuvwxyz0123456789 done'), 'share [redacted] done');
    assert.equal(F.redactText('jwt eyJhbGciOi.eyJzdWIiOi.sig here'), 'jwt [redacted] here');
    assert.equal(F.redactText('word '.repeat(200)).length, 500);
});

test('applies the query rules to absolute and relative URLs and drops credentials and fragments', () => {
    const F = load().MothershipFeedback;
    assert.equal(F.redactText('GET /callback?code=shortcode&state=shortstate&page=2 failed'), 'GET /callback?code=[redacted]&state=[redacted]&page=2 failed');
    assert.equal(F.redactText('fetch https://alice:shortpass@example.test/path?x=1#frag failed'), 'fetch https://[redacted]@example.test/path?x=1 failed');
    assert.equal(F.redactText('GET /api?%74oken=enc&q=ok'), 'GET /api?%74oken=[redacted]&q=ok');
    assert.equal(F.redactText('GET ./callback?code=shortcode#frag failed'), 'GET ./callback?code=[redacted] failed');
    assert.equal(F.redactText('callback?code=a&state=b'), 'callback?code=[redacted]&state=[redacted]');
    assert.equal(F.redactText('?code=shortcode'), '?code=[redacted]');
    assert.equal(F.redactText('callback#code=shortsecret'), 'callback');
    assert.equal(F.redactText('see issue #12'), 'see issue #12');
    assert.equal(F.redactText('fetch //alice:shortpass@example.test/p failed'), 'fetch //[redacted]@example.test/p failed');
    assert.equal(F.redactUrl('https://mothership.test/admin/dam/share/x?token=abc&scope=all#frag'), '/admin/dam/share/x?token=[redacted]&scope=all');
    assert.equal(F.redactUrl('/admin/dam?search=cats&api_key=1'), '/admin/dam?search=cats&api_key=[redacted]');
});

test('masks every pair of a cookie header and encoded secrets under ordinary query keys', () => {
    const F = load().MothershipFeedback;
    const opaque = 'a1'.repeat(20);
    assert.equal(F.redactText('Cookie: theme=light; preference=privatevalue'), 'Cookie: [redacted]');
    assert.equal(F.redactText('set-cookie: a=1; b=2\nnext line'), 'set-cookie: [redacted]\nnext line');
    assert.equal(F.redactText('GET /admin?q=token%3Dshort-secret&page=2 failed'), 'GET /admin?q=[redacted]&page=2 failed');
    assert.equal(F.redactText('GET /admin?q=cats%20and%20dogs&page=2'), 'GET /admin?q=cats%20and%20dogs&page=2');
    assert.equal(F.redactUrl(`/admin?q=${opaque}&page=2`), '/admin?q=[redacted]&page=2');
    assert.equal(F.redactUrl('/admin?q=token%3Dshort-secret'), '/admin?q=[redacted]');
    assert.equal(F.redactUrl('/admin?search=cats%20and%20dogs'), '/admin?search=cats%20and%20dogs');
});

test('projects console arguments to strings and error names only, never object fields', () => {
    const F = load().MothershipFeedback;
    assert.equal(F.projectArg('plain'), 'plain');
    assert.equal(F.projectArg(new TypeError('boom')), 'TypeError: boom');
    assert.equal(F.projectArg({ config: { headers: { Authorization: 'Bearer x' } } }), '[object]');
    assert.equal(F.projectArg(null), 'null');
});

test('buffers the last 20 console errors and page errors, redacted, and still logs them', () => {
    const win = load();
    for (let i = 0; i < 25; i += 1) win.console.error('failed', i, { token: 'never' });
    win.listeners.error({ error: new Error('token=abc'), filename: 'https://h.test/build/app.js?sig=zz', lineno: 12 });
    win.listeners.unhandledrejection({ reason: { name: 'AxiosError', message: 'Request failed' } });

    const lines = win.__damFeedbackConsole.lines();
    assert.equal(lines.length, 20);
    assert.equal(win.logged.length, 25);
    assert.deepEqual(Object.keys(lines.at(-2)).sort(), ['kind', 'line', 'message', 'source', 'ts']);
    assert.equal(lines.at(-2).message, 'Error: token=[redacted]');
    assert.equal(lines.at(-2).source, '/build/app.js?sig=[redacted]');
    assert.equal(lines.at(-1).kind, 'unhandledrejection');
    assert.equal(lines.at(-3).message, 'failed 24 [object]');
    assert.ok(!JSON.stringify(lines).includes('never'));
});

test('collects the visible context and installs the buffer once', () => {
    const win = load();
    const before = win.__damFeedbackConsole;
    win.MothershipFeedback.installConsoleBuffer(win);
    assert.equal(win.__damFeedbackConsole, before);

    const context = win.MothershipFeedback.collectContext(win, { includeConsole: false });
    assert.equal(context.browser, 'Chrome 141');
    assert.equal(context.os, 'macOS');
    assert.equal(context.viewport, '1440x900');
    assert.equal(context.dpr, '2');
    assert.deepEqual(context.console, []);
    assert.match(context.client_time, /^\d{4}-\d{2}-\d{2}T/);
});
