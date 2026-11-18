(function (root) {
    'use strict';

    function normalizeIds(ids) {
        return (Array.isArray(ids) ? ids : [])
            .map(Number)
            .filter(id => Number.isFinite(id));
    }

    function normalizeId(id) {
        if (id == null || id === '') return null;
        const value = Number(id);
        return Number.isFinite(value) ? value : null;
    }

    // Resolves the previous/next asset id to navigate the Library lightbox to. When the caller
    // supplied the visible on-screen order (siblings, e.g. the current grid/list/gallery page)
    // and the currently open asset is present in it, navigation walks that order and reports a
    // "position of total". Otherwise it falls back to the server-computed neighbours the show()
    // response already returns (previousAssetId/nextAssetId), with no position/total (the server
    // walk ignores the visible sort/search/page, so a count would be misleading). Either way,
    // reaching either end of the known order returns null rather than wrapping around.
    function neighbours({siblings, currentId, previousAssetId, nextAssetId} = {}) {
        const ids = normalizeIds(siblings);
        const current = normalizeId(currentId);

        if (ids.length && current !== null) {
            const index = ids.indexOf(current);
            if (index !== -1) {
                return {
                    previousId: index > 0 ? ids[index - 1] : null,
                    nextId: index < ids.length - 1 ? ids[index + 1] : null,
                    position: index + 1,
                    total: ids.length,
                };
            }
        }

        return {
            previousId: normalizeId(previousAssetId),
            nextId: normalizeId(nextAssetId),
            position: null,
            total: null,
        };
    }

    function memberNeighbours({files, assetId, fileId} = {}) {
        const own = (Array.isArray(files) ? files : []).filter(file => Number(file.asset_id) === Number(assetId));
        const index = own.findIndex(file => fileId != null ? Number(file.id) === Number(fileId) : file.is_representative);
        return {
            previous: index > 0 ? own[index - 1] : null,
            next: index >= 0 && index < own.length - 1 ? own[index + 1] : null,
            position: index >= 0 ? index + 1 : null,
            total: own.length,
        };
    }

    function restoreOrigin({origin, document: doc = root.document, window: win = root} = {}) {
        if (! origin || ! doc?.querySelector) return false;
        const fallback = origin.kind === 'files'
            ? doc.querySelector('[data-dam-files-panel] [data-dam-files-heading]') || doc.querySelector('[data-dam-files-panel]')
            : origin.kind === 'chooser'
                ? doc.querySelector('[data-dam-stack-fan-heading]') || doc.querySelector('[data-dam-stack-fan] [role="listbox"]')
                : doc.querySelector('[data-dam-asset-open][data-dam-asset-id="' + Number(origin.assetId) + '"]') || doc.querySelector('main h1, h1');
        const trigger = origin.trigger;
        const usable = trigger?.isConnected && trigger !== doc.body && trigger !== doc.documentElement
            && typeof trigger.focus === 'function' && ! trigger.disabled
            && (trigger.tabIndex >= 0 || trigger.hasAttribute?.('tabindex'))
            && (! trigger.getClientRects || trigger.getClientRects().length > 0);
        const target = usable ? trigger : fallback;
        win.scrollTo?.({top: origin.scrollTop || 0, behavior: 'instant'});
        if (! target) return false;
        if (! target.hasAttribute?.('tabindex') && ! /^(BUTTON|A|INPUT|SELECT|TEXTAREA)$/.test(target.tagName)) target.setAttribute?.('tabindex', '-1');
        target.focus?.({preventScroll: true});
        return true;
    }

    const api = {neighbours, memberNeighbours, restoreOrigin};
    if (typeof module !== 'undefined' && module.exports) module.exports = api;
    root.MothershipPreviewNavigation = api;
})(typeof window !== 'undefined' ? window : globalThis);
