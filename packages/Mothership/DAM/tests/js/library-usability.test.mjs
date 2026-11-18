import test from 'node:test';
import assert from 'node:assert/strict';

import libraryNavigation from '../../src/Resources/assets/js/library-navigation.js';

test('folder move targets require access and exclude the source subtree', () => {
    const move = {
        sourceId: 12,
        descendantIds: [18, 19],
        accessibleIds: [5, 12, 20],
        aclBypass: false,
    };

    assert.equal(libraryNavigation.isValidFolderMoveTarget({ ...move, targetId: 20 }), true);
    assert.equal(libraryNavigation.isValidFolderMoveTarget({ ...move, targetId: 12 }), false);
    assert.equal(libraryNavigation.isValidFolderMoveTarget({ ...move, targetId: 18 }), false);
    assert.equal(libraryNavigation.isValidFolderMoveTarget({ ...move, targetId: 5, accessibleIds: [5, 20] }), false);
    assert.equal(libraryNavigation.isValidFolderMoveTarget({ ...move, targetId: 99 }), false);
});

test('folder move target access can be bypassed without weakening subtree checks', () => {
    assert.equal(libraryNavigation.isValidFolderMoveTarget({
        sourceId: 12,
        targetId: 99,
        descendantIds: [18],
        accessibleIds: [],
        aclBypass: true,
    }), true);

    assert.equal(libraryNavigation.isValidFolderMoveTarget({
        sourceId: 12,
        targetId: 18,
        descendantIds: [18],
        accessibleIds: [],
        aclBypass: true,
    }), false);
});

test('dragging near either vertical edge produces bounded autoscroll', () => {
    assert.equal(libraryNavigation.edgeScrollDelta({ pointerY: 104, top: 100, bottom: 500 }), -16);
    assert.equal(libraryNavigation.edgeScrollDelta({ pointerY: 496, top: 100, bottom: 500 }), 16);
    assert.equal(libraryNavigation.edgeScrollDelta({ pointerY: 300, top: 100, bottom: 500 }), 0);
});

test('the shared sorter leads with recently added and keeps every name size and modified direction', () => {
    assert.deepEqual(libraryNavigation.sortOptions, [
        { value: 'created_at:desc', label: 'Recently added' },
        { value: 'created_at:asc', label: 'Oldest added' },
        { value: 'name:asc', label: 'Name A–Z' },
        { value: 'name:desc', label: 'Name Z–A' },
        { value: 'size:asc', label: 'Size smallest' },
        { value: 'size:desc', label: 'Size largest' },
        { value: 'updated_at:desc', label: 'Modified newest' },
        { value: 'updated_at:asc', label: 'Modified oldest' },
    ]);
    assert.equal(libraryNavigation.defaultSort, 'created_at:desc');
});

test('a remembered sort is honoured only while it is still an option, otherwise recently added wins', () => {
    assert.equal(libraryNavigation.resolveSort('name:desc'), 'name:desc');
    assert.equal(libraryNavigation.resolveSort('updated_at:asc'), 'updated_at:asc');
    assert.equal(libraryNavigation.resolveSort(null), 'created_at:desc');
    assert.equal(libraryNavigation.resolveSort('size:sideways'), 'created_at:desc');
    assert.equal(libraryNavigation.resolveSort('deleted_at:desc'), 'created_at:desc');
});

function memoryStorage(entries = {}) {
    const map = new Map(Object.entries(entries));
    return {
        get length() { return map.size; },
        key: (i) => [...map.keys()][i] ?? null,
        getItem: (k) => (map.has(k) ? map.get(k) : null),
        setItem: (k, v) => map.set(k, String(v)),
        removeItem: (k) => map.delete(k),
        keys: () => [...map.keys()],
    };
}

test('explorer tabs, filters and folder are stored per account and never shared (F20)', () => {
    assert.equal(libraryNavigation.explorerKey('dam_explorer_tabs', 5), 'dam_explorer_tabs:5');
    assert.notEqual(libraryNavigation.explorerKey('dam_explorer_tabs', 5), libraryNavigation.explorerKey('dam_explorer_tabs', 6));

    const storage = memoryStorage({
        dam_explorer_tabs: '{"tabs":[{"search":"tree"}]}',
        dam_explorer_filter_state: '[]',
        dam_explorer_active_dir: '143',
        'dam_explorer_tabs:5': '{}',
        dam_explorer_view_mode: 'grid',
    });
    libraryNavigation.dropLegacyExplorerState(storage);
    assert.deepEqual(storage.keys().sort(), ['dam_explorer_tabs:5', 'dam_explorer_view_mode']);

    const session = memoryStorage({ dam_return_dir: '133', 'dam.details.siblings': '{}', other: 'x' });
    libraryNavigation.clearExplorerState(storage, session);
    assert.deepEqual(storage.keys(), []);
    assert.deepEqual(session.keys(), ['other']);
});

test('a Library deep link keeps singular media types and the page (F27, F13)', () => {
    assert.equal(libraryNavigation.locationState('?media_type=video').mediaType, 'videos');
    assert.equal(libraryNavigation.locationState('?media_type=image').mediaType, 'images');
    assert.equal(libraryNavigation.locationState('?media_type=svg').mediaType, 'svgs');
    assert.equal(libraryNavigation.locationState('?media_type=audio').mediaType, 'audio');
    assert.equal(libraryNavigation.locationState('?media_type=bogus').mediaType, 'all');

    const state = libraryNavigation.locationState('?scope=all&search=campus&media_type=all&page=2');
    assert.equal(state.explicit, true);
    assert.equal(state.scope, 'all');
    assert.equal(state.search, 'campus');
    assert.equal(state.page, 2);
    assert.equal(libraryNavigation.locationState('?page=-1').page, 1);
    // No page in the url: a restored tab keeps its remembered page.
    assert.equal(libraryNavigation.locationState('?scope=all').page, null);
});
