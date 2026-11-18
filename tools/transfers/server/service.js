import http from 'node:http';
import { randomUUID, randomInt } from 'node:crypto';
import { fileURLToPath } from 'node:url';
import express from 'express';
import { WebSocketServer, WebSocket } from 'ws';
import { GrantVerifier } from './grants.js';
import { log, connectionDiagnostic } from './log.js';
import { replayPath } from './state.js';
import { isIP } from 'node:net';

export function connectionAddress({ remoteAddress, connectingIp }) {
    const trustedLoopback = ['127.0.0.1', '::1', '::ffff:127.0.0.1'].includes(remoteAddress);
    return trustedLoopback && typeof connectingIp === 'string' && isIP(connectingIp)
        ? connectingIp : remoteAddress;
}

// TRANSFERS_PARENT_ORIGINS is a comma-separated list of exact The Mothership origins; the older single
// TRANSFERS_PARENT_ORIGIN is used only when the list is unset or empty.
export function parentOriginsFromEnv(env) {
    const list = String(env.TRANSFERS_PARENT_ORIGINS ?? '').split(',').map(value => value.trim()).filter(Boolean);
    return list.length ? list : [env.TRANSFERS_PARENT_ORIGIN];
}

// Relay frame: 36 ASCII bytes naming the other peer, one kind byte (0 = protocol text, 1 = file chunk), then the
// payload. The largest payload is one 65,536-character protocol message in UTF-8; a file chunk is 64,000 bytes.
const RELAY_HEADER_BYTES = 37;
const RELAY_PAYLOAD_BYTES = 196608;
const RELAY_FRAMES_PER_SECOND = 2000;
const RELAY_PAUSE_BYTES = 4 * 1024 * 1024;
const RELAY_LIMIT_BYTES = 8 * 1024 * 1024;

export function createTransferService({ key, origin, parentOrigin, parentOrigins = [parentOrigin], rtcConfig = { iceServers: [] },
    replayFile = replayPath(), maxAuthenticated = 1000, relay = false, relayAfterMs = 6000,
    relayPauseBytes = RELAY_PAUSE_BYTES, relayLimitBytes = RELAY_LIMIT_BYTES }) {
    if (!Number.isInteger(maxAuthenticated) || maxAuthenticated < 1 || maxAuthenticated > 1000) throw new Error('invalid_capacity');
    if (typeof relay !== 'boolean' || !Number.isInteger(relayAfterMs) || relayAfterMs < 100 || relayAfterMs > 15000
        || !Number.isInteger(relayPauseBytes) || !Number.isInteger(relayLimitBytes) || relayPauseBytes < 1
        || relayLimitBytes <= relayPauseBytes || relayLimitBytes > RELAY_LIMIT_BYTES) throw new Error('invalid_relay');
    if (!Array.isArray(parentOrigins) || parentOrigins.length < 1 || parentOrigins.length > 16) throw new Error('invalid_origin');
    // Each entry must already be a bare origin (scheme://host[:port], no path or slash), so matching stays exact.
    for (const value of [origin, ...parentOrigins]) {
        if (typeof value !== 'string' || !URL.canParse(value) || new URL(value).origin !== value || !/^https?:/.test(value)) throw new Error('invalid_origin');
    }
    const parents = [...new Set(parentOrigins)];
    const verifier = new GrantVerifier({ key, replayFile });
    const clients = new Map();
    const rooms = new Map();
    const app = express();
    app.disable('x-powered-by');
    app.use((_req, res, next) => {
        res.set({ 'Content-Security-Policy': `default-src 'self'; script-src 'self'; style-src 'self'; connect-src 'self'; img-src 'self' blob: data:; frame-ancestors ${parents.join(' ')}; object-src 'none'; base-uri 'none'`,
            'Referrer-Policy': 'no-referrer', 'X-Content-Type-Options': 'nosniff', 'Cache-Control': 'no-store' });
        next();
    });
    app.get('/config', (_req, res) => res.json({ parentOrigins: parents, rtcConfig, relay: relay ? { afterMs: relayAfterMs } : null }));
    app.get('/health', (_req, res) => res.json({ ok: true }));
    app.use(express.static(fileURLToPath(new URL('../public/', import.meta.url))));
    const server = http.createServer(app);
    const wss = new WebSocketServer({ noServer: true, maxPayload: relay ? RELAY_HEADER_BYTES + RELAY_PAYLOAD_BYTES : 65536, perMessageDeflate: false });
    const send = (socket, message) => {
        if (socket.readyState === WebSocket.OPEN) socket.send(JSON.stringify(message));
    };
    const live = client => client?.identity && client.identity.exp > Math.floor(Date.now() / 1000);
    const visible = (a, b) => live(a) && live(b) && a.room === b.room;
    const sameBrowser = (a, b) => a.browserId && a.browserId === b.browserId && a.identity.sub === b.identity.sub;
    const roster = () => {
        for (const client of clients.values()) {
            if (!live(client)) continue;
            send(client.socket, { type: 'roster', selfId: client.id, room: client.room,
                peers: [...clients.values()].filter(other => other !== client && visible(client, other) && !sameBrowser(client, other))
                    .map(other => ({ peerId: other.id, userId: other.identity.sub, name: other.identity.name,
                        browserId: other.browserId, deviceName: other.deviceName })) });
        }
    };
    // A relay pair lasts while both sockets stay in one room. Leaving the room or the service ends it for both sides.
    const closeRelays = client => {
        if (!relay) return;
        for (const other of clients.values()) other.relays.delete(client.id);
        if (!client.relays.size && !client.relayFrames) return;
        client.relays.clear();
        log({ event: 'relay-closed', detail: { client: client.id, bytes: client.relayBytes, frames: client.relayFrames } });
        client.relayBytes = 0; client.relayFrames = 0;
    };
    // Browsers keep their peers for a room they are already in, so a request that changes nothing must not end a relay.
    const moveRoom = ({ client, room }) => {
        if (client.room === room) return;
        client.room = room;
        closeRelays(client);
    };
    // File bytes pass through this process in memory only: nothing here writes a payload to disk or to the log.
    const relayFrame = ({ client, raw }) => {
        if (!relay || !live(client) || raw.length < RELAY_HEADER_BYTES || raw[36] > 1) throw new Error('invalid_message');
        // Slow a sender to the service's pace, and below to the recipient's, instead of holding its bytes here.
        if (++client.relayBurst > RELAY_FRAMES_PER_SECOND) client.socket.pause();
        const recipient = clients.get(raw.toString('latin1', 0, 36));
        // A frame can cross a roster change. Without both sides' open relay it is dropped, never delivered.
        if (!recipient || recipient === client || !visible(client, recipient)
            || !client.relays.has(recipient.id) || !recipient.relays.has(client.id)
            || recipient.socket.readyState !== WebSocket.OPEN) return;
        if (recipient.socket.bufferedAmount > relayLimitBytes) throw new Error('relay_overflow');
        // The recipient learns the sender from this socket's identity, never from the frame the sender wrote.
        raw.write(client.id, 0, 36, 'latin1');
        recipient.socket.send(raw, { binary: true });
        client.relayBytes += raw.length - RELAY_HEADER_BYTES; client.relayFrames++;
        if (recipient.socket.bufferedAmount > relayPauseBytes) client.socket.pause();
    };
    const remove = client => {
        if (!clients.delete(client.id)) return;
        for (const [code, room] of rooms) if (room.owner === client.id) rooms.delete(code);
        closeRelays(client);
        roster();
        log({ event: 'disconnected', count: clients.size });
    };
    server.on('upgrade', (req, socket, head) => {
        req.transferAddress = connectionAddress({ remoteAddress: req.socket.remoteAddress, connectingIp: req.headers['cf-connecting-ip'] });
        const pending = [...clients.values()].filter(client => !client.identity);
        if (req.url !== '/ws' || req.headers.origin !== origin || clients.size - pending.length >= maxAuthenticated
            || pending.length >= 128 || pending.filter(client => client.address === req.transferAddress).length >= 8) {
            socket.end('HTTP/1.1 403 Forbidden\r\nConnection: close\r\n\r\n');
            return;
        }
        wss.handleUpgrade(req, socket, head, ws => wss.emit('connection', ws, req));
    });
    wss.on('connection', (socket, request) => {
        const id = randomUUID();
        const agent = String(request.headers['user-agent'] || '').slice(0, 1000);
        const platform = /iPhone|iPad/.test(agent) ? 'iOS' : /Android/.test(agent) ? 'Android' : /Windows/.test(agent) ? 'Windows' : /Macintosh/.test(agent) ? 'Mac' : /Linux/.test(agent) ? 'Linux' : 'Device';
        const browser = /Edg\//.test(agent) ? 'Edge' : /Firefox\//.test(agent) ? 'Firefox' : /Chrome\//.test(agent) ? 'Chrome' : /Safari\//.test(agent) ? 'Safari' : 'Browser';
        const client = { id, socket, address: request.transferAddress, identity: null, browserId: null, room: 'staff',
            deviceName: `${platform} · ${browser} · ${id.slice(0, 6)}`, frames: 0,
            relays: new Set(), relayBurst: 0, relayBytes: 0, relayFrames: 0,
            diagnosticInfo: 0, diagnosticFailures: 0, diagnosticReset: Date.now() + 60000,
            roomAttempts: 0, roomAttemptReset: Date.now() + 60000 };
        clients.set(client.id, client);
        const authTimeout = setTimeout(() => { remove(client); socket.terminate(); }, 2000);
        socket.on('close', () => { clearTimeout(authTimeout); remove(client); });
        socket.on('error', () => socket.close());
        socket.on('message', (raw, binary) => {
            if (clients.get(client.id) !== client) return;
            try {
                if (binary) { relayFrame({ client, raw }); return; }
                if (++client.frames > 300 || raw.length > 65536 || (!client.identity && raw.length > 8192)) throw new Error('invalid_message');
                const message = JSON.parse(raw.toString());
                if (message.type === 'authenticate') {
                    // An expired socket cannot be revived by a late renewal.
                    if (client.identity && !live(client)) throw new Error('expired');
                    if (!client.identity && [...clients.values()].filter(other => other.identity).length >= maxAuthenticated) throw new Error('capacity');
                    if (message.browserId !== undefined && (typeof message.browserId !== 'string'
                        || !/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/.test(message.browserId))) throw new Error('invalid_browser');
                    if (client.identity && message.browserId !== undefined && message.browserId !== client.browserId) throw new Error('browser_changed');
                    const identity = verifier.verify({ grant: message.grant, identity: client.identity });
                    if (!client.identity) {
                        client.browserId = message.browserId || null;
                        client.deviceName = `${platform} · ${browser} · ${(client.browserId || id).slice(0, 6)}`;
                    }
                    client.identity = identity;
                    clearTimeout(authTimeout);
                    send(socket, { type: 'authenticated', peerId: client.id, name: client.identity.name, deviceName: client.deviceName, expiresAt: client.identity.exp,
                        diagnosticsEnabled: process.env.TRANSFERS_LOGGING === '1' });
                    roster();
                    log({ event: 'authenticated', count: clients.size });
                    return;
                }
                if (!live(client)) throw new Error('authentication_required');
                if (message.type === 'signal') {
                    const recipient = clients.get(message.to);
                    if (!visible(client, recipient) || recipient === client) throw new Error('membership_required');
                    // Do not forward attacker-controlled sender, name, room or arbitrary message kinds.
                    send(recipient.socket, { type: 'signal', sender: client.id, sdp: message.sdp, ice: message.ice });
                } else if (message.type === 'relay' && relay) {
                    if (message.state !== 'open') throw new Error('invalid_message');
                    const recipient = clients.get(message.to);
                    // The other device can leave while this request is on its way; that is not a protocol error.
                    if (!visible(client, recipient) || recipient === client) return;
                    const opened = !client.relays.has(recipient.id);
                    client.relays.add(recipient.id);
                    send(recipient.socket, { type: 'relay', sender: client.id, state: 'open' });
                    if (!recipient.relays.has(client.id)) return;
                    // This service is the record of who asked: a browser that asks, or asks again after replacing
                    // its peer, learns at once that the other side already has, so neither waits on a lost reply.
                    send(socket, { type: 'relay', sender: recipient.id, state: 'open' });
                    if (opened) log({ event: 'relay-opened', detail: { client: client.id, peer: recipient.id } });
                } else if (message.type === 'diagnostic') {
                    if (process.env.TRANSFERS_LOGGING !== '1') return;
                    const recipient = clients.get(message.peerId);
                    if (!visible(client, recipient) || recipient === client) return;
                    const diagnostic = connectionDiagnostic(message);
                    if (!diagnostic) return;
                    if (Date.now() >= client.diagnosticReset) { client.diagnosticInfo = 0; client.diagnosticFailures = 0; client.diagnosticReset = Date.now() + 60000; }
                    const informational = ['connected', 'ice-candidate', 'ice-add'].includes(diagnostic.source);
                    if (informational ? ++client.diagnosticInfo > 15 : ++client.diagnosticFailures > 30) return;
                    log({ event: 'connection-diagnostic', diagnostic: { ...diagnostic, client: client.id, peer: recipient.id, platform, browser } });
                } else if (message.type === 'room-create') {
                    for (const [code, room] of rooms) if (room.owner === client.id) rooms.delete(code);
                    let code;
                    do { code = String(randomInt(100000, 1000000)); } while (rooms.has(code));
                    const room = { id: randomUUID(), owner: client.id, expires: Date.now() + 300000 };
                    rooms.set(code, room);
                    moveRoom({ client, room: room.id });
                    send(socket, { type: 'room-created', code });
                    roster();
                } else if (message.type === 'room-join') {
                    if (Date.now() > client.roomAttemptReset) { client.roomAttempts = 0; client.roomAttemptReset = Date.now() + 60000; }
                    if (++client.roomAttempts > 5) { send(socket, { type: 'error', code: 'room_unavailable' }); return; }
                    const room = rooms.get(message.code);
                    if (!room || room.expires <= Date.now()) {
                        send(socket, { type: 'error', code: 'room_unavailable' });
                        return;
                    }
                    moveRoom({ client, room: room.id });
                    roster();
                } else if (message.type === 'room-leave') {
                    moveRoom({ client, room: 'staff' });
                    roster();
                } else throw new Error('invalid_message');
            } catch {
                log({ event: 'rejected' });
                remove(client);
                if (!client.identity) socket.terminate();
                else socket.close(4403, 'authentication_or_protocol_rejected');
            }
        });
    });
    const timer = setInterval(() => {
        for (const client of clients.values()) {
            client.frames = 0; client.relayBurst = 0;
            if (client.socket.isPaused) client.socket.resume();
            if (client.identity && !live(client)) { remove(client); client.socket.close(4401, 'expired'); }
        }
        for (const [code, room] of rooms) if (room.expires <= Date.now()) rooms.delete(code);
    }, 1000);
    timer.unref();
    return { server, close: async () => {
        clearInterval(timer);
        for (const socket of wss.clients) socket.terminate();
        await new Promise(resolve => wss.close(resolve));
        await new Promise(resolve => server.close(resolve));
    } };
}
