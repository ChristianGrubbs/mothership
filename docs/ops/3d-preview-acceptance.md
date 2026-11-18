# 3D preview acceptance — 2026-09-04

## STL preview and thumbnails — 2026-09-16

STL opens in the same viewer as GLB and 3MF (`viewer.mjs`, lazy chunk, same 1 GB download
ceiling and 3 million triangle ceiling). `STLLoader` parses on the main thread: STL carries no
colour, UVs or units, so there is nothing to decode off-thread and no plate or animation UI.
The mesh gets one neutral PBR material (`#b8bec9`, roughness .55), normals recomputed from its
faces because real-world STL normals are frequently zero, and the Z-up printer space is remapped
to Y-up exactly like the 3MF path. A binary STL is refused from its declared facet count before
decoding when it exceeds the triangle ceiling; junk or a truncated ASCII facet that parses to no
triangles is refused instead of reaching the stage as a "ready" preview of nothing.

Thumbnail capture (ADR 0006) covers `stl` end to end: `MODEL_CAPTURE_EXTENSIONS`,
`ModelThumbnailService::EXTENSIONS`, the capture endpoint, the has-thumbnail probe, the
missing-list backfill and the Library/preview triggers. There is no STL primary view: that
feature rewrites GLB bytes on the server and stays GLB-only.

Placeholder and badge: `AssetHelper::getFileTypeUsingExtension` types `stl`/`glb`/`3mf` as the
`model` icon bucket (the stored `file_type` stays `document`; `dam_assets.file_type` is an enum),
with a new `model.svg` in the grid and preview placeholder sets. The placeholder route falls back
to the package's bundled SVG when the public disk has no published copy, because the
deploy never runs `vendor:publish`. Library and share cards give the three model extensions a
teal badge.

Refinement audit of the GLB/3MF preview against this record found two defects, each fixed with a
Chromium regression test: a viewer whose stage had no size at load fitted at aspect 1 and never
re-fitted (portrait hosts showed a cropped model until Reset view); and the animation time label
grew a character at 10.0 s, re-wrapping the toolbar and resizing the stage under a playing
animation. Animation play/pause/scrub/speed, Draco/KTX2/Meshopt decoding, portrait fit, the saved
GLB primary view and plate switching were reviewed with no further defect.

Acceptance on the development Mac: Node suite 90 passed (STL scene builder, queue preflight for
every format); Chromium model suite 26 passed, 8 skipped (optional real samples), against the
committed production bundle. PHP coverage (helper typing, placeholder fallback, STL capture and
listing) runs on PR CI. Rollback: revert the PR; STL assets keep their `document` type and go
back to the grey placeholder, and any captured STL thumbnails stay as inert files.


## GLB primary view — 2026-09-05

Set primary view offers orbit/pan/zoom, fit that preserves the chosen front, and 90-degree
tip/turn/roll controls. Save persists preview framing and makes a corrected GLB the normal
download; the original stays in Files history. Restore original restores its bytes and automatic
framing. External apps choose their own camera; the GLB carries a +Y-up, +Z-front correction.

The server preserves binary chunks and corrects scene roots. Repeat saves compose the relative
browser adjustment with stored orientation and rebuild from the preserved original, keeping
wrapper depth fixed. Metadata is bound to the current checksum; revision checks run under an
asset row lock. No migration is required.

Acceptance: initial broader PHP suite 69/285; final repeat-save/multi-scene/composition/graph
suite 15/102; Chrome 10 passed including an actual PHP-corrected textured/animated fixture,
cancel/error recovery, portrait framing and fit-angle preservation; Node scene suite 3 passed.
Optional external samples were not supplied for this run. Independent Claude review identified
wrapper growth and missing assertions; both were reproduced and resolved. Production and
install bundles are synchronized.

Rollback: revert the feature through a PR and redeploy ([runtime guide](studio-runtime.md)). Corrected files
remain valid GLBs; use Restore original before rollback for assets whose correction should also
be reverted.

## Verified before deployment

- 15 Node tests: painted/extended filament states, all subdivision forms and rotated sides, winding and area, malformed paint, standard vertex colors, external components, object and instance transforms, plate membership, and cycle rejection.
- 10 Chromium tests against the production Vite build: plates/visibility/reset, textured animated GLB playback/pause/scrub/speed, malformed download, cancellation during download and Draco decoding, portrait framing, and five real library samples. All passed.
- Independent Claude review `mothership-3d-preview`: no blocker; three findings addressed (portrait framing, paint-branch/color fixture coverage, download preallocation). A separate reproduced Draco close-during-decode promise leak is also fixed and regression-tested.

| Source ref | Live asset | Format | Acceptance |
| --- | --- | --- | --- |
| 471 | 462 | Bambu 3MF | 5 plates, 162,824 decoded triangles, paint and filament assignments |
| 549 | 538 | Bambu 3MF | 6 plates, 38 instances, 375,882 decoded triangles |
| 552 | 541 | Bambu 3MF | 4 plates, 141,342 decoded triangles |
| 5447 | 5411 | GLB | Textured animated sci-fi door |
| 5511 | 5475 | GLB | Textured animated factory scene |

The three Bambu files parsed in approximately 0.3–0.9 seconds on the development Mac. These are sample measurements, not a universal loading-time promise. Metadata survey: 479 of 551 library 3MF originals contain Bambu settings/palettes; 72 do not. No legacy volume-range metadata was found.

## Repeatable checks

Run from an Mothership checkout using its own dependencies:

- Node's test runner: `packages/Mothership/DAM/tests/js/*.test.mjs`.
- Production bundle: `tools/frontend/build-bundles.sh` builds the admin and DAM packages from their lockfiles and mirrors `public/themes/dam/build` into `packages/Mothership/DAM/publishable/dam/build` for fresh installs. Commit the result. CI fails when the committed bundles differ from a fresh build.
- Browser suite: `tests/e2e-pw/playwright.model.config.js`. It serves the compiled assets locally and creates its own minimal textured animated GLB, Bambu fixture and binary STL (`model-fixture.mjs`). Real files are optional via `MODEL_SAMPLE_DIR`, using the source-ref filenames in the table.
- Optional `90001.glb` is the official Three.js r170 [LittlestTokyo fixture](https://github.com/mrdoob/three.js/blob/r170/examples/models/gltf/LittlestTokyo.glb), downloaded locally to verify Draco playback and cancellation. Real library originals and this external fixture are not committed.

## Runtime behavior

3D is loaded only after opening a preview. Bambu decompression, XML and paint subdivisions run in a terminable worker; buffers are transferred to the renderer. STL is parsed on the main thread with its facet count checked against the triangle ceiling first. File downloads are limited to 1 GB (`model/limits.mjs`), expanded model data to 512 MB, and decoded geometry to 3 million triangles. Known-length downloads use one buffer. Unknown-length/encoded streams use bounded chunks. Originals remain downloadable if a limit is exceeded.

Static views render only on interaction/resize. Animation pauses while the document is hidden. Closing cancels download/3MF parsing and disposes graphics resources. In-flight Draco/KTX2 work is allowed to settle before its decoder workers are disposed, preventing stranded promises; its obsolete scene is discarded.

Optional diagnostic logging is off by default. Build with `VITE_DAM_MODEL_DEBUG=true` to retain the last 100 JSONL events in sessionStorage key `mothership.model-preview.log`; logs contain format/count/state only, never source URLs or credentials.

## Deployment check

After merge: deploy main ([runtime guide](studio-runtime.md)), verify asset 462 plate switching and asset 5411 animated/textured preview, reopen both, verify the gallery entry point and an existing 360 preview. Record results in the project MOC.
