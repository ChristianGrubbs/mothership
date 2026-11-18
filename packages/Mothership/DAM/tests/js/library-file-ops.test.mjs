import test from 'node:test';
import assert from 'node:assert/strict';
import ops from '../../src/Resources/assets/js/library-file-ops.js';

test('blocked deletion requires a 422 analysis object and counts blocking entries', () => {
    const analysis = { 1: { blocking: [2] }, 3: { blocking: [] }, 4: {} };
    assert.equal(ops.isBlockedDelete({ response: { status: 422, data: { analysis } } }), true);
    for (const error of [null, {}, { response: { status: 500, data: { analysis } } }, { response: { status: 422, data: { analysis: null } } }]) assert.equal(ops.isBlockedDelete(error), false);
    assert.equal(ops.blockedCount(analysis), 1);
    assert.equal(ops.blockedCount({ blocking: [{ id: 2 }], warnings: [] }), 1);
});
test('drag preserves a selected group, but isolates an unselected card', () => {
    assert.deepEqual(ops.dragPayload({ assetId: 2, selectedIds: [1, 2] }), { type: 'dam-asset', id: 2, ids: [1, 2] });
    assert.deepEqual(ops.dragPayload({ assetId: 3, selectedIds: [1, 2] }).ids, [3]);
});
test('explorer drag carries the whole selection only when it starts on a selected item', () => {
    const selection = [{ id: 1, type: 'asset' }, { id: 2, type: 'asset' }, { id: 7, type: 'directory' }];
    assert.deepEqual(ops.selectionDragFields({ id: 2, type: 'asset', selection }), { ids: [1, 2], dirIds: [7] });
    assert.deepEqual(ops.selectionDragFields({ id: 7, type: 'directory', selection }), { ids: [1, 2], dirIds: [7] });
    assert.deepEqual(ops.selectionDragFields({ id: 3, type: 'asset', selection }), {});
    assert.deepEqual(ops.selectionDragFields({ id: 7, type: 'asset', selection }), {});
    assert.deepEqual(ops.selectionDragFields({ id: 1, type: 'asset', selection: [{ id: 1, type: 'asset' }] }), {});
});
test('only an explorer drag of more than one item counts as a selection drag', () => {
    assert.equal(ops.isSelectionDrag({ tabId: 't', ids: [1, 2], dirIds: [] }), true);
    assert.equal(ops.isSelectionDrag({ tabId: 't', ids: [1], dirIds: [7] }), true);
    assert.equal(ops.isSelectionDrag({ tabId: 't', id: 1 }), false);
    assert.equal(ops.isSelectionDrag({ ids: [1, 2] }), false, 'library grid payloads (no tab) keep the tree mover');
    assert.equal(ops.isSelectionDrag(null), false);
});
test('drop ignores OS files and malformed/non-object data', () => {
    const transfer = (data, types = []) => ({ types, getData: () => data });
    assert.equal(ops.parseDropPayload(transfer('{"id":1}', ['Files'])), null);
    for (const data of ['', 'null', '1', '[]', '{']) assert.equal(ops.parseDropPayload(transfer(data)), null);
    assert.deepEqual(ops.parseDropPayload(transfer('{"type":"dam-asset","id":1}')), { type: 'dam-asset', id: 1 });
});
test('force retry happens once after agreement, and cancellation sends no retry', async () => {
    for (const agree of [true, false]) {
        const calls = [];
        const result = await ops.deleteWithConfirmation({
            request: async force => { calls.push(force); if (!force) throw { response: { status: 422, data: { analysis: [{ blocking: [2] }] } } }; return 'deleted'; },
            message: ':count blocked',
            emitter: { emit: (event, modal) => { assert.equal(event, 'open-delete-modal'); assert.equal(modal.message, '1 blocked'); modal[agree ? 'agree' : 'disagree'](); } },
        });
        assert.deepEqual(calls, agree ? [false, true] : [false]);
        assert.equal(result, agree ? 'deleted' : null);
    }
});
