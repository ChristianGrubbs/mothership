// Fonts & brushes manager page (ADR 0015, PR3): the pure helpers dam::creative-hub.resources inlines and its Vue
// component calls. No DOM and no network here, so tests/js/creative-hub-resources.test.mjs runs it as is.
(function (root) {
    'use strict';

    const KINDS = ['font', 'brush'];
    const VISIBILITIES = ['private', 'everyone', 'groups'];
    // The reason codes HubResourceController answers with (HubResourceRefused plus share_not_allowed).
    const REASONS = ['empty', 'too_large', 'forbidden', 'unknown_format', 'unparsed', 'collection', 'bad_brush', 'quota_bytes', 'license_required', 'bad_visibility', 'not_owner', 'share_not_allowed'];

    function fill(template, values) {
        return String(template ?? '').replace(/:([a-z_]+)/gi, (match, key) => (values && key in values ? String(values[key]) : match));
    }

    // Binary units, as the server words its caps ("The limit is 20 MiB").
    function formatBytes(bytes) {
        const n = Math.max(0, Number(bytes) || 0);
        if (n < 1024) return `${n} B`;
        const units = ['KiB', 'MiB', 'GiB', 'TiB'];
        let value = n / 1024;
        let unit = 0;
        while (value >= 1024 && unit < units.length - 1) {
            value /= 1024;
            unit++;
        }
        const shown = value >= 100 ? Math.round(value) : Math.round(value * 10) / 10;
        return `${shown} ${units[unit]}`;
    }

    /**
     * One failed upload as the row shows it: the server's own translated sentence for a refusal it explained (422 or
     * 403 with a reason code), otherwise a translated line for what went wrong on the way (a proxy's 413, the
     * throttle's 429, an ended session, no answer at all).
     */
    function describeUploadFailure({ error, text }) {
        const response = error?.response;
        const status = Number(response?.status) || 0;
        const data = response && typeof response.data === 'object' && response.data !== null ? response.data : {};
        const reason = typeof data.reason === 'string' ? data.reason : '';
        const message = typeof data.message === 'string' ? data.message.trim() : '';

        if ((status === 422 || status === 403) && REASONS.includes(reason)) {
            return { reason, message: message || text.reasons?.[reason] || text.refused };
        }
        if (status === 413) return { reason: 'too_large', message: text.requestTooLarge };
        if (status === 429) return { reason: 'throttled', message: text.throttled };
        if (status === 401 || status === 419) return { reason: 'session', message: text.session };
        if (status === 422) return { reason: 'invalid', message: message || text.refused };
        if (! response) return { reason: 'network', message: text.network };

        return { reason: 'error', message: message || text.unknown };
    }

    /** What the server read from a stored file, in one line: "Inter · Bold · 700 · OTF" or "Inks · PhotoCraft brush · ABR". */
    function reading(resource, text) {
        if (! resource) return '';
        const format = String(resource.format || '').toUpperCase();
        if (resource.kind === 'brush') return [resource.name, text.brush, format].filter(Boolean).join(' · ');

        return [resource.family || resource.name, resource.subfamily, resource.weight, format].filter((part) => part !== null && part !== undefined && part !== '').join(' · ');
    }

    /** The facts line under a font or brush name. */
    function facts(resource, text) {
        const format = String(resource.format || '').toUpperCase();
        // A slanted face's subfamily usually says so already ("Bold Italic", "Oblique", "Gras Italique"); the style is
        // added only when it does not.
        const style = resource.style && resource.style !== 'normal' && ! /ital|obli|kursiv|cursiv/i.test(String(resource.subfamily || ''))
            ? (text.styles?.[resource.style] || resource.style)
            : null;
        const parts = resource.kind === 'brush'
            ? [text.brush, format]
            : [resource.subfamily, resource.weight, style, resource.variable ? text.variable : null, format];

        return [...parts, formatBytes(resource.size)].filter((part) => part !== null && part !== undefined && part !== '').join(' · ');
    }

    function quota(usage) {
        const used = Math.max(0, Number(usage?.bytes) || 0);
        const limit = Math.max(0, Number(usage?.limit) || 0);
        const percent = limit > 0 ? Math.min(100, (used / limit) * 100) : 0;

        return { used, limit, percent, tone: percent >= 95 ? 'full' : percent >= 80 ? 'high' : 'ok' };
    }

    /** Who sees it, in words: Only me, Everyone, or the group names (two, then "and N more"). */
    function visibilityLabel(resource, groups, text) {
        if (resource.visibility === 'everyone') return text.visibility.everyone;
        if (resource.visibility !== 'groups') return text.visibility.private;
        const byId = new Map((groups || []).map((group) => [group.id, group.name]));
        const names = (resource.group_ids || []).map((id) => byId.get(id)).filter(Boolean);
        if (! names.length) return text.visibility.groups;
        if (names.length <= 2) return fill(text.visibility.groupsNamed, { names: names.join(', ') });

        return fill(text.visibility.groupsMore, { names: names.slice(0, 2).join(', '), count: names.length - 2 });
    }

    /**
     * The PATCH body for a sharing change, or the reason it cannot be sent yet. Everyone and Groups need the license
     * attestation and a note (the API refuses either alone with license_required); Groups needs one group at least.
     */
    function shareRequest({ visibility, groupIds, licenseNote, attested }) {
        if (! VISIBILITIES.includes(visibility)) return { error: 'bad_visibility' };
        if (visibility === 'private') return { payload: { visibility } };
        const note = String(licenseNote ?? '').trim();
        const groups = [...new Set((groupIds || []).map(Number).filter((id) => Number.isInteger(id) && id > 0))];
        if (visibility === 'groups' && ! groups.length) return { error: 'bad_visibility' };
        if (! note || attested !== true) return { error: 'license_required' };
        const payload = { visibility, license_note: note, license_attested: true };
        if (visibility === 'groups') payload.group_ids = groups;

        return { payload };
    }

    /**
     * The PATCH body that "Share selected" sends for one resource: Everyone keeps the resource's own license note, or
     * records $note, and the bulk action is the attestation (ADR 0015 amendment 2026-10-20); private needs neither.
     */
    function bulkShareRequest({ resource, visibility, note }) {
        if (visibility === 'private') return { visibility };
        if (visibility !== 'everyone') return null;

        return { visibility, license_note: String(resource?.license_note ?? '').trim() || String(note ?? '').trim(), license_attested: true };
    }

    /**
     * The bytes URL to load a preview face from, or null. Only a URL on this page's own origin is ever fetched: the
     * bytes route refuses other sites, and nothing on this page may pull a font from anywhere else.
     */
    function sameOriginUrl(url, origin) {
        if (typeof url !== 'string' || ! url || typeof origin !== 'string' || ! origin) return null;
        try {
            const parsed = new URL(url, origin);
            if (parsed.origin !== new URL(origin).origin) return null;
            if (parsed.protocol !== 'https:' && parsed.protocol !== 'http:') return null;

            return parsed.href;
        } catch (_) {
            return null;
        }
    }

    /** A CSS family name for one resource's preview face, unique per blob and never a name the page already uses. */
    function faceName(resource) {
        const sha = String(resource?.sha256 || '').toLowerCase().replace(/[^0-9a-f]/g, '').slice(0, 16);

        return sha ? `hub-resource-${sha}` : null;
    }

    function byKind(resources) {
        const lists = { font: [], brush: [] };
        for (const resource of resources || []) if (KINDS.includes(resource.kind)) lists[resource.kind].push(resource);

        return lists;
    }

    /**
     * Numbers each change sent for a resource, so a saved row only replaces that resource's row when it is newer than
     * the last saved row applied for it: an older PATCH that settles after a newer successful one never puts its stale
     * row back, while a newer one that failed (and so changed nothing) never holds back an older success.
     */
    function mutationLog() {
        const applied = new Map();
        let sequence = 0;

        return {
            begin() {
                sequence += 1;

                return sequence;
            },
            /** Whether a success with this ticket is the newest for the resource; if so it is recorded as applied. */
            claim(resourceId, ticket) {
                if (ticket <= (applied.get(resourceId) ?? 0)) return false;
                applied.set(resourceId, ticket);

                return true;
            },
        };
    }

    /**
     * Sends the edit the page holds in host[field] (an object with busy and error). That object is the operation's
     * token: once the request settles, the edit, and anything onCurrent closes, is only touched while it is still the
     * page's current one, so a Cancel, a closed dialog, or an edit opened meanwhile is never thrown on or overwritten.
     * The saved row reaches onSaved only when it is newer than the last one applied for that resource (mutationLog()).
     *
     * @returns {Promise<'saved'|'failed'|'stale'>} stale: the request failed after its edit was replaced or closed
     */
    async function runEdit({ host, field, mutations, resourceId, send, onSaved, onCurrent = () => {}, describe }) {
        const op = host[field];
        const ticket = mutations.begin();
        op.busy = true;
        op.error = '';
        try {
            const saved = await send();
            if (mutations.claim(resourceId, ticket)) onSaved(saved);
            if (host[field] === op) {
                onCurrent();
                host[field] = null;
            }

            return 'saved';
        } catch (error) {
            if (host[field] !== op) return 'stale';
            op.busy = false;
            op.error = describe(error);

            return 'failed';
        }
    }

    /**
     * Saves the rename the page holds in host.renaming ({ id, name, busy, error }) through runEdit().
     *
     * @returns {Promise<'skipped'|'unchanged'|'saved'|'failed'|'stale'>}
     */
    async function saveRename({ host, mutations, resource, patch, onSaved, describe }) {
        const op = host.renaming;
        if (! op || op.busy || op.id !== resource.id) return 'skipped';
        const name = String(op.name || '').trim();
        if (! name) return 'skipped';
        if (name === resource.name) {
            host.renaming = null;

            return 'unchanged';
        }

        return runEdit({ host, field: 'renaming', mutations, resourceId: resource.id, send: () => patch(name), onSaved, describe });
    }

    /**
     * Saves the sharing dialog's draft in host.share ({ resource, busy, error, ... }) through runEdit(): only the
     * draft it was sent from is closed (onClosed) or shown an error, and the row update goes to that draft's resource.
     *
     * @returns {Promise<'skipped'|'saved'|'failed'|'stale'>}
     */
    async function saveShare({ host, mutations, payload, patch, onSaved, onClosed, describe }) {
        const op = host.share;
        if (! op || op.busy || ! payload) return 'skipped';
        const resource = op.resource;

        return runEdit({ host, field: 'share', mutations, resourceId: resource.id, send: () => patch(resource, payload), onSaved, onCurrent: onClosed, describe });
    }

    root.MothershipHubResources = { KINDS, VISIBILITIES, REASONS, fill, formatBytes, describeUploadFailure, reading, facts, quota, visibilityLabel, shareRequest, bulkShareRequest, sameOriginUrl, faceName, byKind, mutationLog, runEdit, saveRename, saveShare };
})(window);
