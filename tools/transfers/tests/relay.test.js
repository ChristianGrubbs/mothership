import test from 'node:test';
import assert from 'node:assert/strict';
import { once } from 'node:events';
import { mkdtempSync, rmSync, readFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { randomBytes } from 'node:crypto';
import { WebSocket } from 'ws';
import { createTransferService } from '../server/service.js';
import { freshKey, grant, listen } from './helpers.js';

async function until(fn, { timeout = 3000 } = {}) {
    const deadline = Date.now() + timeout;
    while (!fn()) { if (Date.now() > deadline) throw new Error('timeout'); await new Promise(resolve => setTimeout(resolve, 10)); }
}
async function setup(t, options = {}) {
    const key = freshKey(); const origin = 'http://localhost:9001';
    const service = createTransferService({ key, origin, parentOrigin: 'http://localhost:9000', replayFile: null, ...options });
    const base = await listen(service.server);
    t.after(() => service.close());
    async function connect({ sub, authenticate = true, claims = {} } = {}) {
        const socket = new WebSocket(base.replace('http:', 'ws:') + '/ws', { origin });
        const peer = { socket, messages: [], frames: [], id: null, closed: null };
        socket.on('message', (raw, binary) => { if (binary) peer.frames.push(raw); else peer.messages.push(JSON.parse(raw)); });
        socket.on('close', code => { peer.closed = code; });
        socket.on('error', () => {});
        await once(socket, 'open');
        if (authenticate) {
            socket.send(JSON.stringify({ type: 'authenticate', grant: grant({ key, sub, ...claims }) }));
            await until(() => peer.messages.some(message => message.type === 'authenticated'));
            peer.id = peer.messages.find(message => message.type === 'authenticated').peerId;
        }
        return peer;
    }
    return { base, connect };
}
const frame = ({ to, kind = 1, payload = Buffer.from('payload') }) => Buffer.concat([Buffer.from(to.padEnd(36, '0'), 'latin1'), Buffer.from([kind]), payload]);
const open = ({ from, to }) => from.socket.send(JSON.stringify({ type: 'relay', to: to.id, state: 'open' }));
// One socket's messages are handled in order, so a delivered signal proves every earlier frame from it was handled.
async function settled({ from, to }) {
    const marker = randomBytes(8).toString('hex');
    from.socket.send(JSON.stringify({ type: 'signal', to: to.id, sdp: { marker } }));
    await until(() => to.messages.some(message => message.type === 'signal' && message.sdp?.marker === marker));
}

test('the relay is off unless the service is configured with it', async t => {
    const { base, connect } = await setup(t);
    assert.equal((await (await fetch(`${base}/config`)).json()).relay, null);
    const a = await connect({ sub: 'alice' }); const b = await connect({ sub: 'bob' }); const c = await connect({ sub: 'carol' });
    a.socket.send(frame({ to: b.id }));
    await until(() => a.closed !== null);
    c.socket.send(JSON.stringify({ type: 'relay', to: b.id, state: 'open' }));
    await until(() => c.closed !== null);
    assert.equal(b.frames.length, 0);
    assert.equal(b.messages.some(message => message.type === 'relay'), false);
});

test('relay options are validated at startup', () => {
    const base = { key: freshKey(), origin: 'http://localhost:9001', parentOrigin: 'http://localhost:9000', replayFile: null };
    for (const options of [{ relay: 'yes' }, { relay: true, relayAfterMs: 50 }, { relay: true, relayAfterMs: 20000 }, { relay: true, relayAfterMs: Number.NaN },
        { relay: true, relayPauseBytes: 0 }, { relay: true, relayPauseBytes: 4096, relayLimitBytes: 4096 }, { relay: true, relayLimitBytes: 64 * 1024 * 1024 }]) {
        assert.throws(() => createTransferService({ ...base, ...options }), /invalid_relay/);
    }
});

test('a frame is delivered only between two visible peers that both opened the relay, with the sender stamped by the service', async t => {
    const { base, connect } = await setup(t, { relay: true, relayAfterMs: 500 });
    assert.deepEqual((await (await fetch(`${base}/config`)).json()).relay, { afterMs: 500 });
    const a = await connect({ sub: 'alice' }); const b = await connect({ sub: 'bob' }); const c = await connect({ sub: 'carol' });

    a.socket.send(frame({ to: b.id }));
    open({ from: a, to: b });
    await until(() => b.messages.some(message => message.type === 'relay'));
    assert.deepEqual(b.messages.find(message => message.type === 'relay'), { type: 'relay', sender: a.id, state: 'open' });
    a.socket.send(frame({ to: b.id })); b.socket.send(frame({ to: a.id }));
    await settled({ from: a, to: b }); await settled({ from: b, to: a });
    assert.equal(a.frames.length + b.frames.length, 0, 'a one-sided request delivers nothing in either direction');

    open({ from: b, to: a });
    await until(() => a.messages.some(message => message.type === 'relay'));
    const chunk = randomBytes(64000); const text = Buffer.from(JSON.stringify({ type: 'header', name: 'ünïcode.bin', size: 64000 }));
    a.socket.send(frame({ to: b.id, kind: 0, payload: text })); a.socket.send(frame({ to: b.id, kind: 1, payload: chunk }));
    await until(() => b.frames.length === 2);
    for (const [index, kind, payload] of [[0, 0, text], [1, 1, chunk]]) {
        assert.equal(b.frames[index].toString('latin1', 0, 36), a.id, 'the recipient sees the sending socket, not what the sender wrote');
        assert.equal(b.frames[index][36], kind);
        assert.ok(b.frames[index].subarray(37).equals(payload));
    }
    b.socket.send(frame({ to: a.id, payload: Buffer.from('reply') }));
    await until(() => a.frames.length === 1);
    assert.equal(a.frames[0].toString('latin1', 0, 36), b.id);

    // A third signed-in device in the same room, the sender itself and an unknown id receive nothing.
    c.socket.send(frame({ to: b.id })); a.socket.send(frame({ to: c.id })); a.socket.send(frame({ to: a.id })); a.socket.send(frame({ to: 'missing' }));
    open({ from: c, to: b });
    c.socket.send(frame({ to: b.id }));
    await settled({ from: c, to: b }); await settled({ from: a, to: b });
    assert.equal(b.frames.length, 2); assert.equal(c.frames.length, 0); assert.equal(a.frames.length, 1);
    assert.equal(a.closed, null); assert.equal(c.closed, null);
});

test('a browser that asks after the other side learns at once that the relay is open', async t => {
    const { connect } = await setup(t, { relay: true });
    const a = await connect({ sub: 'alice' }); const b = await connect({ sub: 'bob' });
    const answers = peer => peer.messages.filter(message => message.type === 'relay');
    open({ from: a, to: b });
    await settled({ from: a, to: b }); await settled({ from: b, to: a });
    assert.equal(answers(a).length, 0, 'the first to ask hears nothing until the other side asks');
    open({ from: b, to: a });
    await settled({ from: b, to: a });
    // The answer to B travels on B's own socket, so the signal A received says nothing about it yet.
    await until(() => answers(b).length === 2);
    assert.deepEqual(answers(b), [{ type: 'relay', sender: a.id, state: 'open' }, { type: 'relay', sender: a.id, state: 'open' }]);
    assert.deepEqual(answers(a), [{ type: 'relay', sender: b.id, state: 'open' }]);
    // A browser that replaced its peer asks again and is answered, with no reply needed from the other browser.
    open({ from: a, to: b });
    await until(() => answers(a).length === 2);
    assert.deepEqual(answers(a)[1], { type: 'relay', sender: b.id, state: 'open' });
});

test('leaving the shared room ends the relay for both sides; a request that changes no room does not', async t => {
    const { connect } = await setup(t, { relay: true });
    const a = await connect({ sub: 'alice' }); const b = await connect({ sub: 'bob' });
    open({ from: a, to: b }); open({ from: b, to: a });
    await until(() => a.messages.some(message => message.type === 'relay') && b.messages.some(message => message.type === 'relay'));
    // Leave room is offered in the staff room too. Both browsers keep their peers, so the relay must keep working.
    a.socket.send(JSON.stringify({ type: 'room-leave' }));
    a.socket.send(frame({ to: b.id, payload: Buffer.from('still here') }));
    await until(() => b.frames.length === 1);
    b.frames.length = 0;
    a.socket.send(JSON.stringify({ type: 'room-create' }));
    await until(() => a.messages.some(message => message.type === 'room-created'));
    a.socket.send(frame({ to: b.id })); b.socket.send(frame({ to: a.id }));
    a.socket.send(JSON.stringify({ type: 'room-leave' }));
    // Back in the same room, the earlier request no longer counts: both browsers must ask again.
    a.socket.send(frame({ to: b.id })); b.socket.send(frame({ to: a.id }));
    await settled({ from: a, to: b }); await settled({ from: b, to: a });
    assert.equal(a.frames.length + b.frames.length, 0);
    open({ from: a, to: b }); open({ from: b, to: a });
    await settled({ from: a, to: b }); await settled({ from: b, to: a });
    a.socket.send(frame({ to: b.id }));
    await until(() => b.frames.length === 1);
    // Both move to the private room and ask again there; rejoining the room a browser is already in changes nothing.
    const join = JSON.stringify({ type: 'room-join', code: a.messages.find(message => message.type === 'room-created').code });
    a.socket.send(join); b.socket.send(join);
    await until(() => a.messages.at(-1).type === 'roster' && a.messages.at(-1).room !== 'staff' && a.messages.at(-1).peers.length === 1);
    open({ from: a, to: b }); open({ from: b, to: a });
    await settled({ from: a, to: b }); await settled({ from: b, to: a });
    a.socket.send(join);
    a.socket.send(frame({ to: b.id, payload: Buffer.from('same room') }));
    await until(() => b.frames.length === 2);
    assert.equal(b.frames[1].subarray(37).toString(), 'same room');
});

test('malformed, oversize and unauthenticated frames end the session and deliver nothing', async t => {
    const { connect } = await setup(t, { relay: true });
    const b = await connect({ sub: 'bob' });
    const attempts = [
        { name: 'unauthenticated', authenticate: false, data: () => frame({ to: b.id }) },
        { name: 'short', data: () => Buffer.alloc(36, 0x61) },
        { name: 'unknown kind', data: () => frame({ to: b.id, kind: 2 }) },
        { name: 'oversize frame', data: () => frame({ to: b.id, payload: Buffer.alloc(196609) }) },
        { name: 'oversize text', data: () => JSON.stringify({ type: 'signal', to: b.id, sdp: 'x'.repeat(70000) }) }
    ];
    for (const { name, authenticate = true, data } of attempts) {
        const a = await connect({ sub: 'alice', authenticate });
        if (authenticate) { open({ from: a, to: b }); open({ from: b, to: a }); await settled({ from: a, to: b }); }
        a.socket.send(data());
        await until(() => a.closed !== null);
        assert.notEqual(a.closed, 1000, name);
    }
    assert.equal(b.frames.length, 0);
    assert.equal(b.messages.some(message => message.type === 'signal' && typeof message.sdp === 'string'), false);
    assert.equal(b.closed, null);
});

test('a burst above the per-second budget is slowed, not lost', async t => {
    const { connect } = await setup(t, { relay: true });
    const a = await connect({ sub: 'alice' }); const b = await connect({ sub: 'bob' });
    open({ from: a, to: b }); open({ from: b, to: a });
    await settled({ from: a, to: b }); await settled({ from: b, to: a });
    for (let index = 0; index < 2500; index++) a.socket.send(frame({ to: b.id, payload: Buffer.from(String(index)) }));
    await until(() => b.frames.length === 2500, { timeout: 8000 });
    assert.deepEqual(b.frames.map(raw => Number(raw.subarray(37).toString())), Array.from({ length: 2500 }, (_, index) => index));
    // The paused sender is read again on the service's next one-second tick.
    a.socket.send(frame({ to: b.id, payload: Buffer.from('after') }));
    await until(() => b.frames.length === 2501);
    assert.equal(a.closed, null);
});

// 300 frames of 190,000 bytes (57 MB) is more than the loopback socket buffers can absorb, so what the service
// does not read has to wait in the sender.
const flood = ({ from, to, count = 300 }) => {
    for (let index = 0; index < count; index++) {
        const payload = Buffer.alloc(190000, 7); payload.writeUInt32BE(index, 0);
        from.socket.send(frame({ to: to.id, payload }));
    }
    return count;
};

test('a recipient that stops reading slows its sender instead of filling the service, and nothing is lost', { timeout: 60000 }, async t => {
    const { connect } = await setup(t, { relay: true, relayPauseBytes: 1024 * 1024 });
    const a = await connect({ sub: 'alice' }); const b = await connect({ sub: 'bob' });
    open({ from: a, to: b }); open({ from: b, to: a });
    await settled({ from: a, to: b }); await settled({ from: b, to: a });
    b.socket.pause();
    const count = flood({ from: a, to: b });
    await new Promise(resolve => setTimeout(resolve, 2500));
    assert.equal(a.closed, null, 'a paused sender is not ended');
    assert.ok(a.socket.bufferedAmount > 0, 'the unread bytes wait in the sender, not in the service');
    b.socket.resume();
    await until(() => b.frames.length === count, { timeout: 40000 });
    assert.deepEqual(b.frames.map(raw => raw.readUInt32BE(37)), Array.from({ length: count }, (_, index) => index));
    assert.ok(b.frames.every(raw => raw.length === 37 + 190000));
    assert.equal(a.closed, null);
});

test('a sender that keeps pushing at a stalled recipient is ended at the backlog limit; the recipient stays', { timeout: 60000 }, async t => {
    const { connect } = await setup(t, { relay: true, relayPauseBytes: 1024 * 1024, relayLimitBytes: 1536 * 1024 });
    const a = await connect({ sub: 'alice' }); const b = await connect({ sub: 'bob' });
    open({ from: a, to: b }); open({ from: b, to: a });
    await settled({ from: a, to: b }); await settled({ from: b, to: a });
    b.socket.pause();
    const count = flood({ from: a, to: b });
    await until(() => a.closed !== null, { timeout: 30000 });
    assert.equal(a.closed, 4403);
    b.socket.resume();
    await until(() => b.messages.some(message => message.type === 'roster' && message.peers.length === 0));
    assert.ok(b.frames.length < count, 'the service held only a bounded part of what was pushed');
    assert.equal(b.closed, null);
});

test('an expired session neither sends nor receives relay frames', { timeout: 20000 }, async t => {
    const { connect } = await setup(t, { relay: true });
    const expires = Math.floor(Date.now() / 1000) + 3;
    const a = await connect({ sub: 'alice' }); const b = await connect({ sub: 'bob', claims: { exp: expires } });
    open({ from: a, to: b }); open({ from: b, to: a });
    await settled({ from: a, to: b }); await settled({ from: b, to: a });
    a.socket.send(frame({ to: b.id }));
    await until(() => b.frames.length === 1);
    await until(() => Math.floor(Date.now() / 1000) >= expires, { timeout: 6000 });
    a.socket.send(frame({ to: b.id, payload: Buffer.from('late') }));
    b.socket.send(frame({ to: a.id, payload: Buffer.from('late') }));
    await until(() => b.closed !== null, { timeout: 6000 });
    assert.ok([4401, 4403].includes(b.closed));
    // A request that changes nothing still answers with a roster, proving the sender's earlier frame was handled.
    const rosters = a.messages.filter(message => message.type === 'roster').length;
    a.socket.send(JSON.stringify({ type: 'room-leave' }));
    await until(() => a.messages.filter(message => message.type === 'roster').length > rosters);
    assert.equal(b.frames.length, 1); assert.equal(a.frames.length, 0); assert.equal(a.closed, null);
});

test('the relay log holds ids and counters and never a payload', async t => {
    const directory = mkdtempSync(join(tmpdir(), 'mothership-transfers-relay-'));
    const previous = { logging: process.env.TRANSFERS_LOGGING, directory: process.env.TRANSFERS_STATE_DIR };
    process.env.TRANSFERS_LOGGING = '1'; process.env.TRANSFERS_STATE_DIR = directory;
    t.after(() => {
        if (previous.logging === undefined) delete process.env.TRANSFERS_LOGGING; else process.env.TRANSFERS_LOGGING = previous.logging;
        if (previous.directory === undefined) delete process.env.TRANSFERS_STATE_DIR; else process.env.TRANSFERS_STATE_DIR = previous.directory;
        rmSync(directory, { recursive: true, force: true });
    });
    const { connect } = await setup(t, { relay: true });
    const a = await connect({ sub: 'alice' }); const b = await connect({ sub: 'bob' });
    open({ from: a, to: b }); open({ from: b, to: a });
    await settled({ from: a, to: b }); await settled({ from: b, to: a });
    const payload = Buffer.from('DO_NOT_LOG'.repeat(100));
    a.socket.send(frame({ to: b.id, payload }));
    await until(() => b.frames.length === 1);
    a.socket.close();
    const rows = () => readFileSync(join(directory, 'logs/transfers.jsonl'), 'utf8').trim().split('\n').map(line => JSON.parse(line));
    await until(() => rows().some(row => row.event === 'relay-closed'));
    const opened = rows().find(row => row.event === 'relay-opened');
    assert.deepEqual([opened.ctx.client, opened.ctx.peer], [b.id, a.id]);
    const closed = rows().find(row => row.event === 'relay-closed');
    assert.deepEqual({ client: closed.ctx.client, bytes: closed.ctx.bytes, frames: closed.ctx.frames }, { client: a.id, bytes: payload.length, frames: 1 });
    assert.doesNotMatch(readFileSync(join(directory, 'logs/transfers.jsonl'), 'utf8'), /DO_NOT_LOG|payload/);
});
