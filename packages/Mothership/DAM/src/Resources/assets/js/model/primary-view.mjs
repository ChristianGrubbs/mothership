/**
 * The primary-view composition the server applies (GlbPrimaryView::compose), as pure math.
 * A save sends a delta relative to the model currently displayed; the server composes it onto the
 * previous state and bakes the total into the original file. Quaternions are [x, y, z, w].
 */
export function multiplyQuaternions(left, right) {
    const [x, y, z, w] = left;
    const [a, b, c, d] = right;

    return [
        w * a + x * d + y * c - z * b,
        w * b - x * c + y * d + z * a,
        w * c + x * b - y * a + z * d,
        w * d - x * a - y * b - z * c,
    ];
}

export function composePrimaryView({ delta, previous = null }) {
    const norm = Math.hypot(...delta.rotation);
    const q = delta.rotation.map(n => n / norm);

    if (!previous) return { rotation: q, translation: [...delta.translation] };

    const rotated = multiplyQuaternions(
        multiplyQuaternions(q, [...previous.translation, 0]),
        [-q[0], -q[1], -q[2], q[3]],
    );

    return {
        rotation: multiplyQuaternions(q, previous.rotation),
        translation: [0, 1, 2].map(i => rotated[i] + delta.translation[i]),
    };
}

/** Whether "Restore original" applies: a saved view exists (right after a first save, too) and a restore handler was supplied. */
export function canRestorePrimary({ primaryView, hasRestoreHandler }) {
    return Boolean(hasRestoreHandler && primaryView?.view);
}
