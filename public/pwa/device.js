/* Fold-aware layout for The Mothership: progressive enhancement only.
 *
 * Safari 27.1 on iPhone Duo previews the Viewport Segments and Device Posture APIs behind feature flags, and
 * Chromium ships both (WebKit "Features for Safari 27.1", 2026-10-23). When the browser reports exactly two
 * segments this marks <html data-fold="columns|rows"> and publishes each segment's box and the gap between them
 * as CSS custom properties; device.css lays the Library, the preview and the share page out around the fold from
 * those. Every other browser, and Duo with the flags off, gets nothing here: the ordinary responsive layout.
 * No user-agent or device checks; the segment geometry is the only input, and it is re-read on resize, on a
 * posture change and when the segment media queries change. Elements marked [data-fold-split] also get --fold-start (columns: the width of their content box
 * on the leading side of the fold) or --fold-top (rows: the height above it), measured from their own box; elements
 * marked [data-fold-wrap] get --fold-rest (columns: how far the box runs past the fold) so their content wraps first.
 */
(() => {
    if (window.mothershipDevice) return;
    window.mothershipDevice = true;

    const root = document.documentElement;
    const VARS = ['left', 'top', 'width', 'height', 'right', 'bottom'];
    let fold = null;
    let queued = false;
    let observer = null;

    const px = value => `${Math.max(0, Math.round(value))}px`;

    const readFold = () => {
        let segments;
        try { segments = window.viewport?.segments; } catch (_) { segments = null; }
        if (!segments || segments.length !== 2) return null;
        const [a, b] = segments;
        // Side by side (book) when the second segment starts at or after the first one's right edge; else stacked (laptop).
        if (b.left >= a.right) return { mode: 'columns', a, b, gap: b.left - a.right };
        if (b.top >= a.bottom) return { mode: 'rows', a, b, gap: b.top - a.bottom };

        return null;
    };

    const clearRoot = () => {
        delete root.dataset.fold;
        root.style.removeProperty('--fold-gap');
        for (const seg of ['a', 'b', 'trailing']) {
            for (const name of VARS) root.style.removeProperty(`--seg-${seg}-${name}`);
        }
    };

    const measureSplits = () => {
        for (const el of document.querySelectorAll('[data-fold-split], [data-fold-wrap]')) {
            el.style.removeProperty('--fold-start');
            el.style.removeProperty('--fold-top');
            el.style.removeProperty('--fold-rest');
            if (!fold) continue;
            const box = el.getBoundingClientRect();
            const style = getComputedStyle(el);
            if (fold.mode === 'columns') {
                // [data-fold-wrap]: the stretch of the box from the fold to its trailing edge, which its content wraps before.
                const rtl = style.direction === 'rtl';
                const beforeFold = rtl ? box.right > fold.b.left : box.left < fold.a.right;
                el.style.setProperty('--fold-rest', px(beforeFold ? (rtl ? fold.b.left - box.left : box.right - fold.a.right) : 0));
                // The leading track runs from the content box's leading edge to the fold, in the element's own direction.
                const start = style.direction === 'rtl'
                    ? (box.right - parseFloat(style.paddingRight)) - fold.b.left
                    : fold.a.right - (box.left + parseFloat(style.paddingLeft));
                el.style.setProperty('--fold-start', px(start));
            } else {
                el.style.setProperty('--fold-top', px(fold.a.bottom - (box.top + parseFloat(style.paddingTop))));
            }
        }
    };

    // A list page ([data-fold-list]) keeps the row being read in view when the pose changes: folding moves things a little
    // and never rearranges them (HIG). Book pose sets the page head beside the rows, which stops the browser's own scroll
    // anchoring, so the first row in view is noted just before the switch and put back at the same offset after it.
    const holdRow = () => {
        const list = document.querySelector('[data-fold-list]');
        let scroller = list?.parentElement;
        while (scroller && !/(auto|scroll)/.test(getComputedStyle(scroller).overflowY)) scroller = scroller.parentElement;
        if (!scroller) return null;
        const offset = row => row.getBoundingClientRect().top - scroller.getBoundingClientRect().top;
        const row = [...list.querySelectorAll('.bridge-list-row, .bridge-review-item')].find(item => offset(item) + item.offsetHeight > 0);
        if (!row) return null;
        const before = offset(row);

        // A frame later, so it tops up whatever the browser's own anchoring managed instead of adding to it.
        return () => requestAnimationFrame(() => { scroller.scrollTop += offset(row) - before; });
    };

    const apply = () => {
        queued = false;
        const previous = fold?.mode ?? null;
        fold = readFold();
        const changed = (fold?.mode ?? null) !== previous;
        const restoreRow = changed ? holdRow() : null;
        // Layouts that choose a column count in script (Home) re-render when the pose changes.
        if (changed) dispatchEvent(new CustomEvent('mothership:fold', { detail: { mode: fold?.mode ?? null } }));
        if (!fold) {
            clearRoot();
            measureSplits();
            restoreRow?.();
            observer?.disconnect();
            observer = null;

            return;
        }
        root.dataset.fold = fold.mode;
        root.style.setProperty('--fold-gap', px(fold.gap));
        for (const [name, rect] of [['a', fold.a], ['b', fold.b]]) {
            for (const key of VARS) root.style.setProperty(`--seg-${name}-${key}`, px(rect[key]));
        }
        // Book pose: the trailing segment is the one a dialog keeps when the device closes (right in LTR, left in RTL).
        const trailing = fold.mode === 'columns' && root.dir === 'rtl' ? fold.a : fold.b;
        for (const key of VARS) root.style.setProperty(`--seg-trailing-${key}`, px(trailing[key]));
        measureSplits();
        restoreRow?.();
        // Vue renders the Library and dialogs after this runs, so split targets that appear later are measured too.
        if (!observer && 'MutationObserver' in window) {
            observer = new MutationObserver(schedule);
            observer.observe(document.body || root, { childList: true, subtree: true });
        }
    };

    function schedule() {
        if (queued) return;
        queued = true;
        requestAnimationFrame(apply);
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', schedule, { once: true });
    else schedule();
    addEventListener('resize', schedule);
    addEventListener('load', schedule, { once: true });
    try { navigator.devicePosture?.addEventListener('change', schedule); } catch (_) { /* Posture is a hint only. */ }
    // Safari on iPhone Duo fires resize and the posture change before it updates the segments, so a fold, an unfold or a
    // close on an open page would keep the old fold. The segment media queries change after the segments do. The lists
    // are held on the global so they are never collected while they listen.
    window.mothershipDevice = ['(horizontal-viewport-segments: 2)', '(vertical-viewport-segments: 2)'].map(query => {
        const list = matchMedia(query);
        try { list.addEventListener('change', schedule); } catch (_) { /* No segment media features here. */ }

        return list;
    });
})();
