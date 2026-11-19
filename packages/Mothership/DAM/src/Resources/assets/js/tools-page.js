(() => {
    'use strict';
    // The Tools page (dam::tools.index): the first step of the tools funnel. A tool counts as seen when half its tile has
    // been on screen for a second while the page is visible, once per visit. The event, its props and their meaning are
    // the ones Home's tool shelf sent (home.feature.exposed), so the funnel in docs/analytics-posthog.md keeps its first
    // step; only the surface says the Tools page sent it. A tile that is not a link (a tool still to be set up) cannot
    // be opened, so it is never counted.
    const VISIBLE_MS = 1000;
    // Started roots are tracked by element: the app's mount rebuilds #app from the server's markup (tool-frame.js).
    const started = (window.__mothershipToolsPageStarted ??= new WeakSet());
    const start = () => {
        const root = document.querySelector('[data-tools-page]');
        if (!root || started.has(root) || typeof IntersectionObserver !== 'function') return;
        started.add(root);
        // One live page at a time: what a replaced root listened for goes with it.
        window.__mothershipToolsPageLife?.abort();
        const life = (window.__mothershipToolsPageLife = new AbortController());
        const {signal} = life;
        const counted = new Set(), timers = new Map(), visible = new Set();
        const track = id => {
            try { window.damTrack?.('home.feature.exposed', null, {widget_id: id, cols: 1, rows: 1, visible_ms: VISIBLE_MS}, 'tools'); } catch { /* Analytics never blocks the page. */ }
        };
        const arm = tile => {
            clearTimeout(timers.get(tile));
            timers.delete(tile);
            const id = tile.dataset.toolTile;
            if (!id || counted.has(id) || !visible.has(tile) || document.hidden) return;
            timers.set(tile, setTimeout(() => {
                timers.delete(tile);
                if (signal.aborted || !tile.isConnected || !visible.has(tile) || document.hidden || counted.has(id)) return;
                counted.add(id);
                track(id);
            }, VISIBLE_MS));
        };
        const observer = new IntersectionObserver(entries => {
            for (const entry of entries) {
                if (entry.isIntersecting && entry.intersectionRatio >= 0.5) visible.add(entry.target); else visible.delete(entry.target);
                arm(entry.target);
            }
        }, {threshold: 0.5});
        root.querySelectorAll('a[data-tool-tile]').forEach(tile => observer.observe(tile));
        document.addEventListener('visibilitychange', () => visible.forEach(arm), {signal});
        signal.addEventListener('abort', () => { observer.disconnect(); timers.forEach(timer => clearTimeout(timer)); timers.clear(); }, {once: true});
    };
    // The app mounts on DOMContentLoaded (and after the app's own script injection) and rebuilds #app as it does. Start
    // on the task after it; `load` is the second chance for a mount that landed after the first start.
    const afterMount = () => setTimeout(start, 0);
    if (document.readyState !== 'loading') afterMount(); else { document.addEventListener('DOMContentLoaded', afterMount, {once: true}); window.addEventListener('load', afterMount, {once: true}); }
})();
