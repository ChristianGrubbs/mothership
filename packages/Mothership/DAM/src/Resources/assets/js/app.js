
import.meta.glob(["../images/**", "../fonts/**"], { eager: true });

/**
 * Three.js, vendored into this bundle instead of fetched from a CDN.
 *
 * The 360 viewer used to `import()` a cdnjs URL, which made the interactive
 * preview depend on the browser reaching the public internet — the DAM runs
 * behind a tunnel on a homelab host, and an offline or CSP-restricted client
 * silently fell back to the flat player. The dynamic import keeps Three.js in
 * its own chunk, so pages that never open a panorama still don't pay for it,
 * and the memoized promise means repeated viewer opens parse it once.
 */
window.loadDamThree = () => {
    if (!window._damThreePromise) {
        window._damThreePromise = import('three');
    }

    return window._damThreePromise;
};

window.loadDamModelViewer = () => import('./model/viewer.mjs');

// Size guard and progress decisions for the viewer; tiny, so the gate can decide before three.js loads.
window.loadDamModelPolicy = () => import('./model/load-policy.mjs');

// Thumbnail capture and its queue: pulled in by an upload batch, a preview of
// an unthumbnailed model, or the Library backfill action, never on page load.
window.loadDamModelThumbnails = () => import('./model/thumbnail-capture.mjs');

// Pinch-to-zoom math for the 360 viewer; tiny, pulled in only when a panorama opens.
window.loadDamPanoPinch = () => import('./pano/pinch.mjs');

window.loadDamImageEditor = () => import('./image/mount.mjs');

window.loadDamChunkUploader = () => import('./upload/chunked-upload.mjs');

// PDF.js and its worker are fetched only when the expanded document preview opens.
window.loadDamPdfViewer = () => import('./pdf/viewer.mjs');

window.damMediaDebug = import.meta.env.VITE_DAM_MEDIA_DEBUG === 'true';
