import test from 'node:test';
import assert from 'node:assert/strict';
import { PHONE_MODEL_CONFIRM_BYTES } from '../../src/Resources/assets/js/model/limits.mjs';
import {
    needsPhoneConfirm,
    isCoarsePointer,
    resolveExpectedBytes,
    formatDownloadProgress,
    thumbnailTarget,
} from '../../src/Resources/assets/js/model/load-policy.mjs';

const MB = 1024 * 1024;

test('asks before loading a model over 50 MB on a touch-primary device only', () => {
    assert.equal(PHONE_MODEL_CONFIRM_BYTES, 50 * MB);
    assert.equal(needsPhoneConfirm({ sizeBytes: 294 * MB, coarsePointer: true }), true);
    assert.equal(needsPhoneConfirm({ sizeBytes: 50 * MB, coarsePointer: true }), false);
    assert.equal(needsPhoneConfirm({ sizeBytes: 294 * MB, coarsePointer: false }), false);
    assert.equal(needsPhoneConfirm({ sizeBytes: 0, coarsePointer: true }), false);
});

test('detects a coarse pointer and tolerates a missing matchMedia', () => {
    assert.equal(isCoarsePointer({ matchMedia: q => ({ matches: q === '(pointer: coarse)' }) }), true);
    assert.equal(isCoarsePointer({ matchMedia: () => ({ matches: false }) }), false);
    assert.equal(isCoarsePointer({}), false);
});

test('prefers the response length and falls back to the server-provided size', () => {
    assert.equal(resolveExpectedBytes({ contentLength: 100, fallbackBytes: 200 }), 100);
    assert.equal(resolveExpectedBytes({ contentLength: 0, fallbackBytes: 200 }), 200);
    assert.equal(resolveExpectedBytes({ contentLength: NaN, fallbackBytes: 0 }), 0);
});

test('formats "x MB of y MB (z%)" when the total is known', () => {
    assert.deepEqual(formatDownloadProgress({ loaded: 21 * MB, expected: 42 * MB }), { text: '21.0 of 42.0 MB (50%)', percent: 50 });
});

test('stays indeterminate when the total is unknown or already exceeded', () => {
    assert.deepEqual(formatDownloadProgress({ loaded: 6.2 * MB, expected: 0 }), { text: '6.2 MB', percent: null });
    assert.equal(formatDownloadProgress({ loaded: 43 * MB, expected: 42 * MB }).percent, null);
});

test('captures a stacked file against the file, never against the stack cover asset', () => {
    assert.deepEqual(thumbnailTarget({ assetId: 7 }), { kind: 'asset', id: 7 });
    assert.deepEqual(thumbnailTarget({ assetId: '7' }), { kind: 'asset', id: 7 });
    assert.deepEqual(thumbnailTarget({ fileId: 31 }), { kind: 'file', id: 31 });
    // Even if a caller hands over both, the file wins: the asset's thumbnail is the stack's cover.
    assert.deepEqual(thumbnailTarget({ assetId: 7, fileId: 31 }), { kind: 'file', id: 31 });
    // Public share viewers and anything else with no record to store against capture nothing.
    assert.equal(thumbnailTarget({}), null);
    assert.equal(thumbnailTarget(), null);
    assert.equal(thumbnailTarget({ assetId: 0, fileId: null }), null);
});
