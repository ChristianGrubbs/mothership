const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.resolve(__dirname, '../../../packages/Mothership/DAM/src/Resources/assets/js/transfers-host.js'), 'utf8');

function fixture({request = async () => ({grant: 'proof'}), getTheme = () => 'light', track = () => {}} = {}) {
    const window = {addEventListener() {}};
    const document = {querySelector: () => null, addEventListener() {}};
    vm.runInNewContext(source, {window, document, URL, AbortController, setInterval, clearInterval, setTimeout, clearTimeout});
    const messages = [], timers = [], states = [], deadlines = new Map();let nextDeadline = 0;
    let offers = 0;
    const frame = {contentWindow: {postMessage: (message, origin) => messages.push({message, origin})}};
    const bridge = window.MothershipTransfers.create({config: {origin: 'https://transfers.example', frameUrl: 'https://transfers.example/', grantUrl: '/grant', selectedUserId: '7'}, frame, request, getTheme, track,
        onState: state => states.push(state), onOffer: () => offers++, schedule: (callback, delay) => {timers.push({callback, delay});return timers.length;}, unschedule: id => {timers[id - 1].cleared = true;},
        delay: (callback, ms) => {deadlines.set(++nextDeadline, {callback, ms});return nextDeadline;}, cancelDelay: id => deadlines.delete(id)});
    const receive = (data, extra = {}) => bridge.receive({origin: 'https://transfers.example', source: frame.contentWindow, data: {channel: 'mothership-transfers', version: 1, ...data}, ...extra});
    const expire = () => {for (const [id, entry] of [...deadlines]) {deadlines.delete(id);entry.callback();}};
    return {bridge, receive, messages, timers, states, deadlines, expire, frame, offers: () => offers};
}
const settle = () => new Promise(resolve => setImmediate(resolve));

test('missing child ready becomes unavailable and explicit reconnect reloads the frame with a new deadline', async () => {
    let requests = 0;
    const f = fixture({request: async () => {requests++;return {grant: 'proof'};}});
    f.expire();
    assert.equal(f.bridge.getState().status, 'unavailable');
    assert.equal(requests, 0);
    f.receive({type: 'ready'});await settle();assert.equal(requests, 0, 'late ready cannot restart a failed attempt');
    f.bridge.reconnect();assert.equal(f.bridge.getState().status, 'connecting');
    assert.equal(f.frame.src, 'https://transfers.example/');
    assert.equal(f.deadlines.size, 1);
    f.receive({type: 'ready'});await settle();assert.equal(requests, 1);
    f.receive({type: 'state', status: 'ready'});assert.equal(f.deadlines.size, 0);
    f.bridge.stop();
});

test('hung grant aborts once, stops background retry and late completion cannot clear the new pending request', async () => {
    const requests = [];
    const f = fixture({request: (url, options) => new Promise((resolve, reject) => requests.push({resolve, reject, options}))});
    f.receive({type: 'ready'});
    for (let i = 0; i < 10; i++) f.timers[0].callback();
    assert.equal(requests.length, 1);
    f.expire();assert.equal(f.bridge.getState().status, 'unavailable');
    assert.equal(requests[0].options.signal.aborted, true);
    assert.equal(f.timers[0].cleared, true);
    f.timers[0].callback();assert.equal(requests.length, 1);
    f.bridge.reconnect();assert.equal(requests.length, 2);
    requests[0].resolve({grant: 'old'});await settle();
    await f.bridge.renew();assert.equal(requests.length, 2);
    assert.equal(f.messages.some(item => item.message.type === 'initialize'), false);
    requests[1].resolve({grant: 'new'});await settle();
    assert.equal(f.messages.filter(item => item.message.type === 'initialize').length, 1);
    assert.equal(f.messages.find(item => item.message.type === 'initialize').message.grant, 'new');
    f.receive({type: 'state', status: 'ready'});assert.equal(f.deadlines.size, 0);
    f.bridge.stop();
});

test('initialized child that never confirms transport readiness also has a bounded deadline', async () => {
    const f = fixture();f.receive({type: 'ready'});await settle();
    f.receive({type: 'state', status: 'connecting'});f.expire();
    assert.equal(f.bridge.getState().status, 'unavailable');
    assert.equal(f.messages.at(-1).message.type, 'disconnect');
    assert.equal(f.deadlines.size, 0);
});

test('analytics requires initialized pinned frame and strips contents before first-party capture', async () => {
    const captured = [];
    const f = fixture({track: (...args) => captured.push(JSON.parse(JSON.stringify(args)))});
    const message = {type: 'analytics', event: 'acknowledged', props: {transfer_id: 'abcdefgh-1234', direction: 'send', files: 2, text: 'private contents', name: 'private name'}};
    f.receive(message);assert.equal(captured.length, 0);
    f.receive({type: 'ready'});await settle();
    f.receive(message, {origin: 'https://other.example'});
    f.receive(message, {source: {}});
    f.receive({...message, event: 'saved'});
    f.receive({...message, props: {...message.props, transfer_id: undefined}});
    assert.equal(captured.length, 0);
    f.receive(message);
    assert.deepEqual(captured, [['transfer.file.acknowledged', 0, {transfer_id: 'abcdefgh-1234', direction: 'send', files: 2}, 'transfers']]);
    const broken = fixture({track: () => {throw Error('optional');}});
    broken.receive({type: 'ready'});await settle();
    assert.doesNotThrow(() => broken.receive(message));
});

test('pins channel, version, origin and frame source before obtaining a grant', async () => {
    let requests = 0;
    const f = fixture({request: async () => {requests++;return {grant: 'proof'};}});
    f.receive({type: 'ready'}, {origin: 'https://other.example'});
    f.receive({type: 'ready'}, {source: {}});
    f.receive({type: 'ready', version: 2});
    f.receive({type: 'ready', channel: 'other'});
    assert.equal(requests, 0);
    f.receive({type: 'ready'});await settle();
    assert.equal(requests, 1);
    assert.equal(f.messages[0].origin, 'https://transfers.example');
    assert.equal(f.messages[0].message.type, 'initialize');
    assert.equal(f.messages[0].message.selectedUserId, '7');
    assert.equal(f.messages[0].message.grant, 'proof');
    assert.equal(f.timers[0].delay, 30000);
});

(process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH ? test : test.skip)('host Reconnect follows real CSS status and keyboard focus uses dark contrast tokens', async () => {
    const browser = await launchBrowser();
    try {
        const page = await browser.newPage();
        await page.route('https://transfers.example/', route => route.fulfill({contentType: 'text/html', body: '<script>window.initializes=0;addEventListener("message",e=>{if(e.data.type==="initialize")window.initializes++;});</script>'}));
        await page.setContent('<html class="dark"><body><div class="mothership-transfers" data-transfers-host><div class="mothership-transfers-toolbar"><span tabindex="0" id="start">Start</span><a class="secondary-button" href="#">Separate window</a><button class="secondary-button" data-transfers-reconnect hidden>Reconnect</button></div><p data-transfers-status></p><p data-transfers-activity></p><iframe></iframe></div><button class="agh-qv-send">Send</button></body></html>');
        const assets = path.resolve(__dirname, '../../../packages/Mothership/DAM/src/Resources/assets');
        await page.addStyleTag({content: '.secondary-button{display:flex}body{background:#17191d}.mothership-transfers{background:#24191b}'});
        await page.addStyleTag({content: fs.readFileSync(path.join(assets, 'css/home.css'), 'utf8') + fs.readFileSync(path.join(assets, 'css/transfers.css'), 'utf8')});
        await page.evaluate(() => {
            window.__grantRequests = 0;window.__fail = false;
            window.fetch = async () => {window.__grantRequests++;return {ok: !window.__fail, json: async () => ({grant: 'proof'})};};
            window.setInterval = callback => {window.__renew = callback;return 1;};window.clearInterval = () => {};
            document.querySelector('[data-transfers-host]').dataset.transfersConfig = JSON.stringify({origin: 'https://transfers.example', frameUrl: 'https://transfers.example/', grantUrl: '/grant', labels: {connecting: 'Connecting', ready: 'Ready', unavailable: 'Unavailable', disconnected: 'Disconnected', idle: 'Idle'}});
            window.__receive = data => dispatchEvent(new MessageEvent('message', {origin: 'https://transfers.example', source: document.querySelector('iframe').contentWindow, data: {channel: 'mothership-transfers', version: 1, ...data}}));
        });
        await page.addScriptTag({content: source});
        await page.frameLocator('iframe').locator('body').waitFor();
        await page.evaluate(() => window.__receive({type: 'ready'}));
        await page.waitForFunction(() => window.__grantRequests === 1);
        const reconnect = page.locator('[data-transfers-reconnect]');
        for (const status of ['ready', 'connecting']) {
            await page.evaluate(status => window.__receive({type: 'state', status}), status);
            assert.equal(await reconnect.evaluate(button => getComputedStyle(button).display), 'none');
        }
        await page.evaluate(() => window.__receive({type: 'state', status: 'disconnected'}));
        assert.equal(await reconnect.isVisible(), true);await reconnect.click();
        await page.waitForFunction(() => window.__grantRequests === 2);
        await page.waitForFunction(() => document.querySelector('[data-transfers-reconnect]').hidden);
        await page.evaluate(() => window.__receive({type: 'state', status: 'ready', activeCount: 1}));
        await page.evaluate(async () => {window.__fail = true;await window.__renew();});
        assert.equal(await reconnect.isVisible(), false, 'a failed renewal preserves the initialized session');
        await page.evaluate(async () => {window.__fail = false;await window.__renew();});
        assert.equal(await page.evaluate(() => window.__grantRequests), 4);
        await page.frame({url: 'https://transfers.example/'}).waitForFunction(() => window.initializes === 2);
        // A new child session still requires explicit retry when its initial grant fails.
        await page.evaluate(() => {window.__fail = true;window.__receive({type: 'ready'});});
        await reconnect.waitFor({state: 'visible'});
        assert.equal(await reconnect.isVisible(), true);
        await page.evaluate(() => {window.__fail = false;window.__receive({type: 'reconnect'});});
        await page.waitForFunction(() => window.__grantRequests === 6);
        await page.frame({url: 'https://transfers.example/'}).waitForFunction(() => window.initializes === 3);
        assert.equal(await reconnect.evaluate(button => getComputedStyle(button).display), 'none');
        for (const selector of ['.mothership-transfers-toolbar a']) {
            await page.locator('#start').focus();
            for (let index = 0; index < 5 && !await page.locator(selector).evaluate(el => el === document.activeElement); index++) await page.keyboard.press('Tab');
            const seen = await page.locator(selector).evaluate(el => ({focused: el.matches(':focus-visible'), outline: getComputedStyle(el).outlineColor, width: getComputedStyle(el).outlineWidth, fill: getComputedStyle(el).backgroundColor}));
            assert.equal(seen.focused, true);assert.equal(seen.outline, 'rgb(255, 107, 114)');assert.equal(seen.width, '2px');
            const luminance = rgb => rgb.map(value => {const c = value / 255;return c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;}).reduce((sum, value, index) => sum + value * [0.2126, 0.7152, 0.0722][index], 0);
            const indicator = luminance([255, 107, 114]);
            for (const surface of [[23, 25, 29], [36, 25, 27], [57, 40, 44]]) assert.ok((indicator + 0.05) / (luminance(surface) + 0.05) >= 3);
            if (selector.endsWith(' a')) assert.equal(seen.fill, 'rgb(57, 40, 44)');
        }
    } finally {await browser.close();}
});

test('renews fresh proof, selects a user, opens offers and validates ready roster', async () => {
    const f = fixture();f.receive({type: 'ready'});await settle();
    await f.timers[0].callback();
    assert.equal(f.messages.find(item => item.message.type === 'authenticate').message.type, 'authenticate');
    f.bridge.select('9');
    assert.equal(f.messages.at(-1).message.selectedUserId, '9');
    f.receive({type: 'state', readyPeers: [{userId: '9', peerId: 'p', name: 'Staff', deviceName: 'Laptop'}, {userId: 3}], activeCount: 2});
    assert.equal(f.bridge.getState().readyPeers.length, 1);
    assert.equal(f.bridge.getState().activeCount, 2);
    f.receive({type: 'incoming-offer'});
    assert.equal(f.offers(), 1);
    f.receive({type: 'state', activeCount: -1});
    assert.equal(f.bridge.getState().activeCount, 0);
});

test('initial grant rejection requires explicit retry without leaking proof', async () => {
    let fail = true;
    const f = fixture({request: async () => {if (fail) throw new Error('offline');return {grant: 'new-proof'};}});
    f.receive({type: 'ready'});await settle();
    assert.equal(f.messages.at(-1).message.type, 'disconnect');
    assert.equal(f.bridge.getState().status, 'unavailable');
    fail = false;f.receive({type: 'reconnect'});await settle();
    assert.equal(f.messages.at(-2).message.type, 'initialize');
    assert.equal(JSON.stringify(f.states).includes('proof'), false);
});

for (const failure of ['timeout', 'rejection']) test(`active transfer survives renewal ${failure}, retries on schedule and ignores stale completion`, async () => {
    const requests = [];
    const f = fixture({request: (_url, {signal}) => new Promise((resolve, reject) => requests.push({resolve, reject, signal}))});
    f.receive({type: 'ready'});requests[0].resolve({grant: 'initial'});await settle();
    f.receive({type: 'state', status: 'ready', activeCount: 1, readyPeers: [{userId: '7', peerId: 'p', name: 'Example', deviceName: 'Fixture'}]});
    const state = JSON.stringify(f.bridge.getState());const messages = f.messages.length;
    f.timers[0].callback();
    if (failure === 'timeout') f.expire();else requests[1].reject(new Error('offline'));
    await settle();
    assert.equal(requests[1].signal.aborted, true);
    assert.equal(JSON.stringify(f.bridge.getState()), state);
    assert.equal(f.messages.length, messages, 'renewal failure must not send disconnect');
    assert.equal(f.timers[0].cleared, undefined);
    f.timers[0].callback();assert.equal(requests.length, 3);
    requests[1].resolve({grant: 'stale'});await settle();
    await f.bridge.renew();assert.equal(requests.length, 3, 'old completion cannot clear new pending request');
    assert.equal(f.messages.length, messages);
    requests[2].resolve({grant: 'renewed'});await settle();
    assert.equal(f.messages.at(-1).message.type, 'authenticate');
    assert.equal(f.messages.at(-1).message.grant, 'renewed');
    assert.equal(JSON.stringify(f.bridge.getState()), state);
    f.bridge.stop();assert.equal(f.deadlines.size, 0);
});

test('teardown and child reload reject stale in-flight proof and keep one renewal timer', async () => {
    const requests = [];
    const signals = [];
    const f = fixture({request: (_url, {signal}) => {signals.push(signal);return new Promise(resolve => requests.push(resolve));}});
    f.receive({type: 'ready'});f.receive({type: 'ready'});
    assert.equal(signals[0].aborted, true);
    requests[0]({grant: 'old'});await settle();
    assert.equal(f.messages.length, 0);
    requests[1]({grant: 'new'});await settle();
    assert.equal(f.messages[0].message.grant, 'new');
    assert.equal(f.timers.length, 1);
    f.timers[0].callback();f.bridge.stop();requests[2]({grant: 'late'});await settle();
    assert.equal(signals[2].aborted, true);
    assert.equal(f.deadlines.size, 0);
    assert.equal(f.messages.at(-1).message.type, 'disconnect');
    assert.equal(f.timers[0].cleared, true);
    assert.equal(f.bridge.getState().readyPeers.length, 0);
    f.receive({type: 'ready'});assert.equal(requests.length, 3);
});

test('document runtime survives card rerenders, opens offers, guards navigation and cleans removed pages', async () => {
    class Target {
        constructor() {this.listeners = new Map();}
        addEventListener(name, callback) {if (!this.listeners.has(name)) this.listeners.set(name, new Set());this.listeners.get(name).add(callback);}
        removeEventListener(name, callback) {this.listeners.get(name)?.delete(callback);}
        emit(name, event = {}) {this.listeners.get(name)?.forEach(callback => callback(event));return event;}
    }
    const window = new Target(), document = new Target(), dialog = new Target(), close = new Target(), reconnect = new Target();
    let historyHandler, historyReleased = 0;
    window.location = {href: 'https://host.example/transfers', replace: address => {window.replaced = address;}};
    window.history = {state: {page: 'transfers'}, pushState: (_state, _title, address) => {window.location.href = address;}};
    window.confirm = () => false;
    window.mothership = {registerLocalHistoryHandler: handler => {historyHandler = handler;return () => {historyReleased++;historyHandler = null;};}};
    const messages = [], intervals = [], calls = [];
    const frame = {contentWindow: {postMessage: data => messages.push(data)}};
    let frameLoads = 0;
    Object.defineProperty(frame, 'src', {get() {return this.address;}, set(value) {this.address = value;frameLoads++;}});
    dialog.open = false;dialog.showModal = () => {dialog.open = true;};dialog.close = () => {dialog.open = false;dialog.emit('close');};
    const status = {}, activity = {};
    const config = {origin: 'https://transfers.example', frameUrl: 'https://transfers.example/', grantUrl: '/grant', selectedUserId: null, labels: {connecting: 'Connecting', ready: 'Ready', unavailable: 'Unavailable', active: ':count active', activeOne: '1 active transfer', idle: 'Idle'}};
    const root = {isConnected: true, dataset: {csrf: 'fixture', transfersConfig: JSON.stringify(config)}, querySelector(selector) {return {iframe: frame, dialog, '[data-transfers-status]': status, '[data-transfers-activity]': activity, '[data-transfers-close]': close, '[data-transfers-reconnect]': reconnect}[selector];}};
    let present = root;
    document.querySelector = () => present;
    let focused = false;document.activeElement = {focus: () => {focused = true;}};
    let themeDark = false, themeCallback, themeReleased = false;
    document.documentElement = {classList: {contains: () => themeDark}};
    const deadlines = new Map();let nextDeadline = 0;
    vm.runInNewContext(source, {window, document, URL, AbortController, clearTimeout: id => deadlines.delete(id), setTimeout: (callback, ms) => {if (!ms) {callback();return 0;}deadlines.set(++nextDeadline, callback);return nextDeadline;},
        MutationObserver: class {constructor(callback) {themeCallback = callback;}observe() {}disconnect() {themeReleased = true;}},
        fetch: async (url, options) => {calls.push({url, options});return {ok: true, json: async () => ({grant: 'proof'})};},
        setInterval: callback => {intervals.push({callback});return intervals.length;}, clearInterval: id => {intervals[id - 1].cleared = true;}});
    assert.equal(frame.src, config.frameUrl);
    assert.equal(window.listeners.get('message').size, 1);
    for (const callback of [...deadlines.values()]) callback();
    assert.equal(status.textContent, 'Unavailable');assert.equal(reconnect.hidden, false);
    assert.equal(calls.length, 0);
    reconnect.emit('click');
    assert.equal(frameLoads, 2, 'the visible host action reloads a child that never became ready');
    assert.equal(status.textContent, 'Connecting');assert.equal(reconnect.hidden, true);
    const receive = data => window.emit('message', {origin: config.origin, source: frame.contentWindow, data: {channel: 'mothership-transfers', version: 1, ...data}});
    receive({type: 'ready'});await settle();
    assert.equal(calls[0].options.method, 'POST');
    assert.equal(calls[0].options.headers['X-CSRF-TOKEN'], 'fixture');
    assert.equal(calls[0].options.credentials, 'same-origin');
    assert.equal(calls[0].options.signal.aborted, false);
    themeDark = true;themeCallback();assert.equal(messages.at(-1).theme, 'dark');
    window.MothershipTransfers.mount();
    assert.equal(intervals.length, 1);
    assert.equal(window.listeners.get('message').size, 1);
    receive({type: 'incoming-offer'});assert.equal(dialog.open, true);
    close.emit('click');assert.equal(dialog.open, false);assert.equal(focused, true);
    document.activeElement = {isConnected: false};
    let replacementFocused = false;
    document.querySelectorAll = () => [{dataset: {qvSend: '9'}, focus: () => {replacementFocused = true;}}];
    window.MothershipTransfers.openPeer('9');close.emit('click');
    assert.equal(replacementFocused, true, 'a refreshed card restores focus to the same staff Send control');
    receive({type: 'state', readyPeers: [], activeCount: 1});
    assert.equal(activity.textContent, '1 active transfer');
    let prevented = false;
    window.emit('beforeunload', {preventDefault: () => {prevented = true;}});
    assert.equal(prevented, true);
    window.location.href = 'https://host.example/home';
    assert.equal(historyHandler(), true);
    assert.equal(window.location.href, 'https://host.example/transfers', 'cancelled Back restores the owning URL');
    assert.equal(window.replaced, undefined);
    receive({type: 'state', readyPeers: [], activeCount: 0});
    assert.equal(historyReleased, 1);
    window.emit('pagehide');
    window.emit('pageshow', {persisted: true});
    receive({type: 'ready'});await settle();
    assert.equal(calls.length, 2, 'bfcache restore obtains fresh proof through a remounted bridge');
    assert.equal(window.listeners.get('message').size, 1);
    receive({type: 'state', readyPeers: [], activeCount: 1});
    prevented = false;document.emit('mothership:navigate:before', {preventDefault: () => {prevented = true;}});
    assert.equal(prevented, true);
    window.location.href = 'https://host.example/earlier';window.confirm = () => true;historyHandler();
    assert.equal(window.replaced, 'https://host.example/earlier', 'confirmed Back replaces the restored entry');
    present = null;root.isConnected = false;document.emit('mothership:navigate:success');
    assert.equal(intervals[0].cleared, true);
    assert.equal(messages.at(-1).type, 'disconnect');
    assert.equal(window.listeners.get('message').size, 0);
    assert.equal(themeReleased, true);
    assert.equal(deadlines.size, 0);
    prevented = false;window.emit('beforeunload', {preventDefault: () => {prevented = true;}});
    assert.equal(prevented, false);
});

test('initial runtime binds the iframe Vue leaves after DOMContentLoaded, never the replaced markup', () => {
    const events = new Map(), tasks = [], window = {addEventListener() {}, removeEventListener() {}};
    const config = {origin: 'https://transfers.example', frameUrl: 'https://transfers.example/', labels: {idle: 'Idle'}};
    const before = {contentWindow: {}}, after = {contentWindow: {}};
    const makeRoot = frame => ({isConnected: true, dataset: {transfersConfig: JSON.stringify(config)}, querySelector(selector) {return {iframe: frame, '[data-transfers-status]': {}, '[data-transfers-activity]': {}}[selector] || null;}});
    let root = makeRoot(before);
    const document = {readyState: 'loading', querySelector: () => root, addEventListener: (name, callback) => events.set(name, callback), removeEventListener() {}};
    vm.runInNewContext(source, {window, document, URL, AbortController, clearTimeout() {}, setInterval, clearInterval, setTimeout: callback => tasks.push(callback)});
    assert.equal(before.src, undefined);
    events.get('DOMContentLoaded')();
    root = makeRoot(after); // Vue mounts synchronously in the same DOMContentLoaded task.
    tasks.shift()();
    assert.equal(before.src, undefined);
    assert.equal(after.src, config.frameUrl);
});

test('theme follows explicit host light/dark state after initialization and stops with the runtime', async () => {
    let theme = 'light';
    const f = fixture({getTheme: () => theme});
    f.bridge.updateTheme();assert.equal(f.messages.length, 0);
    f.receive({type: 'ready'});await settle();
    assert.equal(f.messages[1].message.type, 'theme');assert.equal(f.messages[1].message.theme, 'light');
    theme = 'dark';f.bridge.updateTheme();
    assert.equal(f.messages.at(-1).message.theme, 'dark');
    const count = f.messages.length;f.bridge.updateTheme();assert.equal(f.messages.length, count);
    f.bridge.stop();theme = 'light';f.bridge.updateTheme();
    assert.equal(f.messages.at(-1).message.type, 'disconnect');
});

test('exact peer selection distinguishes two devices belonging to one user', async () => {
    const f = fixture();f.bridge.selectPeer('phone');f.receive({type: 'ready'});await settle();
    assert.equal(f.messages[0].message.selectedPeerId, 'phone');
    assert.equal(f.messages[0].message.selectedUserId, null);
    f.bridge.selectPeer('laptop');
    assert.equal(f.messages.at(-1).message.type, 'select-peer');
    assert.equal(f.messages.at(-1).message.selectedPeerId, 'laptop');
});

test('intentional child disconnect pauses proof renewal and reconnect mints fresh initialize', async () => {
    let requests = 0;const f = fixture({request: async () => {requests++;return {grant: 'proof'};}});
    f.receive({type: 'ready'});await settle();
    f.receive({type: 'state', status: 'disconnected', readyPeers: [], activeCount: 0});
    assert.equal(f.bridge.getState().status, 'disconnected');assert.equal(f.timers[0].cleared, true);
    await f.timers[0].callback();assert.equal(requests, 1);
    f.receive({type: 'reconnect'});await settle();
    assert.equal(requests, 2);assert.equal(f.messages.filter(item => item.message.type === 'initialize').length, 2);
    assert.equal(f.timers.length, 2);
});

const playwrightPath = process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH;
async function launchBrowser() {
    const {chromium} = require(playwrightPath);
    const launcher = process.env.MOTHERSHIP_CHROME_LAUNCHER;
    if (process.platform === 'darwin') assert.ok(launcher, 'MOTHERSHIP_CHROME_LAUNCHER must name the approved local launcher');
    return launcher
        ? await (await import(require('node:url').pathToFileURL(launcher).href)).launchChrome({chromium, playwrightVersion: require(path.join(playwrightPath, 'package.json')).version})
        : await chromium.launch({headless: true, channel: process.env.MOTHERSHIP_BROWSER_CHANNEL || 'chrome'});
}
test('the frame URL names this page\'s origin in hub_parent so the child can pick among its allowed parents', () => {
    const window = {addEventListener() {}, location: {origin: 'https://mothership.example.net'}};
    vm.runInNewContext(source, {window, document: {querySelector: () => null, addEventListener() {}}, URL, AbortController, setInterval, clearInterval, setTimeout, clearTimeout});
    const frame = {contentWindow: {postMessage() {}}};
    const bridge = window.MothershipTransfers.create({config: {origin: 'https://transfers.example', frameUrl: 'https://transfers.example/', grantUrl: '/grant'}, frame,
        request: async () => ({grant: 'proof'}), schedule: () => 1, unschedule() {}, delay: () => 1, cancelDelay() {}});
    bridge.reconnect();
    assert.equal(frame.src, 'https://transfers.example/?hub_parent=https%3A%2F%2Fmothership.example.net');
    bridge.stop();
});
