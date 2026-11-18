(function (root) {
    'use strict';

    // OD5: which of a shared item's files is on the page's stage. DOM-less, so tools/browser/tests/dam-share-files.test.cjs
    // runs it directly. `files` is SharedFiles::present()'s rows; nothing here ever names a file outside that list.

    function toId(value) {
        const id = Number.parseInt(String(value ?? ''), 10);

        return Number.isFinite(id) && id > 0 ? id : null;
    }

    function list(files) {
        return Array.isArray(files) ? files : [];
    }

    function find(files, id) {
        const wanted = toId(id);

        return wanted === null ? null : (list(files).find(file => file && toId(file.attachment_id) === wanted) || null);
    }

    function mainOf(files) {
        const all = list(files);

        return all.find(file => file && file.is_main === true) || all[0] || null;
    }

    function initialId(files, search) {
        let asked = null;

        try {
            asked = new URLSearchParams(String(search || '')).get('file');
        } catch (error) {
            asked = null;
        }

        const picked = find(files, asked) || mainOf(files);

        return picked ? toId(picked.attachment_id) : null;
    }

    function isChecksum(value) {
        return typeof value === 'string' && /^[0-9a-f]{64}$/.test(value);
    }

    // A pinned link names one exact file: its slot (`file`), the AssetFile row (`file_id`) and the FULL checksum.
    // Any one of the pin parts present makes the link strict, so a half-written or stale pin can never fall back to the
    // default stage or to another file: `unavailable` is its own state. A link with neither is the legacy `?file=` link.
    function initialSelection(files, search) {
        let params = null;

        try {
            params = new URLSearchParams(String(search || ''));
        } catch (error) {
            params = null;
        }

        const fileId = params ? params.get('file_id') : null;
        const checksum = params ? params.get('checksum') : null;

        if (fileId === null && checksum === null) {
            return {id: initialId(files, search), pinned: false, unavailable: false};
        }

        const row = find(files, params.get('file'));
        const valid = !!row && !!row.page_url && toId(fileId) !== null && toId(row.file_id) === toId(fileId) && isChecksum(checksum) && row.checksum === checksum;

        return {id: valid ? toId(row.attachment_id) : null, pinned: true, unavailable: !valid};
    }

    function stage(files, id, failedId, options) {
        const main = mainOf(files);
        const file = find(files, id);
        const strict = options || {};

        if (strict.unavailable) {
            return {mode: 'unavailable', id: null, url: null, name: '', thumb: null, download: null, message: 'file-unavailable'};
        }

        // A pinned link keeps the representative on its own pinned page; only an unpinned main row is the legacy stage.
        const pinnedMain = strict.pinned === true && file && file === main && file.page_url;

        if (!file || (file === main && !pinnedMain)) {
            return {
                mode: 'main',
                id: main ? toId(main.attachment_id) : null,
                url: null,
                name: main ? String(main.name || '') : '',
                thumb: main ? (main.thumb_url || null) : null,
                download: main ? (main.download_url || null) : null,
                message: null,
            };
        }

        const base = {id: toId(file.attachment_id), name: String(file.name || ''), thumb: file.thumb_url || null, download: file.download_url || null};

        if (file.page_url) {
            return Object.assign(base, {mode: 'page', url: file.page_url, message: null});
        }

        if (['image', 'video', 'audio', 'pdf'].includes(file.preview_kind) && file.preview_url && toId(failedId) !== base.id) {
            return Object.assign(base, {mode: file.preview_kind, url: file.preview_url, message: null});
        }

        return Object.assign(base, {mode: 'fallback', url: null, message: base.download ? 'no-preview' : 'downloads-off'});
    }

    // What the strip under the stage reads: the file on stage, by its own row (name, type, pre-formatted size), the same
    // fallback to the main file as stage(). `unavailable` (a pinned file that no longer matches, or a page that is gone)
    // has no file on stage, so it reads nothing and can never show another file's values.
    function info(files, id, options) {
        const none = {name: '', type: '', size: ''};
        const file = (options && options.unavailable) ? null : (find(files, id) || mainOf(files));

        return file ? {name: String(file.name || ''), type: String(file.extension || ''), size: String(file.size_label || '')} : none;
    }

    // The URL for the displayed selection: a page member carries its exact pin (`file`, `file_id`, `checksum`) so the
    // address can be copied and still names this file; the unpinned main file is the plain address. `pinned` keeps a
    // strictly pinned representative pinned.
    function urlFor(href, files, id, pinned) {
        const url = new URL(String(href));
        const file = find(files, id);
        const keep = !!file && !!file.page_url && (file !== mainOf(files) || pinned === true);

        ['file', 'file_id', 'checksum'].forEach(name => url.searchParams.delete(name));

        if (keep) {
            url.searchParams.set('file', String(toId(file.attachment_id)));
            url.searchParams.set('file_id', String(toId(file.file_id)));
            url.searchParams.set('checksum', String(file.checksum));
        } else if (file && file !== mainOf(files)) {
            url.searchParams.set('file', String(toId(file.attachment_id)));
        }

        return url.pathname + url.search + url.hash;
    }

    // What a probe of a page URL means: a 2xx is ready, 409 is still being prepared, 422 could not be prepared, and
    // anything else (404 for a revised, moved or removed file, a closed link, a network error) is unavailable.
    function pageVerdict(status) {
        const code = Number(status);

        if (code >= 200 && code < 300) return 'ready';
        if (code === 409) return 'preparing';
        if (code === 422) return 'failed';

        return 'unavailable';
    }

    const api = {toId, find, mainOf, initialId, initialSelection, stage, info, urlFor, pageVerdict, isChecksum};
    if (typeof module !== 'undefined' && module.exports) module.exports = api;
    root.DamShareFilesState = api;
})(typeof window !== 'undefined' ? window : globalThis);
