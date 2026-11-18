import test from 'node:test';
import assert from 'node:assert/strict';

import detailsNavigation from '../../src/Resources/assets/js/details-navigation.js';

function installBrowser(pathAndQuery) {
    const map = new Map();
    globalThis.sessionStorage = {
        getItem: (k) => (map.has(k) ? map.get(k) : null),
        setItem: (k, v) => map.set(k, String(v)),
        removeItem: (k) => map.delete(k),
    };
    const url = new URL(pathAndQuery, 'https://space.test');
    globalThis.location = { pathname: url.pathname, search: url.search };
}

test('the details page Back target is the Library view the asset was opened from (F13)', () => {
    installBrowser('/admin/dam?scope=all&search=campus&media_type=all&page=2');
    detailsNavigation.writeSiblings({ siblings: [7, 8, 9], scopeLabel: 'grid', directoryId: 'all', total: 59, offset: 50 });

    const stored = detailsNavigation.readSiblings();
    assert.equal(stored.returnUrl, '/admin/dam?scope=all&search=campus&media_type=all&page=2');

    // Stepping with › keeps the handoff, so Back still names the search, never the first asset's folder.
    assert.deepEqual(detailsNavigation.resultPosition({ position: 2, total: 3, stored }), { position: 52, total: 59 });
});

test('a Back target is only ever a same-origin path', () => {
    assert.equal(detailsNavigation.normalizeReturnUrl('https://evil.test/admin/dam'), null);
    assert.equal(detailsNavigation.normalizeReturnUrl('//evil.test/admin/dam'), null);
    assert.equal(detailsNavigation.normalizeReturnUrl('javascript:alert(1)'), null);
    assert.equal(detailsNavigation.normalizeReturnUrl('/\\evil.test/path'), null);
    assert.equal(detailsNavigation.normalizeReturnUrl('/\tevil'), null);
    assert.equal(detailsNavigation.normalizeReturnUrl('/admin/../admin/dam?x=1#frag'), '/admin/dam?x=1');
    assert.equal(detailsNavigation.normalizeReturnUrl('/admin/dam?directory_id=4'), '/admin/dam?directory_id=4');
});

test('without the Library totals the counter keeps the visible-page numbers (F14)', () => {
    assert.deepEqual(detailsNavigation.resultPosition({ position: 2, total: 50, stored: { total: null, offset: null } }), { position: 2, total: 50 });
    assert.deepEqual(detailsNavigation.resultPosition({ position: null, total: null, stored: null }), { position: null, total: null });
});
