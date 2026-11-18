/* Mothership adapter for the pinned PairDrop transport. No grant, filename or message logging. */
'use strict';
const Events = { on: (type, listener) => window.addEventListener(type, listener),
    fire: (type, detail) => window.dispatchEvent(new CustomEvent(type, { detail })) };
const BrowserTabsConnector = { peerIsSameBrowser: () => false };
const Localization = { getTranslation: () => 'Transfer complete' };
// PairDrop uses this only to display a pairing fingerprint; staff identity comes from the server.
function cyrb53(value) { let hash = 0; for (const char of value) hash = (hash * 31 + char.charCodeAt(0)) >>> 0; return hash; }
async function getThumbnailAsDataUrl() { return ''; }

// This identifier groups tabs, not accounts or authorization. Grants stay in memory.
async function browserIdentity() {
    const load = () => {
        const key = 'mothership-transfers-browser-v1';
        const saved = localStorage.getItem(key);
        if (/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/.test(saved)) return saved;
        const id = crypto.randomUUID(); localStorage.setItem(key, id); return id;
    };
    try {
        return navigator.locks ? await navigator.locks.request('mothership-transfers-browser-v1', load) : load();
    } catch { return undefined; } // Storage-denied browsers retain independent usable connections.
}

function browserPeerKey(info) {
    return JSON.stringify(info.browserId ? [info.userId, info.browserId] : [info.peerId]);
}

function browserPeers({ peers, representatives }) {
    const groups = new Map();
    const score = item => item.peer.active ? 3 : item.peer._isConnected() ? 2 : !item.peer._disposed ? 1 : 0;
    for (const [id, item] of peers) {
        const key = browserPeerKey(item.info);
        const current = groups.get(key);
        if (!current || score(item) > score(current[1])
            || (score(item) === score(current[1]) && representatives.get(key) === id)) groups.set(key, [id, item]);
    }
    for (const key of representatives.keys()) if (!groups.has(key)) representatives.delete(key);
    for (const [key, [id]] of groups) representatives.set(key, id);
    return [...groups.values()];
}

// The hardened transfer protocol. Both transports use it unchanged: the direct WebRTC channel (StaffPeer) and the
// relay through the Transfers service (RelayPeer).
const staffProtocol = Base => class extends Base {
    async requestFileTransfer(files) { this.sending = true; return super.requestFileTransfer(files); }
    _onMessage(message) {
        try {
            if (typeof message === 'string') {
                if (message.length > 65536) throw new Error('message_size');
                const parsed = JSON.parse(message);
                if (['file-transfer-complete', 'partition-received'].includes(parsed.type)
                    && (!this.sending || !this._chunker)) return;
                if (parsed.type === 'file-transfer-complete' && !this._chunker.isFileEnd()) return;
                if (parsed.type === 'cancel') { this.reset(); Events.fire('transfer-cancelled', this._peerId); return; }
                if (parsed.type === 'display-name-changed') return;
                if (parsed.type === 'request') {
                    if (!Array.isArray(parsed.header) || !parsed.header.length || parsed.header.length > 1000
                        || !parsed.header.every(item => typeof item.name === 'string' && item.name.length <= 255
                            && typeof item.mime === 'string' && item.mime.length <= 200
                            && Number.isSafeInteger(item.size) && item.size >= 0)
                        || parsed.totalSize !== parsed.header.reduce((total, item) => total + item.size, 0)) throw new Error('bad_offer');
                    if (this.active) { this.sendJSON({ type: 'files-transfer-response', accepted: false }); return; }
                    this.active = true;
                }
                if (parsed.type === 'header') {
                    const expected = this._requestAccepted?.header[0];
                    if (!expected || expected.name !== parsed.name || expected.size !== parsed.size) throw new Error('bad_header');
                }
                if (parsed.type === 'files-transfer-response' && !this._filesRequested) return;
            }
            // Skip upstream RTCPeer's plaintext console logging; reuse Peer transfer protocol directly.
            Peer.prototype._onMessage.call(this, message);
        } catch { Events.fire('transfer-failure', this._peerId); this.cancel(); }
    }
    _onTextReceived(message) {
        const text = decodeURIComponent(escape(atob(message.text)));
        if (!text.length || text.length > 10000) throw new Error('text_size');
        Events.fire('text-received', { text, peerId: this._peerId });
        this.sendJSON({ type: 'message-transfer-complete' });
    }
    _onFileHeader(header) {
        super._onFileHeader(header);
        // Upstream ignores empty chunks, so complete a validated zero-byte file at its header.
        if (header.size === 0) this._onFileReceived(new File([], header.name, { type: header.mime }));
    }
    _onChunkReceived(chunk) {
        if (this._digester && this._digester._bytesReceived + (chunk.byteLength || chunk.size || 0) > this._digester._size) {
            Events.fire('transfer-failure', this._peerId); this.cancel(); return;
        }
        super._onChunkReceived(chunk);
    }
    _onFileTransferCompleted() { super._onFileTransferCompleted(); if (!this._busy) { this.sending = false; this.active = false; Events.fire('transfer-finished', this._peerId); } }
    _onFileTransferRequestResponded(message) {
        super._onFileTransferRequestResponded(message);
        if (!message.accepted) { this.sending = false; this.active = false; Events.fire('transfer-declined', this._peerId); }
    }
    reset() {
        if (this._chunker) {
            this._chunker._onChunk = () => {};
            this._chunker._onPartitionEnd = () => {};
            this._chunker._readChunk = () => {};
            if (this._chunker._reader.readyState === 1) this._chunker._reader.abort();
        }
        this._chunker = null; this._digester = null; this._filesRequested = null;
        this._requestPending = null; this._requestAccepted = null;
        this._filesReceived = []; this._filesQueue = []; this._busy = false; this.active = false; this.sending = false;
    }
    cancel() {
        if (this._isConnected()) this.sendJSON({ type: 'cancel' });
        this.reset(); Events.fire('transfer-cancelled', this._peerId);
    }
};

class StaffPeer extends staffProtocol(RTCPeer) {
    constructor({ server, caller, id, room, rtcConfig, connectTimeoutMs = 15000 }) {
        super(server, caller, id, 'staff', room, rtcConfig);
        this.cancelled = false;
        // A caller opens its connection inside super(), before this deadline is known; the timer starts here.
        this._connectTimeoutMs = connectTimeoutMs;
        this._startConnectingTimer();
    }
    _openConnection() {
        super._openConnection();
        this._startConnectingTimer();
        // A failed STUN/TURN candidate does not mean all ICE routes failed.
        this._conn.onicecandidateerror = event => this._onIceCandidateError(event);
    }
    _startConnectingTimer() {
        if (this._connectingTimer || this._disposed || this._isConnected() || !this._connectTimeoutMs) return;
        this._connectingTimer = setTimeout(() => {
            this._connectingTimer = null;
            if (!this._disposed && !this._isConnected()) this._failConnection({ source: 'connecting-timeout' });
        }, this._connectTimeoutMs);
    }
    _sendSignal(message) { if (!this._disposed) super._sendSignal(message); }
    _onIceCandidate(event) {
        if (event.candidate) this.localCandidates = (this.localCandidates || 0) + 1;
        super._onIceCandidate(event);
    }
    _onDescription(description) { if (!this._disposed) super._onDescription(description); }
    onServerMessage(message) {
        if (this._disposed) return;
        if (!this._conn) this._connect();
        if (message.sdp) {
            this._conn.setRemoteDescription(message.sdp).then(() => {
                if (this._disposed) return;
                if (message.sdp.type === 'offer') return this._conn.createAnswer().then(description => this._onDescription(description));
            }).catch(() => this._failConnection({ source: 'sdp' }));
        } else if (message.ice) {
            this.remoteCandidates = (this.remoteCandidates || 0) + 1;
            try {
                this._conn.addIceCandidate(new RTCIceCandidate(message.ice))
                    .catch(() => this._connectionDiagnostic({ source: 'ice-add' }));
            } catch { this._connectionDiagnostic({ source: 'ice-add' }); }
        }
    }
    _connectionDiagnostic({ source, code = 0 }) {
        if (this._disposed) return;
        // Retain bounded source evidence without the candidate URL, address or error text.
        this.lastConnectionDiagnostic = { peerId: this._peerId, source, code: Number.isInteger(code) ? code : 0,
            connection: this._conn?.connectionState || 'new', ice: this._conn?.iceConnectionState || 'new',
            signaling: this._conn?.signalingState || 'stable', gathering: this._conn?.iceGatheringState || 'new',
            localCandidates: this.localCandidates || 0, remoteCandidates: this.remoteCandidates || 0 };
        Events.fire('connection-diagnostic', this.lastConnectionDiagnostic);
    }
    _onIceCandidateError(event) {
        this._connectionDiagnostic({ source: 'ice-candidate', code: event?.errorCode });
    }
    _failConnection({ source }) {
        if (this._disposed) return;
        this._connectionDiagnostic({ source });
        // The shared disconnect handler preserves an active interruption, then destroys
        // transport and transfer state. Idle peers create no transfer activity.
        Events.fire('peer-disconnected', this._peerId);
    }
    _onError() { this._failConnection({ source: 'signaling-or-channel' }); }
    _onChannelClosed() { this._failConnection({ source: 'data-channel-closed' }); }
    _onConnectionStateChange() {
        if (this._disposed) return;
        const state = this._conn?.connectionState;
        if (state === 'disconnected') {
            if (!this._disconnectTimer) this._disconnectTimer = setTimeout(() => {
                this._disconnectTimer = null;
                if (this._conn?.connectionState === 'disconnected') this._failConnection({ source: 'connection-timeout' });
            }, 5000);
        } else {
            clearTimeout(this._disconnectTimer); this._disconnectTimer = null;
            if (['failed', 'closed'].includes(state)) this._failConnection({ source: 'connection-state' });
        }
    }
    _onIceConnectionStateChange() { if (this._conn?.iceConnectionState === 'failed') this._failConnection({ source: 'ice-state' }); }
    _onChannelOpened(event) {
        const channel = event.channel || event.target;
        if (this._disposed) { channel.close(); return; }
        clearTimeout(this._connectingTimer); this._connectingTimer = null;
        channel.binaryType = 'arraybuffer';
        channel.onmessage = event => this._onMessage(event.data);
        channel.onclose = () => this._onChannelClosed();
        channel.onerror = () => this._failConnection({ source: 'data-channel' });
        this._channel = channel;
        this._connectionDiagnostic({ source: 'connected' });
        Events.fire('peer-connected', { peerId: this._peerId });
    }
    destroy() {
        this._disposed = true;
        clearTimeout(this._disconnectTimer); this._disconnectTimer = null;
        clearTimeout(this._connectingTimer); this._connectingTimer = null;
        this.reset();
        if (this._channel) { this._channel.onclose = null; this._channel.onmessage = null; this._channel.onerror = null; this._channel.onopen = null; this._channel.close(); }
        if (this._conn) {
            this._conn.onconnectionstatechange = null; this._conn.onicecandidate = null;
            this._conn.onicecandidateerror = null; this._conn.oniceconnectionstatechange = null;
            this._conn.ondatachannel = null; this._conn.close();
        }
    }
}

// The same protocol carried by the Transfers service's WebSocket, for two browsers that have no direct route. The
// service forwards each frame, in memory, to the one peer it names, and only once both browsers asked for the relay.
// It is ready when the other browser has asked too; until then nothing is sent or accepted.
class RelayPeer extends staffProtocol(Peer) {
    constructor({ server, id, room, openTimeoutMs = 10000 }) {
        super(server, false, id, 'staff', room);
        this.cancelled = false; this.relay = true; this._opened = false;
        this._openTimer = setTimeout(() => {
            this._openTimer = null;
            if (!this._disposed && !this._opened) Events.fire('peer-disconnected', this._peerId);
        }, openTimeoutMs);
    }
    _isConnected() { return this._opened && !this._disposed; }
    open() {
        if (this._disposed || this._opened) return;
        clearTimeout(this._openTimer); this._openTimer = null;
        this._opened = true; Events.fire('peer-connected', { peerId: this._peerId });
    }
    _send(message) {
        if (!this._isConnected()) return;
        // The receiving browser refuses a longer protocol message, and the service ends a session that sends one.
        if (typeof message === 'string' && message.length > 65536) throw new Error('message_size');
        this._server.relay({ to: this._peerId, message });
    }
    receive(message) { if (this._isConnected()) this._onMessage(message); }
    onServerMessage() { /* A direct offer from a browser that is already on the relay is not answered. */ }
    destroy() { this._disposed = true; clearTimeout(this._openTimer); this._openTimer = null; this.reset(); }
}

// The Mothership page that frames this client, chosen only from the service's exact allowlist. Chromium and Safari
// report it in location.ancestorOrigins, and that browser fact always wins. Firefox has neither ancestorOrigins nor a
// referrer here, so the parent names its own origin in `hub_parent`; that claim is only a choice among the allowlist.
// Every post names the chosen origin as targetOrigin (the browser drops it unless the parent really is that origin)
// and every inbound message must come from window.parent with exactly that origin, so a false claim sends and accepts
// nothing. A single allowed origin needs no claim. Returns null when no allowed parent can be chosen yet.
function resolveParentOrigin({ allowed, ancestor, claimed }) {
    if (ancestor) return allowed.includes(ancestor) ? ancestor : null;
    if (claimed && allowed.includes(claimed)) return claimed;
    return allowed.length === 1 ? allowed[0] : null;
}

(async () => {
    document.documentElement.dataset.embedded = String(window.parent !== window);
    // Read and remove the claim before any await, so nothing else on the page sees it in the URL.
    const claimedParent = (() => {
        try {
            const url = new URL(location.href);
            if (!url.searchParams.has('hub_parent')) return null;
            const value = url.searchParams.get('hub_parent');
            url.searchParams.delete('hub_parent');
            history.replaceState(history.state, '', url.pathname + url.search + url.hash);
            return value;
        } catch (_) { return null; }
    })();
    const config = await fetch('/config').then(response => response.json());
    // The service names a relay only when it offers one; `afterMs` is how long a direct route is tried first.
    const relayAfterMs = Number.isInteger(config.relay?.afterMs) && config.relay.afterMs > 0 ? config.relay.afterMs : null;
    const allowedParents = Array.isArray(config.parentOrigins) ? config.parentOrigins.filter(value => typeof value === 'string')
        : typeof config.parentOrigin === 'string' ? [config.parentOrigin] : [];
    const ancestorParent = location.ancestorOrigins?.[0] ?? null;
    let parentOrigin = resolveParentOrigin({ allowed: allowedParents, ancestor: ancestorParent, claimed: claimedParent });
    // Firefox framed by a host page that sends no claim (an older The Mothership release): announce only the data-free
    // `ready` to each allowed origin (the browser delivers it to the one that really is the parent), then lock onto
    // the first message window.parent sends from an allowed origin. Nothing else is posted until then.
    let learnParent = !parentOrigin && !ancestorParent && allowedParents.length > 1;
    const browserId = await browserIdentity();
    const $ = id => document.getElementById(id);
    const peers = new Map(); const rows = new Map(); const activityPeople = new Map(); const downloads = new Set();
    const representatives = new Map();
    let socket = null; let identity = null; let selected = null; let selectedUserId = null; let selectedPeerId = null; let stopped = false;
    let selectedPeerGroup = null;
    let diagnosticsEnabled = false;
    let diagnosticWindow = 0; let diagnosticInfo = 0; let diagnosticFailures = 0;
    let lastReveal = -Infinity;
    let expiryTimer; let setupTimer;
    const post = (type, payload = {}) => {
        if (parentOrigin) window.parent.postMessage({ channel: 'mothership-transfers', version: 1, type, ...payload }, parentOrigin);
        else if (learnParent && type === 'ready') for (const origin of allowedParents) window.parent.postMessage({ channel: 'mothership-transfers', version: 1, type }, origin);
    };
    const operations = new Map();
    const trackTransfer = ({ id, event, direction, files, error_code }) => {
        try {
            if (event === 'attempted') {
                if (operations.has(id)) trackTransfer({ id, event: 'failed' });
                operations.set(id, { transfer_id: crypto.randomUUID(), direction, files });
            }
            const operation = operations.get(id);
            if (!operation) return;
            post('analytics', { event, props: { ...operation, ...(error_code ? { error_code } : {}) } });
            if (event !== 'attempted') operations.delete(id);
        } catch (_) { /* Capture cannot interrupt a peer operation. */ }
    };
    Events.on('transfer-failure', event => trackTransfer({ id: event.detail, event: 'failed', error_code: 'connection' }));
    const groupedPeers = () => browserPeers({ peers, representatives });
    const state = () => post('state', { status: stopped ? 'disconnected' : identity ? 'ready' : 'connecting', readyPeers: groupedPeers().map(([, item]) => item).filter(item => item.peer._isConnected()).map(item => item.info),
        activeCount: [...peers.values()].filter(item => item.peer.active).length });
    const send = message => { if (socket?.readyState === WebSocket.OPEN) socket.send(JSON.stringify(message)); };
    // Relay frame: 36 characters naming the other peer, one kind byte (0 = protocol text, 1 = file chunk), the payload.
    const sendRelay = ({ to, message }) => {
        if (socket?.readyState !== WebSocket.OPEN) return;
        const text = typeof message === 'string';
        const body = text ? new TextEncoder().encode(message) : new Uint8Array(message);
        const frame = new Uint8Array(37 + body.byteLength);
        for (let index = 0; index < 36; index++) frame[index] = to.charCodeAt(index);
        frame[36] = text ? 0 : 1; frame.set(body, 37);
        socket.send(frame);
    };
    // The service stamps the sender; a frame is used only by that sender's open relay peer.
    const relayed = data => {
        const bytes = new Uint8Array(data);
        if (bytes.byteLength < 37) return;
        const item = peers.get(String.fromCharCode(...bytes.subarray(0, 36)));
        if (!item?.peer.relay) return;
        item.peer.receive(bytes[36] === 0 ? new TextDecoder().decode(bytes.subarray(37)) : bytes.slice(37).buffer);
    };
    Events.on('connection-diagnostic', event => {
        if (!diagnosticsEnabled || !identity || !peers.has(event.detail.peerId)) return;
        if (Date.now() >= diagnosticWindow) { diagnosticWindow = Date.now() + 60000; diagnosticInfo = 0; diagnosticFailures = 0; }
        const informational = ['connected', 'ice-candidate', 'ice-add'].includes(event.detail.source);
        if (informational ? ++diagnosticInfo > 15 : ++diagnosticFailures > 30) return;
        send({ type: 'diagnostic', ...event.detail });
    });
    const clearDownloads = () => { for (const url of downloads) URL.revokeObjectURL(url); downloads.clear(); };
    const cleanup = () => {
        for (const id of operations.keys()) trackTransfer({ id, event: 'failed', error_code: 'disconnected' });
        clearTimeout(expiryTimer); for (const { peer } of peers.values()) peer.destroy(); peers.clear();
        rows.clear(); activityPeople.clear(); representatives.clear(); selectedPeerGroup = null; clearDownloads(); $('activity').replaceChildren(); $('people').replaceChildren();
        $('send-dialog').close(); $('files').value = ''; $('message').value = ''; selected = null; identity = null;
        $('empty').hidden = false; $('activity-empty').hidden = false; state();
        $('this-device').hidden = true; $('this-device').textContent = ''; $('connection').hidden = false;
    };
    const closeSocket = () => {
        clearTimeout(setupTimer); setupTimer = null;
        const current = socket; socket = null;
        if (current) {
            current.onopen = null; current.onmessage = null; current.onclose = null; current.onerror = null;
            current.close();
        }
    };
    const disconnect = () => { stopped = true; closeSocket(); cleanup(); $('disconnect').textContent = 'Reconnect'; $('connection').textContent = 'Disconnected. Reconnect when you are ready.'; };
    const fail = code => { post('error', { code }); disconnect(); $('connection').textContent = 'Could not connect. Check your connection and choose Reconnect.'; };
    function activity({ id, title, detail = '', buttons = [] }) {
        rows.get(id)?.remove();
        const row = document.createElement('article'); row.className = 'transfer'; row.dataset.peerId = id;
        const heading = document.createElement('h3'); heading.textContent = title;
        const description = document.createElement('p'); description.textContent = detail;
        const actions = document.createElement('div'); actions.className = 'actions';
        const person = document.createElement('p'); person.className = 'transfer-person';
        const info = activityPeople.get(id) || peers.get(id)?.info;
        if (info) person.textContent = `${info.name} · ${info.deviceName}`;
        row.append(heading, person, description, actions);
        for (const { label, action, quiet = false } of buttons) {
            const button = document.createElement('button'); button.textContent = label; if (quiet) button.className = 'quiet';
            button.onclick = action; actions.append(button);
        }
        rows.set(id, row); $('activity').prepend(row); $('activity-empty').hidden = true; return row;
    }
    const cancelButton = id => ({ label: 'Cancel', quiet: true, action: () => peers.get(id)?.peer.cancel() });
    function revealIncoming(row, { kind }) {
        if (kind === 'text') {
            if (performance.now() - lastReveal < 5000) return;
            lastReveal = performance.now();
        }
        post('incoming-offer');
        // The host reveals its panel in response to postMessage. A hidden iframe may
        // suspend animation frames; queue the scroll after layout resumes in that panel.
        setTimeout(() => requestAnimationFrame(() => requestAnimationFrame(() => {
            if (row.isConnected) {
                const target = row.scrollHeight > innerHeight ? row.querySelector('.actions') || row : row;
                target.scrollIntoView({ block: 'center', behavior: 'instant' });
            }
        })), 50);
    }
    function openSend(id) {
        const item = peers.get(id); if (!item?.peer._isConnected() || item.peer.active) return;
        selected = id; activityPeople.set(id, { ...item.info }); $('clipboard-status').textContent = ''; chooseMode('files'); $('recipient').textContent = `To ${item.info.name} · ${item.info.deviceName}`;
        $('files').value = ''; $('message').value = ''; $('send-dialog').showModal();
    }
    $('send-dialog').addEventListener('close', () => {
        $('files').value = ''; $('message').value = '';
        [...$('people').children].find(button => button.dataset.peerId === selected)?.focus();
    });
    function renderPeers() {
        const focusId = document.activeElement?.dataset.peerId;
        const focusGroup = peers.has(focusId) ? browserPeerKey(peers.get(focusId).info) : null;
        if (peers.has(selectedPeerId)) selectedPeerGroup = browserPeerKey(peers.get(selectedPeerId).info);
        $('people').replaceChildren();
        for (const [id, { info, peer, failures }] of groupedPeers()) {
            const retry = peer._disposed && failures >= 2;
            const button = document.createElement('button'); button.className = 'peer'; button.dataset.peerId = id;
            button.dataset.connectionState = peer._isConnected() ? 'ready' : retry ? 'failed' : 'connecting';
            button.dataset.transport = peer.relay ? 'relay' : 'direct';
            button.setAttribute('aria-pressed', String(selectedPeerId ? id === selectedPeerId || browserPeerKey(info) === selectedPeerGroup : info.userId === selectedUserId));
            button.disabled = (!peer._isConnected() && !retry) || peer.active;
            const name = document.createElement('strong'); name.textContent = info.name;
            const device = document.createElement('small'); device.textContent = `${info.deviceName} · ${peer._isConnected() ? (peer.relay ? 'Ready · through The Mothership' : 'Ready') : retry ? 'Could not connect · Retry' : peer._disposed ? 'Disconnected · checking again…' : 'Connecting…'}`;
            button.append(name, device); button.onclick = () => {
                if (retry) { const item = peers.get(id); if (!item) return; item.failures = 0; replacePeer({ id, item, caller: true }); renderPeers(); }
                else openSend(id);
            };
            button.ondragover = event => event.preventDefault();
            button.ondrop = event => { event.preventDefault(); offer({ id, files: [...event.dataTransfer.files] }); };
            $('people').append(button);
            if (focusId === id || browserPeerKey(info) === focusGroup) button.focus();
        }
        $('empty').hidden = peers.size > 0; state();
    }
    async function offer({ id, files }) {
        const item = peers.get(id); if (!item?.peer._isConnected() || item.peer.active || !files.length) return;
        item.peer.active = true; activityPeople.set(id, { ...item.info });
        trackTransfer({ id, event: 'attempted', direction: 'send', files: files.length });
        activity({ id, title: `Sending to ${item.info.name}`, detail: 'Preparing files…', buttons: [cancelButton(id)] });
        renderPeers(); $('send-dialog').close();
        try { await item.peer.requestFileTransfer(files); } catch { trackTransfer({ id, event: 'failed', error_code: 'connection' }); item.peer.cancel(); }
        state();
    }
    function replacePeer({ id, item, caller }) {
        item.peer?.destroy();
        item.peer = new StaffPeer({ server: { send }, caller, id, room: item.room, rtcConfig: config.rtcConfig,
            connectTimeoutMs: relayAfterMs ?? 15000 });
    }
    // Two browsers with no direct route (different networks, a filtering firewall) reach each other through the
    // service. This browser asks for the relay; it is ready once the other browser has asked too.
    function useRelay({ id, item, remoteOpen = false }) {
        if (!item.peer.relay || item.peer._disposed) {
            item.peer.destroy();
            item.peer = new RelayPeer({ server: { relay: sendRelay }, id, room: item.room });
            send({ type: 'relay', to: id, state: 'open' });
        }
        if (remoteOpen) item.peer.open();
        renderPeers();
    }
    function reconcile(message) {
        if (peers.has(selectedPeerId)) selectedPeerGroup = browserPeerKey(peers.get(selectedPeerId).info);
        const incoming = new Map(message.peers.map(info => [info.peerId, info]));
        for (const [id, item] of peers) {
            if (!incoming.has(id) || item.room !== message.room) {
                trackTransfer({ id, event: 'failed', error_code: 'disconnected' });
                if (item.peer.active) activity({ id, title: 'Transfer interrupted', detail: 'The other device disconnected.' });
                item.peer.destroy(); peers.delete(id);
            }
        }
        identity = message.selfId;
        for (const [id, info] of incoming) {
            let item = peers.get(id);
            if (!item) { item = { info, room: message.room, failures: 0 }; peers.set(id, item); }
            item.info = info;
            if (!item.peer || (item.peer._disposed && item.failures < 2)) replacePeer({ id, item, caller: identity.localeCompare(id) > 0 });
        }
        if (selected && !peers.has(selected)) {
            const info = activityPeople.get(selected);
            const replacement = info && [...peers].find(([, item]) => browserPeerKey(item.info) === browserPeerKey(info) && item.peer._isConnected() && !item.peer.active);
            if (replacement) {
                selected = replacement[0]; activityPeople.set(selected, { ...replacement[1].info });
            } else {
                if ($('send-dialog').open) activity({ id: selected, title: 'Transfer interrupted', detail: 'The other device disconnected.' });
                $('send-dialog').close(); selected = null;
            }
        }
        renderPeers();
    }
    function connect(grant) {
        stopped = false;
        $('disconnect').textContent = 'Disconnect';
        socket = new WebSocket(`${location.protocol === 'https:' ? 'wss' : 'ws'}://${location.host}/ws`);
        const current = socket;
        current.binaryType = 'arraybuffer';
        const isCurrent = () => socket === current && !stopped;
        const startSetupTimer = () => {
            clearTimeout(setupTimer);
            setupTimer = setTimeout(() => { if (isCurrent()) fail('connection_unavailable'); }, 15000);
        };
        startSetupTimer();
        current.onopen = () => {
            if (!isCurrent()) return;
            send({ type: 'authenticate', grant, browserId }); grant = null; startSetupTimer();
        };
        current.onmessage = event => {
            if (socket !== current || stopped) return;
            if (typeof event.data !== 'string') { relayed(event.data); return; }
            const message = JSON.parse(event.data);
            if (message.type === 'authenticated') {
                diagnosticsEnabled = message.diagnosticsEnabled === true;
                clearTimeout(setupTimer); setupTimer = null;
                clearTimeout(expiryTimer);
                expiryTimer = setTimeout(() => { if (isCurrent()) fail('session_expired'); }, Math.max(0, message.expiresAt * 1000 - Date.now()));
                $('connection').textContent = 'Connected · Staff only'; state();
                $('connection').hidden = window.parent !== window;
                $('this-device').textContent = `Signed in as ${message.name} · ${message.deviceName}`; $('this-device').hidden = false;
            } else if (message.type === 'roster') reconcile(message);
            else if (message.type === 'signal') {
                const item = peers.get(message.sender);
                // An offer can arrive before our next roster refresh, including an explicit retry.
                if (item?.peer._disposed && message.sdp?.type === 'offer') {
                    replacePeer({ id: message.sender, item, caller: false }); renderPeers();
                }
                item?.peer.onServerMessage(message);
            }
            else if (message.type === 'relay') {
                // The other browser gave up on a direct route and asked for the relay: join it.
                const item = peers.get(message.sender);
                if (relayAfterMs === null || !item || message.state !== 'open') return;
                if (!item.peer.relay && !item.peer._disposed) {
                    trackTransfer({ id: message.sender, event: 'failed', error_code: 'disconnected' });
                    if (item.peer.active) activity({ id: message.sender, title: 'Transfer interrupted', detail: 'The other device disconnected.' });
                }
                useRelay({ id: message.sender, item, remoteOpen: true });
            }
            else if (message.type === 'room-created') $('room-code').textContent = message.code;
            else if (message.type === 'error') $('room-code').textContent = 'That room is unavailable. Try a new code.';
        };
        current.onclose = () => { if (!isCurrent()) return; closeSocket(); cleanup(); $('connection').textContent = 'Connection lost. Reconnecting with your next session check…'; post('error', { code: 'connection_lost' }); };
        current.onerror = () => {
            if (!isCurrent()) return;
            if (setupTimer !== null) fail('connection_unavailable');
            else post('error', { code: 'connection_unavailable' });
        };
    }
    window.addEventListener('message', event => {
        if (!parentOrigin && learnParent && event.source === window.parent && allowedParents.includes(event.origin)) {
            parentOrigin = event.origin; learnParent = false;
        }
        if (!parentOrigin || event.origin !== parentOrigin || event.source !== window.parent) return;
        const data = event.data;
        if (!data || data.channel !== 'mothership-transfers' || data.version !== 1) return;
        if (data.type === 'theme' && ['light', 'dark'].includes(data.theme)) document.documentElement.dataset.theme = data.theme;
        else if (data.type === 'disconnect') disconnect();
        else if (data.type === 'select-peer') { selectedPeerId = typeof data.selectedPeerId === 'string' ? data.selectedPeerId : null; selectedPeerGroup = null; selectedUserId = null; renderPeers(); }
        else if (data.type === 'select-user') { selectedUserId = typeof data.selectedUserId === 'string' ? data.selectedUserId : null; selectedPeerId = null; selectedPeerGroup = null; renderPeers(); }
        else if (['initialize', 'authenticate'].includes(data.type) && typeof data.grant === 'string' && data.grant.length <= 4096) {
            if (data.type === 'initialize') { selectedUserId = data.selectedUserId ?? null; selectedPeerId = data.selectedPeerId ?? null; selectedPeerGroup = null; stopped = false; }
            if (stopped) return;
            if (!socket) connect(data.grant);
            else if (socket.readyState === WebSocket.OPEN) send({ type: 'authenticate', grant: data.grant, browserId });
        }
    });
    Events.on('peer-connected', event => { const item = peers.get(event.detail.peerId); if (item) item.failures = 0; renderPeers(); });
    Events.on('peer-disconnected', event => {
        trackTransfer({ id: event.detail, event: 'failed', error_code: 'disconnected' });
        const item = peers.get(event.detail); if (!item) return;
        if (item.peer._disposed) return;
        if (item.peer.active) activity({ id: event.detail, title: 'Transfer interrupted', detail: 'The other device disconnected.' });
        // A direct route that fails moves the pair to the relay. A relay the other browser never joins asks for a retry.
        if (relayAfterMs !== null && !item.peer.relay) { useRelay({ id: event.detail, item }); return; }
        item.failures = item.peer.relay ? 2 : item.failures + 1;
        item.peer.destroy(); renderPeers();
    });
    Events.on('files-transfer-request', event => {
        const { peerId, request } = event.detail; const item = peers.get(peerId); if (!item) return;
        activityPeople.set(peerId, { ...item.info });
        trackTransfer({ id: peerId, event: 'attempted', direction: 'receive', files: request.header.length });
        const row = activity({ id: peerId, title: `${item.info.name} wants to send files`, detail: request.header.map(file => `${file.name} (${file.size.toLocaleString()} bytes)`).join('\n'),
            buttons: [{ label: 'Accept', action: () => {
                item.peer._respondToFileTransferRequest(true);
                activity({ id: peerId, title: `Receiving from ${item.info.name}`, buttons: [cancelButton(peerId)] }); state();
            } }, { label: 'Decline', quiet: true, action: () => {
                trackTransfer({ id: peerId, event: 'declined', error_code: 'declined' });
                item.peer._respondToFileTransferRequest(false); item.peer.reset();
                activity({ id: peerId, title: 'Transfer declined' }); renderPeers();
            } }] });
        revealIncoming(row, { kind: 'files' }); renderPeers();
    });
    Events.on('files-received', event => {
        const { peerId, files } = event.detail; const item = peers.get(peerId); if (!item) return;
        item.peer.active = false;
        trackTransfer({ id: peerId, event: 'received' });
        const row = activity({ id: peerId, title: 'Files received', detail: `From ${item.info.name}. Save your files before leaving.` });
        for (const file of files) {
            const link = document.createElement('a'); const url = URL.createObjectURL(file); downloads.add(url);
            link.href = url; link.download = file.name; link.textContent = `Save ${file.name}`; row.append(link);
        }
        renderPeers();
    });
    Events.on('set-progress', event => {
        const { peerId, progress, status } = event.detail; const row = rows.get(peerId); if (!row) return;
        let bar = row.querySelector('progress'); if (!bar) { bar = document.createElement('progress'); bar.max = 1; bar.setAttribute('aria-label', 'Transfer progress'); row.append(bar); }
        bar.value = Math.max(0, Math.min(1, progress));
        if (status === 'wait' && peers.get(peerId)?.peer.active) row.querySelector('p:not(.transfer-person)').textContent = 'Waiting for acceptance…';
        state();
    });
    for (const [event, title] of [['transfer-cancelled', 'Transfer cancelled'], ['transfer-declined', 'Transfer declined'], ['transfer-finished', 'Files sent'], ['transfer-error', 'Connection interrupted']]) {
        Events.on(event, message => {
            const outcome = { 'transfer-cancelled': 'cancelled', 'transfer-declined': 'declined', 'transfer-finished': 'acknowledged', 'transfer-error': 'failed' }[event];
            trackTransfer({ id: message.detail, event: outcome, error_code: outcome === 'failed' ? 'connection' : undefined });
            if (event === 'transfer-error') peers.get(message.detail)?.peer.reset(); activity({ id: message.detail, title }); renderPeers();
        });
    }
    Events.on('text-received', event => {
        const item = peers.get(event.detail.peerId); if (!item) return;
        activityPeople.set(event.detail.peerId, { ...item.info });
        const text = event.detail.text;
        const row = activity({ id: event.detail.peerId, title: `Text from ${item.info.name}`, detail: text,
            buttons: [{ label: 'Copy text', action: async () => {
                const feedback = row.querySelector('.clipboard-feedback');
                try {
                    await navigator.clipboard.writeText(text);
                    if (row.isConnected) feedback.textContent = 'Copied to clipboard.';
                } catch {
                    if (row.isConnected) feedback.textContent = 'Clipboard unavailable. Select the text above and copy it manually.';
                }
            } }] });
        row.querySelector('p:not(.transfer-person)').className = 'received-text';
        const feedback = document.createElement('p'); feedback.className = 'clipboard-feedback'; feedback.setAttribute('role', 'status'); row.append(feedback);
        revealIncoming(row, { kind: 'text' });
    });
    function chooseMode(mode) {
        for (const value of ['files', 'text']) {
            $(`${value}-panel`).hidden = mode !== value;
            $(`${value}-mode`).setAttribute('aria-pressed', String(mode === value));
        }
    }
    $('files-mode').onclick = () => chooseMode('files');
    $('text-mode').onclick = () => chooseMode('text');
    $('paste-text').onclick = async () => {
        try {
            const text = await navigator.clipboard.readText();
            if (!$('send-dialog').open) return;
            if (text.length > 10000) { $('clipboard-status').textContent = 'Clipboard text exceeds 10,000 characters. Paste a shorter selection manually.'; return; }
            $('message').value = text;
            $('clipboard-status').textContent = text.length ? 'Pasted. Review your text before sending.' : 'Your clipboard has no text. Type or paste below.';
        } catch {
            if (!$('send-dialog').open) return;
            $('clipboard-status').textContent = 'Clipboard unavailable. Paste into the text field manually.';
        }
        $('message').focus();
    };
    $('send-files').onclick = () => offer({ id: selected, files: [...$('files').files] });
    $('send-text').onclick = () => {
        const item = peers.get(selected); if (!item?.peer._isConnected() || !$('message').value.length || $('message').value.length > 10000) return;
        item.peer.sendText($('message').value); $('message').value = ''; $('send-dialog').close();
        activity({ id: selected, title: 'Text sent', detail: `To ${item.info.name}` });
    };
    $('disconnect').onclick = () => { if (stopped) { $('connection').textContent = 'Reconnecting…'; post('reconnect'); } else disconnect(); };
    $('create-room').onclick = () => { if ([...peers.values()].some(item => item.peer.active)) return; send({ type: 'room-create' }); };
    $('join-room').onsubmit = event => { event.preventDefault(); if ([...peers.values()].some(item => item.peer.active)) return; send({ type: 'room-join', code: $('code').value }); $('code').value = ''; };
    $('leave-room').onclick = () => { if ([...peers.values()].some(item => item.peer.active)) return; send({ type: 'room-leave' }); $('room-code').textContent = ''; };
    window.addEventListener('beforeunload', event => { if ([...peers.values()].some(item => item.peer.active)) { event.preventDefault(); event.returnValue = ''; } });
    window.addEventListener('pagehide', disconnect);
    post('ready');
})().catch(() => { document.getElementById('connection').textContent = 'Transfers could not start. Reload this page.'; });
