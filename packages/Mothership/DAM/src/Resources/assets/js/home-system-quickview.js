(() => {
    'use strict';
    const METRICS = [
        {key:'cpu', label:'CPU', aria:'CPU load'},
        {key:'memory', label:'Memory', aria:'Unified memory used'},
        {key:'gpu', label:'GPU', aria:'GPU load'},
    ];
    const VISIBLE_USERS = 4;
    const VISIBLE_PEERS = 2;
    const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
    const bytes = value => {
        const units = ['B','KB','MB','GB','TB'];let amount = Number(value);let unit = 0;
        while (amount >= 1024 && unit < units.length - 1) {amount /= 1024;unit++;}
        return `${amount >= 100 || unit === 0 ? Math.round(amount) : amount.toFixed(1)} ${units[unit]}`;
    };
    const validPercent = signal => signal && signal.available === true && Number.isFinite(Number(signal.percent));

    function create({root = document, request, intervalMs = 10000, idleIntervalMs = intervalMs * 3, idleAfterMs = 120000, now = () => Date.now(), reducedMotion = false}) {
        let card = null;
        let timer = null;
        // `sequence` numbers every request; `applied` is the newest one whose outcome reached the card.
        let sequence = 0;
        let applied = 0;
        let expanded = false;
        let availability = null;
        let updatedAt = null;
        let failed = false;
        let presence = null;
        let metricsStale = false;
        let announcement = '';
        const lastMetrics = {};
        let transfers = null;
        let releaseTransfers = null;
        let transferActionsKey = null;

        const part = selector => card?.querySelector(selector);

        function skeleton() {
            transferActionsKey = null;
            const body = card.querySelector('.agh-card-body') || card;
            body.innerHTML = `<div class="agh-qv${reducedMotion ? ' is-reduced' : ''}" data-qv><div class="agh-qv-meters">${METRICS.map(metric => `<div class="agh-qv-metric" data-qv-metric="${metric.key}"><div class="agh-qv-label"><span>${metric.label}</span><strong data-qv-value>—</strong></div><div class="agh-meter" role="meter" aria-label="${metric.aria}" aria-valuemin="0" aria-valuemax="100"><span style="width:0%"></span></div><small class="agh-caption" data-qv-detail></small></div>`).join('')}</div><div class="agh-qv-people"><p class="agh-qv-people-head" data-qv-count></p><ul class="agh-qv-chips" data-qv-users></ul></div><div class="agh-qv-foot"><span class="agh-caption" data-qv-updated></span><span class="agh-qv-status" role="status" aria-live="polite" data-qv-status></span></div></div>`;
            body.querySelector('[data-qv-users]').addEventListener('click', event => {
                if (!event.target.closest('[data-qv-more]')) return;
                expanded = !expanded;paintPresence();
                part('[data-qv-more]')?.focus();
            });
            if (window.MothershipTransfers) {
                const actions = document.createElement('div');
                actions.className = 'agh-qv-transfer';
                actions.setAttribute('data-qv-transfers', '');
                body.querySelector('[data-qv]').insertBefore(actions, body.querySelector('.agh-qv-foot'));
                actions.addEventListener('click', event => {
                    const button = event.target.closest('[data-qv-send], [data-qv-open-transfers]');
                    if (!button) return;
                    if (button.hasAttribute('data-qv-send')) transfers?.openPeer(button.dataset.qvSend);
                    else transfers?.open(null);
                });
            }
        }

        function paintTransfers() {
            const actions = part('[data-qv-transfers]');
            if (!actions) return;
            if (!transfers) {actions.replaceChildren();transferActionsKey = null;return;}
            const {labels, readyPeers, activeCount} = transfers;
            const nextActionsKey = JSON.stringify(readyPeers);
            if (nextActionsKey !== transferActionsKey) {
                transferActionsKey = nextActionsKey;
                actions.innerHTML = `<div class="agh-qv-transfer-head"><p role="status" aria-live="polite" data-qv-transfer-status></p><button type="button" class="agh-button" data-qv-open-transfers>${esc(labels.open)}</button></div>`
                    + `<ul class="agh-qv-transfer-peers">${readyPeers.slice(0, VISIBLE_PEERS).map(peer => `<li><div><strong>${esc(peer.name)}</strong><span>${esc(peer.deviceName)}</span></div><button type="button" class="agh-qv-send" data-qv-send="${esc(peer.peerId)}" aria-label="${esc(labels.sendToDevice.replace(/:name|:device/g, placeholder => placeholder === ':name' ? peer.name : peer.deviceName))}">${esc(labels.send)}</button></li>`).join('')}</ul>`
                    + (readyPeers.length > VISIBLE_PEERS ? `<button type="button" class="agh-button agh-qv-more-peers" data-qv-open-transfers>${esc(labels.morePeers.replace(':count', readyPeers.length - VISIBLE_PEERS))}</button>` : '');
            }
            actions.querySelector('[data-qv-transfer-status]').textContent = activeCount ? (activeCount === 1 ? labels.activeOne : labels.active).replace(':count', activeCount)
                : transfers.status === 'ready' && !readyPeers.length ? labels.noPeers : labels[transfers.status] || labels.unavailable;
        }

        function paintMetric({key}) {
            const element = part(`[data-qv-metric="${key}"]`);if (!element) return;
            const meter = element.querySelector('[role="meter"]');
            const known = lastMetrics[key];
            element.classList.toggle('is-stale', Boolean(known) && (metricsStale || failed || !known.live));
            element.classList.toggle('is-unavailable', !known);
            if (!known) {
                // No reading has ever arrived: never paint a zero that was not measured.
                meter.removeAttribute('aria-valuenow');meter.setAttribute('aria-valuetext', 'Unavailable');
                meter.firstElementChild.style.width = '0%';
                element.querySelector('[data-qv-value]').textContent = '—';
                element.querySelector('[data-qv-detail]').textContent = 'Unavailable';
                return;
            }
            const percent = Math.round(known.percent);
            meter.setAttribute('aria-valuenow', String(percent));
            meter.setAttribute('aria-valuetext', `${percent}%${element.classList.contains('is-stale') ? ', last known' : ''}`);
            meter.firstElementChild.style.width = `${Math.max(0, Math.min(100, known.percent))}%`;
            element.querySelector('[data-qv-value]').textContent = `${percent}%`;
            element.querySelector('[data-qv-detail]').textContent = key === 'memory' && known.total
                ? `${bytes(known.used)} of ${bytes(known.total)}`
                : element.classList.contains('is-stale') ? 'Last known' : '';
        }

        function paintPresence() {
            const count = part('[data-qv-count]');const list = part('[data-qv-users]');if (!count || !list) return;
            if (!presence || presence.available !== true) {
                count.textContent = presence ? 'Who is online is unavailable right now.' : 'Checking who is online…';
                list.replaceChildren();return;
            }
            const users = Array.isArray(presence.users) ? presence.users : [];
            count.textContent = users.length === 1 ? '1 person online' : `${users.length} people online`;
            const shown = expanded ? users : users.slice(0, VISIBLE_USERS);
            const hidden = users.length - VISIBLE_USERS;
            list.innerHTML = shown.map(user => `<li class="agh-qv-chip${user.is_current ? ' is-you' : ''}" title="${esc(user.name)}">${user.is_current ? 'You' : esc(user.name)}</li>`).join('')
                + (hidden > 0 ? `<li><button type="button" class="agh-qv-chip agh-qv-more" data-qv-more aria-expanded="${expanded}">${expanded ? 'Show fewer' : `+${hidden} more`}</button></li>` : '');
        }

        function paint() {
            if (!card) return;
            METRICS.forEach(paintMetric);
            paintPresence();
            paintTransfers();
            part('[data-qv]')?.classList.toggle('is-degraded', failed);
            const updated = part('[data-qv-updated]');
            if (updated) updated.textContent = updatedAt === null ? '' : `Updated ${new Date(updatedAt).toLocaleTimeString()}`;
            const status = part('[data-qv-status]');
            if (status && status.textContent !== announcement) status.textContent = announcement;
        }

        // Only an availability change is announced; routine numbers never touch the live region.
        function announce(next) {
            if (next === availability) return;
            const first = availability === null;availability = next;
            if (next === 'ok') {announcement = first ? '' : 'Live updates are back.';return;}
            announcement = {
                failed: 'Live updates paused. Retrying…',
                metrics: 'Studio load is unavailable right now.',
                presence: 'Who is online is unavailable right now.',
                both: 'Studio load and who is online are unavailable right now.',
            }[next];
        }

        function render(payload) {
            failed = false;
            const metrics = payload?.metrics;
            const metricsLive = metrics?.available === true;
            metricsStale = metricsLive && metrics.stale === true;
            for (const {key} of METRICS) {
                const signal = metricsLive ? metrics[key] : null;
                if (validPercent(signal)) lastMetrics[key] = {percent:Number(signal.percent), used:signal.used_bytes ?? null, total:signal.total_bytes ?? null, live:true};
                else if (lastMetrics[key]) lastMetrics[key].live = false;
            }
            presence = payload?.presence && typeof payload.presence === 'object' ? payload.presence : {available:false, users:[]};
            const presenceLive = presence.available === true;
            announce(metricsLive && presenceLive ? 'ok' : metricsLive ? 'presence' : presenceLive ? 'metrics' : 'both');
            updatedAt = now();
            paint();
        }

        function degrade() {
            // The endpoint failed as a whole: keep both domains' last state and retry on the next tick.
            failed = true;announce('failed');paint();
        }

        async function refresh() {
            lastRefresh = now();
            const id = ++sequence;
            try {
                const payload = await request('/data/system-quickview');
                if (id < applied || !card) return;
                applied = id;render(payload);
            } catch (error) {
                if (id < applied || !card) return;
                applied = id;degrade();
            }
        }

        // One fixed-rate timer, exactly as before, so a visible card in use polls every `intervalMs` unchanged. A card
        // nobody has touched for `idleAfterMs` skips ticks until `idleIntervalMs` has passed since its last request;
        // a hidden tab never requests. Coming back (shown again, or the first input after idling) refreshes at once.
        const ACTIVITY_EVENTS = ['pointerdown', 'keydown', 'wheel', 'touchstart', 'mousemove'];
        let lastActivity = now();
        let lastRefresh = now();
        const idle = () => now() - lastActivity > idleAfterMs;
        const tick = () => {
            if (document.hidden) return;
            if (idle() && now() - lastRefresh < idleIntervalMs - intervalMs / 2) return;
            refresh();
        };
        const onVisibility = () => {
            if (document.hidden || !card) return;
            lastActivity = now();
            refresh();
        };
        const onActivity = () => {
            const wasIdle = idle();
            lastActivity = now();
            if (wasIdle && card && !document.hidden) refresh();
        };

        function mount(nextCard) {
            if (!nextCard || nextCard === card) return;
            const remount = card !== null;
            card = nextCard;skeleton();paint();
            if (remount) return;
            releaseTransfers = window.MothershipTransfers?.subscribe(state => {transfers = state;paintTransfers();}) ?? null;
            refresh();
            lastActivity = now();
            timer = setInterval(tick, intervalMs);
            document.addEventListener('visibilitychange', onVisibility);
            ACTIVITY_EVENTS.forEach(name => window.addEventListener(name, onActivity, {passive:true}));
        }

        function unmount() {
            releaseTransfers?.();releaseTransfers = null;
            if (timer !== null) {clearInterval(timer);timer = null;}
            document.removeEventListener('visibilitychange', onVisibility);
            ACTIVITY_EVENTS.forEach(name => window.removeEventListener(name, onActivity));
            // Any response still in flight now belongs to a released card.
            applied = ++sequence;
            card = null;
        }

        return {mount, refresh, unmount, render};
    }

    window.MothershipSystemQuickView = {create};
})();
