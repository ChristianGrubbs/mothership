import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import { readFileSync } from 'node:fs';

// The full client runs with a deterministic clock and fictional parent/socket fixtures.
async function connectionFixture({ config = { parentOrigin: 'http://fixture', rtcConfig: {} }, location = { protocol: 'http:', host: 'fixture' }, history } = {}) {
    const timers = new Map(), listeners = new Map(), messages = [], elements = new Map(), sockets = [], connections = [];
    let next = 0;
    const element = () => ({ dataset: {}, style: {}, children: [], hidden: false, textContent: '', value: '',
        addEventListener() {}, remove() {}, querySelector() { return null; }, setAttribute(name, value) { this[name] = value; }, close() { this.open = false; }, showModal() { this.open = true; }, focus() {},
        append(...items) { this.children.push(...items); }, prepend(item) { this.children.unshift(item); },
        replaceChildren() { this.children = []; } });
    const parent = { postMessage: (message, targetOrigin) => messages.push({ ...message, targetOrigin }) };
    const window = { parent, addEventListener(type, fn) { const list = listeners.get(type) || []; list.push(fn); listeners.set(type, list); },
        dispatchEvent(event) { for (const fn of listeners.get(event.type) || []) fn(event); } };
    class Socket {
        static OPEN = 1;
        constructor() { this.readyState = 0; this.sent = []; sockets.push(this); }
        send(message) { this.sent.push(typeof message === 'string' ? JSON.parse(message) : message); }
        close() { this.readyState = 3; this.onclose?.(); }
    }
    class Connection {
        constructor() { connections.push(this); }
        createDataChannel() { this.messages = []; this.channel = { readyState: 'connecting', close() {}, send: message => this.messages.push(message) }; return this.channel; }
        createOffer() { return Promise.resolve({ type: 'offer', sdp: 'fixture' }); }
        createAnswer() { return Promise.resolve({ type: 'answer', sdp: 'fixture' }); }
        setRemoteDescription() { return Promise.resolve(); }
        setLocalDescription() { return Promise.resolve(); }
        close() { this.closed = true; }
    }
    const context = vm.createContext({ window, document: { documentElement: element(), createElement: element,
        getElementById(id) { if (!elements.has(id)) elements.set(id, element()); return elements.get(id); } },
        fetch: async () => ({ json: async () => config }),
        CustomEvent: class { constructor(type, options) { this.type = type; this.detail = options.detail; } },
        console, btoa: value => Buffer.from(value, 'binary').toString('base64'), WebSocket: Socket, RTCPeerConnection: Connection, location, history, URL,
        TextEncoder, TextDecoder, Blob, File,
        setTimeout: (fn, delay) => { timers.set(++next, { fn, delay }); return next; }, clearTimeout: id => timers.delete(id) });
    for (const file of ['vendor/pairdrop-network.js', 'transfers.js']) {
        vm.runInContext(readFileSync(new URL(`../public/${file}`, import.meta.url), 'utf8'), context);
    }
    await new Promise(resolve => setImmediate(resolve));
    const initialize = (type = 'initialize') => window.dispatchEvent({ type: 'message', origin: 'http://fixture', source: parent,
        data: { channel: 'mothership-transfers', version: 1, type, grant: 'fixture' } });
    const receive = message => sockets.at(-1).onmessage({ data: JSON.stringify(message) });
    const advance = delay => { for (const [id, timer] of [...timers]) if (timer.delay === delay) { timers.delete(id); timer.fn(); } };
    const open = () => { sockets.at(-1).readyState = 1; sockets.at(-1).onopen(); };
    const authenticate = () => receive({ type: 'authenticated', expiresAt: Date.now() / 1000 + 3600, name: 'Example', deviceName: 'Fixture' });
    return { context, timers, messages, elements, sockets, connections, initialize, receive, advance, open, authenticate };
}

test('passive peer waiting for an offer has a bounded deadline and successful connection cancels it', async () => {
    const fixture = await connectionFixture();
    const peer = vm.runInContext("new StaffPeer({server:{send(){}},caller:false,id:'waiting',room:'staff',rtcConfig:{}})", fixture.context);
    assert.ok(fixture.timers.size > 0, 'passive waiting must schedule a deadline');
    fixture.advance(15000);
    assert.equal(peer.lastConnectionDiagnostic?.source, 'connecting-timeout');
    peer.destroy();
    const healthy = vm.runInContext("new StaffPeer({server:{send(){}},caller:false,id:'healthy',room:'staff',rtcConfig:{}})", fixture.context);
    healthy._onChannelOpened({ channel: { readyState: 'open', close() {} } });
    assert.equal(fixture.timers.size, 0);
    healthy.destroy();
});

test('handshake diagnostics reach the service only when explicitly enabled after authentication', async () => {
    for (const enabled of [false, true]) {
        const f = await connectionFixture(); f.initialize(); f.open();
        f.receive({ type: 'authenticated', expiresAt: Date.now() / 1000 + 3600, diagnosticsEnabled: enabled });
        f.receive({ type: 'roster', selfId: 'z', room: 'staff', peers: [{ peerId: 'a', userId: 'alice', name: 'Example' }] });
        f.advance(15000);
        const diagnostics = f.sockets[0].sent.filter(message => message.type === 'diagnostic');
        assert.equal(diagnostics.length, enabled ? 1 : 0);
        if (enabled) {
            assert.equal(diagnostics[0].source, 'connecting-timeout');
            assert.equal(diagnostics[0].peerId, 'a');
            assert.equal(diagnostics[0].remoteCandidates, 0);
        }
    }
});

test('client reserves failure diagnostic capacity and bounds informational traffic', async () => {
    const f = await connectionFixture(); f.initialize(); f.open();
    f.receive({ type: 'authenticated', expiresAt: Date.now() / 1000 + 3600, diagnosticsEnabled: true });
    f.receive({ type: 'roster', selfId: 'z', room: 'staff', peers: [{ peerId: 'a', userId: 'alice', name: 'Example' }] });
    for (let n = 0; n < 50; n++) for (const source of ['ice-candidate', 'connecting-timeout']) {
        vm.runInContext(`Events.fire('connection-diagnostic', {peerId:'a',source:'${source}',code:0})`, f.context);
    }
    const messages = f.sockets[0].sent.filter(message => message.type === 'diagnostic');
    assert.equal(messages.filter(message => message.source === 'ice-candidate').length, 15);
    assert.equal(messages.filter(message => message.source === 'connecting-timeout').length, 30);
});

test('device rows and host readyPeers collapse tabs, prefer usable peers, and survive tab removal', async () => {
    const f = await connectionFixture(); f.initialize(); f.open(); f.authenticate();
    const peers = [
        { peerId: 'a', userId: 'alice', browserId: 'browser-a', name: 'Example', deviceName: 'Mac' },
        { peerId: 'b', userId: 'alice', browserId: 'browser-a', name: 'Example', deviceName: 'Mac' },
        { peerId: 'c', userId: 'alice', browserId: 'browser-b', name: 'Example', deviceName: 'Mac' },
        { peerId: 'd', userId: 'bob', browserId: 'browser-a', name: 'Other', deviceName: 'Mac' }
    ];
    const roster = { type: 'roster', selfId: 'z', room: 'staff', peers };
    f.receive(roster);
    assert.equal(f.elements.get('people').children.length, 3);
    for (const connection of f.connections.slice(1)) {
        connection.channel.readyState = 'open'; connection.channel.onopen({ target: connection.channel });
    }
    assert.equal(f.messages.filter(message => message.type === 'state').at(-1).readyPeers.length, 3);
    assert.equal(f.elements.get('people').children[0].dataset.peerId, 'b');
    f.connections[0].channel.readyState = 'open'; f.connections[0].channel.onopen({ target: f.connections[0].channel });
    assert.equal(f.messages.filter(message => message.type === 'state').at(-1).readyPeers.length, 3);
    assert.equal(f.elements.get('people').children[0].dataset.peerId, 'b', 'a newly ready hidden tab must not replace the current representative');
    f.context.window.dispatchEvent({ type: 'message', origin: 'http://fixture', source: f.context.window.parent,
        data: { channel: 'mothership-transfers', version: 1, type: 'select-peer', selectedPeerId: 'b' } });
    f.elements.get('people').children[0].onclick();
    assert.equal(f.elements.get('send-dialog').open, true);
    f.receive({ ...roster, peers: peers.filter(peer => peer.peerId !== 'b') });
    assert.equal(f.elements.get('people').children.length, 3);
    assert.equal(f.elements.get('people').children[0].dataset.peerId, 'a');
    assert.equal(f.elements.get('people').children[0]['aria-pressed'], 'true');
    assert.equal(f.connections[0].closed, undefined);
    f.elements.get('message').value = 'Still the same device';
    f.elements.get('send-text').onclick();
    assert.equal(JSON.parse(f.connections[0].messages.at(-1)).type, 'text', 'open dialog sends to the surviving tab');
});

test('browser identity persists across tabs and tolerates denied storage', async () => {
    const f = await connectionFixture(); const storage = new Map();
    Object.assign(f.context, { navigator: { locks: { request: async (_name, fn) => fn() } },
        localStorage: { getItem: key => storage.get(key), setItem: (key, value) => storage.set(key, value) },
        crypto: { randomUUID: () => '11111111-1111-4111-8111-111111111111' } });
    const first = await vm.runInContext('browserIdentity()', f.context);
    f.context.crypto.randomUUID = () => { throw new Error('must reuse saved identity'); };
    assert.equal(await vm.runInContext('browserIdentity()', f.context), first);
    f.context.navigator = {};
    assert.equal(await vm.runInContext('browserIdentity()', f.context), first);
    f.context.localStorage.getItem = () => { throw new Error('denied'); };
    assert.equal(await vm.runInContext('browserIdentity()', f.context), undefined);
});

test('legacy peers stay distinct and closing an active tab interrupts only its transfer', async () => {
    const f = await connectionFixture(); f.initialize(); f.open(); f.authenticate();
    const peers = [
        { peerId: 'a', userId: 'alice', browserId: 'one', name: 'Example', deviceName: 'Mac' },
        { peerId: 'b', userId: 'alice', browserId: 'one', name: 'Example', deviceName: 'Mac' },
        { peerId: 'c', userId: 'alice', name: 'Legacy', deviceName: 'Mac' },
        { peerId: 'd', userId: 'alice', browserId: null, name: 'Legacy', deviceName: 'Mac' }
    ];
    f.receive({ type: 'roster', selfId: 'z', room: 'staff', peers });
    for (const connection of f.connections) {
        connection.channel.readyState = 'open'; connection.channel.onopen({ target: connection.channel });
    }
    f.connections[1].channel.onmessage({ data: JSON.stringify({ type: 'request', totalSize: 1,
        header: [{ name: 'fixture.txt', mime: 'text/plain', size: 1 }] }) });
    assert.equal(f.elements.get('people').children.length, 3);
    assert.equal(f.messages.filter(message => message.type === 'state').at(-1).activeCount, 1);
    assert.equal(f.elements.get('people').children[0].dataset.peerId, 'b');
    f.receive({ type: 'roster', selfId: 'z', room: 'staff', peers: peers.filter(peer => peer.peerId !== 'b') });
    assert.equal(f.elements.get('people').children.length, 3);
    assert.equal(f.elements.get('people').children[0].dataset.peerId, 'a');
    assert.equal(f.messages.filter(message => message.type === 'state').at(-1).activeCount, 0);
    assert.equal(f.elements.get('activity').children[0].children[0].textContent, 'Transfer interrupted');
    assert.equal(f.connections[0].closed, undefined);
});

for (const busy of [false, true]) test(`open dialog closes when its tab leaves and no idle replacement exists (busy=${busy})`, async () => {
    const f = await connectionFixture(); f.initialize(); f.open(); f.authenticate();
    const peers = ['a', 'b'].map(peerId => ({ peerId, userId: 'alice', browserId: 'one', name: 'Example', deviceName: 'Mac' }));
    f.receive({ type: 'roster', selfId: 'z', room: 'staff', peers });
    for (const connection of f.connections) {
        connection.channel.readyState = 'open'; connection.channel.onopen({ target: connection.channel });
    }
    f.elements.get('people').children[0].onclick();
    assert.equal(f.elements.get('send-dialog').open, true);
    if (busy) f.connections[1].channel.onmessage({ data: JSON.stringify({ type: 'request', totalSize: 1,
        header: [{ name: 'fixture.txt', mime: 'text/plain', size: 1 }] }) });
    f.receive({ type: 'roster', selfId: 'z', room: 'staff', peers: busy ? peers.slice(1) : [] });
    assert.equal(f.elements.get('send-dialog').open, false);
    assert.equal(f.elements.get('activity').children[0].children[0].textContent, 'Transfer interrupted');
});

for (const phase of ['socket', 'authentication']) test(`${phase} setup cannot wait indefinitely and stale callbacks cannot disturb reconnect`, async () => {
    const f = await connectionFixture(); f.initialize();
    const old = f.sockets[0]; const lateOpen = old.onopen, lateError = old.onerror, lateClose = old.onclose, lateMessage = old.onmessage;
    if (phase === 'authentication') f.open();
    f.advance(15000);
    assert.ok(f.messages.some(message => message.type === 'error'), 'unanswered setup must report failure');
    assert.equal(f.elements.get('disconnect').textContent, 'Reconnect');
    assert.equal(old.readyState, 3);
    f.elements.get('disconnect').onclick();
    assert.equal(f.messages.at(-1).type, 'reconnect');
    f.initialize(); const current = f.sockets.at(-1);
    const count = f.messages.length;
    lateOpen(); lateError(); lateClose(); lateMessage({ data: JSON.stringify({ type: 'authenticated', expiresAt: 0 }) });
    assert.equal(current.sent.length, 0); assert.equal(f.messages.length, count);
    f.open(); f.authenticate(); f.advance(15000);
    assert.equal(current.readyState, 1);
    assert.equal(f.elements.get('disconnect').textContent, 'Disconnect');
});

for (const phase of ['socket-opening', 'authentication']) test(`${phase} socket error is terminal and its late close cannot publish again`, async () => {
    const f = await connectionFixture();f.initialize();
    const socket = f.sockets[0];const lateClose = socket.onclose;
    if (phase === 'authentication') f.open();
    socket.onerror();
    assert.equal(f.messages.filter(message => message.type === 'error' && message.code === 'connection_unavailable').length, 1);
    assert.equal(f.elements.get('disconnect').textContent, 'Reconnect');
    assert.equal(f.elements.get('connection').hidden, false);
    assert.equal(socket.readyState, 3);
    assert.equal(f.messages.filter(message => message.type === 'state').at(-1).status, 'disconnected');
    assert.equal(f.timers.size, 0);
    const count = f.messages.length;
    lateClose();
    assert.equal(f.messages.length, count);
});

test('authenticated socket error preserves close recovery on the next host authentication', async () => {
    const f = await connectionFixture();f.initialize();f.open();f.authenticate();
    const socket = f.sockets[0];const close = socket.onclose;
    socket.onerror();
    assert.equal(socket.readyState, 1, 'an established socket error must not force intentional disconnect');
    assert.equal(f.messages.some(message => message.type === 'state' && message.status === 'disconnected'), false);
    close();
    assert.match(f.elements.get('connection').textContent, /next session check/);
    f.initialize('authenticate');
    assert.equal(f.sockets.length, 2);
    f.open();f.authenticate();
    assert.equal(f.sockets[1].readyState, 1);
    assert.equal(f.elements.get('disconnect').textContent, 'Disconnect');
});

test('repeated failed roster attempts settle visibly and explicit peer retry preserves a healthy transfer', async () => {
    const f = await connectionFixture(); f.initialize(); f.open(); f.authenticate();
    const roster = { type: 'roster', selfId: 'z', room: 'staff', peers: [
        { peerId: 'a', name: 'Example A', deviceName: 'Fixture A' }, { peerId: 'b', name: 'Example B', deviceName: 'Fixture B' }] };
    f.receive(roster);
    const healthyConnection = f.connections[1];
    healthyConnection.channel.readyState = 'open';
    healthyConnection.channel.onopen({ target: healthyConnection.channel });
    healthyConnection.channel.onmessage({ data: JSON.stringify({ type: 'request', totalSize: 1,
        header: [{ name: 'fixture.txt', mime: 'text/plain', size: 1 }] }) });
    assert.equal(f.messages.filter(message => message.type === 'state').at(-1).activeCount, 1);
    f.advance(15000); f.receive(roster); f.advance(15000);
    for (let index = 0; index < 5; index++) f.receive(roster);
    assert.equal(f.connections.length, 3, 'only one automatic retry per failed peer');
    const button = f.elements.get('people').children[0];
    assert.match(button.children[1].textContent, /Retry/);
    assert.equal(button.disabled, false);
    button.onclick();
    assert.equal(f.connections.length, 4);
    assert.equal(healthyConnection.closed, undefined);
    assert.equal(f.messages.filter(message => message.type === 'state').at(-1).activeCount, 1);
});

test('exhausted passive receiver accepts a remote retry offer before a roster refresh', async () => {
    const f = await connectionFixture(); f.initialize(); f.open(); f.authenticate();
    const roster = { type: 'roster', selfId: 'a', room: 'staff', peers: [{ peerId: 'z', name: 'Example', deviceName: 'Fixture' }] };
    f.receive(roster); f.advance(15000); f.receive(roster); f.advance(15000);
    assert.match(f.elements.get('people').children[0].children[1].textContent, /Retry/);
    f.receive({ type: 'signal', sender: 'z', sdp: { type: 'offer', sdp: 'fixture' } });
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(f.sockets[0].sent.at(-1).sdp.type, 'answer');
    const connection = f.connections[0];
    connection.ondatachannel({ channel: { readyState: 'open', close() {} } });
    f.advance(15000); f.receive(roster);
    assert.equal(f.connections.length, 1);
    assert.equal(connection.closed, undefined);
    assert.match(f.elements.get('people').children[0].children[1].textContent, /Ready/);
});

const relayConfig = { parentOrigin: 'http://fixture', rtcConfig: {}, relay: { afterMs: 6000 } };
const relayPeerId = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
const relayRoster = { type: 'roster', selfId: 'ffffffff-ffff-4fff-8fff-ffffffffffff', room: 'staff',
    peers: [{ peerId: relayPeerId, userId: 'alice', name: 'Example', deviceName: 'Fixture' }] };
// What the service delivers: the sender it stamped, one kind byte (0 = protocol text, 1 = file chunk), the payload.
const relayFrame = ({ sender = relayPeerId, kind = 0, payload }) => {
    const bytes = Buffer.concat([Buffer.from(sender, 'latin1'), Buffer.from([kind]), Buffer.from(typeof payload === 'string' ? payload : JSON.stringify(payload))]);
    return bytes.buffer.slice(bytes.byteOffset, bytes.byteOffset + bytes.byteLength);
};
const sentFrames = socket => socket.sent.filter(message => message.type === undefined).map(message => Buffer.from(message));
const framedMessages = socket => sentFrames(socket).filter(bytes => bytes[36] === 0).map(bytes => JSON.parse(bytes.subarray(37).toString()));

test('a failed direct route moves the pair to the relay, and the device is ready only once the other browser joins', async () => {
    const f = await connectionFixture({ config: relayConfig }); f.initialize(); f.open(); f.authenticate();
    f.receive(relayRoster);
    const row = () => f.elements.get('people').children[0];
    const relayRequests = () => f.sockets[0].sent.filter(message => message.type === 'relay');
    f.advance(15000);
    assert.equal(relayRequests().length, 0, 'with a relay the direct route gets the configured time, not the default');
    assert.equal(row().dataset.transport, 'direct');
    f.advance(6000);
    assert.deepEqual(relayRequests(), [{ type: 'relay', to: relayPeerId, state: 'open' }]);
    assert.equal(f.connections[0].closed, true, 'the failed direct attempt is torn down');
    assert.equal(row().dataset.connectionState, 'connecting'); assert.equal(row().dataset.transport, 'relay');
    assert.equal(f.messages.filter(message => message.type === 'state').at(-1).readyPeers.length, 0);
    // Nothing is accepted before the other browser has asked for the relay too.
    f.sockets[0].onmessage({ data: relayFrame({ payload: { type: 'request', totalSize: 1, header: [{ name: 'early.txt', mime: 'text/plain', size: 1 }] } }) });
    assert.equal(f.elements.get('activity')?.children.length ?? 0, 0);
    f.receive({ type: 'relay', sender: relayPeerId, state: 'open' });
    assert.equal(row().dataset.connectionState, 'ready');
    assert.match(row().children[1].textContent, /Ready · through The Mothership/);
    assert.equal(f.messages.filter(message => message.type === 'state').at(-1).readyPeers.length, 1);
    f.receive(relayRoster); f.receive({ type: 'relay', sender: relayPeerId, state: 'open' });
    f.receive({ type: 'signal', sender: relayPeerId, sdp: { type: 'offer', sdp: 'fixture' } });
    assert.equal(relayRequests().length, 1, 'the request is sent once');
    assert.equal(f.connections.length, 1, 'a relayed pair does not start another direct attempt');
    assert.equal(row().dataset.connectionState, 'ready');
});

test('the other browser asking first moves a connecting peer to the relay at once', async () => {
    const f = await connectionFixture({ config: relayConfig }); f.initialize(); f.open(); f.authenticate();
    f.receive(relayRoster);
    f.receive({ type: 'relay', sender: relayPeerId, state: 'open' });
    assert.deepEqual(f.sockets[0].sent.filter(message => message.type === 'relay'), [{ type: 'relay', to: relayPeerId, state: 'open' }]);
    assert.equal(f.elements.get('people').children[0].dataset.connectionState, 'ready');
    assert.equal(f.connections[0].closed, true);
    f.advance(6000);
    assert.equal(f.elements.get('people').children[0].dataset.connectionState, 'ready', 'the abandoned direct deadline changes nothing');
    // A service that offers no relay never leaves the direct route, whatever a message claims.
    const direct = await connectionFixture(); direct.initialize(); direct.open(); direct.authenticate();
    direct.receive(relayRoster); direct.receive({ type: 'relay', sender: relayPeerId, state: 'open' });
    assert.equal(direct.sockets[0].sent.some(message => message.type === 'relay'), false);
    assert.equal(direct.elements.get('people').children[0].dataset.transport, 'direct');
});

test('a relay the other browser never joins settles on a visible retry', async () => {
    const f = await connectionFixture({ config: relayConfig }); f.initialize(); f.open(); f.authenticate();
    f.receive(relayRoster); f.advance(6000); f.advance(10000);
    const row = f.elements.get('people').children[0];
    assert.equal(row.dataset.connectionState, 'failed');
    assert.match(row.children[1].textContent, /Could not connect · Retry/);
    f.receive(relayRoster);
    assert.equal(f.connections.length, 1, 'no automatic attempt follows');
    f.elements.get('people').children[0].onclick();
    assert.equal(f.connections.length, 2, 'retry starts with the direct route again');
    assert.equal(f.elements.get('people').children[0].dataset.transport, 'direct');
});

test('relayed frames carry the transfer protocol to the named peer and a file from an open relay peer only', async () => {
    const f = await connectionFixture({ config: relayConfig }); f.initialize(); f.open(); f.authenticate();
    f.receive(relayRoster); f.advance(6000); f.receive({ type: 'relay', sender: relayPeerId, state: 'open' });
    const socket = f.sockets[0];
    f.elements.get('people').children[0].onclick();
    f.elements.get('message').value = 'Größe ✓';
    f.elements.get('send-text').onclick();
    assert.equal(sentFrames(socket).at(-1).toString('latin1', 0, 36), relayPeerId);
    const text = framedMessages(socket).at(-1);
    assert.equal(text.type, 'text'); assert.equal(Buffer.from(text.text, 'base64').toString(), 'Größe ✓');

    const offer = { type: 'request', totalSize: 3, header: [{ name: 'fixture.bin', mime: 'application/octet-stream', size: 3 }] };
    socket.onmessage({ data: relayFrame({ sender: 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', payload: offer }) });
    socket.onmessage({ data: new Uint8Array(10).buffer });
    assert.equal(f.elements.get('activity').children[0].children[0].textContent, 'Text sent', 'an unknown sender and a short frame are ignored');
    socket.onmessage({ data: relayFrame({ payload: offer }) });
    const request = f.elements.get('activity').children[0];
    assert.equal(request.children[0].textContent, 'Example wants to send files');
    request.children[3].children[0].onclick();
    assert.deepEqual(framedMessages(socket).at(-1), { type: 'files-transfer-response', accepted: true });
    socket.onmessage({ data: relayFrame({ payload: { type: 'header', name: 'fixture.bin', mime: 'application/octet-stream', size: 3 } }) });
    socket.onmessage({ data: relayFrame({ kind: 1, payload: Buffer.from([1, 2, 3]).toString('latin1') }) });
    const received = f.elements.get('activity').children[0];
    assert.equal(received.children[0].textContent, 'Files received');
    assert.equal(received.children.find(child => child.download)?.download, 'fixture.bin');
    assert.ok(framedMessages(socket).some(message => message.type === 'file-transfer-complete'));
    assert.equal(f.messages.filter(message => message.type === 'state').at(-1).activeCount, 0);
});

test('receiver cannot be marked sent by unsolicited completion; partition acknowledgments require live sender', () => {
    const events = [];
    const context = vm.createContext({ window: { addEventListener() {}, dispatchEvent(event) { events.push(event); } },
        document: { documentElement: { dataset: {} } },
        CustomEvent: class { constructor(type, options) { this.type = type; this.detail = options.detail; } },
        fetch: () => new Promise(() => {}), console, setTimeout, clearTimeout });
    vm.runInContext(readFileSync(new URL('../public/vendor/pairdrop-network.js', import.meta.url), 'utf8'), context);
    vm.runInContext(readFileSync(new URL('../public/transfers.js', import.meta.url), 'utf8'), context);
    const peer = vm.runInContext("new StaffPeer({server:{send(){}},caller:false,id:'peer',room:'staff',rtcConfig:{}})", context);
    peer.active = true; peer._busy = true;
    peer._onMessage(JSON.stringify({ type: 'file-transfer-complete' }));
    assert.equal(peer.active, true); assert.equal(peer._busy, true);
    assert.equal(events.some(event => event.type === 'transfer-finished'), false);
    let partitions = 0; peer._sendNextPartition = () => partitions++;
    peer._onMessage(JSON.stringify({ type: 'partition-received' })); assert.equal(partitions, 0);
    peer.sending = true;
    peer._onMessage(JSON.stringify({ type: 'file-transfer-complete' })); assert.equal(peer.active, true);
    peer._chunker = { isFileEnd: () => false };
    peer._onMessage(JSON.stringify({ type: 'file-transfer-complete' })); assert.equal(peer.active, true);
    peer._onMessage(JSON.stringify({ type: 'partition-received' })); assert.equal(partitions, 1);
    peer._chunker = { isFileEnd: () => true };
    peer._onMessage(JSON.stringify({ type: 'file-transfer-complete' }));
    assert.equal(peer.active, false); assert.equal(peer.sending, false);
    assert.equal(events.filter(event => event.type === 'transfer-finished').length, 1);
});

test('individual ICE candidate failure preserves idle and active peers; fatal failure tears down and ignores late errors', () => {
    const events = []; let onDisconnect = () => {};
    class FakeConnection { close() { this.closed = true; } }
    const context = vm.createContext({ window: { addEventListener() {}, dispatchEvent(event) {
        events.push(event); if (event.type === 'peer-disconnected') onDisconnect();
    } }, document: { documentElement: { dataset: {} } }, RTCPeerConnection: FakeConnection,
    CustomEvent: class { constructor(type, options) { this.type = type; this.detail = options.detail; } },
    fetch: () => new Promise(() => {}), console, setTimeout, clearTimeout });
    vm.runInContext(readFileSync(new URL('../public/vendor/pairdrop-network.js', import.meta.url), 'utf8'), context);
    vm.runInContext(readFileSync(new URL('../public/transfers.js', import.meta.url), 'utf8'), context);
    const peer = vm.runInContext("new StaffPeer({server:{send(){}},caller:false,id:'peer',room:'staff',rtcConfig:{}})", context);
    peer._openConnection(); const connection = peer._conn;
    const candidateFailure = connection.onicecandidateerror;
    candidateFailure({ errorCode: 701, url: 'private candidate URL', errorText: 'private diagnostic' });
    assert.equal(events.filter(event => event.type === 'peer-disconnected' || event.type === 'transfer-error').length, 0);
    assert.equal(peer.lastConnectionDiagnostic.source, 'ice-candidate');
    assert.equal(peer.lastConnectionDiagnostic.code, 701);
    assert.deepEqual(Object.keys(peer.lastConnectionDiagnostic).sort(), ['code', 'connection', 'gathering', 'ice', 'localCandidates', 'peerId', 'remoteCandidates', 'signaling', 'source']);
    peer.active = true; peer._busy = true; peer._requestAccepted = { header: ['pending'] };
    candidateFailure({ errorCode: 701 });
    assert.equal(peer.active, true); assert.equal(peer._busy, true); assert.ok(peer._requestAccepted);
    onDisconnect = () => peer.destroy();
    connection.iceConnectionState = 'failed'; connection.oniceconnectionstatechange();
    assert.equal(events.filter(event => event.type === 'peer-disconnected').length, 1);
    assert.equal(peer.active, false); assert.equal(peer._requestAccepted, null); assert.equal(connection.closed, true);
    assert.equal(connection.onicecandidateerror, null); assert.equal(connection.oniceconnectionstatechange, null);
    const count = events.length;
    candidateFailure({ errorCode: 701 }); peer._onError(); peer._onChannelClosed();
    assert.equal(events.length, count);
    const lateChannel = { close() { this.closed = true; } };
    peer._onChannelOpened({ channel: lateChannel });
    assert.equal(lateChannel.closed, true); assert.equal(events.length, count);
});

test('remote candidate rejection is nonfatal, transient disconnect recovers, timeout and SDP rejection remain fatal', async () => {
    const events = []; const timers = new Map(); let nextTimer = 0; let peer;
    class FakeConnection {
        constructor() { this.connectionState = 'connected'; }
        addIceCandidate() { return Promise.reject(new Error('candidate rejected')); }
        setRemoteDescription() { return Promise.reject(new Error('SDP rejected')); }
        close() { this.connectionState = 'closed'; }
    }
    const context = vm.createContext({ window: { addEventListener() {}, dispatchEvent(event) {
        events.push(event); if (event.type === 'peer-disconnected') peer.destroy();
    } }, document: { documentElement: { dataset: {} } }, RTCPeerConnection: FakeConnection,
    RTCIceCandidate: class { constructor(value) { Object.assign(this, value); } },
    CustomEvent: class { constructor(type, options) { this.type = type; this.detail = options.detail; } },
    fetch: () => new Promise(() => {}), console,
    setTimeout: (callback, delay) => { assert.ok([5000, 15000].includes(delay)); timers.set(++nextTimer, callback); return nextTimer; },
    clearTimeout: timer => timers.delete(timer) });
    vm.runInContext(readFileSync(new URL('../public/vendor/pairdrop-network.js', import.meta.url), 'utf8'), context);
    vm.runInContext(readFileSync(new URL('../public/transfers.js', import.meta.url), 'utf8'), context);
    const makePeer = () => vm.runInContext("new StaffPeer({server:{send(){}},caller:false,id:'peer',room:'staff',rtcConfig:{}})", context);
    peer = makePeer(); peer._openConnection();
    peer._onChannelOpened({ channel: { readyState: 'open', close() {} } });
    peer.onServerMessage({ ice: { candidate: 'invalid' } });
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(peer.lastConnectionDiagnostic.source, 'ice-add'); assert.equal(peer._disposed, undefined);
    peer.active = true;
    peer.onServerMessage({ ice: { candidate: 'stale' } });
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(peer.active, true); assert.equal(events.filter(event => event.type === 'peer-disconnected').length, 0);
    peer._conn.connectionState = 'disconnected'; peer._onConnectionStateChange();
    assert.equal(timers.size, 1); assert.equal(peer.active, true);
    peer._conn.connectionState = 'connected'; peer._onConnectionStateChange();
    assert.equal(timers.size, 0); assert.equal(peer.active, true);
    peer._conn.connectionState = 'disconnected'; peer._onConnectionStateChange();
    [...timers.values()][0]();
    assert.equal(peer._disposed, true); assert.equal(peer.active, false);
    assert.equal(peer.lastConnectionDiagnostic.source, 'connection-timeout');
    peer = makePeer(); peer._openConnection(); peer.active = true;
    peer.onServerMessage({ sdp: { type: 'offer', sdp: 'invalid' } });
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(peer._disposed, true); assert.equal(peer.active, false);
    assert.equal(peer.lastConnectionDiagnostic.source, 'sdp');
});

test('asymmetric disposal cannot leave a recreated peer connecting indefinitely', async () => {
    const events = []; const timers = new Map(); let nextTimer = 0; const peers = new Map();
    class FakeConnection {
        createDataChannel() { return { readyState: 'connecting', close() {} }; }
        createOffer() { return Promise.resolve({ type: 'offer', sdp: 'offer' }); }
        setLocalDescription() { return Promise.resolve(); }
        close() { this.closed = true; }
    }
    const context = vm.createContext({ window: { addEventListener() {}, dispatchEvent(event) {
        events.push(event); if (event.type === 'peer-disconnected') peers.get(event.detail)?.destroy();
    } }, document: { documentElement: { dataset: {} } }, RTCPeerConnection: FakeConnection,
    CustomEvent: class { constructor(type, options) { this.type = type; this.detail = options.detail; } },
    fetch: () => new Promise(() => {}), console,
    setTimeout: (callback, delay) => { assert.equal(delay, 15000); timers.set(++nextTimer, callback); return nextTimer; },
    clearTimeout: timer => timers.delete(timer) });
    vm.runInContext(readFileSync(new URL('../public/vendor/pairdrop-network.js', import.meta.url), 'utf8'), context);
    vm.runInContext(readFileSync(new URL('../public/transfers.js', import.meta.url), 'utf8'), context);
    const disposedRemote = vm.runInContext("new StaffPeer({server:{send(){}},caller:false,id:'remote',room:'staff',rtcConfig:{}})", context);
    disposedRemote.destroy();
    // A newly recreated caller sends its one-shot offer while the remote slot is still disposed.
    context.testServer = { send: message => disposedRemote.onServerMessage(message) };
    const caller = vm.runInContext("new StaffPeer({server:testServer,caller:true,id:'caller',room:'staff',rtcConfig:{}})", context);
    peers.set('caller', caller);
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(caller._disposed, undefined); assert.equal(caller._isConnected(), undefined);
    assert.equal(timers.size, 1);
    [...timers.values()][0]();
    assert.equal(caller._disposed, true); assert.equal(caller.lastConnectionDiagnostic.source, 'connecting-timeout');
    assert.equal(events.filter(event => event.type === 'peer-disconnected').length, 1);
    assert.equal(caller._conn.closed, true);
});


test('received text preserves Unicode and whitespace but rejects oversized or malformed payloads', () => {
    const events = [];
    const context = vm.createContext({ window: { addEventListener() {}, dispatchEvent(event) { events.push(event); } },
        document: { documentElement: { dataset: {} } }, atob,
        CustomEvent: class { constructor(type, options) { this.type = type; this.detail = options.detail; } },
        fetch: () => new Promise(() => {}), console, setTimeout, clearTimeout });
    vm.runInContext(readFileSync(new URL('../public/vendor/pairdrop-network.js', import.meta.url), 'utf8'), context);
    vm.runInContext(readFileSync(new URL('../public/transfers.js', import.meta.url), 'utf8'), context);
    const peer = vm.runInContext("new StaffPeer({server:{send(){}},caller:false,id:'peer',room:'staff',rtcConfig:{}})", context);
    peer.sendJSON = () => {};
    const text = ' 空間 🧭\n<em>literal</em>  ';
    peer._onMessage(JSON.stringify({ type: 'text', text: Buffer.from(text).toString('base64') }));
    assert.equal(events.find(event => event.type === 'text-received').detail.text, text);
    peer._onMessage(JSON.stringify({ type: 'text', text: Buffer.from('x'.repeat(10001)).toString('base64') }));
    peer._onMessage(JSON.stringify({ type: 'text', text: '%' }));
    assert.equal(events.filter(event => event.type === 'text-received').length, 1);
    assert.equal(events.filter(event => event.type === 'transfer-cancelled').length, 2);
});

test('the parent origin is chosen only by exact match: ancestorOrigins first, then a hub_parent claim, then a single entry', async () => {
    const { context } = await connectionFixture();
    const resolve = input => vm.runInContext('resolveParentOrigin', context)(input);
    const allowed = ['https://mothership.example.com', 'https://mothership.example.net', 'https://mothership.example.org'];
    assert.equal(resolve({ allowed, ancestor: 'https://mothership.example.net', claimed: 'https://mothership.example.org' }), 'https://mothership.example.net');
    assert.equal(resolve({ allowed, ancestor: 'https://evil.example', claimed: 'https://mothership.example.org' }), null);
    assert.equal(resolve({ allowed, ancestor: null, claimed: 'https://mothership.example.org' }), 'https://mothership.example.org');
    for (const claimed of [null, 'https://mothership.example.org.evil.example', 'https://evil.mothership.example.org', 'https://mothership.example.org/', 'http://mothership.example.org', 'https://mothership.ap']) {
        assert.equal(resolve({ allowed, ancestor: null, claimed }), null, String(claimed));
    }
    assert.equal(resolve({ allowed: ['https://only.example'], ancestor: null, claimed: null }), 'https://only.example');
});

test('without ancestorOrigins or a claim, only ready goes to each allowed parent and the first real parent message locks the origin', async () => {
    const allowed = ['https://mothership.example.com', 'https://mothership.example.net', 'https://mothership.example.org'];
    const f = await connectionFixture({ config: { parentOrigins: allowed, rtcConfig: {} } });
    const parent = f.context.window.parent;
    assert.deepEqual(f.messages.map(m => [m.type, m.targetOrigin]), allowed.map(origin => ['ready', origin]));
    const message = (origin, source, type = 'initialize') => f.context.window.dispatchEvent({ type: 'message', origin, source,
        data: { channel: 'mothership-transfers', version: 1, type, grant: 'fixture' } });
    message('https://evil.example', parent); message('https://mothership.example.org', {});
    assert.equal(f.sockets.length, 0, 'a foreign origin or another window cannot pick the parent');
    message('https://mothership.example.net', parent);
    assert.equal(f.sockets.length, 1);
    message('https://mothership.example.org', parent, 'disconnect');
    f.open(); f.authenticate();
    assert.ok(f.messages.slice(3).length > 0 && f.messages.slice(3).every(m => m.targetOrigin === 'https://mothership.example.net'),
        'after locking, every post goes only to the chosen parent and other allowed origins are ignored');
});

test('startup reads the hub_parent claim, cleans the URL, and the claim only selects among allowed parents', async () => {
    const allowed = ['https://mothership.example.com', 'https://mothership.example.net', 'https://mothership.example.org'];
    const start = async ({ claim, ancestor }) => {
        const query = new URLSearchParams({ keep: 'x' }); if (claim !== undefined) query.set('hub_parent', claim);
        const replaced = [];
        const location = { protocol: 'https:', host: 'transfers.example', href: `https://transfers.example/?${query}#tab`,
            ...(ancestor ? { ancestorOrigins: [ancestor] } : {}) };
        const f = await connectionFixture({ config: { parentOrigins: allowed, rtcConfig: {} }, location,
            history: { state: null, replaceState: (_state, _title, url) => replaced.push(url) } });
        return { f, replaced };
    };
    const claimed = await start({ claim: 'https://mothership.example.net' });
    assert.deepEqual(claimed.replaced, ['/?keep=x#tab'], 'claim removed, other query and hash kept');
    assert.deepEqual(claimed.f.messages.map(m => [m.type, m.targetOrigin]), [['ready', 'https://mothership.example.net']]);
    const winner = await start({ claim: 'https://mothership.example.org', ancestor: 'https://mothership.example.com' });
    assert.deepEqual(winner.f.messages.map(m => m.targetOrigin), ['https://mothership.example.com'], 'ancestorOrigins beats the claim');
    const foreign = await start({ ancestor: 'https://evil.example' });
    assert.equal(foreign.f.messages.length, 0, 'a disallowed ancestor gets nothing, not even ready');
    const lookalike = await start({ claim: 'https://mothership.example.org.evil.example' });
    assert.deepEqual(lookalike.f.messages.map(m => m.targetOrigin), allowed, 'a lookalike claim is ignored; only ready fans out');
    assert.deepEqual(lookalike.replaced, ['/?keep=x#tab']);
});
