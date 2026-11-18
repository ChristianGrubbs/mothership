const test = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const path = require('node:path');
const {buildSync} = require('esbuild');

const bundle = buildSync({
    entryPoints: [path.resolve(__dirname, '../../src/Resources/assets/js/analytics/collector.js')],
    bundle: true, write: false, format: 'iife', platform: 'browser',
}).outputFiles[0].text;

// Exercise the shipped collector and real pinned web-vitals algorithms. Only browser
// timing inputs/transport are controlled; no replacement metric implementation.
async function browser({sample = 0, configured = true, names = [], width = 1280, coarse = false, screen = {width: 1920, height: 1080}, navigationType = 'navigate', extra = {}} = {}) {
    const listeners = new Map();
    const documentListeners = new Map();
    const observers = [];
    const sent = [];
    const timers = [];
    let now = 10000;
    let random = 0;
    const config = {t: 'fixture', n: ['client.web_vitals.sampled', 'client.error.raised', ...names], b: 25, q: 100, r: 100, f: 10, v: .1, o: 'admin.dam.index', ...extra};
    let shown = configured ? config : null;
    const document = {visibilityState: 'visible', readyState: 'complete', prerendering: false,
        getElementById: id => id === 'dam-t' && shown ? {textContent: JSON.stringify(shown)} : null,
        addEventListener: (type, fn) => { if (!documentListeners.has(type)) documentListeners.set(type, new Set()); documentListeners.get(type).add(fn); }};
    const navigation = {entryType: 'navigation', type: navigationType, startTime: 0, responseStart: 100, activationStart: 0, name: 'https://private.test/?private=never-send'};
    class Observer {
        static supportedEntryTypes = ['paint', 'navigation', 'event', 'first-input', 'layout-shift', 'largest-contentful-paint'];
        constructor(callback) { this.callback = callback; this.types = new Set(); this.records = []; observers.push(this); }
        observe(options) { this.types.add(options.type); }
        disconnect() { this.types.clear(); }
        takeRecords() { return this.records.splice(0); }
    }
    class EventTiming {}
    EventTiming.prototype.interactionId = 0;
    const context = {
        document, console, URL, location: {origin: 'https://space.test', pathname: '/admin/dam'}, PerformanceObserver: Observer, PerformanceEventTiming: EventTiming,
        performance: {interactionCount: 0, now: () => now, getEntriesByType: type => type === 'navigation' ? [navigation] : []},
        crypto: require('node:crypto').webcrypto, innerWidth: width, screen,
        matchMedia: query => ({matches: query === '(pointer: coarse)' && coarse}),
        Math: Object.assign(Object.create(Math), {random: () => random++ === 0 ? sample : .5 + random / 10000}),
        addEventListener: (type, fn) => { if (!listeners.has(type)) listeners.set(type, new Set()); listeners.get(type).add(fn); },
        removeEventListener: (type, fn) => listeners.get(type)?.delete(fn),
        setTimeout: (fn, delay = 0) => { timers.push({fn, delay}); return timers.length; }, clearTimeout: () => {},
        requestAnimationFrame: fn => { queueMicrotask(() => fn(now)); },
        requestIdleCallback: fn => { queueMicrotask(fn); }, queueMicrotask,
        sessionStorage: {getItem: () => null, setItem: () => {}},
        navigator: {sendBeacon: (_url, body) => { sent.push(...JSON.parse(body).e); return true; }},
        fetch: (_url, options) => { sent.push(...JSON.parse(options.body).e); return Promise.resolve({headers: {get: () => null}}); },
    };
    context.window = context; context.self = context;
    vm.runInNewContext(bundle, context);
    const settle = async () => {
        for (let i = 0; i < 12; i++) {
            await Promise.resolve();
            for (const timer of timers.filter(timer => timer.delay === 0 && !timer.ran)) {
                timer.ran = true;
                timer.fn();
            }
        }
    };
    const event = async (type, extra = {}) => {
        for (const fn of [...(listeners.get(type) || [])]) fn({type, timeStamp: now, isTrusted: true, ...extra});
        await settle();
    };
    const entries = async (type, values) => {
        for (const observer of observers.filter(o => o.types.has(type))) {
            observer.callback({getEntries: () => values.map(value => ({entryType: type, duration: 0, ...value}))});
        }
        await settle();
    };
    await settle();
    // An admin AJAX visit: the destination's config (or its absence) replaces the shown one.
    const navigate = async destination => {
        shown = destination;
        for (const fn of [...(documentListeners.get('mothership:navigate:success') || [])]) fn({type: 'mothership:navigate:success'});
        await settle();
    };
    return {sent, context, observers, entries, event, navigate, config,
        hide: async () => { document.visibilityState = 'hidden'; await event('visibilitychange'); },
        show: () => { document.visibilityState = 'visible'; now += 10000; },
    };
}

test('CLS uses the largest shift session and INP uses p98 interactions, with lifecycle revisions', async () => {
    const page = await browser();
    await page.entries('paint', [{name: 'first-contentful-paint', startTime: 200}]);
    await page.entries('layout-shift', [
        {startTime: 300, value: .1, hadRecentInput: false},
        {startTime: 800, value: .2, hadRecentInput: false},
    ]);
    await page.entries('layout-shift', [{startTime: 3000, value: .25, hadRecentInput: false},
        {startTime: 3200, value: .9, hadRecentInput: true}]);
    page.context.performance.interactionCount = 50;
    await page.entries('event', Array.from({length: 50}, (_, i) => ({
        name: 'click', interactionId: i + 1, startTime: 1000 + i * 10, duration: i === 0 ? 1000 : i === 1 ? 200 : 48,
    })).concat([{name: 'mousemove', interactionId: 0, startTime: 2000, duration: 4000}]));
    await page.hide();
    const metric = name => page.sent.filter(e => e.p.metric === name);
    assert.equal(metric('CLS')[0].p.value, 300);
    assert.equal(metric('INP')[0].p.value, 200);
    assert.equal(metric('FCP')[0].p.value, 200);
    assert.equal(metric('TTFB')[0].p.value, 100);
    assert.equal(metric('CLS')[0].p.measurement, 'web-vitals-6.2.3');
    assert.ok(metric('CLS')[0].p.metric_id);
    page.show();
    await page.entries('layout-shift', [{startTime: 15000, value: .8, hadRecentInput: false}]);
    page.context.performance.interactionCount = 51;
    await page.entries('event', [{name: 'click', interactionId: 51, startTime: 15020, duration: 600}]);
    await page.hide();
    assert.equal(metric('CLS').length, 2);
    assert.equal(metric('CLS')[1].p.value, 800);
    assert.equal(metric('CLS')[1].p.metric_id, metric('CLS')[0].p.metric_id);
    assert.equal(metric('CLS')[1].p.metric_revision, 2);
    assert.equal(metric('INP').length, 2);
    assert.equal(metric('INP')[1].p.value, 600);
    assert.equal(metric('INP')[1].p.metric_revision, 2);
    assert.equal(metric('INP')[1].p.metric_id, metric('INP')[0].p.metric_id);
    await page.hide();
    assert.equal(metric('CLS').length, 2, 'unchanged hidden callbacks add no snapshots');
    assert.ok(page.sent.every(e => !JSON.stringify(e).includes('private.test')));
});

test('LCP finalizes at interaction and ignores later paint; BFCache gets new metric IDs', async () => {
    const page = await browser();
    await page.entries('largest-contentful-paint', [{startTime: 500}]);
    await page.event('click');
    await page.entries('largest-contentful-paint', [{startTime: 2640000}]);
    await page.hide();
    const lcp = () => page.sent.filter(e => e.p.metric === 'LCP');
    assert.equal(lcp()[0].p.value, 500);
    page.show();
    await page.event('pageshow', {persisted: true, timeStamp: 19000});
    await page.hide();
    assert.equal(lcp().length, 2);
    assert.notEqual(lcp()[0].p.metric_id, lcp()[1].p.metric_id);
    assert.equal(lcp()[1].p.navigation_type, 'back-forward-cache');
    assert.equal(lcp()[1].p.route, 'admin.dam.index');
});

test('a history reload keeps interaction metrics but drops its load timings', async () => {
    const page = await browser({navigationType: 'back_forward'});
    await page.entries('paint', [{name: 'first-contentful-paint', startTime: 78447}]);
    await page.entries('largest-contentful-paint', [{startTime: 78447}]);
    await page.entries('layout-shift', [{startTime: 79000, value: .05, hadRecentInput: false}]);
    await page.hide();
    const metrics = page.sent.filter(e => e.n === 'client.web_vitals.sampled').map(e => e.p.metric);
    assert.deepEqual(metrics, ['CLS']);
    assert.equal(page.sent[0].p.navigation_type, 'back-forward');
});

test('sampling and absence of privacy-gated configuration register no vitals observers', async () => {
    assert.equal((await browser({sample: .2})).observers.length, 0);
    assert.equal((await browser({configured: false})).observers.length, 0);
});

test('hidden finalizes LCP without input and no interaction invents no INP sample', async () => {
    const page = await browser();
    await page.entries('largest-contentful-paint', [{startTime: 950}]);
    await page.hide();
    assert.equal(page.sent.find(event => event.p.metric === 'LCP').p.value, 950);
    assert.equal(page.sent.filter(event => event.p.metric === 'INP').length, 0);
    page.show();
    await page.entries('largest-contentful-paint', [{startTime: 2640000}]);
    await page.hide();
    assert.equal(page.sent.filter(event => event.p.metric === 'LCP').length, 1);
});

test('global error listeners retain cap five and never export message, stack or primitive rejection text', async () => {
    const page = await browser({sample: .2});
    await page.event('error', {error: new TypeError('private-message'), filename: 'https://space.test/themes/dam/build/assets/app-Abc123_d.js?private-query', lineno: 10, colno: 20});
    await page.event('unhandledrejection', {reason: 'private-rejection'});
    await page.event('unhandledrejection', {reason: {get name() { throw new Error('private-getter'); }}});
    for (let i = 0; i < 4; i++) await page.event('error', {message: 'private-message'});
    await page.hide();
    assert.equal(page.sent.length, 5);
    assert.equal(page.sent[0].p.error_type, 'TypeError');
    assert.equal(page.sent[0].p.bundle, 'app-Abc123_d.js');
    assert.equal(page.sent[1].p.kind, 'rejection');
    assert.ok(!JSON.stringify(page.sent).includes('private'));
});

test('route views follow the shown config and never send a stale route across an opt-out boundary', async () => {
    const page = await browser({names: ['route.viewed']});
    await page.navigate({...page.config, o: 'admin.home.index'});
    await page.navigate(null);
    await page.event('pageshow', {persisted: true});
    await page.event('pageshow', {persisted: false});
    await page.navigate({...page.config, o: 'admin.dam.index'});
    await page.hide();
    const views = page.sent.filter(event => event.n === 'route.viewed').map(event => [event.p.route, event.p.navigation]);
    assert.deepEqual(views, [['admin.dam.index', 'document'], ['admin.home.index', 'spa'], ['admin.dam.index', 'spa']]);
});

test('a link page sends its view token back with the route view; an admin page has none to send', async () => {
    const link = await browser({names: ['route.viewed'], extra: {o: 'dam.share.show', k: '7d1c0e9a-1111-4a2b-9c3d-000000000001'}});
    const admin = await browser({names: ['route.viewed']});
    for (const page of [link, admin]) await page.hide();
    assert.equal(link.sent[0].p.view_token, '7d1c0e9a-1111-4a2b-9c3d-000000000001');
    assert.equal('view_token' in admin.sent[0].p, false);
});

test('every event carries only a coarse device class and width band', async () => {
    const desktop = await browser({names: ['route.viewed']});
    const phone = await browser({names: ['route.viewed'], width: 390, coarse: true, screen: {width: 390, height: 844}});
    const tablet = await browser({names: ['route.viewed'], width: 1024, coarse: true, screen: {width: 1024, height: 1366}});
    for (const page of [desktop, phone, tablet]) await page.hide();
    assert.deepEqual(desktop.sent[0].x, {dc: 'desktop', vw: '1024-1439'});
    assert.deepEqual(phone.sent[0].x, {dc: 'phone', vw: 'lt640'});
    assert.deepEqual(tablet.sent[0].x, {dc: 'tablet', vw: '1024-1439'});
});
