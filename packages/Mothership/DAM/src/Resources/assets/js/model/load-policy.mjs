import { PHONE_MODEL_CONFIRM_BYTES } from './limits.mjs';

/** Pure decisions for the 3D preview's load flow, kept free of WebGL and the DOM so they can be tested. */

const MB = 1048576;

export function formatMegabytes(bytes) {
    return (bytes / MB).toFixed(1);
}

/** A phone-class (touch-primary) device with a model big enough to risk the tab. */
export function needsPhoneConfirm({ sizeBytes, coarsePointer }) {
    return coarsePointer === true && Number(sizeBytes) > PHONE_MODEL_CONFIRM_BYTES;
}

export function isCoarsePointer(win = globalThis) {
    try {
        return win.matchMedia?.('(pointer: coarse)').matches === true;
    } catch {
        return false;
    }
}

/** The response's own length wins; the size the page already knows is the fallback. 0 means unknown. */
export function resolveExpectedBytes({ contentLength, fallbackBytes }) {
    if (Number.isSafeInteger(contentLength) && contentLength > 0) return contentLength;
    return Number.isSafeInteger(fallbackBytes) && fallbackBytes > 0 ? fallbackBytes : 0;
}

/** `percent` is null when the total is unknown (or already exceeded), which keeps the bar indeterminate. */
export function formatDownloadProgress({ loaded, expected }) {
    if (expected > 0 && loaded <= expected) {
        const percent = Math.min(100, Math.floor((loaded / expected) * 100));

        return { text: `${formatMegabytes(loaded)} of ${formatMegabytes(expected)} MB (${percent}%)`, percent };
    }

    return { text: `${formatMegabytes(loaded)} MB`, percent: null };
}

/**
 * Which record a capture taken from this viewer is stored against (ADR 0006). A file of a stack is captured
 * against the file itself and never against the asset, whose thumbnail is the stack's cover: a stacked file
 * opened in the viewer must not be able to replace it.
 */
export function thumbnailTarget({ assetId = null, fileId = null } = {}) {
    if (Number(fileId) > 0) return { kind: 'file', id: Number(fileId) };

    return Number(assetId) > 0 ? { kind: 'asset', id: Number(assetId) } : null;
}
