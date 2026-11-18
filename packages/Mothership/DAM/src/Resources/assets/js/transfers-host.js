(() => {
    'use strict';
    const CHANNEL = 'mothership-transfers';
    // Transfers allows several The Mothership origins. Chromium and Safari tell the child its parent through
    // location.ancestorOrigins; Firefox does not and this iframe sends no referrer, so the frame URL names this page's
    // origin in `hub_parent`. The child only uses it to pick among its own allowlist; every post still names that
    // origin as targetOrigin, so a false claim delivers nothing. A frame URL that does not parse is used unchanged.
    function frameSrc(frameUrl) {
        try {
            const parentOrigin = window.location?.origin;
            if (!parentOrigin || parentOrigin === 'null') return frameUrl;
            const url = new URL(frameUrl);
            url.searchParams.set('hub_parent', parentOrigin);
            return url.toString();
        } catch (_) {
            return frameUrl;
        }
    }

    // The bridge owns connection state; a Home card is only a subscriber.
    function create({config, frame, request, onState = () => {}, onOffer = () => {}, track = (...args) => window.damTrack?.(...args), getTheme = () => 'light', schedule = setInterval, unschedule = clearInterval, delay = setTimeout, cancelDelay = clearTimeout}) {
        const origin = new URL(config.origin).origin;
        let stopped = false;
        let paused = false;
        let ready = false;
        let initialized = false;
        let pending = null;
        let revision = 0;
        let timer = null;
        let initializationTimer = null;
        let appliedTheme = null;
        let selectedUserId = config.selectedUserId === null ? null : String(config.selectedUserId ?? '');
        let selectedPeerId = config.selectedPeerId == null ? null : String(config.selectedPeerId);
        let state = {readyPeers: [], activeCount: 0, status: 'connecting'};
        const publish = next => {state = {...state, ...next}; onState(state);};
        const send = (type, payload = {}) => frame.contentWindow?.postMessage({channel: CHANNEL, version: 1, type, ...payload}, origin);
        const cancelPending = () => {
            const attempt = pending;pending = null;
            if (attempt) {cancelDelay(attempt.timer);attempt.controller.abort();}
        };
        const clearInitialization = () => {cancelDelay(initializationTimer);initializationTimer = null;};
        const suspend = () => {
            revision++;cancelPending();clearInitialization();
            if (timer !== null) {unschedule(timer);timer = null;}
        };
        const unavailable = () => {
            if (stopped) return;
            suspend();paused = true;initialized = false;
            send('disconnect');publish({readyPeers: [], activeCount: 0, status: 'unavailable'});
        };
        const awaitInitialization = milliseconds => {
            clearInitialization();const attempt = revision;
            initializationTimer = delay(() => {if (!stopped && attempt === revision) unavailable();}, milliseconds);
        };
        const updateTheme = () => {
            if (stopped || !initialized) return;
            const theme = getTheme() === 'dark' ? 'dark' : 'light';
            if (theme === appliedTheme) return;
            appliedTheme = theme;send('theme', {theme});
        };
        async function renew() {
            if (stopped || paused || !ready || pending !== null) return;
            const attempt = {revision, renewal: initialized, controller: new AbortController(), timer: null};
            pending = attempt;
            const failed = () => {
                if (stopped || pending !== attempt || attempt.revision !== revision) return;
                // Existing child authentication remains valid until its own expiry deadline.
                if (attempt.renewal) cancelPending();else unavailable();
            };
            attempt.timer = delay(failed, 15000);
            try {
                const proof = await request(config.grantUrl, {signal: attempt.controller.signal});
                if (stopped || pending !== attempt || attempt.revision !== revision) return;
                if (typeof proof?.grant !== 'string' || !proof.grant) throw new Error('invalid-grant');
                if (!initialized) {
                    send('initialize', {grant: proof.grant, selectedUserId, selectedPeerId});
                    initialized = true;
                    // Allow the child's bounded socket-open and authentication phases to finish.
                    awaitInitialization(35000);
                    updateTheme();
                } else send('authenticate', {grant: proof.grant});
            } catch (_) {
                failed();
            } finally {cancelDelay(attempt.timer);if (pending === attempt) pending = null;}
        }
        function receive(event) {
            if (stopped || event.origin !== origin || event.source !== frame.contentWindow) return;
            const data = event.data;
            if (!data || data.channel !== CHANNEL || data.version !== 1) return;
            if (data.type === 'ready') {
                if (paused && state.status === 'unavailable') return;
                revision++;cancelPending();clearInitialization();
                ready = true;
                paused = false;
                initialized = false;
                appliedTheme = null;
                publish({readyPeers: [], activeCount: 0, status: 'connecting'});
                renew();
                if (timer === null) timer = schedule(renew, 30000);
            } else if (data.type === 'state' && initialized) {
                const readyPeers = Array.isArray(data.readyPeers) ? data.readyPeers.filter(peer => peer && ['userId', 'peerId', 'name', 'deviceName'].every(key => typeof peer[key] === 'string')).slice(0, 500) : [];
                const activeCount = Number.isSafeInteger(data.activeCount) && data.activeCount >= 0 ? data.activeCount : 0;
                if (data.status === 'disconnected') {
                    paused = true;initialized = false;suspend();
                    publish({readyPeers: [], activeCount: 0, status: 'disconnected'});
                } else {
                    if (data.status !== 'connecting') clearInitialization();
                    publish({readyPeers, activeCount, status: data.status === 'connecting' ? 'connecting' : 'ready'});
                }
            } else if (data.type === 'analytics' && initialized) {
                const allowed = ['attempted', 'acknowledged', 'received', 'failed', 'cancelled', 'declined'];
                const p = data.props;
                if (!allowed.includes(data.event) || !p || typeof p.transfer_id !== 'string' || !/^[a-z0-9-]{8,48}$/.test(p.transfer_id)
                    || !['send', 'receive'].includes(p.direction) || !Number.isSafeInteger(p.files) || p.files < 0 || p.files > 1000000) return;
                const props = {transfer_id: p.transfer_id, direction: p.direction, files: p.files};
                if (['disconnected', 'connection', 'cancelled', 'declined'].includes(p.error_code)) props.error_code = p.error_code;
                try { track(`transfer.file.${data.event}`, 0, props, 'transfers'); } catch (_) { /* Capture is optional. */ }
            } else if (data.type === 'incoming-offer' && initialized) onOffer();
            else if (data.type === 'reconnect' && (paused || state.status === 'unavailable')) reconnect();
            else if (data.type === 'error') publish({readyPeers: [], status: 'unavailable'});
        }
        function select(userId) {
            selectedUserId = userId === null ? null : String(userId);
            selectedPeerId = null;
            if (initialized) send('select-user', {selectedUserId});
        }
        function selectPeer(peerId) {
            selectedPeerId = peerId == null ? null : String(peerId);selectedUserId = null;
            if (initialized) send('select-peer', {selectedPeerId});
        }
        function reconnect() {
            if (stopped) return;
            suspend();paused = false;initialized = false;appliedTheme = null;
            publish({readyPeers: [], activeCount: 0, status: 'connecting'});
            if (!ready) {awaitInitialization(15000);frame.src = frameSrc(config.frameUrl);return;}
            renew();if (timer === null) timer = schedule(renew, 30000);
        }
        function stop() {
            if (stopped) return;
            send('disconnect');
            stopped = true;suspend();
            publish({readyPeers: [], activeCount: 0, status: 'disconnected'});
        }
        awaitInitialization(15000);
        return {receive, select, selectPeer, reconnect, renew, stop, updateTheme, getState: () => state};
    }

    if (window.MothershipTransfers) {setTimeout(window.MothershipTransfers.mount, 0); return;}
    let current = null;
    const listeners = new Set();
    const notify = state => listeners.forEach(listener => listener(state));
    function mount() {
        const root = document.querySelector('[data-transfers-host]');
        if (current?.root === root && root?.isConnected) return;
        current?.dispose();current = null;
        if (!root) {notify(null); return;}
        const config = JSON.parse(root.dataset.transfersConfig);
        const frame = root.querySelector('iframe');
        const dialog = root.querySelector('dialog');
        const status = root.querySelector('[data-transfers-status]');
        const activity = root.querySelector('[data-transfers-activity]');
        let opener = null;
        let openerPeerId = null;
        let releaseHistory = null;
        const owningUrl = window.location?.href;
        const owningHistoryState = window.history?.state;
        const openPanel = ({userId = null, peerId = null}) => {
            if (peerId !== null) bridge.selectPeer(peerId);else bridge.select(userId);
            if (dialog && !dialog.open) {opener = document.activeElement;openerPeerId = peerId;dialog.showModal();}
        };
        const open = userId => openPanel({userId});
        const openPeer = peerId => openPanel({peerId});
        const syncHistory = activeCount => {
            if (!activeCount) {releaseHistory?.();releaseHistory = null;return;}
            if (releaseHistory || !window.mothership?.registerLocalHistoryHandler) return;
            releaseHistory = window.mothership.registerLocalHistoryHandler(() => {
                const target = window.location.href;
                window.history.pushState(owningHistoryState, '', owningUrl);
                if (window.confirm(config.labels.leave)) {bridge.stop();window.location.replace(target);}
                return true;
            });
        };
        const bridge = create({config, frame,
            getTheme: () => document.documentElement?.classList.contains('dark') ? 'dark' : 'light',
            request: async (url, {signal}) => {
                const response = await fetch(url, {method: 'POST', signal, credentials: 'same-origin', headers: {'Accept': 'application/json', 'X-CSRF-TOKEN': root.dataset.csrf}});
                if (!response.ok) throw new Error('grant-failed');
                return response.json();
            },
            onOffer: () => open(null),
            onState: state => {
                status.textContent = config.labels[state.status] || config.labels.unavailable;
                activity.textContent = state.activeCount ? (state.activeCount === 1 ? config.labels.activeOne : config.labels.active).replace(':count', state.activeCount) : config.labels.idle;
                const reconnect = root.querySelector('[data-transfers-reconnect]');
                if (reconnect) reconnect.hidden = !['disconnected', 'unavailable'].includes(state.status);
                syncHistory(state.activeCount);
                notify({...state, labels: config.labels, open, openPeer});
            },
        });
        const restoreFocus = () => {
            if (opener?.isConnected !== false) {opener?.focus?.();return;}
            const replacement = [...document.querySelectorAll('[data-qv-send]')].find(button => button.dataset.qvSend === String(openerPeerId));
            (replacement || document.querySelector('[data-qv-open-transfers]'))?.focus();
        };
        const close = () => {dialog?.close();restoreFocus();};
        root.querySelector('[data-transfers-close]')?.addEventListener('click', close);
        root.querySelector('[data-transfers-reconnect]')?.addEventListener('click', () => bridge.reconnect());
        dialog?.addEventListener('close', restoreFocus);
        const message = event => bridge.receive(event);
        const unload = event => {if (bridge.getState().activeCount) {event.preventDefault();event.returnValue = '';}};
        // BEFORE cancellation deliberately falls back to full navigation, where beforeunload protects work.
        const before = event => {if (bridge.getState().activeCount) event.preventDefault();};
        const pagehide = () => bridge.stop();
        window.addEventListener('message', message);
        window.addEventListener('beforeunload', unload);
        window.addEventListener('pagehide', pagehide);
        document.addEventListener('mothership:navigate:before', before);
        const themeObserver = typeof MutationObserver === 'function' && document.documentElement
            ? new MutationObserver(() => bridge.updateTheme()) : null;
        themeObserver?.observe(document.documentElement, {attributes: true, attributeFilter: ['class']});
        current = {root, bridge, open, openPeer, labels: config.labels, dispose() {
            bridge.stop();
            releaseHistory?.();releaseHistory = null;
            themeObserver?.disconnect();
            window.removeEventListener('message', message);
            window.removeEventListener('beforeunload', unload);
            window.removeEventListener('pagehide', pagehide);
            document.removeEventListener('mothership:navigate:before', before);
        }};
        // Assign src only after the pinned listener exists; even a cached child cannot race ready.
        frame.src = frameSrc(config.frameUrl);
        notify({...bridge.getState(), labels: config.labels, open, openPeer});
    }
    window.MothershipTransfers = {create, mount,
        subscribe(listener) {listeners.add(listener);listener(current ? {...current.bridge.getState(), labels: current.labels, open: current.open, openPeer: current.openPeer} : null);return () => listeners.delete(listener);},
        open(userId = null) {current?.open(userId);},
        openPeer(peerId) {current?.openPeer(peerId);},
    };
    const afterMount = () => setTimeout(mount, 0);
    document.addEventListener('mothership:navigate:success', afterMount);
    window.addEventListener('pageshow', event => {if (event.persisted) {current?.dispose();current = null;mount();}});
    // Admin Vue replaces #app on DOMContentLoaded. Bind the actual iframe after that mount.
    if (document.readyState !== 'loading') afterMount();
    else document.addEventListener('DOMContentLoaded', afterMount, {once: true});
})();
