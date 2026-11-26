(function (root) {
    'use strict';
    const mediaTypes = ['all', 'images', 'videos', 'svgs', 'audio', 'models'];
    // Deep links may name one kind in the singular (`media_type=video`); map it to the chip value
    // instead of silently widening the view to "all" (QA finding F27, 2026-09-27).
    const mediaTypeAliases = {image: 'images', video: 'videos', svg: 'svgs', audios: 'audio', model: 'models'};
    function normalizeMediaType(value) {
        const key = String(value ?? '').trim().toLowerCase();
        if (mediaTypes.includes(key)) return key;
        return mediaTypeAliases[key] ?? 'all';
    }
    function normalizePage(value) {
        const page = Number(value);
        return Number.isSafeInteger(page) && page > 0 ? page : 1;
    }
    // Explorer state that names folders or search terms is per account: a shared lab PC must never
    // open the previous user's tabs, folder or search (QA finding F20). Keys carry the admin id the
    // same way the Signal widget's `mothership-signal-v1:<id>` does.
    const userScopedKeys = ['dam_explorer_tabs', 'dam_explorer_filter_state', 'dam_explorer_active_dir'];
    function explorerKey(base, userId) {
        const id = Number(userId);
        return base + ':' + (Number.isSafeInteger(id) && id > 0 ? id : 'guest');
    }
    // Drops the pre-F20 un-keyed copies: nobody can tell whose they were, so they are not migrated.
    function dropLegacyExplorerState(storage) {
        try { userScopedKeys.forEach(key => storage.removeItem(key)); } catch (e) {}
    }
    // Sign-out/sign-in page: forget every explorer key (all users) and the per-tab return handoff.
    function clearExplorerState(storage, session) {
        try {
            const keys = [];
            for (let i = 0; i < storage.length; i++) keys.push(storage.key(i));
            keys.filter(key => typeof key === 'string' && key.startsWith('dam_explorer_')).forEach(key => storage.removeItem(key));
        } catch (e) {}
        try { if (session) { session.removeItem('dam_return_dir'); session.removeItem('dam.details.siblings'); } } catch (e) {}
    }
    function locationState(search) {
        const params = new URLSearchParams(search);
        const directory = Number(params.get('directory_id') || params.get('filters[directory_id][]'));
        const explicitSearch = params.has('search') || params.has('filters[all][]');
        const seed = Number(params.get('similar_to'));
        const similarity = Number.isSafeInteger(seed) && seed > 0 ? { assetId: seed, name: (params.get('similar_name') ?? '').slice(0, 255), priorView: null } : null;
        return {
            freshSearch: params.get('search_submit') === '1',
            recent: params.get('recent') === '1',
            explicit: explicitSearch || params.has('scope') || directory > 0 || params.has('media_type') || !!similarity,
            similarity,
            scope: params.get('scope') === 'all' || (explicitSearch && !directory) ? 'all' : 'directory',
            directoryId: directory > 0 ? directory : null,
            search: params.get('search') ?? params.get('filters[all][]') ?? '',
            mediaType: normalizeMediaType(params.get('media_type')),
            // null when the url names no page, so a restored tab keeps its own.
            page: params.has('page') ? normalizePage(params.get('page')) : null,
        };
    }
    // What Back/Forward must do for the tab given the url it landed on: the folder AND the search, media type and
    // page that entry held. null when the tab already shows exactly that.
    function popStatePlan(wanted, tab) {
        if (! wanted || ! wanted.explicit || ! tab) return null;
        const wantsAll = wanted.scope === 'all' || ! wanted.directoryId;
        const sameView = wantsAll ? tab.scope === 'all' : (tab.scope !== 'all' && Number(tab.directoryId) === Number(wanted.directoryId));
        const view = { search: wanted.search ?? '', mediaType: wanted.mediaType ?? 'all', page: wanted.page ?? 1, ...(wanted.similarity ? { similarity: wanted.similarity } : {}), ...(wanted.listingView ? { listingView: wanted.listingView } : {}) };
        const sameState = (tab.search ?? '') === view.search && (tab.mediaType ?? 'all') === view.mediaType && Number(tab.page || 1) === view.page && (tab.similarity?.assetId ?? null) === (view.similarity?.assetId ?? null);
        if (sameView && sameState) return null;
        return { kind: wantsAll ? 'all' : 'directory', directoryId: wantsAll ? null : wanted.directoryId, sameView, view };
    }
    function sidebarWidth(value) {
        const width = Number(value);
        return Number.isFinite(width) && width > 0 ? Math.min(560, Math.max(220, width)) : 280;
    }
    function isValidFolderMoveTarget({ sourceId, targetId, descendantIds = [], accessibleIds = [], aclBypass = false }) {
        const source = Number(sourceId);
        const target = Number(targetId);
        const accessible = accessibleIds.map(Number);
        const descendants = descendantIds.map(Number);

        if (! source || ! target || source === target || descendants.includes(target)) return false;
        if (aclBypass) return true;

        return accessible.includes(source) && accessible.includes(target);
    }
    function edgeScrollDelta({ pointerY, top, bottom, threshold = 48, speed = 16 }) {
        if (pointerY < top + threshold) return -Math.abs(speed);
        if (pointerY > bottom - threshold) return Math.abs(speed);
        return 0;
    }
    // "Recently added" leads and is the default: a library is browsed for what just landed far more often
    // than alphabetically (operator report 2026-09-15). The server applies the same default.
    const sortOptions = [
        { value: 'created_at:desc', label: 'Recently added' },
        { value: 'created_at:asc', label: 'Oldest added' },
        { value: 'name:asc', label: 'Name A–Z' },
        { value: 'name:desc', label: 'Name Z–A' },
        { value: 'size:asc', label: 'Size smallest' },
        { value: 'size:desc', label: 'Size largest' },
        { value: 'updated_at:desc', label: 'Modified newest' },
        { value: 'updated_at:asc', label: 'Modified oldest' },
    ];
    const defaultSort = 'created_at:desc';
    // A remembered choice (localStorage) is only honoured when it is still a listed option.
    function resolveSort(value) {
        return sortOptions.some((option) => option.value === value) ? value : defaultSort;
    }
    const api = {locationState, popStatePlan, sidebarWidth, mediaTypes, normalizeMediaType, explorerKey, userScopedKeys, dropLegacyExplorerState, clearExplorerState, isValidFolderMoveTarget, edgeScrollDelta, sortOptions, defaultSort, resolveSort};
    if (typeof module !== 'undefined' && module.exports) module.exports = api;
    root.MothershipLibraryNavigation = api;
})(typeof window !== 'undefined' ? window : globalThis);
