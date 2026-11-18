(function (root) {
    'use strict';

    var STORAGE_KEY = 'dam.details.siblings';
    var MAX_AGE_MS = 60 * 60 * 1000; // 1 hour: a much older list almost certainly no longer
    // matches what is on screen (search/filter/sort changed, page reloaded, etc), so falling
    // back to the server ids is safer than trusting it.

    function normalizeIds(ids) {
        return (Array.isArray(ids) ? ids : [])
            .map(Number)
            .filter(id => Number.isFinite(id));
    }

    // The `directory_id` value is one of three distinct scopes (see AssetNeighbours' docblock,
    // which this mirrors): a numeric id string for an explicit browsed folder, the literal "all"
    // for the Library's all/search scope, or null for "no scope signal at all". Never invented —
    // a caller that does not know its scope passes null through rather than guessing.
    function normalizeDirectoryId(directoryId) {
        if (directoryId === 'all') return 'all';
        const value = Number(directoryId);

        return Number.isFinite(value) && value > 0 ? String(value) : null;
    }

    // Called by the Library (grid/list/gallery, and the lightbox's "Open details" button) right
    // before navigating to an asset's details page, so the details page's chevrons can walk the
    // SAME visible order the user came from instead of the server's directory/created_at
    // fallback order, and so the same scope (folder or all/search) is preserved across hops.
    //
    // `total` and `offset` place the visible page inside the whole result set, so the counter
    // reads "12 of 59" rather than "2 of 50" (QA finding F14). `returnUrl` is the Library view the
    // user left — scope, search, media type, folder and page — which the details page's Back link
    // returns to (QA finding F13); it defaults to the current location.
    function writeSiblings({siblings, scopeLabel, directoryId, total, offset, returnUrl} = {}) {
        try {
            const here = typeof location !== 'undefined' ? location.pathname + location.search : null;
            sessionStorage.setItem(STORAGE_KEY, JSON.stringify({
                siblings: normalizeIds(siblings),
                scopeLabel: scopeLabel || null,
                directoryId: normalizeDirectoryId(directoryId),
                total: normalizeCount(total),
                offset: normalizeCount(offset),
                returnUrl: normalizeReturnUrl(returnUrl ?? here),
                at: Date.now(),
            }));
        } catch (e) {
            // sessionStorage unavailable (private mode, quota, disabled) — the details page
            // falls back to the server-computed ids, same as if nothing had been written.
        }
    }

    function normalizeCount(value) {
        const count = Number(value);

        return Number.isSafeInteger(count) && count >= 0 ? count : null;
    }

    // Only a same-origin path is ever handed back to a Back link: a protocol-relative or absolute
    // url, or anything unparsable, is dropped so the link keeps its server default.
    // The value is resolved the way a browser resolves an href (so `/\host` or a control
    // character cannot smuggle in another host) and only its path and query are kept.
    function normalizeReturnUrl(value) {
        if (typeof value !== 'string' || ! value.startsWith('/') || value.length > 2048) return null;
        if (/[\\\u0000-\u001f\u007f]/.test(value)) return null;
        const origin = typeof location !== 'undefined' && location.origin ? location.origin : 'https://origin.invalid';
        let url;
        try {
            url = new URL(value, origin);
        } catch (e) {
            return null;
        }

        return url.origin === origin ? url.pathname + url.search : null;
    }

    // The "n of N" pair for the details page: the position inside the visible page shifted by the
    // page's offset, over the whole result count when the Library supplied them.
    function resultPosition({position, total, stored} = {}) {
        if (position === null || position === undefined) return {position: null, total: null};
        const offset = stored && stored.offset !== null && stored.offset !== undefined ? stored.offset : 0;
        const absolute = offset + position;
        const fullTotal = stored && stored.total !== null && stored.total !== undefined && stored.total >= absolute ? stored.total : null;

        return fullTotal === null ? {position, total} : {position: absolute, total: fullTotal};
    }

    // Replace context keys rather than append them: duplicate file/nav values and a hash before
    // the query otherwise make reload select a different file. Undefined leaves a key alone;
    // null explicitly clears it (for example, when moving to another Library result).
    function withFileContext({url, fileId, directoryId, nav} = {}) {
        if (typeof url !== 'string' || ! url) return url;
        const hashAt = url.indexOf('#');
        const hash = hashAt < 0 ? '' : url.slice(hashAt);
        const beforeHash = hashAt < 0 ? url : url.slice(0, hashAt);
        const queryAt = beforeHash.indexOf('?');
        const path = queryAt < 0 ? beforeHash : beforeHash.slice(0, queryAt);
        const params = new URLSearchParams(queryAt < 0 ? '' : beforeHash.slice(queryAt + 1));
        if (nav !== undefined) {
            params.delete('nav');
            if (nav === true || nav === 1 || nav === '1') params.set('nav', '1');
        }
        if (directoryId !== undefined) {
            params.delete('directory_id');
            const directory = normalizeDirectoryId(directoryId);
            if (directory !== null) params.set('directory_id', directory);
        }
        if (fileId !== undefined) {
            const id = Number(fileId);
            params.delete('file');
            if (Number.isSafeInteger(id) && id > 0) params.set('file', String(id));
        }
        const query = params.toString().replace(/=(&|$)/g, '$1');

        return path + (query ? '?' + query : '') + hash;
    }

    // Appends the `nav=1` marker the details page looks for to know a siblings list may be
    // waiting in sessionStorage, and (when known) the exact `directory_id` scope, without
    // disturbing any query string already on the url.
    function withNavMarker(url, directoryId) {
        return withFileContext({url, nav: true, directoryId: normalizeDirectoryId(directoryId) ?? undefined});
    }

    // Appends ONLY the `directory_id` scope, with no `nav=1` marker — for a hop that has no
    // siblings list active (so there is nothing in sessionStorage worth re-reading on reload)
    // but still needs the scope itself preserved across a reload at the pushed url.
    function withDirectoryId(url, directoryId) {
        if (typeof url !== 'string' || ! url) return url;
        const normalizedDirectoryId = normalizeDirectoryId(directoryId);
        if (normalizedDirectoryId === null) return url;

        return withFileContext({url, directoryId: normalizedDirectoryId});
    }

    // Reads the stored siblings list back, discarding it when missing, unparsable, empty, or
    // older than maxAgeMs — a stale or malformed list is worse than falling back to server ids.
    function readSiblings({maxAgeMs = MAX_AGE_MS} = {}) {
        let raw;
        try {
            raw = sessionStorage.getItem(STORAGE_KEY);
        } catch (e) {
            return null;
        }
        if (! raw) return null;

        let parsed;
        try {
            parsed = JSON.parse(raw);
        } catch (e) {
            return null;
        }

        const siblings = normalizeIds(parsed && parsed.siblings);
        const at = Number(parsed && parsed.at);

        if (! siblings.length || ! Number.isFinite(at) || (Date.now() - at) > maxAgeMs) {
            return null;
        }

        return {
            siblings,
            scopeLabel:  (parsed && parsed.scopeLabel) || null,
            directoryId: normalizeDirectoryId(parsed && parsed.directoryId),
            total:       normalizeCount(parsed && parsed.total),
            offset:      normalizeCount(parsed && parsed.offset),
            returnUrl:   normalizeReturnUrl(parsed && parsed.returnUrl),
        };
    }

    const api = {writeSiblings, readSiblings, withFileContext, withNavMarker, withDirectoryId, normalizeDirectoryId, normalizeReturnUrl, resultPosition};
    if (typeof module !== 'undefined' && module.exports) module.exports = api;
    root.MothershipDetailsNavigation = api;
})(typeof window !== 'undefined' ? window : globalThis);
