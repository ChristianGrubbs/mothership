import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// resources.js is inlined into dam::creative-hub.resources; run it here against a plain window.
const source = readFileSync(new URL('../../src/Resources/assets/js/creative-hub/resources.js', import.meta.url), 'utf8');
const win = {};
new Function('window', source)(win);
const H = win.MothershipHubResources;

const text = {
    reasons: { collection: 'Collections are refused.', unparsed: 'Could not read.' },
    refused: 'This file cannot be used.',
    requestTooLarge: 'Too large to upload.',
    throttled: 'Too many uploads.',
    session: 'Session ended.',
    network: 'Did not reach The Mothership.',
    unknown: 'Something went wrong.',
    brush: 'PhotoCraft brush',
    variable: 'Variable',
    styles: { italic: 'Italic' },
};
const failure = (status, data) => ({ response: { status, data } });

test('a refusal the server explained keeps its own translated sentence and reason code', () => {
    for (const reason of ['too_large', 'collection', 'quota_bytes', 'unknown_format', 'bad_brush', 'license_required']) {
        assert.deepEqual(H.describeUploadFailure({ error: failure(422, { reason, message: `Server says ${reason}.` }), text }), { reason, message: `Server says ${reason}.` });
    }
    assert.deepEqual(H.describeUploadFailure({ error: failure(403, { reason: 'not_owner', message: 'Only the owner.' }), text }), { reason: 'not_owner', message: 'Only the owner.' });
});

test('a known reason without a message falls back to the page translation, then the generic refusal', () => {
    assert.deepEqual(H.describeUploadFailure({ error: failure(422, { reason: 'collection' }), text }), { reason: 'collection', message: 'Collections are refused.' });
    assert.deepEqual(H.describeUploadFailure({ error: failure(422, { reason: 'quota_bytes', message: '  ' }), text }), { reason: 'quota_bytes', message: 'This file cannot be used.' });
});

test('failures on the way to the server get their own translated line, never a raw status or body', () => {
    assert.deepEqual(H.describeUploadFailure({ error: failure(413, '<html>Request Entity Too Large</html>'), text }), { reason: 'too_large', message: 'Too large to upload.' });
    assert.deepEqual(H.describeUploadFailure({ error: failure(429, { message: 'Too Many Attempts.' }), text }), { reason: 'throttled', message: 'Too many uploads.' });
    assert.equal(H.describeUploadFailure({ error: failure(419, { message: 'CSRF token mismatch.' }), text }).message, 'Session ended.');
    assert.equal(H.describeUploadFailure({ error: failure(401, {}), text }).message, 'Session ended.');
    assert.deepEqual(H.describeUploadFailure({ error: new Error('Network Error'), text }), { reason: 'network', message: 'Did not reach The Mothership.' });
    // A validation 422 without a reason code (no file in the request) shows Laravel's message; a 500 never leaks its body.
    assert.equal(H.describeUploadFailure({ error: failure(422, { message: 'The file field is required.' }), text }).message, 'The file field is required.');
    assert.equal(H.describeUploadFailure({ error: failure(500, '<html>Server Error</html>'), text }).message, 'Something went wrong.');
    // An unknown reason code is not trusted as a refusal code.
    assert.equal(H.describeUploadFailure({ error: failure(422, { reason: 'made_up', message: 'Odd.' }), text }).reason, 'invalid');
});

test('the reason codes match the ones HubResourceController answers with', () => {
    const controller = readFileSync(new URL('../../src/Http/Controllers/HubResourceController.php', import.meta.url), 'utf8');
    const refused = readFileSync(new URL('../../src/Services/CreativeHub/HubResourceRefused.php', import.meta.url), 'utf8');
    const codes = [...refused.matchAll(/public const [A-Z_]+ = '([a-z_]+)';/g)].map((m) => m[1]);
    const share = controller.match(/SHARE_NOT_ALLOWED = '([a-z_]+)'/)[1];
    assert.deepEqual([...H.REASONS].sort(), [...codes, share].sort());
});

test('reads back what the server parsed, and words sizes in binary units like the server', () => {
    const font = { kind: 'font', format: 'otf', name: 'Inter-BoldItalic.otf', family: 'Inter', subfamily: 'Bold Italic', weight: 700, style: 'italic', variable: false, size: 319488 };
    assert.equal(H.reading(font, text), 'Inter · Bold Italic · 700 · OTF');
    assert.equal(H.facts(font, text), 'Bold Italic · 700 · OTF · 312 KiB');
    assert.equal(H.facts({ ...font, subfamily: 'Regular', weight: 400, variable: true }, text), 'Regular · 400 · Italic · Variable · OTF · 312 KiB');
    assert.equal(H.facts({ ...font, subfamily: 'Oblique', weight: 400 }, text), 'Oblique · 400 · OTF · 312 KiB');
    assert.equal(H.reading({ kind: 'brush', format: 'abr', name: 'Inks.abr', size: 10 }, text), 'Inks.abr · PhotoCraft brush · ABR');
    assert.equal(H.formatBytes(5 * 1024 ** 3), '5 GiB');
    assert.equal(H.formatBytes(1536), '1.5 KiB');
    assert.equal(H.formatBytes(12), '12 B');
});

test('sharing needs the attestation and a note, and Groups at least one group, before anything is sent', () => {
    assert.deepEqual(H.shareRequest({ visibility: 'private' }), { payload: { visibility: 'private' } });
    assert.equal(H.shareRequest({ visibility: 'everyone', licenseNote: 'OFL', attested: false }).error, 'license_required');
    assert.equal(H.shareRequest({ visibility: 'everyone', licenseNote: '   ', attested: true }).error, 'license_required');
    assert.deepEqual(H.shareRequest({ visibility: 'everyone', licenseNote: ' OFL 1.1 ', attested: true }), { payload: { visibility: 'everyone', license_note: 'OFL 1.1', license_attested: true } });
    assert.equal(H.shareRequest({ visibility: 'groups', groupIds: [], licenseNote: 'OFL', attested: true }).error, 'bad_visibility');
    assert.deepEqual(H.shareRequest({ visibility: 'groups', groupIds: ['3', 3, 5], licenseNote: 'OFL', attested: true }).payload.group_ids, [3, 5]);
    assert.equal(H.shareRequest({ visibility: 'public' }).error, 'bad_visibility');
});

test('Share selected keeps a resource\'s own license note, records the bulk note otherwise, and attests', () => {
    const note = 'Shared from Fonts & brushes';
    assert.deepEqual(H.bulkShareRequest({ resource: { license_note: ' OFL 1.1 ' }, visibility: 'everyone', note }), { visibility: 'everyone', license_note: 'OFL 1.1', license_attested: true });
    assert.deepEqual(H.bulkShareRequest({ resource: { license_note: null }, visibility: 'everyone', note }), { visibility: 'everyone', license_note: note, license_attested: true });
    assert.deepEqual(H.bulkShareRequest({ resource: { license_note: 'OFL' }, visibility: 'private', note }), { visibility: 'private' });
    assert.equal(H.bulkShareRequest({ resource: {}, visibility: 'groups', note }), null);
});

test('a preview face is fetched only from this page\'s own origin', () => {
    const origin = 'https://mothership.example';
    assert.equal(H.sameOriginUrl('/admin/dam/hub/resources/abc/bytes?sha256=ff', origin), 'https://mothership.example/admin/dam/hub/resources/abc/bytes?sha256=ff');
    assert.equal(H.sameOriginUrl('https://mothership.example/x', origin), 'https://mothership.example/x');
    for (const url of ['https://mothership-hub.example/x', '//evil.example/x', 'http://mothership.example/x', 'data:font/ttf;base64,AA', 'javascript:alert(1)', '', null]) {
        assert.equal(H.sameOriginUrl(url, origin), null, String(url));
    }
    assert.equal(H.faceName({ sha256: 'ABCDEF0123456789ffff' }), 'hub-resource-abcdef0123456789');
    assert.equal(H.faceName({ sha256: '' }), null);
});

test('names who can use a resource, and grades the quota', () => {
    const groups = [{ id: 1, name: 'Marketing' }, { id: 2, name: 'Theatre' }, { id: 3, name: 'Yearbook' }];
    const labels = { visibility: { private: 'Only me', everyone: 'Everyone', groups: 'Groups', groupsNamed: 'Groups: :names', groupsMore: 'Groups: :names and :count more' } };
    assert.equal(H.visibilityLabel({ visibility: 'private' }, groups, labels), 'Only me');
    assert.equal(H.visibilityLabel({ visibility: 'everyone' }, groups, labels), 'Everyone');
    assert.equal(H.visibilityLabel({ visibility: 'groups', group_ids: [2] }, groups, labels), 'Groups: Theatre');
    assert.equal(H.visibilityLabel({ visibility: 'groups', group_ids: [1, 2, 3] }, groups, labels), 'Groups: Marketing, Theatre and 1 more');
    // A reader who is not the owner gets no group ids from the API.
    assert.equal(H.visibilityLabel({ visibility: 'groups', group_ids: [] }, groups, labels), 'Groups');
    assert.equal(H.quota({ bytes: 0, limit: 100 }).tone, 'ok');
    assert.equal(H.quota({ bytes: 80, limit: 100 }).tone, 'high');
    assert.equal(H.quota({ bytes: 120, limit: 100 }).percent, 100);
    assert.equal(H.quota({ bytes: 120, limit: 100 }).tone, 'full');
});

// A request the test settles by hand, so Cancel or a second rename can happen while the PATCH is awaiting.
function deferred() {
    let resolve;
    let reject;
    const promise = new Promise((res, rej) => { resolve = res; reject = rej; });

    return { promise, resolve, reject };
}

// One page: rows by id, one mutation log, and a deferred per PATCH sent (requests[0] is the first one).
function pageRig() {
    const page = { renaming: null, share: null, rows: {}, closed: 0 };
    const mutations = H.mutationLog();
    const requests = [];
    const send = () => {
        const request = deferred();
        requests.push(request);

        return request.promise;
    };
    const onSaved = (saved) => { page.rows[saved.id] = saved; };
    const describe = (error) => `failed: ${error.message}`;

    return {
        page,
        requests,
        rename: (resource) => H.saveRename({ host: page, mutations, resource, patch: send, onSaved, describe }),
        share: (payload) => H.saveShare({ host: page, mutations, payload, patch: send, onSaved, onClosed: () => { page.closed += 1; }, describe }),
    };
}

function renameRig() {
    const rig = pageRig();
    const request = { resolve: (value) => rig.requests[0].resolve(value), reject: (error) => rig.requests[0].reject(error) };

    return { page: rig.page, request, run: rig.rename, rows: () => Object.values(rig.page.rows) };
}

test('a rename that succeeds after Cancel and a second rename updates its row but leaves the new edit alone', async () => {
    const { page, request, run, rows } = renameRig();
    const first = { id: 1, name: 'Old.ttf' };
    page.renaming = { id: 1, name: 'New.ttf', busy: false, error: '' };
    const pending = run(first);
    assert.equal(page.renaming.busy, true);

    page.renaming = null; // Cancel / Escape
    page.renaming = { id: 2, name: 'Other.ttf', busy: false, error: '' }; // a second rename
    const second = page.renaming;
    request.resolve({ id: 1, name: 'New.ttf' });

    assert.equal(await pending, 'saved');
    assert.deepEqual(rows(), [{ id: 1, name: 'New.ttf' }]);
    assert.equal(page.renaming, second);
    assert.deepEqual(second, { id: 2, name: 'Other.ttf', busy: false, error: '' });
});

test('a rename that fails after Cancel neither throws on the cleared edit nor marks the next one', async () => {
    const { page, request, run } = renameRig();
    page.renaming = { id: 1, name: 'New.ttf', busy: false, error: '' };
    const pending = run({ id: 1, name: 'Old.ttf' });

    page.renaming = null;
    request.reject(new Error('network'));
    assert.equal(await pending, 'stale');
    assert.equal(page.renaming, null);

    const again = renameRig();
    again.page.renaming = { id: 1, name: 'New.ttf', busy: false, error: '' };
    const retried = again.run({ id: 1, name: 'Old.ttf' });
    again.page.renaming = { id: 1, name: 'Newer.ttf', busy: false, error: '' }; // replaced by a fresh rename of the same row
    const fresh = again.page.renaming;
    again.request.reject(new Error('network'));
    assert.equal(await retried, 'stale');
    assert.deepEqual(fresh, { id: 1, name: 'Newer.ttf', busy: false, error: '' });
});

test('a current rename closes on success and shows its error on failure; a busy or blank one does not resend', async () => {
    const ok = renameRig();
    ok.page.renaming = { id: 1, name: ' New.ttf ', busy: false, error: '' };
    const saving = ok.run({ id: 1, name: 'Old.ttf' });
    assert.equal(await ok.run({ id: 1, name: 'Old.ttf' }), 'skipped');
    ok.request.resolve({ id: 1, name: 'New.ttf' });
    assert.equal(await saving, 'saved');
    assert.equal(ok.page.renaming, null);

    const bad = renameRig();
    bad.page.renaming = { id: 1, name: 'New.ttf', busy: false, error: '' };
    const failing = bad.run({ id: 1, name: 'Old.ttf' });
    bad.request.reject(new Error('refused'));
    assert.equal(await failing, 'failed');
    assert.deepEqual(bad.page.renaming, { id: 1, name: 'New.ttf', busy: false, error: 'failed: refused' });

    bad.page.renaming = { id: 1, name: '   ', busy: false, error: '' };
    assert.equal(await bad.run({ id: 1, name: 'Old.ttf' }), 'skipped');
    bad.page.renaming = { id: 1, name: 'Old.ttf', busy: false, error: '' };
    assert.equal(await bad.run({ id: 1, name: 'Old.ttf' }), 'unchanged');
    assert.equal(bad.page.renaming, null);
});

test('an older rename of the same resource that settles after a newer one never puts its stale name back', async () => {
    const { page, requests, rename } = pageRig();
    const row = { id: 1, name: 'Old.ttf' };
    page.renaming = { id: 1, name: 'First.ttf', busy: false, error: '' };
    const older = rename(row);
    page.renaming = null; // Cancel
    page.renaming = { id: 1, name: 'Second.ttf', busy: false, error: '' };
    const newer = rename(row);

    requests[1].resolve({ id: 1, name: 'Second.ttf' });
    assert.equal(await newer, 'saved');
    requests[0].resolve({ id: 1, name: 'First.ttf' });
    assert.equal(await older, 'saved');

    assert.deepEqual(page.rows[1], { id: 1, name: 'Second.ttf' });
    assert.equal(page.renaming, null);
});

test('a share of A that succeeds after its dialog closed and B\'s opened updates A\'s row only, leaving B\'s dialog open', async () => {
    const { page, requests, share } = pageRig();
    page.share = { resource: { id: 'a', name: 'A.ttf' }, visibility: 'everyone', busy: false, error: '' };
    const pending = share({ visibility: 'everyone', license_note: 'OFL', license_attested: true });
    page.share = null; // dialog closed
    page.share = { resource: { id: 'b', name: 'B.ttf' }, visibility: 'private', busy: false, error: '' };
    const draftB = page.share;

    requests[0].resolve({ id: 'a', name: 'A.ttf', visibility: 'everyone' });
    assert.equal(await pending, 'saved');

    assert.deepEqual(page.rows, { a: { id: 'a', name: 'A.ttf', visibility: 'everyone' } });
    assert.equal(page.share, draftB);
    assert.equal(page.closed, 0);
    assert.deepEqual(draftB, { resource: { id: 'b', name: 'B.ttf' }, visibility: 'private', busy: false, error: '' });
});

test('a share of A that fails after B\'s dialog opened writes no error into B\'s draft; a current one closes or shows it', async () => {
    const { page, requests, share } = pageRig();
    page.share = { resource: { id: 'a', name: 'A.ttf' }, busy: false, error: '' };
    const pending = share({ visibility: 'everyone' });
    page.share = { resource: { id: 'b', name: 'B.ttf' }, busy: false, error: '' };
    const draftB = page.share;
    requests[0].reject(new Error('refused'));
    assert.equal(await pending, 'stale');
    assert.deepEqual(draftB, { resource: { id: 'b', name: 'B.ttf' }, busy: false, error: '' });

    const failing = share({ visibility: 'everyone' });
    assert.equal(await share({ visibility: 'everyone' }), 'skipped');
    requests[1].reject(new Error('refused'));
    assert.equal(await failing, 'failed');
    assert.equal(draftB.error, 'failed: refused');
    assert.equal(draftB.busy, false);

    const saving = share({ visibility: 'everyone' });
    requests[2].resolve({ id: 'b', visibility: 'everyone' });
    assert.equal(await saving, 'saved');
    assert.equal(page.share, null);
    assert.equal(page.closed, 1);
    assert.deepEqual(page.rows.b, { id: 'b', visibility: 'everyone' });
});

test('a newer sharing change that fails never holds back an older rename that succeeded on the same resource', async () => {
    const { page, requests, rename, share } = pageRig();
    const row = { id: 1, name: 'Old.ttf' };
    page.renaming = { id: 1, name: 'New.ttf', busy: false, error: '' };
    const renamed = rename(row);
    page.renaming = null; // Cancel the editor while the rename is pending
    page.share = { resource: row, busy: false, error: '' };
    const shared = share({ visibility: 'everyone' });

    requests[1].reject(new Error('refused'));
    assert.equal(await shared, 'failed');
    requests[0].resolve({ id: 1, name: 'New.ttf' });
    assert.equal(await renamed, 'saved');

    assert.deepEqual(page.rows[1], { id: 1, name: 'New.ttf' });
    assert.equal(page.share.error, 'failed: refused');
});
