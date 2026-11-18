# Image editor render performance — 2026-09-06

The editor felt sluggish next to the rest of the app because every input event
ran a full-resolution render, and each render re-ran the whole pipeline: fetch
and decode the source, crop/resize, read every pixel back to the CPU for the
adjustment loop even when every adjustment was zero, then rotate, frame and
annotate. Pointer moves during an annotation or crop drag arrive at 60–120 Hz,
and each one started another render; the sequence guard only discarded stale
output, it did not cancel the work.

## Measurement

`tools/browser/image-editor-render-bench.html` renders a synthetic
4000×3000 (12 MP) source through `renderDocument` and reports median and IQR
over N repeats per scenario. Serve the repository root with any static server
and open the page (query `w`, `h`, `n`). Apple M4 Pro, Chrome, 12 repeats:

| Scenario | Before (median / IQR) | After (median / IQR) |
| --- | ---: | ---: |
| No edits | 132.9 ms / 568.8 | 0.2 ms / 0.3 |
| Annotation | 121.0 ms / 4.6 | 0.4 ms / 1.8 |
| Brightness | 91.1 ms / 8.9 | 1.2 ms / 3.1 |
| Background frame | 88.4 ms / 6.6 | 1.9 ms / 2.8 |
| Sharpen | 219.1 ms / 9.9 | 1.4 ms / 1.7 |

Sub-step profile of one identity render before the change: `getImageData`
55–120 ms, the adjustment loop 410–645 ms under contention (the loop is pure
JavaScript over 48 MB), `drawImage` into the CPU-backed canvas 12–26 ms.

A burst of 60 renders requested without waiting, the shape of a drag: before,
29.6–31.2 s wall time for four bursts (60 renders each); after, 1–32 ms with
2 renders per burst.

## Changes

- `renderer.mjs` skips `getImageData`/`putImageData` and the CPU-backed canvas
  when every adjustment is at its default (`isIdentityAdjustment`).
- The crop/resize/adjustment stage is memoised on source URL, crop, resize and
  the pixel adjustments; annotation, background, rotation, flip and blur edits
  reuse it.
- Decoded source images are cached per URL (`loadImage`); the resource route is
  `no-store`, so the browser refetched the base image on every repaint.
  `releaseImages()` runs when the editor unmounts.
- `createPainter` runs one render at a time and folds every request made
  meanwhile into a single follow-up render, so a drag ends on the latest state
  after at most one extra render.

Unit coverage: `packages/Mothership/DAM/tests/js/image-renderer-cache.test.mjs`.
Browser coverage: `tests/e2e-pw/tests/dam-image-editor.spec.js` (8 passed
against the rebuilt bundle).
