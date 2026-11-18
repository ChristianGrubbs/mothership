import test from 'node:test';
import assert from 'node:assert/strict';

import idBatches from '../../src/Resources/assets/js/id-batches.js';

test('1,001 ids go out as two requests of at most 1,000', () => {
    const ids = Array.from({ length: 1001 }, (_, i) => i + 1);
    const batches = idBatches.chunkIds(ids);

    assert.deepEqual(batches.map((b) => b.length), [1000, 1]);
    assert.deepEqual(batches.flat(), ids);
});

test('no ids make no requests; 1,000 make one', () => {
    assert.deepEqual(idBatches.chunkIds([]), []);
    assert.equal(idBatches.chunkIds(Array.from({ length: 1000 }, (_, i) => i)).length, 1);
});
