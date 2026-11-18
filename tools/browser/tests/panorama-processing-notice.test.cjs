const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const root = path.resolve(__dirname, '../../..');
const source = fs.readFileSync(path.join(root, 'packages/Mothership/DAM/src/Resources/views/asset/preview-modal/pano360/viewer-script.blade.php'), 'utf8');
const script = source.replace(/<\/?script[^>]*>/g, '').replace(/@js\(trans\('[^']+'\)\)/g, '"message"');
const context = { window: {} };
vm.runInNewContext(script, context);
const computed = context.window._damPanoViewer.computed;

for (const playbackKind of ['pano360-image', 'pano360-video']) {
    test(`working ${playbackKind} does not show a processing warning`, () => {
        const state = { panoramaVisible: true, panoramaState: { processing_enabled: false, classification: { requires_proxy: false } }, panoramaCanOpen: true, panoramaSourceRejected: false };
        assert.equal(computed.panoramaProcessingUnavailable.call(state), false);
    });
}
test('unavailable conversion is explained only when needed', () => {
    const state = { panoramaVisible: true, panoramaState: { processing_enabled: false, classification: { requires_proxy: true } }, panoramaCanOpen: false, panoramaSourceRejected: false };
    assert.equal(computed.panoramaProcessingUnavailable.call(state), true);
    state.panoramaState.classification.requires_proxy = false;
    assert.equal(computed.panoramaProcessingUnavailable.call(state), false);
    state.panoramaSourceRejected = true;
    assert.equal(computed.panoramaProcessingUnavailable.call(state), true);
    state.panoramaState.processing_enabled = true;
    assert.equal(computed.panoramaProcessingUnavailable.call(state), false);
});
