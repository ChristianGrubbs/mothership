(function (root) {
    'use strict';
    function isBlockedDelete(error) {
        const analysis = error?.response?.data?.analysis;
        return error?.response?.status === 422 && analysis !== null && typeof analysis === 'object';
    }
    function blockedCount(analysis) {
        if (Array.isArray(analysis?.blocking)) return analysis.blocking.length ? 1 : 0;
        return Object.values(analysis || {}).filter(entry => Array.isArray(entry?.blocking) && entry.blocking.length > 0).length;
    }
    function dragPayload({ assetId, selectedIds = [] }) {
        return { type: 'dam-asset', id: assetId, ids: selectedIds.some(id => Number(id) === Number(assetId)) ? [...selectedIds] : [assetId] };
    }
    // Explorer drags: a drag that starts on a selected item carries the whole selection (files as
    // `ids`, folders as `dirIds`) so it relocates together; an unselected item still drags alone.
    function selectionDragFields({ id, type, selection = [] }) {
        const inSelection = selection.some(item => Number(item.id) === Number(id) && item.type === type);
        if (!inSelection || selection.length < 2) return {};
        return {
            ids: selection.filter(item => item.type === 'asset').map(item => item.id),
            dirIds: selection.filter(item => item.type === 'directory').map(item => item.id),
        };
    }
    // True for an explorer drag carrying more than one item: its origin tab owns the move, so the
    // selection it clears and the listings it refreshes are the right ones wherever it is dropped.
    function isSelectionDrag(payload) {
        return Boolean(payload?.tabId) && (payload.ids?.length ?? 0) + (payload.dirIds?.length ?? 0) > 1;
    }
    function parseDropPayload(dataTransfer) {
        if (!dataTransfer || Array.from(dataTransfer.types || []).includes('Files')) return null;
        try {
            const payload = JSON.parse(dataTransfer.getData('application/json'));
            return payload && typeof payload === 'object' && !Array.isArray(payload) ? payload : null;
        } catch { return null; }
    }
    async function deleteWithConfirmation({ request, emitter, message, onBlocked = () => {} }) {
        try { return await request(false); } catch (error) {
            if (!isBlockedDelete(error)) throw error;
            onBlocked();
            const blocked = blockedCount(error.response.data.analysis);
            // The message is the pipe-form one|other string, so the count reads as a real plural.
            const forms = String(message ?? '').split('|');
            const template = blocked === 1 ? forms[0] : forms[forms.length > 1 ? 1 : 0];
            const agreed = await new Promise(resolve => emitter.emit('open-delete-modal', {
                message: template.replace(':count', blocked),
                agree: () => resolve(true), disagree: () => resolve(false),
            }));
            return agreed ? request(true) : null;
        }
    }
    // The server's own cap (AccessController::MAX_MOVE_PREVIEW_IDS): one cap for the preview and the
    // move alike, under `lists` (Codex review of PR #75, finding 6), so a selection the preview could
    // never confirm is refused here, before any request, rather than reaching the server as an
    // uncapped, unconfirmed move.
    const MOVE_PREVIEW_MAX_IDS = 500;
    async function moveAssets({ axios, url, statusUrl, ids, targetId, previewUrl = null, confirmMove = null, onPreviewFailed = null }) {
        if (previewUrl && confirmMove) {
            const proceed = await guardedMovePreview({ axios, previewUrl, type: 'asset', ids, target: targetId, confirmMove, onPreviewFailed });
            if (!proceed) return null;
        }
        await axios.post(url, { asset_ids: ids, directory_ids: [], target_directory_id: targetId });
        for (let attempt = 0; attempt < 150; attempt++) {
            await new Promise(resolve => setTimeout(resolve, 2000));
            const { data } = await axios.get(statusUrl);
            if (data.status === 'completed') return data;
            if (data.status === 'failed') throw new Error(data.message || 'Move failed.');
        }
        throw new Error('Move status timed out. Refresh to check the result.');
    }
    // Built by hand rather than via axios params, so `ids` is always sent as `ids[]=1&ids[]=2` — axios's
    // own array serialization repeats the bare key (`ids=1&ids=2`), which PHP never parses as an array.
    function movePreviewQuery({ type, ids, target }) {
        const params = new URLSearchParams();
        params.set('type', type);
        ids.forEach(id => params.append('ids[]', id));
        params.set('target', target);
        return params.toString();
    }
    // Asks the move-preview endpoint how many items would change who can see them, then — only when
    // that count is above zero — asks confirmMove() (the caller's own open-confirm-modal wiring) before
    // resolving true. Throws on any preview failure (network, 403, 404, 422, the cap): under `lists` a
    // preview that could not be confirmed must stop the move, never proceed as if nothing needed
    // confirming (Codex review of PR #75, finding 6 — the previous behaviour let an over-cap or refused
    // selection through to an uncapped, unconfirmed POST).
    async function confirmMovePreview({ axios, previewUrl, type, ids, target, confirmMove }) {
        const { data } = await axios.get(`${previewUrl}?${movePreviewQuery({ type, ids, target })}`);
        const count = (data.folders || 0) + (data.files || 0);
        return count > 0 ? confirmMove(count) : true;
    }
    // confirmMovePreview(), refusing locally before any request when the selection already exceeds the
    // server's own cap, and surfacing (via onPreviewFailed) rather than swallowing every other failure —
    // the caller decides how to show the message and restore any optimistic state.
    async function guardedMovePreview({ axios, previewUrl, type, ids, target, confirmMove, onPreviewFailed = null }) {
        if (ids.length > MOVE_PREVIEW_MAX_IDS) {
            onPreviewFailed?.(new Error(`A move can check at most ${MOVE_PREVIEW_MAX_IDS} items at once; select fewer and try again.`));
            return false;
        }
        try {
            return await confirmMovePreview({ axios, previewUrl, type, ids, target, confirmMove });
        } catch (error) {
            onPreviewFailed?.(error);
            return false;
        }
    }
    const api = {
        isBlockedDelete, blockedCount, dragPayload, selectionDragFields, isSelectionDrag, parseDropPayload, deleteWithConfirmation, moveAssets,
        confirmMovePreview, guardedMovePreview, movePreviewQuery, MOVE_PREVIEW_MAX_IDS,
    };
    if (typeof module !== 'undefined' && module.exports) module.exports = api;
    root.MothershipFileOps = api;
})(typeof window !== 'undefined' ? window : globalThis);
