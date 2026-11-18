import test from 'node:test';
import assert from 'node:assert/strict';
import { once } from 'node:events';
import { mkdtempSync, rmSync, readFileSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { WebSocket } from 'ws';
import { GrantVerifier } from '../server/grants.js';
import { connectionDiagnostic, log } from '../server/log.js';
import { createTransferService, connectionAddress, parentOriginsFromEnv } from '../server/service.js';
import { freshKey, grant, listen } from './helpers.js';

test('connection diagnostics retain bounded states and counts, never arbitrary client data', () => {
    const input = { source: 'connecting-timeout', code: 701, connection: 'connecting', ice: 'checking',
        signaling: 'stable', gathering: 'complete', localCandidates: 2, remoteCandidates: 3 };
    assert.deepEqual(connectionDiagnostic({ ...input, address: 'private', url: 'private', text: 'private', client: 'spoofed' }), input);
    for (const extra of [{ source: 'private' }, { code: '701' }, { localCandidates: -1 }, { remoteCandidates: 1000 }, { ice: 'private' }]) {
        assert.equal(connectionDiagnostic({ ...input, ...extra }), null);
    }
});

test('grant validates signature, schema, expiry, replay and renewal identity', () => {
    const key = freshKey(); const verifier = new GrantVerifier({ key });
    const signed = grant({ key }); const identity = verifier.verify({ grant: signed });
    assert.equal(identity.sub, 'alice');
    assert.throws(() => verifier.verify({ grant: signed }));
    assert.throws(() => verifier.verify({ grant: signed.slice(0, -3) + 'bad' }));
    const now = Math.floor(Date.now() / 1000);
    for (const claims of [{ exp: now }, { exp: now + 91 }, { iat: now + 6 }, { aud: 'other' }, { sub: '' }, { exp: 'later' }]) {
        assert.throws(() => verifier.verify({ grant: grant({ key, ...claims }) }));
    }
    for (const claims of [{ sub: 'bob' }, { sid: 'other-session' }]) {
        assert.throws(() => verifier.verify({ grant: grant({ key, ...claims }), identity }));
    }
    assert.equal(verifier.verify({ grant: grant({ key }), identity }).sid, identity.sid);
});

test('consumed grants remain rejected after service restart without storing identity or grant', t => {
    const directory = mkdtempSync(join(tmpdir(), 'mothership-transfers-'));
    t.after(() => rmSync(directory, { recursive: true, force: true }));
    const replayFile = join(directory, 'replay.json'); const key = freshKey(); const signed = grant({ key });
    new GrantVerifier({ key, replayFile }).verify({ grant: signed });
    const restarted = new GrantVerifier({ key, replayFile });
    assert.throws(() => restarted.verify({ grant: signed }));
    assert.equal(restarted.verify({ grant: grant({ key }) }).sub, 'alice');
    assert.doesNotMatch(readFileSync(replayFile, 'utf8'), /alice|session-a|mothership-transfers/);
});

test('corrupt or full live replay ledger fails closed rather than discarding replay protection', t => {
    const directory = mkdtempSync(join(tmpdir(), 'mothership-transfers-ledger-'));
    t.after(() => rmSync(directory, { recursive: true, force: true }));
    const replayFile = join(directory, 'replay.json'); const key = freshKey();
    writeFileSync(replayFile, '{invalid');
    assert.throws(() => new GrantVerifier({ key, replayFile }), /replay_ledger_unavailable/);
    const exp = Math.floor(Date.now() / 1000) + 60;
    writeFileSync(replayFile, JSON.stringify(Array.from({ length: 100000 }, (_, index) => [index.toString(16).padStart(64, '0'), exp])));
    const verifier = new GrantVerifier({ key, replayFile });
    assert.throws(() => verifier.verify({ grant: grant({ key }) }));
    assert.equal(JSON.parse(readFileSync(replayFile, 'utf8')).length, 100000);
});

async function setup(t, { maxAuthenticated = 1000 } = {}) {
    const key = freshKey(); const origin = 'http://localhost:9001';
    const service = createTransferService({ key, origin, parentOrigin: 'http://localhost:9000', replayFile: null, maxAuthenticated });
    const url = (await listen(service.server)).replace('http:', 'ws:') + '/ws';
    t.after(() => service.close());
    async function connect({ proof, suppliedOrigin = origin, agent = 'Mozilla/5.0 (Macintosh) Chrome/130.0', connectingIp, browserId } = {}) {
        const headers = { 'User-Agent': agent };
        if (connectingIp !== undefined) headers['CF-Connecting-IP'] = connectingIp;
        const socket = new WebSocket(url, { origin: suppliedOrigin, headers });
        const messages = []; socket.on('message', raw => messages.push(JSON.parse(raw)));
        await once(socket, 'open');
        if (proof) socket.send(JSON.stringify({ type: 'authenticate', grant: proof, browserId }));
        return { socket, messages };
    }
    return { key, connect, url };
}
async function until(fn) {
    const deadline = Date.now() + 3000;
    while (!fn()) { if (Date.now() > deadline) throw new Error('timeout'); await new Promise(resolve => setTimeout(resolve, 10)); }
}
test('authenticated handshake logs are bounded and omit arbitrary data', async t => {
    const directory = mkdtempSync(join(tmpdir(), 'mothership-transfers-diagnostics-'));
    const previous = { logging: process.env.TRANSFERS_LOGGING, directory: process.env.TRANSFERS_STATE_DIR };
    process.env.TRANSFERS_LOGGING = '1'; process.env.TRANSFERS_STATE_DIR = directory;
    t.after(() => {
        if (previous.logging === undefined) delete process.env.TRANSFERS_LOGGING; else process.env.TRANSFERS_LOGGING = previous.logging;
        if (previous.directory === undefined) delete process.env.TRANSFERS_STATE_DIR; else process.env.TRANSFERS_STATE_DIR = previous.directory;
        rmSync(directory, { recursive: true, force: true });
    });
    const { key, connect } = await setup(t);
    const a = await connect({ proof: grant({ key }), browserId: '11111111-1111-4111-8111-111111111111' });
    const b = await connect({ proof: grant({ key, sub: 'bob' }) });
    await until(() => a.messages.some(message => message.peers?.length === 1));
    assert.equal(a.messages.find(message => message.type === 'authenticated').diagnosticsEnabled, true);
    const peerId = a.messages.find(message => message.peers?.length === 1).peers[0].peerId;
    const diagnostic = { type: 'diagnostic', peerId, source: 'connecting-timeout', code: 0, connection: 'connecting',
        ice: 'checking', signaling: 'stable', gathering: 'complete', localCandidates: 2, remoteCandidates: 2,
        address: 'DO_NOT_LOG', url: 'DO_NOT_LOG', sdp: 'DO_NOT_LOG' };
    const selfId = a.messages.find(message => message.type === 'authenticated').peerId;
    for (const extra of [{ peerId: selfId }, { peerId: 'missing' }, { source: 'DO_NOT_LOG' }]) a.socket.send(JSON.stringify({ ...diagnostic, ...extra }));
    for (let n = 0; n < 20; n++) a.socket.send(JSON.stringify({ ...diagnostic, source: 'connected' }));
    for (let n = 0; n < 35; n++) a.socket.send(JSON.stringify(diagnostic));
    a.socket.send(JSON.stringify({ type: 'room-leave' }));
    const rosters = a.messages.filter(message => message.type === 'roster').length;
    await until(() => a.messages.filter(message => message.type === 'roster').length > rosters);
    const contents = readFileSync(join(directory, 'logs/transfers.jsonl'), 'utf8');
    const rows = contents.trim().split('\n').map(line => JSON.parse(line)).filter(row => row.event === 'connection-diagnostic');
    assert.equal(rows.filter(row => row.ctx.source === 'connected').length, 15);
    assert.equal(rows.filter(row => row.ctx.source === 'connecting-timeout').length, 30, 'informational traffic cannot exhaust failure budget');
    assert.equal(rows[0].ctx.remoteCandidates, 2);
    assert.deepEqual(Object.keys(rows[0].ctx).sort(), ['browser', 'client', 'code', 'connection', 'count', 'gathering', 'ice', 'localCandidates', 'peer', 'platform', 'remoteCandidates', 'signaling', 'source']);
    assert.doesNotMatch(contents, /DO_NOT_LOG|alice|bob|11111111/);
    assert.equal(a.socket.readyState, WebSocket.OPEN); assert.equal(b.socket.readyState, WebSocket.OPEN);
    process.env.TRANSFERS_LOGGING = '0';
    a.socket.send(JSON.stringify(diagnostic));
    const before = a.messages.filter(message => message.type === 'roster').length;
    a.socket.send(JSON.stringify({ type: 'room-leave' }));
    await until(() => a.messages.filter(message => message.type === 'roster').length > before);
    assert.equal(readFileSync(join(directory, 'logs/transfers.jsonl'), 'utf8'), contents);
    assert.equal(a.socket.readyState, WebSocket.OPEN);
    process.env.TRANSFERS_LOGGING = '1';
    const path = join(directory, 'logs/transfers.jsonl');
    writeFileSync(path, Buffer.alloc(5 * 1024 * 1024));
    log({ event: 'rotation-proof' });
    assert.equal(JSON.parse(readFileSync(path, 'utf8')).event, 'rotation-proof');
    assert.equal(readFileSync(`${path}.1`).length, 5 * 1024 * 1024);
});
test('unauthenticated clients receive no roster and cannot signal; wrong Origin rejected', async t => {
    const { connect } = await setup(t);
    const { socket, messages } = await connect();
    const closed = once(socket, 'close'); socket.send(JSON.stringify({ type: 'signal', to: 'missing' }));
    assert.equal((await closed)[0], 1006); assert.equal(socket._socket.destroyed, true); assert.deepEqual(messages, []);
    await assert.rejects(connect({ suppliedOrigin: 'http://wrong.example' }));
});
test('roster is signed identity; replay and identity-switch close socket', async t => {
    const { key, connect } = await setup(t);
    const proof = grant({ key }); const a = await connect({ proof });
    await until(() => a.messages.some(message => message.type === 'authenticated'));
    assert.equal(a.messages.find(message => message.type === 'authenticated').name, 'Alex Example');
    const replay = await connect(); const closed = once(replay.socket, 'close');
    replay.socket.send(JSON.stringify({ type: 'authenticate', grant: proof }));
    assert.equal((await closed)[0], 1006);
    const b = await connect({ proof: grant({ key, sub: 'bob', name: 'Blair Example' }) });
    await until(() => a.messages.some(message => message.peers?.length === 1));
    assert.equal(a.messages.find(message => message.peers?.length === 1).peers[0].name, 'Blair Example');
    const before = a.messages.filter(message => message.type === 'authenticated').length;
    a.socket.send(JSON.stringify({ type: 'authenticate', grant: grant({ key }) }));
    await until(() => a.messages.filter(message => message.type === 'authenticated').length === before + 1);
    const changed = once(b.socket, 'close');
    b.socket.send(JSON.stringify({ type: 'authenticate', grant: grant({ key, sub: 'mallory' }) }));
    assert.equal((await changed)[0], 4403);
});
test('every relay verifies membership; room join enables only signed staff', async t => {
    const { key, connect } = await setup(t);
    const a = await connect({ proof: grant({ key }) });
    const b = await connect({ proof: grant({ key, sub: 'bob' }) });
    await until(() => b.messages.some(message => message.type === 'authenticated'));
    const bId = b.messages.find(message => message.type === 'authenticated').peerId;
    a.socket.send(JSON.stringify({ type: 'room-create' }));
    await until(() => a.messages.some(message => message.type === 'room-created'));
    const closed = once(a.socket, 'close');
    a.socket.send(JSON.stringify({ type: 'signal', to: bId, sender: bId, sdp: { type: 'offer', sdp: 'test' } }));
    assert.equal((await closed)[0], 4403);
    assert.equal(b.messages.filter(message => message.type === 'signal').length, 0);
});
test('expired connection closes without new traffic', async t => {
    const { key, connect } = await setup(t);
    const a = await connect(); const closed = once(a.socket, 'close');
    a.socket.send(JSON.stringify({ type: 'authenticate', grant: grant({ key, exp: Math.floor(Date.now() / 1000) + 1 }) }));
    assert.equal((await closed)[0], 4401);
});

test('pending socket cap is separate from authenticated capacity and renewal remains available', async t => {
    const { key, connect } = await setup(t);
    const staff = await connect({ proof: grant({ key }) });
    await until(() => staff.messages.some(message => message.type === 'authenticated'));
    const pending = [];
    for (let index = 0; index < 8; index++) pending.push(await connect());
    await assert.rejects(connect());
    const before = staff.messages.filter(message => message.type === 'authenticated').length;
    staff.socket.send(JSON.stringify({ type: 'authenticate', grant: grant({ key }) }));
    await until(() => staff.messages.filter(message => message.type === 'authenticated').length > before);
    assert.equal(staff.messages.filter(message => message.type === 'authenticated').length, before + 1);
});

test('idle pending sockets are terminated and their capacity becomes reusable', async t => {
    const { connect } = await setup(t);
    const pending = [];
    for (let index = 0; index < 8; index++) pending.push(await connect());
    const closed = pending.map(client => once(client.socket, 'close'));
    await assert.rejects(connect());
    const outcomes = await Promise.all(closed);
    assert.ok(outcomes.every(([code]) => code === 1006));
    assert.ok(pending.every(client => client.socket._socket.destroyed));
    const replacement = await connect();
    assert.equal(replacement.socket.readyState, WebSocket.OPEN);
});

test('pending sockets cannot overrun authenticated capacity at admission', async t => {
    const { key, connect } = await setup(t, { maxAuthenticated: 1 });
    const a = await connect(); const b = await connect();
    a.socket.send(JSON.stringify({ type: 'authenticate', grant: grant({ key }) }));
    await until(() => a.messages.some(message => message.type === 'authenticated'));
    const closed = once(b.socket, 'close');
    b.socket.send(JSON.stringify({ type: 'authenticate', grant: grant({ key, sub: 'bob' }) }));
    assert.equal((await closed)[0], 1006);
    assert.equal(b.socket._socket.destroyed, true);
    assert.equal(b.messages.some(message => message.type === 'authenticated'), false);
    assert.ok(a.messages.every(message => !message.peers?.length));
});

test('only trusted loopback proxy addresses may supply a valid single CF client IP', () => {
    assert.equal(connectionAddress({ remoteAddress: '127.0.0.1', connectingIp: '192.0.2.1' }), '192.0.2.1');
    assert.equal(connectionAddress({ remoteAddress: '::1', connectingIp: '2001:db8::1' }), '2001:db8::1');
    assert.equal(connectionAddress({ remoteAddress: '::ffff:127.0.0.1', connectingIp: '192.0.2.1' }), '192.0.2.1');
    for (const connectingIp of [undefined, 'invalid', '192.0.2.1, 192.0.2.2', ['192.0.2.1']]) {
        assert.equal(connectionAddress({ remoteAddress: '127.0.0.1', connectingIp }), '127.0.0.1');
    }
    assert.equal(connectionAddress({ remoteAddress: '198.51.100.1', connectingIp: '192.0.2.1' }), '198.51.100.1');
});

test('loopback proxy clients have independent pending caps and invalid headers use socket fallback', async t => {
    const { connect } = await setup(t);
    for (let index = 0; index < 8; index++) await connect({ connectingIp: '192.0.2.1' });
    await assert.rejects(connect({ connectingIp: '192.0.2.1' }));
    const distinct = await connect({ connectingIp: '192.0.2.2' });
    assert.equal(distinct.socket.readyState, WebSocket.OPEN);
    for (let index = 0; index < 8; index++) await connect({ connectingIp: 'invalid' });
    await assert.rejects(connect());
});

test('same-user devices receive separate peer IDs and safe distinct device hints', async t => {
    const { key, connect } = await setup(t);
    const a = await connect({ proof: grant({ key }) });
    const b = await connect({ proof: grant({ key }), agent: 'untrusted <script> name' });
    const c = await connect({ proof: grant({ key }) });
    await until(() => a.messages.some(message => message.peers?.length === 2));
    const peers = a.messages.find(message => message.peers?.length === 2).peers;
    assert.equal(peers[0].userId, peers[1].userId);
    assert.notEqual(peers[0].peerId, peers[1].peerId);
    assert.notEqual(peers[0].deviceName, peers[1].deviceName);
    assert.ok(peers.every(peer => /^(Mac · Chrome|Device · Browser) · [a-f0-9]{6}$/.test(peer.deviceName)));
});

test('browser identity hides own tabs, preserves other devices and accounts, and cannot change on renewal', async t => {
    const { key, connect } = await setup(t);
    const browserId = '11111111-1111-4111-8111-111111111111';
    const a = await connect({ proof: grant({ key }), browserId });
    const duplicate = await connect({ proof: grant({ key }), browserId });
    const otherDevice = await connect({ proof: grant({ key }), browserId: '22222222-2222-4222-8222-222222222222' });
    const b = await connect({ proof: grant({ key, sub: 'bob' }), browserId });
    const roster = client => client.messages.filter(message => message.type === 'roster').at(-1);
    await until(() => roster(b)?.peers.length === 3);
    assert.equal(roster(a).peers.length, 2);
    assert.equal(roster(duplicate).peers.length, 2);
    assert.equal(roster(a).peers.filter(peer => peer.userId === 'alice').length, 1);
    const ownTabs = roster(b).peers.filter(peer => peer.browserId === browserId);
    assert.equal(ownTabs.length, 2);
    assert.equal(ownTabs[0].deviceName, ownTabs[1].deviceName);
    assert.notEqual(ownTabs[0].peerId, ownTabs[1].peerId, 'transport endpoints remain independent');
    const before = duplicate.messages.filter(message => message.type === 'authenticated').length;
    duplicate.socket.send(JSON.stringify({ type: 'authenticate', grant: grant({ key }) }));
    await until(() => duplicate.messages.filter(message => message.type === 'authenticated').length > before);
    assert.equal(duplicate.messages.filter(message => message.type === 'authenticated').at(-1).deviceName, ownTabs[1].deviceName);
    assert.equal(roster(b).peers.filter(peer => peer.browserId === browserId).length, 2);
    a.socket.close();
    await until(() => roster(b)?.peers.length === 2);
    assert.equal(roster(b).peers.filter(peer => peer.browserId === browserId).length, 1);
    const closed = once(duplicate.socket, 'close');
    duplicate.socket.send(JSON.stringify({ type: 'authenticate', grant: grant({ key }), browserId: '33333333-3333-4333-8333-333333333333' }));
    assert.equal((await closed)[0], 4403);
    assert.equal(otherDevice.socket.readyState, WebSocket.OPEN);
});

test('invalid browser identity cannot authenticate', async t => {
    const { key, connect } = await setup(t);
    const a = await connect(); const closed = once(a.socket, 'close');
    a.socket.send(JSON.stringify({ type: 'authenticate', grant: grant({ key }), browserId: '<untrusted>' }));
    assert.equal((await closed)[0], 1006);
    assert.equal(a.messages.length, 0);
});

test('fresh renewal cannot revive an already expired socket', async t => {
    const { key, connect } = await setup(t);
    const a = await connect({ proof: grant({ key }) });
    await until(() => a.messages.some(message => message.type === 'authenticated'));
    const future = Date.now() + 61000;
    t.mock.method(Date, 'now', () => future);
    const closed = once(a.socket, 'close');
    a.socket.send(JSON.stringify({ type: 'authenticate', grant: grant({ key }) }));
    assert.equal((await closed)[0], 4403);
});

test('room join attempts are bounded; valid staff can join after cooldown and expired codes fail', async t => {
    const { key, connect } = await setup(t);
    let now = Date.now(); t.mock.method(Date, 'now', () => now);
    const proof = sub => grant({ key, sub, exp: Math.floor(now / 1000) + 90 });
    const a = await connect({ proof: proof('alice') }); const b = await connect({ proof: proof('bob') });
    await until(() => b.messages.some(message => message.type === 'authenticated'));
    a.socket.send(JSON.stringify({ type: 'room-create' }));
    await until(() => a.messages.some(message => message.type === 'room-created'));
    const code = a.messages.find(message => message.type === 'room-created').code;
    for (let index = 0; index < 5; index++) b.socket.send(JSON.stringify({ type: 'room-join', code: 'invalid' }));
    b.socket.send(JSON.stringify({ type: 'room-join', code }));
    await until(() => b.messages.filter(message => message.type === 'error').length === 6);
    now += 61000;
    b.socket.send(JSON.stringify({ type: 'room-join', code }));
    await until(() => b.messages.at(-1)?.type === 'roster' && b.messages.at(-1)?.room !== 'staff');
    b.socket.send(JSON.stringify({ type: 'room-leave' }));
    await until(() => b.messages.at(-1)?.room === 'staff');
    // Keep both signed sessions live while advancing past the room's five-minute lifetime.
    for (let index = 0; index < 6; index++) {
        for (const [client, sub] of [[a, 'alice'], [b, 'bob']]) {
            const count = client.messages.filter(message => message.type === 'authenticated').length;
            client.socket.send(JSON.stringify({ type: 'authenticate', grant: proof(sub) }));
            await until(() => client.messages.filter(message => message.type === 'authenticated').length > count);
        }
        now += 45000;
    }
    const errors = b.messages.filter(message => message.type === 'error').length;
    b.socket.send(JSON.stringify({ type: 'room-join', code }));
    await until(() => b.messages.filter(message => message.type === 'error').length > errors);
    assert.equal(b.messages.at(-1).code, 'room_unavailable');
});

test('parent origins come from the comma-separated list, falling back to the single variable', () => {
    assert.deepEqual(parentOriginsFromEnv({ TRANSFERS_PARENT_ORIGINS: ' https://a.example, https://b.example ,',
        TRANSFERS_PARENT_ORIGIN: 'https://old.example' }), ['https://a.example', 'https://b.example']);
    assert.deepEqual(parentOriginsFromEnv({ TRANSFERS_PARENT_ORIGINS: ' , ', TRANSFERS_PARENT_ORIGIN: 'https://old.example' }), ['https://old.example']);
    assert.deepEqual(parentOriginsFromEnv({ TRANSFERS_PARENT_ORIGIN: 'https://old.example' }), ['https://old.example']);
});

test('every allowed parent is a frame ancestor and in /config; non-origin entries are refused', async t => {
    const parents = ['https://mothership.example.com', 'https://mothership.example.net', 'https://mothership.example.org'];
    const service = createTransferService({ key: freshKey(), origin: 'http://localhost:9001', parentOrigins: parents, replayFile: null });
    const base = await listen(service.server);
    t.after(() => service.close());
    const response = await fetch(`${base}/config`);
    assert.match(response.headers.get('content-security-policy'), new RegExp(`frame-ancestors ${parents.join(' ')};`));
    assert.deepEqual((await response.json()).parentOrigins, parents);
    for (const bad of [[], ['https://mothership.example.org/'], ['https://mothership.example.org/path'], ['mothership.example.org'], ['ftp://mothership.example.org'], [undefined]]) {
        assert.throws(() => createTransferService({ key: freshKey(), origin: 'http://localhost:9001', parentOrigins: bad, replayFile: null }), /invalid_origin/);
    }
});
