import test from 'node:test';
import assert from 'node:assert/strict';
import { canRestorePrimary, composePrimaryView, multiplyQuaternions } from '../../src/Resources/assets/js/model/primary-view.mjs';

const close = (actual, expected) => actual.forEach((n, i) => assert.ok(Math.abs(n - expected[i]) < 1e-9, `${actual} vs ${expected}`));

const q1 = [0, Math.sin(0.3), 0, Math.cos(0.3)];
const q2 = [Math.sin(0.2), 0, 0, Math.cos(0.2)];

test('matches the server composition (GlbPrimaryView::compose) for two successive saves', () => {
    const first = composePrimaryView({ delta: { rotation: q1, translation: [1, 2, 3] } });
    const second = composePrimaryView({ delta: { rotation: q2, translation: [0.5, -1, 4] }, previous: first });

    close(second.rotation, [0.18979606097868743, 0.28962947762551555, 0.05871080169382652, 0.9362933635841992]);
    close(second.translation, [1.5, -0.3261330389201814, 7.542019666625956]);
});

test('two successive saves equal one save of the combined rotation', () => {
    const first = composePrimaryView({ delta: { rotation: q1, translation: [1, 2, 3] } });
    const twice = composePrimaryView({ delta: { rotation: q2, translation: [0.5, -1, 4] }, previous: first });
    // The second delta rotates the first translation, then adds its own.
    const rotated = multiplyQuaternions(multiplyQuaternions(q2, [1, 2, 3, 0]), [-q2[0], -q2[1], -q2[2], q2[3]]);
    const once = composePrimaryView({
        delta: { rotation: multiplyQuaternions(q2, q1), translation: [rotated[0] + 0.5, rotated[1] - 1, rotated[2] + 4] },
    });

    close(twice.rotation, once.rotation);
    close(twice.translation, once.translation);
});

test('Restore original appears after the first save of an initially unsaved model, in the same session', () => {
    const unsaved = { view: null, can_edit: true };
    const saved = { view: { distance: 3, aspect: 1.5 }, can_edit: true };

    assert.equal(canRestorePrimary({ primaryView: unsaved, hasRestoreHandler: true }), false);
    // adoptPrimaryView() swaps in the server's answer for the first save; the toolbar re-syncs from it.
    assert.equal(canRestorePrimary({ primaryView: saved, hasRestoreHandler: true }), true);
    assert.equal(canRestorePrimary({ primaryView: saved, hasRestoreHandler: false }), false);
});
